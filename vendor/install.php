<?php
/**
 * 🚀 VENDOR INSTALL — startovací hlavní instalátor pro vendor.appek.cz
 *
 * Co tenhle wizard nastaví:
 *   1. DB credentials   → vendor/config.local.php
 *   2. První admin účet → vendor_users
 *   3. Stub balíčků     → packages (Core + Cukrárna + Lahůdky + Restaurace + Catering + Sezónní)
 *
 * Po dokončení vidíš v portálu:
 *   🔑 Licence  (správa licenčních klíčů zákazníků)
 *   👥 Přístupy (admin / B2B / vendor uživatelé napříč subdoménami)
 *   🛒 Eshop    (digitální prodej na frontpage appek.cz)
 *   🎁 Balíčky  (Core + add-on balíčky a jejich funkce)
 *   ✏️ Web      (editor stránek)
 *   🏢 Firma    (kontaktní údaje firmy)
 *
 * Po dokončení smaž tento soubor (nebo přejmenuj na .bak).
 */

session_start();

// 🔒 v3.0.384 SECURITY — Blok po dokončení BEZ ?force bypassu.
//   Dřív: ?force obešel guard → neautentizovaný re-run přepsal config.local.php (DB credentials)
//   ve step 2 + admin heslo (ON DUPLICATE KEY UPDATE) ve step 3 = takeover vendoru.
//   Teď: pokud je nainstalováno (.installed NEBO config.local.php existuje), zablokuj úplně.
//   Re-instalace = smazat config.local.php (+ .installed) na serveru (admin má SSH/FM přístup).
if (file_exists(__DIR__ . '/.installed') || file_exists(__DIR__ . '/config.local.php')) {
    header('Location: index.php');
    exit;
}

// =============================================================
// 🌍 i18n — instalátor CS / EN / ES
// =============================================================
$supportedLangs = ['cs', 'en', 'es'];
if (isset($_GET['lang']) && in_array($_GET['lang'], $supportedLangs, true)) {
    $_SESSION['vendor_install_lang'] = $_GET['lang'];
}
if (empty($_SESSION['vendor_install_lang'])) {
    $browserLang = strtolower(substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'cs', 0, 2));
    $_SESSION['vendor_install_lang'] = in_array($browserLang, $supportedLangs, true) ? $browserLang : 'cs';
}
$LANG = $_SESSION['vendor_install_lang'];

