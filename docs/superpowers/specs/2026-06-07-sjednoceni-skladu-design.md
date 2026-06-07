# Sjednocení skladových systémů — design

**Datum:** 2026-06-07
**Stav:** Schváleno (čeká na review specu)
**Cílová verze:** v3.0.168

## Problém

APPEK má dnes **dva nezávislé systémy** pro stav stejné věci (zásoba surovin):

| | Systém A (starý) | Systém B (nový) |
|---|---|---|
| Stav | `suroviny.stock_aktualni` (1 globální/surovina) | `sklad_polozky.stav` (per-sklad) |
| Historie | `sklad_pohyby` (surovina_id) | `sklad_pohyby_v2` (item_typ/item_id) |
| Píše | POS odpis, výroba, suroviny | Sklad obrazovka, příjem, výroba |
| Čte | seznam surovin, alerty, kalkulace | **obrazovka Sklad** |

**Důsledek (potvrzeno e2e testem na 22 výrobcích):** POS prodej odepíše systém A
(`stock_aktualni` přesně dle receptury), ale systém B (`sklad_polozky`) o tom neví —
surovina v něm často nemá ani řádek. **Obrazovka Sklad tak prodeje vůbec nevidí.**
Navíc vytvoření suroviny přes API plní jen A.

## Rozhodnutí (z brainstormingu)

1. **Zdroj pravdy = systém B** (`sklad_polozky`, per-sklad) — zachová multi-sklad
   (Hlavní / Chladicí) i přesuny.
2. **Každá surovina má domovský sklad** (`suroviny.domovsky_sklad_id`). POS odepisuje
   z domovského skladu té suroviny.
3. **`suroviny.stock_aktualni` se stane odvozeným součtem** (`SUM(sklad_polozky.stav)`
   přes sklady), automaticky přepočítávaným. → všichni stávající čtenáři (seznamy,
   alerty, kalkulace) fungují dál beze změny. Nízké riziko.

## Architektura

```
                    ┌──────────────── ZDROJ PRAVDY ────────────────┐
   POS prodej ─┐    │  sklad_polozky (sklad_id, item, stav)         │
   příjem    ──┼──► │  sklad_pohyby_v2 (log: prijem/vydej/…)        │
   výroba    ──┘    └───────────────┬──────────────────────────────┘
                                    │ po každé změně
                                    ▼
                    surovina_recompute_total()
                                    │
                                    ▼
              suroviny.stock_aktualni  =  Σ sklad_polozky.stav   (odvozený cache)
                                    │
                  ┌─────────────────┴─────────────────┐
            seznam surovin · alerty · kalkulace   (čtou cache, beze změny)
```

## Komponenty (jednotky)

### 1. DB schéma
- `suroviny += domovsky_sklad_id INT NULL` (FK `sklady.id`, ON DELETE SET NULL).
- Idempotentní `ALTER TABLE … ADD COLUMN IF NOT EXISTS`.

### 2. `api/_sklad_lib.php` (nová sdílená knihovna)
Dvě čisté funkce, jeden účel každá:
- `surovina_recompute_total(PDO $pdo, int $surovinaId): void`
  `UPDATE suroviny SET stock_aktualni = (SELECT COALESCE(SUM(stav),0) FROM sklad_polozky
   WHERE item_typ='surovina' AND item_id=:id) WHERE id=:id`
- `sklad_polozky_ensure(PDO $pdo, int $skladId, string $typ, int $itemId): int`
  vrátí id řádku; když neexistuje, vytvoří se stavem 0 (řeší „surovina nemá řádek v B").
- `surovina_home_sklad(PDO $pdo, int $surovinaId): int`
  vrátí `domovsky_sklad_id`, fallback = primární sklad (nejnižší aktivní id).

### 3. POS odpis — `pos_deduct_ingredients` (api/admin_pos.php)
Pro každou surovinu z receptury (v transakci, `SELECT … FOR UPDATE` na řádek):
1. `sklad = surovina_home_sklad()`
2. `rowId = sklad_polozky_ensure(sklad, 'surovina', surovinaId)`
3. `UPDATE sklad_polozky SET stav = stav - :mn WHERE id = :rowId`
4. log do `sklad_pohyby_v2` (typ=`vydej`, sklad_id, item, mnozstvi, stav_pred/po, kdo)
5. `surovina_recompute_total()`

Přestane se psát starý `sklad_pohyby` + přímý `stock_aktualni -=`.

### 4. Vytvoření suroviny — `api/admin_suroviny.php` POST
Po vložení suroviny: nastav `domovsky_sklad_id` (default primární) a `sklad_polozky_ensure`
v domovském skladu s počátečním stavem (zadaný `stock_aktualni`). → nová surovina je
hned v systému B.

### 5. Ostatní zapisovatelé `sklad_polozky`
`admin_sklad_pohyby.php`, `admin_sklad_polozky.php`, `admin_vyroba.php`: po každé změně
`sklad_polozky` zavolat `surovina_recompute_total()` (cache zůstane v souladu).

### 6. Detail suroviny — `api/admin_suroviny.php` GET + editor
- Historie pohybů čte `sklad_pohyby_v2` (sjednocený log).
- Editor: přidat picker „Domovský sklad".

### 7. Migrace (idempotentní, běží 1× při načtení admin_suroviny)
1. `ADD COLUMN domovsky_sklad_id` (pokud chybí).
2. `UPDATE suroviny SET domovsky_sklad_id = <primární sklad> WHERE domovsky_sklad_id IS NULL`.
3. Pro každou surovinu bez řádku v domovském skladu: `sklad_polozky_ensure` se stavem =
   aktuální `stock_aktualni` (přenese existující zásobu z A do B).
4. `surovina_recompute_total()` pro všechny → cache sjednocena.

## Datový tok po sjednocení

Prodej v POS → odpis z `sklad_polozky` domovského skladu → log `sklad_pohyby_v2` →
přepočet `stock_aktualni`. **Obrazovka Sklad (čte sklad_polozky) prodej okamžitě vidí.**
Seznam surovin / alerty (čtou stock_aktualni cache) ukazují správný součet.

## Testování

Smoke (rozšíření e2e z této session):
- Vytvoř surovinu → má řádek v `sklad_polozky` domovského skladu (B).
- Výrobek s recepturou → prodej v POS → `sklad_polozky` domovského skladu ubyl přesně
  dle receptury · `stock_aktualni = Σ sklad_polozky` · `sklad_pohyby_v2` má výdej ·
  obrazovka Sklad (admin_sklad_pohyby) prodej ukazuje.

## Rizika

- **POS odpis je kritická cesta** (nedávno hardened) → transakce + `FOR UPDATE` + smoke guard.
- **Migrace dat** musí být idempotentní a bezpečná na existujících instancích.
- Starý `sklad_pohyby` se přestane plnit; existující řádky zůstanou jako legacy historie
  (nemažeme).

## Mimo rozsah (YAGNI)

- Stav hotových výrobků (vyrobek) ve skladu — jen suroviny.
- Přepisování všech čtenářů `stock_aktualni` (zůstává jako cache).
- FIFO/expirace šarží napříč sklady.
- Bug „cena výrobku se neuloží přes API" (admin_vyrobky POST) — řeší se zvlášť.
