// =============================================================
// 🔐 Práva v menu — editor pro role
// =============================================================
async function nactiAVykresliPrava() {
  const blok = document.getElementById('role-prava-block');
  if (!blok) return;
  blok.innerHTML = '<div class="card-block" style="padding:14px">⏳ Načítám práva…</div>';
  try {
    const r = await api('admin_role_prava.php');
    state.rolePrava = r.prava;
    state.rolePravaDefaults = r.defaults;
    vykresliPravaPanel();
  } catch (e) {
    blok.innerHTML = `<div class="card-block" style="padding:14px;color:var(--danger-text)">Chyba: ${esc(e.message)}</div>`;
  }
}

function vykresliPravaPanel() {
  const blok = document.getElementById('role-prava-block');
  if (!blok) return;
  const role_meta = [
    { key: 'admin',    label: '👑 Super admin', popis: 'Vidí všechno — needitovatelné',  readonly: true },
    { key: 'prodavac', label: '🛒 Prodavač',     popis: 'Obvykle objednávky, DL, faktury, výrobky' },
    { key: 'vyroba',   label: '🥖 Výroba',       popis: 'Výrobní list, výrobky, HACCP' },
    { key: 'expedice', label: '🚚 Expedice',     popis: 'Jen objednávky a dodací listy' },
    { key: 'pos',      label: '🧾 POS kasa',     popis: 'Pouze POS terminál (/pos/), žádný admin přístup' },
  ];

  blok.innerHTML = `
    <div class="card-block" style="padding:16px">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px">
        <div>
          <h2 style="margin:0;font-size:18px;color:var(--text-1)">🔐 Práva v menu podle role</h2>
          <p style="margin:4px 0 0;font-size:13px;color:var(--text-3)">
            Zaškrtni, které položky menu uvidí jednotlivé role po přihlášení. Super admin vidí vždy všechno.
          </p>
        </div>
        <div style="display:flex;gap:6px">
          <button class="btn-secondary" onclick="resetovatPrava()" style="font-size:13px">↺ Výchozí</button>
          <button class="btn-primary btn-green" onclick="ulozitPrava()" style="font-size:13px">💾 Uložit změny</button>
        </div>
      </div>

      <div class="role-prava-grid">
        ${role_meta.map((r) => {
          const allowed = new Set(state.rolePrava[r.key] || []);
          return `
            <div class="role-prava-col ${r.readonly ? 'is-readonly' : ''}">
              <div class="role-prava-head">
                <strong>${r.label}</strong>
                <small>${esc(r.popis)}</small>
              </div>
              <div class="role-prava-pages">
                ${ALL_NAV_PAGES.filter(p => !p.hidden).map((p) => {
                  const checked = allowed.has(p.key);
                  const disabled = r.readonly || p.key === 'dashboard';
                  return `
                    <label class="role-prava-page ${checked ? 'is-checked' : ''} ${disabled ? 'is-disabled' : ''}">
                      <input type="checkbox" data-role="${r.key}" data-page="${p.key}"
                             ${checked ? 'checked' : ''} ${disabled ? 'disabled' : ''}
                             onchange="zmenitPravo('${r.key}', '${p.key}', this.checked)">
                      <span class="role-prava-page-icon">${p.icon}</span>
                      <span class="role-prava-page-label">${esc(p.label)}</span>
                    </label>
                  `;
                }).join('')}
              </div>
            </div>
          `;
        }).join('')}
      </div>
    </div>
  `;
}

window.zmenitPravo = function(role, page, checked) {
  if (!state.rolePrava[role]) state.rolePrava[role] = [];
  const set = new Set(state.rolePrava[role]);
  if (checked) set.add(page);
  else set.delete(page);
  // Dashboard je vždy povinný
  set.add('dashboard');
  state.rolePrava[role] = Array.from(set);
  // Aktualizuj label styl (checked class)
  const lbl = document.querySelector(`.role-prava-page input[data-role="${role}"][data-page="${page}"]`)?.closest('.role-prava-page');
  if (lbl) lbl.classList.toggle('is-checked', checked);
};

window.ulozitPrava = async function() {
  try {
    const r = await api('admin_role_prava.php', {
      method: 'POST',
      body: JSON.stringify({ prava: state.rolePrava }),
    });
    if (r?.prava) state.rolePrava = r.prava;
    // Pokud zobrazujeme menu pro vlastní roli, aplikuj okamžitě
    aplikovatPravaNaMenu();
    alert('✓ Práva uložena. Změny se projeví okamžitě.');
  } catch (e) {
    alert('Chyba ukládání: ' + e.message);
  }
};

window.resetovatPrava = async function() {
  if (!(await confirmDialog({ title: 'Obnovit výchozí práva?', msg: 'Pro všechny role — přepíšeš všechny vlastní úpravy.', okText: 'Obnovit výchozí', danger: true }))) return;
  state.rolePrava = JSON.parse(JSON.stringify(state.rolePravaDefaults || DEFAULT_ROLE_PRAVA));
  vykresliPravaPanel();
};

