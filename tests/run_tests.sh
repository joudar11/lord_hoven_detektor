#!/usr/bin/env bash
# Spustí všechny testy: prázdná databáze, ukázková data (instalace, bezpečnost, funkce) a prohlížečový test (volitelný).
# Před každým souborem se prostředí vytvoří znovu (čistá databáze), takže testy na sobě nezávisí. Výsledek: kód 0 jen když vše prošlo.
# Požadavky viz tests/README.md. Prohlížečový test na serveru bez displeje: xvfb-run -a tests/run_tests.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"; cd "$HERE/.."
fail=0; summary=()
run() {  # $1 = režim prostředí, $2 = soubor testu
  bash "$HERE/env.sh" up "$1" >/dev/null 2>&1 || { echo "Prostředí ($1) se nepodařilo spustit"; fail=1; return; }
  if python3 "$HERE/$2" > "$HERE/.run/out_$2.txt" 2>&1; then r=OK; else r=SELHALO; fail=1; fi
  summary+=("$r  $2: $(grep -E 'kontrol OK|přeskočen' "$HERE/.run/out_$2.txt" | tail -1)")
  [ "$r" = SELHALO ] && grep -E 'CHYBA|Traceback|Error' "$HERE/.run/out_$2.txt" | head -15
}
run empty test_empty.py
run demo  test_install.py
run demo  test_security.py
run demo  test_features.py
run demo  ui_smoke.py
bash "$HERE/env.sh" down >/dev/null 2>&1
echo; echo "=== Souhrn ==="; printf '%s\n' "${summary[@]}"
[ "$fail" = 0 ] && echo "VŠE PROŠLO" || echo "NĚKTERÝ TEST SELHAL"
exit "$fail"
