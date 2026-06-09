// =============================================================
// 🌍 B2B PORTAL i18n — Czech / English / Spanish
// =============================================================

const B2B_LANG_KEY = 'b2b_lang';
window.b2bLangs = [
  { code: 'cs', label: 'CS', flag: '🇨🇿' },
  { code: 'en', label: 'EN', flag: '🇬🇧' },
  { code: 'es', label: 'ES', flag: '🇪🇸' },
];

window.b2bCurrentLang = (function() {
  try { return localStorage.getItem(B2B_LANG_KEY) || 'cs'; }
  catch (e) { return 'cs'; }
})();

window.setB2bLang = function(code) {
  if (!window.b2bLangs.find(l => l.code === code)) code = 'cs';
  if (code === window.b2bCurrentLang) return;   // stejný jazyk → nic
  try { localStorage.setItem(B2B_LANG_KEY, code); } catch (e) {}
  // okamžitá vizuální odezva na pill (než doběhne reload)
  document.querySelectorAll('.b2b-lang-pill').forEach(b => {
    b.classList.toggle('is-active', b.dataset.lang === code);
  });
  // 🆕 v3.0.184 — RELOAD: B2B překládá text přímo v DOM (in-place), což NEJDE vrátit
  //   na CS (text zůstal v EN/ES → přepínač vypadal „zaseklý"). Reload → init aplikuje
  //   uložený jazyk od nuly = spolehlivý přepnutí i návrat na češtinu.
  window.b2bCurrentLang = code;
  document.documentElement.lang = code;
  location.reload();
};

