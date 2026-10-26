# Databáze

| Soubor | K čemu |
|---|---|
| `schema.sql` | **Úplné schéma pro novou instalaci** (jen struktura, 21 tabulek). Spusť ho v prázdné databázi. |
| `migrations/NNN_*.sql` | Historické migrace pro **aktualizaci starší instalace**. Spouštěj jen ty, které tvoje databáze ještě nemá, popořadě. Každá je opakovatelná. |
| `maintenance/optimalizace_db.sql` | Ruční návod na jednorázovou optimalizaci (maže duplicity, spouštěj opatrně a po záloze). |
| `demo_data.sql` | Vymyšlená ukázková data (volitelně, jen pro vyzkoušení a testy). Nikdy nespouštěj v ostré databázi. |

## Nová instalace

```bash
mariadb -u UŽIVATEL -p -e "CREATE DATABASE lh CHARACTER SET utf8mb4"
mariadb -u UŽIVATEL -p lh < db/schema.sql
```

Tabulky mají kolaci `utf8mb3_czech_ci`: porovnání jmen nerozlišuje velikost písmen a shodné znaky s diakritikou; aplikace na to spoléhá.

## Aktualizace starší instalace

Tabulky ze základní verze 2.00 (`toilet_reports`, `turnstile_logs`, `blacklisted_suspects` a další) musí už existovat. Migrace dopočítají pozdější tabulky a sloupce:

| Migrace | Verze | Co přidává |
|---|---|---|
| `001_device_log.sql` | 2.00 | Log zařízení a veřejné IP u hlášení (sloupce device_id, client_ip, tabulka device_labels) |
| `002_it_dovolene.sql` | 2.00 | IT oddělení a dovolené (tabulky it_staff, it_vacations) |
| `003_pozorovani.sql` | 2.20 | Pozorování osob (tabulka sightings) |
| `004_it_pikacka.sql` | 2.30 | Čtečka na dveřích IT (turniket it_dvere, sloupec virtual_until) |
| `005_incidenty_pocitani.sql` | 2.50 | Vyřazení incidentu z počítání (tabulka incident_ignored) |
| `006_nastaveni_aplikace.sql` | 2.60 | Nastavení aplikace (tabulka app_settings) |
| `007_zarizeni.sql` | 2.90 | Zařízení admina (tabulka device_seen) |
| `008_poznamky.sql` | 3.04 | Poznámka u hlášení a pozorování (sloupce note) |
| `009_oznameni.sql` | 3.10 | Oznámení incidentu příjemcům (tabulka incident_announced) |
| `010_cisla_incidentu.sql` | 3.30 | Čísla incidentů (tabulka incident_numbers) |
| `011_stitky.sql` | 3.50 | Štítky incidentů (tabulky incident_labels, incident_label_map) |
| `012_nevinni.sql` | 3.70 | Nevinné osoby u incidentů (tabulka incident_innocents) |
| `013_zamestnanci.sql` | 3.80 | Seznam zaměstnanců (tabulka employees) |
| `014_milniky.sql` | 4.50 | Milníky (tabulka milestones) |
| `015_poznatky.sql` | 4.70 | Jiné poznatky (tabulka poznatky) |
| `016_aliasy.sql` | 4.90 | Aliasy jmen (tabulka name_aliases) |
| `017_audit.sql` | 4.90 | Záznam akcí admina (tabulka audit_log) |
| `018_duveryhodni.sql` | 4.93 | Důvěryhodné osoby (tabulka trusted_persons) |

Migrace jsou zapsané pro MariaDB 10.4 a novější. Ověřeno: všech 18 se dá pustit opakovaně bez chyby jak na čisté databázi z `schema.sql`, tak na starší databázi.
