<?php
/*
 * tools/zoraxy.php: diagnostika hlaviček, které posílá reverzní proxy (Zoraxy).
 *
 * Použití:
 *   1. Nahrajte soubor vedle index.php (stejná složka).
 *   2. Otevřete ho přes Zoraxy se zapnutým přihlášením (stejný vhost jako aplikace), např. https://vase-domena/zoraxy.php
 *   3. Klepněte na „Zkopírovat výpis“ a text pošlete.
 *   4. Po použití soubor SMAŽTE.
 *
 * Bezpečnost: přístup má jen povolená adresa z lh_config.php (allowed_proxy_ip), stejně jako aplikace.
 * Hodnoty cookies, hlavičky Authorization a tajné hlavičky X-Lh-Proxy se nikdy nevypisují
 * (zobrazí se jen jejich názvy a délky). Výpis tedy jde bez obav poslat dál.
 */
define('LH_APP', true);

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

// Skript funguje i bez rozšíření mbstring (některé hostingy ho nemají)
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) { return (int)preg_match_all('/./us', (string)$s); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len = null, $enc = null) {
        preg_match_all('/./us', (string)$s, $m);
        return implode('', array_slice($m[0], $start, $len));
    }
}

// Konfigurace stejným způsobem jako index.php; bez ní nebo z cizí adresy se nic neukáže
$cfg_file = __DIR__ . '/../lh_config.php';
if (!is_file($cfg_file)) { $cfg_file = __DIR__ . '/lh_config.php'; }
$config = is_file($cfg_file) ? (require $cfg_file) : null;
if (!is_array($config) || empty($config['allowed_proxy_ip']) || ($_SERVER['REMOTE_ADDR'] ?? '') !== $config['allowed_proxy_ip']) {
    http_response_code(403);
    exit('Přístup odepřen.');
}

// ---------- sběr hlaviček ----------
$headers = [];
if (function_exists('getallheaders')) {
    $headers = getallheaders();
}
if (!$headers) {
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $headers[ucwords(strtolower(str_replace('_', '-', substr($k, 5))), '-')] = $v;
        }
    }
    foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $k => $n) {
        if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') { $headers[$n] = $_SERVER[$k]; }
    }
}

// Hodnotu převede na čitelný UTF-8 text bez řídicích znaků
$clean = function ($v) {
    $v = (string)$v;
    if (!preg_match('//u', $v)) {   // není UTF-8: znaky nad 0x7F se berou jako ISO-8859-1
        $v = preg_replace_callback('/[\x80-\xFF]/', function ($m) { $c = ord($m[0]); return chr(0xC0 | ($c >> 6)) . chr(0x80 | ($c & 0x3F)); }, $v);
    }
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '?', $v);
    return mb_strlen($v) > 400 ? mb_substr($v, 0, 400) . ' …(zkráceno)' : $v;
};
$server_key = function ($name) { return 'HTTP_' . strtoupper(str_replace('-', '_', $name)); };

$proxy_secret = (string)($config['proxy_secret'] ?? '');
$user_header = (string)($config['auth_user_header'] ?? 'Remote-User');
$name_header = (string)($config['auth_name_header'] ?? 'Remote-Name');

$re_secret = '/^(authorization|proxy-authorization|cookie|set-cookie|x-lh-proxy|x-backup-token|x-api-key)$|token|secret|password/i';
$re_identity = '/^(remote-|x-remote-|x-auth|x-forwarded-(user|email|groups|preferred|name)|x-authentik|x-zoraxy|zoraxy)/i';
$re_network = '/^(x-forwarded-|x-real-ip|forwarded$|via$|cf-|true-client-ip|x-client-ip)/i';
$re_lh = '/^x-lh-/i';

