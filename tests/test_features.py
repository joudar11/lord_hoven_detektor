"""Funkce aplikace nad ukázkovými daty: sběr dat, označení pachatele a e-maily, souhrn, import, aliasy, záznam akcí, retence,
vlastní texty e-mailů, viditelnost náhledu, důvěryhodní, úprava pozorování. Spouští se s prostředím `tests/env.sh up demo`."""
from lhtest import *
T = Tests('Funkce aplikace')
adm = Client('full', admin=True); anon = Client('full'); anon.get(); user = Client('user'); user.get(); pv = Client('preview')
now = dt.datetime.now(); NOW = now.strftime('%Y-%m-%dT%H:%M')
def counts(): return tuple(int(q("select count(*) from %s" % t)) for t in ('toilet_reports', 'sightings', 'poznatky'))
def submit(c, tag):
    c.post({'action': 'report', 'reporter': 'Test ' + tag, 'cubicle': 'prava', 'status': 'ok', 'reported_at': NOW})
    c.post({'action': 'report_sighting', 'reporter': 'Test ' + tag, 'person_name': 'Neznámá Osoba ' + tag, 'location': 'prava', 'direction': 'vychazel', 'seen_at': NOW})
    c.post({'action': 'report_note', 'reporter': 'Test ' + tag, 'person_name': '', 'poznatek': 'poznatek ' + tag})
def digest_n():
    clear_mails(); adm.post({'action': 'digest_test'}); m = wait_for('[TEST]')
    return int(re.search(r'Aktuálně podezřelých: (\d+)', m['body']).group(1)), m

T.section('Sběr dat: vypnutí vkládání informací pro všechny kromě admina')
b = counts(); submit(anon, 'zap'); T.check('zapnutý sběr: veřejnost může poslat hlášení, pozorování i poznatek', counts() == tuple(x + 1 for x in b), counts())
adm.post({'action': 'set_collection', 'collect': 'off'}); T.check('admin sběr vypne (hláška, nastavení, záznam akcí)', setting('collect_disabled') == '1' and q("select count(*) from audit_log where action_key='set_collection'") != '0')
for lab, c in (('veřejnost', anon), ('přihlášený uživatel', user), ('náhledová adresa', pv)):
    b = counts(); submit(c, 'vyp'); T.check('%s po vypnutí nic neuloží' % lab, counts() == b)
anon.post({'action': 'report', 'reporter': 'X', 'cubicle': 'prava', 'status': 'ok', 'reported_at': NOW}); T.check('veřejnost dostane hlášku „Sběr dat je vypnutý“', 'Sběr dat je vypnutý' in anon.flash()[1])
b = counts(); submit(adm, 'adm'); T.check('admin může zadávat dál', counts() == tuple(x + 1 for x in b), counts())
t = anon.get(); T.check('ostatním zmizely formuláře a vidí „Sběr dat je ukončen“', 'id="record-box"' not in t and 'id="collect-off-info"' in t)
T.check('admin vidí upozornění a své formuláře', 'id="collect-off-admin"' in adm.get() and 'id="record-box"' in adm.last)

