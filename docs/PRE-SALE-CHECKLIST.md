# APPEK — Pre-sale checklist

Stav k zahájení: **v3.0.351**. Legenda: ✅ hotovo · 🔄 rozpracováno · ⬜ čeká · ⚠️ potřebuje kvalifikovanou osobu.

Model nasazení: **self-hosted, 1 instalace = 1 zákazník = vlastní DB** → cross-tenant únik mezi RŮZNÝMI kupci nehrozí; rizikem je únik mezi *odběrateli jedné instalace* (B2B portál) a veřejná/neautentizovaná plocha.

---

## FÁZE 0 — Blokery (před PRVNÍM placeným prodejem)

### Bezpečnost
- ✅ **Systematický IDOR/auth audit VŠECH endpointů** (v349–353, 4 paralelní audity). Nalezeno+opraveno **5 kritických**: B2B IDOR faktury/DL, version.php leak licenčního klíče, updates_apply zip-slip RCE, paypal order-swap, payment_methods IBAN — + mediumy.
- ✅ Public/token plocha — webhooky jídlo fail-closed, pos_qr/cron/check_install/admin_reset_demo/diag gated; version.php klíč maskovaný (anon), diag_apps gated, healthcheck throttled (30s cache)
- ✅ POS `pos_only` — allowlist + updates_apply pos_only reject (v353)
- ✅ File upload — GD re-encode + uploads/ zákaz spuštění php (.htaccess v352)
- ✅ SQL injection sweep — nenalezeno (bound params napříč)
- ✅ Webhooky fail-closed (v315) · payment_methods IBAN za auth (v349) · odpis idempotentní (v316) · gopay callback kontrola částky (v353)
- ✅ Secrety v bundlu — version.php klíč maskovaný (anon); `config.local.php`/`vendor/` mimo zip (build-zip)
- ⬜ **ARCHITEKTURA (samostatný projekt):** podpis bundlu + asymetrické podpisy licencí (bundle integrity + klient-side unlock balíčků); updates_apply CSRF (koordinovat s updater.html); license_enforce hard-lock core (business rozhodnutí — lockout risk)
- ⚠️ Měna `?action=prepocet` idempotence (token guard) — zmírněno zálohou+potvrzením

### Data integrita / peníze
- ✅ Ceny server-authoritative (B2B ověřeno — klient cenu neurčuje)
- 🔄 Fakturace **početně** správná (audit v356): ✅ DPH rozpis reconciliace (sedí na haléř i u vícesazbových), ✅ dobropis `datum_dph`, ✅ náležitosti dokladu (dodavatel/odběratel/IČO/DIČ/3 data/VS), ✅ číselné řady race-safe (atomický čítač + UNIQUE). ⬜ ISDOC plná XSD-konformita (`IssuingSystem` doplněn; zbytek ověřit proti reálnému XSD + import do Pohody). **PRÁVNÍ** správnost = odborník.
- ⚠️ **Rozhodnutí majitele/účetní (fakturace):** (a) dobropis nevrací zboží na sklad (z větší části by-design — výrobky neskladované); (b) dobropis nesnižuje tržby (statistiky čtou z `objednavky`, ne `faktury`) = účetní model. ✅ (c) **VYŘEŠENO v358** — mazání vydaných dokladů zamykatelné přepínačem (Nastavení → Údržba → 📄 Doklady, **default zamknuto**).
- ✅ Sklad A=B konzistence (integrity audit nástroj) · ✅ fresh-install schema (hardened, sweep clean)

### Compliance ⚠️ (kvalifikovaná osoba, ne já)
- ⚠️ GDPR — zpracování PII, retence, výmaz, zpracovatelská smlouva (právě tekly faktury → bereme vážně)
- ⚠️ Fakturace dle zákona (náležitosti, číselné řady), EET pokud relevantní
- ⬜ Cookie consent / GA4 gating (značená mezera)
- ⬜ EULA / licenční podmínky produktu

---

