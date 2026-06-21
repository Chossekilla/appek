// =============================================================
// 📥 IMPORT SUROVIN — základní balíček + CSV
// =============================================================

// Předpřipravený balíček běžných pekařských surovin s orientačními velkoobchodními cenami (CZ 2024-2026)
// cena_baleni v Kč, obsah_baleni v jednotce (g pro pevné, ml pro tekuté)
const SUROVINY_ZAKLADNI_BALICEK = [
  // 🌾 Mouky a krupice
  { nazev: 'Pšeničná mouka hladká T530',    jednotka: 'g',  cena_baleni: 360,  obsah_baleni: 25000, alergen: 'lepek' },
  { nazev: 'Pšeničná mouka chlebová T1050', jednotka: 'g',  cena_baleni: 340,  obsah_baleni: 25000, alergen: 'lepek' },
  { nazev: 'Žitná mouka chlebová T960',     jednotka: 'g',  cena_baleni: 380,  obsah_baleni: 25000, alergen: 'lepek' },
  { nazev: 'Žitná mouka světlá T700',       jednotka: 'g',  cena_baleni: 395,  obsah_baleni: 25000, alergen: 'lepek' },
  { nazev: 'Špaldová mouka',                jednotka: 'g',  cena_baleni: 880,  obsah_baleni: 25000, alergen: 'lepek' },
  { nazev: 'Krupice pšeničná',              jednotka: 'g',  cena_baleni: 360,  obsah_baleni: 25000, alergen: 'lepek' },
  { nazev: 'Otruby pšeničné',               jednotka: 'g',  cena_baleni: 280,  obsah_baleni: 25000, alergen: 'lepek' },
  { nazev: 'Vločky ovesné',                 jednotka: 'g',  cena_baleni: 280,  obsah_baleni: 10000, alergen: 'lepek' },

  // 🥜 Ořechy a semena
  { nazev: 'Slunečnicová semínka loupaná',  jednotka: 'g',  cena_baleni: 1450, obsah_baleni: 25000 },
  { nazev: 'Sezamová semínka',              jednotka: 'g',  cena_baleni: 1850, obsah_baleni: 25000, alergen: 'sezam' },
  { nazev: 'Mák modrý',                     jednotka: 'g',  cena_baleni: 2950, obsah_baleni: 25000 },
  { nazev: 'Lněné semínko',                 jednotka: 'g',  cena_baleni: 1250, obsah_baleni: 25000 },
  { nazev: 'Dýňová semínka loupaná',        jednotka: 'g',  cena_baleni: 2350, obsah_baleni: 25000 },
  { nazev: 'Mandle loupané',                jednotka: 'g',  cena_baleni: 3150, obsah_baleni: 5000,  alergen: 'mandle, ořechy' },
  { nazev: 'Vlašské ořechy',                jednotka: 'g',  cena_baleni: 2150, obsah_baleni: 5000,  alergen: 'ořechy' },

  // 🧈 Tuky
  { nazev: 'Máslo selské 82%',              jednotka: 'g',  cena_baleni: 75,   obsah_baleni: 250,   alergen: 'mléko' },
  { nazev: 'Tuk pekařský pevný',            jednotka: 'g',  cena_baleni: 480,  obsah_baleni: 10000 },
  { nazev: 'Margarín rostlinný pekařský',   jednotka: 'g',  cena_baleni: 470,  obsah_baleni: 10000 },
  { nazev: 'Olej slunečnicový',             jednotka: 'ml', cena_baleni: 250,  obsah_baleni: 5000 },
  { nazev: 'Olej řepkový',                  jednotka: 'ml', cena_baleni: 450,  obsah_baleni: 10000 },

  // 🍬 Cukry a sirupy
  { nazev: 'Cukr krupice',                  jednotka: 'g',  cena_baleni: 1290, obsah_baleni: 50000 },
  { nazev: 'Cukr moučka',                   jednotka: 'g',  cena_baleni: 720,  obsah_baleni: 25000 },
  { nazev: 'Cukr třtinový',                 jednotka: 'g',  cena_baleni: 960,  obsah_baleni: 25000 },
  { nazev: 'Cukr vanilkový',                jednotka: 'g',  cena_baleni: 95,   obsah_baleni: 1000 },
  { nazev: 'Med květový',                   jednotka: 'g',  cena_baleni: 1450, obsah_baleni: 5000 },
  { nazev: 'Glukózový sirup',               jednotka: 'g',  cena_baleni: 290,  obsah_baleni: 5000 },

  // 🍞 Droždí a kypřidla
  { nazev: 'Droždí čerstvé',                jednotka: 'g',  cena_baleni: 55,   obsah_baleni: 1000 },
  { nazev: 'Droždí sušené instantní',       jednotka: 'g',  cena_baleni: 125,  obsah_baleni: 500 },
  { nazev: 'Prášek do pečiva',              jednotka: 'g',  cena_baleni: 125,  obsah_baleni: 1000 },
  { nazev: 'Soda jedlá (bikarbona)',        jednotka: 'g',  cena_baleni: 95,   obsah_baleni: 1000 },
  { nazev: 'Amonium uhličitý',              jednotka: 'g',  cena_baleni: 150,  obsah_baleni: 1000 },

  // 🧂 Sůl a koření
  { nazev: 'Sůl jedlá kuchyňská',           jednotka: 'g',  cena_baleni: 225,  obsah_baleni: 25000 },
  { nazev: 'Skořice mletá',                 jednotka: 'g',  cena_baleni: 125,  obsah_baleni: 1000 },
  { nazev: 'Kmín celý',                     jednotka: 'g',  cena_baleni: 145,  obsah_baleni: 1000 },
  { nazev: 'Vanilkové aroma',               jednotka: 'ml', cena_baleni: 180,  obsah_baleni: 500 },

  // 🥛 Mléčné výrobky
  { nazev: 'Mléko polotučné',               jednotka: 'ml', cena_baleni: 22,   obsah_baleni: 1000,  alergen: 'mléko' },
  { nazev: 'Smetana ke šlehání 33%',        jednotka: 'ml', cena_baleni: 95,   obsah_baleni: 1000,  alergen: 'mléko' },
  { nazev: 'Tvaroh měkký',                  jednotka: 'g',  cena_baleni: 70,   obsah_baleni: 1000,  alergen: 'mléko' },
  { nazev: 'Tvaroh tučný',                  jednotka: 'g',  cena_baleni: 85,   obsah_baleni: 1000,  alergen: 'mléko' },

  // 🥚 Vejce
  { nazev: 'Vejce slepičí M',               jednotka: 'g',  cena_baleni: 125,  obsah_baleni: 1800,  alergen: 'vejce', poznamka: '30 ks × 60 g' },

  // 🍫 Čokoláda a kakao
  { nazev: 'Kakao přírodní 100%',           jednotka: 'g',  cena_baleni: 1450, obsah_baleni: 5000 },
  { nazev: 'Čokoláda hořká 70%',            jednotka: 'g',  cena_baleni: 1850, obsah_baleni: 5000,  alergen: 'sója' },
  { nazev: 'Čokoláda mléčná',               jednotka: 'g',  cena_baleni: 1650, obsah_baleni: 5000,  alergen: 'mléko, sója' },

  // 🥧 Náplně, povidla
  { nazev: 'Mák mletý',                     jednotka: 'g',  cena_baleni: 750,  obsah_baleni: 5000 },
  { nazev: 'Povidla švestková',             jednotka: 'g',  cena_baleni: 290,  obsah_baleni: 5000 },
  { nazev: 'Marmeláda meruňková',           jednotka: 'g',  cena_baleni: 320,  obsah_baleni: 5000 },

  // 🍎 Sušené ovoce
  { nazev: 'Rozinky',                       jednotka: 'g',  cena_baleni: 625,  obsah_baleni: 5000 },
  { nazev: 'Brusinky sušené',               jednotka: 'g',  cena_baleni: 850,  obsah_baleni: 5000 },
];

