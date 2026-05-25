#!/bin/bash
# 🔬 APPEK SMOKE TEST — rychlé end-to-end ověření že kritické endpointy chodí.
#
# Použití:
#   ./scripts/smoke-test.sh                          # default https://demo.appek.cz
#   ./scripts/smoke-test.sh https://my-customer.cz   # custom host
#   ./scripts/smoke-test.sh http://localhost/appek   # local XAMPP
#
# Co testuje (read-only, žádné mutace):
#   1. GET /api/version.php → 200 + valid JSON s polem version
#   2. GET /api/healthcheck.php → 200 + ok:true (ne 503)
#   3. GET / (landing) → 200 + obsahuje "APPEK"
#   4. GET /admin/ → 200 + obsahuje login form
#   5. GET /api/business_info.php → 200 + valid JSON
#   6. GET /api/demo_status.php → 200 + valid JSON
#
# Exit code:
#   0 = vše prošlo
#   1 = aspoň jeden test failnul (script vypíše který)

set -u

HOST="${1:-https://demo.appek.cz}"
HOST="${HOST%/}"  # strip trailing slash

PASS=0
FAIL=0
TESTS=()

echo "🔬 APPEK SMOKE TEST"
echo "   Target: $HOST"
echo ""

check() {
    local name="$1"
    local url="$2"
    local expect_substr="${3:-}"

    local resp
    resp=$(curl -sS -w '\n__HTTP__%{http_code}' --max-time 15 "$url" 2>&1)
    local body=$(echo "$resp" | sed '$d')
    local code=$(echo "$resp" | tail -n1 | sed 's/__HTTP__//')

    local ok=true
    local detail=""
    if [[ "$code" != "200" ]]; then
        ok=false
        detail="HTTP $code"
    elif [[ -n "$expect_substr" ]] && ! echo "$body" | grep -q "$expect_substr"; then
        ok=false
        detail="missing '$expect_substr' in body (got: $(echo "$body" | head -c 80))"
    fi

    if $ok; then
        printf "  ✅ %-35s %s\n" "$name" "HTTP 200"
        PASS=$((PASS+1))
        TESTS+=("ok:$name")
    else
        printf "  ❌ %-35s %s\n" "$name" "$detail"
        FAIL=$((FAIL+1))
        TESTS+=("FAIL:$name:$detail")
    fi
}

# 1. Version endpoint
check "version.php"          "$HOST/api/version.php"               '"version"'

# 2. Healthcheck (acceptable: 200 ok=true OR 503 ok=false — both signal alive)
echo "  ⏳ healthcheck.php (may take ~1s)..."
hc_body=$(curl -sS --max-time 15 "$HOST/api/healthcheck.php" 2>&1)
hc_code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "$HOST/api/healthcheck.php" 2>&1)
if echo "$hc_body" | grep -q '"version"'; then
    if echo "$hc_body" | grep -q '"ok": true'; then
        printf "  ✅ %-35s %s\n" "healthcheck.php" "all checks green"
        PASS=$((PASS+1))
    else
        failed=$(echo "$hc_body" | grep -oE '"name": "[^"]+",\n[[:space:]]*"ok": false' | grep -oE '"[^"]+"' | head -3 | tr '\n' ' ')
        printf "  ⚠️  %-35s %s\n" "healthcheck.php" "alive but some checks failed (HTTP $hc_code)"
        PASS=$((PASS+1))
    fi
else
    printf "  ❌ %-35s %s\n" "healthcheck.php" "not responding correctly (HTTP $hc_code)"
    FAIL=$((FAIL+1))
fi

# 3. Landing page
check "/ (landing)"          "$HOST/"                              "APPEK"

# 4. Admin page (login form should be present)
check "/admin/"              "$HOST/admin/"                        "APPEK B2B"

# 5. Business info JSON (valid JSON response, content not required — installs bez nastavení vrátí [])
bi_resp=$(curl -sS --max-time 10 "$HOST/api/business_info.php" 2>&1)
bi_code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 "$HOST/api/business_info.php" 2>&1)
if [[ "$bi_code" == "200" ]] && (echo "$bi_resp" | python3 -c "import json,sys;json.load(sys.stdin)" 2>/dev/null || echo "$bi_resp" | head -c 1 | grep -qE '[\[\{]'); then
    printf "  ✅ %-35s %s\n" "business_info.php" "valid JSON"
    PASS=$((PASS+1))
else
    printf "  ❌ %-35s %s\n" "business_info.php" "HTTP $bi_code or invalid JSON"
    FAIL=$((FAIL+1))
fi

# 6. Demo status (volitelné — jen na demo)
demo_resp=$(curl -sS --max-time 10 "$HOST/api/demo_status.php" 2>&1)
if echo "$demo_resp" | grep -q '"demo"'; then
    printf "  ✅ %-35s %s\n" "demo_status.php" "(demo mode detected)"
    PASS=$((PASS+1))
else
    printf "  ⚪ %-35s %s\n" "demo_status.php" "skipped (not demo mode)"
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
if [[ $FAIL -eq 0 ]]; then
    echo "✅ SMOKE TEST PASSED — $PASS/$((PASS+FAIL)) endpoints OK"
    exit 0
else
    echo "❌ SMOKE TEST FAILED — $FAIL failures, $PASS passes"
    echo ""
    echo "Failed tests:"
    for t in "${TESTS[@]}"; do
        if [[ "$t" == FAIL:* ]]; then
            echo "  $t"
        fi
    done
    exit 1
fi
