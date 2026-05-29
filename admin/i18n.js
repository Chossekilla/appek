// =============================================================
// 🌍 i18n — Czech / English / Spanish
//
// Použití:
//   t('save')              → "Uložit" / "Save" / "Guardar"
//   t('saved', { name })   → "Uloženo: Jan" / "Saved: Jan" / "Guardado: Jan"
//   document.querySelectorAll('[data-i18n]').forEach(el => el.textContent = t(el.dataset.i18n));
//   document.querySelectorAll('[data-i18n-placeholder]').forEach(el => el.placeholder = t(el.dataset.i18nPlaceholder));
//
// Aktuální jazyk: localStorage.lang ('cs'|'en'|'es'), default 'cs'.
// Fallback na 'cs' pokud klíč v jiném jazyce chybí.
// =============================================================

const I18N_LANG_KEY = 'appek_lang';
window.appekLangs = [
  { code: 'cs', label: 'Čeština',    flag: '🇨🇿' },
  { code: 'sk', label: 'Slovenčina', flag: '🇸🇰' },  // 🆕 v2.6.9 — SK overlay přes i18n_extra.js
  { code: 'en', label: 'English',    flag: '🇬🇧' },
  { code: 'de', label: 'Deutsch',    flag: '🇩🇪' },  // 🆕 v2.6.9 — DE overlay přes i18n_extra.js
  { code: 'es', label: 'Español',    flag: '🇪🇸' },
];

window.appekCurrentLang = (function() {
  try { return localStorage.getItem(I18N_LANG_KEY) || 'cs'; }
  catch (e) { return 'cs'; }
})();

// 🆕 v2.9.314 — Lazy i18n loader. Když user přepne na non-CS jazyk za běhu,
// dynamicky doloadujeme i18n_auto.js / i18n_extra.js (předtím se vždy loadovaly všechny
// = 4 MB pro CS users zbytečně). Helper vrací Promise — setAppekLang čeká před apply.
window._appekI18nLoaded = { auto: typeof I18N_LOOKUP !== 'undefined', extra: typeof I18N_EXTRA !== 'undefined' };
function loadScript(src) {
  return new Promise(function(resolve, reject) {
    var s = document.createElement('script');
    s.src = src;
    s.onload = resolve;
    s.onerror = reject;
    document.head.appendChild(s);
  });
}
window.loadI18nForLang = async function(code) {
  // CS = no extra files needed (default labely v HTML jsou CS)
  if (code === 'cs') return;
  var verMatch = (document.querySelector('script[src*="i18n.js?v="]') || {}).src || '';
  var ver = (verMatch.match(/v=([0-9.]+)/) || [])[1] || '0.0.0';
  // EN/ES needs auto (CS→EN/ES tuples)
  if ((code === 'en' || code === 'es' || code === 'sk' || code === 'de') && !window._appekI18nLoaded.auto) {
    try {
      await loadScript('i18n_auto.js?v=' + ver);
      window._appekI18nLoaded.auto = true;
    } catch (e) { console.warn('[i18n] auto load failed', e); }
  }
  // SK/DE needs extra (overlay přes I18N_LOOKUP)
  if ((code === 'sk' || code === 'de') && !window._appekI18nLoaded.extra) {
    try {
      await loadScript('i18n_extra.js?v=' + ver);
      window._appekI18nLoaded.extra = true;
    } catch (e) { console.warn('[i18n] extra load failed', e); }
  }
};

window.setAppekLang = async function(code) {
  if (!window.appekLangs.find(l => l.code === code)) code = 'cs';
  // 🆕 v2.9.314 — pre-load i18n bundles BEFORE applying (jinak by translatePage() failnul lookup)
  try { await window.loadI18nForLang(code); } catch (e) {}
  window.appekCurrentLang = code;
  try { localStorage.setItem(I18N_LANG_KEY, code); } catch (e) {}
  document.documentElement.lang = code;
  applyTranslations();
};

