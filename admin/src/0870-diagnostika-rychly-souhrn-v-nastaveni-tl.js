// ===================================================================
// DIAGNOSTIKA — rychlý souhrn v Nastavení + tlačítko pro detailní pohled
// ===================================================================
window.diagRychly = async function() {
  const el = document.getElementById('ns-diag-summary');
  const overall = document.getElementById('ns-diag-overall');
  if (!el) return;
  el.innerHTML = '⏳ Načítám…';

  try {
    const d = (await api('admin_diagnostika.php')) || {};
    state._diagRaw = d;

    // 🐛 v3.0.66 — defensive: API může vrátit prázdné/chybové response. User: "db is undefined"
    const sys = d.system || {};
    const db = d.database || {};
    const sch = d.schema || { issues: [] };
    const ep = d.endpoints || [];
    const col = d.collisions || [];
    if (!Array.isArray(sch.issues)) sch.issues = [];
    if (!Array.isArray(sys.missing_extensions)) sys.missing_extensions = [];
    const epMissing = ep.filter(e => !e.exists).length;
    const items = [
      { ok: !!db.connected, label: 'DB', warnLabel: 'DB selhala' },
      { ok: sys.mail_function === 'available', label: 'mail()', warnLabel: 'mail() nedostupný' },
      { ok: sch.issues.length === 0, label: `schéma`, warnLabel: `${sch.issues.length} chybí`, warn: sch.issues.length > 0 },
      { ok: epMissing === 0, label: `endpointy`, warnLabel: `${epMissing} chybí` },
      { ok: !col.length || col.error, label: `funkce`, warnLabel: `${col.length} kolizí` },
      { ok: sys.missing_extensions.length === 0, label: `extensions`, warnLabel: `chybí: ${sys.missing_extensions.join(',')}`, warn: sys.missing_extensions.length > 0 },
    ];
    const okCount = items.filter(i => i.ok).length;
    if (overall) {
      overall.textContent = okCount === items.length ? '✓ vše OK' : `${okCount}/${items.length} OK`;
      overall.style.color = okCount === items.length ? '#166534' : '#92400e';
    }

    const badge = (i) => {
      if (i.ok) return `<span style="background:#DCFCE7;color:#166534;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:600">✓ ${i.label}</span>`;
      if (i.warn) return `<span style="background:#FEF3C7;color:#92400e;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:600">⚠ ${i.warnLabel}</span>`;
      return `<span style="background:#FEE2E2;color:#991B1B;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:600">✗ ${i.warnLabel}</span>`;
    };

    el.innerHTML = `
      <div style="display:flex;flex-wrap:wrap;gap:5px">${items.map(badge).join('')}</div>
      <div style="margin-top:10px;font-size:11px;color:var(--text-3);line-height:1.6">
        PHP ${esc(sys.php_version)} · ${esc(sys.os)}<br>
        Memory: ${esc(sys.memory_peak)} / ${esc(sys.memory_limit)} · MySQL: ${esc(db.version || '?')}
      </div>
    `;
  } catch (e) {
    el.innerHTML = `<div style="color:var(--danger-text);padding:8px;background:var(--danger-bg);border-radius:6px;font-size:12px">Chyba: ${esc(e.message)}</div>`;
  }
};

// Otevři detail diagnostiky — nahradí obsah stránky tabulkou ze starého renderDiagnostika
window.diagOtevrit = async function() {
  await renderDiagnostika();
  // Skroluj nahoru
  window.scrollTo(0, 0);
};

// 🆕 v2.9.322 — Test zdraví aplikace (synthetic monitor on-demand)
// Spustí healthcheck + monitor → výsledky inline pod Diagnostika kartu.
// Zobrazí monitor_token pro CRON setup.
window.healthRunNow = async function() {
  const out = document.getElementById('ns-health-result');
  if (!out) return;
  out.innerHTML = '<span style="color:var(--text-3)">⏳ Spouštím healthcheck + monitor…</span>';
  try {
    const r = await api('admin_health_monitor.php');
    const ok = r.ok && r.healthcheck && r.healthcheck.ok;
    const checks = (r.healthcheck && r.healthcheck.checks) || [];
    const errs = parseInt(r.new_errors_15min || 0);
    const dur = parseInt(r.duration_ms || 0);

    const badge = (c) => c.ok
      ? `<span style="background:#DCFCE7;color:#166534;padding:2px 7px;border-radius:5px;font-size:11px;font-weight:600" title="${esc(c.detail || '')} (${c.duration_ms||0}ms)">✓ ${esc(c.name)}</span>`
      : `<span style="background:#FEE2E2;color:#991B1B;padding:2px 7px;border-radius:5px;font-size:11px;font-weight:600" title="${esc(c.detail || '')}">✗ ${esc(c.name)}</span>`;

    out.innerHTML = `
      <div style="background:${ok ? '#F0FDF4' : '#FEF3C7'};border:1px solid ${ok ? '#BBF7D0' : '#F59E0B'};border-radius:8px;padding:10px 12px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px">
          <strong style="font-size:13px;color:${ok ? '#166534' : '#854F0B'}">${ok ? '✅ Zdraví OK' : '⚠️ Detekován problém'}</strong>
          <span style="font-size:11px;color:var(--text-3)">trvalo ${dur}ms · errors/15min: ${errs}${r.alerts_emitted ? ' · 🔔 ' + r.alerts_emitted + ' notifikací odesláno' : ''}</span>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:4px">${checks.map(badge).join('')}</div>
        ${r.monitor_token ? `
          <details style="margin-top:10px;font-size:11px;color:var(--text-3)">
            <summary style="cursor:pointer;user-select:none">🔑 CRON setup token</summary>
            <div style="margin-top:6px;padding:6px 8px;background:#FAFAFA;border-radius:4px;font-family:monospace;font-size:11px;word-break:break-all">${esc(r.monitor_token)}</div>
            <div style="margin-top:6px">
              Přidej do Hostinger cron tab (každých 5 min):<br>
              <code style="background:#FAFAFA;padding:4px 6px;border-radius:3px;display:inline-block;margin-top:4px;font-size:10px">*/5 * * * * curl -sS "https://${esc(location.host)}/api/admin_health_monitor.php?token=${esc(r.monitor_token)}" > /dev/null</code>
            </div>
          </details>
        ` : ''}
      </div>
    `;
  } catch (e) {
    out.innerHTML = `<div style="background:#FEE2E2;color:#991B1B;padding:8px 10px;border-radius:6px;font-size:12px">Chyba: ${esc(e.message)}</div>`;
  }
};

