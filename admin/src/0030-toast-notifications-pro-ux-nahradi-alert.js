// =============================================================
// 🍞 TOAST NOTIFICATIONS — pro UX (nahradí alert())
// =============================================================
(function() {
  let host = null;
  function ensureHost() {
    if (!host) {
      host = document.createElement('div');
      host.className = 'toast-host';
      document.body.appendChild(host);
    }
    return host;
  }
  function toast(opts) {
    if (typeof opts === 'string') opts = { msg: opts };
    const { msg, title, type = 'info', duration = 4000 } = opts;
    const host = ensureHost();
    const icons = { success: '✓', error: '⚠', warn: '⚠', info: 'ⓘ' };
    const el = document.createElement('div');
    el.className = `toast toast-${type}`;
    el.style.setProperty('--toast-duration', duration + 'ms');
    el.innerHTML = `
      <div class="toast-icon">${icons[type] || icons.info}</div>
      <div class="toast-body">
        ${title ? `<div class="toast-title">${esc(title)}</div>` : ''}
        <div class="toast-msg">${esc(msg || '')}</div>
      </div>
      <button class="toast-close" aria-label="Zavřít">✕</button>
    `;
    const dismiss = () => {
      el.classList.add('is-closing');
      el.classList.remove('show');
      setTimeout(() => el.remove(), 250);
    };
    el.onclick = (e) => { if (e.target.closest('.toast-close') || e.target === el) dismiss(); };
    host.appendChild(el);
    requestAnimationFrame(() => el.classList.add('show'));
    if (duration > 0) setTimeout(dismiss, duration);
    return { dismiss };
  }
  window.toast = toast;
  window.toastSuccess = (msg, title) => toast({ msg, title, type: 'success' });
  window.toastError   = (msg, title) => toast({ msg, title, type: 'error', duration: 6000 });
  window.toastWarn    = (msg, title) => toast({ msg, title, type: 'warn',  duration: 5000 });
  window.toastInfo    = (msg, title) => toast({ msg, title, type: 'info' });
})();

