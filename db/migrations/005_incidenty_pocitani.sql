-- Migrace 005: Vyřazení incidentu z počítání (tabulka incident_ignored)
-- Zavedeno ve verzi 2.50. Opakovatelné (lze spustit znovu). Vyžaduje MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================
-- Přepínač „počítá se“ u incidentů (spusť v phpMyAdminu)
-- Tabulka obsahuje jen incidenty, které admin vyřadil z počítání.
-- report_id je id prvního hlášení „zesraná“ daného incidentu. Smazání tohoto hlášení příznak smaže automaticky.
-- =====================================================================
CREATE TABLE IF NOT EXISTS incident_ignored (
    report_id INT NOT NULL PRIMARY KEY,
    reason VARCHAR(150) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_incident_ignored_report FOREIGN KEY (report_id) REFERENCES toilet_reports (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
