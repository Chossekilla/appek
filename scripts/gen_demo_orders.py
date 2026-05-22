#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
🌱 Generátor konzistentní DATOVÉ sekce demo-seedu pro APPEK demo.appek.cz

Vygeneruje JEDEN společný blok SQL (INSERTy) sdílený oběma seed soubory:
  - deploy/demo-seed-full.sql  (DROP + CREATE + tento blok)
  - deploy/demo-seed.sql       (DELETE + tento blok, hodinový reset)

Co blok obsahuje (vše matematicky a FK konzistentní — odkazuje jen na ID,
která existují v ručně udržovaných hlavičkách: vyrobky 1-50, odberatele 1-12):

  • mista_dodani            — dodací adresy pro vybrané odběratele
  • objednavky (+ polozky)  — 30× validní stavy, částky = součet položek,
                              část napojená na misto_dodani_id
  • dodaci_listy (+ pol.)   — KAŽDÁ doručená objednávka (1-12) má dodací list
  • faktury (+ polozky)     — 9× z doručených objednávek 1-9, se snapshot sloupci
  • faktury_dodaci_listy    — každá faktura napojená na svůj dodací list
  • restaurant_tables       — 6 stolů (POS závisí na nich)
  • restaurant_pos_ucty …   — 10 POS účtenek + položky + platby
  • suroviny                — 20 surovin (sklad / HACCP)
  • vyrobek_suroviny        — receptury pro 10 výrobků
  • vyrobni_listy (+ pol.)  — 3 výrobní listy
  • cislovani               — čítače navazují na nejvyšší doklad (OBJ/FA/DL/VL)

Historie:
  v2.9.57  — validní stavy, částky hlaviček = součet položek
  v2.9.104 — cislovani typ='FA' (ne 'FAK'), predcisli s rokem; faktury snapshot sloupce
  v2.9.105 — KOMPLETNÍ demo: plné řetězce pro všechny doručené objednávky,
             POS data, mista_dodani, suroviny + receptury + výrobní listy.

