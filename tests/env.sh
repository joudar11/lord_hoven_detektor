#!/usr/bin/env bash
# Testovací prostředí: jednorázová databáze, aplikace na několika portech a zachytávací SMTP.
#   bash tests/env.sh up [demo|empty]    spustí vše (demo = schema.sql + db/demo_data.sql, empty = jen schema.sql)
#   bash tests/env.sh down                zastaví servery (a databázi, kterou spustil tests/start_mariadb.sh)
# Požadavky: PHP 8.1+ (pdo_mysql, openssl), MariaDB (mariadbd, mariadb-install-db, mariadb), openssl, python3.
# Porty: 8081 plná verze, 8091 admin (identita), 8092 náhled, 8093 běžný přihlášený uživatel,
#        8095 vlastní texty e-mailů, 8096 retence, 8097 přihlášení podle IP, 8098 hashované heslo, 8099 chybná konfigurace.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; ROOT="$(dirname "$HERE")"
RUN="${LH_RUN:-$HERE/.run}"; export LH_RUN="$RUN"; mkdir -p "$RUN"
PORTS="8081 8091 8092 8093 8095 8096 8097 8098 8099"
stop_all() {
  for p in $PORTS; do pkill -f "php -S 127.0.0.1:$p " 2>/dev/null; done
  pkill -f "$HERE/fake_smtp.py" 2>/dev/null
}
if [ "${1:-}" = "down" ]; then
  stop_all
  [ -f "$RUN/mariadb.pid" ] && kill "$(cat "$RUN/mariadb.pid")" 2>/dev/null
  echo "zastaveno"; exit 0
fi
MODE="${2:-demo}"
stop_all
SOCK="${LH_DB_SOCKET:-$(bash "$HERE/start_mariadb.sh")}"
ADMIN="mariadb -S $SOCK"
$ADMIN -e "DROP DATABASE IF EXISTS lhtest; CREATE DATABASE lhtest CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS 'lh'@'127.0.0.1' IDENTIFIED BY 'lhpass'; CREATE USER IF NOT EXISTS 'lh'@'localhost' IDENTIFIED BY 'lhpass'; GRANT ALL ON lhtest.* TO 'lh'@'127.0.0.1'; GRANT ALL ON lhtest.* TO 'lh'@'localhost'; FLUSH PRIVILEGES;" || exit 1
$ADMIN lhtest < "$ROOT/db/schema.sql" || exit 1
[ "$MODE" = "demo" ] && { $ADMIN lhtest < "$ROOT/db/demo_data.sql" || exit 1; }
cat > "$RUN/env.json" <<EOF
{"db_socket": "$SOCK", "db": "lhtest", "mails": "$RUN/mails.jsonl", "run": "$RUN", "root": "$ROOT", "mode": "$MODE"}
EOF
rm -f "$RUN/mails.jsonl"
HASH="$(php -r 'echo password_hash("HashedAdminPw1", PASSWORD_BCRYPT);')"
base_config() {  # $1 = další položky konfigurace
cat <<EOF
<?php
if (!defined('LH_APP')) { http_response_code(403); exit; }
return [
    'allowed_proxy_ip' => '127.0.0.1', 'db_host' => 'localhost', 'db_socket' => '$SOCK', 'db_name' => 'lhtest', 'db_user' => 'lh', 'db_pass' => 'lhpass',
    'admin_password' => 'TestAdminPw1', 'proxy_secret' => 'PROXYSECRET123', 'device_secret' => 'DEVSECRET456',
    'full_hosts' => ['app.example.test'], 'admin_users' => ['adminuser'],
    'smtp_host' => '127.0.0.1', 'smtp_port' => 2587, 'smtp_user' => 'u@example.test', 'smtp_pass' => 'pw', 'smtp_from' => 'u@example.test', 'smtp_verify_tls' => false,
    'backup_token' => str_repeat('b', 40), 'app_url' => 'http://127.0.0.1:8081',
    $1
];
EOF
}
mksite() {  # $1 = název, $2 = obsah konfigurace, $3.. = porty
  local name="$1" cfg="$2"; shift 2
  local dir="$RUN/site_$name"; rm -rf "$dir"; mkdir -p "$dir"
  cp -r "$ROOT/app" "$dir/app"; cp "$HERE/router.php" "$dir/app/router.php"
  printf '%s' "$cfg" > "$dir/lh_config.php"
  for p in "$@"; do (cd "$dir/app" && nohup php -S 127.0.0.1:$p router.php >"$RUN/php_$p.log" 2>&1 </dev/null &); done
}
mksite main "$(base_config "")" 8081 8091 8092 8093
mksite texts "$(base_config "'texts' => ['culprit_mail_intro' => ['Vlastní úvod: pachatel dopaden.', 'Dosral svoje poslední hovno (vlastní text).'], 'culprit_mail_outro' => ['Vlastní závěr.'], 'digest_outro' => ['Vlastní závěr souhrnu.']],")" 8095
mksite retention "$(base_config "'retention_audit_days' => 30, 'retention_device_days' => 30, 'retention_ip_days' => 30,")" 8096
mksite iplogin "$(base_config "'ip_login_ips' => ['203.0.113.7', 'neplatna-ip'],")" 8097
mksite hashed "$(base_config "" | sed "s#'admin_password' => 'TestAdminPw1'#'admin_password' => '$HASH'#")" 8098
mksite badconf "$(base_config "" | sed "s#'full_hosts' => \['app.example.test'\], ##; s#'device_secret' => 'DEVSECRET456',##")" 8099
nohup python3 "$HERE/fake_smtp.py" >"$RUN/smtp.log" 2>&1 </dev/null &
sleep 2
$ADMIN lhtest -e "INSERT INTO app_settings (setting_key,setting_value) VALUES ('mail_admins','admin@example.test') ON DUPLICATE KEY UPDATE setting_value='admin@example.test'; INSERT INTO app_settings (setting_key,setting_value) VALUES ('mail_digest','digest@example.test') ON DUPLICATE KEY UPDATE setting_value='digest@example.test';"
for p in 8081 8091 8092 8093 8095 8096 8097 8098 8099; do printf 'port %s: HTTP %s\n' "$p" "$(curl -s -m 30 -o /dev/null -w '%{http_code}' http://127.0.0.1:$p/)"; done
echo "prostředí připraveno ($MODE)"
