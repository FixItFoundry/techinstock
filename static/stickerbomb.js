/**
 * techinstock — StickerBomb Background Engine
 * Sourced from thesvg (https://thesvg.org / https://github.com/glincker/thesvg)
 * and modeled after edgeofsanity76/StickerBomb
 * 
 * Generates an organic, dense, layered die-cut vinyl sticker bomb
 * collage across the background of the viewport and full scroll area.
 */

(function () {
  'use strict';

  const STICKERS = [
    // Core Store Trackers
    { id: 'apple', name: 'Apple', file: 'apple.svg', w: 75, h: 75 },
    { id: 'microcenter', name: 'Micro Center', file: 'microcenter.svg', w: 140, h: 60 },
    { id: 'bestbuy', name: 'Best Buy', file: 'bestbuy.svg', w: 110, h: 65 },

    // Primary User-Requested Hardware Brands
    { id: 'asus', name: 'ASUS', file: 'asus.svg', w: 130, h: 48 },
    { id: 'asusrog', name: 'ROG', file: 'asusrog.svg', w: 90, h: 80 },
    { id: 'acer', name: 'Acer', file: 'acer.svg', w: 120, h: 45 },
    { id: 'hp', name: 'HP', file: 'hp.svg', w: 75, h: 75 },
    { id: 'hyperx', name: 'HyperX', file: 'hyperx.svg', w: 130, h: 50 },
    { id: 'razer', name: 'Razer', file: 'razer.svg', w: 130, h: 45 },
    { id: 'dell', name: 'Dell', file: 'dell.svg', w: 75, h: 75 },
    { id: 'alienware', name: 'Alienware', file: 'alienware.svg', w: 85, h: 85 },
    { id: 'xps', name: 'Dell XPS', file: 'xps.svg', w: 120, h: 48 },
    { id: 'sony', name: 'Sony', file: 'sony.svg', w: 120, h: 40 },
    { id: 'playstation', name: 'PlayStation', file: 'playstation.svg', w: 95, h: 75 },
    { id: 'microsoft', name: 'Microsoft', file: 'microsoft.svg', w: 120, h: 48 },
    { id: 'windows', name: 'Windows', file: 'windows.svg', w: 75, h: 75 },
    { id: 'xbox', name: 'Xbox', file: 'xbox.svg', w: 80, h: 80 },
    { id: 'gpd', name: 'GPD', file: 'gpd.svg', w: 125, h: 48 },
    { id: 'minisforum', name: 'Minisforum', file: 'minisforum.svg', w: 135, h: 45 },
    { id: 'fractaldesign', name: 'Fractal Design', file: 'fractaldesign.svg', w: 130, h: 45 },
    { id: 'thermalright', name: 'Thermalright', file: 'thermalright.svg', w: 130, h: 50 },
    { id: 'asrock', name: 'ASRock', file: 'asrock.svg', w: 125, h: 45 },
    { id: 'gigabyte', name: 'Gigabyte', file: 'gigabyte.svg', w: 125, h: 45 },
    { id: 'msi', name: 'MSI', file: 'msi.svg', w: 115, h: 48 },

    // Powerhouse Component & Semiconductor Giants
    { id: 'nvidia', name: 'NVIDIA', file: 'nvidia.svg', w: 130, h: 48 },
    { id: 'amd', name: 'AMD', file: 'amd.svg', w: 115, h: 45 },
    { id: 'intel', name: 'Intel', file: 'intel.svg', w: 110, h: 50 },
    { id: 'corsair', name: 'Corsair', file: 'corsair.svg', w: 125, h: 45 },
    { id: 'logitechg', name: 'Logitech G', file: 'logitechg.svg', w: 85, h: 85 },
    { id: 'nzxt', name: 'NZXT', file: 'nzxt.svg', w: 115, h: 45 },
    { id: 'noctua', name: 'Noctua', file: 'noctua.svg', w: 125, h: 48 },
    { id: 'framework', name: 'Framework', file: 'framework.svg', w: 80, h: 80 },
    { id: 'steamdeck', name: 'Steam Deck', file: 'steamdeck.svg', w: 125, h: 45 },
    { id: 'steam', name: 'Steam', file: 'steam.svg', w: 80, h: 80 },
    { id: 'valve', name: 'Valve', file: 'valve.svg', w: 110, h: 40 },
    { id: 'lenovo', name: 'Lenovo', file: 'lenovo.svg', w: 120, h: 45 },
    { id: 'samsung', name: 'Samsung', file: 'samsung.svg', w: 125, h: 45 },
    { id: 'raspberrypi', name: 'Raspberry Pi', file: 'raspberrypi.svg', w: 75, h: 90 },

    // Hardware Enthusiast, Cooling, Audio & Storage Brands from thesvg
    { id: 'steelseries', name: 'SteelSeries', file: 'steelseries.svg', w: 120, h: 48 },
    { id: 'coolermaster', name: 'Cooler Master', file: 'coolermaster.svg', w: 120, h: 50 },
    { id: 'deepcool', name: 'DeepCool', file: 'deepcool.svg', w: 115, h: 45 },
    { id: 'seagate', name: 'Seagate', file: 'seagate.svg', w: 120, h: 45 },
    { id: 'kingston', name: 'Kingston', file: 'kingston.svg', w: 120, h: 45 },
    { id: 'elgato', name: 'Elgato', file: 'elgato.svg', w: 80, h: 80 },
    { id: 'anker', name: 'Anker', file: 'anker.svg', w: 120, h: 45 },
    { id: 'audiotechnica', name: 'Audio-Technica', file: 'audiotechnica.svg', w: 80, h: 80 },
    { id: 'bose', name: 'Bose', file: 'bose.svg', w: 110, h: 40 },
    { id: 'broadcom', name: 'Broadcom', file: 'broadcom.svg', w: 120, h: 45 },
    { id: 'qualcomm', name: 'Qualcomm', file: 'qualcomm.svg', w: 125, h: 45 },
    { id: 'arm', name: 'ARM', file: 'arm.svg', w: 100, h: 45 },
    { id: 'netgear', name: 'Netgear', file: 'netgear.svg', w: 120, h: 45 },
    { id: 'tplink', name: 'TP-Link', file: 'tplink.svg', w: 115, h: 45 },
    { id: 'synology', name: 'Synology', file: 'synology.svg', w: 125, h: 45 },
    { id: 'qnap', name: 'QNAP', file: 'qnap.svg', w: 115, h: 45 },
    { id: 'ubiquiti', name: 'Ubiquiti', file: 'ubiquiti.svg', w: 80, h: 80 },
    { id: 'sennheiser', name: 'Sennheiser', file: 'sennheiser.svg', w: 120, h: 40 },
    { id: 'lg', name: 'LG', file: 'lg.svg', w: 75, h: 75 },
    { id: 'panasonic', name: 'Panasonic', file: 'panasonic.svg', w: 125, h: 45 },
    { id: 'toshiba', name: 'Toshiba', file: 'toshiba.svg', w: 120, h: 45 },
    { id: 'viewsonic', name: 'ViewSonic', file: 'viewsonic.svg', w: 125, h: 45 },
    { id: 'wacom', name: 'Wacom', file: 'wacom.svg', w: 115, h: 45 }
  ];

  // Pseudo-random generator with seed for repeatable/stable organic layouts per page
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

      // Grid spacing for organic overlapping density
      const colSpacing = 105;
      const rowSpacing = 90;
      const cols = Math.ceil(docWidth / colSpacing) + 1;
      const rows = Math.ceil(docHeight / rowSpacing) + 1;

      const rand = createRandom(108 + cols * 19 + rows * 37);
      const shuffled = [...STICKERS].sort(() => rand() - 0.5);
      let stickerIdx = 0;

      const fragment = document.createDocumentFragment();

      for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
          // Jitter offsets
          const jitterX = (rand() - 0.5) * 50;
          const jitterY = (rand() - 0.5) * 40;
          const posX = c * colSpacing + jitterX - 25;
          const posY = r * rowSpacing + jitterY - 25;

          // Rotation: between -30deg and +30deg
          const rot = Math.round((rand() - 0.5) * 60);
          // Scale: between 0.85 and 1.25
          const scale = (0.85 + rand() * 0.4).toFixed(2);
          // Layer depth
          const zIndex = Math.floor(rand() * 10) + 1;

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
          img.loading = 'lazy';
          img.style.maxWidth = `${sticker.w}px`;
          img.style.maxHeight = `${sticker.h}px`;

          inner.appendChild(img);
          item.appendChild(inner);

          // Subtle interactive hover effect
          item.addEventListener('mouseenter', () => {
            item.style.zIndex = 999;
            item.style.transform = `rotate(${rot * 0.3}deg) scale(${parseFloat(scale) * 1.22}) translateY(-8px)`;
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

    // Debounced resize handler
    let resizeTimer = null;
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(render, 250);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initStickerBomb);
  } else {
    initStickerBomb();
  }
})();
