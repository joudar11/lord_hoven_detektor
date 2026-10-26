#!/usr/bin/env python3
"""
Generátor vymyšlených ukázkových dat (db/demo_data.sql). Žádná skutečná osoba, žádná skutečná data.

Použití:  python3 tests/make_demo_data.py > db/demo_data.sql
Data jsou deterministická (pevné náhodné semínko) a všechna data jsou RELATIVNÍ k dnešku (CURDATE() - INTERVAL n DAY),
takže ukázka je vždy čerstvá. Body podezřelých jsou předem dané (viz EXPECTED níže), protože náhodné průchody se generují
jen MIMO okna incidentů.
"""
import random, sys

rnd = random.Random(20261026)
NAMES = ["Adam Vzorový", "Berta Příkladná", "Cyril Ukázkový", "Dana Zkušební", "Emil Pokusný", "Františka Demonstrační", "Gustav Testovací",
         "Hana Modelová", "Ivo Smyšlený", "Jitka Vymyšlená", "Karel Fiktivní", "Lenka Papírová", "Marek Kartónový", "Nina Plyšová", "Oto Dřevěný",
         "Pavla Skleněná", "Radek Plechový", "Sabina Pískovcová", "Tomáš Hliněný", "Uršula Slámová", "Viktor Kamenný", "Wanda Látková",
         "Xaver Kovový", "Yvona Gumová", "Zdeněk Voskový", "Alena Cínová", "Bohumil Olověný", "Cecílie Zlatá", "Drahomír Stříbrný", "Eliška Měděná"]
IT = ["Ivo Smyšlený", "Jitka Vymyšlená"]          # členové IT: bez turniketu, dostávají virtuální body
BLACKLIST = ["Zdeněk Voskový"]                     # vyřazená osoba: průchody má, ale analýza ji nezobrazí
TRUSTED = ["Hana Modelová"]                        # důvěryhodná osoba (filtr „Skrýt důvěryhodné“)
CULPRIT = "Adam Vzorový"                           # má nejvíc bodů
# (dní zpět, kabinka, začátek okna, konec okna)
INCIDENTS = [(19, 'prava', '08:10', '09:05'), (17, 'leva', '10:20', '12:40'), (15, 'invalide', '13:00', '13:50'), (12, 'prava', '07:30', '08:55'),
             (10, 'prava', '09:15', '10:45'), (7, 'leva', '11:00', '11:40'), (4, 'prava', '08:00', '09:30'), (2, 'prava', '14:00', '15:20')]
# kdo má průchod v okně které ho (indexy incidentů 0..7)
IN_WINDOW = {CULPRIT: [0, 1, 3, 4, 6, 7, 2], "Hana Modelová": [0, 1, 3, 4, 6], "Karel Fiktivní": [1, 2, 5, 7], "Nina Plyšová": [3, 4, 5],
             "Marek Kartónový": [0, 6], "Pavla Skleněná": [4], "Radek Plechový": [7]}
EXPECTED = {n: len(v) for n, v in IN_WINDOW.items()}   # očekávané body (jen ze skutečných průchodů; IT má navíc virtuální body)
WORKERS = [n for n in NAMES if n not in IT]

