#!/usr/bin/env bash
# ════════════════════════════════════════════════════════════════════════════
#  APPEK — REGRESNÍ SMOKE TEST
#  Jeden příkaz, který ověří, že chyby nalezené během E2E testů zůstaly opravené
#  + že klíčové toky (prodej, faktura, kuchyně, kurýr, odpis skladu) fungují.
#
#  Spouští se proti LOKÁLNÍMU Apache (localhost/appek, DB 'appek').
#  Produkce (demo.appek.cz) NENÍ dotčena.
#
#  Použití:
#     tests/smoke.sh
#
#  Exit kód: 0 = vše PASS (bezpečné deployovat), 1 = aspoň jeden FAIL.
#
#  Návrh: rychlý + DETERMINISTICKÝ (běží před každým deployem). Souběhové fixy
#  (#13/#14) se ověřují staticky (code-presence) — behaviorální souběh je
#  časově/strojově závislý a patří do dedikovaného zátěžového testu, ne do smoke.
#
#  Pokrývá nálezy z testovacího bloku (viz commity v3.0.13x–145):
#     #1  order create vkládá datum_objednani        (behavioral + code)
#     #2  faktura detail neselže (vyrobek_cislo)      (schema + behavioral)
#     #3  faktura z objednávky ukáže položky (DL fb)  (behavioral)
#     #5  ruční faktura — misto_dodani_id sloupec     (schema)
#     #6  datum_uhrazeni se nastaví při plné úhradě   (behavioral)
#     #7  alergen hořčice u "hořčičná"                (behavioral)
#     #8  vlastní kurýr doruceno → objednávka dorucena(behavioral)
#     #9  aggregator inbound vkládá datum_objednani   (code-presence)
#     #11 POS prodej odepisuje suroviny ze skladu     (behavioral)
#     #12 nedovařené položky → servirovano po platbě  (behavioral)
#     #13 POS číslo — retry na duplicitu              (code-presence)
#     #14 B2B — retry na deadlock                     (code-presence)
# ════════════════════════════════════════════════════════════════════════════
set -uo pipefail

# ── konfigurace (lze přepsat přes ENV) ──────────────────────────────────────
BASE="${APPEK_BASE:-http://localhost/appek/api}"
DB="${APPEK_DB:-appek}"
MYSQL="${APPEK_MYSQL:-/Applications/XAMPP/xamppfiles/bin/mysql}"
PHP_BIN="${APPEK_PHP:-/Applications/XAMPP/xamppfiles/bin/php}"
SMOKE_EMAIL="${APPEK_SMOKE_EMAIL:-smoke@appek.cz}"
SMOKE_PASS="${APPEK_SMOKE_PASS:-Smoke!2026}"
UA="appek-smoke"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../api" && pwd)"   # zdrojové PHP (pro code-grep)
JAR="$(mktemp -t appek_smoke_jar.XXXXXX)"

trap 'rm -f "$JAR"' EXIT

# ── výstup + počítadla ──────────────────────────────────────────────────────
PASS=0; FAIL=0; SKIP=0; declare -a FAILED=()
c_g(){ printf '\033[32m%s\033[0m' "$*"; }
c_r(){ printf '\033[31m%s\033[0m' "$*"; }
c_y(){ printf '\033[33m%s\033[0m' "$*"; }
ok(){   PASS=$((PASS+1)); printf '  %s  %s\n' "$(c_g '✓ PASS')" "$1"; }
no(){   FAIL=$((FAIL+1)); FAILED+=("$1"); printf '  %s  %s%s\n' "$(c_r '✗ FAIL')" "$1" "${2:+ — $2}"; }
sk(){   SKIP=$((SKIP+1)); printf '  %s  %s%s\n' "$(c_y '∅ SKIP')" "$1" "${2:+ — $2}"; }
sec(){  printf '\n\033[1m── %s ──\033[0m\n' "$1"; }
# assert_eq NAME EXPECTED ACTUAL
aeq(){ if [[ "$2" == "$3" ]]; then ok "$1"; else no "$1" "čekal '$2', dostal '$3'"; fi; }
# assert_contains NAME NEEDLE HAYSTACK
acont(){ if [[ "$3" == *"$2"* ]]; then ok "$1"; else no "$1" "'$2' nenalezeno v '${3:0:80}'"; fi; }

