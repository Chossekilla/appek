# Můj Foodtruck jako druhý prodejní kanál pod vendorem appek — návrh

Datum: 2026-11-04 · Stav: návrh ke schválení

## Proč

Můj Foodtruck (fork appek v3.0.634, repo `Chossekilla/mujfoodtruck.cz`, klon `~/projects/mujfoodtruck.cz`) je levnější edice pro foodtrucky. Dnes nemá žádné licence ani platby — `APP_EDITION='foodtruck'` licenci natvrdo obchází. Majitel ho chce **prodávat stejně jako appek** (koupě → klíč e-mailem → instalace, demo na vyzkoušení, nezaplaceno = aplikace neaktivní s hláškou), ale **spravovat platby a licence obou produktů na jednom místě** (vendor.appek.cz).

## Cíle

1. Pro zákazníka je Můj Foodtruck **kompletně samostatný produkt a značka**: web, pokladna, e-maily, zákaznický portál, demo, instalátor — nikde se neobjeví „appek“ ani appek.cz (výjimka níže).
2. Majitel spravuje **licence, objednávky, platby (a kupony)** obou produktů ve **vendor.appek.cz**, s přepínačem **Appek / Můj Foodtruck** nahoře.
3. Co jde, se **převezme z hotového kódu appek** (generátor klíčů, GoPay klient, mailer, pronájem + hard-lock, portál my.appek.cz, readonly demo).

## Ne-cíle

- Žádné balíčky u foodtrucku (jedna cena, vše v edici zapnuté: core + restaurace + sezona jako dnes).
- Žádná trvalá („napořád“) licence u foodtrucku — jen pronájem (viz rozhodnutí).
- Neslučujeme kódové báze; foodtruck zůstává samostatné repo.
- Žádný zkušební režim zdarma v instalaci — vyzkoušení = demo.
- **Hostování u majitele za příplatek** (majitel ho chce nabízet) — v této fázi se NESTAVÍ. Návrh jen nesmí bránit tomu, aby šlo později přidat (např. příplatková položka v pokladně + ruční nasazení majitelem). Samostatný projekt.

## Anti-cíle (selhání, i kdyby to technicky fungovalo)

- Zákazník foodtrucku uvidí appek (v e-mailu, URL, portálu, instalátoru, chybové hlášce).
- Jakákoli změna rozbije nebo změní dnešní prodej/licence appek (existující klíče, heartbeat, GoPay callback, portál my.appek.cz).
- Klíč appek projde ve foodtrucku nebo obráceně.

## Rozhodnuto majitelem

- Přepínač Appek / Můj Foodtruck ve vendoru; jedna správa plateb a licencí pod jednou doménou (vendor.appek.cz).
- Pro zákazníka vše samostatné.
- Koupě = rovnou klíč (jako appek); vyzkoušení = demo jako u appek.
- Nezaplaceno → aplikace **neaktivní s hláškou**.
- **Platební brána GoPay zůstává jedna (stávající GoID appek)** — na platební stránce GoPay může být vidět název obchodu/firma; to majiteli nevadí. Je to jediná povolená výjimka z cíle 1.
- **Self-hosted jako appek:** zákazník si aplikaci stáhne a nainstaluje na vlastní hosting. Majitel NEMÁ kapacitu hostovat ani spravovat instalace zákazníků (hostovaná varianta zamítnuta).

## Vzhled a texty aplikace (majitel)

Admin aplikace foodtrucku (odvozený z appek admin) má **vlastní vzhled, vlastní texty a horní menu jako e-shop** — tak jak bylo zadáno při vzniku forku. Částečně hotovo (`admin/ft/ft.css`, `admin/ft/ft.js`: světlý/tmavý vzhled, horní menu, stránky Přehled / Kde stojíme / Věrnost / Akce / Statistiky). Součástí tohoto projektu je to **dotáhnout**:

- Projít všechny obrazovky, které zákazník vidí (admin, kasa `/pos/`, KDS, instalátor, online objednávka, B2B portál pokud zůstává, chybové a zamykací hlášky, e-maily z aplikace) a **přepsat viditelné texty** na foodtruck terminologii a značku. Dnes je v aplikaci ~750 výskytů „appek/Krabicovka“ (většina jsou identifikátory v kódu — ty se NEpřejmenovávají; jen to, co uživatel vidí, vč. `<title>`, manifestu, ikon, nápovědy, onboardingu).
- Sjednotit vzhled všech obrazovek na foodtruck styl (nejen admin).
- Kontrola hotovo: grep viditelných řetězců + screenshoty klíčových obrazovek ve světlém i tmavém vzhledu.

## Distribuce a aktualizace (vyplývá ze self-hosted)

