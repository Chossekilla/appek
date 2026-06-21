// =============================================================
// 🧾 POS REGISTER — Launcher (otevírá samostatnou /pos/ aplikaci)
// =============================================================
// POS běží jako standalone app (/pos/ folder, vlastní HTML/CSS/JS)
// — sdílí session s adminem, ale je to jiné okno (fullscreen kiosk friendly)
// 🆕 v2.9.303 — kompaktní hero + list dnešních prodejů (Co se dnes prodalo)
async function renderPOSLauncher() {
  const body = document.getElementById('rest-tab-body');
  if (!body) return;

  // Skeleton během načítání
  body.innerHTML = `
    <style>
      .pos-launcher-grid { display:grid; grid-template-columns:minmax(280px,1fr) minmax(380px,1.4fr); gap:16px; align-items:start; }
      @media (max-width:900px) { .pos-launcher-grid { grid-template-columns:1fr; } }
    </style>
    <div class="pos-launcher-grid">
      <div class="card-block" style="padding:18px;background:linear-gradient(180deg,#FFFDF6 0%,#FAF6EC 100%);border:2px solid var(--primary-border,#FAC775);border-radius:14px">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
          <div style="font-size:32px;line-height:1">🧾</div>
          <div>
            <h2 style="margin:0;font-size:18px;font-weight:800;color:var(--primary,#BA7517)">POS Kasa</h2>
            <small style="font-size:11px;color:var(--text-3)">Touch-grid register pro pultový prodej</small>
          </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px">
          <button class="btn-primary" style="font-size:15px;padding:12px 16px;font-weight:700" onclick="openPOSWindow()">
            🚀 Otevřít POS v novém okně
          </button>
          <button class="btn-secondary" style="font-size:13px;padding:9px 14px" onclick="window.open('pos.php','_self')">
            ↗️ Otevřít v této záložce
          </button>
        </div>
        <details style="margin-top:12px;font-size:12px;color:var(--text-2)">
          <summary style="cursor:pointer;color:var(--text-3);font-size:11px;font-weight:600;user-select:none">ⓘ Co umí POS</summary>
          <ul style="margin:8px 0 0;padding-left:18px;line-height:1.55">
            <li>📦 Produktová mřížka s obrázky, kategorie, search</li>
            <li>💳 6 platebních metod (hotově, karta, PayPal, voucher, gift card, mobile)</li>
            <li>🛍️ 4 typy objednávky (sebou, na místě, vyzvednutí, rozvoz)</li>
            <li>🧾 Tisk účtenky 80 mm, slevy, spropitné, poznámky</li>
            <li>👥 PIN přepínač prodavačů + cart resume</li>
          </ul>
          <div style="margin-top:8px;padding:8px 10px;background:#FFFBE9;border:1px solid #F0D88B;border-radius:6px;font-size:11px">
            💡 Na terminálu si dej <code>/pos/</code> jako záložku na ploše nebo iPad home screen — POS funguje jako PWA.
          </div>
        </details>
      </div>

      <div id="pos-launcher-sales" class="card-block" style="padding:14px;min-height:340px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <h3 style="margin:0;font-size:14px;color:var(--text)">🛒 Dnešní prodeje</h3>
          <button class="btn-secondary" style="font-size:11px;padding:4px 10px" onclick="renderPOSLauncher()" title="Obnovit data">↻</button>
        </div>
        <div style="text-align:center;padding:40px 20px;color:var(--text-3);font-size:13px">⏳ Načítám…</div>
      </div>
    </div>

    <!-- 🆕 v2.9.310 — Editor rychlých voleb pro POS „volnou položku" -->
    <div id="pos-presets-editor" class="card-block" style="margin-top:16px;padding:14px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px">
        <div>
          <h3 style="margin:0;font-size:14px;color:var(--text)">⚙️ Rychlé volby pro „volnou položku" v POS</h3>
          <small style="font-size:11px;color:var(--text-3)">Tlačítka pod formulářem volné položky — typické položky (Korkovné, Obal, Sleva…). Záporná cena = sleva.</small>
        </div>
        <div style="display:flex;gap:6px">
          <button class="btn-secondary" style="font-size:11px;padding:5px 10px" onclick="posPresetsReset()" title="Vrátit na továrenské 4 presety">↺ Reset</button>
          <button class="btn-primary" style="font-size:11px;padding:5px 12px;font-weight:700" onclick="posPresetsSave()">💾 Uložit</button>
        </div>
      </div>
      <div id="pos-presets-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px">
        <div style="padding:20px;text-align:center;color:var(--text-3);font-size:12px">⏳ Načítám…</div>
      </div>
      <button class="btn-secondary" style="font-size:12px;padding:8px 14px;width:100%" onclick="posPresetsAdd()">+ Přidat preset</button>
    </div>
  `;

  // 🚀 v2.9.315 — paralelně launcher_summary + presets (předtím sériově s 2× round-trip
  // latency). Ušetří ~150-300 ms (PHP+MySQL roundtrip). Promise.all je safe — oba endpointy
  // jsou nezávislé, posPresetsLoad() interně zapíše do state._posPresets + #pos-presets-list.
  let d;
  try {
    const [summary] = await Promise.all([
      api('admin_pos.php?action=launcher_summary'),
      posPresetsLoad(), // už re-renderuje #pos-presets-list samostatně
    ]);
    d = summary;
  } catch (e) {
    d = { ok: false, error: e.message };
  }

  const panel = document.getElementById('pos-launcher-sales');
  if (!panel) return; // user mezitím odešel ze stránky

  if (!d || d.ok === false) {
    panel.innerHTML = `
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <h3 style="margin:0;font-size:14px;color:var(--text)">🛒 Dnešní prodeje</h3>
        <button class="btn-secondary" style="font-size:11px;padding:4px 10px" onclick="renderPOSLauncher()">↻</button>
      </div>
      <div style="padding:24px;text-align:center;color:var(--text-3);font-size:12px;background:#FFF8E5;border:1px dashed #E8C988;border-radius:8px">
        ${esc(d?.error || 'Data zatím nedostupná. Endpoint admin_pos.php?action=launcher_summary.')}
      </div>
    `;
    return;
  }

  const s = d.souhrn || {};
  const orders = Array.isArray(d.orders) ? d.orders : [];
  const top = Array.isArray(d.top_items) ? d.top_items : [];
  const fmtCZK = (n) => (parseFloat(n) || 0).toLocaleString('cs-CZ', { maximumFractionDigits: 0 }) + ' Kč';
  const fmtTime = (iso) => {
    if (!iso) return '—';
    try {
      const dt = new Date(iso.replace(' ', 'T'));
      return dt.toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' });
    } catch (e) { return '—'; }
  };
  const typIcon = (t) => ({ 'sebou': '🥡', 'na_miste': '🍽️', 'rozvoz': '🛵', 'vyzvednuti': '🏪' })[t] || '🧾';
  const payIcon = (p) => ({ 'hotove': '💵', 'karta': '💳', 'paypal': '🅿️', 'voucher': '🎫', 'gift': '🎁', 'mobile': '📱' })[p] || '💳';

  // Stat tiles + orders list + top items
  panel.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <h3 style="margin:0;font-size:14px;color:var(--text)">🛒 Dnešní prodeje <small style="font-size:11px;color:var(--text-3);font-weight:400">${esc(d.date || '')}</small></h3>
      <button class="btn-secondary" style="font-size:11px;padding:4px 10px" onclick="renderPOSLauncher()" title="Obnovit data">↻</button>
    </div>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px">
      <div style="background:#F7F8FA;border:1px solid var(--border);border-radius:8px;padding:10px;text-align:center">
        <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:2px">Tržby</div>
        <strong style="font-size:18px;color:#15803D;font-variant-numeric:tabular-nums">${fmtCZK(s.trzby)}</strong>
      </div>
      <div style="background:#F7F8FA;border:1px solid var(--border);border-radius:8px;padding:10px;text-align:center">
        <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:2px">Účtenek</div>
        <strong style="font-size:18px;color:var(--text);font-variant-numeric:tabular-nums">${parseInt(s.pocet || 0)}</strong>
      </div>
      <div style="background:#F7F8FA;border:1px solid var(--border);border-radius:8px;padding:10px;text-align:center">
        <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:2px">Tipy</div>
        <strong style="font-size:18px;color:#BA7517;font-variant-numeric:tabular-nums">${fmtCZK(s.tipy)}</strong>
      </div>
    </div>

    ${(parseFloat(s.hotove) > 0 || parseFloat(s.karta) > 0) ? `
      <div style="display:flex;gap:6px;margin-bottom:12px;font-size:11px;color:var(--text-2)">
        <span style="background:#EFF6FF;color:#0C447C;padding:3px 8px;border-radius:6px">💵 Hotově ${fmtCZK(s.hotove)}</span>
        <span style="background:#F0FDF4;color:#15803D;padding:3px 8px;border-radius:6px">💳 Karta ${fmtCZK(s.karta)}</span>
      </div>
    ` : ''}

    ${top.length > 0 ? `
      <div style="margin-bottom:14px">
        <div style="font-size:12px;font-weight:700;color:var(--text-2);margin-bottom:6px;text-transform:uppercase;letter-spacing:0.04em">🏆 TOP prodané dnes</div>
        <div style="display:flex;flex-direction:column;gap:4px">
          ${top.map((it, idx) => {
            const clickable = it.vyrobek_id && it.vyrobek_id > 0;
            const baseBg = idx === 0 ? '#FFF8E5' : '#F7F8FA';
            const hoverBg = idx === 0 ? '#FFEEC2' : '#EFF1F4';
            // 🐛 v2.9.313 — fix: předtím byly DUPLIKÁTNÍ style="..." atributy. HTML5 spec
            // používá jen první → celý layout (display:flex, gap, padding) se ztratil pro
            // clickable rows. Teď merge do jednoho atributu.
            const baseStyle = `display:flex;justify-content:space-between;gap:8px;padding:6px 10px;background:${baseBg};border-radius:6px;font-size:12px;align-items:center`;
            const clickStyle = clickable ? ';cursor:pointer;transition:background 0.12s ease' : '';
            const clickAttrs = clickable
              ? `onclick="editVyrobek(${it.vyrobek_id})" title="Klikni pro detail výrobku" onmouseover="this.style.background='${hoverBg}'" onmouseout="this.style.background='${baseBg}'"`
              : '';
            return `
              <div ${clickAttrs} style="${baseStyle}${clickStyle}">
                <span style="display:flex;align-items:center;gap:6px;min-width:0">
                  <span style="font-size:11px;color:var(--text-3);min-width:18px">#${idx + 1}</span>
                  <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(it.vyrobek_nazev || '—')}">${esc((it.vyrobek_nazev || '—').slice(0, 32))}</span>
                </span>
                <span style="display:flex;gap:8px;align-items:center;white-space:nowrap;font-variant-numeric:tabular-nums">
                  <strong style="color:#BA7517">${parseFloat(it.mnozstvi_sum).toFixed(parseFloat(it.mnozstvi_sum) % 1 ? 1 : 0)}×</strong>
                  <span style="color:var(--text-3);font-size:11px">${fmtCZK(it.trzba_sum)}</span>
                </span>
              </div>
            `;
          }).join('')}
        </div>
      </div>
    ` : ''}

    ${orders.length > 0 ? `
      <div>
        <div style="font-size:12px;font-weight:700;color:var(--text-2);margin-bottom:6px;text-transform:uppercase;letter-spacing:0.04em">🧾 Poslední účtenky</div>
        <div style="display:flex;flex-direction:column;gap:4px;max-height:280px;overflow:auto">
          ${orders.map(o => `
            <div onclick="openObjednavkaDetail(${o.id})" title="Klikni pro detail účtenky" style="display:grid;grid-template-columns:auto 1fr auto auto;gap:8px;padding:6px 10px;background:#F7F8FA;border-radius:6px;font-size:12px;align-items:center;cursor:pointer;transition:background 0.12s ease" onmouseover="this.style.background='#FFF8E5'" onmouseout="this.style.background='#F7F8FA'">
              <span style="font-size:14px" title="${esc(o.pos_typ || '')}">${typIcon(o.pos_typ)}</span>
              <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <strong style="font-family:monospace;font-size:11px">${esc((o.cislo || '—').slice(-8))}</strong>
                <span style="color:var(--text-3);margin-left:6px">${fmtTime(o.datum_objednani)} · ${parseInt(o.pocet_polozek || 0)} pol.</span>
                ${o.pos_uzivatel ? `<span style="color:var(--text-3);margin-left:6px">· 👤 ${esc(String(o.pos_uzivatel).slice(0, 14))}</span>` : ''}
              </span>
              <span title="${esc(o.pos_payment || '')}">${payIcon(o.pos_payment)}</span>
              <strong style="font-variant-numeric:tabular-nums;color:var(--text)">${fmtCZK(o.castka_celkem)}</strong>
            </div>
          `).join('')}
        </div>
      </div>
    ` : `
      <div style="padding:32px 20px;text-align:center;color:var(--text-3);font-size:13px;background:#FAFAFA;border:1px dashed var(--border);border-radius:8px">
        <div style="font-size:36px;margin-bottom:8px;opacity:0.5">🛒</div>
        Dnes ještě žádné POS prodeje.<br>
        <small style="font-size:11px">Otevři POS Kasu a začni!</small>
      </div>
    `}
  `;

  // 🐛 v2.9.315 — orphan posPresetsLoad() volání odstraněno (teď běží paralelně
  // v Promise.all výše na začátku funkce, viz fetch block).
}

// 🆕 v2.9.310 — POS rychlé volby (editor v POS Kasa hub) ──────────────
// Stav držíme v state._posPresets pro re-render mezi přidáním/úpravou
async function posPresetsLoad() {
  const list = document.getElementById('pos-presets-list');
  if (!list) return;
  try {
    const r = await api('admin_pos_presets.php');
    state._posPresets = Array.isArray(r?.presets) ? r.presets : [];
  } catch (e) {
    state._posPresets = [];
    list.innerHTML = `<div style="padding:14px;background:#FEE2E2;color:#991B1B;border-radius:6px;font-size:12px">Chyba: ${esc(e.message)}</div>`;
    return;
  }
  posPresetsRender();
}
function posPresetsRender() {
  const list = document.getElementById('pos-presets-list');
  if (!list) return;
  const presets = state._posPresets || [];
  if (presets.length === 0) {
    list.innerHTML = `<div style="padding:14px;text-align:center;color:var(--text-3);font-size:12px;background:#FAFAFA;border:1px dashed var(--border);border-radius:6px">Žádné presety. Klikni „+ Přidat preset" níže.</div>`;
    return;
  }
  list.innerHTML = presets.map((p, idx) => {
    const isNeg = (parseFloat(p.cena) || 0) < 0;
    return `
      <div style="display:grid;grid-template-columns:48px minmax(120px,1fr) 90px 70px auto;gap:6px;align-items:center;padding:6px 8px;background:${isNeg ? '#FFF5F5' : '#F7F8FA'};border:1px solid ${isNeg ? '#FCA5A5' : '#E1E5EB'};border-radius:6px">
        <input type="text" maxlength="4" value="${esc(p.ikona || '')}" placeholder="🛒" onchange="posPresetsUpdate(${idx}, 'ikona', this.value)" style="padding:6px;text-align:center;font-size:18px;border:1px solid var(--border);border-radius:4px;background:#fff" title="Emoji/ikona">
        <input type="text" maxlength="60" value="${esc(p.nazev || '')}" placeholder="Název položky" onchange="posPresetsUpdate(${idx}, 'nazev', this.value)" style="padding:6px 10px;font-size:13px;border:1px solid var(--border);border-radius:4px;background:#fff">
        <input type="number" step="0.01" value="${parseFloat(p.cena) || 0}" onchange="posPresetsUpdate(${idx}, 'cena', this.value)" style="padding:6px 10px;font-size:13px;border:1px solid var(--border);border-radius:4px;background:#fff;text-align:right;font-variant-numeric:tabular-nums" title="Cena bez DPH (− = sleva)">
        <select onchange="posPresetsUpdate(${idx}, 'dph', this.value)" style="padding:6px;font-size:13px;border:1px solid var(--border);border-radius:4px;background:#fff" title="DPH %">
          ${[0, 10, 12, 15, 21].map(v => `<option value="${v}" ${parseFloat(p.dph) === v ? 'selected' : ''}>${v}%</option>`).join('')}
        </select>
        <button class="btn-secondary" style="padding:5px 10px;font-size:14px;color:#dc2626;border-color:#FCA5A5" onclick="posPresetsRemove(${idx})" title="Smazat preset">×</button>
      </div>
    `;
  }).join('');
}
window.posPresetsUpdate = function(idx, field, value) {
  if (!state._posPresets || !state._posPresets[idx]) return;
  if (field === 'cena' || field === 'dph') value = parseFloat(value) || 0;
  state._posPresets[idx][field] = value;
};
window.posPresetsAdd = function() {
  if (!Array.isArray(state._posPresets)) state._posPresets = [];
  if (state._posPresets.length >= 24) return alert('Max 24 presetů');
  state._posPresets.push({ ikona: '🛒', nazev: 'Nový preset', cena: 0, dph: 21 });
  posPresetsRender();
};
window.posPresetsRemove = function(idx) {
  if (!state._posPresets) return;
  state._posPresets.splice(idx, 1);
  posPresetsRender();
};
window.posPresetsSave = async function() {
  if (!Array.isArray(state._posPresets)) return;
  // Validace: každý preset musí mít nazev
  for (const p of state._posPresets) {
    if (!p.nazev || !p.nazev.trim()) return alert('Některý preset nemá vyplněný název. Doplň ho nebo smaž.');
  }
  try {
    const r = await api('admin_pos_presets.php?action=save', {
      method: 'POST',
      body: JSON.stringify({ presets: state._posPresets }),
    });
    state._posPresets = r.presets || state._posPresets;
    posPresetsRender();
    toastSuccess(t('toast_presets_saved', { n: r.saved || 0 }));
  } catch (e) { alert('Chyba: ' + e.message); }
};
window.posPresetsReset = async function() {
  if (!(await confirmDialog({ msg: 'Vrátit presety na továrenské 4 (Korkovné, Obal, Sleva, Poplatek)?', danger: true }))) return;
  try {
    const r = await api('admin_pos_presets.php?action=reset', { method: 'POST' });
    state._posPresets = r.presets || [];
    posPresetsRender();
    toastSuccess('✓ Resetováno na továrenské defaulty.');
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.openPOSWindow = function () {
  // Otevři ve velkém okně bez toolbaru — kiosk-friendly
  // 🆕 v2.9.268 — relative path (pos.php) místo /admin/pos.php — funguje
  // v root install (demo.appek.cz/admin/pos.php) i v subfolder (localhost/appek/admin/pos.php).
  const w = screen.availWidth  || 1280;
  const h = screen.availHeight || 800;
  const win = window.open('pos.php', 'appek_pos',
    `width=${w},height=${h},left=0,top=0,toolbar=no,menubar=no,location=no,status=no,scrollbars=yes,resizable=yes`);
  if (!win) {
    alert('Prohlížeč zablokoval popup. Povolte popup okna pro tuto stránku, nebo klikni "Otevřít v této záložce".');
    return;
  }
  win.focus();
};

