// =============================================================
// 🪐 EMPTY STATE helper
// =============================================================
window.emptyState = function({ icon = '📭', title = '', msg = '', actions = '' }) {
  return `
    <div class="empty-state-pro">
      <div class="es-icon">${icon}</div>
      <h3 class="es-title">${esc(title)}</h3>
      <p class="es-msg">${esc(msg)}</p>
      ${actions ? `<div class="es-actions">${actions}</div>` : ''}
    </div>
  `;
};

