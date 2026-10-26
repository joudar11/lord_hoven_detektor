# Import docházky

Admin → **Import docházky z turniketů**. Ve formuláři zvolíš **datum**, **turniket** a vložíš text z exportu čtečky. Aplikace očekává opakující se trojice řádků:

```
in
Jan Novák (jnovak)
07:42
out
Jan Novák (jnovak)
16:05
```

- 1. řádek: směr `in` nebo `out` (na velikosti písmen nezáleží).
- 2. řádek: jméno; závorka s přihlašovacím jménem se odstraní, mezery se sjednotí. Aliasy jmen se uplatní hned (viz Admin → **Aliasy jmen**).
- 3. řádek: čas `H:MM` nebo `HH:MM`. Datum se bere z formuláře.
- Prázdné řádky se ignorují; řádky, které nezapadnou do trojice, se spočítají jako neplatné.

## Kontrola před uložením
Než se cokoli uloží, aplikace zkontroluje, jestli:
- **stejný záznam není u jiného turniketu** (osoba, směr a čas se shodují; typicky špatně zvolený turniket),
- **žádný čas není v budoucnosti**.

Při nálezu se **nic neuloží** a zobrazí se úplný výpis problémů s volbami **Importovat bez problematických**, **Importovat vše přesto** a **Zrušit**. Rozhodnutí se zapíše do záznamu akcí.

Duplicitní řádky (stejná osoba, směr, turniket a čas) databáze přeskočí díky jedinečnému klíči `uq_passes`, takže se stejný text dá bezpečně vložit znovu.

## Turnikety
Čtyři hodnoty: `vlevo`, `vpravo`, `patro_1` (1. patro) a `it_dvere` (čtečka na dveřích IT, 4. patro). Ke zdrojům bodů se přiřazují takto: **Nahoře (4. patro)** = `vlevo`, `vpravo` a `it_dvere`, **Dole (1. patro)** = `patro_1`. Podrobnosti o výpočtu: [ARCHITECTURE.md](ARCHITECTURE.md).