$rows = [];
$secret_header_ok = false;
foreach ($headers as $name => $value) {
    $cat = preg_match($re_lh, $name) ? 'naše' : (preg_match($re_identity, $name) ? 'IDENTITA' : (preg_match($re_network, $name) ? 'síť / klient' : ''));
    $note = '';
    if (strcasecmp($name, 'Cookie') === 0) {
        // jen názvy cookies a délky hodnot
        $parts = [];
        foreach (explode(';', (string)$value) as $c) {
            $c = trim($c);
            if ($c === '') { continue; }
            $pair = explode('=', $c, 2);
            $parts[] = $clean($pair[0]) . ' (hodnota ' . strlen($pair[1] ?? '') . ' znaků)';
        }
        $shown = '(skryto) cookies: ' . implode(', ', $parts);
    } elseif (preg_match($re_secret, $name)) {
        $shown = '(skryto, ' . strlen((string)$value) . ' znaků)';
        if (strcasecmp($name, 'X-Lh-Proxy') === 0) {
            $secret_header_ok = $proxy_secret !== '' && hash_equals($proxy_secret, (string)$value);
            $note = $secret_header_ok ? 'odpovídá proxy_secret v konfiguraci: ANO' : 'odpovídá proxy_secret v konfiguraci: NE';
        }
    } else {
        $shown = $clean($value);
        if (preg_match($re_identity, $name) && !preg_match('/groups/i', $name) && strpos((string)$value, ',') !== false) {
            $note = 'POZOR: hodnota obsahuje čárku, hlavička mohla dorazit víckrát a PHP ji spojilo';
        }
    }
    $rows[] = ['name' => $clean($name), 'value' => $shown, 'cat' => $cat, 'key' => $server_key($name), 'note' => $note];
}

// ---------- co by z toho udělala aplikace ----------
$uv = trim((string)($_SERVER[$server_key($user_header)] ?? ''));
$nv = trim((string)($_SERVER[$server_key($name_header)] ?? ''));
$valid = function ($v) { return $v !== '' && mb_strlen($v) <= 100 && preg_match('/^[\p{L}\p{N} ._@+\-\']+$/u', $v); };
$reasons = [];
if (!$secret_header_ok) { $reasons[] = 'chybí platná hlavička X-Lh-Proxy (identita se ignoruje)'; }
if ($uv === '') { $reasons[] = 'hlavička ' . $user_header . ' nedorazila nebo je prázdná'; }
elseif (!$valid($uv)) { $reasons[] = 'hodnota hlavičky ' . $user_header . ' obsahuje nepovolené znaky nebo je moc dlouhá'; }
$app_ok = !$reasons;
$admin_users = array_map('strtolower', (array)($config['admin_users'] ?? []));

// ---------- v jakém režimu by aplikace běžela? (zápis jen s platnou hlavičkou X-Lh-Proxy a adresou z full_hosts) ----------
$full_hosts = array_map('strtolower', (array)($config['full_hosts'] ?? []));
$orig_host = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))[0]);
if ($orig_host === '') { $orig_host = (string)($_SERVER['HTTP_HOST'] ?? ''); }
$orig_host = strtolower(preg_replace('/:\\d+$/', '', trim($orig_host)));
$ro_reasons = [];
if (!$secret_header_ok) { $ro_reasons[] = 'chybí platná hlavička X-Lh-Proxy'; }
if (!in_array($orig_host, $full_hosts, true)) { $ro_reasons[] = 'adresa „' . $orig_host . '“ není v full_hosts (' . implode(', ', $full_hosts) . ')'; }
if (($_SERVER['HTTP_X_LH_READONLY'] ?? '') === '1') { $ro_reasons[] = 'hlavička X-Lh-Readonly: 1'; }
$ro_reason = implode('; ', $ro_reasons);   // prázdné = plná verze (zápis povolen)

