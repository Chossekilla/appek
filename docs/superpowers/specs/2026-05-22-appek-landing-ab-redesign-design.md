# APPEK appek.cz — dvě varianty landing page (Apple / Klasik) s A/B přepínačem

**Datum:** 2026-05-22
**Repozitář:** `appek.cz` (git, produkt v2.9.x)
**Stav:** návrh schválen — připraveno k tvorbě implementačního plánu

## 1. Cíl a kontext

appek.cz je veřejná marketingová (sales) stránka produktu APPEK B2B — jeden dlouhý
landing page se 14 sekcemi (`index.html`, ~5000 řádků, pouze na MASTER serveru).

Cílem je možnost A/B testovat dva výrazně odlišné designy téže stránky a operativně
mezi nimi přepínat z vendor panelu.

**Hypotéza k otestování:** U tradičního B2B publika (pekárny, cukrárny, gastro —
často starší majitelé) může „old school", věcný a důvěryhodně působící web konvertovat
lépe než moderní, vyhlazený „startupový" design. Místo hádání postavíme obě varianty
a necháme rozhodnout reálný provoz.

Tento dokument definuje **architekturu a designový směr**. Pixelové provedení se
dolaďuje během implementace přes screenshoty.

## 2. Rozsah

**V rozsahu:**
- Dvě nové varianty landing page: `index-apple.html` (moderní, Apple) a
  `index-classic.html` (Klasik, old school).
- PHP router, který na adrese `/` servíruje aktuálně zvolenou variantu.
- Nová stránka ve vendor panelu (`vendor/landing.php`) pro ruční přepnutí aktivní varianty.
- Náhledový režim (`?variant=`) pro kontrolu neaktivní varianty bez přepnutí naživo.

**Mimo rozsah (vědomě):**
- Žádné automatické rozdělení návštěvníků (split). Přepínání je ruční — všichni vidí
  jednu aktivní variantu (zvolený model: sekvenční A/B test).
- Žádné měření konverzí ani dashboard ve vendoru. Vyhodnocení si dělá operátor
  z vlastní analytiky / počtu objednávek za období.
- Zákaznická část (admin/`pirate`, `b2b`, `demo`, instalátor) se nemění.
- Vendor panel se vizuálně nemění — pouze přibude stránka `landing.php`.
- Marketingové texty a ceník zůstávají; mění se vizuál a struktura, ne nabídka.

## 3. Architektura přepínání

### 3.1 Vstupní bod
- Dnes `/` obsluhuje statický `index.html`, který Apache servíruje přímo (PHP se nespustí).
- Současný `index.html` se přejmenuje na `index-legacy.html` → přestane být
  auto-servírovaným `DirectoryIndex` souborem, takže `/` nově spustí `index.php`.
  (Případná úprava `DirectoryIndex` v `.htaccess`.)
- V MASTER větvi `index.php` router přečte uloženou volbu varianty a obsah příslušného
  souboru pošle přímo na výstup (`readfile`). URL zůstává čisté `appek.cz/`, bez přesměrování.
- Zákaznická (CUSTOMER) větev `index.php` se nemění.

### 3.2 Uložení volby
- Aktivní varianta se ukládá do malého runtime souboru `api/landing-variant.txt`
  (obsah: `apple` nebo `classic`).
- **Soubor, ne DB** — servírování homepage tak nepotřebuje databázové připojení.
- Soubor je gitignorovaný a vyloučený z build-zip (jako `api/config.local.php`) — je to
  provozní nastavení živého serveru a přežije nasazení aktualizace.
- Když soubor chybí, výchozí varianta = `apple`.

### 3.3 Náhledový režim
- Router respektuje `?variant=apple|classic` — zobrazí danou variantu bez změny
  uloženého nastavení.
- Náhledové zobrazení dostane `X-Robots-Tag: noindex`, aby Google neindexoval `?variant=` URL.

### 3.4 Soubory
| Soubor | Role |
|---|---|
| `index.php` | rozšířený router (MASTER větev) |
| `index-apple.html` | varianta A — Apple |
| `index-classic.html` | varianta B — Klasik |
| `index-legacy.html` | zmražená kopie dnešního `index.html`; nouzový manuální rollback (noindex, není volbou přepínače) |
| `vendor/landing.php` | přepínač ve vendoru |
| `api/landing-variant.txt` | uložená volba (runtime, gitignored) |

## 4. Varianta A — „Apple" (moderní)

Výrazný redesign. Zachovává hnědo-zlatou značku (`#BA7517`) v čistším provedení.

**Designový jazyk:**
- Velkorysý bílý prostor, obsah „dýchá"; jedna sekce = jedna myšlenka.
- Velká, sebevědomá typografie (system font / SF Pro), výrazný kontrast vah a velikostí.
- Střídání světlých a tmavých celoplošných sekcí; tmavé sekce ~`#1d1d1f` pro dramatický
  důraz (hero, showcase).
- Velké, čisté mockupy aplikace místo drobných náhledů.
- Uměřené animace při scrollu (fade-up, jemný parallax) — prémiové, ne rušivé.
- Zlatá pouze jako akcent: tlačítka, klíčová slova v nadpisech, ikony. Pill tlačítka,
  měkké stíny, minimum rámečků, frosted-glass navigace.
