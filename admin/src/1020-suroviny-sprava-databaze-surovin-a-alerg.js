// =============================================================
// SUROVINY (správa databáze surovin a alergenů)
// =============================================================
// === Auto-kategorizace surovin podle klíčových slov v názvu/složení ===
// Kategorie surovin — pořadí matters (testuje shora dolů, první match vyhrává)
//
// 🆕 v2.9.270 — Editovatelné z UI (otevritKategorieSurovin). Custom verze
// se ukládá do nastaveni (klíč 'suroviny_kategorie'). Při loadu mergujeme
// custom s defaults — pokud user smaže výchozí, dostane jen své.
const SUROVINA_KATEGORIE_DEFAULTS = [
  { key: 'smesi',    icon: '🥨', label: 'Mouky a směsi',      kw: ['směs', 'smes', 'premix', 'předsměs', 'predsmes', 'pekařsk', 'pekarsk', 'diasauer', 'pekamix', 'skandi', 'cereáln', 'cerealn', 'vícezrn', 'vicezrn', 'sedmizrn', 'devítizrn', 'devitizrn', 'celozrn', 'koncentrát', 'koncentrat', 'base ', 'sauer', 'master', 'panettone', 'briosch', 'panin', 'pizza dough', 'fokácc', 'fokacc', 'focacc'] },
  { key: 'mouky',    icon: '🌾', label: 'Mouky a krupice',     kw: ['mouk', 'krupic', 'otrub', 'klíč', 'klic', 'klík', 'klik', 'lepek', 'gluten', 'vločk', 'vlocek', 'müsli', 'musli'] },
  { key: 'tuky',     icon: '🧈', label: 'Tuky',                 kw: ['máslo', 'maslo', 'butter', 'tuk', 'sádlo', 'sadlo', 'olej', 'margar', 'pomaz', 'palmar', 'palmin'] },
  { key: 'mleko',    icon: '🥛', label: 'Mléčné výrobky',       kw: ['mléko', 'mleko', 'mlek', 'smetan', 'jogurt', 'sýr', 'syr', 'tvaroh', 'mascarpone', 'feta', 'krém', 'crem', 'syrov', 'curd', 'kefir', 'podmáslí', 'podmasli'] },
  { key: 'vejce',    icon: '🥚', label: 'Vejce',                kw: ['vejc', 'vajec', 'vajíčk', 'vajicek', 'žloutek', 'zloutek', 'bílek', 'bilek', 'melan'] },
  { key: 'cukry',    icon: '🍬', label: 'Cukry a sirupy',       kw: ['cukr', 'sirup', 'med', 'glukóz', 'glukoz', 'dextróz', 'dextroz', 'fruktóz', 'fruktoz', 'sladidl', 'maltodext', 'invertn', 'karamel'] },
  { key: 'kvasnice', icon: '🍞', label: 'Droždí a kypřidla',    kw: ['droždí', 'drozdi', 'droždi', 'kvasnic', 'kvásek', 'kvasek', 'kvas', 'prášek do peč', 'prasek do pec', 'kypřidl', 'kypridl', 'soda', 'jedlá soda', 'jedla soda', 'amonium', 'amoniak', 'natron'] },
  { key: 'sul',      icon: '🧂', label: 'Sůl a koření',         kw: ['sůl', 'sul', 'pepř', 'pepr', 'koření', 'koreni', 'skořic', 'skoric', 'vanil', 'badyán', 'badyan', 'hřebíč', 'hrebic', 'kmín', 'kmin', 'anýz', 'anyz', 'fenykl', 'tymián', 'tymian', 'bazalka', 'oregano'] },
  { key: 'orechy',   icon: '🥜', label: 'Ořechy a semena',      kw: ['ořech', 'orech', 'mandle', 'lískov', 'liskov', 'vlašsk', 'vlassk', 'pistác', 'pistac', 'kešu', 'kesu', 'arašíd', 'arasid', 'mák', 'sezam', 'slunečnic', 'slunecnic', 'dýňov', 'dynov', 'lněn', 'lnen', 'chia'] },
  { key: 'ovoce',    icon: '🍎', label: 'Ovoce a sušené ovoce', kw: ['rozink', 'jablko', 'jablk', 'hrušk', 'hrusk', 'meruňk', 'merunk', 'švestk', 'svestk', 'třešn', 'tresn', 'višn', 'visn', 'brusink', 'borůvk', 'boruvk', 'malin', 'rybíz', 'ryb iz', 'ribiz', 'jahod', 'banán', 'banan', 'kokos', 'datl', 'fík', 'fik', 'pomeranč', 'pomeranc', 'citron', 'lim', 'cranberr'] },
  { key: 'cokolada', icon: '🍫', label: 'Čokoláda a kakao',     kw: ['čokolád', 'cokolad', 'kakao', 'choc', 'pralinka', 'truffl', 'cocoa'] },
  { key: 'naplne',   icon: '🥧', label: 'Náplně, povidla, džemy', kw: ['náplň', 'naplna', 'naplň', 'naplneni', 'povidl', 'džem', 'džemu', 'dzem', 'marmeláda', 'marmelad', 'pasta', 'mák ', 'lekvar', 'kompot', 'krém ', 'crem '] },
  { key: 'aroma',    icon: '🌈', label: 'Aroma, barviva, esence', kw: ['arom', 'esenc', 'extrakt', 'barv', 'rumov', 'rumové', 'rum '] },
  { key: 'voda',     icon: '💧', label: 'Voda, mléko, tekutiny', kw: ['voda', 'mineráln', 'mineraln', 'ocet', 'víno', 'vino', 'rum'] },
  { key: 'ostatni',  icon: '📦', label: 'Ostatní',              kw: [] },
];

// 🆕 v2.9.270 — mutable copy, override-able z nastaveni
let SUROVINA_KATEGORIE = SUROVINA_KATEGORIE_DEFAULTS.map(k => ({ ...k, kw: [...k.kw] }));

// Load custom kategorie z nastaveni (cache, fallback na defaults)
async function loadSurovinaKategorie() {
  try {
    const all = await api('admin_nastaveni.php').catch(() => null);
    const raw = all && all['suroviny_kategorie'];
    if (raw) {
      const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
      if (Array.isArray(parsed) && parsed.length > 0) {
        SUROVINA_KATEGORIE = parsed.map(k => ({
          key: String(k.key || '').replace(/[^a-z0-9_]/gi, '').toLowerCase() || 'kat' + Math.random().toString(36).slice(2, 7),
          icon: k.icon || '📦',
          label: k.label || 'Kategorie',
          kw: Array.isArray(k.kw) ? k.kw : (typeof k.kw === 'string' ? k.kw.split(',').map(s => s.trim().toLowerCase()).filter(Boolean) : []),
        }));
        _katCache.clear();
      }
    }
  } catch (e) { /* fallback na defaults */ }
}
// Auto-load při startu (po api init)
setTimeout(() => loadSurovinaKategorie(), 200);

// Cache pro kategorizace (klíč = id, hodnota = key kategorie)
const _katCache = new Map();
function kategoriziujSurovinu(s) {
  const cacheKey = s.id || s.nazev;
  if (_katCache.has(cacheKey)) return _katCache.get(cacheKey);
  const text = ((s.nazev || '') + ' ' + (s.slozeni || '')).toLowerCase();
  let result = 'ostatni';
  for (const k of SUROVINA_KATEGORIE) {
    if (k.key === 'ostatni') continue;
    if (k.kw.some(w => text.includes(w))) { result = k.key; break; }
  }
  _katCache.set(cacheKey, result);
  return result;
}