# ── DB helper ───────────────────────────────────────────────────────────────
q(){ "$MYSQL" -u root "$DB" -N -e "$1" 2>/dev/null; }

# ── JSON helper (python3) ─────────────────────────────────────────────────────
# jval '<json>' 'expr'  → vyhodnotí d<expr>, např. jval "$R" "['id']"
jval(){ printf '%s' "$1" | python3 -c "import json,sys
try:
 d=json.load(sys.stdin)
 print(eval('d'+sys.argv[1]))
except Exception:
 print('')" "$2" 2>/dev/null; }

# ── API helpery ───────────────────────────────────────────────────────────────
CSRF=""
api(){ # METHOD PATH [BODY]
  local m="$1" p="$2" b="${3:-}"
  if [[ -n "$b" ]]; then
    curl -s -c "$JAR" -b "$JAR" -A "$UA" -X "$m" "$BASE/$p" \
      -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" -d "$b"
  else
    curl -s -c "$JAR" -b "$JAR" -A "$UA" -X "$m" "$BASE/$p" -H "X-CSRF-Token: $CSRF"
  fi
}
http(){ # METHOD PATH [BODY] → jen HTTP status
  local m="$1" p="$2" b="${3:-}"
  if [[ -n "$b" ]]; then
    curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -A "$UA" -X "$m" "$BASE/$p" \
      -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" -d "$b"
  else
    curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -A "$UA" "$BASE/$p" -H "X-CSRF-Token: $CSRF"
  fi
}

printf '\033[1m╔══════════════════════════════════════════════════════╗\033[0m\n'
printf '\033[1m║   APPEK regresní smoke test — %-23s║\033[0m\n' "$(date '+%Y-%m-%d %H:%M')"
printf '\033[1m╚══════════════════════════════════════════════════════╝\033[0m\n'
printf 'BASE=%s  DB=%s\n' "$BASE" "$DB"

# ════════════════════════════════════════════════════════════════════════════
#  PREFLIGHT — bez přihlášení nemá smysl pokračovat
# ════════════════════════════════════════════════════════════════════════════
sec "PREFLIGHT — přístup + přihlášení"

# DB dostupná?
if ! q "SELECT 1" >/dev/null 2>&1; then
  no "DB připojení" "mysql '$MYSQL' nedostupné nebo DB '$DB' chybí"
  echo; echo "$(c_r 'ABORT') — bez DB nelze pokračovat."; exit 1
else ok "DB připojení"; fi

# dedikovaný smoke admin (deterministické heslo, neruší demo účty)
HASH="$("$PHP_BIN" -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$SMOKE_PASS" 2>/dev/null)"
if [[ -n "$HASH" ]]; then
  q "INSERT INTO admin_users (jmeno,email,heslo_hash,role,aktivni,pos_only)
     VALUES ('SMOKE Test','$SMOKE_EMAIL','$HASH','admin',1,0)
     ON DUPLICATE KEY UPDATE heslo_hash=VALUES(heslo_hash),aktivni=1,role='admin';"
  ok "smoke admin účet zajištěn ($SMOKE_EMAIL)"
else
  no "smoke admin účet" "php binárka '$PHP_BIN' nedostupná"
fi

# login
LOGIN="$(api POST admin_login.php "{\"email\":\"$SMOKE_EMAIL\",\"heslo\":\"$SMOKE_PASS\"}")"
CSRF="$(jval "$LOGIN" "['csrf_token']")"
if [[ -n "$CSRF" ]]; then ok "login → CSRF token"; else
  no "login" "${LOGIN:0:100}"
  echo; echo "$(c_r 'ABORT') — bez přihlášení nelze pokračovat."; exit 1
fi

