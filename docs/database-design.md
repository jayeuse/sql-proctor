# SQL-Proctor Database Design

> Status: Updated for SPEC-0004 (Authentication, Authorization, Classrooms, Student Sessions, and Audit Logging) on 2026-11-28. Exam task/attempt tables and the MariaDB execution schema are not built yet.

SQL-Proctor uses two databases with different jobs:

| Database | Engine | Role | Application data |
|---|---|---|---|
| `sql_proctor` (and `sql_proctor_test`) | PostgreSQL 17.12 | Application database | Yes |
| `sql_exec` | MariaDB 11.8.9 | Student SQL query execution | No |

The separation is a hard rule (`AGENTS.md`, [ADR-0002](adr/0002-auth-session-boundaries-and-audit-isolation.md)). A student query must never run with access to application or audit tables.

## PostgreSQL: `sql_proctor`

Connection: `127.0.0.1:5432`, user `sql_proctor`, from `backend/sql-proctor-api/.env` (and `sql_proctor_test` via `phpunit.xml`). Only the Laravel API connects.

### Tables

| Table | Purpose | Key columns & constraints |
|---|---|---|
| `users` | Staff accounts (`admin`, `examiner`) | `id` (bigint PK), `username` (varchar(64) unique, lowercase `CHECK`), `name` (nullable), `email` (nullable unique), `email_verified_at`, `password`, `role` (`'admin' \| 'examiner'`, indexed, `CHECK`), `is_active` (boolean default `true`, indexed), `last_login_at` (nullable timestamp), `remember_token`, timestamps |
| `classrooms` | Examiner-managed class entry codes | `id` (uuid PK), `examiner_id` (FK -> `users.id`, restrict delete, indexed), `name` (varchar(120)), `join_code` (varchar(16) unique), `is_open` (boolean default `true`), `code_expires_at` (nullable timestamp), timestamps; index `(is_open, code_expires_at)` |
| `student_sessions` | Kahoot-style student sessions (no `users` row) | `id` (uuid PK), `classroom_id` (FK -> `classrooms.id`, cascade delete), `display_name` (varchar(64)), `normalized_display_name` (varchar(64)), `ip_address` (`inet` nullable), `user_agent` (`text` nullable), `joined_at` (timestamp), `last_seen_at` (timestamp), `expires_at` (timestamp), `revoked_at` (nullable timestamp); partial unique index `(classroom_id, normalized_display_name) WHERE revoked_at IS NULL`; index `(classroom_id, expires_at)` |
| `audit_logs` | Immutable append-only security & domain audit log | `id` (uuid PK), `actor_type` (`admin \| examiner \| student \| system \| anonymous`), `actor_id` (varchar(64) nullable), `actor_label` (varchar(120) nullable), `event_type` (varchar(80)), `target_type` (varchar(64) nullable), `target_id` (varchar(64) nullable), `metadata` (`jsonb` default `'{}'`), `ip_address` (`inet` nullable), `user_agent` (`text` nullable), `request_id` (`uuid` nullable), `created_at` (timestamp, no `updated_at`); indexes `(actor_type, actor_id, created_at)`, `(event_type, created_at)`, `(target_type, target_id)`, `(created_at)`; PostgreSQL `BEFORE UPDATE OR DELETE` trigger `audit_logs_prevent_update_delete` |
| `password_reset_tokens` | Laravel default password reset table (unused in Phase 1) | `email` (primary), `token`, `created_at` |
| `sessions` | Database session store for staff (`web` guard) | `id` (primary), `user_id` (indexed), `ip_address`, `user_agent`, `payload`, `last_activity` |
| `personal_access_tokens` | Sanctum API tokens (polymorphic; unused for SPA cookie login) | `id`, `tokenable_type`, `tokenable_id`, `name`, `token` (unique hash), `abilities`, `last_used_at`, `expires_at` |
| `cache`, `cache_locks` | Database cache & rate-limit store | `key` (primary), `value`, `expiration`; lock `owner` |
| `jobs`, `job_batches`, `failed_jobs` | Database queue tables | Standard Laravel queue columns |
| `migrations` | Migration history | `id`, `migration`, `batch` |

### Relationships & integrity rules

- `classrooms.examiner_id` references `users.id` (`ON DELETE RESTRICT`). Staff accounts are deactivated (`is_active = false`), not deleted.
- `student_sessions.classroom_id` references `classrooms.id` (`ON DELETE CASCADE`). Active display names are unique case-insensitively per classroom (`WHERE revoked_at IS NULL`).
- `audit_logs` has no foreign keys to `users`, `classrooms`, or `student_sessions` so historical snapshots (`actor_label`, `actor_id`, `target_id`) survive independently and `audit_logs` stays strictly append-only.
- `audit_logs` enforces append-only integrity via both Eloquent model hooks and the PostgreSQL `prevent_audit_logs_mutation()` trigger on `BEFORE UPDATE OR DELETE`.
- `sessions.user_id` is purged immediately when an admin deactivates an examiner or resets an examiner's password.

### Not built

The exam domain tables remain `TBD` until later feature phases:

- Exam definitions, sections, questions, and expected results.
- Student exam attempts, SQL submissions, and grading scores.

Do not add any of these tables or `audit_logs` to MariaDB. They belong exclusively in PostgreSQL.

### Commands

Run from `backend/sql-proctor-api/` with `PATH="/opt/homebrew/bin:$PATH"`:

```sh
php artisan migrate            # apply pending migrations
php artisan migrate:status     # show applied migrations
php artisan app:bootstrap-admin # create initial active admin idempotently
php artisan migrate:fresh      # DESTRUCTIVE: drop all tables and re-migrate (local only)
```

## MariaDB: `sql_exec`

Connection: `127.0.0.1:3306`, database `sql_exec`, application user `sql_runner`. The password is in `infra/.env`.

### Tables that exist

None. MariaDB is untouched in Phase 1 and stores no application, authentication, session, or audit data.

### Purpose and constraints

MariaDB runs student exam SQL in a later phase (`sql-execution-isolation` gate):

1. A student query must run in an isolated scope (`TBD` pending technical design and Human Owner approval).
2. Least-privilege execution user (`TBD`).
3. Deterministic setup and cleanup (`TBD`).
4. Bounded time, rows, and connections (`TBD`).
5. MariaDB must never receive application credentials, application tables, student session data, or `audit_logs`.

## Storage and lifecycle

| Item | Value |
|---|---|
| PostgreSQL volume | `sql-proctor_postgres_data` |
| MariaDB volume | `sql-proctor_mariadb_data` |
| Survives `docker compose down` | Yes |
| Deleted by `docker compose down -v` | Yes. Destructive. |
| Local credentials | `infra/.env`, mode `600`, gitignored |
| Backups | None. Development data only. |

## Related documents

- [architecture.md](architecture.md)
- [api-specification.md](api-specification.md)
- [SPEC-0004](specs/SPEC-0004-auth-rbac-and-audit-logging.md)
- [ADR-0002](adr/0002-auth-session-boundaries-and-audit-isolation.md)
- `infra/README.md`
