-- Migrace 018: Důvěryhodné osoby (tabulka trusted_persons)
-- Zavedeno ve verzi 4.93. Opakovatelné (lze spustit znovu). Vyžaduje MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================
-- Důvěryhodní: osoby z našeho oddělení, u kterých se silně domníváme, že nejsou pachatel.
-- Spravuje jen admin (Admin → Důvěryhodní). V žebříčku je filtr „Skrýt důvěryhodné“ (výchozí zapnutý), který je skrývá
-- na stejných místech jako filtr „Skrýt nevinné“. Spusť v phpMyAdminu.
-- =====================================================================
CREATE TABLE IF NOT EXISTS trusted_persons (
    person_name VARCHAR(150) NOT NULL PRIMARY KEY,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
