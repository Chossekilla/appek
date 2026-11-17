
// =============================================================
// 💱 🆕 v3.0.645 — VOLBA MĚNY RUČNĚ ZAKLÁDANÉHO DOKLADU
//   Catering z kalkulačky, dort z konfigurátoru, ruční dodací list — stejné UI jako
//   „Nová objednávka" (#no-mena): výběr se ukáže JEN když instalace přijímá víc měn
//   (Nastavení → Přístupy, ceny & měna → platba ve více měnách). Prázdná volba =
//   měna odběratele / výchozí (rozhoduje server: api/_mena_rucni_lib.php).
// =============================================================
function menaVolbaAktivni() {
  const m = window._menaCfg;
  return !!(m && m.platba && m.platba.aktivni);
}
// <option>y pro <select> měny dokladu; `pevna` = jen tahle jedna měna (úprava vystaveného dokladu)
function menaVolbaOptions(value, pevna) {
  const lbl = m => m === 'CZK' ? 'Kč (CZK)' : esc(m);
  if (pevna) return `<option value="${esc(pevna)}" selected>${lbl(String(pevna).toUpperCase())}</option>`;
  const meny = ['CZK', ...(((window._menaCfg || {}).platba || {}).meny || [])];
  return `<option value="">Podle odběratele</option>`
    + [...new Set(meny)].map(m => `<option value="${esc(m)}" ${value === m ? 'selected' : ''}>${lbl(m)}</option>`).join('');
}
// Částka z odpovědi create endpointu v měně dokladu (mena + castka_mena), jinak jako dřív fmt(castka)
function menaFmtVytvoreno(r) {
  return (r && r.mena && r.castka_mena != null) ? fmtDoc(r.castka_mena, r.mena) : fmt(r ? r.castka : 0);
}
