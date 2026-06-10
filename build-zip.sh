#!/bin/bash
# 📦 APPEK BUILD SCRIPT — Postaví oba ZIPy zároveň
#
# CUSTOMER ZIP (appek-vX.Y.Z.zip):
#   Co dostane zákazník po zaplacení. Obsahuje:
#     api/, admin/, b2b/, demo/, install.php, root docs
#   Vyloučeno: vendor/, sales/ (nepotřebují/nesmí to mít)
#
# MASTER ZIP (appek-MASTER-vX.Y.Z.zip):
#   Co nasadíš na vendor.appek.cz + appek.cz (produkce). Obsahuje:
#     vendor/, sales/, api/, root docs
#   Vyloučeno: admin/, b2b/ (NE-deployovat — jen distribuce přes updates)
#                              demo/ je VOLITELNĚ (pro marketing demo.appek.cz)
#
# Použití: ./build-zip.sh [2.1.0]

set -e

VERSION="${1:-2.0.12}"
CUSTOMER_OUT="appek-v${VERSION}.zip"
MASTER_OUT="appek-MASTER-v${VERSION}.zip"

echo "📦 Building APPEK distribuce v${VERSION}"
echo ""

# ─── 🔢 BUMP VERSION v api/config.php (sync APP_VERSION) ────────
if [[ -f api/config.php ]]; then
  # Sed in-place: nahradí APP_VERSION value (BSD/macOS i GNU sed-compatible)
  sed -i.bak -E "s/(define\('APP_VERSION'[^']*')[^']*('\s*\)\s*;)/\1${VERSION}\2/" api/config.php
  rm -f api/config.php.bak
  echo "🔢 APP_VERSION → ${VERSION} v api/config.php"
fi

# 🆕 v2.0.75 — Auto-bump Service Worker CACHE_VERSION
# Bez toho SW servuje starý admin.js z cache i po update → "změny nepropisují"
if [[ -f admin/sw.js ]]; then
  sed -i.bak -E "s/(const CACHE_VERSION = 'appek-v)[^']*(')/\1${VERSION}\2/" admin/sw.js
  rm -f admin/sw.js.bak
  echo "🔢 SW CACHE_VERSION → appek-v${VERSION} v admin/sw.js"
fi
if [[ -f b2b/sw.js ]]; then
  sed -i.bak -E "s/(const CACHE_VERSION = 'appek-v)[^']*(')/\1${VERSION}\2/" b2b/sw.js
  rm -f b2b/sw.js.bak
  echo "🔢 SW CACHE_VERSION → appek-v${VERSION} v b2b/sw.js"
fi

# 🆕 v2.0.75 — Auto-bump admin.css/admin.js cache busters v admin/index.html
# (jinak browser servuje starý JS s ?v=2.0.71 i po update na 2.0.75)
if [[ -f admin/index.html ]]; then
  sed -i.bak -E "s/(admin\.(js|css)\?v=)[0-9]+\.[0-9]+\.[0-9]+/\1${VERSION}/g" admin/index.html
  sed -i.bak -E "s/(i18n_auto\.js\?v=)[0-9]+\.[0-9]+\.[0-9]+/\1${VERSION}/g" admin/index.html
  # 🆕 v2.9.44 — Cache-bust i18n.js a i18n_extra.js (předtím hardcoded ?v=2.0 a ?v=2.6.9)
  sed -i.bak -E "s/(i18n\.js\?v=)[^\"']+/\1${VERSION}/g" admin/index.html
  sed -i.bak -E "s/(i18n_extra\.js\?v=)[^\"']+/\1${VERSION}/g" admin/index.html
  rm -f admin/index.html.bak
  echo "🔢 admin.{js,css,i18n*}?v= → ${VERSION} v admin/index.html"
fi

