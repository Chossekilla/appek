// =============================================================
// 🛣️ ROZVOZOVÉ TRASY — seskupení DL podle města pro řidiče
// =============================================================
async function renderRozvozy() {
  const c = document.getElementById('content');
  const dnes = new Date().toISOString().split('T')[0];
  const zitra = new Date(Date.now() + 86400000).toISOString().split('T')[0];
  const datum = state._rozvozyDatum || zitra;
  state._rozvozyDatum = datum;

  c.innerHTML = `
    <div class="page-head">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <button class="btn-back" onclick="navigate('dodaci_listy')" title="Zpět na Dodací listy" aria-label="Zpět na Dodací listy">
          <span class="btn-back-arrow">←</span>
          <span class="btn-back-lbl">Zpět na Dodací listy</span>
        </button>
        <div>
          <h1 class="page-title" style="margin:0">🛣️ Rozvozové trasy</h1>
          <p class="page-sub" style="margin:2px 0 0">Seskupení DL podle města — pro řidiče s pořadím zastávek</p>
        </div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-secondary" onclick="rozvozTisk()">🖨️ Tisk rozvozového listu</button>
      </div>
    </div>

    <div class="period-tabs">
      <button class="period-tab ${datum === dnes ? 'active' : ''}" onclick="rozvozSetDatum('${dnes}')"><span class="period-tab-icon">📅</span><span class="period-tab-text">Dnes</span></button>
      <button class="period-tab ${datum === zitra ? 'active' : ''}" onclick="rozvozSetDatum('${zitra}')"><span class="period-tab-icon">➡️</span><span class="period-tab-text">Zítra</span></button>
      <input class="filter-input" type="date" value="${datum}" onchange="rozvozSetDatum(this.value)" style="max-width:170px;margin-left:8px">
    </div>

    <div id="rozvozy-data"><div class="empty-state" style="padding:40px">⏳ Načítám…</div></div>
  `;

  try {
    const d = await api(`admin_rozvozy.php?datum=${datum}`);
    const host = document.getElementById('rozvozy-data');
    if (d.mesta.length === 0) {
      host.innerHTML = `<div class="card-block"><div class="empty-state">Žádné dodací listy na ${fmtDate(datum)}.</div></div>`;
      return;
    }
    host.innerHTML = `
      <div class="stat-grid" style="margin-bottom:14px">
        <div class="stat-card"><div class="stat-label">Měst</div><div class="stat-value">${d.pocet_mest}</div></div>
        <div class="stat-card"><div class="stat-label">Dodacích listů</div><div class="stat-value">${d.pocet_dl}</div></div>
        <div class="stat-card"><div class="stat-label">Celkem k rozvozu</div><div class="stat-value">${fmt(d.celkem_kc)}</div></div>
      </div>

      ${d.mesta.map((m, mi) => `
        <div class="card-block" style="margin-bottom:14px">
          <h3 style="margin:0 0 10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <span style="background:var(--primary);color:#fff;padding:4px 12px;border-radius:8px;font-size:14px;font-weight:700">${mi + 1}</span>
            📍 ${esc(m.mesto)}
            <span style="color:var(--text-3);font-size:13px;font-weight:400">${m.dl_count} DL · ${Math.round(m.celkem_ks)} ks · ${fmt(m.celkem_kc)}</span>
          </h3>
          <table class="table" style="margin:0;font-size:13px">
            <thead>
              <tr>
                <th style="width:32px"></th>
                <th>Odběratel / místo</th>
                <th>Adresa</th>
                <th>Kontakt</th>
                <th class="num">Pol.</th>
                <th class="num">Ks</th>
                <th class="num">Kč</th>
              </tr>
            </thead>
            <tbody>
              ${m.dl.map((dl, di) => `
                <tr style="cursor:pointer" onclick="openDodaciListDetail(${dl.id})">
                  <td><strong>${di + 1}.</strong></td>
                  <td>
                    <strong>${esc(dl.odberatel_nazev)}</strong>
                    ${dl.misto_nazev ? `<div style="font-size:11px;color:var(--text-3)">${esc(dl.misto_nazev)}</div>` : ''}
                    <div style="font-size:10px;color:var(--text-3)">${esc(dl.cislo)}${dl.objednavka_cislo ? ' · 🛒 ' + esc(dl.objednavka_cislo) : ''}</div>
                  </td>
                  <td style="font-size:12px">${esc(dl.rozvoz_adresa || '—')}${dl.rozvoz_psc ? `<br><span style="color:var(--text-3);font-size:11px">${esc(dl.rozvoz_psc)}</span>` : ''}</td>
                  <td style="font-size:12px">
                    ${dl.kontaktni_osoba ? `👤 ${esc(dl.kontaktni_osoba)}<br>` : ''}
                    ${(dl.misto_telefon || dl.odberatel_telefon) ? `📞 <a href="tel:${esc((dl.misto_telefon || dl.odberatel_telefon).replace(/[^+0-9]/g, ''))}" onclick="event.stopPropagation()" style="color:var(--primary)">${esc(dl.misto_telefon || dl.odberatel_telefon)}</a>` : ''}
                    ${dl.cas_dodani ? `<br>⏰ <strong>${esc(dl.cas_dodani)}</strong>` : ''}
                  </td>
                  <td class="num">${dl.pocet_polozek || 0}</td>
                  <td class="num">${Math.round(dl.celkem_ks || 0)}</td>
                  <td class="num"><strong>${fmt(dl.castka_celkem)}</strong></td>
                </tr>
                ${dl.pokyny_pro_ridice ? `
                <tr style="background:var(--info-bg);color:var(--info-text)">
                  <td colspan="7" style="padding:6px 12px;font-size:12px;font-style:italic">🚚 ${esc(dl.pokyny_pro_ridice)}</td>
                </tr>` : ''}
              `).join('')}
            </tbody>
          </table>
        </div>
      `).join('')}
    `;
  } catch (e) {
    document.getElementById('rozvozy-data').innerHTML = `<div style="color:var(--danger-text)">Chyba: ${esc(e.message)}</div>`;
  }
}

window.rozvozSetDatum = function(d) {
  state._rozvozyDatum = d;
  renderRozvozy();
};

window.rozvozTisk = function() {
  const datum = state._rozvozyDatum || new Date().toISOString().split('T')[0];
  window.open(`../api/rozvoz_print.php?datum=${datum}`, '_blank');
};

