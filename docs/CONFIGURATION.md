# Konfigurace

Dva druhy nastavení: **soubor `lh_config.php`** (tajemství a provozní údaje, mimo adresář webu) a **nastavení v databázi** (tabulka
`app_settings`), které spravuje admin v aplikaci. Vzor souboru: [../config/config.example.php](../config/config.example.php).

## Povinné klíče v lh_config.php
Když některý chybí nebo je prázdný, aplikace skončí chybou 500 s výpisem chybějících klíčů.

| Klíč | Význam |
|---|---|
| `allowed_proxy_ip` | IP adresa, ze které se přijímají požadavky (reverzní proxy tak, jak ji vidí PHP v `REMOTE_ADDR`). Cokoli jiného dostane 403. |
| `db_host`, `db_name`, `db_user`, `db_pass` | připojení k databázi |
| `admin_password` | heslo admina; **doporučeno hash** z `php tools/hash_password.php` (rozpozná se podle `$2y$` / `$argon2`) |
| `proxy_secret` | tajná hodnota hlavičky `X-Lh-Proxy`, kterou přidává pouze proxy (aspoň 32 znaků) |
| `device_secret` | klíč pro podpis ID zařízení (cookie `lh_v`); po nasazení neměnit, jinak se všechna zařízení tváří jako nová |
| `full_hosts` | pole adres (bez portu), na kterých je plná verze se zápisem. Vše ostatní je jen náhled. Adresa se bere z `X-Forwarded-Host`, jinak z `Host`. |

## Volitelné klíče
| Klíč | Výchozí | Význam |
|---|---|---|
| `db_port`, `db_socket` | – | port databáze, nebo unixový soket místo `db_host` |
| `app_url` | z původní adresy požadavku | odkazy v e-mailech |
| `backup_token` | vypnuto | klíč (aspoň 32 znaků) pro stažení zálohy a týdenní souhrn z domácího serveru (hlavička `X-Backup-Token`) |
| `smtp_host`, `smtp_port` (587), `smtp_user`, `smtp_pass`, `smtp_from`, `smtp_verify_tls` (true) | e-maily vypnuty | odesílání přes STARTTLS s přihlášením |
| `auth_user_header` (`Remote-User`), `auth_name_header` (`Remote-Name`) | | názvy hlaviček s identitou od proxy; přijmou se jen s platnou `X-Lh-Proxy` |
| `admin_users` | `[]` | přihlašovací jména (z hlavičky), která jsou admin bez hesla; na náhledové adrese nikdy |
| `auth_logout_url`, `auth_account_url` | skryto | adresy (jen `https://`) tlačítek Odhlásit a Můj účet |
| `pseudo_secret` | odvozeno z ostatních tajných hodnot | klíč pseudonymů osob v náhledu; změna přepíše všechny pseudonymy |
| `photo_dir` | `fotodokumentace` vedle `index.php` | složka s podsložkami podle čísla incidentu |
| `ip_login_ips` | `[]` (vypnuto) | **nebezpečné**: IP klientů, pro které po kliknutí na „Přihlásit“ projde jakékoli heslo včetně prázdného. Platí jen s platnou `X-Lh-Proxy` a IP klienta z `X-Forwarded-For`; používej jen v důvěryhodné síti. Neplatné položky se ignorují. |
| `retention_audit_days`, `retention_device_days`, `retention_ip_days` | 0 = nemazat | retence ve dnech: záznam akcí, zařízení admina, anonymizace IP u hlášení a pozorování. Čištění běží nejvýš jednou denně při prvním požadavku. |
| `texts` | výchozí české texty | přepis vět v e-mailech: `culprit_mail_intro`, `culprit_mail_outro` (e-mail o dopadení) a `digest_outro` (konec týdenního souhrnu); každý je pole řádků |

## Nastavení v databázi (spravuje admin v aplikaci)
| Klíč v `app_settings` | Výchozí | Význam |
|---|---|---|
| `tolerance_minutes` | 5 | prodloužení okna incidentu v minutách (0 až 60) |
| `collect_disabled` | `0` | `1` = sběr dat vypnutý (hlášení, pozorování a poznatky může zadávat jen admin) |
| `culprit_name`, `culprit_at`, `culprit_by`, `culprit_mailed_at` | prázdné | označený pachatel (jen po ukončení sběru dat) |
| `ro_hidden` | prázdné | seznam částí, které náhled nezobrazuje: `timeline`, `passes`, `photos`, `stats`, `history` |
| `pseudonymize_readonly` | zapnuto (cokoli kromě `0`) | v náhledu se místo jmen ukazují pseudonymy |
| `admin_notify` | všechny skupiny | které skupiny akcí admina posílají e-mail adminům |
| `mail_admins`, `mail_digest` | prázdné | adresy příjemců upozornění a týdenního souhrnu (oddělené čárkou, středníkem nebo novým řádkem) |
| `digest_last_sent`, `retention_last_run`, `mail_last_error` | | provozní hodnoty aplikace, ručně neměnit |

## Přístup a role
- **Plná verze** (`full_hosts` + platná `X-Lh-Proxy`): kdokoli může hlásit; admin je podle `admin_users` (identita z proxy), podle hesla (tlačítko **Admin**) nebo podle `ip_login_ips`.
- **Náhled** (jiná adresa, chybějící hlavička, nebo `X-Lh-Readonly: 1`): vždy jen čtení, žádný admin, žádný POST (výjimka jen volání domácího serveru s `X-Backup-Token`).
