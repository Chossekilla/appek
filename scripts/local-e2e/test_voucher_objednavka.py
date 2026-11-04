#!/usr/bin/env python3
# E2E voucheru na objednávce v adminu (v3.0.631) proti LOKÁLNÍ instalaci (php -S 127.0.0.1:8765, DB appek).
# Kontroluje uplatnění při vytvoření i dodatečně, přepočet při změně položek, vrácení při stornu/odebrání,
# omezení (kanál, odběratel, DL) a invarianty: hlavička = součet řádků, kniha voucheru = řádky voucheru.
# Testová data nechává (vouchery na konci zruší). Potřebuje T_EMAIL/T_PASS lokálního admina.
import json, os, sys, urllib.request, urllib.error, http.cookiejar, subprocess

BASE = os.environ.get('BASE', 'http://127.0.0.1:8765')
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
CSRF = ''
ok = fail = 0

def req(path, body=None, method=None):
    r = urllib.request.Request(BASE + '/api/' + path, data=json.dumps(body).encode() if body is not None else None,
                               method=method or ('POST' if body is not None else 'GET'))
    r.add_header('Content-Type', 'application/json')
    if CSRF: r.add_header('X-CSRF-Token', CSRF)
    try:
        with op.open(r, timeout=30) as x: return x.status, json.loads(x.read() or b'{}')
    except urllib.error.HTTPError as e:
        try: return e.code, json.loads(e.read() or b'{}')
        except Exception: return e.code, {}

def check(name, cond, extra=''):
    global ok, fail
    if cond: ok += 1; print('  OK  ', name)
    else: fail += 1; print('  FAIL', name, extra)

def sql(q):
    return subprocess.run(['/Applications/XAMPP/xamppfiles/bin/mysql', '-uroot', '-N', '-e', q, 'appek'], capture_output=True, text=True).stdout.strip()

def voucher(**kw):
    st, j = req('admin_vouchers.php?action=create', {'poznamka': 'e2e objednavka v631', **kw})
    assert st == 201, (st, j)
    return j['vytvoreno'][0]['kod']

def vdet(kod): return req('admin_vouchers.php?kod=' + kod)[1].get('voucher', {})

def invarianty(oid, nazev):
    hl = sql(f"SELECT ABS(o.castka_celkem - (SELECT COALESCE(SUM(ROUND(p.mnozstvi*p.cena_bez_dph*(1+p.sazba_dph/100),2)),0) FROM objednavky_polozky p WHERE p.objednavka_id=o.id)) < 0.05 FROM objednavky o WHERE o.id={oid}")
    check(f'{nazev}: hlavička = součet řádků', hl == '1', hl)
    radky = float(sql(f"SELECT COALESCE(-SUM(mnozstvi*cena_bez_dph*(1+sazba_dph/100)),0) FROM objednavky_polozky WHERE objednavka_id={oid} AND voucher_id IS NOT NULL") or 0)
    kniha = float(sql(f"SELECT COALESCE(-SUM(castka),0) FROM voucher_pohyby WHERE objednavka_id={oid}") or 0)
    check(f'{nazev}: kniha voucheru = řádky voucheru ({kniha} ~ {radky:.2f})', abs(kniha - radky) < 0.05)

def detail(oid): return req(f'admin_objednavky.php?id={oid}')[1]

def zbozi(oid): return float(sql(f"SELECT ROUND(SUM(mnozstvi*cena_bez_dph*(1+sazba_dph/100)),2) FROM objednavky_polozky WHERE objednavka_id={oid} AND voucher_id IS NULL") or 0)

req('admin_login.php', {'email': os.environ['T_EMAIL'], 'heslo': os.environ['T_PASS']})
st, j = req('whoami.php'); CSRF = (j.get('admin') or j).get('csrf_token', '')
check('login', bool(CSRF))

