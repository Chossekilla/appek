const { webkit, devices } = require('playwright');
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
(async () => {
  const browser = await webkit.launch();
  const ctx = await browser.newContext({ ...devices['iPhone 13'] });
  await ctx.addInitScript(() => { try { localStorage.setItem('sidebarPinned', '1'); } catch (e) {} });
  const page = await ctx.newPage();
  const errs = []; page.on('pageerror', e => errs.push(e.message));
  let watch = []; page.on('request', r => { if (r.url().includes('admin_klient_chyby')) watch.push(r.postData() || ''); });
  await page.goto('https://demo.appek.cz/admin/', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('button[type=submit]'); await sleep(1500); await page.tap('button[type=submit]');
  await page.waitForFunction(() => typeof state !== 'undefined' && state.admin, null, { timeout: 30000 }); await sleep(2500);
  console.log('ver', await page.evaluate(() => document.querySelector('script[src*="admin.js"]').src.split('v=')[1]));
  // FAKTURY: posunout dolů, klepnout M (delší) a D (kratší)
  await page.evaluate(() => navigate('faktury')); await page.waitForSelector('[onclick*="periodTabSet"]'); await sleep(2000);
  await page.tap(`[onclick*="periodTabSet('fa', 'mesic')"]`); await sleep(2500);
  await page.evaluate(() => { document.body.scrollTop = 900; }); await sleep(300);
  const before = await page.evaluate(() => document.body.scrollTop);
  await page.evaluate(() => [...document.querySelectorAll('[onclick*="periodTabSet"]')].find(b => b.getAttribute('onclick').includes("'tyden'")).click());
  await sleep(2500);
  console.log('FA scroll before', before, 'after', await page.evaluate(() => document.body.scrollTop), 'minH', JSON.stringify(await page.evaluate(() => document.getElementById('content').style.minHeight)));
  // SUROVINY: posunout dolů, přepnout Řádky ↔ Dlaždice (tlačítko je pod místem posunu → klik přes JS)
  await page.evaluate(() => navigate('suroviny')); await page.waitForSelector('.sur-mview button'); await sleep(2000);
  for (const lbl of ['Řádky', 'Dlaždice']) {
    const y0 = await page.evaluate(() => { const b = document.querySelector('.sur-mview'); document.body.scrollTop = b.getBoundingClientRect().top + document.body.scrollTop - 200; return document.body.scrollTop; });
    await sleep(300);
    await page.tap(`.sur-mview button:has-text("${lbl}")`); await sleep(2500);
    console.log('SUR', lbl, 'scroll before', y0, 'after', await page.evaluate(() => document.body.scrollTop), 'minH', JSON.stringify(await page.evaluate(() => document.getElementById('content').style.minHeight)), 'toggle top', await page.evaluate(() => Math.round(document.querySelector('.sur-mview').getBoundingClientRect().top)));
  }
  // HLÍDAČ: úmyslný 3s blok hlavního vlákna
  watch = [];
  await page.evaluate(() => { const t = performance.now(); while (performance.now() - t < 3000) {} });
  await sleep(2500);
  console.log('watchdog reports', watch.length, watch.map(w => { try { const j = JSON.parse(w); return j.msg + ' ' + (j.stack || '').slice(0, 140); } catch (e) { return w.slice(0, 100); } }));
  console.log('pageerrors', JSON.stringify(errs));
  await browser.close();
})().catch(e => { console.error('FAIL', e.message); process.exit(1); });
