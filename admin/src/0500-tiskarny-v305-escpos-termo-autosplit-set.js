// =============================================================
// 🖨️ TISKÁRNY (v3.0.5) — ESC/POS termo + auto-split + settings
// =============================================================
async function loadTiskarnyPanel() {
  const panel = document.getElementById('ns-tiskarny-panel');
  if (!panel) return;
  panel.innerHTML = '⏳ Načítám…';
  try {
    const [list, settings] = await Promise.all([
      api('admin_printers.php?action=list'),
      api('admin_printers.php?action=settings'),
    ]);
    const printers = list.printers || [];
    const stanice = list.stanice || []; // 🆕 v3.0.200 — mapování stanice → tiskárna (nahradilo kategorie)
    const TYP_LABEL = {
      kasa: '🧾 Kasa (účtenka)',
      kuchyne: '👨‍🍳 Kuchyně (bon)',
      bar: '🍹 Bar (drinky)',
      sklad: '📦 Sklad (balení)',
      vydej: '📤 Výdej (pickup)',
      generic: '🖨️ Obecná',
    };
    const dummyOn = String(settings.printer_dummy_mode) === '1';

    panel.innerHTML = `
      <!-- POS settings -->
      <div class="card-block" style="padding:18px;margin-bottom:14px">
        <h3 style="margin:0 0 12px;font-size:16px">⚙️ POS chování při tisku</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px">
          <label style="display:block">
            <div style="font-size:12px;font-weight:700;margin-bottom:4px;color:var(--text-3)">Tisk účtenky po platbě</div>
            <select id="ts-print-receipt" class="input" style="width:100%">
              <option value="ask"    ${settings.pos_print_receipt_mode === 'ask' ? 'selected' : ''}>Zeptat se (Ano/Ne dialog)</option>
              <option value="always" ${settings.pos_print_receipt_mode === 'always' ? 'selected' : ''}>Vždy tisknout (tichý tisk)</option>
              <option value="never"  ${settings.pos_print_receipt_mode === 'never' ? 'selected' : ''}>Nikdy tisknout</option>
            </select>
          </label>
          <label style="display:block">
            <div style="font-size:12px;font-weight:700;margin-bottom:4px;color:var(--text-3)">Auto-split bonů na kuchyň/bar</div>
            <select id="ts-print-kitchen" class="input" style="width:100%">
              <option value="auto"   ${settings.pos_print_kitchen_mode === 'auto' ? 'selected' : ''}>Auto (rozeslat po finish)</option>
              <option value="manual" ${settings.pos_print_kitchen_mode === 'manual' ? 'selected' : ''}>Manuální (jen na klik)</option>
              <option value="off"    ${settings.pos_print_kitchen_mode === 'off' ? 'selected' : ''}>Vypnout bonu</option>
            </select>
          </label>
          <label style="display:flex;align-items:center;gap:10px;background:${dummyOn ? '#FEF3C7' : '#F0FDF4'};padding:10px 14px;border-radius:10px;border:1px solid ${dummyOn ? '#FCD34D' : '#86EFAC'};cursor:pointer">
            <input type="checkbox" id="ts-dummy" ${dummyOn ? 'checked' : ''} style="width:18px;height:18px;cursor:pointer">
            <div>
              <div style="font-size:13px;font-weight:700;color:${dummyOn ? '#92400E' : '#15803D'}">${dummyOn ? '🧪 Dummy mode (test)' : '✓ Reálný tisk přes IP'}</div>
              <div style="font-size:11px;color:var(--text-3);margin-top:2px">${dummyOn ? 'Tisk → /tmp/appek_printer_dummy/' : 'Tisk → TCP socket na :9100'}</div>
            </div>
          </label>
        </div>
      </div>

      <!-- Seznam tiskáren -->
      <div class="card-block" style="padding:18px;margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
          <h3 style="margin:0;font-size:16px">📋 Tiskárny (${printers.length})</h3>
          <button class="btn-primary" onclick="tiskarnaEdit(null)">➕ Přidat tiskárnu</button>
        </div>
        ${printers.length === 0 ? `
          <div style="padding:30px 20px;text-align:center;background:#FAFAFA;border-radius:10px;color:var(--text-3)">
            <div style="font-size:48px;margin-bottom:8px">🖨️</div>
            <div style="font-size:15px;font-weight:700;margin-bottom:4px">Žádné tiskárny</div>
            <div style="font-size:13px">Klikni "Přidat" — můžeš začít s dummy tiskárnou pro test.</div>
          </div>
        ` : `
          <div style="display:grid;gap:10px">
            ${printers.map(p => `
              <div style="display:grid;grid-template-columns:auto 1fr auto auto;gap:14px;align-items:center;padding:12px 16px;background:#FAFAFA;border:1px solid var(--border);border-radius:10px;${!p.aktivni ? 'opacity:0.5' : ''}">
                <div style="font-size:28px">${(TYP_LABEL[p.typ] || '🖨️').split(' ')[0]}</div>
                <div>
                  <div style="font-weight:700;font-size:14px">${esc(p.nazev)}</div>
                  <div style="font-size:12px;color:var(--text-3);margin-top:2px">${esc(TYP_LABEL[p.typ] || p.typ)} · <code>${esc(p.ip)}:${p.port}</code> · ${p.sirka_papiru}mm · ${p.pocet_tisku}× tištěno${p.pocet_stanic > 0 ? ` · 🍳 ${p.pocet_stanic} ${p.pocet_stanic === 1 ? 'stanice' : (p.pocet_stanic < 5 ? 'stanice' : 'stanic')}` : ''}</div>
                  ${p.posledni_chyba ? `<div style="font-size:11px;color:#DC2626;margin-top:4px;background:#FEE2E2;padding:4px 8px;border-radius:4px">⚠️ ${esc(p.posledni_chyba)}</div>` : ''}
                </div>
                <button class="btn-secondary" onclick="tiskarnaTest(${p.id})" title="Testovací tisk">🧪 Test</button>
                <div style="display:flex;gap:6px">
                  <button class="btn-secondary" onclick="tiskarnaEdit(${p.id})">✏️</button>
                  <button class="btn-secondary" onclick="tiskarnaDelete(${p.id}, '${esc(p.nazev).replace(/'/g, '&#39;')}')">🗑️</button>
                </div>
              </div>
            `).join('')}
          </div>
        `}
      </div>

      <!-- 🆕 v3.0.200 — Párování stanic → tiskárna (nahradilo mapování kategorií) -->
      ${stanice.length > 0 ? `
        <div class="card-block" style="padding:18px;margin-bottom:14px">
          <h3 style="margin:0 0 6px;font-size:16px">🗺️ Párování: kuchyňská stanice → tiskárna</h3>
          <p style="font-size:12px;color:var(--text-3);margin:0 0 14px">
            Bon se vytiskne na tiskárnu té stanice, ke které výrobek patří (stanici nastavíš u výrobku v <strong>Výrobky → Doba přípravy + stanice</strong>). Stanice bez tiskárny = bon se nikam neposílá.
          </p>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px">
            ${stanice.map(s => `
              <label style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:#FAFAFA;border:1px solid var(--border);border-left:4px solid ${esc(s.barva || '#F59E0B')};border-radius:10px">
                <span style="font-size:20px">${esc(s.ikona || '🔥')}</span>
                <span style="flex:1;font-weight:600;font-size:13px">${esc(s.nazev)}</span>
                <select class="input" onchange="staniceMap(${s.id}, this.value)" style="flex:0 0 150px;font-size:12px">
                  <option value="">— bez tisku —</option>
                  ${printers.map(p => `<option value="${p.id}" ${String(s.printer_id) === String(p.id) ? 'selected' : ''}>${esc(p.nazev)}</option>`).join('')}
                </select>
              </label>
            `).join('')}
          </div>
        </div>
      ` : `
        <div class="card-block" style="padding:16px;margin-bottom:14px;background:#FFFBEB;border-color:#FCD34D">
          <p style="margin:0;font-size:13px;color:#92400E">💡 Žádné kuchyňské stanice k párování. Vytvoř je v <strong>Nastavení → 🍕 Restaurace → Kapacita kuchyně</strong>.</p>
        </div>
      `}

      ${dummyOn ? `
        <!-- Dummy mode: poslední testovací soubory -->
        <div class="card-block" style="padding:18px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
            <h3 style="margin:0;font-size:16px">🧪 Dummy tisky (poslední soubory)</h3>
            <button class="btn-secondary" onclick="loadTiskarnyPanel()">🔄 Refresh</button>
          </div>
          <div id="ts-dummy-files" style="font-size:12px;color:var(--text-3)">⏳ Načítám…</div>
        </div>
      ` : ''}
    `;

    // On-change handlers pro POS settings
    document.getElementById('ts-print-receipt').onchange = e => savePosPrintSetting('pos_print_receipt_mode', e.target.value);
    document.getElementById('ts-print-kitchen').onchange = e => savePosPrintSetting('pos_print_kitchen_mode', e.target.value);
    document.getElementById('ts-dummy').onchange = e => {
      savePosPrintSetting('printer_dummy_mode', e.target.checked ? '1' : '0').then(() => loadTiskarnyPanel());
    };

    if (dummyOn) loadDummyFiles();
  } catch (e) {
    panel.innerHTML = `<div style="padding:30px;color:#DC2626;text-align:center">❌ Chyba: ${esc(e.message)}</div>`;
  }
}

