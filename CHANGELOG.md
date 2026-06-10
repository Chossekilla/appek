# Changelog

Všechny významné změny v APPEK B2B.

Formát: [Keep a Changelog](https://keepachangelog.com/cs/) · [Semantic Versioning](https://semver.org/lang/cs/).

---

## [3.0.251] — 2026-06-10

### ⚡ Výkon — minifikace admin.js (build)
_User: „přijde mi to pomalé". Vyšetření: backend rychlý (0.16 s napříč seznamy/modaly/účtenkami), pomalost byla kontence demo serveru, ne kód. Jediná reálná frontend páka = velikost admin.js._
- **admin.js (~2.1 MB nezminifikovaného zdroje, načítá se synchronně)** se teď v CI buildu minifikuje esbuildem (~2.1 MB → ~1.2 MB) → rychlejší download i parse, hlavně na mobilu/slabém zařízení.
- Běží v `build-zip.sh` po version-editech a **před** `.build-manifest.json` (SHA-256 sedí s nasazeným souborem). Bez `--bundle` → globální onclick handlery se nepřejmenují (inline `onclick="fn()"` fungují). `--charset=utf8` zachová české znaky + emoji.
- **Fail-safe:** když esbuild chybí nebo výstup neprojde sanity checkem (velikost + přítomnost známých handlerů), ponechá se plný admin.js a build pokračuje (radši nezminifikováno než rozbito). Zdroj v gitu zůstává needitovaný (minifikace jen v ephemerálním CI checkoutu).

## [3.0.250] — 2026-06-10

### 📱 B2B karty produktů — qty stepper 100% responzivní
_User: při 5 sloupcích není vidět množství, je utlačené mezi +/−, špatný flex._
- **Bug:** `.card-qty-row .qty-btn` mělo `flex: 0 0 36px` (fixní) + input `min-width: 0` → při úzké kartě tlačítka sežrala místo a input s množstvím **zkolaboval na 0** (číslo zmizelo).
- **Fix:** tlačítka shrinkovatelná (`flex: 0 1 36px; min-width: 22px`), input `min-width: 28px` (číslo se nikdy nezkolabuje) + **container queries** — velikost stepperu škáluje přesně dle šířky karty (best-in-class responzivita napříč 3/4/5 sloupci i mobilem). Ověřeno živě: číslo množství vždy čitelné.

## [3.0.249] — 2026-06-10

### 📃 POS Účtenky čtou nastavení počtu řádků (dotažení)
- POS Účtenky (quick_history) měly limit hardcoded 50 → teď čtou `pagination_pocet` z nastavení (injektnuto do `POS_CONFIG.pagPocet` z pos.php). „Načíst další" chunk respektuje zvolený počet.
- Popisek „Dlouhé seznamy" vrácen na (Objednávky, Faktury, Dodací listy, POS Účtenky) — přesný: počet řádků platí všude, styl jen pro admin seznamy (POS vždy „Načíst další").

## [3.0.248] — 2026-06-10

### 📝 Audit informačních popisků
Projeté popisky napříč aplikací (podtituly sekcí, hinty nastavení, FAQ, tooltipy s tvrzeními o chování) proti reálné funkčnosti.
- **Oprava:** popisek „Dlouhé seznamy" tvrdil, že nastavení platí i pro **POS Účtenky** — neplatí (řídí jen Objednávky/Faktury/DL; POS je samostatná appka). Odebráno.
- Ověřeno jako **přesné:** firma/logo na dokladech (live + „existující se aktualizují při dalším otevření"), B2B sezónní filtr (katalog.php dle data), témata/jazyk (per-zařízení), catering (smlouvy/záloha 50 %), reset dat (2× confirm + typed), bulk faktura z DL (stejný odběratel + nefakturované), „foto jen URL" (upload fakt není).

## [3.0.247] — 2026-06-10

### 📃 Dlouhé seznamy — chybějící Uložit + volba počtu řádků
_User: v sekci „Dlouhé seznamy" (Nastavení → Údržba) není tlačítko Uložit, i když text říká „dole". A přidat volbu po kolika řádcích na stránku._

- **Bug:** „💾 Uložit nastavení" se zobrazoval jen v tabech Firma/Notifikace; sekce „Dlouhé seznamy" je v **Údržbě** → tlačítko tam chybělo, nešlo uložit. Opraveno (`ukazatUlozit` zahrnuje `udrzba`).
- **Nově: výběr počtu řádků na stránku** (25 / 50 / 100 / 200) — setting `pagination_pocet`, aplikuje se na Objednávky, Faktury, Dodací listy (sdílené paging helpery). Při změně se resetuje offset. Platí pro všechna zařízení (server-side nastavení).

## [3.0.246] — 2026-06-10

### 🍽️ Restaurace — šablony s náhledy, oválky, filtr timeline, profi doklady
- **Oválky POS tabů (4× hlášeno):** kritické CSS oválných tabů vloženo přímo do `pos.php` (no-store HTML) → KASA/Stoly… jsou oválné i kdyby `pos.css` zůstala v cache.
- **Picker šablon s náhledy:** vrácen vizuální výběr — každá šablona má mini SVG náhled rozložení stolů (built-in i vlastní). `user_templates` API nově vrací zóny+stoly pro náhled.
- **Konec hromadění stolů:** zrušen merge „➕ Jako zónu" (v adminu i editoru). Načtení šablony = vždy čisté nahrazení → editor zůstává přehledný, jde hýbat/mazat.
- **Filtr zón i v Timeline** kalendáři rezervací (dřív jen v Seznamu); sdílí stav filtru.
- **Doklady tlačítka sjednocena** do profi sady `.doc-action` (Znovu objednat / Vytvořit DL / Vystavit fakturu) — stejný tvar/velikost, primární zelená CTA, modrá „otevřít existující". Label „📋 Doklady" jako hlavička (dřív rozhazoval grid).

### ✅ Ověřeno (testy)
- Změna firmy (IČO) → propis do faktury PDF live (odběratel zůstává snapshot). Test 03118592→99887766→zpět.
- Přidání/odebrání zóny → 0 sirotků, soft-delete stolů, rezervace netknuté, data validní.

## [3.0.245] — 2026-06-10

### 🪟 Sjednocené dialogy v CELÉ aplikaci (dokončení)
_User: dokončit sjednocení dialogů v celé appce (po Nastavení)._

- **87 nativních `confirm()`/`prompt()` → `confirmDialog`/`promptDialog`** napříč celým adminem (objednávky, faktury, sklad, výroba, výrobky, HACCP, cenovky, šablony, restaurace, odběratelé…). Žádný systémový blokující popup už v aplikaci není.
- Transformace string/paren-aware skriptem (respektuje vnořené závorky i řetězce), `danger` se odvozuje z klíčových slov (smazat/přepsat/ztratit…) → červené tlačítko u destruktivních akcí.
- 17 handlerů doplněno o `async` (kvůli `await` dialogu) — všechny jsou onclick handlery, návratová hodnota se nikde synchronně nečte (ověřeno).
- Ověřeno: jsc syntax OK, 0 zbylých nativních dialogů, převedený confirm i promptDialog renderují stylově (Preview MCP).

## [3.0.244] — 2026-06-10

### 🪟 Sjednocené dialogy v Nastavení (konec systémových confirm/prompt)
_User: nativní `confirm()` u uložení číselné řady (a další v Nastavení) — sjednotit na hezký dialog jako všude jinde._

- **Nový `promptDialog`** — stylový input dialog (Apple-style, sdílí vzhled s `confirmDialog`); vrací text nebo `null` při zrušení/Escape, Enter potvrdí.
- **9× `confirm()` → `confirmDialog`** v Nastavení: uložení číselné řady, testovací push všem, náhodný HTML design, sync teď, nový shared secret, vrátit šablonu na výchozí, párování surovin, obnovit výchozí práva, odebrat ze skupiny, self-update na novou verzi.
- **3× `prompt()` → `promptDialog`**: popis zálohy, potvrzení obnovy zálohy (napiš OBNOVIT), testovací e-mail.
- Žádný systémový blokující dialog v Nastavení → konzistentní vzhled + nezamrzá UI. (`alert()` už dřív řešil smart-intercept → toast/modal.)
- Ověřeno Preview MCP: promptDialog vrací zadaný text, confirmDialog (danger) vrací true.

## [3.0.243] — 2026-06-10

### 🪑 Stoly — empty state už není slepá ulička + směrování po uložení
_User: "po smazání jde přidat jenom stůl a ne šablona nebo zóna. tohle musí být 100%. a prověř prolinkování na správné pozice i vnitřní směrování — směrování po uložení a tak."_

- **Empty state „Žádné stoly"** nově nabízí 4 akce: **📋 Načíst šablonu** (nový picker přímo v adminu — vlastní šablony s „➕ Jako zónu" + předpřipravené), **➕ Nová zóna**, **🪑 Přidat stůl**, **🗺️ Editor mapy**. Dřív šel přidat jen jednotlivý stůl.
- **Picker šablon v adminu** (`openSablonyPicker`): destruktivní „Načíst" má confirm + varování; „Jako zónu" přidá šablonu ke stávajícím zónám bez přepsání.
- **Nová zóna z adminu** (`pridatZonu` → `zone_save`).
- **„+ Nový stůl" ukládá zónu** — select zóny místo free-text sekce (dřív stůl vznikal bez `zone_id` → sirotek: neviděl ho floor plan ani filtr rezervací). Fallback text zůstává, když zóny nejsou.
- **Směrování po uložení:** floorplan editor po „Použít"/načtení šablony pošle `postMessage` admin oknu → admin invaliduje cache a překreslí Stoly + toast „Floor plan aktualizován z editoru". Dřív admin po návratu z editoru ukazoval starý layout, dokud user ručně nepřepnul.
- Prověřeno vnitřní směrování: všechny save akce (rezervace, stůl, šablona, zóna, otevírací doba) končí rerenderem na stejné pozici (tab/subview zachován); `openFloorplanWindow` fallback směruje na Stoly → mapa.

## [3.0.242] — 2026-06-10

### 🗓️ Rezervace — filtr na zóny + úprava rezervace proklikem
_User: "restaurace balíček — rezervace — přidat filtr na zóny; v seznamu rezervací proklik do úpravy rezervace."_