// ═══════════════════════════════════════════════════════════
// 🆕 v2.9.321 — APP ERRORS VIEWER (app_errors DB tabulka)
// ═══════════════════════════════════════════════════════════
window.errorsLoad = async function() {
  const list = document.getElementById('ns-errors-list');
  const overall = document.getElementById('ns-errors-overall');
  if (!list) return;
  list.innerHTML = '⏳ Načítám…';

  const q = (document.getElementById('ns-errors-search')?.value || '').trim();
  const sinceH = (document.getElementById('ns-errors-since')?.value || '24');
  const params = new URLSearchParams({ action: 'list', limit: '30', since_h: sinceH });
  if (q) params.set('q', q);

  let stats, rows;
  try {
    [stats, rows] = await Promise.all([
      api('admin_error_log.php?action=stats').catch(() => null),
      api('admin_error_log.php?' + params.toString()),
    ]);
  } catch (e) {
    list.innerHTML = `<div style="color:var(--danger-text);padding:8px;background:var(--danger-bg);border-radius:6px;font-size:12px">Chyba: ${esc(e.message)}</div>`;
    return;
  }

  if (overall && stats?.summary) {
    const s = stats.summary;
    const n1 = parseInt(s.last_1h || 0), n24 = parseInt(s.last_24h || 0), n7 = parseInt(s.last_7d || 0);
    overall.textContent = `1h: ${n1} · 24h: ${n24} · 7d: ${n7}`;
    overall.style.color = n1 > 5 ? '#991B1B' : n24 > 20 ? '#92400e' : '#166534';
  }

  const items = rows?.rows || [];
  if (items.length === 0) {
    list.innerHTML = `<div style="padding:14px;text-align:center;color:var(--text-3);background:#F0FDF4;border:1px dashed #BBF7D0;border-radius:8px;font-size:13px">✓ Žádné chyby v tomto okně (${esc(sinceH)} h). Pokud testuješ konkrétní reqId, vyhledej ho výše.</div>`;
    return;
  }

  list.innerHTML = `
    <div style="font-size:11px;color:var(--text-3);margin-bottom:6px">Posledních ${items.length} z ${rows.total || items.length} chyb${rows.total > items.length ? ' (zobraz více v API)' : ''}</div>
    <div style="display:flex;flex-direction:column;gap:4px;max-height:480px;overflow:auto">
      ${items.map(r => {
        const sevColor = r.severity === 'error' ? '#991B1B' : r.severity === 'warn' ? '#92400e' : '#1E40AF';
        const sevBg = r.severity === 'error' ? '#FEE2E2' : r.severity === 'warn' ? '#FEF3C7' : '#DBEAFE';
        const time = (r.created_at || '').replace('T', ' ').slice(0, 16);
        return `
          <div onclick="errorsDetail('${esc(r.request_id)}')" style="display:grid;grid-template-columns:auto 1fr auto;gap:8px;padding:8px 10px;background:#FAFAFA;border:1px solid var(--border);border-radius:6px;font-size:12px;cursor:pointer;align-items:center" title="Klikni pro detail" onmouseover="this.style.background='#F0F0F0'" onmouseout="this.style.background='#FAFAFA'">
            <span style="background:${sevBg};color:${sevColor};padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase">${esc(r.severity)}</span>
            <div style="min-width:0">
              <div style="font-weight:600;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(r.message)}</div>
              <div style="color:var(--text-3);font-size:11px;margin-top:2px">
                <code style="background:var(--surface-2);padding:1px 4px;border-radius:3px;font-size:10px">${esc(r.request_id)}</code>
                ${r.source ? `· ${esc(r.source)}` : ''}
                ${r.user_email ? `· ${esc(r.user_email)}` : ''}
                · HTTP ${parseInt(r.http_status || 500)}
              </div>
            </div>
            <span style="color:var(--text-3);font-size:11px;white-space:nowrap;font-variant-numeric:tabular-nums">${esc(time)}</span>
          </div>
        `;
      }).join('')}
    </div>
  `;
};

window.errorsDetail = async function(reqId) {
  if (!reqId) return;
  let d;
  try { d = await api('admin_error_log.php?action=detail&request_id=' + encodeURIComponent(reqId)); }
  catch (e) { alert('Chyba načtení detailu: ' + e.message); return; }
  const r = d?.rows?.[0];
  if (!r) { alert('Detail nenalezen'); return; }

  openModal('🐛 Detail chyby ' + reqId, /* body: */ `
    <div style="display:grid;grid-template-columns:auto 1fr;gap:8px 14px;font-size:13px;margin-bottom:14px">
      <strong>Kdy:</strong>           <span>${esc((r.created_at || '').replace('T', ' '))}</span>
      <strong>Severity:</strong>      <span style="text-transform:uppercase;font-weight:700;color:${r.severity === 'error' ? '#991B1B' : '#92400e'}">${esc(r.severity)}</span>
      <strong>HTTP:</strong>          <span>${parseInt(r.http_status || 500)}</span>
      <strong>Zpráva (public):</strong> <span>${esc(r.message)}</span>
      ${r.source ? `<strong>Source:</strong> <span><code>${esc(r.source)}</code></span>` : ''}
      ${r.url    ? `<strong>URL:</strong>    <span style="word-break:break-all"><code>${esc(r.url)}</code></span>` : ''}
      ${r.method ? `<strong>Metoda:</strong> <span>${esc(r.method)}</span>` : ''}
      ${r.user_email ? `<strong>User:</strong> <span>${esc(r.user_email)} (${esc(r.user_role || '?')})</span>` : ''}
      ${r.ip ? `<strong>IP:</strong> <span><code>${esc(r.ip)}</code></span>` : ''}
    </div>
    ${r.exception_class ? `
      <div style="background:#FEF3C7;border-left:3px solid #F59E0B;padding:10px 12px;border-radius:6px;margin-bottom:14px;font-size:13px">
        <div style="font-weight:700;color:#854F0B;margin-bottom:4px">⚠️ Exception</div>
        <div><strong>${esc(r.exception_class)}:</strong> ${esc(r.exception_msg || '')}</div>
        ${r.exception_file ? `<div style="font-size:11px;color:#854F0B;margin-top:4px"><code>${esc(r.exception_file)}:${parseInt(r.exception_line || 0)}</code></div>` : ''}
      </div>
    ` : ''}
    ${r.exception_trace ? `
      <details>
        <summary style="cursor:pointer;font-size:12px;font-weight:600;color:var(--text-2);user-select:none">📜 Stack trace</summary>
        <pre style="background:#1d1d1f;color:#9097a3;padding:12px;border-radius:6px;font-size:11px;line-height:1.5;overflow-x:auto;margin-top:8px;max-height:400px">${esc(r.exception_trace)}</pre>
      </details>
    ` : ''}
  `, 'lg');
};

// ═══════════════════════════════════════════════════════════
// 🔑 LICENCE & UPDATE-CHECKER UI
// ═══════════════════════════════════════════════════════════

