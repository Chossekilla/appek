<?php
/**
 * 🛍️ v3.0.626 — ONLINE OBJEDNÁVKY JÍDLA (vyzvednutí / rozvoz) pro koncové zákazníky.
 *
 * URL: /objednat/            → nabídka → košík → objednávka
 *      /objednat/?stav=TOKEN → sledování stavu objednávky (auto-refresh)
 *
 * Jen SPA shell — veškerá logika a ceny v /api/web_objednavky.php.
 * Bez dekorativních emoji (na Windows/starších zařízeních se renderují jako boxíky).
 */
header('Cache-Control: no-store');
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Objednávka online</title>
<meta name="theme-color" content="#c2410c">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  :root{--acc:#c2410c;--acc2:#9a3412;--bg:#faf9f7;--card:#fff;--tx:#1c1917;--tx2:#57534e;--tx3:#78716c;--ln:#e7e5e4;--ok:#15803d}
  html,body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--tx);-webkit-text-size-adjust:100%}
  body{padding-bottom:96px;min-height:100dvh}
  button{font-family:inherit}
  .top{background:linear-gradient(135deg,var(--acc),var(--acc2));color:#fff;padding:16px 16px 14px}
  .top-in{max-width:760px;margin:0 auto;display:flex;align-items:center;gap:12px}
  .top img{width:44px;height:44px;border-radius:10px;object-fit:contain;background:#fff;flex-shrink:0}
  .top h1{font-size:19px;font-weight:750;line-height:1.2}
  .top .sub{font-size:13px;opacity:.92;margin-top:2px}
  .wrap{max-width:760px;margin:0 auto;padding:0 16px}
  .note{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:12px;padding:10px 12px;font-size:13.5px;margin-top:12px;line-height:1.45}
  .rules{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}
  .rule{font-size:12.5px;background:#fff;border:1px solid var(--ln);border-radius:999px;padding:5px 10px;color:var(--tx2)}
  .cats{position:sticky;top:0;z-index:10;background:var(--bg);padding:10px 0;display:flex;gap:6px;overflow-x:auto;scrollbar-width:none;margin-top:6px}
  .cats::-webkit-scrollbar{display:none}
  .cat{padding:8px 14px;border-radius:999px;background:#fff;border:1px solid var(--ln);color:var(--tx2);font-size:13.5px;font-weight:600;white-space:nowrap;cursor:pointer;flex-shrink:0}
  .cat.on{background:var(--acc);border-color:var(--acc);color:#fff}
  .sec h2{font-size:13px;font-weight:750;color:var(--acc2);text-transform:uppercase;letter-spacing:.5px;margin:16px 2px 8px}
  .item{background:var(--card);border:1.5px solid var(--ln);border-radius:14px;padding:12px;margin-bottom:8px;display:flex;gap:12px;align-items:center}
  .item.in{border-color:var(--acc);background:#fff7ed}
  .item img{width:64px;height:64px;border-radius:10px;object-fit:cover;flex-shrink:0;background:#f5f5f4}
  .item .inf{flex:1;min-width:0}
  .item .nm{font-size:15px;font-weight:650;line-height:1.3}
  .item .ds{font-size:12.5px;color:var(--tx3);line-height:1.4;margin-top:2px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
  .item .al{font-size:11.5px;color:var(--tx3);margin-top:2px}
  .item .pr{font-size:15px;font-weight:750;color:var(--acc2);margin-top:4px;white-space:nowrap}
  .qty{display:flex;align-items:center;gap:4px;flex-shrink:0}
  .qty button{width:38px;height:38px;border-radius:50%;border:1.5px solid var(--acc);background:#fff;color:var(--acc);font-size:20px;font-weight:700;cursor:pointer;line-height:1}
  .qty button.add{background:var(--acc);color:#fff}
  .qty span{min-width:22px;text-align:center;font-weight:750;font-size:15px}
  .bar{position:fixed;left:0;right:0;bottom:0;z-index:20;background:#fff;border-top:1px solid var(--ln);box-shadow:0 -6px 20px rgba(0,0,0,.07);padding:12px 16px calc(12px + env(safe-area-inset-bottom))}
  .bar-in{max-width:760px;margin:0 auto;display:flex;align-items:center;gap:12px}
  .bar .sum{flex:1;min-width:0}
  .bar .sum small{display:block;font-size:12px;color:var(--tx3)}
  .bar .sum b{font-size:19px;white-space:nowrap}
  .btn{padding:13px 20px;border-radius:12px;border:none;background:var(--acc);color:#fff;font-weight:750;font-size:15px;cursor:pointer;white-space:nowrap}
  .btn:disabled{opacity:.45;cursor:not-allowed}
  .btn.full{width:100%}
  .sheet-bg{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;z-index:40;align-items:flex-end;justify-content:center}
  .sheet-bg.show{display:flex}
  .sheet{background:#fff;width:100%;max-width:560px;max-height:92dvh;overflow-y:auto;border-radius:18px 18px 0 0;padding:18px 16px calc(18px + env(safe-area-inset-bottom))}
  @media (min-width:600px){.sheet-bg{align-items:center}.sheet{border-radius:18px}}
  .sheet h3{font-size:18px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center}
  .sheet h3 button{border:none;background:#f5f5f4;width:34px;height:34px;border-radius:50%;font-size:18px;cursor:pointer}
  .seg{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:14px}
  .seg button{padding:11px;border-radius:10px;border:1.5px solid var(--ln);background:#fff;font-weight:650;font-size:14px;cursor:pointer;color:var(--tx2)}
  .seg button.on{border-color:var(--acc);background:#fff7ed;color:var(--acc2)}
  .fld{display:block;margin-bottom:10px}
  .fld span{display:block;font-size:12.5px;font-weight:650;color:var(--tx2);margin-bottom:4px}
  .fld input,.fld select,.fld textarea{width:100%;padding:11px 12px;border:1.5px solid var(--ln);border-radius:10px;font-size:16px;font-family:inherit;background:#fff;color:var(--tx)}
  .fld textarea{min-height:64px;resize:vertical}
  .row2{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:8px}
  .row-psc{display:grid;grid-template-columns:110px minmax(0,1fr);gap:8px}
  .lines{background:var(--bg);border-radius:10px;padding:8px 12px;margin:4px 0 12px;font-size:14px}
  .lines div{display:flex;justify-content:space-between;gap:10px;padding:4px 0}
  .lines div span:last-child{white-space:nowrap;font-weight:650}
  .lines .tot{border-top:1px solid var(--ln);margin-top:4px;padding-top:8px;font-size:16px}
  .chk{display:flex;gap:10px;align-items:flex-start;font-size:13px;color:var(--tx2);margin:10px 0 14px;line-height:1.45}
  .chk input{width:20px;height:20px;flex-shrink:0;margin-top:1px}
  .chk a{color:var(--acc2)}
  .err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;border-radius:10px;padding:10px 12px;font-size:13.5px;margin-bottom:10px;display:none}
  .hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0}
  .empty{text-align:center;padding:48px 16px;color:var(--tx2)}
  .empty h2{font-size:19px;color:var(--tx);margin-bottom:8px}
  .st-card{background:#fff;border:1px solid var(--ln);border-radius:16px;padding:18px;margin-top:16px}
  .st-card h2{font-size:20px;margin-bottom:4px}
  .st-card .meta{color:var(--tx2);font-size:14px;line-height:1.5}
  .steps{list-style:none;margin:16px 0 4px}
  .steps li{display:flex;gap:12px;align-items:center;padding:9px 0;color:var(--tx3);font-size:15px}
  .steps li .dot{width:26px;height:26px;border-radius:50%;border:2px solid var(--ln);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;flex-shrink:0;background:#fff}
  .steps li.done{color:var(--tx)}
  .steps li.done .dot{background:var(--ok);border-color:var(--ok);color:#fff}
  .steps li.cur{color:var(--tx);font-weight:700}
  .steps li.cur .dot{border-color:var(--acc);color:var(--acc)}
  .muted{color:var(--tx3);font-size:12.5px;margin-top:10px}
  .last-link{display:block;text-align:center;margin-top:12px;font-size:14px;color:var(--acc2);font-weight:650}
</style>
</head>
<body>
<header class="top"><div class="top-in"><img id="logo" alt="" hidden onerror="this.hidden=true"><div><h1 id="firma">Objednávka online</h1><div class="sub" id="sub">Načítám…</div></div></div></header>
<main class="wrap" id="app"></main>
<div class="bar" id="bar" hidden><div class="bar-in"><div class="sum"><small id="bar-cnt"></small><b id="bar-sum"></b></div><button class="btn" id="bar-go">Pokračovat</button></div></div>
<div class="sheet-bg" id="sheet-bg"><div class="sheet" id="sheet"></div></div>

<script>
(function(){
  'use strict';
  var API = '../api/web_objednavky.php';
  var LS = 'appek_web_obj';
  var INFO = null, MENU = null, CART = {}, TYP = 'vyzvednuti', SENDING = false;
  var $ = function(id){ return document.getElementById(id); };
  var esc = function(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
  // celé koruny bez haléřů, jinak vždy 2 místa (39,20 Kč, ne 39,2 Kč)
  var kc = function(n){ var r = Math.round(n * 100) / 100, d = r % 1 ? 2 : 0; return r.toLocaleString('cs-CZ', {minimumFractionDigits: d, maximumFractionDigits: d}) + ' Kč'; };
  var DNY = ['', 'po', 'út', 'st', 'čt', 'pá', 'so', 'ne'];
  function lsGet(){ try { return JSON.parse(localStorage.getItem(LS) || '{}') || {}; } catch (e) { return {}; } }
  function lsSet(o){ try { localStorage.setItem(LS, JSON.stringify(Object.assign(lsGet(), o))); } catch (e) {} }

  async function api(action, opts){
    var r = await fetch(API + '?action=' + action + (opts && opts.q ? '&' + opts.q : ''), opts && opts.body ? {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(opts.body)} : {cache: 'no-store'});
    var j = {}; try { j = await r.json(); } catch (e) {}
    if (!r.ok || j.ok === false) throw new Error(j.error || j.message || ('Chyba ' + r.status));
    return j;
  }

  function header(info){
    if (info.firma) { $('firma').textContent = info.firma; document.title = 'Objednávka — ' + info.firma; }
    if (info.logo) { $('logo').src = info.logo; $('logo').hidden = false; }
  }

  function denLabel(d){
    var dt = new Date(d + 'T12:00:00'), dnes = new Date(); dnes.setHours(12, 0, 0, 0);
    var diff = Math.round((dt - dnes) / 864e5);
    var base = DNY[dt.getDay() === 0 ? 7 : dt.getDay()] + ' ' + dt.getDate() + '. ' + (dt.getMonth() + 1) + '.';
    return diff === 0 ? 'Dnes (' + base + ')' : diff === 1 ? 'Zítra (' + base + ')' : base;
  }
  // časy po 15 min v okně (první = nejbližší možný)
  function casy(okno){
    var out = [okno.od], p = okno.od.split(':'), q = okno.do.split(':');
    var t = +p[0] * 60 + +p[1], end = +q[0] * 60 + +q[1];
    for (var n = Math.ceil((t + 1) / 15) * 15; n <= end; n += 15) out.push(String(Math.floor(n / 60)).padStart(2, '0') + ':' + String(n % 60).padStart(2, '0'));
    return out;
  }

  // ── NABÍDKA ──────────────────────────────────────────────
  function renderMenu(){
    var i = INFO, m = MENU, h = '';
    var bits = [];
    if (i.vyzvednuti) bits.push('vyzvednutí');
    if (i.rozvoz) bits.push('rozvoz');
    $('sub').textContent = 'Online objednávka · ' + bits.join(' i ') + ' · ' + i.od + '–' + i.do;
    if (i.zprava) h += '<div class="note">' + esc(i.zprava) + '</div>';
    var r = [];
    if (i.min_objednavka > 0) r.push('Min. objednávka ' + kc(i.min_objednavka));
    if (i.rozvoz) r.push(i.rozvoz_cena > 0 ? 'Rozvoz ' + kc(i.rozvoz_cena) + (i.rozvoz_zdarma_od > 0 ? ', od ' + kc(i.rozvoz_zdarma_od) + ' zdarma' : '') : 'Rozvoz zdarma');
    r.push('Platba při převzetí');
    if (i.okna.length) r.push('Nejdřív ' + denLabel(i.okna[0].den).replace(/ \(.*\)/, '').toLowerCase() + ' v ' + i.okna[0].od);
    h += '<div class="rules">' + r.map(function(x){ return '<span class="rule">' + esc(x) + '</span>'; }).join('') + '</div>';
    if (!i.okna.length) h += '<div class="note">Momentálně nelze objednat — dnes už je zavřeno a předobjednávky nejsou k dispozici.</div>';
    var byCat = {};
    m.vyrobky.forEach(function(v){ var k = v.kategorie_id || 0; (byCat[k] = byCat[k] || []).push(v); });
    var cats = m.kategorie.filter(function(k){ return byCat[k.id]; });
    if (byCat[0]) cats.push({id: 0, nazev: 'Ostatní'});
    if (cats.length > 1) h += '<nav class="cats" id="cats">' + cats.map(function(k, ix){ return '<button class="cat' + (ix ? '' : ' on') + '" data-k="' + k.id + '">' + esc(k.nazev) + '</button>'; }).join('') + '</nav>';
    cats.forEach(function(k){
      h += '<section class="sec" id="k' + k.id + '"><h2>' + esc(k.nazev) + '</h2>' + byCat[k.id].map(itemHtml).join('') + '</section>';
    });
    if (!m.vyrobky.length) h += '<div class="empty"><h2>Nabídka je zatím prázdná</h2></div>';
    if (lsGet().last) h += '<a class="last-link" href="?stav=' + esc(lsGet().last) + '">Moje poslední objednávka</a>';
    $('app').innerHTML = h;
    var nav = $('cats');
    if (nav) nav.addEventListener('click', function(e){
      var b = e.target.closest('.cat'); if (!b) return;
      nav.querySelectorAll('.cat').forEach(function(x){ x.classList.toggle('on', x === b); });
      var s = $('k' + b.dataset.k); if (s) window.scrollTo({top: s.getBoundingClientRect().top + window.scrollY - nav.offsetHeight - 4, behavior: 'smooth'});
    });
    $('app').addEventListener('click', onQty);
    renderBar();
  }
  function itemHtml(v){
    var q = CART[v.id] || 0;
    return '<div class="item' + (q ? ' in' : '') + '" data-id="' + v.id + '">' +
      (v.obrazek_url ? '<img loading="lazy" src="' + esc(v.obrazek_url) + '" alt="">' : '') +
      '<div class="inf"><div class="nm">' + esc(v.nazev) + '</div>' +
      (v.popis ? '<div class="ds">' + esc(v.popis) + '</div>' : '') +
      (v.alergeny ? '<div class="al">Alergeny: ' + esc(v.alergeny) + '</div>' : '') +
      '<div class="pr">' + kc(v.cena) + (v.hmotnost_g ? ' <span style="color:var(--tx3);font-weight:500;font-size:12.5px">· ' + esc(v.hmotnost_g) + ' g</span>' : '') + '</div></div>' +
      '<div class="qty">' + (q ? '<button data-d="-1" aria-label="Ubrat">−</button><span>' + q + '</span>' : '') + '<button class="add" data-d="1" aria-label="Přidat">+</button></div></div>';
  }
  function onQty(e){
    var b = e.target.closest('.qty button'); if (!b) return;
    var row = b.closest('.item'), id = +row.dataset.id;
    var n = Math.max(0, Math.min(99, (CART[id] || 0) + +b.dataset.d));
    if (n) CART[id] = n; else delete CART[id];
    var v = MENU.vyrobky.find(function(x){ return x.id === id; });
    row.outerHTML = itemHtml(v);
    lsSet({cart: CART});
    renderBar();
  }
  function cartLines(){
    return Object.keys(CART).map(function(id){ var v = MENU.vyrobky.find(function(x){ return x.id === +id; }); return v ? {v: v, n: CART[id]} : null; }).filter(Boolean);
  }
  function zbozi(){ return cartLines().reduce(function(s, l){ return s + l.n * l.v.cena; }, 0); }
  function doprava(){
    if (TYP !== 'rozvoz' || !(INFO.rozvoz_cena > 0)) return 0;
    return INFO.rozvoz_zdarma_od > 0 && zbozi() >= INFO.rozvoz_zdarma_od ? 0 : INFO.rozvoz_cena;
  }
  function renderBar(){
    var l = cartLines(), cnt = l.reduce(function(s, x){ return s + x.n; }, 0);
    $('bar').hidden = !cnt;
    if (!cnt) return;
    $('bar-cnt').textContent = cnt + ' ks v košíku';
    $('bar-sum').textContent = kc(zbozi());
    $('bar-go').disabled = !INFO.okna.length;
  }

  // ── POKLADNA ─────────────────────────────────────────────
  function openCheckout(){
    var i = INFO, s = lsGet().kontakt || {};
    if (!i.vyzvednuti) TYP = 'rozvoz'; else if (!i.rozvoz) TYP = 'vyzvednuti';
    var h = '<h3>Dokončit objednávku <button type="button" id="x" aria-label="Zavřít">×</button></h3><div class="err" id="err"></div>';
    if (i.vyzvednuti && i.rozvoz) h += '<div class="seg" id="seg"><button type="button" data-t="vyzvednuti">Vyzvednu si</button><button type="button" data-t="rozvoz">Rozvoz</button></div>';
    h += '<div class="row2"><label class="fld"><span>Den</span><select id="f-den">' + i.okna.map(function(o, ix){ return '<option value="' + ix + '">' + esc(denLabel(o.den)) + '</option>'; }).join('') + '</select></label>' +
         '<label class="fld"><span id="l-cas">Čas</span><select id="f-cas"></select></label></div>';
    h += '<div id="adr"><label class="fld"><span>Ulice a číslo</span><input id="f-adresa" autocomplete="street-address" value="' + esc(s.adresa) + '"></label>' +
         '<div class="row-psc"><label class="fld"><span>PSČ</span><input id="f-psc" inputmode="numeric" autocomplete="postal-code" maxlength="6" value="' + esc(s.psc) + '"></label>' +
         '<label class="fld"><span>Město</span><input id="f-mesto" autocomplete="address-level2" value="' + esc(s.mesto) + '"></label></div></div>';
    h += '<label class="fld"><span>Jméno</span><input id="f-jmeno" autocomplete="name" value="' + esc(s.jmeno) + '"></label>' +
         '<div class="row2"><label class="fld"><span>Telefon</span><input id="f-tel" type="tel" autocomplete="tel" value="' + esc(s.tel) + '"></label>' +
         '<label class="fld"><span>E-mail (potvrzení)</span><input id="f-email" type="email" autocomplete="email" value="' + esc(s.email) + '"></label></div>' +
         '<label class="fld"><span>Poznámka (nepovinné)</span><textarea id="f-pozn" maxlength="500"></textarea></label>' +
         '<input class="hp" id="f-web" tabindex="-1" autocomplete="off" aria-hidden="true">';
    h += '<div class="lines" id="lines"></div>';
    h += '<label class="chk"><input type="checkbox" id="f-souhlas"><span>Souhlasím se <a href="#" id="gdpr">zpracováním osobních údajů</a> pro vyřízení objednávky.</span></label>';
    h += '<button class="btn full" id="send">Objednat</button><p class="muted" id="pay-note"></p>';
    $('sheet').innerHTML = h;
    $('sheet-bg').classList.add('show');
    $('x').onclick = closeSheet;
    var seg = $('seg');
    if (seg) seg.onclick = function(e){ var b = e.target.closest('button'); if (b) { TYP = b.dataset.t; syncTyp(); } };
    $('f-den').onchange = fillCasy;
    $('gdpr').onclick = showGdpr;
    $('send').onclick = send;
    fillCasy(); syncTyp();
  }
  function fillCasy(){
    var o = INFO.okna[+$('f-den').value];
    $('f-cas').innerHTML = casy(o).map(function(c, ix){ return '<option' + (ix ? '' : ' selected') + '>' + c + '</option>'; }).join('');
  }
  function syncTyp(){
    var seg = $('seg');
    if (seg) seg.querySelectorAll('button').forEach(function(b){ b.classList.toggle('on', b.dataset.t === TYP); });
    $('adr').hidden = TYP !== 'rozvoz';
    $('l-cas').textContent = TYP === 'rozvoz' ? 'Doručit v' : 'Vyzvednu v';
    $('pay-note').textContent = 'Platíte až při ' + (TYP === 'rozvoz' ? 'převzetí' : 'vyzvednutí') + ' (hotově nebo kartou).';
    var l = cartLines(), d = doprava(), z = zbozi();
    $('lines').innerHTML = l.map(function(x){ return '<div><span>' + x.n + '× ' + esc(x.v.nazev) + '</span><span>' + kc(x.n * x.v.cena) + '</span></div>'; }).join('') +
      (TYP === 'rozvoz' ? '<div><span>Doprava</span><span>' + (d ? kc(d) : 'zdarma') + '</span></div>' : '') +
      '<div class="tot"><span>Celkem</span><span>' + kc(z + d) + '</span></div>' +
      (INFO.min_objednavka > 0 && z < INFO.min_objednavka ? '<div style="color:#b91c1c;font-size:13px">Minimální objednávka je ' + kc(INFO.min_objednavka) + '</div>' : '');
  }
  function closeSheet(){ $('sheet-bg').classList.remove('show'); }
  function err(m){ var e = $('err'); e.textContent = m; e.style.display = 'block'; $('sheet').scrollTop = 0; }
  async function showGdpr(e){
    e.preventDefault();
    try {
      var r = await fetch('../api/gdpr_verejne.php').then(function(x){ return x.json(); });
      var w = window.open('', '_blank');
      if (w) { w.document.title = 'Zpracování osobních údajů'; w.document.body.style.cssText = 'font-family:sans-serif;max-width:720px;margin:24px auto;padding:0 16px;line-height:1.55;white-space:pre-wrap'; w.document.body.textContent = r.text || ''; }
    } catch (x) {}
  }
  async function send(){
    if (SENDING) return;
    var g = function(id){ var el = $(id); return el ? el.value.trim() : ''; };
    var o = INFO.okna[+$('f-den').value];
    var body = {
      typ: TYP, cas: o.den + 'T' + $('f-cas').value,
      jmeno: g('f-jmeno'), telefon: g('f-tel'), email: g('f-email'), poznamka: g('f-pozn'),
      adresa: g('f-adresa'), psc: g('f-psc'), mesto: g('f-mesto'),
      souhlas: $('f-souhlas').checked, web: g('f-web'),
      polozky: cartLines().map(function(x){ return {id: x.v.id, mnozstvi: x.n}; })
    };
    if (!body.jmeno) return err('Vyplňte jméno.');
    if (body.telefon.replace(/\D/g, '').length < 9) return err('Vyplňte telefon.');
    if (TYP === 'rozvoz' && (!body.adresa || !body.mesto || body.psc.replace(/\D/g, '').length !== 5)) return err('Vyplňte adresu, PSČ a město.');
    if (!body.souhlas) return err('Potvrďte prosím souhlas se zpracováním údajů.');
    SENDING = true; $('send').disabled = true; $('send').textContent = 'Odesílám…';
    try {
      var r = await api('order', {body: body});
      lsSet({kontakt: {jmeno: body.jmeno, tel: body.telefon, email: body.email, adresa: body.adresa, psc: body.psc, mesto: body.mesto}, cart: {}, last: r.token});
      CART = {};
      location.href = '?stav=' + encodeURIComponent(r.token) + '&nova=1';
    } catch (x) {
      err(x.message);
      SENDING = false; $('send').disabled = false; $('send').textContent = 'Objednat';
    }
  }

  // ── STAV OBJEDNÁVKY ──────────────────────────────────────
  var KROKY_V = [['prijato', 'Přijato'], ['pripravuje', 'Připravujeme'], ['pripraveno', 'Připraveno k vyzvednutí'], ['hotovo', 'Vydáno']];
  var KROKY_R = [['prijato', 'Přijato'], ['pripravuje', 'Připravujeme'], ['na_ceste', 'Na cestě'], ['hotovo', 'Doručeno']];
  async function renderStav(token){
    $('bar').hidden = true;
    try {
      var s = await api('stav', {q: 't=' + encodeURIComponent(token)});
      if (s.firma) { $('firma').textContent = s.firma; document.title = 'Objednávka ' + s.cislo + ' — ' + s.firma; }
      $('sub').textContent = 'Stav objednávky';
      var kroky = s.typ === 'rozvoz' ? KROKY_R : KROKY_V;
      var krok = s.krok === 'pripraveno' && s.typ === 'rozvoz' ? 'pripravuje' : s.krok;
      var idx = kroky.findIndex(function(k){ return k[0] === krok; });
      var cas = s.cas ? new Date(s.cas.replace(' ', 'T')) : null;
      var casTxt = cas ? cas.toLocaleString('cs-CZ', {weekday: 'short', day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit'}) : '';
      var h = '<div class="st-card">';
      if (/[?&]nova=1/.test(location.search)) h += '<div class="note" style="margin:0 0 12px;background:#f0fdf4;border-color:#bbf7d0;color:#166534">Děkujeme, objednávku jsme přijali.</div>';
      h += '<h2>Objednávka ' + esc(s.cislo) + '</h2><div class="meta">' + (s.typ === 'rozvoz' ? 'Rozvoz' + (s.adresa ? ' · ' + esc(s.adresa) : '') : 'Vyzvednutí') + (casTxt ? '<br>' + esc(casTxt) : '') +
           '<br>' + (s.zaplaceno ? 'Zaplaceno' : 'K úhradě při ' + (s.typ === 'rozvoz' ? 'převzetí' : 'vyzvednutí')) + ': <b>' + kc(s.celkem) + '</b></div>';
      if (s.krok === 'zruseno') h += '<div class="note">Objednávka byla zrušena. V případě dotazů nás prosím kontaktujte.</div>';
      else h += '<ol class="steps">' + kroky.map(function(k, ix){ var c = ix < idx || (ix === idx && krok === 'hotovo') ? 'done' : ix === idx ? 'cur' : ''; return '<li class="' + c + '"><span class="dot">' + (c === 'done' ? '✓' : ix + 1) + '</span>' + k[1] + '</li>'; }).join('') + '</ol>';
      var bezi = s.krok !== 'hotovo' && s.krok !== 'zruseno';
      if (bezi) h += '<p class="muted">Stránka se sama obnovuje.</p>';
      h += '</div><a class="last-link" href="./">Nová objednávka</a>';
      $('app').innerHTML = h;
      if (bezi) setTimeout(function(){ renderStav(token); }, 30000);
    } catch (x) {
      $('sub').textContent = '';
      $('app').innerHTML = '<div class="empty"><h2>Objednávka nenalezena</h2><p>' + esc(x.message) + '</p><a class="last-link" href="./">Zpět na nabídku</a></div>';
    }
  }

  async function start(){
    var tok = (location.search.match(/[?&]stav=([a-f0-9]{24})/) || [])[1];
    try {
      INFO = await api('info');
      header(INFO);
      if (tok) return renderStav(tok);
      if (!INFO.aktivni) {
        $('sub').textContent = '';
        $('app').innerHTML = '<div class="empty"><h2>Online objednávky teď nepřijímáme</h2><p>Zkuste to prosím později.</p></div>' + (lsGet().last ? '<a class="last-link" href="?stav=' + esc(lsGet().last) + '">Moje poslední objednávka</a>' : '');
        return;
      }
      MENU = await api('menu');
      var saved = lsGet().cart || {};
      Object.keys(saved).forEach(function(id){ if (MENU.vyrobky.some(function(v){ return v.id === +id; })) CART[id] = Math.min(99, +saved[id] || 0); });
      renderMenu();
    } catch (x) {
      $('sub').textContent = '';
      $('app').innerHTML = '<div class="empty"><h2>Nepodařilo se načíst nabídku</h2><p>' + esc(x.message) + '</p></div>';
    }
  }
  $('bar-go').onclick = openCheckout;
  $('sheet-bg').addEventListener('click', function(e){ if (e.target === this) closeSheet(); });
  start();
})();
</script>
</body>
</html>
