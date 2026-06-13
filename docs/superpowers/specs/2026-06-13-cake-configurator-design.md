# Konfigurátor dortů „pořádně" — design (2026-06-13)

## Cíl
Dodělat konfigurátor dortů (Cukrárna balíček) na plně admin-konfigurovatelný nástroj:
1. **Editovatelné velikosti s cenami** (dnes hardcoded `cake_sizes()`).
2. **Více možností** — rozšiřitelné skupiny voleb (náplň/poleva/dekorace/svíčky…).
3. **Propojení do surovin a výrobků** — volby odečítají sklad + počítají do kalkulace.

Navazuje na v3.0.297 (příchuť = dort výrobek `obor='dort'` s recepturou, cena = výrobek × škála).

## Rozhodnutí (potvrzeno uživatelem)
- **Cena velikostí = NÁSOBIČ na příchuť** (zachová rozdíly příchutí + škálování receptu).
- **Skupiny možností = plně admin-editovatelné** (uloženo v `nastaveni` JSON).
- **Volby linkují na SUROVINU nebo VÝROBEK** (s množstvím) → odpis + náklad.

## Datový model — `nastaveni.cake_config` (JSON)
```
{
  "velikosti": [ {id, label, hmotnost_g, nasobic} ],          // nasobic default = hmotnost_g/1000
  "moznosti":  [ {
      id, nazev, typ: "single"|"multi", povinne: bool,
      volby: [ {
        id, nazev, priplatek_kc,
        link_typ: "none"|"surovina"|"vyrobek",
        link_id,            // surovina_id nebo vyrobek_id
        mnozstvi, jednotka  // kolik se spotřebuje na 1 dort
      } ]
  } ]
}
```
- Defaulty se naseedují při prvním načtení: současné velikosti + skupina „Dekorace" (z dnešních `cake_decorations`) + startovní „Náplň"/„Svíčky".
- Příchutě NEJSOU v configu — zůstávají dort výrobky (`obor='dort'`).

## Backend (`admin_cake_configurator.php`)
- `cake_config(pdo)` — načte z `nastaveni` (merge s defaulty); `cake_config_save()`.
- `action=options` — velikosti (config) + prichute (DB) + moznosti (config, s materiálovým nákladem voleb z linku).
- `action=quote` — cena = příchuť.cena × velikost.nasobic + Σ vybraných voleb priplatek_kc; kalkulace materiál = příchuť.material × nasobic + Σ voleb (surovina: mnozstvi × cena_baleni/obsah_baleni; výrobek: recept × mnozstvi).
- `action=create_order`:
  - Řádek příchuti: `vyrobek_id × nasobic` (jako v297).
  - Volba `link=vyrobek` → řádek `vyrobek_id × mnozstvi` → standardní odpis.
  - Volba `link=surovina` → řádek s `surovina_id × mnozstvi` (viz odpis níže).
  - Volba `link=none` → volný příplatkový řádek (bez odpisu).
- `action=save_config` (super_admin) — uloží `cake_config`.

## Odpis surovin u voleb linkujících přímo surovinu
- **Migrace:** `objednavky_polozky` + sloupec `surovina_id INT NULL` (+ index).
- **Výroba** (`admin_vyroba.php` spotreba + odepsat_suroviny): stávající agregace přes
  `vyrobek_suroviny ON vs.vyrobek_id = op.vyrobek_id` se **rozšíří o UNION** druhé větve:
  přímá spotřeba `SUM(op.mnozstvi) FROM objednavky_polozky op WHERE op.surovina_id = s.id`.
  Tím se volby-surovina odečtou + objeví v plánu spotřeby i inventuře stejně jako recepty.
- Odpis (systém B, `sklad_polozky` + `sklad_pohyby_v2`) beze změny — jen rozšířený zdroj potřeby.

## Admin editor (frontend, nový tab v Cukrárně „⚙️ Nastavení konfigurátoru")
- CRUD velikostí (label/hmotnost/nasobic, přidat/smazat/řadit).
- CRUD skupin možností (nazev, single/multi, povinné) + voleb (nazev, příplatek, link surovina/výrobek + množství) se živým náhledem materiálu.
- Uložení přes `action=save_config`.

## Konfigurátor (frontend `renderCakeConfigurator`)
- Velikosti z configu (nasobic). Dynamické skupiny možností (single=radio, multi=checkbox).
- Panel „🧮 Z kalkulace" počítá materiál (příchuť×nasobic + volby) + marži; vybrané volby se přenášejí do quote/create_order.

## Testování (E2E localhost)
- Seed defaultů; admin editor přidá velikost + skupinu „Náplň" s volbou link=surovina (marmeláda 80g) + volbou link=výrobek.
- Quote: cena = příchuť×nasobic + příplatky; materiál škáluje.
- create_order → ověřit ve `admin_vyroba spotreba`: recept příchuti × nasobic + volba-surovina (přímo) + volba-výrobek (recept) se sečtou.
- Inventura/odpis: standardní tok.

## Verze
v3.0.299 (jeden release: backend + migrace + admin editor + konfigurátor + výroba UNION).
