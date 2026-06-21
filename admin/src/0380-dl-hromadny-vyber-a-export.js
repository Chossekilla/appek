// =============================================================
// DL hromadný výběr a export
// =============================================================
window.dlToggleSelect = function(id, checked) {
  if (!state._dlSelected) state._dlSelected = new Set();
  if (checked) state._dlSelected.add(id); else state._dlSelected.delete(id);
  document.querySelectorAll(`[data-dl-check="${id}"]`).forEach(el => { el.checked = checked; });
  const tr = document.querySelector(`input[data-dl-check="${id}"]`)?.closest('tr');
  if (tr) tr.classList.toggle('is-selected', checked);
  updateDlBulkBar();
};
window.dlSelectAll = function(checked) {
  if (!state._dlSelected) state._dlSelected = new Set();
  const list = state._dlList || [];
  if (checked) list.forEach(d => state._dlSelected.add(d.id));
  else state._dlSelected.clear();
  document.querySelectorAll('[data-dl-check]').forEach(el => {
    el.checked = checked;
    const tr = el.closest('tr'); if (tr) tr.classList.toggle('is-selected', checked);
  });
  updateDlBulkBar();
};
window.dlClearSelection = function() {
  if (state._dlSelected) state._dlSelected.clear();
  document.querySelectorAll('[data-dl-check]').forEach(el => {
    el.checked = false;
    const tr = el.closest('tr'); if (tr) tr.classList.remove('is-selected');
  });
  const allCheck = document.getElementById('dl-check-all');
  if (allCheck) allCheck.checked = false;
  updateDlBulkBar();
};
function updateDlBulkBar() {
  const bar = document.getElementById('dl-bulk-bar');
  const cnt = document.getElementById('dl-bulk-count');
  const all = document.getElementById('dl-check-all');
  const n = state._dlSelected ? state._dlSelected.size : 0;
  if (bar) bar.style.display = n > 0 ? 'flex' : 'none';
  document.body.classList.toggle('has-bulk-bar', n > 0);
  if (cnt) cnt.textContent = n;
  if (all) {
    const total = (state._dlList || []).length;
    all.checked = total > 0 && n === total;
    all.indeterminate = n > 0 && n < total;
  }
}
window.dlBulkVystavitFakturu = async function() {
  const ids = [...(state._dlSelected || [])];
  if (ids.length === 0) return alert('Nejprve vyber dodací listy.');

  const list = state._dlList || [];
  const vybrane = ids.map(id => list.find(x => x.id === id)).filter(Boolean);

  // Validace 1: stejný odběratel
  const odberatelIds = [...new Set(vybrane.map(d => d.odberatel_id))];
  if (odberatelIds.length > 1) {
    const nazvy = [...new Set(vybrane.map(d => d.odberatel_nazev))];
    return alert(t('dl_same_customer_required', { n: odberatelIds.length, nazvy: nazvy.join(', ') }));
  }

  // Validace 2: nejsou fakturované
  const uzFakturovane = vybrane.filter(d => d.fakturovano);
  if (uzFakturovane.length > 0) {
    return alert(t('dl_already_invoiced', { seznam: uzFakturovane.map(d => d.cislo).join(', ') }));
  }

  const odberatel = vybrane[0]?.odberatel_nazev || 'odběratele';
  const celkemCastka = vybrane.reduce((s, d) => s + parseFloat(d.castka_celkem || 0), 0);
  if (!(await confirmDialog({ msg: `Vystavit JEDNU fakturu z ${ids.length} dodacích listů?\n\n` +
    `Odběratel: ${odberatel}\n` +
    `Celkem: ${fmt(celkemCastka)}\n\n` +
    `DL čísla: ${vybrane.map(d => d.cislo).join(', ')}`, danger: false }))) return;

  try {
    const res = await api('admin_faktura_z_dl.php', {
      method: 'POST',
      body: { dl_ids: ids },
    });
    alert(t('invoice_issued_with_dl', { cislo: res.cislo, pocet: res.pocet_dl, castka: fmt(res.castka_celkem) }));
    dlClearSelection();
    navigate('faktury');
    setTimeout(() => openFakturaDetail(res.faktura_id), 200);
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

window.dlBulkOdeslatEmailem = function() {
  const ids = [...(state._dlSelected || [])];
  if (ids.length === 0) return alert('Nejprve vyber dodací listy.');
  const list = state._dlList || [];
  const vybrane = ids.map(id => list.find(x => x.id === id)).filter(Boolean);
  const bezEmailu = vybrane.filter(d => !d.odberatel_email).length;
  const radky = vybrane.map(d => `
    <div style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px solid var(--border);font-size:13px">
      <span><strong>${esc(d.cislo)}</strong> · ${esc(d.odberatel_nazev || '—')}</span>
      <span style="color:${d.odberatel_email ? 'var(--text-2)' : 'var(--danger-text)'}">${d.odberatel_email ? esc(d.odberatel_email) : '⚠️ chybí e-mail'}</span>
    </div>`).join('');
  openModal('✉️ Odeslat dodací listy e-mailem', `
    <div style="background:var(--surface-2);border-radius:8px;padding:12px 14px;margin-bottom:12px;font-size:13px;color:var(--text-2)">
      Každý dodací list se odešle <strong>svému odběrateli</strong> (PDF v příloze).
      ${bezEmailu > 0 ? `<div style="color:var(--danger-text);margin-top:6px">⚠️ ${bezEmailu}× chybí e-mail odběratele — ty se přeskočí.</div>` : ''}
    </div>
    <div style="max-height:240px;overflow:auto;margin-bottom:14px">${radky}</div>
    <div class="form-row">
      <label class="form-label" for="dlbe-zprava">💬 Vlastní zpráva (volitelné — připojí se ke všem)</label>
      <textarea id="dlbe-zprava" class="form-input" rows="3" placeholder="Dobrý den, posíláme přiložený dodací list..." style="font-size:14px;resize:vertical"></textarea>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="dlBulkOdeslatEmailemProvest()">✉️ Odeslat vše</button>
    </div>
  `);
};

window.dlBulkOdeslatEmailemProvest = async function() {
  const zprava = (document.getElementById('dlbe-zprava')?.value || '').trim();
  const ids = [...(state._dlSelected || [])];
  const list = state._dlList || [];
  const vybrane = ids.map(id => list.find(x => x.id === id)).filter(Boolean).filter(d => d.odberatel_email);
  if (vybrane.length === 0) return alert('Žádný z vybraných DL nemá e-mail odběratele.');
  const btn = document.querySelector('.modal-card .btn-green');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Odesílám…'; }
  let ok = 0, chyby = 0;
  for (const d of vybrane) {
    try {
      await api('admin_doklad_email.php', {
        method: 'POST',
        body: { typ: 'dl', id: d.id, emails: [d.odberatel_email], predmet: '', zprava },
      });
      ok++;
    } catch (e) { chyby++; }
  }
  alert(t('confirm_send_finished', { ok, chyby_text: chyby > 0 ? `\n✗ Chyby: ${chyby}` : '' }));
  closeModal();
};

// 🆕 v3.0.179 — FA hromadný e-mail (mirror DL; FA bulk bar dřív email neměl)
window.faBulkOdeslatEmailem = function() {
  const ids = [...(state._faSelected || [])];
  if (ids.length === 0) return alert('Nejprve vyber faktury.');
  const list = state._faList || [];
  const vybrane = ids.map(id => list.find(x => x.id === id)).filter(Boolean);
  const bezEmailu = vybrane.filter(f => !f.odberatel_email).length;
  const radky = vybrane.map(f => `
    <div style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px solid var(--border);font-size:13px">
      <span><strong>${esc(f.cislo)}</strong> · ${esc(f.odberatel_nazev || '—')}</span>
      <span style="color:${f.odberatel_email ? 'var(--text-2)' : 'var(--danger-text)'}">${f.odberatel_email ? esc(f.odberatel_email) : '⚠️ chybí e-mail'}</span>
    </div>`).join('');
  openModal('✉️ Odeslat faktury e-mailem', `
    <div style="background:var(--surface-2);border-radius:8px;padding:12px 14px;margin-bottom:12px;font-size:13px;color:var(--text-2)">
      Každá faktura se odešle <strong>svému odběrateli</strong> (PDF v příloze).
      ${bezEmailu > 0 ? `<div style="color:var(--danger-text);margin-top:6px">⚠️ ${bezEmailu}× chybí e-mail odběratele — ty se přeskočí.</div>` : ''}
    </div>
    <div style="max-height:240px;overflow:auto;margin-bottom:14px">${radky}</div>
    <div class="form-row">
      <label class="form-label" for="fabe-zprava">💬 Vlastní zpráva (volitelné — připojí se ke všem)</label>
      <textarea id="fabe-zprava" class="form-input" rows="3" placeholder="Dobrý den, posíláme přiloženou fakturu..." style="font-size:14px;resize:vertical"></textarea>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="faBulkOdeslatEmailemProvest()">✉️ Odeslat vše</button>
    </div>
  `);
};

window.faBulkOdeslatEmailemProvest = async function() {
  const zprava = (document.getElementById('fabe-zprava')?.value || '').trim();
  const ids = [...(state._faSelected || [])];
  const list = state._faList || [];
  const vybrane = ids.map(id => list.find(x => x.id === id)).filter(Boolean).filter(f => f.odberatel_email);
  if (vybrane.length === 0) return alert('Žádná z vybraných faktur nemá e-mail odběratele.');
  const btn = document.querySelector('.modal-card .btn-green');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Odesílám…'; }
  let ok = 0, chyby = 0;
  for (const f of vybrane) {
    try {
      await api('admin_doklad_email.php', {
        method: 'POST',
        body: { typ: 'fa', id: f.id, emails: [f.odberatel_email], predmet: '', zprava },
      });
      ok++;
    } catch (e) { chyby++; }
  }
  alert(t('confirm_send_finished', { ok, chyby_text: chyby > 0 ? `\n✗ Chyby: ${chyby}` : '' }));
  closeModal();
};

window.dlBulkTisk = function() {
  const ids = [...(state._dlSelected || [])];
  if (ids.length === 0) return;
  // Rozdělím na DL s objednávkou (přes ?ids) vs ruční DL (přes ?dl_ids).
  // Ruční DL — z dlPdfUrl známe rozdíl; ale tady máme jen ID — nahlížíme do _dlList.
  const list = state._dlList || [];
  const obj_ids = [];
  const dl_ids = [];
  ids.forEach(id => {
    const d = list.find(x => x.id === id);
    if (!d) return;
    if (d.objednavka_id) obj_ids.push(d.objednavka_id);
    else dl_ids.push(d.id);
  });
  // Backend dodaci_list.php podporuje oba ?ids= i ?dl_ids= naráz
  const params = [];
  if (obj_ids.length) params.push('ids=' + obj_ids.join(','));
  if (dl_ids.length) params.push('dl_ids=' + dl_ids.join(','));
  params.push('autoprint=1');
  window.open(`../api/dodaci_list.php?${params.join('&')}`, '_blank');
};

// Tisk z hlavičky DL — pokud nic vybráno, vytiskne všechny zobrazené
window.dlBulkTiskNahore = async function() {
  let ids = [...(state._dlSelected || [])];
  const list = state._dlList || [];
  if (ids.length === 0) {
    if (list.length === 0) return alert('Žádné dodací listy k tisku.');
    if (list.length > 30) {
      if (!(await confirmDialog({ msg: t('confirm_print_all_dl_many', { n: list.length }), danger: false }))) return;
    } else if (!(await confirmDialog({ msg: t('confirm_print_all_dl', { n: list.length }), danger: false }))) return;
    ids = list.map(d => d.id);
  }
  // Rozdělím na DL z objednávek vs ruční
  const obj_ids = [];
  const dl_ids = [];
  ids.forEach(id => {
    const d = list.find(x => x.id === id);
    if (!d) return;
    if (d.objednavka_id) obj_ids.push(d.objednavka_id);
    else dl_ids.push(d.id);
  });
  const params = [];
  if (obj_ids.length) params.push('ids=' + obj_ids.join(','));
  if (dl_ids.length) params.push('dl_ids=' + dl_ids.join(','));
  params.push('autoprint=1');
  window.open(`../api/dodaci_list.php?${params.join('&')}`, '_blank');
};

// Tisk jednoho DL — otevře PDF s autoprint=1
window.tiskDodaciList = function(dl) {
  const url = dl.objednavka_id
    ? `../api/dodaci_list.php?id=${dl.objednavka_id}&autoprint=1`
    : `../api/dodaci_list.php?dl_id=${dl.id}&autoprint=1`;
  window.open(url, '_blank');
};

window.dlBulkExportCsvZip = async function() {
  const ids = [...(state._dlSelected || [])];
  if (ids.length === 0) return;
  try {
    const res = await fetch('../api/admin_export_dl.php?action=csv-zip', {
      method: 'POST',
      headers: csrfHeaders({ 'Content-Type': 'application/json' }),
      credentials: 'include',
      body: JSON.stringify({ ids }),
    });
    if (!res.ok) throw new Error(await res.text());
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `DL_csv_${new Date().toISOString().slice(0, 10).replace(/-/g, '')}.zip`;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
    dlClearSelection();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.otevritExportDl = function() {
  const dnes = new Date();
  const od = new Date(dnes.getFullYear(), dnes.getMonth(), 1).toISOString().slice(0, 10);
  const dto = new Date(dnes.getFullYear(), dnes.getMonth() + 1, 0).toISOString().slice(0, 10);
  openModal('📤 Export dodacích listů', `
    <p style="font-size:14px;color:var(--text-2);margin-bottom:16px">
      Export do <strong>CSV</strong> — formát čitelný v Excelu, importovatelný do Money S3, Pohoda i jiných systémů.
    </p>

    <div class="card-block" style="padding:14px 16px;margin-bottom:14px;background:#F7F8FA;border:1px solid #E8D5B0;border-radius:10px">
      <h3 style="margin:0 0 8px;font-size:14px">📅 Období</h3>
      <div class="form-grid form-grid-tight">
        <div>
          <label class="form-label">Od</label>
          <input class="form-input" type="date" id="dlexp-od" value="${od}">
        </div>
        <div>
          <label class="form-label">Do</label>
          <input class="form-input" type="date" id="dlexp-do" value="${dto}">
        </div>
      </div>
    </div>

    <div class="card-block" style="padding:14px 16px;margin-bottom:10px">
      <h3 style="margin:0 0 6px;font-size:14px">📊 CSV s položkami</h3>
      <small style="color:var(--text-3);font-size:12px;display:block;margin-bottom:10px">
        Jeden řádek = jedna položka. Sloupce: DL číslo, datum, odběratel, výrobek, množství, ceny, DPH. <strong>Doporučeno pro účetnictví.</strong>
      </small>
      <button class="btn-primary btn-green" onclick="exportDlCsv('csv')">📊 Stáhnout CSV s položkami</button>
    </div>

    <div class="card-block" style="padding:14px 16px;margin-bottom:14px">
      <h3 style="margin:0 0 6px;font-size:14px">📋 Souhrn (jen hlavičky DL)</h3>
      <small style="color:var(--text-3);font-size:12px;display:block;margin-bottom:10px">
        Jeden řádek = jeden DL. Bez položek, jen číslo, datum, odběratel, počet a celková částka.
      </small>
      <button class="btn-secondary" onclick="exportDlCsv('csv-souhrn')">📋 Stáhnout souhrnné CSV</button>
    </div>

    <div class="form-actions">
      <div style="flex:1"></div>
      <!-- "Zavřít" smazáno v v2.5.11 — × v rohu modal-card stačí (větší klikací plocha) -->
    </div>
  `);
};

window.exportDlCsv = function(action) {
  const od = document.getElementById('dlexp-od').value;
  const dto = document.getElementById('dlexp-do').value;
  if (!od || !dto) return alert('Vyplňte období');
  window.location.href = `../api/admin_export_dl.php?action=${encodeURIComponent(action)}&od=${encodeURIComponent(od)}&do=${encodeURIComponent(dto)}`;
};

// ═══════════════════════════════════════════════════════════
// 📤 EXPORT KATALOGU VÝROBKŮ — XML / CSV / JSON / Heureka / Zboží
// (Nastavení → Přístupy & ceny → Export katalogu)
//
// Použijeme fetch() → blob → download anchor. Tato cesta:
//  - korektně zachytí HTTP chyby (server vrátí JSON s detailem)
//  - vynutí stažení i tam, kde Content-Disposition selže
//  - posílá session cookies (credentials: 'include')
// ═══════════════════════════════════════════════════════════
window.exportVyrobku = async function(format) {
  const url = `${API}/admin_vyrobky_export.php?format=${encodeURIComponent(format)}`;
  return _stahniSoubor(url, format);
};

window.exportVyrobkuHeureka = async function() {
  const url = `${API}/admin_vyrobky_export.php?format=heureka`;
  return _stahniSoubor(url, 'heureka');
};

// Helper — stáhne soubor pomocí fetch + blob, s detailem chyby pokud něco selže.
async function _stahniSoubor(url, format) {
  try {
    const res = await fetch(url, { credentials: 'include' });
    if (!res.ok) {
      // Zkus parsovat JSON chybu ze serveru (max 5 KB)
      let detail = `HTTP ${res.status}`;
      try {
        const errText = await res.text();
        try {
          const err = JSON.parse(errText);
          detail = err.error || err.detail || detail;
          if (err.hint) detail += '\n\n💡 ' + err.hint;
        } catch { detail = errText.slice(0, 500) || detail; }
      } catch {}
      throw new Error(detail);
    }
    const blob = await res.blob();

    // Pokus se zjistit filename z Content-Disposition hlavičky
    const cd = res.headers.get('content-disposition') || '';
    const m = cd.match(/filename="?([^";]+)"?/);
    const today = new Date().toISOString().slice(0, 10);
    const filename = (m && m[1])
      ? m[1]
      : `vyrobky-${today}.${format === 'heureka' || format === 'zbozi' ? 'xml' : (format === 'json' ? 'json' : format)}`;

    // Vytvoř dočasný download anchor
    const objUrl = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = objUrl;
    a.download = filename;
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    setTimeout(() => {
      URL.revokeObjectURL(objUrl);
      a.remove();
    }, 1000);
  } catch (e) {
    alert('❌ Export selhal:\n\n' + (e.message || e) + '\n\nMůžeš zkusit otevřít Nastavení → 🩺 Diagnostika pro detail.');
  }
}

