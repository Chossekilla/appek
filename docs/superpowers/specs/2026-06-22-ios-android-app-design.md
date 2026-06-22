# APPEK — mobilní appka (iOS + Android) přes App Store / Google Play

**Datum:** 2026-06-22
**Stav:** Schválený návrh (Fáze 1). Fáze 2 načrtnuta pro kontext.

---

## 1. Kontext & cíl

APPEK je **self-hosted PHP/PWA B2B gastro systém** — 1 instalace = 1 zákazník na vlastní
doméně/subdoméně, distribuováno self-update feedem z vendor.appek.cz. Na iPhone/Android už
jde nainstalovat jako **PWA** (manifest + ikony hotové).

**Cíl:** dostat APPEK do **App Store i Google Play** kvůli viditelnosti a důvěře, jako appku
pro **provoz** (admin + POS). Monetizace **multiplatform**: balíčky se prodávají na vendoru
(tvé ceny, bez provize) **i** v appce (přes Apple/Google s provizí), a **licence platí napříč**
(co koupíš na webu funguje v appce a naopak).

---

## 2. Celkový model + dekompozice + fázování

Multiplatform model = **4 subsystémy**:

| # | Subsystém | Popis |
|---|-----------|-------|
| 1 | Wrapper appka (Capacitor iOS+Android) | nativní obal nad webem APPEKu + login + push |
| 2 | Nákup v appce (IAP) | StoreKit (iOS) + Play Billing (Android) pro roční balíčky |
| 3 | Propojení (server) | vendor ověří Apple/Google účtenku → aktivuje balíček na licenci → platí i v cloudu; obnovy přes store webhooky |
| 4 | Submit + review | App Store / Play, policy |

**Fázování (schváleno):**
- **Fáze 1 = #1 + #4** — appka ve Storu, **login-only, BEZ nákupu v appce**. Rychle ve Storu,
  nízké riziko review; prodej balíčků dál přes vendor. ← *tento spec*
- **Fáze 2 = #2 + #3** — IAP + cross-platform propojení. Jako pozdější update (vlastní spec/plán).

---

## 3. Fáze 1 — rozsah

**Audience:** provoz (majitel/personál) → appka načte **admin + POS** zákazníkovy instalace.
*(B2B portál pro odběratele = jiné publikum, mimo rozsah Fáze 1.)*

**In scope:**
- Capacitor projekt (iOS + Android) z jednoho kódu.
- Centrální login e-mailem → vendor resolve → načtení správné instalace, přihlášené.
- Vendor resolve endpoint (e-mail → instalace).
- Push notifikace (nativní), ikony, splash, status bar / safe area.
- Koncept Store metadat (název, popis, kategorie, screenshoty) + review poznámky.

**Out of scope (Fáze 2+):**
- Jakýkoli nákup / ceník balíčků / odkaz na vendor pricing **uvnitř appky** (anti-steering — nutné kvůli review).
- IAP, cross-platform entitlement, store webhooky.
- Samostatná appka pro odběratele (B2B).

---

## 4. Architektura Fáze 1

### 4.1 Capacitor shell
Tenký nativní obal (iOS WKWebView + Android WebView přes Capacitor). Jeden web/JS kód → obě
platformy. Nativní pluginy: Push, App, StatusBar, SplashScreen. Ikony/splash z `branding/app-icon.png`
(generátor `scripts/gen_pwa_icons.py` rozšíříme o iOS/Android sady).

### 4.2 Centrální login + vendor resolve (datový tok)
1. **App login screen** (nativní-ish HTML v shellu): e-mail + heslo.
2. App → `POST vendor.appek.cz/resolve {email}` → vendor vrátí `{install_url}` (nebo seznam při víc shodách).
   - 0 shod → „e-mail nenalezen" (fallback: ruční zadání adresy).
   - >1 → uživatel vybere instalaci.
3. App → `POST ‹install_url›/api/admin_login.php {email, heslo}` → session cookie pro origin instalace (Capacitor webview cookies).
4. App načte `‹install_url›/admin/` ve webview = přihlášený admin; POS dostupný z adminu.
5. App si zapamatuje `install_url` (+ případně i email) pro příští spuštění → rovnou krok 3/4.

