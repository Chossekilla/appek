// =============================================================
// 🛍️ v3.0.626 — NASTAVENÍ: Online objednávky jídla (/objednat/)
// =============================================================
// Karta v Nastavení → Platby & kanály. Zapnutí, vyzvednutí/rozvoz, cena dopravy + zdarma od,
// PSČ zóna, min. objednávka, otevírací doba, předobjednávka, zpráva zákazníkům.
// Veřejný odkaz + QR k vytištění. Backend: api/web_objednavky.php?action=settings (vyžaduje admina).
const WO_DNY = ['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'];

function webObjUrl() {
  try { return new URL('../objednat/', location.href).href; } catch (e) { return '/objednat/'; }
}

async function webObjPanel() {
  const box = document.getElementById('web-obj-container');
  if (!box) return;
  let r;
  try { r = await api('web_objednavky.php?action=settings&_=' + Date.now()); }
  catch (e) { box.innerHTML = `<div style="color:#dc2626;padding:10px">Nepodařilo se načíst: ${esc(e.message)}</div>`; return; }
  const s = r.settings || {};
  const url = webObjUrl();
  const lbl = (t) => `<span style="display:block;font-size:12px;font-weight:600;color:var(--text-2);margin-bottom:4px">${t}</span>`;
  const num = (id, label, val, suf) => `
    <label style="display:block">${lbl(label)}
      <div style="display:flex;align-items:center;gap:6px"><input type="number" id="${id}" min="0" step="1" value="${Number(val) || 0}" style="width:100%;min-width:0">${suf ? `<span style="color:var(--text-3);font-size:13px;white-space:nowrap">${suf}</span>` : ''}</div></label>`;
  const chk = (id, label, on) => `<label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer"><input type="checkbox" id="${id}" ${on ? 'checked' : ''} style="width:18px;height:18px"> ${label}</label>`;
  box.innerHTML = `
    ${r.restaurace ? '' : `<div style="background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:10px;padding:10px 12px;font-size:13px;margin-bottom:12px">Online objednávky jsou součástí balíčku <strong>Restaurace</strong> — bez něj stránka objednávky nepřijímá.</div>`}
    <div style="display:flex;flex-wrap:wrap;gap:16px;margin-bottom:14px">
      ${chk('wo-zapnuto', '<strong>Přijímat online objednávky</strong>', s.zapnuto)}
      ${chk('wo-vyzvednuti', 'Vyzvednutí', s.vyzvednuti)}
      ${chk('wo-rozvoz', 'Rozvoz', s.rozvoz)}
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:12px">
      ${num('wo-rozvoz_cena', 'Cena rozvozu', s.rozvoz_cena, 'Kč')}
      ${num('wo-rozvoz_zdarma_od', 'Rozvoz zdarma od', s.rozvoz_zdarma_od, 'Kč')}
      ${num('wo-min_objednavka', 'Min. objednávka', s.min_objednavka, 'Kč')}
      ${num('wo-priprava_min', 'Nejdřív za', s.priprava_min, 'min')}
      <label style="display:block">${lbl('Otevřeno od – do')}
        <div style="display:flex;align-items:center;gap:6px"><input type="time" id="wo-od" value="${esc(s.od || '08:00')}" style="min-width:0;flex:1">–<input type="time" id="wo-do" value="${esc(s.do || '20:00')}" style="min-width:0;flex:1"></div></label>
      ${num('wo-predobjednavka_dni', 'Předobjednávka', s.predobjednavka_dni, 'dní')}
    </div>
    <div style="margin-bottom:12px">${lbl('Dny, kdy přijímáte')}
      <div style="display:flex;flex-wrap:wrap;gap:6px">${WO_DNY.map((d, i) => `<label style="display:inline-flex;align-items:center;gap:5px;padding:6px 10px;border:1px solid var(--border);border-radius:999px;cursor:pointer;font-size:13px"><input type="checkbox" class="wo-den" value="${i + 1}" ${(s.dny || []).includes(i + 1) ? 'checked' : ''}> ${d}</label>`).join('')}</div></div>
    <label style="display:block;margin-bottom:12px">${lbl('Rozvoz jen na PSČ (začátky, oddělte čárkou — prázdné = kamkoli)')}
      <input type="text" id="wo-rozvoz_psc" value="${esc(s.rozvoz_psc || '')}" placeholder="např. 110, 120, 130 00" style="width:100%"></label>
    <label style="display:block;margin-bottom:14px">${lbl('Zpráva zákazníkům (nahoře na stránce)')}
      <input type="text" id="wo-zprava" maxlength="300" value="${esc(s.zprava || '')}" placeholder="např. Polední menu do 14:00" style="width:100%"></label>
    <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:center;background:var(--bg-2,#f5f5f4);border-radius:12px;padding:12px">
      <div id="wo-qr" style="width:120px;height:120px;background:#fff;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center"></div>
      <div style="flex:1;min-width:200px">
        <div style="font-size:12px;font-weight:600;color:var(--text-2);margin-bottom:4px">Odkaz pro zákazníky (web, Facebook, Google profil, QR na výlohu)</div>
        <a href="${esc(url)}" target="_blank" rel="noopener" style="font-weight:700;word-break:break-all">${esc(url)}</a>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">
          <button class="btn-secondary" type="button" onclick="webObjCopy()">Kopírovat odkaz</button>
          <button class="btn-secondary" type="button" onclick="webObjPrintQr()">Vytisknout QR</button>
        </div>
      </div>
    </div>
    <p style="font-size:12px;color:var(--text-3);margin:10px 0 0;line-height:1.5">Objednávky chodí do kuchyně, do <strong>Objednávek</strong> (řada WEB) a vyzvednutí i do POS → <strong>Vyzvednutí</strong>, kde je vydáte a zaplatíte. Nabídka a ceny = výrobky zobrazené na POS. Zákazník platí při převzetí.</p>
    <div style="display:flex;justify-content:flex-end;margin-top:14px">
      <button class="btn-primary btn-green" type="button" onclick="webObjUloz()" style="font-weight:700;padding:12px 24px;border-radius:10px">Uložit online objednávky</button>
    </div>`;
  staniceEnsureQRCode().then((ok) => {
    const el = document.getElementById('wo-qr');
    if (ok && el && window.QRCode) { try { new QRCode(el, { text: url, width: 112, height: 112, correctLevel: QRCode.CorrectLevel.M }); } catch (e) {} }
  });
}

