// Micro Center Open Box Hunter — frontend.
const state = {
  stores: {},
  storeKey: null,
  selectedState: "NY",
  snapshot: null,
  sort: "-discount_pct",
  category: "All",
  minPct: 0,
  search: "",
};

const STATE_NAMES = {
  CA: "California",
  CO: "Colorado",
  FL: "Florida",
  GA: "Georgia",
  IL: "Illinois",
  IN: "Indiana",
  KS: "Kansas",
  MA: "Massachusetts",
  MD: "Maryland",
  MI: "Michigan",
  MN: "Minnesota",
  MO: "Missouri",
  NC: "North Carolina",
  NJ: "New Jersey",
  NY: "New York",
  OH: "Ohio",
  PA: "Pennsylvania",
  TX: "Texas",
  VA: "Virginia"
};

const $ = (s) => document.querySelector(s);
const fmt$ = (n) => "$" + Number(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
const fmtPct = (n) => Number(n).toFixed(1) + "%";
const fmtTime = (ts) => {
  if (!ts) return "never";
  const diff = Date.now() / 1000 - ts;
  if (diff < 60) return "just now";
  if (diff < 3600) return Math.floor(diff / 60) + "m ago";
  if (diff < 86400) return Math.floor(diff / 3600) + "h ago";
  return new Date(ts * 1000).toLocaleDateString();
};
const tierFor = (pct) => pct >= 30 ? "tier-3" : pct >= 15 ? "tier-2" : "tier-1";
const escapeHtml = (s) => String(s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

async function loadStores() {
  const d = await fetch("/microcenter/api/stores").then(r => r.json());
  state.stores = d.stores;
  
  const params = new URLSearchParams(window.location.search);
  const paramStore = params.get("store");
  if (paramStore && state.stores[paramStore]) {
    state.storeKey = paramStore;
    state.selectedState = state.stores[paramStore].state || "NY";
  } else {
    state.storeKey = d.default || "westbury";
    state.selectedState = (state.stores[state.storeKey] && state.stores[state.storeKey].state) || "NY";
  }

  renderStateDropdown();
  renderStoreTabs();
  await loadDeals();
}

function renderStateDropdown() {
  const select = $("#state-select");
  if (!select) return;
  
  const statesSet = new Set();
  for (const meta of Object.values(state.stores)) {
    if (meta.state) statesSet.add(meta.state);
  }
  const states = Array.from(statesSet).sort();
  
  select.innerHTML = "";
  for (const st of states) {
    const opt = document.createElement("option");
    opt.value = st;
    opt.textContent = `${st} — ${STATE_NAMES[st] || st}`;
    if (st === state.selectedState) opt.selected = true;
    select.appendChild(opt);
  }

  select.onchange = (e) => {
    state.selectedState = e.target.value;
    const firstStoreKey = Object.keys(state.stores).find(k => state.stores[k].state === state.selectedState);
    if (firstStoreKey) {
      state.storeKey = firstStoreKey;
    }
    renderStoreTabs();
    loadDeals();
  };
}

function renderStoreTabs() {
  const tabs = $("#store-tabs");
  if (!tabs) return;
  tabs.innerHTML = "";
  
  const matchingStores = Object.entries(state.stores).filter(([k, meta]) => meta.state === state.selectedState);
  
  for (const [key, meta] of matchingStores) {
    const b = document.createElement("button");
    b.className = "chip" + (key === state.storeKey ? " active" : "");
    b.textContent = meta.name.split(",")[0];
    b.onclick = () => {
      state.storeKey = key;
      renderStoreTabs();
      loadDeals();
    };
    tabs.appendChild(b);
  }
}

async function loadDeals() {
  $("#deals").innerHTML = '<div class="loading"><span class="spinner"></span>loading cached deals…</div>';
  state.snapshot = await fetch("/microcenter/api/deals?store=" + state.storeKey).then(r => r.json());
  state.category = "All";
  renderCategoryChips();
  render();
}

async function refresh() {
  const btn = $("#refresh-btn");
  btn.disabled = true;
  const orig = btn.textContent;
  btn.innerHTML = '<span class="spinner" style="border-top-color:#fff"></span>scraping…';
  $("#deals").innerHTML = '<div class="loading"><span class="spinner"></span>fetching live from micro center… this can take 10–30s</div>';
  try {
    state.snapshot = await fetch("/microcenter/api/refresh?store=" + state.storeKey, { method: "POST" }).then(r => r.json());
    renderCategoryChips();
    render();
  } catch (e) {
    $("#deals").innerHTML = `<div class="empty"><div class="big">Refresh failed</div>${escapeHtml(e.message || e)}</div>`;
  } finally {
    btn.disabled = false;
    btn.textContent = orig;
  }
}

function renderCategoryChips() {
  const counts = {};
  (state.snapshot?.deals || []).forEach(d => { counts[d.category] = (counts[d.category] || 0) + 1; });
  const cats = Object.keys(counts).sort((a, b) => counts[b] - counts[a]);
  const wrap = $("#cat-chips");
  if (!wrap) return;
  wrap.innerHTML = "";
  const total = (state.snapshot?.deals || []).length;
  const all = document.createElement("button");
  all.className = "chip" + (state.category === "All" ? " active" : "");
  all.innerHTML = `All <span class="count">${total}</span>`;
  all.onclick = () => { state.category = "All"; renderCategoryChips(); render(); };
  wrap.appendChild(all);
  cats.forEach(c => {
    const b = document.createElement("button");
    b.className = "chip" + (state.category === c ? " active" : "");
    b.innerHTML = `${escapeHtml(c)} <span class="count">${counts[c]}</span>`;
    b.onclick = () => { state.category = c; renderCategoryChips(); render(); };
    wrap.appendChild(b);
  });
}

function render() {
  const s = state.snapshot;
  const storeName = s?.store_name || state.stores[state.storeKey]?.name || "—";
  $("#stat-store").textContent = storeName;
  $("#stat-count").textContent = (s?.deals || []).length;
  $("#stat-time").textContent = fmtTime(s?.fetched_at);

  let deals = (s?.deals || []).slice();
  if (state.category !== "All") deals = deals.filter(d => d.category === state.category);
  if (state.minPct > 0) deals = deals.filter(d => d.discount_pct >= state.minPct);
  if (state.search) {
    const q = state.search.toLowerCase();
    deals = deals.filter(d => ((d.name || d.title || "") + " " + (d.category || "") + " " + (d.sku || "")).toLowerCase().includes(q));
  }

  const metaTotal = $("#meta-total");
  if (metaTotal) metaTotal.textContent = deals.length;

  const [key, dir] = state.sort.startsWith("-") ? [state.sort.slice(1), -1] : [state.sort, 1];
  deals.sort((a, b) => (Number(a[key]) - Number(b[key])) * dir);

  const list = $("#deals");
  if (!deals.length) {
    list.innerHTML = `<div class="empty"><div class="big">No deals found for ${escapeHtml(storeName)}</div>Try adjusting filters or clicking ↻ Fetch Live Scrape to run live collection.</div>`;
    return;
  }

  list.innerHTML = deals.map(d => {
    const title = d.name || d.title || "Micro Center Item";
    const imgUrl = d.image || d.image_url || "";
    const imgHtml = imgUrl
      ? `<img src="${escapeHtml(imgUrl)}" alt="${escapeHtml(title)}" loading="lazy" onerror="this.onerror=null; this.src='/static/microcenter-logo.svg'; this.style.padding='10px';">`
      : `<img src="/static/microcenter-logo.svg" alt="Micro Center" style="padding:10px;">`;
    
    const conditionText = d.condition || 'Open Box';

    return `
      <a class="row" href="${escapeHtml(d.url)}" target="_blank" rel="noopener">
        <div class="img">${imgHtml}</div>
        <div>
          <div class="name">${escapeHtml(title)}</div>
          <div class="meta">
            <span class="cond">${escapeHtml(conditionText)}</span>
            <span class="cat">${escapeHtml(d.category || "Other")}</span>
            <span>SKU ${escapeHtml(d.sku || "")}</span>
          </div>
        </div>
        <div class="price">
          <div class="reg">${fmt$(d.regular_price)}</div>
          <div class="ob">${fmt$(d.open_box_price)}</div>
        </div>
        <div class="save">
          <div class="d">−${fmt$(d.discount_dollars)}</div>
          <div class="pct ${tierFor(d.discount_pct)}">${fmtPct(d.discount_pct)}</div>
        </div>
        <div class="arrow">→</div>
      </a>`;
  }).join("");
}

document.querySelectorAll(".chip[data-sort]").forEach(b => {
  b.onclick = () => {
    state.sort = b.dataset.sort;
    document.querySelectorAll(".chip[data-sort]").forEach(x => x.classList.toggle("active", x === b));
    render();
  };
});
$("#min-pct").addEventListener("input", e => { state.minPct = +e.target.value || 0; render(); });
$("#search").addEventListener("input", e => { state.search = e.target.value.trim(); render(); });
$("#refresh-btn").onclick = refresh;

loadStores();
setInterval(() => { if (state.snapshot) $("#stat-time").textContent = fmtTime(state.snapshot.fetched_at); }, 30000);
