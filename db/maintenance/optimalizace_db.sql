-- RUČNÍ NÁVOD (ne migrace): jednorázová optimalizace tabulek průchodů a hlášení. Obsahuje mazání duplicit, proto ho spouštěj krok po kroku a po záloze.
-- =====================================================================
-- OPTIMALIZACE DB – spouštět v phpMyAdminu postupně, KROK PO KROKU
-- Před začátkem si udělejte export (zálohu) obou tabulek.
-- =====================================================================

-- 0) Zjistěte skutečnou strukturu a stávající indexy
SHOW CREATE TABLE turnstile_logs;
SHOW CREATE TABLE toilet_reports;
SHOW INDEX FROM turnstile_logs;
SHOW INDEX FROM toilet_reports;

-- 1) Kolik je duplicit v průchodech (0 = můžete rovnou na krok 3)
SELECT COUNT(*) AS duplicitnich_skupin FROM (
    SELECT 1 FROM turnstile_logs
    GROUP BY person_name, direction, turnstile, passed_at
    HAVING COUNT(*) > 1
) d;

-- 2) Odstranění duplicit (jen pokud turnstile_logs má sloupec `id` jako PRIMARY KEY
--    a krok 1 vrátil víc než 0; ponechá vždy nejstarší řádek)
DELETE t1 FROM turnstile_logs t1
JOIN turnstile_logs t2
  ON  t1.person_name = t2.person_name
  AND t1.direction   = t2.direction
  AND t1.turnstile   = t2.turnstile
  AND t1.passed_at   = t2.passed_at
  AND t1.id > t2.id;

-- 3) Hlavní index průchodů: passed_at je první, takže index slouží zároveň
--    pro rozsahový dotaz (BETWEEN) i jako pokrývající index a jako ochrana proti duplicitám.
ALTER TABLE turnstile_logs
    ADD UNIQUE KEY uq_passes (passed_at, person_name, direction, turnstile);

-- 4) Indexy pro hlášení
ALTER TABLE toilet_reports
    ADD INDEX idx_cubicle_time (cubicle, reported_at),
    ADD INDEX idx_reported_at (reported_at);
