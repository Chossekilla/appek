#!/usr/bin/env bash
# 🔄 End-to-end test cloudové synchronizace (lokální PC ↔ cloud) se dvěma instancemi aplikace.
#
#   bash scripts/sync_e2e.sh            (potřebuje lokální MySQL s databází appek a PHP — XAMPP)
#
# Co dělá:
#   1. „cloud“ = kopie kódu (git archive) na portu 8093 s databází appek_sync_e2e (kopie appek)
#      „lokální PC“ = tento repozitář na portu 8091 s appek
#   2. obě strany vytvoří online objednávky (cloud 60 = víc než jedna dávka), synchronizuje se
#   3. ověří: všechny objednávky i položky na obou stranách, položky u správného výrobku (sync_uuid),
#      žádná kolize čísel (cloud čísluje řadou s „C“), změna stavu se propíše zpět, stav „success“
#   4. uklidí: smaže kopii DB i kódu, vrátí nastavení sync v appek (vypnuto), zastaví servery
# Testovací objednávky v appek zůstanou (testovací data se nemažou).
set -u
B=${XAMPP_BIN:-/Applications/XAMPP/xamppfiles/bin}
P=$(cd "$(dirname "$0")/.." && pwd)
C=$(mktemp -d /tmp/appek_sync_e2e.XXXXXX)
DB=appek_sync_e2e
TABS="sazby_dph jednotky kategorie_vyrobku vyrobky suroviny cenove_skupiny odberatele mista_dodani objednavky objednavky_polozky dodaci_listy dodaci_list_polozky faktury faktura_polozky"
M="$B/mysql -u root"
FAIL=0
ok() { if [ "$1" = "$2" ]; then echo "  ✅ $3"; else echo "  ❌ $3 (čekáno $2, je $1)"; FAIL=1; fi; }

uklid() {
  pkill -f "php -S 127.0.0.1:8093" 2>/dev/null; pkill -f "php -S 127.0.0.1:8091" 2>/dev/null
  $M -e "DROP DATABASE IF EXISTS $DB" 2>/dev/null
  $M appek -e "UPDATE sync_config SET mode='cloud', role='master', cloud_endpoint=NULL, shared_secret=NULL, enabled=0, last_error=NULL, pull_kurzor=NULL WHERE id=1" 2>/dev/null
  # appek: lokální PC se se skutečným cloudem nikdy nesynchronizovalo → stav „neodesláno“ zpět (updated_at beze změny)
  for t in $TABS; do $M appek -e "UPDATE $t SET synced_at=NULL, updated_at=updated_at WHERE synced_at IS NOT NULL" 2>/dev/null; done
  rm -rf "$C"
}
trap uklid EXIT

