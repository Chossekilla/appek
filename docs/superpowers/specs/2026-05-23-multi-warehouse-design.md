# Multi-warehouse (Sklady) — Design

**Datum:** 2026-05-23
**Status:** Draft pending review
**Balíček:** Core (zahrnuto ve všech licencích)

## Cíl

Přidat do APPEK B2B správu více skladů (např. suchý, lednice, mrazák) jako prvotřídní entitu. Suroviny i výrobky půjde řadit do skladů, evidovat stav per (sklad × položka), provádět příjem/výdej/inventuru/přesun.

Aktuálně systém má jeden globální sklad reprezentovaný sloupci `suroviny.sklad_stav` a `vyrobky.sklad_stav`. Pohyby existují pouze pro suroviny (`sklad_pohyby` tabulka).

## Rozsah MVP

Vše do prvního release:

1. CRUD sklady jako entita
2. Přiřazení suroviny/výrobku do (více) skladů se samostatným stavem
3. Přesuny mezi sklady jako nový typ pohybu
4. Filtrování seznamů surovin/výrobků podle skladu

Mimo rozsah (do dalších verzí):
- Multi-tenant per pobočka (zatím všechny sklady patří k jedné firmě)
- Skladová ocenění FIFO/průměr (zatím jen mnozstvi)
- Rezervace / blokované zásoby
- Periodická inventura s template

## Datový model

### Nová tabulka `sklady`

```sql
CREATE TABLE sklady (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kod VARCHAR(20) UNIQUE NOT NULL,        -- 'SK01', 'SK02', editovatelný, auto-generovaný
    nazev VARCHAR(100) NOT NULL,
    typ ENUM('suchy','lednice','mrazak','jiny') DEFAULT 'jiny',
    teplota_min DECIMAL(4,1) NULL,
    teplota_max DECIMAL(4,1) NULL,
    adresa VARCHAR(255) NULL,
    aktivni TINYINT(1) NOT NULL DEFAULT 1,
    vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB CHARSET=utf8mb4;
```

Číslování: prefix `SK`, 2-digit pad výchozí (`SK01`–`SK99`), nad 99 se přirozeně rozšíří (`SK100`). Lazy creation defaultního skladu `SK01 — Hlavní sklad` (typ `jiny`) při první migraci.

### Nová tabulka `sklad_polozky` (pivot)

```sql
CREATE TABLE sklad_polozky (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sklad_id INT NOT NULL,
    item_typ ENUM('surovina','vyrobek') NOT NULL,
    item_id INT NOT NULL,
    stav DECIMAL(12,3) NOT NULL DEFAULT 0,
    min DECIMAL(12,3) NULL,
    cil DECIMAL(12,3) NULL,
    vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP,
    upraveno DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (sklad_id) REFERENCES sklady(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_sklad_item (sklad_id, item_typ, item_id),
    INDEX idx_item (item_typ, item_id)
) ENGINE=InnoDB CHARSET=utf8mb4;
```

`item_id` je deliberátní bez FK — ENUM `item_typ` ukazuje na různé tabulky (`suroviny.id` / `vyrobky.id`). Konzistenci hlídá aplikace, ne DB. Cena tohoto rozhodnutí: ON DELETE neřeší DB, ale endpoint smazání suroviny/výrobku musí čistit `sklad_polozky` (přidat do existující delete cesty).

### Rozšíření `sklad_pohyby`

```sql
ALTER TABLE sklad_pohyby
    ADD COLUMN sklad_id INT NULL AFTER id,         -- kde se pohyb odehrál (NULL = legacy SK01)
    ADD COLUMN sklad_id_cil INT NULL AFTER sklad_id, -- pro typ='presun': cílový sklad
    ADD COLUMN vyrobek_id INT NULL AFTER surovina_id, -- mimo suroviny i pro výrobky
    MODIFY surovina_id INT NULL,                    -- už ne NOT NULL
    MODIFY typ ENUM('prijem','vydej','inventura','korekce','presun'),
    ADD INDEX idx_sklad (sklad_id),
    ADD INDEX idx_pohyb_vyrobek (vyrobek_id);
```

Logický constraint: `surovina_id` nebo `vyrobek_id` musí být vyplněn, ne oba, ne ani jeden. Hlídá aplikace.

### `suroviny` a `vyrobky`

Existující sloupce `sklad_stav`, `sklad_min`, `sklad_cil` zachovat. Po migraci budou počítané jako suma přes `sklad_polozky` — endpointy je vrátí jako agregaci, ne přímo z tabulky. Sloupce zůstanou pro zpětnou kompatibilitu (například: starý reporting odvolávající se přímo na `suroviny.sklad_stav` zatím funguje, hodnota je suma).

### Migrace existujících dat

