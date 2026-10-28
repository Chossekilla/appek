#!/usr/bin/env python3
# E2E test vlastního rozvozu + příjmu objednávky z Woltu (LOKÁLNÍ instalace 127.0.0.1:8765, DB appek).
import json, os, sys, urllib.request, urllib.error, http.cookiejar, subprocess, hmac, hashlib, time
BASE = 'http://127.0.0.1:8765'
cj = http.cookiejar.CookieJar(); op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
CSRF = ''; ok = fail = 0
def req(path, body=None, method=None, headers=None, raw=None):
    data = raw if raw is not None else (json.dumps(body).encode() if body is not None else None)
    r = urllib.request.Request(BASE + '/api/' + path, data=data, method=method or ('POST' if data is not None else 'GET'))
    r.add_header('Content-Type', 'application/json'); r.add_header('Accept', 'application/json')
    if CSRF: r.add_header('X-CSRF-Token', CSRF)
    for k, v in (headers or {}).items(): r.add_header(k, v)
    try:
        with op.open(r, timeout=30) as resp: return resp.status, json.loads(resp.read() or b'{}')
    except urllib.error.HTTPError as e:
        try: return e.code, json.loads(e.read() or b'{}')
        except Exception: return e.code, {}
def check(n, c, x=''):
    global ok, fail
    if c: ok += 1; print('  OK  ', n)
    else: fail += 1; print('  FAIL', n, str(x)[:200])
def sql(q): return subprocess.run(['/Applications/XAMPP/xamppfiles/bin/mysql', '-uroot', '-N', '-e', q, 'appek'], capture_output=True, text=True).stdout.strip()

st, j = req('admin_login.php', {'email': os.environ['T_EMAIL'], 'heslo': os.environ['T_PASS']}); check('login', st == 200)
st, j = req('whoami.php'); CSRF = (j.get('admin') or j).get('csrf_token', '')
vid, vnaz, vcena = sql("SELECT id, nazev, cena_bez_dph FROM vyrobky WHERE aktivni=1 AND cena_bez_dph>0 LIMIT 1").split('\t')
pol = [{'vyrobek_id': int(vid), 'nazev': vnaz, 'jednotka': 'ks', 'mnozstvi': 3, 'cena_bez_dph': float(vcena), 'sazba_dph': 12}]
tag = 'TROZ' + str(int(time.time()))[-6:]

print('== A) vlastní kurýr + rozvoz z pokladny')
st, j = req('admin_couriers.php?action=courier', {'jmeno': 'Kurýr ' + tag, 'telefon': '+420 700 000 001', 'vozidlo': 'auto', 'zona_obslazi': 'Praha 1-3', 'aktivni': 1})
check('kurýr uložen', st == 200 and j.get('id'), j); kid = j.get('id')
st, j = req('admin_pos.php?action=quick_order', {'polozky': pol, 'pos_typ': 'rozvoz', 'pos_payment': 'hotove', 'poznamka': tag})
check('objednávka na rozvoz', st == 200, j); oid = j.get('id')
check('rozvoz čeká (stav nova)', sql(f"SELECT stav FROM objednavky WHERE id={oid}") == 'nova')
st, j = req('admin_couriers.php?action=delivery', {'courier_id': kid, 'objednavka_id': oid, 'adresa': 'Vodičkova 1', 'psc': '11000', 'mesto': 'Praha', 'kontakt_jmeno': 'Zákazník ' + tag, 'kontakt_telefon': '777123456', 'cena_kc': 69})
check('rozvoz přiřazen kurýrovi', st == 200 and j.get('id'), j); did = j.get('id')
st, j = req('admin_couriers.php')
akt = json.dumps(j, ensure_ascii=False)
check('přehled vidí aktivní rozvoz', ('Vodičkova 1' in akt), list(j.keys()) if isinstance(j, dict) else j)
for stav, obj in [('vyzvednuto', 'expedovana'), ('na_ceste', 'expedovana'), ('doruceno', 'dorucena')]:
    st, j = req('admin_couriers.php?action=delivery_status', {'id': did, 'stav': stav})
    check(f'stav {stav} → objednávka {obj}', st == 200 and sql(f"SELECT stav FROM objednavky WHERE id={oid}") == obj, (st, j, sql(f"SELECT stav FROM objednavky WHERE id={oid}")))
