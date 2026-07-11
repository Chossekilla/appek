// =====================================================================
// 🔒 v3.0.425 — GDPR modul (zásady zpracování os. údajů + práva subjektu)
// =====================================================================
// HTML karty jsou v 0490 (integrace blok): #ns-gdpr-zasady-block,
// #ns-gdpr-prava-block. Backend: api/admin_gdpr.php. Veřejné čtení
// (B2B): api/gdpr_verejne.php.

// ── Editor zásad ─────────────────────────────────────────────────────
window.gdprZasadyLoad = async function() {
  const ta = document.getElementById('ns-gdpr-zasady-text');
  if (!ta) return;
  try {
    const r = await api('admin_gdpr.php');
    ta.value = r.text || '';
    const info = document.getElementById('ns-gdpr-zasady-info');
    if (info) {
      info.textContent = r.is_default
        ? '⚪ Zatím neuloženo — zobrazuje se obecná šablona. Uprav a ulož.'
        : (r.updated ? '🟢 Uloženo ' + r.updated : '🟢 Uloženo');
    }
  } catch (e) {
    ta.placeholder = '❌ ' + (e.message || 'Načtení selhalo');
  }
};

window.gdprZasadyVlozitSablonu = async function() {
  const ta = document.getElementById('ns-gdpr-zasady-text');
  if (!ta) return;
  if (ta.value.trim() && !confirm('Přepsat aktuální text obecnou šablonou?')) return;
  try {
    const r = await api('admin_gdpr.php?action=template');
    ta.value = r.text || '';
    const info = document.getElementById('ns-gdpr-zasady-info');
    if (info) info.textContent = '📄 Šablona vložena — zkontroluj a ulož.';
  } catch (e) { alert('❌ ' + (e.message || 'Chyba')); }
};

window.gdprZasadyNahled = function() {
  const ta = document.getElementById('ns-gdpr-zasady-text');
  if (!ta) return;
  gdprModal('👁️ Náhled zásad', `<div style="font-size:13px;line-height:1.55">${ta.value || '<em>Prázdné</em>'}</div>`);
};

window.gdprZasadySave = async function() {
  const ta = document.getElementById('ns-gdpr-zasady-text');
  const out = document.getElementById('ns-gdpr-zasady-save');
  if (!ta) return;
  if (out) out.textContent = '⏳ Ukládám…';
  try {
    const r = await api('admin_gdpr.php?action=save', { method: 'POST', body: JSON.stringify({ text: ta.value }) });
    if (out) out.textContent = '✅ Uloženo ' + (r.updated || '');
    const info = document.getElementById('ns-gdpr-zasady-info');
    if (info) info.textContent = '🟢 Uloženo ' + (r.updated || '');
  } catch (e) {
    if (out) out.textContent = '❌ ' + (e.message || 'Uložení selhalo');
  }
};

// ── Práva subjektu (export / anonymizace) ────────────────────────────
window._gdprHledejTimer = null;
window.gdprPravaHledat = function() {
  clearTimeout(window._gdprHledejTimer);
  window._gdprHledejTimer = setTimeout(gdprPravaHledatNow, 300);
};

window.gdprPravaHledatNow = async function() {
  const q = (document.getElementById('ns-gdpr-hledej') || {}).value || '';
  const box = document.getElementById('ns-gdpr-vysledky');
  if (!box) return;
  if (q.trim().length < 2) { box.innerHTML = '<span style="color:var(--text-3)">Zadej alespoň 2 znaky…</span>'; return; }
  box.innerHTML = '⏳ Hledám…';
  try {
    const r = await api('admin_gdpr.php?action=customers&q=' + encodeURIComponent(q));
    const list = r.customers || [];
    if (!list.length) { box.innerHTML = '<span style="color:var(--text-3)">Nic nenalezeno.</span>'; return; }
    box.innerHTML = list.map(c => `
      <div style="display:flex;gap:8px;align-items:center;justify-content:space-between;border:1px solid var(--border);border-radius:8px;padding:8px 10px;margin-bottom:6px;flex-wrap:wrap">
        <div><strong>${esc(c.nazev || '—')}</strong> <span style="color:var(--text-3);font-size:12px">${esc(c.email || '')}</span></div>
        <div style="display:flex;gap:6px">
          <button class="btn-secondary" onclick="gdprExport(${c.id})">📤 Export údajů</button>
          <button class="btn-secondary" style="color:var(--danger-text,#B91C1C)" onclick="gdprAnonymizovat(${c.id}, ${JSON.stringify(c.nazev || '').replace(/"/g, '&quot;')})">🗑️ Anonymizovat</button>
        </div>
      </div>`).join('');
  } catch (e) {
    box.innerHTML = '<span style="color:var(--danger-text,#B91C1C)">❌ ' + esc(e.message || 'Chyba') + '</span>';
  }
};

window.gdprExport = async function(id) {
  try {
    const r = await api('admin_gdpr.php?action=export&id=' + encodeURIComponent(id));
    const blob = new Blob([JSON.stringify(r, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'gdpr-export-odberatel-' + id + '.json';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 2000);
  } catch (e) { alert('❌ ' + (e.message || 'Export selhal')); }
};

window.gdprAnonymizovat = async function(id, nazev) {
  if (!confirm('Anonymizovat osobní údaje zákazníka „' + (nazev || ('#' + id)) + '"?\n\nOsobní údaje (jméno, e-mail, telefon, adresa) se NEVRATNĚ přepíší. Účetní doklady zůstanou zachovány kvůli zákonné době uchování.')) return;
  if (!confirm('Opravdu? Tato akce je nevratná.')) return;
  try {
    await api('admin_gdpr.php?action=anonymize', { method: 'POST', body: JSON.stringify({ id: id }) });
    alert('✅ Osobní údaje byly anonymizovány.');
    gdprPravaHledatNow();
  } catch (e) { alert('❌ ' + (e.message || 'Anonymizace selhala')); }
};

// ── Jednoduchý modal (sdílený pro náhled) ────────────────────────────
window.gdprModal = function(titulek, htmlObsah) {
  let m = document.getElementById('gdpr-zasady-modal');
  if (m) m.remove();
  m = document.createElement('div');
  m.id = 'gdpr-zasady-modal';
  m.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px';
  m.innerHTML = `
    <div style="background:var(--card-bg,#fff);color:var(--text-1,#111);border-radius:12px;max-width:720px;width:100%;max-height:85vh;overflow:auto;padding:22px 24px;box-shadow:0 12px 40px rgba(0,0,0,.3)">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h3 style="margin:0;font-size:16px">${esc(titulek)}</h3>
        <button class="btn-secondary" onclick="document.getElementById('gdpr-zasady-modal').remove()">✕ Zavřít</button>
      </div>
      ${htmlObsah}
    </div>`;
  m.onclick = (ev) => { if (ev.target === m) m.remove(); };
  document.body.appendChild(m);
};