$T = [
    'app_title'   => ['cs' => '🏢 APPEK Master — Instalace', 'en' => '🏢 APPEK Master — Installation', 'es' => '🏢 APPEK Master — Instalación'],
    'app_sub'     => ['cs' => 'Startovací wizard pro vendor portál (správa licencí, balíčků, digitálního eshopu).', 'en' => 'Setup wizard for the vendor portal (license, package, and digital eshop management).', 'es' => 'Asistente de configuración del portal vendor (licencias, paquetes, tienda digital).'],

    'step_welcome' => ['cs' => 'Úvod', 'en' => 'Welcome', 'es' => 'Inicio'],
    'step_db'      => ['cs' => 'Databáze', 'en' => 'Database', 'es' => 'Base de datos'],
    'step_admin'   => ['cs' => 'Admin účet', 'en' => 'Admin account', 'es' => 'Cuenta admin'],
    'step_brand'   => ['cs' => 'Vaše firma', 'en' => 'Your company', 'es' => 'Tu empresa'],
    'step_done'    => ['cs' => 'Hotovo', 'en' => 'Done', 'es' => 'Listo'],

    // Welcome
    'welcome_h'    => ['cs' => '👋 Vítej v APPEK Master', 'en' => '👋 Welcome to APPEK Master', 'es' => '👋 Bienvenido a APPEK Master'],
    'welcome_p'    => ['cs' => 'Tenhle 3-krokový wizard nastaví tvůj <strong>vendor portál</strong> na vendor.appek.cz. Po dokončení dostaneš centrální místo pro správu celého ekosystému APPEK.', 'en' => 'This 3-step wizard sets up your <strong>vendor portal</strong> at vendor.appek.cz. When done you get one place to run the entire APPEK ecosystem.', 'es' => 'Este asistente de 3 pasos configura tu <strong>portal vendor</strong> en vendor.appek.cz. Al terminar tendrás un lugar central para todo el ecosistema APPEK.'],
    'welcome_what' => ['cs' => 'Co budeš mít v portálu:', 'en' => 'What you get in the portal:', 'es' => 'Lo que tendrás en el portal:'],

    'f_licenses_t' => ['cs' => '🔑 Správa licencí', 'en' => '🔑 License management', 'es' => '🔑 Gestión de licencias'],
    'f_licenses_d' => ['cs' => 'Vystavuj licenční klíče zákazníkům (APPEK-XXXX-XXXX-XXXX-XXXX), s časovým platformem a balíčky.', 'en' => 'Issue customer license keys (APPEK-XXXX-XXXX-XXXX-XXXX) with expiry and package flags.', 'es' => 'Emite claves de licencia para clientes (APPEK-XXXX-XXXX-XXXX-XXXX) con caducidad y paquetes.'],
    'f_access_t'   => ['cs' => '👥 Přístupy demo / B2B / admin', 'en' => '👥 Access: demo / B2B / admin', 'es' => '👥 Acceso: demo / B2B / admin'],
    'f_access_d'   => ['cs' => 'Jeden pohled na všechny uživatele napříč subdoménami — aktivace, deaktivace, reset hesla.', 'en' => 'One view of all users across subdomains — activate, deactivate, reset password.', 'es' => 'Una vista de todos los usuarios entre subdominios — activar, desactivar, restablecer contraseña.'],
    'f_shop_t'     => ['cs' => '🛒 Digitální eshop', 'en' => '🛒 Digital eshop', 'es' => '🛒 Tienda digital'],
    'f_shop_d'     => ['cs' => 'Prodej licencí a balíčků přímo z appek.cz (Stripe, GoPay, převod). Faktura + e-mail s klíčem automaticky.', 'en' => 'Sell licenses and packages directly from appek.cz (Stripe, GoPay, bank transfer). Invoice + key e-mail automatically.', 'es' => 'Vende licencias y paquetes desde appek.cz (Stripe, GoPay, transferencia). Factura + clave por e-mail automáticamente.'],
    'f_packages_t' => ['cs' => '🎁 Balíčky a funkce', 'en' => '🎁 Packages and features', 'es' => '🎁 Paquetes y funciones'],
    'f_packages_d' => ['cs' => '6 startovacích balíčků (Core + Cukrárna + Lahůdky + Restaurace + Catering + Sezónní). Funkce se zapínají bitmaskou v klíči.', 'en' => '6 starter packages (Core + Patisserie + Deli + Restaurant + Catering + Seasonal). Features toggle via key bitmask.', 'es' => '6 paquetes iniciales (Core + Pastelería + Charcutería + Restaurante + Catering + Temporada). Las funciones se activan por máscara de bits en la clave.'],

    'welcome_btn'  => ['cs' => '🚀 Začít instalaci', 'en' => '🚀 Start installation', 'es' => '🚀 Iniciar instalación'],

    // DB step
    'db_h'         => ['cs' => '🗄️ Databáze', 'en' => '🗄️ Database', 'es' => '🗄️ Base de datos'],
    'db_p'         => ['cs' => 'Zadej přístupy k MySQL databázi pro vendor portál. Tabulky mají prefix <code>vendor_</code>.', 'en' => 'Enter MySQL credentials for the vendor portal. Tables are prefixed <code>vendor_</code>.', 'es' => 'Introduce las credenciales MySQL para el portal vendor. Tablas con prefijo <code>vendor_</code>.'],
    'db_host'      => ['cs' => 'Host',    'en' => 'Host',        'es' => 'Host'],
    'db_port'      => ['cs' => 'Port',    'en' => 'Port',        'es' => 'Puerto'],
    'db_name'      => ['cs' => 'Název DB','en' => 'DB name',     'es' => 'Nombre BD'],
    'db_user'      => ['cs' => 'Uživatel','en' => 'User',        'es' => 'Usuario'],
    'db_pass'      => ['cs' => 'Heslo',   'en' => 'Password',    'es' => 'Contraseña'],
    'db_btn'       => ['cs' => '🔌 Připojit & uložit', 'en' => '🔌 Connect & save', 'es' => '🔌 Conectar y guardar'],
    'db_xampp'     => ['cs' => '🧪 XAMPP test režim — DB <code>appek_vendor</code> (vytvoř v phpMyAdmin pokud chybí) · <code>root</code> · prázdné heslo', 'en' => '🧪 XAMPP test mode — DB <code>appek_vendor</code> (create in phpMyAdmin if missing) · <code>root</code> · empty password', 'es' => '🧪 Modo XAMPP — BD <code>appek_vendor</code> (crear en phpMyAdmin si falta) · <code>root</code> · contraseña vacía'],

    // Admin step
    'admin_h'      => ['cs' => '👤 První admin účet', 'en' => '👤 First admin account', 'es' => '👤 Primera cuenta admin'],
    'admin_p'      => ['cs' => 'Vytvoř si admin účet pro přihlášení do vendor portálu.', 'en' => 'Create the admin account for the vendor portal.', 'es' => 'Crea la cuenta admin para el portal vendor.'],
    'admin_user'   => ['cs' => 'Username (login)', 'en' => 'Username (login)', 'es' => 'Usuario (login)'],
    'admin_name'   => ['cs' => 'Jméno (volitelné)', 'en' => 'Name (optional)', 'es' => 'Nombre (opcional)'],
    'admin_pw'     => ['cs' => 'Heslo (min 10)', 'en' => 'Password (min 10)', 'es' => 'Contraseña (mín 10)'],
    'admin_pw2'    => ['cs' => 'Heslo znovu', 'en' => 'Password again', 'es' => 'Repetir contraseña'],
    'admin_btn'    => ['cs' => '✅ Vytvořit účet', 'en' => '✅ Create account', 'es' => '✅ Crear cuenta'],
    'admin_xampp'  => ['cs' => '🧪 XAMPP test režim — předvyplněno: <code>admin</code> / <code>admin123456</code>', 'en' => '🧪 XAMPP test mode — pre-filled: <code>admin</code> / <code>admin123456</code>', 'es' => '🧪 Modo XAMPP — precargado: <code>admin</code> / <code>admin123456</code>'],

    // Brand step (vendor company info)
    'brand_h'      => ['cs' => '🏢 Údaje vaší firmy', 'en' => '🏢 Your company details', 'es' => '🏢 Datos de tu empresa'],
    'brand_p'      => ['cs' => 'Tyto údaje se zobrazí v eshopu, fakturách a e-mailech zákazníkům. Vše lze později upravit v <strong>Firma</strong>.', 'en' => 'These details appear in the shop, invoices, and customer e-mails. You can edit them later in <strong>Company</strong>.', 'es' => 'Estos datos aparecen en la tienda, facturas y correos de clientes. Se pueden editar luego en <strong>Empresa</strong>.'],
    'brand_name'   => ['cs' => 'Obchodní jméno', 'en' => 'Business name', 'es' => 'Nombre comercial'],
    'brand_name_ph'=> ['cs' => 'APPEK s.r.o.', 'en' => 'ACME Ltd.', 'es' => 'ACME S.L.'],
    'brand_email'  => ['cs' => 'Hlavní kontaktní e-mail', 'en' => 'Main contact e-mail', 'es' => 'Correo de contacto principal'],
    'brand_phone'  => ['cs' => 'Telefon (volitelné)', 'en' => 'Phone (optional)', 'es' => 'Teléfono (opcional)'],
    'brand_country'=> ['cs' => 'Země', 'en' => 'Country', 'es' => 'País'],
    'brand_lang'   => ['cs' => 'Hlavní jazyk frontendu', 'en' => 'Frontend default language', 'es' => 'Idioma por defecto'],
    'brand_seg'    => ['cs' => 'Hlavní segment zákazníků (volitelné — pro nabídku balíčků)', 'en' => 'Main customer segment (optional — for package offers)', 'es' => 'Segmento principal de clientes (opcional — para ofertas)'],
    'brand_seg_universal' => ['cs' => '🌐 Univerzální (všechny segmenty)', 'en' => '🌐 Universal (all segments)', 'es' => '🌐 Universal (todos los segmentos)'],
    'brand_seg_bakery'    => ['cs' => '🥖 Pekárny, cukrárny',           'en' => '🥖 Bakeries, patisseries',     'es' => '🥖 Panaderías, pastelerías'],
    'brand_seg_restaurant'=> ['cs' => '🍽️ Restaurace, gastro',          'en' => '🍽️ Restaurants, gastro',       'es' => '🍽️ Restaurantes, gastro'],
    'brand_seg_catering'  => ['cs' => '🎉 Catering, eventy',            'en' => '🎉 Catering, events',          'es' => '🎉 Catering, eventos'],
    'brand_seg_production'=> ['cs' => '🏭 Výrobní provozy (B2B)',       'en' => '🏭 Production businesses (B2B)','es' => '🏭 Negocios de producción (B2B)'],
    'brand_seg_retail'    => ['cs' => '🛒 Prodejny, e-shopy',           'en' => '🛒 Retail, e-shops',           'es' => '🛒 Comercio, tiendas online'],
    'brand_btn'    => ['cs' => '💾 Uložit a pokračovat', 'en' => '💾 Save & continue', 'es' => '💾 Guardar y continuar'],
    'brand_skip'   => ['cs' => 'Přeskočit (vyplnit později)', 'en' => 'Skip (fill later)', 'es' => 'Saltar (rellenar luego)'],
    'brand_err_name'  => ['cs' => 'Obchodní jméno je povinné.', 'en' => 'Business name is required.', 'es' => 'El nombre comercial es obligatorio.'],
    'brand_err_email' => ['cs' => 'Neplatný e-mail.', 'en' => 'Invalid e-mail.', 'es' => 'Correo no válido.'],

    // Done
    'done_h'       => ['cs' => '🎉 Hotovo!', 'en' => '🎉 Done!', 'es' => '🎉 ¡Listo!'],
    'done_p'       => ['cs' => 'Vendor portál je nainstalovaný a připravený.', 'en' => 'Vendor portal is installed and ready.', 'es' => 'Portal vendor instalado y listo.'],
    'done_warn'    => ['cs' => '🔒 SMAŽ <code>vendor/install.php</code> z bezpečnostních důvodů.', 'en' => '🔒 DELETE <code>vendor/install.php</code> for security.', 'es' => '🔒 ELIMINA <code>vendor/install.php</code> por seguridad.'],
    'done_btn'     => ['cs' => '🚪 Přihlásit se', 'en' => '🚪 Sign in', 'es' => '🚪 Iniciar sesión'],

    // Errors
    'err_fill'     => ['cs' => 'Vyplň název DB a uživatele.', 'en' => 'Fill in DB name and user.', 'es' => 'Rellena nombre BD y usuario.'],
    'err_db'       => ['cs' => 'DB chyba: ', 'en' => 'DB error: ', 'es' => 'Error BD: '],
    'err_db_generic' => ['cs' => 'zkontrolujte údaje a server log.', 'en' => 'check the details and server log.', 'es' => 'compruebe los datos y el registro del servidor.'],
    'err_write'    => ['cs' => 'Nelze zapsat vendor/config.local.php — zkontroluj oprávnění složky.', 'en' => 'Cannot write vendor/config.local.php — check folder permissions.', 'es' => 'No se puede escribir vendor/config.local.php — revisa permisos.'],
    'err_user'     => ['cs' => 'Username: 3-32 znaků, jen a-z/0-9/_.', 'en' => 'Username: 3-32 chars, a-z/0-9/_ only.', 'es' => 'Usuario: 3-32 caracteres, sólo a-z/0-9/_.'],
    'err_pw_len'   => ['cs' => 'Heslo: min 10 znaků (jsi dodavatel — měj silné).', 'en' => 'Password: min 10 chars (you are the vendor — make it strong).', 'es' => 'Contraseña: mín 10 caracteres (eres el vendor — que sea fuerte).'],
    'err_pw_diff'  => ['cs' => 'Hesla se neshodují.', 'en' => 'Passwords do not match.', 'es' => 'Las contraseñas no coinciden.'],
];

