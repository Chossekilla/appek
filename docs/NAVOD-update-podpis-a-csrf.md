# Návod — kryptopodpis aktualizací + CSRF (v3.0.388)

> Provozní příručka ke dvěma bezpečnostním vrstvám přidaným ve v3.0.388:
> **(A)** RSA-2048 podpis update bundlů (supply-chain ochrana), **(B)** CSRF tokeny ve vendor panelu.
> Architektura celku: viz `docs/EKOSYSTEM.md`.

---

# ČÁST A — Kryptopodpis aktualizací

## A.1 Proč to je

Před 388 zákaznická instalace ověřovala update jen **SHA-256** checksumem. Jenže checksum si útočník, který kompromituje distribuční kanál (CDN / mirror / feed / hosting), **přepočítá sám** k podvrženému bundlu. Přidali jsme **asymetrický podpis**: bundle podepisuje **privátní klíč** vendoru, klient ověřuje **veřejným** klíčem zapečeným v kódu. Bez privátního klíče nikdo platný podpis nevyrobí → i plně kompromitovaný kanál nepodstrčí kód, který klient přijme.

## A.2 Jak to funguje

```
PUBLISH (vendor)                          APPLY (zákazník)
─────────────────                         ────────────────
manifest.json (files: {cesta: sha256})    stáhne bundle, ověří SHA-256
  │                                        extract do staging/
  ▼ openssl_sign (privátní klíč)           ▼
base64 podpis → vendor_updates.signature  čte staging/manifest.json (RAW byty)
  │                                        🔐 openssl_verify (veřejný klíč) — FAIL-CLOSED
  ▼ vrací check endpoint                   per-file SHA-256 (soubory sedí na manifest)
download_url + checksum + signature   ──▶  copy do live
```

**Klíčové rozhodnutí:** podepisuje se **obsah `manifest.json`** (mapa per-file SHA-256), **NE byty ZIPu**. Mirror bundle přebaluje (rekomprese → jiné byty kontejneru), ale obsah souborů — a tím manifest.json — zůstává bajt po bajtu stejný. Podpis tak přežije přebalení; per-file hash pak sváže reálné soubory s podepsaným manifestem.

**Fail-closed:** dokud je v klientu zapečený veřejný klíč (`appek_update_signing_enforced()` = true), **každý** OTA update musí nést platný podpis. Bundle bez podpisu / s neplatným / bez manifestu (RAW) se **odmítne**.

## A.3 Kde co je

| Co | Soubor | Konstanta / funkce |
|---|---|---|
| Veřejný klíč (ships zákazníkům) | `api/_update_sign.php` | `APPEK_UPDATE_PUBLIC_KEY`, `appek_verify_update_signature()` |
| Privátní klíč (jen vendor) | `vendor/config.local.php` | `APPEK_UPDATE_PRIVATE_KEY` |
| Podepisování | `vendor/_update_sign.php` | `vendor_sign_update_bundle()` |
| Podpis při publishi | `vendor/_self_update.php` (auto), `vendor/updates.php` (upload) | → `vendor_updates.signature` |
| Ověření při apply | `api/updates_apply.php`, `admin/force-update.php` | fail-closed |
| Doručení podpisu | `api/updates_check.php`, `updates/manifest.php` → `api/admin_version_check.php` → admin.js | |
| Sloupec + migrace | `vendor/_schema.sql`, `vendor_ensure_update_signature_column()` | `vendor_updates.signature` |

> Privátní klíč se **NIKDY** nebalí do customer bundlu (ten obsahuje jen `api/` + `admin/` + `b2b/`, ne `vendor/`) a je v gitignoru (`*.local.php`).

## A.4 ⚠️ INSTALACE PRIVÁTNÍHO KLÍČE (povinný krok)

Bez privátního klíče na vendoru `vendor_sign_update_bundle()` vrátí `null` → bundle se publikuje **nepodepsaný** → klienti 388+ ho **odmítnou** (fail-closed).

1. Otevři na **vendor serveru** soubor `vendor/config.local.php`.
2. Vlož na konec (před případné `?>`) blok:
   ```php
   // 🔐 APPEK update signing — privátní klíč (RSA-2048). Jen vendor server.
   define('APPEK_UPDATE_PRIVATE_KEY', <<<'PEM'
   -----BEGIN PRIVATE KEY-----
   …(obsah klíče)…
   -----END PRIVATE KEY-----
   PEM);
   ```
   (Při prvním nasazení 388 ti klíč vygeneroval Claude do `~/Desktop/APPEK-vendor-update-PRIVATE-KEY.txt` — zkopíruj odtud a soubor pak smaž.)
3. Ulož. Hotovo — vendor začne podepisovat.

**Pořadí nasazení (důležité):** hop 387→388 se **neověřuje** (387 nemá verifikační kód) → projde i nepodepsaný. Klíč ale musí být na vendoru **dřív, než vydáš 389** — jinak by 388 klienti uvízli na nepodepsané 389.

## A.5 Rotace / nový pár klíčů

Když chceš klíč vyměnit (kompromitace, pravidelná rotace):

```bash
# 1) vygeneruj pár
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out priv.pem
openssl pkey -in priv.pem -pubout -out pub.pem

# 2) ověř pár (musí říct "Verified OK")
echo -n test > m.txt
openssl dgst -sha256 -sign priv.pem -out s.bin m.txt
openssl dgst -sha256 -verify pub.pem -signature s.bin m.txt
```

