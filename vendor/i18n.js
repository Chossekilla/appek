// =============================================================
// 🌍 VENDOR PANEL i18n — Czech / English / Spanish
// =============================================================

const VENDOR_LANG_KEY = 'vendor_lang';
window.vendorLangs = [
  { code: 'cs', label: 'Čeština', flag: '🇨🇿' },
  { code: 'en', label: 'English', flag: '🇬🇧' },
  { code: 'es', label: 'Español', flag: '🇪🇸' },
];

window.vendorCurrentLang = (function() {
  try { return localStorage.getItem(VENDOR_LANG_KEY) || 'cs'; }
  catch (e) { return 'cs'; }
})();

window.setVendorLang = function(code) {
  if (!window.vendorLangs.find(l => l.code === code)) code = 'cs';
  window.vendorCurrentLang = code;
  try { localStorage.setItem(VENDOR_LANG_KEY, code); } catch (e) {}
  document.documentElement.lang = code;
  applyVendorTranslations();
};

const VENDOR_PHRASES = [
  // Login
  ['Vendor Panel',                     'Vendor Panel',                         'Panel del Vendedor'],
  ['Správa licenčních klíčů Appek B2B', 'Appek B2B license key management',    'Gestión de claves de licencia Appek B2B'],
  ['Správa licenčních klíčů Appek B2B · pro dodavatele', 'Appek B2B license management · for vendors', 'Gestión de licencias Appek B2B · para vendedores'],
  ['Username',                         'Username',                             'Usuario'],
  ['Heslo',                            'Password',                             'Contraseña'],
  ['🔓 Přihlásit',                      '🔓 Sign in',                            '🔓 Iniciar sesión'],
  ['🔐 Ověřit kód',                     '🔐 Verify code',                        '🔐 Verificar código'],
  ['🔐 2FA kód (6 číslic)',             '🔐 2FA code (6 digits)',                '🔐 Código 2FA (6 dígitos)'],
  ['Z aplikace Google Authenticator / Authy / 1Password / …', 'From Google Authenticator / Authy / 1Password / …', 'Desde Google Authenticator / Authy / 1Password / …'],
  ['Neplatné přihlašovací údaje.',     'Invalid credentials.',                 'Credenciales no válidas.'],

  // Topbar
  ['🏢 Vendor Panel',                  '🏢 Vendor Panel',                      '🏢 Panel del Vendedor'],
  ['Odhlásit',                         'Sign out',                             'Cerrar sesión'],

  // Stats cards
  ['CELKEM KLÍČŮ',                     'TOTAL KEYS',                           'CLAVES TOTALES'],
  ['AKTIVNÍ',                          'ACTIVE',                               'ACTIVOS'],
  ['EXPIRUJÍ DO 30 DNÍ',               'EXPIRING IN 30 DAYS',                  'VENCEN EN 30 DÍAS'],
  ['EXPIROVANÉ',                       'EXPIRED',                              'VENCIDOS'],
  ['REVOKED',                          'REVOKED',                              'REVOCADOS'],
  ['TENTO MĚSÍC',                      'THIS MONTH',                           'ESTE MES'],
  ['TRŽBY (ZAPLACENÉ)',                'REVENUE (PAID)',                       'INGRESOS (PAGADOS)'],

  // Toolbar
  ['🔍 Hledat zákazníka / klíč / email…', '🔍 Search customer / key / email…',  '🔍 Buscar cliente / clave / email…'],
  ['Všechny stavy',                    'All statuses',                         'Todos los estados'],
  ['✅ Aktivní',                       '✅ Active',                            '✅ Activo'],
  ['⏰ Expirované',                    '⏰ Expired',                           '⏰ Vencido'],
  ['🚫 Revoked',                       '🚫 Revoked',                           '🚫 Revocado'],
  ['+ Vygenerovat klíč',               '+ Generate key',                       '+ Generar clave'],
  ['Audit log',                        'Audit log',                            'Registro de auditoría'],
  ['Dvoufaktorové ověření (2FA)',      'Two-factor authentication (2FA)',      'Autenticación de dos factores (2FA)'],
  ['Změna hesla',                      'Change password',                      'Cambiar contraseña'],

  // Table headers
  ['Stav',                             'Status',                               'Estado'],
  ['Klíč',                             'Key',                                  'Clave'],
  ['Zákazník',                         'Customer',                             'Cliente'],
  ['🎁 Balíčky',                       '🎁 Modules',                           '🎁 Módulos'],
  ['Kontakt',                          'Contact',                              'Contacto'],
  ['Vydáno',                           'Issued',                               'Emitido'],
  ['Expirace',                         'Expiration',                           'Vencimiento'],
  ['Cena',                             'Price',                                'Precio'],

  // Empty state
  ['📭 Žádné licence — klikni „+ Vygenerovat klíč" pro první.', '📭 No licenses — click "+ Generate key" for the first.', '📭 Sin licencias — haz clic en "+ Generar clave" para la primera.'],

  // Generate modal
  ['🔑 Vygenerovat licenční klíč',     '🔑 Generate license key',              '🔑 Generar clave de licencia'],
  ['Jméno zákazníka *',                'Customer name *',                      'Nombre del cliente *'],
  ['Firma',                            'Company',                              'Empresa'],
  ['Email',                            'Email',                                'Email'],
  ['Telefon',                          'Phone',                                'Teléfono'],
  ['URL instalace',                    'Installation URL',                     'URL de instalación'],
  ['🎁 Balíčky k aktivaci',            '🎁 Modules to activate',               '🎁 Módulos a activar'],
  ['Core je vždy součástí. Vyber doplňkové balíčky (kódují se do klíče).', 'Core is always included. Select add-on modules (encoded into the key).', 'Core siempre está incluido. Selecciona módulos adicionales (codificados en la clave).'],
  ['💰 Doporučená cena',                '💰 Recommended price',                  '💰 Precio recomendado'],
  ['Expirace (volitelné)',             'Expiration (optional)',                'Vencimiento (opcional)'],
  ['Prázdné = navždy',                 'Empty = forever',                      'Vacío = para siempre'],
  ['Cena (Kč)',                        'Price (CZK)',                          'Precio (CZK)'],
  ['Poznámka',                         'Note',                                 'Nota'],
  ['Něco zvláštního? Speciální deal? ...', 'Anything special? Special deal? ...', '¿Algo especial? ¿Trato especial? ...'],
  ['💰 Zaplaceno',                      '💰 Paid',                               '💰 Pagado'],
  ['🎲 Vygenerovat klíč',              '🎲 Generate key',                      '🎲 Generar clave'],
  ['Zrušit',                           'Cancel',                               'Cancelar'],

  // Generated key modal
  ['🎉 Klíč vygenerován',              '🎉 Key generated',                     '🎉 Clave generada'],
  ['📋 Zkopírovat & zavřít',           '📋 Copy & close',                      '📋 Copiar y cerrar'],
  ['Klikni na klíč pro kopírování do schránky.', 'Click the key to copy to clipboard.', 'Haz clic en la clave para copiar al portapapeles.'],

  // Reissue
  ['🎁 Změnit balíčky licence',        '🎁 Change license modules',            '🎁 Cambiar módulos de licencia'],
  ['Vygeneruje se nový klíč s novou množinou balíčků. Starý klíč přestane platit. Pošli zákazníkovi nový klíč emailem — on ho vloží v adminu Nastavení → 🎁 Balíčky → Aktualizovat klíč.',
   'A new key will be generated with the new module set. The old key will stop working. Send the customer the new key — they paste it in Settings → 🎁 Modules → Update key.',
   'Se generará una nueva clave con el nuevo conjunto de módulos. La clave antigua dejará de funcionar. Envía al cliente la nueva clave.'],
  ['🎲 Vygenerovat nový klíč',          '🎲 Generate new key',                  '🎲 Generar nueva clave'],
  ['Současný klíč:',                   'Current key:',                         'Clave actual:'],
  ['Vyber balíčky:',                   'Select modules:',                      'Seleccionar módulos:'],
  ['🎉 Klíč přegenerován',             '🎉 Key regenerated',                   '🎉 Clave regenerada'],
  ['Nový klíč pro',                    'New key for',                          'Nueva clave para'],
  ['Předej zákazníkovi.',              'Hand over to customer.',               'Entregar al cliente.'],
  ['Starý klíč přestal platit. Zákazník MUSÍ vložit nový.', 'Old key no longer valid. Customer MUST insert new one.', 'La clave antigua ya no es válida. El cliente DEBE insertar la nueva.'],

  // Edit
  ['✏️ Upravit licenci',               '✏️ Edit license',                       '✏️ Editar licencia'],
  ['💾 Uložit',                         '💾 Save',                               '💾 Guardar'],

  // Revoke
  ['🚫 Revoke licenci',                '🚫 Revoke license',                    '🚫 Revocar licencia'],
  ['Revokace je jen tvoje evidence — zákazník to nepozná (žádný phone-home). Slouží ke správě tvého obchodu.',
   'Revocation is just your record — customer doesn\'t see it (no phone-home). For managing your business.',
   'La revocación es solo tu registro — el cliente no lo verá (sin phone-home). Para gestionar tu negocio.'],
  ['Důvod revokace *',                 'Revocation reason *',                  'Motivo de revocación *'],
  ['Nezaplaceno · porušení smlouvy · ...', 'Unpaid · contract breach · ...',    'Sin pagar · incumplimiento · ...'],
  ['Licence revoked',                  'License revoked',                      'Licencia revocada'],
  ['Vrátit licenci zpět na active?',   'Revert license back to active?',       '¿Volver la licencia a activa?'],
  ['Vráceno zpět',                     'Reverted',                             'Revertido'],

  // 2FA
  ['🔐 Dvoufaktorové ověření (zapnuto)', '🔐 Two-factor authentication (enabled)', '🔐 Autenticación de dos factores (activada)'],
  ['2FA je aktivní. Při dalším přihlášení budeš muset zadat 6místný kód z autentikační aplikace.',
   '2FA is active. Next login you\'ll need to enter a 6-digit code from the authenticator app.',
   '2FA está activo. En el próximo login deberás introducir un código de 6 dígitos de la app de autenticación.'],
  ['Chceš 2FA vypnout? Zadej aktuální 6místný kód jako potvrzení (chrání proti náhodnému vypnutí někým, kdo má jen heslo).',
   'Want to disable 2FA? Enter current 6-digit code as confirmation (protects against accidental disable by someone with just the password).',
   '¿Quieres desactivar 2FA? Introduce el código actual de 6 dígitos como confirmación.'],
  ['Aktuální 2FA kód',                 'Current 2FA code',                     'Código 2FA actual'],
  ['🔓 Vypnout 2FA',                    '🔓 Disable 2FA',                        '🔓 Desactivar 2FA'],
  ['🔐 Zapnout dvoufaktorové ověření', '🔐 Enable two-factor authentication',  '🔐 Activar autenticación de dos factores'],
  ['Krok 1:',                          'Step 1:',                              'Paso 1:'],
  ['Krok 2:',                          'Step 2:',                              'Paso 2:'],
  ['Otevři Google Authenticator / Authy / 1Password a naskenuj QR kód, nebo zadej secret ručně.',
   'Open Google Authenticator / Authy / 1Password and scan the QR code, or enter the secret manually.',
   'Abre Google Authenticator / Authy / 1Password y escanea el código QR, o introduce el secreto manualmente.'],
  ['Po naskenování zadej kód, který ti aplikace ukáže (6 číslic, mění se každých 30s).',
   'After scanning, enter the code the app shows you (6 digits, changes every 30s).',
   'Después de escanear, introduce el código que muestra la app (6 dígitos, cambia cada 30s).'],
  ['Secret (manuální zadání)',         'Secret (manual entry)',                'Secreto (entrada manual)'],
  ['Klik = kopírovat',                 'Click = copy',                         'Clic = copiar'],
  ['Ověřovací kód',                    'Verification code',                    'Código de verificación'],
  ['🔐 Zapnout 2FA',                    '🔐 Enable 2FA',                         '🔐 Activar 2FA'],

  // Audit log
  ['📜 Audit log (posledních 100 akcí)', '📜 Audit log (last 100 actions)',    '📜 Registro de auditoría (últimas 100 acciones)'],

  // Password change
  ['🔑 Změna hesla',                    '🔑 Change password',                    '🔑 Cambiar contraseña'],
  ['Staré heslo',                      'Old password',                         'Contraseña anterior'],
  ['Nové heslo (min 10)',              'New password (min 10)',                'Nueva contraseña (mín. 10)'],
  ['Nové heslo znovu',                 'New password again',                   'Nueva contraseña otra vez'],
  ['💾 Změnit',                         '💾 Change',                             '💾 Cambiar'],
  ['Heslo změněno',                    'Password changed',                     'Contraseña cambiada'],
  ['Hesla se neshodují',               'Passwords do not match',               'Las contraseñas no coinciden'],

  // Common
  ['Hotovo',                           'Done',                                 'Hecho'],
  ['Chyba:',                           'Error:',                               'Error:'],
  ['Uloženo',                          'Saved',                                'Guardado'],
  ['Smazat',                           'Delete',                               'Eliminar'],
  ['Upravit',                          'Edit',                                 'Editar'],
  ['Volání',                           'Calls',                                'Llamadas'],
  ['Naposled:',                        'Last:',                                'Último:'],
  ['nezaplaceno',                      'unpaid',                               'sin pagar'],
];