async function webObjUloz() {
  const v = (id) => document.getElementById(id);
  const body = {
    zapnuto: v('wo-zapnuto').checked, vyzvednuti: v('wo-vyzvednuti').checked, rozvoz: v('wo-rozvoz').checked,
    rozvoz_cena: +v('wo-rozvoz_cena').value || 0, rozvoz_zdarma_od: +v('wo-rozvoz_zdarma_od').value || 0,
    min_objednavka: +v('wo-min_objednavka').value || 0, priprava_min: +v('wo-priprava_min').value || 0,
    od: v('wo-od').value || '08:00', do: v('wo-do').value || '20:00',
    predobjednavka_dni: +v('wo-predobjednavka_dni').value || 0,
    dny: [...document.querySelectorAll('.wo-den:checked')].map((x) => +x.value),
    rozvoz_psc: v('wo-rozvoz_psc').value, zprava: v('wo-zprava').value,
  };
  if (body.zapnuto && !body.vyzvednuti && !body.rozvoz) return toastWarn('Zapněte aspoň vyzvednutí nebo rozvoz.');
  if (body.zapnuto && !body.dny.length) return toastWarn('Vyberte aspoň jeden den.');
  try {
    await api('web_objednavky.php?action=settings', { method: 'POST', body });
    toastSuccess(body.zapnuto ? 'Online objednávky jsou zapnuté.' : 'Uloženo (objednávky vypnuté).');
  } catch (e) { toastError(e.message); }
}

function webObjCopy() {
  const url = webObjUrl();
  (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject())
    .then(() => toastSuccess('Odkaz zkopírován'))
    .catch(() => { window.prompt('Zkopírujte odkaz:', url); });
}

function webObjPrintQr() {
  const img = document.querySelector('#wo-qr img, #wo-qr canvas');
  const src = img ? (img.tagName === 'CANVAS' ? img.toDataURL() : img.src) : '';
  const w = window.open('', '_blank');
  if (!w) return;
  w.document.write(`<!doctype html><meta charset="utf-8"><title>QR objednávky</title>
    <body style="font-family:sans-serif;text-align:center;padding:40px">
    <h1 style="font-size:34px;margin:0 0 6px">Objednejte online</h1><p style="font-size:18px;margin:0 0 24px">vyzvednutí i rozvoz</p>
    ${src ? `<img src="${src}" style="width:320px;height:320px;image-rendering:pixelated">` : ''}
    <p style="font-size:16px;margin-top:20px;word-break:break-all">${esc(webObjUrl())}</p>
    <script>setTimeout(function(){print()},300)<\/script></body>`);
  w.document.close();
}
