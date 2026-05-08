"""
Best Buy Open Box client + cache.

Uses Best Buy's official Open Box API:
    https://api.bestbuy.com/beta/products/openBox?apiKey=...

Each API result is a product that may have several "offers" (one per
condition tier — Excellent-Certified, Excellent, Satisfactory, Fair). We
flatten those into individual Deal rows so the UI can sort uniformly by
discount percentage across the whole catalog.

Get a free API key at https://developer.bestbuy.com (5/sec, 50k/day).
Set it via the BESTBUY_API_KEY environment variable.
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
import time
from dataclasses import dataclass, asdict, field
from pathlib import Path

import httpx

log = logging.getLogger("bestbuy")

# ---------------------------------------------------------------------------
# Config  ────────────────────────────────────────────────────────────────────
# ---------------------------------------------------------------------------

API_BASE = "https://api.bestbuy.com/beta/products/openBox"
PAGE_SIZE = 100         # max allowed
MAX_PAGES = 30          # safety cap; ~3000 products
REQUEST_TIMEOUT = 25.0
INTER_PAGE_DELAY = 0.25 # sec between pages — well under the 5/sec rate limit

# Friendly labels for the condition slugs the API returns.
CONDITION_LABELS = {
    "excellent-certified": "Excellent — Certified",
    "excellent":           "Excellent",
    "satisfactory":        "Satisfactory",
    "fair":                "Fair",
}

# Tier sort order so a product's "best" condition rises to the top first.
CONDITION_RANK = {
    "excellent-certified": 0,
    "excellent":           1,
    "satisfactory":        2,
    "fair":                3,
}

CACHE_DIR = Path(os.environ.get("PRODUCTS_FILE", "/data/products.json")).parent
CACHE_DIR.mkdir(parents=True, exist_ok=True)
CACHE_FILE = CACHE_DIR / "bestbuy_cache.json"


def get_api_key() -> str | None:
    return os.environ.get("BESTBUY_API_KEY") or None

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
    condition: str             # raw slug, e.g. "excellent-certified"
    condition_label: str       # display string
    regular_price: float
    open_box_price: float
    discount_dollars: float
    discount_pct: float
    rating: float | None       # customerReviews.averageScore
    review_count: int | None

    @property
    def key(self) -> str:
        return f"{self.sku}|{self.condition}"


@dataclass
class Snapshot:
    fetched_at: float
    deals: list[Deal] = field(default_factory=list)
    error: str | None = None
    api_key_set: bool = False

    def to_json(self) -> dict:
        return {
            "fetched_at": self.fetched_at,
            "error":      self.error,
            "api_key_set": self.api_key_set,
            "deals":      [asdict(d) for d in self.deals],
        }

    @classmethod
    def from_json(cls, raw: dict) -> "Snapshot":
        return cls(
            fetched_at=raw.get("fetched_at", 0),
            error=raw.get("error"),
            api_key_set=raw.get("api_key_set", False),
            deals=[Deal(**d) for d in raw.get("deals", [])],
        )

# ---------------------------------------------------------------------------
# Parsing  ───────────────────────────────────────────────────────────────────
# ---------------------------------------------------------------------------

def _category_from_path(path) -> str:
    """
    Best Buy's categoryPath is a list of {id, name} from broadest to most
    specific. We use the second-to-last (or last) element as a usable label,
    skipping the redundant top-level 'Best Buy' / 'All Products' nodes.
    """
    if not path or not isinstance(path, list):
        return "Other"
    names = [p.get("name", "") for p in path if isinstance(p, dict)]
    names = [n for n in names if n and n.lower() not in ("best buy", "all products")]
    if not names:
        return "Other"
    # Use the most specific category, or second-most if it looks redundant
    return names[-1]


def _flatten_product(product: dict) -> list[Deal]:
    out: list[Deal] = []

    sku = str(product.get("sku") or "")
    if not sku:
        return out

    name = (product.get("names") or {}).get("title") or product.get("name") or ""
    if not name:
        return out

    url = (product.get("links") or {}).get("web") or ""
    image = (product.get("images") or {}).get("standard")

    category = _category_from_path(product.get("categoryPath"))

    reviews = product.get("customerReviews") or {}
    try:
        rating = float(reviews.get("averageScore")) if reviews.get("averageScore") else None
    except (TypeError, ValueError):
        rating = None
    try:
        review_count = int(reviews.get("count")) if reviews.get("count") is not None else None
    except (TypeError, ValueError):
        review_count = None

    offers = product.get("offers") or []
    for offer in offers:
        prices = offer.get("prices") or {}
        try:
            open_box_price = float(prices.get("current"))
            regular_price  = float(prices.get("regular"))
        except (TypeError, ValueError):
            continue
        if regular_price <= 0 or open_box_price >= regular_price:
            continue

        cond = (offer.get("condition") or "").lower()
        label = CONDITION_LABELS.get(cond, cond.replace("-", " ").title() or "Open Box")

        discount_dollars = round(regular_price - open_box_price, 2)
        discount_pct     = round(discount_dollars / regular_price * 100, 1)

        out.append(Deal(
            sku=sku,
            name=name,
            url=url,
            image=image,
            category=category,
            condition=cond,
            condition_label=label,
            regular_price=regular_price,
            open_box_price=open_box_price,
            discount_dollars=discount_dollars,
            discount_pct=discount_pct,
            rating=rating,
            review_count=review_count,
        ))

    return out

# ---------------------------------------------------------------------------
# Fetch  ─────────────────────────────────────────────────────────────────────
# ---------------------------------------------------------------------------

class BestBuyKeyMissing(RuntimeError):
    pass


async def fetch_all() -> Snapshot:
    snap = Snapshot(fetched_at=time.time(), api_key_set=False)
    api_key = get_api_key()
    if not api_key:
        snap.error = (
            "BESTBUY_API_KEY not set. Get a free key at https://developer.bestbuy.com "
            "and add it to your docker-compose environment."
        )
        return snap
    snap.api_key_set = True

    seen: set[str] = set()
    async with httpx.AsyncClient(timeout=REQUEST_TIMEOUT, follow_redirects=True) as client:
        for page in range(1, MAX_PAGES + 1):
            params = {
                "apiKey":   api_key,
                "format":   "json",
                "pageSize": str(PAGE_SIZE),
                "page":     str(page),
            }
            try:
                r = await client.get(API_BASE, params=params)
            except httpx.HTTPError as e:
                snap.error = f"network error on page {page}: {e}"
                log.warning("BB fetch network error: %s", e)
                break

            if r.status_code == 403:
                snap.error = "API rejected the key (403). Check BESTBUY_API_KEY."
                break
            if r.status_code == 429:
                # rate limited — back off and retry once
                await asyncio.sleep(2.0)
                r = await client.get(API_BASE, params=params)
            if r.status_code >= 400:
                snap.error = f"page {page}: HTTP {r.status_code}"
                break

            try:
                payload = r.json()
            except json.JSONDecodeError:
                snap.error = f"page {page}: non-JSON response"
                break

            results = payload.get("results") or []
            if not results:
                break

            new = 0
            for product in results:
                for deal in _flatten_product(product):
                    if deal.key in seen:
                        continue
                    seen.add(deal.key)
                    snap.deals.append(deal)
                    new += 1

            log.info("BB page=%d products=%d new_deals=%d", page, len(results), new)

            total_pages = ((payload.get("metadata") or {}).get("resultSet") or {}).get("totalPages")
            if total_pages is not None and page >= int(total_pages):
                break

            await asyncio.sleep(INTER_PAGE_DELAY)

    return snap

# ---------------------------------------------------------------------------
# Cache  ─────────────────────────────────────────────────────────────────────
# ---------------------------------------------------------------------------

def load_cache() -> Snapshot | None:
    if not CACHE_FILE.exists():
        return None
    try:
        return Snapshot.from_json(json.loads(CACHE_FILE.read_text()))
    except (json.JSONDecodeError, OSError):
        return None


def save_cache(snap: Snapshot) -> None:
    tmp = CACHE_FILE.with_suffix(".tmp")
    tmp.write_text(json.dumps(snap.to_json(), indent=2))
    tmp.replace(CACHE_FILE)


async def refresh() -> Snapshot:
    snap = await fetch_all()
    save_cache(snap)
    return snap


def get_cached() -> Snapshot | None:
    return load_cache()
