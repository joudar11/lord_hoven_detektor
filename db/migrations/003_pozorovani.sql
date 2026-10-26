-- Migrace 003: Pozorování osob (tabulka sightings)
-- Zavedeno ve verzi 2.20. Opakovatelné (lze spustit znovu). Vyžaduje MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================
-- Pozorování osob na toaletách (svědectví) – spusť v phpMyAdminu
-- Nová pozorování mají stav 'pending' a do žebříčku se promítnou až po schválení adminem.
-- =====================================================================

CREATE TABLE IF NOT EXISTS sightings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_name VARCHAR(150) NOT NULL,
    location ENUM('invalide','leva','prava','toalety_kabinka','toalety_nevim','pisoar') NOT NULL,
    direction ENUM('vychazel','vchazel','poblizu') NOT NULL DEFAULT 'vychazel',
    seen_at DATETIME NOT NULL,
    reporter VARCHAR(100) NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    name_known TINYINT(1) NOT NULL DEFAULT 0,
    device_id CHAR(32) NULL,
    client_ip VARCHAR(45) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME NULL,
    KEY idx_status_seen (status, seen_at),
    KEY idx_device_created (device_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
