<?php
/*
 * Společné funkce pro index.php a vizualizace.php (dříve duplicitní kopie v obou souborech):
 * náhrady funkcí z mbstring, normalizace jmen, pseudonymizace pro náhledovou verzi, čtení nastavení z databáze,
 * načtení konfigurace a určení režimu (plná verze / náhled). Soubor nespouštěj přímo, jen ho načítají aplikační soubory.
 */
if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'lh_common.php') { http_response_code(403); exit; }

// Náhrady funkcí z rozšíření mbstring, pokud je hosting nemá (aplikace pracuje s češtinou v UTF-8)
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) { return (int)preg_match_all('/./us', (string)$s); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len = null, $enc = null) {
        $chars = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY);
        return implode('', array_slice($chars, $start, $len));
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = null) {
        return strtr(strtolower((string)$s), [
            'Á' => 'á', 'Č' => 'č', 'Ď' => 'ď', 'É' => 'é', 'Ě' => 'ě', 'Í' => 'í', 'Ň' => 'ň', 'Ó' => 'ó', 'Ř' => 'ř',
            'Š' => 'š', 'Ť' => 'ť', 'Ú' => 'ú', 'Ů' => 'ů', 'Ý' => 'ý', 'Ž' => 'ž', 'Ä' => 'ä', 'Ö' => 'ö', 'Ü' => 'ü',
            'Ľ' => 'ľ', 'Ĺ' => 'ĺ', 'Ô' => 'ô', 'Ŕ' => 'ŕ',
        ]);
    }
}

// ==========================================
// NORMALIZACE JMEN, PSEUDONYMY, NASTAVENÍ
// Pseudonymy (kódy osob) se v náhledové verzi počítají z klíče $lh_pseudo_key; jména se porovnávají bez ohledu na velikost písmen a diakritiku.
// ==========================================
function wit_key($name) {
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$name)));
}

function lh_name_norm($n) {
    return strtr(wit_key($n), ['á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z']);
}

function lh_pseudo_b36($name) {
    $h = hash_hmac('sha256', lh_name_norm((string)$name), $GLOBALS['lh_pseudo_key']);
    return str_pad(base_convert(substr($h, 0, 12), 16, 36), 10, '0', STR_PAD_LEFT);
}

function lh_pseudo_enabled($pdo) {
    global $readonly;
    static $on = null;
    if (!$readonly) { return false; }
    if ($on === null) { $on = lh_setting($pdo, 'pseudonymize_readonly') !== '0'; }
    return $on;
}

function lh_pseudo_name($name) {
    static $cache = [], $used = [];
    $name = (string)$name;
    if ($name === '' || $name === 'N/A') { return $name; }
    $key = lh_name_norm($name);
    if (!isset($cache[$key])) {
        $b36 = lh_pseudo_b36($name);
        $code = '';
        for ($len = 5; $len <= 10; $len++) {   // při (nepravděpodobné) shodě kódu dvou různých osob se kód prodlouží
            $code = 'Osoba ' . strtoupper(substr($b36, 0, $len));
            if (!isset($used[$code]) || $used[$code] === $key) { break; }
        }
        $used[$code] = $key;
        $cache[$key] = $code;
    }
    return $cache[$key];
}

function lh_pseudo_text($text) {
    static $re_long = null, $re_short = null;
    global $pdo;
    $text = (string)$text;
    if ($text === '') { return $text; }
    if ($re_long === null) {
        $known = [];
        foreach ([
            "SELECT person_name FROM turnstile_logs GROUP BY person_name", "SELECT name FROM employees", "SELECT person_name FROM incident_innocents",
            "SELECT person_name FROM blacklisted_suspects", "SELECT person_name FROM it_staff", "SELECT person_name FROM sightings",
            "SELECT reporter FROM sightings GROUP BY reporter", "SELECT reporter FROM toilet_reports GROUP BY reporter",
        ] as $q_sql) {
            try { foreach ($pdo->query($q_sql)->fetchAll(PDO::FETCH_COLUMN) as $k_n) { $k_n = trim((string)$k_n); if (mb_strlen($k_n) >= 2) { $known[$k_n] = true; } } } catch (PDOException $e) { /* tabulka ještě neexistuje */ }
        }
        $long = []; $short = [];
        foreach (array_keys($known) as $k_n) { if (mb_strlen($k_n) >= 4) { $long[] = preg_quote($k_n, '/'); } else { $short[] = preg_quote($k_n, '/'); } }
        usort($long, function ($a, $b) { return strlen($b) <=> strlen($a); });
        $b_pre = '(?<![\p{L}\p{N}_])'; $b_post = '(?![\p{L}\p{N}_])';
        $re_long = $long ? '/' . $b_pre . '(?:' . implode('|', $long) . ')' . $b_post . '/iu' : '';
        $re_short = $short ? '/' . $b_pre . '(?:' . implode('|', $short) . ')' . $b_post . '/u' : '';   // krátká označení (např. iniciály) jen přesně
    }
    $cb = function ($m) { return lh_pseudo_name($m[0]); };
    if ($re_long !== '') { $text = preg_replace_callback($re_long, $cb, $text); }
    if ($re_short !== '') { $text = preg_replace_callback($re_short, $cb, $text); }
    return $text;
}

