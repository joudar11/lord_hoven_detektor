-- Migrace 014: Milníky (tabulka milestones)
-- Zavedeno ve verzi 4.50. Opakovatelné (lze spustit znovu). Vyžaduje MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================
-- Milníky: důležité informace s datem (osoba skončila / nastoupila / je pryč, změna provozu, jiné).
-- Zadává, upravuje a maže jen admin, vidí je všichni. Spusť v phpMyAdminu.
--   left   = osoba skončila (date_from = POSLEDNÍ den v práci)
--   joined = osoba nastoupila (date_from = první den v práci)
--   away   = osoba je pryč (date_from až date_to včetně)
--   change = změna provozu, other = jiná informace (jen značka s datem)
-- =====================================================================
CREATE TABLE IF NOT EXISTS milestones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(10) NOT NULL,
    person_name VARCHAR(100) NULL,
    date_from DATE NOT NULL,
    date_to DATE NULL,
    title VARCHAR(120) NOT NULL DEFAULT '',
    note VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_milestones_date (date_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
