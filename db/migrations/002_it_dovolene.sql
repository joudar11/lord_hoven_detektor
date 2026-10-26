-- Migrace 002: IT oddělení a dovolené (tabulky it_staff, it_vacations)
-- Zavedeno ve verzi 2.00. Opakovatelné (lze spustit znovu). Vyžaduje MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================
-- IT oddělení a jejich dovolené (spusť v phpMyAdminu, jeden příkaz po druhém)
-- =====================================================================

CREATE TABLE IF NOT EXISTS it_staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_name VARCHAR(150) NOT NULL,
    UNIQUE KEY uq_it_name (person_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS it_vacations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id INT NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_staff_dates (staff_id, date_from, date_to),
    CONSTRAINT fk_it_vac_staff FOREIGN KEY (staff_id) REFERENCES it_staff (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
