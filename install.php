<?php
/**
 * 🚀 INSTALLER — first-run setup wizard pro Appek B2B systém.
 *
 * Spustí se jednou: vybere režim (lokálně/hybrid/cloud), nastaví DB credentials,
 * vytvoří admin účet, případně nakonfiguruje sync. Vše v 1 wizardu.
 *
 * Bezpečnost: Po dokončení vytvoří `api/.installed` flag a další volání blokuje.
 *             DOPORUČUJEME smazat install.php po instalaci.
 *
 * URL: /install.php
 */

// ⛔ Self-protection: pokud běžíme na master serveru (vendor/ existuje),
//    odmítni se spustit — install.php je pro CUSTOMER deployment, ne pro produkci vendora.
if (is_dir(__DIR__ . '/vendor') && file_exists(__DIR__ . '/index.html')) {
    header('Location: /');
    exit;
}

session_start();
require_once __DIR__ . '/api/_license.php';

$step = (int) ($_GET['step'] ?? $_POST['step'] ?? 0);
$err  = null;
$ok   = null;

// =============================================================
// 🌍 i18n — instalátor v CZ / EN / ES (kompletní překlady)
// =============================================================
$supportedLangs = ['cs', 'en', 'es'];
if (isset($_GET['lang']) && in_array($_GET['lang'], $supportedLangs, true)) {
    $_SESSION['install_lang'] = $_GET['lang'];
}
if (empty($_SESSION['install_lang'])) {
    $browserLang = strtolower(substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'cs', 0, 2));
    $_SESSION['install_lang'] = in_array($browserLang, $supportedLangs, true) ? $browserLang : 'cs';
}
$LANG = $_SESSION['install_lang'];

