// =============================================================
// 🧾 SUROVINY — doplnit orientační ceny + výchozí sada surovin (v3.0.640, obnoveno a dodělané)
//   Backend: admin_suroviny_autofill_ceny.php (náhled GET / uložení POST, ceny počítá server,
//   jednotku suroviny NIKDY nemění) a admin_suroviny.php?action=seed_nahled|seed.
// =============================================================
window._surRefreshAfterChange = function() {
  state._suroviny_cache = null; state._suroviny_full_cache = null;
  if (typeof apiCacheInvalidate === 'function') { apiCacheInvalidate('admin_suroviny.php'); apiCacheInvalidate('admin_vyrobky.php'); }
  if (state.current === 'suroviny') (window.keepScroll || (f => f()))(renderSuroviny);
};
window._surIsReadonly = () => (state.admin && state.admin.role) === 'readonly';

window.surovinyDoplnitCeny = async function() {
  let p;
  try { p = await api('admin_suroviny_autofill_ceny.php'); } catch (e) { return toastError(e.message); }
  state._surCenyPreview = p;
  state._surCenyForce = false;
  state._surCenySel = new Set((p.navrhy || []).filter(n => n.stav === 'doplnit').map(n => n.id));
  openModal('🧾 Doplnit ceny surovin', _surCenyHtml(), 'wide');
};

function _surCenyFmt(n) {
  return (Math.round((parseFloat(n) || 0) * 100) / 100).toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' Kč';
}

function _surCenyHtml() {
  const p = state._surCenyPreview || {}; const force = !!state._surCenyForce; const sel = state._surCenySel;
  const navrhy = p.navrhy || [];
  const lzeVybrat = n => n.stav === 'doplnit' || (force && n.stav === 'ma_cenu');
  const radky = navrhy.filter(n => n.stav === 'doplnit' || n.stav === 'ma_cenu');
  const ro = _surIsReadonly(); const blok = p.mena && p.mena.rezim === 'blok';
  const vybrano = radky.filter(n => sel.has(n.id) && lzeVybrat(n)).length;
  const tr = radky.map(n => {
    const v = n.navrh || {};
    const akt = n.cena_za_jed_akt != null ? `${_surCenyFmt(n.cena_za_jed_akt)}/${esc(v.jed || '')}` : '—';
    const zm = n.cena_za_jed_akt ? ((v.cena_za_jed - n.cena_za_jed_akt) / n.cena_za_jed_akt * 100) : null;
    const zmHtml = zm === null ? '<span style="color:var(--text-3)">nová</span>'
      : `<span style="color:${zm > 5 ? 'var(--danger-text)' : (zm < -5 ? 'var(--success-text)' : 'var(--text-3)')}">${zm > 0 ? '+' : ''}${zm.toFixed(0)} %</span>`;
    const dis = !lzeVybrat(n) || ro || blok;
    return `<tr${n.stav === 'ma_cenu' && !force ? ' style="opacity:.55"' : ''}>
      <td><input type="checkbox" ${sel.has(n.id) && lzeVybrat(n) ? 'checked' : ''} ${dis ? 'disabled' : ''} onchange="_surCenyToggle(${n.id}, this.checked)" aria-label="${esc(t('sur_ceny_vybrat', { nazev: n.nazev }))}"></td>
      <td><strong>${esc(n.nazev)}</strong><div style="font-size:11px;color:var(--text-3)">${t('sur_ceny_vedeno_v', { j: esc(n.jednotka || '—') })}</div></td>
      <td style="font-size:12px;color:var(--text-2)">${esc(v.label || '')}</td>
      <td class="num" style="font-size:12px">${akt}</td>
      <td class="num"><strong>${_surCenyFmt(v.cena_za_jed)}/${esc(v.jed || '')}</strong><div style="font-size:11px;color:var(--text-3)">${t('sur_ceny_baleni')}: ${_surCenyFmt(v.cena_baleni)} / ${fmtQty(v.obsah_baleni)} ${esc(n.jednotka || '')}</div></td>
      <td class="num" style="font-size:12px">${zmHtml}</td></tr>`;
  }).join('');
  const sekce = (stav, nadpis, f) => {
    const l = navrhy.filter(n => n.stav === stav);
    return l.length ? `<details style="margin-top:8px;font-size:12px;color:var(--text-2)"><summary style="cursor:pointer;padding:4px 0">${nadpis} (${l.length})</summary><ul style="margin:6px 0 0 20px;max-height:140px;overflow:auto">${l.map(f).join('')}</ul></details>` : '';
  };
  return `
    <div style="background:var(--info-bg,#EFF6FF);color:var(--info-text,inherit);border:1px solid var(--border);border-radius:8px;padding:10px 14px;font-size:12px;margin-bottom:12px">
      ℹ️ ${esc(p.zdroj || '')} ${t('sur_ceny_info')}</div>
    ${blok ? `<div style="background:var(--danger-bg);color:var(--danger-text);border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:12px">${t('sur_ceny_blok', { kod: esc(p.mena.db_kod) })}</div>` : ''}
    <label style="display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:10px;cursor:pointer">
      <input type="checkbox" ${force ? 'checked' : ''} ${ro || blok ? 'disabled' : ''} onchange="state._surCenyForce=this.checked;_surCenyRender()"> Přepsat i ručně zadané ceny (vyberte je jednotlivě)</label>
    ${radky.length ? `<div style="max-height:50vh;overflow:auto;border:1px solid var(--border);border-radius:6px">
      <table class="table" style="margin:0;font-size:13px">
        <thead><tr><th style="width:28px"></th><th>Surovina</th><th>Rozpoznáno jako</th><th class="num">Aktuální</th><th class="num">Návrh</th><th class="num">Změna</th></tr></thead>
        <tbody>${tr}</tbody></table></div>`
      : '<div class="empty-state" style="padding:24px">Žádná surovina k doplnění — všechny rozpoznané už cenu mají.</div>'}
    ${sekce('nekompatibilni', t('sur_ceny_sekce_nekompat'), n => `<li>${esc(n.nazev)} — ${esc(n.duvod)}</li>`)}
    ${sekce('nerozpoznano', t('sur_ceny_sekce_nerozpoznano'), n => `<li>${esc(n.nazev)}</li>`)}
    ${sekce('beze_zmeny', t('sur_ceny_sekce_beze_zmeny'), n => `<li>${esc(n.nazev)}</li>`)}
    <div class="form-actions" style="margin-top:14px">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="surovinyDoplnitCenyRun()" ${!vybrano || ro || blok ? 'disabled' : ''} ${ro ? 'title="Náhledový účet — jen prohlížení"' : ''}>${t('sur_ceny_ulozit_vybrane', { n: vybrano })}</button>
    </div>`;
}
window._surCenyRender = function() { openModal('🧾 Doplnit ceny surovin', _surCenyHtml(), 'wide'); };   // stejný titulek = překreslení se zachovaným scrollem
window._surCenyToggle = function(id, on) {
  if (on) state._surCenySel.add(id); else state._surCenySel.delete(id);
  _surCenyRender();
};

