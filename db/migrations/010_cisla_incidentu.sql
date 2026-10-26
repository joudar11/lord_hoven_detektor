-- Migrace 010: Čísla incidentů (tabulka incident_numbers)
-- Zavedeno ve verzi 3.30. Opakovatelné (lze spustit znovu). Vyžaduje MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================
-- Stálá čísla incidentů (spusť v phpMyAdminu)
-- Číslo se přidělí incidentu jednou a pak se nemění. Stávající incidenty se při prvním zobrazení
-- očíslují chronologicky (nejstarší = #1). Číslo smazaného incidentu zůstane vyhrazené (znovu se nepoužije).
-- report_id je id prvního hlášení „zesraná“ incidentu (záměrně bez cizího klíče, aby číslo přežilo smazání hlášení).
-- =====================================================================
CREATE TABLE IF NOT EXISTS incident_numbers (
    report_id INT NOT NULL PRIMARY KEY,
    inc_no INT NOT NULL,
    UNIQUE KEY uq_incident_inc_no (inc_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
