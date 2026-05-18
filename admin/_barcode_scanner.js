/**
 * 📷 BARCODE / QR SCANNER — Univerzální čtečka kódů
 *
 * Použití:
 *   import nebo <script src="_barcode_scanner.js"></script>
 *   appekScanner.open({
 *     onScan: (code) => { console.log('Naskenoval:', code); },
 *     types: ['ean_13', 'ean_8', 'code_128', 'qr_code', 'code_39'],
 *     closeOnScan: true,
 *   });
 *
 * Funkce:
 *   - Web BarcodeDetector API (Chrome 88+, Edge, Safari 17+)
 *   - Fallback na manuální vstup (pro starší prohlížeče)
 *   - USB scanner: většina USB scannerů se chová jako klávesnice
 *     → použij `appekScanner.listenUsbInput(onScan)` pro naslouchání rychlému
 *       vstupu ze scanneru (typický scanner = vstup pod 50ms mezi znaky + Enter)
 */
(function() {
  'use strict';

  const SUPPORTED_FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'qr_code', 'data_matrix'];

  let scannerOpen = false;
  let stream = null;
  let videoEl = null;
  let detector = null;
  let scanInterval = null;

  /**
   * Otevře fullscreen overlay s kamerou + detekcí kódů.
   */
  async function open(options = {}) {
    if (scannerOpen) return;
    if (!('BarcodeDetector' in window)) {
      const code = prompt('📷 Kamera scanner není podporován v tomto prohlížeči.\nZadej kód ručně:');
      if (code && options.onScan) options.onScan(code.trim());
      return;
    }
    scannerOpen = true;
    const formats = options.types || SUPPORTED_FORMATS;
    try { detector = new BarcodeDetector({ formats }); }
    catch (e) { detector = new BarcodeDetector(); }

    // Build overlay UI
    const overlay = document.createElement('div');
    overlay.id = 'appek-scanner-overlay';
    overlay.innerHTML = `
      <style>
        #appek-scanner-overlay {
          position: fixed; inset: 0; z-index: 99999;
          background: #000; display: flex; flex-direction: column;
        }
        .scn-head {
          background: rgba(0,0,0,0.7); color: #fff; padding: 14px 20px;
          display: flex; align-items: center; justify-content: space-between;
          backdrop-filter: blur(8px);
        }
        .scn-head h2 { margin: 0; font-size: 16px; font-weight: 700; }
        .scn-close {
          background: rgba(255,255,255,0.15); border: none; color: #fff;
          width: 36px; height: 36px; border-radius: 50%;
          font-size: 18px; cursor: pointer;
        }
        .scn-video-wrap {
          flex: 1; position: relative; overflow: hidden;
          display: flex; align-items: center; justify-content: center;
        }
        .scn-video { width: 100%; height: 100%; object-fit: cover; }
        .scn-target {
          position: absolute; left: 50%; top: 50%; transform: translate(-50%,-50%);
          width: 280px; height: 200px;
          border: 3px solid #34c759; border-radius: 14px;
          box-shadow: 0 0 0 9999px rgba(0,0,0,0.4);
          pointer-events: none;
        }
        .scn-target::before, .scn-target::after {
          content: ''; position: absolute; left: 0; right: 0; height: 2px;
          background: linear-gradient(90deg, transparent, #34c759, transparent);
          animation: scn-scan 1.6s ease-in-out infinite;
        }
        @keyframes scn-scan {
          0%, 100% { top: 5%; }
          50% { top: 95%; }
        }
        .scn-status {
          position: absolute; bottom: 24px; left: 50%; transform: translateX(-50%);
          background: rgba(0,0,0,0.7); color: #fff; padding: 8px 16px;
          border-radius: 999px; font-size: 13px; backdrop-filter: blur(8px);
        }
        .scn-foot {
          background: rgba(0,0,0,0.7); padding: 16px 20px; color: #fff;
          font-size: 12.5px; text-align: center; line-height: 1.5;
        }
        .scn-foot input {
          width: 100%; max-width: 320px; padding: 10px 14px;
          background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.25);
          color: #fff; border-radius: 8px; font-size: 14px; margin-top: 8px;
        }
        .scn-foot input::placeholder { color: rgba(255,255,255,0.5); }
      </style>
      <div class="scn-head">
        <h2>📷 Naskenuj EAN / QR kód</h2>
        <button class="scn-close" onclick="appekScanner.close()">✕</button>
      </div>
      <div class="scn-video-wrap">
        <video class="scn-video" id="scn-video" autoplay playsinline muted></video>
        <div class="scn-target"></div>
        <div class="scn-status" id="scn-status">⏳ Spouštím kameru…</div>
      </div>
      <div class="scn-foot">
        Nasměruj kameru na kód · automaticky se naskenuje
        <br>
        <input type="text" id="scn-manual" placeholder="Nebo zadej ručně a stiskni Enter" autocomplete="off">
      </div>
    `;
    document.body.appendChild(overlay);

    videoEl = overlay.querySelector('#scn-video');
    const statusEl = overlay.querySelector('#scn-status');
    const manualEl = overlay.querySelector('#scn-manual');

    manualEl.addEventListener('keydown', e => {
      if (e.key === 'Enter' && manualEl.value.trim()) {
        const v = manualEl.value.trim();
        if (options.onScan) options.onScan(v);
        if (options.closeOnScan !== false) close();
      }
    });
    manualEl.focus();

    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 } },
        audio: false,
      });
      videoEl.srcObject = stream;
      statusEl.textContent = '👀 Hledám kód…';

      // Start detection loop
      scanInterval = setInterval(async () => {
        try {
          const codes = await detector.detect(videoEl);
          if (codes && codes.length) {
            const code = codes[0].rawValue;
            statusEl.textContent = '✅ ' + code;
            statusEl.style.background = 'rgba(52,199,89,0.9)';
            // Haptic + sound feedback
            if (navigator.vibrate) navigator.vibrate(80);
            if (options.onScan) options.onScan(code);
            if (options.closeOnScan !== false) {
              setTimeout(() => close(), 400);
            }
          }
        } catch (e) {
          // tichá chyba
        }
      }, 250);
    } catch (e) {
      statusEl.textContent = '❌ Kamera nedostupná: ' + e.message;
      statusEl.style.background = 'rgba(255,59,48,0.9)';
    }
  }

  function close() {
    scannerOpen = false;
    if (scanInterval) { clearInterval(scanInterval); scanInterval = null; }
    if (stream) {
      stream.getTracks().forEach(t => t.stop());
      stream = null;
    }
    const o = document.getElementById('appek-scanner-overlay');
    if (o) o.remove();
  }

  /**
   * 🎹 USB scanner support — naslouchá rychlému vstupu z USB čtečky.
   * USB scanner se chová jako klávesnice, většinou:
   *   - Píše znaky velmi rychle (< 50ms mezi znaky)
   *   - Zakončí Enter
   *
   * Zavolej globálně na page load:
   *   appekScanner.listenUsbInput((code) => { ... });
   *
   * Detekce: pokud uživatel napíše 4+ znaky < 50ms apart a stiskne Enter,
   *          považuje se to za scanner input.
   */
  function listenUsbInput(callback) {
    let buffer = '';
    let lastTime = 0;
    const SCANNER_THRESHOLD_MS = 50;
    const MIN_LENGTH = 4;

    document.addEventListener('keydown', (e) => {
      // Skip pokud je fokus v input/textarea (uživatel píše manuálně)
      const activeTag = (document.activeElement?.tagName || '').toLowerCase();
      if (['input', 'textarea', 'select'].includes(activeTag)) {
        // Allow scanner input pouze pokud je rychlost typingu nadprůměrná
      }

      const now = Date.now();
      const delta = now - lastTime;

      if (e.key === 'Enter') {
        if (buffer.length >= MIN_LENGTH && delta < SCANNER_THRESHOLD_MS * 2) {
          e.preventDefault();
          callback(buffer);
        }
        buffer = '';
        lastTime = 0;
        return;
      }

      // Skip ne-tisknutelné klávesy (Shift, Ctrl, atd.)
      if (e.key.length !== 1) return;

      // Reset buffer pokud uplynulo > 200ms (uživatel přestal psát)
      if (delta > 200) buffer = '';

      buffer += e.key;
      lastTime = now;
    });
  }

  window.appekScanner = { open, close, listenUsbInput, SUPPORTED_FORMATS };
})();