function t(string $key): string {
    global $T, $LANG;
    return $T[$key][$LANG] ?? $T[$key]['cs'] ?? $key;
}

// Detekce localhost pro auto-fill defaultů
$isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '::1'])
            || str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost:')
            || ($_SERVER['SERVER_ADDR'] ?? '') === '127.0.0.1';

$step = (int) ($_GET['step'] ?? 0);
$err  = null;
$ok   = null;

if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['db_host'] ?? 'localhost');
    $port = (int) ($_POST['db_port'] ?? 3306);
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';

    if (!$name || !$user) { $err = t('err_fill'); }
    else {
        try {
            $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->query("SELECT 1");

            $cfg = "<?php\n"
                 . "// 🏢 Vendor panel config — auto-generated by install.php\n"
                 . "define('VENDOR_DB_HOST', '" . addslashes($host) . "');\n"
                 . "define('VENDOR_DB_PORT', $port);\n"
                 . "define('VENDOR_DB_NAME', '" . addslashes($name) . "');\n"
                 . "define('VENDOR_DB_USER', '" . addslashes($user) . "');\n"
                 . "define('VENDOR_DB_PASS', '" . addslashes($pass) . "');\n";
            if (file_put_contents(__DIR__ . '/config.local.php', $cfg) === false) {
                $err = t('err_write');
            } else {
                $_SESSION['vendor_install_db_ok'] = true;
                header('Location: install.php?step=3'); exit;
            }
        } catch (Throwable $e) {
            error_log('vendor install DB: ' . $e->getMessage());  // 🔒 v3.0.387 P3-D — nelekuj PDO výjimku na frontend (recon)
            $err = t('err_db') . t('err_db_generic');
        }
    }
}

