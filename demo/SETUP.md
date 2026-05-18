# 🧪 DEMO subdomain — proper setup

Cíl: `demo.appek.cz` má **plnohodnotný admin (1:1 s reálným)** s demo daty, 15-min session timeout a hodinový reset.

---

## 1️⃣ Hostinger / Apache setup

V Hostinger panelu vytvoř subdoménu:

```
Subdomain:    demo
Domain:       appek.cz
Public path:  public_html/demo
```

Nahraj customer ZIP (`appek-v2.0.X.zip`) jako customer:
- Rozbal do `public_html/demo/`
- Spusť `demo.appek.cz/install.php`
- Vytvoř vlastní DB `appek_demo` (oddělená od master)
- Vytvoř admin účet (např. `demo@appek.cz` / `demo1234`)
- Po instalaci **smaž** `demo/install.php`

Demo subdoména už má speciální `.htaccess` (přibalena v demo/) s:
- 15-min idle session timeout (`session.gc_maxlifetime=900`)
- Force HTTPS
- Noindex pro Google
- Bezpečnostní headery

---

## 2️⃣ Seed dat — naplň demo realistickými daty

Po prvotní instalaci spusť SQL seed:

```bash
# Na hostingu
mysql -u user -p appek_demo < deploy/demo-seed.sql
```

Nebo přes phpMyAdmin: Importovat → vybrat `deploy/demo-seed.sql`.

Seed obsahuje:
- 50 produktů (pekařské, cukrářské, nápoje)
- 12 odběratelů (kavárny, restaurace, hotely, jídelny)
- 30 historických objednávek (různé stavy)
- 15 faktur
- HACCP záznamy
- 5 šarží surovin
- 8 cenovek
- 3 demo admin uživatelé (různé role)

---

## 3️⃣ Hodinový reset (cron)

V Hostinger panelu → Cron jobs:

```
Příkaz:   /usr/bin/php /home/USER/public_html/demo/api/admin_reset_demo.php
Frekvence: 0 * * * *  (každou hodinu v xx:00)
```

Reset skript:
1. TRUNCATE všech transakčních tabulek (objednávky, faktury, …)
2. Reseed z `deploy/demo-seed.sql`
3. Reset admin hesla zpět na `demo1234`
4. Logne reset do `demo_pristupy.akce = 'auto_reset'`

---

## 4️⃣ Auto-login (zákazník nemusí nic řešit)

Demo landing page (`demo/index.html`) má tlačítko **„Vstoupit do demo"**.
Klik vede na `demo.appek.cz/admin/` s auto-login query parametrem.

Pro auto-login je potřeba v `demo/admin/` aplikaci aktivovat speciální flag:
1. V `api/config.local.php` přidej: `define('APPEK_DEMO_MODE', true);`
2. To aktivuje v `/admin/index.html` auto-fill credentials z URL
3. Session má TTL 15 minut

---

## 5️⃣ Testing checklist

- [ ] `https://demo.appek.cz/` zobrazí landing page (demo/index.html)
- [ ] Klik na „Vstoupit" → `demo.appek.cz/admin/` → auto-přihlášení
- [ ] V adminu vidíš ~30 objednávek, ~50 produktů, ~12 odběratelů
- [ ] Po 15 min idle jsi automaticky odhlášený
- [ ] Po reload F5 vidíš stejná data (mezi hodinou)
- [ ] V xx:00 (každou hodinu) se data resetují
- [ ] Tracking funguje (`vendor.appek.cz/demo-log.php` ukazuje přístupy)

---

## 🔒 Bezpečnost demo

- Demo DB je **separátní** od produkční (vlastní `appek_demo` databáze)
- Demo nemá přístup k vendor/ ani sales/
- Demo nemá platební údaje, pouze fake data
- Admin login je veřejný (záměrně) — nemůže nic poškodit
- Auto-reset každou hodinu garantuje obnovu po útoku nebo nepořádku
- Rate limit na `api/demo_track.php` (30 req / 5 min / IP)

---

## 📊 Monitoring

Ve vendor panelu `vendor.appek.cz/demo-log.php`:
- Počet přístupů (dnes / 7d / 30d / vše)
- Unikátní IP
- Jazyky návštěvníků
- Reset CSV export

Tato data se trackují přes `api/demo_track.php` na master serveru (ne v demo DB).