const VENDOR_LOOKUP = new Map();
for (const [cs, en, es] of VENDOR_PHRASES) {
  VENDOR_LOOKUP.set(cs, { en, es });
}

window.tv = function(key) {
  const entry = VENDOR_LOOKUP.get(key);
  if (!entry) return key;
  return entry[window.vendorCurrentLang] || key;
};

const SKIP_TAGS = new Set(['CODE', 'PRE', 'KBD', 'SCRIPT', 'STYLE', 'INPUT', 'TEXTAREA', 'SELECT', 'OPTION']);

function vendorTranslateNode(node) {
  let el = node.parentElement;
  while (el) {
    if (SKIP_TAGS.has(el.tagName)) return;
    if (el.dataset && el.dataset.noTranslate !== undefined) return;
    el = el.parentElement;
  }
  const raw = node.nodeValue;
  if (!raw || raw.length < 2 || raw.length > 500) return;
  const trimmed = raw.trim();
  if (!trimmed) return;
  const entry = VENDOR_LOOKUP.get(trimmed);
  if (!entry) return;
  const tgt = entry[window.vendorCurrentLang || 'cs'];
  if (!tgt) return;
  const leading  = raw.match(/^\s*/)[0];
  const trailing = raw.match(/\s*$/)[0];
  node.nodeValue = leading + tgt + trailing;
}

window.applyVendorTranslations = function(root) {
  if (!window.vendorCurrentLang || window.vendorCurrentLang === 'cs') return;
  root = root || document.body;
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
  let n;
  const list = [];
  while ((n = walker.nextNode())) {
    if (n.nodeValue && n.nodeValue.trim()) list.push(n);
  }
  list.forEach(vendorTranslateNode);

  // Translate placeholders, titles, etc.
  document.querySelectorAll('input[placeholder]').forEach(el => {
    const tgt = VENDOR_LOOKUP.get(el.placeholder.trim());
    if (tgt) el.placeholder = tgt[window.vendorCurrentLang] || el.placeholder;
  });
};

// MutationObserver for dynamic content (modals, lists)
function initVendorI18nObserver() {
  const observer = new MutationObserver((mutations) => {
    if (window.vendorCurrentLang === 'cs') return;
    for (const m of mutations) {
      m.addedNodes.forEach(n => {
        if (n.nodeType === 1) window.applyVendorTranslations(n);
      });
    }
  });
  observer.observe(document.body, { childList: true, subtree: true });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => { initVendorI18nObserver(); window.applyVendorTranslations(); });
} else {
  initVendorI18nObserver();
  setTimeout(() => window.applyVendorTranslations(), 100);
}
