# Přechod ze starších verzí (před 5.00)

Verze 5.00 je první veřejné vydání. Změny, které mohou ovlivnit existující instalaci:

1. **Povinný klíč `full_hosts`.** Dřív měl pevnou výchozí hodnotu; teď ho musíš mít v `lh_config.php`, jinak aplikace skončí chybou 500 s výpisem chybějících klíčů.
2. **Dřívější pevné výchozí hodnoty zmizely:** `app_url` (odvodí se z původní adresy), `auth_logout_url` a `auth_account_url` (bez nastavení se tlačítka nezobrazí)
   a seznam IP pro přihlášení bez hesla (nově klíč `ip_login_ips`, výchozí je vypnuto). Pokud jsi je používal, doplň je do konfigurace.
3. **Nové soubory:** nahraj také `lh_common.php` a celou složku `assets/` (CSS, skripty a Bootstrap jsou nyní lokální, žádné CDN).
4. **CSRF:** každý formulář `POST` nese token. Vlastní skripty, které posílají POST na aplikaci, přestanou fungovat (výjimka: zálohy a souhrn s `X-Backup-Token`).
5. **CSP:** inline skripty a inline obsluhy událostí jsou zakázané. Vlastní úpravy šablon musí používat soubory v `assets/` nebo nonce (`$lh_nonce`).
6. **Heslo admina:** může zůstat čisté, doporučuje se hash (`php tools/hash_password.php`).
7. **Přihlášení heslem** má nově tlačítko **Admin** v záhlaví (dřív existoval jen koncový bod bez formuláře).
8. **Databáze:** pokud ještě nemáš všechny migrace, spusť chybějící z `db/migrations/` ([../db/README.md](../db/README.md)). Žádná nová migrace oproti verzi 4.99 není potřeba.
9. **Skripty domácího serveru:** `LH_URL` je nyní povinné (dřív měl skript pevně zapsanou adresu).

Pořadí: záloha databáze → doplnit konfiguraci → nahrát `app/` → zkontrolovat obě adresy (plnou i náhledovou) → vyzkoušet přihlášení admina a jedno hlášení.
