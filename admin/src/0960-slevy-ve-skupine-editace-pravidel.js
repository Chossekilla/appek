// =============================================================
// SLEVY VE SKUPINĚ - editace pravidel
// =============================================================
window.otevritSlevySkupiny = async function(skupina_id, nazev) {
  // Flag: po sk_pridatOdb / sk_odebratOdb se vrátit sem (ne do otevritSkupinu)
  state._sk_back_to_slevy = { id: skupina_id, nazev };
  try {
    const slevy = await api(`admin_cenove_skupiny.php?id=${skupina_id}&action=slevy`);
    // Načteme i kategorie a výrobky pro výběr v dropdownu
    const vyrData = await api('admin_vyrobky.php');
    // Načteme detail skupiny — kvůli členům
    let skDetail = { odberatele: [], odberatele_volni: [] };
    try { skDetail = await api(`admin_cenove_skupiny.php?id=${skupina_id}`); }
    catch (e) {}
    const odberatele = Array.isArray(skDetail.odberatele) ? skDetail.odberatele : [];
    const odbVolni = Array.isArray(skDetail.odberatele_volni) ? skDetail.odberatele_volni : [];

    const kategorieOptions = vyrData.kategorie.map((k) =>
      `<option value="${k.id}">${esc(k.ikona)} ${esc(k.nazev)}</option>`
    ).join('');

    // 🆕 v3.0.279 — data pro searchable combobox výrobku (sl-vyrobek) místo giant <select>
    window._slVyrobky = vyrData.vyrobky.filter((v) => v.aktivni == 1);

    openModal(`🧾 Slevy skupiny: ${nazev}`, `
      <!-- Sekce: existující pravidla slev -->
      <div class="card-block" style="padding:0;margin-bottom:14px;background:white;border:1px solid var(--border);border-radius:10px;overflow:hidden">
        <div style="padding:12px 14px;border-bottom:1px solid var(--border);background:var(--surface-2)">
          <h4 style="margin:0;font-size:14px">📋 Pravidla slev <span style="color:var(--text-3);font-weight:400;font-size:12px">(${slevy.length})</span></h4>
          <small style="color:var(--text-3);font-size:11px">Pravidlo platí pro celou kategorii NEBO konkrétní výrobek. Výrobek vyhrává nad kategorií.</small>
        </div>

      ${slevy.length === 0 ? `
        <div style="padding:32px 20px;text-align:center;background:white">
          <div style="font-size:36px;margin-bottom:8px">💸</div>
          <h4 style="margin:0 0 6px;font-size:14px;color:var(--text-2)">Zatím žádná pravidla slev</h4>
          <p style="font-size:12px;color:var(--text-3);margin:0;line-height:1.5">
            Přidej první pravidlo dole — můžeš dát procentní slevu na celou kategorii<br>
            (např. „Pečivo −15 %") nebo pevnou cenu na konkrétní výrobek.
          </p>
        </div>
      ` : `
      <!-- Desktop tabulka -->
      <div class="desktop-only-block">
        <table class="table" style="margin:0">
          <thead>
            <tr>
              <th>Cíl</th>
              <th class="num">Sleva / Pevná cena</th>
              <th>Poznámka</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="slevy-tbody">
            ${slevy.map((s) => {
              let cil;
              if (s.vyrobek_id) {
                cil = `🥖 ${esc(s.vyrobek_nazev)} <span style="color:var(--text-3);font-size:11px">(${esc(s.vyrobek_cislo || '')})</span>`;
              } else if (s.kategorie_id) {
                cil = `${esc(s.kategorie_ikona || '📁')} ${esc(s.kategorie_nazev)} <span style="color:var(--text-3);font-size:11px">(kategorie)</span>`;
              } else {
                cil = `🌐 <strong>Celý sortiment</strong> <span style="color:var(--text-3);font-size:11px">(všechny výrobky)</span>`;
              }
              const hodnota = s.pevna_cena !== null
                ? `<strong>${parseFloat(s.pevna_cena).toFixed(2)} Kč</strong> <span style="color:var(--text-3);font-size:11px">pevná</span>`
                : `<strong>${parseFloat(s.sleva_pct).toFixed(1)}%</strong> <span style="color:var(--text-3);font-size:11px">sleva</span>`;
              const search = ((s.vyrobek_nazev || '') + ' ' + (s.vyrobek_cislo || '') + ' ' + (s.kategorie_nazev || '') + ' ' + (s.poznamka || '') + ' ' + (s.pevna_cena !== null ? s.pevna_cena + ' kč pevná' : s.sleva_pct + '% sleva')).toLowerCase();
              return `
                <tr data-search="${esc(search)}">
                  <td>${cil}</td>
                  <td class="num">${hodnota}</td>
                  <td style="color:var(--text-3);font-size:12px">${esc(s.poznamka || '')}</td>
                  <td style="text-align:right">
                    <button class="btn-danger" style="font-size:11px;padding:4px 10px;"
                            onclick="smazatSlevu(${s.id}, ${skupina_id}, '${esc(nazev).replace(/'/g, '')}')">×</button>
                  </td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
      </div>

      <!-- Mobile karty pravidel -->
      <div class="mobile-only-block" style="margin-bottom:20px">
        ${slevy.map((s) => {
          let cilIkona, cilNazev, cilTyp;
          if (s.vyrobek_id) {
            cilIkona = '🥖';
            cilNazev = esc(s.vyrobek_nazev);
            cilTyp = `výrobek ${esc(s.vyrobek_cislo || '')}`;
          } else if (s.kategorie_id) {
            cilIkona = esc(s.kategorie_ikona || '📁');
            cilNazev = esc(s.kategorie_nazev);
            cilTyp = 'kategorie';
          } else {
            cilIkona = '🌐';
            cilNazev = 'Celý sortiment';
            cilTyp = 'všechny výrobky';
          }
          const isPevna = s.pevna_cena !== null;
          const hodnotaText = isPevna
            ? `${parseFloat(s.pevna_cena).toFixed(2)} Kč`
            : `−${parseFloat(s.sleva_pct).toFixed(1)}%`;
          const hodnotaStit = isPevna ? 'pevná cena' : 'sleva';

          return `
            <div class="sleva-card">
              <div class="sleva-card-head">
                <div class="sleva-card-cil">
                  <div class="sleva-card-cil-icon">${cilIkona}</div>
                  <div class="sleva-card-cil-info">
                    <div class="sleva-card-cil-nazev">${cilNazev}</div>
                    <div class="sleva-card-cil-typ">${cilTyp}</div>
                  </div>
                </div>
                <button class="btn-danger sleva-card-x"
                        onclick="smazatSlevu(${s.id}, ${skupina_id}, '${esc(nazev).replace(/'/g, '')}')">×</button>
              </div>
              <div class="sleva-card-foot">
                <div class="sleva-card-hodnota ${isPevna ? 'pevna' : 'pct'}">${hodnotaText}</div>
                <div class="sleva-card-stit">${hodnotaStit}</div>
                ${s.poznamka ? `<div class="sleva-card-pozn">📝 ${esc(s.poznamka)}</div>` : ''}
              </div>
            </div>
          `;
        }).join('')}
      </div>
      `}

      </div> <!-- /card-block s tabulkou -->

      <!-- Sekce: přidat pravidlo (oddělená karta) -->
      <div class="card-block" style="padding:16px;background:#FFFAF1;border:1px solid #E8C988;border-radius:10px">
        <h4 style="margin:0 0 12px;font-size:15px;color:#854F0B">➕ Přidat nové pravidlo slevy</h4>

      <!-- VELKÁ TLAČÍTKA - volba cíle slevy -->
      <div style="margin-bottom:18px">
        <span style="display:block;font-size:13px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;font-weight:500">Aplikovat slevu na</span>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">
          <button class="cilove-tlacitko" id="cil-btn-kategorie" data-typ="kategorie" onclick="vybratTypSlevy('kategorie')">
            <div style="font-size:28px;margin-bottom:6px">📁</div>
            <div style="font-weight:600;font-size:14px">Celou kategorii</div>
            <div style="font-size:12px;color:var(--text-3);margin-top:3px">např. Chleba, Pečivo</div>
          </button>
          <button class="cilove-tlacitko" id="cil-btn-vyrobek" data-typ="vyrobek" onclick="vybratTypSlevy('vyrobek')">
            <div style="font-size:28px;margin-bottom:6px">🥖</div>
            <div style="font-weight:600;font-size:14px">Konkrétní výrobek</div>
            <div style="font-size:12px;color:var(--text-3);margin-top:3px">jen jeden výrobek</div>
          </button>
          <button class="cilove-tlacitko" id="cil-btn-sortiment" data-typ="sortiment" onclick="vybratTypSlevy('sortiment')">
            <div style="font-size:28px;margin-bottom:6px">🌐</div>
            <div style="font-weight:600;font-size:14px">Celý sortiment</div>
            <div style="font-size:12px;color:var(--text-3);margin-top:3px">všechno bez výjimky</div>
          </button>
        </div>
        <input type="hidden" id="sl-typ" value="kategorie">
      </div>

      <div class="form-grid form-grid-tight" style="grid-template-columns:2fr 1fr 1fr">
        <div id="lbl-kategorie">
          <label class="form-label">Kategorie</label>
          <select class="form-input" id="sl-kategorie">${kategorieOptions}</select>
        </div>
        <div id="lbl-vyrobek" style="display:none;position:relative">
          <label class="form-label">Výrobek</label>
          <input type="text" class="form-input" id="sl-vyrobek-search" autocomplete="off" placeholder="Začni psát název nebo kód…"
                 oninput="slVyrobekFilter()" onfocus="slVyrobekFilter()"
                 onblur="setTimeout(function(){var l=document.getElementById('sl-vyrobek-list');if(l)l.style.display='none'},160)">
          <input type="hidden" id="sl-vyrobek">
          <div id="sl-vyrobek-list" style="display:none;position:absolute;z-index:60;left:0;right:0;top:100%;margin-top:2px;max-height:240px;overflow:auto;background:var(--surface);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.14)"></div>
        </div>
        <div id="lbl-sortiment-info" style="display:none;grid-column:1 / -1">
          <div style="padding:11px 14px;background:#FAEEDA;color:#854F0B;border-radius:8px;font-size:13px">
            ℹ️ Sleva se aplikuje na všechny výrobky bez výjimky. Konkrétnější pravidla (kategorie, výrobek) ji přebíjí.
          </div>
        </div>
        <div>
          <label class="form-label">Typ slevy</label>
          <select class="form-input" id="sl-typ-hodnoty" onchange="prepnoutHodnotuSlevy()">
            <option value="pct">Sleva v %</option>
            <option value="pevna">Pevná cena</option>
          </select>
        </div>
        <div id="lbl-pct">
          <label class="form-label">Sleva (%)</label>
          <input class="form-input" id="sl-pct" type="number" min="0" max="100" step="0.1" value="10" style="text-align:right;font-weight:600">
        </div>
        <div id="lbl-pevna" style="display:none">
          <label class="form-label">Pevná cena (Kč bez DPH)</label>
          <input class="form-input" id="sl-pevna" type="number" min="0" step="0.01" placeholder="0.00" style="text-align:right;font-weight:600">
        </div>
        <div class="full">
          <label class="form-label">Poznámka <span style="color:var(--text-3);font-weight:400;font-size:11px">(volitelná)</span></label>
          <input class="form-input" id="sl-poznamka" placeholder="např. „letní akce", „pro stálé zákazníky"…">
        </div>
      </div>

      <div style="display:flex;justify-content:flex-end;margin-top:14px">
        <button class="btn-primary btn-green" onclick="pridatSlevu(${skupina_id}, '${esc(nazev).replace(/'/g, '')}')" style="font-size:14px;padding:10px 24px">➕ Přidat pravidlo</button>
      </div>
      </div> <!-- /card-block přidat -->

      <!-- ČLENOVÉ SKUPINY (odběratelé) — DOLE jako tabulka s filterem -->
      <div class="card-block" style="padding:0;margin-top:14px;background:white;border:1px solid var(--border);border-radius:10px;overflow:hidden">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;padding:12px 14px;border-bottom:1px solid var(--border);background:#FFFAF1">
          <div style="flex:1;min-width:180px">
            <h4 style="margin:0;font-size:14px;color:#854F0B">👥 Odběratelé ve skupině <span style="color:var(--text-3);font-weight:400;font-size:12px">(${odberatele.length})</span></h4>
            <small style="color:var(--text-3);font-size:11px">Tito odběratelé dostávají ceny dle pravidel této skupiny.</small>
          </div>
          <input class="form-input" id="sk-odb-filter" placeholder="🔍 Filtrovat odběratele…" oninput="skOdbFilter(this.value)" style="max-width:240px;font-size:12px;padding:6px 10px" ${odberatele.length === 0 ? 'disabled' : ''}>
          ${odbVolni.length > 0 ? `<button class="btn-secondary" onclick="sk_otevritPridatOdb(${skupina_id})" style="font-size:12px;padding:6px 12px;white-space:nowrap">+ Přidat odběratele</button>` : `<span style="font-size:11px;color:var(--text-3);white-space:nowrap">žádní volní odběratelé</span>`}
        </div>

        ${odberatele.length === 0 ? `
          <div style="padding:24px 20px;text-align:center;color:var(--text-3)">
            <div style="font-size:28px;margin-bottom:6px">🪑</div>
            <p style="font-size:13px;margin:0">Zatím žádní odběratelé v této skupině.</p>
            <p style="font-size:11px;margin:4px 0 0">Klepni „+ Přidat odběratele" nahoře vpravo.</p>
          </div>
        ` : `
          <table class="table" style="margin:0;font-size:13px">
            <thead>
              <tr style="background:var(--surface-2)">
                <th>Název</th>
                <th>IČO</th>
                <th>Město</th>
                <th>E-mail</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="sk-odb-tbody">
              ${odberatele.map(o => {
                const search = ((o.nazev || '') + ' ' + (o.ico || '') + ' ' + (o.mesto || '') + ' ' + (o.email || '')).toLowerCase();
                return `
                <tr data-search="${esc(search)}" ${parseInt(o.aktivni) === 0 ? 'style="opacity:0.55"' : ''}>
                  <td><strong>${esc(o.nazev)}</strong>${parseInt(o.aktivni) === 0 ? ' <span style="font-size:10px;color:var(--text-3)">(neaktivní)</span>' : ''}</td>
                  <td style="font-family:monospace;font-size:11px">${esc(o.ico || '—')}</td>
                  <td style="font-size:12px;color:var(--text-2)">${esc(o.mesto || '—')}</td>
                  <td style="font-size:11px;color:var(--text-2)">${o.email ? esc(o.email) : '<span style="color:#dc2626">⚠ chybí</span>'}</td>
                  <td style="text-align:right;white-space:nowrap">
                    <button class="btn-secondary" onclick="sk_odebratOdb(${skupina_id}, ${o.id}, '${esc((o.nazev || '').replace(/'/g, "\\'"))}')" style="font-size:11px;padding:4px 10px" title="Odebrat ze skupiny">× Odebrat</button>
                  </td>
                </tr>
                `;
              }).join('')}
            </tbody>
          </table>
        `}
      </div>

      <div class="form-actions" style="margin-top:14px">
        <div style="flex:1"></div>
        <!-- "Zavřít" smazáno v v2.5.11 — × v rohu modal-card stačí (větší klikací plocha) -->
      </div>
    `, 'wide');

    // Po vykreslení modalu aktivuj výchozí tlačítko (kategorie)
    setTimeout(() => vybratTypSlevy('kategorie'), 0);
  } catch (e) { alert('Chyba: ' + e.message); }
};

// Filter odběratelů v modalu Slevy skupiny
window.skOdbFilter = function(q) {
  q = (q || '').toLowerCase().trim();
  document.querySelectorAll('#sk-odb-tbody tr[data-search]').forEach(tr => {
    const m = tr.dataset.search || '';
    tr.style.display = m.includes(q) ? '' : 'none';
  });
};

window.vybratTypSlevy = function(typ) {
  // Označit aktivní tlačítko
  ['kategorie', 'vyrobek', 'sortiment'].forEach(t => {
    const btn = document.getElementById('cil-btn-' + t);
    if (btn) btn.classList.toggle('active', t === typ);
  });
  // Uložit stav
  const hidden = document.getElementById('sl-typ');
  if (hidden) hidden.value = typ;

  // Skrýt/ukázat příslušné inputy
  document.getElementById('lbl-kategorie').style.display     = typ === 'kategorie' ? '' : 'none';
  document.getElementById('lbl-vyrobek').style.display       = typ === 'vyrobek'   ? '' : 'none';
  const sortInfo = document.getElementById('lbl-sortiment-info');
  if (sortInfo) sortInfo.style.display = typ === 'sortiment' ? '' : 'none';

  // Pro celý sortiment vynutíme procento (pevná cena nedává smysl)
  const typHodnoty = document.getElementById('sl-typ-hodnoty');
  if (typHodnoty) {
    if (typ === 'sortiment') {
      typHodnoty.value = 'pct';
      typHodnoty.disabled = true;
      Array.from(typHodnoty.options).forEach(opt => {
        if (opt.value === 'pevna') opt.style.display = 'none';
      });
      prepnoutHodnotuSlevy();
    } else {
      typHodnoty.disabled = false;
      Array.from(typHodnoty.options).forEach(opt => { opt.style.display = ''; });
    }
  }
};

// Zachováme starý název pro zpětnou kompatibilitu (pokud někde zůstal)
window.prepnoutTypSlevy = window.vybratTypSlevy;

window.prepnoutHodnotuSlevy = function() {
  const typ = document.getElementById('sl-typ-hodnoty').value;
  document.getElementById('lbl-pct').style.display   = typ === 'pct'   ? '' : 'none';
  document.getElementById('lbl-pevna').style.display = typ === 'pevna' ? '' : 'none';
};

window.pridatSlevu = async function(skupina_id, nazev) {
  const typ_cile = document.getElementById('sl-typ').value;
  const typ_hodnoty = document.getElementById('sl-typ-hodnoty').value;
  const data = {
    skupina_id,
    poznamka: document.getElementById('sl-poznamka').value.trim() || null,
  };
  // Sortiment-wide = oba target IDs zůstanou nedefinované
  if (typ_cile === 'kategorie') {
    data.kategorie_id = parseInt(document.getElementById('sl-kategorie').value);
  } else if (typ_cile === 'vyrobek') {
    data.vyrobek_id = parseInt(document.getElementById('sl-vyrobek').value);
  }
  // typ_cile === 'sortiment' → žádný target ID se neposílá

  if (typ_hodnoty === 'pct') data.sleva_pct  = parseFloat(document.getElementById('sl-pct').value);
  else                       data.pevna_cena = parseFloat(document.getElementById('sl-pevna').value);

  if (data.sleva_pct !== undefined && (isNaN(data.sleva_pct) || data.sleva_pct < 0)) {
    return alert('Zadejte platnou slevu (0-100%)');
  }
  if (data.pevna_cena !== undefined && (isNaN(data.pevna_cena) || data.pevna_cena < 0)) {
    return alert('Zadejte platnou pevnou cenu');
  }

  try {
    await api('admin_cenove_skupiny.php?action=sleva', {
      method: 'POST', body: JSON.stringify(data),
    });
    closeModal();
    otevritSlevySkupiny(skupina_id, nazev);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.smazatSlevu = async function(id, skupina_id, nazev) {
  if (!await confirmDelete2x('toto slevové pravidlo')) return;
  try {
    await api(`admin_cenove_skupiny.php?action=sleva&id=${id}`, { method: 'DELETE' });
    closeModal();
    otevritSlevySkupiny(skupina_id, nazev);
  } catch (e) { alert('Chyba: ' + e.message); }
};

