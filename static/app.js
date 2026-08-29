// Apple Stock Tracker — frontend. Vanilla JS, no framework.
const $ = (sel) => document.querySelector(sel);
const els = {
  zip: $("#zip"),
  refresh: $("#refresh"),
  addToggle: $("#add-toggle"),
  addPanel: $("#add-panel"),
  addForm: $("#add-form"),
  status: $("#status-text"),
  statusDot: $("#status-dot"),
  lastChecked: $("#last-checked"),
  categories: $("#categories"),
  storeCount: $("#store-count"),
};

const ZIP_KEY = "apple-stock:zip";
let catalog = { categories: [] };
let lastResult = null;

// fetch JSON with a hard timeout + no-store so a slow Apple call can never
// hang the UI forever, and we never serve a stale catalog.
async function fetchJson(url, opts = {}) {
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), 50000);
  try {
    const r = await fetch(url, { cache: "no-store", signal: ctrl.signal, ...opts });
    if (!r.ok) {
      const d = await r.json().catch(() => ({}));
      throw new Error(d.detail || `HTTP ${r.status}`);
    }
    return await r.json();
  } finally {
    clearTimeout(t);
  }
}

async function init() {
  try {
    const savedZip = localStorage.getItem(ZIP_KEY);
    catalog = await fetchProducts();
    els.zip.value = savedZip || catalog.default_zip || "";
    await refresh();
  } catch (e) {
    setStatus("err", e.message || "Failed to load catalog");
  }
}

