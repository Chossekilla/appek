# Fáze 1 — Deploy pipeline — ruční kroky pro majitele

Kód Fáze 1 je hotový. Těchto 6 kroků musíš udělat ty — jde o přístupy
a tajné klíče, které Claude záměrně nezná a nezadává.

Pořadí: nejdřív se větev `f1-deploy-pipeline` zmerguje do `main` (to udělá
Claude), pak provedeš kroky níže.

---

## 1. Vygeneruj deploy token

Na svém Macu spusť:

```
openssl rand -hex 32
```

Dostaneš 64znakový řetězec. **Je to tajný klíč — zacházej s ním jako s heslem,
nikam ho nelep do gitu.** Stejnou hodnotu vložíš na dvě místa (kroky 2 a 3).

## 2. Token na server

Na serveru otevři `vendor/config.local.php` (přes File Manager hostingu nebo
FTP) a přidej řádek:

```php
define('DEPLOY_TOKEN', 'sem-vlož-token-z-kroku-1');
```

## 3. GitHub Secrets

`github.com/Chossekilla/appek` → Settings → Secrets and variables → Actions →
**New repository secret**. Přidej dva:

| Název | Hodnota |
|-------|---------|
| `DEPLOY_TOKEN` | stejná hodnota jako v kroku 2 |
| `DEPLOY_URL` | základní URL vendoru BEZ koncového lomítka, např. `https://vendor.appek.cz` (dokud běží dočasná doména, použij ji — viz `APP_URL` v `api/config.php`) |

## 4. MacBook (jednorázově)

Aby ses dostal k projektu i z MacBooku:

```
git clone https://github.com/Chossekilla/appek.git
```

Pak v `api/config.local.php` nastav lokální XAMPP přístupy pro testování —
soubor je gitignored, na každém stroji má vlastní.

## 5. První ostrý release

Až jsou kroky 1–4 hotové, spusť na Macu (na větvi `main`, čistý strom):

```
./scripts/release.sh <verze>
```

Sleduj `github.com/Chossekilla/appek/actions` — workflow musí:
1. postavit MASTER i customer ZIP,
2. vytvořit GitHub Release,
3. krok **„Deploy to server"** skončit zeleně se `"status":"ok"`.

## 6. Ověř nasazení

Otevři `vendor.appek.cz`, `appek.cz` a `demo.appek.cz` — musí hlásit novou
verzi. Když je CI job červený, GitHub ti pošle e-mail a server se měl
auto-rollbacknout do předchozího funkčního stavu.

---

**Tip:** první release klidně udělej s malým, neškodným změnou (např. úprava
textu), ať si ověříš, že celá pipeline projde end-to-end, než na ni pustíš
něco důležitého.
