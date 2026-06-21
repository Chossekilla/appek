// =============================================================
// 🔌 CUSTOMER INTEGRACE — Stripe + GoPay + Zásilkovna + DPD (v2.5)
// =============================================================
const INT_CARDS = {
  stripe: {
    label: 'Stripe',
    color: '#635bff',
    statusEl: 'ns-int-stripe-status',
    formEl: 'ns-int-stripe',
    fields: [
      { key: 'environment', label: 'Režim', type: 'select', options: [['test','🧪 Test'],['live','🟢 Live']], col: 'half' },
      { key: 'currency',    label: 'Měna',  type: 'select', options: [['czk','CZK'],['eur','EUR'],['usd','USD']], col: 'half' },
      { key: 'secret_key',      label: 'Secret key (sk_…)',    type: 'password', placeholder: 'sk_live_…' },
      { key: 'publishable_key', label: 'Publishable key (pk_…)', type: 'text', placeholder: 'pk_live_…' },
      { key: 'webhook_secret',  label: 'Webhook secret (whsec_…)', type: 'password', placeholder: 'whsec_…' },
    ],
    docs: 'https://dashboard.stripe.com/apikeys',
    docsLabel: '📖 Dashboard Stripe',
  },
  gopay: {
    label: 'GoPay',
    color: '#dc2626',
    statusEl: 'ns-int-gopay-status',
    formEl: 'ns-int-gopay',
    fields: [
      { key: 'environment', label: 'Režim',    type: 'select', options: [['test','🧪 Sandbox'],['production','🟢 Production']], col: 'half' },
      { key: 'currency',    label: 'Měna',     type: 'select', options: [['CZK','CZK'],['EUR','EUR']], col: 'half' },
      { key: 'goid',        label: 'GoID',     type: 'text',     placeholder: '8123456789' },
      { key: 'client_id',     label: 'Client ID',     type: 'text', placeholder: 'GoPay OAuth client ID' },
      { key: 'client_secret', label: 'Client Secret', type: 'password', placeholder: 'OAuth secret' },
    ],
    docs: 'https://help.gopay.com/cs/s/dokumentace-pro-vyvojare',
    docsLabel: '📖 GoPay API docs',
  },
  // 🆕 v2.9.209 — PayPal pro koncové zákazníky
  paypal: {
    label: 'PayPal',
    color: '#0070ba',
    statusEl: 'ns-int-paypal-status',
    formEl: 'ns-int-paypal',
    fields: [
      { key: 'environment',   label: 'Režim', type: 'select', options: [['sandbox','🧪 Sandbox'],['live','🟢 Live']], col: 'half' },
      { key: 'currency',      label: 'Měna',  type: 'select', options: [['CZK','CZK'],['EUR','EUR'],['USD','USD']], col: 'half' },
      { key: 'client_id',     label: 'Client ID',     type: 'text',     placeholder: 'AYSq3RDGsmBLJE-...' },
      { key: 'client_secret', label: 'Client Secret', type: 'password', placeholder: 'EGnHDxD_qRPdaLdZz...' },
    ],
    docs: 'https://developer.paypal.com/dashboard/applications',
    docsLabel: '📖 PayPal Developer Dashboard',
  },
  zas: {
    label: 'Zásilkovna',
    color: '#bf2026',
    statusEl: 'ns-int-zas-status',
    formEl: 'ns-int-zas',
    fields: [
      { key: 'api_password', label: 'API password',     type: 'password', placeholder: 'z administrace Zásilkovny' },
      { key: 'sender_label', label: 'Label odesílatele', type: 'text',     placeholder: 'Moje pekařství' },
      { key: 'id_sender',    label: 'ID odesílatele',    type: 'text',     placeholder: '12345' },
    ],
    docs: 'https://client.packeta.com/cs/support',
    docsLabel: '📖 Zásilkovna podpora',
  },
  dpd: {
    label: 'DPD CZ',
    color: '#dc0032',
    statusEl: 'ns-int-dpd-status',
    formEl: 'ns-int-dpd',
    fields: [
      { key: 'environment', label: 'Režim', type: 'select', options: [['test','🧪 Test'],['production','🟢 Production']], col: 'full' },
      { key: 'username',    label: 'Username',     type: 'text',     placeholder: 'DPD účet' },
      { key: 'password',    label: 'Password',     type: 'password', placeholder: 'DPD heslo' },
      { key: 'customer_id', label: 'Customer ID',  type: 'text',     placeholder: 'zákaznické číslo' },
    ],
    docs: 'https://docs.dpd.cz',
    docsLabel: '📖 DPD CZ API docs',
  },
  ppl: {
    label: 'PPL',
    color: '#005a9b',
    statusEl: 'ns-int-ppl-status',
    formEl: 'ns-int-ppl',
    fields: [
      { key: 'environment',   label: 'Režim', type: 'select', options: [['test','🧪 Test'],['production','🟢 Production']], col: 'full' },
      { key: 'client_id',     label: 'Client ID',     type: 'text',     placeholder: 'PPL myAPI2 client_id' },
      { key: 'client_secret', label: 'Client Secret', type: 'password', placeholder: 'PPL myAPI2 secret' },
      { key: 'sender_name',   label: 'Název odesílatele', type: 'text',  placeholder: 'Moje pekařství' },
      { key: 'product',       label: 'Produkt (kód)', type: 'text',     placeholder: 'BUSCMN' },
    ],
    docs: 'https://github.com/ppl-cpl/myapi2',
    docsLabel: '📖 PPL myAPI2 docs',
  },
  cp: {
    label: 'Česká pošta',
    color: '#caa800',
    statusEl: 'ns-int-cp-status',
    formEl: 'ns-int-cp',
    fields: [
      { key: 'api_url',     label: 'API URL',  type: 'text',     placeholder: 'https://… (dle smlouvy ČP)' },
      { key: 'api_key',     label: 'API klíč / token', type: 'password', placeholder: 'token Podání Online' },
      { key: 'customer_id', label: 'Zákaznické číslo', type: 'text', placeholder: 'PSČ podání / ID' },
      { key: 'sender_name', label: 'Název odesílatele', type: 'text', placeholder: 'Moje pekařství' },
      { key: 'service',     label: 'Služba (kód)', type: 'text', placeholder: 'BA = Balík Do ruky' },
    ],
    docs: 'https://www.postaonline.cz/dokumentace',
    docsLabel: '📖 ČP Podání Online docs',
  },
};

