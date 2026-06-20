#!/usr/bin/env bash
# 🆕 v3.0.355 — APPEK smoke test (regrese-guard, node-nezávislý).
#   Usage: bash scripts/smoke.sh [BASE_URL]   (default http://localhost/appek)
#   Kontroly:
#     1) žádný api/*.php endpoint nesmí vrátit 500/000 (GET, bez session) → chytí
#        parse error, fresh-install schema 500, fatal. (Vendor-only → 503 = OK.)
#     2) auth-required endpointy MUSÍ bez session vrátit 401/403 (regrese IDOR/auth).
#     3) public endpointy MUSÍ vrátit 200.
#   Exit 1 při jakémkoli failu (CI-friendly).
set -uo pipefail
BASE="${1:-http://localhost/appek}"
SROOT="$(cd "$(dirname "$0")/.." && pwd)"
PASS=0; FAIL=0; FAILED=()

code() { curl -s -m 15 -o /dev/null -w "%{http_code}" "$1" 2>/dev/null || echo "000"; }

echo "🔍 APPEK smoke @ $BASE"

echo "── 1) no-500 sweep (GET, bez session) ──"
for f in "$SROOT"/api/*.php; do
  b="$(basename "$f")"
  case "$b" in _*|config.php|config.local.php|vendor_db_config*) continue;; esac
  c=$(code "$BASE/api/$b")
  if [[ "$c" == "500" || "$c" == "000" ]]; then
    echo "  ❌ $b → $c"; FAILED+=("nosweep:$b=$c"); FAIL=$((FAIL+1))
  else PASS=$((PASS+1)); fi
done

echo "── 2) auth-required (bez session → 401/403) ──"
for ep in objednavky.php faktury_odberatele.php mista_dodani.php statistiky.php \
          admin_odberatele.php admin_faktury.php admin_vyrobky.php admin_pos.php \
          admin_nastaveni.php admin_import.php "faktura.php?ids=1" "dodaci_list.php?ids=1"; do
  c=$(code "$BASE/api/$ep")
  if [[ "$c" == "401" || "$c" == "403" ]]; then PASS=$((PASS+1)); else
    echo "  ❌ $ep → $c (čekám 401/403)"; FAILED+=("auth:$ep=$c"); FAIL=$((FAIL+1)); fi
done

echo "── 3) public (→ 200) ──"
for ep in version.php firma_branding.php "payment_methods.php?context=b2b"; do
  c=$(code "$BASE/api/$ep")
  if [[ "$c" == "200" ]]; then PASS=$((PASS+1)); else
    echo "  ❌ $ep → $c (čekám 200)"; FAILED+=("public:$ep=$c"); FAIL=$((FAIL+1)); fi
done
# healthcheck: 200 (ok) i 503 (check failnul) = endpoint běží
c=$(code "$BASE/api/healthcheck.php")
if [[ "$c" == "200" || "$c" == "503" ]]; then PASS=$((PASS+1)); else
  echo "  ❌ healthcheck.php → $c (čekám 200/503)"; FAILED+=("public:healthcheck=$c"); FAIL=$((FAIL+1)); fi

echo ""
echo "✅ PASS=$PASS  ❌ FAIL=$FAIL"
if [[ $FAIL -gt 0 ]]; then printf '   %s\n' "${FAILED[@]}"; exit 1; fi
echo "🎉 Smoke OK"
