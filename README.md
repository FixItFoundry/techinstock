# Tech in Stock

A self-hosted dashboard for tracking tech availability, clearance, and open-box deals across **Apple**, **Micro Center**, and **Best Buy**. Built with a lightweight PHP 8.3 container and an urban cyberpunk aesthetic.

```
                         ┌──────────────────────┐
                         │   /  (Landing Hub)   │
                         └────┬───────┬────┬────┘
                              │       │    │
               ┌──────────────┘       │    └─────────────┐
               ▼                      ▼                  ▼
         /apple                 /microcenter         /bestbuy
      (Apple Stock)            (MC Open Box)       (BB Open Box)
```

---

## ⚡ Quick Start

```bash
git clone https://github.com/FixItFoundry/techinstock.git
cd techinstock

# Start with Docker Compose or Podman Compose
docker compose up -d --build
# or
podman-compose up -d
```

Open **`http://localhost:8765`** in your browser.

- **Apple Stock** and **Micro Center** trackers work out of the box.
- **Best Buy** requires a free API key from [developer.bestbuy.com](https://developer.bestbuy.com).

---

## 🛠️ Trackers & Methodology

### 1. Apple Stock (`/apple`)
Tracks local Apple Store pickup availability for custom and retail SKUs.
- **Methodology**: Directly queries Apple's official `pickup-message` fulfillment endpoint for all tracked SKUs near a given ZIP code.
- **Features**:
  - Watchlist management (M4/M5 Pro/Max MacBook, Mac Studio, iPad, etc.).
  - Inline SKU and category editing with drag-and-drop reordering.
  - Configurable ZIP code with persistent local caching.

### 2. Micro Center Open Box (`/microcenter`)
Scrapes open-box clearance inventory across **29 Micro Center locations nationwide across 19 US States**.
- **Methodology**: Queries Micro Center's full open-box catalog using a **FlareSolverr** sidecar to navigate Cloudflare protection, parsing live pricing and discount tiers.
- **Features**:
  - **State & Location Selector**: Filter deals by US State (CA, CO, FL, GA, IL, IN, KS, MA, MD, MI, MN, MO, NC, NJ, NY, OH, PA, TX, VA) and local store.
  - **Discount Rankings**: Ranked by highest **% Off** first, **$ Saved**, or Price.
  - **Category Chips**: Auto-discovered categories (Laptops, Apple, Desktops, GPUs, Monitors, etc.) with real-time deal counts.

### 3. Best Buy Open Box (`/bestbuy`)
Pulls catalog-wide open-box pricing and verified discount rankings via official APIs.
- **Methodology**: Uses Best Buy's Developer API (`openBox` endpoint) across major consumer electronics categories.
- **Features**:
  - **Condition Breakdown**: Distinguishes between *Excellent-Certified*, *Excellent*, *Satisfactory*, and *Fair*.
  - Live customer review scores, retail vs. open-box savings, and direct product links.

---

## ⚙️ Configuration

Set environment variables in `docker-compose.yml`:

| Variable | Default | Description |
|---|---|---|
| `DEFAULT_ZIP` | `11793` | Default ZIP code for Apple pickup checks. |
| `PRODUCTS_FILE` | `/data/products.json` | Persistent JSON file storing tracked Apple watchlists. |
| `BESTBUY_API_KEY` | *(unset)* | Developer API key from [developer.bestbuy.com](https://developer.bestbuy.com). |
| `FLARESOLVERR_URL` | `http://flaresolverr:8191` | FlareSolverr sidecar URL for Micro Center scraping. |

### Adding Best Buy API Key
1. Get a free key at [developer.bestbuy.com](https://developer.bestbuy.com).
2. Add `BESTBUY_API_KEY: "your_key"` to `docker-compose.yml`.
3. Restart: `docker compose up -d`.

---

## 🏗️ Architecture

- **Backend**: Native PHP 8.3 with Apache (`mod_php`) front controller (`index.php`). No bloated dependencies or frameworks.
- **Scraper Pipeline**: FlareSolverr sidecar for automated Cloudflare resolution + headless Chromium fallback.
- **Storage**: Lightweight JSON cache files persisted in `/data` volume.
- **Frontend**: Vanilla ES6 JavaScript + modern CSS with sticker-bomb cyberpunk styling and verified SVG brand assets.

```
techinstock/
├── index.php              # Front controller & API routing
├── lib/
│   ├── apple.php          # Apple fulfillment API client
│   ├── microcenter.php    # Micro Center scraper & nationwide store definitions
│   ├── bestbuy.php        # Best Buy developer API client
│   ├── products.php       # Watchlist CRUD
│   └── common.php         # HTTP & JSON helpers
├── views/                 # HTML templates (home, apple, microcenter, bestbuy)
├── static/                # CSS design system, SVG logos, and client JS
└── docker-compose.yml     # Multi-container orchestration (App + FlareSolverr)
```

---

## 🔌 API Endpoints

### Apple
- `GET /api/products` — List watchlist items
- `POST /api/products` — Add product SKU
- `PATCH /api/products/{part}` — Edit tracked SKU
- `DELETE /api/products/{part}` — Delete tracked SKU
- `GET /api/check?zip={zip}` — Check local store stock

### Micro Center
- `GET /microcenter/api/stores` — List all 29 US stores grouped by State
- `GET /microcenter/api/deals?store={store_key}` — Fetch cached deals
- `POST /microcenter/api/refresh?store={store_key}` — Trigger live scrape

### Best Buy
- `GET /bestbuy/api/status` — API key & cache status
- `GET /bestbuy/api/deals` — Fetch cached open-box deals
- `POST /bestbuy/api/refresh` — Trigger live API sync

---

## 📄 License

MIT License. Designed for personal and homelab tracking.
