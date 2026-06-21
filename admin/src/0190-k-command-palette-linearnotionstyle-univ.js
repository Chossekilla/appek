// =============================================================
// ⌘K COMMAND PALETTE — Linear/Notion-style universal search
// =============================================================
(function() {
  let overlay = null;
  let activeIdx = 0;
  let lastResults = [];

  // Statické navigation položky (vždy v paletě)
  function staticNav() {
    return [
      { kind: 'nav', id: 'dashboard',     icon: '📊', title: 'Dashboard',         sub: 'Dashboard se statistikami', page: 'dashboard' },
      { kind: 'nav', id: 'objednavky',    icon: '📋', title: 'Objednávky',       sub: 'Seznam objednávek', page: 'objednavky' },
      { kind: 'nav', id: 'vyroba',        icon: '🥖', title: 'Výroba',           sub: 'Výrobní list, suroviny, sklad, HACCP', page: 'vyroba' },
      { kind: 'nav', id: 'dodaci_listy',  icon: '📃', title: 'Dodací listy',     sub: '', page: 'dodaci_listy' },
      { kind: 'nav', id: 'faktury',       icon: '💰', title: 'Faktury',          sub: '', page: 'faktury' },
      { kind: 'nav', id: 'vyrobky',       icon: '📦', title: 'Výrobky',          sub: 'Katalog výrobků', page: 'vyrobky' },
      { kind: 'nav', id: 'stitky',        icon: '🏷️', title: 'Štítky a cenovky', sub: '', page: 'stitky' },
      { kind: 'nav', id: 'haccp',         icon: '📋', title: 'HACCP',            sub: 'Bezpečnost potravin', page: 'haccp' },
      { kind: 'nav', id: 'odberatele',    icon: '👥', title: 'Odběratelé',       sub: '', page: 'odberatele' },
      { kind: 'nav', id: 'suroviny',      icon: '🌾', title: 'Suroviny',         sub: '', page: 'suroviny' },
      { kind: 'nav', id: 'sklad',         icon: '📦', title: 'Sklad',            sub: '', page: 'sklad' },
      { kind: 'nav', id: 'nastaveni',     icon: '⚙️', title: 'Nastavení',        sub: 'Firma, balíčky, údržba', page: 'nastaveni' },
    ];
  }

  function quickActions() {
    return [
      { kind: 'action', id: 'new_obj',     icon: '➕', title: 'Nová objednávka',  sub: 'Vytvořit novou objednávku', do: () => { closeCmdk(); navigate('objednavky'); setTimeout(() => window.otevritNovouObjednavku?.(), 200); } },
      { kind: 'action', id: 'pos_register',icon: '🧾', title: 'POS Kasa (nové okno)', sub: 'Standalone touch-grid POS pro restaurace', do: () => { closeCmdk(); window.openPOSWindow?.(); } },
      { kind: 'action', id: 'floorplan',   icon: '🗺️', title: 'Floor Plan Editor (nové okno)', sub: 'Drag&drop mapa stolů s šablonami', do: () => { closeCmdk(); window.open('floorplan.php','appek_fp','width='+screen.availWidth+',height='+screen.availHeight+',toolbar=no,menubar=no'); } },
      { kind: 'action', id: 'new_vyr',     icon: '➕', title: 'Nový výrobek',     sub: '',                          do: () => { closeCmdk(); navigate('vyrobky'); setTimeout(() => window.editVyrobek?.(), 200); } },
      { kind: 'action', id: 'new_odb',     icon: '➕', title: 'Nový odběratel',   sub: '',                          do: () => { closeCmdk(); navigate('odberatele'); setTimeout(() => window.editOdberatel?.(), 200); } },
      { kind: 'action', id: 'imp_cenik',   icon: '📊', title: 'Import ceníku (Excel/CSV)', sub: 'Hromadný import výrobků nebo surovin', do: () => { closeCmdk(); window.openImportCenik?.('vyrobky'); } },
      { kind: 'action', id: 'balicky',     icon: '🎁', title: 'Balíčky funkcí',  sub: 'Cukrárna, Lahůdky, Restaurace…', do: () => { closeCmdk(); navigate('nastaveni'); setTimeout(() => { state._nastaveniTab = 'balicky'; renderNastaveni(); }, 100); } },
      { kind: 'action', id: 'zalohy',      icon: '💾', title: 'Zálohy databáze', sub: '',                          do: () => { closeCmdk(); navigate('nastaveni'); setTimeout(() => { state._nastaveniTab = 'udrzba'; renderNastaveni(); }, 100); } },
    ];
  }

  async function searchDynamic(q) {
    if (!q || q.length < 2) return [];
    // Souběžně zkusíme vyrobky + odberatele (z cache pokud možno)
    const out = [];
    try {
      const v = await api('admin_vyrobky.php');
      (v.vyrobky || []).forEach(p => {
        if ((p.nazev || '').toLowerCase().includes(q) || (p.cislo || '').toLowerCase().includes(q)) {
          out.push({ kind: 'vyrobek', id: 'v' + p.id, icon: '📦', title: p.nazev, sub: `Výrobek · ${p.cislo || ''} · ${fmt(p.cena_bez_dph)}`, do: () => { closeCmdk(); navigate('vyrobky'); setTimeout(() => window.editVyrobek?.(p.id), 200); } });
        }
      });
    } catch (e) { /* ignore */ }
    try {
      const o = await api('admin_odberatele.php');
      (o.odberatele || []).forEach(c => {
        if ((c.nazev || '').toLowerCase().includes(q) || (c.cislo || '').toLowerCase().includes(q) || (c.ico || '').toLowerCase().includes(q)) {
          out.push({ kind: 'odberatel', id: 'o' + c.id, icon: '👥', title: c.nazev, sub: `Odběratel · ${c.cislo || ''} · ${c.ico || ''}`, do: () => { closeCmdk(); navigate('odberatele'); setTimeout(() => window.editOdberatel?.(c.id), 200); } });
        }
      });
    } catch (e) { /* ignore */ }
    return out.slice(0, 10);
  }

  function render(query) {
    if (!overlay) return;
    const q = (query || '').trim().toLowerCase();
    const nav = staticNav().filter(it => !q || it.title.toLowerCase().includes(q) || it.sub.toLowerCase().includes(q));
    const actions = quickActions().filter(it => !q || it.title.toLowerCase().includes(q));

    let html = '';
    if (actions.length) {
      html += `<div class="cmdk-section-title">⚡ Rychlé akce</div>`;
      html += actions.map((it, i) => itemHtml(it, i)).join('');
    }
    if (nav.length) {
      html += `<div class="cmdk-section-title">📁 Stránky</div>`;
      html += nav.map((it, i) => itemHtml(it, actions.length + i)).join('');
    }
    lastResults = [...actions, ...nav];

    const body = overlay.querySelector('.cmdk-results');
    if (lastResults.length === 0) {
      body.innerHTML = `<div class="cmdk-empty">🤷 Žádné výsledky pro „${esc(q)}"</div>`;
      // Zkus dynamický search výrobků/odběratelů
      if (q.length >= 2) {
        body.innerHTML = `<div class="cmdk-empty">⏳ Hledám výrobky a odběratele…</div>`;
        searchDynamic(q).then(dyn => {
          if (q !== (overlay.querySelector('.cmdk-input').value || '').trim().toLowerCase()) return;
          if (dyn.length === 0) {
            body.innerHTML = `<div class="cmdk-empty">🤷 Nic nenalezeno</div>`;
            return;
          }
          lastResults = dyn;
          activeIdx = 0;
          body.innerHTML = `<div class="cmdk-section-title">🔍 Hledání</div>` + dyn.map((it, i) => itemHtml(it, i)).join('');
          wireItems();
        });
      }
    } else {
      body.innerHTML = html;
      // Pokud uživatel hledá, i tak ukaž live výrobky/odběratele
      if (q.length >= 2) {
        searchDynamic(q).then(dyn => {
          if (q !== (overlay.querySelector('.cmdk-input').value || '').trim().toLowerCase()) return;
          if (dyn.length === 0) return;
          const dynHtml = `<div class="cmdk-section-title">🔍 Hledání</div>` + dyn.map((it, i) => itemHtml(it, lastResults.length + i)).join('');
          lastResults = [...lastResults, ...dyn];
          body.insertAdjacentHTML('beforeend', dynHtml);
          wireItems();
        });
      }
    }
    activeIdx = 0;
    wireItems();
  }

  function itemHtml(it, idx) {
    return `
      <div class="cmdk-item" data-idx="${idx}">
        <div class="cmdk-icon">${esc(it.icon || '·')}</div>
        <div class="cmdk-text">
          <div class="cmdk-title">${esc(it.title)}</div>
          ${it.sub ? `<div class="cmdk-sub">${esc(it.sub)}</div>` : ''}
        </div>
      </div>
    `;
  }

  function wireItems() {
    if (!overlay) return;
    overlay.querySelectorAll('.cmdk-item').forEach((el, idx) => {
      el.onclick = () => {
        const it = lastResults[parseInt(el.dataset.idx)];
        if (it) {
          if (it.do) it.do();
          else if (it.page) { closeCmdk(); navigate(it.page); }
        }
      };
      el.onmouseenter = () => setActive(parseInt(el.dataset.idx));
    });
    setActive(0);
  }

  function setActive(i) {
    activeIdx = Math.max(0, Math.min(lastResults.length - 1, i));
    if (!overlay) return;
    overlay.querySelectorAll('.cmdk-item').forEach(el => el.classList.remove('is-active'));
    const el = overlay.querySelector(`.cmdk-item[data-idx="${activeIdx}"]`);
    if (el) {
      el.classList.add('is-active');
      el.scrollIntoView({ block: 'nearest' });
    }
  }

  function openCmdk() {
    if (overlay) return;
    overlay = document.createElement('div');
    overlay.className = 'cmdk-overlay';
    // v2.9.24 — BULLETPROOF inline styly (immune to transform-parent bugy)
    overlay.style.cssText = [
      'position:fixed',
      'top:0','left:0','right:0','bottom:0',
      'width:100vw','height:100vh',
      'background:rgba(20,20,22,0.55)',
      '-webkit-backdrop-filter:blur(8px)',
      'backdrop-filter:blur(8px)',
      'z-index:99999',
      'display:flex',
      'align-items:flex-start',
      'justify-content:center',
      'padding-top:12vh',
      'padding-left:16px',
      'padding-right:16px',
      'opacity:0',
      'pointer-events:none',
      'transition:opacity 0.18s',
      'box-sizing:border-box',
      'margin:0'
    ].join(';');
    overlay.innerHTML = `
      <div class="cmdk" style="position:relative;width:min(640px,calc(100vw - 32px));max-width:100%;max-height:75vh;background:#fff;border-radius:16px;box-shadow:0 30px 80px rgba(0,0,0,0.45),0 4px 20px rgba(0,0,0,0.15);display:flex;flex-direction:column;overflow:hidden;z-index:100000">
        <div class="cmdk-search-row">
          <div class="cmdk-search-icon">🔍</div>
          <input class="cmdk-input" placeholder="Hledej cokoliv — výrobek, odběratele, akci…" autocomplete="off" autocorrect="off">
          <span class="cmdk-hint">ESC</span>
        </div>
        <div class="cmdk-results"></div>
        <div class="cmdk-footer">
          <span><kbd>↑↓</kbd> pohyb</span>
          <span><kbd>⏎</kbd> otevřít</span>
          <span style="flex:1"></span>
          <span><kbd>⌘</kbd> <kbd>K</kbd> přepnout</span>
        </div>
      </div>
    `;
    document.body.appendChild(overlay);
    // Force scroll lock — modal nesmí scrollovat pozadí
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => {
      overlay.classList.add('show');
      overlay.style.opacity = '1';
      overlay.style.pointerEvents = 'auto';
    });

    const input = overlay.querySelector('.cmdk-input');
    input.focus();
    input.oninput = () => render(input.value);
    overlay.onclick = (e) => { if (e.target === overlay) closeCmdk(); };
    document.addEventListener('keydown', onKey);
    render('');
  }

  function closeCmdk() {
    if (!overlay) return;
    overlay.classList.remove('show');
    overlay.style.opacity = '0';
    overlay.style.pointerEvents = 'none';
    // Restore body scroll
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onKey);
    setTimeout(() => { overlay?.remove(); overlay = null; }, 200);
  }

  function onKey(e) {
    if (!overlay) return;
    if (e.key === 'Escape')      { e.preventDefault(); closeCmdk(); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); setActive(activeIdx + 1); }
    else if (e.key === 'ArrowUp')   { e.preventDefault(); setActive(activeIdx - 1); }
    else if (e.key === 'Enter') {
      e.preventDefault();
      const it = lastResults[activeIdx];
      if (it) {
        if (it.do) it.do();
        else if (it.page) { closeCmdk(); navigate(it.page); }
      }
    }
  }

  // Global shortcut ⌘K / Ctrl+K (mimo input/textarea pokud je text)
  document.addEventListener('keydown', (e) => {
    const isCmdK = (e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K');
    if (isCmdK) {
      e.preventDefault();
      if (overlay) closeCmdk(); else openCmdk();
    }
  });

  window.openCmdk = openCmdk;
})();