T.section('Označení pachatele (až po ukončení sběru dat) a e-mail o dopadení')
clear_mails(); adm.post({'action': 'culprit_set', 'culprit_name': 'adam vzorovy', 'notify': '1'}); fl = adm.flash(); m = wait_for('[Detektor] Pachatel byl dopaden!') or {}
T.check('pachatel označen s e-mailem; jméno sjednoceno s docházkou (%s); e-mail odešel jednou' % setting('culprit_name'), setting('culprit_name') == 'Adam Vzorový' and len(mails_with('[Detektor] Pachatel byl dopaden!')) == 1, fl)
T.check('e-mail obsahuje přesnou větu „Pachatel byl dopaden a dosral svoje poslední hovno.“', 'Pachatel byl dopaden a dosral svoje poslední hovno.' in m.get('body', ''))
T.check('e-mail neobsahuje jméno pachatele a odešel příjemci souhrnu', 'Adam' not in m.get('body', '') + m.get('subject', '') and 'Vzorov' not in m.get('body', '') and m.get('to') == 'digest@example.test')
t = anon.get(); T.check('banner „Pachatel byl dopaden“ a odznak PACHATEL u řádku v žebříčku', 'id="culprit-banner"' in t and re.search(r'data-name="Adam Vzorový"[^>]*data-culprit="1"', t) is not None and '>PACHATEL<' in t)
tp = pv.get(); T.check('náhled: banner s pseudonymem, skutečné jméno nikde', re.search(r'id="culprit-name">Osoba [0-9A-Z]{5}<', tp) is not None and 'Adam Vzorový' not in tp)
q("DELETE FROM app_settings WHERE setting_key='digest_last_sent'"); r = urllib.request.Request('http://127.0.0.1:8081/', data=urllib.parse.urlencode({'action': 'digest_send'}).encode(), headers={'X-Backup-Token': 'b' * 40, 'X-Lh-Proxy': 'PROXYSECRET123'})
T.check('týdenní souhrn z cronu se po dopadení přeskočí', urllib.request.urlopen(r).read().decode().startswith('SKIP: pachatel byl dopaden'))
adm.post({'action': 'set_collection', 'collect': 'on'}); T.check('opětovné zapnutí sběru označení zruší', setting('culprit_name') == '' and 'id="culprit-banner"' not in anon.get())
adm.post({'action': 'culprit_set', 'culprit_name': 'Adam Vzorový'}); T.check('při zapnutém sběru jde pachatele označit až po jeho ukončení', setting('culprit_name') == '' and adm.flash()[0] == 'danger')

T.section('Vlastní texty e-mailů (konfigurace texts)')
adm.post({'action': 'set_collection', 'collect': 'off'}); tx = Client('texts', admin=True); clear_mails()
tx.post({'action': 'culprit_set', 'culprit_name': 'Adam Vzorový', 'notify': '1'}); body = (wait_for('[Detektor] Pachatel byl dopaden!') or {}).get('body', '')
T.check('e-mail o dopadení použije vlastní úvod a závěr z konfigurace', 'Vlastní úvod: pachatel dopaden.' in body and 'Vlastní závěr.' in body and 'Lord Hoven byl dopaden!' not in body, body[:200])
adm.post({'action': 'culprit_clear'}); adm.post({'action': 'set_collection', 'collect': 'on'}); clear_mails(); tx.post({'action': 'digest_test'}); dm = wait_for('[TEST]') or {}
T.check('týdenní souhrn použije vlastní závěr z konfigurace', 'Vlastní závěr souhrnu.' in dm.get('body', '') and 'smyčka se stahuje' not in dm.get('body', ''))

T.section('Týdenní souhrn: „Aktuálně podezřelých“ bez důvěryhodných a nevinných')
n, m = digest_n(); T.check('výchozí stav: jediný hlavní podezřelý (Adam), důvěryhodná Hana se nepočítá (%d)' % n, n == 1 and m['subject'].startswith('[TEST]'))
adm.post({'action': 'trusted_add', 'trusted_names': 'Adam Vzorový'}); n, _ = digest_n(); T.check('Adam důvěryhodný: Karel a Ivo se 4 body (2)', n == 2, n)
iid = q("select id from toilet_reports where status='zesrana' order by reported_at limit 1")
adm.post({'action': 'innocent_add', 'person_names': 'Karel Fiktivní', 'report_ids[]': iid}); n, _ = digest_n(); T.check('Karel navíc nevinný: zbývá jen Ivo (1)', n == 1, n)
adm.post({'action': 'trusted_add', 'trusted_names': 'Ivo Smyšlený\nNina Plyšová\nMarek Kartónový\nPavla Skleněná\nRadek Plechový\nJitka Vymyšlená'}); n, _ = digest_n(); T.check('všichni s body skrytí: 0', n == 0, n)
q("DELETE FROM trusted_persons WHERE person_name <> 'Hana Modelová'"); q("DELETE FROM incident_innocents WHERE person_name='Karel Fiktivní'")
n, _ = digest_n(); T.check('po úklidu zase 1', n == 1, n)

