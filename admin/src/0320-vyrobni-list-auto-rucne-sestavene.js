// =============================================================
// VÝROBNÍ LIST (auto + ručně sestavené)
// =============================================================
// 🆕 v2.9.188/189 — Výroba má vodorovné sub-taby. Default = Výrobní list.
// Od výroby vše začíná — Suroviny, Sklad, HACCP, Kalkulace, Přehled, Spárování.
// 🆕 v2.9.228 — emoji rozlišení (🏭 Sklady vs 🧮 Kalkulace), kratší labely pro mobile
// 🆕 v2.9.256 — 'Výrobní list' 📋 → 📝 (memo = denní úkoly co péct);
//              📋 byla moc podobná 📃 Dodací list. 📝 odlišný + intuitivní 'todo'.
// 🆕 v2.9.270 — 'Stav skladu' přesunut DO 'Sklady' (combined view s surovin stock + sklady)
const VYROBA_SUBTABS = [
  { key: 'list',       label: '📝 Výrobní list',  render: () => renderVyrobniListInline() },
  { key: 'suroviny',   label: '🌾 Suroviny',       nav: 'suroviny' },
  { key: 'sklady',     label: '🏭 Sklady',         render: () => renderSkladyInline() },  // 🆕 v2.9.215, v2.9.270 obsahuje i Stav skladu
  { key: 'haccp',      label: '🛡️ HACCP',          nav: 'haccp' },        // 🆕 emoji = bezpečnost potravin
  { key: 'kalkulace',  label: '🧮 Kalkulace',      nav: 'vyrobni_kalkulace' }, // 🆕 emoji = kalkulace
  { key: 'prehled',    label: '📈 Vyrobeno',       nav: 'export_vyroby' },     // 🆕 v2.9.258 — 'Přehled' → 'Vyrobeno' (krátký, jasný; odlišit od Dashboard Přehled)
];

