// =============================================================
// 🔍 PANEL AUDIT — pod dokumentem vnitřního auditu
// =============================================================

// Detekce: je tento dokument "Vnitřní audit"? Hledá podle obsahu (robustnější
// než název, protože uživatel může dokument přejmenovat).
function haccpJeAuditDokument(akt) {
  if (!akt) return false;
  const obsah = akt.obsah || '';
  if (obsah.includes('Záznamy o auditech')) return true;
  // Fallback — i podle názvu, lower-cased, bez diakritiky
  const n = (akt.nazev || '').toLowerCase();
  if (n.includes('audit') && (n.includes('verifikace') || n.includes('validace'))) return true;
  return false;
}
const HACCP_VYSLEDKY = {
  v_poradku: { label: '✓ V pořádku', color: '#166534', bg: '#DCFCE7' },
  s_pripominkami: { label: '⚠ S připomínkami', color: '#92400e', bg: '#FEF3C7' },
  nevyhovuje: { label: '✗ Nevyhovuje', color: '#991B1B', bg: '#FEE2E2' },
};

function renderHaccpAudityPanel() {
  const audity = haccpState.audity || [];
  const dalsiRok = audity.length > 0 ? Math.max(...audity.map(a => parseInt(a.rok))) + 1 : new Date().getFullYear();
  return `
    <div style="margin:14px;padding:16px;background:linear-gradient(135deg,#FFF8E7 0%,#FAEEDA 100%);border:1.5px solid #E8C988;border-radius:12px">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;flex-wrap:wrap">
        <h3 style="margin:0;font-size:15px;color:#854F0B">🔍 Provedené interní audity</h3>
        <button class="btn-primary btn-green" onclick="haccpAuditPridat(${dalsiRok})" style="font-size:12px;padding:7px 14px">
          + Přidat audit za rok ${dalsiRok}
        </button>
      </div>

      ${audity.length === 0
        ? '<div style="padding:14px;text-align:center;color:var(--text-3);font-size:13px">Zatím žádné audity. Klikni „+ Přidat audit" výše.</div>'
        : `
          <table class="table" style="font-size:12px;background:white">
            <thead>
              <tr style="background:#FFF8E7">
                <th style="width:60px">Rok</th>
                <th style="width:110px">Datum</th>
                <th>Auditor</th>
                <th>Výsledek</th>
                <th>Nápravná opatření</th>
                <th style="width:90px;text-align:right"></th>
              </tr>
            </thead>
            <tbody>
              ${audity.map(a => {
                const v = HACCP_VYSLEDKY[a.vysledek] || HACCP_VYSLEDKY.v_poradku;
                return `
                  <tr>
                    <td><strong>${a.rok}</strong></td>
                    <td>${a.datum ? fmtDate(a.datum) : '—'}</td>
                    <td>${esc(a.auditor || '')}</td>
                    <td><span style="background:${v.bg};color:${v.color};padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;white-space:nowrap">${v.label}</span></td>
                    <td style="font-size:11px;color:var(--text-2);max-width:300px">${esc(a.napravna_opatreni || '—')}</td>
                    <td style="text-align:right;white-space:nowrap">
                      <button class="btn-secondary" style="font-size:11px;padding:4px 10px" onclick="haccpAuditUpravit(${a.id})">✏️</button>
                      <button class="btn-secondary" style="font-size:11px;padding:4px 8px;color:#B91C1C" onclick="haccpAuditSmazat(${a.id}, ${a.rok})">✕</button>
                    </td>
                  </tr>
                `;
              }).join('')}
            </tbody>
          </table>
          <div style="margin-top:8px;font-size:11px;color:var(--text-3)">
            💡 Tato tabulka se automaticky propíše do dokumentu „Vnitřní audit" při dalším otevření i do tisku PDF příručky.
          </div>
        `}
    </div>
  `;
}

window.haccpAuditPridat = function(rok) {
  haccpAuditOtevritModal({
    rok,
    datum: new Date().toISOString().slice(0, 10),
    auditor: 'Hana Mašková',
    vysledek: 'v_poradku',
    napravna_opatreni: '',
    poznamka: '',
  }, false);
};

window.haccpAuditUpravit = function(id) {
  const a = (haccpState.audity || []).find(x => parseInt(x.id) === parseInt(id));
  if (!a) return alert('Záznam nenalezen');
  haccpAuditOtevritModal({ ...a }, true);
};

