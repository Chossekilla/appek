# 🍕 APPEK Restaurace — logika & flow

> Kompletní průvodce jak v APPEK funguje restaurační provoz od QR / číšníka po výdej a platbu.
>
> Verze: **v3.0.22** · datum: 2026-05-25

---

## 📦 4 vrstvy systému

```
┌─────────────────────────────────────────────────────────────┐
│  1. NASTAVENÍ                                                │
│     Floor plan (zóny + stoly) + Tiskárny + Kategorie         │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│  2. OBJEDNÁVKA — 3 vstupní body                              │
│     a) Číšník (POS Stoly → klik → přidá položku)             │
│     b) Host (QR sken → /qr/?t=token → odešle → schválit)     │
│     c) Walk-in (POS Produkty → cart → FINISH = sebou)        │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│  3. KUCHYNĚ                                                  │
│     KDS (Kitchen Display) → vaří → hotovo                    │
│     Výdej (Pass) → odnese ke stolu → servírováno             │
│     Tiskárny → auto bon na kuchyň/bar po objednání           │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│  4. PLATBA                                                   │
│     Hotově / Karta (POS) / QR pay-at-table (Stripe/GoPay)   │
│     Tisk účtenky → uzavření účtu → stůl volný                │
└─────────────────────────────────────────────────────────────┘
```

---

## 1️⃣ Nastavení (one-time setup)

### A) Floor plan (mapa restaurace)
**Cesta:** `Admin → Restaurace → Stoly → Floor plan → "✏️ Editovat layout"`

1. Vytvoř **zóny** (Sál, Bar, Terasa, Salonek) — každá má svůj canvas (š × v)
2. V zóně:
   - **Drag-add:** dvojklik do prázdna = přidá stůl
   - **Quick-add toolbar:** ⬜2 / ⬜4 / ⭕2 / ⭕4 / ▭6 / ▭8 / 🍺Bar / 🛋️Salonek
   - **Smart naming:** S1, S2... S8 (pokračuje v sekvenci, ne "Stůl 16")
3. Drag = přesouvej stoly · Klik = uprav (název/místa/tvar/barva)
4. **💾 Uložit** — bulk save dirty změn

**DB:** `restaurant_tables` + `restaurant_zones`. Sloupce: `x, y, width, height, tvar (round/square/rect), mist, sekce, zone_id, stav`.

### B) Tiskárny (volitelné — bez nich vše funguje, jen netiskne)
**Cesta:** `Admin → Nastavení → 🖨️ Tiskárny`

1. Přidat: název, typ (kasa/kuchyně/bar/sklad/výdej), IP, port 9100, šířka 80mm
2. Test print (🧪 Test) — vytiskne testovací bon
3. **Mapování:** každá kategorie výrobků → tiskárna
   - Káva, čaj → Kasa printer (barista)
   - Hlavní jídla, předkrmy → Kuchyně printer
   - Nápoje, drinky → Bar printer
4. **POS settings:**
   - "Tisk účtenky po platbě": **Vždy** / **Zeptat se** / **Nikdy**
   - "Auto-split bonů": **Auto** / **Manuální** / **Vypnuto**

**Bez fyzické tiskárny:** zapni 🧪 Dummy mode → zápisy do `/tmp/appek_printer_dummy/*.txt` (lze otestovat).

### C) Kategorie + výrobky
**Cesta:** `Admin → Výrobky → + Nový`

- Kategorie: `kategorie_vyrobku` (s `printer_id` mapping)
- Výrobky: cena, jednotka, DPH (12% v ČR pro stravování), alergeny, popis, foto
- Receptura: vyrobek → suroviny (kolik gramů) → auto-výpočet nutri hodnot

**Quick start:** Restaurace → "🍕 Naseed demo data" → 11 výrobků + 18 surovin + recepty hotové za 2s.

---

## 2️⃣ Objednávka — 3 vstupní body

### A) Číšník v POS (nejčastější)

**Cesta:** `POS → 🪑 Stoly → klik na stůl S2 → modal otevřen`