- **Dva celky v jednom repu foodtrucku:** (a) *web produktu + prodej* (landing `index.html`, pokladna, platební callback, stav platby, heartbeat, ověření klíče, stahování, update feed, portál) — běží jen na mujfoodtruck.cz a jako jediný zná vendor DB; (b) *aplikace* pro zákazníka. Zákaznický balíček (ZIP) **nesmí obsahovat** celek (a) ani žádnou cestu/údaje k vendor DB. Vzor: appek `build-zip.sh` exclude + `.landing-only` marker na apexu.
- **Build:** skript ve foodtruck repu, který sestaví zákaznický ZIP (verze v `APP_VERSION`), bez webu produktu, bez `.git`, bez `config.local.php`.
- **Stahování:** e-mail s klíčem odkazuje na `mujfoodtruck.cz/download…?key=MFT-…` (vzor `appek.cz/download.php`, včetně ochrany proti brute-force); stáhne jen platný, aktivní MFT klíč.
- **Aktualizace:** zapnout zpět, ale z vlastního feedu na mujfoodtruck.cz, podepsané **vlastním RSA klíčovým párem foodtrucku** (vzor appek RSA-2048 podpis, fail-closed). Soukromý klíč jen na serveru mujfoodtruck.cz mimo git, vkládá majitel. `admin_version_check.php`, `updates_apply.php`, `admin_notifications.php` přesměrovat z appek.cz na foodtruck feed. Aktualizace dostane jen instalace s aktivní licencí.
- **Vydání verze:** na builderovi (ručně skript + nahrání na server, nebo CI tag jako appek) — hlavně ať je to jeden příkaz.

## Rozhodnuto mnou (ke kontrole)

1. **Rozlišení produktu v datech:** sloupec `product VARCHAR(16) NOT NULL DEFAULT 'appek'` v `vendor_licenses`, `vendor_shop_orders` (a `vendor_coupons`). Idempotentní runtime migrace ve vendor `_lib.php` (vzor `vendor_ensure_pirate_columns`). Default `appek` → existující data beze změny.
2. **Tvar klíče:** `MFT-XXXX-XXXX-XXXX-CCCC`, checksum s **vlastním saltem** (jiná konstanta než `LICENSE_SALT` appek). Foodtruck klient přijme jen `MFT-`, appek klient dál jen `APPEK-`. Generátor nově vedle stávajícího ve `vendor/_license.php` (nová funkce, žádná změna existujících — pozor na lekci „dvě kopie knihovny se stejnými globálními funkcemi v jednom requestu = fatal“).
3. **Cenový model:** vždy pronájem (`rental=1`), období 1 / 3 / 6 / 12 měsíců. Cena = vendor nastavení `ft_price_month_kc` (+ volitelně `ft_price_year_kc` pro 12 měs. se slevou). Dnešní zástupná cena 499 Kč/měs. na webu → číst z vendor nastavení.
4. **Kde běží zákaznické endpointy:** na **mujfoodtruck.cz** (vlastní nákup, GoPay callback, stav platby, heartbeat, ověření klíče — názvy na builderovi). Obě domény jsou na stejném Hostinger účtu u880385154 → mujfoodtruck.cz se připojí **přímo k vendor DB** (`localhost`, údaje v jeho `config.local.php`, mimo git). Žádný request na appek.cz, nic se neprozradí.
5. **Sdílený kód:** zkopírovat potřebné knihovny (GoPay klient, mailer, generátor/validátor MFT klíčů, logika obnovy) do repa foodtrucku do vlastní složky a **neincludovat** cesty z appek.cz. Duplicita je vědomá — produkty se mají vyvíjet nezávisle.
6. **GoPay:** stejný GoID a klíče (čtené z `vendor_settings` ve sdílené DB). `notification_url` a `return_url` míří na mujfoodtruck.cz. Číslo objednávky foodtrucku s vlastním prefixem (např. `MFT-ORD-…`), aby šlo rozlišit i v GoPay přehledu. Ověřit, že stávající `api/gopay_callback.php` appek takovou platbu nezpracuje (neexistuje v jeho `vendor_shop_orders` s product=appek → ignoruje).
7. **Klientská aplikace foodtruck:** odstranit natvrdo vypnutý licenční bypass (větve `foodtruck` v `license_is_dev_host()` a `license_status()`); heartbeat míří na mujfoodtruck.cz (dnes `api/license_heartbeat.php:47` → vendor.appek.cz); po vypršení + 14 dní odkladu **hard-lock celé aplikace** (stávající `full_lock`/402 mechanika) s hláškou ve foodtruck stylu a odkazem na prodloužení ve foodtruck portálu. Data zůstávají, po zaplacení se samo odemkne. Instalátor: krok Licence zpět, ověřuje `MFT-` klíč proti mujfoodtruck.cz, foodtruck texty. Zachovat výjimku pro lokální vývoj (localhost).
8. **Zákaznický portál:** kopie `my/index.php` (magic-link, zaplaceno do / zbývá / prodloužit) na subdoméně foodtrucku (např. `muj.mujfoodtruck.cz`), filtrovaný na `product='foodtruck'`, foodtruck vzhled.
9. **E-maily:** vlastní šablony foodtruck (klíč, potvrzení platby, prodloužení, připomínka 3 dny před koncem, magic-link). Odesílatel @mujfoodtruck.cz (SMTP schránku zřídí majitel; do té doby funkční fallback). Admin notifikace majiteli dál na stejnou adresu, s označením produktu.
10. **Připomínky:** stávající `vendor/cron_reminders.php` rozšířit, aby u `product='foodtruck'` posílal foodtruck šablonu s foodtruck odkazy (nebo samostatný cron na mujfoodtruck.cz — na builderovi). Appek připomínky beze změny.
11. **Vendor UI:** přepínač produktu nahoře (pamatuje si volbu), filtruje seznamy licencí, objednávek, kuponů a KPI na dashboardu. Ruční vydání licence ve vendoru: při přepnutí na foodtruck generuje `MFT-` klíč, skryje balíčky, `rental` vždy 1. Nastavení: sekce „Můj Foodtruck“ (ceny, odesílatel e-mailů, URL portálu). Pirát/revoke/unlock funguje pro oba.
12. **Demo:** samostatná instalace `demo.mujfoodtruck.cz` s ukázkovým menu a readonly účtem (vzor `demo@appek.cz`); demo se nezamyká.
13. **Kupony:** slevové kupony mají `product`; foodtruck pokladna přijme jen foodtruck kupony.
14. **Pokladna foodtrucku:** platba GoPay; platba převodem/fakturou zatím ne (lze doplnit). Totéž pro prodloužení v portálu.
15. **Ceny prodloužení:** 1/3/6 měs. = `ft_price_month_kc` × N; 12 měs. = `ft_price_year_kc` (když je vyplněná, jinak × 12).
16. **Žádné ostré instalace foodtrucku zatím nejsou** (dočasná doména ještě nenainstalovaná) → bypass licence se ruší bez přechodu.
17. **Heartbeat = plná parita s appek `vendor/heartbeat.php`** (fingerprint binding, 3× mismatch → lock, revoke/unlock, `expires_at`, grace, `rental`), nad sdílenými tabulkami filtrovanými `product='foodtruck'`.
18. **Faktury:** PAID objednávka foodtrucku vystaví fakturu ve **vlastní číselné řadě** (např. `MFT-2026-…`) se jménem/údaji dodavatele a vzhledem z foodtruck nastavení ve vendoru — nikde „appek“. Ve vendoru vidět přes přepínač.

