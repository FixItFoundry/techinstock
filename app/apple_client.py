"""
Client for Apple's pickup-message endpoint.

Apple retired /shop/fulfillment-messages (which now returns the /shop/404
page) and the new endpoint is /shop/retail/pickup-message. The response
shape also changed: stores are now directly under `body.stores`, not
nested under `body.content.pickupMessage.stores`.

Strategy:
  1. Batch parts in small groups (BATCH_SIZE).
  2. If a batch fails, fall back to per-part requests so a single bad
     SKU only fails itself.
  3. Send full browser-like headers.
  4. Parse both new and old response shapes for robustness.
"""

from __future__ import annotations

import asyncio
import logging
import time
from typing import Any

import httpx

log = logging.getLogger(__name__)

# New endpoint as of late 2025. The old /shop/fulfillment-messages now 404s.
APPLE_PICKUP_URL = "https://www.apple.com/shop/retail/pickup-message"

BATCH_SIZE = 5
CACHE_TTL_SECONDS = 30
_cache: dict[str, tuple[float, dict[str, Any]]] = {}

DEFAULT_HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
        "AppleWebKit/605.1.15 (KHTML, like Gecko) "
        "Version/17.4 Safari/605.1.15"
    ),
    "Accept": "application/json, text/javascript, */*; q=0.01",
    "Accept-Language": "en-US,en;q=0.9",
    "Referer": "https://www.apple.com/shop/buy-mac/mac-mini",
    "Sec-Fetch-Dest": "empty",
    "Sec-Fetch-Mode": "cors",
    "Sec-Fetch-Site": "same-origin",
    "X-Requested-With": "XMLHttpRequest",
}


def _build_params(part_numbers: list[str], location: str) -> dict[str, str]:
    params: dict[str, str] = {
        "pl": "true",
        "mts.0": "regular",
        "mts.1": "compact",
        "location": location,
    }
    for i, part in enumerate(part_numbers):
        params[f"parts.{i}"] = part
    return params


def _extract_stores(data: dict[str, Any]) -> list[dict[str, Any]]:
    """
    Return the raw stores list from either the new or old response shape.
      New (2025+):  body.stores[]
      Old:          body.content.pickupMessage.stores[]
    """
    body = data.get("body", {}) or {}
    new_shape = body.get("stores")
    if isinstance(new_shape, list):
        return new_shape
    old_shape = (
        body.get("content", {}).get("pickupMessage", {}).get("stores", [])
    )
    return old_shape if isinstance(old_shape, list) else []


class AppleClient:
    def __init__(self, timeout: float = 10.0) -> None:
        self._client = httpx.AsyncClient(
            timeout=timeout,
            headers=DEFAULT_HEADERS,
            follow_redirects=True,
        )

    async def close(self) -> None:
        await self._client.aclose()

    # ------------------------------------------------------------------
    # Diagnostic
    # ------------------------------------------------------------------
    async def diag(self, part: str, location: str) -> dict[str, Any]:
        params = _build_params([part], location)
        try:
            resp = await self._client.get(APPLE_PICKUP_URL, params=params)
            body_text = resp.text
            try:
                body_json = resp.json()
            except Exception:  # noqa: BLE001
                body_json = None
            return {
                "ok": resp.is_success,
                "status_code": resp.status_code,
                "url": str(resp.request.url),
                "body_json": body_json,
                "body_text_preview": body_text[:2000],
            }
        except Exception as exc:  # noqa: BLE001
            return {
                "ok": False,
                "status_code": None,
                "url": None,
                "error": f"{type(exc).__name__}: {exc}",
            }

    # ------------------------------------------------------------------
    # Public entry point
    # ------------------------------------------------------------------
    async def check_availability(
        self, part_numbers: list[str], location: str
    ) -> dict[str, Any]:
        if not part_numbers:
            return {"stores": [], "failed_parts": []}

        cache_key = f"{location}|{'|'.join(sorted(part_numbers))}"
        cached = _cache.get(cache_key)
        if cached and (time.time() - cached[0]) < CACHE_TTL_SECONDS:
            return cached[1]

        merged: dict[str, dict[str, Any]] = {}
        failed_parts: list[str] = []

        for i in range(0, len(part_numbers), BATCH_SIZE):
            batch = part_numbers[i : i + BATCH_SIZE]
            try:
                stores = await self._fetch(batch, location)
                self._merge(merged, stores)
            except Exception as exc:  # noqa: BLE001
                log.warning(
                    "Batch %s failed (%s); retrying parts individually", batch, exc
                )
                for part in batch:
                    try:
                        stores = await self._fetch([part], location)
                        self._merge(merged, stores)
                    except Exception as part_exc:  # noqa: BLE001
                        log.warning("Part %s failed: %s", part, part_exc)
                        failed_parts.append(part)

        store_list = list(merged.values())
        store_list.sort(key=lambda s: s.get("_distance_value") or 1e9)
        for s in store_list:
            s.pop("_distance_value", None)

        # Apple's new endpoint returns 200 OK with zero stores when a part
        # number is invalid/retired, instead of an HTTP error. Catch these
        # silent failures: any requested part that never showed up in any
        # store's partsAvailability is treated as a failed SKU.
        seen_parts: set[str] = set()
        for s in store_list:
            seen_parts.update(s.get("parts", {}).keys())
        already_failed = set(failed_parts)
        for part in part_numbers:
            if part not in seen_parts and part not in already_failed:
                failed_parts.append(part)

        result = {"stores": store_list, "failed_parts": failed_parts}
        _cache[cache_key] = (time.time(), result)
        return result

    # ------------------------------------------------------------------
    # Internal helpers
    # ------------------------------------------------------------------
    async def _fetch(
        self, part_numbers: list[str], location: str
    ) -> list[dict[str, Any]]:
        params = _build_params(part_numbers, location)
        resp = await self._client.get(APPLE_PICKUP_URL, params=params)
        resp.raise_for_status()
        data = resp.json()

        stores_raw = _extract_stores(data)

        out: list[dict[str, Any]] = []
        for s in stores_raw:
            parts_avail = s.get("partsAvailability", {}) or {}
            parts: dict[str, dict[str, Any]] = {}
            for part_no, info in parts_avail.items():
                pickup_display = (info.get("pickupDisplay") or "").lower()
                quote = (
                    info.get("pickupSearchQuote")
                    or info.get("storePickupQuote")
                    or info.get("pickupQuote")
                    or ""
                )
                parts[part_no] = {
                    "available": pickup_display == "available",
                    "status": quote,
                }

            distance_value = s.get("storedistance")
            try:
                distance_value = float(distance_value) if distance_value is not None else None
            except (TypeError, ValueError):
                distance_value = None

            out.append(
                {
                    "store_name": s.get("storeName"),
                    "store_number": s.get("storeNumber"),
                    "city": s.get("city"),
                    "state": s.get("state"),
                    "distance": s.get("storeDistanceWithUnit")
                    or (
                        f"{s.get('storedistance')} {s.get('storeDistanceVicinity', '')}".strip()
                        if s.get("storedistance") is not None
                        else None
                    ),
                    "_distance_value": distance_value,
                    "parts": parts,
                }
            )

        await asyncio.sleep(0.15)
        return out

    @staticmethod
    def _merge(
        accum: dict[str, dict[str, Any]], stores: list[dict[str, Any]]
    ) -> None:
        for s in stores:
            sn = s.get("store_number")
            if not sn:
                continue
            if sn not in accum:
                accum[sn] = {**s, "parts": dict(s.get("parts", {}))}
            else:
                accum[sn]["parts"].update(s.get("parts", {}))
