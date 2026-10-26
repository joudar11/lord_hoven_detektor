#!/usr/bin/env bash
# Denní záloha databáze Detektoru Lorda Hoven.
# Stáhne zálohu z aplikace (POST s klíčem), zkontroluje ji, uloží do backups/
# a smaže zálohy starší než 14 dní (až po úspěšné nové záloze).
#
# Stahuje se přímo z hostingu (ne přes reverzní proxy). Aplikace pustí jen požadavky z povolené IP adresy
# (veřejná adresa vaší domácí sítě) a navíc je potřeba klíč v souboru .lh-backup-token.
#
# Použití (cron, denně ve 3:30; adresu předej proměnnou LH_URL):
#   30 3 * * * LH_URL=https://example.org/lh/index.php /home/UZIVATEL/lh/lh-backup.sh >> /home/UZIVATEL/lh/backups/backup.log 2>&1
set -euo pipefail

DIR="${LH_DIR:-$HOME/lh}"
BACKUP_DIR="$DIR/backups"
URL="${LH_URL:-}"   # povinné: adresa index.php na hostingu, např. https://example.org/lh/index.php
TOKEN_FILE="$DIR/.lh-backup-token"
LOCK_FILE="$DIR/.lh-backup.lock"
KEEP_DAYS=14

log() { echo "$(date '+%F %T') $*"; }
fail() { log "CHYBA: $*" >&2; exit 1; }
[ -n "$URL" ] || fail "nastav LH_URL (adresa index.php aplikace, např. https://example.org/lh/index.php)"

umask 077
mkdir -p "$BACKUP_DIR"

# Nespouštět dvakrát současně
exec 9>"$LOCK_FILE"
flock -n 9 || fail "záloha už běží"

# Klíč je tajný, proto jen přes HTTPS (LH_ALLOW_HTTP=1 jen pro zkoušení)
[[ "$URL" == https://* || "${LH_ALLOW_HTTP:-}" == 1 ]] || fail "adresa musí začínat https:// ($URL)"

[ -r "$TOKEN_FILE" ] || fail "chybí soubor s klíčem $TOKEN_FILE"
perm=$(stat -c %a "$TOKEN_FILE")
[[ "$perm" =~ ^[0-7]00$ ]] || log "UPOZORNĚNÍ: soubor s klíčem má práva $perm, doporučeno chmod 600 $TOKEN_FILE" >&2
TOKEN=$(tr -d '[:space:]' < "$TOKEN_FILE")
[ "${#TOKEN}" -ge 32 ] || fail "klíč v $TOKEN_FILE je příliš krátký (aspoň 32 znaků)"

STAMP=$(date +%F_%H%M)
OUT="$BACKUP_DIR/lh-zaloha-$STAMP.sql.gz"
TMP="$BACKUP_DIR/.lh-zaloha-$STAMP.part"
trap 'rm -f "$TMP" "$TMP.gz"' EXIT

curl_args=(--fail --silent --show-error --max-time 300 --retry 2 --retry-delay 10
           -X POST -d "action=backup_token" -H "X-Backup-Token: $TOKEN" -o "$TMP")

curl "${curl_args[@]}" "$URL" || fail "stažení se nepovedlo (server nedostupný nebo odmítl požadavek)"

# Odpověď musí být platný gzip. Pokud server nemá kompresi a vrátí čistý SQL, zabalíme ho sami.
if ! gzip -t "$TMP" 2>/dev/null; then
    if head -c 40 "$TMP" | grep -q '^-- Detektor'; then
        gzip -c "$TMP" > "$TMP.gz" && mv "$TMP.gz" "$TMP"
    else
        fail "odpověď není záloha (špatný klíč, klíč není v konfiguraci aplikace nebo chybná adresa)"
    fi
fi

# Úplnost: poslední řádek zálohy musí být značka konce
LAST=$(gzip -dc "$TMP" | tail -n 1)
case "$LAST" in
    "-- KONEC ZÁLOHY"*) ;;
    *) fail "záloha je neúplná (chybí značka konce): $LAST" ;;
esac

mv "$TMP" "$OUT"
log "OK: uloženo $OUT ($(du -h "$OUT" | cut -f1)), $LAST"

# Mazání záloh starších než $KEEP_DAYS dní, jen po úspěšné nové záloze (jiné soubory se nemažou)
find "$BACKUP_DIR" -maxdepth 1 -type f -name 'lh-zaloha-*.sql.gz' -mtime +"$KEEP_DAYS" -print -delete \
    | while read -r f; do log "smazáno (starší než $KEEP_DAYS dní): $f"; done
