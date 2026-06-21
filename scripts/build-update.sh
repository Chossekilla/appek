#!/bin/bash
# 📦 BUILD UPDATE BUNDLE
# Vytvoří update ZIP pro distribuci přes vendor.appek.cz/updates.php
#
# Použití:
#   ./scripts/build-update.sh 2.1.0                  # full bundle (všechny soubory)
#   ./scripts/build-update.sh 2.1.0 --since 2.0.10   # diff bundle proti commit/tag
#
# Výstup:
#   appek-update-X.Y.Z.zip se strukturou:
#     manifest.json   (verze, files: {path: sha256}, …)
#     files/          (zrcadlí customer instalaci)
#     changelog.md    (z CHANGELOG.md sekce X.Y.Z, pokud existuje)

set -e

VERSION="$1"
MODE="full"
SINCE=""

if [[ -z "$VERSION" ]]; then
  echo "❌ Chybí verze. Použití: ./build-update.sh X.Y.Z [--since OLD_VERSION]"
  exit 1
fi

if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[a-z0-9]+)?$ ]]; then
  echo "❌ Verze musí být ve formátu X.Y.Z (např. 2.1.0)"
  exit 1
fi

if [[ "$2" == "--since" && -n "$3" ]]; then
  MODE="diff"
  SINCE="$3"
fi