const B2B_PHRASES = [
  // Login / welcome
  ['Přihlášení',                       'Sign in',                              'Iniciar sesión'],
  ['Vítejte',                          'Welcome',                              'Bienvenido'],
  ['Vítejte v B2B portálu',            'Welcome to the B2B portal',            'Bienvenido al portal B2B'],
  ['E-mail',                           'E-mail',                               'E-mail'],
  ['Heslo',                            'Password',                             'Contraseña'],
  ['Přihlásit se',                     'Sign in',                              'Iniciar sesión'],
  ['Zapomenuté heslo?',                'Forgot password?',                     '¿Olvidaste la contraseña?'],
  ['Nemáte účet?',                     'No account?',                          '¿Sin cuenta?'],
  ['Kontaktujte dodavatele',           'Contact your supplier',                'Contacta a tu proveedor'],
  ['Špatný email nebo heslo',          'Wrong email or password',              'Email o contraseña incorrectos'],
  ['Odhlásit',                         'Sign out',                             'Cerrar sesión'],

  // Tabs
  ['Katalog',                          'Catalog',                              'Catálogo'],
  ['Košík',                            'Cart',                                 'Carrito'],
  ['Historie',                         'History',                              'Historial'],
  ['Přehled',                          'Overview',                             'Resumen'],
  ['Můj profil',                       'My profile',                           'Mi perfil'],

  // Catalog
  ['Hledat výrobky',                   'Search products',                      'Buscar productos'],
  ['Hledat',                           'Search',                               'Buscar'],
  ['Filtrovat',                        'Filter',                               'Filtrar'],
  ['Všechny',                          'All',                                  'Todos'],
  ['Všechny kategorie',                'All categories',                       'Todas las categorías'],
  ['Žádné výrobky',                    'No products',                          'Sin productos'],
  ['Přidat do košíku',                 'Add to cart',                          'Añadir al carrito'],
  ['Přidáno do košíku',                'Added to cart',                        'Añadido al carrito'],
  ['ks',                               'pcs',                                  'uds'],
  ['Cena za ks',                       'Price per piece',                      'Precio por unidad'],
  ['Cena za kg',                       'Price per kg',                         'Precio por kg'],
  ['Hmotnost',                         'Weight',                               'Peso'],
  ['Alergeny',                         'Allergens',                            'Alérgenos'],
  ['Složení',                          'Composition',                          'Composición'],
  ['Min. objednávka',                  'Min. order',                           'Pedido mín.'],
  ['Objednat do',                      'Order by',                             'Pedir hasta'],

  // Cart / checkout
  ['Můj košík',                        'My cart',                              'Mi carrito'],
  ['Prázdný košík',                    'Empty cart',                           'Carrito vacío'],
  ['Košík je prázdný',                 'Cart is empty',                        'El carrito está vacío'],
  ['Pokračovat v nákupu',              'Continue shopping',                    'Seguir comprando'],
  ['Pokračovat k objednávce',          'Continue to order',                    'Continuar al pedido'],
  ['Vyprázdnit košík',                 'Empty cart',                           'Vaciar carrito'],
  ['Odeslat objednávku',               'Submit order',                         'Enviar pedido'],
  ['Objednávka odeslána',              'Order submitted',                      'Pedido enviado'],
  ['Děkujeme za objednávku!',          'Thank you for your order!',            '¡Gracias por su pedido!'],
  ['Souhrn objednávky',                'Order summary',                        'Resumen del pedido'],
  ['Celkem bez DPH',                   'Total excl. VAT',                      'Total sin IVA'],
  ['Celkem s DPH',                     'Total incl. VAT',                      'Total con IVA'],
  ['DPH',                              'VAT',                                  'IVA'],
  ['Doprava',                          'Delivery',                             'Envío'],
  ['Doručit dne',                      'Deliver on',                           'Entregar el'],
  ['Datum dodání',                     'Delivery date',                        'Fecha de entrega'],
  ['Místo dodání',                     'Delivery location',                    'Lugar de entrega'],
  ['Poznámka k objednávce',            'Order note',                           'Nota del pedido'],
  ['Volitelná poznámka pro provoz…',   'Optional note for the supplier…',      'Nota opcional para el proveedor…'],
  ['Množství',                         'Quantity',                             'Cantidad'],
  ['Cena',                             'Price',                                'Precio'],
  ['Celkem',                           'Total',                                'Total'],
  ['Odebrat',                          'Remove',                               'Quitar'],
  ['Odebrat z košíku',                 'Remove from cart',                     'Quitar del carrito'],
  ['Vyberte místo dodání',             'Select delivery location',             'Selecciona lugar de entrega'],

  // History
  ['Moje objednávky',                  'My orders',                            'Mis pedidos'],
  ['Žádné objednávky',                 'No orders',                            'Sin pedidos'],
  ['Nemáte žádné objednávky',          'You have no orders',                   'No tienes pedidos'],
  ['Číslo objednávky',                 'Order number',                         'Número de pedido'],
  ['Datum objednání',                  'Order date',                           'Fecha de pedido'],
  ['Stav',                             'Status',                               'Estado'],
  ['Detail',                           'Detail',                               'Detalle'],
  ['Otevřít detail',                   'Open detail',                          'Abrir detalle'],
  ['Stáhnout fakturu',                 'Download invoice',                     'Descargar factura'],
  ['Stáhnout DL',                      'Download DN',                          'Descargar albarán'],
  ['Znovu objednat',                   'Reorder',                              'Volver a pedir'],

  // Statuses
  ['Nová',                             'New',                                  'Nuevo'],
  ['Potvrzená',                        'Confirmed',                            'Confirmado'],
  ['Ve výrobě',                        'In production',                        'En producción'],
  ['Připravená',                       'Ready',                                'Lista'],
  ['Expedovaná',                       'Shipped',                              'Enviado'],
  ['Doručená',                         'Delivered',                            'Entregado'],
  ['Zrušená',                          'Cancelled',                            'Cancelado'],

  // Push banner
  ['Zapnout upozornění',               'Enable notifications',                 'Activar notificaciones'],
  ['Push upozornění',                  'Push notifications',                   'Notificaciones push'],
  ['Buďte informováni o stavu objednávek', 'Stay informed about your orders',  'Mantente informado sobre tus pedidos'],
  ['Zapnout',                          'Enable',                               'Activar'],
  ['Později',                          'Later',                                'Más tarde'],

  // Install banner
  ['Nainstaluj jako aplikaci',         'Install as app',                       'Instalar como app'],
  ['Rychlejší přístup z plochy, push notifikace.', 'Faster access from home screen, push notifications.', 'Acceso más rápido, notificaciones push.'],
  ['Instalovat',                       'Install',                              'Instalar'],

  // Common
  ['Uložit',                           'Save',                                 'Guardar'],
  ['Zrušit',                           'Cancel',                               'Cancelar'],
  ['Zavřít',                           'Close',                                'Cerrar'],
  ['Zpět',                             'Back',                                 'Atrás'],
  ['Načítám…',                         'Loading…',                             'Cargando…'],
  ['Chyba',                            'Error',                                'Error'],
  ['Hotovo',                           'Done',                                 'Hecho'],
  ['Ano',                              'Yes',                                  'Sí'],
  ['Ne',                               'No',                                   'No'],

  // Cart actions
  ['Vyberte alespoň jeden výrobek',    'Select at least one product',          'Selecciona al menos un producto'],
  ['Vyberte datum dodání',             'Select delivery date',                 'Selecciona fecha de entrega'],
  ['Uzávěrka objednávek',              'Order deadline',                       'Plazo de pedidos'],
  ['Pro tento den je uzávěrka',        'Deadline for this day is',             'El plazo para este día es'],

  // Footer
  ['Online prodejna',                  'Online store',                         'Tienda en línea'],
  ['Veškerá práva vyhrazena',          'All rights reserved',                  'Todos los derechos reservados'],

  // ───────── B2B Portal — rozšířené překlady ─────────
  ['Vybrat vše',                       'Select all',                           'Seleccionar todo'],
  ['Odznačit vše',                     'Deselect all',                         'Deseleccionar todo'],
  ['Vybráno',                          'Selected',                             'Seleccionado'],
  ['Nic nevybráno',                    'Nothing selected',                     'Nada seleccionado'],
  ['Filtrovat výrobky',                'Filter products',                      'Filtrar productos'],
  ['Hledat výrobek…',                  'Search product…',                      'Buscar producto…'],
  ['Hledat v katalogu',                'Search catalog',                       'Buscar en el catálogo'],
  ['Žádné výrobky',                    'No products',                          'Sin productos'],
  ['Žádný výsledek',                   'No results',                           'Sin resultados'],
  ['Nepřiřazeno',                      'Unassigned',                           'Sin asignar'],
  ['Vyberte kategorii',                'Select category',                      'Selecciona categoría'],
  ['Všechny kategorie',                'All categories',                       'Todas las categorías'],
  ['Nejprodávanější',                  'Best sellers',                         'Más vendidos'],
  ['Doporučené',                       'Recommended',                          'Recomendados'],
  ['Nové',                             'New',                                  'Nuevo'],
  ['Akce',                             'Promo',                                'Promoción'],
  ['Sleva',                            'Discount',                             'Descuento'],
  ['Z',                                'From',                                 'Desde'],
  ['Do',                               'To',                                   'Hasta'],
  ['Detail výrobku',                   'Product detail',                       'Detalle del producto'],
  ['Popis výrobku',                    'Product description',                  'Descripción del producto'],
  ['Alergeny',                         'Allergens',                            'Alérgenos'],
  ['Trvanlivost',                      'Shelf life',                           'Vida útil'],
  ['Skladovací podmínky',              'Storage conditions',                   'Condiciones de almacenamiento'],
  ['Složení',                          'Ingredients',                          'Ingredientes'],
  ['Nutriční hodnoty',                 'Nutrition facts',                      'Información nutricional'],
  ['Energie',                          'Energy',                               'Energía'],
  ['Bílkoviny',                        'Protein',                              'Proteínas'],
  ['Tuky',                             'Fat',                                  'Grasas'],
  ['Sacharidy',                        'Carbohydrates',                        'Carbohidratos'],
  ['Cukry',                            'Sugars',                               'Azúcares'],
  ['Sůl',                              'Salt',                                 'Sal'],
  ['Vláknina',                         'Fiber',                                'Fibra'],
  ['na 100 g',                         'per 100 g',                            'por 100 g'],

  // Cart deep
  ['Vyprázdnit košík',                 'Empty cart',                           'Vaciar carrito'],
  ['Opravdu vyprázdnit košík?',        'Empty cart for sure?',                 '¿Vaciar el carrito?'],
  ['Košík byl vyprázdněn',             'Cart emptied',                         'Carrito vaciado'],
  ['Pokračovat v nákupu',              'Continue shopping',                    'Seguir comprando'],
  ['Pokračovat',                       'Continue',                             'Continuar'],
  ['Dokončit objednávku',              'Complete order',                       'Completar pedido'],
  ['Souhrn objednávky',                'Order summary',                        'Resumen del pedido'],
  ['Položek v košíku',                 'Items in cart',                        'Artículos en el carrito'],
  ['Mezisoučet',                       'Subtotal',                             'Subtotal'],
  ['Doprava',                          'Shipping',                             'Envío'],
  ['Daň (DPH)',                        'Tax (VAT)',                            'Impuesto (IVA)'],
  ['Sleva celkem',                     'Total discount',                       'Descuento total'],
  ['K úhradě',                         'Total due',                            'Total a pagar'],
  ['Bezplatné pro objednávky nad',     'Free for orders over',                 'Gratis para pedidos superiores a'],
  ['Po objednání obdržíte potvrzení e-mailem',
                                       'You will receive confirmation by email after ordering',
                                                                               'Recibirás confirmación por email después del pedido'],
  ['Objednávka odeslána',              'Order submitted',                      'Pedido enviado'],
  ['Objednávka byla úspěšně odeslána', 'Order has been submitted successfully', 'El pedido se ha enviado correctamente'],

  // Address / delivery
  ['Adresa dodání',                    'Delivery address',                     'Dirección de entrega'],
  ['Fakturační adresa',                'Billing address',                      'Dirección de facturación'],
  ['Stejná jako dodací adresa',        'Same as delivery address',             'Igual que la dirección de entrega'],
  ['Ulice',                            'Street',                               'Calle'],
  ['Číslo popisné',                    'House number',                         'Número'],
  ['Město',                            'City',                                 'Ciudad'],
  ['PSČ',                              'ZIP code',                             'Código postal'],
  ['Země',                             'Country',                              'País'],
  ['Telefon',                          'Phone',                                'Teléfono'],
  ['IČO',                              'Company ID',                           'ID de empresa'],
  ['DIČ',                              'VAT ID',                               'ID de IVA'],
  ['Společnost',                       'Company',                              'Empresa'],
  ['Pobočka',                          'Branch',                               'Sucursal'],
  ['Místo doručení',                   'Delivery location',                    'Lugar de entrega'],
  ['Vyberte místo dodání',             'Select delivery location',             'Selecciona lugar de entrega'],
  ['Hlavní pobočka',                   'Main branch',                          'Sucursal principal'],

  // History
  ['Historie objednávek',              'Order history',                        'Historial de pedidos'],
  ['Aktivní objednávky',               'Active orders',                        'Pedidos activos'],
  ['Dokončené objednávky',             'Completed orders',                     'Pedidos completados'],
  ['Zrušené objednávky',               'Cancelled orders',                     'Pedidos cancelados'],
  ['Číslo objednávky',                 'Order number',                         'Número de pedido'],
  ['Datum',                            'Date',                                 'Fecha'],
  ['Stav',                             'Status',                               'Estado'],
  ['Akce',                             'Action',                               'Acción'],
  ['Detail',                           'Detail',                               'Detalle'],
  ['Stáhnout PDF',                     'Download PDF',                         'Descargar PDF'],
  ['Stáhnout dodací list',             'Download delivery note',               'Descargar nota de entrega'],
  ['Stáhnout fakturu',                 'Download invoice',                     'Descargar factura'],
  ['Objednat znovu',                   'Reorder',                              'Pedir de nuevo'],
  ['Přidat do košíku',                 'Add to cart',                          'Añadir al carrito'],
  ['Přidáno do košíku',                'Added to cart',                        'Añadido al carrito'],
  ['Položka odebrána',                 'Item removed',                         'Artículo eliminado'],
  ['Množství upraveno',                'Quantity updated',                     'Cantidad actualizada'],

  // Profile
  ['Osobní údaje',                     'Personal information',                 'Datos personales'],
  ['Údaje o firmě',                    'Company information',                  'Información de la empresa'],
  ['Změna hesla',                      'Change password',                      'Cambiar contraseña'],
  ['Současné heslo',                   'Current password',                     'Contraseña actual'],
  ['Nové heslo',                       'New password',                         'Nueva contraseña'],
  ['Potvrzení hesla',                  'Confirm password',                     'Confirmar contraseña'],
  ['Hesla se neshodují',               'Passwords don\'t match',               'Las contraseñas no coinciden'],
  ['Heslo úspěšně změněno',            'Password successfully changed',        'Contraseña cambiada exitosamente'],
  ['Heslo je příliš krátké',           'Password too short',                   'Contraseña demasiado corta'],
  ['Min. 8 znaků',                     'Min. 8 characters',                    'Mín. 8 caracteres'],
  ['Vyžaduje velké písmeno',           'Requires uppercase letter',            'Requiere mayúscula'],
  ['Vyžaduje číslici',                 'Requires a digit',                     'Requiere un dígito'],

  // Notifications
  ['Notifikace',                       'Notifications',                        'Notificaciones'],
  ['Žádné nové notifikace',            'No new notifications',                 'Sin nuevas notificaciones'],
  ['Označit jako přečtené',            'Mark as read',                         'Marcar como leído'],
  ['Označit vše jako přečtené',        'Mark all as read',                     'Marcar todo como leído'],

  // Favorites / Templates
  ['Oblíbené',                         'Favorites',                            'Favoritos'],
  ['Přidat do oblíbených',             'Add to favorites',                     'Añadir a favoritos'],
  ['Odebrat z oblíbených',             'Remove from favorites',                'Quitar de favoritos'],
  ['Šablony objednávek',               'Order templates',                      'Plantillas de pedidos'],
  ['Nová šablona',                     'New template',                         'Nueva plantilla'],
  ['Uložit jako šablonu',              'Save as template',                     'Guardar como plantilla'],
  ['Použít šablonu',                   'Use template',                         'Usar plantilla'],
  ['Šablona uložena',                  'Template saved',                       'Plantilla guardada'],
  ['Smazat šablonu?',                  'Delete template?',                     '¿Eliminar plantilla?'],

  // Search / empty / errors
  ['Vyhledávání…',                     'Searching…',                           'Buscando…'],
  ['Načítám výrobky…',                 'Loading products…',                    'Cargando productos…'],
  ['Načítám historii…',                'Loading history…',                     'Cargando historial…'],
  ['Nepodařilo se načíst data',        'Failed to load data',                  'No se pudieron cargar los datos'],
  ['Zkuste to znovu',                  'Try again',                            'Inténtalo de nuevo'],
  ['Nepodařilo se připojit k serveru', 'Failed to connect to server',          'No se pudo conectar al servidor'],
  ['Žádné připojení k internetu',      'No internet connection',               'Sin conexión a internet'],
  ['Připojení obnoveno',               'Connection restored',                  'Conexión restaurada'],

  // POS mode
  ['POS režim',                        'POS mode',                             'Modo POS'],
  ['Dotykový režim',                   'Touch mode',                           'Modo táctil'],
  ['Klasický režim',                   'Classic mode',                         'Modo clásico'],

  // Misc
  ['Čeština',                          'Czech',                                'Checo'],
  ['Angličtina',                       'English',                              'Inglés'],
  ['Španělština',                      'Spanish',                              'Español'],
  ['Jazyk',                            'Language',                             'Idioma'],
  ['Vyberte jazyk',                    'Select language',                      'Selecciona idioma'],
  ['Tmavý režim',                      'Dark mode',                            'Modo oscuro'],
  ['Světlý režim',                     'Light mode',                           'Modo claro'],
  ['Celá obrazovka',                   'Fullscreen',                           'Pantalla completa'],
  ['Odhlášení',                        'Sign out',                             'Cerrar sesión'],
  ['Opravdu se odhlásit?',             'Really sign out?',                     '¿Cerrar sesión?'],

  // ───────── B2B BATCH 1 — LOGIN / WELCOME ─────────
  ['Vítejte zpět',                     'Welcome back',                         'Bienvenido de nuevo'],
  ['Dobrý den',                        'Hello',                                'Hola'],
  ['Dobré ráno',                       'Good morning',                         'Buenos días'],
  ['Dobré odpoledne',                  'Good afternoon',                       'Buenas tardes'],
  ['Dobrý večer',                      'Good evening',                         'Buenas noches'],
  ['Přihlaste se prosím',              'Please sign in',                       'Por favor inicia sesión'],
  ['Zapomněli jste heslo?',            'Forgot your password?',                '¿Olvidaste tu contraseña?'],
  ['Reset hesla',                      'Reset password',                       'Restablecer contraseña'],
  ['Odkaz pro reset hesla',            'Password reset link',                  'Enlace para restablecer contraseña'],
  ['Zaslat odkaz',                     'Send link',                            'Enviar enlace'],
  ['Odkaz byl odeslán',                'Link has been sent',                   'Enlace enviado'],
  ['Zkontrolujte e-mail',              'Check your email',                     'Revisa tu correo'],
  ['Nové heslo',                       'New password',                         'Nueva contraseña'],
  ['Potvrdit nové heslo',              'Confirm new password',                 'Confirmar nueva contraseña'],
  ['Heslo bylo změněno',               'Password has been changed',            'Contraseña cambiada'],
  ['Můžete se přihlásit',              'You can sign in',                      'Puedes iniciar sesión'],
  ['Zůstaň přihlášen',                 'Stay signed in',                       'Permanecer conectado'],
  ['Neukládat na tomto zařízení',      'Do not save on this device',           'No guardar en este dispositivo'],
  ['Veřejné zařízení',                 'Public device',                        'Dispositivo público'],
  ['Soukromé zařízení',                'Private device',                       'Dispositivo privado'],
  ['Zadejte heslo',                    'Enter password',                       'Introduce contraseña'],
  ['Zadejte e-mail',                   'Enter email',                          'Introduce email'],
  ['Příliš mnoho pokusů',              'Too many attempts',                    'Demasiados intentos'],
  ['Zkuste to za 10 minut',            'Try again in 10 minutes',              'Inténtalo en 10 minutos'],
  ['Účet je dočasně zablokován',       'Account temporarily locked',           'Cuenta temporalmente bloqueada'],
  ['Účet vyžaduje aktivaci',           'Account requires activation',          'La cuenta requiere activación'],

  // ───────── B2B BATCH 1 — TOP NAV ─────────
  ['Domů',                             'Home',                                 'Inicio'],
  ['Můj účet',                         'My account',                           'Mi cuenta'],
  ['Nabídka',                          'Menu',                                 'Menú'],
  ['Akce a slevy',                     'Promotions',                           'Promociones y descuentos'],
  ['Novinky',                          'News',                                 'Novedades'],
  ['Doporučujeme',                     'We recommend',                         'Recomendamos'],
  ['Sezónní výrobky',                  'Seasonal products',                    'Productos de temporada'],
  ['Vrátit zpět',                      'Go back',                              'Volver'],
  ['Vrátit na nákup',                  'Back to shopping',                     'Volver a la compra'],
  ['Vrátit na hlavní',                 'Back to home',                         'Volver al inicio'],

  // ───────── B2B BATCH 1 — KATALOG ─────────
  ['Filtrovat dle kategorií',          'Filter by categories',                 'Filtrar por categorías'],
  ['Filtrovat dle ceny',               'Filter by price',                      'Filtrar por precio'],
  ['Filtrovat dle dostupnosti',        'Filter by availability',               'Filtrar por disponibilidad'],
  ['Pouze skladem',                    'In stock only',                        'Solo en stock'],
  ['Pouze v akci',                     'On sale only',                         'Solo en promoción'],
  ['Pouze novinky',                    'New only',                             'Solo nuevos'],
  ['Pouze sezónní',                    'Seasonal only',                        'Solo de temporada'],
  ['Seřadit dle ceny',                 'Sort by price',                        'Ordenar por precio'],
  ['Seřadit dle abecedy',              'Sort alphabetically',                  'Ordenar alfabéticamente'],
  ['Seřadit dle popularity',           'Sort by popularity',                   'Ordenar por popularidad'],
  ['Seřadit dle nejnovějších',         'Sort by newest',                       'Ordenar por más nuevos'],
  ['Cena od nejnižší',                 'Price low to high',                    'Precio de menor a mayor'],
  ['Cena od nejvyšší',                 'Price high to low',                    'Precio de mayor a menor'],
  ['Od A do Z',                        'A to Z',                               'De A a Z'],
  ['Od Z do A',                        'Z to A',                               'De Z a A'],
  ['Mřížkový pohled',                  'Grid view',                            'Vista en cuadrícula'],
  ['Seznam',                           'List view',                            'Vista de lista'],
  ['Kompaktní zobrazení',              'Compact view',                         'Vista compacta'],
  ['Detailní zobrazení',               'Detailed view',                        'Vista detallada'],
  ['Zobrazit obrázky',                 'Show images',                          'Mostrar imágenes'],
  ['Skrýt obrázky',                    'Hide images',                          'Ocultar imágenes'],
  ['Filtrovat',                        'Filter',                               'Filtrar'],
  ['Vymazat filtry',                   'Clear filters',                        'Limpiar filtros'],
  ['Použít filtry',                    'Apply filters',                        'Aplicar filtros'],
  ['Aktivní filtry',                   'Active filters',                       'Filtros activos'],

  // ───────── B2B BATCH 1 — PRODUKT DETAIL ─────────
  ['Cena za jednotku',                 'Price per unit',                       'Precio por unidad'],
  ['Cena za kus',                      'Price per piece',                      'Precio por pieza'],
  ['Cena za kg',                       'Price per kg',                         'Precio por kg'],
  ['Cena za balení',                   'Price per package',                    'Precio por paquete'],
  ['Cena celkem',                      'Total price',                          'Precio total'],
  ['Cena s DPH',                       'Price incl. VAT',                      'Precio con IVA'],
  ['Cena bez DPH',                     'Price excl. VAT',                      'Precio sin IVA'],
  ['Ušetříte',                         'You save',                             'Ahorras'],
  ['Sleva',                            'Discount',                             'Descuento'],
  ['Akční cena',                       'Promo price',                          'Precio promocional'],
  ['Původní cena',                     'Original price',                       'Precio original'],
  ['Vaše cena',                        'Your price',                           'Tu precio'],
  ['Cena pro váš účet',                'Your account price',                   'Precio para tu cuenta'],
  ['B2B cena',                         'B2B price',                            'Precio B2B'],
  ['Velkoobchodní cena',               'Wholesale price',                      'Precio mayorista'],
  ['Maloobchodní cena',                'Retail price',                         'Precio minorista'],
  ['Nejlepší cena',                    'Best price',                           'Mejor precio'],
  ['Cena na poptávku',                 'Price on request',                     'Precio bajo demanda'],
  ['Volat pro cenu',                   'Call for price',                       'Llama para precio'],
  ['Spočítat cenu',                    'Calculate price',                      'Calcular precio'],

  // ───────── B2B BATCH 1 — DOSTUPNOST ─────────
  ['Dostupné',                         'Available',                            'Disponible'],
  ['Skladem',                          'In stock',                             'En stock'],
  ['Skladem do',                       'In stock until',                       'En stock hasta'],
  ['Posledních kusů skladem',          'Last pieces in stock',                 'Últimas piezas'],
  ['Pouze',                            'Only',                                 'Solo'],
  ['kusů skladem',                     'pieces in stock',                      'piezas en stock'],
  ['Vyprodáno',                        'Sold out',                             'Agotado'],
  ['Nedostupné',                       'Unavailable',                          'No disponible'],
  ['Dočasně nedostupné',               'Temporarily unavailable',              'Temporalmente no disponible'],
  ['Brzy v nabídce',                   'Coming soon',                          'Próximamente'],
  ['Předobjednávka',                   'Pre-order',                            'Reserva'],
  ['Předobjednat',                     'Pre-order',                            'Reservar'],
  ['K objednání',                      'On order',                             'Por pedido'],
  ['Doručíme do',                      'We deliver by',                        'Entregamos en'],
  ['Vyrobíme na míru',                 'Made to order',                        'A medida'],
  ['Dle dostupnosti',                  'Subject to availability',              'Según disponibilidad'],
  ['Sledovat dostupnost',              'Track availability',                   'Seguir disponibilidad'],
  ['Upozornit, až bude skladem',       'Notify when in stock',                 'Avisar cuando esté disponible'],

  // ───────── B2B BATCH 1 — VARIANTY ─────────
  ['Vyberte variantu',                 'Select variant',                       'Selecciona variante'],
  ['Vyberte velikost',                 'Select size',                          'Selecciona tamaño'],
  ['Vyberte barvu',                    'Select color',                         'Selecciona color'],
  ['Vyberte hmotnost',                 'Select weight',                        'Selecciona peso'],
  ['Vyberte balení',                   'Select package',                       'Selecciona paquete'],
  ['Velikost',                         'Size',                                 'Tamaño'],
  ['Hmotnost',                         'Weight',                               'Peso'],
  ['Balení',                           'Package',                              'Paquete'],
  ['Barva',                            'Color',                                'Color'],
  ['Příchuť',                          'Flavor',                               'Sabor'],
  ['Originální balení',                'Original package',                     'Paquete original'],
  ['Dárkové balení',                   'Gift package',                         'Envoltorio regalo'],
  ['Sezónní balení',                   'Seasonal package',                     'Paquete de temporada'],

  // ───────── B2B BATCH 1 — KOŠÍK ZÁKLADY ─────────
  ['Přidat do košíku',                 'Add to cart',                          'Añadir al carrito'],
  ['Vložit do košíku',                 'Add to cart',                          'Poner al carrito'],
  ['Přidáno do košíku',                'Added to cart',                        'Añadido al carrito'],
  ['Odebrat z košíku',                 'Remove from cart',                     'Quitar del carrito'],
  ['Odebráno z košíku',                'Removed from cart',                    'Quitado del carrito'],
  ['V košíku',                         'In cart',                              'En el carrito'],
  ['Aktualizovat košík',               'Update cart',                          'Actualizar carrito'],
  ['Košík je prázdný',                 'Cart is empty',                        'El carrito está vacío'],
  ['Žádné položky v košíku',           'No items in cart',                     'Sin artículos en el carrito'],
  ['Začněte s nákupem',                'Start shopping',                       'Empezar a comprar'],
  ['Prohlédnout katalog',              'Browse catalog',                       'Explorar catálogo'],
  ['Zpět do katalogu',                 'Back to catalog',                      'Volver al catálogo'],
  ['Pokračovat v objednávce',          'Continue with order',                  'Continuar con el pedido'],
  ['Přejít k pokladně',                'Go to checkout',                       'Ir al pago'],
  ['Snížit množství',                  'Decrease quantity',                    'Reducir cantidad'],
  ['Zvýšit množství',                  'Increase quantity',                    'Aumentar cantidad'],
  ['Nastavit množství',                'Set quantity',                         'Establecer cantidad'],
  ['Maximální množství',               'Maximum quantity',                     'Cantidad máxima'],
  ['Minimální množství',               'Minimum quantity',                     'Cantidad mínima'],
  ['Minimum pro objednávku',           'Minimum order',                        'Pedido mínimo'],
  ['Krok množství',                    'Quantity step',                        'Paso de cantidad'],

  // ───────── B2B BATCH 1 — OBJEDNÁVKA ─────────
  ['Vaše objednávka',                  'Your order',                           'Tu pedido'],
  ['Detail objednávky',                'Order details',                        'Detalles del pedido'],
  ['Čísla objednávky',                 'Order number',                         'Número de pedido'],
  ['Datum objednávky',                 'Order date',                           'Fecha del pedido'],
  ['Datum dodání',                     'Delivery date',                        'Fecha de entrega'],
  ['Preferované datum dodání',         'Preferred delivery date',              'Fecha preferida de entrega'],
  ['Čas dodání',                       'Delivery time',                        'Hora de entrega'],
  ['Časové okno',                      'Time window',                          'Ventana horaria'],
  ['Ráno (8-12)',                      'Morning (8-12)',                       'Mañana (8-12)'],
  ['Odpoledne (12-16)',                'Afternoon (12-16)',                    'Tarde (12-16)'],
  ['Večer (16-20)',                    'Evening (16-20)',                      'Noche (16-20)'],
  ['Celý den',                         'All day',                              'Todo el día'],
  ['Stejné pro celou objednávku',      'Same for entire order',                'Igual para todo el pedido'],
  ['Po částech',                       'In parts',                             'Por partes'],

  // ───────── B2B BATCH 1 — ČETNOST ─────────
  ['Jednorázová objednávka',           'One-time order',                       'Pedido único'],
  ['Pravidelná objednávka',            'Recurring order',                      'Pedido recurrente'],
  ['Opakovat každý týden',             'Repeat every week',                    'Repetir cada semana'],
  ['Opakovat každé 2 týdny',           'Repeat every 2 weeks',                 'Repetir cada 2 semanas'],
  ['Opakovat každý měsíc',             'Repeat every month',                   'Repetir cada mes'],
  ['Vlastní frekvence',                'Custom frequency',                     'Frecuencia personalizada'],
  ['První dodávka',                    'First delivery',                       'Primera entrega'],
  ['Poslední dodávka',                 'Last delivery',                        'Última entrega'],
  ['Pozastavit opakování',             'Pause recurrence',                     'Pausar recurrencia'],
  ['Obnovit opakování',                'Resume recurrence',                    'Reanudar recurrencia'],
  ['Zrušit opakování',                 'Cancel recurrence',                    'Cancelar recurrencia'],
  ['Příští dodávka',                   'Next delivery',                        'Próxima entrega'],

  // ───────── B2B BATCH 1 — VYPOČÍTAT ─────────
  ['Spočítat celkem',                  'Calculate total',                      'Calcular total'],
  ['Spočítat dopravu',                 'Calculate shipping',                   'Calcular envío'],
  ['Spočítat slevu',                   'Calculate discount',                   'Calcular descuento'],
  ['Cena s dopravou',                  'Price with shipping',                  'Precio con envío'],
  ['Cena bez dopravy',                 'Price without shipping',               'Precio sin envío'],
  ['Doprava zdarma od',                'Free shipping from',                   'Envío gratis desde'],
  ['Doprava zdarma',                   'Free shipping',                        'Envío gratis'],
  ['Platba zdarma',                    'Free payment',                         'Pago gratuito'],
  ['Doprava placená',                  'Paid shipping',                        'Envío pagado'],
  ['Doprava účtována',                 'Shipping charged',                    'Envío facturado'],

  // ───────── B2B BATCH 1 — ADRESY ─────────
  ['Stejná jako fakturační',           'Same as billing',                      'Igual que facturación'],
  ['Stejná jako dodací',               'Same as delivery',                     'Igual que entrega'],
  ['Jiná adresa',                      'Different address',                    'Dirección diferente'],
  ['Přidat adresu',                    'Add address',                          'Añadir dirección'],
  ['Upravit adresu',                   'Edit address',                         'Editar dirección'],
  ['Smazat adresu',                    'Delete address',                       'Eliminar dirección'],
  ['Uložit jako výchozí',              'Save as default',                      'Guardar como predeterminado'],
  ['Výchozí adresa',                   'Default address',                      'Dirección predeterminada'],
  ['Mé adresy',                        'My addresses',                         'Mis direcciones'],
  ['Žádné uložené adresy',             'No saved addresses',                   'Sin direcciones guardadas'],
  ['Korespondenční adresa',            'Mailing address',                      'Dirección postal'],
  ['Sídlo firmy',                      'Company HQ',                           'Sede de empresa'],

  // ───────── B2B BATCH 2 — KOŠÍK DEEP ─────────
  ['Souhrn košíku',                    'Cart summary',                         'Resumen del carrito'],
  ['Cena za zboží',                    'Goods price',                          'Precio de mercancía'],
  ['Cena za dopravu',                  'Shipping price',                       'Precio de envío'],
  ['Cena za platbu',                   'Payment price',                        'Precio de pago'],
  ['Cena za balení',                   'Packaging price',                      'Precio de embalaje'],
  ['Sleva celkem',                     'Total discount',                       'Descuento total'],
  ['Z toho DPH',                       'Of which VAT',                         'De los cuales IVA'],
  ['Celkem k úhradě',                  'Total to pay',                         'Total a pagar'],
  ['Celkem za měsíc',                  'Monthly total',                        'Total mensual'],
  ['Průměrná cena objednávky',         'Average order price',                  'Precio medio de pedido'],
  ['Můj poslední nákup',               'My last purchase',                     'Mi última compra'],
  ['Připomenutí',                      'Reminders',                            'Recordatorios'],
  ['Sledování objednávky',             'Order tracking',                       'Seguimiento de pedido'],
  ['Stav objednávky',                  'Order status',                         'Estado del pedido'],
  ['Status v reálném čase',            'Real-time status',                     'Estado en tiempo real'],

  // ───────── B2B BATCH 2 — STAVY OBJEDNÁVEK ─────────
  ['Přijatá',                          'Accepted',                             'Aceptado'],
  ['Schválená',                        'Approved',                             'Aprobado'],
  ['Ve výrobě',                        'In production',                        'En producción'],
  ['Vyrobená',                         'Manufactured',                         'Fabricado'],
  ['Připravená',                       'Prepared',                             'Preparado'],
  ['Expedovaná',                       'Dispatched',                           'Enviado'],
  ['Na cestě',                         'In transit',                           'En camino'],
  ['Doručená',                         'Delivered',                            'Entregado'],
  ['Vyfakturovaná',                    'Invoiced',                             'Facturado'],
  ['Uhrazená',                         'Paid',                                 'Pagado'],
  ['Stornovaná',                       'Cancelled',                            'Cancelado'],
  ['Vrácená',                          'Returned',                             'Devuelto'],
  ['Reklamovaná',                      'Complained',                           'Reclamado'],
  ['Vyřešená reklamace',               'Complaint resolved',                   'Reclamación resuelta'],
  ['Pozastavená',                      'On hold',                              'En espera'],
  ['Čekající na schválení',            'Awaiting approval',                    'Esperando aprobación'],
  ['Vrácená k úpravě',                 'Returned for editing',                 'Devuelto para edición'],

  // ───────── B2B BATCH 2 — HISTORIE ─────────
  ['Moje objednávky',                  'My orders',                            'Mis pedidos'],
  ['Aktivní',                          'Active',                               'Activos'],
  ['Vyřízené',                         'Completed',                            'Completados'],
  ['Všechny objednávky',               'All orders',                           'Todos los pedidos'],
  ['Hledat v objednávkách',            'Search orders',                        'Buscar pedidos'],
  ['Filtrovat objednávky',             'Filter orders',                        'Filtrar pedidos'],
  ['Tento měsíc',                      'This month',                           'Este mes'],
  ['Minulý měsíc',                     'Last month',                           'Mes pasado'],
  ['Tento rok',                        'This year',                            'Este año'],
  ['Minulý rok',                       'Last year',                            'Año pasado'],
  ['Vlastní období',                   'Custom period',                        'Período personalizado'],
  ['Statistiky',                       'Statistics',                           'Estadísticas'],
  ['Můj přehled',                      'My overview',                          'Mi resumen'],
  ['Měsíční přehled',                  'Monthly overview',                     'Resumen mensual'],
  ['Roční přehled',                    'Annual overview',                      'Resumen anual'],
  ['Suma za období',                   'Period total',                         'Total del período'],
  ['Průměrný měsíc',                   'Average month',                        'Mes promedio'],
  ['Nejvyšší měsíc',                   'Highest month',                        'Mes más alto'],
  ['Počet objednávek',                 'Order count',                          'Número de pedidos'],
  ['Počet kusů',                       'Piece count',                          'Número de piezas'],
  ['Top výrobky',                      'Top products',                         'Top productos'],
  ['Top kategorie',                    'Top categories',                       'Categorías top'],
  ['Nejčastěji objednávané',           'Most frequently ordered',              'Más pedidos'],
  ['Nedávné objednávky',               'Recent orders',                        'Pedidos recientes'],
  ['Předchozí objednávka',             'Previous order',                       'Pedido anterior'],

  // ───────── B2B BATCH 2 — DOKUMENTY ─────────
  ['Vyžádat fakturu',                  'Request invoice',                      'Solicitar factura'],
  ['Vyžádat dodací list',              'Request delivery note',                'Solicitar nota de entrega'],
  ['Stáhnout fakturu',                 'Download invoice',                     'Descargar factura'],
  ['Stáhnout dodací list',             'Download delivery note',               'Descargar nota de entrega'],
  ['Stáhnout potvrzení',               'Download confirmation',                'Descargar confirmación'],
  ['Stáhnout vše jako ZIP',            'Download all as ZIP',                  'Descargar todo en ZIP'],
  ['Stáhnout PDF',                     'Download PDF',                         'Descargar PDF'],
  ['Tisknout',                         'Print',                                'Imprimir'],
  ['Náhled tisku',                     'Print preview',                        'Vista previa de impresión'],
  ['Sdílet fakturu',                   'Share invoice',                        'Compartir factura'],
  ['Sdílet objednávku',                'Share order',                          'Compartir pedido'],
  ['Poslat na e-mail',                 'Send to email',                        'Enviar al email'],
  ['Poslat účetní',                    'Send to accountant',                   'Enviar al contable'],

  // ───────── B2B BATCH 2 — REKLAMACE ─────────
  ['Reklamovat položku',               'File complaint',                       'Reclamar artículo'],
  ['Reklamovat objednávku',            'Complain order',                       'Reclamar pedido'],
  ['Důvod reklamace',                  'Complaint reason',                     'Motivo de reclamación'],
  ['Vadné zboží',                      'Defective goods',                      'Mercancía defectuosa'],
  ['Nesprávné množství',               'Wrong quantity',                       'Cantidad incorrecta'],
  ['Nesprávný druh',                   'Wrong type',                           'Tipo incorrecto'],
  ['Poškozený obal',                   'Damaged packaging',                    'Embalaje dañado'],
  ['Zkažené zboží',                    'Spoiled goods',                        'Mercancía estropeada'],
  ['Pozdní dodání',                    'Late delivery',                        'Entrega tardía'],
  ['Žádné doručení',                   'No delivery',                          'Sin entrega'],
  ['Popis problému',                   'Problem description',                  'Descripción del problema'],
  ['Foto k reklamaci',                 'Photo of complaint',                   'Foto de reclamación'],
  ['Přidat foto',                      'Add photo',                            'Añadir foto'],
  ['Odeslat reklamaci',                'Submit complaint',                     'Enviar reclamación'],
  ['Reklamace odeslána',               'Complaint submitted',                  'Reclamación enviada'],
  ['Číslo reklamace',                  'Complaint number',                     'Número de reclamación'],
  ['Mé reklamace',                     'My complaints',                        'Mis reclamaciones'],
  ['Žádné reklamace',                  'No complaints',                        'Sin reclamaciones'],

  // ───────── B2B BATCH 2 — PROFIL ─────────
  ['Můj profil',                       'My profile',                           'Mi perfil'],
  ['Upravit profil',                   'Edit profile',                         'Editar perfil'],
  ['Avatar / Foto',                    'Avatar / Photo',                       'Avatar / Foto'],
  ['Nahrát foto',                      'Upload photo',                         'Subir foto'],
  ['Změnit foto',                      'Change photo',                         'Cambiar foto'],
  ['Odstranit foto',                   'Remove photo',                         'Quitar foto'],
  ['Můj e-mail',                       'My email',                             'Mi email'],
  ['Můj telefon',                      'My phone',                             'Mi teléfono'],
  ['Změnit e-mail',                    'Change email',                         'Cambiar email'],
  ['Změnit telefon',                   'Change phone',                         'Cambiar teléfono'],
  ['Ověření e-mailu',                  'Email verification',                   'Verificación de email'],
  ['Ověření telefonu',                 'Phone verification',                   'Verificación de teléfono'],
  ['E-mail ověřen',                    'Email verified',                       'Email verificado'],
  ['E-mail neověřen',                  'Email not verified',                   'Email no verificado'],
  ['Telefon ověřen',                   'Phone verified',                       'Teléfono verificado'],
  ['Telefon neověřen',                 'Phone not verified',                   'Teléfono no verificado'],
  ['Údaje firmy',                      'Company details',                      'Datos de la empresa'],
  ['Název firmy',                      'Company name',                         'Nombre de la empresa'],
  ['IČO firmy',                        'Company ID',                           'ID de la empresa'],
  ['DIČ firmy',                        'VAT ID',                               'NIF'],
  ['Plátce DPH',                       'VAT registered',                       'Registrado en IVA'],
  ['Neplátce DPH',                     'Not VAT registered',                   'No registrado en IVA'],
  ['Kontaktní osoba',                  'Contact person',                       'Persona de contacto'],
  ['Hlavní kontakt',                   'Main contact',                         'Contacto principal'],
  ['Sekundární kontakt',               'Secondary contact',                    'Contacto secundario'],

  // ───────── B2B BATCH 2 — UPOZORNĚNÍ ─────────
  ['Nastavení upozornění',             'Notification settings',                'Configuración de notificaciones'],
  ['E-mailová upozornění',             'Email notifications',                  'Notificaciones por email'],
  ['SMS upozornění',                   'SMS notifications',                    'Notificaciones por SMS'],
  ['Push upozornění',                  'Push notifications',                   'Notificaciones push'],
  ['Upozornit na novou objednávku',    'Notify on new order',                  'Avisar sobre nuevo pedido'],
  ['Upozornit na doručení',            'Notify on delivery',                   'Avisar sobre entrega'],
  ['Upozornit na fakturu',             'Notify on invoice',                    'Avisar sobre factura'],
  ['Upozornit na změnu ceny',          'Notify on price change',               'Avisar sobre cambio de precio'],
  ['Upozornit na akce a slevy',        'Notify on promotions',                 'Avisar sobre promociones'],
  ['Upozornit na novinky',             'Notify on news',                       'Avisar sobre novedades'],
  ['Upozornit na sezónní výrobky',     'Notify on seasonal',                   'Avisar sobre temporada'],
  ['Newsletter',                       'Newsletter',                           'Boletín'],
  ['Odebírat newsletter',              'Subscribe to newsletter',              'Suscribirse al boletín'],
  ['Odhlásit z newsletteru',           'Unsubscribe from newsletter',          'Cancelar suscripción'],
  ['Frekvence newsletteru',            'Newsletter frequency',                 'Frecuencia del boletín'],
  ['Denně',                            'Daily',                                'Diariamente'],
  ['Týdně',                            'Weekly',                               'Semanalmente'],
  ['Měsíčně',                          'Monthly',                              'Mensualmente'],
  ['Nikdy',                            'Never',                                'Nunca'],

  // ───────── B2B BATCH 2 — HESLA + 2FA ─────────
  ['Změnit heslo',                     'Change password',                      'Cambiar contraseña'],
  ['Současné heslo',                   'Current password',                     'Contraseña actual'],
  ['Zapomněli jste současné heslo?',   'Forgot current password?',             '¿Olvidaste la contraseña actual?'],
  ['Heslo musí mít alespoň 8 znaků',   'Password must be at least 8 chars',    'Mínimo 8 caracteres'],
  ['Heslo musí obsahovat číslo',       'Password must contain number',         'Debe contener número'],
  ['Heslo musí obsahovat velké písmeno','Password must contain uppercase',     'Debe contener mayúscula'],
  ['Heslo musí obsahovat speciální znak','Password must contain special char', 'Debe contener carácter especial'],
  ['Síla hesla',                       'Password strength',                    'Fortaleza de contraseña'],
  ['Slabé',                            'Weak',                                 'Débil'],
  ['Střední',                          'Medium',                               'Mediana'],
  ['Silné',                            'Strong',                               'Fuerte'],
  ['Velmi silné',                      'Very strong',                          'Muy fuerte'],
  ['Heslo úspěšně změněno',            'Password changed successfully',        'Contraseña cambiada'],
  ['Dvoufaktorové ověření',            'Two-factor authentication',            'Autenticación de dos factores'],
  ['Zapnout 2FA',                      'Enable 2FA',                           'Activar 2FA'],
  ['Vypnout 2FA',                      'Disable 2FA',                          'Desactivar 2FA'],
  ['Naskenovat QR kód',                'Scan QR code',                         'Escanear código QR'],
  ['Zadejte 6-místný kód',             'Enter 6-digit code',                   'Introduce código de 6 dígitos'],
  ['Záložní kódy',                     'Backup codes',                         'Códigos de respaldo'],
  ['Stáhnout záložní kódy',            'Download backup codes',                'Descargar códigos de respaldo'],

  // ───────── B2B BATCH 2 — POBOČKY ─────────
  ['Moje pobočky',                     'My branches',                          'Mis sucursales'],
  ['Přidat pobočku',                   'Add branch',                           'Añadir sucursal'],
  ['Hlavní sídlo',                     'Headquarters',                         'Sede'],
  ['Pobočka',                          'Branch',                               'Sucursal'],
  ['Aktivní pobočky',                  'Active branches',                      'Sucursales activas'],
  ['Vybrat pobočku',                   'Select branch',                        'Seleccionar sucursal'],
  ['Pro tuto pobočku',                 'For this branch',                      'Para esta sucursal'],
  ['Pro všechny pobočky',              'For all branches',                     'Para todas las sucursales'],
  ['Skladová zásoba pro pobočku',      'Stock for branch',                     'Stock para sucursal'],
  ['Objednávky pro pobočku',           'Orders for branch',                    'Pedidos para sucursal'],

  // ───────── B2B BATCH 2 — POZNÁMKY ─────────
  ['Mé poznámky',                      'My notes',                             'Mis notas'],
  ['Přidat poznámku',                  'Add note',                             'Añadir nota'],
  ['Vlastní poznámka',                 'Custom note',                          'Nota personalizada'],
  ['Poznámka k objednávce',            'Order note',                           'Nota del pedido'],
  ['Poznámka pro řidiče',              'Note for driver',                      'Nota para conductor'],
  ['Speciální požadavky',              'Special requirements',                 'Requisitos especiales'],
  ['Speciální instrukce',              'Special instructions',                 'Instrucciones especiales'],
  ['Instrukce pro doručení',           'Delivery instructions',                'Instrucciones de entrega'],
  ['Bezbariérový vstup',               'Wheelchair access',                    'Acceso para silla de ruedas'],
  ['Vchod ze dvora',                   'Yard entrance',                        'Entrada por patio'],
  ['Zazvonit',                         'Ring the bell',                        'Tocar el timbre'],
  ['Nezvonit',                         'Do not ring',                          'No tocar el timbre'],

  // ───────── B2B BATCH 3 — NOTIFIKACE ─────────
  ['Žádné notifikace',                 'No notifications',                     'Sin notificaciones'],
  ['Nová notifikace',                  'New notification',                     'Nueva notificación'],
  ['Označit přečtené',                 'Mark read',                            'Marcar como leído'],
  ['Označit všechny přečtené',         'Mark all read',                        'Marcar todo como leído'],
  ['Vymazat všechny',                  'Clear all',                            'Borrar todo'],
  ['Vaše objednávka byla přijata',     'Your order has been received',         'Tu pedido ha sido recibido'],
  ['Vaše objednávka byla potvrzena',   'Your order has been confirmed',        'Tu pedido ha sido confirmado'],
  ['Vaše objednávka je vyrobená',      'Your order is manufactured',           'Tu pedido está fabricado'],
  ['Vaše objednávka byla expedována',  'Your order has been dispatched',       'Tu pedido ha sido enviado'],
  ['Vaše objednávka byla doručena',    'Your order has been delivered',        'Tu pedido ha sido entregado'],
  ['Faktura byla vystavena',           'Invoice has been issued',              'Factura emitida'],
  ['Faktura je po splatnosti',         'Invoice is overdue',                   'Factura vencida'],
  ['Nová akce v katalogu',             'New promotion in catalog',             'Nueva promoción'],
  ['Nový sezónní výrobek',             'New seasonal product',                 'Nuevo producto de temporada'],
  ['Vyprší vám předplatné',            'Subscription expiring',                'Suscripción a punto de caducar'],
  ['Změna v rozvrhu doručení',         'Delivery schedule change',             'Cambio en horario de entrega'],

  // ───────── B2B BATCH 3 — DETAIL HISTORIE ─────────
  ['Objednávka č.',                    'Order no.',                            'Pedido nº'],
  ['Faktura č.',                       'Invoice no.',                          'Factura nº'],
  ['Dodací list č.',                   'Delivery note no.',                    'Nota de entrega nº'],
  ['Datum vytvoření',                  'Created on',                           'Fecha de creación'],
  ['Datum potvrzení',                  'Confirmed on',                         'Confirmado el'],
  ['Datum expedice',                   'Dispatched on',                        'Enviado el'],
  ['Datum doručení',                   'Delivered on',                         'Entregado el'],
  ['Datum vyfakturování',              'Invoiced on',                          'Facturado el'],
  ['Datum úhrady',                     'Paid on',                              'Pagado el'],
  ['Datum splatnosti',                 'Due on',                               'Vencimiento'],
  ['Položek',                          'Items',                                'Artículos'],
  ['Hmotnost celkem',                  'Total weight',                         'Peso total'],
  ['Objem celkem',                     'Total volume',                         'Volumen total'],
  ['Stav platby',                      'Payment status',                       'Estado de pago'],
  ['Pouze náhled',                     'Preview only',                         'Solo vista previa'],

  // ───────── B2B BATCH 3 — SDÍLENÍ ─────────
  ['Sdílet odkaz',                     'Share link',                           'Compartir enlace'],
  ['Kopírovat odkaz',                  'Copy link',                            'Copiar enlace'],
  ['Odkaz zkopírován',                 'Link copied',                          'Enlace copiado'],
  ['Sdílet přes e-mail',               'Share via email',                      'Compartir por email'],
  ['Sdílet přes WhatsApp',             'Share via WhatsApp',                   'Compartir por WhatsApp'],
  ['Sdílet přes SMS',                  'Share via SMS',                        'Compartir por SMS'],
  ['Sdílet QR kódem',                  'Share via QR code',                    'Compartir con QR'],
  ['Sdílet katalog',                   'Share catalog',                        'Compartir catálogo'],
  ['Sdílet objednávku s kolegou',      'Share order with colleague',           'Compartir pedido con colega'],
  ['Pozvat do firmy',                  'Invite to company',                    'Invitar a la empresa'],
  ['Pozvánka odeslána',                'Invitation sent',                      'Invitación enviada'],

  // ───────── B2B BATCH 3 — POMOC ─────────
  ['Nápověda',                         'Help',                                 'Ayuda'],
  ['Centrum nápovědy',                 'Help center',                          'Centro de ayuda'],
  ['Časté otázky',                     'FAQ',                                  'Preguntas frecuentes'],
  ['Jak objednat',                     'How to order',                         'Cómo pedir'],
  ['Jak platit',                       'How to pay',                           'Cómo pagar'],
  ['Jak vrátit zboží',                 'How to return goods',                  'Cómo devolver'],
  ['Jak reklamovat',                   'How to complain',                      'Cómo reclamar'],
  ['Jak změnit adresu',                'How to change address',                'Cómo cambiar dirección'],
  ['Jak změnit heslo',                 'How to change password',               'Cómo cambiar contraseña'],
  ['Jak změnit e-mail',                'How to change email',                  'Cómo cambiar email'],
  ['Jak zrušit účet',                  'How to delete account',                'Cómo eliminar cuenta'],
  ['Kontaktovat podporu',              'Contact support',                      'Contactar soporte'],
  ['Zákaznická linka',                 'Customer line',                        'Línea al cliente'],
  ['Otevírací hodiny podpory',         'Support hours',                        'Horario de soporte'],
  ['Po-Pá 8:00-17:00',                 'Mon-Fri 8 AM-5 PM',                    'Lun-Vie 8:00-17:00'],
  ['Mimo provoz',                      'Out of service',                       'Fuera de servicio'],
  ['Vrátíme se nazítří',               'We will return tomorrow',              'Volveremos mañana'],

  // ───────── B2B BATCH 3 — PRODUKTOVÁ STRÁNKA ─────────
  ['Souhrn',                           'Summary',                              'Resumen'],
  ['Detail',                           'Detail',                               'Detalle'],
  ['Specifikace',                      'Specifications',                       'Especificaciones'],
  ['Recenze',                          'Reviews',                              'Reseñas'],
  ['Otázky a odpovědi',                'Q&A',                                  'Preguntas y respuestas'],
  ['Podobné výrobky',                  'Similar products',                     'Productos similares'],
  ['Lidé také kupují',                 'People also buy',                      'La gente también compra'],
  ['Často kupované spolu',             'Frequently bought together',           'Frecuentemente comprados juntos'],
  ['Položit otázku',                   'Ask a question',                       'Hacer una pregunta'],
  ['Napsat recenzi',                   'Write a review',                       'Escribir reseña'],
  ['Více recenzí',                     'More reviews',                         'Más reseñas'],
  ['Filtrovat recenze',                'Filter reviews',                       'Filtrar reseñas'],
  ['Doporučuji všem',                  'Recommend to everyone',                'Lo recomiendo a todos'],
  ['Spokojený zákazník',               'Satisfied customer',                   'Cliente satisfecho'],
  ['Naprostá spokojenost',             'Completely satisfied',                 'Totalmente satisfecho'],

  // ───────── B2B BATCH 3 — PLATEBNÍ METODY ─────────
  ['Vyberte způsob platby',            'Select payment method',                'Selecciona método de pago'],
  ['Bankovním převodem',               'Bank transfer',                        'Transferencia bancaria'],
  ['Platba kartou',                    'Card payment',                         'Pago con tarjeta'],
  ['Platba kartou online',             'Online card payment',                  'Pago con tarjeta en línea'],
  ['Platba na dobírku',                'Cash on delivery',                     'Contra reembolso'],
  ['Platba hotově při převzetí',       'Cash on pickup',                       'Efectivo al recoger'],
  ['Bezhotovostně',                    'Cashless',                             'Sin efectivo'],
  ['Hotovostně',                       'In cash',                              'En efectivo'],
  ['Faktura se splatností',            'Invoice with payment terms',           'Factura con plazo'],
  ['Splatnost 7 dní',                  '7 days due',                           '7 días para pagar'],
  ['Splatnost 14 dní',                 '14 days due',                          '14 días para pagar'],
  ['Splatnost 30 dní',                 '30 days due',                          '30 días para pagar'],
  ['Splatnost 60 dní',                 '60 days due',                          '60 días para pagar'],
  ['Splatnost 90 dní',                 '90 days due',                          '90 días para pagar'],

  // ───────── B2B BATCH 3 — POSLEDNÍ COMMON ─────────
  ['Pondělí',                          'Monday',                               'Lunes'],
  ['Úterý',                            'Tuesday',                              'Martes'],
  ['Středa',                           'Wednesday',                            'Miércoles'],
  ['Čtvrtek',                          'Thursday',                             'Jueves'],
  ['Pátek',                            'Friday',                               'Viernes'],
  ['Sobota',                           'Saturday',                             'Sábado'],
  ['Neděle',                           'Sunday',                               'Domingo'],
  ['Po',                               'Mon',                                  'Lun'],
  ['Út',                               'Tue',                                  'Mar'],
  ['St',                               'Wed',                                  'Mié'],
  ['Čt',                               'Thu',                                  'Jue'],
  ['Pá',                               'Fri',                                  'Vie'],
  ['So',                               'Sat',                                  'Sáb'],
  ['Ne',                               'Sun',                                  'Dom'],
  ['leden',                            'January',                              'enero'],
  ['únor',                             'February',                             'febrero'],
  ['březen',                           'March',                                'marzo'],
  ['duben',                            'April',                                'abril'],
  ['květen',                           'May',                                  'mayo'],
  ['červen',                           'June',                                 'junio'],
  ['červenec',                         'July',                                 'julio'],
  ['srpen',                            'August',                               'agosto'],
  ['září',                             'September',                            'septiembre'],
  ['říjen',                            'October',                              'octubre'],
  ['listopad',                         'November',                             'noviembre'],
  ['prosinec',                         'December',                             'diciembre'],

  // ───────── B2B BATCH 3 — RYCHLÉ AKCE ─────────
  ['Rychlý nákup',                     'Quick buy',                            'Compra rápida'],
  ['Objednat znovu',                   'Order again',                          'Pedir de nuevo'],
  ['Opakovat objednávku',              'Repeat order',                         'Repetir pedido'],
  ['Z minulého týdne',                 'From last week',                       'De la semana pasada'],
  ['Z minulého měsíce',                'From last month',                      'Del mes pasado'],
  ['Nákupní seznam',                   'Shopping list',                        'Lista de compras'],
  ['Mé nákupní seznamy',               'My shopping lists',                    'Mis listas de compras'],
  ['Nový seznam',                      'New list',                             'Nueva lista'],
  ['Sdílet seznam',                    'Share list',                           'Compartir lista'],
  ['Tisknout seznam',                  'Print list',                           'Imprimir lista'],
  ['Smazat seznam',                    'Delete list',                          'Eliminar lista'],
  ['Šablona objednávky',               'Order template',                       'Plantilla de pedido'],
  ['Mé šablony',                       'My templates',                         'Mis plantillas'],
  ['Použít šablonu',                   'Use template',                         'Usar plantilla'],
  ['Uložit jako šablonu',              'Save as template',                     'Guardar como plantilla'],

  // ───────── B2B BATCH 3 — STAVOVÉ ZPRÁVY ─────────
  ['Načítám…',                         'Loading…',                             'Cargando…'],
  ['Ukládám…',                         'Saving…',                              'Guardando…'],
  ['Mažu…',                            'Deleting…',                            'Eliminando…'],
  ['Odesílám…',                        'Sending…',                             'Enviando…'],
  ['Tisknu…',                          'Printing…',                            'Imprimiendo…'],
  ['Stahování…',                       'Downloading…',                         'Descargando…'],
  ['Nahrávám…',                        'Uploading…',                           'Subiendo…'],
  ['Hotovo',                           'Done',                                 'Listo'],
  ['Uloženo',                          'Saved',                                'Guardado'],
  ['Smazáno',                          'Deleted',                              'Eliminado'],
  ['Odesláno',                         'Sent',                                 'Enviado'],
  ['Staženo',                          'Downloaded',                           'Descargado'],
  ['Nahráno',                          'Uploaded',                             'Subido'],
  ['Zkopírováno',                      'Copied',                               'Copiado'],
  ['Vyprázdněno',                      'Emptied',                              'Vaciado'],
  ['Objednávka odeslána',              'Order submitted',                      'Pedido enviado'],
  ['Děkujeme za objednávku',           'Thanks for your order',                'Gracias por tu pedido'],
  ['Brzy vás budeme kontaktovat',      'We will contact you soon',             'Te contactaremos pronto'],
  ['Potvrzení bylo zasláno',           'Confirmation has been sent',           'Confirmación enviada'],

  // ───────── B2B BATCH 3 — CHYBOVÉ HLÁŠKY ─────────
  ['Něco se pokazilo',                 'Something went wrong',                 'Algo salió mal'],
  ['Zkuste to znovu',                  'Try again',                            'Inténtalo de nuevo'],
  ['Načtěte stránku znovu',            'Reload the page',                      'Recarga la página'],
  ['Bez připojení',                    'No connection',                        'Sin conexión'],
  ['Bez internetu',                    'No internet',                          'Sin internet'],
  ['Připojení obnoveno',               'Connection restored',                  'Conexión restaurada'],
  ['Server nedostupný',                'Server unavailable',                   'Servidor no disponible'],
  ['Údržba serveru',                   'Server maintenance',                   'Mantenimiento del servidor'],
  ['Stránka nenalezena',               'Page not found',                       'Página no encontrada'],
  ['404 — nenalezeno',                 '404 — not found',                      '404 — no encontrado'],
  ['Vrátit na hlavní stranu',          'Return home',                          'Volver al inicio'],

  // ───────── B2B BATCH 3 — POPISKY KATEGORIÍ ─────────
  ['Pečivo',                           'Bakery',                               'Panadería'],
  ['Chleby',                           'Breads',                               'Panes'],
  ['Bagety',                           'Baguettes',                            'Baguettes'],
  ['Rohlíky',                          'Rolls',                                'Bollos'],
  ['Koblihy',                          'Donuts',                               'Donas'],
  ['Buchty',                           'Buns',                                 'Bollos'],
  ['Koláče',                           'Pies',                                 'Tartas'],
  ['Dorty',                            'Cakes',                                'Pasteles'],
  ['Zákusky',                          'Pastries',                             'Pasteles pequeños'],
  ['Cukrovinky',                       'Sweets',                               'Dulces'],
  ['Sušenky',                          'Cookies',                              'Galletas'],
  ['Perníčky',                         'Gingerbread',                          'Pan de jengibre'],
  ['Slané pečivo',                     'Savory bakery',                        'Panadería salada'],
  ['Sladké pečivo',                    'Sweet bakery',                         'Panadería dulce'],
  ['Speciality',                       'Specialties',                          'Especialidades'],
  ['Sezónní pečivo',                   'Seasonal bakery',                      'Panadería de temporada'],
  ['Velikonoční',                      'Easter',                               'Pascua'],
  ['Vánoční',                          'Christmas',                            'Navideño'],
  ['Den matek',                        'Mother\'s Day',                        'Día de la Madre'],
  ['Valentýnské',                      'Valentine\'s',                         'San Valentín'],
  ['Halloweenské',                     'Halloween',                            'Halloween'],

  // ───────── B2B MODÁLNÍ OKNA — KOMPLETNÍ POKRYTÍ ─────────
  // Modal titles
  ['Detail objednávky',                'Order detail',                         'Detalle del pedido'],
  ['Detail výrobku',                   'Product detail',                       'Detalle del producto'],
  ['Detail košíku',                    'Cart detail',                          'Detalle del carrito'],
  ['Detail platby',                    'Payment detail',                       'Detalle del pago'],
  ['Detail dopravy',                   'Shipping detail',                      'Detalle del envío'],
  ['Detail faktury',                   'Invoice detail',                       'Detalle de factura'],
  ['Detail reklamace',                 'Complaint detail',                     'Detalle de reclamación'],
  ['Detail rezervace',                 'Reservation detail',                   'Detalle de reserva'],
  ['Souhrn vaší objednávky',           'Your order summary',                   'Resumen de tu pedido'],
  ['Souhrn nákupu',                    'Purchase summary',                     'Resumen de compra'],
  ['Souhrn košíku',                    'Cart summary',                         'Resumen del carrito'],

  // Modal sekce
  ['Vyberte způsob dodání',            'Select delivery method',               'Selecciona método de entrega'],
  ['Vyberte způsob platby',            'Select payment method',                'Selecciona método de pago'],
  ['Vyberte adresu doručení',          'Select delivery address',              'Selecciona dirección de entrega'],
  ['Vyberte fakturační adresu',        'Select billing address',               'Selecciona dirección de facturación'],
  ['Vyberte datum dodání',             'Select delivery date',                 'Selecciona fecha de entrega'],
  ['Vyberte časové okno',              'Select time window',                   'Selecciona ventana horaria'],

  // Modal akce
  ['Pokračovat k pokladně',            'Continue to checkout',                 'Continuar al pago'],
  ['Dokončit nákup',                   'Complete purchase',                    'Completar compra'],
  ['Odeslat objednávku',               'Submit order',                         'Enviar pedido'],
  ['Potvrdit objednávku',              'Confirm order',                        'Confirmar pedido'],
  ['Zrušit objednávku',                'Cancel order',                         'Cancelar pedido'],
  ['Upravit objednávku',               'Edit order',                           'Editar pedido'],
  ['Smazat objednávku',                'Delete order',                         'Eliminar pedido'],

  // Modal formulářové prvky
  ['Jméno a příjmení',                 'Full name',                            'Nombre completo'],
  ['Křestní jméno',                    'First name',                           'Nombre'],
  ['Příjmení',                         'Last name',                            'Apellido'],
  ['E-mailová adresa',                 'Email address',                        'Dirección de email'],
  ['Telefonní číslo',                  'Phone number',                         'Número de teléfono'],
  ['Mobilní číslo',                    'Mobile number',                        'Número de móvil'],
  ['Pevná linka',                      'Landline',                             'Línea fija'],
  ['Pevné číslo',                      'Landline number',                      'Línea fija'],
  ['Ulice a číslo',                    'Street and number',                    'Calle y número'],
  ['Doplněk adresy',                   'Address line 2',                       'Dirección 2'],
  ['Patro / byt',                      'Floor / apt',                          'Piso / depto'],
  ['Země',                             'Country',                              'País'],
  ['Region / kraj',                    'Region / state',                       'Región / estado'],

  // Modal validace
  ['Toto pole musí být vyplněno',      'This field must be filled',            'Este campo debe rellenarse'],
  ['Vyplňte prosím všechna pole',      'Please fill all fields',               'Por favor rellena todos los campos'],
  ['Vyplňte alespoň jedno pole',       'Fill at least one field',              'Rellena al menos un campo'],
  ['Neplatná e-mailová adresa',        'Invalid email address',                'Dirección de email no válida'],
  ['Neplatné telefonní číslo',         'Invalid phone number',                 'Número de teléfono no válido'],
  ['Neplatné PSČ',                     'Invalid ZIP code',                     'Código postal no válido'],
  ['Neplatné IČO',                     'Invalid company ID',                   'ID empresarial no válido'],
  ['Neplatné DIČ',                     'Invalid VAT ID',                       'NIF no válido'],

  // Modal confirm dialogs
  ['Opravdu odebrat z košíku?',        'Really remove from cart?',             '¿Realmente quitar del carrito?'],
  ['Opravdu vyprázdnit košík?',        'Really empty cart?',                   '¿Realmente vaciar el carrito?'],
  ['Opravdu zrušit objednávku?',       'Really cancel order?',                 '¿Realmente cancelar el pedido?'],
  ['Opravdu odhlásit se?',             'Really sign out?',                     '¿Realmente cerrar sesión?'],
  ['Opravdu smazat účet?',             'Really delete account?',               '¿Realmente eliminar la cuenta?'],
  ['Tato akce je nevratná',            'This action is irreversible',          'Esta acción es irreversible'],

  // Modal tlačítka
  ['Uložit',                           'Save',                                 'Guardar'],
  ['Uložit a zavřít',                  'Save and close',                       'Guardar y cerrar'],
  ['Zrušit',                           'Cancel',                               'Cancelar'],
  ['Zavřít',                           'Close',                                'Cerrar'],
  ['Smazat',                           'Delete',                               'Eliminar'],
  ['Upravit',                          'Edit',                                 'Editar'],
  ['Potvrdit',                         'Confirm',                              'Confirmar'],
  ['Odmítnout',                        'Decline',                              'Rechazar'],
  ['Pokračovat',                       'Continue',                             'Continuar'],
  ['Zpět',                             'Back',                                 'Atrás'],
  ['Dále',                             'Next',                                 'Siguiente'],
  ['Hotovo',                           'Done',                                 'Listo'],
  ['Aplikovat',                        'Apply',                                'Aplicar'],
  ['Resetovat',                        'Reset',                                'Restablecer'],
  ['Vymazat',                          'Clear',                                'Borrar'],

  // Modal feedback
  ['Děkujeme za nákup',                'Thanks for your purchase',             'Gracias por tu compra'],
  ['Děkujeme za objednávku',           'Thanks for your order',                'Gracias por tu pedido'],
  ['Děkujeme za zpětnou vazbu',        'Thanks for your feedback',             'Gracias por tu opinión'],
  ['Vaše objednávka byla přijata',     'Your order has been received',         'Tu pedido ha sido recibido'],
  ['Vaše objednávka byla odeslána',    'Your order has been sent',             'Tu pedido ha sido enviado'],
  ['Potvrzení zasláno na e-mail',      'Confirmation sent to email',           'Confirmación enviada por email'],
  ['Brzy se vám ozveme',               'We will contact you soon',             'Te contactaremos pronto'],
  ['Číslo vaší objednávky',            'Your order number',                    'Tu número de pedido'],
  // b2b EN/ES doplneni 2026-05-22 (+140)
  ['+10 ks', '+10 pcs', '+10 uds'],
  ['APPEK B2B portál', 'APPEK B2B portal', 'Portal APPEK B2B'],
  ['APPEK B2B – B2B objednávky', 'APPEK B2B – B2B orders', 'APPEK B2B – Pedidos B2B'],
  ['B2B objednávkový systém', 'B2B ordering system', 'Sistema de pedidos B2B'],
  ['B2B objednávky', 'B2B orders', 'Pedidos B2B'],
  ['Bankovní převod', 'Bank transfer', 'Transferencia bancaria'],
  ['Bez DPH', 'Excl. VAT', 'Sin IVA'],
  ['Bez DPH celkem', 'Total excl. VAT', 'Total sin IVA'],
  ['byla odeslána. Děkujeme!', 'has been submitted. Thank you!', 'ha sido enviado. ¡Gracias!'],
  ['Běžná cena', 'Regular price', 'Precio habitual'],
  ['Běžně', 'Usually', 'Habitualmente'],
  ['Celková hmotnost položky', 'Total item weight', 'Peso total del artículo'],
  ['Celková sleva', 'Total discount', 'Descuento total'],
  ['Cena/ks', 'Price/pc', 'Precio/ud'],
  ['defaultně zítra', 'defaults to tomorrow', 'por defecto mañana'],
  ['Demo režim', 'Demo mode', 'Modo demo'],
  ['Demo session vyprší za 1 min', 'Demo session expires in 1 min', 'La sesión demo expira en 1 min'],
  ['DL zatím neexistuje', 'Delivery note does not exist yet', 'El albarán aún no existe'],
  ['Dobírka', 'Cash on delivery', 'Contra reembolso'],
  ['Dodací list', 'Delivery note', 'Albarán'],
  ['Dodací list (PDF)', 'Delivery note (PDF)', 'Albarán (PDF)'],
  ['Dodací list zatím nebyl vytvořen', 'The delivery note has not been created yet', 'El albarán aún no ha sido creado'],
  ['Dodací údaje', 'Delivery details', 'Datos de entrega'],
  ['Doprodej', 'Clearance', 'Liquidación'],
  ['Dostávejte zprávy o stavu vašich objednávek přímo na telefon — zdarma, bez SMS.', 'Receive updates about your order status directly on your phone — free, without SMS.', 'Reciba notificaciones sobre el estado de sus pedidos directamente en su teléfono — gratis, sin SMS.'],
  ['DPH celkem', 'Total VAT', 'IVA total'],
  ['FA', 'INV', 'FAC'],
  ['FA zatím neexistuje', 'Invoice does not exist yet', 'La factura aún no existe'],
  ['Faktura', 'Invoice', 'Factura'],
  ['Faktura (PDF)', 'Invoice (PDF)', 'Factura (PDF)'],
  ['Faktura zatím nebyla vystavena', 'The invoice has not been issued yet', 'La factura aún no ha sido emitida'],
  ['GoPay (karta + bank)', 'GoPay (card + bank)', 'GoPay (tarjeta + banco)'],
  ['Hledat výrobek (název, kategorie, kód)…', 'Search product (name, category, code)…', 'Buscar producto (nombre, categoría, código)…'],
  ['Hotově při převzetí', 'Cash on pickup', 'Efectivo al recoger'],
  ['jak často se má opakovat', 'how often it should repeat', 'con qué frecuencia se repetirá'],
  ['jakýkoli den v budoucnu', 'any day in the future', 'cualquier día futuro'],
  ['jeden den', 'one day', 'un día'],
  ['Jednorázová', 'One-time', 'Única'],
  ['již nedostupný v katalogu (přeskočeno).', 'no longer available in the catalog (skipped).', 'ya no disponible en el catálogo (omitido).'],
  ['Karta online (Stripe)', 'Card online (Stripe)', 'Tarjeta online (Stripe)'],
  ['Katalog výrobků', 'Product catalog', 'Catálogo de productos'],
  ['katalogu', 'catalog', 'catálogo'],
  ['každý den', 'every day', 'cada día'],
  ['Každý pracovní den v určeném období.', 'Every working day in the specified period.', 'Cada día laborable en el período especificado.'],
  ['každý týden', 'every week', 'cada semana'],
  ['Klikni na rychlou volbu nebo vyber datum z kalendáře.', 'Click a quick choice or select a date from the calendar.', 'Haga clic en una opción rápida o seleccione una fecha del calendario.'],
  ['Ks', 'Pcs', 'Uds'],
  ['ks)', 'pcs)', 'uds)'],
  ['Méně', 'Less', 'Menos'],
  ['Na konkrétní den v budoucnu.', 'For a specific day in the future.', 'Para un día específico en el futuro.'],
  ['na zítra', 'for tomorrow', 'para mañana'],
  ['Na zítra, jednou. Nejčastější volba.', 'For tomorrow, once. The most common choice.', 'Para mañana, una vez. La opción más común.'],
  ['Nahoru', 'Top', 'Arriba'],
  ['Nahoru na začátek katalogu', 'Back to top of the catalog', 'Volver al inicio del catálogo'],
  ['Naplánovaná', 'Scheduled', 'Programada'],
  ['Načíst do košíku', 'Load into cart', 'Cargar en el carrito'],
  ['Načíst položky této objednávky do košíku', 'Load items from this order into the cart', 'Cargar los artículos de este pedido en el carrito'],
  ['Načítám možnosti dopravy…', 'Loading shipping options…', 'Cargando opciones de envío…'],
  ['Načítám možnosti platby…', 'Loading payment options…', 'Cargando métodos de pago…'],
  ['Ne, díky', 'No, thanks', 'No, gracias'],
  ['nedostupných přeskočeno)', 'unavailable skipped)', 'no disponibles omitidos)'],
  ['Nejčastěji objednávané výrobky', 'Most frequently ordered products', 'Productos más pedidos'],
  ['Nemáte přístup? Kontaktujte dodavatele.', 'No access? Contact your supplier.', '¿No tiene acceso? Contacte con su proveedor.'],
  ['Novinka', 'New', 'Nuevo'],
  ['Objednávek', 'Orders', 'Pedidos'],
  ['Objednávka', 'Order', 'Pedido'],
  ['objednávka se opakuje', 'order repeats', 'el pedido se repite'],
  ['objednávka se vytvoří', 'order will be created', 'se creará el pedido'],
  ['Oblíbený', 'Favorite', 'Favorito'],
  ['Odebrat 5', 'Remove 5', 'Quitar 5'],
  ['Opakuje se každý týden ve stejné dny.', 'Repeats every week on the same days.', 'Se repite cada semana los mismos días.'],
  ['Platí do', 'Valid until', 'Válido hasta'],
  ['Platí od', 'Valid from', 'Válido desde'],
  ['pol.', 'items', 'art.'],
  ['pol. ·', 'items ·', 'art. ·'],
  ['pol.)', 'items)', 'art.)'],
  ['položek', 'items', 'artículos'],
  ['položek ·', 'items ·', 'artículos ·'],
  ['Položka', 'Item', 'Artículo'],
  ['Poznámka', 'Note', 'Nota'],
  ['Průměr', 'Average', 'Promedio'],
  ['Přehled za měsíc', 'Monthly overview', 'Resumen del mes'],
  ['Přepnout celou obrazovku', 'Toggle full screen', 'Cambiar a pantalla completa'],
  ['Přepnout dotykový (POS) režim', 'Toggle touch (POS) mode', 'Cambiar al modo táctil (POS)'],
  ['Přepnout tmavý režim', 'Toggle dark mode', 'Cambiar al modo oscuro'],
  ['Přidat', 'Add', 'Añadir'],
  ['Přidat 10', 'Add 10', 'Añadir 10'],
  ['Přidat výrobek —', 'Add product —', 'Añadir producto —'],
  ['Přidávejte výrobky vlevo.', 'Add products on the left.', 'Añada productos a la izquierda.'],
  ['Příští pondělí', 'Next Monday', 'El próximo lunes'],
  ['Rabat / ks', 'Discount / pc', 'Descuento / ud'],
  ['Rabat /ks', 'Discount /pc', 'Descuento /ud'],
  ['s DPH /ks', 'incl. VAT /pc', 'con IVA /ud'],
  ['Smazat položku z košíku', 'Remove item from cart', 'Eliminar artículo del carrito'],
  ['Smazat z košíku', 'Remove from cart', 'Eliminar del carrito'],
  ['Smazat šablonu', 'Delete template', 'Eliminar plantilla'],
  ['Souhrn objednávky →', 'Order summary →', 'Resumen del pedido →'],
  ['Součet hmotností všech položek (ks × hmotnost na kus + kg/g položky)', 'Sum of weights of all items (pcs × weight per piece + kg/g of item)', 'Suma de los pesos de todos los artículos (uds × peso por unidad + kg/g del artículo)'],
  ['Speciální požadavky...', 'Special requests...', 'Solicitudes especiales...'],
  ['Statistiky za tento měsíc', 'Statistics for this month', 'Estadísticas de este mes'],
  ['to', 'to', 'a'],
  ['to samé jako jednorázová, ale můžeš si vybrat', 'the same as one-time, but you can choose', 'lo mismo que puntual, pero puede elegir'],
  ['Typ objednávky', 'Order type', 'Tipo de pedido'],
  ['Týdenní plán', 'Weekly plan', 'Plan semanal'],
  ['Ulož aktuální košík jako šablonu pro příště', 'Save the current cart as a template for next time', 'Guarde el carrito actual como plantilla para la próxima vez'],
  ['Uložit změny', 'Save changes', 'Guardar cambios'],
  ['Vaše /ks', 'Your /pc', 'Su /ud'],
  ['Vaše poslední objednávky', 'Your recent orders', 'Sus pedidos recientes'],
  ['Vaše uložené košíky', 'Your saved carts', 'Sus carritos guardados'],
  ['Vlastní odvoz / pickup', 'Self-pickup', 'Recogida propia'],
  ['Volitelná poznámka', 'Optional note', 'Nota opcional'],
  ['volitelné', 'optional', 'opcional'],
  ['volitelné, jinak běží do odvolání', 'optional, otherwise runs until cancelled', 'opcional, si no se cancela continúa indefinidamente'],
  ['Vyber den dodání', 'Choose delivery day', 'Elija el día de entrega'],
  ['VYPRODÁNO', 'SOLD OUT', 'AGOTADO'],
  ['Vyprázdnit', 'Empty', 'Vaciar'],
  ['vytvoří se jedna objednávka pro', 'one order will be created for', 'se creará un pedido para'],
  ['Více', 'More', 'Más'],
  ['Výrobek', 'Product', 'Producto'],
  ['Vše', 'All', 'Todo'],
  ['Vše →', 'All →', 'Todo →'],
  ['Za 2 týdny', 'In 2 weeks', 'En 2 semanas'],
  ['Za týden', 'In a week', 'En una semana'],
  ['Zapnout upozornění?', 'Enable notifications?', '¿Activar notificaciones?'],
  ['Zatím žádné objednávky', 'No orders yet', 'Aún no hay pedidos'],
  ['Zkontroluj položky, nastav dodání a odešli', 'Check items, set delivery and submit', 'Revise los artículos, configure la entrega y envíe'],
  ['Znovu', 'Again', 'De nuevo'],
  ['Zopakovat poslední objednávku', 'Repeat last order', 'Repetir último pedido'],
  ['Zpět na detail', 'Back to detail', 'Volver al detalle'],
  ['Zásilkovna', 'Zásilkovna (parcel locker)', 'Zásilkovna (punto de recogida)'],
  ['Úprava objednávky', 'Edit order', 'Editar pedido'],
  ['Útrata', 'Spending', 'Gasto'],
  ['Šablony jsou uložené lokálně ve vašem prohlížeči — vidíte je jen v tomto zařízení', 'Templates are stored locally in your browser — you see them only on this device', 'Las plantillas se guardan localmente en su navegador — solo las verá en este dispositivo'],
  ['Žádná data', 'No data', 'Sin datos'],
  ['Žádné položky. Přidejte alespoň jednu.', 'No items. Add at least one.', 'Sin artículos. Añada al menos uno.'],
  ['Žádný způsob dopravy zatím není zapnutý. Kontaktuj dodavatele.', 'No shipping method is enabled yet. Contact the supplier.', 'Aún no hay ningún método de envío activado. Contacte con el proveedor.'],
  ['−5 ks', '−5 pcs', '−5 uds'],
  ['💰 Celkem k objednání', '💰 Total to order', '💰 Total a pedir'],
  ['💳 Způsob platby', '💳 Payment method', '💳 Método de pago'],
  ['🚚 Způsob doručení', '🚚 Delivery method', '🚚 Método de entrega'],
  // b2b history days/statuses 2026-05-22 (+9)
  ['Zítra', 'Tomorrow', 'Mañana'],
  ['Pozítří', 'The day after tomorrow', 'Pasado mañana'],
  ['Dnes', 'Today', 'Hoy'],
  ['Včera', 'Yesterday', 'Ayer'],
  ['Expedována', 'Shipped', 'Enviada'],
  ['Doručena', 'Delivered', 'Entregada'],
  ['Zrušena', 'Cancelled', 'Cancelada'],
  ['Upravena', 'Modified', 'Modificada'],
  ['Obnovena', 'Restored', 'Restaurada'],

  // — refaktor 2026-05-23: rozdělené runtime konkatenace v app.js / index.html
  ['je zastaralý.', 'is outdated.', 'está obsoleto.'],
  ['Pro pohodlné objednávání aktualizujte prohlížeč.', 'Update your browser for comfortable ordering.', 'Actualice su navegador para pedidos cómodos.'],
  ['Top', 'Top', 'Top'],
  ['Objednávku můžete upravovat', 'You can edit the order', 'Puede editar el pedido'],
  ['do', 'until', 'hasta'],
  // Typ objednávky — info boxy (renderTypHelp) + role label, dříve CS-only fragmenty
  ['Odběratel', 'Customer', 'Cliente'],
  ['. Provoz ti ji potvrdí a v ten den ti zboží přivezou. Defaultně', '. The operator will confirm it and deliver the goods to you that day. By default', '. El operador lo confirmará y te llevará la mercancía ese día. Por defecto'],
  ['(např. „pondělí za týden") přes rychlé chipy nebo kalendář.', '(e.g. "Monday next week") via the quick chips or the calendar.', '(p. ej. "lunes de la próxima semana") mediante los chips rápidos o el calendario.'],
  ['v zadaném období. Když potřebuješ identickou objednávku po dobu několika dní/týdnů (např. dovolenou v hotelu). Můžeš ji kdykoli upravit nebo zrušit v Historii.', 'in the specified period. When you need an identical order for several days/weeks (e.g. a hotel stay). You can edit or cancel it anytime in History.', 'en el periodo indicado. Cuando necesitas un pedido idéntico durante varios días/semanas (p. ej. una estancia de hotel). Puedes editarlo o cancelarlo en cualquier momento en el Historial.'],
  ['ve stejné dny v zvoleném období. Pro hotely / kavárny s pravidelným rytmem (např. „každý pátek a sobota").', 'on the same days in the chosen period. For hotels / cafés with a regular rhythm (e.g. "every Friday and Saturday").', 'los mismos días en el periodo elegido. Para hoteles / cafeterías con un ritmo regular (p. ej. "cada viernes y sábado").'],
];

