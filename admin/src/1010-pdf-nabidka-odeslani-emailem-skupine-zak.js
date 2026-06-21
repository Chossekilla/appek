// =============================================================
// PDF NABÍDKA — odeslání e-mailem skupině zákazníků (s jejich cenami)
// =============================================================
window.katalogOdeslatEmail = async function() {
  const k = state._katalog;
  const vybrane = Array.from(k.vybrane);
  if (vybrane.length === 0) {
    return alert('Nejprve vyber alespoň jeden výrobek (klik na kartě výrobku).');
  }

  // Načti seznam slevových skupin + počty členů
  let skupiny = [];
  try { skupiny = await api('admin_cenove_skupiny.php'); }
  catch (e) { return alert('Chyba načtení skupin: ' + e.message); }

  if (!Array.isArray(skupiny) || skupiny.length === 0) {
    return alert('Žádné cenové skupiny. Nejprve vytvoř skupinu odběratelů v sekci „Slevové skupiny".');
  }

  const preselect = parseInt(k.skupina_id) || 0;
  const nazev = (k.nazev || '').trim() || 'Nabídka výrobků';

  openModal('📧 Odeslat PDF nabídku skupině', `
    <p style="font-size:13px;color:var(--text-2);margin-bottom:14px">
      Vybráno <strong>${vybrane.length} výrobků</strong>. Pošle se PDF nabídka s cenami pro vybranou skupinu odběratelů.
    </p>

    <div class="form-grid form-grid-tight" style="grid-template-columns:1fr">
      <div>
        <label class="form-label">Cenová skupina <span style="color:var(--text-3);font-weight:400;font-size:11px">(určuje ceny v nabídce; „bez skupiny" = základní ceník)</span></label>
        <select class="form-input" id="ke-skupina" onchange="ke_skupinaZmena(this.value)">
          <option value="0" ${!preselect ? 'selected' : ''}>— Bez skupiny (základní ceny, jen na zadané e-maily) —</option>
          ${skupiny.map(s => `<option value="${s.id}" ${preselect == s.id ? 'selected' : ''}>${esc(s.nazev)} · ${s.pocet_odberatelu || 0} členů</option>`).join('')}
        </select>
      </div>
      <div>
        <label class="form-label">Předmět e-mailu</label>
        <input class="form-input" id="ke-predmet" value="${esc(nazev)}" placeholder="např. Letní nabídka 2026">
      </div>
      <div>
        <label class="form-label">Úvodní text (volitelný — zobrazí se nad tabulkou produktů)</label>
        <textarea class="form-textarea" id="ke-zprava" rows="3" placeholder="např. Dobrý den, posíláme aktuální nabídku našich pekařských výrobků. Ceny jsou platné do…">Dobrý den,

posíláme Vám aktuální nabídku našich výrobků.

V případě zájmu nás kontaktujte.</textarea>
      </div>
    </div>

    <div id="ke-prijemci" style="margin-top:14px;padding:10px 14px;background:#FFFAF1;border:1px solid #E8C988;border-radius:8px;min-height:80px">
      <div style="font-size:13px;color:#854F0B;text-align:center;padding:12px">⏳ Načítám příjemce…</div>
    </div>

    <!-- Vlastní e-mailové adresy navíc -->
    <div style="margin-top:12px;padding:12px 14px;background:#F0F9FF;border:1px solid #93C5FD;border-radius:8px">
      <label class="form-label" style="color:#1e40af;display:flex;align-items:center;gap:6px">
        ➕ Další e-mailové adresy <span style="color:var(--text-3);font-weight:400;font-size:11px">(volitelně, mimo skupinu)</span>
      </label>
      <textarea class="form-textarea" id="ke-extra-emaily" rows="2" placeholder="Další adresy oddělené čárkou nebo Enter — např.&#10;jana@example.cz, novak@firma.cz&#10;dalsi@dodavatel.cz"></textarea>
      <small style="color:#1e40af;font-size:11px;margin-top:4px;display:block">💡 Použij pro: <strong>vyzkoušení</strong> e-mailu na sebe, jednorázové <strong>dodatky</strong>, lidi <strong>mimo skupinu</strong>.</small>
    </div>

    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap">
      <button class="btn-secondary" onclick="ke_testNaSebe()" title="Pošle testovací e-mail jen tobě (na firma_email z nastavení)" style="margin-right:auto;font-size:12px">📨 Test na sebe</button>
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="ke_odeslat()" id="ke-odeslat-btn">📧 Odeslat e-mail</button>
    </div>
  `, 'wide');

  // Inicializuj zobrazení příjemců
  setTimeout(() => ke_skupinaZmena(preselect || skupiny[0].id), 30);
};

