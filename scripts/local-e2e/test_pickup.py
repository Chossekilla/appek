#!/usr/bin/env python3
# E2E test vyzvednutí proti LOKÁLNÍ instalaci (php -S 127.0.0.1:8765, DB appek). Lokální testovací účet z poznámek projektu.
import json, os, sys, urllib.request, urllib.error, http.cookiejar, datetime, subprocess

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

st, j = req('admin_login.php', {'email': os.environ['T_EMAIL'], 'heslo': os.environ['T_PASS']})
check('login', st == 200, str(j)[:120])
st, j = req('whoami.php')
CSRF = (j.get('admin') or j).get('csrf_token', '') if isinstance(j, dict) else ''
check('csrf', bool(CSRF), str(j)[:120])

vyr = sql("SELECT id, nazev, cena_bez_dph FROM vyrobky WHERE aktivni=1 AND cena_bez_dph>0 LIMIT 1").split('\t')
vid, vnaz, vcena = int(vyr[0]), vyr[1], float(vyr[2])
cas = (datetime.datetime.now() + datetime.timedelta(minutes=45)).strftime('%Y-%m-%dT%H:%M')
pol = [{'vyrobek_id': vid, 'nazev': vnaz, 'jednotka': 'ks', 'mnozstvi': 2, 'cena_bez_dph': vcena, 'sazba_dph': 12}]
today = datetime.date.today().isoformat()

def trzba():
    st, j = req('admin_pos.php?action=uzaverka&date=' + today)
    t = j.get('total') or {}
    return float(t.get('trzba', 0) or 0)

print('== 1) NEZAPLACENÉ vyzvednutí')
t0 = trzba()
st, j = req('admin_pos.php?action=quick_order', {'polozky': pol, 'pos_typ': 'vyzvednuti', 'pos_payment': 'pri_vyzvednuti',
            'vyzvednuti_cas': cas, 'vyzvednuti_jmeno': 'Test Novák', 'vyzvednuti_tel': '+420 777 111 222', 'vyzvednuti_email': 'test@example.com'})
check('quick_order 200', st == 200, str(j)[:200]); oid = j.get('id'); celkem = j.get('celkem')
check('nezaplaceno flag', j.get('nezaplaceno') is True, j.get('nezaplaceno'))
check('bez auto účtenky', j.get('print_receipt_mode') == 'never', j.get('print_receipt_mode'))
row = sql(f"SELECT stav, pos_payment, vyzvednuti_jmeno, vyzvednuti_tel, vyzvednuti_email, DATE_FORMAT(vyzvednuti_cas,'%Y-%m-%dT%H:%i') FROM objednavky WHERE id={oid}").split('\t')
check('DB stav=nova, platba pri_vyzvednuti', row[0] == 'nova' and row[1] == 'pri_vyzvednuti', row)
check('DB údaje vyzvednutí', row[2:] == ['Test Novák', '+420 777 111 222', 'test@example.com', cas], row)
print('     kuchyně:', sql(f"SELECT GROUP_CONCAT(DISTINCT poznamka) FROM kitchen_queue WHERE objednavka_id={oid}")[:100] or '(auto-fire vypnutý / žádný záznam)')
st, j = req('admin_pos.php?action=pickup_list'); items = j.get('items', [])
it = next((x for x in items if str(x['id']) == str(oid)), None)
check('v seznamu k vyzvednutí', it is not None, [x['id'] for x in items][:5])
check('seznam: položky + zaplaceno=false', bool(it) and it.get('zaplaceno') is False and len(it.get('polozky', [])) == 1, it)
t1 = trzba()
check('uzávěrka: nezaplacené NEzapočteno', abs(t1 - t0) < 0.01, (t0, t1))
st, j = req('admin_pos.php?action=pickup_ready', {'id': oid}); check('připraveno 200', st == 200, j)
check('DB připraveno nastaveno', sql(f"SELECT vyzvednuti_pripraveno IS NOT NULL FROM objednavky WHERE id={oid}") == '1')
st, j = req('admin_pos.php?action=pickup_complete', {'id': oid}); check('vydat BEZ platby u nezaplaceného → 400', st == 400, (st, j))
st, j = req('admin_pos.php?action=pickup_complete', {'id': oid, 'pos_payment': 'karta'}); check('vydat + zaplatit kartou 200', st == 200, (st, j))
row = sql(f"SELECT stav, pos_payment, vyzvednuto_kdy IS NOT NULL, objednano_puvodne IS NOT NULL FROM objednavky WHERE id={oid}").split('\t')
check('DB zaplaceno/karta/vydáno/původní čas', row == ['zaplaceno', 'karta', '1', '1'], row)
t2 = trzba()
check('uzávěrka: po zaplacení započteno', abs((t2 - t1) - float(celkem)) < 0.02, (t1, t2, celkem))
st, j = req('admin_pos.php?action=pickup_list'); check('po vydání zmizelo ze seznamu', str(oid) not in [str(x['id']) for x in j.get('items', [])])

print('== 2) ZAPLACENÉ předem')
st, j = req('admin_pos.php?action=quick_order', {'polozky': pol, 'pos_typ': 'vyzvednuti', 'pos_payment': 'hotove', 'vyzvednuti_cas': cas, 'vyzvednuti_jmeno': 'Test Předem'})
check('quick_order 200', st == 200, j); oid2 = j.get('id')
check('DB stav zaplaceno/hotove', sql(f"SELECT CONCAT(stav,'/',pos_payment) FROM objednavky WHERE id={oid2}") == 'zaplaceno/hotove')
st, j = req('admin_pos.php?action=pickup_cancel', {'id': oid2}); check('zrušit zaplacené → 409', st == 409, (st, j))
st, j = req('admin_pos.php?action=pickup_complete', {'id': oid2}); check('vydat zaplacené 200', st == 200, (st, j))
check('DB vydáno, platba beze změny', sql(f"SELECT CONCAT(stav,'/',pos_payment,'/',vyzvednuto_kdy IS NOT NULL) FROM objednavky WHERE id={oid2}") == 'zaplaceno/hotove/1')

print('== 3) ZRUŠENÍ nezaplaceného')
st, j = req('admin_pos.php?action=quick_order', {'polozky': pol, 'pos_typ': 'vyzvednuti', 'pos_payment': 'pri_vyzvednuti', 'vyzvednuti_tel': '777000111'})
oid3 = j.get('id')
st, j = req('admin_pos.php?action=pickup_cancel', {'id': oid3}); check('zrušit nezaplacené 200', st == 200, (st, j))
check('DB zrusena', sql(f"SELECT stav FROM objednavky WHERE id={oid3}") == 'zrusena')

print('== 4) OCHRANY + ostatní typy beze změny')
st, j = req('admin_pos.php?action=quick_order', {'polozky': pol, 'pos_typ': 'sebou', 'pos_payment': 'pri_vyzvednuti'})
check('pri_vyzvednuti u S sebou → 400', st == 400, (st, j))
st, j = req('admin_pos.php?action=quick_order', {'polozky': pol, 'pos_typ': 'sebou', 'pos_payment': 'hotove'})
check('S sebou hotově = zaplaceno', st == 200 and sql(f"SELECT stav FROM objednavky WHERE id={j.get('id')}") == 'zaplaceno', (st, j))

print(f'\nVÝSLEDEK: {ok} OK / {fail} FAIL')
sys.exit(1 if fail else 0)
