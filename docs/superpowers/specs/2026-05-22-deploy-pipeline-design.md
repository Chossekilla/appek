# Fáze 1 — Deploy pipeline

| | |
|---|---|
| **Datum** | 2026-05-22 |
| **Status** | Návrh schválen — připraveno k implementačnímu plánu |
| **Souvisí** | `build-zip.sh`, `vendor/self-update.php`, `.github/workflows/release.yml` |
| **Část projektu** | APPEK — startovací platforma (F1 z F1–F5; viz poznámka „Mimo rozsah") |

---

## 1. Cíl

Jeden příkaz z libovolného stroje (iMac, MacBook, na cestách) bezpečně nasadí
novou verzi na celý ekosystém — `vendor.appek.cz`, `appek.cz` (front),
`demo.appek.cz`, `b2b` — bez ručního FTP, bez ručního nahrávání zipů,
s automatickým rollbackem při chybě a s viditelným potvrzením, že nasazení prošlo.

Pipeline cíleně odstraňuje dlouhodobé „patálie":

- nekonzistentní lokální buildy („postavil jsem to blbě na svém Macu"),
- nahrání špatného nebo starého zipu,
- zapomenutý bump verze,
- „půlnapůl" nasazení (config.php nový, admin.js starý).

---

## 2. Výchozí stav

**Co už existuje:**

- Repo `github.com/Chossekilla/appek`, větev `main`.
- `.github/workflows/release.yml` — na push tagu `vX.Y.Z` postaví **customer** ZIP
  a vytvoří GitHub Release. Nestaví MASTER ZIP, nikam nenasazuje.
- `build-zip.sh X.Y.Z` — postaví CUSTOMER + MASTER + update bundle, bumpuje
  `APP_VERSION` a cache-bustery, generuje `api/.build-manifest.json`.
- `vendor/self-update.php` — ověřená apply rutina: pre-flight validace ZIPu →
  preserve configů → záloha do `/tmp` → rsync `-aI` → post-deploy validace →
  auto-publish customer OTA bundle. Dnes spustitelná **jen ručním uploadem
  v prohlížeči** (session auth, role `admin`).
- `api/updates_*.php` — OTA kanál pro platící zákazníky (zůstává beze změny).

**Topologie:** `appek.cz`, `vendor` / `admin` / `b2b` / `demo` jsou subdomény
nad jedním `public_html`. MASTER ZIP obsahuje celý web → **jedno nasazení
aktualizuje všechny čtyři naráz.** Platící zákazníci na vlastním hostingu se
aktualizují odděleně přes OTA kanál (self-update ho publikuje automaticky).

**Hlavní mezera:** mezi GitHubem a živým serverem není žádný most. Build i
nasazení jsou ruční a křehké.

---

## 3. Cílový tok

```
iMac / MacBook / na cestách
        │
        │  ./scripts/release.sh 2.9.142        ◀── jediná akce vývojáře
        │  (bump verze → commit → tag → push)
        ▼
   GitHub (main, tag vX.Y.Z)
        │
        ▼
   GitHub Actions (release.yml)
        │  1. ověří APP_VERSION == tag
        │  2. build-zip.sh → MASTER + customer ZIP
        │  3. GitHub Release (archiv obou zipů)
        │  4. HTTP POST MASTER zip → vendor/deploy-hook.php  (token)
        ▼
   server: vendor/deploy-hook.php
        │  ověří token → uloží zip → self_update_apply()
        ▼
   apply rutina:  validace → záloha → rsync → post-deploy kontrola
        │                                         │
        │                                  selhala? → auto-rollback
        ▼
   ✅ vendor + front + demo + b2b live  ·  customer OTA auto-publikován
        │
        ▼
   health check → JSON ✅/❌  →  CI job zelený/červený (GitHub pošle e-mail při ❌)
```

Žádné FTP. Server si nic nestahuje — CI mu balíček donese přes HTTP, stejným
kanálem, jakým dnes nahráváš zip ručně v prohlížeči.

---

## 4. Komponenty

### 4.1 `scripts/release.sh` — nový

Jeden příkaz pro vydání verze. Volání: `./scripts/release.sh 2.9.142`.

Chování:

1. Ověří formát verze `X.Y.Z`.
2. Ověří, že jsme na větvi `main` a pracovní strom je **čistý** (jinak abort —
   „nejdřív zacommituj práci"). Ověří, že `main` není pozadu za `origin/main`.
3. Bumpne `APP_VERSION` v `api/config.php` na zadanou verzi.
4. Commitne tu změnu: `chore: release vX.Y.Z`.
5. Vytvoří anotovaný tag `vX.Y.Z`.
6. Pushne `main` i tag na `origin`.

Parametr `--dry-run` vypíše, co by se stalo, ale nic neprovede (pro test).

Edge case: pokud `APP_VERSION` už cílovou verzi má, krok 3–4 se přeskočí
(jen tag + push).

### 4.2 `.github/workflows/release.yml` — upgrade

Stávající workflow rozšířit (trigger `push: tags: v[0-9]+.[0-9]+.[0-9]+`
zůstává):

- Krok „Build" — `build-zip.sh` už staví MASTER i customer ZIP; jen je oba
  použít dál.
- Krok „Create GitHub Release" — přidat do `files:` **oba** zipy
  (`appek-vX.Y.Z.zip` i `appek-MASTER-vX.Y.Z.zip`).
- **Nový krok „Deploy"** — `curl` multipart POST: pošle `appek-MASTER-vX.Y.Z.zip`
  na `${{ secrets.DEPLOY_URL }}/deploy-hook.php` s polem `deploy_token`
  z `${{ secrets.DEPLOY_TOKEN }}`. Přečte JSON odpověď; když status != `ok`,
  krok **selže** (červený CI job → GitHub pošle e-mail).

### 4.3 `vendor/_self_update.php` — nová knihovna

Apply logika je dnes vlepená přímo v `self-update.php` (inline blok `if POST`,
ř. ~47–271). Vytáhnout ji do sdílené knihovny, ať ji může volat UI stránka
i CI endpoint bez duplikace.

Veřejné funkce:

- `self_update_apply(string $zipPath, array &$log): array`
  — celá rutina: pre-flight validace → preserve → záloha → rsync → post-deploy
  validace → (při chybě) auto-rollback → auto-publish customer OTA → health
  check. Vrací strukturovaný výsledek `{ ok, version, steps[], health, error }`.
- `self_update_rollback(string $backupDir, array &$log): bool`
  — obnoví zálohu přes webroot.
- `self_update_health_check(string $webroot, string $expectedVersion): array`
  — viz 4.6.

Sem se přesunou i stávající helpery (`self_update_verify_bundle_integrity`,
`self_update_build_customer_zip`, `self_update_copy_dir`, `self_update_rmdir`).

### 4.4 `vendor/self-update.php` — refaktor

Zůstává jako **UI stránka** pro ruční nasazení (drag-and-drop upload, session
auth, role `admin`). Místo vlastní inline logiky volá `self_update_apply()`
z knihovny. Chování pro uživatele beze změny — je to záložní cesta.

### 4.5 `vendor/deploy-hook.php` — nový endpoint

Tenký HTTP endpoint pro CI. Žádné HTML, vrací **JSON**.

- Přijímá POST: soubor `master_zip` + pole `deploy_token`.
- Autentizace **tokenem** (ne session) — `hash_equals()` proti `DEPLOY_TOKEN`
  z `vendor/config.local.php`.
- Doporučené tvrzení: ověřit, že požadavek přišel z IP rozsahu GitHub Actions
  (`api.github.com/meta`, klíč `actions`).
- Lock soubor → zabrání souběžným nasazením.
- Uloží zip do `sys_get_temp_dir()`, zavolá `self_update_apply()`.
- Každé volání zapíše do `vendor_audit_log`.
- Odpoví JSON: `{ status: "ok"|"error", version, health, log[] }`.

### 4.6 Health check

Funkce v knihovně, volá se po úspěšném apply. Ověří:

- `api/config.php` → `APP_VERSION` == očekávaná verze,
- `vendor/.appek-version` == očekávaná verze,
- HTTP dostupnost (200) pro front, `demo`, `b2b`, `vendor`,
- (rozšíření) SHA kontrola customer souborů proti `api/.build-manifest.json`.

Výsledek `{ ok, checks[] }` jde do JSON odpovědi (čte CI) i do UI self-update.

---

## 5. Datový tok krok za krokem

1. Vývojář na kterémkoli stroji: `git pull` → upraví kód → `git push`.
2. Vývojář: `./scripts/release.sh 2.9.142` → bump, commit, tag, push.
3. GitHub Actions se spustí na tag:
   a. ověří `APP_VERSION` == tag,
   b. `build-zip.sh` postaví MASTER + customer ZIP,
   c. vytvoří GitHub Release s oběma zipy,
   d. POST MASTER zip → `deploy-hook.php` s tokenem.
4. `deploy-hook.php`: ověří token → uloží zip → `self_update_apply()`.
5. Apply: validace ZIPu → preserve (`*config.local.php`, `.installed`,
   `updates_storage/*`) → záloha → rsync přes webroot → post-deploy validace.
6. Při chybě post-deploy validace → `self_update_rollback()` → server zpět
   ve stavu před nasazením.
7. Při úspěchu → auto-publish customer OTA bundle (stávající chování) →
   health check.
8. `deploy-hook.php` vrátí JSON; CI job je zelený (✅) nebo červený (❌).

---

## 6. Bezpečnost

- **`DEPLOY_TOKEN`** — náhodný 64znakový token. Uložen v
  `vendor/config.local.php` (gitignored, self-update ho preservuje) **a** v
  GitHub → Settings → Secrets jako `DEPLOY_TOKEN`. Porovnání `hash_equals()`.
- **`DEPLOY_URL`** — základní URL vendoru (GitHub Secret/variable), protože
  doména se zatím může lišit (dnes dočasná `*.hostingersite.com`).
- `deploy-hook.php` musí být **dosažitelný i přes případný IP-whitelist**
  `vendor/.htaccess` — buď výjimka pro tento soubor, nebo allowlist IP GitHub
  Actions. Jinak CI neprojde.
- Lock proti souběžnému nasazení; každé volání do `vendor_audit_log`.
- FTP přihlašovací údaje **nejsou potřeba** — pipeline FTP nepoužívá.
- `config.local.php` zůstává mimo git i mimo deploy (preserve list) — na každém
  Macu i na serveru vlastní, se secrety (DB, Stripe, GoPay).
- Token a další secrety zadává do GitHubu **majitel sám** — Claude hesla
  nezadává.

---

## 7. Chování při chybě — auto-rollback

- Před nasazením apply rutina udělá zálohu webroot adresářů, které deploy
  přepisuje. **Poznámka:** dnešní záloha kryje jen `vendor/`, `api/`, `sales/` —
  rozšířit o `admin/`, `b2b/`, `demo/`, `pos/`, `floorplan/` a root HTML,
  jinak auto-rollback neumí vrátit rozbité `admin/`.
- Po nasazení post-deploy validace ověří, že kritické soubory existují a nejsou
  prázdné (stávající logika).
- **Nově:** když validace selže, `self_update_rollback()` automaticky obnoví
  zálohu → server je zpět v posledním funkčním stavu. Výsledek se reportuje
  jako `error` (CI červený).
- Záloha zůstává v `/tmp` i po úspěchu (pro ruční rollback v krajním případě).

---

## 8. Vícestrojový vývoj (iMac + MacBook)

- Oba stroje mají klon `github.com/Chossekilla/appek`. **GitHub = jediný zdroj
  pravdy.**
- Pravidlo: `git pull` na začátku práce, `git push` na konci. `release.sh`
  navíc odmítne vydat verzi, když je `main` pozadu za `origin` → nikdy se
  nenasadí zastaralý kód.
- `api/config.local.php` se nesynchronizuje (gitignored). Na MacBooku se
  vytvoří jednou pro lokální XAMPP testování (viz `appek-local-testing.md`).
- Build probíhá **vždy v CI z `main`** — nikdy ne z lokálního stavu. Tím se
  „co je živé" rovná „co je na GitHubu" a oba stroje se nemůžou rozejít.

---

## 9. Testování

- `release.sh` — otestovat s `--dry-run`; pak na throwaway verzi.
- `self_update_apply()` / `self_update_rollback()` — lokálně: nasměrovat na
  lokální webroot, podat (a) korektní MASTER zip → projde, (b) záměrně
  poškozený zip → spadne post-deploy validace → ověřit auto-rollback.
- `deploy-hook.php` — lokálně přes `appek-php` preview server
  (`localhost:8756/vendor/deploy-hook.php`): test špatný token → 403,
  správný token → apply.
- Health check — ověřit ✅ i ❌ větev.
- Plná CI cesta — první ostrý release proti **dočasné** doméně
  (`*.hostingersite.com`), teprve po ověření na produkční doméně.

---

## 10. Mimo rozsah F1

- Správa typů instalací demo / B2B / pirát (= Fáze 2).
- Dokončení eshopu a balíčků (= Fáze 3).
- Univerzální branding (= Fáze 4).
- Ladění zákaznické aplikace (= Fáze 5).
- Sjednocení dvou update systémů (`api/updates_*` vs `self-update`) — OTA kanál
  v F1 zůstává beze změny; konsolidace je pozdější téma.
- Atomický deploy přes symlink swap — pro sdílený hosting zbytečně složité;
  zůstáváme u zip + rsync s auto-rollbackem.

---

## 11. Ruční kroky pro majitele

Tyto kroky musí udělat majitel sám (Claude je neudělá — jde o přístupy
a secrety):

1. GitHub → repo `Chossekilla/appek` → Settings → Secrets and variables →
   Actions → přidat `DEPLOY_TOKEN` (hodnotu vygeneruje Claude) a `DEPLOY_URL`.
2. Vložit stejný `DEPLOY_TOKEN` do `vendor/config.local.php` na serveru.
3. Na MacBooku: `git clone`, vytvořit `api/config.local.php` pro lokální testy.
