# 🖥️ Setup pro iMac (multi-device workflow)

Tento návod popisuje jak nakonfigurovat **iMac** pro práci na APPEK B2B
projektu, aniž bys přišel o data nebo si rozbil git.

## ❌ Co NEDĚLAT

**NIKDY** neměj git working dir uvnitř `iCloud Drive` (`~/Library/Mobile Documents/com~apple~CloudDocs/…`):

- iCloud sleduje soubory **jednotlivě** a syncuje je asynchronně
- git očekává **atomické** operace nad `.git/objects/`, `.git/refs/`, `.git/HEAD`
- Při souběhu (commit + iCloud upload) může dojít k:
  - Korupci `.git/index` → `fatal: index file corrupt`
  - Konfliktům v `packed-refs` → `error: refs/remotes/origin/main: invalid sha1`
  - Ztrátě commitů (iCloud "Optimize Storage" smaže lokální blob)
- Multi-device přes iCloud + git = **garantované problémy** dříve či později

## ✅ Doporučená architektura

```
┌──────────────────────────────────────────────────────────────────┐
│  GitHub (github.com/Chossekilla/appek) — single source of truth  │
└────────────┬─────────────────────────────────────┬───────────────┘
             │ push/pull                            │ push/pull
             ▼                                      ▼
   ┌─────────────────────┐              ┌──────────────────────┐
   │  MacBook            │              │  iMac                │
   │  ~/projects/appek.cz│              │  ~/projects/appek.cz │
   │  (lokální dev)      │              │  (lokální dev)       │
   └─────────────────────┘              └──────────────────────┘
```

**iCloud složku** `~/Library/Mobile Documents/com~apple~CloudDocs/appek.cz`
už **nepoužívej** — můžeš ji smazat nebo archivovat (přesun do `~/Documents/_archiv/`).

## 🚀 Setup iMac přes GitHub Desktop (5 minut)

### 1. Nainstaluj GitHub Desktop

Pokud ještě nemáš: <https://desktop.github.com/>

### 2. Přihlas se k GitHubu

GitHub Desktop → **File → Options → Accounts → Sign in to GitHub.com**

(Použij stejný účet `Chossekilla` jako máš MacBooku.)

### 3. Clone repo do `~/projects/`

GitHub Desktop → **File → Clone repository**

- **URL tab** → vlož: `https://github.com/Chossekilla/appek.git`
- **Local path**: `/Users/chossekilaimac/projects/appek.cz`
  (NE iCloud! Pokud `~/projects/` neexistuje, GitHub Desktop ji vytvoří.)
- Klikni **Clone**

### 4. (Volitelné) Smaž starou iCloud kopii

V Finderu:
```
~/Library/Mobile Documents/com~apple~CloudDocs/appek.cz
```
Buď smaž (Cmd+Delete), nebo přesuň do `~/Documents/_archiv/appek.cz-icloud-2026-05-24`.

### 5. (Pokud chceš lokální XAMPP/MAMP testing)

Nepoužívej symlink, ale **rsync helper** (viz `scripts/sync-local.sh`).

## 🔄 Denní workflow (multi-device)

### Před začátkem práce (vždy!)
```bash
cd ~/projects/appek.cz
git pull origin main
```
Nebo v GitHub Desktop: klikni **Fetch origin** → **Pull**.

### Po dokončení práce (vždy!)
```bash
git add .
git commit -m "popis změn"
git push origin main
```
Nebo v GitHub Desktop: **Commit to main** → **Push origin**.

### Nikdy nepracuj na obou strojích současně bez commit+push

Pokud zapomeneš pull před prací → vznikne **merge conflict** při push.
Řešení: `git pull --rebase` (advanced) nebo požádej Claude/copilot o pomoc.

## 🆘 Co dělat když...

### "fatal: refusing to merge unrelated histories"
Ozvi se mi — pravděpodobně máš v iCloud složce starou kopii s vlastní
git historií, která koliduje s GitHub remote.

### "Your local changes would be overwritten by merge"
Máš nezacommitované změny. Buď je commitni, nebo `git stash` (uložení do
"odkladiště"), `git pull`, `git stash pop` (vytažení zpět).

### Soubory v iCloud neukládají
iCloud složku **nepoužívej pro git!** Přesuň do `~/projects/`.

### XAMPP/MAMP nevidí nejnovější změny
Použij `scripts/sync-local.sh` (rsync helper) nebo nakonfiguruj XAMPP
DocumentRoot na `~/projects/appek.cz`.

## 📝 Aktuální stav

- **MacBook**: `/Users/chossekilaimac/projects/appek.cz` — aktivní
- **iMac**: potřeba clone přes GitHub Desktop (viz krok 3 výše)
- **GitHub**: <https://github.com/Chossekilla/appek> — single source of truth
- **iCloud složka** `~/Library/Mobile Documents/.../appek.cz`: **NEPOUŽÍVAT**

Aktuální verze: viz `api/config.php` → `APP_VERSION`.