window.licenseLoadStatus = async function() {
  const host  = document.getElementById('ns-license-info');
  const badge = document.getElementById('ns-license-badge');
  if (!host) return;
  host.innerHTML = '⏳ Načítám…';

  try {
    const r = await api('admin_version_check.php');
    const lic = r.license || {};
    const ver = r.version || {};

    if (badge) {
      if (lic.ok) {
        badge.textContent = '✅ Aktivní';
        badge.style.background = 'var(--success-bg)';
        badge.style.color = 'var(--success-text)';
      } else {
        badge.textContent = lic.reason === 'no_key' ? '⚠️ Chybí klíč' : '❌ Neplatná';
        badge.style.background = 'var(--danger-bg)';
        badge.style.color = 'var(--danger-text)';
      }
    }

    const updateAvailable = ver.update_available;
    // 🆕 v2.0.74 — Apple-style čistá one-click update karta:
    //   • Žádný ZIP backup link (zbytečný — vlastní backup je v api/zalohy/)
    //   • Žádný inline changelog (rozlišovat "co" zákazník nemusí — píšeme obecně)
    //   • Jen 1 jasné tlačítko + popis obecných změn
    const updateBlock = !updateAvailable ? `
      <div style="background:var(--surface-2);padding:10px 12px;border-radius:8px;font-size:12px;color:var(--text-3)">
        ✅ Máš nejnovější verzi <strong>${esc(ver.current || '?')}</strong>.
      </div>` : `
      <div style="background:linear-gradient(135deg,#FFF8E7,#FEF3C7);border:1.5px solid #FBBF24;border-radius:12px;padding:16px 18px;font-size:13px;color:#854F0B">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
          <div style="font-size:32px;line-height:1">🚀</div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:800;font-size:16px;color:#1d1d1f">Dostupná aktualizace ${esc(ver.latest || '?')}</div>
            <div style="font-size:12.5px;color:#854F0B;margin-top:3px;line-height:1.5">
              Aktuální verze <strong>${esc(ver.current || '?')}</strong> · Nové funkce, opravy chyb, vylepšení rychlosti a bezpečnosti.
            </div>
          </div>
        </div>
        <div style="background:#fff;border-radius:8px;padding:10px 12px;margin-bottom:12px;font-size:12px;line-height:1.6;color:var(--text-2)">
          <strong style="color:#1d1d1f">Co se stane po kliku:</strong>
          <ol style="margin:6px 0 0;padding-left:20px;color:var(--text-2)">
            <li>Vytvoří se automatická záloha do <code>api/zalohy/</code></li>
            <li>Stáhnou se a aplikují aktualizované soubory</li>
            <li>Konfigurace, data, faktury — vše zůstává nedotčeno</li>
            <li>Stránka se obnoví, hotovo</li>
          </ol>
        </div>
        <button id="ns-self-update-btn" onclick="runSelfUpdate('${esc(ver.latest || '')}', '${esc(ver.download_url || '')}', '${esc(ver.checksum_sha256 || '')}')" class="btn-primary btn-green" style="font-weight:700;padding:12px 22px;font-size:14px;border:none;border-radius:10px;cursor:pointer;width:100%;display:flex;align-items:center;justify-content:center;gap:8px">
          ⚡ Aktualizovat na ${esc(ver.latest || '?')}
        </button>
        <div id="ns-self-update-log" style="display:none;margin-top:12px;background:#1d1d1f;color:#fff;padding:12px 14px;border-radius:8px;font-family:'SF Mono',Menlo,monospace;font-size:11.5px;line-height:1.7;max-height:280px;overflow-y:auto;white-space:pre-wrap"></div>
      </div>`;

    host.innerHTML = `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px">
        <div style="background:var(--surface-2);padding:10px 12px;border-radius:8px">
          <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.05em">Licenční klíč</div>
          <div style="font-weight:600;font-size:13px;margin-top:2px;font-family:'SF Mono',Menlo,monospace">${esc(lic.masked || '—')}</div>
        </div>
        <div style="background:var(--surface-2);padding:10px 12px;border-radius:8px">
          <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.05em">Verze</div>
          <div style="font-weight:600;font-size:13px;margin-top:2px">${esc(ver.current || '?')}</div>
        </div>
      </div>
      ${updateBlock}
      ${!lic.ok ? `<div style="background:var(--danger-bg);color:var(--danger-text);padding:10px 12px;border-radius:8px;font-size:12px;margin-top:8px">
        ⚠️ ${lic.reason === 'no_key' ? 'Aplikace běží bez licenčního klíče. Pro plnou podporu kontaktuj dodavatele.' : 'Licenční klíč v config.local.php je neplatný. Kontaktuj dodavatele.'}
      </div>` : ''}
    `;
  } catch (e) {
    host.innerHTML = `<div style="color:var(--danger-text);padding:10px 12px;background:var(--danger-bg);border-radius:8px;font-size:12px">Chyba: ${esc(e.message)}</div>`;
  }
};

window.checkForUpdates = async function() {
  const host = document.getElementById('ns-license-info');
  if (host) host.innerHTML = '⏳ Kontroluji aktualizace…';
  try {
    await api('admin_version_check.php?refresh=1');
    licenseLoadStatus();
  } catch (e) {
    alert('Chyba: ' + e.message);
    licenseLoadStatus();
  }
};

