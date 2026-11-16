// =============================================================
// ✉️ ODESLAT DOKLAD EMAILEM — FA / DL / OBJ s PDF přílohou
// =============================================================
window.otevritOdeslatEmailem = function(typ, id, cislo, defaultEmail = '') {
  const typLabel = typ === 'fa' ? 'fakturu' : (typ === 'dl' ? 'dodací list' : 'objednávku');
  const typLabelVelky = typ === 'fa' ? 'Faktura' : (typ === 'dl' ? 'Dodací list' : 'Objednávka');

  openModal(`✉️ Odeslat ${typLabel} emailem`, `
    <div style="background:var(--surface-2);border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:14px">
      <strong>${esc(typLabelVelky)} ${esc(cislo)}</strong>
      <div style="font-size:12px;color:var(--text-3);margin-top:2px">PDF dokladu bude přiloženo k emailu</div>
    </div>

    <div class="form-row">
      <label class="form-label" for="oem-emails">📧 Příjemci <span class="doc-meta-req">*</span></label>
      <input id="oem-emails" class="form-input" type="text" value="${esc(defaultEmail)}" placeholder="emails@firma.cz, sekretarka@firma.cz" style="font-size:15px;padding:10px 14px">
      <small style="color:var(--text-3);font-size:12px;margin-top:4px;display:block">Více adres oddělte čárkou.</small>
    </div>

    <div class="form-row" style="margin-top:12px">
      <label class="form-label" for="oem-predmet">📝 Předmět</label>
      <input id="oem-predmet" class="form-input" type="text" placeholder="${esc(typLabelVelky)} ${esc(cislo)}" style="font-size:14px">
    </div>

    <div class="form-row" style="margin-top:12px">
      <label class="form-label" for="oem-zprava">💬 Vlastní zpráva (volitelné)</label>
      <textarea id="oem-zprava" class="form-input" rows="4" placeholder="Dobrý den, posíláme přiloženou ${esc(typLabel)}..." style="font-size:14px;resize:vertical"></textarea>
    </div>

    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="odeslatDokladEmailem('${typ}', ${id})">✉️ Odeslat</button>
    </div>
  `);

  // Fokus na input
  setTimeout(() => {
    const inp = document.getElementById('oem-emails');
    if (inp) { inp.focus(); inp.select(); }
  }, 100);
};

window.odeslatDokladEmailem = async function(typ, id) {
  const rawEmails = (document.getElementById('oem-emails')?.value || '').trim();
  const predmet = (document.getElementById('oem-predmet')?.value || '').trim();
  const zprava = (document.getElementById('oem-zprava')?.value || '').trim();

  if (!rawEmails) {
    alert('Zadejte aspoň jeden e-mail.');
    return;
  }

  const emails = rawEmails.split(/[,;\s]+/).map(e => e.trim()).filter(Boolean);
  // Validace
  const invalid = emails.filter(e => !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e));
  if (invalid.length > 0) {
    alert('Neplatné e-maily:\n' + invalid.join('\n'));
    return;
  }

  // Loading state
  const btn = document.querySelector('.modal-card .btn-green');
  const origText = btn?.textContent;
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Odesílám…'; }

  try {
    const r = await api('admin_doklad_email.php', {
      method: 'POST',
      body: { typ, id, emails, predmet, zprava },
    });
    const msg = `✓ Odesláno: ${r.odeslano}\n${r.chyby > 0 ? '✗ Chyby: ' + r.chyby + '\n' : ''}${r.priloha || ''}`;
    alert(msg);
    closeModal();
  } catch (e) {
    alert('Chyba odeslání: ' + e.message);
    if (btn) { btn.disabled = false; btn.textContent = origText; }
  }
};

window.ulozitFakturu = async function(id) {
  const data = {
    id,
    datum_splatnosti: document.getElementById('ff-splatnost').value,
    castka_uhrazeno: parseFloat(document.getElementById('ff-uhrazeno').value) || 0,
    variabilni_symbol: document.getElementById('ff-vs').value,
    poznamka: document.getElementById('ff-pozn').value,
  };
  await api('admin_faktury.php', { method: 'PUT', body: JSON.stringify(data) });
  closeModal();
  navigate('faktury');
};