odb, odb2 = [int(x) for x in sql("SELECT id FROM odberatele WHERE COALESCE(blokovan,0)=0 ORDER BY id LIMIT 2").split()]
cenik = req(f'cenik_odberatele.php?odberatel_id={odb}')[1].get('vyrobky', [])
sazby = {}
for v in cenik: sazby.setdefault(float(v.get('dph') or 0), v)
vyr = list(sazby.values())
check('ceník má výrobky', len(vyr) >= 1, list(sazby))
# 2 sazby DPH: druhý výrobek s jinou sazbou, jinak volný řádek s 21 % (kvůli rozpočtu slevy po sazbách)
druhy = {'vyrobek_id': vyr[1]['id'], 'mnozstvi': 3} if len(vyr) > 1 else \
        {'vyrobek_id': None, 'vyrobek_nazev': 'E2E nápoj 21 %', 'jednotka': 'ks', 'mnozstvi': 3, 'cena_bez_dph': 40, 'sazba_dph': 21 if float(vyr[0].get('dph') or 0) != 21 else 12}
polozky = [{'vyrobek_id': vyr[0]['id'], 'mnozstvi': 4}, druhy]
def nova(**extra):
    return req('admin_objednavky.php', {'action': 'vytvorit', 'odberatel_id': odb, 'datum_dodani': '2030-01-15', 'polozky': polozky, **extra})

print('== 1) Kč voucher (platba) rovnou v nové objednávce')
kod1 = voucher(typ='voucher', hodnota=100, kanaly=['admin'], chovani='platba')
st, j = nova(voucher_kod=kod1); oid1 = j.get('id')
check('vytvořeno s voucherem', st == 201 and oid1, (st, j))
d = detail(oid1)
check('detail: voucher −100, platba', d.get('voucher', {}).get('uplatneno') == 100 and d['voucher']['chovani'] == 'platba', d.get('voucher'))
check('řádek „Uhrazeno voucherem" se sazbou 0 %', sql(f"SELECT COUNT(*) FROM objednavky_polozky WHERE objednavka_id={oid1} AND voucher_id IS NOT NULL AND sazba_dph=0 AND cena_bez_dph=-100") == '1')
check('DPH objednávky nezměněno (platba)', abs(float(d['castka_dph']) - float(sql(f"SELECT ROUND(SUM(mnozstvi*cena_bez_dph*sazba_dph/100),2) FROM objednavky_polozky WHERE objednavka_id={oid1} AND voucher_id IS NULL"))) < 0.02)
check('zůstatek voucheru 0, vyčerpaný', float(vdet(kod1)['zustatek']) == 0 and vdet(kod1)['stav_aktualni'] == 'vycerpany')
invarianty(oid1, 'po vytvoření')

print('== 2) snížení množství pod hodnotu voucheru → částečné vrácení')
pid = sql(f"SELECT id FROM objednavky_polozky WHERE objednavka_id={oid1} AND voucher_id IS NULL ORDER BY id LIMIT 1")
pid2 = sql(f"SELECT id FROM objednavky_polozky WHERE objednavka_id={oid1} AND voucher_id IS NULL ORDER BY id DESC LIMIT 1")
st, j = req('admin_objednavky.php', {'id': oid1, 'polozky_zmeny': [{'polozka_id': int(pid), 'mnozstvi': 0}, {'polozka_id': int(pid2), 'mnozstvi': 1}]}, 'PUT')
z = zbozi(oid1)
check('PUT 200', st == 200, (st, j))
check(f'voucher čerpá jen hodnotu zboží ({z})', abs(detail(oid1)['voucher']['uplatneno'] - min(100, z)) < 0.01, detail(oid1).get('voucher'))
check('rozdíl vrácen na voucher', abs(float(vdet(kod1)['zustatek']) - round(100 - min(100, z), 2)) < 0.01, vdet(kod1)['zustatek'])
vpid = int(sql(f"SELECT id FROM objednavky_polozky WHERE objednavka_id={oid1} AND voucher_id IS NOT NULL LIMIT 1"))
st, j = req('admin_objednavky.php', {'id': oid1, 'polozky_zmeny': [{'polozka_id': vpid, 'mnozstvi': 5}]}, 'PUT')
check('řádek voucheru nejde ručně změnit (ignorováno)', abs(detail(oid1)['voucher']['uplatneno'] - min(100, z)) < 0.01)
invarianty(oid1, 'po změně množství')