// 🆕 v2.0.71 — One-click self-update z Nastavení (jako vendor master)
window.runSelfUpdate = async function(version, downloadUrl, expectedChecksum) {
  if (!version || !downloadUrl) {
    alert('Chyba: chybí verze nebo download URL');
    return;
  }
  const ok = await confirmDialog({
    title: `Aktualizovat na verzi ${version}?`,
    msg: 'Stáhne se update ZIP z appek.cz, vytvoří se záloha (api/zalohy/), soubory se přepíšou novou verzí. Tvá data + konfigurace zůstanou. Doporučuji předem zálohovat DB.',
    okText: 'Spustit aktualizaci', danger: true,
  });
  if (!ok) return;

  const btn = document.getElementById('ns-self-update-btn');
  const logEl = document.getElementById('ns-self-update-log');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Aktualizuji…'; }
  if (logEl) {
    logEl.style.display = 'block';
    logEl.textContent = `[${new Date().toLocaleTimeString()}] 🚀 Spouštím self-update na ${version}…\n`;
  }
  const logAdd = (m) => {
    if (logEl) {
      logEl.textContent += `[${new Date().toLocaleTimeString()}] ${m}\n`;
      logEl.scrollTop = logEl.scrollHeight;
    }
  };

  // Načti license key z license info (admin_version_check.php už ho zpřístupní)
  let licenseKey = '';
  try {
    const r = await api('admin_version_check.php');
    licenseKey = r.license?.key || '';
  } catch (e) { /* ignore — apply zkusí bez */ }

  // Volej api/updates_apply.php — fallback chain pro endpoint
  const endpoints = [
    'updates_apply.php',
    // fallback 1: pokud customer's local /api nemá updates_apply.php → pure cross-origin
    // (Pozn: updates_apply musí běžet LOKÁLNĚ aby mohl psát soubory)
  ];

  let res = null;
  let lastErr = null;
  for (const ep of endpoints) {
    try {
      logAdd(`→ POST ${ep}…`);
      res = await api(ep, {
        method: 'POST',
        body: JSON.stringify({
          license_key:       licenseKey,
          version:           version,
          download_url:      downloadUrl,
          expected_checksum: expectedChecksum || null,
        }),
      });
      if (res?.ok) break;
      lastErr = res?.error || 'unknown';
    } catch (e) {
      lastErr = e.message;
      logAdd(`✗ ${ep}: ${e.message}`);
    }
  }

  if (res && Array.isArray(res.steps)) {
    res.steps.forEach(s => logAdd(s));
  }

  if (res?.ok) {
    logAdd(`\n🎉 Aktualizace na ${res.version} dokončena!`);
    logAdd(`📁 Backup: ${res.backup_dir || '—'}`);
    logAdd(`✅ Aplikováno ${res.files_applied || '?'} souborů`);

    // 🆕 v2.0.75 — VYČISTI VŠECHNY CACHE před reloadem (jinak servuje starý JS/CSS)
    logAdd(`\n🧹 Čistím Service Worker cache + browser cache…`);
    try {
      // Service Worker cache wipe
      if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage({ type: 'CLEAR_CACHE' });
        logAdd(`  ✅ SW CLEAR_CACHE message odeslán`);
      }
      // Direct cache delete (fallback)
      if ('caches' in window) {
        const keys = await caches.keys();
        await Promise.all(keys.map(k => caches.delete(k)));
        logAdd(`  ✅ Smazáno ${keys.length} cache buckets`);
      }
      // Unregister stale SW → nový SW se zaregistruje na reloadu
      if ('serviceWorker' in navigator) {
        const regs = await navigator.serviceWorker.getRegistrations();
        for (const r of regs) await r.unregister();
        logAdd(`  ✅ ${regs.length} starých SW odregistrováno (nový se zaregistruje po reloadu)`);
      }
    } catch (e) {
      logAdd(`  ⚠️ Cache clear chyba (může být OK): ${e.message}`);
    }

    logAdd(`\n💡 Refresh za 2 sekundy…`);
    if (btn) btn.textContent = '✅ Hotovo · Refresh za chvíli';
    if (typeof toastSuccess === 'function') toastSuccess(t('toast_updated_to_version', { version: res.version }));

    // 🆕 v2.0.79 — flag pro post-refresh banner (zobrazí Apple-style notif po reloadu)
    try { localStorage.setItem('appek_post_update_ack', 'v' + res.version); } catch (e) {}

    // Auto-refresh po 2.5s s cache-bust query
    setTimeout(() => {
      // 🆕 location.reload(true) je deprecated → použij location.href s cache-buster
      const url = new URL(location.href);
      url.searchParams.set('_v', res.version + '-' + Date.now());
      location.href = url.toString();
    }, 2500);
  } else {
    logAdd(`\n❌ Aktualizace selhala: ${lastErr || 'neznámá chyba'}`);

    // 🆕 v2.0.84 — Speciální detekce BUNDLE NEKOMPLETNÍ (vendor publikoval broken bundle)
    if (lastErr && (lastErr.includes('BUNDLE NEKOMPLETNÍ') || lastErr.includes('admin/admin.js'))) {
      logAdd(`\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━`);
      logAdd(`🔥 ROOT CAUSE: Vendor publikoval broken bundle`);
      logAdd(`━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━`);
      logAdd(`Bundle v vendor/updates_storage/ neobsahuje admin/ soubory.`);
      logAdd(`Důvod: Dodavatel nahrál starý MASTER ZIP bez embedded customer bundle.`);
      logAdd(``);
      logAdd(`📞 ŘEŠENÍ pro dodavatele:`);
      logAdd(`  1. Lokálně build-zip.sh v2.0.70+ vygeneruje MASTER s embed`);
      logAdd(`  2. Nahraj fresh MASTER na vendor.appek.cz/vendor/self-update.php`);
      logAdd(`  3. Vendor MASTER 2.0.84+ má integrity check — odmítne broken bundle`);
      logAdd(`  4. Tvoje další aktualizace pak proběhne korektně`);
      logAdd(``);
      logAdd(`💡 Alternativa: použij /admin/force-update.php (standalone recovery)`);
    }

    if (btn) { btn.disabled = false; btn.textContent = '🔄 Zkusit znovu'; }
    if (typeof toastError === 'function') toastError(t('toast_update_failed', { err: lastErr || 'Aktualizace selhala' }));
  }
};

// ═══════════════════════════════════════════════════════════
// 📋 BEZPEČNOSTNÍ LIST / CHEAT SHEET — print-ready (Apple-style)
// ═══════════════════════════════════════════════════════════
window._cheatSheetData = null;