1. CREATE TABLE `sklady` (lazy DDL v `_schema_lib.php`)
2. INSERT IGNORE `sklady (kod='SK01', nazev='Hlavní sklad', typ='jiny', aktivni=1)`
3. CREATE TABLE `sklad_polozky` (lazy DDL)
4. INSERT IGNORE `sklad_polozky (sklad_id=1, item_typ='surovina', item_id=s.id, stav=COALESCE(s.sklad_stav, 0), min=s.sklad_min, cil=s.sklad_cil)` ze **všech** záznamů v `suroviny` (i s NULL stavem → 0). UNIQUE KEY zaručí, že opakovaný běh je no-op.
5. Totéž pro `vyrobky` (item_typ='vyrobek')
6. ALTER `sklad_pohyby` (lazy DDL)
7. UPDATE `sklad_pohyby SET sklad_id = 1 WHERE sklad_id IS NULL` (přiřadit legacy pohyby do SK01)

Důsledek: po PR2 je každá existující surovina i výrobek minimálně v SK01 (stav 0 pokud byl NULL). Žádná položka není mimo systém skladů.

Vše idempotentní v `ensure_sklady_schema()` funkci. Spustí se při prvním requestu na sklady endpoint.

## Endpointy

### `api/admin_sklady.php` — CRUD sklady

| Method | Path | Akce |
|---|---|---|
| GET | `/api/admin_sklady.php` | Seznam skladů (s počty: pocet_polozek, pocet_surovin, pocet_vyrobku) |
| GET | `/api/admin_sklady.php?id=N` | Detail skladu + položky (suroviny+výrobky se stavem) |
| POST | `/api/admin_sklady.php` | Vytvořit. Auto-generuje `kod` (`SK` + první volné dvouciferné číslo). Body: `{nazev, typ?, teplota_min?, teplota_max?, adresa?}`. |
| PUT | `/api/admin_sklady.php?id=N` | Update všech atributů. |
| DELETE | `/api/admin_sklady.php?id=N` | Smaže pokud nemá žádný pohyb ani aktivní `sklad_polozky` se stavem >0. Jinak vrátí 409 s nabídkou soft-delete (`aktivni=0`). |

Auth: `require_admin` na všech. DELETE pak `require_super_admin`.

### `api/admin_sklad_polozky.php` — přiřazení a min/cíl

| Method | Path | Akce |
|---|---|---|
| GET | `?sklad_id=N` | Seznam položek v skladu (s názvy ze suroviny/vyrobky) |
| GET | `?item_typ=&item_id=` | Kde všude položka je (po skladech, suma) |
| POST | — | Přiřadit položku do skladu (stav 0). Body: `{sklad_id, item_typ, item_id, min?, cil?}`. UNIQUE KEY zabrání duplikátu — chyba 409. |
| PUT | `?id=N` | Změnit `min`/`cil` (stav se mění výlučně přes pohyby — read-only zde). |
| DELETE | `?id=N` | Odebrat položku ze skladu. Vyžaduje stav=0; jinak 409. |

### `api/admin_sklad_pohyby.php` — pohyby + přesuny

Nová samostatná endpointa (refactor existující `admin_suroviny.php` action `pohyb` sem). Atomická.

| Method | Path | Akce |
|---|---|---|
| GET | `?sklad_id=` `&item_typ=` `&item_id=` `&typ=` `&limit=` | Historie pohybů s filtry. |
| POST | `?action=prijem` | Body: `{sklad_id, item_typ, item_id, mnozstvi, cena_za_jed?, poznamka?}`. INSERT pohyb + UPDATE sklad_polozky.stav += mnozstvi. |
| POST | `?action=vydej` | Totéž ale `stav -= mnozstvi`. Pokud `stav - mnozstvi < 0` → 409 (nelze do záporu). |
| POST | `?action=inventura` | Body: `{sklad_id, item_typ, item_id, novy_stav, poznamka?}`. INSERT pohyb s `mnozstvi = novy_stav - stary_stav` + UPDATE stavu. |
| POST | `?action=presun` | Body: `{sklad_id_z, sklad_id_do, item_typ, item_id, mnozstvi, poznamka?}`. Validace `sklad_id_z != sklad_id_do`. V 1 transakci: vydej z A, příjem do B, oba pohyby s `typ='presun'` a křížově vyplněnými `sklad_id`/`sklad_id_cil`. |

CSRF: všechny POST přes `_admin_auth.php` (které ho zařídí).

### Změny existujících endpointů

- `admin_suroviny.php` GET seznam: stav počítá `SELECT SUM(stav) FROM sklad_polozky WHERE item_typ='surovina' AND item_id = s.id` (zachovat zpětně-kompatibilní formát). Filter `?sklad_id=N` — vrátí jen suroviny v daném skladu.
- `admin_vyrobky.php` totéž.
- DELETE suroviny/výrobku: před `DELETE FROM suroviny WHERE id = …` spustit `DELETE FROM sklad_polozky WHERE item_typ='surovina' AND item_id = …` (kvůli absenci FK).