## FÁZE 1 — Kvalita & udržitelnost (před ŠKÁLOVÁNÍM prodeje)
- ✅ **Automatické testy peněžních cest** — `scripts/test-money-paths.php` (read-only, 0 side-effects, **475 asserts PASS**): ceník chokepoint, sleva skupiny, BOM odpis, sezónní úprava + řetězec **objednávka→DL→faktura** (header math celkem==bez+dph, dobropis≤0, faktura==ΣDL, referenční integrita). Spusť: `php scripts/test-money-paths.php`. ⬜ Volitelně: HTTP create-cleanup E2E (amount-konzistenci jinak hlídá runtime integrity audit)
- ✅ Smoke test všech endpointů — `scripts/smoke.sh` (no-500 sweep + auth-required→401/403 + public→200); hned našel+opravil 2 webhook 500 (gopay/stripe). Spusť: `bash scripts/smoke.sh [base]`
- 🔄 JS build/lint gate — `node --check` přidán do build-update.sh (admin/+b2b/ .js); aktivuje se po instalaci node (zatím ⚠️ skip). **TODO: `brew install node`.**
- ⬜ Rozbít `admin.js` monolit do modulů — bus factor
- ✅ CI publish **FUNGUJE** — celý `release.yml` (build ZIP + GitHub Release + **deploy-hook auto-publish**): u v3.0.360 deploy step vrátil **HTTP 200 `{"status":"ok","published":true,"health":{"ok":true}}`** (DEPLOY_TOKEN opraven 31.5.). **`git push origin main --tags` STAČÍ** — vendor feed + appek.cz master se publikují/aktualizují samy. Ruční curl publish byl redundantní. (Pozn.: runs „zelené" i při deploy failu kvůli `continue-on-error` → pravdu řekne deploy step log.)

---

## FÁZE 2 — Distribuce & support (aby zákazník nevolal naštvaný)
- 🔄 Install robustní — design OK (`install.php` recreates config; `config.local.php` není v bundlu → update ho nepřepíše), fresh-install schema hardened. Ztráta `config.local.php` = externí (hosting/FTP), ne chyba appky.
- ✅ Self-update spolehlivý — **E2E OVĚŘENO** (v360): demo updatováno **294→360** reálným self-update (download 3 MB + SHA verify + záloha + apply + migrace), pak **smoke 177/0 = zdravé** po 66-verzním skoku. Plus auto-rollback (`restore_from_backup`) + inode retence + UA fix. ⚠️ demo NEMÁ auto-update cron (ruční trigger).
- ✅ HTTPS/HSTS (v359) — HSTS hlavička jen na HTTPS (`env=HTTPS` v .htaccess) → HTTP instalace nerozbije; 6 měsíců, bez includeSubDomains/preload (recoverable). Force-HTTPS redirect = volitelný hosting-level. **✅ LIVE OVĚŘENO na demu (HTTPS): `strict-transport-security: max-age=15768000`.**
- ✅ Dokumentace — [INSTALL.md](../INSTALL.md) (instalace/licence/onboarding) + **[docs/PROVOZNI-PRIRUCKA.md](PROVOZNI-PRIRUCKA.md)** (update/zálohy/troubleshooting/FAQ/migrace) napsána (v357)
- ⬜ Support proces (kanál, SLA, vzdálená diagnostika — chce SSH na instalace) — *business rozhodnutí*
- ✅ Onboarding pro KUPUJÍCÍHO — wizard + provozní příručka (PROVOZNI-PRIRUCKA.md)

---

## FÁZE 3 — Produkt & business
- ⬜ Pricing/licence (balíčky ✅) + trial + refund policy
- ✅ Demo prostředí (self-update funguje, pomalejší cron)
- ⬜ Mobil/iOS ověřeno na REÁLNÉM zařízení (Chrome MCP neumí <desktop viewport)
- ⬜ Výkon pod zátěží (kombinovaný provoz 5 kanálů)
- ✅ i18n (v360) — genuine zbytek přeložen (EN/ES/SK/DE); ostatní „missing" z auditu = non-translatable junk (placeholdery/jednotky/technické labely/příklady/URL) + emoji-only (flexible lookup OK)

---

## Doporučené pořadí
**0 (bezpečnost) → 1 (testy/build) → 2 (distribuce/support) → compliance paralelně přes odborníka → 3.**
Pravidlo: neprodávat „na ostro", dokud Fáze 0 bezpečnost + data integrita nejsou ✅ a není aspoň minimální test coverage na peněžní toky.
