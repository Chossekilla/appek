#!/usr/bin/env python3
"""
🌍 i18n EXTRA Generator — vygeneruje SK + DE překlady pro všech 15 800+ CS frází
z admin/i18n_auto.js a uloží do admin/i18n_extra.js.

Strategie:
  • SK: rule-based (Czech ≈ Slovak phonetic mapping + word-level dictionary)
  • DE: hand-curated dictionary + fallback na EN (z původního I18N_LOOKUP)

Spuštění:
  python3 scripts/gen_i18n_extra.py
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC  = ROOT / 'admin' / 'i18n_auto.js'
DST  = ROOT / 'admin' / 'i18n_extra.js'

# Import extra data dicts (separated for cleaner organization)
sys.path.insert(0, str(Path(__file__).resolve().parent))
try:
    from i18n_dicts_extra import SK_EXTRA, DE_EXTRA
    HAS_EXTRA = True
except ImportError:
    SK_EXTRA = {}
    DE_EXTRA = {}
    HAS_EXTRA = False

# ─────────────────────────────────────────────────────────────────────
# 🇸🇰 SLOVAK RULES — phonetic + morphological CS→SK transformations
# ─────────────────────────────────────────────────────────────────────

# Word-level SK dictionary (overrides rules for irregular words)
SK_DICT = {
    # Core actions
    'Uložit': 'Uložiť', 'Uložit a zavřít': 'Uložiť a zavrieť', 'Smazat': 'Vymazať',
    'Zrušit': 'Zrušiť', 'Upravit': 'Upraviť', 'Otevřít': 'Otvoriť', 'Zavřít': 'Zavrieť',
    'Hledat': 'Hľadať', 'Filtrovat': 'Filtrovať', 'Tisknout': 'Tlačiť', 'Stáhnout': 'Stiahnuť',
    'Pokračovat': 'Pokračovať', 'Zpět': 'Späť', 'Dále': 'Ďalej', 'Hotovo': 'Hotovo',
    'Odeslat': 'Odoslať', 'Kopírovat': 'Kopírovať', 'Klonovat': 'Klonovať',
    'Duplikovat': 'Duplikovať', 'Vybrat': 'Vybrať', 'Generovat': 'Generovať',
    'Vygenerovat': 'Vygenerovať', 'Ověřit': 'Overiť', 'Aplikovat': 'Aplikovať',
    'Potvrdit': 'Potvrdiť', 'Schválit': 'Schváliť', 'Načíst': 'Načítať',
    'Obnovit': 'Obnoviť', 'Resetovat': 'Resetovať', 'Vrátit': 'Vrátiť',
    'Vrátit zpět': 'Vrátiť späť', 'Stornovat': 'Stornovať', 'Tisk': 'Tlač',
    'Tisk vybraných': 'Tlač vybraných', 'Tisknout vše': 'Vytlačiť všetko',
    'Stáhnout PDF': 'Stiahnuť PDF', 'Stáhnout CSV': 'Stiahnuť CSV',
    'Odeslat e-mailem': 'Odoslať e-mailom', 'Vybrat vše': 'Vybrať všetko',
    'Zrušit výběr': 'Zrušiť výber', 'Přidat': 'Pridať', 'Odebrat': 'Odobrať',
    'Odstranit': 'Odstrániť', 'Nahrát': 'Nahrať', 'Nahrávám': 'Nahrávam',
    'Načítání': 'Načítavanie', 'Načítám': 'Načítavam', 'Načítám…': 'Načítavam…',
    'Hledání…': 'Hľadanie…', 'Uloženo': 'Uložené', 'Uloženo!': 'Uložené!',
    'Smazáno': 'Vymazané', 'Smazáno!': 'Vymazané!', 'Vytvořeno': 'Vytvorené',
    'Aktualizováno': 'Aktualizované', 'Změněno': 'Zmenené', 'Přijato': 'Prijaté',
    'Posláno': 'Odoslané', 'Odesláno': 'Odoslané', 'Zkopírováno': 'Skopírované',
    'Zkopírováno!': 'Skopírované!', 'Vyhotoveno': 'Vyhotovené',

    # Fields / Labels
    'Název': 'Názov', 'Číslo': 'Číslo', 'Kód': 'Kód', 'Popis': 'Popis',
    'Poznámka': 'Poznámka', 'Stav': 'Stav', 'Datum': 'Dátum', 'Cena': 'Cena',
    'DPH': 'DPH', 'Sazba DPH': 'Sadzba DPH', 'Množství': 'Množstvo',
    'Jednotka': 'Jednotka', 'Hmotnost': 'Hmotnosť', 'Obsah': 'Obsah',
    'Alergeny': 'Alergény', 'Alergen': 'Alergén', 'Složení': 'Zloženie',
    'IČO': 'IČO', 'DIČ': 'DIČ', 'E-mail': 'E-mail', 'Telefon': 'Telefón',
    'Web': 'Web', 'Ulice': 'Ulica', 'Město': 'Mesto', 'PSČ': 'PSČ',
    'Stát': 'Štát', 'Adresa': 'Adresa', 'Splatnost': 'Splatnosť',
    'Pobočka': 'Pobočka', 'Pobočky': 'Pobočky', 'Heslo': 'Heslo',
    'Jméno': 'Meno', 'Příjmení': 'Priezvisko', 'Role': 'Rola', 'Typ': 'Typ',
    'Kategorie': 'Kategória', 'Verze': 'Verzia', 'Licenční klíč': 'Licenčný kľúč',
    'Celkem': 'Celkom', 'Cena celkem': 'Cena celkom', 'Mezisoučet': 'Medzisúčet',
    'Tržba': 'Tržba', 'Tržby': 'Tržby', 'Tržby celkem': 'Tržby celkom',
    'Zisk': 'Zisk', 'Náklady': 'Náklady', 'Marže': 'Marža', 'Sleva': 'Zľava',
    'Měna': 'Mena', 'Forma úhrady': 'Forma úhrady',

    # Statuses
    'Aktivní': 'Aktívny', 'Neaktivní': 'Neaktívny', 'Skryté': 'Skryté',
    'Skrytý': 'Skrytý', 'Blokován': 'Zablokovaný', 'Nový': 'Nový', 'Nová': 'Nová',
    'Nové': 'Nové', 'Potvrzená': 'Potvrdená', 'Potvrzený': 'Potvrdený',
    'Potvrzeno': 'Potvrdené', 'Ve výrobě': 'Vo výrobe', 'Připravená': 'Pripravená',
    'Připraveno': 'Pripravené', 'Expedovaná': 'Expedovaná', 'Doručená': 'Doručená',
    'Doručeno': 'Doručené', 'Zrušená': 'Zrušená', 'Zrušeno': 'Zrušené',
    'Vyfakturováno': 'Vyfakturované', 'Nevyfakturováno': 'Nevyfakturované',
    'Uhrazeno': 'Uhradené', 'Neuhrazeno': 'Neuhradené', 'Po splatnosti': 'Po splatnosti',
    'Ano': 'Áno', 'Ne': 'Nie', 'Probíhá': 'Prebieha', 'Dokončeno': 'Dokončené',
    'Selhalo': 'Zlyhalo', 'Chyba': 'Chyba', 'Varování': 'Varovanie',
    'Úspěch': 'Úspech', 'Úspěšně': 'Úspešne',

    # Sections / Menu
    'Přehled': 'Prehľad', 'Objednávky': 'Objednávky', 'Objednávka': 'Objednávka',
    'Výrobky': 'Výrobky', 'Výrobek': 'Výrobok', 'Výrobní list': 'Výrobný list',
    'Dodací listy': 'Dodacie listy', 'Dodací list': 'Dodací list',
    'Faktury': 'Faktúry', 'Faktura': 'Faktúra', 'Odběratelé': 'Odberatelia',
    'Odběratel': 'Odberateľ', 'Suroviny': 'Suroviny', 'Surovina': 'Surovina',
    'Sklad': 'Sklad', 'Skladem': 'Skladom', 'HACCP': 'HACCP',
    'Štítky': 'Štítky', 'Štítek': 'Štítok', 'Cenovky': 'Cenovky', 'Cenovka': 'Cenovka',
    'Štítky a cenovky': 'Štítky a cenovky', 'Kategorie výrobků': 'Kategórie výrobkov',
    'Cenové skupiny': 'Cenové skupiny', 'Uživatelé': 'Užívatelia',
    'Uživatel': 'Užívateľ', 'Rozvozy': 'Rozvozy', 'Rozvoz': 'Rozvoz',
    'Opakující se': 'Opakujúce sa', 'PDF nabídka': 'PDF ponuka',
    'Nastavení': 'Nastavenia', 'Firma': 'Firma', 'Nápověda': 'Pomocník',
    'Pomoc': 'Pomoc', 'Podpora': 'Podpora', 'Účet': 'Účet', 'Profil': 'Profil',
    'Odhlásit': 'Odhlásiť', 'Odhlásit se': 'Odhlásiť sa', 'Přihlásit': 'Prihlásiť',
    'Přihlásit se': 'Prihlásiť sa', 'Registrace': 'Registrácia',
    'Reporty': 'Reporty', 'Report': 'Report', 'Statistika': 'Štatistika',
    'Statistiky': 'Štatistiky', 'Aktualizace': 'Aktualizácie',
    'Aktualizovat': 'Aktualizovať', 'Aktualizovat aplikaci': 'Aktualizovať aplikáciu',

    # Time / Period
    'Dnes': 'Dnes', 'Včera': 'Včera', 'Zítra': 'Zajtra', 'Tento týden': 'Tento týždeň',
    'Tento měsíc': 'Tento mesiac', 'Tento rok': 'Tento rok', 'Minulý týden': 'Minulý týždeň',
    'Minulý měsíc': 'Minulý mesiac', 'Minulý rok': 'Minulý rok',
    'Týden': 'Týždeň', 'Měsíc': 'Mesiac', 'Rok': 'Rok', 'Den': 'Deň',
    'Dní': 'Dní', 'Hodin': 'Hodín', 'Hodina': 'Hodina', 'Hodiny': 'Hodiny',
    'Minut': 'Minút', 'Minuta': 'Minúta', 'Minuty': 'Minúty', 'Sekund': 'Sekúnd',
    'Vlastní': 'Vlastné', 'Období': 'Obdobie', 'Období:': 'Obdobie:',

    # Common phrases
    'Žádné': 'Žiadne', 'Žádná': 'Žiadna', 'Žádný': 'Žiadny', 'Žádná data': 'Žiadne dáta',
    'Žádné objednávky': 'Žiadne objednávky', 'Žádné faktury': 'Žiadne faktúry',
    'Zatím žádné': 'Zatiaľ žiadne', 'Zatím žádný': 'Zatiaľ žiadny',
    'Načítám…': 'Načítavam…', 'Zpracovávám…': 'Spracovávam…',
    'Probíhá zpracování…': 'Prebieha spracovanie…',

    # Demo / B2B
    'Demo režim': 'Demo režim', 'Demo': 'Demo', 'Reset demo dat': 'Reset demo dát',
    'Demo data': 'Demo dáta', 'Pirate': 'Pirate', 'Pirátská kopie': 'Pirátska kópia',

    # Days of week
    'Pondělí': 'Pondelok', 'Úterý': 'Utorok', 'Středa': 'Streda',
    'Čtvrtek': 'Štvrtok', 'Pátek': 'Piatok', 'Sobota': 'Sobota', 'Neděle': 'Nedeľa',

    # Months
    'Leden': 'Január', 'Únor': 'Február', 'Březen': 'Marec', 'Duben': 'Apríl',
    'Květen': 'Máj', 'Červen': 'Jún', 'Červenec': 'Júl', 'Srpen': 'August',
    'Září': 'September', 'Říjen': 'Október', 'Listopad': 'November', 'Prosinec': 'December',

    # Numbers
    'Jeden': 'Jeden', 'Dva': 'Dva', 'Tři': 'Tri', 'Čtyři': 'Štyri', 'Pět': 'Päť',
    'Šest': 'Šesť', 'Sedm': 'Sedem', 'Osm': 'Osem', 'Devět': 'Deväť', 'Deset': 'Desať',

    # Empty states / negation
    'Sales report': 'Sales report', 'Žádné DL': 'Žiadne DL', 'Žádné výrobky': 'Žiadne výrobky',
    'Zatím žádné výrobky': 'Zatiaľ žiadne výrobky', 'Zatím žádné objednávky': 'Zatiaľ žiadne objednávky',
    'Zatím žádné faktury': 'Zatiaľ žiadne faktúry', 'Zatím žádní odběratelé': 'Zatiaľ žiadni odberatelia',
    'Žádný odběratel neodpovídá filtru': 'Žiadny odberateľ nezodpovedá filtru',
    'Žádný výrobek neodpovídá filtru': 'Žiadny výrobok nezodpovedá filtru',
    'Žádná objednávka neodpovídá filtru': 'Žiadna objednávka nezodpovedá filtru',
    '⚠️ Po splatnosti': '⚠️ Po splatnosti', 'vše uhrazeno': 'všetko uhradené',
    'Žádné záznamy.': 'Žiadne záznamy.', 'Žádné položky.': 'Žiadne položky.',
    'Žádné dodací listy': 'Žiadne dodacie listy', 'Žádné suroviny odpovídající filtru': 'Žiadne suroviny zodpovedajúce filtru',
    'Žádné uložené výrobní listy': 'Žiadne uložené výrobné listy',
    'Žádný štítek neodpovídá vyhledávání.': 'Žiadny štítok nezodpovedá vyhľadávaniu.',
    'Žádné objednávky na zítra': 'Žiadne objednávky na zajtra',
    'Žádné vyplněné řádky.': 'Žiadne vyplnené riadky.',
    'Žádné platné vCard bloky.': 'Žiadne platné vCard bloky.',
    'Žádné faktury k tisku.': 'Žiadne faktúry na tlač.',
    'Žádné dodací listy k tisku.': 'Žiadne dodacie listy na tlač.',
    'Žádné alergeny v surovinách': 'Žiadne alergény v surovinách',
    'Žádný volní odběratelé.': 'Žiadni voľní odberatelia.',
    'Žádný výrobek': 'Žiadny výrobok',
    'Načítám výrobky…': 'Načítavam výrobky…',
    'Žádný odběratel': 'Žiadny odberateľ',

    # Selection / filtering
    'Vybráno': 'Vybrané', 'Vybrat vše ze zobrazených': 'Vybrať všetko zo zobrazených',
    'Všechny': 'Všetky', 'Všechny stavy': 'Všetky stavy', 'Jen aktivní': 'Iba aktívne',
    'Jen skryté': 'Iba skryté', 'Vše': 'Všetko', 'Řadit': 'Zoradiť', 'Roztřídit': 'Roztriediť',
    'Přečíslovat': 'Prečíslovať',

    # Import / Export
    'Z výrobku': 'Z výrobku', 'Import ceníku': 'Import cenníka',
    'Import vizitek': 'Import vizitiek', 'Import JSON/CSV': 'Import JSON/CSV',
    'Náhled': 'Náhľad', 'Naplnit demo daty': 'Naplniť demo dátami',
    '📊 Import ceníku': '📊 Import cenníka', '🎬 Naplnit demo daty': '🎬 Naplniť demo dátami',
    '📋 Otevřít objednávky': '📋 Otvoriť objednávky',

    # New / Edit / Detail prefixes
    'Nový výrobek': 'Nový výrobok', 'Upravit výrobek': 'Upraviť výrobok',
    'Nový odběratel': 'Nový odberateľ', 'Upravit odběratele': 'Upraviť odberateľa',
    'Nová objednávka': 'Nová objednávka', 'Upravit objednávku': 'Upraviť objednávku',
    'Nový štítek': 'Nový štítok', 'Upravit štítek': 'Upraviť štítok',
    'Detail objednávky': 'Detail objednávky', 'Detail faktury': 'Detail faktúry',
    'Detail dodacího listu': 'Detail dodacieho listu',
    'Vytvořit': 'Vytvoriť', 'Vytvořit novou': 'Vytvoriť novú',
    'Vytvořit nový': 'Vytvoriť nový', 'Vytvořit nové': 'Vytvoriť nové',
    'Vymazat': 'Vymazať', 'Otevřít detail': 'Otvoriť detail',

    # Settings groups
    '🏢 Firma & doklady': '🏢 Firma & doklady', '📧 Notifikace': '📧 Notifikácie',
    '🥖 Výroba': '🥖 Výroba', '👥 Přístupy & ceny': '👥 Prístupy & ceny',
    '🎁 Balíčky': '🎁 Balíčky', '🛠️ Údržba': '🛠️ Údržba',
    '❓ Nápověda & FAQ': '❓ Pomocník & FAQ',
    'Firemní údaje, kontakt, číselné řady, DPH': 'Firemné údaje, kontakt, číselné rady, DPH',
    'E-maily a uzávěrka úprav objednávek': 'E-maily a uzávierka úprav objednávok',
    'Suroviny, kategorie, kalkulace, náklady': 'Suroviny, kategórie, kalkulácie, náklady',
    'Uživatelé a slevové skupiny': 'Užívatelia a zľavové skupiny',
    'Aktivace doplňkových modulů (Cukrárna, Lahůdky, …)': 'Aktivácia doplnkových modulov (Cukráreň, Lahôdky, …)',
    'Bezpečnost, zálohy DB, diagnostika': 'Bezpečnosť, zálohy DB, diagnostika',
    'Jak na to — návody a časté dotazy': 'Ako na to — návody a časté otázky',
    'Jazyk aplikace': 'Jazyk aplikácie', 'Vzhled aplikace': 'Vzhľad aplikácie',

    # HACCP
    'Plán HACCP': 'Plán HACCP', 'Plán systému kritických bodů': 'Plán systému kritických bodov',
    'Sanitační řád': 'Sanitačný poriadok', 'Vstupní instruktáž': 'Vstupná inštruktáž',
    'Postupy CCP': 'Postupy CCP', 'Formuláře': 'Formuláre',
    'Záznamy o školení': 'Záznamy o školení', 'Interní audit': 'Interný audit',
    'Audity': 'Audity', 'Audit': 'Audit', 'Auditor': 'Audítor',
    'Výsledek': 'Výsledok', 'V pořádku': 'V poriadku', 'Nevyhovuje': 'Nevyhovuje',
    'S připomínkami': 'S pripomienkami',

    # Loading / errors
    'Načítám...': 'Načítavam...', 'Chyba serveru': 'Chyba servera',
    'Chyba při načítání': 'Chyba pri načítavaní', 'Změna uložena': 'Zmena uložená',
    'Změny uloženy': 'Zmeny uložené', 'Nebyly provedeny žádné změny': 'Neboli vykonané žiadne zmeny',

    # Notifications
    'Vše přečteno': 'Všetko prečítané', 'Vše označeno jako přečtené': 'Všetko označené ako prečítané',
    'Notifikace': 'Notifikácie', 'Žádné nové notifikace': 'Žiadne nové notifikácie',
    '🎉 Žádné nové notifikace': '🎉 Žiadne nové notifikácie',
    'Aktivní balíčky': 'Aktívne balíčky',

    # Auth
    'Vyhledat': 'Vyhľadať', 'Vítejte,': 'Vitajte,', 'online B2B': 'online B2B',
    'Celá obrazovka': 'Celá obrazovka', 'Administrace': 'Administrácia',
    'Nesprávný email nebo heslo': 'Nesprávny email alebo heslo',
    'Super admin': 'Super admin', 'Prodavač': 'Predavač', 'Výroba': 'Výroba',
    'Expedice': 'Expedícia',

    'Faktury se generují z dodacích listů. Vystav nejdřív DL z objednávky.':
        'Faktúry sa generujú z dodacích listov. Vystav najprv DL z objednávky.',

    # Sidebar
    'Připnuto': 'Pripnuté', 'Připnout menu': 'Pripnúť menu',
    'Skrýt menu': 'Skryť menu', 'Zobrazit menu': 'Zobraziť menu',

    # Activity / sync
    'Activity log': 'Activity log', 'Žádná aktivita.': 'Žiadna aktivita.',
    '📜 Posledních 5 sync operací': '📜 Posledných 5 sync operácií',
    '☁️ Sync s cloudem': '☁️ Sync s cloudom', 'Poslední sync': 'Posledný sync',
    'Čeká na sync': 'Čaká na sync', 'Sync teď': 'Sync teraz',
    'Konfigurace': 'Konfigurácia', 'Sync je vypnutý.': 'Sync je vypnutý.',
    'Částečný sync': 'Čiastočný sync', 'Nikdy nesyncováno': 'Nikdy nesynchronizované',

    # License
    '🔑 Licence & aktualizace': '🔑 Licencie & aktualizácie',
    'Aktualizovat klíč': 'Aktualizovať kľúč',
    'Zkontrolovat aktualizace': 'Skontrolovať aktualizácie',
    'Modulární licence': 'Modulárne licencie',

    # Common particles
    'např.': 'napr.', 'volitelné': 'voliteľné', 'povinné': 'povinné',
    'min.': 'min.', 'max.': 'max.',
    'ks': 'ks', 'kg': 'kg', 'g': 'g', 'l': 'l', 'Kč': 'Kč',
    'Stránka': 'Stránka', 'stránek': 'stránok', 'Více': 'Viac', 'Méně': 'Menej',
    'Klik': 'Klik', '🖨️ Fronta tisku': '🖨️ Fronta tlače',
    'Klikněte na': 'Kliknite na',

    # Cake configurator (cukrárna)
    '🧁 Konfigurátor dortů': '🧁 Konfigurátor tort',
    '📏 Velikost (počet porcí)': '📏 Veľkosť (počet porcií)',
    '🍫 Příchuť': '🍫 Príchuť', '✨ Dekorace': '✨ Dekorácia',
    '📝 Text na dortu & foto předlohy': '📝 Text na torte & foto predlohy',
    '💰 Kalkulace': '💰 Kalkulácia', 'Velikost': 'Veľkosť',
    'v ceně': 'v cene', 'zdarma': 'zdarma',
    'Doba přípravy': 'Doba prípravy', 'Bez dekorace': 'Bez dekorácie',
    '📋 Vytvořit objednávku': '📋 Vytvoriť objednávku',

    # Catering
    '🥗 Catering kalkulátor': '🥗 Catering kalkulátor',
    'Zadej počet osob + typ události → kalkulátor doporučí množství a spočítá cenu.':
        'Zadaj počet osôb + typ udalosti → kalkulátor odporučí množstvo a vypočíta cenu.',
    '👥 Akce': '👥 Akcia', 'Počet osob': 'Počet osôb',
    'Typ události': 'Typ udalosti',
    '🍽️ Jídlo (zaškrtni co chceš)': '🍽️ Jedlo (zaškrtni čo chceš)',
    '🥤 Nápoje': '🥤 Nápoje', 'Pro': 'Pre', 'osob': 'osôb',
    'osoba': 'osoba', '/ osoba': '/ osoba', '/ porci': '/ porciu',
    'Nic nevybráno.': 'Nič nevybrané.',

    # Seasonal
    '🍰 Sezónní katalog': '🍰 Sezónny katalóg',
    '📅 Sezóny': '📅 Sezóny',
    '🥖 Přiřaď výrobky k sezónám': '🥖 Priraď výrobky k sezónam',
    '🟢 PRÁVĚ AKTIVNÍ': '🟢 PRÁVE AKTÍVNE',
    '⏸️ mimo sezónu': '⏸️ mimo sezóny',
    '🐰 Velikonoce': '🐰 Veľká noc',
    '🌷 Den matek': '🌷 Deň matiek',
    '💝 Sv. Valentýn': '💝 Sv. Valentín',
    '🎃 Halloween': '🎃 Halloween',
    '🎄 Vánoce': '🎄 Vianoce', '🎅 Mikuláš': '🎅 Mikuláš',

    # Company contact
    '📞 Kontaktní údaje': '📞 Kontaktné údaje',
    'Volitelné — zobrazí se v patičce dokladů.': 'Voliteľné — zobrazí sa v päte dokladov.',
    'E-mail firmy': 'E-mail firmy', 'Patička dokladů': 'Päta dokladov',
    '🎨 Branding (vlastní barva + logo)': '🎨 Branding (vlastná farba + logo)',
    '🎨 Primární barva': '🎨 Primárna farba',
    '🖼️ URL loga': '🖼️ URL loga',
    '📺 Náhled': '📺 Náhľad',
    '🔄 Vyzkoušet barvu hned': '🔄 Vyskúšať farbu hneď',
    'Ukázkové tlačítko': 'Ukážkové tlačidlo',

    # Webhooks
    '🔄 Webhooks': '🔄 Webhooks', '(out-going HTTP)': '(out-going HTTP)',
    '+ Nový webhook': '+ Nový webhook',
    '📡 Žádné webhooks. Klikni „+ Nový webhook".': '📡 Žiadne webhooks. Klikni „+ Nový webhook".',
    'Events': 'Events', 'Test fire': 'Test fire',
    'Webhook log': 'Webhook log', '📜 Webhook log': '📜 Webhook log',
    '🚀 Test event odeslán — zkontroluj log': '🚀 Test event odoslaný — skontroluj log',
    'Smazat webhook?': 'Vymazať webhook?',
    'Smaže i log historie. Akce je nevratná.': 'Vymaže aj log histórie. Akcia je nevratná.',
    'Webhook smazán': 'Webhook vymazaný',
    'Webhook uložen': 'Webhook uložený',
    'volání': 'volania', 'selhání': 'zlyhania', 'Naposled:': 'Naposledy:',
    '(volitelné) náhodný string pro ověření': '(voliteľné) náhodný string na overenie',

    # 2FA
    '🔐 Dvoufaktorové ověření': '🔐 Dvojfaktorové overenie',
    'Dvoufaktorové ověření (2FA)': 'Dvojfaktorové overenie (2FA)',
    '🔐 2FA kód (6 číslic)': '🔐 2FA kód (6 číslic)',
    'Z aplikace Google Authenticator / Authy / 1Password / …': 'Z aplikácie Google Authenticator / Authy / 1Password / …',
    'Zapnout 2FA': 'Zapnúť 2FA', 'Vypnout 2FA': 'Vypnúť 2FA',
    'Ověřovací kód': 'Overovací kód',
    'Zadej 6místný kód z autentikační aplikace.': 'Zadaj 6miestny kód z autentikačnej aplikácie.',
    'Neplatný kód. Zkus znovu (kód se mění každých 30s).': 'Neplatný kód. Skús znova (kód sa mení každých 30s).',

    # Keyboard shortcuts
    '⌨️ Klávesové zkratky': '⌨️ Klávesové skratky',
    'Global': 'Global', 'Quick navigation': 'Quick navigation',
    'Quick actions': 'Quick actions', 'V seznamech': 'V zoznamoch',
    'Otevřít vyhledávání (Command Palette)': 'Otvoriť vyhľadávanie (Command Palette)',
    'Tato nápověda': 'Tento pomocník',
    'Zavřít modal / panel': 'Zavrieť modal / panel',
    'Fullscreen toggle': 'Fullscreen toggle',
    'přidán do tisku': 'pridaný do tlače',
    'Otevírám': 'Otváram', 'dokumentů…': 'dokumentov…',

    # License status
    'Expirují do 30 dní': 'Expirujú do 30 dní',
    'Expirované': 'Expirované', 'Revoked': 'Zrušené (revoked)',
    'Celkem klíčů': 'Celkom kľúčov', '📜 Activity log': '📜 Activity log',
    'Posledních 50 akcí v aplikaci — login pokusy, sync operace, audit změn.':
        'Posledných 50 akcií v aplikácii — login pokusy, sync operácie, audit zmien.',

    # Import wizard
    'Mapping sloupců': 'Mapovanie stĺpcov',
    'Auto-match výsledky': 'Auto-match výsledky', 'Hotovo!': 'Hotovo!',
    'Soubor': 'Súbor', 'DB pole': 'DB pole', 'Sloupec v souboru': 'Stĺpec v súbore',
    'Podle čeho párovat na existující záznamy?': 'Podľa čoho párovať na existujúce záznamy?',
    'Cíl': 'Cieľ', 'Akce': 'Akcia', 'Z importu': 'Z importu',
    'Ostatní data': 'Ostatné dáta', 'Shoda': 'Zhoda', 'Nejisté': 'Neisté',
    '📝 Update': '📝 Update', '➕ Vytvořit nový': '➕ Vytvoriť nový',
    '⏭️ Přeskočit': '⏭️ Preskočiť',

    # Demo seed
    '🎬 Naplnit ukázkovými daty': '🎬 Naplniť ukážkovými dátami',
    'Aplikace přidá': 'Aplikácia pridá', 'kategorií': 'kategórií',
    'výrobků': 'výrobkov', 'odběratelů': 'odberateľov', 'surovin': 'surovín',
    'Existující záznamy se zachovají (skipuje duplicity).':
        'Existujúce záznamy sa zachovajú (preskočí duplicity).',
    'Demo data připravena': 'Demo dáta pripravené',
    'Naplnit': 'Naplniť',

    # Form labels
    'Název *': 'Názov *', 'Název firmy *': 'Názov firmy *',

    'Vše →': 'Všetko →',

    # Validation
    'Čekající': 'Čakajúci', 'Deaktivovaný': 'Deaktivovaný',
    'Fakturováno': 'Fakturované', 'Nefakturováno': 'Nefakturované',
    'VÝCHOZÍ': 'VÝCHODISKOVÉ', 'DOPORUČUJEME': 'ODPORÚČAME',
    'Skrytá': 'Skrytá',
    'Vyplňte název': 'Vyplň názov', 'Vyplň název': 'Vyplň názov',
    'Vyplňte období': 'Vyplň obdobie',
    'Vyplňte datum dodání': 'Vyplň dátum dodania',
    'Vyplňte datum vystavení': 'Vyplň dátum vystavenia',
    'Vyplňte oba datumy': 'Vyplň oba dátumy',
    'Vyplňte tělo': 'Vyplň telo',
    'Vyplňte předmět': 'Vyplň predmet',
    'Vyberte odběratele': 'Vyber odberateľa',
    'Vyberte výrobek z nabídky': 'Vyber výrobok z ponuky',
    'Přidejte alespoň jednu položku': 'Pridaj aspoň jednu položku',
    'Přidejte z katalogu níže.': 'Pridaj z katalógu nižšie.',
    'Přidejte z katalogu níže nebo volný řádek.': 'Pridaj z katalógu nižšie alebo voľný riadok.',
    'Tato objednávka nemá žádné položky.': 'Táto objednávka nemá žiadne položky.',
    'Zadej cílový počet kusů.': 'Zadaj cieľový počet kusov.',
    'Zadej cílovou hmotnost těsta.': 'Zadaj cieľovú hmotnosť cesta.',
    'Výrobek nenalezen v ceníku': 'Výrobok nenájdený v cenníku',
    'Receptura nemá platnou hmotnost.': 'Receptúra nemá platnú hmotnosť.',
    'Chyba ukládání:': 'Chyba ukladania:',
    'Nepodařilo se načíst poslední objednávky:': 'Nepodarilo sa načítať posledné objednávky:',

    # POS / Restaurant
    'Stůl': 'Stôl', 'Stoly': 'Stoly', 'Stolová správa': 'Stolová správa',
    'Účty': 'Účty', 'Nový účet': 'Nový účet',
    'Kuchyně': 'Kuchyňa', 'Bar': 'Bar', 'Servis': 'Servis',
    'Číšník': 'Čašník', 'Pokladna': 'Pokladňa',
    'Platba': 'Platba', 'Platby': 'Platby',
    'Hotově': 'Hotovo', 'Kartou': 'Kartou',
    'Účtenka': 'Účtenka', 'Spropitné': 'Sprepitné',
    'Rozdělit účet': 'Rozdeliť účet',
    'Spojit účty': 'Spojiť účty', 'Přesunout': 'Presunúť',

    # Documents / Banking
    'Fakturace': 'Fakturácia', 'Bankovní účet': 'Bankový účet',
    'IBAN': 'IBAN', 'SWIFT': 'SWIFT', 'BIC': 'BIC',
    'Konstantní symbol': 'Konštantný symbol',
    'Variabilní symbol': 'Variabilný symbol',
    'Specifický symbol': 'Špecifický symbol',

    # Toggles / Permissions
    'Zapnout': 'Zapnúť', 'Vypnout': 'Vypnúť', 'Povolit': 'Povoliť',
    'Zakázat': 'Zakázať', 'Zablokovat': 'Zablokovať',
    'Odblokovat': 'Odblokovať', 'Skrýt': 'Skryť', 'Zobrazit': 'Zobraziť',
    'Skrýt vše': 'Skryť všetko', 'Zobrazit vše': 'Zobraziť všetko',

    # Pagination / search
    'Vyhledávání': 'Vyhľadávanie',
    'Filtrovat (název, číslo)…': 'Filtrovať (názov, číslo)…',
    'První': 'Prvá', 'Poslední': 'Posledná',
    'Stránka 1 z': 'Stránka 1 z',
    'Záznamů': 'Záznamov',
    'Zobrazeno': 'Zobrazené', 'z celkem': 'z celkom',

    # Common phrases
    'Vyber': 'Vyber', 'Vyberte': 'Vyber',
    'Vybrat soubor': 'Vybrať súbor',
    'Nahrát soubor': 'Nahrať súbor',
    'Nahrát obrázek': 'Nahrať obrázok',
    'Smazat obrázek': 'Vymazať obrázok',
    'Změnit': 'Zmeniť', 'Změna': 'Zmena', 'Změny': 'Zmeny',
    'Uložit změny': 'Uložiť zmeny',
    'Zahodit změny': 'Zahodiť zmeny',
    'Bez změny': 'Bez zmeny',

    # Various UI bits
    'Refresh': 'Obnoviť', 'Detail': 'Detail',
    'Export': 'Export', 'Import': 'Import',
    'Hodnota': 'Hodnota', 'Hodnoty': 'Hodnoty',
    'Klíč': 'Kľúč', 'Klíče': 'Kľúče',
    'Účinné od': 'Účinné od', 'Účinné do': 'Účinné do',
    'Od': 'Od', 'Do': 'Do', 'Pro': 'Pre',
    'S': 'S', 'Z': 'Z', 'Na': 'Na', 'V': 'V',

    # Statuses extended
    'Dostupný': 'Dostupný', 'Nedostupný': 'Nedostupný',
    'Online': 'Online', 'Offline': 'Offline',
    'Připojeno': 'Pripojené', 'Odpojeno': 'Odpojené',
    'Otevřeno': 'Otvorené', 'Zavřeno': 'Zatvorené',
    'Veřejný': 'Verejný', 'Soukromý': 'Súkromný',
    'Soukromé': 'Súkromné', 'Veřejné': 'Verejné',

    # Logging / errors
    'Chyba načítání': 'Chyba načítania',
    'Chyba ukládání': 'Chyba ukladania',
    'Nepodařilo se uložit': 'Nepodarilo sa uložiť',
    'Nepodařilo se načíst': 'Nepodarilo sa načítať',
    'Nepodařilo se smazat': 'Nepodarilo sa vymazať',
    'Nepodařilo se odeslat': 'Nepodarilo sa odoslať',
    'Zkuste to znovu': 'Skúste to znova',
    'Zkuste to znovu později': 'Skúste to znova neskôr',
    'Operace selhala': 'Operácia zlyhala',
    'Operace úspěšná': 'Operácia úspešná',

    # Calendar
    'Kalendář': 'Kalendár', 'Týden': 'Týždeň', 'Měsíc': 'Mesiac',
    'Pondělí': 'Pondelok', 'Úterý': 'Utorok', 'Středa': 'Streda',
    'Čtvrtek': 'Štvrtok', 'Pátek': 'Piatok', 'Sobota': 'Sobota', 'Neděle': 'Nedeľa',
    'Po': 'Po', 'Út': 'Ut', 'St': 'St', 'Čt': 'Št', 'Pá': 'Pi', 'So': 'So', 'Ne': 'Ne',

    # Products / Stock
    'Skladová zásoba': 'Skladová zásoba',
    'Skladem na': 'Skladom na',
    'Pohyb skladu': 'Pohyb skladu',
    'Příjem': 'Príjem', 'Výdej': 'Výdaj',
    'Naskladnit': 'Naskladniť',
    'Vyskladnit': 'Vyskladniť',
    'Inventura': 'Inventúra',

    # Recipes
    'Receptura': 'Receptúra', 'Receptury': 'Receptúry',
    'Ingredience': 'Ingrediencia', 'Suroviny v receptu': 'Suroviny v recepte',
    'Krok': 'Krok', 'Kroky': 'Kroky', 'Postup': 'Postup',

    # Calendar/Datum specific
    'Datum a čas': 'Dátum a čas',
    'Čas': 'Čas',
    'Od kdy': 'Od kedy',
    'Do kdy': 'Do kedy',
    'Začátek': 'Začiatok',
    'Konec': 'Koniec',
    'Trvání': 'Trvanie',

    # Forms
    'Povinné pole': 'Povinné pole',
    'Volitelné pole': 'Voliteľné pole',
    'Neplatný formát': 'Neplatný formát',
    'Toto pole je povinné': 'Toto pole je povinné',
    'Neplatný e-mail': 'Neplatný e-mail',
    'Heslo musí mít alespoň 8 znaků': 'Heslo musí mať aspoň 8 znakov',
    'Hesla se neshodují': 'Heslá sa nezhodujú',

    # Numerals / units
    'měsíc': 'mesiac', 'měsíce': 'mesiace', 'měsíců': 'mesiacov',
    'den': 'deň', 'dny': 'dni', 'dní': 'dní',
    'hodina': 'hodina', 'hodiny': 'hodiny', 'hodin': 'hodín',
    'minuta': 'minúta', 'minuty': 'minúty', 'minut': 'minút',
    'rok': 'rok', 'roky': 'roky', 'let': 'rokov',
    'kus': 'kus', 'kusy': 'kusy', 'kusů': 'kusov',
    'položka': 'položka', 'položky': 'položky', 'položek': 'položiek',

    # Common verbs / imperatives
    'Pošli': 'Pošli', 'Pošlu': 'Pošlem',
    'Pošlete': 'Pošlite',
    'Načti': 'Načítaj', 'Načtěte': 'Načítajte',
    'Vyplň': 'Vyplň', 'Vyplňte': 'Vyplň',
    'Klikni': 'Klikni', 'Klikněte': 'Kliknite',
    'Stiskni': 'Stlač', 'Stiskněte': 'Stlačte',
    'Pokud': 'Ak', 'Pokud potřebuješ': 'Ak potrebuješ',
    'Najdi': 'Nájdi', 'Najdete': 'Nájdete',

    # Allergens
    'Obsahuje alergeny:': 'Obsahuje alergény:', 'Bez alergenů': 'Bez alergénov',
    'Lepek': 'Lepok', 'Mléko': 'Mlieko', 'Vejce': 'Vajce',
    'Ořechy': 'Orechy', 'Sezam': 'Sezam', 'Sója': 'Sója',
    'Hořčice': 'Horčica', 'Celer': 'Zeler', 'Ryby': 'Ryby',
    'Korýši': 'Kôrovce', 'Měkkýši': 'Mäkkýše', 'Vlčí bob': 'Lupina',
    'Oxid siřičitý': 'Oxid siričitý', 'Burské oříšky': 'Burské orechy',
    'Arašídy': 'Arašidy', 'Mandlové': 'Mandľové',

    # Pricing
    'Sleva (%)': 'Zľava (%)', 'Cenová skupina': 'Cenová skupina',
    'Pevná cena': 'Pevná cena', 'Bez DPH celkem': 'Bez DPH celkom',
    'S DPH celkem': 'S DPH celkom', 'DPH celkem': 'DPH celkom',
    'Splatnost dní': 'Splatnosť dní', 'Splatnost: dní': 'Splatnosť: dní',

    # Reports
    'Měsíční přehled': 'Mesačný prehľad', 'Roční přehled': 'Ročný prehľad',
    'Týdenní přehled': 'Týždenný prehľad', 'Denní přehled': 'Denný prehľad',
    'Top zákazníci': 'Top zákazníci', 'Top produkty': 'Top produkty',
    'Exportovat': 'Exportovať',
    'Označit jako uhrazené': 'Označiť ako uhradené',
    'Označit jako neuhrazené': 'Označiť ako neuhradené',
    'Označeno jako uhrazené': 'Označené ako uhradené',
    'Označeno jako neuhrazené': 'Označené ako neuhradené',
    'Stornovat fakturu': 'Stornovať faktúru',
    'Storno faktura': 'Storno faktúra',

    # Export formats
    'ISDOC export': 'ISDOC export', 'CSV export': 'CSV export',
    'PDF export': 'PDF export', 'XML export': 'XML export',
    'Stáhnout ZIP': 'Stiahnuť ZIP', 'Stáhnout XML': 'Stiahnuť XML',
    'Sloučit do jednoho PDF': 'Zlúčiť do jedného PDF',
    'Tisknout všechny vybrané': 'Vytlačiť všetky vybrané',

    # Search
    'Najdi výrobek…': 'Nájdi výrobok…',
    'Najdi odběratele…': 'Nájdi odberateľa…',
    'Volný řádek': 'Voľný riadok', 'Volný text': 'Voľný text',

    # Order reuse
    'Z předchozí objednávky': 'Z predchádzajúcej objednávky',
    'Znovu objednat': 'Znovu objednať',
    'Duplikovat objednávku': 'Duplikovať objednávku',

    # Holidays
    'Svátek': 'Sviatok', 'Den díkůvzdání': 'Deň vďakyvzdania',
    '1. svátek vánoční': '1. sviatok vianočný',
    '2. svátek vánoční / Štěpán': '2. sviatok vianočný / Štefan',
    'Den boje za svobodu': 'Deň boja za slobodu',
    'Den dětí': 'Deň detí', 'Den otců': 'Deň otcov',
    'Den otevřených dveří': 'Deň otvorených dverí',
    'Den pracovního klidu': 'Deň pracovného pokoja',

    # Reports
    'Vygenerovat report': 'Vygenerovať report',
    'Aktualizovat vše': 'Aktualizovať všetko',

    # Capacity
    '📅 Kapacita pečení': '📅 Kapacita pečenia',
    '🖼️ Galerie inspirací': '🖼️ Galéria inšpirácií',
    '🎂 Konfigurátor dortů': '🎂 Konfigurátor tort',
    '⚙️ Výchozí denní kapacita': '⚙️ Východisková denná kapacita',
    'Maximální počty pro běžný den (lze přepsat per-den níže).':
        'Maximálne počty pre bežný deň (možno prepísať per-deň nižšie).',
    '🎂 Max dortů / den': '🎂 Max tort / deň',
    '🥪 Max chlebíčků / den': '🥪 Max chlebíčkov / deň',
    '🧁 Max zákusků / den': '🧁 Max zákuskov / deň',
    '💾 Uložit defaulty': '💾 Uložiť východiská',
    '📅 Kalendář příštích 30 dní': '📅 Kalendár najbližších 30 dní',
    'Defaultní kapacita uložena': 'Predvolená kapacita uložená',
    'Kapacita uložena': 'Kapacita uložená',
    'Kapacita resetovaná': 'Kapacita resetovaná',
    'Reset kapacity?': 'Reset kapacity?',
    'Vrátí den na výchozí kapacitu.': 'Vráti deň na východiskovú kapacitu.',
    '🚫 Zavřeno': '🚫 Zatvorené',
    '🚫 Zavřeno — nepřijímat objednávky na tento den':
        '🚫 Zatvorené — neprijímať objednávky na tento deň',
    'Poznámka pro tým': 'Poznámka pre tím',
    'volných': 'voľných', 'vlastní': 'vlastné',
    'Obsazenost:': 'Obsadenosť:',
    'z aktuálních objednávek': 'z aktuálnych objednávok',
    '↩️ Reset na default': '↩️ Reset na predvolené',

    # Gallery
    'Zatím žádné fotky': 'Zatiaľ žiadne fotky',
    'Přidej první foto dortu jako inspirace pro zákazníky.':
        'Pridaj prvé foto torty ako inšpiráciu pre zákazníkov.',
    '➕ Přidat foto': '➕ Pridať foto',
    'Foto přidáno': 'Foto pridané', 'Foto smazáno': 'Foto vymazané',
    'Smazat foto?': 'Vymazať foto?',
    'Foto se odebere z galerie.': 'Foto sa odoberie z galérie.',

    # Tables (restaurant)
    '🪑 Stolová správa': '🪑 Stolová správa',
    'Stoly · Rezervace · Mapa restaurace': 'Stoly · Rezervácie · Mapa reštaurácie',
    '+ Nový stůl': '+ Nový stôl', '+ Přidat první stůl': '+ Pridať prvý stôl',
    'Žádné stoly': 'Žiadne stoly',
    'Počet míst': 'Počet miest', 'Sekce': 'Sekcia',
    'Tvar': 'Tvar', 'Čtverec': 'Štvorec',
    'Kulatý': 'Okrúhly', 'Obdélník': 'Obdĺžnik',
    '🟢 volný': '🟢 voľný', 'rez.': 'rez.',
    'Stůl přidán': 'Stôl pridaný',
    'Stůl smazán': 'Stôl vymazaný',
    'Smazat stůl?': 'Vymazať stôl?',
    'Smaže i všechny rezervace tohoto stolu.':
        'Vymaže aj všetky rezervácie tohto stola.',

    # Reservations
    'Rezervace': 'Rezervácia', 'Rezervace stolu': 'Rezervácia stola',
    'Rezervovat': 'Rezervovať', '📅 Rezervovat': '📅 Rezervovať',
    'Rezervace uložena': 'Rezervácia uložená',
    'Čas od': 'Čas od', 'Čas do': 'Čas do',
    'Vyplň jméno.': 'Vyplň meno.',
    '🎉 Velký catering': '🎉 Veľký catering',

    # Additional common
    'Šablona': 'Šablóna', 'Šablony': 'Šablóny',
    'Nová šablona': 'Nová šablóna',
    'Vytvořit šablonu': 'Vytvoriť šablónu',
    'Použít šablonu': 'Použiť šablónu',
    'Šablona uložena': 'Šablóna uložená',
    'Smazat šablonu?': 'Vymazať šablónu?',

    # Backup
    'Záloha': 'Záloha', 'Zálohy': 'Zálohy',
    'Vytvořit zálohu': 'Vytvoriť zálohu',
    'Obnovit ze zálohy': 'Obnoviť zo zálohy',
    'Stáhnout zálohu': 'Stiahnuť zálohu',
    'Záloha vytvořena': 'Záloha vytvorená',
    'Záloha obnovena': 'Záloha obnovená',
    'Smazat zálohu?': 'Vymazať zálohu?',
    'Smazat všechny zálohy?': 'Vymazať všetky zálohy?',
    'Automatické zálohy': 'Automatické zálohy',
    'Frekvence záloh': 'Frekvencia záloh',
    'Denně': 'Denne', 'Týdně': 'Týždenne', 'Měsíčně': 'Mesačne',

    # User management
    'Spravovat uživatele': 'Spravovať užívateľov',
    'Pozvat uživatele': 'Pozvať užívateľa',
    'Pozvánka odeslána': 'Pozvánka odoslaná',
    'Změnit roli': 'Zmeniť rolu',
    'Změnit heslo': 'Zmeniť heslo',
    'Resetovat heslo': 'Resetovať heslo',
    'Nové heslo': 'Nové heslo',
    'Současné heslo': 'Súčasné heslo',
    'Potvrdit heslo': 'Potvrdiť heslo',
    'Heslo změněno': 'Heslo zmenené',
    'Uživatel vytvořen': 'Užívateľ vytvorený',
    'Uživatel smazán': 'Užívateľ vymazaný',
    'Uživatel aktivován': 'Užívateľ aktivovaný',
    'Uživatel deaktivován': 'Užívateľ deaktivovaný',
    'Smazat uživatele?': 'Vymazať užívateľa?',

    # Permissions / Roles
    'Administrátor': 'Administrátor', 'Manažer': 'Manažér',
    'Uživatel': 'Užívateľ', 'Pouze čtení': 'Iba na čítanie',
    'Vlastník': 'Vlastník',

    # Settings sub-items
    'Obecné': 'Všeobecné', 'Vzhled': 'Vzhľad',
    'Bezpečnost': 'Bezpečnosť', 'Soukromí': 'Súkromie',
    'Integrace': 'Integrácie', 'Účty': 'Účty',
    'Pokročilé': 'Pokročilé', 'Experimentální': 'Experimentálne',

    # Buttons - extended
    'Uložit a další': 'Uložiť a ďalšie',
    'Uložit a pokračovat': 'Uložiť a pokračovať',
    'Uložit a nový': 'Uložiť a nový',
    'Uložit jako šablonu': 'Uložiť ako šablónu',
    'Náhled tisku': 'Náhľad tlače',
    'Nastavit': 'Nastaviť',
    'Konfigurovat': 'Konfigurovať',
    'Použít': 'Použiť',
    'Spustit': 'Spustiť', 'Zastavit': 'Zastaviť',
    'Pauza': 'Pauza', 'Pokračování': 'Pokračovanie',
    'Přerušit': 'Prerušiť', 'Restart': 'Reštart',

    # Filter labels
    'Filtr': 'Filter', 'Filtry': 'Filtre',
    'Aktivní filtry': 'Aktívne filtre',
    'Vymazat filtry': 'Vymazať filtre',
    'Resetovat filtry': 'Resetovať filtre',
    'Použít filtry': 'Použiť filtre',
    'Pokročilý filtr': 'Pokročilý filter',

    # Sortovani
    'Seřadit podle': 'Zoradiť podľa',
    'Vzestupně': 'Vzostupne', 'Sestupně': 'Zostupne',
    'Abecedně': 'Abecedne', 'Podle data': 'Podľa dátumu',
    'Podle ceny': 'Podľa ceny', 'Podle množství': 'Podľa množstva',

    # Common message templates
    'Opravdu smazat?': 'Skutočne vymazať?',
    'Tato akce je nevratná.': 'Táto akcia je nevratná.',
    'Operace dokončena.': 'Operácia dokončená.',
    'Operace přerušena.': 'Operácia prerušená.',
    'Nejprve vyber položku.': 'Najprv vyber položku.',
    'Změny nebyly uloženy.': 'Zmeny neboli uložené.',
    'Chcete uložit změny?': 'Chcete uložiť zmeny?',
    'Uložit změny před odchodem?': 'Uložiť zmeny pred odchodom?',
    'Odejít bez uložení': 'Odísť bez uloženia',

    # Order statuses (workflow)
    'Čeká na potvrzení': 'Čaká na potvrdenie',
    'Potvrzená objednávka': 'Potvrdená objednávka',
    'V přípravě': 'V príprave',
    'Připravena k expedici': 'Pripravená na expedíciu',
    'Vyzvednutí': 'Vyzdvihnutie',
    'Závoz': 'Závoz', 'Závozy': 'Závozy',

    # Drobné UI
    'Zobrazit více': 'Zobraziť viac',
    'Zobrazit méně': 'Zobraziť menej',
    'Zobrazit detaily': 'Zobraziť detaily',
    'Skrýt detaily': 'Skryť detaily',
    'Rozbalit': 'Rozbaliť', 'Sbalit': 'Zbaliť',
    'Rozbalit vše': 'Rozbaliť všetko',
    'Sbalit vše': 'Zbaliť všetko',
    'Předchozí': 'Predchádzajúce',
    'Další': 'Ďalšie',
    'Předchozí strana': 'Predchádzajúca strana',
    'Další strana': 'Ďalšia strana',

    # Confirmations
    'Smazáno!': 'Vymazané!',
    'Uloženo!': 'Uložené!',
    'Aktualizováno!': 'Aktualizované!',
    'Vytvořeno!': 'Vytvorené!',
    'Odesláno!': 'Odoslané!',
    'Hotovo!': 'Hotovo!',
    'Úspěšně provedeno': 'Úspešne vykonané',
    'Úspěšně uloženo': 'Úspešne uložené',
    'Úspěšně smazáno': 'Úspešne vymazané',
    'Úspěšně vytvořeno': 'Úspešne vytvorené',
    'Úspěšně odesláno': 'Úspešne odoslané',

    # CMD-K / Search
    'Hledat objednávky, výrobky, odběratele…': 'Hľadať objednávky, výrobky, odberateľov…',
    'Žádné výsledky': 'Žiadne výsledky',
    'Žádné výsledky pro': 'Žiadne výsledky pre',
    'Hledat všude': 'Hľadať všade',
    'Nedávno hledané': 'Nedávno hľadané',
    'Klávesové zkratky': 'Klávesové skratky',
    'Skok na': 'Skok na',

    # Modals
    'Zavřít okno': 'Zavrieť okno',
    'Minimalizovat': 'Minimalizovať',
    'Maximalizovat': 'Maximalizovať',
    'Otevřít v novém okně': 'Otvoriť v novom okne',

    # Quick stats
    'Počet objednávek': 'Počet objednávok',
    'Počet faktur': 'Počet faktúr',
    'Počet dodacích listů': 'Počet dodacích listov',
    'Počet výrobků': 'Počet výrobkov',
    'Počet odběratelů': 'Počet odberateľov',
    'Počet uživatelů': 'Počet užívateľov',
    'Celkový počet': 'Celkový počet',
    'Průměr': 'Priemer',
    'Maximum': 'Maximum', 'Minimum': 'Minimum',
    'Suma': 'Suma', 'Součet': 'Súčet',

    # Status workflow
    'Objednáno': 'Objednané',
    'V přípravě': 'V príprave',
    'Vyrobeno': 'Vyrobené',
    'Zabaleno': 'Zabalené',
    'Vyexpedováno': 'Vyexpedované',
    'Doručeno na adresu': 'Doručené na adresu',
    'Předáno': 'Odovzdané',
    'Vráceno': 'Vrátené',
    'Reklamace': 'Reklamácia',
    'Reklamováno': 'Reklamované',

    # Communication
    'E-mail šablona': 'E-mail šablóna',
    'E-mail šablony': 'E-mail šablóny',
    'Předmět e-mailu': 'Predmet e-mailu',
    'Tělo e-mailu': 'Telo e-mailu',
    'Odeslat e-mail': 'Odoslať e-mail',
    'E-mail odeslán': 'E-mail odoslaný',
    'Komu': 'Komu', 'Od': 'Od', 'Předmět': 'Predmet',
    'Příloha': 'Príloha', 'Přílohy': 'Prílohy',

    # Numbers / quantities
    'První': 'Prvý', 'Druhý': 'Druhý', 'Třetí': 'Tretí',
    'Čtvrtý': 'Štvrtý', 'Pátý': 'Piaty',
    'Poslední': 'Posledný',

    # Diagnostics
    '🩺 Diagnostika': '🩺 Diagnostika',
    'Spustit diagnostiku': 'Spustiť diagnostiku',
    'Výsledky diagnostiky': 'Výsledky diagnostiky',
    'Vše v pořádku': 'Všetko v poriadku',
    'Nalezeny problémy': 'Nájdené problémy',
    'Detaily': 'Detaily',

    # Inventory
    'Inventarizace': 'Inventarizácia',
    'Zahájit inventuru': 'Začať inventúru',
    'Ukončit inventuru': 'Ukončiť inventúru',
    'Stav skladu': 'Stav skladu',
    'Pohyb': 'Pohyb', 'Pohyby': 'Pohyby',
    'Inventarizační rozdíl': 'Inventarizačný rozdiel',

    # Templates / Categories / Recipes
    'Žádné šablony.': 'Žiadne šablóny.',
    'Šablona smazána': 'Šablóna vymazaná',
    'Smaže i všechny kategorie a ingredience.': 'Vymaže aj všetky kategórie a ingrediencie.',
    'Základní cena': 'Základná cena',
    'Základní cena (Kč)': 'Základná cena (€)',
    'Ikona (emoji)': 'Ikona (emoji)',
    'Kategorie uložena': 'Kategória uložená',
    'Kategorie smazána': 'Kategória vymazaná',
    'Smazat kategorii?': 'Vymazať kategóriu?',
    'Smaže i všechny ingredience v ní.': 'Vymaže aj všetky ingrediencie v nej.',
    'Min. výběr': 'Min. výber', 'Max. výběr': 'Max. výber',
    'Povinná kategorie (musí se z ní vybrat)': 'Povinná kategória (musí sa z nej vybrať)',
    'vyber': 'vyber',
    'Bez ingrediencí — přidej výše.': 'Bez ingrediencií — pridaj vyššie.',
    'Ingredience uložena': 'Ingrediencia uložená',
    'Ingredience smazána': 'Ingrediencia vymazaná',
    'Smazat ingredienci?': 'Vymazať ingredienciu?',
    'Příplatek (Kč)': 'Príplatok (€)',
    '⚠ Alergeny (čárkami)': '⚠ Alergény (čiarkami)',
    'Mléko, Vejce, Lepek': 'Mlieko, Vajce, Lepok',
    'Šablona nemá žádné kategorie ingrediencí.': 'Šablóna nemá žiadne kategórie ingrediencií.',
    '+ Přidat kategorii': '+ Pridať kategóriu',
    '+ Přidat první šarži': '+ Pridať prvú šaržu',
    '✏️ Upravit šablonu': '✏️ Upraviť šablónu',
    'Max': 'Max', 'v této kategorii': 'v tejto kategórii',
    'ingr.': 'ingr.',

    # Restaurant / Pizzeria
    '🚚 Catering objednávky s časem dodání': '🚚 Catering objednávky s časom dodania',
    'Sdílí evidenci s balíčkem 🎉 Velký catering': 'Zdieľa evidenciu s balíčkom 🎉 Veľký catering',
    '+ Vytvořit první akci': '+ Vytvoriť prvú akciu',
    'Čas dodání': 'Čas dodania', 'akcí': 'akcií',
    '🍕 Restaurace / Pizzerie': '🍕 Reštaurácie / Pizzerie',
    '🪑 Stoly': '🪑 Stoly',
    '👨‍🍳 Kapacita kuchyně': '👨‍🍳 Kapacita kuchyne',
    '⏱️ Doba přípravy': '⏱️ Doba prípravy',
    '🛵 Rozvoz / Kurýry': '🛵 Rozvoz / Kuriéri',
    '⚡ Aktuální vytížení': '⚡ Aktuálne vyťaženie',
    '🚫 Kuchyně plná': '🚫 Kuchyňa plná',
    'vytížení': 'vyťaženia',
    '📋 Aktivní objednávky': '📋 Aktívne objednávky',
    '🔨 Připravuje se': '🔨 Pripravuje sa',
    '✅ Hotovo k výdeji': '✅ Hotovo na výdaj',

    # Stations
    '🔥 Stanice': '🔥 Stanice',
    '+ Nová stanice': '+ Nová stanica',
    'Nová stanice': 'Nová stanica',
    'Stanice uložena': 'Stanica uložená',
    'Stanice smazána': 'Stanica vymazaná',
    'Smazat stanici?': 'Vymazať stanicu?',
    'Položky ve frontě této stanice ztratí přiřazení.': 'Položky vo fronte tejto stanice stratia priradenie.',
    'paralelně': 'paralelne',
    'Max. paralelně': 'Max. paralelne',
    '📋 Fronta výroby': '📋 Fronta výroby',
    '😴 Klid v kuchyni — žádné položky ve frontě.': '😴 Pokoj v kuchyni — žiadne položky vo fronte.',
    '▶ Čeká': '▶ Čaká', '🔨 Připravuje': '🔨 Pripravuje',
    '✓ Hotovo': '✓ Hotovo', '▶ Začít': '▶ Začať',
    '📤 Vydáno': '📤 Vydané',
    'v queue': 'v queue', 'připravuje se': 'pripravuje sa',
    'Max. paralelních objednávek': 'Max. paralelných objednávok',
    'Max. minut přípravy SLA': 'Max. minút prípravy SLA',
    'Velikost slotu (min)': 'Veľkosť slotu (min)',
    'Otevřeno od': 'Otvorené od', 'Otevřeno do': 'Otvorené do',
    'Auto-blokovat nové objednávky když je plno': 'Auto-blokovať nové objednávky keď je plno',
    '💾 Uložit nastavení': '💾 Uložiť nastavenia',
    's nastavenou dobou': 's nastavenou dobou',
    'průměr': 'priemer',
    'Doba (min)': 'Doba (min)',
    'Stanice kuchyně': 'Stanice kuchyne',
    '— Žádná —': '— Žiadna —',
    'změn': 'zmien',
    '💡 Jak se počítá doba pro objednávku': '💡 Ako sa počíta doba pre objednávku',

    # Couriers
    'aktivních kurýrů': 'aktívnych kuriérov',
    'probíhajících': 'prebiehajúcich',
    'dnes doručeno': 'dnes doručené',
    '+ Nový kurýr': '+ Nový kuriér',
    'Nový kurýr': 'Nový kuriér',
    'Kurýr přidán': 'Kuriér pridaný',
    'Kurýr upraven': 'Kuriér upravený',
    'Kurýr smazán': 'Kuriér vymazaný',
    'Smazat kurýra?': 'Vymazať kuriéra?',
    '🛵 Aktuálně na cestě': '🛵 Aktuálne na ceste',
    '👥 Kurýři / Řidiči': '👥 Kuriéri / Vodiči',
    '📋 Naplánováno': '📋 Naplánované',
    '📦 Vyzvednuto': '📦 Vyzdvihnuté',
    '🛵 Na cestě': '🛵 Na ceste',
    '✅ Doručeno': '✅ Doručené',
    '📦 Vyzvedl': '📦 Vyzdvihol',
    'Plánováno:': 'Plánované:',
    '🔌 Integrace s externími službami': '🔌 Integrácie s externými službami',
    '✓ Aktivní': '✓ Aktívny',
    'Vypnuto': 'Vypnuté',
    'Provize:': 'Provízia:',
    'Store:': 'Store:',
    '📞 Telefon': '📞 Telefón',
    '📧 E-mail': '📧 E-mail',
    '🚗 Vozidlo': '🚗 Vozidlo',
    'SPZ': 'EČV',
    '📍 Obsluhovaná zóna': '📍 Obsluhovaná zóna',
    '💰 Provize (%)': '💰 Provízia (%)',
    '🏠 Vlastní řidič': '🏠 Vlastný vodič',
    '🔌 Externí služba': '🔌 Externá služba',
    'Externí služba': 'Externá služba',
    'Aktivní (dostupný pro nové rozvozy)': 'Aktívny (dostupný pre nové rozvozy)',
    '🔌 Integrace:': '🔌 Integrácia:',
    'Wolt': 'Wolt', 'Bolt Food': 'Bolt Food', 'Dáme jídlo': 'Dáme jedlo',
    'Uber Eats': 'Uber Eats', 'Foodora': 'Foodora',

    # Order details
    'Položky objednávky': 'Položky objednávky',
    'Souhrn objednávky': 'Súhrn objednávky',
    'Detaily objednávky': 'Detaily objednávky',
    'Historie objednávek': 'História objednávok',
    'Historie změn': 'História zmien',

    # Customer
    'Údaje o zákazníkovi': 'Údaje o zákazníkovi',
    'Kontaktní údaje': 'Kontaktné údaje',
    'Fakturační údaje': 'Fakturačné údaje',
    'Dodací adresa': 'Dodacia adresa',
    'Fakturační adresa': 'Fakturačná adresa',
    'Stejná jako fakturační': 'Rovnaká ako fakturačná',

    # Products
    'Detaily výrobku': 'Detaily výrobku',
    'Specifikace výrobku': 'Špecifikácia výrobku',
    'Skladová karta': 'Skladová karta',
    'Cena za kus': 'Cena za kus',
    'Cena za kg': 'Cena za kg',

    # Validation extended
    'Vyplňte všechna povinná pole': 'Vyplň všetky povinné polia',
    'Neplatná hodnota': 'Neplatná hodnota',
    'Hodnota mimo rozsah': 'Hodnota mimo rozsahu',
    'Pole je příliš dlouhé': 'Pole je príliš dlhé',
    'Pole je příliš krátké': 'Pole je príliš krátke',
    'Datum v minulosti': 'Dátum v minulosti',
    'Datum v budoucnosti': 'Dátum v budúcnosti',
    'Neplatné datum': 'Neplatný dátum',

    # Workflow ext
    'připraveno': 'pripravené', 'rozpracováno': 'rozpracované',
    'dokončeno': 'dokončené', 'zrušeno': 'zrušené',
    'odložené': 'odložené', 'naplánováno': 'naplánované',

    # Frequent
    'Detail uživatele': 'Detail užívateľa',
    'Detail zákazníka': 'Detail zákazníka',
    'Detail výrobku': 'Detail výrobku',
    'Detail dodávky': 'Detail dodávky',
    'Nová položka': 'Nová položka',
    'Upravit položku': 'Upraviť položku',
    'Smazat položku': 'Vymazať položku',
    'Smazat položky?': 'Vymazať položky?',
    'Duplikovat položku': 'Duplikovať položku',
    'Vytvořit kopii': 'Vytvoriť kópiu',

    # Quantity verbs
    'přidat': 'pridať', 'odebrat': 'odobrať',
    'zvýšit': 'zvýšiť', 'snížit': 'znížiť',
    'plus': 'plus', 'minus': 'mínus',

    # Common UI
    'Po kliknutí': 'Po kliknutí',
    'Po stisknutí': 'Po stlačení',
    'Po vybrání': 'Po vybraní',
    'Po uložení': 'Po uložení',
    'Po smazání': 'Po vymazaní',

    # Misc
    'Skutečně chcete tuto akci provést?': 'Skutočne chcete túto akciu vykonať?',
    'Akce byla úspěšně dokončena': 'Akcia bola úspešne dokončená',
    'Akce selhala': 'Akcia zlyhala',
    'Akce přerušena': 'Akcia prerušená',

    # POS
    'Otevřít účet': 'Otvoriť účet',
    'Zavřít účet': 'Zatvoriť účet',
    'Vystavit účet': 'Vystaviť účet',
    'Vyúčtovat': 'Vyúčtovať',
    'Placeno': 'Platené',
    'Otevřeno': 'Otvorené',
    'Zaplacené': 'Zaplatené',
    'Nezaplacené': 'Nezaplatené',

    # KDS / Kitchen Display
    'Kuchyňský display': 'Kuchynský display',
    'KDS': 'KDS',
    'Aktivní položky': 'Aktívne položky',
    'Hotové položky': 'Hotové položky',
    'Vrátit položku': 'Vrátiť položku',
    'Zrušit položku': 'Zrušiť položku',
    'Označit jako hotové': 'Označiť ako hotové',
    'Označit jako vydané': 'Označiť ako vydané',
    'Čas přípravy': 'Čas prípravy',

    # Branding ext
    'Logo': 'Logo', 'Nahrát logo': 'Nahrať logo',
    'Smazat logo': 'Vymazať logo',
    'Doporučená velikost': 'Odporúčaná veľkosť',
    'Max. velikost': 'Max. veľkosť',
    'Podporované formáty': 'Podporované formáty',

    # Number formatting
    'Tisíc': 'Tisíc', 'Milion': 'Milión', 'Miliarda': 'Miliarda',
    'jen': 'len', 'pouze': 'iba',
    'kromě': 'okrem',

    # Common short answers
    'Připojeno': 'Pripojené', 'Odpojeno': 'Odpojené',
    'Načteno': 'Načítané', 'Odesláno': 'Odoslané',
    'Přijato': 'Prijaté', 'Spuštěno': 'Spustené',
    'Zastaveno': 'Zastavené',
}

# SK SAFE rules — pouze pro JEDNOSLOVNÉ infinitivní slovesa, ostatní necháváme jako CS
# Důvod: CS a SK jsou tak blízké že 80% slov je beze změny intelligibilní pro Slováky.
# Tady transformujeme pouze ty case které jsou JISTĚ správné a bezpečné.

# Mapování pro slovní fragmenty (slovo = část slovesa které lze bezpečně přeložit)
SK_VERB_PATTERNS = [
    # -ovat (very productive) → -ovať
    (re.compile(r'^(.+)ovat$'), r'\1ovať'),
    (re.compile(r'^(.+)Ovat$'), r'\1Ovať'),
    # -knout → -knúť
    (re.compile(r'^(.+)knout$'), r'\1knúť'),
    # -nout → -nuť
    (re.compile(r'^(.+)nout$'), r'\1nuť'),
    # Common noun forms
    # -ace → -ácia (operace → operácia, administrace → administrácia)
    (re.compile(r'^(.+)ace$'), r'\1ácia'),
    (re.compile(r'^(.+)Ace$'), r'\1Ácia'),
    # -ence → -encia (frekvence → frekvencia, prezence → prezencia)
    (re.compile(r'^(.+)ence$'), r'\1encia'),
    # -ární → -árny (kalendární → kalendárny)
    (re.compile(r'^(.+)ární$'), r'\1árny'),
    # -ický → -ický (no change but verify)
    # -ist (CS) → -ista (SK)? — too risky, skip
]

# Pure character substitutions (safe for vast majority of words)
SK_CHAR_SUBS = [
    (re.compile(r'ř'), 'r'),  # CS ř → SK r (most common)
    (re.compile(r'Ř'), 'R'),
    # NEVER touch ě, ů, í, é — too risky for false positives
]

def apply_sk_rules(text):
    """Bezpečné SK transformace - pouze char subs + verbal patterns na single-word infinitives."""
    # Single word — zkusíme verb patterns
    if ' ' not in text and len(text) > 3:
        for pat, repl in SK_VERB_PATTERNS:
            if pat.match(text):
                return pat.sub(repl, text)
    # Multi-word nebo non-verb: aplikuj jen char subs
    out = text
    for pattern, repl in SK_CHAR_SUBS:
        out = pattern.sub(repl, out)
    return out

def translate_sk(cs_text):
    """CS → SK: dict-first, pak safe rules pro infinitivní slovesa.

    Strategie:
    1. SK_DICT lookup — nejvyšší priorita
    2. Pokud word končí na -ovat / -nout: aplikuj verb pattern
    3. Jinak skip (CS je intelligibilní pro Slováky)
    """
    if cs_text in SK_DICT:
        return SK_DICT[cs_text]
    # Single word verb endings
    if ' ' not in cs_text and len(cs_text) > 3:
        for pat, repl in SK_VERB_PATTERNS:
            if pat.match(cs_text):
                result = pat.sub(repl, cs_text)
                if result != cs_text:
                    return result
    return None


# ─────────────────────────────────────────────────────────────────────
# 🇩🇪 GERMAN DICTIONARY — hand-curated for most common UI terms
# ─────────────────────────────────────────────────────────────────────

DE_DICT = {
    # Actions
    'Uložit': 'Speichern', 'Uložit a zavřít': 'Speichern und schließen',
    'Smazat': 'Löschen', 'Zrušit': 'Abbrechen', 'Upravit': 'Bearbeiten',
    'Otevřít': 'Öffnen', 'Zavřít': 'Schließen', 'Detail': 'Detail',
    'Hledat': 'Suchen', 'Filtrovat': 'Filtern', 'Vyhledávání': 'Suche',
    'Refresh': 'Aktualisieren', 'Obnovit': 'Aktualisieren', 'Načíst': 'Laden',
    'Tisk': 'Drucken', 'Tisknout': 'Drucken', 'Tisknout vše': 'Alles drucken',
    'Stáhnout': 'Herunterladen', 'Stáhnout PDF': 'PDF herunterladen',
    'Stáhnout CSV': 'CSV herunterladen', 'Export': 'Export', 'Import': 'Import',
    'Pokračovat': 'Weiter', 'Zpět': 'Zurück', 'Dále': 'Weiter',
    'Hotovo': 'Fertig', 'Odeslat': 'Senden', 'Odeslat e-mailem': 'Per E-Mail senden',
    'Kopírovat': 'Kopieren', 'Klonovat': 'Klonen', 'Duplikovat': 'Duplizieren',
    'Vybrat': 'Auswählen', 'Vybrat vše': 'Alles auswählen',
    'Zrušit výběr': 'Auswahl aufheben', 'Vrátit zpět': 'Rückgängig',
    'Resetovat': 'Zurücksetzen', 'Generovat': 'Generieren', 'Vygenerovat': 'Generieren',
    'Ověřit': 'Verifizieren', 'Aplikovat': 'Anwenden', 'Potvrdit': 'Bestätigen',
    'Schválit': 'Genehmigen', 'Přidat': 'Hinzufügen', 'Odebrat': 'Entfernen',
    'Odstranit': 'Entfernen', 'Nahrát': 'Hochladen', 'Nahrávám': 'Wird hochgeladen',
    'Načítání': 'Laden', 'Načítám': 'Wird geladen', 'Načítám…': 'Wird geladen…',
    'Hledání…': 'Suche läuft…', 'Uloženo': 'Gespeichert', 'Uloženo!': 'Gespeichert!',
    'Smazáno': 'Gelöscht', 'Smazáno!': 'Gelöscht!', 'Vytvořeno': 'Erstellt',
    'Aktualizováno': 'Aktualisiert', 'Změněno': 'Geändert', 'Přijato': 'Empfangen',
    'Posláno': 'Gesendet', 'Odesláno': 'Gesendet', 'Zkopírováno': 'Kopiert',
    'Zkopírováno!': 'Kopiert!', 'Vyhotoveno': 'Erstellt', 'Tisk vybraných': 'Ausgewählte drucken',
    'Stornovat': 'Stornieren', 'Storno': 'Storno',

    # Fields
    'Název': 'Name', 'Číslo': 'Nummer', 'Číslo / kód': 'Nummer / Code', 'Kód': 'Code',
    'Popis': 'Beschreibung', 'Poznámka': 'Notiz', 'Stav': 'Status', 'Datum': 'Datum',
    'Datum dodání': 'Lieferdatum', 'Datum vystavení': 'Ausstellungsdatum',
    'Datum splatnosti': 'Fälligkeitsdatum', 'Datum objednání': 'Bestelldatum',
    'Cena': 'Preis', 'Cena bez DPH': 'Preis ohne MwSt.', 'Cena s DPH': 'Preis inkl. MwSt.',
    'Cena za jednotku': 'Stückpreis', 'DPH': 'MwSt.', 'Sazba DPH': 'MwSt.-Satz',
    'Množství': 'Menge', 'Jednotka': 'Einheit', 'Hmotnost': 'Gewicht', 'Obsah': 'Inhalt',
    'Alergeny': 'Allergene', 'Alergen': 'Allergen', 'Složení': 'Zusammensetzung',
    'EAN': 'EAN', 'IČO': 'USt-IdNr.', 'DIČ': 'St-Nr.', 'E-mail': 'E-Mail',
    'Email': 'E-Mail', 'Telefon': 'Telefon', 'Web': 'Web', 'Ulice': 'Straße',
    'Město': 'Stadt', 'PSČ': 'PLZ', 'Stát': 'Land', 'Adresa': 'Adresse',
    'Kontaktní osoba': 'Kontaktperson', 'Splatnost': 'Zahlungsziel',
    'Splatnost (dní)': 'Zahlungsziel (Tage)', 'Pobočka': 'Filiale', 'Pobočky': 'Filialen',
    'Heslo': 'Passwort', 'Heslo (min 8)': 'Passwort (min. 8)',
    'Heslo (min 10)': 'Passwort (min. 10)', 'Heslo znovu': 'Passwort wiederholen',
    'Username (login)': 'Benutzername (Login)', 'Jméno': 'Vorname',
    'Příjmení': 'Nachname', 'Role': 'Rolle', 'Typ': 'Typ', 'Kategorie': 'Kategorie',
    'Verze': 'Version', 'Licenční klíč': 'Lizenzschlüssel', 'Cena (Kč)': 'Preis (€)',
    'Cena (Kč/MJ)': 'Preis (€/Einh.)', 'Celkem': 'Gesamt', 'Cena celkem': 'Gesamtpreis',
    'Tržba celkem': 'Umsatz gesamt', 'Mezisoučet': 'Zwischensumme', 'Zisk': 'Gewinn',
    'Náklady': 'Kosten', 'Marže': 'Marge', 'Sleva': 'Rabatt', 'Měna': 'Währung',
    'Forma úhrady': 'Zahlungsart',

    # Statuses
    'Aktivní': 'Aktiv', 'Neaktivní': 'Inaktiv', 'Aktivních': 'Aktive',
    'Skryté': 'Versteckt', 'Skrytý': 'Versteckt', 'Blokován': 'Gesperrt',
    'Nová': 'Neu', 'Nový': 'Neu', 'Nové': 'Neu', 'Potvrzená': 'Bestätigt',
    'Potvrzený': 'Bestätigt', 'Potvrzeno': 'Bestätigt', 'Ve výrobě': 'In Produktion',
    'Připravená': 'Bereit', 'Připraveno': 'Bereit', 'Expedovaná': 'Versendet',
    'Doručená': 'Zugestellt', 'Doručeno': 'Zugestellt', 'Zrušená': 'Storniert',
    'Zrušeno': 'Storniert', 'Vyfakturováno': 'Berechnet', 'Nevyfakturováno': 'Nicht berechnet',
    'Uhrazeno': 'Bezahlt', 'Neuhrazeno': 'Unbezahlt', 'Po splatnosti': 'Überfällig',
    'Ano': 'Ja', 'Ne': 'Nein', 'Probíhá': 'Läuft', 'Dokončeno': 'Abgeschlossen',
    'Selhalo': 'Fehlgeschlagen', 'Chyba': 'Fehler', 'Varování': 'Warnung',
    'Úspěch': 'Erfolg', 'Úspěšně': 'Erfolgreich',

    # Sections / Menu
    'Přehled': 'Übersicht', 'Objednávky': 'Bestellungen', 'Objednávka': 'Bestellung',
    'Výrobky': 'Produkte', 'Výrobek': 'Produkt', 'Výrobní list': 'Produktionsliste',
    'Dodací listy': 'Lieferscheine', 'Dodací list': 'Lieferschein',
    'Faktury': 'Rechnungen', 'Faktura': 'Rechnung', 'Odběratelé': 'Kunden',
    'Odběratel': 'Kunde', 'Suroviny': 'Rohstoffe', 'Surovina': 'Rohstoff',
    'Sklad': 'Lager', 'Skladem': 'Auf Lager', 'HACCP': 'HACCP',
    'Štítky': 'Etiketten', 'Štítek': 'Etikett', 'Cenovky': 'Preisschilder',
    'Cenovka': 'Preisschild', 'Štítky a cenovky': 'Etiketten und Preisschilder',
    'Kategorie výrobků': 'Produktkategorien', 'Cenové skupiny': 'Preisgruppen',
    'Uživatelé': 'Benutzer', 'Uživatel': 'Benutzer', 'Rozvozy': 'Lieferfahrten',
    'Rozvoz': 'Lieferung', 'Opakující se': 'Wiederkehrend',
    'PDF nabídka': 'PDF-Angebot', 'Nastavení': 'Einstellungen',
    'Firma': 'Unternehmen', 'Nápověda': 'Hilfe', 'Pomoc': 'Hilfe',
    'Podpora': 'Support', 'Účet': 'Konto', 'Profil': 'Profil',
    'Odhlásit': 'Abmelden', 'Odhlásit se': 'Abmelden', 'Přihlásit': 'Anmelden',
    'Přihlásit se': 'Anmelden', 'Registrace': 'Registrierung', 'Reporty': 'Berichte',
    'Report': 'Bericht', 'Statistika': 'Statistik', 'Statistiky': 'Statistiken',
    'Aktualizace': 'Aktualisierungen', 'Aktualizovat': 'Aktualisieren',
    'Aktualizovat aplikaci': 'Anwendung aktualisieren',

    # Time
    'Dnes': 'Heute', 'Včera': 'Gestern', 'Zítra': 'Morgen',
    'Tento týden': 'Diese Woche', 'Tento měsíc': 'Diesen Monat',
    'Tento rok': 'Dieses Jahr', 'Minulý týden': 'Letzte Woche',
    'Minulý měsíc': 'Letzter Monat', 'Minulý rok': 'Letztes Jahr',
    'Týden': 'Woche', 'Měsíc': 'Monat', 'Rok': 'Jahr', 'Den': 'Tag',
    'Dní': 'Tage', 'Hodin': 'Stunden', 'Hodina': 'Stunde', 'Hodiny': 'Stunden',
    'Minut': 'Minuten', 'Minuta': 'Minute', 'Minuty': 'Minuten', 'Sekund': 'Sekunden',
    'Vlastní': 'Benutzerdefiniert', 'Období': 'Zeitraum', 'Období:': 'Zeitraum:',

    # Empty states
    'Žádné': 'Keine', 'Žádná': 'Keine', 'Žádný': 'Kein', 'Žádná data': 'Keine Daten',
    'Žádné objednávky': 'Keine Bestellungen', 'Žádné faktury': 'Keine Rechnungen',
    'Zatím žádné': 'Noch keine', 'Zatím žádný': 'Noch kein',
    'Načítám…': 'Wird geladen…', 'Zpracovávám…': 'Wird verarbeitet…',
    'Probíhá zpracování…': 'Verarbeitung läuft…',

    # Days
    'Pondělí': 'Montag', 'Úterý': 'Dienstag', 'Středa': 'Mittwoch',
    'Čtvrtek': 'Donnerstag', 'Pátek': 'Freitag', 'Sobota': 'Samstag', 'Neděle': 'Sonntag',
    # Months
    'Leden': 'Januar', 'Únor': 'Februar', 'Březen': 'März', 'Duben': 'April',
    'Květen': 'Mai', 'Červen': 'Juni', 'Červenec': 'Juli', 'Srpen': 'August',
    'Září': 'September', 'Říjen': 'Oktober', 'Listopad': 'November', 'Prosinec': 'Dezember',

    # B2B / Demo
    'Demo režim': 'Demo-Modus', 'Demo': 'Demo', 'Demo data': 'Demo-Daten',
    'Reset demo dat': 'Demo-Daten zurücksetzen', 'Pirate': 'Pirate',
    'Pirátská kopie': 'Raubkopie',

    # POS / Restaurant
    'Stůl': 'Tisch', 'Stoly': 'Tische', 'Stolová správa': 'Tischverwaltung',
    'Účet': 'Rechnung', 'Účty': 'Rechnungen', 'Nový účet': 'Neue Rechnung',
    'Kuchyně': 'Küche', 'Bar': 'Bar', 'Servis': 'Service', 'Číšník': 'Kellner',
    'Pokladna': 'Kasse', 'Platba': 'Zahlung', 'Platby': 'Zahlungen',
    'Hotově': 'Bar', 'Kartou': 'Karte', 'Účtenka': 'Beleg', 'Bonet': 'Bestellbeleg',
    'Spropitné': 'Trinkgeld', 'Rozdělit účet': 'Rechnung aufteilen',
    'Spojit účty': 'Rechnungen zusammenfügen', 'Přesunout': 'Verschieben',

    # Accounting
    'Fakturace': 'Fakturierung', 'Bankovní účet': 'Bankkonto', 'IBAN': 'IBAN',
    'SWIFT': 'SWIFT', 'BIC': 'BIC', 'Konstantní symbol': 'Konstantes Symbol',
    'Variabilní symbol': 'Variables Symbol', 'Specifický symbol': 'Spezifisches Symbol',

    # Common buttons
    '+ Nová': '+ Neu', '+ Nový': '+ Neu', '+ Přidat': '+ Hinzufügen',
    '+ Nová objednávka': '+ Neue Bestellung', '+ Nový výrobek': '+ Neues Produkt',
    '+ Nový odběratel': '+ Neuer Kunde', '+ Nová faktura': '+ Neue Rechnung',
    '+ Nová kategorie': '+ Neue Kategorie', '+ Nová pobočka': '+ Neue Filiale',
    '+ Nová receptura': '+ Neues Rezept', '+ Nová surovina': '+ Neuer Rohstoff',
    '+ Nová akce': '+ Neue Aktion', '+ Nová cenová skupina': '+ Neue Preisgruppe',
    '+ Nová sezóna': '+ Neue Saison', '+ Nová skupina': '+ Neue Gruppe',
    '+ Nová slevová úroveň': '+ Neue Rabattstufe', '+ Nová měna': '+ Neue Währung',
    '+ Nová sazba DPH': '+ Neuer MwSt.-Satz', '+ Ingredience': '+ Zutat',
    '+ Nová ingredience': '+ Neue Zutat',

    # Misc UI
    'Vyhledávání': 'Suche', 'Filtrovat (název, číslo)…': 'Filtern (Name, Nummer)…',
    'TOP': 'TOP', 'TOP odběratelů': 'TOP Kunden', 'TOP výrobků': 'TOP Produkte',
    'TOP 10 odběratelů': 'TOP 10 Kunden', 'TOP 10 výrobků': 'TOP 10 Produkte',
    'Nedávné': 'Letzte', 'Nedávné objednávky': 'Letzte Bestellungen',
    'Nedávné DL': 'Letzte Lieferscheine', 'Nedávné faktury': 'Letzte Rechnungen',
    'Dnes - objednávek': 'Heute - Bestellungen', 'Tržby (zaplacené)': 'Umsatz (bezahlt)',

    # Frequent
    'Vyber': 'Wählen', 'Vyberte': 'Wählen Sie', 'Vybrat soubor': 'Datei auswählen',
    'Nahrát soubor': 'Datei hochladen', 'Nahrát obrázek': 'Bild hochladen',
    'Smazat obrázek': 'Bild löschen', 'Změnit': 'Ändern', 'Změna': 'Änderung',
    'Změny': 'Änderungen', 'Uložit změny': 'Änderungen speichern',
    'Zahodit změny': 'Änderungen verwerfen', 'Bez změny': 'Keine Änderung',

    # Empty states / negation
    'Sales report': 'Verkaufsbericht', 'Žádné DL': 'Keine Lieferscheine',
    'Žádné výrobky': 'Keine Produkte',
    'Zatím žádné výrobky': 'Noch keine Produkte',
    'Zatím žádné objednávky': 'Noch keine Bestellungen',
    'Zatím žádné faktury': 'Noch keine Rechnungen',
    'Zatím žádní odběratelé': 'Noch keine Kunden',
    'Žádný odběratel neodpovídá filtru': 'Kein Kunde entspricht dem Filter',
    'Žádný výrobek neodpovídá filtru': 'Kein Produkt entspricht dem Filter',
    'Žádná objednávka neodpovídá filtru': 'Keine Bestellung entspricht dem Filter',
    '⚠️ Po splatnosti': '⚠️ Überfällig', 'vše uhrazeno': 'alles bezahlt',
    'Žádné záznamy.': 'Keine Einträge.', 'Žádné položky.': 'Keine Posten.',
    'Žádné dodací listy': 'Keine Lieferscheine',
    'Žádné suroviny odpovídající filtru': 'Keine Rohstoffe entsprechen dem Filter',
    'Žádné uložené výrobní listy': 'Keine gespeicherten Produktionslisten',
    'Žádný štítek neodpovídá vyhledávání.': 'Kein Etikett entspricht der Suche.',
    'Žádné objednávky na zítra': 'Keine Bestellungen für morgen',
    'Žádné vyplněné řádky.': 'Keine ausgefüllten Zeilen.',
    'Žádné platné vCard bloky.': 'Keine gültigen vCard-Blöcke.',
    'Žádné faktury k tisku.': 'Keine Rechnungen zum Drucken.',
    'Žádné dodací listy k tisku.': 'Keine Lieferscheine zum Drucken.',
    'Žádné alergeny v surovinách': 'Keine Allergene in Rohstoffen',
    'Žádný volní odběratelé.': 'Keine freien Kunden.',
    'Žádný výrobek': 'Kein Produkt',
    'Načítám výrobky…': 'Produkte werden geladen…',
    'Žádný odběratel': 'Kein Kunde',

    # Selection / filter
    'Vybráno': 'Ausgewählt', 'Vybrat vše ze zobrazených': 'Alle Angezeigten auswählen',
    'Všechny': 'Alle', 'Všechny stavy': 'Alle Status', 'Jen aktivní': 'Nur aktive',
    'Jen skryté': 'Nur versteckte', 'Vše': 'Alles', 'Řadit': 'Sortieren',
    'Roztřídit': 'Sortieren', 'Přečíslovat': 'Neu nummerieren',

    # Import / Export
    'Z výrobku': 'Vom Produkt', 'Import ceníku': 'Preisliste importieren',
    'Import vizitek': 'Visitenkarten importieren', 'Import JSON/CSV': 'JSON/CSV-Import',
    'Náhled': 'Vorschau', 'Naplnit demo daty': 'Mit Demo-Daten füllen',
    '📊 Import ceníku': '📊 Preisliste importieren',
    '🎬 Naplnit demo daty': '🎬 Mit Demo-Daten füllen',
    '📋 Otevřít objednávky': '📋 Bestellungen öffnen',

    # New / Edit
    'Nový výrobek': 'Neues Produkt', 'Upravit výrobek': 'Produkt bearbeiten',
    'Nový odběratel': 'Neuer Kunde', 'Upravit odběratele': 'Kunden bearbeiten',
    'Nová objednávka': 'Neue Bestellung', 'Upravit objednávku': 'Bestellung bearbeiten',
    'Nový štítek': 'Neues Etikett', 'Upravit štítek': 'Etikett bearbeiten',
    'Detail objednávky': 'Bestelldetails', 'Detail faktury': 'Rechnungsdetails',
    'Detail dodacího listu': 'Lieferscheindetails',
    'Vytvořit': 'Erstellen', 'Vytvořit novou': 'Neue erstellen',
    'Vytvořit nový': 'Neuen erstellen', 'Vytvořit nové': 'Neues erstellen',
    'Vymazat': 'Löschen', 'Otevřít detail': 'Details öffnen',

    # Settings groups
    '🏢 Firma & doklady': '🏢 Unternehmen & Belege',
    '📧 Notifikace': '📧 Benachrichtigungen',
    '🥖 Výroba': '🥖 Produktion',
    '👥 Přístupy & ceny': '👥 Zugänge & Preise',
    '🎁 Balíčky': '🎁 Pakete',
    '🛠️ Údržba': '🛠️ Wartung',
    '❓ Nápověda & FAQ': '❓ Hilfe & FAQ',
    'Firemní údaje, kontakt, číselné řady, DPH': 'Firmendaten, Kontakt, Nummernkreise, MwSt.',
    'E-maily a uzávěrka úprav objednávek': 'E-Mails und Bearbeitungsfrist für Bestellungen',
    'Suroviny, kategorie, kalkulace, náklady': 'Rohstoffe, Kategorien, Kalkulationen, Kosten',
    'Uživatelé a slevové skupiny': 'Benutzer und Rabattgruppen',
    'Aktivace doplňkových modulů (Cukrárna, Lahůdky, …)': 'Aktivierung von Zusatzmodulen (Konditorei, Feinkost, …)',
    'Bezpečnost, zálohy DB, diagnostika': 'Sicherheit, DB-Backups, Diagnose',
    'Jak na to — návody a časté dotazy': 'So geht\'s — Anleitungen und FAQ',
    'Jazyk aplikace': 'Anwendungssprache', 'Vzhled aplikace': 'Anwendungsdesign',

    # HACCP
    'Plán HACCP': 'HACCP-Plan',
    'Plán systému kritických bodů': 'Plan der kritischen Kontrollpunkte',
    'Sanitační řád': 'Hygieneordnung', 'Vstupní instruktáž': 'Einweisung',
    'Postupy CCP': 'CCP-Verfahren', 'Formuláře': 'Formulare',
    'Záznamy o školení': 'Schulungsprotokolle', 'Interní audit': 'Internes Audit',
    'Audity': 'Audits', 'Audit': 'Audit', 'Auditor': 'Prüfer',
    'Výsledek': 'Ergebnis', 'V pořádku': 'In Ordnung', 'Nevyhovuje': 'Nicht konform',
    'S připomínkami': 'Mit Anmerkungen',

    # Loading / errors
    'Načítám...': 'Wird geladen...', 'Chyba serveru': 'Serverfehler',
    'Chyba při načítání': 'Fehler beim Laden', 'Změna uložena': 'Änderung gespeichert',
    'Změny uloženy': 'Änderungen gespeichert',
    'Nebyly provedeny žádné změny': 'Keine Änderungen vorgenommen',

    # Notifications
    'Vše přečteno': 'Alles gelesen', 'Vše označeno jako přečtené': 'Alles als gelesen markiert',
    'Notifikace': 'Benachrichtigungen',
    'Žádné nové notifikace': 'Keine neuen Benachrichtigungen',
    '🎉 Žádné nové notifikace': '🎉 Keine neuen Benachrichtigungen',
    'Aktivní balíčky': 'Aktive Pakete',

    # Auth
    'Vyhledat': 'Suchen', 'Vítejte,': 'Willkommen,', 'online B2B': 'Online B2B',
    'Celá obrazovka': 'Vollbild', 'Administrace': 'Verwaltung',
    'Nesprávný email nebo heslo': 'Falsche E-Mail oder Passwort',
    'Super admin': 'Super-Admin', 'Prodavač': 'Verkäufer',
    'Výroba': 'Produktion', 'Expedice': 'Versand',

    'Faktury se generují z dodacích listů. Vystav nejdřív DL z objednávky.':
        'Rechnungen werden aus Lieferscheinen generiert. Erstelle zuerst einen LS aus der Bestellung.',

    # Sidebar
    'Připnuto': 'Angeheftet', 'Připnout menu': 'Menü anheften',
    'Skrýt menu': 'Menü ausblenden', 'Zobrazit menu': 'Menü anzeigen',

    # Activity / sync
    'Activity log': 'Aktivitätsprotokoll', 'Žádná aktivita.': 'Keine Aktivität.',
    '📜 Posledních 5 sync operací': '📜 Letzte 5 Sync-Operationen',
    '☁️ Sync s cloudem': '☁️ Cloud-Sync', 'Poslední sync': 'Letzte Synchronisierung',
    'Čeká na sync': 'Wartet auf Sync', 'Sync teď': 'Jetzt synchronisieren',
    'Konfigurace': 'Konfiguration', 'Sync je vypnutý.': 'Sync ist deaktiviert.',
    'Částečný sync': 'Teilweise Sync', 'Nikdy nesyncováno': 'Nie synchronisiert',

    # License
    '🔑 Licence & aktualizace': '🔑 Lizenz & Updates',
    'Aktualizovat klíč': 'Schlüssel aktualisieren',
    'Zkontrolovat aktualizace': 'Nach Updates suchen',
    'Modulární licence': 'Modulare Lizenz',

    # Particles / units
    'např.': 'z. B.', 'volitelné': 'optional', 'povinné': 'erforderlich',
    'min.': 'mind.', 'max.': 'max.',
    'ks': 'Stk.', 'kg': 'kg', 'g': 'g', 'l': 'l', 'Kč': 'CZK',
    'Stránka': 'Seite', 'stránek': 'Seiten', 'Více': 'Mehr', 'Méně': 'Weniger',
    'Klik': 'Klick', '🖨️ Fronta tisku': '🖨️ Druckwarteschlange',
    'Klikněte na': 'Klicken Sie auf',

    # Cake configurator
    '🧁 Konfigurátor dortů': '🧁 Torten-Konfigurator',
    '📏 Velikost (počet porcí)': '📏 Größe (Anzahl Portionen)',
    '🍫 Příchuť': '🍫 Geschmack', '✨ Dekorace': '✨ Dekoration',
    '📝 Text na dortu & foto předlohy': '📝 Text auf Torte & Fotovorlagen',
    '💰 Kalkulace': '💰 Kalkulation', 'Velikost': 'Größe',
    'v ceně': 'inklusive', 'zdarma': 'kostenlos',
    'Doba přípravy': 'Zubereitungszeit', 'Bez dekorace': 'Ohne Dekoration',
    '📋 Vytvořit objednávku': '📋 Bestellung erstellen',

    # Catering
    '🥗 Catering kalkulátor': '🥗 Catering-Kalkulator',
    'Zadej počet osob + typ události → kalkulátor doporučí množství a spočítá cenu.':
        'Personenanzahl + Eventtyp eingeben → Rechner empfiehlt Menge und berechnet Preis.',
    '👥 Akce': '👥 Event', 'Počet osob': 'Personenanzahl',
    'Typ události': 'Eventtyp',
    '🍽️ Jídlo (zaškrtni co chceš)': '🍽️ Speisen (auswählen)',
    '🥤 Nápoje': '🥤 Getränke', 'Pro': 'Für', 'osob': 'Personen',
    'osoba': 'Person', '/ osoba': '/ Person', '/ porci': '/ Portion',
    'Nic nevybráno.': 'Nichts ausgewählt.',

    # Seasonal
    '🍰 Sezónní katalog': '🍰 Saisonkatalog',
    '📅 Sezóny': '📅 Saisons',
    '🥖 Přiřaď výrobky k sezónám': '🥖 Produkte Saisons zuordnen',
    '🟢 PRÁVĚ AKTIVNÍ': '🟢 GERADE AKTIV',
    '⏸️ mimo sezónu': '⏸️ außerhalb der Saison',
    '🐰 Velikonoce': '🐰 Ostern', '🌷 Den matek': '🌷 Muttertag',
    '💝 Sv. Valentýn': '💝 Valentinstag',
    '🎃 Halloween': '🎃 Halloween',
    '🎄 Vánoce': '🎄 Weihnachten', '🎅 Mikuláš': '🎅 Nikolaus',

    # Branding
    '📞 Kontaktní údaje': '📞 Kontaktdaten',
    'Volitelné — zobrazí se v patičce dokladů.': 'Optional — erscheint in der Fußzeile der Belege.',
    'E-mail firmy': 'Firmen-E-Mail', 'Patička dokladů': 'Belegfußzeile',
    '🎨 Branding (vlastní barva + logo)': '🎨 Branding (eigene Farbe + Logo)',
    '🎨 Primární barva': '🎨 Primärfarbe',
    '🖼️ URL loga': '🖼️ Logo-URL',
    '📺 Náhled': '📺 Vorschau',
    '🔄 Vyzkoušet barvu hned': '🔄 Farbe sofort testen',
    'Ukázkové tlačítko': 'Beispiel-Schaltfläche',

    # Webhooks
    '🔄 Webhooks': '🔄 Webhooks', '(out-going HTTP)': '(ausgehend HTTP)',
    '+ Nový webhook': '+ Neuer Webhook',
    '📡 Žádné webhooks. Klikni „+ Nový webhook".': '📡 Keine Webhooks. Klicke "+ Neuer Webhook".',
    'Events': 'Ereignisse', 'Test fire': 'Test',
    'Webhook log': 'Webhook-Log', '📜 Webhook log': '📜 Webhook-Log',
    '🚀 Test event odeslán — zkontroluj log': '🚀 Test-Ereignis gesendet — Log prüfen',
    'Smazat webhook?': 'Webhook löschen?',
    'Smaže i log historie. Akce je nevratná.': 'Löscht auch den Verlauf. Aktion ist unwiderruflich.',
    'Webhook smazán': 'Webhook gelöscht',
    'Webhook uložen': 'Webhook gespeichert',
    'volání': 'Aufrufe', 'selhání': 'Fehler', 'Naposled:': 'Zuletzt:',
    '(volitelné) náhodný string pro ověření': '(optional) zufälliger String zur Verifizierung',

    # 2FA
    '🔐 Dvoufaktorové ověření': '🔐 Zwei-Faktor-Authentifizierung',
    'Dvoufaktorové ověření (2FA)': 'Zwei-Faktor-Authentifizierung (2FA)',
    '🔐 2FA kód (6 číslic)': '🔐 2FA-Code (6 Ziffern)',
    'Z aplikace Google Authenticator / Authy / 1Password / …': 'Aus Google Authenticator / Authy / 1Password / …',
    'Zapnout 2FA': '2FA aktivieren', 'Vypnout 2FA': '2FA deaktivieren',
    'Ověřovací kód': 'Verifizierungscode',
    'Zadej 6místný kód z autentikační aplikace.': 'Gib den 6-stelligen Code aus der Authenticator-App ein.',
    'Neplatný kód. Zkus znovu (kód se mění každých 30s).':
        'Ungültiger Code. Bitte erneut versuchen (Code ändert sich alle 30 Sek.).',

    # Keyboard shortcuts
    '⌨️ Klávesové zkratky': '⌨️ Tastenkürzel',
    'Global': 'Global', 'Quick navigation': 'Schnellnavigation',
    'Quick actions': 'Schnellaktionen', 'V seznamech': 'In Listen',
    'Otevřít vyhledávání (Command Palette)': 'Suche öffnen (Befehlspalette)',
    'Tato nápověda': 'Diese Hilfe',
    'Zavřít modal / panel': 'Modal/Panel schließen',
    'Fullscreen toggle': 'Vollbild umschalten',
    'přidán do tisku': 'zum Druck hinzugefügt',
    'Otevírám': 'Öffne', 'dokumentů…': 'Dokumente…',

    # License extras
    'Expirují do 30 dní': 'Laufen in 30 Tagen ab',
    'Expirované': 'Abgelaufen', 'Revoked': 'Widerrufen',
    'Celkem klíčů': 'Schlüssel gesamt',
    '📜 Activity log': '📜 Aktivitätsprotokoll',
    'Posledních 50 akcí v aplikaci — login pokusy, sync operace, audit změn.':
        'Letzte 50 Aktionen in der Anwendung — Login-Versuche, Sync, Änderungsprotokoll.',

    # Import
    'Mapping sloupců': 'Spaltenzuordnung',
    'Auto-match výsledky': 'Auto-Match-Ergebnisse', 'Hotovo!': 'Fertig!',
    'Soubor': 'Datei', 'DB pole': 'DB-Feld', 'Sloupec v souboru': 'Spalte in Datei',
    'Podle čeho párovat na existující záznamy?': 'Anhand welches Feldes mit bestehenden Datensätzen abgleichen?',
    'Cíl': 'Ziel', 'Akce': 'Aktion', 'Z importu': 'Vom Import',
    'Ostatní data': 'Sonstige Daten', 'Shoda': 'Übereinstimmung', 'Nejisté': 'Unsicher',
    '📝 Update': '📝 Update', '➕ Vytvořit nový': '➕ Neuen erstellen',
    '⏭️ Přeskočit': '⏭️ Überspringen',

    # Demo seed
    '🎬 Naplnit ukázkovými daty': '🎬 Mit Beispieldaten füllen',
    'Aplikace přidá': 'Die Anwendung fügt hinzu', 'kategorií': 'Kategorien',
    'výrobků': 'Produkte', 'odběratelů': 'Kunden', 'surovin': 'Rohstoffe',
    'Existující záznamy se zachovají (skipuje duplicity).':
        'Bestehende Datensätze bleiben erhalten (Duplikate werden übersprungen).',
    'Demo data připravena': 'Demo-Daten bereit',
    'Naplnit': 'Füllen',

    # Required
    'Název *': 'Name *', 'Název firmy *': 'Firmenname *',

    'Vše →': 'Alles →',

    # Validation
    'Čekající': 'Wartend', 'Deaktivovaný': 'Deaktiviert',
    'Fakturováno': 'Berechnet', 'Nefakturováno': 'Nicht berechnet',
    'VÝCHOZÍ': 'STANDARD', 'DOPORUČUJEME': 'EMPFEHLUNG',
    'Skrytá': 'Versteckt',
    'Vyplňte název': 'Name eingeben', 'Vyplň název': 'Name eingeben',
    'Vyplňte období': 'Zeitraum eingeben',
    'Vyplňte datum dodání': 'Lieferdatum eingeben',
    'Vyplňte datum vystavení': 'Ausstellungsdatum eingeben',
    'Vyplňte oba datumy': 'Beide Daten eingeben',
    'Vyplňte tělo': 'Text eingeben',
    'Vyplňte předmět': 'Betreff eingeben',
    'Vyberte odběratele': 'Kunden auswählen',
    'Vyberte výrobek z nabídky': 'Produkt aus dem Angebot auswählen',
    'Přidejte alespoň jednu položku': 'Mindestens einen Posten hinzufügen',
    'Přidejte z katalogu níže.': 'Aus dem Katalog unten hinzufügen.',
    'Přidejte z katalogu níže nebo volný řádek.':
        'Aus dem Katalog unten hinzufügen oder freie Zeile.',
    'Tato objednávka nemá žádné položky.':
        'Diese Bestellung hat keine Posten.',
    'Zadej cílový počet kusů.': 'Zielmenge in Stück eingeben.',
    'Zadej cílovou hmotnost těsta.': 'Zielmasse des Teigs eingeben.',
    'Výrobek nenalezen v ceníku': 'Produkt in der Preisliste nicht gefunden',
    'Receptura nemá platnou hmotnost.': 'Rezept hat keine gültige Masse.',
    'Chyba ukládání:': 'Speicherfehler:',
    'Nepodařilo se načíst poslední objednávky:':
        'Letzte Bestellungen konnten nicht geladen werden:',

    # POS / Restaurant
    'Stůl': 'Tisch', 'Stoly': 'Tische', 'Stolová správa': 'Tischverwaltung',
    'Účty': 'Rechnungen', 'Nový účet': 'Neue Rechnung',
    'Kuchyně': 'Küche', 'Bar': 'Bar', 'Servis': 'Service',
    'Číšník': 'Kellner', 'Pokladna': 'Kasse',
    'Platba': 'Zahlung', 'Platby': 'Zahlungen',
    'Hotově': 'Bar', 'Kartou': 'Karte',
    'Účtenka': 'Beleg', 'Spropitné': 'Trinkgeld',
    'Rozdělit účet': 'Rechnung aufteilen',
    'Spojit účty': 'Rechnungen zusammenfügen', 'Přesunout': 'Verschieben',

    # Banking
    'Fakturace': 'Fakturierung', 'Bankovní účet': 'Bankkonto',
    'IBAN': 'IBAN', 'SWIFT': 'SWIFT', 'BIC': 'BIC',
    'Konstantní symbol': 'Konstantes Symbol',
    'Variabilní symbol': 'Variables Symbol',
    'Specifický symbol': 'Spezifisches Symbol',

    # Toggles
    'Zapnout': 'Aktivieren', 'Vypnout': 'Deaktivieren',
    'Povolit': 'Erlauben', 'Zakázat': 'Verbieten',
    'Zablokovat': 'Sperren', 'Odblokovat': 'Entsperren',
    'Skrýt': 'Ausblenden', 'Zobrazit': 'Anzeigen',
    'Skrýt vše': 'Alles ausblenden', 'Zobrazit vše': 'Alles anzeigen',

    # Pagination
    'První': 'Erste', 'Poslední': 'Letzte',
    'Stránka 1 z': 'Seite 1 von', 'Záznamů': 'Einträge',
    'Zobrazeno': 'Angezeigt', 'z celkem': 'von insgesamt',

    # Stock
    'Skladová zásoba': 'Lagerbestand', 'Skladem na': 'Auf Lager in',
    'Pohyb skladu': 'Lagerbewegung', 'Příjem': 'Eingang', 'Výdej': 'Ausgang',
    'Naskladnit': 'Einlagern', 'Vyskladnit': 'Auslagern',
    'Inventura': 'Inventur',

    # Recipes
    'Receptura': 'Rezept', 'Receptury': 'Rezepte',
    'Ingredience': 'Zutat', 'Suroviny v receptu': 'Zutaten im Rezept',
    'Krok': 'Schritt', 'Kroky': 'Schritte', 'Postup': 'Zubereitung',

    # Date/time
    'Datum a čas': 'Datum und Uhrzeit', 'Čas': 'Uhrzeit',
    'Od kdy': 'Von', 'Do kdy': 'Bis',
    'Začátek': 'Beginn', 'Konec': 'Ende', 'Trvání': 'Dauer',

    # Form validation
    'Povinné pole': 'Pflichtfeld', 'Volitelné pole': 'Optionales Feld',
    'Neplatný formát': 'Ungültiges Format',
    'Toto pole je povinné': 'Dieses Feld ist erforderlich',
    'Neplatný e-mail': 'Ungültige E-Mail',
    'Heslo musí mít alespoň 8 znaků': 'Passwort muss mindestens 8 Zeichen haben',
    'Hesla se neshodují': 'Passwörter stimmen nicht überein',

    # Plurals (Czech declension)
    'měsíc': 'Monat', 'měsíce': 'Monate', 'měsíců': 'Monate',
    'den': 'Tag', 'dny': 'Tage', 'dní': 'Tage',
    'hodina': 'Stunde', 'hodiny': 'Stunden', 'hodin': 'Stunden',
    'minuta': 'Minute', 'minuty': 'Minuten', 'minut': 'Minuten',
    'rok': 'Jahr', 'roky': 'Jahre', 'let': 'Jahre',
    'kus': 'Stück', 'kusy': 'Stücke', 'kusů': 'Stücke',
    'položka': 'Posten', 'položky': 'Posten', 'položek': 'Posten',

    # Common imperatives
    'Pošli': 'Senden', 'Pošlete': 'Bitte senden',
    'Načti': 'Laden', 'Vyplň': 'Eingeben', 'Vyplňte': 'Eingeben',
    'Klikni': 'Klicken', 'Klikněte': 'Bitte klicken',
    'Stiskni': 'Drücken', 'Stiskněte': 'Bitte drücken',
    'Pokud': 'Wenn', 'Pokud potřebuješ': 'Wenn du brauchst',
    'Najdi': 'Finden', 'Najdete': 'Finden Sie',

    # Status
    'Dostupný': 'Verfügbar', 'Nedostupný': 'Nicht verfügbar',
    'Online': 'Online', 'Offline': 'Offline',
    'Připojeno': 'Verbunden', 'Odpojeno': 'Getrennt',
    'Otevřeno': 'Geöffnet', 'Zavřeno': 'Geschlossen',
    'Veřejný': 'Öffentlich', 'Soukromý': 'Privat',
    'Soukromé': 'Privat', 'Veřejné': 'Öffentlich',

    # Errors
    'Chyba načítání': 'Ladefehler',
    'Chyba ukládání': 'Speicherfehler',
    'Nepodařilo se uložit': 'Speichern fehlgeschlagen',
    'Nepodařilo se načíst': 'Laden fehlgeschlagen',
    'Nepodařilo se smazat': 'Löschen fehlgeschlagen',
    'Nepodařilo se odeslat': 'Senden fehlgeschlagen',
    'Zkuste to znovu': 'Bitte erneut versuchen',
    'Zkuste to znovu později': 'Bitte später erneut versuchen',
    'Operace selhala': 'Vorgang fehlgeschlagen',
    'Operace úspěšná': 'Vorgang erfolgreich',

    # Calendar
    'Kalendář': 'Kalender',
    'Po': 'Mo', 'Út': 'Di', 'St': 'Mi', 'Čt': 'Do', 'Pá': 'Fr', 'So': 'Sa', 'Ne': 'So',

    # Misc
    'Hodnota': 'Wert', 'Hodnoty': 'Werte',
    'Klíč': 'Schlüssel', 'Klíče': 'Schlüssel',
    'Účinné od': 'Gültig ab', 'Účinné do': 'Gültig bis',
    'Od': 'Von', 'Do': 'Bis',
    'S': 'Mit', 'Z': 'Aus', 'Na': 'Auf', 'V': 'In',

    # Allergens
    'Obsahuje alergeny:': 'Enthält Allergene:', 'Bez alergenů': 'Ohne Allergene',
    'Lepek': 'Gluten', 'Mléko': 'Milch', 'Vejce': 'Eier',
    'Ořechy': 'Nüsse', 'Sezam': 'Sesam', 'Sója': 'Soja',
    'Hořčice': 'Senf', 'Celer': 'Sellerie', 'Ryby': 'Fisch',
    'Korýši': 'Krebstiere', 'Měkkýši': 'Weichtiere', 'Vlčí bob': 'Lupinen',
    'Oxid siřičitý': 'Schwefeldioxid', 'Burské oříšky': 'Erdnüsse',
    'Arašídy': 'Erdnüsse', 'Mandlové': 'Mandel-',

    # Pricing
    'Sleva (%)': 'Rabatt (%)', 'Cenová skupina': 'Preisgruppe',
    'Pevná cena': 'Festpreis', 'Bez DPH celkem': 'Ohne MwSt. gesamt',
    'S DPH celkem': 'Inkl. MwSt. gesamt', 'DPH celkem': 'MwSt. gesamt',
    'Splatnost dní': 'Zahlungsziel Tage',

    # Reports
    'Měsíční přehled': 'Monatsübersicht', 'Roční přehled': 'Jahresübersicht',
    'Týdenní přehled': 'Wochenübersicht', 'Denní přehled': 'Tagesübersicht',
    'Top zákazníci': 'Top-Kunden', 'Top produkty': 'Top-Produkte',
    'Exportovat': 'Exportieren',
    'Označit jako uhrazené': 'Als bezahlt markieren',
    'Označit jako neuhrazené': 'Als unbezahlt markieren',
    'Označeno jako uhrazené': 'Als bezahlt markiert',
    'Označeno jako neuhrazené': 'Als unbezahlt markiert',
    'Stornovat fakturu': 'Rechnung stornieren',
    'Storno faktura': 'Stornorechnung',

    # Exports
    'ISDOC export': 'ISDOC-Export', 'CSV export': 'CSV-Export',
    'PDF export': 'PDF-Export', 'XML export': 'XML-Export',
    'Stáhnout ZIP': 'ZIP herunterladen', 'Stáhnout XML': 'XML herunterladen',
    'Sloučit do jednoho PDF': 'In ein PDF zusammenfügen',
    'Tisknout všechny vybrané': 'Alle ausgewählten drucken',

    # Search
    'Najdi výrobek…': 'Produkt suchen…',
    'Najdi odběratele…': 'Kunden suchen…',
    'Volný řádek': 'Freie Zeile', 'Volný text': 'Freier Text',

    # Order reuse
    'Z předchozí objednávky': 'Aus vorheriger Bestellung',
    'Znovu objednat': 'Erneut bestellen',
    'Duplikovat objednávku': 'Bestellung duplizieren',

    # Holidays
    'Svátek': 'Feiertag', 'Den díkůvzdání': 'Erntedankfest',
    '1. svátek vánoční': '1. Weihnachtstag',
    '2. svátek vánoční / Štěpán': '2. Weihnachtstag / Stephanstag',
    'Den boje za svobodu': 'Tag des Kampfes für Freiheit',
    'Den dětí': 'Kindertag', 'Den otců': 'Vatertag',
    'Den otevřených dveří': 'Tag der offenen Tür',
    'Den pracovního klidu': 'Feiertag',

    # Reports
    'Vygenerovat report': 'Bericht generieren',
    'Aktualizovat vše': 'Alles aktualisieren',

    # Capacity
    '📅 Kapacita pečení': '📅 Backkapazität',
    '🖼️ Galerie inspirací': '🖼️ Inspirationsgalerie',
    '🎂 Konfigurátor dortů': '🎂 Torten-Konfigurator',
    '⚙️ Výchozí denní kapacita': '⚙️ Standard-Tageskapazität',
    'Maximální počty pro běžný den (lze přepsat per-den níže).':
        'Maximalmengen für einen normalen Tag (kann pro Tag unten überschrieben werden).',
    '🎂 Max dortů / den': '🎂 Max. Torten / Tag',
    '🥪 Max chlebíčků / den': '🥪 Max. Sandwiches / Tag',
    '🧁 Max zákusků / den': '🧁 Max. Gebäck / Tag',
    '💾 Uložit defaulty': '💾 Standards speichern',
    '📅 Kalendář příštích 30 dní': '📅 Kalender der nächsten 30 Tage',
    'Defaultní kapacita uložena': 'Standardkapazität gespeichert',
    'Kapacita uložena': 'Kapazität gespeichert',
    'Kapacita resetovaná': 'Kapazität zurückgesetzt',
    'Reset kapacity?': 'Kapazität zurücksetzen?',
    'Vrátí den na výchozí kapacitu.':
        'Setzt den Tag auf die Standardkapazität zurück.',
    '🚫 Zavřeno': '🚫 Geschlossen',
    '🚫 Zavřeno — nepřijímat objednávky na tento den':
        '🚫 Geschlossen — keine Bestellungen für diesen Tag annehmen',
    'Poznámka pro tým': 'Notiz für das Team',
    'volných': 'frei', 'vlastní': 'eigene',
    'Obsazenost:': 'Belegung:',
    'z aktuálních objednávek': 'aus aktuellen Bestellungen',
    '↩️ Reset na default': '↩️ Auf Standard zurücksetzen',

    # Gallery
    'Zatím žádné fotky': 'Noch keine Fotos',
    'Přidej první foto dortu jako inspirace pro zákazníky.':
        'Füge das erste Tortenfoto als Inspiration für Kunden hinzu.',
    '➕ Přidat foto': '➕ Foto hinzufügen',
    'Foto přidáno': 'Foto hinzugefügt', 'Foto smazáno': 'Foto gelöscht',
    'Smazat foto?': 'Foto löschen?',
    'Foto se odebere z galerie.': 'Das Foto wird aus der Galerie entfernt.',

    # Tables
    '🪑 Stolová správa': '🪑 Tischverwaltung',
    'Stoly · Rezervace · Mapa restaurace': 'Tische · Reservierungen · Restaurantplan',
    '+ Nový stůl': '+ Neuer Tisch',
    '+ Přidat první stůl': '+ Ersten Tisch hinzufügen',
    'Žádné stoly': 'Keine Tische',
    'Počet míst': 'Sitzplätze', 'Sekce': 'Bereich',
    'Tvar': 'Form', 'Čtverec': 'Quadrat',
    'Kulatý': 'Rund', 'Obdélník': 'Rechteck',
    '🟢 volný': '🟢 frei', 'rez.': 'Res.',
    'Stůl přidán': 'Tisch hinzugefügt',
    'Stůl smazán': 'Tisch gelöscht',
    'Smazat stůl?': 'Tisch löschen?',
    'Smaže i všechny rezervace tohoto stolu.':
        'Löscht auch alle Reservierungen dieses Tisches.',

    # Reservations
    'Rezervace': 'Reservierung', 'Rezervace stolu': 'Tischreservierung',
    'Rezervovat': 'Reservieren', '📅 Rezervovat': '📅 Reservieren',
    'Rezervace uložena': 'Reservierung gespeichert',
    'Čas od': 'Zeit von', 'Čas do': 'Zeit bis',
    'Vyplň jméno.': 'Namen eingeben.',
    '🎉 Velký catering': '🎉 Großes Catering',

    # Templates
    'Šablona': 'Vorlage', 'Šablony': 'Vorlagen',
    'Nová šablona': 'Neue Vorlage',
    'Vytvořit šablonu': 'Vorlage erstellen',
    'Použít šablonu': 'Vorlage verwenden',
    'Šablona uložena': 'Vorlage gespeichert',
    'Smazat šablonu?': 'Vorlage löschen?',

    # Backup
    'Záloha': 'Backup', 'Zálohy': 'Backups',
    'Vytvořit zálohu': 'Backup erstellen',
    'Obnovit ze zálohy': 'Aus Backup wiederherstellen',
    'Stáhnout zálohu': 'Backup herunterladen',
    'Záloha vytvořena': 'Backup erstellt',
    'Záloha obnovena': 'Backup wiederhergestellt',
    'Smazat zálohu?': 'Backup löschen?',
    'Smazat všechny zálohy?': 'Alle Backups löschen?',
    'Automatické zálohy': 'Automatische Backups',
    'Frekvence záloh': 'Backup-Frequenz',
    'Denně': 'Täglich', 'Týdně': 'Wöchentlich', 'Měsíčně': 'Monatlich',

    # User management
    'Spravovat uživatele': 'Benutzer verwalten',
    'Pozvat uživatele': 'Benutzer einladen',
    'Pozvánka odeslána': 'Einladung gesendet',
    'Změnit roli': 'Rolle ändern',
    'Změnit heslo': 'Passwort ändern',
    'Resetovat heslo': 'Passwort zurücksetzen',
    'Nové heslo': 'Neues Passwort',
    'Současné heslo': 'Aktuelles Passwort',
    'Potvrdit heslo': 'Passwort bestätigen',
    'Heslo změněno': 'Passwort geändert',
    'Uživatel vytvořen': 'Benutzer erstellt',
    'Uživatel smazán': 'Benutzer gelöscht',
    'Uživatel aktivován': 'Benutzer aktiviert',
    'Uživatel deaktivován': 'Benutzer deaktiviert',
    'Smazat uživatele?': 'Benutzer löschen?',

    # Roles
    'Administrátor': 'Administrator', 'Manažer': 'Manager',
    'Uživatel': 'Benutzer', 'Pouze čtení': 'Nur Lesen',
    'Vlastník': 'Eigentümer',

    # Settings sub
    'Obecné': 'Allgemein', 'Vzhled': 'Erscheinungsbild',
    'Bezpečnost': 'Sicherheit', 'Soukromí': 'Datenschutz',
    'Integrace': 'Integrationen', 'Účty': 'Konten',
    'Pokročilé': 'Erweitert', 'Experimentální': 'Experimentell',

    # Buttons ext
    'Uložit a další': 'Speichern und weiter',
    'Uložit a pokračovat': 'Speichern und fortfahren',
    'Uložit a nový': 'Speichern und neu',
    'Uložit jako šablonu': 'Als Vorlage speichern',
    'Náhled tisku': 'Druckvorschau',
    'Nastavit': 'Festlegen',
    'Konfigurovat': 'Konfigurieren',
    'Použít': 'Anwenden',
    'Spustit': 'Starten', 'Zastavit': 'Stoppen',
    'Pauza': 'Pause', 'Pokračování': 'Fortsetzung',
    'Přerušit': 'Unterbrechen', 'Restart': 'Neustart',

    # Filters
    'Filtr': 'Filter', 'Filtry': 'Filter',
    'Aktivní filtry': 'Aktive Filter',
    'Vymazat filtry': 'Filter löschen',
    'Resetovat filtry': 'Filter zurücksetzen',
    'Použít filtry': 'Filter anwenden',
    'Pokročilý filtr': 'Erweiterter Filter',

    # Sorting
    'Seřadit podle': 'Sortieren nach',
    'Vzestupně': 'Aufsteigend', 'Sestupně': 'Absteigend',
    'Abecedně': 'Alphabetisch', 'Podle data': 'Nach Datum',
    'Podle ceny': 'Nach Preis', 'Podle množství': 'Nach Menge',

    # Common messages
    'Opravdu smazat?': 'Wirklich löschen?',
    'Tato akce je nevratná.': 'Diese Aktion ist unwiderruflich.',
    'Operace dokončena.': 'Vorgang abgeschlossen.',
    'Operace přerušena.': 'Vorgang abgebrochen.',
    'Nejprve vyber položku.': 'Zuerst einen Posten auswählen.',
    'Změny nebyly uloženy.': 'Änderungen wurden nicht gespeichert.',
    'Chcete uložit změny?': 'Möchten Sie die Änderungen speichern?',
    'Uložit změny před odchodem?': 'Änderungen vor dem Verlassen speichern?',
    'Odejít bez uložení': 'Ohne Speichern verlassen',

    # Order workflow
    'Čeká na potvrzení': 'Wartet auf Bestätigung',
    'Potvrzená objednávka': 'Bestätigte Bestellung',
    'V přípravě': 'In Vorbereitung',
    'Připravena k expedici': 'Versandbereit',
    'Vyzvednutí': 'Abholung',
    'Závoz': 'Lieferung', 'Závozy': 'Lieferungen',

    # UI bits
    'Zobrazit více': 'Mehr anzeigen',
    'Zobrazit méně': 'Weniger anzeigen',
    'Zobrazit detaily': 'Details anzeigen',
    'Skrýt detaily': 'Details ausblenden',
    'Rozbalit': 'Aufklappen', 'Sbalit': 'Einklappen',
    'Rozbalit vše': 'Alle aufklappen',
    'Sbalit vše': 'Alle einklappen',
    'Předchozí': 'Vorherige', 'Další': 'Weiter',
    'Předchozí strana': 'Vorherige Seite',
    'Další strana': 'Nächste Seite',

    # Confirmations
    'Smazáno!': 'Gelöscht!',
    'Uloženo!': 'Gespeichert!',
    'Aktualizováno!': 'Aktualisiert!',
    'Vytvořeno!': 'Erstellt!',
    'Odesláno!': 'Gesendet!',
    'Hotovo!': 'Fertig!',
    'Úspěšně provedeno': 'Erfolgreich ausgeführt',
    'Úspěšně uloženo': 'Erfolgreich gespeichert',
    'Úspěšně smazáno': 'Erfolgreich gelöscht',
    'Úspěšně vytvořeno': 'Erfolgreich erstellt',
    'Úspěšně odesláno': 'Erfolgreich gesendet',

    # CMD-K / Search
    'Hledat objednávky, výrobky, odběratele…': 'Bestellungen, Produkte, Kunden suchen…',
    'Žádné výsledky': 'Keine Ergebnisse',
    'Žádné výsledky pro': 'Keine Ergebnisse für',
    'Hledat všude': 'Überall suchen',
    'Nedávno hledané': 'Kürzlich gesucht',
    'Klávesové zkratky': 'Tastenkürzel',
    'Skok na': 'Springe zu',

    # Modals
    'Zavřít okno': 'Fenster schließen',
    'Minimalizovat': 'Minimieren',
    'Maximalizovat': 'Maximieren',
    'Otevřít v novém okně': 'In neuem Fenster öffnen',

    # Stats
    'Počet objednávek': 'Anzahl Bestellungen',
    'Počet faktur': 'Anzahl Rechnungen',
    'Počet dodacích listů': 'Anzahl Lieferscheine',
    'Počet výrobků': 'Anzahl Produkte',
    'Počet odběratelů': 'Anzahl Kunden',
    'Počet uživatelů': 'Anzahl Benutzer',
    'Celkový počet': 'Gesamtanzahl',
    'Průměr': 'Durchschnitt',
    'Maximum': 'Maximum', 'Minimum': 'Minimum',
    'Suma': 'Summe', 'Součet': 'Summe',

    # Workflow
    'Objednáno': 'Bestellt',
    'Vyrobeno': 'Hergestellt',
    'Zabaleno': 'Verpackt',
    'Vyexpedováno': 'Versendet',
    'Doručeno na adresu': 'An Adresse geliefert',
    'Předáno': 'Übergeben',
    'Vráceno': 'Zurückgegeben',
    'Reklamace': 'Reklamation',
    'Reklamováno': 'Reklamiert',

    # Email templates
    'E-mail šablona': 'E-Mail-Vorlage',
    'E-mail šablony': 'E-Mail-Vorlagen',
    'Předmět e-mailu': 'E-Mail-Betreff',
    'Tělo e-mailu': 'E-Mail-Text',
    'Odeslat e-mail': 'E-Mail senden',
    'E-mail odeslán': 'E-Mail gesendet',
    'Komu': 'An', 'Předmět': 'Betreff',
    'Příloha': 'Anhang', 'Přílohy': 'Anhänge',

    # Ordinals
    'První': 'Erste', 'Druhý': 'Zweite', 'Třetí': 'Dritte',
    'Čtvrtý': 'Vierte', 'Pátý': 'Fünfte',
    'Poslední': 'Letzte',

    # Diagnostics
    '🩺 Diagnostika': '🩺 Diagnose',
    'Spustit diagnostiku': 'Diagnose starten',
    'Výsledky diagnostiky': 'Diagnoseergebnisse',
    'Vše v pořádku': 'Alles in Ordnung',
    'Nalezeny problémy': 'Probleme gefunden',
    'Detaily': 'Details',

    # Inventory
    'Inventarizace': 'Inventur',
    'Zahájit inventuru': 'Inventur starten',
    'Ukončit inventuru': 'Inventur beenden',
    'Stav skladu': 'Lagerbestand',
    'Pohyb': 'Bewegung', 'Pohyby': 'Bewegungen',
    'Inventarizační rozdíl': 'Inventurdifferenz',

    # Templates / Categories
    'Žádné šablony.': 'Keine Vorlagen.',
    'Šablona smazána': 'Vorlage gelöscht',
    'Smaže i všechny kategorie a ingredience.': 'Löscht auch alle Kategorien und Zutaten.',
    'Základní cena': 'Grundpreis',
    'Základní cena (Kč)': 'Grundpreis (€)',
    'Ikona (emoji)': 'Symbol (Emoji)',
    'Kategorie uložena': 'Kategorie gespeichert',
    'Kategorie smazána': 'Kategorie gelöscht',
    'Smazat kategorii?': 'Kategorie löschen?',
    'Smaže i všechny ingredience v ní.': 'Löscht auch alle Zutaten darin.',
    'Min. výběr': 'Min. Auswahl', 'Max. výběr': 'Max. Auswahl',
    'Povinná kategorie (musí se z ní vybrat)': 'Pflichtkategorie (Auswahl erforderlich)',
    'vyber': 'wählen',
    'Bez ingrediencí — přidej výše.': 'Keine Zutaten — oben hinzufügen.',
    'Ingredience uložena': 'Zutat gespeichert',
    'Ingredience smazána': 'Zutat gelöscht',
    'Smazat ingredienci?': 'Zutat löschen?',
    'Příplatek (Kč)': 'Aufpreis (€)',
    '⚠ Alergeny (čárkami)': '⚠ Allergene (mit Kommas)',
    'Mléko, Vejce, Lepek': 'Milch, Eier, Gluten',
    'Šablona nemá žádné kategorie ingrediencí.': 'Vorlage hat keine Zutatenkategorien.',
    '+ Přidat kategorii': '+ Kategorie hinzufügen',
    '+ Přidat první šarži': '+ Erste Charge hinzufügen',
    '✏️ Upravit šablonu': '✏️ Vorlage bearbeiten',
    'v této kategorii': 'in dieser Kategorie',
    'ingr.': 'Zut.',

    # Restaurant
    '🚚 Catering objednávky s časem dodání': '🚚 Catering-Bestellungen mit Lieferzeit',
    'Sdílí evidenci s balíčkem 🎉 Velký catering': 'Teilt Erfassung mit Paket 🎉 Großes Catering',
    '+ Vytvořit první akci': '+ Erstes Event erstellen',
    'Čas dodání': 'Lieferzeit', 'akcí': 'Events',
    '🍕 Restaurace / Pizzerie': '🍕 Restaurants / Pizzerien',
    '🪑 Stoly': '🪑 Tische',
    '👨‍🍳 Kapacita kuchyně': '👨‍🍳 Küchenkapazität',
    '⏱️ Doba přípravy': '⏱️ Zubereitungszeit',
    '🛵 Rozvoz / Kurýry': '🛵 Lieferung / Kuriere',
    '⚡ Aktuální vytížení': '⚡ Aktuelle Auslastung',
    '🚫 Kuchyně plná': '🚫 Küche voll',
    'vytížení': 'Auslastung',
    '📋 Aktivní objednávky': '📋 Aktive Bestellungen',
    '🔨 Připravuje se': '🔨 Wird zubereitet',
    '✅ Hotovo k výdeji': '✅ Bereit zur Ausgabe',

    # Stations
    '🔥 Stanice': '🔥 Stationen',
    '+ Nová stanice': '+ Neue Station',
    'Nová stanice': 'Neue Station',
    'Stanice uložena': 'Station gespeichert',
    'Stanice smazána': 'Station gelöscht',
    'Smazat stanici?': 'Station löschen?',
    'Položky ve frontě této stanice ztratí přiřazení.': 'Posten in der Warteschlange dieser Station verlieren die Zuordnung.',
    'paralelně': 'parallel',
    'Max. paralelně': 'Max. parallel',
    '📋 Fronta výroby': '📋 Produktionswarteschlange',
    '😴 Klid v kuchyni — žádné položky ve frontě.': '😴 Ruhe in der Küche — keine Posten in der Warteschlange.',
    '▶ Čeká': '▶ Wartet', '🔨 Připravuje': '🔨 Zubereitung',
    '✓ Hotovo': '✓ Fertig', '▶ Začít': '▶ Beginnen',
    '📤 Vydáno': '📤 Ausgegeben',
    'v queue': 'in Warteschlange', 'připravuje se': 'wird zubereitet',
    'Max. paralelních objednávek': 'Max. parallele Bestellungen',
    'Max. minut přípravy SLA': 'Max. Zubereitungszeit SLA (Min.)',
    'Velikost slotu (min)': 'Slotgröße (Min.)',
    'Otevřeno od': 'Geöffnet von', 'Otevřeno do': 'Geöffnet bis',
    'Auto-blokovat nové objednávky když je plno': 'Neue Bestellungen automatisch blockieren wenn voll',
    '💾 Uložit nastavení': '💾 Einstellungen speichern',
    's nastavenou dobou': 'mit eingestellter Dauer',
    'průměr': 'Durchschnitt',
    'Doba (min)': 'Dauer (Min.)',
    'Stanice kuchyně': 'Küchenstationen',
    '— Žádná —': '— Keine —',
    'změn': 'Änderungen',
    '💡 Jak se počítá doba pro objednávku': '💡 Wie wird die Bestelldauer berechnet',

    # Couriers
    'aktivních kurýrů': 'aktive Kuriere',
    'probíhajících': 'laufend',
    'dnes doručeno': 'heute geliefert',
    '+ Nový kurýr': '+ Neuer Kurier',
    'Nový kurýr': 'Neuer Kurier',
    'Kurýr přidán': 'Kurier hinzugefügt',
    'Kurýr upraven': 'Kurier bearbeitet',
    'Kurýr smazán': 'Kurier gelöscht',
    'Smazat kurýra?': 'Kurier löschen?',
    '🛵 Aktuálně na cestě': '🛵 Aktuell unterwegs',
    '👥 Kurýři / Řidiči': '👥 Kuriere / Fahrer',
    '📋 Naplánováno': '📋 Geplant',
    '📦 Vyzvednuto': '📦 Abgeholt',
    '🛵 Na cestě': '🛵 Unterwegs',
    '✅ Doručeno': '✅ Geliefert',
    '📦 Vyzvedl': '📦 Abgeholt',
    'Plánováno:': 'Geplant:',
    '🔌 Integrace s externími službami': '🔌 Integration mit externen Diensten',
    '✓ Aktivní': '✓ Aktiv',
    'Vypnuto': 'Deaktiviert',
    'Provize:': 'Provision:',
    'Store:': 'Store:',
    '📞 Telefon': '📞 Telefon',
    '📧 E-mail': '📧 E-Mail',
    '🚗 Vozidlo': '🚗 Fahrzeug',
    'SPZ': 'Kennzeichen',
    '📍 Obsluhovaná zóna': '📍 Betreutes Gebiet',
    '💰 Provize (%)': '💰 Provision (%)',
    '🏠 Vlastní řidič': '🏠 Eigener Fahrer',
    '🔌 Externí služba': '🔌 Externer Dienst',
    'Externí služba': 'Externer Dienst',
    'Aktivní (dostupný pro nové rozvozy)': 'Aktiv (verfügbar für neue Lieferungen)',
    '🔌 Integrace:': '🔌 Integration:',
    'Wolt': 'Wolt', 'Bolt Food': 'Bolt Food', 'Dáme jídlo': 'Dáme jídlo',
    'Uber Eats': 'Uber Eats', 'Foodora': 'Foodora',

    # Order details
    'Položky objednávky': 'Bestellposten',
    'Souhrn objednávky': 'Bestellzusammenfassung',
    'Detaily objednávky': 'Bestelldetails',
    'Historie objednávek': 'Bestellverlauf',
    'Historie změn': 'Änderungsverlauf',

    # Customer
    'Údaje o zákazníkovi': 'Kundendaten',
    'Kontaktní údaje': 'Kontaktdaten',
    'Fakturační údaje': 'Rechnungsdaten',
    'Dodací adresa': 'Lieferadresse',
    'Fakturační adresa': 'Rechnungsadresse',
    'Stejná jako fakturační': 'Wie Rechnungsadresse',

    # Products
    'Detaily výrobku': 'Produktdetails',
    'Specifikace výrobku': 'Produktspezifikation',
    'Skladová karta': 'Lagerkarte',
    'Cena za kus': 'Stückpreis',
    'Cena za kg': 'Preis pro kg',

    # Validation
    'Vyplňte všechna povinná pole': 'Alle Pflichtfelder ausfüllen',
    'Neplatná hodnota': 'Ungültiger Wert',
    'Hodnota mimo rozsah': 'Wert außerhalb des Bereichs',
    'Pole je příliš dlouhé': 'Feld ist zu lang',
    'Pole je příliš krátké': 'Feld ist zu kurz',
    'Datum v minulosti': 'Datum in der Vergangenheit',
    'Datum v budoucnosti': 'Datum in der Zukunft',
    'Neplatné datum': 'Ungültiges Datum',

    # Workflow ext
    'připraveno': 'fertig', 'rozpracováno': 'in Bearbeitung',
    'dokončeno': 'abgeschlossen', 'zrušeno': 'storniert',
    'odložené': 'aufgeschoben', 'naplánováno': 'geplant',

    # Frequent
    'Detail uživatele': 'Benutzerdetails',
    'Detail zákazníka': 'Kundendetails',
    'Detail výrobku': 'Produktdetails',
    'Detail dodávky': 'Lieferdetails',
    'Nová položka': 'Neuer Posten',
    'Upravit položku': 'Posten bearbeiten',
    'Smazat položku': 'Posten löschen',
    'Smazat položky?': 'Posten löschen?',
    'Duplikovat položku': 'Posten duplizieren',
    'Vytvořit kopii': 'Kopie erstellen',

    # Verbs
    'přidat': 'hinzufügen', 'odebrat': 'entfernen',
    'zvýšit': 'erhöhen', 'snížit': 'verringern',
    'plus': 'plus', 'minus': 'minus',

    # UI common
    'Po kliknutí': 'Nach dem Klick',
    'Po stisknutí': 'Nach dem Drücken',
    'Po vybrání': 'Nach der Auswahl',
    'Po uložení': 'Nach dem Speichern',
    'Po smazání': 'Nach dem Löschen',

    # Misc
    'Skutečně chcete tuto akci provést?': 'Möchten Sie diese Aktion wirklich durchführen?',
    'Akce byla úspěšně dokončena': 'Aktion erfolgreich abgeschlossen',
    'Akce selhala': 'Aktion fehlgeschlagen',
    'Akce přerušena': 'Aktion abgebrochen',

    # POS
    'Otevřít účet': 'Rechnung öffnen',
    'Zavřít účet': 'Rechnung schließen',
    'Vystavit účet': 'Rechnung ausstellen',
    'Vyúčtovat': 'Abrechnen',
    'Placeno': 'Bezahlt',
    'Otevřeno': 'Offen',
    'Zaplacené': 'Bezahlt',
    'Nezaplacené': 'Unbezahlt',

    # KDS
    'Kuchyňský display': 'Küchen-Display',
    'KDS': 'KDS',
    'Aktivní položky': 'Aktive Posten',
    'Hotové položky': 'Fertige Posten',
    'Vrátit položku': 'Posten zurückgeben',
    'Zrušit položku': 'Posten stornieren',
    'Označit jako hotové': 'Als fertig markieren',
    'Označit jako vydané': 'Als ausgegeben markieren',
    'Čas přípravy': 'Zubereitungszeit',

    # Branding ext
    'Logo': 'Logo', 'Nahrát logo': 'Logo hochladen',
    'Smazat logo': 'Logo löschen',
    'Doporučená velikost': 'Empfohlene Größe',
    'Max. velikost': 'Max. Größe',
    'Podporované formáty': 'Unterstützte Formate',

    # Numbers
    'Tisíc': 'Tausend', 'Milion': 'Million', 'Miliarda': 'Milliarde',
    'jen': 'nur', 'pouze': 'nur',
    'kromě': 'außer',

    # Connection status
    'Připojeno': 'Verbunden', 'Odpojeno': 'Getrennt',
    'Načteno': 'Geladen', 'Odesláno': 'Gesendet',
    'Přijato': 'Empfangen', 'Spuštěno': 'Gestartet',
    'Zastaveno': 'Gestoppt',
}


# ─────────────────────────────────────────────────────────────────────
# MAIN — read CS phrases, translate, write i18n_extra.js
# ─────────────────────────────────────────────────────────────────────

def read_cs_phrases():
    """Extracts unique CS phrases from i18n_auto.js."""
    content = SRC.read_text()
    phrases = []
    seen = set()
    # Match leading: ['CS_TEXT', ...
    # CS string is single-quoted; may contain escaped \'
    for m in re.finditer(r"^\s*\['((?:[^'\\]|\\.)+)',", content, re.MULTILINE):
        phrase = m.group(1).replace("\\'", "'").replace('\\"', '"').replace('\\\\', '\\')
        if phrase not in seen:
            seen.add(phrase)
            phrases.append(phrase)
    return phrases

def js_escape(s):
    """Escape string for safe JS single-quoted output."""
    return s.replace('\\', '\\\\').replace("'", "\\'").replace('\n', '\\n').replace('\r', '\\r')

def main():
    # Merge extra dicts into main dicts
    if HAS_EXTRA:
        SK_DICT.update(SK_EXTRA)
        DE_DICT.update(DE_EXTRA)
        print(f"📦 Loaded {len(SK_EXTRA)} SK + {len(DE_EXTRA)} DE extras from i18n_dicts_extra.py")

    phrases = read_cs_phrases()
    print(f"📖 Read {len(phrases)} unique CS phrases")

    sk_translations = {}
    de_translations = {}
    sk_dict_hit = sk_rule_hit = sk_skipped = 0
    de_dict_hit = de_skipped = 0

    for cs in phrases:
        # SK — dict + safe rules
        sk = translate_sk(cs)
        if sk is not None:
            sk_translations[cs] = sk
            if cs in SK_DICT:
                sk_dict_hit += 1
            else:
                sk_rule_hit += 1
        else:
            sk_skipped += 1

        # DE — dict only
        if cs in DE_DICT:
            de_translations[cs] = DE_DICT[cs]
            de_dict_hit += 1
        else:
            de_skipped += 1

    print(f"🇸🇰 SK: {len(sk_translations)} translations (dict={sk_dict_hit}, rules={sk_rule_hit}, skipped={sk_skipped})")
    print(f"🇩🇪 DE: {len(de_translations)} translations (dict={de_dict_hit}, skipped={de_skipped})")

    # Write i18n_extra.js
    lines = [
        "// =============================================================",
        "// 🌍 i18n EXTRA — overlay pro další jazyky (SK, DE, PL, …)",
        "//",
        "// AUTO-GENERATED by scripts/gen_i18n_extra.py — neměň ručně.",
        "// Pro úpravy: edituj SK_DICT / DE_DICT v generátoru a spusť ho znovu.",
        "// =============================================================",
        "",
        "const I18N_EXTRA = {",
    ]

    # SK section
    lines.append("  // 🇸🇰 Slovenčina — " + str(len(sk_translations)) + " frází")
    lines.append("  sk: {")
    for cs, sk in sorted(sk_translations.items()):
        lines.append(f"    '{js_escape(cs)}': '{js_escape(sk)}',")
    lines.append("  },")

    # DE section
    lines.append("")
    lines.append("  // 🇩🇪 Deutsch — " + str(len(de_translations)) + " frází")
    lines.append("  de: {")
    for cs, de in sorted(de_translations.items()):
        lines.append(f"    '{js_escape(cs)}': '{js_escape(de)}',")
    lines.append("  },")

    lines.append("};")
    lines.append("")
    lines.append("// MERGE do existujícího I18N_LOOKUP (z i18n_auto.js)")
    lines.append("(function mergeIntoLookup() {")
    lines.append("  if (typeof I18N_LOOKUP === 'undefined' || !I18N_LOOKUP) {")
    lines.append("    console.warn('[i18n_extra] I18N_LOOKUP nenalezeno');")
    lines.append("    return;")
    lines.append("  }")
    lines.append("  let merged = 0;")
    lines.append("  for (const [lang, dict] of Object.entries(I18N_EXTRA)) {")
    lines.append("    for (const [cs, translation] of Object.entries(dict)) {")
    lines.append("      const existing = I18N_LOOKUP.get(cs) || {};")
    lines.append("      existing[lang] = translation;")
    lines.append("      I18N_LOOKUP.set(cs, existing);")
    lines.append("      merged++;")
    lines.append("    }")
    lines.append("  }")
    lines.append("  console.log('[i18n_extra] ✅ Loaded ' + merged + ' phrases in ' + Object.keys(I18N_EXTRA).length + ' languages (' + Object.keys(I18N_EXTRA).join(', ') + ')');")
    lines.append("})();")
    lines.append("")
    lines.append("window.APPEK_EXTRA_LANGS = {")
    lines.append("  sk: { flag: '🇸🇰', label: 'Slovenčina', native: 'Slovenčina' },")
    lines.append("  de: { flag: '🇩🇪', label: 'Deutsch',    native: 'Deutsch' },")
    lines.append("};")

    DST.write_text("\n".join(lines) + "\n")
    print(f"✅ Wrote {DST}")
    print(f"📦 File size: {DST.stat().st_size:,} bytes")

if __name__ == '__main__':
    main()
