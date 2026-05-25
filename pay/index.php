<?php
/**
 * 📲 PAY LANDING — Mobil-first stránka pro hosta po naskenování pay-QR.
 *
 * URL: /pay/?t=<token>
 *
 * Volá /api/pay_qr.php (zde žádný server logic, jen SPA shell).
 */
$token = preg_replace('/[^a-f0-9]/', '', $_GET['t'] ?? '');
if (!$token || strlen($token) < 16) {
    http_response_code(400);
    die('Neplatný platební QR kód');
}
$justPaid = !empty($_GET['paid']);
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover">
<title>💳 Zaplatit účtenku — APPEK</title>
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
</style>
</head>
<body>

<div id="app">
  <div class="loading">⏳ Načítám účtenku…</div>
</div>

<script>
const API = '/api/pay_qr.php';
const TOKEN = <?= json_encode($token) ?>;
const JUST_PAID = <?= json_encode($justPaid) ?>;

function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function fmt(n) { return Number(n || 0).toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

let pollTimer = null;

async function loadInfo() {
  try {
    const r = await fetch(`${API}?action=info&t=${encodeURIComponent(TOKEN)}`);
    const d = await r.json();
    if (!r.ok || !d.ok) throw new Error(d.error || 'Účtenka nenalezena');
    renderApp(d);
  } catch (e) {
    document.getElementById('app').innerHTML = `
      <div class="container"><div class="error">❌ ${esc(e.message)}</div></div>
    `;
  }
}

function renderApp(d) {
  const o = d.order;
  const items = d.items || [];
  const methods = d.methods || {};

  // Pokud zaplaceno — ukaž potvrzení
  if (o.pay_status === 'paid') {
    document.getElementById('app').innerHTML = `
      <div class="top">
        <h1>✅ Zaplaceno</h1>
        <div class="firma">${esc(d.firma.firma_nazev || 'Děkujeme')}</div>
      </div>
      <div class="container">
        <div class="card status-card">
          <span class="big-ic paid-ic">✅</span>
          <h2>Platba úspěšná</h2>
          <p class="sub">
            Účtenka <strong>${esc(o.cislo)}</strong><br>
            Částka: <strong>${fmt(o.castka)} Kč</strong><br>
            ${o.paid_at ? `Zaplaceno: ${esc(String(o.paid_at).slice(0, 16).replace('T', ' '))}` : ''}<br>
            ${o.pay_method ? `Metoda: ${esc(o.pay_method)}` : ''}
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
        <h1>🙋 Čekám na číšníka</h1>
        <div class="firma">${esc(d.firma.firma_nazev || '')}</div>
        <div class="amount">${fmt(o.castka)}<span class="currency">Kč</span></div>
        <div class="cislo">${esc(o.cislo)}</div>
      </div>
      <div class="container">
        <div class="card status-card">
          <span class="big-ic">🙋</span>
          <h2>Číšník je informován</h2>
          <p class="sub">Přijde si pro platbu hotovostí.<br>Stránka se obnoví automaticky.</p>
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
      <h1>Zaplatit účtenku</h1>
      <div class="firma">${esc(d.firma.firma_nazev || '')}</div>
      <div class="amount">${fmt(o.castka)}<span class="currency">Kč</span></div>
      <div class="cislo">${esc(o.cislo)}</div>
    </div>

    <div class="container">
      ${JUST_PAID ? '<div class="card" style="background:#FEF3C7;border-color:#FCD34D"><p style="margin:0;font-size:13px">⏳ Platba se zpracovává… stránka se obnoví automaticky.</p></div>' : ''}

      ${items.length > 0 ? `
        <div class="card">
          <h2>📋 Účtenka</h2>
          <div class="items">
            ${items.map(it => `
              <div class="item">
                <span class="qty">${parseInt(it.mnozstvi)}×</span>
                <span class="name">${esc(it.nazev || '?')}</span>
                <span class="price">${fmt(it.cena_bez_dph * it.mnozstvi * (1 + (parseFloat(it.sazba_dph) || 0) / 100))} Kč</span>
              </div>
            `).join('')}
          </div>
        </div>
      ` : ''}

      <div class="card">
        <h2>💳 Vybrat platbu</h2>
        ${methods.stripe ? `
          <button class="pay-btn stripe" onclick="payStripe()">
            <span class="ic">💳</span>Kartou online (Stripe)
          </button>
        ` : ''}
        ${methods.gopay ? `
          <button class="pay-btn gopay" onclick="payGopay()">
            <span class="ic">🔴</span>Kartou / bankou (GoPay)
          </button>
        ` : ''}
        <button class="pay-btn manual" onclick="payManual('cash')">
          <span class="ic">💵</span>Zaplatím hotovostí číšníkovi
        </button>
        ${!methods.stripe && !methods.gopay ? `
          <p style="font-size:11px;color:#9CA3AF;margin-top:10px;text-align:center">
            Online platby nejsou nakonfigurované.
          </p>
        ` : ''}
      </div>

      <div class="powered">Powered by APPEK · <a href="https://appek.cz">appek.cz</a></div>
    </div>
  `;

  if (JUST_PAID) startPolling();
}

async function payStripe() {
  try {
    const r = await fetch(`${API}?action=stripe_init`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: TOKEN }),
    });
    const d = await r.json();
    if (!r.ok || !d.ok) throw new Error(d.error || 'Stripe init selhal');
    location.href = d.redirect;
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
}

async function payGopay() {
  try {
    const r = await fetch(`${API}?action=gopay_init`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: TOKEN }),
    });
    const d = await r.json();
    if (!r.ok || !d.ok) throw new Error(d.error || 'GoPay init selhal');
    location.href = d.redirect;
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
}

async function payManual(method) {
  if (!confirm('Označit jako "platím hotovostí číšníkovi"? Číšník bude informován a přijde si pro platbu.')) return;
  try {
    const r = await fetch(`${API}?action=mark_paid_manual`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: TOKEN, method }),
    });
    const d = await r.json();
    if (!r.ok || !d.ok) throw new Error(d.error || 'Chyba');
    loadInfo();
  } catch (e) {
    alert('Chyba: ' + e.message);
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