# autentizovaný GET vrací 200
aeq "GET admin_suroviny (auth 200)"   "200" "$(http GET admin_suroviny.php)"
aeq "GET admin_objednavky (auth 200)" "200" "$(http GET admin_objednavky.php)"
aeq "GET admin_faktury (auth 200)"    "200" "$(http GET admin_faktury.php)"

# ════════════════════════════════════════════════════════════════════════════
#  FIXTURY — hermetické, prefix "SMOKE"
# ════════════════════════════════════════════════════════════════════════════
sec "FIXTURY"

# smaž staré SMOKE recept-vazby/výrobky/suroviny (čistý start)
OLD_VYR="$(q "SELECT id FROM vyrobky WHERE nazev LIKE 'SMOKE %'" | tr '\n' ',' | sed 's/,$//')"
[[ -n "$OLD_VYR" ]] && q "DELETE FROM vyrobek_suroviny WHERE vyrobek_id IN ($OLD_VYR)"
q "DELETE FROM vyrobky  WHERE nazev LIKE 'SMOKE %'"
q "DELETE FROM suroviny WHERE nazev LIKE 'SMOKE %'"

# surovina pro odpis (#11) — přes API, pak nastav stock
SUR_R="$(api POST admin_suroviny.php '{"nazev":"SMOKE Mouka","jednotka":"kg","stock_aktualni":1000}')"
SUR_ID="$(jval "$SUR_R" "['id']")"
[[ -z "$SUR_ID" ]] && SUR_ID="$(q "SELECT id FROM suroviny WHERE nazev='SMOKE Mouka' ORDER BY id DESC LIMIT 1")"
q "UPDATE suroviny SET stock_aktualni=1000 WHERE id=$SUR_ID"

# výrobek s receptem — SQL (převezmi jednotka_id/sazba_dph_id z existujícího výrobku)
read -r JID SDID <<<"$(q "SELECT jednotka_id, sazba_dph_id FROM vyrobky WHERE jednotka_id IS NOT NULL AND sazba_dph_id IS NOT NULL LIMIT 1")"
q "INSERT INTO vyrobky (nazev,jednotka_id,sazba_dph_id) VALUES ('SMOKE Pizza',${JID:-NULL},${SDID:-NULL})"
VYR_ID="$(q "SELECT id FROM vyrobky WHERE nazev='SMOKE Pizza' ORDER BY id DESC LIMIT 1")"
# recept: 5 jednotek suroviny na 1 výrobek
q "INSERT INTO vyrobek_suroviny (vyrobek_id,surovina_id,mnozstvi) VALUES ($VYR_ID,$SUR_ID,5)"

# odběratel pro B2B (#1/#3)
ODB_R="$(api POST admin_odberatele.php '{"nazev":"SMOKE Odběratel s.r.o."}')"
ODB_ID="$(jval "$ODB_R" "['id']")"
[[ -z "$ODB_ID" ]] && ODB_ID="$(q "SELECT id FROM odberatele WHERE nazev='SMOKE Odběratel s.r.o.' ORDER BY id DESC LIMIT 1")"

# stůl pro POS účet (#12)
STUL_ID="$(q "SELECT id FROM restaurant_tables ORDER BY id LIMIT 1")"
# vlastní kurýr (#8)
COURIER_ID="$(q "SELECT id FROM couriers WHERE COALESCE(typ,'') NOT IN ('wolt','bolt') ORDER BY id LIMIT 1")"
[[ -z "$COURIER_ID" ]] && COURIER_ID="$(q "SELECT id FROM couriers ORDER BY id LIMIT 1")"

printf '  fixtury: surovina=%s výrobek=%s odběratel=%s stůl=%s kurýr=%s\n' \
  "${SUR_ID:-–}" "${VYR_ID:-–}" "${ODB_ID:-–}" "${STUL_ID:-–}" "${COURIER_ID:-–}"

