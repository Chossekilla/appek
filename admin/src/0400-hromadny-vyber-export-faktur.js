// =============================================================
// HROMADNÝ VÝBĚR + EXPORT FAKTUR
// =============================================================
window.faToggleSelect = function(id, checked) {
  if (!state._faSelected) state._faSelected = new Set();
  if (checked) state._faSelected.add(id); else state._faSelected.delete(id);
  document.querySelectorAll(`[data-fa-check="${id}"]`).forEach(el => { el.checked = checked; });
  const tr = document.querySelector(`input[data-fa-check="${id}"]`)?.closest('tr');
  if (tr) tr.classList.toggle('is-selected', checked);
  updateFaBulkBar();
};

window.faSelectAll = function(checked) {
  if (!state._faSelected) state._faSelected = new Set();
  const list = state._faList || [];
  if (checked) list.forEach(f => state._faSelected.add(f.id));
  else state._faSelected.clear();
  document.querySelectorAll('[data-fa-check]').forEach(el => {
    el.checked = checked;
    const tr = el.closest('tr'); if (tr) tr.classList.toggle('is-selected', checked);
  });
  updateFaBulkBar();
};

window.faClearSelection = function() {
  if (state._faSelected) state._faSelected.clear();
  document.querySelectorAll('[data-fa-check]').forEach(el => {
    el.checked = false;
    const tr = el.closest('tr'); if (tr) tr.classList.remove('is-selected');
  });
  const allCheck = document.getElementById('fa-check-all');
  if (allCheck) allCheck.checked = false;
  updateFaBulkBar();
};

function updateFaBulkBar() {
  const bar = document.getElementById('fa-bulk-bar');
  const cnt = document.getElementById('fa-bulk-count');
  const all = document.getElementById('fa-check-all');
  const n = state._faSelected ? state._faSelected.size : 0;
  if (bar) bar.style.display = n > 0 ? 'flex' : 'none';
  document.body.classList.toggle('has-bulk-bar', n > 0);
  if (cnt) cnt.textContent = n;
  if (all) {
    const total = (state._faList || []).length;
    all.checked = total > 0 && n === total;
    all.indeterminate = n > 0 && n < total;
  }
}

window.faBulkTisk = function() {
  const ids = [...(state._faSelected || [])];
  if (ids.length === 0) return;
  // Otevři jeden tab s autoprint=1 — všechny faktury za sebou s page-break mezi nimi
  window.open(`../api/faktura.php?ids=${ids.join(',')}&autoprint=1`, '_blank');
};

// Tisk z hlavičky — pokud nic vybráno, zeptá se a vytiskne všechny zobrazené
window.faBulkTiskNahore = async function() {
  let ids = [...(state._faSelected || [])];
  if (ids.length === 0) {
    const all = (state._faList || []).map(f => f.id);
    if (all.length === 0) return alert('Žádné faktury k tisku.');
    if (all.length > 30) {
      if (!(await confirmDialog({ msg: t('confirm_print_all_invoices_many', { n: all.length }), danger: false }))) return;
    } else if (!(await confirmDialog({ msg: t('confirm_print_all_invoices', { n: all.length }), danger: false }))) return;
    ids = all;
  }
  window.open(`../api/faktura.php?ids=${ids.join(',')}&autoprint=1`, '_blank');
};

