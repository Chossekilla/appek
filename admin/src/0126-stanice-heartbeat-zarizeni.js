// =============================================================
// 📱 STANICE — heartbeat zařízení (admin/kuchyň)
// =============================================================
// Zaregistruje toto zařízení a drží ho „online" v přehledu Nástroje → Stanice.
// Trvalý token v localStorage identifikuje zařízení napříč reloady.
// Ping jde jen když je uživatel přihlášený (máme CSRF token z loginu).
(function stationHeartbeatAdmin() {
  function stationToken() {
    let t = '';
    try { t = localStorage.getItem('appek_station_token') || ''; } catch (e) {}
    if (!/^[a-f0-9]{24,}$/.test(t)) {
      const a = new Uint8Array(16);
      if (self.crypto && crypto.getRandomValues) crypto.getRandomValues(a);
      else for (let i = 0; i < a.length; i++) a[i] = Math.floor(Math.random() * 256);
      t = Array.from(a, b => b.toString(16).padStart(2, '0')).join('');
      try { localStorage.setItem('appek_station_token', t); } catch (e) {}
    }
    return t;
  }
  function defaultName() {
    const ua = navigator.userAgent || '';
    let os = 'zařízení';
    if (/iPad/.test(ua)) os = 'iPad';
    else if (/iPhone/.test(ua)) os = 'iPhone';
    else if (/Android/.test(ua)) os = 'Android';
    else if (/Macintosh|Mac OS/.test(ua)) os = 'Mac';
    else if (/Windows/.test(ua)) os = 'Windows';
    else if (/Linux/.test(ua)) os = 'Linux';
    return 'Admin · ' + os;
  }
  async function ping() {
    let csrf = '';
    try { csrf = (typeof state !== 'undefined' && state && state.csrfToken) || localStorage.getItem('appek_csrf_token') || ''; } catch (e) {}
    if (!csrf) return; // nepřihlášen → nepinguj (jméno by stejně dostalo 401)
    if (typeof api !== 'function') return;
    try {
      await api('admin_stanice.php?action=ping', {
        method: 'POST',
        body: { token: stationToken(), role: 'admin', nazev: defaultName() },
      });
    } catch (e) { /* tiše — offline/nepřihlášen */ }
  }
  const kick = () => setTimeout(ping, 3000);
  if (document.readyState !== 'loading') kick();
  else document.addEventListener('DOMContentLoaded', kick);
  setInterval(ping, 40000); // heartbeat každých 40 s (server počítá online do 90 s)
})();
