// ===================================================================
// DIAGNOSTIKA — system info, DB, schéma, endpointy, kolize, logy
// ===================================================================
async function renderDiagnostika() {
  const c = document.getElementById('content');
  c.innerHTML = `
    <div class="page-head">
      <div>
        <h1 class="page-title">🩺 Diagnostika</h1>
        <p class="page-sub">Stav serveru, databáze a aplikace</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-back" onclick="navigate('nastaveni');setTimeout(()=>{state._nastaveniTab='udrzba';renderNastaveni();},100)" title="Zpět na Údržba tab"><span class="btn-back-arrow">←</span> <span class="btn-back-lbl">Údržba</span></button>
        <button class="btn-secondary" onclick="diagCopy()" title="Zkopírovat všechna data do schránky (pro support)">📋 Kopírovat info</button>
        <button class="btn-secondary" onclick="diagLint()" title="Projeď všechny PHP soubory v api/ — najdi parse errors">🔬 Lint API</button>
        <button class="btn-secondary" onclick="diagPingMail()" title="Pošle testovací e-mail na firma_email">✉️ Test e-mailu</button>
        <button class="btn-primary btn-green" onclick="renderDiagnostika()" title="Načíst znovu">🔄 Obnovit</button>
      </div>
    </div>
    <div id="diag-body" class="empty-state">⏳ Načítám diagnostiku…</div>
  `;

  let d;
  try {
    d = await api('admin_diagnostika.php');
    state._diagRaw = d;
  } catch (e) {
    document.getElementById('diag-body').innerHTML =
      `<div style="padding:20px;background:var(--danger-bg);color:var(--danger-text);border-radius:8px">
        ✗ Načtení diagnostiky selhalo: ${esc(e.message)}
      </div>`;
    return;
  }

  const sys = d.system, db = d.database, sch = d.schema, fs = d.filesystem,
        disk = d.disk, ep = d.endpoints, col = d.collisions, logs = d.logs,
        set = d.settings, cnt = d.counts;

  // helper: barevný status badge
  const ok = (txt = 'OK') => `<span style="background:#DCFCE7;color:#166534;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600">✓ ${txt}</span>`;
  const warn = (txt) => `<span style="background:#FEF3C7;color:#92400e;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600">⚠ ${txt}</span>`;
  const err = (txt) => `<span style="background:#FEE2E2;color:#991B1B;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600">✗ ${txt}</span>`;

  // 🏥 Souhrn na vrchol — co je v pořádku, co rozbité
  const totalEpMissing = (ep || []).filter(e => !e.exists).length;
  const overall = [];
  if (db.connected) overall.push(ok('DB připojena'));
  else overall.push(err('DB nepřipojena'));
  overall.push(sys.mail_function === 'available' ? ok('mail()') : err('mail() nedostupný'));
  if (sch.issues.length === 0) overall.push(ok(`Schéma (${sch.ok.length} sloupců)`));
  else overall.push(warn(`${sch.issues.length} chybějících sloupců`));
  if (totalEpMissing === 0) overall.push(ok(`Endpointy (${ep.length})`));
  else overall.push(err(`${totalEpMissing} chybějících endpointů`));
  if (!col.length || col.error) overall.push(ok('Bez kolizí funkcí'));
  else overall.push(err(`${col.length} kolizí funkcí`));
  if (sys.missing_extensions.length === 0) overall.push(ok('PHP extensions'));
  else overall.push(warn(`Chybí: ${sys.missing_extensions.join(', ')}`));

  // helper: tabulka klíč→hodnota
  const kvTable = (obj, opts = {}) => {
    const rows = Object.entries(obj || {})
      .filter(([k]) => !k.startsWith('_'))
      .map(([k, v]) => {
        let val;
        if (Array.isArray(v)) val = v.length === 0 ? '<i style="color:var(--text-3)">—</i>' : esc(v.join(', '));
        else if (v === null || v === undefined || v === '') val = '<i style="color:var(--text-3)">—</i>';
        else val = esc(String(v));
        return `<tr><td style="color:var(--text-3);width:40%;padding:4px 8px 4px 0">${esc(k)}</td><td style="padding:4px 0;font-family:${opts.mono ? 'monospace' : 'inherit'};font-size:13px">${val}</td></tr>`;
      }).join('');
    return `<table style="width:100%;font-size:13px;border-collapse:collapse">${rows}</table>`;
  };

  document.getElementById('diag-body').innerHTML = `
    <!-- 🏥 Hlavní stav nahoře -->
    <div class="card-block" style="padding:16px;margin-bottom:14px">
      <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:13px">
        <strong style="margin-right:8px">Stav systému:</strong>
        ${overall.join(' ')}
      </div>
      <div style="margin-top:8px;font-size:11px;color:var(--text-3)">
        Generováno: ${esc(d.generated)} · Server čas: ${esc(sys.server_time)}
      </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px">

      <!-- 🖥️ SYSTÉM -->
      <div class="card-block" style="padding:16px">
        <h3 style="font-size:14px;margin:0 0 10px;color:var(--text-2)">🖥️ Systém / PHP</h3>
        ${kvTable({
          'PHP verze': sys.php_version,
          'SAPI': sys.php_sapi,
          'OS': sys.os,
          'Server': sys.server_software,
          'Host': sys.host,
          'Časové pásmo': sys.timezone,
          'Memory limit': sys.memory_limit,
          'Memory peak': sys.memory_peak,
          'Max execution': sys.max_execution_time,
          'Upload max': sys.upload_max_filesize,
          'Display errors': sys.display_errors,
          'Log errors': sys.log_errors,
          'mail()': sys.mail_function,
          'Načtené extensions': sys.extensions,
          'Chybějící extensions': sys.missing_extensions,
        })}
      </div>

      <!-- 💾 DATABÁZE -->
      <div class="card-block" style="padding:16px">
        <h3 style="font-size:14px;margin:0 0 10px;color:var(--text-2)">💾 Databáze</h3>
        ${db.connected ? kvTable({
          'Status': '✓ Připojeno',
          'Verze MySQL/MariaDB': db.version,
          'Název databáze': db.name,
          'Počet tabulek': db.table_count,
          'Záznamů celkem (odhad)': db.total_rows,
          'Velikost dat': db.total_size,
        }) : `<div style="color:var(--danger-text)">✗ ${esc(db.error || 'neznámá chyba')}</div>`}

        ${db.connected && db.tables ? `
          <details style="margin-top:10px">
            <summary style="cursor:pointer;font-size:12px;color:var(--text-3)">Tabulky (${db.tables.length})</summary>
            <table class="table" style="font-size:11px;margin-top:6px">
              <thead><tr><th>Tabulka</th><th class="num">Záznamů</th><th class="num">Velikost</th></tr></thead>
              <tbody>
                ${db.tables.map(t => `<tr><td style="font-family:monospace">${esc(t.name)}</td><td class="num">${t.rows.toLocaleString('cs-CZ')}</td><td class="num">${esc(t.size)}</td></tr>`).join('')}
              </tbody>
            </table>
          </details>
        ` : ''}
      </div>

      <!-- 🗂️ SCHÉMA -->
      <div class="card-block" style="padding:16px">
        <h3 style="font-size:14px;margin:0 0 10px;color:var(--text-2)">🗂️ Schéma — kontrola kritických sloupců</h3>
        ${sch.issues.length === 0
          ? `<div style="padding:8px 12px;background:#DCFCE7;color:#166534;border-radius:6px;font-size:12px">✓ Všech ${sch.ok.length} kritických sloupců existuje</div>`
          : `<div style="padding:8px 12px;background:#FEE2E2;color:#991B1B;border-radius:6px;font-size:12px;margin-bottom:8px">
              <strong>⚠ Chybí ${sch.issues.length} sloupců/tabulek:</strong>
              <ul style="margin:6px 0 0 18px;padding:0">${sch.issues.map(i => `<li>${esc(i)}</li>`).join('')}</ul>
              <div style="margin-top:6px;font-size:11px;color:#92400e">Většina se automaticky doplní při návštěvě odpovídající stránky (auto-migrace).</div>
            </div>`}
        ${sch.ok.length > 0 ? `
          <details style="margin-top:6px">
            <summary style="cursor:pointer;font-size:11px;color:var(--text-3)">OK sloupce (${sch.ok.length})</summary>
            <div style="font-family:monospace;font-size:11px;color:var(--text-3);margin-top:4px;line-height:1.6">${sch.ok.map(esc).join(', ')}</div>
          </details>
        ` : ''}
      </div>

      <!-- 📁 SOUBORY -->
      <div class="card-block" style="padding:16px">
        <h3 style="font-size:14px;margin:0 0 10px;color:var(--text-2)">📁 Soubory / disk</h3>
        ${kvTable({
          'Volné místo': disk.free,
          'Celkem': disk.total,
          'Využito': disk.used_pct !== null ? disk.used_pct + ' %' : '?',
        })}
        <table class="table" style="font-size:11px;margin-top:8px">
          <thead><tr><th>Složka</th><th>Stav</th><th class="num">Souborů</th><th class="num">Velikost</th></tr></thead>
          <tbody>
            ${fs.map(d => `
              <tr>
                <td style="font-family:monospace">${esc(d.path)}</td>
                <td>${d.exists ? (d.writable ? ok('writable') : warn('read-only')) : err('chybí')}</td>
                <td class="num">${d.files ?? '—'}</td>
                <td class="num">${d.size ?? '—'}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>

      <!-- 📈 POČTY ZÁZNAMŮ -->
      <div class="card-block" style="padding:16px">
        <h3 style="font-size:14px;margin:0 0 10px;color:var(--text-2)">📈 Počty záznamů</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px">
          ${Object.entries(cnt).map(([k, v]) => `
            <div style="padding:8px 10px;background:var(--surface-2);border-radius:6px">
              <div style="color:var(--text-3);font-size:11px">${esc(k)}</div>
              <div style="font-size:18px;font-weight:700;color:#854F0B">${v === null ? '—' : v.toLocaleString('cs-CZ')}</div>
            </div>
          `).join('')}
        </div>
      </div>

      <!-- 🔌 ENDPOINTY -->
      <div class="card-block" style="padding:16px">
        <h3 style="font-size:14px;margin:0 0 10px;color:var(--text-2)">🔌 API endpointy (${ep.length})</h3>
        <div style="font-size:12px;color:var(--text-3);margin-bottom:6px">Existence souborů, velikost a poslední změna.</div>
        <div style="overflow:auto;max-height:480px;border:1px solid var(--border);border-radius:8px">
          <table class="table" style="font-size:11px;margin:0;table-layout:fixed;width:100%">
            <colgroup>
              <col style="width:auto">
              <col style="width:70px">
              <col style="width:90px">
              <col style="width:140px">
            </colgroup>
            <thead style="position:sticky;top:0;background:var(--surface-2);z-index:1">
              <tr><th>Soubor</th><th>Stav</th><th class="num">Velikost</th><th>Změna</th></tr>
            </thead>
            <tbody>
              ${ep.map(e => `
                <tr style="${e.exists ? '' : 'background:#FEE2E2'}">
                  <td style="font-family:monospace;font-size:10.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(e.file)}">${esc(e.file)}</td>
                  <td>${e.exists ? ok() : err('chybí')}</td>
                  <td class="num">${e.size ?? '—'}</td>
                  <td style="font-size:10.5px;color:var(--text-3);white-space:nowrap">${e.modified ?? '—'}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      </div>

      <!-- ⚠️ KOLIZE FUNKCÍ -->
      <div class="card-block" style="padding:16px">
        <h3 style="font-size:14px;margin:0 0 10px;color:var(--text-2)">⚠️ Kolize názvů funkcí</h3>
        ${col.error
          ? `<div style="color:var(--danger-text)">Chyba: ${esc(col.error)}</div>`
          : (col.length === 0
            ? `<div style="padding:8px 12px;background:#DCFCE7;color:#166534;border-radius:6px;font-size:12px">✓ Bez duplicitních deklarací funkcí</div>`
            : `<div style="padding:8px 12px;background:#FEE2E2;color:#991B1B;border-radius:6px;font-size:12px;margin-bottom:8px">
                <strong>⚠ Nalezeno ${col.length} kolizí — RUNTIME fatal error!</strong>
              </div>
              <table class="table" style="font-size:11px">
                <thead><tr><th>Funkce</th><th>Soubory</th></tr></thead>
                <tbody>
                  ${col.map(c => `<tr><td style="font-family:monospace"><strong>${esc(c.function)}()</strong></td><td>${c.files.map(esc).join(', ')}</td></tr>`).join('')}
                </tbody>
              </table>`)}
      </div>

      <!-- 🏢 NASTAVENÍ FIRMY -->
      <div class="card-block" style="padding:16px">
        <h3 style="font-size:14px;margin:0 0 10px;color:var(--text-2)">🏢 Nastavení firmy</h3>
        ${kvTable(set, { mono: true })}
      </div>

      <!-- 🚨 JS CHYBY Z PROHLÍŽEČŮ -->
      <div class="card-block" style="padding:16px;grid-column:1/-1">
        <h3 style="font-size:14px;margin:0 0 10px;color:var(--text-2);display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          🚨 JS chyby z prohlížečů
          ${d.js_errors?.stats ? `
            <span style="font-size:11px;font-weight:normal;color:var(--text-3)">
              za posledních: 1h <strong style="color:${d.js_errors.stats['1h']>0?'#B91C1C':'#166534'}">${d.js_errors.stats['1h']}</strong> ·
              24h <strong style="color:${d.js_errors.stats['24h']>0?'#B0540B':'#166534'}">${d.js_errors.stats['24h']}</strong> ·
              7d <strong>${d.js_errors.stats['7d']}</strong> ·
              celkem <strong>${d.js_errors.stats.celkem}</strong>
            </span>
          ` : ''}
        </h3>
        ${(d.js_errors?.rows || []).length === 0
          ? `<div style="color:#166534;font-size:12px;padding:8px 12px;background:#DCFCE7;border-radius:6px">✓ Žádné JS chyby nezachyceny — vše v pořádku!</div>`
          : `
            ${d.js_errors.top && d.js_errors.top.length > 0 ? `
              <details style="margin-bottom:10px">
                <summary style="cursor:pointer;font-size:12px;color:#B0540B"><strong>🔥 Top 5 nejčastějších (7 dní)</strong></summary>
                <table class="table" style="font-size:11px;margin-top:6px">
                  <thead><tr><th>Zpráva</th><th class="num">Výskytů</th><th>Naposledy</th></tr></thead>
                  <tbody>
                    ${d.js_errors.top.map(t => `
                      <tr>
                        <td style="font-family:monospace;max-width:500px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(t.msg)}</td>
                        <td class="num"><strong>${t.pocet}</strong></td>
                        <td>${esc(t.posledni || '—')}</td>
                      </tr>
                    `).join('')}
                  </tbody>
                </table>
              </details>
            ` : ''}
            <table class="table" style="font-size:11px">
              <thead><tr><th>Kdy</th><th>App</th><th>Chyba</th><th>Soubor:řádek</th><th>Uživatel</th></tr></thead>
              <tbody>
                ${d.js_errors.rows.slice(0, 15).map(r => `
                  <tr>
                    <td style="white-space:nowrap">${esc(r.kdy?.replace('T', ' ').substring(0, 16) || '?')}</td>
                    <td>${r.app === 'admin' ? '🛠️ admin' : '🛒 frontend'}</td>
                    <td style="font-family:monospace;max-width:380px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#B91C1C" title="${esc(r.msg)}">${esc(r.msg)}</td>
                    <td style="font-family:monospace;font-size:10px;color:var(--text-3)">${esc(r.source ? r.source.split('/').pop() : '—')}${r.line ? ':' + r.line : ''}${r.col ? ':' + r.col : ''}</td>
                    <td style="font-size:11px;color:var(--text-3)">${esc(r.user_info || '—')}</td>
                  </tr>
                `).join('')}
              </tbody>
            </table>
            ${d.js_errors.rows.length > 15 ? `<div style="font-size:11px;color:var(--text-3);margin-top:6px">… a dalších ${d.js_errors.rows.length - 15} chyb (klikni „Kopírovat info" pro plný export)</div>` : ''}
          `}
      </div>

      <!-- 🩺 LIVE HEALTH CHECK — endpointy odpovídají? -->
      <div class="card-block" style="padding:16px">
        <h3 style="font-size:14px;margin:0 0 10px;color:var(--text-2)">🩺 Live health check</h3>
        <table class="table" style="font-size:12px">
          <thead><tr><th>Endpoint</th><th>Stav</th><th class="num">HTTP</th><th class="num">Latence</th></tr></thead>
          <tbody>
            ${(d.live_check || []).map(c => `
              <tr>
                <td>${esc(c.label)}<br><small style="color:var(--text-3);font-family:monospace">${esc(c.url)}</small></td>
                <td>${c.ok ? ok() : err('selhal')}</td>
                <td class="num"><strong>${c.http}</strong></td>
                <td class="num">${c.ms} ms</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>

      <!-- 🔑 AUTH LOG — posledních 20 přihlášení -->
      <div class="card-block" style="padding:16px">
        <h3 style="font-size:14px;margin:0 0 10px;color:var(--text-2);display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          🔑 Přihlašování
          ${d.auth_log?.stats ? `
            <span style="font-size:11px;font-weight:normal;color:var(--text-3)">
              24h: ✓ <strong style="color:#166534">${d.auth_log.stats.uspesnych_24h}</strong> ·
              ✗ <strong style="color:${d.auth_log.stats.neuspesnych_24h>0?'#B91C1C':'inherit'}">${d.auth_log.stats.neuspesnych_24h}</strong>
            </span>
          ` : ''}
        </h3>
        ${(d.auth_log?.rows || []).length === 0
          ? `<div style="color:var(--text-3);font-size:12px">Žádné záznamy.</div>`
          : `
            <table class="table" style="font-size:11px">
              <thead><tr><th>Kdy</th><th>Email</th><th>Typ</th><th>IP</th><th>Stav</th></tr></thead>
              <tbody>
                ${d.auth_log.rows.map(r => `
                  <tr ${!parseInt(r.uspesny) ? 'style="background:#FEF2F2"' : ''}>
                    <td style="white-space:nowrap">${esc(r.kdy?.replace('T', ' ').substring(0, 16) || '?')}</td>
                    <td style="font-family:monospace;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(r.email || '—')}</td>
                    <td>${esc(r.typ || '—')}</td>
                    <td style="font-family:monospace">${esc(r.ip || '—')}</td>
                    <td>${parseInt(r.uspesny) ? ok('OK') : err('selhal')}</td>
                  </tr>
                `).join('')}
              </tbody>
            </table>
          `}
      </div>

      <!-- 📝 NEDÁVNÉ ZMĚNY SOUBORŮ -->
      <div class="card-block" style="padding:16px">
        <h3 style="font-size:14px;margin:0 0 10px;color:var(--text-2)">📝 Nedávné změny souborů (48h)</h3>
        ${(d.recent_changes || []).length === 0 || d.recent_changes[0]?.error
          ? `<div style="color:var(--text-3);font-size:12px">Žádné změny za poslední 48 hodin.</div>`
          : `
            <table class="table" style="font-size:11px">
              <thead><tr><th>Soubor</th><th class="num">Velikost</th><th>Změna</th></tr></thead>
              <tbody>
                ${d.recent_changes.slice(0, 15).map(f => `
                  <tr>
                    <td style="font-family:monospace">${esc(f.soubor)}</td>
                    <td class="num">${esc(f.velikost)}</td>
                    <td style="color:var(--text-3)">${esc(f.kdy)}</td>
                  </tr>
                `).join('')}
              </tbody>
            </table>
            ${d.recent_changes.length > 15 ? `<div style="font-size:11px;color:var(--text-3);margin-top:6px">… a dalších ${d.recent_changes.length - 15}</div>` : ''}
          `}
      </div>

      <!-- 🐬 MYSQL KONFIGURACE -->
      <div class="card-block" style="padding:16px">
        <h3 style="font-size:14px;margin:0 0 10px;color:var(--text-2)">🐬 MySQL konfigurace</h3>
        ${kvTable(d.mysql_cfg || {}, { mono: true })}
      </div>

      <!-- ⚡ OPCACHE -->
      <div class="card-block" style="padding:16px">
        <h3 style="font-size:14px;margin:0 0 10px;color:var(--text-2)">⚡ OPcache (PHP bytecode cache)</h3>
        ${d.opcache?.enabled === false
          ? `<div style="color:var(--text-3);font-size:12px;padding:8px 12px;background:var(--surface-2);border-radius:6px">OPcache nedostupná: ${esc(d.opcache?.reason || '?')}</div>`
          : kvTable(d.opcache || {})}
      </div>

      <!-- 📊 PERFORMANCE -->
      <div class="card-block" style="padding:16px">
        <h3 style="font-size:14px;margin:0 0 10px;color:var(--text-2)">📊 Výkonové statistiky</h3>
        ${kvTable({
          'MySQL uptime': (d.perf?.uptime_hours || '?') + ' h',
          'Celkem dotazů': (d.perf?.total_queries || 0).toLocaleString('cs-CZ'),
          'Dotazů/sec': d.perf?.queries_per_sec || '?',
          'Pomalých dotazů': d.perf?.slow_queries || 0,
        })}
      </div>

      <!-- 🔍 SESSION INFO -->
      <div class="card-block" style="padding:16px">
        <h3 style="font-size:14px;margin:0 0 10px;color:var(--text-2)">🔍 Aktuální session</h3>
        ${kvTable(d.session_info || {}, { mono: true })}
      </div>

      <!-- 📜 POSLEDNÍ CHYBY Z ERROR LOGU -->
      <div class="card-block" style="padding:16px;grid-column:1/-1">
        <h3 style="font-size:14px;margin:0 0 10px;color:var(--text-2)">📜 Poslední chyby z PHP error logu</h3>
        ${logs.length === 0
          ? `<div style="color:var(--text-3);font-size:12px">Žádný čitelný error log nenalezen.</div>`
          : logs.map(l => `
            <div style="margin-bottom:14px">
              <div style="font-size:11px;color:var(--text-3);font-family:monospace;margin-bottom:4px">${esc(l.path)} (${esc(l.size)})</div>
              ${l.lines.length === 0
                ? '<div style="color:var(--text-3);font-size:12px">— bez relevantních chyb —</div>'
                : `<pre style="background:#0F172A;color:#E2E8F0;padding:10px 12px;border-radius:6px;font-size:11px;line-height:1.5;overflow-x:auto;max-height:280px">${l.lines.map(esc).join('\n')}</pre>`}
            </div>
          `).join('')}
      </div>

    </div>
  `;
}

window.diagCopy = function() {
  const raw = state._diagRaw;
  if (!raw) return alert('Nejdřív načti diagnostiku.');
  const txt = JSON.stringify(raw, null, 2);
  navigator.clipboard.writeText(txt).then(
    () => alert('✓ Diagnostické info zkopírováno do schránky (' + Math.round(txt.length / 1024) + ' kB).'),
    () => { promptDialog({ msg: 'Schránka selhala — zkopíruj manuálně:', value: txt.substring(0, 5000) }); }
  );
};

window.diagLint = async function() {
  try {
    const r = await api('admin_diagnostika.php?action=lint');
    const bad = r.files.filter(f => f.status !== 'ok');
    if (bad.length === 0) {
      alert('✓ Všech ' + r.files.length + ' PHP souborů v /api se v pořádku parsuje (žádné parse errors).');
    } else {
      const list = bad.map(f => `• ${f.file} — ${f.status}${f.msg ? ': ' + f.msg : ''}`).join('\n');
      alert('⚠ Nalezeny parse chyby (' + bad.length + ' z ' + r.files.length + '):\n\n' + list);
    }
  } catch (e) { alert('Lint selhal: ' + e.message); }
};

window.diagPingMail = async function() {
  const to = await promptDialog({ title: 'Testovací e-mail', msg: 'E-mail, kam poslat testovací zprávu (nech prázdné pro firma_email z nastavení).', placeholder: 'jmeno@firma.cz', okText: 'Odeslat test', icon: '📧', inputType: 'email' });
  try {
    const url = 'admin_diagnostika.php?action=ping_mail' + (to ? '&to=' + encodeURIComponent(to) : '');
    const r = await api(url);
    if (r.ok) {
      alert('✓ Mail odeslán\n\nNa: ' + r.to + '\nOd: ' + r.from + '\n\nPokud nedorazí během minuty, zkontroluj spam.');
    } else {
      alert('✗ mail() vrátil false — PHP mail funkce selhala.\n\nNa: ' + r.to + '\nOd: ' + r.from);
    }
  } catch (e) { alert('Test selhal: ' + e.message); }
};

window.loadCislovani = async function() {
  // 🐛 v3.0.294 — async race: když uživatel přepne tab dřív než dorazí API,
  // 'cislovani-container' už není v DOM → guard (jinak TypeError na null.innerHTML).
  if (!document.getElementById('cislovani-container')) return;
  try {
    const data = await api('admin_cislovani.php');
    renderCislovani(data);
  } catch (e) {
    const host = document.getElementById('cislovani-container');
    if (host) host.innerHTML =
      `<div style="color:var(--danger-text);padding:12px;background:var(--danger-bg);border-radius:6px;">
        Chyba při načítání: ${esc(e.message)}
      </div>`;
  }
};

function renderCislovani(data) {
  const c = document.getElementById('cislovani-container');
  if (!c) return;   // 🐛 v3.0.294 — element může zmizet (přepnutý tab) než dorazí async data
  c.innerHTML = `
    <p style="margin-bottom:14px;font-size:13px;color:var(--text-3);">
      Aktuální rok: <strong>${data.rok}</strong>
    </p>
    <div class="cislovani-grid">
      ${data.rady.map((r) => `
        <div class="cislo-rada-card" data-typ="${r.typ}">
          <div class="cislo-rada-head">
            <span class="cislo-rada-icon">${r.ikona}</span>
            <strong>${esc(r.nazev)}</strong>
          </div>
          <div class="cislo-rada-fields">
            <label>
              <span class="form-label">Předčíslí (značka)</span>
              <input class="form-input cislo-predcisli" type="text" maxlength="40"
                     value="${esc(r.predcisli)}" placeholder="např. FA-2026-">
            </label>
            <label>
              <span class="form-label">Počáteční číslo</span>
              <input class="form-input cislo-pocatecni" type="number" min="1" step="1"
                     value="${r.posledni + 1}">
            </label>
          </div>
          <div class="cislo-rada-nahled">
            <span class="form-label" style="font-size:11px;">Příští doklad bude:</span>
            <code class="cislo-priste">${esc(r.priste)}</code>
          </div>
          <div class="cislo-rada-foot">
            <button class="btn-secondary" onclick="ulozitCisloRadu('${r.typ}', ${r.rok})">
              Uložit ${r.nazev.toLowerCase()}
            </button>
            <small style="color:var(--text-3);font-size:11px;">
              ${r.posledni > 0 ? `Posledně vystaveno: ${esc(r.predcisli)}${r.posledni}` : 'Zatím nic nevystaveno'}
            </small>
          </div>
        </div>
      `).join('')}
    </div>
  `;

  // Live náhled při psaní
  document.querySelectorAll('.cislo-rada-card').forEach((card) => {
    const updNahled = () => {
      const pred = card.querySelector('.cislo-predcisli').value;
      const num = parseInt(card.querySelector('.cislo-pocatecni').value) || 1;
      card.querySelector('.cislo-priste').textContent = pred + num;
    };
    card.querySelector('.cislo-predcisli').addEventListener('input', updNahled);
    card.querySelector('.cislo-pocatecni').addEventListener('input', updNahled);
  });
}

window.ulozitCisloRadu = async function(typ, rok) {
  const card = document.querySelector(`.cislo-rada-card[data-typ="${typ}"]`);
  if (!card) return;

  const predcisli = card.querySelector('.cislo-predcisli').value;
  const pocatecni = parseInt(card.querySelector('.cislo-pocatecni').value) || 1;

  if (!(await confirmDialog({
    title: 'Uložit číselnou řadu?',
    msg: `Předčíslí „${predcisli}" · příští doklad ${predcisli}${pocatecni}. Pokud máte vystavené doklady s vyšším číslem, raději neměňte.`,
    okText: 'Uložit řadu', danger: true,
  }))) return;

  try {
    await api('admin_cislovani.php', {
      method: 'PUT',
      body: { typ, rok, predcisli, pocatecni },
    });
    loadCislovani();
    // Vizuální feedback
    card.style.borderColor = 'var(--success-text)';
    setTimeout(() => { card.style.borderColor = ''; }, 1500);
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

