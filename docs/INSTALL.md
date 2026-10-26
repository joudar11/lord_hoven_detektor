# Instalace

## 1. Požadavky
- PHP 8.1 nebo novější s `pdo_mysql` a `openssl` (odesílání e-mailů přes STARTTLS). `mbstring` není nutné.
- MariaDB 10.4+ (testováno 10.11). MySQL nebylo testováno.
- Webový server (Apache + PHP, nginx + PHP-FPM), **přístupný jen přes reverzní proxy** ([REVERSE_PROXY.md](REVERSE_PROXY.md)).
- HTTPS na straně uživatelů (cookie relace dostane příznak `Secure`, když proxy hlásí `X-Forwarded-Proto: https`).

## 2. Databáze
```bash
mariadb -u root -p -e "CREATE DATABASE lh CHARACTER SET utf8mb4; CREATE USER 'lh'@'localhost' IDENTIFIED BY 'SILNE_HESLO'; GRANT ALL ON lh.* TO 'lh'@'localhost';"
mariadb -u lh -p lh < db/schema.sql
```
Starší instalaci aktualizuj podle [../db/README.md](../db/README.md) a [UPGRADE.md](UPGRADE.md). Soubor `db/demo_data.sql` do ostré databáze nikdy nenahrávej.

## 3. Soubory
Obsah složky `app/` nahraj do adresáře webu (např. `/var/www/lh/`): `index.php`, `vizualizace.php`, `lh_common.php` a složku `assets/`.
**Konfigurace patří mimo adresář webu:**
```bash
cp config/config.example.php /var/www/lh_config.php     # o úroveň výš než index.php
chmod 600 /var/www/lh_config.php
```
Vyplň aspoň povinné klíče ([CONFIGURATION.md](CONFIGURATION.md)). Hash hesla admina vyrobíš příkazem `php tools/hash_password.php`.
Nenahrávej složku `tests/`, `tools/zoraxy.php` ani `db/demo_data.sql` na veřejný web.

## 4. Reverzní proxy
Proxy musí aplikaci posílat tajnou hlavičku `X-Lh-Proxy` (hodnota = `proxy_secret`) a původní adresu v `X-Forwarded-Host`. Bez toho je
aplikace jen v režimu náhledu. Postup a příklady: [REVERSE_PROXY.md](REVERSE_PROXY.md). IP adresa proxy, jak ji vidí PHP, je `allowed_proxy_ip`.

## 5. První spuštění
1. Otevři aplikaci na adrese z `full_hosts`. Admin se přihlásí buď identitou z proxy (`admin_users`), nebo tlačítkem **Admin** v záhlaví a heslem.
2. Admin → **Seznam všech zaměstnanců**: vlož seznam jmen (pro „Osoby bez alibi“ a kontrolu překlepů).
3. Admin → **Import docházky z turniketů**: vlož docházku po turniketech a dnech ([IMPORT.md](IMPORT.md)).
4. Admin → **Tolerance po nálezu incidentu**, **IT oddělení a dovolené**, **Anonymizace osob v náhledové verzi**, **E-maily** a další panely podle potřeby.
5. Kolegům dej adresu plné verze (zápis) a případně náhledovou adresu (jen čtení, pseudonymy).

## 6. E-maily (volitelné)
Doplň `smtp_*` v konfiguraci a v administraci adresy příjemců (upozornění adminům, týdenní souhrn). Port 587 se STARTTLS a přihlášením.

## 7. Zálohy a týdenní souhrn z domácího serveru (volitelné)
1. V konfiguraci nastav `backup_token` (např. `openssl rand -hex 32`).
2. Na domácím serveru ulož stejný klíč do souboru `~/lh/.lh-backup-token` (`chmod 600`), zkopíruj `scripts/lh-backup.sh` a `scripts/lh-digest.sh` a nastav jim spustitelnost (`chmod +x ~/lh/*.sh`).
3. Hosting musí volání pustit (stejná IP jako `allowed_proxy_ip` nebo přímý přístup). Příklad cronu:
```cron
30 3 * * * LH_URL=https://example.org/lh/index.php /home/UZIVATEL/lh/lh-backup.sh >> /home/UZIVATEL/lh/backups/backup.log 2>&1
0 7 * * 1  LH_URL=https://example.org/lh/index.php /home/UZIVATEL/lh/lh-digest.sh >> /home/UZIVATEL/lh/backups/digest.log 2>&1
```
Skripty pošlou klíč jen přes HTTPS (výjimka `LH_ALLOW_HTTP=1` je jen pro zkoušení) a zálohu zkontrolují (musí končit značkou konce).
Zálohy obsahují osobní údaje: ulož je s právy `600` a šifrovaně mimo dosah ostatních.

## 8. Fotodokumentace (volitelné)
Fotky ukládej do `fotodokumentace/<číslo incidentu>/foto.jpg` vedle `index.php` (nebo do složky z `photo_dir`). Složka musí být čitelná pro PHP; aplikace
soubory vydává sama (s kontrolou cesty), takže je nemusíš zveřejňovat přes web server.

## 9. Kontrolní seznam před ostrým provozem
- [ ] `lh_config.php` je mimo adresář webu a má práva `600`; heslo admina je hash.
- [ ] Přímý přístup k aplikaci mimo proxy je zablokovaný (firewall / jen `allowed_proxy_ip`).
- [ ] Proxy **přepisuje** nebo maže hlavičky `Remote-User`, `Remote-Name` a `X-Forwarded-For` od klienta ([REVERSE_PROXY.md](REVERSE_PROXY.md)).
- [ ] Anonymizace náhledové verze je zapnutá (výchozí stav).
- [ ] Přečtený [PRIVACY.md](PRIVACY.md): právní základ, informování lidí, retence.
- [ ] Zálohy jsou šifrované a testovaně obnovitelné.
