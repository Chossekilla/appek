/**
 * 🏢 Kontakty z nastavení webu (vendor → Business info → vendor_settings.business_*)
 *
 * Jeden zdroj pravdy pro veřejné stránky appek.cz (landing i varianty, kontakty,
 * VOP, GDPR, platba…). HTML drží jen fallback (+420 774 599 622 / info@appek.cz);
 * tenhle skript ho po načtení přepíše hodnotami z /api/business_info.php.
 *
 * Co přepisuje:
 *   - a[href^="tel:"]                         → business_phone (všechny tel. odkazy = firemní telefon)
 *   - [data-biz-phone]                        → business_phone (i ne-odkazy)
 *   - mailto:/text info@ | support@ | gdpr@appek.cz → business_email / _support_email / _gdpr_email
 *   - [data-biz-email="support|gdpr"]         → odpovídající e-mail (bez hodnoty = hlavní)
 *   - [data-biz-name|address|ico]             → obchodní jméno / adresa / IČO
 * Re-aplikuje se po přepnutí jazyka (setLang přepisuje innerHTML z i18n).
 */
(function () {
  var DEFAULT_EMAILS = { 'info@appek.cz': 'business_email', 'support@appek.cz': 'business_support_email', 'gdpr@appek.cz': 'business_gdpr_email' };
  var biz = null;

  function emailFor(kind) {
    if (kind === 'support') return biz.business_support_email || biz.business_email;
    if (kind === 'gdpr') return biz.business_gdpr_email || biz.business_email;
    return biz.business_email || biz.business_support_email;
  }

  function apply() {
    if (!biz) return;
    var phone = biz.business_phone;
    if (phone) {
      var tel = 'tel:+' + phone.replace(/[^\d]/g, '');
      document.querySelectorAll('a[href^="tel:"], [data-biz-phone]').forEach(function (el) {
        if (el.tagName === 'A') el.href = tel;
        // Text přepiš jen když je to číslo (ne „Zavolejte nám" apod.)
        if (el.hasAttribute('data-biz-phone') || /^\s*\+?[\d\s]{9,}\s*$/.test(el.textContent)) el.textContent = phone;
      });
    }
    // Výchozí e-maily v odkazech i v <code>/textu
    Object.keys(DEFAULT_EMAILS).forEach(function (def) {
      var val = biz[DEFAULT_EMAILS[def]];
      if (!val || val === def) return;
      document.querySelectorAll('a[href^="mailto:' + def + '"]').forEach(function (a) { a.href = 'mailto:' + val; });
      document.querySelectorAll('a, code').forEach(function (el) {
        if (el.children.length === 0 && el.textContent.trim() === def) el.textContent = val;
      });
    });
    document.querySelectorAll('[data-biz-email]').forEach(function (el) {
      var val = emailFor(el.getAttribute('data-biz-email'));
      if (!val) return;
      if (el.tagName === 'A') el.href = 'mailto:' + val;
      if (el.textContent.indexOf('@') !== -1) el.textContent = val;
    });
    var map = { 'data-biz-name': biz.business_name, 'data-biz-address': biz.business_address_full,
                'data-biz-ico': biz.business_ico ? 'IČO: ' + biz.business_ico : '' };
    Object.keys(map).forEach(function (attr) {
      if (!map[attr]) return;
      document.querySelectorAll('[' + attr + ']').forEach(function (el) { el.textContent = map[attr]; });
    });
  }

  function hookSetLang() {
    if (typeof window.setLang !== 'function' || window.setLang.__biz) return;
    var orig = window.setLang;
    window.setLang = function () { var r = orig.apply(this, arguments); apply(); return r; };
    window.setLang.__biz = true;
  }

  fetch('/api/business_info.php', { cache: 'no-cache' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (j) {
      if (!j || j.error || typeof j !== 'object') return;
      biz = j;
      hookSetLang();
      apply();
    })
    .catch(function () { /* tichý fallback — zůstane hodnota z HTML */ });
})();
