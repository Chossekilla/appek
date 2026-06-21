// =============================================================
// VÝROBKY (s podporou nahrávání obrázků)
// =============================================================
// Vrátí HTML badge ikon (DL/FA + jak nedávno použitý) pro řádek/kartu výrobku
function vyrobekDocBadges(v) {
  const dl = (v.pocet_dl || 0) | 0;
  const fa = (v.pocet_fa || 0) | 0;
  const _recept = (v.pocet_surovin_receptu || 0) | 0;
  const _kalk   = (v.pocet_kalkulaci || 0) | 0;
  if (!dl && !fa && !_recept && !_kalk) return '';

  // "fresh" = použito v posledních 30 dnech → zvýrazní se barvou
  const dnyOd = (s) => {
    if (!s) return null;
    const d = new Date(s);
    if (isNaN(d)) return null;
    return Math.floor((Date.now() - d.getTime()) / 86400000);
  };
  const freshDl = (() => { const n = dnyOd(v.posledni_dl); return n !== null && n <= 30; })();
  const freshFa = (() => { const n = dnyOd(v.posledni_fa); return n !== null && n <= 30; })();

  const tip = (kind, pocet, posledni) => {
    if (!pocet) return '';
    const d = posledni ? ` · naposledy ${fmtDate(posledni)}` : '';
    return `${kind}: ${pocet}×${d}`;
  };

  // 🆕 v2.9.271 — recept + kalkulace badges
  const recept = (v.pocet_surovin_receptu || 0) | 0;
  const kalk   = (v.pocet_kalkulaci || 0) | 0;

  let html = '<span class="vyr-badges" style="display:inline-flex;gap:4px;margin-left:8px;vertical-align:middle">';
  if (recept) {
    html += `<span class="vyr-badge" title="Receptura: ${recept} surovin · klikni na výrobek pro detail" style="background:#fef3c7;color:#854F0B;font-size:11px;padding:2px 6px;border-radius:10px;font-weight:600">🧬 ${recept}</span>`;
  }
  if (kalk) {
    html += `<span class="vyr-badge" title="Uložené kalkulace: ${kalk}× · poslední ${v.posledni_kalkulace ? fmtDate(v.posledni_kalkulace) : '—'}" style="background:#ddd6fe;color:#5b21b6;font-size:11px;padding:2px 6px;border-radius:10px;font-weight:600">🧮 ${kalk}</span>`;
  }
  if (dl) {
    const bg = freshDl ? '#dcfce7' : '#f1f5f9';
    const fg = freshDl ? '#15803d' : '#475569';
    html += `<span class="vyr-badge" title="${esc(tip('Dodací listy', dl, v.posledni_dl))}" style="background:${bg};color:${fg};font-size:11px;padding:2px 6px;border-radius:10px;font-weight:600">📃 ${dl}</span>`;
  }
  if (fa) {
    const bg = freshFa ? '#dbeafe' : '#f1f5f9';
    const fg = freshFa ? '#1d4ed8' : '#475569';
    html += `<span class="vyr-badge" title="${esc(tip('Faktury', fa, v.posledni_fa))}" style="background:${bg};color:${fg};font-size:11px;padding:2px 6px;border-radius:10px;font-weight:600">💰 ${fa}</span>`;
  }
  html += '</span>';
  return html;
}

