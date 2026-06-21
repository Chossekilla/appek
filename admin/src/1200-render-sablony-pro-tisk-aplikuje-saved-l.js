// =============================================================
// Render šablony pro tisk — aplikuje saved layout (z editoru) na konkrétní data výrobku
// =============================================================
function stitekHtmlSablona(v, sablona) {
  const formatCena = (n) => {
    const s = (parseFloat(n) || 0).toFixed(2).replace('.', ',');
    return s.replace(/\B(?=(\d{3})+(?=,))/g, ' ');
  };

  const cena = parseFloat(v.cena_bez_dph) || 0;
  const dph = parseFloat(v.dph || 12);
  const cenaSDph = cena * (1 + dph / 100);

  // obsah/jednotka pro cena/kg|l
  const obsah = parseFloat(v.obsah) || 0;
  const obsahJed = v.obsah_jednotka || '';
  let kg = 0, l = 0;
  if (obsahJed === 'g') kg = obsah / 1000;
  else if (obsahJed === 'kg') kg = obsah;
  else if (obsahJed === 'ml') l = obsah / 1000;
  else if (obsahJed === 'l') l = obsah;
  const hmotnostG = parseFloat(v.hmotnost_g) || 0;
  if (kg === 0 && l === 0 && hmotnostG > 0) kg = hmotnostG / 1000;
  const cenaKg = kg > 0 ? (cenaSDph / kg) : 0;
  const cenaL = l > 0 ? (cenaSDph / l) : 0;

  // Obsah jednotky pro hmotnost
  let hmTxt = '';
  if (obsah > 0 && obsahJed) hmTxt = `${obsah} ${obsahJed}`;
  else if (hmotnostG > 0) hmTxt = `${hmotnostG} g`;

  const renderPrvek = (p) => {
    let inner = '';
    let extraClass = '';
    let raw = false;
    switch (p.typ) {
      case 'nazev':    inner = esc(p.text || v.nazev || ''); break;
      case 'cena':     inner = p.text ? esc(p.text) : formatCena(cenaSDph); break;
      case 'mena':     inner = esc(p.text || 'Kč'); break;
      case 'jed':      inner = esc(p.text || `za ${v.jednotka || 'ks'}`); break;
      case 'cenakg':
        if (p.text) inner = esc(p.text);
        else if (cenaKg > 0) inner = `${formatCena(cenaKg)} Kč/kg`;
        else if (cenaL > 0)  inner = `${formatCena(cenaL)} Kč/l`;
        break;
      case 'hmotnost': inner = esc(p.text || hmTxt); break;
      case 'dph':      inner = esc(p.text || `DPH ${dph}%`); break;
      case 'slozeni':
        if (p.text) inner = esc(p.text);
        else if (v.slozeni) inner = `<b>Složení:</b> ${esc(v.slozeni)}`;
        raw = true;
        break;
      case 'alergeny':
        if (p.text) inner = esc(p.text);
        else if (v.alergeny) inner = `<b>Alergeny:</b> ${esc(v.alergeny)}`;
        raw = true;
        break;
      // 🥗 v2.9.302 — Nutriční hodnoty (na 100 g výrobku) — pro tisk
      case 'nutri': {
        if (p.text) { inner = esc(p.text); break; }
        const n = vyrobekNutri(v);
        const has = (k) => n[k] !== undefined && n[k] !== null && !isNaN(parseFloat(n[k]));
        if (!Object.keys(n).length || !Object.values(n).some(x => x !== null && x !== '' && !isNaN(parseFloat(x)))) break;
        const cell = (lbl, val, jed, dec = 1) => has(val)
          ? `<div class="sab-nutri-cell"><span class="sab-nutri-l">${lbl}</span><span class="sab-nutri-v">${parseFloat(n[val]).toFixed(dec).replace(/\.?0+$/, '')}${jed ? ' ' + jed : ''}</span></div>`
          : '';
        // Mini EU-style tabulka (kompaktní, jeden řádek headeru + hodnoty pod sebou)
        let table = '<div class="sab-nutri-tbl"><div class="sab-nutri-head">Nutriční hodnoty / 100 g</div>';
        if (has('energie_kj') || has('energie_kcal')) {
          const kj = has('energie_kj') ? Math.round(n.energie_kj) + ' kJ' : '';
          const kcal = has('energie_kcal') ? Math.round(n.energie_kcal) + ' kcal' : '';
          const sep = (kj && kcal) ? ' / ' : '';
          table += `<div class="sab-nutri-cell"><span class="sab-nutri-l">Energie</span><span class="sab-nutri-v">${kj}${sep}${kcal}</span></div>`;
        }
        table += cell('Tuky', 'tuky', 'g', 1);
        table += cell('— z toho nasycené', 'tuky_nasycene', 'g', 1);
        table += cell('Sacharidy', 'sacharidy', 'g', 1);
        table += cell('— z toho cukry', 'cukry', 'g', 1);
        table += cell('Bílkoviny', 'bilkoviny', 'g', 1);
        table += cell('Sůl', 'sul', 'g', 2);
        table += '</div>';
        inner = table;
        raw = true;
        break;
      }
      case 'nutri_kj':   inner = esc(p.text || nutriPrvekText('nutri_kj', v)   || ''); break;
      case 'nutri_kcal': inner = esc(p.text || nutriPrvekText('nutri_kcal', v) || ''); break;
      case 'nutri_tuky': inner = esc(p.text || nutriPrvekText('nutri_tuky', v) || ''); break;
      case 'nutri_sach': inner = esc(p.text || nutriPrvekText('nutri_sach', v) || ''); break;
      case 'nutri_bilk': inner = esc(p.text || nutriPrvekText('nutri_bilk', v) || ''); break;
      case 'nutri_sul':  inner = esc(p.text || nutriPrvekText('nutri_sul', v)  || ''); break;
      case 'kod':      inner = esc(p.text || (v.cislo ? `kód ${v.cislo}` : '')); break;
      case 'badge':    inner = esc(p.text || 'NOVINKA'); extraClass = 'sab-badge'; break;
      case 'text':     inner = esc(p.text || ''); break;
      case 'ean':
        if (v.ean && /^\d{12,13}$/.test(String(v.ean).replace(/\D/g, ''))) {
          inner = ean13ToSvg(v.ean, 30, 10);
          raw = true;
        }
        break;
      case 'qr': {
        // Priorita: vlastní text z editoru → EAN → kód výrobku → ID
        let payload = (p.text || '').trim();
        if (!payload) {
          if (v.ean && /^\d{12,13}$/.test(String(v.ean).replace(/\D/g, ''))) {
            payload = String(v.ean).replace(/\D/g, '');
          } else if (v.cislo) {
            payload = String(v.cislo);
          } else if (v.id) {
            payload = 'OBJ:' + v.id;
          } else {
            payload = 'APPEK';
          }
        }
        inner = `<div class="sab-qr-placeholder" data-qr="${esc(payload)}" style="width:100%;height:100%"></div>`;
        raw = true;
        break;
      }
      case 'cara': case 'box': inner = ''; break;
      default: inner = esc(p.text || '');
    }

    const styly = [];
    styly.push(`left:${p.x_pct}%`);
    styly.push(`top:${p.y_pct}%`);
    styly.push(`width:${p.w_pct}%`);
    styly.push(`height:${p.h_pct}%`);
    if (p.fontSize)   styly.push(`font-size:${p.fontSize}pt`);
    if (p.fontWeight) styly.push(`font-weight:${p.fontWeight}`);
    if (p.color)      styly.push(`color:${p.color}`);
    if (p.bg)         styly.push(`background:${p.bg}`);
    if (p.align)      styly.push(`text-align:${p.align};justify-content:${p.align === 'right' ? 'flex-end' : (p.align === 'center' ? 'center' : 'flex-start')}`);
    if (p.italic)     styly.push(`font-style:italic`);
    if (p.padding)    styly.push(`padding:${p.padding}mm`);

    return `<div class="sab-prvek sab-typ-${p.typ} ${extraClass}" style="${styly.join(';')}">${inner}</div>`;
  };

  const prvky = (sablona.layout?.prvky || []).map(renderPrvek).join('');

  // Globální badge "ribbon" nahoře (Novinka/Akce/Doporučujeme/BIO/…)
  // Stejná logika jako v stitekHtmlCenovka — per-stitek > globální
  const badgeMap = {
    novinka:  { txt: 'NOVINKA',     bg: '#22863a' },
    akce:     { txt: 'AKCE',        bg: '#dc2626' },
    doporuc:  { txt: 'DOPORUČUJEME',bg: '#BA7517' },
    bio:      { txt: 'BIO',         bg: '#15803d' },
    limited:  { txt: 'LIMIT. EDICE',bg: '#7c3aed' },
  };
  const localTxt = (v._localBadgeText || '').trim();
  const localKey = v._localBadge;
  const globalTxt = (stState.cenovkaBadgeText || '').trim();
  const globalKey = stState.cenovkaBadge;
  let badgeTxt = '', badgeBg = '#dc2626';
  if (localTxt) { badgeTxt = localTxt; }
  else if (localKey && badgeMap[localKey]) { badgeTxt = badgeMap[localKey].txt; badgeBg = badgeMap[localKey].bg; }
  else if (globalTxt) { badgeTxt = globalTxt; }
  else if (globalKey && badgeMap[globalKey]) { badgeTxt = badgeMap[globalKey].txt; badgeBg = badgeMap[globalKey].bg; }
  const badgeHtml = badgeTxt
    ? `<div class="st-cen-badge" style="background:${badgeBg}">${esc(badgeTxt)}</div>`
    : '';

  // Fallback — zaškrtnutá pole co v šabloně chybí, dopíšeme jako stack dole na štítku
  const p = stState.cenovkaPole || {};
  const prvkyTypes = new Set((sablona.layout?.prvky || []).map(pr => (pr.typ || '').toLowerCase()));
  const fallbackParts = [];
  if (p.popis    && !prvkyTypes.has('popis')    && v.popis)    fallbackParts.push(`<div style="font-style:italic">${esc(v.popis)}</div>`);
  if (p.hmotnost && !prvkyTypes.has('hmotnost') && hmotnostG > 0) fallbackParts.push(`<div>⚖ ${hmotnostG} g</div>`);
  if (p.cenaKg   && !prvkyTypes.has('cenakg')   && cenaKg > 0) fallbackParts.push(`<div>${formatCena(cenaKg)} Kč/kg</div>`);
  if (p.cislo    && !prvkyTypes.has('kod') && !prvkyTypes.has('cislo') && v.cislo) fallbackParts.push(`<div>${esc(v.cislo)}</div>`);
  if (p.ean      && !prvkyTypes.has('ean')      && v.ean)      fallbackParts.push(`<div style="font-family:monospace">||| ${esc(v.ean)}</div>`);
  if (p.alergeny && !prvkyTypes.has('alergeny') && v.alergeny) fallbackParts.push(`<div style="color:#92400e"><b>Alergeny:</b> ${esc(v.alergeny)}</div>`);
  if (p.slozeni  && !prvkyTypes.has('slozeni')  && v.slozeni)  fallbackParts.push(`<div style="font-style:italic;color:#666"><b>Složení:</b> ${esc(v.slozeni)}</div>`);
  const fallbackHtml = fallbackParts.length > 0
    ? `<div class="sab-fallback" style="position:absolute;left:2mm;right:2mm;bottom:1.5mm;font-size:6pt;line-height:1.2;border-top:0.2mm dashed #ccc;padding-top:1mm;display:flex;flex-direction:column;gap:0.4mm;background:rgba(255,255,255,0.92)">${fallbackParts.join('')}</div>`
    : '';

  return `<div class="st-cell st-cell-sablona ${badgeHtml ? 'has-badge' : ''}">${badgeHtml}${prvky}${fallbackHtml}</div>`;
}

