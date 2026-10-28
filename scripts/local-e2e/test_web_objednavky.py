#!/usr/bin/env python3
# E2E online objednávek (/objednat/) proti LOKÁLNÍ instalaci (php -S 127.0.0.1:8765, DB appek).
# Dočasně zapne balíček Restaurace + web_objednavky, na konci vrátí původní nastavení.
# Admin část (nastavení, vydání na POS) potřebuje T_EMAIL/T_PASS lokálního testovacího účtu.
import json, os, sys, urllib.request, urllib.error, http.cookiejar, subprocess

BASE = os.environ.get('BASE', 'http://127.0.0.1:8765')
cj = http.cookiejar.CookieJar()
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
CSRF = ''
ok = fail = 0

def req(path, body=None, method=None):
    data = json.dumps(body).encode() if body is not None else None
    r = urllib.request.Request(BASE + '/api/' + path, data=data, method=method or ('POST' if body is not None else 'GET'))
    r.add_header('Content-Type', 'application/json'); r.add_header('Accept', 'application/json')
    if CSRF: r.add_header('X-CSRF-Token', CSRF)
    try:
        with op.open(r, timeout=30) as resp: return resp.status, json.loads(resp.read() or b'{}')
    except urllib.error.HTTPError as e:
        try: return e.code, json.loads(e.read() or b'{}')
        except Exception: return e.code, {}

def check(name, cond, extra=''):
    global ok, fail
    if cond: ok += 1; print('  OK  ', name)
    else: fail += 1; print('  FAIL', name, extra)

def sql(q):
    return subprocess.run(['/Applications/XAMPP/xamppfiles/bin/mysql', '-uroot', '-N', '-e', q, 'appek'], capture_output=True, text=True).stdout.strip()

def setting(klic, val):
    v = val.replace("'", "''")
    sql(f"INSERT INTO nastaveni (klic, hodnota) VALUES ('{klic}', '{v}') ON DUPLICATE KEY UPDATE hodnota = '{v}'")

def login():
    global CSRF
    req('admin_login.php', {'email': os.environ['T_EMAIL'], 'heslo': os.environ['T_PASS']})
    st, j = req('whoami.php'); CSRF = (j.get('admin') or j).get('csrf_token', '')
    return bool(CSRF)

def anon():
    global CSRF
    CSRF = ''; cj.clear()