- **Veřejný** klíč (`pub.pem`) vlož do `api/_update_sign.php` → konstanta `APPEK_UPDATE_PUBLIC_KEY` (heredoc `<<<'PEM'`).
- **Privátní** klíč (`priv.pem`) vlož do `vendor/config.local.php` → `APPEK_UPDATE_PRIVATE_KEY`.
- ⚠️ Rotace = **breaking**: bundle podepsaný NOVÝM privátem ověří jen klient s NOVÝM veřejným. Klient musí nejdřív dostat update s novým veřejným klíčem (ten hop podepiš ještě STARÝM klíčem), teprve další bundly podepisuj novým. (Stejný chicken-and-egg jako prvotní zavedení.)

## A.6 Ověření že to jede

- **Vendor podepsal?** V DB: `SELECT version, LEFT(signature,16) FROM vendor_updates WHERE status='published' ORDER BY id DESC LIMIT 3;` — `signature` nesmí být NULL.
- **Klient ověřil?** Log v `api/updates_apply.php` odpovědi obsahuje krok `🔐 Podpis manifestu ověřen (RSA-2048/SHA-256)`.
- **Lokální roundtrip test** (jako při zavedení):
  ```bash
  # podepiš manifest privátem, ověř veřejným v _update_sign.php → musí PASS
  ```

## A.7 Troubleshooting

| Symptom | Příčina | Řešení |
|---|---|---|
| Klient: `PODPIS NEPLATNÝ` | Bundle publikován **bez** podpisu (privátní klíč chybí na vendoru) | Nainstaluj klíč (A.4), re-publikuj verzi |
| Klient: `PODPIS NEPLATNÝ` | Veřejný v klientu ≠ privátní na vendoru (rozjetá rotace) | Sjednoť pár; viz A.5 |
| Klient: `NEPODEPSANÝ BALÍČEK` | Bundle je RAW (bez `manifest.json`) | OTA musí být BUNDLE formát (build-update.sh); RAW jen pro prvotní install |
| Vendor log: `⚠️ Bundle NEPODEPSÁN` | `APPEK_UPDATE_PRIVATE_KEY` není definovaný | A.4 |

---

# ČÁST B — CSRF ve vendor panelu

## B.1 Proč to je

Vendor je **master všeho** (licence, přístupy). Nad `SameSite=Lax` (která tlumí hlavní vektor) jsme přidali **synchronizer token** jako defense-in-depth — uzavírá úzké okno (prastaré prohlížeče, historické „Lax+POST" okno).

## B.2 Jak to funguje

```
Session token (vendor_csrf_token, 32B hex)
  ├── HTML formuláře:  hidden <input name="_csrf">   (vendor_csrf_field())
  └── JS/AJAX (api.php): hlavička X-CSRF-Token        (app.js → api.php?action=csrf)
        ▼
  vendor_csrf_check() na každém POST → hash_equals(session, sent) → jinak 403
```

- Helpery: `vendor/_lib.php` — `vendor_csrf_token()` / `vendor_csrf_field()` / `vendor_csrf_check()`.
- `api.php`: guard `if (POST) vendor_csrf_check(true)` + GET `?action=csrf` (token pro frontend).
- `app.js`: `api()` wrapper přidá `X-CSRF-Token` na všech ne-GET requestech (lazy-fetch tokenu).
- **10 form stránek** má hidden pole + guard: access, business-info, packages, pages-editor, pirate, sales-cms, self-update, settings, shop, updates.

## B.3 ⚠️ Pravidlo pro NOVÉ vendor endpointy

> **Každý nový state-changing POST ve vendor panelu MUSÍ přidat CSRF**, jinak je díra.

- **Form stránka:** na začátek POST větve `if ($_SERVER['REQUEST_METHOD'] === 'POST') vendor_csrf_check();` + do každého `<form method="POST">` vlož `<?php vendor_csrf_field(); ?>`.
- **api.php akce:** POST guard už kryje všechny akce centrálně — nic nepřidáváš, jen ať akce jde přes `api.php` a frontend přes `app.js` `api()` wrapper.

**VYJMUTO (CSRF se NEPŘIDÁVÁ):**
- `index.php` **login** (před session, standardně exempt)
- **Strojové endpointy:** `heartbeat.php`, `resolve.php`, `deploy-hook.php`, `install.php` — autentizují se HMAC / tokenem / first-run guardem, **ne session cookie**. CSRF token by je rozbil (zákazníci/CI je volají bez vendor session).

> Analogie s `require_admin()` POS allowlistem: i tady platí „nový endpoint = vědomě zařaď do správné kategorie".

## B.4 Troubleshooting

| Symptom | Příčina | Řešení |
|---|---|---|
| `403 — CSRF token neplatný` po dlouhé nečinnosti | Stránka otevřená přes reset session (re-login) | F5 (obnovit stránku → nový token) |
| Nový vendor formulář hází 403 | Chybí `vendor_csrf_field()` ve formuláři | Přidej hidden pole (B.3) |
| AJAX akce hází 403 | Volá se mimo `app.js` `api()` wrapper (chybí hlavička) | Volej přes `api()`, nebo přidej `X-CSRF-Token` ručně |

---

# ČÁST C — Deploy

```
git push main
  → CI postaví MASTER bundle + nasadí na vendor
  → vendor self-update PODEPÍŠE manifest (vyžaduje A.4!) → publish
  → demo auto-update + zákazníci OTA (s ověřením podpisu)
```

Před pushem lokálně: `scripts/build-update.sh X.Y.Z` (sync verze, regenerace admin.js, lint gate, bundle). Testy: `bash scripts/smoke.sh` + `php scripts/test-money-paths.php`.

---

## Související
- `docs/EKOSYSTEM.md` — architektura celku
- `docs/PRE-SALE-CHECKLIST.md` — předprodejní bezpečnostní stav
