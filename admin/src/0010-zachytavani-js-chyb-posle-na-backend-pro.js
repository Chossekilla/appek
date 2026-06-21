// =============================================================
// 🚨 ZACHYTÁVÁNÍ JS CHYB — pošle na backend pro diagnostiku
// =============================================================
(function setupErrorCapture() {
  let lastReportTs = 0;
  function reportError(payload) {
    const now = Date.now();
    if (now - lastReportTs < 1000) return;
    lastReportTs = now;
    try {
      fetch('../api/admin_klient_chyby.php', {
        method: 'POST',
        credentials: 'include',
        headers: csrfHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({
          app: 'admin',
          ...payload,
          url: location.href,
          user_info: (state?.admin?.jmeno || 'admin') + ' (' + (state?.admin?.role || '?') + ')',
        }),
      }).catch(() => {});
    } catch (e) {}
  }
  window.addEventListener('error', (e) => {
    reportError({
      msg: e.message || 'Unknown error',
      source: e.filename || '',
      line: e.lineno || 0,
      col: e.colno || 0,
      stack: e.error?.stack || '',
    });
  });
  window.addEventListener('unhandledrejection', (e) => {
    reportError({
      msg: '[Promise] ' + (e.reason?.message || String(e.reason)),
      stack: e.reason?.stack || '',
    });
  });
})();

