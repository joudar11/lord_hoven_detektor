#!/usr/bin/env bash
# Týdenní souhrn Detektoru Lorda Hoven: zavolá aplikaci, ta rozešle e-mail příjemcům ze seznamu v administraci.
# Používá stejný klíč jako záloha (soubor .lh-backup-token). Aplikace si hlídá, že souhrn nepošle dvakrát během 24 hodin.
#
# Použití (cron, každé pondělí v 7:00; adresu předej proměnnou LH_URL):
#   0 7 * * 1 LH_URL=https://example.org/lh/index.php /home/UZIVATEL/lh/lh-digest.sh >> /home/UZIVATEL/lh/backups/digest.log 2>&1
set -euo pipefail

DIR="${LH_DIR:-$HOME/lh}"
URL="${LH_URL:-}"   # povinné: adresa index.php na hostingu, např. https://example.org/lh/index.php
TOKEN_FILE="$DIR/.lh-backup-token"

log() { echo "$(date '+%F %T') $*"; }
fail() { log "CHYBA: $*" >&2; exit 1; }
[ -n "$URL" ] || fail "nastav LH_URL (adresa index.php aplikace, např. https://example.org/lh/index.php)"

# Klíč je tajný, proto jen přes HTTPS (LH_ALLOW_HTTP=1 jen pro zkoušení)
[[ "$URL" == https://* || "${LH_ALLOW_HTTP:-}" == 1 ]] || fail "adresa musí začínat https:// ($URL)"
[ -r "$TOKEN_FILE" ] || fail "chybí soubor s klíčem $TOKEN_FILE"
TOKEN=$(tr -d '[:space:]' < "$TOKEN_FILE")
[ "${#TOKEN}" -ge 32 ] || fail "klíč v $TOKEN_FILE je příliš krátký (aspoň 32 znaků)"

RESP=$(curl --fail --silent --show-error --max-time 120 --retry 2 --retry-delay 10 \
            -X POST -d "action=digest_send" -H "X-Backup-Token: $TOKEN" "$URL") \
    || fail "volání aplikace se nepovedlo (server nedostupný nebo odmítl požadavek)"

case "$RESP" in
    OK:*)   log "$RESP" ;;
    SKIP:*) log "$RESP" ;;
    *)      fail "neočekávaná odpověď aplikace: ${RESP:0:200}" ;;
esac
