# SQL-Proctor Architecture

> Status: Updated for SPEC-0003 (API response/pagination foundation) and SPEC-0004 (Authentication, Authorization, Student Sessions, and Audit Logging) on 2026-11-28. Exam authoring, SQL execution, and grading remain `TBD`.

SQL-Proctor is a web-based SQL practical examination system. This document describes the components, the runtime topology, and the boundaries between them. It records what exists. Planned behavior is marked `TBD` or `Proposed`.

## Components

| Component | Technology | Location | Role |
|---|---|---|---|
| Frontend | Next.js 16.4.6, React 19.3.0, JavaScript, App Router, Tailwind CSS v4 | `frontend/sql-proctor-ui/` | The browser application. Sends HTTP requests + cookies to the Laravel API. |
| Backend | Laravel 13.43.0, PHP 8.5.12, Laravel Sanctum 4.5.0 | `backend/sql-proctor-api/` | The API. Owns application data, authentication, RBAC, and audit logging. |
| Application database | PostgreSQL 17.12 | `infra/compose.yaml`, service `postgres` | Stores `users`, `classrooms`, `student_sessions`, `audit_logs`, and sessions. Only Laravel connects. |
| Execution database | MariaDB 11.8.9 | `infra/compose.yaml`, service `mariadb` | Runs student SQL queries in a future phase. Never stores application or audit data. |
| Infrastructure | Docker Compose | `infra/` | Starts and manages the two databases. Holds no application code. |
| Local evidence | Markdown | `.agent-runtime/` | Development run records. Gitignored and never committed. |
| Agent engineering | Markdown skills, `AGENTS.md`, `skills-lock.json` | `.agents/skills/`, project root | Development guidance for the coding agent. No runtime role. |

## Agent engineering layer

The agent engineering layer is development guidance. It is not imported by either application and it does not change a runtime boundary.

| Item | Location | Role |
|---|---|---|
| Project rules | `AGENTS.md` | Always-loaded rules, architecture boundaries, workflows, and the skill-loading map |
| Project skills | `.agents/skills/<id>/SKILL.md` | Installed and custom skills, loaded by the coding agent when a task needs them |
| Skill lock | `skills-lock.json` | Source, commit, path, hash, and install mode for each external skill |
| Inventory | `docs/agent-engineering.md` | Sources, capability matrix, rejected candidates, compatibility, maintenance |
| Templates and records | `docs/templates/`, `docs/specs/`, `docs/adr/` | Specifications, plans, test matrices, reviews, and decisions |

The SQL execution isolation skill is the security gate for the MariaDB execution feature. It does not run SQL; it forces the design, privilege, limit, cleanup, and verification decisions before that feature is built.

## Runtime topology

```text
Browser
  |
  | HTTP (Sanctum SPA cookies + XSRF-TOKEN + student_session_id)
  v
Next.js  localhost:3000
  |
  | Axios, NEXT_PUBLIC_API_URL=http://localhost:8000/api (withCredentials, withXSRFToken)
  v
Laravel  localhost:8000
  |
  | PDO, pgsql
  v
PostgreSQL 127.0.0.1:5432
  (users, classrooms, student_sessions, append-only audit_logs, sessions)

MariaDB 127.0.0.1:3306
  (student query execution; no application or audit schema)

Docker Compose in `infra/` owns PostgreSQL and MariaDB.
```

The applications run locally at this stage. Only the databases are containerized.

## Request flow

1. The browser loads the Next.js application on `http://localhost:3000`.
2. Client components call `ensureCsrfCookie()` (`GET /sanctum/csrf-cookie`) before mutating auth/session requests through the shared Axios instance in `frontend/sql-proctor-ui/lib/api.js`.
3. `AssignRequestId` resolves or generates `X-Request-Id` for every `/api/*` request.
4. Laravel validates requests, enforces RBAC (`auth:sanctum`, `EnsureUserIsActive`, `EnsureRole`, Policies) or student session boundaries (`VerifyStudentSession`), writes append-only records via `AuditLogger`, and persists to PostgreSQL on port 5432.
5. Laravel returns JSON in the `SPEC-0003` envelope. The frontend renders it.

MariaDB is not in this request path. It becomes reachable when the exam execution feature is built.

## Ports

