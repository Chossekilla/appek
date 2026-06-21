// =============================================================
// 🪐 SMART ALERT INTERCEPT — krátké alerty → toast, dlouhé → modal
// =============================================================
(function() {
  const nativeAlert = window.alert.bind(window);
  window.nativeAlert = nativeAlert;
  window.alert = function(msg) {
    const m = String(msg ?? '');
    // Dlouhé zprávy (víceřádkové formuláře/detaily) → fallback na native alert
    if (m.length > 240 || (m.match(/\n/g) || []).length >= 3) {
      return nativeAlert(m);
    }
    // Auto-detekce typu z emoji prefixu / klíčových slov
    let type = 'info';
    if (/^❌|^⚠️|^🚫|^[Cc]hyba|nepoda[řr]ilo|selha|chybn[ýé]/i.test(m)) type = 'error';
    else if (/^✅|^✓|^🎉|^💾|^📥|hotov|uspe[šs]|uloženo|smazáno|vytvo[řr]eno/i.test(m)) type = 'success';
    else if (/^⚠️|^🟡|^💡|upozorn|pozor/i.test(m)) type = 'warn';
    // Strip emoji prefix
    const clean = m.replace(/^[✅✓❌⚠️🚫🎉💾📥🟡💡⏭️ⓘ]+\s*/, '').trim();
    window.toast({ msg: clean || m, type });
  };
})();

