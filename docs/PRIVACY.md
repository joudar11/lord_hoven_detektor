# Ochrana osobních údajů

> Tento dokument **není právní poradenství**. Požadavky závisí na zemi a na vašem zaměstnavateli. Před nasazením se poraď s pověřencem pro ochranu
> osobních údajů, právníkem nebo personálním oddělením.

## Jaké údaje aplikace zpracovává
| Údaj | Odkud | Kde |
|---|---|---|
| jména a časy vstupu a odchodu zaměstnanců | import docházky z turniketů | `turnstile_logs` |
| hlášení stavu kabinek, jména hlásících | formulář | `toilet_reports` |
| pozorování osob (kdo kdy vycházel z kabinky) | formulář | `sightings` |
| poznatky o osobách | formulář | `poznatky` |
| ID zařízení (cookie `lh_v`) a IP adresa u hlášení | automaticky | `toilet_reports`, `sightings`, `device_seen` |
| jména adminů a jejich akce, IP | automaticky | `audit_log` |
| odvozené: žebříček „podezřelých“, počty bodů | výpočet | jen v paměti / v zobrazení |

Aplikace profiluje jmenovité osoby. Jde o **statistický ukazatel, ne důkaz**: shoda průchodu s časovým oknem neříká nic o tom, co kdo udělal.

## Doporučení
- **Právní základ a informování.** Rozhodni a zdokumentuj, proč údaje zpracováváš, a informuj dotčené lidi. U zaměstnanců se v mnoha zemích vyžaduje i projednání (zaměstnavatel, odbory, pověřenec).
- **Minimalizace.** Importuj jen dny, které potřebuješ. Pozorování a poznatky vypni, pokud je nepotřebuješ (Admin → **Sběr dat**).
- **Pseudonymizace náhledu** je ve výchozím stavu zapnutá: náhledová adresa ukazuje místo jmen pseudonymy. Vypínej ji jen vědomě.
- **Retence.** Nastav `retention_audit_days`, `retention_device_days` a `retention_ip_days` ([CONFIGURATION.md](CONFIGURATION.md)). Data docházky maž ručně po skončení vyšetřování.
- **Přístup.** Administrátoři vidí jména. Omez jejich počet, používej hash hesla a přihlášení přes proxy (SSO s dvoufázovým ověřením).
- **Zálohy** obsahují všechna data: šifruj je, omez přístup a nastav jejich mazání (skript `lh-backup.sh` maže po 14 dnech).
- **Žádné skutečné údaje ve veřejných repozitářích, hlášeních chyb ani snímcích obrazovky.** Pro ukázky použij `db/demo_data.sql` (vymyšlená data).
- **Označení pachatele** zveřejní jméno všem uživatelům (v náhledu pseudonym). Používej ho s rozmyslem.

## Co aplikace nedělá
Neposílá data třetím stranám, nepoužívá externí CDN ani analytiku. E-maily odesílá jen přes SMTP server, který nakonfiguruješ.
