# Testy

Všechny testy běží nad **vymyšlenými daty** (`db/demo_data.sql`, generuje je `tests/make_demo_data.py`) a nad jednorázovou databází. Nikdy se nedotýkají ostré instalace.

## Požadavky
- PHP 8.1+ s `pdo_mysql` a `openssl` (vestavěný server `php -S`)
- MariaDB: programy `mariadbd`, `mariadb-install-db`, `mariadb` (jednorázový server si spustí `tests/start_mariadb.sh` na soketu v `tests/.run/`, port 3307)
- `openssl` (certifikát zachytávacího SMTP serveru), `curl`, Python 3 (jen standardní knihovna)
- volitelně pro prohlížečový test: `pip install selenium` a ovladač prohlížeče (výchozí WebKitGTK; `LH_BROWSER=chrome` pro Chrome); na serveru bez displeje spusť přes `xvfb-run -a`

## Spuštění
```bash
bash tests/run_tests.sh            # všechno (prostředí se pro každý soubor vytvoří znovu)
bash tests/env.sh up demo          # jen prostředí s ukázkovými daty; pak třeba:
python3 tests/test_security.py
bash tests/env.sh down
```
Prostředí běží na portech 8081 (plná verze), 8091 (admin z proxy), 8092 (náhled), 8093 (běžný přihlášený uživatel), 8095 až 8099 (varianty konfigurace).
Zachycené e-maily jsou v `tests/.run/mails.jsonl`.

## Co se testuje
| Soubor | Obsah |
|---|---|
| `test_empty.py` | instalace jen ze `schema.sql`: všechny režimy bez chyb, první hlášení, import a seznam zaměstnanců |
| `test_install.py` | ukázková data: přesné očekávané body, IT virtuální body, vyřazená osoba, režimy, soubory, vizualizace, záloha |
| `test_security.py` | CSP a hlavičky, cookie, CSRF, hesla (čistá a hash), přihlášení podle IP, chybná konfigurace, XSS, formulář přihlášení |
| `test_features.py` | sběr dat, označení pachatele a e-maily, souhrn (nevinní, důvěryhodní), import s kontrolou, aliasy, záznam akcí, retence, vlastní texty, viditelnost náhledu, úprava pozorování |
| `ui_smoke.py` | prohlížeč s CSP: záložky, filtry, sbalené detaily, potvrzovací dialogy, označený pachatel, časová osa, mobil |

Poznámka: kontrola „záznam z budoucnosti“ v `test_features.py` se spustí jen mimo posledních minut dne (od 23:25 se přeskočí, protože by čas z budoucnosti přeskočil půlnoc), takže počet kontrol může být o jednu nižší.

## Co testy nepokrývají
Texty a vzhled stránek, většinu okrajových případů importu, odesílání e-mailů proti skutečnému SMTP serveru (jen proti zachytávacímu), reverzní proxy
(`router.php` ji jen nahrazuje) a různé prohlížeče. Doplňuj testy ke každé nové funkci.
