"""
techinstock — FastAPI application.

A unified homelab dashboard that bundles three trackers:
  · Apple stock        ( /apple        )
  · Micro Center O-B   ( /microcenter  )
  · Best Buy O-B       ( /bestbuy      )

Apple routes (existing):
    GET    /apple                         Apple dashboard
    GET    /api/products                  configured products grouped by category
    GET    /api/check?zip=...             live availability for all configured products
    GET    /api/diag?part=X&zip=Y         raw single-part diagnostic
    POST   /api/products                  add a new product to the catalog
    PATCH  /api/products/{part}           edit a product
    PATCH  /api/categories/{id}           rename a category
    DELETE /api/products/{part}           remove a product

Landing + health:
    GET    /                              unified landing page
    GET    /healthz                       health probe
"""

from __future__ import annotations

import json
import os
from contextlib import asynccontextmanager
from pathlib import Path
from typing import Any

from fastapi import FastAPI, HTTPException, Query
from fastapi.responses import FileResponse, JSONResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel, Field

from .apple_client import AppleClient
from .microcenter_routes import router as microcenter_router
from .bestbuy_routes import router as bestbuy_router

APP_DIR = Path(__file__).parent
STATIC_DIR = APP_DIR / "static"
PRODUCTS_PATH = Path(os.environ.get("PRODUCTS_FILE", APP_DIR / "products.json"))
DEFAULT_ZIP = os.environ.get("DEFAULT_ZIP", "11793")


def load_products() -> dict[str, Any]:
    if not PRODUCTS_PATH.exists():
        return {"categories": []}
    with PRODUCTS_PATH.open("r") as f:
        return json.load(f)


def save_products(data: dict[str, Any]) -> None:
    PRODUCTS_PATH.parent.mkdir(parents=True, exist_ok=True)
    tmp = PRODUCTS_PATH.with_suffix(".tmp")
    with tmp.open("w") as f:
        json.dump(data, f, indent=2)
    tmp.replace(PRODUCTS_PATH)


def find_category(categories: list[dict], category_id: str) -> dict | None:
    """Case-insensitive category lookup."""
    return next(
        (c for c in categories if c["id"].lower() == category_id.lower()), None
    )


@asynccontextmanager
async def lifespan(app: FastAPI):
    app.state.apple = AppleClient()
    yield
    await app.state.apple.close()


app = FastAPI(title="techinstock", lifespan=lifespan)

# Sub-trackers — each is a self-contained module with its own /api/ routes
app.include_router(microcenter_router)
app.include_router(bestbuy_router)


class NewProduct(BaseModel):
    category_id: str = Field(..., min_length=1)
    part: str = Field(..., min_length=1)
    name: str = Field(..., min_length=1)


class EditProduct(BaseModel):
    part: str | None = None        # new part number
    name: str | None = None        # new friendly name
    category_id: str | None = None # move to a different category
    position: int | None = None    # new index within target category (0-based)


class EditCategory(BaseModel):
    name: str | None = None  # new display name
    id: str | None = None    # new id (slug)


@app.get("/api/products")
def get_products() -> dict[str, Any]:
    data = load_products()
    return {**data, "default_zip": DEFAULT_ZIP}


@app.post("/api/products")
def add_product(product: NewProduct) -> dict[str, Any]:
    data = load_products()
    categories = data.setdefault("categories", [])

    # Case-insensitive match — "Mac-Mini" finds "mac-mini"
    cat = find_category(categories, product.category_id)
    if cat is None:
        # New category: derive display name from the id (mac-mini → Mac Mini)
        cat = {
            "id": product.category_id.lower(),
            "name": product.category_id.replace("-", " ").title(),
            "products": [],
        }
        categories.append(cat)

    if any(p["part"] == product.part for p in cat["products"]):
        raise HTTPException(status_code=409, detail="Part already tracked")

    cat["products"].append({"part": product.part, "name": product.name})
    save_products(data)
    return data