window.otevritUzivatele = async function(id) {
  let u = { id: null, email: '', jmeno: '', role: 'prodavac', aktivni: 1 };

  if (id) {
    try {
      const all = await api('admin_users.php');
      u = all.find((x) => x.id == id) || u;
    } catch (e) { alert('Chyba: ' + e.message); return; }
  }

  const isSelf = id && id == state.admin.id;
  const isNew = !id;
  const initials = (u.jmeno || u.email || '?').trim()
    .split(/\s+/).map(s => s[0]).join('').slice(0, 2).toUpperCase() || '?';

  // Role definice — drží se beckendu (admin, prodavac, vyroba, expedice, pos)
  const roleDef = {
    admin:    { label: 'Super admin',  emoji: '👑', desc: 'Plný přístup včetně mazání faktur, objednávek, výrobků' },
    prodavac: { label: 'Prodavač',     emoji: '🛒', desc: 'Vidí vše, vystavuje doklady, ale nesmí mazat' },
    vyroba:   { label: 'Výroba',       emoji: '🥖', desc: 'Pro pekaře — výrobní list, objednávky' },
    expedice: { label: 'Expedice',     emoji: '🚚', desc: 'Pro řidiče — dodací listy, expedice' },
    pos:      { label: 'POS kasa',     emoji: '🧾', desc: 'Pro obsluhu kasy — pouze POS terminál (žádný admin)' },
  };

  openModal(isNew ? '+ Nový uživatel' : `✏️ Upravit: ${u.jmeno || u.email}`, `
    ${!isNew ? `
      <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;background:var(--surface-2);border-radius:10px;margin-bottom:14px">
        <div style="width:44px;height:44px;border-radius:50%;background:var(--primary-light);color:var(--primary-dark);display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:700;flex-shrink:0">${esc(initials)}</div>
        <div style="flex:1;min-width:0">
          <div style="font-size:14px;font-weight:600;line-height:1.2">${esc(u.jmeno || u.email)}</div>
          <div style="font-size:12px;color:var(--text-3);margin-top:2px">${esc(u.email)}</div>
        </div>
        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
          <span class="${u.role === 'admin' ? 'role-badge super' : 'role-badge'}">${esc(roleDef[u.role]?.label || u.role)}</span>
          ${u.aktivni == 1 ? '<span class="status dorucena">Aktivní</span>' : '<span class="status zrusena">Deaktivovaný</span>'}
        </div>
        ${u.posledni_prihlaseni ? `
          <div style="text-align:right;font-size:11px;color:var(--text-3);border-left:1px solid var(--border);padding-left:12px">
            <div style="text-transform:uppercase;letter-spacing:0.5px">Naposledy</div>
            <div style="font-size:12px;color:var(--text-2);font-weight:500;margin-top:2px">${fmtDateTime(u.posledni_prihlaseni)}</div>
          </div>
        ` : ''}
      </div>
    ` : ''}

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
      <!-- Identita -->
      <div style="background:#F7F8FA;border:1px solid #E8D5B0;border-radius:8px;padding:12px 14px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#854F0B;margin-bottom:8px;font-weight:600">👤 Identita</div>
        <label class="form-label" style="margin-bottom:2px">Jméno</label>
        <input class="form-input" id="u-jmeno" value="${esc(u.jmeno || '')}" placeholder="Karel Novák" style="margin-bottom:8px">
        <label class="form-label" style="margin-bottom:2px">Email *</label>
        <input class="form-input" id="u-email" type="email" value="${esc(u.email || '')}" placeholder="jmeno@appek.cz" required>
      </div>

      <!-- Role a stav -->
      <div style="background:#EFF6FF;border:1px solid #B5D4F4;border-radius:8px;padding:12px 14px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#0C447C;margin-bottom:8px;font-weight:600">🔐 Role a přístup</div>
        <label class="form-label" style="margin-bottom:2px">Role *</label>
        <select class="form-select" id="u-role" ${isSelf ? 'disabled' : ''} onchange="document.getElementById('u-role-desc').textContent=this.options[this.selectedIndex].dataset.desc||''">
          ${Object.entries(roleDef).map(([k, v]) => `
            <option value="${k}" data-desc="${esc(v.desc)}" ${u.role === k ? 'selected' : ''}>${v.emoji} ${esc(v.label)}</option>
          `).join('')}
        </select>
        <div id="u-role-desc" style="font-size:11px;color:#0C447C;margin-top:6px;line-height:1.4;min-height:32px">${esc(roleDef[u.role]?.desc || '')}</div>
        ${isSelf ? '<small style="display:block;color:#A04D4D;font-size:11px;margin-top:4px">⚠ Sám sobě nemůžete měnit roli</small>' : ''}
        ${!isNew && !isSelf ? `
          <label class="checkbox-row" style="padding:8px 0 0;margin:0;border-top:1px solid #D5E5F5;margin-top:8px">
            <input type="checkbox" id="u-aktivni" ${u.aktivni == 1 ? 'checked' : ''}>
            <span style="font-size:13px;color:var(--text-2)">Účet je aktivní</span>
          </label>
        ` : ''}
      </div>
    </div>

    <!-- Heslo + PIN -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
      <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:8px;padding:12px 14px">
        <label class="form-label" style="margin-bottom:4px">🔑 Heslo${isNew ? ' *' : ''}</label>
        <input class="form-input" id="u-heslo" type="password" placeholder="${isNew ? 'min. 6 znaků' : 'Nechte prázdné'}">
        <div style="font-size:11px;color:var(--text-3);margin-top:6px;line-height:1.4">Pro přihlášení do adminu</div>
      </div>
      <!-- 🆕 v2.9.270 — PIN pro POS kasu -->
      <div style="background:#FFF8F0;border:1px solid #F0D9B8;border-radius:8px;padding:12px 14px">
        <label class="form-label" style="margin-bottom:4px">🧾 PIN pro POS kasu</label>
        <input class="form-input" id="u-pin" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6"
               autocomplete="off"
               placeholder="${u.ma_pin ? '••••  (nechte prázdné)' : '4-6 cifer'}"
               style="font-family:monospace;letter-spacing:4px;font-size:16px">
        <div style="font-size:11px;color:#854F0B;margin-top:6px;line-height:1.4">
          ${u.ma_pin ? '✓ PIN nastaven · zadejte nový nebo „null" pro smazání' : 'Bez PIN se nepřihlásí do POS'}
        </div>
      </div>
    </div>

    <!-- 🆕 v2.9.270 — POS-only checkbox -->
    ${!isSelf ? `
    <div style="background:#FFF5F5;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;margin-bottom:14px">
      <label class="checkbox-row" style="padding:0;margin:0;align-items:flex-start;gap:10px;cursor:pointer">
        <input type="checkbox" id="u-pos-only" ${Number(u.pos_only) === 1 ? 'checked' : ''} style="margin-top:2px">
        <span style="flex:1">
          <strong style="font-size:13px;color:#7F1D1D">🔒 Pouze POS terminál</strong>
          <span style="display:block;font-size:11px;color:#991B1B;margin-top:2px;line-height:1.4">
            Uživatel se nepřihlásí do administrace, jen do POS kasy přes PIN. Vyžaduje nastavený PIN.
          </span>
        </span>
      </label>
    </div>
    ` : ''}

    <div class="form-actions">
      ${!isNew && !isSelf ? `<div class="form-actions-icons-row"><button class="btn-danger-corner" onclick="smazatUzivatele(${u.id}, '${esc(u.jmeno || u.email).replace(/'/g, '')}')" title="Smazat uživatele" aria-label="Smazat uživatele">🗑️</button></div>` : ''}
      <div style="flex:1"></div>
      <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
      <button class="btn-primary btn-green" onclick="ulozitUzivatele(${u.id || 'null'})">${isNew ? '✓ Vytvořit uživatele' : '💾 Uložit změny'}</button>
    </div>
  `);
};