async function renderVyrobaHub() {
  const c = document.getElementById('content');
  // 🆕 v2.9.222 — fix: zajisti že _vyrobaSubTab je VŽDY 'render' typ (ne 'nav').
  // Předtím když user navigoval ze sub-stránky (např. Kalkulace) zpět na Výrobu,
  // _vyrobaSubTab === 'kalkulace' (sub.nav: 'vyrobni_kalkulace') → infinite navigate loop.
  let aktSubTab = state._vyrobaSubTab || 'list';
  const checkSub = VYROBA_SUBTABS.find(t => t.key === aktSubTab);
  if (!checkSub || !checkSub.render) {
    aktSubTab = 'list';
    state._vyrobaSubTab = 'list';
  }

  // 🆕 v2.9.227 — sub-taby jako stylované segmented control (icon nahoře, label dole)
  // v2.9.229 — refactor na .seg-tabs (univerzální styling, sdílený s Nastavením)
  const splitLabel = (lbl) => {
    // Předpokládám formát "🧪 HACCP" — rozdělím na emoji + zbytek
    const m = lbl.match(/^(\p{Emoji}+|\p{Extended_Pictographic}+|[^\s]+)\s+(.+)$/u);
    if (m) return { icon: m[1], text: m[2] };
    return { icon: '', text: lbl };
  };

  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">🥖 Výroba</h1>
        <p class="page-sub">Výrobní list, suroviny, sklad, HACCP, kalkulace a sumáře vyrobeno</p>
      </div>
    </div>

    <!-- 🗂️ SUB-TABY — segmented control s icon + label -->
    <div class="seg-tabs" role="tablist">
      ${VYROBA_SUBTABS.map(t => {
        const { icon, text } = splitLabel(t.label);
        return `
        <button type="button" role="tab" class="seg-tab ${aktSubTab === t.key ? 'active' : ''}"
                onclick="vyrobaSetSubTab('${t.key}')" aria-selected="${aktSubTab === t.key}">
          <span class="seg-tab-icon">${icon}</span>
          <span class="seg-tab-text">${text}</span>
        </button>
      `;
      }).join('')}
    </div>

    <div class="nastaveni-page nastaveni-tab-content">
      <div id="vyroba-subtab-content"></div>
    </div>
  `;

  // Render aktivního sub-tabu (vždy render type — nav type je filtrován výš)
  const sub = VYROBA_SUBTABS.find(t => t.key === aktSubTab) || VYROBA_SUBTABS[0];
  if (sub.render) await sub.render();
}

window.vyrobaSetSubTab = function(tabKey) {
  const sub = VYROBA_SUBTABS.find(t => t.key === tabKey);
  // 🆕 v2.9.222 — 'nav' sub-taby (kalkulace, haccp, sklad, prehled, suroviny) jen
  // přesměrují pryč — NESMÍ se uložit do _vyrobaSubTab, jinak vznikne nav loop
  // při návratu na Výrobu (renderVyrobaHub by viděl _vyrobaSubTab='kalkulace' a
  // znovu zavolal navigate). Ukládáme jen 'render' sub-taby (list, sklady).
  if (sub?.nav) {
    navigate(sub.nav);
  } else if (sub?.render) {
    state._vyrobaSubTab = tabKey;
    renderVyrobaHub();
  }
};

// Inline render Výrobního listu — žije v #vyroba-subtab-content (ne v #content!)
async function renderVyrobniListInline() {
  const c = document.getElementById('vyroba-subtab-content');
  if (!c) return;
  c.innerHTML = `
    <div class="vyroba-mode-tabs">
      <button class="vyroba-mode-tab ${state.vyrobaMode === 'auto' ? 'active' : ''}" onclick="setVyrobaMode('auto')">
        🤖 Z objednávek (automaticky)
      </button>
      <button class="vyroba-mode-tab ${state.vyrobaMode === 'manual' ? 'active' : ''}" onclick="setVyrobaMode('manual')">
        ✋ Ručně sestavené listy
      </button>
    </div>
    <div id="vyroba-content"></div>
  `;
  if (state.vyrobaMode === 'auto') await renderVyrobaAuto();
  else await renderVyrobaManual();
}

// Backwards-compat: stará navigate('vyrobni_list') route → otevři Výrobu se sub-tabem 'list'
async function renderVyrobniList() {
  state._vyrobaSubTab = 'list';
  await renderVyrobaHub();
}

// 🆕 v2.9.215 — Sklady management (multi-warehouse). Inline render uvnitř Výroba hubu.
// 🆕 v2.9.270 — propojení se Stavem skladu (surovin stock summary)
async function renderSkladyInline() {
  const c = document.getElementById('vyroba-subtab-content');
  if (!c) return;
  c.innerHTML = '⏳ Načítám sklady…';
  try {
    // Paralelně načti sklady + suroviny stock summary
    const [r, suroviny] = await Promise.all([
      api('admin_sklady.php'),
      // Cachuj sdíleně s renderSuroviny / renderSklad
      state._suroviny_full_cache
        ? Promise.resolve(state._suroviny_full_cache)
        : api('admin_suroviny.php').then(d => { state._suroviny_full_cache = d; return d; }),
    ]);
    const sklady = r.sklady || [];
    const typIcon = { suchy: '📦', lednice: '❄️', mrazak: '🧊', jiny: '🏭' };
    const typLabel = { suchy: 'Suchý sklad', lednice: 'Lednice', mrazak: 'Mrazák', jiny: 'Jiný' };

    // 🆕 Stav skladu metrics
    const aktivniSur = (suroviny || []).filter(s => parseInt(s.aktivni) !== 0);
    const podMinimem = aktivniSur.filter(s => {
      const min = parseFloat(s.stock_minimalni);
      const akt = parseFloat(s.stock_aktualni) || 0;
      return !isNaN(min) && akt <= min;
    });
    const beStockuVubec = aktivniSur.filter(s => (parseFloat(s.stock_aktualni) || 0) === 0).length;
    const hodnotaSkladu = aktivniSur.reduce((sum, s) => {
      const cb = parseFloat(s.cena_baleni) || 0;
      const ob = parseFloat(s.obsah_baleni) || 0;
      const akt = parseFloat(s.stock_aktualni) || 0;
      if (cb > 0 && ob > 0 && akt > 0) return sum + (cb / ob) * akt;
      return sum;
    }, 0);

    c.innerHTML = `
      <!-- 🆕 v2.9.270 — Stav skladu summary (propojení s Sklady) -->
      <div class="card-block" style="padding:16px 18px;margin-bottom:14px;background:linear-gradient(135deg,#FFFBEB,#FFF8F0);border:1px solid #F0D9B8">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:12px">
          <div style="display:flex;align-items:center;gap:10px">
            <span style="font-size:28px">📦</span>
            <div>
              <h3 style="margin:0;font-size:16px;font-weight:700;color:#854F0B">Stav skladu — souhrn</h3>
              <p style="margin:2px 0 0;font-size:12px;color:#854F0B;opacity:0.8">${aktivniSur.length} aktivních surovin · ${podMinimem.length > 0 ? `<strong style="color:#991B1B">⚠ ${podMinimem.length} pod minimem</strong>` : '<span style="color:#166534">✓ vše OK</span>'}</p>
            </div>
          </div>
          <button class="btn-primary btn-green" onclick="navigate('sklad')" style="padding:10px 16px;font-size:13px;font-weight:700">
            📋 Otevřít plný přehled stavu skladu
          </button>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">
          <div class="stat-card" style="padding:12px 14px;background:#fff;border:1px solid #F0D9B8;border-radius:10px;cursor:pointer" onclick="navigate('sklad')">
            <div class="stat-label">Položek v evidenci</div>
            <div class="stat-value" style="font-size:22px">${aktivniSur.length}</div>
            <div class="stat-sub">${beStockuVubec > 0 ? `🛒 ${beStockuVubec} bez zásob` : '✓ vše naskladněno'}</div>
          </div>
          <div class="stat-card" style="padding:12px 14px;background:#fff;border:1px solid ${podMinimem.length > 0 ? '#FECACA' : '#F0D9B8'};border-radius:10px;cursor:pointer" onclick="state._suroviny_pod_minimem=true;navigate('suroviny')">
            <div class="stat-label" style="color:${podMinimem.length > 0 ? '#991B1B' : 'var(--text-3)'}">${podMinimem.length > 0 ? '⚠ Pod minimem' : '✓ Nad minimem'}</div>
            <div class="stat-value" style="color:${podMinimem.length > 0 ? '#991B1B' : 'var(--text-1)'};font-size:22px">${podMinimem.length}</div>
            <div class="stat-sub">${podMinimem.length > 0 ? '→ doobjednat' : 'vše OK'}</div>
          </div>
          <div class="stat-card" style="padding:12px 14px;background:#fff;border:1px solid #F0D9B8;border-radius:10px">
            <div class="stat-label">Hodnota skladu (orient.)</div>
            <div class="stat-value" style="font-size:22px">${hodnotaSkladu > 0 ? Math.round(hodnotaSkladu).toLocaleString('cs-CZ') + ' Kč' : '—'}</div>
            <div class="stat-sub">na základě cen balení</div>
          </div>
        </div>

        ${podMinimem.length > 0 ? `
          <details style="margin-top:12px">
            <summary style="cursor:pointer;font-size:13px;font-weight:600;color:#991B1B;padding:6px 0">
              ⚠ ${podMinimem.length} ${podMinimem.length === 1 ? 'položka' : (podMinimem.length < 5 ? 'položky' : 'položek')} k doobjednání
            </summary>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">
              ${podMinimem.slice(0, 12).map(s => `
                <span class="status zrusena" style="cursor:pointer;font-size:11px"
                      onclick="editSurovina(${s.id})"
                      title="Aktuálně: ${parseFloat(s.stock_aktualni).toFixed(2)} ${esc(s.jednotka || 'g')} · min: ${parseFloat(s.stock_minimalni).toFixed(2)} ${esc(s.jednotka || 'g')}">
                  ${esc(s.nazev)}
                </span>
              `).join('')}
              ${podMinimem.length > 12 ? `<button class="btn-secondary" style="font-size:11px;padding:4px 10px" onclick="state._suroviny_pod_minimem=true;navigate('suroviny')">+${podMinimem.length - 12} dalších</button>` : ''}
            </div>
            <!-- 🆕 v3.0.204 — 1-klik naskladnění všech pod minimem na cílovou zásobu -->
            <div style="margin-top:10px">
              <button class="btn-primary btn-green" style="font-size:12px;padding:7px 14px;font-weight:700"
                      onclick='doplnitVse(${JSON.stringify(podMinimem.map(s => s.id))})'
                      title="Naskladní všechny suroviny pod minimem na jejich cílovou zásobu (1 klik)">
                🛒 Doplnit vše na cíl (${podMinimem.length})
              </button>
            </div>
          </details>
        ` : ''}
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
        <div>
          <h3 style="margin:0;font-size:15px;font-weight:700">🏭 Sklady (multi-warehouse)</h3>
          <p style="font-size:13px;color:var(--text-3);margin:2px 0 0">${sklady.length} ${sklady.length === 1 ? 'sklad' : (sklady.length >= 2 && sklady.length <= 4 ? 'sklady' : 'skladů')} · ${sklady.filter(s => s.aktivni).length} aktivních</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <!-- 🆕 v2.9.272 — Quick přesun mezi sklady -->
          ${sklady.filter(s => s.aktivni).length >= 2 ? `<button class="btn-secondary" onclick="otevritPresunPolozky()" style="padding:10px 16px;font-size:14px" title="Přesunout položku mezi sklady (atomická transakce)">🔄 Přesun</button>` : ''}
          <!-- 🆕 v2.9.239 — Správa exportů & inventur (panel s 2 taby per-sklad) -->
          <button class="btn-secondary" onclick="otevritSpravuExportu();setTimeout(()=>{state._spravaTab='porovnani';spravaRender()},100)" style="padding:10px 16px;font-size:14px" title="Pivot tabulka stavů napříč všemi sklady">📊 Porovnání</button>
          <button class="btn-secondary" onclick="otevritSpravuExportu()" style="padding:10px 16px;font-size:14px" title="Hromadné exporty + provedení inventury pro všechny sklady">📤 Export & inventura</button>
          <button class="btn-primary btn-green" onclick="editSklad()" style="padding:10px 18px;font-size:14px;font-weight:700">+ Nový sklad</button>
        </div>
      </div>
      ${sklady.length === 0 ? `
        <div class="card-block" style="text-align:center;padding:40px 20px;color:var(--text-3)">
          <div style="font-size:48px;margin-bottom:10px">🏭</div>
          <h3 style="margin:0 0 6px">Zatím žádné sklady</h3>
          <p style="margin:0 0 16px;font-size:13px">Vytvoř svůj první sklad — např. suchý sklad, lednice, mrazák.</p>
          <button class="btn-primary btn-green" onclick="editSklad()">+ Vytvořit sklad</button>
        </div>
      ` : `
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px">
          ${sklady.map(s => `
            <div class="card-block sklad-card ${s.aktivni ? '' : 'is-inactive'}"
                 onclick="otevritSklad(${s.id}, '${esc(s.nazev)}', '${esc(s.kod)}')"
                 role="button" tabindex="0"
                 onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();otevritSklad(${s.id}, '${esc(s.nazev)}', '${esc(s.kod)}')}"
                 title="Klikni pro detail skladu — položky, příjem, výdej, inventura"
                 style="${s.aktivni ? '' : 'opacity:0.55;border-style:dashed'};padding:16px;display:flex;flex-direction:column;gap:8px;cursor:pointer">
              <div style="display:flex;align-items:center;gap:10px">
                <span style="font-size:30px;line-height:1">${typIcon[s.typ] || '🏭'}</span>
                <div style="flex:1;min-width:0">
                  <div style="font-weight:700;font-size:15px;color:var(--text-1)">${esc(s.nazev)}</div>
                  <div style="font-size:11px;color:var(--text-3);font-family:monospace">${esc(s.kod)} · ${esc(typLabel[s.typ] || s.typ)}</div>
                </div>
                <span class="sklad-card-arrow" style="font-size:18px;color:var(--text-3);transition:transform 0.15s ease, color 0.15s ease">→</span>
              </div>
              ${(s.teplota_min !== null || s.teplota_max !== null) ? `
                <div style="font-size:12px;color:var(--text-2)">🌡️ ${s.teplota_min !== null ? s.teplota_min : '?'}°C — ${s.teplota_max !== null ? s.teplota_max : '?'}°C</div>
              ` : ''}
              ${s.adresa ? `<div style="font-size:12px;color:var(--text-2)">📍 ${esc(s.adresa)}</div>` : ''}
              ${s.poznamka ? `<div style="font-size:11.5px;color:var(--text-3);font-style:italic">${esc(s.poznamka)}</div>` : ''}
              <!-- 🆕 v2.9.273 — action toolbar (event.stopPropagation = nezavírá hlavní onclick) -->
              <div onclick="event.stopPropagation()" style="display:flex;gap:6px;margin-top:auto;padding-top:8px;flex-wrap:wrap;border-top:1px solid var(--border-2);padding-top:10px">
                <button class="btn-secondary" onclick="editSklad(${s.id})" style="font-size:12px;padding:6px 12px" title="Upravit metadata skladu">✏️ Upravit</button>
                <button class="btn-secondary" onclick="exportSklad(${s.id}, 'pdf')" style="font-size:12px;padding:6px 10px" title="Rychlý export — HTML print-ready">📄</button>
                <button class="btn-secondary" onclick="exportSklad(${s.id}, 'csv')" style="font-size:12px;padding:6px 10px" title="Rychlý export — CSV (Excel / účetní)">📊</button>
                <span style="flex:1"></span>
                <button class="btn-secondary" onclick="smazatSklad(${s.id}, '${esc(s.nazev)}')" style="font-size:12px;padding:6px 10px;background:#fde7e9;color:#a8232f;border-color:#fde7e9" title="Smazat / deaktivovat sklad">🗑️</button>
              </div>
            </div>
          `).join('')}
        </div>
      `}
      <p style="font-size:11px;color:var(--text-3);margin-top:14px">
        ℹ️ Klikni na kartu skladu pro detail — položky, příjem, výdej, inventura, korekce. Akce v patičce (✏️ 📄 📊 🗑️) se otevírají samostatně.
      </p>
    `;
  } catch (e) {
    c.innerHTML = `<div style="background:#fde7e9;color:#a8232f;padding:14px;border-radius:8px">❌ Chyba: ${esc(e.message)}</div>`;
  }
}

window.editSklad = async function(id) {
  let sklad = { id: 0, kod: '', nazev: '', typ: 'jiny', teplota_min: null, teplota_max: null, adresa: '', poznamka: '', aktivni: 1 };
  if (id) {
    try {
      sklad = await api('admin_sklady.php?id=' + id);
    } catch (e) { alert('Chyba: ' + e.message); return; }
  }
  const isNew = !id;
  openModal(isNew ? '🏭 Nový sklad' : `✏️ Upravit sklad ${sklad.kod}`, `
    <form id="sklad-form" onsubmit="event.preventDefault(); ulozitSklad(${id || 0})" style="display:flex;flex-direction:column;gap:12px">
      ${!isNew ? `
        <div>
          <label class="form-label">Kód</label>
          <input class="form-input" id="sk-kod" value="${esc(sklad.kod)}" pattern="[A-Z0-9_-]{2,20}" required style="font-family:monospace;text-transform:uppercase">
        </div>
      ` : `
        <div style="background:rgba(186,117,23,0.08);padding:8px 12px;border-radius:6px;font-size:12px;color:var(--text-2)">
          ℹ️ Kód bude auto-generován (SK01, SK02, …) — můžeš ho přejmenovat po vytvoření
        </div>
      `}
      <div>
        <label class="form-label">Název *</label>
        <input class="form-input" id="sk-nazev" value="${esc(sklad.nazev || '')}" required placeholder="např. Hlavní suchý sklad">
      </div>
      <div>
        <label class="form-label">Typ</label>
        <select class="form-select" id="sk-typ">
          <option value="suchy" ${sklad.typ === 'suchy' ? 'selected' : ''}>📦 Suchý sklad</option>
          <option value="lednice" ${sklad.typ === 'lednice' ? 'selected' : ''}>❄️ Lednice</option>
          <option value="mrazak" ${sklad.typ === 'mrazak' ? 'selected' : ''}>🧊 Mrazák</option>
          <option value="jiny" ${sklad.typ === 'jiny' ? 'selected' : ''}>🏭 Jiný</option>
        </select>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <div>
          <label class="form-label">Teplota min (°C)</label>
          <input class="form-input" id="sk-tmin" type="number" step="0.1" value="${sklad.teplota_min ?? ''}" placeholder="např. 0">
        </div>
        <div>
          <label class="form-label">Teplota max (°C)</label>
          <input class="form-input" id="sk-tmax" type="number" step="0.1" value="${sklad.teplota_max ?? ''}" placeholder="např. 5">
        </div>
      </div>
      <div>
        <label class="form-label">Adresa / lokace</label>
        <input class="form-input" id="sk-adresa" value="${esc(sklad.adresa || '')}" placeholder="např. Hlavní budova, místnost 12">
      </div>
      <div>
        <label class="form-label">Poznámka</label>
        <textarea class="form-input" id="sk-pozn" rows="2" placeholder="(volitelné)">${esc(sklad.poznamka || '')}</textarea>
      </div>
      ${!isNew ? `
        <label style="display:flex;align-items:center;gap:8px;font-size:13px">
          <input type="checkbox" id="sk-aktivni" ${sklad.aktivni ? 'checked' : ''}>
          <span>Aktivní</span>
        </label>
      ` : ''}
      <div style="display:flex;gap:8px;justify-content:flex-end;border-top:1px solid var(--border);padding-top:12px;margin-top:6px">
        <button type="button" class="btn-secondary" onclick="closeModal()">Zrušit</button>
        <button type="submit" class="btn-primary btn-green" style="font-weight:700">💾 ${isNew ? 'Vytvořit' : 'Uložit'}</button>
      </div>
    </form>
  `);
};

window.ulozitSklad = async function(id) {
  const data = {
    nazev: document.getElementById('sk-nazev').value.trim(),
    typ: document.getElementById('sk-typ').value,
    teplota_min: document.getElementById('sk-tmin').value || null,
    teplota_max: document.getElementById('sk-tmax').value || null,
    adresa: document.getElementById('sk-adresa').value.trim(),
    poznamka: document.getElementById('sk-pozn').value.trim(),
  };
  const kodEl = document.getElementById('sk-kod');
  if (kodEl) data.kod = kodEl.value.trim();
  const aktEl = document.getElementById('sk-aktivni');
  if (aktEl) data.aktivni = aktEl.checked ? 1 : 0;

  try {
    if (id) {
      await api('admin_sklady.php?id=' + id, { method: 'PUT', body: JSON.stringify(data) });
    } else {
      await api('admin_sklady.php', { method: 'POST', body: JSON.stringify(data) });
    }
    closeModal();
    renderSkladyInline();
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

window.smazatSklad = async function(id, nazev) {
  if (!(await confirmDialog({ msg: t('confirm_delete_warehouse', { nazev }), danger: false }))) return;
  try {
    const r = await api('admin_sklady.php?id=' + id, { method: 'DELETE' });
    renderSkladyInline();
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

// 🆕 v2.9.232 — Export skladu (per-sklad: položky + volitelně historie pohybů)
// Formáty: pdf (HTML print-ready, nové okno), csv (download), xml (download), json (download)
window.exportSklad = function(skladId, format) {
  if (!skladId) { alert('Chybí ID skladu'); return; }
  const url = '../api/admin_sklad_export.php?sklad_id=' + skladId + '&format=' + encodeURIComponent(format);
  if (format === 'pdf' || format === 'html') {
    // Otevři v novém okně — uvnitř je 'Tisk / PDF' tlačítko (window.print)
    window.open(url, '_blank', 'noopener,noreferrer');
  } else {
    // CSV / JSON / XML — stahování přes Content-Disposition: attachment header
    window.location.href = url;
  }
};

// Export i s historií pohybů (audit trail) — pro pokročilejší use case
window.exportSkladSPohyby = function(skladId, format) {
  if (!skladId) { alert('Chybí ID skladu'); return; }
  const url = '../api/admin_sklad_export.php?sklad_id=' + skladId + '&format=' + encodeURIComponent(format) + '&pohyby=1';
  if (format === 'pdf' || format === 'html') {
    window.open(url, '_blank', 'noopener,noreferrer');
  } else {
    window.location.href = url;
  }
};

// =============================================================
// 📤 SPRÁVA EXPORTŮ + INVENTUR — hub modal (v2.9.239)
// 2 taby: Exporty (per sklad × 4 formáty) + Inventura (batch update)
// =============================================================
window.otevritSpravuExportu = async function() {
  state._spravaTab = state._spravaTab || 'exporty';
  state._spravaSkladId = state._spravaSkladId || 0;
  openModal('📤 Správa exportů & inventur', `<div id="sprava-body" style="min-height:300px">⏳ Načítám…</div>`, 'wide');
  await spravaRender();
};

async function spravaRender() {
  const body = document.getElementById('sprava-body');
  if (!body) return;
  try {
    const r = await api('admin_sklady.php');
    const sklady = (r.sklady || []).filter(s => s.aktivni);
    if (sklady.length === 0) {
      body.innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-3)"><div style="font-size:48px;margin-bottom:10px">🏭</div><p>Žádné aktivní sklady. Vytvoř první sklad.</p></div>';
      return;
    }

    const aktTab = state._spravaTab || 'exporty';
    const typIcon = { suchy: '📦', lednice: '❄️', mrazak: '🧊', jiny: '🏭' };

    body.innerHTML = `
      <!-- Sub-taby panelu -->
      <div class="seg-tabs" role="tablist" style="margin-bottom:18px">
        <button type="button" role="tab" class="seg-tab ${aktTab === 'exporty' ? 'active' : ''}"
                onclick="state._spravaTab='exporty';spravaRender()" aria-selected="${aktTab === 'exporty'}">
          <span class="seg-tab-icon">📤</span>
          <span class="seg-tab-text">Exporty</span>
        </button>
        <button type="button" role="tab" class="seg-tab ${aktTab === 'inventura' ? 'active' : ''}"
                onclick="state._spravaTab='inventura';spravaRender()" aria-selected="${aktTab === 'inventura'}">
          <span class="seg-tab-icon">📝</span>
          <span class="seg-tab-text">Inventura</span>
        </button>
        <!-- 🆕 v2.9.272 — Porovnání skladů (pivot tabulka + součty) -->
        <button type="button" role="tab" class="seg-tab ${aktTab === 'porovnani' ? 'active' : ''}"
                onclick="state._spravaTab='porovnani';spravaRender()" aria-selected="${aktTab === 'porovnani'}">
          <span class="seg-tab-icon">📊</span>
          <span class="seg-tab-text">Porovnání</span>
        </button>
      </div>

      <div id="sprava-tab-body"></div>
    `;

    const tabBody = document.getElementById('sprava-tab-body');
    if (aktTab === 'exporty') {
      // ─── EXPORTY TAB ──────────────────────────────────────
      tabBody.innerHTML = `
        <p style="font-size:13px;color:var(--text-3);margin:0 0 14px;line-height:1.5">
          Vyber sklad a formát exportu. Volitelně zaškrtni „Včetně pohybů" pro kompletní
          audit trail (příjem/výdej/inventura/přesun).
        </p>

        <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:var(--surface-2);border-radius:8px;margin-bottom:14px;cursor:pointer">
          <input type="checkbox" id="sprava-pohyby" style="width:18px;height:18px;cursor:pointer">
          <span style="font-size:13.5px"><strong>Včetně pohybů</strong> — audit trail (posledních ~5000 pohybů per sklad)</span>
        </label>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px">
          ${sklady.map(s => `
            <div class="card-block" style="padding:14px;display:flex;flex-direction:column;gap:8px">
              <div style="display:flex;align-items:center;gap:10px">
                <span style="font-size:28px;line-height:1">${typIcon[s.typ] || '🏭'}</span>
                <div style="flex:1;min-width:0">
                  <div style="font-weight:700;font-size:14px">${esc(s.nazev)}</div>
                  <div style="font-size:11px;color:var(--text-3);font-family:monospace">${esc(s.kod)}</div>
                </div>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:6px">
                <button class="btn-secondary" onclick="spravaExport(${s.id}, 'pdf')" style="font-size:12px;padding:8px 10px" title="HTML print-ready">📄 PDF</button>
                <button class="btn-secondary" onclick="spravaExport(${s.id}, 'csv')" style="font-size:12px;padding:8px 10px" title="CSV pro Excel">📊 CSV</button>
                <button class="btn-secondary" onclick="spravaExport(${s.id}, 'xml')" style="font-size:12px;padding:8px 10px" title="XML pro POHODA/Money S3">🔌 XML</button>
                <button class="btn-secondary" onclick="spravaExport(${s.id}, 'json')" style="font-size:12px;padding:8px 10px" title="JSON pro API">{ } JSON</button>
              </div>
            </div>
          `).join('')}
        </div>
      `;
    } else if (aktTab === 'inventura') {
      // ─── INVENTURA TAB ────────────────────────────────────
      const skladId = state._spravaSkladId || sklady[0].id;
      state._spravaSkladId = skladId;
      const sklad = sklady.find(s => s.id === skladId);
      tabBody.innerHTML = `
        <p style="font-size:13px;color:var(--text-3);margin:0 0 14px;line-height:1.5">
          Vyber sklad → načti aktuální stavy → zadej skutečné stavy (fyzická inventura) →
          systém vypočte rozdíly a vytvoří inventurní pohyby pro každou položku, kde se
          stav liší. <strong>Snapshot zůstává v audit trailu.</strong>
        </p>

        <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:14px">
          <div style="flex:1;min-width:200px">
            <label class="form-label">Sklad pro inventuru *</label>
            <select class="form-select" id="invSkladId" onchange="state._spravaSkladId=parseInt(this.value);spravaRender()">
              ${sklady.map(s => `<option value="${s.id}" ${s.id === skladId ? 'selected' : ''}>${typIcon[s.typ] || '🏭'} ${esc(s.kod)} · ${esc(s.nazev)}</option>`).join('')}
            </select>
          </div>
          <button class="btn-primary btn-green" onclick="spravaLoadInventura(${skladId})" style="padding:11px 18px;font-size:13px;font-weight:700">📋 Načíst položky skladu</button>
        </div>

        <div id="sprava-inventura-form"></div>
      `;
    } else {
      // ─── 🆕 v2.9.272 — POROVNÁNÍ SKLADŮ (pivot položka × sklad) ──────────────
      tabBody.innerHTML = `
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:14px">
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;cursor:pointer;padding:6px 10px;background:var(--surface-2);border-radius:8px">
              <input type="checkbox" id="porovTypFilter" style="width:16px;height:16px;cursor:pointer" onchange="spravaPorovnaniRender()">
              <span>Jen výrobky (skrýt suroviny)</span>
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;cursor:pointer;padding:6px 10px;background:var(--surface-2);border-radius:8px">
              <input type="checkbox" id="porovStavFilter" checked style="width:16px;height:16px;cursor:pointer" onchange="spravaPorovnaniRender()">
              <span>Jen s nenulovým stavem</span>
            </label>
          </div>
          <button class="btn-primary btn-green" onclick="otevritPresunPolozky()" style="padding:10px 18px;font-size:13px;font-weight:700">🔄 Přesun mezi sklady</button>
        </div>
        <div id="sprava-porovnani-data"><div class="empty-state" style="padding:40px">⏳ Načítám…</div></div>
      `;
      // Po vyrenderování formuláře načti data
      spravaPorovnaniRender();
    }
  } catch (e) {
    body.innerHTML = `<div style="background:#fde7e9;color:#a8232f;padding:14px;border-radius:8px">❌ Chyba: ${esc(e.message)}</div>`;
  }
}

// 🆕 v2.9.272 — Porovnání skladů: pivot tabulka položka × sklad + součty
window.spravaPorovnaniRender = async function() {
  const host = document.getElementById('sprava-porovnani-data');
  if (!host) return;
  const jenVyrobky = document.getElementById('porovTypFilter')?.checked;
  const jenSStavem = document.getElementById('porovStavFilter')?.checked;
  const typParam = jenVyrobky ? '&item_typ=vyrobek' : '';
  const stavParam = jenSStavem ? '&jen_se_stavem=1' : '';
  host.innerHTML = '<div class="empty-state" style="padding:40px">⏳ Načítám…</div>';

  try {
    const r = await api('admin_sklad_polozky.php?action=compare&jen_aktivni=1' + typParam + stavParam);
    const sklady = r.sklady || [];
    const polozky = r.polozky || [];
    const perSklad = r.sums?.per_sklad || {};
    const hodnotaCelkem = r.sums?.hodnota_celkem || 0;

    if (sklady.length === 0) {
      host.innerHTML = '<div class="empty-state" style="padding:40px">Žádné aktivní sklady</div>';
      return;
    }
    if (polozky.length === 0) {
      host.innerHTML = `
        <div class="empty-state" style="padding:40px">
          ${jenSStavem ? 'Žádné položky s nenulovým stavem.' : 'Žádné položky ve skladech.'}
          ${jenSStavem ? '<br><button class="btn-secondary" onclick="document.getElementById(\'porovStavFilter\').checked=false;spravaPorovnaniRender()" style="margin-top:10px;font-size:12px;padding:6px 12px">Zobrazit i prázdné</button>' : ''}
        </div>`;
      return;
    }

    const typIcon = { suchy: '📦', lednice: '❄️', mrazak: '🧊', jiny: '🏭' };
    const fmtStav = (n) => {
      const v = parseFloat(n) || 0;
      if (v === 0) return '<span style="color:var(--text-3)">—</span>';
      const s = v >= 100 ? v.toFixed(0) : v.toFixed(2).replace(/\.?0+$/, '');
      return `<strong>${s}</strong>`;
    };

    // 🆕 v2.9.277 — mobile responsive helper: pokud má víc skladů než 2, na mobilu může uživatel scrollnout
    const skladaCount = sklady.length;
    const tableWidth = 240 + 60 + skladaCount * 110 + 110 + 90; // px

    host.innerHTML = `
      <!-- Scroll hint na mobilu (viditelný jen pokud tabulka přečnívá) -->
      <div class="porov-scroll-hint" style="display:none;text-align:center;font-size:11px;color:var(--text-3);margin-bottom:6px">
        ← scroll horizontálně pro další sklady →
      </div>
      <div class="porov-table-wrap" style="overflow-x:auto;border:1px solid var(--border);border-radius:10px;-webkit-overflow-scrolling:touch">
        <table class="table porov-table" style="margin:0;font-size:13px;min-width:${tableWidth}px">
          <thead>
            <tr style="background:var(--surface-2)">
              <th style="position:sticky;left:0;background:var(--surface-2);z-index:2;min-width:240px">Položka</th>
              <th style="text-align:center;width:60px">Typ</th>
              ${sklady.map(s => `
                <th class="num" style="min-width:110px" title="${esc(s.nazev)}">
                  <div style="font-size:11px;font-weight:700">${typIcon[s.typ] || '🏭'} ${esc(s.kod)}</div>
                  <div style="font-size:10px;color:var(--text-3);font-weight:400;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100px">${esc(s.nazev)}</div>
                </th>
              `).join('')}
              <th class="num" style="background:#FFF8F0;min-width:110px;border-left:2px solid #F0D9B8">
                <div style="font-size:11px;font-weight:700;color:#854F0B">Σ CELKEM</div>
                <div style="font-size:10px;color:#854F0B;font-weight:400">napříč sklady</div>
              </th>
              <th class="num" style="min-width:90px">Hodnota</th>
            </tr>
          </thead>
          <tbody>
            ${polozky.map(p => `
              <tr ${p.pod_minimem ? 'style="background:#FEF3C7"' : ''}>
                <td style="position:sticky;left:0;background:${p.pod_minimem ? '#FEF3C7' : '#fff'};z-index:1">
                  <strong>${esc(p.nazev)}</strong>
                  ${p.cislo ? `<div style="font-size:11px;color:var(--text-3);font-family:monospace">${esc(p.cislo)}</div>` : ''}
                  ${p.pod_minimem ? '<div style="font-size:10px;color:#991B1B;font-weight:600">⚠ pod minimem</div>' : ''}
                </td>
                <td style="text-align:center;color:var(--text-3);font-size:11px">
                  ${p.item_typ === 'surovina' ? '🌾' : '📦'}
                </td>
                ${sklady.map(s => {
                  const stav = (p.stavy && p.stavy[s.id]) || 0;
                  return `<td class="num" style="font-variant-numeric:tabular-nums">${fmtStav(stav)} ${stav > 0 ? `<span style="color:var(--text-3);font-size:11px">${esc(p.jednotka || '')}</span>` : ''}</td>`;
                }).join('')}
                <td class="num" style="background:#FFFBEB;font-variant-numeric:tabular-nums;border-left:2px solid #F0D9B8">
                  <strong style="color:#854F0B;font-size:14px">${p.celkem > 0 ? p.celkem.toFixed(p.celkem >= 100 ? 0 : 2).replace(/\.?0+$/, '') : '—'}</strong>
                  ${p.celkem > 0 ? `<span style="color:#854F0B;font-size:11px"> ${esc(p.jednotka || '')}</span>` : ''}
                </td>
                <td class="num" style="font-variant-numeric:tabular-nums;font-size:12px">
                  ${p.hodnota_kc > 0 ? `<strong>${Math.round(p.hodnota_kc).toLocaleString('cs-CZ')}</strong> <span style="color:var(--text-3);font-size:11px">Kč</span>` : '<span style="color:var(--text-3)">—</span>'}
                </td>
              </tr>
            `).join('')}
          </tbody>
          <tfoot>
            <tr style="background:#F1F5F9;border-top:2px solid var(--border)">
              <td style="position:sticky;left:0;background:#F1F5F9"><strong>Σ Položek</strong></td>
              <td style="text-align:center;color:var(--text-3)">—</td>
              ${sklady.map(s => {
                const ps = perSklad[s.id] || { pocet_polozek: 0, hodnota_kc: 0 };
                return `
                  <td class="num" style="font-size:12px;color:var(--text-2)">
                    <strong>${ps.pocet_polozek}×</strong>
                    <div style="font-size:10px;color:var(--text-3);font-weight:400">${Math.round(ps.hodnota_kc).toLocaleString('cs-CZ')} Kč</div>
                  </td>
                `;
              }).join('')}
              <td class="num" style="background:#FFF8F0;border-left:2px solid #F0D9B8">
                <strong style="color:#854F0B">${polozky.length}</strong>
                <div style="font-size:10px;color:#854F0B;font-weight:400">unikátních</div>
              </td>
              <td class="num" style="font-size:13px">
                <strong style="color:#854F0B">${Math.round(hodnotaCelkem).toLocaleString('cs-CZ')} Kč</strong>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>

      <p style="font-size:11.5px;color:var(--text-3);margin:12px 0 0;line-height:1.5">
        💡 Pivot zobrazuje stav každé položky ve všech aktivních skladech. Σ CELKEM = součet napříč sklady. Hodnota = stav × cena_baleni / obsah_baleni (jen pro suroviny s nastavenými cenami). Klikni <strong>🔄 Přesun mezi sklady</strong> pro přesun položek.
      </p>
    `;

    // 🆕 v2.9.277 — Detekuj jestli tabulka přečnívá → zobraz scroll hint
    setTimeout(() => {
      const wrap = host.querySelector('.porov-table-wrap');
      const hint = host.querySelector('.porov-scroll-hint');
      if (wrap && hint && wrap.scrollWidth > wrap.clientWidth + 10) {
        hint.style.display = 'block';
      }
    }, 50);
  } catch (e) {
    host.innerHTML = `<div style="background:#fde7e9;color:#a8232f;padding:14px;border-radius:8px">❌ ${esc(e.message)}</div>`;
  }
};

// 🆕 v2.9.272 — Modal pro přesun položky mezi sklady (využívá existing API admin_sklad_pohyby.php?action=presun)
window.otevritPresunPolozky = async function(predvybranaPolozka) {
  // Načti aktivní sklady + položky (s aktuálním stavem) pro výběr
  try {
    const [sklRes, comparison] = await Promise.all([
      api('admin_sklady.php'),
      api('admin_sklad_polozky.php?action=compare&jen_aktivni=1&jen_se_stavem=1'),
    ]);
    const sklady = (sklRes.sklady || []).filter(s => s.aktivni);
    const polozky = comparison.polozky || [];

    if (sklady.length < 2) {
      return alert('Pro přesun jsou potřeba alespoň 2 aktivní sklady.\n\nVytvoř další sklad ve Výroba → Sklady.');
    }
    if (polozky.length === 0) {
      return alert('Žádné položky s nenulovým stavem k přesunu.');
    }

    const typIcon = { suchy: '📦', lednice: '❄️', mrazak: '🧊', jiny: '🏭' };
    const predvybrana = predvybranaPolozka || null;

    openModal('🔄 Přesun položky mezi sklady', `
      <form id="presun-form" onsubmit="event.preventDefault();ulozitPresun()" style="display:flex;flex-direction:column;gap:12px">
        <div>
          <label class="form-label">Položka *</label>
          <select class="form-select" id="presun-polozka" required onchange="presunZmenaPolozky()">
            <option value="">— vyber položku —</option>
            ${polozky.map(p => {
              const key = p.item_typ + ':' + p.item_id;
              const selected = predvybrana && (predvybrana === key) ? 'selected' : '';
              return `<option value="${key}" data-jed="${esc(p.jednotka || '')}" ${selected}>
                ${p.item_typ === 'surovina' ? '🌾' : '📦'} ${esc(p.nazev)} ${p.cislo ? '(' + esc(p.cislo) + ')' : ''} · celkem ${p.celkem.toFixed(2).replace(/\.?0+$/, '')} ${esc(p.jednotka || '')}
              </option>`;
            }).join('')}
          </select>
        </div>

        <div id="presun-stavy-info" style="display:none;background:#F0F9FF;border:1px solid #BAE6FD;border-radius:8px;padding:10px 12px;font-size:12px;color:#0C4A6E">
          ⏳
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div>
            <label class="form-label">🔻 Ze skladu (zdroj) *</label>
            <select class="form-select" id="presun-z" required onchange="presunPocitejMax()">
              <option value="">— zdroj —</option>
              ${sklady.map(s => `<option value="${s.id}">${typIcon[s.typ] || '🏭'} ${esc(s.kod)} · ${esc(s.nazev)}</option>`).join('')}
            </select>
          </div>
          <div>
            <label class="form-label">🔺 Do skladu (cíl) *</label>
            <select class="form-select" id="presun-do" required>
              <option value="">— cíl —</option>
              ${sklady.map(s => `<option value="${s.id}">${typIcon[s.typ] || '🏭'} ${esc(s.kod)} · ${esc(s.nazev)}</option>`).join('')}
            </select>
          </div>
        </div>

        <div>
          <label class="form-label">Množství *</label>
          <div style="display:flex;gap:6px;align-items:stretch">
            <input class="form-input" id="presun-mn" type="number" step="0.001" min="0.001" required placeholder="0" style="flex:1;font-size:16px;font-weight:600">
            <span id="presun-jed" style="display:flex;align-items:center;padding:0 14px;background:var(--surface-2);border:1px solid var(--border);border-radius:8px;font-weight:700;color:var(--text-2);min-width:50px">—</span>
            <button type="button" class="btn-secondary" onclick="presunVse()" style="font-size:11px;padding:6px 10px;white-space:nowrap" title="Přesunout VŠE ze zdrojového skladu">↦ Vše</button>
          </div>
          <div id="presun-max-info" style="font-size:11px;color:var(--text-3);margin-top:4px"></div>
        </div>

        <div>
          <label class="form-label">Poznámka <span style="color:var(--text-3);font-weight:400;font-size:11px">(volitelné — důvod přesunu)</span></label>
          <textarea class="form-input" id="presun-pozn" rows="2" placeholder="např: rotace zásob, doplnění do lednice…"></textarea>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;border-top:1px solid var(--border);padding-top:12px;margin-top:4px">
          <button type="button" class="btn-secondary" onclick="closeModal()">Zrušit</button>
          <button type="submit" class="btn-primary btn-green" style="font-weight:700">🔄 Provést přesun</button>
        </div>
      </form>
    `);

    // Cache pro presun helpers
    state._presunPolozky = polozky;
    state._presunSklady = sklady;
    if (predvybrana) {
      setTimeout(() => presunZmenaPolozky(), 50);
    }
  } catch (e) {
    alert('Chyba načtení dat pro přesun: ' + e.message);
  }
};

// Reload stav info když user změní položku
window.presunZmenaPolozky = function() {
  const sel = document.getElementById('presun-polozka');
  const key = sel?.value;
  if (!key) {
    document.getElementById('presun-stavy-info').style.display = 'none';
    document.getElementById('presun-jed').textContent = '—';
    return;
  }
  const [typ, idStr] = key.split(':');
  const id = parseInt(idStr, 10);
  const p = (state._presunPolozky || []).find(x => x.item_typ === typ && x.item_id === id);
  if (!p) return;

  // Update jednotka v UI
  const jed = p.jednotka || '';
  document.getElementById('presun-jed').textContent = jed || '—';

  // Stavy per sklad — zobraz info box
  const sklady = state._presunSklady || [];
  const stavyArr = sklady.filter(s => (p.stavy && p.stavy[s.id]) > 0).map(s => {
    const stav = p.stavy[s.id];
    return `<strong>${esc(s.kod)}</strong>: ${stav.toFixed(2).replace(/\.?0+$/, '')} ${esc(jed)}`;
  });
  const info = document.getElementById('presun-stavy-info');
  info.innerHTML = stavyArr.length > 0
    ? `📍 Stavy: ${stavyArr.join(' · ')}`
    : '⚠ Položka má všude nulový stav';
  info.style.display = 'block';

  presunPocitejMax();
};

// Recompute max množství na základě zdroje
window.presunPocitejMax = function() {
  const sel = document.getElementById('presun-polozka');
  const key = sel?.value;
  const zId = parseInt(document.getElementById('presun-z')?.value || '0', 10);
  if (!key || !zId) {
    document.getElementById('presun-max-info').textContent = '';
    document.getElementById('presun-mn').max = '';
    return;
  }
  const [typ, idStr] = key.split(':');
  const id = parseInt(idStr, 10);
  const p = (state._presunPolozky || []).find(x => x.item_typ === typ && x.item_id === id);
  if (!p) return;
  const stav = (p.stavy && p.stavy[zId]) || 0;
  const info = document.getElementById('presun-max-info');
  document.getElementById('presun-mn').max = stav > 0 ? stav : '';
  if (stav === 0) {
    info.innerHTML = '<span style="color:#991B1B">⚠ Ve zdrojovém skladu není žádný stav</span>';
  } else {
    info.innerHTML = `📦 Maximum: <strong>${stav.toFixed(2).replace(/\.?0+$/, '')}</strong> ${esc(p.jednotka || '')}`;
  }
};

window.presunVse = function() {
  const sel = document.getElementById('presun-polozka');
  const key = sel?.value;
  const zId = parseInt(document.getElementById('presun-z')?.value || '0', 10);
  if (!key || !zId) return alert('Vyber nejdřív položku a zdrojový sklad');
  const [typ, idStr] = key.split(':');
  const id = parseInt(idStr, 10);
  const p = (state._presunPolozky || []).find(x => x.item_typ === typ && x.item_id === id);
  if (!p) return;
  const stav = (p.stavy && p.stavy[zId]) || 0;
  if (stav <= 0) return alert('Ve zdrojovém skladu není co přesunout');
  document.getElementById('presun-mn').value = stav;
};

window.ulozitPresun = async function() {
  const sel = document.getElementById('presun-polozka');
  const key = sel?.value;
  const zId = parseInt(document.getElementById('presun-z')?.value || '0', 10);
  const doId = parseInt(document.getElementById('presun-do')?.value || '0', 10);
  const mn = parseFloat(document.getElementById('presun-mn')?.value || '0');
  const pozn = document.getElementById('presun-pozn')?.value?.trim() || '';

  if (!key) return alert('Vyber položku');
  if (!zId || !doId) return alert('Vyber oba sklady');
  if (zId === doId) return alert('Zdrojový a cílový sklad musí být různé');
  if (!mn || mn <= 0) return alert('Zadej množství > 0');

  const [typ, idStr] = key.split(':');
  const id = parseInt(idStr, 10);

  try {
    const r = await api('admin_sklad_pohyby.php?action=presun', {
      method: 'POST',
      body: JSON.stringify({
        sklad_id_z: zId,
        sklad_id_do: doId,
        item_typ: typ,
        item_id: id,
        mnozstvi: mn,
        poznamka: pozn || null,
      }),
    });
    closeModal();
    alert(t('warehouse_moved', { mn, z: r.stav_z, do: r.stav_do }));
    // Refresh porovnání pokud je modal otevřený
    if (state._spravaTab === 'porovnani' && typeof spravaPorovnaniRender === 'function') {
      setTimeout(() => spravaPorovnaniRender(), 200);
    }
  } catch (e) {
    alert('Chyba přesunu: ' + e.message);
  }
};

window.spravaExport = function(skladId, format) {
  const sPohyby = document.getElementById('sprava-pohyby')?.checked ? '&pohyby=1' : '';
  const url = '../api/admin_sklad_export.php?sklad_id=' + skladId + '&format=' + encodeURIComponent(format) + sPohyby;
  if (format === 'pdf' || format === 'html') {
    window.open(url, '_blank', 'noopener,noreferrer');
  } else {
    window.location.href = url;
  }
};

window.spravaLoadInventura = async function(skladId) {
  const cont = document.getElementById('sprava-inventura-form');
  if (!cont) return;
  cont.innerHTML = '⏳ Načítám položky skladu…';
  try {
    const r = await api('admin_sklad_polozky.php?sklad_id=' + skladId);
    const items = r.polozky || [];
    if (items.length === 0) {
      cont.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text-3)"><div style="font-size:32px;margin-bottom:6px">📭</div><p>Sklad nemá žádné položky. Nejdřív přiřaď suroviny/výrobky.</p></div>';
      return;
    }

    cont.innerHTML = `
      <div style="background:#FFF8E5;border-left:3px solid #BA7517;padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;color:#854F0B;line-height:1.5">
        💡 <strong>Postup:</strong> Pro každou položku zadej <strong>aktuální fyzický stav</strong>
        (co skutečně máš na skladě). Položky bez změny můžeš nechat prázdné — nezasáhne je
        inventurní pohyb. Klikni „Provést inventuru" pro batch commit.
      </div>

      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <thead>
            <tr style="background:var(--surface-2);color:var(--text-3);font-size:11px;text-transform:uppercase;letter-spacing:0.4px">
              <th style="padding:8px 10px;text-align:left">Položka</th>
              <th style="padding:8px 10px;text-align:right">Systém</th>
              <th style="padding:8px 10px;text-align:right">Skutečnost</th>
              <th style="padding:8px 10px;text-align:right">Rozdíl</th>
              <th style="padding:8px 10px;text-align:left">Jednotka</th>
            </tr>
          </thead>
          <tbody id="inv-tbody">
            ${items.map(p => {
              const stav = parseFloat(p.stav) || 0;
              return `
                <tr style="border-bottom:1px solid var(--border)" data-polozka-id="${p.id}" data-item-typ="${p.item_typ}" data-item-id="${p.item_id}" data-stav-pred="${stav}">
                  <td style="padding:8px 10px">
                    <strong>${esc(p.nazev || '(?)')}</strong>
                    <div style="font-size:11px;color:var(--text-3)">${p.item_typ === 'surovina' ? '🌾 Surovina' : '📦 Výrobek'}${p.cislo ? ` · ${esc(p.cislo)}` : ''}</div>
                  </td>
                  <td style="padding:8px 10px;text-align:right;color:var(--text-3);font-variant-numeric:tabular-nums">${stav.toFixed(2)}</td>
                  <td style="padding:8px 10px;text-align:right">
                    <input type="number" step="0.01" min="0" class="form-input inv-input" placeholder="—"
                           style="width:120px;text-align:right;font-variant-numeric:tabular-nums"
                           oninput="spravaInvDiffUpdate(this)">
                  </td>
                  <td style="padding:8px 10px;text-align:right;font-weight:600;font-variant-numeric:tabular-nums" class="inv-diff" data-diff>—</td>
                  <td style="padding:8px 10px;color:var(--text-3)">${esc(p.jednotka || '')}</td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
      </div>

      <div style="display:flex;gap:8px;justify-content:space-between;align-items:center;flex-wrap:wrap;margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
        <div style="font-size:13px;color:var(--text-3)">
          <span id="inv-count">0</span> položek se změnou
        </div>
        <div style="display:flex;gap:8px">
          <button class="btn-secondary" onclick="closeModal()">Zavřít</button>
          <button class="btn-primary btn-green" onclick="spravaProvestInventuru(${skladId})" style="padding:10px 18px;font-weight:700">📝 Provést inventuru</button>
        </div>
      </div>
    `;
  } catch (e) {
    cont.innerHTML = `<div style="background:#fde7e9;color:#a8232f;padding:14px;border-radius:8px">❌ Chyba: ${esc(e.message)}</div>`;
  }
};

window.spravaInvDiffUpdate = function(input) {
  const tr = input.closest('tr');
  const pred = parseFloat(tr.dataset.stavPred) || 0;
  const novy = input.value.trim();
  const diffCell = tr.querySelector('[data-diff]');
  if (!novy) {
    diffCell.textContent = '—';
    diffCell.style.color = 'var(--text-3)';
  } else {
    const novyNum = parseFloat(novy);
    if (isNaN(novyNum)) return;
    const diff = novyNum - pred;
    if (Math.abs(diff) < 0.005) {
      diffCell.textContent = '0,00';
      diffCell.style.color = 'var(--text-3)';
    } else {
      diffCell.textContent = (diff > 0 ? '+' : '') + diff.toFixed(2);
      diffCell.style.color = diff > 0 ? '#15803d' : '#a8232f';
    }
  }
  // Update counter (kolik řádků má vyplněnou hodnotu)
  const filled = document.querySelectorAll('#inv-tbody .inv-input').length > 0
    ? Array.from(document.querySelectorAll('#inv-tbody .inv-input')).filter(i => i.value.trim() !== '').length
    : 0;
  const counter = document.getElementById('inv-count');
  if (counter) counter.textContent = filled;
};

window.spravaProvestInventuru = async function(skladId) {
  const rows = Array.from(document.querySelectorAll('#inv-tbody tr'));
  const ucinit = [];
  rows.forEach(tr => {
    const input = tr.querySelector('.inv-input');
    const novy = input?.value.trim();
    if (!novy) return;
    const novyNum = parseFloat(novy);
    if (isNaN(novyNum) || novyNum < 0) return;
    const pred = parseFloat(tr.dataset.stavPred) || 0;
    if (Math.abs(novyNum - pred) < 0.005) return; // nezměněno
    ucinit.push({
      item_typ: tr.dataset.itemTyp,
      item_id: parseInt(tr.dataset.itemId),
      novy_stav: novyNum,
      stav_pred: pred,
    });
  });

  if (ucinit.length === 0) {
    alert('Žádné položky nemají změněný stav. Vyplň aktuální fyzické stavy v sloupci „Skutečnost".');
    return;
  }

  if (!(await confirmDialog({ msg: t('confirm_inventory_action', { n: ucinit.length, label: ucinit.length === 1 ? 'položku' : (ucinit.length < 5 ? 'položky' : 'položek') }), danger: false }))) return;

  let ok = 0, fail = 0;
  for (const item of ucinit) {
    try {
      await api('admin_sklad_pohyby.php?action=inventura', {
        method: 'POST',
        body: JSON.stringify({
          sklad_id: skladId,
          item_typ: item.item_typ,
          item_id: item.item_id,
          novy_stav: item.novy_stav,
          poznamka: 'Hromadná inventura ze Správy exportů',
        }),
      });
      ok++;
    } catch (e) {
      console.error('Inventura selhala:', item, e);
      fail++;
    }
  }

  const msg = fail > 0
    ? `⚠️ Inventura: ${ok} OK · ${fail} selhalo (viz konzole)`
    : `✅ Inventura provedena: ${ok} ${ok === 1 ? 'pohyb' : 'pohybů'}`;
  alert(msg);

  closeModal();
  if (typeof renderSkladyInline === 'function') renderSkladyInline();
};

// 🆕 v3.0.332 — Navádějící dialog ze skeneru: „kde leží" + rychlý příjem na pozici + úprava pozice.
window.skladScanDialog = async function(match) {
  if (!match) return;
  state._skladScanMatch = match;
  const nm = match.nazev || match.ean || match.cislo || 'Položka';
  openModal('📍 ' + nm, '<div id="skladscan-body" style="min-height:160px">⏳ Načítám…</div>');
  await window.skladScanDialogRefresh();
};

window.skladScanDialogRefresh = async function() {
  let m = state._skladScanMatch;
  const body = document.getElementById('skladscan-body');
  if (!m || !body) return;
  // Re-resolve dle kódu → čerstvé pozice/stavy po akci
  const code = m.ean || m.cislo || m.code;
  if (code) { try { const r = await api('admin_scan.php?code=' + encodeURIComponent(code)); if (r && r.match) { m = Object.assign({}, m, r.match); state._skladScanMatch = m; } } catch (e) {} }
  // Sklady (aktivní) pro výběr příjmu — z compare endpointu (spolehlivý tvar)
  let sklady = [];
  try { sklady = (await api('admin_sklad_polozky.php?action=compare&jen_aktivni=1')).sklady || []; } catch (e) {}
  const typLabel = m.type === 'surovina' ? '🌾 Surovina' : '📦 Výrobek';
  const lok = m.sklad_pozice || [];
  body.innerHTML = `
    <div style="font-size:12px;color:var(--text-3);margin-bottom:12px">${typLabel}${m.cislo ? ' · ' + esc(m.cislo) : ''}${m.ean ? ' · 🏷️ ' + esc(m.ean) : ''}</div>

    <h4 style="margin:0 0 6px;font-size:13px">📍 Kde leží</h4>
    ${lok.length ? `<div style="display:flex;flex-direction:column;gap:6px;margin-bottom:16px">
      ${lok.map(l => `
        <div style="display:flex;gap:6px;align-items:center;padding:8px 10px;background:var(--surface-2);border-radius:8px">
          <div style="flex:1;min-width:0">
            <strong style="font-size:12.5px">${esc(l.kod || '')}</strong> <span style="font-size:12px;color:var(--text-3)">${esc(l.sklad || '')}</span>
            <div style="font-size:11px;color:var(--text-3)">stav: ${(+l.stav).toFixed(2)}</div>
          </div>
          <input class="form-input" id="pz-${l.polozka_id}" value="${esc(l.pozice || '')}" placeholder="regál/police" style="width:130px;padding:5px 8px;font-size:12px">
          <button class="btn-icon" onclick="skladScanSavePozice(${l.polozka_id},'pz-${l.polozka_id}')" title="Uložit pozici" style="font-size:13px">💾</button>
        </div>`).join('')}
    </div>` : '<div style="font-size:12px;color:var(--text-3);margin-bottom:16px">Zatím bez stavu/pozice na žádném skladu.</div>'}

    <h4 style="margin:0 0 6px;font-size:13px">➕ Naskladnit (příjem)</h4>
    <div style="display:grid;grid-template-columns:1fr 90px;gap:8px">
      <select class="form-input" id="ss-sklad" style="font-size:13px">${sklady.map(s => `<option value="${s.id}">${esc(s.kod)} · ${esc(s.nazev)}</option>`).join('')}</select>
      <input class="form-input" id="ss-qty" type="number" step="0.01" min="0" placeholder="množ." style="font-size:13px">
      <input class="form-input" id="ss-poz" placeholder="pozice (regál/police)" value="${esc((lok[0] && lok[0].pozice) || '')}" style="grid-column:1/-1;font-size:13px">
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
      <button class="btn-secondary" onclick="closeModal()">Zavřít</button>
      <button class="btn-primary btn-green" onclick="skladScanPrijem()" style="font-weight:700">📦 Naskladnit</button>
    </div>`;
};

window.skladScanSavePozice = async function(polozkaId, inputId) {
  const el = document.getElementById(inputId);
  if (!el) return;
  try {
    await api('admin_sklad_polozky.php?id=' + polozkaId, { method: 'PUT', body: JSON.stringify({ pozice: el.value }) });
    toastSuccess('📍 Pozice uložena');
    skladScanDialogRefresh();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.skladScanPrijem = async function() {
  const m = state._skladScanMatch;
  const skladEl = document.getElementById('ss-sklad'), qtyEl = document.getElementById('ss-qty'), pozEl = document.getElementById('ss-poz');
  if (!m || !skladEl) return;
  const sklad = parseInt(skladEl.value), qty = parseFloat(qtyEl.value), poz = (pozEl.value || '').trim();
  if (!sklad) { alert('Vyber sklad.'); return; }
  if (!qty || qty <= 0) { alert('Zadej kladné množství.'); return; }
  try {
    const payload = { sklad_id: sklad, item_typ: m.type, item_id: m.id, mnozstvi: qty };
    if (poz) payload.pozice = poz;
    await api('admin_sklad_pohyby.php?action=prijem', { method: 'POST', body: JSON.stringify(payload) });
    toastSuccess('📦 Naskladněno' + (poz ? ' → ' + poz : ''));
    if (qtyEl) qtyEl.value = '';
    skladScanDialogRefresh();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🆕 v3.0.332 — úprava pozice položky přímo z detailu skladu (prompt → PUT pozice).
window.skladSetPozice = async function(polozkaId) {
  const nova = prompt('Pozice ve skladu (regál / police / bin). Prázdné = smazat:');
  if (nova === null) return;
  try {
    await api('admin_sklad_polozky.php?id=' + polozkaId, { method: 'PUT', body: JSON.stringify({ pozice: nova.trim() }) });
    toastSuccess('📍 Pozice uložena');
    if (state._currentSkladId) otevritSklad(state._currentSkladId, state._currentSkladNazev, state._currentSkladKod || '');
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🆕 v2.9.216 — Detail skladu (modal) — seznam přiřazených položek s edit
window.otevritSklad = async function(skladId, nazev, kod) {
  state._currentSkladId = skladId;  // pro pohybSkladu actions
  state._currentSkladNazev = nazev;
  state._currentSkladKod = kod || '';
  openModal(`🏭 ${kod} · ${nazev}`, `
    <div id="sklad-detail-content" style="min-height:200px">⏳ Načítám položky…</div>
  `);
  try {
    const r = await api('admin_sklad_polozky.php?sklad_id=' + skladId);
    const items = r.polozky || [];
    const suroviny = items.filter(p => p.item_typ === 'surovina');
    const vyrobky = items.filter(p => p.item_typ === 'vyrobek');
    const c = document.getElementById('sklad-detail-content');
    if (!c) return;

    const renderTable = (rows, typLabel, typIcon) => rows.length === 0 ? '' : `
      <h3 style="margin:16px 0 8px;font-size:14px;color:var(--text-2)">${typIcon} ${typLabel} <span style="color:var(--text-3);font-weight:400">(${rows.length})</span></h3>
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
          <tr style="background:var(--surface-2);color:var(--text-3);font-size:11px;text-transform:uppercase;letter-spacing:0.4px">
            <th style="padding:8px 10px;text-align:left">Kód</th>
            <th style="padding:8px 10px;text-align:left">Název</th>
            <th style="padding:8px 10px;text-align:right">Stav</th>
            <th style="padding:8px 10px;text-align:right">Min</th>
            <th style="padding:8px 10px;text-align:right">Cíl</th>
            <th style="padding:8px 10px;text-align:left">📍 Pozice</th>
            <th style="padding:8px 10px;text-align:right">Akce</th>
          </tr>
        </thead>
        <tbody>
          ${rows.map(p => {
            const stav = parseFloat(p.stav) || 0;
            const min = p.min_stav !== null ? parseFloat(p.min_stav) : null;
            const underMin = min !== null && stav <= min;
            return `
              <tr style="border-bottom:1px solid var(--border)">
                <td style="padding:8px 10px;font-family:monospace;color:var(--text-3);font-size:11.5px">${esc(p.cislo || '—')}</td>
                <td style="padding:8px 10px"><strong>${esc(p.nazev || '?')}</strong></td>
                <td style="padding:8px 10px;text-align:right;${underMin ? 'color:#c66800;font-weight:700' : ''}">${stav.toFixed(2)} ${esc(p.jednotka || '')}${underMin ? ' ⚠️' : ''}</td>
                <td style="padding:8px 10px;text-align:right;color:var(--text-3)">${min !== null ? min.toFixed(2) : '—'}</td>
                <td style="padding:8px 10px;text-align:right;color:var(--text-3)">${p.cil_stav !== null ? parseFloat(p.cil_stav).toFixed(2) : '—'}</td>
                <td style="padding:8px 10px;white-space:nowrap">
                  <span style="font-size:12px">${p.pozice ? esc(p.pozice) : '<span style="color:var(--text-3)">—</span>'}</span>
                  <button class="btn-icon" onclick="skladSetPozice(${p.id})" title="Upravit pozici" style="font-size:11px;padding:2px 5px">✏️</button>
                </td>
                <td style="padding:8px 10px;text-align:right;white-space:nowrap">
                  <button class="btn-secondary" onclick="pohybSkladu(${state._currentSkladId || 0}, ${p.id}, 'prijem', '${esc(p.nazev)}', '${esc(p.item_typ)}', ${p.item_id}, '${esc(p.jednotka || '')}')" style="font-size:11px;padding:4px 8px;background:#dcfce7;color:#15803d;border-color:#dcfce7" title="Příjem">+</button>
                  <button class="btn-secondary" onclick="pohybSkladu(${state._currentSkladId || 0}, ${p.id}, 'vydej', '${esc(p.nazev)}', '${esc(p.item_typ)}', ${p.item_id}, '${esc(p.jednotka || '')}')" style="font-size:11px;padding:4px 8px;background:#fef3c7;color:#854F0B;border-color:#fef3c7" title="Výdej">−</button>
                  <button class="btn-secondary" onclick="pohybSkladu(${state._currentSkladId || 0}, ${p.id}, 'inventura', '${esc(p.nazev)}', '${esc(p.item_typ)}', ${p.item_id}, '${esc(p.jednotka || '')}')" style="font-size:11px;padding:4px 8px" title="Inventura">📝</button>
                  <button class="btn-secondary" onclick="pohybSkladu(${state._currentSkladId || 0}, ${p.id}, 'presun', '${esc(p.nazev)}', '${esc(p.item_typ)}', ${p.item_id}, '${esc(p.jednotka || '')}')" style="font-size:11px;padding:4px 8px" title="Přesun do jiného skladu">↔</button>
                  <button class="btn-secondary" onclick="editSkladPolozku(${p.id}, ${stav}, ${min ?? 'null'}, ${p.cil_stav ?? 'null'}, '${esc(p.nazev)}')" style="font-size:11px;padding:4px 8px" title="Edit min/cíl">✏️</button>
                  <button class="btn-secondary" onclick="odebratPolozku(${p.id}, '${esc(p.nazev)}')" style="font-size:11px;padding:4px 8px;background:#fde7e9;color:#a8232f;border-color:#fde7e9" title="Odebrat ze skladu">×</button>
                </td>
              </tr>
            `;
          }).join('')}
        </tbody>
      </table>
    `;

    c.innerHTML = `
      <div style="display:flex;gap:6px;border-bottom:1px solid var(--border);margin-bottom:14px;flex-wrap:wrap;align-items:end">
        <button class="btn-secondary skd-tab ${(state._skladDetailTab || 'polozky') === 'polozky' ? 'is-active' : ''}" onclick="state._skladDetailTab='polozky';otevritSklad(${skladId}, '${esc(state._currentSkladNazev || '')}', '')" style="border-radius:8px 8px 0 0;border-bottom:none;font-size:13px;padding:8px 14px">📋 Položky (${items.length})</button>
        <button class="btn-secondary skd-tab ${state._skladDetailTab === 'historie' ? 'is-active' : ''}" onclick="state._skladDetailTab='historie';otevritSklad(${skladId}, '${esc(state._currentSkladNazev || '')}', '')" style="border-radius:8px 8px 0 0;border-bottom:none;font-size:13px;padding:8px 14px">📊 Historie pohybů</button>
        <!-- 🆕 v2.9.232 — Export menu vpravo, oddělené od tabů -->
        <div style="margin-left:auto;display:flex;gap:6px;align-items:center;padding-bottom:4px">
          <span style="font-size:11px;color:var(--text-3);font-weight:500">Export:</span>
          <button class="btn-secondary" onclick="exportSklad(${skladId}, 'pdf')" title="HTML print-ready — vytisknout nebo uložit jako PDF" style="font-size:12px;padding:5px 10px">📄 PDF</button>
          <button class="btn-secondary" onclick="exportSklad(${skladId}, 'csv')" title="CSV pro Excel / Google Sheets / účetní (středník)" style="font-size:12px;padding:5px 10px">📊 CSV</button>
          <button class="btn-secondary" onclick="exportSklad(${skladId}, 'xml')" title="XML — pro POHODA, Money S3, ABRA" style="font-size:12px;padding:5px 10px">🔌 XML</button>
          <button class="btn-secondary" onclick="exportSklad(${skladId}, 'json')" title="JSON — pro API integrace" style="font-size:12px;padding:5px 10px">{ } JSON</button>
        </div>
      </div>
      <div id="sklad-detail-tab-body"></div>
      <style>.skd-tab.is-active{background:var(--primary,#BA7517);color:#fff;border-color:var(--primary,#BA7517)}</style>
    `;

    const body = document.getElementById('sklad-detail-tab-body');
    if ((state._skladDetailTab || 'polozky') === 'polozky') {
      body.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <div style="font-size:13px;color:var(--text-3)">${items.length} ${items.length === 1 ? 'položka' : (items.length >= 2 && items.length <= 4 ? 'položky' : 'položek')}</div>
          <button class="btn-primary" onclick="priraditPolozku(${skladId})" style="font-size:13px;padding:8px 14px">+ Přiřadit položku</button>
        </div>
        ${items.length === 0 ? `
          <div style="text-align:center;padding:40px 20px;color:var(--text-3)">
            <div style="font-size:36px;margin-bottom:8px">📭</div>
            <p>Žádné položky ve skladu. Přiřaď první surovinu nebo výrobek.</p>
          </div>
        ` : `
          ${renderTable(suroviny, 'Suroviny', '🌾')}
          ${renderTable(vyrobky, 'Výrobky', '📦')}
        `}
      `;
    } else {
      body.innerHTML = '⏳ Načítám historii…';
      try {
        const hist = await api('admin_sklad_pohyby.php?sklad_id=' + skladId + '&limit=200');
        const pohyby = hist.pohyby || [];
        const typLabel = {
          prijem: { lbl: '➕ Příjem', color: '#15803d' },
          vydej:  { lbl: '➖ Výdej', color: '#854F0B' },
          inventura: { lbl: '📝 Inventura', color: '#0058b8' },
          korekce: { lbl: '🔧 Korekce', color: '#a8232f' },
          presun: { lbl: '↔ Přesun', color: '#5b21b6' },
        };
        if (pohyby.length === 0) {
          body.innerHTML = '<div style="text-align:center;padding:40px 20px;color:var(--text-3)"><div style="font-size:36px;margin-bottom:8px">📭</div><p>Žádné pohyby zatím.</p></div>';
        } else {
          body.innerHTML = `
            <!-- 🆕 v2.9.232 — Export s pohyby (kompletní audit trail) -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px">
              <div style="font-size:13px;color:var(--text-3)">${pohyby.length} pohybů (limit 200, nejnovější první)</div>
              <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                <span style="font-size:11px;color:var(--text-3);font-weight:500">Export včetně pohybů:</span>
                <button class="btn-secondary" onclick="exportSkladSPohyby(${skladId}, 'pdf')" title="HTML print-ready — vč. audit trailu" style="font-size:12px;padding:5px 10px">📄 PDF</button>
                <button class="btn-secondary" onclick="exportSkladSPohyby(${skladId}, 'csv')" title="CSV vč. pohybů" style="font-size:12px;padding:5px 10px">📊 CSV</button>
                <button class="btn-secondary" onclick="exportSkladSPohyby(${skladId}, 'json')" title="JSON vč. pohybů" style="font-size:12px;padding:5px 10px">{ } JSON</button>
              </div>
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:12.5px">
              <thead>
                <tr style="background:var(--surface-2);color:var(--text-3);font-size:11px;text-transform:uppercase;letter-spacing:0.4px">
                  <th style="padding:8px 8px;text-align:left">Kdy</th>
                  <th style="padding:8px 8px;text-align:left">Typ</th>
                  <th style="padding:8px 8px;text-align:left">Položka</th>
                  <th style="padding:8px 8px;text-align:right">Množství</th>
                  <th style="padding:8px 8px;text-align:right">Stav po</th>
                  <th style="padding:8px 8px;text-align:left">Detail</th>
                </tr>
              </thead>
              <tbody>
                ${pohyby.map(p => {
                  const t = typLabel[p.typ] || { lbl: p.typ, color: '#6e6e73' };
                  const isPresun = p.typ === 'presun';
                  const cilTxt = isPresun && p.sklad_id_cil && p.sklad_id_cil != skladId
                    ? ` <span style="color:var(--text-3)">→ ${esc(p.sklad_kod_cil || '?')}</span>`
                    : (isPresun && p.sklad_id != skladId ? ` <span style="color:var(--text-3)">← ${esc(p.sklad_kod || '?')}</span>` : '');
                  const mn = parseFloat(p.mnozstvi);
                  const mnTxt = (mn > 0 ? '+' : '') + mn.toFixed(2);
                  const mnColor = mn > 0 ? '#15803d' : (mn < 0 ? '#a8232f' : '#6e6e73');
                  return `
                    <tr style="border-bottom:1px solid var(--border)">
                      <td style="padding:6px 8px;color:var(--text-3);white-space:nowrap">${esc((p.kdy || '').substring(0, 16).replace('T',' '))}</td>
                      <td style="padding:6px 8px;color:${t.color};font-weight:600">${t.lbl}${cilTxt}</td>
                      <td style="padding:6px 8px">${esc(p.item_nazev || '?')}</td>
                      <td style="padding:6px 8px;text-align:right;color:${mnColor};font-weight:600">${mnTxt}</td>
                      <td style="padding:6px 8px;text-align:right">${p.stav_po !== null ? parseFloat(p.stav_po).toFixed(2) : '—'}</td>
                      <td style="padding:6px 8px;color:var(--text-3);font-size:11.5px">${esc(p.poznamka || '')}${p.kdo ? ` <span style="opacity:0.6">· ${esc(p.kdo)}</span>` : ''}</td>
                    </tr>
                  `;
                }).join('')}
              </tbody>
            </table>
            <p style="font-size:11px;color:var(--text-3);margin-top:10px">📌 Posledních ${pohyby.length} pohybů. Pohyby jsou seřazené od nejnovějších.</p>
          `;
        }
      } catch (e) {
        body.innerHTML = `<div style="background:#fde7e9;color:#a8232f;padding:12px;border-radius:8px">❌ ${esc(e.message)}</div>`;
      }
    }
  } catch (e) {
    const c = document.getElementById('sklad-detail-content');
    if (c) c.innerHTML = `<div style="background:#fde7e9;color:#a8232f;padding:12px;border-radius:8px">❌ ${esc(e.message)}</div>`;
  }
};

// Přiřadit položku do skladu (z detailu skladu)
window.priraditPolozku = async function(skladId) {
  let suroviny = [], vyrobky = [];
  try {
    const [sR, vR] = await Promise.all([
      api('admin_suroviny.php'),
      api('admin_vyrobky.php'),
    ]);
    suroviny = Array.isArray(sR) ? sR : (sR.suroviny || []);
    vyrobky = (vR && vR.vyrobky) || [];
  } catch (e) { alert('Chyba načítání: ' + e.message); return; }

  openModal('➕ Přiřadit položku do skladu', `
    <form onsubmit="event.preventDefault(); ulozitPrirazeni(${skladId})" style="display:flex;flex-direction:column;gap:12px">
      <div>
        <label class="form-label">Typ</label>
        <select class="form-select" id="pp-typ" onchange="document.getElementById('pp-surovina').style.display=this.value==='surovina'?'block':'none';document.getElementById('pp-vyrobek').style.display=this.value==='vyrobek'?'block':'none'">
          <option value="surovina">🌾 Surovina</option>
          <option value="vyrobek">📦 Výrobek</option>
        </select>
      </div>
      <div id="pp-surovina">
        <label class="form-label">Surovina</label>
        <select class="form-select" id="pp-surovina-id">
          ${suroviny.filter(s => s.aktivni).map(s => `<option value="${s.id}">${esc(s.nazev)} (${esc(s.jednotka || '')})</option>`).join('')}
        </select>
      </div>
      <div id="pp-vyrobek" style="display:none">
        <label class="form-label">Výrobek</label>
        <select class="form-select" id="pp-vyrobek-id">
          ${vyrobky.filter(v => v.aktivni).map(v => `<option value="${v.id}">${esc(v.cislo)} — ${esc(v.nazev)}</option>`).join('')}
        </select>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px">
        <div>
          <label class="form-label">Stav</label>
          <input class="form-input" id="pp-stav" type="number" step="0.01" value="0">
        </div>
        <div>
          <label class="form-label">Minimum</label>
          <input class="form-input" id="pp-min" type="number" step="0.01" placeholder="—">
        </div>
        <div>
          <label class="form-label">Cíl</label>
          <input class="form-input" id="pp-cil" type="number" step="0.01" placeholder="—">
        </div>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;border-top:1px solid var(--border);padding-top:12px;margin-top:6px">
        <button type="button" class="btn-secondary" onclick="closeModal()">Zrušit</button>
        <button type="submit" class="btn-primary btn-green" style="font-weight:700">💾 Přiřadit</button>
      </div>
    </form>
  `);
};

window.ulozitPrirazeni = async function(skladId) {
  const typ = document.getElementById('pp-typ').value;
  const itemId = parseInt(typ === 'surovina'
    ? document.getElementById('pp-surovina-id').value
    : document.getElementById('pp-vyrobek-id').value);
  if (!itemId) { alert('Vyber položku'); return; }

  try {
    await api('admin_sklad_polozky.php', {
      method: 'POST',
      body: JSON.stringify({
        sklad_id: skladId,
        item_typ: typ,
        item_id: itemId,
        stav: parseFloat(document.getElementById('pp-stav').value) || 0,
        min_stav: document.getElementById('pp-min').value || null,
        cil_stav: document.getElementById('pp-cil').value || null,
      }),
    });
    closeModal();
    // Re-render detail
    const sklad = await api('admin_sklady.php?id=' + skladId);
    otevritSklad(skladId, sklad.nazev, sklad.kod);
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

window.editSkladPolozku = async function(id, stav, min, cil, nazev) {
  const newStav = (await promptDialog({ msg: `Aktuální stav "${nazev}":`, value: stav }));
  if (newStav === null) return;
  const newMin = (await promptDialog({ msg: `Minimum (prázdné = bez):`, value: min !== null ? min : '' }));
  if (newMin === null) return;
  const newCil = (await promptDialog({ msg: `Cíl (prázdné = bez):`, value: cil !== null ? cil : '' }));
  if (newCil === null) return;

  api('admin_sklad_polozky.php?id=' + id, {
    method: 'PUT',
    body: JSON.stringify({
      stav: parseFloat(newStav) || 0,
      min_stav: newMin === '' ? null : parseFloat(newMin),
      cil_stav: newCil === '' ? null : parseFloat(newCil),
    }),
  }).then(() => {
    // Re-fetch detail
    closeModal();
    // Need skladId — read from modal title? Simpler: re-render whole sklady list
    renderSkladyInline();
  }).catch(e => alert('Chyba: ' + e.message));
};

window.odebratPolozku = async function(id, nazev) {
  if (!(await confirmDialog({ msg: t('confirm_remove_from_warehouse', { nazev }), danger: false }))) return;
  try {
    await api('admin_sklad_polozky.php?id=' + id, { method: 'DELETE' });
    closeModal();
    renderSkladyInline();
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

// 🆕 v2.9.217 — Pohyby (PR3): příjem / výdej / inventura / přesun
window.pohybSkladu = async function(skladId, polozkaId, typ, nazev, itemTyp, itemId, jednotka) {
  const titles = {
    prijem:    `➕ Příjem · ${nazev}`,
    vydej:     `➖ Výdej · ${nazev}`,
    inventura: `📝 Inventura · ${nazev}`,
    presun:    `↔ Přesun · ${nazev}`,
  };
  const labels = {
    prijem:    'Naskladnit množství',
    vydej:     'Vyskladnit množství',
    inventura: 'Aktuální stav (přepíše)',
    presun:    'Přesunout množství',
  };

  // Pro přesun načti seznam cílových skladů (vše kromě aktuálního)
  let sklady = [];
  if (typ === 'presun') {
    try {
      const r = await api('admin_sklady.php');
      sklady = (r.sklady || []).filter(s => s.id !== skladId && s.aktivni);
      if (sklady.length === 0) { alert('Nemáš žádný jiný aktivní sklad — vytvoř nový.'); return; }
    } catch (e) { alert('Chyba: ' + e.message); return; }
  }

  openModal(titles[typ] || typ, `
    <form onsubmit="event.preventDefault(); ulozitPohyb(${skladId}, '${typ}', '${itemTyp}', ${itemId})" style="display:flex;flex-direction:column;gap:12px">
      <div>
        <label class="form-label">${labels[typ] || 'Množství'} *</label>
        <div style="display:flex;align-items:center;gap:8px">
          <input class="form-input" id="poh-mn" type="number" step="0.01" required autofocus placeholder="0.00" style="flex:1">
          <span style="color:var(--text-3);font-size:13px">${esc(jednotka || '')}</span>
        </div>
      </div>
      ${typ === 'presun' ? `
        <div>
          <label class="form-label">Cílový sklad *</label>
          <select class="form-select" id="poh-cil" required>
            ${sklady.map(s => `<option value="${s.id}">${esc(s.kod)} · ${esc(s.nazev)}</option>`).join('')}
          </select>
        </div>
      ` : ''}
      ${typ === 'prijem' ? `
        <div>
          <label class="form-label">Cena za jednotku (Kč) <small style="color:var(--text-3)">— volitelné</small></label>
          <input class="form-input" id="poh-cena" type="number" step="0.01" placeholder="—">
        </div>
      ` : ''}
      <div>
        <label class="form-label">Poznámka ${typ === 'korekce' ? '*' : '<small style="color:var(--text-3)">(volitelná)</small>'}</label>
        <textarea class="form-input" id="poh-pozn" rows="2" ${typ === 'korekce' ? 'required' : ''} placeholder="${typ === 'inventura' ? 'např. Měsíční inventura' : (typ === 'presun' ? 'např. Přesun do mraziku po výrobě' : '')}"></textarea>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;border-top:1px solid var(--border);padding-top:12px;margin-top:6px">
        <button type="button" class="btn-back" onclick="otevritSklad(${skladId}, '${esc(state._currentSkladNazev || '')}', '')">← Zpět</button>
        <button type="submit" class="btn-primary btn-green" style="font-weight:700">${typ === 'prijem' ? '+ Naskladnit' : (typ === 'vydej' ? '− Vyskladnit' : (typ === 'inventura' ? '📝 Uložit inventuru' : '↔ Přesunout'))}</button>
      </div>
    </form>
  `);
};

window.ulozitPohyb = async function(skladId, typ, itemTyp, itemId) {
  const mn = parseFloat(document.getElementById('poh-mn').value) || 0;
  const pozn = document.getElementById('poh-pozn').value.trim();
  const cenaEl = document.getElementById('poh-cena');
  const cilEl = document.getElementById('poh-cil');

  const payload = {
    sklad_id: skladId,
    item_typ: itemTyp,
    item_id: itemId,
    poznamka: pozn || null,
  };
  if (typ === 'inventura') payload.novy_stav = mn;
  else payload.mnozstvi = mn;
  if (cenaEl && cenaEl.value) payload.cena_za_jed = parseFloat(cenaEl.value);
  if (typ === 'presun') {
    payload.sklad_id_z = skladId;
    payload.sklad_id_do = parseInt(cilEl.value);
    delete payload.sklad_id;
  }

  try {
    const r = await api('admin_sklad_pohyby.php?action=' + typ, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    closeModal();
    // Re-render detail skladu
    const sklad = await api('admin_sklady.php?id=' + skladId);
    otevritSklad(skladId, sklad.nazev, sklad.kod);
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

window.setVyrobaMode = function(mode) {
  state.vyrobaMode = mode;
  renderVyrobniListInline();
};

async function renderVyrobaAuto() {
  const tomorrow = new Date();
  tomorrow.setDate(tomorrow.getDate() + 1);
  const datum = state._vyrobaDatum || tomorrow.toISOString().split('T')[0];
  await renderVyrobaAutoForDate(datum);
}

// Helper: zítra je YYYY-MM-DD
function _vyDatumOffset(days) {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d.toISOString().split('T')[0];
}

window.renderVyrobaAutoForDate = async function(datum) {
  state._vyrobaDatum = datum;
  const div = document.getElementById('vyroba-content');

  // Quick-pick datumy
  const dnes = _vyDatumOffset(0);
  const zitra = _vyDatumOffset(1);
  const pozitri = _vyDatumOffset(2);

  // Pojmenování dne
  const datumDate = new Date(datum);
  const dny = ['Neděle','Pondělí','Úterý','Středa','Čtvrtek','Pátek','Sobota'];
  const denNazev = dny[datumDate.getDay()];

  // Měsíc pro kalendář (defaultně měsíc vybraného dne)
  const calRok  = state._vyKalRok  || datumDate.getFullYear();
  const calMesic = state._vyKalMesic || (datumDate.getMonth() + 1);
  state._vyKalRok = calRok;
  state._vyKalMesic = calMesic;

  div.innerHTML = `
    <!-- Quick-pick datumové taby -->
    <div class="period-tabs">
      <button class="period-tab ${datum === dnes ? 'active' : ''}" onclick="renderVyrobaAutoForDate('${dnes}')"><span class="period-tab-icon">📅</span><span class="period-tab-text">Dnes</span></button>
      <button class="period-tab ${datum === zitra ? 'active' : ''}" onclick="renderVyrobaAutoForDate('${zitra}')"><span class="period-tab-icon">➡️</span><span class="period-tab-text">Zítra</span></button>
      <button class="period-tab ${datum === pozitri ? 'active' : ''}" onclick="renderVyrobaAutoForDate('${pozitri}')"><span class="period-tab-icon">⏭️</span><span class="period-tab-text">Pozítří</span></button>
      <button class="period-tab" onclick="vyrobaToggleKalendar()" id="vy-kal-toggle">📆 Kalendář</button>
    </div>

    <!-- Měsíční kalendář (collapsible) -->
    <div id="vy-kalendar" class="vy-kalendar" style="display:${state._vyKalShow ? 'block' : 'none'}">
      <div class="vy-kal-loading" style="padding:30px;text-align:center;color:var(--text-3)">⏳ Načítám kalendář…</div>
    </div>

    <div class="filters" style="margin-bottom:14px">
      <input class="filter-input" type="date" id="vy-datum" value="${datum}" onchange="renderVyrobaAutoForDate(this.value)">
      <a href="../api/vyrobni_list_print.php?datum=${datum}" target="_blank" class="btn-primary" style="text-decoration:none;">🖨️ Tisk / PDF</a>
    </div>

    <p class="period-range">📅 <strong>${denNazev}</strong> ${fmtDate(datum)}</p>

    <div id="vyroba-data"><div class="empty-state" style="padding:40px">Načítám…</div></div>
  `;

  // Načti kalendář (i když je collapsed, ať je hned k dispozici)
  if (state._vyKalShow) loadVyrobaKalendar(calRok, calMesic);

  try {
    let data = await api(`admin_vyroba.php?datum=${datum}`);
    // 🆕 v2.9.288 — defenzivní fallback pro broken/empty API response
    if (!data || typeof data !== 'object') data = {};
    if (!Array.isArray(data.souhrn)) data.souhrn = [];
    if (!Array.isArray(data.po_pobockach)) data.po_pobockach = [];
    const dd = document.getElementById('vyroba-data');

    if (data.souhrn.length === 0) {
      dd.innerHTML = '<div class="card-block"><div class="empty-state">Žádné objednávky na ' + fmtDate(datum) + '</div></div>';
      return;
    }

    // Statistiky
    const celkemKusu = data.souhrn.reduce((sum, s) => sum + Number(s.celkem || 0), 0);
    const pocetVyrobku = data.souhrn.length;
    const pocetObjednavek = new Set(data.po_pobockach.map(p => p.objednavka_id)).size;
    const pocetMist = data.po_pobockach.length;

    // Seskup souhrn podle kategorií
    const kategorie = {};
    data.souhrn.forEach(s => {
      const k = s.kategorie || 'Bez kategorie';
      if (!kategorie[k]) kategorie[k] = { ikona: s.kategorie_ikona || '🥖', items: [] };
      kategorie[k].items.push(s);
    });

    dd.innerHTML = `
      <!-- Statistiky -->
      <div class="stat-grid" style="margin-bottom:14px">
        <div class="stat-card">
          <div class="stat-label">Objednávek</div>
          <div class="stat-value">${pocetObjednavek}</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Míst dodání</div>
          <div class="stat-value">${pocetMist}</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Druhů výrobků</div>
          <div class="stat-value">${pocetVyrobku}</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Celkem kusů</div>
          <div class="stat-value">${Math.round(celkemKusu)}</div>
        </div>
      </div>

      <div class="card-block">
        <h3>📋 Souhrn — kolik celkem upéct (po kategoriích)</h3>
        ${Object.entries(kategorie).map(([nazev, data]) => `
          <div class="vyroba-kat-block">
            <div class="vyroba-kat-head">${esc(data.ikona)} ${esc(nazev)} <span style="color:var(--text-3);font-weight:400">(${data.items.length})</span></div>
            ${data.items.map((s) => `
              <div class="vyroba-souhrn-row">
                <span class="vyroba-ikona">${s.obrazek_url ? `<img src="${esc(s.obrazek_url)}" alt="" style="width:32px;height:32px;border-radius:6px;object-fit:cover">` : esc(s.kategorie_ikona || '🥖')}</span>
                <div class="vyroba-info">
                  <div class="vyroba-name">${esc(s.nazev)}</div>
                  <div class="vyroba-cislo">${esc(s.cislo || '')}${s.hmotnost_g ? ' · ' + s.hmotnost_g + ' g' : ''}</div>
                </div>
                <div class="vyroba-mnozstvi">${Math.round(s.celkem)} ${esc(s.jednotka || 'ks')}</div>
              </div>
            `).join('')}
          </div>
        `).join('')}
      </div>

      <!-- 🌾 Spotřeba surovin podle receptur — kontrola skladu před výrobou -->
      <div class="card-block" id="vy-spotreba-host" style="position:relative">
        <h3 style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px">
          <span>🌾 Spotřeba surovin <span style="color:var(--text-3);font-weight:400;font-size:13px">— co budeš potřebovat ze skladu</span></span>
          <button class="btn-secondary" style="font-size:12px;padding:6px 12px" onclick="vyLoadSpotreba('${datum}')">🔄 Načíst / obnovit</button>
        </h3>
        <div id="vy-spotreba-data" style="font-size:13px;color:var(--text-3);padding:8px">Klikni „Načíst" pro spočítání spotřeby ze surovinových receptur.</div>
      </div>

      <div class="card-block">
        <h3>📦 Rozpis pro expedici (po pobočkách)</h3>
        ${data.po_pobockach.map((p) => `
          <div class="pobocka-rozpis">
            <div class="pobocka-rozpis-head">
              <div>
                <div class="pobocka-rozpis-name">${esc(p.odberatel)}</div>
                <div class="pobocka-rozpis-misto">📍 ${esc(p.misto)}</div>
                <div class="pobocka-rozpis-meta">
                  ${esc(p.adresa || '')}
                  ${p.kontakt ? ' · 👤 ' + esc(p.kontakt) : ''}
                  ${p.telefon ? ' · 📞 ' + esc(p.telefon) : ''}
                </div>
              </div>
              <div style="text-align:right;">
                <div style="font-size:11px;color:var(--text-3);">${esc(p.objednavka_cislo)}</div>
                ${p.cas_dodani ? `<div class="pobocka-rozpis-cas">⏰ ${esc(p.cas_dodani)}</div>` : ''}
              </div>
            </div>
            <div class="pobocka-rozpis-polozky">
              ${p.polozky.map((pol) => `
                <div class="pobocka-rozpis-pol">
                  <span>${esc(pol.vyrobek)}</span>
                  <span class="pobocka-rozpis-pol-mn">${Math.round(pol.mnozstvi)} ks</span>
                </div>
              `).join('')}
            </div>
            ${p.pokyny ? `<div style="margin-top:8px;padding:6px 10px;background:var(--info-bg);color:var(--info-text);border-radius:6px;font-size:12px;">🚚 ${esc(p.pokyny)}</div>` : ''}
            ${p.poznamka ? `<div style="margin-top:6px;padding:6px 10px;background:var(--primary-light);color:var(--primary-dark);border-radius:6px;font-size:12px;">📝 ${esc(p.poznamka)}</div>` : ''}
          </div>
        `).join('')}
      </div>
    `;
  } catch (e) {
    document.getElementById('vyroba-data').innerHTML = `<div style="color:var(--danger-text)">Chyba: ${esc(e.message)}</div>`;
  }
};

