# SQL-Proctor Architecture

> Status: Current for the development environment built on 2026-11-19. Product features are not implemented.

SQL-Proctor is a web-based SQL practical examination system. This document describes the components, the runtime topology, and the boundaries between them. It records what exists. Planned behavior is marked `TBD` or `Proposed`.

## Components

| Component | Technology | Location | Role |
|---|---|---|---|
| Frontend | Next.js 16.4.6, React 19.3.0, JavaScript, App Router | `frontend/sql-proctor-ui/` | The browser application. Sends HTTP requests to the Laravel API. |
| Backend | Laravel 13.43.0, PHP 8.5.12, Laravel Sanctum 4.5.0 | `backend/sql-proctor-api/` | The API. Owns application data and authentication. |
| Application database | PostgreSQL 17.12 | `infra/compose.yaml`, service `postgres` | Stores application data. Only the Laravel API reads and writes it. |
| Execution database | MariaDB 11.8.9 | `infra/compose.yaml`, service `mariadb` | Runs student SQL queries. Never stores application data. |
| Infrastructure | Docker Compose | `infra/` | Starts and manages the two databases. Holds no application code. |
| Local evidence | Markdown | `.agent-runtime/` | Development run records. Gitignored and never committed. |

## Runtime topology

```text
Browser
  |
  | HTTP
  v
Next.js  localhost:3000
  |
  | Axios, NEXT_PUBLIC_API_URL=http://localhost:8000/api
  v
Laravel  localhost:8000
  |
  | PDO, pgsql
  v
PostgreSQL 127.0.0.1:5432
  (application data)

MariaDB 127.0.0.1:3306
  (student query execution; no application schema)

Docker Compose in `infra/` owns PostgreSQL and MariaDB.
```

The applications run locally at this stage. Only the databases are containerized.

## Request flow

1. The browser loads the Next.js application on port 3000.
2. A client component uses the single Axios instance in `frontend/sql-proctor-ui/lib/api.js`.
3. The instance reads `NEXT_PUBLIC_API_URL`, defaulting to `http://localhost:8000/api`, and sends cookie credentials.
4. Laravel handles the route under `/api/*`.
5. Controllers (when they exist) use Eloquent against PostgreSQL on port 5432.
6. Laravel returns JSON. The frontend renders it.

MariaDB is not in this request path. It becomes reachable when the exam execution feature is built.

## Ports

| Port | Service | Binding |
|---|---|---|
| 3000 | Next.js dev server | `localhost` |
| 8000 | Laravel dev server | `127.0.0.1` |
| 5432 | PostgreSQL | `127.0.0.1` (published by Docker) |
| 3306 | MariaDB | `127.0.0.1` (published by Docker) |

Port 3306 can conflict with a local XAMPP MySQL installation. Set `MARIADB_PORT` in `infra/.env` to a free port, or stop XAMPP MySQL, before `docker compose up -d`.

## Configuration

| Variable | Where | Purpose |
|---|---|---|
| `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD`, `POSTGRES_PORT` | `infra/.env` | PostgreSQL container and host mapping. |
| `MARIADB_DATABASE`, `MARIADB_USER`, `MARIADB_PASSWORD`, `MARIADB_ROOT_PASSWORD`, `MARIADB_PORT` | `infra/.env` | MariaDB container and host mapping. |
| `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `backend/sql-proctor-api/.env` | Laravel to PostgreSQL. |
| `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS` | `backend/sql-proctor-api/.env` | Sanctum stateful domains for the local SPA. |
| `NEXT_PUBLIC_API_URL` | `frontend/sql-proctor-ui/.env.local` | Axios base URL. |

Every `.env` file is gitignored. `.env.example` files contain placeholders only. No credential is stored in a tracked file, a document, or a record.

## Boundaries

1. Frontend and backend are separate applications with separate dependency trees and lockfiles.
2. `infra/` holds infrastructure configuration only. No application code lives there.
3. PostgreSQL is the only application database. MariaDB is the student-query execution engine and never stores application data.
4. The frontend never connects to a database directly. It calls the API.
5. The applications are not containerized at this stage. A later task can add them and the Human Owner approves it.
6. No service exists beyond PostgreSQL and MariaDB. Redis, queues, and mail services are added only when a feature requires them.

## Authentication

Laravel Sanctum 4.5.0 is installed and prepared:

- `personal_access_tokens` table migrated on PostgreSQL.
- `User` uses `Laravel\Sanctum\HasApiTokens`.
- `$middleware->statefulApi()` is enabled in `bootstrap/app.php`.
- `SANCTUM_STATEFUL_DOMAINS` includes `localhost:3000` and `127.0.0.1:3000`.
- CORS allows `http://localhost:3000` and `http://127.0.0.1:3000` with credentials.

No registration, login, or logout endpoint exists. Those are application features and are not part of this setup. `GET /api/user` exists as Laravel's API scaffold and returns `401` until authentication is implemented.

## Startup order

1. `cd infra && docker compose up -d`, then wait for both services to report `healthy`.
2. `cd backend/sql-proctor-api && PATH="/opt/homebrew/bin:$PATH" php artisan serve`.
3. `cd frontend/sql-proctor-ui && npm run dev`.

The backend needs PostgreSQL only. The frontend needs the backend only when it calls the API.

## Deliberately absent

- Exam authoring, exam attempts, SQL execution, grading, and reporting.
- Authentication endpoints and user management.
- Frontend tests. `Vitest` is named in the stack but no test runner is installed yet.
- Redis, queues, mail delivery, and CI configuration.
- Production deployment.

## Verified versions

| Tool | Version |
|---|---|
| PHP | 8.5.12 (Homebrew) |
| Composer | 2.11.2 |
| Laravel | 13.43.0 |
| Laravel Sanctum | 4.5.0 |
| Node.js | 26.8.1 |
| npm | 11.19.0 |
| Next.js | 16.4.6 |
| React | 19.3.0 |
| Axios | 1.21.1 |
| `@monaco-editor/react` | 4.7.0 (Monaco 0.57.0) |
| PostgreSQL | 17.12 |
| MariaDB (pinned image tag; runtime check pending) | 11.8.9 |
| Docker | 29.8.0 |
| Docker Compose | 5.5.1 |