### 4.3 Vendor resolve endpoint (nový)
- `POST vendor.appek.cz/resolve` `{email}` → `{installs:[{url, nazev}]}`.
- **Zdroj mapy e-mail → instalace:** rozšíříme **license heartbeat** (instalace už vendoru
  pravidelně hlásí stav + APP_URL) o **admin e-maily** dané instalace → vendor uloží `email → install_url`.
  Alternativa/doplněk: zaevidovat e-mail při vydání licence.
- **Bezpečnost:** rate-limit (proti enumeraci e-mailů), žádné prozrazení existence účtu nad rámec nutného.
- **GDPR:** centrální evidence admin e-mailů = osobní údaj na vendoru → minimální rozsah, účel
  (směrování loginu), souhlas/ujednání. *(Souvisí s celkovou GDPR cestou k prodeji.)*

### 4.4 Nativní hodnota (kvůli Apple 4.2 + užitku)
- **Push notifikace** — nová objednávka atd. APPEK push backend existuje (`_push_lib.php`);
  Capacitor Push plugin → registrace tokenu → APPEK push. (iOS potřebuje APNs klíč = tvůj účet.)
- Ikona, splash, status bar / safe-area, offline fallback stránka.

### 4.5 Bez nákupu v appce (Fáze 1)
Appka neobsahuje IAP ani ceník/odkaz na koupi balíčků. Licenci/balíčky kupuje provoz mimo appku
(vendor) → spadá pod Apple **B2B/business** výjimku → nízké riziko + bez Apple provize.

---

## 5. Co udělám já × co ty

**Já (v repu, bez Macu/účtu):**
- Capacitor projekt + config, login/resolve obrazovka, napojení push, offline fallback.
- Vendor `resolve` endpoint + rozšíření heartbeatu o admin e-maily.
- iOS/Android ikony + splash (rozšíření generátoru).
- Koncept Store metadat + review poznámky (vč. demo účtu pro reviewera).
- Otestuju web/JS části + strukturu projektu (build nativní části ověříš ty).

**Ty (z Macu, pod tvým Apple/Google účtem — nejde za tebe):**
- Otevřít projekt v Xcode / Android Studiu.
- Podpisové certifikáty + **APNs klíč** (push) + provisioning.
- Build + nahrání do App Store Connect / Play Console + odeslání k review.

---

## 6. Review / policy
- **Apple 4.2 „minimum functionality"** = hlavní riziko (webview obal). Mitigace: reálný B2B
  login (ne veřejný web), push (nativní hodnota), review poznámky: *„B2B SaaS pro gastro provozy;
  účet/licence se pořizuje samostatně mimo appku; přiložen demo účet a popis."*
- **Žádný in-app nákup** ve Fázi 1 → nespouští IAP pravidla (3.1.1).
- Před submitem ověřit aktuální Apple guidelines (3.1.3 multiplatform, 4.2) — pravidla se mění.

---

## 7. Rizika
- Apple/Google rejekce „je to jen web" → mitigace výše; připravit i argumentaci/odvolání.
- Resolve endpoint = nová centrální evidence e-mailů (GDPR) → ošetřit.
- Push na iOS závisí na tvém APNs klíči (build-time, tvůj účet).
- Self-hosted multi-tenant: instalace na různých doménách → resolve musí zvládnout i vlastní domény, ne jen *.appek.cz.

---

## 8. Fáze 2 (odložená — vlastní spec) — náčrt
- **IAP:** StoreKit (iOS) + Play Billing (Android), produkty = roční balíčky (ceny v App Store
  Connect / Play Console; musí pokrýt provizi ~15–30 %).
- **Propojení:** vendor `validate-receipt` endpoint (ověří Apple/Google účtenku) → aktivuje
  balíček na licenci (existující licenční systém) → platí i v cloudu. Obnovy/expirace přes
  **App Store Server Notifications** + **Google Play RTDN** (webhooky na vendor).
- **Anti-steering:** v iOS appce nelze odkazovat na levnější web nákup (mimo EU/povolené regiony).

---

## 9. Success criteria (Fáze 1)
- Appka (iOS+Android) z jednoho Capacitor kódu se postaví a spustí.
- Uživatel zadá e-mail+heslo → appka najde jeho instalaci → načte přihlášený admin (+ POS).
- Push dorazí na zařízení.
- Appka projde review a je ke stažení v App Store + Play (login-only, bez IAP).
- Prodej balíčků přes vendor jede beze změny.