window.ulozitUzivatele = async function(id) {
  const data = {
    jmeno: document.getElementById('u-jmeno').value.trim(),
    email: document.getElementById('u-email').value.trim(),
    role:  document.getElementById('u-role').value,
  };
  const heslo = document.getElementById('u-heslo').value;
  if (heslo) data.heslo = heslo;

  const aktivniEl = document.getElementById('u-aktivni');
  if (aktivniEl) data.aktivni = aktivniEl.checked ? 1 : 0;

  // 🆕 v2.9.270 — PIN + pos_only
  const pinEl = document.getElementById('u-pin');
  if (pinEl) {
    const pinVal = pinEl.value.trim();
    if (pinVal === 'null' || pinVal === '__clear__') {
      data.pin = '__clear__';
    } else if (pinVal !== '') {
      if (!/^\d{4,6}$/.test(pinVal)) {
        return alert('PIN musí mít 4-6 cifer (jen čísla). Zadejte „null" pro smazání PIN.');
      }
      data.pin = pinVal;
    }
  }
  const posOnlyEl = document.getElementById('u-pos-only');
  if (posOnlyEl) data.pos_only = posOnlyEl.checked ? 1 : 0;

  if (!data.email) return alert('Email je povinný');
  if (!id && !heslo) return alert('Při vytváření je heslo povinné');
  if (data.pos_only === 1 && !data.pin && !id) {
    return alert('„Pouze POS" vyžaduje PIN');
  }

  try {
    if (id) {
      data.id = id;
      await api('admin_users.php', { method: 'PUT', body: JSON.stringify(data) });
    } else {
      await api('admin_users.php', { method: 'POST', body: JSON.stringify(data) });
    }
    closeModal();
    navigate('users');
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

window.smazatUzivatele = async function(id, jmeno) {
  if (!await confirmDelete2x({ co: `uživatele "${jmeno}"`, detail: 'Uživatel se nebude moci přihlásit. Historické záznamy jeho akcí (audit log) zůstanou zachovány.' })) return;
  try {
    await api(`admin_users.php?id=${id}`, { method: 'DELETE' });
    navigate('users');
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