$T = [
    // ─── App + step labels ───
    'app_title'       => ['cs' => '📦 Appek B2B — Instalace', 'en' => '📦 Appek B2B — Installation', 'es' => '📦 Appek B2B — Instalación'],
    'app_subtitle'    => ['cs' => 'Univerzální B2B objednávkový systém · Krabicovka v2.0', 'en' => 'Universal B2B ordering system · Boxed v2.0', 'es' => 'Sistema universal de pedidos B2B · Empaquetado v2.0'],
    'step_license'    => ['cs' => 'Licence', 'en' => 'License', 'es' => 'Licencia'],
    'step_mode'       => ['cs' => 'Režim', 'en' => 'Mode', 'es' => 'Modo'],
    'step_check'      => ['cs' => 'Kontrola', 'en' => 'Check', 'es' => 'Comprobación'],
    'step_db'         => ['cs' => 'Databáze', 'en' => 'Database', 'es' => 'Base de datos'],
    'step_admin'      => ['cs' => 'Admin', 'en' => 'Admin', 'es' => 'Admin'],
    'step_sync'       => ['cs' => 'Sync', 'en' => 'Sync', 'es' => 'Sync'],
    'step_done'       => ['cs' => 'Hotovo', 'en' => 'Done', 'es' => 'Listo'],

    // ─── License gate ───
    'license_title'   => ['cs' => '🔑 Licenční klíč', 'en' => '🔑 License key', 'es' => '🔑 Clave de licencia'],
    'license_desc'    => ['cs' => 'Pro instalaci Appek B2B je potřeba licenční klíč. <strong>Dodavatel ti ho zaslal</strong> e-mailem po objednávce.', 'en' => 'A license key is required to install Appek B2B. <strong>The supplier emailed it</strong> to you after purchase.', 'es' => 'Se requiere clave de licencia para instalar Appek B2B. <strong>El proveedor te la envió</strong> por correo tras la compra.'],
    'license_format'  => ['cs' => 'Formát:', 'en' => 'Format:', 'es' => 'Formato:'],
    'license_nokey'   => ['cs' => 'Nemáš klíč? Kontaktuj dodavatele systému.', 'en' => 'No key? Contact your supplier.', 'es' => '¿Sin clave? Contacta a tu proveedor.'],
    'license_label'   => ['cs' => 'Licenční klíč', 'en' => 'License key', 'es' => 'Clave de licencia'],
    'license_hint'    => ['cs' => 'Vlož klíč přesně jak ti přišel (velká písmena, pomlčky).', 'en' => 'Enter the key exactly as received (uppercase, hyphens).', 'es' => 'Introduce la clave tal como la recibiste (mayúsculas, guiones).'],
    'license_validate'=> ['cs' => '🔓 Ověřit klíč &amp; pokračovat', 'en' => '🔓 Verify key &amp; continue', 'es' => '🔓 Verificar clave y continuar'],
    'license_invalid' => ['cs' => 'Neplatný licenční klíč. Zkontroluj formát APPEK-XXXX-XXXX-XXXX-XXXX.', 'en' => 'Invalid license key. Check format APPEK-XXXX-XXXX-XXXX-XXXX.', 'es' => 'Clave inválida. Verifica el formato APPEK-XXXX-XXXX-XXXX-XXXX.'],
    'license_required'=> ['cs' => 'Pro pokračování zadej platný licenční klíč.', 'en' => 'Enter a valid license key to continue.', 'es' => 'Introduce una clave válida para continuar.'],
    'license_xampp'   => ['cs' => '🧪 XAMPP test mode — testovací klíč přednastaven.', 'en' => '🧪 XAMPP test mode — test key pre-filled.', 'es' => '🧪 Modo de prueba XAMPP — clave de prueba precargada.'],

    // ─── Mode selection ───
    'mode_title'      => ['cs' => '🎯 Vyber režim provozu', 'en' => '🎯 Choose operation mode', 'es' => '🎯 Elige el modo de operación'],
    'mode_desc'       => ['cs' => 'V jakém režimu chceš Appek provozovat? Tuto volbu lze později změnit v <strong>Nastavení → Sync s cloudem</strong>.', 'en' => 'How do you want to run Appek? This can be changed later in <strong>Settings → Cloud Sync</strong>.', 'es' => '¿Cómo quieres ejecutar Appek? Esto se puede cambiar en <strong>Ajustes → Sync Cloud</strong>.'],
    'mode_back'       => ['cs' => '← Zpět (změnit klíč)', 'en' => '← Back (change key)', 'es' => '← Atrás (cambiar clave)'],
    'mode_cloud_t'    => ['cs' => 'Pouze cloud', 'en' => 'Cloud only', 'es' => 'Solo cloud'],
    'mode_cloud_d'    => ['cs' => 'Aplikace běží na hostingu. B2B portál dostupný odkudkoliv. <strong>Standardní volba.</strong>', 'en' => 'App runs on hosting. B2B portal accessible from anywhere. <strong>Standard choice.</strong>', 'es' => 'App en hosting. Portal B2B desde cualquier lugar. <strong>Opción estándar.</strong>'],
    'mode_hybrid_t'   => ['cs' => 'Hybridní (master + cloud)', 'en' => 'Hybrid (master + cloud)', 'es' => 'Híbrido (master + cloud)'],
    'mode_hybrid_d'   => ['cs' => 'Hlavní aplikace u tebe (PC/server), cloud zrcadlí. Mobilní přístup pro odběratele.', 'en' => 'Main app at your place (PC/server), cloud mirrors. Mobile access for customers.', 'es' => 'App principal en tu sitio (PC/servidor), cloud refleja. Acceso móvil para clientes.'],
    'mode_local_t'    => ['cs' => 'Pouze lokálně', 'en' => 'Local only', 'es' => 'Solo local' ],
    'mode_local_d'    => ['cs' => 'XAMPP/MAMP na tvém PC. Offline. Bez webu, bez sync. Pro testování / interní použití.', 'en' => 'XAMPP/MAMP on your PC. Offline. No web, no sync. For testing / internal use.', 'es' => 'XAMPP/MAMP en tu PC. Offline. Sin web, sin sync. Para pruebas / uso interno.'],

    // ─── Server check ───
    'check_title'     => ['cs' => '📋 Kontrola serveru', 'en' => '📋 Server check', 'es' => '📋 Comprobación del servidor'],
    'check_desc'      => ['cs' => 'Zkontrolujeme PHP, rozšíření a přístup k souborům.', 'en' => 'Checking PHP, extensions and file permissions.', 'es' => 'Comprobando PHP, extensiones y permisos.'],
    'check_ok'        => ['cs' => '✅ Vše OK — můžeš pokračovat', 'en' => '✅ All OK — you can continue', 'es' => '✅ Todo OK — puedes continuar'],
    'check_fail'      => ['cs' => '❌ Některé požadavky nejsou splněny', 'en' => '❌ Some requirements are not met', 'es' => '❌ Algunos requisitos no se cumplen'],

    // ─── Database ───
    'db_title'        => ['cs' => '🗄️ Databáze MySQL/MariaDB', 'en' => '🗄️ MySQL/MariaDB database', 'es' => '🗄️ Base de datos MySQL/MariaDB'],
    'db_info'         => ['cs' => '<strong>Tabulky se vytvoří AUTOMATICKY</strong> — instalátor naimportuje ~75 tabulek + seed dat. Stačí prázdná databáze.', 'en' => '<strong>Tables are created AUTOMATICALLY</strong> — installer imports ~75 tables + seed data. Just an empty database is needed.', 'es' => '<strong>Las tablas se crean AUTOMÁTICAMENTE</strong> — el instalador importa ~75 tablas + datos. Solo necesitas una BD vacía.'],
    'db_how'          => ['cs' => 'Jak vytvořit prázdnou DB?', 'en' => 'How to create an empty DB?', 'es' => '¿Cómo crear una BD vacía?'],
    'db_how_host'     => ['cs' => '<strong>Hostinger:</strong> hPanel → Databáze → MySQL → „Vytvořit"', 'en' => '<strong>Hostinger:</strong> hPanel → Databases → MySQL → "Create"', 'es' => '<strong>Hostinger:</strong> hPanel → Bases de datos → MySQL → "Crear"'],
    'db_how_local'    => ['cs' => '<strong>Lokálně:</strong> phpMyAdmin → New → DB name → Collation utf8mb4_unicode_ci', 'en' => '<strong>Locally:</strong> phpMyAdmin → New → DB name → Collation utf8mb4_unicode_ci', 'es' => '<strong>Local:</strong> phpMyAdmin → New → DB name → Collation utf8mb4_unicode_ci'],
    'db_host'         => ['cs' => 'Host (server)', 'en' => 'Host (server)', 'es' => 'Host (servidor)'],
    'db_port'         => ['cs' => 'Port', 'en' => 'Port', 'es' => 'Puerto'],
    'db_name'         => ['cs' => 'Název databáze', 'en' => 'Database name', 'es' => 'Nombre de la BD'],
    'db_user'         => ['cs' => 'Uživatel', 'en' => 'User', 'es' => 'Usuario'],
    'db_pass'         => ['cs' => 'Heslo', 'en' => 'Password', 'es' => 'Contraseña'],
    'db_pass_hint'    => ['cs' => 'XAMPP root účet nemá heslo — nech prázdné', 'en' => 'XAMPP root has no password — leave empty', 'es' => 'XAMPP root sin contraseña — déjalo vacío'],
    'db_connect'      => ['cs' => '🔌 Otestovat &amp; uložit', 'en' => '🔌 Test &amp; save', 'es' => '🔌 Probar y guardar'],
    'db_fail'         => ['cs' => 'Nepodařilo se připojit k DB', 'en' => 'Could not connect to database', 'es' => 'No se pudo conectar a la base de datos'],

    // ─── Admin ───
    'admin_title'     => ['cs' => '👤 Hlavní administrátor', 'en' => '👤 Main administrator', 'es' => '👤 Administrador principal'],
    'admin_desc'      => ['cs' => 'Vytvoř super admin účet. Tento e-mail + heslo použiješ pro přihlášení do <code>/admin/</code>.', 'en' => 'Create the super admin account. This email + password to log in to <code>/admin/</code>.', 'es' => 'Crea la cuenta de super admin. Este correo + contraseña para iniciar sesión en <code>/admin/</code>.'],
    'admin_jmeno'     => ['cs' => 'Jméno', 'en' => 'Name', 'es' => 'Nombre'],
    'admin_jmeno_ph'  => ['cs' => 'např. Jan Novák', 'en' => 'e.g. John Smith', 'es' => 'p. ej. Juan García'],
    'admin_email'     => ['cs' => 'E-mail (login)', 'en' => 'Email (login)', 'es' => 'Correo (inicio)'],
    'admin_email_ph'  => ['cs' => 'admin@firma.cz', 'en' => 'admin@company.com', 'es' => 'admin@empresa.es'],
    'admin_heslo'     => ['cs' => 'Heslo (min 8)', 'en' => 'Password (min 8)', 'es' => 'Contraseña (mín 8)'],
    'admin_heslo2'    => ['cs' => 'Heslo znovu', 'en' => 'Confirm password', 'es' => 'Confirmar contraseña'],
    'admin_create'    => ['cs' => '✅ Vytvořit admin účet', 'en' => '✅ Create admin account', 'es' => '✅ Crear cuenta admin'],
    'admin_err_email' => ['cs' => 'Neplatný e-mail.', 'en' => 'Invalid email.', 'es' => 'Correo inválido.'],
    'admin_err_short' => ['cs' => 'Heslo musí mít alespoň 8 znaků.', 'en' => 'Password must be at least 8 characters.', 'es' => 'Contraseña debe tener al menos 8 caracteres.'],
    'admin_err_match' => ['cs' => 'Hesla se neshodují.', 'en' => 'Passwords do not match.', 'es' => 'Las contraseñas no coinciden.'],

    // ─── Sync (hybrid mode) ───
    'sync_title'      => ['cs' => '🔄 Hybrid sync — konfigurace', 'en' => '🔄 Hybrid sync — configuration', 'es' => '🔄 Sincronización híbrida — configuración'],
    'sync_info'       => ['cs' => 'Sync potřebuje vědět: <strong>kam</strong> posílat data (URL cloud serveru) a <strong>jak</strong> ověřit pravost (shared secret).', 'en' => 'Sync needs: <strong>where</strong> to send data (cloud URL) and <strong>how</strong> to verify (shared secret).', 'es' => 'Sync necesita: <strong>dónde</strong> enviar datos (URL cloud) y <strong>cómo</strong> verificar (secreto compartido).'],
    'sync_role'       => ['cs' => 'Role tohoto serveru', 'en' => 'Role of this server', 'es' => 'Rol de este servidor'],
    'sync_master'     => ['cs' => '🖥️ Master (provoz — píše vše)', 'en' => '🖥️ Master (on-premise — writes everything)', 'es' => '🖥️ Master (local — escribe todo)'],
    'sync_mirror'     => ['cs' => '☁️ Mirror (cloud — přijímá zrcadlo)', 'en' => '☁️ Mirror (cloud — receives mirror)', 'es' => '☁️ Mirror (cloud — recibe espejo)'],
    'sync_url'        => ['cs' => 'URL druhého serveru', 'en' => 'URL of the other server', 'es' => 'URL del otro servidor'],
    'sync_secret'     => ['cs' => 'Shared secret (pokud necháš prázdné, vygeneruje se nový)', 'en' => 'Shared secret (empty = auto-generate)', 'es' => 'Secreto compartido (vacío = auto-generar)'],
    'sync_gen'        => ['cs' => '🎲 Vygenerovat nový bezpečný secret', 'en' => '🎲 Generate new secure secret', 'es' => '🎲 Generar nuevo secreto seguro'],
    'sync_save'       => ['cs' => '✅ Uložit konfiguraci', 'en' => '✅ Save configuration', 'es' => '✅ Guardar configuración'],

    // ─── Done ───
    'done_title'      => ['cs' => '🎉 Instalace dokončena!', 'en' => '🎉 Installation complete!', 'es' => '🎉 ¡Instalación completada!'],
    'done_ready'      => ['cs' => 'Systém je připravený k použití.', 'en' => 'System is ready to use.', 'es' => 'El sistema está listo para usar.'],
    'done_secret_t'   => ['cs' => '🔐 Sync shared secret', 'en' => '🔐 Sync shared secret', 'es' => '🔐 Secreto compartido sync'],
    'done_secret_d'   => ['cs' => 'Zkopíruj a nastav i na druhé straně. <strong>Uvidíš ho JEN TEĎ.</strong>', 'en' => 'Copy and set on the other side too. <strong>You\'ll see it ONLY NOW.</strong>', 'es' => 'Copia y configúralo en el otro lado. <strong>Solo lo verás AHORA.</strong>'],
    'done_security'   => ['cs' => '🔐 Bezpečnost:', 'en' => '🔐 Security:', 'es' => '🔐 Seguridad:'],
    'done_sec_1'      => ['cs' => '<strong>SMAŽ</strong> tento soubor <code>install.php</code> z bezpečnostních důvodů', 'en' => '<strong>DELETE</strong> this <code>install.php</code> for security', 'es' => '<strong>ELIMINA</strong> este <code>install.php</code> por seguridad'],
    'done_sec_2'      => ['cs' => 'Změň oprávnění <code>api/config.local.php</code> na 600', 'en' => 'Change <code>api/config.local.php</code> permissions to 600', 'es' => 'Cambia permisos de <code>api/config.local.php</code> a 600'],
    'done_sec_3'      => ['cs' => 'Nastav HTTPS (Let\'s Encrypt v hostingu zdarma)', 'en' => 'Enable HTTPS (Let\'s Encrypt free in hosting)', 'es' => 'Activa HTTPS (Let\'s Encrypt gratis en hosting)'],
    'done_next'       => ['cs' => 'Co dál:', 'en' => 'What\'s next:', 'es' => 'Qué sigue:'],
    'done_next_1'     => ['cs' => 'Přihlas se do admin panelu', 'en' => 'Log in to admin panel', 'es' => 'Inicia sesión en el panel admin'],
    'done_next_2'     => ['cs' => 'Onboarding wizard tě provede 9 kroky (jazyk, firma, logo, balíčky, demo data…)', 'en' => 'Onboarding wizard guides you through 9 steps (language, company, logo, packages, demo data…)', 'es' => 'Asistente de onboarding te guía por 9 pasos (idioma, empresa, logo, paquetes, datos demo…)'],
    'done_next_3'     => ['cs' => 'V Nastavení → 🛠️ Údržba si <strong>zapni denní zálohy</strong>', 'en' => 'In Settings → 🛠️ Maintenance, <strong>enable daily backups</strong>', 'es' => 'En Ajustes → 🛠️ Mantenimiento, <strong>activa copias diarias</strong>'],
    'done_admin_btn'  => ['cs' => '🚪 Přejít do adminu', 'en' => '🚪 Go to admin', 'es' => '🚪 Ir al admin'],

    // ─── Common buttons / errors ───
    'btn_back'        => ['cs' => '← Zpět', 'en' => '← Back', 'es' => '← Atrás'],
    'btn_next'        => ['cs' => 'Pokračovat →', 'en' => 'Continue →', 'es' => 'Continuar →'],
    'btn_retry'       => ['cs' => 'Zkusit znovu', 'en' => 'Try again', 'es' => 'Reintentar'],
    'err_generic'     => ['cs' => 'Nastala chyba:', 'en' => 'An error occurred:', 'es' => 'Ocurrió un error:'],
    'success_label'   => ['cs' => 'Hotovo:', 'en' => 'Done:', 'es' => 'Listo:'],
];
function t(string $key): string {
    global $T, $LANG;
    return $T[$key][$LANG] ?? $T[$key]['cs'] ?? $key;
}

