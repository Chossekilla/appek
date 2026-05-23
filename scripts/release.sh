#!/bin/bash
# 🏷️ APPEK RELEASE — bump verze v api/config.php, commit, tag, push.
# Použití: ./scripts/release.sh 2.9.142  [--dry-run]
set -e

VERSION=""
DRY_RUN=false
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=true ;;
    *) VERSION="$arg" ;;
  esac
done

if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "❌ Verze musí být X.Y.Z. Použití: ./scripts/release.sh X.Y.Z [--dry-run]"
  exit 1
fi

cd "$(dirname "$0")/.."

BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [[ "$BRANCH" != "main" ]]; then
  echo "❌ Nejsi na 'main' (jsi na '$BRANCH'). Release jen z main."
  exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
  echo "❌ Pracovní strom není čistý — nejdřív zacommituj práci:"
  git status --short
  exit 1
fi

git fetch origin main --quiet
if [[ -n "$(git rev-list HEAD..origin/main)" ]]; then
  echo "❌ 'main' je pozadu za origin/main. Udělej 'git pull'."
  exit 1
fi

if git rev-parse "v$VERSION" >/dev/null 2>&1; then
  echo "❌ Tag v$VERSION už existuje."
  exit 1
fi

CUR=$(sed -nE "s/.*APP_VERSION'[^']*'([0-9]+\.[0-9]+\.[0-9]+)'.*/\1/p" api/config.php | head -1)
echo "🏷️  Release v$VERSION  (současná APP_VERSION: ${CUR:-?})  dry-run: $DRY_RUN"

if [[ "$DRY_RUN" == true ]]; then
  echo "   [dry-run] bump api/config.php → $VERSION, commit 'chore: release v$VERSION', tag v$VERSION, push main+tag"
  exit 0
fi

if [[ "$CUR" != "$VERSION" ]]; then
  sed -i.bak -E "s/(define\('APP_VERSION'[^']*')[^']*('\s*\)\s*;)/\1${VERSION}\2/" api/config.php
  rm -f api/config.php.bak

  # 🐛 fix v2.9.201 — auto-bump cache-bust ?v=X.Y.Z v admin/b2b assets,
  # jinak browsery cachují staré admin.js + style.css a uživatel vidí dnes
  # mě nesouvislé chyby ('Výrobní list je furt v menu' atd.).
  for f in admin/index.html b2b/index.html; do
    if [[ -f "$f" ]]; then
      sed -i.bak -E "s/\?v=[0-9]+\.[0-9]+\.[0-9]+/?v=${VERSION}/g" "$f"
      rm -f "${f}.bak"
      git add "$f"
    fi
  done

  # 🐛 fix v2.9.223 — bump APPEK_ADMIN_JS_VERSION (v admin.js) + --appek-css-version
  # (v admin.css), jinak detectStaleCode() semverCompare hlásí 'stale code' a může
  # triggerovat infinite cache-clear loop, když existuje api/.update-manifest.json
  # s vyšší verzí. Také footer ukazuje warning při version mismatch.
  if [[ -f admin/admin.js ]]; then
    sed -i.bak -E "s/(APPEK_ADMIN_JS_VERSION\s*=\s*')[0-9]+\.[0-9]+\.[0-9]+/\1${VERSION}/" admin/admin.js
    rm -f admin/admin.js.bak
    git add admin/admin.js
  fi
  if [[ -f admin/admin.css ]]; then
    sed -i.bak -E "s/(--appek-css-version:\s*\")[0-9]+\.[0-9]+\.[0-9]+/\1${VERSION}/" admin/admin.css
    rm -f admin/admin.css.bak
    git add admin/admin.css
  fi

  git add api/config.php
  git commit -m "chore: release v$VERSION"
fi

git tag -a "v$VERSION" -m "Release v$VERSION"
git push origin main
git push origin "v$VERSION"
echo "✅ v$VERSION vydáno. GitHub Actions teď staví a nasazuje:"
echo "   https://github.com/Chossekilla/appek/actions"
