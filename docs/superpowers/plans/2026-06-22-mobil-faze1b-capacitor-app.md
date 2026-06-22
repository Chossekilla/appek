# APPEK mobil — Fáze 1B: Capacitor appka (iOS+Android) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development nebo superpowers:executing-plans. Kroky mají checkbox (`- [ ]`).

**Goal:** Nativní appka (iOS+Android, jeden Capacitor kód) pro provoz: zadá e-mail → najde instalaci (vendor resolve, Fáze 1A) → otevře admin (+POS) instalace ve webview; push; bez nákupu v appce.

**Architecture:** Capacitor shell. App shell (`mobile/www/`) = login obrazovka (JEN e-mail) → `vendor.appek.cz/resolve.php` → uloží install_url (Preferences) → naviguje webview na `‹install›/admin/?app_email=…`. Heslo uživatel zadá na přihlašovací stránce instalace (vlastní origin → session cookie OK; appka heslo neřeší). Push přes Capacitor plugin → existující APPEK push.

**Tech Stack:** Capacitor 6, Node v24/npm, vanilla JS (www). Nativní build (iOS/Android) = uživatel na Macu (Xcode/Android Studio, podpis, APNs/FCM).

**Rozhraní z Fáze 1A:** `POST https://vendor.appek.cz/resolve.php {email}` → `{ok, installs:[{url,nazev}]}`.

---

## File Structure
- `mobile/package.json` — Capacitor deps + skripty.
- `mobile/capacitor.config.json` — appId `cz.appek.app`, appName `APPEK`, webDir `www`.
- `mobile/www/index.html` — login shell (e-mail) + styl.
- `mobile/www/app.js` — resolve → uložení URL → navigace; Preferences; výběr při víc instalacích; ruční fallback URL.
- `mobile/www/style.css` — vzhled (APPEK barvy).
- `mobile/www/assets/` — ikona/splash.
- `mobile/README.md` — Mac kroky (cap add ios/android, podpis, build, submit).
- Modify: `admin/index.html` — login auto-prefill z `?app_email=` (drobnost; vedle demo auto-fill).
- `mobile/ios/`, `mobile/android/` — generuje `npx cap add` (Mac/SDK; necommitovat build artefakty — `.gitignore`).

---

### Task 1: Capacitor scaffold

**Files:** Create `mobile/package.json`, `mobile/capacitor.config.json`, `mobile/.gitignore`

- [ ] **Step 1: `mobile/package.json`**
```json
{
  "name": "appek-mobile",
  "version": "1.0.0",
  "private": true,
  "description": "APPEK — mobilní appka (Capacitor wrapper iOS+Android)",
  "scripts": {
    "sync": "cap sync",
    "open:ios": "cap open ios",
    "open:android": "cap open android"
  },
  "dependencies": {
    "@capacitor/core": "^6.2.0",
    "@capacitor/ios": "^6.2.0",
    "@capacitor/android": "^6.2.0",
    "@capacitor/app": "^6.0.0",
    "@capacitor/preferences": "^6.0.0",
    "@capacitor/push-notifications": "^6.0.0",
    "@capacitor/splash-screen": "^6.0.0",
    "@capacitor/status-bar": "^6.0.0"
  },
  "devDependencies": { "@capacitor/cli": "^6.2.0" }
}
```

- [ ] **Step 2: `mobile/capacitor.config.json`**
```json
{
  "appId": "cz.appek.app",
  "appName": "APPEK",
  "webDir": "www",
  "server": { "androidScheme": "https", "iosScheme": "https" },
  "plugins": {
    "SplashScreen": { "launchAutoHide": true, "backgroundColor": "#1d1d1f" },
    "PushNotifications": { "presentationOptions": ["badge", "sound", "alert"] }
  }
}
```

- [ ] **Step 3: `mobile/.gitignore`**
```
node_modules/
ios/
android/
*.log
```
*(ios/ a android/ se generují na Macu; do gitu necommitujeme build projekty — appka se rekonstruuje z `mobile/` + `npx cap add`.)*

- [ ] **Step 4: npm install**
Run: `cd mobile && PATH="$PATH:/usr/local/bin" npm install`
Expected: nainstaluje Capacitor deps (node_modules), bez chyb.

- [ ] **Step 5: Commit**
```bash
git add mobile/package.json mobile/capacitor.config.json mobile/.gitignore mobile/package-lock.json
git commit -m "feat(mobile): Capacitor scaffold (package.json + config)"
```

