# Bezpečnost

## Hlášení zranitelnosti
Zranitelnost nehlas veřejně v issue. Použij **soukromé hlášení** na GitHubu (záložka *Security → Report a vulnerability*). Napiš, co jsi našel, jak to zopakovat
a jakou verzi (`Verze:` v hlavičce `app/index.php`). Na žádné skutečné osobní údaje v hlášení nepoužívej odkazy ani přílohy.

## Podporované verze
Opravy dostává poslední vydaná verze.

## Model a doporučení
Popis bezpečnostního modelu je v [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) a kontrolní seznam před ostrým provozem v [docs/INSTALL.md](docs/INSTALL.md).
Nejdůležitější: aplikace věří jen reverzní proxy, proto proxy musí přepisovat `Remote-User`, `Remote-Name` a `X-Forwarded-For` a přímý přístup mimo proxy musí být zablokovaný.