print('== 3) storno → voucher celý zpět')
st, j = req('admin_objednavky.php', {'id': oid1, 'stav': 'zrusena'}, 'PUT')
check('storno 200', st == 200, (st, j))
check('zůstatek zpět 100, aktivní', float(vdet(kod1)['zustatek']) == 100 and vdet(kod1)['stav_aktualni'] == 'aktivni', vdet(kod1))
check('řádky voucheru pryč, detail bez voucheru', sql(f"SELECT COUNT(*) FROM objednavky_polozky WHERE objednavka_id={oid1} AND voucher_id IS NOT NULL") == '0' and not detail(oid1).get('voucher'))
st, j = req('admin_objednavky.php', {'action': 'voucher_uplatnit', 'objednavka_id': oid1, 'kod': kod1})
check('na stornovanou objednávku nejde uplatnit → 409', st == 409, (st, j))

print('== 4) % sleva dodatečně na existující objednávce (po sazbách DPH)')
st, j = nova(); oid2 = j['id']
pred = float(detail(oid2)['castka_celkem'])
kod2 = voucher(typ='sleva', sleva_pct=10)
st, j = req('admin_objednavky.php', {'action': 'voucher_uplatnit', 'objednavka_id': oid2, 'kod': kod2})
check('uplatnit 200, −10 %', st == 200 and abs(j.get('uplatneno', 0) - round(pred * 0.1, 2)) < 0.02, (st, j, pred))
check('2 řádky slevy (po sazbách) se sazbou > 0', sql(f"SELECT COUNT(*) FROM objednavky_polozky WHERE objednavka_id={oid2} AND voucher_id IS NOT NULL AND sazba_dph>0") == '2')
check('celkem = 90 %', abs(float(detail(oid2)['castka_celkem']) - round(pred * 0.9, 2)) < 0.03, detail(oid2)['castka_celkem'])
check('% voucher vyčerpaný', vdet(kod2)['stav_aktualni'] == 'vycerpany')
st, j = req('admin_objednavky.php', {'action': 'pridat_polozku', 'objednavka_id': oid2, 'vyrobek_id': vyr[0]['id'], 'mnozstvi': 2})
z2 = zbozi(oid2)
check('přidání položky → sleva dopočtena z nového zboží', abs(detail(oid2)['voucher']['uplatneno'] - round(z2 * 0.1, 2)) < 0.02, (detail(oid2).get('voucher'), z2))
invarianty(oid2, '% sleva')
st, j = req('admin_objednavky.php', {'action': 'voucher_uplatnit', 'objednavka_id': oid2, 'kod': kod1})
check('druhý voucher na stejnou objednávku → 409', st == 409 and 'jiný voucher' in j.get('error', ''), (st, j))
st, j = req('admin_objednavky.php', {'action': 'voucher_odebrat', 'objednavka_id': oid2})
check('odebrat 200 → % voucher znovu aktivní, celkem = zboží', st == 200 and vdet(kod2)['stav_aktualni'] == 'aktivni' and abs(float(detail(oid2)['castka_celkem']) - z2) < 0.03, (st, j))
invarianty(oid2, 'po odebrání')

