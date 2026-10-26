<?php
/**
 * Vizualizace: časová osa všech událostí (stav a hlášení kabinek, incidenty a jejich časová okna, průchody turnikety,
 * pozorování osob, dovolené IT). Samostatná aplikace ve stejné složce jako index.php, se stejnou konfigurací (lh_config.php).
 * Jen čte z databáze, nic nezapisuje a nepotřebuje žádný SQL skript.
 * Verze: 1.80
 *
 * Přístup a anonymizace jsou stejné jako v index.php: povolená adresa proxy; plná verze (skutečná jména) jen s tajnou hlavičkou X-Lh-Proxy
 * a adresou z full_hosts; jinak náhledová verze, ve které jsou jména osob a hlásících nahrazena stálými pseudonymy (vypínač v administraci
 * index.php). Funkce wit_key, lh_name_norm, lh_pseudo_*, lh_setting jsou KOPIE z index.php (stejné kódy pseudonymů); při jejich změně je
 * nutné upravit oba soubory.
 *
 * CHANGELOG
 * 1.70 (2026-10-21) Náhledová verze: stránku lze vypnout (ro_hidden obsahuje timeline) a vrstvu průchodů vypnout samostatně (passes); ovládací prvky průchodů se pak nezobrazují.
 * 1.60 (2026-10-17) Milníky: nepřekrývající se sdílejí řádek, překrývající se (i ze stejného dne) mají každý svůj řádek. Nová vrstva „Poznatky“ (jen s časem, jde skrýt; v náhledové verzi není).
 *                   Karta „Časová okna incidentů“ je při načtení vždy sbalená a po rozbalení bez vlastního scrollování.
 * 1.50 (2026-10-15) Vrstva „Milníky“ (zadává admin v hlavní aplikaci): vlajka v samostatném řádku, přerušovaná čára přes všechny řádky, pásmo u „pryč“, položky v seznamu událostí a detail.
 *                   Milníky osob vyřazených z hodnocení se skrývají jako jejich průchody (do zapnutí „Vyřazené osoby"). V náhledu jsou jména pseudonymy.
 * 1.40 (2026-10-15) Karta „Časová okna incidentů“ jako v hlavní aplikaci: štítky s počty, Sjednocení/Průnik, seznam incidentů se zaškrtávátky (výchozí = počítané incidenty), rozklik do detailů a přeskok na osu;
 *                   výběr se promítne do osy i seznamu událostí. Dřívější rozbalovátko „Incident“ nahrazeno touto kartou.
 * 1.30 (2026-10-15) Turnikety se zapínají a vypínají každý zvlášť; v seznamu jsou i průchody (podle filtrů, po 300 položkách); filtr osob s našeptávačem (průchody a pozorování);
 *                   filtr incidentů podle štítků (sjednocení / průnik); v detailu pozorování je vidět, kdo ho nahlásil. Nový endpoint ?ajax=people.
 * 1.20 (2026-10-15) Přepínače „Vyřazené osoby“ (zobrazí je jako by vyřazené nebyly; data se stahují jen na požádání) a „První průchod dne“ (první průchod osoby za den, po filtru směru).
 * 1.10 (2026-10-15) Turnikety: směr IN (nahoře, plný bod) a OUT (dole, kroužek) s přepínači; osoby vyřazené z hodnocení jsou skryté (průchody, pozorování, dovolené, počty v oknech).
 * 1.00 (2026-10-15) Výchozí verze: osa s vrstvami (incidenty, kabinky, turnikety, pozorování, dovolené IT, kvalita dat), zoom a posun myší i dotykem,
 *                   seznam událostí pro mobil, detail události, anonymizace jmen na serveru.
 */
$viz_version = '1.80';

// Náhrady funkcí z rozšíření mbstring, pokud je hosting nemá (stejné jako v index.php)

// ==========================================
// KONFIGURACE (stejný soubor jako index.php, mimo veřejný adresář)
// ==========================================
define('LH_APP', true);
require_once __DIR__ . '/lh_common.php';
$config = lh_load_config();
$lh_pseudo_key = (string)($config['pseudo_secret'] ?? hash('sha256', 'lh-pseudo|' . ($config['device_secret'] ?? '') . '|' . ($config['proxy_secret'] ?? '')));
$lh_missing = lh_missing_config($config, ['allowed_proxy_ip', 'db_host', 'db_name', 'db_user', 'db_pass', 'proxy_secret', 'device_secret', 'full_hosts']);
if ($lh_missing) { http_response_code(500); die('Neúplná konfigurace, chybí: ' . implode(', ', $lh_missing) . ' (viz docs/CONFIGURATION.md).'); }

