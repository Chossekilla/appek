// =============================================================
// 🎁 PACKAGE PLACEHOLDER PAGES
// =============================================================
const PKG_PLACEHOLDER_CONTENT = {
  pkg_cukrarna: {
    ikona: '🧁',
    nazev: 'Cukrárna',
    popis: 'Modul pro cukrárny — konfigurátor dortů, kapacita pečení, foto galerie.',
    features: [
      { ikona: '🎂', nazev: 'Konfigurátor dortů',  popis: 'Porce → hmotnost → cena · text na dortu · upload fotky předlohy · výběr příchutě/dekorace.', stav: 'hotovo' },
      { ikona: '📅', nazev: 'Denní kapacita pečení', popis: 'Kalendář s "dnes max 12 dortů" — auto-blokuje objednávky když je plno.', stav: 'hotovo' },
      { ikona: '🖼️', nazev: 'Galerie inspirací', popis: 'Fotky hotových dortů jako inspirace pro zákazníky při objednávce.', stav: 'hotovo' },
      { ikona: '♻️', nazev: 'Evidence vratných stojanů', popis: 'Tracking stojanů na dorty zapůjčených zákazníkům — záloha, plánovaný návrat, alerty po termínu.', stav: 'hotovo' },
    ],
  },
  pkg_lahudky: {
    ikona: '🥗',
    nazev: 'Lahůdkárna',
    popis: 'Modul pro lahůdkárny a deli — catering, šarže, mix-and-match.',
    features: [
      { ikona: '🍱', nazev: 'Catering kalkulátor', popis: 'X osob → návrh počtu chlebíčků/zákusků/nápojů s cenou.', stav: 'hotovo' },
      { ikona: '📋', nazev: 'Šaržová HACCP evidence', popis: 'Každá várka = vlastní šarže s teplotou skladu + DMT, filtry, kontrolní záznamy pro audit.', stav: 'hotovo' },
      { ikona: '🧩', nazev: 'Mix-and-match konfigurátor', popis: 'Šablony chlebíček/bageta s kategoriemi a ingrediencemi · cena se počítá automaticky · alergeny.', stav: 'hotovo' },
      { ikona: '🚚', nazev: 'Catering objednávky s časem', popis: 'Rozvoz v určitý čas s timeslotem — sdílí evidenci s balíčkem 🎉 Velký catering.', stav: 'hotovo' },
    ],
  },
  pkg_restaurace: {
    ikona: '🍕',
    nazev: 'Restaurace / Pizzerie',
    popis: 'Modul pro restaurace — POS kasa, stoly, kuchyně, rozvoz.',
    features: [
      { ikona: '🧾', nazev: 'POS Kasa (touch-grid)', popis: 'Klasický POS register · produktová mřížka · košík · 6 platebních metod (hotově, karta, PayPal, voucher, gift card, mobile) · 4 typy (sebou, na místě, vyzvednutí, rozvoz) · tisk účtenky · slevy a spropitné.', stav: 'hotovo' },
      { ikona: '🪑', nazev: 'Stolová správa', popis: 'Rezervace, mapa stolů, obsazenost · Mapa / Timeline / Seznam.', stav: 'hotovo' },
      { ikona: '👨‍🍳', nazev: 'Kapacita kuchyně', popis: 'Max paralelních objednávek · stanice (pec, gril, bar) · live queue · gauge vytížení · auto-block.', stav: 'hotovo' },
      { ikona: '⏱️', nazev: 'Doba přípravy', popis: 'Per výrobek — paralelní výpočet podle stanic · bulk editor.', stav: 'hotovo' },
      { ikona: '🛵', nazev: 'Vlastní rozvoz + kurýry', popis: 'Vlastní řidiči + evidence Wolt/Bolt/Dáme jídlo/Foodora · live status doručení · integrace.', stav: 'hotovo' },
    ],
  },
  pkg_catering: {
    ikona: '🎉',
    nazev: 'Velký catering',
    popis: 'Modul pro velkokapacitní akce a firemní objednávky.',
    features: [
      { ikona: '🏢', nazev: 'Firemní objednávky', popis: 'Faktura na IČO · evidence kontaktní osoby, telefonu a e-mailu · full edit modal.', stav: 'hotovo' },
      { ikona: '👥', nazev: 'Cenové úrovně dle počtu osob', popis: 'Stupňovité ceny v sadách (Standard / Raut / Svatba) · automatický výběr úrovně podle počtu osob · kalkulačka.', stav: 'hotovo' },
      { ikona: '📑', nazev: 'Generování smluv + nabídek', popis: 'PDF nabídka pro klienta · PDF smlouva s podmínkami a podpisy · PDF zálohová faktura (50 %).', stav: 'hotovo' },
      { ikona: '🎯', nazev: 'Záloha 50%', popis: 'Workflow zálohy + doplatek · auto-výpočet 50 % · stat panel čekajících / uhrazených · jeden klik na "Uhrazeno".', stav: 'hotovo' },
    ],
  },
  pkg_sezona: {
    ikona: '🍰',
    nazev: 'Sezónní módy',
    popis: 'Speciální módy pro Velikonoce, Vánoce, sv. Valentýna.',
    features: [
      { ikona: '🐰', nazev: 'Sezónní katalog', popis: 'Auto on/off výrobků podle data.', stav: 'plánováno' },
      { ikona: '🎄', nazev: 'Předobjednávky s deadlinem', popis: 'Vánoční cukroví — uzávěrka 15.12.', stav: 'plánováno' },
      { ikona: '🎁', nazev: 'Dárkové balení', popis: 'Vrstva + cena u objednávky.', stav: 'plánováno' },
      { ikona: '🏷️', nazev: 'Sezónní akce a slevy', popis: 'Time-bounded slevy.', stav: 'plánováno' },
    ],
  },
};