# ════════════════════════════════════════════════════════════════════════════
#  SCHEMA GUARDY (#2, #5) — chybějící sloupec = HTTP 500 v minulosti
# ════════════════════════════════════════════════════════════════════════════
sec "SCHEMA guardy (#2, #5)"
aeq "#2 faktura_polozky.vyrobek_cislo existuje" "1" \
  "$(q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='faktura_polozky' AND COLUMN_NAME='vyrobek_cislo'")"
aeq "#5 faktury.misto_dodani_id existuje" "1" \
  "$(q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='faktury' AND COLUMN_NAME='misto_dodani_id'")"

# ════════════════════════════════════════════════════════════════════════════
#  CODE-PRESENCE GUARDY (#9, #13, #14) — fixy, které nelze spolehlivě
#  ověřit funkčně (signatura webhooku / časování souběhu)
# ════════════════════════════════════════════════════════════════════════════
sec "CODE-PRESENCE guardy (#9, #13, #14)"
if grep -q "datum_objednani" "$SRC/_delivery_aggregators.php" 2>/dev/null; then
  ok "#9 _delivery_aggregators vkládá datum_objednani"
else no "#9 _delivery_aggregators datum_objednani" "v INSERTu chybí"; fi

if grep -qiE "duplicate|1062|23000" "$SRC/admin_pos.php" 2>/dev/null && grep -qi "attempt" "$SRC/admin_pos.php" 2>/dev/null; then
  ok "#13 admin_pos má retry na duplicitu POS čísla"
else no "#13 admin_pos retry" "smyčka nenalezena"; fi

if grep -qE "40001|1213|1205|[Dd]eadlock" "$SRC/admin_objednavky.php" 2>/dev/null; then
  ok "#14 admin_objednavky má retry na deadlock"
else no "#14 admin_objednavky retry" "smyčka nenalezena"; fi

# ════════════════════════════════════════════════════════════════════════════
#  BEHAVIORAL — alergeny, odpis skladu, POS, kurýr, faktura
# ════════════════════════════════════════════════════════════════════════════
sec "#7 — auto-detekce alergenu hořčice u \"hořčičná\""
A_R="$(api POST admin_suroviny.php '{"nazev":"SMOKE Hořčice dijon","jednotka":"g","slozeni":"hořčičná semínka, ocet, voda, sůl"}')"
acont "#7 \"hořčičná semínka\" → hořčice" "hořčice" "$(jval "$A_R" "['slozeni_alergeny']")"
q "DELETE FROM suroviny WHERE nazev='SMOKE Hořčice dijon'"

sec "#11 — POS prodej odepisuje suroviny ze skladu"
if [[ -n "$VYR_ID" && -n "$SUR_ID" ]]; then
  STOCK_PRE="$(q "SELECT stock_aktualni FROM suroviny WHERE id=$SUR_ID")"
  POH_PRE="$(q "SELECT COUNT(*) FROM sklad_pohyby WHERE surovina_id=$SUR_ID AND typ='vydej'")"
  R11="$(api POST "admin_pos.php?action=quick_order" "{\"pos_typ\":\"sebou\",\"pos_payment\":\"hotove\",\"poznamka\":\"SMOKE #11\",\"polozky\":[{\"vyrobek_id\":$VYR_ID,\"nazev\":\"SMOKE Pizza\",\"mnozstvi\":3,\"cena_bez_dph\":100,\"sazba_dph\":12}]}")"
  if [[ -n "$(jval "$R11" "['id']")" ]]; then
    STOCK_POST="$(q "SELECT stock_aktualni FROM suroviny WHERE id=$SUR_ID")"
    POH_POST="$(q "SELECT COUNT(*) FROM sklad_pohyby WHERE surovina_id=$SUR_ID AND typ='vydej'")"
    # prodány 3 ks × 5 jednotek = 15 úbytek
    DIFF="$(q "SELECT ROUND($STOCK_PRE - $STOCK_POST, 3)")"
    aeq "#11 stock klesl o 15 (3ks × recept 5)" "15.000" "$DIFF"
    if (( POH_POST > POH_PRE )); then ok "#11 sklad_pohyby výdej zalogován"; else no "#11 sklad_pohyby výdej" "žádný nový pohyb"; fi
  else no "#11 POS prodej proběhl" "${R11:0:80}"; fi
