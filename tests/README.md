# APPEK — testy

## `smoke.sh` — regresní smoke test

Jeden příkaz, který ověří, že chyby nalezené během E2E testovacího bloku
(v3.0.13x–145) zůstaly opravené, a že klíčové toky fungují. Určeno jako
**brána před deployem**.

```bash
tests/smoke.sh
```

Exit kód: `0` = vše PASS (bezpečné deployovat), `1` = aspoň jeden FAIL.

Doporučený workflow:

```bash
tests/smoke.sh && ./build-zip.sh X.Y.Z
```

(Pokud smoke spadne, build se nespustí.)

### Co testuje (21 kontrol)

| Skupina | Kontrola |
|---|---|
| Preflight | DB spojení, login, autentizované GETy (200) |
| Schema | #2 `faktura_polozky.vyrobek_cislo`, #5 `faktury.misto_dodani_id` |
| Code-presence | #9 datum_objednani v aggregator INSERTu, #13 POS-číslo retry, #14 deadlock retry |
| Behavioral | #4 QR objednávka — cena/název serverově (host nesmí podhodnotit účet), #7 alergen hořčice u „hořčičná", #11 odpis surovin při POS prodeji (+ sklad_pohyby), #12 položky → servirovano po platbě, #8 vlastní kurýr → objednávka dorucena, #1 B2B objednávka + datum_objednani, #2 faktura detail 200, #3 položky faktury (DL fallback), #6 datum_uhrazeni při plné úhradě |

### Předpoklady

- Lokální Apache + MySQL (XAMPP), DB `appek` naplněná (`localhost/appek`).
- Test si založí vlastní dedikovaný admin účet `smoke@appek.cz` (neruší demo účty)
  a používá hermetické fixtury s prefixem `SMOKE ` (před každým během se přemažou).
- **Produkce (demo.appek.cz) NENÍ dotčena** — vše běží jen lokálně.

### Konfigurace (ENV, volitelné)

| Proměnná | Default |
|---|---|
| `APPEK_BASE` | `http://localhost/appek/api` |
| `APPEK_DB` | `appek` |
| `APPEK_MYSQL` | `/Applications/XAMPP/xamppfiles/bin/mysql` |
| `APPEK_PHP` | `/Applications/XAMPP/xamppfiles/bin/php` |

### Záměrně mimo rozsah

Behaviorální souběh (#13 POS-číslo race, #14 B2B deadlock) se ve smoke
netestuje — PHP zamyká session soubor (žádný `session_write_close`), takže
sdílená session by paralelní requesty serializovala; multi-login zase naráží
na rate-limit. Smoke proto hlídá souběhové fixy staticky (code-presence);
behaviorální souběh patří do dedikovaného zátěžového testu.
