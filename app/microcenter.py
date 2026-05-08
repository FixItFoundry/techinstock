"""
Micro Center open-box scraper + cache.

Fetches the open-box listing for a given store, parses each card for the
regular price and the open-box price, computes discount $ and %, and caches
results on disk so the UI can sort/filter without re-hitting Micro Center.

Intentionally tolerant of HTML changes: every selector falls back to a
broader sibling search before giving up on a card, so a minor markup tweak
on Micro Center's side won't take down the whole feed.
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
import re
import time
from dataclasses import dataclass, asdict, field
from pathlib import Path
from typing import Iterable

import httpx
from curl_cffi import requests as curl_requests
from curl_cffi.requests.errors import RequestsError

log = logging.getLogger("microcenter")

# ---------------------------------------------------------------------------
# Stores  ────────────────────────────────────────────────────────────────────
# ---------------------------------------------------------------------------

STORES: dict[str, dict[str, str]] = {
    "westbury": {"id": "065", "name": "Westbury, NY"},
    "flushing": {"id": "051", "name": "Flushing, NY"},
    "yonkers":  {"id": "105", "name": "Yonkers, NY"},
    "brooklyn": {"id": "115", "name": "Brooklyn, NY"},
}

DEFAULT_STORE = "westbury"

# ---------------------------------------------------------------------------
# Scrape config  ─────────────────────────────────────────────────────────────
# ---------------------------------------------------------------------------

# Micro Center's open-box filter is ?N=4294966937. We page through up to
# MAX_PAGES, stopping early when a page yields no items.
OPEN_BOX_N = "4294966937"
SEARCH_URL = "https://www.microcenter.com/search/search_results.aspx"
PAGE_SIZE = 96            # MC supports up to 96 per page via pagecount
MAX_PAGES = 20            # safety cap; raise if your store has more
REQUEST_TIMEOUT = 25.0
INTER_PAGE_DELAY = (0.6, 1.4)   # seconds; jittered to look less bot-like

# Reasonable browser UA — MC's CDN drops obviously-bot UAs.
USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/126.0.0.0 Safari/537.36"
)

CACHE_DIR = Path(os.environ.get("PRODUCTS_FILE", "/data/products.json")).parent
CACHE_DIR.mkdir(parents=True, exist_ok=True)
CACHE_FILE = CACHE_DIR / "microcenter_cache.json"

# ---------------------------------------------------------------------------
# Data model  ────────────────────────────────────────────────────────────────
# ---------------------------------------------------------------------------

@dataclass
class Deal:
    sku: str
    name: str
    url: str
    image: str | None
    category: str
    condition: str          # e.g. "Open Box - Excellent"
    regular_price: float
    open_box_price: float
    discount_dollars: float
    discount_pct: float

    @property
    def key(self) -> str:
        # SKU + condition keeps two open-box variants of the same product distinct.
        return f"{self.sku}|{self.condition}"


@dataclass
class StoreSnapshot:
    store_key: str
    store_id: str
    store_name: str
    fetched_at: float                          # unix seconds
    deals: list[Deal] = field(default_factory=list)
    error: str | None = None

    def to_json(self) -> dict:
        return {
            "store_key": self.store_key,
            "store_id": self.store_id,
            "store_name": self.store_name,
            "fetched_at": self.fetched_at,
            "error": self.error,
            "deals": [asdict(d) for d in self.deals],
        }

    @classmethod
    def from_json(cls, raw: dict) -> "StoreSnapshot":
        return cls(
            store_key=raw["store_key"],
            store_id=raw["store_id"],
            store_name=raw["store_name"],
            fetched_at=raw["fetched_at"],
            error=raw.get("error"),
            deals=[Deal(**d) for d in raw.get("deals", [])],
        )

# ---------------------------------------------------------------------------
# Parsing  ───────────────────────────────────────────────────────────────────
# ---------------------------------------------------------------------------

_PRICE_RE = re.compile(r"\$?\s*([\d,]+\.\d{2}|\d+)")


def _parse_price(text: str | None) -> float | None:
    if not text:
        return None
    m = _PRICE_RE.search(text)
    if not m:
        return None
    try:
        return float(m.group(1).replace(",", ""))
    except ValueError:
        return None


def _first_text(node, *selectors: str) -> str | None:
    for sel in selectors:
        found = node.select_one(sel)
        if found:
            txt = found.get_text(" ", strip=True)
            if txt:
                return txt
    return None


def _extract_card(card) -> Deal | None:
    """Pull a single deal out of a product card. Returns None if it isn't actually open-box."""

    # Name + product URL
    a = card.select_one("a.productClickItemV2") or card.select_one("h2 a") or card.select_one("a[href*='/product/']")
    if not a:
        return None
    name = a.get_text(" ", strip=True)
    href = a.get("href", "")
    url = href if href.startswith("http") else f"https://www.microcenter.com{href}"

    # SKU — usually on a data attribute on the card or its inner div
    sku = (
        card.get("data-id")
        or card.get("data-sku")
        or (card.select_one("[data-id]") or {}).get("data-id", "")
        or (card.select_one("[data-sku]") or {}).get("data-sku", "")
        or ""
    )
    if not sku:
        # last-ditch: pull the digits out of the product URL
        m = re.search(r"/product/(\d+)", url)
        sku = m.group(1) if m else url

    # Image
    img_node = card.select_one("img")
    image = None
    if img_node:
        image = (
            img_node.get("src")
            or img_node.get("data-src")
            or img_node.get("data-original")
        )
        if image and image.startswith("//"):
            image = "https:" + image

    # Open-box price (the "now" price on an open-box listing)
    open_box_text = _first_text(
        card,
        ".price-wrapper [itemprop='price']",
        "[data-price]",
        ".product_price",
        ".price",
    )
    open_box_price = _parse_price(open_box_text)

    # The "regular price" line — Micro Center labels this differently across templates.
    # We try a few patterns and finally fall back to scanning sibling text.
    regular_text = _first_text(
        card,
        ".comp-price",
        ".compare-price",
        ".strikethrough",
        ".was-price",
        "del",
        "s",
    )
    regular_price = _parse_price(regular_text)

    if regular_price is None:
        # Sometimes MC writes "Reg. $1,299.99" inline as plain text
        whole = card.get_text(" ", strip=True)
        m = re.search(r"(?:Reg(?:ular)?\.?\s*Price\.?|Reg\.)\s*\$?([\d,]+\.\d{2})", whole, re.I)
        if m:
            regular_price = _parse_price(m.group(1))

    if open_box_price is None or regular_price is None or regular_price <= 0:
        return None

    if open_box_price >= regular_price:
        # Not actually a discount — skip rather than show 0% off rows.
        return None

    # Condition label — "Open Box", "Open Box - Excellent", etc.
    condition = _first_text(card, ".condition", ".openBoxCondition", ".product-condition") or "Open Box"

    # Category — pull from the nav breadcrumb attribute the card carries, else "Other"
    category = (
        card.get("data-category")
        or (card.select_one("[data-category]") or {}).get("data-category", "")
        or _first_text(card, ".category", ".breadcrumb")
        or "Other"
    )
    category = category.strip().title() or "Other"

    discount_dollars = round(regular_price - open_box_price, 2)
    discount_pct = round(discount_dollars / regular_price * 100, 1)

    return Deal(
        sku=str(sku),
        name=name,
        url=url,
        image=image,
        category=category,
        condition=condition,
        regular_price=regular_price,
        open_box_price=open_box_price,
        discount_dollars=discount_dollars,
        discount_pct=discount_pct,
    )


