// =============================================================
// 🔌 API TOKENY (pro účetní systémy)
// =============================================================
window.apiTokensLoad = async function() {
  const host = document.getElementById('api-tokens-list');
  if (!host) return;
  try {
    const list = await api('admin_api_tokens.php');
    if (list.length === 0) {
      host.innerHTML = '<div class="empty-state" style="padding:14px;text-align:center">Zatím žádné API tokeny. Klikni „+ Nový token" pro vytvoření prvního.</div>';
      return;
    }
    host.innerHTML = `
      <table class="table" style="margin:0;font-size:13px">
        <thead>
          <tr>
            <th>Název</th>
            <th>Token</th>
            <th>Oprávnění</th>
            <th class="num">Volání</th>
            <th>Posl. použití</th>
            <th>Stav</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          ${list.map(t => `
            <tr>
              <td><strong>${esc(t.nazev)}</strong></td>
              <td style="font-family:monospace;font-size:11px;color:var(--text-3)">${esc(t.token_preview)}</td>
              <td>${t.opravneni === 'write' ? '<span class="status nova">✏️ Read + Write</span>' : '<span class="status dorucena">👁 Read only</span>'}</td>
              <td class="num">${t.pocet_volani}</td>
              <td style="font-size:11px">${t.posledni_pouziti ? fmtDateTime(t.posledni_pouziti) : '<span style="color:var(--text-3)">nikdy</span>'}</td>
              <td>${t.aktivni == 1 ? '<span class="status dorucena">Aktivní</span>' : '<span class="status zrusena">Vypnut</span>'}</td>
              <td style="text-align:right;white-space:nowrap">
                <button class="btn-secondary" style="font-size:11px;padding:4px 10px" onclick="apiTokenToggle(${t.id}, ${t.aktivni})">${t.aktivni ? '⏸ Vypnout' : '▶ Zapnout'}</button>
                <button class="btn-danger" style="font-size:11px;padding:4px 10px" onclick="apiTokenDelete(${t.id}, '${esc(t.nazev).replace(/'/g, '')}')">🗑️</button>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
  } catch (e) { host.innerHTML = `<div style="color:var(--danger-text)">Chyba: ${esc(e.message)}</div>`; }
};

window.apiTokenNew = async function() {
  openModal('+ Nový API token', `
    <div class="form-grid form-grid-tight">
      <div class="full">
        <label class="form-label">Název tokenu *</label>
        <input class="form-input" id="atk-nazev" placeholder="např. Money S3 — paní Nováková">
      </div>
      <div class="full">
        <label class="form-label">Oprávnění</label>
        <select class="form-input" id="atk-opravneni">
          <option value="read">👁 Read only (doporučeno) — jen stahování dat</option>
          <option value="write">✏️ Read + Write — i označit FA jako uhrazenou</option>
        </select>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="apiTokenCreate()" style="font-weight:700">🔑 Vytvořit token</button>
    </div>
  `);
};

window.apiTokenCreate = async function() {
  const nazev = document.getElementById('atk-nazev').value.trim();
  const opravneni = document.getElementById('atk-opravneni').value;
  if (!nazev) return alert('Vyplň název');
  try {
    const r = await api('admin_api_tokens.php', { method: 'POST', body: JSON.stringify({ nazev, opravneni }) });
    // Zobraz token jen 1×
    openModal('🔑 Token vytvořen — uložte si ho!', `
      <div style="background:#FEF3C7;border:2px solid #F59E0B;padding:14px;border-radius:8px;margin-bottom:12px;color:#854F0B">
        <strong>⚠️ Důležité:</strong> Token uvidíte teď naposledy! Z bezpečnostních důvodů ho už neukážeme. Zkopírujte si ho hned.
      </div>
      <label class="form-label">API token</label>
      <textarea readonly class="form-input" rows="3" onclick="this.select()" style="font-family:monospace;font-size:13px;background:#f7f8fa">${esc(r.token)}</textarea>
      <p style="font-size:12px;color:var(--text-3);margin-top:10px">
        <strong>Použití v Money S3 / POHODA / Fakturoid:</strong><br>
        URL: <code>${location.origin}/api/v1/faktury?token=${esc(r.token)}</code><br>
        Nebo HTTP hlavička: <code>Authorization: Bearer ${esc(r.token)}</code>
      </p>
      <div class="form-actions">
        <button class="btn-secondary" onclick="navigator.clipboard.writeText('${esc(r.token).replace(/'/g, "\\'")}').then(() => alert('✓ Zkopírováno do schránky'))">📋 Kopírovat token</button>
        <div style="flex:1"></div>
        <button class="btn-primary btn-green" onclick="closeModal();apiTokensLoad()">Zavřít</button>
      </div>
    `);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.apiTokenToggle = async function(id, isAkt) {
  // Načti pro nazev a opravneni — jednodušší PUT s minimálními změnami
  const list = await api('admin_api_tokens.php');
  const t = list.find(x => x.id == id);
  if (!t) return;
  await api('admin_api_tokens.php', { method: 'PUT', body: JSON.stringify({ id, nazev: t.nazev, opravneni: t.opravneni, aktivni: isAkt ? 0 : 1 }) });
  apiTokensLoad();
};

window.apiTokenDelete = async function(id, nazev) {
  if (!await confirmDelete2x(`API token „${nazev}"`)) return;
  try {
    await api(`admin_api_tokens.php?id=${id}`, { method: 'DELETE' });
    apiTokensLoad();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🗂️ Přepnutí záložky v Nastavení
window.nastaveniSetTab = function(key) {
  state._nastaveniTab = key;
  renderNastaveni();
  // Skroluj nahoru
  window.scrollTo({ top: 0, behavior: 'smooth' });
};