T.section('Důvěryhodní: správa seznamu')
adm.post({'action': 'trusted_add', 'trusted_names': 'Eliška Měděná\neliska medena\nNeexistující Osoba'}); T.check('přidání sjednotí varianty jména a ohlásí počet (%s)' % adm.flash()[1], q("select count(*) from trusted_persons where person_name in ('Eliška Měděná','Neexistující Osoba')") == '2')
adm.post({'action': 'trusted_add', 'trusted_names': 'x' * 101}); T.check('příliš dlouhé jméno se odmítne', adm.flash()[0] == 'danger')
adm.post({'action': 'trusted_delete', 'person_name': 'Eliška Měděná'}); T.check('odebrání funguje', q("select count(*) from trusted_persons where person_name='Eliška Měděná'") == '0')
anon.post({'action': 'trusted_add', 'trusted_names': 'Hacker'}); T.check('veřejnost seznam měnit nemůže', q("select count(*) from trusted_persons where person_name='Hacker'") == '0')
q("DELETE FROM trusted_persons WHERE person_name <> 'Hana Modelová'")

T.section('Import docházky: kontrola před uložením')
row = q("select person_name, direction, passed_at from turnstile_logs where turnstile='vpravo' and direction='IN' order by id limit 1").split('\t'); day = row[2][:10]; hhmm = row[2][11:16]
n0 = q("select count(*) from turnstile_logs"); raw = 'in\n%s\n%s\nin\nVymyšlená Nová Osoba\n05:11' % (row[0], hhmm)
adm.post({'action': 'import_logs', 'log_date': day, 'turnstile': 'vlevo', 'raw_text': raw}); pg = adm.last
T.check('záznam, který už existuje u jiného turniketu, import zastaví a nic se neuloží', 'id="import-warning"' in pg and q("select count(*) from turnstile_logs") == n0, pg[:100])
T.check('výpis uvádí osobu i turniket a nabízí „bez problematických (1)“ a „vše přesto (2)“', row[0] in pg and 'Turniket VPRAVO' in pg and 'Importovat bez problematických (1)' in pg and 'Importovat vše přesto (2)' in pg)
adm.post({'action': 'import_logs', 'log_date': day, 'turnstile': 'vlevo', 'raw_text': raw, 'import_mode': 'skip'}); T.check('„bez problematických“ uloží jen čistý záznam', int(q("select count(*) from turnstile_logs")) == int(n0) + 1, adm.flash())
adm.post({'action': 'import_logs', 'log_date': day, 'turnstile': 'vlevo', 'raw_text': raw, 'import_mode': 'all'}); T.check('„vše přesto“ uloží i problematický záznam', int(q("select count(*) from turnstile_logs")) == int(n0) + 2)
fut = (now + dt.timedelta(minutes=30)).strftime('%H:%M')
if now.hour < 23 and now.minute < 25:
    adm.post({'action': 'import_logs', 'log_date': now.date().isoformat(), 'turnstile': 'vlevo', 'raw_text': 'in\nVymyšlená Budoucí Osoba\n%s' % fut}); T.check('záznam z budoucnosti se zastaví a je ve výpisu „Záznamy z budoucnosti“', 'Záznamy z budoucnosti' in adm.last and q("select count(*) from turnstile_logs where person_name='Vymyšlená Budoucí Osoba'") == '0')
adm.post({'action': 'import_logs', 'log_date': day, 'turnstile': 'vlevo', 'raw_text': 'in\nČistá Osoba\n05:44'}); T.check('import bez problémů proběhne rovnou bez varování', 'id="import-warning"' not in adm.last and q("select count(*) from turnstile_logs where person_name='Čistá Osoba'") == '1')