else sk "#11 odpis skladu" "chybí fixtura výrobek/surovina"; fi

sec "#12 — nedovařené položky → servirovano po platbě"
if [[ -n "$STUL_ID" && -n "$VYR_ID" ]]; then
  UCET="$(jval "$(api GET "admin_pos.php?action=ucet&stul_id=$STUL_ID")" "['id']")"
  api POST "admin_pos.php?action=item" "{\"ucet_id\":$UCET,\"vyrobek_id\":$VYR_ID,\"mnozstvi\":1}" >/dev/null
  SUMA="$(jval "$(api GET "admin_pos.php?action=ucet&stul_id=$STUL_ID")" "['suma_kc']")"
  api POST "admin_pos.php?action=pay" "{\"ucet_id\":$UCET,\"platby\":[{\"castka\":${SUMA:-0},\"zpusob\":\"hotovost\"}]}" >/dev/null
  STUCK="$(q "SELECT COUNT(*) FROM restaurant_pos_polozky WHERE ucet_id=$UCET AND stav IN ('objednano','vari_se','hotovo')")"
  aeq "#12 0 položek zaseknutých na objednano/vari_se" "0" "$STUCK"
else sk "#12 platba → servirovano" "chybí stůl/výrobek"; fi

sec "#8 — vlastní kurýr doruceno → objednávka dorucena"
if [[ -n "$COURIER_ID" && -n "$VYR_ID" ]]; then
  R8="$(api POST "admin_pos.php?action=quick_order" "{\"pos_typ\":\"rozvoz\",\"pos_payment\":\"karta\",\"poznamka\":\"SMOKE #8\",\"polozky\":[{\"vyrobek_id\":$VYR_ID,\"nazev\":\"SMOKE Pizza\",\"mnozstvi\":1,\"cena_bez_dph\":199,\"sazba_dph\":12}]}")"
  OID="$(jval "$R8" "['id']")"
  if [[ -n "$OID" ]]; then
    STAV_PRE="$(q "SELECT stav FROM objednavky WHERE id=$OID")"
    RD="$(api POST "admin_couriers.php?action=delivery" "{\"courier_id\":$COURIER_ID,\"objednavka_id\":$OID,\"adresa\":\"SMOKE 1\",\"mesto\":\"Brno\",\"cena_kc\":99}")"
    DID="$(jval "$RD" "['id']")"
    api POST "admin_couriers.php?action=delivery_status" "{\"id\":$DID,\"stav\":\"vyzvednuto\"}" >/dev/null
    api POST "admin_couriers.php?action=delivery_status" "{\"id\":$DID,\"stav\":\"doruceno\"}" >/dev/null
    aeq "#8 objednávka $OID ($STAV_PRE) → dorucena" "dorucena" "$(q "SELECT stav FROM objednavky WHERE id=$OID")"
  else no "#8 rozvoz objednávka" "${R8:0:80}"; fi
else sk "#8 kurýr → objednávka" "žádný kurýr/výrobek v DB"; fi

