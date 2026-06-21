// =============================================================
// 🔁 OPAKUJÍCÍ SE OBJEDNÁVKY — pravidla pro auto-generování
// =============================================================
async function renderRecurring() {
  const c = document.getElementById('content');
  c.innerHTML = `<div class="page-head"><h1 class="page-title">🔁 Opakující se objednávky</h1></div><p>Načítám…</p>`;

  try {
    const list = await api('admin_recurring.php');
    c.innerHTML = `
      <div class="page-head">
        <div>
          <h1 class="page-title">🔁 Opakující se objednávky</h1>
          <p class="page-sub">${list.length} pravidel · Automatické generování objednávek dle frekvence</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn-secondary" onclick="navigate('objednavky')">← Objednávky</button>
          <button class="btn-secondary" onclick="recurringSpustit()" title="Vygeneruje objednávky na zítra (test)">⚡ Spustit teď (test)</button>
          <button class="btn-primary btn-green btn-big-action" onclick="recurringEdit(null)" style="font-size:18px !important;font-weight:800 !important;padding:18px 32px !important;min-height:64px !important;border-radius:12px !important">+ Nové pravidlo</button>
        </div>
      </div>

      <div class="card-block" style="margin-bottom:14px;padding:12px 16px;background:var(--info-bg);color:var(--info-text);font-size:13px;line-height:1.6">
        💡 <strong>Jak to funguje:</strong> Vytvoříte pravidlo (např. „Hotel Beránek — pondělí, středa: 30 chlebů + 50 rohlíků").
        Každou noc cron skript (volitelně přes web s tokenem) vygeneruje objednávky pro zítřejší den.
        Anti-duplikát zajistí, že se obj nevytvoří dvakrát.
      </div>

      ${list.length === 0 ? `
        <div class="card-block"><div class="empty-state">Žádná pravidla. Klikni „+ Nové pravidlo" pro vytvoření prvního.</div></div>
      ` : `
        <div class="card-block" style="padding:0">
          <table class="table">
            <thead>
              <tr>
                <th>Název</th>
                <th>Odběratel</th>
                <th>Frekvence</th>
                <th>Položek</th>
                <th class="num">Vygenerováno</th>
                <th>Stav</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              ${list.map(r => `
                <tr class="row-clickable" onclick="recurringEdit(${r.id})">
                  <td><strong>${esc(r.nazev)}</strong></td>
                  <td>${esc(r.odberatel_nazev)}${r.misto_nazev ? ` <span style="color:var(--text-3);font-size:11px">· ${esc(r.misto_nazev)}</span>` : ''}</td>
                  <td>${recurringFmtFrekvence(r)}</td>
                  <td class="num">${r.pocet_polozek || 0}</td>
                  <td class="num">${r.pocet_vygen || 0}×${r.posledni_beh ? `<div style="font-size:10px;color:var(--text-3)">${fmtDate(r.posledni_beh)}</div>` : ''}</td>
                  <td>${r.aktivni == 1 ? '<span class="status dorucena">Aktivní</span>' : '<span class="status zrusena">Pozastaveno</span>'}</td>
                  <td onclick="event.stopPropagation()" style="text-align:right;white-space:nowrap">
                    <button class="btn-secondary" style="font-size:11px;padding:4px 10px" onclick="recurringEdit(${r.id})">Upravit</button>
                    <button class="btn-danger" style="font-size:11px;padding:4px 10px" onclick="recurringSmazat(${r.id}, '${esc(r.nazev).replace(/'/g, '')}')">Smazat</button>
                  </td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      `}
    `;
  } catch (e) {
    c.innerHTML = `<div style="color:var(--danger-text);padding:20px">Chyba: ${esc(e.message)}</div>`;
  }
}

function recurringFmtFrekvence(r) {
  const fmtMap = {
    denne:   '📅 Denně',
    tydne:   '📅 Týdně',
    dvouty:  '📅 Každé 2 týdny',
    mesicne: '📅 Měsíčně (1. v měsíci)',
  };
  const baseTxt = fmtMap[r.frekvence] || r.frekvence;
  const dnyNazvy = ['Po','Út','St','Čt','Pá','So','Ne'];
  const aktivnnDny = (r.dny_v_tydnu || '').split(',').filter(x => x).map(d => dnyNazvy[parseInt(d) - 1]).join(', ');
  return `${baseTxt}${aktivnnDny ? ` · ${aktivnnDny}` : ''}${r.cas_dodani ? ` · ⏰ ${esc(r.cas_dodani)}` : ''}`;
}

window.recurringEdit = async function(id) {
  let r = { id: null, nazev: '', odberatel_id: null, misto_dodani_id: null, frekvence: 'tydne', dny_v_tydnu: '', cas_dodani: '', polozky: [], poznamka: '', aktivni: 1, datum_zacatku: new Date().toISOString().split('T')[0], datum_konce: '' };
  if (id) {
    try { r = await api(`admin_recurring.php?id=${id}`); }
    catch (e) { return alert('Chyba: ' + e.message); }
  }

  // Načti odběratele a výrobky pro výběr
  const [odberatele, vyrobkyData] = await Promise.all([
    api('admin_odberatele.php'),
    api('admin_vyrobky.php'),
  ]);
  const vyrobky = (vyrobkyData.vyrobky || []).filter(v => v.aktivni);

  const dnyCheckboxes = [
    {k: '1', l: 'Po'}, {k: '2', l: 'Út'}, {k: '3', l: 'St'},
    {k: '4', l: 'Čt'}, {k: '5', l: 'Pá'}, {k: '6', l: 'So'}, {k: '7', l: 'Ne'},
  ];
  const dnyAktivni = (r.dny_v_tydnu || '').split(',').filter(x => x);

  openModal(id ? `🔁 Upravit pravidlo: ${r.nazev}` : '🔁 Nové opakující pravidlo', `
    <div class="form-grid form-grid-tight">
      <div class="full">
        <label class="form-label">Název pravidla *</label>
        <input class="form-input" id="rec-nazev" value="${esc(r.nazev || '')}" placeholder="např. Hotel Beránek — pondělí + středa">
      </div>
      <div>
        <label class="form-label">Odběratel *</label>
        <select class="form-input" id="rec-odberatel">
          <option value="">— Vyberte odběratele —</option>
          ${odberatele.map(o => `<option value="${o.id}" ${parseInt(r.odberatel_id) === parseInt(o.id) ? 'selected' : ''}>${esc(o.nazev)}</option>`).join('')}
        </select>
      </div>
      <div>
        <label class="form-label">Místo dodání (volitelné)</label>
        <input class="form-input" id="rec-misto" type="number" value="${r.misto_dodani_id || ''}" placeholder="ID pobočky (nepovinné)">
      </div>
      <div>
        <label class="form-label">Frekvence *</label>
        <select class="form-input" id="rec-frekvence">
          <option value="denne"  ${r.frekvence === 'denne' ? 'selected' : ''}>📅 Denně</option>
          <option value="tydne"  ${r.frekvence === 'tydne' ? 'selected' : ''}>📅 Týdně (vyberte dny)</option>
          <option value="dvouty" ${r.frekvence === 'dvouty' ? 'selected' : ''}>📅 Každé 2 týdny</option>
          <option value="mesicne"${r.frekvence === 'mesicne' ? 'selected' : ''}>📅 Měsíčně (1. týden)</option>
        </select>
      </div>
      <div>
        <label class="form-label">Čas dodání (volitelné)</label>
        <input class="form-input" id="rec-cas" value="${esc(r.cas_dodani || '')}" placeholder="např. 07:00">
      </div>
      <div class="full">
        <label class="form-label">Dny v týdnu (pro týdně / dvouty / měsíčně)</label>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          ${dnyCheckboxes.map(d => `
            <label class="checkbox-row" style="background:var(--surface-2);padding:8px 14px;border-radius:8px;cursor:pointer">
              <input type="checkbox" class="rec-den-cb" value="${d.k}" ${dnyAktivni.includes(d.k) ? 'checked' : ''}>
              <span>${d.l}</span>
            </label>
          `).join('')}
        </div>
      </div>
      <div>
        <label class="form-label">Datum začátku</label>
        <input class="form-input" id="rec-dzacatku" type="date" value="${esc(r.datum_zacatku || new Date().toISOString().split('T')[0])}">
      </div>
      <div>
        <label class="form-label">Datum konce (volitelné)</label>
        <input class="form-input" id="rec-dkonce" type="date" value="${esc(r.datum_konce || '')}">
      </div>
      <div class="full">
        <label class="form-label">Poznámka</label>
        <input class="form-input" id="rec-poznamka" value="${esc(r.poznamka || '')}" placeholder="např. Pro hotelové konference">
      </div>
      <div class="full">
        <label class="checkbox-row" style="background:var(--surface-2);padding:10px 14px;border-radius:8px;cursor:pointer">
          <input type="checkbox" id="rec-aktivni" ${r.aktivni == 1 ? 'checked' : ''}>
          <span>Pravidlo je aktivní (cron ho bude spouštět)</span>
        </label>
      </div>
    </div>

    <!-- Položky -->
    <h3 style="margin:18px 0 8px;font-size:15px">📦 Položky objednávky</h3>
    <div id="rec-polozky" style="max-height:280px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;padding:8px">
      ${(r.polozky || []).map((p, i) => recurringPolozkaRow(p, i, vyrobky)).join('')}
    </div>
    <button type="button" class="btn-secondary" onclick="recurringAddPolozka()" style="margin-top:8px;font-size:13px">+ Přidat položku</button>

    <div class="form-actions">
      ${id ? `<div class="form-actions-icons-row"><button class="btn-danger-corner" onclick="recurringSmazat(${id}, '${esc(r.nazev).replace(/'/g, '')}')" title="Smazat">🗑️</button></div>` : ''}
      <div style="flex:1"></div>
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="recurringSave(${id || 'null'})" style="font-weight:700;padding:12px 24px">💾 Uložit pravidlo</button>
    </div>
  `, 'wide');

  state._recurringVyrobky = vyrobky;
};

function recurringPolozkaRow(p, idx, vyrobky) {
  return `
    <div class="rec-polozka-row" data-idx="${idx}" style="display:grid;grid-template-columns:2fr 100px 80px 100px auto;gap:8px;margin-bottom:6px;align-items:center">
      ${vyrComboRow('rec-pol-vyrobek', vyrobky, p.vyrobek_id, 'data-fld="vyrobek_id"')}
      <input class="form-input rec-pol-mn" type="number" step="0.01" min="0" data-fld="mnozstvi" value="${p.mnozstvi || ''}" placeholder="ks">
      <input class="form-input" type="text" data-fld="jednotka" value="${esc(p.jednotka || 'ks')}" placeholder="jed.">
      <input class="form-input" type="number" step="0.01" min="0" data-fld="cena_bez_dph" value="${p.cena_bez_dph || ''}" placeholder="Cena">
      <button type="button" class="btn-danger" style="padding:6px 10px;font-size:12px" onclick="this.closest('.rec-polozka-row').remove()">×</button>
    </div>
  `;
}

window.recurringAddPolozka = function() {
  const host = document.getElementById('rec-polozky');
  const idx = host.children.length;
  const v = state._recurringVyrobky || [];
  host.insertAdjacentHTML('beforeend', recurringPolozkaRow({}, idx, v));
};

window.recurringSave = async function(id) {
  const polozky = [];
  document.querySelectorAll('.rec-polozka-row').forEach(row => {
    const idEl = row.querySelector('[data-fld="vyrobek_id"]');
    const vid = parseInt(idEl.value);
    const mn  = parseFloat(row.querySelector('[data-fld="mnozstvi"]').value) || 0;
    const jed = row.querySelector('[data-fld="jednotka"]').value || 'ks';
    const cena = parseFloat(row.querySelector('[data-fld="cena_bez_dph"]').value) || 0;
    if (vid && mn > 0) {
      // 🆕 v3.0.279 — DPH + název z hidden inputu comboboxu (data-* nastaví vyrComboRow/vyrComboPick)
      const dph = parseFloat(idEl.dataset.dph) || 12;
      const nazev = idEl.dataset.nazev || '';
      polozky.push({ vyrobek_id: vid, vyrobek_nazev: nazev, mnozstvi: mn, jednotka: jed, cena_bez_dph: cena, sazba_dph: dph });
    }
  });
  if (polozky.length === 0) return alert('Přidejte alespoň jednu položku');

  const dnyCheckboxes = [...document.querySelectorAll('.rec-den-cb:checked')].map(c => c.value).join(',');

  const data = {
    nazev: document.getElementById('rec-nazev').value.trim(),
    odberatel_id: parseInt(document.getElementById('rec-odberatel').value),
    misto_dodani_id: parseInt(document.getElementById('rec-misto').value) || null,
    frekvence: document.getElementById('rec-frekvence').value,
    dny_v_tydnu: dnyCheckboxes,
    cas_dodani: document.getElementById('rec-cas').value || null,
    datum_zacatku: document.getElementById('rec-dzacatku').value,
    datum_konce: document.getElementById('rec-dkonce').value || null,
    poznamka: document.getElementById('rec-poznamka').value || null,
    aktivni: document.getElementById('rec-aktivni').checked ? 1 : 0,
    polozky,
  };
  if (!data.nazev) return alert('Vyplňte název');
  if (!data.odberatel_id) return alert('Vyberte odběratele');
  try {
    if (id) {
      data.id = id;
      await api('admin_recurring.php', { method: 'PUT', body: JSON.stringify(data) });
    } else {
      await api('admin_recurring.php', { method: 'POST', body: JSON.stringify(data) });
    }
    closeModal();
    navigate('recurring');
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.recurringSmazat = async function(id, nazev) {
  if (!await confirmDelete2x(`pravidlo „${nazev}"`)) return;
  try {
    await api(`admin_recurring.php?id=${id}`, { method: 'DELETE' });
    closeModal();
    navigate('recurring');
  } catch (e) { alert('Chyba: ' + e.message); }
};

