// 🏢 VENDOR PANEL — frontend logic

// 🔐 CSRF token — lazy fetch + cache (session synchronizer token z api.php?action=csrf)
let _csrfToken = null;
async function csrfToken() {
  if (_csrfToken) return _csrfToken;
  const meta = document.querySelector('meta[name="csrf-token"]')?.content;
  if (meta) { _csrfToken = meta; return _csrfToken; }
  try {
    const r = await fetch('api.php?action=csrf', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    });
    const d = await r.json();
    _csrfToken = d.token || '';
  } catch (e) { _csrfToken = ''; }
  return _csrfToken;
}

async function api(action, opts = {}) {
  const url = 'api.php?action=' + encodeURIComponent(action) + (opts.query ? '&' + opts.query : '');
  const method = opts.method || 'GET';
  const headers = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  if (method !== 'GET') headers['X-CSRF-Token'] = await csrfToken();  // 🔐 CSRF na všech POST
  const r = await fetch(url, {
    method,
    headers,
    body: opts.body ? JSON.stringify(opts.body) : undefined,
    credentials: 'same-origin',
  });
  if (r.status === 401) { location.href = 'index.php'; return; }
  const data = await r.json();
  if (!r.ok) throw new Error(data.error || ('HTTP ' + r.status));
  return data;
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));
}

function fmtDate(s) {
  if (!s) return '—';
  const d = new Date(s.includes('T') ? s : s + 'T00:00:00');
  if (isNaN(d)) return s;
  return d.toLocaleDateString('cs-CZ');
}

function fmtKc(n) {
  if (n == null || n === '') return '—';
  return new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: 'CZK', minimumFractionDigits: 0 }).format(n);
}

function statusBadge(status, daysToExpiry) {
  const map = {
    active:  { ico: '✅', text: 'Aktivní', cls: 'st-active' },
    expired: { ico: '⏰', text: 'Expirovaná', cls: 'st-expired' },
    revoked: { ico: '🚫', text: 'Revoked', cls: 'st-revoked' },
  };
  const s = map[status] || map.active;
  let warn = '';
  if (status === 'active' && daysToExpiry !== null && daysToExpiry !== undefined && daysToExpiry <= 30 && daysToExpiry >= 0) {
    warn = ` <span class="warn-soon">🟡 za ${daysToExpiry} dní</span>`;
  }
  return `<span class="status-pill ${s.cls}">${s.ico} ${s.text}</span>${warn}`;
}

// ═══════════════════════════════════════════════════════
// STATS + LIST
// ═══════════════════════════════════════════════════════
async function loadStats() {
  try {
    const s = await api('stats');
    document.getElementById('stats').innerHTML = `
      <div class="stat-card">
        <div class="stat-label">Celkem klíčů</div>
        <div class="stat-value">${s.total}</div>
      </div>
      <div class="stat-card stat-green">
        <div class="stat-label">✅ Aktivní</div>
        <div class="stat-value">${s.active}</div>
      </div>
      <div class="stat-card stat-yellow">
        <div class="stat-label">🟡 Expirují do 30 dní</div>
        <div class="stat-value">${s.expiring_soon}</div>
      </div>
      <div class="stat-card stat-orange">
        <div class="stat-label">⏰ Expirované</div>
        <div class="stat-value">${s.expired}</div>
      </div>
      <div class="stat-card stat-red">
        <div class="stat-label">🚫 Revoked</div>
        <div class="stat-value">${s.revoked}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">📅 Tento měsíc</div>
        <div class="stat-value">${s.this_month}</div>
      </div>
      <div class="stat-card stat-blue">
        <div class="stat-label">💰 Tržby (zaplacené)</div>
        <div class="stat-value">${fmtKc(s.revenue_total)}</div>
      </div>
    `;
  } catch (e) {
    document.getElementById('stats').innerHTML = `<div class="alert err">Chyba: ${esc(e.message)}</div>`;
  }
}

let allLicenses = [];