| Port | Service | Binding |
|---|---|---|
| 3000 | Next.js dev server | `localhost` |
| 8000 | Laravel dev server | `localhost` / `127.0.0.1` |
| 5432 | PostgreSQL | `127.0.0.1` (published by Docker) |
| 3306 | MariaDB | `127.0.0.1` (published by Docker) |

Port 3306 can conflict with a local XAMPP MySQL installation. Set `MARIADB_PORT` in `infra/.env` to a free port, or stop XAMPP MySQL, before `docker compose up -d`.

## Configuration

| Variable | Where | Purpose |
|---|---|---|
| `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD`, `POSTGRES_PORT` | `infra/.env` | PostgreSQL container and host mapping. |
| `MARIADB_DATABASE`, `MARIADB_USER`, `MARIADB_PASSWORD`, `MARIADB_ROOT_PASSWORD`, `MARIADB_PORT` | `infra/.env` | MariaDB container and host mapping. |
| `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `backend/sql-proctor-api/.env` | Laravel to PostgreSQL (`sql_proctor`; `phpunit.xml` targets `sql_proctor_test`). |
| `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN` | `backend/sql-proctor-api/.env` | Set to `http://localhost:3000`, `localhost:3000`, and `localhost` respectively. |
| `NEXT_PUBLIC_API_URL` | `frontend/sql-proctor-ui/.env.local` | Axios base URL (`http://localhost:8000/api`). |

Every `.env` file is gitignored. `.env.example` files contain placeholders only. No credential is stored in a tracked file, a document, or a record.

## Boundaries

1. Frontend and backend are separate applications with separate dependency trees and lockfiles.
2. `infra/` holds infrastructure configuration only. No application code lives there.
3. PostgreSQL is the only application database and owns `audit_logs`. MariaDB is the student-query execution engine and never stores application or audit data.
4. The frontend never connects to a database directly. It calls the API.
5. The applications are not containerized at this stage. A later task can add them and the Human Owner approves it.
6. No service exists beyond PostgreSQL and MariaDB. Redis, queues, and mail services are added only when a feature requires them.
7. Agent skills and engineering documents are development guidance. They are not bundled into the Next.js or Laravel applications and do not change a runtime boundary.

## Authentication & Authorization

Two strictly isolated cookie mechanisms operate under [ADR-0002](adr/0002-auth-session-boundaries-and-audit-isolation.md):

- **Staff (`admin`, `examiner`):** Sanctum SPA stateful cookie authentication (`web` guard backed by PostgreSQL `sessions`), `SANCTUM_STATEFUL_DOMAINS=localhost:3000`, `SESSION_DOMAIN=localhost`, `http_only=true`, `same_site=lax`, CORS restricted to `http://localhost:3000` with `supports_credentials=true`, login rate limiting (`5/min`), and `EnsureUserIsActive` + `EnsureRole` + Policies (`UserPolicy`, `ClassroomPolicy`, `AuditLogPolicy`). Initial admin created idempotently via `php artisan app:bootstrap-admin`.
- **Students (`student`):** Kahoot-style passwordless entry via `display_name` + `class_code` (`POST /api/student/join`) with zero `users` rows, issuing a signed, encrypted, HTTP-only, `SameSite=Lax` cookie (`student_session_id`) verified by `VerifyStudentSession` and protected by `EnsureStatefulAuthSession` CSRF/cookie middleware.

## Startup order

1. `cd infra && docker compose up -d`, then wait for both services to report `healthy`.
2. `cd backend/sql-proctor-api && PATH="/opt/homebrew/bin:$PATH" php artisan migrate && php artisan app:bootstrap-admin && php artisan serve`.
3. `cd frontend/sql-proctor-ui && npm run dev`.

The backend needs PostgreSQL only. The frontend needs the backend when calling `/api/*` or `/sanctum/csrf-cookie`.

## Deliberately absent

- Exam authoring, exam attempts, SQL execution against MariaDB, grading, and reporting.
- Self-service staff registration, password reset emails, Bearer token login, 2FA, and SSO.
- Redis, external mail delivery, and production deployment.

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
| Tailwind CSS | `^4.3.5` |
| Vitest | `^5.1.2` |
| Playwright | `^1.65.1` (`@playwright/test`) |
| Axios | 1.21.1 |
| `@monaco-editor/react` | 4.7.0 (Monaco 0.57.0) |
| PostgreSQL | 17.12 |
| MariaDB (pinned image tag; runtime check pending) | 11.8.9 |
| Docker | 29.8.0 |
| Docker Compose | 5.5.1 |