window.openCheatSheet = async function() {
  if (typeof openModal !== 'function') return;

  // Načti data jednou (cache)
  if (!window._cheatSheetData) {
    try {
      const [lic, nast, user] = await Promise.all([
        api('admin_version_check.php').catch(() => ({})),
        api('admin_nastaveni.php').catch(() => ({})),
        api('admin_me.php').catch(() => ({})),
      ]);
      window._cheatSheetData = { lic, nast, user };
    } catch (e) { /* ignore */ }
  }
  const d = window._cheatSheetData || {};
  const lic = d.lic || {};
  const nast = d.nast || {};
  const user = d.user || {};

  const license  = lic.license || {};
  const version  = lic.version || {};
  const licMasked = license.masked || '—';
  const licEmail  = license.email || nast.license_email || '—';
  const ver       = version.current || (typeof APP_VERSION !== 'undefined' ? APP_VERSION : '?');

  const firma = nast.firma_nazev || '—';
  const ico   = nast.firma_ico || '—';
  const dic   = nast.firma_dic || '';
  const ulice = nast.firma_ulice || '';
  const mesto = nast.firma_mesto || '';
  const psc   = nast.firma_psc || '';
  const adresa = [ulice, [psc, mesto].filter(Boolean).join(' ')].filter(Boolean).join(', ');

  const proto = location.protocol === 'https:' ? 'https://' : 'http://';
  const host = location.host;
  const baseUrl = proto + host;
  const adminUrl = baseUrl + '/admin/';
  const b2bUrl   = baseUrl + '/b2b/';

  const adminEmail = user.email || '—';
  const adminName  = user.jmeno || user.display_name || '—';

  // 🎨 Apple-style printable HTML
  const html = `
    <div id="cheatsheet-print-area" style="background:#fff;color:#000;padding:0">

      <!-- Hlavička -->
      <div style="background:linear-gradient(135deg,#BA7517,#854F0B);color:#fff;padding:28px 32px;border-radius:14px 14px 0 0;margin:-1px -1px 0 -1px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap">
          <div style="min-width:0">
            <div style="font-size:11px;letter-spacing:1.5px;opacity:0.9;text-transform:uppercase;font-weight:700;margin-bottom:6px">APPEK Bezpečnostní list</div>
            <div style="font-size:24px;font-weight:800;letter-spacing:-0.02em;line-height:1.2">${esc(firma)}</div>
            <div style="font-size:13px;opacity:0.9;margin-top:6px">${esc(adresa)}</div>
            ${ico !== '—' ? `<div style="font-size:12px;opacity:0.85;margin-top:3px">IČO: <strong>${esc(ico)}</strong>${dic ? ' · DIČ: <strong>'+esc(dic)+'</strong>' : ''}</div>` : ''}
          </div>
          <div style="text-align:right;font-size:11px;opacity:0.85;line-height:1.6;white-space:nowrap">
            <div>Vytištěno: <strong>${new Date().toLocaleDateString('cs-CZ')}</strong></div>
            <div>Verze: <strong>${esc(ver)}</strong></div>
            <div style="margin-top:6px;padding:6px 12px;background:rgba(255,255,255,0.18);border-radius:999px;font-size:10px">📌 USCHOVEJ NA BEZPEČNÉ MÍSTO</div>
          </div>
        </div>
      </div>

      <div style="padding:24px 32px;font-size:14px;line-height:1.6;color:#1d1d1f">

        <!-- ⚠️ UPOZORNĚNÍ -->
        <div style="background:#FFF8E5;border-left:4px solid #BA7517;padding:14px 18px;border-radius:8px;margin-bottom:24px;font-size:13px">
          <strong>⚠️ DŮLEŽITÉ:</strong> Tento list obsahuje citlivé údaje. Vytiskni a uchovej v <strong>uzamčeném šuplíku</strong>.
          Pokud někdo získá tyto údaje, může se přihlásit do tvého systému. <strong>Nikdy nesdílej email s nikým</strong>, kdo o to neoprávněně žádá.
        </div>

        <!-- 1. ZÁKLADNÍ ÚDAJE -->
        <h3 style="font-size:16px;margin:0 0 12px;color:#854F0B;border-bottom:2px solid #FBBF24;padding-bottom:6px;display:flex;align-items:center;gap:8px">
          <span style="background:#BA7517;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:700">1</span>
          Přihlášení do systému
        </h3>
        <table style="width:100%;border-collapse:collapse;margin-bottom:24px;font-size:13.5px">
          <tr style="background:#f7f8fa"><td style="padding:10px;font-weight:600;width:160px">🌐 Web admin</td><td style="padding:10px;font-family:'SF Mono',Menlo,monospace"><a href="${esc(adminUrl)}" style="color:#854F0B">${esc(adminUrl)}</a></td></tr>
          <tr><td style="padding:10px;font-weight:600;border-top:1px solid #eee">📧 Tvůj email</td><td style="padding:10px;border-top:1px solid #eee;font-family:'SF Mono',Menlo,monospace">${esc(adminEmail)}</td></tr>
          <tr style="background:#f7f8fa"><td style="padding:10px;font-weight:600;border-top:1px solid #eee">🔑 Heslo</td><td style="padding:10px;border-top:1px solid #eee;font-family:'SF Mono',Menlo,monospace">_______________________________ <span style="font-size:11px;color:#888">← napiš ručně, neukládáme</span></td></tr>
          <tr><td style="padding:10px;font-weight:600;border-top:1px solid #eee">👤 Jméno administrátora</td><td style="padding:10px;border-top:1px solid #eee">${esc(adminName)}</td></tr>
        </table>

        <!-- 2. LICENCE -->
        <h3 style="font-size:16px;margin:0 0 12px;color:#854F0B;border-bottom:2px solid #FBBF24;padding-bottom:6px;display:flex;align-items:center;gap:8px">
          <span style="background:#BA7517;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:700">2</span>
          Licence APPEK
        </h3>
        <table style="width:100%;border-collapse:collapse;margin-bottom:24px;font-size:13.5px">
          <tr style="background:#f7f8fa"><td style="padding:10px;font-weight:600;width:160px">🔑 Licenční klíč</td><td style="padding:10px;font-family:'SF Mono',Menlo,monospace;letter-spacing:0.1em"><strong>${esc(licMasked)}</strong></td></tr>
          <tr><td style="padding:10px;font-weight:600;border-top:1px solid #eee">📧 Bound email <span style="font-size:11px;color:#888;font-weight:400">(security)</span></td><td style="padding:10px;border-top:1px solid #eee;font-family:'SF Mono',Menlo,monospace">${esc(licEmail)}</td></tr>
          <tr style="background:#f7f8fa"><td style="padding:10px;font-weight:600;border-top:1px solid #eee">📦 Verze</td><td style="padding:10px;border-top:1px solid #eee">v${esc(ver)}</td></tr>
          <tr><td style="padding:10px;font-weight:600;border-top:1px solid #eee;vertical-align:top">⚠️ Co to znamená</td><td style="padding:10px;border-top:1px solid #eee;font-size:12.5px;color:#555;line-height:1.55">
            Licence je svázaná s emailem <strong>${esc(licEmail)}</strong>. Při instalaci na jiném serveru ti pošleme ověřovací kód na tento email — <strong>NEZTRAĆ k němu přístup</strong>.
            Pokud změníš email (např. opustíš poskytovatele), kontaktuj nás na <a href="mailto:podpora@appek.cz">podpora@appek.cz</a> <span style="color:#888;font-size:11px">· tel <a href="tel:+420733700808" style="color:#888;text-decoration:none">733 700 808</a></span>.
          </td></tr>
        </table>

        <!-- 3. KAM JÍT PRO CO -->
        <h3 style="font-size:16px;margin:0 0 12px;color:#854F0B;border-bottom:2px solid #FBBF24;padding-bottom:6px;display:flex;align-items:center;gap:8px">
          <span style="background:#BA7517;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:700">3</span>
          Kam jít pro co
        </h3>
        <table style="width:100%;border-collapse:collapse;margin-bottom:24px;font-size:13.5px">
          <tr style="background:#f7f8fa"><td style="padding:10px;font-weight:600;width:240px">📊 Přehled / dashboard</td><td style="padding:10px">Hlavní stránka po přihlášení</td></tr>
          <tr><td style="padding:10px;font-weight:600;border-top:1px solid #eee">📋 Objednávky</td><td style="padding:10px;border-top:1px solid #eee">Příjem objednávek, statusy, editace</td></tr>
          <tr style="background:#f7f8fa"><td style="padding:10px;font-weight:600;border-top:1px solid #eee">🥖 Výrobní list</td><td style="padding:10px;border-top:1px solid #eee">Co a kolik péct na konkrétní den</td></tr>
          <tr><td style="padding:10px;font-weight:600;border-top:1px solid #eee">📃 Dodací listy + 💰 Faktury</td><td style="padding:10px;border-top:1px solid #eee">Vystavení DL → Faktura (ISDOC export pro účetní)</td></tr>
          <tr style="background:#f7f8fa"><td style="padding:10px;font-weight:600;border-top:1px solid #eee">📦 Výrobky</td><td style="padding:10px;border-top:1px solid #eee">Katalog výrobků, ceník, kategorie, fotky</td></tr>
          <tr><td style="padding:10px;font-weight:600;border-top:1px solid #eee">👥 Odběratelé</td><td style="padding:10px;border-top:1px solid #eee">Tvoji B2B zákazníci, jejich pobočky, ceník</td></tr>
          <tr style="background:#f7f8fa"><td style="padding:10px;font-weight:600;border-top:1px solid #eee">📋 HACCP</td><td style="padding:10px;border-top:1px solid #eee">Hygienické záznamy (teploty, čištění, kritické body)</td></tr>
          <tr><td style="padding:10px;font-weight:600;border-top:1px solid #eee">⚙️ Nastavení</td><td style="padding:10px;border-top:1px solid #eee">Firma, balíčky, uživatelé, zálohy, sync, SMTP</td></tr>
          <tr style="background:#f7f8fa"><td style="padding:10px;font-weight:600;border-top:1px solid #eee">🛒 B2B portál</td><td style="padding:10px;border-top:1px solid #eee"><a href="${esc(b2bUrl)}" style="color:#854F0B">${esc(b2bUrl)}</a> — sem dej odběratelům přístup pro objednávky</td></tr>
        </table>

        <!-- 4. ZÁLOHY -->
        <h3 style="font-size:16px;margin:0 0 12px;color:#854F0B;border-bottom:2px solid #FBBF24;padding-bottom:6px;display:flex;align-items:center;gap:8px">
          <span style="background:#BA7517;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:700">4</span>
          Zálohy databáze
        </h3>
        <div style="background:#f7f8fa;padding:14px 18px;border-radius:8px;margin-bottom:24px;font-size:13px;line-height:1.7">
          <strong>📍 Kde:</strong> Nastavení → 🛠️ Údržba → 💾 Zálohu DB<br>
          <strong>📦 Co dělá:</strong> Vytvoří SQL dump kompletní databáze (objednávky, faktury, výrobky, odběratelé)<br>
          <strong>🔄 Doporučení:</strong> Stáhnout zálohu si <strong>každý pátek</strong> a uchovat 4 nejnovější (= 1 měsíc dozadu)<br>
          <strong>📤 Kam ukládat:</strong> Externí disk / cloud (Google Drive, Dropbox, OneDrive) — nezůstávej jen na serveru<br>
          <strong>♻️ Automatika:</strong> Auto-záloha běží před každým updatem (api/zalohy/)
        </div>

        <!-- 5. AKTUALIZACE -->
        <h3 style="font-size:16px;margin:0 0 12px;color:#854F0B;border-bottom:2px solid #FBBF24;padding-bottom:6px;display:flex;align-items:center;gap:8px">
          <span style="background:#BA7517;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:700">5</span>
          Aktualizace systému
        </h3>
        <div style="background:#f7f8fa;padding:14px 18px;border-radius:8px;margin-bottom:24px;font-size:13px;line-height:1.7">
          <strong>📍 Kde:</strong> Nastavení → 🔑 Licence &amp; aktualizace → 🔄 Zkontrolovat aktualizace<br>
          <strong>🚀 Jak:</strong> Pokud je dostupný update, objeví se velké zelené tlačítko „⚡ Aktualizovat na X.Y.Z"<br>
          <strong>⏱️ Co očekávat:</strong> 1 klik → záloha → stažení → aplikace → refresh stránky (cca 30-60 s)<br>
          <strong>✅ Bezpečné:</strong> Tvá data, faktury, nastavení — vše zůstává nedotčeno<br>
          <strong>📅 Frekvence:</strong> Kontrola každých 5 min v notifikacích (🔔 v top baru) — když svítí čísko, je tu novinka
        </div>

        <!-- 6. ČASTÉ PROBLÉMY -->
        <h3 style="font-size:16px;margin:0 0 12px;color:#854F0B;border-bottom:2px solid #FBBF24;padding-bottom:6px;display:flex;align-items:center;gap:8px">
          <span style="background:#BA7517;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:700">6</span>
          Časté problémy &amp; rychlá řešení
        </h3>
        <table style="width:100%;border-collapse:collapse;margin-bottom:24px;font-size:13px">
          <tr style="background:#f7f8fa"><td style="padding:10px;font-weight:600;width:240px;vertical-align:top">❓ Zapomněl jsem heslo</td><td style="padding:10px;vertical-align:top">Klikni „Zapomněl jsem heslo" na login obrazovce. Pokud ani email nefunguje, kontaktuj <strong>podpora@appek.cz</strong> s licenčním klíčem.</td></tr>
          <tr><td style="padding:10px;font-weight:600;border-top:1px solid #eee;vertical-align:top">❓ Stránka nejde / 500 error</td><td style="padding:10px;border-top:1px solid #eee;vertical-align:top">Cmd+Shift+R (vyčistí cache). Pokud nepomůže, restartuj prohlížeč. Pokud stále nejde, zkontroluj webhostingovou službu (Hostinger / Wedos panel).</td></tr>
          <tr style="background:#f7f8fa"><td style="padding:10px;font-weight:600;border-top:1px solid #eee;vertical-align:top">❓ Email s objednávkou nepřišel</td><td style="padding:10px;border-top:1px solid #eee;vertical-align:top">Nastavení → SMTP → „Odeslat testovací". Pokud nejde, zkontroluj SMTP credentials od poskytovatele mailu.</td></tr>
          <tr><td style="padding:10px;font-weight:600;border-top:1px solid #eee;vertical-align:top">❓ Update selhal</td><td style="padding:10px;border-top:1px solid #eee;vertical-align:top">Záloha je v <code>api/zalohy/update-backup-DATUM/</code> — bezpečně se vracíš zpět. Kontaktuj nás.</td></tr>
          <tr style="background:#f7f8fa"><td style="padding:10px;font-weight:600;border-top:1px solid #eee;vertical-align:top">❓ B2B odběratel nemůže objednat</td><td style="padding:10px;border-top:1px solid #eee;vertical-align:top">Odběratelé → ten odběratel → zkontroluj „Login email" + nastav jim heslo. Případně Odběratelé → „Pozvánka" pošle reset link.</td></tr>
        </table>

        <!-- 7. KONTAKT -->
        <h3 style="font-size:16px;margin:0 0 12px;color:#854F0B;border-bottom:2px solid #FBBF24;padding-bottom:6px;display:flex;align-items:center;gap:8px">
          <span style="background:#BA7517;color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:700">7</span>
          Kontakt &amp; podpora
        </h3>
        <table style="width:100%;border-collapse:collapse;margin-bottom:24px;font-size:13.5px">
          <tr style="background:#f7f8fa"><td style="padding:10px;font-weight:600;width:160px">📧 Email podpora</td><td style="padding:10px"><a href="mailto:podpora@appek.cz" style="color:#854F0B">podpora@appek.cz</a></td></tr>
          <tr><td style="padding:10px;font-weight:600;border-top:1px solid #eee">🌐 Web</td><td style="padding:10px;border-top:1px solid #eee"><a href="https://appek.cz" style="color:#854F0B">appek.cz</a></td></tr>
          <tr style="background:#f7f8fa"><td style="padding:10px;font-weight:600;border-top:1px solid #eee">📚 Návod</td><td style="padding:10px;border-top:1px solid #eee"><a href="https://appek.cz/instalace.html" style="color:#854F0B">appek.cz/instalace.html</a></td></tr>
          <tr><td style="padding:10px;font-weight:600;border-top:1px solid #eee">⚖️ VOP</td><td style="padding:10px;border-top:1px solid #eee"><a href="https://appek.cz/obchodni-podminky.html" style="color:#854F0B">obchodní podmínky</a></td></tr>
        </table>

        <!-- Footer -->
        <div style="margin-top:32px;padding-top:16px;border-top:2px solid #eee;text-align:center;font-size:11px;color:#888;line-height:1.6">
          APPEK · Bezpečnostní list ${esc(firma)} · Vytištěno ${new Date().toLocaleString('cs-CZ')}<br>
          Tento dokument je důvěrný. Po vytištění uschovej v uzamčeném prostoru.
        </div>

      </div>
    </div>

    <div class="modal-actions no-print">
      <!-- "Zavřít" smazáno v v2.5.11 — × v rohu modal-card stačí (větší klikací plocha) -->
      <div class="grow"></div>
      <button class="btn-primary" onclick="window.printCheatSheet()">🖨️ Vytisknout / Uložit jako PDF</button>
    </div>

    <style>
      @media print {
        body * { visibility: hidden; }
        #cheatsheet-print-area, #cheatsheet-print-area * { visibility: visible; }
        #cheatsheet-print-area { position: absolute; left: 0; top: 0; width: 100%; }
        .no-print { display: none !important; }
        .modal-overlay { position: static; padding: 0; }
        .modal-card { box-shadow: none; max-width: 100% !important; max-height: none !important; }
      }
    </style>
  `;
  // 🆕 v2.9.66 — správné volání openModal(title, body, size). Předtím se html
  // posílalo jako title a {wide:true} jako body → modal-body = "[object Object]"
  // a v hlavičce byl 2× křížek (vlastní + ten z #modal). Teď 1 křížek, 1 hlavička.
  openModal('📋 Bezpečnostní list · ' + firma, html, 'wide');
};

