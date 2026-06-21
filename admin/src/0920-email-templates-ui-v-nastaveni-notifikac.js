// =============================================================
// 📝 EMAIL TEMPLATES — UI v Nastavení → Notifikace
// =============================================================
window.loadEmailTemplates = async function() {
  const host = document.getElementById('email-templates-container');
  if (!host) return;
  try {
    const d = await api('admin_nastaveni.php?action=email_templates');
    state._email_templates_promenne = d.promenne || {};
    host.innerHTML = d.templates.map(t => `
      <div class="card-block" style="padding:12px 14px;margin:0;background:var(--surface-2);border:1px solid var(--border)">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
          <div style="flex:1;min-width:240px">
            <div style="font-weight:700;font-size:14px;color:var(--text-1);display:flex;align-items:center;gap:8px;flex-wrap:wrap">
              ${esc(t.popis || t.klic)}
              <span style="background:${t.format === 'html' ? '#E0F2FE' : '#F5F5F5'};color:${t.format === 'html' ? '#0C447C' : '#666'};padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700">${t.format === 'html' ? '🎨 HTML' : '📝 TEXT'}</span>
            </div>
            <div style="font-size:12px;color:var(--text-3);margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:600px">
              ${esc(t.predmet)}
            </div>
            ${t.upraveno ? '<span style="display:inline-block;margin-top:6px;background:#FFF8E5;color:#854F0B;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600">✏️ upraveno</span>' : ''}
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <button class="btn-secondary" style="font-size:12px;padding:6px 12px" onclick="emailTemplateEdit('${esc(t.klic)}')">✏️ Upravit</button>
            <button class="btn-secondary" style="font-size:12px;padding:6px 12px" onclick="emailTemplatePreview('${esc(t.klic)}')">👁️ Náhled</button>
            ${t.upraveno ? `<button class="btn-secondary" style="font-size:12px;padding:6px 12px;color:var(--danger-text)" onclick="emailTemplateReset('${esc(t.klic)}')">🔄 Reset</button>` : ''}
          </div>
        </div>
      </div>
    `).join('');
  } catch (e) {
    host.innerHTML = `<div style="color:var(--danger-text);padding:12px">Chyba: ${esc(e.message)}</div>`;
  }
};