// Detect production vs localhost (pro defaults)
$isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '::1'])
              || str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost:')
              || ($_SERVER['SERVER_ADDR'] ?? '') === '127.0.0.1';
$defaultMode = $isLocalhost ? 'local' : 'cloud';

// 🔒 v2.6.0 SECURITY FIX (C5): STRICT .installed lock — žádný HTTP ?force=1!
//    Předtím: ?force=1 přeskočilo lock a Step 3 reseuje super-admin heslo
//    přes INSERT...ON DUPLICATE KEY UPDATE.
//    Nyní: re-instalace JEN přes FTP smazání api/.installed (CLI only).
if (file_exists(__DIR__ . '/api/.installed') && $step !== 99) {
    // ?force=1 IGNORUJEME — nelze přes HTTP obejít installed-lock.
    // Pokud admin chce re-install: smaže api/.installed přes FTP a začne znovu.
    header('Location: admin/');
    exit;
}

// ← ZPĚT na úvodní license gate (vynuluje klíč v session — pak ho zadá znovu)
if (isset($_GET['back']) && $_GET['back'] === 'license') {
    unset($_SESSION['install_license']);
    unset($_SESSION['install_license_challenge']);
    unset($_SESSION['install_license_email']);
    header('Location: install.php');
    exit;
}

// 🆕 v2.0.73 — Reset license + challenge state (jiné tlačítko "Zrušit a začít znovu")
if (isset($_GET['reset_license'])) {
    unset($_SESSION['install_license']);
    unset($_SESSION['install_license_challenge']);
    unset($_SESSION['install_license_email']);
    unset($_SESSION['install_license_warn']);
    header('Location: install.php');
    exit;
}

// 🆕 v2.0.73 — Send (re-send) challenge code email
if (isset($_GET['send_code']) && !empty($_SESSION['install_license_challenge'])) {
    $ch = $_SESSION['install_license_challenge'];
    $emailVerifyUrls = ['https://appek.cz/api/license_email_verify.php',
                        'https://vendor.appek.cz/api/license_email_verify.php'];
    $resp = null;
    foreach ($emailVerifyUrls as $url) {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode([
                    'license_key' => $ch['license'],
                    'email'       => $ch['email'],
                    'action'      => 'send_code',
                ]),
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body !== false) { $resp = json_decode($body, true); if (is_array($resp)) break; }
    }
    if ($resp && !empty($resp['ok']) && !empty($resp['sent'])) {
        $_SESSION['install_license_challenge']['sent'] = true;
        $_SESSION['install_license_warn'] = '✉️ Kód odeslán na ' . ($resp['masked_email'] ?? '***') . ' — zkontroluj inbox (i spam).';
    } else {
        $_SESSION['install_license_warn'] = '⚠️ Odeslání kódu selhalo: ' . ($resp['message'] ?? 'vendor offline');
    }
    header('Location: install.php');
    exit;
}

// 🔑 LICENSE GATE — bez platného klíče nemůže pokračovat do žádného kroku
// (kromě step=99 force / debug). Klíč se zadává jednou, drží se v session
// a do config.local.php se zapíše až ve step 2 spolu s DB credentials.
//
// 🆕 v2.0.73 — HYBRID EMAIL VERIFICATION:
//   Customer zadá license_key + email. Backend (license_email_verify.php):
//     - License nemá bound email → TOFU bind okamžitě
//     - License má bound stejný email → pass
//     - License má JINÝ bound email → challenge required (kód na původní email)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['license_key']) && empty($_SESSION['install_license'])) {
    $candidate = strtoupper(trim($_POST['license_key']));
    $candidateEmail = strtolower(trim($_POST['license_email'] ?? ''));
    $challengeCode  = trim($_POST['license_challenge_code'] ?? '');

    // 1) Offline HMAC kontrola (rychlá, lokální)
    if (!license_valid($candidate)) {
        $err = $T['license_invalid'][$LANG] ?? $T['license_invalid']['cs'];
    } elseif ($candidateEmail === '' || !filter_var($candidateEmail, FILTER_VALIDATE_EMAIL)) {
        $err = 'Zadej platný email — pro bezpečnostní binding licence.';
    } else {
        // 🔐 EMAIL VERIFICATION FLOW
        $emailVerifyUrls = ['https://appek.cz/api/license_email_verify.php',
                            'https://vendor.appek.cz/api/license_email_verify.php'];
        $emailVerifyResp = null;
        $emailAction = $challengeCode ? 'verify_code' : 'check';
        foreach ($emailVerifyUrls as $url) {
            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\n",
                    'content' => json_encode([
                        'license_key' => $candidate,
                        'email'       => $candidateEmail,
                        'action'      => $emailAction,
                        'code'        => $challengeCode,
                    ]),
                    'timeout' => 8,
                    'ignore_errors' => true,
                ],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body !== false) {
                $emailVerifyResp = json_decode($body, true);
                if (is_array($emailVerifyResp)) break;
            }
        }

        if (!$emailVerifyResp) {
            // Vendor server nedostupný — povol pokračovat v offline módu (s warningem)
            $_SESSION['install_license_warn'] = '⚠️ Email verifikace přeskočena (vendor offline). Po instalaci ověř ručně.';
        } elseif (!empty($emailVerifyResp['ok']) && in_array($emailVerifyResp['status'] ?? '', ['bound_tofu', 'already_bound', 'rebound'], true)) {
            // ✅ Email OK — proceed
            $_SESSION['install_license_email'] = $candidateEmail;
            if (($emailVerifyResp['status'] ?? '') === 'bound_tofu') {
                $_SESSION['install_license_warn'] = '✅ Email automaticky bound k licenci (první instalace).';
            } elseif (($emailVerifyResp['status'] ?? '') === 'rebound') {
                $_SESSION['install_license_warn'] = '✅ Email rebindnut — bezpečnost ověřena.';
            }
        } elseif (!empty($emailVerifyResp['ok']) && ($emailVerifyResp['status'] ?? '') === 'challenge_required') {
            // 🚨 Challenge — vrať info pro UI ať customer zadá kód
            $_SESSION['install_license_challenge'] = [
                'license' => $candidate,
                'email'   => $candidateEmail,
                'masked'  => $emailVerifyResp['masked_email'] ?? '***',
                'sent'    => false,
            ];
            $err = '🔐 Tato licence je registrovaná na jiný email (' . htmlspecialchars($emailVerifyResp['masked_email'] ?? '***') . '). Pro bezpečnost klikni na "Poslat ověřovací kód" — pošleme ti 6-místný kód na původní email.';
        } else {
            $err = $emailVerifyResp['message'] ?? ('Email verifikace selhala: ' . ($emailVerifyResp['reason'] ?? '?'));
        }
    }

    // === Pokud email check prošel → pokračuj na license_verify (registrace install) ===
    if (empty($err) && !empty($_SESSION['install_license_email'])) {
        // 2) Online ověření u dodavatele (vendor) — registruje instalaci
        $verifyOk = true;
        $verifyMsg = '';
        $verifyInfo = null;
        // Spočti install URL (bez query)
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $thisHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $installUrl = $proto . '://' . $thisHost;

        // Zkus na vendor — pokud nedostupný (offline / firewall), povol pokračovat s warningem
        $vendorUrls = ['https://appek.cz/api/license_verify.php', 'https://vendor.appek.cz/api/license_verify.php'];
        $resp = null;
        foreach ($vendorUrls as $url) {
            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\n",
                    'content' => json_encode([
                        'license_key'    => $candidate,
                        'install_url'    => $installUrl,
                        'current_version'=> defined('APP_VERSION') ? APP_VERSION : '0.0.0',
                        'register'       => true,
                    ]),
                    'timeout' => 5,
                    'ignore_errors' => true,
                ],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body !== false) {
                $resp = json_decode($body, true);
                if (is_array($resp)) break;
            }
        }

        if ($resp && isset($resp['valid'])) {
            if ($resp['valid'] === false) {
                $verifyOk = false;
                $verifyMsg = $resp['message'] ?? ('Klíč neprošel ověřením: ' . ($resp['reason'] ?? '?'));
            } else {
                $verifyInfo = $resp;
            }
        }
        // Pokud vendor nedostupný → offline mode, povol pokračovat
        if (!$resp) {
            $verifyMsg = '⚠️ Vendor server nedostupný — pokračujeme v offline módu. Doporučujeme online ověření po instalaci.';
        }

        if ($verifyOk) {
            $_SESSION['install_license'] = $candidate;
            // 🆕 v2.0.73 — Email už je v $_SESSION['install_license_email'] z předchozí
            // verifikace. Vyčistíme challenge state — license je teď fully accepted.
            unset($_SESSION['install_license_challenge']);
            if ($verifyInfo) $_SESSION['install_license_info'] = $verifyInfo;
            if ($verifyMsg)  $_SESSION['install_license_warn'] = $verifyMsg;
            header('Location: install.php?step=0');
            exit;
        } else {
            $err = $verifyMsg;
        }
    }
}
$hasLicense = !empty($_SESSION['install_license']);

