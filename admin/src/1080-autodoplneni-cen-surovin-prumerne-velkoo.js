// =============================================================
// AUTO-DOPLNĚNÍ CEN SUROVIN — průměrné velkoobchodní ceny pro pekařství
// =============================================================
window.surovinyDoplnitCeny = async function() {
  let preview;
  try { preview = await api('admin_suroviny_autofill_ceny.php'); }
  catch (e) { return alert('Chyba: ' + e.message); }

  const navrhy = preview.navrhy || [];
  const matched = navrhy.filter(n => n.matched);
  const unmatched = navrhy.filter(n => !n.matched);

  const fmtKc = n => (parseFloat(n) || 0).toFixed(2).replace('.', ',') + ' Kč';

  const rowsMatched = matched.map(n => {
    const ma = (n.aktualni.cena_baleni > 0 && n.aktualni.obsah_baleni > 0);
    const aktKc = ma ? (n.aktualni.cena_baleni / n.aktualni.obsah_baleni) : null;
    const novyKc = n.navrh.cena_baleni / n.navrh.obsah_baleni;
    const change = aktKc !== null ? ((novyKc - aktKc) / aktKc * 100) : null;
    return `
      <tr>
        <td><strong>${esc(n.nazev)}</strong></td>
        <td style="font-size:11px;color:var(--text-3)">${esc(n.navrh.label)}</td>
        <td class="num" style="font-size:12px">${ma ? fmtKc(aktKc) + '/' + esc(n.aktualni.jednotka) : '—'}</td>
        <td class="num"><strong>${fmtKc(novyKc)}/${esc(n.navrh.jednotka)}</strong></td>
        <td class="num" style="font-size:11px">${change !== null ? `<span style="color:${change > 5 ? '#dc2626' : (change < -5 ? '#16a34a' : 'var(--text-3)')}">${change > 0 ? '+' : ''}${change.toFixed(0)} %</span>` : '<span style="color:var(--text-3)">nová</span>'}</td>
      </tr>
    `;
  }).join('');

  const rowsUnmatched = unmatched.map(n => `<li><span style="color:var(--text-2)">${esc(n.nazev)}</span></li>`).join('');

  openModal('🧾 Doplnit ceny surovin', `
    <p style="font-size:13px;color:var(--text-2);margin-bottom:14px">
      Návrh průměrných velkoobchodních cen pro pekařství v ČR (2024–2025).
      Hodnoty zahrnují <strong>${matched.length}</strong> rozpoznaných surovin z celkem ${navrhy.length}.
    </p>

    <div style="background:#FFF8E7;padding:10px 14px;border-radius:8px;font-size:11px;margin-bottom:12px;border:1px solid #F59E0B33">
      <strong>📋 Pravidla:</strong> ceny jsou orientační velkoobchodní (pro gastro provozy, ne maloobchodní), normalizované na 1 jednotku (1 kg, 1 l, 1 ks). Po aplikaci si je můžeš ručně doupřesnit dle skutečných smluvních cen tvých dodavatelů.
    </div>

    ${matched.length === 0 ? '<div class="empty-state" style="padding:30px">Žádné suroviny k doplnění.</div>' : `
      <div style="max-height:50vh;overflow:auto;border:1px solid var(--border);border-radius:6px;margin-bottom:14px">
        <table class="table" style="margin:0;font-size:13px">
          <thead><tr><th>Surovina</th><th>Rozpoznáno jako</th><th class="num">Aktuální</th><th class="num">Návrh</th><th class="num">Změna</th></tr></thead>
          <tbody>${rowsMatched}</tbody>
        </table>
      </div>
    `}

    ${unmatched.length > 0 ? `
      <details style="margin-bottom:12px;font-size:12px;color:var(--text-3)">
        <summary style="cursor:pointer;padding:6px 0">⚠ Nerozpoznáno (${unmatched.length}) — doplň ručně</summary>
        <ul style="margin:6px 0 0 22px;max-height:140px;overflow:auto;font-size:12px">${rowsUnmatched}</ul>
      </details>
    ` : ''}

    <div style="background:#F0F9FF;border:1px solid #93C5FD;padding:10px 14px;border-radius:8px;font-size:12px;margin-bottom:14px;color:#1e40af">
      ℹ️ <strong>Defaultně</strong> doplní jen suroviny <em>bez ceny</em> (ruční úpravy zachová).<br>
      ⚠️ <strong>Force</strong> přepíše všechny ceny — i ručně zadané.
    </div>

    <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-secondary" onclick="surovinyDoplnitCenyRun(true)" style="color:#dc2626;border-color:#fca5a5">⚠️ Přepsat vše</button>
      <button class="btn-primary btn-green" onclick="surovinyDoplnitCenyRun(false)">✅ Doplnit prázdné</button>
    </div>
  `, 'wide');
};

