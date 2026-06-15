# APPEK — Pre-sale checklist

Stav k zahájení: **v3.0.351**. Legenda: ✅ hotovo · 🔄 rozpracováno · ⬜ čeká · ⚠️ potřebuje kvalifikovanou osobu.

Model nasazení: **self-hosted, 1 instalace = 1 zákazník = vlastní DB** → cross-tenant únik mezi RŮZNÝMI kupci nehrozí; rizikem je únik mezi *odběrateli jedné instalace* (B2B portál) a veřejná/neautentizovaná plocha.

---

## FÁZE 0 — Blokery (před PRVNÍM placeným prodejem)

### Bezpečnost
- 🔄 **Systematický IDOR/auth audit VŠECH endpointů** — každý zákaznický (B2B) scoped na `odberatel_id` ze session (ne z body/URL). *(B2B faktura/DL/payment opraveno ve v349; teď dořešit zbytek.)*
- ⬜ Public/token plocha (faktura/dodaci_list token, katalog, qr/pay, webhooky, install, version) — žádný leak bez/s minimálním auth
- ⬜ POS `pos_only` session — allowlist těsný, žádný endpoint mimo POS dosažitelný PINem
- ⬜ File upload (logo, předlohy, import CSV/XML) — kontrola typu/velikosti, path-traversal, GD re-encode *(kategorie ✅ dle auditu)*
- ⬜ SQL injection sweep — grep string-interpolace user inputu do dotazů
- ✅ Webhooky fail-closed (v315) · ✅ payment_methods IBAN za auth (v349) · ✅ odpis idempotentní (v316)
- ⚠️ Měna `?action=prepocet` NENÍ idempotentní (zmírněno zálohou+potvrzením) — doplnit token guard
- ⬜ Žádné secrety v zákaznickém bundlu (klíče/hesla/IBAN) + `config.local.php`/`.env` mimo zip

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
- ⬜ **Automatické testy na peněžní cesty** (objednávka→DL→faktura, sklad odpis, ceník/sleva/sezóna) — teď 0 testů
- ⬜ Smoke test všech endpointů (status/auth) — harness z auditů zformalizovat
- ⬜ JS build/lint gate (`node --check`/esbuild) — `admin.js` 42,5k ř. nemá syntax pojistku
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
