# APPEK B2B — Provozní příručka

**Pro koho:** majitel / správce instalace APPEK. Doplňuje [INSTALL.md](../INSTALL.md) (jednorázová instalace) o **každodenní provoz**: aktualizace, zálohy, řešení potíží, FAQ.

| | |
|---|---|
| **Audience** | Provozovatel instalace |
| **Předpoklad** | APPEK už nainstalován (viz INSTALL.md) |

> Aktuální verzi své instalace zjistíte kdykoli na `https://vaše-doména/api/version.php`.

---

## 1. Aktualizace aplikace

**Jak to funguje.** Aktualizace běží přímo z aplikace: **Nastavení → Údržba → Licence & aktualizace → Zkontrolovat aktualizace**. APPEK stáhne nový balíček z výrobce a nasadí ho sám.

**Je to bezpečné?** Ano:
- Před nasazením se **automaticky vytvoří záloha** nahrazovaných souborů (`api/zalohy/update-backup-RRRRMMDD…`).
- Když se nasazení nepovede u kritického souboru, spustí se **automatický rollback** ze zálohy (instalace se vrátí do funkčního stavu).
- Drží se **jen poslední** záloha aktualizace (aby nepřetekla disková/inode kvóta hostingu).

**Ověření po aktualizaci:**
1. Otevřete `https://vaše-doména/api/version.php` → musí ukazovat novou verzi (tato adresa se necachuje).
2. Pokud `version.php` ukazuje novou verzi, ale rozhraní ne → **tvrdý reload** (Ctrl/Cmd + Shift + R). Statické soubory mohou být chvíli v CDN cache; po pár minutách se srovná.

**Doporučení:** aktualizujte průběžně — updaty obsahují opravy i nové funkce. Před velkou aktualizací si stáhněte zálohu DB (viz dále).

---

## 2. Zálohy a obnova

> ⚠️ **Vaše data jsou jen u vás** — ve vaší databázi a ve složce `uploads/` na vašem hostingu. Nikdo jiný k nim nemá přístup, takže **zálohy jsou vaše odpovědnost.**

**Co APPEK zálohuje automaticky:**
- soubory před každou aktualizací (`api/zalohy/`),
- stav před rizikovými akcemi v adminu (např. před dobropisem, přepočtem měny, mazáním).

**Ruční záloha databáze:** Nastavení → Údržba → **Zálohy databáze**. Doporučujeme stáhnout zálohu:
- pravidelně (např. týdně),
- vždy před větší změnou (přepočet měny, hromadné úpravy, aktualizace).

**Doporučená druhá vrstva (mimo aplikaci):**
- pravidelný **export databáze** přes hosting (phpMyAdmin / panel hostingu),
- záloha složky **`uploads/`** (loga, fotky předloh, štítky).

**Obnova:**
- Databáze: import zálohy (`.sql`) v phpMyAdmin.
- Soubory: nahrání z `api/zalohy/…` přes FTP zpět do kořene.

---

## 3. Běžné problémy a řešení

| Příznak | Pravděpodobná příčina | Řešení |
|---|---|---|
| Aplikace hlásí instalaci / chce DB údaje | chybí `api/config.local.php` (smazán/přepsán na hostingu) | obnovte soubor ze zálohy/FTP, nebo znovu projděte instalátor (data v DB zůstávají) |
| Aktualizace „se neprojevila" | cache prohlížeče/CDN | ověřte `…/api/version.php`; pokud je tam nová verze → tvrdý reload (Ctrl/Cmd+Shift+R), případně počkejte pár minut |
| Bílá stránka / chyba 500 | oprávnění souborů, verze PHP, chyba v logu | PHP ≥ 8.0; složky `755`, soubory `644`; mrkněte do **error logu** hostingu (panel → Logy) |
| Aplikace je pomalá | dočasná zátěž hostingu | změřte **jeden** požadavek (ne dávku); pokud přetrvává, kontaktujte hosting / podporu |
| E-maily nechodí | špatné SMTP | Nastavení → SMTP → vyplňte server/port/heslo → tlačítko **Test** |
| Hosting hlásí „nedostatek místa / inode" | nahromaděné dočasné soubory | smažte dočasné soubory; APPEK sám drží jen poslední zálohu aktualizace |
| Placené balíčky přestaly fungovat | výpadek spojení s licenčním serverem nebo vypršení licence | ověřte připojení k internetu; po vypršení **jádro funguje dál**, placené balíčky se po ochranné lhůtě vypnou, obnovením licence se vrátí |

---

## 4. Přesun na jiný hosting / změna domény

1. Export databáze (phpMyAdmin) na starém hostingu.
2. Zkopírujte **všechny soubory** včetně `api/config.local.php` a složky `uploads/`.
3. Na novém hostingu: import databáze + úprava `api/config.local.php` (nové DB údaje, příp. nová doména).
4. Ověřte `…/api/version.php` a přihlášení do adminu.

---

## 5. FAQ

**Je automatická aktualizace bezpečná?** Ano — vytvoří zálohu a při chybě se sama vrátí zpět. Přesto doporučujeme mít i vlastní zálohu DB.

**Jak často aktualizovat?** Klidně průběžně; updaty obsahují opravy i funkce.

**Kde jsou moje data?** Pouze ve vaší databázi a složce `uploads/` na vašem hostingu.

**Co když vyprší licence?** Jádro běží dál; placené balíčky se po ochranné lhůtě (cca 14 dní) vypnou. Obnovením licence se vše vrátí.

**HTTPS?** Zapněte SSL certifikát na hostingu a provozujte APPEK pouze přes `https://`.

**GDPR / osobní údaje?** Data máte u sebe; za jejich zpracování (retence, výmaz, souhlasy, zpracovatelská smlouva) odpovídá provozovatel — doporučujeme konzultaci s odborníkem.

---

## 6. Podpora

Při hlášení problému přiložte:
- **verzi** (`…/api/version.php`),
- popis, co jste dělali a co se stalo,
- screenshot,
- výňatek z error logu hostingu (pokud je).

> Kontakt na podporu: _doplní dodavatel (e-mail / telefon / SLA)._