T.section('Aliasy jmen: sloučení variant')
dup = q("select direction, turnstile, passed_at from turnstile_logs where person_name='Adam Vzorový' order by id limit 1").split('\t')
q("INSERT INTO turnstile_logs (person_name,direction,turnstile,passed_at) VALUES ('avzor','%s','%s','%s'), ('avzor','IN','vlevo','%s 05:07:00')" % (dup[0], dup[1], dup[2], day))
nA = q("select count(*) from turnstile_logs where person_name='Adam Vzorový'")
pj = json.loads(adm.get('?ajax=alias_preview&canonical=Adam%20Vzorov%C3%BD&aliases=avzor')); it = pj['items'][0]
T.check('náhled slučení hlásí 2 řádky a 1 duplicitu v průchodech', it['rows'].get('turnstile_logs') == 2 and it['dups'].get('turnstile_logs') == 1, it)
adm.post({'action': 'alias_merge', 'canonical': 'Adam Vzorový', 'aliases': 'avzor'}); fl = adm.flash()
T.check('sloučení přepíše 1 řádek a duplicitu odstraní (řádků Adama %s → %s)' % (nA, q("select count(*) from turnstile_logs where person_name='Adam Vzorový'")), int(q("select count(*) from turnstile_logs where person_name='Adam Vzorový'")) == int(nA) + 1 and q("select count(*) from turnstile_logs where person_name='avzor'") == '0', fl)
adm.post({'action': 'import_logs', 'log_date': day, 'turnstile': 'vlevo', 'raw_text': 'in\navzor\n05:09'}); T.check('při dalším importu se alias sám změní na hlavní jméno', q("select count(*) from turnstile_logs where person_name='Adam Vzorový' and passed_at='%s 05:09:00'" % day) == '1')
for ep in ('audit', 'alias_preview', 'alias_suggest'):
    c = Client('full'); c.get('?ajax=' + ep); T.check('adresa ?ajax=%s je jen pro admina (veřejnost HTTP 403)' % ep, c.code == 403)

T.section('Záznam akcí')
al = q("select count(*) from audit_log where action_key in ('set_collection','culprit_set','alias_merge','import_logs','trusted_add')")
T.check('akce admina jsou v záznamu (%s záznamů)' % al, int(al) >= 6)
T.check('v záznamu nejsou hesla ani tajné klíče', q("select count(*) from audit_log where details like '%TestAdminPw1%' or result like '%TestAdminPw1%' or details like '%PROXYSECRET123%'") == '0')
pj = json.loads(adm.get('?ajax=audit&limit=3')); T.check('adresa ?ajax=audit vrací stránkované záznamy (3 + „jsou starší“)', len(pj['rows']) == 3 and pj['more'] is True)

T.section('Retence osobních údajů (konfigurace retention_*)')
q("DELETE FROM audit_log WHERE action_key='ret_test'; DELETE FROM device_seen WHERE device_id IN ('r1','r2'); DELETE FROM toilet_reports WHERE reporter IN ('Ret stará','Ret nová'); DELETE FROM app_settings WHERE setting_key='retention_last_run'")
q("INSERT INTO audit_log (created_at,user_name,action_key,label,grp) VALUES (NOW() - INTERVAL 100 DAY,'x','ret_test','stará','settings'), (NOW() - INTERVAL 2 DAY,'x','ret_test','nová','settings')")
q("INSERT INTO device_seen (device_id,first_seen,last_seen,last_ip) VALUES ('r1',NOW() - INTERVAL 100 DAY,NOW() - INTERVAL 100 DAY,'192.0.2.1'), ('r2',NOW(),NOW(),'192.0.2.2')")
q("INSERT INTO toilet_reports (reporter,cubicle,status,reported_at,client_ip) VALUES ('Ret stará','leva','ok',NOW() - INTERVAL 100 DAY,'192.0.2.10'), ('Ret nová','leva','ok',NOW() - INTERVAL 1 DAY,'192.0.2.11')")
Client('retention').get()
T.check('starý záznam akcí a staré zařízení se smažou, nové zůstanou', q("select group_concat(label) from audit_log where action_key='ret_test'") == 'nová' and q("select group_concat(device_id order by device_id) from device_seen where device_id in ('r1','r2')") == 'r2')
T.check('IP u staré zprávy se anonymizuje (zpráva zůstane), u nové zůstane', q("select ifnull(client_ip,'NULL') from toilet_reports where reporter='Ret stará'") == 'NULL' and q("select client_ip from toilet_reports where reporter='Ret nová'") == '192.0.2.11')
T.check('čištění je zaznamenáno (retention_last_run = dnes) a běží nejvýš jednou denně', setting('retention_last_run') == dt.date.today().isoformat())
q("INSERT INTO audit_log (created_at,user_name,action_key,label,grp) VALUES (NOW() - INTERVAL 100 DAY,'x','ret_test','druhá stará','settings')"); Client('retention').get()
T.check('druhé načtení téhož dne už nemaže', q("select count(*) from audit_log where label='druhá stará'") == '1')
Client('full').get(); T.check('bez nastavení retence (hlavní aplikace) se nemaže nic', q("select count(*) from audit_log where label='druhá stará'") == '1')

