#!/usr/bin/env bash
# Spustí jednorázový (zahazovaný) server MariaDB pro testy: soket .run/mariadb.sock, port 3307 jen na 127.0.0.1.
# Nepoužívej proti ostré databázi. Vypíše cestu k soketu. Zastavení: tests/env.sh down
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
RUN="${LH_RUN:-$HERE/.run}"; DATA="$RUN/mariadb-data"; SOCK="$RUN/mariadb.sock"
mkdir -p "$RUN"
if [ ! -d "$DATA/mysql" ]; then mariadb-install-db --datadir="$DATA" --auth-root-authentication-method=normal --skip-test-db >/dev/null 2>&1; fi
if ! mariadb -S "$SOCK" -e 'select 1' >/dev/null 2>&1; then
  USERARG=(); [ "$(id -u)" = 0 ] && USERARG=(--user=root)
  nohup mariadbd --datadir="$DATA" --socket="$SOCK" --port=3307 --bind-address=127.0.0.1 --pid-file="$RUN/mariadb.pid" "${USERARG[@]}" >"$RUN/mariadb.log" 2>&1 </dev/null &
  for i in $(seq 1 60); do mariadb -S "$SOCK" -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
fi
echo "$SOCK"
