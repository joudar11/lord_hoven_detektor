-- Migrace 007: Zařízení admina (tabulka device_seen)
-- Zavedeno ve verzi 2.90. Opakovatelné (lze spustit znovu). Vyžaduje MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================
-- Zařízení použitá pro admin přístup (spusť v phpMyAdminu)
-- Díky této tabulce jde pojmenovat i zařízení, ze kterého nebylo nikdy nic hlášeno.
-- Ukládá se jen otisk zařízení admina, čas a IP adresa (nikoli zařízení běžných návštěvníků).
-- =====================================================================
CREATE TABLE IF NOT EXISTS device_seen (
    device_id CHAR(32) NOT NULL PRIMARY KEY,
    first_seen DATETIME NOT NULL,
    last_seen DATETIME NOT NULL,
    last_ip VARCHAR(45) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
