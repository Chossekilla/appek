// =============================================================
// EDITOR: výběr výrobku pro náhled (reálná data místo placeholderů)
// =============================================================
window.ed_otevritVyberVyrobku = async function() {
  // Načti seznam výrobků (use cache)
  if (!Array.isArray(stState.vyrobky) || stState.vyrobky.length === 0) {
    try {
      const r = await api('admin_vyrobky.php');
      stState.vyrobky = (r && Array.isArray(r.vyrobky)) ? r.vyrobky : (Array.isArray(r) ? r : []);
    } catch (e) { stState.vyrobky = []; }
  }
  const list = (stState.vyrobky || []).filter(v => parseInt(v.aktivni) === 1);

  openModal('📥 Vyberte výrobek pro náhled', `
    <p style="font-size:13px;color:var(--text-2);margin-bottom:12px">
      Vybraný výrobek se vykreslí v editoru namísto zástupných textů — uvidíš jak bude šablona vypadat s reálnými daty (název, cena, EAN, alergeny, hmotnost…).
    </p>
    <div style="display:flex;gap:8px;margin-bottom:10px;align-items:center">
      <input class="form-input" id="ed-vyr-q" placeholder="Hledat (název, číslo)..." style="flex:1" oninput="ed_filtrVyrobku(this.value)">
      <span style="font-size:12px;color:var(--text-3)">${list.length} výrobků</span>
    </div>
    <div style="max-height:60vh;overflow:auto;border:1px solid var(--border);border-radius:6px">
      ${list.length === 0
        ? '<div class="empty-state" style="padding:30px">Žádné výrobky. Nejdřív založ alespoň jeden v sekci „Výrobky".</div>'
        : `<table class="table" id="ed-vyr-table" style="margin:0;font-size:13px">
            <thead><tr><th>Kód</th><th>Název</th><th>EAN</th><th>Cena</th><th>Hmotnost</th><th></th></tr></thead>
            <tbody>${list.map(v => `
              <tr data-search="${esc(((v.nazev || '') + ' ' + (v.cislo || '')).toLowerCase())}">
                <td style="font-size:11px;color:var(--text-3)">${esc(v.cislo || '')}</td>
                <td><strong>${esc(v.nazev)}</strong></td>
                <td style="font-family:monospace;font-size:11px">${esc(v.ean || '—')}</td>
                <td class="num">${fmt(v.cena_bez_dph || 0)}</td>
                <td style="font-size:11px;color:var(--text-3)">${v.obsah ? esc(v.obsah + ' ' + (v.obsah_jednotka || '')) : (v.hmotnost_g ? esc(v.hmotnost_g + ' g') : '—')}</td>
                <td><button class="btn-primary" onclick="ed_nastavitPreviewVyrobek(${v.id})" style="font-size:12px;padding:4px 10px">📥 Použít</button></td>
              </tr>
            `).join('')}</tbody>
          </table>`}
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
    </div>
  `, 'wide');
};

window.ed_filtrVyrobku = function(q) {
  q = (q || '').toLowerCase().trim();
  document.querySelectorAll('#ed-vyr-table tbody tr').forEach(tr => {
    const m = tr.dataset.search || '';
    tr.style.display = m.includes(q) ? '' : 'none';
  });
};

