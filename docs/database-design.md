# SQL-Proctor Database Design

> Status: Current for the schema created on 2026-11-19. Exam tables and the MariaDB execution schema are not built.

SQL-Proctor uses two databases with different jobs:

| Database | Engine | Role | Application data |
|---|---|---|---|
| `sql_proctor` | PostgreSQL 17.12 | Application database | Yes |
| `sql_exec` | MariaDB 11.8.9 | Student SQL query execution | No |

The separation is a hard rule. It is recorded as DEC-0042 in the company workspace, with approval record APR-0013. A student query must never run with access to application tables.

## PostgreSQL: `sql_proctor`

Connection: `127.0.0.1:5432`, user `sql_proctor`, from `backend/sql-proctor-api/.env`. Only the Laravel API connects.

### Tables that exist

| Table | Purpose | Key columns |
|---|---|---|
| `users` | Application accounts | `id`, `name`, `email` (unique), `email_verified_at`, `password`, `remember_token`, timestamps |
| `password_reset_tokens` | Password reset requests | `email` (primary), `token`, `created_at` |
| `sessions` | Database session store | `id` (primary), `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity` |
| `personal_access_tokens` | Sanctum API tokens (polymorphic) | `id`, `tokenable_type`, `tokenable_id`, `name`, `token` (unique hash), `abilities`, `last_used_at`, `expires_at` |
| `cache`, `cache_locks` | Database cache store | `key` (primary), `value`, `expiration`; lock `owner` |
| `jobs`, `job_batches`, `failed_jobs` | Database queue tables | Standard Laravel queue columns |
| `migrations` | Migration history | `id`, `migration`, `batch` |

`users`, `password_reset_tokens`, and `sessions` come from `0001_01_01_000000_create_users_table`. The cache and queue tables come from the next two default migrations. `personal_access_tokens` comes from Laravel Sanctum.

### Relationships

- `personal_access_tokens.tokenable_type` + `tokenable_id` point to `users.id` for user tokens. The relation is polymorphic.
- `sessions.user_id` references `users.id` logically. The default migration does not add a foreign key.
- `password_reset_tokens.email` matches `users.email`. It has no foreign key.

### Not built

The exam domain has no tables yet. Each area is `TBD` until a feature task defines it:

- Exam definition, sections, questions, and answer options.
- Student attempts, answers, and timing.
- SQL tasks, expected results, and grading rules.
- Results, scores, and audit history.
- Role and permission storage beyond Laravel's default `users` table.

Do not add a table to MariaDB for any of these. They belong in PostgreSQL.

### Commands

Run from `backend/sql-proctor-api/` with `PATH="/opt/homebrew/bin:$PATH"`:

```sh
php artisan migrate            # apply pending migrations
php artisan migrate:status     # show applied migrations
php artisan migrate:fresh      # DESTRUCTIVE: drop all tables and re-migrate
```

`migrate:fresh` and `migrate:rollback` destroy application data. Treat them as destructive and use them only on local data.

## MariaDB: `sql_exec`

Connection: `127.0.0.1:3306`, database `sql_exec`, application user `sql_runner`. The password is in `infra/.env`.

### Tables that exist

None. The database is created empty by the container on its first start. The image has never started on this machine because XAMPP MySQL holds the host port.

### Purpose and constraints

MariaDB runs the SQL that a student writes during an exam. It is not an application store. The following are design constraints, not built behavior:

1. A student query must run in an isolated scope. The isolation mechanism is `TBD`. Options are one schema per attempt, one database per attempt, or a restricted server instance. The choice needs a technical design and the Human Owner's approval because it changes a schema and access boundary.
2. The execution user must have only the privileges the queries need. Whether that is read-only, read-write on a disposable schema, or limited DDL is `TBD`.
3. Each attempt needs a deterministic setup and cleanup. The seed data source and the cleanup trigger are `TBD`.
4. Query time and result size must be bounded. The limits are `TBD`.
5. MariaDB must never receive application credentials, application tables, or copies of application data.

### Commands

From `infra/`:

```sh
docker compose exec mariadb sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb -u"$MARIADB_USER" "$MARIADB_DATABASE"'
```

The container expands the variables. Do not print the password. A host client can connect to `127.0.0.1:3306` when the port is published and XAMPP MySQL is not using it.

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
- `infra/README.md`