# 🆕 v2.0.83 — Embed BUILD_VERSION do admin.js (pro stale-code self-healing detekci)
# Po boot admin.js porovná tuto konstantu s api/.update-manifest.json. Mismatch → auto cache clear + reload.
if [[ -f admin/admin.js ]]; then
  sed -i.bak -E "s/const APPEK_ADMIN_JS_VERSION = '[^']*';/const APPEK_ADMIN_JS_VERSION = '${VERSION}';/" admin/admin.js
  rm -f admin/admin.js.bak
  echo "🔢 APPEK_ADMIN_JS_VERSION → ${VERSION} v admin/admin.js"
fi
# 🆕 v2.9.60 — Embed verzi i do admin.css (--appek-css-version) — footer ji ověří
# Tím footer pozná jestli admin.css JE aktuální (ne jen config.php / admin.js).
if [[ -f admin/admin.css ]]; then
  sed -i.bak -E "s/--appek-css-version: \"[^\"]*\"/--appek-css-version: \"${VERSION}\"/" admin/admin.css
  rm -f admin/admin.css.bak
  echo "🔢 --appek-css-version → ${VERSION} v admin/admin.css"
fi
# 🆕 v2.9.62 — Verze do vendor/.appek-version (vendor topbar ji čte)
if [[ -d vendor ]]; then
  echo "${VERSION}" > vendor/.appek-version
  echo "🔢 vendor/.appek-version → ${VERSION}"
fi
if [[ -f b2b/index.html ]]; then
  sed -i.bak -E "s/(app\.js\?v=)[^\"\\']*/\1${VERSION}/g" b2b/index.html
  sed -i.bak -E "s/(style\.css\?v=)[^\"\\']*/\1${VERSION}/g" b2b/index.html
  sed -i.bak -E "s/(i18n\.js\?v=)[^\"\\']*/\1${VERSION}/g" b2b/index.html
  rm -f b2b/index.html.bak
  echo "🔢 b2b cache busters → ${VERSION}"
fi

# ─── 🔧 CSS BRACE CHECK — admin.css musí mít vyvážené { } ───────
# 🆕 v2.9.70 — bez tohohle šel zabalit rozbitý admin.css (chybějící „}"),
# kde půlka stylů spadla do @media a na PC se vůbec neaplikovala.
# Build teď SPADNE, když je CSS strukturálně rozbité.
if [[ -f scripts/css-braces.py ]]; then
  if python3 scripts/css-braces.py > /tmp/appek-braces.txt 2>&1; then
    echo "✅ admin.css — závorky { } vyvážené"
  else
    echo "❌ ABORT: admin.css má nevyvážené závorky — oprav před buildem:"
    cat /tmp/appek-braces.txt
    exit 1
  fi
fi

# ─── 🌍 i18n — REGENERACE PŘEKLADOVÉHO BUNDLU ──────────────────
# 🆕 v2.9.130 — i18n_extra.js (SK/DE overlay) se generuje ze
# scripts/i18n_dicts_extra.py přes scripts/gen_i18n_extra.py. Build ho teď
# regeneruje VŽDY — aby žádný balíček neobsahoval zastaralé překlady
# (předtím to byl ruční krok a snadno se zapomněl). Build SPADNE když gen selže.
# MUSÍ běžet PŘED deploy manifestem (jinak by hashe neseděly s i18n_extra.js).
if [[ -f scripts/gen_i18n_extra.py ]]; then
  if python3 scripts/gen_i18n_extra.py; then
    echo "✅ i18n_extra.js — překlady regenerovány ze slovníku"
  else
    echo "❌ ABORT: gen_i18n_extra.py selhal — oprav scripts/i18n_dicts_extra.py před buildem"
    exit 1
  fi
fi