async function fetchProducts() {
  return fetchJson("/api/products");
}
async function checkStock(zip) {
  return fetchJson(`/api/check?zip=${encodeURIComponent(zip)}`);
}
async function addProduct(payload) {
  const r = await fetch("/api/products", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
  if (!r.ok) { const d = await r.json().catch(() => ({})); throw new Error(d.detail || `HTTP ${r.status}`); }
  return r.json();
}
async function editProduct(oldPart, edits) {
  const r = await fetch(`/api/products/${encodeURIComponent(oldPart)}`, {
    method: "PATCH",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(edits),
  });
  if (!r.ok) { const d = await r.json().catch(() => ({})); throw new Error(d.detail || `HTTP ${r.status}`); }
  return r.json();
}
async function editCategory(categoryId, edits) {
  const r = await fetch(`/api/categories/${encodeURIComponent(categoryId)}`, {
    method: "PATCH",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(edits),
  });
  if (!r.ok) { const d = await r.json().catch(() => ({})); throw new Error(d.detail || `HTTP ${r.status}`); }
  return r.json();
}
async function deleteProduct(part) {
  const r = await fetch(`/api/products/${encodeURIComponent(part)}`, { method: "DELETE" });
  if (!r.ok) throw new Error(`HTTP ${r.status}`);
  return r.json();
}

function makeEditable(el, saveFn) {
  el.title = "Double-click to edit";
  el.addEventListener("dblclick", (e) => {
    e.stopPropagation();
    if (el.querySelector("input")) return;
    const original = el.textContent.trim();
    const input = document.createElement("input");
    input.className = "inline-edit";
    input.value = original;
    el.textContent = "";
    el.appendChild(input);
    input.focus();
    input.select();
    async function commit() {
      const val = input.value.trim();
      if (!val || val === original) { el.textContent = original; return; }
      try {
        catalog = await saveFn(val);
        catalog.default_zip = els.zip.value;
        render();
      } catch (err) {
        el.textContent = original;
        alert(`Could not save: ${err.message}`);
      }
    }
    input.addEventListener("keydown", (ev) => {
      if (ev.key === "Enter") { ev.preventDefault(); input.blur(); }
      if (ev.key === "Escape") { el.textContent = original; }
    });
    input.addEventListener("blur", commit);
  });
}

async function refresh() {
  const zip = (els.zip.value || "").trim();
  if (!zip) { setStatus("err", "Enter a ZIP code"); return; }
  localStorage.setItem(ZIP_KEY, zip);
  setStatus("loading", "Checking Apple…");
  try {
    lastResult = await checkStock(zip);
    catalog = await fetchProducts();
    catalog.default_zip = zip;
    render();
    const failed = (lastResult.failed_parts || []).length;
    let msg = `Found ${lastResult.stores.length} store${lastResult.stores.length === 1 ? "" : "s"} near ${zip}`;
    if (failed) msg += ` · ${failed} part${failed === 1 ? "" : "s"} rejected by Apple`;
    setStatus(failed ? "err" : "ok", msg);
    els.lastChecked.textContent = `updated ${formatTime(new Date())}`;
    els.storeCount.textContent = `${lastResult.stores.length} stores`;
  } catch (e) {
    setStatus("err", e.message || "Failed to fetch");
  }
}

function setStatus(kind, text) {
  els.status.textContent = text;
  els.statusDot.className = `dot dot-${kind}`;
}
function formatTime(d) {
  return d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", second: "2-digit" });
}

function render() {
  els.categories.innerHTML = "";
  if (!catalog.categories.length) {
    els.categories.innerHTML = `<div class="empty"><p>No products tracked yet. Click ＋ Add to start.</p></div>`;
    return;
  }
  const stores = (lastResult && lastResult.stores) || [];
  const failedSet = new Set((lastResult && lastResult.failed_parts) || []);

  for (const cat of catalog.categories) {
    const section = document.createElement("section");
    section.className = "category";
    section.dataset.category = cat.id;

    const header = document.createElement("div");
    header.className = "category-header";
    const h2 = document.createElement("h2");
    h2.textContent = cat.name;
    const countSpan = document.createElement("span");
    countSpan.className = "category-count";
    countSpan.textContent = `${cat.products.length} TRACKED`;
    header.appendChild(h2);
    header.appendChild(countSpan);
    section.appendChild(header);

    makeEditable(h2, (newName) => editCategory(cat.id, { name: newName }));

    for (const product of cat.products) {
      const stocked = stores.filter((s) => s.parts[product.part] && s.parts[product.part].available);
      const allChecked = stores.filter((s) => s.parts[product.part]);
      const isRejected = failedSet.has(product.part);

      let pill;
      if (isRejected) {
        pill = `<span class="availability-pill pill-unavailable" title="Apple rejected this part — likely a retired SKU">invalid SKU</span>`;
      } else if (allChecked.length === 0) {
        pill = `<span class="availability-pill pill-unknown">no data</span>`;
      } else if (stocked.length > 0) {
        pill = `<span class="availability-pill pill-available">in stock · ${stocked.length}</span>`;
      } else {
        pill = `<span class="availability-pill pill-unavailable">unavailable</span>`;
      }

      const row = document.createElement("div");
      row.className = "product";
      row.draggable = true;
      row.dataset.part = product.part;
      row.dataset.category = cat.id;
      row.innerHTML = `
        <div class="product-row" data-part="${escapeHtml(product.part)}">
          <div class="product-info">
            <div class="product-name">${escapeHtml(product.name)}</div>
            <div class="product-part">${escapeHtml(product.part)}</div>
          </div>
          ${pill}
          <div class="store-count">${allChecked.length} stores</div>
          <button class="delete-btn" data-delete="${escapeHtml(product.part)}" title="Stop tracking">×</button>
        </div>
        <div class="stores-detail"></div>
      `;

      makeEditable(row.querySelector(".product-name"), (newName) => editProduct(product.part, { name: newName }));
      makeEditable(row.querySelector(".product-part"), async (newPart) => {
        const updated = await editProduct(product.part, { part: newPart });
        lastResult = null;
        return updated;
      });

      const detail = row.querySelector(".stores-detail");
      if (allChecked.length === 0) {
        detail.innerHTML = `<div class="store-item"><span class="store-name" style="color:var(--ink-3)">No store data for this part.</span></div>`;
      } else {
        for (const s of allChecked) {
          const info = s.parts[product.part];
          const cls = info.available ? "available" : "unavailable";
          detail.innerHTML += `
            <div class="store-item">
              <div>
                <span class="store-name">${escapeHtml(s.store_name || "Unknown")}</span>
                <span class="store-location">${escapeHtml([s.city, s.state].filter(Boolean).join(", "))}</span>
              </div>
              <span class="store-distance">${escapeHtml(s.distance || "")}</span>
              <span class="store-status ${cls}">${escapeHtml(info.status || (info.available ? "available" : "unavailable"))}</span>
            </div>`;
        }
      }
      section.appendChild(row);
    }
    els.categories.appendChild(section);
  }
}

function escapeHtml(s) {
  if (s == null) return "";
  return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

els.refresh.addEventListener("click", refresh);
els.zip.addEventListener("keydown", (e) => { if (e.key === "Enter") refresh(); });
els.addToggle.addEventListener("click", () => { els.addPanel.hidden = !els.addPanel.hidden; });
els.addForm.addEventListener("submit", async (e) => {
  e.preventDefault();
  const fd = new FormData(els.addForm);
  const payload = { category_id: fd.get("category_id").trim(), part: fd.get("part").trim(), name: fd.get("name").trim() };
  try {
    catalog = await addProduct(payload);
    catalog.default_zip = els.zip.value;
    els.addForm.reset();
    els.addPanel.hidden = true;
    await refresh();
  } catch (e) { alert(`Could not add: ${e.message}`); }
});

document.addEventListener("click", async (e) => {
  const del = e.target.closest("[data-delete]");
  if (del) {
    e.stopPropagation();
    if (!confirm(`Stop tracking ${del.dataset.delete}?`)) return;
    try { catalog = await deleteProduct(del.dataset.delete); await refresh(); }
    catch (err) { alert(`Could not delete: ${err.message}`); }
    return;
  }
  if (e.target.classList.contains("inline-edit")) return;
  if (e.target.classList.contains("product-name")) return;
  if (e.target.classList.contains("product-part")) return;
  const row = e.target.closest(".product-row");
  if (row) row.parentElement.classList.toggle("expanded");
});

let dragSource = null;
document.addEventListener("dragstart", (e) => {
  const product = e.target.closest(".product");
  if (!product) return;
  if (e.target.classList.contains("inline-edit")) { e.preventDefault(); return; }
  dragSource = product;
  product.classList.add("dragging");
  e.dataTransfer.effectAllowed = "move";
  e.dataTransfer.setData("text/plain", product.dataset.part);
});
document.addEventListener("dragend", (e) => {
  const product = e.target.closest(".product");
  if (product) product.classList.remove("dragging");
  document.querySelectorAll(".drop-above, .drop-below, .drop-into").forEach(el => el.classList.remove("drop-above", "drop-below", "drop-into"));
  dragSource = null;
});
document.addEventListener("dragover", (e) => {
  if (!dragSource) return;
  const targetProduct = e.target.closest(".product");
  const targetCategory = e.target.closest(".category");
  document.querySelectorAll(".drop-above, .drop-below, .drop-into").forEach(el => el.classList.remove("drop-above", "drop-below", "drop-into"));
  if (targetProduct && targetProduct !== dragSource) {
    e.preventDefault();
    const rect = targetProduct.getBoundingClientRect();
    targetProduct.classList.add(e.clientY < rect.top + rect.height / 2 ? "drop-above" : "drop-below");
  } else if (targetCategory && !targetProduct) {
    e.preventDefault();
    targetCategory.classList.add("drop-into");
  }
});
document.addEventListener("drop", async (e) => {
  if (!dragSource) return;
  e.preventDefault();
  const targetProduct = e.target.closest(".product");
  const targetCategory = e.target.closest(".category");
  let newCategoryId = null, newPosition = null;
  if (targetProduct && targetProduct !== dragSource) {
    newCategoryId = targetProduct.dataset.category;
    const rect = targetProduct.getBoundingClientRect();
    const dropAbove = e.clientY < rect.top + rect.height / 2;
    const targetCat = catalog.categories.find(c => c.id === newCategoryId);
    if (targetCat) {
      const idx = targetCat.products.findIndex(p => p.part === targetProduct.dataset.part);
      newPosition = dropAbove ? idx : idx + 1;
      if (dragSource.dataset.category === newCategoryId) {
        const srcIdx = targetCat.products.findIndex(p => p.part === dragSource.dataset.part);
        if (srcIdx < idx) newPosition = Math.max(0, newPosition - 1);
      }
    }
  } else if (targetCategory) {
    newCategoryId = targetCategory.dataset.category;
    const targetCat = catalog.categories.find(c => c.id === newCategoryId);
    newPosition = targetCat ? targetCat.products.length : 0;
  } else return;

  const sourcePart = dragSource.dataset.part;
  try {
    const edits = { position: newPosition };
    if (newCategoryId !== dragSource.dataset.category) edits.category_id = newCategoryId;
    catalog = await editProduct(sourcePart, edits);
    catalog.default_zip = els.zip.value;
    render();
  } catch (err) { alert(`Could not move: ${err.message}`); }
});

setInterval(refresh, 5 * 60 * 1000);
init();
