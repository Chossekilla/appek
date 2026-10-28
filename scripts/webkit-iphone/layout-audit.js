// Audit rozložení adminu ve WebKitu (engine Safari): všechny stránky × 1280×800 / iPhone 430 / iPhone 390.
// Hledá: vodorovné přetečení stránky, prvky za pravým okrajem (mimo posuvné kontejnery), oříznutý/přetékající text.
// Demo login je předvyplněný → jen klik. Výstup: JSON do OUT (default /tmp/appek-layout-audit.json) + souhrn.
const { webkit, devices } = require('playwright');
const fs = require('fs');
const BASE = process.env.BASE || 'https://demo.appek.cz';
const OUT = process.env.OUT || '/tmp/appek-layout-audit.json';
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

const PAGES = (process.env.PAGES || [
  'dashboard', 'objednavky', 'vyroba', 'vyrobni_list', 'vyrobni_kalkulace', 'export_vyroby', 'dodaci_listy', 'rozvozy',
  'faktury', 'vratky', 'vouchery', 'recurring', 'sales_report', 'vyrobky', 'kategorie', 'katalog', 'suroviny', 'sklad',
  'haccp', 'odberatele', 'cenove_skupiny', 'users', 'nastroje', 'stitky', 'tiskarny', 'stanice', 'integrace', 'diagnostika',
  'pkg_cukrarna', 'pkg_lahudky', 'pkg_restaurace', 'pkg_catering', 'pkg_sezona',
  'nastaveni:firma', 'nastaveni:notifikace', 'nastaveni:platby', 'nastaveni:pristupy', 'nastaveni:balicky', 'nastaveni:udrzba', 'nastaveni:napoveda',
].join(',')).split(',');

const VIEWPORTS = [
  { name: '1280', opts: { viewport: { width: 1280, height: 800 } } },
  { name: 'iPhone430', opts: { ...devices['iPhone 14 Pro Max'] } },
  { name: 'iPhone390', opts: { ...devices['iPhone 13'] } },
].filter(v => !process.env.VP || process.env.VP.split(',').includes(v.name));

function detector() {
  const vw = window.innerWidth;
  const desc = (el, r) => {
    const id = el.id ? '#' + el.id : '';
    const cls = (el.className && typeof el.className === 'string') ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '';
    return `${el.tagName.toLowerCase()}${id}${cls} «${(el.innerText || el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40)}» [${Math.round(r.left)}–${Math.round(r.right)}]`;
  };
  const scrollerOrClip = (el) => {
    for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
      const cs = getComputedStyle(p);
      if (/(auto|scroll|hidden|clip)/.test(cs.overflowX)) return true;   // posuvný kontejner / ořízne rodič
    }
    return false;
  };
  const visible = (cs) => cs.display !== 'none' && cs.visibility !== 'hidden' && parseFloat(cs.opacity || '1') > 0.05;
  const out = { pageOverflow: Math.max(0, document.documentElement.scrollWidth - vw, document.body.scrollWidth - vw), beyond: [], clipped: [] };
  const scope = [document.querySelector('#content') || document.body, ...document.querySelectorAll('.topbar, .sidebar, .modal-overlay.open, .bottom-nav')];
  const seen = new Set();
  for (const r0 of scope) for (const el of r0.querySelectorAll('*')) {
    if (seen.has(el)) continue; seen.add(el);
    const cs = getComputedStyle(el);
    if (!visible(cs)) continue;
    const r = el.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) continue;
    if (r.right > vw + 1 && !scrollerOrClip(el)) {
      const par = el.parentElement && el.parentElement.getBoundingClientRect();
      if (!par || par.right <= vw + 1) out.beyond.push(desc(el, r));       // jen nejvyšší přetékající
    }
    if (el.children.length === 0 && (el.textContent || '').trim().length > 1 && cs.display !== 'inline' && cs.textOverflow !== 'ellipsis'
        && el.clientWidth > 0 && el.scrollWidth > el.clientWidth + 2 && !/(auto|scroll)/.test(cs.overflowX)) {
      out.clipped.push(desc(el, r) + ` sw${el.scrollWidth}>cw${el.clientWidth} ov:${cs.overflowX}`);
    }
  }
  out.beyond = out.beyond.slice(0, 8); out.clipped = out.clipped.slice(0, 8);
  return out;
}

(async () => {
  const browser = await webkit.launch();
  const report = {};
  for (const vp of VIEWPORTS) {
    const ctx = await browser.newContext(vp.opts);
    const page = await ctx.newPage();
    await page.goto(BASE + '/admin/', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#login-form button[type=submit]', { timeout: 20000 });
    await sleep(1200);
    await page.click('#login-form button[type=submit]');
    await page.waitForFunction(() => typeof state !== 'undefined' && state.admin, null, { timeout: 30000 });
    await sleep(2000);
    for (const p of PAGES) {
      const [route, tab] = p.split(':');
      try {
        await page.evaluate(([r, t]) => { if (t) { state._nastaveniTab = t; } navigate(r); }, [route, tab]);
        for (let i = 0; i < 16; i++) {                                     // čekej až zmizí „Načítám" (max 8 s)
          await sleep(500);
          const loading = await page.evaluate(() => { const c = document.getElementById('content'); return !c || /Načítám/.test((c.innerText || '').slice(0, 300)); });
          if (!loading) break;
        }
        await sleep(1200);
        const r = await page.evaluate(detector);
        const bad = r.pageOverflow > 0 || r.beyond.length || r.clipped.length;
        (report[p] = report[p] || {})[vp.name] = r;
        console.log(`${bad ? '✗' : '✓'} ${vp.name.padEnd(9)} ${p.padEnd(24)} přetečení ${r.pageOverflow}px · za okrajem ${r.beyond.length} · ořez ${r.clipped.length}`);
        if (bad && process.env.SHOTS) await page.screenshot({ path: `${process.env.SHOTS}/${vp.name}-${p.replace(':', '_')}.png` });
      } catch (e) {
        (report[p] = report[p] || {})[vp.name] = { error: e.message.slice(0, 160) };
        console.log(`! ${vp.name.padEnd(9)} ${p.padEnd(24)} CHYBA ${e.message.slice(0, 100)}`);
      }
    }
    await ctx.close();
  }
  fs.writeFileSync(OUT, JSON.stringify(report, null, 1));
  console.log('report →', OUT);
  await browser.close();
})().catch(e => { console.error('FAIL', e.message); process.exit(1); });
