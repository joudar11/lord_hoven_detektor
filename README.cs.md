# Detektor Lorda Hoven

Malá samostatně provozovaná webová aplikace v PHP, která **statisticky porovnává průchody turniketů s časovými okny incidentů**
(kabinka na toaletě nahlášená jako „zesraná“). Vznikla jako kancelářský vtip a vyrostla v kompletní analytický nástroj:
hlášení kolegů určují časové okno každého incidentu, importovaná docházka z turniketů dává žebříček toho, kdo byl v budově
ve správný čas, a nad tím je časová osa, statistiky a týdenní souhrn e-mailem.

> **Důležité před nasazením mezi skutečné lidi.** Aplikace zpracovává osobní údaje zaměstnanců (jména a časy vstupu do budovy)
> a řadí jmenovité osoby jako „podezřelé“. Výsledek je **statistický nápověda, ne důkaz**. Provoz na pracovišti potřebuje právní
> základ, informování dotčených lidí a podle země i projednání se zaměstnavatelem, pověřencem pro ochranu osobních údajů nebo
> odborovou organizací. Podrobnosti: [docs/PRIVACY.md](docs/PRIVACY.md). Autor neposkytuje právní poradenství.

## Jak to funguje

1. Kolegové hlásí stav kabinek (**OK** / **zesraná**). Každý přechod z OK na „zesraná“ je **incident**; jeho časové okno je od posledního
   hlášení OK do hlášení závady. Admin nastavuje **toleranci** (výchozí 5 minut), o kterou se okno prodlužuje.
2. Admin importuje docházku z turniketů (viz [docs/IMPORT.md](docs/IMPORT.md)).
3. Každá osoba dostane **bod za incident**, u kterého má v okně průchod (nebo jiný zdroj bodu). Zdroje bodů:
   *nahoře (4. patro)*, *dole (1. patro)*, *virtuální body členů IT* (čtečka na dveřích IT), *svědectví přesná a nepřesná* (pozorování osob)
   a *informativní* zdroj; navíc se bez bodu ukazuje, kdo byl ten den ve firmě („Během dne ve firmě“).
4. Žebříček se dá filtrovat podle zdrojů bodů a vybraných incidentů; **nevinní** (alibi) a **důvěryhodní** (seznam admina) se výchozím filtrem skrývají.

## Funkce

- Hlášení stavu kabinek, pozorování osob a poznatky (admin může sběr dat jedním přepínačem vypnout a po dopadení označit pachatele).
- Žebříček podezřelých a pohled podle incidentů, štítky incidentů, milníky, čísla incidentů, počítadlo dnů od posledního incidentu.
- Časová osa všech událostí (`vizualizace.php`), statistiky a předpověď, historie hlášení, fotodokumentace.
- **Náhledová adresa jen pro čtení s pseudonymizovanými jmény** a přepínače, co všechno náhled ukazuje.
- Aliasy jmen (sloučení variant), vyřazení osob, import s kontrolou před uložením, úpravy adminem.
- Týdenní souhrn e-mailem, zálohy a souhrn z domácího serveru (cron skripty), záznam akcí admina, volitelná retence údajů.
- Zabezpečení: tokeny CSRF, CSP s nonce, bezpečné cookie, hashované heslo admina, model důvěryhodné proxy.

## Požadavky

PHP 8.1+ (testováno na 8.3) s `pdo_mysql` a `openssl`, MariaDB 10.4+ (testováno na 10.11), webový server **za reverzní proxy**, která přidává
tajnou hlavičku ([docs/REVERSE_PROXY.md](docs/REVERSE_PROXY.md)). Bez Composeru, bez sestavování a bez externích CDN.
Rozšíření `mbstring` není nutné (aplikace má náhrady).

## Rychlé vyzkoušení (jen vymyšlená data)

```bash
bash tests/env.sh up demo          # jednorázová MariaDB + PHP servery + vymyšlená ukázková data
# otevři http://127.0.0.1:8081/ (plná verze) a http://127.0.0.1:8092/ (náhled jen pro čtení)
bash tests/env.sh down
```

V ukázce má nejvíc bodů fiktivní „Adam Vzorový“ (7 incidentů). Skutečné nasazení: [docs/INSTALL.md](docs/INSTALL.md).

## Dokumentace

| Dokument | Obsah |
|---|---|
| [docs/INSTALL.md](docs/INSTALL.md) | instalace krok za krokem, cron, aktualizace |
| [docs/CONFIGURATION.md](docs/CONFIGURATION.md) | všechny klíče konfigurace a nastavení v administraci |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | soubory, režimy plná verze / náhled, model důvěry, datový model |
| [docs/REVERSE_PROXY.md](docs/REVERSE_PROXY.md) | co musí proxy posílat, příklady (nginx, Caddy, Apache) |
| [docs/REVERSE_PROXY_ZORAXY.md](docs/REVERSE_PROXY_ZORAXY.md) | ověřená zkušenost se Zoraxy |
| [docs/IMPORT.md](docs/IMPORT.md) | formát importu docházky a kontroly |
| [docs/PRIVACY.md](docs/PRIVACY.md) | osobní údaje, doporučení, retence |
| [docs/UPGRADE.md](docs/UPGRADE.md) | přechod ze starších verzí (před 5.00) |
| [db/README.md](db/README.md) | schéma databáze a migrace |
| [tests/README.md](tests/README.md) | testy |

## Licence

MIT ([LICENSE](LICENSE)). Součásti třetích stran (Bootstrap, ikony Feather): [NOTICE.md](NOTICE.md).
