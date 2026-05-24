#!/bin/bash
# 🔄 SYNC-LOCAL — kopíruje git working dir do lokálního XAMPP / MAMP htdocs
# pro rychlé testování bez release+deploy cyklu.
#
# Použití:
#   ./scripts/sync-local.sh                      # sync do default XAMPP htdocs/appek
#   ./scripts/sync-local.sh /path/to/htdocs      # custom cíl
#   ./scripts/sync-local.sh --dry-run            # ukázat co by se kopírovalo, bez akce
#
# Co dělá:
#   - rsync s --delete (přesný mirror, smaže soubory co v gitu nejsou)
#   - exclude: .git/, .DS_Store, *.log, .bak/, scripts/, build artefakty
#   - zachovává XAMPP-specific .user.ini, .htaccess (pokud existují)

set -e

DRY_RUN=false
TARGET=""
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=true ;;
    *) TARGET="$arg" ;;
  esac
done

# Default XAMPP htdocs (macOS)
DEFAULT_TARGET="/Applications/XAMPP/xamppfiles/htdocs/appek"
TARGET="${TARGET:-$DEFAULT_TARGET}"

# Source = root projektu (parent dir tohoto skriptu)
SOURCE="$(cd "$(dirname "$0")/.." && pwd)"

echo "🔄 SYNC LOCAL"
echo "   Source: $SOURCE"
echo "   Target: $TARGET"
echo "   Dry-run: $DRY_RUN"
echo ""

if [[ ! -d "$TARGET" ]]; then
  echo "❌ Cíl '$TARGET' neexistuje. Vytvoř ručně nebo zadej jinou cestu jako argument."
  echo "   Příklad: ./scripts/sync-local.sh /Applications/MAMP/htdocs/appek"
  exit 1
fi

# Backup .htaccess a .user.ini z target (pokud existují) — XAMPP může mít vlastní
# konfigurace co nechceš přepsat
if [[ -f "$TARGET/.htaccess" ]] && [[ "$DRY_RUN" == false ]]; then
  cp "$TARGET/.htaccess" "/tmp/appek-htaccess-$$.bak"
  echo "💾 .htaccess backup → /tmp/appek-htaccess-$$.bak"
fi
if [[ -f "$TARGET/.user.ini" ]] && [[ "$DRY_RUN" == false ]]; then
  cp "$TARGET/.user.ini" "/tmp/appek-userini-$$.bak"
  echo "💾 .user.ini backup → /tmp/appek-userini-$$.bak"
fi

RSYNC_OPTS=(
  -av                                      # archive + verbose
  --delete                                 # smaž v target co není v source
  --exclude='.git/'                        # nikdy nesync .git
  --exclude='.DS_Store'                    # macOS junk
  --exclude='*.log'
  --exclude='*.bak'
  --exclude='*.zip'
  --exclude='node_modules/'
  --exclude='.idea/'
  --exclude='.vscode/'
  --exclude='/scripts/'                    # build skripty nepotřebujeme na serveru
  --exclude='/uploads/'                    # site-specific data zachovat
  --exclude='/api/zalohy/'                 # zálohy zachovat
  --exclude='/api/.update-manifest.json'   # generuje deploy
  --exclude='/api/.build-manifest.json'
  --exclude='/api/config.local.php'        # site-specific creds
  --exclude='/vendor/_local.php'
  --exclude='/SETUP-IMAC.md'
  --exclude='/README.md'
  --exclude='/build-zip.sh'
)

if [[ "$DRY_RUN" == true ]]; then
  RSYNC_OPTS+=("--dry-run")
fi

rsync "${RSYNC_OPTS[@]}" "$SOURCE/" "$TARGET/"

# Restore .htaccess + .user.ini pokud rsync je smazal
if [[ -f "/tmp/appek-htaccess-$$.bak" ]] && [[ "$DRY_RUN" == false ]]; then
  cp "/tmp/appek-htaccess-$$.bak" "$TARGET/.htaccess"
  rm -f "/tmp/appek-htaccess-$$.bak"
  echo "♻️ .htaccess restored"
fi
if [[ -f "/tmp/appek-userini-$$.bak" ]] && [[ "$DRY_RUN" == false ]]; then
  cp "/tmp/appek-userini-$$.bak" "$TARGET/.user.ini"
  rm -f "/tmp/appek-userini-$$.bak"
  echo "♻️ .user.ini restored"
fi

if [[ "$DRY_RUN" == true ]]; then
  echo ""
  echo "🔍 Dry-run hotov. Pro skutečný sync spusť bez --dry-run."
else
  echo ""
  echo "✅ Sync hotov. Otevři http://localhost/appek/admin/ a hard-refresh (Cmd+Shift+R)."
fi
