-- Migrace 013: Seznam zaměstnanců (tabulka employees)
-- Zavedeno ve verzi 3.80. Opakovatelné (lze spustit znovu). Vyžaduje MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================
-- Seznam všech zaměstnanců (spusť v phpMyAdminu)
-- Zadává ho admin; slouží k výpočtu sekce „Osoby bez alibi“ v žebříčku podezřelých.
-- =====================================================================
CREATE TABLE IF NOT EXISTS employees (
    name VARCHAR(100) NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
