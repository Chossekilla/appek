// =============================================================
// 🔍 ODBERATEL PICKER — sdílený searchable dropdown
// =============================================================
function _vpOdbList(prefix) {
  if (prefix === 'rf')  return window.rucniFakturaState?.vsechny_odberatele || [];
  if (prefix === 'rdl') return window.rdlState?.vsechny_odberatele || [];
  if (prefix === 'no')  return window.noState?.vsechny_odberatele || [];
  return [];
}
function _vpOdbPickFn(prefix) {
  if (prefix === 'rf')  return window.rfVybratOdberatele;
  if (prefix === 'rdl') return window.rdlVybratOdberatele;
  if (prefix === 'no')  return window.noVybratOdberatele;
  return null;
}
window.vpOdbFilter = function(prefix, query) {
  const dd = document.getElementById(`${prefix}-odb-dropdown`);
  if (!dd) return;
  const q = (query || '').toLowerCase().trim();
  const list = _vpOdbList(prefix);
  let items = list;
  if (q) {
    items = list.filter(o =>
      (o.nazev || '').toLowerCase().includes(q) ||
      (o.ico || '').toString().includes(q)
    );
  }
  const top = items.slice(0, 25);
  if (!window._vpOdbState) window._vpOdbState = {};
  window._vpOdbState[prefix] = { items: top, highlight: 0 };

  if (top.length === 0) {
    dd.innerHTML = `<div class="vp-picker-empty">Žádný odběratel neodpovídá „${esc(q)}"</div>`;
  } else {
    dd.innerHTML = top.map((o, i) => `
      <div class="vp-picker-item ${i === 0 ? 'is-active' : ''}" data-idx="${i}"
           onmousedown="vpOdbPick('${prefix}', ${o.id})" onmouseenter="vpOdbHighlight('${prefix}', ${i})"
           style="display:flex;align-items:center;gap:12px;padding:12px 16px">
        <div style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
          <span style="font-size:20px;font-weight:700;color:var(--text)">${esc(o.nazev)}</span>
          ${o.ico ? `<span style="font-size:10px;font-weight:500;color:var(--text-3);margin-left:8px">IČO ${esc(o.ico)}</span>` : ''}
        </div>
        ${o.mesto ? `<span style="font-size:10px;color:var(--text-3);white-space:nowrap;font-weight:500">📍 ${esc(o.mesto)}</span>` : ''}
      </div>
    `).join('') + (items.length > 25 ? `<div class="vp-picker-more">Zobrazeno prvních 25 z ${items.length} — upřesni hledání…</div>` : '');
  }
  dd.style.display = 'block';
};
window.vpOdbHighlight = function(prefix, idx) {
  if (!window._vpOdbState?.[prefix]) return;
  window._vpOdbState[prefix].highlight = idx;
  const dd = document.getElementById(`${prefix}-odb-dropdown`);
  if (!dd) return;
  dd.querySelectorAll('.vp-picker-item').forEach((el, i) => {
    el.classList.toggle('is-active', i === idx);
  });
};
window.vpOdbPick = function(prefix, id) {
  const o = _vpOdbList(prefix).find(x => x.id == id);
  if (!o) return;
  document.getElementById(`${prefix}-odberatel-search`).value = o.nazev + (o.ico ? ' · IČO ' + o.ico : '');
  document.getElementById(`${prefix}-odberatel`).value = o.id;
  document.getElementById(`${prefix}-odb-dropdown`).style.display = 'none';
  // Spustit původní handler (vybere odběratele a načte cenik/pobočky)
  const fn = _vpOdbPickFn(prefix);
  if (fn) fn(o.id);
};
window.vpOdbKey = function(ev, prefix) {
  const st = window._vpOdbState?.[prefix];
  if (!st) return;
  if (ev.key === 'ArrowDown') {
    ev.preventDefault();
    st.highlight = Math.min(st.items.length - 1, st.highlight + 1);
    vpOdbHighlight(prefix, st.highlight);
  } else if (ev.key === 'ArrowUp') {
    ev.preventDefault();
    st.highlight = Math.max(0, st.highlight - 1);
    vpOdbHighlight(prefix, st.highlight);
  } else if (ev.key === 'Enter') {
    ev.preventDefault();
    const o = st.items[st.highlight];
    if (o) vpOdbPick(prefix, o.id);
  } else if (ev.key === 'Escape') {
    document.getElementById(`${prefix}-odb-dropdown`).style.display = 'none';
  }
};

