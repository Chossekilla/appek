# APPEK mobil (Capacitor) — iOS + Android

Nativní obal nad webem APPEKu pro **provoz** (admin + POS). Appka se zeptá na e-mail →
`vendor.appek.cz/resolve.php` najde instalaci → otevře `‹instalace›/admin/`. Heslo zadáš
na přihlašovací stránce instalace. **Bez nákupu v appce** (Fáze 1).

## Web část (děláno v repu, bez Macu)
```
cd mobile
npm install
# www/ = login shell (index.html + app.js + style.css)
```
`www/` jde otevřít i v běžném prohlížeči (Preferences má localStorage fallback) — login obrazovka + resolve se otestují bez nativního buildu.

## Nativní projekty (na Macu — Xcode / Android Studio)
> `ios/` a `android/` jsou v `.gitignore` — generují se lokálně.

```bash
cd mobile
npx cap add ios          # potřebuje CocoaPods + Xcode CLI
npx cap add android      # potřebuje Android SDK
npm run assets           # ikony/splash z ../branding/app-icon.png (@capacitor/assets)
npx cap sync
```

### iOS submit
```bash
npx cap open ios
```
V Xcode:
1. **Signing & Capabilities** → Team = tvůj Apple Developer účet, Bundle ID `cz.appek.app`.
2. Přidej capability **Push Notifications** + v Apple Developer portálu vytvoř **APNs key**.
3. Test na zařízení → **Product → Archive** → App Store Connect → odeslat k review.
4. **Review poznámky** (důležité kvůli Apple 4.2): „B2B SaaS pro gastro provozy; účet/licence se
   pořizuje samostatně mimo appku (vendor); přiložen demo účet: demo@appek.cz." Bez in-app nákupu.

### Android submit
```bash
npx cap open android
```
Android Studio → signed App Bundle (.aab) → Play Console → review.

## Verze / appId
- appId: `cz.appek.app`, appName: `APPEK` (viz `capacitor.config.json`).
- Fáze 2 (později): IAP (StoreKit + Play Billing) + cross-platform entitlement — viz spec `docs/superpowers/specs/2026-06-22-ios-android-app-design.md`.