## UI

### Umístění

Top-level **🥖 Výroba** dostane sub-taby (které dnes nemá):
- Výrobní list (existující obsah)
- 🏭 **Sklady** (nové)

Suroviny zatím zůstávají jako vlastní top-level item (nepřesouvat) — kdyby přesun mezi sekcemi rozbil bookmarky/cestu, je to bolestivá UX změna. Pokud se po pár týdnech ukáže, že Sklady + Suroviny patří k sobě, přesun lze udělat v dalším PR.

### Obrazovka Sklady — seznam

Grid karet (na desktopu 2-3 sloupce, na mobilu 1). Karta:

```
┌─────────────────────────┐
│ 📦 SK01 Hlavní suchý    │
│ Typ: suchý sklad        │
│ 12 surovin · 4 výrobky  │
│ Teplota: 15–22 °C       │
│ ⚠️ 2 položky pod min   │
│ [Detail] [Edit] [Pohyby]│
└─────────────────────────┘
```

Toolbar: `[+ Nový sklad]` `[Hromadný přesun]` `[Filtr typu ▼]` `[Zobrazit neaktivní ☐]`.

### Obrazovka Sklady — detail jednoho

Tabulka položek v daném skladu:

| Kód | Název | Typ | Stav | Min | Cíl | Akce |
|---|---|---|---|---|---|---|
| MOU01 | Mouka hladká | surovina | 25 kg | 5 | 50 | [+] [-] [↔] [📊] |
| PEC02 | Pečivo | výrobek | 0 ks | 10 | — | ⚠️ pod min |

Tlačítka per řádek:
- `[+]` Příjem (modal: množství, cena/jed, poznámka)
- `[-]` Výdej (modal: množství, poznámka)
- `[↔]` Přesun (modal: cílový sklad, množství, poznámka)
- `[📊]` Pohyby (otevře filtrovaný log)

Pod tabulkou: `[+ Přiřadit položku]` — modal vybere surovinu/výrobek a min/cíl.

### Edit suroviny / výrobku (změny v existujícím UI)

Místo jednoho pole `sklad_stav` se objeví sekce **Sklady** s tabulkou:

| Sklad | Stav | Min | Cíl | Akce |
|---|---|---|---|---|
| SK01 Hlavní suchý | 25 kg | 5 | 50 | [Edit min/cíl] [Odebrat] |
| LE01 Hlavní lednice | 3 kg | 0 | — | [Edit] [Odebrat] |

Pod ní: `[+ Přiřadit do skladu]` (dropdown skladů, které ještě nemá).

Stavy se NEMĚNÍ tady (read-only) — explicitně přes pohyby (akce v detailu skladu).

### Seznam surovin/výrobků (existující obrazovky)

Dva drobné přidatky:

1. Sloupec **Sklad** ukazuje badge pro každý sklad s nenulovým stavem: `SK01: 25 · LE01: 3`. Klik na badge filtruje seznam podle skladu.
2. Filter dropdown nahoře: `Všechny sklady ▼` → výběr konkrétního skladu omezí seznam (i ke skladu nepřiřazené položky).

### Mobile

- Karty skladů: 1 sloupec.
- Detail skladu: tabulka položek se zalomí (Kód + Stav → 1 řádek, Min/Cíl → 2. řádek).
- Modaly nahradit bottom-sheet komponentou (existující v admin.css).

## Fázování (PR plán)

1. **PR1 — Sklady jako entita**
   - DB migrace: `sklady`, `sklad_polozky` tabulky, defaultní SK01
   - `admin_sklady.php` CRUD
   - Sub-tab Sklady v UI s kartami a edit modálem
   - Žádné položky, žádné pohyby — jen sklady jako entita
   - Cíl: user vidí v UI sklady, může je vytvářet/editovat

2. **PR2 — Položky v skladech**
   - `admin_sklad_polozky.php`
   - Migrace existujících `sklad_stav` z suroviny+vyrobky do `sklad_polozky` (do SK01)
   - Sekce "Sklady" v editu suroviny/výrobku
   - Detail skladu se seznamem položek (read-only stav)
   - Cíl: každá surovina/výrobek je přiřazena alespoň do SK01

3. **PR3 — Pohyby**
   - `admin_sklad_pohyby.php` (refactor + výrobky)
   - Tlačítka příjem/výdej/inventura per řádek
   - Historie pohybů per sklad
   - Cíl: user mění stav přes pohyby, ne přímou editací

