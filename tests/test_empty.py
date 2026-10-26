"""Instalace do PRÁZDNÉ databáze (jen schema.sql): všechny stránky se vykreslí bez chyb a aplikace jde použít od nuly.
Spouští se s prostředím `tests/env.sh up empty`."""
from lhtest import *
T = Tests('Prázdná databáze (jen schema.sql)')
assert ENV['mode'] == 'empty', 'spusť tests/env.sh up empty'
T.section('Stránky ve všech režimech')
for key, label in (('full', 'plná verze'), ('admin', 'admin (identita)'), ('preview', 'náhled'), ('user', 'přihlášený uživatel')):
    c = Client(key); t = c.get()
    T.check('%s: HTTP 200, žádná chyba PHP ve výstupu' % label, c.code == 200 and not re.search(r'(Warning|Notice|Fatal error|Deprecated)[^<]{0,60}on line', t), t[:150])
c = Client('full'); t = c.get()
T.check('žebříček i počítadlo hlásí, že nic není: „Zatím nebyl zaznamenán žádný incident.“', 'Zatím nebyl zaznamenán žádný incident' in t)
T.check('vizualizace odpovídá (HTTP 200) a její data (ajax=meta) jsou platný JSON bez incidentů', Client('full').get('vizualizace.php') is not None and json.loads(Client('full').get('vizualizace.php?ajax=meta')).get('inc') == [])
T.section('Použití od nuly')
adm = Client('full', admin=True)
T.check('admin se přihlásí heslem a vidí administraci', 'id="pane-admin"' in adm.get())
now = dt.datetime.now().strftime('%Y-%m-%dT%H:%M')
u = Client('full')
u.post({'action': 'report', 'reporter': 'Tester', 'cubicle': 'prava', 'status': 'ok', 'reported_at': now})
T.check('první hlášení stavu kabinky se uloží', q("select count(*) from toilet_reports where reporter='Tester'") == '1', u.flash())
yday = (dt.date.today() - dt.timedelta(days=1)).isoformat()
adm.post({'action': 'import_logs', 'log_date': yday, 'turnstile': 'vlevo', 'raw_text': 'in\nVzorová Osoba (vzorova)\n07:30\nout\nVzorová Osoba (vzorova)\n16:10'})
T.check('první import docházky se uloží (2 průchody) a jméno bez přihlašovacího jména je očištěné', q("select count(*) from turnstile_logs where person_name='Vzorová Osoba'") == '2', adm.flash())
adm.post({'action': 'employees_save', 'employees': 'Vzorová Osoba\nDruhá Osoba'})
T.check('seznam zaměstnanců jde uložit', q("select count(*) from employees") == '2')
adm.post({'action': 'set_ro_sections', 'ro_visible[]': ['timeline', 'passes', 'photos', 'stats', 'history']})
T.check('nastavení náhledu jde uložit', setting('ro_hidden') == '')
t = Client('full').get(); T.check('stránka po těchto krocích pořád bez chyby PHP', not re.search(r'(Warning|Notice|Fatal error)[^<]{0,60}on line', t))
sys.exit(T.finish())