window.rfPridatVolny = function() {
  rucniFakturaState.polozky.push({
    vyrobek_id: null,
    vyrobek_cislo: null,
    vyrobek_nazev: '',
    jednotka: 'ks',
    mnozstvi: 1,
    cena_bez_dph: 0,
    sazba_dph: 21,
    je_z_katalogu: false,
  });
  vykreslitRucniFakturu();
};

window.rfUpdate = function(idx, field, value) {
  if (!rucniFakturaState.polozky[idx]) return;
  rucniFakturaState.polozky[idx][field] = value;
  // Re-render jen souhrnu - aby uživatel neztratil focus v inputu
  // (Plný re-render by způsobil ztrátu focusu při psaní)
  // Necháme jen aktualizovat souhrn níže přepočtem
  const polozky = rucniFakturaState.polozky;
  let bez = 0, dph = 0;
  polozky.forEach(p => {
    const b = p.cena_bez_dph * p.mnozstvi;
    bez += b;
    dph += b * (p.sazba_dph / 100);
  });
  // Najdi souhrn a aktualizuj jen tam
  const summaryBox = document.querySelector('.modal-body div[style*="FAEEDA"]');
  if (summaryBox) {
    const rows = summaryBox.querySelectorAll('div');
    if (rows.length >= 3) {
      rows[0].lastElementChild.textContent = fmt(bez);
      rows[1].lastElementChild.textContent = fmt(dph);
      rows[2].lastElementChild.textContent = fmt(bez + dph);
    }
  }
};

window.rfRemove = function(idx) {
  rucniFakturaState.polozky.splice(idx, 1);
  vykreslitRucniFakturu();
};

/**
 * Načte položky ze staré objednávky do faktury.
 * Zeptá se modálem: Nahradit / Přidat / Zrušit.
 * Ceny se vezmou z AKTUÁLNÍHO ceníku odběratele (s aktuální slevovou skupinou),
 * ne ze starých cen z objednávky - aby faktura odpovídala aktuálním podmínkám.
 */
window.rfNacistZObjednavky = function(obj_id) {
  const s = rucniFakturaState;
  const obj = s.posledni_objednavky.find(o => o.id == obj_id);
  if (!obj) return;

  if (!obj.polozky || obj.polozky.length === 0) {
    alert('Tato objednávka nemá žádné položky.');
    return;
  }

  // Pokud je faktura prázdná, rovnou nahraď bez ptání
  if (s.polozky.length === 0) {
    rfAplikovatPolozkyZObjednavky(obj, 'replace');
    return;
  }

  // Jinak se zeptej co dělat
  const html = `
    <p style="font-size:15px;line-height:1.5;margin-bottom:16px">
      Faktura už obsahuje <strong>${s.polozky.length}</strong>
      ${s.polozky.length === 1 ? 'položku' : (s.polozky.length < 5 ? 'položky' : 'položek')}.
      Co s nimi udělat?
    </p>

    <p style="font-size:14px;color:var(--text-2);margin-bottom:20px">
      Načítám <strong>${obj.polozky.length}</strong>
      ${obj.polozky.length === 1 ? 'položku' : (obj.polozky.length < 5 ? 'položky' : 'položek')}
      z objednávky <strong>#${esc(obj.cislo)}</strong> ze dne ${fmtDate(obj.datum_dodani)}.
    </p>

    <div class="form-actions" style="flex-wrap:wrap;gap:8px">
      <button class="btn-secondary" onclick="closeModalConfirm()">Zrušit</button>
      <div style="flex:1"></div>
      <button class="btn-secondary" onclick="rfAplikovatPolozkyZObjednavky(${JSON.stringify(obj).replace(/"/g, '&quot;')}, 'append'); closeModalConfirm();">
        ➕ Přidat ke stávajícím
      </button>
      <button class="btn-primary" onclick="rfAplikovatPolozkyZObjednavky(${JSON.stringify(obj).replace(/"/g, '&quot;')}, 'replace'); closeModalConfirm();">
        🔁 Nahradit vše
      </button>
    </div>
  `;

  // Použijeme druhý modal (na vrch)
  showConfirmModal('Načíst položky?', html);
};

window.closeModalConfirm = function() {
  const m = document.getElementById('confirm-modal');
  if (m) m.style.display = 'none';
};