window.ke_skupinaZmena = async function(skupina_id) {
  const id = parseInt(skupina_id) || 0;
  const box = document.getElementById('ke-prijemci');
  if (!box) return;
  if (!id) {
    // „Bez skupiny" — pošle se jen na e-maily zadané v sekci níž
    box.innerHTML = `
      <div style="text-align:center;padding:14px">
        <div style="font-size:24px;margin-bottom:6px">📧</div>
        <strong style="font-size:13px;color:#854F0B">Bez skupiny — základní ceny</strong>
        <div style="font-size:12px;color:var(--text-2);margin-top:4px;line-height:1.5">
          E-mail se pošle <strong>jen na adresy zadané níže</strong> v sekci „Další e-mailové adresy".<br>
          V tabulce nabídky budou ceny <strong>bez slev</strong> (základní ceník).
        </div>
      </div>
    `;
    return;
  }
  box.innerHTML = '<div style="font-size:13px;color:#854F0B;text-align:center;padding:12px">⏳ Načítám příjemce…</div>';
  try {
    const sk = await api(`admin_cenove_skupiny.php?id=${id}`);
    const odb = Array.isArray(sk.odberatele) ? sk.odberatele : [];
    if (odb.length === 0) {
      box.innerHTML = `
        <div style="font-size:13px;color:#92400e;text-align:center;padding:12px">
          ⚠ Skupina <strong>${esc(sk.nazev)}</strong> nemá žádné odběratele.<br>
          <span style="font-size:11px">Nejdřív do ní přidej odběratele v sekci „Slevové skupiny".</span>
        </div>`;
      return;
    }
    // Načteme detail odběratelů (kvůli emailům)
    const detail = await api('admin_odberatele.php');
    const odbDetail = Array.isArray(detail) ? detail : (detail.odberatele || []);
    const detailById = Object.fromEntries(odbDetail.map(o => [o.id, o]));

    box.innerHTML = `
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px">
        <strong style="color:#854F0B">📨 Příjemci ze skupiny „${esc(sk.nazev)}" (${odb.length})</strong>
        <div>
          <button class="btn-secondary" onclick="document.querySelectorAll('.ke-prij-cb').forEach(c=>c.checked=true)" style="font-size:11px;padding:3px 8px">Vše</button>
          <button class="btn-secondary" onclick="document.querySelectorAll('.ke-prij-cb').forEach(c=>c.checked=false)" style="font-size:11px;padding:3px 8px">Nic</button>
        </div>
      </div>
      <div style="max-height:200px;overflow:auto;background:white;border:1px solid #E8C988;border-radius:6px">
        <table class="table" style="margin:0;font-size:12px">
          <thead><tr><th style="width:30px"></th><th>Odběratel</th><th>E-mail</th></tr></thead>
          <tbody>
            ${odb.map(o => {
              const d = detailById[o.id] || {};
              const email = (d.email || '').trim();
              return `
                <tr ${!email ? 'style="opacity:0.5"' : ''}>
                  <td><input type="checkbox" class="ke-prij-cb" value="${o.id}" data-email="${esc(email)}" ${email ? 'checked' : 'disabled'}></td>
                  <td><strong>${esc(o.nazev)}</strong></td>
                  <td style="font-size:11px;color:${email ? 'var(--text-2)' : '#dc2626'}">${email || '⚠ chybí e-mail'}</td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
      </div>
    `;
  } catch (e) {
    box.innerHTML = `<div style="color:#dc2626;font-size:13px;text-align:center;padding:12px">⚠ Chyba: ${esc(e.message)}</div>`;
  }
};

// Helper: vytáhne validní e-maily z textarea (oddělené čárkou, středníkem, mezerou nebo Enter)
function ke_parsujExtraEmaily() {
  const txt = document.getElementById('ke-extra-emaily')?.value || '';
  const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  return txt.split(/[\s,;]+/).map(s => s.trim()).filter(s => s && re.test(s));
}

window.ke_odeslat = async function() {
  const k = state._katalog;
  const skupina_id = parseInt(document.getElementById('ke-skupina').value) || 0;
  const predmet = document.getElementById('ke-predmet').value.trim();
  const zprava = document.getElementById('ke-zprava').value.trim();
  const odberatel_ids = Array.from(document.querySelectorAll('.ke-prij-cb:checked')).map(c => parseInt(c.value));
  const extraEmaily = ke_parsujExtraEmaily();

  // skupina_id == 0 → „bez skupiny" — pošle se jen na extra e-maily, ceny základní
  if (skupina_id === 0 && extraEmaily.length === 0) {
    return alert('Bez skupiny musíš zadat alespoň jeden e-mail v sekci „Další e-mailové adresy".');
  }
  if (skupina_id !== 0 && odberatel_ids.length === 0 && extraEmaily.length === 0) {
    return alert('Vyber alespoň jednoho odběratele NEBO zadej e-mail v sekci „Další e-mailové adresy".');
  }
  if (!predmet) return alert('Vyplň předmět.');

  // Validace extra emailů — pokud user napsal něco co nezavalidovalo
  const rawExtra = (document.getElementById('ke-extra-emaily')?.value || '').trim();
  if (rawExtra && extraEmaily.length === 0) {
    if (!(await confirmDialog({ msg: 'V poli „Další e-mailové adresy" jsi něco napsal, ale žádný platný e-mail jsem nenašel. Pokračovat bez nich?', danger: false }))) return;
  }

  const btn = document.getElementById('ke-odeslat-btn');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Odesílám…'; }

  try {
    const r = await api('admin_katalog_email.php', {
      method: 'POST',
      body: JSON.stringify({
        skupina_id,
        odberatel_ids,
        extra_emaily: extraEmaily,
        vyrobek_ids: Array.from(k.vybrane),
        poznamky: k.poznamky,
        predmet,
        zprava,
        nazev: (k.nazev || '').trim(),
      }),
    });
    closeModal();
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.15);font-size:13px;font-weight:600;z-index:9999;line-height:1.5';
    t.innerHTML = `📧 Odesláno ${r.odeslano || 0} e-mailů${r.chyby > 0 ? `<br><small style="color:#92400e">⚠ ${r.chyby} chyb — zkontroluj e-maily</small>` : ''}`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 5000);
  } catch (e) {
    alert('Chyba: ' + e.message);
    if (btn) { btn.disabled = false; btn.textContent = '📧 Odeslat e-mail'; }
  }
};

// Test e-mail na sebe — pošle náhled na firma_email z nastavení
window.ke_testNaSebe = async function() {
  let testEmail = '';
  try {
    const ns = await api('admin_nastaveni.php');
    testEmail = ns?.firma_email || '';
  } catch (e) {}
  testEmail = (await promptDialog({ msg: 'Zadej e-mail pro test (defaultně firma_email z nastavení):', value: testEmail }));
  if (!testEmail || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(testEmail)) {
    if (testEmail !== null) alert('Neplatný e-mail.');
    return;
  }

  const k = state._katalog;
  const skupina_id = parseInt(document.getElementById('ke-skupina').value) || 0;
  const predmet = '[TEST] ' + (document.getElementById('ke-predmet').value.trim() || 'Nabídka');
  const zprava = '⚠ TESTOVACÍ E-MAIL ⚠\n\n' + (document.getElementById('ke-zprava').value.trim() || '');
  // skupina_id == 0 je OK — test bude se základními cenami

  const btn = document.getElementById('ke-odeslat-btn');
  try {
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Odesílám test…'; }
    const r = await api('admin_katalog_email.php', {
      method: 'POST',
      body: JSON.stringify({
        skupina_id,
        odberatel_ids: [],
        extra_emaily: [testEmail],
        vyrobek_ids: Array.from(k.vybrane),
        poznamky: k.poznamky,
        predmet,
        zprava,
        nazev: (k.nazev || '').trim(),
      }),
    });
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.15);font-size:13px;font-weight:600;z-index:9999';
    t.textContent = `✓ Test odeslán na ${testEmail}`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3500);
  } catch (e) {
    alert('Chyba: ' + e.message);
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = '📧 Odeslat e-mail'; }
  }
};

window.katalogGenerate = function() {
  const k = state._katalog;
  const params = new URLSearchParams();

  if (k.vybrane.size > 0) {
    params.set('vyrobky', Array.from(k.vybrane).join(','));
    // Poznámky — pozn[ID]=text
    Object.entries(k.poznamky).forEach(([id, txt]) => {
      if ((txt || '').trim() !== '' && k.vybrane.has(parseInt(id))) {
        params.append(`pozn[${id}]`, txt);
      }
    });
  } else if (k.kategorie_filtr !== null) {
    params.set('kategorie', String(k.kategorie_filtr));
  }
  // Bez výběru i bez filtru = vše

  if (k.skupina_id) params.set('skupina', k.skupina_id);
  if ((k.nazev || '').trim()) params.set('nazev', k.nazev.trim());

  const url = '../api/katalog_pdf.php' + (params.toString() ? '?' + params.toString() : '');
  window.open(url, '_blank');
};

