-- Migrace 012: Nevinné osoby u incidentů (tabulka incident_innocents)
-- Zavedeno ve verzi 3.70. Opakovatelné (lze spustit znovu). Vyžaduje MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================
-- Nevinné osoby u incidentů (spusť v phpMyAdminu)
-- Zadává je jen admin (s volitelným důvodem), vidí je všichni. Nemá vliv na body.
-- report_id je id prvního hlášení „zesraná“ incidentu (smazání hlášení smaže i záznamy).
-- =====================================================================
CREATE TABLE IF NOT EXISTS incident_innocents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    person_name VARCHAR(100) NOT NULL,
    reason VARCHAR(200) NULL,
    UNIQUE KEY uq_innocent (report_id, person_name),
    CONSTRAINT fk_innocent_report FOREIGN KEY (report_id) REFERENCES toilet_reports (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
