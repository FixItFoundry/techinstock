# Tech In Stock

A self-hosted homelab dashboard for tracking tech availability and deals. Three
trackers, one PHP container:

1. **Apple Stock** — real-time pickup availability for tracked Apple products at
   stores near a ZIP. Hits the same `pickup-message` endpoint apple.com itself
   uses on product pages.
2. **Micro Center Open Box** — open-box deals across the four NY-area stores
   (Westbury, Flushing, Yonkers, Brooklyn). Compares regular vs. open-box price,
   sorts by % off, filters by category.
3. **Best Buy Open Box** — open-box deals across the entire Best Buy catalog via
   the official developer API. Tier-aware (Excellent-Certified, Excellent,
   Satisfactory, Fair) so each condition is its own row.

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

The Apple and Micro Center trackers work immediately (Micro Center needs a live
scrape on first load — hit **↻ refresh**). The Best Buy tracker needs an API key
— see below.

## Configuration

| Env var           | Default               | Notes                                                                 |
|-------------------|-----------------------|-----------------------------------------------------------------------|
| `DEFAULT_ZIP`     | `11793`               | ZIP shown in the Apple UI on first load                               |
| `PRODUCTS_FILE`   | `/data/products.json` | Editable Apple catalog; mount a volume to persist                     |
| `BESTBUY_API_KEY` | *(unset)*             | Free key from [developer.bestbuy.com](https://developer.bestbuy.com)  |
| `FLARESOLVERR_URL`| `http://flaresolverr:8191` | Cloudflare solver sidecar for the Micro Center scraper (**required** for MC) |

Change the host port by editing the `ports:` line in `docker-compose.yml`
(default `8765:80`).

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

Free tier limits: **5 calls/sec, 50,000 calls/day**.

## What each tracker does

### Apple Stock — `/apple`

Add Apple part numbers (e.g. `MU9D3LL/A`) to a watchlist. The dashboard queries
Apple's public fulfillment API for every part at once and shows which nearby
stores have each model in stock for pickup.

- Default catalog covers Mac mini (M5), Mac Studio (M5 Max), MacBook Air
  (M5), MacBook Pro (M5 Pro). Apple retires part numbers periodically — if a
  SKU comes back as "Product(s) Invalid or not buyable", replace it in
  `products.json` / `data/products.json` with a current part number.
- Add via the **＋ Add** button or by editing `data/products.json` directly.
- Auto-refreshes every 5 minutes; press Enter or hit Refresh to force.
- Double-click a name or part number to rename it inline; drag rows to reorder.

### Micro Center Open Box — `/microcenter`

Scrapes Micro Center's open-box listings for the four NY-area stores and shows
discount % vs. regular price. Switch between stores with one click.

- **Stores**: Westbury (065), Flushing (051), Yonkers (105), Brooklyn (115).
  Edit `MC_STORES` in `lib/microcenter.php` to add others.
- **Categories** auto-discovered from each scrape; chip-filterable with counts.
- **Sort by**: % off (default), $ saved, price ↑, price ↓.
- **Refresh**: triggers a live scrape via the FlareSolverr sidecar (~10–30s per
  store for the first Cloudflare solve, then faster as the session cookie is
  reused); results cached on the volume so reloads are instant.
- Discount tiers color-coded: green ≥ 30%, amber ≥ 15%, plain below.

> Micro Center is protected by **Cloudflare Turnstile** ("Just a moment…"), not
> Akamai. A plain HTTP GET — and even headless Chromium alone — gets the
> challenge page, not the listing. The scraper therefore proxies every page
> through **FlareSolverr** (`mc_render_via_solver()` in `lib/microcenter.php`),
> which returns the solved, rendered HTML. Chromium `--dump-dom` is kept only as
> a last-resort fallback when FlareSolverr is unavailable. To point at a
> different solver, set `FLARESOLVERR_URL` (default `http://flaresolverr:8191`).

### Best Buy Open Box — `/bestbuy`

Uses Best Buy's official `openBox` endpoint. Each product can have multiple
condition tiers (each with its own price), and the tracker flattens those into
individual rows so a Satisfactory listing at 40% off ranks above an Excellent
listing at 15% off.

- **Conditions**: Excellent-Certified, Excellent, Satisfactory, Fair —
  filterable.
- Customer ratings shown inline.
- One refresh ≈ 30 API calls and finishes in ~5–15s.
- Data cached on the volume; the page renders the cache on load.

## Architecture

One PHP front controller (`index.php`) replaces FastAPI. No framework, no
Composer — it runs under **Apache + mod_php** (official `php:8.3-apache` image,
with Chromium added for the Micro Center scraper). Apache serves `/static/*`
natively and concurrently, and routes everything else to `index.php` via
`FallbackResource`, so a slow Apple scrape can never block CSS/JS or other
requests.

```
index.php                  # router / front controller (FallbackResource target)
lib/
  common.php              # curl HTTP client, JSON helpers, file cache
  products.php            # Apple catalog load/save + CRUD
  apple.php               # Apple pickup-availability client
  microcenter.php         # MC scraper (FlareSolverr + chromium fallback) + cache
  bestbuy.php             # BB API client + cache
views/                    # HTML shells: home, apple, microcenter, bestbuy
static/
  style.css               # shared, calm design system
  app.js                  # Apple dashboard logic
  mc.js                   # Micro Center dashboard logic
  bb.js                   # Best Buy dashboard logic
products.json             # Default Apple catalog (seeds the volume)
```

Caches live on the mounted volume next to `products.json`:

```
data/
├── products.json            # Apple catalog (editable)
├── apple_cache.json         # last Apple check per zip (30s TTL)
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

For production use just build the image (`docker compose build && docker compose
up -d`) — it runs under Apache and is concurrency-safe. For a quick local hack
without Apache:

```bash
# needs php-cli >= 8.1 and chromium installed as `chromium`
export DEFAULT_ZIP=11793
export BESTBUY_API_KEY=your-key-here   # optional
php -S 0.0.0.0:8000 index.php
```

> The built-in `php -S` dev server is single-threaded, so a slow Apple/Micro
> Center refresh blocks other requests (CSS/JS) until it finishes. Use the
> Apache image for anything you actually rely on.

The container runs as `www-data`; the entrypoint makes the mounted `/data`
writable (and relabels it with `:Z` under rootless podman) so the catalog and
caches can be written. `display_errors` is off so a failed cache write can never
corrupt an API response with a PHP warning.

## Notes

- The Apple tracker hits an undocumented `pickup-message` endpoint that's been
  stable for years — but it's not contractual. Parser lives in `lib/apple.php`.
  It already handles the 2025 endpoint move (`/shop/retail/pickup-message`) and
  both the old and new response shapes.
- The Micro Center scraper uses tolerant fallback selectors so minor HTML
  changes won't break it. The one function to patch if Akamai changes its
  challenge is `mc_render_html()` in `lib/microcenter.php`.
- The Best Buy tracker uses the *official* Open Box API, so it's the most
  reliable of the three. If a request returns 403 it almost always means the API
  key is wrong or expired.
