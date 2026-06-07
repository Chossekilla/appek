# Sjednocení skladových systémů — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sjednotit dva skladové systémy tak, aby `sklad_polozky` (per-sklad) byl jediný zdroj pravdy a POS prodej se promítl do obrazovky Sklad.

**Architecture:** `sklad_polozky.stav` = pravda (per-sklad). `suroviny.stock_aktualni` = odvozený součet (cache) přepočítávaný po každé změně. Každá surovina má domovský sklad, odkud POS odepisuje. Idempotentní migrace přenese existující zásobu A→B.

**Tech Stack:** PHP 8 + PDO MySQL/MariaDB. Testy = `tests/smoke.sh` (bash + curl + SQL). Deploy = `scripts/build-update.sh` + ruční vendor publish.

---

## Konvence pro testování

Testy se přidávají do `tests/smoke.sh` (existující harness). Spouští se `bash tests/smoke.sh` proti lokálnímu XAMPP API (`http://localhost/appek/api`). Před testem vždy `bash scripts/sync-local.sh` (rsync working dir → htdocs). Helpery v smoke.sh: `q()` (SQL), `api()` (vrací body), `http()` (status), `jval()` (JSON extract), `aeq`/`acont`/`ok`/`no`/`sk`.

**TDD smyčka u každého tasku:** přidej smoke check → `sync-local` → `bash tests/smoke.sh` (selže) → implementuj PHP → sync → smoke (projde) → commit.

---

## Task 1: Sdílená knihovna `_sklad_lib.php` + idempotentní migrace

