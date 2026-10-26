-- Migrace 006: Nastavení aplikace (tabulka app_settings)
-- Zavedeno ve verzi 2.60. Opakovatelné (lze spustit znovu). Vyžaduje MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================
-- Nastavení aplikace (spusť v phpMyAdminu). Zatím drží dva přepínače:
--   incident_select_global = 1  výběr incidentů pro výpočet podezřelých mají všichni
--   incident_select_admin  = 1  výběr incidentů má jen admin (zkouška)
-- Bez řádku v tabulce je příslušná volba vypnutá.
-- =====================================================================
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(50) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