if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['vendor_install_db_ok'])) {
    $username = trim($_POST['username'] ?? '');
    $name     = trim($_POST['display_name'] ?? '');
    $pw       = $_POST['password'] ?? '';
    $pw2      = $_POST['password2'] ?? '';

    if (!preg_match('/^[a-z0-9_]{3,32}$/i', $username)) { $err = t('err_user'); }
    elseif (strlen($pw) < 10) { $err = t('err_pw_len'); }
    elseif ($pw !== $pw2) { $err = t('err_pw_diff'); }
    else {
        try {
            require_once __DIR__ . '/_lib.php';
            $pdo = vendor_db();
            $pdo->prepare("
                INSERT INTO vendor_users (username, password_hash, display_name, role)
                VALUES (:u, :h, :n, 'admin')
                ON DUPLICATE KEY UPDATE password_hash = :h2, display_name = :n2, role = 'admin'
            ")->execute([
                'u' => $username,
                'h' => password_hash($pw, PASSWORD_BCRYPT),
                'n' => $name ?: $username,
                'h2'=> password_hash($pw, PASSWORD_BCRYPT),
                'n2'=> $name ?: $username,
            ]);
            @file_put_contents(__DIR__ . '/.installed', date('c'));
            $_SESSION['vendor_install_done'] = true;
            header('Location: install.php?step=4'); exit;
        } catch (Throwable $e) {
            error_log('vendor install DB: ' . $e->getMessage());  // 🔒 v3.0.387 P3-D — nelekuj PDO výjimku na frontend (recon)
            $err = t('err_db') . t('err_db_generic');
        }
    }
}

