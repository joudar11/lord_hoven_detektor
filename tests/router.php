<?php
// Testovací směrovač pro vestavěný server PHP (php -S): nahrazuje reverzní proxy. Doplní tajnou hlavičku X-Lh-Proxy a hostitele podle portu.
//   8092 = náhledová adresa, 8091 = přihlášený admin (identita z proxy), 8093 = přihlášený běžný uživatel, ostatní = plná verze bez identity.
$port = (int)($_SERVER['SERVER_PORT'] ?? 0);
if (empty($_SERVER['HTTP_X_LH_PROXY'])) { $_SERVER['HTTP_X_LH_PROXY'] = 'PROXYSECRET123'; }
if ($port === 8091) { $_SERVER['HTTP_REMOTE_USER'] = 'AdminUser'; $_SERVER['HTTP_REMOTE_NAME'] = 'Admin Uzivatel'; }
if ($port === 8093) { $_SERVER['HTTP_REMOTE_USER'] = 'regular'; $_SERVER['HTTP_REMOTE_NAME'] = 'Regular User'; }
if ($port === 8092) { $_SERVER['HTTP_X_FORWARDED_HOST'] = 'preview.example.test'; }
elseif (empty($_SERVER['HTTP_X_FORWARDED_HOST'])) { $_SERVER['HTTP_HOST'] = 'app.example.test'; }
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p === '/' || $p === '/index.php') { require __DIR__ . '/index.php'; return true; }
if ($p === '/vizualizace.php') { require __DIR__ . '/vizualizace.php'; return true; }
return false;   // ostatní (assets/...) obslouží vestavěný server jako statické soubory