# ─── 🗜️  MINIFIKACE admin.js (v3.0.251) ───────────────────────
# admin.js je ~2.1 MB nezminifikovaného zdroje a načítá se synchronně → parse
# blokuje boot (hlavně mobil/slabé zařízení). esbuild minifikace ~2.1 MB → ~1.2 MB.
# DŮLEŽITÉ pořadí: běží AŽ po všech text-editech admin.js (APPEK_ADMIN_JS_VERSION)
# a PŘED .build-manifest.json (aby SHA-256 seděl s nasazeným minifikovaným souborem).
# BEZPEČNOST: bez --bundle → globální handlery (top-level function / window.fn) se
# NEpřejmenují, takže inline onclick="fn()" zůstávají funkční. --charset=utf8 zachová
# české znaky + emoji. FAIL-SAFE: když esbuild chybí nebo výstup neprojde sanity
# checkem, ponechá se PLNÝ admin.js a build pokračuje (radši nezminifikováno než rozbito).
if [[ -f admin/admin.js ]]; then
  _JS_SRC_BYTES=$(wc -c < admin/admin.js | tr -d ' ')
  if npx --yes esbuild admin/admin.js --minify --charset=utf8 --legal-comments=none \
        --outfile=admin/admin.min.tmp 2>/tmp/appek-esbuild.log; then
    _JS_MIN_BYTES=$(wc -c < admin/admin.min.tmp | tr -d ' ')
    # Sanity: výstup je menší než zdroj, ne absurdně malý (>600 kB), a stále obsahuje
    # známé globální handlery volané z inline onclick (důkaz že mangling nesmazal globály).
    if [[ "$_JS_MIN_BYTES" -gt 600000 && "$_JS_MIN_BYTES" -lt "$_JS_SRC_BYTES" ]] \
       && grep -q "renderDashboard" admin/admin.min.tmp \
       && grep -q "renderObjednavky" admin/admin.min.tmp \
       && grep -q "ulozitNastaveni" admin/admin.min.tmp; then
      mv admin/admin.min.tmp admin/admin.js
      echo "🗜️  admin.js minifikován: $((_JS_SRC_BYTES/1024)) kB → $((_JS_MIN_BYTES/1024)) kB"
    else
      rm -f admin/admin.min.tmp
      echo "⚠️  Minifikace přeskočena (sanity check selhal) — ponechán plný admin.js"
    fi
  else
    rm -f admin/admin.min.tmp
    echo "⚠️  esbuild nedostupný/selhal — ponechán plný admin.js (build pokračuje)"
    head -3 /tmp/appek-esbuild.log 2>/dev/null || true
  fi
fi

# ─── 🔐 DEPLOY MANIFEST — SHA-256 každého klientského souboru ────
# 🆕 v2.9.65 — api/.build-manifest.json říká "co MÁ být na serveru po tomto buildu".
# admin/deploy-check.php to po deployi porovná s realitou na disku → pozná STALE
# soubory (admin.css/admin.js které zůstaly staré), NE jen verzi v patičce.
# MUSÍ běžet AŽ po všech version-bumpech (jinak by hashe neseděly s nasazeným kódem).
echo "🔐 Generuji api/.build-manifest.json (SHA-256 každého souboru)…"
python3 - "$VERSION" <<'PYMANIFEST'
import sys, os, json, hashlib, time

version = sys.argv[1]
root = os.getcwd()

# Top-level cesty které tvoří customer/demo instalaci (ne vendor/, ne sales/)
WALK_DIRS  = ['admin', 'api', 'b2b', 'pos', 'floorplan']
ROOT_FILES = ['index.php', 'install.php', 'instalace.html', '.htaccess', 'robots.txt']

def skip(rel):
    """Site-specific / runtime / artefakty — NIKDY nehashovat."""
    base = os.path.basename(rel)
    if base in ('.DS_Store', '.build-manifest.json', '.update-manifest.json',
                '.deploy-check.json', '.version', '.installed'):
        return True
    if base.endswith(('.local.php', '.log', '.bak', '.zip', '.sql')):
        return True
    # zálohy: admin.css.bak-v…, admin.css.STABLE-MENU-v… (.bak/STABLE uprostřed jména)
    if '.bak' in base or 'STABLE-MENU' in base:
        return True
    if '/zalohy/' in rel or rel.startswith('api/zalohy/'):
        return True
    if '/uploads/' in rel or rel.startswith('uploads/'):
        return True
    return False

