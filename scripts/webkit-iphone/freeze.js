// WebKit (Safari engine) + iPhone profil: reprodukce „zamrzání" Faktury T→D a Suroviny Dlaždice→Řádky.
// Přihlášení: demo login je předvyplněný → jen klik na „Přihlásit se" (heslo se nepíše).
const { webkit, devices } = require('playwright');
const BASE = process.env.BASE || 'https://demo.appek.cz';
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

(async () => {
  const browser = await webkit.launch();
  const ctx = await browser.newContext({ ...devices['iPhone 13'] });
  // PINNED=1 → mobilní připnuté menu (rail místo bottom-nav) jako má uživatel na iPhonu
  if (process.env.PINNED) await ctx.addInitScript(() => { try { localStorage.setItem('sidebarPinned', '1'); } catch (e) {} });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push('console: ' + m.text().slice(0, 200)); });
  const reqs = [];
  const started = new Map();
  page.on('request', r => started.set(r, Date.now()));
  page.on('requestfinished', async r => { const ms = Date.now() - (started.get(r) || Date.now()); const resp = await r.response().catch(() => null); const st = resp ? resp.status() : 0; if (st >= 400 || ms > 1500) reqs.push(st + ' ' + ms + 'ms ' + r.method() + ' ' + r.url().replace(BASE, '').slice(0, 90)); });
  page.on('requestfailed', r => reqs.push('FAILED ' + (r.failure() || {}).errorText + ' ' + r.url().replace(BASE, '').slice(0, 90)));

  await page.goto(BASE + '/admin/', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('button[type=submit]', { timeout: 20000 });
  await sleep(1500);
  await page.tap('button[type=submit]');
  await page.waitForFunction(() => typeof state !== 'undefined' && state.admin, null, { timeout: 30000 });
  await sleep(2500);
  console.log('pinned', await page.evaluate(() => document.body.classList.contains('sidebar-pinned')), 'UA', await page.evaluate(() => navigator.userAgent.slice(0, 80)), 'ver', await page.evaluate(() => document.querySelector('script[src*="admin.js"]')?.src.split('v=')[1]));

  // měřič: jak dlouho je hlavní vlákno zablokované (heartbeat setTimeout smyčka) + long tasks
  await page.evaluate(() => {
    window._lt = [];
    try { new PerformanceObserver(l => l.getEntries().forEach(e => window._lt.push(Math.round(e.duration)))).observe({ entryTypes: ['longtask'] }); } catch (e) { window._lt.push('noLT'); }
    window._beat = { max: 0, last: performance.now() };
    const tick = () => { const n = performance.now(); const d = n - window._beat.last; if (d > window._beat.max) window._beat.max = d; window._beat.last = n; setTimeout(tick, 16); };
    tick();
  });
  const resetBeat = () => page.evaluate(() => { window._beat.max = 0; window._beat.last = performance.now(); window._lt = []; });
  const beat = () => page.evaluate(() => ({ maxGapMs: Math.round(window._beat.max), longTasks: window._lt.slice(0, 10), navSeq: window._navSeq, cur: state.current }));
  // co je „pod prstem" — neviditelná vrstva přes obsah = stránka vypadá zamrzlá
  const hit = () => page.evaluate(() => {
    const out = [];
    for (const [x, y] of [[200, 300], [200, 500], [120, 650], [300, 420]]) {
      const el = document.elementFromPoint(x, y);
      const inContent = !!(el && el.closest('#content'));
      if (!inContent) { const cs = el && getComputedStyle(el); out.push(`${x},${y}:${el ? (el.id || el.className || el.tagName).toString().slice(0, 40) : 'null'} pos=${cs && cs.position} op=${cs && cs.opacity}`); }
    }
    const fixedOverlays = [...document.querySelectorAll('body *')].filter(e => { const cs = getComputedStyle(e); if (cs.position !== 'fixed' || cs.pointerEvents === 'none' || cs.display === 'none' || cs.visibility === 'hidden') return false; const r = e.getBoundingClientRect(); return r.width > 300 && r.height > 300; }).map(e => (e.id || e.className).toString().slice(0, 40) + ' op' + getComputedStyle(e).opacity + ' z' + getComputedStyle(e).zIndex);
    return { notContent: out, bigFixed: fixedOverlays };
  });
  const canScroll = () => page.evaluate(async () => {
    const b = document.body, before = b.scrollTop; b.scrollTop = before + 300; await new Promise(r => setTimeout(r, 100));
    const moved = b.scrollTop !== before; b.scrollTop = before; return { moved, bodyScrollH: b.scrollHeight, overflow: getComputedStyle(b).overflowY };
  });

  // ===== FAKTURY T → D =====
  await page.evaluate(() => navigate('faktury'));
  await page.waitForSelector('[onclick*="periodTabSet"]', { timeout: 20000 });
  await sleep(2000);
  for (const step of ['tyden', 'dnes', 'tyden', 'dnes', 'mesic', 'dnes']) {
    await resetBeat();
    const t0 = Date.now();
    await page.tap(`[onclick*="periodTabSet('fa', '${step}')"]`, { timeout: 10000 });
    await sleep(2500);
    const b = await beat();
    const active = await page.evaluate(() => [...document.querySelectorAll('[onclick*="periodTabSet"]')].filter(x => /active/.test(x.className)).map(x => x.textContent.trim()).join(','));
    console.log('FA', step, 'tapMs', Date.now() - t0, JSON.stringify(b), 'active', active, JSON.stringify(await canScroll()), 'HIT', JSON.stringify(await hit()));
  }

  // ===== SUROVINY Dlaždice ↔ Řádky =====
  await page.evaluate(() => navigate('suroviny'));
  await page.waitForSelector('.sur-mview button', { timeout: 20000 });
  await sleep(2000);
  for (const lbl of ['Řádky', 'Dlaždice', 'Řádky']) {
    await resetBeat();
    const t0 = Date.now();
    await page.tap(`.sur-mview button:has-text("${lbl}")`, { timeout: 10000 });
    await sleep(2500);
    const b = await beat();
    const n = await page.evaluate(() => ({ rows: document.querySelectorAll('.sur-mrow').length, tiles: document.querySelectorAll('.sur-tile').length, html: document.getElementById('content').innerHTML.length }));
    console.log('SUR', lbl, 'tapMs', Date.now() - t0, JSON.stringify(b), JSON.stringify(n), JSON.stringify(await canScroll()), 'HIT', JSON.stringify(await hit()));
  }
  // posun dolů a znovu přepnout (uživatel typicky odscrolluje)
  await page.evaluate(() => { document.body.scrollTop = 1500; });
  await sleep(500);
  await resetBeat();
  await page.tap('.sur-mview button:has-text("Dlaždice")').catch(e => console.log('tap after scroll fail', e.message.slice(0, 160)));
  await sleep(2500);
  console.log('SUR after-scroll', JSON.stringify(await beat()), JSON.stringify(await canScroll()), 'scrollTop', await page.evaluate(() => document.body.scrollTop));

  console.log('ERRORS', JSON.stringify(errors.slice(0, 20)));
  console.log('SLOW/FAILED REQUESTS\n  ' + reqs.join('\n  '));
  await page.screenshot({ path: (process.env.OUT || '/tmp') + '/appek-webkit-after.png' });
  await browser.close();
})().catch(e => { console.error('FAIL', e.message); process.exit(1); });
