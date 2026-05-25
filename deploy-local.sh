#!/bin/bash
# 🚀 Deploy aktuální dev verze do XAMPP htdocs (pro lokální testing)
#
# Použití:
#   ./deploy-local.sh           — deploy + zachovat installed stav
#   ./deploy-local.sh --reset   — deploy + smazat .installed a config.local.php (čistá instalace)
#   ./deploy-local.sh --no-vendor — deploy jen appek, vendor skip

set -e

DEV_DIR="/Users/chossekilaimac/projects/appek.cz"
XAMPP_APPEK="/Applications/XAMPP/htdocs/appek"
XAMPP_VENDOR="/Applications/XAMPP/htdocs/vendor"

RESET=false
SKIP_VENDOR=false

for arg in "$@"; do
  case "$arg" in
    --reset)      RESET=true ;;
    --no-vendor)  SKIP_VENDOR=true ;;
    *) echo "Neznámý parametr: $arg"; exit 1 ;;
  esac
done

echo "📦 Deploying $DEV_DIR → $XAMPP_APPEK"

# Reset režim — smaž stav instalace (s automatickou zálohou config.local.php)
if [ "$RESET" = true ]; then
  echo "🔄 RESET — mažu .installed + config.local.php (záloha do .bak)"
  [ -f "$XAMPP_APPEK/api/config.local.php" ] && cp "$XAMPP_APPEK/api/config.local.php" "$XAMPP_APPEK/api/config.local.php.bak-$(date +%s)"
  rm -f "$XAMPP_APPEK/api/.installed" "$XAMPP_APPEK/api/config.local.php"
fi

# Hlavní rsync — Appek
rsync -a --delete \
  --exclude='.git/' --exclude='.gitignore' --exclude='.DS_Store' \
  --exclude='.claude/' --exclude='.github/' \
  --exclude='CLAUDE.md' --exclude='SYNC_ARCHITECTURE.md' \
  --exclude='scripts/' --exclude='vendor/' --exclude='APPEK_*/' \
  --exclude='build-zip.sh' --exclude='deploy-local.sh' \
  --exclude='api/.installed' --exclude='api/config.local.php' \
  --exclude='api/zalohy/' --exclude='uploads/' \
  --exclude='*.log' --exclude='*.bak' --exclude='*.zip' \
  --exclude='node_modules/' --exclude='.vscode/' --exclude='.idea/' \
  --exclude='Snímek*.png' \
  "$DEV_DIR/" "$XAMPP_APPEK/"

# Vytvoř writable složky
mkdir -p "$XAMPP_APPEK/uploads" "$XAMPP_APPEK/api/zalohy"

# Vendor (volitelné)
if [ "$SKIP_VENDOR" = false ]; then
  echo "🏢 Deploying vendor panel → $XAMPP_VENDOR"
  rsync -a "$DEV_DIR/vendor/" "$XAMPP_VENDOR/"
  if [ "$RESET" = true ]; then
    echo "🔄 RESET vendor — záloha config.local.php do .bak"
    [ -f "$XAMPP_VENDOR/config.local.php" ] && cp "$XAMPP_VENDOR/config.local.php" "$XAMPP_VENDOR/config.local.php.bak-$(date +%s)"
    rm -f "$XAMPP_VENDOR/.installed" "$XAMPP_VENDOR/config.local.php"
  fi
fi

# Oprávnění pro XAMPP (daemon user)
chmod -R 777 "$XAMPP_APPEK/api" "$XAMPP_APPEK/uploads" 2>/dev/null || true
[ "$SKIP_VENDOR" = false ] && chmod -R 777 "$XAMPP_VENDOR" 2>/dev/null || true

echo ""
echo "✅ Hotovo!"
echo ""
echo "🍞 Appek:  http://localhost/appek/$([ ! -f "$XAMPP_APPEK/api/.installed" ] && echo 'install.php' || echo 'admin/')"
[ "$SKIP_VENDOR" = false ] && echo "🏢 Vendor: http://localhost/vendor/$([ ! -f "$XAMPP_VENDOR/.installed" ] && echo 'install.php' || echo '')"
echo ""
[ "$RESET" = true ] && echo "💡 Vytvoř nové DB v phpMyAdmin (http://localhost/phpmyadmin):"
[ "$RESET" = true ] && echo "   • appek         (utf8mb4_unicode_ci)"
[ "$RESET" = true ] && [ "$SKIP_VENDOR" = false ] && echo "   • appek_vendor  (utf8mb4_unicode_ci)"
