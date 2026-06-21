// =============================================================
// 📤 EXPORT SUROVIN — CSV seskupené dle kategorie (v3.0.182)
// =============================================================
window.exportSurovinyCsv = function() {
  const rows = state._suroviny_filtered_export || [];
  if (!rows.length) return alert('Žádné suroviny k exportu — zkontroluj filtr / kategorii.');
  const katLabel = {};
  (typeof SUROVINA_KATEGORIE !== 'undefined' ? SUROVINA_KATEGORIE : []).forEach(k => { katLabel[k.key] = k.label; });
  // seřaď dle kategorie, pak název
  const sorted = [...rows].sort((a, b) =>
    (katLabel[a._kat] || a._kat || '').localeCompare(katLabel[b._kat] || b._kat || '', 'cs')
    || (a.nazev || '').localeCompare(b.nazev || '', 'cs'));
  const cell = (v) => { const s = (v == null ? '' : String(v)); return /[";\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; };
  const num = (v) => (v == null || v === '' ? '' : String(v).replace('.', ','));
  const head = ['Kategorie', 'Název', 'Jednotka', 'Cena balení (Kč)', 'Obsah balení', 'Cena/jednotka', 'Stav', 'Min. zásoba', 'Alergen'];
  const lines = [head.join(';')];
  sorted.forEach(s => {
    const cb = parseFloat(s.cena_baleni) || 0, ob = parseFloat(s.obsah_baleni) || 0;
    const cenaJed = (cb > 0 && ob > 0) ? (cb / ob) : 0;
    lines.push([
      katLabel[s._kat] || s._kat || '',
      s.nazev || '',
      s.jednotka || '',
      cb ? num(cb.toFixed(2)) : '',
      ob || '',
      cenaJed ? num(cenaJed.toFixed(4)) : '',
      num(s.stock_aktualni),
      num(s.stock_minimalni),
      s.alergen || '',
    ].map(cell).join(';'));
  });
  const csv = '﻿' + lines.join('\r\n');  // BOM → Excel správně načte UTF-8 + diakritiku
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `suroviny_${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 2000);
  if (typeof toastSuccess === 'function') toastSuccess(`Exportováno ${sorted.length} surovin do CSV (dle kategorií)`);
};