window.smazatFakturu = async function(id) {
  if (!await confirmDelete2x('tuto fakturu')) return;
  await api(`admin_faktury.php?id=${id}`, { method: 'DELETE' });
  closeModal();
  navigate('faktury');
};

window.smazatDodaciList = async function(id) {
  if (!await confirmDelete2x({ co: 'tento dodací list', detail: '⚠️ Položky DL se nenávratně smažou. Pokud je DL navázán na fakturu, je nutné nejprve smazat fakturu.' })) return;
  try {
    await api(`admin_dodaci_listy.php?id=${id}`, { method: 'DELETE' });
    closeModal();
    navigate('dodaci_listy');
  } catch (e) {
    alert('Chyba: ' + (e.message || e));
  }
};

// +/− tlačítka u množství v FA detailu
window.faQtyAdj = function(polozka_id, delta) {
  const inp = document.querySelector(`#fa-polozky .obj-polozka-row[data-pol-id="${polozka_id}"] .qty-input`);
  if (!inp) return;
  const newVal = Math.max(0, (parseInt(inp.value) || 0) + delta);
  inp.value = newVal;
  faQtyRecalc(polozka_id);
};

// Live přepočet ceny řádku u faktury
window.faQtyRecalc = function(polozka_id) {
  const row = document.querySelector(`#fa-polozky .obj-polozka-row[data-pol-id="${polozka_id}"]`);
  if (!row) return;
  const inp = row.querySelector('.qty-input');
  const cenaCell = document.getElementById(`fa-polozka-cena-${polozka_id}`);
  if (!inp || !cenaCell) return;
  const mn = parseFloat(inp.value) || 0;
  const cena = parseFloat(inp.dataset.cena) || 0;
  const dph = parseFloat(inp.dataset.dph) || 0;
  // 💱 v3.0.642 — v měně dokladu (Kč doklad = beze změny)
  const d = { doc_mena: inp.dataset.docMena || '', doc_k: inp.dataset.docK || '' };
  cenaCell.textContent = d.doc_mena ? fmtDoc(docRadekSDph(d, cena, mn, dph), d.doc_mena) : fmt(mn * cena * (1 + dph / 100));
};

window.ulozitPolozkyFaktury = async function(id) {
  const inputs = document.querySelectorAll('#fa-polozky input[data-pol-id]');
  const zmeny = [];
  inputs.forEach((inp) => {
    const orig = parseFloat(inp.dataset.orig);
    const aktual = parseFloat(inp.value) || 0;
    if (aktual !== orig) zmeny.push({ polozka_id: parseInt(inp.dataset.polId), mnozstvi: aktual });
  });
  if (zmeny.length === 0) return alert('Nebyly provedeny žádné změny');

  if (!(await confirmDialog({ msg: '⚠️ Měníte položky na již vystavené faktuře.\n\n' +
    'Faktura se přepíše a součty se přepočítají.\n' +
    'Pokud již byla faktura vytištěna nebo poslána odběrateli, zahoďte ji a vytiskněte nové PDF.\n\n' +
    'Opravdu pokračovat?', danger: true }))) return;

  try {
    await api('admin_faktury.php', {
      method: 'PUT',
      body: JSON.stringify({ id, polozky_zmeny: zmeny }),
    });
    alert('✓ Položky faktury upraveny. Vytiskněte nové PDF.');
    openFakturaDetail(id);
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

window.smazatPolozkuFaktury = async function(polozka_id, faktura_id) {
  if (!await confirmDelete2x({ co: 'tuto položku z faktury', detail: 'Faktura se po smazání položky přepočítá.' })) return;
  try {
    await api('admin_faktury.php', {
      method: 'PUT',
      body: JSON.stringify({
        id: faktura_id,
        polozky_zmeny: [{ polozka_id, mnozstvi: 0 }],
      }),
    });
    openFakturaDetail(faktura_id);
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

