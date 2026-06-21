// =============================================================
// UŽIVATELÉ (Admini / Prodavači) - jen pro super admina
// =============================================================
async function renderUsers() {
  if (!isSuperAdmin()) {
    document.getElementById('content').innerHTML = `
      <div style="padding:40px;text-align:center;color:var(--text-3)">
        Tato sekce je pouze pro super admina.
      </div>`;
    return;
  }

  const c = document.getElementById('content');
  c.innerHTML = `<div class="page-head"><h1 class="page-title">Uživatelé</h1></div><p>Načítám…</p>`;

  try {
    // 🐛 v3.0.66 — defensive: pokud API vrátí ne-array (např. {error: "..."} or unauthorized
    // wrapper), zkusíme rozumný fallback místo crash. User: "Chyba: users.filter is not a function".
    let users = await api('admin_users.php');
    if (!Array.isArray(users)) {
      // Některé endpointy obalují do {data: [...]} nebo {users: [...]}
      if (users && Array.isArray(users.users)) users = users.users;
      else if (users && Array.isArray(users.data)) users = users.data;
      else users = [];
    }

    const roleLabel = (r) => ({
      admin:    '👑 Super admin',
      prodavac: '🛒 Prodavač',
      vyroba:   '🥖 Výroba',
      expedice: '🚚 Expedice',
      pos:      '🧾 POS kasa',
    })[r] || r;

    const roleBadgeClass = (r) =>
      r === 'admin' ? 'role-badge super' : 'role-badge';

    // 🆕 v2.9.277 — POS onboarding tooltip pokud Restaurace balíček aktivní + žádný POS user
    const hasRestaurace = window._activePackages?.includes('restaurace');
    const posUsersCount = users.filter(u => Number(u.ma_pin) === 1 || u.role === 'pos' || Number(u.pos_only) === 1).length;
    const showPosOnboarding = hasRestaurace && posUsersCount === 0;

    c.innerHTML = `
      <div class="page-head">
        <div>
          <h1 class="page-title">👥 Uživatelé</h1>
          <p class="page-sub">Správa admin přístupů a prodavačů</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <button class="btn-back" onclick="navigate('nastaveni');setTimeout(()=>{state._nastaveniTab='udrzba';renderNastaveni();},100)" title="Zpět na Údržba tab"><span class="btn-back-arrow">←</span> <span class="btn-back-lbl">Údržba</span></button>
          <button class="btn-primary btn-green btn-big-action" onclick="otevritUzivatele(null)" style="font-size:18px !important;font-weight:800 !important;padding:18px 32px !important;min-height:64px !important;border-radius:12px !important;letter-spacing:0.3px !important">+ Nový uživatel</button>
        </div>
      </div>

      ${showPosOnboarding ? `
        <div class="card-block" style="padding:16px 20px;margin-bottom:14px;background:linear-gradient(135deg,#FFFBEB,#FFF8F0);border:1px solid #F0D9B8">
          <div style="display:flex;align-items:start;gap:14px;flex-wrap:wrap">
            <div style="font-size:36px;line-height:1;flex-shrink:0">🧾</div>
            <div style="flex:1;min-width:220px">
              <h3 style="margin:0 0 6px;font-size:15px;color:#854F0B">POS kasa — onboarding tip</h3>
              <p style="margin:0 0 10px;font-size:13px;color:#854F0B;line-height:1.5">
                Aktivovali jste <strong>🍕 Restaurace</strong> balíček. Pro plný POS workflow potřebujete uživatele s <strong>PIN</strong> (4-6 cifer) pro přihlášení do kasy bez hesla.
              </p>
              <details style="margin-bottom:10px">
                <summary style="cursor:pointer;font-size:12px;font-weight:600;color:#854F0B;padding:4px 0">📖 Jak to funguje?</summary>
                <ul style="margin:6px 0 0 18px;font-size:12px;color:#854F0B;line-height:1.6">
                  <li><strong>Role POS kasa</strong> — uživatel vidí jen POS terminál (žádný admin)</li>
                  <li><strong>PIN login</strong> — místo emailu+hesla zadá 4-6 cifer na touch keypadu</li>
                  <li><strong>„Pouze POS" checkbox</strong> — uživatel se NEDOSTANE do /admin/, jen do /pos/</li>
                  <li><strong>Admin s PIN</strong> — admin se může přihlásit do POS přes PIN i přes heslo do admin</li>
                  <li><strong>Rate-limit</strong> — 5 selhaných pokusů / 15 min / IP</li>
                </ul>
              </details>
              <button class="btn-primary btn-green" onclick="otevritUzivatele(null)" style="font-size:13px;padding:8px 16px">
                + Vytvořit POS uživatele s PIN
              </button>
            </div>
          </div>
        </div>
      ` : ''}

      <!-- Desktop: tabulka -->
      <div class="card-block desktop-only-block" style="padding:0">
        <table class="table">
          <thead>
            <tr>
              <th>Jméno</th>
              <th>Email</th>
              <th>Role</th>
              <th>POS</th>
              <th>Stav</th>
              <th>Poslední přihlášení</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            ${users.map((u) => `
              <tr onclick="otevritUzivatele(${u.id})" style="cursor:pointer">
                <td><strong>${esc(u.jmeno || '—')}</strong></td>
                <td>${esc(u.email)}</td>
                <td><span class="${roleBadgeClass(u.role)}">${esc(roleLabel(u.role))}</span></td>
                <td>
                  ${Number(u.ma_pin) === 1 ? '<span class="status dorucena" title="PIN nastaven">🧾 PIN</span>' : '<span style="color:var(--text-3);font-size:11px">—</span>'}
                  ${Number(u.pos_only) === 1 ? '<br><span class="status zrusena" style="font-size:10px;margin-top:2px" title="Pouze POS, žádný admin">🔒 POS only</span>' : ''}
                </td>
                <td>${u.aktivni == 1
                    ? '<span class="status dorucena">Aktivní</span>'
                    : '<span class="status zrusena">Deaktivovaný</span>'}</td>
                <td style="color:var(--text-3); font-size:13px">
                  ${u.posledni_prihlaseni ? fmtDateTime(u.posledni_prihlaseni) : (u.posledni_pos_login ? '🧾 ' + fmtDateTime(u.posledni_pos_login) : '—')}
                </td>
                <td onclick="event.stopPropagation();" style="text-align:right">
                  ${u.id != state.admin.id ? `
                    <button class="btn-danger" style="font-size:11px;padding:4px 10px;"
                            onclick="smazatUzivatele(${u.id}, '${esc(u.jmeno || u.email).replace(/'/g, '')}')">
                      Smazat
                    </button>
                  ` : '<span style="color:var(--text-3); font-size:11px">Vy</span>'}
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>

      <!-- Mobile: kompaktní karty -->
      <div class="mobile-only-block">
        ${users.length === 0 ? '<div class="card-block"><div class="empty-state">Žádní uživatelé</div></div>' :
          users.map((u) => {
            const initials = (u.jmeno || u.email || '?').trim()
              .split(/\s+/).map(s => s[0]).join('').slice(0, 2).toUpperCase();
            return `
              <div class="user-card" onclick="otevritUzivatele(${u.id})">
                <div class="user-card-head">
                  <div class="user-card-avatar">${esc(initials || '?')}</div>
                  <div class="user-card-title">
                    <div class="user-card-jmeno">${esc(u.jmeno || '—')}${u.id == state.admin.id ? ' <span class="user-card-vy">(vy)</span>' : ''}</div>
                    <div class="user-card-email">${esc(u.email)}</div>
                  </div>
                </div>

                <div class="user-card-badges">
                  <span class="${roleBadgeClass(u.role)}">${esc(roleLabel(u.role))}</span>
                  ${u.aktivni == 1
                    ? '<span class="status dorucena">Aktivní</span>'
                    : '<span class="status zrusena">Deaktivovaný</span>'}
                </div>

                <div class="user-card-foot">
                  <div class="user-card-prihlaseni">
                    ${u.posledni_prihlaseni
                      ? `🕐 ${fmtDateTime(u.posledni_prihlaseni)}`
                      : '<span style="color:var(--text-3)">Ještě se nepřihlásil</span>'}
                  </div>
                  ${u.id != state.admin.id ? `
                    <button class="btn-danger user-card-smazat"
                            onclick="event.stopPropagation();smazatUzivatele(${u.id}, '${esc(u.jmeno || u.email).replace(/'/g, '')}')">
                      Smazat
                    </button>
                  ` : ''}
                </div>
              </div>
            `;
          }).join('')
        }
      </div>

      <!-- 🔐 PRÁVA V MENU pro role -->
      <div id="role-prava-block" style="margin-top:24px"></div>
    `;

    // Načti a vykresli panel s právy
    nactiAVykresliPrava();
  } catch (e) {
    c.innerHTML = `<div style="color:var(--danger-text);padding:20px">Chyba: ${esc(e.message)}</div>`;
  }
}