const B2B_LOOKUP = new Map();
for (const [cs, en, es] of B2B_PHRASES) {
  B2B_LOOKUP.set(cs, { en, es });
}

window.tb = function(key) {
  const entry = B2B_LOOKUP.get(key);
  if (!entry) return key;
  return entry[window.b2bCurrentLang] || key;
};

const B2B_SKIP_TAGS = new Set(['CODE', 'PRE', 'KBD', 'SCRIPT', 'STYLE', 'INPUT', 'TEXTAREA']);

function b2bStripIconPrefix(s) {
  let i = 0;
  while (i < s.length) {
    const c = s.charCodeAt(i);
    if (c >= 0x2000 || c === 35 || c === 42 || c === 43 || c === 0xB7) { i++; continue; }
    break;
  }
  if (i === 0 || i >= s.length || !/\s/.test(s.charAt(i))) return null;
  let j = i;
  while (j < s.length && /\s/.test(s.charAt(j))) j++;
  return { prefix: s.slice(0, j), rest: s.slice(j) };
}

function b2bLookupFlexible(trimmed) {
  let v = B2B_LOOKUP.get(trimmed);
  if (v) return { prefix: '', suffix: '', entry: v };
  let core = trimmed, prefix = '', suffix = '';
  const ip = b2bStripIconPrefix(core);
  if (ip) { prefix += ip.prefix; core = ip.rest; }
  const nm = core.match(/^[0-9][0-9.,\s]*\s/);
  if (nm) { prefix += nm[0]; core = core.slice(nm[0].length); }
  if (core.length >= 3) {
    const last = core.charCodeAt(core.length - 1);
    if (last === 0x3A || last === 0xFF1A) { suffix = core.slice(-1); core = core.slice(0, -1); }
  }
  if ((prefix || suffix) && core.length >= 2 && core !== trimmed) {
    v = B2B_LOOKUP.get(core);
    if (v) return { prefix, suffix, entry: v };
  }
  return null;
}

