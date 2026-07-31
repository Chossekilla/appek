# APPEK — Backlog / vědomě odložené funkce

Funkce, které **nejsou bug ani nedodělek** — čekají na poptávku nebo legislativu.
Zapisuj sem rozhodnutí „teď ne, později ano (a jak)".

---

## 🧾 EET / elektronická evidence tržeb — ODLOŽENO

**Stav (2026-07):** EET (zákon 112/2016 Sb.) bylo **zrušeno k 1. 1. 2023**. Návrat se politicky zvažuje, ale **konkrétní zákon ani technická specifikace zatím nejsou** — a nová verze může mít jiný formát než ta původní.

**Rozhodnutí:** **Teď není potřeba — nestavíme.** Dopíše se, až (a) vyjde reálný zákon + spec, **nebo** (b) bude poptávka na **Slovensku** — pozor, SK má vlastní systém **eKasa**, který je **jiný** než české EET (samostatná implementace).

**Jak by se to udělalo (až bude jasná specifikace):**

- **Karta v Nastavení → Integrace** „🧾 EET / eKasa" — stejný vzor jako ostatní integrace (Stripe / GoPay / přepravci / účetní): nahrání **certifikátu** (`.p12/.pfx` + heslo maskované, mimo bundle), **DIČ / ID provozovny / ID pokladny**, režim (běžný/zjednodušený), timeout, přepínač **Test (Playground) / Ostré**, hlavní on/off.
- **Sdílená lib `_eet_lib.php`** (testovatelná zvlášť): sestavení XML datové zprávy → **PKP** (RSA-SHA256 podpis) + **BKP** (SHA-1) → SOAP přes **mTLS** na `prod.eet.cz` → parse **FIK**. **Offline režim:** při timeoutu (min 2 s) prodej proběhne, na účtenku **PKP+BKP** místo FIK, zpráva do **fronty `eet_fronta`** na doposlání (do 48 h, cron/retry).
- **Napojení:** oba POS soubory (`admin/pos.php` + `/pos/`) + kanálový registr (`objednavky.puvod`); povinné údaje (FIK/BKP/PKP, DIČ, provozovna, pokladna, číslo, datum/čas, částka, režim) na **účtenku i fakturu**.
- **Test:** nejdřív proti **Playground** (`pg.eet.cz`), pak ostré.
- **⚠️ Právní/daňové** (které tržby podléhají, režim, kdy) = **účetní / daňový poradce**, ne vývoj.

*(Souvisí: [PRE-SALE-CHECKLIST.md](PRE-SALE-CHECKLIST.md) Fáze 0 compliance, [FAKTURACE-PODKLAD-UCETNI.md](FAKTURACE-PODKLAD-UCETNI.md).)*