async function loadLicenses() {
  const q       = document.getElementById('search').value.trim();
  const status  = document.getElementById('status-filter').value;
  const params  = [];
  if (q)      params.push('q=' + encodeURIComponent(q));
  if (status) params.push('status=' + encodeURIComponent(status));

  try {
    const r = await api('list', { query: params.join('&') });
    allLicenses = r.licenses;
    renderTable(allLicenses);
  } catch (e) {
    document.getElementById('lic-body').innerHTML =
      `<tr><td colspan="8" class="alert err">Chyba: ${esc(e.message)}</td></tr>`;
  }
}

function licensePackagesFromKey(key) {
  const parts = (key || '').split('-');
  if (parts.length !== 6) return [];  // v1 = jen core
  // Decode bitmask z 4-char base32 — replicates _license.php logic
  const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  const PKG_BITS = { cukrarna:0, lahudky:1, restaurace:2, catering:3, sezona:4 };
  let n = 0;
  for (const ch of parts[4]) {
    const idx = ALPHABET.indexOf(ch);
    if (idx < 0) return [];
    n = (n << 5) | idx;
  }
  return Object.entries(PKG_BITS).filter(([_, bit]) => n & (1 << bit)).map(([k]) => k);
}

function pkgChips(key) {
  const pkgs = licensePackagesFromKey(key);
  if (pkgs.length === 0) return `<span class="muted" style="font-size:11px">core only</span>`;
  const meta = {
    cukrarna: '🧁', lahudky: '🥗', restaurace: '🍕', catering: '🎉', sezona: '🍰',
  };
  return pkgs.map(p => `<span style="display:inline-block;padding:1px 6px;background:#FFE0A8;color:#854F0B;border-radius:999px;font-size:10.5px;font-weight:600;margin:1px 2px">${meta[p] || ''} ${p}</span>`).join('');
}

