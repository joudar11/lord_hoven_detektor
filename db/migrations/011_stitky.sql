-- Migrace 011: Štítky incidentů (tabulky incident_labels, incident_label_map)
-- Zavedeno ve verzi 3.50. Opakovatelné (lze spustit znovu). Vyžaduje MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================
-- Štítky incidentů (např. vzorec znečištění; spusť v phpMyAdminu)
-- Štítky vytváří, upravuje a přiřazuje jen admin; vidí je všichni.
-- report_id v tabulce přiřazení je id prvního hlášení „zesraná“ incidentu (smazání hlášení smaže i přiřazení).
-- =====================================================================
CREATE TABLE IF NOT EXISTS incident_labels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(40) NOT NULL,
    color CHAR(7) NOT NULL DEFAULT '#6b7280',
    UNIQUE KEY uq_incident_label_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS incident_label_map (
    report_id INT NOT NULL,
    label_id INT NOT NULL,
    PRIMARY KEY (report_id, label_id),
    CONSTRAINT fk_ilm_report FOREIGN KEY (report_id) REFERENCES toilet_reports (id) ON DELETE CASCADE,
    CONSTRAINT fk_ilm_label FOREIGN KEY (label_id) REFERENCES incident_labels (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
