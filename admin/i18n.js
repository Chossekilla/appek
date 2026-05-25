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