files = {}
def add_file(abs_p, rel):
    rel = rel.replace(os.sep, '/')
    if skip(rel):
        return
    try:
        with open(abs_p, 'rb') as fh:
            data = fh.read()
    except OSError:
        return
    files[rel] = {'sha256': hashlib.sha256(data).hexdigest(), 'size': len(data)}

for d in WALK_DIRS:
    ad = os.path.join(root, d)
    if not os.path.isdir(ad):
        continue
    for cur, _, fns in os.walk(ad):
        for fn in fns:
            ap = os.path.join(cur, fn)
            add_file(ap, os.path.relpath(ap, root))

for f in ROOT_FILES:
    ap = os.path.join(root, f)
    if os.path.isfile(ap):
        add_file(ap, f)

manifest = {
    'version':    version,
    'built_at':   time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
    'file_count': len(files),
    'files':      dict(sorted(files.items())),
}
out = os.path.join(root, 'api', '.build-manifest.json')
with open(out, 'w', encoding='utf-8') as fh:
    json.dump(manifest, fh, indent=1, ensure_ascii=False)
print(f"  ✓ api/.build-manifest.json — {len(files)} souborů, verze {version}")
PYMANIFEST

# ─── Smaž staré ZIPy ────────────────────────────────────────────
rm -f "$CUSTOMER_OUT" "$MASTER_OUT"

# ─── Společné vyloučení (citlivé / dev artefakty) ───────────────
COMMON_EXCLUDES=(
  -x "*.zip"
  -x ".git/*" -x ".gitignore"
  -x ".github/*"
  -x ".DS_Store"
  -x ".vscode/*" -x ".idea/*" -x ".claude/*"
  -x "node_modules/*"
  -x "uploads/*"
  -x "api/.installed"
  -x "api/config.local.php"
  -x "api/vendor_db_config.local.php"
  -x "api/zalohy/*"
  -x "vendor/config.local.php"
  -x "vendor/.installed"
  -x "vendor/updates_storage/appek-update-*.zip"
  -x "vendor/updates_storage/.gitkeep"
  -x "*.log" -x "*.bak" -x "*.bak-*" -x "*STABLE-MENU*"
  -x "Snímek*.png"
  -x "APPEK_*/*"
  -x "build-zip.sh"
)

# ═══════════════════════════════════════════════════════════════
# 1️⃣  CUSTOMER ZIP — co se zabaluje pro zákazníka
# ═══════════════════════════════════════════════════════════════
echo "📦 [1/2] Building CUSTOMER ZIP: $CUSTOMER_OUT"
echo "        (api/, admin/, b2b/, pos/, demo/, install.php, index.php router)"

zip -r "$CUSTOMER_OUT" . \
  "${COMMON_EXCLUDES[@]}" \
  -x "vendor/*" \
  -x "sales/*" \
  -x "scripts/*" \
  -x "updates/*" \
  -x "index.html" \
  -x "checkout.html" \
  -x "sitemap.xml" \
  -x "CLAUDE.md" -x "SYNC_ARCHITECTURE.md" \
  > /dev/null

CUSTOMER_SIZE=$(ls -lh "$CUSTOMER_OUT" | awk '{print $5}')
CUSTOMER_COUNT=$(unzip -l "$CUSTOMER_OUT" | tail -1 | awk '{print $2}')
echo "        ✅ $CUSTOMER_SIZE · $CUSTOMER_COUNT souborů"
echo ""

# ═══════════════════════════════════════════════════════════════
# 2️⃣  MASTER ZIP — co nasadíš na vendor.appek.cz + appek.cz
#     Obsahuje sales + vendor + api v rootu
#     PLUS demo/ s plnou customer aplikací + pre-fillnutými demo credentials
#     (tj. jeden upload = appek.cz + vendor.appek.cz + demo.appek.cz hotovo)
# ═══════════════════════════════════════════════════════════════
echo "📦 [2/2] Building MASTER ZIP: $MASTER_OUT"
echo "        Root: vendor/, sales/, api/, admin/, b2b/, pos/, install.php — VŠE v jednom"
echo "        Demo/: PLNÁ customer aplikace s demo credentials (demo.appek.cz)"
echo "        🆕 v2.8.2 — MASTER teď obsahuje I admin/ + b2b/ + install.php (1 upload = vše)"

