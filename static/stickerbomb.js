/**
 * techinstock — High-Density StickerBomb Background Engine
 * Modeled after edgeofsanity76/StickerBomb
 * Sourced from thesvg (https://thesvg.org) & official brand assets
 * 
 * Creates an edge-to-edge, ultra-dense, multi-layered sticker bomb
 * collage covering the entire background.
 */

(function () {
  'use strict';

  // Primary user-requested brands (weighted heavily so they appear frequently across the wall)
  const PRIMARY_STICKERS = [
    { id: 'apple', name: 'Apple', file: 'apple.svg', w: 65, h: 65 },
    { id: 'microcenter', name: 'Micro Center', file: 'microcenter.svg', w: 95, h: 46 },
    { id: 'bestbuy', name: 'Best Buy', file: 'bestbuy.svg', w: 90, h: 52 },
    { id: 'asus', name: 'ASUS', file: 'asus.svg', w: 95, h: 36 },
    { id: 'asusrog', name: 'ASUS ROG', file: 'asusrog.svg', w: 65, h: 65 },
    { id: 'acer', name: 'Acer', file: 'acer.svg', w: 90, h: 34 },
    { id: 'hp', name: 'HP', file: 'hp.svg', w: 60, h: 60 },
    { id: 'hyperx', name: 'HyperX', file: 'hyperx.svg', w: 95, h: 38 },
    { id: 'razer', name: 'Razer', file: 'razer.svg', w: 90, h: 36 },
    { id: 'dell', name: 'Dell', file: 'dell.svg', w: 60, h: 60 },
    { id: 'alienware', name: 'Alienware', file: 'alienware.svg', w: 65, h: 65 },
    { id: 'xps', name: 'Dell XPS', file: 'xps.svg', w: 90, h: 36 },
    { id: 'sony', name: 'Sony', file: 'sony.svg', w: 90, h: 30 },
    { id: 'playstation', name: 'PlayStation', file: 'playstation.svg', w: 75, h: 58 },
    { id: 'microsoft', name: 'Microsoft', file: 'microsoft.svg', w: 90, h: 36 },
    { id: 'windows', name: 'Windows', file: 'windows.svg', w: 60, h: 60 },
    { id: 'xbox', name: 'Xbox', file: 'xbox.svg', w: 65, h: 65 },
    { id: 'gpd', name: 'GPD', file: 'gpd.svg', w: 90, h: 36 },
    { id: 'minisforum', name: 'Minisforum', file: 'minisforum.svg', w: 105, h: 34 },
    { id: 'fractaldesign', name: 'Fractal Design', file: 'fractaldesign.svg', w: 95, h: 34 },
    { id: 'thermalright', name: 'Thermalright', file: 'thermalright.svg', w: 95, h: 34 },
    { id: 'asrock', name: 'ASRock', file: 'asrock.svg', w: 95, h: 34 },
    { id: 'gigabyte', name: 'Gigabyte', file: 'gigabyte.svg', w: 95, h: 34 },
    { id: 'msi', name: 'MSI', file: 'msi.svg', w: 65, h: 65 }
  ];

  // Secondary tech brands from thesvg
  const SECONDARY_STICKERS = [
    { id: 'nvidia', name: 'NVIDIA', file: 'nvidia.svg', w: 95, h: 36 },
    { id: 'amd', name: 'AMD', file: 'amd.svg', w: 85, h: 36 },
    { id: 'intel', name: 'Intel', file: 'intel.svg', w: 85, h: 38 },
    { id: 'corsair', name: 'Corsair', file: 'corsair.svg', w: 85, h: 34 },
    { id: 'logitechg', name: 'Logitech G', file: 'logitechg.svg', w: 85, h: 34 },
    { id: 'nzxt', name: 'NZXT', file: 'nzxt.svg', w: 85, h: 34 },
    { id: 'noctua', name: 'Noctua', file: 'noctua.svg', w: 95, h: 34 },
    { id: 'framework', name: 'Framework', file: 'framework.svg', w: 60, h: 60 },
    { id: 'steamdeck', name: 'Steam Deck', file: 'steamdeck.svg', w: 95, h: 34 },
    { id: 'steam', name: 'Steam', file: 'steam.svg', w: 65, h: 65 },
    { id: 'valve', name: 'Valve', file: 'valve.svg', w: 85, h: 32 },
    { id: 'lenovo', name: 'Lenovo', file: 'lenovo.svg', w: 90, h: 34 },
    { id: 'samsung', name: 'Samsung', file: 'samsung.svg', w: 90, h: 34 },
    { id: 'raspberrypi', name: 'Raspberry Pi', file: 'raspberrypi.svg', w: 60, h: 70 },
    { id: 'steelseries', name: 'SteelSeries', file: 'steelseries.svg', w: 90, h: 36 },
    { id: 'coolermaster', name: 'Cooler Master', file: 'coolermaster.svg', w: 85, h: 36 },
    { id: 'deepcool', name: 'DeepCool', file: 'deepcool.svg', w: 85, h: 34 },
    { id: 'seagate', name: 'Seagate', file: 'seagate.svg', w: 90, h: 34 },
    { id: 'kingston', name: 'Kingston', file: 'kingston.svg', w: 90, h: 34 },
    { id: 'elgato', name: 'Elgato', file: 'elgato.svg', w: 60, h: 60 },
    { id: 'anker', name: 'Anker', file: 'anker.svg', w: 90, h: 34 },
    { id: 'audiotechnica', name: 'Audio-Technica', file: 'audiotechnica.svg', w: 60, h: 60 },
    { id: 'bose', name: 'Bose', file: 'bose.svg', w: 85, h: 30 },
    { id: 'broadcom', name: 'Broadcom', file: 'broadcom.svg', w: 90, h: 34 },
    { id: 'qualcomm', name: 'Qualcomm', file: 'qualcomm.svg', w: 90, h: 34 },
    { id: 'arm', name: 'ARM', file: 'arm.svg', w: 75, h: 34 },
    { id: 'netgear', name: 'Netgear', file: 'netgear.svg', w: 90, h: 34 },
    { id: 'tplink', name: 'TP-Link', file: 'tplink.svg', w: 85, h: 34 },
    { id: 'synology', name: 'Synology', file: 'synology.svg', w: 90, h: 34 },
    { id: 'qnap', name: 'QNAP', file: 'qnap.svg', w: 85, h: 34 },
    { id: 'ubiquiti', name: 'Ubiquiti', file: 'ubiquiti.svg', w: 60, h: 60 },
    { id: 'sennheiser', name: 'Sennheiser', file: 'sennheiser.svg', w: 90, h: 30 },
    { id: 'lg', name: 'LG', file: 'lg.svg', w: 60, h: 60 },
    { id: 'panasonic', name: 'Panasonic', file: 'panasonic.svg', w: 90, h: 34 },
    { id: 'toshiba', name: 'Toshiba', file: 'toshiba.svg', w: 90, h: 34 },
    { id: 'viewsonic', name: 'ViewSonic', file: 'viewsonic.svg', w: 90, h: 34 },
    { id: 'wacom', name: 'Wacom', file: 'wacom.svg', w: 85, h: 34 }
  ];

  // Weighted pool: primary logos repeat 3x so user-requested brands dominate
  const STICKER_POOL = [
    ...PRIMARY_STICKERS,
    ...PRIMARY_STICKERS,
    ...PRIMARY_STICKERS,
    ...SECONDARY_STICKERS
  ];

  function createRandom(seed) {
    let s = seed % 2147483647;
    if (s <= 0) s += 2147483646;
    return function () {
      return (s = (s * 16807) % 2147483647) / 2147483647;
    };
  }

  function initStickerBomb() {
    let container = document.getElementById('stickerbomb-canvas');
    if (!container) {
      container = document.createElement('div');
      container.id = 'stickerbomb-canvas';
      container.className = 'stickerbomb-canvas';
      document.body.prepend(container);
    }

    function render() {
      container.innerHTML = '';

      const docWidth = Math.max(document.documentElement.clientWidth || window.innerWidth, 1200);
      const docHeight = Math.max(
        document.documentElement.scrollHeight,
        document.body.scrollHeight,
        window.innerHeight,
        1000
      );

      // Tight grid spacing for authentic sticker-bomb overlap
      const colSpacing = 64;
      const rowSpacing = 54;
      const cols = Math.ceil(docWidth / colSpacing) + 2;
      const rows = Math.ceil(docHeight / rowSpacing) + 2;

      const rand = createRandom(149 + cols * 29 + rows * 43);
      const shuffled = [...STICKER_POOL].sort(() => rand() - 0.5);
      let stickerIdx = 0;

      const fragment = document.createDocumentFragment();

      for (let r = -1; r < rows; r++) {
        for (let c = -1; c < cols; c++) {
          // Organic jitter to break grid alignment
          const jitterX = (rand() - 0.5) * 38;
          const jitterY = (rand() - 0.5) * 32;
          const posX = Math.round(c * colSpacing + jitterX);
          const posY = Math.round(r * rowSpacing + jitterY);

          // Full authentic rotations: between -35deg and +35deg
          const rot = Math.round((rand() - 0.5) * 70);
          // Scale variation: between 0.90 and 1.25
          const scale = (0.90 + rand() * 0.35).toFixed(2);
          // Multi-layer z-index
          const zIndex = Math.floor(rand() * 25) + 1;

          const sticker = shuffled[stickerIdx % shuffled.length];
          stickerIdx++;

          const item = document.createElement('div');
          item.className = `sticker-bomb-item sticker-${sticker.id}`;
          item.title = sticker.name;
          item.style.left = `${posX}px`;
          item.style.top = `${posY}px`;
          item.style.zIndex = zIndex;
          item.style.transform = `rotate(${rot}deg) scale(${scale})`;
          item.dataset.rot = rot;
          item.dataset.scale = scale;

          const inner = document.createElement('div');
          inner.className = 'sticker-diecut-wrap';

          const img = document.createElement('img');
          img.src = `/static/stickers/${sticker.file}`;
          img.alt = sticker.name;
          img.className = 'sticker-img';
          img.width = sticker.w;
          img.height = sticker.h;
          img.style.width = `${sticker.w}px`;
          img.style.height = `${sticker.h}px`;

          inner.appendChild(img);
          item.appendChild(inner);

          // Interactive hover reaction
          item.addEventListener('mouseenter', () => {
            item.style.zIndex = 9999;
            item.style.transform = `rotate(${rot * 0.2}deg) scale(${parseFloat(scale) * 1.25}) translateY(-10px)`;
          });

          item.addEventListener('mouseleave', () => {
            item.style.zIndex = zIndex;
            item.style.transform = `rotate(${rot}deg) scale(${scale})`;
          });

          fragment.appendChild(item);
        }
      }

      container.appendChild(fragment);
    }

    render();

    // Re-render when page fully loads all assets
    window.addEventListener('load', render);

    // Debounced resize handler
    let resizeTimer = null;
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(render, 200);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initStickerBomb);
  } else {
    initStickerBomb();
  }
})();