check('čas doručení zapsán', sql(f"SELECT cas_doruceno IS NOT NULL FROM courier_deliveries WHERE id={did}") == '1')
st, j = req('admin_couriers.php?action=delivery_status', {'id': did, 'stav': 'nesmysl'}); check('neplatný stav → 400', st == 400, st)
st, j = req('admin_pos.php?action=quick_order', {'polozky': pol, 'pos_typ': 'rozvoz', 'pos_payment': 'hotove', 'poznamka': tag + '-zrus'}); oid2 = j.get('id')
st, j = req('admin_couriers.php?action=delivery', {'courier_id': kid, 'objednavka_id': oid2, 'adresa': 'Spálená 5'}); did2 = j.get('id')
st, j = req('admin_couriers.php?action=delivery_status', {'id': did2, 'stav': 'zruseno'})
check('zrušený rozvoz → objednávka zrušena', sql(f"SELECT stav FROM objednavky WHERE id={oid2}") == 'zrusena')
st, j = req('admin_couriers.php?action=delivery', {'courier_id': kid, 'adresa': ''}); check('rozvoz bez adresy → 400', st == 400, st)
st, j = req('admin_rozvozy.php'); check('trasy rozvozu (admin_rozvozy) 200', st == 200, (st, str(j)[:120]))

print('== B) příjem objednávky z Woltu (webhook s podpisem)')
secret = 'test-secret-' + tag
st, j = req('admin_couriers.php?action=integration', {'sluzba': 'wolt', 'povolena': 1, 'api_key': secret, 'store_id': 'store-' + tag})
check('integrace Wolt uložena', st == 200, j)
ext = 'wolt-' + tag
payload = {'event_type': 'order.created', 'order': {'id': ext, 'price': {'amount': 25900}, 'consumer': {'first_name': 'Eva', 'last_name': 'Wolt', 'phone_number': '+420777888999'},
           'delivery': {'type': 'homedelivery', 'location': {'street_address': 'Národní 10', 'city': 'Praha', 'postal_code': '11000'}},
           'items': [{'name': vnaz, 'count': 2, 'unit_price': 12950}], 'consumer_comment': 'Zvonit 2x'}}
raw = json.dumps(payload).encode()
st, j = req('wolt_webhook.php', raw=raw); check('bez podpisu → 401', st == 401, (st, j))
st, j = req('wolt_webhook.php', raw=raw, headers={'X-Wolt-Signature': 'bad'}); check('špatný podpis → 401', st == 401, (st, j))
sig = hmac.new(secret.encode(), raw, hashlib.sha256).hexdigest()
st, j = req('wolt_webhook.php', raw=raw, headers={'X-Wolt-Signature': sig}); check('správný podpis → přijato', st == 200, (st, j))
row = sql(f"SELECT o.id, o.puvod, o.stav, o.castka_celkem FROM objednavky o JOIN delivery_external_orders d ON d.objednavka_id=o.id WHERE d.external_id='{ext}' LIMIT 1").split('\t')
check('objednávka z Woltu založená (puvod wolt)', len(row) == 4 and row[1] == 'wolt', row)
if len(row) == 4:
    wid = row[0]
    print('     stav', row[2], 'částka', row[3], 'položek', sql(f"SELECT COUNT(*) FROM objednavky_polozky WHERE objednavka_id={wid}"))
    check('položky se spárovaly s výrobkem (kvůli odpisu)', sql(f"SELECT COUNT(*) FROM objednavky_polozky WHERE objednavka_id={wid} AND vyrobek_id IS NOT NULL") != '0',
          sql(f"SELECT vyrobek_id, vyrobek_nazev FROM objednavky_polozky WHERE objednavka_id={wid}"))
    print('     kuchyně:', sql(f"SELECT COUNT(*) FROM kitchen_queue WHERE objednavka_id={wid}"), 'záznamů')
st, j = req('wolt_webhook.php', raw=raw, headers={'X-Wolt-Signature': sig})
check('opakovaný webhook nezaloží duplicitu', sql(f"SELECT COUNT(*) FROM delivery_external_orders WHERE external_id='{ext}'") == '1', sql(f"SELECT COUNT(*) FROM delivery_external_orders WHERE external_id='{ext}'"))
print(f'\nVÝSLEDEK: {ok} OK / {fail} FAIL')
