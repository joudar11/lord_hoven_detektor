# Architektura

## Soubory
| Soubor | Role |
|---|---|
| `app/index.php` | celá aplikace: zpracování požadavků (POST akce, AJAX adresy), výpočet analýzy, šablona stránky |
| `app/vizualizace.php` | časová osa všech událostí (jen čtení; vlastní AJAX adresy `?ajax=meta|data|people`) |
| `app/lh_common.php` | společné funkce: náhrady `mb_*`, normalizace jmen, pseudonymy, nastavení z DB, načtení konfigurace, režim požadavku, CSRF |
| `app/assets/` | `lh.css`, `lh.js`, `lh-boot.js` (motiv před vykreslením), `viz.css`, `viz.js`, `vendor/` (Bootstrap 5.3.0) |
| `db/schema.sql`, `db/migrations/` | schéma a migrace |
| `scripts/` | cron skripty domácího serveru (záloha, týdenní souhrn) |
| `tools/` | pomocné nástroje (hash hesla, diagnostika hlaviček proxy); **nenasazovat na web** |

Aplikace je záměrně jednoduchá: bez frameworku, bez Composeru, bez sestavování. Verze se čte z hlavičky `index.php` (`Verze:`).

## Režimy: plná verze a náhled
`lh_request_mode()` rozhodne při každém požadavku:
- **Plná verze** jen když (1) požadavek přišel z `allowed_proxy_ip`, (2) nese platnou hlavičku `X-Lh-Proxy` a (3) původní adresa je v `full_hosts`.
- Jinak **náhled**: žádný admin, každý POST se odmítne (kromě volání domácího serveru s `X-Backup-Token`), jména se pseudonymizují (pokud to admin nevypnul) a části vypnuté v `ro_hidden` se vůbec nevykreslí a jejich adresy vrací 403.

## Model důvěry
Aplikace věří **jen proxy**: identita uživatele (`Remote-User`) i IP klienta (`X-Forwarded-For`) se přijmou pouze s platnou `X-Lh-Proxy`. Proto musí
proxy hlavičky od klienta přepisovat, ne přeposílat ([REVERSE_PROXY.md](REVERSE_PROXY.md)). Tajná hlavička se porovnává přes `hash_equals`.

## Zabezpečení v kódu
- **CSRF:** `ob_start('lh_csrf_inject')` vloží do každého formuláře `method="POST"` skrytý token z relace; každý POST ho ověřuje (výjimka: volání s `X-Backup-Token`).
- **Relace:** `HttpOnly`, `SameSite=Lax`, `Secure` za HTTPS, přísný režim ID, po přihlášení nové ID relace.
- **CSP:** skripty jen ze `'self'` nebo s nonce na každý požadavek; žádné inline obsluhy událostí (jednotné atributy `data-confirm`, `data-autosubmit`, `data-check-all`
  zpracovává `assets/lh.js`), žádné externí zdroje, `frame-ancestors 'none'`, `form-action 'self'`.
- **SQL:** dotazy s uživatelským vstupem jsou připravené (`prepare` / `execute`), do SQL se nevkládají proměnné z požadavku; **výstup** se escapuje (`htmlspecialchars`), data pro skripty jdou přes `json_encode` se `JSON_HEX_*`.
- **Hesla:** admin heslo z konfigurace (čisté nebo `password_hash`), neúspěšné přihlášení se zpomalí (`sleep(1)`) a zapíše do záznamu akcí (bez hesel).

## Výpočet analýzy
1. Z `toilet_reports` se pro každou kabinku vyhledají přechody OK → zesraná; **okno incidentu** = od posledního OK po hlášení závady, plus tolerance.
2. Pro každou osobu se z `turnstile_logs` zjistí průchody v okně. Zdroje bodů jsou bitová maska: **1** nahoře (4. patro: turnikety `vlevo`, `vpravo`, `it_dvere`), **2** dole (1. patro: `patro_1`), **4** virtuální body IT,
   **8** svědectví přesné, **16** svědectví nepřesné (pod prahem váhy), **32** informativní, **64** „Během dne ve firmě“ (bez bodu).
3. **Body = počet incidentů, u kterých má osoba aspoň jeden zdroj z vybraných.** Přepočet podle filtrů zdrojů a incidentů běží v prohlížeči (`assets/lh.js`) nad daty v atributech řádků.
4. Skrývání nevinných a důvěryhodných je filtr zobrazení; označený pachatel se jím neskrývá.

## Datový model (21 tabulek)
| Tabulka | Obsah |
|---|---|
| `toilet_reports` | hlášení stavu kabinek (`ok` / `zesrana`), volitelně poznámka, ID zařízení a IP |
| `turnstile_logs` | importované průchody (osoba, IN/OUT, turniket, čas); jedinečný klíč `uq_passes` chrání před duplicitami |
| `sightings` | pozorování osob (čekající / schválená / zamítnutá) |
| `poznatky` | volné poznatky o osobách |
| `employees`, `it_staff`, `it_vacations` | zaměstnanci, členové IT (virtuální body) a jejich dovolené |
| `blacklisted_suspects`, `trusted_persons`, `incident_innocents`, `name_aliases` | vyřazení, důvěryhodní, nevinní u incidentu, aliasy jmen |
| `incident_numbers`, `incident_ignored`, `incident_labels`, `incident_label_map`, `incident_announced`, `milestones` | čísla, vyřazení z počítání, štítky, oznámení, milníky |
| `app_settings`, `audit_log`, `device_seen`, `device_labels` | nastavení, záznam akcí admina, zařízení admina a jejich názvy |

## Rozšiřování
Nová akce admina: přidej ji do pole `$admin_actions` a štítků `$audit_labels` v `index.php`, napiš obsluhu `case` v bloku akcí a formulář v šabloně
(token CSRF se vloží sám). Pro každou funkci přidej test do `tests/`.
