# Reverzní proxy

Aplikace počítá s tím, že před ní stojí reverzní proxy, která **jediná** smí přidávat určité hlavičky. Požadavky z jiné IP než `allowed_proxy_ip` se odmítnou.

## Co musí proxy posílat
| Hlavička | Hodnota | Proč |
|---|---|---|
| `X-Lh-Proxy` | tajná hodnota = `proxy_secret` z konfigurace | bez ní je aplikace jen v režimu náhledu a ignoruje identitu i IP z hlaviček; **nastavit v každém pravidle / vhostu** |
| `X-Forwarded-Host` | původní adresa, kterou uživatel zadal | podle ní se pozná plná verze (`full_hosts`) a náhled |
| `X-Forwarded-For` | **jedna hodnota**: IP klienta (přepsat, ne připojit) | záznam akcí a přihlášení podle IP (`ip_login_ips`) |
| `X-Forwarded-Proto` | `https` | cookie relace dostane příznak `Secure` |
| `Remote-User`, `Remote-Name` | jen když proxy ověřila uživatele (SSO / forward auth) | identita; jinak je proxy musí **odstranit nebo přepsat** |

**Nejčastější bezpečnostní chyba:** proxy přepošle `Remote-User` od klienta. Protože aplikace identitě věří, když přijde s platnou `X-Lh-Proxy`, mohl by si
útočník poslat vlastní `Remote-User: admin`. Proxy proto musí tuto hlavičku vždy přepsat nebo smazat, i když přihlašování nepoužíváš.

## Dvě adresy: plná verze a náhled
Používej dvě adresy (vhosty) na stejnou aplikaci, např. `app.example.org` (v `full_hosts`) a `preview.example.org` (není v `full_hosts` a ukazuje pseudonymy).
`X-Lh-Proxy` se posílá v obou. Náhled lze vynutit i hlavičkou `X-Lh-Readonly: 1`.

## Příklady
> **Příklady níže nejsou v tomto projektu otestované** (otestované je jen to, co popisuje [REVERSE_PROXY_ZORAXY.md](REVERSE_PROXY_ZORAXY.md)).
> Ověř je diagnostickým skriptem `tools/zoraxy.php` (po použití ho smaž) a útokem popsaným níže.

### nginx
```nginx
server {
    listen 443 ssl;
    server_name app.example.org;
    location / {
        proxy_pass http://127.0.0.1:8080;                 # Apache / PHP s aplikací
        proxy_set_header Host               $host;
        proxy_set_header X-Forwarded-Host   $host;
        proxy_set_header X-Forwarded-For    $remote_addr; # přepsat, ne $proxy_add_x_forwarded_for
        proxy_set_header X-Forwarded-Proto  $scheme;
        proxy_set_header X-Lh-Proxy         "TAJNA_HODNOTA_PROXY_SECRET";
        proxy_set_header Remote-User        "";           # smazat hlavičky od klienta
        proxy_set_header Remote-Name        "";
        # SSO přes auth_request: auth_request /auth; auth_request_set $u $upstream_http_remote_user; proxy_set_header Remote-User $u;
    }
}
```

### Caddy
```caddy
app.example.org {
    reverse_proxy 127.0.0.1:8080 {
        header_up X-Lh-Proxy "TAJNA_HODNOTA_PROXY_SECRET"
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-For {remote_host}
        header_up -Remote-User
        header_up -Remote-Name
    }
}
```

### Apache (mod_proxy, mod_headers)
```apache
<VirtualHost *:443>
    ServerName app.example.org
    ProxyPass / http://127.0.0.1:8080/
    RequestHeader set X-Lh-Proxy "TAJNA_HODNOTA_PROXY_SECRET"
    RequestHeader set X-Forwarded-Host "app.example.org"
    RequestHeader set X-Forwarded-Proto "https"
    RequestHeader unset Remote-User
    RequestHeader unset Remote-Name
</VirtualHost>
```

## Jak proxy ověřit
1. Nahraj `tools/zoraxy.php` vedle aplikace, otevři ho přes proxy a zkontroluj, které hlavičky dorazily a že tajný klíč je platný. **Po použití soubor smaž.**
2. Pošli požadavek přímo na aplikaci (mimo proxy) s vlastní hlavičkou `Remote-User: admin`: aplikace musí vrátit 403 (jiná IP), případně identitu ignorovat (bez `X-Lh-Proxy`).
3. Pošli přes proxy požadavek s vlastní hlavičkou `Remote-User: admin`: v aplikaci se **nesmí** objevit jako přihlášený admin.
4. Zkontroluj obě adresy (plnou i náhledovou): na náhledové nesmí jít odeslat žádný formulář.