window.printCheatSheet = function() {
  if (!document.getElementById('cheatsheet-print-area')) {
    window.openCheatSheet().then(() => setTimeout(() => window.print(), 300));
  } else {
    window.print();
  }
};

// ═══════════════════════════════════════════════════════════
// ☁️ SYNC UI — Phase 3 (lokální↔cloud sync admin interface)
// ═══════════════════════════════════════════════════════════

window.syncLoadStatus = async function() {
  const host = document.getElementById('ns-sync-status');
  const badge = document.getElementById('ns-sync-mode-badge');
  if (!host) return;
  host.innerHTML = '⏳ Načítám…';

  try {
    const s = await api('sync/status.php');
    const c = s.config || {};
    const modeIcons = { local: '🏠 Pouze lokálně', hybrid: '🔄 Lokálně + Cloud', cloud: '☁️ Pouze cloud' };
    if (badge) {
      badge.textContent = modeIcons[c.mode] || c.mode;
      badge.style.background = c.enabled ? 'var(--success-bg)' : 'var(--surface-2)';
      badge.style.color = c.enabled ? 'var(--success-text)' : 'var(--text-3)';
    }

    // Status indikátor
    const statusMap = {
      success: { ico: '✅', label: 'V pořádku', color: 'var(--success-text)' },
      partial: { ico: '🟡', label: 'Částečný sync', color: '#92400e' },
      error:   { ico: '🔴', label: 'Chyba', color: 'var(--danger-text)' },
      never:   { ico: '⏸️', label: 'Nikdy nesyncováno', color: 'var(--text-3)' },
    };
    const st = statusMap[c.last_sync_status] || statusMap.never;

    // Pending records
    const pending = s.pending?.total || 0;
    const pendingDetail = Object.entries(s.pending?.by_table || {})
      .map(([t, n]) => `${t} (${n})`).join(', ');

    // Last sync time
    const lastSyncStr = c.last_sync_at
      ? new Date(c.last_sync_at.replace(' ', 'T')).toLocaleString('cs-CZ')
      : '—';

    host.innerHTML = `
      ${!c.enabled ? `
        <div style="background:var(--surface-2);padding:12px 14px;border-radius:8px;color:var(--text-3);font-size:13px">
          🛑 <strong>Sync je vypnutý.</strong> Aplikace běží v módu <strong>${esc(modeIcons[c.mode] || c.mode)}</strong>.
          Klikni <strong>⚙️ Konfigurace</strong> pro zapnutí.
        </div>
      ` : `
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:10px">
          <div style="background:var(--surface-2);padding:10px 12px;border-radius:8px">
            <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.05em">Stav</div>
            <div style="font-weight:600;color:${st.color};font-size:13px;margin-top:2px">${st.ico} ${st.label}</div>
          </div>
          <div style="background:var(--surface-2);padding:10px 12px;border-radius:8px">
            <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.05em">Poslední sync</div>
            <div style="font-weight:600;font-size:13px;margin-top:2px">${esc(lastSyncStr)}</div>
          </div>
          <div style="background:var(--surface-2);padding:10px 12px;border-radius:8px">
            <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.05em">Čeká na sync</div>
            <div style="font-weight:600;font-size:13px;margin-top:2px">${pending} ${pending === 1 ? 'záznam' : 'záznamů'}</div>
          </div>
        </div>
        ${pendingDetail ? `<div style="font-size:11px;color:var(--text-3);margin-bottom:8px">📋 ${esc(pendingDetail)}</div>` : ''}
        ${c.last_error ? `<div style="background:var(--danger-bg);color:var(--danger-text);padding:10px 12px;border-radius:8px;font-size:12px;margin-bottom:8px">⚠️ <strong>Poslední chyba:</strong> ${esc(c.last_error.slice(0, 250))}</div>` : ''}
        <details style="font-size:12px">
          <summary style="cursor:pointer;color:var(--text-3)">📜 Posledních ${(s.recent_logs || []).length} sync operací</summary>
          <div style="margin-top:8px;max-height:300px;overflow-y:auto">
            ${(s.recent_logs || []).map(l => `
              <div style="padding:6px 10px;background:var(--surface-2);border-radius:6px;margin-bottom:4px;font-size:11px">
                <strong>${esc(l.direction)}</strong> · ${esc(l.status)} · ${esc(l.records_count)} záznamů · ${l.duration_ms}ms · ${esc(l.created_at)}
                ${l.error_message ? `<div style="color:var(--danger-text);margin-top:2px">${esc(l.error_message.slice(0, 150))}</div>` : ''}
              </div>
            `).join('') || '<div style="color:var(--text-3);font-size:11px;padding:8px">Žádné záznamy.</div>'}
          </div>
        </details>
      `}
    `;
  } catch (e) {
    host.innerHTML = `<div style="color:var(--danger-text);padding:10px 12px;background:var(--danger-bg);border-radius:8px;font-size:12px">Chyba: ${esc(e.message)}</div>`;
  }
};

