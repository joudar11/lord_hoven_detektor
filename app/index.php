<?php
/*
 * Detektor Lorda Hoven
 * Verze: 5.00
 *
 * Webová aplikace pro statistickou analýzu průchodů turniketů v časových oknech incidentů (hlášení stavu toalet).
 * Licence: MIT (viz LICENSE). Přehled změn: CHANGELOG.md. Instalace: docs/INSTALL.md. Konfigurace: docs/CONFIGURATION.md.
 * Pravidlo verzí: každá nová verze = +0.01, větší změna = +0.10 (verzi čte aplikace z řádku „Verze:“ výše).
 */
require_once __DIR__ . '/lh_common.php';


// ==========================================
// 0. KONFIGURACE S TAJEMSTVÍMI (mimo zdrojový kód)
// Soubor lh_config.php patří MIMO veřejný adresář (vzor viz config.example.php).
// ==========================================
define('LH_APP', true);
$app_version = preg_match('/Verze:\s*([0-9]+(?:\.[0-9]+)*)/', (string)file_get_contents(__FILE__, false, null, 0, 800), $vm) ? $vm[1] : '?';
$config = lh_load_config();
// Klíč pro pseudonymy osob v náhledové verzi (volitelně pseudo_secret v konfiguraci, jinak se odvodí z ostatních tajných hodnot)
$lh_pseudo_key = (string)($config['pseudo_secret'] ?? hash('sha256', 'lh-pseudo|' . ($config['device_secret'] ?? '') . '|' . ($config['proxy_secret'] ?? '')));
$backup_token = (string)($config['backup_token'] ?? ''); // volitelný klíč pro automatické stažení zálohy domácím serverem
// SMTP (volitelné): upozornění adminům, záznam akcí admina a týdenní souhrn
$smtp = [
    'host' => (string)($config['smtp_host'] ?? ''), 'port' => (int)($config['smtp_port'] ?? 587),
    'user' => (string)($config['smtp_user'] ?? ''), 'pass' => (string)($config['smtp_pass'] ?? ''),
    'from' => (string)($config['smtp_from'] ?? ($config['smtp_user'] ?? '')),
    'verify' => ($config['smtp_verify_tls'] ?? true) !== false,
];

$lh_missing = lh_missing_config($config, ['allowed_proxy_ip', 'db_host', 'db_name', 'db_user', 'db_pass', 'admin_password', 'proxy_secret', 'device_secret', 'full_hosts']);
if ($lh_missing) { http_response_code(500); die('Neúplná konfigurace, chybí: ' . implode(', ', $lh_missing) . ' (viz docs/CONFIGURATION.md).'); }

// ==========================================
// 1. RESTRIKCE IP ADRESY REVERZNÍ PROXY
// ==========================================
$allowed_proxy_ip = $config['allowed_proxy_ip'];
$proxy_ip = $_SERVER['REMOTE_ADDR'] ?? '';

if ($proxy_ip !== $allowed_proxy_ip) {
    http_response_code(403);
    die("Přístup odepřen (403 Forbidden). Vaše IP ($proxy_ip) nemá oprávnění k přístupu.");
}

// Relace: cookie jen pro HTTP (HttpOnly), SameSite=Lax a Secure, když požadavek přišel přes HTTPS (z naší důvěryhodné proxy), přísný režim ID relace
$lh_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $lh_https, 'httponly' => true, 'samesite' => 'Lax']);
session_start();
lh_csrf_token();
// Bezpečnostní hlavičky; skripty se spouštějí jen z vlastních souborů nebo s jednorázovým nonce (žádné inline obsluhy událostí, žádné externí zdroje)
$lh_nonce = base64_encode(random_bytes(16));
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . $lh_nonce . "'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

// ==========================================
// 2. KONFIGURACE DATABÁZE A HESLA ADMINA
// ==========================================
$db_host = $config['db_host'];
$db_name = $config['db_name'];
$db_user = $config['db_user'];
$db_pass = $config['db_pass'];

$admin_password = $config['admin_password']; // Heslo pro přihlášení admina
$is_admin = !empty($_SESSION['is_admin']); // Admin session (aktivní po přihlášení)

// Režim zápisu (plná verze) viz lh_request_mode() v lh_common.php. Cokoli jiného je vždy jen náhled: žádný admin a žádný POST nic nezmění.
$proxy_secret = $config['proxy_secret']; // Tajná hodnota hlavičky X-Lh-Proxy, kterou přidává pouze naše reverzní proxy
$lh_mode = lh_request_mode($config);
$proxy_secret_ok = $lh_mode['proxy_secret_ok'];
$full_hosts = $lh_mode['full_hosts'];
$orig_host = $lh_mode['orig_host'];
$readonly = $lh_mode['readonly'];
// Adresa aplikace pro odkazy v e-mailech: app_url z konfigurace, jinak podle původní adresy požadavku
$app_url = !empty($config['app_url']) ? rtrim((string)$config['app_url'], '/') . '/' : ($orig_host !== '' ? 'https://' . $orig_host . '/' : '/');
if ($readonly) {
    $is_admin = false;
    // Výjimka: skripty domácího serveru (záloha, týdenní souhrn) volají hosting napřímo a ověřují se vlastním klíčem v hlavičce X-Backup-Token
    $cron_call = in_array($_POST['action'] ?? '', ['backup_token', 'digest_send'], true) && !empty($_SERVER['HTTP_X_BACKUP_TOKEN']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$cron_call) {
        $_SESSION['flash_message'] = 'Tato adresa je jen pro náhled, změny zde nejsou možné.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: index.php');
        exit;
    }
}
// Ochrana proti CSRF: každý POST musí nést token z relace; výjimka jsou jen volání domácího serveru (záloha, souhrn) s klíčem v hlavičce X-Backup-Token
$lh_cron_post = in_array($_POST['action'] ?? '', ['backup_token', 'digest_send'], true) && !empty($_SERVER['HTTP_X_BACKUP_TOKEN']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$lh_cron_post && !hash_equals((string)($_SESSION['lh_csrf'] ?? ''), (string)($_POST['lh_csrf'] ?? ''))) {
    $_SESSION['flash_message'] = 'Formulář vypršel nebo je neplatný (bezpečnostní token). Obnov stránku a zkus to znovu.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: index.php');
    exit;
}
$device_secret = $config['device_secret']; // Klíč pro podpis ID zařízení; po nasazení neměnit (všechna zařízení by se tvářila jako nová)
try {
    $pdo = new PDO(lh_pdo_dsn($config), $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Chyba připojení k databázi: " . $e->getMessage());
}

lh_retention_cleanup($pdo, $config);

// Náhledová verze: admin může vypnout jednotlivé části (ro_hidden = seznam klíčů). Platí jen pro náhled, plná verze a admin vidí vždy vše.
$ro_sections = [
    'timeline' => ['Časová osa', 'Tlačítko „Časová osa“ a celá stránka vizualizace.'],
    'passes' => ['Průchody', 'Konkrétní průchody turniketů (časy, turnikety) v detailu podezřelých, v pohledu Podle incidentů a ve vrstvě průchodů časové osy. Body a počty zůstávají.'],
    'photos' => ['Fotky', 'Záložka Fotodokumentace a stahování fotek.'],
    'stats' => ['Statistika', 'Záložka Statistiky a předpověď.'],
    'history' => ['Historie hlášení', 'Záložka Historie hlášení.'],
];
$ro_hidden = array_values(array_intersect(array_keys($ro_sections), array_filter(array_map('trim', explode(',', lh_setting($pdo, 'ro_hidden'))))));
$ro_no = [];
foreach ($ro_sections as $ro_k => $ro_v) { $ro_no[$ro_k] = $readonly && in_array($ro_k, $ro_hidden, true); }

$cubicles_list = [
    'leva' => 'Levá kabinka',
    'prava' => 'Pravá kabinka',
    'invalide' => 'Kabinka Invalidé'
];

// Seznam turniketů
$turnstiles_list = [
    'vlevo' => 'Turniket VLEVO',
    'vpravo' => 'Turniket VPRAVO',
    'patro_1' => '1. patro',
    'it_dvere' => 'IT dveře (4. patro)'
];

// ==========================================
// 2a. ID ZAŘÍZENÍ (podepsaná cookie) A VEŘEJNÁ IP KLIENTA
// Slouží jen pro admin log, běžnému uživateli se nikde nezobrazuje.
// ==========================================
$device_id = null;
if (!empty($_COOKIE['lh_v']) && strpos($_COOKIE['lh_v'], '.') !== false) {
    list($cookie_id, $cookie_sig) = explode('.', $_COOKIE['lh_v'], 2);
    if (preg_match('/^[a-f0-9]{32}$/', $cookie_id) && hash_equals(substr(hash_hmac('sha256', $cookie_id, $device_secret), 0, 16), $cookie_sig)) {
        $device_id = $cookie_id;
    }
}
if ($device_id === null) {
    $device_id = bin2hex(random_bytes(16));
}
// Cookie se obnovuje při každé návštěvě (klouzavá platnost). Prohlížeče stropují délku:
// Chrome na 400 dní, Brave na 6 měsíců, takže delší hodnota nemá smysl.
setcookie('lh_v', $device_id . '.' . substr(hash_hmac('sha256', $device_id, $device_secret), 0, 16), [
    'expires' => time() + 400 * 86400,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Hlavičku X-Forwarded-For lze brát jen proto, že požadavek prošel IP kontrolou výše (přišel z naší proxy).
// Bere se poslední hodnota, kterou přidala proxy, takže ji klient nemůže podvrhnout.
$client_ip = null;
if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $xff_parts = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
    $xff_candidate = end($xff_parts);
    if (filter_var($xff_candidate, FILTER_VALIDATE_IP)) {
        $client_ip = $xff_candidate;
    }
}

// Přihlášení bez hesla pro vybrané IP klienta: po kliknutí na "Přihlásit" projde jakékoli heslo (i prázdné).
// Session se nikdy neaktivuje sama a jde normálně odhlásit. Platí jen tehdy, když požadavek nese tajnou hlavičku
// X-Lh-Proxy (přidává ji jen naše proxy, klient ji nezná), takže podvržená hlavička X-Forwarded-For nestačí.
// Seznam IP je v konfiguraci (ip_login_ips). Výchozí stav je VYPNUTO (prázdný seznam). Používej jen v důvěryhodné síti: kdokoli z takové IP se stane adminem.
$ip_login_ips = array_values(array_filter((array)($config['ip_login_ips'] ?? []), function ($ip) { return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP); }));
$ip_login_ok = $proxy_secret_ok && $client_ip !== null && in_array($client_ip, $ip_login_ips, true);

// Identita přihlášeného uživatele z reverzní proxy (Zoraxy Auth / Forward Auth). Přijme se jen s platnou tajnou hlavičkou X-Lh-Proxy,
// takže ji klient podvrhnout nemůže. Názvy hlaviček jdou změnit v konfiguraci (auth_user_header, auth_name_header).
$auth_user = '';
$auth_display = '';
$auth_hdr = function ($name) { return trim((string)($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '')); };
$auth_valid = function ($v) { return $v !== '' && mb_strlen($v) <= 100 && preg_match('/^[\p{L}\p{N} ._@+\-\']+$/u', $v); };
if ($proxy_secret_ok) {
    $au = $auth_hdr($config['auth_user_header'] ?? 'Remote-User');
    $an = $auth_hdr($config['auth_name_header'] ?? 'Remote-Name');
    if ($auth_valid($au)) { $auth_user = $au; $auth_display = $auth_valid($an) ? $an : $au; }
}
// Admin podle seznamu uživatelů v konfiguraci (admin_users); na náhledové adrese nikdy
$identity_admin = !$readonly && $auth_user !== '' && in_array(strtolower($auth_user), array_map('strtolower', (array)($config['admin_users'] ?? [])), true);
if ($identity_admin) { $is_admin = true; }
$auth_url = function ($key, $default) use ($config) { $u = (string)($config[$key] ?? $default); return preg_match('~^https://~i', $u) ? $u : ''; };
$auth_logout_url = $auth_url('auth_logout_url', '');   // tlačítko Odhlásit se zobrazí jen při nastavení v konfiguraci
$auth_account_url = $auth_url('auth_account_url', '');   // tlačítko Můj účet se zobrazí jen při nastavení v konfiguraci
$audit_user = $auth_user === '' ? '' : ($auth_display !== $auth_user ? $auth_display . ' (' . $auth_user . ')' : $auth_user);

// ==========================================
// 2b. HISTORIE HLÁŠENÍ (VÝPIS + AJAX)
// Limit zobrazených hlášení se týká jen tohoto výpisu, výpočet incidentů čte všechna hlášení.
// ==========================================
$history_default_limit = 10;
$history_public_max = 150; // nepřihlášený uživatel; admin bez omezení

function fetch_report_history($pdo, $filter_date, $filter_cubicle, $limit, $filter_status = '') {
    $where_clauses = [];
    $params = [];
    if (!empty($filter_date)) { $where_clauses[] = "reported_at >= ? AND reported_at < DATE_ADD(?, INTERVAL 1 DAY)"; $params[] = $filter_date; $params[] = $filter_date; }
    if (!empty($filter_cubicle)) { $where_clauses[] = "cubicle = ?"; $params[] = $filter_cubicle; }
    if (in_array($filter_status, ['ok', 'zesrana'], true)) { $where_clauses[] = "status = ?"; $params[] = $filter_status; }
    $where_sql = !empty($where_clauses) ? " WHERE " . implode(' AND ', $where_clauses) : '';

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM toilet_reports" . $where_sql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM toilet_reports" . $where_sql . " ORDER BY reported_at DESC, id DESC LIMIT " . (int)$limit);
    $stmt->execute($params);

    return [$stmt->fetchAll(), $total];
}

function load_device_labels($pdo, $is_admin) {
    if (!$is_admin) return [];
    try {
        return $pdo->query("SELECT device_id, label FROM device_labels")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        return [];
    }
}

function render_report_rows($reports, $cubicles_list, $is_admin, $device_labels = []) {
    ob_start();
    if (empty($reports)): ?>
        <tr><td colspan="<?= $is_admin ? 7 : 5 ?>" class="text-center text-body-secondary">Žádné záznamy nenalezeny.</td></tr>
    <?php else: ?>
        <?php foreach ($reports as $r): ?>
            <tr>
                <td><strong><?= htmlspecialchars(lh_d($r['reported_at'])) ?></strong></td>
                <td><?= htmlspecialchars($cubicles_list[$r['cubicle']] ?? strtoupper($r['cubicle'])) ?></td>
                <td>
                    <span class="badge <?= $r['status'] === 'ok' ? 'badge-ok' : 'badge-zesrana' ?>">
                        <?= htmlspecialchars(strtoupper($r['status'])) ?>
                    </span>
                </td>
                <td data-label="Nahlásil"><?= htmlspecialchars($r['reporter']) ?><?php if (!empty($r['note'])): ?><div class="small text-body-secondary lh-note"><?= htmlspecialchars($r['note']) ?></div><?php endif; ?></td>
                <td class="text-body-secondary small" data-label="Zaznamenáno"><?= htmlspecialchars(lh_d($r['created_at'])) ?></td>
                <?php if ($is_admin): ?>
                <td class="small" data-label="Zařízení">
                    <?php $dev = $r['device_id'] ?? null; ?>
                    <?php if ($dev): ?>
                        <span class="font-monospace"><?= htmlspecialchars($device_labels[$dev] ?? ('#' . substr($dev, 0, 6))) ?></span>
                        <button type="button" class="btn btn-link btn-sm p-0 ms-1 btn-device-label" data-device="<?= htmlspecialchars($dev) ?>" data-label="<?= htmlspecialchars($device_labels[$dev] ?? '') ?>" title="Pojmenovat zařízení">✎</button>
                    <?php else: ?>
                        <span class="text-body-secondary">–</span>
                    <?php endif; ?>
                    <?php if (!empty($r['client_ip'])): ?>
                        <div class="text-body-secondary"><?= htmlspecialchars($r['client_ip']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="text-nowrap">
                    <button type="button" class="btn btn-sm btn-outline-primary btn-edit-report"
                            data-bs-toggle="modal" data-bs-target="#editReportModal"
                            data-id="<?= (int)$r['id'] ?>"
                            data-reporter="<?= htmlspecialchars($r['reporter']) ?>"
                            data-cubicle="<?= htmlspecialchars($r['cubicle']) ?>"
                            data-status="<?= htmlspecialchars($r['status']) ?>"
                            data-note="<?= htmlspecialchars($r['note'] ?? '') ?>"
                            data-reported-at="<?= date('Y-m-d\TH:i', strtotime($r['reported_at'])) ?>">Upravit</button>
                    <form method="POST" class="d-inline" data-confirm="Opravdu smazat toto hlášení?">
                        <input type="hidden" name="action" value="delete_report">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Smazat</button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
    <?php endif;
    return ob_get_clean();
}

// Fotodokumentace: složka s podsložkami podle čísla incidentu (výchozí fotodokumentace vedle index.php, jinou cestu lze nastavit v konfiguraci: photo_dir)
$photo_dir = rtrim((string)($config['photo_dir'] ?? (__DIR__ . '/fotodokumentace')), '/\\');
if (isset($_GET['photo']) && is_string($_GET['photo'])) {
    if ($ro_no['photos']) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); exit('Fotky nejsou v této verzi k dispozici.'); }
    // Soubor se vydá jen tehdy, když je na seznamu souborů incidentu (žádné cesty „..“, skryté soubory ani odkazy mimo složku)
    $ph = explode('/', (string)$_GET['photo'], 2);
    $ph_file = null;
    if (ctype_digit($ph[0] ?? '') && isset($ph[1]) && $ph[1] !== '' && strpos($ph[1], '/') === false && strpos($ph[1], '\\') === false) {
        foreach (lh_photo_files($photo_dir, (int)$ph[0]) as $f_ph) { if ($f_ph['name'] === $ph[1]) { $ph_file = $f_ph; break; } }
    }
    if (!$ph_file) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Soubor nenalezen.');
    }
    $ph_path = realpath($photo_dir) . '/' . (int)$ph[0] . '/' . $ph_file['name'];
    $ph_types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif'];
    $ph_ext = strtolower(pathinfo($ph_file['name'], PATHINFO_EXTENSION));
    $ph_inline = isset($ph_types[$ph_ext]);   // obrázky se zobrazí, cokoli jiného se jen stáhne (nikdy se nespustí jako stránka)
    header('Content-Type: ' . ($ph_inline ? $ph_types[$ph_ext] : 'application/octet-stream'));
    header('Content-Disposition: ' . ($ph_inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($ph_file['name']));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . filesize($ph_path));
    readfile($ph_path);
    exit;
}

// AJAX: dotažení dalších hlášení po změně limitu (limit se nikde neukládá, po načtení stránky je vždy výchozí)
if (($_GET['ajax'] ?? '') === 'history') {
    if ($ro_no['history']) { http_response_code(403); header('Content-Type: application/json; charset=utf-8'); exit('{"error":"forbidden"}'); }
    $limit = max(1, (int)($_GET['limit'] ?? $history_default_limit));
    if (!$is_admin) { $limit = min($limit, $history_public_max); }

    list($ajax_reports, $ajax_total) = fetch_report_history($pdo, $_GET['date'] ?? '', $_GET['cubicle'] ?? '', $limit, $_GET['status'] ?? '');
    if (lh_pseudo_enabled($pdo)) { lh_pseudo_reports($ajax_reports); }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'html' => render_report_rows($ajax_reports, $cubicles_list, $is_admin, load_device_labels($pdo, $is_admin)),
        'shown' => count($ajax_reports),
        'total' => $ajax_total,
        'limit' => $limit
    ]);
    exit;
}

// ==========================================
// 2c. SVĚDECTVÍ (pozorování osob na toaletách): nastavení a pomocné funkce
// ==========================================
$wit_locations = [
    'invalide' => 'Kabinka invalidé',
    'leva' => 'Levá kabinka',
    'prava' => 'Pravá kabinka',
    'toalety_kabinka' => 'Toalety, určitě kabinka (levá nebo pravá)',
    'toalety_nevim' => 'Toalety, nevím (kabinka nebo pisoár)',
    'pisoar' => 'Pisoár (nemá vliv na žebříček)',
];
$wit_loc_short = ['invalide' => 'kabinka invalidé', 'leva' => 'levá kabinka', 'prava' => 'pravá kabinka',
                  'toalety_kabinka' => 'toalety (kabinka)', 'toalety_nevim' => 'toalety (nevím)', 'pisoar' => 'pisoár'];
$wit_directions = ['vychazel' => 'vycházel', 'vchazel' => 'vcházel', 'poblizu' => 'jen poblíž'];
$wit_dir_weights = ['vychazel' => 1.0, 'vchazel' => 0.5, 'poblizu' => 0.2];   // váha podle toho, co osoba dělala
$wit_p_cubicle = 0.35;          // podíl návštěv toalet, při kterých osoba použije kabinku (ne pisoár); předpoklad, nastavit podle prostředí
$wit_threshold = 0.25;          // minimální pravděpodobnost nepřesného svědectví, aby se počítalo
$wit_max_per_device_day = 10;   // nejvýše tolik pozorování za den z jednoho zařízení


// Jméno pro porovnání: malá písmena bez českých diakritických znamének

// Základ pseudonymu (10 znaků base36) pro jméno; kód „Osoba XXXXX“ je jeho začátek (5 znaků, při shodě dvou osob delší)

// Překladač pseudonymů pro admina: k vloženému kódu (nebo textu s kódy „Osoba XXXXX“) dohledá jména tak, že kód spočítá pro každé jméno z databáze
// stejnou funkcí jako náhled. Nic se neukládá ani nezapisuje. Shoda: přesná; „first5“ = kód je delší než běžný, shoda podle prvních 5 znaků;
// „fuzzy“ = liší se jediný z prvních 5 znaků (překlep).
function lh_pseudo_translate($pdo, $text) {
    $text = (string)$text;
    $sources = [
        'docházka' => "SELECT person_name FROM turnstile_logs GROUP BY person_name",
        'zaměstnanec' => "SELECT name FROM employees",
        'nevinný' => "SELECT person_name FROM incident_innocents GROUP BY person_name",
        'vyřazen ze žebříčku' => "SELECT person_name FROM blacklisted_suspects",
        'IT oddělení' => "SELECT person_name FROM it_staff",
        'důvěryhodný' => "SELECT person_name FROM trusted_persons",
        'pozorování (osoba)' => "SELECT person_name FROM sightings GROUP BY person_name",
        'pozorování (hlásící)' => "SELECT reporter FROM sightings GROUP BY reporter",
        'hlásící hlášení' => "SELECT reporter FROM toilet_reports GROUP BY reporter",
    ];
    $idents = [];   // normalizované jméno => ['b36' => ..., 'forms' => [zápis => [zdroj => true]]]
    foreach ($sources as $label => $sql) {
        try {
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $nm) {
                $nm = trim((string)$nm);
                if ($nm === '') { continue; }
                $k = lh_name_norm($nm);
                if (!isset($idents[$k])) { $idents[$k] = ['b36' => strtoupper(lh_pseudo_b36($nm)), 'forms' => []]; }
                $idents[$k]['forms'][$nm][$label] = true;
            }
        } catch (PDOException $e) { /* tabulka ještě neexistuje */ }
    }
    $re_prefixed = '/(?<![\p{L}\p{N}])O?soba\s*([0-9A-Za-z]{5,10})(?![\p{L}\p{N}])/iu';
    $re_bare = '/(?<![\p{L}\p{N}])([0-9A-Za-z]{5,10})(?![\p{L}\p{N}])/u';
    $re = $re_prefixed;
    if (!preg_match($re_prefixed, $text)) {
        $rest = preg_replace($re_bare, '', $text);   // jen samotné kódy (např. vložený seznam kódů)
        if (preg_match($re_bare, $text) && trim(preg_replace('/[\s,;]+/u', '', $rest)) === '') { $re = $re_bare; }
        else { return ['codes' => [], 'translated' => $text, 'note' => 'V textu jsem nenašel žádný kód (hledám tvar „Osoba 0LOA5“ nebo samotné kódy o 5 až 10 znacích).']; }
    }
    $match = function ($code) use ($idents) {
        $c = strtoupper($code); $len = strlen($c); $keys = [];
        foreach ($idents as $k => $id) { if (strncmp($id['b36'], $c, $len) === 0) { $keys[] = $k; } }
        if ($keys) { return ['exact', $keys]; }
        if ($len > 5) { foreach ($idents as $k => $id) { if (strncmp($id['b36'], $c, 5) === 0) { $keys[] = $k; } } if ($keys) { return ['first5', $keys]; } }
        foreach ($idents as $k => $id) {
            $diff = 0;
            for ($i = 0; $i < 5; $i++) { if ($id['b36'][$i] !== $c[$i]) { $diff++; } }
            if ($diff <= 1) { $keys[] = $k; }
        }
        return [$keys ? 'fuzzy' : 'none', $keys];
    };
    $best_form = function ($k) use ($idents) {   // zápis s nejvíce zdroji (při shodě první abecedně)
        $forms = $idents[$k]['forms']; uksort($forms, 'strcmp');
        $best = ''; $n = -1;
        foreach ($forms as $f => $srcs) { if (count($srcs) > $n) { $best = $f; $n = count($srcs); } }
        return $best;
    };
    $codes = []; $too_many = false;
    preg_match_all($re, $text, $mm);
    foreach ($mm[1] as $c) {
        $u = strtoupper($c);
        if (isset($codes[$u])) { continue; }
        if (count($codes) >= 50) { $too_many = true; break; }
        list($type, $keys) = $match($c);
        $people = [];
        foreach ($keys as $k) { $people[] = ['best' => $best_form($k), 'forms' => $idents[$k]['forms']]; }
        $codes[$u] = ['type' => $type, 'people' => $people];
    }
    $translated = preg_replace_callback($re, function ($m) use ($codes) {
        $u = strtoupper($m[1]);
        if (!isset($codes[$u]) || !$codes[$u]['people']) { return $m[0] . ' (nenalezeno)'; }
        $names = array_map(function ($p) { return $p['best']; }, $codes[$u]['people']);
        return implode(' / ', $names) . ($codes[$u]['type'] === 'exact' ? '' : ' (≈ přibližná shoda)');
    }, $text);
    return ['codes' => $codes, 'translated' => $translated, 'note' => $too_many ? 'Přeloženo prvních 50 různých kódů.' : ''];
}

// „Není člověk, není problém“: podklad po dnech. Pro každý den s docházkou: první a poslední průchod dne (v minutách), zda se ten den kontrolovaly kabinky
// (existuje hlášení) a kdo měl ten den aspoň jeden průchod jakýmikoli dveřmi. U incidentů jsou jejich dny (od dne začátku okna po den nálezu).
// Vyhodnocení podle zaškrtnutých incidentů dělá prohlížeč. Vyřazené osoby a členové IT (nemají turniket) se nezahrnují.
function lh_absence_data($pdo, $incs, $incident_no, $employee_names, $blacklisted) {
    $out = ['days' => [], 'p' => [], 'pres' => [], 'inc' => []];
    $bl = [];
    foreach ($blacklisted as $b_n) { $bl[lh_name_norm($b_n)] = true; }
    $it = [];
    try { foreach ($pdo->query("SELECT person_name FROM it_staff")->fetchAll(PDO::FETCH_COLUMN) as $n_it) { $it[lh_name_norm($n_it)] = true; } } catch (PDOException $e) { /* tabulka ještě neexistuje */ }
    try {
        $days = $pdo->query("SELECT DATE(passed_at) AS d, MIN(HOUR(passed_at) * 60 + MINUTE(passed_at)) AS fm, MAX(HOUR(passed_at) * 60 + MINUTE(passed_at)) AS lm FROM turnstile_logs GROUP BY DATE(passed_at) ORDER BY d")->fetchAll();
    } catch (PDOException $e) { return $out; }
    if (!$days) { return $out; }
    $days = array_slice($days, -366);   // nejvýše poslední rok docházky
    $mon = [];
    try { foreach ($pdo->query("SELECT DISTINCT DATE(reported_at) FROM toilet_reports")->fetchAll(PDO::FETCH_COLUMN) as $md) { $mon[$md] = true; } } catch (PDOException $e) { /* bez hlášení */ }
    $idx = [];
    foreach ($days as $k => $r) {
        $idx[$r['d']] = $k;
        $out['days'][] = ['d' => $r['d'], 'f' => (int)$r['fm'], 'l' => (int)$r['lm'], 'm' => isset($mon[$r['d']]) ? 1 : 0];
        $out['pres'][] = [];
    }
    $pidx = [];
    $q = $pdo->prepare("SELECT DATE(passed_at) AS d, person_name FROM turnstile_logs WHERE passed_at >= ? GROUP BY DATE(passed_at), person_name");
    $q->execute([$days[0]['d'] . ' 00:00:00']);
    foreach ($q->fetchAll() as $r) {
        $key = lh_name_norm($r['person_name']);
        if (isset($bl[$key]) || isset($it[$key]) || !isset($idx[$r['d']])) { continue; }
        if (!isset($pidx[$key])) { $pidx[$key] = count($out['p']); $out['p'][] = (string)$r['person_name']; }
        $out['pres'][$idx[$r['d']]][] = $pidx[$key];
    }
    foreach ($employee_names as $en) {   // zaměstnanci bez jediného průchodu v celé docházce
        $key = lh_name_norm($en);
        if (isset($bl[$key]) || isset($it[$key]) || isset($pidx[$key])) { continue; }
        $pidx[$key] = count($out['p']); $out['p'][] = (string)$en;
    }
    foreach (array_values($incs) as $inc) {
        $e_d = substr($inc['window_end'], 0, 10);
        $s_d = !empty($inc['window_start']) ? substr($inc['window_start'], 0, 10) : $e_d;
        $ok = 1; $ds = [];
        $t = strtotime($s_d . ' 12:00:00'); $t_end = strtotime($e_d . ' 12:00:00');
        if ($t === false || $t_end === false || $t_end < $t || ($t_end - $t) > 31 * 86400) { $ok = 0; }
        else { for (; $t <= $t_end; $t += 86400) { $dd = date('Y-m-d', $t); if (isset($idx[$dd])) { $ds[] = $idx[$dd]; } else { $ok = 0; } } }   // den okna bez docházky: incident nelze vyhodnotit
        $out['inc'][] = ['id' => (int)$inc['id'], 'no' => (int)($incident_no[(int)$inc['id']] ?? 0), 'c' => !empty($inc['counted']) ? 1 : 0, 'ok' => $ok, 'days' => $ok ? $ds : [], 'e' => $ok ? ($idx[$e_d] ?? -1) : -1, 'em' => (int)date('H', strtotime($inc['window_end'])) * 60 + (int)date('i', strtotime($inc['window_end']))];
    }
    return $out;
}

// ==========================================
// MILNÍKY
// ==========================================
function lh_ms_kinds() {
    return ['left' => 'Osoba skončila', 'joined' => 'Osoba nastoupila', 'away' => 'Osoba je pryč', 'change' => 'Změna provozu', 'other' => 'Jiné'];
}
function lh_ms_is_person($kind) { return in_array($kind, ['left', 'joined', 'away'], true); }
// Text milníku pro zobrazení (bez data); v anonymizované náhledové verzi se osoba i text nahradí pseudonymy
function lh_ms_text($m, $pdo) {
    $anon = lh_pseudo_enabled($pdo);
    $name = (string)($m['person_name'] ?? '');
    if ($anon && $name !== '') { $name = lh_pseudo_name($name); }
    $title = (string)$m['title'];
    if ($anon && $title !== '') { $title = lh_pseudo_text($title); }
    $to = !empty($m['date_to']) ? date('d-m-Y', strtotime($m['date_to'])) : '';
    switch ($m['kind']) {
        case 'left': $t = $name . ' skončil(a) v práci (poslední den v práci)'; break;
        case 'joined': $t = $name . ' nastoupil(a) (první den v práci)'; break;
        case 'away': $t = $name . ' je pryč' . ($to !== '' ? ' do ' . $to : ''); break;
        default: $t = $title !== '' ? $title : 'Milník';
    }
    if (lh_ms_is_person($m['kind']) && $title !== '') { $t .= ': ' . $title; }
    return $t;
}
function lh_ms_note($m, $pdo) {
    $n = (string)($m['note'] ?? '');
    return ($n !== '' && lh_pseudo_enabled($pdo)) ? lh_pseudo_text($n) : $n;
}
// Den, od kterého milník dělí období (u „skončila“ je to den PO posledním dni v práci); slouží k umístění mezi incidenty
function lh_ms_key($m) { return $m['kind'] === 'left' ? date('Y-m-d', strtotime($m['date_from'] . ' +1 day')) : $m['date_from']; }
// Je osoba v daný den podle milníku mimo firmu?
function lh_ms_day_out($m, $d) {
    if ($m['kind'] === 'left') { return $d > $m['date_from']; }
    if ($m['kind'] === 'joined') { return $d < $m['date_from']; }
    if ($m['kind'] === 'away') { return $d >= $m['date_from'] && $d <= ((string)$m['date_to'] !== '' ? $m['date_to'] : $m['date_from']); }
    return false;
}
// Osoby, které byly podle milníků mimo firmu po CELÉ okno incidentu (každý den okna musí být „mimo“; okno bez známého začátku se nevyhodnotí).
// Vrací jméno => seznam milníků, které to způsobují.
function lh_ms_out_persons($milestones, $inc) {
    if (empty($inc['window_start']) || empty($inc['window_end'])) { return []; }
    $sd = date('Y-m-d', strtotime($inc['window_start']));
    $ed = date('Y-m-d', strtotime($inc['window_end']));
    if ($ed < $sd) { return []; }
    $days = [];
    for ($t = strtotime($sd . ' 12:00:00'), $te = strtotime($ed . ' 12:00:00'); $t <= $te; $t += 86400) {
        $days[] = date('Y-m-d', $t);
        if (count($days) > 31) { return []; }
    }
    $by = [];
    foreach ($milestones as $m) {
        if (!lh_ms_is_person($m['kind']) || (string)$m['person_name'] === '') { continue; }
        $k = lh_name_norm($m['person_name']);
        if (!isset($by[$k])) { $by[$k] = ['name' => $m['person_name'], 'ms' => []]; }
        $by[$k]['ms'][] = $m;
    }
    $out = [];
    foreach ($by as $p) {
        $why = []; $all = true;
        foreach ($days as $d) {
            $day_out = false;
            foreach ($p['ms'] as $m) { if (lh_ms_day_out($m, $d)) { $day_out = true; $why[(int)$m['id']] = $m; } }
            if (!$day_out) { $all = false; break; }
        }
        if ($all) { $out[$p['name']] = array_values($why); }
    }
    ksort($out);
    return $out;
}
// Porovnání před/po milníku: dny s kontrolou kabinek (existuje hlášení) a počítané incidenty rozdělené podle milníku.
// p = pravděpodobnost, že by tak velký rozdíl vznikl náhodou (jednostranný binomický test; při stejné míře incidentů by se incidenty dělily podle počtu dnů)
function lh_ms_compare($pdo, $milestones, $incs_counted) {
    $res = [];
    if (!$milestones) { return $res; }
    try { $mon = $pdo->query("SELECT DISTINCT DATE(reported_at) FROM toilet_reports")->fetchAll(PDO::FETCH_COLUMN); } catch (PDOException $e) { return $res; }
    $inc_days = [];
    foreach ($incs_counted as $inc) { $d = substr((string)$inc['window_end'], 0, 10); $inc_days[$d] = ($inc_days[$d] ?? 0) + 1; }
    foreach ($milestones as $m) {
        $da = $db = $na = $nb = 0;
        foreach ($mon as $d) { if (lh_ms_after($m, $d)) { $da++; } else { $db++; } }
        foreach ($inc_days as $d => $n) { if (lh_ms_after($m, $d)) { $na += $n; } else { $nb += $n; } }
        $row = ['da' => $da, 'db' => $db, 'na' => $na, 'nb' => $nb, 'p' => null, 'dir' => ''];
        $n = $na + $nb;
        if ($da > 0 && $db > 0 && $n > 0) {
            $pa = $da / ($da + $db);
            $ra = $na / $da; $rb = $nb / $db;
            $row['dir'] = $ra < $rb ? 'pokles' : ($ra > $rb ? 'nárůst' : 'beze změny');
            // P(X <= na) při poklesu, P(X >= na) při nárůstu, X ~ Bin(n, pa)
            $pmf = pow(1 - $pa, $n); $cdf = 0.0; $tail_ge = 0.0;
            for ($k = 0; $k <= $n; $k++) {
                if ($k <= $na) { $cdf += $pmf; }
                if ($k >= $na) { $tail_ge += $pmf; }
                if ($k < $n) { $pmf = $pmf * ($n - $k) / ($k + 1) * $pa / (1 - $pa); }
            }
            $row['p'] = $row['dir'] === 'pokles' ? min(1.0, $cdf) : ($row['dir'] === 'nárůst' ? min(1.0, $tail_ge) : 1.0);
        }
        $res[(int)$m['id']] = $row;
    }
    return $res;
}
// Patří den „za“ milník (po odchodu, od nástupu, v období pryč, od změny)?
function lh_ms_after($m, $d) {
    switch ($m['kind']) {
        case 'left': return $d > $m['date_from'];
        case 'away': return $d >= $m['date_from'] && $d <= ((string)$m['date_to'] !== '' ? $m['date_to'] : $m['date_from']);
        default: return $d >= $m['date_from'];
    }
}

// Rozpor v nevinnostech: osoba má u incidentu zadanou nevinnost (alibi „nebyl tam“), ale v době incidentu (okno až do nálezu + tolerance) měla průchod turniketem.
// Průchod mimo okno (např. příchod až po incidentu) rozpor není. Incident bez známého začátku okna se nevyhodnotí.
function lh_innocent_conflicts($pdo, $incidents, $incident_innocents, $incident_no, $tol_min, $turnstiles_list) {
    $out = [];
    $q = $pdo->prepare("SELECT person_name, turnstile, direction, passed_at FROM turnstile_logs WHERE passed_at BETWEEN ? AND ? ORDER BY passed_at ASC");
    foreach ($incidents as $inc) {
        $rid = (int)$inc['id'];
        if (empty($incident_innocents[$rid]) || empty($inc['window_start']) || empty($inc['window_end'])) { continue; }
        $a = $inc['window_start'];
        $b = date('Y-m-d H:i:s', strtotime($inc['window_end']) + (int)$tol_min * 60);
        $q->execute([$a, $b]);
        $by = [];
        foreach ($q->fetchAll() as $r) { $by[lh_name_norm($r['person_name'])][] = $r; }
        foreach ($incident_innocents[$rid] as $row) {
            $k = lh_name_norm($row['person_name']);
            if (!isset($by[$k])) { continue; }
            $out[] = [
                'id' => (int)$row['id'], 'report_id' => $rid, 'no' => (int)($incident_no[$rid] ?? 0), 'person' => (string)$row['person_name'], 'reason' => (string)$row['reason'],
                'a' => $a, 'e' => $inc['window_end'], 'b' => $b, 'n' => count($by[$k]),
                'passes' => array_map(function ($r) use ($turnstiles_list) { return ['t' => $r['passed_at'], 'ts' => $turnstiles_list[$r['turnstile']] ?? $r['turnstile'], 'dir' => strtoupper((string)$r['direction']) === 'IN' ? 'IN' : 'OUT']; }, array_slice($by[$k], 0, 6)),
            ];
        }
    }
    usort($out, function ($x, $y) { return ($y['no'] <=> $x['no']) ?: strcmp($x['person'], $y['person']); });
    return $out;
}

// Poslední nahrané průchody (pro admina v panelu importu): celkově a po turniketech, aby bylo vidět, čím docházka končí
function lh_last_passes($pdo, $turnstiles_list) {
    $out = ['total' => 0, 'first' => null, 'last' => null, 'last_ts' => [], 'by' => []];
    try {
        $rows = $pdo->query("SELECT turnstile, MIN(passed_at) AS first_pass, MAX(passed_at) AS last_pass, COUNT(*) AS n FROM turnstile_logs GROUP BY turnstile")->fetchAll();
    } catch (PDOException $e) { return $out; }
    $by = [];
    foreach ($rows as $r) { $by[$r['turnstile']] = $r; $out['total'] += (int)$r['n']; }
    foreach ($turnstiles_list as $key => $name) {   // pořadí podle seznamu turniketů, i turniket bez průchodů se ukáže
        $r = $by[$key] ?? null;
        $out['by'][$key] = ['name' => $name, 'n' => $r ? (int)$r['n'] : 0, 'first' => $r ? $r['first_pass'] : null, 'last' => $r ? $r['last_pass'] : null];
        if ($r && ($out['last'] === null || $r['last_pass'] > $out['last'])) { $out['last'] = $r['last_pass']; }
        if ($r && ($out['first'] === null || $r['first_pass'] < $out['first'])) { $out['first'] = $r['first_pass']; }
    }
    foreach ($out['by'] as $key => $t) { if ($t['last'] !== null && $t['last'] === $out['last']) { $out['last_ts'][] = $t['name']; } }   // při shodě času jich může být víc
    return $out;
}

// Příchod po incidentu: pro každého člověka a incident (1) první průchod dne nálezu jako rozdíl v minutách od nálezu a (2) zda měl průchod v okně
// incidentu včetně tolerance (stejné pravidlo jako body v žebříčku). Klasifikaci podle posuvníku dělá až prohlížeč. Vyřazené osoby (blacklisted_suspects) se
// nezahrnují, členové IT jsou zvlášť (nemají turniket). Zaměstnanci bez průchodu mají null. Incident, u kterého chybí docházka, má cov = 0 (nelze vyhodnotit).
function lh_arrival_data($pdo, $incs, $incident_no, $tol, $employee_names, $blacklisted) {
    $out = ['tol' => (int)$tol, 'inc' => [], 'p' => []];
    if (!$incs) { return $out; }
    $bl = [];
    foreach ($blacklisted as $b_n) { $bl[lh_name_norm($b_n)] = true; }
    $it = [];
    try { foreach ($pdo->query("SELECT person_name FROM it_staff")->fetchAll(PDO::FETCH_COLUMN) as $n_it) { $it[lh_name_norm($n_it)] = (string)$n_it; } } catch (PDOException $e) { /* tabulka ještě neexistuje */ }
    $q_rows = $pdo->prepare("SELECT person_name, MIN(CASE WHEN DATE(passed_at) = ? THEN passed_at END) AS first_d, MAX(passed_at BETWEEN ? AND ?) AS inwin FROM turnstile_logs WHERE passed_at BETWEEN ? AND ? GROUP BY person_name");
    $q_day = $pdo->prepare("SELECT COUNT(*) AS n, MAX(passed_at) AS mx FROM turnstile_logs WHERE passed_at BETWEEN ? AND ?");
    $per = [];   // normalizované jméno => ['n' => zápis z docházky, 'v' => [index incidentu => [rozdíl v minutách nebo null, 0/1]]]
    foreach (array_values($incs) as $k => $inc) {
        $e_ts = strtotime($inc['window_end']);
        $cov = 0;
        if (!empty($inc['window_start'])) {
            $s_ts = strtotime($inc['window_start']);
            $d = date('Y-m-d', $e_ts); $ds = date('Y-m-d', $s_ts);
            // docházka musí za den nálezu pokrývat aspoň do okamžiku nálezu a (u okna přes půlnoc) musí existovat i za den začátku okna
            $q_day->execute([$d . ' 00:00:00', $d . ' 23:59:59']); $r_d = $q_day->fetch();
            $ok = ((int)$r_d['n'] > 0 && $r_d['mx'] !== null && strtotime($r_d['mx']) >= $e_ts);
            if ($ok && $ds !== $d) { $q_day->execute([$ds . ' 00:00:00', $ds . ' 23:59:59']); $ok = ((int)$q_day->fetch()['n'] > 0); }
            $cov = $ok ? 1 : 0;
            if ($cov) {
                $q_rows->execute([$d, $inc['window_start'], date('Y-m-d H:i:s', $e_ts + (int)$tol * 60), $ds . ' 00:00:00', $d . ' 23:59:59']);
                foreach ($q_rows->fetchAll() as $r) {
                    $key = lh_name_norm($r['person_name']);
                    if (isset($bl[$key]) || isset($it[$key])) { continue; }
                    if (!isset($per[$key])) { $per[$key] = ['n' => (string)$r['person_name'], 'v' => []]; }
                    $per[$key]['v'][$k] = [$r['first_d'] === null ? null : (int)round((strtotime($r['first_d']) - $e_ts) / 60), (int)$r['inwin']];
                }
            }
        }
        $out['inc'][] = ['id' => (int)$inc['id'], 'no' => (int)($incident_no[(int)$inc['id']] ?? 0), 'c' => !empty($inc['counted']) ? 1 : 0, 'cov' => $cov];
    }
    // základ osob nezávisí na tom, které incidenty se zrovna načítají: všechna jména z docházky (i sdílené karty apod., vyřadit je může admin přes blacklist) a zaměstnanci
    try {
        foreach ($pdo->query("SELECT DISTINCT person_name FROM turnstile_logs")->fetchAll(PDO::FETCH_COLUMN) as $ln) {
            $key = lh_name_norm($ln);
            if (isset($bl[$key]) || isset($it[$key]) || isset($per[$key])) { continue; }
            $per[$key] = ['n' => (string)$ln, 'v' => []];
        }
    } catch (PDOException $e) { /* bez docházky */ }
    foreach ($employee_names as $en) {   // zaměstnanci bez průchodu u všech incidentů (nebo bez docházky vůbec)
        $key = lh_name_norm($en);
        if (isset($bl[$key]) || isset($it[$key]) || isset($per[$key])) { continue; }
        $per[$key] = ['n' => (string)$en, 'v' => []];
    }
    $n_inc = count($out['inc']);
    foreach ($per as $row) {
        $v = [];
        for ($k = 0; $k < $n_inc; $k++) { $v[] = $row['v'][$k] ?? null; }
        $out['p'][] = ['n' => $row['n'], 'it' => 0, 'v' => $v];
    }
    foreach ($it as $key => $name) { if (!isset($bl[$key])) { $out['p'][] = ['n' => $name, 'it' => 1, 'v' => array_fill(0, $n_inc, null)]; } }
    return $out;
}

// Anonymizace osob (pseudonymy) platí jen v náhledové verzi a jen dokud ji admin nevypne (nastavení pseudonymize_readonly = 0; výchozí je zapnuto)

// Pseudonymy osob v náhledové (jen čtecí) verzi: ze jména (bez ohledu na velikost písmen a diakritiku) vzniká stálý kód „Osoba XXXXX“
// (HMAC s tajným klíčem), takže se osoby dají v aplikaci rozlišovat, ale nedají se poznat ani zpětně uhodnout.
// Volný text (poznámky, důvody): známá jména a označení hlásících (z databáze) se v něm nahradí pseudonymy
// Hlášení (historie): hlásící a poznámka
function lh_pseudo_reports(&$rows) {
    foreach ($rows as $k => $r) {
        $rows[$k]['reporter'] = lh_pseudo_name($r['reporter']);
        if (!empty($r['note'])) { $rows[$k]['note'] = lh_pseudo_text($r['note']); }
    }
}

// Porovnání jmen podle české abecedy (Č za C, Š za S, Ř za R, Ž za Z, CH za H; ostatní diakritika se při řazení ignoruje)
function lh_cz_cmp($a, $b) {
    static $coll = null;
    if ($coll === null) { $coll = class_exists('Collator') ? new Collator('cs_CZ') : false; }
    if ($coll) { return $coll->compare((string)$a, (string)$b); }
    $key = function ($x) {
        $x = mb_strtolower(trim((string)$x));
        $x = strtr($x, ['č' => 'c~', 'ř' => 'r~', 'š' => 's~', 'ž' => 'z~', 'ch' => 'h~',
            'á' => 'a', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y']);
        return $x;
    };
    return strcmp($key($a), $key($b));
}

// Váha umístění pro incident v dané kabince (1 = přesně tato kabinka)
function wit_loc_weight($location, $cubicle, $p_cubicle) {
    switch ($location) {
        case 'invalide': return $cubicle === 'invalide' ? 1.0 : 0.0;
        case 'leva':     return $cubicle === 'leva' ? 1.0 : 0.0;
        case 'prava':    return $cubicle === 'prava' ? 1.0 : 0.0;
        case 'toalety_kabinka': return ($cubicle === 'leva' || $cubicle === 'prava') ? 0.5 : 0.0;
        case 'toalety_nevim':   return ($cubicle === 'leva' || $cubicle === 'prava') ? $p_cubicle * 0.5 : 0.0;
        default: return 0.0;   // pisoár
    }
}

// Seznam zařízení pro admina: zařízení z admin relací (device_seen), z hlášení a z pozorování, s názvy
function lh_device_list($pdo, $labels) {
    $d = [];
    $touch = function ($id) use (&$d) {
        if ($id !== null && $id !== '' && !isset($d[$id])) { $d[$id] = ['label' => '', 'ip' => '', 'last' => '', 'reports' => 0, 'sightings' => 0]; }
    };
    $later = function ($id, $t) use (&$d) { if ($t !== null && (string)$t > $d[$id]['last']) { $d[$id]['last'] = (string)$t; } };
    try {
        foreach ($pdo->query("SELECT device_id, last_seen, last_ip FROM device_seen")->fetchAll() as $r) {
            $touch($r['device_id']); $later($r['device_id'], $r['last_seen']);
            if ((string)$r['last_ip'] !== '') { $d[$r['device_id']]['ip'] = (string)$r['last_ip']; }
        }
    } catch (PDOException $e) {}
    try {
        foreach ($pdo->query("SELECT device_id, COUNT(*) n, MAX(created_at) t FROM toilet_reports WHERE device_id IS NOT NULL GROUP BY device_id")->fetchAll() as $r) {
            $touch($r['device_id']); $d[$r['device_id']]['reports'] = (int)$r['n']; $later($r['device_id'], $r['t']);
        }
        foreach ($pdo->query("SELECT t.device_id, t.client_ip FROM toilet_reports t JOIN (SELECT MAX(id) mid FROM toilet_reports WHERE device_id IS NOT NULL GROUP BY device_id) m ON t.id = m.mid")->fetchAll() as $r) {
            if ($d[$r['device_id']]['ip'] === '' && (string)$r['client_ip'] !== '') { $d[$r['device_id']]['ip'] = (string)$r['client_ip']; }
        }
    } catch (PDOException $e) {}
    try {
        foreach ($pdo->query("SELECT device_id, COUNT(*) n, MAX(created_at) t FROM sightings WHERE device_id IS NOT NULL GROUP BY device_id")->fetchAll() as $r) {
            $touch($r['device_id']); $d[$r['device_id']]['sightings'] = (int)$r['n']; $later($r['device_id'], $r['t']);
        }
    } catch (PDOException $e) {}
    foreach ($labels as $id => $lab) { $touch($id); $d[$id]['label'] = (string)$lab; }
    uasort($d, function ($a, $b) { return strcmp($b['last'], $a['last']); });
    return $d;
}

// Nepovinná poznámka k hlášení: text do 300 znaků bez řídicích znaků
function lh_note($s) {
    $s = str_replace(["\r\n", "\r"], "\n", (string)$s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
    $s = preg_replace('/\n{3,}/', "\n\n", trim($s));
    return mb_substr($s, 0, 300);
}

// Fotodokumentace: soubory incidentu ve složce <photo_dir>/<číslo incidentu>. Jen běžné soubory (bez skrytých, podsložek a symbolických odkazů).
function lh_photo_files($dir, $no) {
    $base = realpath($dir);
    $path = $base ? realpath($base . '/' . (int)$no) : false;
    if (!$base || !$path || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_dir($path)) { return []; }
    $out = [];
    foreach (@scandir($path) ?: [] as $f) {
        $fp = $path . '/' . $f;
        if ($f === '' || $f[0] === '.' || is_link($fp) || !is_file($fp)) { continue; }
        $out[] = ['name' => $f, 'size' => (int)@filesize($fp)];
    }
    usort($out, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
    return $out;
}

// Štítky incidentů: barva (jen #rrggbb), čitelná barva textu a vykreslení odznaku
function lh_label_color($c) { return preg_match('/^#[0-9a-fA-F]{6}$/', (string)$c) ? strtolower($c) : '#6b7280'; }
function lh_label_badge($lb) {
    $c = lh_label_color($lb['color']);
    $lum = 0.299 * hexdec(substr($c, 1, 2)) + 0.587 * hexdec(substr($c, 3, 2)) + 0.114 * hexdec(substr($c, 5, 2));
    return '<span class="badge lh-label" style="background:' . $c . ';color:' . ($lum > 150 ? '#1f2937' : '#ffffff') . '">' . htmlspecialchars($lb['name']) . '</span>';
}
function lh_label_badges($ids, $labels) {
    $out = '';
    foreach ($ids as $lid) { if (isset($labels[$lid])) { $out .= lh_label_badge($labels[$lid]) . ' '; } }
    return $out;
}

// Zobrazení data: RRRR-MM-DD se v textu přepíše na DD-MM-RRRR (čas zůstává). Používá se jen pro zobrazení, ne pro vstupy ani porovnání.
function lh_d($s) {
    return preg_replace('/\b(\d{4})-(\d{2})-(\d{2})\b/', '$3-$2-$1', (string)$s);
}

// Oznámení o novém incidentu pro příjemce týdenního souhrnu (bez jmen osob a bez poznámek)
function lh_incident_notice($inc, $cubicles_list, $app_url) {
    $cub = $cubicles_list[$inc['cubicle']] ?? $inc['cubicle'];
    $lines = ['Nový útok Lorda Hoven', '', 'Kabinka: ' . $cub, 'Nález: ' . date('j. n. Y H:i', strtotime($inc['window_end']))];
    if (!empty($inc['window_start'])) {
        $lines[] = 'Časové okno: od ' . date('j. n. Y H:i', strtotime($inc['window_start'])) . ' (' . format_minutes((strtotime($inc['window_end']) - strtotime($inc['window_start'])) / 60) . ')';
    }
    $lines[] = '';
    $lines[] = 'Lord Hoven znovu zasáhl.';
    $lines[] = '';
    $lines[] = 'Viděl jsi něco? Viděl jsi někoho? Byl jsi na toaletě?';
    $lines[] = 'Podej hlášení: ' . $app_url;
    return ['[Detektor] Nový incident: ' . $cub, implode("\n", $lines)];
}

// Údaje jednoho pozorování pro zobrazení; u pozorování bez bodu (src 32) včetně důvodu
function wit_item_view($it_w, $src, $group, $threshold) {
    $note = '';
    if ($src === 32) {
        if ($it_w['lw'] <= 0) {
            $note = ($it_w['row']['location'] === 'pisoar') ? 'pisoár, bez vlivu na žebříček' : 'jiná kabinka než u tohoto incidentu, bez bodu';
        } else {
            $w_show = $group ? $group['prob'] : ($it_w['lw'] * $it_w['dw']);
            $note = 'pod prahem: váha ' . number_format($w_show, 2, ',', '') . ' < ' . number_format($threshold, 2, ',', '') . ', bez bodu';
        }
    }
    return [
        'seen_at' => $it_w['row']['seen_at'],
        'location' => $it_w['row']['location'],
        'direction' => $it_w['row']['direction'],
        'reporter' => $it_w['row']['reporter'],
        'src' => $src,
        'note' => $note,
    ];
}

// Jméno tak, jak je zapsané v docházce (posledních 30 dní) nebo mezi členy IT; null = neznámé jméno
// ==========================================
// ALIASY JMEN (aliasy.sql): varianty jména téhož člověka se slučují na hlavní jméno
// ==========================================
function lh_alias_map($pdo, $reload = false) {
    static $m = null;
    if ($m === null || $reload) {
        $m = [];
        try { foreach ($pdo->query("SELECT alias, canonical FROM name_aliases")->fetchAll() as $r) { $m[lh_name_norm($r['alias'])] = $r['canonical']; } } catch (PDOException $e) { /* tabulka ještě neexistuje */ }
    }
    return $m;
}
// Pokud je jméno alias, vrátí hlavní jméno; jinak jméno beze změny
function lh_alias_apply($pdo, $name) {
    $m = lh_alias_map($pdo);
    $k = lh_name_norm((string)$name);
    return isset($m[$k]) ? $m[$k] : $name;
}
// Tabulky se jmény osob, která se při sloučení přepisují (jména hlásících se nemění). typ: dup = unikátní klíč (duplicity se odstraní), plain = jen přepis, it = člen IT
function lh_alias_tables() {
    return [
        ['turnstile_logs', 'person_name', 'dup', "c.passed_at = a.passed_at AND c.direction = a.direction AND c.turnstile = a.turnstile"],
        ['sightings', 'person_name', 'plain', ''],
        ['incident_innocents', 'person_name', 'dup', "c.report_id = a.report_id"],
        ['employees', 'name', 'dup', "1 = 1"],
        ['blacklisted_suspects', 'person_name', 'dup', "1 = 1"],
        ['trusted_persons', 'person_name', 'dup', "1 = 1"],
        ['milestones', 'person_name', 'plain', ''],
        ['poznatky', 'person_name', 'plain', ''],
        ['it_staff', 'person_name', 'it', ''],
    ];
}
// Kontrola importu docházky před uložením: (1) záznam (stejný člověk, směr a čas), který už existuje u JINÉHO turniketu, (2) záznam z budoucnosti (čas po aktuálním čase serveru).
// Vrací úplné seznamy; bad = indexy řádků s problémem (pro volbu „importovat bez problematických“).
function lh_import_check($pdo, $rows, $turnstile, $turnstiles_list) {
    $res = ['cross' => [], 'future' => [], 'bad' => [], 'now' => date('d-m-Y H:i:s')];
    $now = time();
    $days = [];
    foreach ($rows as $i => $r) {
        $t = strtotime($r[3]);
        if ($t === false) { continue; }
        $days[date('Y-m-d', $t)] = true;
        if ($t > $now) { $res['future'][] = ['name' => $r[0], 'dir' => $r[1], 'at' => date('d-m-Y H:i', $t), 'ts' => $t, 'ahead' => $t - $now]; $res['bad'][$i] = true; }
    }
    $idx = [];
    $st = $pdo->prepare("SELECT person_name, direction, turnstile, passed_at FROM turnstile_logs WHERE passed_at >= ? AND passed_at < ? AND turnstile <> ?");
    foreach (array_keys($days) as $day) {
        $st->execute([$day . ' 00:00:00', date('Y-m-d', strtotime($day . ' +1 day')) . ' 00:00:00', $turnstile]);
        foreach ($st->fetchAll() as $ex) { $idx[lh_name_norm($ex['person_name']) . '|' . strtoupper((string)$ex['direction']) . '|' . $ex['passed_at']][$ex['turnstile']] = true; }
    }
    foreach ($rows as $i => $r) {
        $t = strtotime($r[3]);
        if ($t === false) { continue; }
        $k = lh_name_norm($r[0]) . '|' . $r[1] . '|' . date('Y-m-d H:i:s', $t);
        if (isset($idx[$k])) {
            $res['cross'][] = ['name' => $r[0], 'dir' => $r[1], 'at' => date('d-m-Y H:i', $t), 'ts' => $t, 'where' => implode(', ', array_map(function ($x) use ($turnstiles_list) { return $turnstiles_list[$x] ?? $x; }, array_keys($idx[$k])))];
            $res['bad'][$i] = true;
        }
    }
    $cmp = function ($a, $b) { return ($a['ts'] <=> $b['ts']) ?: strcmp($a['name'], $b['name']); };
    usort($res['cross'], $cmp); usort($res['future'], $cmp);
    return $res;
}
function lh_alias_table_missing($e) { return in_array((string)$e->getCode(), ['42S02'], true); }
// Kolik řádků by se přepsalo / kolik duplicit by se odstranilo (náhled, bez změn)
function lh_alias_preview_one($pdo, $canon, $alias) {
    $out = ['rows' => [], 'dups' => [], 'it_conflict' => false];
    foreach (lh_alias_tables() as $t) {
        list($tbl, $col, $kind, $join) = $t;
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM `$tbl` WHERE `$col` = ? AND BINARY `$col` <> BINARY ?");
            $st->execute([$alias, $canon]);
            $n = (int)$st->fetchColumn();
            if ($n > 0) { $out['rows'][$tbl] = $n; }
            if ($kind === 'dup' && $n > 0) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM `$tbl` a WHERE a.`$col` = ? AND BINARY a.`$col` <> BINARY ? AND EXISTS (SELECT 1 FROM `$tbl` c WHERE BINARY c.`$col` = BINARY ? AND $join)");
                $st->execute([$alias, $canon, $canon]);
                $d = (int)$st->fetchColumn();
                if ($d > 0) { $out['dups'][$tbl] = $d; }
            }
            if ($kind === 'it' && $n > 0) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM it_staff WHERE BINARY person_name = BINARY ?");
                $st->execute([$canon]);
                if ((int)$st->fetchColumn() > 0) { $out['it_conflict'] = true; }
            }
        } catch (PDOException $e) { if (!lh_alias_table_missing($e)) { throw $e; } }
    }
    return $out;
}
// Přepíše alias na hlavní jméno ve všech tabulkách (volat v transakci). Vrací počty; u dvou členů IT vyhodí výjimku.
function lh_alias_merge_one($pdo, $canon, $alias) {
    $pv = lh_alias_preview_one($pdo, $canon, $alias);
    if ($pv['it_conflict']) { throw new RuntimeException('„' . $alias . '“ i „' . $canon . '“ jsou členové IT, slouč je ručně v panelu IT (jinak by se ztratily dovolené).'); }
    $moved = []; $dups = 0;
    foreach (lh_alias_tables() as $t) {
        list($tbl, $col, $kind) = $t;
        try {
            if ($kind === 'dup') {
                $st = $pdo->prepare("UPDATE IGNORE `$tbl` SET `$col` = ? WHERE `$col` = ? AND BINARY `$col` <> BINARY ?");
                $st->execute([$canon, $alias, $canon]);
                $n = $st->rowCount();
                $st = $pdo->prepare("DELETE FROM `$tbl` WHERE `$col` = ? AND BINARY `$col` <> BINARY ?");   // zbylé řádky = duplicity již existujícího záznamu hlavního jména
                $st->execute([$alias, $canon]);
                $d = $st->rowCount(); $dups += $d;
                if ($n > 0) { $moved[$tbl] = $n; }
            } else {
                $st = $pdo->prepare("UPDATE `$tbl` SET `$col` = ? WHERE `$col` = ? AND BINARY `$col` <> BINARY ?");
                $st->execute([$canon, $alias, $canon]);
                if ($st->rowCount() > 0) { $moved[$tbl] = $st->rowCount(); }
            }
        } catch (PDOException $e) { if (!lh_alias_table_missing($e)) { throw $e; } }
    }
    return ['moved' => $moved, 'dups' => $dups];
}

function wit_lookup_canonical($pdo, $name) {
    $mapped = lh_alias_apply($pdo, $name);   // alias → hlavní jméno
    if ($mapped !== $name) { return $mapped; }
    $st = $pdo->prepare("SELECT person_name FROM turnstile_logs WHERE passed_at >= ? AND person_name = ? LIMIT 1");
    $st->execute([date('Y-m-d H:i:s', time() - 30 * 86400), $name]);
    $found = $st->fetchColumn();
    if ($found !== false && $found !== null) { return $found; }
    try {
        $st = $pdo->prepare("SELECT person_name FROM it_staff WHERE person_name = ? LIMIT 1");
        $st->execute([$name]);
        $found = $st->fetchColumn();
        if ($found !== false && $found !== null) { return $found; }
    } catch (PDOException $e) {
    }
    return null;
}

// AJAX jen pro admina: záznam akcí, náhled sloučení aliasů, návrhy možných duplicit jmen
if (in_array($_GET['ajax'] ?? '', ['audit', 'alias_preview', 'alias_suggest'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$is_admin) { http_response_code(403); echo '{"error":"forbidden"}'; exit; }
    $ax = $_GET['ajax'];
    try {
        if ($ax === 'audit') {
            $q_a = trim((string)($_GET['q'] ?? '')); $grp_a = trim((string)($_GET['group'] ?? '')); $usr_a = (string)($_GET['user'] ?? '');
            $before_a = (int)($_GET['before'] ?? 0); $limit_a = max(1, min(200, (int)($_GET['limit'] ?? 50)));
            $where = []; $args = [];
            if ($grp_a !== '') { $where[] = 'grp = ?'; $args[] = $grp_a; }
            if ($usr_a !== '') { $where[] = 'user_name = ?'; $args[] = $usr_a === '(heslo)' ? '' : $usr_a; }
            if ($before_a > 0) { $where[] = 'id < ?'; $args[] = $before_a; }
            if ($q_a !== '') { $where[] = "CONCAT_WS(' ', label, user_name, action_key, result, details) LIKE ?"; $args[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q_a) . '%'; }
            $st = $pdo->prepare("SELECT id, created_at, user_name, ip, device, action_key, label, grp, result, details FROM audit_log" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY id DESC LIMIT " . ($limit_a + 1));
            $st->execute($args);
            $rows_a = $st->fetchAll();
            $more_a = count($rows_a) > $limit_a;
            $rows_a = array_slice($rows_a, 0, $limit_a);
            $out_a = ['rows' => array_map(function ($r) { return ['id' => (int)$r['id'], 't' => date('d-m-Y H:i:s', strtotime($r['created_at'])), 'user' => $r['user_name'], 'ip' => (string)$r['ip'], 'dev' => (string)$r['device'], 'act' => $r['action_key'], 'label' => $r['label'], 'grp' => $r['grp'], 'result' => (string)$r['result'], 'det' => (string)$r['details']]; }, $rows_a), 'more' => $more_a];
            if ($before_a === 0) {
                $out_a['groups'] = $pdo->query("SELECT DISTINCT grp FROM audit_log ORDER BY grp")->fetchAll(PDO::FETCH_COLUMN);
                $out_a['users'] = $pdo->query("SELECT DISTINCT user_name FROM audit_log ORDER BY user_name")->fetchAll(PDO::FETCH_COLUMN);
            }
            echo json_encode($out_a, JSON_UNESCAPED_UNICODE);
        } elseif ($ax === 'alias_preview') {
            $canon_p = trim(preg_replace('/\s+/u', ' ', (string)($_GET['canonical'] ?? '')));
            $list_p = [];
            foreach (preg_split('/\r\n|\r|\n/', (string)($_GET['aliases'] ?? '')) as $ln_p) { $a_p = trim(preg_replace('/\s+/u', ' ', $ln_p)); if ($a_p !== '') { $list_p[$a_p] = true; } }
            $list_p = array_slice(array_keys($list_p), 0, 20);
            $res_canon = lh_alias_apply($pdo, $canon_p);
            $items_p = [];
            foreach ($list_p as $a_p) {
                $pv = lh_alias_preview_one($pdo, $res_canon, $a_p);
                $items_p[] = ['alias' => $a_p, 'same' => strcmp($a_p, $res_canon) === 0, 'rows' => $pv['rows'], 'dups' => $pv['dups'], 'it_conflict' => $pv['it_conflict'], 'total' => array_sum($pv['rows'])];
            }
            echo json_encode(['canonical' => $res_canon, 'resolved' => $res_canon !== $canon_p, 'items' => $items_p], JSON_UNESCAPED_UNICODE);
        } else {
            // možné duplicity: stejné jméno bez diakritiky a velikosti písmen, stejná slova v jiném pořadí, překlep (1 znak)
            $names_s = [];
            foreach ([['turnstile_logs', 'person_name'], ['employees', 'name'], ['incident_innocents', 'person_name'], ['it_staff', 'person_name'], ['blacklisted_suspects', 'person_name'], ['sightings', 'person_name'], ['milestones', 'person_name'], ['poznatky', 'person_name']] as $tc) {
                try { foreach ($pdo->query("SELECT `{$tc[1]}`, COUNT(*) FROM `{$tc[0]}` WHERE `{$tc[1]}` IS NOT NULL AND `{$tc[1]}` <> '' GROUP BY `{$tc[1]}`")->fetchAll(PDO::FETCH_NUM) as $nr) { $names_s[$nr[0]] = ($names_s[$nr[0]] ?? 0) + ($tc[0] === 'turnstile_logs' ? (int)$nr[1] : 0); } } catch (PDOException $e) { /* tabulka ještě neexistuje */ }
            }
            $map_s = lh_alias_map($pdo);
            $keys_s = [];
            foreach ($names_s as $n_s => $c_s) { if (!isset($map_s[lh_name_norm($n_s)]) || strcmp($map_s[lh_name_norm($n_s)], $n_s) === 0) { $keys_s[$n_s] = lh_name_norm($n_s); } }
            $nm_s = array_keys($keys_s);
            $pairs_s = [];
            for ($i_s = 0; $i_s < count($nm_s); $i_s++) {
                for ($j_s = $i_s + 1; $j_s < count($nm_s); $j_s++) {
                    $a_s = $nm_s[$i_s]; $b_s = $nm_s[$j_s]; $ka = $keys_s[$a_s]; $kb = $keys_s[$b_s]; $why = '';
                    if ($ka === $kb) { $why = 'stejné jméno, jiná velikost písmen nebo diakritika'; }
                    else {
                        $ta = explode(' ', $ka); $tb = explode(' ', $kb); sort($ta); sort($tb);
                        if (count($ta) > 1 && $ta === $tb) { $why = 'stejná slova v jiném pořadí'; }
                        elseif (min(strlen($ka), strlen($kb)) >= 7 && abs(strlen($ka) - strlen($kb)) <= 1 && levenshtein($ka, $kb) === 1) { $why = 'možný překlep (liší se o 1 znak)'; }
                    }
                    if ($why !== '') { $pairs_s[] = ['a' => $a_s, 'b' => $b_s, 'why' => $why, 'na' => $names_s[$a_s], 'nb' => $names_s[$b_s]]; }
                }
            }
            echo json_encode(['pairs' => array_slice($pairs_s, 0, 40), 'total' => count($pairs_s), 'names' => count($nm_s)], JSON_UNESCAPED_UNICODE);
        }
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Tabulka chybí nebo dotaz selhal.']);
    }
    exit;
}

// AJAX: našeptávač jmen (jen shody s dotazem, ne celý seznam)
if (($_GET['ajax'] ?? '') === 'names') {
    if (lh_pseudo_enabled($pdo)) { header('Content-Type: application/json; charset=utf-8'); echo '[]'; exit; }   // anonymizovaná náhledová verze jména nenabízí
    $q = trim((string)($_GET['q'] ?? ''));
    $names = [];
    if (mb_strlen($q) >= 2) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $st = $pdo->prepare("SELECT DISTINCT person_name FROM turnstile_logs WHERE passed_at >= ? AND person_name LIKE ? ORDER BY person_name ASC LIMIT 8");
        $st->execute([date('Y-m-d H:i:s', time() - 30 * 86400), $like]);
        $names = $st->fetchAll(PDO::FETCH_COLUMN);
        try {
            $st = $pdo->prepare("SELECT person_name FROM it_staff WHERE person_name LIKE ? ORDER BY person_name ASC LIMIT 8");
            $st->execute([$like]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) { if (!in_array($n, $names, true)) { $names[] = $n; } }
        } catch (PDOException $e) {
        }
        $names = array_slice($names, 0, 8);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($names);
    exit;
}

// ==========================================
// 3. VÝPOČET INCIDENTŮ (ČASOVÁ OKNA)
// ==========================================
$incidents = [];

$stmt_inc = $pdo->prepare("SELECT * FROM toilet_reports WHERE cubicle = ? ORDER BY reported_at ASC, id ASC");

foreach (array_keys($cubicles_list) as $c) {
    $stmt_inc->execute([$c]);
    $chronological = $stmt_inc->fetchAll();

    $last_ok = null;
    $prev_status = null;
    foreach ($chronological as $row) {
        if ($row['status'] === 'ok') {
            $last_ok = $row;
        } elseif ($row['status'] === 'zesrana') {
            if ($prev_status === 'zesrana') {
                // Další hlášení "zesraná" bez čisto mezi nimi je jen potvrzení téhož incidentu, nový nevzniká
            } elseif ($last_ok !== null) {
                $incidents[] = [
                    'id' => $row['id'],
                    'cubicle' => $c,
                    'window_start' => $last_ok['reported_at'],
                    'last_ok_by' => $last_ok['reporter'],
                    'window_end' => $row['reported_at'],
                    'dirty_reported_by' => $row['reporter'],
                    'ok_note' => $last_ok['note'] ?? null,
                    'dirty_note' => $row['note'] ?? null
                ];
                $last_ok = null;
            } else {
                $incidents[] = [
                    'id' => $row['id'],
                    'cubicle' => $c,
                    'window_start' => null, // Chybí předchozí čisto
                    'last_ok_by' => 'N/A',
                    'window_end' => $row['reported_at'],
                    'dirty_reported_by' => $row['reporter'],
                    'ok_note' => null,
                    'dirty_note' => $row['note'] ?? null
                ];
            }
        }
        $prev_status = $row['status'];
    }
}

usort($incidents, function($a, $b) {
    return strcmp($b['window_end'], $a['window_end']);
});

// Číslo incidentu je stálé: přidělí se jednou, při prvním zobrazení incidentu, a pak se nemění (tabulka incident_numbers, cisla_incidentu.sql).
// Nové číslo = nejvyšší dosavadní + 1 (číslo smazaného incidentu se znovu nepoužije). Při prvním spuštění se stávající incidenty očíslují
// chronologicky (nejstarší = #1). Bez tabulky se čísla dopočítají podle času nálezu (nejsou stálá).
$incident_no = [];
$incno_ok = true;
$inc_by_age = $incidents;
usort($inc_by_age, function($a, $b) { return strcmp($a['window_end'], $b['window_end']) ?: ((int)$a['id'] <=> (int)$b['id']); });
try {
    $stored = $pdo->query("SELECT report_id, inc_no FROM incident_numbers")->fetchAll(PDO::FETCH_KEY_PAIR);
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $next = $stored ? (int)max($stored) + 1 : 1;
        $new = [];
        foreach ($inc_by_age as $inc_age) {
            if (!isset($stored[(int)$inc_age['id']])) { $new[(int)$inc_age['id']] = $next++; }
        }
        if (!$new) { break; }
        if ($readonly) { $stored = $stored + $new; break; }   // náhledová adresa nezapisuje, číslo je jen předběžné a shodné s tím, které se přidělí v plné verzi
        try {
            $ins_no = $pdo->prepare("INSERT INTO incident_numbers (report_id, inc_no) VALUES (?, ?)");
            foreach ($new as $iid_new => $no_new) { $ins_no->execute([$iid_new, $no_new]); }
            $stored = $stored + $new;
            break;
        } catch (PDOException $e) {
            // souběžný požadavek přidělil čísla dřív: načte se znovu a zkusí se to znovu
            $stored = $pdo->query("SELECT report_id, inc_no FROM incident_numbers")->fetchAll(PDO::FETCH_KEY_PAIR);
        }
    }
    foreach ($incidents as $inc_n) { $incident_no[(int)$inc_n['id']] = (int)($stored[(int)$inc_n['id']] ?? 0); }
} catch (PDOException $e) {
    $incno_ok = false;
    foreach ($inc_by_age as $n_inc => $inc_age) { $incident_no[(int)$inc_age['id']] = $n_inc + 1; }
}
unset($inc_by_age);

// Incidenty, které admin vyřadil z počítání (zůstávají vidět, ale nepočítají se do žebříčku, statistik ani předpovědi)
$incident_flags_ok = true;
$incident_ignored = [];
try {
    foreach ($pdo->query("SELECT report_id, reason FROM incident_ignored")->fetchAll() as $f_row) {
        $incident_ignored[(int)$f_row['report_id']] = (string)($f_row['reason'] ?? '');
    }
} catch (PDOException $e) {
    $incident_flags_ok = false;   // tabulka ještě neexistuje (incidenty_pocitani.sql)
}
// Incidenty oznámené všem příjemcům týdenního souhrnu (tlačítko „Oznámit všem“, oznameni.sql)
$announce_ok = true;
$incident_announced = [];
try {
    foreach ($pdo->query("SELECT report_id, announced_at FROM incident_announced")->fetchAll() as $an_row) {
        $incident_announced[(int)$an_row['report_id']] = (string)$an_row['announced_at'];
    }
} catch (PDOException $e) {
    $announce_ok = false;   // tabulka ještě neexistuje
}
// Štítky incidentů (např. vzorec znečištění); vytváří a přiřazuje je jen admin (stitky.sql)
$labels_ok = true;
$inc_labels = [];       // id štítku => ['id','name','color']
$incident_label_ids = []; // id incidentu (první hlášení „zesraná“) => [id štítků]
try {
    foreach ($pdo->query("SELECT id, name, color FROM incident_labels ORDER BY name")->fetchAll() as $lb_row) { $inc_labels[(int)$lb_row['id']] = $lb_row; }
    foreach ($pdo->query("SELECT report_id, label_id FROM incident_label_map")->fetchAll() as $lm_row) { $incident_label_ids[(int)$lm_row['report_id']][] = (int)$lm_row['label_id']; }
} catch (PDOException $e) {
    $labels_ok = false;
}
// Nevinné osoby u incidentů (jen admin je zadává, vidí je všichni; nevinni.sql). Klíč = id incidentu (první hlášení „zesraná“)
$innocent_ok = true;
$incident_innocents = [];
try {
    foreach ($pdo->query("SELECT id, report_id, person_name, reason FROM incident_innocents")->fetchAll() as $in_row) { $incident_innocents[(int)$in_row['report_id']][] = $in_row; }
} catch (PDOException $e) {
    $innocent_ok = false;
}
// Poznatky (poznatky.sql): sdílené poznámky k osobám a datům; jen mimo náhledovou (read only) verzi
$pozn_ok = false;
$poznatky = [];
if (!$readonly) {
    try {
        $poznatky = $pdo->query("SELECT id, reporter, person_name, happened_at, poznatek, created_at FROM poznatky ORDER BY created_at DESC, id DESC")->fetchAll();
        $pozn_ok = true;
    } catch (PDOException $e) { /* tabulka ještě neexistuje */ }
}
// Aliasy jmen (aliasy.sql) a záznam akcí v aplikaci (audit.sql): jen pro admina
$aliases_ok = false;
$alias_rows = [];
$audit_ok = false;
if ($is_admin) {
    try { $alias_rows = $pdo->query("SELECT id, alias, canonical, created_at FROM name_aliases ORDER BY canonical, alias")->fetchAll(); $aliases_ok = true; } catch (PDOException $e) { /* tabulka ještě neexistuje */ }
    try { $pdo->query("SELECT 1 FROM audit_log LIMIT 1"); $audit_ok = true; } catch (PDOException $e) { /* tabulka ještě neexistuje */ }
}
// Důvěryhodní (duveryhodni.sql): osoby, které filtr „Skrýt důvěryhodné“ skrývá; seznam spravuje admin
$trusted_ok = false;
$trusted_names = [];
try {
    $trusted_names = $pdo->query("SELECT person_name FROM trusted_persons ORDER BY person_name")->fetchAll(PDO::FETCH_COLUMN);
    $trusted_ok = true;
} catch (PDOException $e) { /* tabulka ještě neexistuje */ }
// Milníky (důležité informace s datem; zadává jen admin, vidí všichni; milniky.sql)
$milestones_ok = true;
$milestones = [];
try {
    $milestones = $pdo->query("SELECT id, kind, person_name, date_from, date_to, title, note FROM milestones ORDER BY date_from DESC, id DESC")->fetchAll();
} catch (PDOException $e) {
    $milestones_ok = false;   // tabulka ještě neexistuje
}
// Seznam všech zaměstnanců (zadává admin; z něj se počítají „Osoby bez alibi“; zamestnanci.sql)
$employees_ok = true;
$employee_names = [];
try {
    $employee_names = $pdo->query("SELECT name FROM employees")->fetchAll(PDO::FETCH_COLUMN);
    usort($employee_names, 'lh_cz_cmp');
} catch (PDOException $e) {
    $employees_ok = false;
}
foreach ($incident_innocents as $k_in => $rows_in) { usort($rows_in, function ($a, $b) { return lh_cz_cmp($a['person_name'], $b['person_name']); }); $incident_innocents[$k_in] = $rows_in; }
$digest_rcpt_n = $is_admin ? count(lh_mail_list($pdo, 'mail_digest')) : 0;   // počet příjemců pro potvrzení tlačítka „Oznámit všem“
$incidents_counted = [];
foreach ($incidents as $k_inc => $inc_row) {
    $incidents[$k_inc]['counted'] = !isset($incident_ignored[(int)$inc_row['id']]);
    $incidents[$k_inc]['ignore_reason'] = $incident_ignored[(int)$inc_row['id']] ?? '';
    if ($incidents[$k_inc]['counted']) { $incidents_counted[] = $incidents[$k_inc]; }
}

// ==========================================
// 4. ANALÝZA PODEZŘELÝCH (ALGORITMUS)
// ==========================================
// ==========================================
// 3b. STATISTIKY INCIDENTŮ (agregace, bez osobních údajů)
// ==========================================
$cz_days = [1 => 'pondělí', 2 => 'úterý', 3 => 'středa', 4 => 'čtvrtek', 5 => 'pátek', 6 => 'sobota', 7 => 'neděle'];

// Záloha databáze: SQL dump streamovaný přímo z databáze (gzip, pokud je dostupný zlib), bez načítání celé tabulky do paměti
function lh_stream_backup($pdo, $db_name) {
    @set_time_limit(0);
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) { ob_end_clean(); }

    $gz = function_exists('deflate_init');
    $filename = 'lh-zaloha-' . date('Y-m-d_Hi') . ($gz ? '.sql.gz' : '.sql');
    header('Content-Type: ' . ($gz ? 'application/gzip' : 'application/sql'));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $ctx = $gz ? deflate_init(ZLIB_ENCODING_GZIP) : null;
    $buf = '';
    $emit = function ($chunk, $final = false) use (&$buf, $ctx, $gz) {
        $buf .= $chunk;
        if (!$final && strlen($buf) < 65536) { return; }
        echo $gz ? deflate_add($ctx, $buf, $final ? ZLIB_FINISH : ZLIB_NO_FLUSH) : $buf;
        $buf = '';
        flush();
    };

    $tables_done = 0;
    try {
        $emit("-- Detektor Lorda Hoven: záloha databáze " . $db_name . "\n-- " . date('Y-m-d H:i:s') . "\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n\n");
        $tables = array_column($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM), 0);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        foreach ($tables as $t) {
            $tq = '`' . str_replace('`', '``', $t) . '`';

            $st = $pdo->query("SHOW CREATE TABLE $tq");
            $create = $st->fetch(PDO::FETCH_NUM);
            $st->closeCursor();
            $emit("DROP TABLE IF EXISTS $tq;\n" . $create[1] . ";\n\n");

            $st = $pdo->query("SELECT * FROM $tq");
            $head = null;
            $batch = [];
            while ($row = $st->fetch(PDO::FETCH_NUM)) {
                if ($head === null) {
                    $cols = [];
                    for ($i = 0; $i < $st->columnCount(); $i++) {
                        $meta = $st->getColumnMeta($i);
                        $cols[] = '`' . str_replace('`', '``', $meta['name']) . '`';
                    }
                    $head = "INSERT INTO $tq (" . implode(',', $cols) . ") VALUES\n";
                }
                $vals = [];
                foreach ($row as $v) { $vals[] = $v === null ? 'NULL' : $pdo->quote((string)$v); }
                $batch[] = '(' . implode(',', $vals) . ')';
                if (count($batch) >= 250) { $emit($head . implode(",\n", $batch) . ";\n"); $batch = []; }
            }
            $st->closeCursor();
            if (!empty($batch)) { $emit($head . implode(",\n", $batch) . ";\n"); }
            $emit("\n");
            $tables_done++;
        }
        $emit("SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n\n-- KONEC ZÁLOHY (tabulek: " . $tables_done . ")\n", true);
    } catch (Throwable $e) {
        // Neúplná záloha se pozná podle chybějícího řádku "KONEC ZÁLOHY"
        $emit("\n-- CHYBA PŘI ZÁLOZE, záloha je NEÚPLNÁ\n", true);
    }
}

// ==========================================
// E-MAILY (SMTP): upozornění adminům, záznam akcí admina, týdenní souhrn
// ==========================================

function lh_setting_set($pdo, $key, $val) {
    $st = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $st->execute([$key, $val]);
}

// Platné e-mailové adresy uložené v nastavení (oddělené čárkou, mezerou nebo novým řádkem)
function lh_mail_list($pdo, $key) {
    $out = [];
    foreach (preg_split('/[\s,;]+/', lh_setting($pdo, $key), -1, PREG_SPLIT_NO_EMPTY) as $e) {
        if (filter_var($e, FILTER_VALIDATE_EMAIL)) { $out[] = $e; }
    }
    return $out;
}

function lh_mail_configured($smtp) {
    return $smtp['host'] !== '' && $smtp['user'] !== '' && $smtp['pass'] !== '';
}

// Odeslání jedné zprávy přes SMTP (STARTTLS + AUTH LOGIN). Vrací null při úspěchu, jinak text chyby.
function lh_smtp_send($smtp, $to, $subject, $body) {
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => $smtp['verify'], 'verify_peer_name' => $smtp['verify'],
        'allow_self_signed' => !$smtp['verify'], 'peer_name' => $smtp['host'],
    ]]);
    $fp = @stream_socket_client('tcp://' . $smtp['host'] . ':' . $smtp['port'], $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { return 'spojení se serverem selhalo (' . trim($errstr) . ')'; }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp) {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') { break; }
        }
        return $out;
    };
    $cmd = function ($c, $expect) use ($fp, $read) {
        if ($c !== null) { fwrite($fp, $c . "\r\n"); }
        $r = $read();
        if (strpos($r, (string)$expect) !== 0) { throw new Exception(trim($r) !== '' ? trim($r) : 'server neodpověděl'); }
        return $r;
    };

    try {
        $helo = parse_url('https://' . preg_replace('~^https?://~', '', (string)($GLOBALS['app_url'] ?? 'localhost')), PHP_URL_HOST) ?: 'localhost';
        $cmd(null, 220);
        $cmd('EHLO ' . $helo, 250);
        $cmd('STARTTLS', 220);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { throw new Exception('šifrované spojení (TLS) se nepodařilo'); }
        $cmd('EHLO ' . $helo, 250);
        $cmd('AUTH LOGIN', 334);
        $cmd(base64_encode($smtp['user']), 334);
        $cmd(base64_encode($smtp['pass']), 235);
        $cmd('MAIL FROM:<' . $smtp['from'] . '>', 250);
        $cmd('RCPT TO:<' . $to . '>', 250);
        $cmd('DATA', 354);
        $msg = "From: Detektor Lorda Hoven <" . $smtp['from'] . ">\r\n"
             . "To: <" . $to . ">\r\n"
             . "Subject: =?UTF-8?B?" . base64_encode(str_replace(["\r", "\n"], ' ', $subject)) . "?=\r\n"
             . "Date: " . date('r') . "\r\n"
             . "Message-ID: <" . bin2hex(random_bytes(8)) . "@" . $helo . ">\r\n"
             . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($body));
        $cmd($msg . "\r\n.", 250);
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return null;
    } catch (Exception $e) {
        @fclose($fp);
        return $e->getMessage();
    }
}

// Pošle zprávu každému příjemci zvlášť (příjemci se navzájem nevidí). Vrací seznam chyb (prázdný = vše v pořádku).
function lh_mail_send_all($smtp, $recipients, $subject, $body) {
    $errors = [];
    foreach ($recipients as $to) {
        $err = lh_smtp_send($smtp, $to, $subject, $body);
        if ($err !== null) { $errors[] = $to . ': ' . $err; }
    }
    return $errors;
}

// Upozornění se odešle až po odeslání odpovědi uživateli (pokud to PHP umožní); poslední chyba se uloží do nastavení
function lh_mail_event($pdo, $smtp, $list_key, $subject, $body) {
    if (!lh_mail_configured($smtp)) { return; }
    $to = lh_mail_list($pdo, $list_key);
    if (empty($to)) { return; }
    register_shutdown_function(function () use ($pdo, $smtp, $to, $subject, $body) {
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        $errors = lh_mail_send_all($smtp, $to, $subject, $body);
        try { lh_setting_set($pdo, 'mail_last_error', $errors ? date('Y-m-d H:i:s') . ' ' . $errors[0] : ''); } catch (Throwable $e) {}
    });
}

// Upozornění pro adminy: skupiny, které si admin zapíná zaškrtávátky. Nastavení admin_notify = „v1:“ + seznam zapnutých skupin;
// bez nastavení je zapnuto vše (původní chování). Zkušební e-maily se posílají vždy.
function lh_notify_groups() {
    return [
        'Události' => [
            'new_incident' => 'Nový incident (hlášení „zesraná“ po „čisto“)',
            'new_sighting' => 'Nové pozorování ke schválení',
        ],
        'Záznam akcí admina' => [
            'login' => 'Přihlášení admina',
            'backup' => 'Stažení zálohy databáze',
            'reports' => 'Úprava a mazání hlášení',
            'import' => 'Import docházky',
            'exclusions' => 'Vyřazování osob a incidentů z počítání',
            'sightings_admin' => 'Schvalování a úpravy pozorování osob',
            'it' => 'IT oddělení a dovolené',
            'analysis' => 'Štítky a nevinné osoby',
            'announce' => 'Oznámení incidentu všem',
            'settings' => 'Nastavení a ostatní změny (e-maily, tolerance, zaměstnanci, názvy zařízení…)',
        ],
    ];
}
function lh_notify_enabled($pdo, $key) {
    $v = lh_setting($pdo, 'admin_notify');
    if (strncmp($v, 'v1:', 3) !== 0) { return true; }
    return in_array($key, explode(',', substr($v, 3)), true);
}
// Do které skupiny upozornění patří akce admina
function lh_audit_group($action) {
    static $map = [
        'edit_report' => 'reports', 'delete_report' => 'reports', 'poznatek_edit' => 'reports', 'poznatek_delete' => 'reports', 'trusted_add' => 'analysis', 'trusted_delete' => 'analysis',
        'blacklist_suspect' => 'exclusions', 'restore_suspect' => 'exclusions', 'incident_set_counted' => 'exclusions',
        'sighting_approve' => 'sightings_admin', 'sighting_reject' => 'sightings_admin', 'sighting_delete' => 'sightings_admin', 'sighting_rename' => 'sightings_admin', 'sighting_edit' => 'sightings_admin',
        'it_add_staff' => 'it', 'it_remove_staff' => 'it', 'it_set_until' => 'it', 'it_set_until_all' => 'it', 'it_add_vacation' => 'it', 'it_delete_vacation' => 'it',
        'label_save' => 'analysis', 'label_delete' => 'analysis', 'incident_labels_set' => 'analysis', 'innocent_add' => 'analysis', 'innocent_delete' => 'analysis', 'milestone_grant' => 'analysis',
        'incident_announce' => 'announce', 'culprit_set' => 'announce', 'culprit_clear' => 'announce', 'culprit_notify' => 'announce',
    ];
    return $map[$action] ?? 'settings';
}

// Retence (volitelná, výchozí je nemazat nic): retention_audit_days = záznam akcí, retention_device_days = zařízení admina,
// retention_ip_days = IP adresy u hlášení a pozorování (starší se anonymizují, záznam zůstává). Běží nejvýš jednou denně.
function lh_retention_cleanup($pdo, $config) {
    $audit = (int)($config['retention_audit_days'] ?? 0);
    $dev = (int)($config['retention_device_days'] ?? 0);
    $ip = (int)($config['retention_ip_days'] ?? 0);
    if ($audit <= 0 && $dev <= 0 && $ip <= 0) { return; }
    if (lh_setting($pdo, 'retention_last_run') === date('Y-m-d')) { return; }
    $jobs = [];
    if ($audit > 0) { $jobs[] = ["DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)", $audit]; }
    if ($dev > 0) { $jobs[] = ["DELETE FROM device_seen WHERE last_seen < DATE_SUB(NOW(), INTERVAL ? DAY)", $dev]; }
    if ($ip > 0) {
        $jobs[] = ["UPDATE toilet_reports SET client_ip = NULL WHERE client_ip IS NOT NULL AND reported_at < DATE_SUB(NOW(), INTERVAL ? DAY)", $ip];
        $jobs[] = ["UPDATE sightings SET client_ip = NULL WHERE client_ip IS NOT NULL AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)", $ip];
        $jobs[] = ["UPDATE device_seen SET last_ip = NULL WHERE last_ip IS NOT NULL AND last_seen < DATE_SUB(NOW(), INTERVAL ? DAY)", $ip];
    }
    foreach ($jobs as $j) { try { $st = $pdo->prepare($j[0]); $st->execute([$j[1]]); } catch (PDOException $e) { /* tabulka nebo sloupec ještě neexistuje */ } }
    lh_setting_set($pdo, 'retention_last_run', date('Y-m-d'));
}

// Záznam akce do databáze (Admin → Záznam akcí). Zapisuje se vždy, nezávisle na e-mailových upozorněních. Nikdy neobsahuje hesla ani klíče.
function lh_audit_store($pdo, $label, $action, $params, $before, $result, $client_ip, $device_id, $user, $group) {
    try {
        $det = [];
        foreach ($params as $k => $v) {
            if ($k === 'action' || stripos($k, 'password') !== false || stripos($k, 'token') !== false) { continue; }
            if (is_array($v)) { $v = implode(', ', array_filter(array_map(function ($x) { return is_scalar($x) ? (string)$x : ''; }, $v), 'strlen')); }
            if (trim((string)$v) === '') { continue; }
            $val = trim(preg_replace('/\s*[\r\n]+\s*/', ', ', (string)$v));
            if (mb_strlen($val) > 1500) { $val = mb_substr($val, 0, 1500) . ' … (zkráceno)'; }
            $det[] = $k . ' = ' . $val;
        }
        if (is_array($before)) { $det[] = 'Původní záznam: ' . implode(', ', array_map(function ($k, $v) { return $k . '=' . $v; }, array_keys($before), $before)); }
        $st = $pdo->prepare("INSERT INTO audit_log (user_name, ip, device, action_key, label, grp, result, details) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $st->execute([mb_substr((string)$user, 0, 100), $client_ip ?: null, substr((string)$device_id, 0, 6), mb_substr((string)$action, 0, 60), mb_substr((string)$label, 0, 120), mb_substr((string)$group, 0, 20),
            $result !== '' ? mb_substr((string)$result, 0, 500) : null, mb_substr(implode("\n", $det), 0, 16000)]);
    } catch (PDOException $e) { /* tabulka ještě neexistuje */ }
}

// Externí záznam akce admina (e-mail adminům). Nikdy neobsahuje hesla ani klíče.
function lh_audit($pdo, $smtp, $label, $params, $before, $result, $client_ip, $device_id, $user = '', $group = 'settings') {
    lh_audit_store($pdo, $label, (string)($params['action'] ?? ''), $params, $before, $result, $client_ip, $device_id, $user, $group);
    if (!lh_notify_enabled($pdo, $group)) { return; }
    $lines = ['Akce admina: ' . $label, 'Čas: ' . date('j. n. Y H:i:s'), 'Uživatel: ' . ($user !== '' ? $user : 'neidentifikován (přihlášení heslem)'), 'IP adresa klienta: ' . ($client_ip ?: 'neznámá'), 'Zařízení: #' . substr((string)$device_id, 0, 6) . (($dl = (load_device_labels($pdo, true)[$device_id] ?? '')) !== '' ? ' (' . $dl . ')' : '')];
    if ($result !== '') { $lines[] = 'Výsledek: ' . $result; }
    $det = [];
    foreach ($params as $k => $v) {
        if ($k === 'action' || stripos($k, 'password') !== false || stripos($k, 'token') !== false || is_array($v) || trim((string)$v) === '') { continue; }
        // Víceřádkové hodnoty (např. seznamy adres) se spojí do jednoho řádku; ořez je až u velmi dlouhých textů a je označený
        $val = trim(preg_replace('/\s*[\r\n]+\s*/', ', ', (string)$v));
        if (mb_strlen($val) > 1500) { $val = mb_substr($val, 0, 1500) . ' … (zkráceno)'; }
        $det[] = '  ' . $k . ' = ' . $val;
    }
    if ($det) { $lines[] = "Údaje:\n" . implode("\n", $det); }
    if (is_array($before)) {
        $lines[] = "Původní záznam:\n  " . implode(', ', array_map(function ($k, $v) { return $k . '=' . $v; }, array_keys($before), $before));
    }
    lh_mail_event($pdo, $smtp, 'mail_admins', '[Detektor] Akce admina: ' . $label, implode("\n", $lines));
}

// Počet „Aktuálně podezřelých“ pro týdenní souhrn: stejně jako ve výchozím zobrazení žebříčku se nevinné osoby (nevinnost u některého započítaného incidentu)
// a důvěryhodné osoby (seznam admina) přeskočí a štítek mají všichni na prvním místě mezi zbylými s aspoň jedním bodem.
function lh_digest_leaders($suspects, $incident_innocents, $incidents, $trusted_names) {
    $skip = [];
    foreach ($incidents as $inc) {
        if (empty($inc['counted'])) { continue; }
        foreach ($incident_innocents[(int)$inc['id']] ?? [] as $row) { $skip[lh_name_norm($row['person_name'])] = true; }
    }
    foreach ($trusted_names as $tn) { $skip[lh_name_norm($tn)] = true; }
    $top = 0; $n = 0;
    foreach ($suspects as $sp) {
        if ((int)$sp['score'] <= 0 || isset($skip[lh_name_norm($sp['name'])])) { continue; }
        if ((int)$sp['score'] > $top) { $top = (int)$sp['score']; $n = 1; }
        elseif ((int)$sp['score'] === $top) { $n++; }
    }
    return $n;
}

// E-mail o dopadení pachatele všem příjemcům týdenního souhrnu (seznam mail_digest). Jméno pachatele se do e-mailu nepíše. Vrací [počet odeslaných, chyby].
// Texty e-mailů lze přepsat v konfiguraci: 'texts' => ['culprit_mail_intro' => [...řádky], 'culprit_mail_outro' => [...], 'digest_outro' => [...]]
function lh_text_lines($key, $default) {
    global $config;
    $v = $config['texts'][$key] ?? null;
    return (is_array($v) && $v) ? array_map('strval', $v) : $default;
}

function lh_culprit_mail($pdo, $smtp, $incidents, $app_url) {
    if (!lh_mail_configured($smtp)) { return [0, ['SMTP není nastaveno']]; }
    $to = lh_mail_list($pdo, 'mail_digest');
    if (empty($to)) { return [0, ['seznam příjemců souhrnu je prázdný']]; }
    $cnt = 0; $first = null; $last = null;
    foreach ($incidents as $inc) {
        if (empty($inc['counted'])) { continue; }
        $cnt++;
        $t = strtotime($inc['window_end']);
        if ($first === null || $t < $first) { $first = $t; }
        if ($last === null || $t > $last) { $last = $t; }
    }
    $lines = lh_text_lines('culprit_mail_intro', ['Lord Hoven byl dopaden!', '', 'Pachatel byl dopaden a dosral svoje poslední hovno.', '',
        'Sběr dat je ukončen, nová hlášení se už nepřijímají.']);
    if ($cnt > 0) { $lines[] = 'Útoků celkem: ' . $cnt . ' (první ' . date('j. n. Y', $first) . ', poslední ' . date('j. n. Y', $last) . ')'; }
    $lines[] = '';
    foreach (lh_text_lines('culprit_mail_outro', ['Díky všem, kdo hlídali, hlásili a koukali na záchod pozorněji než kdy dřív.', 'Kancelář je zase v bezpečí.']) as $lh_ln) { $lines[] = $lh_ln; }
    $lines[] = '';
    $lines[] = $app_url;
    $errs = lh_mail_send_all($smtp, $to, '[Detektor] Pachatel byl dopaden!', implode("\n", $lines));
    $n_ok = count($to) - count($errs);
    if (!$errs) { lh_setting_set($pdo, 'culprit_mailed_at', date('Y-m-d H:i:s')); }
    return [$n_ok, $errs];
}

// Týdenní souhrn za posledních 7 dní, bez jmen osob
function lh_build_digest($pdo, $incidents, $cubicles_list, $app_url, $now_ts, $leaders_n = null) {
    $from = $now_ts - 7 * 86400;
    $prev = $now_ts - 14 * 86400;
    $cur = [];
    $before = 0;
    $ignored = 0;
    foreach ($incidents as $inc) {
        $t = strtotime($inc['window_end']);
        if ($t >= $from && $t <= $now_ts) {
            if (!empty($inc['counted'])) { $cur[] = $inc; } else { $ignored++; }
        } elseif ($t >= $prev && $t < $from && !empty($inc['counted'])) {
            $before++;
        }
    }
    $by_cub = array_fill_keys(array_keys($cubicles_list), 0);
    $hours = [];
    $mins = [];
    foreach ($cur as $inc) {
        if (isset($by_cub[$inc['cubicle']])) { $by_cub[$inc['cubicle']]++; }
        $h = (int)date('G', strtotime($inc['window_end']));
        $hours[$h] = ($hours[$h] ?? 0) + 1;
        if (!empty($inc['window_start'])) { $mins[] = (strtotime($inc['window_end']) - strtotime($inc['window_start'])) / 60; }
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM toilet_reports WHERE reported_at >= ? AND reported_at <= ?");
    $st->execute([date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $now_ts)]);
    $reports_n = (int)$st->fetchColumn();

    $lines = [
        'Týdenní hlášení z Detektoru Lorda Hoven',
        'Období: ' . date('j. n. Y', $from) . ' až ' . date('j. n. Y', $now_ts),
        '',
        'Útoků: ' . count($cur) . ' (minulý týden: ' . $before . ')',
        '',
    ];
    // Počet osob, které mají v žebříčku štítek Podezřelý (všichni na prvním místě mezi osobami, které nejsou nevinné ani důvěryhodné)
    if ($leaders_n !== null) {
        $lines[] = 'Aktuálně podezřelých: ' . (int)$leaders_n;
        $lines[] = '';
    }
    $lines[] = 'Kde Lord Hoven útočil:';
    foreach ($by_cub as $k => $n_cub) { $lines[] = '  ' . ($cubicles_list[$k] ?? $k) . ': ' . $n_cub; }
    $lines[] = '';
    if (!empty($hours) && max($hours) >= 2) {
        $ph = array_search(max($hours), $hours);
        $lines[] = sprintf('Nejčastější hodina útoku: %02d:00 až %02d:59 (%d×)', $ph, $ph, max($hours));
        $lines[] = '';
    }
    if (!empty($mins)) {
        sort($mins);
        $n = count($mins);
        $median = ($n % 2) ? $mins[intdiv($n, 2)] : ($mins[$n / 2 - 1] + $mins[$n / 2]) / 2;
        $lines[] = 'Jak dlouho měl nepřítel volnou ruku (časová okna incidentů):';
        $lines[] = '  obvykle (medián): ' . format_minutes($median);
        $lines[] = '  nejdéle: ' . format_minutes(max($mins));
        $lines[] = '';
    }
    $lines[] = 'Došlých hlášení: ' . $reports_n;
    if ($ignored > 0) { $lines[] = 'Útoků vyřazených z počítání: ' . $ignored; }
    $lines[] = '';
    foreach (lh_text_lines('digest_outro', ['Lord Hoven je stále na svobodě, ale prostor se mu zužuje a smyčka se stahuje.', 'Kancelář tě potřebuje více než kdy dřív!', '', 'Viděl jsi něco? Viděl jsi někoho? Byl jsi na toaletě?']) as $lh_ln) { $lines[] = $lh_ln; }
    $lines[] = 'Podej hlášení: ' . $app_url;

    return ['[Detektor] Týdenní souhrn ' . date('j. n.', $from) . ' až ' . date('j. n. Y', $now_ts), implode("\n", $lines)];
}

// Odešle souhrn všem příjemcům; vrací [počet příjemců, chyby]
function lh_digest_send($pdo, $smtp, $incidents, $cubicles_list, $app_url, $list_key = 'mail_digest', $mark_sent = true, $subject_prefix = '', $leaders_n = null) {
    $to = lh_mail_list($pdo, $list_key);
    if (empty($to)) { return [0, ['seznam příjemců je prázdný']]; }
    list($subject, $body) = lh_build_digest($pdo, $incidents, $cubicles_list, $app_url, time(), $leaders_n);
    $errors = lh_mail_send_all($smtp, $to, $subject_prefix . $subject, $body);
    // Testovací souhrn ($mark_sent = false) nesmí posunout časovač posledního odeslání
    if ($mark_sent && count($errors) < count($to)) { try { lh_setting_set($pdo, 'digest_last_sent', date('Y-m-d H:i:s')); } catch (Throwable $e) {} }
    return [count($to) - count($errors), $errors];
}

function format_minutes($m) {
    $m = (int)round($m);
    $h = intdiv($m, 60);
    return ($h > 0 ? $h . ' h ' : '') . ($m % 60) . ' min';
}

// Sada jednoduchých ikon (inline SVG, bez externích závislostí)
// Ikony: dráhy převzaté z knihovny Feather (https://feathericons.com, licence MIT, viz NOTICE.md)
function lh_icon($name, $size = 18) {
    static $paths = [
        'moon' => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
        'alert' => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'chart' => '<path d="M18 20V10M12 20V4M6 20v-6"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>',
        'target' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'eye' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
        'monitor' => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'trend' => '<path d="m23 6-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/>',
        'flag' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22v-7"/>',
    ];
    if (!isset($paths[$name])) { return ''; }
    return '<svg class="lh-ico" width="' . (int)$size . '" height="' . (int)$size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
}

// Doba v čitelném tvaru (z vteřin): „42 min“, „1 h 05 min“, „2 d 3 h“
function lh_dur_txt($sec) {
    $sec = max(0, (int)$sec);
    if ($sec < 60) { return 'méně než minutu'; }
    if ($sec < 3600) { return floor($sec / 60) . ' min'; }
    if ($sec < 86400) { return floor($sec / 3600) . ' h ' . sprintf('%02d', floor(($sec % 3600) / 60)) . ' min'; }
    return floor($sec / 86400) . ' d ' . floor(($sec % 86400) / 3600) . ' h';
}

// Karta jednoho pozorování ve frontě admina
function render_sighting_card($sg, $locs, $dirs, $device_labels) {
    ob_start();
    $dev = $sg['device_id'] ?? null;
    $status_map = ['approved' => ['schváleno', 'badge-ok'], 'rejected' => ['zamítnuto', 'badge-zesrana']];
    ?>
    <div class="lh-box mb-2">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <strong><?= htmlspecialchars($sg['person_name']) ?></strong>
                <?php if (empty($sg['name_known'])): ?><span class="badge bg-warning text-dark">neznámé jméno</span><?php endif; ?>
                <?php if (isset($status_map[$sg['status']])): ?><span class="badge <?= $status_map[$sg['status']][1] ?>"><?= $status_map[$sg['status']][0] ?></span><?php endif; ?>
            </div>
            <span class="small text-body-secondary">viděno <strong><?= htmlspecialchars(lh_d($sg['seen_at'])) ?></strong></span>
        </div>
        <div class="small text-body-secondary mt-1">
            <?= htmlspecialchars($locs[$sg['location']] ?? $sg['location']) ?> · <?= htmlspecialchars($dirs[$sg['direction']] ?? $sg['direction']) ?>
            · nahlásil <?= htmlspecialchars($sg['reporter']) ?>
            <?php if ($dev): ?>· zařízení <span class="font-monospace"><?= htmlspecialchars($device_labels[$dev] ?? ('#' . substr($dev, 0, 6))) ?></span><?php endif; ?>
            <?php if (!empty($sg['client_ip'])): ?>· <?= htmlspecialchars($sg['client_ip']) ?><?php endif; ?>
        </div>
        <?php if (!empty($sg['note'])): ?><div class="small mt-1 lh-note"><strong>Poznámka:</strong> <?= htmlspecialchars($sg['note']) ?></div><?php endif; ?>
        <div class="small text-body-secondary mt-1">
            odesláno <?= htmlspecialchars(lh_d($sg['created_at'] ?? '–')) ?>
            <?php if (!empty($sg['decided_at'])): ?>· vyřízeno <?= htmlspecialchars(lh_d($sg['decided_at'])) ?><?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-2">
            <?php if ($sg['status'] !== 'approved'): ?>
                <form method="POST" class="m-0"><input type="hidden" name="action" value="sighting_approve"><input type="hidden" name="id" value="<?= (int)$sg['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-success">Schválit</button></form>
            <?php endif; ?>
            <?php if ($sg['status'] !== 'rejected'): ?>
                <form method="POST" class="m-0"><input type="hidden" name="action" value="sighting_reject"><input type="hidden" name="id" value="<?= (int)$sg['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger">Zamítnout</button></form>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-secondary btn-sighting-edit" data-id="<?= (int)$sg['id'] ?>" data-name="<?= htmlspecialchars($sg['person_name']) ?>" data-location="<?= htmlspecialchars($sg['location']) ?>" data-direction="<?= htmlspecialchars($sg['direction']) ?>" data-seen="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime($sg['seen_at']))) ?>" data-reporter="<?= htmlspecialchars($sg['reporter']) ?>" data-note="<?= htmlspecialchars((string)($sg['note'] ?? '')) ?>">Upravit</button>
            <button type="button" class="btn btn-sm btn-outline-secondary btn-sighting-rename" data-id="<?= (int)$sg['id'] ?>" data-name="<?= htmlspecialchars($sg['person_name']) ?>">Přejmenovat</button>
            <form method="POST" class="m-0" data-confirm="Opravdu smazat toto pozorování?"><input type="hidden" name="action" value="sighting_delete"><input type="hidden" name="id" value="<?= (int)$sg['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-secondary">Smazat</button></form>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function next_workday_ts($ts) {
    $n = strtotime('+1 day', $ts);
    while ((int)date('N', $n) >= 6) { $n = strtotime('+1 day', $n); }
    return $n;
}

// ==========================================
// PŘEDPOVĚDNÍ MODEL (jednoduchý statistický odhad, ne strojové učení)
// ==========================================
define('PRED_WH_START', 5);   // pracovní doba modelu od 5:00
define('PRED_WH_END', 23);    // do 23:00
define('PRED_BINS', (PRED_WH_END - PRED_WH_START) * 4);   // 15minutové koše

// Rozloží okno incidentu na 15minutové koše pracovní doby (jen Po až Pá); součet vah = 1
function pred_window_cells($start, $end) {
    $cells = [];
    $total = 0.0;
    $day = strtotime(date('Y-m-d', $start));
    while ($day <= $end) {
        $dow = (int)date('N', $day);
        if ($dow <= 5) {
            for ($b = 0; $b < PRED_BINS; $b++) {
                $bs = $day + PRED_WH_START * 3600 + $b * 900;
                $ov = min($end, $bs + 900) - max($start, $bs);
                if ($ov > 0) { $cells[] = [$b, $dow, $ov]; $total += $ov; }
            }
        }
        $day = strtotime('+1 day', $day);
    }
    if ($total <= 0) { return []; }
    foreach ($cells as &$c) { $c[2] = $c[2] / $total; }
    unset($c);
    return $cells;
}

// Rozdělení pravděpodobnosti hodiny a dne v týdnu.
// Čas vzniku incidentu neznáme, jen okno, ve kterém leží. Rozdělení se proto odhaduje iterativně (EM s vyhlazením):
// každý incident se přiřadí do těch částí svého okna, které jsou podle aktuálního odhadu pravděpodobnější.
// Díky tomu hodiny na začátku oken (ranní kontrola) nezískávají váhu jen proto, že tam okno začíná.
function pred_build_model($wins, $ref_ts, $half_life_weeks = 6, $sigma = 3, $iterations = 15) {
    $nb = PRED_BINS;
    $f = array_fill(0, $nb, 1.0 / $nb);
    $g = [1 => 0.2, 2 => 0.2, 3 => 0.2, 4 => 0.2, 5 => 0.2];

    $weights = [];
    $wsum = 0.0;
    foreach ($wins as $i => $w) {
        $age_weeks = max(0, ($ref_ts - $w['end']) / (7 * 86400));
        $weights[$i] = pow(0.5, $age_weeks / $half_life_weeks);
        $wsum += $weights[$i];
    }

    $kernel = [];
    $ksum = 0.0;
    for ($k = -3 * $sigma; $k <= 3 * $sigma; $k++) {
        $kernel[$k] = exp(-($k * $k) / (2 * $sigma * $sigma));
        $ksum += $kernel[$k];
    }

    if ($wsum > 0) {
        for ($it = 0; $it < $iterations; $it++) {
            $nf = array_fill(0, $nb, 0.0);
            $ng = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0];

            // E-krok: posterior části okna ~ f(koš) * g(den) * překryv
            foreach ($wins as $i => $w) {
                $den = 0.0;
                $post = [];
                foreach ($w['cells'] as $ci => $c) {
                    $post[$ci] = $f[$c[0]] * $g[$c[1]] * $c[2];
                    $den += $post[$ci];
                }
                if ($den <= 0) { continue; }
                $kk = $weights[$i] / $den;
                foreach ($w['cells'] as $ci => $c) {
                    $q = $post[$ci] * $kk;
                    $nf[$c[0]] += $q;
                    $ng[$c[1]] += $q;
                }
            }

            // M-krok: normalizace dne a vyhlazení rozdělení v čase
            $ng_sum = array_sum($ng);
            if ($ng_sum <= 0) { break; }
            foreach ($ng as $d => $v) { $g[$d] = $v / $ng_sum; }

            $sm = array_fill(0, $nb, 0.0);
            foreach ($nf as $b => $v) {
                if ($v <= 0) { continue; }
                foreach ($kernel as $k => $kv) {
                    $bb = $b + $k;
                    if ($bb >= 0 && $bb < $nb) { $sm[$bb] += $v * $kv / $ksum; }
                }
            }
            $sm_sum = array_sum($sm);
            if ($sm_sum <= 0) { break; }
            foreach ($sm as $b => $v) { $f[$b] = 0.99 * ($v / $sm_sum) + 0.01 / $nb; }
        }
    }

    // Příměs rovnoměrného rozdělení úměrná tomu, jak málo dat model má
    $alpha_h = 3.0;
    $bin_p = [];
    foreach ($f as $b => $v) { $bin_p[$b] = ($wsum * $v + $alpha_h / $nb) / ($wsum + $alpha_h); }
    $dow_p = [];
    foreach ($g as $d => $v) { $dow_p[$d] = ($wsum * $v + 1.0) / ($wsum + 5.0); }
    $hour_p = [];
    for ($h = 0; $h < PRED_WH_END - PRED_WH_START; $h++) {
        $hour_p[$h] = $bin_p[4 * $h] + $bin_p[4 * $h + 1] + $bin_p[4 * $h + 2] + $bin_p[4 * $h + 3];
    }
    return ['hour' => $hour_p, 'dow' => $dow_p];
}

// Kvantil rozdělení Gamma(shape k, rate r) pomocí Wilson-Hilfertyho aproximace
function pred_gamma_quantile($k, $r, $z) {
    $t = 1 - 1 / (9 * $k) + $z / (3 * sqrt($k));
    return max(0.0, $k / $r * pow(max(0.0, $t), 3));
}

function pred_day_label($ts) {
    $names = [1 => 'Po', 2 => 'Út', 3 => 'St', 4 => 'Čt', 5 => 'Pá', 6 => 'So', 7 => 'Ne'];
    return $names[(int)date('N', $ts)] . ' ' . date('j. n.', $ts);
}

// Jednoduchý sloupcový graf z HTML/CSS (bez knihoven, drží se světlého/tmavého režimu)
function render_bar_chart($labels, $values, $colors = 'var(--lh-text-3)', $decimals = 0, $label_step = 1) {
    $max = max($values);
    if ($max <= 0) { $max = 1; }
    $cols = 'display: grid; grid-template-columns: repeat(' . count($values) . ', minmax(0, 1fr)); gap: 4px;';
    // Sloupce i popisky sdílejí stejnou mřížku se stejně širokými sloupci, takže popisky vždy sedí pod svými sloupci
    ob_start(); ?>
    <div style="<?= $cols ?> align-items: end; height: 150px; background-image: linear-gradient(to top, var(--lh-border) 1px, transparent 1px); background-size: 100% 25%; background-position: bottom;">
        <?php foreach ($values as $i => $v): $c = is_array($colors) ? ($colors[$i] ?? 'var(--lh-text-3)') : $colors; ?>
            <div class="d-flex flex-column justify-content-end align-items-center" style="min-width: 0; height: 100%;" title="<?= htmlspecialchars($labels[$i]) ?>: <?= round($v, $decimals) ?>">
                <span class="text-body-secondary" style="font-size: .65rem;"><?= $v > 0 ? round($v, $decimals) : '' ?></span>
                <div style="width: 100%; height: <?= round($v / $max * 80) ?>%; min-height: <?= $v > 0 ? 3 : 0 ?>px; background: <?= $c ?>; border-radius: 6px 6px 0 0;"></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="border-top pt-1" style="<?= $cols ?>">
        <?php foreach ($labels as $i => $label): ?>
            <div class="text-center text-body-secondary" style="min-width: 0; font-size: .65rem;"><?= ($i % $label_step === 0) ? htmlspecialchars($label) : '&nbsp;' ?></div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

// Statistiky a předpověď pro zadanou množinu incidentů: výchozí je počítaná adminem, přes ?ajax=stats i výběr uživatele ($custom).
// Vrací všechny lokální proměnné, které pak používá lh_stats_render().
function lh_stats_compute($pdo, $incidents, $incidents_counted, $cubicles_list, $incident_no, $custom = false) {
    global $cz_days;
$total_inc = count($incidents_counted);
$st_by_cubicle = array_fill_keys(array_keys($cubicles_list), 0);
$st_hour_found = array_fill(0, 24, 0);
$st_hour_spread = array_fill(0, 24, 0.0);
$st_dow = array_fill(1, 7, 0);
$st_week = [];
$st_windows = [];
$st_found_dates = [];
$st_no_window = 0;
$st_longest = null;

foreach ($incidents_counted as $inc) {
    $end = strtotime($inc['window_end']);
    if (isset($st_by_cubicle[$inc['cubicle']])) { $st_by_cubicle[$inc['cubicle']]++; }
    $st_hour_found[(int)date('G', $end)]++;
    $st_dow[(int)date('N', $end)]++;
    $wk = date('o-\WW', $end);
    $st_week[$wk] = ($st_week[$wk] ?? 0) + 1;
    $st_found_dates[date('Y-m-d', $end)] = true;

    if (empty($inc['window_start'])) { $st_no_window++; continue; }
    $start = strtotime($inc['window_start']);
    $secs = max(60, $end - $start);
    $st_windows[] = $secs / 60;
    if ($st_longest === null || $secs > $st_longest['secs']) {
        $st_longest = ['secs' => $secs, 'cubicle' => $inc['cubicle'], 'date' => date('Y-m-d', $end), 'no' => $incident_no[(int)$inc['id']] ?? 0];
    }
    // Váha incidentu (1) se rovnoměrně rozloží přes jeho okno do hodin dne (jen okna do 24 h)
    if ($secs <= 86400) {
        for ($slot = (int)(floor($start / 3600) * 3600); $slot < $end; $slot += 3600) {
            $overlap = min($end, $slot + 3600) - max($start, $slot);
            if ($overlap > 0) { $st_hour_spread[(int)date('G', $slot)] += $overlap / $secs; }
        }
    }
}

// Posledních 12 týdnů (poslední sloupec je aktuální týden)
$st_weeks_labels = [];
$st_weeks_values = [];
for ($i = 11; $i >= 0; $i--) {
    $ts = strtotime("-$i week");
    $st_weeks_labels[] = 'T' . date('W', $ts);
    $st_weeks_values[] = $st_week[date('o-\WW', $ts)] ?? 0;
}

// Délka okna incidentu
$bucket_defs = ['< 30 min' => 30, '30–60 min' => 60, '1–2 h' => 120, '2–4 h' => 240, '4–8 h' => 480, '> 8 h' => PHP_INT_MAX];
$st_len_buckets = array_fill_keys(array_keys($bucket_defs), 0);
foreach ($st_windows as $m) {
    foreach ($bucket_defs as $bucket_label => $limit) {
        if ($m < $limit) { $st_len_buckets[$bucket_label]++; break; }
    }
}
$st_median = null;
if (!empty($st_windows)) {
    sort($st_windows);
    $n_w = count($st_windows);
    $st_median = ($n_w % 2) ? $st_windows[intdiv($n_w, 2)] : ($st_windows[$n_w / 2 - 1] + $st_windows[$n_w / 2]) / 2;
}

// Nejčastější kabinka
$tmp_c = $st_by_cubicle;
arsort($tmp_c);
$st_top_cubicle = $total_inc > 0 ? key($tmp_c) : null;
$st_top_cubicle_n = $total_inc > 0 ? current($tmp_c) : 0;

// Textová pozorování (neobvyklé vzorce)
$insights = [];
if ($total_inc < 3) {
    $insights[] = 'Zatím je příliš málo incidentů na spolehlivé vzorce.';
} else {
    $insights[] = ($cubicles_list[$st_top_cubicle] ?? $st_top_cubicle) . ': ' . $st_top_cubicle_n . ' z ' . $total_inc . ' incidentů (' . round($st_top_cubicle_n / $total_inc * 100) . ' %).';

    if (max($st_hour_found) >= 2) {
        $ph = array_search(max($st_hour_found), $st_hour_found);
        $insights[] = sprintf('Nejčastěji se incident nachází mezi %02d:00 a %02d:59 (%d z %d).', $ph, $ph, max($st_hour_found), $total_inc);
    }
    if (max($st_dow) >= 2) {
        $pd = array_search(max($st_dow), $st_dow);
        $insights[] = 'Nejčastější den nálezu: ' . $cz_days[$pd] . ' (' . max($st_dow) . ' z ' . $total_inc . ').';
    }
    if ($st_longest !== null) {
        $insights[] = 'Nejdelší okno: ' . format_minutes($st_longest['secs'] / 60) . ' (#' . $st_longest['no'] . ', ' . ($cubicles_list[$st_longest['cubicle']] ?? $st_longest['cubicle']) . ', ' . lh_d($st_longest['date']) . '). Dlouhá okna snižují vypovídací hodnotu žebříčku.';
    }

    // Nejdelší série pracovních dnů po sobě s incidentem
    $dates_sorted = array_keys($st_found_dates);
    sort($dates_sorted);
    $best_streak = ['len' => 0, 'from' => null, 'to' => null];
    $cur_streak = ['len' => 0, 'from' => null];
    $prev_ts = null;
    foreach ($dates_sorted as $d) {
        if ($prev_ts !== null && date('Y-m-d', next_workday_ts($prev_ts)) === $d) {
            $cur_streak['len']++;
        } else {
            $cur_streak = ['len' => 1, 'from' => $d];
        }
        if ($cur_streak['len'] > $best_streak['len']) {
            $best_streak = ['len' => $cur_streak['len'], 'from' => $cur_streak['from'], 'to' => $d];
        }
        $prev_ts = strtotime($d);
    }
    if ($best_streak['len'] >= 2) {
        $insights[] = 'Nejdelší série: ' . $best_streak['len'] . ($best_streak['len'] <= 4 ? ' pracovní dny' : ' pracovních dnů') . ' po sobě s incidentem (' . lh_d($best_streak['from']) . ' až ' . lh_d($best_streak['to']) . ').';
    }

    // Aktuální týden výrazně nad průměrem předchozích týdnů
    $prev_weeks = array_slice($st_weeks_values, 0, 11);
    $cur_week = end($st_weeks_values);
    $prev_avg = array_sum($prev_weeks) / 11;
    if ($cur_week >= 3 && count(array_filter($prev_weeks)) >= 3 && $cur_week > 2 * $prev_avg) {
        $insights[] = 'Tento týden: ' . $cur_week . ' incidentů, víc než dvojnásobek průměru předchozích týdnů (' . number_format($prev_avg, 1, ',', '') . ').';
    }
}
if (count($incidents) > count($incidents_counted)) {
    $insights[] = $custom
        ? 'Incidentů mimo tvůj výběr: ' . (count($incidents) - count($incidents_counted)) . ' (nejsou v těchto statistikách ani předpovědi).'
        : 'Incidentů vyřazených adminem z počítání: ' . (count($incidents) - count($incidents_counted)) . ' (nejsou ve statistikách, předpovědi ani žebříčku, zůstávají vidět v přehledu oken).';
}
if ($st_no_window > 0) {
    $insights[] = 'Incidentů bez určeného okna: ' . $st_no_window . ' (první hlášení kabinky bylo ZESRANÁ, nelze je použít pro analýzu podezřelých).';
}

// ==========================================
// 3c. PŘEDPOVĚĎ DALŠÍHO INCIDENTU
// ==========================================
$now_ts = time();
$pred_first_raw = $pdo->query("SELECT MIN(reported_at) FROM toilet_reports")->fetchColumn();
$pred_first_ts = $pred_first_raw ? strtotime($pred_first_raw) : null;
$pred_days_of_data = $pred_first_ts ? (int)floor(($now_ts - $pred_first_ts) / 86400) : 0;

// Použitelná okna: určený začátek, délka do 24 h, překryv s pracovní dobou (max. 150 posledních)
$pred_wins = [];
foreach ($incidents_counted as $inc) {
    if (empty($inc['window_start'])) { continue; }
    $s_ts = strtotime($inc['window_start']);
    $e_ts = strtotime($inc['window_end']);
    if ($e_ts <= $s_ts || $e_ts - $s_ts > 86400) { continue; }
    $cells = pred_window_cells($s_ts, $e_ts);
    if (empty($cells)) { continue; }
    $pred_wins[] = ['start' => $s_ts, 'end' => $e_ts, 'cells' => $cells];
    if (count($pred_wins) >= 150) { break; }
}
$pred_wins = array_reverse($pred_wins); // chronologicky

$pred_min_incidents = 8;
$pred_min_days = 21;
$pred_ready = count($pred_wins) >= $pred_min_incidents && $pred_days_of_data >= $pred_min_days;

if ($pred_ready) {
    $pred_model = pred_build_model($pred_wins, $now_ts);

    // Rychlost incidentů na pracovní den: Gamma-Poisson s váhou podle stáří (poločas 6 týdnů)
    $n_w = 0.0;
    foreach ($incidents_counted as $inc) {
        $age_w = max(0, ($now_ts - strtotime($inc['window_end'])) / (7 * 86400));
        $n_w += pow(0.5, $age_w / 6);
    }
    $N_w = 0.0;
    for ($d = strtotime(date('Y-m-d', $pred_first_ts)); $d <= $now_ts; $d = strtotime('+1 day', $d)) {
        if ((int)date('N', $d) <= 5) {
            $N_w += pow(0.5, max(0, ($now_ts - $d) / (7 * 86400)) / 6);
        }
    }
    $g_k = $n_w + 0.5;      // slabý apriorní odhad: ~0,25 incidentu na pracovní den
    $g_r = $N_w + 2.0;
    $lam = $g_k / $g_r;
    $lam_lo = pred_gamma_quantile($g_k, $g_r, -1.2816);   // 80% interval
    $lam_hi = pred_gamma_quantile($g_k, $g_r, 1.2816);

    // Příštích 5 pracovních dnů (dnes jen pokud ještě zbývá pracovní doba)
    $pred_days = [];
    for ($d = strtotime('today'); count($pred_days) < 5; $d = strtotime('+1 day', $d)) {
        if ((int)date('N', $d) > 5) { continue; }
        if ($d == strtotime('today') && $now_ts >= $d + PRED_WH_END * 3600) { continue; }
        $pred_days[] = $d;
    }

    $pred_slots = [];   // [expected, day_index, hour_index]
    $pred_grid = [];    // [day_index][hour_index] = očekávaný počet incidentů
    $pred_day_exp = [];
    foreach ($pred_days as $di => $d) {
        $dow = (int)date('N', $d);
        $sum_day = 0.0;
        for ($h = 0; $h < PRED_WH_END - PRED_WH_START; $h++) {
            $h_start = $d + (PRED_WH_START + $h) * 3600;
            $h_end = $h_start + 3600;
            if ($h_end <= $now_ts) { $frac = 0.0; }
            elseif ($h_start >= $now_ts) { $frac = 1.0; }
            else { $frac = ($h_end - $now_ts) / 3600; }
            $exp = $lam * 5 * $pred_model['dow'][$dow] * $pred_model['hour'][$h] * $frac;
            $pred_grid[$di][$h] = $exp;
            $sum_day += $exp;
            if ($exp > 0) { $pred_slots[] = [$exp, $di, $h]; }
        }
        $pred_day_exp[$di] = $sum_day;
    }
    usort($pred_slots, function($a, $b) { return $b[0] <=> $a[0]; });
    $pred_top = array_slice($pred_slots, 0, 3);
    $pred_grid_max = 0.0;
    foreach ($pred_grid as $row) { $pred_grid_max = max($pred_grid_max, max($row)); }

    // Zpětné ověření: každý z posledních incidentů se předpoví jen z incidentů před ním
    // Zásah = jedna ze 3 nejpravděpodobnějších hodin leží v okně incidentu. Porovnání s náhodným tipem 3 hodin.
    $bt_total = 0;
    $bt_hits = 0;
    $bt_base = 0.0;
    $pred_n = count($pred_wins);
    $hours_n = PRED_WH_END - PRED_WH_START;
    $comb3 = function($n) { return $n >= 3 ? $n * ($n - 1) * ($n - 2) / 6 : 0; };
    for ($i = max(5, $pred_n - 25); $i < $pred_n; $i++) {
        $m_i = pred_build_model(array_slice($pred_wins, 0, $i), $pred_wins[$i]['end']);
        arsort($m_i['hour']);
        $top3 = array_slice(array_keys($m_i['hour']), 0, 3);
        $covered = [];
        foreach ($pred_wins[$i]['cells'] as $c) { $covered[intdiv($c[0], 4)] = true; }
        $hit = false;
        foreach ($top3 as $h) { if (isset($covered[$h])) { $hit = true; } }
        $bt_total++;
        if ($hit) { $bt_hits++; }
        $bt_base += 1 - $comb3($hours_n - count($covered)) / $comb3($hours_n);
    }
    $pred_reliability = $pred_n < 15 ? 'nízká' : ($pred_n < 40 ? 'střední' : 'vyšší');
}
    // Milníky a porovnání před/po (jen informace; počítané incidenty podle výběru)
    $ms_rows = [];
    try { $ms_rows = $pdo->query("SELECT id, kind, person_name, date_from, date_to, title, note FROM milestones ORDER BY date_from DESC, id DESC")->fetchAll(); } catch (PDOException $e) { /* tabulka ještě neexistuje */ }
    $ms_cmp = $ms_rows ? lh_ms_compare($pdo, $ms_rows, $incidents_counted) : [];
    return get_defined_vars();
}

// Vykreslení záložky Statistiky (použije se pro výchozí stav i pro výběr uživatele)
function lh_stats_render($v) {
    extract($v);
    ob_start();
    ?>
            <div class="card p-4">
                <h3 class="card-title h5 mb-3"><?= lh_icon('chart', 20) ?> Statistiky incidentů</h3>
                <?php if (!empty($custom)): ?>
                    <div class="alert alert-info small py-2">Statistiky a předpověď jsou počítány z tvého výběru: <?= count($incidents_counted) ?> z <?= count($incidents) ?> incidentů („Zahrnout do výpočtu“ u časových oken; jen v tvém prohlížeči).</div>
                <?php endif; ?>
                <?php if ($total_inc === 0): ?>
                    <p class="text-body-secondary mb-0">Zatím nejsou žádné incidenty.</p>
                <?php else: ?>
                    <div class="row g-3 mb-4">
                        <div class="col-6 col-md-3">
                            <div class="lh-box text-center h-100">
                                <div class="fs-3 fw-bold"><?= $total_inc ?></div>
                                <div class="small text-body-secondary">incidentů celkem</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="lh-box text-center h-100">
                                <div class="fs-3 fw-bold"><?= round($st_top_cubicle_n / $total_inc * 100) ?> %</div>
                                <div class="small text-body-secondary"><?= htmlspecialchars($cubicles_list[$st_top_cubicle] ?? $st_top_cubicle) ?></div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="lh-box text-center h-100">
                                <div class="fs-3 fw-bold"><?= $st_median !== null ? htmlspecialchars(format_minutes($st_median)) : '–' ?></div>
                                <div class="small text-body-secondary">medián délky okna</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="lh-box text-center h-100">
                                <div class="fs-3 fw-bold"><?= (int)end($st_weeks_values) ?></div>
                                <div class="small text-body-secondary">incidentů tento týden</div>
                            </div>
                        </div>
                    </div>

                    <div class="lh-box mb-4">
                        <h4 class="h6 mb-2"><?= lh_icon('trend', 18) ?> Předpověď dalšího incidentu</h4>
                        <?php if (!$pred_ready): ?>
                            <p class="small text-body-secondary mb-0">
                                Na předpověď zatím není dost dat: potřeba aspoň <?= $pred_min_incidents ?> incidentů s určeným oknem (zatím <?= count($pred_wins) ?>)
                                a <?= $pred_min_days ?> dní záznamů (zatím <?= $pred_days_of_data ?>). Model počítá s pracovní dobou <?= PRED_WH_START ?>:00 až <?= PRED_WH_END ?>:00.
                            </p>
                        <?php else: ?>
                            <?php $best = $pred_top[0] ?? null; ?>
                            <?php if ($best): ?>
                                <p class="mb-2">
                                    Nejpravděpodobnější slot v nejbližších pracovních dnech:
                                    <strong><?= htmlspecialchars(pred_day_label($pred_days[$best[1]])) ?>, <?= sprintf('%02d:00–%02d:00', PRED_WH_START + $best[2], PRED_WH_START + $best[2] + 1) ?></strong>
                                    (≈ <?= round((1 - exp(-$best[0])) * 100) ?> % šance na incident v této hodině).
                                </p>
                            <?php endif; ?>

                            <div class="small mb-2">
                                <?php foreach ($pred_days as $di => $d): ?>
                                    <?php
                                        $e_day = $pred_day_exp[$di];
                                        $p_day = 1 - exp(-$e_day);
                                        $p_lo = 1 - exp(-$e_day * ($lam_lo / $lam));
                                        $p_hi = 1 - exp(-$e_day * ($lam_hi / $lam));
                                        $is_today = ($d == strtotime('today'));
                                    ?>
                                    <div>
                                        <strong><?= $is_today ? 'Dnes (zbytek dne)' : htmlspecialchars(pred_day_label($d)) ?>:</strong>
                                        ≈ <?= round($p_day * 100) ?> % šance na incident
                                        <span class="text-body-secondary">(rozmezí <?= round($p_lo * 100) ?>–<?= round($p_hi * 100) ?> %)</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if (!empty($pred_top)): ?>
                                <div class="small mb-3">
                                    <strong>Nejpravděpodobnější hodiny:</strong>
                                    <ol class="mb-0">
                                        <?php foreach ($pred_top as $t): ?>
                                            <li><?= htmlspecialchars(pred_day_label($pred_days[$t[1]])) ?>, <?= sprintf('%02d:00–%02d:00', PRED_WH_START + $t[2], PRED_WH_START + $t[2] + 1) ?> (≈ <?= round((1 - exp(-$t[0])) * 100) ?> %)</li>
                                        <?php endforeach; ?>
                                    </ol>
                                </div>
                            <?php endif; ?>

                            <div class="mb-3" style="overflow-x: auto;">
                                <div class="d-grid" style="grid-template-columns: 62px repeat(<?= PRED_WH_END - PRED_WH_START ?>, minmax(12px, 1fr)); gap: 2px; min-width: 300px;">
                                    <div></div>
                                    <?php for ($h = 0; $h < PRED_WH_END - PRED_WH_START; $h++): ?>
                                        <div class="text-center text-body-secondary" style="font-size: .6rem;"><?= ($h % 3 === 0) ? sprintf('%02d', PRED_WH_START + $h) : '' ?></div>
                                    <?php endfor; ?>
                                    <?php foreach ($pred_days as $di => $d): ?>
                                        <div class="small text-body-secondary text-nowrap" style="font-size: .7rem;"><?= htmlspecialchars(pred_day_label($d)) ?></div>
                                        <?php for ($h = 0; $h < PRED_WH_END - PRED_WH_START; $h++): ?>
                                            <?php
                                                $cell_exp = $pred_grid[$di][$h];
                                                $alpha = ($pred_grid_max > 0 && $cell_exp > 0) ? 0.12 + 0.88 * ($cell_exp / $pred_grid_max) : 0;
                                            ?>
                                            <div style="height: 18px; border-radius: 2px; background: linear-gradient(rgba(220,53,69,<?= round($alpha, 2) ?>), rgba(220,53,69,<?= round($alpha, 2) ?>)), var(--bs-tertiary-bg);"
                                                 title="<?= htmlspecialchars(pred_day_label($d)) ?>, <?= sprintf('%02d:00', PRED_WH_START + $h) ?>: ≈ <?= round((1 - exp(-$cell_exp)) * 100, 1) ?> %"></div>
                                        <?php endfor; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <p class="small text-body-secondary mb-1">
                                <span class="badge bg-secondary">Spolehlivost: <?= $pred_reliability ?></span>
                                Model vychází z <?= count($pred_wins) ?> incidentů s určeným oknem.
                                <?php if ($bt_total > 0): ?>
                                    Zpětné ověření (posledních <?= $bt_total ?> incidentů, vždy jen z předchozích): ve třech nejpravděpodobnějších hodinách bylo okno <?= $bt_hits ?>×,
                                    náhodný tip tří hodin by v průměru trefil <?= number_format($bt_base, 1, ',', '') ?>×.
                                <?php endif; ?>
                            </p>
                            <p class="small text-body-secondary mb-0">
                                Jde o statistický odhad z minulých incidentů (pracovní doba <?= PRED_WH_START ?>:00 až <?= PRED_WH_END ?>:00, jen Po až Pá), nikoli o jistotu.
                                Nezohledňuje svátky, dovolené ani změny provozu. Rozmezí v procentech vyjadřuje jen nejistotu v četnosti incidentů.
                            </p>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($ms_rows)): ?>
                    <div class="lh-box mb-4">
                        <h4 class="h6 mb-2"><?= lh_icon('flag', 18) ?> Milníky</h4>
                        <ul class="mb-2 small">
                            <?php foreach ($ms_rows as $m_r): ?>
                                <?php $c_r = $ms_cmp[(int)$m_r['id']] ?? null; $n_note = lh_ms_note($m_r, $pdo); ?>
                                <li>
                                    <strong><?= htmlspecialchars(date('d-m-Y', strtotime($m_r['date_from']))) ?></strong> · <?= htmlspecialchars(lh_ms_text($m_r, $pdo)) ?><?= $n_note !== '' ? ' (' . htmlspecialchars($n_note) . ')' : '' ?>
                                    <?php if ($c_r && $c_r['p'] !== null): ?>
                                        <div class="text-body-secondary">
                                            <?= $m_r['kind'] === 'away' ? 'mimo období' : 'před' ?>: <?= (int)$c_r['nb'] ?> incidentů za <?= (int)$c_r['db'] ?> dní s kontrolou kabinek (<?= number_format($c_r['nb'] / max(1, $c_r['db']), 2, ',', '') ?> za den) ·
                                            <?= $m_r['kind'] === 'away' ? 'v období' : 'po' ?>: <?= (int)$c_r['na'] ?> za <?= (int)$c_r['da'] ?> dní (<?= number_format($c_r['na'] / max(1, $c_r['da']), 2, ',', '') ?> za den) ·
                                            <?= htmlspecialchars($c_r['dir']) ?><?php if ($c_r['dir'] !== 'beze změny'): ?>, pravděpodobnost, že by takový rozdíl vznikl náhodou: ≈ <?= $c_r['p'] < 0.01 ? '< 1' : round($c_r['p'] * 100) ?> %<?php endif; ?>
                                        </div>
                                    <?php elseif ($c_r && in_array($m_r['kind'], ['left', 'joined', 'away', 'change', 'other'], true)): ?>
                                        <div class="text-body-secondary">není s čím porovnat (<?= (int)$c_r['da'] === 0 ? 'po milníku zatím není den s kontrolou kabinek' : ((int)$c_r['db'] === 0 ? 'před milníkem není den s kontrolou kabinek' : 'žádný vybraný incident') ?>)</div>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="small text-body-secondary">Porovnání bere jen dny, kdy se kontrolovaly kabinky, a vybrané (počítané) incidenty. Je to orientační údaj: dny po změně nemusí být srovnatelné a čím víc milníků, tím snáz vznikne nějaký rozdíl náhodou.</div>
                    </div>
                    <?php endif; ?>
                    <div class="lh-box mb-4">
                        <h4 class="h6 mb-2"><?= lh_icon('search', 18) ?> Pozorování</h4>
                        <ul class="mb-0 small">
                            <?php foreach ($insights as $ins): ?>
                                <li><?= htmlspecialchars($ins) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <?php
                        $cub_palette = ['leva' => 'var(--lh-accent)', 'prava' => 'var(--lh-bad)', 'invalide' => 'var(--lh-text-3)'];
                        $cub_labels = [];
                        $cub_colors = [];
                        foreach ($cubicles_list as $c_key => $c_name) {
                            $cub_labels[] = $c_name;
                            $cub_colors[] = $cub_palette[$c_key] ?? 'var(--lh-text-3)';
                        }
                        $hour_labels = array_map(function($h) { return sprintf('%02d', $h); }, range(0, 23));
                    ?>
                    <div class="row g-4">
                        <div class="col-md-6">
                            <h4 class="h6">Incidenty podle kabinky</h4>
                            <?= render_bar_chart($cub_labels, array_values($st_by_cubicle), $cub_colors) ?>
                        </div>
                        <div class="col-md-6">
                            <h4 class="h6">Posledních 12 týdnů</h4>
                            <?= render_bar_chart($st_weeks_labels, $st_weeks_values, 'var(--lh-warn)') ?>
                        </div>
                        <div class="col-md-6">
                            <h4 class="h6">Hodina nálezu</h4>
                            <?= render_bar_chart($hour_labels, $st_hour_found, 'var(--lh-accent)', 0, 3) ?>
                        </div>
                        <div class="col-md-6">
                            <h4 class="h6">Pravděpodobné hodiny vzniku</h4>
                            <?= render_bar_chart($hour_labels, array_values($st_hour_spread), 'var(--lh-bad)', 1, 3) ?>
                        </div>
                        <div class="col-md-6">
                            <h4 class="h6">Dny v týdnu (den nálezu)</h4>
                            <?= render_bar_chart(['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'], array_values($st_dow), 'var(--lh-ok)') ?>
                        </div>
                        <div class="col-md-6">
                            <h4 class="h6">Délka okna incidentu</h4>
                            <?= render_bar_chart(array_keys($st_len_buckets), array_values($st_len_buckets), 'var(--lh-text-3)') ?>
                        </div>
                    </div>

                    <p class="small text-body-secondary mt-4 mb-0">
                        Hodina nálezu je přesný čas hlášení ZESRANÁ. Čas vzniku neznáme, jen okno mezi posledním OK a nálezem,
                        proto graf „Pravděpodobné hodiny vzniku“ rozkládá každý incident rovnoměrně přes jeho okno (jen okna do 24 h).
                        Statistiky jsou souhrnné a neobsahují jména osob.
                    </p>
                <?php endif; ?>
            </div>
    <?php
    return ob_get_clean();
}

$stats_vars = lh_stats_compute($pdo, $incidents, $incidents_counted, $cubicles_list, $incident_no, false);

// Nastavení: výběr incidentů pro výpočet podezřelých (plošně / jen admin). Bez tabulky app_settings je funkce vypnutá.
$settings_ok = true;
$app_settings = [];
try {
    foreach ($pdo->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll() as $st_row) {
        $app_settings[$st_row['setting_key']] = $st_row['setting_value'];
    }
} catch (PDOException $e) {
    $settings_ok = false;
}
$sel_global = ($app_settings['incident_select_global'] ?? '0') === '1';
$sel_admin = ($app_settings['incident_select_admin'] ?? '0') === '1';
$sel_enabled = $sel_global || ($sel_admin && $is_admin);
// Data vyřazených incidentů se posílají jen tomu, kdo má výběr zapnutý
$incidents_for_analysis = $sel_enabled ? $incidents : $incidents_counted;

// Statistiky a předpověď podle výběru incidentů uživatele (stejná pravidla jako výběr pro podezřelé: jen kdo má výběr zapnutý)
if (($_GET['ajax'] ?? '') === 'stats') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    if ($ro_no['stats']) { http_response_code(403); exit('Statistiky nejsou v této verzi k dispozici.'); }
    if (!$sel_enabled) { http_response_code(403); exit('Výběr incidentů není zapnutý.'); }
    $ids_req = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))))));
    if (count($ids_req) > 500) { http_response_code(400); exit('Příliš mnoho incidentů.'); }
    $sel_inc = array_values(array_filter($incidents, function ($i_s) use ($ids_req) { return in_array((int)$i_s['id'], $ids_req, true); }));
    echo lh_stats_render(lh_stats_compute($pdo, $incidents, $sel_inc, $cubicles_list, $incident_no, true));
    exit;
}

// Zařízení použité pro admin přístup se zapamatuje, aby šlo pojmenovat i bez podaného hlášení
$device_seen_ok = true;
if ($is_admin && !empty($device_id)) {
    try {
        $st = $pdo->prepare("INSERT INTO device_seen (device_id, first_seen, last_seen, last_ip) VALUES (?, NOW(), NOW(), ?) ON DUPLICATE KEY UPDATE last_seen = NOW(), last_ip = VALUES(last_ip)");
        $st->execute([$device_id, $client_ip ?: null]);
    } catch (PDOException $e) {
        $device_seen_ok = false;   // tabulka ještě neexistuje (zarizeni.sql)
    }
}

// Sloupec pro poznámku (poznamky.sql): bez něj se pole poznámky nezobrazuje a nic se neukládá
$note_cols = ['reports' => false, 'sightings' => false];
foreach (['reports' => 'toilet_reports', 'sightings' => 'sightings'] as $nk => $ntb) {
    try { $note_cols[$nk] = (bool)$pdo->query("SHOW COLUMNS FROM $ntb LIKE 'note'")->fetch(); } catch (PDOException $e) {}
}

$suspects = [];

// Tolerance po vzniku incidentu: průchody turniketem do X minut po nahlášení se také počítají
// (viník se mohl vracet do kanceláře až po nahlášení)
$tolerance_minutes = 5;   // výchozí; admin ji mění v panelu Tolerance (nastavení tolerance_minutes, 0 až 60)
if (isset($app_settings['tolerance_minutes']) && ctype_digit((string)$app_settings['tolerance_minutes'])) {
    $tolerance_minutes = min(60, (int)$app_settings['tolerance_minutes']);
}

// Zdroje bodů podle turniketu: nahoře = 4. patro (vlevo, vpravo a IT dveře), dole = 1. patro
$top_turnstiles = ['vlevo', 'vpravo', 'it_dvere'];
$bottom_turnstiles = ['patro_1'];

$blacklisted = $pdo->query("SELECT person_name FROM blacklisted_suspects ORDER BY person_name ASC")->fetchAll(PDO::FETCH_COLUMN);
$blacklisted_lookup = array_flip($blacklisted);

// IT oddělení: nechodí přes turniket, takže jsou podezřelí v každém incidentu,
// pokud nebyli na dovolené po celou dobu časového okna incidentu
$it_tables_ok = true;
$it_staff = [];
$it_vacation_rows = [];
$it_vacations = [];
$it_has_until = true;
try {
    try {
        $it_staff = $pdo->query("SELECT id, person_name, virtual_until FROM it_staff ORDER BY person_name ASC")->fetchAll();
    } catch (PDOException $e) {
        $it_has_until = false;   // sloupec virtual_until ještě neexistuje (it_pikacka.sql)
        $it_staff = $pdo->query("SELECT id, person_name FROM it_staff ORDER BY person_name ASC")->fetchAll();
    }
    $it_vacation_rows = $pdo->query("SELECT id, staff_id, date_from, date_to FROM it_vacations ORDER BY date_from DESC, id DESC")->fetchAll();
    foreach ($it_vacation_rows as $v) {
        $it_vacations[$v['staff_id']][] = $v;
    }
} catch (PDOException $e) {
    $it_tables_ok = false;
}

// Jméno z čtečky, které odpovídá členu IT (bez ohledu na velikost písmen a mezery), se sloučí s jeho jménem v panelu IT
$it_key_to_name = [];
foreach ($it_staff as $it_row) { $it_key_to_name[wit_key($it_row['person_name'])] = $it_row['person_name']; }

function it_on_vacation_whole_window($vacations, $window_start, $window_end) {
    $day = strtotime(substr($window_start, 0, 10));
    $last = strtotime(substr($window_end, 0, 10));
    for (; $day <= $last; $day = strtotime('+1 day', $day)) {
        $d = date('Y-m-d', $day);
        $covered = false;
        foreach ($vacations as $v) {
            if ($v['date_from'] <= $d && $d <= $v['date_to']) { $covered = true; break; }
        }
        if (!$covered) return false;
    }
    return true;
}

// Svědectví: do analýzy se berou jen schválená pozorování
$wit_tables_ok = true;
$wit_rows = [];
try {
    $wit_rows = $pdo->query("SELECT id, person_name, location, direction, seen_at, reporter, name_known FROM sightings WHERE status = 'approved' ORDER BY seen_at ASC")->fetchAll();
} catch (PDOException $e) {
    $wit_tables_ok = false;
}
$incident_direct = []; // incidenty s přesným svědectvím (id incidentu => true)

// Jednotné jméno pro stejnou osobu v různých pozorováních (jinak by se rozdělila na více podezřelých):
// přednost má jméno ověřené proti docházce nebo IT, jinak první zadané
$wit_display = [];
foreach ($wit_rows as $w) {
    if (!empty($w['name_known'])) {
        $k = wit_key($w['person_name']);
        if (!isset($wit_display[$k])) { $wit_display[$k] = $w['person_name']; }
    }
}
foreach ($wit_rows as $w) {
    $k = wit_key($w['person_name']);
    if (!isset($wit_display[$k])) { $wit_display[$k] = $w['person_name']; }
}

$stmt_p = $pdo->prepare("
    SELECT person_name, direction, turnstile, passed_at 
    FROM turnstile_logs 
    WHERE passed_at BETWEEN ? AND ?
    ORDER BY passed_at ASC
");

// Průchody během dnů okna incidentu (od půlnoci dne začátku do konce dne nálezu): pro informaci „Během dne ve firmě“
$stmt_day = $pdo->prepare("
    SELECT person_name, direction, turnstile, passed_at
    FROM turnstile_logs
    WHERE passed_at >= ? AND passed_at < ?
    ORDER BY passed_at ASC
");
$has_day_entries = false;   // existuje aspoň jedna položka „Během dne ve firmě“ (zobrazí se filtr)
$incident_people = []; // podezřelí rozdělení podle incidentu (id incidentu => seznam osob)
$blacklisted_with_points = []; // vyřazené osoby, které by v žebříčku měly aspoň jeden bod (veřejný seznam)
$incident_wit_extra = []; // pozorování v okně incidentu, za která nikdo nedostal bod (id incidentu => osoby a jejich pozorování)
$incident_wit_count = []; // počet viditelných pozorování v okně incidentu
$blacklisted_keys = [];
foreach ($blacklisted as $bl_name) { $blacklisted_keys[wit_key($bl_name)] = true; }

foreach ($incidents_for_analysis as $inc) {
    if (!$inc['window_start']) continue;
    $incident_people[$inc['id']] = [];

    $search_end = date('Y-m-d H:i:s', strtotime($inc['window_end']) + $tolerance_minutes * 60);   // konec okna pro průchody turniketem (s tolerancí)
    $stmt_p->execute([$inc['window_start'], $search_end]);
    $passes = $stmt_p->fetchAll();

    $persons_in_incident = [];
    foreach ($passes as $p) {
        $p_name = $it_key_to_name[wit_key($p['person_name'])] ?? $p['person_name'];
        if (!isset($persons_in_incident[$p_name])) {
            $persons_in_incident[$p_name] = [];
        }
        $persons_in_incident[$p_name][] = $p;
    }

    // IT bez turniketu (prázdný seznam průchodů), pokud už nejsou zachyceni z turniketu
    $virtual_names = [];
    foreach ($it_staff as $it) {
        if (isset($persons_in_incident[$it['person_name']])) continue;
        // Od data předělu mají dveře IT čtečku (skutečné průchody), virtuální bod se u novějších incidentů nepřiděluje
        if (!empty($it['virtual_until']) && $inc['window_start'] >= $it['virtual_until'] . ' 00:00:00') continue;
        if (it_on_vacation_whole_window($it_vacations[$it['id']] ?? [], $inc['window_start'], $search_end)) continue;
        $persons_in_incident[$it['person_name']] = [];
        $virtual_names[$it['person_name']] = true;
    }

    // Svědectví v okně tohoto incidentu: váha = váha umístění × váha činnosti
    // Každé schválené pozorování v okně se zobrazí (i s nulovou váhou); bod dá jen to, co splní podmínky níže
    $wit_groups = [];
    $wit_all = [];
    foreach ($wit_rows as $w) {
        if ($w['seen_at'] < $inc['window_start'] || $w['seen_at'] > $inc['window_end']) continue;   // pozorování jen přímo v okně, tolerance se na ně nevztahuje
        $lw = wit_loc_weight($w['location'], $inc['cubicle'], $wit_p_cubicle);
        $dw = $wit_dir_weights[$w['direction']] ?? 0.0;
        $k = wit_key($w['person_name']);
        if (!isset($wit_all[$k])) { $wit_all[$k] = ['name' => $wit_display[$k] ?? $w['person_name'], 'items' => []]; }
        $wit_all[$k]['items'][] = ['row' => $w, 'lw' => $lw, 'dw' => $dw];
        if ($lw <= 0 || $dw <= 0) continue;
        if (!isset($wit_groups[$k])) { $wit_groups[$k] = ['name' => $wit_display[$k] ?? $w['person_name'], 'items' => []]; }
        // Viděn vycházet z toalet nebo do nich vcházet (kabinka nebo nevím): osoba tam prokazatelně byla, bod dostane vždy
        $toilet_presence = in_array($w['location'], ['toalety_kabinka', 'toalety_nevim'], true) && in_array($w['direction'], ['vychazel', 'vchazel'], true);
        $wit_groups[$k]['items'][] = ['row' => $w, 'weight' => $lw * $dw, 'exact' => ($lw >= 1.0 && $dw >= 0.5), 'toilet_presence' => $toilet_presence];
    }
    // Přesné svědectví: přesná kabinka a osoba vycházela nebo vcházela. Jinak se pravděpodobnosti více pozorování spojí.
    foreach ($wit_groups as $k => &$g) {
        $g['exact'] = false;
        $presence = false;
        $none = 1.0;
        foreach ($g['items'] as $it_w) {
            if ($it_w['exact']) { $g['exact'] = true; }
            if ($it_w['toilet_presence']) { $presence = true; }
            $none *= (1 - $it_w['weight']);
        }
        $g['prob'] = 1 - $none;
        $g['vague'] = !$g['exact'] && ($presence || $g['prob'] >= $wit_threshold);
    }
    unset($g);

    // Spojení svědectví s osobami z turniketu (bez ohledu na velikost písmen); svědek mimo turniket se přidá
    $key_to_name = [];
    foreach ($persons_in_incident as $nm => $_pl) { $key_to_name[wit_key($nm)] = $nm; }
    $wit_for = [];
    foreach ($wit_groups as $k => $g) {
        $target = $key_to_name[$k] ?? $g['name'];
        if (!isset($persons_in_incident[$target])) {
            if (!$g['exact'] && !$g['vague']) continue;   // samotné nedostatečné svědectví osobu nezakládá
            $persons_in_incident[$target] = [];
        }
        $wit_for[$target] = $g;
    }

    // Během dne ve firmě: osoba nemá v okně incidentu žádný zdroj bodu, ale v aspoň jeden den okna prošla dveřmi (kdykoli mimo okno).
    // Bod za to nedostává (maska 64), jen se u incidentu zobrazí informace. U oken přes více dnů stačí jeden den.
    $day_only = [];
    $day_from = date('Y-m-d', strtotime($inc['window_start']));
    $day_to = date('Y-m-d', strtotime($inc['window_end']));
    if ($day_from <= $day_to && (strtotime($day_to) - strtotime($day_from)) <= 31 * 86400) {
        $stmt_day->execute([$day_from . ' 00:00:00', date('Y-m-d', strtotime($day_to . ' +1 day')) . ' 00:00:00']);
        $in_keys = [];
        foreach ($persons_in_incident as $nm_in => $_unused) { $in_keys[wit_key($nm_in)] = true; }
        foreach ($stmt_day->fetchAll() as $dp) {
            $dn = $it_key_to_name[wit_key($dp['person_name'])] ?? $dp['person_name'];
            if (isset($in_keys[wit_key($dn)]) || isset($blacklisted_keys[wit_key($dn)])) { continue; }   // v okně (má bod) nebo vyřazená osoba
            $day_only[$dn][] = $dp;
        }
    }

    $added_keys = [];
    foreach ($persons_in_incident as $name => $p_list) {
        $is_blacklisted = isset($blacklisted_lookup[$name]);

        // Maska zdrojů v tomto incidentu: 1 = nahoře, 2 = dole, 4 = virtuální (IT bez průchodu),
        // 8 = svědectví přesné, 16 = svědectví nepřesné
        $mask = 0;
        foreach ($p_list as $ps) {
            if (in_array($ps['turnstile'], $top_turnstiles, true)) { $mask |= 1; }
            elseif (in_array($ps['turnstile'], $bottom_turnstiles, true)) { $mask |= 2; }
        }
        if (!empty($p_list) && $mask === 0) { $mask = 1; }
        $wg = $wit_for[$name] ?? null;
        $has_witness = $wg && ($wg['exact'] || $wg['vague']);
        $is_virtual = !empty($virtual_names[$name]) && empty($p_list) && !$has_witness;
        if ($is_virtual) { $mask |= 4; }
        if ($wg && $wg['exact']) { $mask |= 8; }
        elseif ($wg && $wg['vague']) { $mask |= 16; }
        if ($mask === 0) continue;
        if ($is_blacklisted) {
            // Vyřazená osoba se do žebříčku nezapočítá, jen si zapamatujeme, že by bod měla (u počítaného incidentu)
            if (!empty($inc['counted'])) { $blacklisted_with_points[$name] = true; }
            continue;
        }
        $added_keys[wit_key($name)] = true;
        if ($mask & 8) { $incident_direct[$inc['id']] = true; }

        // Zobrazení svědectví: položky podpírající bod mají kód zdroje (8/16), ostatní 32 (jen informativně)
        $wit_items = [];
        if (isset($wit_all[wit_key($name)])) {
            $src_group = $wg ? ($wg['exact'] ? 8 : ($wg['vague'] ? 16 : 32)) : 32;
            foreach ($wit_all[wit_key($name)]['items'] as $it_w) {
                $counts = ($it_w['lw'] > 0 && $it_w['dw'] > 0);
                $wit_items[] = wit_item_view($it_w, $counts ? $src_group : 32, $wg, $wit_threshold);
            }
        }

        if (!isset($suspects[$name])) {
            $suspects[$name] = [
                'name' => $name,
                'score' => 0,
                'real_score' => 0,
                'virtual_score' => 0,
                'up_count' => 0,
                'down_count' => 0,
                'wit_exact' => 0,
                'wit_vague' => 0,
                'incident_count' => 0,
                'details' => []
            ];
        }
        $incident_people[$inc['id']][] = ['name' => $name, 'mask' => $mask, 'passes' => $p_list, 'witness' => $wit_items];

        // Bod za incident je jen jeden, bez ohledu na počet průchodů a svědectví; vyřazené incidenty se do výchozích součtů nepočítají
        if (!empty($inc['counted'])) {
            $suspects[$name]['score'] += 1;
            if (!empty($p_list)) { $suspects[$name]['real_score'] += 1; }
            if ($mask & 4) { $suspects[$name]['virtual_score'] += 1; }
            if ($mask & 1) { $suspects[$name]['up_count'] += 1; }
            if ($mask & 2) { $suspects[$name]['down_count'] += 1; }
            if ($mask & 8) { $suspects[$name]['wit_exact'] += 1; }
            if ($mask & 16) { $suspects[$name]['wit_vague'] += 1; }
            $suspects[$name]['incident_count'] += 1;
        }
        $suspects[$name]['details'][] = [
            'incident' => $inc['id'],
            'cubicle' => $inc['cubicle'],
            'window' => lh_d($inc['window_start']) . ' -> ' . lh_d($inc['window_end']) . ($tolerance_minutes > 0 ? " (průchody +{$tolerance_minutes} min tolerance)" : ''),
            'passes' => $p_list,
            'witness' => $wit_items,
            'mask' => $mask,
            'no_turnstile' => $is_virtual
        ];
    }

    foreach ($day_only as $dname => $d_list) {
        if (!isset($suspects[$dname])) {
            $suspects[$dname] = ['name' => $dname, 'score' => 0, 'real_score' => 0, 'virtual_score' => 0, 'up_count' => 0, 'down_count' => 0, 'wit_exact' => 0, 'wit_vague' => 0, 'incident_count' => 0, 'details' => []];
        }
        $has_day_entries = true;
        // nejbližší průchody kolem incidentu: poslední před začátkem okna a první po konci okna (po toleranci); $d_list je seřazený podle času
        $d_before = null; $d_after = null; $d_other = [];
        foreach ($d_list as $dpx) {
            if ($dpx['passed_at'] < $inc['window_start']) { $d_before = $dpx; }
            elseif ($dpx['passed_at'] > $search_end && $d_after === null) { $d_after = $dpx; }
        }
        foreach ($d_list as $dpx) { if ($dpx !== $d_before && $dpx !== $d_after) { $d_other[] = $dpx; } }
        $suspects[$dname]['details'][] = [
            'incident' => $inc['id'],
            'cubicle' => $inc['cubicle'],
            'window' => lh_d($inc['window_start']) . ' -> ' . lh_d($inc['window_end']) . ($tolerance_minutes > 0 ? " (průchody +{$tolerance_minutes} min tolerance)" : ''),
            'passes' => [], 'witness' => [], 'mask' => 64, 'no_turnstile' => false,
            'day_before' => $d_before, 'day_after' => $d_after, 'day_ref_start' => $inc['window_start'], 'day_ref_end' => $inc['window_end'],
            'day_passes' => array_slice($d_other, 0, 4), 'day_n' => count($d_other)
        ];
    }

    // Pozorování v okně, za která nikdo nedostal bod (pod prahem, pisoár, jiná kabinka): zůstanou viditelná
    $shown_wit = 0;
    foreach ($wit_all as $k => $ga) {
        if (isset($blacklisted_keys[$k])) continue;
        if (isset($added_keys[$k])) { $shown_wit += count($ga['items']); continue; }
        $grp = $wit_groups[$k] ?? null;
        $items_view = [];
        foreach ($ga['items'] as $it_w) {
            $items_view[] = wit_item_view($it_w, 32, $grp, $wit_threshold);
        }
        $incident_wit_extra[$inc['id']][] = ['name' => $ga['name'], 'items' => $items_view];
        $shown_wit += count($items_view);
    }
    $incident_wit_count[$inc['id']] = $shown_wit;
}

// Incidenty, u kterých je osoba označena jako nevinná: v detailu místo průchodů a svědectví zelený štítek „Nevinný“ (a důvod). Body se tím nemění.
if ($innocent_ok && !empty($incident_innocents)) {
    $inn_by_person = [];   // normalizované jméno => [id incidentu => důvod]
    foreach ($incident_innocents as $rid_i => $rows_i) {
        foreach ($rows_i as $r_i) { $inn_by_person[lh_name_norm($r_i['person_name'])][(int)$rid_i] = (string)($r_i['reason'] ?? ''); }
    }
    $inc_pos = [];
    foreach ($incidents as $pos_i => $inc_p) { $inc_pos[(int)$inc_p['id']] = $pos_i; }
    foreach ($suspects as $sk_i => $sv_i) {
        $mine = $inn_by_person[lh_name_norm($sv_i['name'])] ?? null;
        if (!$mine) { continue; }
        // Maska zdrojů původní položky zůstává (prohlížeč z ní přepočítává body), nevinnost body nemění
        $orig_mask = [];
        foreach ($sv_i['details'] as $d_i) { if (isset($mine[(int)$d_i['incident']])) { $orig_mask[(int)$d_i['incident']] = $d_i['mask']; } }
        $details_new = array_values(array_filter($sv_i['details'], function ($d_i) use ($mine) { return !isset($mine[(int)$d_i['incident']]); }));
        foreach ($incidents as $inc_d) {
            $iid_d = (int)$inc_d['id'];
            if (!isset($mine[$iid_d])) { continue; }
            $details_new[] = [
                'incident' => $inc_d['id'], 'cubicle' => $inc_d['cubicle'],
                'window' => lh_d($inc_d['window_start']) . ' -> ' . lh_d($inc_d['window_end']) . ($tolerance_minutes > 0 ? " (průchody +{$tolerance_minutes} min tolerance)" : ''),
                'passes' => [], 'witness' => [], 'mask' => $orig_mask[$iid_d] ?? 0, 'no_turnstile' => false, 'innocent' => true, 'reason' => $mine[$iid_d]
            ];
        }
        usort($details_new, function ($a, $b) use ($inc_pos) { return ($inc_pos[(int)$a['incident']] ?? 0) <=> ($inc_pos[(int)$b['incident']] ?? 0); });
        $suspects[$sk_i]['details'] = $details_new;
    }
}

usort($suspects, function($a, $b) {
    return [$b['score'], $b['real_score']] <=> [$a['score'], $a['real_score']];
});

// Štítek: jediný vedoucí je „Hlavní podezřelý“, při shodě na prvním místě mají štítek „Podezřelý“ všichni z nich
$top_score = !empty($suspects) ? $suspects[0]['score'] : 0;
$leaders_n = 0;
foreach ($suspects as $k_s => $s_l) {
    if ($top_score > 0 && $s_l['score'] === $top_score) { $suspects[$k_s]['is_main'] = true; $leaders_n++; }
}
$main_label = $leaders_n === 1 ? 'HLAVNÍ PODEZŘELÝ' : 'PODEZŘELÝ';
$digest_leaders_n = lh_digest_leaders($suspects, $incident_innocents, $incidents, $trusted_names);   // pro týdenní souhrn: bez nevinných a důvěryhodných

// Týdenní souhrn: volá domácí server (cron) s klíčem backup_token v hlavičce X-Backup-Token. Bez platného klíče se aplikace chová běžně.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'digest_send') {
    $sent_token = (string)($_SERVER['HTTP_X_BACKUP_TOKEN'] ?? '');
    if (strlen($backup_token) >= 32 && $sent_token !== '' && hash_equals($backup_token, $sent_token)) {
        header('Content-Type: text/plain; charset=utf-8');
        if (!lh_mail_configured($smtp)) { echo "CHYBA: SMTP není nastaveno\n"; exit; }
        if (lh_setting($pdo, 'culprit_name') !== '') { echo "SKIP: pachatel byl dopaden, týdenní souhrn se už neodesílá\n"; exit; }
        $last = strtotime(lh_setting($pdo, 'digest_last_sent') ?: '1970-01-01');
        if (time() - $last < 1 * 86400) { echo "SKIP: souhrn už byl odeslán " . date('j. n. Y H:i', $last) . "\n"; exit; }
        list($n_ok, $errs) = lh_digest_send($pdo, $smtp, $incidents, $cubicles_list, $app_url, 'mail_digest', true, '', $digest_leaders_n);
        echo $errs ? "CHYBA: " . implode('; ', $errs) . "\n" : "OK: odesláno $n_ok příjemcům\n";
        exit;
    }
    sleep(1);
}

// ==========================================
// 4b. PŘIHLÁŠENÍ / ODHLÁŠENÍ ADMINA
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_login') {
    if ($ip_login_ok || lh_verify_admin_password($admin_password, $_POST['admin_password'] ?? '')) {
        session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
        lh_audit($pdo, $smtp, 'Přihlášení admina', [], null, '', $client_ip, $device_id, $audit_user, 'login');
        $_SESSION['flash_message'] = 'Admin přihlášen.';
        $_SESSION['flash_type'] = 'success';
    } else {
        lh_audit_store($pdo, 'Neúspěšné přihlášení admina', 'admin_login', [], null, 'Neplatné heslo', $client_ip, $device_id, $audit_user, 'login');
        $_SESSION['flash_message'] = 'Neplatné admin heslo!';
        $_SESSION['flash_type'] = 'danger';
    }
    header('Location: index.php');
    exit;
}

// Automatické stažení zálohy (domácí server): jen s dlouhým klíčem z konfigurace v hlavičce X-Backup-Token.
// Při chybějícím nebo špatném klíči se aplikace chová jako při běžném požadavku (nic neprozradí).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup_token') {
    $sent_token = (string)($_SERVER['HTTP_X_BACKUP_TOKEN'] ?? '');
    if (strlen($backup_token) >= 32 && $sent_token !== '' && hash_equals($backup_token, $sent_token)) {
        lh_stream_backup($pdo, $db_name);
        exit;
    }
    sleep(1); // zpomalení hádání klíče
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'backup_download') {
    if (!$is_admin) {
        $_SESSION['flash_message'] = 'Pro stažení zálohy se musíš přihlásit jako admin!';
        $_SESSION['flash_type'] = 'danger';
        header('Location: index.php');
        exit;
    }
    lh_audit($pdo, $smtp, 'Stažení zálohy databáze', [], null, '', $client_ip, $device_id, $audit_user, 'backup');
    lh_stream_backup($pdo, $db_name);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_logout') {
    unset($_SESSION['is_admin']);
    $_SESSION['flash_message'] = 'Admin odhlášen.';
    $_SESSION['flash_type'] = 'success';
    header('Location: index.php');
    exit;
}

// ==========================================
// 5. IMPORT LOGŮ DOCHÁZKY (ADMIN POST)
// ==========================================
$import_warning = null;   // varování před importem (záznamy u jiného turniketu / z budoucnosti): stránka se vykreslí s úplným výpisem a volbou
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_logs') {
    $log_date = $_POST['log_date'] ?? '';
    $turnstile = $_POST['turnstile'] ?? '';
    $raw_text = trim($_POST['raw_text'] ?? '');

    if (!$is_admin) {
        $_SESSION['flash_message'] = 'Pro import se musíš přihlásit jako admin!';
        $_SESSION['flash_type'] = 'danger';
    } elseif (empty($log_date) || empty($turnstile) || empty($raw_text)) {
        $_SESSION['flash_message'] = 'Všechna pole v importním formuláři jsou povinná!';
        $_SESSION['flash_type'] = 'danger';
    } else {
        // Formát vstupu: po třech řádcích "in/out", "Jméno (login)", "HH:MM" (závorka s loginem se odstraní)
        $lines = [];
        foreach (explode("\n", str_replace("\r", "", $raw_text)) as $line) {
            $line = trim($line);
            if ($line !== '') $lines[] = $line;
        }

        $imported_count = 0;
        $rows = [];
        $invalid_lines = 0;
        $re_time = '/^\d{1,2}:\d{2}$/';
        $re_dir = '/^(in|out)$/i';
        $total = count($lines);

        for ($i = 0; $i < $total; ) {
            if ($i + 2 < $total
                && preg_match($re_dir, $lines[$i])
                && !preg_match($re_time, $lines[$i + 1]) && !preg_match($re_dir, $lines[$i + 1])
                && preg_match($re_time, $lines[$i + 2])) {

                $person = lh_alias_apply($pdo, trim(preg_replace('/\s+/u', ' ', preg_replace('/\s*\([^)]*\)/u', '', $lines[$i + 1]))));   // alias → hlavní jméno
                if ($person !== '') {
                    $rows[] = [$person, strtoupper($lines[$i]), $turnstile, $log_date . ' ' . $lines[$i + 2] . ':00'];
                } else {
                    $invalid_lines += 3;
                }
                $i += 3;
            } else {
                $invalid_lines++;
                $i++;
            }
        }

        // Kontrola před uložením: při nálezu se nic neuloží, dokud admin nezvolí „přesto“ nebo „bez problematických“
        $import_mode = (string)($_POST['import_mode'] ?? '');
        $import_chk = lh_import_check($pdo, $rows, $turnstile, $turnstiles_list);
        $import_has_issue = !empty($import_chk['cross']) || !empty($import_chk['future']);
        $import_skipped_bad = 0;
        if ($import_has_issue && !in_array($import_mode, ['all', 'skip'], true)) {
            $import_warning = ['chk' => $import_chk, 'turnstile_key' => $turnstile, 'turnstile' => $turnstiles_list[$turnstile] ?? $turnstile, 'date' => $log_date, 'raw' => $raw_text, 'total' => count($rows), 'invalid' => $invalid_lines];
            lh_audit_store($pdo, 'Import docházky zastaven (varování)', 'import_logs', ['turnstile' => $import_warning['turnstile'], 'date' => $log_date, 'u jiného turniketu' => count($import_chk['cross']), 'z budoucnosti' => count($import_chk['future'])], null, 'Čeká na rozhodnutí admina, nic nebylo uloženo.', $client_ip, $device_id, $audit_user, 'import');
        } else {
        if ($import_has_issue && $import_mode === 'skip') {
            $import_skipped_bad = count($import_chk['bad']);
            $rows = array_values(array_filter($rows, function ($k) use ($import_chk) { return !isset($import_chk['bad'][$k]); }, ARRAY_FILTER_USE_KEY));
        }
        // Dávkové vložení v jedné transakci (INSERT IGNORE přeskočí duplicity, pokud existuje UNIQUE klíč)
        try {
            $pdo->beginTransaction();
            foreach (array_chunk($rows, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?)'));
                $stmt_ins = $pdo->prepare("INSERT IGNORE INTO turnstile_logs (person_name, direction, turnstile, passed_at) VALUES $placeholders");
                $stmt_ins->execute(array_merge(...$chunk));
                $imported_count += $stmt_ins->rowCount();
            }
            $pdo->commit();

            $skipped = (count($rows) - $imported_count) + $invalid_lines;
            $turnstile_title = $turnstiles_list[$turnstile] ?? $turnstile;
            $_SESSION['flash_message'] = "Úspěšně importováno $imported_count záznamů průchodů ($turnstile_title)."
                . ($skipped > 0 ? " Přeskočeno: $skipped (duplicity nebo neplatný formát)." : '')
                . ($import_has_issue && $import_mode === 'skip' ? " Vynecháno problematických záznamů: $import_skipped_bad." : '')
                . ($import_has_issue && $import_mode === 'all' ? " Importováno i přes varování (u jiného turniketu: " . count($import_chk['cross']) . ", z budoucnosti: " . count($import_chk['future']) . ")." : '');
            $_SESSION['flash_type'] = 'success';
            $audit_imp = ['turnstile' => $turnstile_title, 'date' => $_POST['log_date'] ?? ''];
            if ($import_has_issue) { $audit_imp += ['varování' => $import_mode === 'skip' ? 'importováno bez problematických' : 'importováno přesto', 'u jiného turniketu' => count($import_chk['cross']), 'z budoucnosti' => count($import_chk['future'])]; }
            lh_audit($pdo, $smtp, 'Import docházky', $audit_imp, null, $_SESSION['flash_message'], $client_ip, $device_id, $audit_user, 'import');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash_message'] = 'Import selhal, nic nebylo uloženo.';
            $_SESSION['flash_type'] = 'danger';
        }
        }   // konec větve bez varování
    }

    if ($import_warning === null) {
        header('Location: index.php');
        exit;
    }
}

// ==========================================
// SBĚR DAT: admin může jedním přepínačem vypnout vkládání informací pro všechny kromě admina (formuláře se skryjí a POST akce se odmítnou)
// ==========================================
$collect_off = (lh_setting($pdo, 'collect_disabled') === '1');
$collect_blocked = $collect_off && !$is_admin;
// Označený pachatel (admin ho může označit až po ukončení sběru dat)
$culprit_name = lh_setting($pdo, 'culprit_name');
$culprit_at = lh_setting($pdo, 'culprit_at');
$culprit_by = lh_setting($pdo, 'culprit_by');
$culprit_mailed = lh_setting($pdo, 'culprit_mailed_at');
if ($collect_blocked && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['report', 'report_sighting', 'report_note'], true)) {
    $_SESSION['flash_message'] = 'Sběr dat je vypnutý, nová hlášení, pozorování ani poznatky se nepřijímají.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: index.php');
    exit;
}

// ==========================================
// 6. ZPRACOVÁNÍ HLÁŠENÍ STAVU KABINKY
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'report') {
    $reporter = trim($_POST['reporter'] ?? '');
    if ($auth_user !== '' && !$is_admin) { $reporter = $auth_display; }   // jméno se bere z přihlášení, admin může hlásit jménem kohokoli
    $cubicle = $_POST['cubicle'] ?? '';
    $status = $_POST['status'] ?? '';
    $reported_at = $_POST['reported_at'] ?? '';

    $report_ts = strtotime((string)$reported_at);
    if (empty($reporter) || empty($cubicle) || empty($status) || empty($reported_at)) {
        $_SESSION['flash_message'] = 'Všechna pole jsou povinná!';
        $_SESSION['flash_type'] = 'danger';
    } elseif (!isset($cubicles_list[$cubicle]) || !in_array($status, ['ok', 'zesrana'], true)
              || $report_ts === false || $report_ts < strtotime('2000-01-01') || $report_ts > time() + 86400 || mb_strlen($reporter) > 100) {
        // Kabinka a stav musí být z povolených hodnot, čas platné datum (ne víc než den do budoucna), jméno nejvýše 100 znaků
        $_SESSION['flash_message'] = 'Neplatné údaje hlášení!';
        $_SESSION['flash_type'] = 'danger';
    } else {
        $reported_at = date('Y-m-d H:i:s', $report_ts);
        // Nový incident = hlášení „zesraná“, kterému v téže kabince nepředchází „zesraná“ (zjistíme před uložením)
        $prev_status = null;
        $prev_at = null;
        if ($status === 'zesrana') {
            $stp = $pdo->prepare("SELECT status, reported_at FROM toilet_reports WHERE cubicle = ? AND reported_at <= ? ORDER BY reported_at DESC, id DESC LIMIT 1");
            $stp->execute([$cubicle, date('Y-m-d H:i:s', strtotime($reported_at))]);
            $pr = $stp->fetch();
            $prev_status = $pr['status'] ?? null;
            $prev_at = $pr['reported_at'] ?? null;
        }
        $note = lh_note($_POST['note'] ?? '');
        $with_note = $note_cols['reports'] && $note !== '';
        try {
            $stmt = $pdo->prepare("INSERT INTO toilet_reports (reporter, cubicle, status, reported_at, device_id, client_ip" . ($with_note ? ', note' : '') . ") VALUES (?, ?, ?, ?, ?, ?" . ($with_note ? ', ?' : '') . ")");
            $stmt->execute(array_merge([$reporter, $cubicle, $status, $reported_at, $device_id, $client_ip], $with_note ? [$note] : []));
        } catch (PDOException $e) {
            // Sloupce pro log ještě neexistují: hlášení se uloží bez nich (běžnému uživateli se žádná chyba neukazuje)
            $stmt = $pdo->prepare("INSERT INTO toilet_reports (reporter, cubicle, status, reported_at" . ($with_note ? ', note' : '') . ") VALUES (?, ?, ?, ?" . ($with_note ? ', ?' : '') . ")");
            $stmt->execute(array_merge([$reporter, $cubicle, $status, $reported_at], $with_note ? [$note] : []));
        }
        if ($status === 'zesrana' && $prev_status !== 'zesrana') {
            $found_ts = strtotime($reported_at);
            $win_txt = ($prev_status === 'ok' && $prev_at)
                ? 'od ' . date('H:i', strtotime($prev_at)) . ' (' . format_minutes(($found_ts - strtotime($prev_at)) / 60) . ')'
                : 'neznámé (předchozí hlášení „čisto“ chybí)';
            if (lh_notify_enabled($pdo, 'new_incident')) lh_mail_event($pdo, $smtp, 'mail_admins', '[Detektor] Nový incident: ' . ($cubicles_list[$cubicle] ?? $cubicle),
                "Nový incident\nKabinka: " . ($cubicles_list[$cubicle] ?? $cubicle) . "\nNález: " . date('j. n. Y H:i', $found_ts) . "\nČasové okno: " . $win_txt . "\n\nPodrobnosti: " . $app_url);
        }
        $_SESSION['flash_message'] = 'Stav byl úspěšně zaznamenán.';
        $_SESSION['flash_type'] = 'success';
    }

    header('Location: index.php');
    exit;
}

// ==========================================
// 6b. ODESLÁNÍ POZOROVÁNÍ OSOBY NA TOALETÁCH
// Nová pozorování čekají na schválení adminem; pozorování zadané adminem se schvaluje rovnou.
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report_sighting') {
    $sg_person = trim(preg_replace('/\s+/u', ' ', (string)($_POST['person_name'] ?? '')));
    $sg_location = (string)($_POST['location'] ?? '');
    $sg_direction = (string)($_POST['direction'] ?? '');
    $sg_reporter = trim((string)($_POST['reporter'] ?? ''));
    if ($auth_user !== '' && !$is_admin) { $sg_reporter = $auth_display; }
    $sg_ts = strtotime((string)($_POST['seen_at'] ?? ''));
    $sg_flash = ['Pozorování bylo odesláno ke schválení adminem.', 'success'];

    if (!$wit_tables_ok) {
        $sg_flash = ['Tabulka sightings neexistuje – spusť SQL skript pozorovani.sql.', 'danger'];
    } elseif ($sg_person === '' || mb_strlen($sg_person) > 150 || $sg_reporter === '' || mb_strlen($sg_reporter) > 100
              || !isset($wit_locations[$sg_location]) || !isset($wit_directions[$sg_direction])
              || $sg_ts === false || $sg_ts > time() + 300 || $sg_ts < time() - 14 * 86400) {
        $sg_flash = ['Neplatné údaje pozorování!', 'danger'];
    } else {
        $sg_seen = date('Y-m-d H:i:s', $sg_ts);
        $st = $pdo->prepare("SELECT COUNT(*) FROM sightings WHERE device_id = ? AND created_at >= CURDATE()");
        $st->execute([$device_id]);
        if ((int)$st->fetchColumn() >= $wit_max_per_device_day) {
            $sg_flash = ['Dnes jsi už odeslal maximální počet pozorování.', 'danger'];
        } else {
            $canon = wit_lookup_canonical($pdo, $sg_person);
            $st = $pdo->prepare("SELECT COUNT(*) FROM sightings WHERE device_id = ? AND person_name = ? AND location = ? AND seen_at BETWEEN ? AND ?");
            $st->execute([$device_id, $canon ?? $sg_person, $sg_location, date('Y-m-d H:i:s', $sg_ts - 1800), date('Y-m-d H:i:s', $sg_ts + 1800)]);
            if ((int)$st->fetchColumn() > 0) {
                $sg_flash = ['Toto pozorování už je zaznamenané.', 'info'];
            } else {
                $sg_status = $is_admin ? 'approved' : 'pending';
                $sg_note = lh_note($_POST['note'] ?? '');
                $sg_with_note = $note_cols['sightings'] && $sg_note !== '';
                $st = $pdo->prepare("INSERT INTO sightings (person_name, location, direction, seen_at, reporter, status, name_known, device_id, client_ip, decided_at" . ($sg_with_note ? ', note' : '') . ") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?" . ($sg_with_note ? ', ?' : '') . ")");
                $st->execute(array_merge([$canon ?? $sg_person, $sg_location, $sg_direction, $sg_seen, $sg_reporter, $sg_status, $canon !== null ? 1 : 0, $device_id, $client_ip, $is_admin ? date('Y-m-d H:i:s') : null], $sg_with_note ? [$sg_note] : []));
                if ($is_admin) { $sg_flash = ['Pozorování bylo uloženo a schváleno.', 'success']; }
                else {
                    $pend = $pdo->query("SELECT COUNT(*) FROM sightings WHERE status = 'pending'")->fetchColumn();
                    if (lh_notify_enabled($pdo, 'new_sighting')) lh_mail_event($pdo, $smtp, 'mail_admins', '[Detektor] Nové pozorování ke schválení',
                        "Nové pozorování čeká na schválení (celkem čekajících: " . (int)$pend . ").\nUmístění: " . ($wit_loc_short[$sg_location] ?? $sg_location) . ", " . ($wit_directions[$sg_direction] ?? $sg_direction) . "\nČas pozorování: " . date('j. n. Y H:i', $sg_ts) . "\n\nSchválit v administraci: " . $app_url);
                }
            }
        }
    }
    $_SESSION['flash_message'] = $sg_flash[0];
    $_SESSION['flash_type'] = $sg_flash[1];
    header('Location: index.php');
    exit;
}

// Jiný poznatek: osoba ani čas nejsou povinné; ukládá se hned bez schvalování; v náhledové verzi nedostupné
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report_note') {
    $pn_reporter = trim((string)($_POST['reporter'] ?? ''));
    if ($auth_user !== '' && !$is_admin) { $pn_reporter = $auth_display; }
    $pn_person = trim(preg_replace('/\s+/u', ' ', (string)($_POST['person_name'] ?? '')));
    $pn_text = trim(str_replace("\r\n", "\n", (string)($_POST['poznatek'] ?? '')));
    $pn_when = trim((string)($_POST['happened_at'] ?? ''));
    $pn_ts = $pn_when === '' ? null : strtotime($pn_when);   // minulý i budoucí čas je v pořádku
    $pn_flash = ['Poznatek byl uložen.', 'success'];
    if ($readonly) {
        $pn_flash = ['Poznatky nejsou v této verzi k dispozici.', 'danger'];
    } elseif (!$pozn_ok) {
        $pn_flash = ['Tabulka poznatky neexistuje – spusť SQL skript poznatky.sql.', 'danger'];
    } elseif ($pn_reporter === '' || mb_strlen($pn_reporter) > 100 || $pn_text === '' || mb_strlen($pn_text) > 500 || mb_strlen($pn_person) > 150
              || ($pn_when !== '' && ($pn_ts === false || (int)date('Y', $pn_ts) < 2000 || (int)date('Y', $pn_ts) > 2100))) {
        $pn_flash = ['Neplatné údaje poznatku! Vyplň jméno reportujícího a poznatek (nejvýše 500 znaků).', 'danger'];
    } else {
        $st = $pdo->prepare("SELECT COUNT(*) FROM poznatky WHERE device_id = ? AND created_at >= CURDATE()");
        $st->execute([$device_id]);
        $st2 = $pdo->prepare("SELECT COUNT(*) FROM poznatky WHERE device_id = ? AND poznatek = ? AND created_at >= (NOW() - INTERVAL 10 MINUTE)");
        $st2->execute([$device_id, $pn_text]);
        if ((int)$st->fetchColumn() >= $wit_max_per_device_day) {
            $pn_flash = ['Dnes jsi už odeslal maximální počet hlášení.', 'danger'];
        } elseif ((int)$st2->fetchColumn() > 0) {
            $pn_flash = ['Tento poznatek už je zaznamenaný.', 'info'];
        } else {
            $canon = $pn_person !== '' ? wit_lookup_canonical($pdo, $pn_person) : null;
            $st = $pdo->prepare("INSERT INTO poznatky (reporter, person_name, happened_at, poznatek, device_id, client_ip) VALUES (?, ?, ?, ?, ?, ?)");
            $st->execute([$pn_reporter, $pn_person !== '' ? ($canon ?? $pn_person) : null, $pn_ts !== null ? date('Y-m-d H:i:s', $pn_ts) : null, $pn_text, $device_id, $client_ip]);
        }
    }
    $_SESSION['flash_message'] = $pn_flash[0];
    $_SESSION['flash_type'] = $pn_flash[1];
    header('Location: index.php');
    exit;
}

// Překladač pseudonymů (jen admin): výsledek se jen zobrazí v panelu; nic se neukládá, nezapisuje ani nehlásí e-mailem
$pt_result = null;
$pt_text = '';
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pseudo_translate') {
    $pt_text = mb_substr((string)($_POST['pt_text'] ?? ''), 0, 5000);
    $pt_result = lh_pseudo_translate($pdo, $pt_text);
    header('Cache-Control: no-store');
}

// ==========================================
// 7. ADMIN AKCE (EDITACE/MAZÁNÍ HLÁŠENÍ, BLACKLIST OSOB)
// ==========================================
$admin_actions = ['edit_report', 'delete_report', 'blacklist_suspect', 'restore_suspect', 'it_add_staff', 'it_remove_staff', 'it_set_until', 'it_set_until_all', 'it_add_vacation', 'it_delete_vacation', 'set_device_label', 'sighting_approve', 'sighting_reject', 'sighting_delete', 'sighting_rename', 'incident_set_counted', 'set_setting', 'set_mail_lists', 'mail_test', 'digest_now', 'digest_test', 'set_tolerance', 'incident_announce', 'label_save', 'label_delete', 'incident_labels_set', 'innocent_add', 'innocent_delete', 'employees_save', 'set_admin_notify', 'set_pseudonymize', 'milestone_save', 'milestone_delete', 'milestone_grant', 'poznatek_edit', 'poznatek_delete', 'alias_merge', 'alias_delete', 'trusted_add', 'trusted_delete', 'sighting_edit', 'set_collection', 'set_ro_sections', 'culprit_set', 'culprit_clear', 'culprit_notify'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', $admin_actions, true)) {
    if (!$is_admin) {
        $_SESSION['flash_message'] = 'Pro tuto akci se musíš přihlásit jako admin!';
        $_SESSION['flash_type'] = 'danger';
    } elseif (in_array($_POST['action'], ['set_setting', 'set_mail_lists', 'set_tolerance', 'set_admin_notify', 'set_pseudonymize'], true) && !$settings_ok) {
        $_SESSION['flash_message'] = 'Chybí tabulka app_settings – spusť SQL skript nastaveni_aplikace.sql.';
        $_SESSION['flash_type'] = 'danger';
    } elseif ($_POST['action'] === 'employees_save' && !$employees_ok) {
        $_SESSION['flash_message'] = 'Chybí tabulka employees – spusť SQL skript zamestnanci.sql.';
        $_SESSION['flash_type'] = 'danger';
    } elseif (in_array($_POST['action'], ['innocent_add', 'innocent_delete'], true) && !$innocent_ok) {
        $_SESSION['flash_message'] = 'Chybí tabulka incident_innocents – spusť SQL skript nevinni.sql.';
        $_SESSION['flash_type'] = 'danger';
    } elseif (in_array($_POST['action'], ['trusted_add', 'trusted_delete'], true) && !$trusted_ok) {
        $_SESSION['flash_message'] = 'Chybí tabulka trusted_persons – spusť SQL skript duveryhodni.sql.';
        $_SESSION['flash_type'] = 'danger';
    } elseif (in_array($_POST['action'], ['alias_merge', 'alias_delete'], true) && !$aliases_ok) {
        $_SESSION['flash_message'] = 'Chybí tabulka name_aliases – spusť SQL skript aliasy.sql.';
        $_SESSION['flash_type'] = 'danger';
    } elseif (in_array($_POST['action'], ['poznatek_edit', 'poznatek_delete'], true) && !$pozn_ok) {
        $_SESSION['flash_message'] = 'Chybí tabulka poznatky – spusť SQL skript poznatky.sql.';
        $_SESSION['flash_type'] = 'danger';
    } elseif (in_array($_POST['action'], ['milestone_save', 'milestone_delete', 'milestone_grant'], true) && !$milestones_ok) {
        $_SESSION['flash_message'] = 'Chybí tabulka milestones – spusť SQL skript milniky.sql.';
        $_SESSION['flash_type'] = 'danger';
    } elseif (in_array($_POST['action'], ['label_save', 'label_delete', 'incident_labels_set'], true) && !$labels_ok) {
        $_SESSION['flash_message'] = 'Chybí tabulky štítků – spusť SQL skript stitky.sql.';
        $_SESSION['flash_type'] = 'danger';
    } elseif ($_POST['action'] === 'incident_announce' && !$announce_ok) {
        $_SESSION['flash_message'] = 'Chybí tabulka incident_announced – spusť SQL skript oznameni.sql.';
        $_SESSION['flash_type'] = 'danger';
    } elseif ($_POST['action'] === 'incident_set_counted' && !$incident_flags_ok) {
        $_SESSION['flash_message'] = 'Chybí tabulka incident_ignored – spusť SQL skript incidenty_pocitani.sql.';
        $_SESSION['flash_type'] = 'danger';
    } elseif (in_array($_POST['action'], ['it_set_until', 'it_set_until_all'], true) && $it_tables_ok && !$it_has_until) {
        $_SESSION['flash_message'] = 'Chybí sloupec virtual_until – spusť SQL skript it_pikacka.sql.';
        $_SESSION['flash_type'] = 'danger';
    } elseif (strpos($_POST['action'], 'it_') === 0 && !$it_tables_ok) {
        $_SESSION['flash_message'] = 'Tabulky it_staff a it_vacations neexistují – spusť SQL skript it_dovolene.sql.';
        $_SESSION['flash_type'] = 'danger';
    } elseif (strpos($_POST['action'], 'sighting_') === 0 && !$wit_tables_ok) {
        $_SESSION['flash_message'] = 'Tabulka sightings neexistuje – spusť SQL skript pozorovani.sql.';
        $_SESSION['flash_type'] = 'danger';
    } else {
        // Původní hodnoty pro záznam akce (úprava a smazání hlášení)
        $audit_before = null;
        if (in_array($_POST['action'], ['edit_report', 'delete_report'], true)) {
            $stb = $pdo->prepare("SELECT id, cubicle, status, reported_at, reporter" . ($note_cols['reports'] ? ', note' : '') . " FROM toilet_reports WHERE id = ?");
            $stb->execute([(int)($_POST['id'] ?? $_POST['report_id'] ?? 0)]);
            $audit_before = $stb->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (in_array($_POST['action'], ['sighting_edit', 'sighting_delete'], true) && $wit_tables_ok) {
            $stb = $pdo->prepare("SELECT id, person_name, location, direction, seen_at, reporter, status" . ($note_cols['sightings'] ? ', note' : '') . " FROM sightings WHERE id = ?");
            $stb->execute([(int)($_POST['id'] ?? 0)]);
            $audit_before = $stb->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        switch ($_POST['action']) {
            case 'set_ro_sections':
                $rs_vis = array_map('strval', (array)($_POST['ro_visible'] ?? []));
                $rs_hidden = [];
                foreach ($ro_sections as $rs_k => $rs_v) { if (!in_array($rs_k, $rs_vis, true)) { $rs_hidden[] = $rs_k; } }
                lh_setting_set($pdo, 'ro_hidden', implode(',', $rs_hidden));
                $_POST['skryté_části'] = $rs_hidden ? implode(', ', array_map(function ($k) use ($ro_sections) { return $ro_sections[$k][0]; }, $rs_hidden)) : '(žádné)';
                $_SESSION['flash_message'] = $rs_hidden ? 'Uloženo. V náhledové verzi jsou skryté: ' . implode(', ', array_map(function ($k) use ($ro_sections) { return $ro_sections[$k][0]; }, $rs_hidden)) . '.' : 'Uloženo. Náhledová verze zobrazuje všechny části.';
                $_SESSION['flash_type'] = 'success';
                break;

            case 'set_collection':
                $sc_off = (($_POST['collect'] ?? '') === 'off');
                lh_setting_set($pdo, 'collect_disabled', $sc_off ? '1' : '0');
                $sc_extra = '';
                if (!$sc_off && lh_setting($pdo, 'culprit_name') !== '') {   // sběr se znovu otevřel, případ už není uzavřený
                    foreach (['culprit_name', 'culprit_at', 'culprit_by', 'culprit_mailed_at'] as $sc_k) { lh_setting_set($pdo, $sc_k, ''); }
                    $sc_extra = ' Označení pachatele bylo zrušeno, protože se sběr dat znovu otevřel.';
                }
                $_SESSION['flash_message'] = ($sc_off ? 'Sběr dat byl vypnut: nikdo kromě admina už nemůže posílat hlášení stavu, pozorování ani poznatky.' : 'Sběr dat byl zapnut.') . $sc_extra;
                $_SESSION['flash_type'] = 'success';
                break;

            case 'culprit_set':
                if (lh_setting($pdo, 'collect_disabled') !== '1') {
                    $_SESSION['flash_message'] = 'Pachatele jde označit až po ukončení sběru dat.';
                    $_SESSION['flash_type'] = 'danger';
                    break;
                }
                $cu_in = trim(preg_replace('/\s+/u', ' ', (string)($_POST['culprit_name'] ?? '')));
                if ($cu_in === '' || mb_strlen($cu_in) > 100) {
                    $_SESSION['flash_message'] = 'Zadej jméno pachatele (nejvýše 100 znaků).';
                    $_SESSION['flash_type'] = 'danger';
                    break;
                }
                $cu_canon = wit_lookup_canonical($pdo, $cu_in);   // sjednotí jméno s docházkou a aliasy
                $cu_store = $cu_canon ?? $cu_in;
                lh_setting_set($pdo, 'culprit_name', $cu_store);
                lh_setting_set($pdo, 'culprit_at', date('Y-m-d H:i:s'));
                lh_setting_set($pdo, 'culprit_by', $audit_user !== '' ? $audit_user : 'admin');
                lh_setting_set($pdo, 'culprit_mailed_at', '');
                $cu_msg = 'Pachatel byl označen: ' . $cu_store . ($cu_canon === null ? ' (jméno se nenašlo v docházce, uloženo tak, jak je)' : '') . '.';
                if (!empty($_POST['notify'])) {
                    list($cu_n, $cu_errs) = lh_culprit_mail($pdo, $smtp, $incidents, $app_url);
                    $cu_msg .= $cu_errs ? ' E-mail o dopadení se nepodařilo odeslat: ' . $cu_errs[0] . '.' : ' E-mail o dopadení byl odeslán (' . $cu_n . ' příjemců).';
                    $_POST['odesláno'] = $cu_errs ? 'ne: ' . $cu_errs[0] : 'ano, ' . $cu_n . ' příjemců';
                }
                $_SESSION['flash_message'] = $cu_msg;
                $_SESSION['flash_type'] = 'success';
                break;

            case 'culprit_notify':
                if (lh_setting($pdo, 'culprit_name') === '') {
                    $_SESSION['flash_message'] = 'Nejdřív označ pachatele.';
                    $_SESSION['flash_type'] = 'danger';
                    break;
                }
                list($cu_n, $cu_errs) = lh_culprit_mail($pdo, $smtp, $incidents, $app_url);
                $_SESSION['flash_message'] = $cu_errs ? 'E-mail o dopadení se nepodařilo odeslat: ' . $cu_errs[0] . '.' : 'E-mail o dopadení byl odeslán (' . $cu_n . ' příjemců).';
                $_SESSION['flash_type'] = $cu_errs ? 'danger' : 'success';
                break;

            case 'culprit_clear':
                foreach (['culprit_name', 'culprit_at', 'culprit_by', 'culprit_mailed_at'] as $cu_k) { lh_setting_set($pdo, $cu_k, ''); }
                $_SESSION['flash_message'] = 'Označení pachatele bylo zrušeno.';
                $_SESSION['flash_type'] = 'success';
                break;

            case 'set_tolerance':
                $tv = trim((string)($_POST['tolerance_minutes'] ?? ''));
                if (!ctype_digit($tv) || (int)$tv > 60) {
                    $_SESSION['flash_message'] = 'Tolerance musí být celé číslo od 0 do 60 minut.';
                    $_SESSION['flash_type'] = 'danger';
                } else {
                    lh_setting_set($pdo, 'tolerance_minutes', (string)(int)$tv);
                    $_SESSION['flash_message'] = 'Tolerance byla nastavena na ' . (int)$tv . ' min.';
                    $_SESSION['flash_type'] = 'success';
                }
                break;

            case 'set_mail_lists':
                $parsed = [];
                $bad = [];
                foreach (['mail_admins', 'mail_digest'] as $mk) {
                    $emails = [];
                    foreach (preg_split('/[\s,;]+/', (string)($_POST[$mk] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $e) {
                        if (filter_var($e, FILTER_VALIDATE_EMAIL)) { $emails[strtolower($e)] = $e; } else { $bad[] = $e; }
                    }
                    $parsed[$mk] = array_slice(array_values($emails), 0, 20);
                }
                if ($bad) {
                    $_SESSION['flash_message'] = 'Neplatné e-mailové adresy (nic se neuložilo): ' . implode(', ', array_slice($bad, 0, 5));
                    $_SESSION['flash_type'] = 'danger';
                } else {
                    foreach ($parsed as $mk => $list) { lh_setting_set($pdo, $mk, implode(',', $list)); }
                    $_SESSION['flash_message'] = 'E-mailové seznamy byly uloženy.';
                    $_SESSION['flash_type'] = 'success';
                }
                break;

            case 'mail_test':
            case 'digest_now':
            case 'digest_test':
                $to_list = lh_mail_list($pdo, $_POST['action'] === 'digest_now' ? 'mail_digest' : 'mail_admins');
                if (!lh_mail_configured($smtp)) {
                    $_SESSION['flash_message'] = 'SMTP není nastaveno v lh_config.php.';
                    $_SESSION['flash_type'] = 'danger';
                } elseif (empty($to_list)) {
                    $_SESSION['flash_message'] = 'Příslušný seznam e-mailů je prázdný.';
                    $_SESSION['flash_type'] = 'danger';
                } elseif ($_POST['action'] === 'mail_test') {
                    $errs = lh_mail_send_all($smtp, $to_list, '[Detektor] Zkušební e-mail', "Zkušební zpráva z Detektoru Lorda Hoven.\nPokud ji čtete, odesílání funguje.\n\n" . $app_url);
                    $_SESSION['flash_message'] = $errs ? 'Odeslání selhalo: ' . $errs[0] : 'Zkušební e-mail byl odeslán (' . count($to_list) . ' příjemců).';
                    $_SESSION['flash_type'] = $errs ? 'danger' : 'success';
                } elseif ($_POST['action'] === 'digest_test') {
                    // Testovací souhrn jde jen adminům a nemění časovač pravidelného souhrnu
                    list($n_ok, $errs) = lh_digest_send($pdo, $smtp, $incidents, $cubicles_list, $app_url, 'mail_admins', false, '[TEST] ', $digest_leaders_n);
                    $_SESSION['flash_message'] = $errs ? 'Odeslání testovacího souhrnu selhalo: ' . $errs[0] : 'Testovací souhrn byl odeslán adminům (' . $n_ok . ' příjemců). Časovač pravidelného souhrnu se nezměnil.';
                    $_SESSION['flash_type'] = $errs ? 'danger' : 'success';
                } else {
                    list($n_ok, $errs) = lh_digest_send($pdo, $smtp, $incidents, $cubicles_list, $app_url, 'mail_digest', true, '', $digest_leaders_n);
                    $_SESSION['flash_message'] = $errs ? 'Odeslání souhrnu selhalo: ' . $errs[0] : 'Týdenní souhrn byl odeslán (' . $n_ok . ' příjemců).';
                    $_SESSION['flash_type'] = $errs ? 'danger' : 'success';
                }
                break;

            case 'sighting_approve':
            case 'sighting_reject':
                $st = $pdo->prepare("UPDATE sightings SET status = ?, decided_at = NOW() WHERE id = ?");
                $st->execute([$_POST['action'] === 'sighting_approve' ? 'approved' : 'rejected', (int)($_POST['id'] ?? 0)]);
                $_SESSION['flash_message'] = $_POST['action'] === 'sighting_approve' ? 'Pozorování bylo schváleno.' : 'Pozorování bylo zamítnuto.';
                $_SESSION['flash_type'] = 'success';
                break;

            case 'sighting_delete':
                $st = $pdo->prepare("DELETE FROM sightings WHERE id = ?");
                $st->execute([(int)($_POST['id'] ?? 0)]);
                $_SESSION['flash_message'] = 'Pozorování bylo smazáno.';
                $_SESSION['flash_type'] = 'success';
                break;

            case 'sighting_edit':
                // Úprava pozorování adminem: osoba, místo, směr, čas, hlásící a poznámka. Stav (schváleno / čeká / zamítnuto) a údaje o odeslání se nemění.
                $se_id = (int)($_POST['id'] ?? 0);
                $se_person = trim(preg_replace('/\s+/u', ' ', (string)($_POST['person_name'] ?? '')));
                $se_loc = (string)($_POST['location'] ?? '');
                $se_dir = (string)($_POST['direction'] ?? '');
                $se_rep = trim((string)($_POST['reporter'] ?? ''));
                $se_ts = strtotime((string)($_POST['seen_at'] ?? ''));
                $se_note = lh_note($_POST['note'] ?? '');
                $st = $pdo->prepare("SELECT COUNT(*) FROM sightings WHERE id = ?");
                $st->execute([$se_id]);
                if ($se_id <= 0 || (int)$st->fetchColumn() === 0) {
                    $_SESSION['flash_message'] = 'Pozorování neexistuje.';
                    $_SESSION['flash_type'] = 'danger';
                } elseif ($se_person === '' || mb_strlen($se_person) > 150 || $se_rep === '' || mb_strlen($se_rep) > 100
                          || !isset($wit_locations[$se_loc]) || !isset($wit_directions[$se_dir])
                          || $se_ts === false || (int)date('Y', $se_ts) < 2000 || $se_ts > time() + 300) {
                    $_SESSION['flash_message'] = 'Neplatné údaje pozorování! Vyplň jméno, místo, směr, čas (ne z budoucnosti) a hlásícího.';
                    $_SESSION['flash_type'] = 'danger';
                } else {
                    $se_canon = wit_lookup_canonical($pdo, $se_person);
                    $se_args = [$se_canon ?? $se_person, $se_loc, $se_dir, date('Y-m-d H:i:s', $se_ts), $se_rep, $se_canon !== null ? 1 : 0];
                    if ($note_cols['sightings']) { $se_args[] = $se_note !== '' ? $se_note : null; }
                    $se_args[] = $se_id;
                    $st = $pdo->prepare("UPDATE sightings SET person_name = ?, location = ?, direction = ?, seen_at = ?, reporter = ?, name_known = ?" . ($note_cols['sightings'] ? ', note = ?' : '') . " WHERE id = ?");
                    $st->execute($se_args);
                    $_SESSION['flash_message'] = $st->rowCount() > 0 ? 'Pozorování bylo upraveno.' : 'Beze změny: zadané údaje se shodují s uloženými.';
                    $_SESSION['flash_type'] = $st->rowCount() > 0 ? 'success' : 'info';
                }
                break;

            case 'sighting_rename':
                $new_name = trim(preg_replace('/\s+/u', ' ', (string)($_POST['person_name'] ?? '')));
                if ($new_name !== '' && mb_strlen($new_name) <= 150) {
                    $canon = wit_lookup_canonical($pdo, $new_name);
                    $st = $pdo->prepare("UPDATE sightings SET person_name = ?, name_known = ? WHERE id = ?");
                    $st->execute([$canon ?? $new_name, $canon !== null ? 1 : 0, (int)($_POST['id'] ?? 0)]);
                    $_SESSION['flash_message'] = 'Jméno u pozorování bylo upraveno.';
                    $_SESSION['flash_type'] = 'success';
                }
                break;

            case 'edit_report':
                $id = (int)($_POST['id'] ?? 0);
                $reporter = trim($_POST['reporter'] ?? '');
                $cubicle = $_POST['cubicle'] ?? '';
                $status = $_POST['status'] ?? '';
                $reported_at = $_POST['reported_at'] ?? '';

                if ($id <= 0 || empty($reporter) || !isset($cubicles_list[$cubicle]) || !in_array($status, ['ok', 'zesrana'], true) || empty($reported_at)) {
                    $_SESSION['flash_message'] = 'Neplatná data hlášení!';
                    $_SESSION['flash_type'] = 'danger';
                } else {
                    if ($note_cols['reports']) {
                        $edit_note = lh_note($_POST['note'] ?? '');
                        $stmt = $pdo->prepare("UPDATE toilet_reports SET reporter = ?, cubicle = ?, status = ?, reported_at = ?, note = ? WHERE id = ?");
                        $stmt->execute([$reporter, $cubicle, $status, $reported_at, $edit_note !== '' ? $edit_note : null, $id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE toilet_reports SET reporter = ?, cubicle = ?, status = ?, reported_at = ? WHERE id = ?");
                        $stmt->execute([$reporter, $cubicle, $status, $reported_at, $id]);
                    }
                    $_SESSION['flash_message'] = 'Hlášení bylo upraveno.';
                    $_SESSION['flash_type'] = 'success';
                }
                break;

            case 'delete_report':
                $stmt = $pdo->prepare("DELETE FROM toilet_reports WHERE id = ?");
                $stmt->execute([(int)($_POST['id'] ?? 0)]);
                $_SESSION['flash_message'] = 'Hlášení bylo smazáno.';
                $_SESSION['flash_type'] = 'success';
                break;

            case 'blacklist_suspect':
                $person = trim($_POST['person_name'] ?? '');
                if ($person !== '') {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO blacklisted_suspects (person_name) VALUES (?)");
                    $stmt->execute([$person]);
                    $_SESSION['flash_message'] = "Osoba „{$person}“ byla odstraněna ze seznamu podezřelých.";
                    $_SESSION['flash_type'] = 'success';
                }
                break;

            case 'restore_suspect':
                $person = trim($_POST['person_name'] ?? '');
                if ($person !== '') {
                    $stmt = $pdo->prepare("DELETE FROM blacklisted_suspects WHERE person_name = ?");
                    $stmt->execute([$person]);
                    $_SESSION['flash_message'] = "Osoba „{$person}“ byla vrácena do seznamu podezřelých.";
                    $_SESSION['flash_type'] = 'success';
                }
                break;

            case 'set_device_label':
                $label_device = $_POST['device_id'] ?? '';
                $label_text = trim($_POST['label'] ?? '');
                if (preg_match('/^[a-f0-9]{32}$/', $label_device)) {
                    try {
                        if ($label_text === '') {
                            $stmt = $pdo->prepare("DELETE FROM device_labels WHERE device_id = ?");
                            $stmt->execute([$label_device]);
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO device_labels (device_id, label) VALUES (?, ?) ON DUPLICATE KEY UPDATE label = VALUES(label)");
                            $stmt->execute([$label_device, mb_substr($label_text, 0, 100)]);
                        }
                        $_SESSION['flash_message'] = 'Název zařízení byl uložen.';
                        $_SESSION['flash_type'] = 'success';
                    } catch (PDOException $e) {
                        $_SESSION['flash_message'] = 'Tabulka device_labels neexistuje – spusť SQL skript device_log.sql.';
                        $_SESSION['flash_type'] = 'danger';
                    }
                }
                break;

            case 'it_add_staff':
                $person = lh_alias_apply($pdo, trim($_POST['person_name'] ?? ''));   // alias → hlavní jméno
                if ($person !== '') {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO it_staff (person_name) VALUES (?)");
                    $stmt->execute([$person]);
                    $_SESSION['flash_message'] = "Člen IT „{$person}“ byl přidán.";
                    $_SESSION['flash_type'] = 'success';
                }
                break;

            case 'set_setting':
                $skey = $_POST['setting_key'] ?? '';
                if (in_array($skey, ['incident_select_global', 'incident_select_admin'], true)) {
                    $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    $stmt->execute([$skey, ($_POST['setting_value'] ?? '0') === '1' ? '1' : '0']);
                    $_SESSION['flash_message'] = 'Nastavení uloženo.';
                    $_SESSION['flash_type'] = 'success';
                }
                break;

            case 'innocent_add':
                // Osoby: person_names (jedna na řádek, lze vložit celý seznam), starší pole person_name se bere také.
                // Incidenty: report_ids[] (víc najednou); starší pole report_id se bere také. Důvod platí pro všechny dvojice osoba × incident.
                $want = [];
                foreach (array_merge((array)($_POST['report_ids'] ?? []), isset($_POST['report_id']) ? [$_POST['report_id']] : []) as $w_id) { if ((int)$w_id > 0) { $want[(int)$w_id] = true; } }
                $want = array_keys($want);
                $valid_nos = [];
                foreach ($incidents as $ii) { if (in_array((int)$ii['id'], $want, true)) { $valid_nos[(int)$ii['id']] = $incident_no[(int)$ii['id']] ?? 0; } }
                $in_people = [];
                $in_bad = [];
                foreach (preg_split('/\r\n|\r|\n|;/', (string)($_POST['person_names'] ?? ($_POST['person_name'] ?? ''))) as $in_line) {
                    $in_nm = trim(preg_replace('/\s+/u', ' ', $in_line));
                    if ($in_nm === '') { continue; }
                    if (mb_strlen($in_nm) > 100) { $in_bad[] = mb_substr($in_nm, 0, 30) . '…'; continue; }
                    $in_key = lh_name_norm($in_nm);
                    if (!isset($in_people[$in_key])) { $in_people[$in_key] = $in_nm; }
                }
                $in_reason = trim(preg_replace('/\s+/u', ' ', (string)($_POST['reason'] ?? '')));
                if (empty($want) || count($valid_nos) !== count($want)) {
                    $_SESSION['flash_message'] = empty($want) ? 'Vyber aspoň jeden incident.' : 'Některý z vybraných incidentů neexistuje.';
                    $_SESSION['flash_type'] = 'danger';
                } elseif (count($want) > 50) {
                    $_SESSION['flash_message'] = 'Najednou lze vybrat nejvýše 50 incidentů.';
                    $_SESSION['flash_type'] = 'danger';
                } elseif ($in_bad) {
                    $_SESSION['flash_message'] = 'Jméno je delší než 100 znaků (nic se neuložilo): ' . implode(', ', array_slice($in_bad, 0, 3));
                    $_SESSION['flash_type'] = 'danger';
                } elseif (empty($in_people)) {
                    $_SESSION['flash_message'] = 'Zadej aspoň jednu osobu.';
                    $_SESSION['flash_type'] = 'danger';
                } elseif (count($in_people) > 300) {
                    $_SESSION['flash_message'] = 'Najednou lze zadat nejvýše 300 osob.';
                    $_SESSION['flash_type'] = 'danger';
                } elseif (mb_strlen($in_reason) > 200) {
                    $_SESSION['flash_message'] = 'Důvod může mít nejvýše 200 znaků.';
                    $_SESSION['flash_type'] = 'danger';
                } else {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("INSERT INTO incident_innocents (report_id, person_name, reason) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE reason = VALUES(reason)");
                    $stored_names = [];
                    foreach ($in_people as $in_nm) {
                        $in_store = wit_lookup_canonical($pdo, $in_nm) ?? $in_nm;   // jméno se sjednotí s docházkou, když v ní existuje
                        $stored_names[] = $in_store;
                        foreach (array_keys($valid_nos) as $rid_w) { $stmt->execute([$rid_w, $in_store, $in_reason !== '' ? $in_reason : null]); }
                    }
                    $pdo->commit();
                    $nos_txt = implode(', ', array_map(function ($n) { return '#' . $n; }, array_values($valid_nos)));
                    $_POST['incident'] = $nos_txt;
                    unset($_POST['person_names'], $_POST['person_name']);
                    $_POST['osoby'] = count($stored_names) <= 5 ? implode(', ', $stored_names) : count($stored_names) . ' osob (' . implode(', ', array_slice($stored_names, 0, 5)) . ', …)';
                    $n_st = count($stored_names);
                    $who_txt = $n_st === 1 ? 'Osoba „' . $stored_names[0] . '“ je označena jako nevinná'
                        : ($n_st < 5 ? $n_st . ' osoby jsou označeny jako nevinné' : $n_st . ' osob je označeno jako nevinných');
                    $_SESSION['flash_message'] = $who_txt . ' u ' . (count($valid_nos) > 1 ? 'incidentů ' : 'incidentu ') . $nos_txt . '.';
                    $_SESSION['flash_type'] = 'success';
                }
                break;

            case 'set_pseudonymize':
                $pv = (string)($_POST['pseudonymize'] ?? '');
                if ($pv !== '0' && $pv !== '1') {
                    $_SESSION['flash_message'] = 'Neplatná hodnota.';
                    $_SESSION['flash_type'] = 'danger';
                } else {
                    lh_setting_set($pdo, 'pseudonymize_readonly', $pv);
                    $_SESSION['flash_message'] = $pv === '1' ? 'Anonymizace osob v náhledové verzi je zapnuta.' : 'Anonymizace osob v náhledové verzi je VYPNUTA, náhled ukazuje skutečná jména.';
                    $_SESSION['flash_type'] = $pv === '1' ? 'success' : 'warning';
                }
                break;

            case 'set_admin_notify':
                $all_keys = [];
                foreach (lh_notify_groups() as $grp_items) { foreach (array_keys($grp_items) as $nk) { $all_keys[] = $nk; } }
                $want_keys = array_values(array_intersect($all_keys, array_map('strval', (array)($_POST['notify'] ?? []))));
                lh_setting_set($pdo, 'admin_notify', 'v1:' . implode(',', $want_keys));
                $_POST['zapnuto'] = count($want_keys) . ' z ' . count($all_keys);
                $_SESSION['flash_message'] = 'Upozornění uložena (zapnuto ' . count($want_keys) . ' z ' . count($all_keys) . ').';
                $_SESSION['flash_type'] = 'success';
                break;

            case 'employees_save':
                // Jeden zaměstnanec na řádek; duplicity (bez ohledu na velikost písmen a diakritiku) se sloučí, celý seznam se nahradí
                $emp_new = [];
                $emp_bad = [];
                foreach (preg_split('/\r\n|\r|\n/', (string)($_POST['employees'] ?? '')) as $emp_line) {
                    $emp_name = trim(preg_replace('/\s+/u', ' ', $emp_line));
                    if ($emp_name === '') { continue; }
                    $emp_name = lh_alias_apply($pdo, $emp_name);   // alias → hlavní jméno
                    if (mb_strlen($emp_name) > 100) { $emp_bad[] = mb_substr($emp_name, 0, 30) . '…'; continue; }
                    $emp_key = lh_name_norm($emp_name);
                    if (!isset($emp_new[$emp_key])) { $emp_new[$emp_key] = $emp_name; }
                }
                if ($emp_bad) {
                    $_SESSION['flash_message'] = 'Jméno je delší než 100 znaků (nic se neuložilo): ' . implode(', ', array_slice($emp_bad, 0, 3));
                    $_SESSION['flash_type'] = 'danger';
                } elseif (count($emp_new) > 1000) {
                    $_SESSION['flash_message'] = 'Seznam může mít nejvýše 1000 zaměstnanců.';
                    $_SESSION['flash_type'] = 'danger';
                } else {
                    $pdo->beginTransaction();
                    $pdo->exec("DELETE FROM employees");
                    $stmt = $pdo->prepare("INSERT INTO employees (name) VALUES (?)");
                    foreach ($emp_new as $emp_name) { $stmt->execute([$emp_name]); }
                    $pdo->commit();
                    $_POST['employees'] = '(' . count($emp_new) . ' zaměstnanců)';
                    $_SESSION['flash_message'] = 'Seznam zaměstnanců byl uložen (' . count($emp_new) . ' osob).';
                    $_SESSION['flash_type'] = 'success';
                }
                break;

            case 'innocent_delete':
                $in_id = (int)($_POST['id'] ?? 0);
                $in_found = null;
                foreach ($incident_innocents as $rid_in => $rows_in) { foreach ($rows_in as $r_in) { if ((int)$r_in['id'] === $in_id) { $in_found = [$rid_in, $r_in]; } } }
                if (!$in_found) {
                    $_SESSION['flash_message'] = 'Záznam neexistuje.';
                    $_SESSION['flash_type'] = 'danger';
                } else {
                    $stmt = $pdo->prepare("DELETE FROM incident_innocents WHERE id = ?");
                    $stmt->execute([$in_id]);
                    $_POST['incident'] = '#' . ($incident_no[$in_found[0]] ?? '?');
                    $_POST['osoba'] = $in_found[1]['person_name'];
                    $_SESSION['flash_message'] = 'Osoba „' . $in_found[1]['person_name'] . '“ už u incidentu #' . ($incident_no[$in_found[0]] ?? '?') . ' není označena jako nevinná.';
                    $_SESSION['flash_type'] = 'success';
                }
                break;

            case 'trusted_add':
                $tr_people = [];
                $tr_bad = [];
                foreach (preg_split('/\r\n|\r|\n|;/', (string)($_POST['trusted_names'] ?? '')) as $tr_line) {
                    $tr_nm = trim(preg_replace('/\s+/u', ' ', $tr_line));
                    if ($tr_nm === '') { continue; }
                    if (mb_strlen($tr_nm) > 100) { $tr_bad[] = mb_substr($tr_nm, 0, 30) . '…'; continue; }
                    $tr_key = lh_name_norm($tr_nm);
                    if (!isset($tr_people[$tr_key])) { $tr_people[$tr_key] = $tr_nm; }
                }
                if ($tr_bad) {
                    $_SESSION['flash_message'] = 'Jméno je delší než 100 znaků (nic se neuložilo): ' . implode(', ', array_slice($tr_bad, 0, 3));
                    $_SESSION['flash_type'] = 'danger';
                } elseif (empty($tr_people)) {
                    $_SESSION['flash_message'] = 'Zadej aspoň jednu osobu.';
                    $_SESSION['flash_type'] = 'danger';
                } elseif (count($tr_people) > 300) {
                    $_SESSION['flash_message'] = 'Najednou lze zadat nejvýše 300 osob.';
                    $_SESSION['flash_type'] = 'danger';
                } else {
                    $tr_have = [];
                    foreach ($trusted_names as $tr_ex) { $tr_have[lh_name_norm($tr_ex)] = true; }
                    $tr_new = 0; $tr_old = 0; $tr_added = [];
                    $stmt = $pdo->prepare("INSERT IGNORE INTO trusted_persons (person_name) VALUES (?)");
                    foreach ($tr_people as $tr_nm) {
                        $tr_store = wit_lookup_canonical($pdo, $tr_nm) ?? $tr_nm;   // jméno se sjednotí s docházkou a aliasy
                        $tr_k2 = lh_name_norm($tr_store);
                        if (isset($tr_have[$tr_k2])) { $tr_old++; continue; }
                        $stmt->execute([$tr_store]);
                        if ($stmt->rowCount() > 0) { $tr_new++; $tr_have[$tr_k2] = true; $tr_added[] = $tr_store; } else { $tr_old++; }
                    }
                    $_POST['osoby'] = $tr_added ? implode(', ', array_slice($tr_added, 0, 20)) . (count($tr_added) > 20 ? ', …' : '') : '(žádné nové)';
                    $tr_msg = $tr_new === 0 ? 'Žádná nová osoba nebyla přidána' : 'Do seznamu Důvěryhodní ' . ($tr_new === 1 ? 'byla přidána 1 osoba' : ($tr_new < 5 ? 'byly přidány ' . $tr_new . ' osoby' : 'bylo přidáno ' . $tr_new . ' osob'));
                    $_SESSION['flash_message'] = $tr_msg . ($tr_old > 0 ? ' (' . $tr_old . ' už v seznamu bylo)' : '') . '.';
                    $_SESSION['flash_type'] = $tr_new > 0 ? 'success' : 'info';
                }
                break;

            case 'trusted_delete':
                $st = $pdo->prepare("DELETE FROM trusted_persons WHERE person_name = ?");
                $st->execute([(string)($_POST['person_name'] ?? '')]);
                $_SESSION['flash_message'] = $st->rowCount() > 0 ? 'Osoba byla odebrána ze seznamu Důvěryhodní.' : 'Osoba v seznamu není.';
                $_SESSION['flash_type'] = $st->rowCount() > 0 ? 'success' : 'danger';
                break;

            case 'alias_merge':
                $am_canon = trim(preg_replace('/\s+/u', ' ', (string)($_POST['canonical'] ?? '')));
                $am_in = [];
                foreach (preg_split('/\r\n|\r|\n/', (string)($_POST['aliases'] ?? '')) as $am_ln) { $am_a = trim(preg_replace('/\s+/u', ' ', $am_ln)); if ($am_a !== '') { $am_in[$am_a] = true; } }
                $am_aliases = array_keys($am_in);
                $am_err = '';
                if ($am_canon === '' || mb_strlen($am_canon) > 100) { $am_err = 'Zadej hlavní jméno (nejvýše 100 znaků).'; }
                elseif (!$am_aliases) { $am_err = 'Zadej aspoň jeden alias (jeden na řádek).'; }
                elseif (count($am_aliases) > 20) { $am_err = 'Najednou lze sloučit nejvýše 20 aliasů.'; }
                else {
                    foreach ($am_aliases as $am_a) { if (mb_strlen($am_a) > 150) { $am_err = 'Alias může mít nejvýše 150 znaků.'; } }
                    $am_canon = lh_alias_apply($pdo, $am_canon);   // hlavní jméno, které je samo alias, se nahradí svým hlavním jménem
                    $am_aliases = array_values(array_filter($am_aliases, function ($a) use ($am_canon) { return strcmp($a, $am_canon) !== 0; }));
                    if ($am_err === '' && !$am_aliases) { $am_err = 'Alias je shodný s hlavním jménem, není co sloučit.'; }
                }
                if ($am_err !== '') {
                    $_SESSION['flash_message'] = $am_err;
                    $_SESSION['flash_type'] = 'danger';
                } else {
                    try {
                        $pdo->beginTransaction();
                        $am_tot = []; $am_dups = 0;
                        foreach ($am_aliases as $am_a) {
                            $r_am = lh_alias_merge_one($pdo, $am_canon, $am_a);
                            foreach ($r_am['moved'] as $t_am => $n_am) { $am_tot[$t_am] = ($am_tot[$t_am] ?? 0) + $n_am; }
                            $am_dups += $r_am['dups'];
                            $st = $pdo->prepare("INSERT INTO name_aliases (alias, canonical) VALUES (?, ?) ON DUPLICATE KEY UPDATE canonical = VALUES(canonical)");
                            $st->execute([$am_a, $am_canon]);
                            $st = $pdo->prepare("UPDATE name_aliases SET canonical = ? WHERE BINARY canonical = BINARY ? AND BINARY alias <> BINARY ?");   // aby nevznikaly řetězce alias → alias
                            $st->execute([$am_canon, $am_a, $am_a]);
                        }
                        $pdo->commit();
                        lh_alias_map($pdo, true);
                        $am_names = ['turnstile_logs' => 'průchody', 'sightings' => 'pozorování', 'incident_innocents' => 'nevinnosti', 'employees' => 'zaměstnanci', 'blacklisted_suspects' => 'vyřazení', 'trusted_persons' => 'důvěryhodní', 'milestones' => 'milníky', 'poznatky' => 'poznatky', 'it_staff' => 'členové IT'];
                        $am_parts = [];
                        foreach ($am_tot as $t_am => $n_am) { $am_parts[] = ($am_names[$t_am] ?? $t_am) . ' ' . $n_am; }
                        $_SESSION['flash_message'] = 'Sloučeno ' . count($am_aliases) . ' ' . (count($am_aliases) === 1 ? 'alias' : 'aliasů') . ' do „' . $am_canon . '“. Přepsáno: ' . ($am_parts ? implode(', ', $am_parts) : 'nic (jména v datech nebyla)') . ($am_dups > 0 ? '; odstraněno duplicit: ' . $am_dups : '') . '.';
                        $_SESSION['flash_type'] = 'success';
                    } catch (RuntimeException $e) {
                        if ($pdo->inTransaction()) { $pdo->rollBack(); }
                        $_SESSION['flash_message'] = $e->getMessage() . ' Nic nebylo změněno.';
                        $_SESSION['flash_type'] = 'danger';
                    } catch (PDOException $e) {
                        if ($pdo->inTransaction()) { $pdo->rollBack(); }
                        $_SESSION['flash_message'] = 'Sloučení selhalo, nic nebylo změněno.';
                        $_SESSION['flash_type'] = 'danger';
                    }
                }
                break;

            case 'alias_delete':
                $st = $pdo->prepare("DELETE FROM name_aliases WHERE id = ?");
                $st->execute([(int)($_POST['id'] ?? 0)]);
                $_SESSION['flash_message'] = $st->rowCount() > 0 ? 'Alias byl odebrán (jména už přepsaná v datech zůstávají; pravidlo se jen přestane používat při dalším zadávání).' : 'Alias neexistuje.';
                $_SESSION['flash_type'] = $st->rowCount() > 0 ? 'success' : 'danger';
                break;

            case 'poznatek_edit':
                $pe_id = (int)($_POST['id'] ?? 0);
                $pe_reporter = trim((string)($_POST['reporter'] ?? ''));
                $pe_person = trim(preg_replace('/\s+/u', ' ', (string)($_POST['person_name'] ?? '')));
                $pe_text = trim(str_replace("\r\n", "\n", (string)($_POST['poznatek'] ?? '')));
                $pe_when = trim((string)($_POST['happened_at'] ?? ''));
                $pe_ts = $pe_when === '' ? null : strtotime($pe_when);
                if ($pe_reporter === '' || mb_strlen($pe_reporter) > 100 || $pe_text === '' || mb_strlen($pe_text) > 500 || mb_strlen($pe_person) > 150
                    || ($pe_when !== '' && ($pe_ts === false || (int)date('Y', $pe_ts) < 2000 || (int)date('Y', $pe_ts) > 2100))) {
                    $_SESSION['flash_message'] = 'Neplatné údaje poznatku!';
                    $_SESSION['flash_type'] = 'danger';
                } else {
                    $canon = $pe_person !== '' ? wit_lookup_canonical($pdo, $pe_person) : null;
                    $st = $pdo->prepare("UPDATE poznatky SET reporter = ?, person_name = ?, happened_at = ?, poznatek = ? WHERE id = ?");
                    $st->execute([$pe_reporter, $pe_person !== '' ? ($canon ?? $pe_person) : null, $pe_ts !== null ? date('Y-m-d H:i:s', $pe_ts) : null, $pe_text, $pe_id]);
                    $_POST['poznatek_id'] = '#' . $pe_id;
                    $_SESSION['flash_message'] = 'Poznatek byl upraven.';
                    $_SESSION['flash_type'] = 'success';
                }
                break;

            case 'poznatek_delete':
                $st = $pdo->prepare("DELETE FROM poznatky WHERE id = ?");
                $st->execute([(int)($_POST['id'] ?? 0)]);
                $_POST['poznatek_id'] = '#' . (int)($_POST['id'] ?? 0);
                $_SESSION['flash_message'] = $st->rowCount() > 0 ? 'Poznatek byl smazán.' : 'Poznatek neexistuje.';
                $_SESSION['flash_type'] = $st->rowCount() > 0 ? 'success' : 'danger';
                break;

            case 'milestone_save':
                $ms_id = (int)($_POST['id'] ?? 0);
                $ms_kind = (string)($_POST['kind'] ?? '');
                $ms_person = trim(preg_replace('/\s+/u', ' ', (string)($_POST['person_name'] ?? '')));
                $ms_from = (string)($_POST['date_from'] ?? '');
                $ms_to = (string)($_POST['date_to'] ?? '');
                $ms_title = trim(preg_replace('/\s+/u', ' ', (string)($_POST['title'] ?? '')));
                $ms_note = trim((string)($_POST['note'] ?? ''));
                $ms_valid_date = function ($d) { return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $mm) && checkdate((int)$mm[2], (int)$mm[3], (int)$mm[1]); };
                $ms_err = '';
                if (!isset(lh_ms_kinds()[$ms_kind])) { $ms_err = 'Neplatný typ milníku.'; }
                elseif (!$ms_valid_date($ms_from)) { $ms_err = 'Zadej platné datum.'; }
                elseif (lh_ms_is_person($ms_kind) && ($ms_person === '' || mb_strlen($ms_person) > 100)) { $ms_err = 'Zadej osobu (nejvýše 100 znaků).'; }
                elseif ($ms_kind === 'away' && (!$ms_valid_date($ms_to) || $ms_to < $ms_from)) { $ms_err = 'U období „pryč“ zadej konec, který není před začátkem.'; }
                elseif (!lh_ms_is_person($ms_kind) && ($ms_title === '' || mb_strlen($ms_title) > 120)) { $ms_err = 'Zadej název milníku (1 až 120 znaků).'; }
                elseif (mb_strlen($ms_title) > 120) { $ms_err = 'Název může mít nejvýše 120 znaků.'; }
                elseif (mb_strlen($ms_note) > 500) { $ms_err = 'Poznámka může mít nejvýše 500 znaků.'; }
                elseif ($ms_id > 0 && !in_array($ms_id, array_map('intval', array_column($milestones, 'id')), true)) { $ms_err = 'Milník neexistuje.'; }
                elseif ($ms_id === 0 && count($milestones) >= 500) { $ms_err = 'Je dosažen nejvyšší počet milníků (500).'; }
                if ($ms_err !== '') {
                    $_SESSION['flash_message'] = $ms_err;
                    $_SESSION['flash_type'] = 'danger';
                } else {
                    if (lh_ms_is_person($ms_kind)) { $ms_person = wit_lookup_canonical($pdo, $ms_person) ?? $ms_person; } else { $ms_person = ''; }
                    $ms_to_db = $ms_kind === 'away' ? $ms_to : null;
                    if ($ms_id > 0) {
                        $stmt = $pdo->prepare("UPDATE milestones SET kind = ?, person_name = ?, date_from = ?, date_to = ?, title = ?, note = ? WHERE id = ?");
                        $stmt->execute([$ms_kind, $ms_person !== '' ? $ms_person : null, $ms_from, $ms_to_db, $ms_title, $ms_note !== '' ? $ms_note : null, $ms_id]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO milestones (kind, person_name, date_from, date_to, title, note) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$ms_kind, $ms_person !== '' ? $ms_person : null, $ms_from, $ms_to_db, $ms_title, $ms_note !== '' ? $ms_note : null]);
                    }
                    $_POST['milnik'] = lh_ms_kinds()[$ms_kind] . ' ' . date('d-m-Y', strtotime($ms_from)) . ($ms_person !== '' ? ' (' . $ms_person . ')' : '');
                    $_SESSION['flash_message'] = 'Milník byl uložen.';
                    $_SESSION['flash_type'] = 'success';
                }
                break;

            case 'milestone_delete':
                $ms_id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("DELETE FROM milestones WHERE id = ?");
                $stmt->execute([$ms_id]);
                $_SESSION['flash_message'] = $stmt->rowCount() > 0 ? 'Milník byl smazán.' : 'Milník neexistuje.';
                $_SESSION['flash_type'] = $stmt->rowCount() > 0 ? 'success' : 'danger';
                break;

            case 'milestone_grant':
                // Nevinnost osobám, které byly podle milníků mimo firmu po celé okno incidentu (vybraným nebo všem). Seznam se vždy znovu spočítá na serveru.
                $mg_rid = (int)($_POST['report_id'] ?? 0);
                $mg_inc = null;
                foreach ($incidents as $mg_i) { if ((int)$mg_i['id'] === $mg_rid) { $mg_inc = $mg_i; break; } }
                if (!$innocent_ok) {
                    $_SESSION['flash_message'] = 'Chybí tabulka incident_innocents – spusť SQL skript nevinni.sql.';
                    $_SESSION['flash_type'] = 'danger';
                } elseif ($mg_inc === null) {
                    $_SESSION['flash_message'] = 'Incident neexistuje.';
                    $_SESSION['flash_type'] = 'danger';
                } else {
                    $mg_out = lh_ms_out_persons($milestones, $mg_inc);
                    $mg_have = [];
                    foreach ($incident_innocents[$mg_rid] ?? [] as $mg_r) { $mg_have[lh_name_norm($mg_r['person_name'])] = true; }
                    $mg_all = !empty($_POST['all']);
                    $mg_sel = array_map('strval', (array)($_POST['persons'] ?? []));
                    $mg_targets = [];
                    foreach ($mg_out as $mg_name => $mg_why) {
                        if (isset($mg_have[lh_name_norm($mg_name)])) { continue; }
                        if ($mg_all || in_array($mg_name, $mg_sel, true)) { $mg_targets[$mg_name] = $mg_why; }
                    }
                    if (!$mg_targets) {
                        $_SESSION['flash_message'] = 'Není komu nevinnost udělit (nikdo nevybrán nebo už ji mají).';
                        $_SESSION['flash_type'] = 'warning';
                    } else {
                        $pdo->beginTransaction();
                        $stmt = $pdo->prepare("INSERT IGNORE INTO incident_innocents (report_id, person_name, reason) VALUES (?, ?, ?)");
                        $mg_n = 0; $mg_done = [];
                        foreach ($mg_targets as $mg_name => $mg_why) {
                            $m0 = $mg_why[0];
                            $reason = 'mimo firmu (' . ($m0['kind'] === 'left' ? 'poslední den v práci ' . date('d-m-Y', strtotime($m0['date_from'])) : ($m0['kind'] === 'joined' ? 'první den v práci ' . date('d-m-Y', strtotime($m0['date_from'])) : 'pryč ' . date('d-m-Y', strtotime($m0['date_from'])) . ' až ' . date('d-m-Y', strtotime($m0['date_to'] ?: $m0['date_from'])))) . ')';
                            $stmt->execute([$mg_rid, $mg_name, mb_substr($reason, 0, 200)]);
                            $mg_n += $stmt->rowCount(); $mg_done[] = $mg_name;
                        }
                        $pdo->commit();
                        $_POST['incident'] = '#' . ($incident_no[$mg_rid] ?? 0);
                        $_POST['osoby'] = count($mg_done) <= 5 ? implode(', ', $mg_done) : count($mg_done) . ' osob (' . implode(', ', array_slice($mg_done, 0, 5)) . ', …)';
                        $_SESSION['flash_message'] = ($mg_n === 1 ? 'Nevinnost byla udělena 1 osobě' : 'Nevinnost byla udělena ' . $mg_n . ' osobám') . ' u incidentu #' . ($incident_no[$mg_rid] ?? 0) . ' (mimo firmu).';
                        $_SESSION['flash_type'] = 'success';
                    }
                }
                break;

            case 'label_save':
                $lb_id = (int)($_POST['id'] ?? 0);
                $lb_name = trim(preg_replace('/\s+/', ' ', (string)($_POST['name'] ?? '')));
                $lb_color = (string)($_POST['color'] ?? '');
                if ($lb_name === '' || mb_strlen($lb_name) > 40) {
                    $_SESSION['flash_message'] = 'Název štítku musí mít 1 až 40 znaků.';
                    $_SESSION['flash_type'] = 'danger';
                } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $lb_color)) {
                    $_SESSION['flash_message'] = 'Neplatná barva štítku.';
                    $_SESSION['flash_type'] = 'danger';
                } elseif ($lb_id > 0 && !isset($inc_labels[$lb_id])) {
                    $_SESSION['flash_message'] = 'Štítek neexistuje.';
                    $_SESSION['flash_type'] = 'danger';
                } elseif (count($inc_labels) >= 50 && $lb_id === 0) {
                    $_SESSION['flash_message'] = 'Je dosažen nejvyšší počet štítků (50).';
                    $_SESSION['flash_type'] = 'danger';
                } else {
                    try {
                        if ($lb_id > 0) {
                            $stmt = $pdo->prepare("UPDATE incident_labels SET name = ?, color = ? WHERE id = ?");
                            $stmt->execute([$lb_name, strtolower($lb_color), $lb_id]);
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO incident_labels (name, color) VALUES (?, ?)");
                            $stmt->execute([$lb_name, strtolower($lb_color)]);
                        }
                        $_SESSION['flash_message'] = 'Štítek „' . $lb_name . '“ byl uložen.';
                        $_SESSION['flash_type'] = 'success';
                    } catch (PDOException $e) {
                        $_SESSION['flash_message'] = 'Štítek s tímto názvem už existuje.';
                        $_SESSION['flash_type'] = 'danger';
                    }
                }
                break;

            case 'label_delete':
                $lb_id = (int)($_POST['id'] ?? 0);
                if (!isset($inc_labels[$lb_id])) {
                    $_SESSION['flash_message'] = 'Štítek neexistuje.';
                    $_SESSION['flash_type'] = 'danger';
                } else {
                    $stmt = $pdo->prepare("DELETE FROM incident_labels WHERE id = ?");
                    $stmt->execute([$lb_id]);
                    $_SESSION['flash_message'] = 'Štítek „' . $inc_labels[$lb_id]['name'] . '“ byl smazán (i u incidentů).';
                    $_SESSION['flash_type'] = 'success';
                }
                break;

            case 'incident_labels_set':
                $rid = (int)($_POST['report_id'] ?? 0);
                $known = false;
                foreach ($incidents as $ii) { if ((int)$ii['id'] === $rid) { $known = true; break; } }
                if (!$known) {
                    $_SESSION['flash_message'] = 'Incident neexistuje.';
                    $_SESSION['flash_type'] = 'danger';
                } else {
                    $want = [];
                    foreach ((array)($_POST['labels'] ?? []) as $lid) { if (isset($inc_labels[(int)$lid])) { $want[(int)$lid] = true; } }
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("DELETE FROM incident_label_map WHERE report_id = ?");
                    $stmt->execute([$rid]);
                    $ins = $pdo->prepare("INSERT INTO incident_label_map (report_id, label_id) VALUES (?, ?)");
                    foreach (array_keys($want) as $lid) { $ins->execute([$rid, $lid]); }
                    $pdo->commit();
                    $_POST['incident'] = '#' . ($incident_no[$rid] ?? '?');
                    $_POST['stitky'] = implode(', ', array_map(function ($lid) use ($inc_labels) { return $inc_labels[$lid]['name']; }, array_keys($want)));
                    $_SESSION['flash_message'] = 'Štítky incidentu #' . ($incident_no[$rid] ?? '?') . ' byly uloženy.';
                    $_SESSION['flash_type'] = 'success';
                }
                break;

            case 'incident_announce':
                $rid = (int)($_POST['report_id'] ?? 0);
                $target = null;
                foreach ($incidents as $ii) { if ((int)$ii['id'] === $rid) { $target = $ii; break; } }
                $to_list = lh_mail_list($pdo, 'mail_digest');
                $ann_err = '';
                if (!$target) { $ann_err = 'Incident neexistuje.'; }
                elseif (empty($target['counted'])) { $ann_err = 'Incident se nepočítá, oznámení nejde poslat.'; }
                elseif (isset($incident_announced[$rid])) { $ann_err = 'Incident už byl oznámen.'; }
                elseif (!lh_mail_configured($smtp)) { $ann_err = 'SMTP není nastaveno v lh_config.php.'; }
                elseif (empty($to_list)) { $ann_err = 'Seznam příjemců týdenního souhrnu je prázdný.'; }
                if ($ann_err !== '') {
                    $_SESSION['flash_message'] = $ann_err;
                    $_SESSION['flash_type'] = 'danger';
                } else {
                    list($ann_subject, $ann_body) = lh_incident_notice($target, $cubicles_list, $app_url);
                    $errs = lh_mail_send_all($smtp, $to_list, $ann_subject, $ann_body);
                    if (count($errs) < count($to_list)) {
                        $stmt = $pdo->prepare("INSERT INTO incident_announced (report_id, announced_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE announced_at = announced_at");
                        $stmt->execute([$rid]);
                        $_SESSION['flash_message'] = 'Incident #' . ($incident_no[$rid] ?? '?') . ' byl oznámen (' . (count($to_list) - count($errs)) . ' příjemcům)' . ($errs ? '. Nedoručeno: ' . count($errs) . ' (' . $errs[0] . ')' : '.');
                        $_SESSION['flash_type'] = $errs ? 'warning' : 'success';
                    } else {
                        $_SESSION['flash_message'] = 'Oznámení se nepodařilo odeslat: ' . $errs[0];
                        $_SESSION['flash_type'] = 'danger';
                    }
                }
                break;

            case 'incident_set_counted':
                $rid = (int)($_POST['report_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT 1 FROM toilet_reports WHERE id = ? AND status = 'zesrana'");
                $stmt->execute([$rid]);
                if (!$stmt->fetchColumn()) {
                    $_SESSION['flash_message'] = 'Incident neexistuje.';
                    $_SESSION['flash_type'] = 'danger';
                } elseif (($_POST['counted'] ?? '1') === '1') {
                    $stmt = $pdo->prepare("DELETE FROM incident_ignored WHERE report_id = ?");
                    $stmt->execute([$rid]);
                    $_SESSION['flash_message'] = 'Incident #' . ($incident_no[$rid] ?? '?') . ' se zase počítá.';
                    $_SESSION['flash_type'] = 'success';
                } else {
                    $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 150);
                    $stmt = $pdo->prepare("INSERT INTO incident_ignored (report_id, reason) VALUES (?, ?) ON DUPLICATE KEY UPDATE reason = VALUES(reason)");
                    $stmt->execute([$rid, $reason !== '' ? $reason : null]);
                    $_SESSION['flash_message'] = 'Incident #' . ($incident_no[$rid] ?? '?') . ' se přestal počítat (zůstává vidět v přehledu).';
                    $_SESSION['flash_type'] = 'success';
                }
                break;

            case 'it_set_until':
            case 'it_set_until_all':
                $until = trim($_POST['virtual_until'] ?? '');
                if ($until !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) {
                    $_SESSION['flash_message'] = 'Neplatné datum!';
                    $_SESSION['flash_type'] = 'danger';
                } elseif ($_POST['action'] === 'it_set_until_all') {
                    $stmt = $pdo->prepare("UPDATE it_staff SET virtual_until = ?");
                    $stmt->execute([$until === '' ? null : $until]);
                    $_SESSION['flash_message'] = $until === '' ? 'Virtuální body IT jsou bez omezení.' : "Virtuální body všech členů IT se přidělují jen u incidentů před " . lh_d($until) . ".";
                    $_SESSION['flash_type'] = 'success';
                } else {
                    $stmt = $pdo->prepare("UPDATE it_staff SET virtual_until = ? WHERE id = ?");
                    $stmt->execute([$until === '' ? null : $until, (int)($_POST['id'] ?? 0)]);
                    $_SESSION['flash_message'] = 'Datum ukončení virtuálních bodů bylo uloženo.';
                    $_SESSION['flash_type'] = 'success';
                }
                break;

            case 'it_remove_staff':
                $stmt = $pdo->prepare("DELETE FROM it_staff WHERE id = ?");
                $stmt->execute([(int)($_POST['id'] ?? 0)]);
                $_SESSION['flash_message'] = 'Člen IT byl odebrán (včetně jeho dovolených).';
                $_SESSION['flash_type'] = 'success';
                break;

            case 'it_add_vacation':
                $staff_id = (int)($_POST['staff_id'] ?? 0);
                $date_from = $_POST['date_from'] ?? '';
                $date_to = ($_POST['date_to'] ?? '') !== '' ? $_POST['date_to'] : $date_from;

                if ($staff_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to) || $date_to < $date_from) {
                    $_SESSION['flash_message'] = 'Neplatná data dovolené!';
                    $_SESSION['flash_type'] = 'danger';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO it_vacations (staff_id, date_from, date_to) VALUES (?, ?, ?)");
                    $stmt->execute([$staff_id, $date_from, $date_to]);
                    $_SESSION['flash_message'] = 'Dovolená byla uložena.';
                    $_SESSION['flash_type'] = 'success';
                }
                break;

            case 'it_delete_vacation':
                $stmt = $pdo->prepare("DELETE FROM it_vacations WHERE id = ?");
                $stmt->execute([(int)($_POST['id'] ?? 0)]);
                $_SESSION['flash_message'] = 'Dovolená byla smazána.';
                $_SESSION['flash_type'] = 'success';
                break;
        }
        // Externí záznam akce (e-mail adminům), jen pro úspěšné změny
        // (vypnutí anonymizace se hlásí varováním, ale změna proběhla, takže se zapisuje také)
        if ((($_SESSION['flash_type'] ?? '') === 'success' || (($_SESSION['flash_type'] ?? '') === 'warning' && $_POST['action'] === 'set_pseudonymize')) && !in_array($_POST['action'], ['mail_test', 'digest_now', 'digest_test'], true)) {
            $audit_labels = [
                'edit_report' => 'Úprava hlášení', 'delete_report' => 'Smazání hlášení', 'blacklist_suspect' => 'Vyřazení osoby ze žebříčku',
                'restore_suspect' => 'Vrácení osoby do žebříčku', 'it_add_staff' => 'Přidání člena IT', 'it_remove_staff' => 'Odebrání člena IT',
                'it_set_until' => 'Datum konce virtuálních bodů (člen IT)', 'it_set_until_all' => 'Datum konce virtuálních bodů (všichni)',
                'it_add_vacation' => 'Přidání dovolené IT', 'it_delete_vacation' => 'Smazání dovolené IT', 'sighting_approve' => 'Schválení pozorování',
                'sighting_reject' => 'Zamítnutí pozorování', 'sighting_delete' => 'Smazání pozorování', 'sighting_edit' => 'Úprava pozorování', 'set_collection' => 'Zapnutí nebo vypnutí sběru dat', 'culprit_set' => 'Označení pachatele', 'culprit_clear' => 'Zrušení označení pachatele', 'culprit_notify' => 'Oznámení o dopadení pachatele', 'set_ro_sections' => 'Nastavení viditelnosti náhledové verze', 'sighting_rename' => 'Přejmenování osoby v pozorování',
                'incident_set_counted' => 'Změna počítání incidentu', 'incident_announce' => 'Oznámení incidentu všem', 'label_save' => 'Uložení štítku', 'label_delete' => 'Smazání štítku', 'incident_labels_set' => 'Nastavení štítků incidentu', 'trusted_add' => 'Přidání důvěryhodných osob', 'trusted_delete' => 'Odebrání důvěryhodné osoby', 'alias_merge' => 'Sloučení aliasů jmen', 'alias_delete' => 'Odebrání aliasu jména', 'poznatek_edit' => 'Úprava poznatku', 'poznatek_delete' => 'Smazání poznatku', 'milestone_save' => 'Uložení milníku', 'milestone_delete' => 'Smazání milníku', 'milestone_grant' => 'Nevinnost osobám mimo firmu (milník)', 'innocent_add' => 'Označení osoby jako nevinné', 'innocent_delete' => 'Zrušení označení nevinné osoby', 'employees_save' => 'Uložení seznamu zaměstnanců', 'set_admin_notify' => 'Změna nastavení upozornění', 'set_pseudonymize' => 'Změna anonymizace v náhledové verzi', 'set_tolerance' => 'Změna tolerance', 'set_setting' => 'Změna nastavení', 'set_mail_lists' => 'Změna e-mailových seznamů',
            ];
            lh_audit($pdo, $smtp, $audit_labels[$_POST['action']] ?? $_POST['action'], $_POST, $audit_before, (string)($_SESSION['flash_message'] ?? ''), $client_ip, $device_id, $audit_user, lh_audit_group($_POST['action']));
        }
    }

    header('Location: index.php');
    exit;
}

// Fronta pozorování pro admina (čekající + posledních 15 vyřízených)
$wit_pending = [];
$wit_recent = [];
if ($is_admin && $wit_tables_ok) {
    $wit_pending = $pdo->query("SELECT * FROM sightings WHERE status = 'pending' ORDER BY seen_at DESC")->fetchAll();
    $wit_recent = $pdo->query("SELECT * FROM sightings WHERE status <> 'pending' ORDER BY decided_at DESC, id DESC LIMIT 15")->fetchAll();
}

// Rozpory v nevinnostech (jen pro admina)
$inn_conflicts = [];
if ($is_admin && $innocent_ok && !empty($incident_innocents) && !isset($_GET['action'])) {
    try { $inn_conflicts = lh_innocent_conflicts($pdo, $incidents, $incident_innocents, $incident_no, $tolerance_minutes, $turnstiles_list); } catch (PDOException $e) { /* bez docházky se nevyhodnotí */ }
}
$inn_conflict_ids = array_flip(array_column($inn_conflicts, 'id'));

// Příchod po incidentu (data pro prohlížeč; počítá se se skutečnými jmény, pseudonymizace následuje níže)
$arrival_data = ['tol' => (int)$tolerance_minutes, 'inc' => [], 'p' => []];
if (!empty($suspects) && !isset($_GET['action'])) {
    try { $arrival_data = lh_arrival_data($pdo, $incidents_for_analysis, $incident_no, $tolerance_minutes, $employee_names, $blacklisted); } catch (PDOException $e) { /* bez docházky se sekce nezobrazí */ }
}

$absence_data = ['days' => [], 'p' => [], 'pres' => [], 'inc' => []];
if (!empty($suspects) && !isset($_GET['action'])) {
    try { $absence_data = lh_absence_data($pdo, $incidents_for_analysis, $incident_no, $employee_names, $blacklisted); } catch (PDOException $e) { /* bez docházky se sekce nezobrazí */ }
}

// Náhledová (jen čtecí) verze: jména osob a hlásících se nahradí stálými pseudonymy (stejná osoba = stejný kód); výpočty se tím nemění (jde vypnout v administraci)
if (lh_pseudo_enabled($pdo)) {
    foreach ($absence_data['p'] as $k_ab => $n_ab) { $absence_data['p'][$k_ab] = lh_pseudo_name($n_ab); }
    foreach ($trusted_names as $k_tr => $n_tr) { $trusted_names[$k_tr] = lh_pseudo_name($n_tr); }   // důvěryhodní v náhledu jako pseudonymy
    if ($culprit_name !== '') { $culprit_name = lh_pseudo_name($culprit_name); }   // označený pachatel v náhledu jako pseudonym
    foreach ($arrival_data['p'] as $k_ar => $p_ar) { $arrival_data['p'][$k_ar]['n'] = lh_pseudo_name($p_ar['n']); }
    foreach ($suspects as $k_ps => $s_ps) {
        $suspects[$k_ps]['name'] = lh_pseudo_name($s_ps['name']);
        foreach ($s_ps['details'] as $j_ps => $d_ps) {   // štítek „Nevinný“ nese důvod (volný text)
            if (!empty($d_ps['reason'])) { $suspects[$k_ps]['details'][$j_ps]['reason'] = lh_pseudo_text($d_ps['reason']); }
            foreach (($d_ps['witness'] ?? []) as $w_ps => $wi_ps) {   // autor pozorování (svědectví)
                if (!empty($wi_ps['reporter'])) { $suspects[$k_ps]['details'][$j_ps]['witness'][$w_ps]['reporter'] = lh_pseudo_name($wi_ps['reporter']); }
            }
        }
    }
    $inc_sets_ps = [&$incidents, &$incidents_counted, &$incidents_for_analysis];
    foreach ($inc_sets_ps as &$set_ps) {
        foreach ($set_ps as $k_ps => $i_ps) {
            foreach (['last_ok_by', 'dirty_reported_by'] as $f_ps) { if (isset($i_ps[$f_ps])) { $set_ps[$k_ps][$f_ps] = lh_pseudo_name($i_ps[$f_ps]); } }
            foreach (['ok_note', 'dirty_note', 'ignore_reason'] as $f_ps) { if (!empty($i_ps[$f_ps])) { $set_ps[$k_ps][$f_ps] = lh_pseudo_text($i_ps[$f_ps]); } }
        }
    }
    unset($set_ps, $inc_sets_ps);
    foreach ($incident_people as $k_ps => $list_ps) {
        foreach ($list_ps as $j_ps => $p_ps) {
            $incident_people[$k_ps][$j_ps]['name'] = lh_pseudo_name($p_ps['name']);
            foreach (($p_ps['witness'] ?? []) as $w_ps => $wi_ps) {   // autor pozorování (svědectví)
                if (!empty($wi_ps['reporter'])) { $incident_people[$k_ps][$j_ps]['witness'][$w_ps]['reporter'] = lh_pseudo_name($wi_ps['reporter']); }
            }
        }
    }
    foreach ($incident_wit_extra as $k_ps => $list_ps) {
        foreach ($list_ps as $j_ps => $p_ps) {
            $incident_wit_extra[$k_ps][$j_ps]['name'] = lh_pseudo_name($p_ps['name']);
            foreach (($p_ps['items'] ?? []) as $w_ps => $wi_ps) {
                if (!empty($wi_ps['reporter'])) { $incident_wit_extra[$k_ps][$j_ps]['items'][$w_ps]['reporter'] = lh_pseudo_name($wi_ps['reporter']); }
            }
        }
    }
    $bl_new_ps = [];
    foreach ($blacklisted_with_points as $n_ps => $v_ps) { $bl_new_ps[lh_pseudo_name($n_ps)] = $v_ps; }
    $blacklisted_with_points = $bl_new_ps;
    $blacklisted = array_map('lh_pseudo_name', $blacklisted);
    sort($blacklisted, SORT_STRING);   // pořadí podle pseudonymů, aby neprozradilo začáteční písmena jmen
    foreach ($incident_innocents as $k_ps => $rows_ps) {
        foreach ($rows_ps as $j_ps => $r_ps) {
            $rows_ps[$j_ps]['person_name'] = lh_pseudo_name($r_ps['person_name']);
            if ((string)$r_ps['reason'] !== '') { $rows_ps[$j_ps]['reason'] = lh_pseudo_text($r_ps['reason']); }
        }
        usort($rows_ps, function ($a, $b) { return strcmp($a['person_name'], $b['person_name']); });
        $incident_innocents[$k_ps] = $rows_ps;
    }
    $employee_names = array_map('lh_pseudo_name', $employee_names);
    sort($employee_names, SORT_STRING);
}

// EXPORT DO CSV
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=incidenty_lord_hoven.csv');
    $output = fopen('php://output', 'w');
    fputs($output, $bom = chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, ['#', 'Kabinka', 'Od (poslední OK)', 'Poslední OK nahlásil', 'Do (první zesraná)', 'Zesranou nahlásil', 'Počítá se', 'Štítky'], ';');
    foreach ($incidents as $inc) {
        fputcsv($output, [
            $incident_no[(int)$inc['id']] ?? '',
            $cubicles_list[$inc['cubicle']] ?? strtoupper($inc['cubicle']),
            $inc['window_start'] ?? 'Neznámo',
            $inc['last_ok_by'],
            $inc['window_end'],
            $inc['dirty_reported_by'],
            !empty($inc['counted']) ? 'ano' : 'ne',
            implode(', ', array_map(function ($lid) use ($inc_labels) { return $inc_labels[$lid]['name']; }, array_values(array_filter($incident_label_ids[(int)$inc['id']] ?? [], function ($lid) use ($inc_labels) { return isset($inc_labels[$lid]); }))))
        ], ';');
    }
    fclose($output);
    exit;
}

$message = $_SESSION['flash_message'] ?? '';
$message_type = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// NAČTENÍ DASHBOARDU A HISTORIE
$current_status = [];
foreach (array_keys($cubicles_list) as $c) {
    $stmt = $pdo->prepare("SELECT * FROM toilet_reports WHERE cubicle = ? ORDER BY reported_at DESC LIMIT 1");
    $stmt->execute([$c]);
    $current_status[$c] = $stmt->fetch();
}

$filter_date = $_GET['date'] ?? '';
$filter_cubicle = $_GET['cubicle'] ?? '';
$filter_status = in_array($_GET['status'] ?? '', ['ok', 'zesrana'], true) ? $_GET['status'] : '';

// Při načtení stránky se vždy zobrazí výchozí počet hlášení
list($reports, $total_reports) = fetch_report_history($pdo, $filter_date, $filter_cubicle, $history_default_limit, $filter_status);
if (lh_pseudo_enabled($pdo)) {
    lh_pseudo_reports($reports);
    foreach ($current_status as $c_ps => $st_ps) {
        if ($st_ps) {
            $current_status[$c_ps]['reporter'] = lh_pseudo_name($st_ps['reporter']);
            if (!empty($st_ps['note'])) { $current_status[$c_ps]['note'] = lh_pseudo_text($st_ps['note']); }
        }
    }
}
ob_start('lh_csrf_inject');   // do všech formulářů POST se vloží token CSRF
?>
<!DOCTYPE html>
<html lang="cs" data-bs-theme="auto">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detektor Lorda Hoven</title>
    <link rel="icon" href="favicon.ico" sizes="any">
    <link href="assets/vendor/bootstrap.min.css?v=<?= htmlspecialchars($app_version) ?>" rel="stylesheet">
    <link href="assets/lh.css?v=<?= htmlspecialchars($app_version) ?>" rel="stylesheet">
    <script src="assets/lh-boot.js?v=<?= htmlspecialchars($app_version) ?>"></script>
</head>
<body class="py-4">
<div class="container">
    
    <div class="lh-appbar">
        <h1 class="lh-title">Detektor Lorda Hoven <span class="lh-version" title="Verze aplikace">v<?= htmlspecialchars($app_version) ?></span></h1>
        <?php if ($auth_user !== '' || !$readonly): ?>
        <div class="lh-auth">
            <?php if ($auth_user !== ''): ?>
                <span class="badge bg-secondary" title="Přihlášený uživatel (z přihlášení před aplikací)">Přihlášen: <?= htmlspecialchars($auth_display) ?></span>
                <?php if ($is_admin && !$readonly): ?><span class="badge bg-primary">Admin</span><?php endif; ?>
                <?php if ($auth_account_url !== ''): ?><a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars($auth_account_url) ?>" target="_blank" rel="noopener" title="Heslo, dvoufázové ověření, passkey">Můj účet</a><?php endif; ?>
                <?php if ($auth_logout_url !== ''): ?><a class="btn btn-outline-danger btn-sm" href="<?= htmlspecialchars($auth_logout_url) ?>">Odhlásit</a><?php endif; ?>
            <?php elseif ($is_admin && !$readonly): ?>
                <span class="badge bg-primary">Admin</span>
                <form method="POST" class="m-0">
                    <input type="hidden" name="action" value="admin_logout">
                    <button type="submit" class="btn btn-outline-danger btn-sm">Odhlásit</button>
                </form>
            <?php elseif (!$readonly): ?>
                <details class="lh-login">
                    <summary class="btn btn-outline-secondary btn-sm">Admin</summary>
                    <form method="POST" class="d-flex gap-1 mt-1">
                        <input type="hidden" name="action" value="admin_login">
                        <input type="password" name="admin_password" class="form-control form-control-sm" placeholder="Heslo admina" aria-label="Heslo admina" autocomplete="current-password" <?= $ip_login_ok ? '' : 'required' ?>>
                        <button type="submit" class="btn btn-primary btn-sm">Přihlásit</button>
                    </form>
                </details>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (is_file(__DIR__ . '/vizualizace.php') && !$ro_no['timeline']): ?>
            <a class="btn btn-outline-secondary btn-sm lh-iconbtn" href="vizualizace.php" title="Časová osa všech událostí (jen čtení)"><span>Časová osa</span></a>
        <?php endif; ?>
        <button id="theme-toggle" class="btn btn-outline-secondary btn-sm lh-iconbtn">
            <span id="theme-icon"><?= lh_icon('moon', 18) ?></span>
            <span id="theme-text">Tmavý režim</span>
        </button>
    </div>

    <?php
        // Počítadlo dnů od posledního incidentu: nejnovější incident, který se počítá (vyřazené adminem ne); dny kalendářně, 0 = dnes
        $since_inc = null;
        foreach ($incidents as $since_i) { if (!empty($since_i['counted'])) { $since_inc = $since_i; break; } }   // $incidents je od nejnovějšího
        $since_days = null;
        if ($since_inc) {
            $since_diff = (new DateTime(date('Y-m-d')))->diff(new DateTime(substr($since_inc['window_end'], 0, 10)));
            $since_days = $since_diff->invert ? $since_diff->days : 0;   // incident v budoucnosti (rozdíl hodin mezi zařízeními) se bere jako dnes
        }
    ?>
    <div class="mb-3">
    <div class="small text-body-secondary" id="days-since">
        <?php if ($since_inc): ?>
            Dnů od posledního incidentu: <strong class="text-body"><?= (int)$since_days ?></strong>
            <span>(#<?= (int)($incident_no[(int)$since_inc['id']] ?? 0) ?> · <?= htmlspecialchars(date('d-m-Y H:i', strtotime($since_inc['window_end']))) ?> · <?= htmlspecialchars($cubicles_list[$since_inc['cubicle']] ?? $since_inc['cubicle']) ?>)</span>
        <?php else: ?>
            Zatím nebyl zaznamenán žádný incident.
        <?php endif; ?>
    </div>
    <div class="small text-body-secondary mt-1 d-none d-flex flex-wrap align-items-center gap-2 column-gap-3" id="days-stats"></div>
    </div>
    <?php
        $since_js = ['today' => date('Y-m-d'), 'inc' => []];
        foreach ($incidents_for_analysis as $si_j) {
            $since_js['inc'][] = ['id' => (int)$si_j['id'], 'no' => (int)($incident_no[(int)$si_j['id']] ?? 0), 'e' => $si_j['window_end'], 't' => date('d-m-Y H:i', strtotime($si_j['window_end'])),
                'c' => (string)($cubicles_list[$si_j['cubicle']] ?? $si_j['cubicle']), 'k' => !empty($si_j['counted']) ? 1 : 0];
        }
    ?>
    <script nonce="<?= htmlspecialchars($lh_nonce) ?>">window.LH_TRUSTED = <?= json_encode(array_values($trusted_names), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script nonce="<?= htmlspecialchars($lh_nonce) ?>">window.LH_SINCE = <?= json_encode($since_js, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>

    <?php if ($is_admin && $import_warning !== null): ?>
        <?php $iw = $import_warning; $iwc = $iw['chk']; $iw_ok = $iw['total'] - count($iwc['bad']); ?>
        <div class="alert alert-warning border border-3 border-warning shadow-sm mb-4" role="alert" id="import-warning">
            <div class="d-flex align-items-start gap-3">
                <div class="text-warning-emphasis flex-shrink-0 mt-1"><?= lh_icon('alert', 28) ?></div>
                <div class="flex-grow-1" style="min-width: 0;">
                    <h2 class="h5 fw-bold mb-1">Import docházky zastaven: nalezeny problémy</h2>
                    <p class="small mb-2"><strong>Nic zatím nebylo uloženo.</strong> Importuješ <?= (int)$iw['total'] ?> záznamů do turniketu <strong><?= htmlspecialchars($iw['turnstile']) ?></strong> za <strong><?= htmlspecialchars(date('d-m-Y', strtotime($iw['date']) ?: 0)) ?></strong> (čas serveru: <?= htmlspecialchars($iwc['now']) ?>).<?= $iw['invalid'] > 0 ? ' Navíc ' . (int)$iw['invalid'] . ' řádků nemá platný formát a při importu se přeskočí.' : '' ?></p>
                    <?php if (!empty($iwc['cross'])): ?>
                        <h3 class="h6 mt-3">Záznamy, které už existují u jiného turniketu (<?= count($iwc['cross']) ?>)</h3>
                        <p class="small mb-1">Stejný člověk, směr a čas už je uložený u jiného turniketu. Pravděpodobně je to chybný turniket nebo omylem vložený text.</p>
                        <div class="table-responsive"><table class="table table-sm table-warning align-middle mb-2">
                            <thead><tr><th>Jméno</th><th>Směr</th><th>Čas</th><th>Už existuje u</th></tr></thead>
                            <tbody><?php foreach ($iwc['cross'] as $ic): ?><tr><td><?= htmlspecialchars($ic['name']) ?></td><td><?= htmlspecialchars($ic['dir']) ?></td><td class="text-nowrap"><?= htmlspecialchars($ic['at']) ?></td><td><?= htmlspecialchars($ic['where']) ?></td></tr><?php endforeach; ?></tbody>
                        </table></div>
                    <?php endif; ?>
                    <?php if (!empty($iwc['future'])): ?>
                        <h3 class="h6 mt-3">Záznamy z budoucnosti (<?= count($iwc['future']) ?>)</h3>
                        <p class="small mb-1">Čas průchodu je pozdější než aktuální čas serveru. Docházka z budoucnosti nemůže existovat, zkontroluj datum a časy.</p>
                        <div class="table-responsive"><table class="table table-sm table-warning align-middle mb-2">
                            <thead><tr><th>Jméno</th><th>Směr</th><th>Čas</th><th>O kolik napřed</th></tr></thead>
                            <tbody><?php foreach ($iwc['future'] as $ifu): $ah = (int)$ifu['ahead']; ?><tr><td><?= htmlspecialchars($ifu['name']) ?></td><td><?= htmlspecialchars($ifu['dir']) ?></td><td class="text-nowrap"><?= htmlspecialchars($ifu['at']) ?></td><td class="text-nowrap"><?= $ah >= 86400 ? floor($ah / 86400) . ' d ' . floor(($ah % 86400) / 3600) . ' h' : ($ah >= 3600 ? floor($ah / 3600) . ' h ' . floor(($ah % 3600) / 60) . ' min' : max(1, (int)ceil($ah / 60)) . ' min') ?></td></tr><?php endforeach; ?></tbody>
                        </table></div>
                    <?php endif; ?>
                    <form method="POST" class="d-flex flex-wrap gap-2 mt-3">
                        <input type="hidden" name="action" value="import_logs">
                        <input type="hidden" name="log_date" value="<?= htmlspecialchars($iw['date']) ?>">
                        <input type="hidden" name="turnstile" value="<?= htmlspecialchars($iw['turnstile_key']) ?>">
                        <textarea name="raw_text" class="d-none"><?= htmlspecialchars($iw['raw']) ?></textarea>
                        <button type="submit" name="import_mode" value="all" class="btn btn-danger" data-confirm="Importovat všech <?= (int)$iw['total'] ?> záznamů i s problémy?">Importovat vše přesto (<?= (int)$iw['total'] ?>)</button>
                        <?php if ($iw_ok > 0): ?><button type="submit" name="import_mode" value="skip" class="btn btn-primary">Importovat bez problematických (<?= (int)$iw_ok ?>)</button><?php endif; ?>
                        <a href="index.php" class="btn btn-outline-secondary">Zrušit import</a>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($readonly): ?>
        <div class="alert alert-info small">Náhled pouze ke čtení: na této adrese nelze podávat hlášení ani svědectví.</div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($is_admin && !empty($inn_conflicts)): ?>
        <?php $n_c = count($inn_conflicts); $n_c_txt = $n_c === 1 ? '1 osoba' : ($n_c < 5 ? $n_c . ' osoby' : $n_c . ' osob'); ?>
        <div class="alert alert-danger border border-3 border-danger shadow-sm mb-4" role="alert" id="inn-conflicts">
            <div class="d-flex align-items-start gap-3">
                <div class="text-danger flex-shrink-0 mt-1"><?= lh_icon('alert', 28) ?></div>
                <div class="flex-grow-1">
                    <h2 class="h5 fw-bold mb-1">Rozpor v nevinnostech: <?= htmlspecialchars($n_c_txt) ?> s nevinností, ale s průchodem v době incidentu</h2>
                    <p class="small mb-2">Nevinnost znamená „v době incidentu tam nebyl(a)“. Tyto osoby ale mezi začátkem okna incidentu a nálezem (+ tolerance <?= (int)$tolerance_minutes ?> min) prošly turniketem. Taková osoba nemůže být nevinná: buď je nevinnost zadaná chybně, nebo je chyba v datech (sdílená karta, špatný čas, jiné jméno, chybný milník). Zkontroluj to a nevinnost smaž nebo oprav data.</p>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($inn_conflicts as $cf_i => $cf): ?>
                            <li class="border-top border-danger-subtle pt-2 mt-2<?= $cf_i >= 6 ? ' inn-extra d-none' : '' ?>">
                                <div><strong><?= htmlspecialchars($cf['person']) ?></strong> je nevinný(á) u incidentu <strong>#<?= (int)$cf['no'] ?></strong><?= $cf['reason'] !== '' ? ' <span class="text-body-secondary">(důvod: ' . htmlspecialchars($cf['reason']) . ')</span>' : '' ?></div>
                                <div class="small">Okno: <?= htmlspecialchars(date('d-m-Y H:i', strtotime($cf['a']))) ?> až <?= htmlspecialchars(date('d-m-Y H:i', strtotime($cf['e']))) ?> (s tolerancí do <?= htmlspecialchars(date('H:i', strtotime($cf['b']))) ?>). Průchody v okně:
                                    <?php foreach ($cf['passes'] as $cp): ?><span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle fw-normal"><?= htmlspecialchars($cp['ts']) ?> · <?= $cp['dir'] ?> · <?= htmlspecialchars(date('d-m-Y H:i', strtotime($cp['t']))) ?></span> <?php endforeach; ?>
                                    <?= $cf['n'] > count($cf['passes']) ? '<span class="text-body-secondary">+ ' . ($cf['n'] - count($cf['passes'])) . ' dalších</span>' : '' ?>
                                </div>
                                <div class="d-flex flex-wrap gap-2 mt-1">
                                    <a href="#inc-item-<?= (int)$cf['report_id'] ?>" class="btn btn-sm btn-outline-danger" data-open-inc="<?= (int)$cf['report_id'] ?>">Otevřít incident</a>
                                    <form method="POST" class="m-0" data-confirm="Smazat nevinnost osoby <?= htmlspecialchars($cf['person'], ENT_QUOTES) ?> u incidentu #<?= (int)$cf['no'] ?>?">
                                        <input type="hidden" name="action" value="innocent_delete">
                                        <input type="hidden" name="id" value="<?= (int)$cf['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Smazat nevinnost</button>
                                    </form>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($n_c > 6): ?><button type="button" class="btn btn-sm btn-danger mt-3" id="inn-more">Zobrazit dalších <?= $n_c - 6 ?></button><?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- DASHBOARD STAV KABINEK -->
    <div class="row g-3 mb-4">
        <?php foreach ($cubicles_list as $key => $title): ?>
            <?php 
                $st = $current_status[$key]; 
                $is_ok = $st && $st['status'] === 'ok';
                $card_class = $st ? ($is_ok ? 'status-card-ok' : 'status-card-zesrana') : '';

                // Poslední kontrola starší než 2 hodiny -> varování (záře + poznámka)
                $age = $st ? time() - strtotime($st['reported_at']) : 0;
                $is_stale = $st && $age > 2 * 3600;
                if ($is_stale) {
                    $card_class .= ' status-card-stale';
                    $age_days = intdiv($age, 86400);
                    $age_hours = intdiv($age % 86400, 3600);
                    $age_minutes = intdiv($age % 3600, 60);
                    $age_text = ($age_days > 0 ? "$age_days d " : '') . ($age_days > 0 || $age_hours > 0 ? "$age_hours h " : '') . "$age_minutes min";
                }
            ?>
            <div class="col-md-4">
                <div class="lh-tile <?= $card_class ?>">
                    <span class="lh-dot"></span>
                    <div class="lh-tile-body">
                        <h5 class="lh-tile-title"><?= htmlspecialchars($title) ?></h5>
                        <?php if ($st): ?>
                            <div class="lh-tile-meta"><?= htmlspecialchars(lh_d($st['reported_at'])) ?></div>
                            <div class="lh-tile-meta">(<?= htmlspecialchars($st['reporter']) ?>)</div>
                            <?php if (!empty($st['note'])): ?><div class="lh-tile-note" title="<?= htmlspecialchars($st['note']) ?>"><?= htmlspecialchars($st['note']) ?></div><?php endif; ?>
                            <?php if ($is_stale): ?>
                                <div class="stale-note mt-1"><?= lh_icon('alert', 14) ?> Bez kontroly už <?= $age_text ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="lh-tile-meta">Zatím bez hlášení</div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if (!$st): ?>
                            <span class="badge bg-secondary">NEZNÁMÝ</span>
                        <?php elseif ($is_ok): ?>
                            <span class="badge badge-ok fs-6">ČISTO</span>
                        <?php else: ?>
                            <span class="badge badge-zesrana fs-6">ZESRANÁ</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($culprit_name !== ''): ?>
        <div class="alert alert-success border border-2 border-success mb-4" id="culprit-banner" role="status">
            <strong>Pachatel byl dopaden:</strong> <span id="culprit-name"><?= htmlspecialchars($culprit_name) ?></span><?php if ($culprit_at !== ''): ?> <span class="small text-body-secondary">(<?= htmlspecialchars(date('d-m-Y', strtotime($culprit_at))) ?>)</span><?php endif; ?><br>
            Lord Hoven je dopaden a sběr dat je ukončen. Děkujeme všem, kdo pomáhali.
        </div>
    <?php endif; ?>
    <?php if (!$readonly && $collect_blocked): ?>
        <div class="alert alert-secondary mb-4" id="collect-off-info" role="status">
            <strong>Sběr dat je ukončen.</strong> Nová hlášení stavu, pozorování ani poznatky se momentálně nepřijímají. Dosavadní data a analýza zůstávají k dispozici.
        </div>
    <?php endif; ?>
    <?php if (!$readonly && $collect_off && $is_admin): ?>
        <div class="alert alert-warning mb-4 d-flex flex-wrap align-items-center gap-2" id="collect-off-admin" role="status">
            <div class="flex-grow-1"><strong>Sběr dat je vypnutý pro ostatní uživatele.</strong> Formuláře vidíte a můžete je používat jen vy jako admin.</div>
            <form method="POST" class="m-0"><input type="hidden" name="action" value="set_collection"><input type="hidden" name="collect" value="on"><button type="submit" class="btn btn-sm btn-success">Zapnout sběr dat</button></form>
        </div>
    <?php endif; ?>
    <?php if (!$readonly && !$collect_blocked): ?>
    <!-- CHCI ZAZNAMENAT: tři formuláře za přepínačem; sekce je při načtení sbalená a předvolený je „Stav toalety“ -->
    <div class="card mb-4">
        <details id="record-box">
            <summary class="p-3 px-md-4 fw-semibold fs-5">Chci zaznamenat</summary>
            <div class="px-3 px-md-4 pb-4">
              <div class="mx-auto" style="max-width: 720px;">
                <div class="btn-group w-100 mb-3" role="group" aria-label="Co chci zaznamenat" style="--bs-btn-font-size: .9rem; --bs-btn-padding-x: .35rem;">
                    <input type="radio" class="btn-check" name="rec-kind" id="rec-stav" value="stav" autocomplete="off" checked>
                    <label class="btn btn-outline-primary flex-fill" for="rec-stav">Stav toalety</label>
                    <?php if ($wit_tables_ok): ?>
                    <input type="radio" class="btn-check" name="rec-kind" id="rec-poz" value="poz" autocomplete="off">
                    <label class="btn btn-outline-primary flex-fill" for="rec-poz">Pozorování osoby</label>
                    <?php endif; ?>
                    <?php if ($pozn_ok): ?>
                    <input type="radio" class="btn-check" name="rec-kind" id="rec-pozn" value="pozn" autocomplete="off">
                    <label class="btn btn-outline-primary flex-fill" for="rec-pozn">Jiný poznatek</label>
                    <?php endif; ?>
                </div>
        <!-- FORMULÁŘ PRO NAHLÁŠENÍ STAVU -->
        <div class="lh-rec-form" data-rec="stav">
            <div>
                <h3 class="h6 mb-3">Nahlásit stav kabinky</h3>
                <form method="POST" id="report-form">
                    <input type="hidden" name="action" value="report">
                    <div class="mb-3">
                        <label class="form-label">Jméno reportujícího *</label>
                        <input type="text" name="reporter" id="reporter" class="form-control" required placeholder="např. Jan Novák"<?= $auth_user !== '' ? ' value="' . htmlspecialchars($auth_display) . '"' . ($is_admin ? '' : ' readonly') : '' ?>>
                    <?php if ($auth_user !== '' && $is_admin): ?><div class="form-text">Jako admin můžete hlásit jménem kohokoli.</div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Kabinka *</label>
                        <div class="btn-group w-100" role="group" aria-label="Kabinka" style="--bs-btn-font-size: .95rem; --bs-btn-padding-x: .35rem;">
                            <?php foreach ($cubicles_list as $c_key => $c_name): ?>
                                <?php $c_short = trim(preg_replace('/\s+/u', ' ', preg_replace('/\bkabinka\b/ui', '', $c_name))); ?>
                                <input type="radio" class="btn-check" name="cubicle" id="rc-<?= htmlspecialchars($c_key) ?>" value="<?= htmlspecialchars($c_key) ?>" data-full="<?= htmlspecialchars($c_name) ?>" autocomplete="off" required>
                                <label class="btn btn-outline-primary flex-fill" for="rc-<?= htmlspecialchars($c_key) ?>"><?= htmlspecialchars($c_short) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="alert alert-info py-2 px-3 small mt-2 mb-0 d-flex gap-2 align-items-start">
                            <?= lh_icon('info', 16) ?>
                            <span>Levá a pravá kabinka se určuje z pohledu příchozího (pisoáry máš za zády).</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Stav *</label>
                        <div class="btn-group w-100" role="group" aria-label="Stav">
                            <input type="radio" class="btn-check" name="status" id="rs-ok" value="ok" autocomplete="off" required>
                            <label class="btn btn-outline-success flex-fill" for="rs-ok">OK (čisto)</label>
                            <input type="radio" class="btn-check" name="status" id="rs-zesrana" value="zesrana" autocomplete="off" required>
                            <label class="btn btn-outline-danger flex-fill" for="rs-zesrana">Zesraná</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Čas kontroly/příchodu *</label>
                        <input type="datetime-local" name="reported_at" class="form-control" required value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                    <?php if ($note_cols['reports']): ?>
                    <div class="mb-3">
                        <label class="form-label" for="report-note">Poznámka <span class="text-body-secondary fw-normal">(nepovinná)</span></label>
                        <textarea name="note" id="report-note" class="form-control" rows="2" maxlength="300" data-note-counter="report-note-count" placeholder="např. došel papír"></textarea>
                        <div class="form-text d-flex justify-content-between gap-2"><span>Poznámku uvidí všichni.</span><span id="report-note-count" class="text-nowrap flex-shrink-0">0/300</span></div>
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary w-100">Uložit hlášení</button>
                </form>
            </div>
        </div>

        <?php if ($wit_tables_ok): ?>
        <div class="lh-rec-form d-none" data-rec="poz" id="sighting-card">
            <div>
                <h3 class="h6 mb-3">Viděl jsem osobu na toaletách</h3>
                <div>
                    <p class="small text-body-secondary mb-3">Nahlaste jen to, co jste skutečně viděli a o kom jistě víte, kdo to byl. Pozorování schvaluje admin.</p>
                    <form method="POST" id="sighting-form">
                        <input type="hidden" name="action" value="report_sighting">
                        <div class="mb-3">
                            <label class="form-label" for="sighting-reporter">Jméno reportujícího *</label>
                            <input type="text" name="reporter" id="sighting-reporter" class="form-control" required placeholder="např. Jan Novák"<?= $auth_user !== '' ? ' value="' . htmlspecialchars($auth_display) . '"' . ($is_admin ? '' : ' readonly') : '' ?>>
                    <?php if ($auth_user !== '' && $is_admin): ?><div class="form-text">Jako admin můžete hlásit jménem kohokoli.</div><?php endif; ?>
                        </div>
                        <div class="mb-3 position-relative">
                            <label class="form-label" for="sighting-person">Koho jsi viděl *</label>
                            <input type="text" name="person_name" id="sighting-person" class="form-control" required autocomplete="off" placeholder="Jméno a příjmení">
                            <div id="sighting-suggest" class="list-group lh-suggest d-none"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="sighting-location">Kde *</label>
                            <select name="location" id="sighting-location" class="form-select" required>
                                <option value="">-- Vyber umístění --</option>
                                <?php foreach ($wit_locations as $lk => $ll): ?>
                                    <option value="<?= $lk ?>"><?= htmlspecialchars($ll) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Co dělal *</label>
                            <div class="btn-group w-100" role="group">
                                <?php foreach ($wit_directions as $dk => $dl): ?>
                                    <input type="radio" class="btn-check" name="direction" id="dir-<?= $dk ?>" value="<?= $dk ?>" autocomplete="off"<?= $dk === 'vychazel' ? ' checked' : '' ?>>
                                    <label class="btn btn-outline-primary" for="dir-<?= $dk ?>"><?= htmlspecialchars($dl) ?></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Kdy *</label>
                            <input type="datetime-local" name="seen_at" class="form-control" required value="<?= date('Y-m-d\TH:i') ?>">
                        </div>
                        <?php if ($note_cols['sightings']): ?>
                        <div class="mb-3">
                            <label class="form-label" for="sighting-note">Poznámka <span class="text-body-secondary fw-normal">(nepovinná)</span></label>
                            <textarea name="note" id="sighting-note" class="form-control" rows="2" maxlength="300" data-note-counter="sighting-note-count"></textarea>
                            <div class="form-text d-flex justify-content-between gap-2"><span>Poznámku uvidí jen admin.</span><span id="sighting-note-count" class="text-nowrap flex-shrink-0">0/300</span></div>
                        </div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary w-100">Odeslat pozorování</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($pozn_ok): ?>
        <!-- FORMULÁŘ: JINÝ POZNATEK -->
        <div class="lh-rec-form d-none" data-rec="pozn">
            <h3 class="h6 mb-3">Jiný poznatek</h3>
            <p class="small text-body-secondary mb-3">Informace, které je dobré evidovat a nehodí se jinam (např. „Jan Novák je levák“, „v kuchyňce byl na zemi toaleťák“). Uvidí je všichni, kdo mohou podávat hlášení; zobrazí se v záložce Poznatky a, je-li zadaný čas, i na časové ose.</p>
            <form method="POST" id="note-form">
                <input type="hidden" name="action" value="report_note">
                <div class="mb-3">
                    <label class="form-label" for="note-reporter">Jméno reportujícího *</label>
                    <input type="text" name="reporter" id="note-reporter" class="form-control" required maxlength="100" placeholder="např. Jan Novák"<?= $auth_user !== '' ? ' value="' . htmlspecialchars($auth_display) . '"' . ($is_admin ? '' : ' readonly') : '' ?>>
                    <?php if ($auth_user !== '' && $is_admin): ?><div class="form-text">Jako admin můžete hlásit jménem kohokoli.</div><?php endif; ?>
                </div>
                <div class="mb-3 position-relative">
                    <label class="form-label" for="note-person">Osoba, které se hlášení týká <span class="text-body-secondary fw-normal">(nepovinné)</span></label>
                    <input type="text" name="person_name" id="note-person" class="form-control" maxlength="150" autocomplete="off" placeholder="Jméno a příjmení">
                    <div id="note-suggest" class="list-group lh-suggest d-none"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="note-when">Čas a datum <span class="text-body-secondary fw-normal">(nepovinné)</span></label>
                    <input type="datetime-local" name="happened_at" id="note-when" class="form-control">
                    <div class="form-text">Vyplň, jen když se poznatek vztahuje ke konkrétnímu okamžiku (může být i v minulosti nebo budoucnosti). Jen takové se ukážou na časové ose.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="note-body">Poznatek *</label>
                    <textarea name="poznatek" id="note-body" class="form-control" rows="3" maxlength="500" required data-note-counter="note-body-count" placeholder="např. Jan Novák je levák"></textarea>
                    <div class="form-text text-end"><span id="note-body-count">0/500</span></div>
                </div>
                <button type="submit" class="btn btn-primary w-100">Uložit poznatek</button>
            </form>
        </div>
        <?php endif; ?>
              </div>
            </div>
        </details>
    </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <!-- ANALÝZA - ČASOVÁ OKNA (sbalená při načtení, po rozbalení celá bez vlastního scrollování) -->
        <div class="col-12">
          <div class="card lh-card-warn">
            <details id="inc-card">
                <summary class="p-4 fw-semibold fs-5"><?= lh_icon('target', 20) ?> Časová okna incidentů</summary>
                <div class="px-4 pb-4">
                <div class="d-flex justify-content-end mb-3">
                    <a href="index.php?action=export_csv" class="btn btn-sm btn-outline-success">Exportovat CSV</a>
                </div>
                <?php if (empty($incidents)): ?>
                    <p class="text-body-secondary italic">Zatím nebyly identifikovány žádné incidenty.</p>
                <?php else: ?>
                    <?php if ($sel_enabled && $labels_ok && !empty($inc_labels)): ?>
                    <?php
                        $lb_counts = [];
                        foreach ($incidents as $inc_c) { foreach ($incident_label_ids[(int)$inc_c['id']] ?? [] as $lid_c) { $lb_counts[$lid_c] = ($lb_counts[$lid_c] ?? 0) + 1; } }
                    ?>
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3" id="label-filter">
                        <span class="small text-body-secondary">Počítat jen incidenty se štítkem:</span>
                        <?php foreach ($inc_labels as $lb): ?>
                            <button type="button" class="lh-label-filter" data-label="<?= (int)$lb['id'] ?>" aria-pressed="false" title="Do výpočtu se zahrnou jen incidenty s tímto štítkem (i ty, které admin vyřadil)"><?= lh_label_badge($lb) ?> <span class="small text-body-secondary">×<?= (int)($lb_counts[(int)$lb['id']] ?? 0) ?></span></button>
                        <?php endforeach; ?>
                        <div class="btn-group btn-group-sm ms-1" role="group" aria-label="Způsob spojení vybraných štítků" id="label-mode">
                            <input type="radio" class="btn-check" name="label-mode" id="label-mode-union" value="union" autocomplete="off" checked>
                            <label class="btn btn-outline-secondary" for="label-mode-union" title="Sjednocení: do výpočtu se zahrne incident, který má alespoň jeden z vybraných štítků (štítek A nebo štítek B).">Sjednocení</label>
                            <input type="radio" class="btn-check" name="label-mode" id="label-mode-inter" value="inter" autocomplete="off">
                            <label class="btn btn-outline-secondary" for="label-mode-inter" title="Průnik: do výpočtu se zahrne jen incident, který má všechny vybrané štítky najednou (štítek A i štítek B).">Průnik</label>
                        </div>
                        <span class="small text-body-secondary">Bez vybraného štítku platí všechny počítané incidenty. Jen filtr zobrazení v tvém prohlížeči.</span>
                    </div>
                    <?php endif; ?>
                    <div class="lh-inc-list">
                        <?php
                            // Milníky se ukazují mezi incidenty tam, kam časově patří (seznam je od nejnovějšího)
                            $ms_pending = $milestones;
                            usort($ms_pending, function ($a, $b) { return strcmp(lh_ms_key($b), lh_ms_key($a)) ?: strcmp($b['date_from'], $a['date_from']) ?: ((int)$b['id'] <=> (int)$a['id']); });
                            $ms_row_html = function ($m) use ($pdo) {
                                $note = lh_ms_note($m, $pdo);
                                return '<div class="lh-ms-row small my-2 px-2 py-1 rounded" style="border-left: 4px solid #d97706; background: rgba(217,119,6,.10);">'
                                    . lh_icon('flag', 14) . ' <strong>' . htmlspecialchars(date('d-m-Y', strtotime($m['date_from']))) . '</strong> · ' . htmlspecialchars(lh_ms_text($m, $pdo))
                                    . ($note !== '' ? ' <span class="text-body-secondary">(' . htmlspecialchars($note) . ')</span>' : '') . '</div>';
                            };
                        ?>
                        <?php foreach ($incidents as $inc): ?>
                            <?php
                                while ($ms_pending && lh_ms_key($ms_pending[0]) > substr((string)$inc['window_end'], 0, 10)) { echo $ms_row_html(array_shift($ms_pending)); }
                                $iid = (int)$inc['id'];
                                $ino = (int)($incident_no[$iid] ?? 0);
                                $has_start = !empty($inc['window_start']);
                                $dur_txt = $has_start ? format_minutes((strtotime($inc['window_end']) - strtotime($inc['window_start'])) / 60) : '';
                            ?>
                            <details class="lh-inc-item border rounded mb-2<?= !empty($inc['counted']) ? '' : ' is-ignored' ?>" data-incident="<?= $iid ?>" id="inc-item-<?= $iid ?>">
                                <summary class="p-2 d-flex flex-wrap align-items-center gap-3">
                                    <?php if ($sel_enabled): ?>
                                    <span class="form-check m-0 lh-stop">
                                        <input class="form-check-input inc-include" type="checkbox" autocomplete="off" id="inc-in-<?= $iid ?>" data-incident="<?= $iid ?>" data-labels="<?= htmlspecialchars(implode(',', $incident_label_ids[$iid] ?? [])) ?>" <?= !empty($inc['counted']) ? 'checked' : '' ?> aria-label="Zahrnout do výpočtu">
                                        <label class="form-check-label small text-body-secondary" for="inc-in-<?= $iid ?>">Zahrnout do výpočtu</label>
                                    </span>
                                    <?php endif; ?>
                                    <span class="lh-incno">#<?= $ino ?></span>
                                    <span><?= htmlspecialchars(lh_d($inc['window_end'])) ?></span>
                                    <span class="text-body-secondary">nahlásil <?= htmlspecialchars($inc['dirty_reported_by']) ?></span>
                                    <?= lh_label_badges($incident_label_ids[$iid] ?? [], $inc_labels) ?>
                                </summary>
                                <div class="p-3 border-top">
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                                        <strong><?= htmlspecialchars($cubicles_list[$inc['cubicle']] ?? strtoupper($inc['cubicle'])) ?></strong>
                                        <?php if (!empty($inc['counted'])): ?>
                                            <span class="badge badge-ok">počítá se</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">nepočítá se</span>
                                            <?php if ($inc['ignore_reason'] !== ''): ?><span class="small text-body-secondary"><?= htmlspecialchars($inc['ignore_reason']) ?></span><?php endif; ?>
                                        <?php endif; ?>
                                        <?= lh_label_badges($incident_label_ids[$iid] ?? [], $inc_labels) ?>
                                        <?php if ($is_admin && isset($incident_announced[$iid])): ?><span class="badge bg-primary-subtle text-primary-emphasis" title="Oznámeno všem příjemcům týdenního souhrnu: <?= htmlspecialchars(lh_d($incident_announced[$iid])) ?>">Oznámeno</span><?php endif; ?>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <div class="lh-inc-label">Poslední čisto</div>
                                            <?php if ($has_start): ?>
                                                <div><strong><?= htmlspecialchars($inc['last_ok_by']) ?></strong></div>
                                                <div class="small"><?= htmlspecialchars(lh_d($inc['window_start'])) ?></div>
                                                <?php if (!empty($inc['ok_note'])): ?><div class="small text-body-secondary lh-note mt-1"><?= htmlspecialchars($inc['ok_note']) ?></div><?php endif; ?>
                                            <?php else: ?>
                                                <div class="small text-body-secondary">Předchozí hlášení „čisto“ chybí, okno je neznámé.</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="lh-inc-label">Nález (hlášení „zesraná“)</div>
                                            <div><strong><?= htmlspecialchars($inc['dirty_reported_by']) ?></strong></div>
                                            <div class="small"><?= htmlspecialchars(lh_d($inc['window_end'])) ?></div>
                                            <?php if (!empty($inc['dirty_note'])): ?><div class="small text-body-secondary lh-note mt-1"><?= htmlspecialchars($inc['dirty_note']) ?></div><?php endif; ?>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="lh-inc-label">Časové okno</div>
                                            <?php if ($has_start): ?>
                                                <div class="small"><?= htmlspecialchars(lh_d($inc['window_start'])) ?> &rarr; <?= htmlspecialchars(lh_d($inc['window_end'])) ?></div>
                                                <div class="small"><?= lh_icon('clock', 13) ?> <?= htmlspecialchars($dur_txt) ?></div>
                                                <?php if ($tolerance_minutes > 0): ?><div class="small text-body-secondary">průchody turniketem +<?= (int)$tolerance_minutes ?> min po nálezu</div><?php endif; ?>
                                            <?php else: ?>
                                                <div class="small text-body-secondary">neznámé</div>
                                            <?php endif; ?>
                                            <?php if (!empty($incident_wit_count[$iid])): ?><div class="small"><?= lh_icon('eye', 13) ?> pozorování v okně: <?= (int)$incident_wit_count[$iid] ?></div><?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ($innocent_ok && !empty($incident_innocents[$iid])): ?>
                                    <details class="mt-3">
                                        <summary class="small fw-semibold">Nevinní (<?= count($incident_innocents[$iid]) ?>)</summary>
                                        <ul class="mb-0 mt-1">
                                            <?php foreach ($incident_innocents[$iid] as $in_r): ?>
                                                <li><?= htmlspecialchars($in_r['person_name']) ?><?= (string)$in_r['reason'] !== '' ? ' (' . htmlspecialchars($in_r['reason']) . ')' : '' ?><?php if ($is_admin && isset($inn_conflict_ids[(int)$in_r['id']])): ?> <span class="badge bg-danger" title="Má průchod turniketem v době incidentu, nemůže být nevinná">rozpor: průchod v době incidentu</span><?php endif; ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                    <?php endif; ?>
                                    <div class="d-flex flex-wrap align-items-center gap-3 mt-3">
                                        <button type="button" class="btn btn-sm btn-outline-secondary inc-open" data-incident="<?= $iid ?>">Zobrazit podezřelé tohoto incidentu</button>
                                        <?php if ($is_admin && ($incident_flags_ok || $announce_ok)): ?>
                                        <div class="inc-admin d-flex flex-wrap align-items-center gap-3">
                                            <?php if ($incident_flags_ok): ?>
                                            <form method="POST" class="m-0 d-flex align-items-center gap-2">
                                                <input type="hidden" name="action" value="incident_set_counted">
                                                <input type="hidden" name="report_id" value="<?= (int)$inc['id'] ?>">
                                                <input type="hidden" name="counted" value="<?= !empty($inc['counted']) ? '0' : '1' ?>">
                                                <input type="hidden" name="reason" value="">
                                                <div class="form-check form-switch m-0">
                                                    <input class="form-check-input inc-switch" type="checkbox" role="switch" id="inc-sw-<?= (int)$inc['id'] ?>" <?= !empty($inc['counted']) ? 'checked' : '' ?> aria-label="Počítat tento incident">
                                                </div>
                                                <label class="small text-body-secondary " for="inc-sw-<?= (int)$inc['id'] ?>">Počítat tento incident</label>
                                            </form>
                                            <?php endif; ?>
                                            <?php if ($announce_ok && !empty($inc['counted']) && !isset($incident_announced[$inc['id']])): ?>
                                            <form method="POST" class="m-0 mt-1" data-confirm="Poslat oznámení o incidentu #<?= (int)($incident_no[(int)$inc['id']] ?? 0) ?> všem příjemcům týdenního souhrnu (<?= (int)$digest_rcpt_n ?>)?">
                                                <input type="hidden" name="action" value="incident_announce">
                                                <input type="hidden" name="report_id" value="<?= (int)$inc['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger text-nowrap">Oznámit všem</button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($is_admin && $milestones_ok && $innocent_ok): ?>
                                        <?php
                                            $ms_out = lh_ms_out_persons($milestones, $inc);
                                            $ms_have = [];
                                            foreach ($incident_innocents[$iid] ?? [] as $ms_r) { $ms_have[lh_name_norm($ms_r['person_name'])] = true; }
                                        ?>
                                        <?php if ($ms_out): ?>
                                        <form method="POST" class="mt-3 pt-3 border-top ms-grant-form">
                                            <input type="hidden" name="action" value="milestone_grant">
                                            <input type="hidden" name="report_id" value="<?= $iid ?>">
                                            <div class="lh-inc-label mb-1">Osoby mimo firmu po celé okno incidentu (podle milníků)</div>
                                            <?php foreach ($ms_out as $ms_name => $ms_why): ?>
                                                <?php $ms_has = isset($ms_have[lh_name_norm($ms_name)]); ?>
                                                <label class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                    <input type="checkbox" class="form-check-input m-0 ms-out-chk" name="persons[]" value="<?= htmlspecialchars($ms_name) ?>"<?= $ms_has ? ' disabled' : ' checked' ?>>
                                                    <span><?= htmlspecialchars($ms_name) ?></span>
                                                    <span class="small text-body-secondary"><?= htmlspecialchars(implode('; ', array_map(function ($w) use ($pdo) { return date('d-m-Y', strtotime($w['date_from'])) . ' ' . lh_ms_text(['person_name' => '', 'kind' => $w['kind'], 'title' => '', 'date_to' => $w['date_to']] , $pdo); }, $ms_why))) ?></span>
                                                    <?php if ($ms_has): ?><span class="badge bg-success">už má nevinnost</span><?php endif; ?>
                                                </label>
                                            <?php endforeach; ?>
                                            <div class="d-flex flex-wrap gap-2 mt-2">
                                                <button type="button" class="btn btn-sm btn-outline-secondary ms-toggle-all">Vybrat všechny / zrušit výběr</button>
                                                <button type="submit" class="btn btn-sm btn-primary">Udělit nevinnost vybraným</button>
                                                <button type="submit" name="all" value="1" class="btn btn-sm btn-outline-primary" data-confirm="Udělit nevinnost všem osobám mimo firmu u tohoto incidentu?">Udělit nevinnost všem</button>
                                            </div>
                                        </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($is_admin && $labels_ok): ?>
                                    <form method="POST" class="d-flex flex-wrap align-items-center gap-3 mt-3 pt-3 border-top">
                                        <input type="hidden" name="action" value="incident_labels_set">
                                        <input type="hidden" name="report_id" value="<?= $iid ?>">
                                        <span class="lh-inc-label m-0">Štítky</span>
                                        <?php foreach ($inc_labels as $lb): ?>
                                            <label class="d-inline-flex align-items-center gap-1 m-0">
                                                <input type="checkbox" class="form-check-input m-0" name="labels[]" value="<?= (int)$lb['id'] ?>"<?= in_array((int)$lb['id'], $incident_label_ids[$iid] ?? [], true) ? ' checked' : '' ?>>
                                                <?= lh_label_badge($lb) ?>
                                            </label>
                                        <?php endforeach; ?>
                                        <?php if (empty($inc_labels)): ?><span class="small text-body-secondary">Zatím nejsou žádné štítky, vytvoř je v administraci.</span><?php endif; ?>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary"<?= empty($inc_labels) ? ' disabled' : '' ?>>Uložit štítky</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </details>
                        <?php endforeach; ?>
                        <?php while ($ms_pending) { echo $ms_row_html(array_shift($ms_pending)); } ?>
                    </div>
                <?php endif; ?>
                </div>
            </details>
          </div>
        </div>
    </div>

    <!-- ZÁLOŽKY -->
    <ul class="nav nav-tabs mb-3" id="main-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-suspects" data-bs-toggle="tab" data-bs-target="#pane-suspects" type="button" role="tab">Podezřelí</button>
        </li>
 <?php if (!$ro_no['history']): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-history" data-bs-toggle="tab" data-bs-target="#pane-history" type="button" role="tab"><span class="d-none d-sm-inline">Historie hlášení</span> <span class="d-sm-none">Historie</span></button>
        </li>
        <?php endif; ?>
        <?php if (!$ro_no['stats']): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-stats" data-bs-toggle="tab" data-bs-target="#pane-stats" type="button" role="tab">Statistiky</button>
        </li>
        <?php endif; ?>
        <?php if (!$ro_no['photos']): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-photos" data-bs-toggle="tab" data-bs-target="#pane-photos" type="button" role="tab"><span class="d-none d-sm-inline">Fotodokumentace</span><span class="d-sm-none">Fotky</span></button>
        </li>
        <?php endif; ?>
        <?php if (!$readonly && ($pozn_ok || $is_admin)): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-poznatky" data-bs-toggle="tab" data-bs-target="#pane-poznatky" type="button" role="tab">Poznatky<?= $pozn_ok ? ' (' . count($poznatky) . ')' : '' ?></button>
        </li>
        <?php endif; ?>
        <?php if ($is_admin): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-admin" data-bs-toggle="tab" data-bs-target="#pane-admin" type="button" role="tab">Admin<?= !empty($wit_pending) ? ' (' . count($wit_pending) . ')' : '' ?></button>
        </li>
        <?php endif; ?>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="pane-suspects" role="tabpanel">
    <!-- PODEZŘELÍ #1 (FORENZNÍ VÝSTUP) -->
    <div class="card p-4 mb-4 lh-card-danger">
        <h3 class="card-title h5 mb-3 d-flex align-items-center gap-2">
            <?= lh_icon('eye', 20) ?><span>Žebříček podezřelých (Analyzováno dle čteček karet)</span>
        </h3>
        <?php if (empty($suspects)): ?>
            <p class="text-body-secondary m-0">Zatím nebyly spárovány žádné průchody v časových oknech incidentů (nahraj docházku níže).</p>
        <?php else: ?>
            <?php /* Počet osob se štítkem Podezřelý (shoda na prvním místě); ukáže se jen když jich je víc než jedna, hodnotu přepočítává JS podle řazení a filtrů */ ?>
            <div id="suspects-count" class="lh-suspects-count mb-3<?= $leaders_n > 1 ? '' : ' d-none' ?>">Aktuálně podezřelých: <strong><?= (int)$leaders_n ?></strong></div>
            <?php if ($innocent_ok): ?>
                <?php
                    $inn_js = [];
                    foreach ($incidents as $inc_i) {
                        foreach ($incident_innocents[(int)$inc_i['id']] ?? [] as $in_r) {
                            $inn_js[] = ['n' => $in_r['person_name'], 'i' => (int)$inc_i['id'], 'no' => (int)($incident_no[(int)$inc_i['id']] ?? 0), 'r' => (string)($in_r['reason'] ?? ''), 'c' => !empty($inc_i['counted']) ? 1 : 0];
                        }
                    }
                ?>
                <details id="innocents-box" class="mb-3 d-none">
                    <summary class="fw-semibold">Nevinní (<span id="innocents-n">0</span>)</summary>
                    <div id="innocents-list" class="mt-2 small"></div>
                </details>
                <script nonce="<?= htmlspecialchars($lh_nonce) ?>">window.LH_INNOCENTS = <?= json_encode($inn_js, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
                <?php
                    // Osoby vyřazené ze žebříčku (blacklisted_suspects) se v sekci „Osoby bez alibi“ nezobrazují (ani v datech stránky)
                    $bl_norm = array_map('lh_name_norm', $blacklisted);
                    $noalibi_names = array_values(array_filter($employee_names, function ($n_e) use ($bl_norm) { return !in_array(lh_name_norm($n_e), $bl_norm, true); }));
                ?>
                <?php if ($employees_ok && !empty($noalibi_names)): ?>
                    <details id="noalibi-box" class="mb-3 d-none">
                        <summary class="fw-semibold">Osoby bez alibi (<span id="noalibi-n">0</span>)</summary>
                        <div id="noalibi-list" class="mt-2 small"></div>
                    </details>
                    <script nonce="<?= htmlspecialchars($lh_nonce) ?>">
                        window.LH_EMPLOYEES = <?= json_encode($noalibi_names, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                        window.LH_COUNTED_N = <?= count(array_filter($incidents, function ($i_c) { return !empty($i_c['counted']); })) ?>;
                    </script>
                <?php endif; ?>
            <?php endif; ?>
            <?php $arr_ok = !empty($arrival_data['inc']); ?>
            <?php if ($arr_ok): ?>
                <details id="arrival-box" class="mb-3">
                    <summary class="fw-semibold">Příchod po incidentu</summary>
                    <div class="mt-2">
                        <p class="small text-body-secondary mb-2">Příchod = první průchod čtečkou v den nálezu. Kdo přišel až dlouho po nálezu, v době incidentu v budově nebyl. Nejde o alibi: průchod mohl chybět (bez karty, jiný vchod) a hodnocení se týká jen vybraných incidentů, u kterých je k dispozici docházka.</p>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <label for="arr-x" class="small mb-0">Rezerva po nálezu:</label>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="arr-minus" aria-label="Méně">−</button>
                            <input type="range" class="form-range" id="arr-x" min="1" max="120" step="1" value="<?= max(1, min(120, (int)$tolerance_minutes)) ?>" style="max-width: 260px; flex: 1 1 140px;">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="arr-plus" aria-label="Více">+</button>
                            <input type="number" class="form-control form-control-sm" id="arr-x-num" min="1" max="120" value="<?= max(1, min(120, (int)$tolerance_minutes)) ?>" style="width: 5.5rem;" aria-label="Minuty">
                            <span class="small">min</span>
                        </div>
                        <div class="small text-body-secondary mb-2" id="arr-eff"></div>
                        <div class="btn-group btn-group-sm mb-3" role="group" aria-label="Režim vyhodnocení">
                            <input type="radio" class="btn-check" name="arr-mode" id="arr-mode-any" value="any" autocomplete="off" checked>
                            <label class="btn btn-outline-secondary" for="arr-mode-any" title="Osoba je vyloučena, když přišla až po incidentu aspoň u jednoho z vybraných incidentů. Platí při hypotéze jednoho pachatele, který musel být přítomen u všech.">U aspoň jednoho incidentu</label>
                            <input type="radio" class="btn-check" name="arr-mode" id="arr-mode-all" value="all" autocomplete="off">
                            <label class="btn btn-outline-secondary" for="arr-mode-all" title="Osoba je vyloučena, jen když přišla až po incidentu (nebo vůbec nepřišla) u všech vybraných incidentů.">U všech incidentů</label>
                        </div>
                        <div class="small text-warning-emphasis mb-2 d-none" id="arr-note"></div>
                        <details class="mb-2" id="arr-d-after"><summary class="fw-semibold">Příchod až po incidentu (<span id="arr-n-after">0</span>)</summary><div class="small mt-1" id="arr-l-after"></div></details>
                        <details class="mb-2" id="arr-d-near"><summary class="fw-semibold">Příchod těsně po incidentu (<span id="arr-n-near">0</span>)</summary><div class="small mt-1" id="arr-l-near"></div></details>
                        <details class="mb-2" id="arr-d-before"><summary class="fw-semibold">Příchod před incidentem nebo v průběhu (<span id="arr-n-before">0</span>)</summary><div class="small mt-1" id="arr-l-before"></div></details>
                        <details class="mb-2" id="arr-d-none"><summary class="fw-semibold">Bez průchodu, nelze ověřit (<span id="arr-n-none">0</span>)</summary><div class="small mt-1" id="arr-l-none"></div></details>
                        <details class="mb-2" id="arr-d-it"><summary class="fw-semibold">IT oddělení, bez turniketu (<span id="arr-n-it">0</span>)</summary><div class="small mt-1" id="arr-l-it"></div></details>
                        <div class="small text-body-secondary">Značka <span class="badge bg-success">N</span> = má zadanou nevinnost aspoň u jednoho vybraného incidentu. Klepnutím na jméno se ukážou podrobnosti po incidentech.</div>
                    </div>
                </details>
                <script nonce="<?= htmlspecialchars($lh_nonce) ?>">window.LH_ARR = <?= json_encode($arrival_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
            <?php endif; ?>
            <?php if (!empty($absence_data['days']) && !empty($absence_data['inc'])): ?>
                <details id="absence-box" class="mb-3">
                    <summary class="fw-semibold">Není člověk, není problém (<span id="abs-n">0</span>)</summary>
                    <div class="mt-2">
                        <p class="small text-body-secondary mb-2">Osoby, které chyběly celý den (žádný průchod žádnými dveřmi) a přitom se v takové dny nestal <strong>žádný z vybraných incidentů</strong> (zaškrtnutých „Zahrnout do výpočtu“). Zároveň byly přítomny v den každého vyhodnoceného incidentu. Je to jen srovnání dnů, ne důkaz: čím víc dní s úplnou docházkou, tím víc znamená.</p>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2 small">
                            <span>Den se bere jako úplný, když docházka toho dne začíná nejpozději v</span>
                            <input type="time" class="form-control form-control-sm" id="abs-from" value="08:00" style="width: 7.5rem;" aria-label="Docházka začíná nejpozději">
                            <span>a končí nejdříve v</span>
                            <input type="time" class="form-control form-control-sm" id="abs-to" value="12:00" style="width: 7.5rem;" aria-label="Docházka končí nejdříve">
                        </div>
                        <div class="small text-warning-emphasis mb-2 d-none" id="abs-note"></div>
                        <div class="small text-body-secondary mb-2" id="abs-info"></div>
                        <div class="small" id="abs-list"></div>
                        <div class="small text-body-secondary mt-2">Počítají se jen dny, kdy se kontrolovaly kabinky. Značka <span class="badge bg-success">N</span> = má zadanou nevinnost aspoň u jednoho vybraného incidentu. Klepnutím na jméno se ukážou podrobnosti.</div>
                    </div>
                </details>
                <details id="present-box" class="mb-3">
                    <summary class="fw-semibold">Ve firmě u každého incidentu (<span id="pres-n">0</span>)</summary>
                    <div class="mt-2">
                        <p class="small text-body-secondary mb-2">Osoby, které byly ve firmě u <strong>každého vybraného incidentu</strong> (zaškrtnutého „Zahrnout do výpočtu“): v den okna incidentu měly aspoň jeden průchod dveřmi, ať v okně, nebo mimo něj (u oken přes více dnů stačí jeden den). Počítají se jen incidenty, u nichž docházka dne nálezu sahá aspoň do okamžiku nálezu.</p>
                        <div class="small text-warning-emphasis mb-2 d-none" id="pres-note"></div>
                        <div class="small text-body-secondary mb-2" id="pres-info"></div>
                        <div class="small" id="pres-list"></div>
                        <div class="small text-body-secondary mt-2">Značka <span class="badge bg-success">N</span> = má zadanou nevinnost aspoň u jednoho vybraného incidentu (skryje ji „Skrýt nevinné“).</div>
                    </div>
                </details>
                <script nonce="<?= htmlspecialchars($lh_nonce) ?>">window.LH_ABS = <?= json_encode($absence_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
            <?php endif; ?>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <div class="btn-group btn-group-sm" role="group" aria-label="Pohled">
                    <input type="radio" class="btn-check" name="susp-view" id="view-persons" autocomplete="off" checked>
                    <label class="btn btn-outline-primary" for="view-persons">Podle osob</label>
                    <input type="radio" class="btn-check" name="susp-view" id="view-incidents" autocomplete="off">
                    <label class="btn btn-outline-primary" for="view-incidents">Podle incidentů</label>
                </div>
                <span class="small text-body-secondary ms-2">Body počítat z:</span>
                <div class="btn-group btn-group-sm lh-src-group" role="group" aria-label="Zdroje bodů">
                    <input type="checkbox" class="btn-check" id="flt-up" autocomplete="off" checked>
                    <label class="btn btn-outline-up" for="flt-up">Nahoře (4. patro)</label>
                    <input type="checkbox" class="btn-check" id="flt-down" autocomplete="off" checked>
                    <label class="btn btn-outline-down" for="flt-down">Dole (1. patro)</label>
                    <input type="checkbox" class="btn-check" id="flt-virt" autocomplete="off" checked>
                    <label class="btn btn-outline-info" for="flt-virt">Virtuální (IT)</label>
                    <input type="checkbox" class="btn-check" id="flt-witx" autocomplete="off" checked>
                    <label class="btn btn-outline-wit" for="flt-witx">Svědectví přesné</label>
                    <input type="checkbox" class="btn-check" id="flt-witv" autocomplete="off" checked>
                    <label class="btn btn-outline-wit2" for="flt-witv">Svědectví nepřesné</label>
                </div>
                <?php if ($innocent_ok && !empty($incident_innocents)): ?>
                    <input type="checkbox" class="btn-check" id="flt-hide-inn" autocomplete="off" checked>
                    <label class="btn btn-outline-success btn-sm" for="flt-hide-inn" title="Skryje osoby, které mají odznak Nevinný (podle vybraných incidentů)">Skrýt nevinné</label>
                <?php endif; ?>
                <?php if (!empty($arrival_data['inc'])): ?>
                    <input type="checkbox" class="btn-check" id="flt-hide-excl" autocomplete="off">
                    <label class="btn btn-outline-secondary btn-sm" for="flt-hide-excl" title="Skryje osoby vyloučené příchodem po incidentu v žebříčku podezřelých a v seznamu Osoby bez alibi. Seznamy Příchod po incidentu zůstávají beze změny.">Skrýt vyloučené příchodem</label>
                <?php endif; ?>
                <?php if (!empty($trusted_names)): ?>
                    <input type="checkbox" class="btn-check" id="flt-hide-trust" autocomplete="off" checked>
                    <label class="btn btn-outline-secondary btn-sm" for="flt-hide-trust" title="Skryje osoby ze seznamu Důvěryhodní (zadává admin) v žebříčku a v seznamech Příchod po incidentu, Není člověk, není problém a Ve firmě u každého incidentu, tedy tam, kde filtr Skrýt nevinné">Skrýt důvěryhodné</label>
                <?php endif; ?>
                <?php if ($has_day_entries): ?>
                    <input type="checkbox" class="btn-check" id="flt-hide-day" autocomplete="off">
                    <label class="btn btn-outline-secondary btn-sm" for="flt-hide-day" title="Skryje informaci „Během dne ve firmě“ (osoba v den okna incidentu prošla dveřmi, ale v samotném okně ne; bez bodu) i osoby, které mají jen tuto informaci">Skrýt „Během dne ve firmě“</label>
                <?php endif; ?>
                <?php if (!$ro_no['passes']): ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm ms-md-auto" id="lh-details-toggle" aria-pressed="false" title="Rozbalí nebo sbalí detail průchodů u všech podezřelých najednou">Rozbalit všechny detaily</button>
                <?php endif; ?>
            </div>
            <div id="persons-view" class="table-responsive">
                <table class="table table-hover align-middle mb-0 lh-susp-table">
                    <thead>
                        <tr>
                            <th>Podezřelý</th>
                            <th class="sortable text-nowrap" data-sort="points" role="button" title="Body podle vybraných zdrojů">Body<span class="sort-arrow"> ▼</span></th>
                            <th class="sortable text-nowrap" data-sort="up" role="button" title="Incidenty s průchodem nahoře (4. patro)">Nahoře<span class="sort-arrow"></span></th>
                            <th class="sortable text-nowrap" data-sort="down" role="button" title="Incidenty s průchodem dole (1. patro)">Dole<span class="sort-arrow"></span></th>
                            <th class="sortable text-nowrap" data-sort="virt" role="button" title="Virtuální body IT oddělení">Virtuální<span class="sort-arrow"></span></th>
                            <th class="sortable text-nowrap" data-sort="wit" role="button" title="Incidenty se svědectvím (přesné / nepřesné)">Svědectví<span class="sort-arrow"></span></th>
                            <th>Detail průchodů v kritických oknech</th>
                            <?php if ($is_admin): ?><th>Akce</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="suspects-body">
                        <?php foreach ($suspects as $s): ?>
                            <tr class="suspect-row <?= !empty($s['is_main']) ? 'suspect-rank-1 fw-bold' : '' ?>" data-name="<?= htmlspecialchars($s['name']) ?>" data-inc="<?= htmlspecialchars(implode(',', array_column($s['details'], 'mask'))) ?>" data-incids="<?= htmlspecialchars(implode(',', array_column($s['details'], 'incident'))) ?>"<?= ($culprit_name !== '' && lh_name_norm($s['name']) === lh_name_norm($culprit_name)) ? ' data-culprit="1"' : '' ?> data-up="<?= (int)$s['up_count'] ?>" data-down="<?= (int)$s['down_count'] ?>" data-virt="<?= (int)$s['virtual_score'] ?>" data-wit="<?= (int)$s['wit_exact'] * 1000 + (int)$s['wit_vague'] ?>" data-points="<?= (int)$s['score'] ?>">
                                <td>
                                    <?= htmlspecialchars($s['name']) ?>
                                    <?php if ($innocent_ok): ?><span class="badge bg-success innocent-badge d-none"></span><?php endif; ?>
                                    <?php if (!empty($trusted_names)): ?><span class="badge bg-secondary trusted-badge d-none" title="Důvěryhodný (seznam admina)">Důvěryhodný</span><?php endif; ?>
                                    <?php if ($culprit_name !== '' && lh_name_norm($s['name']) === lh_name_norm($culprit_name)): ?><span class="badge bg-danger culprit-badge" title="Označen adminem jako dopadený pachatel">PACHATEL</span><?php endif; ?>
                                    <div class="main-badge<?= empty($s['is_main']) ? ' d-none' : '' ?>"><span class="badge bg-danger"><?= $main_label ?></span></div>
                                </td>
                                <td><span class="badge bg-danger points-badge"><?= $s['score'] ?> B</span></td>
                                <td data-label="Nahoře"><span class="badge src-up"><?= $s['up_count'] ?></span></td>
                                <td data-label="Dole"><span class="badge src-down"><?= $s['down_count'] ?></span></td>
                                <td data-label="Virtuální"><span class="badge bg-info text-dark"><?= $s['virtual_score'] ?></span></td>
                                <td data-label="Svědectví"><span class="d-inline-flex gap-1"><span class="badge src-wit" title="přesné"><?= $s['wit_exact'] ?></span><span class="badge src-wit2" title="nepřesné"><?= $s['wit_vague'] ?></span></span></td>
                                <td class="small">
                                  <?php if ($ro_no['passes']): ?>
                                    <span class="text-body-secondary">Detail průchodů není v této verzi k dispozici.</span>
                                  <?php else: ?>
                                  <details class="lh-detail">
                                    <summary>Detail průchodů (<?= count($s['details']) ?>)</summary>
                                    <?php foreach ($s['details'] as $dt): ?>
                                        <div data-mask="<?= (int)$dt['mask'] ?>"<?= !empty($dt['innocent']) ? ' data-innocent="1"' : '' ?> data-incid="<?= (int)$dt['incident'] ?>" class="lh-detail-item<?= !empty($dt['no_turnstile']) ? ' is-virtual' : '' ?><?= !empty($dt['innocent']) ? ' is-innocent' : '' ?><?= (int)$dt['mask'] === 64 ? ' is-day' : '' ?>">
                                            <strong>Incident #<?= (int)($incident_no[(int)$dt['incident']] ?? 0) ?></strong> | <strong>Kabinka:</strong> <?= htmlspecialchars($cubicles_list[$dt['cubicle']] ?? $dt['cubicle']) ?> | 
                                            <strong>Okno:</strong> <?= htmlspecialchars($dt['window']) ?>
                                            <br>
                                            <?php if (!empty($dt['innocent'])): ?>
                                                <span class="badge bg-success">Nevinný</span><?php if ((string)$dt['reason'] !== ''): ?> <span class="text-body-secondary">(<?= htmlspecialchars($dt['reason']) ?>)</span><?php endif; ?>

                                            <?php elseif ((int)$dt['mask'] === 64): ?>
                                                <span class="badge bg-secondary">Během dne ve firmě</span>
                                                <span class="text-body-secondary">bez bodu: v okně incidentu nemá průchod, mimo okno prošel(a) dveřmi.</span>
                                                <?php
                                                    $dchip = function ($ps) use ($turnstiles_list) { return '<span class="pass-chip" data-src="0">[' . htmlspecialchars($turnstiles_list[$ps['turnstile']] ?? $ps['turnstile']) . ' | ' . htmlspecialchars($ps['direction']) . ' @ ' . date('d-m H:i', strtotime($ps['passed_at'])) . ']</span>'; };
                                                ?>
                                                <?php if (!empty($dt['day_before']) || !empty($dt['day_after'])): ?>
                                                    <div class="mt-1 day-near" data-near-before="<?= htmlspecialchars((string)($dt['day_before']['passed_at'] ?? '')) ?>" data-near-after="<?= htmlspecialchars((string)($dt['day_after']['passed_at'] ?? '')) ?>">
                                                        <?php if (!empty($dt['day_before'])): ?>
                                                            <span class="text-body-secondary">Poslední před incidentem:</span> <?= $dchip($dt['day_before']) ?> <span class="small text-body-secondary">(<?= htmlspecialchars(lh_dur_txt(strtotime($dt['day_ref_start']) - strtotime($dt['day_before']['passed_at']))) ?> před začátkem okna)</span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($dt['day_after'])): ?>
                                                            <?= !empty($dt['day_before']) ? '<br>' : '' ?><span class="text-body-secondary">První po incidentu:</span> <?= $dchip($dt['day_after']) ?> <span class="small text-body-secondary">(<?= htmlspecialchars(lh_dur_txt(strtotime($dt['day_after']['passed_at']) - strtotime($dt['day_ref_end']))) ?> po nálezu)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($dt['day_passes'])): ?>
                                                    <div class="small text-body-secondary mt-1">Další průchody ten den:
                                                        <?php foreach ($dt['day_passes'] as $ps): ?> <?= $dchip($ps) ?><?php endforeach; ?>
                                                        <?= $dt['day_n'] > count($dt['day_passes']) ? '+ ' . ($dt['day_n'] - count($dt['day_passes'])) . ' dalších' : '' ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                            <span class="text-body-secondary">Průchody: 
                                            <?php if (!empty($dt['no_turnstile'])): ?>
                                                <span class="badge bg-info text-dark">VIRTUÁLNÍ BOD</span>
                                                IT oddělení, bez turniketu (v tomto období nebyl na dovolené)
                                            <?php endif; ?>
                                            <?php foreach ($dt['passes'] as $ps): ?>
                                                <span class="pass-chip" data-src="<?= in_array($ps['turnstile'], $bottom_turnstiles, true) ? 2 : 1 ?>">[<?= htmlspecialchars($turnstiles_list[$ps['turnstile']] ?? $ps['turnstile']) ?> | <?= htmlspecialchars($ps['direction']) ?> @ <?= date('H:i', strtotime($ps['passed_at'])) ?>]</span> 
                                            <?php endforeach; ?>
                                            <?php if (empty($dt['passes']) && empty($dt['no_turnstile'])): ?>bez průchodu turniketem<?php endif; ?>
                                            </span>
                                            <?php if (!empty($dt['witness'])): ?>
                                                <br><span class="text-body-secondary">Svědectví:
                                                <?php foreach ($dt['witness'] as $wi): ?>
                                                    <span class="pass-chip" data-src="<?= (int)$wi['src'] ?>">[<?= htmlspecialchars($wit_loc_short[$wi['location']] ?? $wi['location']) ?>, <?= htmlspecialchars($wit_directions[$wi['direction']] ?? '') ?> @ <?= date('H:i', strtotime($wi['seen_at'])) ?>, nahlásil <?= htmlspecialchars($wi['reporter']) ?>]</span><?php if (!empty($wi['note'])): ?> <span class="small text-body-secondary">(<?= htmlspecialchars($wi['note']) ?>)</span><?php endif; ?> 
                                                <?php endforeach; ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                  </details>
                                  <?php endif; ?>
                                </td>
                                <?php if ($is_admin): ?>
                                <td>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="blacklist_suspect">
                                        <input type="hidden" name="person_name" value="<?= htmlspecialchars($s['name']) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Trvale odstranit ze seznamu podezřelých">Odstranit</button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="suspects-empty" class="d-none">
                            <td colspan="<?= $is_admin ? 8 : 7 ?>" class="text-center text-body-secondary">Žádní podezřelí pro zvolený výběr zdrojů.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div id="incidents-view" class="d-none">
                <div id="inc-cards-empty" class="text-center text-body-secondary py-3 d-none">Není vybrán žádný incident („Zahrnout do výpočtu“ v časových oknech).</div>
                <?php foreach ($incidents as $inc): ?>
                    <?php $has_window = !empty($inc['window_start']); $people = $incident_people[$inc['id']] ?? []; $ign_hidden = empty($inc['counted']) && !$sel_enabled; ?>
                    <details class="inc-card border rounded mb-2<?= $has_window ? '' : ' inc-nowindow' ?><?= $ign_hidden ? ' inc-ignored' : '' ?>" id="inc-<?= (int)$inc['id'] ?>">
                        <summary class="p-2 d-flex flex-wrap align-items-center gap-2">
                            <span class="lh-incno">#<?= (int)($incident_no[(int)$inc['id']] ?? 0) ?></span>
                            <strong><?= htmlspecialchars($cubicles_list[$inc['cubicle']] ?? strtoupper($inc['cubicle'])) ?></strong>
                            <?= lh_label_badges($incident_label_ids[(int)$inc['id']] ?? [], $inc_labels) ?>
                            <?php if ($has_window): ?>
                                <span class="small"><?= htmlspecialchars(lh_d($inc['window_start'])) ?> &rarr; <?= htmlspecialchars(lh_d($inc['window_end'])) ?><?= $tolerance_minutes > 0 ? ' (průchody +' . (int)$tolerance_minutes . ' min)' : '' ?> · <?= lh_icon('clock', 13) ?> <?= htmlspecialchars(format_minutes((strtotime($inc['window_end']) - strtotime($inc['window_start'])) / 60)) ?></span>
                                <?php if (empty($inc['counted'])): ?>
                                    <span class="badge bg-secondary">nepočítá se</span>
                                <?php endif; ?>
                                <?php if ($is_admin && isset($incident_announced[$inc['id']])): ?><span class="badge bg-primary-subtle text-primary-emphasis">Oznámeno</span><?php endif; ?>
                                <?php if (!$ign_hidden): ?>
                                <span class="badge bg-secondary inc-count"></span>
                                <span class="badge bg-warning text-dark inc-single d-none">jediný podezřelý</span>
                                <?php if (!empty($incident_direct[$inc['id']])): ?><span class="badge src-wit">přímé svědectví</span><?php endif; ?>
                                <?php if (!empty($incident_wit_count[$inc['id']])): ?><span class="badge bg-secondary"><?= lh_icon('eye', 12) ?> pozorování: <?= (int)$incident_wit_count[$inc['id']] ?></span><?php endif; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="small">okno neznámé &rarr; <?= htmlspecialchars(lh_d($inc['window_end'])) ?></span>
                                <span class="badge bg-secondary">bez okna, podezřelé nelze určit</span>
                            <?php endif; ?>
                            <span class="small text-body-secondary ms-auto">Nález: <?= htmlspecialchars($inc['dirty_reported_by']) ?> · Poslední čisto: <?= htmlspecialchars($inc['last_ok_by']) ?></span>
                        </summary>
                        <?php if (!empty($inc['ok_note']) || !empty($inc['dirty_note'])): ?>
                            <div class="px-2 pb-1 small lh-note">
                                <?php if (!empty($inc['ok_note'])): ?><div><strong>Poznámka k poslednímu čisto (<?= htmlspecialchars($inc['last_ok_by']) ?>):</strong> <?= htmlspecialchars($inc['ok_note']) ?></div><?php endif; ?>
                                <?php if (!empty($inc['dirty_note'])): ?><div><strong>Poznámka k nálezu (<?= htmlspecialchars($inc['dirty_reported_by']) ?>):</strong> <?= htmlspecialchars($inc['dirty_note']) ?></div><?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($ign_hidden): ?>
                            <div class="px-2 pb-2 small text-body-secondary">
                                Admin tento incident vyřadil z počítání. Podezřelí se u něj nevyhodnocují a nezapočítává se do žebříčku, statistik ani předpovědi.
                                <?php if ($inc['ignore_reason'] !== ''): ?><br><strong>Důvod:</strong> <?= htmlspecialchars($inc['ignore_reason']) ?><?php endif; ?>
                            </div>
                        <?php elseif ($has_window): ?>
                            <div class="inc-people px-2 pb-2">
                                <?php if (empty($people)): ?>
                                    <div class="small text-body-secondary py-2">V tomto okně nikdo neprošel a žádný člen IT není podezřelý.</div>
                                <?php endif; ?>
                                <?php foreach ($people as $pp): ?>
                                    <div class="inc-person border-top py-2" data-person="<?= htmlspecialchars($pp['name']) ?>" data-mask="<?= (int)$pp['mask'] ?>">
                                        <div class="d-flex justify-content-between align-items-start gap-2">
                                            <div>
                                                <strong><?= htmlspecialchars($pp['name']) ?></strong>
                                                <?php if ($innocent_ok): foreach ($incident_innocents[(int)$inc['id']] ?? [] as $in_c): if (lh_name_norm($in_c['person_name']) === lh_name_norm($pp['name'])): ?><span class="badge bg-success" title="<?= htmlspecialchars((string)$in_c['reason'] !== '' ? $in_c['reason'] : 'N/A') ?>">Nevinný</span><?php endif; endforeach; endif; ?>
                                                <span class="badge bg-danger inc-person-pts">0 B</span>
                                                <?php if ($pp['mask'] & 1): ?><span class="badge src-up">Nahoře</span><?php endif; ?>
                                                <?php if ($pp['mask'] & 2): ?><span class="badge src-down">Dole</span><?php endif; ?>
                                                <?php if ($pp['mask'] & 4): ?><span class="badge bg-info text-dark">Virtuální</span><?php endif; ?>
                                                <?php if ($pp['mask'] & 8): ?><span class="badge src-wit">Svědectví přesné</span><?php endif; ?>
                                                <?php if ($pp['mask'] & 16): ?><span class="badge src-wit2">Svědectví nepřesné</span><?php endif; ?>
                                            </div>
                                            <?php if ($is_admin): ?>
                                                <form method="POST" class="m-0">
                                                    <input type="hidden" name="action" value="blacklist_suspect">
                                                    <input type="hidden" name="person_name" value="<?= htmlspecialchars($pp['name']) ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Trvale odstranit ze seznamu podezřelých">Odstranit</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                        <div class="small text-body-secondary">
                                            <?php if ($ro_no['passes']): ?>
                                                <?php if ($pp['mask'] & 4): ?><span class="badge bg-info text-dark">VIRTUÁLNÍ BOD</span> IT oddělení, bez turniketu (v tomto období nebyl na dovolené)<?php endif; ?>
                                            <?php else: ?>
                                            Průchody:
                                            <?php if ($pp['mask'] & 4): ?>
                                                <span class="badge bg-info text-dark">VIRTUÁLNÍ BOD</span> IT oddělení, bez turniketu (v tomto období nebyl na dovolené)
                                            <?php endif; ?>
                                            <?php foreach ($pp['passes'] as $ps): ?>
                                                <span class="pass-chip" data-src="<?= in_array($ps['turnstile'], $bottom_turnstiles, true) ? 2 : 1 ?>">[<?= htmlspecialchars($turnstiles_list[$ps['turnstile']] ?? $ps['turnstile']) ?> | <?= htmlspecialchars($ps['direction']) ?> @ <?= date('H:i', strtotime($ps['passed_at'])) ?>]</span> 
                                            <?php endforeach; ?>
                                            <?php if (empty($pp['passes']) && !($pp['mask'] & 4)): ?>bez průchodu turniketem<?php endif; ?>
                                            <?php endif; ?>
                                            <?php if (!empty($pp['witness'])): ?>
                                                <?= $ro_no['passes'] ? '' : '<br>' ?>Svědectví:
                                                <?php foreach ($pp['witness'] as $wi): ?>
                                                    <span class="pass-chip" data-src="<?= (int)$wi['src'] ?>">[<?= htmlspecialchars($wit_loc_short[$wi['location']] ?? $wi['location']) ?>, <?= htmlspecialchars($wit_directions[$wi['direction']] ?? '') ?> @ <?= date('H:i', strtotime($wi['seen_at'])) ?>, nahlásil <?= htmlspecialchars($wi['reporter']) ?>]</span><?php if (!empty($wi['note'])): ?> <span class="small text-body-secondary">(<?= htmlspecialchars($wi['note']) ?>)</span><?php endif; ?> 
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php $extra_wit = $incident_wit_extra[$inc['id']] ?? []; ?>
                            <?php if (!empty($extra_wit)): ?>
                                <div class="inc-extra px-2 pb-2">
                                    <div class="small fw-semibold border-top pt-2 pb-1"><?= lh_icon('eye', 14) ?> Další pozorování v okně (bez bodu)</div>
                                    <?php foreach ($extra_wit as $ew): ?>
                                        <div class="small py-1">
                                            <strong><?= htmlspecialchars($ew['name']) ?></strong>
                                            <?php foreach ($ew['items'] as $wi): ?>
                                                <span class="pass-chip" data-src="32">[<?= htmlspecialchars($wit_loc_short[$wi['location']] ?? $wi['location']) ?>, <?= htmlspecialchars($wit_directions[$wi['direction']] ?? '') ?> @ <?= date('H:i', strtotime($wi['seen_at'])) ?>, nahlásil <?= htmlspecialchars($wi['reporter']) ?>]</span><?php if (!empty($wi['note'])): ?> <span class="text-body-secondary">(<?= htmlspecialchars($wi['note']) ?>)</span><?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <p class="small text-body-secondary mt-3 mb-0">
            <strong><?= lh_icon('info', 14) ?> Upozornění:</strong> Žebříček je pouze statistická analýza průchodů turnikety v časových oknech incidentů a nepředstavuje důkaz.
            Každý je považován za nevinného, dokud mu není skutek prokázán. Body ukazují jen to, kolikrát byla osoba v době incidentu v budově nebo
            kolikrát by tam mohla být, nikoli kdo incident způsobil. Analýza může selhat například tehdy, když je pachatelů více, když někoho do dveří
            pustí jiná osoba nebo se použije cizí karta, když někdo vstoupí bez průchodu čtečkou (návštěva, externista), když je čas průchodu nebo hlášení nepřesný,
            nebo když je časové okno příliš široké. Virtuální body získávají členové IT oddělení jen proto, že nechodí přes turniket a nebyli na dovolené,
            takže nic nedokazují. Se závěry zacházejte opatrně a nikoho nevinného nepodezírejte.
        </p>

        <?php
            $bl_shown = $is_admin ? $blacklisted : array_values(array_filter($blacklisted, function($n) use ($blacklisted_with_points) { return isset($blacklisted_with_points[$n]); }));
        ?>
        <?php if (!empty($bl_shown)): ?>
            <div class="mt-3 pt-3 border-top">
                <div class="small text-body-secondary mb-2">
                    <?= $is_admin ? 'Osoby vyřazené ze žebříčku (kliknutím vrátit):' : 'Osoby, které byly adminem vyřazeny ze žebříčku, ale bez vyřazení by v něm figurovaly s alespoň jedním bodem:' ?>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($bl_shown as $b_name): ?>
                        <?php if ($is_admin): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="restore_suspect">
                            <input type="hidden" name="person_name" value="<?= htmlspecialchars($b_name) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-success" title="Vrátit do seznamu podezřelých">↩ <?= htmlspecialchars($b_name) ?></button>
                        </form>
                        <?php else: ?>
                        <span class="badge bg-secondary"><?= htmlspecialchars($b_name) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

        </div>

        <?php if (!$ro_no['history']): ?>
        <div class="tab-pane fade" id="pane-history" role="tabpanel">
    <!-- VÝPIS A FILTR ZÁZNAMŮ -->
    <div class="card p-4">
        <h3 class="card-title h5 mb-3">Historie hlášení</h3>
        
        <form method="GET" class="row g-3 mb-4">
            <div class="col-md-3">
                <label class="form-label small">Filtr podle dne</label>
                <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($filter_date) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small">Filtr podle kabinky</label>
                <select name="cubicle" class="form-select">
                    <option value="">Všechny kabinky</option>
                    <?php foreach ($cubicles_list as $c_key => $c_name): ?>
                        <option value="<?= $c_key ?>" <?= $filter_cubicle === $c_key ? 'selected' : '' ?>><?= htmlspecialchars($c_name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Filtr podle stavu</label>
                <select name="status" class="form-select">
                    <option value="">Všechny stavy</option>
                    <option value="ok" <?= $filter_status === 'ok' ? 'selected' : '' ?>>Čisto (OK)</option>
                    <option value="zesrana" <?= $filter_status === 'zesrana' ? 'selected' : '' ?>>Zesraná</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="history-limit">Počet hlášení<?= $is_admin ? '' : " (max $history_public_max)" ?></label>
                <input type="number" id="history-limit" class="form-control" min="1" <?= $is_admin ? '' : 'max="' . $history_public_max . '"' ?> value="<?= $history_default_limit ?>">
            </div>
            <div class="col-12 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-secondary">Filtrovat</button>
                <a href="index.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>

        <?php if ($is_admin && !empty($reports) && !array_key_exists('device_id', $reports[0])): ?>
            <div class="alert alert-warning small">Logování zařízení a IP zatím není aktivní – spusť SQL skript <code>device_log.sql</code>.</div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-striped table-hover lh-hist-table">
                <thead>
                    <tr>
                        <th>Čas události</th>
                        <th>Kabinka</th>
                        <th>Stav</th>
                        <th>Nahlásil</th>
                        <th>Zaznamenáno v DB</th>
                        <?php if ($is_admin): ?><th>Zařízení / IP</th><th>Akce</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody id="history-body">
                    <?= render_report_rows($reports, $cubicles_list, $is_admin, load_device_labels($pdo, $is_admin)) ?>
                </tbody>
            </table>
        </div>

        <small id="history-count" class="text-body-secondary">Zobrazeno <?= count($reports) ?> z <?= $total_reports ?> hlášení</small>
    </div>
        </div>

        <?php endif; ?>
        <?php if (!$ro_no['stats']): ?>
        <div class="tab-pane fade" id="pane-stats" role="tabpanel">
            <div id="stats-content"><?= lh_stats_render($stats_vars) ?></div>
        </div>
        <?php endif; ?>

        <?php if (!$ro_no['photos']): ?>
        <div class="tab-pane fade" id="pane-photos" role="tabpanel">
            <div class="card p-4 mb-4">
                <h3 class="card-title h5 mb-3">Fotodokumentace incidentů</h3>
                <?php if (empty($incidents)): ?>
                    <p class="text-body-secondary mb-0">Zatím nejsou žádné incidenty.</p>
                <?php else: ?>
                    <?php foreach ($incidents as $inc): ?>
                        <?php $ph_no = (int)($incident_no[(int)$inc['id']] ?? 0); $ph_list = lh_photo_files($photo_dir, $ph_no); ?>
                        <div class="py-2 border-top">
                            <div><span class="lh-incno">#<?= $ph_no ?></span> <strong><?= htmlspecialchars($cubicles_list[$inc['cubicle']] ?? strtoupper($inc['cubicle'])) ?></strong> <span class="text-body-secondary small"><?= htmlspecialchars(lh_d($inc['window_end'])) ?></span></div>
                            <?php if (empty($ph_list)): ?>
                                <div class="small text-body-secondary">K tomuto incidentu nejsou fotky.</div>
                            <?php else: ?>
                                <ul class="mb-0 mt-1">
                                    <?php foreach ($ph_list as $f_l): ?>
                                        <li><a href="?photo=<?= rawurlencode($ph_no . '/' . $f_l['name']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($f_l['name']) ?></a> <span class="small text-body-secondary">(<?= $f_l['size'] >= 1048576 ? number_format($f_l['size'] / 1048576, 1, ',', '') . ' MB' : max(1, (int)round($f_l['size'] / 1024)) . ' kB' ?>)</span></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$readonly && ($pozn_ok || $is_admin)): ?>
        <div class="tab-pane fade" id="pane-poznatky" role="tabpanel">
            <?php if (!$pozn_ok): ?>
                <div class="alert alert-warning">Chybí tabulka poznatků – spusť SQL skript <code>poznatky.sql</code>.</div>
            <?php elseif (empty($poznatky)): ?>
                <p class="text-body-secondary">Zatím žádné poznatky. Přidáš je v sekci „Chci zaznamenat“, volba „Jiný poznatek“.</p>
            <?php else: ?>
                <p class="small text-body-secondary">Sdílené poznatky: vidí je všichni, kdo mohou podávat hlášení. Nejnověji vložené jsou nahoře.</p>
                <?php foreach ($poznatky as $pz): ?>
                    <div class="lh-box mb-2" id="poznatek-<?= (int)$pz['id'] ?>">
                        <div style="white-space: pre-line; overflow-wrap: anywhere;"><?= htmlspecialchars($pz['poznatek']) ?></div>
                        <div class="small text-body-secondary mt-1">
                            <?php if ((string)$pz['person_name'] !== ''): ?><span class="badge bg-secondary">Osoba: <?= htmlspecialchars($pz['person_name']) ?></span><?php endif; ?>
                            <?php if ((string)$pz['happened_at'] !== ''): ?><span class="badge bg-info-subtle text-info-emphasis">Kdy: <?= htmlspecialchars(date('d-m-Y H:i', strtotime($pz['happened_at']))) ?></span><?php endif; ?>
                            vložil <strong><?= htmlspecialchars($pz['reporter']) ?></strong> dne <?= htmlspecialchars(date('d-m-Y H:i', strtotime($pz['created_at']))) ?>
                        </div>
                        <?php if ($is_admin): ?>
                        <details class="mt-2">
                            <summary class="small">Upravit nebo smazat</summary>
                            <form method="POST" class="row g-2 mt-1">
                                <input type="hidden" name="action" value="poznatek_edit"><input type="hidden" name="id" value="<?= (int)$pz['id'] ?>">
                                <div class="col-md-4"><label class="form-label small mb-0">Vložil</label><input type="text" name="reporter" class="form-control form-control-sm" maxlength="100" required value="<?= htmlspecialchars($pz['reporter']) ?>"></div>
                                <div class="col-md-4"><label class="form-label small mb-0">Osoba</label><input type="text" name="person_name" class="form-control form-control-sm" maxlength="150" value="<?= htmlspecialchars((string)$pz['person_name']) ?>"></div>
                                <div class="col-md-4"><label class="form-label small mb-0">Čas a datum</label><input type="datetime-local" name="happened_at" class="form-control form-control-sm" value="<?= (string)$pz['happened_at'] !== '' ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($pz['happened_at']))) : '' ?>"></div>
                                <div class="col-12"><label class="form-label small mb-0">Poznatek</label><textarea name="poznatek" class="form-control form-control-sm" rows="2" maxlength="500" required><?= htmlspecialchars($pz['poznatek']) ?></textarea></div>
                                <div class="col-12 d-flex gap-2"><button type="submit" class="btn btn-sm btn-primary">Uložit úpravy</button></div>
                            </form>
                            <form method="POST" class="mt-2" data-confirm="Opravdu smazat tento poznatek?"><input type="hidden" name="action" value="poznatek_delete"><input type="hidden" name="id" value="<?= (int)$pz['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger">Smazat</button></form>
                        </details>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($is_admin): ?>
        <div class="tab-pane fade" id="pane-admin" role="tabpanel">
    <!-- ADMIN PANEL: SBĚR DAT -->
    <div class="card p-4 my-4 lh-card-admin" id="collect-panel">
        <h3 class="card-title h5 mb-2"><?= lh_icon('alert', 20) ?> Sběr dat <?php if ($collect_off): ?><span class="badge bg-danger ms-1">vypnuto</span><?php else: ?><span class="badge bg-success ms-1">zapnuto</span><?php endif; ?></h3>
        <p class="small text-body-secondary mb-2">Jedním přepínačem vypnete vkládání informací všem <strong>kromě admina</strong>: formuláře „Chci zaznamenat“ (stav toalety, pozorování osoby, jiný poznatek) se ostatním skryjí a server jejich odeslání odmítne. Hodí se, až bude pachatel dopaden. Čtení, analýza a vaše administrace fungují beze změny a sběr jde kdykoli znovu zapnout.</p>
        <form method="POST" class="m-0" data-confirm="<?= $collect_off ? 'Zapnout sběr dat pro všechny?' : 'Opravdu vypnout sběr dat? Nikdo kromě admina už nebude moci nic odeslat.' ?>">
            <input type="hidden" name="action" value="set_collection">
            <input type="hidden" name="collect" value="<?= $collect_off ? 'on' : 'off' ?>">
            <?php if ($collect_off): ?>
                <button type="submit" class="btn btn-success">Zapnout sběr dat</button>
            <?php else: ?>
                <button type="submit" class="btn btn-danger">Vypnout sběr dat</button>
            <?php endif; ?>
        </form>
        <hr class="my-3">
        <h4 class="h6 mb-2">Dopadení pachatele</h4>
        <?php if (!$collect_off): ?>
            <p class="small text-body-secondary mb-0">Pachatele jde označit až po ukončení sběru dat (nejdřív vypněte sběr dat).</p>
        <?php else: ?>
            <?php $cu_rcpt = lh_mail_list($pdo, 'mail_digest'); $cu_can_mail = lh_mail_configured($smtp) && !empty($cu_rcpt); ?>
            <?php if ($culprit_name !== ''): ?>
                <div class="alert alert-success small mb-2">
                    Označen: <strong><?= htmlspecialchars($culprit_name) ?></strong> (<?= htmlspecialchars(lh_d($culprit_at)) ?><?= $culprit_by !== '' ? ', ' . htmlspecialchars($culprit_by) : '' ?>).
                    <?= $culprit_mailed !== '' ? 'E-mail příjemcům souhrnu odeslán ' . htmlspecialchars(lh_d($culprit_mailed)) . '.' : 'E-mail příjemcům souhrnu zatím nebyl odeslán.' ?>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php if ($cu_can_mail): ?>
                        <form method="POST" class="m-0" data-confirm="<?= $culprit_mailed !== '' ? 'E-mail už byl odeslán. Poslat ho znovu' : 'Poslat e-mail' ?> <?= count($cu_rcpt) ?> příjemcům souhrnu?">
                            <input type="hidden" name="action" value="culprit_notify">
                            <button type="submit" class="btn btn-primary"><?= $culprit_mailed !== '' ? 'Odeslat e-mail znovu' : 'Obeznámit příjemce e-mailem' ?> (<?= count($cu_rcpt) ?>)</button>
                        </form>
                    <?php endif; ?>
                    <form method="POST" class="m-0" data-confirm="Zrušit označení pachatele? Už odeslaný e-mail to nevrátí.">
                        <input type="hidden" name="action" value="culprit_clear">
                        <button type="submit" class="btn btn-outline-secondary">Zrušit označení</button>
                    </form>
                </div>
            <?php else: ?>
                <form method="POST" id="culprit-form" class="m-0" data-confirm-if="notify" data-confirm="Odeslat e-mail o dopadení <?= count($cu_rcpt) ?> příjemcům souhrnu? Nejde vzít zpět.">
                    <input type="hidden" name="action" value="culprit_set">
                    <div class="mb-2 position-relative">
                        <label class="form-label small mb-1" for="culprit-person">Kdo byl dopaden (jméno osoby)</label>
                        <input type="text" class="form-control" name="culprit_name" id="culprit-person" maxlength="100" required autocomplete="off" placeholder="Jméno a příjmení">
                        <div id="culprit-suggest" class="list-group lh-suggest d-none"></div>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="notify" value="1" id="culprit-notify" <?= $cu_can_mail ? '' : 'disabled' ?>>
                        <label class="form-check-label small" for="culprit-notify">Obeznámit e-mailem příjemce týdenního souhrnu, že pachatel byl dopaden a „dosral svoje poslední hovno“ <?= $cu_can_mail ? '(' . count($cu_rcpt) . ' adres)' : '(nelze: ' . (lh_mail_configured($smtp) ? 'seznam příjemců souhrnu je prázdný' : 'SMTP není nastaveno') . ')' ?></label>
                    </div>
                    <button type="submit" class="btn btn-danger">Označit pachatele</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- ADMIN PANEL: POZOROVÁNÍ OSOB -->
    <div class="card p-4 my-4 lh-card-admin">
        <h3 class="card-title h5 mb-2"><?= lh_icon('eye', 20) ?> Pozorování osob</h3>
        <?php if (!$wit_tables_ok): ?>
            <div class="alert alert-warning mb-0">Tabulka <code>sightings</code> neexistuje – spusť SQL skript <code>pozorovani.sql</code>.</div>
        <?php else: ?>
            <?php $wit_dev_labels = load_device_labels($pdo, $is_admin); ?>
            <p class="small text-body-secondary">Čekající pozorování se do žebříčku promítnou až po schválení. Jméno musí odpovídat docházce, jinak se neshoduje s ostatními důkazy (označeno „neznámé jméno“).</p>
            <h4 class="h6">Ke schválení (<?= count($wit_pending) ?>)</h4>
            <?php if (empty($wit_pending)): ?>
                <p class="small text-body-secondary">Žádná čekající pozorování.</p>
            <?php endif; ?>
            <?php foreach ($wit_pending as $sg): ?>
                <?= render_sighting_card($sg, $wit_locations, $wit_directions, $wit_dev_labels) ?>
            <?php endforeach; ?>
            <?php if (!empty($wit_recent)): ?>
                <h4 class="h6 mt-3">Naposledy vyřízená</h4>
                <?php foreach ($wit_recent as $sg): ?>
                    <?= render_sighting_card($sg, $wit_locations, $wit_directions, $wit_dev_labels) ?>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($is_admin): ?>
    <!-- ADMIN PANEL: IMPORT LOGŮ Z TURNIKETŮ -->
    <div class="card p-4 my-4 lh-card-admin">
        <h3 class="card-title h5 mb-3"><?= lh_icon('download', 20) ?> Import docházky z turniketů</h3>
        <?php $last_pass = lh_last_passes($pdo, $turnstiles_list); $fmt_lp = function ($t) { return $t === null ? '–' : date('d-m-Y H:i', strtotime($t)); }; ?>
        <div class="alert alert-info small py-2 mb-3" id="last-pass-info">
            <?php if ($last_pass['last'] === null): ?>
                Zatím nejsou nahrané žádné průchody.
            <?php else: ?>
                <div class="mb-1"><strong>Poslední nahraný průchod: <?= htmlspecialchars($fmt_lp($last_pass['last'])) ?></strong> (<?= htmlspecialchars(implode(', ', $last_pass['last_ts'])) ?>) · data od <?= htmlspecialchars($fmt_lp($last_pass['first'])) ?> · průchodů celkem <?= (int)$last_pass['total'] ?></div>
                <div class="table-responsive">
                    <table class="table table-sm table-borderless align-middle mb-1" style="--bs-table-bg: transparent;">
                        <thead><tr><th>Turniket</th><th>Poslední průchod</th><th class="text-end">Průchodů</th></tr></thead>
                        <tbody>
                        <?php foreach ($last_pass['by'] as $lp_key => $lp): ?>
                            <tr data-ts="<?= htmlspecialchars($lp_key) ?>"><td><?= htmlspecialchars($lp['name']) ?></td><td><?= htmlspecialchars($fmt_lp($lp['last'])) ?></td><td class="text-end"><?= (int)$lp['n'] ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div>Další várku vlož <strong>od data posledního průchodu</strong> daného turniketu: duplicity se přeskočí, takže překryv nevadí. Poslední den bývá neúplný, protože export končí v okamžiku stažení.</div>
            <?php endif; ?>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="import_logs">
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label small">Datum docházky *</label>
                    <input type="date" name="log_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Turniket / Čtečka *</label>
                    <select name="turnstile" class="form-select" required>
                        <?php foreach ($turnstiles_list as $t_key => $t_name): ?>
                            <option value="<?= $t_key ?>"><?= htmlspecialchars($t_name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small">Vlož docházku (po třech řádcích: <code>in/out</code>, <code>Jméno (login)</code>, <code>HH:MM</code>)</label>
                <textarea name="raw_text" class="form-control font-monospace" rows="6" placeholder="in&#10;Petr Novák (pnovak)&#10;12:33&#10;out&#10;Jan Svoboda (jsvoboda)&#10;11:52" required></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Importovat a přepočítat podezřelé</button>
        </form>
        
    </div>
    <?php endif; ?>

    <?php if ($is_admin): ?>
    <!-- ADMIN PANEL: IT ODDĚLENÍ A DOVOLENÉ -->
    <div class="card p-4 mb-4 lh-card-admin">
        <h3 class="card-title h5 mb-2"><?= lh_icon('monitor', 20) ?> IT oddělení a dovolené</h3>
        <p class="small text-body-secondary">Dokud nemají IT dveře čtečku, jsou IT v každém incidentu podezřelí, pokud nebyli na dovolené po celou dobu jeho časového okna (virtuální bod). Datum „Virtuální body do“ určuje, od kdy už se virtuální body nepřidělují: platí jen u incidentů, jejichž okno začíná před tímto datem. Průchody dveřmi IT se počítají jako 4. patro.</p>

        <?php if (!$it_tables_ok): ?>
            <div class="alert alert-warning mb-0">Tabulky <code>it_staff</code> a <code>it_vacations</code> neexistují – spusť SQL skript <code>it_dovolene.sql</code>.</div>
        <?php else: ?>
            <div class="row g-4">
                <div class="col-md-5">
                    <h4 class="h6">Členové IT</h4>
                    <?php if (!$it_has_until): ?>
                        <div class="alert alert-warning small">Chybí sloupec <code>virtual_until</code> – spusť SQL skript <code>it_pikacka.sql</code>.</div>
                    <?php else: ?>
                        <form method="POST" class="d-flex gap-2 align-items-end mb-3" data-confirm="Nastavit toto datum všem členům IT?">
                            <input type="hidden" name="action" value="it_set_until_all">
                            <div class="flex-grow-1">
                                <label class="form-label small mb-1">Virtuální body všem do (prázdné = bez omezení)</label>
                                <input type="date" name="virtual_until" class="form-control form-control-sm">
                            </div>
                            <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Nastavit všem</button>
                        </form>
                    <?php endif; ?>
                    <form method="POST" class="d-flex gap-2 mb-3">
                        <input type="hidden" name="action" value="it_add_staff">
                        <input type="text" name="person_name" class="form-control" placeholder="Jméno Příjmení (stejně jako v docházce)" required>
                        <button type="submit" class="btn btn-primary text-nowrap">Přidat</button>
                    </form>
                    <?php if (empty($it_staff)): ?>
                        <p class="small text-body-secondary m-0">Zatím žádní členové IT.</p>
                    <?php else: ?>
                        <ul class="list-group">
                            <?php foreach ($it_staff as $it): ?>
                                <li class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span><?= htmlspecialchars($it['person_name']) ?></span>
                                        <form method="POST" class="m-0" data-confirm="Odebrat člena IT včetně jeho dovolených?">
                                            <input type="hidden" name="action" value="it_remove_staff">
                                            <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Odebrat</button>
                                        </form>
                                    </div>
                                    <?php if ($it_has_until): ?>
                                        <form method="POST" class="d-flex gap-2 align-items-center mt-2">
                                            <input type="hidden" name="action" value="it_set_until">
                                            <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                            <label class="small text-body-secondary text-nowrap m-0">Virtuální body do</label>
                                            <input type="date" name="virtual_until" class="form-control form-control-sm" value="<?= htmlspecialchars($it['virtual_until'] ?? '') ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">Uložit</button>
                                        </form>
                                        <div class="small text-body-secondary mt-1"><?= !empty($it['virtual_until']) ? 'Virtuální body se přidělují u incidentů před ' . htmlspecialchars(lh_d($it['virtual_until'])) . '.' : 'Virtuální body bez omezení.' ?></div>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div class="col-md-7">
                    <h4 class="h6">Dovolená</h4>
                    <?php if (empty($it_staff)): ?>
                        <p class="small text-body-secondary m-0">Nejdřív přidej člena IT.</p>
                    <?php else: ?>
                        <form method="POST" class="row g-2 mb-3">
                            <input type="hidden" name="action" value="it_add_vacation">
                            <div class="col-12">
                                <select name="staff_id" class="form-select" required>
                                    <option value="">-- Vyber člena IT --</option>
                                    <?php foreach ($it_staff as $it): ?>
                                        <option value="<?= (int)$it['id'] ?>"><?= htmlspecialchars($it['person_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-1">Od *</label>
                                <input type="date" name="date_from" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-1">Do (prázdné = jeden den)</label>
                                <input type="date" name="date_to" class="form-control">
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">Uložit dovolenou</button>
                            </div>
                        </form>

                        <?php if (!empty($it_vacation_rows)): ?>
                            <?php $it_names = array_column($it_staff, 'person_name', 'id'); ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr><th>Kdo</th><th>Od</th><th>Do</th><th></th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($it_vacation_rows as $v): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($it_names[$v['staff_id']] ?? '?') ?></td>
                                                <td><?= htmlspecialchars(lh_d($v['date_from'])) ?></td>
                                                <td><?= htmlspecialchars(lh_d($v['date_to'])) ?></td>
                                                <td class="text-end">
                                                    <form method="POST" class="m-0" data-confirm="Smazat tuto dovolenou?">
                                                        <input type="hidden" name="action" value="it_delete_vacation">
                                                        <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Smazat</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ADMIN PANEL: VÝBĚR INCIDENTŮ UŽIVATELEM -->
    <div class="card p-4 mb-4 lh-card-admin">
        <h3 class="card-title h5 mb-2"><?= lh_icon('target', 20) ?> Výběr incidentů pro výpočty (podezřelí, statistiky, předpověď)</h3>
        <p class="small text-body-secondary">Zaškrtávátko „Zahrnout do výpočtu“ u incidentů v seznamu časových oken dá uživateli možnost zvolit, ze kterých incidentů se počítají podezřelí, statistiky i předpověď. Výběr se neukládá, po načtení stránky je zaškrtnutý každý incident, který se počítá. Statistiky a předpověď se výběrem neřídí. Uživatel s touto funkcí uvidí i data incidentů, které jste vyřadil.</p>
        <?php if (!$settings_ok): ?>
            <div class="alert alert-warning small mb-0">Chybí tabulka <code>app_settings</code> – spusť SQL skript <code>nastaveni_aplikace.sql</code>.</div>
        <?php else: ?>
            <form method="POST" class="mb-2">
                <input type="hidden" name="action" value="set_setting">
                <input type="hidden" name="setting_key" value="incident_select_global">
                <input type="hidden" name="setting_value" value="<?= $sel_global ? '0' : '1' ?>">
                <div class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="set-sel-global" <?= $sel_global ? 'checked' : '' ?> data-autosubmit="1">
                    <label class="form-check-label" for="set-sel-global">Zapnout pro všechny uživatele</label>
                </div>
            </form>
            <form method="POST" class="m-0">
                <input type="hidden" name="action" value="set_setting">
                <input type="hidden" name="setting_key" value="incident_select_admin">
                <input type="hidden" name="setting_value" value="<?= $sel_admin ? '0' : '1' ?>">
                <div class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="set-sel-admin" <?= $sel_admin ? 'checked' : '' ?> data-autosubmit="1">
                    <label class="form-check-label" for="set-sel-admin">Zapnout jen pro admina (zkouška)<?= $sel_global ? ' – bez efektu, funkce je zapnutá pro všechny' : '' ?></label>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <!-- ADMIN PANEL: NEVINNÍ -->
    <div class="card p-4 mb-4 lh-card-admin">
        <h3 class="card-title h5 mb-2"><?= lh_icon('eye', 20) ?> Nevinní u incidentů</h3>
        <p class="small text-body-secondary">Osoba označená u incidentu jako nevinná se ukáže všem v detailu incidentu a v žebříčku v sekci „Nevinní“. Důvod je nepovinný. Stejné jméno u stejného incidentu znovu = změna důvodu. Nijak to neovlivňuje body.</p>
        <?php if (!$innocent_ok): ?>
            <div class="alert alert-warning small mb-0">Chybí tabulka <code>incident_innocents</code> – spusť SQL skript <code>nevinni.sql</code>.</div>
        <?php else: ?>
            <form method="POST" class="mb-3" id="innocent-form">
                <input type="hidden" name="action" value="innocent_add">
                <div class="row g-2 align-items-end">
                    <div class="col-md-5 position-relative">
                        <label class="form-label small mb-1" for="innocent-person">Osoby (jedna na řádek, lze vložit celý seznam)</label>
                        <textarea name="person_names" id="innocent-person" class="form-control" rows="4" required autocomplete="off" placeholder="Jan Novák&#10;Marie Svobodová"></textarea>
                        <div id="innocent-suggest" class="list-group lh-suggest d-none"></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1" for="innocent-reason">Důvod (nepovinný, platí pro všechny vybrané osoby a incidenty)</label>
                        <input type="text" name="reason" id="innocent-reason" class="form-control" maxlength="200" placeholder="např. dovolená">
                    </div>
                    <div class="col-md-3"><button type="submit" class="btn btn-primary w-100">Přidat vybraným</button></div>
                </div>
                <div class="mt-3">
                    <div class="small fw-semibold mb-1">Incidenty (lze vybrat víc najednou, zaškrtnutím data se vyberou všechny incidenty z toho dne)</div>
                    <div class="border rounded p-2" style="max-height: 14rem; overflow-y: auto;">
                        <?php
                            $by_day = [];
                            foreach ($incidents as $inc_o) { $by_day[substr($inc_o['window_end'], 0, 10)][] = $inc_o; }
                        ?>
                        <?php foreach ($by_day as $day => $day_list): ?>
                            <div class="mb-2 innocent-day-group">
                                <label class="fw-semibold small d-block m-0"><input type="checkbox" class="form-check-input me-1 innocent-day"> <?= htmlspecialchars(lh_d($day)) ?></label>
                                <?php foreach ($day_list as $inc_o): ?>
                                    <label class="d-inline-flex align-items-center gap-1 me-3 small"><input type="checkbox" class="form-check-input m-0 innocent-inc" name="report_ids[]" value="<?= (int)$inc_o['id'] ?>"> #<?= (int)($incident_no[(int)$inc_o['id']] ?? 0) ?> <?= htmlspecialchars($cubicles_list[$inc_o['cubicle']] ?? $inc_o['cubicle']) ?> <?= htmlspecialchars(substr($inc_o['window_end'], 11, 5)) ?></label>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </form>
            <?php $any_inn = false; foreach ($incidents as $inc_o): $in_list = $incident_innocents[(int)$inc_o['id']] ?? []; if (!$in_list) { continue; } $any_inn = true; ?>
                <div class="py-2 border-top">
                    <div><span class="lh-incno">#<?= (int)($incident_no[(int)$inc_o['id']] ?? 0) ?></span> <strong><?= htmlspecialchars($cubicles_list[$inc_o['cubicle']] ?? $inc_o['cubicle']) ?></strong> <span class="small text-body-secondary"><?= htmlspecialchars(lh_d($inc_o['window_end'])) ?></span></div>
                    <?php foreach ($in_list as $in_r): ?>
                        <form method="POST" class="d-flex flex-wrap align-items-center gap-2 mt-1">
                            <input type="hidden" name="action" value="innocent_delete">
                            <input type="hidden" name="id" value="<?= (int)$in_r['id'] ?>">
                            <span><?= htmlspecialchars($in_r['person_name']) ?><?= (string)$in_r['reason'] !== '' ? ' <span class="text-body-secondary">(' . htmlspecialchars($in_r['reason']) . ')</span>' : '' ?></span>
                            <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Zrušit označení nevinné osoby?">Zrušit</button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$any_inn): ?><div class="small text-body-secondary">Zatím nejsou u žádného incidentu zadané nevinné osoby.</div><?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- ADMIN PANEL: SEZNAM VŠECH ZAMĚSTNANCŮ -->
    <div class="card p-4 mb-4 lh-card-admin">
        <h3 class="card-title h5 mb-2"><?= lh_icon('eye', 20) ?> Seznam všech zaměstnanců</h3>
        <p class="small text-body-secondary">Jeden zaměstnanec na řádek (jméno a příjmení, nejlépe stejně jako v docházce). Z tohoto seznamu se v žebříčku podezřelých počítá sekce „Osoby bez alibi“: zaměstnanci, kteří u vyhodnocovaných incidentů nemají zadanou žádnou nevinnost (bez ohledu na body). Osoby vyřazené ze žebříčku se v ní nezobrazují. Uložením se celý seznam nahradí.</p>
        <?php if (!$employees_ok): ?>
            <div class="alert alert-warning small mb-0">Chybí tabulka <code>employees</code> – spusť SQL skript <code>zamestnanci.sql</code>.</div>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="employees_save">
                <textarea class="form-control mb-3" name="employees" rows="10" placeholder="Jan Novák&#10;Marie Svobodová"><?= htmlspecialchars(implode("\n", $employee_names)) ?></textarea>
                <button type="submit" class="btn btn-primary">Uložit seznam</button>
                <span class="small text-body-secondary ms-2">Teď uloženo: <?= count($employee_names) ?> zaměstnanců</span>
            </form>
        <?php endif; ?>
    </div>

    <!-- ADMIN PANEL: ŠTÍTKY INCIDENTŮ -->
    <div class="card p-4 mb-4 lh-card-admin">
        <h3 class="card-title h5 mb-2"><?= lh_icon('target', 20) ?> Štítky incidentů</h3>
        <p class="small text-body-secondary">Štítky označují třeba vzorec znečištění (prkýnko, mísa vpředu). Vytvářet, upravovat a mazat je smí jen admin. Přiřazují se u jednotlivých incidentů (po rozbalení v seznamu incidentů) a vidí je všichni. Smazáním štítku zmizí i u všech incidentů.</p>
        <?php if (!$labels_ok): ?>
            <div class="alert alert-warning small mb-0">Chybí tabulky štítků – spusť SQL skript <code>stitky.sql</code>.</div>
        <?php else: ?>
            <?php $palette = ['#e11d48', '#2563eb', '#16a34a', '#d97706', '#7c3aed', '#0891b2', '#db2777', '#4b5563']; ?>
            <?php foreach ($inc_labels as $lb): ?>
                <form method="POST" class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <input type="hidden" name="action" value="label_save">
                    <input type="hidden" name="id" value="<?= (int)$lb['id'] ?>">
                    <input type="color" name="color" class="form-control form-control-color" value="<?= htmlspecialchars(lh_label_color($lb['color'])) ?>" title="Barva">
                    <input type="text" name="name" class="form-control" maxlength="40" required value="<?= htmlspecialchars($lb['name']) ?>" style="max-width: 16rem;">
                    <span><?= lh_label_badge($lb) ?></span>
                    <button type="submit" class="btn btn-sm btn-outline-secondary">Uložit</button>
                    <button type="submit" name="action" value="label_delete" class="btn btn-sm btn-outline-danger" data-confirm="Smazat štítek? Zmizí i u všech incidentů." formnovalidate>Smazat</button>
                </form>
            <?php endforeach; ?>
            <form method="POST" class="d-flex flex-wrap align-items-center gap-2 mt-3 pt-3 border-top">
                <input type="hidden" name="action" value="label_save">
                <input type="color" name="color" class="form-control form-control-color" value="<?= $palette[count($inc_labels) % count($palette)] ?>" title="Barva">
                <input type="text" name="name" class="form-control" maxlength="40" required placeholder="Nový štítek (např. prkýnko)" style="max-width: 16rem;">
                <button type="submit" class="btn btn-primary">Vytvořit štítek</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- ADMIN PANEL: TOLERANCE -->
    <div class="card p-4 mb-4 lh-card-admin">
        <h3 class="card-title h5 mb-2"><?= lh_icon('clock', 20) ?> Tolerance po nálezu incidentu</h3>
        <p class="small text-body-secondary">Časové okno incidentu trvá od posledního hlášení „čisto“ po první hlášení „zesraná“. K oknu se navíc počítá tolerance po nálezu, ale <strong>jen pro průchody turniketem</strong>: průchod až do tolika minut po nálezu se bere, jako by byl v okně (viník mohl po útoku odejít třeba na cigaretu a vrátit se). <strong>Pozorování osob se tolerancí neřídí</strong>, platí jen přímo v okně, protože kdo byl viděn na toaletách až po nálezu, to spíš nebyl on. Výchozí hodnota je 5 minut. Změna platí pro <strong>všechny incidenty včetně starších</strong> a přepočítá body, žebříček, statistiky i předpověď.</p>
        <?php if (!$settings_ok): ?>
            <div class="alert alert-warning small mb-0">Chybí tabulka <code>app_settings</code> – spusť SQL skript <code>nastaveni_aplikace.sql</code>.</div>
        <?php else: ?>
            <form method="POST" class="d-flex align-items-end gap-2 flex-wrap">
                <input type="hidden" name="action" value="set_tolerance">
                <div>
                    <label class="form-label small mb-1" for="tolerance-minutes">Tolerance (minuty, 0 až 60)</label>
                    <input type="number" class="form-control" id="tolerance-minutes" name="tolerance_minutes" min="0" max="60" step="1" value="<?= (int)$tolerance_minutes ?>" required style="max-width: 9rem;">
                </div>
                <button type="submit" class="btn btn-primary">Uložit</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- ADMIN PANEL: PŘIHLÁŠENÍ UŽIVATELŮ -->
    <?php
        $seen_hdrs = [];
        foreach ($_SERVER as $hk => $hv) {
            if (strpos($hk, 'HTTP_') === 0 && preg_match('/^HTTP_(REMOTE_|X_REMOTE_|X_AUTH|X_FORWARDED_(USER|EMAIL|GROUPS|PREFERRED)|X_AUTHENTIK|X_LH_)/', $hk)) {
                $nm = implode('-', array_map('ucfirst', explode('_', strtolower(substr($hk, 5)))));
                $seen_hdrs[$nm] = $hk === 'HTTP_X_LH_PROXY' ? ($proxy_secret_ok ? '(přítomná, klíč platný)' : '(přítomná, klíč NEPLATNÝ)') : mb_substr((string)$hv, 0, 100);
            }
        }
        $cfg_admin_users = (array)($config['admin_users'] ?? []);
    ?>
    <div class="card p-4 mb-4 lh-card-admin">
        <h3 class="card-title h5 mb-2"><?= lh_icon('eye', 20) ?> Přihlášení uživatelů</h3>
        <p class="small text-body-secondary">Jméno uživatele předává reverzní proxy v hlavičce a aplikace ho přijme jen s platnou tajnou hlavičkou <span class="font-monospace">X-Lh-Proxy</span>. Uživatel uvedený v <span class="font-monospace">admin_users</span> v konfiguraci je admin bez hesla. Ostatním přihlášeným se jméno předvyplní v hlášeních a nejde změnit.</p>
        <table class="table table-sm mb-3">
            <tbody>
                <tr><td>Hlavička s uživatelem (konfigurace)</td><td class="font-monospace"><?= htmlspecialchars((string)($config['auth_user_header'] ?? 'Remote-User')) ?></td></tr>
                <tr><td>Tajná hlavička X-Lh-Proxy</td><td><?= $proxy_secret_ok ? 'dorazila, klíč platný' : 'nedorazila nebo je klíč neplatný (identita se ignoruje)' ?></td></tr>
                <tr><td>Rozpoznaný uživatel (vy)</td><td><?= $auth_user !== '' ? htmlspecialchars($audit_user) . ($identity_admin ? ' <span class="badge bg-primary">admin podle seznamu</span>' : '') : '<span class="text-body-secondary">žádný (hlavička nedorazila nebo je neplatná)</span>' ?></td></tr>
                <tr><td>Admin uživatelé v konfiguraci</td><td><?= $cfg_admin_users ? htmlspecialchars(implode(', ', $cfg_admin_users)) : '<span class="text-body-secondary">žádní (admin_users není nastaveno)</span>' ?></td></tr>
            </tbody>
        </table>
        <div class="small text-body-secondary mb-1">Hlavičky týkající se přihlášení, které dorazily s tímto požadavkem:</div>
        <?php if (empty($seen_hdrs)): ?>
            <div class="small text-body-secondary">Žádné.</div>
        <?php else: ?>
            <table class="table table-sm mb-0">
                <tbody>
                    <?php foreach ($seen_hdrs as $hn => $hv2): ?><tr><td class="font-monospace small"><?= htmlspecialchars($hn) ?></td><td class="small"><?= htmlspecialchars($hv2) ?></td></tr><?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- ADMIN PANEL: ZAŘÍZENÍ -->
    <?php $dev_list = lh_device_list($pdo, load_device_labels($pdo, true)); ?>
    <div class="card p-4 mb-4 lh-card-admin">
        <h3 class="card-title h5 mb-2"><?= lh_icon('eye', 20) ?> Zařízení</h3>
        <p class="small text-body-secondary">Zařízení se rozpoznávají podle podepsané cookie (otisk prohlížeče). Vidíte ta, ze kterých někdo podal hlášení nebo pozorování, nebo na kterých byl otevřen admin. Označení <span class="font-monospace">#abc123</span> je stejné jako v e-mailech se záznamem akcí.</p>
        <?php if (!$device_seen_ok): ?>
            <div class="alert alert-warning small">Chybí tabulka <code>device_seen</code> – spusť SQL skript <code>zarizeni.sql</code>. Bez ní se nezobrazí zařízení, ze kterých nebylo nic hlášeno.</div>
        <?php endif; ?>
        <?php if (empty($dev_list)): ?>
            <p class="text-body-secondary mb-0">Zatím nejsou žádná zařízení.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Zařízení</th><th>Název</th><th>Poslední IP</th><th>Poslední aktivita</th><th class="text-end">Hlášení</th><th class="text-end">Pozorování</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($dev_list as $dv_id => $dv): ?>
                        <tr>
                            <td>
                                <span class="font-monospace" title="<?= htmlspecialchars($dv_id) ?>">#<?= htmlspecialchars(substr($dv_id, 0, 6)) ?></span>
                                <?php if ($dv_id === $device_id): ?><span class="badge bg-primary">toto zařízení</span><?php endif; ?>
                            </td>
                            <td><?= $dv['label'] !== '' ? htmlspecialchars($dv['label']) : '<span class="text-body-secondary">bez názvu</span>' ?></td>
                            <td class="font-monospace small"><?= htmlspecialchars($dv['ip'] !== '' ? $dv['ip'] : '–') ?></td>
                            <td class="small"><?= htmlspecialchars($dv['last'] !== '' ? lh_d($dv['last']) : '–') ?></td>
                            <td class="text-end"><?= (int)$dv['reports'] ?></td>
                            <td class="text-end"><?= (int)$dv['sightings'] ?></td>
                            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-secondary btn-device-label" data-device="<?= htmlspecialchars($dv_id) ?>" data-label="<?= htmlspecialchars($dv['label']) ?>"><?= $dv['label'] !== '' ? 'Přejmenovat' : 'Pojmenovat' ?></button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ADMIN PANEL: E-MAILY -->
    <div class="card p-4 mb-4 lh-card-admin">
        <h3 class="card-title h5 mb-2"><?= lh_icon('eye', 20) ?> E-maily</h3>
        <p class="small text-body-secondary"><strong>Admin e-maily</strong> dostávají upozornění na nový incident, na nové pozorování ke schválení a záznam každé akce admina (úpravy, mazání, vyřazování, nastavení, přihlášení). <strong>Příjemci týdenního souhrnu</strong> dostanou počty incidentů bez jmen osob (zasílá se z domácího serveru skriptem lh-digest.sh). Adresy oddělte novým řádkem nebo čárkou.</p>
        <?php if (!lh_mail_configured($smtp)): ?>
            <div class="alert alert-warning small">SMTP není nastaveno v <code>lh_config.php</code> (smtp_host, smtp_user, smtp_pass, smtp_from). E-maily se neodesílají.</div>
        <?php endif; ?>
        <?php if (!$settings_ok): ?>
            <div class="alert alert-warning small mb-0">Chybí tabulka <code>app_settings</code> – spusť SQL skript <code>nastaveni_aplikace.sql</code>.</div>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="set_mail_lists">
                <label class="form-label small mb-1" for="mail-admins">Admin e-maily</label>
                <textarea class="form-control mb-3" id="mail-admins" name="mail_admins" rows="3" placeholder="admin@example.cz"><?= htmlspecialchars(implode("\n", lh_mail_list($pdo, 'mail_admins'))) ?></textarea>
                <label class="form-label small mb-1" for="mail-digest">Příjemci týdenního souhrnu (libovolné adresy)</label>
                <textarea class="form-control mb-3" id="mail-digest" name="mail_digest" rows="3" placeholder="kolega@example.cz"><?= htmlspecialchars(implode("\n", lh_mail_list($pdo, 'mail_digest'))) ?></textarea>
                <button type="submit" class="btn btn-primary">Uložit seznamy</button>
            </form>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <form method="POST" class="m-0"><input type="hidden" name="action" value="mail_test"><button type="submit" class="btn btn-sm btn-outline-secondary">Odeslat zkušební e-mail adminům</button></form>
                <form method="POST" class="m-0"><input type="hidden" name="action" value="digest_test"><button type="submit" class="btn btn-sm btn-outline-secondary" title="Pošle souhrn jen adminům a nezmění časovač pravidelného odesílání">Poslat testovací digest adminům</button></form>
                <form method="POST" class="m-0" data-confirm="Odeslat týdenní souhrn příjemcům teď? Tím se spustí časovač a pravidelný souhrn se do 24 hodin neodešle."><input type="hidden" name="action" value="digest_now"><button type="submit" class="btn btn-sm btn-outline-secondary">Odeslat týdenní souhrn teď</button></form>
            </div>
            <?php $mle = lh_setting($pdo, 'mail_last_error'); $dls = lh_setting($pdo, 'digest_last_sent'); ?>
            <?php if ($dls !== ''): ?><div class="small text-body-secondary mt-2">Poslední týdenní souhrn: <?= htmlspecialchars(lh_d($dls)) ?></div><?php endif; ?>
            <?php if ($mle !== ''): ?><div class="alert alert-danger small mt-3 mb-0">Poslední chyba odesílání: <?= htmlspecialchars(lh_d($mle)) ?></div><?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- ADMIN PANEL: MILNÍKY -->
    <div class="card p-4 mb-4 lh-card-admin" id="ms-panel">
        <h3 class="card-title h5 mb-2"><?= lh_icon('flag', 20) ?> Milníky</h3>
        <p class="small text-body-secondary">Důležité informace s datem: osoba skončila, nastoupila nebo je pryč, změna provozu, jiná informace. Zadává je jen admin, vidí je všichni (v seznamu incidentů a ve statistikách). U osob se v detailu incidentu nabídnou ti, kdo byli po celé okno incidentu mimo firmu, a lze jim jedním klepnutím udělit nevinnost.</p>
        <?php if (!$milestones_ok): ?>
            <div class="alert alert-warning small mb-0">Chybí tabulka milníků – spusť SQL skript <code>milniky.sql</code>.</div>
        <?php else: ?>
            <form method="POST" id="ms-form" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="milestone_save">
                <input type="hidden" name="id" id="ms-id" value="0">
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="ms-kind">Typ</label>
                    <select class="form-select" name="kind" id="ms-kind">
                        <?php foreach (lh_ms_kinds() as $mk => $mn): ?><option value="<?= $mk ?>"><?= htmlspecialchars($mn) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 position-relative" id="ms-person-box">
                    <label class="form-label small mb-1" for="ms-person">Osoba</label>
                    <input type="text" class="form-control" name="person_name" id="ms-person" maxlength="100" autocomplete="off" placeholder="Jméno a příjmení">
                    <div id="ms-suggest" class="list-group lh-suggest d-none"></div>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="ms-from" id="ms-from-label">Datum</label>
                    <input type="date" class="form-control" name="date_from" id="ms-from" required>
                </div>
                <div class="col-md-2" id="ms-to-box">
                    <label class="form-label small mb-1" for="ms-to">Do (včetně)</label>
                    <input type="date" class="form-control" name="date_to" id="ms-to">
                </div>
                <div class="col-md-6">
                    <label class="form-label small mb-1" for="ms-title" id="ms-title-label">Název</label>
                    <input type="text" class="form-control" name="title" id="ms-title" maxlength="120" placeholder="např. nová čtečka, kontroly po 30 minutách">
                </div>
                <div class="col-md-6">
                    <label class="form-label small mb-1" for="ms-note">Poznámka (nepovinná)</label>
                    <input type="text" class="form-control" name="note" id="ms-note" maxlength="500">
                </div>
                <div class="col-12 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary" id="ms-submit">Uložit milník</button>
                    <button type="button" class="btn btn-outline-secondary d-none" id="ms-cancel">Zrušit úpravu</button>
                </div>
            </form>
            <?php if ($milestones): ?>
            <div class="table-responsive mt-3">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Datum</th><th>Milník</th><th>Poznámka</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($milestones as $mrow): ?>
                        <tr data-ms="<?= htmlspecialchars(json_encode(['id' => (int)$mrow['id'], 'kind' => $mrow['kind'], 'person' => (string)$mrow['person_name'], 'from' => $mrow['date_from'], 'to' => (string)$mrow['date_to'], 'title' => $mrow['title'], 'note' => (string)$mrow['note']], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
                            <td class="text-nowrap"><?= htmlspecialchars(date('d-m-Y', strtotime($mrow['date_from']))) ?></td>
                            <td><?= htmlspecialchars(lh_ms_text($mrow, $pdo)) ?></td>
                            <td class="small text-body-secondary"><?= htmlspecialchars((string)$mrow['note']) ?></td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary ms-edit">Upravit</button>
                                <form method="POST" class="d-inline" data-confirm="Smazat milník?">
                                    <input type="hidden" name="action" value="milestone_delete">
                                    <input type="hidden" name="id" value="<?= (int)$mrow['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Smazat</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- ADMIN PANEL: DŮVĚRYHODNÍ -->
    <div class="card p-4 mb-4 lh-card-admin" id="trusted-panel">
        <h3 class="card-title h5 mb-2"><?= lh_icon('eye', 20) ?> Důvěryhodní</h3>
        <p class="small text-body-secondary">Osoby z našeho oddělení, u kterých se silně domníváme, že nejsou pachatel. V žebříčku je filtr „Skrýt důvěryhodné“ (výchozí zapnutý), který je skrývá na stejných místech jako „Skrýt nevinné“. Mění jen zobrazení, ne body ani analýzu. Seznam vidí a filtr používají všichni, upravuje ho jen admin.</p>
        <?php if (!$trusted_ok): ?>
            <div class="alert alert-warning small mb-0">Chybí tabulka důvěryhodných – spusť SQL skript <code>duveryhodni.sql</code>.</div>
        <?php else: ?>
            <form method="POST" id="trusted-form" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="trusted_add">
                <div class="col-md-8 position-relative">
                    <label class="form-label small mb-1" for="trusted-person">Osoby (jedna na řádek, lze vložit celý seznam)</label>
                    <textarea name="trusted_names" id="trusted-person" class="form-control" rows="3" required autocomplete="off" placeholder="Jan Novák&#10;Marie Svobodová"></textarea>
                    <div id="trusted-suggest" class="list-group lh-suggest d-none"></div>
                </div>
                <div class="col-md-4"><button type="submit" class="btn btn-primary w-100">Přidat do seznamu</button></div>
            </form>
            <div class="mt-3">
                <div class="small fw-semibold mb-1">V seznamu (<span id="trusted-count"><?= count($trusted_names) ?></span>)</div>
                <?php if (empty($trusted_names)): ?>
                    <div class="small text-body-secondary">Zatím nikdo. Filtr „Skrýt důvěryhodné“ se v žebříčku ukáže, jakmile v seznamu někdo bude.</div>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($trusted_names as $tn): ?>
                            <form method="POST" class="d-inline m-0" data-confirm="Odebrat osobu ze seznamu Důvěryhodní?">
                                <input type="hidden" name="action" value="trusted_delete"><input type="hidden" name="person_name" value="<?= htmlspecialchars($tn) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Odebrat ze seznamu"><?= htmlspecialchars($tn) ?> ✕</button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ADMIN PANEL: ALIASY JMEN -->
    <div class="card p-4 mb-4 lh-card-admin" id="alias-panel">
        <h3 class="card-title h5 mb-2"><?= lh_icon('search', 20) ?> Aliasy jmen</h3>
        <p class="small text-body-secondary">Sloučí varianty jména téhož člověka (např. přihlašovací jméno a celé jméno) na jedno hlavní jméno. Alias se přepíše ve všech datech (průchody, pozorování, nevinnosti, zaměstnanci, vyřazení, členové IT, milníky, poznatky) a při dalším importu docházky a zadávání osob se změní sám. Jména hlásících se nemění. <strong>Sloučení nejde vrátit</strong>, proto před ním stáhni zálohu a použij „Náhled změn“.</p>
        <?php if (!$aliases_ok): ?>
            <div class="alert alert-warning small mb-0">Chybí tabulka aliasů – spusť SQL skript <code>aliasy.sql</code>.</div>
        <?php else: ?>
            <form method="POST" id="alias-form" class="row g-2" data-confirm="Opravdu sloučit? Jména se přepíšou ve všech datech a nejde to vrátit zpět.">
                <input type="hidden" name="action" value="alias_merge">
                <div class="col-md-5 position-relative">
                    <label class="form-label small mb-1" for="alias-canon">Hlavní jméno (zůstane)</label>
                    <input type="text" class="form-control" name="canonical" id="alias-canon" maxlength="100" required autocomplete="off" placeholder="Jméno a příjmení">
                    <div id="alias-canon-suggest" class="list-group lh-suggest d-none"></div>
                </div>
                <div class="col-md-7">
                    <label class="form-label small mb-1" for="alias-list">Aliasy (jeden na řádek; přepíšou se na hlavní jméno)</label>
                    <textarea class="form-control" name="aliases" id="alias-list" rows="3" required placeholder="např. pnovak"></textarea>
                </div>
                <div class="col-12 d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-secondary" id="alias-preview-btn">Náhled změn</button>
                    <button type="submit" class="btn btn-danger">Sloučit</button>
                    <button type="button" class="btn btn-outline-secondary ms-auto" id="alias-suggest-btn">Najít možné duplicity</button>
                </div>
            </form>
            <div id="alias-preview" class="small mt-3"></div>
            <div id="alias-suggest" class="small mt-3"></div>
            <?php if ($alias_rows): ?>
            <div class="table-responsive mt-3">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Alias</th><th>Hlavní jméno</th><th>Od</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($alias_rows as $ar): ?>
                        <tr>
                            <td><?= htmlspecialchars($ar['alias']) ?></td>
                            <td><?= htmlspecialchars($ar['canonical']) ?></td>
                            <td class="small text-nowrap"><?= htmlspecialchars(date('d-m-Y', strtotime($ar['created_at']))) ?></td>
                            <td class="text-end">
                                <form method="POST" class="d-inline" data-confirm="Odebrat pravidlo? Už přepsaná jména v datech zůstanou, pravidlo se jen přestane používat při dalším zadávání.">
                                    <input type="hidden" name="action" value="alias_delete"><input type="hidden" name="id" value="<?= (int)$ar['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Odebrat pravidlo</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- ADMIN PANEL: ZÁZNAM AKCÍ -->
    <div class="card p-4 mb-4 lh-card-admin" id="audit-panel">
        <h3 class="card-title h5 mb-2"><?= lh_icon('eye', 20) ?> Záznam akcí</h3>
        <p class="small text-body-secondary">Co kdo kdy v administraci změnil, včetně přihlášení (i neúspěšných), importů a stažení zálohy. Zapisuje se vždy, i když jsou e-maily o akcích vypnuté. Hesla a klíče se nezapisují.</p>
        <?php if (!$audit_ok): ?>
            <div class="alert alert-warning small mb-0">Chybí tabulka záznamu akcí – spusť SQL skript <code>audit.sql</code>.</div>
        <?php else: ?>
            <div class="row g-2 mb-2">
                <div class="col-md-5"><input type="search" class="form-control form-control-sm" id="audit-q" placeholder="Hledat (akce, uživatel, údaje …)" aria-label="Hledat v záznamu akcí"></div>
                <div class="col-md-3"><select id="audit-group" class="form-select form-select-sm" aria-label="Skupina"><option value="">Všechny skupiny</option></select></div>
                <div class="col-md-3"><select id="audit-user" class="form-select form-select-sm" aria-label="Uživatel"><option value="">Všichni uživatelé</option></select></div>
                <div class="col-md-1 d-grid"><button type="button" id="audit-reload" class="btn btn-sm btn-outline-secondary" title="Obnovit">↻</button></div>
            </div>
            <div id="audit-list" class="small"></div>
            <div class="d-flex align-items-center gap-2 mt-2"><button type="button" id="audit-more" class="btn btn-sm btn-outline-secondary d-none">Načíst starší</button><span class="small text-body-secondary" id="audit-info"></span></div>
        <?php endif; ?>
    </div>

    <!-- ADMIN PANEL: VIDITELNOST ČÁSTÍ V NÁHLEDOVÉ VERZI -->
    <div class="card p-4 mb-4 lh-card-admin" id="ro-panel">
        <h3 class="card-title h5 mb-2"><?= lh_icon('eye', 20) ?> Náhledová verze: co vidí uživatelé</h3>
        <p class="small text-body-secondary">Řídí, které části uvidí uživatelé na náhledové (read only) adrese. Vypnutá část se na serveru vůbec nevykreslí a její adresy přestanou odpovídat. Plná verze a admin vidí vždy vše. Anonymizaci jmen nastavuješ zvlášť níže.</p>
        <form method="POST" class="m-0">
            <input type="hidden" name="action" value="set_ro_sections">
            <div class="table-responsive">
                <table class="table align-middle mb-2">
                    <thead><tr><th>Část</th><th class="d-none d-md-table-cell">Co se týká</th><th class="text-center">Viditelná</th></tr></thead>
                    <tbody>
                    <?php foreach ($ro_sections as $ro_k => $ro_v): ?>
                        <tr>
                            <td class="fw-semibold text-nowrap"><label for="ro-vis-<?= $ro_k ?>"><?= htmlspecialchars($ro_v[0]) ?></label>
                                <div class="small text-body-secondary fw-normal d-md-none"><?= htmlspecialchars($ro_v[1]) ?></div></td>
                            <td class="small text-body-secondary d-none d-md-table-cell"><?= htmlspecialchars($ro_v[1]) ?></td>
                            <td class="text-center">
                                <div class="form-check form-switch d-inline-block m-0">
                                    <input class="form-check-input" type="checkbox" role="switch" name="ro_visible[]" value="<?= $ro_k ?>" id="ro-vis-<?= $ro_k ?>" <?= in_array($ro_k, $ro_hidden, true) ? '' : 'checked' ?>>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" class="btn btn-primary">Uložit</button>
        </form>
    </div>

    <!-- ADMIN PANEL: ANONYMIZACE V NÁHLEDOVÉ VERZI -->
    <div class="card p-4 mb-4 lh-card-admin">
        <h3 class="card-title h5 mb-2"><?= lh_icon('eye', 20) ?> Anonymizace osob v náhledové verzi</h3>
        <p class="small text-body-secondary">V náhledové (jen čtecí) verzi stránky se jména osob a hlásících se nahrazují stálými pseudonymy („Osoba 7K2QF“): stejná osoba má všude stejný kód, ale nejde ji poznat. Plná verze se tím nemění. Dokud nic neuložíš, je anonymizace <strong>zapnuta</strong>.</p>
        <?php if (!$settings_ok): ?>
            <div class="alert alert-warning small mb-0">Chybí tabulka <code>app_settings</code> – spusť SQL skript <code>nastaveni_aplikace.sql</code>.</div>
        <?php else: ?>
            <?php $pseudo_now = lh_setting($pdo, 'pseudonymize_readonly') !== '0'; ?>
            <form method="POST" data-confirm-unless="pseudonymize:1" data-confirm="Vypnout anonymizaci? Náhledová verze pak bude ukazovat skutečná jména osob a hlásících se všem, kdo ji otevřou.">
                <input type="hidden" name="action" value="set_pseudonymize">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="pseudonymize" id="pseudo-on" value="1"<?= $pseudo_now ? ' checked' : '' ?>>
                    <label class="form-check-label" for="pseudo-on">Zapnuto: v náhledu jen pseudonymy</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="pseudonymize" id="pseudo-off" value="0"<?= $pseudo_now ? '' : ' checked' ?>>
                    <label class="form-check-label" for="pseudo-off">Vypnuto: v náhledu skutečná jména</label>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">Uložit</button>
                    <span class="small <?= $pseudo_now ? 'text-body-secondary' : 'text-danger fw-semibold' ?>">Teď: <?= $pseudo_now ? 'anonymizace zapnuta' : 'anonymizace VYPNUTA, náhled ukazuje skutečná jména' ?></span>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <!-- ADMIN PANEL: PŘEKLADAČ PSEUDONYMŮ -->
    <div class="card p-4 mb-4 lh-card-admin" id="pseudo-translator">
        <h3 class="card-title h5 mb-2"><?= lh_icon('eye', 20) ?> Překladač pseudonymů</h3>
        <p class="small text-body-secondary">Vlož kód z náhledové verze (např. „Osoba 0LOA5“), nebo celý text, který kódy obsahuje, a ukáže se, komu patří. Nic se neukládá ani nezapisuje (ani do záznamu akcí e-mailem). Funguje, dokud se nezmění klíč pro pseudonymy.</p>
        <?php if (!isset($config['pseudo_secret'])): ?>
            <div class="alert alert-warning small py-2">Klíč pro pseudonymy se teď odvozuje z <code>device_secret</code> a <code>proxy_secret</code>: změna kterékoli z nich změní <strong>všechny</strong> kódy a staré kódy už nepůjde přeložit. Doporučuji v <code>lh_config.php</code> nastavit napevno <code>pseudo_secret</code> (a už ho neměnit).</div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="action" value="pseudo_translate">
            <textarea class="form-control mb-2" name="pt_text" rows="3" maxlength="5000" placeholder="např. Osoba 0LOA5 byla u incidentu #4"><?= htmlspecialchars($pt_text) ?></textarea>
            <button type="submit" class="btn btn-primary">Přeložit</button>
        </form>
        <?php if ($pt_result !== null): ?>
            <div class="mt-3">
                <?php if ($pt_result['note'] !== ''): ?><div class="alert alert-info small py-2"><?= htmlspecialchars($pt_result['note']) ?></div><?php endif; ?>
                <?php if (!empty($pt_result['codes'])): ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-3">
                            <thead><tr><th>Kód</th><th>Jméno</th><th>Zdroj</th><th>Shoda</th></tr></thead>
                            <tbody>
                            <?php foreach ($pt_result['codes'] as $code => $cr): ?>
                                <?php if (empty($cr['people'])): ?>
                                    <tr><td><code><?= htmlspecialchars($code) ?></code></td><td colspan="3" class="text-body-secondary">nenalezeno (neznámý kód nebo jiný klíč pro pseudonymy)</td></tr>
                                <?php else: foreach ($cr['people'] as $pe): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($code) ?></code></td>
                                        <td><strong><?= htmlspecialchars($pe['best']) ?></strong><?php
                                            $other = array_diff(array_keys($pe['forms']), [$pe['best']]);
                                            if ($other): ?> <span class="small text-body-secondary">(další zápisy: <?= htmlspecialchars(implode(', ', $other)) ?>)</span><?php endif; ?></td>
                                        <td class="small"><?php
                                            $srcs = [];
                                            foreach ($pe['forms'] as $f_srcs) { foreach (array_keys($f_srcs) as $sl) { $srcs[$sl] = true; } }
                                            echo htmlspecialchars(implode(', ', array_keys($srcs))); ?></td>
                                        <td class="small"><?= $cr['type'] === 'exact' ? 'přesná' : ($cr['type'] === 'first5' ? '<span class="text-warning-emphasis">podle prvních 5 znaků (kód je delší než běžný)</span>' : '<span class="text-danger">přibližná (liší se jeden znak, ověř)</span>') ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <label class="form-label small mb-1">Text s doplněnými jmény</label>
                    <textarea class="form-control" rows="3" readonly><?= htmlspecialchars($pt_result['translated']) ?></textarea>
                <?php endif; ?>
            </div>
            
        <?php endif; ?>
    </div>

    <!-- ADMIN PANEL: UPOZORNĚNÍ ADMINŮM -->
    <div class="card p-4 mb-4 lh-card-admin">
        <h3 class="card-title h5 mb-2"><?= lh_icon('eye', 20) ?> Upozornění adminům</h3>
        <p class="small text-body-secondary">Zaškrtni, o čem mají adminům (seznam „Admin e-maily“) chodit e-maily. Zkušební e-mail a testovací souhrn se posílají vždy. Dokud nic neuložíš, je zapnuto vše.</p>
        <?php if (!$settings_ok): ?>
            <div class="alert alert-warning small mb-0">Chybí tabulka <code>app_settings</code> – spusť SQL skript <code>nastaveni_aplikace.sql</code>.</div>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="set_admin_notify">
                <?php foreach (lh_notify_groups() as $grp_title => $grp_items): ?>
                    <div class="fw-semibold small text-uppercase text-body-secondary mt-3 mb-1"><?= htmlspecialchars($grp_title) ?></div>
                    <?php foreach ($grp_items as $nk => $nlabel): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="notify[]" value="<?= htmlspecialchars($nk) ?>" id="notify-<?= htmlspecialchars($nk) ?>"<?= lh_notify_enabled($pdo, $nk) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="notify-<?= htmlspecialchars($nk) ?>"><?= htmlspecialchars($nlabel) ?></label>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">Uložit upozornění</button>
                    <button type="button" class="btn btn-outline-secondary" data-check-all="1">Zapnout vše</button>
                    <button type="button" class="btn btn-outline-secondary" data-check-all="0">Vypnout vše</button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <!-- ADMIN PANEL: ZÁLOHA DATABÁZE -->
    <div class="card p-4 mb-4 lh-card-admin">
        <h3 class="card-title h5 mb-2"><?= lh_icon('download', 20) ?> Záloha databáze</h3>
        <p class="small text-body-secondary">Stáhne celou databázi jako SQL soubor (komprimovaný gzip). Obnova: phpMyAdmin, záložka Import (gzip zvládne rovnou). Soubor obsahuje osobní údaje (jména, zařízení, IP adresy, svědectví), uložte ho zabezpečeně mimo hosting. Poslední řádek zálohy je „KONEC ZÁLOHY“, podle něj poznáte, že je úplná. Automatické denní stahování domácím serverem se zapíná klíčem <code>backup_token</code> v <code>lh_config.php</code>.</p>
        <form method="POST" class="m-0">
            <input type="hidden" name="action" value="backup_download">
            <button type="submit" class="btn btn-primary">Stáhnout zálohu</button>
        </form>
    </div>

        </div>
        <?php endif; ?>
    </div>
</div>

<?php
    // Poslední známý stav každé kabinky (stejná data jako dashboard), slouží jen k rozhodnutí, zda se zeptat
    $last_status_map = [];
    foreach ($cubicles_list as $c_key => $c_name) {
        $last_status_map[$c_key] = $current_status[$c_key]['status'] ?? null;
    }
?>
<!-- MODAL: POTVRZENÍ (NOVÝ INCIDENT / OPRAVA ZE ZESRANÁ NA OK) -->
<div class="modal fade" id="confirmIncidentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirm-title">Opravdu si přejete založit incident?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-footer flex-column align-items-stretch gap-2">
                <button type="button" id="confirm-incident-yes" class="btn btn-danger btn-lg m-0">ANO, kabinka je zesraná</button>
                <button type="button" id="confirm-no" class="btn btn-secondary btn-lg m-0" data-bs-dismiss="modal">NE, nezakládat incident</button>
            </div>
        </div>
    </div>
</div>

<?php if ($is_admin): ?>
<form method="POST" id="device-label-form" class="d-none">
    <input type="hidden" name="action" value="set_device_label">
    <input type="hidden" name="device_id" value="">
    <input type="hidden" name="label" value="">
</form>
<!-- MODAL: ÚPRAVA POZOROVÁNÍ (admin) -->
<div class="modal fade" id="sighting-edit-modal" tabindex="-1" aria-labelledby="sighting-edit-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content" id="sighting-edit-form">
            <input type="hidden" name="action" value="sighting_edit">
            <input type="hidden" name="id" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="sighting-edit-title">Upravit pozorování</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3 position-relative">
                    <label class="form-label small mb-1" for="se-person">Jméno osoby (musí odpovídat docházce) *</label>
                    <input type="text" class="form-control" name="person_name" id="se-person" maxlength="150" required autocomplete="off">
                    <div id="se-suggest" class="list-group lh-suggest d-none"></div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-sm-7">
                        <label class="form-label small mb-1" for="se-location">Kde *</label>
                        <select class="form-select" name="location" id="se-location" required>
                            <?php foreach ($wit_locations as $wl_key => $wl_name): ?><option value="<?= htmlspecialchars($wl_key) ?>"><?= htmlspecialchars($wl_name) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-5">
                        <label class="form-label small mb-1" for="se-direction">Směr *</label>
                        <select class="form-select" name="direction" id="se-direction" required>
                            <?php foreach ($wit_directions as $wd_key => $wd_name): ?><option value="<?= htmlspecialchars($wd_key) ?>"><?= htmlspecialchars($wd_name) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label small mb-1" for="se-seen">Čas pozorování *</label>
                        <input type="datetime-local" class="form-control" name="seen_at" id="se-seen" required>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small mb-1" for="se-reporter">Nahlásil *</label>
                        <input type="text" class="form-control" name="reporter" id="se-reporter" maxlength="100" required>
                    </div>
                </div>
                <?php if ($note_cols['sightings']): ?>
                    <div class="mb-1">
                        <label class="form-label small mb-1" for="se-note">Poznámka (nepovinná)</label>
                        <textarea class="form-control" name="note" id="se-note" rows="2" maxlength="300"></textarea>
                    </div>
                <?php endif; ?>
                <div class="small text-body-secondary mt-2">Stav pozorování (schváleno / čeká / zamítnuto) se úpravou nemění. Změna se hned promítne do žebříčku.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                <button type="submit" class="btn btn-primary">Uložit změny</button>
            </div>
        </form>
    </div>
</div>
<form method="POST" id="sighting-rename-form" class="d-none">
    <input type="hidden" name="action" value="sighting_rename">
    <input type="hidden" name="id" value="">
    <input type="hidden" name="person_name" value="">
</form>


<!-- MODAL: EDITACE HLÁŠENÍ -->
<div class="modal fade" id="editReportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content" id="edit-report-form">
            <input type="hidden" name="action" value="edit_report">
            <input type="hidden" name="id" value="">
            <div class="modal-header">
                <h5 class="modal-title">Upravit hlášení</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Jméno reportujícího *</label>
                    <input type="text" name="reporter" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Kabinka *</label>
                    <select name="cubicle" class="form-select" required>
                        <?php foreach ($cubicles_list as $c_key => $c_name): ?>
                            <option value="<?= $c_key ?>"><?= htmlspecialchars($c_name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Stav *</label>
                    <select name="status" class="form-select" required>
                        <option value="ok">OK (čisto)</option>
                        <option value="zesrana">Zesraná</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Čas kontroly/příchodu *</label>
                    <input type="datetime-local" name="reported_at" class="form-control" required>
                </div>
                <?php if ($note_cols['reports']): ?>
                <div class="mb-3">
                    <label class="form-label" for="edit-note">Poznámka <span class="text-body-secondary fw-normal">(nepovinná)</span></label>
                    <textarea name="note" id="edit-note" class="form-control" rows="2" maxlength="300" data-note-counter="edit-note-count"></textarea>
                    <div class="form-text text-end"><span id="edit-note-count">0/300</span></div>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zrušit</button>
                <button type="submit" class="btn btn-primary">Uložit změny</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script nonce="<?= htmlspecialchars($lh_nonce) ?>">window.LH_LASTSTATUS = <?= json_encode($last_status_map) ?>;</script>
<script src="assets/lh.js?v=<?= htmlspecialchars($app_version) ?>"></script>
<script src="assets/vendor/bootstrap.bundle.min.js?v=<?= htmlspecialchars($app_version) ?>"></script>
</body>
</html>