window.ed_nastavitPreviewVyrobek = async function(id) {
  // Načti detail výrobku — potřebujeme i složení/alergeny
  try {
    const v = await api(`admin_vyrobky.php?id=${id}`);
    stEditor.previewVyrobek = v;

    // Pokud canvas ještě nemá žádné prvky, automaticky vygeneruj základní layout
    // (název, cena, hmotnost, kód, EAN — uživatel pak může pohejbat / přidat další)
    let wasEmpty = false;
    if (stEditor.prvky.length === 0) {
      wasEmpty = true;
      stEditor.prvky = ed_generujZakladniLayout();
    } else {
      // Vyčisti zástupné texty u datových typů — aby se použila reálná data z výrobku
      const datoveTypy = ['nazev','cena','mena','jed','cenakg','hmotnost','dph','slozeni','alergeny','kod','ean','qr','nutri','nutri_kj','nutri_kcal','nutri_tuky','nutri_sach','nutri_bilk','nutri_sul'];
      stEditor.prvky.forEach(p => {
        if (datoveTypy.includes(p.typ)) {
          const def = PALETA_PRVKY.find(x => x.typ === p.typ);
          if (def && p.text === def.d) p.text = ''; // identický s placeholderem → vymaž
        }
      });
    }

    closeModal();
    vykreslitEditor();

    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:12px 18px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.15);font-size:13px;font-weight:600;z-index:9999;max-width:340px;line-height:1.5';
    t.innerHTML = wasEmpty
      ? `📥 Načteno: <strong>${esc(v.nazev)}</strong><br><small style="font-size:11px;opacity:0.85">Šablona byla prázdná — vygenerován základní layout (${stEditor.prvky.length} prvků). Pohejbej je podle potřeby.</small>`
      : `📥 Náhled: <strong>${esc(v.nazev)}</strong>`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), wasEmpty ? 5000 : 2200);
  } catch (e) { alert('Chyba: ' + e.message); }
};

// Vygeneruje výchozí layout cenovky (název + cena + meta + EAN)
function ed_generujZakladniLayout() {
  const fmt = STITKY_FORMATY.find(f => f.id === stEditor.formatId) || STITKY_FORMATY[0];
  const isSmall = (fmt.h <= 50);
  const prvky = [];
  let nid = 0;
  const id = () => 'p' + Date.now() + '_' + (++nid);

  // Název
  prvky.push({
    id: id(), typ: 'nazev',
    x_pct: 4, y_pct: 4, w_pct: 92, h_pct: 12,
    fontSize: isSmall ? 11 : 14, fontWeight: 800, align: 'left',
  });
  // Cena (velká, vystředěná)
  prvky.push({
    id: id(), typ: 'cena',
    x_pct: 4, y_pct: 22, w_pct: 70, h_pct: 16,
    fontSize: isSmall ? 22 : 32, fontWeight: 900, color: '#2C2C2A', align: 'center',
  });
  // Měna Kč vedle ceny
  prvky.push({
    id: id(), typ: 'mena',
    x_pct: 75, y_pct: 24, w_pct: 21, h_pct: 12,
    fontSize: isSmall ? 11 : 14, fontWeight: 700, color: '#BA7517', align: 'left',
  });
  // Hmotnost
  prvky.push({
    id: id(), typ: 'hmotnost',
    x_pct: 4, y_pct: 42, w_pct: 30, h_pct: 8,
    fontSize: 10, fontWeight: 600, align: 'left',
  });
  // Cena za kg
  prvky.push({
    id: id(), typ: 'cenakg',
    x_pct: 36, y_pct: 42, w_pct: 36, h_pct: 8,
    fontSize: 8, fontWeight: 700, color: '#854F0B', bg: '#FFF8E5', padding: 1,
  });
  // Kód
  prvky.push({
    id: id(), typ: 'kod',
    x_pct: 74, y_pct: 42, w_pct: 22, h_pct: 8,
    fontSize: 7, color: '#999', align: 'right',
  });
  // Alergeny
  prvky.push({
    id: id(), typ: 'alergeny',
    x_pct: 4, y_pct: 54, w_pct: 92, h_pct: 8,
    fontSize: 7, color: '#92400e', fontWeight: 600,
  });
  // EAN dolů
  prvky.push({
    id: id(), typ: 'ean',
    x_pct: 4, y_pct: 78, w_pct: 60, h_pct: 18,
  });

  return prvky;
}

window.ed_zrusitVyberVyrobku = function() {
  stEditor.previewVyrobek = null;
  vykreslitEditor();
};