1. POS app (samostatné okno, fullscreen, dotyk-friendly)
2. Klik na stůl v mapě → otevře POS-native modal
3. **Layout modal (v3.0.15+):**
   - **Vlevo:** menu (katalog výrobků s big-touch cards 24px ceny)
     - Kategorie chips: ⭐ Vše / 🍕 Pizzy / ☕ Káva / ...
     - Search box (Cmd+K)
   - **Vpravo:** košík/účet
     - Položky s ✕ pro odebrání
     - Status badge per položka (🔥 vaří se / ✓ hotovo)
     - Summary: Bez DPH / DPH / **CELKEM**
4. **Akce:**
   - 🍳 Tisk bonu — vytištění kuchyňského bonu (nové položky)
   - 📤 Tisk účtu — finální účet pro hosta
   - 📲 QR platba — generuj QR (host scan → zaplatí online)
   - 💾 Uložit a zavřít — zachová účet otevřený (rozpracovaný)
   - 💰 Zaplatit — uzavře účet jako hotovostní platba

**Co se stane na backendu při přidání položky:**

```sql
INSERT INTO restaurant_pos_polozky (
    ucet_id, vyrobek_id, nazev, jednotkova_cena, mnozstvi,
    kategorie, stav = 'objednano', objednal_kdo
) VALUES (...);
```

→ Položka se okamžitě objeví na **KDS** (auto-refresh 10s) protože KDS dotazuje
`WHERE stav IN ('objednano','vari_se','hotovo')`.

→ Pokud kategorie má namapovanou **tiskárnu** (kuchyně/bar) → auto-print bon.

### B) Host přes QR kód

**Cesta:** Číšník nalepí QR kartu na stůl. Host scan → mobil otevře `/qr/?t=<token>`.

1. **Generování QR:** `Admin → Restaurace → Stoly → klik stůl → 📲 QR` → modal s QR + URL
2. **QR landing** (`/qr/index.php`): mobile-first PWA
   - GET `/api/pos_qr.php?action=menu&token=X` → menu pro stůl
   - Host: kategorie → tap +/− → košík → "Odeslat"
3. **Queue:** POST `/api/pos_qr.php?action=order` → INSERT do `restaurant_qr_orders` (stav='pending')
4. **Schválení:** `Admin → Restaurace → 📲 QR objednávky` → klik "✅ Schválit vše"
   - Backend přidá položky do otevřeného účtu stolu (nebo otevře nový)
   - Položky jdou na KDS
5. **Bonus:** Host může zavolat číšníka (🔔 button) → set table.stav = 'attention'

**Anti-spam:** Schvalování je povinné (host nemůže přímo poslat do kuchyně). Pro kavárny, kde to chceš auto-approve, je to plánováno v 3.1.

### C) Walk-in POS (sebou / take-away)

**Cesta:** `POS → 📦 Produkty tab`

1. Klik na produkty → cart vpravo
2. Vyber typ: 🛍️ Sebou / 🍽️ Na místě / 📦 Vyzvednutí / 🛵 Rozvoz
3. Klik **FINISH** → POST `quick_order` → INSERT do `objednavky` tabulky (NE `restaurant_pos_polozky`!)
   - Tyhle objednávky **NEJDOU na KDS** — jsou pro pultový prodej
   - Účtenka se vytiskne podle "Tisk účtenky" settings

---

## 3️⃣ Kuchyně

### 🍳 KDS (Kitchen Display) — pro kuchaře

**URL:** `/admin/kds.php` (samostatné okno, oranžový theme)

- Auto-refresh 10s + zvuk na novou objednávku
- Karty per účet (stůl) s položkami
- **Klik na položku** = posun stavu: `objednáno → vaří se → hotovo`
- "✓ Vše hotovo" tlačítko per účet (hromadné)
- **Stará >10 min** = oranžová · **>15 min** = červená pulzující
- Widget filtry: 🔥 Vaří se / ✓ Hotových (klik = filtr)
- Stats: Účtů · Položek · Vaří se · Hotových

**SQL endpoint:**
```sql
SELECT p.*, u.stul_id, t.nazev AS stul_nazev
FROM restaurant_pos_polozky p
JOIN restaurant_pos_ucty u ON u.id = p.ucet_id
JOIN restaurant_tables t   ON t.id = u.stul_id
WHERE u.stav = 'open' AND p.stav IN ('objednano','vari_se','hotovo')
ORDER BY p.cas_objednavky ASC
```