// Detekce PHP požadavků
$req = [
    'PHP ≥ 8.0'       => version_compare(PHP_VERSION, '8.0.0', '>='),
    'PDO MySQL'       => extension_loaded('pdo_mysql'),
    'OpenSSL'         => extension_loaded('openssl'),
    'GD (obrázky)'    => extension_loaded('gd'),
    'GMP (push)'      => extension_loaded('gmp'),
    'cURL'            => extension_loaded('curl'),
    'mbstring (CZ)'   => extension_loaded('mbstring'),
    'JSON'            => extension_loaded('json') || function_exists('json_encode'),
    'Zapisovatelná uploads/' => is_writable(__DIR__) || @mkdir(__DIR__ . '/uploads', 0755, true) || is_writable(__DIR__ . '/uploads'),
    'Zapisovatelná api/'      => is_writable(__DIR__ . '/api'),
];
$allOk = !in_array(false, $req, true);

// 🔑 LICENSE-required guard — žádný step se nezpracuje bez platné licence v session.
// (POST license_key se zpracoval výše a teď je v session, takže legitimní flow projde.)
if (!$hasLicense && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['license_key'])) {
    $err = $T['license_required'][$LANG] ?? $T['license_required']['cs'];
}

// === STEP 0: Mode selection ===
if ($hasLicense && $step === 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'cloud';
    if (in_array($mode, ['local', 'hybrid', 'cloud'], true)) {
        $_SESSION['install_mode'] = $mode;
        // Auto-skip server check pokud vše OK
        if ($allOk) {
            header('Location: install.php?step=2');
        } else {
            header('Location: install.php?step=1');
        }
        exit;
    }
}

// === STEP 1: Server check (uživatel klikne pokračovat) ===
if ($hasLicense && $step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: install.php?step=2');
    exit;
}

// === STEP 2: DB ===
if ($hasLicense && $step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['db_host'] ?? 'localhost');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';
    $port = (int) ($_POST['db_port'] ?? 3306);

    if (!$name || !$user) { $err = 'Vyplňte název databáze a uživatele.'; }
    else {
        try {
            $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
            ]);
            $pdo->query("SELECT 1");

            // Ulož config (včetně licenčního klíče + bound email ze session)
            $licenseKey   = $_SESSION['install_license'] ?? '';
            $licenseEmail = $_SESSION['install_license_email'] ?? '';
            $configContent = "<?php\n"
                . "// 🚀 Auto-generovaný config — neupravujte ručně, použijte install.php?force=1\n"
                . "define('DB_HOST', '" . addslashes($host) . "');\n"
                . "define('DB_PORT', $port);\n"
                . "define('DB_NAME', '" . addslashes($name) . "');\n"
                . "define('DB_USER', '" . addslashes($user) . "');\n"
                . "define('DB_PASS', '" . addslashes($pass) . "');\n"
                . "define('APP_URL', '" . addslashes(($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "');\n"
                . "define('APP_LICENSE_KEY', '" . addslashes($licenseKey) . "');\n"
                . "define('APP_LICENSE_EMAIL', '" . addslashes($licenseEmail) . "');\n";

            $cfgPath = __DIR__ . '/api/config.local.php';
            $writeOk = @file_put_contents($cfgPath, $configContent);

            // Fallback: zkus uvolnit oprávnění api/ a zopakovat
            if ($writeOk === false) {
                @chmod(__DIR__ . '/api', 0755);
                $writeOk = @file_put_contents($cfgPath, $configContent);
            }

            if ($writeOk === false) {
                $apiDir   = __DIR__ . '/api';
                $apiOwner = function_exists('posix_getpwuid') && @fileowner($apiDir)
                    ? (posix_getpwuid(fileowner($apiDir))['name'] ?? '?')
                    : (string) @fileowner($apiDir);
                $webUser  = function_exists('posix_getpwuid')
                    ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
                    : (string) @getmyuid();
                $isWin    = stripos(PHP_OS, 'WIN') === 0;

                $err = "Nelze zapsat $cfgPath.\n\n"
                     . "🔧 Web server běží jako uživatel: $webUser\n"
                     . "🔧 Vlastník složky api/: $apiOwner\n\n"
                     . ($isWin
                        ? "Windows fix:\n  1. Pravý klik na složku api/ → Vlastnosti → Zabezpečení\n  2. Edit → Add → „Everyone“ → Full control\n  3. Refresh tuhle stránku."
                        : "Mac/Linux fix (XAMPP terminal):\n  sudo chmod -R 775 \"$apiDir\"\n  sudo chown -R $webUser \"$apiDir\"\n\nXAMPP Mac alternativa (rychlá, pro test):\n  chmod -R 777 \"$apiDir\""
                       )
                     . "\n\nPo opravě klikni na tlačítko Zpět a zkus to znovu.";
            } else {
                // 🆕 v2.9.325 — auto 0600 (jen owner read/write). Předtím 0644 → vendor mohl
                // teoreticky číst credentials. Pro license-sale model je customer self-hosted,
                // 0600 je správný default.
                @chmod($cfgPath, 0600);
                // Schema migrace
                try {
                    $schema = file_get_contents(__DIR__ . '/api/_schema.sql');
                    if ($schema !== false) {
                        // Strip SQL comments (řádky začínající `--`) — jinak by chunky začínající komentářem byly skipnuty
                        $schemaClean = preg_replace('/^\s*--.*$/m', '', $schema);
                        $statements = preg_split('/;\s*$/m', $schemaClean);
                        foreach ($statements as $stmt) {
                            $stmt = trim($stmt);
                            if ($stmt === '') continue;
                            try { $pdo->exec($stmt); }
                            catch (Throwable $e) {
                                if (!str_contains($e->getMessage(), 'Duplicate')) {
                                    error_log('Schema: ' . $e->getMessage());
                                }
                            }
                        }
                    }
                    // 🛡️ FULL SCHEMA — všechny tabulky a sloupce co se v produkci přidávaly auto-migrací
                    if (file_exists(__DIR__ . '/api/_full_schema.php')) {
                        require_once __DIR__ . '/api/_full_schema.php';
                        if (function_exists('apply_full_schema')) apply_full_schema($pdo);
                    }
                } catch (Throwable $e) { error_log('Schema init: ' . $e->getMessage()); }

                // 📁 v2.0.67 — Auto-vytvoř uploads složky aby diagnostika nezobrazovala "✗ chybí"
                $uploadDirs = [
                    __DIR__ . '/uploads',
                    __DIR__ . '/uploads/vyrobky',
                    __DIR__ . '/uploads/odberatele',
                    __DIR__ . '/uploads/loga',
                ];
                foreach ($uploadDirs as $d) {
                    if (!is_dir($d)) @mkdir($d, 0755, true);
                    // .gitkeep aby složky přežily ZIP/rsync (i prázdné)
                    $keep = $d . '/.gitkeep';
                    if (!file_exists($keep)) @file_put_contents($keep, "# Auto-created by install.php\n");
                }

                $_SESSION['install_db_ok'] = true;
                header('Location: install.php?step=3');
                exit;
            }
        } catch (Throwable $e) {
            $err = 'Nepodařilo se připojit k DB: ' . $e->getMessage();
        }
    }
}

