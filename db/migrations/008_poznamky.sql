-- Migrace 008: Poznámka u hlášení a pozorování (sloupce note)
-- Zavedeno ve verzi 3.04. Opakovatelné (lze spustit znovu). Vyžaduje MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================
-- Nepovinná poznámka (max 300 znaků) k hlášení stavu kabinky a k pozorování osoby (spusť v phpMyAdminu)
-- =====================================================================
ALTER TABLE toilet_reports ADD COLUMN IF NOT EXISTS note VARCHAR(300) NULL;
ALTER TABLE sightings ADD COLUMN IF NOT EXISTS note VARCHAR(300) NULL;
