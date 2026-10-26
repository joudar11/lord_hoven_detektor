-- Migrace 004: Čtečka na dveřích IT (turniket it_dvere, sloupec virtual_until)
-- Zavedeno ve verzi 2.30. Opakovatelné (lze spustit znovu). Vyžaduje MariaDB 10.4+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================
-- Čtečka na dveřích IT (spusť v phpMyAdminu po jednom příkazu, nejdřív si udělej export tabulek)
-- =====================================================================

-- 1) Nový turniket "IT dveře" (4. patro): rozšíření seznamu hodnot sloupce turnstile
ALTER TABLE turnstile_logs
    MODIFY turnstile ENUM('vlevo','vpravo','patro_1','it_dvere') NOT NULL;

-- 2) Datum, od kterého se členu IT přestanou přidělovat virtuální body (NULL = bez omezení)
ALTER TABLE it_staff
    ADD COLUMN IF NOT EXISTS virtual_until DATE NULL;