// === STEP 3: Admin účet ===
if ($hasLicense && $step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['install_db_ok'])) {
    $email = trim($_POST['email'] ?? '');
    $jmeno = trim($_POST['jmeno'] ?? '');
    $heslo = $_POST['heslo'] ?? '';
    $heslo2 = $_POST['heslo2'] ?? '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $err = 'Neplatný e-mail.'; }
    elseif (strlen($heslo) < 8) { $err = 'Heslo musí mít alespoň 8 znaků.'; }
    elseif ($heslo !== $heslo2) { $err = 'Hesla se neshodují.'; }
    else {
        try {
            require_once __DIR__ . '/api/config.php';
            $pdo = db();
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS admin_users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(150) UNIQUE NOT NULL,
                    jmeno VARCHAR(150),
                    heslo_hash VARCHAR(255) NOT NULL,
                    role VARCHAR(30) DEFAULT 'admin',
                    aktivni TINYINT(1) DEFAULT 1,
                    vytvoreno DATETIME DEFAULT CURRENT_TIMESTAMP
                ) DEFAULT CHARSET=utf8mb4
            ");
            $stmt = $pdo->prepare("
                INSERT INTO admin_users (email, jmeno, heslo_hash, role, aktivni)
                VALUES (:e, :j, :h, 'admin', 1)
                ON DUPLICATE KEY UPDATE heslo_hash = :h2, role = 'admin', aktivni = 1
            ");
            $hash = password_hash($heslo, PASSWORD_BCRYPT);
            $stmt->execute(['e' => $email, 'j' => $jmeno ?: 'Admin', 'h' => $hash, 'h2' => $hash]);
            $_SESSION['install_admin_ok']    = true;
            $_SESSION['install_admin_email'] = $email;
            $_SESSION['install_admin_jmeno'] = $jmeno ?: 'Admin';
            // Pokud hybrid → step 4 (sync setup), jinak step 5 (done)
            $mode = $_SESSION['install_mode'] ?? $defaultMode;
            if ($mode === 'hybrid') {
                header('Location: install.php?step=4');
            } else {
                header('Location: install.php?step=5');
            }
            exit;
        } catch (Throwable $e) {
            $err = 'Chyba: ' . $e->getMessage();
        }
    }
}