echo "🔧 příprava"
cd "$P"
SECRET=$(openssl rand -hex 32)
$M -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
$M appek -e "UPDATE sync_config SET mode='hybrid', role='master', cloud_endpoint='http://127.0.0.1:8093/api', shared_secret='$SECRET', enabled=1, last_error=NULL WHERE id=1"
(cd api && $B/php -r 'require "config.php"; db();')   # sloupce sync (sync_uuid, pull_kurzor) + backfill
$M appek -e "UPDATE sync_config SET pull_kurzor=NULL WHERE id=1"
$B/mysqldump -u root --single-transaction appek 2>/dev/null | $M $DB
git archive HEAD | tar -x -C "$C"
cp api/config.php api/_sync_schema.php "$C/api/"; cp api/sync/*.php "$C/api/sync/"   # i necommitnuté změny sync
sed "s/define('DB_NAME', 'appek')/define('DB_NAME', '$DB')/" api/config.local.php > "$C/api/config.local.php"
$M $DB -e "UPDATE sync_config SET mode='cloud', role='mirror', cloud_endpoint=NULL, shared_secret='$SECRET', enabled=1, pull_kurzor=NULL WHERE id=1"
(cd "$C" && PHP_CLI_SERVER_WORKERS=4 $B/php -S 127.0.0.1:8093 -t . > /tmp/appek_sync_e2e_8093.log 2>&1 &)
pgrep -f "php -S 127.0.0.1:8091" >/dev/null || (PHP_CLI_SERVER_WORKERS=4 $B/php -S 127.0.0.1:8091 -t . > /tmp/appek_sync_e2e_8091.log 2>&1 &)
sleep 1

agent() { $B/php api/sync/agent.php 2>&1; }
sync_do_ustaleni() { local prev="" k pu out; for i in $(seq 1 ${KOLA:-40}); do out=$(agent); pu=$(echo "$out" | grep -o "Pushed: [0-9]*" | grep -o "[0-9]*"); k=$($M appek -N -e "select pull_kurzor from sync_config"); [ "$k" = "$prev" ] && [ "$pu" = "0" ] && return 0; prev=$k; done; echo "$out" | tail -15; return 1; }
obj() {   # obj <db> <port> <jméno> <výrobek> <ks> — appek: přes scripts/sync_e2e_obj.php (online objednávky jsou za licencí balíčku „restaurace“)
  local root="$P"; [ "$2" = 8093 ] && root="$C"
  $B/php "$P/scripts/sync_e2e_obj.php" "$root" "$3" "$4" "$5"
}

START=$($M -N -e "select now()")
echo "🔄 počáteční synchronizace (appek ~26 tis. řádků, 100 na běh agenta)"
KOLA=600 sync_do_ustaleni || { echo "  ❌ počáteční sync se neustálil"; FAIL=1; }

echo "🛒 objednávky na obou stranách"
V1=$($M appek -N -e "select id from vyrobky where aktivni=1 and cena_bez_dph>0 order by id limit 1 offset 2")
V2=$($M appek -N -e "select id from vyrobky where aktivni=1 and cena_bez_dph>0 order by id limit 1 offset 5")
L1=$(obj appek 8091 LokalA "$V1" 1); L2=$(obj appek 8091 LokalB "$V1" 2)
first=""; last=""; n=0
for i in $(seq 1 60); do c=$(obj $DB 8093 "Cloud$i" "$V2" $(( (i % 4) + 1 ))); case "$c" in WEB-*) n=$((n+1)); [ -z "$first" ] && first=$c; last=$c;; esac; done
ok "$n" 60 "cloud vytvořil 60 objednávek ($first … $last)"
case "$first" in *-C*) ok 1 1 "cloud čísluje vlastní řadou s „C“";; *) ok 0 1 "cloud čísluje vlastní řadou s „C“";; esac

echo "🔄 synchronizace"
sync_do_ustaleni || { echo "  ❌ sync se neustálil"; FAIL=1; }
ok "$($M appek -N -e "select last_sync_status from sync_config")" success "stav synchronizace"
ok "$($M appek -N -e "select count(*) from objednavky where cislo between '$first' and '$last'")" 60 "cloudové objednávky na lokálním PC"
ok "$($M appek -N -e "select count(*) from objednavky_polozky p join objednavky o on o.id=p.objednavka_id where o.cislo between '$first' and '$last'")" 60 "jejich položky na lokálním PC (správná objednávka)"
ok "$($M -N -e "select count(*) from (select o.cislo, p.vyrobek_nazev, p.mnozstvi from $DB.objednavky o join $DB.objednavky_polozky p on p.objednavka_id=o.id where o.cislo between '$first' and '$last') c left join (select o.cislo, p.vyrobek_nazev, p.mnozstvi from appek.objednavky o join appek.objednavky_polozky p on p.objednavka_id=o.id where o.cislo between '$first' and '$last') m on m.cislo=c.cislo and m.vyrobek_nazev=c.vyrobek_nazev and m.mnozstvi=c.mnozstvi where m.cislo is null")" 0 "položky sedí (výrobek, množství)"
ok "$($M -N -e "select sum(cv.sync_uuid <> mv.sync_uuid) from appek.objednavky mo join appek.objednavky_polozky mp on mp.objednavka_id=mo.id join appek.vyrobky mv on mv.id=mp.vyrobek_id join $DB.objednavky_polozky cp on cp.sync_uuid=mp.sync_uuid join $DB.vyrobky cv on cv.id=cp.vyrobek_id where mo.cislo between '$first' and '$last'")" 0 "položky ukazují na stejný výrobek (sync_uuid)"
ok "$($M $DB -N -e "select count(*) from objednavky_polozky p join objednavky o on o.id=p.objednavka_id where o.cislo in ('$L1','$L2')")" 2 "lokální objednávky v cloudu i s položkami"

ZM=0; for t in $TABS; do z=$($M appek -N -e "select count(*) from $t where updated_at >= '$START'"); ZM=$((ZM+z)); done
ok "$($M appek -N -e "select count(*) from objednavky where updated_at >= '$START'")" "$($M appek -N -e "select count(*) from objednavky where updated_at >= '$START' and (cislo between '$first' and '$last' or cislo in ('$L1','$L2'))")" "sync nepřepsal updated_at nezměněných objednávek (změněno celkem $ZM řádků = jen nové)"

echo "🔢 další čísla bez kolize"
L3=$(obj appek 8091 LokalC "$V1" 1); C3=$(obj $DB 8093 CloudX "$V1" 1)
case "$L3$C3" in *CHYBA*) ok "$L3 / $C3" "dvě nová čísla" "další objednávka na obou stranách";; *) ok 1 1 "další objednávka na obou stranách ($L3, $C3)";; esac

echo "✏️ změna stavu lokálně → cloud"
$M appek -e "UPDATE objednavky SET stav='dorucena', updated_at=NOW() WHERE cislo='$first'"; sleep 1
sync_do_ustaleni
ok "$($M $DB -N -e "select stav from objednavky where cislo='$first'")" dorucena "stav se propsal do cloudu"

echo; [ $FAIL = 0 ] && echo "✅ SYNC E2E OK" || echo "❌ SYNC E2E SELHAL"
exit $FAIL