### 📤 Výdej (Pass-through) — pro číšníka

**URL:** `/admin/vydej.php` (zelený theme)

- Ukáže **jen účty s alespoň jednou hotovou položkou**
- Vařící se / nepřipravené = šedé info (číšník vidí co ještě dojde)
- **Klik na hotovou položku** = `servírováno` (zmizí z výdeje)
- "📤 Vše odneseno" pro hromadné
- 🍳 Tisk pickup bonu — vytištění pickup ticketu

### 🖨️ Tiskárny — auto-print logika

**Hook v `admin_pos.php?action=quick_order`** (POS finish):
1. Detect kategorie každé položky
2. Group by `kategorie.printer_id` → batch per tiskárnu
3. Build ESC/POS bytes (init, header, items, cut)
4. TCP socket na `printer.ip:9100` → write
5. Soft-fail (POS pokračuje, tisk nesmí blokovat)

**Hook pro POS Stoly** (přidání položky do účtu): zatím manuální (klik 🍳 Tisk bonu v modalu).

---

## 4️⃣ Platba — 3 možnosti

### A) Hotově / Karta na terminálu (číšník)

POS modal → "💰 Zaplatit" → potvrdit → `POST ?action=pay`:
```sql
UPDATE restaurant_pos_ucty SET stav='paid', paid_at=NOW() WHERE id=:id;
UPDATE restaurant_tables SET stav='free' WHERE id=:stul_id;
INSERT INTO objednavky (...) FROM restaurant_pos_polozky;  -- create receipt
```

→ Účtenka se vytiskne na kasa printer (pokud nakonfigurováno).

### B) QR pay-at-table (online platba)

**Po finalizaci účtu:** klik "📲 QR platba" → modal:
- Generuje pay_token (16 bytes hex) v `objednavky.pay_token`
- URL: `https://restaurace.cz/pay/?t=<token>`
- QR vytištěn / přidán k papírovému účtu

**Host scan → `/pay/index.php`:**
1. GET `/api/pay_qr.php?action=info&t=X` → částka, položky, info
2. Vybere metodu:
   - 💳 **Stripe** Checkout → redirect → karta/Apple Pay → webhook potvrdí
   - 🔴 **GoPay** redirect → karta/bank převod → callback potvrdí
   - 💵 **Hotovostí číšníkovi** → mark `pay_status='pending_manual'` → číšník je informován
3. Po platbě webhook → `objednavky.pay_status='paid'`, `paid_at=NOW()`
4. Polling na pay stránce každé 4s → auto refresh na "✅ Zaplaceno"

**Bez Stripe/GoPay setupu:** zobrazí se jen 💵 Hotovostí (vždy dostupné).

---

## 📊 Monitorování provozu (live)

### 📺 Provoz hub (admin Restaurace → Provoz tab)
- 4 widget karty: Stoly · Kuchyně · Rozvoz · POS dnes
- Auto-refresh 60s
- Klik na kartu → drill-down (Stoly → floor plan, POS dnes → POS app)