// === STEP 4: Sync config (jen pro hybrid mode) ===
if ($hasLicense && $step === 4 && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['install_admin_ok'])) {
    $role     = $_POST['role'] ?? 'master';
    $endpoint = trim($_POST['cloud_endpoint'] ?? '');
    $secret   = $_POST['shared_secret'] ?? '';
    $generate = !empty($_POST['generate_secret']);

    try {
        require_once __DIR__ . '/api/config.php';
        require_once __DIR__ . '/api/_sync_schema.php';
        $pdo = db();
        ensure_sync_schema($pdo);

        if ($generate || empty($secret)) {
            $secret = bin2hex(random_bytes(32));
        }

        $pdo->prepare("
            UPDATE sync_config SET
                mode = 'hybrid',
                role = :r,
                cloud_endpoint = :e,
                shared_secret = :s,
                enabled = 1
            WHERE id = 1
        ")->execute(['r' => $role, 'e' => $endpoint, 's' => $secret]);

        $_SESSION['install_sync_ok'] = true;
        $_SESSION['install_sync_secret'] = $secret; // pro zobrazení na step 5
        header('Location: install.php?step=5');
        exit;
    } catch (Throwable $e) {
        $err = 'Chyba sync setup: ' . $e->getMessage();
    }
}

// === STEP 5: Lock + finish ===
if ($step === 5 && !empty($_SESSION['install_admin_ok'])) {
    @file_put_contents(__DIR__ . '/api/.installed', date('c'));
    @chmod(__DIR__ . '/api/.installed', 0644);
    $finalMode = $_SESSION['install_mode'] ?? $defaultMode;
    $syncSecret = $_SESSION['install_sync_secret'] ?? null;
    session_destroy();
}

// 🆕 v2.9.325 — STEP 99: self-delete install.php (po vědomém kliku usera)
// Předtím install.php zůstal na serveru navždy + .installed flag jen blokoval re-init.
// Customer ho ručně nikdy nesmaže → security risk (kdokoli může číst install.php logiku).
if ($step === 99 && file_exists(__DIR__ . '/api/.installed')) {
    $self = __FILE__;
    $deleted = @unlink($self);
    header('Content-Type: text/html; charset=UTF-8');
    if ($deleted) {
        echo '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"><title>Smazáno</title></head><body style="font-family:system-ui;max-width:500px;margin:60px auto;padding:20px;text-align:center">
            <h1 style="color:#15803D">✅ install.php byl smazán</h1>
            <p>Bezpečnostní krok dokončen — instalační skript už nelze spustit.</p>
            <p><a href="admin/" style="display:inline-block;padding:10px 24px;background:#BA7517;color:#fff;text-decoration:none;border-radius:8px;font-weight:600">🖥️ Otevřít Admin panel</a></p>
        </body></html>';
    } else {
        echo '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"><title>Smazání selhalo</title></head><body style="font-family:system-ui;max-width:500px;margin:60px auto;padding:20px;text-align:center">
            <h1 style="color:#92400e">⚠️ Nemohli jsme smazat install.php</h1>
            <p>FTP nedovolil PHP smazat soubor. Smaž ho prosím ručně přes <strong>FTP klient</strong> (FileZilla, Cyberduck):</p>
            <p><code style="background:#f4f5ff;padding:6px 10px;border-radius:4px">' . htmlspecialchars($self) . '</code></p>
            <p><a href="admin/">→ Pokračovat do Admin panelu</a></p>
        </body></html>';
    }
    exit;
}

$mode = $_SESSION['install_mode'] ?? $defaultMode;
?><!DOCTYPE html>
<html lang="cs"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Instalace Appek B2B</title>
<style>
* { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; background: linear-gradient(135deg,#FAEEDA,#fff); min-height: 100vh; margin: 0; padding: 20px; color: #1a1a1a; }
.wrap { max-width: 720px; margin: 30px auto; background: #fff; border-radius: 16px; box-shadow: 0 12px 40px rgba(186,117,23,0.18); overflow: hidden; }
.head { background: linear-gradient(135deg,#BA7517,#D89940); color: #fff; padding: 28px 32px; }
.head h1 { margin: 0; font-size: 24px; letter-spacing: -0.02em; }
.head .sub { font-size: 13px; opacity: 0.92; margin-top: 6px; }
.body { padding: 28px 32px; }
.steps { display: flex; gap: 6px; margin: 0 0 22px; padding: 0; list-style: none; flex-wrap: wrap; }
.steps li { flex: 1; min-width: 80px; padding: 8px 6px; text-align: center; font-size: 10.5px; font-weight: 600; color: #aaa; background: #f7f8fa; border-radius: 6px; }
.steps li.done { background: #DCFCE7; color: #166534; }
.steps li.now { background: #BA7517; color: #fff; }
h2 { margin: 0 0 14px; font-size: 19px; letter-spacing: -0.01em; }
label { display: block; margin-bottom: 12px; }
label .lbl { font-size: 13px; font-weight: 600; color: #444; margin-bottom: 4px; display: block; }
input[type=text], input[type=email], input[type=password], input[type=number], input[type=url] {
  width: 100%; padding: 11px 14px; border: 1.5px solid #ddd; border-radius: 10px; font-size: 14px; font-family: inherit;
}
input:focus { border-color: #BA7517; outline: none; box-shadow: 0 0 0 3px rgba(186,117,23,0.15); }
.btn { background: linear-gradient(180deg, #FFC966, #BA7517); color: #fff; border: 1px solid #A06513; padding: 12px 26px; border-radius: 999px; font-size: 15px; font-weight: 700; cursor: pointer; box-shadow: 0 2px 6px rgba(186,117,23,0.30); transition: all 0.15s; font-family: inherit; }
.btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(186,117,23,0.40); }
.btn-secondary { background: #f7f8fa; color: #444; border: 1px solid #ddd; box-shadow: none; }
.btn-secondary:hover { background: #eef0f4; }
.btn-back { background: transparent; color: #888; border: none; padding: 10px 14px; font-size: 13px; font-weight: 500; cursor: pointer; font-family: inherit; text-decoration: none; display: inline-block; transition: color 0.15s; }
.btn-back:hover { color: #BA7517; }
.form-row { display: flex; align-items: center; gap: 8px; margin-top: 8px; flex-wrap: wrap; }
.form-row .grow { flex: 1; }
.alert { padding: 10px 14px; border-radius: 10px; margin-bottom: 14px; font-size: 13px; white-space: pre-line; }
.alert-err { background: #FEE2E2; color: #991B1B; border: 1px solid #FCA5A5; }
.alert-ok { background: #DCFCE7; color: #166534; border: 1px solid #86EFAC; }
.alert-info { background: #FFF8E7; color: #854F0B; border: 1px solid #FDE68A; }
table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 14px; }
td { padding: 6px 8px; border-bottom: 1px solid #eee; }
td.ok { color: #166534; font-weight: 700; }
td.bad { color: #991B1B; font-weight: 700; }
code { background: #f7f8fa; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
small { color: #888; font-size: 12px; }

/* Mode cards */
.mode-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin: 18px 0; }
.mode-card { background: #fff; border: 2px solid #ddd; border-radius: 14px; padding: 18px; cursor: pointer; transition: all 0.15s; text-align: left; }
.mode-card:hover { border-color: #BA7517; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(186,117,23,0.15); }
.mode-card input[type=radio] { display: none; }
.mode-card input:checked ~ .mode-content { color: #854F0B; }
.mode-card.selected { border-color: #BA7517; background: #FFF8E7; box-shadow: 0 0 0 3px rgba(186,117,23,0.15); }
.mode-card .ico { font-size: 28px; line-height: 1; margin-bottom: 8px; }
.mode-card h4 { font-size: 15px; margin: 0 0 4px; font-weight: 700; }
.mode-card .desc { font-size: 11.5px; color: #666; line-height: 1.4; }
@media (max-width: 640px) { .mode-grid { grid-template-columns: 1fr; } }

/* Secret display */
.secret-box { background: #1D1D1F; color: #6dd58a; padding: 14px 18px; border-radius: 10px; font-family: "SF Mono", Menlo, monospace; font-size: 11.5px; word-break: break-all; margin: 12px 0; }
</style>
</head>
<body>
<!-- 🌍 Language switcher — pevně v pravém horním rohu -->
<div style="position:fixed;top:14px;right:14px;z-index:100;display:flex;gap:4px;background:rgba(255,255,255,0.95);backdrop-filter:blur(10px);border:1px solid rgba(0,0,0,0.08);border-radius:999px;padding:4px;box-shadow:0 4px 14px rgba(0,0,0,0.08)">
  <?php foreach (['cs' => '🇨🇿 CS', 'en' => '🇬🇧 EN', 'es' => '🇪🇸 ES'] as $lc => $label): ?>
    <a href="?lang=<?= $lc ?>&amp;step=<?= $step ?>" style="padding:6px 12px;border-radius:999px;text-decoration:none;font-size:13px;font-weight:600;color:<?= $LANG === $lc ? '#fff' : '#1d1d1f' ?>;background:<?= $LANG === $lc ? '#BA7517' : 'transparent' ?>;transition:all 0.15s"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<div class="wrap">
  <div class="head">
    <h1><?= htmlspecialchars(t('app_title')) ?></h1>
    <div class="sub"><?= htmlspecialchars(t('app_subtitle')) ?></div>
  </div>
  <div class="body">

    <?php
    $isHybrid = ($mode === 'hybrid');
    $stepLabels = [
      ['k' => -1, 'l' => t('step_license')],
      ['k' => 0, 'l' => t('step_mode')],
      ['k' => 1, 'l' => t('step_check')],
      ['k' => 2, 'l' => t('step_db')],
      ['k' => 3, 'l' => t('step_admin')],
    ];
    if ($isHybrid) $stepLabels[] = ['k' => 4, 'l' => t('step_sync')];
    $stepLabels[] = ['k' => 5, 'l' => t('step_done')];
    $effectiveStep = $hasLicense ? $step : -1;
    ?>
    <ul class="steps">
      <?php foreach ($stepLabels as $sl): ?>
        <li class="<?= $effectiveStep >= $sl['k'] ? 'done' : '' ?><?= $effectiveStep === $sl['k'] ? ' now' : '' ?>">
          <?= htmlspecialchars((string)$sl['l']) ?>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($err): ?><div class="alert alert-err">❌ <?= htmlspecialchars($err) ?></div><?php endif; ?>
    <?php if ($ok):  ?><div class="alert alert-ok">✅ <?= htmlspecialchars($ok) ?></div><?php endif; ?>

    <?php if (!$hasLicense): /* ═══ LICENSE GATE ═══ */ ?>
      <h2><?= t('license_title') ?></h2>
      <p style="font-size:13.5px;color:#555;line-height:1.6">
        <?= t('license_desc') ?> <?= t('license_format') ?> <code>APPEK-XXXX-XXXX-XXXX-XXXX</code>
      </p>
      <p style="font-size:12.5px;color:#888;line-height:1.5">
        <?= t('license_nokey') ?>
      </p>

      <?php
      // 🆕 v2.0.73 — Challenge state pro email rebind
      $challenge = $_SESSION['install_license_challenge'] ?? null;
      ?>
      <form method="POST" action="install.php">
        <label><span class="lbl"><?= t('license_label') ?></span>
          <input type="text" name="license_key" value="<?= $challenge ? htmlspecialchars($challenge['license']) : ($isLocalhost ? 'APPEK-4UG2-FWHX-D6SS-D3BA' : '') ?>" placeholder="APPEK-XXXX-XXXX-XXXX-XXXX" required style="font-family:'SF Mono',Menlo,monospace;letter-spacing:0.05em;text-transform:uppercase" <?= $challenge ? '' : 'autofocus' ?>>
          <small><?= $isLocalhost ? t('license_xampp') : t('license_hint') ?></small>
        </label>

        <label>
          <span class="lbl">📧 Email pro bezpečnostní binding licence <span style="color:#dc2626">*</span></span>
          <input type="email" name="license_email" value="<?= htmlspecialchars($challenge['email'] ?? '') ?>" placeholder="tvuj@email.cz" required <?= $challenge ? 'autofocus' : '' ?>>
          <small style="color:#666;font-size:12px;display:block;margin-top:4px">
            🔐 <strong>Hybrid TOFU verifikace:</strong> Při první instalaci se email automaticky bind k licenci. Při instalaci s jiným emailem ti pošleme ověřovací kód na původní email (anti-fraud).
          </small>
        </label>

        <?php if ($challenge): ?>
          <!-- 🚨 Challenge mode — customer musí zadat 6-digit kód -->
          <div style="background:#FFF8E7;border:2px solid #FBBF24;border-radius:12px;padding:14px 16px;margin-bottom:12px">
            <div style="font-weight:700;color:#854F0B;margin-bottom:8px">🔐 Bezpečnostní ověření vyžadováno</div>
            <div style="font-size:13px;color:#5C3608;line-height:1.5;margin-bottom:10px">
              Tato licence je registrovaná na <strong><?= htmlspecialchars($challenge['masked']) ?></strong>.
              Pošleme ti 6-místný kód — zadej ho níže.
            </div>
            <label style="margin-bottom:0">
              <span class="lbl">Ověřovací kód (6 číslic)</span>
              <input type="text" name="license_challenge_code" placeholder="123 456" pattern="[0-9]{6}" maxlength="6" required style="font-family:'SF Mono',Menlo,monospace;letter-spacing:0.3em;font-size:20px;text-align:center" autofocus>
              <small style="color:#666;display:block;margin-top:4px">
                Kód platí 15 min. Nemáš ho? <a href="install.php?send_code=1" style="color:#854F0B;font-weight:600">Poslat znovu</a>
              </small>
            </label>
          </div>
        <?php endif; ?>

        <button class="btn" type="submit"><?= $challenge ? '🔓 Ověřit kód a pokračovat' : t('license_validate') ?></button>
      </form>

      <?php if ($challenge): ?>
        <div style="margin-top:14px;text-align:center">
          <a href="install.php?reset_license=1" class="btn-back">← Zrušit a začít znovu</a>
        </div>
      <?php endif; ?>

    <?php elseif ($step === 0): /* ═══ STEP 0: MODE SELECTION ═══ */ ?>
      <h2><?= t('mode_title') ?></h2>
      <p style="font-size:13.5px;color:#555;line-height:1.6">
        <?= t('mode_desc') ?>
      </p>

      <form method="POST" action="install.php?step=0">
        <input type="hidden" name="step" value="0">
        <div class="form-row" style="justify-content:flex-end;margin-bottom:0">
          <a href="install.php?back=license" class="btn-back"><?= t('mode_back') ?></a>
        </div>

        <div class="mode-grid">
          <label class="mode-card <?= $defaultMode === 'cloud' ? 'selected' : '' ?>">
            <input type="radio" name="mode" value="cloud" <?= $defaultMode === 'cloud' ? 'checked' : '' ?> onchange="document.querySelectorAll('.mode-card').forEach(c=>c.classList.remove('selected'));this.closest('.mode-card').classList.add('selected')">
            <div class="mode-content">
              <div class="ico">☁️</div>
              <h4><?= t('mode_cloud_t') ?></h4>
              <div class="desc"><?= t('mode_cloud_d') ?></div>
            </div>
          </label>

          <label class="mode-card">
            <input type="radio" name="mode" value="hybrid" onchange="document.querySelectorAll('.mode-card').forEach(c=>c.classList.remove('selected'));this.closest('.mode-card').classList.add('selected')">
            <div class="mode-content">
              <div class="ico">🔄</div>
              <h4><?= t('mode_hybrid_t') ?></h4>
              <div class="desc"><?= t('mode_hybrid_d') ?></div>
            </div>
          </label>

          <label class="mode-card <?= $defaultMode === 'local' ? 'selected' : '' ?>">
            <input type="radio" name="mode" value="local" <?= $defaultMode === 'local' ? 'checked' : '' ?> onchange="document.querySelectorAll('.mode-card').forEach(c=>c.classList.remove('selected'));this.closest('.mode-card').classList.add('selected')">
            <div class="mode-content">
              <div class="ico">🏠</div>
              <h4><?= t('mode_local_t') ?></h4>
              <div class="desc"><?= t('mode_local_d') ?></div>
            </div>
          </label>
        </div>

        <button class="btn" type="submit"><?= t('btn_next') ?></button>
      </form>

    <?php elseif ($step === 1): /* ═══ STEP 1: SERVER CHECK ═══ */ ?>
      <h2><?= t('check_title') ?></h2>
      <p style="font-size:13px;color:#666"><?= t('check_desc') ?></p>
      <table>
        <?php foreach ($req as $name => $ok): ?>
          <tr>
            <td><?= htmlspecialchars($name) ?></td>
            <td class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✅ OK' : '❌' ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php if (!$allOk): ?>
        <div class="alert alert-info">⚠️ <?= t('check_fail') ?></div>
      <?php else: ?>
        <div class="alert alert-ok">✅ <strong><?= t('check_ok') ?></strong></div>
      <?php endif; ?>
      <form method="POST" action="install.php?step=1">
        <input type="hidden" name="step" value="1">
        <div class="form-row">
          <a href="install.php?step=0" class="btn-back"><?= t('btn_back') ?></a>
          <div class="grow"></div>
          <button class="btn" type="submit"><?= t('btn_next') ?></button>
        </div>
      </form>

    <?php elseif ($step === 2): /* ═══ STEP 2: DATABASE ═══ */ ?>
      <h2><?= t('db_title') ?></h2>
      <div class="alert alert-info">
        ℹ️ <?= t('db_info') ?>
      </div>
      <p style="font-size:13px;color:#666"><strong><?= t('db_how') ?></strong></p>
      <ul style="font-size:12.5px;color:#666;line-height:1.7">
        <li><?= t('db_how_host') ?></li>
        <li><?= t('db_how_local') ?></li>
      </ul>
      <form method="POST" action="install.php?step=2">
        <input type="hidden" name="step" value="2">
        <label><span class="lbl">Host (server)</span>
          <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required>
        </label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <label><span class="lbl">Port</span>
            <input type="number" name="db_port" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>">
          </label>
          <label><span class="lbl">Název databáze</span>
            <input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? ($isLocalhost ? 'appek' : '')) ?>" required>
          </label>
        </div>
        <label><span class="lbl"><?= t('db_user') ?></span>
          <input type="text" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? ($isLocalhost ? 'root' : '')) ?>" required>
        </label>
        <label><span class="lbl"><?= t('db_pass') ?> <?= $isLocalhost ? '<small>(' . t('db_pass_hint') . ')</small>' : '' ?></span>
          <input type="password" name="db_pass" value="">
        </label>
        <div class="form-row">
          <a href="install.php?step=1" class="btn-back"><?= t('btn_back') ?></a>
          <div class="grow"></div>
          <button class="btn" type="submit"><?= t('db_connect') ?></button>
        </div>
      </form>

    <?php elseif ($step === 3): /* ═══ STEP 3: ADMIN ═══ */ ?>
      <h2><?= t('admin_title') ?></h2>
      <p style="font-size:13px;color:#666"><?= t('admin_desc') ?></p>
      <form method="POST" action="install.php?step=3">
        <input type="hidden" name="step" value="3">
        <label><span class="lbl"><?= t('admin_jmeno') ?></span>
          <input type="text" name="jmeno" placeholder="<?= t('admin_jmeno_ph') ?>" value="<?= htmlspecialchars($_POST['jmeno'] ?? ($isLocalhost ? 'Admin (test)' : '')) ?>">
        </label>
        <label><span class="lbl"><?= t('admin_email') ?></span>
          <input type="email" name="email" placeholder="<?= t('admin_email_ph') ?>" value="<?= htmlspecialchars($_POST['email'] ?? ($isLocalhost ? 'admin@appek.cz' : '')) ?>" required>
        </label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <label><span class="lbl"><?= t('admin_heslo') ?></span>
            <input type="password" name="heslo" minlength="8" value="<?= $isLocalhost ? 'admin123' : '' ?>" required>
          </label>
          <label><span class="lbl"><?= t('admin_heslo2') ?></span>
            <input type="password" name="heslo2" minlength="8" value="<?= $isLocalhost ? 'admin123' : '' ?>" required>
          </label>
        </div>
        <div class="form-row">
          <a href="install.php?step=2" class="btn-back"><?= t('btn_back') ?></a>
          <div class="grow"></div>
          <button class="btn" type="submit"><?= t('admin_create') ?></button>
        </div>
      </form>

    <?php elseif ($step === 4): /* ═══ STEP 4: SYNC CONFIG (jen pro hybrid) ═══ */ ?>
      <h2><?= t('sync_title') ?></h2>
      <div class="alert alert-info">
        ℹ️ <?= t('sync_info') ?>
      </div>

      <form method="POST" action="install.php?step=4">
        <input type="hidden" name="step" value="4">
        <label><span class="lbl"><?= t('sync_role') ?></span>
          <select name="role" style="width:100%;padding:11px 14px;border:1.5px solid #ddd;border-radius:10px;font-size:14px">
            <option value="master"><?= t('sync_master') ?></option>
            <option value="mirror"><?= t('sync_mirror') ?></option>
          </select>
        </label>
        <label><span class="lbl"><?= t('sync_url') ?></span>
          <input type="url" name="cloud_endpoint" placeholder="https://moje-firma.cz/api" required>
        </label>
        <label><span class="lbl"><?= t('sync_secret') ?></span>
          <input type="text" name="shared_secret" placeholder="(auto)">
        </label>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px">
          <input type="checkbox" name="generate_secret" checked>
          <span><?= t('sync_gen') ?></span>
        </label>
        <div class="form-row">
          <a href="install.php?step=3" class="btn-back"><?= t('btn_back') ?></a>
          <div class="grow"></div>
          <button class="btn" type="submit"><?= t('sync_save') ?></button>
        </div>
      </form>

    <?php elseif ($step === 5): /* ═══ STEP 5: DONE ═══ */
      // Vytáhni info z session pro shrnutí
      $sessAdmin  = $_SESSION['install_admin_email']  ?? null;
      $sessLicense = $_SESSION['install_license']      ?? '';
      $sessLicInfo = $_SESSION['install_license_info'] ?? null;
      $licPackages = $sessLicInfo['packages'] ?? [];
      if (empty($licPackages) && $sessLicense && function_exists('license_packages')) {
        $licPackages = license_packages($sessLicense);
      }
      // Customer name pokud licence vrátila
      $customerName = $sessLicInfo['license_info']['customer_name'] ?? null;
      $expiresAt    = $sessLicInfo['license_info']['expires_at']    ?? null;
      // Masked klíč
      $maskedKey = $sessLicense ? (function_exists('license_masked') ? license_masked($sessLicense) : substr($sessLicense, 0, 12) . '••••') : '—';
      // Mapování balíčků → vizuál
      $pkgMeta = [
        'core'       => ['ic'=>'⚙️',  'name'=>'Core'],
        'cukrarna'   => ['ic'=>'🧁', 'name'=>'Cukrárna'],
        'lahudky'    => ['ic'=>'🥗', 'name'=>'Lahůdky'],
        'restaurace' => ['ic'=>'🍕', 'name'=>'Restaurace'],
        'catering'   => ['ic'=>'🎉', 'name'=>'Catering'],
        'sezona'     => ['ic'=>'🍰', 'name'=>'Sezónní akce'],
      ];
      $appVersion = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
      $host = $_SERVER['HTTP_HOST'] ?? 'tvojefirma.cz';
    ?>

      <!-- ═══ HERO success ═══ -->
      <div style="background:linear-gradient(135deg,#22c55e,#15803d);color:#fff;border-radius:14px;padding:28px 30px;margin-bottom:18px;text-align:center;box-shadow:0 8px 24px rgba(34,197,94,0.25)">
        <div style="font-size:48px;margin-bottom:6px">🎉</div>
        <h2 style="margin:0 0 6px;color:#fff;font-size:22px"><?= t('done_title') ?></h2>
        <div style="opacity:0.92;font-size:14px"><?= t('done_ready') ?> · APPEK v<?= htmlspecialchars($appVersion) ?></div>
        <?php if ($customerName): ?>
          <div style="margin-top:10px;background:rgba(255,255,255,0.18);display:inline-block;padding:6px 14px;border-radius:999px;font-size:13px">
            👤 Licence registrována na: <strong><?= htmlspecialchars($customerName) ?></strong>
          </div>
        <?php endif; ?>
        <?php if ($sessAdmin): ?>
          <div style="margin-top:10px;background:rgba(0,0,0,0.18);display:inline-block;padding:6px 14px;border-radius:999px;font-size:13px">
            🔐 Přihlašovací email admina: <strong><?= htmlspecialchars($sessAdmin) ?></strong>
          </div>
        <?php endif; ?>
      </div>

      <!-- ═══ 3 velké akční karty ═══ -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:18px">
        <a href="admin/" style="background:linear-gradient(135deg,#BA7517,#854F0B);color:#fff;padding:18px 14px;border-radius:12px;text-decoration:none;text-align:center;display:block">
          <div style="font-size:32px;line-height:1">🖥️</div>
          <div style="font-weight:700;margin-top:6px">Admin panel</div>
          <div style="font-size:11px;opacity:0.85;margin-top:2px"><?= htmlspecialchars($host) ?>/admin/</div>
        </a>
        <a href="b2b/" target="_blank" style="background:#fff;color:#1d1d1f;padding:18px 14px;border-radius:12px;text-decoration:none;text-align:center;border:2px solid #208438;display:block">
          <div style="font-size:32px;line-height:1">🛒</div>
          <div style="font-weight:700;margin-top:6px;color:#208438">B2B portál</div>
          <div style="font-size:11px;color:#6e6e73;margin-top:2px">Pro tvé odběratele</div>
        </a>
        <a href="api/version.php" target="_blank" style="background:#fff;color:#1d1d1f;padding:18px 14px;border-radius:12px;text-decoration:none;text-align:center;border:2px solid #d2d2d7;display:block">
          <div style="font-size:32px;line-height:1">🔌</div>
          <div style="font-weight:700;margin-top:6px">REST API</div>
          <div style="font-size:11px;color:#6e6e73;margin-top:2px">Verze + integrace</div>
        </a>
      </div>

      <!-- ═══ License & balíčky ═══ -->
      <div style="background:#fff;border:1px solid #e5e5e7;border-radius:12px;padding:18px 20px;margin-bottom:14px">
        <div style="font-size:11px;color:#86868b;text-transform:uppercase;letter-spacing:0.5px;font-weight:700;margin-bottom:10px">🔑 Licence a balíčky</div>
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px">
          <code style="background:#f5f5f7;padding:6px 12px;border-radius:6px;font-size:12.5px;font-family:'SF Mono',Menlo,monospace"><?= htmlspecialchars($maskedKey) ?></code>
          <?php if ($expiresAt): ?>
            <span style="font-size:12px;color:#6e6e73">Platnost do: <strong><?= htmlspecialchars($expiresAt) ?></strong></span>
          <?php else: ?>
            <span style="background:rgba(52,199,89,0.15);color:#208438;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700">♾️ DOŽIVOTNÍ</span>
          <?php endif; ?>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
          <?php foreach ($licPackages as $p):
            $meta = $pkgMeta[$p] ?? ['ic'=>'📦','name'=>$p];
          ?>
            <span style="background:linear-gradient(135deg,#fff8e8,#fff);border:1.5px solid #BA7517;color:#854F0B;padding:6px 12px;border-radius:8px;font-size:12.5px;font-weight:600">
              <?= $meta['ic'] ?> <?= htmlspecialchars($meta['name']) ?>
            </span>
          <?php endforeach; ?>
          <?php if (empty($licPackages)): ?>
            <span style="color:#86868b;font-size:12px;font-style:italic">Žádné balíčky v licenci</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- ═══ Onboarding (admin wizard) ═══ -->
      <div style="background:#eff6ff;border-left:3px solid #0058b8;padding:14px 16px;border-radius:8px;margin-bottom:14px">
        <strong style="color:#0058b8">🚀 Při prvním přihlášení tě onboarding wizard provede:</strong>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;margin-top:10px;font-size:12.5px;color:#1d1d1f">
          <div>🌍 <strong>Jazyk</strong> · CZ / EN / ES</div>
          <div>🏢 <strong>Údaje firmy</strong> · IČO, DIČ, adresa</div>
          <div>🎨 <strong>Logo + barvy</strong></div>
          <div>📦 <strong>První výrobky</strong> · ručně nebo z CSV</div>
          <div>👥 <strong>Odběratelé</strong> · import z ARES</div>
          <div>💰 <strong>DPH sazby</strong> · 12 % / 21 %</div>
          <div>📧 <strong>SMTP / e-maily</strong></div>
          <div>🛒 <strong>Balíčky</strong> · zapnout funkce</div>
          <div>🧪 <strong>Demo data</strong> · vyplnit ukázkovou DB (volitelně)</div>
        </div>
      </div>

      <!-- ═══ Bezpečnost — kritické kroky ═══ -->
      <div style="background:#fef3c7;border-left:3px solid #c66800;padding:14px 16px;border-radius:8px;margin-bottom:14px">
        <strong style="color:#854F0B"><?= t('done_security') ?> · ⚠️ NEPŘESKAKUJ</strong>
        <ol style="margin:8px 0 0;padding-left:20px;font-size:12.5px;line-height:1.7;color:#1d1d1f">
          <li><?= t('done_sec_1') ?>
            <br><small style="color:#854F0B">→ Klikni "🗑️ Smazat install.php nyní" níže (1 klik), nebo ručně přes FTP. Pokud nebude smazaný, kdokoliv může re-instalovat aplikaci.</small></li>
          <li><?= t('done_sec_2') ?>
            <br><small style="color:#854F0B">→ Auto-nastaveno na 0600 v této verzi (v2.9.325+). Pro starší verze: FileManager → klik pravým na <code>api/config.local.php</code> → Permissions → 600.</small></li>
          <li><?= t('done_sec_3') ?>
            <br><small style="color:#854F0B">→ Hostinger panel → SSL/HTTPS → Install Free SSL (Let's Encrypt, klik).</small></li>
        </ol>

        <!-- 🆕 v2.9.325 — Self-delete button (1 klik smazání install.php) -->
        <div style="margin-top:14px;padding-top:14px;border-top:1px dashed #c66800">
          <form method="get" action="install.php" onsubmit="return confirm('Opravdu smazat install.php?\n\nTo je BEZPEČNÉ — nepotřebuješ ho už spouštět. Re-install vyžaduje FTP smazání api/.installed.\n\nPokud souhlasíš, klikni OK.')">
            <input type="hidden" name="step" value="99">
            <button type="submit" style="background:#dc2626;color:#fff;border:none;padding:10px 18px;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer">
              🗑️ Smazat install.php nyní (doporučeno)
            </button>
          </form>
        </div>
      </div>

      <?php if (!empty($syncSecret)): ?>
        <div class="alert alert-info">
          🔐 <strong><?= t('done_secret_t') ?></strong> — <?= t('done_secret_d') ?>
          <div class="secret-box"><?= htmlspecialchars($syncSecret) ?></div>
        </div>
      <?php endif; ?>

      <!-- ═══ Aktualizace — info ═══ -->
      <div style="background:#f0fdf4;border-left:3px solid #208438;padding:14px 16px;border-radius:8px;margin-bottom:14px">
        <strong style="color:#208438">🔄 Aktualizace systému</strong>
        <div style="font-size:12.5px;line-height:1.7;color:#1d1d1f;margin-top:6px">
          APPEK se aktualizuje sám.<br>
          → Admin se podívá na vendor server a pokud je dostupná novější verze, ukáže zelený pulsující pill <em>🆕 Nová verze X.Y.Z</em> v topbaru.<br>
          → Klik → updater stáhne ZIP a aplikuje. Konfigurace zůstane.<br>
          → Manuální spuštění: <a href="admin/updater.html" style="color:#208438;font-weight:600">/admin/updater.html</a>
        </div>
      </div>

      <!-- ═══ Doporučujeme po prvním přihlášení ═══ -->
      <div style="background:#fff;border:1px solid #e5e5e7;border-radius:12px;padding:14px 18px;margin-bottom:14px">
        <strong>💡 Doporučujeme po prvním přihlášení:</strong>
        <ul style="font-size:12.5px;line-height:1.7;color:#1d1d1f;margin:6px 0 0;padding-left:20px">
          <li><strong>Denní zálohy DB</strong> — Nastavení → 🛠️ Údržba → Auto-backup ON (cron jednou denně)</li>
          <li><strong>Příští admin uživatel</strong> — Nastavení → Uživatelé → "Pozvat" (e-mailem)</li>
          <li><strong>Import dat z účetnictví</strong> — Nástroje → Migrace dat (POHODA, Money S3, FlexiBee, …)</li>
          <li><strong>Test e-mailu</strong> — Nastavení → SMTP → "Odeslat testovací" před tím než odešleš první fakturu</li>
          <li><strong>Mobile PWA</strong> — Otevři <code>/admin/</code> v mobilu → menu → "Přidat na plochu" → ikona jako app</li>
          <li><strong>🧹 Smazat demo data</strong> — Nastavení → 🛠️ Údržba → "Smazat demo data" (jedním klikem). Žádné riziko — smaže jen ukázkové výrobky/odběratele/objednávky, tvá nastavení a firma zůstávají. Vhodné dělat <em>před</em> ostrým provozem.</li>
        </ul>
      </div>

      <!-- ═══ Podpora / kontakt ═══ -->
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;font-size:12.5px;color:#6e6e73">
        <a href="https://appek.cz" target="_blank" style="color:#BA7517;text-decoration:none;font-weight:600">🌐 appek.cz</a>
        <span>·</span>
        <a href="mailto:info@appek.cz" style="color:#BA7517;text-decoration:none;font-weight:600">📧 info@appek.cz</a>
        <span>·</span>
        <a href="https://appek.cz/instalace.html" target="_blank" style="color:#BA7517;text-decoration:none;font-weight:600">📚 Návod</a>
        <span>·</span>
        <span>🆔 Licence: <code><?= htmlspecialchars($maskedKey) ?></code></span>
      </div>

      <!-- ═══ Big CTA ═══ -->
      <a href="admin/" class="btn" style="display:block;text-align:center;font-size:16px;padding:14px 24px"><?= t('done_admin_btn') ?></a>

    <?php endif; ?>

  </div>
</div>
</body></html>
