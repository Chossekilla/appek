// =============================================================
// AUTOFILL — automaticky vyplní HACCP data pro všechny výrobky
// =============================================================
window.haccpAutofillPreview = async function() {
  // Zkontroluj zda jsou grafy
  const grafy = haccpState.grafy || [];
  if (grafy.length === 0) {
    if (!(await confirmDialog({ msg: 'Nejprve potřebuješ HACCP grafy (šablony). Naimportovat výchozí sadu (5 šablon) automaticky?', danger: false }))) return;
    try {
      await api('admin_haccp_grafy.php?action=import_default', { method: 'POST', body: '{}' });
      haccpState.grafy = await api('admin_haccp_grafy.php');
    } catch (e) { return alert('Chyba: ' + e.message); }
  }

  let preview;
  try {
    preview = await api('admin_haccp_autofill.php');
  } catch (e) { return alert('Chyba: ' + e.message); }

  const tk = preview.po_typu || {};
  const labels = {
    'chleba': 'Chléb',
    'jemne': 'Jemné pečivo',
    'specialni': 'Speciální (dalamánky)',
    'psenicne_zdobeni': 'Pšeničné se zdobením',
    'psenicne_zaklad': 'Pšeničné základní',
  };
  const summary = Object.entries(tk).map(([k, n]) => `<li><strong>${esc(labels[k] || k)}</strong>: ${n} výrobků</li>`).join('');

  // Detail první stránky výrobků
  const navrhy = preview.navrhy || [];
  const ukazka = navrhy.slice(0, 8).map(n => `
    <tr>
      <td style="font-size:11px;color:var(--text-3)">${esc(n.cislo || '')}</td>
      <td><strong>${esc(n.nazev)}</strong></td>
      <td><span style="background:#F4F0E8;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:600">${esc(labels[n.typ] || n.typ)}</span></td>
      <td style="font-size:11px;color:var(--text-2)">${esc(n.graf_nazev || '—')}</td>
    </tr>
  `).join('');

  openModal('🪄 Auto-vyplnit HACCP karty — preview', `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
      <div style="background:var(--surface-2);padding:12px;border-radius:8px">
        <div style="font-size:11px;color:var(--text-3);margin-bottom:6px;font-weight:600;text-transform:uppercase;letter-spacing:0.5pt">Celkem k zpracování</div>
        <div style="font-size:28px;font-weight:700;color:var(--brand)">${preview.celkem || 0}</div>
        <div style="font-size:11px;color:var(--text-2)">aktivních výrobků</div>
      </div>
      <div style="background:var(--surface-2);padding:12px;border-radius:8px">
        <div style="font-size:11px;color:var(--text-3);margin-bottom:6px;font-weight:600;text-transform:uppercase;letter-spacing:0.5pt">Detekované typy</div>
        <ul style="margin:0;padding-left:18px;font-size:13px">${summary || '<li style="color:var(--text-3)">žádné</li>'}</ul>
      </div>
    </div>

    <div style="background:#FFF8E7;padding:10px 14px;border-radius:8px;font-size:12px;margin-bottom:12px;border:1px solid #F59E0B33">
      <strong>📋 Co se vyplní:</strong>
      <ul style="margin:6px 0 0 18px;padding:0">
        <li>12 standardních polí (produkt, skupina, trvanlivost, popis, balení, skladování...)</li>
        <li>9 kritických bodů (B/CH/F nebezpečí + opatření) s konkrétními teplotami pečení per typ</li>
        <li>5 organoleptických parametrů (vzhled, tvar, vůně, chuť, struktura)</li>
        <li>Mikrobiologické limity dle EK 2073/2005</li>
        <li>Přiřadí HACCP graf (šablonu postupu) podle typu výrobku</li>
      </ul>
    </div>

    <div style="margin-bottom:8px;font-weight:600;font-size:13px">Ukázka prvních ${Math.min(8, navrhy.length)} výrobků:</div>
    <div style="max-height:30vh;overflow:auto;border:1px solid var(--border);border-radius:6px;margin-bottom:14px">
      <table class="table" style="margin:0;font-size:13px">
        <thead><tr><th>Kód</th><th>Výrobek</th><th>Detekovaný typ</th><th>HACCP graf</th></tr></thead>
        <tbody>${ukazka}</tbody>
      </table>
    </div>

    <div style="background:#F0F9FF;border:1px solid #93C5FD;padding:10px 14px;border-radius:8px;font-size:12px;margin-bottom:14px;color:#1e40af">
      ℹ️ <strong>Defaultní režim</strong>: vyplní jen <em>prázdná pole</em> (zachová ruční úpravy).<br>
      ⚠️ <strong>Force režim</strong>: <em>přepíše vše</em> (i ručně vyplněná pole) — používej opatrně.
    </div>

    <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-secondary" onclick="haccpAutofillRun(true)" style="color:#dc2626;border-color:#fca5a5">⚠️ Přepsat vše (force)</button>
      <button class="btn-primary btn-green" onclick="haccpAutofillRun(false)">✅ Spustit (jen prázdná)</button>
    </div>
  `);
};

window.haccpAutofillRun = async function(force) {
  const url = force ? 'admin_haccp_autofill.php?force=1' : 'admin_haccp_autofill.php';
  closeModal();
  // Loading toast
  const t = document.createElement('div');
  t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--surface-1);color:var(--text-1);padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:14px;font-weight:600;z-index:9999;border:1px solid var(--border)';
  t.textContent = '⏳ Autofill běží…';
  document.body.appendChild(t);
  try {
    const r = await api(url, { method: 'POST', body: '{}' });
    t.remove();
    const ok = document.createElement('div');
    ok.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:14px;font-weight:600;z-index:9999';
    ok.innerHTML = `✓ Vyplněno ${r.updated_haccp || 0} karet, přiřazeno ${r.assigned_graf || 0} grafů`;
    document.body.appendChild(ok);
    setTimeout(() => ok.remove(), 4500);
    // Refresh data
    const [vList, grafy] = await Promise.all([
      api('admin_vyrobky.php'),
      api('admin_haccp_grafy.php').catch(() => []),
    ]);
    haccpState.vyrobky = (vList && Array.isArray(vList.vyrobky)) ? vList.vyrobky : [];
    haccpState.grafy = Array.isArray(grafy) ? grafy : [];
    haccpRender();
  } catch (e) {
    t.remove();
    alert('Chyba: ' + e.message);
  }
};

