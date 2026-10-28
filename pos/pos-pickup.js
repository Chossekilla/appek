// =============================================================
// 📦 v3.0.623 — VYZVEDNUTÍ v pokladně (sdílené pro /admin/pos.php i /pos/)
// =============================================================
// • POSPickup.collect(totalTxt) → formulář (čas, jméno, telefon, e-mail, zaplatit hned / při vyzvednutí)
//   volá finish() obou pokladen, když je typ „Vyzvednutí". Vrací data do quick_order nebo null (zrušeno).
// • Odznak „📦 K vyzvednutí (N)" vedle tlačítek typu → seznam čekajících: Připraveno (+e-mail), Vydat (+zaplatit), Zrušit.
// Backend: api/admin_pos.php ?action=pickup_list | pickup_complete | pickup_ready | pickup_cancel.
(function () {
  const CFG = window.POS_CONFIG || {};
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const money = (n) => (parseFloat(n) || 0).toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' Kč';
  const qty = (n) => (parseFloat(n) || 0).toLocaleString('cs-CZ', { maximumFractionDigits: 3 });

  async function api(path, opts = {}) {
    const method = (opts.method || 'GET').toUpperCase();
    const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
    if (method !== 'GET' && CFG.csrfToken) headers['X-CSRF-Token'] = CFG.csrfToken;
    const r = await fetch((CFG.apiBase || '../api/') + path, { method, headers, body: opts.body, credentials: 'same-origin' });
    let data = null;
    try { data = await r.json(); } catch (e) { data = {}; }
    if (!r.ok) throw new Error((data && (data.error || data.message)) || ('HTTP ' + r.status));
    return data;
  }

  // ─── styly + drobný toast (pokladny mají toast uvnitř svého modulu) ───
  function css() {
    if (document.getElementById('pk-css')) return;
    const s = document.createElement('style');
    s.id = 'pk-css';
    s.textContent = `
      .pk-ov{position:fixed;inset:0;z-index:100050;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;padding:12px}
      .pk-box{background:#fff;color:#1f1a14;border-radius:16px;width:min(520px,100%);max-height:92vh;overflow:auto;padding:18px 18px 14px;box-shadow:0 18px 50px rgba(0,0,0,.3);font:15px/1.4 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif}
      .pk-box h3{margin:0 0 12px;font-size:19px}
      .pk-f{display:grid;gap:10px}
      .pk-f label{display:grid;gap:4px;font-size:13px;font-weight:600;color:#5b5045}
      .pk-f input{font:inherit;font-size:16px;padding:11px 12px;border:1.5px solid #ddd3c4;border-radius:10px;width:100%;box-sizing:border-box}
      .pk-row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
      .pk-seg{display:grid;grid-template-columns:1fr 1fr;gap:6px}
      .pk-seg button,.pk-btn{font:inherit;font-weight:700;min-height:46px;border-radius:11px;border:1.5px solid #ddd3c4;background:#faf6ef;color:#3b2f22;cursor:pointer;padding:8px 12px}
      .pk-seg button.on{background:#ba7517;border-color:#ba7517;color:#fff}
      .pk-act{display:flex;gap:8px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap}
      .pk-btn.pri{background:#2e7d32;border-color:#2e7d32;color:#fff}
      .pk-btn.dan{background:#fdecea;border-color:#f3a5a0;color:#a3261c}
      .pk-card{border:1.5px solid #e8dfd2;border-radius:12px;padding:11px 12px;margin-bottom:9px;background:#fffdf9}
      .pk-card.late{border-color:#e57373;background:#fff5f4}
      .pk-card-h{display:flex;justify-content:space-between;gap:8px;align-items:baseline;flex-wrap:wrap}
      .pk-time{font-size:18px;font-weight:800}
      .pk-card.late .pk-time{color:#c62828}
      .pk-meta{font-size:13px;color:#6b5f52;margin-top:2px}
      .pk-meta a{color:#1565c0;font-weight:700;text-decoration:none}
      .pk-items{font-size:13.5px;margin:7px 0 2px;color:#3b2f22}
      .pk-badge{display:inline-block;font-size:11.5px;font-weight:800;border-radius:8px;padding:2px 8px}
      .pk-paid{background:#e8f5e9;color:#2e7d32}.pk-unpaid{background:#fff3e0;color:#8a4b00}.pk-ready{background:#e3f2fd;color:#1565c0}
      .pk-card .pk-act{justify-content:flex-start;margin-top:9px}
      .pk-card .pk-btn{min-height:44px;font-size:14px}
      .pk-pill{font:inherit;font-weight:800;font-size:13px;border-radius:999px;border:1.5px solid #ba7517;background:#fff8ec;color:#8a4b00;padding:6px 12px;cursor:pointer;white-space:nowrap}
      .pk-pill.zero{opacity:.55}
      .pk-toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:100060;background:#2b2118;color:#fff;padding:11px 16px;border-radius:12px;font:600 14px/1.3 -apple-system,Segoe UI,Arial,sans-serif;max-width:90vw;box-shadow:0 8px 24px rgba(0,0,0,.25)}
      .pk-toast.err{background:#a3261c}
      .pk-empty{text-align:center;color:#8a7d6e;padding:22px 8px}
    `;
    document.head.appendChild(s);
  }
  function toast(msg, err) {
    css();
    const t = document.createElement('div');
    t.className = 'pk-toast' + (err ? ' err' : '');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), err ? 4500 : 2600);
  }
  function modal(html) {
    css();
    const ov = document.createElement('div');
    ov.className = 'pk-ov';
    ov.innerHTML = `<div class="pk-box" role="dialog" aria-modal="true">${html}</div>`;
    document.body.appendChild(ov);
    return ov;
  }
  const pad = (n) => String(n).padStart(2, '0');
  const localDT = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;

  // ─── formulář při dokončení objednávky typu Vyzvednutí ───
  function collect(totalTxt) {
    return new Promise((resolve) => {
      const d = new Date(Date.now() + 30 * 60000);
      d.setMinutes(Math.ceil(d.getMinutes() / 5) * 5, 0, 0);
      let payLater = true;
      const ov = modal(`
        <h3>📦 Vyzvednutí${totalTxt ? ` · ${esc(totalTxt)}` : ''}</h3>
        <div class="pk-f">
          <label>Kdy si to zákazník vyzvedne<input type="datetime-local" id="pk-cas" value="${localDT(d)}"></label>
          <label>Jméno<input id="pk-jmeno" maxlength="80" autocomplete="off" placeholder="např. Novák"></label>
          <div class="pk-row2">
            <label>Telefon<input id="pk-tel" type="tel" inputmode="tel" maxlength="30" placeholder="+420…"></label>
            <label>E-mail <span style="font-weight:400">(nepovinný)</span><input id="pk-email" type="email" inputmode="email" maxlength="120" placeholder="pro „připraveno“"></label>
          </div>
          <div>
            <div style="font-size:13px;font-weight:600;color:#5b5045;margin-bottom:4px">Platba</div>
            <div class="pk-seg">
              <button type="button" data-pl="0">💳 Zaplatit hned</button>
              <button type="button" data-pl="1" class="on">⏳ Při vyzvednutí</button>
            </div>
          </div>
        </div>
        <div class="pk-act">
          <button type="button" class="pk-btn" data-x="cancel">Zpět</button>
          <button type="button" class="pk-btn pri" data-x="ok">✓ Uložit vyzvednutí</button>
        </div>`);
      const done = (val) => { ov.remove(); resolve(val); };
      ov.addEventListener('click', (e) => {
        if (e.target === ov) return done(null);
        const seg = e.target.closest('[data-pl]');
        if (seg) { payLater = seg.dataset.pl === '1'; ov.querySelectorAll('[data-pl]').forEach(b => b.classList.toggle('on', b === seg)); return; }
        const x = e.target.closest('[data-x]');
        if (!x) return;
        if (x.dataset.x === 'cancel') return done(null);
        const jmeno = ov.querySelector('#pk-jmeno').value.trim();
        const tel = ov.querySelector('#pk-tel').value.trim();
        if (!jmeno && !tel) { toast('Vyplň aspoň jméno nebo telefon', true); ov.querySelector('#pk-jmeno').focus(); return; }
        done({
          vyzvednuti_cas: ov.querySelector('#pk-cas').value,
          vyzvednuti_jmeno: jmeno,
          vyzvednuti_tel: tel,
          vyzvednuti_email: ov.querySelector('#pk-email').value.trim(),
          zaplatit_pozdeji: payLater,
        });
      });
      setTimeout(() => { const i = ov.querySelector('#pk-jmeno'); if (i) i.focus(); }, 50);
    });
  }

  // ─── odznak + seznam čekajících ───
  let lastCount = 0;
  function badge() {
    const vyzBtn = document.querySelector('.pos-type-btn[data-typ="vyzvednuti"]');
    if (!vyzBtn) return null;
    let b = document.getElementById('pk-pill');
    if (!b) {
      b = document.createElement('button');
      b.type = 'button';
      b.id = 'pk-pill';
      b.className = 'pk-pill zero';
      b.title = 'Objednávky čekající na vyzvednutí';
      b.addEventListener('click', openList);
      vyzBtn.insertAdjacentElement('afterend', b);
    }
    return b;
  }
  function setBadge(n) {
    css();
    lastCount = n;
    const b = badge();
    if (!b) return;
    b.textContent = `📦 K vyzvednutí (${n})`;
    b.classList.toggle('zero', !n);
  }
  async function refresh() {
    try { const r = await api('admin_pos.php?action=pickup_list'); setBadge(r.count || 0); return r.items || []; }
    catch (e) { return null; }
  }

  function card(o) {
    const cas = o.vyzvednuti_cas ? new Date(o.vyzvednuti_cas.replace(' ', 'T')) : null;
    const late = cas && cas.getTime() < Date.now() - 5 * 60000;
    const today = cas && cas.toDateString() === new Date().toDateString();
    const casTxt = cas ? (today ? '' : cas.getDate() + '. ' + (cas.getMonth() + 1) + '. ') + pad(cas.getHours()) + ':' + pad(cas.getMinutes()) : 'bez času';
    const items = (o.polozky || []).map(p => `${qty(p.mnozstvi)}× ${esc(p.nazev)}`).join(', ');
    return `
      <div class="pk-card${late ? ' late' : ''}" data-id="${o.id}">
        <div class="pk-card-h">
          <span class="pk-time">${late ? '⏰ ' : ''}${casTxt}</span>
          <span><strong>${money(o.castka_celkem)}</strong>
            ${o.zaplaceno ? '<span class="pk-badge pk-paid">zaplaceno</span>' : '<span class="pk-badge pk-unpaid">platí při vyzvednutí</span>'}
            ${o.vyzvednuti_pripraveno ? '<span class="pk-badge pk-ready">připraveno</span>' : ''}</span>
        </div>
        <div class="pk-meta">${esc(o.cislo)} · <strong>${esc(o.vyzvednuti_jmeno || '—')}</strong>
          ${o.vyzvednuti_tel ? ` · <a href="tel:${esc(String(o.vyzvednuti_tel).replace(/\s+/g, ''))}">📞 ${esc(o.vyzvednuti_tel)}</a>` : ''}
          ${o.vyzvednuti_email ? ' · ✉️' : ''}</div>
        ${items ? `<div class="pk-items">${items}</div>` : ''}
        <div class="pk-act">
          ${o.vyzvednuti_pripraveno ? '' : `<button type="button" class="pk-btn" data-a="ready">✅ Připraveno${o.vyzvednuti_email ? ' + e-mail' : ''}</button>`}
          ${o.zaplaceno ? `<button type="button" class="pk-btn pri" data-a="give">📦 Vydat</button>`
                        : `<button type="button" class="pk-btn pri" data-a="pay">💵 Vydat a zaplatit</button>`}
          ${o.zaplaceno ? '' : `<button type="button" class="pk-btn dan" data-a="cancel">✕ Zrušit</button>`}
        </div>
      </div>`;
  }

  async function openList() {
    const ov = modal(`<h3>📦 K vyzvednutí</h3><div id="pk-list"><div class="pk-empty">Načítám…</div></div>
      <div class="pk-act"><button type="button" class="pk-btn" data-x="close">Zavřít</button></div>`);
    const list = ov.querySelector('#pk-list');
    const load = async () => {
      const items = await refresh();
      if (items === null) { list.innerHTML = '<div class="pk-empty">Nepodařilo se načíst.</div>'; return; }
      list.innerHTML = items.length ? items.map(card).join('') : '<div class="pk-empty">Nic nečeká na vyzvednutí.</div>';
    };
    await load();
    ov.addEventListener('click', async (e) => {
      if (e.target === ov || e.target.closest('[data-x="close"]')) { ov.remove(); return; }
      const a = e.target.closest('[data-a]');
      if (!a) return;
      const id = parseInt(a.closest('[data-id]').dataset.id, 10);
      const act = a.dataset.a;
      try {
        if (act === 'ready') {
          const r = await api('admin_pos.php?action=pickup_ready', { method: 'POST', body: JSON.stringify({ id }) });
          toast(r.emailed ? '✅ Připraveno · e-mail odeslán' : '✅ Označeno jako připravené');
        } else if (act === 'give') {
          await api('admin_pos.php?action=pickup_complete', { method: 'POST', body: JSON.stringify({ id }) });
          toast('📦 Vydáno');
        } else if (act === 'pay') {
          const pay = await choosePay();
          if (!pay) return;
          const r = await api('admin_pos.php?action=pickup_complete', { method: 'POST', body: JSON.stringify({ id, pos_payment: pay }) });
          toast('💵 Zaplaceno a vydáno · ' + money(r.celkem));
        } else if (act === 'cancel') {
          if (!confirm('Zrušit nezaplacenou objednávku k vyzvednutí?')) return;
          await api('admin_pos.php?action=pickup_cancel', { method: 'POST', body: JSON.stringify({ id }) });
          toast('✕ Zrušeno');
        }
        await load();
      } catch (err) { toast('❌ ' + (err.message || 'Chyba'), true); }
    });
  }

  function choosePay() {
    return new Promise((resolve) => {
      const ov = modal(`<h3>Jak zákazník platí?</h3>
        <div class="pk-f">
          <button type="button" class="pk-btn" data-p="hotove">💵 Hotově</button>
          <button type="button" class="pk-btn" data-p="karta">💳 Kartou</button>
          <button type="button" class="pk-btn" data-p="qr_platba">📱 QR platba</button>
        </div>
        <div class="pk-act"><button type="button" class="pk-btn" data-p="">Zpět</button></div>`);
      ov.addEventListener('click', (e) => {
        if (e.target === ov) { ov.remove(); return resolve(null); }
        const b = e.target.closest('[data-p]');
        if (!b) return;
        ov.remove();
        resolve(b.dataset.p || null);
      });
    });
  }

  // start: odznak + obnova každou minutu, jen když je pokladna vidět
  function start() {
    if (!document.querySelector('.pos-type-btn[data-typ="vyzvednuti"]')) return;
    setBadge(lastCount);
    refresh();
    setInterval(() => { if (document.visibilityState === 'visible') refresh(); }, 60000);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();

  window.POSPickup = { collect, refresh, openList };
})();