window.emailTemplateEdit = async function(klic, override = null) {
  try {
    const d = await api('admin_nastaveni.php?action=email_templates');
    const tpl = d.templates.find(t => t.klic === klic);
    if (!tpl) return alert('Šablona nenalezena');
    // 🎯 Override (např. po výběru HTML designu z picker modal)
    if (override) {
      if (override.telo !== undefined) tpl.telo = override.telo;
      if (override.predmet !== undefined) tpl.predmet = override.predmet;
      if (override.format !== undefined) tpl.format = override.format;
    }
    const promenne = d.promenne || {};
    const isHtml = tpl.format === 'html';

    openModal(`✏️ Upravit šablonu — ${tpl.popis || klic}`, `
      <!-- Toggle Text / HTML -->
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;padding:10px 14px;background:var(--surface-2);border-radius:8px;border:1px solid var(--border)">
        <div style="display:flex;gap:4px;align-items:center">
          <button type="button" id="et-mode-text" class="period-tab ${!isHtml ? 'active' : ''}" onclick="emailTplSetMode('text')" style="font-size:13px;padding:8px 16px"><span class="period-tab-icon">📝</span><span class="period-tab-text">Plain text</span></button>
          <button type="button" id="et-mode-html" class="period-tab ${isHtml ? 'active' : ''}" onclick="emailTplSetMode('html')" style="font-size:13px;padding:8px 16px"><span class="period-tab-icon">🎨</span><span class="period-tab-text">HTML</span></button>
          <input type="hidden" id="et-format" value="${isHtml ? 'html' : 'text'}">
        </div>
        <div id="et-html-tools" style="display:${isHtml ? 'flex' : 'none'};gap:6px;flex-wrap:wrap">
          <button type="button" class="btn-primary btn-green" onclick="emailTplGenerate('${esc(klic)}', 'random')" style="font-size:12px;padding:6px 14px;font-weight:700">🎲 Náhodný design</button>
          <button type="button" class="btn-secondary" onclick="emailTplShowDesigns('${esc(klic)}')" style="font-size:12px;padding:6px 12px">🎨 Vybrat design</button>
        </div>
      </div>

      <div class="form-grid form-grid-tight">
        <div class="full">
          <label class="form-label">Předmět e-mailu</label>
          <input class="form-input" id="et-predmet" value="${esc(tpl.predmet)}" style="font-size:14px">
        </div>
        <div class="full">
          <label class="form-label">Tělo e-mailu <span id="et-format-hint" style="font-weight:400;color:var(--text-3);font-size:11px">${isHtml ? '(HTML — můžeš použít tagy, inline styly)' : '(prostý text)'}</span></label>
          <textarea class="form-input" id="et-telo" rows="16" style="font-family:'SF Mono',Menlo,Consolas,monospace;font-size:12px;line-height:1.5">${esc(tpl.telo)}</textarea>
        </div>
        <div class="full">
          <label class="form-label">💡 Dostupné proměnné (klik vloží do těla)</label>
          <div style="display:flex;flex-wrap:wrap;gap:6px;background:var(--surface-2);padding:10px;border-radius:8px;border:1px solid var(--border);max-height:140px;overflow-y:auto">
            ${Object.entries(promenne).map(([k, popis]) => `
              <button type="button" onclick="emailTemplateInsertVar('${esc(k)}')" class="btn-secondary" style="font-size:11px;padding:4px 8px;font-family:'SF Mono',Menlo,Consolas,monospace" title="${esc(popis)}">{${esc(k)}}</button>
            `).join('')}
          </div>
        </div>
      </div>
      <div class="form-actions">
        <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
        <button class="btn-secondary" onclick="emailTemplatePreviewLive('${esc(klic)}')">👁️ Náhled s vzorovými daty</button>
        <div style="flex:1"></div>
        <button class="btn-primary btn-green" onclick="emailTemplateSave('${esc(klic)}')" style="font-weight:700;padding:12px 24px">💾 Uložit šablonu</button>
      </div>
    `, 'wide');
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🔀 Přepnutí Text ↔ HTML mode
window.emailTplSetMode = function(mode) {
  document.getElementById('et-format').value = mode;
  document.getElementById('et-mode-text')?.classList.toggle('active', mode === 'text');
  document.getElementById('et-mode-html')?.classList.toggle('active', mode === 'html');
  document.getElementById('et-html-tools').style.display = mode === 'html' ? 'flex' : 'none';
  const hint = document.getElementById('et-format-hint');
  if (hint) hint.textContent = mode === 'html' ? '(HTML — můžeš použít tagy, inline styly)' : '(prostý text)';
};

// 🎲 Generuj náhodný HTML design (nebo konkrétní) — volá se z otevřeného editoru
window.emailTplGenerate = async function(klic, style) {
  if (style === 'random' && !(await confirmDialog({ title: 'Náhodný HTML design?', msg: 'Aktuální obsah těla bude přepsán.', okText: 'Načíst', danger: true }))) return;
  try {
    const r = await api(`admin_nastaveni.php?action=email_template_generate_html&klic=${encodeURIComponent(klic)}&style=${style}`);
    const ta = document.getElementById('et-telo');
    if (!ta) {
      // Editor není otevřený → reopen s override
      const predmet = document.getElementById('et-predmet')?.value || state._emailTplSavedPredmet || '';
      return emailTemplateEdit(klic, { telo: r.telo, predmet, format: 'html' });
    }
    ta.value = r.telo;
    emailTplSetMode('html');
    // Mini toast
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:12px 18px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:13px;font-weight:600;z-index:99999';
    t.textContent = `✓ Načten design: ${r.popis} — otevírám náhled…`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 2200);
    // 👁️ Auto-zobrazit RENDERED náhled po generování (uživatel nechce vidět kód v editoru)
    setTimeout(() => emailTemplatePreviewLive(klic), 250);
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🎨 Vyber konkrétní design (modal s grid)
window.emailTplShowDesigns = async function(klic) {
  // Před otevřením picker modal uložím aktuální stav editoru (předmět, abych ho neztratil)
  const savedPredmet = document.getElementById('et-predmet')?.value || '';
  state._emailTplSavedPredmet = savedPredmet;
  try {
    const r = await api(`admin_nastaveni.php?action=email_template_generate_html&klic=${encodeURIComponent(klic)}&style=modern`);
    const designs = r.designs || {};
    const html = Object.entries(designs).map(([id, popis]) => `
      <button type="button" class="btn-secondary" onclick="emailTplPickDesign('${esc(klic)}', '${esc(id)}')" style="text-align:left;padding:14px;min-height:80px;display:flex;flex-direction:column;align-items:flex-start;gap:4px">
        <strong style="font-size:14px">${esc(popis)}</strong>
        <span style="font-size:11px;color:var(--text-3)">Klikni pro načtení</span>
      </button>
    `).join('');
    openModal(`🎨 Vybrat HTML design`, `
      <p style="font-size:13px;color:var(--text-2);margin-bottom:14px">Vyber design — bude vyrenderován s placeholders, můžeš pak ručně upravit. Aktuální obsah těla bude přepsán.</p>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px">${html}</div>
      <div class="form-actions">
        <button class="btn-back" onclick="closeModal();setTimeout(() => emailTemplateEdit('${esc(klic)}', { predmet: state._emailTplSavedPredmet }), 50)">← Zpět</button>
      </div>
    `, 'wide');
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🎯 Vybere design a načte ho do editoru — fix bugu „et-telo is null"
window.emailTplPickDesign = async function(klic, style) {
  try {
    const r = await api(`admin_nastaveni.php?action=email_template_generate_html&klic=${encodeURIComponent(klic)}&style=${style}`);
    closeModal();
    setTimeout(() => emailTemplateEdit(klic, {
      telo: r.telo,
      predmet: state._emailTplSavedPredmet,
      format: 'html',   // 🎨 Auto-přepne na HTML
    }), 50);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.emailTemplateInsertVar = function(name) {
  const t = document.getElementById('et-telo');
  if (!t) return;
  const start = t.selectionStart;
  const end = t.selectionEnd;
  const ins = '{' + name + '}';
  t.value = t.value.substring(0, start) + ins + t.value.substring(end);
  t.selectionStart = t.selectionEnd = start + ins.length;
  t.focus();
};

window.emailTemplateSave = async function(klic) {
  const predmet = document.getElementById('et-predmet')?.value.trim();
  const telo    = document.getElementById('et-telo')?.value;
  const format  = document.getElementById('et-format')?.value || 'text';
  if (!predmet) return alert('Vyplňte předmět');
  if (!telo)    return alert('Vyplňte tělo');
  try {
    await api('admin_nastaveni.php?action=email_template', {
      method: 'PUT',
      body: JSON.stringify({ klic, predmet, telo, format }),
    });
    closeModal();
    loadEmailTemplates();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// ← Zpět do editoru z náhledu — zachová generovaný/upravený obsah
window.emailTplBackToEditor = function() {
  const p = state._emailTplPendingSave;
  if (!p || !p.klic) {
    closeModal();
    return;
  }
  // Reopen editor s overrides (zachová generovaný obsah)
  emailTemplateEdit(p.klic, {
    telo: p.telo,
    predmet: p.predmet,
    format: p.format || 'text',
  });
};

// 💾 Uložit šablonu přímo z náhledu (před uložením) — nevrací se zpátky do editoru
window.emailTplSaveFromPreview = async function() {
  const p = state._emailTplPendingSave;
  if (!p || !p.klic) return alert('Nemám co uložit. Otevři editor znovu.');
  if (!p.predmet || !String(p.predmet).trim()) return alert('Vyplňte předmět');
  if (!p.telo || !String(p.telo).trim())       return alert('Vyplňte tělo');
  try {
    await api('admin_nastaveni.php?action=email_template', {
      method: 'PUT',
      body: JSON.stringify({
        klic:    p.klic,
        predmet: String(p.predmet).trim(),
        telo:    p.telo,
        format:  p.format || 'text',
      }),
    });
    state._emailTplPendingSave = null;
    closeModal();
    // Refresh seznamu šablon a notifikace
    loadEmailTemplates();
    // Toast
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.15);font-size:13px;font-weight:600;z-index:9999';
    t.innerHTML = '💾 Šablona uložena.';
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.emailTemplatePreview = async function(klic, customVars = null) {
  try {
    const r = await api('admin_nastaveni.php?action=email_template_preview', {
      method: 'POST',
      body: JSON.stringify({ klic, vars: customVars }),
    });
    // Detekce HTML: server pošle r.format, ale pojistka — heuristika podle obsahu
    const isHtml = (r.format === 'html')
      || (typeof r.telo === 'string' && /<\s*(html|body|table|div|p|h[1-6]|center)[\s>]/i.test(r.telo));

    // Tělo render: HTML → iframe (sandbox), text → <pre>
    const bodyHtml = isHtml
      ? `<iframe id="email-tpl-preview-iframe" style="width:100%;min-height:540px;border:1px solid var(--border);border-radius:8px;background:#fff" sandbox="allow-same-origin"></iframe>`
      : `<pre style="white-space:pre-wrap;font-family:inherit;font-size:13px;line-height:1.6;margin:0;color:#000;background:#fff;padding:14px 18px;border-radius:8px;border:1px solid var(--border)">${esc(r.telo)}</pre>`;

    openModal(`👁️ Náhled e-mailu ${isHtml ? '— HTML render' : '— text'}`, `
      <div style="background:var(--surface-2);padding:12px;border-radius:8px;margin-bottom:10px;border:1px solid var(--border)">
        <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Předmět</div>
        <div style="font-weight:700;font-size:14px">${esc(r.predmet)}</div>
      </div>
      <div>
        <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px">Tělo (s vzorovými daty)</div>
        ${bodyHtml}
      </div>
      <div class="form-actions">
        <button class="btn-secondary" onclick="closeModal();setTimeout(()=>emailTemplateEdit('${esc(klic)}'),50)" title="Otevřít editor a upravit šablonu">✏️ Upravit</button>
        <div style="flex:1"></div>
        <button class="btn-primary btn-green" onclick="closeModal()">Zavřít</button>
      </div>
    `, 'wide');

    // Naplň iframe HTML obsahem (jen pokud je HTML format)
    if (isHtml) {
      setTimeout(() => {
        const ifr = document.getElementById('email-tpl-preview-iframe');
        if (ifr && ifr.contentDocument) {
          ifr.contentDocument.open();
          ifr.contentDocument.write(r.telo);
          ifr.contentDocument.close();
          // Auto-resize podle obsahu
          setTimeout(() => {
            try {
              const h = ifr.contentDocument.documentElement.scrollHeight;
              ifr.style.height = (h + 20) + 'px';
            } catch {}
          }, 100);
        }
      }, 50);
    }
  } catch (e) { alert('Chyba: ' + e.message); }
};

// Live náhled z aktuálně otevřeného editoru (před uložením)
window.emailTemplatePreviewLive = async function(klic) {
  const predmet = document.getElementById('et-predmet')?.value || '';
  const telo    = document.getElementById('et-telo')?.value || '';
  const format  = document.getElementById('et-format')?.value || 'text';
  // Render lokálně přes substituci proměnných (na frontendu — same logic)
  const sample = {
    firma: 'APPEK B2B',
    cislo: 'OBJ-2026-148',
    datum: '14. 5. 2026',
    misto: '',
    odberatel: 'Hotel Beránek s.r.o.',
    stav: 'expedována',
    polozky_text: '  • Chleba selský — 12 ks × 28,00 Kč = 336,00 Kč\n  • Bageta — 8 ks × 18,00 Kč = 144,00 Kč',
    castka_bez_dph: '420,00 Kč',
    castka_dph: '60,00 Kč',
    castka_celkem: '480,00 Kč',
    poznamka: 'Dovézt prosím před 8:30.',
  };
  const render = (text) => {
    let out = text;
    if (out.includes('{poznamka_block}')) {
      out = out.replace('{poznamka_block}', sample.poznamka ? '\nPoznámka: ' + sample.poznamka + '\n' : '');
    }
    Object.keys(sample).forEach(k => {
      out = out.split('{' + k + '}').join(sample[k]);
    });
    return out;
  };
  const isHtml = format === 'html';
  const bodyRendered = render(telo);
  // HTML: renderuj v iframe (izoluje styly od admin UI)
  const bodyHtml = isHtml
    ? `<iframe id="email-preview-iframe" style="width:100%;min-height:540px;border:1px solid var(--border);border-radius:8px;background:#fff" sandbox="allow-same-origin"></iframe>`
    : `<pre style="white-space:pre-wrap;font-family:inherit;font-size:13px;line-height:1.6;margin:0;color:#000;background:#fff;padding:14px 18px;border-radius:8px;border:1px solid var(--border)">${esc(bodyRendered)}</pre>`;
  // Uložíme aktuální editor stav do globálního state — kvůli tlačítku Uložit níže
  state._emailTplPendingSave = { klic, predmet, telo, format };
  openModal(`👁️ Náhled (před uložením) ${isHtml ? '— HTML render' : '— text'}`, `
    <div style="background:var(--surface-2);padding:12px;border-radius:8px;margin-bottom:10px;border:1px solid var(--border)">
      <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Předmět</div>
      <div style="font-weight:700;font-size:14px">${esc(render(predmet))}</div>
    </div>
    <div>
      <div style="font-size:11px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px">Tělo (s vzorovými daty)</div>
      ${bodyHtml}
    </div>
    <div class="form-actions">
      <button class="btn-back" onclick="emailTplBackToEditor()">← Zpět do editoru</button>
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="emailTplSaveFromPreview()" style="font-weight:700;padding:12px 24px">💾 Uložit šablonu</button>
    </div>
  `, 'wide');
  // Naplň iframe HTML obsahem
  if (isHtml) {
    setTimeout(() => {
      const ifr = document.getElementById('email-preview-iframe');
      if (ifr && ifr.contentDocument) {
        ifr.contentDocument.open();
        ifr.contentDocument.write(bodyRendered);
        ifr.contentDocument.close();
        // Auto-resize iframe podle obsahu
        setTimeout(() => {
          try {
            const h = ifr.contentDocument.documentElement.scrollHeight;
            ifr.style.height = (h + 20) + 'px';
          } catch {}
        }, 100);
      }
    }, 50);
  }
};

window.emailTemplateReset = async function(klic) {
  if (!(await confirmDialog({ title: 'Vrátit šablonu na výchozí?', msg: 'Vaše úpravy se ztratí.', okText: 'Vrátit výchozí', danger: true }))) return;
  try {
    await api('admin_nastaveni.php?action=email_template_reset', {
      method: 'POST',
      body: JSON.stringify({ klic }),
    });
    loadEmailTemplates();
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.loadDph = async function() {
  // 🐛 v3.0.294 — stejný async race jako loadCislovani: tab přepnut dřív než dorazí API
  if (!document.getElementById('dph-container')) return;
  try {
    const data = await api('admin_sazby_dph.php');
    renderDph(data);
  } catch (e) {
    const host = document.getElementById('dph-container');
    if (host) host.innerHTML =
      `<div style="color:var(--danger-text);padding:12px;background:var(--danger-bg);border-radius:6px;">
        Chyba při načítání: ${esc(e.message)}
      </div>`;
  }
};

function renderDph(data) {
  const c = document.getElementById('dph-container');
  if (!c) return;   // 🐛 v3.0.294 — element mohl zmizet (přepnutý tab)
  c.innerHTML = `
    ${data.sazby.length === 0 ? `
      <div class="empty-state" style="padding:24px;background:var(--surface-2);border-radius:8px">Žádné sazby DPH</div>
    ` : `
      <div class="dph-grid">
        ${data.sazby.map((s) => `
          <div class="dph-card">
            <div class="dph-card-head">
              <strong class="dph-card-nazev">${esc(s.nazev)}</strong>
              <span class="dph-card-sazba">${parseFloat(s.sazba).toFixed(2).replace('.', ',')} %</span>
            </div>
            <div class="dph-card-meta">
              <span title="Platnost od">📅 ${fmtDate(s.platne_od)}</span>
              <span title="Počet výrobků">📦 ${s.pocet_vyrobku} výrobků</span>
            </div>
            <div class="dph-card-actions">
              <button class="btn-secondary" onclick="editDph(${s.id})">Upravit</button>
              <button class="btn-danger" onclick="smazatDph(${s.id}, ${s.pocet_vyrobku})">Smazat</button>
            </div>
          </div>
        `).join('')}
      </div>
    `}
    <button class="btn-secondary" style="margin-top:10px" onclick="editDph()">+ Přidat sazbu DPH</button>
  `;
}

window.editDph = async function(id = null) {
  let s = { sazba: '', nazev: '', platne_od: new Date().toISOString().split('T')[0] };

  if (id) {
    const data = await api('admin_sazby_dph.php');
    s = data.sazby.find(x => x.id === id);
    if (!s) return alert('Sazba nenalezena');
  }

  openModal(id ? `Upravit sazbu DPH: ${esc(s.nazev)}` : '+ Nová sazba DPH', `
    <div class="form-grid" style="grid-template-columns:1fr 1fr">
      <label class="full">
        <span>Název *</span>
        <input class="input" id="dph-nazev" value="${esc(s.nazev || '')}" placeholder="např. snížená, základní, druhá snížená" maxlength="20">
      </label>
      <label>
        <span>Sazba % *</span>
        <input class="input" id="dph-sazba" type="number" step="0.01" min="0" max="100" value="${s.sazba || ''}" placeholder="12.00">
      </label>
      <label>
        <span>Platnost od *</span>
        <input class="input" id="dph-platne-od" type="date" value="${s.platne_od || ''}">
      </label>
    </div>

    <div style="background:var(--info-bg);color:var(--info-text);padding:12px 16px;border-radius:8px;margin-top:14px;font-size:13px">
      ℹ️ Tato sazba se použije <strong>jen u nově vystavených dokladů</strong>. Existující faktury, dodací listy a objednávky zůstávají s původním DPH.
    </div>

    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <div style="flex:1"></div>
      <button class="btn-primary" onclick="ulozitDph(${id || 'null'})">${id ? 'Uložit změny' : 'Vytvořit sazbu'}</button>
    </div>
  `);
};

window.ulozitDph = async function(id) {
  const nazev = document.getElementById('dph-nazev').value.trim();
  const sazba = parseFloat(document.getElementById('dph-sazba').value);
  const platne_od = document.getElementById('dph-platne-od').value;

  if (!nazev) return alert('Vyplňte název');
  if (isNaN(sazba) || sazba < 0 || sazba > 100) return alert('Sazba musí být 0 až 100 %');
  if (!platne_od) return alert('Vyplňte datum platnosti');

  try {
    await api('admin_sazby_dph.php', {
      method: id ? 'PUT' : 'POST',
      body: { id, nazev, sazba, platne_od },
    });
    closeModal();
    loadDph();
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

window.smazatDph = async function(id, pocet) {
  if (pocet > 0) {
    return alert(t('vat_rate_in_use', { n: pocet }));
  }
  if (!await confirmDelete2x('tuto sazbu DPH')) return;

  try {
    await api(`admin_sazby_dph.php?id=${id}`, { method: 'DELETE' });
    loadDph();
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

window.otevritMatchSlozeni = async function() {
  let preview;
  try { preview = await api('admin_match_slozeni.php?action=preview'); }
  catch (e) { return alert('Chyba: ' + e.message); }

  openModal('🔗 Párování složení → suroviny', `
    <div style="background:#EFF6FF;border:1px solid #B5D4F4;border-radius:8px;padding:12px 14px;margin-bottom:14px">
      <div style="font-size:14px;color:#0C447C;margin-bottom:4px"><strong>Sken hotov:</strong></div>
      <div style="font-size:13px;color:#0C447C">
        Výrobků: <strong>${preview.pocet_vyrobku}</strong> ·
        Naparováno se surovinami: <strong>${preview.pocet_napar}</strong> ·
        Chybí (vytvoří se): <strong>${preview.pocet_chybi}</strong> ·
        Unikátních nových: <strong>${preview.nove_suroviny.length}</strong>
      </div>
    </div>

    ${preview.nove_suroviny.length > 0 ? `
      <h3 style="font-size:14px;margin:12px 0 6px">✨ Nové suroviny k vytvoření (${preview.nove_suroviny.length})</h3>
      <div style="max-height:240px;overflow-y:auto;border:1px solid var(--border);border-radius:6px">
        <table class="table" style="font-size:12px;margin:0">
          <thead><tr><th>Název</th><th>Jednotka</th><th>Alergeny (auto)</th></tr></thead>
          <tbody>
            ${preview.nove_suroviny.map(s => `
              <tr>
                <td>${esc(s.nazev)}</td>
                <td>${esc(s.detected_jednotka)}</td>
                <td>${(s.detected_alergeny || []).length > 0 ? `<span style="background:#fef3c7;color:#92400e;font-size:11px;padding:2px 6px;border-radius:8px">${esc(s.detected_alergeny.join(', '))}</span>` : '<span style="color:var(--text-3)">—</span>'}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    ` : '<p style="color:var(--text-3);font-size:13px">Všechny suroviny ze složení už máš v databázi.</p>'}

    <div style="margin-top:14px;display:flex;flex-direction:column;gap:8px">
      <label class="checkbox-row">
        <input type="checkbox" id="ms-prepsat-alergeny" checked>
        <span>Přepsat existující alergeny u výrobků (jinak doplní jen prázdná)</span>
      </label>
      <label class="checkbox-row">
        <input type="checkbox" id="ms-jen-existujici">
        <span>Jen párovat existující suroviny (NEvytvářet nové)</span>
      </label>
    </div>

    <div class="form-actions">
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="spustitMatchSlozeni()">🔗 Spustit párování</button>
    </div>
  `, 'wide');
};

window.spustitMatchSlozeni = async function() {
  const prepsat_alergeny = document.getElementById('ms-prepsat-alergeny')?.checked || false;
  const jen_existujici = document.getElementById('ms-jen-existujici')?.checked || false;
  if (!(await confirmDialog({ title: 'Spustit párování?', msg: (jen_existujici ? 'Jen napáruje existující suroviny. ' : 'Vytvoří nové chybějící suroviny. ') + (prepsat_alergeny ? 'Přepíše alergeny u výrobků.' : 'Doplní alergeny jen kde chybí.'), okText: 'Spustit párování' }))) return;
  try {
    const r = await api('admin_match_slozeni.php?action=apply', {
      method: 'POST',
      body: JSON.stringify({ prepsat_alergeny, jen_existujici }),
    });
    closeModal();
    let report = `✓ Hotovo!\n\n`;
    report += `• Výrobků zpracováno: ${r.vyrobky_zpracovano}\n`;
    report += `• Spárováno se surovinami: ${r.naparovano_existujicich}\n`;
    report += `• Nových surovin vytvořeno: ${r.novych_surovin}\n`;
    report += `• Aktualizováno alergenů: ${r.aktualizovano_alergenu}`;
    if ((r.vytvorene_suroviny || []).length > 0) {
      report += '\n\nNové suroviny (' + r.vytvorene_suroviny.length + '):\n';
      report += r.vytvorene_suroviny.slice(0, 15).map(s => '• ' + s.nazev + (s.alergen ? ' [' + s.alergen + ']' : '')).join('\n');
      if (r.vytvorene_suroviny.length > 15) report += `\n... a ${r.vytvorene_suroviny.length - 15} dalších`;
    }
    alert(report);
    state._suroviny_cache = null; state._suroviny_full_cache = null; // Refresh cache
  } catch (e) { alert('Chyba: ' + e.message); }
};

// 🆕 v2.9.181 — modal s formulářem fixních nákladů. Otevírá se z Výroba hubu
// (předtím byl form součástí Nastavení→Výroba tabu, ten zrušen).
window.otevritFixniNaklady = async function() {
  let items = [];
  try {
    const n = await api('admin_nastaveni.php');
    items = JSON.parse(n.naklady_polozky || '[]');
    if (!Array.isArray(items)) items = [];
  } catch (e) { items = []; }
  if (items.length === 0) items = [
    { nazev: 'Energie (plyn, elektřina)', cena_kc: 0 },
    { nazev: 'Práce', cena_kc: 0 },
    { nazev: 'Obal', cena_kc: 0 },
  ];

  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;display:flex;align-items:center;justify-content:center;padding:20px';
  // 🆕 v2.9.222 — fix: background:var(--bg-1) byla neexistující proměnná → průhledný modal.
  // Použijeme stejné proměnné jako standardní .modal-card (surface + border + shadow).
  overlay.innerHTML = `
    <div class="modal" style="background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:12px;max-width:560px;width:100%;max-height:80vh;overflow:auto;padding:24px;box-shadow:0 12px 40px rgba(0,0,0,0.25)">
      <h2 style="margin:0 0 6px;font-size:20px">💰 Fixní náklady na výrobek</h2>
      <p class="page-sub" style="margin:0 0 18px;font-size:13px">
        Položky které se přičtou ke každé kalkulaci výrobku (vedle ceny surovin) — energie, práce, balení, nájem rozpočítaný na ks…
      </p>
      <div id="naklady-rows">
        ${items.map((it, i) => `
          <div class="naklad-row" data-idx="${i}" style="display:grid;grid-template-columns:1fr 140px auto;gap:8px;margin-bottom:6px;align-items:center">
            <input class="form-input" placeholder="Název položky" value="${esc(it.nazev || '')}" data-fld="nazev">
            <input class="form-input" type="number" step="0.01" placeholder="Kč" value="${it.cena_kc || 0}" data-fld="cena_kc">
            <button type="button" class="btn-danger" style="padding:6px 10px;font-size:12px" onclick="this.closest('.naklad-row').remove()">×</button>
          </div>
        `).join('')}
      </div>
      <button type="button" class="btn-secondary" style="margin-top:6px;font-size:13px" onclick="nakladPridejRadek()">+ Přidat položku</button>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:24px;border-top:1px solid var(--border);padding-top:16px">
        <button type="button" class="btn-secondary" onclick="this.closest('.modal-overlay').remove()">Zrušit</button>
        <button type="button" class="btn-primary btn-green" id="naklady-save-btn">💾 Uložit</button>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);

  document.getElementById('naklady-save-btn').onclick = async () => {
    const out = [];
    document.querySelectorAll('#naklady-rows .naklad-row').forEach(r => {
      const nazev = r.querySelector('[data-fld="nazev"]')?.value.trim() || '';
      const cena_kc = parseFloat(r.querySelector('[data-fld="cena_kc"]')?.value) || 0;
      if (nazev || cena_kc > 0) out.push({ nazev, cena_kc });
    });
    try {
      await api('admin_nastaveni.php', { method: 'PUT', body: JSON.stringify({ naklady_polozky: JSON.stringify(out) }) });
      overlay.remove();
      // 🆕 v3.0.150 — živé propojení s Výrobní kalkulací: po uložení rovnou obnov picker
      // fixních plateb (jinak se nový seznam projevil až po reloadu stránky).
      if (typeof vkState !== 'undefined') {
        vkState.sablonyFixni = out;
        if (document.querySelector('.vk-grid') && typeof vkRender === 'function') vkRender();
      }
      const toast = document.createElement('div');
      toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:14px;font-weight:500;z-index:9999';
      toast.textContent = '✓ Fixní náklady uloženy';
      document.body.appendChild(toast);
      setTimeout(() => toast.remove(), 2500);
    } catch (e) {
      alert('Chyba: ' + (e?.message || e));
    }
  };
};

window.nakladPridejRadek = function() {
  const cont = document.getElementById('naklady-rows');
  if (!cont) return;
  const idx = cont.querySelectorAll('.naklad-row').length;
  const div = document.createElement('div');
  div.className = 'naklad-row';
  div.dataset.idx = idx;
  div.style.cssText = 'display:grid;grid-template-columns:1fr 140px auto;gap:8px;margin-bottom:6px;align-items:center';
  div.innerHTML = `
    <input class="form-input" placeholder="Název položky" data-fld="nazev">
    <div style="display:flex;align-items:center;gap:4px">
      <input class="form-input" type="number" step="0.01" min="0" placeholder="0,00" data-fld="cena_kc" style="text-align:right">
      <span style="font-size:13px;color:var(--text-3)">Kč</span>
    </div>
    <button type="button" class="btn-danger" style="padding:6px 10px;font-size:12px" onclick="this.closest('.naklad-row').remove()">×</button>
  `;
  cont.appendChild(div);
};

window.ulozitNastaveni = async function() {
  // Safe gettery — vrací undefined když element neexistuje (jiný aktivní tab)
  const v  = (id) => document.getElementById(id)?.value;
  const cb = (id) => document.getElementById(id)?.checked;
  // Sestav data jen z polí které existují v aktuálně otevřeném tabu
  const data = {};
  const setIf = (k, val) => { if (val !== undefined && val !== null) data[k] = val; };

  setIf('firma_nazev', v('ns-nazev'));
  setIf('firma_ico', v('ns-ico'));
  setIf('firma_dic', v('ns-dic'));
  setIf('firma_ulice', v('ns-ulice'));
  setIf('firma_mesto', v('ns-mesto'));
  setIf('firma_psc', v('ns-psc'));
  setIf('firma_banka', v('ns-banka'));
  setIf('firma_email', v('ns-email'));
  setIf('firma_telefon', v('ns-tel'));
  setIf('firma_web', v('ns-web'));
  setIf('firma_paticka_dokladu', v('ns-paticka'));
  // 🎨 Branding
  setIf('firma_brand_color', v('ns-brand-color') || v('ns-brand-color-text'));
  setIf('firma_logo_url', v('ns-logo-url'));
  // 📄 Logo na dokladech (checkbox)
  if (document.getElementById('ns-logo-doklady')) data.firma_logo_na_dokladech = cb('ns-logo-doklady') ? '1' : '0';
  setIf('admin_email_pro_objednavky', v('ns-admin-email'));
  setIf('uzaverka_hodina', v('ns-uzaverka-h'));
  setIf('uzaverka_dni_predem', v('ns-uzaverka-d'));
  setIf('pagination_styl', v('ns-pagination')); // 🆕 v3.0.218 — styl stránkování seznamů
  setIf('pagination_pocet', v('ns-pag-pocet')); // 🆕 v3.0.247 — počet řádků na stránku
  if (document.getElementById('ns-perf-lite')) {                  // ⚡ v3.0.252 — odlehčený režim (výkon)
    const _pl = cb('ns-perf-lite');
    data.vykon_lite = _pl ? '1' : '0';
    try { localStorage.setItem('appek_perf_lite', _pl ? '1' : '0'); } catch (e) {}
    document.body.classList.toggle('perf-lite', _pl);
  }
  if (document.getElementById('ns-notif-nova')) data.notif_nova_objednavka = cb('ns-notif-nova') ? '1' : '0';
  if (document.getElementById('ns-notif-stav')) data.notif_zmena_stavu     = cb('ns-notif-stav') ? '1' : '0';
  if (document.querySelector('[data-stav-notif]')) {
    data.notif_stavy_pro_email = [...document.querySelectorAll('[data-stav-notif]:checked')].map(el => el.value).join(',');
  }
  // Náklady — jen pokud je řada viditelná
  if (document.getElementById('naklady-rows')) {
    const items = [];
    document.querySelectorAll('#naklady-rows .naklad-row').forEach(r => {
      const nazev = r.querySelector('[data-fld="nazev"]')?.value.trim() || '';
      const cena_kc = parseFloat(r.querySelector('[data-fld="cena_kc"]')?.value) || 0;
      if (nazev || cena_kc > 0) items.push({ nazev, cena_kc });
    });
    data.naklady_polozky = JSON.stringify(items);
  }
  // Validace — jen pokud je tab firma viditelný
  if (data.firma_nazev !== undefined && !String(data.firma_nazev).trim()) {
    return alert('Vyplňte alespoň název firmy');
  }
  if (Object.keys(data).length === 0) {
    return alert('Nic k uložení (žádné pole v aktivním tabu).');
  }

  try {
    await api('admin_nastaveni.php', { method: 'PUT', body: JSON.stringify(data) });
    if ('pagination_styl' in data) state._pagStyl = null; // 🆕 v3.0.218 — projeví se nový styl
    if ('pagination_pocet' in data) { state._pagStyl = null; state._pagLimit = null; } // 🆕 v3.0.247 — reload limitu
    // Hezčí toast místo alert
    const toast = document.createElement('div');
    toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:14px 22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:14px;font-weight:500;z-index:1000;';
    toast.textContent = '✓ Nastavení uloženo';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2500);
  } catch (e) { 
    alert('Chyba: ' + e.message); 
  }
};

window.testEmail = async function() {
  const inp = document.getElementById('ns-test-email');
  const result = document.getElementById('ns-test-result');
  const email = (inp.value || '').trim();
  if (!email || !email.includes('@')) {
    result.innerHTML = '<span style="color:var(--danger-text)">⚠️ Zadejte platnou emailovou adresu</span>';
    return;
  }
  result.innerHTML = '<span style="color:var(--text-3)">⏳ Odesílám…</span>';
  try {
    const r = await api('admin_nastaveni.php?action=test_email', {
      method: 'POST',
      body: JSON.stringify({ email }),
    });
    result.innerHTML = `<span style="color:var(--success-text)">✓ ${esc(r.zprava || 'Email odeslán')}</span>`;
  } catch (e) {
    result.innerHTML = `<span style="color:var(--danger-text)">✗ ${esc(e.message)}</span>`;
  }
};