// Univerzální helper pro confirm modal (sekundární na vrchu primárního)
window.showConfirmModal = function(title, html) {
  let confirm_modal = document.getElementById('confirm-modal');
  if (!confirm_modal) {
    confirm_modal = document.createElement('div');
    confirm_modal.id = 'confirm-modal';
    confirm_modal.className = 'modal-overlay';
    confirm_modal.style.zIndex = '2000';  // 🆕 v2.9.66 — nad primární modal (z-index 1000), jinak se kreslil ZA něj
    confirm_modal.innerHTML = `
      <div class="modal-card" style="max-width:520px">
        <div class="modal-head">
          <h2 id="confirm-modal-title"></h2>
          <button class="modal-close" onclick="closeModalConfirm()"></button>
        </div>
        <div class="modal-body" id="confirm-modal-body"></div>
      </div>
    `;
    document.body.appendChild(confirm_modal);
  }
  document.getElementById('confirm-modal-title').textContent = title;
  document.getElementById('confirm-modal-body').innerHTML = html;
  confirm_modal.style.display = 'flex';
};

/**
 * Aplikuje položky ze staré objednávky.
 * mode = 'replace' (nahradit vše) | 'append' (přidat ke stávajícím)
 *
 * Pro každou položku: pokud výrobek pořád existuje v ceníku, vezmi
 * AKTUÁLNÍ cenu (po slevě). Jinak vezmi cenu ze staré objednávky.
 */
window.rfAplikovatPolozkyZObjednavky = function(obj, mode) {
  // Pokud je obj string (z JSON.stringify), zparsuj
  if (typeof obj === 'string') {
    try { obj = JSON.parse(obj.replace(/&quot;/g, '"')); }
    catch (e) { return alert('Chyba při čtení objednávky'); }
  }

  const cenikIndex = {};
  rucniFakturaState.cenik.forEach(v => { cenikIndex[v.id] = v; });

  const noveRadky = obj.polozky.map(p => {
    const aktualni = cenikIndex[p.vyrobek_id];
    return {
      vyrobek_id: p.vyrobek_id,
      vyrobek_cislo: p.vyrobek_cislo,
      vyrobek_nazev: p.vyrobek_nazev,
      jednotka: p.jednotka || 'ks',
      mnozstvi: parseFloat(p.mnozstvi),
      // AKTUÁLNÍ cena z ceníku (po slevě), fallback na cenu ze staré objednávky
      cena_bez_dph: aktualni ? parseFloat(aktualni.cena_bez_dph) : parseFloat(p.cena_bez_dph),
      sazba_dph: aktualni ? parseFloat(aktualni.dph || 12) : parseFloat(p.sazba_dph),
      je_z_katalogu: !!aktualni,
    };
  });

  if (mode === 'replace') {
    rucniFakturaState.polozky = noveRadky;
  } else {
    rucniFakturaState.polozky = rucniFakturaState.polozky.concat(noveRadky);
  }
  vykreslitRucniFakturu();
};

window.rfVystavit = async function() {
  const s = rucniFakturaState;
  if (!s.odberatel_id) return alert('Vyberte odběratele');
  if (s.polozky.length === 0) return alert('Přidejte alespoň jednu položku');

  // Validace položek
  for (let i = 0; i < s.polozky.length; i++) {
    const p = s.polozky[i];
    if (!p.vyrobek_nazev?.trim()) return alert(t('row_missing_name_short', { n: i + 1 }));
    if (p.mnozstvi <= 0) return alert(t('row_invalid_qty', { n: i + 1 }));
    if (p.cena_bez_dph < 0) return alert(t('row_negative_price', { n: i + 1 }));
  }

  const data = {
    odberatel_id: s.odberatel_id,
    misto_dodani_id: s.misto_dodani_id,
    datum_vystaveni: document.getElementById('rf-datum-vyst').value,
    datum_splatnosti: document.getElementById('rf-datum-spl').value || null,
    poznamka: document.getElementById('rf-poznamka').value || null,
    polozky: s.polozky.map((p, i) => ({
      vyrobek_id: p.vyrobek_id,
      vyrobek_cislo: p.vyrobek_cislo,
      vyrobek_nazev: p.vyrobek_nazev,
      jednotka: p.jednotka,
      mnozstvi: p.mnozstvi,
      cena_bez_dph: p.cena_bez_dph,
      sazba_dph: p.sazba_dph,
      poradi: i,
    })),
  };

  if (s.editId) {
    data.id = s.editId;
    data.action = 'upravit';
  }

  if (!data.datum_vystaveni) return alert('Vyplňte datum vystavení');

  try {
    const res = await api('admin_faktury.php', {
      method: 'POST',
      body: JSON.stringify(data),
    });
    closeModal();
    alert(t('invoice_issued_simple', { cislo: res.cislo }));
    window.open(`../api/faktura.php?id=${res.id}`, '_blank');
    navigate('faktury');
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