// Přístup jen přes naši reverzní proxy
if (($_SERVER['REMOTE_ADDR'] ?? '') !== $config['allowed_proxy_ip']) {
    http_response_code(403);
    die('Přístup odepřen (403 Forbidden).');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); die('Jen čtení.'); }

// Režim: plná verze jen s platnou tajnou hlavičkou a adresou z full_hosts, jinak náhled (stejně jako index.php)
$lh_mode = lh_request_mode($config);
$proxy_secret_ok = $lh_mode['proxy_secret_ok'];
$full_hosts = $lh_mode['full_hosts'];
$orig_host = $lh_mode['orig_host'];
$readonly = $lh_mode['readonly'];

try {
    $pdo = new PDO(lh_pdo_dsn($config), $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die('Chyba připojení k databázi.');
}

$cubicles_list = ['leva' => 'Levá kabinka', 'prava' => 'Pravá kabinka', 'invalide' => 'Kabinka Invalidé'];
$cubicles_short = ['leva' => 'Levá', 'prava' => 'Pravá', 'invalide' => 'Inv.'];
$turnstiles_list = ['vlevo' => 'Turniket VLEVO', 'vpravo' => 'Turniket VPRAVO', 'patro_1' => '1. patro', 'it_dvere' => 'IT dveře (4. patro)'];
$turnstiles_short = ['vlevo' => 'Vlevo', 'vpravo' => 'Vpravo', 'patro_1' => '1. p.', 'it_dvere' => 'IT'];
$wit_locations = ['invalide' => 'kabinka invalidé', 'leva' => 'levá kabinka', 'prava' => 'pravá kabinka',
                  'toalety_kabinka' => 'toalety (kabinka)', 'toalety_nevim' => 'toalety (nevím)', 'pisoar' => 'pisoár'];
$wit_directions = ['vychazel' => 'vycházel', 'vchazel' => 'vcházel', 'poblizu' => 'jen poblíž'];

// ==========================================
// SPOLEČNÉ FUNKCE jsou v lh_common.php (stejné kódy pseudonymů jako v index.php)
// ==========================================







// Náhledová verze: admin může v hlavní aplikaci vypnout části (ro_hidden). Časová osa i vrstva průchodů v náhledu se řídí stejným nastavením; plná verze vidí vždy vše.
$ro_hidden = array_filter(array_map('trim', explode(',', lh_setting($pdo, 'ro_hidden'))));
if ($readonly && in_array('timeline', $ro_hidden, true)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><title>Časová osa</title><p style="font:16px system-ui;padding:2rem">Časová osa není v této verzi k dispozici.</p>');
}
$ro_nopass = $readonly && in_array('passes', $ro_hidden, true);

// ==========================================
// DATA PRO OSU (JSON); jména se v náhledové verzi nahrazují pseudonymy už tady na serveru
// ==========================================
$pseudo_on = lh_pseudo_enabled($pdo);
$pn = function ($s) use ($pseudo_on) { return $pseudo_on ? lh_pseudo_name($s) : (string)$s; };    // jméno / označení hlásícího
$pt = function ($s) use ($pseudo_on) { return ($s === null || $s === '') ? null : ($pseudo_on ? lh_pseudo_text($s) : (string)$s); };  // volný text
// Osoby vyřazené z hodnocení (blacklisted_suspects) se ve vizualizaci nezobrazují: ani jejich průchody, pozorování, dovolené, ani se nepočítají v oknech incidentů
$bl_keys = [];
try { foreach ($pdo->query("SELECT person_name FROM blacklisted_suspects")->fetchAll(PDO::FETCH_COLUMN) as $bl_n) { $bl_keys[wit_key($bl_n)] = true; } } catch (PDOException $e) { /* tabulka ještě neexistuje */ }
$show_bl = isset($_GET['bl']) && is_string($_GET['bl']) && $_GET['bl'] === '1';   // přepínač „Vyřazené osoby“: jejich data se pošlou jen na požádání
$is_bl = function ($name) use ($bl_keys, $show_bl) { return !$show_bl && isset($bl_keys[wit_key($name)]); };
$tol_raw = lh_setting($pdo, 'tolerance_minutes');
$tol = ($tol_raw !== '' && ctype_digit($tol_raw) && (int)$tol_raw <= 60) ? (int)$tol_raw : 5;   // tolerance po nálezu jako v index.php

function viz_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
// Čas z databáze je bez časové zóny; pro osu se bere jako UTC (stejná hodnota se tak zobrazí na každém zařízení)
function viz_ts($dt) { return $dt === null ? null : (int)strtotime($dt . ' UTC'); }
function viz_is_virtual($note) { return $note !== null && $note !== '' && strncmp(lh_name_norm($note), 'virtu', 5) === 0; }

// Incidenty (stejná rekonstrukce jako index.php: první hlášení „zesraná“ po „čisto“ v dané kabince)
function viz_incidents($pdo, $cubicles_list) {
    $incs = [];
    $stmt = $pdo->prepare("SELECT * FROM toilet_reports WHERE cubicle = ? ORDER BY reported_at ASC, id ASC");
    foreach (array_keys($cubicles_list) as $c) {
        $stmt->execute([$c]);
        $last_ok = null; $prev = null;
        foreach ($stmt->fetchAll() as $row) {
            if ($row['status'] === 'ok') { $last_ok = $row; }
            elseif ($row['status'] === 'zesrana') {
                if ($prev === 'zesrana') { /* potvrzení téhož incidentu */ }
                elseif ($last_ok !== null) {
                    $incs[] = ['id' => (int)$row['id'], 'c' => $c, 'start' => $last_ok['reported_at'], 'end' => $row['reported_at'], 'ok_by' => $last_ok['reporter'], 'by' => $row['reporter'],
                               'ok_note' => $last_ok['note'] ?? null, 'note' => $row['note'] ?? null];
                    $last_ok = null;
                } else {
                    $incs[] = ['id' => (int)$row['id'], 'c' => $c, 'start' => null, 'end' => $row['reported_at'], 'ok_by' => null, 'by' => $row['reporter'], 'ok_note' => null, 'note' => $row['note'] ?? null];
                }
            }
            $prev = $row['status'];
        }
    }
    usort($incs, function ($a, $b) { return strcmp($a['end'], $b['end']) ?: ($a['id'] <=> $b['id']); });
    return $incs;
}

if (isset($_GET['ajax'])) {
    $what = is_string($_GET['ajax']) ? $_GET['ajax'] : '';
    if ($what === 'meta') {
        $incs = viz_incidents($pdo, $cubicles_list);
        // čísla incidentů (stálá z incident_numbers; nové dostanou předběžné číslo stejně jako v náhledu index.php)
        $stored = [];
        try { $stored = $pdo->query("SELECT report_id, inc_no FROM incident_numbers")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (PDOException $e) { $stored = null; }
        $next = $stored ? (int)max($stored) + 1 : 1;
        $no = [];
        foreach ($incs as $k => $i) {   // $incs je seřazeno podle času nálezu
            if ($stored !== null && isset($stored[$i['id']])) { $no[$i['id']] = (int)$stored[$i['id']]; }
            elseif ($stored !== null) { $no[$i['id']] = $next++; }
            else { $no[$i['id']] = $k + 1; }
        }
        $labels = []; $lmap = []; $ignored = [];
        try { foreach ($pdo->query("SELECT id, name, color FROM incident_labels")->fetchAll() as $r) { $labels[(int)$r['id']] = ['n' => (string)$r['name'], 'c' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)$r['color']) ? strtolower($r['color']) : '#6b7280']; } } catch (PDOException $e) {}
        try { foreach ($pdo->query("SELECT report_id, label_id FROM incident_label_map")->fetchAll() as $r) { $lmap[(int)$r['report_id']][] = (int)$r['label_id']; } } catch (PDOException $e) {}
        try { foreach ($pdo->query("SELECT report_id, reason FROM incident_ignored")->fetchAll() as $r) { $ignored[(int)$r['report_id']] = (string)($r['reason'] ?? ''); } } catch (PDOException $e) {}
        $q_day = $pdo->prepare("SELECT DATE(passed_at) AS d, MIN(passed_at) AS mn, MAX(passed_at) AS mx FROM turnstile_logs WHERE passed_at BETWEEN ? AND ? GROUP BY DATE(passed_at)");
        $q_cnt = $pdo->prepare("SELECT person_name, COUNT(*) AS n FROM turnstile_logs WHERE passed_at BETWEEN ? AND ? GROUP BY person_name");
        $out = [];
        foreach ($incs as $i) {
            $st = viz_ts($i['start']); $en = viz_ts($i['end']); $te = $en + $tol * 60;
            $virt = viz_is_virtual($i['ok_note']);
            $warn = [];
            $n_pass = 0; $n_people = 0;
            if ($st === null) { $warn[] = 'nostart'; }
            else {
                if ($virt) { $warn[] = 'virt'; }
                $d0 = substr($i['start'], 0, 10); $d1 = substr($i['end'], 0, 10);
                $q_day->execute([$d0 . ' 00:00:00', $d1 . ' 23:59:59']);
                $days = [];
                foreach ($q_day->fetchAll() as $r) { $days[$r['d']] = $r; }
                $missing = 0;
                for ($t = strtotime($d0 . ' UTC'); $t <= strtotime($d1 . ' UTC'); $t += 86400) { if (!isset($days[gmdate('Y-m-d', $t)])) { $missing++; } }
                if ($missing > 0) { $warn[] = 'nodata'; }
                if (isset($days[$d0]) && !$virt && viz_ts($days[$d0]['mn']) > $st + 1800) { $warn[] = 'cov_start'; }   // docházka začíná o víc než 30 min později než okno
                if (isset($days[$d1]) && viz_ts($days[$d1]['mx']) < $te) { $warn[] = 'cov_end'; }
                $q_cnt->execute([$i['start'], gmdate('Y-m-d H:i:s', $te)]);
                foreach ($q_cnt->fetchAll() as $c) { if ($is_bl($c['person_name'])) { continue; } $n_pass += (int)$c['n']; $n_people++; }
            }
            $out[] = [
                'id' => $i['id'], 'no' => $no[$i['id']], 'c' => $i['c'], 's' => $st, 'e' => $en,
                'okby' => $i['ok_by'] === null ? null : $pn($i['ok_by']), 'by' => $pn($i['by']),
                'okn' => $pt($i['ok_note']), 'n' => $pt($i['note']),
                'lab' => array_values(array_map(function ($lid) use ($labels) { return $labels[$lid]; }, array_filter($lmap[$i['id']] ?? [], function ($lid) use ($labels) { return isset($labels[$lid]); }))),
                'ign' => isset($ignored[$i['id']]), 'ignr' => isset($ignored[$i['id']]) ? $pt($ignored[$i['id']]) : null,
                'virt' => $virt, 'warn' => $warn, 'np' => $n_pass, 'npp' => $n_people,
            ];
        }
        // Milníky (zadává admin v hlavní aplikaci, vidí všichni). Milníky osob vyřazených z hodnocení se skryjí stejně jako jejich průchody, dokud není zapnuto „Vyřazené osoby“.
        // t = okamžik hranice (u „skončila“ až den PO posledním dni v práci), t2 = konec období u „pryč“ (první okamžik po něm)
        $ms_out = [];
        try {
            foreach ($pdo->query("SELECT id, kind, person_name, date_from, date_to, title, note FROM milestones ORDER BY date_from ASC, id ASC")->fetchAll() as $m) {
                $person = (string)$m['person_name'];
                if ($person !== '' && $is_bl($person)) { continue; }
                $f = strtotime($m['date_from'] . ' UTC');
                $ms_out[] = [
                    'id' => (int)$m['id'], 'k' => $m['kind'], 'who' => $person !== '' ? $pn($person) : null, 'f' => $f,
                    't' => $m['kind'] === 'left' ? $f + 86400 : $f,
                    't2' => ($m['kind'] === 'away' && !empty($m['date_to'])) ? strtotime($m['date_to'] . ' UTC') + 86400 : null,
                    'title' => $pt($m['title']) ?? '', 'n' => $pt($m['note']),
                ];
            }
        } catch (PDOException $e) { /* tabulka milníků ještě neexistuje */ }
        $b = $pdo->query("SELECT MIN(reported_at) AS a, MAX(reported_at) AS b FROM toilet_reports")->fetch();
        $p = $pdo->query("SELECT MIN(passed_at) AS a, MAX(passed_at) AS b FROM turnstile_logs")->fetch();
        $first = array_filter([viz_ts($b['a']), viz_ts($p['a'])]); $last = array_filter([viz_ts($b['b']), viz_ts($p['b'])]);
        viz_json(['inc' => $out, 'tol' => $tol, 'first' => $first ? min($first) : null, 'last' => $last ? max($last) : null, 'pseudo' => $pseudo_on, 'bl' => $show_bl, 'ms' => $ms_out]);
    }
    if ($what === 'data') {
        $od = isset($_GET['od']) && is_string($_GET['od']) ? $_GET['od'] : ''; $do = isset($_GET['do']) && is_string($_GET['do']) ? $_GET['do'] : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $od) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $do) || strtotime($od . ' UTC') === false || strtotime($do . ' UTC') === false) { viz_json(['error' => 'Neplatné datum.'], 400); }
        $t0 = strtotime($od . ' UTC'); $t1 = strtotime($do . ' UTC') + 86399;
        if ($t1 < $t0) { viz_json(['error' => 'Konec je před začátkem.'], 400); }
        if ($t1 - $t0 > 31 * 86400) { viz_json(['error' => 'Nejvýše 31 dní.'], 400); }
        $f0 = gmdate('Y-m-d H:i:s', $t0); $f1 = gmdate('Y-m-d H:i:s', $t1);
        // hlášení kabinek + poslední hlášení před začátkem (kvůli barvě úseku)
        $reports = [];
        $q = $pdo->prepare("SELECT * FROM toilet_reports WHERE reported_at BETWEEN ? AND ? ORDER BY reported_at ASC, id ASC");
        $q->execute([$f0, $f1]);
        foreach ($q->fetchAll() as $r) { $reports[] = ['id' => (int)$r['id'], 'c' => $r['cubicle'], 's' => $r['status'] === 'ok' ? 0 : 1, 't' => viz_ts($r['reported_at']), 'by' => $pn($r['reporter']), 'n' => $pt($r['note'] ?? null), 'v' => viz_is_virtual($r['note'] ?? null)]; }
        $prev = [];
        $qp = $pdo->prepare("SELECT * FROM toilet_reports WHERE cubicle = ? AND reported_at < ? ORDER BY reported_at DESC, id DESC LIMIT 1");
        foreach (array_keys($cubicles_list) as $c) {
            $qp->execute([$c, $f0]); $r = $qp->fetch();
            if ($r) { $prev[$c] = ['s' => $r['status'] === 'ok' ? 0 : 1, 't' => viz_ts($r['reported_at'])]; }
        }
        // průchody turnikety (jména ve slovníku; v náhledu pseudonymy)
        $ts_keys = array_keys($turnstiles_list);
        $names = []; $name_idx = []; $rows = []; $cov = []; $hid = ['pass' => 0, 'sig' => 0, 'vac' => 0];
        $limit = 30000; $truncated = false;
        $q = $pdo->prepare("SELECT person_name, direction, turnstile, passed_at FROM turnstile_logs WHERE passed_at BETWEEN ? AND ? ORDER BY passed_at ASC LIMIT " . ($limit + 1));
        $q->execute([$f0, $f1]);
        foreach ($q->fetchAll() as $n => $r) {
            if ($n >= $limit) { $truncated = true; break; }
            $ti = array_search($r['turnstile'], $ts_keys, true);
            if ($ti === false) { $ts_keys[] = $r['turnstile']; $ti = count($ts_keys) - 1; }
            $t = viz_ts($r['passed_at']);
            $d = gmdate('Y-m-d', $t);   // pokrytí docházky se počítá ze všech průchodů (i skrytých), je to údaj o úplnosti importu
            if (!isset($cov[$d])) { $cov[$d] = [$t, $t]; } else { $cov[$d][1] = $t; }
            if ($ro_nopass) { continue; }   // průchody jsou v náhledu vypnuté (pokrytí docházky výše se počítá dál, je to jen údaj o úplnosti importu)
            if ($is_bl($r['person_name'])) { $hid['pass']++; continue; }
            $nm = $pn($r['person_name']);
            if (!isset($name_idx[$nm])) { $name_idx[$nm] = count($names); $names[] = $nm; }
            $rows[] = [$t, $ti, $name_idx[$nm], strtoupper((string)$r['direction']) === 'IN' ? 0 : 1];
        }
        // pozorování osob (jen schválená; poznámky k pozorování jsou jen pro admina a sem nejdou)
        $sights = [];
        try {
            $q = $pdo->prepare("SELECT id, person_name, reporter, location, direction, seen_at FROM sightings WHERE status = 'approved' AND seen_at BETWEEN ? AND ? ORDER BY seen_at ASC");
            $q->execute([$f0, $f1]);
            foreach ($q->fetchAll() as $r) { if ($is_bl($r['person_name'])) { $hid['sig']++; continue; } $sights[] = ['id' => (int)$r['id'], 't' => viz_ts($r['seen_at']), 'who' => $pn($r['person_name']), 'by' => ($r['reporter'] === null || $r['reporter'] === '') ? null : $pn($r['reporter']), 'loc' => $r['location'], 'dir' => $r['direction']]; }
        } catch (PDOException $e) {}
        // dovolené IT
        $vac = [];
        try {
            $q = $pdo->prepare("SELECT v.date_from, v.date_to, s.person_name FROM it_vacations v JOIN it_staff s ON s.id = v.staff_id WHERE v.date_to >= ? AND v.date_from <= ? ORDER BY v.date_from ASC");
            $q->execute([substr($f0, 0, 10), substr($f1, 0, 10)]);
            foreach ($q->fetchAll() as $r) { if ($is_bl($r['person_name'])) { $hid['vac']++; continue; } $vac[] = ['a' => strtotime($r['date_from'] . ' UTC'), 'b' => strtotime($r['date_to'] . ' UTC') + 86400, 'who' => $pn($r['person_name'])]; }
        } catch (PDOException $e) {}
        // poznatky z hlavní aplikace (poznatky.sql): jen mimo náhledovou verzi a jen ty, které mají čas; poznatek o vyřazené osobě se skrývá jako její průchody
        $pozn = [];
        if (!$readonly) {
            try {
                $q = $pdo->prepare("SELECT id, reporter, person_name, happened_at, poznatek, created_at FROM poznatky WHERE happened_at BETWEEN ? AND ? ORDER BY happened_at ASC");
                $q->execute([$f0, $f1]);
                foreach ($q->fetchAll() as $r) {
                    $has_p = (string)$r['person_name'] !== '';
                    if ($has_p && $is_bl($r['person_name'])) { continue; }
                    $pozn[] = ['id' => (int)$r['id'], 't' => viz_ts($r['happened_at']), 'who' => $has_p ? $pn($r['person_name']) : null, 'by' => $r['reporter'], 'at' => viz_ts($r['created_at']), 'txt' => $r['poznatek']];
                }
            } catch (PDOException $e) { /* tabulka ještě neexistuje */ }
        }
        viz_json(['from' => $t0, 'to' => $t1, 'rep' => $reports, 'prev' => $prev, 'names' => $names, 'pass' => $rows, 'tsk' => $ts_keys, 'cov' => $cov, 'sig' => $sights, 'vac' => $vac, 'pozn' => $pozn, 'trunc' => $truncated, 'hid' => $hid]);
    }
    if ($what === 'people') {
        // jména pro našeptávač filtru osob (z celé docházky a pozorování; vyřazené osoby jen na požádání, v náhledu pseudonymy)
        $seen_p = []; $people = [];
        $add_p = function ($nm) use (&$seen_p, &$people, $is_bl, $pn) {
            $nm = trim((string)$nm);
            if ($nm === '' || $is_bl($nm)) { return; }
            $k = lh_name_norm($nm);
            if (isset($seen_p[$k])) { return; }
            $seen_p[$k] = true; $people[] = $pn($nm);
        };
        foreach ($pdo->query("SELECT DISTINCT person_name FROM turnstile_logs")->fetchAll(PDO::FETCH_COLUMN) as $n_p) { $add_p($n_p); }
        try { foreach ($pdo->query("SELECT DISTINCT person_name FROM sightings WHERE status = 'approved'")->fetchAll(PDO::FETCH_COLUMN) as $n_p) { $add_p($n_p); } } catch (PDOException $e) { /* tabulka ještě neexistuje */ }
        sort($people, SORT_STRING);
        viz_json(['people' => array_slice($people, 0, 5000)]);
    }
    viz_json(['error' => 'Neznámý požadavek.'], 400);
}

