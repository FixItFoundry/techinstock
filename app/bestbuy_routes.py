"""
FastAPI router for the Best Buy open-box tracker.

Mount in main.py:

    from app.bestbuy_routes import router as bestbuy_router
    app.include_router(bestbuy_router)

Routes:
    GET  /bestbuy                     -> dashboard HTML
    GET  /bestbuy/api/deals           -> latest cached snapshot
    POST /bestbuy/api/refresh         -> force a live fetch
    GET  /bestbuy/api/status          -> { api_key_set: bool, fetched_at, count }
"""

from __future__ import annotations

from pathlib import Path

from fastapi import APIRouter
from fastapi.responses import FileResponse, JSONResponse

from . import bestbuy as bb

router = APIRouter(prefix="/bestbuy", tags=["bestbuy"])

_HTML_PATH = Path(__file__).parent / "static" / "bestbuy.html"


@router.get("", include_in_schema=False)
@router.get("/", include_in_schema=False)
def page():
    return FileResponse(_HTML_PATH)


@router.get("/api/status")
def status():
    snap = bb.get_cached()
    return {
        "api_key_set": bb.get_api_key() is not None,
        "fetched_at":  snap.fetched_at if snap else None,
        "count":       len(snap.deals) if snap else 0,
        "error":       snap.error if snap else None,
    }


@router.get("/api/deals")
def deals():
    snap = bb.get_cached()
    if snap is None:
        return JSONResponse({
            "fetched_at":  None,
            "deals":       [],
            "error":       "no cache yet — hit refresh",
            "api_key_set": bb.get_api_key() is not None,
        })
    return snap.to_json()


@router.post("/api/refresh")
async def refresh():
    snap = await bb.refresh()
    return snap.to_json()