@app.patch("/api/products/{part:path}")
def edit_product(part: str, edits: EditProduct) -> dict[str, Any]:
    data = load_products()
    categories = data.get("categories", [])

    # Find the product and which category it lives in
    found_cat = None
    found_product = None
    for cat in categories:
        for p in cat["products"]:
            if p["part"] == part:
                found_cat = cat
                found_product = p
                break
        if found_product:
            break

    if not found_product:
        raise HTTPException(status_code=404, detail="Part not found")

    # Apply edits
    if edits.name is not None:
        found_product["name"] = edits.name.strip()

    if edits.part is not None:
        new_part = edits.part.strip()
        # Check for collision in the same category
        if any(p["part"] == new_part and p is not found_product for p in found_cat["products"]):
            raise HTTPException(status_code=409, detail="Part number already exists")
        found_product["part"] = new_part

    if edits.category_id is not None:
        target_cat = find_category(categories, edits.category_id)
        if target_cat is None:
            target_cat = {
                "id": edits.category_id.lower(),
                "name": edits.category_id.replace("-", " ").title(),
                "products": [],
            }
            categories.append(target_cat)
        if target_cat is not found_cat:
            found_cat["products"].remove(found_product)
            target_cat["products"].append(found_product)
            found_cat = target_cat
            # Drop now-empty categories
            data["categories"] = [c for c in categories if c["products"]]

    # Reorder within the (possibly new) category
    if edits.position is not None:
        try:
            found_cat["products"].remove(found_product)
        except ValueError:
            pass
        idx = max(0, min(edits.position, len(found_cat["products"])))
        found_cat["products"].insert(idx, found_product)

    save_products(data)
    return data


@app.patch("/api/categories/{category_id}")
def edit_category(category_id: str, edits: EditCategory) -> dict[str, Any]:
    data = load_products()
    categories = data.get("categories", [])

    cat = find_category(categories, category_id)
    if cat is None:
        raise HTTPException(status_code=404, detail="Category not found")

    if edits.name is not None:
        cat["name"] = edits.name.strip()

    if edits.id is not None:
        new_id = edits.id.strip().lower()
        if any(c["id"].lower() == new_id and c is not cat for c in categories):
            raise HTTPException(status_code=409, detail="Category ID already exists")
        cat["id"] = new_id

    save_products(data)
    return data


@app.delete("/api/products/{part:path}")
def delete_product(part: str) -> dict[str, Any]:
    data = load_products()
    removed = False
    for cat in data.get("categories", []):
        before = len(cat["products"])
        cat["products"] = [p for p in cat["products"] if p["part"] != part]
        if len(cat["products"]) != before:
            removed = True
    if not removed:
        raise HTTPException(status_code=404, detail="Part not found")
    data["categories"] = [c for c in data["categories"] if c["products"]]
    save_products(data)
    return data


@app.get("/api/check")
async def check_stock(
    zip: str = Query(default=DEFAULT_ZIP, min_length=3, max_length=10),
) -> JSONResponse:
    data = load_products()
    all_parts: list[str] = []
    for cat in data.get("categories", []):
        for p in cat["products"]:
            all_parts.append(p["part"])

    if not all_parts:
        return JSONResponse({"zip": zip, "stores": [], "failed_parts": [], "checked_at": None})

    try:
        result = await app.state.apple.check_availability(all_parts, zip)
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=502, detail=f"Apple API error: {exc}") from exc

    import datetime as _dt

    return JSONResponse(
        {
            "zip": zip,
            "stores": result["stores"],
            "failed_parts": result["failed_parts"],
            "checked_at": _dt.datetime.utcnow().isoformat() + "Z",
        }
    )


@app.get("/api/diag")
async def diag(
    part: str = Query(..., min_length=4, description="Single Apple part number, e.g. MU9D3LL/A"),
    zip: str = Query(default=DEFAULT_ZIP, min_length=3, max_length=10),
) -> JSONResponse:
    result = await app.state.apple.diag(part, zip)
    return JSONResponse(result)


# Static frontend
app.mount("/static", StaticFiles(directory=STATIC_DIR), name="static")


@app.get("/")
def home() -> FileResponse:
    """Unified landing page that links to all three trackers."""
    return FileResponse(STATIC_DIR / "home.html")


@app.get("/apple")
def apple_dashboard() -> FileResponse:
    """The original Apple stock tracker UI."""
    return FileResponse(STATIC_DIR / "index.html")


@app.get("/healthz")
def healthz() -> dict[str, str]:
    return {"status": "ok"}
