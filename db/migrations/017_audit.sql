-- Migrace 017: Záznam akcí admina (tabulka audit_log)
-- Zavedeno ve verzi 4.90. Opakovatelné (lze spustit znovu). Vyžaduje MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================
-- Záznam akcí admina přímo v aplikaci (Admin → Záznam akcí). Zapisuje se vždy, i když jsou e-maily o akcích vypnuté.
-- Neobsahuje hesla ani klíče. Spusť v phpMyAdminu.
-- =====================================================================
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_name VARCHAR(100) NOT NULL DEFAULT '',
    ip VARCHAR(45) NULL,
    device VARCHAR(12) NULL,
    action_key VARCHAR(60) NOT NULL DEFAULT '',
    label VARCHAR(120) NOT NULL,
    grp VARCHAR(20) NOT NULL DEFAULT 'settings',
    result VARCHAR(500) NULL,
    details MEDIUMTEXT NULL,
    KEY idx_audit_created (created_at),
    KEY idx_audit_user (user_name),
    KEY idx_audit_grp (grp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
