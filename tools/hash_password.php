<?php
/*
 * tools/hash_password.php: vytvoří hash hesla admina pro konfiguraci (lh_config.php, klíč 'admin_password').
 *
 * Použití v příkazové řádce (heslo se nezobrazuje na obrazovce a nezůstane v historii příkazů):
 *     php tools/hash_password.php
 * Výsledný řetězec (začíná $2y$ nebo $argon2) vlož do konfigurace místo čistého hesla. Aplikace rozpozná hash sama.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
fwrite(STDERR, "Zadej heslo admina (min. 12 znaků): ");
if (stripos(PHP_OS, 'WIN') !== 0) { system('stty -echo'); }
$pw = trim((string)fgets(STDIN));
if (stripos(PHP_OS, 'WIN') !== 0) { system('stty echo'); }
fwrite(STDERR, "\n");
if (strlen($pw) < 12) { fwrite(STDERR, "Heslo je příliš krátké (aspoň 12 znaků).\n"); exit(1); }
$algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
echo password_hash($pw, $algo), "\n";
