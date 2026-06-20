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
- ⬜ Fakturace právně i početně správná (ISDOC, DPH rozpis, dobropisy, zaokrouhlení) — projít doklad po dokladu
- ✅ Sklad A=B konzistence (integrity audit nástroj) · ✅ fresh-install schema (hardened, sweep clean)

### Compliance ⚠️ (kvalifikovaná osoba, ne já)
- ⚠️ GDPR — zpracování PII, retence, výmaz, zpracovatelská smlouva (právě tekly faktury → bereme vážně)
- ⚠️ Fakturace dle zákona (náležitosti, číselné řady), EET pokud relevantní
- ⬜ Cookie consent / GA4 gating (značená mezera)
- ⬜ EULA / licenční podmínky produktu

---

## FÁZE 1 — Kvalita & udržitelnost (před ŠKÁLOVÁNÍM prodeje)
- 🔄 **Automatické testy peněžních cest** — `scripts/test-money-paths.php` (read-only, 0 side-effects): ceník chokepoint, sleva skupiny, BOM odpis, sezónní úprava — **418 asserts PASS**. Spusť: `php scripts/test-money-paths.php`. ⬜ Zbývá: HTTP write-chain E2E (vytvoř obj→DL→faktura→assert částky→cleanup) + amount-consistency hlídá i runtime integrity audit
- ✅ Smoke test všech endpointů — `scripts/smoke.sh` (no-500 sweep + auth-required→401/403 + public→200); hned našel+opravil 2 webhook 500 (gopay/stripe). Spusť: `bash scripts/smoke.sh [base]`
- 🔄 JS build/lint gate — `node --check` přidán do build-update.sh (admin/+b2b/ .js); aktivuje se po instalaci node (zatím ⚠️ skip). **TODO: `brew install node`.**
- ⬜ Rozbít `admin.js` monolit do modulů — bus factor
- ⬜ CI publish spolehlivý (teď flaky → publikuju ručně na vendor)

---

## FÁZE 2 — Distribuce & support (aby zákazník nevolal naštvaný)
- ⬜ Install robustní (`config.local.php` mizí→install loop; fresh-install schema)
- ⬜ Self-update spolehlivý (inode retence, hcdn cache, UA) + rollback; demo cron cadence
- ⬜ HTTPS/HSTS po SSL zákazníka (teď zakomentováno)
- ⬜ Dokumentace: instalace / update / zálohy / FAQ / troubleshooting
- ⬜ Support proces (kanál, SLA, vzdálená diagnostika — chce SSH na instalace)
- ⬜ Onboarding pro KUPUJÍCÍHO (ne jen end-usera) — wizard ✅, ale provozní příručka chybí

---

## FÁZE 3 — Produkt & business
- ⬜ Pricing/licence (balíčky ✅) + trial + refund policy
- ✅ Demo prostředí (self-update funguje, pomalejší cron)
- ⬜ Mobil/iOS ověřeno na REÁLNÉM zařízení (Chrome MCP neumí <desktop viewport)
- ⬜ Výkon pod zátěží (kombinovaný provoz 5 kanálů)
- ⬜ i18n dokončení (112 zbývá; emoji-only OK)

---

## Doporučené pořadí
**0 (bezpečnost) → 1 (testy/build) → 2 (distribuce/support) → compliance paralelně přes odborníka → 3.**
Pravidlo: neprodávat „na ostro", dokud Fáze 0 bezpečnost + data integrita nejsou ✅ a není aspoň minimální test coverage na peněžní toky.
