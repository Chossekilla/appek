// =============================================================
// 🆕 v2.9.270 — KATEGORIE SUROVIN editor (3-col grid modal)
// =============================================================
const EMOJI_PRESETS = ['🌾','🥨','🧈','🥛','🥚','🍬','🍞','🧂','🥜','🍎','🍫','🥧','🌈','💧','📦','🍇','🍷','🥥','🍯','🌶️','🥕','🌿','🍋','🍓'];

window.otevritKategorieSurovin = function() {
  // Pokud nikdy neproběhl load, načti teď a otevři po loadu
  if (!state._katsLoaded) {
    state._katsLoaded = true;
    loadSurovinaKategorie().then(() => renderKategorieSurovinModal());
  } else {
    renderKategorieSurovinModal();
  }
};

function renderKategorieSurovinModal() {
  // Workng copy — pracujeme s clonem, save → propíše do SUROVINA_KATEGORIE
  state._katsDraft = SUROVINA_KATEGORIE.map(k => ({ ...k, kw: [...k.kw] }));

  const html = `
    <div style="display:flex;flex-direction:column;gap:14px">
      <div style="padding:12px 14px;background:#FFF8F0;border:1px solid #F0D9B8;border-radius:10px;font-size:13px;color:#854F0B;line-height:1.5">
        💡 <strong>Tip:</strong> Klíčová slova rozhodují, do jaké kategorie surovina spadne (porovnává se s názvem + složením, case-insensitive). Změny se uloží do databáze a aplikují i pro ostatní uživatele.
      </div>
      <div id="kats-grid" class="kats-grid"></div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn-secondary" onclick="pridatKategoriiSurovin()">➕ Přidat kategorii</button>
        <button class="btn-secondary" onclick="obnovitVychoziKategorie()" title="Vrátí výchozí systémové kategorie (ztratíš vlastní úpravy)">🔄 Obnovit výchozí</button>
        <div style="flex:1"></div>
        <button class="btn-secondary" onclick="closeModal()">Zrušit</button>
        <button class="btn-primary btn-green" onclick="ulozitKategorieSurovin()">💾 Uložit kategorie</button>
      </div>
    </div>
  `;
  openModal('📂 Kategorie surovin', html);
  renderKategoriGrid();
}

function renderKategoriGrid() {
  const grid = document.getElementById('kats-grid');
  if (!grid) return;
  const arr = state._katsDraft || [];
  grid.innerHTML = arr.map((k, idx) => `
    <div class="kat-card" data-idx="${idx}">
      <div class="kat-card-head">
        <button class="kat-emoji-btn" onclick="vyberEmojiPro(${idx})" title="Změnit emoji">${esc(k.icon)}</button>
        <input class="form-input kat-label" type="text" value="${esc(k.label)}"
               placeholder="Název kategorie"
               oninput="state._katsDraft[${idx}].label = this.value">
        <button class="btn-danger" style="font-size:11px;padding:4px 8px"
                onclick="smazatKategoriiSurovin(${idx})" title="Smazat tuto kategorii">🗑️</button>
      </div>
      <div class="kat-card-body">
        <label class="form-label" style="font-size:11px;margin:0 0 4px">Klíčová slova (oddělené čárkou)</label>
        <textarea class="form-input kat-kw" rows="3"
                  placeholder="např: mouk, krupic, otrub"
                  oninput="state._katsDraft[${idx}].kw = this.value.split(',').map(s=>s.trim().toLowerCase()).filter(Boolean)">${esc(k.kw.join(', '))}</textarea>
        <div style="font-size:10px;color:var(--text-3);margin-top:4px">key: <code>${esc(k.key)}</code> · ${k.kw.length} klíč. slov</div>
      </div>
    </div>
  `).join('');
}

window.vyberEmojiPro = function(idx) {
  const current = state._katsDraft[idx]?.icon || '📦';
  const html = `
    <div style="display:grid;grid-template-columns:repeat(8,1fr);gap:8px;padding:8px 0">
      ${EMOJI_PRESETS.map(e => `
        <button class="emoji-pick ${e === current ? 'is-active' : ''}"
                onclick="zvolEmoji(${idx}, '${e}')">${e}</button>
      `).join('')}
    </div>
    <div style="margin-top:14px">
      <label class="form-label">Vlastní emoji nebo text</label>
      <input class="form-input" id="emoji-custom" value="${esc(current)}" maxlength="4" style="font-size:22px;text-align:center">
      <button class="btn-primary btn-green" style="margin-top:8px;width:100%"
              onclick="zvolEmoji(${idx}, document.getElementById('emoji-custom').value)">Použít</button>
    </div>
  `;
  openModal('🎨 Vyberte emoji', html);
};

window.zvolEmoji = function(idx, emoji) {
  if (state._katsDraft[idx]) {
    state._katsDraft[idx].icon = emoji.trim() || '📦';
  }
  closeModal();
  // Re-open kategorie modal
  setTimeout(() => renderKategorieSurovinModal(), 50);
};

window.pridatKategoriiSurovin = function() {
  const newKey = 'kat_' + Math.random().toString(36).slice(2, 7);
  state._katsDraft.push({
    key: newKey,
    icon: '📦',
    label: 'Nová kategorie',
    kw: [],
  });
  renderKategoriGrid();
  // Scroll na poslední přidanou
  setTimeout(() => {
    const last = document.querySelectorAll('.kat-card');
    if (last.length) last[last.length - 1].scrollIntoView({ behavior: 'smooth', block: 'center' });
  }, 50);
};

window.smazatKategoriiSurovin = async function(idx) {
  const k = state._katsDraft[idx];
  if (!k) return;
  if (k.key === 'ostatni') {
    alert('Kategorii „Ostatní" nelze smazat — slouží jako fallback pro nezařazené suroviny.');
    return;
  }
  if (!(await confirmDialog({ msg: t('confirm_delete_category', { label: k.label }), danger: false }))) return;
  state._katsDraft.splice(idx, 1);
  renderKategoriGrid();
};

window.obnovitVychoziKategorie = async function() {
  if (!(await confirmDialog({ msg: 'Obnovit výchozí systémové kategorie? Ztratíš vlastní úpravy.', danger: true }))) return;
  state._katsDraft = SUROVINA_KATEGORIE_DEFAULTS.map(k => ({ ...k, kw: [...k.kw] }));
  renderKategoriGrid();
};

window.ulozitKategorieSurovin = async function() {
  const draft = state._katsDraft || [];
  if (draft.length === 0) return alert('Musí zůstat alespoň jedna kategorie');
  // Validate — zajistit fallback 'ostatni'
  if (!draft.find(k => k.key === 'ostatni')) {
    draft.push({ key: 'ostatni', icon: '📦', label: 'Ostatní', kw: [] });
  }
  // Validate unique keys
  const keys = new Set();
  for (const k of draft) {
    if (keys.has(k.key)) {
      // Re-key duplicate
      k.key = 'kat_' + Math.random().toString(36).slice(2, 7);
    }
    keys.add(k.key);
  }
  try {
    await api('admin_nastaveni.php', {
      method: 'PUT',
      body: JSON.stringify({ suroviny_kategorie: JSON.stringify(draft) }),
    });
    SUROVINA_KATEGORIE = draft.map(k => ({ ...k, kw: [...k.kw] }));
    _katCache.clear();
    state._suroviny_full_cache = null; // invalidace cache aby kategoriziujSurovinu re-run
    closeModal();
    alert('✓ Kategorie uloženy');
    if (typeof renderSuroviny === 'function') renderSuroviny();
  } catch (e) {
    alert('Chyba ukládání: ' + e.message);
  }
};