- Pocit: prémiový, moderní, „vyladěný produkt".

## 5. Varianta B — „Klasik" (old school / důvěra)

Záměrně tradiční, věcný design cílený na důvěryhodnost.

**Designový jazyk:**
- Hutnější rozvržení, méně prázdného prostoru — obsah napřed.
- Jasná vizuální struktura: ohraničené sekce, viditelné rámečky a oddělené boxy,
  definované barevné pruhy.
- Robustnější, menší nadpisy; konvenční velikosti.
- Minimum animací — stabilní, okamžitě načtený, solidní dojem.
- Zlatá v tradičnějším podání: plný barevný pruh hlavičky, klasičtější (méně „pill") tlačítka.

**Prvky důvěry (nahoře a v celé stránce):**
- Stáří firmy (rok založení), počet instalací a zákazníků.
- Loga / jmenné reference zákazníků (jméno, firma, obor).
- Záruka vrácení peněz, „česká firma, česká podpora".
- Telefon viditelně v hlavičce — „zavoláte živému člověku".
- Detailnější textové popisy funkcí, srovnávací tabulka, prominentní FAQ.

## 6. Obsahová parita variant

Obě varianty sdílejí:
- Stejných 14 sekcí a marketingové texty.
- Stejný i18n mechanismus sales stránky (atributy `data-i18n`, `setSalesLang`), 5 jazyků
  (CZ/SK/EN/DE/ES).
- Stejnou konfiguraci e-shopu / ceníku (JS).
- Stejné sdílené assety (`assets/`, obrázky, og-image).

Varianty se liší pouze vizuálem, rozvržením a — u Klasiku — přidanými sekcemi důvěry.
Nové texty Klasiku přinášejí nové `data-i18n` klíče → musí projít i18n pipeline
(`scripts/`) a být přeloženy do všech 5 jazyků.

Protože test je sekvenční (varianty neběží současně), drobná divergence je přijatelná;
operátor by ale měl držet nabídku a ceny v obou souborech sjednocené.

## 7. Přepínač ve vendoru — `vendor/landing.php`

- Nová stránka v duchu existujících vendor stránek (`sales-cms.php`, `pages-editor.php`):
  autentizace a layout přes existující `_layout.php` / `_lib.php`.
- Obsah:
  - Zobrazení aktuálně aktivní varianty.
  - Volba (radio): „Apple (moderní)" / „Klasik (důvěryhodná)".
  - Tlačítko Uložit → zapíše `api/landing-variant.txt`.
  - Čas poslední změny.
  - Náhledové odkazy: `/?variant=apple`, `/?variant=classic`.
- Odkaz na stránku se přidá do hlavní navigace vendor panelu.

## 8. Build a nasazení

- Vše se týká jen MASTER serveru (appek.cz sales). Zákaznický build se nemění.
- `build-zip.sh`: nové soubory (`index-apple.html`, `index-classic.html`,
  `index-legacy.html`, `vendor/landing.php`, upravený `index.php`) patří do MASTER zipu.
  `api/landing-variant.txt` se z buildu vylučuje (runtime nastavení).
- Kontrola CSS závorek (`scripts/css-braces.py`) musí pokrýt inline `<style>` v obou
  nových variantách — jinak může build propustit rozbité CSS.
- Verzování assetů v `build-zip.sh` musí bumpnout obě HTML varianty.
- Po každé hotové fázi proběhne `./build-zip.sh X.Y.Z`.

## 9. SEO

- Varianty servírované na `/` mají `<link rel="canonical" href="https://appek.cz/">`.
- Přímé soubory variant i `?variant=` náhledy jsou `noindex` (viz 3.3) — kvůli duplicitě obsahu.

## 10. Fáze implementace

1. **Infrastruktura přepínače** — router v `index.php`, `api/landing-variant.txt`,
   `vendor/landing.php`, náhledový režim, úprava `.htaccess`/`DirectoryIndex`, přejmenování
   dnešního `index.html` na `index-legacy.html`. Otestovatelné s dočasným obsahem obou
   variant (apple = dnešní obsah, classic = placeholder).
2. **Varianta Apple** — výrazný redesign `index-apple.html` (vychází z dnešního obsahu).
3. **Varianta Klasik** — `index-classic.html` (old-school redesign), nové sekce důvěry
   + jejich i18n do 5 jazyků.

Každá fáze je samostatně testovatelná a uzavřená buildem.

## 11. Rizika a poznámky

- **Souběžné editace:** repozitář upravují i další automatizované smyčky a linter
  přepisuje soubory. Přejmenování `index.html` a úpravy `index.php` ověřit těsně před
  zápisem; při kolizi raději nahlásit než přepsat.
- **i18n práce navíc:** nové texty Klasiku vyžadují překlad do 5 jazyků přes pipeline
  ve `scripts/`.
- **Výkon:** `/` nově projde PHP + `readfile` místo přímého statického servírování —
  režie zanedbatelná.
- **Údržba:** dvě HTML varianty znamenají dvojí úpravy obsahu; po skončení A/B testu
  doporučeno ponechat vítěze a druhou variantu archivovat.
