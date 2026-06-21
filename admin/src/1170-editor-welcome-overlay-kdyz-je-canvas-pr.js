// =============================================================
// EDITOR: welcome overlay když je canvas prázdný — sjednocený rozcestník
// =============================================================
function ed_renderWelcome() {
  const maSablony = stEditor.ulozeneSablony && stEditor.ulozeneSablony.length > 0;
  const maCenovkaConfig = stState.cenovkaPole && Object.values(stState.cenovkaPole).some(v => v);
  return `
    <div class="ed-welcome" style="position:absolute;inset:0;background:rgba(255,255,255,0.96);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:14px;text-align:center;border:2px dashed #d4d4d4;border-radius:6px;z-index:5">
      <div style="font-size:32px;margin-bottom:6px">🛠️</div>
      <div style="font-weight:700;font-size:14px;margin-bottom:4px;color:var(--text-2)">Prázdné plátno</div>
      <div style="font-size:11px;color:var(--text-3);margin-bottom:12px;max-width:320px;line-height:1.4">Začni jednou z těchto cest — všechny vyplní plátno prvky, můžeš pak pohejbat.</div>
      <div style="display:flex;flex-direction:column;gap:6px;min-width:240px">
        <button class="btn-primary" onclick="event.stopPropagation();ed_otevritVyberVyrobku()" style="font-size:13px;justify-content:center">📥 Načíst data z výrobku</button>
        ${maCenovkaConfig ? `<button class="btn-secondary" onclick="event.stopPropagation();ed_zCenovkaConfig()" style="font-size:12px">🧱 Vygenerovat z cenovky („Co bude na cenovce")</button>` : ''}
        ${maSablony ? `<button class="btn-secondary" onclick="event.stopPropagation();ed_otevritVyberSablony()" style="font-size:12px">📂 Otevřít uloženou šablonu</button>` : ''}
        <button class="btn-secondary" onclick="event.stopPropagation();ed_zacitPrazdny()" style="font-size:12px;color:var(--text-3)">✨ Začít prázdný (přidat prvky ručně)</button>
      </div>
    </div>
  `;
}

// Akce welcome: vygenerovat z cenovkaPole
window.ed_zCenovkaConfig = function() {
  // Použij existující helper z cenovek
  stOtevritEditorZeCenovky();
};

// Akce welcome: vybrat uloženou šablonu (modal)
window.ed_otevritVyberSablony = function() {
  const list = stEditor.ulozeneSablony || [];
  openModal('📂 Otevřít uloženou šablonu', `
    <p style="font-size:13px;color:var(--text-2);margin-bottom:12px">Vyber šablonu — načte se do editoru s prvky.</p>
    <div style="max-height:50vh;overflow:auto;border:1px solid var(--border);border-radius:6px">
      <table class="table" style="margin:0;font-size:13px">
        <thead><tr><th>Název</th><th>Formát</th><th></th></tr></thead>
        <tbody>
          ${list.map(t => `
            <tr>
              <td><strong>${esc(t.nazev)}</strong></td>
              <td style="font-size:11px;color:var(--text-3)">${esc(t.format_id || '—')}</td>
              <td><button class="btn-primary" onclick="closeModal();ed_nacist(${t.id})" style="font-size:12px;padding:4px 10px">📂 Otevřít</button></td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
      <!-- "Zavřít" smazáno v v2.5.11 — × v rohu modal-card stačí (větší klikací plocha) -->
    </div>
  `);
};

// Akce welcome: nechat prázdné (skryj overlay tím, že přidáš dummy prvek? — nemusíme, stačí ignorovat)
window.ed_zacitPrazdny = function() {
  // Přidej průhledný „neviditelný" prvek (cara s minimální velikostí) aby welcome zmizel
  // ALE lepší: přidat reálný prvek text který si user upraví
  ed_pridat('text');
};

// =============================================================
// AUTO-TRANSFORM v2: chytrý auto-fit
//   1) měření reálné šířky textu canvasem (na základě skutečných dat výrobku)
//   2) font sized dle min(širka, výška) prvku — nikdy nevyleze ven
//   3) detekce a řešení překryvů — posune kolize dolů
//   4) clamp do plátna
// =============================================================

// Helper: zmeří šířku textu pomocí canvasu (1 mm ≈ 3.7795 px @ 96 dpi)
function ed_measureTextW(text, fontSizePt, fontWeight) {
  if (!text) return 0;
  const c = ed_measureTextW._c || (ed_measureTextW._c = document.createElement('canvas'));
  const ctx = c.getContext('2d');
  ctx.font = `${fontWeight || 400} ${fontSizePt}pt -apple-system, "Helvetica Neue", Arial, sans-serif`;
  return ctx.measureText(text).width; // px
}

// Najde max fontSize tak, aby text vlezl do dané šířky v mm
function ed_maxFontByWidth(text, widthMm, fontWeight) {
  if (!text) return 100;
  const widthPx = widthMm * 3.7795;
  let lo = 4, hi = 100;
  while (hi - lo > 0.3) {
    const mid = (lo + hi) / 2;
    const w = ed_measureTextW(text, mid, fontWeight);
    if (w <= widthPx) lo = mid; else hi = mid;
  }
  return lo;
}

// Vrátí konkrétní text který se vykreslí — buď p.text, nebo data z previewVyrobek
function ed_textProPrvek(p) {
  if (p.text) return p.text;
  const v = stEditor.previewVyrobek;
  if (!v) {
    const def = PALETA_PRVKY.find(x => x.typ === p.typ);
    return def?.d || '';
  }
  switch (p.typ) {
    case 'nazev': return v.nazev || '';
    case 'cena': {
      const sazba = parseFloat(v.sazba_dph) || 12;
      return ((parseFloat(v.cena_bez_dph) || 0) * (1 + sazba / 100)).toFixed(2).replace('.', ',');
    }
    case 'mena': return 'Kč';
    case 'jed': return v.jednotka_kod ? `za ${v.jednotka_kod}` : 'za ks';
    case 'hmotnost':
      if (v.obsah && v.obsah_jednotka) return `${parseFloat(v.obsah).toString().replace('.', ',')} ${v.obsah_jednotka}`;
      return v.hmotnost_g ? `${v.hmotnost_g} g` : '';
    case 'cenakg': {
      const sazba = parseFloat(v.sazba_dph) || 12;
      const cs = (parseFloat(v.cena_bez_dph) || 0) * (1 + sazba / 100);
      let g = 0;
      if (v.obsah && v.obsah_jednotka) {
        const oj = String(v.obsah_jednotka).toLowerCase();
        if (oj === 'kg' || oj === 'l') g = parseFloat(v.obsah) * 1000;
        else if (oj === 'g' || oj === 'ml') g = parseFloat(v.obsah);
      } else if (v.hmotnost_g) g = parseFloat(v.hmotnost_g);
      return g ? `${(cs * 1000 / g).toFixed(2).replace('.', ',')} Kč/kg` : '—';
    }
    case 'dph': return `DPH ${parseFloat(v.sazba_dph || 12)}%`;
    case 'slozeni': return (typeof v.slozeni === 'string' ? v.slozeni : (v.slozeni_text || '')).slice(0, 200);
    case 'alergeny': return v.alergeny ? `Alergeny: ${v.alergeny}` : '';
    // 🥗 v2.9.302 — Nutri (na 100 g)
    case 'nutri':      return nutriPrvekText('nutri', v)      || '';
    case 'nutri_kj':   return nutriPrvekText('nutri_kj', v)   || '';
    case 'nutri_kcal': return nutriPrvekText('nutri_kcal', v) || '';
    case 'nutri_tuky': return nutriPrvekText('nutri_tuky', v) || '';
    case 'nutri_sach': return nutriPrvekText('nutri_sach', v) || '';
    case 'nutri_bilk': return nutriPrvekText('nutri_bilk', v) || '';
    case 'nutri_sul':  return nutriPrvekText('nutri_sul', v)  || '';
    case 'kod': return v.cislo ? `kód ${v.cislo}` : '';
    case 'badge': return 'NOVINKA';
    case 'ean': return v.ean || '';
    default: return '';
  }
}

// Detekce překryvu dvou prvků v procentech
function ed_prvkyOverlap(a, b) {
  return !(
    a.x_pct + a.w_pct <= b.x_pct ||
    b.x_pct + b.w_pct <= a.x_pct ||
    a.y_pct + a.h_pct <= b.y_pct ||
    b.y_pct + b.h_pct <= a.y_pct
  );
}

window.ed_autoTransform = async function() {
  if (!stEditor.prvky.length) return;
  if (!(await confirmDialog({ msg: '🪄 Chytrý auto-fit:\n\n• Přizpůsobí fontSize textu šířce + výšce prvku\n• Vyřeší překryvy posunutím dolů\n• Ořízne prvky přes okraj\n\nPokračovat?', danger: false }))) return;

  const fmt = STITKY_FORMATY.find(f => f.id === stEditor.formatId) || STITKY_FORMATY[0];

  // Ratio výška × ratio = max fontSize (1 pt ≈ 0.353 mm; pro ~70 % výplň → 1.85)
  const ratioPerTyp = {
    nazev: 1.85, cena: 2.20, mena: 1.55, jed: 1.30, cenakg: 1.30,
    hmotnost: 1.55, dph: 1.30, slozeni: 1.10, alergeny: 1.10,
    kod: 1.10, badge: 1.55, text: 1.55,
    // 🥗 v2.9.302 — Nutri (krátké texty → větší font; full tabulka → menší)
    nutri: 1.05, nutri_kj: 1.45, nutri_kcal: 1.45,
    nutri_tuky: 1.35, nutri_sach: 1.35, nutri_bilk: 1.35, nutri_sul: 1.35,
  };
  const datoveTypy = Object.keys(ratioPerTyp);

  let upraveno = 0, oriznuto = 0, presunuto = 0;

  // FÁZE 1 — ořez na okraje + auto-font
  stEditor.prvky.forEach(p => {
    if (p.x_pct < 0) { p.w_pct = Math.max(2, p.w_pct + p.x_pct); p.x_pct = 0; oriznuto++; }
    if (p.y_pct < 0) { p.h_pct = Math.max(2, p.h_pct + p.y_pct); p.y_pct = 0; oriznuto++; }
    if (p.x_pct + p.w_pct > 100) { p.w_pct = Math.max(2, 100 - p.x_pct); oriznuto++; }
    if (p.y_pct + p.h_pct > 100) { p.h_pct = Math.max(2, 100 - p.y_pct); oriznuto++; }

    if (datoveTypy.includes(p.typ)) {
      const ratio = ratioPerTyp[p.typ];
      const h_mm = (p.h_pct / 100) * fmt.h;
      const w_mm = (p.w_pct / 100) * fmt.w;

      // Max font dle výšky
      let fontByH = h_mm * ratio;
      // Pro víceřádkové elementy (slozeni, alergeny, nutri tabulka) zmenši
      if (p.typ === 'slozeni' || p.typ === 'alergeny' || p.typ === 'nutri') fontByH = Math.min(fontByH, 10);

      // Max font dle šířky (měříme reálný text)
      const text = ed_textProPrvek(p);
      const fontByW = text ? ed_maxFontByWidth(text, w_mm * 0.95, p.fontWeight || 400) : fontByH;

      // Vezmi menší (aby se text vešel do obou rozměrů)
      let fontSize = Math.min(fontByH, fontByW);
      fontSize = Math.max(4, Math.min(72, fontSize));
      p.fontSize = parseFloat(fontSize.toFixed(1));
      upraveno++;
    }
  });

  // FÁZE 2 — řešení překryvů. Sortuj podle y, pak x. Pokud někdo překrývá, posuň ho dolů.
  // Speciální výjimka: badge je "fixní" pruh (typ.badge má y=0..8).
  const sorted = stEditor.prvky.slice().sort((a, b) => (a.y_pct - b.y_pct) || (a.x_pct - b.x_pct));
  for (let i = 0; i < sorted.length; i++) {
    const a = sorted[i];
    for (let j = i + 1; j < sorted.length; j++) {
      const b = sorted[j];
      if (ed_prvkyOverlap(a, b)) {
        // Posuň b pod a — pokud sdílejí horizontální prostor
        const aBottom = a.y_pct + a.h_pct;
        const newY = Math.min(99 - b.h_pct, aBottom + 0.5);
        if (newY > b.y_pct) {
          b.y_pct = parseFloat(newY.toFixed(2));
          presunuto++;
        }
      }
    }
  }

  // FÁZE 3 — finální clamp (po posunutí mohou některé prvky vyjet z plátna)
  stEditor.prvky.forEach(p => {
    if (p.y_pct + p.h_pct > 100) {
      // Zmenši výšku, aby se vešel — a font upravit
      p.h_pct = Math.max(2, 100 - p.y_pct);
      if (datoveTypy.includes(p.typ)) {
        const ratio = ratioPerTyp[p.typ];
        const h_mm = (p.h_pct / 100) * fmt.h;
        p.fontSize = parseFloat(Math.min(72, Math.max(4, h_mm * ratio)).toFixed(1));
      }
      oriznuto++;
    }
  });

  vykreslitEditor();

  const t = document.createElement('div');
  t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--success-bg);color:var(--success-text);padding:12px 18px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.15);font-size:13px;font-weight:600;z-index:9999;line-height:1.5;max-width:340px';
  t.innerHTML = `🪄 Chytrý auto-fit hotovo<br><small style="font-size:11px;opacity:0.85">Fontů: <strong>${upraveno}</strong>${presunuto > 0 ? ` · přesunuto kolizí: <strong>${presunuto}</strong>` : ''}${oriznuto > 0 ? ` · oříznuto: <strong>${oriznuto}</strong>` : ''}</small>`;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 4000);
};