def mins(hm): h, m = hm.split(':'); return int(h) * 60 + int(m)
def hm(m): return '%02d:%02d' % (m // 60, m % 60)
def ts(d, t): return "TIMESTAMP(CURDATE() - INTERVAL %d DAY, '%s:00')" % (d, t)
def q(s): return "'" + s.replace("'", "''") + "'"
WIN = {}
for d, c, a, b in INCIDENTS: WIN.setdefault(d, []).append((mins(a) - 10, mins(b) + 20))   # okno + tolerance 10 min + rezerva
def outside(d, t):
    return not any(a <= t <= b for a, b in WIN.get(d, []))
def free_time(d, lo, hi):
    for _ in range(200):
        t = rnd.randint(lo, hi)
        if outside(d, t): return t
    return None   # v ten den není mimo okna incidentu žádný volný čas

out = []
w = out.append
w("-- Vymyšlená ukázková data (generováno tests/make_demo_data.py). Žádná skutečná osoba. NESPOUŠTĚT v ostré databázi.\n-- Všechna data jsou relativní k dnešku.")
w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;")
w("INSERT INTO app_settings (setting_key, setting_value) VALUES ('tolerance_minutes', '10') ON DUPLICATE KEY UPDATE setting_value = '10';")
w("INSERT IGNORE INTO employees (name) VALUES " + ", ".join("(%s)" % q(n) for n in NAMES) + ";")
w("INSERT IGNORE INTO it_staff (person_name) VALUES " + ", ".join("(%s)" % q(n) for n in IT) + ";")
w("UPDATE it_staff SET virtual_until = CURDATE() - INTERVAL 11 DAY;   -- po tomto dni se členům IT už virtuální body nepřidělují (čtečka na dveřích IT)")
w("INSERT INTO it_vacations (staff_id, date_from, date_to) SELECT id, CURDATE() - INTERVAL 17 DAY, CURDATE() - INTERVAL 15 DAY FROM it_staff WHERE person_name = 'Jitka Vymyšlená';")
w("INSERT IGNORE INTO blacklisted_suspects (person_name) VALUES " + ", ".join("(%s)" % q(n) for n in BLACKLIST) + ";")
w("INSERT IGNORE INTO trusted_persons (person_name) VALUES " + ", ".join("(%s)" % q(n) for n in TRUSTED) + ";")
# hlášení: nejdřív ok na začátku okna (a dřívější ok), na konci zesraná
rows = []
for i, (d, c, a, b) in enumerate(INCIDENTS):
    rows.append("(%s, %s, 'ok', %s)" % (q('demo_kontrola'), q(c), ts(d, hm(mins(a) - 180))))
    rows.append("(%s, %s, 'ok', %s)" % (q('demo_kontrola'), q(c), ts(d, a)))
    rows.append("(%s, %s, 'zesrana', %s)" % (q('demo_hlasitel'), q(c), ts(d, b)))
rows.append("(%s, 'leva', 'ok', %s)" % (q('demo_kontrola'), ts(1, '09:00')))
rows.append("(%s, 'invalide', 'ok', %s)" % (q('demo_kontrola'), ts(1, '09:05')))
w("INSERT INTO toilet_reports (reporter, cubicle, status, reported_at) VALUES\n  " + ",\n  ".join(rows) + ";")
# průchody: pracovní příchod a odchod, oběd; jen mimo okna incidentů
passes = []
def P(name, dr, ts_name, d, t): passes.append("(%s, %s, %s, %s)" % (q(name), q(dr), q(ts_name), ts(d, hm(t))))
for d in range(1, 23):
    for n in WORKERS:
        if rnd.random() < 0.07: continue                  # občas dovolená / nemoc
        arr = free_time(d, 6 * 60 + 20, 8 * 60 + 40)
        if arr is not None: P(n, 'IN', rnd.choice(['vlevo', 'vpravo']), d, arr)
        lunch = free_time(d, 11 * 60 + 40, 12 * 60 + 50); back = free_time(d, lunch + 20, lunch + 55) if lunch is not None else None
        if rnd.random() < 0.6 and lunch is not None and back is not None: P(n, 'OUT', 'vlevo', d, lunch); P(n, 'IN', 'vlevo', d, back)
        dep = free_time(d, 15 * 60 + 40, 17 * 60 + 30)
        if dep is not None: P(n, 'OUT', rnd.choice(['vlevo', 'vpravo']), d, dep)
    if d == 11:    # den, kdy skupina přišla jinou cestou (turniket u 1. patra), aby byly vidět všechny turnikety
        for n in ("Lenka Papírová", "Tomáš Hliněný"): P(n, 'IN', 'patro_1', d, 10 * 60 + 5)
for k, idxs in IN_WINDOW.items():
    for j, i in enumerate(idxs):
        d, c, a, b = INCIDENTS[i]
        P(k, 'IN', 'vpravo' if j % 2 == 0 else 'patro_1', d, mins(a) + 10 + (j % 4) * 5)
w("INSERT INTO turnstile_logs (person_name, direction, turnstile, passed_at) VALUES\n  " + ",\n  ".join(passes) + ";")
# pozorování osob a ostatní data
def rid(i): d, c, a, b = INCIDENTS[i]; return "(SELECT id FROM toilet_reports WHERE cubicle = %s AND status = 'zesrana' AND reported_at = %s)" % (q(c), ts(d, b))
w("INSERT INTO sightings (person_name, location, direction, seen_at, reporter, status, name_known, decided_at, note) VALUES\n"
  "  (%s, 'prava', 'vychazel', %s, 'demo_svedek', 'approved', 1, NOW(), 'viděl jsem ho vycházet z kabinky'),\n"
  "  ('Hana Modelová', 'toalety_nevim', 'poblizu', %s, 'demo_svedek', 'approved', 1, NOW(), NULL),\n"
  "  ('Karel Fiktivní', 'leva', 'vchazel', %s, 'demo_svedek2', 'pending', 1, NULL, NULL);"
  % (q(CULPRIT), ts(4, '09:00'), ts(12, '08:30'), ts(2, '14:50')))
w("INSERT IGNORE INTO incident_innocents (report_id, person_name, reason) VALUES (%s, 'Nina Plyšová', 'byla na služební cestě');" % rid(3))
w("INSERT IGNORE INTO incident_labels (id, name, color) VALUES (1, 'Prkýnko', '#e11d48'), (2, 'Hodně času', '#2563eb');")
w("INSERT IGNORE INTO incident_label_map (report_id, label_id) VALUES (%s, 1), (%s, 1), (%s, 2), (%s, 2);" % (rid(0), rid(3), rid(1), rid(5)))
w("INSERT INTO milestones (kind, person_name, date_from, title) VALUES ('left', 'Oto Dřevěný', CURDATE() - INTERVAL 9 DAY, 'Oto Dřevěný odešel z firmy');")
w("INSERT INTO poznatky (reporter, person_name, poznatek) VALUES ('demo_poznamky', 'Gustav Testovací', 'Gustav Testovací je levák (vymyšlený příklad).');")
w("SET FOREIGN_KEY_CHECKS = 1;")
sys.stdout.write("\n".join(out) + "\n")
sys.stderr.write("očekávané body podle průchodů v oknech: %s\n" % EXPECTED)
