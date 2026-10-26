# Zoraxy: hlavičky a předávání identity uživatele do aplikace

Stav k 11. 10. 2026, Zoraxy 3.3.5-rc3 se zapnutým Zoraxy Auth (SSO). Obecný postup pro libovolnou proxy je v REVERSE_PROXY.md; tento dokument je konkrétní zkušenost se Zoraxy.
Každá informace je označena **OVĚŘENO V PRAXI** (skutečný výpis z `zoraxy.php`), **OVĚŘENO (zdroj)** nebo
**NEOVĚŘENO**.

## 1. Skutečný výpis hlaviček (OVĚŘENO V PRAXI)

Zoraxy Auth (SSO) před aplikací na PHP-FPM / Apache, požadavek přes HTTPS, prohlížeč přihlášený uživatel.
Hodnoty osobních údajů jsou nahrazeny.

| Hlavička | Hodnota | Poznámka |
|---|---|---|
| `Remote-User` | `jan.novak` | přihlašovací jméno (to čte aplikace) |
| `Remote-Name` | `jan.novak` | zobrazované jméno (v tomto případě stejné jako login) |
| `Remote-Email` | `user@example.org` | e-mail |
| `X-Auth-Method` | `zoraxy_sso` | označuje, že přihlášení proběhlo přes Zoraxy Auth |
| `X-Forwarded-For` | `192.0.2.14` | **jedna hodnota**: IP klienta, tak ji Zoraxy přidává |
| `X-Real-Ip` | `192.0.2.14` | totéž |
| `X-Forwarded-Proto` | `https` | |
| `X-Forwarded-Host` | `app.example.org` | **původní adresa**, kterou uživatel zadal |
| `X-Forwarded-Server` | `zoraxy-<uuid>` | identifikace instance Zoraxy |
| `Host` | `app.example.org` | **přepsaný na adresu aplikace** (původní je jen v `X-Forwarded-Host`) |
| `X-Lh-Proxy` | (tajný klíč, 48 znaků) | **naše** hlavička z Custom Headers, aplikace ji porovnává s `proxy_secret` |
| `Cookie` | `lh_v`, `PHPSESSID`, `zr_xauth_session` | cookies klienta se předávají aplikaci |
| `Transfer-Encoding` | `chunked` | přítomná i u GET |

Dále dorazí běžné hlavičky prohlížeče (`Accept`, `User-Agent`, `Sec-Fetch-*`, `Sec-Ch-Ua-*` …).
`REMOTE_ADDR` v aplikaci byla veřejná IP domácí sítě (adresa, ze které Zoraxy volá hosting).

Co z toho plyne:
- Jméno se čte z `Remote-User` (`$_SERVER['HTTP_REMOTE_USER']`), zobrazované z `Remote-Name`.
- Původní adresa (např. náhledová) je dostupná **jen** v `X-Forwarded-Host`, protože `Host` je přepsaný.
- **Cookie `zr_xauth_session` dorazí i do aplikace.** Je to relační klíč SSO, aplikace s ním nesmí nic dělat
  a nikdy ho nesmí logovat ani vypisovat.
- `X-Forwarded-For` je zde jedna hodnota (IP klienta). Aplikace bere poslední hodnotu.
- **NEOVĚŘENO:** `Remote-Groups` ve výpisu nebyla (uživatel zřejmě nemá skupiny). Z poznámek k rc3 je jasné,
  že ji Zoraxy Auth umí posílat a před nastavením uživatele ji maže.

## 2. Čím jde identitu předat

