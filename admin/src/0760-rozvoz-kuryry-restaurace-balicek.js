// =============================================================
// 🛵 ROZVOZ / KURÝRY (Restaurace balíček)
// =============================================================
async function renderCouriers() {
  const body = document.getElementById('rest-tab-body');
  if (!body) return;
  body.innerHTML = skeletonCards(3);
  let data;
  try { data = await api('admin_couriers.php'); }
  catch (e) { body.innerHTML = `<div class="alert err">${esc(e.message)}</div>`; return; }

  const s = data.stats || {};
  const stavLabel = {
    'naplanovano': {bg:'#DBEAFE',fg:'#1E40AF',lbl:'📋 Naplánováno'},
    'vyzvednuto':  {bg:'#FEF3C7',fg:'#92400E',lbl:'📦 Vyzvednuto'},
    'na_ceste':    {bg:'#FED7AA',fg:'#9A3412',lbl:'🛵 Na cestě'},
    'doruceno':    {bg:'#DCFCE7',fg:'#166534',lbl:'✅ Doručeno'},
    'zruseno':     {bg:'#FEE2E2',fg:'#991B1B',lbl:'🚫 Zrušeno'},
  };
  const sluzbyMeta = {
    'wolt':       { ikona:'🟢', name:'Wolt',       color:'#00C2E8' },
    'bolt':       { ikona:'🟩', name:'Bolt Food',  color:'#34D186' },
    'dame_jidlo': { ikona:'🟧', name:'Dáme jídlo', color:'#F26430' },
    'foodora':    { ikona:'🩷', name:'Foodora',    color:'#D70F64' },
  };

  body.innerHTML = `
    <div class="card-block" style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <div style="display:flex;gap:16px;flex-wrap:wrap">
        <div><strong style="font-size:20px">${s.kuryru_aktivnich || 0}</strong> <span style="color:var(--text-3);font-size:12px">aktivních kurýrů</span></div>
        <div><strong style="font-size:20px;color:#1E40AF">${s.rozvozy_aktivni || 0}</strong> <span style="color:var(--text-3);font-size:12px">probíhajících</span></div>
        <div><strong style="font-size:20px;color:#166534">${s.rozvozy_dnes_doruceno || 0}</strong> <span style="color:var(--text-3);font-size:12px">dnes doručeno</span></div>
      </div>
      <button class="btn-primary btn-green" onclick="courierEdit(0)">+ Nový kurýr</button>
    </div>

    ${(data.deliveries || []).length > 0 ? `
      <div class="card-block" style="margin-bottom:14px">
        <h3 style="margin:0 0 10px;font-size:15px">🛵 Aktuálně na cestě (${data.deliveries.length})</h3>
        <div style="display:flex;flex-direction:column;gap:6px">
          ${data.deliveries.map(d => {
            const c = stavLabel[d.stav] || stavLabel.naplanovano;
            return `
              <div style="display:grid;grid-template-columns:auto 1fr auto auto;gap:10px;align-items:center;background:var(--surface-2);padding:8px 12px;border-radius:8px;border-left:4px solid ${d.courier_barva || '#10B981'}">
                <span style="font-size:18px">${d.courier_ikona || '🛵'}</span>
                <div>
                  <strong style="font-size:13.5px">${esc(d.courier_jmeno)}</strong>
                  <span style="font-size:11.5px;color:var(--text-3)"> · ${esc(d.adresa)}${d.mesto ? ', ' + esc(d.mesto) : ''}</span>
                  ${d.kontakt_jmeno ? `<div style="font-size:11px;color:var(--text-3)">👤 ${esc(d.kontakt_jmeno)} ${d.kontakt_telefon ? '· 📞 ' + esc(d.kontakt_telefon) : ''}</div>` : ''}
                  ${d.cas_planovany ? `<div style="font-size:11px;color:var(--text-3)">🕐 Plánováno: ${d.cas_planovany.slice(0,5)}</div>` : ''}
                </div>
                <span style="background:${c.bg};color:${c.fg};padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:700;white-space:nowrap">${c.lbl}</span>
                <div style="display:flex;gap:4px">
                  ${d.stav === 'naplanovano' ? `<button class="btn-primary" style="font-size:11px;padding:4px 8px" onclick="courierDelivStatus(${d.id}, 'vyzvednuto')">📦 Vyzvedl</button>` : ''}
                  ${d.stav === 'vyzvednuto' ? `<button class="btn-primary" style="font-size:11px;padding:4px 8px" onclick="courierDelivStatus(${d.id}, 'na_ceste')">🛵 Na cestě</button>` : ''}
                  ${d.stav === 'na_ceste' ? `<button class="btn-primary btn-green" style="font-size:11px;padding:4px 8px" onclick="courierDelivStatus(${d.id}, 'doruceno')">✅ Doručeno</button>` : ''}
                </div>
              </div>
            `;
          }).join('')}
        </div>
      </div>
    ` : ''}

    <div class="card-block" style="margin-bottom:14px">
      <h3 style="margin:0 0 12px;font-size:15px">👥 Kurýři / Řidiči (${(data.couriers || []).length})</h3>
      ${(data.couriers || []).length === 0 ? `
        <div style="text-align:center;padding:20px;color:var(--text-3);font-size:13px">
          🛵 Žádní kurýři. Přidej vlastního řidiče nebo nastav integraci níže.
        </div>
      ` : `
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px">
          ${data.couriers.map(c => `
            <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px;border-left:4px solid ${c.barva}">
              <div style="display:flex;justify-content:space-between;align-items:start;gap:8px">
                <div style="min-width:0">
                  <strong style="font-size:14px;display:flex;align-items:center;gap:6px">
                    ${c.ikona} ${esc(c.jmeno)}
                    ${!parseInt(c.aktivni) ? '<span style="background:#FEE2E2;color:#991B1B;padding:1px 6px;border-radius:999px;font-size:10px;font-weight:600">Neaktivní</span>' : ''}
                  </strong>
                  ${c.externi ? `<div style="font-size:11px;color:var(--text-3);font-weight:600;text-transform:uppercase">${esc(c.externi_sluzba)}</div>` : ''}
                  ${c.telefon ? `<div style="font-size:11.5px;margin-top:4px">📞 <a href="tel:${esc(c.telefon)}">${esc(c.telefon)}</a></div>` : ''}
                  ${c.vozidlo ? `<div style="font-size:11.5px;color:var(--text-3)">🚗 ${esc(c.vozidlo)}${c.spz ? ' · ' + esc(c.spz) : ''}</div>` : ''}
                  ${c.zona_obslazi ? `<div style="font-size:11px;color:var(--text-3)">📍 ${esc(c.zona_obslazi)}</div>` : ''}
                </div>
                <button class="btn-secondary" style="font-size:11px;padding:4px 8px" onclick="courierEdit(${c.id})">✏️</button>
              </div>
              <div style="display:flex;gap:8px;margin-top:8px;font-size:11px;color:var(--text-3)">
                <span>⏳ ${c.aktivni_pocet || 0} aktivní</span>
                <span>📅 ${c.dnes_doruceno || 0} dnes</span>
                <span>📊 ${c.celkem_doruceno || 0} celkem</span>
              </div>
              ${parseFloat(c.provize_pct) > 0 ? `<div style="font-size:11px;color:var(--text-3);margin-top:4px">🧾 Provize: ${c.provize_pct}%</div>` : ''}
            </div>
          `).join('')}
        </div>
      `}
    </div>

    <div class="card-block">
      <h3 style="margin:0 0 12px;font-size:15px">🔌 Integrace s externími službami</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px">
        ${(data.integrations || []).map(i => {
          const m = sluzbyMeta[i.sluzba] || { ikona:'🔌', name:i.sluzba, color:'#888' };
          const on = parseInt(i.povolena) === 1;
          return `
            <div style="background:${on ? m.color + '15' : 'var(--surface)'};border:2px solid ${on ? m.color : 'var(--border)'};border-radius:10px;padding:12px;cursor:pointer" onclick="courierIntegrationEdit('${i.sluzba}')">
              <div style="display:flex;justify-content:space-between;align-items:center">
                <strong style="font-size:14px">${m.ikona} ${m.name}</strong>
                <span style="background:${on ? '#DCFCE7' : '#E5E7EB'};color:${on ? '#166534' : '#374151'};padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:700">${on ? '✓ Aktivní' : 'Vypnuto'}</span>
              </div>
              <div style="font-size:11.5px;color:var(--text-3);margin-top:6px">
                Provize: ${i.provize_pct || 0}%
                ${i.store_id ? `<br>Store: ${esc(i.store_id)}` : ''}
              </div>
            </div>
          `;
        }).join('')}
      </div>
      <div style="font-size:11.5px;color:var(--text-3);margin-top:10px;line-height:1.6">
        💡 Klikni na službu pro nastavení API klíče + webhook URL. <strong style="color:#065F46">✅ Live integrace</strong> (v3.0.38+): auto-příjem objednávek, status sync, menu push, HMAC signature ověření. <em>Vyžaduje partner credentials od dané služby.</em>
      </div>
    </div>
  `;
}