window.surovinyDoplnitCenyRun = async function(force) {
  closeModal();
  try {
    const r = await api('admin_suroviny_autofill_ceny.php' + (force ? '?force=1' : ''), { method: 'POST', body: '{}' });
    state._suroviny_cache = null; state._suroviny_full_cache = null; // invalidate cache
    await renderSuroviny();
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:14px;font-weight:600;z-index:9999';
    t.innerHTML = `✓ Doplněno <strong>${r.applied}</strong> surovin · přeskočeno ${r.skipped}${r.unmatched > 0 ? ` · nerozpoznáno ${r.unmatched}` : ''}`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4500);
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 📦 Modal pro pohyby skladu (příjem / výdej / inventura) — rychlá akce z listu i detailu
window.surSkladModal = async function(id) {
  const s = await api(`admin_suroviny.php?id=${id}`);
  if (!s) return alert('Surovina nenalezena');
  const jed = s.jednotka || 'g';
  const aktStock = parseFloat(s.stock_aktualni) || 0;
  // Načti i historii
  let pohyby = [];
  try { pohyby = await api(`admin_suroviny.php?action=sklad_pohyby&surovina_id=${id}&limit=20`); } catch {}

  openModal(`📦 Sklad — ${esc(s.nazev)}`, `
    <div style="background:var(--surface-2);padding:14px 16px;border-radius:8px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
      <div>
        <div style="font-size:12px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.5px">Aktuální stav</div>
        <div style="font-size:28px;font-weight:800;color:var(--text-1);font-variant-numeric:tabular-nums">${aktStock.toFixed(2).replace(/\.?0+$/, '').replace('.', ',')} <span style="font-size:14px;color:var(--text-3);font-weight:500">${esc(jed)}</span></div>
      </div>
      <div style="font-size:12px;color:var(--text-3);text-align:right">
        ${s.stock_minimalni ? `Min: ${parseFloat(s.stock_minimalni)} ${esc(jed)}<br>` : ''}
        ${s.stock_cilove    ? `Cíl: ${parseFloat(s.stock_cilove)} ${esc(jed)}` : ''}
      </div>
    </div>

    <!-- Záložky příjem/výdej/inventura -->
    <div class="period-tab-row" style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap">
      <button type="button" class="period-tab active" onclick="surSkladTab('prijem')" data-tab="prijem" style="font-size:14px;padding:10px 16px;font-weight:600"><span class="period-tab-icon">📥</span><span class="period-tab-text">Příjem</span></button>
      <button type="button" class="period-tab" onclick="surSkladTab('vydej')" data-tab="vydej" style="font-size:14px;padding:10px 16px;font-weight:600">📤 Výdej</button>
      <button type="button" class="period-tab" onclick="surSkladTab('vratka')" data-tab="vratka" style="font-size:14px;padding:10px 16px;font-weight:600">↩️ Vratka</button>
      <button type="button" class="period-tab" onclick="surSkladTab('inventura')" data-tab="inventura" style="font-size:14px;padding:10px 16px;font-weight:600">📋 Inventura</button>
    </div>

    <div id="sur-sklad-form">
      ${_skladForm('prijem', s)}
    </div>

    <!-- Historie pohybů -->
    <div style="margin-top:18px">
      <h4 style="margin:0 0 8px;font-size:14px;color:var(--text-2)">📜 Posledních ${pohyby.length} pohybů</h4>
      ${pohyby.length === 0 ? '<div class="empty-state" style="padding:14px;font-size:13px">Žádné pohyby zatím nejsou</div>' : `
        <div style="max-height:240px;overflow-y:auto;border:1px solid var(--border);border-radius:6px">
          <table class="table" style="margin:0;font-size:12px">
            <thead><tr><th>Kdy</th><th>Typ</th><th class="num">Změna</th><th class="num">Stav po</th><th>Pozn.</th></tr></thead>
            <tbody>
              ${pohyby.map(p => {
                const typIko = { prijem: '📥', vydej: '📤', inventura: '📋', korekce: '⚙', vratka: '↩️' }[p.typ] || '';
                const znak = (p.typ === 'vydej') ? '−' : (p.typ === 'inventura' ? '=' : '+');
                return `
                  <tr>
                    <td>${fmtDateTime(p.kdy)}</td>
                    <td>${typIko} ${esc(p.typ)}</td>
                    <td class="num"><strong>${znak}${parseFloat(p.mnozstvi)}</strong> ${esc(p.jednotka || jed)}</td>
                    <td class="num">${parseFloat(p.stock_po).toFixed(2).replace(/\.?0+$/, '').replace('.', ',')}</td>
                    <td style="font-size:11px;color:var(--text-3)">${esc(p.poznamka || '')}${p.kdo ? `<br>${esc(p.kdo)}` : ''}</td>
                  </tr>
                `;
              }).join('')}
            </tbody>
          </table>
        </div>
      `}
    </div>

    <div class="form-actions">
      <!-- "Zavřít" smazáno v v2.5.11 — × v rohu modal-card stačí (větší klikací plocha) -->
    </div>
  `, 'wide');
};

// Helper na render formuláře jedné záložky
function _skladForm(typ, s) {
  const jed = s.jednotka || 'g';
  const ciloveStock = parseFloat(s.stock_cilove) || 0;
  const aktStock = parseFloat(s.stock_aktualni) || 0;
  const navrhMnozstvi = (typ === 'prijem' && ciloveStock > 0 && aktStock < ciloveStock) ? (ciloveStock - aktStock) : '';

  if (typ === 'inventura') {
    return `
      <div class="form-grid form-grid-tight">
        <div class="full">
          <label class="form-label">Skutečný stav po inventuře</label>
          <div style="display:flex;align-items:center;gap:8px">
            <input class="form-input" id="sk-mnozstvi" type="number" step="0.001" min="0" value="${aktStock}" style="font-size:16px;font-weight:600">
            <span style="font-size:14px;color:var(--text-3);white-space:nowrap">${esc(jed)}</span>
          </div>
          <small style="color:var(--text-3);font-size:12px;display:block;margin-top:4px">Nastaví stav přesně na zadanou hodnotu</small>
        </div>
        <div class="full">
          <label class="form-label">Poznámka</label>
          <input class="form-input" id="sk-poznamka" placeholder="např. roční inventura 2026">
        </div>
      </div>
      <div class="form-actions">
        <button class="btn-primary btn-green" onclick="surSkladPotvrdit('inventura', ${s.id})" style="font-size:15px;font-weight:700;padding:14px 24px">📋 Provést inventuru</button>
      </div>
    `;
  }
  // 🆕 v3.0.268 — vratka = příjem zpět na sklad (vlastní auditovaný typ pohybu)
  const label = typ === 'prijem' ? 'Přijaté množství' : (typ === 'vratka' ? 'Vrácené množství' : 'Vydané množství');
  const btnTxt = typ === 'prijem' ? '📥 Naskladnit' : (typ === 'vratka' ? '↩️ Přijmout vratku' : '📤 Odepsat ze skladu');
  const btnColor = (typ === 'prijem' || typ === 'vratka') ? 'btn-green' : 'btn-secondary';
  return `
    <div class="form-grid form-grid-tight">
      <div>
        <label class="form-label">${label}</label>
        <div style="display:flex;align-items:center;gap:8px">
          <input class="form-input" id="sk-mnozstvi" type="number" step="0.001" min="0" placeholder="0" value="${navrhMnozstvi}" style="font-size:16px;font-weight:600">
          <span style="font-size:14px;color:var(--text-3);white-space:nowrap">${esc(jed)}</span>
        </div>
        ${navrhMnozstvi ? `<small style="color:var(--text-3);font-size:12px;display:block;margin-top:4px">💡 Navrženo doplnit do cílové hladiny</small>` : ''}
      </div>
      ${typ === 'prijem' ? `
      <div>
        <label class="form-label">Cena za jednotku (volitelné)</label>
        <div style="display:flex;align-items:center;gap:8px">
          <input class="form-input" id="sk-cena" type="number" step="0.01" min="0" placeholder="0,00">
          <span style="font-size:14px;color:var(--text-3);white-space:nowrap">Kč/${esc(jed)}</span>
        </div>
        <small style="color:var(--text-3);font-size:12px;display:block;margin-top:4px">Pro evidenci, neaktualizuje cena_baleni</small>
      </div>
      ` : '<div></div>'}
      <div class="full">
        <label class="form-label">Poznámka</label>
        <input class="form-input" id="sk-poznamka" placeholder="${typ === 'prijem' ? 'např. dodavatel Penam, šarže 2026/3' : 'např. spotřebováno při výrobě'}">
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-primary ${btnColor}" onclick="surSkladPotvrdit('${typ}', ${s.id})" style="font-size:15px;font-weight:700;padding:14px 24px">${btnTxt}</button>
    </div>
  `;
}

// Přepnutí záložky v sklad modalu
window.surSkladTab = function(typ) {
  document.querySelectorAll('.period-tab-row .period-tab').forEach(b => {
    b.classList.toggle('active', b.dataset.tab === typ);
  });
  // Re-render formuláře — potřebuji surovinu, vezmu z modal title
  const id = parseInt(document.querySelector('[onclick*="surSkladPotvrdit"]')?.getAttribute('onclick')?.match(/(\d+)\)$/)?.[1]);
  // Místo refetch — uložím data atributem (jednodušší)
  const host = document.getElementById('sur-sklad-form');
  if (!host) return;
  // Volání s posledně načtenými daty — uloženo do _state
  if (!window._surSkladState) {
    // Pokud nemáme cache, vyžádáme přes id
    const surId = window._surSkladId || id;
    api(`admin_suroviny.php?id=${surId}`).then(s => {
      window._surSkladState = s;
      host.innerHTML = _skladForm(typ, s);
    });
    return;
  }
  host.innerHTML = _skladForm(typ, window._surSkladState);
};

