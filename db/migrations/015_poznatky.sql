-- Migrace 015: Jiné poznatky (tabulka poznatky)
-- Zavedeno ve verzi 4.70. Opakovatelné (lze spustit znovu). Vyžaduje MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================
-- Poznatky: sdílené poznámky, které se nehodí jinam (např. „Jan Novák je levák“, „v kuchyňce byl na zemi toaleťák“).
-- Mohou, ale nemusí se vztahovat k osobě a ke konkrétnímu datu a času. Vidí je každý, kdo může podávat hlášení (ne náhledová verze);
-- upravuje a maže jen admin. Spusť v phpMyAdminu.
-- =====================================================================
CREATE TABLE IF NOT EXISTS poznatky (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reporter VARCHAR(100) NOT NULL,
    person_name VARCHAR(150) NULL,
    happened_at DATETIME NULL,
    poznatek VARCHAR(500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    device_id CHAR(32) NULL,
    client_ip VARCHAR(45) NULL,
    KEY idx_poznatky_created (created_at),
    KEY idx_poznatky_happened (happened_at),
    KEY idx_poznatky_device (device_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