sec "#1 + #3 + #2 — B2B objednávka → faktura → detail s položkami"
if [[ -n "$ODB_ID" && -n "$VYR_ID" ]]; then
  DD="$(q "SELECT DATE_ADD(CURDATE(), INTERVAL 2 DAY)")"
  # pozn.: admin_objednavky čte 'action' z TĚLA požadavku (ne z query stringu)
  ORD_R="$(api POST "admin_objednavky.php" "{\"action\":\"vytvorit\",\"odberatel_id\":$ODB_ID,\"datum_dodani\":\"$DD\",\"polozky\":[{\"vyrobek_id\":$VYR_ID,\"mnozstvi\":4}]}")"
  B_OID="$(jval "$ORD_R" "['id']")"
  if [[ -n "$B_OID" ]]; then
    ok "#1 objednávka vytvořena (id=$B_OID)"
    DO="$(q "SELECT datum_objednani FROM objednavky WHERE id=$B_OID")"
    if [[ -n "$DO" && "$DO" != "NULL" && "$DO" != "0000-00-00" ]]; then ok "#1 datum_objednani vyplněno ($DO)"; else no "#1 datum_objednani" "je '$DO'"; fi
    # faktura z objednávky
    FA_R="$(api POST "faktura.php?action=vytvor&objednavka_id=$B_OID" "{}")"
    FA_ID="$(jval "$FA_R" "['id']")"; [[ -z "$FA_ID" ]] && FA_ID="$(jval "$FA_R" "['faktura_id']")"
    [[ -z "$FA_ID" ]] && FA_ID="$(q "SELECT id FROM faktury WHERE objednavka_id=$B_OID ORDER BY id DESC LIMIT 1")"
    if [[ -n "$FA_ID" ]]; then
      aeq "#2 faktura detail HTTP 200 (ne 500)" "200" "$(http GET "admin_faktury.php?id=$FA_ID")"
      DET="$(api GET "admin_faktury.php?id=$FA_ID")"
      NPOL="$(jval "$DET" "['polozky'] and len(d['polozky'])")"
      if [[ -n "$NPOL" && "$NPOL" != "0" && "$NPOL" != "False" ]]; then ok "#3 faktura ukazuje položky (DL fallback): $NPOL"; else no "#3 faktura položky" "prázdné (got '$NPOL')"; fi
      # #6 — plná úhrada nastaví datum_uhrazeni
      CELK="$(q "SELECT castka_celkem FROM faktury WHERE id=$FA_ID")"
      api PUT "admin_faktury.php" "{\"id\":$FA_ID,\"castka_uhrazeno\":$CELK}" >/dev/null
      DU="$(q "SELECT datum_uhrazeni FROM faktury WHERE id=$FA_ID")"
      if [[ -n "$DU" && "$DU" != "NULL" && "$DU" != "0000-00-00" ]]; then ok "#6 datum_uhrazeni nastaveno po plné úhradě ($DU)"; else no "#6 datum_uhrazeni" "je '$DU'"; fi
    else no "#3/#2/#6 faktura z objednávky" "fakturu se nepodařilo vytvořit: ${FA_R:0:80}"; fi
  else no "#1 objednávka vytvořena" "${ORD_R:0:100}"; fi
else sk "#1/#2/#3/#6" "chybí odběratel/výrobek"; fi

# Pozn.: behaviorální souběh (#13 POS číslo race, #14 B2B deadlock) se ve smoke
# záměrně netestuje — PHP zamyká session soubor (žádný session_write_close), takže
# sdílená session by paralelní requesty serializovala; multi-login zase naráží na
# rate-limit. Pro behaviorální souběh slouží dedikovaný zátěžový test (mimo smoke).
# Tady stačí code-presence guardy výše, které ohlídají, že retry smyčky nezmizí.

# ════════════════════════════════════════════════════════════════════════════
#  SHRNUTÍ
# ════════════════════════════════════════════════════════════════════════════
printf '\n\033[1m════════════════════════════════════════════════════════\033[0m\n'
TOTAL=$((PASS+FAIL))
printf '  %s   %s   %s   (celkem %d)\n' \
  "$(c_g "✓ $PASS PASS")" "$(c_r "✗ $FAIL FAIL")" "$(c_y "∅ $SKIP SKIP")" "$TOTAL"
if (( FAIL > 0 )); then
  printf '\n  %s\n' "$(c_r 'NEUSPĚLO:')"
  for f in "${FAILED[@]}"; do printf '    • %s\n' "$f"; done
  printf '\n%s — NEdeployovat, dokud nebude zeleno.\n' "$(c_r '✗ SMOKE TEST SELHAL')"
  exit 1
else
  printf '\n%s — bezpečné deployovat.\n' "$(c_g '✓ VŠE ZELENÉ')"
  exit 0
fi