| Mechanismus | Kde v Zoraxy | Co dostane aplikace | Stav |
|---|---|---|---|
| **Zoraxy Auth** (vestavěné SSO) | SSO / OAuth2 | `Remote-User`, `Remote-Name`, `Remote-Email`, `X-Auth-Method: zoraxy_sso` | OVĚŘENO V PRAXI |
| **Forward Auth** (Authelia, Authentik, Keycloak) | SSO / OAuth2 → Forward Auth | Hlavičky, které vypíšete v poli „Response Headers“ | OVĚŘENO (zdroj) |
| **OAuth2** | SSO / OAuth2 | Předání jména do hlavičky je nejasné | NEOVĚŘENO |
| **Custom Headers** | Vlastnosti proxy pravidla → Headers | Libovolné pevné hlavičky (Zoraxy → Origin, Origin → klient) | OVĚŘENO V PRAXI (X-Lh-Proxy) |
| Basic Auth | Vlastnosti proxy pravidla | Standardní hlavička Authorization | beze zdroje, běžná vlastnost |

### Forward Auth (OVĚŘENO, zdroj)
- Do pole **Response Headers** se vypíšou názvy hlaviček oddělené čárkou, například
  `Remote-User,Remote-Groups,Remote-Email,Remote-Name` (Authelia). Zoraxy je z odpovědi autentizačního serveru
  zkopíruje do požadavku pro aplikaci.