4. **PR4 — Přesuny + filtrování**
   - Action `presun` v pohybech
   - Bulk přesun (vybrat položky, cíl, množství)
   - Filter dropdown ve seznamech surovin/výrobků
   - Cíl: feature kompletní

Každé PR samostatně deployovatelné, předchozí stav nepoškodí.

## Validace a chyby

- `kod` regex `^SK[0-9]{2,}$`, 3-50 znaků (zatím)
- `teplota_min ≤ teplota_max` (pokud oba)
- `presun`: `sklad_id_z != sklad_id_do`, `mnozstvi > 0`
- `vydej`/`presun`: výsledný `stav < 0` → HTTP 409 + `{error: "Nedostatek na skladu", available: X, requested: Y}`
- DELETE skladu s pohyby → HTTP 409 + `{error: "Sklad má historii pohybů — nelze smazat. Lze deaktivovat (aktivni=0)."}`
- DELETE položky ze skladu se stavem > 0 → HTTP 409
- Soft delete fallback: PUT `aktivni=0` všude akceptovaný.

## Testování

- Unit (kdyby projekt měl test suite — nemá): teď manuálně přes curl
- E2E smoke per PR:
  - PR1: vytvořit sklad SK02, edit, smazat, recreate, ověřit kod=SK02 zase volný
  - PR2: po migraci ověřit že každá surovina je v SK01 se správným stavem
  - PR3: prijem +10 → stav vzroste o 10, vydej -3 → stav klesne. Pokus o vydej -100 vrátí 409
  - PR4: přesun 5 z SK01 do SK02 → SK01 stav -5, SK02 stav +5, 2 záznamy pohyby
- Manuální v admin UI: vytvořit, edit, smazat sklad; přiřadit surovinu; provést všechny 4 typy pohybů
- Migrace na produkční DB s reálnými daty (after lazy DDL spuštění): porovnat `SUM(sklad_polozky.stav) per surovina` se starým `suroviny.sklad_stav` — musí sedět

## Rizika a otevřené otázky

**Rizika:**
1. **Žádné FK na `sklad_polozky.item_id`** — aplikace musí čistit při delete. Riziko: dangling rows pokud někdo přidá nový smazací path bez čistícího kódu. Mitigace: cron sanity-check jednou denně, který tyto rows vymaže.
2. **Migrace existujících dat** — pokud surovina měla `sklad_stav=0`, není přesunuta (INSERT IGNORE skip). To OK — položka se objeví v editu suroviny jako "není v žádném skladu".
3. **`suroviny.sklad_stav` jako agregace vs sloupec** — pokud někdo někde dělá UPDATE `suroviny.sklad_stav = X`, ten se ztratí (agregace ho přepíše). Audit: `grep -r "sklad_stav.*=" api/` před PR2 nasazením. Pokud najdeme legacy UPDATE, opravíme na pohyb.

**Otevřené otázky (k vyřešení po PR1):**
1. Má sklad mít `pobočka_id` (multi-pobočka firma)? Zatím ne, ale architektura to umožní (nový sloupec lazy).
2. Má `cenova_skupina` ovlivnit dostupnost položek z konkrétního skladu? Zatím ne.
3. Má se reservace zboží (objednávka rezervuje stav v konkrétním skladu) řešit teď, nebo v dalším verzi? Nyní v rozsah ne — pohyb proběhne až při vystavení DL.

## Implementační poznámky

- `_schema_lib.php` rozšířit o `ensure_sklady_schema(PDO)` se 3 lazy DDL bloky + INSERT IGNORE defaultního SK01 + migrace stavu.
- `dalsi_cislo()` pro typ='SK' bude potřebovat speciální handling (bez roku, 2-digit padding zatím — nebo přidat parametr `$pad`). Alternativa: vlastní helper `dalsi_kod_skladu(PDO): string`.
- Frontend: `admin/admin.js` přidá sekci `renderSklady()` a v `renderVyroba()` přidá sub-taby. Stylování přes existující `nastaveni-tab` třídy.
- Code generation: žádné, vše vanilla PHP + vanilla JS.
- Deploy: standardně přes `./scripts/release.sh X.Y.Z`, žádné speciální kroky.

## Definition of done (per PR)

- PR1: vytvořit/edit/smazat sklad v UI funguje, DB má tabulku `sklady` s defaultním SK01.
- PR2: každá surovina/výrobek je viditelně v alespoň 1 skladu, edit dialog ukazuje sekci Sklady, agregace stavu funguje.
- PR3: všechny 4 typy pohybů (kromě přesun) v UI fungují, historie pohybů per sklad zobrazuje data.
- PR4: přesun mezi sklady funguje atomicky, filter dropdown ve seznamech funguje.

Po PR4: feature je v Core a publikuje se v release notes jako headline.