// ---------- text pro odeslání ----------
$t = [];
$t[] = 'Diagnostika hlaviček Zoraxy (zoraxy.php)';
$t[] = 'Čas: ' . date('Y-m-d H:i:s') . ' | PHP ' . PHP_VERSION . ' | ' . PHP_SAPI . ' | ' . $clean($_SERVER['SERVER_SOFTWARE'] ?? '');
$t[] = 'Požadavek: ' . $clean($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . $clean($_SERVER['REQUEST_URI'] ?? '') . ' ' . $clean($_SERVER['SERVER_PROTOCOL'] ?? '');
$t[] = 'REMOTE_ADDR: ' . $clean($_SERVER['REMOTE_ADDR'] ?? '') . ' | HTTPS: ' . (!empty($_SERVER['HTTPS']) ? 'ano' : 'ne') . ' | HTTP_HOST: ' . $clean($_SERVER['HTTP_HOST'] ?? '');
foreach (['REMOTE_USER', 'REDIRECT_REMOTE_USER', 'PHP_AUTH_USER'] as $sk) {
    if (isset($_SERVER[$sk])) { $t[] = '$_SERVER[' . $sk . ']: ' . $clean($_SERVER[$sk]); }
}
$t[] = '';
$t[] = 'PŘIJATÉ HLAVIČKY (' . count($rows) . '):';
foreach ($rows as $r) {
    $t[] = '  ' . $r['name'] . ': ' . $r['value'] . '   [' . ($r['cat'] !== '' ? $r['cat'] . ' | ' : '') . $r['key'] . ']' . ($r['note'] !== '' ? '   <-- ' . $r['note'] : '');
}
$t[] = '';
$t[] = 'REŽIM APLIKACE: ' . ($ro_reason === '' ? 'PLNÁ VERZE (zápis povolen) | adresa: ' . $orig_host : 'POUZE NÁHLED (jen čtení): ' . $ro_reason);
$t[] = '';
$t[] = 'Poznámka: duplicitní hlavičku (Zoraxy do verze před 3.3.5-rc3 posílal identitu dvakrát) tento výpis nemusí ukázat. Záleží na webovém serveru, některé ponechají jen první nebo jen poslední hodnotu.';
$t[] = '';
$t[] = 'IDENTITA VE VÝPISU:';
$ident = array_filter($rows, function ($r) { return $r['cat'] === 'IDENTITA'; });
if ($ident) { foreach ($ident as $r) { $t[] = '  ' . $r['name'] . ' = ' . $r['value']; } } else { $t[] = '  žádná identitní hlavička (Remote-*, X-Auth-*, X-Forwarded-User/Email/Groups) nedorazila'; }
$t[] = '';
$t[] = 'CO BY Z TOHO UDĚLALA APLIKACE (konfigurace: ' . $user_header . ' / ' . $name_header . '):';
$t[] = '  uživatel: ' . ($uv !== '' ? $clean($uv) : '(žádný)') . ' | zobrazované jméno: ' . ($nv !== '' ? $clean($nv) : '(žádné)');
$t[] = '  identita by byla přijata: ' . ($app_ok ? 'ANO' : 'NE (' . implode('; ', $reasons) . ')');
if ($app_ok) { $t[] = '  admin podle seznamu admin_users: ' . (in_array(strtolower($uv), $admin_users, true) ? 'ANO' : 'ne'); }
$text = implode("\n", $t) . "\n";

if (isset($_GET['raw'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo $text;
    exit;
}
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Diagnostika hlaviček Zoraxy</title>
    <style>
        :root { color-scheme: light dark; }
        body { font: 15px/1.5 system-ui, sans-serif; margin: 0; padding: 24px; max-width: 1100px; margin-inline: auto; }
        h1 { font-size: 1.3rem; margin: 0 0 4px; }
        .muted { color: #6b7280; font-size: .85rem; }
        .box { border: 1px solid #9ca3af66; border-radius: 10px; padding: 12px 16px; margin: 16px 0; }
        .ok { border-color: #16a34a; } .bad { border-color: #dc2626; }
        table { border-collapse: collapse; width: 100%; font-size: .85rem; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #9ca3af44; vertical-align: top; word-break: break-word; }
        th { color: #6b7280; font-weight: 600; }
        .tag { display: inline-block; font-size: .7rem; padding: 1px 7px; border-radius: 99px; background: #9ca3af33; }
        .tag.id { background: #dc262633; color: #dc2626; font-weight: 700; }
        .mono { font-family: ui-monospace, monospace; }
        button { font: inherit; padding: 8px 14px; border-radius: 8px; border: 1px solid #2563eb; background: #2563eb; color: #fff; cursor: pointer; }
        textarea { width: 100%; min-height: 220px; font: 12px/1.4 ui-monospace, monospace; box-sizing: border-box; }
        .warn { color: #b45309; font-weight: 600; }
    </style>
</head>
<body>
    <h1>Diagnostika hlaviček Zoraxy</h1>
    <div class="muted">Po použití tento soubor smažte. Hodnoty cookies, Authorization a X-Lh-Proxy se nezobrazují.</div>

    <div class="box <?= $app_ok ? 'ok' : 'bad' ?>">
        <strong>Co by z toho udělala aplikace:</strong>
        identita <?= $app_ok ? 'by byla <strong>přijata</strong>' : 'by <strong>nebyla přijata</strong>' ?>.
        <div>Uživatel: <span class="mono"><?= $h($uv !== '' ? $uv : '(žádný)') ?></span>
            | zobrazované jméno: <span class="mono"><?= $h($nv !== '' ? $nv : '(žádné)') ?></span></div>
        <?php if ($reasons): ?><div class="warn"><?= $h(implode('; ', $reasons)) ?></div><?php endif; ?>
    </div>

    <div class="box <?= $ro_reason === '' ? 'ok' : 'bad' ?>">
        <strong>Režim aplikace:</strong> <?= $ro_reason === '' ? 'plná verze (zápis povolen)' : 'pouze náhled (jen čtení)' ?>
        <div class="muted"><?= $ro_reason === '' ? 'Platná hlavička X-Lh-Proxy a adresa ' . $h($orig_host) . ' (full_hosts).' : $h($ro_reason) ?></div>
    </div>

    <div class="box">
        <div><strong>Požadavek:</strong> <span class="mono"><?= $h($_SERVER['REQUEST_METHOD'] ?? '') ?> <?= $h($_SERVER['REQUEST_URI'] ?? '') ?></span>
            | REMOTE_ADDR <span class="mono"><?= $h($_SERVER['REMOTE_ADDR'] ?? '') ?></span>
            | HTTPS <?= !empty($_SERVER['HTTPS']) ? 'ano' : 'ne' ?> | Host <span class="mono"><?= $h($_SERVER['HTTP_HOST'] ?? '') ?></span></div>
    </div>

    <div class="muted">Duplicitní hlavičku (starší Zoraxy posílal identitu dvakrát) tento výpis nemusí ukázat, záleží na webovém serveru.</div>
    <h2 style="font-size:1.05rem">Přijaté hlavičky (<?= count($rows) ?>)</h2>
    <table>
        <thead><tr><th>Hlavička</th><th>Hodnota</th><th>Druh</th><th>Klíč v $_SERVER</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="mono"><?= $h($r['name']) ?></td>
                <td class="mono"><?= $h($r['value']) ?><?php if ($r['note'] !== ''): ?><div class="warn"><?= $h($r['note']) ?></div><?php endif; ?></td>
                <td><?php if ($r['cat'] !== ''): ?><span class="tag<?= $r['cat'] === 'IDENTITA' ? ' id' : '' ?>"><?= $h($r['cat']) ?></span><?php endif; ?></td>
                <td class="mono muted"><?= $h($r['key']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="box">
        <p><button type="button" id="copy">Zkopírovat výpis</button> <span id="copied" class="muted"></span></p>
        <textarea id="txt" readonly><?= $h($text) ?></textarea>
        <div class="muted">Stejný text vrací adresa se znakem <span class="mono">?raw=1</span> (čistý text).</div>
    </div>
    <script>
        document.getElementById('copy').addEventListener('click', function () {
            var ta = document.getElementById('txt'); ta.select();
            var done = function () { document.getElementById('copied').textContent = 'Zkopírováno.'; };
            if (navigator.clipboard) { navigator.clipboard.writeText(ta.value).then(done, function () { document.execCommand('copy'); done(); }); }
            else { document.execCommand('copy'); done(); }
        });
    </script>
</body>
</html>