function haccpAuditOtevritModal(data, isEdit) {
  const v = HACCP_VYSLEDKY;
  // 🆕 v2.9.66 — openModal(title, body): předtím se celé HTML poslalo jako title
  // (skončilo jako text v <h2>) a vlastní modal-head dělal 2. křížek. Teď 1 hlavička.
  openModal(`${isEdit ? '✏️ Upravit audit' : '+ Přidat audit'} za rok ${data.rok}`, `
      <div class="form-grid form-grid-tight">
        <div>
          <label class="form-label">Rok</label>
          <input class="form-input" type="number" id="ha-rok" value="${parseInt(data.rok) || new Date().getFullYear()}" min="2000" max="2100" ${isEdit ? 'readonly style="background:var(--surface-2)"' : ''}>
        </div>
        <div>
          <label class="form-label">Datum auditu</label>
          <input class="form-input" type="date" id="ha-datum" value="${esc(data.datum || '')}">
        </div>
        <div class="full">
          <label class="form-label">Auditor (jméno)</label>
          <input class="form-input" type="text" id="ha-auditor" value="${esc(data.auditor || 'Hana Mašková')}" placeholder="např. Hana Mašková">
          <small style="font-size:11px;color:var(--text-3);margin-top:4px;display:block">💡 Auditor je obvykle školitel HACCP. Můžeš zadat víc jmen oddělených čárkou.</small>
        </div>
        <div class="full">
          <label class="form-label">Výsledek auditu</label>
          <select class="form-select" id="ha-vysledek">
            ${Object.entries(v).map(([k, x]) => `<option value="${k}" ${data.vysledek === k ? 'selected' : ''}>${x.label}</option>`).join('')}
          </select>
        </div>
        <div class="full">
          <label class="form-label">Nápravná opatření</label>
          <textarea class="form-input" id="ha-napravna" rows="2" placeholder="Pokud byly připomínky, co se s nimi udělalo">${esc(data.napravna_opatreni || '')}</textarea>
        </div>
        <div class="full">
          <label class="form-label">Poznámka</label>
          <textarea class="form-input" id="ha-poznamka" rows="2">${esc(data.poznamka || '')}</textarea>
        </div>
      </div>
      <div class="form-actions">
        <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
        <div style="flex:1"></div>
        <button class="btn-primary btn-green" onclick="haccpAuditUlozit(${isEdit ? parseInt(data.id) : 0})">💾 Uložit</button>
      </div>
  `);
}