---

### Task 2: Login shell (www) — e-mail → resolve → navigace

**Files:** Create `mobile/www/index.html`, `mobile/www/app.js`, `mobile/www/style.css`

- [ ] **Step 1: `mobile/www/index.html`**
```html
<!DOCTYPE html>
<html lang="cs"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>APPEK</title>
<link rel="stylesheet" href="style.css">
</head><body>
  <div id="login" class="card">
    <div class="logo">A</div>
    <h1>APPEK</h1>
    <p class="sub">Přihlaš se svým e-mailem</p>
    <input id="email" type="email" inputmode="email" autocomplete="username" placeholder="tvuj@email.cz">
    <button id="go" class="btn">Pokračovat →</button>
    <p id="err" class="err" hidden></p>
    <button id="manual" class="link">Zadat adresu instalace ručně</button>
  </div>
  <script type="module" src="app.js"></script>
</body></html>
```

- [ ] **Step 2: `mobile/www/app.js`**
```js
import { Preferences } from '@capacitor/preferences';
const VENDOR = 'https://vendor.appek.cz/resolve.php';
const $ = (id) => document.getElementById(id);

async function boot() {
  const { value } = await Preferences.get({ key: 'install_url' });
  if (value) { openInstall(value, (await Preferences.get({ key: 'email' })).value || ''); }
}
function openInstall(url, email) {
  const u = url.replace(/\/+$/, '') + '/admin/' + (email ? ('?app_email=' + encodeURIComponent(email)) : '');
  window.location.href = u;
}
function showErr(m) { const e = $('err'); e.textContent = m; e.hidden = false; }

$('go').addEventListener('click', async () => {
  const email = ($('email').value || '').trim().toLowerCase();
  $('err').hidden = true;
  if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return showErr('Zadej platný e-mail.');
  $('go').disabled = true; $('go').textContent = 'Hledám…';
  try {
    const r = await fetch(VENDOR, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email }) });
    const d = await r.json();
    const installs = d.installs || [];
    if (installs.length === 0) return showErr('E-mail jsme nenašli. Zkus „zadat adresu ručně" nebo kontaktuj dodavatele.');
    const chosen = installs.length === 1 ? installs[0] : await pick(installs);
    if (!chosen) return;
    await Preferences.set({ key: 'install_url', value: chosen.url });
    await Preferences.set({ key: 'email', value: email });
    openInstall(chosen.url, email);
  } catch (e) { showErr('Chyba spojení: ' + e.message); }
  finally { $('go').disabled = false; $('go').textContent = 'Pokračovat →'; }
});

$('manual').addEventListener('click', async () => {
  const url = prompt('Adresa tvé APPEK instalace (https://…):', 'https://');
  if (!url || !/^https?:\/\//.test(url)) return;
  await Preferences.set({ key: 'install_url', value: url });
  openInstall(url, ($('email').value || '').trim().toLowerCase());
});

function pick(installs) {
  return new Promise((res) => {
    const txt = installs.map((x, i) => `${i + 1}) ${x.nazev || x.url}`).join('\n');
    const n = parseInt(prompt('Víc instalací — vyber číslo:\n' + txt, '1'), 10);
    res(installs[n - 1] || null);
  });
}
boot();
```

- [ ] **Step 3: `mobile/www/style.css`**
```css
:root { --p:#BA7517; --bg:#FAEEDA; }
* { box-sizing:border-box; } body { margin:0; font-family:-apple-system,system-ui,sans-serif; background:var(--bg); display:flex; min-height:100vh; align-items:center; justify-content:center; padding:24px; }
.card { background:#fff; border-radius:18px; padding:32px 26px; width:100%; max-width:360px; box-shadow:0 10px 40px rgba(0,0,0,.08); text-align:center; }
.logo { width:64px; height:64px; border-radius:50%; background:var(--p); color:#fff; font-size:30px; font-weight:800; display:flex; align-items:center; justify-content:center; margin:0 auto 14px; }
h1 { margin:0 0 4px; font-size:26px; } .sub { color:#777; margin:0 0 20px; font-size:14px; }
input { width:100%; padding:14px; border:2px solid #e3e3e3; border-radius:12px; font-size:16px; margin-bottom:12px; }
.btn { width:100%; padding:14px; border:none; border-radius:12px; background:var(--p); color:#fff; font-size:16px; font-weight:700; cursor:pointer; }
.btn:disabled { opacity:.6; } .err { color:#b3261e; font-size:13px; margin-top:10px; }
.link { background:none; border:none; color:var(--p); margin-top:14px; cursor:pointer; font-size:13px; text-decoration:underline; }
```

