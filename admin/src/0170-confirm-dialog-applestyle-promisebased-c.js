// =============================================================
// 🪟 CONFIRM DIALOG — Apple-style promise-based confirm()
// =============================================================
window.confirmDialog = function({ title = 'Potvrzení', msg = '', okText = 'Pokračovat', cancelText = 'Zrušit', danger = false, icon = null } = {}) {
  return new Promise(resolve => {
    const overlay = document.createElement('div');
    overlay.className = 'confirm-overlay';
    const ico = icon ?? (danger ? '⚠️' : '❓');
    overlay.innerHTML = `
      <div class="confirm-card">
        <div class="confirm-icon">${ico}</div>
        <h3 class="confirm-title">${esc(title)}</h3>
        <p class="confirm-msg">${esc(msg)}</p>
        <div class="confirm-actions">
          <button class="confirm-cancel">${esc(cancelText)}</button>
          <button class="${danger ? 'confirm-danger' : 'confirm-primary'}">${esc(okText)}</button>
        </div>
      </div>
    `;
    document.body.appendChild(overlay);
    requestAnimationFrame(() => overlay.classList.add('show'));
    const close = (result) => {
      overlay.classList.remove('show');
      setTimeout(() => overlay.remove(), 200);
      document.removeEventListener('keydown', onKey);
      resolve(result);
    };
    overlay.querySelector('.confirm-cancel').onclick = () => close(false);
    overlay.querySelector('.confirm-primary, .confirm-danger').onclick = () => close(true);
    overlay.onclick = (e) => { if (e.target === overlay) close(false); };
    const onKey = (e) => {
      if (e.key === 'Escape') close(false);
      if (e.key === 'Enter') close(true);
    };
    document.addEventListener('keydown', onKey);
    setTimeout(() => overlay.querySelector('.confirm-primary, .confirm-danger')?.focus(), 200);
  });
};

// 🆕 v3.0.244 — stylový prompt() (Apple-style, jako confirmDialog). Vrací zadaný text,
//   nebo null při Zrušit/Escape. Nahrazuje nativní blokující window.prompt v adminu.
window.promptDialog = function({ title = 'Zadej hodnotu', msg = '', value = '', placeholder = '', okText = 'OK', cancelText = 'Zrušit', icon = '✏️', inputType = 'text' } = {}) {
  return new Promise(resolve => {
    const overlay = document.createElement('div');
    overlay.className = 'confirm-overlay';
    overlay.innerHTML = `
      <div class="confirm-card">
        <div class="confirm-icon">${icon}</div>
        <h3 class="confirm-title">${esc(title)}</h3>
        ${msg ? `<p class="confirm-msg">${esc(msg)}</p>` : ''}
        <input type="${esc(inputType)}" class="confirm-input form-input" value="${esc(value)}" placeholder="${esc(placeholder)}"
               style="width:100%;margin:6px 0 4px;box-sizing:border-box">
        <div class="confirm-actions">
          <button class="confirm-cancel">${esc(cancelText)}</button>
          <button class="confirm-primary">${esc(okText)}</button>
        </div>
      </div>
    `;
    document.body.appendChild(overlay);
    const input = overlay.querySelector('.confirm-input');
    requestAnimationFrame(() => overlay.classList.add('show'));
    const close = (result) => {
      overlay.classList.remove('show');
      setTimeout(() => overlay.remove(), 200);
      document.removeEventListener('keydown', onKey);
      resolve(result);
    };
    overlay.querySelector('.confirm-cancel').onclick = () => close(null);
    overlay.querySelector('.confirm-primary').onclick = () => close(input.value);
    overlay.onclick = (e) => { if (e.target === overlay) close(null); };
    const onKey = (e) => {
      if (e.key === 'Escape') close(null);
      if (e.key === 'Enter') close(input.value);
    };
    document.addEventListener('keydown', onKey);
    setTimeout(() => { input.focus(); input.select(); }, 200);
  });
};

