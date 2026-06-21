// =============================================================
// 💳 PAYMENT METHODS (v2.9.205) — centrální on/off
// =============================================================
async function loadPaymentMethodsPanel() {
  const panel = document.getElementById('ns-platby-panel');
  if (!panel) return;
  panel.innerHTML = '⏳ Načítám…';
  try {
    const r = await api('payment_methods.php');
    const methods = r.methods || [];
    const cfg = r.config || {};
    const dopr = r.doprava || { zdarma_od: null, metody: [] };
    const groups = {
      physical: { title: '🛒 POS — fyzická platba',  items: [], hint: 'V kase / pokladně' },
      online:   { title: '🌐 Online platební brány', items: [], hint: 'Karta online, pro web checkout a B2B portal' },
      deferred: { title: '🏦 Odložená platba',        items: [], hint: 'Bankovní převod, faktura, dobírka — B2B portal' },
      other:    { title: '🎫 Ostatní',                items: [], hint: 'Speciální platební metody pro POS' },
    };
    methods.forEach(m => { if (groups[m.cat]) groups[m.cat].items.push(m); });

    // 🆕 v3.0.272 — per-metoda config (rozklikávací): poplatek, splatnost, IBAN
    const POPLATEK_OK = ['hotove','karta','qr_platba','dobirka','paypal','gift_card','voucher','mobile'];
    const SPLATNOST_OK = ['prevod','faktura','dobirka'];
    const IBAN_OK = ['qr_platba','prevod','faktura'];
    const methodConfigFields = (m) => {
      const c = cfg[m.key] || {};
      const rows = [];
      if (POPLATEK_OK.includes(m.key)) rows.push(`
        <div style="display:flex;align-items:center;gap:8px">
          <label style="font-size:12px;color:var(--text-2);min-width:130px">Příplatek za platbu</label>
          <input type="number" step="0.01" min="0" data-pmcfg="${m.key}" data-pmfield="poplatek" value="${c.poplatek||''}" placeholder="0" style="width:90px;padding:6px 8px;border:1px solid var(--border);border-radius:6px;font-size:13px">
          <select data-pmcfg="${m.key}" data-pmfield="poplatek_typ" style="padding:6px;border:1px solid var(--border);border-radius:6px;font-size:13px">
            <option value="kc" ${(c.poplatek_typ||'kc')==='kc'?'selected':''}>Kč</option>
            <option value="pct" ${c.poplatek_typ==='pct'?'selected':''}>% z objednávky</option>
          </select>
        </div>`);
      if (SPLATNOST_OK.includes(m.key)) rows.push(`
        <div style="display:flex;align-items:center;gap:8px">
          <label style="font-size:12px;color:var(--text-2);min-width:130px">Splatnost (dní)</label>
          <input type="number" step="1" min="0" max="365" data-pmcfg="${m.key}" data-pmfield="splatnost_dni" value="${c.splatnost_dni||''}" placeholder="14" style="width:90px;padding:6px 8px;border:1px solid var(--border);border-radius:6px;font-size:13px">
        </div>`);
      if (IBAN_OK.includes(m.key)) rows.push(`
        <div style="display:flex;align-items:center;gap:8px">
          <label style="font-size:12px;color:var(--text-2);min-width:130px">Účet / IBAN ${m.key==='qr_platba'?'(pro QR)':''}</label>
          <input type="text" data-pmcfg="${m.key}" data-pmfield="ucet_iban" value="${esc(c.ucet_iban||'')}" placeholder="CZ65 0800 0000 1920 0014 5399" style="flex:1;padding:6px 8px;border:1px solid var(--border);border-radius:6px;font-size:13px;font-family:monospace">
        </div>`);
      if (!rows.length) return '';
      return `<div data-pm-cfgbox="${m.key}" style="display:none;flex-direction:column;gap:8px;padding:10px 12px;margin-top:-2px;background:var(--surface-2);border:1px solid var(--border);border-top:none;border-radius:0 0 8px 8px">${rows.join('')}</div>`;
    };

    const renderGroup = (g) => g.items.length === 0 ? '' : `
      <div class="card-block" style="margin:0;padding:14px 16px">
        <h3 style="margin:0 0 4px;font-size:14px">${esc(g.title)}</h3>
        <p style="font-size:12px;color:var(--text-3);margin:0 0 12px">${esc(g.hint)}</p>
        <div style="display:flex;flex-direction:column;gap:6px">
          ${g.items.map(m => {
            const hasCfg = methodConfigFields(m) !== '';
            return `
            <div>
              <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;cursor:pointer;font-size:13px">
                <input type="checkbox" data-pm-key="${m.key}" ${m.enabled ? 'checked' : ''}>
                <span style="flex:1">${esc(m.label)}${(cfg[m.key]&&cfg[m.key].poplatek)?` <span style="font-size:11px;color:#BA7517">· +${cfg[m.key].poplatek}${cfg[m.key].poplatek_typ==='pct'?'%':' Kč'}</span>`:''}</span>
                <span style="font-size:10px;color:var(--text-3)">${m.pos ? 'POS' : ''}${m.pos && m.b2b ? ' · ' : ''}${m.b2b ? 'B2B' : ''}</span>
                ${hasCfg ? `<button type="button" onclick="event.preventDefault();const b=document.querySelector('[data-pm-cfgbox=\\'${m.key}\\']');b.style.display=b.style.display==='none'?'flex':'none';this.textContent=b.style.display==='none'?'⚙️':'▲'" title="Nastavení metody" style="background:none;border:none;cursor:pointer;font-size:15px;padding:2px 6px">⚙️</button>` : ''}
              </label>
              ${methodConfigFields(m)}
            </div>`;
          }).join('')}
        </div>
      </div>`;

    // 🆕 v3.0.272 — Doprava: práh „zdarma od" + cena per způsob
    //   (admin odpověď má metody jako objekt {key:…}, b2b kontext jako pole — zvládni obojí)
    const dopMap = {};
    (Array.isArray(dopr.metody) ? dopr.metody : Object.values(dopr.metody || {})).forEach(d => { if (d && d.key) dopMap[d.key] = d; });
    const DOPR_DEF = [['rozvoz','🚚 Rozvoz na adresu'],['kuryr','🛵 Kurýr'],['vlastni','🚐 Vlastní odvoz / pickup'],['zasilkovna','📦 Zásilkovna'],['dpd','📦 DPD CZ']];
    const blokDoprava = `
      <div class="card-block" style="margin:18px 0 0;padding:16px;border-left:4px solid #BA7517">
        <h3 style="margin:0 0 4px;font-size:16px">🚚 Doprava — ceny a práh „zdarma"</h3>
        <p style="font-size:12px;color:var(--text-3);margin:0 0 14px;line-height:1.5">Cena za každý způsob dopravy se přičte k objednávce. Nad nastavenou hranicí je doprava <strong>zdarma</strong>. Týká se B2B portálu (a POS rozvozu).</p>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;padding:10px 12px;background:rgba(186,117,23,0.08);border-radius:8px">
          <label style="font-size:13px;font-weight:600;min-width:170px">🎉 Doprava zdarma od</label>
          <input type="number" step="1" min="0" id="dopr-zdarma-od" value="${dopr.zdarma_od!=null?dopr.zdarma_od:''}" placeholder="např. 1500" style="width:120px;padding:8px;border:1px solid var(--border);border-radius:6px;font-size:14px;font-weight:600">
          <span style="font-size:13px;color:var(--text-3)">Kč (s DPH). Prázdné = nikdy zdarma.</span>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px">
          ${DOPR_DEF.map(([k,lbl]) => { const d = dopMap[k]||{}; return `
            <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;border:1px solid var(--border);border-radius:8px">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" data-dopr="${k}" data-dfield="aktivni" ${d.aktivni!==false?'checked':''}></label>
              <span style="flex:1;font-size:13px">${lbl}</span>
              <input type="number" step="0.01" min="0" data-dopr="${k}" data-dfield="cena" value="${d.cena||''}" placeholder="0" style="width:90px;padding:6px 8px;border:1px solid var(--border);border-radius:6px;font-size:13px;text-align:right">
              <span style="font-size:12px;color:var(--text-3)">Kč</span>
            </div>`; }).join('')}
        </div>
      </div>`;

    panel.innerHTML = `
      <div class="platby-grid">
        ${renderGroup(groups.physical)}
        ${renderGroup(groups.online)}
        ${renderGroup(groups.deferred)}
        ${renderGroup(groups.other)}
      </div>
      ${blokDoprava}
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px">
        <button class="btn-primary btn-green btn-big-action" onclick="savePaymentMethods()" style="font-size:15px;padding:12px 24px">💾 Uložit platby, poplatky i dopravu</button>
      </div>
      <p style="font-size:11px;color:var(--text-3);margin-top:10px">
        ℹ️ Klikni na <strong>⚙️</strong> u metody pro poplatek / splatnost / IBAN. Příplatky (doprava + poplatek platby) se promítnou do CELKEM objednávky a objeví se jako řádky na dokladech.
        Online brány (Stripe / GoPay) napoj v <strong>🔌 Integrace</strong>.
      </p>
    `;
  } catch (e) {
    panel.innerHTML = `<div style="background:#fde7e9;color:#a8232f;padding:12px;border-radius:8px">❌ Chyba: ${esc(e.message)}</div>`;
  }
}