- [ ] **Step 4: Commit**
```bash
git add mobile/www/
git commit -m "feat(mobile): login shell — email→resolve→navigace na instalaci"
```

---

### Task 3: Install-side prefill e-mailu z `?app_email`

**Files:** Modify `admin/index.html` (demo auto-fill blok ~ř.94-118)

- [ ] **Step 1:** Do skriptu auto-fillu v `admin/index.html` přidej PŘED `demo_status` fetch:
```html
  // 🆕 Fáze 1B — prefill e-mailu z mobilní appky (?app_email=)
  try {
    const ae = new URLSearchParams(location.search).get('app_email');
    if (ae) { const f = document.getElementById('login-form'); if (f) f.querySelector('input[name="email"]').value = ae; }
  } catch (e) {}
```
- [ ] **Step 2: Commit** `git commit -am "feat: admin login prefill z ?app_email (mobil)"`

---

### Task 4: Push notifikace (Capacitor → APPEK push)

**Files:** Modify `mobile/www/app.js` (registrace po otevření instalace)

- [ ] **Step 1:** Přidej do `app.js` registraci push (zaregistruje token a pošle ho instalaci do existující push tabulky přes endpoint APPEKu):
```js
import { PushNotifications } from '@capacitor/push-notifications';
export async function initPush(installUrl) {
  try {
    let perm = await PushNotifications.checkPermissions();
    if (perm.receive !== 'granted') perm = await PushNotifications.requestPermissions();
    if (perm.receive !== 'granted') return;
    PushNotifications.addListener('registration', async (t) => {
      try { await fetch(installUrl.replace(/\/+$/,'') + '/api/push_register.php', { method:'POST', credentials:'include', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ token: t.value, platform: 'capacitor' }) }); } catch(e){}
    });
    await PushNotifications.register();
  } catch (e) {}
}
```
*(Pozn.: ověř název endpointu pro registraci push tokenu v `api/` — viz `_push_lib.php`; pokud chybí REST endpoint, doplň `api/push_register.php`. APNs/FCM klíče = build-time, uživatel na Macu/účtu.)*

- [ ] **Step 2: Commit** `git commit -am "feat(mobile): push registrace tokenu"`

---

### Task 5: Ikony + splash
- [ ] Vygeneruj sady (1024 zdroj `branding/app-icon.png`): `@capacitor/assets` (`npx @capacitor/assets generate`) NEBO rozšiř `scripts/gen_pwa_icons.py`. Ulož do `mobile/www/assets/` (+ po `cap add` se vloží do ios/android). Commit.

---

### Task 6: Mac handoff (uživatel — nejde za něj)
- [ ] `cd mobile && npx cap add ios && npx cap add android` (Mac: CocoaPods + Android SDK).
- [ ] `npx cap sync`
- [ ] `npx cap open ios` → Xcode → **Signing & Capabilities**: zvol tým (Apple účet), bundle id `cz.appek.app`, zapni **Push Notifications** + nahraj **APNs key**.
- [ ] Build na zařízení (test), pak Archive → App Store Connect → odeslat k review (review poznámky ze specu sekce 6).
- [ ] Android: `npx cap open android` → Android Studio → signed bundle → Play Console.

---

## Self-Review
- **Spec coverage:** Capacitor (Task 1) · centrální login e-mailem → resolve → instalace (Task 2, používá 1A endpoint) · prefill (Task 3) · push (Task 4) · ikony (Task 5) · submit (Task 6). ✓ BEZ IAP/nákupu (mimo rozsah). ✓
- **Heslo:** appka ho NEŘEŠÍ — zadává se na login stránce instalace (vlastní origin → cookie OK, žádné cross-origin auth). Bezpečnější + jednodušší.
- **Placeholdery:** Task 4 push_register endpoint — ověř/doplň v api (konkrétní instrukce, ne vágní). Task 5 ikony — dvě konkrétní cesty.
- **Závislost:** Task 2 vyžaduje Fázi 1A resolve.php (LIVE). Nativní build (Task 6) = Mac/účet uživatele.
