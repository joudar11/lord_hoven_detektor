-- Migrace 001: Log zařízení a veřejné IP u hlášení (sloupce device_id, client_ip, tabulka device_labels)
-- Zavedeno ve verzi 2.00. Opakovatelné (lze spustit znovu). Vyžaduje MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================
-- Log zařízení a veřejné IP u hlášení (spusť v phpMyAdminu po jednom příkazu)
-- Starší hlášení budou mít v nových sloupcích NULL.
-- =====================================================================

ALTER TABLE toilet_reports
    ADD COLUMN IF NOT EXISTS device_id CHAR(32) NULL,
    ADD COLUMN IF NOT EXISTS client_ip VARCHAR(45) NULL;

CREATE TABLE IF NOT EXISTS device_labels (
    device_id CHAR(32) NOT NULL PRIMARY KEY,
    label VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
