# Dokumentační index — APPEK B2B

**Vstupní bod pro veškerou oficiální dokumentaci.**

| | |
|---|---|
| **Verze** | 2.0.4 |
| **Aktualizováno** | 2026-05-17 |
| **Web** | [appek.cz](https://appek.cz) |

---

## Rychlá orientace podle role

### Jsem zákazník — chci nainstalovat APPEK

1. [INSTALL.md](../INSTALL.md) — Instalační průvodce krok za krokem
2. [HOSTING_SETUP.md](../deploy/docs/HOSTING_SETUP.md) — Nasazení na produkční hosting
3. [SECURITY.md](../deploy/docs/SECURITY.md) — Bezpečnostní doporučení

### Jsem provozovatel platformy

1. [vendor/README.md](../vendor/README.md) — Master Control Panel
2. [SECURITY.md](../deploy/docs/SECURITY.md) — Bezpečnostní průvodce
3. [CHANGELOG.md](../CHANGELOG.md) — Historie verzí

### Jsem vývojář

1. [README.md](../README.md) — Přehled produktu a architektury
2. [SYNC_ARCHITECTURE.md](../SYNC_ARCHITECTURE.md) — Hybrid synchronizace
3. [CHANGELOG.md](../CHANGELOG.md) — Verzování a upgrade cesta

### Mám právní/obchodní otázku

1. [LICENSE.md](../LICENSE.md) — Licenční smlouva (EULA)
2. [SECURITY.md](../deploy/docs/SECURITY.md) — GDPR compliance

---

## Kompletní seznam dokumentů

### Hlavní dokumenty (root)

| Dokument | Cílová skupina | Popis |
|----------|---------------|-------|
| [README.md](../README.md) | Všichni | Přehled produktu, funkcí a architektury |
| [INSTALL.md](../INSTALL.md) | Zákazníci | Instalace krok za krokem (lokálně i cloud) |
| [CHANGELOG.md](../CHANGELOG.md) | Všichni | Historie verzí, plánované funkce |
| [LICENSE.md](../LICENSE.md) | Zákazníci | Licenční smlouva (EULA) |
| [SYNC_ARCHITECTURE.md](../SYNC_ARCHITECTURE.md) | Vývojáři | Hybrid synchronizace local + cloud |

### Deployment a operace

| Dokument | Cílová skupina | Popis |
|----------|---------------|-------|
| [HOSTING_SETUP.md](../deploy/docs/HOSTING_SETUP.md) | Admin systému | Detailní průvodce nasazením |
| [SECURITY.md](../deploy/docs/SECURITY.md) | Admin systému | Bezpečnostní průvodce a checklist |

### Moduly

| Dokument | Cílová skupina | Popis |
|----------|---------------|-------|
| [vendor/README.md](../vendor/README.md) | Provozovatel platformy | Master Control Panel |

---

## Konvence dokumentace

Veškerá oficiální dokumentace dodržuje **unifikovaný profi styl**:

### Struktura dokumentu

```markdown
# Název dokumentu

**Krátká podtituľka.**

| Metadata | Hodnota |
|---|---|
| Verze | X.Y.Z |
| Aktualizováno | YYYY-MM-DD |
| Audience | [Pro koho je dokument] |

---

## Obsah

1. [Sekce 1](#sekce-1)
...

---

## Sekce 1

Obsah...

---

## Související dokumenty

- [link](path) — popis

---

**Kontakt:** ...
**Aktualizováno:** YYYY-MM-DD
```

### Styling konvence

- **H1** pouze jednou (titulek)
- **H2** pro hlavní sekce
- **H3** pro podsekce
- **H4** pro detaily

### Callouts

> **Note:** Informativní poznámka.

> **Tip:** Užitečný tip nebo best practice.

> **Warning:** Upozornění na riziko nebo důležitý postup.

### Tabulky

Pro srovnání, options, parametry. Vždy s headerem.

### Code bloky

Vždy s identifikátorem jazyka:

````markdown
```php
echo "Hello";
```
````

### Emoji

Minimální použití — jen v case-by-case scenarech (status, priority). Nikdy v běžném textu.

### Jazyk

- **Primárně čeština** (cílový trh)
- Technické termíny zachovány v angličtině (např. "rate limiting", "SSL certifikát")
- Anglické překlady dostupné v `*_EN.md` pro mezinárodní zákazníky (TBD)

---

## Životní cyklus dokumentů

### Vytvoření

Nový dokument:

1. Použijte šablonu výše
2. Přidejte do tohoto indexu (`docs/README.md`)
3. Linkujte ze souvisejících dokumentů

### Aktualizace

Při změně dokumentu:

1. Aktualizujte `Verze` v hlavičce
2. Aktualizujte `Aktualizováno` na dnešní datum
3. V CHANGELOG.md zaznamenejte změnu pod relevantní release

### Verzování

Dokumentace sleduje verzi softwaru:

- **MAJOR** změny (2.x → 3.0) — komplexní revize všech dokumentů
- **MINOR** změny (2.0 → 2.1) — aktualizace funkcí, nové sekce
- **PATCH** změny (2.0.1 → 2.0.2) — drobné opravy, typo fixes

### Archivace

Stará verze dokumentu:

- Před přepisem zkopírujte do `docs/archive/X.Y.Z/`
- V CHANGELOG.md zaznamenejte breaking changes

---

## Generování PDF

Pro tisk nebo distribuci v PDF formátu:

```bash
# Pandoc — všechny dokumenty do PDF
cd /Users/chossekilaimac/projects/appek.cz
pandoc README.md INSTALL.md SECURITY.md CHANGELOG.md \
    -o APPEK-Documentation.pdf \
    --pdf-engine=xelatex \
    -V geometry:margin=2cm \
    -V mainfont="Inter" \
    -V monofont="Fira Code"

# Jednotlivý dokument
pandoc INSTALL.md -o INSTALL.pdf --pdf-engine=xelatex
```

> **Tip:** Pro nejlepší výsledek používejte XeLaTeX engine s podporou Unicode (české znaky).

---

## Překlady

Dokumentace je aktuálně dostupná v češtině. Anglické a španělské překlady jsou plánovány v rámci verze 2.1.0.

**Plánované překlady:**

| Soubor | EN | ES |
|--------|----|----|
| README.md | Q3 2026 | Q4 2026 |
| INSTALL.md | Q3 2026 | Q4 2026 |
| LICENSE.md | Q3 2026 | Q4 2026 |
| SECURITY.md | Q4 2026 | Q1 2027 |

> **Note:** UI aplikace je již plně přeložena (43 000+ klíčů). Překládáme nyní samotnou dokumentaci.

---

## Hlášení chyb v dokumentaci

Pokud najdete chybu, nejasnost nebo chybějící informaci:

- **E-mail:** docs@appek.cz
- **GitHub issue:** [github.com/appek/docs/issues](https://github.com/appek/docs/issues) (pokud máte přístup)

Děkujeme za zpětnou vazbu — dokumentace je živý dokument, který společně zlepšujeme.

---

## Kontakty

| Účel | Kontakt |
|------|---------|
| **Obecná podpora** | support@appek.cz |
| **Dokumentace** | docs@appek.cz |
| **Bezpečnost** | security@appek.cz |
| **Právní záležitosti** | legal@appek.cz |
| **Marketing** | marketing@appek.cz |

---

**Aktualizováno:** 2026-05-17
**Verze indexu:** 2.0.4