window.syncRunNow = async function() {
  if (!(await confirmDialog({ title: 'Spustit synchronizaci?', msg: 'Synchronizace proběhne teď.', okText: 'Spustit' }))) return;
  const host = document.getElementById('ns-sync-status');
  if (host) host.innerHTML = '⏳ Synchronizuji…';
  try {
    const r = await api('sync/agent.php?manual=1');
    if (r.skipped === 'sync_disabled') {
      alert('🛑 Sync je vypnutý. Otevři Konfigurace a zapni ho.');
    } else if (r.errors?.length) {
      alert('🟡 Sync proběhl s chybami:\n\n' + r.errors.join('\n'));
    } else {
      alert(t('sync_done_stats', { pushed: r.records_pushed || 0, pulled: r.records_pulled || 0, ms: r.duration_ms }));
    }
    syncLoadStatus();
  } catch (e) {
    alert('❌ Chyba: ' + e.message);
    syncLoadStatus();
  }
};

window.syncOpenConfig = async function() {
  const s = await api('sync/status.php');
  const c = s.config || {};

  openModal('⚙️ Konfigurace sync s cloudem', `
    <p style="font-size:13px;color:var(--text-2);line-height:1.6;margin-bottom:14px">
      Hybrid sync umožňuje běh na lokálním PC (provoz) s automatickým zrcadlením do cloudu (B2B portál).
      Vhodné pro gastro provozy se slabým internetem.
    </p>

    <div class="form-grid form-grid-tight">
      <div class="full">
        <label class="form-label">🎯 Režim</label>
        <select class="form-input" id="sync-mode">
          <option value="cloud"  ${c.mode === 'cloud'  ? 'selected' : ''}>☁️ Pouze cloud (současný stav)</option>
          <option value="local"  ${c.mode === 'local'  ? 'selected' : ''}>🏠 Pouze lokálně (offline-only)</option>
          <option value="hybrid" ${c.mode === 'hybrid' ? 'selected' : ''}>🔄 Hybrid (lokálně + cloud sync)</option>
        </select>
      </div>

      <div class="full">
        <label class="form-label">🔁 Role tohoto serveru</label>
        <select class="form-input" id="sync-role">
          <option value="master" ${c.role === 'master' ? 'selected' : ''}>🖥️ Master (provoz — píše vše)</option>
          <option value="mirror" ${c.role === 'mirror' ? 'selected' : ''}>☁️ Mirror (cloud — přijímá změny)</option>
        </select>
        <small style="color:var(--text-3);font-size:11px;display:block;margin-top:4px">Master = lokální PC v provoze. Mirror = cloud hosting.</small>
      </div>

      <div class="full">
        <label class="form-label">🌐 Cloud endpoint URL</label>
        <input class="form-input" id="sync-endpoint" type="url" value="${esc(c.cloud_endpoint || '')}" placeholder="https://moje-firma.cz/api">
        <small style="color:var(--text-3);font-size:11px;display:block;margin-top:4px">Adresa cloud hostingu (kde běží B2B). Master sem posílá změny.</small>
      </div>

      <div>
        <label class="form-label">⏰ Interval sync (minuty)</label>
        <input class="form-input" id="sync-interval" type="number" min="5" max="1440" value="${c.interval_minutes || 15}">
      </div>

      <div>
        <label class="form-label">🔐 Shared secret</label>
        <div style="display:flex;gap:6px">
          <input class="form-input" id="sync-secret-status" value="${c.has_secret ? '✅ nastaven' : '❌ chybí'}" readonly>
          <button class="btn-secondary" onclick="syncGenerateSecret()" style="white-space:nowrap;font-size:12px">🎲 Generovat</button>
        </div>
        <small style="color:var(--text-3);font-size:11px;display:block;margin-top:4px">Sdílený mezi master a mirror. Musí být stejný na obou stranách.</small>
      </div>

      <div class="full">
        <label class="checkbox-row" style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--surface-2);border-radius:8px;cursor:pointer">
          <input type="checkbox" id="sync-enabled" ${c.enabled ? 'checked' : ''} style="width:20px;height:20px">
          <span style="flex:1">
            <strong style="font-size:14px">🔄 Zapnout synchronizaci</strong>
            <div style="font-size:12px;color:var(--text-3);margin-top:2px">Aktivuje cron + manuální trigger. Vypnutí zachová data, jen pozastaví sync.</div>
          </span>
        </label>
      </div>
    </div>

    <div class="box tip" style="margin-top:14px;padding:12px 14px;background:rgba(0,122,255,0.08);border-left:3px solid #0071E3;border-radius:8px">
      <strong style="font-size:13px;color:#0071E3">💡 Cron job na masteru</strong>
      <p style="font-size:12px;margin:4px 0 0;color:var(--text-2)">
        Aby sync běžel automaticky každých ${c.interval_minutes || 15} minut, přidej do cron:
        <code style="display:block;background:var(--surface-2);padding:6px 10px;border-radius:4px;margin-top:4px;font-size:11px">*/${c.interval_minutes || 15} * * * * php ${location.origin}/api/sync/agent.php</code>
      </p>
    </div>

    <div class="form-actions">
      <button class="btn-secondary" onclick="syncTestConnection()">🔌 Test připojení</button>
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="syncSaveConfig()" style="font-weight:700">💾 Uložit</button>
    </div>
  `, 'wide');
};

