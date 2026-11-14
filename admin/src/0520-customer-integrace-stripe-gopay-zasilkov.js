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

// 🐛 v3.0.640 — loadUcetniIntegrace / POHODA mServer / FlexiBee REST karty odstraněny (backend exportu nikdy
//   nefungoval; podporovaná cesta = ISDOC export ve Fakturách). Kód je v git historii (≤ v3.0.639).