window.haccpAuditUlozit = async function(id) {
  const body = {
    rok: parseInt(document.getElementById('ha-rok').value),
    datum: document.getElementById('ha-datum').value || null,
    auditor: document.getElementById('ha-auditor').value.trim(),
    vysledek: document.getElementById('ha-vysledek').value,
    napravna_opatreni: document.getElementById('ha-napravna').value.trim(),
    poznamka: document.getElementById('ha-poznamka').value.trim(),
  };
  try {
    if (id) {
      await api(`admin_haccp_dokumenty.php?action=audity_update&id=${id}`, {
        method: 'PUT', body: JSON.stringify(body),
      });
    } else {
      await api('admin_haccp_dokumenty.php?action=audity_add', {
        method: 'POST', body: JSON.stringify(body),
      });
    }
    closeModal();
    const a = await api('admin_haccp_dokumenty.php?action=audity_list');
    haccpState.audity = a.audity || [];
    // Také znovu načti obsah dokumentu, aby se v něm propsala nová tabulka
    if (haccpState.dok_aktivni_id) {
      try {
        haccpState.dok_aktivni_obsah = await api(`admin_haccp_dokumenty.php?id=${haccpState.dok_aktivni_id}`);
      } catch (e) {}
    }
    haccpRender();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.haccpAuditSmazat = async function(id, rok) {
  if (!await confirmDelete2x(`HACCP audit za rok ${rok}`)) return;
  try {
    await api(`admin_haccp_dokumenty.php?action=audity_delete&id=${id}`, { method: 'DELETE' });
    const a = await api('admin_haccp_dokumenty.php?action=audity_list');
    haccpState.audity = a.audity || [];
    if (haccpState.dok_aktivni_id) {
      try {
        haccpState.dok_aktivni_obsah = await api(`admin_haccp_dokumenty.php?id=${haccpState.dok_aktivni_id}`);
      } catch (e) {}
    }
    haccpRender();
  } catch (e) { alert('Chyba: ' + e.message); }
};

function haccpDokJeZmena() {
  const akt = haccpState.dok_aktivni_obsah;
  if (!akt) return false;
  const nazev = document.getElementById('hd-dok-nazev')?.value;
  const obsah = document.getElementById('hd-dok-obsah')?.innerHTML;
  return (nazev !== undefined && nazev !== akt.nazev) || (obsah !== undefined && obsah !== akt.obsah);
}

window.haccpDokUlozit = async function() {
  const akt = haccpState.dok_aktivni_obsah;
  if (!akt) return;
  const data = {
    kategorie: document.getElementById('hd-dok-kat').value,
    nazev: document.getElementById('hd-dok-nazev').value.trim(),
    poradi: parseInt(document.getElementById('hd-dok-poradi').value) || 0,
    obsah: document.getElementById('hd-dok-obsah').innerHTML,
    aktivni: document.getElementById('hd-dok-aktivni').checked ? 1 : 0,
  };
  if (!data.nazev) return alert('Vyplň název');
  try {
    if (akt.id) {
      await api(`admin_haccp_dokumenty.php?id=${akt.id}`, { method: 'PUT', body: JSON.stringify(data) });
    } else {
      const r = await api('admin_haccp_dokumenty.php', { method: 'POST', body: JSON.stringify(data) });
      haccpState.dok_aktivni_id = r.id;
    }
    haccpState.dokumenty = await api('admin_haccp_dokumenty.php');
    haccpState.dok_aktivni_obsah = await api(`admin_haccp_dokumenty.php?id=${haccpState.dok_aktivni_id}`);
    haccpRender();
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:12px 18px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.15);font-size:13px;font-weight:600;z-index:9999';
    t.textContent = '✓ Dokument uložen';
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 1800);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.haccpDokSmazat = async function(id) {
  const akt = haccpState.dok_aktivni_obsah;
  if (!akt) return;
  if (!await confirmDelete2x(`HACCP dokument „${akt.nazev}"`)) return;
  try {
    await api(`admin_haccp_dokumenty.php?id=${id}`, { method: 'DELETE' });
    haccpState.dok_aktivni_id = null;
    haccpState.dok_aktivni_obsah = null;
    haccpState.dokumenty = await api('admin_haccp_dokumenty.php');
    haccpRender();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.haccpDokNovy = function() {
  haccpState.dok_aktivni_id = null;
  haccpState.dok_aktivni_obsah = {
    id: null,
    kategorie: 'plan_haccp',
    nazev: 'Nový dokument',
    poradi: 99,
    obsah: '<h2>Nový dokument</h2>\n<p>Začni psát…</p>',
    aktivni: 1,
  };
  haccpRender();
};

function haccpRenderDefaulty() {
  const d = haccpState.defaults || {};
  const customs = Array.isArray(d._custom) ? d._custom : [];
  return `
    <div class="card-block">
      <h3 style="margin:0 0 6px;font-size:15px">⚙️ Defaultní hodnoty pro všechny výrobky</h3>
      <p class="page-sub" style="margin-bottom:16px;font-size:12px">
        Vyplň jednou — hodnoty se použijí u každého výrobku, který nemá svůj přepis.
      </p>
      <div class="form-grid">
        ${HACCP_FIELDS.map(f => `
          <div class="full">
            <label class="form-label">${esc(f.l)}</label>
            ${f.type === 'textarea'
              ? `<textarea class="form-textarea" id="hd-${f.k}" rows="2" placeholder="${esc(f.placeholder || '')}">${esc(d[f.k] || '')}</textarea>`
              : `<input class="form-input" id="hd-${f.k}" value="${esc(d[f.k] || '')}" placeholder="${esc(f.placeholder || '')}">`}
          </div>
        `).join('')}
      </div>

      <!-- Vlastní pole -->
      <div style="margin-top:18px;padding-top:14px;border-top:1px dashed var(--border)">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px">
          <h4 style="margin:0;font-size:14px">➕ Vlastní pole <span style="color:var(--text-3);font-weight:400;font-size:11px">(libovolně přidaná pole nad rámec standardu)</span></h4>
          <button class="btn-secondary" onclick="haccpDefCustomAdd()" style="font-size:12px;padding:5px 10px">+ Přidat pole</button>
        </div>
        ${customs.length === 0 ? '<div style="color:var(--text-3);font-size:13px;padding:10px 0">Žádná vlastní pole. Klepni na „+ Přidat pole" pro vytvoření.</div>' : `
          <div id="hd-custom-rows" style="display:flex;flex-direction:column;gap:6px">
            ${customs.map((c, i) => `
              <div class="naklad-row" data-idx="${i}" style="display:grid;grid-template-columns:1fr 2fr auto;gap:8px;align-items:center">
                <input class="form-input" placeholder="Název pole (např. „Doporučená teplota")" value="${esc(c.label || '')}" data-fld="label">
                <input class="form-input" placeholder="Hodnota" value="${esc(c.value || '')}" data-fld="value">
                <button class="btn-danger" style="padding:6px 10px;font-size:12px" onclick="this.closest('.naklad-row').remove()">×</button>
              </div>
            `).join('')}
          </div>
        `}
      </div>

      <div class="form-actions" style="margin-top:18px">
        <div style="flex:1"></div>
        <button class="btn-primary btn-green" onclick="ulozitHaccpDefaulty()">💾 Uložit defaulty</button>
      </div>
    </div>
  `;
}

window.haccpSetTab = async function(t) {
  haccpState.tab = t;
  if (t === 'grafy' && (!Array.isArray(haccpState.grafy) || haccpState.grafy.length === 0)) {
    try { haccpState.grafy = await api('admin_haccp_grafy.php'); }
    catch (e) { haccpState.grafy = []; }
  }
  if (t === 'dokumenty' && !haccpState.dokumenty) {
    try { haccpState.dokumenty = await api('admin_haccp_dokumenty.php'); }
    catch (e) { haccpState.dokumenty = { kategorie: [], dokumenty: [] }; }
  }
  haccpRender();
};

window.haccpRefreshGrafy = async function() {
  try { haccpState.grafy = await api('admin_haccp_grafy.php'); }
  catch (e) { haccpState.grafy = []; }
  haccpRender();
};