window.faBulkExportIsdoc = async function() {
  const ids = [...(state._faSelected || [])];
  if (ids.length === 0) return;
  try {
    // Vytvoříme POST request a streamem stáhneme ZIP
    const res = await fetch('../api/admin_export_isdoc.php?action=zip', {
      method: 'POST',
      headers: csrfHeaders({ 'Content-Type': 'application/json' }),
      credentials: 'include',
      body: JSON.stringify({ ids }),
    });
    if (!res.ok) {
      const t = await res.text();
      throw new Error(t || 'HTTP ' + res.status);
    }
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `faktury_isdoc_${new Date().toISOString().slice(0, 10).replace(/-/g, '')}.zip`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
    faClearSelection();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.otevritExportFaktur = async function() {
  const dnes = new Date();
  const od = new Date(dnes.getFullYear(), dnes.getMonth(), 1).toISOString().slice(0, 10);
  const dto = new Date(dnes.getFullYear(), dnes.getMonth() + 1, 0).toISOString().slice(0, 10);

  // Načti uložený email (z nastavení)
  let savedEmail = '';
  try {
    const ns = await api('admin_nastaveni.php');
    savedEmail = ns.export_isdoc_email || '';
  } catch (e) {}

  openModal('📤 Export faktur', `
    <p style="font-size:14px;color:var(--text-2);margin-bottom:16px">
      Vyberte formát exportu. ISDOC je standardní český XML formát, který importují <strong>Money S3, Pohoda, Helios</strong> a další účetní systémy.
    </p>

    <div class="card-block" style="padding:16px;margin-bottom:14px;background:#F7F8FA;border:1px solid #E8D5B0;border-radius:10px">
      <h3 style="margin:0 0 10px;font-size:15px">📤 ISDOC ZIP — faktury za období</h3>
      <div class="form-grid form-grid-tight">
        <div>
          <label class="form-label">Od</label>
          <input class="form-input" type="date" id="exp-od" value="${od}">
        </div>
        <div>
          <label class="form-label">Do</label>
          <input class="form-input" type="date" id="exp-do" value="${dto}">
        </div>
      </div>

      <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
        <button class="btn-primary btn-green" onclick="exportFakturIsdocPeriod()">📦 Stáhnout ZIP</button>
      </div>

      <div style="margin-top:14px;padding-top:14px;border-top:1px dashed #E8D5B0">
        <h4 style="margin:0 0 8px;font-size:13px;color:#854F0B">📧 Poslat účetní na email</h4>
        <div style="display:flex;gap:6px;align-items:end;flex-wrap:wrap">
          <div style="flex:1;min-width:200px">
            <label class="form-label">Email účetní</label>
            <input class="form-input" type="email" id="exp-email" value="${esc(savedEmail)}" placeholder="ucetni@firma.cz">
          </div>
          <button class="btn-primary" onclick="exportFakturEmail()">📧 Poslat ZIP na email</button>
        </div>
        <small style="display:block;color:var(--text-3);margin-top:6px;font-size:11px">Email se uloží pro příště. Zpráva s ZIPem v příloze ISDOC souborů.</small>
      </div>

      <small style="display:block;color:var(--text-3);margin-top:8px;font-size:12px">Každá faktura jako samostatný .isdoc soubor v jednom ZIPu.</small>
    </div>

    <div class="card-block" style="padding:16px;margin-bottom:14px">
      <h3 style="margin:0 0 10px;font-size:15px">📊 CSV — flat seznam pro Excel</h3>
      <button class="btn-secondary" onclick="exportFakturCsv()">📊 Stáhnout CSV</button>
      <small style="display:block;color:var(--text-3);margin-top:6px;font-size:12px">Tabulka s číslem, datumem, odběratelem, DPH a částkami. Bez položek.</small>
    </div>

    <div class="form-actions">
      <div style="flex:1"></div>
      <!-- "Zavřít" smazáno v v2.5.11 — × v rohu modal-card stačí (větší klikací plocha) -->
    </div>
  `);
};

window.exportFakturEmail = async function() {
  const od = document.getElementById('exp-od').value;
  const dto = document.getElementById('exp-do').value;
  const email = (document.getElementById('exp-email').value || '').trim();
  if (!email || !email.includes('@')) return alert('Vyplň email');
  if (!od || !dto) return alert('Vyplň období');
  if (!(await confirmDialog({ msg: t('confirm_send_isdoc_zip', { email }), danger: false }))) return;
  try {
    const r = await api('admin_export_isdoc.php?action=email', {
      method: 'POST',
      body: JSON.stringify({ od, do: dto, email }),
    });
    closeModal();
    alert(t('isdoc_sent', { n: r.odeslano, kb: r.velikost_kb, email: r.email }));
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.exportFakturIsdocPeriod = async function() {
  const od = document.getElementById('exp-od').value;
  const dto = document.getElementById('exp-do').value;
  if (!od || !dto) return alert('Vyplňte období');
  try {
    // Načti IDs faktur za období
    const params = new URLSearchParams({ datum_od: od, datum_do: dto }).toString();
    const data = await api('admin_faktury.php?' + params);
    const ids = (data.faktury || []).map(f => f.id);
    if (ids.length === 0) return alert('V období nejsou žádné faktury');
    if (!(await confirmDialog({ msg: t('confirm_download_isdoc_zip', { n: ids.length }), danger: false }))) return;

    const res = await fetch('../api/admin_export_isdoc.php?action=zip', {
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
    a.download = `faktury_${od.replace(/-/g, '')}_${dto.replace(/-/g, '')}_isdoc.zip`;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
    closeModal();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.exportFakturCsv = function() {
  const od = document.getElementById('exp-od').value;
  const dto = document.getElementById('exp-do').value;
  if (!od || !dto) return alert('Vyplňte období');
  window.location.href = `../api/admin_export_isdoc.php?action=csv&od=${encodeURIComponent(od)}&do=${encodeURIComponent(dto)}`;
};

