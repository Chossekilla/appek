// =============================================================
// NASTAVENÍ - údaje firmy pro fakturu
// =============================================================
// 🆕 v3.0.358 — toggle zámku mazání vydaných dokladů (Nastavení → Údržba → Doklady)
window.toggleZamknoutMazani = async function (cb) {
  try {
    await api('admin_nastaveni.php', { method: 'PUT', body: JSON.stringify({ faktura_zamknout_mazani: cb.checked ? '1' : '0' }) });
    toastSuccess(cb.checked ? 'Mazání vydaných dokladů zamčeno 🔒' : 'Mazání vydaných dokladů povoleno');
  } catch (e) { cb.checked = !cb.checked; toastError('Nepodařilo se uložit: ' + (e.message || e)); }
};

async function renderNastaveni() {
  const n = await api('admin_nastaveni.php');
  const c = document.getElementById('content');

  // 🗂️ Záložky pro Nastavení
  // 🆕 v2.9.181 — tab '🥖 Výroba' přesunut do top-level Výroba hubu.
  // 🆕 v2.9.205 — nový tab 💳 Platby (centrální on/off platebních metod).
  // 🆕 v3.0.29 — Tiskárny přesunuty do Nástroje (navigate('tiskarny'))
  // 🆕 v3.0.29 — Účetní (POHODA/FlexiBee) sloučeno pod Integrace blok
  const TABS = [
    { key: 'firma',      label: '🏢 Firma & doklady',  popis: 'Firemní údaje, kontakt, číselné řady, DPH' },
    { key: 'notifikace', label: '📧 Notifikace',        popis: 'E-maily a uzávěrka úprav objednávek' },
    // 🆕 v3.0.271 — Kanály sloučeny pod Platby (souvisí: jak platí × odkud přišla objednávka).
    { key: 'platby',     label: '💳 Platby & kanály',   popis: 'Platební metody (jak zákazník zaplatí) + prodejní kanály (odkud objednávka přišla).' },
    // 🆕 v3.0.370 — Integrace NENÍ tab v Nastavení (user: „integrace pryč z nastavení, nechat v nástrojích").
    //   Otevírá se jako SAMOSTATNÁ stránka z Nástrojů: navigate('integrace') → renderNastaveni standalone shell
    //   (titulek „🔌 Integrace" + ← Nástroje, bez settings tab baru). Blok zůstává v blokyTabu['integrace'].
    { key: 'pristupy',   label: '👥 Přístupy, ceny & měna', popis: 'Uživatelé, slevové skupiny a měna/kurz (cílová měna + vlastní nebo ČNB kurz).', adminOnly: true },
    { key: 'balicky',    label: '🎁 Balíčky',           popis: 'Aktivace doplňkových modulů (Cukrárna, Lahůdky, …)', adminOnly: true },
    { key: 'udrzba',     label: '🛠️ Údržba',            popis: 'Bezpečnost, zálohy DB, diagnostika' },
    { key: 'napoveda',   label: '❓ Nápověda & FAQ',     popis: 'Jak na to — návody a časté dotazy' },
  ];
  // 🐛 fix v2.9.182 — pokud user měl uložený state._nastaveniTab='vyroba' (smazaný
  // tab od v2.9.181), fallback do 'firma'. Bez explicitního checku by se zobrazila
  // prázdná Nastavení stránka (aktivniBlok = undefined → text "undefined").
  const validTabKeys = [...TABS.map(t => t.key), 'integrace']; // integrace = skrytý tab, otevírá se z Nástrojů
  let aktTab = state._nastaveniTab || 'firma';
  // 🆕 v3.0.29 — Migrace starých stavů: tiskarny → standalone, ucetni → integrace
  if (aktTab === 'tiskarny') {
    state._nastaveniTab = 'integrace';
    setTimeout(() => navigate('tiskarny'), 0);
    return;
  }
  if (aktTab === 'ucetni') aktTab = 'integrace';
  if (aktTab === 'kanaly') aktTab = 'platby'; // 🆕 v3.0.271 — kanály sloučeny pod Platby
  if (!validTabKeys.includes(aktTab)) aktTab = 'firma';

  // === BLOKY OBSAHU JEDNOTLIVÝCH TABŮ ===
  const blokFirma = `
    <!-- ŘADA 1: Firemní + Kontaktní -->
    <div class="nastaveni-row">
      <div class="card-block">
        <h3 style="margin-bottom:12px;">🏢 Firemní údaje</h3>
        <p style="font-size:12px;color:var(--text-3);margin-bottom:14px;">
          Zobrazují se na všech tištěných dokumentech (FA, DL, výrobní list).
        </p>

        <div class="form-grid form-grid-tight">
          <div class="full">
            <label class="form-label">Název firmy *</label>
            <input class="form-input" id="ns-nazev" value="${esc(n.firma_nazev || '')}" placeholder="např. APPEK B2B s.r.o.">
          </div>
          <div>
            <label class="form-label">IČO <span style="font-size:11px;color:var(--text-3);font-weight:400">(🇨🇿 ARES / 🇸🇰 RPO)</span></label>
            <div style="display:flex;gap:6px">
              <input class="form-input" id="ns-ico" value="${esc(n.firma_ico || '')}" placeholder="12345678" style="flex:1">
              <button type="button" class="btn-secondary" onclick="nastaveniAresLookup()" title="Načíst data o firmě z veřejných registrů — nejdřív CZ ARES, pak SK RPO" style="white-space:nowrap;font-size:12px;padding:8px 12px">🔍 Načíst</button>
            </div>
          </div>
          <div>
            <label class="form-label">DIČ</label>
            <input class="form-input" id="ns-dic" value="${esc(n.firma_dic || '')}" placeholder="CZ12345678 / SK1234567890">
          </div>
          <div class="full">
            <label class="form-label">Ulice</label>
            <input class="form-input" id="ns-ulice" value="${esc(n.firma_ulice || '')}" placeholder="Hlavní 15">
          </div>
          <div>
            <label class="form-label">Město</label>
            <input class="form-input" id="ns-mesto" value="${esc(n.firma_mesto || '')}" placeholder="Praha 1">
          </div>
          <div>
            <label class="form-label">PSČ</label>
            <input class="form-input" id="ns-psc" value="${esc(n.firma_psc || '')}" placeholder="110 00">
          </div>
          <div class="full">
            <label class="form-label">Bankovní účet</label>
            <input class="form-input" id="ns-banka" value="${esc(n.firma_banka || '')}" placeholder="1234567890/0100">
          </div>
        </div>
      </div>

      <!-- 🖼️ LOGO + FAVICON -->
      <div class="card-block">
        <h3 style="margin-bottom:6px;">🖼️ Logo a favicon</h3>
        <p style="font-size:12px;color:var(--text-3);margin-bottom:14px;">
          Nahrajte logo firmy — automaticky se z něj vygeneruje i favicon (ikona v záložce prohlížeče).
          Podporováno PNG / JPG / WEBP, max 5 MB.
        </p>
        <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start">
          <!-- Náhled loga -->
          <div style="display:flex;flex-direction:column;align-items:center;gap:6px">
            <div style="width:120px;height:120px;border:2px dashed var(--border);border-radius:10px;display:flex;align-items:center;justify-content:center;background:var(--surface-2);overflow:hidden" id="ns-logo-preview">
              ${n.firma_logo_url
                ? `<img src="${esc(n.firma_logo_url)}" style="max-width:100%;max-height:100%;object-fit:contain" alt="Logo">`
                : `<span style="font-size:38px;color:var(--text-3)">🖼️</span>`}
            </div>
            <div style="font-size:11px;color:var(--text-3)">Logo (400×400)</div>
          </div>
          <!-- Náhled favicon -->
          <div style="display:flex;flex-direction:column;align-items:center;gap:6px">
            <div style="width:48px;height:48px;border:2px dashed var(--border);border-radius:8px;display:flex;align-items:center;justify-content:center;background:var(--surface-2);overflow:hidden" id="ns-favicon-preview">
              ${n.firma_favicon_url
                ? `<img src="${esc(n.firma_favicon_url)}" style="width:32px;height:32px;object-fit:contain" alt="Favicon">`
                : `<span style="font-size:16px;color:var(--text-3)">⭐</span>`}
            </div>
            <div style="font-size:11px;color:var(--text-3)">Favicon (32×32)</div>
          </div>
          <!-- Akce -->
          <div style="flex:1;min-width:200px;display:flex;flex-direction:column;gap:8px">
            <input type="file" id="ns-logo-file" accept="image/png,image/jpeg,image/webp" style="display:none" onchange="uploadLogo()">
            <button class="btn-primary btn-green" onclick="document.getElementById('ns-logo-file').click()" style="font-weight:700">
              📤 ${n.firma_logo_url ? 'Změnit logo' : 'Nahrát logo'}
            </button>
            ${n.firma_logo_url ? `<button class="btn-secondary" onclick="removeLogo()" style="color:var(--danger-text)">🗑️ Odstranit</button>` : ''}
            <small style="color:var(--text-3);font-size:11px;line-height:1.4">
              💡 Tip: Použijte čtvercové logo s průhledným pozadím (PNG). Favicon se zobrazí v záložce prohlížeče po nahrání.
            </small>
          </div>
        </div>

        <!-- Toggle: logo na fakturách / DL -->
        <div style="margin-top:14px;padding-top:14px;border-top:1px dashed var(--border)">
          <label class="checkbox-row" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--surface-2);border-radius:8px;cursor:pointer">
            <input type="checkbox" id="ns-logo-doklady" ${(n.firma_logo_na_dokladech ?? '1') === '1' ? 'checked' : ''} style="width:18px;height:18px;cursor:pointer">
            <span style="flex:1">
              <strong style="font-size:14px">📄 Tisknout logo na fakturách a dodacích listech</strong>
              <div style="font-size:12px;color:var(--text-3);margin-top:2px">
                Pokud máte nahraté logo, přidá se nahoru každé FA / DL. Vypnutím se logo skryje (zůstane jen text firmy).
              </div>
            </span>
          </label>
        </div>

        <!-- 💸 SAZBY DPH (přesunuto pod logo — využití prostoru) -->
        <div style="margin-top:14px;padding-top:14px;border-top:1px dashed var(--border)">
          <h4 style="margin:0 0 4px;font-size:14px;display:flex;align-items:center;gap:6px">💸 Sazby DPH</h4>
          <p style="font-size:11px;color:var(--text-3);margin:0 0 10px;line-height:1.5">
            Sazby DPH používané u výrobků. Změna sazby ovlivní jen <strong>nově vystavené</strong> doklady — existující FA/DL/objednávky zůstávají s původním DPH (snapshot).
          </p>
          <div id="dph-container">
            <div style="text-align:center;padding:14px;color:var(--text-3);font-size:12px">Načítám…</div>
          </div>
        </div>
      </div>

      <div class="card-block">
        <h3 style="margin-bottom:12px;">📞 Kontaktní údaje</h3>
        <p style="font-size:12px;color:var(--text-3);margin-bottom:14px;">Volitelné — zobrazí se v patičce dokladů.</p>
        <div class="form-grid form-grid-tight">
          <div>
            <label class="form-label">E-mail firmy</label>
            <input class="form-input" id="ns-email" type="email" value="${esc(n.firma_email || '')}" placeholder="info@appek.cz">
          </div>
          <div>
            <label class="form-label">Telefon</label>
            <input class="form-input" id="ns-tel" value="${esc(n.firma_telefon || '')}" placeholder="+420 777 123 456">
          </div>
          <div class="full">
            <label class="form-label">Web</label>
            <input class="form-input" id="ns-web" value="${esc(n.firma_web || '')}" placeholder="www.appek.cz">
          </div>
          <div class="full">
            <label class="form-label">Patička dokladů</label>
            <textarea class="form-input" id="ns-paticka" rows="3" placeholder="APPEK B2B s.r.o. · tel: ... · web: ...">${esc(n.firma_paticka_dokladu || '')}</textarea>
            <small style="color:var(--text-3);font-size:12px;display:block;margin-top:4px;">Zobrazí se na konci faktury a dodacího listu. Více řádků = Enter.</small>
          </div>
        </div>
      </div>

      <!-- 🎨 BRANDING -->
      <div class="card-block">
        <h3 style="margin-bottom:12px;">🎨 Branding (vlastní barva + logo)</h3>
        <p style="font-size:12px;color:var(--text-3);margin-bottom:14px;">Přizpůsob vzhled aplikace tvé firmě. Barva ovlivňuje akcenty (tlačítka, badge, nav). Logo nahraj v sekci Údržba → Logo.</p>
        <div class="form-grid form-grid-tight">
          <div>
            <label class="form-label">🎨 Primární barva</label>
            <div style="display:flex;gap:8px;align-items:center">
              <input type="color" id="ns-brand-color" value="${esc(n.firma_brand_color || '#BA7517')}" style="width:54px;height:40px;border:1px solid var(--border);border-radius:8px;cursor:pointer;background:transparent">
              <input class="form-input" id="ns-brand-color-text" value="${esc(n.firma_brand_color || '#BA7517')}" placeholder="#BA7517" style="font-family:'SF Mono',Menlo,monospace" oninput="document.getElementById('ns-brand-color').value=this.value">
            </div>
            <small style="color:var(--text-3);font-size:11px;display:block;margin-top:4px">Hex barva (#RRGGBB). Light/dark varianty se vypočítají automaticky.</small>
          </div>
          <div>
            <label class="form-label">🖼️ URL loga</label>
            <input class="form-input" id="ns-logo-url" value="${esc(n.firma_logo_url || '')}" placeholder="/uploads/logo/logo.png">
            <small style="color:var(--text-3);font-size:11px;display:block;margin-top:4px">Nahraj v Údržba → 🖼️ Logo, nebo zadej URL.</small>
          </div>
          <div class="full" style="background:var(--surface-2);padding:14px;border-radius:8px;margin-top:6px">
            <strong style="font-size:12.5px">📺 Náhled</strong>
            <div id="brand-preview" style="display:flex;gap:10px;align-items:center;margin-top:8px;padding:10px;background:var(--surface);border-radius:6px">
              <div id="brand-prev-logo" style="width:32px;height:32px;border-radius:8px;background:var(--primary);color:#fff;font-weight:800;display:flex;align-items:center;justify-content:center;font-size:18px">A</div>
              <button class="btn-primary btn-green" style="font-size:13px;padding:8px 16px" onclick="event.preventDefault()">Ukázkové tlačítko</button>
              <span style="background:var(--primary);color:#fff;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600">Badge</span>
            </div>
            <button class="btn-secondary" onclick="applyBrandPreview()" style="margin-top:8px;font-size:12px">🔄 Vyzkoušet barvu hned</button>
          </div>
        </div>
      </div>
    </div>

    <script>
      // Sync color picker → text input
      setTimeout(() => {
        const cp = document.getElementById('ns-brand-color');
        const tp = document.getElementById('ns-brand-color-text');
        if (cp && tp) {
          cp.oninput = () => { tp.value = cp.value; };
        }
      }, 50);
    </script>
  `;

  const blokNotifikace = `
    <!-- 📧 v3.0.289 — SMTP odesílání -->
    <div class="card-block" id="ns-smtp-block" style="margin-bottom:14px">
      <h3 style="margin:0 0 4px">📧 SMTP odesílání</h3>
      <p style="font-size:12px;color:var(--text-3);margin:0 0 12px">
        Výchozí jdou e-maily přes server (PHP <code>mail()</code>) — často padají do spamu. Nastav SMTP svého poskytovatele (Seznam, Google Workspace, vlastní…) pro spolehlivé doručení faktur, voucherů a notifikací. Prázdné / vypnuté = nativní odesílání.
      </p>
      <label style="display:flex;align-items:center;gap:8px;font-size:14px;margin-bottom:10px;cursor:pointer">
        <input type="checkbox" id="smtp-enabled" onchange="smtpToggle()"> <strong>Posílat e-maily přes SMTP</strong>
      </label>
      <div id="smtp-fields" style="display:none">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
          <div style="flex:2;min-width:200px"><label class="form-label" style="font-size:12px">Server (host)</label><input class="form-input" id="smtp-host" placeholder="smtp.seznam.cz"></div>
          <div style="width:90px"><label class="form-label" style="font-size:12px">Port</label><input class="form-input" id="smtp-port" type="number" value="587"></div>
          <div style="width:130px"><label class="form-label" style="font-size:12px">Zabezpečení</label>
            <select class="form-input" id="smtp-secure"><option value="tls">STARTTLS (587)</option><option value="ssl">SSL/TLS (465)</option><option value="none">Žádné</option></select></div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:8px">
          <div style="flex:1;min-width:180px"><label class="form-label" style="font-size:12px">Uživatel (login)</label><input class="form-input" id="smtp-user" autocomplete="off" placeholder="faktury@firma.cz"></div>
          <div style="flex:1;min-width:160px"><label class="form-label" style="font-size:12px">Heslo</label><input class="form-input" id="smtp-pass" type="password" autocomplete="new-password" placeholder="••••••••"></div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:8px">
          <div style="flex:1;min-width:180px"><label class="form-label" style="font-size:12px">Odesílatel (e-mail)</label><input class="form-input" id="smtp-from" placeholder="faktury@firma.cz"></div>
          <div style="flex:1;min-width:160px"><label class="form-label" style="font-size:12px">Jméno odesílatele</label><input class="form-input" id="smtp-from-name" placeholder="Pekárna Novák"></div>
        </div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap">
        <button class="btn-primary btn-green" onclick="smtpSave()">💾 Uložit SMTP</button>
        <button class="btn-secondary" onclick="smtpTest()">✉️ Odeslat testovací</button>
        <span id="smtp-info" style="font-size:12px;color:var(--text-3)"></span>
      </div>
      <pre id="smtp-log" style="display:none;margin-top:10px;background:#1d1d1f;color:#cdd;padding:10px 12px;border-radius:8px;font-size:11px;line-height:1.6;max-height:240px;overflow:auto;white-space:pre-wrap"></pre>
    </div>

    <!-- ŘADA 2: Notifikace + Uzávěrka -->
    <div class="nastaveni-row">
      <div class="card-block">
        <h3 style="margin-bottom:8px;">📧 Notifikace o objednávkách</h3>
        <p style="font-size:12px;color:var(--text-3);margin-bottom:14px;">
          Email pro provoz i odběratele.
        </p>
        <div class="form-grid">
          <div class="full">
            <label class="form-label">Adresa(y) provozu pro notifikace</label>
            <input class="form-input" id="ns-admin-email" type="text"
                   value="${esc(n.admin_email_pro_objednavky || '')}"
                   placeholder="objednavky@appek.cz, sef@appek.cz">
            <small style="color:var(--text-3);font-size:12px;display:block;margin-top:4px;">
              Více adres oddělte čárkou. Prázdné = použije se E-mail firmy.
            </small>
          </div>
        </div>

        <div style="margin-top:14px;padding:12px;background:#EFF6FF;border:1px solid #B5D4F4;border-radius:8px">
          <h4 style="font-size:13px;margin-bottom:8px;color:#0C447C">📨 Notifikace pro odběratele</h4>
          <div class="checkbox-row" style="margin-bottom:6px">
            <input type="checkbox" id="ns-notif-nova" ${(n.notif_nova_objednavka ?? '1') === '1' ? 'checked' : ''}>
            <label for="ns-notif-nova" style="font-size:13px">Potvrzení nové objednávky (s položkami)</label>
          </div>
          <div class="checkbox-row" style="margin-bottom:8px">
            <input type="checkbox" id="ns-notif-stav" ${(n.notif_zmena_stavu ?? '1') === '1' ? 'checked' : ''}>
            <label for="ns-notif-stav" style="font-size:13px">Změna stavu objednávky</label>
          </div>
          <label class="form-label" style="font-size:11px;margin-top:8px">Stavy spouštějící e-mail</label>
          <div id="ns-notif-stavy-row" style="display:flex;flex-wrap:wrap;gap:8px;font-size:12px">
            ${[
              { k: 'potvrzena',  l: '✓ Potvrzena' },
              { k: 've_vyrobe',  l: '🔥 Ve výrobě' },
              { k: 'pripravena', l: '📦 Připravena' },
              { k: 'expedovana', l: '🚚 Expedována' },
              { k: 'dorucena',   l: '✅ Doručena' },
            ].map(s => {
              const aktivni = (n.notif_stavy_pro_email || 'expedovana,dorucena').split(',').map(x => x.trim()).includes(s.k);
              return `<label class="checkbox-row" style="background:white;padding:5px 10px;border-radius:6px;border:1px solid #D5E5F5">
                <input type="checkbox" data-stav-notif value="${s.k}" ${aktivni ? 'checked' : ''}>
                <span>${s.l}</span>
              </label>`;
            }).join('')}
          </div>
          <small style="display:block;color:#0C447C;font-size:11px;margin-top:6px">
            Odběratel může v detailu vypnout notifikace pro sebe individuálně.
          </small>
        </div>

        <div style="margin-top:14px;padding:12px;background:var(--surface-2);border-radius:8px">
          <h4 style="font-size:13px;margin-bottom:6px;">🧪 Testovací email</h4>
          <p style="font-size:11px;color:var(--text-3);margin-bottom:8px;">
            Ověří, že PHP mail() funguje.
          </p>
          <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
            <input class="form-input" id="ns-test-email" type="email" placeholder="kam-poslat@example.cz" style="flex:1;min-width:180px">
            <button class="btn-secondary" onclick="testEmail()">📤 Odeslat</button>
          </div>
          <div id="ns-test-result" style="margin-top:8px;font-size:12px;"></div>
        </div>
      </div>

      <!-- Pravý sloupec ŘADY 2 — Uzávěrka NAD, Šablony e-mailů POD -->
      <div class="ns-stack">
        <div class="card-block">
          <h3 style="margin-bottom:8px;">⏰ Uzávěrka úprav objednávek</h3>
          <p style="font-size:12px;color:var(--text-3);margin-bottom:14px;">
            Do kdy může odběratel upravit / zrušit objednávku.
          </p>
          <div class="form-grid form-grid-tight">
            <div>
              <label class="form-label">Hodina uzávěrky</label>
              <input class="form-input" id="ns-uzaverka-h" type="number" min="0" max="23"
                     value="${esc(n.uzaverka_hodina || '18')}">
              <small style="color:var(--text-3);font-size:11px;">0–23 (18 = 18:00)</small>
            </div>
            <div>
              <label class="form-label">Dní před dodáním</label>
              <input class="form-input" id="ns-uzaverka-d" type="number" min="0" max="14"
                     value="${esc(n.uzaverka_dni_predem || '1')}">
              <small style="color:var(--text-3);font-size:11px;">0=stejný, 1=den před…</small>
            </div>
          </div>
          <p style="margin-top:10px;font-size:12px;background:var(--info-bg);color:var(--info-text);padding:8px 10px;border-radius:6px;">
            <strong>Příklad:</strong> „18 / 1“ = objednávku na úterý lze měnit do pondělí 18:00.
          </p>
        </div>

        <!-- 📝 Šablony e-mailů (přesunuto pod Uzávěrku — sdílí pravý sloupec) -->
        <div class="card-block">
          <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:6px">
            <h3 style="margin:0">📝 Šablony e-mailů</h3>
            <span style="font-size:11px;color:var(--text-3)">Lifecycle objednávky</span>
          </div>
          <p style="font-size:12px;color:var(--text-3);margin-bottom:12px;line-height:1.5">
            Předmět a tělo e-mailů odběratelům. Proměnné <code style="background:var(--surface-2);padding:1px 5px;border-radius:3px;font-size:10px">{cislo}</code>
            <code style="background:var(--surface-2);padding:1px 5px;border-radius:3px;font-size:10px">{datum}</code>
            <code style="background:var(--surface-2);padding:1px 5px;border-radius:3px;font-size:10px">{firma}</code>…
          </p>
          <div id="email-templates-container" style="display:flex;flex-direction:column;gap:6px">
            <div style="padding:20px;text-align:center;color:var(--text-3);font-size:12px">⏳ Načítám…</div>
          </div>
        </div>
      </div>
    </div>

    <!-- 📱 Push notifikace (PWA — zdarma, alternativa SMS) -->
    <div class="card-block" style="margin-top:14px">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:8px">
        <h3 style="margin:0">📱 Push notifikace <span style="font-size:12px;font-weight:400;background:var(--success-bg);color:var(--success-text);padding:2px 10px;border-radius:10px;margin-left:8px">ZDARMA</span></h3>
        <span style="font-size:12px;color:var(--text-3)">Web Push — odběratelé dostanou zprávu jako mobilní notifikaci</span>
      </div>
      <p style="font-size:13px;color:var(--text-2);margin-bottom:14px;line-height:1.5">
        Odběratel si přidá B2B aplikaci na home screen (banner v B2B se objeví automaticky) a klikne „Zapnout upozornění".
        Při změně stavu objednávky mu dorazí push — stejně jako SMS, ale <strong>zdarma navždy</strong>.
      </p>
      <div id="push-stats-host" style="font-size:13px;color:var(--text-3);padding:12px;background:var(--surface-2);border-radius:8px">
        ⏳ Načítám statistiku…
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
        <button class="btn-primary btn-green" onclick="pushSendTest()" style="font-size:14px;font-weight:700;padding:10px 18px">🧪 Test push (mně)</button>
        <button class="btn-secondary" onclick="pushSendTestAll()">📢 Test všem (${'pošle test všem subscriberům'})</button>
        <button class="btn-secondary" onclick="zapnoutPushAdmin()">🔔 Zapnout mně (admin)</button>
        <button class="btn-secondary" onclick="loadPushStats()">🔄 Obnovit</button>
      </div>
    </div>
  `;

  const blokPristupy = `
    <!-- 💱 MĚNA & PŘEPOČET (v3.0.283) -->
    <div class="card-block admin-only" id="ns-mena-block" style="margin-bottom:14px">
      <h3 style="margin-bottom:6px">💱 Měna & přepočet</h3>
      <p class="page-sub" style="margin-bottom:12px;font-size:12px">
        Ceny v databázi jsou v Kč. Zde nastavíš cílovou měnu a kurz — zobrazení se přepočítá v adminu, na pokladně i v B2B portálu; na doklady lze přidat informativní přepočet. Trvalý přepočet ceníku níže ceny skutečně přepíše.
      </p>
      <div id="ns-mena-body" style="font-size:13px;color:var(--text-3)">⏳ Načítám…</div>
    </div>

    <!-- ŘADA: Slevové skupiny + Uživatelé -->
    <div class="nastaveni-row">
      <div class="card-block admin-only">
        <h3 style="margin-bottom:6px;">🧾 Slevové skupiny</h3>
        <p class="page-sub" style="margin-bottom:14px;font-size:12px">
          Cenové hladiny pro odběratele — slevové sazby a pevné ceny. Přiřaďte konkrétním odběratelům.
        </p>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:auto">
          <button class="btn-primary" onclick="navigate('cenove_skupiny')">🧾 Spravovat</button>
        </div>
      </div>

      <div class="card-block admin-only">
        <h3 style="margin-bottom:6px;">🔑 Uživatelé administrace</h3>
        <p class="page-sub" style="margin-bottom:14px;font-size:12px">
          Přístupy do adminu — super admin, prodavač, výroba, expedice. Spravujte hesla a role.
        </p>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:auto">
          <button class="btn-primary" onclick="navigate('users')">🔑 Spravovat</button>
        </div>
      </div>
    </div>

    <!-- 📤 EXPORT KATALOGU VÝROBKŮ -->
    <div class="nastaveni-row" style="margin-top:14px">
      <div class="card-block">
        <h3 style="margin-bottom:6px;">📤 Export katalogu výrobků</h3>
        <p class="page-sub" style="margin-bottom:14px;font-size:12px">
          Stáhněte celý katalog výrobků pro <strong>účetní systémy</strong>, <strong>e-shop</strong>, <strong>Heureku/Zboží.cz</strong> nebo zálohu. Obsahuje: název, cenu, DPH, EAN, hmotnost, kategorii, popis, alergeny, obrázek.
        </p>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn-primary btn-green" onclick="exportVyrobku('xml')" title="Univerzální XML — pro e-shop, Heureku, Zboží.cz, Money S3">📄 XML</button>
          <button class="btn-primary" onclick="exportVyrobku('csv')" title="CSV — pro Excel, Google Sheets, Pohoda">📊 CSV</button>
          <button class="btn-secondary" onclick="exportVyrobku('json')" title="JSON — pro API integraci">{ } JSON</button>
          <button class="btn-secondary" onclick="exportVyrobkuHeureka()" title="XML feed pro Heureku.cz">🛒 Heureka XML</button>
        </div>
        <small style="display:block;margin-top:8px;color:var(--text-3);font-size:11px">
          💡 Stažený soubor obsahuje pouze <strong>aktivní</strong> výrobky. Hesla, vnitřní kódy a interní pole se neexportují.
        </small>
      </div>

      <div class="card-block">
        <h3 style="margin-bottom:6px;">📥 Hromadný import výrobků</h3>
        <p class="page-sub" style="margin-bottom:14px;font-size:12px">
          Naimportujte hromadně výrobky z CSV nebo XML — užitečné pro <strong>migraci z jiného systému</strong> nebo <strong>roční aktualizaci</strong>.
        </p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:auto">
          <button class="btn-primary" onclick="navigate('vyrobky');setTimeout(()=>{const b=document.querySelector('button[onclick*=\\'otevritImportVyrobku\\']');if(b)b.click();},200)" title="Otevře hromadný import v sekci Výrobky">📥 Importovat</button>
        </div>
        <small style="display:block;margin-top:8px;color:var(--text-3);font-size:11px">
          Otevře dialog v sekci Výrobky. Podporuje CSV i JSON formát.
        </small>
      </div>
    </div>
  `;

  // Doplníme do bloku Firma číselné řady + DPH + Uložit + info náhled
  const blokFirmaDoklady = blokFirma + `
    <!-- ČÍSELNÉ ŘADY -->
    <div class="card-block">
      <h3 style="margin-bottom:6px;">🔢 Číselné řady</h3>
      <p class="page-sub" style="margin-bottom:16px;">
        Předčíslí (text před číslem) se nemění. Počáteční číslo určuje, kde řada začne — další vystavený doklad dostane další číslo.
      </p>
      <div id="cislovani-container">
        <div style="text-align:center;padding:20px;color:var(--text-3);font-size:13px">Načítám…</div>
      </div>
    </div>

    <div class="card-block" style="margin-top:14px;background:var(--info-bg);border-color:var(--info-text);">
      <h3 style="margin-bottom:8px;color:var(--info-text);">💡 Náhled na faktuře</h3>
      <p style="font-size:13px;color:var(--text-2);">
        Po uložení nastavení se údaje automaticky zobrazí na všech nově generovaných fakturách a dodacích listech.
        Existující PDF se aktualizují při dalším otevření.
      </p>
    </div>
  `;

  const blokUdrzba = `
    <!-- 🌍 JAZYK APLIKACE -->
    <div class="card-block">
      <h3 style="margin-bottom:6px;">🌍 ${esc(t('settings_language'))}</h3>
      <p class="page-sub" style="margin-bottom:14px">
        Vybraný jazyk se uloží lokálně do prohlížeče (per-zařízení) a aplikuje okamžitě.
        Většina UI je přeložena; nepřeložené řetězce zůstávají v češtině.
      </p>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:8px">
        ${(window.appekLangs || []).map(l => {
          const aktivni = (window.appekCurrentLang || 'cs') === l.code;
          return `
            <button type="button" onclick="setAppekLang('${esc(l.code)}');renderNastaveni()" class="lang-card ${aktivni ? 'is-active' : ''}" style="display:flex;align-items:center;gap:10px;padding:14px 18px;background:var(--surface);border:2px solid ${aktivni ? 'var(--primary)' : 'var(--border)'};border-radius:10px;cursor:pointer;font-family:inherit;transition:all 0.15s;min-width:160px;font-size:14px">
              <span style="font-size:24px;line-height:1">${l.flag}</span>
              <div style="text-align:left;flex:1">
                <strong>${esc(l.label)}</strong>
                <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.05em">${esc(l.code)}</div>
              </div>
              ${aktivni ? '<span style="color:var(--success-text)">✓</span>' : ''}
            </button>
          `;
        }).join('')}
      </div>
    </div>

    <!-- 📃 Dlouhé seznamy + ⚡ Výkon — přesunuto níž (za Hromadný tisk), vedle sebe. v3.0.367 -->

    <!-- 🎨 VZHLED APLIKACE -->
    <div class="card-block" style="margin-top:14px">
      <h3 style="margin-bottom:6px;">🎨 Vzhled aplikace</h3>
      <p class="page-sub" style="margin-bottom:14px;">
        Změňte vzhled administrace. Funkčnost zůstává stejná, mění se jen barvy, fonty a tvary.
      </p>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px">
        ${[
          { id: 'default',   nazev: 'Moderní',     popis: 'Výchozí, zlatohnědé akcenty',         preview: 'linear-gradient(135deg, #FDFDFE 0%, #FBFCFD 50%, #BA7517 100%)' },
          { id: 'apple',     nazev: 'Apple',       popis: 'Clean, kulaté rohy, SF Pro, jemné stíny', preview: 'linear-gradient(135deg, #F5F5F7 0%, #FFFFFF 60%, #007AFF 100%)' },
          { id: 'win98',     nazev: 'Windows 98',  popis: 'Retro – šedé, zkosené tlačítka',      preview: 'linear-gradient(135deg, #C0C0C0 0%, #C0C0C0 60%, #000080 100%)' },
          { id: 'dark',      nazev: 'Tmavý',       popis: 'Pro práci večer / OLED',              preview: 'linear-gradient(135deg, #1a1a1a 0%, #2a2a2a 50%, #BA7517 100%)' },
        ].map(t => {
          const aktivni = getAppTheme() === t.id;
          return `
            <button type="button" onclick="setAppTheme('${t.id}')" class="theme-card ${aktivni ? 'is-active' : ''}" style="display:flex;flex-direction:column;align-items:stretch;gap:8px;padding:12px;background:var(--surface);border:2px solid ${aktivni ? 'var(--primary)' : 'var(--border)'};border-radius:10px;cursor:pointer;text-align:left;font-family:inherit;transition:all 0.15s">
              <div style="width:100%;height:60px;border-radius:6px;background:${t.preview};box-shadow:inset 0 1px 3px rgba(0,0,0,0.1);position:relative">
                ${aktivni ? '<span style="position:absolute;top:6px;right:6px;background:var(--primary);color:white;font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px">✓ Aktivní</span>' : ''}
              </div>
              <div>
                <div style="font-size:14px;font-weight:700;margin-bottom:2px">${esc(t.nazev)}</div>
                <div style="font-size:12px;color:var(--text-3)">${esc(t.popis)}</div>
              </div>
            </button>
          `;
        }).join('')}
      </div>
      <p style="margin-top:12px;font-size:12px;color:var(--text-3);background:var(--info-bg);padding:8px 10px;border-radius:6px">
        💡 <strong>Tip:</strong> Funkčnost zůstává nezměněna ve všech stylech. Stačí kliknout na variantu — změna je okamžitá a zapamatuje se.
      </p>

      <!-- 📏 HUSTOTA UI — kompaktní/pohodlné/prostorné -->
      <div style="margin-top:18px;padding-top:18px;border-top:1px dashed var(--border)">
        <h4 style="margin:0 0 4px;font-size:14px">📏 Hustota UI</h4>
        <p style="font-size:12px;color:var(--text-3);margin:0 0 12px;line-height:1.5">
          Velikost fontů, výplň tlačítek a rozestupy. Užitečné pro <strong>starší uživatele</strong> (Prostorné),
          nebo pokud chceš vidět <strong>víc dat naráz</strong> (Kompaktní).
        </p>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px">
          ${[
            { id: 'compact',     nazev: 'Kompaktní',  popis: 'Víc dat na obrazovku, menší fonty',           ikona: '🔬' },
            { id: 'comfortable', nazev: 'Pohodlné',   popis: 'Výchozí — vyvážená velikost',                  ikona: '😌' },
            { id: 'spacious',    nazev: 'Prostorné',  popis: 'Větší fonty, větší tlačítka',                 ikona: '🔎' },
            { id: 'extreme',     nazev: 'Extrémní',   popis: 'XXL — pro slabozraké / dotyk / kiosek',       ikona: '👴' },
          ].map(d => {
            const aktivni = getAppDensity() === d.id;
            return `
              <button type="button" onclick="setAppDensity('${d.id}')" class="density-card ${aktivni ? 'is-active' : ''}" style="display:flex;flex-direction:column;align-items:stretch;gap:6px;padding:12px;background:var(--surface);border:2px solid ${aktivni ? 'var(--primary)' : 'var(--border)'};border-radius:10px;cursor:pointer;text-align:left;font-family:inherit;transition:all 0.15s">
                <div style="display:flex;align-items:center;gap:8px">
                  <span style="font-size:24px;line-height:1">${d.ikona}</span>
                  <strong style="font-size:14px;flex:1">${esc(d.nazev)}</strong>
                  ${aktivni ? '<span style="background:var(--primary);color:white;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px">✓ Aktivní</span>' : ''}
                </div>
                <div style="font-size:11px;color:var(--text-3);line-height:1.4">${esc(d.popis)}</div>
              </button>
            `;
          }).join('')}
        </div>
      </div>

      <!-- 🆕 v3.0.190 — 📱 Rychlá akční tlačítka na mobilu (FAB) on/off -->
      <div style="margin-top:18px;padding-top:18px;border-top:1px dashed var(--border)">
        <h4 style="margin:0 0 4px;font-size:14px">📱 Rychlá tlačítka na mobilu</h4>
        <p style="font-size:12px;color:var(--text-3);margin:0 0 12px;line-height:1.5">
          Plovoucí akční tlačítko v rohu (Nová objednávka, Nový DL, Nová FA…) — <strong>swipni do strany</strong> pro skrytí. Zobrazuje se jen na mobilu / dotyku.
        </p>
        <label class="checkbox-row" style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--surface-2);border-radius:8px;cursor:pointer">
          <input type="checkbox" id="ns-fab-toggle" ${getAppFabEnabled() ? 'checked' : ''} onchange="setAppFabEnabled(this.checked)" style="width:20px;height:20px;cursor:pointer">
          <span style="flex:1">
            <strong style="font-size:14px">📱 Zobrazit rychlá tlačítka</strong>
            <div style="font-size:12px;color:var(--text-3);margin-top:2px">Plovoucí rychlá akce na mobilu (swipovací). Vypni, pokud ho nechceš zobrazovat.</div>
          </span>
          <span id="ns-fab-status" style="font-size:12px;font-weight:600;padding:4px 10px;border-radius:12px;${getAppFabEnabled() ? 'background:var(--success-bg);color:var(--success-text)' : 'background:#FEE2E2;color:#7F1D1D'}">${getAppFabEnabled() ? '✓ Zapnuto' : '✕ Vypnuto'}</span>
        </label>
      </div>
    </div>

    <!-- 🆕 v3.0.139 — 2 sloupce: Bezpečnost | Hromadný tisk -->
    <div class="nastaveni-row" style="margin-top:14px">
    <!-- 🔐 BEZPEČNOST & POTVRZOVÁNÍ -->
    <div class="card-block">
      <h3 style="margin-bottom:6px;">🔐 Bezpečnost & potvrzování</h3>
      <p class="page-sub" style="margin-bottom:14px;">
        Ochrana před omylem provedenými mazacími akcemi. Vypnete-li toto, smazání proběhne po jediném potvrzení.
      </p>
      <div class="form-grid">
        <div class="full">
          <label class="checkbox-row" style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--surface-2);border-radius:8px;cursor:pointer">
            <input type="checkbox" id="ns-confirm-2x" ${getConfirmDelete2xEnabled() ? 'checked' : ''} onchange="setConfirmDelete2xEnabled(this.checked)" style="width:20px;height:20px;cursor:pointer">
            <span style="flex:1">
              <strong style="font-size:14px">🗑️ Dvojité potvrzení smazání</strong>
              <div style="font-size:12px;color:var(--text-3);margin-top:2px">
                Po kliknutí na 🗑️ se zobrazí <strong>dvě</strong> potvrzovací okna místo jednoho. Doporučeno pro ostrý provoz.
              </div>
            </span>
            <span id="ns-confirm-2x-status" style="font-size:12px;font-weight:600;padding:4px 10px;border-radius:12px;${getConfirmDelete2xEnabled() ? 'background:var(--success-bg);color:var(--success-text)' : 'background:#FEE2E2;color:#7F1D1D'}">${getConfirmDelete2xEnabled() ? '✓ Zapnuto' : '✕ Vypnuto'}</span>
          </label>
        </div>
      </div>
    </div>

    <!-- 🖨️ HROMADNÝ TISK -->
    <div class="card-block">
      <h3 style="margin-bottom:6px;">🖨️ Hromadný tisk</h3>
      <p class="page-sub" style="margin-bottom:14px;">Plovoucí tlačítko „Tisknout vše" v rohu obrazovky — dávkový tisk více dokladů najednou.</p>
      <div class="form-grid">
        <div class="full">
          <label class="checkbox-row" style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--surface-2);border-radius:8px;cursor:pointer">
            <input type="checkbox" id="ns-print-all" ${getPrintAllEnabled() ? 'checked' : ''} onchange="setPrintAllEnabled(this.checked)" style="width:20px;height:20px;cursor:pointer">
            <span style="flex:1">
              <strong style="font-size:14px">🖨️ Tlačítko „Tisknout vše"</strong>
              <div style="font-size:12px;color:var(--text-3);margin-top:2px">Vypni, pokud dávkový tisk nepoužíváš — plovoucí tlačítko se skryje.</div>
            </span>
            <span id="ns-print-all-status" style="font-size:12px;font-weight:600;padding:4px 10px;border-radius:12px;${getPrintAllEnabled() ? 'background:var(--success-bg);color:var(--success-text)' : 'background:#FEE2E2;color:#7F1D1D'}">${getPrintAllEnabled() ? '✓ Zapnuto' : '✕ Vypnuto'}</span>
          </label>
        </div>
      </div>
    </div>

    </div>

    <!-- 📃 Dlouhé seznamy + ⚡ Výkon — vedle sebe, přesunuto sem (v3.0.367: „dolů, ale ne úplně") -->
    <div class="nastaveni-row" style="margin-top:14px">
      <div class="card-block">
        <h3 style="margin-bottom:6px;">📃 Dlouhé seznamy</h3>
        <p class="page-sub" style="margin-bottom:14px;">Jak načítat dlouhé seznamy (Objednávky, Faktury, Dodací listy, POS Účtenky) při velkém počtu záznamů. <span style="color:var(--text-3)">Počet řádků platí všude; styl načítání pro admin seznamy (POS vždy „Načíst další").</span></p>
        <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
          <div>
            <label class="form-label" style="font-size:12px">Styl načítání</label>
            <select class="form-select" id="ns-pagination" style="max-width:340px">
              <option value="load_more" ${(n.pagination_styl || 'load_more') === 'load_more' ? 'selected' : ''}>▾ Načíst další (tlačítko)</option>
              <option value="stranky" ${n.pagination_styl === 'stranky' ? 'selected' : ''}># Stránkování (čísla stránek)</option>
              <option value="infinite" ${n.pagination_styl === 'infinite' ? 'selected' : ''}>∞ Nekonečné scrollování</option>
            </select>
          </div>
          <div>
            <label class="form-label" style="font-size:12px">Řádků na stránku</label>
            <select class="form-select" id="ns-pag-pocet" style="max-width:160px">
              ${[10, 25, 50, 100, 200].map(p => `<option value="${p}" ${(parseInt(n.pagination_pocet) || 10) === p ? 'selected' : ''}>${p} řádků</option>`).join('')}
            </select>
          </div>
        </div>
        <p style="font-size:11px;color:var(--text-3);margin-top:10px">Uloží se tlačítkem „💾 Uložit nastavení" dole. Platí pro všechna zařízení.</p>
      </div>

      <div class="card-block">
        <h3 style="margin-bottom:6px;">⚡ Výkon</h3>
        <p class="page-sub" style="margin-bottom:14px;">Odlehčený režim vypne animace, stíny a rozostření — aplikace je svižnější na slabších zařízeních a starších mobilech. Vzhled je plošší, funkce zůstávají stejné.</p>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:600">
          <input type="checkbox" id="ns-perf-lite" ${n.vykon_lite === '1' ? 'checked' : ''}
            onchange="document.body.classList.toggle('perf-lite', this.checked); try{localStorage.setItem('appek_perf_lite', this.checked?'1':'0')}catch(e){}"
            style="width:18px;height:18px;cursor:pointer">
          Odlehčený režim <span style="color:var(--text-3);font-weight:400">(rychlejší na slabších zařízeních)</span>
        </label>
        <p style="font-size:11px;color:var(--text-3);margin-top:10px">Projeví se hned (toto zařízení) · „💾 Uložit nastavení" dole uloží pro všechna zařízení.</p>
      </div>
    </div>

    <!-- 🆕 v3.0.139 — 2 sloupce: API | Chyby aplikace -->
    <div class="nastaveni-row" style="margin-top:14px">
    <!-- 🔌 API TOKENY pro účetní systémy -->
    <div class="card-block">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:8px">
        <h3 style="margin:0">🔌 API pro účetní systémy <span style="font-size:11px;font-weight:400;background:var(--info-bg);color:var(--info-text);padding:2px 8px;border-radius:6px;margin-left:6px">REST v1</span></h3>
        <button class="btn-primary btn-green" onclick="apiTokenNew()" style="font-size:13px;padding:8px 14px">+ Nový token</button>
      </div>
      <p style="font-size:13px;color:var(--text-2);margin-bottom:12px;line-height:1.5">
        Vytvořte token pro účetní (Money S3 / POHODA / Fakturoid). Účetní stáhne FA přes <code style="background:var(--surface-2);padding:1px 6px;border-radius:4px;font-size:11px">GET /api/v1/faktury?token=...</code>.
        <a href="../api/v1/" target="_blank" style="color:var(--primary);text-decoration:underline">📖 Dokumentace API ↗</a>
      </p>
      <div id="api-tokens-list" style="font-size:13px;color:var(--text-3)">⏳ Načítám…</div>
    </div>
    <!-- 🐛 CHYBY APLIKACE (v páru s API — v3.0.139) -->
    <div class="card-block" id="ns-errors-block">
      <h3 style="margin-bottom:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        🐛 Chyby aplikace
        <span id="ns-errors-overall" style="font-size:11px;font-weight:normal;color:var(--text-3)"></span>
      </h3>
      <p style="font-size:12px;color:var(--text-3);margin-bottom:14px">
        Server-side chyby z PHP backendu (json_error_safe) — persistované v <code>app_errors</code> DB.
        Když ti user řekne <em>"rozbité, reqId: abc123"</em>, najdi ho zde (search).
      </p>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;align-items:center">
        <input id="ns-errors-search" placeholder="Hledat (request_id, zpráva, source)…" oninput="debounce('errsearch', errorsLoad, 350)" style="flex:1;min-width:220px;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;font-family:inherit">
        <select id="ns-errors-since" onchange="errorsLoad()" style="padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px" title="Časové okno">
          <option value="1">Posledních 1 h</option>
          <option value="24" selected>24 h</option>
          <option value="168">7 dní</option>
          <option value="720">30 dní</option>
        </select>
        <button class="btn-secondary" onclick="errorsLoad()" style="font-size:13px;padding:7px 14px">🔄 Načíst</button>
      </div>
      <div id="ns-errors-list" style="font-size:13px;color:var(--text-3)">⏳ Načítám…</div>
    </div>
    </div>

    <!-- 💾 ZÁLOHY + 🩺 DIAGNOSTIKA — vedle sebe -->
    <div class="nastaveni-row" style="margin-top:14px">

      <div class="card-block" id="ns-zalohy-block">
        <h3 style="margin-bottom:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          💾 Zálohy databáze
          <span id="ns-zalohy-overall" style="font-size:11px;font-weight:normal;color:var(--text-3)"></span>
        </h3>
        <p style="font-size:12px;color:var(--text-3);margin-bottom:14px">
          SQL dump celé databáze (volitelně i <code>/uploads</code>). Ukládá se na server, můžeš stáhnout, obnovit.
        </p>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
          <button class="btn-primary btn-green" onclick="zalohaVytvorit(false)">💾 Zálohu DB</button>
          <button class="btn-secondary" onclick="zalohaVytvorit(true)">📦 DB + uploads</button>
          <button class="btn-secondary" onclick="zalohaInfo()" title="Info o CRON / místě / posledních zálohách">ℹ️ Info & CRON</button>
          <button class="btn-secondary" onclick="zalohyRefresh()" title="Obnovit seznam">🔄</button>
        </div>

        <div id="ns-zalohy-list" style="font-size:13px;color:var(--text-3)">⏳ Načítám…</div>
      </div>

      <div class="card-block" id="ns-diag-block">
        <h3 style="margin-bottom:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          🩺 Diagnostika
          <span id="ns-diag-overall" style="font-size:11px;font-weight:normal;color:var(--text-3)"></span>
        </h3>
        <p style="font-size:12px;color:var(--text-3);margin-bottom:14px">
          Stav serveru, DB, schématu, endpointů a logů. Otevři detail pro podrobnosti.
        </p>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
          <button class="btn-primary btn-green" onclick="diagOtevrit()">🩺 Otevřít detail</button>
          <button class="btn-secondary" onclick="healthRunNow()" title="🆕 v2.9.322 — synthetic healthcheck (DB, schema, disk, write, error rate)">🫀 Test zdraví</button>
          <button class="btn-secondary" onclick="diagLint()" title="Projeď všechny PHP soubory v api/">🔬 Lint API</button>
          <button class="btn-secondary" onclick="diagPingMail()" title="Test mailu">✉️ Test mail</button>
          <button class="btn-secondary" onclick="diagRychly()" title="Načíst znovu">🔄</button>
        </div>

        <div id="ns-diag-summary" style="font-size:13px;color:var(--text-3)">⏳ Načítám…</div>
        <div id="ns-health-result" style="margin-top:10px;font-size:12px"></div>
      </div>

    </div>

    <!-- 🐛 Chyby aplikace — PŘESUNUTO nahoru do páru s API (v3.0.139) -->

    <!-- 🆕 v3.0.358 — Doklady: zámek mazání vydaných dokladů -->
    <div class="nastaveni-row" style="margin-top:14px">
      <div class="card-block" id="ns-doklady-block">
        <h3 style="margin-bottom:6px;display:flex;align-items:center;gap:8px">📄 Doklady</h3>
        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:13px">
          <input type="checkbox" ${n.faktura_zamknout_mazani === '0' ? '' : 'checked'} onchange="toggleZamknoutMazani(this)" style="width:18px;height:18px;margin-top:1px;flex-shrink:0;accent-color:var(--primary)">
          <span><strong>Zamknout mazání vydaných dokladů</strong> (faktury, dodací listy)<br>
          <span style="color:var(--text-3)">Doporučeno zapnuto — číselná řada dokladů musí být souvislá (zákon). Opravy řešte storno/dobropisem. Vypnutím umožníte trvalé smazání = díra v řadě.</span></span>
        </label>
      </div>
    </div>

    <!-- 🆕 v3.0.160 — Licence & aktualizace + Activity log vedle sebe (2 sloupce) -->
    <div class="nastaveni-row" style="margin-top:14px">
    <!-- 🔑 LICENCE & AKTUALIZACE -->
    <div class="card-block" id="ns-license-block">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:6px">
        <h3 style="margin:0;display:flex;align-items:center;gap:8px">
          🔑 Licence &amp; aktualizace
          <span id="ns-license-badge" style="font-size:11px;font-weight:500;background:var(--surface-2);color:var(--text-3);padding:3px 10px;border-radius:999px">⏳</span>
        </h3>
        <div style="display:flex;gap:6px">
          <button class="btn-secondary" onclick="checkForUpdates()" style="font-size:12px;padding:6px 12px" title="Zkontrolovat dostupnost nové verze">🔄 Zkontrolovat aktualizace</button>
        </div>
      </div>
      <div id="ns-license-info" style="font-size:13px;color:var(--text-3)">⏳ Načítám…</div>
    </div>
    <!-- 📜 ACTIVITY LOG (přesunuto sem do páru s Licencí) -->
    <div class="card-block" id="ns-activity-block">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:6px">
        <h3 style="margin:0;display:flex;align-items:center;gap:8px">📜 Activity log</h3>
        <button class="btn-secondary" onclick="loadActivityLog()" style="font-size:12px;padding:6px 12px">🔄 Refresh</button>
      </div>
      <p style="font-size:12px;color:var(--text-3);margin:0 0 12px;line-height:1.5">
        Posledních 5 akcí v aplikaci — login pokusy, sync operace, audit změn. Pro starší klikni na „Zobrazit více".
      </p>
      <div id="ns-activity-list" style="font-size:13px">⏳ Načítám…</div>
    </div>
    </div>

    <!-- 🆕 v3.0.139 — 2 sloupce: Bezpečnostní list | Webhooks -->
    <div class="nastaveni-row" style="margin-top:14px">
    <!-- 📋 BEZPEČNOSTNÍ LIST / CHEAT SHEET — print-ready -->
    <div class="card-block" id="ns-cheatsheet-block">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:6px">
        <h3 style="margin:0;display:flex;align-items:center;gap:8px">
          📋 Bezpečnostní list
          <span style="font-size:11px;font-weight:500;background:rgba(34,197,94,0.15);color:#15803d;padding:3px 10px;border-radius:999px">📌 Vytiskni a uschovej</span>
        </h3>
        <div style="display:flex;gap:6px">
          <button class="btn-secondary" onclick="window.openCheatSheet()" style="font-size:12px;padding:6px 12px">📋 Zobrazit</button>
          <button class="btn-secondary" onclick="window.printCheatSheet()" style="font-size:12px;padding:6px 12px">🖨️ Tisk / PDF</button>
        </div>
      </div>
      <p style="font-size:12.5px;color:var(--text-3);margin:0;line-height:1.5">
        Kompletní instrukce pro tebe i tvoje kolegy — co se kde nachází, jak se přihlásit, kam jdou data, koho volat když něco nefunguje. <strong>Vytiskni a pověs vedle počítače.</strong>
      </p>
    </div>

    <!-- 🔄 WEBHOOKS -->
    <div class="card-block" id="ns-webhooks-block">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:6px">
        <h3 style="margin:0;display:flex;align-items:center;gap:8px">🔄 Webhooks <span style="font-size:11px;font-weight:500;color:var(--text-3)">(out-going HTTP)</span></h3>
        <div style="display:flex;gap:6px">
          ${adminOnly('<button class="btn-secondary" onclick="openWebhookEdit()" style="font-size:12px;padding:6px 12px">+ Nový webhook</button>')}
          <button class="btn-secondary" onclick="loadWebhooks()" style="font-size:12px;padding:6px 12px">🔄</button>
        </div>
      </div>
      <p style="font-size:12px;color:var(--text-3);margin:0 0 12px;line-height:1.5">
        Out-going HTTP volání pro účetní systémy (Money S3, POHODA, Stormware), CRM, Slack/Discord, atd. Při zvoleném eventu Appek POST JSON na tvoji URL.
      </p>
      <div id="ns-webhooks-list" style="font-size:13px">⏳ Načítám…</div>
    </div>
    </div>

    <!-- 📜 ACTIVITY LOG — přesunuto nahoru do páru s Licencí (v3.0.160) -->

    <!-- ☁️ SYNC S CLOUDEM — Phase 3 -->
    <div class="card-block" id="ns-sync-block" style="margin-top:14px">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:6px">
        <h3 style="margin:0;display:flex;align-items:center;gap:8px">
          ☁️ Sync s cloudem <span id="ns-sync-mode-badge" style="font-size:11px;font-weight:500;background:var(--surface-2);color:var(--text-3);padding:3px 10px;border-radius:999px"></span>
        </h3>
        <div style="display:flex;gap:6px">
          <button class="btn-secondary" onclick="syncRunNow()" style="font-size:12px;padding:6px 12px" title="Spustit sync ručně teď">🔄 Sync teď</button>
          <button class="btn-secondary" onclick="syncOpenConfig()" style="font-size:12px;padding:6px 12px">⚙️ Konfigurace</button>
        </div>
      </div>
      <p style="font-size:12px;color:var(--text-3);margin:0 0 12px;line-height:1.5">
        Hybrid sync: lokální PC ↔ cloud. Push změn každých 15 minut + manual trigger. Pro provoz na slabém internetu (vesnice).
      </p>
      <div id="ns-sync-status" style="font-size:13px;color:var(--text-3)">⏳ Načítám…</div>
    </div>

    <!-- 🆕 v2.9.320 — Demo data PŘESUNUTO na konec sekce + skryto v <details>
         (předtím bylo nahoře jako velký prominentní card → user mohl omylem
         kliknout RESET a smazat všechna ostrá data). Teď je to schované,
         vyžaduje vědomé rozbalení. Stále plně funkční, jen méně risk. -->
    <details class="card-block" style="margin-top:24px;background:#FAFAFA;border:1px dashed var(--border)">
      <summary style="cursor:pointer;padding:6px 0;font-size:13px;font-weight:600;color:var(--text-2);user-select:none;list-style:none;display:flex;align-items:center;gap:8px">
        <span style="font-size:18px">🧪</span>
        <span>Demo data — naplnit / resetovat (pokročilé)</span>
        <span style="margin-left:auto;font-size:11px;color:var(--text-3);font-weight:400">▾ rozbalit</span>
      </summary>
      <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
        <p style="font-size:13px;color:var(--text-2);margin-bottom:14px;line-height:1.5">
          Naplnit aplikaci ukázkovými daty (10 výrobků, 35+ surovin, recepty, kalkulace, objednávky, POS users, stoly…) nebo začít čistě.
        </p>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <button class="btn-secondary" onclick="openDemoSeed()" style="padding:9px 16px;font-size:13px">
            🎬 Naplnit demo daty
          </button>
          <!-- 🆕 v3.0.139 — restaurační seed přesunut SEM z Restaurace (jediné bezpečné místo).
               Idempotentní (existující se zachová), ale schované v <details> = nejde kliknout omylem. -->
          <button class="btn-secondary" onclick="seedRestaurantPack()" style="padding:9px 16px;font-size:13px"
                  title="Naseed restaurační/pizzerie demo (6 kategorií, 18 surovin, 11 výrobků, recepty) — idempotentní">
            🍕 Restaurační demo
          </button>
          <button class="btn-secondary" onclick="resetDemoSeed()"
                  style="padding:9px 16px;font-size:13px;background:#FEE2E2;color:#991B1B;border-color:#FECACA"
                  title="⚠️ Smaže VŠECHNA data — systém zůstane prázdný (vyžaduje 2× confirm + typed input)">
            🗑️ Smazat vše
          </button>
        </div>
        <div style="margin-top:12px;padding:10px 12px;background:#FEF3C7;border-left:3px solid #F59E0B;border-radius:6px;font-size:12px;color:#854F0B;line-height:1.5">
          ⚠️ <strong>Pozor:</strong> Reset smaže VŠECHNA data včetně ručních úprav (objednávky, faktury, výrobky, zákazníci…). Použij jen pokud chceš začít s čistou databází na testovacím prostředí.
        </div>
      </div>
    </details>
  `;

  // ❓ FAQ blok — dvousloupcový grid (na mobilu jeden sloupec)
  const blokNapoveda = `
    <div class="card-block" style="margin-bottom:14px">
      <h3 style="margin:0 0 8px">❓ Vítejte v nápovědě</h3>
      <p style="font-size:13px;color:var(--text-2);line-height:1.6;margin:0">
        Tady najdete odpovědi na nejčastější otázky, návody jak používat hlavní funkce a tipy pro řešení problémů.
        Pokud něco chybí, kontaktujte dodavatele systému.
      </p>
    </div>

    <!-- 🆕 v3.0.185 — YouTube video návody ODSTRANĚNY z Nápovědy (user: „youtube z nápovědy pryč") -->

    <div class="faq-grid">
    ${[
      { q: '🎯 Začínám — co první?', a: `
          <p>Po prvním přihlášení vás uvítá <strong>Onboarding wizard</strong> (6 kroků). Pokud jste ho přeskočili, spusťte ho znovu v konzoli: <code>restartOnboarding()</code></p>
          <ol style="line-height:1.8">
            <li><strong>Údaje firmy</strong> — Nastavení → 🏢 Firma & doklady → vyplňte IČO a klikněte „🔍 Načíst" pro auto-fill z 🇨🇿 ARES nebo 🇸🇰 RPO (Slovenský register právnických osôb)</li>
            <li><strong>Logo</strong> — nahrajte logo (favicon se generuje automaticky)</li>
            <li><strong>Výrobky</strong> — Výrobky → + Nový (nebo využijte vzorová data z onboardingu)</li>
            <li><strong>Odběratelé</strong> — Odběratelé → + Nový (pozvánka jim přijde emailem)</li>
            <li><strong>Test objednávka</strong> — Objednávky → + Nová → vyzkoušejte celý flow</li>
          </ol>`
      },
      { q: '📦 Jak funguje skladová evidence surovin?', a: `
          <p>U každé suroviny můžete nastavit <strong>minimální hladinu</strong> + <strong>cílovou hladinu</strong> (Suroviny → upravit).
          Když stav klesne pod minimum, surovina se podbarví červeně a objeví se alert.</p>
          <ul style="line-height:1.8">
            <li><strong>Naskladnit</strong> (příjem dodávky): klik na 📦 ikonu u suroviny → Příjem → kg</li>
            <li><strong>Odepsat</strong>: ručně Výdej, nebo automaticky z Výrobního listu („Vyrobeno → odepsat suroviny")</li>
            <li><strong>Inventura</strong>: nastaví stav přesně na zadanou hodnotu (s audit zápisem)</li>
            <li><strong>Přehled</strong>: Nastavení → 🥖 Výroba → 📦 Otevřít sklad — uvidíte hodnotu skladu + co je pod minimem</li>
          </ul>`
      },
      { q: '🔁 Opakující se objednávky — jak nastavit?', a: `
          <p>Pro stálé zákazníky vytvořte pravidlo: Nastavení → 🥖 Výroba → 🔁 Opakující se objednávky → + Nové pravidlo.</p>
          <p>Frekvence: <strong>denně / týdně / každé 2 týdny / měsíčně</strong>. Vyberete dny v týdnu a položky.</p>
          <p><strong>Spouští se cronem každý den ráno</strong> — vytvoří objednávky na zítřejší den.
          Pokud váš hosting nemá cron, nastavte přes web s tokenem (Nastavení → klíč <code>cron_token</code>) a volejte
          <code>https://vase-domena.cz/api/cron_recurring.php?token=...</code> z jiného serveru / Zapier / IFTTT.</p>
          <p>Anti-duplikát zaručí, že se obj nevytvoří 2× pro stejný den.</p>`
      },
      { q: '🛣️ Rozvozové trasy — jak na to?', a: `
          <p>Sidebar → 🛣️ Rozvozové trasy → vyberte den (Dnes / Zítra / kalendář).
          DL se seskupí <strong>podle města/PSČ</strong> a seřadí. Klik na <strong>🖨️ Tisk rozvozového listu</strong> vytiskne A4 list pro řidiče s pořadovými čísly zastávek, adresami, časy a kontakty.</p>`
      },
      { q: '🏷️ Cenovky — jak vytvořit a vytisknout?', a: `
          <p>Štítky a cenovky → Cenovky z výrobků:</p>
          <ol style="line-height:1.8">
            <li>Vyberte <strong>formát papíru</strong> (Avery, Printky, SEVT…)</li>
            <li>Vyberte <strong>design</strong> (15 hotových — Hero, Minimalist, Premium…)</li>
            <li>Zaškrtněte výrobky</li>
            <li>Klik 🖨️ Tisk — otevře se PDF pro tisk</li>
          </ol>
          <p>Pro <strong>vlastní design</strong>: Editor šablon → drag&drop prvků na štítek → uložit.</p>`
      },
      { q: '📧 E-mailové šablony — jak upravit?', a: `
          <p>Nastavení → 📧 Notifikace → 📝 Šablony e-mailů → ✏️ Upravit u jakékoliv šablony.</p>
          <p>Můžete přepnout mezi <strong>📝 Plain text</strong> a <strong>🎨 HTML</strong>.
          V HTML módu máte 8 hotových designů (🎲 Náhodný design nebo 🎨 Vybrat design).</p>
          <p>Proměnné: <code>{cislo}</code>, <code>{datum}</code>, <code>{firma}</code>, <code>{castka_celkem}</code>, <code>{polozky_text}</code> atd. — klikni v editoru na proměnnou pro vložení.</p>`
      },
      { q: '📱 Push notifikace — jak je zapnout?', a: `
          <p>PWA push fungují <strong>pouze přes HTTPS</strong>.</p>
          <p><strong>Admin (provoz):</strong> Nastavení → Notifikace → 📱 Push notifikace → klik <strong>„🔔 Zapnout mně"</strong> → klikni Test push.</p>
          <p><strong>Odběratel:</strong> Otevře B2B aplikaci v Chrome/Safari → po 3 sekundách se objeví zlatý banner „🔔 Zapnout upozornění?" → souhlasit.</p>
          <p>Po souhlasu dostává push při změně stavu objednávky (expedována, doručena...).</p>
          <p>iOS Safari vyžaduje iOS 16.4+. Starší iPhony bez push.</p>`
      },
      { q: '🔌 API pro účetní systémy — jak ho zapojit?', a: `
          <p>Nastavení → 🛠️ Údržba → 🔌 API tokeny → + Nový token (zadejte název „Money S3").</p>
          <p>Zkopírujte token (vidíte ho jen 1×!) a zadejte do účetního SW URL:</p>
          <pre style="background:var(--surface-2);padding:10px;border-radius:6px;font-size:11px;overflow-x:auto">${esc(location.origin)}/api/v1/faktury?token=VÁŠ_TOKEN&od=2026-01-01&do=2026-12-31</pre>
          <p>Účetní stáhne všechny faktury jako JSON nebo ISDOC. Dokumentace: <a href="../api/v1/" target="_blank" style="color:var(--primary)">/api/v1/</a></p>`
      },
      { q: '🧾 Slevové skupiny / ceníky — jak fungují?', a: `
          <p>Nastavení → 👥 Přístupy & ceny → 🧾 Slevové skupiny.</p>
          <p>Každý odběratel může patřit do <strong>jednoho ceníku</strong>. Ceník má:</p>
          <ul style="line-height:1.8">
            <li><strong>Globální sleva %</strong> — pro celý sortiment (např. -3 %)</li>
            <li><strong>Minimum objednávky</strong> — Kč limit pro B2B košík</li>
            <li><strong>Splatnost FA</strong> — dní pro tuto skupinu</li>
            <li><strong>Specifická pravidla</strong> — sleva per kategorie / per výrobek (přebíjí globální)</li>
          </ul>
          <p>Priorita: <strong>výrobek → kategorie → sortiment pravidlo → globální → základní cena</strong>. Vyhrává nejspecifičtější.</p>`
      },
      { q: '💾 Zálohy databáze — jak a kdy?', a: `
          <p>Nastavení → 🛠️ Údržba → 💾 Zálohy databáze:</p>
          <ul style="line-height:1.8">
            <li><strong>💾 Zálohu DB</strong> — SQL dump databáze (rychlé)</li>
            <li><strong>📦 DB + uploads</strong> — DB + obrázky výrobků (větší)</li>
            <li><strong>ℹ️ Info & CRON</strong> — návod jak nastavit auto-zálohy</li>
          </ul>
          <p>Doporučujeme: <strong>denní DB záloha</strong> + <strong>týdenní DB+uploads</strong>. Stáhněte si je občas na flash disk.</p>`
      },
      { q: '🎨 Témata — jak změnit vzhled?', a: `
          <p>Nastavení → 🛠️ Údržba → 🎨 Vzhled aplikace. K dispozici 4 témata:</p>
          <ul style="line-height:1.8">
            <li><strong>☀️ Moderní</strong> (default) — světlé, zlato-hnědé</li>
            <li><strong>🍎 Apple</strong> — clean, kulaté rohy, SF Pro font, jemné stíny</li>
            <li><strong>🪟 Windows 98</strong> — retro stříbrné s 3D tlačítky 🤘</li>
            <li><strong>🌙 Tmavý</strong> — OLED-friendly</li>
          </ul>
          <p>Plus <strong>📏 Hustota UI</strong> (Kompaktní/Pohodlné/Prostorné/Extrémní) pro velikost fontů a tlačítek.</p>
          <p>Změna je okamžitá, ukládá se do prohlížeče (per zařízení).</p>`
      },
      { q: '📈 Sales report — jak ho stáhnout?', a: `
          <p>Nastavení → 🥖 Výroba → 📊 Otevřít Sales report → vyberte období.</p>
          <p>Uvidíte:</p>
          <ul style="line-height:1.8">
            <li>Celkovou tržbu s/bez DPH</li>
            <li>TOP 10 výrobků (kus, tržba, podíl %)</li>
            <li>TOP 10 odběratelů</li>
            <li>Tržbu podle kategorií (s progress bary)</li>
          </ul>
          <p>Klik <strong>🖨️ Stáhnout / vytisknout</strong> → A4 PDF přes prohlížeč.</p>`
      },
      { q: '🛡️ Bezpečnost a 2× potvrzení mazání', a: `
          <p>Nastavení → 🛠️ Údržba → 🔐 Bezpečnost & potvrzování → zapnuté default.</p>
          <p>Po kliknutí na 🗑️ se zobrazí <strong>2 potvrzovací okna</strong> místo jednoho — ochrana proti omylem provedeným mazáním.</p>
          <p>Pokud vás zdržuje, můžete vypnout (ale doporučujeme pro ostrý provoz nechat).</p>`
      },
      { q: '🚨 Něco nefunguje — kam se podívat?', a: `
          <p>Nastavení → 🛠️ Údržba → 🩺 Diagnostika → klik <strong>🩺 Otevřít detail</strong>.</p>
          <p>Uvidíte stav: DB připojení, PHP extensions, mail funkce, schéma DB, endpointy, kolize funkcí.</p>
          <p>Pokud něco svítí <strong>červeně</strong> → kontaktujte hostingového administrátora s tím konkrétním textem.</p>
          <p>Časté problémy:</p>
          <ul style="line-height:1.8">
            <li><strong>Emaily nechodí</strong> → na Hostingeru zapnout mail v cPanelu, ověřit odesílací adresu</li>
            <li><strong>Push nefunguje</strong> → potřeba HTTPS + iOS 16.4+ / moderní Chrome / Firefox</li>
            <li><strong>Upload nejde</strong> → zkontroluj <code>upload_max_filesize</code> + zápisová oprávnění <code>uploads/</code></li>
            <li><strong>Pomalé načítání</strong> → zkontroluj indexy v DB (Diagnostika to ohlásí)</li>
          </ul>`
      },
      { q: '🚀 Jak nasadit na produkční hosting?', a: `
          <p><strong>1. Stáhněte si ZIP</strong> celé aplikace (váš dodavatel).</p>
          <p><strong>2. Nahrajte na hosting</strong> přes FTP / FileManager / SSH.</p>
          <p><strong>3. Vytvořte MySQL databázi</strong> v hosting panelu.</p>
          <p><strong>4. Otevřete <code>vase-domena.cz/install.php</code></strong> — wizard vás provede.</p>
          <p><strong>5. Po instalaci smažte <code>install.php</code></strong>.</p>
          <p>Detailní návod ve souboru <strong>README.md</strong> v rootu aplikace.</p>`
      },

      // 🆕 v3.0.7 — Restaurace / POS / KDS / Výdej průvodci
      { q: '🧾 POS Kasa — jak začít prodávat?', a: `
          <p><strong>1. Otevři POS</strong> — Restaurace → 🧾 POS Kasa (nebo přímo <code>/admin/pos.php</code> na druhém monitoru).</p>
          <p><strong>2. Přihlaš se PINem</strong> — každý prodavač má 4-místný PIN. Spravuj přístupy v Nastavení → 👥 Přístupy.</p>
          <p><strong>3. Vyber typ objednávky</strong> nahoře (Hotově / Karta / Sebou / Vyzvednutí / Rozvoz / Na místě).</p>
          <p><strong>4. Klikni na produkt</strong> vlevo → přidá se do košíku vpravo. Plus/minus mění množství.</p>
          <p><strong>5. Volná položka</strong> — pro netradiční prodej (např. dárek, korkovné) klikni "+ Volná položka", vyplň název a cenu.</p>
          <p><strong>6. Klik FINISH</strong> → uloží účtenku + auto-rozešle bony na kuchyň/bar (pokud máš nastavené tiskárny).</p>
          <p><strong>7. Reprint / detail</strong> — klikni na účtenku v historii → modal s položkami + tlačítka Reprint, QR platba, Upravit.</p>
          <p><strong>Tip:</strong> Velikost FINISH tlačítka je tak velká protože hodně lidí klikne během provozu. Big mode v 1441px+ widthu pro tablety.</p>`
      },
      { q: '👨‍🍳 KDS (Kuchyňský displej) + 📤 Výdej — jak to chodí v kuchyni?', a: `
          <p><strong>Restaurační workflow má 2 displeje:</strong></p>
          <p><strong>1. KDS</strong> (oranžová sunrise) <code>/admin/kds.php</code> — pro kuchaře.</p>
          <ul style="line-height:1.8">
            <li>Karta na účet, klik na položku posune stav: <strong>objednáno → vaří se → hotovo</strong></li>
            <li>"✓ Vše hotovo" = označí celou objednávku</li>
            <li>Klik widgetu "Vaří se" = filtruj jen vařící</li>
            <li>Auto-refresh 10s + zvuk na novou objednávku</li>
            <li>Stará > 10 min = oranžová, > 15 min = červená pulzující</li>
          </ul>
          <p><strong>2. Výdej</strong> (zelený fresh) <code>/admin/vydej.php</code> — pro číšníka u pass-through okna.</p>
          <ul style="line-height:1.8">
            <li>Vidí jen účty s alespoň jednou hotovou položkou</li>
            <li>Klik na hotovou položku = servírováno (zmizí z výdeje)</li>
            <li>"📤 Vše odneseno" + "🖨️ Tisk bonu" tlačítka per účet</li>
            <li>Zvuk na novou hotovou položku</li>
          </ul>
          <p><strong>Otevři v novém okně</strong> z Restaurace → Provoz → klikni KDS / Výdej karta.</p>`
      },
      { q: '📲 QR objednávky — jak si host objedná z mobilu?', a: `
          <p><strong>1. Vygeneruj QR pro stůl</strong> — Restaurace → 🪑 Stoly → klik na stůl → 📲 QR.</p>
          <p>Modal ukáže QR + URL ve formátu <code>https://restaurace.cz/qr/?t=&lt;token&gt;</code>.</p>
          <p><strong>2. Vytiskni QR + nalep na stůl</strong> (plastová karta / stojánek).</p>
          <p><strong>3. Host naskenuje mobil-foťákem</strong> → otevře menu → kategorie → tap +/− → "Odeslat".</p>
          <p><strong>4. Objednávka jde do queue</strong> (NE rovnou na kuchyň!) — Restaurace → 📲 QR objednávky.</p>
          <p><strong>5. Číšník schválí</strong> → "✅ Schválit vše" → přidá do účtu stolu → KDS vidí.</p>
          <p><strong>Anti-spam:</strong> Schvalování je povinné (host nemůže přímo poslat do kuchyně). Pokud chceš auto-approve pro kavárny, zatím manuálně přes 📲 QR objednávky.</p>
          <p><strong>Reset token:</strong> Pokud někdo QR ukradne nebo opisuje → 📲 QR → "🔄 Nový token" zneplatní starý.</p>`
      },
      { q: '💳 QR k platbě (pay-at-table) — jak to funguje?', a: `
          <p><strong>Nové v 3.0.7!</strong> Host po jídle naskenuje QR a zaplatí kartou přes Stripe/GoPay nebo informuje že platí hotovostí.</p>
          <p><strong>1. V detailu účtenky</strong> (POS → klik na účtenku v historii) → klikni "📲 QR platba".</p>
          <p>Modal ukáže QR + URL <code>https://restaurace.cz/pay/?t=&lt;token&gt;</code>.</p>
          <p><strong>2. Vytiskni / přiloš QR k účtence</strong> u stolu.</p>
          <p><strong>3. Host naskenuje</strong> → vidí účtenku + 3 možnosti:</p>
          <ul style="line-height:1.8">
            <li><strong>💳 Stripe</strong> (pokud máš zapnutý) — kartou online, Apple/Google Pay</li>
            <li><strong>🔴 GoPay</strong> (pokud máš zapnutý) — kartou nebo bankovním převodem</li>
            <li><strong>💵 Hotovostí číšníkovi</strong> — informuje číšníka že platí cash</li>
          </ul>
          <p><strong>4. Po platbě</strong> — webhook označí účet jako zaplacený. Číšník vidí ✅ v adminu.</p>
          <p><strong>Stripe/GoPay setup:</strong> Nastavení → 🔌 Integrace → Stripe/GoPay → zadej API klíče. Bez nich funguje jen "💵 Hotovostí".</p>`
      },
      { q: '🖨️ Tiskárny ESC/POS — setup pro kuchyň/bar/výdej', a: `
          <p><strong>Nové v 3.0.5!</strong> Síťové termo tiskárny pro auto-rozesílání bonů.</p>
          <p><strong>Hardware:</strong> Doporučená <strong>Epson TM-T20III</strong> (cca 3500-5000 Kč) Ethernet verze. Připoj do routeru, přidej IP.</p>
          <p><strong>1. Test bez tiskárny</strong> — Nastavení → 🖨️ Tiskárny → zapni <strong>🧪 Dummy mode</strong> (žlutý). Tisk = soubor v /tmp/, vidíš preview.</p>
          <p><strong>2. Přidej tiskárnu</strong> — ➕ Přidat → Název, Typ (kasa/kuchyně/bar/sklad/výdej), IP, Port 9100, šířka 58/80mm.</p>
          <p><strong>3. Test</strong> — klik 🧪 Test → vytiskne testovací bon s diakritikou.</p>
          <p><strong>4. Mapuj kategorie</strong> — sekce "🗺️ Mapování kategorií" → vyber pro každou kategorii tiskárnu. Příklad:</p>
          <ul style="line-height:1.8">
            <li>Nápoje studené → Bar</li>
            <li>Káva, čaj → Kasa (barista)</li>
            <li>Hlavní jídla, předkrmy → Kuchyně</li>
          </ul>
          <p><strong>5. POS settings:</strong> "Tisk účtenky po platbě" (Vždy/Zeptat se/Nikdy) + "Auto-split bonů" (Auto/Manual/Off).</p>
          <p><strong>Reálný tisk:</strong> Vypni dummy mode (zezelená "✓ Reálný tisk"). Backend posílá ESC/POS přes TCP socket :9100.</p>`
      },
      { q: '📺 Multi-screen setup — Provoz + KDS + Výdej + POS', a: `
          <p><strong>Pro profesionální restauraci 3-4 monitory:</strong></p>
          <ul style="line-height:1.8">
            <li><strong>👨‍🍳 KDS</strong> u kuchaře — co vařit</li>
            <li><strong>📤 Výdej</strong> u pass-through okna — číšník vidí co odnést</li>
            <li><strong>📺 Provoz</strong> v kanceláři / sales floor — celkový přehled (Stoly/Kuchyně/Rozvoz/POS dnes)</li>
            <li><strong>🧾 POS Kasa</strong> na tabletu u pultu / dotykový terminál</li>
          </ul>
          <p>Otevři každý v samostatném okně z <strong>Restaurace → Provoz</strong> → klik karty.</p>
          <p><strong>Velikost Provoz čísel</strong> (3.0.6+) — A−/A+ tlačítka v hlavičce. Pro velký TV monitor: <code>/admin/provoz.php?size=tv</code> = 220px čísla.</p>
          <p><strong>Wake-lock</strong> — všechny kiosk stránky drží obrazovku nahoře 24/7 (modern browser API).</p>
          <p><strong>Hardware:</strong> stačí 1 server (NAS/mini PC) + 1 router + 4× tablet / monitor + tiskárny. Vše real-time přes sdílenou DB.</p>`
      },
      { q: '🔄 Update systému — jak na nové verze', a: `
          <p><strong>Self-update:</strong> Nastavení → 🛠️ Údržba → "🔄 Zkontrolovat update" → pokud je dostupný, klik "Aktualizovat".</p>
          <p>Backend:</p>
          <ol style="line-height:1.8">
            <li>Stáhne ZIP z <code>https://appek.cz/download.php?key=&lt;tvuj_licencni_klic&gt;</code></li>
            <li>Ověří SHA256 checksum</li>
            <li>Zálohuje aktuální verzi do <code>vendor/backups/</code></li>
            <li>Rozbalí nový ZIP přes existující soubory</li>
            <li>Spustí auto-migration (CREATE TABLE IF NOT EXISTS, ALTER TABLE)</li>
            <li>Při chybě → auto-rollback ze zálohy</li>
          </ol>
          <p><strong>Manuální update</strong> (pokud self-update nefunguje):</p>
          <ol style="line-height:1.8">
            <li>Stáhni <code>appek-vX.Y.Z.zip</code> z licenčního emailu</li>
            <li>FTP upload + přepiš všechny soubory</li>
            <li>Otevři admin → migrace se spustí automaticky</li>
          </ol>
          <p><strong>Záloha vždycky:</strong> Před manuálním updatem → Údržba → "💾 Vytvořit zálohu DB" → uloží SQL dump.</p>`
      },
      { q: '💾 Zálohy DB — jak často a kam?', a: `
          <p><strong>Manuální záloha:</strong> Nastavení → 🛠️ Údržba → "💾 Vytvořit zálohu" → stáhne SQL dump.</p>
          <p><strong>Doporučení:</strong></p>
          <ul style="line-height:1.8">
            <li>Denní záloha pro restauraci (transakce)</li>
            <li>Týdenní pro pekárnu (méně dat)</li>
            <li>Před každým update systému</li>
            <li>Před importem velkého množství dat</li>
          </ul>
          <p><strong>Auto-záloha cronem:</strong> Pokud hosting umí cron → <code>0 3 * * * curl https://tvoje-domena.cz/api/cron_backup.php?token=...</code> (denně ve 3:00 ráno).</p>
          <p><strong>Off-site záloha:</strong> Stažený SQL nahraj na Google Drive / Dropbox / iCloud — neztratíš při havárii hostingu.</p>
          <p><strong>Restore:</strong> Údržba → "📥 Obnovit ze zálohy" → vyber SQL soubor → potvrď. <strong>POZOR:</strong> přepíše aktuální data!</p>`
      },
    ].map((item, i) => `
      <details class="card-block" style="padding:14px 18px">
        <summary style="cursor:pointer;font-weight:700;font-size:15px;color:var(--text-1);user-select:none">
          ${esc(item.q)}
        </summary>
        <div style="margin-top:14px;padding-top:14px;border-top:1px dashed var(--border);font-size:13px;line-height:1.7;color:var(--text-2)">
          ${item.a}
        </div>
      </details>
    `).join('')}
    </div>

    <div class="card-block" style="margin-top:14px;background:linear-gradient(135deg,rgba(186,117,23,0.08),rgba(186,117,23,0.02));border-left:4px solid var(--primary)">
      <h3 style="margin:0 0 8px;color:var(--primary-dark)">📞 Něco chybí?</h3>
      <p style="font-size:13px;color:var(--text-2);line-height:1.6;margin:0">
        Kontaktujte dodavatele systému (kontakt obvykle ve vašem emailu z koupě).
        Pro vlastní úpravy: README.md v rootu aplikace + dokumentace API na <a href="../api/v1/" target="_blank" style="color:var(--primary)">/api/v1/</a>.
      </p>
    </div>
  `;

  // === MAPA TAB → OBSAH ===
  const blokBalicky = `
    <div class="card-block" style="padding:16px">
      <h2 style="margin:0 0 6px;font-size:18px;letter-spacing:-0.01em">🎁 Balíčky funkcí — roční licence</h2>
      <p style="font-size:13px;color:var(--text-3);margin:0 0 14px">Rozšíření systému pro váš obor, jako <strong>roční licence</strong>. Zakoupené balíčky zapnete přepínačem; ostatní se aktivují <strong>po zakoupení</strong>.</p>
      <div id="ns-balicky-host">⏳ Načítám…</div>
    </div>
  `;

  // 🆕 v2.4 — Účetní integrace (POHODA / FlexiBee / ISDOC)
  const blokUcetni = `
    <div class="card-block" style="padding:16px;margin-bottom:14px">
      <h2 style="margin:0 0 6px;font-size:18px;letter-spacing:-0.01em">📊 Účetní integrace</h2>
      <p style="font-size:13px;color:var(--text-3);margin:0 0 14px">Live propojení s českými účetními systémy — automatický export faktur a DL z APPEK.</p>
    </div>

    <!-- 🆕 v2.9.187 — Sales report + Fixní náklady přesunuté z bývalého tabu Výroba -->
    <div class="nastaveni-row" style="margin-bottom:14px">
      <div class="card-block">
        <h3 style="margin-bottom:6px;">📈 Sales report PDF</h3>
        <p class="page-sub" style="margin-bottom:14px;font-size:12px">
          Měsíční / roční přehled tržeb, top výrobky, top odběratelé. Vhodné pro účetní + marketing.
        </p>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:auto">
          <button class="btn-primary" onclick="navigate('sales_report')">📊 Otevřít</button>
        </div>
      </div>

      <!-- 🆕 v2.9.219 — '💰 Fixní náklady' přesunuto do Výrobní kalkulace (tlačítko v page-head) -->
    </div>

    <!-- 3-sloupcový grid: POHODA + FlexiBee + ISDOC -->
    <div class="settings-3col">
      <!-- POHODA mServer card -->
      <div class="card-block" style="padding:18px">
        <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:10px;margin-bottom:14px">
          <h3 style="margin:0;font-size:15px;display:flex;align-items:center;gap:8px">
            <span style="background:#e30613;color:#fff;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:800;letter-spacing:0.5px">POHODA</span>
            mServer
          </h3>
          <div id="ns-pohoda-status" style="font-size:12px;font-weight:600;color:var(--text-3)">⏳</div>
        </div>
        <p style="font-size:12px;color:var(--text-3);margin:0 0 10px">Stormware POHODA — XML API. Faktury, DL, partneři.</p>
        <div id="ns-pohoda-config" style="display:flex;flex-direction:column;gap:8px">⏳</div>
      </div>

      <!-- FlexiBee card -->
      <div class="card-block" style="padding:18px">
        <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:10px;margin-bottom:14px">
          <h3 style="margin:0;font-size:15px;display:flex;align-items:center;gap:8px">
            <span style="background:#f7941d;color:#fff;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:800;letter-spacing:0.5px">🐝 FlexiBee</span>
            REST API
          </h3>
          <div id="ns-fbee-status" style="font-size:12px;font-weight:600;color:var(--text-3)">⏳</div>
        </div>
        <p style="font-size:12px;color:var(--text-3);margin:0 0 10px">ABRA FlexiBee cloud / on-prem. Dvoucestná synchronizace.</p>
        <div id="ns-fbee-config" style="display:flex;flex-direction:column;gap:8px">⏳</div>
      </div>

      <!-- ISDOC info -->
      <div class="card-block" style="padding:18px;background:var(--surface-2)">
        <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:10px;margin-bottom:14px">
          <h3 style="margin:0;font-size:15px;display:flex;align-items:center;gap:8px">
            <span style="background:#003a70;color:#fff;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:800;letter-spacing:0.5px">📄 ISDOC</span>
          </h3>
          <div style="font-size:12px;font-weight:600;color:#0058b8">offline</div>
        </div>
        <p style="font-size:12px;color:var(--text-3);margin:0 0 10px;line-height:1.55">Money S3, Helios, ABRA, iDoklad importují <strong>ISDOC XML</strong>. Export ve Fakturách.</p>
        <button class="btn-secondary" onclick="navigate('faktury')" style="font-size:12px;width:100%;padding:8px">→ Otevřít Faktury</button>
      </div>
    </div>
  `;

  // 🆕 v2.5 — INTEGRACE pro customer (Stripe, GoPay, Zásilkovna, DPD)
  const blokIntegrace = `
    <!-- 📊 v3.0.284/286 — Google Analytics — oddělená ID pro B2B portál a POS pokladnu -->
    <div class="card-block" id="ns-ga-block" style="margin-bottom:14px;padding:14px 16px">
      <h3 style="margin:0 0 6px;font-size:15px">📊 Google Analytics</h3>
      <p class="page-sub" style="font-size:12px;margin:0 0 10px">
        Measurement ID (např. <code>G-XXXXXXXXXX</code>) — <strong>zvlášť pro B2B portál, pokladnu a admin</strong>, ať se data nemíchají. Každá instalace svoje ID (vlastní data, GDPR čisté). Prázdné pole = vypnuto.
      </p>
      <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
        <div>
          <label class="form-label" style="font-size:12px">🌐 B2B portál (objednávky odběratelů)</label>
          <input class="form-input" id="ns-ga-id" placeholder="G-XXXXXXXXXX" style="width:200px;font-family:monospace" value="">
        </div>
        <div>
          <label class="form-label" style="font-size:12px">🧾 POS pokladna</label>
          <input class="form-input" id="ns-ga-id-pos" placeholder="G-YYYYYYYYYY" style="width:200px;font-family:monospace" value="">
        </div>
        <div>
          <label class="form-label" style="font-size:12px">🛠️ Admin (core aplikace)</label>
          <input class="form-input" id="ns-ga-id-core" placeholder="G-ZZZZZZZZZZ" style="width:200px;font-family:monospace" value="">
        </div>
        <button class="btn-primary btn-green" onclick="ulozitGaId()">💾 Uložit</button>
        <span id="ns-ga-info" style="font-size:12px;color:var(--text-3)"></span>
      </div>
      <!-- 🍪 v3.0.401 — vlastní sledovací kód (Meta Pixel, Sklik, LinkedIn…) pro B2B portál -->
      <div style="margin-top:14px">
        <label class="form-label" style="font-size:12px">🧩 Vlastní sledovací kód — B2B portál (HTML/JS, např. Meta Pixel, Sklik)</label>
        <textarea class="form-input" id="ns-tracking-code" rows="4" spellcheck="false"
          placeholder="&lt;script&gt;…&lt;/script&gt; — vloží se návštěvníkům B2B portálu AŽ po souhlasu s cookies"
          style="width:100%;max-width:720px;font-family:monospace;font-size:12px"></textarea>
        <div class="page-sub" style="font-size:11.5px;margin-top:4px">Kód se návštěvníkům vkládá <strong>až po souhlasu</strong> s analytickými cookies (GDPR). Ukládá se tlačítkem 💾 Uložit výše.</div>
      </div>
    </div>

    <!-- 🍪 v3.0.401 — Soukromí / GDPR — stav souhlasu + zásady cookies -->
    <div class="card-block" id="ns-gdpr-block" style="margin-bottom:14px;padding:14px 16px">
      <h3 style="margin:0 0 6px;font-size:15px">🍪 Soukromí / GDPR</h3>
      <p class="page-sub" style="font-size:12px;margin:0 0 10px">
        Cookie lišta se zobrazuje jen tam, kde je zapnuté měření (GA / vlastní kód). Souhlas se ukládá per prohlížeč
        a platí napříč admin / POS / B2B. Bez měření se používají jen nezbytné cookies (přihlášení, košík) — souhlas není potřeba.
      </p>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <span id="ns-gdpr-stav" style="font-size:13px;font-weight:600"></span>
        <button class="btn-secondary" onclick="gdprZmenitVolbu()">↺ Změnit volbu v tomto prohlížeči</button>
        <button class="btn-secondary" onclick="gdprZasady()">📋 Zásady cookies</button>
      </div>
    </div>

    <!-- 🔒 v3.0.425 — Zásady zpracování osobních údajů (GDPR dokument) -->
    <div class="card-block" id="ns-gdpr-zasady-block" style="margin-bottom:14px;padding:14px 16px">
      <h3 style="margin:0 0 6px;font-size:15px">🔒 Zásady zpracování osobních údajů (GDPR)</h3>
      <p class="page-sub" style="font-size:12px;margin:0 0 10px">
        Obecné GDPR ustanovení, které uvidí zákazníci v B2B portálu. Předvyplněnou obecnou šablonu si vlož tlačítkem
        a uprav podle svého provozu. <strong>Doporučujeme kontrolu právníkem.</strong>
      </p>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
        <button class="btn-secondary" onclick="gdprZasadyVlozitSablonu()">📄 Vložit obecnou šablonu</button>
        <button class="btn-secondary" onclick="gdprZasadyNahled()">👁️ Náhled</button>
        <span id="ns-gdpr-zasady-info" style="font-size:12px;color:var(--text-3);align-self:center"></span>
      </div>
      <textarea id="ns-gdpr-zasady-text" class="form-input" rows="10" style="width:100%;font-family:monospace;font-size:12px;line-height:1.5" placeholder="⏳ Načítám…"></textarea>
      <div style="margin-top:10px;display:flex;gap:8px;align-items:center">
        <button class="btn-primary btn-green" onclick="gdprZasadySave()">💾 Uložit zásady</button>
        <span id="ns-gdpr-zasady-save" style="font-size:12px;color:var(--text-3)"></span>
      </div>
    </div>

    <!-- 🔐 v3.0.425 — Práva subjektu (export / anonymizace osobních údajů) -->
    <div class="card-block admin-only" id="ns-gdpr-prava-block" style="margin-bottom:14px;padding:14px 16px">
      <h3 style="margin:0 0 6px;font-size:15px">🔐 Práva subjektu údajů</h3>
      <p class="page-sub" style="font-size:12px;margin:0 0 10px">
        Na žádost zákazníka: <strong>export</strong> jeho osobních údajů (právo na přístup) nebo <strong>anonymizace</strong>
        (právo být zapomenut). Účetní doklady zůstávají kvůli zákonné době uchování.
      </p>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input class="form-input" id="ns-gdpr-hledej" placeholder="Hledat zákazníka (jméno / e-mail / IČO)…" style="flex:1;min-width:220px" oninput="gdprPravaHledat()">
      </div>
      <div id="ns-gdpr-vysledky" style="margin-top:10px;font-size:13px"></div>
    </div>

    <!-- 🆕 v2.9.243 — sales-pitch header (proč to chtít, value proposition) -->
    <div class="card-block int-hero" style="padding:20px 22px;margin-bottom:14px;background:linear-gradient(135deg, #FFF8E7 0%, #FEF3C7 100%);border:1.5px solid #FBBF24">
      <div style="display:flex;gap:14px;align-items:start;flex-wrap:wrap">
        <div style="font-size:42px;line-height:1">🔌</div>
        <div style="flex:1;min-width:240px">
          <h2 style="margin:0 0 6px;font-size:19px;letter-spacing:-0.01em;color:#854F0B">Propojení = méně práce, víc objednávek</h2>
          <p style="font-size:13.5px;color:#854F0B;margin:0 0 10px;line-height:1.55">
            Tvoji <strong>B2B zákazníci nemusí nic přepisovat</strong> — platí 1 klikem (Stripe/GoPay/PayPal),
            faktury jdou rovnou do jejich účetnictví (POHODA/Money S3), zásilky se posílají automaticky
            (Zásilkovna/DPD). <strong>Méně tření = víc opakovaných nákupů.</strong>
          </p>
          <div style="display:flex;gap:8px;flex-wrap:wrap;font-size:11.5px">
            <span style="background:rgba(255,255,255,0.7);padding:4px 10px;border-radius:999px;color:#854F0B;font-weight:600">⚡ 1-click platba</span>
            <span style="background:rgba(255,255,255,0.7);padding:4px 10px;border-radius:999px;color:#854F0B;font-weight:600">📤 Auto-export do účetnictví</span>
            <span style="background:rgba(255,255,255,0.7);padding:4px 10px;border-radius:999px;color:#854F0B;font-weight:600">📦 Auto-štítky</span>
            <span style="background:rgba(255,255,255,0.7);padding:4px 10px;border-radius:999px;color:#854F0B;font-weight:600">📧 Auto-notifikace</span>
          </div>
        </div>
      </div>
    </div>

    <div class="card-block" style="padding:14px 16px;margin-bottom:14px">
      <p style="font-size:12.5px;color:var(--text-2);margin:0 0 6px;line-height:1.5">
        💡 <strong>Začni zdarma</strong> — registrace v každé službě je bez poplatku, platíš až za úspěšné transakce. Většina běží do <strong>5 minut</strong>.
      </p>
      <p style="font-size:12px;color:var(--text-3);margin:0">
        Registrovat se: <a href="https://stripe.com" target="_blank">Stripe</a> · <a href="https://gopay.com/cs/zaregistrovat-se/" target="_blank">GoPay</a> · <a href="https://www.paypal.com/cz/business" target="_blank">PayPal Business</a> · <a href="https://www.zasilkovna.cz/podnikatele/registrace-noveho-zakaznika" target="_blank">Zásilkovna</a> · <a href="https://www.dpd.com/cz/cs/byt-zakaznikem/" target="_blank">DPD CZ</a>
      </p>
    </div>

    <!-- 3-sloupcový grid — kartám se přizpůsobí počet sloupců (1/2/3 podle šířky) -->
    <div class="settings-3col">

      <!-- 💳 STRIPE — platby kartou pro zákazníky -->
      <div class="card-block int-card" style="padding:18px">
        <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:10px;margin-bottom:8px">
          <h3 style="margin:0;font-size:15px;display:flex;align-items:center;gap:8px">
            <span style="background:#635bff;color:#fff;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:800;letter-spacing:0.5px">💳 Stripe</span>
          </h3>
          <div id="ns-int-stripe-status" style="font-size:12px;font-weight:600;color:var(--text-3)">⏳</div>
        </div>
        <div style="font-size:11.5px;color:#15803d;font-weight:600;margin-bottom:6px">✓ Mezinárodní · Apple Pay · Google Pay · 1-click</div>
        <p style="font-size:12px;color:var(--text-3);margin:0 0 10px;line-height:1.55">Když má zákazník Apple Pay / Google Pay v telefonu, <strong>zaplatí FA otiskem za 3 vteřiny</strong>. 1.4% + 6 Kč.</p>
        <div id="ns-int-stripe" style="display:flex;flex-direction:column;gap:8px">⏳</div>
      </div>

      <!-- 💳 GOPAY — CZ karty + bank převod -->
      <div class="card-block int-card" style="padding:18px">
        <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:10px;margin-bottom:8px">
          <h3 style="margin:0;font-size:15px;display:flex;align-items:center;gap:8px">
            <span style="background:#dc2626;color:#fff;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:800;letter-spacing:0.5px">💳 GoPay</span>
          </h3>
          <div id="ns-int-gopay-status" style="font-size:12px;font-weight:600;color:var(--text-3)">⏳</div>
        </div>
        <div style="font-size:11.5px;color:#15803d;font-weight:600;margin-bottom:6px">✓ ČR/SK · okamžitý převod · nejlevnější</div>
        <p style="font-size:12px;color:var(--text-3);margin:0 0 10px;line-height:1.55">Pro české zákazníky <strong>nejlevnější varianta</strong> — od 0.85%. Karta i bank tlačítko (Komerční, ČSOB, Air, …).</p>
        <div id="ns-int-gopay" style="display:flex;flex-direction:column;gap:8px">⏳</div>
      </div>

      <!-- 💼 PAYPAL — mezinárodní, EUR/USD/CZK -->
      <div class="card-block int-card" style="padding:18px">
        <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:10px;margin-bottom:8px">
          <h3 style="margin:0;font-size:15px;display:flex;align-items:center;gap:8px">
            <span style="background:#0070ba;color:#fff;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:800;letter-spacing:0.5px">💼 PayPal</span>
          </h3>
          <div id="ns-int-paypal-status" style="font-size:12px;font-weight:600;color:var(--text-3)">⏳</div>
        </div>
        <div style="font-size:11.5px;color:#15803d;font-weight:600;margin-bottom:6px">✓ EUR/USD · zákazníci v zahraničí</div>
        <p style="font-size:12px;color:var(--text-3);margin:0 0 10px;line-height:1.55">Pokud máš <strong>zákazníky v Německu, Rakousku, Polsku</strong> — PayPal už mají a věří mu. Kupují bez váhání.</p>
        <div id="ns-int-paypal" style="display:flex;flex-direction:column;gap:8px">⏳</div>
      </div>

      <!-- 📦 ZÁSILKOVNA -->
      <div class="card-block int-card" style="padding:18px">
        <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:10px;margin-bottom:8px">
          <h3 style="margin:0;font-size:15px;display:flex;align-items:center;gap:8px">
            <span style="background:#bf2026;color:#fff;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:800;letter-spacing:0.5px">📦 Zásilkovna</span>
          </h3>
          <div id="ns-int-zas-status" style="font-size:12px;font-weight:600;color:var(--text-3)">⏳</div>
        </div>
        <div style="font-size:11.5px;color:#15803d;font-weight:600;margin-bottom:6px">✓ 12 000+ výdejen · auto-štítky · sledování</div>
        <p style="font-size:12px;color:var(--text-3);margin:0 0 10px;line-height:1.55">Zákazník si <strong>vybere výdejní místo blízko domu</strong>. APPEK vytvoří zásilku + vytiskne štítek, ty jen polepíš krabičku.</p>
        <div id="ns-int-zas" style="display:flex;flex-direction:column;gap:8px">⏳</div>
      </div>

      <!-- 📦 DPD CZ -->
      <div class="card-block int-card" style="padding:18px">
        <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:10px;margin-bottom:8px">
          <h3 style="margin:0;font-size:15px;display:flex;align-items:center;gap:8px">
            <span style="background:#dc0032;color:#fff;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:800;letter-spacing:0.5px">📦 DPD CZ</span>
          </h3>
          <div id="ns-int-dpd-status" style="font-size:12px;font-weight:600;color:var(--text-3)">⏳</div>
        </div>
        <div style="font-size:11.5px;color:#15803d;font-weight:600;margin-bottom:6px">✓ Velké balíky · doručení do ruky · OAuth API</div>
        <p style="font-size:12px;color:var(--text-3);margin:0 0 10px;line-height:1.55">Pro <strong>objemné/těžké zásilky</strong> (chleba, sušenky v kartonech). Auto-štítky + push tracking, zákazník dostane SMS s ETA.</p>
        <div id="ns-int-dpd" style="display:flex;flex-direction:column;gap:8px">⏳</div>
      </div>

      <div class="card-block int-card" style="padding:18px">
        <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:10px;margin-bottom:8px">
          <h3 style="margin:0;font-size:15px;display:flex;align-items:center;gap:8px">
            <span style="background:#005a9b;color:#fff;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:800;letter-spacing:0.5px">📦 PPL</span>
          </h3>
          <div id="ns-int-ppl-status" style="font-size:12px;font-weight:600;color:var(--text-3)">⏳</div>
        </div>
        <div style="font-size:11.5px;color:#15803d;font-weight:600;margin-bottom:6px">✓ Balíky po ČR · myAPI2 (OAuth) · auto-štítky</div>
        <p style="font-size:12px;color:var(--text-3);margin:0 0 10px;line-height:1.55">Hustá síť výdejních míst (ParcelShop). Klíče z PPL myAPI2 (client_id/secret).</p>
        <div id="ns-int-ppl" style="display:flex;flex-direction:column;gap:8px">⏳</div>
      </div>

      <div class="card-block int-card" style="padding:18px">
        <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:10px;margin-bottom:8px">
          <h3 style="margin:0;font-size:15px;display:flex;align-items:center;gap:8px">
            <span style="background:#ffd400;color:#1a1a1a;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:800;letter-spacing:0.5px">🟡 Česká pošta</span>
          </h3>
          <div id="ns-int-cp-status" style="font-size:12px;font-weight:600;color:var(--text-3)">⏳</div>
        </div>
        <div style="font-size:11.5px;color:#15803d;font-weight:600;margin-bottom:6px">✓ Balík Do ruky / Na poštu · největší pokrytí</div>
        <p style="font-size:12px;color:var(--text-3);margin:0 0 10px;line-height:1.55">Podání Online (B2B). API URL + klíč podle tvé smlouvy s ČP.</p>
        <div id="ns-int-cp" style="display:flex;flex-direction:column;gap:8px">⏳</div>
      </div>

    </div>
  `;

  // 🆕 v2.9.205 — blokPlatby je placeholder; reálný obsah loaduje renderPlatbyPanel()
  // 🆕 v3.0.271 — sloučeno s Kanály. Dvě sekce: Platební metody (JAK platí) +
  //   Prodejní kanály (ODKUD objednávka přišla). Vysvětlen rozdíl, ať je jasné co je co.
  const blokPlatby = `
    <div class="card-block" style="padding:16px;margin-bottom:14px;border-left:4px solid #34C759;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
      <div style="min-width:0">
        <h2 style="margin:0 0 6px;font-size:18px;letter-spacing:-0.01em">💳 Platební metody</h2>
        <p style="font-size:13px;color:var(--text-3);margin:0 0 4px;line-height:1.55">
          <strong>JAK</strong> zákazník zaplatí. Zapni jen metody, které reálně přijímáš — objeví se v POS pokladně, B2B portálu i v online checkoutu. Vypnutá metoda se zákazníkovi vůbec nenabídne.
        </p>
        <p style="font-size:12px;color:var(--text-3);margin:0;opacity:0.85">Hotově, kartou, QR, převodem, dobírka, stravenky… Online brány (Stripe, GoPay) napoj v záložce <strong>🔌 Integrace</strong>.</p>
      </div>
      <button class="btn-secondary" style="white-space:nowrap" onclick="navigate('vouchery')" title="Vytvořit a spravovat poukazy / dárkové karty">🎟️ Vouchery & dárkové karty</button>
    </div>
    <div id="ns-platby-panel" style="margin-bottom:26px">⏳ Načítám…</div>

    ${adminOnly(`
    <div class="card-block" style="padding:16px;margin-bottom:14px;border-left:4px solid #0a84ff">
      <h2 style="margin:0 0 6px;font-size:18px;letter-spacing:-0.01em">🔀 Prodejní kanály</h2>
      <p style="font-size:13px;color:var(--text-3);margin:0 0 4px;line-height:1.55">
        <strong>ODKUD</strong> objednávka přišla. Každý zdroj (POS pokladna, B2B portál, dort konfigurátor, rozvoz Wolt/Bolt, opakované objednávky…) má vlastní označení a <strong>vlastní číselnou řadu</strong>, aby se doklady nepřebíjely.
      </p>
      <p style="font-size:12px;color:var(--text-3);margin:0;opacity:0.85">U každého kanálu: přejmenuj, vypni nepoužívaný, a zaškrtni „pokladní prodej" u těch, co se mají počítat do <strong>POS Účtenek a uzávěrky</strong> (typicky kasa a rozvoz, ne B2B faktury).</p>
    </div>
    <div id="kanaly-container"><div style="text-align:center;padding:20px;color:var(--text-3)">⏳ Načítám…</div></div>
    <div style="display:flex;justify-content:flex-end;margin-top:14px">
      <button class="btn-primary btn-green" onclick="ulozitKanaly()" style="font-weight:700;padding:12px 24px;border-radius:10px">💾 Uložit kanály</button>
    </div>
    `)}
  `;

  // 🆕 v3.0.5 — Tiskárny tab (CRUD + mapping + settings)
  const blokTiskarny = `
    <div class="card-block" style="padding:16px;margin-bottom:14px">
      <h2 style="margin:0 0 6px;font-size:18px;letter-spacing:-0.01em">🖨️ Tiskárny (ESC/POS)</h2>
      <p style="font-size:13px;color:var(--text-3);margin:0 0 14px;line-height:1.55">
        Síťové termo tiskárny pro kasa, kuchyň, bar, sklad a výdej. Bonu se automaticky rozesílají podle <strong>kategorie výrobku</strong> na příslušnou tiskárnu.
        <br>Standard: <strong>Epson TM-T20III</strong> nebo podobná na IP, port <code>9100</code>. Bez fyzické tiskárny zapni <strong>dummy mode</strong> (tisk do souboru pro test).
      </p>
    </div>
    <div id="ns-tiskarny-panel">⏳ Načítám…</div>
  `;

  // 🆕 v3.0.29 — Integrace + Účetní sloučeno do jednoho bloku (Integrace = vše externí)
  const blokIntegraceCombined = blokIntegrace + `
    <!-- 🆕 v3.0.29 — Účetní sekce (POHODA / FlexiBee / ISDOC) sloučena pod Integrace -->
    <div style="margin-top:18px;padding:14px 0;border-top:2px solid var(--border)">
      <h2 style="margin:0 0 4px;font-size:18px;letter-spacing:-0.01em">📊 Účetní integrace</h2>
      <p style="font-size:13px;color:var(--text-3);margin:0 0 14px">POHODA mServer · FlexiBee REST · ISDOC export — live sync s tvým účetním softwarem.</p>
    </div>
  ` + blokUcetni;

  // 🆕 v3.0.271 — blokKanaly zrušen jako samostatný tab; obsah je teď uvnitř blokPlatby.

  // 🆕 v3.0.330 — Skener čárových kódů přesunut z Nastavení pod Nástroje (window.appekScannerSettings modal).

  const blokyTabu = {
    firma:      blokFirmaDoklady,
    notifikace: blokNotifikace,
    platby:     blokPlatby,
    integrace:  blokIntegraceCombined,
    pristupy:   blokPristupy,
    balicky:    blokBalicky,
    udrzba:     blokUdrzba,
    napoveda:   blokNapoveda,
    // Backward-compat: kdyby starý state byl 'tiskarny' nebo 'ucetni', mapuj
    tiskarny:   blokIntegraceCombined,  // bude přesměrováno níže
    ucetni:     blokIntegraceCombined,
  };
  // Pokud se uloží státní hodnota, kterou nemáme, fallback
  const aktivniBlok = blokyTabu[aktTab] || blokyTabu.firma;
  // Jen Firma + Notifikace mají formulářová pole, která vyžadují "Uložit"
  // (ostatní taby ukládají on-change nebo navigují na samostatné endpointy).
  // 🐛 v3.0.247 — Údržba taky potřebuje Uložit (sekce „Dlouhé seznamy" tam byla bez tlačítka → nešlo uložit)
  const ukazatUlozit = (aktTab === 'firma' || aktTab === 'notifikace' || aktTab === 'udrzba');

  const standalone = (aktTab === 'integrace');  // 🆕 v3.0.370 — integrace = samostatná stránka z Nástrojů (bez settings tab baru)
  const segTabsHtml = TABS.map(t => {
    // Rozdělit "🏢 Firma & doklady" na ikona + text (Unicode emoji regex)
    const m = (t.label || '').match(/^(\p{Emoji}+|\p{Extended_Pictographic}+|[^\s]+)\s+(.+)$/u);
    const icon = m ? m[1] : '';
    const text = m ? m[2] : t.label;
    return `
        <button type="button" role="tab" class="seg-tab ${aktTab === t.key ? 'active' : ''} ${t.adminOnly ? 'admin-only' : ''}"
                onclick="nastaveniSetTab('${t.key}')" aria-selected="${aktTab === t.key}">
          <span class="seg-tab-icon">${icon}</span>
          <span class="seg-tab-text">${esc(text)}</span>
        </button>`;
  }).join('');
  c.innerHTML = `
    ${standalone ? `
    <div class="page-head" style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
      <div>
        <h1 class="page-title">🔌 Integrace</h1>
        <p class="page-sub">Platby (Stripe, GoPay, PayPal), přepravci (Zásilkovna, DPD, PPL, ČP) a účetní (POHODA, FlexiBee, ISDOC).</p>
      </div>
      <button class="btn-secondary" onclick="navigate('nastroje')" style="white-space:nowrap">← Nástroje</button>
    </div>
    ` : `
    <div class="page-head">
      <div>
        <h1 class="page-title">⚙️ Nastavení</h1>
        <p class="page-sub">${esc(TABS.find(t => t.key === aktTab)?.popis || '')}</p>
      </div>
    </div>

    <!-- 🗂️ ZÁLOŽKY — v2.9.229 segmented control (icon nahoře, label dole) -->
    <div class="seg-tabs" role="tablist">${segTabsHtml}</div>
    `}

    <div class="nastaveni-page nastaveni-tab-content">
      ${aktivniBlok}
      ${ukazatUlozit ? `
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
          <button class="btn-primary btn-green btn-big-action" onclick="ulozitNastaveni()" style="font-size:16px !important;font-weight:700 !important;padding:14px 28px !important;min-height:56px !important;border-radius:10px !important">💾 Uložit nastavení</button>
        </div>
      ` : ''}
    </div>
  `;

  // Načti asynchronní data jen pokud je aktivní příslušný tab
  if (aktTab === 'firma') {
    loadCislovani();
    loadDph();
  }
  if (aktTab === 'notifikace') {
    loadEmailTemplates();
    loadPushStats();
    smtpLoad();   // 📧 v3.0.289
  }
  if (aktTab === 'udrzba') {
    zalohyRefresh();
    diagRychly();
    apiTokensLoad();
    syncLoadStatus();
    licenseLoadStatus();
    loadActivityLog();
    loadWebhooks();
    if (typeof errorsLoad === 'function') errorsLoad(); // 🆕 v2.9.321 — app_errors viewer
  }
  if (aktTab === 'pristupy') {
    menaLoad();   // 💱 v3.0.283 — měna & přepočet
  }
  if (aktTab === 'balicky') {
    loadBalicky();
  }
  if (aktTab === 'ucetni') {
    loadUcetniIntegrace();
  }
  if (aktTab === 'integrace') {
    loadCustomerIntegrace();
    // 📊 v3.0.284/286 — předvyplň GA measurement ID (B2B + POS zvlášť)
    api('admin_nastaveni.php').then(n => {
      const inp = document.getElementById('ns-ga-id');
      if (inp && n && n.ga_measurement_id) inp.value = n.ga_measurement_id;
      const inpPos = document.getElementById('ns-ga-id-pos');
      if (inpPos && n && n.ga_measurement_id_pos) inpPos.value = n.ga_measurement_id_pos;
      const inpCore = document.getElementById('ns-ga-id-core');
      if (inpCore && n && n.ga_measurement_id_core) inpCore.value = n.ga_measurement_id_core;
      // 🍪 v3.0.401 — vlastní sledovací kód + stav GDPR souhlasu
      const inpTrk = document.getElementById('ns-tracking-code');
      if (inpTrk && n && n.tracking_custom_code) inpTrk.value = n.tracking_custom_code;
      if (typeof gdprRefreshStav === 'function') gdprRefreshStav(n);
    }).catch(() => {});
    // 🔒 v3.0.425 — načti GDPR zásady zpracování osobních údajů do editoru
    if (typeof gdprZasadyLoad === 'function') gdprZasadyLoad();
  }
  if (aktTab === 'platby') {
    loadPaymentMethodsPanel();
    // 🆕 v3.0.271 — kanály sloučeny pod Platby → naplň i jejich panel (jen admin; kontejner existuje jen pro něj)
    if (document.getElementById('kanaly-container')) renderKanalyPanel();
  }
  // 🆕 v3.0.29 — Tiskárny přesunuté → naviguj na standalone page
  if (aktTab === 'tiskarny') {
    state._nastaveniTab = 'integrace';
    navigate('tiskarny');
    return;
  }
  // 🆕 v3.0.29 — Účetní sloučeno pod Integrace, přepnout
  if (aktTab === 'ucetni') {
    state._nastaveniTab = 'integrace';
    renderNastaveni();
    return;
  }
}

