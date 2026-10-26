-- Migrace 016: Aliasy jmen (tabulka name_aliases)
-- Zavedeno ve verzi 4.90. Opakovatelné (lze spustit znovu). Vyžaduje MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================
-- Aliasy jmen: pravidlo „alias → hlavní jméno“ pro varianty jména téhož člověka (např. přihlašovací jméno a celé jméno).
-- Spravuje jen admin (Admin → Aliasy jmen). Při sloučení se jméno přepíše ve všech datech a pravidlo se uloží sem,
-- takže se při dalším importu docházky a zadávání osob alias automaticky změní na hlavní jméno. Spusť v phpMyAdminu.
-- =====================================================================
CREATE TABLE IF NOT EXISTS name_aliases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alias VARCHAR(150) NOT NULL,
    canonical VARCHAR(150) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_name_alias (alias),
    KEY idx_name_alias_canonical (canonical)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