function b2bTranslateNode(node) {
  let el = node.parentElement;
  while (el) {
    if (B2B_SKIP_TAGS.has(el.tagName)) return;
    if (el.dataset && el.dataset.noTranslate !== undefined) return;
    el = el.parentElement;
  }
  const raw = node.nodeValue;
  if (!raw || raw.length < 2 || raw.length > 500) return;
  const trimmed = raw.trim();
  if (!trimmed) return;
  const hit = b2bLookupFlexible(trimmed);
  if (!hit) return;
  const tgt = hit.entry[window.b2bCurrentLang || 'cs'];
  if (!tgt) return;
  const leading  = raw.match(/^\s*/)[0];
  const trailing = raw.match(/\s*$/)[0];
  node.nodeValue = leading + hit.prefix + tgt + hit.suffix + trailing;
}

function b2bTranslateAttr(el, attr) {
  const raw = el.getAttribute(attr);
  if (!raw) return;
  const trimmed = raw.trim();
  if (trimmed.length < 2) return;
  const hit = b2bLookupFlexible(trimmed);
  if (!hit) return;
  const tgt = hit.entry[window.b2bCurrentLang || 'cs'];
  if (tgt) el.setAttribute(attr, hit.prefix + tgt + hit.suffix);
}

window.applyB2bTranslations = function(root) {
  if (!window.b2bCurrentLang || window.b2bCurrentLang === 'cs') return;
  root = root || document.body;
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
  let n;
  const list = [];
  while ((n = walker.nextNode())) {
    if (n.nodeValue && n.nodeValue.trim()) list.push(n);
  }
  list.forEach(b2bTranslateNode);
  const scope = (root && root.querySelectorAll) ? root : document.body;
  scope.querySelectorAll('[placeholder],[title]').forEach(el => {
    b2bTranslateAttr(el, 'placeholder');
    b2bTranslateAttr(el, 'title');
  });
};

function initB2bI18nObserver() {
  const observer = new MutationObserver((mutations) => {
    if (window.b2bCurrentLang === 'cs') return;
    for (const m of mutations) {
      m.addedNodes.forEach(n => {
        if (n.nodeType === 1) window.applyB2bTranslations(n);
      });
    }
  });
  observer.observe(document.body, { childList: true, subtree: true });
}

function initLangPillsActive() {
  const code = window.b2bCurrentLang || 'cs';
  document.querySelectorAll('.b2b-lang-pill').forEach(b => {
    b.classList.toggle('is-active', b.dataset.lang === code);
  });
  const sel = document.getElementById('b2b-lang-switch');
  if (sel) sel.value = code;
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    initB2bI18nObserver();
    initLangPillsActive();
    window.applyB2bTranslations();
  });
} else {
  initB2bI18nObserver();
  initLangPillsActive();
  setTimeout(() => window.applyB2bTranslations(), 100);
}
