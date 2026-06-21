// =============================================================
// 🎬 DEMO DATA SEEDER — one-click "Naplnit ukázkovými daty"
// =============================================================
// 🆕 v2.5.9 — Dismiss empty-state demo banner
window.dismissDemoBanner = function() {
  try { localStorage.setItem('appek_demo_banner_dismissed', '1'); } catch (e) {}
  const banner = document.getElementById('empty-demo-banner');
  if (banner) {
    banner.style.transition = 'opacity 0.2s, transform 0.2s';
    banner.style.opacity = '0';
    banner.style.transform = 'scale(0.95)';
    setTimeout(() => banner.remove(), 200);
  }
};

window.openDemoSeed = async function() {
  let stats = null;
  try {
    stats = await api('admin_demo_seed.php?action=preview', { method: 'POST' });
  } catch (e) {
    return alert('Chyba: ' + e.message);
  }
  const cur = stats.current || {};
  const will = stats.will_add || {};
  const proceed = await confirmDialog({
    title: '🎬 Naplnit FULL DEMO daty',
    icon: '🎬',
    msg: `FULL DEMO — kompletní funkční ukázka:\n\n📦 KATALOG:\n• ${will.kategorie || 0} kategorií · ${will.vyrobky || 0} výrobků · ${will.suroviny || 0} surovin\n• ${will.recepty || 0} receptů (klasické pekařské receptury)\n• ${will.naskladneno_polozek || 0} surovin naskladněno (8 balení mouky, 40 másel…)\n• ${will.kalkulace_ulozeno || 0} uložených kalkulací s marží\n• ${will.fixni_naklady_polozek || 0} fixních nákladů (energie, mzdy, nájem)\n\n👥 B2B:\n• ${will.odberatele || 0} odběratelů (John Doe + 4 varianty)\n• ${will.mista_dodani || 0} poboček pro John Doe\n• ${will.cenove_skupiny || 0} cenové skupiny (Restaurace 5%, Hotely 8%, Kavárny 3%)\n• ${will.objednavky || 0} obj + ${will.dodaci_listy || 0} DL + ${will.faktury || 0} faktura (John Doe)\n• ${will.historie_obj || 0} obj historie napříč 14 dny (pro grafy)\n\n🍕 RESTAURACE balíček:\n• ${will.pos_users || 0} POS uživatelů s PIN (Jarmila/1234, Evžen/5678, Prodavač1/0000, Karel/9999)\n• ${will.stoly || 0} stolů (sál + terasa)\n• ${will.kuchyne_stanice || 0} kuchyně stanice (Pec, Studená, Gril, Bar)\n• ${will.kuryrky || 0} kurýrky (vlastní + Wolt + Bolt)\n\n👤 B2B LOGIN: odberatel@demo.cz / demo1234\n\nExistující záznamy se zachovají (skipuje duplicity).`,
    okText: 'Naplnit FULL DEMO',
    cancelText: 'Zrušit',
  });
  if (!proceed) return;

  try {
    const r = await api('admin_demo_seed.php?action=apply', { method: 'POST' });
    const summary = `+${r.vyrobky || 0} výrobků, +${r.recepty || 0} receptů, +${r.naskladneno_polozek || 0} naskladnění, +${r.kalkulace_ulozeno || 0} kalkulací`;

    // 🆕 v2.9.283 — Verbose alert pokud něco selhalo (recepty bez surovin, errors)
    const skipNenalezena = r.recepty_skip_nenalezena_surovina || [];
    const skipVyrobek = r.recepty_skip_nenalezen_vyrobek || 0;
    const errors = (r.errors || []).slice(0, 3); // max 3 errors

    if (skipNenalezena.length > 0 || skipVyrobek > 0 || errors.length > 0) {
      // Verbose dialog místo toastu
      const details = [
        summary,
        '',
        '⚠️ Některé věci se nedopnily:',
        skipVyrobek > 0 ? `• ${skipVyrobek} výrobků nenalezeno v DB (chybí cislo nebo název)` : '',
        skipNenalezena.length > 0 ? `• Suroviny nenalezeny v DB (chybí název nebo alias):\n   ${skipNenalezena.slice(0, 10).join(', ')}${skipNenalezena.length > 10 ? '…' : ''}` : '',
        errors.length > 0 ? `• Chyby:\n   ${errors.join('\n   ')}` : '',
        '',
        '💡 Tip: pro čistý start klikni 🗑️ Reset demo data (smaže VŠE + naplní znova).',
      ].filter(Boolean).join('\n');
      alert(details);
    } else {
      toastSuccess(summary, 'Demo data připravena 🎉');
    }
    setTimeout(() => location.reload(), 1500);
  } catch (e) {
    alert('Chyba: ' + e.message);
  }
};

// 🆕 v2.9.277 — Smazat vše. 🔄 v3.0.231: UŽ NENAPLŇUJE automaticky — systém zůstane
// prázdný; demo data jen ručně tlačítkem 🎬 Naplnit demo daty (user 2026-06-10).
window.resetDemoSeed = async function() {
  if (!isSuperAdmin()) return alert('Tato akce vyžaduje super admin práva');

  // 1. Strong confirm — 2 kroky
  const proceed1 = await confirmDialog({
    title: '⚠️ SMAZAT VŠECHNA DATA',
    icon: '🗑️',
    msg: 'Smaže VŠECHNA data v systému (objednávky, výrobky, suroviny, faktury, odběratele, sklad pohyby…). Systém zůstane PRÁZDNÝ.\n\n⚠️ TOTO NELZE VRÁTIT ZPĚT.\n\nDemo data pak můžeš naplnit tlačítkem 🎬 Naplnit demo daty. Pokračovat?',
    okText: '⚠️ Pokračovat',
    cancelText: 'Zrušit',
  });
  if (!proceed1) return;

  // 2. Typed confirmation
  const typed = (await promptDialog({ msg: 'Pro potvrzení napiš velkými písmeny:\n\nSMAZAT VSE', value: '' }));
  if (typed !== 'SMAZAT VSE') {
    if (typed !== null) alert('Neshoduje se — reset zrušen');
    return;
  }

  try {
    toastSuccess('⏳ Mažu data…', 'Reset běží');
    await api('admin_demo_seed.php?action=clear', {
      method: 'POST',
      body: JSON.stringify({ confirm: 'SMAZAT VSE' }),
    });
    toastSuccess('Systém je prázdný. Demo data naplníš tlačítkem 🎬.', '✅ Vše smazáno');
    setTimeout(() => location.reload(), 1500);
  } catch (e) {
    alert('Chyba resetu: ' + e.message);
  }
};