function renderTable(rows) {
  const tbody = document.getElementById('lic-body');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="9" class="empty">📭 Žádné licence — klikni „+ Vygenerovat klíč" pro první.</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map(r => `
    <tr data-id="${r.id}">
      <td>${statusBadge(r.status, r.days_to_expiry)}${r.lock_state === 'locked' ? ` <span class="status-pill st-revoked" title="🔒 Anti-piracy lock — fingerprint instalace nesedí (reinstal/migrace serveru NEBO reuse klíče). Pokud legit, odemkni 🔓.">🔒 Locked</span>` : ''}</td>
      <td><code class="lic-key" onclick="copyKey('${esc(r.license_key)}')" title="Klik = kopírovat">${esc(r.license_key)}</code></td>
      <td>
        <div class="cust-name">${esc(r.customer_name)}</div>
        ${r.customer_company ? `<div class="cust-co">${esc(r.customer_company)}</div>` : ''}
      </td>
      <td>${pkgChips(r.license_key)}</td>
      <td>
        ${r.customer_email ? `<a href="mailto:${esc(r.customer_email)}">${esc(r.customer_email)}</a><br>` : ''}
        ${r.customer_phone ? `<span class="muted">${esc(r.customer_phone)}</span>` : ''}
      </td>
      <td>${fmtDate(r.issued_at)}</td>
      <td>${r.expires_at ? fmtDate(r.expires_at) : '<span class="muted">∞</span>'}</td>
      <td>${r.paid ? '✓' : '<span class="muted">nezaplaceno</span>'} ${fmtKc(r.price_kc)}</td>
      <td class="actions">
        <button class="btn-icon" onclick="openReissueModal(${r.id})" title="Změnit balíčky (nový klíč)">🎁</button>
        <button class="btn-icon" onclick="openEditModal(${r.id})" title="Upravit">✏️</button>
        ${r.status === 'revoked'
          ? `<button class="btn-icon" onclick="unrevokeLicense(${r.id})" title="Vrátit zpět">♻️</button>`
          : `<button class="btn-icon" onclick="openRevokeModal(${r.id})" title="Revoke">🚫</button>`
        }
        ${r.lock_state === 'locked' ? `<button class="btn-icon" onclick="unlockLicense(${r.id})" title="🔓 Odemknout anti-piracy lock (re-bind na příští heartbeat)">🔓</button>` : ''}
      </td>
    </tr>
  `).join('');
}

// 🎁 Reissue klíče s jinými balíčky (zachová random část, customer info)
window.openReissueModal = function(id) {
  const r = allLicenses.find(x => x.id === id);
  if (!r) return;
  const currentPkgs = licensePackagesFromKey(r.license_key);
  openModal('🎁 Změnit balíčky licence', `
    <div style="background:#FEF3C7;border-left:3px solid #FBBF24;padding:10px 12px;border-radius:8px;margin-bottom:12px;font-size:12px;color:#92400E">
      ⚠️ Vygeneruje se <strong>nový klíč</strong> s novou množinou balíčků. Starý klíč přestane platit.
      Pošli zákazníkovi nový klíč emailem — on ho vloží v adminu Nastavení → 🎁 Balíčky → Aktualizovat klíč.
    </div>
    <p style="font-size:13px;margin-bottom:8px"><strong>${esc(r.customer_name)}</strong></p>
    <p style="font-size:11.5px;color:#86868b;margin-bottom:14px">Současný klíč: <code>${esc(r.license_key)}</code></p>

    <div style="font-weight:600;font-size:13px;margin-bottom:6px">🎁 Vyber balíčky:</div>
    <form id="reissue-form" onsubmit="event.preventDefault();submitReissue(${id});">
      ${renderPackageCheckboxes(currentPkgs)}
      <div class="form-actions" style="margin-top:14px">
        <button type="button" class="btn-secondary" onclick="closeModal()">Zrušit</button>
        <button type="submit" class="btn btn-primary">🎲 Vygenerovat nový klíč</button>
      </div>
    </form>
  `);
};

window.submitReissue = async function(id) {
  const form = document.getElementById('reissue-form');
  const packages = Array.from(form.querySelectorAll('input[name="pkg"]:checked')).map(el => el.value);
  try {
    const r = await api('reissue', { method: 'POST', body: { id, packages } });
    const newKey = r.license.license_key;
    openModal('🎉 Klíč přegenerován', `
      <div class="alert ok">
        ✅ Nový klíč pro <strong>${esc(r.license.customer_name)}</strong>:
      </div>
      <div class="key-display" onclick="copyKey('${esc(newKey)}')">${esc(newKey)}</div>
      <p class="muted" style="text-align:center;margin:10px 0">Klikni na klíč pro kopírování. Předej zákazníkovi.</p>
      <div style="background:#FEE2E2;color:#991B1B;padding:10px;border-radius:6px;font-size:11.5px;margin-top:10px">
        ⚠️ Starý klíč přestal platit. Zákazník MUSÍ vložit nový.
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" onclick="copyKey('${esc(newKey)}'); closeModal();">📋 Zkopírovat &amp; zavřít</button>
      </div>
    `);
    loadStats(); loadLicenses();
  } catch (e) { alert('Chyba: ' + e.message); }
};

async function copyKey(key) {
  try {
    await navigator.clipboard.writeText(key);
    toast('✅ Klíč zkopírován do schránky');
  } catch (e) {
    prompt('Kopíruj ručně:', key);
  }
}

function toast(msg) {
  const t = document.createElement('div');
  t.className = 'toast';
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => t.classList.add('show'), 10);
  setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 2500);
}

// ═══════════════════════════════════════════════════════
// MODAL HELPERS
// ═══════════════════════════════════════════════════════
function openModal(title, html) {
  document.getElementById('modal-title').textContent = title;
  document.getElementById('modal-body').innerHTML = html;
  document.getElementById('modal').classList.remove('hidden');
}
function closeModal() {
  document.getElementById('modal').classList.add('hidden');
}

// ═══════════════════════════════════════════════════════
// GENERATE
// ═══════════════════════════════════════════════════════
// Katalog dostupných balíčků s cenami (musí odpovídat api/admin_packages.php)
const PKG_CATALOG = [
  { key: 'cukrarna',   ikona: '🧁', nazev: 'Cukrárna',           cena: 5000 },
  { key: 'lahudky',    ikona: '🥗', nazev: 'Lahůdkárna',         cena: 3000 },
  { key: 'restaurace', ikona: '🍕', nazev: 'Restaurace/Pizzerie', cena: 4000 },
  { key: 'catering',   ikona: '🎉', nazev: 'Velký catering',     cena: 2500 },
  { key: 'sezona',     ikona: '🍰', nazev: 'Sezónní módy',       cena: 1500 },
];

function renderPackageCheckboxes(selected = []) {
  return PKG_CATALOG.map(p => `
    <label class="pkg-check-row" style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:#f5f5f7;border-radius:8px;cursor:pointer;margin-bottom:6px">
      <input type="checkbox" name="pkg" value="${esc(p.key)}" ${selected.includes(p.key) ? 'checked' : ''} onchange="updatePkgPrice()" style="width:18px;height:18px">
      <span style="flex:1;font-size:13px"><strong>${p.ikona} ${esc(p.nazev)}</strong></span>
      <span style="font-size:12px;color:#86868b;font-weight:600">+${p.cena.toLocaleString('cs-CZ')} Kč</span>
    </label>
  `).join('');
}

window.updatePkgPrice = function() {
  const checked = Array.from(document.querySelectorAll('input[name="pkg"]:checked')).map(el => el.value);
  const total = PKG_CATALOG.filter(p => checked.includes(p.key)).reduce((s, p) => s + p.cena, 0);
  const el = document.getElementById('pkg-total');
  if (el) el.textContent = total.toLocaleString('cs-CZ') + ' Kč';
  const priceField = document.querySelector('input[name="price_kc"]');
  if (priceField && !priceField.dataset.userEdited) {
    priceField.value = total;
  }
};

function openGenerateModal() {
  openModal('🔑 Vygenerovat licenční klíč', `
    <form id="gen-form" onsubmit="event.preventDefault();submitGenerate();">
      <label><span class="lbl">Jméno zákazníka *</span>
        <input type="text" name="customer_name" required autofocus placeholder="Jan Novák">
      </label>
      <label><span class="lbl">Firma</span>
        <input type="text" name="customer_company" placeholder="Provoz Novák s.r.o.">
      </label>
      <div class="grid-2">
        <label><span class="lbl">Email</span><input type="email" name="customer_email" placeholder="info@provoz.cz"></label>
        <label><span class="lbl">Telefon</span><input type="tel" name="customer_phone" placeholder="+420 ..."></label>
      </div>
      <label><span class="lbl">URL instalace</span>
        <input type="url" name="install_url" placeholder="https://muj-provoz.cz">
      </label>

      <label style="margin-top:10px"><span class="lbl">🎁 Balíčky k aktivaci</span></label>
      <div style="background:#f7f8fa;padding:12px;border-radius:10px;border:1px dashed #d2d2d7">
        <div style="font-size:12px;color:#86868b;margin-bottom:8px">Core je vždy součástí. Vyber doplňkové balíčky (kódují se do klíče).</div>
        ${renderPackageCheckboxes([])}
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;padding-top:8px;border-top:1px solid #d2d2d7;font-size:13px">
          <span style="color:#86868b">💰 Doporučená cena</span>
          <strong id="pkg-total" style="font-size:16px;color:#0071e3">0 Kč</strong>
        </div>
      </div>

      <div class="grid-2" style="margin-top:10px">
        <label><span class="lbl">Expirace (volitelné)</span>
          <input type="date" name="expires_at">
          <small>Prázdné = navždy</small>
        </label>
        <label><span class="lbl">Cena (Kč)</span>
          <input type="number" name="price_kc" min="0" step="100" placeholder="0" oninput="this.dataset.userEdited='1'">
        </label>
      </div>
      <label><span class="lbl">Poznámka</span>
        <textarea name="note" rows="2" placeholder="Něco zvláštního? Speciální deal? ..."></textarea>
      </label>
      <label class="checkbox-row">
        <input type="checkbox" name="paid" checked>
        <span>💰 Zaplaceno</span>
      </label>
      <div class="form-actions">
        <button type="button" class="btn-secondary" onclick="closeModal()">Zrušit</button>
        <button type="submit" class="btn btn-primary">🎲 Vygenerovat klíč</button>
      </div>
    </form>
  `);
}

async function submitGenerate() {
  const form = document.getElementById('gen-form');
  const data = Object.fromEntries(new FormData(form).entries());
  data.paid = form.paid.checked;
  // Collect selected packages
  data.packages = Array.from(form.querySelectorAll('input[name="pkg"]:checked')).map(el => el.value);
  delete data.pkg;
  try {
    const r = await api('generate', { method: 'POST', body: data });
    const k = r.license.license_key;
    openModal('🎉 Klíč vygenerován', `
      <div class="alert ok">
        ✅ Klíč pro <strong>${esc(r.license.customer_name)}</strong> byl vytvořen:
      </div>
      <div class="key-display" onclick="copyKey('${esc(k)}')">${esc(k)}</div>
      <p class="muted" style="text-align:center;margin:10px 0">Klikni na klíč pro kopírování do schránky.</p>
      <div class="form-actions">
        <button class="btn btn-primary" onclick="copyKey('${esc(k)}'); closeModal();">📋 Zkopírovat &amp; zavřít</button>
      </div>
    `);
    loadStats(); loadLicenses();
  } catch (e) { alert('Chyba: ' + e.message); }
}

// ═══════════════════════════════════════════════════════
// EDIT
// ═══════════════════════════════════════════════════════
function openEditModal(id) {
  const r = allLicenses.find(x => x.id === id);
  if (!r) return;
  openModal('✏️ Upravit licenci', `
    <div class="key-display" style="margin-bottom:14px">${esc(r.license_key)}</div>
    <form id="edit-form" onsubmit="event.preventDefault();submitEdit(${id});">
      <label><span class="lbl">Jméno zákazníka</span><input type="text" name="customer_name" value="${esc(r.customer_name)}" required></label>
      <label><span class="lbl">Firma</span><input type="text" name="customer_company" value="${esc(r.customer_company ?? '')}"></label>
      <div class="grid-2">
        <label><span class="lbl">Email</span><input type="email" name="customer_email" value="${esc(r.customer_email ?? '')}"></label>
        <label><span class="lbl">Telefon</span><input type="tel" name="customer_phone" value="${esc(r.customer_phone ?? '')}"></label>
      </div>
      <label><span class="lbl">URL instalace</span><input type="url" name="install_url" value="${esc(r.install_url ?? '')}"></label>
      <div class="grid-2">
        <label><span class="lbl">Expirace</span><input type="date" name="expires_at" value="${esc(r.expires_at ?? '')}"></label>
        <label><span class="lbl">Cena (Kč)</span><input type="number" name="price_kc" min="0" step="100" value="${esc(r.price_kc ?? '')}"></label>
      </div>
      <label><span class="lbl">Poznámka</span><textarea name="note" rows="2">${esc(r.note ?? '')}</textarea></label>
      <label class="checkbox-row"><input type="checkbox" name="paid" ${r.paid == 1 ? 'checked' : ''}><span>💰 Zaplaceno</span></label>
      <div class="form-actions">
        <button type="button" class="btn-secondary" onclick="closeModal()">Zrušit</button>
        <button type="submit" class="btn btn-primary">💾 Uložit</button>
      </div>
    </form>
  `);
}

async function submitEdit(id) {
  const form = document.getElementById('edit-form');
  const data = Object.fromEntries(new FormData(form).entries());
  data.id   = id;
  data.paid = form.paid.checked ? 1 : 0;
  try {
    await api('update', { method: 'POST', body: data });
    closeModal();
    toast('✅ Uloženo');
    loadStats(); loadLicenses();
  } catch (e) { alert('Chyba: ' + e.message); }
}

// ═══════════════════════════════════════════════════════
// REVOKE
// ═══════════════════════════════════════════════════════
function openRevokeModal(id) {
  const r = allLicenses.find(x => x.id === id);
  if (!r) return;
  openModal('🚫 Revoke licenci', `
    <div class="alert warn">
      ⚠️ Revokace je <strong>jen tvoje evidence</strong> — zákazník to nepozná
      (žádný phone-home). Slouží ke správě tvého obchodu.
    </div>
    <p>Licence: <code>${esc(r.license_key)}</code><br>
    Zákazník: <strong>${esc(r.customer_name)}</strong></p>
    <form onsubmit="event.preventDefault();submitRevoke(${id});">
      <label><span class="lbl">Důvod revokace *</span>
        <textarea id="revoke-reason" rows="3" required placeholder="Nezaplaceno · porušení smlouvy · ..."></textarea>
      </label>
      <div class="form-actions">
        <button type="button" class="btn-secondary" onclick="closeModal()">Zrušit</button>
        <button type="submit" class="btn btn-danger">🚫 Revoke</button>
      </div>
    </form>
  `);
}

async function submitRevoke(id) {
  const reason = document.getElementById('revoke-reason').value.trim();
  if (!reason) return;
  try {
    await api('revoke', { method: 'POST', body: { id, reason } });
    closeModal();
    toast('✅ Licence revoked');
    loadStats(); loadLicenses();
  } catch (e) { alert('Chyba: ' + e.message); }
}

async function unrevokeLicense(id) {
  if (!confirm('Vrátit licenci zpět na active?')) return;
  try {
    await api('unrevoke', { method: 'POST', body: { id } });
    toast('✅ Vráceno zpět');
    loadStats(); loadLicenses();
  } catch (e) { alert('Chyba: ' + e.message); }
}

// 🆕 v3.0.386 — odemkni anti-piracy lock (false-positive po legit reinstalu/migraci serveru)
async function unlockLicense(id) {
  if (!confirm('Odemknout anti-piracy lock?\n\nVynuluje fingerprint → licence se re-nabinduje na aktuální instalaci při příštím heartbeatu. Použij když jde o legitimní reinstal/migraci serveru, ne o reuse klíče.')) return;
  try {
    await api('unlock', { method: 'POST', body: { id } });
    toast('🔓 Odemčeno (re-bind na příští heartbeat)');
    loadStats(); loadLicenses();
  } catch (e) { alert('Chyba: ' + e.message); }
}

// ═══════════════════════════════════════════════════════
// AUDIT LOG
// ═══════════════════════════════════════════════════════
async function openAuditLog() {
  openModal('📜 Audit log (posledních 100 akcí)', '<div>⏳ Načítám…</div>');
  try {
    const r = await api('audit_log', { query: 'limit=100' });
    const html = r.logs.map(l => `
      <div class="log-row">
        <span class="muted">${esc(new Date(l.created_at).toLocaleString('cs-CZ'))}</span>
        <strong>${esc(l.username || '?')}</strong>
        <span class="action-pill">${esc(l.action)}</span>
        ${l.target_key ? `<code>${esc(l.target_key)}</code>` : ''}
        ${l.details ? `<span class="muted">— ${esc(l.details)}</span>` : ''}
      </div>
    `).join('') || '<div class="empty">Žádné záznamy.</div>';
    document.getElementById('modal-body').innerHTML = `<div class="log-list">${html}</div>`;
  } catch (e) {
    document.getElementById('modal-body').innerHTML = `<div class="alert err">${esc(e.message)}</div>`;
  }
}

// ═══════════════════════════════════════════════════════
// CHANGE PASSWORD
// ═══════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════
// 🔐 2FA (TOTP) Setup
// ═══════════════════════════════════════════════════════
window.open2faModal = async function() {
  let status;
  try {
    status = await api('2fa_status');
  } catch (e) { return alert('Chyba: ' + e.message); }

  if (status.enabled) {
    openModal('🔐 Dvoufaktorové ověření (zapnuto)', `
      <div class="alert ok">✅ 2FA je <strong>aktivní</strong>. Při dalším přihlášení budeš muset zadat 6místný kód z autentikační aplikace.</div>
      <p class="muted" style="margin:14px 0 8px;font-size:12.5px">Chceš 2FA vypnout? Zadej aktuální 6místný kód jako potvrzení (chrání proti náhodnému vypnutí někým, kdo má jen heslo).</p>
      <form onsubmit="event.preventDefault();submit2faDisable();">
        <label><span class="lbl">Aktuální 2FA kód</span>
          <input type="text" id="totp-disable-code" pattern="[0-9]{6}" maxlength="6" inputmode="numeric"
                 required autofocus style="font-family:'SF Mono',Menlo,monospace;letter-spacing:0.2em;font-size:18px;text-align:center">
        </label>
        <div class="form-actions">
          <button type="button" class="btn-secondary" onclick="closeModal()">Zrušit</button>
          <button type="submit" class="btn btn-danger">🔓 Vypnout 2FA</button>
        </div>
      </form>
    `);
    return;
  }

  // Setup wizard
  let setup;
  try {
    setup = await api('2fa_setup_start', { method: 'POST' });
  } catch (e) { return alert('Chyba: ' + e.message); }

  openModal('🔐 Zapnout dvoufaktorové ověření', `
    <div class="alert" style="background:#FFF8E5;color:#854F0B;padding:12px;border-radius:8px;border-left:3px solid #BA7517;margin-bottom:14px">
      <strong>Krok 1:</strong> Otevři Google Authenticator / Authy / 1Password a naskenuj QR kód, nebo zadej secret ručně.
    </div>
    <div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;justify-content:center">
      <img src="${esc(setup.qr_url)}" alt="2FA QR" style="width:200px;height:200px;border:1px solid #d2d2d7;border-radius:10px;padding:6px;background:#fff">
      <div style="flex:1;min-width:200px">
        <div style="font-size:12px;color:#86868b;margin-bottom:4px">Secret (manuální zadání)</div>
        <code style="display:block;background:#1d1d1f;color:#6dd58a;padding:10px 12px;border-radius:8px;font-size:14px;letter-spacing:0.1em;word-break:break-all;cursor:pointer" onclick="navigator.clipboard.writeText('${esc(setup.secret)}');toast('✅ Zkopírováno')">${esc(setup.secret)}</code>
        <small style="color:#86868b;font-size:11px;display:block;margin-top:4px">Klik = kopírovat</small>
      </div>
    </div>

    <div class="alert" style="background:#DBEAFE;color:#1e40af;padding:10px 12px;border-radius:8px;margin-top:14px;font-size:12.5px">
      <strong>Krok 2:</strong> Po naskenování zadej kód, který ti aplikace ukáže (6 číslic, mění se každých 30s).
    </div>

    <form onsubmit="event.preventDefault();submit2faConfirm();">
      <label><span class="lbl">Ověřovací kód</span>
        <input type="text" id="totp-confirm-code" pattern="[0-9]{6}" maxlength="6" inputmode="numeric"
               required autofocus style="font-family:'SF Mono',Menlo,monospace;letter-spacing:0.2em;font-size:20px;text-align:center;padding:14px">
      </label>
      <div class="form-actions">
        <button type="button" class="btn-secondary" onclick="closeModal()">Zrušit</button>
        <button type="submit" class="btn btn-primary">🔐 Zapnout 2FA</button>
      </div>
    </form>
  `);
};

window.submit2faConfirm = async function() {
  const code = document.getElementById('totp-confirm-code')?.value?.trim();
  if (!code) return;
  try {
    await api('2fa_setup_confirm', { method: 'POST', body: { code } });
    closeModal();
    toast('✅ 2FA aktivováno. Při dalším přihlášení budeš potřebovat kód.');
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.submit2faDisable = async function() {
  const code = document.getElementById('totp-disable-code')?.value?.trim();
  if (!code) return;
  try {
    await api('2fa_disable', { method: 'POST', body: { code } });
    closeModal();
    toast('🔓 2FA vypnuto');
  } catch (e) { alert('Chyba: ' + e.message); }
};

function openPasswordModal() {
  openModal('🔑 Změna hesla', `
    <form id="pw-form" onsubmit="event.preventDefault();submitPassword();">
      <label><span class="lbl">Staré heslo</span><input type="password" name="old" required></label>
      <label><span class="lbl">Nové heslo (min 10)</span><input type="password" name="new" minlength="10" required></label>
      <label><span class="lbl">Nové heslo znovu</span><input type="password" name="new2" minlength="10" required></label>
      <div class="form-actions">
        <button type="button" class="btn-secondary" onclick="closeModal()">Zrušit</button>
        <button type="submit" class="btn btn-primary">💾 Změnit</button>
      </div>
    </form>
  `);
}

async function submitPassword() {
  const f = document.getElementById('pw-form');
  if (f.new.value !== f.new2.value) { alert('Hesla se neshodují'); return; }
  try {
    await api('change_password', { method: 'POST', body: { old: f.old.value, new: f.new.value } });
    closeModal();
    toast('✅ Heslo změněno');
  } catch (e) { alert('Chyba: ' + e.message); }
}

// ═══════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════
document.getElementById('search').addEventListener('input', debounce(loadLicenses, 200));
document.getElementById('status-filter').addEventListener('change', loadLicenses);

function debounce(fn, ms) {
  let t; return function() { clearTimeout(t); t = setTimeout(() => fn.apply(this, arguments), ms); };
}

loadStats();
loadLicenses();