window.surovinyDoplnitCenyRun = async function() {
  const p = state._surCenyPreview || {}; const force = !!state._surCenyForce;
  const lze = n => n.stav === 'doplnit' || (force && n.stav === 'ma_cenu');
  const vybrane = (p.navrhy || []).filter(n => state._surCenySel.has(n.id) && lze(n));
  if (!vybrane.length) return;
  const prepis = vybrane.filter(n => n.stav === 'ma_cenu').length;
  if (prepis && !(await confirmDialog({ title: t('sur_ceny_prepsat_title'), msg: t('sur_ceny_prepsat_msg', { n: prepis }), okText: t('sur_ceny_prepsat_ok'), danger: true }))) return;
  try {
    const r = await api('admin_suroviny_autofill_ceny.php', { method: 'POST', body: { force, ids: vybrane.map(n => n.id) } });
    closeModal();
    _surRefreshAfterChange();
    toastSuccess(t('sur_ceny_doplneno', { n: r.applied }), r.zaloha ? t('sur_ceny_zaloha_ok') : undefined);
    if (force && !r.zaloha) toastWarn(t('sur_ceny_zaloha_fail'));
  } catch (e) { toastError(e.message); }
};

window.seedSuroviny = async function() {
  let n;
  try { n = await api('admin_suroviny.php?action=seed_nahled'); } catch (e) { return toastError(e.message); }
  if (!n.novych) return toastInfo(t('sur_seed_vse_mate'));
  if (_surIsReadonly()) return toastInfo(t('sur_seed_readonly', { n: n.novych, celkem: n.celkem }));
  if (!(await confirmDialog({ title: t('sur_seed_title'), msg: t('sur_seed_msg', { n: n.novych, celkem: n.celkem }), okText: t('sur_seed_ok'), icon: '🌾' }))) return;
  try {
    const r = await api('admin_suroviny.php?action=seed', { method: 'POST', body: {} });
    _surRefreshAfterChange();
    toastSuccess(r.message);
    if (r.inserted > 0 && await confirmDialog({ title: t('sur_seed_ceny_title'), msg: t('sur_seed_ceny_msg'), okText: t('sur_seed_ceny_ok'), cancelText: t('sur_seed_ceny_ne') })) surovinyDoplnitCeny();
  } catch (e) { toastError(e.message); }
};

// Prázdná databáze surovin → nabídnout start (dřív jen „Žádné suroviny odpovídající filtru")
window._surEmptyCta = () => `<div class="empty-state" style="padding:28px 16px"><div style="font-size:15px;font-weight:700;margin-bottom:6px">Zatím nemáte žádné suroviny</div><p style="margin:0 0 14px;color:var(--text-2);font-size:13px">Začněte sadou běžných pekařských surovin, nebo importem.</p><div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap"><button class="btn-primary btn-green" onclick="seedSuroviny()">🌾 Naplnit výchozí suroviny</button><button class="btn-secondary" onclick="otevritImportSurovin()">📦 Základní balíček s cenami</button><button class="btn-secondary" onclick="editSurovina()">+ Nová surovina</button></div></div>`;
