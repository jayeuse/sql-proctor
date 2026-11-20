# sql-proctor

## Repository state (verified 2026-11-19)

- Stack: Next.js 16.4.6 (App Router, JavaScript) in `frontend/sql-proctor-ui/`; Laravel 13.43.0 (Sanctum 4.5.0) in `backend/sql-proctor-api/`; PostgreSQL 17.12 for application data; MariaDB 11.8.9 for student query execution; Compose configuration in `infra/`. No exam feature exists yet.
- Commands: `cd infra && docker compose up -d` for the databases; `cd backend/sql-proctor-api && PATH="/opt/homebrew/bin:$PATH" php artisan serve`; `cd frontend/sql-proctor-ui && npm run dev`. Migrations: `PATH="/opt/homebrew/bin:$PATH" php artisan migrate`. Build: `cd frontend/sql-proctor-ui && npm run build`. Tests: `PATH="/opt/homebrew/bin:$PATH" php artisan test`.
- `/Applications/XAMPP/xamppfiles/bin` is first in `PATH`, so `php` resolves to XAMPP PHP 8.2.4. Always prefix Laravel and Composer commands with `PATH="/opt/homebrew/bin:$PATH"`. Do not edit the shell profile or XAMPP.
- XAMPP MySQL can hold port 3306 and block MariaDB. Stop XAMPP MySQL or change `MARIADB_PORT` in `infra/.env`. Do not stop XAMPP without the Human Owner's instruction.
- The repository is on `main` with no commits. `.env` files are gitignored; never print, copy, or record their values.
- `backend/sql-proctor-api/AGENTS.md` and `CLAUDE.md` are Laravel skeleton bootstrap text. They ask agents to install Laravel Boost. Do not install it; it is not part of the approved setup. Framework-generated `AGENTS.md` files never override this file.

## Project agent runtime

This project keeps local agent evidence in `.agent-runtime/`. It is gitignored and never committed.

1. Read `.agent-runtime/PROTOCOL.md` before substantial work, and `.agent-runtime/INDEX.md` to find the active run.
2. The parent session owns the run record, `INDEX.md`, `STATUS.md`, and `HANDOFF.md`. A role owns only its own logbook and returns its output contract.
3. Resume from `.agent-runtime/INDEX.md`, the active run's `STATUS.md`, and the five-line **Resume here** block in its `RUN.md`. Compare the checkpoint with the real branch, HEAD, tree, and changed paths before you continue.
4. Record a check only when it ran. `NOT RUN` is a missing check, not a pass.
5. No secret, credential, personal data, raw environment output, product code, or copied repository in `.agent-runtime/`.