async function loadCustomerIntegrace() {
  for (const [svc, cfg] of Object.entries(INT_CARDS)) {
    loadIntCard(svc, cfg);
  }
}

async function loadIntCard(svc, cfg) {
  let data = {};
  try { data = await api('admin_integrace.php?action=settings&service=' + svc); }
  catch (e) { /* nově — empty */ }
  const enabled = (data['int_' + svc + '_enabled'] || '0') === '1';
  const statusEl = document.getElementById(cfg.statusEl);
  if (statusEl) {
    statusEl.innerHTML = enabled
      ? '<span style="background:#DCFCE7;color:#166534;padding:3px 10px;border-radius:999px">✅ Aktivní</span>'
      : '<span style="background:#F3F4F6;color:#6B7280;padding:3px 10px;border-radius:999px">⚫ Vypnuto</span>';
  }
  const formEl = document.getElementById(cfg.formEl);
  if (!formEl) return;

  // Dvouřádkový renderer pro fields (col: full / half)
  const rows = [];
  let halfBuffer = null;
  for (const f of cfg.fields) {
    if (f.col === 'half') {
      if (halfBuffer) { rows.push([halfBuffer, f]); halfBuffer = null; }
      else halfBuffer = f;
    } else {
      if (halfBuffer) { rows.push([halfBuffer]); halfBuffer = null; }
      rows.push([f]);
    }
  }
  if (halfBuffer) rows.push([halfBuffer]);

  const fieldHtml = (f) => {
    const fullKey = 'int_' + svc + '_' + f.key;
    const id = 'int-' + svc + '-' + f.key;
    const val = data[fullKey] || '';
    const isPwdSet = data[fullKey + '_set'] === true;
    if (f.type === 'select') {
      return `<label style="display:block">
        <span class="lbl" style="font-size:11px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:0.4px;margin-bottom:3px;display:block">${esc(f.label)}</span>
        <select id="${id}" class="form-input" style="font-size:13px;padding:6px 8px">
          ${f.options.map(([v, l]) => `<option value="${v}" ${val === v ? 'selected' : ''}>${esc(l)}</option>`).join('')}
        </select>
      </label>`;
    }
    return `<label style="display:block">
      <span class="lbl" style="font-size:11px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:0.4px;margin-bottom:3px;display:block">${esc(f.label)}${isPwdSet ? ' <small style="color:#16A34A;font-weight:500;text-transform:none;letter-spacing:0">(uloženo)</small>' : ''}</span>
      <input id="${id}" type="${f.type}" class="form-input" placeholder="${esc(f.placeholder || '')}" value="${esc(val.includes('•') ? '' : val)}" style="font-size:13px;padding:6px 8px">
    </label>`;
  };

  formEl.innerHTML = `
    ${rows.map(row => row.length === 2
      ? `<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">${row.map(fieldHtml).join('')}</div>`
      : fieldHtml(row[0])).join('')}

    <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;cursor:pointer;padding:6px 0;margin-top:4px;border-top:1px dashed var(--border)">
      <input type="checkbox" id="int-${svc}-enabled" ${enabled ? 'checked' : ''} style="width:18px;height:18px;accent-color:${cfg.color}">
      Povolit ${esc(cfg.label)}
    </label>

    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <button class="btn-primary" onclick="saveIntCard('${svc}')" style="flex:1;font-size:12px;padding:8px;background:${cfg.color}">💾 Uložit</button>
      <button class="btn-secondary" onclick="testIntCard('${svc}')" style="font-size:12px;padding:8px">🔌 Test</button>
    </div>
    <a href="${esc(cfg.docs)}" target="_blank" style="font-size:11px;color:var(--text-3);text-decoration:none;text-align:center;padding:4px 0">${esc(cfg.docsLabel)} ↗</a>
  `;
}

window.saveIntCard = async function(svc) {
  const cfg = INT_CARDS[svc];
  if (!cfg) return;
  const body = {
    enabled: document.getElementById('int-' + svc + '-enabled').checked ? '1' : '0',
  };
  for (const f of cfg.fields) {
    const el = document.getElementById('int-' + svc + '-' + f.key);
    if (!el) continue;
    body[f.key] = el.value;
  }
  try {
    await api('admin_integrace.php?action=save_settings&service=' + svc, {
      method: 'POST', body: JSON.stringify(body),
    });
    toastSuccess('✅ ' + cfg.label + ' uloženo');
    loadIntCard(svc, cfg);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.testIntCard = async function(svc) {
  const cfg = INT_CARDS[svc];
  toastSuccess('⏳ Testuji ' + cfg.label + '…');
  try {
    const r = await api('admin_integrace.php?action=test&service=' + svc);
    if (r.ok) alert('✅ ' + cfg.label + ': ' + (r.message || 'Spojení OK'));
    else alert('❌ ' + cfg.label + ': ' + (r.error || 'Selhalo'));
  } catch (e) { alert('❌ ' + e.message); }
};

// 🆕 v2.9.3 — loadUcetniIntegrace() byla volaná ale ne definovaná → "⏳" stuck
// Wrapper který načte oba (POHODA + FlexiBee) paralelně
async function loadUcetniIntegrace() {
  // ISDOC card je staticky vyrendrovaný v blokUcetni (offline = funkční offline export)
  // Načítáme POHODA + FlexiBee config asynchronně
  await Promise.allSettled([
    loadPohodaConfig(),
    loadFlexibeeConfig(),
  ]);
}
window.loadUcetniIntegrace = loadUcetniIntegrace;

async function loadPohodaConfig() {
  const cfg = await api('admin_pohoda.php?action=settings').catch(() => ({}));
  const enabled = cfg.pohoda_enabled === '1';
  const statusEl = document.getElementById('ns-pohoda-status');
  if (statusEl) {
    statusEl.innerHTML = enabled
      ? '<span style="background:#DCFCE7;color:#166534;padding:4px 12px;border-radius:999px">✅ Aktivní</span>'
      : '<span style="background:#F3F4F6;color:#6B7280;padding:4px 12px;border-radius:999px">⚫ Neaktivní</span>';
  }
  const host = document.getElementById('ns-pohoda-config');
  if (!host) return;
  host.innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <label><span class="lbl">URL mServeru</span>
        <input id="pohoda-url" class="form-input" placeholder="http://192.168.1.100:444" value="${esc(cfg.pohoda_url || '')}">
      </label>
      <label><span class="lbl">IČO firmy (v Pohoda)</span>
        <input id="pohoda-ico" class="form-input" placeholder="12345678" value="${esc(cfg.pohoda_ico || '')}">
      </label>
      <label><span class="lbl">Uživatel</span>
        <input id="pohoda-username" class="form-input" placeholder="Admin" value="${esc(cfg.pohoda_username || '')}">
      </label>
      <label><span class="lbl">Heslo ${cfg.pohoda_password_set ? '<small style="color:#16A34A">(uloženo — vyplň jen pro změnu)</small>' : ''}</span>
        <input id="pohoda-password" type="password" class="form-input" placeholder="${cfg.pohoda_password_set ? '••••••••' : ''}">
      </label>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
      <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;cursor:pointer">
        <input type="checkbox" id="pohoda-enabled" ${enabled ? 'checked' : ''} style="width:18px;height:18px">
        Povolit POHODA mServer
      </label>
      <button class="btn-primary" onclick="savePohodaConfig()" style="margin-left:auto">💾 Uložit</button>
      <button class="btn-secondary" onclick="testPohodaConnection()">🔌 Test spojení</button>
      <a class="btn-secondary" href="../api/admin_pohoda.php?action=audit_log" target="_blank" style="text-decoration:none">📋 Log</a>
    </div>
    <details style="margin-top:10px">
      <summary style="cursor:pointer;color:var(--text-3);font-size:13px">ℹ️ Jak nastavit POHODA mServer?</summary>
      <div style="font-size:12.5px;color:var(--text-2);padding:8px 0;line-height:1.6">
        <ol style="padding-left:20px">
          <li>Otevři Pohodu na počítači kde běží účetnictví</li>
          <li>Menu <strong>Soubor → mServer</strong> → Konfigurace → zapni "Spustit mServer"</li>
          <li>Nastav port (default 444) a uživatele s heslem</li>
          <li>Pokud APPEK běží na cloudu, otevři port 444 ve firewallu / VPN</li>
          <li>Zde zadej <code>http://&lt;IP-PC&gt;:444</code>, IČO, uživatele a heslo → 🔌 Test spojení</li>
        </ol>
        <p>📖 Plná dokumentace: <a href="https://www.stormware.cz/pohoda/xml/dokumentace/" target="_blank">stormware.cz/pohoda/xml/dokumentace</a></p>
      </div>
    </details>
  `;
}

