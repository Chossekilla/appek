# Catering — produkty z katalogu (návrh)

**Datum:** 2026-07-08
**Balíček:** Catering (gated roční licencí)
**Přístup:** A — rozšíření `catering_config` (JSON v `nastaveni`), bez nové DB tabulky.

## Cíl

Umožnit přidávat produkty z katalogu výrobků do cateringu — jako trvalý číselník
v konfiguraci kalkulačky **i** ad-hoc do konkrétní nabídky — s nastavením porce na
osobu, dostupnosti/povinnosti a s výkonným pickerem (nenabíhá dlouho i u velkého katalogu).

## Rozhodnutí (z brainstormingu)

- **Kam:** obojí — číselník v „Nastavení kalkulačky" + ad-hoc v konkrétní nabídce.
- **Co editovat u produktu:** porce/množství na osobu, dostupnost (zap/vyp), povinné vs. volitelné extra. **NE** vlastní název/cenu (berou se z katalogu).
- **Sekce nabídky:** převzaté z `kategorie_id` výrobku (žádný samostatný editor sekcí).
- **Picker:** hledání podle názvu + **dropdown filtr kategorie** + **server-side pagination**.

## Datový model

Žádná nová tabulka. Do `catering_config` (klíč `nastaveni.catering_config`) přibude klíč `produkty`:

```json
"produkty": [
  { "vyrobek_id": 42, "porce_na_osobu": 1.5, "aktivni": true, "povinne": true, "poradi": 0 }
]
```

- `porce_na_osobu` (float) — množství na osobu; kalkulace = `osob × porce_na_osobu`.
- `aktivni` (bool) — zobrazí se v nabídce.
- `povinne` (bool) — `true` = vždy v základní kalkulaci; `false` = volitelné extra (přičte se jen když ho klient zvolí, účtuje se příplatkem = katalogová cena).
- `poradi` (int) — pořadí v rámci sekce.
- **Odvozené za běhu joinem na `vyrobky`** (neukládá se): `nazev`, `cena`, `kategorie_id` + název kategorie (= sekce), `material` (BOM cost pro marži), `recept_polozek`.

Migrace není potřeba (JSON), tím pádem **žádné riziko rozbití čisté instalace** (viz [[appek-fresh-install-schema]]).

## Komponenty

### Backend — `api/admin_catering_calc.php`
1. **Config load/save** — `catering_config()` / uložení rozšířit o `produkty` (validace: vyrobek_id int, porce_na_osobu ≥ 0, aktivni/povinne bool). Defaultně prázdné pole.
2. **`?action=options`** — vrátí nakonfigurované produkty dekorované joinem na `vyrobky` (nazev/cena/material/kategorie), grupované dle kategorie. Smazaný výrobek → `{ smazany: true }` (UI ho označí, kalkulace přeskočí).
3. **`?action=produkty_pick&q=&kategorie=&page=`** — picker feed:
   - `SELECT ... FROM vyrobky WHERE (q LIKE) AND (kategorie_id = ?) ORDER BY nazev LIMIT :lim OFFSET :off`
   - `LIMIT` ~24; vrací `{ items:[{id,nazev,cena,kategorie_id,kategorie}], total, page, pages }`.
   - Vyloučí výrobky, které už v číselníku jsou (nebo je označí `pridano:true`).
4. **`?action=quote` / `create_order`** — zahrnou nakonfigurované produkty (`osob × porce_na_osobu`, jen `aktivni`; volitelné jen když zvoleny) + ad-hoc produkty z nabídky. `create_order` používá stávající `vyrobek_id × mnozstvi` → výroba/odpis surovin (beze změny).

### Admin UI — `admin/src/0560-catering-kalkulator-*.js` (tab „⚙️ Nastavení kalkulačky")
- Nová sekce **„Produkty z katalogu"**: seznam grupovaný dle kategorie; u každé položky inline editace `porce_na_osobu`, přepínač `aktivni`, přepínač povinné/volitelné, drag/šipky pořadí, odebrat.
- **„+ Přidat produkt"** → **picker modal**: vyhledávací pole (debounce ~300 ms), dropdown kategorie, stránkování (prev/next + čísla). Klik na produkt ho přidá s defaulty (porce 1, aktivní, povinné).

### Nabídka/objednávka UI
- **„+ Přidat produkt"** (stejný picker) → jednorázová položka do konkrétní nabídky (mimo číselník), s vlastní porcí/množstvím.

## Datový tok

1. **Config:** admin vybere výrobky přes picker → uloží se `produkty[]` do `catering_config`.
2. **Options:** endpoint dekoruje joinem → UI grupuje dle kategorie.
3. **Quote:** `osob × porce_na_osobu` pro aktivní produkty → množství → cena z katalogu → součet; volitelná extra přičtena při výběru.
4. **Order:** řádky `vyrobek_id × mnozstvi` → existující výroba/odpis.

## Ošetření okrajů

- Smazaný/neexistující výrobek v configu → „(smazaný produkt)", vyřazen z kalkulace, jde odebrat.
- Picker: prázdný výsledek hledání → prázdný stav; ošetřit rychlé po sobě jdoucí requesty (debounce + ignorovat staré odpovědi).
- `porce_na_osobu = 0` nebo neaktivní → do kalkulace nevstupuje.
- Balíček gating: sekce/endpointy jen když je Catering balíček vlastněný (viz stávající gating [[appek-balicky-licence]]).

## Testování

- `php scripts/test-money-paths.php` — rozšířit: quote s nakonfigurovanými produkty škáluje `osob × porce_na_osobu`; volitelná extra se nezapočte bez výběru; order vytvoří správné `vyrobek_id × mnozstvi` řádky.
- `bash scripts/smoke.sh` — endpoint `produkty_pick` vrací 200 + validní JSON s paginací.
- Ruční: čistá instalace (config prázdný `produkty`), picker u velkého katalogu (rychlost).

## Mimo rozsah (YAGNI)

- Vlastní catering název/cena produktu (uživatel nechce — z katalogu).
- Samostatný editor sekcí (sekce = kategorie výrobku).
- Nová DB tabulka (přístup B odmítnut kvůli riziku migrace).
