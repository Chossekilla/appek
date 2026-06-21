// =============================================================
// POBOČKY
// =============================================================
window.editPobocka = async function(odberatel_id, pobocka_id = null, returnTo = null) {
  const navratFn = { novaObjednavka: 'vykreslitNovouObjednavku', novaFaktura: 'vykreslitRucniFakturu', novyDl: 'vykreslitRucniDl' }[returnTo] || null;
  const m = pobocka_id
    ? (await api(`admin_pobocky.php?odberatel_id=${odberatel_id}`)).find((x) => x.id == pobocka_id)
    : { aktivni: 1, vychozi: 0 };

  openModal(pobocka_id ? `Upravit pobočku` : 'Nová pobočka', `
    <div class="form-grid form-grid-tight">
      <div class="full">
        <label class="form-label">Název pobočky *</label>
        <input class="form-input" id="po-nazev" value="${esc(m.nazev || '')}" required placeholder="např. Centrála, Pobočka Anděl...">
      </div>
      <div class="full">
        <label class="form-label">Ulice</label>
        <input class="form-input" id="po-ul" value="${esc(m.ulice || '')}">
      </div>
      <div>
        <label class="form-label">Město</label>
        <input class="form-input" id="po-me" value="${esc(m.mesto || '')}">
      </div>
      <div>
        <label class="form-label">PSČ</label>
        <input class="form-input" id="po-psc" value="${esc(m.psc || '')}">
      </div>
      <div>
        <label class="form-label">Kontaktní osoba</label>
        <input class="form-input" id="po-ko" value="${esc(m.kontaktni_osoba || '')}">
      </div>
      <div>
        <label class="form-label">Telefon</label>
        <input class="form-input" id="po-tel" value="${esc(m.telefon || '')}">
      </div>
      <div>
        <label class="form-label">E-mail</label>
        <input class="form-input" id="po-em" type="email" value="${esc(m.email || '')}">
      </div>
      <div>
        <label class="form-label">Čas dodání</label>
        <input class="form-input" id="po-cas" value="${esc(m.cas_dodani || '')}" placeholder="např. 6:00 - 7:30">
      </div>
      <div class="full">
        <label class="form-label">Pokyny pro řidiče</label>
        <textarea class="form-textarea" id="po-pok" rows="2">${esc(m.pokyny_pro_ridice || '')}</textarea>
      </div>
      <div>
        <div class="checkbox-row">
          <input type="checkbox" id="po-vy" ${m.vychozi == 1 ? 'checked' : ''}>
          <label for="po-vy">Výchozí pobočka</label>
        </div>
      </div>
      <div>
        <div class="checkbox-row">
          <input type="checkbox" id="po-akt" ${m.aktivni != 0 ? 'checked' : ''}>
          <label for="po-akt">Aktivní</label>
        </div>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-back" onclick="${navratFn ? navratFn + '()' : `editOdberatel(${odberatel_id})`}">${navratFn ? '← Zpět' : '← Zpět na odběratele'}</button>
      <div style="flex:1"></div>
      <button class="btn-primary" onclick="ulozitPobocku(${odberatel_id}, ${pobocka_id || 'null'}, ${returnTo ? `'${returnTo}'` : 'null'})">Uložit pobočku</button>
    </div>
  `);
};

window.ulozitPobocku = async function(odberatel_id, pobocka_id, returnTo = null) {
  const data = {
    id: pobocka_id || undefined,
    odberatel_id,
    nazev: document.getElementById('po-nazev').value.trim(),
    ulice: document.getElementById('po-ul').value || null,
    mesto: document.getElementById('po-me').value || null,
    psc: document.getElementById('po-psc').value || null,
    kontaktni_osoba: document.getElementById('po-ko').value || null,
    telefon: document.getElementById('po-tel').value || null,
    email: document.getElementById('po-em').value || null,
    cas_dodani: document.getElementById('po-cas').value || null,
    pokyny_pro_ridice: document.getElementById('po-pok').value || null,
    vychozi: document.getElementById('po-vy').checked ? 1 : 0,
    aktivni: document.getElementById('po-akt').checked ? 1 : 0,
  };
  
  if (!data.nazev) return alert('Vyplňte název pobočky');
  
  try {
    await api('admin_pobocky.php', {
      method: pobocka_id ? 'PUT' : 'POST',
      body: JSON.stringify(data),
    });
    const navrat = {
      novaObjednavka: { st: () => noState,          render: vykreslitNovouObjednavku },
      novaFaktura:    { st: () => rucniFakturaState, render: vykreslitRucniFakturu },
      novyDl:         { st: () => rdlState,          render: vykreslitRucniDl },
    }[returnTo];
    if (navrat) {
      const st = navrat.st();
      st.pobocky = await api(`admin_pobocky.php?odberatel_id=${odberatel_id}`);
      if (st.pobocky.length === 1) st.misto_dodani_id = st.pobocky[0].id;
      navrat.render();
    } else {
      editOdberatel(odberatel_id);
    }
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.smazatPobocku = async function(pobocka_id, odberatel_id) {
  if (!await confirmDelete2x({ co: 'tuto pobočku', detail: 'Pokud má objednávky, bude jen deaktivována (historické doklady zůstanou).' })) return;
  await api(`admin_pobocky.php?id=${pobocka_id}`, { method: 'DELETE' });
  editOdberatel(odberatel_id);
};