orig_pk = sql("SELECT hodnota FROM nastaveni WHERE klic='packages_enabled'")
orig_wo = sql("SELECT hodnota FROM nastaveni WHERE klic='web_objednavky'")
try:
    pk = json.loads(orig_pk or '{}'); pk['restaurace'] = True
    setting('packages_enabled', json.dumps(pk))
    sql("DELETE FROM nastaveni WHERE klic='web_objednavky'")
    if sql("SHOW TABLES LIKE 'web_objednavky_rl'"): sql("DELETE FROM web_objednavky_rl")

    print('== 1) vypnuto = nepřijímá')
    st, j = req('web_objednavky.php?action=info'); check('info 200, aktivni=false', st == 200 and j.get('aktivni') is False, (st, j))
    st, j = req('web_objednavky.php?action=order', {'polozky': [{'id': 1, 'mnozstvi': 1}]}); check('order při vypnutí → 403', st == 403, (st, j))

    print('== 2) admin nastavení')
    st, j = req('web_objednavky.php?action=settings', {'zapnuto': True}); check('settings bez přihlášení → 401/403', st in (401, 403), st)
    check('login', login())
    cfg = {'zapnuto': True, 'vyzvednuti': True, 'rozvoz': True, 'rozvoz_cena': 59, 'rozvoz_zdarma_od': 100000, 'rozvoz_psc': '110, 120',
           'min_objednavka': 0, 'priprava_min': 20, 'od': '00:00', 'do': '23:59', 'dny': [1, 2, 3, 4, 5, 6, 7], 'predobjednavka_dni': 2, 'zprava': 'Test'}
    st, j = req('web_objednavky.php?action=settings', cfg); check('settings uloženo', st == 200 and j['settings']['zapnuto'] is True and j['settings']['rozvoz_cena'] == 59, (st, j))
    anon()

    print('== 3) veřejné info + menu')
    st, info = req('web_objednavky.php?action=info'); check('aktivni=true + okna', info.get('aktivni') is True and len(info.get('okna', [])) >= 1, info)
    st, menu = req('web_objednavky.php?action=menu'); vyr = menu.get('vyrobky', [])
    check('menu má výrobky s cenou s DPH', len(vyr) > 1 and all(v['cena'] > 0 for v in vyr), len(vyr))
    check('menu neprozrazuje cenu bez DPH', all('cena_bez_dph' not in v for v in vyr))
    v1, v2 = vyr[0], vyr[1]
    okno = info['okna'][-1]; cas = okno['den'] + 'T' + okno['do']

    print('== 4) validace')
    base = {'typ': 'vyzvednuti', 'cas': cas, 'jmeno': 'Test Online', 'telefon': '777 123 456', 'email': 'online@example.com', 'souhlas': True,
            'polozky': [{'id': v1['id'], 'mnozstvi': 2}, {'id': v2['id'], 'mnozstvi': 1}]}
    st, j = req('web_objednavky.php?action=order', {**base, 'web': 'bot'}); check('honeypot → 400', st == 400, (st, j))
    st, j = req('web_objednavky.php?action=order', {**base, 'telefon': '12'}); check('krátký telefon → 400', st == 400, (st, j))
    st, j = req('web_objednavky.php?action=order', {**base, 'souhlas': False}); check('bez souhlasu → 400', st == 400, (st, j))
    st, j = req('web_objednavky.php?action=order', {**base, 'cas': '2001-01-01T12:00'}); check('čas mimo okno → 400', st == 400, (st, j))
    st, j = req('web_objednavky.php?action=order', {**base, 'polozky': [{'id': 99999999, 'mnozstvi': 1}]}); check('neznámý výrobek → 409', st == 409, (st, j))
    st, j = req('web_objednavky.php?action=order', {**base, 'typ': 'rozvoz', 'adresa': 'Hlavní 1', 'psc': '60200', 'mesto': 'Brno'}); check('PSČ mimo zónu → 400', st == 400, (st, j))

    print('== 5) vyzvednutí — vytvoření + ceny ze serveru')
    st, j = req('web_objednavky.php?action=order', {**base, 'polozky': [{'id': v1['id'], 'mnozstvi': 2, 'cena': 0.01}, {'id': v2['id'], 'mnozstvi': 1}]})
    check('order 200, číslo WEB…', st == 200 and str(j.get('cislo', '')).startswith('WEB'), (st, j))
    tok = j.get('token'); ocek = round(2 * v1['cena'] + v2['cena'], 2)
    check('celkem = ceny serveru (podstrčená cena ignorována)', abs(j.get('celkem', 0) - ocek) < 0.05, (j.get('celkem'), ocek))
    row = sql(f"SELECT id, puvod, typ, pos_typ, pos_payment, stav, vyzvednuti_jmeno, DATE(datum_dodani)=DATE(vyzvednuti_cas) FROM objednavky WHERE web_token='{tok}'").split('\t')
    oid = row[0]
    check('DB puvod/typ/pos_typ/platba/stav', row[1:6] == ['web', 'web', 'vyzvednuti', 'pri_vyzvednuti', 'nova'], row)
    check('DB jméno + datum_dodani = den vyzvednutí', row[6] == 'Test Online' and row[7] == '1', row)
    check('2 položky vloženy', sql(f"SELECT COUNT(*) FROM objednavky_polozky WHERE objednavka_id={oid} AND vyrobek_id IS NOT NULL") == '2')
    print('     kuchyně:', sql(f"SELECT COUNT(*) FROM kitchen_queue WHERE objednavka_id={oid}"), 'řádků')
    st, s = req('web_objednavky.php?action=stav&t=' + tok); check('stav: přijato, nezaplaceno', s.get('krok') in ('prijato', 'pripravuje') and s.get('zaplaceno') is False, s)
    st, s = req('web_objednavky.php?action=stav&t=' + '0' * 24); check('cizí token → 404', st == 404, st)

    print('== 6) rozvoz s dopravou')
    st, j = req('web_objednavky.php?action=order', {**base, 'typ': 'rozvoz', 'adresa': 'Hlavní 1', 'psc': '120 00', 'mesto': 'Praha'})
    check('rozvoz 200 + doprava 59', st == 200 and abs(j.get('doprava', 0) - 59) < 0.01, (st, j))
    rid = sql(f"SELECT id FROM objednavky WHERE web_token='{j.get('token')}'")
    check('řádek Doprava v položkách', sql(f"SELECT COUNT(*) FROM objednavky_polozky WHERE objednavka_id={rid} AND vyrobek_id IS NULL") == '1')
    check('web_adresa uložena', 'Praha' in sql(f"SELECT web_adresa FROM objednavky WHERE id={rid}"))
    check('součet hlavičky = součet řádků', sql(f"SELECT ABS(o.castka_celkem - (SELECT SUM(ROUND(p.mnozstvi*p.cena_bez_dph*(1+p.sazba_dph/100),2)) FROM objednavky_polozky p WHERE p.objednavka_id=o.id)) < 0.1 FROM objednavky o WHERE o.id={rid}") == '1')

    print('== 7) vydání na pokladně (pickup) → zaplaceno')
    check('login', login())
    st, j = req('admin_pos.php?action=pickup_list'); check('web objednávka v seznamu k vyzvednutí', oid in [str(x['id']) for x in j.get('items', [])], [x['id'] for x in j.get('items', [])][:5])
    st, j = req('admin_pos.php?action=pickup_ready', {'id': int(oid)}); check('připraveno 200', st == 200, (st, j))
    st, s = req('web_objednavky.php?action=stav&t=' + tok); check('stav zákazníka: připraveno', s.get('krok') == 'pripraveno', s)
    st, j = req('admin_pos.php?action=pickup_complete', {'id': int(oid), 'pos_payment': 'hotove'}); check('vydat + zaplatit 200', st == 200, (st, j))
    st, s = req('web_objednavky.php?action=stav&t=' + tok); check('stav: hotovo + zaplaceno', s.get('krok') == 'hotovo' and s.get('zaplaceno') is True, s)

    print('== 8) limit na IP')
    anon()
    codes = [req('web_objednavky.php?action=order', base)[0] for _ in range(6)]
    check('nad 6 objednávek / 30 min z IP → 429', 429 in codes, codes)
finally:
    if orig_pk: setting('packages_enabled', orig_pk)
    if orig_wo: setting('web_objednavky', orig_wo)
    else: sql("DELETE FROM nastaveni WHERE klic='web_objednavky'")
    print('nastavení obnoveno')

print(f'\nVÝSLEDEK: {ok} OK / {fail} FAIL')
sys.exit(1 if fail else 0)
