// Best Buy Open Box Hunter — frontend.
const state = { snapshot: null, sort: "discount_pct", category: "All", condition: "All", minPct: 0, search: "" };

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

async function loadDeals() {
  $("#deals").innerHTML = '<div class="loading"><span class="spinner"></span>loading cached deals…</div>';
  state.snapshot = await fetch("/bestbuy/api/deals").then(r => r.json());
  state.category = "All";
  state.condition = "All";
  renderConditionChips();
  renderCategoryChips();
  renderKeyBanner();
  render();
}
async function refresh() {
  const btn = $("#refresh-btn");
  btn.disabled = true;
  const orig = btn.textContent;
  btn.innerHTML = '<span class="spinner" style="border-top-color:#fff"></span>fetching…';
  $("#deals").innerHTML = '<div class="loading"><span class="spinner"></span>fetching live from best buy api… this takes 5–15s</div>';
  try {
    state.snapshot = await fetch("/bestbuy/api/refresh", { method: "POST" }).then(r => r.json());
    renderConditionChips();
    renderCategoryChips();
    renderKeyBanner();
    render();
  } catch (e) {
    $("#deals").innerHTML = `<div class="empty"><div class="big">Refresh failed</div>${escapeHtml(e.message || e)}</div>`;
  } finally {
    btn.disabled = false;
    btn.textContent = orig;
  }
}
function renderKeyBanner() {
  const banner = $("#key-warning");
  if (state.snapshot && !state.snapshot.source) banner.removeAttribute("hidden");
  else banner.setAttribute("hidden", "");
}
function renderConditionChips() {
  const counts = {};
  (state.snapshot?.deals || []).forEach(d => { counts[d.condition_label] = (counts[d.condition_label] || 0) + 1; });
  const order = ["Excellent — Certified", "Excellent", "Satisfactory", "Fair"];
  const labels = order.filter(l => counts[l]).concat(Object.keys(counts).filter(l => !order.includes(l)).sort());
  const wrap = $("#cond-chips");
  wrap.innerHTML = "";
  const total = (state.snapshot?.deals || []).length;
  const all = document.createElement("button");
  all.className = "chip" + (state.condition === "All" ? " active" : "");
  all.innerHTML = `All <span class="count">${total}</span>`;
  all.onclick = () => { state.condition = "All"; renderConditionChips(); render(); };
  wrap.appendChild(all);
  labels.forEach(l => {
    const b = document.createElement("button");
    b.className = "chip" + (state.condition === l ? " active" : "");
    b.innerHTML = `${escapeHtml(l)} <span class="count">${counts[l]}</span>`;
    b.onclick = () => { state.condition = l; renderConditionChips(); render(); };
    wrap.appendChild(b);
  });
}
function renderCategoryChips() {
  const counts = {};
  (state.snapshot?.deals || []).forEach(d => { counts[d.category] = (counts[d.category] || 0) + 1; });
  const cats = Object.keys(counts).sort((a, b) => counts[b] - counts[a]);
  const wrap = $("#cat-chips");
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
  const snap = state.snapshot || { deals: [], fetched_at: null, error: null, api_key_set: true };
  $("#stat-time").textContent = fmtTime(snap.fetched_at);
  $("#stat-count").textContent = (snap.deals || []).length;
  $("#stat-key").textContent = snap.source === "scraper" ? "scraper" : (snap.api_key_set ? "set" : "missing");
  $("#stat-key").style.color = snap.source ? "var(--ok)" : "var(--bad)";

  let deals = (snap.deals || []).slice();
  if (state.category !== "All") deals = deals.filter(d => d.category === state.category);
  if (state.condition !== "All") deals = deals.filter(d => d.condition_label === state.condition);
  if (state.minPct > 0) deals = deals.filter(d => d.discount_pct >= state.minPct);
  if (state.search) {
    const q = state.search.toLowerCase();
    deals = deals.filter(d => d.name.toLowerCase().includes(q));
  }
  const reversed = state.sort.startsWith("-");
  const sortKey = reversed ? state.sort.slice(1) : state.sort;
  const ascending = sortKey === "open_box_price" ? !reversed : reversed;
  deals.sort((a, b) => ascending ? a[sortKey] - b[sortKey] : b[sortKey] - a[sortKey]);
  $("#meta-total").textContent = deals.length;

  if (snap.error && (!snap.deals || snap.deals.length === 0)) {
     $("#deals").innerHTML = `<div class="empty"><div class="big">No data yet</div>${escapeHtml(snap.error)}<br><br>${!snap.source ? "Set <b>BESTBUY_API_KEY</b> or <b>FLARESOLVERR_URL</b>, then hit <b>↻ refresh</b>." : "Hit <b>↻ refresh</b> to fetch live."}</div>`;
    return;
  }
  if (deals.length === 0) {
    $("#deals").innerHTML = `<div class="empty"><div class="big">No matches</div>Try a different category, condition, or lower the min %.</div>`;
    return;
  }
  $("#deals").innerHTML = deals.map(d => {
    const img = d.image
      ? `<img src="${escapeHtml(d.image)}" alt="" loading="lazy" onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'ph',textContent:'no img'}))">`
      : `<span class="ph">no img</span>`;
    const stars = d.rating != null
      ? `<span class="stars">★ <b>${Number(d.rating).toFixed(1)}</b>${d.review_count ? ` <span style="color:var(--ink-3)">(${Number(d.review_count).toLocaleString()})</span>` : ""}</span>`
      : "";
    return `
      <a class="row" href="${escapeHtml(d.url)}" target="_blank" rel="noopener">
        <span class="img">${img}</span>
        <span class="name-block">
          <div class="name">${escapeHtml(d.name)}</div>
          <div class="meta">
            <span class="cond">${escapeHtml(d.condition_label)}</span>
            <span class="cat">${escapeHtml(d.category)}</span>
            <span>SKU ${escapeHtml(d.sku)}</span>
            ${stars}
          </div>
        </span>
        <span class="price">
          <div class="reg">${fmt$(d.regular_price)}</div>
          <div class="ob">${fmt$(d.open_box_price)}</div>
        </span>
        <span class="save">
          <div class="d">−${fmt$(d.discount_dollars)}</div>
          <div class="pct ${tierFor(d.discount_pct)}">${fmtPct(d.discount_pct)}</div>
        </span>
        <span class="arrow">→</span>
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

loadDeals();
setInterval(() => { if (state.snapshot) $("#stat-time").textContent = fmtTime(state.snapshot.fetched_at); }, 30000);