print('== 5) omezení')
kod3 = voucher(typ='voucher', hodnota=50, kanaly=['pos', 'b2b'])
st, j = req('admin_objednavky.php', {'action': 'voucher_uplatnit', 'objednavka_id': oid2, 'kod': kod3})
check('voucher jen pro pokladnu/B2B → 409 s vysvětlením', st == 409 and 'Platí jen' in j.get('error', ''), (st, j))
kod4 = voucher(typ='voucher', hodnota=50, odberatel_id=odb2)
st, j = req('admin_objednavky.php', {'action': 'voucher_uplatnit', 'objednavka_id': oid2, 'kod': kod4})
check('voucher jiného odběratele → 409', st == 409 and 'jiného odběratele' in j.get('error', ''), (st, j))
pocet = sql("SELECT COUNT(*) FROM objednavky")
st, j = nova(voucher_kod='XX-NEEXISTUJE')
check('neplatný kód v nové objednávce → 400 a objednávka NEVZNIKNE', st == 400 and sql("SELECT COUNT(*) FROM objednavky") == pocet, (st, j))
kody = [kod1, kod2, kod3, kod4]
sdl = sql("SELECT o.id FROM objednavky o JOIN dodaci_listy d ON d.objednavka_id=o.id WHERE o.stav<>'zrusena' AND COALESCE(o.puvod,'interni') IN ('interni','b2b') LIMIT 1")
if sdl:
    kod5 = voucher(typ='voucher', hodnota=10); kody.append(kod5)
    st, j = req('admin_objednavky.php', {'action': 'voucher_uplatnit', 'objednavka_id': int(sdl), 'kod': kod5})
    check('objednávka s DL → 409', st == 409 and 'dodací list' in j.get('error', ''), (st, j))
spos = sql("SELECT id FROM objednavky WHERE puvod='pos' AND stav<>'zrusena' AND NOT EXISTS (SELECT 1 FROM dodaci_listy d WHERE d.objednavka_id=objednavky.id) LIMIT 1")
if spos:
    st, j = req('admin_objednavky.php', {'action': 'voucher_uplatnit', 'objednavka_id': int(spos), 'kod': kod2})
    check('objednávka z pokladny → 409', st == 409 and 'pokladn' in j.get('error', ''), (st, j))

print('== 6) faktura z objednávky s voucherem (DL + faktura převezmou řádky slevy)')
st, j = nova(); oid6 = j['id']
kod6 = voucher(typ='sleva', sleva_pct=15); kody.append(kod6)
req('admin_objednavky.php', {'action': 'voucher_uplatnit', 'objednavka_id': oid6, 'kod': kod6})
celkem6 = float(detail(oid6)['castka_celkem'])
st, j = req('faktura.php?action=vytvor', {'objednavka_id': oid6})
fid = j.get('faktura_id')
check('faktura vytvořena', st in (200, 201) and fid, (st, j))
if fid:
    fa = float(sql(f"SELECT castka_celkem FROM faktury WHERE id={fid}") or 0)
    dl = float(sql(f"SELECT castka_celkem FROM dodaci_listy WHERE objednavka_id={oid6} LIMIT 1") or 0)
    check(f'faktura = DL = objednávka ({fa} / {dl} / {celkem6})', abs(fa - celkem6) < 0.05 and abs(dl - celkem6) < 0.05)
    # faktura z DL tiskne řádky navázaného DL (faktura_polozky se materializují jen u dobropisů)
    check('DL faktury obsahuje řádky slevy voucheru (po sazbách)', int(sql(f"SELECT COUNT(*) FROM dodaci_list_polozky p JOIN faktury_dodaci_listy f ON f.dodaci_list_id=p.dodaci_list_id WHERE f.faktura_id={fid} AND p.vyrobek_nazev LIKE '%voucher {kod6}%'") or 0) == 2)
    html = op.open(urllib.request.Request(f'{BASE}/api/faktura.php?id={fid}'), timeout=30).read().decode('utf-8', 'replace')
    check('vytištěná faktura ukazuje voucher', kod6 in html)
    st, j = req('admin_objednavky.php', {'action': 'voucher_odebrat', 'objednavka_id': oid6})
    check('po vystavení DL voucher nejde odebrat → 409', st == 409, (st, j))

for k in kody:
    req('admin_vouchers.php?action=deactivate', {'id': vdet(k).get('id')})
print(f'\nVÝSLEDEK: {ok} OK / {fail} FAIL')
sys.exit(1 if fail else 0)