window.otevritImportSurovin = function() {
  state._importSurovinyTab = state._importSurovinyTab || 'balicek';
  state._importSurovinySelected = state._importSurovinySelected || new Set(
    SUROVINY_ZAKLADNI_BALICEK.map((_, i) => i)
  );

  vykreslitImportSurovin();
};

function vykreslitImportSurovin() {
  const tab = state._importSurovinyTab;
  const selected = state._importSurovinySelected;
  const allSelected = selected.size === SUROVINY_ZAKLADNI_BALICEK.length;

  // Body
  const balicekBody = `
    <p style="margin:0 0 10px;font-size:13px;color:var(--text-2)">
      Vyber suroviny, které chceš naimportovat. Pokud surovina se stejným názvem už existuje, <strong>aktualizuje se</strong> (přepíše prázdná pole / cenu).
    </p>
    <p style="margin:0 0 12px;font-size:12px;color:var(--text-3);font-style:italic">
      Ceny jsou orientační velkoobchodní (CZ 2024–2026). Po importu si je můžeš upravit.
    </p>
    <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
      <button class="btn-secondary" style="font-size:12px;padding:6px 12px" onclick="surImportToggleAll(${!allSelected})">
        ${allSelected ? '☐ Odznačit vše' : '☑ Označit vše'}
      </button>
      <span style="font-size:13px;color:var(--text-3);align-self:center">
        Vybráno: <strong>${selected.size}</strong> z ${SUROVINY_ZAKLADNI_BALICEK.length}
      </span>
    </div>
    <div style="max-height:400px;overflow-y:auto;border:1px solid var(--border);border-radius:8px">
      <table class="table" style="margin:0">
        <thead style="position:sticky;top:0;background:var(--surface);z-index:1">
          <tr>
            <th style="width:32px"></th>
            <th>Název</th>
            <th>Jed.</th>
            <th>Alergen</th>
            <th class="num">Cena balení</th>
            <th class="num">Obsah</th>
            <th class="num">Cena/jed.</th>
          </tr>
        </thead>
        <tbody>
          ${SUROVINY_ZAKLADNI_BALICEK.map((s, i) => {
            const ok = selected.has(i);
            const perUnit = s.obsah_baleni > 0 ? (s.cena_baleni / s.obsah_baleni) : 0;
            return `
              <tr style="${!ok ? 'opacity:0.5' : ''}" onclick="surImportToggle(${i})">
                <td><input type="checkbox" ${ok ? 'checked' : ''} onclick="event.stopPropagation();surImportToggle(${i})"></td>
                <td><strong>${esc(s.nazev)}</strong>${s.poznamka ? `<div style="font-size:11px;color:var(--text-3)">${esc(s.poznamka)}</div>` : ''}</td>
                <td>${esc(s.jednotka)}</td>
                <td>${s.alergen ? `<span style="background:#fef3c7;color:#92400e;font-size:11px;padding:1px 6px;border-radius:8px">${esc(s.alergen)}</span>` : '<span style="color:var(--text-3)">—</span>'}</td>
                <td class="num">${s.cena_baleni.toFixed(0)} Kč</td>
                <td class="num">${s.obsah_baleni.toLocaleString('cs-CZ')} ${esc(s.jednotka)}</td>
                <td class="num" style="color:var(--primary-dark);font-weight:600">${perUnit.toFixed(4).replace(/\.?0+$/, '').replace('.', ',')} Kč</td>
              </tr>
            `;
          }).join('')}
        </tbody>
      </table>
    </div>
  `;

  // Quick-entry rows v state
  if (!state._importSurovinyRows || state._importSurovinyRows.length === 0) {
    state._importSurovinyRows = [emptyImportRow(), emptyImportRow(), emptyImportRow()];
  }

  const rucniBody = `
    <p style="margin:0 0 12px;font-size:14px;color:var(--text-2)">
      Zapiš každou surovinu jako řádek. Vyplň co znáš — <strong>cena za kg/jed se dopočítá automaticky</strong>.
    </p>
    <div style="overflow-x:auto;border:1px solid var(--border);border-radius:8px">
      <table class="table sur-import-table" style="margin:0;min-width:840px">
        <thead>
          <tr>
            <th style="min-width:200px">Název *</th>
            <th style="width:90px">Jednotka</th>
            <th style="width:130px" class="num">Cena za balení</th>
            <th style="width:130px" class="num">Obsah balení</th>
            <th style="width:110px" class="num">Ks v krabici</th>
            <th style="width:110px" class="num">Hmotnost/ks (g)</th>
            <th style="width:130px" class="num">Cena/jed.</th>
            <th style="width:36px"></th>
          </tr>
        </thead>
        <tbody>
          ${state._importSurovinyRows.map((r, i) => {
            const cenaJed = (parseFloat(r.cena_baleni) > 0 && parseFloat(r.obsah_baleni) > 0)
              ? parseFloat(r.cena_baleni) / parseFloat(r.obsah_baleni)
              : 0;
            const cenaKg = (r.jednotka === 'g' && cenaJed > 0) ? cenaJed * 1000 : 0;
            return `
              <tr>
                <td>
                  <input class="form-input sur-import-inp" data-row="${i}" data-field="nazev" value="${esc(r.nazev)}" placeholder="např. Pšeničná mouka T530" oninput="surImportRowEdit(${i},'nazev',this.value)">
                </td>
                <td>
                  <select class="form-input sur-import-inp" data-row="${i}" data-field="jednotka" onchange="surImportRowEdit(${i},'jednotka',this.value)">
                    <option value="g"  ${r.jednotka === 'g'  ? 'selected' : ''}>g</option>
                    <option value="kg" ${r.jednotka === 'kg' ? 'selected' : ''}>kg</option>
                    <option value="ks" ${r.jednotka === 'ks' ? 'selected' : ''}>ks</option>
                    <option value="ml" ${r.jednotka === 'ml' ? 'selected' : ''}>ml</option>
                    <option value="l"  ${r.jednotka === 'l'  ? 'selected' : ''}>l</option>
                  </select>
                </td>
                <td>
                  <input class="form-input sur-import-inp num" type="number" step="0.01" min="0" value="${r.cena_baleni}" placeholder="0" oninput="surImportRowEdit(${i},'cena_baleni',this.value)">
                </td>
                <td>
                  <input class="form-input sur-import-inp num" type="number" step="0.01" min="0" value="${r.obsah_baleni}" placeholder="0" oninput="surImportRowEdit(${i},'obsah_baleni',this.value)" title="V jednotce balení (g/ks/ml…)">
                </td>
                <td>
                  <input class="form-input sur-import-inp num" type="number" step="1" min="0" value="${r.ks_krabice || ''}" placeholder="—" oninput="surImportRowEdit(${i},'ks_krabice',this.value)" title="Počet kusů v přepravce/krabici (jen poznámka)">
                </td>
                <td>
                  <input class="form-input sur-import-inp num" type="number" step="0.1" min="0" value="${r.hmotnost_ks || ''}" placeholder="—" oninput="surImportRowEdit(${i},'hmotnost_ks',this.value)" title="Pro jednotka 'ks' — hmotnost 1 ks">
                </td>
                <td class="num" data-computed-row="${i}" style="font-weight:700;color:var(--primary-dark);font-variant-numeric:tabular-nums">
                  ${cenaJed > 0 ? `${cenaJed.toFixed(4).replace(/\.?0+$/, '').replace('.', ',')} Kč/${esc(r.jednotka)}` : '<span style="color:var(--text-3);font-weight:400">—</span>'}
                  ${cenaKg > 0 ? `<div style="font-size:11px;font-weight:600;color:var(--text-3);margin-top:2px">${cenaKg.toFixed(2).replace('.', ',')} Kč/kg</div>` : ''}
                </td>
                <td>
                  <button class="btn-danger-corner" onclick="surImportRowRemove(${i})" title="Odebrat řádek" aria-label="Odebrat">×</button>
                </td>
              </tr>
            `;
          }).join('')}
        </tbody>
      </table>
    </div>
    <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;align-items:center">
      <button class="btn-secondary" onclick="surImportRowAdd()">+ Přidat další řádek</button>
      <button class="btn-secondary" onclick="surImportRowsAdd5()">+ Přidat 5 řádků</button>
      <span class="sur-import-counter" style="margin-left:auto;font-size:13px;color:var(--text-3)">
        ${state._importSurovinyRows.filter(r => (r.nazev || '').trim()).length} vyplněných řádků
      </span>
    </div>
    <div style="margin-top:10px;padding:10px 12px;background:var(--surface-2);border-radius:6px;font-size:12px;color:var(--text-3)">
      💡 <strong>Tip:</strong> "Obsah balení" je množství ve zvolené jednotce — pro 25 kg pytel mouky napiš <code>25000</code> (g) nebo přepni na <code>kg</code> a napiš <code>25</code>. Cena/kg se přepočítá sama.
    </div>
  `;

  const pocetVyplnenych = (state._importSurovinyRows || []).filter(r => (r.nazev || '').trim()).length;
  const csvBody = renderCsvImportBody();
  const csvPocet = (state._csvImportPreview?.rows || []).length;

  openModal('📥 Import surovin', `
    <nav class="modal-tabs">
      <button class="modal-tab ${tab === 'balicek' ? 'active' : ''}" onclick="state._importSurovinyTab='balicek';vykreslitImportSurovin()">📦 Základní balíček (${SUROVINY_ZAKLADNI_BALICEK.length})</button>
      <button class="modal-tab ${tab === 'rucni' ? 'active' : ''}" onclick="state._importSurovinyTab='rucni';vykreslitImportSurovin()">✍️ Rychlý zápis</button>
      <button class="modal-tab ${tab === 'csv' ? 'active' : ''}" onclick="state._importSurovinyTab='csv';vykreslitImportSurovin()">📋 CSV párovačka</button>
    </nav>

    <div class="modal-tab-pane active">
      ${tab === 'balicek' ? balicekBody : (tab === 'rucni' ? rucniBody : csvBody)}
    </div>

    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <div style="flex:1"></div>
      ${tab === 'balicek'
        ? `<button class="btn-primary btn-green" onclick="surImportBalicek()" ${selected.size === 0 ? 'disabled' : ''}>✓ Naimportovat ${selected.size > 0 ? selected.size : ''} surovin</button>`
        : tab === 'rucni'
        ? `<button class="btn-primary btn-green sur-import-submit" onclick="surImportRucni()" ${pocetVyplnenych === 0 ? 'disabled' : ''}>✓ Naimportovat ${pocetVyplnenych > 0 ? pocetVyplnenych : ''} surovin</button>`
        : `<button class="btn-primary btn-green" onclick="surImportCsvSubmit()" ${csvPocet === 0 ? 'disabled' : ''}>✓ Naimportovat ${csvPocet > 0 ? csvPocet : ''} surovin</button>`
      }
    </div>
  `, 'wide');
}