window.courierEdit = async function(id) {
  let c = { id:0, jmeno:'', telefon:'', email:'', vozidlo:'', spz:'', zona_obslazi:'',
            provize_pct:0, externi:0, externi_sluzba:'vlastni', aktivni:1, barva:'#10B981', ikona:'🛵', poznamka:'' };
  if (id) {
    try {
      const data = await api('admin_couriers.php');
      const found = (data.couriers || []).find(x => x.id === id);
      if (found) c = found;
    } catch (e) {}
  }
  openModal(id ? `✏️ Kurýr ${esc(c.jmeno)}` : '+ Nový kurýr', `
    <div class="form-grid form-grid-tight">
      <div><label class="form-label">Ikona</label>
        <input class="form-input" id="cr-ikona" value="${esc(c.ikona)}" style="font-size:24px;text-align:center" maxlength="2">
      </div>
      <div><label class="form-label">Barva</label>
        <input type="color" class="form-input" id="cr-barva" value="${esc(c.barva || '#10B981')}" style="padding:4px;height:38px">
      </div>
      <div class="full"><label class="form-label">Jméno *</label>
        <input class="form-input" id="cr-jmeno" value="${esc(c.jmeno)}" placeholder="Jan Novák">
      </div>
      <div><label class="form-label">📞 Telefon</label>
        <input class="form-input" id="cr-tel" value="${esc(c.telefon || '')}" placeholder="+420 ...">
      </div>
      <div><label class="form-label">📧 E-mail</label>
        <input class="form-input" id="cr-email" value="${esc(c.email || '')}">
      </div>
      <div><label class="form-label">🚗 Vozidlo</label>
        <input class="form-input" id="cr-voz" value="${esc(c.vozidlo || '')}" placeholder="např. Škoda Fabia">
      </div>
      <div><label class="form-label">SPZ</label>
        <input class="form-input" id="cr-spz" value="${esc(c.spz || '')}" placeholder="1AB 2345">
      </div>
      <div class="full"><label class="form-label">📍 Obsluhovaná zóna</label>
        <input class="form-input" id="cr-zona" value="${esc(c.zona_obslazi || '')}" placeholder="Praha 1, 2, 3...">
      </div>
      <div><label class="form-label">🧾 Provize (%)</label>
        <input type="number" class="form-input" id="cr-prov" value="${c.provize_pct || 0}" step="0.5">
      </div>
      <div><label class="form-label">Typ</label>
        <select class="form-input" id="cr-typ" onchange="document.getElementById('cr-sluzba').disabled=this.value==='vlastni'">
          <option value="vlastni" ${!parseInt(c.externi) ? 'selected' : ''}>🏠 Vlastní řidič</option>
          <option value="externi" ${parseInt(c.externi) ? 'selected' : ''}>🔌 Externí služba</option>
        </select>
      </div>
      <div class="full"><label class="form-label">Externí služba</label>
        <select class="form-input" id="cr-sluzba" ${!parseInt(c.externi) ? 'disabled' : ''}>
          ${['vlastni','wolt','bolt','dame_jidlo','foodora','jiny'].map(s => `<option value="${s}" ${c.externi_sluzba===s ? 'selected' : ''}>${s}</option>`).join('')}
        </select>
      </div>
      <div class="full">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" id="cr-ak" ${parseInt(c.aktivni) ? 'checked' : ''}>
          <span>Aktivní (dostupný pro nové rozvozy)</span>
        </label>
      </div>
      <div class="full"><label class="form-label">Poznámka</label>
        <textarea class="form-input" id="cr-pozn" rows="2">${esc(c.poznamka || '')}</textarea>
      </div>
    </div>
    <div class="form-actions" style="justify-content:space-between">
      ${id ? `<button class="btn-secondary" style="color:#dc2626" onclick="courierDelete(${id})">🗑️ Smazat</button>` : '<div></div>'}
      <div style="display:flex;gap:8px">
        <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
        <button class="btn-primary btn-green" onclick="courierSave(${id})">💾 Uložit</button>
      </div>
    </div>
  `);
};