# Krok 2a — Main master content (root — VŠE kromě dev artefaktů + starého demo/)
zip -r "$MASTER_OUT" . \
  "${COMMON_EXCLUDES[@]}" \
  -x "instalace.html" \
  -x "scripts/*" \
  -x "deploy/*" \
  -x "demo/*" \
  -x "SYNC_ARCHITECTURE.md" -x "INSTALL.md" -x "CLAUDE.md" \
  > /dev/null

# Krok 2b — Postav demo overlay s plnou customer aplikací
DEMO_OVERLAY="/tmp/appek-master-demo-overlay-$$"
mkdir -p "$DEMO_OVERLAY/demo"

# Customer-relevant složky a soubory do demo/
for p in api admin b2b install.php; do
  [[ -e "$p" ]] && cp -R "$p" "$DEMO_OVERLAY/demo/"
done

# Landing page + .htaccess + SETUP z source demo/
for f in demo/index.html demo/.htaccess demo/SETUP.md; do
  [[ -f "$f" ]] && cp "$f" "$DEMO_OVERLAY/demo/$(basename "$f")"
done

# Demo seed SQL:
#   demo-seed-full.sql  = KOMPLETNÍ (DROP + CREATE + INSERT) — pro první import přes phpMyAdmin
#   demo-seed.sql       = jen INSERTy (pro hodinový reset přes admin_reset_demo.php)
[[ -f deploy/demo-seed-full.sql ]] && cp deploy/demo-seed-full.sql "$DEMO_OVERLAY/demo/demo-seed-full.sql"
[[ -f deploy/demo-seed.sql       ]] && cp deploy/demo-seed.sql       "$DEMO_OVERLAY/demo/demo-seed.sql"