**Files:**
- Create: `api/_sklad_lib.php`
- Modify: `api/admin_suroviny.php` (require lib + zavolat migraci v GET)
- Test: `tests/smoke.sh` (sekce #S1)

- [ ] **Step 1: Napiš knihovnu** — `api/_sklad_lib.php`:

```php
<?php
// 🆕 v3.0.168 — sjednocený sklad: sklad_polozky = zdroj pravdy,
// suroviny.stock_aktualni = odvozený součet (cache).
if (!function_exists('sklad_default_id')) {

function sklad_default_id(PDO $pdo): int {
    return (int) $pdo->query("SELECT id FROM sklady WHERE COALESCE(aktivni,1)=1 ORDER BY id LIMIT 1")->fetchColumn();
}

function surovina_home_sklad(PDO $pdo, int $surovinaId): int {
    $st = $pdo->prepare("SELECT domovsky_sklad_id FROM suroviny WHERE id=:id");
    $st->execute(['id' => $surovinaId]);
    $home = (int) $st->fetchColumn();
    return $home > 0 ? $home : sklad_default_id($pdo);
}

function sklad_polozky_ensure(PDO $pdo, int $skladId, string $typ, int $itemId): int {
    $st = $pdo->prepare("SELECT id FROM sklad_polozky WHERE sklad_id=:s AND item_typ=:t AND item_id=:i LIMIT 1");
    $st->execute(['s' => $skladId, 't' => $typ, 'i' => $itemId]);
    $id = (int) $st->fetchColumn();
    if ($id) return $id;
    $pdo->prepare("INSERT INTO sklad_polozky (sklad_id,item_typ,item_id,stav) VALUES (:s,:t,:i,0)")
        ->execute(['s' => $skladId, 't' => $typ, 'i' => $itemId]);
    return (int) $pdo->lastInsertId();
}

function surovina_recompute_total(PDO $pdo, int $surovinaId): void {
    $pdo->prepare("UPDATE suroviny SET stock_aktualni = (
        SELECT COALESCE(SUM(stav),0) FROM sklad_polozky WHERE item_typ='surovina' AND item_id=:a
    ) WHERE id=:b")->execute(['a' => $surovinaId, 'b' => $surovinaId]);
}

// Idempotentní migrace A→B. Volá se 1× při načtení admin_suroviny (GET).
function sklad_unify_migrate(PDO $pdo): void {
    try { $pdo->exec("ALTER TABLE suroviny ADD COLUMN IF NOT EXISTS domovsky_sklad_id INT NULL"); } catch (Throwable $e) {}
    $def = sklad_default_id($pdo);
    if ($def <= 0) return; // žádný sklad → nic nemigruj
    $pdo->prepare("UPDATE suroviny SET domovsky_sklad_id=:d WHERE domovsky_sklad_id IS NULL OR domovsky_sklad_id=0")->execute(['d' => $def]);
    $rows = $pdo->query("SELECT id, COALESCE(stock_aktualni,0) AS s, COALESCE(domovsky_sklad_id,$def) AS dom FROM suroviny")->fetchAll();
    foreach ($rows as $r) {
        $sid = (int) $r['id'];
        $has = $pdo->prepare("SELECT COUNT(*) FROM sklad_polozky WHERE item_typ='surovina' AND item_id=:i");
        $has->execute(['i' => $sid]);
        if ((int) $has->fetchColumn() === 0) {
            // surovina nemá řádek v B → přenes A-zásobu do domovského skladu
            $pdo->prepare("INSERT INTO sklad_polozky (sklad_id,item_typ,item_id,stav) VALUES (:s,'surovina',:i,:st)")
                ->execute(['s' => (int) $r['dom'], 'i' => $sid, 'st' => (float) $r['s']]);
        }
        surovina_recompute_total($pdo, $sid);
    }
}

} // function_exists
```

- [ ] **Step 2: Napoj migraci** — v `api/admin_suroviny.php` přidej nahoru k ostatním `require_once`:

```php
require_once __DIR__ . '/_sklad_lib.php';
```
a v GET handleru (seznam surovin) **na začátek** přidej:
```php
sklad_unify_migrate($pdo); // idempotentní — 1× sjednotí A↔B
```

- [ ] **Step 3: Napiš smoke #S1** — do `tests/smoke.sh` před závěrečnou sekci (před `# Pozn.: behaviorální`):

```bash
sec "#S1 — sklad sjednocen: každá surovina má řádek v B + stock_aktualni=součet"
api GET admin_suroviny.php >/dev/null   # spustí migraci
aeq "#S1 sloupec domovsky_sklad_id existuje" "1" \
  "$(q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='suroviny' AND COLUMN_NAME='domovsky_sklad_id'")"
aeq "#S1 žádná surovina bez řádku v sklad_polozky" "0" \
  "$(q "SELECT COUNT(*) FROM suroviny s WHERE NOT EXISTS(SELECT 1 FROM sklad_polozky p WHERE p.item_typ='surovina' AND p.item_id=s.id)")"
aeq "#S1 stock_aktualni = SUM(sklad_polozky) u všech" "0" \
  "$(q "SELECT COUNT(*) FROM suroviny s WHERE ROUND(COALESCE(s.stock_aktualni,0),3) <> ROUND((SELECT COALESCE(SUM(stav),0) FROM sklad_polozky p WHERE p.item_typ='surovina' AND p.item_id=s.id),3)")"
```

- [ ] **Step 4: Spusť — musí selhat** (sloupec + řádky zatím nejsou):
Run: `bash scripts/sync-local.sh && bash tests/smoke.sh 2>&1 | grep '#S1'`
Expected: `#S1` checky FAIL (sloupec chybí / suroviny bez řádku).

- [ ] **Step 5: Sync + spusť — musí projít:**
Run: `bash scripts/sync-local.sh && bash tests/smoke.sh 2>&1 | grep -E '#S1|PASS|FAIL'`
Expected: #S1 3× PASS, celkový součet bez nových FAIL.

- [ ] **Step 6: Commit:**
```bash
git add api/_sklad_lib.php api/admin_suroviny.php tests/smoke.sh
git commit -m "feat(sklad): _sklad_lib + idempotentní migrace A→B (Task 1)"
```

---

## Task 2: Vytvoření suroviny založí řádek v systému B

**Files:**
- Modify: `api/admin_suroviny.php` (POST handler — po insertu suroviny)
- Test: `tests/smoke.sh` (#S2)

- [ ] **Step 1: Napiš smoke #S2** — do `tests/smoke.sh`:

```bash
sec "#S2 — vytvoření suroviny založí řádek v sklad_polozky (systém B)"
q "DELETE FROM sklad_polozky WHERE item_typ='surovina' AND item_id IN (SELECT id FROM suroviny WHERE nazev='SMOKE S2'); DELETE FROM suroviny WHERE nazev='SMOKE S2'" >/dev/null
S2R="$(api POST admin_suroviny.php '{"nazev":"SMOKE S2","jednotka":"kg","stock_aktualni":42}')"
S2ID="$(jval "$S2R" "['id']")"
[[ -z "$S2ID" ]] && S2ID="$(q "SELECT id FROM suroviny WHERE nazev='SMOKE S2' ORDER BY id DESC LIMIT 1")"
aeq "#S2 surovina má řádek v B s počátečním stavem 42" "42.000" \
  "$(q "SELECT IFNULL(SUM(stav),'NIC') FROM sklad_polozky WHERE item_typ='surovina' AND item_id=$S2ID")"
aeq "#S2 stock_aktualni = součet z B" "42.000" "$(q "SELECT stock_aktualni FROM suroviny WHERE id=$S2ID")"
q "DELETE FROM sklad_polozky WHERE item_typ='surovina' AND item_id=$S2ID; DELETE FROM suroviny WHERE id=$S2ID" >/dev/null
```

- [ ] **Step 2: Spusť — musí selhat** (B řádek se zatím nezakládá):
Run: `bash scripts/sync-local.sh && bash tests/smoke.sh 2>&1 | grep '#S2'`
Expected: `#S2 surovina má řádek v B` FAIL (NIC).

- [ ] **Step 3: Implementuj** — v `api/admin_suroviny.php` v POST handleru **za** `INSERT INTO suroviny` (kde už znáš nové `$id` z `lastInsertId()`), přidej:

```php
// 🆕 v3.0.168 — nová surovina rovnou do systému B (domovský sklad = default)
$home = sklad_default_id($pdo);
if ($home > 0) {
    $pdo->prepare("UPDATE suroviny SET domovsky_sklad_id=:d WHERE id=:id")->execute(['d' => $home, 'id' => $id]);
    $rowId = sklad_polozky_ensure($pdo, $home, 'surovina', $id);
    $pdo->prepare("UPDATE sklad_polozky SET stav=:st WHERE id=:r")->execute(['st' => (float) ($stockAktualni ?? 0), 'r' => $rowId]);
    surovina_recompute_total($pdo, $id);
}
```
> Pozn.: `$stockAktualni` = hodnota `stock_aktualni` z requestu, kterou handler už čte. Pokud se proměnná jmenuje jinak, použij existující název. Insert do `suroviny` ponech beze změny (stock_aktualni se přepočítá z B).

- [ ] **Step 4: Spusť — musí projít:**
Run: `bash scripts/sync-local.sh && bash tests/smoke.sh 2>&1 | grep '#S2'`
Expected: #S2 2× PASS.

- [ ] **Step 5: Commit:**
```bash
git add api/admin_suroviny.php tests/smoke.sh
git commit -m "feat(sklad): vytvoření suroviny zakládá řádek v B (Task 2)"
```

---

## Task 3: POS odpis odepisuje ze systému B (kritická cesta)

**Files:**
- Modify: `api/admin_pos.php` (require lib + funkce `pos_deduct_ingredients`, ~ř.276)
- Test: `tests/smoke.sh` (#S3)

- [ ] **Step 1: Napiš smoke #S3** — do `tests/smoke.sh` (využívá fixtury SUR_ID, VYR_ID, STUL_ID):

```bash
sec "#S3 — POS prodej odepisuje systém B (sklad_polozky domovského skladu)"
if [[ -n "$SUR_ID" && -n "$VYR_ID" && -n "$STUL_ID" ]]; then
  DEF=$(q "SELECT id FROM sklady WHERE COALESCE(aktivni,1)=1 ORDER BY id LIMIT 1")
  q "UPDATE suroviny SET domovsky_sklad_id=$DEF WHERE id=$SUR_ID;
     DELETE FROM sklad_polozky WHERE item_typ='surovina' AND item_id=$SUR_ID;
     INSERT INTO sklad_polozky (sklad_id,item_typ,item_id,stav) VALUES ($DEF,'surovina',$SUR_ID,1000);
     UPDATE suroviny SET stock_aktualni=1000 WHERE id=$SUR_ID" >/dev/null
  # recept: SMOKE Pizza používá 5 jednotek SUR_ID (z fixtur). Prodáme 1× walk-in.
  SB0=$(q "SELECT stav FROM sklad_polozky WHERE sklad_id=$DEF AND item_typ='surovina' AND item_id=$SUR_ID")
  BODY="{\"pos_typ\":\"s_sebou\",\"polozky\":[{\"vyrobek_id\":$VYR_ID,\"mnozstvi\":1,\"nazev\":\"SMOKE Pizza\",\"jednotkova_cena\":100,\"sazba_dph\":21}]}"
  http POST "admin_pos.php?action=quick_order" "$BODY" >/dev/null
  SB1=$(q "SELECT stav FROM sklad_polozky WHERE sklad_id=$DEF AND item_typ='surovina' AND item_id=$SUR_ID")
  aeq "#S3 sklad_polozky (B) domovského skladu ubyl o 5" "5.000" "$(python3 -c "print(f'{float('$SB0')-float('$SB1'):.3f}')" 2>/dev/null)"
  aeq "#S3 stock_aktualni = součet z B" "$SB1" "$(q "SELECT stock_aktualni FROM suroviny WHERE id=$SUR_ID")"
  aeq "#S3 sklad_pohyby_v2 má výdej řádek" "1" "$(q "SELECT COUNT(*) FROM sklad_pohyby_v2 WHERE item_typ='surovina' AND item_id=$SUR_ID AND typ='vydej' AND sklad_id=$DEF")"
else sk "#S3 POS odpis B" "chybí fixtury"; fi
```

- [ ] **Step 2: Spusť — musí selhat** (odpis jde zatím do A):
Run: `bash scripts/sync-local.sh && bash tests/smoke.sh 2>&1 | grep '#S3'`
Expected: `#S3 sklad_polozky (B) ubyl` FAIL (B se nezměnil).

- [ ] **Step 3: Implementuj** — v `api/admin_pos.php` přidej nahoru `require_once __DIR__ . '/_sklad_lib.php';` a přepiš tělo `pos_deduct_ingredients` (~ř.276). Stávající `UPDATE suroviny SET stock_aktualni -=` + `INSERT INTO sklad_pohyby` nahraď:

```php
function pos_deduct_ingredients(PDO $pdo, array $items, string $label, string $kdo = 'POS'): array {
    // 🐛 v3.0.168 — odpis ze systému B (sklad_polozky domovského skladu).
    $out = [];
    // agreguj spotřebu surovin přes recepturu
    $recStmt = $pdo->prepare("SELECT surovina_id, mnozstvi FROM vyrobek_suroviny WHERE vyrobek_id = :v");
    $need = []; // surovina_id => mnozstvi
    foreach ($items as $it) {
        $vid = (int) ($it['vyrobek_id'] ?? 0);
        $mn  = (float) ($it['mnozstvi'] ?? 1);
        if ($vid <= 0) continue;
        $recStmt->execute(['v' => $vid]);
        foreach ($recStmt->fetchAll() as $r) {
            $sid = (int) $r['surovina_id'];
            $need[$sid] = ($need[$sid] ?? 0) + (float) $r['mnozstvi'] * $mn;
        }
    }
    foreach ($need as $sid => $mn) {
        if ($mn <= 0) continue;
        $home  = surovina_home_sklad($pdo, $sid);
        $rowId = sklad_polozky_ensure($pdo, $home, 'surovina', $sid);
        // zamkni řádek, odečti
        $lock = $pdo->prepare("SELECT stav FROM sklad_polozky WHERE id=:r FOR UPDATE");
        $lock->execute(['r' => $rowId]);
        $pred = (float) $lock->fetchColumn();
        $po   = $pred - $mn;
        $pdo->prepare("UPDATE sklad_polozky SET stav=:po WHERE id=:r")->execute(['po' => $po, 'r' => $rowId]);
        $pdo->prepare("INSERT INTO sklad_pohyby_v2 (sklad_id,item_typ,item_id,typ,mnozstvi,stav_pred,stav_po,poznamka,kdo,kdy)
                       VALUES (:s,'surovina',:i,'vydej',:mn,:pr,:po,:pz,:kdo,NOW())")
            ->execute(['s' => $home, 'i' => $sid, 'mn' => $mn, 'pr' => $pred, 'po' => $po, 'pz' => $label, 'kdo' => $kdo]);
        surovina_recompute_total($pdo, $sid);
        $out[] = ['surovina_id' => $sid, 'odepsano' => $mn, 'stav_po' => $po];
    }
    return $out;
}
```
> Volající (`action=pay` ř.554, `action=quick_order` ř.1263) zůstávají — volají stejnou signaturu. Funkce běží uvnitř existující transakce volajícího.

- [ ] **Step 4: Spusť — musí projít:**
Run: `bash scripts/sync-local.sh && bash tests/smoke.sh 2>&1 | grep -E '#S3|#11'`
Expected: #S3 3× PASS. (Pozn.: starý #11 testoval odpis do A/`sklad_pohyby` — uprav ho v tomto kroku, aby ověřoval B místo A, nebo ho nahraď #S3.)

- [ ] **Step 5: Commit:**
```bash
git add api/admin_pos.php tests/smoke.sh
git commit -m "feat(sklad): POS odpis ze systému B + sklad_pohyby_v2 (Task 3)"
```

---

## Task 4: Přepočet cache po ostatních zápisech do B

**Files:**
- Modify: `api/admin_sklad_pohyby.php`, `api/admin_sklad_polozky.php`, `api/admin_vyroba.php`
- Test: `tests/smoke.sh` (#S4)

- [ ] **Step 1: Napiš smoke #S4** — ověř, že příjem/inventura v B přepočítá stock_aktualni:

```bash
sec "#S4 — změna sklad_polozky přepočítá suroviny.stock_aktualni"
if [[ -n "$SUR_ID" ]]; then
  DEF=$(q "SELECT id FROM sklady WHERE COALESCE(aktivni,1)=1 ORDER BY id LIMIT 1")
  BODY="{\"sklad_id\":$DEF,\"item_typ\":\"surovina\",\"item_id\":$SUR_ID,\"novy_stav\":123}"
  http POST "admin_sklad_pohyby.php?action=inventura" "$BODY" >/dev/null
  aeq "#S4 stock_aktualni = součet z B po inventuře" \
    "$(q "SELECT COALESCE(SUM(stav),0) FROM sklad_polozky WHERE item_typ='surovina' AND item_id=$SUR_ID")" \
    "$(q "SELECT ROUND(stock_aktualni,3) FROM suroviny WHERE id=$SUR_ID")"
else sk "#S4 přepočet" "chybí surovina"; fi
```

- [ ] **Step 2: Spusť — musí selhat** (přepočet zatím chybí):
Run: `bash scripts/sync-local.sh && bash tests/smoke.sh 2>&1 | grep '#S4'`
Expected: FAIL (stock_aktualni nesedí se součtem B).

- [ ] **Step 3: Implementuj** — v `api/admin_sklad_pohyby.php`, `admin_sklad_polozky.php`, `admin_vyroba.php`:
require lib (`require_once __DIR__ . '/_sklad_lib.php';`) a po každém `UPDATE/INSERT sklad_polozky … item_typ='surovina'` zavolej `surovina_recompute_total($pdo, $itemId);` (kde `$itemId` je dotčená surovina). U inventura/korekce/presun/prijem v admin_sklad_pohyby.php přidej hned po commitu/úpravě stavu řádku.

- [ ] **Step 4: Spusť — musí projít:**
Run: `bash scripts/sync-local.sh && bash tests/smoke.sh 2>&1 | grep -E '#S4|#I'`
Expected: #S4 PASS, #I (sklad inventura) stále PASS.

- [ ] **Step 5: Commit:**
```bash
git add api/admin_sklad_pohyby.php api/admin_sklad_polozky.php api/admin_vyroba.php tests/smoke.sh
git commit -m "feat(sklad): přepočet stock_aktualni po zápisech do B (Task 4)"
```

---

## Task 5: Historie suroviny z v2 + picker domovského skladu

**Files:**
- Modify: `api/admin_suroviny.php` (GET detail/historie → `sklad_pohyby_v2`)
- Modify: `admin/admin.js` (editor suroviny — select „Domovský sklad")
- Test: `tests/smoke.sh` (#S5) + vizuální ověření (screenshot)

- [ ] **Step 1: Napiš smoke #S5** — ověř, že detail suroviny vrací historii z v2 + sklady pro picker:

```bash
sec "#S5 — detail suroviny: historie z v2 + sklady pro picker"
if [[ -n "$SUR_ID" ]]; then
  D5="$(api GET "admin_suroviny.php?id=$SUR_ID")"
  acont "#S5 detail vrací 'sklady' (pro picker)" "sklad" "$D5"
  acont "#S5 detail vrací 'domovsky_sklad_id'" "domovsky_sklad_id" "$D5"
else sk "#S5 detail" "chybí surovina"; fi
```

- [ ] **Step 2: Spusť — musí selhat:**
Run: `bash scripts/sync-local.sh && bash tests/smoke.sh 2>&1 | grep '#S5'`
Expected: FAIL (detail nevrací sklady/domovsky_sklad_id).

- [ ] **Step 3: Implementuj backend** — v `api/admin_suroviny.php` GET detailu:
historii pohybů čti z `sklad_pohyby_v2 WHERE item_typ='surovina' AND item_id=:id ORDER BY kdy DESC` (místo staré `sklad_pohyby`); přidej do odpovědi `sklady` = `SELECT id,nazev FROM sklady WHERE COALESCE(aktivni,1)=1 ORDER BY id` a `domovsky_sklad_id` suroviny.

- [ ] **Step 4: Implementuj UI** — v `admin/admin.js` v editoru suroviny přidej (vedle ostatních polí) select:
```js
`<label class="form-label">Domovský sklad</label>
 <select class="form-select" id="su-home-sklad">
   ${(data.sklady||[]).map(s=>`<option value="${s.id}" ${data.domovsky_sklad_id==s.id?'selected':''}>${esc(s.nazev)}</option>`).join('')}
 </select>`
```
a do save-body suroviny přidej `domovsky_sklad_id: parseInt(document.getElementById('su-home-sklad')?.value)||null`. V backend PUT/POST ulož `domovsky_sklad_id` (validuj že sklad existuje).

- [ ] **Step 5: Spusť + vizuální ověření:**
Run: `bash scripts/sync-local.sh && bash tests/smoke.sh 2>&1 | grep '#S5'` → PASS.
Vizuálně: Preview MCP screenshot editoru suroviny — picker „Domovský sklad" je vidět a uložení funguje.

- [ ] **Step 6: Commit:**
```bash
git add api/admin_suroviny.php admin/admin.js tests/smoke.sh
git commit -m "feat(sklad): historie suroviny z v2 + picker domovského skladu (Task 5)"
```

---

## Task 6: Plná regrese + deploy + ověření na demu

**Files:** `api/config.php` (verze přes build), `tests/smoke.sh`

- [ ] **Step 1: Plný smoke zelený:**
Run: `bash scripts/sync-local.sh && bash tests/smoke.sh`
Expected: `✓ VŠE ZELENÉ`, 0 FAIL (včetně #S1–#S5 a původních #D/E/F/G/H/I/J/K).

- [ ] **Step 2: E2E reprodukce z této session** — krátký Python skript (jako `/tmp/e2e_full.py` v session): 22 výrobků → objednávka → kuchyně → platba → ověř že **sklad_polozky domovského skladu ubyl** a obrazovka Sklad (admin_sklad_pohyby) prodej ukazuje. Musí být ✅ tam, kde minule byl DISCONNECT.

- [ ] **Step 3: Build + commit verze:**
```bash
bash scripts/build-update.sh 3.0.168   # sync verzí + php-lint + zip
git add -A && git commit -m "release: sjednocení skladu v3.0.168"
```

- [ ] **Step 4: Push + tag:**
```bash
git push origin main && git tag -a v3.0.168 -m "sjednocení skladu" && git push origin v3.0.168
```

- [ ] **Step 5: Vendor publish + demo apply** (ruční flow z memory `appek-deploy-distribution.md`):
login vendor → upload `appek-update-3.0.168.zip` → najdi id → publish → ověř feed → demo: login → version_check → updates_apply → ověř `version.php`=3.0.168.

- [ ] **Step 6: Ověř na demu:** prodej v POS → obrazovka Sklad ukazuje úbytek. `admin_sklad_pohyby.php` má výdej z domovského skladu.

---

## Migrace — poznámka pro provoz

Po nasazení se `stock_aktualni` zarovná na `SUM(sklad_polozky)` (B = pravda). U surovin, které **už měly** řádky v B, se může `stock_aktualni` posunout na hodnotu z B. **Doporuč zákazníkovi udělat po upgradu inventuru**, aby B odpovídal fyzickému stavu. Suroviny bez řádku v B dostanou svoji A-zásobu do domovského skladu (beze změny hodnoty).

## Self-Review (pokrytí specu)

- DB sloupec `domovsky_sklad_id` → Task 1 ✓
- `_sklad_lib.php` (recompute, ensure, home, default, migrace) → Task 1 ✓
- POS odpis ze systému B → Task 3 ✓
- Vytvoření suroviny zakládá B řádek → Task 2 ✓
- Přepočet po ostatních zápisech → Task 4 ✓
- Historie z v2 + picker → Task 5 ✓
- Migrace idempotentní → Task 1 ✓
- Smoke guard (e2e) → Task 3 + Task 6 ✓
- Deploy → Task 6 ✓
- Rizika (transakce, FOR UPDATE, lint) → Task 3 (FOR UPDATE), Task 6 (build php-lint) ✓
