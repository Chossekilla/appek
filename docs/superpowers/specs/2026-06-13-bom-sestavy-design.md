# Sestavy / víceúrovňový BOM pro výrobu — design (2026-06-13)

## Cíl
Recept výrobku může obsahovat suroviny I jiné výrobky (polotovary: korpus, krém, poleva,
pomazánka, omáčka). Editovatelné, propojené, vše funkční. Pomáhá lidem ve výrobě
(cukrárna/lahůdky/restaurace/catering) — jeden engine, polotovary = znovupoužitelné výrobky.

## Rozhodnutí (potvrzeno)
- **Hybrid sklad polotovarů:** polotovar může mít vlastní sklad (`sleduje_sklad`). Má-li → ve výrobě
  se ubírá ze skladu polotovaru. Nemá-li → recept se rekurzivně rozpadne až na suroviny.
- Zahrnuto: víceúrovňový recept (sestava), rozpad spotřeby ve výrobě, rollup nákladů+alergenů,
  škálování dávky, výrobní postup (kroky).

## Datový model (auto-migrace)
- `vyrobek_suroviny` + `slozka_vyrobek_id INT NULL` (+ index). Řádek receptu = BUĎ `surovina_id`
  (raw) NEBO `slozka_vyrobek_id` (polotovar/výrobek) + `mnozstvi`/`jednotka`. `surovina_id`
  povolit NULL (sub-výrobek řádky). Existující řádky beze změny.
- `vyrobky` + `je_polotovar TINYINT(1) DEFAULT 0`, `sleduje_sklad TINYINT(1) DEFAULT 0`,
  `postup_json TEXT NULL`.
- Stockovaný polotovar = sklad přes systém B `sklad_polozky` s `item_typ='vyrobek'` (existující mechanismus).

## Engine — `api/_bom_lib.php` (sdílený, rekurzivní, cyklus-guard depth≤8 + visited set)
- `bom_explode(pdo, vyrobek_id, qty, &$potreba_sur, &$potreba_pol, depth, visited)`:
  - řádek surovina → `$potreba_sur[surovina_id] += qty × mn`
  - řádek polotovar + `sleduje_sklad=1` → `$potreba_pol[vyrobek_id] += qty × mn` (ubere ze skladu)
  - řádek polotovar + `sleduje_sklad=0` → `bom_explode(polotovar, qty × mn, …)` (rekurze na suroviny)
  - cyklus/hloubka → stop + log.
- `bom_cost(pdo, vyrobek_id, visited)`: Σ surovina (cena_baleni/obsah_baleni × mn) + Σ polotovar
  (bom_cost(polotovar) × mn). Vrací materiálový náklad.
- `bom_allergens(pdo, vyrobek_id, visited)`: union alergenů surovin + alergenů polotovarů (rekurzivně).

## Kde to plugne
- **admin_vyrobky.php**: auto-migrace sloupců; receptura GET vrací řádky (surovina|polotovar) +
  `bom_cost` + `bom_allergens` + `postup_json` + flagy; POST/PUT syncReceptura přijme oba typy řádků +
  flagy + postup. Při výběru polotovaru: validace že to není sám sebe / cyklus.
- **admin.js editVyrobek**: editor receptu — přidat řádek typu **Surovina** nebo **Výrobek (polotovar)**
  (picker), množství/jednotka; náhled rolled-up nákladů + alergenů; checkbox `je_polotovar`/`sleduje_sklad`;
  editor kroků postupu (přidat/smazat/řadit); škálování (N porcí/kg → přepočet + „vyrobitelné ze skladu").
- **admin_vyroba.php**: spotřeba/odpis — nahradí flat SQL `vyroba_potreba_src` PHP rozpadem
  (`bom_explode` per objednací řádek dne) → akumuluje suroviny + stockované polotovary →
  odpis systém B (item_typ surovina i vyrobek). Zachovat přímé `op.surovina_id` (konfigurátor) jako leaf.
- **Výrobní list** (`admin_vyroba` výrobní list / vyrobni_list_print): zobrazí postup kroků (+ tisk).

## Škálování dávky
- `?action=skalovat` (admin_vyrobky nebo vyroba): recept × faktor (N porcí / N kg / N ks) → rozpad →
  potřeby. + „kolik vyrobím z aktuálního skladu" = min přes suroviny (stock/per-unit).

## Testování (E2E localhost)
- Vytvoř polotovar „Máslový krém" (recept: máslo+cukr moučka) + „Korpus" (mouka+vejce+cukr).
- Dort čokoládový: recept → Korpus (1) + Máslový krém (1) + kakao + čokoláda. (sestava 2 úrovně)
- bom_cost(dort) = korpus + krém + suroviny; alergeny = union.
- Výroba: objednávka dortu → spotřeba rozpadne na suroviny korpusu+krému (sleduje_sklad=0) NEBO
  ubere krém ze skladu (sleduje_sklad=1). Ověřit obě varianty.
- Škálování: dort ×5 → potřeby ×5. Postup kroky tisk.
- Cyklus guard: A→B→A nespadne.

## Verze
v3.0.303+ (po vrstvách: lib+migrace → admin_vyrobky → editor → výroba → škálování+postup).
