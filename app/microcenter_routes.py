"""
FastAPI router for the Micro Center open-box tracker.

Mount in main.py with a single line:

    from app.microcenter_routes import router as microcenter_router
    app.include_router(microcenter_router)

Routes:
    GET  /microcenter                 -> HTML page (the dashboard)
    GET  /microcenter/api/stores      -> { westbury: {...}, ... }
    GET  /microcenter/api/deals       -> latest cached snapshot for ?store=
    POST /microcenter/api/refresh     -> force a live scrape of ?store=
"""

from __future__ import annotations

from pathlib import Path

from fastapi import APIRouter, HTTPException, Query
from fastapi.responses import FileResponse, JSONResponse

from . import microcenter as mc

router = APIRouter(prefix="/microcenter", tags=["microcenter"])

_HTML_PATH = Path(__file__).parent / "static" / "microcenter.html"


@router.get("", include_in_schema=False)
@router.get("/", include_in_schema=False)
def page():
    return FileResponse(_HTML_PATH)


@router.get("/api/stores")
def stores():
    return {
        "default": mc.DEFAULT_STORE,
        "stores":  mc.STORES,
    }


@router.get("/api/deals")
def deals(store: str = Query(default=mc.DEFAULT_STORE)):
    if store not in mc.STORES:
        raise HTTPException(404, f"unknown store '{store}'")
    snap = mc.get_cached(store)
    if snap is None:
        return JSONResponse(
            {
                "store_key":  store,
                "store_name": mc.STORES[store]["name"],
                "fetched_at": None,
                "deals":      [],
                "error":      "no cache yet — hit refresh",
            }
        )
    return snap.to_json()


@router.post("/api/refresh")
async def refresh(store: str = Query(default=mc.DEFAULT_STORE)):
    if store not in mc.STORES:
        raise HTTPException(404, f"unknown store '{store}'")
    snap = await mc.refresh(store)
    return snap.to_json()