## Omezení

- Hostinger/LiteSpeed: PHP→HTTP volání vždy s `User-Agent`; zkontrolovat `.htaccess` foodtrucku, že callback/heartbeat/ověření klíče nejsou blokované.
- `vendor/_lib.php` neběží pod CLI — testy přes standalone PDO.
- Změny vendoru (appek master) se nasazují podle stávajícího postupu appek; **klientské `api/` appek se nemění**.
- Foodtruck se nasazuje přes SSH `git archive | tar` do `~/domains/<doména>/public_html`. Cílová doména mujfoodtruck.cz se zřizuje; do té doby dočasná `mediumseagreen-camel-103046.hostingersite.com` (URL brát z konfigurace, ne natvrdo).
- Nikdy hesla/klíče do gitu ani do chatu; GoPay/SMTP/DB údaje vkládá majitel.

## Na builderovi

Přesné názvy souborů a endpointů, rozvržení vendor UI přepínače, struktura sdílené knihovny ve foodtruck repu, pořadí kroků, testovací harness (sandbox GoPay `*,00`/`*,04`, smoke testy).

## Hotovo znamená

- Vendor: přepínač funguje, appek data vypadají přesně jako dnes, foodtruck licence/objednávky jsou vidět odděleně.
- Koupě na mujfoodtruck.cz (GoPay sandbox, pak ostrý 1 Kč test) → PAID → MFT klíč + odkaz ke stažení e-mailem z foodtruck adresy → stažený ZIP (bez webu produktu a bez vendor DB kódu) nainstalovaný na čistý hosting s klíčem projde → aplikace jede.
- Vydaná nová verze → nainstalovaná aplikace ji uvidí a podepsanou aktualizaci nasadí; podvržený balíček odmítne.
- Vypršení (simulované) → po odkladu aplikace zamčená s foodtruck hláškou → prodloužení v portálu → odemčeno.
- APPEK klíč ve foodtrucku neprojde, MFT klíč v appeku neprojde.
- Na žádné zákaznické stránce/e-mailu foodtrucku není „appek“ (grep + screenshoty), kromě GoPay platební stránky.
- Dnešní prodej appek ověřen beze změny (smoke + test tlačítko GoPay).
