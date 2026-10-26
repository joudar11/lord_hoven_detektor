-- Migrace 009: Oznámení incidentu příjemcům (tabulka incident_announced)
-- Zavedeno ve verzi 3.10. Opakovatelné (lze spustit znovu). Vyžaduje MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================
-- Oznámení incidentu všem příjemcům týdenního souhrnu (tlačítko „Oznámit všem“, spusť v phpMyAdminu)
-- Tabulka drží jen incidenty, které už byly oznámeny. report_id je id prvního hlášení „zesraná“ incidentu;
-- smazání tohoto hlášení záznam smaže automaticky.
-- =====================================================================
CREATE TABLE IF NOT EXISTS incident_announced (
    report_id INT NOT NULL PRIMARY KEY,
    announced_at DATETIME NOT NULL,
    CONSTRAINT fk_incident_announced_report FOREIGN KEY (report_id) REFERENCES toilet_reports (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
