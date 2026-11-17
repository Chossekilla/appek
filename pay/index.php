<?php
/**
 * 📲 PAY LANDING — Mobil-first stránka pro hosta po naskenování pay-QR.
 *
 * URL: /pay/?t=<token>
 *
 * Volá /api/pay_qr.php (zde žádný server logic, jen SPA shell).
 */
require_once __DIR__ . '/../api/_i18n_web.php';   // 🆕 v3.0.645 — stránka v jazycích (cs/en/es/de/sk)

$token = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['t'] ?? ''));
header('Vary: Accept-Language');
if (!$token || strlen($token) < 16) {
    [$L] = web_lang_resolve();
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    die(web_t($L, 'Neplatný platební QR kód'));
}
// 🆕 v3.0.645 — jazyk: ?lang= → jazyk odběratele objednávky (doklad) → prohlížeč → cs.
//   DB jen když URL jazyk neurčí; jakákoli chyba (DB, starší schéma) = jazyk z prohlížeče.
$docLang = null;
if (web_lang_normalize($_GET['lang'] ?? null) === null && is_file(__DIR__ . '/../api/config.local.php')) {
    try {
        require_once __DIR__ . '/../api/config.php';
        $docLang = web_lang_pay_token(db(), $token);
    } catch (Throwable $e) { $docLang = null; }
}
[$L] = web_lang_resolve($docLang);
header('Content-Language: ' . $L);
$justPaid = !empty($_GET['paid']);
?><!DOCTYPE html>
<html lang="<?= $L ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover">
<title>💳 <?= htmlspecialchars(web_t($L, 'Zaplatit účtenku')) ?> — APPEK</title>
<meta name="theme-color" content="#15803D">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{min-height:100%;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:linear-gradient(135deg,#F0FDF4,#DCFCE7);color:#1F2937}
  body{padding:0 0 40px}
  .top{background:linear-gradient(135deg,#10B981,#059669);color:#fff;padding:24px 20px 28px;border-radius:0 0 24px 24px;text-align:center;box-shadow:0 4px 20px rgba(16,185,129,0.25)}
  .top h1{font-size:18px;font-weight:700;opacity:0.95;letter-spacing:-0.01em}
  .top .firma{font-size:14px;opacity:0.85;margin-top:4px}
  .top .amount{font-size:48px;font-weight:900;letter-spacing:-0.04em;margin-top:12px;line-height:1}
  .top .currency{font-size:24px;opacity:0.7;font-weight:700;margin-left:4px}
  .top .cislo{font-size:11px;opacity:0.7;margin-top:8px;font-family:monospace}
  .container{max-width:480px;margin:0 auto;padding:20px}
  .card{background:#fff;border-radius:16px;padding:18px;margin-bottom:14px;box-shadow:0 2px 12px rgba(0,0,0,0.05);border:1px solid rgba(0,0,0,0.04)}
  .card h2{font-size:15px;font-weight:700;margin-bottom:10px;color:#374151}
  .items{font-size:14px}
  .items .item{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed #E5E7EB}
  .items .item:last-child{border-bottom:none}
  .items .name{flex:1;color:#374151}
  .items .qty{color:#6B7280;margin-right:8px;font-variant-numeric:tabular-nums}
  .items .price{font-weight:600;font-variant-numeric:tabular-nums}
  .pay-btn{display:block;width:100%;padding:18px;border:none;border-radius:14px;font-size:17px;font-weight:800;color:#fff;cursor:pointer;margin-bottom:10px;transition:all 0.15s ease;text-align:center;text-decoration:none}
  .pay-btn:active{transform:scale(0.98)}
  .pay-btn.stripe{background:linear-gradient(135deg,#635BFF,#5046E5)}
  .pay-btn.gopay{background:linear-gradient(135deg,#FB7185,#E11D48)}
  .pay-btn.apple{background:#000}
  .pay-btn.manual{background:linear-gradient(135deg,#94A3B8,#64748B)}
  .pay-btn .ic{font-size:22px;margin-right:8px;vertical-align:-3px}
  .status-card{text-align:center;padding:30px 20px}
  .status-card .big-ic{font-size:64px;margin-bottom:14px;display:block}
  .status-card h2{font-size:22px;font-weight:800;margin-bottom:6px;color:#15803D}
  .status-card .sub{font-size:14px;color:#6B7280;line-height:1.5}
  .loading{text-align:center;padding:60px 20px;color:#6B7280}
  .error{background:#FEE2E2;color:#991B1B;padding:14px 18px;border-radius:10px;font-size:13px;margin-bottom:14px;border:1px solid #FECACA}
  .powered{text-align:center;font-size:11px;color:#9CA3AF;margin-top:14px;padding:10px}
  .powered a{color:inherit}
  @keyframes pulse-success{0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,0.4)}50%{box-shadow:0 0 0 20px rgba(16,185,129,0)}}
  .paid-ic{animation:pulse-success 1.5s ease-out 3}
  .top .mena-sw{display:inline-flex;gap:6px;margin-top:12px;background:rgba(255,255,255,.15);padding:4px;border-radius:12px}
  .top .mena-sw button{border:0;background:none;color:inherit;font:inherit;font-weight:700;padding:8px 16px;border-radius:9px;cursor:pointer;min-height:38px}
  .top .mena-sw button.on{background:#fff;color:#1d1d1f}
  .top .kurz{font-size:11px;opacity:.8;margin-top:6px}
  /* 🆕 v3.0.645 — nenápadný přepínač jazyka (vpravo nahoře nad zeleným záhlavím) */
  .lang-sw{position:absolute;top:6px;right:8px;z-index:5;display:flex;gap:2px;font-size:11px;font-weight:700;letter-spacing:.02em}
  .lang-sw a{color:#fff;opacity:.7;text-decoration:none;padding:4px 5px;border-radius:6px}
  .lang-sw a:hover,.lang-sw a:focus-visible{opacity:1;background:rgba(255,255,255,.18)}
  .lang-sw a.on{opacity:1;text-decoration:underline;text-underline-offset:3px}
  .top{position:relative}
</style>
</head>
<body>

<?= web_lang_switcher($L) ?>
<div id="app">
  <div class="loading">⏳ <?= htmlspecialchars(web_t($L, 'Načítám účtenku…')) ?></div>
</div>

<script>
<?= web_i18n_js($L) ?>
</script>
<script>
const API = '../api/pay_qr.php';   // 🆕 v3.0.645 — relativně (instalace v podsložce; dřív /api → 404)
const TOKEN = <?= json_encode($token) ?>;
const JUST_PAID = <?= json_encode($justPaid) ?>;

function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function fmt(n) { return NUM(n, 2, 2); }   // 🆕 v3.0.645 — formát čísla dle jazyka (cs beze změny)
// 🆕 v3.0.645 — číslo účtenky účtu stolu („Účet #12") v jazyce stránky
function cisloTxt(c) { const m = /^Účet #(\d+)$/.exec(String(c ?? '')); return m ? T('Účet #{id}', { id: m[1] }) : String(c ?? ''); }
// 🆕 v3.0.645 — datum/čas zaplacení: cs jako dřív (2026-11-17 14:30), ostatní jazyky dle locale
function casTxt(s) {
  const raw = String(s).slice(0, 16).replace('T', ' ');
  if (LANG === 'cs') return raw;
  const d = new Date(raw.replace(' ', 'T'));
  return isNaN(d) ? raw : d.toLocaleString(LOCALE, { day: 'numeric', month: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
const METODY = { cash: 'hotovost', card_terminal: 'kartou (terminál)', voucher: 'voucher', stripe: 'Stripe', gopay: 'GoPay' };
function metodaTxt(m) { return LANG === 'cs' ? m : (METODY[m] ? T(METODY[m]) : m); }

let pollTimer = null;
// 💱 v3.0.643 — měna platby (účet stolu: host volí Kč / €; objednávka: měna dokladu pevná)
let INFO = null, MENA = null;
const sym = (m) => SYM(m);   // 🆕 v3.0.645 — Kč (cs/sk) / CZK (en/es/de), € …
function setMena(m) { MENA = m; renderApp(INFO); }

async function loadInfo() {
  try {
    const r = await fetch(`${API}?action=info&t=${encodeURIComponent(TOKEN)}`);
    const d = await r.json().catch(() => ({}));
    if (!r.ok || !d.ok) throw new Error(d.error ? TS(d.error) : T('Účtenka nenalezena'));
    renderApp(d);
  } catch (e) {
    document.getElementById('app').innerHTML = `
      <div class="container"><div class="error">❌ ${esc(e instanceof TypeError || e instanceof SyntaxError ? T('Něco se nepovedlo. Zkuste to prosím znovu.') : e.message)}</div></div>
    `;
  }
}

function renderApp(d) {
  INFO = d;
  const o = Object.assign({}, d.order);
  const items = d.items || [];
  const methods = Object.assign({}, d.methods || {});
  const mz = d.moznosti_men || {};
  if (!MENA || !mz[MENA]) MENA = o.mena || 'CZK';
  const cur = mz[MENA] || { castka: o.castka, polozky: [] };
  o.castka = cur.castka;
  const S = sym(MENA);
  // brána, která zvolenou měnu neumí, se skryje
  const mm = d.methods_meny || {};
  ['stripe', 'gopay'].forEach(k => { if (methods[k] && mm[k] && !mm[k].includes(MENA)) methods[k] = false; });

  // Pokud zaplaceno — ukaž potvrzení
  if (o.pay_status === 'paid') {
    document.getElementById('app').innerHTML = `
      <div class="top">
        <h1>✅ ${esc(T('Zaplaceno'))}</h1>
        <div class="firma">${esc(d.firma.firma_nazev || T('Děkujeme'))}</div>
      </div>
      <div class="container">
        <div class="card status-card">
          <span class="big-ic paid-ic">✅</span>
          <h2>${esc(T('Platba úspěšná'))}</h2>
          <p class="sub">
            ${esc(T('Účtenka'))} <strong>${esc(cisloTxt(o.cislo))}</strong><br>
            ${esc(T('Částka:'))} <strong>${fmt(o.castka)} ${esc(S)}</strong><br>
            ${o.paid_at ? `${esc(T('Zaplaceno:'))} ${esc(casTxt(o.paid_at))}` : ''}<br>
            ${o.pay_method ? `${esc(T('Metoda:'))} ${esc(metodaTxt(o.pay_method))}` : ''}
          </p>
        </div>
        <div class="powered">Powered by APPEK · <a href="https://appek.cz">appek.cz</a></div>
      </div>
    `;
    return;
  }

  // Pokud pending_manual — informace pro hosta
  if (o.pay_status === 'pending_manual') {
    document.getElementById('app').innerHTML = `
      <div class="top">
        <h1>🙋 ${esc(T('Čekám na číšníka'))}</h1>
        <div class="firma">${esc(d.firma.firma_nazev || '')}</div>
        <div class="amount">${fmt(o.castka)}<span class="currency">${esc(S)}</span></div>
        <div class="cislo">${esc(cisloTxt(o.cislo))}</div>
      </div>
      <div class="container">
        <div class="card status-card">
          <span class="big-ic">🙋</span>
          <h2>${esc(T('Číšník je informován'))}</h2>
          <p class="sub">${esc(T('Přijde si pro platbu hotovostí.'))}<br>${esc(T('Stránka se obnoví automaticky.'))}</p>
        </div>
        <div class="powered">Powered by APPEK · <a href="https://appek.cz">appek.cz</a></div>
      </div>
    `;
    startPolling();
    return;
  }

  // Normální stav — pending, ukaž volby platby
  document.getElementById('app').innerHTML = `
    <div class="top">
      <h1>${esc(T('Zaplatit účtenku'))}</h1>
      <div class="firma">${esc(d.firma.firma_nazev || '')}</div>
      <div class="amount">${fmt(o.castka)}<span class="currency">${esc(S)}</span></div>
      <div class="cislo">${esc(cisloTxt(o.cislo))}</div>
      ${!o.mena_pevna && Object.keys(mz).length > 1 ? `
        <div class="mena-sw">${Object.keys(mz).map(m => `<button type="button" class="${m === MENA ? 'on' : ''}" onclick="setMena('${m}')">${m === 'CZK' ? esc(sym('CZK')) : esc(sym(m)) + ' ' + m}</button>`).join('')}</div>
        ${MENA !== 'CZK' ? `<div class="kurz">1 ${esc(S)} = ${NUM(+cur.kurz_prodej || +cur.kurz, 0, 3)} ${esc(sym('CZK'))}</div>` : ''}` : ''}
    </div>

    <div class="container">
      ${JUST_PAID && o.pay_status !== 'failed' ? `<div class="card" style="background:#FEF3C7;border-color:#FCD34D"><p style="margin:0;font-size:13px">⏳ ${esc(T('Platba se zpracovává… stránka se obnoví automaticky.'))}</p></div>` : ''}
      ${o.pay_status === 'failed' ? `<div class="error">❌ ${esc(T('Platba se nezdařila nebo byla zamítnuta. Zkuste to prosím znovu.'))}</div>` : ''}

      ${items.length > 0 ? `
        <div class="card">
          <h2>📋 ${esc(T('Účtenka'))}</h2>
          <div class="items">
            ${items.map((it, i) => `
              <div class="item">
                <span class="qty">${parseFloat(it.mnozstvi)}×</span>
                <span class="name">${esc(it.nazev || '?')}</span>
                <span class="price">${fmt((cur.polozky || [])[i] != null ? cur.polozky[i] : it.castka)} ${esc(S)}</span>
              </div>
            `).join('')}
          </div>
        </div>
      ` : ''}

      <div class="card">
        <h2>💳 ${esc(T('Vybrat platbu'))}</h2>
        ${methods.stripe ? `
          <button class="pay-btn stripe" onclick="payStripe()">
            <span class="ic">💳</span>${esc(T('Kartou online (Stripe)'))}
          </button>
        ` : ''}
        ${methods.gopay ? `
          <button class="pay-btn gopay" onclick="payGopay()">
            <span class="ic">🔴</span>${esc(T('Kartou / bankou (GoPay)'))}
          </button>
        ` : ''}
        <button class="pay-btn manual" onclick="payManual('cash')">
          <span class="ic">💵</span>${esc(T('Zaplatím hotovostí číšníkovi'))}
        </button>
        ${!methods.stripe && !methods.gopay ? `
          <p style="font-size:11px;color:#9CA3AF;margin-top:10px;text-align:center">
            ${esc(T('Online platby nejsou nakonfigurované.'))}
          </p>
        ` : ''}
      </div>

      <div class="powered">Powered by APPEK · <a href="https://appek.cz">appek.cz</a></div>
    </div>
  `;

  if (JUST_PAID && o.pay_status !== 'failed') startPolling();
}

async function payStripe() {
  try {
    const r = await fetch(`${API}?action=stripe_init`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: TOKEN, mena: MENA, lang: LANG }),   // 🆕 v3.0.645 — návrat z brány ve stejném jazyce
    });
    const d = await r.json().catch(() => ({}));
    if (!r.ok || !d.ok) throw new Error(d.error ? TS(d.error) : T('Stripe init selhal'));
    location.href = d.redirect;
  } catch (e) {
    alert(T('Chyba') + ': ' + (e instanceof TypeError ? T('Něco se nepovedlo. Zkuste to prosím znovu.') : e.message));
  }
}

async function payGopay() {
  try {
    const r = await fetch(`${API}?action=gopay_init`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: TOKEN, mena: MENA, lang: LANG }),   // 🆕 v3.0.645 — návrat z brány ve stejném jazyce
    });
    const d = await r.json().catch(() => ({}));
    if (!r.ok || !d.ok) throw new Error(d.error ? TS(d.error) : T('GoPay init selhal'));
    location.href = d.redirect;
  } catch (e) {
    alert(T('Chyba') + ': ' + (e instanceof TypeError ? T('Něco se nepovedlo. Zkuste to prosím znovu.') : e.message));
  }
}

async function payManual(method) {
  if (!confirm(T('Označit jako "platím hotovostí číšníkovi"? Číšník bude informován a přijde si pro platbu.'))) return;
  try {
    const r = await fetch(`${API}?action=mark_paid_manual`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: TOKEN, method }),
    });
    const d = await r.json().catch(() => ({}));
    if (!r.ok || !d.ok) throw new Error(d.error ? TS(d.error) : T('Něco se nepovedlo. Zkuste to prosím znovu.'));
    loadInfo();
  } catch (e) {
    alert(T('Chyba') + ': ' + (e instanceof TypeError ? T('Něco se nepovedlo. Zkuste to prosím znovu.') : e.message));
  }
}

function startPolling() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = setInterval(async () => {
    try {
      const r = await fetch(`${API}?action=status&t=${encodeURIComponent(TOKEN)}`);
      const d = await r.json();
      if (d.ok && (d.pay_status === 'paid' || d.pay_status === 'failed')) {
        clearInterval(pollTimer);
        loadInfo();
      }
    } catch (e) {}
  }, 4000);
}

loadInfo();
</script>
</body>
</html>