const I18N = {
  // ─────────────── COMMON ACTIONS ───────────────
  save:        { cs: 'Uložit',           en: 'Save',           es: 'Guardar' },
  cancel:      { cs: 'Zrušit',           en: 'Cancel',         es: 'Cancelar' },
  delete:      { cs: 'Smazat',           en: 'Delete',         es: 'Eliminar' },
  edit:        { cs: 'Upravit',          en: 'Edit',           es: 'Editar' },
  new:         { cs: 'Nový',             en: 'New',            es: 'Nuevo' },
  add:         { cs: 'Přidat',           en: 'Add',            es: 'Añadir' },
  close:       { cs: 'Zavřít',           en: 'Close',          es: 'Cerrar' },
  back:        { cs: 'Zpět',             en: 'Back',           es: 'Atrás' },
  continue:    { cs: 'Pokračovat',       en: 'Continue',       es: 'Continuar' },
  search:      { cs: 'Hledat',           en: 'Search',         es: 'Buscar' },
  filter:      { cs: 'Filtrovat',        en: 'Filter',         es: 'Filtrar' },
  refresh:     { cs: 'Obnovit',          en: 'Refresh',        es: 'Actualizar' },
  loading:     { cs: 'Načítám…',         en: 'Loading…',       es: 'Cargando…' },
  print:       { cs: 'Tisknout',         en: 'Print',          es: 'Imprimir' },
  download:    { cs: 'Stáhnout',         en: 'Download',       es: 'Descargar' },
  copy:        { cs: 'Kopírovat',        en: 'Copy',           es: 'Copiar' },
  yes:         { cs: 'Ano',              en: 'Yes',            es: 'Sí' },
  no:          { cs: 'Ne',               en: 'No',             es: 'No' },
  ok:          { cs: 'OK',               en: 'OK',             es: 'OK' },
  confirm:     { cs: 'Potvrdit',         en: 'Confirm',        es: 'Confirmar' },
  required:    { cs: 'Povinné',          en: 'Required',       es: 'Requerido' },
  optional:    { cs: 'Volitelné',        en: 'Optional',       es: 'Opcional' },

  // ─────────────── STATUSES / GENERIC ───────────────
  saved:       { cs: 'Uloženo',          en: 'Saved',          es: 'Guardado' },
  deleted:     { cs: 'Smazáno',          en: 'Deleted',        es: 'Eliminado' },
  done:        { cs: 'Hotovo',           en: 'Done',           es: 'Hecho' },
  error:       { cs: 'Chyba',            en: 'Error',          es: 'Error' },
  warning:     { cs: 'Upozornění',       en: 'Warning',        es: 'Aviso' },
  info:        { cs: 'Informace',        en: 'Info',           es: 'Información' },
  total:       { cs: 'Celkem',           en: 'Total',          es: 'Total' },
  today:       { cs: 'Dnes',             en: 'Today',          es: 'Hoy' },
  yesterday:   { cs: 'Včera',            en: 'Yesterday',      es: 'Ayer' },
  tomorrow:    { cs: 'Zítra',            en: 'Tomorrow',       es: 'Mañana' },
  week:        { cs: 'Týden',            en: 'Week',           es: 'Semana' },
  month:       { cs: 'Měsíc',            en: 'Month',          es: 'Mes' },
  year:        { cs: 'Rok',              en: 'Year',           es: 'Año' },
  date:        { cs: 'Datum',            en: 'Date',           es: 'Fecha' },
  amount:      { cs: 'Částka',           en: 'Amount',         es: 'Importe' },
  price:       { cs: 'Cena',             en: 'Price',          es: 'Precio' },
  quantity:    { cs: 'Množství',         en: 'Quantity',       es: 'Cantidad' },
  status:      { cs: 'Stav',             en: 'Status',         es: 'Estado' },
  active:      { cs: 'Aktivní',          en: 'Active',         es: 'Activo' },
  inactive:    { cs: 'Neaktivní',        en: 'Inactive',       es: 'Inactivo' },
  blocked:     { cs: 'Blokován',         en: 'Blocked',        es: 'Bloqueado' },

  // ─────────────── SIDEBAR / NAVIGATION ───────────────
  nav_dashboard:    { cs: 'Přehled',           en: 'Dashboard',         es: 'Panel' },
  nav_orders:       { cs: 'Objednávky',        en: 'Orders',            es: 'Pedidos' },
  nav_production:   { cs: 'Výroba',            en: 'Production',        es: 'Producción' },
  nav_delivery:     { cs: 'Dodací listy',      en: 'Delivery notes',    es: 'Albaranes' },
  nav_invoices:     { cs: 'Faktury',           en: 'Invoices',          es: 'Facturas' },
  nav_products:     { cs: 'Výrobky',           en: 'Products',          es: 'Productos' },
  nav_catalog:      { cs: 'PDF nabídka',       en: 'PDF catalog',       es: 'Catálogo PDF' },
  nav_labels:       { cs: 'Štítky a cenovky',  en: 'Labels & prices',   es: 'Etiquetas y precios' },
  nav_haccp:        { cs: 'HACCP',             en: 'HACCP',             es: 'APPCC' },
  nav_customers:    { cs: 'Odběratelé',        en: 'Customers',         es: 'Clientes' },
  nav_settings:     { cs: 'Nastavení',         en: 'Settings',          es: 'Ajustes' },

  // ─────────────── LOGIN ───────────────
  login_title:      { cs: 'Administrace',      en: 'Administration',    es: 'Administración' },
  login_email:      { cs: 'E-mail',            en: 'E-mail',            es: 'E-mail' },
  login_password:   { cs: 'Heslo',             en: 'Password',          es: 'Contraseña' },
  login_submit:     { cs: 'Přihlásit se',      en: 'Sign in',           es: 'Iniciar sesión' },
  login_failed:     { cs: 'Nesprávný email nebo heslo', en: 'Wrong email or password', es: 'Email o contraseña incorrectos' },

  // ─────────────── TOPBAR ───────────────
  greet:            { cs: 'Vítejte,',          en: 'Welcome,',          es: 'Bienvenido,' },
  online_b2b:       { cs: 'online B2B',        en: 'online B2B',        es: 'B2B en línea' },
  open_apps:        { cs: 'Aplikace',           sk: 'Aplikácie',        en: 'Apps',              de: 'Apps',           es: 'Apps' },
  open_pos:         { cs: 'POS Kasa',           sk: 'POS Pokladňa',     en: 'POS Register',      de: 'POS Kasse',      es: 'TPV / POS' },
  open_fp:          { cs: 'Mapa stolů',         sk: 'Mapa stolov',      en: 'Floor Plan',        de: 'Sitzplan',       es: 'Plano de mesas' },
  fullscreen:       { cs: 'Celá obrazovka',    en: 'Fullscreen',        es: 'Pantalla completa' },
  logout:           { cs: 'Odhlásit',          en: 'Sign out',          es: 'Cerrar sesión' },
  notifications:    { cs: 'Notifikace',        en: 'Notifications',     es: 'Notificaciones' },
  mark_all_read:    { cs: 'Vše přečteno',      en: 'Mark all read',     es: 'Marcar todo leído' },
  no_notifications: { cs: 'Žádné nové notifikace', en: 'No new notifications', es: 'Sin notificaciones' },

  // ─────────────── DASHBOARD ───────────────
  dash_orders_period: { cs: 'Objednávek',      en: 'Orders',            es: 'Pedidos' },
  dash_revenue_period:{ cs: 'Tržby',           en: 'Revenue',           es: 'Ingresos' },
  dash_today_orders:  { cs: 'Dnes - objednávek',en: 'Today - orders',   es: 'Hoy - pedidos' },
  dash_overdue:       { cs: 'Po splatnosti',   en: 'Overdue',           es: 'Vencido' },
  dash_recent:        { cs: 'Nedávné',         en: 'Recent',            es: 'Recientes' },
  dash_top_customers: { cs: 'TOP odběratelů',  en: 'TOP customers',     es: 'TOP clientes' },
  dash_top_products:  { cs: 'TOP výrobků',     en: 'TOP products',      es: 'TOP productos' },
  per_day:            { cs: 'den',             en: 'day',               es: 'día' },

  // ─────────────── ORDERS ───────────────
  order_new:        { cs: 'Nová objednávka',   en: 'New order',         es: 'Nuevo pedido' },
  order_number:     { cs: 'Číslo',             en: 'Number',            es: 'Número' },
  order_customer:   { cs: 'Odběratel',         en: 'Customer',          es: 'Cliente' },
  order_delivery:   { cs: 'Místo dodání',      en: 'Delivery to',       es: 'Lugar de entrega' },
  order_date:       { cs: 'Datum dodání',      en: 'Delivery date',     es: 'Fecha de entrega' },
  order_items:      { cs: 'Položky',           en: 'Items',             es: 'Artículos' },
  order_note:       { cs: 'Poznámka',          en: 'Note',              es: 'Nota' },
  order_status_new:    { cs: 'Nová',           en: 'New',               es: 'Nuevo' },
  order_status_confirmed: { cs: 'Potvrzená',   en: 'Confirmed',         es: 'Confirmado' },
  order_status_production:{ cs: 'Ve výrobě',   en: 'In production',     es: 'En producción' },
  order_status_ready:  { cs: 'Připravená',     en: 'Ready',             es: 'Lista' },
  order_status_shipped:{ cs: 'Expedovaná',     en: 'Shipped',           es: 'Enviado' },
  order_status_delivered: { cs: 'Doručená',    en: 'Delivered',         es: 'Entregado' },
  order_status_cancelled: { cs: 'Zrušená',     en: 'Cancelled',         es: 'Cancelado' },

  // ─────────────── PRODUCTS ───────────────
  product_new:      { cs: '+ Nový výrobek',    en: '+ New product',     es: '+ Nuevo producto' },
  product_name:     { cs: 'Název',             en: 'Name',              es: 'Nombre' },
  product_code:     { cs: 'Číslo / kód',       en: 'Code',              es: 'Código' },
  product_category: { cs: 'Kategorie',         en: 'Category',          es: 'Categoría' },
  product_unit:     { cs: 'Jednotka',          en: 'Unit',              es: 'Unidad' },
  product_weight:   { cs: 'Hmotnost',          en: 'Weight',            es: 'Peso' },
  product_vat:      { cs: 'DPH',               en: 'VAT',               es: 'IVA' },
  product_price_net:{ cs: 'Cena bez DPH',      en: 'Price excl. VAT',   es: 'Precio sin IVA' },
  product_price_gross: { cs: 'Cena s DPH',     en: 'Price incl. VAT',   es: 'Precio con IVA' },
  product_allergens:{ cs: 'Alergeny',          en: 'Allergens',         es: 'Alérgenos' },
  product_ean:      { cs: 'EAN',               en: 'EAN',               es: 'EAN' },

  // ─────────────── CUSTOMERS ───────────────
  customer_new:     { cs: '+ Nový odběratel',  en: '+ New customer',    es: '+ Nuevo cliente' },
  customer_company: { cs: 'Firma / Název',     en: 'Company / Name',    es: 'Empresa / Nombre' },
  customer_ico:     { cs: 'IČO',               en: 'Tax ID',            es: 'CIF' },
  customer_dic:     { cs: 'DIČ',               en: 'VAT ID',            es: 'NIF' },
  customer_email:   { cs: 'E-mail',            en: 'E-mail',            es: 'E-mail' },
  customer_phone:   { cs: 'Telefon',           en: 'Phone',             es: 'Teléfono' },
  customer_address: { cs: 'Adresa',            en: 'Address',           es: 'Dirección' },
  customer_contact: { cs: 'Kontaktní osoba',   en: 'Contact person',    es: 'Persona de contacto' },
  customer_due_days:{ cs: 'Splatnost (dní)',   en: 'Payment due (days)',es: 'Vencimiento (días)' },

  // ─────────────── SETTINGS ───────────────
  settings_company:  { cs: '🏢 Firma & doklady', en: '🏢 Company & docs', es: '🏢 Empresa y documentos' },
  settings_notif:    { cs: '📧 Notifikace',    en: '📧 Notifications',  es: '📧 Notificaciones' },
  settings_production:{ cs: '🥖 Výroba',       en: '🥖 Production',     es: '🥖 Producción' },
  settings_access:   { cs: '👥 Přístupy & ceny', en: '👥 Access & prices', es: '👥 Acceso y precios' },
  settings_packages: { cs: '🎁 Balíčky',       en: '🎁 Modules',        es: '🎁 Módulos' },
  settings_maintenance:{ cs: '🛠️ Údržba',      en: '🛠️ Maintenance',    es: '🛠️ Mantenimiento' },
  settings_help:     { cs: '❓ Nápověda',       en: '❓ Help',            es: '❓ Ayuda' },
  settings_language: { cs: 'Jazyk aplikace',   en: 'Application language', es: 'Idioma de la aplicación' },

  // ─────────────── EMPTY STATES ───────────────
  empty_products_title: { cs: 'Zatím žádné výrobky', en: 'No products yet', es: 'Aún no hay productos' },
  empty_orders_title:   { cs: 'Zatím žádné objednávky', en: 'No orders yet', es: 'Aún no hay pedidos' },
  empty_customers_title:{ cs: 'Zatím žádní odběratelé', en: 'No customers yet', es: 'Aún no hay clientes' },
  empty_invoices_title: { cs: 'Zatím žádné faktury', en: 'No invoices yet', es: 'Aún no hay facturas' },
  empty_delivery_title: { cs: 'Zatím žádné DL', en: 'No delivery notes yet', es: 'Aún no hay albaranes' },

  // ─────────────── NAVIGATION (extended) ───────────────
  nav_recurring:    { cs: 'Opakující se',      en: 'Recurring',         es: 'Recurrente' },
  nav_routes:       { cs: 'Rozvozy',           en: 'Delivery routes',   es: 'Rutas de entrega' },
  nav_warehouse:    { cs: 'Sklad',             en: 'Warehouse',         es: 'Almacén' },
  nav_ingredients:  { cs: 'Suroviny',          en: 'Ingredients',       es: 'Ingredientes' },
  nav_categories:   { cs: 'Kategorie',         en: 'Categories',        es: 'Categorías' },
  nav_users:        { cs: 'Uživatelé',         en: 'Users',             es: 'Usuarios' },
  nav_pricing:      { cs: 'Cenové skupiny',    en: 'Price groups',      es: 'Grupos de precios' },
  // 🆕 v2.9.225 — Nástroje hub (katalog + štítky)
  nav_tools:        { cs: 'Nástroje',          en: 'Tools',             es: 'Herramientas' },

  // ─────────────── FORMS ───────────────
  form_name:        { cs: 'Název',             en: 'Name',              es: 'Nombre' },
  form_code:        { cs: 'Kód',               en: 'Code',              es: 'Código' },
  form_description: { cs: 'Popis',             en: 'Description',       es: 'Descripción' },
  form_note:        { cs: 'Poznámka',          en: 'Note',              es: 'Nota' },
  form_email:       { cs: 'E-mail',            en: 'E-mail',            es: 'E-mail' },
  form_phone:       { cs: 'Telefon',           en: 'Phone',             es: 'Teléfono' },
  form_street:      { cs: 'Ulice',             en: 'Street',            es: 'Calle' },
  form_city:        { cs: 'Město',             en: 'City',              es: 'Ciudad' },
  form_zip:         { cs: 'PSČ',               en: 'ZIP',               es: 'CP' },
  form_country:     { cs: 'Stát',              en: 'Country',           es: 'País' },
  form_password:    { cs: 'Heslo',             en: 'Password',          es: 'Contraseña' },
  form_password_again: { cs: 'Heslo znovu',    en: 'Password again',    es: 'Contraseña otra vez' },
  form_select:      { cs: 'Vyber…',            en: 'Select…',           es: 'Seleccionar…' },
  form_all:         { cs: 'Vše',               en: 'All',               es: 'Todo' },
  form_none:        { cs: 'Žádné',             en: 'None',              es: 'Ninguno' },
  form_from:        { cs: 'Od',                en: 'From',              es: 'Desde' },
  form_to:          { cs: 'Do',                en: 'To',                es: 'Hasta' },

  // ─────────────── INVOICE / DL specific ───────────────
  invoice_number:   { cs: 'Číslo faktury',     en: 'Invoice number',    es: 'Nº de factura' },
  invoice_issued:   { cs: 'Datum vystavení',   en: 'Issue date',        es: 'Fecha de emisión' },
  invoice_due:      { cs: 'Datum splatnosti',  en: 'Due date',          es: 'Fecha de vencimiento' },
  invoice_vs:       { cs: 'Variabilní symbol', en: 'Variable symbol',   es: 'Símbolo variable' },
  invoice_paid:     { cs: 'Uhrazeno',          en: 'Paid',              es: 'Pagado' },
  invoice_unpaid:   { cs: 'Neuhrazeno',        en: 'Unpaid',            es: 'No pagado' },
  invoice_overdue:  { cs: 'Po splatnosti',     en: 'Overdue',           es: 'Vencido' },
  net:              { cs: 'Bez DPH',           en: 'Excl. VAT',         es: 'Sin IVA' },
  gross:            { cs: 'S DPH',             en: 'Incl. VAT',         es: 'Con IVA' },
  subtotal:         { cs: 'Mezisoučet',        en: 'Subtotal',          es: 'Subtotal' },
  vat:              { cs: 'DPH',               en: 'VAT',               es: 'IVA' },
  grand_total:      { cs: 'Celkem k úhradě',   en: 'Grand total',       es: 'Total a pagar' },

  // ─────────────── SUROVINY / INGREDIENTS ───────────────
  ing_unit:         { cs: 'Jednotka',          en: 'Unit',              es: 'Unidad' },
  ing_pkg_price:    { cs: 'Cena balení',       en: 'Pack price',        es: 'Precio del paquete' },
  ing_pkg_content:  { cs: 'Obsah balení',      en: 'Pack content',      es: 'Contenido del paquete' },
  ing_allergen:     { cs: 'Alergen',           en: 'Allergen',          es: 'Alérgeno' },
  ing_composition:  { cs: 'Složení',           en: 'Composition',       es: 'Composición' },
  ing_stock:        { cs: 'Skladem',           en: 'In stock',          es: 'En stock' },
  ing_min_stock:    { cs: 'Min. zásoba',       en: 'Min stock',         es: 'Stock mínimo' },

  // ─────────────── HACCP ───────────────
  haccp_plan:       { cs: 'Plán HACCP',        en: 'HACCP Plan',        es: 'Plan APPCC' },
  haccp_sanitation: { cs: 'Sanitační řád',     en: 'Sanitation rules',  es: 'Reglas de sanidad' },
  haccp_training:   { cs: 'Záznamy o školení', en: 'Training records',  es: 'Registros de formación' },
  haccp_audit:      { cs: 'Interní audit',     en: 'Internal audit',    es: 'Auditoría interna' },
  haccp_ccp:        { cs: 'Postupy CCP',       en: 'CCP procedures',    es: 'Procedimientos PCC' },
  haccp_forms:      { cs: 'Formuláře',         en: 'Forms',             es: 'Formularios' },

  // ─────────────── DASHBOARD extended ───────────────
  recent_orders:    { cs: 'Nedávné objednávky', en: 'Recent orders',    es: 'Pedidos recientes' },
  recent_dl:        { cs: 'Nedávné dodací listy', en: 'Recent delivery notes', es: 'Albaranes recientes' },
  recent_invoices:  { cs: 'Nedávné faktury',   en: 'Recent invoices',   es: 'Facturas recientes' },
  production_tomorrow: { cs: 'Výroba na zítra', en: 'Production tomorrow', es: 'Producción para mañana' },
  no_orders_tomorrow:  { cs: 'Žádné objednávky na zítra', en: 'No orders for tomorrow', es: 'Sin pedidos para mañana' },

  // ─────────────── BULK ACTIONS ───────────────
  bulk_select_all:  { cs: 'Vybrat vše',        en: 'Select all',        es: 'Seleccionar todo' },
  bulk_deselect_all:{ cs: 'Zrušit výběr',      en: 'Deselect all',      es: 'Deseleccionar todo' },
  bulk_selected:    { cs: 'Vybráno',           en: 'Selected',          es: 'Seleccionado' },
  bulk_print:       { cs: 'Tisk vybraných',    en: 'Print selected',    es: 'Imprimir seleccionados' },
  bulk_delete:      { cs: 'Smazat vybrané',    en: 'Delete selected',   es: 'Eliminar seleccionados' },
  bulk_export:      { cs: 'Export vybraných',  en: 'Export selected',   es: 'Exportar seleccionados' },

  // ─────────────── IMPORT/EXPORT ───────────────
  import:           { cs: 'Import',            en: 'Import',            es: 'Importar' },
  export:           { cs: 'Export',            en: 'Export',            es: 'Exportar' },
  import_price_list:{ cs: 'Import ceníku',     en: 'Import price list', es: 'Importar lista de precios' },
  upload_file:      { cs: 'Nahrát soubor',     en: 'Upload file',       es: 'Subir archivo' },
  drag_drop:        { cs: 'Přetáhni soubor sem', en: 'Drag file here',  es: 'Arrastra el archivo aquí' },
  mapping:          { cs: 'Mapování',          en: 'Mapping',           es: 'Mapeo' },
  preview:          { cs: 'Náhled',            en: 'Preview',           es: 'Vista previa' },
  apply:            { cs: 'Aplikovat',         en: 'Apply',             es: 'Aplicar' },

  // ─────────────── PACKAGES ───────────────
  pkg_active:       { cs: 'Aktivní',           en: 'Active',            es: 'Activo' },
  pkg_locked:       { cs: '🔒 Zakoupit',       en: '🔒 Purchase',       es: '🔒 Comprar' },
  pkg_upgrade_needed: { cs: 'Vyžaduje upgrade licence', en: 'License upgrade required', es: 'Se requiere actualización de licencia' },
  pkg_update_key:   { cs: 'Aktualizovat klíč', en: 'Update key',        es: 'Actualizar clave' },

  // ─────────────── KEYBOARD HINTS ───────────────
  kbd_search:       { cs: 'Hledání',           en: 'Search',            es: 'Buscar' },
  kbd_open:         { cs: 'Otevřít',           en: 'Open',              es: 'Abrir' },
  kbd_move:         { cs: 'Pohyb',             en: 'Move',              es: 'Mover' },
  kbd_close:        { cs: 'Zavřít',            en: 'Close',             es: 'Cerrar' },
  kbd_save_short:   { cs: 'Uložit',            en: 'Save',              es: 'Guardar' },

  // ─────────────── TOAST MESSAGES (common) ───────────────
  toast_saved:      { cs: '✅ Uloženo',        en: '✅ Saved',          es: '✅ Guardado' },
  toast_deleted:    { cs: '✅ Smazáno',        en: '✅ Deleted',        es: '✅ Eliminado' },
  toast_copied:     { cs: '✅ Zkopírováno',    en: '✅ Copied',         es: '✅ Copiado' },
  toast_error:      { cs: '❌ Chyba',          en: '❌ Error',          es: '❌ Error' },
  toast_no_results: { cs: '🤷 Žádné výsledky', en: '🤷 No results',     es: '🤷 Sin resultados' },

  // ─────────────── CONFIRMS ───────────────
  confirm_delete_title: { cs: 'Smazat?',       en: 'Delete?',           es: '¿Eliminar?' },
  confirm_delete_msg:   { cs: 'Tato akce je nevratná.', en: 'This action cannot be undone.', es: 'Esta acción no se puede deshacer.' },

  // ─────────────── NOTIFICATIONS extended ───────────────
  notif_no_new:     { cs: '🎉 Žádné nové notifikace', en: '🎉 No new notifications', es: '🎉 Sin notificaciones nuevas' },
  notif_new_order:  { cs: 'Nová objednávka',   en: 'New order',         es: 'Nuevo pedido' },
  notif_low_stock:  { cs: 'Nízký sklad',       en: 'Low stock',         es: 'Stock bajo' },
  notif_sync_error: { cs: 'Sync chyba',        en: 'Sync error',        es: 'Error de sincronización' },
  notif_backup_stale: { cs: 'Záloha je stará', en: 'Backup is stale',   es: 'La copia de seguridad está obsoleta' },

  // ─────────────── COMMAND PALETTE ───────────────
  cmdk_quick_actions: { cs: '⚡ Rychlé akce',  en: '⚡ Quick actions',  es: '⚡ Acciones rápidas' },
  cmdk_pages:       { cs: '📁 Stránky',        en: '📁 Pages',          es: '📁 Páginas' },
  cmdk_search:      { cs: '🔍 Hledání',        en: '🔍 Search',         es: '🔍 Búsqueda' },
  cmdk_placeholder: { cs: 'Hledej cokoliv — výrobek, odběratele, akci…', en: 'Search anything — product, customer, action…', es: 'Busca cualquier cosa — producto, cliente, acción…' },

  // ─────────────── ONBOARDING EXTENDED ───────────────
  onboard_welcome_t: { cs: 'Vítejte v Appek!', en: 'Welcome to Appek!', es: '¡Bienvenido a Appek!' },
  onboard_welcome_d: { cs: 'Pomůžeme vám rozjet váš objednávkový systém za ~5 minut.', en: 'We will help you set up your ordering system in ~5 minutes.', es: 'Te ayudaremos a poner en marcha tu sistema de pedidos en ~5 minutos.' },
  onboard_step_label: { cs: 'Krok', en: 'Step', es: 'Paso' },
  onboard_of:        { cs: 'z', en: 'of', es: 'de' },
  onboard_skip_all:  { cs: '✕ Přeskočit vše', en: '✕ Skip all', es: '✕ Omitir todo' },
  onboard_back:      { cs: '← Zpět', en: '← Back', es: '← Atrás' },
  onboard_next:      { cs: '➜ Dál', en: '➜ Next', es: '➜ Siguiente' },
  onboard_start:     { cs: '➜ Začít', en: '➜ Start', es: '➜ Empezar' },
  onboard_finish:    { cs: '➜ Hotovo!', en: '➜ Done!', es: '➜ ¡Listo!' },
  onboard_selected:  { cs: '✓ Vybráno', en: '✓ Selected', es: '✓ Seleccionado' },
  onboard_save:      { cs: '💾 Uložit', en: '💾 Save', es: '💾 Guardar' },

  // Onboarding krok 1 — Jazyk
  ob_lang_t:    { cs: '🌍 Vyber jazyk aplikace', en: '🌍 Choose application language', es: '🌍 Selecciona el idioma' },
  ob_lang_d:    { cs: 'Aplikace plně přeložená do češtiny, angličtiny a španělštiny.', en: 'App fully translated to Czech, English and Spanish.', es: 'App completamente traducida al checo, inglés y español.' },
  ob_lang_info: { cs: '💡 Při výběru EN/ES se automaticky upraví výchozí měna, sazby DPH a formát data.', en: '💡 Selecting EN/ES auto-adjusts default currency, VAT rates and date format.', es: '💡 Al seleccionar EN/ES, la moneda, IVA y formato de fecha se ajustan automáticamente.' },

  // Onboarding krok 2 — Install mode
  ob_mode_t: { cs: '🏠 Kde to chceš provozovat?', en: '🏠 Where do you want to run it?', es: '🏠 ¿Dónde quieres ejecutarlo?' },
  ob_mode_d: { cs: 'Vyber způsob hosting. Můžeš kdykoliv změnit.', en: 'Choose hosting type. You can change anytime.', es: 'Elige el tipo de hosting. Puedes cambiar en cualquier momento.' },
  ob_mode_local:  { cs: 'Lokální', en: 'Local', es: 'Local' },
  ob_mode_cloud:  { cs: 'Cloud',   en: 'Cloud', es: 'Cloud' },
  ob_mode_hybrid: { cs: 'Hybridní', en: 'Hybrid', es: 'Híbrido' },
  ob_mode_local_d:  { cs: 'XAMPP / MAMP zdarma. Offline. Data jen u tebe.', en: 'XAMPP / MAMP for free. Offline. Data only with you.', es: 'XAMPP / MAMP gratis. Offline. Datos solo contigo.' },
  ob_mode_cloud_d:  { cs: 'Vždy online. Automatické zálohy. Z mobilu i PC.', en: 'Always online. Automatic backups. From mobile or PC.', es: 'Siempre online. Copias automáticas. Desde móvil o PC.' },
  ob_mode_hybrid_d: { cs: 'Hlavní data lokálně, sync s cloudem pro mobilní přístup.', en: 'Main data locally, sync with cloud for mobile access.', es: 'Datos principales localmente, sync cloud para acceso móvil.' },

  // Onboarding krok 3 — Údaje firmy
  ob_firm_t:     { cs: '🏢 Údaje vaší firmy', en: '🏢 Your company details', es: '🏢 Datos de tu empresa' },
  ob_firm_d:     { cs: 'Pro fakturace a HACCP dokumenty. V ČR vyplňte IČO — zbytek se načte z ARES.', en: 'For invoicing and HACCP. In CZ enter company ID — rest auto-fills from ARES.', es: 'Para facturación y APPCC. Introduce el NIF para auto-rellenar.' },
  ob_firm_name:  { cs: 'Název firmy', en: 'Company name', es: 'Nombre de empresa' },
  ob_firm_ico:   { cs: 'IČO', en: 'Company ID', es: 'NIF/CIF' },
  ob_firm_dic:   { cs: 'DIČ', en: 'VAT ID', es: 'NIF IVA' },
  ob_firm_addr:  { cs: 'Ulice + číslo', en: 'Street + number', es: 'Calle + número' },
  ob_firm_city:  { cs: 'Město', en: 'City', es: 'Ciudad' },
  ob_firm_psc:   { cs: 'PSČ', en: 'Postal code', es: 'Código postal' },
  ob_firm_email: { cs: 'E-mail (pro odběratele)', en: 'Email (for customers)', es: 'Email (para clientes)' },
  ob_firm_phone: { cs: 'Telefon', en: 'Phone', es: 'Teléfono' },
  ob_firm_bank:  { cs: 'Bankovní účet', en: 'Bank account', es: 'Cuenta bancaria' },
  ob_firm_ares:  { cs: '🔍 Načíst z ARES', en: '🔍 Auto-fill from ARES', es: '🔍 Auto-rellenar' },

  // Onboarding krok 4 — Logo
  ob_logo_t:   { cs: '🎨 Logo a vzhled', en: '🎨 Logo and design', es: '🎨 Logo y diseño' },
  ob_logo_d:   { cs: 'Nahrajte logo (PNG s průhledným pozadím, min 300×300).', en: 'Upload logo (PNG with transparent background, min 300×300).', es: 'Sube el logo (PNG fondo transparente, mín 300×300).' },
  ob_logo_upload: { cs: '📤 Nahrát logo', en: '📤 Upload logo', es: '📤 Subir logo' },
  ob_theme:    { cs: 'Hlavní barva', en: 'Main color', es: 'Color principal' },

  // Onboarding krok 5 — Balíčky
  ob_pkg_t: { cs: '📦 Vyber balíčky', en: '📦 Choose packages', es: '📦 Elige paquetes' },
  ob_pkg_d: { cs: 'Doporučujeme HACCP a Výrobní plán. Můžeš kdykoliv aktivovat/deaktivovat.', en: 'We recommend HACCP and Production Plan. Toggle anytime.', es: 'Recomendamos APPCC y Plan de Producción. Cambiable.' },

  // Onboarding krok 6 — Demo data
  ob_demo_t:  { cs: '🧪 Chceš nahrát demo data?', en: '🧪 Want to load demo data?', es: '🧪 ¿Cargar datos demo?' },
  ob_demo_d:  { cs: 'Nahrajeme 15 výrobků, 5 odběratelů a 3 objednávky.', en: 'We will load 15 products, 5 customers, 3 orders.', es: 'Cargaremos 15 productos, 5 clientes, 3 pedidos.' },
  ob_demo_yes: { cs: 'Ano, nahrát demo', en: 'Yes, load demo', es: 'Sí, cargar demo' },
  ob_demo_no:  { cs: 'Ne, čisté prostředí', en: 'No, clean environment', es: 'No, entorno limpio' },
  ob_demo_yes_d: { cs: 'Doporučeno pro první vyzkoušení.', en: 'Recommended for first try.', es: 'Recomendado para primera prueba.' },
  ob_demo_no_d:  { cs: 'Pro ostrý provoz — vlož vlastní data.', en: 'For production — enter your own data.', es: 'Para producción — introduce tus datos.' },

  // Onboarding krok 7 — Quick start
  ob_qs_t: { cs: '🚀 Quick start — co dělat dál', en: '🚀 Quick start — what to do next', es: '🚀 Inicio rápido — qué hacer' },
  ob_qs_d: { cs: 'Doporučený postup pro první spuštění. Celkem ~30 minut.', en: 'Recommended steps for first launch. Total ~30 minutes.', es: 'Pasos recomendados. Total ~30 minutos.' },
  ob_qs_summary: { cs: '✅ Celkem ~30 minut a budeš mít aplikaci plně rozjetou s prvními tržbami.', en: '✅ ~30 minutes total and you will have the app fully running.', es: '✅ ~30 minutos y tendrás la app totalmente operativa.' },

  // Onboarding krok 8 — Done
  ob_done_t: { cs: '🎉 Hotovo!', en: '🎉 Done!', es: '🎉 ¡Listo!' },
  ob_done_d: { cs: 'Onboarding dokončen. Můžeš začít používat aplikaci.', en: 'Onboarding complete. You can start using the app.', es: 'Onboarding completado. Puedes empezar a usar la app.' },

  // ─────────────── DASHBOARD ───────────────
  dash_today:    { cs: 'Dnes',           en: 'Today',       es: 'Hoy' },
  dash_week:     { cs: 'Tento týden',    en: 'This week',   es: 'Esta semana' },
  dash_month:    { cs: 'Tento měsíc',    en: 'This month',  es: 'Este mes' },
  dash_year:     { cs: 'Tento rok',      en: 'This year',   es: 'Este año' },
  dash_revenue:  { cs: 'Tržby',          en: 'Revenue',     es: 'Ingresos' },
  dash_orders:   { cs: 'Objednávky',     en: 'Orders',      es: 'Pedidos' },
  dash_customers:{ cs: 'Zákazníci',      en: 'Customers',   es: 'Clientes' },
  dash_avg:      { cs: 'Průměrná objednávka', en: 'Avg order', es: 'Pedido medio' },
  dash_top_prod: { cs: 'Top výrobky',    en: 'Top products', es: 'Top productos' },
  dash_top_cust: { cs: 'Top zákazníci',  en: 'Top customers', es: 'Top clientes' },
  dash_recent:   { cs: 'Nedávné objednávky', en: 'Recent orders', es: 'Pedidos recientes' },
  dash_no_data:  { cs: 'Zatím žádná data', en: 'No data yet', es: 'Sin datos aún' },

  // ─────────────── ORDERS ───────────────
  order_new:        { cs: '+ Nová objednávka', en: '+ New order', es: '+ Nuevo pedido' },
  order_number:     { cs: 'Číslo objednávky', en: 'Order number', es: 'Nº de pedido' },
  order_date:       { cs: 'Datum dodání',    en: 'Delivery date', es: 'Fecha de entrega' },
  order_customer:   { cs: 'Odběratel',       en: 'Customer',     es: 'Cliente' },
  order_status:     { cs: 'Stav',            en: 'Status',       es: 'Estado' },
  order_total:      { cs: 'Celkem',          en: 'Total',        es: 'Total' },
  order_items:      { cs: 'Položky',         en: 'Items',        es: 'Artículos' },
  order_note:       { cs: 'Poznámka',        en: 'Note',         es: 'Nota' },
  order_status_new: { cs: 'Nová',            en: 'New',          es: 'Nuevo' },
  order_status_inprod: { cs: 'Ve výrobě',    en: 'In production', es: 'En producción' },
  order_status_ready: { cs: 'Připraveno',    en: 'Ready',        es: 'Listo' },
  order_status_delivered: { cs: 'Doručeno',  en: 'Delivered',    es: 'Entregado' },
  order_status_invoiced: { cs: 'Vyfakturováno', en: 'Invoiced', es: 'Facturado' },
  order_status_paid: { cs: 'Zaplaceno',      en: 'Paid',         es: 'Pagado' },
  order_status_cancelled: { cs: 'Zrušeno',   en: 'Cancelled',    es: 'Cancelado' },
  order_add_item:   { cs: '+ Přidat položku', en: '+ Add item',  es: '+ Añadir artículo' },
  order_qty:        { cs: 'Množství',        en: 'Quantity',     es: 'Cantidad' },
  order_price:      { cs: 'Cena',            en: 'Price',        es: 'Precio' },
  order_unit:       { cs: 'Jednotka',        en: 'Unit',         es: 'Unidad' },
  order_vat:        { cs: 'DPH',             en: 'VAT',          es: 'IVA' },
  order_search_customer: { cs: 'Vyber odběratele…', en: 'Choose customer…', es: 'Elige cliente…' },
  order_search_product: { cs: 'Vyhledej výrobek…', en: 'Find product…', es: 'Buscar producto…' },

  // ─────────────── PRODUCTS ───────────────
  prod_new:      { cs: '+ Nový výrobek',  en: '+ New product',  es: '+ Nuevo producto' },
  prod_name:     { cs: 'Název',           en: 'Name',           es: 'Nombre' },
  prod_code:     { cs: 'Číslo',           en: 'Code',           es: 'Código' },
  prod_category: { cs: 'Kategorie',       en: 'Category',       es: 'Categoría' },
  prod_unit:     { cs: 'Jednotka',        en: 'Unit',           es: 'Unidad' },
  prod_price:    { cs: 'Cena bez DPH',    en: 'Price excl. VAT', es: 'Precio sin IVA' },
  prod_vat:      { cs: 'Sazba DPH',       en: 'VAT rate',       es: 'Tasa IVA' },
  prod_weight:   { cs: 'Hmotnost (g)',    en: 'Weight (g)',     es: 'Peso (g)' },
  prod_active:   { cs: 'Aktivní',         en: 'Active',         es: 'Activo' },
  prod_alergens: { cs: 'Alergeny',        en: 'Allergens',      es: 'Alérgenos' },
  prod_recipe:   { cs: 'Receptura',       en: 'Recipe',         es: 'Receta' },
  prod_lead_time:{ cs: 'Doba výroby',     en: 'Production time', es: 'Tiempo producción' },
  prod_search:   { cs: 'Vyhledat výrobek…', en: 'Search product…', es: 'Buscar producto…' },
  prod_import:   { cs: '📥 Import z Excelu', en: '📥 Import from Excel', es: '📥 Importar Excel' },
  prod_export:   { cs: '📤 Export', en: '📤 Export', es: '📤 Exportar' },

  // ─────────────── CUSTOMERS ───────────────
  cust_new:       { cs: '+ Nový odběratel', en: '+ New customer', es: '+ Nuevo cliente' },
  cust_name:      { cs: 'Název firmy',     en: 'Company name',   es: 'Nombre empresa' },
  cust_contact:   { cs: 'Kontaktní osoba', en: 'Contact person', es: 'Persona contacto' },
  cust_email:     { cs: 'E-mail',          en: 'Email',          es: 'Correo' },
  cust_phone:     { cs: 'Telefon',         en: 'Phone',          es: 'Teléfono' },
  cust_address:   { cs: 'Adresa',          en: 'Address',        es: 'Dirección' },
  cust_city:      { cs: 'Město',           en: 'City',           es: 'Ciudad' },
  cust_psc:       { cs: 'PSČ',             en: 'Postal code',    es: 'Código postal' },
  cust_ico:       { cs: 'IČO',             en: 'Company ID',     es: 'CIF/NIF' },
  cust_dic:       { cs: 'DIČ',             en: 'VAT ID',         es: 'NIF IVA' },
  cust_pricelist: { cs: 'Ceník',           en: 'Price list',     es: 'Lista de precios' },
  cust_payment:   { cs: 'Splatnost (dny)', en: 'Payment (days)', es: 'Vencimiento (días)' },
  cust_active:    { cs: 'Aktivní',         en: 'Active',         es: 'Activo' },
  cust_orders:    { cs: 'Objednávky',      en: 'Orders',         es: 'Pedidos' },
  cust_revenue:   { cs: 'Celkové tržby',   en: 'Total revenue',  es: 'Ingresos totales' },

  // ─────────────── INVOICES ───────────────
  inv_new:       { cs: '+ Nová faktura', en: '+ New invoice', es: '+ Nueva factura' },
  inv_number:    { cs: 'Číslo faktury', en: 'Invoice number', es: 'Nº factura' },
  inv_var_sym:   { cs: 'Variabilní symbol', en: 'Variable symbol', es: 'Símbolo variable' },
  inv_issue_date:{ cs: 'Datum vystavení', en: 'Issue date',   es: 'Fecha emisión' },
  inv_due_date:  { cs: 'Splatnost',       en: 'Due date',     es: 'Vencimiento' },
  inv_tax_date:  { cs: 'DUZP',            en: 'Tax date',     es: 'Fecha imponible' },
  inv_total_excl:{ cs: 'Celkem bez DPH',  en: 'Total excl. VAT', es: 'Total sin IVA' },
  inv_total_vat: { cs: 'DPH',             en: 'VAT',          es: 'IVA' },
  inv_total:     { cs: 'Celkem k úhradě', en: 'Total to pay', es: 'Total a pagar' },
  inv_paid:      { cs: 'Zaplaceno',       en: 'Paid',         es: 'Pagado' },
  inv_unpaid:    { cs: 'Neuhrazeno',      en: 'Unpaid',       es: 'Sin pagar' },
  inv_overdue:   { cs: 'Po splatnosti',   en: 'Overdue',      es: 'Vencido' },
  inv_pdf:       { cs: '📄 PDF',          en: '📄 PDF',       es: '📄 PDF' },
  inv_isdoc:     { cs: '📋 ISDOC export', en: '📋 ISDOC export', es: '📋 Exportar ISDOC' },
  inv_send_email:{ cs: '📧 Poslat e-mailem', en: '📧 Send by email', es: '📧 Enviar email' },

  // ─────────────── HACCP ───────────────
  haccp_t:        { cs: '🛡️ HACCP dokumentace', en: '🛡️ HACCP documentation', es: '🛡️ Documentación APPCC' },
  haccp_docs:     { cs: 'Dokumenty',       en: 'Documents',    es: 'Documentos' },
  haccp_records:  { cs: 'Záznamy',         en: 'Records',      es: 'Registros' },
  haccp_audit:    { cs: 'Audit',           en: 'Audit',        es: 'Auditoría' },
  haccp_critical: { cs: 'Kritické body',   en: 'Critical points', es: 'Puntos críticos' },
  haccp_temp:     { cs: 'Teplotní záznam', en: 'Temperature record', es: 'Registro temperatura' },
  haccp_print:    { cs: '🖨️ Tisk',         en: '🖨️ Print',     es: '🖨️ Imprimir' },

  // ─────────────── SETTINGS ───────────────
  set_t:          { cs: 'Nastavení',       en: 'Settings',     es: 'Ajustes' },
  set_firma:      { cs: '🏢 Firma a doklady', en: '🏢 Company & documents', es: '🏢 Empresa y documentos' },
  set_packages:   { cs: '🎁 Balíčky',      en: '🎁 Packages',  es: '🎁 Paquetes' },
  set_smtp:       { cs: '📧 SMTP / E-mail', en: '📧 SMTP / Email', es: '📧 SMTP / Email' },
  set_users:      { cs: '👥 Uživatelé',    en: '👥 Users',     es: '👥 Usuarios' },
  set_dph:        { cs: '💰 Sazby DPH',    en: '💰 VAT rates', es: '💰 Tasas IVA' },
  set_numbering:  { cs: '🔢 Číselné řady', en: '🔢 Numbering', es: '🔢 Numeración' },
  set_haccp:      { cs: '📋 HACCP nastavení', en: '📋 HACCP settings', es: '📋 Ajustes APPCC' },
  set_backup:     { cs: '💾 Zálohy',       en: '💾 Backups',   es: '💾 Copias' },
  set_license:    { cs: '🔑 Licence',      en: '🔑 License',   es: '🔑 Licencia' },
  set_diag:       { cs: '📊 Diagnostika',  en: '📊 Diagnostics', es: '📊 Diagnóstico' },
  set_sync:       { cs: '🔄 Sync',         en: '🔄 Sync',      es: '🔄 Sync' },
  set_save:       { cs: '💾 Uložit', en: '💾 Save', es: '💾 Guardar' },
  set_saved:      { cs: '✅ Uloženo', en: '✅ Saved', es: '✅ Guardado' },

  // ─────────────── MISC UI ───────────────
  modal_save_close: { cs: 'Uložit a zavřít', en: 'Save and close', es: 'Guardar y cerrar' },
  modal_unsaved:    { cs: 'Máte neuložené změny — opravdu zavřít?', en: 'You have unsaved changes — really close?', es: '¿Cambios sin guardar — cerrar de todos modos?' },
  table_no_results: { cs: 'Žádné výsledky',  en: 'No results',     es: 'Sin resultados' },
  table_show_more:  { cs: 'Zobrazit více',   en: 'Show more',      es: 'Mostrar más' },
  empty_state:      { cs: 'Zatím prázdné',   en: 'Empty for now',  es: 'Vacío por ahora' },
  loading_data:     { cs: '⏳ Načítání dat…', en: '⏳ Loading…',   es: '⏳ Cargando…' },
  retry:            { cs: '🔄 Zkusit znovu', en: '🔄 Retry',      es: '🔄 Reintentar' },
  cancel:           { cs: 'Zrušit',          en: 'Cancel',         es: 'Cancelar' },
  delete_confirm:   { cs: 'Opravdu smazat?', en: 'Really delete?', es: '¿Eliminar realmente?' },
  delete_undo:      { cs: 'Vrátit zpět',     en: 'Undo',           es: 'Deshacer' },

  // ─────────────── BOTTOM NAV (mobile) ───────────────
  bn_dashboard: { cs: 'Přehled',     en: 'Overview',  es: 'Resumen' },
  bn_orders:    { cs: 'Objednávky',  en: 'Orders',    es: 'Pedidos' },
  bn_products:  { cs: 'Výrobky',     en: 'Products',  es: 'Productos' },
  bn_customers: { cs: 'Odběratelé',  en: 'Customers', es: 'Clientes' },
  bn_settings:  { cs: 'Nastavení',   en: 'Settings',  es: 'Ajustes' },

  // ─────────────── 🆕 v2.9.x PLACEHOLDER KEYS (5 jazyků) ───────────────
  // ARES / společnost
  ares_loaded_dont_forget_save: {
    cs: '✅ Načteno z {zdroj}.\n\nNezapomeň kliknout "Uložit nastavení".',
    sk: '✅ Načítané z {zdroj}.\n\nNezabudni kliknúť "Uložiť nastavenie".',
    en: '✅ Loaded from {zdroj}.\n\nDon\'t forget to click "Save settings".',
    de: '✅ Geladen von {zdroj}.\n\nVergiss nicht, "Einstellungen speichern" zu klicken.',
    es: '✅ Cargado de {zdroj}.\n\nNo olvides hacer clic en "Guardar configuración".',
  },
  ares_check_ico_8: {
    cs: '❌ {msg}\n\nZkontroluj IČO. CZ má 8 číslic, SK také 8.',
    sk: '❌ {msg}\n\nSkontroluj IČO. CZ má 8 číslic, SK tiež 8.',
    en: '❌ {msg}\n\nCheck the company ID. CZ has 8 digits, SK also 8.',
    de: '❌ {msg}\n\nÜberprüfe die Firmen-ID. CZ hat 8 Ziffern, SK auch 8.',
    es: '❌ {msg}\n\nVerifica el ID empresarial. CZ tiene 8 dígitos, SK también 8.',
  },
  ares_loaded_simple: {
    cs: '✅ Načteno z {zdroj}.',
    sk: '✅ Načítané z {zdroj}.',
    en: '✅ Loaded from {zdroj}.',
    de: '✅ Geladen von {zdroj}.',
    es: '✅ Cargado de {zdroj}.',
  },
  ares_check_ico_8_long: {
    cs: '❌ {msg}\n\nZkontroluj IČO. CZ IČO má 8 číslic, SK IČO většinou také 8.',
    sk: '❌ {msg}\n\nSkontroluj IČO. CZ IČO má 8 číslic, SK IČO väčšinou tiež 8.',
    en: '❌ {msg}\n\nCheck the company ID. CZ has 8 digits, SK usually also 8.',
    de: '❌ {msg}\n\nÜberprüfe die Firmen-ID. CZ hat 8 Ziffern, SK meist auch 8.',
    es: '❌ {msg}\n\nVerifica el ID empresarial. CZ tiene 8 dígitos, SK normalmente también 8.',
  },

  // Demo / import výsledky
  demo_created_cats_products: {
    cs: '✓ Vytvořeno: {cats} kategorií + {prods} výrobků',
    sk: '✓ Vytvorené: {cats} kategórií + {prods} výrobkov',
    en: '✓ Created: {cats} categories + {prods} products',
    de: '✓ Erstellt: {cats} Kategorien + {prods} Produkte',
    es: '✓ Creado: {cats} categorías + {prods} productos',
  },
  changes_saved_n_updated: {
    cs: '✓ Změny uloženy. {co} aktualizovány — vytiskněte nové.',
    sk: '✓ Zmeny uložené. {co} aktualizované — vytlačte nové.',
    en: '✓ Changes saved. {co} updated — print new ones.',
    de: '✓ Änderungen gespeichert. {co} aktualisiert — neue drucken.',
    es: '✓ Cambios guardados. {co} actualizados — imprimir nuevos.',
  },
  imported_lines_skipped: {
    cs: '📥 Načteno {n} položek. ({skipped} volných řádků bez výrobku přeskočeno.)',
    sk: '📥 Načítaných {n} položiek. ({skipped} prázdnych riadkov bez výrobku preskočených.)',
    en: '📥 Loaded {n} items. ({skipped} empty rows without product skipped.)',
    de: '📥 {n} Artikel geladen. ({skipped} leere Zeilen ohne Produkt übersprungen.)',
    es: '📥 Cargados {n} artículos. ({skipped} filas vacías sin producto omitidas.)',
  },

  // Form row errors
  row_missing_name: {
    cs: 'Řádek {n}: chybí název položky',
    sk: 'Riadok {n}: chýba názov položky',
    en: 'Row {n}: missing item name',
    de: 'Zeile {n}: Artikelname fehlt',
    es: 'Fila {n}: falta el nombre del artículo',
  },
  row_missing_name_short: {
    cs: 'Řádek {n}: chybí název',
    sk: 'Riadok {n}: chýba názov',
    en: 'Row {n}: missing name',
    de: 'Zeile {n}: Name fehlt',
    es: 'Fila {n}: falta el nombre',
  },
  row_negative_price: {
    cs: 'Řádek {n}: záporná cena',
    sk: 'Riadok {n}: záporná cena',
    en: 'Row {n}: negative price',
    de: 'Zeile {n}: negativer Preis',
    es: 'Fila {n}: precio negativo',
  },
  row_invalid_qty: {
    cs: 'Řádek {n}: neplatné množství',
    sk: 'Riadok {n}: neplatné množstvo',
    en: 'Row {n}: invalid quantity',
    de: 'Zeile {n}: ungültige Menge',
    es: 'Fila {n}: cantidad inválida',
  },

  // Objednávka / faktura výsledky
  order_created: {
    cs: 'Objednávka {cislo} byla vytvořena.',
    sk: 'Objednávka {cislo} bola vytvorená.',
    en: 'Order {cislo} was created.',
    de: 'Bestellung {cislo} wurde erstellt.',
    es: 'Pedido {cislo} fue creado.',
  },
  order_created_simple: {
    cs: 'Vytvořena objednávka {cislo}.',
    sk: 'Vytvorená objednávka {cislo}.',
    en: 'Order {cislo} created.',
    de: 'Bestellung {cislo} erstellt.',
    es: 'Pedido {cislo} creado.',
  },
  invoice_issued_simple: {
    cs: 'Faktura {cislo} byla vystavena.',
    sk: 'Faktúra {cislo} bola vystavená.',
    en: 'Invoice {cislo} was issued.',
    de: 'Rechnung {cislo} wurde ausgestellt.',
    es: 'Factura {cislo} fue emitida.',
  },
  invoice_issued_with_dl: {
    cs: '✓ Faktura {cislo} vystavena.\n{pocet} DL navázáno · {castka}',
    sk: '✓ Faktúra {cislo} vystavená.\n{pocet} DL prepojených · {castka}',
    en: '✓ Invoice {cislo} issued.\n{pocet} delivery notes linked · {castka}',
    de: '✓ Rechnung {cislo} ausgestellt.\n{pocet} Lieferscheine verknüpft · {castka}',
    es: '✓ Factura {cislo} emitida.\n{pocet} albaranes vinculados · {castka}',
  },
  warehouse_moved: {
    cs: '✓ Přesunuto {mn} jednotek.\n\nZdroj: {z}\nCíl: {do}',
    sk: '✓ Presunuté {mn} jednotiek.\n\nZdroj: {z}\nCieľ: {do}',
    en: '✓ Moved {mn} units.\n\nFrom: {z}\nTo: {do}',
    de: '✓ {mn} Einheiten verschoben.\n\nVon: {z}\nNach: {do}',
    es: '✓ Movidas {mn} unidades.\n\nDe: {z}\nA: {do}',
  },
  warehouse_writeoff: {
    cs: '✅ Odepsáno {n} surovin ze skladu.',
    sk: '✅ Odpísané {n} surovín zo skladu.',
    en: '✅ Wrote off {n} ingredients from warehouse.',
    de: '✅ {n} Zutaten aus dem Lager abgeschrieben.',
    es: '✅ Dadas de baja {n} ingredientes del almacén.',
  },
  bulk_done_stats: {
    cs: '✅ Hotovo.\n\nVytvořeno: {ok}\nPřeskočeno: {skip}\nChyby: {err}',
    sk: '✅ Hotovo.\n\nVytvorené: {ok}\nPreskočené: {skip}\nChyby: {err}',
    en: '✅ Done.\n\nCreated: {ok}\nSkipped: {skip}\nErrors: {err}',
    de: '✅ Fertig.\n\nErstellt: {ok}\nÜbersprungen: {skip}\nFehler: {err}',
    es: '✅ Listo.\n\nCreados: {ok}\nOmitidos: {skip}\nErrores: {err}',
  },
  dl_same_customer_required: {
    cs: 'Vybrané dodací listy musí být od stejného odběratele.\n\nVybráno odběratelů: {n}\n{nazvy}',
    sk: 'Vybrané dodacie listy musia byť od rovnakého odberateľa.\n\nVybraných odberateľov: {n}\n{nazvy}',
    en: 'Selected delivery notes must be from the same customer.\n\nCustomers selected: {n}\n{nazvy}',
    de: 'Ausgewählte Lieferscheine müssen vom gleichen Kunden sein.\n\nAusgewählte Kunden: {n}\n{nazvy}',
    es: 'Los albaranes seleccionados deben ser del mismo cliente.\n\nClientes seleccionados: {n}\n{nazvy}',
  },
  dl_already_invoiced: {
    cs: 'Tyto DL už jsou fakturované: {seznam}\n\nOdškrtni je z výběru.',
    sk: 'Tieto DL sú už vyfaktúrované: {seznam}\n\nOdškrtni ich z výberu.',
    en: 'These delivery notes are already invoiced: {seznam}\n\nUncheck them from the selection.',
    de: 'Diese Lieferscheine sind bereits fakturiert: {seznam}\n\nAbwählen.',
    es: 'Estos albaranes ya están facturados: {seznam}\n\nDesmárcalos de la selección.',
  },
  isdoc_sent: {
    cs: '✓ Odesláno!\n\n• Faktur: {n}\n• Velikost: {kb} kB\n• Příjemce: {email}',
    sk: '✓ Odoslané!\n\n• Faktúr: {n}\n• Veľkosť: {kb} kB\n• Príjemca: {email}',
    en: '✓ Sent!\n\n• Invoices: {n}\n• Size: {kb} kB\n• Recipient: {email}',
    de: '✓ Gesendet!\n\n• Rechnungen: {n}\n• Größe: {kb} kB\n• Empfänger: {email}',
    es: '✓ Enviado!\n\n• Facturas: {n}\n• Tamaño: {kb} kB\n• Destinatario: {email}',
  },
  license_updated: {
    cs: '✅ Klíč aktualizován.\n\nOdemčené balíčky: {pkgs}\n\nStránka se obnoví aby se promítly změny.',
    sk: '✅ Kľúč aktualizovaný.\n\nOdomknuté balíčky: {pkgs}\n\nStránka sa obnoví, aby sa zmeny prejavili.',
    en: '✅ Key updated.\n\nUnlocked packages: {pkgs}\n\nThe page will reload to apply changes.',
    de: '✅ Schlüssel aktualisiert.\n\nFreigeschaltete Pakete: {pkgs}\n\nDie Seite wird neu geladen.',
    es: '✅ Clave actualizada.\n\nPaquetes desbloqueados: {pkgs}\n\nLa página se recargará para aplicar los cambios.',
  },
  demo_seed_done: {
    cs: '✅ Hotovo!\n\n• {cat} kategorií\n• {sur} surovin (s nutri)\n• {newP} nových výrobků (+{updP} aktualizováno)\n• {recipes} řádků receptů\n\nMrkni do Výrobky → uvidíš novinky.',
    sk: '✅ Hotovo!\n\n• {cat} kategórií\n• {sur} surovín (s nutri)\n• {newP} nových výrobkov (+{updP} aktualizovaných)\n• {recipes} riadkov receptov\n\nPozri do Výrobky → uvidíš novinky.',
    en: '✅ Done!\n\n• {cat} categories\n• {sur} ingredients (with nutrition)\n• {newP} new products (+{updP} updated)\n• {recipes} recipe rows\n\nCheck Products → you will see news.',
    de: '✅ Fertig!\n\n• {cat} Kategorien\n• {sur} Zutaten (mit Nährwerten)\n• {newP} neue Produkte (+{updP} aktualisiert)\n• {recipes} Rezeptzeilen\n\nSchau in Produkte → du siehst Neuigkeiten.',
    es: '✅ Listo!\n\n• {cat} categorías\n• {sur} ingredientes (con nutrición)\n• {newP} productos nuevos (+{updP} actualizados)\n• {recipes} filas de recetas\n\nMira en Productos → verás las novedades.',
  },
  sync_done_stats: {
    cs: '✅ Sync hotový!\n\nPush: {pushed} záznamů\nPull: {pulled} záznamů\nČas: {ms}ms',
    sk: '✅ Sync hotový!\n\nPush: {pushed} záznamov\nPull: {pulled} záznamov\nČas: {ms}ms',
    en: '✅ Sync complete!\n\nPush: {pushed} records\nPull: {pulled} records\nTime: {ms}ms',
    de: '✅ Sync abgeschlossen!\n\nPush: {pushed} Datensätze\nPull: {pulled} Datensätze\nZeit: {ms}ms',
    es: '✅ ¡Sync completo!\n\nPush: {pushed} registros\nPull: {pulled} registros\nTiempo: {ms}ms',
  },
  cloud_connection_ok: {
    cs: '✅ Připojení OK!\n\nCloud odpověděl HTTP {status}.',
    sk: '✅ Pripojenie OK!\n\nCloud odpovedal HTTP {status}.',
    en: '✅ Connection OK!\n\nCloud responded with HTTP {status}.',
    de: '✅ Verbindung OK!\n\nCloud antwortete mit HTTP {status}.',
    es: '✅ ¡Conexión OK!\n\nLa nube respondió con HTTP {status}.',
  },
  cloud_responded_with_error: {
    cs: '🟡 Cloud odpověděl, ale s chybou:\n\n{json}',
    sk: '🟡 Cloud odpovedal, ale s chybou:\n\n{json}',
    en: '🟡 Cloud responded with an error:\n\n{json}',
    de: '🟡 Cloud antwortete mit einem Fehler:\n\n{json}',
    es: '🟡 La nube respondió con un error:\n\n{json}',
  },
  restore_success: {
    cs: '✓ Obnova proběhla úspěšně!\n\nProvedeno SQL příkazů: {n}\nChyb: {err}\n\nStránka se nyní obnoví.',
    sk: '✓ Obnova prebehla úspešne!\n\nVykonaných SQL príkazov: {n}\nChýb: {err}\n\nStránka sa teraz obnoví.',
    en: '✓ Restore successful!\n\nSQL commands executed: {n}\nErrors: {err}\n\nThe page will reload now.',
    de: '✓ Wiederherstellung erfolgreich!\n\nAusgeführte SQL-Befehle: {n}\nFehler: {err}\n\nDie Seite wird neu geladen.',
    es: '✓ ¡Restauración exitosa!\n\nComandos SQL ejecutados: {n}\nErrores: {err}\n\nLa página se recargará.',
  },
  restore_with_errors: {
    cs: '⚠ Obnova proběhla s chybami:\n\nÚspěšně: {ok}\nChyb: {err}\n\n{errors}\n\nDoporučuju zkontrolovat stav DB.',
    sk: '⚠ Obnova prebehla s chybami:\n\nÚspešne: {ok}\nChýb: {err}\n\n{errors}\n\nOdporúčam skontrolovať stav DB.',
    en: '⚠ Restore completed with errors:\n\nSuccessful: {ok}\nErrors: {err}\n\n{errors}\n\nWe recommend checking the DB state.',
    de: '⚠ Wiederherstellung mit Fehlern abgeschlossen:\n\nErfolgreich: {ok}\nFehler: {err}\n\n{errors}\n\nDB-Status prüfen empfohlen.',
    es: '⚠ Restauración completada con errores:\n\nExitosos: {ok}\nErrores: {err}\n\n{errors}\n\nRecomendamos comprobar el estado de la BD.',
  },
  test_email_sent: {
    cs: '✅ Test odeslán.\n\nOdesláno: {sent}\nSelhalo: {failed}\nExpirováno: {expired}',
    sk: '✅ Test odoslaný.\n\nOdoslané: {sent}\nZlyhalo: {failed}\nExpirované: {expired}',
    en: '✅ Test sent.\n\nSent: {sent}\nFailed: {failed}\nExpired: {expired}',
    de: '✅ Test gesendet.\n\nGesendet: {sent}\nFehlgeschlagen: {failed}\nAbgelaufen: {expired}',
    es: '✅ Test enviado.\n\nEnviado: {sent}\nFallaron: {failed}\nExpirados: {expired}',
  },
  bulk_test_sent: {
    cs: '✅ Hromadný test odeslán.\n\nOdesláno: {sent}\nSelhalo: {failed}\nExpirováno (smazáno): {expired}',
    sk: '✅ Hromadný test odoslaný.\n\nOdoslané: {sent}\nZlyhalo: {failed}\nExpirované (zmazané): {expired}',
    en: '✅ Bulk test sent.\n\nSent: {sent}\nFailed: {failed}\nExpired (deleted): {expired}',
    de: '✅ Massentest gesendet.\n\nGesendet: {sent}\nFehlgeschlagen: {failed}\nAbgelaufen (gelöscht): {expired}',
    es: '✅ Test masivo enviado.\n\nEnviado: {sent}\nFallaron: {failed}\nExpirados (eliminados): {expired}',
  },
  vat_rate_in_use: {
    cs: 'Sazbu nelze smazat — používá ji {n} výrobků.\n\nNejprve výrobky převeďte na jinou sazbu.',
    sk: 'Sadzbu nemožno zmazať — používa ju {n} výrobkov.\n\nNajskôr výrobky preveďte na inú sadzbu.',
    en: 'Cannot delete VAT rate — used by {n} products.\n\nFirst, switch the products to another rate.',
    de: 'MwSt.-Satz kann nicht gelöscht werden — wird von {n} Produkten verwendet.\n\nProdukte zuerst auf einen anderen Satz umstellen.',
    es: 'No se puede eliminar el IVA — lo usan {n} productos.\n\nPrimero cambia los productos a otra tasa.',
  },
  csv_import_done: {
    cs: '✓ CSV import dokončen\n\nVytvořeno: {created}\nAktualizováno: {updated}\nCelkem: {total}{errors}',
    sk: '✓ CSV import dokončený\n\nVytvorené: {created}\nAktualizované: {updated}\nCelkom: {total}{errors}',
    en: '✓ CSV import complete\n\nCreated: {created}\nUpdated: {updated}\nTotal: {total}{errors}',
    de: '✓ CSV-Import abgeschlossen\n\nErstellt: {created}\nAktualisiert: {updated}\nGesamt: {total}{errors}',
    es: '✓ Importación CSV completada\n\nCreados: {created}\nActualizados: {updated}\nTotal: {total}{errors}',
  },
  import_done: {
    cs: '✓ Import dokončen\n\nVytvořeno: {created}\nAktualizováno: {updated}\nCelkem: {total}{errors}',
    sk: '✓ Import dokončený\n\nVytvorené: {created}\nAktualizované: {updated}\nCelkom: {total}{errors}',
    en: '✓ Import complete\n\nCreated: {created}\nUpdated: {updated}\nTotal: {total}{errors}',
    de: '✓ Import abgeschlossen\n\nErstellt: {created}\nAktualisiert: {updated}\nGesamt: {total}{errors}',
    es: '✓ Importación completada\n\nCreados: {created}\nActualizados: {updated}\nTotal: {total}{errors}',
  },
  ingredient_in_use_deactivated: {
    cs: 'Surovina je v {n} výrobcích — byla deaktivována místo smazání.',
    sk: 'Surovina je v {n} výrobkoch — bola deaktivovaná namiesto zmazania.',
    en: 'Ingredient is in {n} products — was deactivated instead of deleted.',
    de: 'Zutat ist in {n} Produkten — wurde deaktiviert statt gelöscht.',
    es: 'El ingrediente está en {n} productos — fue desactivado en lugar de eliminado.',
  },
  bulk_done_summary: {
    cs: '📦 Hotovo.\n\n• Vytvořeno: {ok}\n• Chyb: {err}{detail}',
    sk: '📦 Hotovo.\n\n• Vytvorené: {ok}\n• Chýb: {err}{detail}',
    en: '📦 Done.\n\n• Created: {ok}\n• Errors: {err}{detail}',
    de: '📦 Fertig.\n\n• Erstellt: {ok}\n• Fehler: {err}{detail}',
    es: '📦 Listo.\n\n• Creados: {ok}\n• Errores: {err}{detail}',
  },
  templates_created: {
    cs: '✓ Vytvořeno {ok} šablon{chyby_text}.\n\nNajdeš je v dropdownu „Načíst uloženou šablonu".',
    sk: '✓ Vytvorené {ok} šablón{chyby_text}.\n\nNájdeš ich v dropdowne „Načítať uloženú šablónu".',
    en: '✓ Created {ok} templates{chyby_text}.\n\nFind them in the "Load saved template" dropdown.',
    de: '✓ {ok} Vorlagen erstellt{chyby_text}.\n\nIn der "Gespeicherte Vorlage laden"-Dropdown zu finden.',
    es: '✓ Creadas {ok} plantillas{chyby_text}.\n\nLas encontrarás en el menú "Cargar plantilla guardada".',
  },
  invalid_multiplier: {
    cs: 'Neplatný násobitel ({mult}). Zkontroluj cílové hodnoty.',
    sk: 'Neplatný násobiteľ ({mult}). Skontroluj cieľové hodnoty.',
    en: 'Invalid multiplier ({mult}). Check the target values.',
    de: 'Ungültiger Multiplikator ({mult}). Zielwerte überprüfen.',
    es: 'Multiplicador inválido ({mult}). Verifica los valores objetivo.',
  },

  // ─────────────── CONFIRMS s placeholders ───────────────
  confirm_delete_notif_kind: {
    cs: 'Smazat všechny notifikace typu "{kind}" ({n}×)?',
    sk: 'Zmazať všetky notifikácie typu "{kind}" ({n}×)?',
    en: 'Delete all notifications of type "{kind}" ({n}×)?',
    de: 'Alle Benachrichtigungen vom Typ "{kind}" ({n}×) löschen?',
    es: '¿Eliminar todas las notificaciones del tipo "{kind}" ({n}×)?',
  },
  confirm_delete_co: {
    cs: 'Opravdu smazat {co}?{detail}',
    sk: 'Naozaj zmazať {co}?{detail}',
    en: 'Really delete {co}?{detail}',
    de: '{co} wirklich löschen?{detail}',
    es: '¿Eliminar realmente {co}?{detail}',
  },
  confirm_delete_final_step: {
    cs: '⚠️ POSLEDNÍ POTVRZENÍ\n\nOpravdu nevratně smazat {co}?\n\nKlikněte OK pouze pokud si jste 100% jistí. Tato akce nelze vrátit zpět.\n\n(Druhý krok můžete vypnout v Nastavení.)',
    sk: '⚠️ POSLEDNÉ POTVRDENIE\n\nNaozaj nenávratne zmazať {co}?\n\nKliknite OK len ak ste si na 100% istí. Túto akciu nemožno vrátiť späť.\n\n(Druhý krok môžete vypnúť v Nastaveniach.)',
    en: '⚠️ FINAL CONFIRMATION\n\nReally permanently delete {co}?\n\nClick OK only if you are 100% sure. This action cannot be undone.\n\n(You can disable the second step in Settings.)',
    de: '⚠️ LETZTE BESTÄTIGUNG\n\nWirklich {co} unwiderruflich löschen?\n\nNur OK klicken, wenn Sie 100% sicher sind. Diese Aktion ist nicht rückgängig zu machen.\n\n(Sie können den zweiten Schritt in den Einstellungen deaktivieren.)',
    es: '⚠️ CONFIRMACIÓN FINAL\n\n¿Eliminar permanentemente {co}?\n\nHaga clic en OK solo si está 100% seguro. Esta acción no se puede deshacer.\n\n(Puede desactivar el segundo paso en Ajustes.)',
  },
  confirm_bulk_action_orders: {
    cs: 'Opravdu hromadně {akce} pro {n} {label}?',
    sk: 'Naozaj hromadne {akce} pre {n} {label}?',
    en: 'Really perform bulk {akce} for {n} {label}?',
    de: 'Wirklich Massenaktion {akce} für {n} {label} durchführen?',
    es: '¿Realmente realizar {akce} masivamente para {n} {label}?',
  },
  confirm_delete_warehouse: {
    cs: 'Smazat sklad "{nazev}"? (Pokud má položky / pohyby, bude jen deaktivován.)',
    sk: 'Zmazať sklad "{nazev}"? (Ak má položky / pohyby, bude len deaktivovaný.)',
    en: 'Delete warehouse "{nazev}"? (If it has items / movements, it will only be deactivated.)',
    de: 'Lager "{nazev}" löschen? (Wenn es Artikel / Bewegungen hat, wird es nur deaktiviert.)',
    es: '¿Eliminar el almacén "{nazev}"? (Si tiene artículos/movimientos, solo se desactivará.)',
  },
  confirm_inventory_action: {
    cs: 'Provést inventuru pro {n} {label}? Vytvoří se inventurní pohyby s aktuálním datem.',
    sk: 'Vykonať inventúru pre {n} {label}? Vytvoria sa inventúrne pohyby s aktuálnym dátumom.',
    en: 'Perform inventory for {n} {label}? Inventory movements with the current date will be created.',
    de: 'Inventur für {n} {label} durchführen? Inventurbewegungen mit aktuellem Datum werden erstellt.',
    es: '¿Realizar inventario para {n} {label}? Se crearán movimientos de inventario con la fecha actual.',
  },
  confirm_remove_from_warehouse: {
    cs: 'Odebrat "{nazev}" ze skladu? (Pouze pokud stav = 0.)',
    sk: 'Odobrať "{nazev}" zo skladu? (Iba ak stav = 0.)',
    en: 'Remove "{nazev}" from warehouse? (Only if stock = 0.)',
    de: '"{nazev}" aus dem Lager entfernen? (Nur wenn Bestand = 0.)',
    es: '¿Eliminar "{nazev}" del almacén? (Solo si el stock = 0.)',
  },
  confirm_print_all_dl_many: {
    cs: 'Nic není vybráno. Vytisknout všech {n} zobrazených DL? (To je hodně!)',
    sk: 'Nič nie je vybrané. Vytlačiť všetkých {n} zobrazených DL? (To je veľa!)',
    en: 'Nothing selected. Print all {n} displayed delivery notes? (That\'s a lot!)',
    de: 'Nichts ausgewählt. Alle {n} angezeigten Lieferscheine drucken? (Das ist viel!)',
    es: 'Nada seleccionado. ¿Imprimir todos los {n} albaranes mostrados? (¡Es mucho!)',
  },
  confirm_print_all_dl: {
    cs: 'Nic není vybráno. Vytisknout všech {n} zobrazených DL?',
    sk: 'Nič nie je vybrané. Vytlačiť všetkých {n} zobrazených DL?',
    en: 'Nothing selected. Print all {n} displayed delivery notes?',
    de: 'Nichts ausgewählt. Alle {n} angezeigten Lieferscheine drucken?',
    es: 'Nada seleccionado. ¿Imprimir todos los {n} albaranes mostrados?',
  },
  confirm_print_all_invoices_many: {
    cs: 'Nic není vybráno. Vytisknout všech {n} zobrazených faktur? (To je hodně!)',
    sk: 'Nič nie je vybrané. Vytlačiť všetkých {n} zobrazených faktúr? (To je veľa!)',
    en: 'Nothing selected. Print all {n} displayed invoices? (That\'s a lot!)',
    de: 'Nichts ausgewählt. Alle {n} angezeigten Rechnungen drucken? (Das ist viel!)',
    es: 'Nada seleccionado. ¿Imprimir todas las {n} facturas mostradas? (¡Es mucho!)',
  },
  confirm_print_all_invoices: {
    cs: 'Nic není vybráno. Vytisknout všech {n} zobrazených faktur?',
    sk: 'Nič nie je vybrané. Vytlačiť všetkých {n} zobrazených faktúr?',
    en: 'Nothing selected. Print all {n} displayed invoices?',
    de: 'Nichts ausgewählt. Alle {n} angezeigten Rechnungen drucken?',
    es: 'Nada seleccionado. ¿Imprimir todas las {n} facturas mostradas?',
  },
  confirm_send_isdoc_zip: {
    cs: 'Poslat ZIP s ISDOC fakturami na {email}?',
    sk: 'Poslať ZIP s ISDOC faktúrami na {email}?',
    en: 'Send ZIP with ISDOC invoices to {email}?',
    de: 'ZIP mit ISDOC-Rechnungen an {email} senden?',
    es: '¿Enviar ZIP con facturas ISDOC a {email}?',
  },
  confirm_download_isdoc_zip: {
    cs: 'Stáhnout ZIP s {n} fakturami v ISDOC formátu?',
    sk: 'Stiahnuť ZIP s {n} faktúrami v ISDOC formáte?',
    en: 'Download ZIP with {n} invoices in ISDOC format?',
    de: 'ZIP mit {n} Rechnungen im ISDOC-Format herunterladen?',
    es: '¿Descargar ZIP con {n} facturas en formato ISDOC?',
  },
  confirm_overwrite_allergens: {
    cs: 'Detekované alergeny ze surovin (vč. složení):\n{list}\n\nPřepsat aktuální „{old}"?',
    sk: 'Detegované alergény zo surovín (vč. zloženia):\n{list}\n\nPrepísať aktuálne „{old}"?',
    en: 'Detected allergens from ingredients (incl. composition):\n{list}\n\nOverwrite current "{old}"?',
    de: 'Erkannte Allergene aus Zutaten (inkl. Zusammensetzung):\n{list}\n\nAktuelle "{old}" überschreiben?',
    es: 'Alérgenos detectados de los ingredientes (incl. composición):\n{list}\n\n¿Sobrescribir el actual "{old}"?',
  },
  confirm_delete_printer: {
    cs: 'Smazat tiskárnu "{nazev}"?\n\nKategorie přiřazené této tiskárně se nastaví na "bez tisku".',
    sk: 'Zmazať tlačiareň "{nazev}"?\n\nKategórie priradené tejto tlačiarni sa nastavia na "bez tlače".',
    en: 'Delete printer "{nazev}"?\n\nCategories assigned to this printer will be set to "no print".',
    de: 'Drucker "{nazev}" löschen?\n\nKategorien dieses Druckers werden auf "kein Druck" gesetzt.',
    es: '¿Eliminar la impresora "{nazev}"?\n\nLas categorías asignadas se establecerán en "sin impresión".',
  },
  confirm_reject_items: {
    cs: 'Odmítnout {n} položek?',
    sk: 'Odmietnuť {n} položiek?',
    en: 'Reject {n} items?',
    de: '{n} Artikel ablehnen?',
    es: '¿Rechazar {n} artículos?',
  },
  confirm_sum_mismatch: {
    cs: 'Součet ({sum}) ≠ celkem ({total}). Pokračovat?',
    sk: 'Súčet ({sum}) ≠ celkom ({total}). Pokračovať?',
    en: 'Sum ({sum}) ≠ total ({total}). Continue?',
    de: 'Summe ({sum}) ≠ Gesamtsumme ({total}). Fortfahren?',
    es: 'Suma ({sum}) ≠ total ({total}). ¿Continuar?',
  },
  confirm_import_template_destructive: {
    cs: 'Naimportovat šablonu "{nazev}"?\n\nVŠECHNY stávající stoly a zóny budou SMAZÁNY.',
    sk: 'Naimportovať šablónu "{nazev}"?\n\nVŠETKY existujúce stoly a zóny budú ZMAZANÉ.',
    en: 'Import template "{nazev}"?\n\nALL existing tables and zones will be DELETED.',
    de: 'Vorlage "{nazev}" importieren?\n\nALLE bestehenden Tische und Zonen werden GELÖSCHT.',
    es: '¿Importar plantilla "{nazev}"?\n\nTODAS las mesas y zonas existentes serán ELIMINADAS.',
  },
  confirm_remove_from_group: {
    cs: 'Odebrat „{nazev}" ze skupiny?\n\n(Odběratel zůstane v systému, jen přijde o slevy této skupiny.)',
    sk: 'Odobrať „{nazev}" zo skupiny?\n\n(Odberateľ zostane v systéme, len príde o zľavy tejto skupiny.)',
    en: 'Remove "{nazev}" from group?\n\n(The customer stays in the system, only loses this group\'s discounts.)',
    de: '"{nazev}" aus der Gruppe entfernen?\n\n(Der Kunde bleibt im System, verliert nur die Rabatte dieser Gruppe.)',
    es: '¿Eliminar "{nazev}" del grupo?\n\n(El cliente permanece en el sistema, solo pierde los descuentos de este grupo.)',
  },
  confirm_delete_category: {
    cs: 'Smazat kategorii „{label}"?\n\nSuroviny v ní budou přesunuté do "Ostatní".',
    sk: 'Zmazať kategóriu „{label}"?\n\nSuroviny v nej budú presunuté do "Ostatné".',
    en: 'Delete category "{label}"?\n\nIngredients will be moved to "Other".',
    de: 'Kategorie "{label}" löschen?\n\nZutaten werden in "Sonstige" verschoben.',
    es: '¿Eliminar la categoría "{label}"?\n\nLos ingredientes se moverán a "Otros".',
  },
  confirm_detected_overwrite_allergens: {
    cs: 'Detekováno: {found}\n\nChceš tyto alergeny přepsat do pole „Alergen"? (Aktuálně: „{current}")',
    sk: 'Detegované: {found}\n\nChceš tieto alergény prepísať do poľa „Alergén"? (Aktuálne: „{current}")',
    en: 'Detected: {found}\n\nDo you want to overwrite the "Allergen" field? (Currently: "{current}")',
    de: 'Erkannt: {found}\n\nMöchten Sie diese Allergene im "Allergen"-Feld überschreiben? (Aktuell: "{current}")',
    es: 'Detectado: {found}\n\n¿Desea sobrescribir el campo "Alérgeno"? (Actualmente: "{current}")',
  },
  confirm_demo_recipe: {
    cs: 'Doplnit demo recept pro výrobek {cislo}?\n\n⚠️ Pokud výrobek už nějaký recept má, bude PŘEPSÁN.',
    sk: 'Doplniť demo recept pre výrobok {cislo}?\n\n⚠️ Ak výrobok už nejaký recept má, bude PREPÍSANÝ.',
    en: 'Add demo recipe for product {cislo}?\n\n⚠️ If the product already has a recipe, it will be OVERWRITTEN.',
    de: 'Demo-Rezept für Produkt {cislo} hinzufügen?\n\n⚠️ Falls das Produkt bereits ein Rezept hat, wird es ÜBERSCHRIEBEN.',
    es: '¿Añadir receta demo para el producto {cislo}?\n\n⚠️ Si el producto ya tiene una receta, será SOBRESCRITA.',
  },
  confirm_recalc_recipe: {
    cs: 'Přepočítat recepturu z {from} kg na {to} kg těsta?\n\nNásobek: {mult}×\n\nPřenásobí všechna množství surovin (vč. ks — pokud máš v receptuře vejce, vynásobí se).',
    sk: 'Prepočítať recept z {from} kg na {to} kg cesta?\n\nNásobok: {mult}×\n\nPrenásobí všetky množstvá surovín (vč. ks — ak máš v receptúre vajcia, vynásobia sa).',
    en: 'Recalculate recipe from {from} kg to {to} kg of dough?\n\nMultiplier: {mult}×\n\nWill multiply all ingredient amounts (incl. pcs — if you have eggs, they will be multiplied).',
    de: 'Rezept von {from} kg auf {to} kg Teig umrechnen?\n\nMultiplikator: {mult}×\n\nAlle Zutatenmengen werden multipliziert (inkl. Stück — wenn Eier im Rezept sind, werden sie multipliziert).',
    es: '¿Recalcular la receta de {from} kg a {to} kg de masa?\n\nMultiplicador: {mult}×\n\nMultiplicará todas las cantidades de ingredientes (incl. pzs — si hay huevos, se multiplicarán).',
  },
  confirm_import_cards: {
    cs: 'Importovat {n} vizitek?{typ_text}',
    sk: 'Importovať {n} vizitiek?{typ_text}',
    en: 'Import {n} business cards?{typ_text}',
    de: '{n} Visitenkarten importieren?{typ_text}',
    es: '¿Importar {n} tarjetas de visita?{typ_text}',
  },
  confirm_clear_selected_products: {
    cs: 'Vymazat všechny vybrané výrobky ({n}×)?',
    sk: 'Vymazať všetky vybrané výrobky ({n}×)?',
    en: 'Clear all selected products ({n}×)?',
    de: 'Alle ausgewählten Produkte ({n}×) löschen?',
    es: '¿Eliminar todos los productos seleccionados ({n}×)?',
  },
  confirm_create_template_presets: {
    cs: 'Vytvořit {n} přednastavených šablon?\n\nZahrnuje:\n• Velké cenovky (A4 plakát, A6, půl A4)\n• Klasické cenovky (Avery 8/21/24/33, Printky 6/10/42/44/65, SEVT 10/14/18/52)\n• Appek fold (sklad od půlky)\n• Styly: hero, minimalist, vintage, eco, akce, kompakt — viz názvy ★',
    sk: 'Vytvoriť {n} prednastavených šablón?\n\nZahŕňa:\n• Veľké cenovky (A4 plagát, A6, polovičná A4)\n• Klasické cenovky (Avery 8/21/24/33, Printky 6/10/42/44/65, SEVT 10/14/18/52)\n• Appek fold (sklad od polovice)\n• Štýly: hero, minimalist, vintage, eco, akcia, kompakt — viď názvy ★',
    en: 'Create {n} preset templates?\n\nIncludes:\n• Large price tags (A4 poster, A6, half A4)\n• Classic price tags (Avery 8/21/24/33, Printky 6/10/42/44/65, SEVT 10/14/18/52)\n• Appek fold (folded in half)\n• Styles: hero, minimalist, vintage, eco, sale, compact — see names ★',
    de: '{n} voreingestellte Vorlagen erstellen?\n\nEnthält:\n• Große Preisschilder (A4-Poster, A6, halbes A4)\n• Klassische Preisschilder (Avery 8/21/24/33, Printky 6/10/42/44/65, SEVT 10/14/18/52)\n• Appek-Falz (auf Hälfte gefaltet)\n• Stile: Hero, Minimalist, Vintage, Eco, Aktion, Kompakt — siehe Namen ★',
    es: '¿Crear {n} plantillas predefinidas?\n\nIncluye:\n• Etiquetas grandes (cartel A4, A6, medio A4)\n• Etiquetas clásicas (Avery 8/21/24/33, Printky 6/10/42/44/65, SEVT 10/14/18/52)\n• Appek fold (doblado por la mitad)\n• Estilos: hero, minimalista, vintage, eco, oferta, compacto — ver nombres ★',
  },
  confirm_add_more_docs: {
    cs: 'Už existuje {n} dokumentů. Přidat dalších (mohou být duplikáty)?',
    sk: 'Už existuje {n} dokumentov. Pridať ďalšie (môžu byť duplikáty)?',
    en: '{n} documents already exist. Add more (may be duplicates)?',
    de: 'Es existieren bereits {n} Dokumente. Weitere hinzufügen (können Duplikate sein)?',
    es: 'Ya existen {n} documentos. ¿Añadir más (pueden ser duplicados)?',
  },
  confirm_add_more_templates: {
    cs: 'Už existuje {n} šablon. Přidat dalších 5 (mohou být duplikáty)?',
    sk: 'Už existuje {n} šablón. Pridať ďalších 5 (môžu byť duplikáty)?',
    en: '{n} templates already exist. Add 5 more (may be duplicates)?',
    de: 'Es existieren bereits {n} Vorlagen. 5 weitere hinzufügen (können Duplikate sein)?',
    es: 'Ya existen {n} plantillas. ¿Añadir 5 más (pueden ser duplicados)?',
  },
  confirm_overwrite_recipe: {
    cs: 'Přepsat aktuální recepturu na {label} (násobek {mult}×)?\n\nOriginální hodnoty se ztratí. Doporučujeme nejprve uložit snímek (📂 Historie).',
    sk: 'Prepísať aktuálny recept na {label} (násobok {mult}×)?\n\nOriginálne hodnoty sa stratia. Odporúčame najskôr uložiť snímok (📂 História).',
    en: 'Overwrite current recipe to {label} (multiplier {mult}×)?\n\nOriginal values will be lost. We recommend saving a snapshot first (📂 History).',
    de: 'Aktuelles Rezept auf {label} (Multiplikator {mult}×) überschreiben?\n\nOriginalwerte gehen verloren. Wir empfehlen, zuerst einen Snapshot (📂 Verlauf) zu speichern.',
    es: '¿Sobrescribir la receta actual a {label} (multiplicador {mult}×)?\n\nLos valores originales se perderán. Recomendamos guardar primero una instantánea (📂 Historial).',
  },
  confirm_send_finished: {
    cs: '✓ Odesláno: {ok}{chyby_text}',
    sk: '✓ Odoslané: {ok}{chyby_text}',
    en: '✓ Sent: {ok}{chyby_text}',
    de: '✓ Gesendet: {ok}{chyby_text}',
    es: '✓ Enviado: {ok}{chyby_text}',
  },

  // ─────────────── TOASTS s placeholders ───────────────
  toast_added_to_print: {
    cs: '{label} přidán do tisku ({n})',
    sk: '{label} pridaný do tlače ({n})',
    en: '{label} added to print ({n})',
    de: '{label} zum Druck hinzugefügt ({n})',
    es: '{label} añadido a impresión ({n})',
  },
  toast_opening_docs: {
    cs: 'Otevírám {n} dokumentů…',
    sk: 'Otváram {n} dokumentov…',
    en: 'Opening {n} documents…',
    de: 'Öffne {n} Dokumente…',
    es: 'Abriendo {n} documentos…',
  },
  toast_section_requires_role: {
    cs: '🔒 Sekce „{section}" vyžaduje vyšší oprávnění než „{role}"',
    sk: '🔒 Sekcia „{section}" vyžaduje vyššie oprávnenie než „{role}"',
    en: '🔒 Section "{section}" requires higher permissions than "{role}"',
    de: '🔒 Bereich "{section}" erfordert höhere Berechtigung als "{role}"',
    es: '🔒 La sección "{section}" requiere permisos superiores a "{role}"',
  },
  toast_dummy_print: {
    cs: '✓ Dummy tisk → {file}',
    sk: '✓ Dummy tlač → {file}',
    en: '✓ Dummy print → {file}',
    de: '✓ Dummy-Druck → {file}',
    es: '✓ Impresión dummy → {file}',
  },
  toast_printed_bytes: {
    cs: '✓ Vytištěno ({bytes}B)',
    sk: '✓ Vytlačené ({bytes}B)',
    en: '✓ Printed ({bytes}B)',
    de: '✓ Gedruckt ({bytes}B)',
    es: '✓ Impreso ({bytes}B)',
  },
  toast_order_created_amount: {
    cs: '✓ Objednávka {cislo} vytvořena ({amount})',
    sk: '✓ Objednávka {cislo} vytvorená ({amount})',
    en: '✓ Order {cislo} created ({amount})',
    de: '✓ Bestellung {cislo} erstellt ({amount})',
    es: '✓ Pedido {cislo} creado ({amount})',
  },
  toast_approved_items: {
    cs: '✅ Schváleno {n} položek',
    sk: '✅ Schválené {n} položiek',
    en: '✅ Approved {n} items',
    de: '✅ {n} Artikel genehmigt',
    es: '✅ Aprobados {n} artículos',
  },
  toast_saved_tables: {
    cs: '✅ Uloženo ({n} stolů)',
    sk: '✅ Uložené ({n} stolov)',
    en: '✅ Saved ({n} tables)',
    de: '✅ Gespeichert ({n} Tische)',
    es: '✅ Guardado ({n} mesas)',
  },
  toast_added_item_price: {
    cs: '+ {nazev} ({cena} Kč)',
    sk: '+ {nazev} ({cena} Kč)',
    en: '+ {nazev} ({cena} CZK)',
    de: '+ {nazev} ({cena} CZK)',
    es: '+ {nazev} ({cena} CZK)',
  },
  toast_paid_amount: {
    cs: '✅ Zaplaceno {amount} Kč ({method})',
    sk: '✅ Zaplatené {amount} Kč ({method})',
    en: '✅ Paid {amount} CZK ({method})',
    de: '✅ Bezahlt {amount} CZK ({method})',
    es: '✅ Pagado {amount} CZK ({method})',
  },
  toast_split_parts: {
    cs: '✂️ Rozděleno na {n} částí',
    sk: '✂️ Rozdelené na {n} častí',
    en: '✂️ Split into {n} parts',
    de: '✂️ In {n} Teile geteilt',
    es: '✂️ Dividido en {n} partes',
  },
  toast_merged_accounts: {
    cs: '🔗 Sloučeno {n} účtů',
    sk: '🔗 Zlúčených {n} účtov',
    en: '🔗 Merged {n} bills',
    de: '🔗 {n} Konten zusammengeführt',
    es: '🔗 Fusionadas {n} cuentas',
  },
  toast_floorplan_imported: {
    cs: '✅ Naimportováno {stoly} stolů ve {zones} zónách.',
    sk: '✅ Naimportovaných {stoly} stolov v {zones} zónach.',
    en: '✅ Imported {stoly} tables in {zones} zones.',
    de: '✅ {stoly} Tische in {zones} Zonen importiert.',
    es: '✅ Importadas {stoly} mesas en {zones} zonas.',
  },
  toast_presets_saved: {
    cs: '✓ Uloženo {n} presetů. V POS se použijí ihned (cache 5 min).',
    sk: '✓ Uložených {n} presetov. V POS sa použijú ihneď (cache 5 min).',
    en: '✓ Saved {n} presets. Used in POS immediately (5 min cache).',
    de: '✓ {n} Voreinstellungen gespeichert. Im POS sofort verwendet (5 Min Cache).',
    es: '✓ Guardados {n} ajustes. Usados en TPV inmediatamente (caché 5 min).',
  },
  toast_n_changes_saved: {
    cs: 'Uloženo {n} změn',
    sk: 'Uložených {n} zmien',
    en: 'Saved {n} changes',
    de: '{n} Änderungen gespeichert',
    es: 'Guardados {n} cambios',
  },
  toast_max_in_category: {
    cs: 'Max {n} v této kategorii',
    sk: 'Max {n} v tejto kategórii',
    en: 'Max {n} in this category',
    de: 'Max {n} in dieser Kategorie',
    es: 'Máx {n} en esta categoría',
  },
  toast_price_set_to: {
    cs: 'Cena nastavena na {sum}',
    sk: 'Cena nastavená na {sum}',
    en: 'Price set to {sum}',
    de: 'Preis auf {sum} gesetzt',
    es: 'Precio establecido en {sum}',
  },
  toast_updated_to_version: {
    cs: '✅ Aktualizováno na {version}!',
    sk: '✅ Aktualizované na {version}!',
    en: '✅ Updated to {version}!',
    de: '✅ Aktualisiert auf {version}!',
    es: '✅ Actualizado a {version}!',
  },
  toast_update_failed: {
    cs: '❌ {err}',
    sk: '❌ {err}',
    en: '❌ {err}',
    de: '❌ {err}',
    es: '❌ {err}',
  },
  toast_loaded_from_product: {
    cs: '✓ Načteno z výrobku: {nazev}',
    sk: '✓ Načítané z výrobku: {nazev}',
    en: '✓ Loaded from product: {nazev}',
    de: '✓ Aus Produkt geladen: {nazev}',
    es: '✓ Cargado del producto: {nazev}',
  },

  // ─────────────── POS specific ───────────────
  pos_draft_saved: {
    cs: '💾 Uloženo ({n} rozpracovaných v paměti)',
    sk: '💾 Uložené ({n} rozpracovaných v pamäti)',
    en: '💾 Saved ({n} drafts in memory)',
    de: '💾 Gespeichert ({n} Entwürfe im Speicher)',
    es: '💾 Guardado ({n} borradores en memoria)',
  },
  pos_draft_loaded: {
    cs: '✓ Načteno: {n} položek · {amount} Kč',
    sk: '✓ Načítané: {n} položiek · {amount} Kč',
    en: '✓ Loaded: {n} items · {amount} CZK',
    de: '✓ Geladen: {n} Artikel · {amount} CZK',
    es: '✓ Cargado: {n} artículos · {amount} CZK',
  },
  pos_bill_ready: {
    cs: '✓ Účet {cislo} · {amount} · připraveno pro dalšího hosta',
    sk: '✓ Účet {cislo} · {amount} · pripravené pre ďalšieho hosťa',
    en: '✓ Bill {cislo} · {amount} · ready for next guest',
    de: '✓ Rechnung {cislo} · {amount} · bereit für nächsten Gast',
    es: '✓ Cuenta {cislo} · {amount} · listo para el siguiente cliente',
  },
  pos_bill_print_confirm: {
    cs: 'Účet {cislo} · {amount}\n\n🖨️ Vytisknout účtenku?',
    sk: 'Účet {cislo} · {amount}\n\n🖨️ Vytlačiť účtenku?',
    en: 'Bill {cislo} · {amount}\n\n🖨️ Print receipt?',
    de: 'Rechnung {cislo} · {amount}\n\n🖨️ Beleg drucken?',
    es: 'Cuenta {cislo} · {amount}\n\n🖨️ ¿Imprimir recibo?',
  },
  pos_bill_closed: {
    cs: '✓ Účet uzavřen · {cislo} · {amount}',
    sk: '✓ Účet uzavretý · {cislo} · {amount}',
    en: '✓ Bill closed · {cislo} · {amount}',
    de: '✓ Rechnung geschlossen · {cislo} · {amount}',
    es: '✓ Cuenta cerrada · {cislo} · {amount}',
  },
  pos_item_added: {
    cs: '+ {nazev}',
    sk: '+ {nazev}',
    en: '+ {nazev}',
    de: '+ {nazev}',
    es: '+ {nazev}',
  },

  // ─────────────── FLOORPLAN specific ───────────────
  fp_confirm_delete_zone: {
    cs: 'Smazat zónu "{nazev}" včetně všech prvků?',
    sk: 'Zmazať zónu "{nazev}" vrátane všetkých prvkov?',
    en: 'Delete zone "{nazev}" including all elements?',
    de: 'Zone "{nazev}" inkl. aller Elemente löschen?',
    es: '¿Eliminar la zona "{nazev}" incluyendo todos los elementos?',
  },
  fp_confirm_load_template_destructive: {
    cs: 'POZOR — Načtení šablony smaže aktuální stoly z DB a nahradí je. Pokračovat?',
    sk: 'POZOR — Načítanie šablóny zmaže aktuálne stoly z DB a nahradí ich. Pokračovať?',
    en: 'WARNING — Loading template will delete current tables from DB and replace them. Continue?',
    de: 'ACHTUNG — Vorlage löscht aktuelle Tische aus DB und ersetzt sie. Fortfahren?',
    es: 'ATENCIÓN — Cargar la plantilla eliminará las mesas actuales de la BD y las reemplazará. ¿Continuar?',
  },
  fp_confirm_import_overwrite: {
    cs: 'Import přepíše aktuální floor plan. Pokračovat?',
    sk: 'Import prepíše aktuálny floor plan. Pokračovať?',
    en: 'Import will overwrite the current floor plan. Continue?',
    de: 'Import überschreibt den aktuellen Sitzplan. Fortfahren?',
    es: 'La importación sobrescribirá el plano actual. ¿Continuar?',
  },
  fp_toast_applied_zones: {
    cs: '✓ Aplikováno: {zon} zón · {stoly} stolů · {mist} míst',
    sk: '✓ Aplikované: {zon} zón · {stoly} stolov · {mist} miest',
    en: '✓ Applied: {zon} zones · {stoly} tables · {mist} seats',
    de: '✓ Angewendet: {zon} Zonen · {stoly} Tische · {mist} Plätze',
    es: '✓ Aplicado: {zon} zonas · {stoly} mesas · {mist} asientos',
  },
  vcards_imported: {
    cs: '✅ Importováno: {n} odběratelů.{warnings}',
    sk: '✅ Importovaných: {n} odberateľov.{warnings}',
    en: '✅ Imported: {n} customers.{warnings}',
    de: '✅ Importiert: {n} Kunden.{warnings}',
    es: '✅ Importados: {n} clientes.{warnings}',
  },
  more_errors_suffix: {
    cs: '\n…a další {n}',
    sk: '\n…a ďalších {n}',
    en: '\n…and {n} more',
    de: '\n…und {n} weitere',
    es: '\n…y {n} más',
  },
  backup_created: {
    cs: '✓ Záloha vytvořena!\n\nSoubor: {soubor}\nVelikost: {velikost}\nTabulek: {tabulek}\nZáznamů: {zaznamu}\n{uploads_text}\nDoporučuju si ji rovnou stáhnout (tlačítko ⬇).',
    sk: '✓ Záloha vytvorená!\n\nSúbor: {soubor}\nVeľkosť: {velikost}\nTabuliek: {tabulek}\nZáznamov: {zaznamu}\n{uploads_text}\nOdporúčam si ju hneď stiahnuť (tlačidlo ⬇).',
    en: '✓ Backup created!\n\nFile: {soubor}\nSize: {velikost}\nTables: {tabulek}\nRecords: {zaznamu}\n{uploads_text}\nWe recommend downloading it right away (⬇ button).',
    de: '✓ Backup erstellt!\n\nDatei: {soubor}\nGröße: {velikost}\nTabellen: {tabulek}\nDatensätze: {zaznamu}\n{uploads_text}\nWir empfehlen, es sofort herunterzuladen (⬇-Schaltfläche).',
    es: '✓ ¡Copia de seguridad creada!\n\nArchivo: {soubor}\nTamaño: {velikost}\nTablas: {tabulek}\nRegistros: {zaznamu}\n{uploads_text}\nRecomendamos descargarla inmediatamente (botón ⬇).',
  },
  uploads_count_line: {
    cs: 'Souborů z /uploads: {n}\n',
    sk: 'Súborov z /uploads: {n}\n',
    en: 'Files from /uploads: {n}\n',
    de: 'Dateien aus /uploads: {n}\n',
    es: 'Archivos de /uploads: {n}\n',
  },
};

