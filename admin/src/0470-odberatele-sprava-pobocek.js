// =============================================================
// ODBĚRATELÉ + správa POBOČEK
// =============================================================
// Katalog typů odběratelů (kategorie) — používá se v selectu a filtru
window.ODB_TYPY = [
  { id: 'restaurace',  label: 'Restaurace',  ikona: '🍽️' },
  { id: 'hotel',       label: 'Hotel',       ikona: '🏨' },
  { id: 'kavarna',     label: 'Kavárna',     ikona: '☕' },
  { id: 'pekarna',     label: 'Provoz',     ikona: '🥖' },
  { id: 'cukrarna',    label: 'Cukrárna',    ikona: '🧁' },
  { id: 'jidelna',     label: 'Jídelna',     ikona: '🍲' },
  { id: 'skola',       label: 'Škola/MŠ',    ikona: '🎓' },
  { id: 'nemocnice',   label: 'Nemocnice',   ikona: '🏥' },
  { id: 'firma',       label: 'Firma',       ikona: '🏢' },
  { id: 'urad',        label: 'Úřad',        ikona: '🏛️' },
  { id: 'maloobchod',  label: 'Maloobchod',  ikona: '🏪' },
  { id: 'velkoobchod', label: 'Velkoobchod', ikona: '📦' },
  { id: 'penzion',     label: 'Penzion',     ikona: '🛏️' },
  { id: 'catering',    label: 'Catering',    ikona: '🥗' },
  { id: 'jine',        label: 'Jiné',        ikona: '📌' },
];

window.odbTypByKey = function(key) {
  if (!key) return null;
  return (window.ODB_TYPY || []).find(t => t.id === String(key)) || null;
};

window.odbTypBadge = function(key) {
  const t = window.odbTypByKey(key);
  if (!t) return '';
  return `<span class="odb-typ-badge" title="${esc(t.label)}">${t.ikona} ${esc(t.label)}</span>`;
};

// Filter state — který typ je vybraný
window.odbFilterTyp = window.odbFilterTyp || '';