Výstup: SQL blok na stdout. Vkládá se mezi sekci `odberatele` a `SET FOREIGN_KEY_CHECKS = 1`.
"""

# ── Výrobky: id → (název, cena_bez_dph, sazba_dph) ──────────────────────────
# Pozn.: musí odpovídat hlavičce `vyrobky` v seedu (id 1-50).
V = {
    1:  ('Chléb pšenično-žitný 1kg', 38.00, 12),
    2:  ('Bageta francouzská 250g',  18.00, 12),
    3:  ('Houska s mákem',            8.50, 12),
    5:  ('Rohlík tukový',             3.20, 12),
    6:  ('Ciabatta 200g',            22.00, 12),
    10: ('Sourdough chléb 800g',     72.00, 12),
    13: ('Větrník',                  32.00, 12),
    17: ('Croissant s čokoládou',    28.00, 12),
    26: ('Sendvič šunkový',          42.00, 21),
    28: ('Wrap kuřecí',              68.00, 21),
    30: ('Bramborový salát 500g',    62.00, 21),
    34: ('Káva Lungo 0.5l',          45.00, 12),
}

# ── Odběratelé: id → (nazev, ico, dic, ulice, mesto, psc) ───────────────────
# Snapshot zdroj pro faktury (musí odpovídat hlavičce `odberatele`).
ODB = {
    1:  ('Penzion U Lípy',               '12345001', 'CZ12345001', 'Lipová 12',    'Brno',     '60200'),
    2:  ('Restaurace Pod Lípou',         '12345002', 'CZ12345002', 'Hlavní 24',    'Praha 5',  '15000'),
    3:  ('Hotel Continental ****',       '12345003', 'CZ12345003', 'Václavské 7',  'Praha 1',  '11000'),
    4:  ('Kavárna Sladký Sen',           '12345004', 'CZ12345004', 'Masarykova 5', 'Brno',     '60200'),
    5:  ('Školní jídelna ZŠ Komenského', '12345005', 'CZ12345005', 'Komenského 8', 'Plzeň',    '30100'),
    6:  ('Catering Premium s.r.o.',      '12345006', 'CZ12345006', 'Průmyslová 3', 'Ostrava',  '70200'),
    7:  ('Pivnice Na Kopečku',           '12345007', 'CZ12345007', 'Kopcová 18',   'Liberec',  '46001'),
    8:  ('Bistro Vegan Garden',          '12345008', 'CZ12345008', 'Bezručova 11', 'Praha 6',  '16000'),
    9:  ('Pension Hluboká nad Vltavou',  '12345009', 'CZ12345009', 'Zámecká 2',    'Hluboká',  '37341'),
    10: ('Restaurace Stará pošta',       '12345010', 'CZ12345010', 'Hlavní 100',   'Olomouc',  '77900'),
    11: ('Cukrárna U Tří Kytek',         '12345011', 'CZ12345011', 'Květinová 5',  'Hradec K.', '50000'),
    12: ('Senior dům Slunečnice',        '12345012', 'CZ12345012', 'Slunná 14',    'Pardubice', '53002'),
}

# Platné stavy objednávky (dle api/admin_objednavky.php):
# ['nova','potvrzena','ve_vyrobe','pripravena','expedovana','dorucena','zrusena']

# ── 30 objednávek ───────────────────────────────────────────────────────────
# (odberatel_id, dni_obj, dni_dod, stav, [(vyrobek_id, mnozstvi),…], poznamka, misto_dodani_id|0)
ORDERS = [
    (1,  7, 6, 'dorucena',   [(1,20),(3,50),(5,150)],  'Pravidelný odběr pondělí',   1),
    (2,  6, 5, 'dorucena',   [(13,30),(17,40)],         'Termín do 8:00',             3),
    (3,  5, 4, 'dorucena',   [(26,50),(28,30)],         'Snídaně pro hotel',          4),
    (4,  4, 3, 'dorucena',   [(2,30),(5,80)],           '',                           0),
    (5,  4, 3, 'dorucena',   [(1,15),(3,40)],           'Školní obědy',               0),
    (6,  4, 2, 'dorucena',   [(26,80),(28,50),(34,40)], 'Catering svatba',            7),
    (7,  3, 2, 'dorucena',   [(5,100),(3,30)],          '',                           0),
    (8,  3, 2, 'dorucena',   [(2,20),(6,15)],           'Vegan sendviče',             0),
    (9,  3, 1, 'dorucena',   [(1,25),(10,10)],          '',                           8),
    (10, 2, 1, 'dorucena',   [(1,30),(13,20)],          'Pension týdenní',            0),
    (11, 2, 1, 'dorucena',   [(26,40),(30,20)],         '',                           0),
    (12, 2, 1, 'dorucena',   [(13,40),(17,30)],         'Cukrárna týdenní',           0),
    (1,  2, 0, 'expedovana', [(1,18),(3,60)],           '90 obědů',                   2),
    (2,  1, 0, 'expedovana', [(2,40),(5,120)],          '',                           3),
    (3,  1, 0, 'expedovana', [(1,30),(6,40),(10,12)],   'Hotel ranní dodávka',        4),
    (4,  1, 0, 'pripravena', [(3,50),(5,80)],           '',                           0),
    (5,  1, 0, 'pripravena', [(13,25),(17,20)],         'Školní obědy',               0),
    (6,  0, 0, 've_vyrobe',  [(26,60),(28,40)],         'Svatební hostina sobota',    7),
    (7,  0, 0, 've_vyrobe',  [(5,90),(2,15)],           '',                           0),
    (8,  0, 0, 've_vyrobe',  [(6,30),(34,20)],          '',                           0),
    (1,  0, -1, 'potvrzena', [(1,20),(3,50),(5,150)],   'Týdenní objednávka',         1),
    (2,  0, -1, 'potvrzena', [(13,30),(17,40)],         '',                           3),
    (3,  0, -1, 'nova',      [(26,50),(28,30)],         '',                           4),
    (4,  0, -1, 'nova',      [(2,30),(5,80)],           '',                           0),
    (9,  0, -1, 'nova',      [(1,30),(13,20)],          '',                           0),
    (10, 0, -2, 'nova',      [(26,40),(30,20)],         '',                           0),
    (11, 0, -2, 'nova',      [(13,40),(17,30)],         '',                           0),
    (12, 0, -2, 'nova',      [(5,60),(3,40)],           '',                           0),
    (5,  0, -3, 'nova',      [(13,25),(17,20)],         '',                           0),
    (6,  0, -5, 'nova',      [(26,80),(28,50),(34,40)], 'Catering velký event',       7),
]

# ── Místa dodání: id → (odberatel_id, nazev, ulice, mesto, psc, kontakt, tel, cas, vychozi) ──
MISTA = [
    (1, 1,  'Penzion U Lípy — recepce',      'Lipová 12',     'Brno',    '60200', 'Marie Lípová',  '+420 777 100 001', '06:30–07:30', 1),
    (2, 1,  'Penzion U Lípy — kavárna',      'Lipová 12a',    'Brno',    '60200', 'Jan Lípa',      '+420 777 100 021', '07:00–08:00', 0),
    (3, 2,  'Restaurace Pod Lípou — kuchyně','Hlavní 24',     'Praha 5', '15000', 'Petr Novák',    '+420 777 100 002', 'do 08:00',    1),
    (4, 3,  'Hotel Continental — zásobování','Václavské 7',   'Praha 1', '11000', 'Jana Veselá',   '+420 777 100 003', '05:30–06:30', 1),
    (5, 6,  'Catering Premium — sklad',      'Průmyslová 3',  'Ostrava', '70200', 'Tomáš Svoboda', '+420 777 100 006', 'dle dohody',  1),
    (6, 9,  'Pension Hluboká — recepce',     'Zámecká 2',     'Hluboká', '37341', 'Karel Vondra',  '+420 777 100 009', '07:00–08:00', 1),
    (7, 6,  'Catering Premium — event hala', 'Výstavní 10',   'Ostrava', '70030', 'Lenka Eventová','+420 777 100 026', 'dle eventu',  0),
    (8, 9,  'Pension Hluboká — restaurace',  'Zámecká 2b',    'Hluboká', '37341', 'Eva Vondrová',  '+420 777 100 029', '07:30–08:30', 0),
]

# ── Suroviny: (id, nazev, jednotka, alergen, cena_baleni, obsah_baleni, stock, min, cil) ──
SUROVINY = [
    (1,  'Mouka pšeničná hladká T530', 'kg', 'lepek',  18.50, 25.0,  180.0,  40.0, 250.0),
    (2,  'Mouka chlebová T1050',       'kg', 'lepek',  17.90, 25.0,  220.0,  50.0, 300.0),
    (3,  'Mouka žitná T960',           'kg', 'lepek',  19.40, 25.0,   95.0,  30.0, 150.0),
    (4,  'Droždí čerstvé',             'kg', None,     42.00,  1.0,   14.5,   5.0,  20.0),
    (5,  'Sůl jedlá',                  'kg', None,      9.90, 25.0,   60.0,  10.0,  80.0),
    (6,  'Cukr krystal',               'kg', None,     21.50, 50.0,  120.0,  20.0, 150.0),
    (7,  'Cukr moučka',                'kg', None,     27.00, 25.0,   38.0,  10.0,  60.0),
    (8,  'Máslo 82%',                  'kg', 'mléko',  248.0,  5.0,   42.0,  12.0,  60.0),
    (9,  'Mléko plnotučné 3,5%',       'l',  'mléko',  24.90,  1.0,   85.0,  30.0, 120.0),
    (10, 'Vejce slepičí L',            'ks', 'vejce',   4.20, 360.0, 720.0, 240.0, 900.0),
    (11, 'Olej slunečnicový',          'l',  None,     38.00, 10.0,   46.0,  10.0,  60.0),
    (12, 'Olivový olej extra virgin',  'l',  None,    219.00,  5.0,   12.0,   4.0,  20.0),
    (13, 'Kakao 100%',                 'kg', None,    189.00,  5.0,    8.5,   3.0,  15.0),
    (14, 'Čokoláda hořká 70%',         'kg', 'sója',  165.00,  5.0,   22.0,   8.0,  30.0),
    (15, 'Mák modrý mletý',            'kg', 'mák',    98.00,  5.0,   17.0,   5.0,  25.0),
    (16, 'Sezam loupaný',              'kg', 'sezam',  76.00,  5.0,    9.0,   3.0,  15.0),
    (17, 'Šunka dušená výběrová',      'kg', None,    159.00,  3.0,   28.0,  10.0,  40.0),
    (18, 'Kuřecí prsní řízek',         'kg', None,    149.00,  5.0,   34.0,  15.0,  50.0),
    (19, 'Sýr Eidam 30%',              'kg', 'mléko',  119.00, 3.0,   19.0,   8.0,  30.0),
    (20, 'Brambory varný typ B',       'kg', None,     14.50, 25.0,  140.0,  40.0, 200.0),
]

# ── Receptury: vyrobek_id → [(surovina_id, mnozstvi, jednotka), …] ──────────
# Hrubá kalkulace na 1 ks výrobku — pro modul kalkulace / HACCP / spotřeba.
RECEPTY = {
    1:  [(2, 0.620, 'kg'), (3, 0.180, 'kg'), (4, 0.018, 'kg'), (5, 0.016, 'kg')],   # Chléb pš.-žitný
    2:  [(1, 0.170, 'kg'), (4, 0.006, 'kg'), (5, 0.004, 'kg')],                      # Bageta
    3:  [(1, 0.052, 'kg'), (4, 0.002, 'kg'), (6, 0.005, 'kg'), (15, 0.004, 'kg')],   # Houska s mákem
    5:  [(1, 0.034, 'kg'), (4, 0.001, 'kg'), (11, 0.003, 'l')],                      # Rohlík tukový
    6:  [(1, 0.165, 'kg'), (4, 0.005, 'kg'), (12, 0.012, 'l'), (5, 0.004, 'kg')],    # Ciabatta
    10: [(3, 0.480, 'kg'), (2, 0.260, 'kg'), (5, 0.014, 'kg')],                      # Sourdough chléb
    13: [(1, 0.040, 'kg'), (10, 1.000, 'ks'), (8, 0.030, 'kg'), (7, 0.020, 'kg')],   # Větrník
    17: [(1, 0.045, 'kg'), (8, 0.022, 'kg'), (14, 0.018, 'kg'), (9, 0.020, 'l')],    # Croissant s čok.
    26: [(1, 0.090, 'kg'), (17, 0.060, 'kg'), (8, 0.010, 'kg')],                     # Sendvič šunkový
    28: [(1, 0.110, 'kg'), (18, 0.090, 'kg'), (19, 0.020, 'kg')],                    # Wrap kuřecí
}

# ── Výrobní listy: (id, dni_vyroby, dni_dodani, stav, poznamka, [(vyrobek_id, plan, hotovo), …]) ──
VYROBNI_LISTY = [
    (1, 6, 6, 'dokonceno', 'Ranní šára pondělí',
     [(1, 53, 53), (3, 110, 110), (5, 150, 150)]),
    (2, 4, 4, 'dokonceno', 'Cukrářská výroba — catering',
     [(13, 70, 70), (17, 70, 68)]),
    (3, 0, 0, 'rozpracovano', 'Dnešní plán výroby',
     [(1, 48, 20), (6, 70, 30), (10, 12, 0)]),
]

# ── POS účtenky (restaurace) ────────────────────────────────────────────────
# Stoly
POS_TABLES = [
    (1, 'Stůl 1', 2, 'Lokál',     'round'),
    (2, 'Stůl 2', 4, 'Lokál',     'square'),
    (3, 'Stůl 3', 4, 'Lokál',     'square'),
    (4, 'Stůl 4', 6, 'Salonek',   'rect'),
    (5, 'Stůl 5', 2, 'Zahrádka',  'round'),
    (6, 'Bar',    8, 'Bar',       'rect'),
]
# Účty: (id, stul_id, dni, hod_open, stav, pocet_hostu, typ, [(vyrobek_id, mnozstvi), …],
#        [(castka, zpusob), …])  — castka_celkem položek se dopočítá; platby musí sedět.
POS_UCTY = [
    (1, 2, 1, 12, 'paid',   3, 'inhouse',  [(26, 3), (34, 3)],          'split-card-cash'),
    (2, 1, 1, 13, 'paid',   2, 'inhouse',  [(28, 2), (13, 2)],          'card'),
    (3, 6, 1, 18, 'paid',   4, 'inhouse',  [(34, 4), (17, 4), (13, 2)], 'cash'),
    (4, 3, 1, 19, 'paid',   5, 'inhouse',  [(26, 5), (30, 2), (34, 5)], 'card'),
    (5, 5, 0,  9, 'paid',   2, 'takeaway', [(17, 2), (34, 2)],          'cash'),
    (6, 4, 0, 11, 'paid',   6, 'inhouse',  [(28, 6), (26, 3), (34, 6)], 'card'),
    (7, 1, 0, 12, 'paid',   1, 'takeaway', [(2, 2), (3, 4)],            'qr'),
    (8, 6, 0, 14, 'paid',   3, 'inhouse',  [(13, 3), (34, 3)],          'cash'),
    (9, 2, 0, 16, 'open',   2, 'inhouse',  [(26, 2), (17, 2)],          None),
    (10, 3, 0, 18, 'open',  4, 'inhouse',  [(28, 4), (34, 4), (13, 1)], None),
]
# Způsob platby účtu → preferovaný zpusob v restaurant_pos_platby
POS_KAT = {  # vyrobek_id → kategorie pro POS položku
    1: 'Pečivo', 2: 'Pečivo', 3: 'Pečivo', 5: 'Pečivo', 6: 'Pečivo', 10: 'Pečivo',
    13: 'Cukrářské', 17: 'Cukrářské',
    26: 'Lahůdky', 28: 'Lahůdky', 30: 'Lahůdky',
    34: 'Nápoje',
}


def money(x):
    return round(x + 1e-9, 2)


def order_totals(items):
    bez = 0.0
    dph = 0.0
    for vid, qty in items:
        _, cena, sazba = V[vid]
        line = cena * qty
        bez += line
        dph += line * sazba / 100.0
    return money(bez), money(dph)


def pos_total(items):
    """POS suma = cena_s_dph × množství (POS pracuje s koncovými cenami)."""
    total = 0.0
    for vid, qty in items:
        _, cena, sazba = V[vid]
        total += cena * (1 + sazba / 100.0) * qty
    return money(total)


def dexpr(days):
    if days == 0:
        return 'CURDATE()'
    if days > 0:
        return f'DATE_SUB(CURDATE(), INTERVAL {days} DAY)'
    return f'DATE_ADD(CURDATE(), INTERVAL {-days} DAY)'


def dtexpr(days, hour):
    """DATETIME výraz: CURDATE() − days + hodina:00."""
    base = dexpr(days)
    return f"TIMESTAMP({base}, '{hour:02d}:00:00')"


def esc(s):
    return s.replace("'", "''")


# ── DODACÍ LISTY: každá doručená objednávka (stav='dorucena') → 1 DL ─────────
# (dl_id, objednavka_id) — pořadí dle objednávky. fakturovano=1 pokud má fakturu.
DELIVERED = [i for i, o in enumerate(ORDERS, start=1) if o[3] == 'dorucena']  # = 1..12
# Faktury jen z prvních 9 doručených objednávek
INVOICED_ORDERS = DELIVERED[:9]  # = 1..9

lines = []
A = lines.append

A('-- ════════════════════════════════════════════════════════════')
A('-- 🏠 MÍSTA DODÁNÍ — dodací adresy odběratelů')
A('-- ════════════════════════════════════════════════════════════')
A('INSERT INTO mista_dodani (id, odberatel_id, nazev, ulice, mesto, psc, kontaktni_osoba, telefon, cas_dodani, vychozi, aktivni, poradi) VALUES')
mh = []
for mid, odb, nazev, ulice, mesto, psc, kont, tel, cas, vych in MISTA:
    mh.append(f"({mid}, {odb}, '{esc(nazev)}', '{esc(ulice)}', '{esc(mesto)}', '{psc}', "
              f"'{esc(kont)}', '{tel}', '{cas}', {vych}, 1, {mid})")
A(',\n'.join(mh) + ';')
A('')

# ── OBJEDNÁVKY ──────────────────────────────────────────────────────────────
A('-- ════════════════════════════════════════════════════════════')
A('-- 📋 OBJEDNÁVKY — 30× (validní stavy, částky = součet položek)')
A('-- ════════════════════════════════════════════════════════════')
A('INSERT INTO objednavky (id, cislo, odberatel_id, misto_dodani_id, datum_objednani, datum_dodani, stav, castka_bez_dph, castka_dph, castka_celkem, poznamka) VALUES')
oh = []
for i, (odb, d_obj, d_dod, stav, items, pozn, misto) in enumerate(ORDERS, start=1):
    bez, dph = order_totals(items)
    celkem = money(bez + dph)
    cislo = f'OBJ-2026-{i:03d}'
    misto_sql = str(misto) if misto else 'NULL'
    oh.append(f"({i}, '{cislo}', {odb}, {misto_sql}, {dexpr(d_obj)}, {dexpr(d_dod)}, "
              f"'{stav}', {bez:.2f}, {dph:.2f}, {celkem:.2f}, '{esc(pozn)}')")
A(',\n'.join(oh) + ';')
A('')

A('-- ── Objednávky — položky ──')
A('INSERT INTO objednavky_polozky (objednavka_id, vyrobek_id, vyrobek_nazev, mnozstvi, jednotka, cena_bez_dph, sazba_dph) VALUES')
op = []
for i, (odb, d_obj, d_dod, stav, items, pozn, misto) in enumerate(ORDERS, start=1):
    for vid, qty in items:
        nazev, cena, sazba = V[vid]
        op.append(f"({i}, {vid}, '{esc(nazev)}', {qty}, 'ks', {cena:.2f}, {sazba})")
A(',\n'.join(op) + ';')
A('')

# ── DODACÍ LISTY — pro KAŽDOU doručenou objednávku ──────────────────────────
A('-- ════════════════════════════════════════════════════════════')
A('-- 📄 DODACÍ LISTY — 12× (každá doručená objednávka má dodací list)')
A('-- ════════════════════════════════════════════════════════════')
A('INSERT INTO dodaci_listy (id, cislo, objednavka_id, odberatel_id, misto_dodani_id, datum_vystaveni, datum_dodani, castka_celkem, fakturovano) VALUES')
dh = []
for dl_id, obj_id in enumerate(DELIVERED, start=1):
    odb, d_obj, d_dod, stav, items, pozn, misto = ORDERS[obj_id - 1]
    bez, dph = order_totals(items)
    celkem = money(bez + dph)
    fakt = 1 if obj_id in INVOICED_ORDERS else 0
    misto_sql = str(misto) if misto else 'NULL'
    dh.append(f"({dl_id}, 'DL-2026-{dl_id:03d}', {obj_id}, {odb}, {misto_sql}, "
              f"{dexpr(d_dod)}, {dexpr(d_dod)}, {celkem:.2f}, {fakt})")
A(',\n'.join(dh) + ';')
A('')

A('-- ── Dodací listy — položky ──')
A('INSERT INTO dodaci_list_polozky (dodaci_list_id, vyrobek_id, vyrobek_nazev, mnozstvi, jednotka, cena_bez_dph, sazba_dph) VALUES')
dp = []
for dl_id, obj_id in enumerate(DELIVERED, start=1):
    odb, d_obj, d_dod, stav, items, pozn, misto = ORDERS[obj_id - 1]
    for vid, qty in items:
        nazev, cena, sazba = V[vid]
        dp.append(f"({dl_id}, {vid}, '{esc(nazev)}', {qty}, 'ks', {cena:.2f}, {sazba})")
A(',\n'.join(dp) + ';')
A('')

# ── FAKTURY — 9×, každá má dodací list + snapshot odběratele ────────────────
A('-- ════════════════════════════════════════════════════════════')
A('-- 💰 FAKTURY — 9× (z doručených objednávek 1-9, každá s dodacím listem)')
A('-- v2.9.104: snapshot sloupce odběratele se plní z řádku odberatele')
A('-- ════════════════════════════════════════════════════════════')
A('INSERT INTO faktury (id, cislo, odberatel_id, datum_vystaveni, datum_splatnosti, datum_dph, '
  'castka_bez_dph, castka_dph, castka_celkem, castka_uhrazeno, datum_uhrazeni, variabilni_symbol, '
  'misto_dodani_id, odb_nazev_snapshot, odb_ico_snapshot, odb_dic_snapshot, '
  'odb_ulice_snapshot, odb_mesto_snapshot, odb_psc_snapshot) VALUES')
fh = []
# faktura_id == objednavka_id == pořadí; DL faktury i = DL index doručené objednávky i
for fid, obj_id in enumerate(INVOICED_ORDERS, start=1):
    odb, d_obj, d_dod, stav, items, pozn, misto = ORDERS[obj_id - 1]
    bez, dph = order_totals(items)
    celkem = money(bez + dph)
    # Vystaveno den po dodání; splatnost +14 dní; první 4 faktury uhrazené
    d_vyst = d_dod - 1
    placeno = fid <= 4
    uhr = celkem if placeno else 0.00
    uhr_date = dexpr(d_vyst - 2) if placeno else 'NULL'
    nazev, ico, dic, ulice, mesto, psc = ODB[odb]
    misto_sql = str(misto) if misto else 'NULL'
    fh.append(
        f"({fid}, 'FAK-2026-{fid:03d}', {odb}, {dexpr(d_vyst)}, {dexpr(d_vyst - 14)}, {dexpr(d_vyst)}, "
        f"{bez:.2f}, {dph:.2f}, {celkem:.2f}, {uhr:.2f}, {uhr_date}, '2026{fid:04d}', "
        f"{misto_sql}, '{esc(nazev)}', '{ico}', '{dic}', '{esc(ulice)}', '{esc(mesto)}', '{psc}')")
A(',\n'.join(fh) + ';')
A('')

A('-- ── Faktury — položky ──')
A('INSERT INTO faktura_polozky (faktura_id, vyrobek_id, vyrobek_nazev, mnozstvi, jednotka, cena_bez_dph, sazba_dph, poradi) VALUES')
fp = []
for fid, obj_id in enumerate(INVOICED_ORDERS, start=1):
    odb, d_obj, d_dod, stav, items, pozn, misto = ORDERS[obj_id - 1]
    for poradi, (vid, qty) in enumerate(items, start=1):
        nazev, cena, sazba = V[vid]
        fp.append(f"({fid}, {vid}, '{esc(nazev)}', {qty}, 'ks', {cena:.2f}, {sazba}, {poradi})")
A(',\n'.join(fp) + ';')
A('')

# ── VAZBA faktura ⇄ dodací list ─────────────────────────────────────────────
A('-- ── Vazba faktura ⇄ dodací list (každá faktura → svůj DL) ──')
A('INSERT INTO faktury_dodaci_listy (faktura_id, dodaci_list_id) VALUES')
fdl = []
# DL pro doručenou objednávku obj_id má dl_id = pozice obj_id v DELIVERED
delivered_pos = {obj_id: idx for idx, obj_id in enumerate(DELIVERED, start=1)}
for fid, obj_id in enumerate(INVOICED_ORDERS, start=1):
    fdl.append(f"({fid}, {delivered_pos[obj_id]})")
A(',\n'.join(fdl) + ';')
A('')

# ── SUROVINY ────────────────────────────────────────────────────────────────
A('-- ════════════════════════════════════════════════════════════')
A('-- 🌾 SUROVINY — 20× (sklad / kalkulace / HACCP)')
A('-- ════════════════════════════════════════════════════════════')
A('INSERT INTO suroviny (id, nazev, jednotka, alergen, cena_baleni, obsah_baleni, '
  'stock_aktualni, stock_minimalni, stock_cilove, sklad_stav, sklad_min, sklad_cil, aktivni) VALUES')
sh = []
for sid, nazev, jed, alerg, cena, obsah, stock, smin, scil in SUROVINY:
    alerg_sql = f"'{esc(alerg)}'" if alerg else 'NULL'
    sh.append(f"({sid}, '{esc(nazev)}', '{jed}', {alerg_sql}, {cena:.2f}, {obsah:.3f}, "
              f"{stock:.3f}, {smin:.3f}, {scil:.3f}, {stock:.3f}, {smin:.3f}, {scil:.3f}, 1)")
A(',\n'.join(sh) + ';')
A('')

# ── RECEPTURY (vyrobek_suroviny) ────────────────────────────────────────────
A('-- ════════════════════════════════════════════════════════════')
A('-- 🧾 RECEPTURY — vazba výrobek ⇄ surovina (10 výrobků)')
A('-- ════════════════════════════════════════════════════════════')
A('INSERT INTO vyrobek_suroviny (vyrobek_id, surovina_id, mnozstvi, jednotka, poradi) VALUES')
vs = []
for vid in sorted(RECEPTY):
    for poradi, (sid, mn, jed) in enumerate(RECEPTY[vid], start=1):
        vs.append(f"({vid}, {sid}, {mn:.3f}, '{jed}', {poradi})")
A(',\n'.join(vs) + ';')
A('')

# ── VÝROBNÍ LISTY ───────────────────────────────────────────────────────────
A('-- ════════════════════════════════════════════════════════════')
A('-- 🏭 VÝROBNÍ LISTY — 3× (plán výroby + položky)')
A('-- ════════════════════════════════════════════════════════════')
A('INSERT INTO vyrobni_listy (id, cislo, datum_vyroby, datum_dodani, stav, poznamka, created_by) VALUES')
vh = []
for vl_id, d_vyr, d_dod, stav, pozn, _items in VYROBNI_LISTY:
    vh.append(f"({vl_id}, 'VL-2026-{vl_id:03d}', {dexpr(d_vyr)}, {dexpr(d_dod)}, "
              f"'{stav}', '{esc(pozn)}', 'Demo Výroba')")
A(',\n'.join(vh) + ';')
A('')

A('-- ── Výrobní listy — položky ──')
A('INSERT INTO vyrobni_list_polozky (vyrobni_list_id, vyrobek_id, mnozstvi, vyrobeno) VALUES')
vlp = []
for vl_id, d_vyr, d_dod, stav, pozn, vitems in VYROBNI_LISTY:
    for vid, plan, hotovo in vitems:
        vlp.append(f"({vl_id}, {vid}, {plan:.3f}, {hotovo:.3f})")
A(',\n'.join(vlp) + ';')
A('')

# ── POS — stoly + účtenky + položky + platby ────────────────────────────────
A('-- ════════════════════════════════════════════════════════════')
A('-- 🍽️ RESTAURACE POS — stoly, účtenky, položky, platby')
A('-- ════════════════════════════════════════════════════════════')
A('-- POS tabulky NEJSOU v api/_schema.sql (vytváří je admin_pos.php /')
A('-- admin_tables.php při prvním načtení). Hodinový reset jede čisté SQL,')
A('-- proto je tu zaručíme přes CREATE TABLE IF NOT EXISTS (kopie definic).')
A("""CREATE TABLE IF NOT EXISTS restaurant_tables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nazev VARCHAR(60) NOT NULL,
    mist INT NOT NULL DEFAULT 2,
    sekce VARCHAR(40) DEFAULT NULL,
    x INT DEFAULT 0,
    y INT DEFAULT 0,
    tvar ENUM('round','square','rect') DEFAULT 'square',
    aktivni TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sekce (sekce)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;""")
A("""CREATE TABLE IF NOT EXISTS restaurant_pos_ucty (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stul_id INT NOT NULL,
    otevreno_v DATETIME DEFAULT CURRENT_TIMESTAMP,
    zaplaceno_v DATETIME NULL,
    otevrel_id INT NULL,
    otevrel_jmeno VARCHAR(120) NULL,
    stav ENUM('open','awaiting_payment','paid','cancelled','merged','split') NOT NULL DEFAULT 'open',
    suma_kc DECIMAL(10,2) NOT NULL DEFAULT 0,
    suma_zaplaceno DECIMAL(10,2) NOT NULL DEFAULT 0,
    pocet_hostu INT DEFAULT 1,
    poznamka TEXT NULL,
    parent_ucet_id INT NULL,
    objednavka_typ ENUM('inhouse','takeaway','delivery') DEFAULT 'inhouse',
    cislo_dokladu VARCHAR(40) NULL,
    INDEX idx_stul (stul_id),
    INDEX idx_stav (stav),
    INDEX idx_otevreno (otevreno_v),
    FOREIGN KEY (stul_id) REFERENCES restaurant_tables(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;""")
A("""CREATE TABLE IF NOT EXISTS restaurant_pos_polozky (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ucet_id INT NOT NULL,
    vyrobek_id INT NULL,
    nazev VARCHAR(200) NOT NULL,
    jednotkova_cena DECIMAL(10,2) NOT NULL,
    mnozstvi DECIMAL(8,2) NOT NULL DEFAULT 1,
    kategorie VARCHAR(60) NULL,
    kurz INT DEFAULT 1,
    cas_objednavky DATETIME DEFAULT CURRENT_TIMESTAMP,
    cas_vari_se DATETIME NULL,
    cas_pripraveno DATETIME NULL,
    cas_servirovano DATETIME NULL,
    stav ENUM('objednano','vari_se','hotovo','servirovano','storno') NOT NULL DEFAULT 'objednano',
    kuchyne_tisk TINYINT(1) DEFAULT 0,
    poznamka VARCHAR(300) NULL,
    objednal_kdo VARCHAR(120) NULL,
    zdroj ENUM('staff','qr','app') DEFAULT 'staff',
    INDEX idx_ucet (ucet_id),
    INDEX idx_stav (stav),
    INDEX idx_kurz (ucet_id, kurz),
    FOREIGN KEY (ucet_id) REFERENCES restaurant_pos_ucty(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;""")
A("""CREATE TABLE IF NOT EXISTS restaurant_pos_platby (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ucet_id INT NOT NULL,
    castka DECIMAL(10,2) NOT NULL,
    zpusob ENUM('hotovost','karta','qr','online','poukaz','prevod') NOT NULL DEFAULT 'hotovost',
    zaplaceno_v DATETIME DEFAULT CURRENT_TIMESTAMP,
    doklad_cislo VARCHAR(40) NULL,
    poznamka VARCHAR(200) NULL,
    INDEX idx_ucet (ucet_id),
    FOREIGN KEY (ucet_id) REFERENCES restaurant_pos_ucty(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;""")
A('')
A('INSERT INTO restaurant_tables (id, nazev, mist, sekce, tvar, aktivni) VALUES')
th = []
for tid, nazev, mist, sekce, tvar in POS_TABLES:
    th.append(f"({tid}, '{esc(nazev)}', {mist}, '{esc(sekce)}', '{tvar}', 1)")
A(',\n'.join(th) + ';')
A('')

A('INSERT INTO restaurant_pos_ucty (id, stul_id, otevreno_v, zaplaceno_v, otevrel_jmeno, '
  'stav, suma_kc, suma_zaplaceno, pocet_hostu, objednavka_typ, cislo_dokladu) VALUES')
uh = []
for uid, stul, dni, hod, stav, hostu, typ, items, _pozn in POS_UCTY:
    suma = pos_total(items)
    open_dt = dtexpr(dni, hod)
    if stav == 'paid':
        pay_dt = dtexpr(dni, hod + 1)
        suma_zapl = suma
        doklad = f"'POS-DEMO-{uid:03d}'"
    else:
        pay_dt = 'NULL'
        suma_zapl = 0.00
        doklad = 'NULL'
    uh.append(f"({uid}, {stul}, {open_dt}, {pay_dt}, 'Demo Obsluha', "
              f"'{stav}', {suma:.2f}, {suma_zapl:.2f}, {hostu}, '{typ}', {doklad})")
A(',\n'.join(uh) + ';')
A('')

A('-- ── POS — položky účtenek ──')
A('INSERT INTO restaurant_pos_polozky (ucet_id, vyrobek_id, nazev, jednotkova_cena, mnozstvi, '
  'kategorie, kurz, stav, zdroj) VALUES')
ph = []
for uid, stul, dni, hod, stav, hostu, typ, items, _pozn in POS_UCTY:
    pol_stav = 'servirovano' if stav == 'paid' else 'objednano'
    for vid, qty in items:
        nazev, cena, sazba = V[vid]
        cena_s_dph = money(cena * (1 + sazba / 100.0))
        kat = POS_KAT.get(vid, 'Ostatní')
        ph.append(f"({uid}, {vid}, '{esc(nazev)}', {cena_s_dph:.2f}, {qty}, "
                  f"'{kat}', 1, '{pol_stav}', 'staff')")
A(',\n'.join(ph) + ';')
A('')

A('-- ── POS — platby (jen zaplacené účtenky; součet = suma účtu) ──')
A('INSERT INTO restaurant_pos_platby (ucet_id, castka, zpusob, zaplaceno_v, doklad_cislo) VALUES')
pay = []
for uid, stul, dni, hod, stav, hostu, typ, items, splitnote in POS_UCTY:
    if stav != 'paid':
        continue
    suma = pos_total(items)
    pay_dt = dtexpr(dni, hod + 1)
    doklad = f"POS-DEMO-{uid:03d}"
    if splitnote == 'split-card-cash':
        # rozděl platbu kartou + hotovostí
        cast1 = money(suma * 0.6)
        cast2 = money(suma - cast1)
        pay.append(f"({uid}, {cast1:.2f}, 'karta', {pay_dt}, '{doklad}')")
        pay.append(f"({uid}, {cast2:.2f}, 'hotovost', {pay_dt}, '{doklad}')")
    else:
        zpusob = splitnote if splitnote in ('card', 'cash', 'qr') else 'cash'
        zmap = {'card': 'karta', 'cash': 'hotovost', 'qr': 'qr'}
        pay.append(f"({uid}, {suma:.2f}, '{zmap[zpusob]}', {pay_dt}, '{doklad}')")
A(',\n'.join(pay) + ';')
A('')

# ── CISLOVANI — čítače navazují na nejvyšší doklad ──────────────────────────
A('-- ════════════════════════════════════════════════════════════')
A('-- 🔢 ČÍSELNÉ ŘADY — posledni = nejvyšší seedované číslo dokladu')
A('-- v2.9.104: typ=\'FA\' (kód volá dalsi_cislo(...,\'FA\',...)), predcisli s rokem')
A('-- ════════════════════════════════════════════════════════════')
A('INSERT INTO cislovani (typ, rok, posledni, predcisli) VALUES')
A(f"('OBJ', YEAR(CURDATE()), {len(ORDERS)}, CONCAT('OBJ-', YEAR(CURDATE()), '-')),")
A(f"('FA',  YEAR(CURDATE()), {len(INVOICED_ORDERS)}, CONCAT('FAK-', YEAR(CURDATE()), '-')),")
A(f"('DL',  YEAR(CURDATE()), {len(DELIVERED)}, CONCAT('DL-',  YEAR(CURDATE()), '-')),")
A(f"('VL',  YEAR(CURDATE()), {len(VYROBNI_LISTY)}, CONCAT('VL-',  YEAR(CURDATE()), '-'));")

print('\n'.join(lines))