async function renderPackagePage(pageKey) {
  // 🎂 Cukrárna → konfigurátor + kapacita + galerie
  if (pageKey === 'pkg_cukrarna') return renderCakeConfigurator();
  // 🥗 Lahůdky → catering kalkulátor
  if (pageKey === 'pkg_lahudky')  return renderCateringCalculator();
  // 🍰 Sezónní → katalog manager
  if (pageKey === 'pkg_sezona')   return renderSeasonalCatalog();
  // 🍕 Restaurace → stolová správa
  if (pageKey === 'pkg_restaurace') return renderRestaurantPage();
  // 🎉 Catering → firemní objednávky
  if (pageKey === 'pkg_catering') return renderCateringPage();

  const meta = PKG_PLACEHOLDER_CONTENT[pageKey];
  const c = document.getElementById('content');
  if (!meta) {
    c.innerHTML = `<div class="page-head"><h1>Neznámý modul</h1></div>`;
    return;
  }
  const stavBarvy = { aktivní: '#DCFCE7|#166534', 'v testu': '#DBEAFE|#1e40af', plánováno: '#FEF3C7|#92400e' };
  const featuresHtml = meta.features.map(f => {
    const [bg, fg] = (stavBarvy[f.stav] || stavBarvy.plánováno).split('|');
    return `
      <div class="card-block" style="padding:16px">
        <div style="display:flex;align-items:start;gap:14px">
          <div style="font-size:30px;line-height:1">${f.ikona}</div>
          <div style="flex:1">
            <h3 style="margin:0 0 4px;font-size:15px">${esc(f.nazev)}</h3>
            <p style="font-size:13px;color:var(--text-3);margin:0 0 8px;line-height:1.5">${esc(f.popis)}</p>
            <span style="display:inline-block;padding:3px 10px;background:${bg};color:${fg};border-radius:999px;font-size:11px;font-weight:600">${esc(f.stav)}</span>
          </div>
        </div>
      </div>
    `;
  }).join('');

  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">${meta.ikona} ${esc(meta.nazev)}</h1>
        <p class="page-sub">${esc(meta.popis)}</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn-secondary" onclick="navigate('dashboard')">← Dashboard</button>
        <button class="btn-secondary" onclick="navigate('nastaveni');setTimeout(()=>{state._nastaveniTab='balicky';renderNastaveni();},100)">🎁 Správa balíčků</button>
      </div>
    </div>

    <div style="background:#FFF8E5;border-left:3px solid #BA7517;padding:14px 16px;border-radius:8px;margin-bottom:16px;font-size:13px;color:#854F0B;line-height:1.6">
      ✅ <strong>Balíček je aktivován.</strong> Níže jsou featury které tento modul přinese.
      Většina je <em>zatím v plánu</em> — řekni mi, kterou chceš implementovat jako první, postavím ji.
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:14px">
      ${featuresHtml}
    </div>
  `;
}