function stitekHtmlExpedicni(o) {
  const firma = '🍞 APPEK B2B';
  const qrPayload = `OBJ:${o.id}:${o.cislo}`;
  const showQr = stState.vlozitQr;
  return `
    <div class="st-cell st-cell-exp">
      <div class="st-exp-content">
        <div class="st-exp-h1">${esc(firma)}</div>
        <div class="st-exp-pob">${esc(o.misto_nazev || o.odberatel_nazev || '')}</div>
        ${o.misto_nazev && o.odberatel_nazev !== o.misto_nazev ? `<div class="st-exp-addr">${esc(o.odberatel_nazev)}</div>` : ''}
        <div class="st-exp-meta">
          🚚 <strong>${fmtDate(o.datum_dodani)}</strong><br>
          📋 OBJ <span class="st-exp-cislo">${esc(o.cislo)}</span> · 📦 ${o.pocet_polozek || 0} pol.
        </div>
      </div>
      ${showQr ? `<div class="st-exp-qr" data-qr="${esc(qrPayload)}"></div>` : ''}
    </div>
  `;
}

function stitekHtmlCenovka(v) {
  const p = stState.cenovkaPole;
  const cena = parseFloat(v.cena_bez_dph) || 0;
  const dph = parseFloat(v.dph || 12);
  const cenaSDph = cena * (1 + dph / 100);

  // Přepočet ceny za 1 kg / 1 l z obsahu
  const obsah = parseFloat(v.obsah) || 0;
  const obsahJed = v.obsah_jednotka || '';
  let kg = 0, l = 0;
  if (obsahJed === 'g')  kg = obsah / 1000;
  else if (obsahJed === 'kg') kg = obsah;
  else if (obsahJed === 'ml') l  = obsah / 1000;
  else if (obsahJed === 'l')  l  = obsah;
  // Backward compat: hmotnost_g samotné (bez obsah_jednotka) → g
  const hmotnostG = parseFloat(v.hmotnost_g) || 0;
  if (kg === 0 && l === 0 && hmotnostG > 0) kg = hmotnostG / 1000;

  const cenaKg = kg > 0 ? (cenaSDph / kg) : 0;
  const cenaL  = l  > 0 ? (cenaSDph / l)  : 0;
  const showEan = (p.ean !== false) && v.ean && /^\d{12,13}$/.test(String(v.ean).replace(/\D/g, ''));

  // Badge — per-štítek (Moje štítky) má přednost před globální volbou
  const badgeMap = {
    novinka:  { txt: 'NOVINKA',     bg: '#22863a' },
    akce:     { txt: 'AKCE',        bg: '#dc2626' },
    doporuc:  { txt: 'DOPORUČUJEME',bg: '#BA7517' },
    bio:      { txt: 'BIO',         bg: '#15803d' },
    limited:  { txt: 'LIMIT. EDICE',bg: '#7c3aed' },
  };
  const localTxt = (v._localBadgeText || '').trim();
  const localKey = v._localBadge;
  const globalTxt = (stState.cenovkaBadgeText || '').trim();
  const globalKey = stState.cenovkaBadge;
  // Priorita: lokální text > lokální preset > globální text > globální preset
  let badgeTxt = '', badgeBg = '#dc2626';
  if (localTxt) { badgeTxt = localTxt; }
  else if (localKey && badgeMap[localKey]) { badgeTxt = badgeMap[localKey].txt; badgeBg = badgeMap[localKey].bg; }
  else if (globalTxt) { badgeTxt = globalTxt; }
  else if (globalKey && badgeMap[globalKey]) { badgeTxt = badgeMap[globalKey].txt; badgeBg = badgeMap[globalKey].bg; }
  const badgeHtml = badgeTxt
    ? `<div class="st-cen-badge" style="background:${badgeBg}">${esc(badgeTxt)}</div>`
    : '';

  // Modern layout — head (název + meta), price block (hero cena), foot (info + EAN)
  // Naformátuj cenu — desetinná čárka, oddělené tisíce
  const formatCena = (n) => {
    const s = n.toFixed(2).replace('.', ',');
    return s.replace(/\B(?=(\d{3})+(?=,))/g, ' ');
  };

  // HEAD: název + popis + kompaktní meta řádek (hmotnost, DPH)
  const headParts = [];
  if (p.nazev && v.nazev) {
    headParts.push(`<div class="st-cen-nazev">${esc(v.nazev)}</div>`);
  }
  if (p.popis && v.popis) {
    headParts.push(`<div class="st-cen-popis">${esc(v.popis)}</div>`);
  }
  const metaItems = [];
  if (p.hmotnost) {
    if (obsah > 0 && obsahJed) metaItems.push(`<span class="st-cen-meta-item">⚖️ ${obsah} ${obsahJed}</span>`);
    else if (hmotnostG > 0) metaItems.push(`<span class="st-cen-meta-item">⚖️ ${hmotnostG} g</span>`);
  }
  if (p.cena) {
    metaItems.push(`<span class="st-cen-meta-item st-cen-meta-dph">DPH ${dph}%</span>`);
  }
  if (metaItems.length) {
    headParts.push(`<div class="st-cen-meta-line">${metaItems.join('')}</div>`);
  }

  // PRICE BLOCK (hero)
  const priceParts = [];
  if (p.cena) {
    const jedTxt = esc(v.jednotka || 'ks');
    const sekundarni = [];
    sekundarni.push(`<span class="st-cen-jed">za ${jedTxt}</span>`);
    if (p.cenaKg) {
      if (cenaKg > 0) sekundarni.push(`<span class="st-cen-cenakg">${formatCena(cenaKg)} Kč / kg</span>`);
      else if (cenaL > 0) sekundarni.push(`<span class="st-cen-cenakg">${formatCena(cenaL)} Kč / l</span>`);
    }
    priceParts.push(`
      <div class="st-cen-price-block">
        <div class="st-cen-cena">${formatCena(cenaSDph)}<span class="st-cen-mena">Kč</span></div>
        ${sekundarni.length ? `<div class="st-cen-secondary">${sekundarni.join('')}</div>` : ''}
      </div>
    `);
  } else if (p.cenaKg && (cenaKg > 0 || cenaL > 0)) {
    priceParts.push(`<div class="st-cen-cenakg-only">${formatCena(cenaKg || cenaL)} Kč / ${cenaKg > 0 ? 'kg' : 'l'}</div>`);
  }

  // FOOT: složení + alergeny — každé na svém řádku; auto-fit je případně sloučí na jeden
  const footParts = [];
  const infoLines = [];
  if (p.slozeni && v.slozeni) {
    infoLines.push(`<span class="info-line info-sloz"><b>Složení:</b> ${esc(v.slozeni)}</span>`);
  }
  if (p.alergeny && v.alergeny) {
    infoLines.push(`<span class="info-line info-aler"><b>Alergeny:</b> ${esc(v.alergeny)}</span>`);
  }
  if (p.cislo && v.cislo) {
    infoLines.push(`<span class="info-line info-cislo">kód ${esc(v.cislo)}</span>`);
  }
  if (infoLines.length) {
    footParts.push(`<div class="st-cen-info">${infoLines.join('')}</div>`);
  }
  if (showEan) {
    footParts.push(`<div class="st-cen-ean">${ean13ToSvg(v.ean, 32, 11)}</div>`);
  }

  return `
    <div class="st-cell st-cell-cen ${badgeHtml ? 'has-badge' : ''}">
      ${badgeHtml}
      ${headParts.length ? `<div class="st-cen-head">${headParts.join('')}</div>` : ''}
      ${priceParts.join('')}
      ${footParts.length ? `<div class="st-cen-foot">${footParts.join('')}</div>` : ''}
    </div>
  `;
}