window.savePohodaConfig = async function() {
  const body = {
    enabled: document.getElementById('pohoda-enabled').checked ? '1' : '0',
    url: document.getElementById('pohoda-url').value.trim(),
    ico: document.getElementById('pohoda-ico').value.trim(),
    username: document.getElementById('pohoda-username').value.trim(),
    password: document.getElementById('pohoda-password').value,
  };
  try {
    await api('admin_pohoda.php?action=save_settings', { method: 'POST', body: JSON.stringify(body) });
    toastSuccess('✅ POHODA konfigurace uložena');
    loadPohodaConfig();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.testPohodaConnection = async function() {
  toastSuccess('⏳ Testuji spojení…');
  try {
    const r = await api('admin_pohoda.php?action=test');
    if (r.ok) {
      alert('✅ ' + (r.message || 'Spojení OK'));
    } else {
      alert('❌ ' + (r.error || 'Selhalo'));
    }
  } catch (e) { alert('❌ ' + e.message); }
};

async function loadFlexibeeConfig() {
  const cfg = await api('admin_flexibee.php?action=settings').catch(() => ({}));
  const enabled = cfg.flexibee_enabled === '1';
  const statusEl = document.getElementById('ns-fbee-status');
  if (statusEl) {
    statusEl.innerHTML = enabled
      ? '<span style="background:#DCFCE7;color:#166534;padding:4px 12px;border-radius:999px">✅ Aktivní</span>'
      : '<span style="background:#F3F4F6;color:#6B7280;padding:4px 12px;border-radius:999px">⚫ Neaktivní</span>';
  }
  const host = document.getElementById('ns-fbee-config');
  if (!host) return;
  host.innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <label><span class="lbl">URL serveru</span>
        <input id="fbee-url" class="form-input" placeholder="https://moje-firma.flexibee.eu:5434" value="${esc(cfg.flexibee_url || '')}">
      </label>
      <label><span class="lbl">Kód firmy (company slug)</span>
        <input id="fbee-company" class="form-input" placeholder="moje_firma_s_r_o" value="${esc(cfg.flexibee_company || '')}">
      </label>
      <label><span class="lbl">Uživatel</span>
        <input id="fbee-username" class="form-input" placeholder="admin" value="${esc(cfg.flexibee_username || '')}">
      </label>
      <label><span class="lbl">Heslo ${cfg.flexibee_password_set ? '<small style="color:#16A34A">(uloženo — vyplň jen pro změnu)</small>' : ''}</span>
        <input id="fbee-password" type="password" class="form-input" placeholder="${cfg.flexibee_password_set ? '••••••••' : ''}">
      </label>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
      <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;cursor:pointer">
        <input type="checkbox" id="fbee-enabled" ${enabled ? 'checked' : ''} style="width:18px;height:18px">
        Povolit FlexiBee REST
      </label>
      <button class="btn-primary" onclick="saveFlexibeeConfig()" style="margin-left:auto">💾 Uložit</button>
      <button class="btn-secondary" onclick="testFlexibeeConnection()">🔌 Test spojení</button>
      <a class="btn-secondary" href="../api/admin_flexibee.php?action=audit_log" target="_blank" style="text-decoration:none">📋 Log</a>
    </div>
    <details style="margin-top:10px">
      <summary style="cursor:pointer;color:var(--text-3);font-size:13px">ℹ️ Jak nastavit FlexiBee REST?</summary>
      <div style="font-size:12.5px;color:var(--text-2);padding:8px 0;line-height:1.6">
        <ol style="padding-left:20px">
          <li>Přihlas se do FlexiBee (cloud nebo on-prem) jako administrátor</li>
          <li>V URL prohlížeče vidíš <code>https://server.flexibee.eu:5434/c/<strong>kod_firmy</strong>/...</code> — kód firmy je <strong>company slug</strong></li>
          <li>Vytvoř service-user s rolí pro REST API přístup (Nastavení → Uživatelé)</li>
          <li>Zde zadej URL bez /c/firma, kód firmy zvlášť, uživatele + heslo → 🔌 Test spojení</li>
        </ol>
        <p>📖 Dokumentace: <a href="https://demo.flexibee.eu/devdoc/" target="_blank">demo.flexibee.eu/devdoc</a></p>
      </div>
    </details>
  `;
}

window.saveFlexibeeConfig = async function() {
  const body = {
    enabled: document.getElementById('fbee-enabled').checked ? '1' : '0',
    url: document.getElementById('fbee-url').value.trim(),
    company: document.getElementById('fbee-company').value.trim(),
    username: document.getElementById('fbee-username').value.trim(),
    password: document.getElementById('fbee-password').value,
  };
  try {
    await api('admin_flexibee.php?action=save_settings', { method: 'POST', body: JSON.stringify(body) });
    toastSuccess('✅ FlexiBee konfigurace uložena');
    loadFlexibeeConfig();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.testFlexibeeConnection = async function() {
  toastSuccess('⏳ Testuji spojení…');
  try {
    const r = await api('admin_flexibee.php?action=test');
    if (r.ok) {
      alert('✅ ' + (r.message || 'Spojení OK'));
    } else {
      alert('❌ ' + (r.error || 'Selhalo'));
    }
  } catch (e) { alert('❌ ' + e.message); }
};

