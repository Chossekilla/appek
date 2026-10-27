// =============================================================
// 🐢 v3.0.622 — HLÍDAČ ZASEKNUTÍ (diagnostika „na iPhonu to zamrzne")
// =============================================================
// Desktopový WebKit ani Chrome emulace zaseknutí nereprodukují → sbírej data z reálného zařízení:
//   • hlavní vlákno zablokované > 2,5 s (heartbeat mezera, jen když je stránka vidět)
//   • stránka visí na „Načítám…" > 8 s po posledním klepnutí
// → záznam do klient_chyby (stejná cesta jako 0010) s posledním klepnutím, stránkou a scroll stavem.
//   Max 5 záznamů na relaci, nic neblokuje (setInterval + passive listenery).
(function setupJankWatchdog() {
  let sent = 0;
  let lastTap = { t: 0, what: '' };
  const send = (msg, extra) => {
    if (sent >= 5) return;
    sent++;
    try {
      fetch('../api/admin_klient_chyby.php', {
        method: 'POST',
        credentials: 'include',
        headers: (typeof csrfHeaders === 'function') ? csrfHeaders({ 'Content-Type': 'application/json' }) : { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          app: 'admin',
          msg: '[ZASEKNUTI] ' + msg,
          stack: JSON.stringify(Object.assign({
            page: (typeof state !== 'undefined' && state.current) || '',
            lastTap: lastTap.what, msSinceTap: lastTap.t ? Date.now() - lastTap.t : null,
            bodyScrollTop: document.body.scrollTop, bodyScrollH: document.body.scrollHeight, vh: window.innerHeight,
            pinned: document.body.classList.contains('sidebar-pinned'),
            nodes: document.getElementsByTagName('*').length,
          }, extra || {})).slice(0, 1800),
          url: location.href,
          user_info: ((typeof state !== 'undefined' && state.admin && state.admin.jmeno) || 'admin') + ' (' + ((typeof state !== 'undefined' && state.admin && state.admin.role) || '?') + ')',
        }),
      }).catch(() => {});
    } catch (e) {}
  };

  const markTap = (e) => {
    const el = e.target && e.target.closest ? e.target.closest('button,a,[onclick],label,input,select') : null;
    lastTap = { t: Date.now(), what: el ? ((el.getAttribute('onclick') || el.textContent || el.tagName) + '').trim().slice(0, 90) : String(e.target && e.target.tagName) };
  };
  document.addEventListener('click', markTap, { capture: true, passive: true });
  document.addEventListener('touchend', markTap, { capture: true, passive: true });

  // 1) zablokované hlavní vlákno (na pozadí se časovače škrtí → po návratu do aplikace měření vynuluj)
  let last = performance.now();
  document.addEventListener('visibilitychange', () => { last = performance.now(); });
  setInterval(() => {
    const now = performance.now();
    const gap = now - last;
    last = now;
    if (document.visibilityState === 'visible' && gap > 2500) send('hlavní vlákno blokováno ' + Math.round(gap) + ' ms', { gapMs: Math.round(gap) });
  }, 500);

  // 2) stránka visí na „Načítám…" dlouho po klepnutí
  setInterval(() => {
    if (document.visibilityState !== 'visible' || !lastTap.t || lastTap.reported) return;
    const since = Date.now() - lastTap.t;
    if (since < 8000 || since > 20000) return;
    const c = document.getElementById('content');
    if (c && c.childElementCount <= 2 && /Načítám/.test(c.textContent || '')) {
      lastTap.reported = true;
      send('stránka visí na Načítám ' + Math.round(since / 1000) + ' s', { contentText: (c.textContent || '').trim().slice(0, 120) });
    }
  }, 2000);
})();
