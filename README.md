# Tech In Stock

A self-hosted homelab dashboard for tracking tech availability and deals. Three
trackers, one Docker container:

1. **Apple Stock** — real-time pickup availability for tracked Apple products
   at stores near a ZIP. Hits the same `fulfillment-messages` endpoint
   apple.com itself uses on product pages.
2. **Micro Center Open Box** — open-box deals across the four NY-area
   stores (Westbury, Flushing, Yonkers, Brooklyn). Compares regular vs.
   open-box price, sorts by % off, filters by category.
3. **Best Buy Open Box** — open-box deals across the entire Best Buy
   catalog via the official developer API. Tier-aware (Excellent-Certified,
   Excellent, Satisfactory, Fair) so each condition is its own row.

```
                        ┌──────────────────────┐
                        │   /  (landing page)  │
                        └────┬───────┬────┬────┘
                             │       │    │
              ┌──────────────┘       │    └─────────────┐
              ▼                      ▼                  ▼
        /apple                 /microcenter         /bestbuy
        (Apple stock)         (MC Open Box)        (BB Open Box)
```

## Quick start

```bash
git clone <this repo> techinstock
cd techinstock
docker compose build && docker compose up -d
```

Open `http://<your-host>:8765` for the landing page.

The Apple and Micro Center trackers work immediately. The Best Buy tracker
needs an API key — see below.

## Configuration