async function renderOdberatele() {
  const c0 = document.getElementById('content');
  if (c0) c0.innerHTML = `
    <div class="page-head"><div><h1 class="page-title">👥 Odběratelé</h1><p class="page-sub">${skeletonLine('120px', '12px')}</p></div></div>
    <div class="card-block">${skeletonTable(8)}</div>
  `;
  const filtrTyp = window.odbFilterTyp || '';
  const filtrQ = (window.odbFilterQ || '').trim();
  const qs = new URLSearchParams();
  if (filtrTyp) qs.set('typ', filtrTyp);
  if (filtrQ) qs.set('q', filtrQ);
  const url = qs.toString() ? `admin_odberatele.php?${qs.toString()}` : 'admin_odberatele.php';
  let [list, stats] = await Promise.all([
    api(url).catch(() => []),
    api('admin_odberatele.php?action=typy_stats').catch(() => []),
  ]);
  // 🆕 v2.9.288 — defenzivní fallback (server může vrátit error/non-array)
  if (!Array.isArray(list)) list = [];
  if (!Array.isArray(stats)) stats = [];
  const c = document.getElementById('content');

  // Spočítej celkový počet (suma ze stats) pro pillsy "Vše"
  const totalAll = stats.reduce((s, r) => s + parseInt(r.pocet || 0, 10), 0);

  // Pillsy — typy odběratele (větší + samostatná ikona pro CSS scaling)
  const typPillsy = `
    <button class="odb-typ-pill ${filtrTyp === '' ? 'is-active' : ''}" onclick="setOdbTypFilter('')">
      <span class="odb-typ-pill-ico">📋</span><span class="odb-typ-pill-lbl">Vše</span><span class="odb-typ-pill-count">${totalAll}</span>
    </button>
    ${(window.ODB_TYPY || []).map(t => {
      const stat = (stats || []).find(s => s.typ === t.id);
      const pocet = stat ? parseInt(stat.pocet, 10) : 0;
      if (pocet === 0 && filtrTyp !== t.id) return ''; // skryj prázdné kategorie kromě aktivní
      return `
        <button class="odb-typ-pill ${filtrTyp === t.id ? 'is-active' : ''}" onclick="setOdbTypFilter('${t.id}')">
          <span class="odb-typ-pill-ico">${t.ikona}</span><span class="odb-typ-pill-lbl">${esc(t.label)}</span><span class="odb-typ-pill-count">${pocet}</span>
        </button>`;
    }).join('')}
    ${(() => {
      const nez = (stats || []).find(s => s.typ === '_nezarazeno');
      const pocet = nez ? parseInt(nez.pocet, 10) : 0;
      if (pocet === 0) return '';
      return `
        <button class="odb-typ-pill ${filtrTyp === '_nezarazeno' ? 'is-active' : ''}" onclick="setOdbTypFilter('_nezarazeno')">
          <span class="odb-typ-pill-ico">❓</span><span class="odb-typ-pill-lbl">Nezařazeno</span><span class="odb-typ-pill-count">${pocet}</span>
        </button>`;
    })()}
  `;

  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">👥 Odběratelé</h1>
        <p class="page-sub">${list.length} odběratelů${filtrTyp ? ` v kategorii „${esc((window.odbTypByKey(filtrTyp) || {label: 'Nezařazeno'}).label)}"` : ''}</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        ${adminOnly('<button class="btn-secondary" onclick="otevritImportVcard()" title="Import vizitek (vCard z mobilu nebo aplikací)">📇 Import vizitek</button>')}
        ${adminOnly('<button class="btn-secondary" onclick="otevritImportOdberatelu()" title="Hromadný import odběratelů z JSON">📥 Import JSON/CSV</button>')}
        <button class="btn-primary btn-green btn-big-action" onclick="editOdberatel()" style="font-size:18px !important;font-weight:800 !important;padding:18px 32px !important;min-height:64px !important;border-radius:12px !important;letter-spacing:0.3px !important">+ Nový odběratel</button>
      </div>
    </div>

    <!-- Typové filtry (chips/pillsy) -->
    <div class="odb-typ-pills">
      ${typPillsy}
    </div>

    <div class="filters">
      <input class="filter-input" type="search" id="odb-q" placeholder="Hledat (název, IČO, email)..."
             value="${esc(filtrQ)}"
             oninput="if(event.key==='Enter')applyOdbFilter()" onkeydown="if(event.key==='Enter')applyOdbFilter()">
      <button class="btn-secondary" onclick="applyOdbFilter()">Hledat</button>
      ${filtrQ ? `<button class="btn-secondary" onclick="window.odbFilterQ='';renderOdberatele()" title="Smazat hledání">✕</button>` : ''}
    </div>

    <!-- Desktop: tabulka -->
    <div class="card-block desktop-only-block">
      <table class="table">
        <thead>
          <tr>
            <th>Č.</th><th>Název</th><th>IČO</th><th>Login</th>
            <th class="num">Pobočky</th><th class="num">Objednávek</th><th class="num">Tržba celkem</th>
            <th>Stav</th><th></th>
          </tr>
        </thead>
        <tbody>
          ${list.map((o) => `
            <tr class="row-clickable" onclick="editOdberatel(${o.id})">
              <td>${esc(o.cislo || '')}</td>
              <td class="odberatel-row-name">
                <strong>${esc(o.nazev)}</strong>
                ${o.typ ? `<div style="margin-top:2px">${window.odbTypBadge(o.typ)}</div>` : ''}
                ${(o.ulice || o.mesto) ? `<div style="font-size:11px;color:var(--text-3);margin-top:2px">${esc([o.ulice, o.psc, o.mesto].filter(Boolean).join(', '))}</div>` : ''}
                ${o.telefon ? `<div style="font-size:11px;margin-top:1px"><a href="tel:${esc(String(o.telefon).replace(/[\s-]/g, ''))}" onclick="event.stopPropagation();" style="color:var(--text-3);text-decoration:none">📞 ${esc(o.telefon)}</a></div>` : ''}
                ${o.email ? `<div style="font-size:11px;margin-top:1px"><a href="mailto:${esc(o.email)}" onclick="event.stopPropagation();" style="color:var(--text-3);text-decoration:none">✉️ ${esc(o.email)}</a></div>` : ''}
              </td>
              <td>${esc(o.ico || '')}</td>
              <td>${o.login_email ? esc(o.login_email) : '<i style="color:var(--text-3)">bez přístupu</i>'}</td>
              <td class="num"><strong>${o.pocet_pobocek}</strong></td>
              <td class="num">${o.pocet_objednavek}</td>
              <td class="num">${fmt(o.trzba_celkem)}</td>
              <td>${o.blokovan ? '<span class="status zrusena">Blokován</span>' : '<span class="status dorucena">Aktivní</span>'}</td>
              <td onclick="event.stopPropagation();" style="white-space:nowrap;text-align:right">
                <span class="doc-badges-row" style="justify-content:flex-end;gap:4px">
                  ${o.pocet_objednavek > 0
                    ? `<a href="#" onclick="zobrazitObjednavkyOdberatele(${o.id});return false" class="doc-badge obj" title="Zobrazit objednávky odběratele">🛒 OBJ ${o.pocet_objednavek}</a>`
                    : `<span class="doc-badge obj unavailable" title="Žádné objednávky">🛒 0</span>`}
                  ${(o.pocet_dl || 0) > 0
                    ? `<a href="#" onclick="zobrazitDodaciOdberatele(${o.id});return false" class="doc-badge dl" title="Zobrazit dodací listy odběratele">📃 DL ${o.pocet_dl}</a>`
                    : `<span class="doc-badge dl unavailable" title="Žádné dodací listy">📃 0</span>`}
                  ${(o.pocet_faktur || 0) > 0
                    ? `<a href="#" onclick="zobrazitFakturyOdberatele(${o.id});return false" class="doc-badge fa" title="Zobrazit faktury odběratele">💰 FA ${o.pocet_faktur}</a>`
                    : `<span class="doc-badge fa unavailable" title="Žádné faktury">💰 0</span>`}
                  <button class="btn-secondary" style="font-size:12px;padding:6px 10px;margin-left:4px" onclick="editOdberatel(${o.id})">Detail</button>
                </span>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>

    <!-- Mobile: kompaktní karty -->
    <div class="mobile-only-block">
      ${list.length === 0 ? (
        // 🔧 v2.0.72 FIX: dříve používalo nedefinovanou proměnnou 'data' → ReferenceError.
        // Teď: pokud nejsou žádní odběratelé celkem (totalAll === 0), ukáže empty state;
        // jinak (existují, ale filtry vrátily 0) ukáže "neodpovídá filtru".
        (totalAll === 0)
          ? emptyState({
              icon: '👥',
              title: 'Zatím žádní odběratelé',
              msg: 'Přidej prvního obchodního partnera. Můžeš taky importovat JSON / CSV nebo nahrát vCard vizitky.',
              actions: `<button class="btn-primary btn-green" onclick="editOdberatel()" style="font-size:15px;padding:11px 22px">+ Nový odběratel</button>`,
            })
          : '<div class="card-block"><div class="empty-state">Žádný odběratel neodpovídá filtru</div></div>'
      ) :
        list.map((o) => `
          <div class="odberatel-card" onclick="editOdberatel(${o.id})">
            <div class="odberatel-card-head">
              <div class="odberatel-card-title">
                <div class="odberatel-card-cislo">${esc(o.cislo || '—')}</div>
                <div class="odberatel-card-nazev">${esc(o.nazev)}</div>
                ${o.typ ? `<div style="margin:2px 0">${window.odbTypBadge(o.typ)}</div>` : ''}
                ${(o.ulice || o.mesto) ? `<div class="odberatel-card-adresa">📍 ${esc([o.ulice, o.psc, o.mesto].filter(Boolean).join(', '))}</div>` : ''}
                ${o.telefon ? `<div class="odberatel-card-adresa"><a href="tel:${esc(String(o.telefon).replace(/[\s-]/g, ''))}" onclick="event.stopPropagation();" class="odberatel-link">📞 ${esc(o.telefon)}</a></div>` : ''}
                ${o.email ? `<div class="odberatel-card-adresa"><a href="mailto:${esc(o.email)}" onclick="event.stopPropagation();" class="odberatel-link">✉️ ${esc(o.email)}</a></div>` : ''}
              </div>
              ${o.blokovan ? '<span class="status zrusena">Blokován</span>' : '<span class="status dorucena">Aktivní</span>'}
            </div>

            <div class="odberatel-card-meta">
              ${o.ico ? `<span>🏢 IČO ${esc(o.ico)}</span>` : ''}
              ${o.login_email
                ? `<span><a href="mailto:${esc(o.login_email)}" onclick="event.stopPropagation();" class="odberatel-link">📧 ${esc(o.login_email)}</a></span>`
                : `<span style="color:var(--text-3)"><i>bez přístupu</i></span>`}
            </div>

            <div class="odberatel-card-stats">
              <div class="odberatel-stat">
                <div class="odberatel-stat-label">Pobočky</div>
                <div class="odberatel-stat-value">${o.pocet_pobocek}</div>
              </div>
              <div class="odberatel-stat">
                <div class="odberatel-stat-label">Objednávek</div>
                <div class="odberatel-stat-value">${o.pocet_objednavek}</div>
              </div>
              <div class="odberatel-stat">
                <div class="odberatel-stat-label">Tržba</div>
                <div class="odberatel-stat-value odberatel-stat-trzba">${fmt(o.trzba_celkem)}</div>
              </div>
            </div>

            <div class="odberatel-card-actions" onclick="event.stopPropagation();">
              ${o.pocet_objednavek > 0
                ? `<a href="#" onclick="zobrazitObjednavkyOdberatele(${o.id});return false" class="doc-badge obj">🛒 Objednávky (${o.pocet_objednavek})</a>`
                : `<span class="doc-badge obj unavailable">🛒 Žádné objednávky</span>`}
              ${(o.pocet_dl || 0) > 0
                ? `<a href="#" onclick="zobrazitDodaciOdberatele(${o.id});return false" class="doc-badge dl">📃 Dodací listy (${o.pocet_dl})</a>`
                : `<span class="doc-badge dl unavailable">📃 Žádné DL</span>`}
              ${(o.pocet_faktur || 0) > 0
                ? `<a href="#" onclick="zobrazitFakturyOdberatele(${o.id});return false" class="doc-badge fa">💰 Faktury (${o.pocet_faktur})</a>`
                : `<span class="doc-badge fa unavailable">💰 Žádné FA</span>`}
            </div>
          </div>
        `).join('')
      }
    </div>
  `;
}

window.applyOdbFilter = function() {
  window.odbFilterQ = document.getElementById('odb-q')?.value || '';
  renderOdberatele();
};

window.setOdbTypFilter = function(typ) {
  window.odbFilterTyp = typ || '';
  renderOdberatele();
};

window.editOdberatel = async function(id = null) {
  const o = id ? await api(`admin_odberatele.php?id=${id}`) : { mista_dodani: [], statistika: { pocet: 0, celkem: 0 } };
  // Cenové skupiny pro select
  let skupiny = [];
  try { skupiny = await api('admin_cenove_skupiny.php'); } catch (e) { /* ignore - může neexistovat sekce */ }
  const skupinyOptions = `
    <option value="">— bez skupiny —</option>
    ${skupiny.filter(s => s.aktivni == 1).map((s) => `
      <option value="${s.id}" ${o.cenova_skupina_id == s.id ? 'selected' : ''}>${esc(s.nazev)}</option>
    `).join('')}
  `;

  // Počet poboček pro badge
  const pocetPobocek = id ? (o.mista_dodani || []).length : 0;

  openModal(id ? `Odběratel ${esc(o.nazev)}` : 'Nový odběratel', `
    ${id ? `
      <div class="modal-stats-box">
        <div>
          <div class="stat-key">Objednávek</div>
          <div class="stat-val">${o.statistika.pocet}</div>
        </div>
        <div>
          <div class="stat-key">Tržba celkem</div>
          <div class="stat-val">${fmt(o.statistika.celkem)}</div>
        </div>
        ${adminOnly(`
          <div style="flex:1;display:flex;justify-content:flex-end;align-items:center;gap:8px">
            <button class="btn-secondary" onclick="zobrazitFakturyOdberatele(${id})">📄 Faktury odběratele</button>
            <button class="btn-secondary" onclick="zobrazitObjednavkyOdberatele(${id})">📋 Objednávky</button>
          </div>
        `)}
      </div>
    ` : ''}

    <nav class="modal-tabs">
      <button class="modal-tab active" data-tab="udaje" onclick="switchModalTab('udaje')">📋 Údaje</button>
      ${id ? `<button class="modal-tab" data-tab="pobocky" onclick="switchModalTab('pobocky')">📍 Pobočky <span class="badge">${pocetPobocek}</span></button>` : ''}
      ${id ? `<button class="modal-tab" data-tab="objednavky" onclick="switchModalTab('objednavky');loadOdberatelObjednavky(${id})">🛒 Objednávky <span class="badge">${o.statistika?.pocet || 0}</span></button>` : ''}
      <button class="modal-tab modal-tab-right" data-tab="pristup" onclick="switchModalTab('pristup')">🔑 Přístup</button>
    </nav>

    <!-- TAB: ÚDAJE -->
    <div class="modal-tab-pane active" data-pane="udaje">
      <div class="form-grid form-grid-tight">
        <div>
          <label class="form-label">Číslo</label>
          <input class="form-input" id="od-cislo" value="${esc(o.cislo || '')}">
        </div>
        <div>
          <label class="form-label">IČO</label>
          <div style="display:flex;gap:6px">
            <input class="form-input" id="od-ico" value="${esc(o.ico || '')}" style="flex:1;min-width:0">
            <button type="button" class="btn-secondary" onclick="odberatelAresLookup()" title="Načíst název, adresu a DIČ z ARES (CZ) / RPO (SK)" style="white-space:nowrap;padding:6px 10px">🔍 ARES</button>
          </div>
        </div>
        <div class="full">
          <label class="form-label">Název firmy *</label>
          <input class="form-input" id="od-nazev" value="${esc(o.nazev || '')}" required>
        </div>
        <div>
          <label class="form-label">DIČ</label>
          <input class="form-input" id="od-dic" value="${esc(o.dic || '')}">
        </div>
        <div>
          <label class="form-label">Splatnost (dní)</label>
          <input class="form-input" id="od-spl" type="number" value="${o.splatnost_dni || 14}">
        </div>
        <div>
          <label class="form-label">🏷️ Typ odběratele</label>
          <select class="form-input" id="od-typ">
            <option value="">— nezařazeno —</option>
            ${(window.ODB_TYPY || []).map(t => `
              <option value="${t.id}" ${o.typ === t.id ? 'selected' : ''}>${t.ikona} ${esc(t.label)}</option>
            `).join('')}
          </select>
        </div>
        <div>
          <label class="form-label">🧾 Slevová skupina</label>
          <select class="form-input" id="od-skupina">${skupinyOptions}</select>
        </div>
        <div class="full">
          <label class="form-label">Sídlo - ulice</label>
          <input class="form-input" id="od-ul" value="${esc(o.ulice || '')}">
        </div>
        <div>
          <label class="form-label">Město</label>
          <input class="form-input" id="od-me" value="${esc(o.mesto || '')}">
        </div>
        <div>
          <label class="form-label">PSČ</label>
          <input class="form-input" id="od-psc" value="${esc(o.psc || '')}">
        </div>
        <div>
          <label class="form-label">E-mail</label>
          <input class="form-input" id="od-em" type="email" value="${esc(o.email || '')}">
        </div>
        <div>
          <label class="form-label">Telefon</label>
          <input class="form-input" id="od-tel" value="${esc(o.telefon || '')}">
        </div>
        <div class="full">
          <label class="form-label">Kontaktní osoba</label>
          <input class="form-input" id="od-ko" value="${esc(o.kontaktni_osoba || '')}">
        </div>
        <div class="full">
          <label class="form-label">Poznámka</label>
          <textarea class="form-textarea" id="od-pozn">${esc(o.poznamka || '')}</textarea>
        </div>
      </div>
    </div>

    ${id ? `
      <!-- TAB: POBOČKY -->
      <div class="modal-tab-pane" data-pane="pobocky">
        <div class="pobocky-head">
          <div>
            <h3 class="pobocky-title">📍 Provozovny <span class="pobocky-count">${o.mista_dodani.length}</span></h3>
            <p class="pobocky-sub">Místa, kam se odběrateli rozváží zboží. Výchozí pobočka se předvyplňuje při zakládání objednávky.</p>
          </div>
          <button class="btn-primary" onclick="editPobocka(${id})">+ Přidat pobočku</button>
        </div>
        <div class="pobocky-list">
          ${o.mista_dodani.length === 0 ? `
            <div class="pobocky-empty">
              <div class="pobocky-empty-icon">📍</div>
              <div class="pobocky-empty-title">Zatím žádné pobočky</div>
              <div class="pobocky-empty-sub">Přidejte první klepnutím na tlačítko vpravo nahoře.</div>
            </div>
          ` : o.mista_dodani.map((m) => {
              const isDefault = m.vychozi == 1;
              const isInactive = m.aktivni == 0;
              const tel = m.telefon ? String(m.telefon).replace(/[\s-]/g, '') : '';
              return `
              <div class="pobocka-card ${isDefault ? 'is-default' : ''} ${isInactive ? 'is-inactive' : ''}">
                <div class="pobocka-info">
                  <div class="pobocka-name">
                    ${isDefault ? '<span class="pobocka-star" title="Výchozí pobočka">★</span>' : '<span class="pobocka-pin">📍</span>'}
                    <span>${esc(m.nazev)}</span>
                    ${isDefault ? '<span class="vychozi-badge">VÝCHOZÍ</span>' : ''}
                    ${isInactive ? '<span class="vychozi-badge inactive-badge">DEAKTIVOVÁNO</span>' : ''}
                  </div>
                  ${m.ulice || m.mesto || m.psc ? `
                    <div class="pobocka-addr">
                      ${m.ulice ? esc(m.ulice) : ''}${m.ulice && (m.mesto || m.psc) ? ', ' : ''}${esc([m.psc, m.mesto].filter(Boolean).join(' '))}
                    </div>
                  ` : ''}
                  ${m.kontaktni_osoba || m.telefon || m.email || m.cas_dodani ? `
                    <div class="pobocka-meta">
                      ${m.kontaktni_osoba ? `<span>👤 ${esc(m.kontaktni_osoba)}</span>` : ''}
                      ${m.telefon ? `<a href="tel:${esc(tel)}" onclick="event.stopPropagation();" class="pobocka-link">📞 ${esc(m.telefon)}</a>` : ''}
                      ${m.email ? `<a href="mailto:${esc(m.email)}" onclick="event.stopPropagation();" class="pobocka-link">✉️ ${esc(m.email)}</a>` : ''}
                      ${m.cas_dodani ? `<span>⏰ ${esc(m.cas_dodani)}</span>` : ''}
                    </div>
                  ` : ''}
                  ${m.pokyny_pro_ridice ? `
                    <div class="pobocka-pokyny">
                      <span class="pobocka-pokyny-label">🚚 Pokyny pro řidiče:</span>
                      <span class="pobocka-pokyny-text">${esc(m.pokyny_pro_ridice)}</span>
                    </div>
                  ` : ''}
                </div>
                <div class="pobocka-actions">
                  ${adminOnly(`<button class="btn-secondary btn-icon-only" onclick="zobrazitDodaciPobocky(${m.id}, ${id})" title="Dodací listy této pobočky">📦</button>`)}
                  ${adminOnly(`<button class="btn-secondary btn-icon-only" onclick="zobrazitFakturyPobocky(${m.id}, ${id})" title="Faktury této pobočky">📄</button>`)}
                  <button class="btn-secondary" onclick="editPobocka(${id}, ${m.id})">Upravit</button>
                  ${adminOnly(`<button class="btn-danger btn-icon-only" onclick="smazatPobocku(${m.id}, ${id})" title="Smazat pobočku">🗑️</button>`)}
                </div>
              </div>
            `;}).join('')
          }
        </div>
      </div>
      <!-- TAB: OBJEDNÁVKY (embedded list s filtrem) -->
      <div class="modal-tab-pane" data-pane="objednavky">
        <div class="ob-tab-bar" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;align-items:center">
          <input type="search" id="od-obj-q" placeholder="🔍 hledat číslo/poznámku..." style="flex:1;min-width:160px;padding:8px 12px;border:1px solid var(--border);border-radius:8px"
                 oninput="filterOdberatelObjednavky(${id})">
          <select id="od-obj-stav" onchange="filterOdberatelObjednavky(${id})" style="padding:8px 12px;border:1px solid var(--border);border-radius:8px">
            <option value="">Všechny stavy</option>
            <option value="nova">🆕 Nová</option>
            <option value="priprava">🥖 Příprava</option>
            <option value="expedice">📦 Expedice</option>
            <option value="doruceno">✅ Doručeno</option>
            <option value="zruseno">🚫 Zrušeno</option>
          </select>
          <select id="od-obj-period" onchange="filterOdberatelObjednavky(${id})" style="padding:8px 12px;border:1px solid var(--border);border-radius:8px">
            <option value="">Vše období</option>
            <option value="7">Posledních 7 dní</option>
            <option value="30">Posledních 30 dní</option>
            <option value="90">Posledních 90 dní</option>
            <option value="365">Posledních 12 měsíců</option>
          </select>
          <button class="btn-secondary" onclick="window.zobrazitObjednavkyOdberatele(${id});closeModal()" title="Otevřít celou stránku objednávek">🔗 Celá stránka</button>
        </div>
        <div id="od-obj-list">
          <div class="empty-state" style="padding:30px;text-align:center;color:var(--text-3)">⏳ Načítám objednávky…</div>
        </div>
      </div>
    ` : ''}

    <!-- TAB: PŘÍSTUP -->
    <div class="modal-tab-pane" data-pane="pristup">
      <div class="form-grid">
        <div>
          <label class="form-label">Přihlašovací email</label>
          <input class="form-input" id="od-log" type="email" value="${esc(o.login_email || '')}" placeholder="pro přihlášení do B2B">
        </div>
        <div>
          <label class="form-label">${id ? 'Nové heslo (volitelné)' : 'Heslo'}</label>
          <input class="form-input" id="od-heslo" type="password" placeholder="${id ? 'Ponechat prázdné = beze změny' : ''}">
        </div>
        <div class="full">
          <div class="checkbox-row">
            <input type="checkbox" id="od-blok" ${o.blokovan == 1 ? 'checked' : ''}>
            <label for="od-blok">Blokovat účet (odběratel se nemůže přihlásit)</label>
          </div>
        </div>
        <div class="full">
          <div class="checkbox-row">
            <input type="checkbox" id="od-notif" ${o.notif_emaily != 0 ? 'checked' : ''}>
            <label for="od-notif">📧 Posílat e-mailové notifikace (potvrzení objednávky, expedice…)</label>
          </div>
        </div>
        ${!id ? `
          <div class="full">
            <div class="checkbox-row">
              <input type="checkbox" id="od-vytvorit-pobocku" checked>
              <label for="od-vytvorit-pobocku">Automaticky vytvořit hlavní provozovnu z adresy sídla</label>
            </div>
          </div>
        ` : ''}
      </div>
    </div>

    <div class="form-actions">
      ${id ? adminOnly(`<div class="form-actions-icons-row"><button class="btn-danger-corner" onclick="smazatOdberatele(${id})" title="Smazat odběratele" aria-label="Smazat odběratele">🗑️</button></div><div style="flex:1"></div>`) : ''}
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary" onclick="ulozitOdberatele(${id || 'null'})">Uložit</button>
    </div>
  `, 'wide');
};

window.ulozitOdberatele = async function(id) {
  const data = {
    id: id || undefined,
    cislo: document.getElementById('od-cislo').value || null,
    nazev: document.getElementById('od-nazev').value.trim(),
    ico: document.getElementById('od-ico').value || null,
    dic: document.getElementById('od-dic').value || null,
    ulice: document.getElementById('od-ul').value || null,
    mesto: document.getElementById('od-me').value || null,
    psc: document.getElementById('od-psc').value || null,
    email: document.getElementById('od-em').value || null,
    telefon: document.getElementById('od-tel').value || null,
    kontaktni_osoba: document.getElementById('od-ko').value || null,
    login_email: document.getElementById('od-log').value || null,
    splatnost_dni: parseInt(document.getElementById('od-spl').value) || 14,
    cenova_skupina_id: parseInt(document.getElementById('od-skupina')?.value) || null,
    blokovan: document.getElementById('od-blok').checked ? 1 : 0,
    notif_emaily: document.getElementById('od-notif')?.checked ? 1 : 0,
    poznamka: document.getElementById('od-pozn').value || null,
    typ: document.getElementById('od-typ')?.value || null,
  };
  const heslo = document.getElementById('od-heslo').value;
  if (heslo) data.heslo = heslo;
  
  if (!id && document.getElementById('od-vytvorit-pobocku')?.checked) {
    data.vytvorit_hlavni_pobocku = true;
  }
  
  if (!data.nazev) return alert('Vyplňte název');
  
  try {
    await api('admin_odberatele.php', {
      method: id ? 'PUT' : 'POST',
      body: JSON.stringify(data),
    });
    closeModal();
    navigate('odberatele');
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.smazatOdberatele = async function(id) {
  if (!await confirmDelete2x({ co: 'tohoto odběratele', detail: 'Pokud má objednávky, bude jen zablokován (historické doklady zůstanou).' })) return;
  await api(`admin_odberatele.php?id=${id}`, { method: 'DELETE' });
  closeModal();
  navigate('odberatele');
};

/**
 * Skratka: zobrazí faktury patřící vybranému odběrateli.
 * Zavře modal odběratele, přejde do sekce Faktury, vyplní hledání jménem odběratele.
 */
window.zobrazitFakturyOdberatele = async function(odberatel_id) {
  // Načti název odběratele kvůli filtrování
  let nazev = '';
  try {
    const o = await api(`admin_odberatele.php?id=${odberatel_id}`);
    nazev = o.nazev || '';
  } catch (e) {}
  closeModal();
  await navigate('faktury');
  // Po renderu nastav hledání a aplikuj
  setTimeout(() => {
    const q = document.getElementById('ff-q');
    if (q) {
      q.value = nazev;
      if (typeof applyFakturyFilters === 'function') applyFakturyFilters();
    }
  }, 50);
};

/**
 * Skratka: zobrazí dodací listy patřící vybranému odběrateli.
 */
window.zobrazitDodaciOdberatele = async function(odberatel_id) {
  let nazev = '';
  try {
    const o = await api(`admin_odberatele.php?id=${odberatel_id}`);
    nazev = o.nazev || '';
  } catch (e) {}
  closeModal();
  await navigate('dodaci_listy');
  setTimeout(() => {
    const q = document.getElementById('dlf-q');
    if (q) {
      q.value = nazev;
      if (typeof applyDlFilters === 'function') applyDlFilters();
    }
  }, 50);
};

/**
 * Skratka: zobrazí objednávky patřící vybranému odběrateli.
 */
/**
 * 🛒 Load orders for selected odběratel inside modal tab.
 * Cachuje data v window._odObjCache aby filtr nebyl třeba refetch.
 */
window.loadOdberatelObjednavky = async function(odberatelId) {
  const listEl = document.getElementById('od-obj-list');
  if (!listEl) return;
  listEl.innerHTML = '<div class="empty-state" style="padding:30px;text-align:center;color:var(--text-3)">⏳ Načítám objednávky…</div>';
  try {
    const data = await api(`admin_objednavky.php?odberatel_id=${odberatelId}`);
    const orders = Array.isArray(data) ? data : (data.objednavky || data.list || []);
    window._odObjCache = orders;
    window._odObjId = odberatelId;
    renderOdberatelObjednavky();
  } catch (e) {
    listEl.innerHTML = `<div class="empty-state" style="padding:30px;text-align:center;color:var(--danger-text)">❌ ${esc(e.message)}</div>`;
  }
};

window.filterOdberatelObjednavky = function() {
  renderOdberatelObjednavky();
};

function renderOdberatelObjednavky() {
  const listEl = document.getElementById('od-obj-list');
  if (!listEl) return;
  const orders = window._odObjCache || [];
  const q = (document.getElementById('od-obj-q')?.value || '').toLowerCase().trim();
  const stav = document.getElementById('od-obj-stav')?.value || '';
  const period = parseInt(document.getElementById('od-obj-period')?.value || '0');

  let filtered = orders;
  if (q) {
    filtered = filtered.filter(o =>
      (o.cislo || '').toLowerCase().includes(q) ||
      (o.poznamka || '').toLowerCase().includes(q)
    );
  }
  if (stav) {
    filtered = filtered.filter(o => o.stav === stav);
  }
  if (period > 0) {
    const cutoff = new Date(); cutoff.setDate(cutoff.getDate() - period);
    const cutoffStr = cutoff.toISOString().slice(0, 10);
    filtered = filtered.filter(o => (o.datum_objednani || '') >= cutoffStr);
  }

  if (filtered.length === 0) {
    listEl.innerHTML = '<div class="empty-state" style="padding:30px;text-align:center;color:var(--text-3)">Žádné objednávky neodpovídají filtru.</div>';
    return;
  }

  const stavBadge = (s) => {
    const meta = {
      'nova':       { bg:'#dbeafe', fg:'#1e40af', ic:'🆕' },
      'priprava':   { bg:'#fef3c7', fg:'#854F0B', ic:'🥖' },
      'expedice':   { bg:'#fed7aa', fg:'#9a3412', ic:'📦' },
      'doruceno':   { bg:'#dcfce7', fg:'#166534', ic:'✅' },
      'zruseno':    { bg:'#fee2e2', fg:'#991b1b', ic:'🚫' },
    }[s] || { bg:'#f5f5f7', fg:'#6e6e73', ic:'•' };
    return `<span style="background:${meta.bg};color:${meta.fg};padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700">${meta.ic} ${esc(s)}</span>`;
  };

  const total = filtered.reduce((s, o) => s + parseFloat(o.castka_celkem || 0), 0);

  listEl.innerHTML = `
    <div style="display:flex;justify-content:space-between;padding:8px 12px;background:#fff8e8;border-radius:8px;margin-bottom:10px;font-size:13px">
      <strong>${filtered.length}</strong> ${filtered.length === 1 ? 'objednávka' : 'objednávek'}
      <strong style="color:var(--primary-dark)">Celkem: ${fmt(total)}</strong>
    </div>
    <div style="max-height:420px;overflow-y:auto;-webkit-overflow-scrolling:touch">
      <table class="table" style="width:100%;font-size:13px">
        <thead>
          <tr><th>Číslo</th><th>Objednáno</th><th>Dodání</th><th>Stav</th><th class="num">Částka</th><th></th></tr>
        </thead>
        <tbody>
          ${filtered.map(o => `
            <tr class="row-clickable" onclick="closeModal();setTimeout(()=>openObjednavkaDetail(${o.id}),50)">
              <td><strong>${esc(o.cislo)}</strong></td>
              <td>${fmtDate(o.datum_objednani)}</td>
              <td>${fmtDate(o.datum_dodani)}</td>
              <td>${stavBadge(o.stav)}</td>
              <td class="num"><strong>${fmt(o.castka_celkem)}</strong></td>
              <td><button class="btn-link" onclick="event.stopPropagation();closeModal();setTimeout(()=>openObjednavkaDetail(${o.id}),50)">↗ Detail</button></td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
  `;
}

window.zobrazitObjednavkyOdberatele = async function(odberatel_id) {
  let nazev = '';
  try {
    const o = await api(`admin_odberatele.php?id=${odberatel_id}`);
    nazev = o.nazev || '';
  } catch (e) {}
  closeModal();
  await navigate('objednavky');
  setTimeout(() => {
    const q = document.getElementById('of-q');
    if (q) {
      q.value = nazev;
      if (typeof applyObjFilters === 'function') applyObjFilters();
    }
  }, 50);
};

/**
 * Skratka: zobrazí faktury, kde figuruje konkrétní pobočka jako místo dodání.
 */
window.zobrazitFakturyPobocky = async function(pobocka_id, odberatel_id) {
  // Pobočka má svůj název - načti ho a použij jako hledání
  let nazev = '';
  try {
    const seznam = await api(`admin_pobocky.php?odberatel_id=${odberatel_id}`);
    const m = seznam.find(x => x.id == pobocka_id);
    nazev = m ? m.nazev : '';
  } catch (e) {}
  closeModal();
  await navigate('faktury');
  setTimeout(() => {
    const q = document.getElementById('ff-q');
    if (q) {
      q.value = nazev;
      if (typeof applyFakturyFilters === 'function') applyFakturyFilters();
    }
  }, 50);
};

/**
 * Skratka: zobrazí dodací listy patřící vybrané pobočce.
 */
window.zobrazitDodaciPobocky = async function(pobocka_id, odberatel_id) {
  let nazev = '';
  try {
    const seznam = await api(`admin_pobocky.php?odberatel_id=${odberatel_id}`);
    const m = seznam.find(x => x.id == pobocka_id);
    nazev = m ? m.nazev : '';
  } catch (e) {}
  closeModal();
  await navigate('dodaci_listy');
  setTimeout(() => {
    const q = document.getElementById('dlf-q');
    if (q) {
      q.value = nazev;
      if (typeof applyDlFilters === 'function') applyDlFilters();
    }
  }, 50);
};

