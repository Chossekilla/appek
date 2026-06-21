// =============================================================
// 🖥️ FULLSCREEN — pro malé monitory v provoze (F11 alternativa)
// =============================================================
window.toggleFullscreen = function() {
  const doc = document.documentElement;
  const isFs = !!(document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement);
  try {
    if (!isFs) {
      if (doc.requestFullscreen) doc.requestFullscreen();
      else if (doc.webkitRequestFullscreen) doc.webkitRequestFullscreen();
      else if (doc.msRequestFullscreen) doc.msRequestFullscreen();
    } else {
      if (document.exitFullscreen) document.exitFullscreen();
      else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
      else if (document.msExitFullscreen) document.msExitFullscreen();
    }
  } catch (e) {
    alert('Celá obrazovka není dostupná: ' + e.message);
  }
};
// Sleduj změnu stavu (uživatel může stisknout Esc) — přepíná class na <html>
document.addEventListener('fullscreenchange', () => {
  document.documentElement.classList.toggle('is-fullscreen',
    !!(document.fullscreenElement || document.webkitFullscreenElement));
});
document.addEventListener('webkitfullscreenchange', () => {
  document.documentElement.classList.toggle('is-fullscreen',
    !!(document.fullscreenElement || document.webkitFullscreenElement));
});

// 🆕 v2.9.29 — BULLETPROOF: hide fullscreen button na mobilu (JS backup pro případ že CSS nefunguje)
(function hideFsOnMobile() {
  function apply() {
    const btn = document.getElementById('fullscreen-btn');
    if (!btn) return;
    const isMobile = window.matchMedia && window.matchMedia('(max-width: 700px)').matches;
    btn.style.display = isMobile ? 'none' : '';
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', apply);
  } else {
    apply();
  }
  window.addEventListener('resize', apply);
  // Re-apply několikrát kvůli pozdějšímu loadu DOM po loginu
  setTimeout(apply, 100);
  setTimeout(apply, 500);
  setTimeout(apply, 1500);
})();

function openModal(title, body, size = '') {
  document.getElementById('modal-title').textContent = title;
  document.getElementById('modal-body').innerHTML = body;
  const modal = document.getElementById('modal');
  modal.style.display = 'flex';
  // Aplikuj size class na modal-card
  const card = modal.querySelector('.modal-card');
  if (card) {
    card.classList.remove('modal-wide');
    if (size === 'wide') card.classList.add('modal-wide');
  }
  // Aplikuj data-label na tabulky uvnitř modalu (pro mobilní kartové zobrazení)
  if (typeof labelizeTables === 'function') {
    setTimeout(labelizeTables, 0);
  }
  // 🆕 v3.0.347 — autofocus prvního pole + Esc zavře modal (parita s confirmDialog)
  setTimeout(() => { const fe = card && card.querySelector('input:not([type=hidden]):not([disabled]),select,textarea'); if (fe) { try { fe.focus(); } catch (e) {} } }, 60);
  if (!modal._escBound) {
    modal._escBound = true;
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && modal.style.display === 'flex') closeModal(); });
  }
}
window.closeModal = function() {
  document.getElementById('modal').style.display = 'none';
  // Reset jednorázových návratových flagů (ulozitSurovinu si flag zachytí lokálně před voláním closeModal)
  if (typeof state !== 'undefined' && state) state._sur_return_to_kalkulace = false;
};

/**
 * Přepíná taby uvnitř modalu. Tabové tlačítko musí mít atribut data-tab="X"
 * a obsahový panel <div class="modal-tab-pane" data-pane="X">.
 */
window.switchModalTab = function(tabName) {
  const modal = document.getElementById('modal');
  modal.querySelectorAll('.modal-tab').forEach(t => {
    t.classList.toggle('active', t.dataset.tab === tabName);
  });
  modal.querySelectorAll('.modal-tab-pane').forEach(p => {
    p.classList.toggle('active', p.dataset.pane === tabName);
  });
};