window.savePaymentMethods = async function() {
  const methods = {};
  document.querySelectorAll('[data-pm-key]').forEach(c => { methods[c.dataset.pmKey] = c.checked; });
  // 🆕 v3.0.272 — per-metoda config (poplatek/splatnost/IBAN)
  const config = {};
  document.querySelectorAll('[data-pmcfg]').forEach(el => {
    const k = el.dataset.pmcfg, f = el.dataset.pmfield;
    if (!config[k]) config[k] = {};
    config[k][f] = el.value;
  });
  // 🆕 v3.0.272 — doprava (práh + ceny + aktivní)
  const doprava = { zdarma_od: (document.getElementById('dopr-zdarma-od')?.value || ''), metody: {} };
  document.querySelectorAll('[data-dopr]').forEach(el => {
    const k = el.dataset.dopr, f = el.dataset.dfield;
    if (!doprava.metody[k]) doprava.metody[k] = {};
    doprava.metody[k][f] = (f === 'aktivni') ? el.checked : el.value;
  });
  try {
    await api('payment_methods.php', { method: 'PUT', body: JSON.stringify({ methods, config, doprava }) });
    if (typeof toast === 'function') toast('✓ Platby, poplatky i doprava uloženy', 'success');
    loadPaymentMethodsPanel(); // re-render → projeví se „+X Kč" čipy a uložené hodnoty
  } catch (e) {
    if (typeof toast === 'function') toast('❌ ' + e.message, 'error'); else alert('Chyba: ' + e.message);
  }
};