- **Filtr na zóny** v seznamu rezervací (Stoly → Rezervace → Seznam): chips „Vše (N)" + jednotlivé zóny s počty rezervací; nový sloupec **Zóna** v tabulce.
- **Proklik do úpravy:** řádek seznamu i blok v timeline otevírá modal **✏️ Rezervace** — změna stolu (select groupnutý po zónách), data, časů, jména, telefonu, počtu osob, stavu (potvrzená/čeká/nepřišli) a poznámky; 🗑️ zrušení rezervace. Dřív byl proklik jen stub (toast „Detail rezervace #id") — rezervace nešla upravit vůbec.
- **Backend `update_reservation`:** partial update + stejná anti-double-booking validace jako vytvoření (sdílený helper `tr_validate_slot` — drží fixy v3.0.214 překryvy + v3.0.222 přes-půlnoc; při editu vynechá sebe sama z kolizí). Přesun do minulosti blokován jen při změně data (oprava jména u historické rezervace projde). Testy 7/7: edit, kolize 409, partial, self-overlap, neexistující stůl 400, zrušení.

## [3.0.241] — 2026-06-10

### 🗺️ Floor plan — oprava „Chyba apply: t is not defined" + šablona do nové zóny
_User: "floorplan není úplně funkční, prověř ho. šablony z něj ukládat do vytvořených šablon, přidat aby se dala použít do nové zóny."_

- **Kritický bug:** `admin/floorplan.js` mělo 4 volání i18n funkce `t()`, která se v tomto editoru vůbec nenačítá → `ReferenceError: t is not defined`. Rozbíjelo to: **Použít** (apply — i když se uložilo, ukázalo chybu), **Načíst šablonu** (tiché selhání), **mazání zóny**, **import JSON**. Doplněn lokální `t()` fallback s českými texty.
- **Šablony do nové zóny:** tlačítko **„➕ Jako zónu"** u vlastních šablon — přidá šablonu jako nové zóny **bez přepsání** stávajícího rozložení (`apply_user_template` s `merge=true`; backend nově posouvá `sort_order` nových zón za stávající, aby taby neskákaly). Vedle zůstává „📥 Načíst" (přepíše vše).
- Uložení šablony (`save_user_template` → `floorplan_templates`) ověřeno — ukládá do existujících vlastních šablon.

## [3.0.240] — 2026-06-10

### 📱 POS na mobilu použitelná — košík přes celou plochu byl problém
_User: "na iphone je pos v podstatě nepoužitelná." Příčina: desktopový pravý panel košíku se na výšku zalomil dolů s `max-height:50vh` i prázdný → na produkty zbyl proužek (1 řádek dlaždic)._

- **Produkty přes celou obrazovku** (≤768px). Košík vytažen z flow jako výsuvný overlay.
- **Dva režimy mobilního košíku — volba v ⋮ menu** (výchozí = lišta):
  - **Spodní lišta** — dole tenká lišta `🛒 N ks · CELKEM · ✓ FINISH`; ťuknutím vyjede košík nahoru přes ztmavený backdrop, ✕ / klik mimo zavře.
  - **Přepínač Produkty / Košík** — horní segmentový přepínač, čistá celoobrazovková výměna.
  - Uloženo v `localStorage pos_mcart_mode` (vzor jako `pos_zoom`).
- **Navigace vrácena na mobil** — KASA / Stoly / Účtenky / Statistiky / Uzávěrka v ⋮ menu (horní taby jsou ≤1100px skryté). Mimo KASU se lišta/přepínač skryje.
- Ověřeno Preview MCP na 375×812 (lišta zavřená/otevřená + přepínač oba pohledy).

## [3.0.239] — 2026-06-10

### 🔄 POS — detekce nové verze (konec „zaseklé staré UI")
_User: horní taby (KASA/Stoly…) pořád hranaté, ne oválné — přitom oválný CSS byl nasazený (v3.0.234). Příčina: starý service worker admin SPA (scope /admin/) servíroval starou `pos.css` z cache i přes `?v=` bump._

- `pos.js` nově při startu (+ každých 5 min) porovná načtenou verzi (`POS_CONFIG.version`, server-rendered) s živou `api/version.php`. Při neshodě ukáže banner **„🆕 Nová verze X — klikni pro obnovení"**.
- Obnovení **odregistruje service worker + smaže všechny caches** → spolehlivě probije i zaseklý starý SW (ne jen `location.reload`). POS dosud neměl stale-detektor, který admin SPA má.
- Oválné taby (`.pos-tab-h` border-radius 999px) byly korektně nasazené už ve v3.0.234 — ověřeno, že živé `pos.css?v=` je obsahuje; šlo čistě o klientskou cache.

## [3.0.238] — 2026-06-10

### 🚨 Inode kvóta — oprava úniku v self-update (KRITICKÉ)
_Účet měl 647 630 / 600 000 inodů (107 %) → filesystém přestal tvořit soubory (sessions, uploady, updaty)._

- **Příčina:** `vendor/_self_update.php` dělal při každém deployi `cp -R` celého stromu (vendor+api+sales+admin+b2b+demo+pos+floorplan) do `/tmp/appek-self-update-backup-<datum>/` a **nikdy nemazal** (komentář „backup zůstává pro rollback"). Na CloudLinuxu `/tmp` = `~/.cagefs/tmp` → počítá se do kvóty účtu. 59 záloh × ~11k souborů = 641k inodů.
- **Hned:** smazáno 58 starých záloh → `/home` z 647 630 na **7 918 inodů** (1,3 %).
- **Trvale:** přidána retence `self_update_prune_tmp()` — nechává **newest 2 zálohy**, staré extract/staging diry (>1 h) maže. Customer `updates_apply.php` byl už OK (zálohuje jen měněné soubory do `api/zalohy/` + maže staré).

### 🔗 Doklady — konzistentní obousměrný řetězec
_User: "z objednávky musí jít vytvořit DL a fakturu a z faktur a DL musí jít nová objednávka, skrz naskrz konzistentní."_

- Doplněna **zpětná navigace faktura → dodací list** (`admin_faktury.php` vrací `dodaci_listy`; modal faktury ukazuje sjednocené „🔗 Vázané doklady" s 🛒 objednávkou i 📃 DL). Dřív faktura linkovala jen objednávku.
- Ověřeno, že zbytek řetězce už funguje: objednávka → vytvořit DL / fakturu (zvýrazněno v 3.0.237), DL → objednávka (badge) + vystavit fakturu, faktura/DL/objednávka → 🔁 znovu objednat (nová objednávka ze stejných položek).

## [3.0.237] — 2026-06-10

### 🧾 Faktury — výběr nefakturovaných dokladů + zvýraznění
_User: "u tlačítka nová faktura musí být pole s výběrem nefakturovaných objednávek a dodacích listů. ne každá objednávka musí mít dl a fa a obráceně, aby nekolidovaly číselné řady. modal objednávky — vystavit fakturu je někde malé, udělej to přehlednější."_

- **Nová faktura → picker:** modal "+ Nová faktura" nově nabízí `<select>` s nefakturovanými **objednávkami bez DL** a **nefakturovanými dodacími listy** (optgroupy s počty). Výběr rovnou vystaví fakturu z objednávky (`vytvoritFakturu`) nebo z DL (`vytvoritFakturuZDL`). Pod selectem je poznámka, že objednávka/DL/faktura mají vlastní číselné řady a nekolidují.
- **Detail objednávky:** "💰 Vystavit fakturu" zvýrazněno na zelené CTA (dřív splývalo jako `btn-secondary`); řada akcí má popisek "📋 Doklady:"; když faktura existuje, tlačítko ukazuje její číslo.
- **Detail DL (v3.0.233):** velké tlačítko "💰 Vystavit fakturu" / "Otevřít fakturu".

### 🗄️ Service-worker cache (v3.0.236)
- Build bumpuje `CACHE_VERSION` v `admin/sw.js` + `b2b/sw.js` (dřív zamrzlé → uživatelé viděli starou verzi nahoře vs. dole). Po jednom hard-refreshi se SW přeinstaluje a dál se aktualizuje sám.
- Manifest se píše i bez tečky (`update-manifest.json`) — hostingová CDN blokovala dotfile (403).

## [3.0.69] — 2026-05-27

### ✨ Rail mode polish — krásnější design (žádné bílé pilly, větší ikony, elegant pin)
_User: "tohle srovnej a udělej krásný. ten button efekt pod A logo smaž. dole je to nedojetá ten přechod u špendlíku. zvětši ikony trochu. no prostě hezký."_

**Nav-items v railu:**
- Smazány bílé pill backgroundy (transparent + žádné border-bottom)
- Ikony 22px → **28px** (větší)
- Opacity 0.55 default, 1.0 aktivní + scale 1.08 + gold drop-shadow
- Aktivní item: subtle gold tint na celé dlaždici + 3px gold accent strip vlevo
- Hover: jemné light tint (rgba 3.5%)

**Sidebar-utils (pin area):**
- Tvrdá `border-top` → **plynulý gold gradient fade** (transparent → 4% → 8%)
- Krátká dashed čára nahoře (jen 50% šířky, vystředěná) — accent místo full-width line
- Pin: 44×44 s gradient background (FAEEDA→F4DDB8), inset highlight, hover lift effect

**Sidebar-logo:**
- Border-bottom smazán, místo toho stejná krátká dashed čára (sjednocený design)
- Logo-icon zmenšeno na 36px (consistent)

### 📦 Build & sync
- Bumped: config.php 3.0.68→3.0.69, admin.js, sw.js, HTML asset URLs

---

## [3.0.68] — 2026-05-27

### 🖥 Desktop sidebar = full-screen flex menu (nav od shora dolů)
_User: "full screen flex menu allways - na PC! mobil je dobrej už"_

- `@media (min-width: 701px) .sidebar-nav .nav-item { flex: 1 1 0; max-height: 80px }`
- Nav items na desktopu rozdělí celou výšku sidebaru rovnoměrně (žádné prázdné místo dole)
- Mobil zůstává beze změny (3-col grid, 64px buttony, fungující)
- Safe-guard: max-height 80px aby při role='pos' (1 item) nepřerostl absurdně

### 📦 Build & sync
- Bumped: config.php 3.0.67→3.0.68, admin.js, sw.js, HTML asset URLs

---

## [3.0.67] — 2026-05-27

### 📌 Špendlík v rail = NATURAL POZICE pod ikonami (žádné gold pill)
_User: "chci to menu jak bylo ne oválky! mobil boční menu jak bylo ikony a pod nimi špendlík!"_

**Kompletní přepis rail mode:**
- Sidebar: `height: calc(100dvh - 76px)` (stop nad bottom-nav 72px + 4px gap) — pin se vejde DOVNITŘ sidebaru, žádné překrytí
- Sidebar = `display: flex; flex-direction: column` (logo → nav → utils)
- Nav: `flex: 1 1 auto; overflow-y: auto` (scrollable pokud nevejde)
- Nav-items: `flex: 0 0 44px` (fixed 44×44 ikony, ne stretching)
- Sidebar-utils: `flex: 0 0 auto; border-top` (separator nad pinem)
- Pin: **`position: static`** (žádné fixed/absolute), 44×44, světlo-zlaté pozadí + border, ikona 20px

Žádný floating gold pill (v3.0.62), žádný kulatý design (v3.0.62), žádný stretching nav (v3.0.61).
Pin je TAM kde má být — pod ikonami v natural flow.

### 📦 Build & sync
- Bumped: config.php 3.0.66→3.0.67, admin.js, sw.js, HTML asset URLs

---

## [3.0.66] — 2026-05-27

### 🐛 JS chyby + pin v rail rozšířen na iPad + nav od shora dolů
_User: "kde je špendlík? a menu od shora dolu?" + screenshoty s 3 JS chybami_

**Fix #1 — `Chyba: users.filter is not a function`:**
- `renderUsers()` defensive: pokud API vrátí ne-array → zkusit `.users`/`.data` wrap, fallback `[]`

**Fix #2 — `Chyba: can't access property "length", d.zalohy is undefined`:**
- `zalohyRefresh()` defensive: `d.zalohy = Array.isArray(d.zalohy) ? d.zalohy : []`
- Plus normalizace `d.pocet` + `d.celkova_velikost`

**Fix #3 — `Chyba: can't access property "connected", db is undefined`:**
- `diagRychly()` defensive: všechny d.system/database/schema/endpoints/collisions přes `|| {}` fallback
- Array.isArray checks na sch.issues + sys.missing_extensions

**Fix #4 — Pin v rail mode rozšířen na iPad (≤1100px):**
- v3.0.63 mělo @media (max-width: 700px) — iPad portrait (768) + landscape mimo
- Rozšířeno na 1100px, pokrývá iPhone, iPad portrait, iPad landscape

**Fix #5 — Nav v rail od shora dolů:**
- `body.sidebar-pinned .sidebar` = flex column
- `.sidebar-nav` flex: 1 1 auto, min-height: 0
- `.nav-item` flex: 1 1 0 → rozdělí výšku rovnoměrně + jen ikona (22px)

### 📦 Build & sync
- Bumped: config.php 3.0.65→3.0.66, admin.js, sw.js, HTML asset URLs

---

## [3.0.65] — 2026-05-27

### ⏪ REVERT v3.0.61 nav stretching + scroll fix
_User: "pořád jsou ty buttony hrozně vysoké na mobilu dej je jak byly. a nejde scrolovat."_

**Root cause:**
- v3.0.61 dal sidebar `min-height: calc(100dvh - 56px)` + nav `grid-auto-rows: 1fr`
- Sidebar v unpinned mobile zabíral CELÝ viewport → user musel scrollovat 100dvh než viděl obsah
- Buttony se roztáhly přes celou výšku → "hrozně vysoké"
- Sticky bottom sidebar-utils navíc blokoval scroll

**Fix:**
- Sidebar: `min-height: 0; height: auto; padding-bottom: 12px` (auto sizing)
- Nav-item: `min-height: 64px; flex: none; font-size: 14px` (back to original)
- Nav-icon: `font-size: 20px` (back to original)
- Grid: `grid-auto-rows: auto` (ne 1fr)
- Sticky bottom sidebar-utils → jen desktop (`@media min-width: 701px`)
- HTML/body: `overflow-y: auto !important; -webkit-overflow-scrolling: touch`
- Main-content: `padding-bottom: 88px` (aby bottom-nav nezakrýval poslední řádek)

### 📦 Build & sync
- Bumped: config.php 3.0.64→3.0.65, admin.js, sw.js, HTML asset URLs

---

## [3.0.64] — 2026-05-27

### 🐛 Bug fix: Dodací listy prázdný seznam, ale dashboard alert ukazuje 8
_User: "kde jsou data z dodacích listu"_

**Root cause:**
- `admin_dodaci_listy.php` GET list endpoint měl `JOIN odberatele od ON od.id = dl.odberatel_id` (INNER JOIN)
- Pokud DL má `odberatel_id` ukazující na neexistujícího (smazaného) odběratele → DL **zmizí ze seznamu**
- Dashboard alert používá `SELECT COUNT(*) FROM dodaci_listy WHERE fakturovano = 0` (bez JOIN) → správně počítá 8
- User viděl prázdný list ale alert "8 nefakturovaných"

**Fix:**
- API: `INNER JOIN odberatele → LEFT JOIN odberatele` — orphan DL zůstanou viditelné
- UI: tabulka + karta fallback `${esc(d.odberatel_nazev || '⚠️ smazaný odběratel')}` aby user věděl proč nemá odběratele

### 📦 Build & sync
- Bumped: config.php 3.0.63→3.0.64, admin.js, sw.js, HTML asset URLs

---

## [3.0.63] — 2026-05-27

### 🎨 Brand text v topbaru + pin v rail = zaoblený obdélník
_User: "ten název toho zákazníka při odepnutém menu na mobilu kde je? ten button připnout dobrý ale špendlík kulatej je hnusnej! dej to jak to bylo jen ten špendlík si měl posunout vejš."_

**Fix #1 — Brand text (firma name) v sidebar-logo na mobile:**
- v3.0.51 schoval `.sidebar-logo strong` na celém mobilu (kvůli `display: contents` bugu)
- Tento bug už neexistuje od v3.0.52 → vrátit brand text na unpinned mobile
- `body:not(.sidebar-pinned) .sidebar-logo strong { display: inline-block; font-size: 15px; font-weight: 700; max-width: 50%; ellipsis }`
- V pinned (rail) zůstává hidden (jen ikona)

**Fix #2 — Pin v rail = ROUNDED SQUARE (ne kruh):**
- v3.0.62 použil circle design (border-radius: 50%) — user: "kulatej je hnusnej!"
- Vrátit původní design: 40×40 rounded square (border-radius: 10px), gold gradient
- Zachovat `position: fixed; bottom: 84px; left: 8px; z-index: 90` (nad bottom-nav)
- Sidebar-utils v rail: `display: none` (pin escapes via fixed)

### 📦 Build & sync
- Bumped: config.php 3.0.62→3.0.63, admin.js, sw.js, HTML asset URLs

---

## [3.0.62] — 2026-05-27

### 🔴 KRITICKÝ FIX: Špendlík v rail módu KONEČNĚ viditelný (4. pokus, bulletproof)
_User (frustrovaný): "neni vidět špendlík! tohle jsem psal už 4X!!!!!"_

**Historie selhání:**
- v3.0.51: opravil hide-pin na login (jiný problém)
- v3.0.55: pin výraznější (ale stejná pozice — pod bottom-nav)
- v3.0.57: opravil transparent override (ale stejná pozice)
- v3.0.59: smazal sticky circle, vrátil natural flow (stále pod bottom-nav)
- v3.0.61: padding-bottom 88px na sidebar (možná bypassed jinou rule)

**Bulletproof v3.0.62 fix — `position: fixed` na pin:**
- Pin button v rail mode: `position: fixed !important; bottom: 84px; left: 4px; z-index: 90`
- ŽÁDNÉ dependencies na sidebar overflow/padding/height — pin escapes layout úplně
- 48×48px round button, gold gradient (BA7517→854F0B), bílý border 2px, double shadow
- z-index 90 garantuje že je NAD bottom-nav (z-index 50)
- Sekundární pojistka: sidebar height `calc(100dvh - 76px)` aby se ostatní obsah neoverflowoval

### 📦 Build & sync
- Bumped: config.php 3.0.61→3.0.62, admin.js, sw.js, HTML asset URLs

---

## [3.0.61] — 2026-05-27

### 📱 3 mobile fixy z user feedbacku (pin v rail visible, nav full-height, pin gaps)
_User: "neni vidět špendlík! 1. ve připnutém menu na mobilu. 2. buttony nejsou pořád přes celou obrazovku od shora dolu - levé menu odepnuté. 3. připnout button ne úlně do okrajů ten button vycentrovat a dát gapy po stranách."_

**Fix #1 — Pin viditelný v rail módu (sidebar-pinned mobile):**
- Root cause: `.bottom-nav` má fixed `bottom: 0`, height ~72px → překryje pin na konci 100dvh sidebaru
- `padding-bottom: 88px` na `body.sidebar-pinned .sidebar` (bottom-nav 72px + 16px safe area)
- Pin tlačítko v rail teď výrazný gold gradient (BA7517→854F0B) s glow shadow + bílá ikona s rotate(-15deg)
- Sidebar-utils: gradient pozadí + dashed border-top jako separator

**Fix #2 — Nav buttons stretch full viewport height (unpinned mobile):**
- Sidebar `min-height: calc(100dvh - 56px)` (viewport − topbar) + flex column
- `.sidebar-nav { flex: 1 1 auto; grid-auto-rows: 1fr }` — všechny řady stejně vysoké, zaberou zbylou výšku
- Nav-item `min-height: 0 !important; height: 100%; flex: 1 1 auto` — zruší rigid 64px
- Větší fonty: nav-item 15px, nav-icon 26px (proporční ke zvětšenému buttonu)

**Fix #3 — "Připnout menu" button: gaps po stranách (unpinned mobile):**
- `.sidebar-utils { padding-inline: 16px }` — gap od okraje sidebar containeru
- `.sidebar-pin { width: auto; max-width: 100%; border-radius: 12px; justify-content: center }`
- Místo full-width edge-to-edge teď vycentrovaný button s prostorem po stranách

### 📦 Build & sync
- Bumped: config.php 3.0.60→3.0.61, admin.js, sw.js, HTML asset URLs

---

## [3.0.60] — 2026-05-27

### 📱 Mobile UX follow-up (period tabs + sidebar overflow + swipe-dismiss alerts)
_User: "to nova objednavka udelat swipe zavřít do strany" + 7 screenshotů ze APPEK_FILES. Bugy zjištěné po v3.0.59 testu._

**Period tabs D/T/M/R/V — fix ellipsis:**
- Root cause: extreme breakpoint ≤400px (iPhone 14 Plus 428, Pro Max 430 mimo extreme mode → zobrazoval "D...", "T..." s ellipsis)
- JS `periodTabsRender`: práh `≤400 → ≤460px`
- CSS `@media`: `400px → 460px`, přidán `overflow: visible` + `text-overflow: clip` → "D" se ukáže celé bez ellipsis

**Sidebar — všechny nav items vidět při kratším viewportu:**
- User: "musí se tam vejít všechny položky-zmenšovat flex.a roztyhovat podle obrazovky"
- Přidány `@media (max-height: 800px/500px)` s progresivně menšími nav-items (min-height 30/24px, font 12.5/11.5px)
- `.sidebar > .sidebar-utils` position: sticky bottom — pin/Skrýt vždy přístupné

**Swipe-to-dismiss pro „Akce vyžadující pozornost":**
- User: "to nova objednavka udelat swipe zavřít do strany"
- JS handler: `touchstart/move/end` listener na `.dash-alerts`
- Horizontal swipe > 80px → dismiss + 1h hide (sdílí state s ✕ buttonem)
- Threshold ANGLE_TOL 1.5: pokud `|dy| > |dx| * 1.5` → vertikální scroll, nezruší swipe handler
- Vizuál: translateX follows finger + opacity fade; snap-back pokud < threshold
- CSS: `touch-action: pan-y` + swipe hint `⇆` v pravém dolním rohu (jen mobile)

### 📦 Build & sync
- Bumped: config.php 3.0.59→3.0.60, admin.js, sw.js, HTML asset URLs

---

## [3.0.59] — 2026-05-26

### 📱 Pin natural position + svátek viditelný + POS compact iPhone
_User: "v adminu nevidim svatek jmeno. spendlik smaz, ma byt pod menu kdyz schovane, a kdyz se pripne, tak dole pod nastavenim ikonou pod prerusovanou carou. pos kompaktni na iphone"_

**Pin button — natural sidebar position (mobile):**
- Smazán sticky/fixed circle pin (z v3.0.55-57)
- Pin teď flowí naturally v `.sidebar-utils` na konci sidebaru
- `border-top: 2px dashed` jako separator mezi nav items a pin
- Unpinned state: primary-tinted button (rgba 10%) s ikonou + label
- Pinned state: full primary gradient (BA7517→854F0B) + bílý text
- `sidebar-collapse` skryt na mobilu (jen pro desktop)

**Svátek viditelný (admin topbar):**
- Root cause: `@media (max-width: 1280px)` na řádku 15891 hide `.ts-svatek` přepisoval předchozí fixy nižší specifity
- Fix: vyšší specifita přes `body:not(.sidebar-pinned) .sidebar-logo .topbar-datum-svatek`
- font-size `10px → 12px`, color `var(--text-2) → #5C3608` (kontrast s chip pozadím)
- font-weight `700 → 800` na svátek strong
- `display: block` (2-řádkový stack: datum / svátek)

**POS compact na iPhone (≤480px portrait):**
- Header: `--pos-header-h: 56px → 44px`, kategorie 64px, grid 2-col
- Cart kompakt, total 21px, action icons 36×40px
- iPhone SE (≤375px): brand-name hidden, tile 56px

### 📦 Build & sync
- Bumped: config.php 3.0.58→3.0.59, admin.js, sw.js, HTML asset URLs

---

## [3.0.58] — 2026-05-26

### 📱 Extreme mode: zvětšeno bez deformace + i18n B19
_User: "spousta toho zůstává malé v extreme mode. zvětši ale nedeformat na všech theme. dopřeložit další dávky"_

**Period tabs (≤400px) zvětšeno:**
- icon `14px → 19px`
- text `13px → 16px` (weight 800, letter-spacing 0.05em)
- padding `5px 2px → 9px 6px`
- gap `3px → 5px`, button gap `1px → 3px`
- min-height `52px` (touch-friendly)
- D/T/M/R/V teď čitelné z dálky, theme-agnostic

**i18n batches (B19) — 44 stringů z 6 batchů:**
- 5 reálných překladů (DL, dodaci_listy, Položky, IČO/DIČ, Volitelná…) → en/es
- SK/DE překlady přes i18n_dicts_extra.py
- 39 pass-through (technické názvy, emaily, URLs, IDs — admin/appek.cz/Client ID/Customer ID/atd.)
- `i18n_merge.py` tolerantní k chybějícím batches (existing files only)

### 📦 Build & sync
- Bumped: config.php 3.0.57→3.0.58, admin.js, sw.js, HTML asset URLs

---

## [3.0.57] — 2026-05-26

### 🐛 Pin button hapruje — root cause + fix
_User: "ten špendlík tam hapruje pořád"_

**Audit přes Claude_Preview MCP + CSSOM walker** nalezeno:
- Pin element JE v DOM s mým v3.0.55 styling (position fixed, w/h 48, border-radius 50%, color white)
- **ALE background gradient nebyl aplikován** — pin byl neviditelný/transparentní

**Root cause**: Historická rule na řádku 13811 přepisovala můj gradient:
```css
@media (max-width: 700px) {
  .sidebar-pin, body.sidebar-pinned .sidebar-pin, body.sidebar-collapsed .sidebar-pin {
    background: transparent !important;  /* ← bug */
    border-color: transparent !important;
    box-shadow: none !important;
  }
}
```

Tato rule pocházela z v2.9.x kdy pin měl být "jen ikona". Po v3.0.55 (sticky circle button) měla být odstraněna, ale zůstala → konflikt s `!important` na obou stranách → vyhrávalo source order (později definované).

**Fix**: Rule odstraněna (komentář ponechán pro historii).

### 📦 Build & sync
- Bumped: config.php 3.0.56→3.0.57, admin.js, sw.js, HTML asset URLs

---

## [3.0.56] — 2026-05-26

### 📱 Extreme mobile: 1-letter period tabs (D/T/M/R/V)
_User: "na extrem ui na mobilu dej v kalendářích jenom první písmena D T M R V, všude ve všech theme"_

**`periodTabsRender` upraveno na 3 size tiers:**
- **≤400px (extreme)**: `t.x` nebo auto-derived 1. písmeno z `t.short`/`t.l` → D/T/M/R/V
- **≤700px (mobile)**: short label (`Dnes/Týden/Měsíc/Rok/Vlastní`)
- **desktop**: full label (`Dnes/Tento týden/...`)

Auto-derivation = funguje pro VŠECHNY callery bez nutnosti updatu (Dashboard, Faktury, Objednávky, Dodací listy, Výroba).

**Resize listener**: tracking i `lastIsExtreme` (≤400) — re-render při transition mezi tiers.

**CSS pro extreme mode** (≤400):
- `period-tabs gap: 3px, padding: 3px`
- `period-tab padding: 5px 2px, flex-direction: column`
- `period-tab-icon: 14px, period-tab-text: 13px weight 800`
- **Theme-agnostic** (žádné theme-specific overrides) → funguje v light/dark/win98/apple/all themes

### 📦 Build & sync
- Bumped: config.php 3.0.55→3.0.56, admin.js, sw.js, HTML asset URLs

---

## [3.0.55] — 2026-05-26

### 🐛 4 mobile UX fixes (z testování)
_User: "pin je tam i při zavřeném menu / Akce dej dashed + dismiss / DL víc zvýraznit funkční / sidebar do půlky, má být full s flex"_

**Fix 1 — Sidebar flex full-height (desktop)**: Sidebar-nav items teď `flex-shrink: 1` aby se vždy vešly. Plus media queries pro krátké viewporty (≤700px, ≤600px) → menší padding/gap/font.

**Fix 2 — Akce vyžadující pozornost: dashed border + dismiss**
- `2px dashed #FBBF24` border (předtím solid)
- ✕ Dismiss button v hlavičce (skryje na 1h via localStorage `appek_alerts_dismissed_until`)
- Slide-out animace

**Fix 3 — DL/FA badge prominence**
- Funkční (clickable `<a>`) badge: 2px border + gradient bg + box-shadow + hover lift
- Unavailable: opacity 0.45 + grayscale + cursor not-allowed
- Předtím vypadaly skoro stejně — teď okamžitě vidíš co je akce vs disabled

**Fix 4 — Pin button state-aware**
- Skrytý když `body.sidebar-collapsed` (user nechce pin když je menu zavřené)
- Zůstává viditelný v default mobile stavu

### 📦 Build & sync
- Bumped: config.php 3.0.54→3.0.55, admin.js, sw.js, HTML asset URLs

---

## [3.0.54] — 2026-05-26

### 🐛 Mystery "button pod A" identifikováno + opraveno
_User: "co to je za tlačítkem pod tím A? v levo nahoře?"_

**Root cause**: Cmd+K search trigger (`.btn-cmdk-trigger`) byl na mobilu 189×38px **prázdný pill** s jen 🔍 ikonou + bez labelu. Vypadal jak mystery button.

**Fix**: na mobile (≤768px) search button = **kruhové ikon-only 38×38**, čistý 🔍, jasný shape (matches notif bell/ostatní topbar ikony).

### 🧹 GitHub cleanup
- Smazáno **63 v2.9.x releases** + **128 v2.9.x tags** přes GitHub API
- Repo Chossekilla/appek nyní obsahuje jen v3.0.x

### 📦 Build & sync
- Bumped: config.php 3.0.53→3.0.54, admin.js, sw.js, HTML asset URLs

---

## [3.0.53] — 2026-05-26

### 🐛 Sticky pin button na mobilu
_User: "špendlík neni vidět jak jsem psal"_

**Root cause**: Pin button v `.sidebar-utils` byl na pozici top=758 (h=44) → zakrytý bottom-navem (start ~755).

**Fix**: Pin button na mobilu se chová jako mini-FAB:
- `position: fixed` vlevo-dole, **nad bottom-nav** (`bottom: calc(72px + safe-area-inset-bottom)`)
- Kruh 48×48, primary gradient
- Aktivní stav (sidebar-pinned) → success zelený gradient (vizuální indikace)
- z-index 89 (nad content, pod offline banner)
- Skrytý na login (přes `body.is-login` / `:has()`)
- iOS safe-area podpora
- Labely (Připnuto / Připnout menu) skryté (jen ikona 📌)

**Bonus**: `.sidebar-collapse` button skrytý na mobilu (jen pro desktop UX)

### 📦 Polling task
- Změněno: 10 min → **15 min** interval (`*/15 * * * *`)

### 📦 Build & sync
- Bumped: config.php 3.0.52→3.0.53, admin.js, sw.js, HTML asset URLs

---

## [3.0.52] — 2026-05-26

### 🐛 Fix sidebar over login (display:contents bypass) + FAB swipe-to-dismiss
_Z polling screenshots (APPEK_FILES)_

**Bug 1: Sidebar nav-items se zobrazují přes login screen**
- Root cause: `.sidebar { display: contents }` na mobilu — `display: contents` znamená že element neexistuje pro layout a jeho děti flow do parent gridu. Bypassuje `#app[display:none]` protože `display: contents` skip parent display.
- Fix: explicit `body.is-login .sidebar, body.is-login .sidebar * { display: none !important }` + `:has()` selector pro modern browsery

**Bug 2: FAB swipe-to-dismiss** _User: "to nová objednávka udělat swiper zavřít do strany"_
- `appekFabSwipeBind(fab)` — touch handlers s axis-lock (jen horizontal swipe doprava)
- Visual: translateX podle prstu, opacity fade-out (0→0.8)
- Threshold 100px → dismissed, slide-out 220px → `.is-dismissed` class (display:none)
- Persisted per-stránka v localStorage `appek_fab_dismissed_page` (přejdeš jinam → FAB znovu)
- Haptic medium na dismiss

### 📦 Build & sync
- Bumped: config.php 3.0.51→3.0.52, admin.js, sw.js, HTML asset URLs

---

## [3.0.51] — 2026-05-26

### 🐛 Mobile: hide sidebar over login + APPEK text + dashboard crash
_User: "menu nemůže být natvrdo přes login. APPEK najednou ukazuje název firmy. pin na mobilu zmizel. otevři admin mobile a proklikej"_

**Audit přes Claude_Preview MCP (375×812)** — 3 viditelné bugy:

**Bug 1**: `app-fab`, `ptr-indicator`, `pwa-install-banner`, `bottom-nav`, `offline-banner` jsou v body MIMO `#app` div → zobrazují se i přes login screen
**Fix**: CSS `body:has(#app[style*="display:none"]) #app-fab, ... { display: none }` + class fallback `body.is-login` (`appekInitLoginState()` na init detekuje že app je hidden → přidá class; `showApp()` ji odstraní)

**Bug 2**: "APPEK B2B" brand text se zobrazuje v topbaru na mobilu — sidebar má `display: contents` na mobilu, takže `.sidebar-logo strong` flow do parent gridu
**Fix**: `@media (max-width: 768px) { .sidebar-logo strong { display: none !important } }`

**Bug 3**: Dashboard crash `Cannot read properties of undefined (reading 'objednavek')` — `renderDashboard()` neguardoval `d.dnes` a `d.po_splatnosti`
**Fix**: Přidány defenzivní fallbacky v `renderDashboard()` po stávajících guards

### 📦 Build & sync
- Bumped: config.php 3.0.50→3.0.51, admin.js, sw.js, HTML asset URLs

---

## [3.0.50] — 2026-05-26

### 🐛 REVERT — smazána broken mobile-rail logika
_User: "celé je to nefunkční. floating ≡ je vidět i přes login screen. APPEK najednou ukazuje název firmy. smaž to, nech jen pin (📌)"_

**Smazáno** (kompletní revert v3.0.46/47/48):
- HTML: `<button id="mobile-rail-restore">≡</button>` (floating button — bug: zobrazoval se přes login screen, byl mimo `#app` div)
- HTML: `.nav-item-mob-hide` button v sidebaru
- JS: `hideMobileSidebar()` / `showMobileSidebar()` funkce
- JS: localStorage restore z `appek_mobile_rail_hidden`
- CSS: `#mobile-rail-restore` styling (44×44 gradient button)
- CSS: `.nav-item-mob-hide` styling (primary-tinted nav-item)
- CSS: `body.mobile-rail-hidden` state classes (sidebar hide + grid reflow)

**Nechano (existující v0+ funkce):**
- `sidebar-pin` button (📌 v sidebar-utils) — uživatel chce TENTO jako jediný toggle
- `sidebar-collapse` button (◀) — pro desktop
- Bottom nav mobile

### 📦 Build & sync
- Bumped: config.php 3.0.49→3.0.50, admin.js, sw.js, HTML asset URLs

---

## [3.0.49] — 2026-05-26

### 🐛 Fit-to-screen + mobile overflow audit
_User: "nei to fit to screen, je to flow, jde hýbat s celou stránkou, chci pevné okraje + zkontroluj jestli něco na mobilu nepřetýká"_

**Root cause**: `overflow-x: hidden` na html/body samo nestopne horizontální pan gesto na iOS Safari. Bylo třeba `touch-action: pan-y` (jen vertikální scroll, žádný horizontal pan).

**Plus**: `.sidebar-logo` měl `margin: -4px 4px 16px 4px` — 4px horizontal margin způsobovalo 4px overflow (379px vs viewport 375px).

**Fixes**:
1. `html, body` — přidáno `touch-action: pan-y` + `overscroll-behavior-x: none` (+ `!important` defenzivně proti pozdějším override)
2. `.sidebar-logo` — odstraněn horizontal margin (jen `margin: -4px 0 16px`), přidáno `max-width: 100%` + `box-sizing: border-box`
3. Odstraněno duplicitní `html, body { overflow-x: hidden }` na řádku 19586 (už je v main rule s !important)

**Verifikace přes Preview MCP (mobile 375×812):**
- Dashboard: bodyScrollW = 375 ✅
- Objednávky: 375 ✅
- Výrobky: 375 ✅
- Nastavení: 375 ✅
- Restaurace: 375 ✅
- `touch-action: pan-y` ✅
- `overscroll-behavior-x: none` ✅
- Žádné offenders (elements wider than viewport)

### 📦 Build & sync
- Bumped: config.php 3.0.48→3.0.49, admin.js, sw.js, HTML asset URLs

---

## [3.0.48] — 2026-05-26

### 📱 Mobile nav simplify — jen schovat / zobrazit sidebar
_User: "nech tam jen tlačítko schovat postraní menu, nic víc, zbytek smaž"_

**Smazáno** (z v3.0.46/v3.0.47):
- Celá 3-state cycle logika (`cycleMobileNav`)
- Expanded mode (`mobile-sidebar-expanded` class — sidebar 240px s labely)
- `mobile-bottom-hidden` state (skrývání bottom navu)
- CSS pro expanded layout + active state v cycle buttonu

**Nechan ONLY:**
- ◀ "Schovat menu" button v sidebaru pod Nastavením (vypadá jako nav-item)
- `hideMobileSidebar()` — schová sidebar
- Floating ≡ top-left když je sidebar schovaný
- `showMobileSidebar()` — vrátí sidebar
- localStorage persist (`appek_mobile_rail_hidden`)

**Default chování:** sidebar + bottom nav obojí. Klik na ◀ schová sidebar (content full šířka). Klik na floating ≡ vrátí sidebar.

### 📦 Build & sync
- Bumped: config.php 3.0.47→3.0.48, admin.js, sw.js, HTML asset URLs

---

## [3.0.47] — 2026-05-26

### 📱 Mobile nav fix — ONE cycle button (přepsání v3.0.46)
_User correction: "jedna ikona pod nastavením (jakoby další z menu), 3 stavy cyklem"_

**Předtím** (v3.0.46): 2 dashed/colored buttony pod logem A + pod Nastavení — rušilo design sidebaru
**Teď** (v3.0.47): JEDNA ikona pod Nastavení stylovaná jako další nav-item + floating ≡ když je sidebar skrytý

**Cycle:** A → B → C → A → ...
- **A (default)**: rail + bottom nav (label "Sbalit menu")
- **B (rail-hidden)**: jen bottom + floating ≡ top-left, full WIDTH content (klik na floating ≡ = B → C)
- **C (expanded)**: sidebar FULL s labely + skrytý bottom, full HEIGHT content (label "Sbalit do railu" + active gradient bg)

**Implementace:**
- HTML: `.nav-item.nav-item-mob-cycle` jako poslední button v `.sidebar-nav` (vypadá jako nav-item, primary-tinted bg)
- HTML: `#mobile-rail-restore` floating ≡ (44×44, gradient bg, jen při state B)
- JS: `cycleMobileNav()` — state machine s 3 stavy, label se mění podle stavu
- CSS: nový `mobile-sidebar-expanded` class — sidebar 240px wide, labely nav-items viditelné
- localStorage: `appek_mobile_nav_state` (default/rail-hidden/expanded) — persist přes reload

**Odstraněno**: `.sidebar-mob-fullheight`, `.sidebar-mob-collapse` (oba dashed buttony z v3.0.46 — rušilo design)

### 📦 Build & sync
- Bumped: config.php 3.0.46→3.0.47, admin.js, sw.js, index.html (HTML cleanup), HTML asset URLs

---

## [3.0.46] — 2026-05-26

### 📱 Mobile nav 3-state toggle (sbalit rail / full výška)
_User: "pod Nastavení ikonu — sbalit menu (jen bottom zůstane, full screen). Pod A — expand (zmizí bottom, full výška)"_

**3 stavy na mobilu (≤768px):**
- **A (default)** — rail + bottom nav obojí viditelné
- **B (mobile-rail-hidden)** — jen bottom nav, content full **width**
- **C (mobile-bottom-hidden)** — jen rail, content full **height**

**Buttony:**
1. **`⤢ Plná výška`** v sidebaru pod logem A → State C (skryje bottom)
2. **`📵 Sbalit rail`** v sidebar-utils pod Nastavení → State B (skryje rail)
3. **`≡` floating hamburger** top-left když je State B → návrat do A (rail show)

**Logika:**
- Klik na "Plná výška" zruší State B (mutually exclusive)
- Klik na "Sbalit rail" zruší State C
- Stav persisted v localStorage (`appek_mobile_rail_hidden`, `appek_mobile_bottom_hidden`)
- Haptic `medium` při toggle

**Layout úpravy per stav:**
- State B: `.admin-app { grid-template-columns: 1fr }` (sidebar pryč), `.topbar { padding-left: 68px }` (uvolnit místo pro hamburger)
- State C: `.bottom-nav { display: none }`, `.content { padding-bottom: 0 }`, `.app-fab { bottom: 16px }` (níž protože nav nepřekáží)
- Active state "Plná výška" tlačítko dostává plný gradient bg + bílý text + ↩ ikona pro návrat

**Buttony na desktop** skryté (jen mobile UX).

### 📦 Build & sync
- Bumped: config.php 3.0.45→3.0.46, admin.js, sw.js, index.html (nový button + restore), HTML asset URLs

---

## [3.0.45] — 2026-05-26

### 🐛 Sidebar fix — od shora dolu přes celou obrazovku
_User: "to postraní menu nei pořád přes celou obrazovku, od shora dolu"_

**Bug**: `html, body` CSS reset chyběl `margin: 0` → výchozí browser margin **8px** posunoval celou aplikaci od top/left okraje. Sidebar tak začínal na Y=8px, ne Y=0, a celá app měla 16px viditelnou mezeru kolem.

**Fix**:
```css
html, body {
  margin: 0;
  padding: 0;
  overflow-x: hidden;
  max-width: 100%;
  height: 100%;       /* aby 100vh / 100dvh správně */
}
```

**Bonus**: `.admin-app` má teď `min-height: 100dvh` (dynamic viewport — důležité na mobilech kde browser UI bar pohlcuje místo). Předtím jen `100vh` = na iOS Safari spodní UI překrýval bottom-nav.

**Bonus 2**: `.sidebar { align-self: start }` — zajistí že sidebar (sticky) je správně ukotvený v grid řádku, nestrečuje se nečekaně.

### 📦 Build & sync
- Bumped: config.php 3.0.44→3.0.45, admin.js, sw.js, HTML asset URLs

---

## [3.0.44] — 2026-05-26

### 🐛 Bug audit po v3.0.38-43 (security + UX + cleanup)
_User: "projed bugy po změnách, hezky čistý kod"_

**HIGH — Security:**
- 🔐 **Webhook signature bypass FIXED** ve všech 4 receiverech (`wolt/bolt_food/damejidlo/foodora_webhook.php`). Předtím: chybějící `X-*-Signature` header → kontrola se přeskočila → útočník mohl spoofovat objednávky. Teď: missing header = HTTP 401 + log jako `rejected_no_signature`.

**HIGH — Broken:**
- 🔧 **FAB function refs** opraveny ve 4 místech (`openVyrobekModal` → `window.editVyrobek`, `openImportVyrobky` → `window.otevritImportVyrobku`)
- 🔧 **`appekScanFromVyrobky`** používalo neexistující `?search_ean=` endpoint → přidán `?ean=` endpoint do `admin_vyrobky.php` + opraven volání `editVyrobek()` (nebere 2. argument)
- 🔧 **Swipe haptic tick** — `swiping.threshHit = true` na boolean byl no-op (haptic na threshold se nikdy nezavolal). Standalone `threshHit` var + `>=` místo `===` (float dx rarely exact)
- 🔧 **Offline banner z-index** `9999` → `800` (předtím blokoval modal close button — modal je 1000)

**MEDIUM — UX:**
- 📱 **iOS PWA install hint** přidán (Safari nedispatchne `beforeinstallprompt` → bez hintu uživatel neví že lze instalovat). Po 30s na iPhone bez standalone mode se ukáže "Klepni Sdílet → Přidat na plochu"
- 🎨 **`renderTableTile` — t.barva** nyní jen pro `stav='free'`. Předtím custom barva přepsala occupied/reserved gradient → ztracený stav signal pro waitera z dálky
- 🎨 **Swipe underlay positioning** přepsáno z snapshot offsetLeft/Top na `<div class="swipe-wrap">` wrapper. Při resize/reflow už není desync

**LOW — Cleanup:**
- 🧹 **Duplicate `.bottom-nav { display: none }`** CSS rule odstraněn (řádek 13534 byl identický s 12495)
- 🧹 **Category regex** v `renderTableTile` — duplicitní `🍻` v bar+family branch (family byl dead code, first match wins = bar). Přepsáno na priority order: grill > stage > family > lounge > garden > bar

### ✅ Verifikace
- PHP lint: všech 7 souborů OK
- CSS brace balance: 3716/3716 (po stripping komentářů)
- Žádné neexistující function references v APPEK_FAB_CONFIG/SHEET

### 📦 Build & sync
- Bumped: config.php 3.0.43→3.0.44, admin.js, sw.js, HTML asset URLs

---

## [3.0.43] — 2026-05-26

### 🎨 Sub-tabs jako mini-bannery (sjednoceno se v3.0.42)
_User: "ty pod tím taky předělej, ted to vypadá nedodělně"_

**4 sub-tabs ve Stoly view** — z malých text-pillů na color-coded mini-bannery:
| Tab | Emoji | Gradient | Sub-text |
|---|---|---|---|
| **Layout** | 🗺️ | modrý | Floor plan editor |
| **Rezervace** | 📅 | fialový | Timeline · seznam |
| **Otevřené účty** | 🧾 | zelený | Aktivní POS účty |
| **QR queue** | 📲 | oranžový | Pending objednávky |

**Zone tabs** (Zahrada / Pergola / atd.) — z plain pillů na **color-coded pill-bannery**:
- Auto-cycle barev z 5-color palette (green/blue/purple/orange/pink) podle pořadí zóny
- Glass count badge (rgba alpha) pro counts
- Active stav: plný gradient bg + bílý text + shadow + lift
- Hover lift -2px + saturate(+8%)
- Surface highlight overlay přes `::before`

**Add Zone button** redesign (dashed border, hover na primary)

**Mobile responsive:**
- Sub-tabs: 2 sloupce grid (≤700px)
- Zone tabs: kompaktnější padding, smaller text/icon

**Dark mode podpora** pro všechny nové komponenty

### 📦 Build & sync
- Bumped: config.php 3.0.42→3.0.43, admin.js, sw.js, HTML asset URLs

---

## [3.0.42] — 2026-05-26

### 🎨 Velké barevné bannery místo malých tabů (Restaurace)
_User: "to horní menu udělej buttony velké bannery až"_

**Před:** 6 malých text-pillů v `.nastaveni-tabs` (📺 Provoz | 🧾 POS Kasa | 🪑 Stoly | …)

**Teď:** 6 velkých color-coded banner cards (`.rest-banner-tabs` grid):

| Tab | Emoji | Gradient | Sub-text |
|---|---|---|---|
| **Provoz** | 📺 | modrý (#3B82F6→#1E40AF) | Live monitor + KDS |
| **POS Kasa** | 🧾 | zelený (#10B981→#065F46) | Restaurační pokladna |
| **Stoly** | 🪑 | amber (#BA7517→#854F0B) | Layout · rezervace |
| **Kapacita kuchyně** | 👨‍🍳 | červený (#EF4444→#991B1B) | Stanice · max paral. |
| **Doba přípravy** | ⏱️ | fialový (#A78BFA→#5B21B6) | Min. per výrobek |
| **Rozvoz / Kurýři** | 🛵 | oranžový (#F97316→#9A3412) | Wolt · Bolt · vlastní |

**Layout:**
- Desktop: `repeat(auto-fit, minmax(170px, 1fr))` — 6 v řadě
- Mobile (≤700px): 2 sloupce, kompaktnější padding

**Banner anatomy:**
- Velký emoji v glass-pillu (48×48px) s `backdrop-filter: blur(4px)`
- Label (15px, weight 800, letter-spacing -0.01em)
- Sub-text (11.5px, weight 600) s krátkým popisem
- Surface highlight overlay přes `::before` (radial gradient, mix-blend overlay)

**Aktivní stav:**
- Plný gradient bg + bílý text
- Shadow `0 10px 28px {color}80, 0 4px 10px rgba(0,0,0,0.18)`
- Drop-shadow filter na emoji
- Translate -2px (slightly elevated)

**Inactive stav:**
- Light bg (cat color light), dark text
- 2px solid border (same light color)

**Interakce:**
- Hover: translateY(-3px) scale(1.02) + saturate(1.1)
- Aktivní hover: -5px lift (větší pop)
- Active: scale(1.01) — push feedback
- Cubic-bezier (.2,.8,.2,1) smooth

### 📦 Build & sync
- Bumped: config.php 3.0.41→3.0.42, admin.js, sw.js, HTML asset URLs

---

## [3.0.41] — 2026-05-26

### 📱 Mobile UX upgrade — 6 nových interakcí
_User: "Pull-to-refresh, Swipe-to-action, Quick actions sheet, Offline indicator, Camera barcode, Vibration feedback — přidej"_

**1. 🔄 Pull-to-refresh** (`appekInitPullToRefresh()`)
- Touch handlers na `#content`, jen pokud `scrollTop=0`
- Threshold 80px → trigger refresh aktuální stránky
- Visual: rotating 🔄 indicator s opacity feedback per progress
- Haptic `tick` na threshold + `medium` při triggeru

**2. 👆 Swipe-to-action** (`window.appekAddSwipeActions(el, opts)`)
- Touch handlers s axis lock (horizontal vs vertical)
- Underlay layer s left/right action labels
- Threshold 80px → execute akci (smazat / vytvořit DL / atd.)
- Smooth cubic-bezier transition zpět
- Haptic `tick` na threshold + `medium` na execute

**3. 📋 Long-press FAB → Quick actions sheet** (`appekShowQuickSheet()`)
- 480ms hold detection na FAB
- Per-stránka 3-5 quick actions (dashboard má 4, vyrobky má 4 vč. scan)
- Bottom sheet s backdrop blur, slide-up animace
- iOS-style drag handle nahoře
- Skryté na desktop (jen mobilní UX)

**4. 📡 Offline indicator** (`appekUpdateOnlineStatus()`)
- Listener na `online`/`offline` events
- Top banner: animated pulse dot + "📡 Offline" hláška
- Auto-fade out když je znovu online
- iOS safe-area podpora
- Haptic `warning` na offline, `success` na recovery
- Fancy **`admin/offline.html`** fallback page (precached v SW):
  - Floating 📡 ikona s animací
  - Auto-reload jakmile online
  - Dark mode podpora
  - Glass-card design

**5. 📷 Camera barcode scan FAB** (Vyrobky stránka)
- Long-press FAB → "📷 Skenovat čárkód" v sub-actions
- Použije existující `appekScanner` (Web BarcodeDetector API)
- Formáty: ean_13, ean_8, upc_a/e, code_128, code_39
- Logika: nalezeno 1 → otevři detail · nalezeno víc → filter · 0 → nabídka nového výrobku

**6. 📳 Haptic feedback** (`window.haptic(type)`)
- Patterns: `light`(10ms), `medium`(20), `heavy`(40), `success`(40-30-40), `warning`(60-40-60), `error`(80-50-80-50-80), `tick`(3-10-3)
- Respekt `prefers-reduced-motion: reduce`
- iOS Safari fallback (nemá vibrate API → silent skip)
- Hooked všude: FAB taps, swipe thresholds, online/offline transitions

### 📦 Build & sync
- Bumped: config.php 3.0.40→3.0.41, admin.js, sw.js (+ offline.html precache), HTML asset URLs

---

## [3.0.40] — 2026-05-26

### 🎨 Moderní 2026 floor plan tiles (žádné "80ové ovaly")
_User: "hezčí ty stoly, ne takový jak z 80let oválky, máme rok 2026 přeci, tak moderní look"_

**Premium tile design v `renderTableTile()`:**
- **Soft 3-stop gradients** místo flat 2-color (140° angle pro moderní look)
- **Inset shadow** (carved-into-floor feel) + outer glow per kategorie
- **Surface highlight overlay** přes `::before` pseudo-element — radial-gradient simulující reálný tabletop reflex (mix-blend overlay)
- **Subtle inner ring** přes `::after` — naznačuje rim/edge stolu
- **Premium typography** — letter-spacing -0.02em, font-weight 800, text-shadow pro depth
- **Glass-pill seat count** badge — `backdrop-filter: blur(2px)` + 40% alpha bg
- **Smooth cubic-bezier** transitions (.2,.8,.2,1) pro hover lift
- **Saturate boost** na hover (+8%) pro "popnutí" karty

**Kategorizace per typ stolu** (detekce z názvu):
- 🍺 **Bar** (Bar/Tap/Pult) → oranžový gradient
- 🛋️ **Lounge** (VIP/Salon/Tatami/Pergola) → fialový
- 🌳 **Garden** (zahrada/strom/piknik) → zelený
- 🔥 **Grill** (Grill/Pec/Teppanyaki) → červený
- 🎵 **Stage** (Parket/DJ/Pódium/Fire pit) → modrý
- 🍽️ **Family** (Rodinný/Komunitní/Společný) → amber-žlutý
- Standard → cream s hnědým akcentem

**Dekorativní prvky (mist=0):**
- Detekce `isDecor` → samostatný render bez interakce
- Velký emoji (55% size), drop-shadow, pointer-events:none
- Žádný chrome (border, label) — čistá vizuální dekorace

### 🌳 Letní zahrada v2 — vyplněný layout
- **Canvas 1100×700 → 1200×720** (více prostoru)
- **Středová DOMINANTA: 🌳 strom 220×230px** místo malé tečky
- Květinová dekorace (🌹 🌷) kolem stromu jako accent
- **Symetrické rozmístění:**
  - Levá zóna: 4 slunečníky (2×2)
  - Střed: velký strom + květiny (vizuální focus)
  - Pravá zóna: 4 round + 4 square stolky (3×2 grid)
  - Spodek: 4 rodinné stoly v řadě + 1 piknik komunitní přes celou šíři
- **Žádné mrtvé prostory** — celá plocha využitá
- **Více míst:** 8 (bar) + 16 (slunečníky) + 24 (round pravá) + 24 (square pravá) + 30 (rodinné) + 18 (piknik) = 120 v zahradě + 60 v pergole = **180 míst**
- Pergola rozšířena: 8 přidaných square stolků (P5-P8)

### 📦 Build & sync
- Bumped: config.php 3.0.39→3.0.40, admin.js, sw.js, HTML versioned URLs

---

## [3.0.39] — 2026-05-26

### 📱 POCKET-READY — APPEK jako profi mobilní app
_User feedback: "musí to být super vychytávka do kapsy"_

**📲 Bottom navigation enabled na mobilu (≤768px):**
- 5 tlačítek (Přehled / Objednávky / Výroba / Dodací listy / Nastavení)
- Frosted-glass backdrop (`backdrop-filter: blur(20px)` + 96% alpha)
- iOS safe-area podpora (`env(safe-area-inset-bottom)` pro home indicator pruh)
- Aktivní item zvýrazněn primary color + drop-shadow
- **Notification badge** — počet nových objednávek se aktualizuje každých 60s s animací pulse
- Auto-padding `<body>` aby fixed nav nepřekrýval content

**➕ Context-aware FAB (Floating Action Button):**
- Per-stránka primary akce (Dashboard → 🛒 Nová obj · Výroba → 🥖 Vyrobit · DL → ➕ Nový DL · atd.)
- Sledování přes `state.current` v `navigate()`
- Pill-shape s emoji + label (vyšší affordance než kruh)
- iOS safe-area margin

**📍 Tap-to-navigate:**
- Adresy v recent rows na dashboardu nyní clickable → `openInMaps()`
- Univerzální deeplink: `maps://` (iOS) → `geo:` (Android) → fallback `google.com/maps` (web)
- Vizuální indikace 🗺️ emoji + primary barva

**📲 PWA install banner:**
- Po 30s na mobilu se zobrazí "📲 Nainstaluj APPEK na plochu"
- Dismiss persisted do localStorage (žádné nátlakové repeaty)
- Trigger `beforeinstallprompt` event (Android + Chrome)
- Skryté pokud už nainstalováno (`display-mode: standalone`)

**🎨 Visual polish:**
- Pulsing badge animace 1.4s ease-in-out
- Slide-up animace banneru 0.35s ease-out
- Pull-to-refresh indikátor (CSS připravený, JS handler v další verzi)

### 📦 Build & sync
- Bumped: config.php 3.0.38→3.0.39, admin.js, sw.js, HTML versioned URLs

---

## [3.0.38] — 2026-05-26

### 🍔 Plnohodnotná LIVE integrace pro Wolt / Bolt Food / Dáme jídlo / Foodora
Po zpětné vazbě uživatele _"musí to být, tak to dodělej"_ — kompletně přepracované od stubu na full backend.

**Nová library: `api/_delivery_aggregators.php`** (~700 řádků):
- HTTP klient přes curl (žádné Composer dependencies)
- Třídy `Wolt_Client`, `BoltFood_Client`, `DameJidlo_Client`, `Foodora_Client` — společný interface
- Per-service: `test()` · `pushStatus()` · `syncMenu()` · `acknowledgeOrder()` · `parseInboundOrder()` · `verifySignature()`
- HMAC-SHA256 signature verification pro každý webhook
- Mapping stavů: naše 6 stavů ↔ jejich 5-7 stavů per service
- Auto-acknowledge Wolt (musí být do 5 min)

**4 nové webhook endpointy** (public, signature-verified):
- `api/wolt_webhook.php` (`X-Wolt-Signature`)
- `api/bolt_food_webhook.php` (`X-Bolt-Signature`)
- `api/damejidlo_webhook.php` (`X-DameJidlo-Signature`)
- `api/foodora_webhook.php` (`X-DH-Signature`)
- Event handlery: order.created → vytvoří objednávku · order.cancelled → stav zrušena · order.delivered → stav doručena
- `delivery_webhook_log` tabulka pro debug + audit (poslední 30 eventů per service)

**Nové akce v `admin_couriers.php`:**
- `?action=test_integration` — ping API, ověří credentials (Wolt: `GET /venues/{id}/status`, Bolt: `GET /v1/providers/{id}`, Dáme jídlo: `GET /v1/restaurants/{id}`, Foodora: `GET /v1/vendors/{id}`)
- `?action=sync_menu` — push naše restaurační výrobky do jejich katalogu (kategorie + položky + ceny + obrázky)
- `?action=push_status` — manuální push stavu pro konkrétní objednávku
- `?action=webhook_urls` — vrátí URL které user vloží do partner portalu
- `?action=webhook_log&sluzba=X` — poslední příchozí eventy
- **Auto-push stavu** při změně v `delivery_status` — pokud má objednávka externí mapování, automaticky pošle do služby

**Nová tabulka `delivery_external_orders`** — mapování náš id ↔ jejich external_id + raw payload + last status push.

**UI v `admin/admin.js` — kompletně přepracovaný integration modal:**
- Live status badge (🟢 aktivní / ⚪ vypnutá) + Test tlačítko v hlavičce
- Help banner s odkazem na partner portal + API docs per service
- Per-service portal info (Wolt → merchant.wolt.com, Bolt → partners.bolt.eu/food, atd.)
- **Webhook URL display** s "Kopírovat" tlačítkem — user jen vloží do partner portalu
- Pokročilé akce: Sync menu · Webhook log viewer
- Test výsledky inline (zelený OK / červený error s detaily)
- Updated text: ❌ "Plánovaná integrace, manuální evidence" → ✅ "Live integrace aktivní"

### 📱 Mobile UX fixes (user feedback)
- **DL přetékaly na mobilním přehledu** → CSS safety net: `min-width: 0` + `overflow: hidden` + `overflow-wrap: anywhere` na `.recent-card` a buňkách
- **Sidebar logo (písmeno "A" + brand text)** → nyní klikatelné, navigates na dashboard. Hover state + tooltip "🏠 Domů — Přehled"

### 📦 Build & sync
- Bumped: config.php 3.0.37→3.0.38, admin.js, sw.js, index.html versioned assets

---

## [3.0.37] — 2026-05-26

### ⏱️ Doba přípravy — jen jídla & nápoje (žádný chleba)
- **Backend filter** `api/admin_prep_times.php` — výpis výrobků jen z restauračních kategorií (Pizzy, Káva, Nealko, Saláty, Dezerty, Těstoviny, Hlavní jídla, Předkrmy, Polévky, Drinky, Alkohol, Víno, Pivo, Burgery + LIKE patterny `%nápoj%`, `%jídl%`, `%pizza%`, `%káva%`, `%drink%`)
- Fallback pokud kategorie chybí → filtr na `cislo LIKE 'R-%'` (restaurační seed prefix)
- **Důvod:** uživatel reportoval _"doba přípravy veka je divná"_ — nemá smysl mít prep time pro pekařské produkty

### 🏗️ 6 nových restaurant space templates (celkem 10!)
1. 🌳 **Letní zahrada** — Outdoor sezení + pergola, slunečníky, piknik stůl pro 16 (2 zóny)
2. 🍺 **Pivnice Plzeň** — Tap bar (10 míst) + 4 dlouhé komunitní stoly po 12 + 6 menších (zone single)
3. 🎉 **Banketní sál** — Hlavní stůl novomanželů, 6 dlouhých stolů po 10, parket, DJ booth, Champagne bar zóna (2 zóny)
4. 🥩 **Steakhouse Grand** — Premium booths podél stěn, open grill bar, chef tasting, whisky stůl, 3 VIP salonky (2 zóny, tmavé pozadí)
5. 🍔 **Burger Bistro** — Counter + pickup okno + bar pult + booth tables podél stěny + středové stoly
6. 🏙️ **Rooftop Praha** — Cocktail bar (vertikální), 3 sunset sofas, high cocktail tables, fire pit, DJ booth (tmavé pozadí)
7. 🍣 **Sushi & Asia** — U-shaped sushi bar (3 segmenty), 8 barových stoliček, teppanyaki grill tables, Tatami room s VIP suite (2 zóny, tmavé pozadí)

### 🎨 UI polish
- **Banner šablon** v Restaurace → Stoly: 8 quick-pick tlačítek (místo původních 4) + tlačítko "📋 Všechny šablony s náhledem →" otevírající full picker
- **Template picker** modal:
  - Header: počet šablon v titulku (`📋 Šablony layoutu (10)`)
  - SVG mini-preview s barvami **per typ stolu**: bar (oranžová), lounge/VIP (fialová), grill/oheň (červená), parket/DJ (modrá), zahrada/tatami (zelená), default (žlutá)
  - **Podpora tmavého pozadí** (rooftop/steakhouse/sushi) — automaticky invertujeme barvy stolů (tmavší fill, světlejší stroke) pro dobrou čitelnost

### 📦 Build & sync
- Bumped: `config.php` 3.0.36→3.0.37, `admin.js` BUILD_VERSION, `sw.js` CACHE_VERSION
- Updated `index.html` versioned asset URLs (admin + b2b)

---

## [3.0.12] — 2026-05-25

### ✨ Velký redesign POS Stoly modal

**Plně přepsaný table modal — touch-first, responsive, kompaktní**

- **Šířka 1400px max** (předtím 920px) — víc místa pro produkty + cart
- **Výška 92vh** — využívá celou obrazovku
- **Mobil = full screen** (no padding, no border-radius) → wraps whole viewport
- **Dotykové buttony** — min-height 50px, větší padding, výrazný hover/active feedback
- **Animace** — modal fade-in 180ms + slide-up 220ms

**Hlavička modal:**
- Status badge: 🟡 Rozpracovaný / ✅ Zaplaceno / ✕ Storno (auto podle stav účtu)
- Meta info: „otevřen 87 min · Karel"
- ESC klávesa zavře modal
- Rotace ✕ tlačítka na 90° při hover

**Cart sekce:**
- Header counter „📋 Položky účtu (3)"
- Položky jako karty s color-codingem (žlutá = vaří se, zelená = hotovo)
- Status badge vedle jména („✓ hotovo" / „🔥 vaří se")
- Cena 16px tučně zelená
- × tlačítko pro odebrání (animace scale)
- **Summary box** dole: Bez DPH / DPH / **CELKEM (22px)** — vše viditelné

**Menu sekce:**
- Search box 16px font, výrazný focus state s box-shadow
- **Kategorie chips** — gradient pro aktivní, hover border
- **Produkty 160px** s big price (24px tučně oranžová) — touch friendly
- Hover lift -2px + shadow

**Footer akce:**
- 🍳 Tisk bonu · 📤 Tisk účtu · 📲 QR platba (secondary)
- 🔓 Znovu otevřít (jen pokud paid/cancelled, danger style)
- **💾 Uložit a zavřít** (modrý gradient — viditelně odlišený)
- **💰 Zaplatit** (zelený gradient, větší font)

**Responsivní breakpointy:**
- **Desktop (>1100px)** — full 2-col 0.9fr + 1.4fr, 160px produktové karty
- **Tablet (800-1100px)** — užší cart, 140px karty
- **Mobil (<800px)** — full screen, **tabs nahoře** „📋 Účet" | „➕ Přidat" (přepínání cart/menu views), 2 produkty per řádek
- **Phone (<400px)** — kompaktní buttony 12px font

**Status logika:**
- Tlačítka Uložit/Zaplatit zobrazené jen pro `stav=open`
- Tlačítko Znovu otevřít jen pro `paid` nebo `cancelled` (TODO: implementace v adminu)
- Auto-refresh statusu v hlavičce po každém přidání/odebrání položky

---

## [3.0.11] — 2026-05-25

### ✨ Vylepšení

**Back tlačítka — vizuálně odlišená**
- `.btn-back` CSS přebarvená na **modrý nádech** (#EFF6FF → #DBEAFE gradient + modrý border #93C5FD) — vidíš na první pohled že to je "zpět" akce, ne běžné secondary tlačítko
- Hover: tmavší modrá + výraznější posun šipky doleva (animace `translateX(-3px)`)
- Šipka samotná má svojí mini-animaci na hover
- Dark mode varianta s tlumenými modrými barvami
- **Bulk update**: 11 back buttonů v admin.js (mimo onboarding wizard) konvertováno z `btn-secondary` → `btn-back`
- **Diagnostika back button** opraven — vrací do Údržba tabu místo výchozí Firma tabu (3× výskyt opravený)

### 🐛 Opravy

**Faktury — auto-migrace chybějících sloupců**
- Diagnostika hlásila: `faktury.odb_nazev_snapshot` a `faktury.odb_ico_snapshot` chybí
- Nový `ensure_faktury_schema()` v `_schema_lib.php` — auto-vytvoří 6 snapshot sloupců + `rucni` flag
- Zavolán v `api/admin_faktury.php` a `api/admin_faktura_z_dl.php` při každém requestu (idempotent, static cache)
- Po prvním načtení Faktury sekce sloupce přibydou automaticky

---

## [3.0.10] — 2026-05-25

### 🐛 Opravy + ✨ UX polish

**POS Stoly tab (v3.0.8 follow-up)**
- **Ceny 0 Kč** — bug v field name (`v.cena` neexistuje, správně `v.cena_bez_dph` + DPH dopočítat). Teď zobrazuje **správné ceny s DPH**.
- **Cart výpočet** — předtím počítal z `cena_bez_dph` které není v restaurant_pos_polozky. Teď z `jednotkova_cena` (která je s DPH, jak ji POS ukládá).
- **Větší produktové karty** v modal (150px width, 110px height) — touch-friendly pro tablet
- **Velká cena** v kartě (22px bold orange) — vidíš ji první
- **Kategorie chips** nad gridem — filtr per kategorie (Pečivo / Chleby / Nápoje / ...). Bez filtru = "⭐ Vše"
- **Search box** větší (15px) pro snadné psaní

**Zone tabs ve Floor view**
- Tabs nad floor gridem: 🪑 Vše (N) · Sál (10) · Terasa (4) · Bar (4) · Salonek (2)
- Klik = filtr stolů jen pro tu zónu (skryje ostatní)
- Aktivní zóna = sunrise gradient highlight

**Modal akce**
- **🍳 Tisk bonu** — opraven na popup okno (380×640) místo nového tabu, zobrazí toast feedback
- **📤 Tisk účtu** (nahradil "Zaplatit") — vytiskne účet hosta pak nabídne uzavření jako hotovostní platba
- **🗑️ Odebrat položku** — nový × tlačítko vedle každé položky (storno přes item_state endpoint)
- **Stav badge** vedle položky (✓ hotovo / 🔥 vaří se) — info pro číšníka co kuchyně dělá

**Uzavření účtu**
- `posTableCloseUcet()` — volá `admin_pos.php?action=pay` endpoint
- Po platbě modal zmizí, floor refresh ukáže stůl jako volný

---

## [3.0.9] — 2026-05-25

### 🐛 Kritická oprava — subdirectory hosting

**Bug:** Když byla aplikace umístěna v subdirectory (např. `localhost/appek/` při lokálním vývoji nebo na hostingu kde appek není root domény), spousta JS volání selhávala s 404.

**Příčina:** Absolutní cesty `/api/...` místo relativních `../api/...` na 17 místech v admin/pos/b2b šablonách.

**Fix v3.0.9:**
- `admin/pos.php` `CFG.apiBase: '/api/'` → `'../api/'`
- `admin/index.html` 3× `fetch('/api/X')` → `fetch('../api/X')`
- `admin/feature-*.html` (9 souborů) — všechny fetch volání
- `admin/shipping.html` — 2 fetch volání
- `b2b/index.html` — 2 fetch volání

**Production na root doméně** (např. `restaurace.cz/admin/`) fungoval předtím i teď stejně. Bug se projevoval jen na **subdirectory** instalacích (lokální XAMPP, shared hosting bez root).

---

## [3.0.8] — 2026-05-25

### ✨ Nové funkce

**POS — Floor view tab (🪑 Stoly)**
- Nový 4. tab v POS hlavičce: **📦 Produkty | 🪑 Stoly | 📜 Účtenky | 📊 Statistiky**
- Grid karet stolů seskupených podle zón (Sál, Terasa, Bar, Salonek)
- Karta ukáže: název, kapacitu, status (VOLNÝ zelená / OBSAZENO oranžová), sumu účtu + počet položek + minuty od otevření
- Stats banner: Volné / Obsazené / Celkem (počty)
- **Klik na stůl** = otevři účet → modal:
  - Vlevo: aktuální položky účtu + total
  - Vpravo: katalog výrobků (search + grid) — tap = +1 do účtu
  - Footer: 🍳 Tisk bonu / 📲 QR platba / ⚙️ Více v adminu / 💰 Zaplatit
- Floor plan **editor** (přidat bar / salonek / přesunout stoly drag-drop) zůstává v Admin → Restaurace → Stoly → Floor plan tab — POS je jen operační view

---

## [3.0.7] — 2026-05-25

### ✨ Nové funkce

**QR k platbě (pay-at-table)**
- Nová stránka `/pay/?t=<token>` — mobil-first platební landing pro hosty
- V detailu účtenky (POS) tlačítko **"📲 QR platba"** → modal s QR + URL → nalep / vytiskni k účtence
- Host naskenuje → vidí účtenku + 3 možnosti platby:
  - **💳 Stripe** (Apple Pay / Google Pay / kartou online) — pokud nakonfigurován
  - **🔴 GoPay** (kartou / bankovním převodem CZ) — pokud nakonfigurován
  - **💵 Hotovostí číšníkovi** — vždy dostupné, informuje obsluhu
- Backend (`api/pay_qr.php`): create_token, info, status polling, stripe_init, gopay_init, webhook handlers
- Schema: nové sloupce `objednavky.pay_token / pay_status / pay_method / paid_at / pay_extra` (auto-migrace)
- Reuse existující customer_int_stripe_create_checkout / customer_int_gopay_create_payment

**Nápověda — komplexní průvodci**
- Nastavení → ❓ Nápověda → **8 nových sekcí** krok-za-krokem:
  - POS Kasa — jak začít prodávat
  - KDS + Výdej — kuchyňský workflow
  - QR objednávky — host objedná z mobilu
  - QR k platbě — pay-at-table workflow
  - Tiskárny ESC/POS — setup pro kuchyň/bar
  - Multi-screen setup — 3-4 monitory v provozu
  - Update systému — self-update + manuální
  - Zálohy DB — frekvence, off-site, restore

---

## [3.0.6] — 2026-05-25

### ✨ Vylepšení

**Provoz — škálovatelná velikost čísel**
- Default čísla zvětšena 56px → **96px** (skoro 2× větší)
- **6 úrovní velikosti**: XS (56), S (72), M (96), L (130), XL (170), **TV (220px)** — pro velký monitor v provozu
- **A− / A+ buttons** v hlavičce pro live změnu (uloží se do localStorage)
- **Klávesy + / −** pro rychlé škálování bez myši, **0** pro reset
- **URL parametr `?size=tv`** pro deep-link na konkrétní velikost (např. pro fixní setup TV monitoru)
- Label/meta také škálují proporcionálně

### 🐛 Opravy

- **Backward-compat shim `api/admin_katalog.php`** — staré klienty s cached service workerem volaly toto URL → 404. Nyní forwarduje na `admin_pos.php?action=catalog`.

---

## [3.0.5] — 2026-05-25

### ✨ Nové funkce

**Tiskárny — kompletní ESC/POS infrastruktura**
- Admin → Nastavení → **🖨️ Tiskárny** tab
- **CRUD** termo tiskáren: název, typ (kasa/kuchyně/bar/sklad/výdej/generic), IP, port (default 9100), šířka papíru (58/80mm), encoding (cp852/cp1250/utf-8/ascii), aktivní, poznámka
- **Test tisk** — tlačítko 🧪 Test vytiskne testovací bon s diakritikou
- **Mapování kategorie → tiskárna** — výrobky z kategorie "Nápoje" jdou na bar, "Hlavní jídla" na kuchyň atd.
- **Dummy mode** — bez fyzické tiskárny zapsat tisk do `/tmp/appek_printer_dummy/*.txt` (preview přes browser)
- **Statistiky** — počet tisků, datum posledního, last error (auto-zaznamen pokud TCP socket selže)

**POS auto-tisk při finish objednávky**
- Setting "Tisk účtenky po platbě": **Vždy** / **Zeptat se** / **Nikdy**
- Při "Zeptat se" → modal dialog "🖨️ Vytisknout účtenku? Ano/Ne" s velkou částkou (auto-close za 12s)
- Setting "Auto-split bonů": **Auto** / **Manuální** / **Vypnuto**
- Při "Auto" → po `quick_order` se bonu rozesílají paralelně podle kategorie (jídlo → kuchyň, drinky → bar)
- Soft-fail: pokud tisk selže, POS objednávka se přesto uloží (tisk je vedlejší)

**Backend (ESC/POS přes TCP socket :9100)**
- `api/_printer_lib.php` — kompletní ESC/POS builder (init, align, bold, size, feed, cut), encoding přes iconv
- `api/admin_printers.php` — CRUD + test + map + settings + dummy_files endpoints
- `api/admin_pos.php` — nový endpoint `?action=print_receipt` + auto-dispatch v `quick_order`
- Tabulka `restaurant_printers` (auto-create on first hit)
- Column `kategorie_vyrobku.printer_id` (auto-add)

### 🔌 Pro zákazníky

- Standard: **Epson TM-T20III** Ethernet (cca 3500 Kč) nebo **Star TSP100III**
- Podpora **58mm i 80mm** termo papíru
- Bez fyzické tiskárny: dummy mode → testuj logiku, kup tiskárnu když ti vyhovuje

---

## [3.0.4] — 2026-05-25

### ✨ Nové funkce

**Výdej / Pass-through displej** (`admin/vydej.php`)
- Druhý kuchyňský displej pro číšníka u výdejního okna
- Ukazuje **jen účty s aspoň jednou hotovou položkou**
- Hotové = velké, zelené, klikatelné → klik = servírováno (zmizí z výdeje)
- Vařící se / nepřipravené = šedé, neklikatelné (info pro číšníka co ještě dojde)
- **Tisk pickup bonu** — tlačítko per účet vytiskne kitchen ticket: stůl + hotové položky + čas
- "Vše odneseno" tlačítko pro hromadné označení
- Zelený sunrise gradient (vizuálně odlišný od oranžového KDS)
- Zvukový ding-dong na novou hotovou položku
- Wake-lock + auto-refresh 8 s

**Restaurace → Provoz hub — 3 karty pro multi-screen setup**
- 👨‍🍳 KDS (oranžová karta) — pro kuchaře
- 📤 Výdej (zelená karta) — pro číšníka u pass
- 🗺️ Floor plan editor
- 🧾 POS Kasa
- Tip pro 3+ monitor setup (KDS + Výdej + Provoz + POS)

### 🐛 Opravy

- Odkazy mezi displeji: KDS ↔ Výdej ↔ Provoz ↔ Admin v každém footeru

---

## [3.0.3] — 2026-05-25

### ✨ Vylepšení

**KDS (Kuchyňský displej) — nová hlavička**
- Pozadí změněno z tmavé na světlý sunrise gradient (amber → orange) — pozitivnější vibe pro kuchyňský provoz
- **OBROVSKÉ číselné staty** (68px) — čitelné ze 3+ metrů přes celou kuchyň
- Staty přepracovány na widget karty: ikona, count-up animace, hover lift + shadow
- **Klik na widget = filtr** — kliknu na "Vaří se" → vidím jen účty s vařícími se položkami; opakovaný klik filtr zruší
- Pulsing dot indikátor na widgetech kde se něco děje (Účtů > 0, Vaří se > 0)
- Hodiny zvětšeny na 48px + přidáno datum (Po 25. kvě)
- Color-coding čísel: Účtů modrá, Položek fialová, Vaří se oranžová, Hotových zelená
- Responsive: tablet stack pod hlavičku, mobil 2×2 grid s menšími čísly

---

## [3.0.0] — 2026-05-25 — 🎉 OFFICIAL LAUNCH

První veřejná verze APPEK B2B. Kulminace 400+ commitů a 2 měsíců intenzivního vývoje.

### ✨ Nové funkce

**Pokladna (POS)**
- Nový kasový modul s PIN přihlášením prodavačů (4 uživatelé)
- Velké, dotykově optimalizované rozhraní pro tablet i MacBook 13"
- Editovatelné rychlé volby pro volný řádek (korkovné, obal, sleva)
- Sleva a spropitné se ukládají jako samostatné řádky → vidí je admin v detailu objednávky
- Klikatelné účtenky s detailem v modálním okně
- Drafty rozpracovaných košíků (localStorage 20-item) + auto-resume po přepnutí prodavače
- 6 platebních metod (hotově, karta, PayPal, gift card, voucher, mobile)
- 4 typy objednávky (sebou, na místě, vyzvednutí, rozvoz)
- Idempotency key proti duplikátům při retry
- Kompaktní launcher Kasy v adminu s dnešními prodeji + TOP položkami

**Sklady (multi-warehouse)**
- Správa více skladů — CRUD, přepínání, oddělené přehledy
- Pivot „suroviny + výrobky per sklad" pro jasný stav zásob
- Pohyby: příjem, výdej, inventura, korekce, přesun (FOR UPDATE proti race)
- Per-sklad exporty: PDF / CSV / XML / JSON
- Porovnání skladů + UI pro přesuny

**Platby online**
- Stripe Checkout s validací klíčů + test connection v nastavení
- GoPay + PayPal jako alternativní brány
- Centrální správa platebních metod (POS + B2B + checkout)
- Webhook + session_verify polling fallback

**Dashboard a přehledy**
- Layout: Tržby 75 % + Dnešek 25 %, druhý řádek 50/50
- Sparkliny jako watermark pod hlavní částkou (Stripe/Linear styl)
- Widget „Akce vyžadující pozornost" s klikatelnými alerty
- Sjednocené period taby napříč aplikací

**Výroba a kalkulace**
- Hub „Výroba" s 5 sub-taby (Vyrobeno / Výrobní list / Suroviny / Kalkulace / HACCP)
- Step-by-step UI klonek výpočtu
- Composite ingredient recurse (kompozitní suroviny — Diasauer, směsi)
- Pre-check + lock + dual-write do sklad_pohyby_v2

**Štítky a katalog**
- 7 prvků pro nutriční hodnoty na štítku (Energie kJ/kcal, Tuky, Sacharidy, Bílkoviny, Sůl)
- EU 1169/2011 tabulka per 100 g

**Onboarding a demo**
- Funkční „WOW" demo seed (10 výrobků, 35 surovin, 10 receptů, 5 kalkulací, 5 odběratelů, POS users, stoly)
- Při onboardingu volba **ano / ne** pro demo data
- Reset demo skryt v Údržbě (proti omylem kliku)
- Merge režim — doplní jen chybějící data
- 14 dnů historie objednávek pro funkční grafy

**Navigace a UX**
- Globální Cmd+K vyhledávání
- Mobilní hamburger menu + bottom-nav
- Cesty zpět na 100 % sub-stránek
- Mobile period tabs zkrácené (Týden místo Tento týden)

**Notifikace**
- Centrální panel v Dashboard stylu
- Seskupené notifikace, klikatelné s navigací
- Červená tečka u upravených dokladů

### 🔒 Bezpečnost

- **`app_errors` DB tabulka** — každý error logger zapíše s request_id + plný exception trace + user kontext
- **Admin Logs viewer** v Diagnostice — search podle reqId
- **POS frontend error capture** (předtím chyběl)
- **request_id v error toastech** — propojení frontend ↔ backend
- **Healthcheck endpoint** (`/api/healthcheck.php`) — 7 checks (DB / schema / write / disk / file / error_rate / PHP runtime)
- **Proaktivní monitoring** — cron-callable, auto-notif při alert/spike
- **Dashboard top banner** sekundy po incidentu
- **41 endpoints** migrováno z `json_error($e->getMessage())` na `json_error_safe()` (zabraňuje information disclosure)
- **Stored XSS fix** v POS preset names (JSON.stringify v single-quoted attribute)
- **Privilege escalation fixes** — `fix_demo_users` guard, `quick_order_detail` puvod check
- **Input bounds** v POS quick_order (DoS guards: ≤200 položek, tip 0-100k, sleva 0-100%, mn 0-9999)
- **Sklad lock** proti race conditions (sorted ID lock order, FOR UPDATE)
- **Idempotency** (POS finish + retry guard)
- **Auto-rollback** při selhání self-update
- **session_secure_start** sjednoceno (APPEKSID cookie)
- 2FA TOTP, CSRF tokens, license-key gating, Argon2id

### 🚀 Výkon

- **Lazy i18n loading** — CS users (~80 % bázi) předtím tahali 4 MB JS zbytečně. Teď CS = 0 MB extra
- **SQL range scan** místo DATE() wrap — 5-50× rychlejší při >10k řádků
- **Promise.all** parallel fetch v POS launcher (úspora ~150-300 ms)
- Lazy DDL — chybějící sloupce se doplní jen v momentě, kdy jsou potřeba
- Cache-bust verze CSS i JS automaticky v release skriptu
- Defenzivní fallbacky proti broken API responses

### 🌍 Lokalizace

- 5 jazyků: 🇨🇿 čeština · 🇸🇰 slovenčina · 🇬🇧 angličtina · 🇪🇸 španělština · 🇩🇪 němčina
- 18 000+ frází napříč moduly
- B2B portál: kompletní pokrytí EN/ES/SK/DE
- Frontpage fallback chain (SK → CS, DE → EN)
- Lazy load i18n bundles + runtime dynamic load při přepnutí

### 🐛 Důležité opravy

- Sidebar se rozbil při jediné položce (flex chování)
- POS cart row v MacBook 13 nebyl viditelný (BIG MODE CSS bez media query)
- „Akce vyžadující pozornost" + bell nyní klikatelné
- POS účtenky klikatelné s detail modálním oknem
- Demo seed 500 fix (DDL implicit commit v MySQL)
- Aktualizace selhávala s `admin_session_required` (oprava session konfliktu)
- Mobile period taby — nikdy nezalamovat, plynulý shrink
- Defenzivní fallbacky pro `data.faktury`, `obdobi_stats`, `data.pocet`
- Otevírací doba odstraněna z landing page (matoucí pro SaaS)

### 🛠️ Pro vývojáře / správce

- **GitHub Actions CI/CD** — `release.yml` build + deploy s retry logic
- **`scripts/release.sh`** pro jednopříkazové vydání
- **Self-update modul** s SHA256 verifikací + auto-rollback při selhání
- **`download.php`** v rootu — license-gated download pro customers
- **Install.php self-delete** button + auto-chmod 0600 na config.local.php
- **`scripts/sync-local.sh`** pro multi-device vývoj
- **`SETUP-IMAC.md`** pro nový dev stroj
- BSD sed kompatibilita ve `release.sh` (macOS friendly)

---

## Pre-release historie

**v2.9.x** — interní vývoj a beta testování (327+ commitů, neveřejné)
**v2.0.x – v2.6.x** — alpha / private beta

Detailní git historie: [GitHub Releases](https://github.com/Chossekilla/appek/releases)