function lh_setting($pdo, $key) {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? '' : (string)$v;
    } catch (PDOException $e) {
        return '';
    }
}

// ==========================================
// KONFIGURACE A REŽIM
// ==========================================
// Načte lh_config.php (o úroveň výš než aplikace, jinak vedle ní). Soubor patří MIMO veřejný adresář a nikdy se nesdílí (vzor: config/config.example.php).
function lh_load_config() {
    if (!defined('LH_APP')) { define('LH_APP', true); }
    $file = __DIR__ . '/../lh_config.php';
    if (!is_file($file)) { $file = __DIR__ . '/lh_config.php'; }
    if (!is_file($file)) { http_response_code(500); die('Chybí konfigurační soubor lh_config.php (viz docs/CONFIGURATION.md).'); }
    $cfg = require $file;
    if (!is_array($cfg)) { http_response_code(500); die('Konfigurační soubor musí vracet pole.'); }
    return $cfg;
}

// Vrátí klíče, které v konfiguraci chybí nebo jsou prázdné
function lh_missing_config($config, $keys) {
    return array_values(array_filter($keys, function ($k) use ($config) { return empty($config[$k]); }));
}

// Režim požadavku: plná verze (zápis) je jen s platnou tajnou hlavičkou X-Lh-Proxy (přidává ji pouze naše reverzní proxy) A původní adresou
// ze seznamu full_hosts. Cokoli jiného je vždy jen náhled. Původní adresa je první hodnota X-Forwarded-Host, bez ní se bere Host.
// Hlavička X-Lh-Readonly: 1 náhled vynutí vždy.
function lh_request_mode($config) {
    $proxy_secret_ok = !empty($_SERVER['HTTP_X_LH_PROXY']) && hash_equals((string)$config['proxy_secret'], (string)$_SERVER['HTTP_X_LH_PROXY']);
    $full_hosts = array_map('strtolower', (array)($config['full_hosts'] ?? []));
    $orig_host = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))[0]);
    if ($orig_host === '') { $orig_host = (string)($_SERVER['HTTP_HOST'] ?? ''); }
    $orig_host = strtolower(preg_replace('/:\d+$/', '', trim($orig_host)));
    $readonly = !$proxy_secret_ok || !in_array($orig_host, $full_hosts, true) || (($_SERVER['HTTP_X_LH_READONLY'] ?? '') === '1');
    return ['proxy_secret_ok' => $proxy_secret_ok, 'full_hosts' => $full_hosts, 'orig_host' => $orig_host, 'readonly' => $readonly];
}

// DSN pro PDO: db_host (volitelně s db_port), nebo unixový soket db_socket (některé hostingy a testy)
function lh_pdo_dsn($config) {
    $name = (string)$config['db_name'];
    if (!empty($config['db_socket'])) { return 'mysql:unix_socket=' . $config['db_socket'] . ';dbname=' . $name . ';charset=utf8mb4'; }
    return 'mysql:host=' . $config['db_host'] . (!empty($config['db_port']) ? ';port=' . (int)$config['db_port'] : '') . ';dbname=' . $name . ';charset=utf8mb4';
}

// Ověření hesla admina: v konfiguraci může být čisté heslo, nebo hash z password_hash() (viz tools/hash_password.php; hash je doporučený)
function lh_verify_admin_password($stored, $input) {
    $stored = (string)$stored; $input = (string)$input;
    if (preg_match('/^\$(2[aby]|argon2i|argon2id)\$/', $stored)) { return password_verify($input, $stored); }
    return hash_equals($stored, $input);
}

// ==========================================
// OCHRANA PROTI CSRF
// Do každého formuláře s method="POST" ve vykresleném HTML se vloží skrytý token z relace (volá se jako výstupní filtr ob_start).
// ==========================================
function lh_csrf_token() {
    if (empty($_SESSION['lh_csrf'])) { $_SESSION['lh_csrf'] = bin2hex(random_bytes(32)); }
    return $_SESSION['lh_csrf'];
}
function lh_csrf_inject($html) {
    $tok = htmlspecialchars(lh_csrf_token(), ENT_QUOTES);
    return preg_replace_callback('~<form\b[^>]*\bmethod\s*=\s*["\']?post["\']?[^>]*>~i', function ($m) use ($tok) {
        return $m[0] . '<input type="hidden" name="lh_csrf" value="' . $tok . '">';
    }, $html);
}
