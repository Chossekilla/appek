// =============================================================
// 🆕 v3.0.645 — ⏰ PLÁNOVANÉ ÚLOHY (CRON) v Nastavení → Údržba
//   Ukáže, jakou veřejnou adresu (vč. podsložky) použije cron pro odkazy v e-mailech/push,
//   a hotové příkazy pro hPanel (formát hPanelu: JEN cesta od domovské složky + 5 polí času —
//   „/usr/bin/php /home/<účet>/" doplní hPanel sám). Data: api/admin_cron_info.php.
// =============================================================

window.cronInfoLoad = async function () {
  const box = document.getElementById('ns-cron-info');
  if (!box) return;
  box.innerHTML = '⏳ Načítám…';
  let r;
  try {
    apiCacheInvalidate('admin_cron_info');
    r = await api('admin_cron_info.php');
  } catch (e) {
    box.innerHTML = `<span style="color:var(--danger-text, #991B1B)">Nepodařilo se načíst: ${esc(e.message || String(e))}</span>`;
    return;
  }
  const zdroj = r.zdroj === 'auto'
    ? 'zjištěno automaticky z přihlášení super admina'
    : (r.zdroj === 'app_url' ? 'z APP_URL v api/config.local.php' : 'neznámá');
  const copyBtn = (id) => `<button type="button" class="btn-secondary" style="font-size:11px;padding:4px 10px;flex-shrink:0" onclick="cronInfoCopy('${id}')">📋 Kopírovat</button>`;
  const fmtPosledni = (p) => {
    if (!p) return '<span style="color:var(--text-3)">zatím neběžel</span>';
    const kdy = esc(String(p.started_at || '').substring(0, 16));
    return p.ok
      ? `<span style="color:var(--success-text)">✓ ${kdy}</span>`
      : `<span style="color:var(--danger-text, #991B1B)">✗ ${kdy}</span>`;
  };
  const ulohy = (r.ulohy || []).map((u, i) => {
    // hPanel má čas a příkaz v samostatných polích → kopírovat jen cestu (čas je vidět nad ní)
    const prikaz = u.hpanel ? u.hpanel : u.prikaz;
    return `
      <div style="padding:10px 0;border-top:1px dashed var(--border)">
        <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;font-size:13px">
          <strong>${esc(u.soubor)}</strong>
          <span style="font-size:12px">Poslední běh: ${fmtPosledni(u.posledni)}</span>
        </div>
        <div style="font-size:12px;color:var(--text-3);margin:2px 0 6px">${esc(u.popis)}</div>
        ${u.hpanel ? `
          <div style="font-size:11px;color:var(--text-3);margin-bottom:3px">Čas: <code>${esc(u.cas)}</code> · Příkaz: <code>${esc(u.hpanel)}</code></div>` : ''}
        <div style="display:flex;gap:6px;align-items:center;min-width:0">
          <input class="form-input" id="ns-cron-cmd-${i}" type="text" readonly value="${esc(prikaz)}" onclick="this.select()" style="font-family:monospace;font-size:11px;flex:1;min-width:0">
          ${copyBtn('ns-cron-cmd-' + i)}
        </div>
      </div>`;
  }).join('');
  box.innerHTML = `
    <div style="font-size:12px;color:var(--text-3);margin-bottom:4px">Adresa pro odkazy z cronu (e-maily, push):</div>
    <div style="display:flex;gap:6px;align-items:center;min-width:0;margin-bottom:4px">
      <input class="form-input" id="ns-cron-base" type="text" readonly value="${esc(r.cron_base || '— neznámá —')}" onclick="this.select()" style="font-family:monospace;font-size:12px;flex:1;min-width:0">
    </div>
    <div style="font-size:11px;color:var(--text-3);margin-bottom:8px">Zdroj: ${esc(zdroj)}${r.web_base && r.web_base !== r.cron_base ? ` · právě přihlášeni přes <code>${esc(r.web_base)}</code>` : ''}</div>
    ${(r.varovani || []).map(v => `
      <div style="margin-bottom:8px;padding:8px 10px;background:#FEF3C7;border-left:3px solid #F59E0B;border-radius:6px;font-size:12px;color:#854F0B;line-height:1.5">⚠️ ${esc(v)}</div>`).join('')}
    ${r.muze_pouzit ? `
      <button type="button" class="btn-secondary" style="font-size:12px;padding:6px 12px;margin-bottom:10px" onclick="cronInfoPouzit()">📍 Cron má používat tuto adresu</button>` : ''}
    <div style="font-size:12px;color:var(--text-2);margin:6px 0 2px">
      ${r.hpanel
        ? '<strong>hPanel → Pokročilé → Cron úlohy → Vlastní:</strong> zadejte JEN cestu a 5 polí času (<code>/usr/bin/php</code> a domovskou složku doplní hPanel sám).'
        : '<strong>Crontab serveru:</strong> zkopírujte řádek do <code>crontab -e</code>.'}
    </div>
    ${ulohy || '<div style="font-size:12px;color:var(--text-3)">Žádné úlohy.</div>'}
  `;
};

window.cronInfoCopy = function (id) {
  const el = document.getElementById(id);
  if (!el) return;
  const hotovo = () => (typeof toastSuccess === 'function' ? toastSuccess('Zkopírováno') : null);
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(el.value).then(hotovo).catch(() => { el.select(); document.execCommand('copy'); hotovo(); });
  } else {
    el.select(); document.execCommand('copy'); hotovo();
  }
};

window.cronInfoPouzit = async function () {
  if (!confirm('Cron bude v odkazech používat adresu, přes kterou jste právě přihlášeni. Pokračovat?')) return;
  try {
    await api('admin_cron_info.php?action=pouzit', { method: 'POST', body: {} });
    if (typeof toastSuccess === 'function') toastSuccess('Adresa pro cron uložena');
  } catch (e) {
    if (typeof toastError === 'function') toastError(e.message || String(e)); else alert(e.message || e);
  }
  cronInfoLoad();
};
