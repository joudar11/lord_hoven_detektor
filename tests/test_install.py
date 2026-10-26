"""Instalace s ukázkovými daty (schema.sql + demo_data.sql): přesné očekávané body, režimy, vyřazené osoby, zálohy, soubory.
Spouští se s prostředím `tests/env.sh up demo`."""
from lhtest import *
T = Tests('Instalace s ukázkovými daty')
assert ENV['mode'] == 'demo', 'spusť tests/env.sh up demo'
EXPECT = {'Adam Vzorový': 7, 'Hana Modelová': 5, 'Karel Fiktivní': 4, 'Nina Plyšová': 3, 'Marek Kartónový': 2, 'Pavla Skleněná': 1, 'Radek Plechový': 1}
T.section('Stránky ve všech režimech')
for key, label in (('full', 'plná verze'), ('admin', 'admin (identita)'), ('preview', 'náhled'), ('user', 'přihlášený uživatel')):
    c = Client(key); t = c.get()
    T.check('%s: HTTP 200, žádná chyba PHP ve výstupu' % label, c.code == 200 and not re.search(r'(Warning|Notice|Fatal error|Deprecated)[^<]{0,60}on line', t), t[:150])
T.section('Žebříček podezřelých odpovídá návrhu dat')
t = Client('full').get(); rk = ranking(t)
T.check('osoby s body ze skutečných průchodů v oknech: %s' % {k: rk.get(k) for k in EXPECT}, all(rk.get(k) == v for k, v in EXPECT.items()), rk)
T.check('členové IT mají virtuální body (Ivo 4, Jitka 2: dovolená a datum čtečky)', rk.get('Ivo Smyšlený') == 4 and rk.get('Jitka Vymyšlená') == 2, rk)
T.check('vyřazená osoba (Zdeněk Voskový) v žebříčku není, přestože má průchody', 'Zdeněk Voskový' not in rk and q("select count(*) from turnstile_logs where person_name='Zdeněk Voskový'") != '0')
T.check('jediný vedoucí (Adam Vzorový) má štítek „HLAVNÍ PODEZŘELÝ“', 'HLAVNÍ PODEZŘELÝ' in t and re.search(r'suspect-rank-1[^"]*" data-name="Adam Vzorový"', t) is not None)
T.check('osm incidentů, očíslované #1 až #8 a poslední má dnešní odstup (počítadlo ukazuje 2 dny)', q("select count(*) from toilet_reports where status='zesrana'") == '8' and re.search(r'Dnů od posledního incidentu: (<strong[^>]*>)?2', t) is not None)
T.check('svědectví u Adama je přesné (kód 1000) a čekající pozorování (Karel) do bodů nejde', re.search(r'data-name="Adam Vzorový"[^>]*data-wit="1000"', t) is not None and re.search(r'data-name="Karel Fiktivní"[^>]*data-wit="0"', t) is not None)
T.section('Režimy a oprávnění')
pv = Client('preview'); tp = pv.get()
T.check('náhled: jména jsou pseudonymy (žádné skutečné jméno z ukázky ve stránce)', 'Adam Vzorový' not in tp and re.search(r'data-name="Osoba [0-9A-Z]{5}"', tp) is not None)
adm = Client('admin'); ta = adm.get()
T.check('admin (identita z proxy) vidí administraci a jména', 'id="pane-admin"' in ta and 'Adam Vzorový' in ta)
T.check('běžný přihlášený uživatel administraci nevidí', 'id="pane-admin"' not in Client('user').get())
T.section('Soubory, vizualizace, záloha')
for a in ('assets/lh.css', 'assets/lh.js', 'assets/lh-boot.js', 'assets/viz.css', 'assets/viz.js', 'assets/vendor/bootstrap.min.css', 'assets/vendor/bootstrap.bundle.min.js'):
    c = Client('full'); c.get(a); T.check('%s je dostupný (HTTP 200)' % a, c.code == 200)
c = Client('full'); c.get('lh_common.php'); T.check('lh_common.php se přímým voláním nespustí (HTTP 403)', c.code == 403)
meta = json.loads(Client('full').get('vizualizace.php?ajax=meta')); T.check('vizualizace: 8 incidentů v datech', len(meta['inc']) == 8)
data = json.loads(Client('full').get('vizualizace.php?ajax=data&od=%s&do=%s' % ((dt.date.today() - dt.timedelta(days=30)).isoformat(), dt.date.today().isoformat())))
T.check('vizualizace: průchody i hlášení jsou v datech (%d průchodů)' % len(data['pass']), len(data['pass']) > 500 and len(data['rep']) > 10)
req = urllib.request.Request('http://127.0.0.1:8081/', data=urllib.parse.urlencode({'action': 'backup_token'}).encode(), headers={'X-Backup-Token': 'b' * 40, 'X-Lh-Proxy': 'PROXYSECRET123'})
raw = urllib.request.urlopen(req).read()
import gzip
try: sql = gzip.decompress(raw).decode('utf-8')
except OSError: sql = raw.decode('utf-8', 'replace')
T.check('automatická záloha s klíčem: platná záloha, která končí značkou konce a neobsahuje hesla', sql.rstrip().split('\n')[-1].startswith('-- KONEC ZÁLOHY') and 'CREATE TABLE' in sql and 'TestAdminPw1' not in sql)
sys.exit(T.finish())