def _parse_listing(html: str) -> list[Deal]:
    soup = BeautifulSoup(html, "lxml")
    cards: Iterable = (
        soup.select("li.product_wrapper")
        or soup.select("article.product_wrapper")
        or soup.select(".product_wrapper")
        or soup.select("li[data-id]")
    )
    out: list[Deal] = []
    for c in cards:
        try:
            d = _extract_card(c)
            if d:
                out.append(d)
        except Exception as e:                # one bad card shouldn't kill the page
            log.debug("card parse failed: %s", e)
    return out

# ---------------------------------------------------------------------------
# Fetch  ─────────────────────────────────────────────────────────────────────
# ---------------------------------------------------------------------------

async def _jittered_sleep() -> None:
    import random
    lo, hi = INTER_PAGE_DELAY
    await asyncio.sleep(random.uniform(lo, hi))


import urllib.parse
import nodriver as uc
import asyncio
import time

async def fetch_store(store_key: str) -> StoreSnapshot:
    if store_key not in STORES:
        raise ValueError(f"unknown store '{store_key}'")
    meta = STORES[store_key]
    snap = StoreSnapshot(
        store_key=store_key,
        store_id=meta["id"],
        store_name=meta["name"],
        fetched_at=time.time(),
    )

    # Start the stealth browser. 
    # TIP: For your first run, set headless=False so you can visually confirm 
    # that it is passing the Akamai interstitial screen.
    browser = await uc.start(headless=True)
    
    try:
        # Open a new tab
        page_tab = await browser.get("about:blank")

        seen: set[str] = set()
        
        for page in range(1, MAX_PAGES + 1):
            params = {
                "N":           OPEN_BOX_N,
                "storeid":     meta["id"],
                "myStore":     "true",
                "pagecount":   str(PAGE_SIZE),
                "currentpage": str(page),
                "sortby":      "match",
            }
            
            # nodriver's .get() requires a full string URL, so we encode the params
            query_string = urllib.parse.urlencode(params)
            full_url = f"{SEARCH_URL}?{query_string}"
            
            # Navigate to the Micro Center search page
            await page_tab.get(full_url)
            
            # CRITICAL: We must sleep to let Akamai's JS run.
            # Akamai often loads a blank page or a loading spinner for 1-3 seconds 
            # while it calculates mouse/canvas fingerprints before unhiding the DOM.
            await asyncio.sleep(4) 
            
            # Extract the fully rendered HTML out of the DOM
            html = await page_tab.get_content()
            
            # Pass the HTML right back into your existing BeautifulSoup parser
            page_deals = _parse_listing(html)
            log.info("store=%s page=%d parsed=%d", store_key, page, len(page_deals))

            new = 0
            for d in page_deals:
                if d.key not in seen:
                    seen.add(d.key)
                    snap.deals.append(d)
                    new += 1

            if new == 0:
                # Either pagination ended or the page is a duplicate of the previous.
                break

            await _jittered_sleep()

    except Exception as e:
        snap.error = f"page {page}: {e}"
        log.warning("fetch failed: %s", e)
    finally:
        # Always stop the browser, otherwise you will end up with 
        # dozens of zombie Chromium processes eating your RAM.
        browser.stop()

    return snap

# ---------------------------------------------------------------------------
# Cache  ─────────────────────────────────────────────────────────────────────
# ---------------------------------------------------------------------------

def load_cache() -> dict[str, StoreSnapshot]:
    if not CACHE_FILE.exists():
        return {}
    try:
        raw = json.loads(CACHE_FILE.read_text())
    except (json.JSONDecodeError, OSError):
        return {}
    return {k: StoreSnapshot.from_json(v) for k, v in raw.items()}


def save_cache(cache: dict[str, StoreSnapshot]) -> None:
    tmp = CACHE_FILE.with_suffix(".tmp")
    tmp.write_text(json.dumps({k: v.to_json() for k, v in cache.items()}, indent=2))
    tmp.replace(CACHE_FILE)


async def refresh(store_key: str) -> StoreSnapshot:
    snap = await fetch_store(store_key)
    cache = load_cache()
    cache[store_key] = snap
    save_cache(cache)
    return snap


def get_cached(store_key: str) -> StoreSnapshot | None:
    return load_cache().get(store_key)