async function renderVyrobky(filters = {}) {
  const c = document.getElementById('content');
  c.innerHTML = `
    <div class="page-head"><div><h1 class="page-title">📦 Výrobky</h1><p class="page-sub">${skeletonLine('120px', '12px')}</p></div></div>
    <div class="card-block">${skeletonTable(8)}</div>
  `;
  const d = await api('admin_vyrobky.php');
  // 🐛 fix v2.9.187 — defenzivní fallback, kdyby endpoint vrátil chybu/prázdnou strukturu
  d.vyrobky = d.vyrobky || [];
  d.kategorie = d.kategorie || [];

  // Spočítej výrobky v každé kategorii (pro badge)
  const pocetPoKat = {};
  d.vyrobky.forEach((v) => {
    const k = v.kategorie_id || 0;
    pocetPoKat[k] = (pocetPoKat[k] || 0) + 1;
  });

  // Aplikuj filtry lokálně
  const aktivniKat = filters.kategorie_id ?? null;
  const q = (filters.q || '').trim().toLowerCase();
  const stav = filters.stav || ''; // '', 'aktivni', 'skryte'
  const reorderMode = !!state._vyrobkyReorder;
  const groupBy = !!state._vyrobky_group; // 🔀 přepínač "Roztřídit" — group by kategorii (jen když aktivniKat = null)

  const filtered = d.vyrobky.filter((v) => {
    if (aktivniKat !== null && (v.kategorie_id || 0) != aktivniKat) return false;
    if (stav === 'aktivni' && !v.aktivni) return false;
    if (stav === 'skryte' && v.aktivni) return false;
    if (q && !((v.nazev || '').toLowerCase().includes(q) || (v.cislo || '').toLowerCase().includes(q))) return false;
    return true;
  });

  // Kategorie list pro filtr
  const kategorie = (d.kategorie || []).slice().sort((a, b) => (a.poradi || 0) - (b.poradi || 0));

  // Když je groupBy aktivní + zobrazujeme všechny — rozsekej filtered do skupin podle kategorie
  // (rozhoduje se v render helperech níže — connectorně, zachová pořadí kategorií)
  const grouping = groupBy && aktivniKat === null && !reorderMode;
  const skupinyVyrobku = {};
  if (grouping) {
    kategorie.forEach(k => skupinyVyrobku[k.id] = []);
    skupinyVyrobku[0] = []; // bez kategorie
    filtered.forEach(v => {
      const kid = v.kategorie_id || 0;
      if (!skupinyVyrobku[kid]) skupinyVyrobku[kid] = [];
      skupinyVyrobku[kid].push(v);
    });
  }
  // 🆕 v3.0.335 — pořadí grupování: hlavní kategorie → její subkategorie (↳), pak osiřelé
  const katHierarchy = (() => {
    const mains = kategorie.filter(k => !k.parent_id);
    const out = [];
    mains.forEach(m => {
      out.push({ k: m, isSub: false });
      kategorie.filter(s => s.parent_id == m.id).forEach(s => out.push({ k: s, isSub: true }));
    });
    kategorie.filter(k => k.parent_id && !mains.find(m => m.id == k.parent_id)).forEach(s => out.push({ k: s, isSub: true }));
    return out;
  })();

  // 🆕 v3.0.267 — stránkování výrobků (dosud jediný hlavní seznam BEZ stránkování — 1400+
  //   řádků v DOM). Klientské nad `filtered` (kategorie badge/řazení/grupování potřebují plný
  //   dataset, server vrací vše). Režim řazení a „Roztřídit" zůstávají bez stránkování.
  const paginated = !grouping && !reorderMode;
  const vp = state._vyrPag || (state._vyrPag = { offset: 0, shown: 0 });
  applyPagLimit(vp);
  const vpSig = JSON.stringify([aktivniKat, q, stav]);
  if (vp.sig !== vpSig) { vp.sig = vpSig; vp.offset = 0; vp.shown = 0; }
  let pageItems = filtered;
  if (paginated) {
    const vLim = vp.limit || 50;
    if ((state._pagStyl || 'load_more') === 'stranky') {
      if (vp.offset >= filtered.length) vp.offset = 0;
      pageItems = filtered.slice(vp.offset, vp.offset + vLim);
    } else {
      vp.offset = 0;
      if (!vp.shown) vp.shown = vLim;
      pageItems = filtered.slice(0, vp.shown);
    }
  }
  vp.items = pageItems; vp.total = filtered.length;

  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">📦 Výrobky</h1>
        <p class="page-sub">${filtered.length} / ${d.vyrobky.length} <span>výrobků</span></p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-secondary" onclick="navigate('kategorie')" title="Spravovat kategorie výrobků">🏷️ Kategorie</button>
        <button class="btn-secondary ${reorderMode ? 'btn-active' : ''}" onclick="toggleVyrobkyReorder()" title="${reorderMode ? 'Ukončit řazení' : 'Přesouvat výrobky drag & drop'}">
          ${reorderMode ? '✓ Hotovo' : '🔀 Řadit'}
        </button>
        ${!reorderMode ? `${adminOnly('<button class="btn-secondary" onclick="otevritPrecislovat()" title="Přečíslovat všechny výrobky od 1">🔢 Přečíslovat</button>')}${adminOnly('<button class="btn-secondary" onclick="openImportCenik(\'vyrobky\')" title="Import ceníku z Excel/CSV s auto-matchingem">📊 Import ceníku</button>')}${adminOnly('<button class="btn-secondary" onclick="otevritImportVyrobku()" title="Hromadný import výrobků z JSON souboru">📥 JSON</button>')}<button class="btn-primary btn-green btn-big-action" onclick="editVyrobek()" style="font-size:18px !important;font-weight:800 !important;padding:18px 32px !important;min-height:64px !important;border-radius:12px !important;letter-spacing:0.3px !important">+ Nový výrobek</button>` : ''}
      </div>
    </div>

    ${reorderMode ? `
      <div class="reorder-banner">
        <span>🔀 <strong>Režim řazení:</strong> přetáhněte výrobky myší na nové pořadí. Filtruje vás aktuální kategorie.</span>
      </div>
    ` : `
      <div class="filters">
        <input class="filter-input" type="search" id="vf-q" placeholder="Hledat (název, číslo)..." value="${esc(filters.q || '')}" oninput="debounce('vf-q', applyVyrobkyFilters, 220)">
        <select class="filter-select" id="vf-stav" onchange="applyVyrobkyFilters()">
          <option value="">Všechny</option>
          <option value="aktivni" ${stav === 'aktivni' ? 'selected' : ''}>Jen aktivní</option>
          <option value="skryte" ${stav === 'skryte' ? 'selected' : ''}>Jen skryté</option>
        </select>
        <label style="display:flex;align-items:center;gap:10px;font-size:15px;font-weight:600;white-space:nowrap;padding:0 10px;cursor:pointer" title="Při zapnutí se výrobky v zobrazení 'Vše' rozsekají do sekcí podle kategorie">
          <input type="checkbox" ${groupBy ? 'checked' : ''} onchange="state._vyrobky_group=this.checked;renderVyrobky(state._vyrobkyFilters || {})" style="width:22px;height:22px;cursor:pointer;accent-color:var(--primary)">
          Roztřídit
        </label>
      </div>
    `}

    <!-- Filtr kategorií — horizontální scroll lišta -->
    <div class="kat-filter-wrap">
      <div class="kat-filter">
        <button class="kat-btn ${aktivniKat === null ? 'active' : ''}" onclick="filterVyrobkyKat(null)">
          <span class="kat-btn-emoji">📦</span>
          <span class="kat-btn-name">Vše</span>
          <span class="kat-btn-count">${d.vyrobky.length}</span>
        </button>
        ${kategorie.map((k) => `
          <button class="kat-btn ${aktivniKat == k.id ? 'active' : ''}" onclick="filterVyrobkyKat(${k.id})">
            <span class="kat-btn-emoji">${esc(k.ikona || '🥖')}</span>
            <span class="kat-btn-name">${esc(k.nazev)}</span>
            <span class="kat-btn-count">${pocetPoKat[k.id] || 0}</span>
          </button>
        `).join('')}
        ${pocetPoKat[0] ? `
          <button class="kat-btn ${aktivniKat == 0 ? 'active' : ''}" onclick="filterVyrobkyKat(0)">
            <span class="kat-btn-emoji">❓</span>
            <span class="kat-btn-name">Bez kategorie</span>
            <span class="kat-btn-count">${pocetPoKat[0]}</span>
          </button>
        ` : ''}
      </div>
    </div>

    <!-- Desktop: tabulka -->
    <div class="card-block desktop-only-block">
      ${(() => {
        if (filtered.length === 0) {
          const hasAnyRecord = (d.vyrobky || []).length > 0;
          if (!hasAnyRecord) {
            return emptyState({
              icon: '📦',
              title: 'Zatím žádné výrobky',
              msg: 'Začni přidáním prvního výrobku, importem z Excelu/CSV, nebo si nech aplikaci naplnit ukázkovými daty (10 výrobků + 5 odběratelů + suroviny).',
              actions: `
                <button class="btn-primary btn-green" onclick="editVyrobek()" style="font-size:15px;padding:11px 22px">+ Přidat první výrobek</button>
                ${adminOnly('<button class="btn-secondary" onclick="openImportCenik(\'vyrobky\')">📊 Import ceníku</button>')}
                <!-- 🆕 v3.0.177 — „Naplnit demo daty" sjednoceno do Nastavení → Údržba -->
              `,
            });
          }
          return '<div class="empty-state">Žádný výrobek neodpovídá filtru</div>';
        }
        const head = `
          <thead>
            <tr>
              ${reorderMode ? '<th style="width:30px"></th>' : ''}
              <th></th><th>Kód</th><th>Název</th><th>Kategorie</th><th class="num">Cena</th><th>DPH</th><th>Stav</th><th></th>
            </tr>
          </thead>
        `;
        const radek = (v) => `
          <tr ${reorderMode ? `draggable="true" data-id="${v.id}" class="reorder-row"` : `class="row-clickable" onclick="editVyrobek(${v.id})"`} ${!v.aktivni ? 'style="opacity:0.5;"' : ''}>
            ${reorderMode ? '<td class="drag-handle">⋮⋮</td>' : ''}
            <td>${v.obrazek_url ? `<img src="${esc(v.obrazek_url)}" style="width:64px;height:64px;border-radius:8px;object-fit:cover;display:block;">` : `<div style="width:64px;height:64px;border-radius:8px;background:var(--surface-2);display:flex;align-items:center;justify-content:center;font-size:32px;">${esc(v.kategorie_ikona || '🥖')}</div>`}</td>
            <td>${esc(v.cislo || '')}</td>
            <td class="vyrobek-row-name"><strong>${esc(v.nazev)}</strong>${vyrobekDocBadges(v)}${v.hmotnost_g ? `<div style="color:var(--text-3);font-size:12px;">${v.hmotnost_g} g</div>` : ''}</td>
            <td>${esc(v.kategorie_nazev || '')}</td>
            <td class="num" onclick="event.stopPropagation();">
              ${reorderMode ? fmt(v.cena_bez_dph) : `<span class="inline-edit" data-inline-table="vyrobky" data-inline-id="${v.id}" data-inline-field="cena_bez_dph" data-inline-type="number" data-inline-value="${v.cena_bez_dph}" title="Klik = upravit cenu inline">${fmt(v.cena_bez_dph)}</span>`}
            </td>
            <td>${v.dph}%</td>
            <td>${v.aktivni ? '<span class="status dorucena">Aktivní</span>' : '<span class="status zrusena">Skrytý</span>'}</td>
            <td onclick="event.stopPropagation();">
              ${reorderMode ? '' : `<button class="btn-secondary" style="font-size:12px;padding:6px 10px;" onclick="editVyrobek(${v.id})">Upravit</button>`}
            </td>
          </tr>
        `;
        if (!grouping) {
          return `<table class="table ${reorderMode ? 'reorder-table' : ''}">${head}<tbody id="vyrobky-tbody">${pageItems.map(radek).join('')}</tbody></table>`;
        }
        // 🔀 Group view — sekce podle kategorie
        const rendered = [];
        katHierarchy.forEach(({ k, isSub }) => {
          const items = skupinyVyrobku[k.id] || [];
          const subHasItems = !isSub && kategorie.some(s => s.parent_id == k.id && (skupinyVyrobku[s.id] || []).length);
          if (items.length === 0 && !subHasItems) return; // hlavní se subkat. produkty se ukáže i bez přímých
          rendered.push(`
            <h3 style="margin:${isSub ? '12px 0 8px 28px' : '24px 0 10px'};font-size:${isSub ? '15px' : '18px'};font-weight:700;color:var(--text);display:flex;align-items:center;gap:${isSub ? '8px' : '12px'};border-bottom:${isSub ? '1px' : '2px'} solid var(--border-2);padding-bottom:${isSub ? '5px' : '8px'}">
              <span style="font-size:${isSub ? '20px' : '32px'};line-height:1">${isSub ? '↳ ' : ''}${esc(k.ikona || '🥖')}</span>
              <span>${esc(k.nazev)}</span>
              <span style="font-size:13px;font-weight:600;color:var(--text-3);background:var(--surface-2);padding:4px 12px;border-radius:12px">${items.length}</span>
            </h3>
            ${items.length ? `<table class="table" style="margin-bottom:12px${isSub ? ';margin-left:28px' : ''}">${head}<tbody>${items.map(radek).join('')}</tbody></table>` : ''}
          `);
        });
        if (skupinyVyrobku[0] && skupinyVyrobku[0].length > 0) {
          rendered.push(`
            <h3 style="margin:24px 0 10px;font-size:18px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:12px;border-bottom:2px solid var(--border-2);padding-bottom:8px">
              <span style="font-size:32px;line-height:1">❓</span>
              <span>Bez kategorie</span>
              <span style="font-size:13px;font-weight:600;color:var(--text-3);background:var(--surface-2);padding:4px 12px;border-radius:12px">${skupinyVyrobku[0].length}</span>
            </h3>
            <table class="table" style="margin-bottom:12px">${head}<tbody>${skupinyVyrobku[0].map(radek).join('')}</tbody></table>
          `);
        }
        return rendered.join('');
      })()}
    </div>

    <!-- Mobile: kompaktní karty -->
    ${(() => {
      const karta = (v) => `
        <div class="vyrobek-card ${!v.aktivni ? 'inactive' : ''} ${reorderMode ? 'reorder-card' : ''}"
             ${reorderMode ? `draggable="true" data-id="${v.id}"` : `onclick="editVyrobek(${v.id})"`}>
          ${reorderMode ? '<div class="vyrobek-card-handle">⋮⋮</div>' : ''}
          <div class="vyrobek-card-img">
            ${v.obrazek_url
              ? `<img src="${esc(v.obrazek_url)}" alt="${esc(v.nazev)}">`
              : `<div class="vyrobek-card-emoji">${esc(v.kategorie_ikona || '🥖')}</div>`}
            ${!v.aktivni ? '<span class="vyrobek-card-skryt">Skrytý</span>' : ''}
          </div>
          <div class="vyrobek-card-body">
            <div class="vyrobek-card-cislo">${esc(v.cislo || '')}</div>
            <div class="vyrobek-card-nazev">${esc(v.nazev)}${vyrobekDocBadges(v)}</div>
            <div class="vyrobek-card-meta">
              ${v.hmotnost_g ? `${v.hmotnost_g} g · ` : ''}${esc(v.kategorie_nazev || '')}
            </div>
            <div class="vyrobek-card-price">${fmt(v.cena_bez_dph)} <span class="dph">+${v.dph}%</span></div>
          </div>
        </div>
      `;
      if (filtered.length === 0) {
        return `<div class="vyrobky-grid mobile-only-block" id="vyrobky-grid-mobile"><div class="card-block" style="grid-column:1/-1"><div class="empty-state">Žádné výrobky</div></div></div>`;
      }
      if (!grouping) {
        return `<div class="vyrobky-grid mobile-only-block" id="vyrobky-grid-mobile">${pageItems.map(karta).join('')}</div>`;
      }
      // 🔀 Group view (mobile) — nadpisy + grid pro každou kategorii
      const out = ['<div class="mobile-only-block">'];
      katHierarchy.forEach(({ k, isSub }) => {
        const items = skupinyVyrobku[k.id] || [];
        const subHasItems = !isSub && kategorie.some(s => s.parent_id == k.id && (skupinyVyrobku[s.id] || []).length);
        if (items.length === 0 && !subHasItems) return;
        out.push(`
          <h3 style="margin:${isSub ? '10px 0 6px 18px' : '18px 0 8px'};font-size:${isSub ? '14px' : '16px'};font-weight:700;color:var(--text);display:flex;align-items:center;gap:${isSub ? '8px' : '10px'};padding-bottom:6px;border-bottom:${isSub ? '1px' : '2px'} solid var(--border-2)">
            <span style="font-size:${isSub ? '20px' : '28px'};line-height:1">${isSub ? '↳ ' : ''}${esc(k.ikona || '🥖')}</span>
            <span>${esc(k.nazev)}</span>
            <span style="font-size:12px;font-weight:600;color:var(--text-3);background:var(--surface-2);padding:2px 10px;border-radius:10px">${items.length}</span>
          </h3>
          ${items.length ? `<div class="vyrobky-grid"${isSub ? ' style="margin-left:18px"' : ''}>${items.map(karta).join('')}</div>` : ''}
        `);
      });
      if (skupinyVyrobku[0] && skupinyVyrobku[0].length > 0) {
        out.push(`
          <h3 style="margin:18px 0 8px;font-size:16px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:10px;padding-bottom:6px;border-bottom:2px solid var(--border-2)">
            <span style="font-size:28px;line-height:1">❓</span>
            <span>Bez kategorie</span>
            <span style="font-size:12px;font-weight:600;color:var(--text-3);background:var(--surface-2);padding:2px 10px;border-radius:10px">${skupinyVyrobku[0].length}</span>
          </h3>
          <div class="vyrobky-grid">${skupinyVyrobku[0].map(karta).join('')}</div>
        `);
      }
      out.push('</div>');
      return out.join('');
    })()}
    ${paginated ? pagControlHtml('vyr', vp, 'vyrGoToPage', 'vyrLoadMore') : ''}
  `;

  // Ulož aktuální filtry pro re-render
  state._vyrobkyFilters = { kategorie_id: aktivniKat, q, stav };

  // 🆕 v3.0.267 — nekonečné scrollování výrobků (jen styl=infinite)
  if (paginated) pagSetupInfinite('vyr', vp, 'vyrLoadMore');

  // Pokud je reorder mode, attachni drag listenery
  if (reorderMode && filtered.length > 1) {
    attachReorderListeners('vyrobky-tbody', 'tr');
    attachReorderListeners('vyrobky-grid-mobile', '.vyrobek-card');
  }
}

// 🆕 v3.0.267 — stránkování výrobků (klientské; viz renderVyrobky)
window.vyrLoadMore = function() {
  const vp = state._vyrPag; if (vp) vp.shown = (vp.shown || vp.limit || 50) + (vp.limit || 50);
  renderVyrobky(state._vyrobkyFilters || {});
};
window.vyrGoToPage = function(p) {
  const vp = state._vyrPag; if (vp) { vp.offset = p * (vp.limit || 50); vp.shown = 0; }
  renderVyrobky(state._vyrobkyFilters || {});
};

window.toggleVyrobkyReorder = function() {
  state._vyrobkyReorder = !state._vyrobkyReorder;
  renderVyrobky(state._vyrobkyFilters || {});
};