# 🆕 v3.0.165 — auto-sync verze do VŠECH markerů (config.php / admin.js / admin.css),
# ať footer + deploy-check nehlásí "nekompletní deploy". Dřív se ručně bumpoval jen
# config.php → admin.js/admin.css zůstávaly pozadu a footer ukazoval starou verzi.
SROOT="$(cd "$(dirname "$0")/.." && pwd)"
perl -i -pe "s/(APP_VERSION'\\s*,\\s*')\\d+\\.\\d+\\.\\d+(')/\${1}${VERSION}\${2}/"          "$SROOT/api/config.php"
# 🆕 v3.0.361 — admin.js je GENEROVANÝ z admin/src/*.js (modularizace). Bumpni marker ve SRC + regeneruj.
#   EDITUJ admin/src/, NE admin/admin.js (concat ho přepíše). Viz admin/src/README.md.
if [ -d "$SROOT/admin/src" ]; then
  perl -i -pe "s/(APPEK_ADMIN_JS_VERSION\\s*=\\s*')\\d+\\.\\d+\\.\\d+(')/\${1}${VERSION}\${2}/" "$SROOT/admin/src/0000-preamble.js"
  cat "$SROOT"/admin/src/*.js > "$SROOT/admin/admin.js"
  echo "🧩 admin.js regenerován z admin/src/ ($(ls "$SROOT"/admin/src/*.js | wc -l | tr -d ' ') souborů)"
else
  perl -i -pe "s/(APPEK_ADMIN_JS_VERSION\\s*=\\s*')\\d+\\.\\d+\\.\\d+(')/\${1}${VERSION}\${2}/" "$SROOT/admin/admin.js"
fi
perl -i -pe "s/(--appek-css-version:\\s*\")\\d+\\.\\d+\\.\\d+(\")/\${1}${VERSION}\${2}/"       "$SROOT/admin/admin.css"
# 🆕 v3.0.183/184 — cache-bust: bumpni ?v=X.Y.Z na VŠECH assetech v admin/index.html I b2b/index.html
#   (admin.js/css, i18n, app.js, style.css). Dřív zamrzlé na 3.0.162 → prohlížeč nestáhl nový
#   JS/CSS bez hard-refreshe. Teď auto-sync obou portálů.
perl -i -pe "s/(\\?v=)\\d+\\.\\d+\\.\\d+/\${1}${VERSION}/g"                                    "$SROOT/admin/index.html" "$SROOT/b2b/index.html"
# 🆕 v3.0.236 — KRITICKÉ: bumpni CACHE_VERSION v service-workerech. Dřív zamrzlé (admin
#   'appek-v3.0.153', b2b 'appek-b2b-v1') → SW activate NIKDY nepurgoval starou cache →
#   uživatelé zamrzli na staré verzi navždy (footer X, licence Y). Teď se s každým deployem
#   změní byte sw.js → prohlížeč přeinstaluje SW → activate smaže starou cache → čerstvé assety.
perl -i -pe "s/(CACHE_VERSION\\s*=\\s*')appek-v[0-9.]+(')/\${1}appek-v${VERSION}\${2}/"        "$SROOT/admin/sw.js"
perl -i -pe "s/(CACHE_NAME\\s*=\\s*')appek-b2b-v[0-9.a-z]+(')/\${1}appek-b2b-v${VERSION}\${2}/" "$SROOT/b2b/sw.js"
perl -i -pe "s/(CACHE_NAME\\s*=\\s*')appek-pos-v[0-9.a-z]+(')/\${1}appek-pos-v${VERSION}\${2}/" "$SROOT/pos/sw.js"
echo "🔖 Verze sjednoceny na ${VERSION}: config.php · admin.js · admin.css · admin+b2b/index.html (?v) · sw.js CACHE_VERSION (admin+b2b)"

# 🆕 v3.0.166 — php -l guard: zachyť PARSE ERROR před buildem. Jinak se nasadí soubor
# s fatální chybou → 500 na endpointu (viz admin_dodaci_listy.php v3.0.165: dvojitá
# uvozovka v SQL komentáři uvnitř "$sql = \"...\"" → "unexpected integer"). SHA sedí,
# takže updater to nepozná — proto lint tady, při buildu.
PHPBIN="$(command -v php || echo /Applications/XAMPP/xamppfiles/bin/php)"
if [[ -x "$PHPBIN" ]]; then
  LINT_ERR=0
  while IFS= read -r f; do
    "$PHPBIN" -l "$f" >/dev/null 2>&1 || { echo "❌ PHP parse error: $f"; "$PHPBIN" -l "$f" 2>&1 | grep -i 'error' | head -1; LINT_ERR=1; }
  done < <(find "$SROOT/api" "$SROOT/admin" -name '*.php' 2>/dev/null)
  [[ "$LINT_ERR" == "1" ]] && { echo "❌ Build zastaven — oprav parse chyby výše."; exit 1; }
  echo "✅ php -l: všechny PHP v api/ + admin/ bez parse chyb"
fi

# 🆕 v3.0.353 — JS syntax gate: admin.js (~42k ř.) + b2b/app.js + i18n nemají jinak
# ŽÁDNOU pojistku proti parse chybě → jediný špatný edit rozbije celou admin/B2B
# appku u zákazníků (php -l je nepokryje). Aktivuje se, jakmile je node v PATH.
NODEBIN="$(command -v node || true)"
if [[ -n "$NODEBIN" ]]; then
  JS_ERR=0
  while IFS= read -r jf; do
    "$NODEBIN" --check "$jf" 2>/dev/null || { echo "❌ JS parse error: $jf"; "$NODEBIN" --check "$jf" 2>&1 | head -3; JS_ERR=1; }
  done < <(find "$SROOT/admin" "$SROOT/b2b" -name '*.js' 2>/dev/null)
  [[ "$JS_ERR" == "1" ]] && { echo "❌ Build zastaven — oprav JS parse chyby výše."; exit 1; }
  echo "✅ node --check: admin/ + b2b/ JS bez parse chyb"
else
  echo "⚠️  node není v PATH → JS syntax gate PŘESKOČEN (nainstaluj node: pojistka proti rozbití admin.js)"
fi

OUTPUT="appek-update-${VERSION}.zip"
TMPDIR="/tmp/appek-update-${VERSION}-$$"
mkdir -p "$TMPDIR/files"

echo "🏗️  Building update bundle: $OUTPUT (mode=$MODE)"
[[ -n "$SINCE" ]] && echo "   diff od verze: $SINCE"

# Soubory k zahrnutí (customer-relevant — vyloučí vendor/, sales/, build script)
INCLUDE_PATHS=(
  "api"
  "admin"
  "b2b"
  "pos"
  "floorplan"
  "qr"
  "demo"
  "install.php"
  "index.php"
  "instalace.html"
  ".htaccess"
  "robots.txt"
  "CHANGELOG.md"
)

EXCLUDES=(
  ".git" ".github" ".claude" ".vscode" ".idea"
  "node_modules" "uploads"
  "api/config.local.php" "api/.installed" "api/zalohy"
  "vendor" "sales"   # NEZAHRNOUJ — vendor jen lokálně, sales je na appek.cz, ne na customer
  "scripts"
  "*.zip" "*.log" "*.bak" "*.bak-*" "download.php" "docs"
)

# Vytvoř files/ s relevantními soubory
if [[ "$MODE" == "full" ]]; then
  for p in "${INCLUDE_PATHS[@]}"; do
    if [[ -e "$p" ]]; then
      mkdir -p "$TMPDIR/files/$(dirname "$p")"
      cp -R "$p" "$TMPDIR/files/$(dirname "$p")/" 2>/dev/null || true
    fi
  done
elif [[ "$MODE" == "diff" ]]; then
  # Diff podle git
  if ! git rev-parse "$SINCE" >/dev/null 2>&1; then
    # Try tag form 'v2.0.10'
    if git rev-parse "v$SINCE" >/dev/null 2>&1; then
      SINCE="v$SINCE"
    else
      echo "❌ Git ref '$SINCE' neexistuje. Vytvoř tag: git tag v$SINCE"
      exit 1
    fi
  fi
  CHANGED=$(git diff --name-only "$SINCE" HEAD)
  if [[ -z "$CHANGED" ]]; then
    echo "❌ Žádné změny mezi $SINCE a HEAD."
    exit 1
  fi
  while IFS= read -r f; do
    SKIP=0
    for ex in "${EXCLUDES[@]}"; do
      [[ "$f" == "$ex"* ]] && { SKIP=1; break; }
    done
    [[ $SKIP -eq 1 ]] && continue
    [[ ! -e "$f" ]] && continue
    mkdir -p "$TMPDIR/files/$(dirname "$f")"
    cp "$f" "$TMPDIR/files/$f"
  done <<< "$CHANGED"
fi

# Odstraň exludované soubory rekurzivně
cd "$TMPDIR/files"
for ex in "${EXCLUDES[@]}"; do
  find . -name "$(basename "$ex")" -path "*${ex}*" -exec rm -rf {} + 2>/dev/null || true
done
cd - >/dev/null

# Vygeneruj manifest.json s SHA-256 každého souboru (Python = validní JSON, žádná trailing comma)
echo "🔍 Generuji manifest.json (SHA-256 každého souboru)…"
RELEASED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
python3 - "$TMPDIR" "$VERSION" "$MODE" "$RELEASED_AT" <<'PYEOF'
import sys, os, json, hashlib
tmpdir, version, mode, released_at = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
files_dir = os.path.join(tmpdir, 'files')
files = {}
for root, _, fns in os.walk(files_dir):
    for fn in fns:
        abs_p = os.path.join(root, fn)
        rel = os.path.relpath(abs_p, files_dir).replace(os.sep, '/')
        with open(abs_p, 'rb') as fh:
            h = hashlib.sha256(fh.read()).hexdigest()
        files[rel] = h
manifest = {
    'version': version,
    'released_at': released_at,
    'channel': 'stable',
    'mode': mode,
    'min_version': None,
    'packages_required': ['core'],
    'files': dict(sorted(files.items())),
}
with open(os.path.join(tmpdir, 'manifest.json'), 'w', encoding='utf-8') as fh:
    json.dump(manifest, fh, indent=2, ensure_ascii=False)
print(f"  ✓ manifest.json — {len(files)} souborů")
PYEOF

# Changelog — vytáhni sekci pro tuto verzi z CHANGELOG.md
if [[ -f "CHANGELOG.md" ]]; then
  CHANGELOG=$(awk -v v="$VERSION" '
    /^## \[?'"$VERSION"'\]?/ { found=1; print; next }
    found && /^## / { exit }
    found { print }
  ' CHANGELOG.md)
  if [[ -n "$CHANGELOG" ]]; then
    echo "$CHANGELOG" > "$TMPDIR/changelog.md"
    echo "📝 Changelog pro $VERSION nalezen v CHANGELOG.md"
  else
    echo "## $VERSION" > "$TMPDIR/changelog.md"
    echo "" >> "$TMPDIR/changelog.md"
    echo "_(Changelog nebyl nalezen v CHANGELOG.md. Doplň ručně sekci '## [$VERSION]'.)_" >> "$TMPDIR/changelog.md"
  fi
else
  echo "## $VERSION" > "$TMPDIR/changelog.md"
fi

# Zabal vše
rm -f "$OUTPUT"
(cd "$TMPDIR" && zip -qr "$OUTPUT" .) && mv "$TMPDIR/$OUTPUT" .

# Cleanup
rm -rf "$TMPDIR"

# Stats
SIZE=$(ls -lh "$OUTPUT" | awk '{print $5}')
FILES=$(unzip -l "$OUTPUT" | tail -1 | awk '{print $2}')
SHA=$(shasum -a 256 "$OUTPUT" | cut -d' ' -f1)

echo ""
echo "✅ Hotovo!"
echo "   Bundle:    $OUTPUT"
echo "   Velikost:  $SIZE"
echo "   Souborů:   $FILES"
echo "   SHA-256:   $SHA"
echo ""
echo "Další kroky:"
echo "  1. Nahraj na vendor.appek.cz/updates.php"
echo "  2. Zkontroluj že velikost + SHA sedí (vendor je spočítá znova)"
echo "  3. Klikni Publikovat → zákazníci to uvidí"