// ==========================================
// STRÁNKA
// ==========================================
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
$lh_nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . $lh_nonce . "'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'none'; frame-ancestors 'none'");
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>Vizualizace – Detektor Lorda Hoven</title>
<link rel="stylesheet" href="assets/viz.css?v=<?= htmlspecialchars($viz_version) ?>">
</head>
<body>
<div class="wrap">
 <header>
  <a class="btn sm" href="index.php">← Hlavní aplikace</a>
  <h1>Časová osa</h1>
  <span class="ver">Vizualizace v<?= htmlspecialchars($viz_version) ?><?= $readonly ? ' · náhled' : '' ?><?= $pseudo_on ? ' · jména anonymizována' : '' ?></span>
  <span class="spacer"></span>
  <button class="btn sm" id="modeBtn" type="button">Seznam</button>
  <button class="btn sm" id="themeBtn" type="button">Tmavý</button>
 </header>
 <div class="bar" id="presets">
  <button class="btn sm" data-p="1" type="button">Poslední den</button>
  <button class="btn sm" data-p="3" type="button">3 dny</button>
  <button class="btn sm" data-p="7" type="button">7 dní</button>
  <button class="btn sm" data-p="14" type="button">14 dní</button>
  <button class="btn sm only-m" id="moreBtn" type="button" aria-expanded="false">Filtr ▾</button>
 </div>
 <div class="bar" id="more">
  <label for="fOd">od</label><input type="date" id="fOd">
  <label for="fDo">do</label><input type="date" id="fDo">
  <button class="btn sm" id="fGo" type="button">Zobrazit</button>
 </div>
 <div class="chips" id="chips"></div>
 <div id="filters">
  <div class="frow">
   <label for="pInp">Osoby</label>
   <div class="pbox"><input type="search" id="pInp" placeholder="Najdi osobu (průchody a pozorování jen těchto osob)" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="pSug" aria-autocomplete="list"><div id="pSug" role="listbox"></div></div>
   <div class="pchips" id="pSel"></div>
  </div>
  <details class="icard" id="icard">
   <summary><span>Časová okna incidentů</span> <span class="small" id="icnt"></span></summary>
   <div class="ibody">
    <div class="frow" id="labRow">
     <label>Zobrazit jen incidenty se štítkem:</label>
     <div class="pchips" id="labChips"></div>
     <div class="seg" id="labMode" role="group" aria-label="Spojení štítků">
      <button type="button" data-m="union" class="on" title="Zobrazí se incident, který má alespoň jeden z vybraných štítků (štítek A nebo štítek B).">Sjednocení</button>
      <button type="button" data-m="inter" title="Zobrazí se jen incident, který má všechny vybrané štítky najednou (štítek A i štítek B).">Průnik</button>
     </div>
    </div>
    <div class="small" id="labInfo"></div>
    <div class="ilist" id="ilist"></div>
   </div>
  </details>
 </div>
 <div id="stage">
  <canvas id="cv" tabindex="0" aria-label="Časová osa událostí"></canvas>
  <div id="msg"></div>
  <div id="load">načítám…</div>
  <div class="zoom"><button class="btn" id="zOut" type="button" aria-label="Oddálit">−</button><button class="btn" id="zIn" type="button" aria-label="Přiblížit">+</button></div>
 </div>
 <div id="list"></div>
 <div class="note" id="foot"></div>
</div>
<div id="tip"></div>
<div id="sheet" role="dialog" aria-modal="false"><button class="x" id="sheetX" type="button" aria-label="Zavřít">×</button><div id="sheetBody"></div></div>
<script nonce="<?= htmlspecialchars($lh_nonce) ?>">
window.LH_BOOT = <?= json_encode([
    'cubicles' => array_map(function ($k) use ($cubicles_list, $cubicles_short) { return ['key' => $k, 'name' => $cubicles_list[$k], 'short' => $cubicles_short[$k]]; }, array_keys($cubicles_list)),
    'cubShort' => $cubicles_short,
    'turnstiles' => array_map(function ($k) use ($turnstiles_list, $turnstiles_short) { return ['key' => $k, 'name' => $turnstiles_list[$k], 'short' => $turnstiles_short[$k]]; }, array_keys($turnstiles_list)),
    'locs' => $wit_locations, 'dirs' => $wit_directions, 'ro' => $readonly, 'nopass' => $ro_nopass,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="assets/viz.js?v=<?= htmlspecialchars($viz_version) ?>"></script>
</body>
</html>