# Vyčistit citlivé / nadbytečné soubory v demo overlay
rm -f "$DEMO_OVERLAY/demo/api/config.local.php" 2>/dev/null
rm -f "$DEMO_OVERLAY/demo/api/.installed" 2>/dev/null
rm -rf "$DEMO_OVERLAY/demo/api/zalohy" 2>/dev/null
# Zálohy (admin.css.bak-…, *.STABLE-MENU-…) — do demo overlay nepatří, jen bobtnají MASTER
rm -f "$DEMO_OVERLAY"/demo/admin/*.bak "$DEMO_OVERLAY"/demo/admin/*.bak-* "$DEMO_OVERLAY"/demo/admin/*STABLE-MENU* 2>/dev/null

# Pre-fillnuté demo config.local.php
cat > "$DEMO_OVERLAY/demo/api/config.local.php" <<'DEMOCFG'
<?php
/**
 * 🧪 DEMO CONFIG — auto-generated by build-zip.sh (master)
 * Pro demo.appek.cz na Hostinger.
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'u880385154_appek_demo');
define('DB_USER', 'u880385154_appek_demo');
define('DB_PASS', 'Karkulka55+');

define('APP_LICENSE_KEY', 'APPEK-BED9-RG9D-MRV8-AAA9-8FBC');

define('APPEK_DEMO_MODE', true);
define('APPEK_DEMO_EMAIL',         'demo@appek.cz');
define('APPEK_DEMO_PASSWORD',      'demo1234');
define('APPEK_DEMO_B2B_EMAIL',     'odberatel@demo.cz');
define('APPEK_DEMO_B2B_PASSWORD',  'demo1234');
define('APPEK_DEMO_SESSION_TTL', 900);

ini_set('session.gc_maxlifetime', '900');
ini_set('session.cookie_lifetime', '900');
DEMOCFG

# .installed flag — přeskočí install wizard
echo "Master deployment $(date +%Y-%m-%d) — v${VERSION}" > "$DEMO_OVERLAY/demo/api/.installed"

# Přidat demo overlay do master ZIPu (absolutní cesta!)
ABS_MASTER="$(pwd)/$MASTER_OUT"
(cd "$DEMO_OVERLAY" && zip -urq "$ABS_MASTER" demo/)
rm -rf "$DEMO_OVERLAY"

# 🆕 v2.0.71 — Build PROPER BUNDLE pomocí scripts/build-update.sh a embed do MASTERu.
# Tím update bundle MÁ manifest.json + files/ subfolder (= formát co OLD 2.0.63 zákazníci
# očekávají). NEW 2.0.71+ má univerzální podporu (bundle + raw), takže fungují oba.
# Tím se opraví bug: 2.0.63 → 2.0.71 update by jinak selhal kvůli formátu.
echo "📦 [3/3] Building UPDATE BUNDLE: appek-update-${VERSION}.zip (manifest + files/)"
BUNDLE_OUT="appek-update-${VERSION}.zip"
rm -f "$BUNDLE_OUT"
if [[ -f scripts/build-update.sh ]]; then
  bash scripts/build-update.sh "$VERSION" 2>&1 | tail -5
fi

if [[ -f "$BUNDLE_OUT" ]]; then
  echo "📦 Embed update BUNDLE do MASTER · vendor/updates_storage/${BUNDLE_OUT}"
  EMBED_DIR="/tmp/appek-embed-bundle-$$"
  mkdir -p "$EMBED_DIR/vendor/updates_storage"
  cp "$BUNDLE_OUT" "$EMBED_DIR/vendor/updates_storage/${BUNDLE_OUT}"
  (cd "$EMBED_DIR" && zip -urq "$ABS_MASTER" vendor/updates_storage/)
  rm -rf "$EMBED_DIR"
  BUNDLE_SIZE=$(ls -lh "$BUNDLE_OUT" | awk '{print $5}')
  echo "        ✅ Bundle embedded · $BUNDLE_SIZE (bundle format = backward compatible s 2.0.63+)"
else
  echo "        ⚠️ build-update.sh selhal, embeduji raw customer ZIP jako fallback"
  EMBED_DIR="/tmp/appek-embed-customer-$$"
  mkdir -p "$EMBED_DIR/vendor/updates_storage"
  cp "$CUSTOMER_OUT" "$EMBED_DIR/vendor/updates_storage/appek-update-${VERSION}.zip"
  (cd "$EMBED_DIR" && zip -urq "$ABS_MASTER" vendor/updates_storage/)
  rm -rf "$EMBED_DIR"
fi

MASTER_SIZE=$(ls -lh "$MASTER_OUT" | awk '{print $5}')
MASTER_COUNT=$(unzip -l "$MASTER_OUT" | tail -1 | awk '{print $2}')
echo "        ✅ $MASTER_SIZE · $MASTER_COUNT souborů (z toho demo/ obsahuje plný customer install)"
echo ""

# ─── Auto-copy na plochu ────────────────────────────────────────
DESKTOP="$HOME/Desktop"
if [[ -d "$DESKTOP" ]]; then
  cp "$CUSTOMER_OUT" "$MASTER_OUT" "$DESKTOP/"
  echo "📥 Zkopírováno na plochu: $DESKTOP"
fi

echo ""
echo "═══════════════════════════════════════════════"
echo "✅ BUILD HOTOVÝ — APPEK v${VERSION}"
echo "═══════════════════════════════════════════════"
echo ""
echo "📤 MASTER ZIP    → ${MASTER_OUT}"
echo "   Nahraj na vendor.appek.cz hosting (deploy produkce)"
echo "   Obsahuje: vendor + sales + api + demo + root"
echo ""
echo "📦 CUSTOMER ZIP  → ${CUSTOMER_OUT}"
echo "   Dej zákazníkovi po zaplacení (přes update modul nebo manuálně)"
echo "   Obsahuje: api + admin + b2b + demo + install.php"
echo ""
echo "💡 Pro update bundle pro existující zákazníky:"
echo "   ./scripts/build-update.sh ${VERSION}"
