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
// 🆕 v3.0.645 — stránka v jazycích cs/en/es/de/sk: ?lang= → Accept-Language → cs (bez DB; odkaz na stav
//   objednávky z e-mailu nese ?lang= jazyka, ve kterém zákazník objednával)
require_once __DIR__ . '/../api/_i18n_web.php';
[$L, $Lsrc] = web_lang_resolve();
header('Vary: Accept-Language');
header('Content-Language: ' . $L);
$h = fn(string $k) => htmlspecialchars(web_t($L, $k), ENT_QUOTES);
?><!DOCTYPE html>
<html lang="<?= $L ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= $h('Objednávka online') ?></title>
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
  .vch-row{display:flex;gap:8px}
  .vch-row input{flex:1;min-width:0;text-transform:uppercase;letter-spacing:.03em}
  .btn.sec{background:#fff;color:var(--acc);border:1.5px solid var(--acc);padding:10px 14px;font-size:14px}
  .vch-ok{display:flex;align-items:center;gap:8px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:9px 12px;font-size:13.5px;margin-bottom:12px}
  .vch-ok b{font-family:ui-monospace,Menlo,monospace}
  .vch-ok span{flex:1;min-width:0}
  .vch-ok button{background:none;border:none;color:#166534;font-size:20px;line-height:1;cursor:pointer;padding:0 2px}
  .lines .vch-l{color:#166534}
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
  .lines #mena-q{display:block;padding:0}
  .mena-row{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-top:6px}
  .mena-btns{display:inline-flex;gap:6px}
  .mena-b{border:1px solid var(--ln);background:none;color:inherit;border-radius:8px;padding:6px 12px;font:inherit;font-weight:600;cursor:pointer;min-height:34px}
  .mena-b.on{background:var(--acc,#BA7517);border-color:var(--acc,#BA7517);color:#fff}
  .mena-b:disabled{opacity:.45}
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
  /* 🆕 v3.0.645 — nenápadný přepínač jazyka v záhlaví */
  .top-in>div:not(.lang-sw){flex:1;min-width:0}
  .lang-sw{display:flex;gap:1px;flex-shrink:0;align-self:flex-start;font-size:11.5px;font-weight:700;letter-spacing:.02em}
  .lang-sw a{color:#fff;opacity:.7;text-decoration:none;padding:4px 5px;border-radius:6px}
  .lang-sw a:hover,.lang-sw a:focus-visible{opacity:1;background:rgba(255,255,255,.18)}
  .lang-sw a.on{opacity:1;text-decoration:underline;text-underline-offset:3px}
  @media (max-width:380px){.lang-sw{flex-wrap:wrap;max-width:76px;justify-content:flex-end}}
</style>
</head>
<body>
<header class="top"><div class="top-in"><img id="logo" alt="" hidden onerror="this.hidden=true"><div><h1 id="firma"><?= $h('Objednávka online') ?></h1><div class="sub" id="sub"><?= $h('Načítám…') ?></div></div><?= web_lang_switcher($L) ?></div></header>
<main class="wrap" id="app"></main>
<div class="bar" id="bar" hidden><div class="bar-in"><div class="sum"><small id="bar-cnt"></small><b id="bar-sum"></b></div><button class="btn" id="bar-go"><?= $h('Pokračovat') ?></button></div></div>
<div class="sheet-bg" id="sheet-bg"><div class="sheet" id="sheet"></div></div>

<script>
<?= web_i18n_js($L) ?>
var LANG_URL = <?= json_encode($Lsrc === 'url') ?>;
var LANG_SRC = <?= json_encode($Lsrc) ?>;   // 🆕 v3.0.645 — 'vychozi' = jazyk nezjištěn → e-mail dle výchozího jazyka dokladů firmy   // jazyk zvolený v URL → nést ho i do interních odkazů
</script>
<script>
(function(){
  'use strict';
  var API = '../api/web_objednavky.php';
  var LS = 'appek_web_obj';
  var INFO = null, MENU = null, CART = {}, TYP = 'vyzvednuti', SENDING = false;
  var MENA = null;      // 💱 v3.0.643 — měna platby (Kč | €), null = výchozí ze serveru
  var VOUCHER = null;   // {kod, odecet, popis, chovani} — odhad ze serveru (počítá stejně jako objednávka)
  var $ = function(id){ return document.getElementById(id); };
  var esc = function(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
  // celé koruny bez haléřů, jinak vždy 2 místa (39,20 Kč, ne 39,2 Kč)
  //   🆕 v3.0.645 — formát a symbol dle jazyka (MONEY/SYM z _i18n_web.php; čeština beze změny)
  var kc = function(n){ return MONEY(n, 'CZK', true); };
  var fm = function(n, m){ if (!m || m === 'CZK') return kc(n); return MONEY(n, m, false); };
  // 🆕 v3.0.645 — interní odkazy nesou ?lang=, pokud ho zvolil zákazník (přepínač / odkaz z e-mailu)
  var withLang = function(u){ return LANG_URL ? u + (u.indexOf('?') >= 0 ? '&' : '?') + 'lang=' + LANG : u; };
  var bad = function(x){ return x instanceof TypeError || x instanceof SyntaxError ? T('Něco se nepovedlo. Zkuste to prosím znovu.') : (x && x.message) || ''; };
  function menaAktivni(){ var p = INFO && INFO.platba; return !!(p && p.aktivni && (p.prijimane || []).length > 1); }
  var DNY = ['', 'po', 'út', 'st', 'čt', 'pá', 'so', 'ne'];
  function lsGet(){ try { return JSON.parse(localStorage.getItem(LS) || '{}') || {}; } catch (e) { return {}; } }
  function lsSet(o){ try { localStorage.setItem(LS, JSON.stringify(Object.assign(lsGet(), o))); } catch (e) {} }

  async function api(action, opts){
    var r = await fetch(API + '?action=' + action + (opts && opts.q ? '&' + opts.q : ''), opts && opts.body ? {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(opts.body)} : {cache: 'no-store'});
    var j = {}; try { j = await r.json(); } catch (e) {}
    if (!r.ok || j.ok === false) throw new Error(j.error || j.message ? TS(j.error || j.message) : T('Chyba {n}', {n: r.status}));
    return j;
  }

  function header(info){
    if (info.firma) { $('firma').textContent = info.firma; document.title = T('Objednávka — {firma}', {firma: info.firma}); }
    if (info.logo) { $('logo').src = info.logo; $('logo').hidden = false; }
  }

  function denDiff(d){ var dt = new Date(d + 'T12:00:00'), dnes = new Date(); dnes.setHours(12, 0, 0, 0); return Math.round((dt - dnes) / 864e5); }
  // 🆕 v3.0.645 — den dle jazyka (cs beze změny: „po 17. 11."; jinak Intl, např. „Tue 18/11", „Di., 18.11.")
  function denBase(d){
    var dt = new Date(d + 'T12:00:00');
    if (LANG === 'cs') return DNY[dt.getDay() === 0 ? 7 : dt.getDay()] + ' ' + dt.getDate() + '. ' + (dt.getMonth() + 1) + '.';
    return dt.toLocaleDateString(LOCALE, {weekday: 'short', day: 'numeric', month: 'numeric'});
  }
  function denLabel(d){
    var diff = denDiff(d), base = denBase(d);
    return diff === 0 ? T('Dnes ({den})', {den: base}) : diff === 1 ? T('Zítra ({den})', {den: base}) : base;
  }
  // „Nejdřív dnes / zítra / po 17. 11. v 10:30" (cs stejné jako dřív)
  function denKratce(d){ var diff = denDiff(d); return diff === 0 ? T('dnes') : diff === 1 ? T('zítra') : (LANG === 'cs' ? denBase(d).toLowerCase() : denBase(d)); }
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
    if (i.vyzvednuti) bits.push(T('vyzvednutí'));
    if (i.rozvoz) bits.push(T('rozvoz'));
    $('sub').textContent = T('Online objednávka · {typy} · {od}–{do}', {typy: bits.length > 1 ? T('{a} i {b}', {a: bits[0], b: bits[1]}) : bits.join(''), od: i.od, do: i.do});
    if (i.zprava) h += '<div class="note">' + esc(i.zprava) + '</div>';
    var r = [];
    if (i.min_objednavka > 0) r.push(T('Min. objednávka {castka}', {castka: kc(i.min_objednavka)}));
    if (i.rozvoz) r.push(i.rozvoz_cena > 0 ? (i.rozvoz_zdarma_od > 0 ? T('Rozvoz {cena}, od {od} zdarma', {cena: kc(i.rozvoz_cena), od: kc(i.rozvoz_zdarma_od)}) : T('Rozvoz {cena}', {cena: kc(i.rozvoz_cena)})) : T('Rozvoz zdarma'));
    r.push(T('Platba při převzetí'));
    if (i.okna.length) r.push(T('Nejdřív {den} v {cas}', {den: denKratce(i.okna[0].den), cas: i.okna[0].od}));
    h += '<div class="rules">' + r.map(function(x){ return '<span class="rule">' + esc(x) + '</span>'; }).join('') + '</div>';
    if (!i.okna.length) h += '<div class="note">' + esc(T('Momentálně nelze objednat — dnes už je zavřeno a předobjednávky nejsou k dispozici.')) + '</div>';
    var byCat = {};
    m.vyrobky.forEach(function(v){ var k = v.kategorie_id || 0; (byCat[k] = byCat[k] || []).push(v); });
    var cats = m.kategorie.filter(function(k){ return byCat[k.id]; });
    if (byCat[0]) cats.push({id: 0, nazev: T('Ostatní')});
    if (cats.length > 1) h += '<nav class="cats" id="cats">' + cats.map(function(k, ix){ return '<button class="cat' + (ix ? '' : ' on') + '" data-k="' + k.id + '">' + esc(k.nazev) + '</button>'; }).join('') + '</nav>';
    cats.forEach(function(k){
      h += '<section class="sec" id="k' + k.id + '"><h2>' + esc(k.nazev) + '</h2>' + byCat[k.id].map(itemHtml).join('') + '</section>';
    });
    if (!m.vyrobky.length) h += '<div class="empty"><h2>' + esc(T('Nabídka je zatím prázdná')) + '</h2></div>';
    if (lsGet().last) h += '<a class="last-link" href="' + esc(withLang('?stav=' + lsGet().last)) + '">' + esc(T('Moje poslední objednávka')) + '</a>';
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
      (v.alergeny ? '<div class="al">' + esc(T('Alergeny:')) + ' ' + esc(v.alergeny) + '</div>' : '') +
      '<div class="pr">' + kc(v.cena) + (v.hmotnost_g ? ' <span style="color:var(--tx3);font-weight:500;font-size:12.5px">· ' + esc(v.hmotnost_g) + ' g</span>' : '') + '</div></div>' +
      '<div class="qty">' + (q ? '<button data-d="-1" aria-label="' + esc(T('Ubrat')) + '">−</button><span>' + q + '</span>' : '') + '<button class="add" data-d="1" aria-label="' + esc(T('Přidat')) + '">+</button></div></div>';
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
    $('bar-cnt').textContent = T('{n} ks v košíku', {n: cnt});
    $('bar-sum').textContent = kc(zbozi());
    $('bar-go').disabled = !INFO.okna.length;
  }

  // ── POKLADNA ─────────────────────────────────────────────
  function openCheckout(){
    var i = INFO, s = lsGet().kontakt || {};
    if (!i.vyzvednuti) TYP = 'rozvoz'; else if (!i.rozvoz) TYP = 'vyzvednuti';
    var h = '<h3>' + esc(T('Dokončit objednávku')) + ' <button type="button" id="x" aria-label="' + esc(T('Zavřít')) + '">×</button></h3><div class="err" id="err"></div>';
    if (i.vyzvednuti && i.rozvoz) h += '<div class="seg" id="seg"><button type="button" data-t="vyzvednuti">' + esc(T('Vyzvednu si')) + '</button><button type="button" data-t="rozvoz">' + esc(T('Rozvoz')) + '</button></div>';
    h += '<div class="row2"><label class="fld"><span>' + esc(T('Den')) + '</span><select id="f-den">' + i.okna.map(function(o, ix){ return '<option value="' + ix + '">' + esc(denLabel(o.den)) + '</option>'; }).join('') + '</select></label>' +
         '<label class="fld"><span id="l-cas">' + esc(T('Čas')) + '</span><select id="f-cas"></select></label></div>';
    h += '<div id="adr"><label class="fld"><span>' + esc(T('Ulice a číslo')) + '</span><input id="f-adresa" autocomplete="street-address" value="' + esc(s.adresa) + '"></label>' +
         '<div class="row-psc"><label class="fld"><span>' + esc(T('PSČ')) + '</span><input id="f-psc" inputmode="numeric" autocomplete="postal-code" maxlength="6" value="' + esc(s.psc) + '"></label>' +
         '<label class="fld"><span>' + esc(T('Město')) + '</span><input id="f-mesto" autocomplete="address-level2" value="' + esc(s.mesto) + '"></label></div></div>';
    h += '<label class="fld"><span>' + esc(T('Jméno')) + '</span><input id="f-jmeno" autocomplete="name" value="' + esc(s.jmeno) + '"></label>' +
         '<div class="row2"><label class="fld"><span>' + esc(T('Telefon')) + '</span><input id="f-tel" type="tel" autocomplete="tel" value="' + esc(s.tel) + '"></label>' +
         '<label class="fld"><span>' + esc(T('E-mail (potvrzení)')) + '</span><input id="f-email" type="email" autocomplete="email" value="' + esc(s.email) + '"></label></div>' +
         '<label class="fld"><span>' + esc(T('Poznámka (nepovinné)')) + '</span><textarea id="f-pozn" maxlength="500"></textarea></label>' +
         '<input class="hp" id="f-web" tabindex="-1" autocomplete="off" aria-hidden="true">';
    h += '<div id="vch"></div>';
    h += '<div class="lines" id="lines"></div>';
    // souhlas: [ ] v překladu = odkaz na zásady zpracování osobních údajů
    h += '<label class="chk"><input type="checkbox" id="f-souhlas"><span>' + esc(T('Souhlasím se [zpracováním osobních údajů] pro vyřízení objednávky.')).replace(/\[([^\]]+)\]/, '<a href="#" id="gdpr">$1</a>') + '</span></label>';
    h += '<button class="btn full" id="send">' + esc(T('Objednat')) + '</button><p class="muted" id="pay-note"></p>';
    $('sheet').innerHTML = h;
    $('sheet-bg').classList.add('show');
    $('x').onclick = closeSheet;
    var seg = $('seg');
    if (seg) seg.onclick = function(e){ var b = e.target.closest('button'); if (b) { TYP = b.dataset.t; syncTyp(); if (VOUCHER) uplatnitVoucher(VOUCHER.kod); /* doprava změní základ → přepočítat */ } };
    $('f-den').onchange = fillCasy;
    $('gdpr').onclick = showGdpr;
    $('send').onclick = send;
    renderVoucher();
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
    $('l-cas').textContent = TYP === 'rozvoz' ? T('Doručit v') : T('Vyzvednu v');
    $('pay-note').textContent = TYP === 'rozvoz' ? T('Platíte až při převzetí (hotově nebo kartou).') : T('Platíte až při vyzvednutí (hotově nebo kartou).');
    var l = cartLines(), d = doprava(), z = zbozi();
    $('lines').innerHTML = l.map(function(x){ return '<div><span>' + x.n + '× ' + esc(x.v.nazev) + '</span><span>' + kc(x.n * x.v.cena) + '</span></div>'; }).join('') +
      (TYP === 'rozvoz' ? '<div><span>' + esc(T('Doprava')) + '</span><span>' + (d ? kc(d) : esc(T('zdarma'))) + '</span></div>' : '') +
      (VOUCHER && VOUCHER.odecet > 0 ? '<div class="vch-l"><span>' + esc(VOUCHER.chovani === 'platba' ? T('Uhrazeno voucherem') : T('Sleva')) + ' ' + esc(VOUCHER.kod) + '</span><span>−' + kc(VOUCHER.odecet) + '</span></div>' : '') +
      '<div class="tot"><span>' + esc(T('Celkem')) + '</span><span>' + kc(Math.max(0, z + d - (VOUCHER ? VOUCHER.odecet : 0))) + '</span></div>' +
      (INFO.min_objednavka > 0 && z < INFO.min_objednavka ? '<div style="color:#b91c1c;font-size:13px">' + esc(T('Minimální objednávka je {castka}', {castka: kc(INFO.min_objednavka)})) + '</div>' : '') +
      (menaAktivni() ? '<div id="mena-q"></div>' : '');
    if (menaAktivni()) menaQuote();
  }
  // 💱 v3.0.643 — platba v Kč nebo v € (částku v měně počítá server stejně jako doklad/účtenku)
  var _mq = 0;
  async function menaQuote(){
    var my = ++_mq, p = INFO.platba, q = null, e = '';
    try { q = await api('quote', {body: {typ: TYP, mena: MENA || undefined, polozky: cartLines().map(function(x){ return {id: x.v.id, mnozstvi: x.n}; })}}); if (!MENA) MENA = q.mena; }
    catch (x) { e = bad(x); }
    if (my !== _mq) return;
    var el = $('mena-q'); if (!el) return;
    var cur = MENA || p.vychozi || 'CZK';
    el.innerHTML = '<div class="mena-row"><span>' + esc(T('Platit v měně')) + '</span><span class="mena-btns">' + (p.prijimane || []).map(function(m){
        var i = (p.meny || {})[m] || {}, dis = m !== 'CZK' && i.ok === false;
        return '<button type="button" class="mena-b' + (cur === m ? ' on' : '') + '" data-m="' + esc(m) + '"' + (dis ? ' disabled' : '') + '>' + esc(m === 'CZK' ? SYM('CZK') : SYM(m) + ' ' + m) + '</button>';
      }).join('') + '</span></div>' +
      (q && q.mena !== 'CZK' ? '<div class="tot"><span>' + esc(T('K úhradě v {mena}', {mena: q.mena})) + '</span><span>' + fm(q.celkem, q.mena) + '</span></div>' +
        '<div style="font-size:12px;color:var(--tx3)">' + esc(T('Kurz 1 {sym} = {kurz}', {sym: SYM(q.mena), kurz: NUM(+q.kurz_prodej || +q.kurz, 0, 3) + ' ' + SYM('CZK')})) + (VOUCHER ? ' · ' + esc(T('sleva z kódu se odečte v {mena}', {mena: q.mena})) : '') + '</div>' : '') +
      (e ? '<div style="color:#b91c1c;font-size:13px">' + esc(e) + '</div>' : '');
    el.querySelectorAll('.mena-b').forEach(function(b){ b.onclick = function(){ MENA = b.dataset.m; menaQuote(); }; });
  }
  // ── SLEVOVÝ KÓD ──────────────────────────────────────────
  function renderVoucher(){
    var box = $('vch'); if (!box) return;
    box.innerHTML = VOUCHER
      ? '<div class="vch-ok"><span>' + esc(T('Kód')) + ' <b>' + esc(VOUCHER.kod) + '</b> · ' + esc(TS(VOUCHER.popis, true)) + '</span><button type="button" id="vch-x" aria-label="' + esc(T('Odebrat kód')) + '">×</button></div>'
      : '<label class="fld"><span>' + esc(T('Slevový kód / voucher (nepovinné)')) + '</span><div class="vch-row"><input id="f-voucher" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="40">' +
        '<button type="button" class="btn sec" id="vch-go">' + esc(T('Uplatnit')) + '</button></div></label>';
    if (VOUCHER) $('vch-x').onclick = function(){ VOUCHER = null; renderVoucher(); syncTyp(); };
    else {
      $('vch-go').onclick = function(){ uplatnitVoucher($('f-voucher').value); };
      $('f-voucher').onkeydown = function(e){ if (e.key === 'Enter') { e.preventDefault(); uplatnitVoucher(this.value); } };
    }
  }
  async function uplatnitVoucher(kod){
    kod = String(kod || '').trim();
    if (!kod) return err(T('Zadejte slevový kód.'));
    var b = $('vch-go'); if (b) { b.disabled = true; b.textContent = T('Ověřuji…'); }
    try {
      VOUCHER = await api('voucher', {body: {kod: kod, typ: TYP, polozky: cartLines().map(function(x){ return {id: x.v.id, mnozstvi: x.n}; })}});
      $('err').style.display = 'none';
    } catch (x) {
      VOUCHER = null;
      err(bad(x));
    }
    renderVoucher(); syncTyp();
  }
  function closeSheet(){ $('sheet-bg').classList.remove('show'); }
  function err(m){ var e = $('err'); e.textContent = m; e.style.display = 'block'; $('sheet').scrollTop = 0; }
  async function showGdpr(e){
    e.preventDefault();
    try {
      var r = await fetch('../api/gdpr_verejne.php').then(function(x){ return x.json(); });
      var w = window.open('', '_blank');
      if (w) { w.document.documentElement.lang = LANG; w.document.title = T('Zpracování osobních údajů'); w.document.body.style.cssText = 'font-family:sans-serif;max-width:720px;margin:24px auto;padding:0 16px;line-height:1.55;white-space:pre-wrap'; w.document.body.textContent = r.text || ''; }
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
      lang: LANG, lang_src: LANG_SRC,                                            // 🆕 v3.0.645 — jazyk potvrzovacího e-mailu + odkazu na stav
      polozky: cartLines().map(function(x){ return {id: x.v.id, mnozstvi: x.n}; }),
      voucher_kod: VOUCHER ? VOUCHER.kod : g('f-voucher'),  // napsaný, ale neuplatněný kód ověří server při odeslání
      mena: menaAktivni() && MENA ? MENA : undefined         // 💱 v3.0.643
    };
    if (!body.jmeno) return err(T('Vyplňte jméno.'));
    if (body.telefon.replace(/\D/g, '').length < 9) return err(T('Vyplňte telefon.'));
    if (TYP === 'rozvoz' && (!body.adresa || !body.mesto || body.psc.replace(/\D/g, '').length !== 5)) return err(T('Vyplňte adresu, PSČ a město.'));
    if (!body.souhlas) return err(T('Potvrďte prosím souhlas se zpracováním údajů.'));
    SENDING = true; $('send').disabled = true; $('send').textContent = T('Odesílám…');
    try {
      var r = await api('order', {body: body});
      lsSet({kontakt: {jmeno: body.jmeno, tel: body.telefon, email: body.email, adresa: body.adresa, psc: body.psc, mesto: body.mesto}, cart: {}, last: r.token});
      CART = {};
      location.href = withLang('?stav=' + encodeURIComponent(r.token) + '&nova=1');
    } catch (x) {
      err(bad(x));
      SENDING = false; $('send').disabled = false; $('send').textContent = T('Objednat');
    }
  }

  // ── STAV OBJEDNÁVKY ──────────────────────────────────────
  var KROKY_V = [['prijato', T('Přijato')], ['pripravuje', T('Připravujeme')], ['pripraveno', T('Připraveno k vyzvednutí')], ['hotovo', T('Vydáno')]];
  var KROKY_R = [['prijato', T('Přijato')], ['pripravuje', T('Připravujeme')], ['na_ceste', T('Na cestě')], ['hotovo', T('Doručeno')]];
  async function renderStav(token){
    $('bar').hidden = true;
    try {
      var s = await api('stav', {q: 't=' + encodeURIComponent(token)});
      if (s.firma) { $('firma').textContent = s.firma; document.title = T('Objednávka {cislo} — {firma}', {cislo: s.cislo, firma: s.firma}); }
      $('sub').textContent = T('Stav objednávky');
      var kroky = s.typ === 'rozvoz' ? KROKY_R : KROKY_V;
      var krok = s.krok === 'pripraveno' && s.typ === 'rozvoz' ? 'pripravuje' : s.krok;
      var idx = kroky.findIndex(function(k){ return k[0] === krok; });
      var cas = s.cas ? new Date(s.cas.replace(' ', 'T')) : null;
      var casTxt = cas && !isNaN(cas) ? cas.toLocaleString(LOCALE, {weekday: 'short', day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit'}) : '';
      var h = '<div class="st-card">';
      if (/[?&]nova=1/.test(location.search)) h += '<div class="note" style="margin:0 0 12px;background:#f0fdf4;border-color:#bbf7d0;color:#166534">' + esc(T('Děkujeme, objednávku jsme přijali.')) + '</div>';
      h += '<h2>' + esc(T('Objednávka {cislo}', {cislo: s.cislo})) + '</h2><div class="meta">' + (s.typ === 'rozvoz' ? esc(T('Rozvoz')) + (s.adresa ? ' · ' + esc(s.adresa) : '') : esc(T('Vyzvednutí'))) + (casTxt ? '<br>' + esc(casTxt) : '') +
           '<br>' + esc(s.zaplaceno ? T('Zaplaceno') : (s.typ === 'rozvoz' ? T('K úhradě při převzetí') : T('K úhradě při vyzvednutí'))) + ': <b>' + fm(s.celkem_mena != null ? s.celkem_mena : s.celkem, s.mena) + '</b></div>';
      if (s.krok === 'zruseno') h += '<div class="note">' + esc(T('Objednávka byla zrušena. V případě dotazů nás prosím kontaktujte.')) + '</div>';
      else h += '<ol class="steps">' + kroky.map(function(k, ix){ var c = ix < idx || (ix === idx && krok === 'hotovo') ? 'done' : ix === idx ? 'cur' : ''; return '<li class="' + c + '"><span class="dot">' + (c === 'done' ? '✓' : ix + 1) + '</span>' + esc(k[1]) + '</li>'; }).join('') + '</ol>';
      var bezi = s.krok !== 'hotovo' && s.krok !== 'zruseno';
      if (bezi) h += '<p class="muted">' + esc(T('Stránka se sama obnovuje.')) + '</p>';
      h += '</div><a class="last-link" href="' + esc(withLang('./')) + '">' + esc(T('Nová objednávka')) + '</a>';
      $('app').innerHTML = h;
      if (bezi) setTimeout(function(){ renderStav(token); }, 30000);
    } catch (x) {
      $('sub').textContent = '';
      $('app').innerHTML = '<div class="empty"><h2>' + esc(T('Objednávka nenalezena')) + '</h2><p>' + esc(bad(x)) + '</p><a class="last-link" href="' + esc(withLang('./')) + '">' + esc(T('Zpět na nabídku')) + '</a></div>';
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
        $('app').innerHTML = '<div class="empty"><h2>' + esc(T('Online objednávky teď nepřijímáme')) + '</h2><p>' + esc(T('Zkuste to prosím později.')) + '</p></div>' + (lsGet().last ? '<a class="last-link" href="' + esc(withLang('?stav=' + lsGet().last)) + '">' + esc(T('Moje poslední objednávka')) + '</a>' : '');
        return;
      }
      MENU = await api('menu');
      var saved = lsGet().cart || {};
      Object.keys(saved).forEach(function(id){ if (MENU.vyrobky.some(function(v){ return v.id === +id; })) CART[id] = Math.min(99, +saved[id] || 0); });
      renderMenu();
    } catch (x) {
      $('sub').textContent = '';
      $('app').innerHTML = '<div class="empty"><h2>' + esc(T('Nepodařilo se načíst nabídku')) + '</h2><p>' + esc(bad(x)) + '</p></div>';
    }
  }
  $('bar-go').onclick = openCheckout;
  $('sheet-bg').addEventListener('click', function(e){ if (e.target === this) closeSheet(); });
  start();
})();
</script>
</body>
</html>