// Step 4: Brand info (volitelné, ale doporučené)
if ($step === 4 && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['vendor_install_done'])) {
    $bname  = trim($_POST['business_name'] ?? '');
    $bemail = trim($_POST['business_email'] ?? '');
    $bphone = trim($_POST['business_phone'] ?? '');
    $bctry  = trim($_POST['business_country'] ?? 'CZ');
    $blang  = trim($_POST['default_lang'] ?? 'cs');
    $bseg   = trim($_POST['segment'] ?? 'universal');

    if (!empty($_POST['skip'])) {
        header('Location: install.php?step=5'); exit;
    }

    if ($bname === '') { $err = t('brand_err_name'); }
    elseif ($bemail && !filter_var($bemail, FILTER_VALIDATE_EMAIL)) { $err = t('brand_err_email'); }
    else {
        try {
            require_once __DIR__ . '/_lib.php';
            require_once __DIR__ . '/_mail.php';
            $pdo = vendor_db();
            vendor_ensure_settings_table($pdo);
            $setVals = [
                'business_name'         => $bname,
                'business_email'        => $bemail,
                'business_support_email'=> $bemail, // mirror — můžeš změnit později
                'business_phone'        => $bphone,
                'business_country'      => $bctry,
                'business_web'          => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'appek.cz'),
                'default_lang'          => $blang,
                'customer_segment'      => $bseg,
                'install_completed_at'  => date('c'),
            ];
            foreach ($setVals as $k => $v) { vendor_mail_set($k, $v); }
            header('Location: install.php?step=5'); exit;
        } catch (Throwable $e) {
            error_log('vendor install DB: ' . $e->getMessage());  // 🔒 v3.0.387 P3-D — nelekuj PDO výjimku na frontend (recon)
            $err = t('err_db') . t('err_db_generic');
        }
    }
}
?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($LANG) ?>"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= htmlspecialchars(t('app_title')) ?></title>
<link rel="stylesheet" href="style.css">
<style>
  .lang-pill { display:inline-block; padding:5px 12px; margin-left:6px; border-radius:999px; background:#f5f5f7; color:#6e6e73; font-size:11.5px; font-weight:600; text-decoration:none; }
  .lang-pill.active { background:linear-gradient(180deg,#BA7517,#854F0B); color:#fff; }
  .lang-bar { position:absolute; top:18px; right:24px; display:flex; gap:4px; }
  .feature-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin:18px 0; }
  .feature-grid .fi { background:#fafafa; border:1px solid #eee; border-radius:10px; padding:14px 16px; }
  .feature-grid .ft { font-weight:700; font-size:14px; margin-bottom:4px; }
  .feature-grid .fd { font-size:12.5px; color:#6e6e73; line-height:1.5; }
  @media (max-width:600px) { .feature-grid { grid-template-columns:1fr; } }
</style>
</head>
<body class="install-page">
<div class="install-wrap" style="position:relative">

  <div class="lang-bar">
    <a href="?lang=cs<?= $step ? '&step=' . $step : '' ?>" class="lang-pill <?= $LANG === 'cs' ? 'active' : '' ?>">🇨🇿 CS</a>
    <a href="?lang=en<?= $step ? '&step=' . $step : '' ?>" class="lang-pill <?= $LANG === 'en' ? 'active' : '' ?>">🇬🇧 EN</a>
    <a href="?lang=es<?= $step ? '&step=' . $step : '' ?>" class="lang-pill <?= $LANG === 'es' ? 'active' : '' ?>">🇪🇸 ES</a>
  </div>

  <div class="install-head">
    <h1><?= htmlspecialchars(t('app_title')) ?></h1>
    <div class="sub"><?= htmlspecialchars(t('app_sub')) ?></div>
  </div>
  <div class="install-body">
    <ul class="steps">
      <li class="<?= $step >= 1 ? 'done' : '' ?><?= $step <= 1 ? ' now' : '' ?>">1. <?= htmlspecialchars(t('step_welcome')) ?></li>
      <li class="<?= $step >= 2 ? 'done' : '' ?><?= $step === 2 ? ' now' : '' ?>">2. <?= htmlspecialchars(t('step_db')) ?></li>
      <li class="<?= $step >= 3 ? 'done' : '' ?><?= $step === 3 ? ' now' : '' ?>">3. <?= htmlspecialchars(t('step_admin')) ?></li>
      <li class="<?= $step >= 4 ? 'done' : '' ?><?= $step === 4 ? ' now' : '' ?>">4. <?= htmlspecialchars(t('step_brand')) ?></li>
      <li class="<?= $step >= 5 ? 'done' : '' ?><?= $step === 5 ? ' now' : '' ?>">5. <?= htmlspecialchars(t('step_done')) ?></li>
    </ul>

    <?php if ($err): ?><div class="alert err">❌ <?= htmlspecialchars($err) ?></div><?php endif; ?>
    <?php if ($ok):  ?><div class="alert ok">✅ <?= htmlspecialchars($ok) ?></div><?php endif; ?>

    <?php if ($step === 0 || $step === 1): ?>
      <h2><?= htmlspecialchars(t('welcome_h')) ?></h2>
      <p style="color:#555;font-size:14px;line-height:1.6"><?= t('welcome_p') ?></p>
      <p style="font-weight:700;margin-top:18px;font-size:13.5px;color:#1d1d1f"><?= htmlspecialchars(t('welcome_what')) ?></p>
      <div class="feature-grid">
        <div class="fi">
          <div class="ft"><?= htmlspecialchars(t('f_licenses_t')) ?></div>
          <div class="fd"><?= htmlspecialchars(t('f_licenses_d')) ?></div>
        </div>
        <div class="fi">
          <div class="ft"><?= htmlspecialchars(t('f_access_t')) ?></div>
          <div class="fd"><?= htmlspecialchars(t('f_access_d')) ?></div>
        </div>
        <div class="fi">
          <div class="ft"><?= htmlspecialchars(t('f_shop_t')) ?></div>
          <div class="fd"><?= htmlspecialchars(t('f_shop_d')) ?></div>
        </div>
        <div class="fi">
          <div class="ft"><?= htmlspecialchars(t('f_packages_t')) ?></div>
          <div class="fd"><?= htmlspecialchars(t('f_packages_d')) ?></div>
        </div>
      </div>
      <a href="install.php?step=2" class="btn"><?= htmlspecialchars(t('welcome_btn')) ?></a>

    <?php elseif ($step === 2): ?>
      <h2><?= htmlspecialchars(t('db_h')) ?></h2>
      <p style="color:#666;font-size:13px"><?= t('db_p') ?></p>
      <form method="POST" action="install.php?step=2">
        <label><span class="lbl"><?= htmlspecialchars(t('db_host')) ?></span>
          <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required>
        </label>
        <div class="grid-2">
          <label><span class="lbl"><?= htmlspecialchars(t('db_port')) ?></span><input type="number" name="db_port" value="3306"></label>
          <label><span class="lbl"><?= htmlspecialchars(t('db_name')) ?></span>
            <input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? ($isLocalhost ? 'appek_vendor' : '')) ?>" placeholder="appek_vendor" required>
          </label>
        </div>
        <label><span class="lbl"><?= htmlspecialchars(t('db_user')) ?></span>
          <input type="text" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? ($isLocalhost ? 'root' : '')) ?>" required>
        </label>
        <label><span class="lbl"><?= htmlspecialchars(t('db_pass')) ?></span>
          <input type="password" name="db_pass">
        </label>
        <?php if ($isLocalhost): ?>
          <div class="alert" style="background:#FEF3C7;color:#92400E;padding:10px 14px;border-radius:10px;margin-bottom:14px;font-size:12px">
            <?= t('db_xampp') ?>
          </div>
        <?php endif; ?>
        <button class="btn" type="submit"><?= htmlspecialchars(t('db_btn')) ?></button>
      </form>

    <?php elseif ($step === 3): ?>
      <h2><?= htmlspecialchars(t('admin_h')) ?></h2>
      <p style="color:#666;font-size:13px"><?= htmlspecialchars(t('admin_p')) ?></p>
      <form method="POST" action="install.php?step=3">
        <label><span class="lbl"><?= htmlspecialchars(t('admin_user')) ?></span>
          <input type="text" name="username" pattern="[a-zA-Z0-9_]{3,32}" value="<?= htmlspecialchars($_POST['username'] ?? ($isLocalhost ? 'admin' : '')) ?>" required autofocus>
        </label>
        <label><span class="lbl"><?= htmlspecialchars(t('admin_name')) ?></span>
          <input type="text" name="display_name" placeholder="Jan Novák" value="<?= htmlspecialchars($_POST['display_name'] ?? ($isLocalhost ? 'Admin (test)' : '')) ?>">
        </label>
        <div class="grid-2">
          <label><span class="lbl"><?= htmlspecialchars(t('admin_pw')) ?></span><input type="password" name="password" minlength="10" value="<?= $isLocalhost ? 'admin123456' : '' ?>" required></label>
          <label><span class="lbl"><?= htmlspecialchars(t('admin_pw2')) ?></span><input type="password" name="password2" minlength="10" value="<?= $isLocalhost ? 'admin123456' : '' ?>" required></label>
        </div>
        <?php if ($isLocalhost): ?>
          <div style="background:#FEF3C7;color:#92400E;padding:10px 14px;border-radius:10px;margin-bottom:14px;font-size:12px">
            <?= t('admin_xampp') ?>
          </div>
        <?php endif; ?>
        <button class="btn" type="submit"><?= htmlspecialchars(t('admin_btn')) ?></button>
      </form>

    <?php elseif ($step === 4): ?>
      <h2><?= htmlspecialchars(t('brand_h')) ?></h2>
      <p style="color:#666;font-size:13px"><?= t('brand_p') ?></p>
      <form method="POST" action="install.php?step=4">
        <label><span class="lbl"><?= htmlspecialchars(t('brand_name')) ?> <span style="color:#a8232f">*</span></span>
          <input type="text" name="business_name" placeholder="<?= htmlspecialchars(t('brand_name_ph')) ?>" value="<?= htmlspecialchars($_POST['business_name'] ?? '') ?>" required autofocus>
        </label>
        <div class="grid-2">
          <label><span class="lbl"><?= htmlspecialchars(t('brand_email')) ?></span>
            <input type="email" name="business_email" placeholder="info@firma.cz" value="<?= htmlspecialchars($_POST['business_email'] ?? '') ?>">
          </label>
          <label><span class="lbl"><?= htmlspecialchars(t('brand_phone')) ?></span>
            <input type="tel" name="business_phone" placeholder="+420 777 123 456" value="<?= htmlspecialchars($_POST['business_phone'] ?? '') ?>">
          </label>
        </div>
        <div class="grid-2">
          <label><span class="lbl"><?= htmlspecialchars(t('brand_country')) ?></span>
            <select name="business_country">
              <option value="CZ" <?= ($_POST['business_country'] ?? 'CZ') === 'CZ' ? 'selected' : '' ?>>🇨🇿 Česká republika</option>
              <option value="SK" <?= ($_POST['business_country'] ?? '') === 'SK' ? 'selected' : '' ?>>🇸🇰 Slovensko</option>
              <option value="PL" <?= ($_POST['business_country'] ?? '') === 'PL' ? 'selected' : '' ?>>🇵🇱 Polsko</option>
              <option value="DE" <?= ($_POST['business_country'] ?? '') === 'DE' ? 'selected' : '' ?>>🇩🇪 Německo</option>
              <option value="AT" <?= ($_POST['business_country'] ?? '') === 'AT' ? 'selected' : '' ?>>🇦🇹 Rakousko</option>
              <option value="ES" <?= ($_POST['business_country'] ?? '') === 'ES' ? 'selected' : '' ?>>🇪🇸 Španělsko</option>
              <option value="GB" <?= ($_POST['business_country'] ?? '') === 'GB' ? 'selected' : '' ?>>🇬🇧 UK</option>
              <option value="US" <?= ($_POST['business_country'] ?? '') === 'US' ? 'selected' : '' ?>>🇺🇸 USA</option>
              <option value="OTHER" <?= ($_POST['business_country'] ?? '') === 'OTHER' ? 'selected' : '' ?>>🌍 Jiná</option>
            </select>
          </label>
          <label><span class="lbl"><?= htmlspecialchars(t('brand_lang')) ?></span>
            <select name="default_lang">
              <option value="cs" <?= ($_POST['default_lang'] ?? $LANG) === 'cs' ? 'selected' : '' ?>>🇨🇿 Čeština</option>
              <option value="en" <?= ($_POST['default_lang'] ?? $LANG) === 'en' ? 'selected' : '' ?>>🇬🇧 English</option>
              <option value="es" <?= ($_POST['default_lang'] ?? $LANG) === 'es' ? 'selected' : '' ?>>🇪🇸 Español</option>
            </select>
          </label>
        </div>
        <label><span class="lbl"><?= htmlspecialchars(t('brand_seg')) ?></span>
          <select name="segment">
            <option value="universal"  <?= ($_POST['segment'] ?? 'universal') === 'universal'  ? 'selected' : '' ?>><?= htmlspecialchars(t('brand_seg_universal')) ?></option>
            <option value="bakery"     <?= ($_POST['segment'] ?? '') === 'bakery'     ? 'selected' : '' ?>><?= htmlspecialchars(t('brand_seg_bakery')) ?></option>
            <option value="restaurant" <?= ($_POST['segment'] ?? '') === 'restaurant' ? 'selected' : '' ?>><?= htmlspecialchars(t('brand_seg_restaurant')) ?></option>
            <option value="catering"   <?= ($_POST['segment'] ?? '') === 'catering'   ? 'selected' : '' ?>><?= htmlspecialchars(t('brand_seg_catering')) ?></option>
            <option value="production" <?= ($_POST['segment'] ?? '') === 'production' ? 'selected' : '' ?>><?= htmlspecialchars(t('brand_seg_production')) ?></option>
            <option value="retail"     <?= ($_POST['segment'] ?? '') === 'retail'     ? 'selected' : '' ?>><?= htmlspecialchars(t('brand_seg_retail')) ?></option>
          </select>
        </label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
          <button class="btn" type="submit"><?= htmlspecialchars(t('brand_btn')) ?></button>
          <button class="btn" type="submit" name="skip" value="1" style="background:#e5e5e7;color:#1d1d1f"><?= htmlspecialchars(t('brand_skip')) ?></button>
        </div>
      </form>

    <?php elseif ($step === 5): ?>
      <h2><?= htmlspecialchars(t('done_h')) ?></h2>
      <div class="alert ok">✅ <?= htmlspecialchars(t('done_p')) ?></div>
      <div class="feature-grid" style="margin-top:14px">
        <a href="index.php" class="fi" style="text-decoration:none;color:inherit;background:linear-gradient(135deg,#BA7517,#854F0B);color:#fff;border:none">
          <div class="ft" style="color:#fff">📊 Dashboard</div>
          <div class="fd" style="color:rgba(255,255,255,0.85)">Centrální přehled vendor portálu</div>
        </a>
        <a href="licenses.php" class="fi" style="text-decoration:none;color:inherit">
          <div class="ft">🔑 Vydat licenci</div>
          <div class="fd">Vystavit první licenční klíč zákazníkovi</div>
        </a>
        <a href="packages.php" class="fi" style="text-decoration:none;color:inherit">
          <div class="ft">🎁 Balíčky</div>
          <div class="fd">6 startovacích balíčků (Core + 5 segmentů)</div>
        </a>
        <a href="business-info.php" class="fi" style="text-decoration:none;color:inherit">
          <div class="ft">🏢 Firma — kompletní údaje</div>
          <div class="fd">IČO, DIČ, banka, právní (VOP, GDPR)</div>
        </a>
        <a href="settings.php#2fa" class="fi" style="text-decoration:none;color:inherit;border-color:#208438">
          <div class="ft" style="color:#208438">🔐 Zapni 2FA (doporučeno)</div>
          <div class="fd">TOTP — Google Authenticator / Authy / 1Password</div>
        </a>
        <a href="shop.php" class="fi" style="text-decoration:none;color:inherit">
          <div class="ft">🛒 Eshop</div>
          <div class="fd">Objednávky z appek.cz/checkout</div>
        </a>
      </div>
      <div class="alert err" style="margin-top:14px">
        <?= t('done_warn') ?>
      </div>
      <a href="index.php" class="btn" style="margin-top:8px"><?= htmlspecialchars(t('done_btn')) ?></a>
    <?php endif; ?>
  </div>
</div>
</body></html>