// Potvrzení pohybu skladu
window.surSkladPotvrdit = async function(typ, surovina_id) {
  const mnozstvi = parseFloat(document.getElementById('sk-mnozstvi')?.value);
  if (isNaN(mnozstvi) || mnozstvi < 0) return alert('Zadejte platné množství');
  if (typ !== 'inventura' && mnozstvi <= 0) return alert('Množství musí být > 0');
  const poznamka = document.getElementById('sk-poznamka')?.value || '';
  const cena = document.getElementById('sk-cena')?.value || '';
  try {
    await api(`admin_suroviny.php?action=sklad_${typ}`, {
      method: 'POST',
      body: JSON.stringify({ surovina_id, mnozstvi, poznamka, cena_za_jed: cena || undefined }),
    });
    state._suroviny_full_cache = null;
    state._suroviny_cache = null;
    closeModal();
    if (state.current === 'suroviny') renderSuroviny();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// Cache surovina pro tab-switching v modalu (drobná optimalizace)
const _origSurSkladModal = window.surSkladModal;
window.surSkladModal = async function(id) {
  window._surSkladId = id;
  window._surSkladState = null;
  return _origSurSkladModal(id);
};

// 🆕 v3.0.204 — 1-klik naskladnění suroviny na cílovou zásobu (low-stock list)
window.doplnitSurovinu = async function(id, silent) {
  const r = await api('admin_suroviny.php?action=restock', { method: 'POST', body: JSON.stringify({ id }) });
  if (!silent) {
    if (r.doplneno > 0) toast(`✓ ${r.nazev}: +${(+r.doplneno).toFixed(2)} ${r.jednotka || ''} → ${(+r.stav).toFixed(2)}`, 'success');
    else toast(`${r.nazev || 'Surovina'}: už na cíli`, 'info');
  }
  return r;
};
window.doplnitVse = async function(ids) {
  if (!Array.isArray(ids) || !ids.length) return;
  if (!(await confirmDialog({ title: 'Doplnit zásoby?', msg: `Naskladnit ${ids.length} surovin pod minimem na jejich cílovou zásobu?`, okText: '🛒 Doplnit vše' }))) return;
  let ok = 0, skip = 0;
  for (const id of ids) {
    try { const r = await window.doplnitSurovinu(id, true); (r && r.doplneno > 0) ? ok++ : skip++; }
    catch (e) { skip++; }
  }
  toast(`🛒 Doplněno ${ok} surovin${skip ? `, ${skip} přeskočeno (bez cíle / už na cíli)` : ''}`, ok ? 'success' : 'info');
  if (typeof renderSkladyInline === 'function') renderSkladyInline();
};

window.editSurovina = async function(id = null) {
  const s = id ? await api(`admin_suroviny.php?id=${id}`) : {};
  openModal(id ? `Surovina: ${esc(s.nazev)}` : 'Nová surovina', `
    <div class="form-grid">
      <div class="full">
        <label class="form-label">Název *</label>
        <input class="form-input" id="sur-nazev" value="${esc(s.nazev || '')}" placeholder="např. Mouka pšeničná hladká" required>
      </div>
      <div>
        <label class="form-label">Jednotka</label>
        <select class="form-select" id="sur-jed" onchange="surPrepocet()">
          ${['g', 'kg', 'ml', 'l', 'ks', 'lžíce', 'lžička'].map(j => `
            <option value="${j}" ${(s.jednotka || 'g') === j ? 'selected' : ''}>${j}</option>
          `).join('')}
        </select>
      </div>
      <div>
        <label class="form-label">Alergen <span style="color:var(--text-3);font-weight:400;font-size:12px">(volitelné)</span></label>
        <input class="form-input" id="sur-aler" value="${esc(s.alergen || '')}" placeholder="lepek, mléko, vejce…">
      </div>

      <div class="full vy-section-box">
        <div class="vy-section-title">💰 Nákupní cena (pro kalkulaci nákladů)</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div>
            <label class="form-label">Cena balení (Kč)</label>
            <input class="form-input" id="sur-cena" type="number" step="0.01" min="0" value="${s.cena_baleni || ''}" placeholder="0,00" oninput="surPrepocet()">
          </div>
          <div>
            <label class="form-label">Obsah balení</label>
            <input class="form-input" id="sur-obsah" type="number" step="0.001" min="0" value="${s.obsah_baleni || ''}" placeholder="např. 25" oninput="surPrepocet()">
          </div>
        </div>
        <div id="sur-prepocet" style="margin-top:8px;padding:8px 12px;background:var(--surface);border-radius:6px;font-size:13px;color:#854F0B;font-weight:600;border:1px solid var(--border)">— vyplň cenu a obsah balení —</div>
      </div>

      <!-- 📦 Sklad — minimální a cílová hladina -->
      <div class="full vy-section-box">
        <div class="vy-section-title" style="display:flex;justify-content:space-between;align-items:center">
          <span>📦 Sklad <span style="color:var(--text-3);font-weight:400;font-size:11px">(volitelné — pro alerty „pod hladinou" a návrh kolik objednat)</span></span>
          ${id ? `<button class="btn-secondary" onclick="surSkladModal(${id})" type="button" style="font-size:11px;padding:4px 10px">📦 Pohyby skladu</button>` : ''}
        </div>
        <div class="form-grid form-grid-tight" style="margin-top:8px">
          <div>
            <label class="form-label">Aktuální stav skladem</label>
            <div style="display:flex;align-items:center;gap:6px">
              <input class="form-input" type="number" step="0.001" min="0" value="${s.stock_aktualni || 0}" disabled style="background:var(--surface-2);color:var(--text-2)">
              <span style="font-size:13px;color:var(--text-3);white-space:nowrap" id="sur-jed-display">${esc(s.jednotka || 'g')}</span>
            </div>
            <small style="color:var(--text-3);font-size:11px;display:block;margin-top:4px">Mění se přes „Pohyby skladu" (audit)</small>
          </div>
          <div>
            <label class="form-label">Minimální hladina (alert)</label>
            <input class="form-input" id="sur-stock-min" type="number" step="0.001" min="0" value="${s.stock_minimalni || ''}" placeholder="—">
          </div>
          <div>
            <label class="form-label">Cílová hladina (pro doplnění)</label>
            <input class="form-input" id="sur-stock-cil" type="number" step="0.001" min="0" value="${s.stock_cilove || ''}" placeholder="—">
          </div>
          <div>
            <label class="form-label">🏭 Domovský sklad <span style="color:var(--text-3);font-weight:400;font-size:11px">(odkud POS/výroba odepisuje)</span></label>
            <select class="form-select" id="sur-home-sklad">
              ${(s.sklady || []).map(sk => `<option value="${sk.id}" ${s.domovsky_sklad_id == sk.id ? 'selected' : ''}>${esc(sk.nazev)}</option>`).join('')}
            </select>
          </div>
        </div>
      </div>

      <div class="full vy-section-box">
        <div class="vy-section-title" style="display:flex;justify-content:space-between;align-items:center">
          <span>🧬 Složení suroviny <span style="color:var(--text-3);font-weight:400;font-size:11px">(pro kompozitní suroviny — Diasauer, směsi, zlepšovadla)</span></span>
          <button class="btn-secondary" onclick="surDetekovatAlergeny()" type="button" style="font-size:11px;padding:4px 10px">🔍 Detekovat alergeny</button>
        </div>
        <textarea class="form-textarea" id="sur-slozeni" rows="2" placeholder="např. Pšeničný slad, sůl, ječný slad, kyselina askorbová, enzymy" oninput="surDetekovatAlergenyAuto()">${esc(s.slozeni || '')}</textarea>
        <div id="sur-slozeni-alergeny" style="margin-top:6px;font-size:12px;color:var(--text-2);min-height:18px">
          ${s.slozeni_alergeny
            ? `<span style="color:#92400e">🔬 Detekované alergeny ze složení: <strong>${esc(s.slozeni_alergeny)}</strong></span>`
            : '<span style="color:var(--text-3)">Vyplň složení — alergeny se detekují automaticky.</span>'}
        </div>
      </div>

      <div class="full vy-section-box">
        <div class="vy-section-title">🍎 Nutriční hodnoty <span style="color:var(--text-3);font-weight:400;font-size:11px">(na 100 g/ml — pro výpočet u výrobků)</span></div>
        <div style="display:grid;grid-template-columns:repeat(2, 1fr);gap:10px">
          <label class="ed-prop-mini"><span>Energie (kJ)</span><input class="form-input" type="number" step="0.1" min="0" id="sur-n-kj" value="${s.nutri_energie_kj ?? ''}" placeholder="0"></label>
          <label class="ed-prop-mini"><span>Energie (kcal)</span><input class="form-input" type="number" step="0.1" min="0" id="sur-n-kcal" value="${s.nutri_energie_kcal ?? ''}" placeholder="0"></label>
          <label class="ed-prop-mini"><span>Tuky (g)</span><input class="form-input" type="number" step="0.01" min="0" id="sur-n-tuky" value="${s.nutri_tuky ?? ''}" placeholder="0"></label>
          <label class="ed-prop-mini"><span>z toho nasycené (g)</span><input class="form-input" type="number" step="0.01" min="0" id="sur-n-tuky-nas" value="${s.nutri_tuky_nasycene ?? ''}" placeholder="0"></label>
          <label class="ed-prop-mini"><span>Sacharidy (g)</span><input class="form-input" type="number" step="0.01" min="0" id="sur-n-sach" value="${s.nutri_sacharidy ?? ''}" placeholder="0"></label>
          <label class="ed-prop-mini"><span>z toho cukry (g)</span><input class="form-input" type="number" step="0.01" min="0" id="sur-n-cukry" value="${s.nutri_cukry ?? ''}" placeholder="0"></label>
          <label class="ed-prop-mini"><span>Bílkoviny (g)</span><input class="form-input" type="number" step="0.01" min="0" id="sur-n-bilk" value="${s.nutri_bilkoviny ?? ''}" placeholder="0"></label>
          <label class="ed-prop-mini"><span>Sůl (g)</span><input class="form-input" type="number" step="0.001" min="0" id="sur-n-sul" value="${s.nutri_sul ?? ''}" placeholder="0"></label>
        </div>
        <div style="margin-top:8px;font-size:11px;color:var(--text-3)">💡 Tip: Hodnoty z obalu výrobcu (e.g. „Hodnoty na 100 g") nebo z databáze potravin. Podle nich systém spočítá nutriční hodnoty u výrobků.</div>
      </div>

      <div class="full">
        <label class="form-label">Poznámka</label>
        <input class="form-input" id="sur-pozn" value="${esc(s.poznamka || '')}" placeholder="např. dodavatel, šarže…">
      </div>
      <div>
        <div class="checkbox-row">
          <input type="checkbox" id="sur-akt" ${s.aktivni == 1 || !id ? 'checked' : ''}>
          <label for="sur-akt">Aktivní</label>
        </div>
      </div>
    </div>

    ${id && Array.isArray(s.pouzito_ve_vyrobcich) && s.pouzito_ve_vyrobcich.length > 0 ? `
      <div class="card-block" style="margin-top:18px;padding:14px 16px;background:var(--surface-2)">
        <h3 style="margin:0 0 10px;font-size:15px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px">
          🥖 Použito ve výrobcích
          <span style="font-size:12px;font-weight:600;color:var(--text-3);background:var(--surface);padding:2px 10px;border-radius:10px">${s.pouzito_ve_vyrobcich.length}</span>
        </h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px">
          ${s.pouzito_ve_vyrobcich.map(v => `
            <button class="btn-secondary" onclick="closeModal();setTimeout(()=>editVyrobek(${v.id}),200)" style="text-align:left;padding:8px 12px;font-size:13px;${!v.aktivni ? 'opacity:0.5' : ''};display:flex;justify-content:space-between;align-items:center;gap:8px">
              <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <strong>${esc(v.nazev)}</strong>
                ${v.cislo ? `<span style="color:var(--text-3);font-size:11px;margin-left:4px">${esc(v.cislo)}</span>` : ''}
              </span>
              <span style="color:var(--text-3);font-size:11px;white-space:nowrap;flex-shrink:0">${parseFloat(v.mnozstvi || 0)} ${esc(v.jednotka || 'g')}</span>
            </button>
          `).join('')}
        </div>
      </div>
    ` : ''}
    ${id && Array.isArray(s.pouzito_ve_vyrobcich) && s.pouzito_ve_vyrobcich.length === 0 ? `
      <div style="margin-top:14px;padding:10px 14px;background:var(--surface-2);border-radius:8px;font-size:12px;color:var(--text-3);font-style:italic">
        ℹ️ Tato surovina zatím není použita v žádném výrobku.
      </div>
    ` : ''}

    <div class="form-actions">
      ${id ? `<div class="form-actions-icons-row"><button class="btn-danger-corner" onclick="smazatSurovinu(${id})" title="Smazat surovinu" aria-label="Smazat surovinu">🗑️</button></div><div style="flex:1"></div>` : ''}
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary" onclick="ulozitSurovinu(${id || 'null'})">Uložit</button>
    </div>
  `);
  setTimeout(surPrepocet, 30);
};

// Klientská detekce 14 EU alergenů ze složení (mirror serveru)
const SUR_ALERGEN_PATTERNS = [
  ['lepek (obiloviny)', /(pšenic|žitn|ječm|ovsen|oves\s|špald|kamut|trit[ic]al|sladk|slad\b|mouka|otrub|krupic|krupk|strouhank|těstovin|kuskus|bulgur|cous?cous|seitan)/iu],
  ['korýši',           /(krevet|krab|humr|languost|korýš|raček)/iu],
  ['vejce',            /(vejce|vajíčk|vaječ|žloutek|bílek|albumin)/iu],
  ['ryby',             /(ryba|tuňák|losos|sardin|tresk|filé\s+rybí|kavi|sleď)/iu],
  ['arašídy',          /(arašíd|burský|peanut)/iu],
  ['sója',             /(sój|tofu|edamame|tempeh)/iu],
  ['mléko',            /(mléko|mlék|smetan|máslo|tvaroh|sýr|jogurt|laktóz|laktoz|kasein|syrovátk|šlehačk)/iu],
  ['ořechy',           /(mandle|lískov.*ořech|vlašsk.*ořech|kešu|pekan|para\s+ořech|pistác|makadam|brazilsk.*ořech|ořechy|ořech\b)/iu],
  ['celer',            /(celer)/iu],
  ['hořčice',          /(hořčic)/iu],
  ['sezam',            /(sezam)/iu],
  ['oxid siřičitý / siřičitany', /(siřič|sulfit|e22[01-8])/iu],
  ['vlčí bob (lupina)', /(lupin|vlčí\s+bob)/iu],
  ['měkkýši',          /(slimák|šnek|mušle|chobotnic|olihn|kalmar|měkkýš|ústřic|hřebenatk)/iu],
];

function surDetekujAlergenyZTextu(text) {
  const t = (text || '').toLowerCase();
  if (!t.trim()) return [];
  return SUR_ALERGEN_PATTERNS.filter(([_, rgx]) => rgx.test(t)).map(([label]) => label);
}

window.surDetekovatAlergenyAuto = function() {
  const txt = document.getElementById('sur-slozeni')?.value || '';
  const found = surDetekujAlergenyZTextu(txt);
  const box = document.getElementById('sur-slozeni-alergeny');
  if (!box) return;
  if (found.length === 0) {
    box.innerHTML = txt.trim()
      ? '<span style="color:var(--text-3)">Žádné alergeny ze 14 EU detekovány.</span>'
      : '<span style="color:var(--text-3)">Vyplň složení — alergeny se detekují automaticky.</span>';
  } else {
    box.innerHTML = `<span style="color:#92400e">🔬 Detekované alergeny ze složení: <strong>${esc(found.join(', '))}</strong></span>`;
  }
};

window.surDetekovatAlergeny = async function() {
  surDetekovatAlergenyAuto();
  const txt = document.getElementById('sur-slozeni')?.value || '';
  const found = surDetekujAlergenyZTextu(txt);
  // Pokud uživatel ještě nemá nic v poli "Alergen", nabídneme automatické vyplnění
  const alerEl = document.getElementById('sur-aler');
  if (!alerEl) return;
  if (found.length === 0) {
    alert('Ze složení nebyl detekován žádný alergen.');
    return;
  }
  if (alerEl.value.trim() === '') {
    alerEl.value = found.join(', ');
    return;
  }
  if ((await confirmDialog({ msg: t('confirm_detected_overwrite_allergens', { found: found.join(', '), current: alerEl.value }), danger: false }))) {
    alerEl.value = found.join(', ');
  }
};

window.surPrepocet = function() {
  const cena = parseFloat(document.getElementById('sur-cena')?.value) || 0;
  const obsah = parseFloat(document.getElementById('sur-obsah')?.value) || 0;
  const jed = document.getElementById('sur-jed')?.value || 'g';
  const box = document.getElementById('sur-prepocet');
  if (!box) return;
  if (cena <= 0 || obsah <= 0) {
    box.innerHTML = '<span style="color:var(--text-3);font-weight:400">— vyplň cenu a obsah balení —</span>';
    return;
  }
  const cenaJed = cena / obsah;
  box.innerHTML = `→ <strong>${cenaJed.toFixed(4).replace(/\.?0+$/, '').replace('.', ',')} Kč</strong> / ${esc(jed)}`;
};

window.ulozitSurovinu = async function(id) {
  const numOrNull = (selektor) => {
    const v = document.getElementById(selektor)?.value;
    return v === '' || v == null ? null : parseFloat(v);
  };
  const data = {
    id: id || undefined,
    nazev: document.getElementById('sur-nazev').value.trim(),
    jednotka: document.getElementById('sur-jed').value,
    alergen: document.getElementById('sur-aler').value.trim() || null,
    cena_baleni: parseFloat(document.getElementById('sur-cena')?.value) || null,
    obsah_baleni: parseFloat(document.getElementById('sur-obsah')?.value) || null,
    slozeni: document.getElementById('sur-slozeni')?.value.trim() || null,
    nutri_energie_kj:    numOrNull('sur-n-kj'),
    nutri_energie_kcal:  numOrNull('sur-n-kcal'),
    nutri_tuky:          numOrNull('sur-n-tuky'),
    nutri_tuky_nasycene: numOrNull('sur-n-tuky-nas'),
    nutri_sacharidy:     numOrNull('sur-n-sach'),
    nutri_cukry:         numOrNull('sur-n-cukry'),
    nutri_bilkoviny:     numOrNull('sur-n-bilk'),
    nutri_sul:           numOrNull('sur-n-sul'),
    stock_minimalni:     numOrNull('sur-stock-min'),
    stock_cilove:        numOrNull('sur-stock-cil'),
    poznamka: document.getElementById('sur-pozn').value.trim() || null,
    aktivni: document.getElementById('sur-akt').checked ? 1 : 0,
    domovsky_sklad_id: parseInt(document.getElementById('sur-home-sklad')?.value) || null,
  };
  if (!data.nazev) return alert('Vyplňte název');
  // Zachyť flag PŘED closeModal/navigate (aby se neztratil)
  const returnToKalkulace = !!state._sur_return_to_kalkulace;
  state._sur_return_to_kalkulace = false;
  try {
    await api('admin_suroviny.php', { method: id ? 'PUT' : 'POST', body: JSON.stringify(data) });
    closeModal();
    state._suroviny_cache = null; state._suroviny_full_cache = null;
    // Pokud jsi přišel z kalkulace (klik na „🛒 doplnit cenu →"), nech tě tam
    if (returnToKalkulace) {
      try { await loadSurovinyCache(); } catch (e) {}
      if (typeof vkRender === 'function') vkRender();
      const t = document.createElement('div');
      t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:12px 18px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.15);font-size:13px;font-weight:600;z-index:9999';
      t.textContent = '✓ Cena suroviny uložena — kalkulace přepočítána';
      document.body.appendChild(t);
      setTimeout(() => t.remove(), 2200);
      return;
    }
    navigate('suroviny');
  } catch (e) {
    alert('Chyba: ' + e.message);
    // Při chybě obnov flag aby další pokus o uložení respektoval návrat
    state._sur_return_to_kalkulace = returnToKalkulace;
  }
};

window.smazatSurovinu = async function(id) {
  if (!await confirmDelete2x({ co: 'tuto surovinu', detail: 'Pokud je v některém receptu (složení výrobku), surovina se jen deaktivuje (recepty zůstanou nedotčené).' })) return;
  try {
    const res = await api(`admin_suroviny.php?id=${id}`, { method: 'DELETE' });
    if (res.deactivated) alert(t('ingredient_in_use_deactivated', { n: res.pouzita_v_vyrobcich }));
    closeModal();
    state._suroviny_cache = null; state._suroviny_full_cache = null;
    navigate('suroviny');
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.seedSuroviny = async function() {
  if (!(await confirmDialog({ msg: 'Naplnit databázi sadou ~70 výchozích surovin? (Existující se nepřepíší.)', danger: true }))) return;
  try {
    const res = await api('admin_suroviny.php?action=seed', { method: 'POST' });
    // Invalidate cache aby seed byl vidět hned
    state._suroviny_cache = null;
    state._suroviny_full_cache = null;
    alert(res.message ? res.message : `Vloženo ${res.inserted} surovin.`);
    if (state.current === 'suroviny') renderSuroviny();
  } catch (e) { alert('Chyba: ' + e.message); }
};

