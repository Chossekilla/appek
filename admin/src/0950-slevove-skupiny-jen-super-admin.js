// =============================================================
// SLEVOVÉ SKUPINY (jen super admin)
// =============================================================
async function renderCenoveSkupiny() {
  if (!isSuperAdmin()) {
    document.getElementById('content').innerHTML = `
      <div style="padding:40px;text-align:center;color:var(--text-3)">
        Tato sekce je pouze pro super admina.
      </div>`;
    return;
  }

  const c = document.getElementById('content');
  c.innerHTML = `<div class="page-head"><h1 class="page-title">Slevové skupiny</h1></div><p>Načítám…</p>`;

  try {
    const skupiny = await api('admin_cenove_skupiny.php');

    c.innerHTML = `
      <div class="page-head">
        <div>
          <h1 class="page-title">💸 Slevové skupiny</h1>
          <p class="page-sub">Definujte slevy pro skupiny zákazníků (procenta nebo pevné ceny)</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <button class="btn-back" onclick="navigate('nastaveni');setTimeout(()=>{state._nastaveniTab='udrzba';renderNastaveni();},100)" title="Zpět na Údržba tab"><span class="btn-back-arrow">←</span> <span class="btn-back-lbl">Údržba</span></button>
          <button class="btn-primary btn-green btn-big-action" onclick="otevritSkupinu(null)" style="font-size:18px !important;font-weight:800 !important;padding:18px 32px !important;min-height:64px !important;border-radius:12px !important;letter-spacing:0.3px !important">+ Nová skupina</button>
        </div>
      </div>

      <!-- Desktop: tabulka -->
      <div class="card-block desktop-only-block" style="padding:0">
        <table class="table">
          <thead>
            <tr>
              <th>Název</th>
              <th>Popis</th>
              <th class="num">Glob. sleva</th>
              <th class="num">Slev</th>
              <th class="num">Odběratelů</th>
              <th>Stav</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            ${skupiny.length === 0 ? `
              <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-3)">
                Zatím žádné ceníky. Klepněte na "+ Nová skupina".
              </td></tr>
            ` : skupiny.map((s) => `
              <tr style="cursor:pointer" onclick="otevritSlevySkupiny(${s.id}, '${esc(s.nazev).replace(/'/g, '')}')">
                <td><strong>${esc(s.nazev)}</strong></td>
                <td style="color:var(--text-3); font-size:13px">${esc(s.popis || '')}</td>
                <td class="num">${s.globalni_sleva_pct ? `<strong style="color:#854F0B">−${parseFloat(s.globalni_sleva_pct)} %</strong>` : '<span style="color:var(--text-3)">—</span>'}</td>
                <td class="num">${s.pocet_slev}</td>
                <td class="num">${s.pocet_odberatelu}</td>
                <td>${s.aktivni == 1
                    ? '<span class="status dorucena">Aktivní</span>'
                    : '<span class="status zrusena">Neaktivní</span>'}</td>
                <td onclick="event.stopPropagation();" style="text-align:right;white-space:nowrap">
                  <button class="btn-secondary" style="font-size:11px;padding:4px 10px;"
                          onclick="otevritSkupinu(${s.id})">Upravit</button>
                  <button class="btn-danger" style="font-size:11px;padding:4px 10px;"
                          onclick="smazatSkupinu(${s.id}, '${esc(s.nazev).replace(/'/g, '')}', ${s.pocet_odberatelu})">Smazat</button>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>

      <!-- Mobile: kompaktní karty -->
      <div class="mobile-only-block">
        ${skupiny.length === 0 ? `
          <div class="card-block"><div class="empty-state">
            Zatím žádné slevové skupiny. Klepněte na "+ Nová skupina".
          </div></div>
        ` : skupiny.map((s) => `
          <div class="skupina-card" onclick="otevritSlevySkupiny(${s.id}, '${esc(s.nazev).replace(/'/g, '')}')">
            <div class="skupina-card-head">
              <div class="skupina-card-title">
                <div class="skupina-card-icon">🧾</div>
                <div class="skupina-card-info">
                  <div class="skupina-card-nazev">${esc(s.nazev)}</div>
                  ${s.popis ? `<div class="skupina-card-popis">${esc(s.popis)}</div>` : ''}
                </div>
              </div>
              ${s.aktivni == 1
                ? '<span class="status dorucena">Aktivní</span>'
                : '<span class="status zrusena">Neaktivní</span>'}
            </div>

            <div class="skupina-card-stats">
              <div class="skupina-stat">
                <div class="skupina-stat-label">Slev</div>
                <div class="skupina-stat-value">${s.pocet_slev}</div>
              </div>
              <div class="skupina-stat">
                <div class="skupina-stat-label">Odběratelů</div>
                <div class="skupina-stat-value">${s.pocet_odberatelu}</div>
              </div>
            </div>

            <div class="skupina-card-actions" onclick="event.stopPropagation();">
              <button class="btn-secondary" onclick="otevritSkupinu(${s.id})">Upravit</button>
              <button class="btn-danger" onclick="smazatSkupinu(${s.id}, '${esc(s.nazev).replace(/'/g, '')}', ${s.pocet_odberatelu})">Smazat</button>
            </div>
          </div>
        `).join('')}
      </div>
    `;
  } catch (e) {
    c.innerHTML = `<div style="color:var(--danger-text);padding:20px">Chyba: ${esc(e.message)}</div>`;
  }
}

window.otevritSkupinu = async function(id) {
  // Reset flagu pro návrat — uživatel přišel přes „Upravit", vrátit se má sem
  state._sk_back_to_slevy = null;
  let s = { id: null, nazev: '', popis: '', aktivni: 1, odberatele: [], odberatele_volni: [] };
  if (id) {
    try { s = await api(`admin_cenove_skupiny.php?id=${id}`); }
    catch (e) { alert('Chyba: ' + e.message); return; }
  }

  const odbList = Array.isArray(s.odberatele) ? s.odberatele : [];
  const volni = Array.isArray(s.odberatele_volni) ? s.odberatele_volni : [];

  openModal(id ? `🧾 Upravit ceník: ${s.nazev}` : '🧾 Nový ceník', `
    <!-- Hlavička skupiny -->
    <div class="card-block" style="padding:16px;margin-bottom:14px;background:linear-gradient(135deg,#FFF8E7,#FEF3C7);border:1px solid #E8C988;border-radius:10px">
      <div class="form-grid" style="grid-template-columns:2fr 1fr;gap:14px">
        <div>
          <label class="form-label" style="color:#854F0B;font-weight:600">Název ceníku <span style="color:#dc2626">*</span></label>
          <input class="form-input" id="sk-nazev" value="${esc(s.nazev || '')}" placeholder="např. Hotely VIP, Restaurace, Kavárny" style="font-size:15px;font-weight:600">
        </div>
        <div>
          <label class="form-label" style="color:#854F0B;font-weight:600">Stav</label>
          <label class="form-input" style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 14px;background:white">
            <input type="checkbox" id="sk-aktivni" ${s.aktivni == 1 || !id ? 'checked' : ''} style="width:18px;height:18px;cursor:pointer">
            <span style="font-size:14px;font-weight:500">Aktivní</span>
          </label>
        </div>
        <div class="full">
          <label class="form-label" style="color:#854F0B;font-weight:600">Popis <span style="color:var(--text-3);font-weight:400;font-size:11px">(volitelně, uvidí ho jen administrátor)</span></label>
          <input class="form-input" id="sk-popis" value="${esc(s.popis || '')}" placeholder="např. Speciální ceny pro hotelové řetězce — sleva 15 % na pečivo">
        </div>
      </div>

      <!-- 🧾 Globální parametry ceníku — sleva, minimum, splatnost -->
      <div style="margin-top:14px;padding-top:14px;border-top:1px dashed #E8C988">
        <div style="font-size:13px;font-weight:700;color:#854F0B;margin-bottom:8px">⚙️ Globální parametry ceníku</div>
        <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr;gap:14px">
          <div>
            <label class="form-label" style="color:#854F0B;font-weight:600">Globální sleva %</label>
            <div style="display:flex;align-items:center;gap:6px">
              <input class="form-input" id="sk-global-sleva" type="number" step="0.01" min="0" max="100" value="${s.globalni_sleva_pct ?? ''}" placeholder="např. 3" style="text-align:right;font-weight:600">
              <span style="font-size:13px;color:#854F0B;font-weight:700">%</span>
            </div>
            <small style="font-size:11px;color:var(--text-3);display:block;margin-top:3px">Pro celý sortiment, kde není jiné pravidlo</small>
          </div>
          <div>
            <label class="form-label" style="color:#854F0B;font-weight:600">Minimum objednávky</label>
            <div style="display:flex;align-items:center;gap:6px">
              <input class="form-input" id="sk-min-obj" type="number" step="0.01" min="0" value="${s.minimum_obj_kc ?? ''}" placeholder="např. 500" style="text-align:right;font-weight:600">
              <span style="font-size:13px;color:#854F0B;font-weight:700">Kč</span>
            </div>
            <small style="font-size:11px;color:var(--text-3);display:block;margin-top:3px">B2B košík vyžádá min. částku</small>
          </div>
          <div>
            <label class="form-label" style="color:#854F0B;font-weight:600">Splatnost FA</label>
            <div style="display:flex;align-items:center;gap:6px">
              <input class="form-input" id="sk-splatnost" type="number" min="0" max="365" value="${s.splatnost_dni ?? ''}" placeholder="např. 14" style="text-align:right;font-weight:600">
              <span style="font-size:13px;color:#854F0B;font-weight:700">dní</span>
            </div>
            <small style="font-size:11px;color:var(--text-3);display:block;margin-top:3px">Default při vystavení FA</small>
          </div>
        </div>
        <div style="margin-top:10px;padding:8px 12px;background:rgba(255,255,255,0.7);border-radius:6px;font-size:12px;color:#854F0B;line-height:1.5">
          💡 <strong>Priorita slev (zleva nejvyšší):</strong> Per-výrobek → Per-kategorie → Pravidlo na celý sortiment → <strong>Globální sleva ceníku</strong>
        </div>
      </div>
    </div>

    ${id ? `
      <div class="card-block" style="margin-top:14px;padding:12px 14px;background:#FFFAF1;border:1px solid #E8C988;border-radius:8px">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px">
          <h4 style="margin:0;font-size:14px;color:#854F0B">👥 Odběratelé ve skupině <span style="font-weight:400;font-size:12px;color:var(--text-3)">(${odbList.length})</span></h4>
          ${volni.length > 0 ? `<button class="btn-secondary" onclick="sk_otevritPridatOdb(${id})" style="font-size:12px;padding:5px 10px">+ Přidat odběratele</button>` : `<span style="font-size:11px;color:var(--text-3)">žádní volní odběratelé</span>`}
        </div>
        ${odbList.length === 0
          ? `<div style="font-size:12px;color:var(--text-3);text-align:center;padding:12px;background:white;border-radius:6px">Zatím žádní odběratelé v této skupině</div>`
          : `<div style="max-height:240px;overflow:auto;border:1px solid #E8C988;border-radius:6px;background:white">
              <table class="table" style="margin:0;font-size:12px">
                <thead><tr><th>Název</th><th>IČO</th><th>Město</th><th></th></tr></thead>
                <tbody>
                  ${odbList.map(o => `
                    <tr ${parseInt(o.aktivni) === 0 ? 'style="opacity:0.5"' : ''}>
                      <td><strong>${esc(o.nazev)}</strong>${parseInt(o.aktivni) === 0 ? ' <span style="font-size:10px;color:var(--text-3)">(neaktivní)</span>' : ''}</td>
                      <td style="font-family:monospace;font-size:11px">${esc(o.ico || '—')}</td>
                      <td style="font-size:11px;color:var(--text-3)">${esc(o.mesto || '—')}</td>
                      <td style="text-align:right"><button class="btn-secondary" onclick="sk_odebratOdb(${id}, ${o.id}, '${esc((o.nazev || '').replace(/'/g, "\\'"))}')" style="font-size:11px;padding:3px 8px" title="Odebrat ze skupiny">× Odebrat</button></td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            </div>`
        }
      </div>
    ` : `
      <div style="margin-top:14px;padding:14px;background:#F0F9FF;border:1px solid #93C5FD;border-radius:8px;font-size:12px;color:#1e40af;line-height:1.6">
        💡 <strong>Tip:</strong> Po vytvoření skupiny ji uvidíš v seznamu. Klikem na řádek otevřeš správu slev (procenta / pevné ceny per kategorie nebo výrobek) a členy skupiny.
      </div>
    `}

    <div class="form-actions">
      ${id ? `<a href="javascript:closeModal();otevritSlevySkupiny(${id}, '${esc(s.nazev || '').replace(/'/g, '')}')" style="font-size:13px;color:var(--brand);text-decoration:underline;cursor:pointer;margin-right:auto">🧾 Pravidla slev</a>` : ''}
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="ulozitSkupinu(${id || 'null'})">${id ? '💾 Uložit změny' : '+ Vytvořit skupinu'}</button>
    </div>
  `);
};

window.sk_otevritPridatOdb = async function(sk_id) {
  let s;
  try { s = await api(`admin_cenove_skupiny.php?id=${sk_id}`); }
  catch (e) { return alert('Chyba: ' + e.message); }
  const volni = Array.isArray(s.odberatele_volni) ? s.odberatele_volni : [];
  if (volni.length === 0) return alert('Žádní volní odběratelé.');

  // Zachovej main modal, zobraz "vnořený" modal-like overlay
  openModal(`+ Přidat odběratele do skupiny "${s.nazev}"`, `
    <p style="font-size:13px;color:var(--text-2);margin-bottom:10px">Vyber jednoho nebo více odběratelů ${volni.length > 1 ? '(zaškrtni více pro hromadné přidání)' : ''}.</p>
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px">
      <input class="form-input" id="sk-volni-q" placeholder="Hledat..." oninput="document.querySelectorAll('#sk-volni-tb tr').forEach(tr=>{const t=tr.dataset.search||'';tr.style.display=t.includes(this.value.toLowerCase())?'':'none'})" style="flex:1">
      <button class="btn-secondary" onclick="document.querySelectorAll('.sk-volni-cb').forEach(c=>c.checked=true)" style="font-size:12px">Vše</button>
      <button class="btn-secondary" onclick="document.querySelectorAll('.sk-volni-cb').forEach(c=>c.checked=false)" style="font-size:12px">Nic</button>
    </div>
    <div style="max-height:50vh;overflow:auto;border:1px solid var(--border);border-radius:6px">
      <table class="table" style="margin:0;font-size:13px">
        <thead><tr><th style="width:30px"></th><th>Název</th><th>IČO</th><th>Město</th></tr></thead>
        <tbody id="sk-volni-tb">
          ${volni.map(o => `
            <tr data-search="${esc(((o.nazev || '') + ' ' + (o.ico || '') + ' ' + (o.mesto || '')).toLowerCase())}">
              <td><input type="checkbox" class="sk-volni-cb" value="${o.id}"></td>
              <td><strong>${esc(o.nazev)}</strong></td>
              <td style="font-family:monospace;font-size:11px">${esc(o.ico || '—')}</td>
              <td style="font-size:11px;color:var(--text-3)">${esc(o.mesto || '—')}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
      <button class="btn-back" onclick="closeModal();sk_returnTo(${sk_id})">← Zpět</button>
      <button class="btn-primary btn-green" onclick="sk_pridatOdbSubmit(${sk_id})">✓ Přidat vybrané</button>
    </div>
  `);
};

function sk_returnTo(sk_id) {
  // Vrátit se buď do slev modalu (pokud uživatel přišel odtud) nebo do edit-skupina
  const back = state._sk_back_to_slevy;
  if (back && back.id === sk_id) {
    setTimeout(() => otevritSlevySkupiny(back.id, back.nazev), 50);
  } else {
    setTimeout(() => otevritSkupinu(sk_id), 50);
  }
}

window.sk_pridatOdbSubmit = async function(sk_id) {
  const ids = Array.from(document.querySelectorAll('.sk-volni-cb:checked')).map(c => parseInt(c.value));
  if (ids.length === 0) return alert('Vyber alespoň jednoho odběratele.');
  try {
    await api('admin_cenove_skupiny.php?action=pridat_odberatele', {
      method: 'POST',
      body: JSON.stringify({ skupina_id: sk_id, odberatel_ids: ids }),
    });
    closeModal();
    sk_returnTo(sk_id);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.sk_odebratOdb = async function(sk_id, odb_id, nazev) {
  if (!(await confirmDialog({ title: 'Odebrat ze skupiny?', msg: t('confirm_remove_from_group', { nazev }), okText: 'Odebrat', danger: true }))) return;
  try {
    await api('admin_cenove_skupiny.php?action=odebrat_odberatele', {
      method: 'POST',
      body: JSON.stringify({ odberatel_ids: [odb_id] }),
    });
    closeModal();
    sk_returnTo(sk_id);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.ulozitSkupinu = async function(id) {
  const numOrNull = (sel) => {
    const v = document.getElementById(sel)?.value;
    return v === '' || v == null ? null : parseFloat(v);
  };
  const intOrNull = (sel) => {
    const v = document.getElementById(sel)?.value;
    return v === '' || v == null ? null : parseInt(v);
  };
  const data = {
    nazev: document.getElementById('sk-nazev').value.trim(),
    popis: document.getElementById('sk-popis').value.trim() || null,
    aktivni: document.getElementById('sk-aktivni').checked ? 1 : 0,
    globalni_sleva_pct: numOrNull('sk-global-sleva'),
    minimum_obj_kc:     numOrNull('sk-min-obj'),
    splatnost_dni:      intOrNull('sk-splatnost'),
  };
  if (!data.nazev) return alert('Název je povinný');
  if (data.globalni_sleva_pct !== null && (data.globalni_sleva_pct < 0 || data.globalni_sleva_pct > 100)) {
    return alert('Sleva musí být mezi 0 a 100 %');
  }

  try {
    if (id) {
      data.id = id;
      await api('admin_cenove_skupiny.php', { method: 'PUT', body: JSON.stringify(data) });
    } else {
      await api('admin_cenove_skupiny.php', { method: 'POST', body: JSON.stringify(data) });
    }
    closeModal();
    navigate('cenove_skupiny');
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.smazatSkupinu = async function(id, nazev, pocet_odb) {
  let detail = '';
  if (pocet_odb > 0) {
    detail = `${pocet_odb} odběratel${pocet_odb === 1 ? '' : (pocet_odb < 5 ? 'é' : 'ů')} v této skupině bude přesunut do "bez skupiny".`;
  }
  if (!await confirmDelete2x({ co: `skupinu "${nazev}"`, detail })) return;
  try {
    await api(`admin_cenove_skupiny.php?id=${id}`, { method: 'DELETE' });
    navigate('cenove_skupiny');
  } catch (e) { alert('Chyba: ' + e.message); }
};

