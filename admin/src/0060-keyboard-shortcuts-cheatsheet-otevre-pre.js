// =============================================================
// ⌨️ KEYBOARD SHORTCUTS CHEATSHEET — `?` otevře přehled
// =============================================================
window.openKbdCheat = function() {
  if (document.querySelector('.kbd-cheat-overlay')) return;
  const overlay = document.createElement('div');
  overlay.className = 'kbd-cheat-overlay';
  overlay.innerHTML = `
    <div class="kbd-cheat">
      <div class="kbd-cheat-head">
        <h2>⌨️ Klávesové zkratky</h2>
        <button class="modal-close" onclick="closeKbdCheat()"></button>
      </div>
      <div class="kbd-cheat-body">
        <div class="kbd-section">
          <div class="kbd-section-title">Global</div>
          <div class="kbd-row"><span>Otevřít vyhledávání (Command Palette)</span><span class="kbd-keys"><kbd>⌘</kbd><kbd>K</kbd></span></div>
          <div class="kbd-row"><span>Tato nápověda</span><span class="kbd-keys"><kbd>?</kbd></span></div>
          <div class="kbd-row"><span>Zavřít modal / panel</span><span class="kbd-keys"><kbd>Esc</kbd></span></div>
          <div class="kbd-row"><span>Fullscreen toggle</span><span class="kbd-keys"><kbd>F11</kbd></span></div>
        </div>
        <div class="kbd-section">
          <div class="kbd-section-title">Quick navigation</div>
          <div class="kbd-row"><span>Přehled</span><span class="kbd-keys"><kbd>G</kbd><kbd>D</kbd></span></div>
          <div class="kbd-row"><span>Objednávky</span><span class="kbd-keys"><kbd>G</kbd><kbd>O</kbd></span></div>
          <div class="kbd-row"><span>Výrobky</span><span class="kbd-keys"><kbd>G</kbd><kbd>P</kbd></span></div>
          <div class="kbd-row"><span>Faktury</span><span class="kbd-keys"><kbd>G</kbd><kbd>F</kbd></span></div>
          <div class="kbd-row"><span>Odběratelé</span><span class="kbd-keys"><kbd>G</kbd><kbd>C</kbd></span></div>
          <div class="kbd-row"><span>Nastavení</span><span class="kbd-keys"><kbd>G</kbd><kbd>S</kbd></span></div>
        </div>
        <div class="kbd-section">
          <div class="kbd-section-title">Quick actions</div>
          <div class="kbd-row"><span>Nová objednávka</span><span class="kbd-keys"><kbd>N</kbd><kbd>O</kbd></span></div>
          <div class="kbd-row"><span>Nový výrobek</span><span class="kbd-keys"><kbd>N</kbd><kbd>P</kbd></span></div>
          <div class="kbd-row"><span>Nový odběratel</span><span class="kbd-keys"><kbd>N</kbd><kbd>C</kbd></span></div>
        </div>
        <div class="kbd-section">
          <div class="kbd-section-title">V seznamech</div>
          <div class="kbd-row"><span>Hledání</span><span class="kbd-keys"><kbd>/</kbd></span></div>
          <div class="kbd-row"><span>Vybrat/zrušit vše</span><span class="kbd-keys"><kbd>⌘</kbd><kbd>A</kbd></span></div>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
  requestAnimationFrame(() => overlay.classList.add('show'));
  overlay.onclick = (e) => { if (e.target === overlay) closeKbdCheat(); };
};
window.closeKbdCheat = function() {
  const ov = document.querySelector('.kbd-cheat-overlay');
  if (!ov) return;
  ov.classList.remove('show');
  setTimeout(() => ov.remove(), 200);
};

// Sekvenční klávesy: G+page, N+entity
(function() {
  let chord = null;
  let chordTimer = null;
  function pageMap() {
    return { d: 'dashboard', o: 'objednavky', p: 'vyrobky', f: 'faktury', c: 'odberatele', s: 'nastaveni', l: 'dodaci_listy', h: 'haccp' };
  }
  function newMap() {
    return {
      o: () => window.otevritNovouObjednavku?.(),
      p: () => window.editVyrobek?.(),
      c: () => window.editOdberatel?.(),
    };
  }
  document.addEventListener('keydown', (e) => {
    if (e.target.matches('input, textarea, [contenteditable]')) return;
    // ? → cheatsheet
    if (e.key === '?' && !e.metaKey && !e.ctrlKey) { e.preventDefault(); openKbdCheat(); return; }
    // Chord start
    if (!chord && (e.key === 'g' || e.key === 'n') && !e.metaKey && !e.ctrlKey && !e.altKey) {
      chord = e.key;
      chordTimer = setTimeout(() => { chord = null; }, 1500);
      return;
    }
    if (chord === 'g') {
      const target = pageMap()[e.key.toLowerCase()];
      if (target) { e.preventDefault(); navigate(target); }
      chord = null; clearTimeout(chordTimer);
    } else if (chord === 'n') {
      const fn = newMap()[e.key.toLowerCase()];
      if (fn) { e.preventDefault(); fn(); }
      chord = null; clearTimeout(chordTimer);
    }
  });
})();

