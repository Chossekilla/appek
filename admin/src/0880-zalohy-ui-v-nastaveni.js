// ===================================================================
// ZÁLOHY — UI v Nastavení
// ===================================================================
function _zalohaTypBadge(t) {
  const map = {
    manual:   { bg: '#DCFCE7', fg: '#166534', txt: '✋ ručně' },
    auto:     { bg: '#DBEAFE', fg: '#1E40AF', txt: '🕐 auto' },
    snapshot: { bg: '#FEF3C7', fg: '#92400e', txt: '📸 snapshot' },
  };
  const c = map[t] || { bg: '#E5E7EB', fg: '#374151', txt: t };
  return `<span style="background:${c.bg};color:${c.fg};padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;white-space:nowrap">${c.txt}</span>`;
}

function _zalohaVel(b) {
  if (b < 1024) return b + ' B';
  if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' kB';
  if (b < 1024 * 1024 * 1024) return (b / 1024 / 1024).toFixed(1) + ' MB';
  return (b / 1024 / 1024 / 1024).toFixed(2) + ' GB';
}

window.zalohyRefresh = async function(showAll = false) {
  const el = document.getElementById('ns-zalohy-list');
  if (!el) return;
  el.innerHTML = '⏳ Načítám…';
  try {
    const d = await api('admin_zalohy.php?action=list') || {};
    // 🐛 v3.0.66 — defensive: API může vrátit chybu nebo prázdný objekt
    if (!Array.isArray(d.zalohy)) d.zalohy = [];
    if (typeof d.pocet !== 'number') d.pocet = d.zalohy.length;
    if (typeof d.celkova_velikost !== 'number') d.celkova_velikost = 0;
    const overall = document.getElementById('ns-zalohy-overall');
    if (overall) {
      overall.textContent = d.pocet === 0
        ? '— zatím žádné zálohy —'
        : `${d.pocet} záloh · celkem ${_zalohaVel(d.celkova_velikost)}`;
    }
    if (d.zalohy.length === 0) {
      el.innerHTML = `
        <div style="padding:20px;text-align:center;background:#F7F8FA;border:1px dashed #E8C988;border-radius:8px;color:#92400e">
          ⚠ Zatím nemáš žádnou zálohu. <strong>Doporučujeme udělat hned první.</strong>
        </div>`;
      return;
    }
    // 🆕 v3.0.283 — defaultně jen 5 nejnovějších, zbytek na kliknutí
    const LIMIT = 5;
    const zobraz = showAll ? d.zalohy : d.zalohy.slice(0, LIMIT);
    const skryto = d.zalohy.length - zobraz.length;
    el.innerHTML = `
      <table class="table zalohy-table" style="font-size:12px">
        <thead>
          <tr>
            <th>Vytvořeno</th><th class="zaloha-col-hide">Typ</th><th>Popis</th>
            <th class="num zaloha-col-hide">Tabulek</th><th class="num zaloha-col-hide">Záznamů</th>
            <th class="num zaloha-col-hide">Velikost</th><th class="zaloha-col-hide">Uploads</th><th></th>
          </tr>
        </thead>
        <tbody>
          ${zobraz.map(z => `
            <tr>
              <td class="zaloha-cell-when">
                <strong>${esc(z.vytvoreno?.substring(0, 16).replace('T', ' ') || '?')}</strong>
                <div class="zaloha-meta">${esc(z.typ || 'manual')} · ${_zalohaVel(z.velikost)}${parseInt(z.include_uploads) ? ' · +uploads' : ' · jen DB'}</div>
                ${z.vytvoril ? `<div class="zaloha-vytvoril">${esc(z.vytvoril)}</div>` : ''}
              </td>
              <td class="zaloha-col-hide">${_zalohaTypBadge(z.typ)}</td>
              <td>${esc(z.label || '—')}</td>
              <td class="num zaloha-col-hide">${z.tabulek}</td>
              <td class="num zaloha-col-hide">${z.zaznamu?.toLocaleString('cs-CZ') || '—'}</td>
              <td class="num zaloha-col-hide">${_zalohaVel(z.velikost)}</td>
              <td class="zaloha-col-hide">${parseInt(z.include_uploads) ? '✓' : '—'}</td>
              <td class="zaloha-actions">
                <a href="../api/admin_zalohy.php?action=download&id=${z.id}" class="btn-secondary zaloha-btn" data-icon="⬇" title="Stáhnout ZIP">⬇ Stáhnout</a>
                <button class="btn-secondary zaloha-btn" data-icon="↺" onclick="zalohaObnov(${z.id}, '${esc(z.vytvoreno?.substring(0,16).replace('T',' ') || '')}')" title="Obnovit DB z této zálohy">↺ Obnovit</button>
                <button class="btn-secondary zaloha-btn zaloha-btn-del" data-icon="✕" onclick="zalohaSmazat(${z.id})" title="Smazat">✕ Smazat</button>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
      ${skryto > 0 ? `
        <div style="text-align:center;padding:8px 0 2px">
          <button class="btn-secondary" style="font-size:12px;padding:6px 14px" onclick="zalohyRefresh(true)">Zobrazit všech ${d.zalohy.length} záloh ▾</button>
        </div>` : (showAll && d.zalohy.length > LIMIT ? `
        <div style="text-align:center;padding:8px 0 2px">
          <button class="btn-secondary" style="font-size:12px;padding:6px 14px" onclick="zalohyRefresh(false)">Zobrazit jen posledních ${LIMIT} ▴</button>
        </div>` : '')}
    `;
  } catch (e) {
    el.innerHTML = `<div style="color:var(--danger-text);padding:14px;background:var(--danger-bg);border-radius:6px">Chyba: ${esc(e.message)}</div>`;
  }
};

window.zalohaVytvorit = async function(includeUploads) {
  const label = await promptDialog({
    title: 'Vytvořit zálohu',
    msg: includeUploads
      ? 'Popis zálohy (volitelně). 📦 Zahrnu i složku /uploads (loga, fotky výrobků, podpisy) — záloha bude větší.'
      : 'Popis zálohy (volitelně, např. „před změnou cen").',
    placeholder: 'Popis zálohy…', okText: 'Vytvořit zálohu', icon: '💾',
  });
  if (label === null) return; // zrušeno

  const btns = document.querySelectorAll('#ns-zalohy-block button');
  btns.forEach(b => b.disabled = true);
  document.getElementById('ns-zalohy-list').innerHTML = '⏳ Vytvářím zálohu… (může trvat 10-30 s pro velkou DB)';

  try {
    const r = await api('admin_zalohy.php?action=create', {
      method: 'POST',
      body: JSON.stringify({ typ: 'manual', label: label || null, include_uploads: includeUploads }),
    });
    alert(t('backup_created', {
      soubor: r.soubor,
      velikost: _zalohaVel(r.velikost),
      tabulek: r.tabulek,
      zaznamu: r.zaznamu.toLocaleString('cs-CZ'),
      uploads_text: r.uploads ? t('uploads_count_line', { n: r.uploads }) : '',
    }));
    zalohyRefresh();
  } catch (e) {
    alert('Vytvoření zálohy selhalo: ' + e.message);
  } finally {
    btns.forEach(b => b.disabled = false);
  }
};

window.zalohaSmazat = async function(id) {
  if (!await confirmDelete2x({ co: 'tuto zálohu', detail: 'Stažený ZIP soubor zůstane u tebe, ale ze serveru zmizí.' })) return;
  try {
    await api('admin_zalohy.php?id=' + id, { method: 'DELETE' });
    zalohyRefresh();
  } catch (e) { alert('Smazání selhalo: ' + e.message); }
};

window.zalohaObnov = async function(id, datum) {
  const msg = `⚠️ POZOR — Obnovit databázi ze zálohy z ${datum}?\n\n` +
    `• Všechna aktuální data se PŘEPÍŠOU daty ze zálohy.\n` +
    `• Změny po datu této zálohy budou ZTRACENY.\n` +
    `• Před obnovou se automaticky udělá snapshot stávajícího stavu (pro případ že obnova selže).\n\n` +
    `Pokud rozumíš a chceš pokračovat, napiš velkými písmeny:  OBNOVIT`;
  const reply = await promptDialog({ title: '⚠️ Obnova zálohy', msg, placeholder: 'napiš: OBNOVIT', okText: 'Obnovit', icon: '⚠️' });
  if (reply !== 'OBNOVIT') {
    if (reply !== null) alert('Obnova zrušena (musíš napsat přesně OBNOVIT velkými písmeny).');
    return;
  }
  try {
    const r = await api('admin_zalohy.php?action=restore&id=' + id, {
      method: 'POST',
      body: JSON.stringify({ potvrzeni: 'OBNOVIT' }),
    });
    if (r.ok) {
      alert(t('restore_success', { n: r.provedeno, err: r.chyb }));
      location.reload();
    } else {
      alert(t('restore_with_errors', { ok: r.provedeno, err: r.chyb, errors: r.errors.slice(0, 3).join('\n') }));
      zalohyRefresh();
    }
  } catch (e) {
    alert('Obnova selhala: ' + e.message);
  }
};

window.zalohaInfo = async function() {
  try {
    const r = await api('admin_zalohy.php?action=info');
    const last = r.posledni
      ? `${r.posledni.vytvoreno?.substring(0, 16).replace('T', ' ')} (${r.posledni.typ})`
      : '— žádná zatím —';
    const diskUsedPct = r.disk_total > 0 ? (100 - (r.disk_free / r.disk_total * 100)).toFixed(1) : '?';
    openModal('ℹ️ Info & Automatické zálohy (CRON)', `
      <div class="card-block" style="padding:14px;margin-bottom:14px;background:#F7F8FA;border:1px solid #E8D5B0">
        <h3 style="font-size:14px;margin-bottom:10px">📊 Statistika</h3>
        <table style="width:100%;font-size:13px">
          <tr><td style="color:var(--text-3);padding:3px 0">Počet záloh</td><td style="text-align:right"><strong>${r.pocet}</strong></td></tr>
          <tr><td style="color:var(--text-3);padding:3px 0">Celková velikost</td><td style="text-align:right"><strong>${_zalohaVel(r.celkova_velikost)}</strong></td></tr>
          <tr><td style="color:var(--text-3);padding:3px 0">Volné místo na disku</td><td style="text-align:right"><strong>${_zalohaVel(r.disk_free)}</strong> (využito ${diskUsedPct} %)</td></tr>
          <tr><td style="color:var(--text-3);padding:3px 0">Poslední záloha</td><td style="text-align:right"><strong>${esc(last)}</strong></td></tr>
        </table>
      </div>

      <div class="card-block" style="padding:14px;margin-bottom:14px">
        <h3 style="font-size:14px;margin-bottom:8px">🕐 Automatická záloha (CRON)</h3>
        <p style="font-size:12px;color:var(--text-2);line-height:1.6;margin-bottom:10px">
          Nastav si na Hostingeru CRON úlohu, která bude spouštět denní automatickou zálohu. Doporučuju <strong>3:00 v noci</strong> (kdy nikdo nepracuje).
        </p>
        <p style="font-size:12px;color:var(--text-2);margin-bottom:6px"><strong>Hostinger panel → Pokročilé → Cron úlohy → Vytvořit novou úlohu:</strong></p>
        <ul style="font-size:12px;color:var(--text-2);margin-bottom:10px;padding-left:20px;line-height:1.6">
          <li><strong>Příkaz:</strong> <code>wget -q -O /dev/null "URL NÍŽE"</code></li>
          <li><strong>Spouštět:</strong> Denně ve 3:00 (nebo „0 3 * * *")</li>
        </ul>

        <label class="form-label">CRON URL (citlivá — neukazuj nikomu):</label>
        <input class="form-input" id="zaloha-cron-url" type="text" readonly value="${esc(r.cron_url)}" onclick="this.select()" style="font-family:monospace;font-size:11px">
        <button class="btn-secondary" style="margin-top:6px;font-size:12px;padding:6px 12px" onclick="navigator.clipboard.writeText(document.getElementById('zaloha-cron-url').value).then(() => alert('✓ Zkopírováno'))">📋 Kopírovat URL</button>

        <p style="font-size:11px;color:var(--text-3);margin-top:10px">
          Po zapnutí CRONu bude server každou noc vytvořit zálohu DB (jen tabulky, bez uploads). Staré se automaticky rotují (denní 30, měsíční 12).
        </p>
      </div>

      <div class="card-block" style="padding:14px;background:#FEF3C7;border:1px solid #FCD34D">
        <h3 style="font-size:14px;margin-bottom:8px;color:#92400e">🔄 Rotace záloh</h3>
        <p style="font-size:12px;color:#854F0B;line-height:1.6;margin-bottom:0">
          Automaticky se po každé záloze projde historie a vyhodí starší než:<br>
          • <strong>Denní</strong>: posledních 30<br>
          • <strong>Měsíční</strong>: posledních 12<br>
          • <strong>Snapshoty</strong>: posledních 50
        </p>
      </div>

      <div class="form-actions">
        <div style="flex:1"></div>
        <!-- "Zavřít" smazáno v v2.5.11 — × v rohu modal-card stačí (větší klikací plocha) -->
      </div>
    `);
  } catch (e) { alert('Info selhala: ' + e.message); }
};

