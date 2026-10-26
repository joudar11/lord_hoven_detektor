<?php
// Vzor konfigurace. Zkopíruj jako lh_config.php, vyplň hodnoty a nahraj MIMO veřejný adresář
// (o úroveň výš než index.php, nebo vedle něj). Skutečný soubor nikam nesdílej (je v .gitignore).
// Úplný popis všech klíčů: docs/CONFIGURATION.md
if (!defined('LH_APP')) { http_response_code(403); exit; }

return [
    // ===== POVINNÉ (bez nich aplikace skončí chybou s výpisem chybějících klíčů) =====
    'allowed_proxy_ip' => '203.0.113.10',          // IP adresa reverzní proxy, jak ji vidí aplikace (REMOTE_ADDR); odsud se přijímají požadavky
    'db_host'          => 'localhost',
    'db_name'          => 'nazev_databaze',
    'db_user'          => 'uzivatel_db',
    'db_pass'          => 'HESLO_K_DATABAZI',
    // 'db_port'       => 3306,                     // volitelné
    // 'db_socket'     => '/var/run/mysqld/mysqld.sock',   // volitelné: unixový soket místo db_host
    'admin_password'   => 'HESLO_ADMINA',          // doporučeno hash: php tools/hash_password.php (hodnota začíná $2y$ nebo $argon2)
    'proxy_secret'     => 'NAHODNY_RETEZEC_32_ZNAKU_A_VIC',   // stejná hodnota v hlavičce X-Lh-Proxy na proxy
    'device_secret'    => 'JINY_NAHODNY_RETEZEC_32_ZNAKU',    // klíč pro podpis ID zařízení, po nasazení neměnit
    'full_hosts'       => ['app.example.org'],     // adresy, na kterých je plná verze (zápis); vše ostatní je jen náhled

    // ===== VOLITELNÉ =====
    // 'app_url'       => 'https://app.example.org',   // odkaz v e-mailech (bez něj se odvodí z původní adresy požadavku)
    // Klíč pro automatické stažení zálohy a týdenní souhrn z domácího serveru (aspoň 32 znaků, např. openssl rand -hex 32).
    // Bez něj jsou tyto funkce vypnuté. Stejnou hodnotu ulož do souboru .lh-backup-token (viz scripts/).
    // 'backup_token'  => 'DLOUHY_NAHODNY_KLIC',
    // Odesílání e-mailů (upozornění adminům, záznam akcí, týdenní souhrn, oznámení o incidentu). Port 587 se STARTTLS.
    // 'smtp_host' => 'smtp.example.org', 'smtp_port' => 587, 'smtp_user' => 'adresa@example.org', 'smtp_pass' => 'HESLO_NEBO_TOKEN', 'smtp_from' => 'adresa@example.org',
    // 'smtp_verify_tls' => true,
    // Přihlášení uživatelů přes reverzní proxy (SSO / forward auth). Identita se přijme jen s platnou hlavičkou X-Lh-Proxy.
    // 'auth_user_header' => 'Remote-User', 'auth_name_header' => 'Remote-Name',
    // 'admin_users'      => ['jan.novak'],        // tito uživatelé jsou admin bez hesla (na náhledové adrese nikdy)
    // 'auth_logout_url'  => 'https://auth.example.org/logout',   // tlačítko Odhlásit (jen https; bez nastavení se nezobrazí)
    // 'auth_account_url' => 'https://auth.example.org/user',     // tlačítko Můj účet (jen https; bez nastavení se nezobrazí)
    // 'pseudo_secret' => 'dlouhý-náhodný-řetězec',   // klíč pro pseudonymy osob v náhledu; změna přepíše všechny pseudonymy
    // 'photo_dir'     => '/cesta/k/fotodokumentace', // podsložky podle čísla incidentu; výchozí je složka fotodokumentace vedle index.php
    // NEBEZPEČNÉ: přihlášení admina bez hesla pro vybrané IP klienta (viz docs/CONFIGURATION.md). Výchozí je vypnuto.
    // 'ip_login_ips' => ['192.0.2.10'],
    // Retence osobních údajů ve dnech (0 nebo bez klíče = nemazat). Čištění běží nejvýš jednou denně.
    // 'retention_audit_days' => 365,    // záznam akcí admina
    // 'retention_device_days' => 365,   // zařízení admina
    // 'retention_ip_days' => 90,        // IP adresy u hlášení a pozorování se anonymizují (záznam zůstane)
    // Vlastní texty e-mailů (pole řádků); bez nich platí výchozí české texty:
    // 'texts' => ['culprit_mail_intro' => ['Pachatel byl dopaden.'], 'culprit_mail_outro' => ['Děkujeme.'], 'digest_outro' => ['Konec souhrnu.']],
];