async function savePosPrintSetting(key, value) {
  try {
    await api('admin_printers.php?action=settings', {
      method: 'POST', body: JSON.stringify({ [key]: value })
    });
    toast('✓ Uloženo', 'success');
  } catch (e) {
    toast('Chyba: ' + e.message, 'error');
  }
}

async function loadDummyFiles() {
  const c = document.getElementById('ts-dummy-files');
  if (!c) return;
  try {
    const r = await api('admin_printers.php?action=dummy_files');
    const files = r.files || [];
    if (!files.length) {
      c.innerHTML = '<div style="padding:20px;text-align:center;opacity:0.6">Žádné dummy tisky. Zkus tlačítko "🧪 Test" u tiskárny.</div>';
      return;
    }
    c.innerHTML = `
      <div style="font-size:11px;margin-bottom:8px;opacity:0.7">📁 ${esc(r.dir)}</div>
      <div style="display:grid;gap:6px;max-height:300px;overflow-y:auto">
        ${files.map(f => `
          <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:#fff;border:1px solid var(--border);border-radius:6px;font-family:monospace;font-size:11px">
            <span>${esc(f.name)} <span style="opacity:0.5">(${f.size}B · ${esc(f.mtime)})</span></span>
            <button class="btn-secondary" style="padding:4px 10px;font-size:11px" onclick="window.open('../api/admin_printers.php?action=dummy_file&name=${encodeURIComponent(f.name)}', '_blank')">👁️ Preview</button>
          </div>
        `).join('')}
      </div>
    `;
  } catch (e) {
    c.innerHTML = `<div style="color:#DC2626">Chyba: ${esc(e.message)}</div>`;
  }
}

