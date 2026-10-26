# Detektor Lorda Hoven

A small self-hosted PHP web application that **statistically correlates turnstile passes with the time windows of incidents**
(a toilet cubicle reported as "ruined"). It started as an office in-joke and grew into a complete little analysis tool:
status reports from colleagues define the time window of every incident, imported turnstile logs give a ranking of
who was in the building at the right time, and a timeline, statistics and a weekly e-mail digest sit on top.

> **The user interface and the documentation are in Czech.** The English summary below is only an overview.
> Full documentation: [README.cs.md](README.cs.md) and the [docs](docs/) folder.

**Read this before you use it on real people.** The application processes personal data of employees (names and times of
entering the building) and ranks named people as "suspects". The result is a *statistical hint, not proof*. Using it in a
workplace needs a legal basis, transparency towards the people involved and, depending on your country, consultation with the
employer, data protection officer or works council. See [docs/PRIVACY.md](docs/PRIVACY.md). The author gives no legal advice.

## Features

- Status reports of cubicles (OK / ruined) define incident windows; a configurable tolerance extends them.
- Suspect ranking from imported turnstile logs (two turnstile groups, IT "virtual" points, witness sightings, informational sources) with filters.
- Innocent and trusted lists, name aliases, excluded persons, labels and milestones.
- Timeline of all events (`vizualizace.php`), statistics and forecast, history of reports, photo documentation.
- **Read-only preview address with pseudonymised names** and per-section switches for what the preview shows.
- Weekly e-mail digest, backups for a home server (cron scripts), admin action log, optional data retention.
- Hardened: CSRF tokens, CSP with nonces, secure session cookies, hashed admin password, trusted-proxy model.

## Requirements

PHP 8.1+ (tested on 8.3) with `pdo_mysql` and `openssl`; MariaDB 10.4+ (tested on 10.11); a web server **behind a reverse proxy** that
adds a secret header (see [docs/REVERSE_PROXY.md](docs/REVERSE_PROXY.md)). No Composer, no build step, no external CDN.

## Quick try (demo data, no real data involved)

```bash
bash tests/env.sh up demo          # throw-away MariaDB + PHP servers + fictional demo data
# open http://127.0.0.1:8081/ (full version), http://127.0.0.1:8092/ (read-only preview)
bash tests/env.sh down
```

Install for real: [docs/INSTALL.md](docs/INSTALL.md). Configuration: [docs/CONFIGURATION.md](docs/CONFIGURATION.md).

## Tests

`bash tests/run_tests.sh` runs about 130 server-side checks plus 20 browser checks (the browser test is optional, see [tests/README.md](tests/README.md)).

## License

MIT, see [LICENSE](LICENSE) and [NOTICE.md](NOTICE.md) for third-party components (Bootstrap, Feather icons).