| Env var           | Default               | Notes                                                                 |
|-------------------|-----------------------|-----------------------------------------------------------------------|
| `DEFAULT_ZIP`     | `11793`               | ZIP shown in the Apple UI on first load                               |
| `PRODUCTS_FILE`   | `/data/products.json` | Editable Apple catalog; mount a volume to persist                     |
| `BESTBUY_API_KEY` | *(unset)*             | Free key from [developer.bestbuy.com](https://developer.bestbuy.com)  |

Change the host port by editing the `ports:` line in `docker-compose.yml`
(default `8765:8000`).

### Setting up the Best Buy API key

1. Sign up at [developer.bestbuy.com](https://developer.bestbuy.com) — instant,
   no approval queue.
2. Copy your key from the dashboard.
3. Set it in `docker-compose.yml`:
   ```yaml
   environment:
     BESTBUY_API_KEY: "your-key-here"
   ```
4. `docker compose up -d --force-recreate`.
5. Open `/bestbuy` and hit **↻ refresh**.

Free tier limits: **5 calls/sec, 50,000 calls/day** — way more than the
tracker uses (one full refresh is roughly 30 calls).

## What each tracker does

### Apple Stock — `/apple`

Add Apple part numbers (e.g. `MU9D3LL/A`) to a watchlist. The dashboard
queries Apple's public fulfillment API for every part at once and shows
which nearby stores have each model in stock for pickup.

- Default catalog covers Mac mini (M4), Mac Studio (M4 Max), MacBook Air
  (M4), MacBook Pro (M4 / M4 Pro), AirPods 4, AirPods Pro 2, AirPods Max.
- Edit via the **+ Add** button or by editing `data/products.json` directly.
- Auto-refreshes every 5 minutes; press Enter or hit Refresh to force.
- Find part numbers in the URL after configuring a Mac on apple.com (e.g.
  `?mtm=MU9D3LL/A`) or by searching `LL/A` in any product page's source.

### Micro Center Open Box — `/microcenter`

Scrapes Micro Center's open-box listings for the four NY-area stores and
shows discount % vs. regular price. Switch between stores with one click.

- **Stores**: Westbury (065), Flushing (051), Yonkers (105), Brooklyn (115).
  Edit `STORES` in `app/microcenter.py` to add others.
- **Categories** auto-discovered from each scrape; chip-filterable with item counts.
- **Sort by**: % off (default), $ saved, price ↑, price ↓.
- **Refresh**: triggers a live scrape (~10–25s for a full store);
  results cached on the volume so reloads are instant.
- Discount tiers color-coded: green ≥ 30%, amber ≥ 15%, white below.

### Best Buy Open Box — `/bestbuy`

Uses Best Buy's official `openBox` endpoint. Each product can have multiple
condition tiers (each with its own price), and the tracker flattens those
into individual rows so a Satisfactory listing at 40% off ranks above an
Excellent listing at 15% off.

- **Conditions**: Excellent-Certified, Excellent, Satisfactory, Fair —
  filterable.
- Customer ratings shown inline.
- One refresh ≈ 30 API calls and finishes in ~5–15s.
- Data cached on the volume; the page renders the cache on load.

## Architecture

Everything is one FastAPI app in one container.

```
app/
├── main.py                  # FastAPI app, mounts the routers below
├── apple_client.py          # Apple fulfillment-messages client
├── microcenter.py           # MC scraper + cache
├── microcenter_routes.py    # /microcenter/* endpoints
├── bestbuy.py               # BB API client + cache
├── bestbuy_routes.py        # /bestbuy/* endpoints
├── products.json            # Default Apple catalog (seeds the volume)
└── static/
    ├── home.html            # Unified landing page  ( / )
    ├── index.html           # Apple dashboard       ( /apple )
    ├── microcenter.html     # MC dashboard          ( /microcenter )
    ├── bestbuy.html         # BB dashboard          ( /bestbuy )
    ├── style.css            # Apple page styles
    └── app.js               # Apple page logic
```

Caches live on the mounted volume next to `products.json`:

```
data/
├── products.json            # Apple catalog (editable)
├── microcenter_cache.json   # last MC scrape per store
└── bestbuy_cache.json       # last BB API snapshot
```

## API reference

### Apple
| Method | Path                       | Purpose                                 |
|--------|----------------------------|-----------------------------------------|
| GET    | `/api/products`            | List configured products                |
| POST   | `/api/products`            | Add `{category_id, part, name}`         |
| PATCH  | `/api/products/{part}`     | Edit a tracked part                     |
| DELETE | `/api/products/{part}`     | Remove a tracked part                   |
| PATCH  | `/api/categories/{id}`     | Rename / re-id a category               |
| GET    | `/api/check?zip=NNNNN`     | Live availability across nearby stores  |
| GET    | `/api/diag?part=X&zip=Y`   | Raw single-part diagnostic              |

### Micro Center
| Method | Path                              | Purpose                                |
|--------|-----------------------------------|----------------------------------------|
| GET    | `/microcenter/api/stores`         | Available stores + default             |
| GET    | `/microcenter/api/deals?store=X`  | Cached deals for a store               |
| POST   | `/microcenter/api/refresh?store=X`| Force live scrape of a store           |

### Best Buy
| Method | Path                       | Purpose                                |
|--------|----------------------------|----------------------------------------|
| GET    | `/bestbuy/api/status`      | API key configured? cache status?      |
| GET    | `/bestbuy/api/deals`       | Cached snapshot                        |
| POST   | `/bestbuy/api/refresh`     | Force a live API fetch                 |

### General
| Method | Path        | Purpose                          |
|--------|-------------|----------------------------------|
| GET    | `/healthz`  | Used by the container HEALTHCHECK |

## Running without Docker

```bash
pip install -r requirements.txt
export DEFAULT_ZIP=11793
export BESTBUY_API_KEY=your-key-here   # optional
uvicorn app.main:app --reload --port 8000
```

## Notes

- The Apple tracker hits an undocumented `fulfillment-messages` endpoint
  that's been stable for years — but it's not contractual. Parser lives
  in `app/apple_client.py`.
- The Micro Center scraper uses tolerant fallback selectors so minor HTML
  tweaks won't break it. If a major redesign hits, the only function to
  patch is `_extract_card()` in `app/microcenter.py`.
- The Best Buy tracker uses the *official* Open Box API, so it's the most
  reliable of the three. If a request returns 403 it almost always means
  the API key is wrong or expired.