window.tiskarnaTest = async function(id) {
  toast('⏳ Testovací tisk…', 'info');
  try {
    const r = await api('admin_printers.php?action=test', {
      method: 'POST', body: JSON.stringify({ id })
    });
    if (r.ok) {
      if (r.dummy) toast(t('toast_dummy_print', { file: r.file.split('/').pop() }), 'success');
      else        toast(t('toast_printed_bytes', { bytes: r.bytes }), 'success');
      loadTiskarnyPanel();
    } else {
      toast('❌ ' + (r.error || 'Neznámá chyba'), 'error');
    }
  } catch (e) { toast('Chyba: ' + e.message, 'error'); }
};

window.tiskarnaDelete = async function(id, nazev) {
  if (!(await confirmDialog({ msg: t('confirm_delete_printer', { nazev }), danger: false }))) return;
  try {
    await api('admin_printers.php?action=delete', { method: 'POST', body: JSON.stringify({ id }) });
    toast('✓ Smazáno', 'success');
    loadTiskarnyPanel();
  } catch (e) { toast('Chyba: ' + e.message, 'error'); }
};

// 🆕 v3.0.200 — párování tiskárny na kuchyňskou stanici (nahradilo tiskarnaMap pro kategorie)
window.staniceMap = async function(stationId, printerId) {
  try {
    await api('admin_printers.php?action=map_station', {
      method: 'POST', body: JSON.stringify({ station_id: stationId, printer_id: printerId ? parseInt(printerId) : null })
    });
    toast('✓ Tiskárna přiřazena ke stanici', 'success');
  } catch (e) { toast('Chyba: ' + e.message, 'error'); }
};