### 📺 Provoz standalone (`/admin/provoz.php`)
- Pro 2. monitor / TV v provozu
- 6 velikostí čísel (XS/S/M/L/XL/**TV**) → škálování pro velký monitor
- Wake-lock — drží obrazovku 24/7

### ⏱️ Timeline rezervací (Admin → Restaurace → Stoly → Timeline)
- 3 vrstvy:
  1. 📅 Rezervace (barevné bloky per host) — plánované
  2. 🟡 **Live POS účty** (žlutý pruh od otevreno_v do TEĎ)
  3. 🔴 **Now-line** (vertikální čára aktuálního času)
- Klik na blok = detail rezervace
- Klik do prázdna = nová rezervace

### 🩺 Diagnostika
`Admin → Nastavení → Údržba → 🩺 Diagnostika`
- Checks: DB connect, schema, disk, file write, error rate, PHP runtime
- Lint API (najde parse errors ve všech PHP)
- Test e-mailu

---

## 🗂️ DB tabulky — quick reference

| Tabulka | Co | Klíčové sloupce |
|---------|-----|----------------|
| `restaurant_tables` | Fyzické stoly | id, nazev, x, y, width, height, tvar, zone_id, stav, stav_od, obsluhuje |
| `restaurant_zones` | Zóny (Sál, Bar...) | id, nazev, canvas_w, canvas_h, bg_barva |
| `restaurant_pos_ucty` | Otevřené účty stolu | id, stul_id, stav (open/paid), otevreno_v, otevrel_jmeno |
| `restaurant_pos_polozky` | Položky účtu (KDS source) | id, ucet_id, vyrobek_id, nazev, jednotkova_cena, mnozstvi, kategorie, stav (objednano/vari_se/hotovo/servirovano) |
| `restaurant_qr_orders` | QR objednávky waiting | id, stul_id, stav (pending/approved), polozky_json |
| `restaurant_printers` | ESC/POS tiskárny | id, nazev, typ, ip, port, sirka_papiru, aktivni |
| `kategorie_vyrobku` | Kategorie | id, nazev, ikona, printer_id (→ mapping) |
| `vyrobky` | Výrobky | id, nazev, kategorie_id, cena_bez_dph, sazba_dph_id, alergeny, nutricni_hodnoty |
| `vyrobek_suroviny` | Recepty | vyrobek_id, surovina_id, mnozstvi, jednotka |
| `suroviny` | Suroviny | id, nazev, jednotka, alergen, nutri_kj/kcal/tuky/sacharidy/bilkoviny/sul |
| `objednavky` | Finální účtenky (POS sebou + paid stoly) | id, cislo, datum_objednani, castka_celkem, pos_payment, pay_token, pay_status |

---

## ⚙️ Multi-screen setup (doporučení)

Pro profesionální restauraci 3-4 monitory:

```
┌──────────────────────────────────────────────────────────────────┐
│  📺 PROVOZ        🧾 POS         👨‍🍳 KDS         📤 VÝDEJ        │
│  (kancelář)       (pult/iPad)   (kuchyně)        (pass-through)  │
│  /admin/provoz.php  pos.php     kds.php          vydej.php       │
│  + autorefresh    Production    Auto-refresh     Auto-refresh    │
│  Wake-lock        Cash drawer   10s + zvuk       8s + ding-dong  │
└──────────────────────────────────────────────────────────────────┘
                              ↓
                     Sdílená MySQL DB
                              ↓
                       1 router + LAN
```

**Hardware minimum (pro 50-100 obj./den):**
- 1× server (NAS / mini PC) + 1× router
- 3-4× tablet nebo monitor (Android tablet, iPad, Raspberry Pi s monitorem...)
- Tiskárny: **Epson TM-T20III** Ethernet (~3500 Kč), 80mm termo
- QR kódy: nalepené plastové karty na stoly

---

## 🆘 Co když...

**Q: Host objedná přes POS, ale na KDS to nevidím?**
- Zkontroluj že položka má `stav = 'objednano'` (default při INSERT)
- Zkontroluj že účet má `stav = 'open'` (KDS filtruje WHERE u.stav='open')
- KDS auto-refresh je 10s → počkej

**Q: Tisk nefunguje?**
- Otestuj 🧪 Test v Tiskárně — pokud OK fyzicky → mapování kategorií chybí
- Pokud test fail → zkontroluj IP/port 9100, firewall
- Pro debug: zapni Dummy mode, mrkni `/tmp/appek_printer_dummy/`

**Q: Stoly nezmění barvu na obsazený?**
- POS → otevři účet stolu — backend nastaví `restaurant_tables.stav = 'occupied'`
- Pokud stav zůstane na 'free', mrkni Diagnostiku → app_errors

**Q: Floor plan editor neuloží?**
- Klik 💾 Uložit (vidí změny v `dirtyTables`)
- Pokud 0 změn = nic neuloží
- Pokud chyba → mrkni Logs (Nastavení → Údržba)

---

**Powered by APPEK** · [appek.cz](https://appek.cz) · [Video návody na YouTube](https://youtu.be/oeOdpkFAg8M)