window.t = function(key, vars) {
  const entry = I18N[key];
  if (!entry) return key;
  const lang = window.appekCurrentLang;
  let s = entry[lang];
  // 🆕 v2.6.9 — Fallback pro sk/de/další jazyky přes I18N_EXTRA (lookup CS text → překlad)
  // Pokud sk/de chybí v hardcoded I18N, zkusíme overlay z i18n_extra.js
  if (!s && typeof I18N_EXTRA !== 'undefined' && I18N_EXTRA && I18N_EXTRA[lang] && entry.cs) {
    s = I18N_EXTRA[lang][entry.cs];
  }
  // Final fallback na češtinu nebo key
  if (!s) s = entry.cs || key;
  if (vars) {
    for (const k in vars) s = s.replace(new RegExp('{' + k + '}', 'g'), vars[k]);
  }
  return s;
};

// =============================================================
// 🔄 Apply translations — pro statické HTML elementy s data-i18n
// =============================================================
window.applyTranslations = function() {
  document.documentElement.lang = window.appekCurrentLang;
  document.querySelectorAll('[data-i18n]').forEach(el => {
    const key = el.dataset.i18n;
    if (key) el.textContent = t(key);
  });
  document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
    const key = el.dataset.i18nPlaceholder;
    if (key) el.placeholder = t(key);
  });
  document.querySelectorAll('[data-i18n-title]').forEach(el => {
    const key = el.dataset.i18nTitle;
    if (key) el.title = t(key);
  });
};

// Run apply hned po loadu
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', applyTranslations);
} else {
  applyTranslations();
}
