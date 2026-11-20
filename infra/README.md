# SQL-Proctor local infrastructure

This directory holds the local development infrastructure for SQL-Proctor. It contains Docker Compose configuration and its documentation only. No application code lives here (DEC-0042).

## Services

| Service | Role | Image | Host address |
|---|---|---|---|
| `postgres` | The application database. Only the Laravel API reads and writes it. | `postgres:17.12-trixie` | `127.0.0.1:5432` |
| `mariadb` | The student-query execution engine. It is **never** the application database. | `mariadb:11.8.9` | `127.0.0.1:3306` |

Both services bind to the loopback address only. They do not accept connections from the local network.

Data lives in the named volumes `sql-proctor_postgres_data` and `sql-proctor_mariadb_data`. This project's Compose name is `sql-proctor`, so Compose prefixes the volume names with it. The data survives `docker compose down` and a container restart.

MariaDB is the student-query execution engine and never the application database. The Laravel API must not store application data in MariaDB.

The applications are not containerized at this stage. Laravel and Next.js run locally and connect to these services on `127.0.0.1`.

## Setup

1. Copy the example file. Run this from `infra/`:

   ```sh
   cp .env.example .env
   ```

2. Replace every placeholder in `.env` with a local development value. Use URL-safe characters or hexadecimal values. Do not reuse a real or production credential.
3. Limit the file to your user account:

   ```sh
   chmod 600 .env
   ```

The Compose file reads `.env` automatically from this directory. The file is gitignored. Never commit it, print it, or copy its values into a record.

## PHP and Composer

The XAMPP installation puts `/Applications/XAMPP/xamppfiles/bin` first in `PATH`, so `php` resolves to XAMPP PHP 8.2.4 with the default shell setup. Homebrew PHP is installed but comes later in `PATH`.

Put Homebrew first in the shell that runs Laravel or Composer:

```sh
PATH="/opt/homebrew/bin:$PATH"
```

Verify the change before you run a project command:

```sh
php -v
php -m | grep -x pdo_pgsql
composer --version
```

`php -v` must show PHP 8.5.x, the second command must print `pdo_pgsql`, and `composer --version` must report Composer 2.x with the Homebrew PHP path. Do not edit the shell profile or the XAMPP installation.

## Commands

Run every command from `infra/`.

| Action | Command |
|---|---|
| Start | `docker compose up -d` |
| Status | `docker compose ps` |
| Logs (follow) | `docker compose logs -f` |
| Logs (last 100 lines, no follow) | `docker compose logs --tail 100` |
| Restart | `docker compose restart` |
| Stop (containers only; network and volumes kept) | `docker compose stop` |
| Teardown (containers and network, volumes kept) | `docker compose down` |

Caution: `docker compose down -v` is destructive. It deletes the named volumes, so PostgreSQL and MariaDB start empty on the next `up`. Run it only when the Human Owner approved the data loss.

Startup after `stop`: `docker compose start`. Startup after `down`: `docker compose up -d`.

## Verify

```sh
docker compose ps
docker compose exec -T postgres sh -c 'pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
docker compose exec -T mariadb healthcheck.sh --connect --innodb_initialized
```

`docker compose ps` shows `healthy` for both services when their health checks pass. The first start needs a few seconds. Follow startup with `docker compose logs -f` when a service does not become healthy.

## Notes

- Image tags are pinned. Change a tag only with a separate review.
- This stack adds no Redis, queue, or mail service. Add a service only when a later task requires it and the Human Owner authorizes it.
- Do not add initialization scripts under `infra/docker/` yet. The current databases start empty by design.