window.syncGenerateSecret = async function() {
  if (!(await confirmDialog({ title: 'Nový shared secret?', msg: 'Pokud máš sync zapnutý, MUSÍŠ tento secret nastavit i na druhé straně (master/mirror), jinak sync přestane fungovat.', okText: 'Vygenerovat', danger: true }))) return;
  try {
    const r = await api('sync/status.php?action=generate_secret', { method: 'POST' });
    const el = document.getElementById('sync-secret-status');
    if (el) el.value = '✅ vygenerován — zkopíruj: ' + r.secret;
    alert('✅ Vygenerován nový secret:\n\n' + r.secret + '\n\n⚠️ Zkopíruj si ho NYNÍ a nastav na druhé straně. Po zavření okna ho už nebudeš moci přečíst.');
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.syncSaveConfig = async function() {
  const data = {
    mode: document.getElementById('sync-mode').value,
    role: document.getElementById('sync-role').value,
    cloud_endpoint: document.getElementById('sync-endpoint').value.trim(),
    interval_minutes: parseInt(document.getElementById('sync-interval').value) || 15,
    enabled: document.getElementById('sync-enabled').checked,
  };
  try {
    await api('sync/status.php?action=save_config', { method: 'POST', body: JSON.stringify(data) });
    closeModal();
    alert('✅ Konfigurace uložena.');
    syncLoadStatus();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.syncTestConnection = async function() {
  try {
    const r = await api('sync/status.php?action=test_connection', { method: 'POST' });
    if (r.ok) {
      alert(t('cloud_connection_ok', { status: r.status }));
    } else {
      alert(t('cloud_responded_with_error', { json: JSON.stringify(r.response, null, 2) }));
    }
  } catch (e) {
    alert('❌ Test selhal:\n\n' + e.message + '\n\nZkontroluj cloud endpoint URL a shared secret na obou stranách.');
  }
};