- Do požadavku na autentizační server se ve výchozím nastavení zkopírují všechny hlavičky klienta
  (PR #1264).

### Zoraxy Auth (ZorxAuth)
- Je to komunitně udržovaná část projektu („hledá se správce“), od verze 3.3.4.
- Používá relační cookie `zr_xauth_session` (OVĚŘENO V PRAXI, že dorazí i do aplikace).
- Má stránky pro odhlášení a správu účtu na adrese služby (u nás `/logout` a `/user`).

## 3. Bezpečnost: na co si dát pozor

1. **Starší verze hlavičku od klienta nepřepsaly, ale přidaly druhou** (issue #1322). Aplikace pak dostala obě
   hodnoty a klient si mohl poslat vlastní `Remote-User`. **Opraveno ve verzi 3.3.5-rc3.** Na starších verzích
   identitě v hlavičce nevěřit.
2. **Samotná hlavička s uživatelem nestačí.** Aplikace smí identitu přijmout jen tehdy, když přijde i tajná
   hlavička, kterou přidává pouze naše proxy (`X-Lh-Proxy`), protože k aplikaci se dá dostat i mimo proxy
   (např. z jiného zařízení v téže síti).
3. **Každý vhost má vlastní pravidlo.** Pokud je pro jednu aplikaci víc adres (u nás hlavní a náhledová), tajná
   hlavička musí být nastavená v **každém** pravidle, jinak aplikace na dané adrese identitu ignoruje.
4. Hodnoty z hlaviček vždy kontrolovat (povolené znaky, délka) a při výpisu escapovat.
5. Cookie `zr_xauth_session`, `Authorization` a tajné hlavičky nikdy nevypisovat ani nelogovat.
6. OAuth2 je v Zoraxy **globální**: jeden klient sdílený všemi proxy pravidly (diskuze #1001, odpověď člena
   komunity).
7. Pravidla „Remove Header“ v Custom Headers běžela podle hlášení v issue #1322 až po kroku, který kopíruje
   hlavičky z autentizace. Na odstraňování identitních hlaviček se nespoléhat.
8. Duplicitní hlavičku PHP nemusí ukázat (záleží na webovém serveru: některé ponechají první, některé poslední
   hodnotu, Apache je spojuje čárkou).

Zdroje:
- https://github.com/tobychui/zoraxy/issues/1322
- https://github.com/tobychui/zoraxy/pull/1324
- https://github.com/tobychui/zoraxy/pull/1264
- https://github.com/tobychui/zoraxy/discussions/1001

## 4. Postup pro novou aplikaci

1. Nahrát `tools/zoraxy.php` (diagnostický skript) vedle aplikace a otevřít přes Zoraxy se zapnutým přihlášením.
   Skript ukáže všechny hlavičky (cookies, Authorization a tajný klíč jen jako délky), co by z nich aplikace
   udělala a zda pozná „náhledovou“ adresu. Po použití soubor smazat.
2. Do konfigurace aplikace zapsat názvy hlaviček (`Remote-User`, `Remote-Name`).
3. V Zoraxy přidat Custom Header se **tajným klíčem** (32+ náhodných znaků) **v každém pravidle** pro aplikaci
   a v aplikaci přijímat identitu jen s ním.
4. Vyzkoušet útok: poslat požadavek přímo na aplikaci s vlastní hlavičkou `Remote-User` bez klíče a ověřit,
   že se ignoruje. Pak totéž s klíčem, ale jako běžný uživatel (ne jako admin).
5. Pokud se používá víc adres, zkontrolovat diagnostiku na každé z nich.

## 5. Čtení hlavičky v PHP

Název hlavičky se převede takto: velká písmena, pomlčky na podtržítka, předpona `HTTP_`.

| Hlavička | Klíč v `$_SERVER` |
|---|---|
| `Remote-User` | `HTTP_REMOTE_USER` |
| `Remote-Name` | `HTTP_REMOTE_NAME` |
| `X-Lh-Proxy` | `HTTP_X_LH_PROXY` |
| `X-Forwarded-Host` | `HTTP_X_FORWARDED_HOST` |

Porovnání tajného klíče vždy přes `hash_equals`.

## 6. Jak je to nastavené v Detektoru Lorda Hoven (verze 3.01)

V `lh_config.php`:

```php
'proxy_secret'     => '...',            // klíč v hlavičce X-Lh-Proxy (musí odpovídat Custom Headers v Zoraxy)
'auth_user_header' => 'Remote-User',    // hlavička s přihlašovacím jménem
'auth_name_header' => 'Remote-Name',    // hlavička se zobrazovaným jménem (nepovinné)
'admin_users'      => ['login'],        // tito uživatelé jsou admin bez hesla (na náhledové adrese nikdy)
'auth_logout_url'  => 'https://auth.example.org/logout',   // tlačítko Odhlásit (jen https; bez nastavení se nezobrazí)
'auth_account_url' => 'https://auth.example.org/user',     // tlačítko Můj účet (jen https)
'full_hosts'       => ['app.example.org'],              // adresy s plnou verzí (zápis), poznají se z X-Forwarded-Host; vše ostatní je náhled
```

Chování: **zápis (plná verze) je jen s platnou hlavičkou `X-Lh-Proxy` a původní adresou z `full_hosts`, jinak je vždy jen náhled** (skripty domácího serveru s vlastním klíčem jsou výjimka). Identita platí jen s platnou hlavičkou `X-Lh-Proxy`. Přihlášenému uživateli se jméno předvyplní a
zamkne v hlášeních (admin může hlásit jménem kohokoli). Tlačítka „Můj účet“ a „Odhlásit“ i jméno v záhlaví se
zobrazí jen tehdy, když aplikace identitu přijala.

**OVĚŘENO V PRAXI:** na náhledové adrese se jméno a tlačítka nezobrazovaly, dokud pravidlo pro tuto adresu v
Zoraxy nedostalo vlastní Custom Header `X-Lh-Proxy` (každé pravidlo ho potřebuje zvlášť). Po doplnění se
zobrazily. Při potížích na jedné z adres nejdřív zkontrolovat tuto hlavičku (diagnostika `zoraxy.php` ukazuje,
zda klíč dorazil a je platný).

## 7. Co ještě není ověřeno

- Zda a kdy Zoraxy Auth posílá `Remote-Groups`.
- Úplný seznam hlaviček, které Zoraxy standardně přidává (ve výpisu byly: `X-Forwarded-For`, `X-Real-Ip`,
  `X-Forwarded-Proto`, `X-Forwarded-Host`, `X-Forwarded-Server`).
- Co přesně dělá adresa `/logout` po klepnutí (kam uživatele vrátí).