window.courierSave = async function(id) {
  const jmeno = document.getElementById('cr-jmeno').value.trim();
  if (!jmeno) { alert('Vyplň jméno'); return; }
  try {
    await api('admin_couriers.php?action=courier', { method:'POST', body: JSON.stringify({
      id: id || 0, jmeno,
      telefon: document.getElementById('cr-tel').value.trim() || null,
      email: document.getElementById('cr-email').value.trim() || null,
      vozidlo: document.getElementById('cr-voz').value.trim() || null,
      spz: document.getElementById('cr-spz').value.trim() || null,
      zona_obslazi: document.getElementById('cr-zona').value.trim() || null,
      provize_pct: parseFloat(document.getElementById('cr-prov').value) || 0,
      externi: document.getElementById('cr-typ').value === 'externi' ? 1 : 0,
      externi_sluzba: document.getElementById('cr-sluzba').value || 'vlastni',
      aktivni: document.getElementById('cr-ak').checked ? 1 : 0,
      barva: document.getElementById('cr-barva').value || '#10B981',
      ikona: document.getElementById('cr-ikona').value.trim() || '🛵',
      poznamka: document.getElementById('cr-pozn').value.trim() || null,
    })});
    closeModal();
    toastSuccess(id ? 'Kurýr upraven' : 'Kurýr přidán');
    renderCouriers();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.courierDelete = async function(id) {
  if (!await customConfirm('Smazat kurýra?', 'Pokud má aktivní rozvozy, nejprve je dokonči/zruš.', 'Smazat')) return;
  try {
    await api('admin_couriers.php?action=courier&id=' + id, { method:'DELETE' });
    closeModal();
    toastSuccess('Kurýr smazán');
    renderCouriers();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.courierDelivStatus = async function(id, stav) {
  try {
    await api('admin_couriers.php?action=delivery_status', { method:'POST', body: JSON.stringify({ id, stav })});
    renderCouriers();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.courierIntegrationEdit = async function(sluzba) {
  let cur = {};
  let webhookUrl = '';
  try {
    const data = await api('admin_couriers.php');
    cur = (data.integrations || []).find(x => x.sluzba === sluzba) || {};
  } catch (e) {}
  try {
    const wh = await api('admin_couriers.php?action=webhook_urls');
    webhookUrl = (wh.urls || {})[sluzba] || '';
  } catch (e) {}

  // 🆕 v3.0.38 — Per-service portal info (kde vzít klíče, kde nastavit webhook)
  const sluzbyInfo = {
    wolt: {
      name: 'Wolt',
      color: '#00C2E8',
      portal: 'https://merchant.wolt.com',
      docsUrl: 'https://developer.wolt.com/docs/api/order-api',
      keyLabel: 'API Key (Bearer token)',
      storeLabel: 'Venue ID',
      help: 'Z Merchant portalu: Settings → Integrations → Generate API Key. Venue ID najdeš v Venue Settings.',
      webhookHeader: 'X-Wolt-Signature',
    },
    bolt: {
      name: 'Bolt Food',
      color: '#34D186',
      portal: 'https://partners.bolt.eu/food',
      docsUrl: 'https://partners.bolt.eu/food',
      keyLabel: 'X-Auth-Token',
      storeLabel: 'Provider ID',
      help: 'Bolt partner manager ti pošle credentials po podpisu smlouvy. Provider ID = tvoje restaurace v jejich systému.',
      webhookHeader: 'X-Bolt-Signature',
    },
    dame_jidlo: {
      name: 'Dáme jídlo',
      color: '#F26430',
      portal: 'https://restaurace.damejidlo.cz',
      docsUrl: 'https://restaurace.damejidlo.cz',
      keyLabel: 'Bearer token',
      storeLabel: 'Restaurant ID',
      help: 'Restaurátorský portál → Nastavení → API přístup → Generovat token.',
      webhookHeader: 'X-DameJidlo-Signature',
    },
    foodora: {
      name: 'Foodora',
      color: '#D70F64',
      portal: 'https://vendor.delivery-hero.com',
      docsUrl: 'https://docs.deliveryhero.com',
      keyLabel: 'Bearer token',
      storeLabel: 'Vendor Code',
      help: 'Delivery Hero portal → API & Webhooks → vygeneruj credentials. Vendor Code dostaneš od account managera.',
      webhookHeader: 'X-DH-Signature',
    },
  };
  const info = sluzbyInfo[sluzba] || { name: sluzba, color:'#888', portal:'', docsUrl:'', keyLabel:'API klíč', storeLabel:'Store ID', help:'' };
  const isEnabled = parseInt(cur.povolena) === 1;

  openModal(`🔌 Integrace: ${info.name}`, `
    <!-- Live status badge -->
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;padding:10px 14px;background:${isEnabled ? '#DCFCE7' : '#F3F4F6'};border:1.5px solid ${isEnabled ? '#10B981' : '#9CA3AF'};border-radius:10px">
      <span style="font-size:24px">${isEnabled ? '🟢' : '⚪'}</span>
      <div style="flex:1">
        <strong style="color:${isEnabled ? '#065F46' : '#374151'}">${isEnabled ? 'Live integrace povolená' : 'Integrace vypnutá'}</strong>
        <div style="font-size:11.5px;color:${isEnabled ? '#047857' : '#6B7280'};margin-top:2px">
          ${isEnabled ? 'Webhook bude přijímat objednávky · status sync zapnutý' : 'Klíče uložené, ale neaktivní'}
        </div>
      </div>
      <button id="ci-test-btn" class="btn-secondary" onclick="courierTestIntegration('${sluzba}')" style="padding:8px 14px;font-weight:700;background:${info.color};color:#fff;border:none;border-radius:8px;cursor:pointer">
        🔌 Test
      </button>
    </div>

    <!-- Help banner (kde vzít klíče) -->
    <div style="background:#EFF6FF;border-left:3px solid #3B82F6;padding:10px 14px;border-radius:8px;font-size:12.5px;color:#1E3A8A;margin-bottom:14px;line-height:1.55">
      💡 ${esc(info.help)}<br>
      <a href="${esc(info.portal)}" target="_blank" rel="noopener" style="color:#1D4ED8;font-weight:600">📂 Portal služby →</a>
      &nbsp;·&nbsp;
      <a href="${esc(info.docsUrl)}" target="_blank" rel="noopener" style="color:#1D4ED8;font-weight:600">📖 API docs →</a>
    </div>

    <!-- Credentials -->
    <div class="form-grid form-grid-tight">
      <div class="full">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;padding:10px;background:var(--surface-2);border-radius:8px">
          <input type="checkbox" id="ci-on" ${isEnabled ? 'checked' : ''} style="transform:scale(1.3)">
          <span><strong>✓ Povolit integraci</strong> <small style="color:var(--text-3)">— webhook + status sync</small></span>
        </label>
      </div>
      <div><label class="form-label">🧾 Provize služby (%)</label>
        <input type="number" class="form-input" id="ci-prov" value="${cur.provize_pct || 0}" step="0.5">
      </div>
      <div><label class="form-label">${esc(info.storeLabel)}</label>
        <input class="form-input" id="ci-store" value="${esc(cur.store_id || '')}" placeholder="z portálu">
      </div>
      <div class="full"><label class="form-label">🔑 ${esc(info.keyLabel)}</label>
        <input class="form-input" id="ci-key" type="password" value="${esc(cur.api_key || '')}" placeholder="${sluzba === 'wolt' ? 'wlt_xxxxxxxxxxxxxxxxxxxxxxxx' : 'pošle ti partner manager'}" autocomplete="new-password">
        <small style="display:block;font-size:11px;color:var(--text-3);margin-top:4px">Klíč je v DB šifrovaný heslem databáze. <strong>Slouží taky jako webhook secret</strong> (pro HMAC ověření).</small>
      </div>
    </div>

    <!-- Webhook URL (kterou user vloží do portalu) -->
    ${webhookUrl ? `
      <div style="margin-top:16px;padding:14px;background:linear-gradient(135deg,#F0F9FF,#DBEAFE);border:1.5px solid #3B82F6;border-radius:10px">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
          <strong style="color:#1E40AF;font-size:13px">🔗 Webhook URL pro ${info.name} portal:</strong>
        </div>
        <div style="display:flex;gap:6px">
          <input type="text" readonly value="${esc(webhookUrl)}" id="ci-webhook-url"
            style="flex:1;padding:8px 10px;font-family:monospace;font-size:12px;background:#fff;border:1px solid #93C5FD;border-radius:6px;color:#1E40AF"
            onclick="this.select()">
          <button class="btn-secondary" onclick="navigator.clipboard.writeText(document.getElementById('ci-webhook-url').value);toastSuccess('📋 Zkopírováno')" style="padding:8px 14px;font-size:12px">📋 Kopírovat</button>
        </div>
        <div style="font-size:11px;color:#1E3A8A;margin-top:6px;line-height:1.5">
          Vlož do <strong>${esc(info.name)} portal → Webhooks/Notifications</strong> jako endpoint pro nové objednávky.
          Signature header: <code style="background:#fff;padding:2px 6px;border-radius:4px;font-size:11px">${esc(info.webhookHeader)}</code>.
        </div>
      </div>
    ` : ''}

    <!-- Pokročilé akce -->
    <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn-secondary" onclick="courierSyncMenu('${sluzba}')" style="font-size:12px;padding:8px 14px;background:#FEF3C7;border-color:#F59E0B;color:#92400E">
        📤 Sync menu (push náš katalog)
      </button>
      <button class="btn-secondary" onclick="courierWebhookLog('${sluzba}')" style="font-size:12px;padding:8px 14px">
        📋 Webhook log
      </button>
    </div>

    <!-- Status pole pro výsledky test/sync -->
    <div id="ci-result" style="margin-top:12px;display:none;padding:10px;border-radius:8px;font-size:13px"></div>

    <!-- v3.0.38 — Updated text: live integrace funguje, ne plánovaná -->
    <div style="background:#DCFCE7;padding:10px 12px;border-radius:8px;font-size:11.5px;color:#065F46;margin:14px 0 0;line-height:1.6">
      ✅ <strong>Live integrace aktivní</strong> (v3.0.38+): auto-příjem objednávek, status sync, menu push, HMAC signature ověření.
      Potřebuješ partner credentials od ${esc(info.name)} (typicky se vydávají po podpisu smlouvy).
    </div>

    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="courierIntegrationSave('${sluzba}')">💾 Uložit</button>
    </div>
  `);
};

// 🆕 v3.0.38 — Test integration
window.courierTestIntegration = async function(sluzba) {
  const btn = document.getElementById('ci-test-btn');
  const result = document.getElementById('ci-result');
  if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Testuji…'; }
  try {
    // Nejdřív uložit (uživatel zadal credentials co ještě nejsou v DB)
    await api('admin_couriers.php?action=integration', { method:'POST', body: JSON.stringify({
      sluzba,
      povolena: document.getElementById('ci-on')?.checked ? 1 : 0,
      provize_pct: parseFloat(document.getElementById('ci-prov')?.value) || 0,
      store_id: document.getElementById('ci-store')?.value.trim() || null,
      api_key: document.getElementById('ci-key')?.value.trim() || null,
    })});
    const r = await api('admin_couriers.php?action=test_integration', { method:'POST', body: JSON.stringify({ sluzba })});
    if (result) {
      result.style.display = 'block';
      result.style.background = r.ok ? '#DCFCE7' : '#FEE2E2';
      result.style.border = '1px solid ' + (r.ok ? '#10B981' : '#DC2626');
      result.style.color = r.ok ? '#065F46' : '#991B1B';
      result.innerHTML = `<strong>${r.ok ? '✅' : '❌'} ${esc(r.message || 'OK')}</strong>${r.details ? '<pre style="font-size:11px;margin:8px 0 0;max-height:120px;overflow:auto;background:rgba(0,0,0,0.05);padding:6px;border-radius:4px">' + esc(JSON.stringify(r.details, null, 2).slice(0, 800)) + '</pre>' : ''}`;
    }
  } catch (e) {
    if (result) {
      result.style.display = 'block';
      result.style.background = '#FEE2E2';
      result.style.color = '#991B1B';
      result.innerHTML = '❌ Chyba: ' + esc(e.message);
    }
  } finally {
    if (btn) { btn.disabled = false; btn.innerHTML = '🔌 Test'; }
  }
};

// 🆕 v3.0.38 — Sync menu
window.courierSyncMenu = async function(sluzba) {
  if (!(await confirmDialog({ msg: 'Pošle všechny tvoje restaurační výrobky do ' + sluzba + ' katalogu. Pokračovat?', danger: false }))) return;
  const result = document.getElementById('ci-result');
  if (result) { result.style.display = 'block'; result.style.background = '#FEF3C7'; result.style.color = '#78350F'; result.innerHTML = '⏳ Sync menu…'; }
  try {
    const r = await api('admin_couriers.php?action=sync_menu', { method:'POST', body: JSON.stringify({ sluzba })});
    if (result) {
      result.style.background = r.ok ? '#DCFCE7' : '#FEE2E2';
      result.style.color = r.ok ? '#065F46' : '#991B1B';
      result.style.border = '1px solid ' + (r.ok ? '#10B981' : '#DC2626');
      result.innerHTML = `<strong>${r.ok ? '✅' : '❌'} ${esc(r.message || (r.ok ? 'Menu synchronizováno' : 'Sync selhal'))}</strong>${r.items_count ? `<br><small>Položek: ${r.items_count}</small>` : ''}`;
    }
  } catch (e) {
    if (result) { result.style.background = '#FEE2E2'; result.style.color = '#991B1B'; result.innerHTML = '❌ ' + esc(e.message); }
  }
};

// 🆕 v3.0.38 — Webhook log viewer
window.courierWebhookLog = async function(sluzba) {
  try {
    const r = await api('admin_couriers.php?action=webhook_log&sluzba=' + sluzba);
    const log = r.log || [];
    openModal('📋 Webhook log — ' + sluzba, `
      ${log.length === 0 ? `
        <div style="text-align:center;padding:30px;color:var(--text-3)">
          <div style="font-size:42px;opacity:0.4">📭</div>
          <h3 style="margin:10px 0 6px;font-size:14px">Žádné příchozí eventy</h3>
          <p style="font-size:12px">${esc(r.note || 'Až přijde první webhook, uvidíš ho zde s celým payloadem.')}</p>
        </div>
      ` : `
        <div style="display:flex;flex-direction:column;gap:8px;max-height:60vh;overflow:auto">
          ${log.map(l => `
            <details style="background:var(--surface-2);border-radius:8px;padding:10px 12px;border-left:3px solid ${l.event.includes('error') || l.event.includes('rejected') ? '#DC2626' : '#10B981'}">
              <summary style="cursor:pointer;display:flex;justify-content:space-between;gap:10px;font-size:13px;font-weight:600">
                <span>${esc(l.event)} ${l.objednavka_id ? `<span style="background:#DBEAFE;color:#1E40AF;padding:1px 8px;border-radius:999px;font-size:11px;margin-left:6px">Obj #${l.objednavka_id}</span>` : ''}</span>
                <span style="color:var(--text-3);font-size:11px;white-space:nowrap">${esc(l.received_at)} · IP ${esc(l.ip || '-')}</span>
              </summary>
              <pre style="margin:8px 0 0;font-size:11px;padding:8px;background:#fff;border-radius:6px;overflow:auto;max-height:200px;white-space:pre-wrap">${esc(l.payload || '{}')}</pre>
            </details>
          `).join('')}
        </div>
      `}
      <div class="form-actions">
        <button class="btn-secondary" onclick="closeModal();courierIntegrationEdit('${sluzba}')">← Zpět na integraci</button>
      </div>
    `);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.courierIntegrationSave = async function(sluzba) {
  try {
    await api('admin_couriers.php?action=integration', { method:'POST', body: JSON.stringify({
      sluzba,
      povolena: document.getElementById('ci-on').checked ? 1 : 0,
      provize_pct: parseFloat(document.getElementById('ci-prov').value) || 0,
      store_id: document.getElementById('ci-store').value.trim() || null,
      api_key: document.getElementById('ci-key').value.trim() || null,
    })});
    closeModal();
    toastSuccess('Integrace uložena');
    renderCouriers();
  } catch (e) { alert('Chyba: ' + e.message); }
};