T.section('Viditelnost náhledové verze (admin)')
for key, endp in (('history', '?ajax=history&limit=5'), ('stats', '?ajax=stats&ids=1'), ('timeline', 'vizualizace.php')):
    adm.post({'action': 'set_ro_sections', 'ro_visible[]': [k for k in ('timeline', 'passes', 'photos', 'stats', 'history') if k != key]}); p = Client('preview'); p.get(endp)
    T.check('vypnutá část „%s“: adresa v náhledu vrací 403, plná verze ji dál má' % key, p.code == 403 and (Client('full').get(endp) is not None) and Client('full').code != 403)
adm.post({'action': 'set_ro_sections', 'ro_visible[]': ['timeline', 'stats', 'history', 'photos']}); t = Client('preview').get()
T.check('vypnuté „Průchody“: v náhledu nejsou odkazy na turnikety, body zůstávají', not re.search(r'\[(Turniket [A-Z]+|1\. patro)\s*\|\s*(IN|OUT) @', t) and sorted(ranking(t).values()) == sorted(ranking(Client('full').get()).values()))
adm.post({'action': 'set_ro_sections', 'ro_visible[]': ['timeline', 'passes', 'photos', 'stats', 'history']}); T.check('zapnutí všeho vrátí původní stav', setting('ro_hidden') == '')

T.section('Úprava pozorování adminem')
sid = q("select id from sightings where status='approved' order by id limit 1"); before = q("select status,device_id,created_at from sightings where id=%s" % sid)
adm.post({'action': 'sighting_edit', 'id': sid, 'person_name': 'Adam Vzorový', 'location': 'leva', 'direction': 'vchazel', 'seen_at': (now - dt.timedelta(days=1)).strftime('%Y-%m-%dT10:00'), 'reporter': 'demo_admin', 'note': 'upraveno'})
T.check('úprava změní místo, směr, čas, hlásícího a poznámku', q("select concat(location,'|',direction,'|',reporter,'|',note) from sightings where id=%s" % sid) == 'leva|vchazel|demo_admin|upraveno')
T.check('stav a údaje o odeslání se nemění', q("select status,device_id,created_at from sightings where id=%s" % sid) == before)
adm.post({'action': 'sighting_edit', 'id': sid, 'person_name': '', 'location': 'leva', 'direction': 'vchazel', 'seen_at': NOW, 'reporter': 'x', 'note': ''}); T.check('neplatný vstup (prázdné jméno) se odmítne', adm.flash()[0] == 'danger')
adm.post({'action': 'sighting_edit', 'id': sid, 'person_name': 'Adam Vzorový', 'location': 'leva', 'direction': 'vchazel', 'seen_at': (now + dt.timedelta(days=1)).strftime('%Y-%m-%dT10:00'), 'reporter': 'x', 'note': ''}); T.check('čas z budoucnosti se odmítne', adm.flash()[0] == 'danger')
anon.post({'action': 'sighting_edit', 'id': sid, 'person_name': 'Hacker', 'location': 'leva', 'direction': 'vchazel', 'seen_at': NOW, 'reporter': 'x', 'note': ''}); T.check('veřejnost pozorování upravit nemůže', q("select person_name from sightings where id=%s" % sid) == 'Adam Vzorový')
sys.exit(T.finish())