window.tiskarnaEdit = async function(id) {
  let p = { id: null, nazev: '', typ: 'kasa', ip: '192.168.1.100', port: 9100, sirka_papiru: 80, encoding: 'cp852', aktivni: 1, poznamka: '' };
  if (id) {
    try {
      const r = await api('admin_printers.php?action=list');
      const found = (r.printers || []).find(x => x.id === id);
      if (found) p = found;
    } catch (e) { return toast('Chyba: ' + e.message, 'error'); }
  }
  openModal(id ? '✏️ Upravit tiskárnu' : '➕ Nová tiskárna', `
    <form id="tiskarna-form" style="display:grid;gap:12px;padding:8px 4px">
      <label>
        <div style="font-size:12px;font-weight:700;margin-bottom:4px">Název *</div>
        <input class="input" name="nazev" value="${esc(p.nazev)}" required placeholder="Kasa u baru" style="width:100%">
      </label>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <label>
          <div style="font-size:12px;font-weight:700;margin-bottom:4px">Typ *</div>
          <select class="input" name="typ" style="width:100%">
            <option value="kasa"    ${p.typ === 'kasa' ? 'selected' : ''}>🧾 Kasa (účtenka)</option>
            <option value="kuchyne" ${p.typ === 'kuchyne' ? 'selected' : ''}>👨‍🍳 Kuchyně (bon)</option>
            <option value="bar"     ${p.typ === 'bar' ? 'selected' : ''}>🍹 Bar (drinky)</option>
            <option value="sklad"   ${p.typ === 'sklad' ? 'selected' : ''}>📦 Sklad (balení)</option>
            <option value="vydej"   ${p.typ === 'vydej' ? 'selected' : ''}>📤 Výdej (pickup)</option>
            <option value="generic" ${p.typ === 'generic' ? 'selected' : ''}>🖨️ Obecná</option>
          </select>
        </label>
        <label>
          <div style="font-size:12px;font-weight:700;margin-bottom:4px">Šířka papíru</div>
          <select class="input" name="sirka_papiru" style="width:100%">
            <option value="80" ${p.sirka_papiru == 80 ? 'selected' : ''}>80 mm (standard)</option>
            <option value="58" ${p.sirka_papiru == 58 ? 'selected' : ''}>58 mm (mini)</option>
          </select>
        </label>
      </div>
      <div style="display:grid;grid-template-columns:2fr 1fr;gap:10px">
        <label>
          <div style="font-size:12px;font-weight:700;margin-bottom:4px">IP adresa nebo hostname *</div>
          <input class="input" name="ip" value="${esc(p.ip)}" required placeholder="192.168.1.100" style="width:100%;font-family:monospace">
        </label>
        <label>
          <div style="font-size:12px;font-weight:700;margin-bottom:4px">Port</div>
          <input class="input" name="port" type="number" value="${p.port || 9100}" min="1" max="65535" style="width:100%;font-family:monospace">
        </label>
      </div>
      <label>
        <div style="font-size:12px;font-weight:700;margin-bottom:4px">Encoding (diakritika)</div>
        <select class="input" name="encoding" style="width:100%">
          <option value="cp852"  ${p.encoding === 'cp852' ? 'selected' : ''}>cp852 (středoevropský, doporučeno)</option>
          <option value="cp1250" ${p.encoding === 'cp1250' ? 'selected' : ''}>cp1250 (Windows CE)</option>
          <option value="utf-8"  ${p.encoding === 'utf-8' ? 'selected' : ''}>UTF-8 (jen pokud tiskárna podporuje)</option>
          <option value="ascii"  ${p.encoding === 'ascii' ? 'selected' : ''}>ASCII (bez diakritiky)</option>
        </select>
      </label>
      <label>
        <div style="font-size:12px;font-weight:700;margin-bottom:4px">Poznámka</div>
        <input class="input" name="poznamka" value="${esc(p.poznamka || '')}" placeholder="Epson TM-T20III, sériové č. ..." style="width:100%">
      </label>
      <label style="display:flex;align-items:center;gap:8px;padding:10px;background:#F0FDF4;border-radius:8px">
        <input type="checkbox" name="aktivni" ${p.aktivni ? 'checked' : ''} style="width:18px;height:18px">
        <span style="font-weight:600">Tiskárna aktivní (přijímá tisky)</span>
      </label>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">
        <button type="button" class="btn-secondary" onclick="closeModal()">Zrušit</button>
        <button type="submit" class="btn-primary">💾 Uložit</button>
      </div>
    </form>
  `);
  document.getElementById('tiskarna-form').onsubmit = async (ev) => {
    ev.preventDefault();
    const f = ev.target;
    const data = {
      id: p.id,
      nazev: f.nazev.value,
      typ: f.typ.value,
      ip: f.ip.value,
      port: parseInt(f.port.value) || 9100,
      sirka_papiru: parseInt(f.sirka_papiru.value),
      encoding: f.encoding.value,
      aktivni: f.aktivni.checked ? 1 : 0,
      poznamka: f.poznamka.value,
    };
    try {
      await api('admin_printers.php?action=save', { method: 'POST', body: JSON.stringify(data) });
      toast('✓ Uloženo', 'success');
      closeModal();
      loadTiskarnyPanel();
    } catch (e) {
      toast('Chyba: ' + e.message, 'error');
    }
  };
};

