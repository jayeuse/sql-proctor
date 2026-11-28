# SQL-Proctor Architecture

> Status: Updated for SPEC-0005 (Exam Authoring, Isolated MariaDB SQL Execution, Student Examination, and Grading) and ADR-0003 on 2026-11-28.

SQL-Proctor is a web-based SQL practical examination system. This document describes the components, the runtime topology, and the boundaries between them.

## Components

| Component | Technology | Location | Role |
|---|---|---|---|
| Frontend | Next.js 16.4.6, React 19.3.0, JavaScript, App Router, Tailwind CSS v4, Monaco Editor | `frontend/sql-proctor-ui/` | Browser UI for staff authoring/grading/oversight and student examination. Sends HTTP requests + cookies to Laravel API. |
| Backend | Laravel 13.43.0, PHP 8.5.12, Laravel Sanctum 4.5.0 | `backend/sql-proctor-api/` | API, business rules, exam versioning, MariaDB sandbox orchestration, outcome-based grading, RBAC, and audit logging. |
| Application database | PostgreSQL 17.12 | `infra/compose.yaml`, service `postgres` | Stores all application, exam, attempt, answer, query run, and `audit_logs` tables. Only Laravel connects. |
| Execution database | MariaDB 11.8.9 | `infra/compose.yaml`, service `mariadb` | Executes synthetic exam SQL inside ephemeral `sbx_<32-hex>` schemas. Never stores application or audit data. |
| Infrastructure | Docker Compose | `infra/` | Manages PostgreSQL and MariaDB containers and init scripts. Holds no application code. |
| Local evidence | Markdown | `.agent-runtime/` | Development run records. Gitignored and never committed. |
| Agent engineering | Markdown skills, `AGENTS.md`, `skills-lock.json` | `.agents/skills/`, project root | Development guidance for the coding agent. No runtime role. |

## Agent engineering layer

| Item | Location | Role |
|---|---|---|
| Project rules | `AGENTS.md` | Always-loaded rules, architecture boundaries, workflows, and the skill-loading map |
| Project skills | `.agents/skills/<id>/SKILL.md` | Installed and custom skills, loaded by the coding agent when a task needs them |
| Skill lock | `skills-lock.json` | Source, commit, path, hash, and install mode for each external skill |
| Inventory | `docs/agent-engineering.md` | Sources, capability matrix, rejected candidates, compatibility, maintenance |
| Templates and records | `docs/templates/`, `docs/specs/`, `docs/adr/` | Specifications, plans, test matrices, reviews, and decisions |

## Runtime topology

```text
Browser
  |
  | HTTP (Sanctum SPA cookies + XSRF-TOKEN + student_session_id)
  v
Next.js  localhost:3000 (with dynamic Monaco SqlEditor)
  |
  | Axios, NEXT_PUBLIC_API_URL=http://localhost:8000/api (withCredentials, withXSRFToken)
  v
Laravel  localhost:8000
  |
  +---> PDO (pgsql) ---> PostgreSQL 127.0.0.1:5432
  |                      (users, classrooms, student_sessions, sql_datasets, exams,
  |                       exam_questions, question_test_cases, question_options,
  |                       exam_versions, classroom_exams, exam_attempts, attempt_answers,
  |                       attempt_query_runs, append-only audit_logs, sessions)
  |
  +---> PDO (mariadb_provisioner + mariadb_runner) ---> MariaDB 127.0.0.1:3306 (or 3307 when XAMPP holds 3306)
                         (ephemeral sbx_<32-hex> schemas dropped in finally;
                          no application, credential, or audit schema)

Docker Compose in `infra/` owns PostgreSQL and MariaDB.
```

## Request flow

1. The browser loads the Next.js application on `http://localhost:3000`.
2. Client components call `ensureCsrfCookie()` (`GET /sanctum/csrf-cookie`) before mutating auth/session/exam requests through `frontend/sql-proctor-ui/lib/api.js`.
3. `AssignRequestId` resolves or generates `X-Request-Id` for every `/api/*` request.
4. Laravel validates requests, enforces RBAC (`auth:sanctum`, `EnsureUserIsActive`, `EnsureRole`, Policies) or student session ownership (`VerifyStudentSession`, `ExamAttemptPolicy`), and persists domain and audit records to PostgreSQL.
5. For SQL validation, live student query execution, and SQL grading, `SandboxManager` + `SqlExecutionService` provision an ephemeral `sbx_<32-hex>` schema via `mariadb_provisioner`, run bounded untrusted SQL via least-privilege `mariadb_runner` (`sql_runner`), and deterministically revoke grants and drop the schema inside `finally`.
6. Laravel returns JSON in the `SPEC-0003` envelope (with strict anti-leakage serializers on student endpoints).

## Ports

| Port | Service | Binding |
|---|---|---|
| 3000 | Next.js dev server | `localhost` |
| 8000 | Laravel dev server | `localhost` / `127.0.0.1` |
| 5432 | PostgreSQL | `127.0.0.1` (published by Docker) |
| 3306 (or `MARIADB_PORT`, e.g. `3307`) | MariaDB | `127.0.0.1` (published by Docker) |

Port `3306` conflicts when local XAMPP MySQL runs. Set `MARIADB_PORT=3307` in `infra/.env` and `backend/sql-proctor-api/.env` without stopping XAMPP.

## Configuration

| Variable | Where | Purpose |
|---|---|---|
| `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD`, `POSTGRES_PORT` | `infra/.env` | PostgreSQL container and host mapping. |
| `MARIADB_DATABASE`, `MARIADB_USER`, `MARIADB_PASSWORD`, `MARIADB_PROVISIONER_USER`, `MARIADB_PROVISIONER_PASSWORD`, `MARIADB_ROOT_PASSWORD`, `MARIADB_PORT` | `infra/.env` | MariaDB container, host port, and sandbox role credentials. |
| `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `backend/sql-proctor-api/.env` | Laravel to PostgreSQL (`sql_proctor`; `phpunit.xml` targets `sql_proctor_test`). |
| `MARIADB_HOST`, `MARIADB_PORT`, `MARIADB_PROVISIONER_USERNAME`, `MARIADB_PROVISIONER_PASSWORD`, `MARIADB_RUNNER_USERNAME`, `MARIADB_RUNNER_PASSWORD` | `backend/sql-proctor-api/.env` | Dual MariaDB connections (`mariadb_provisioner` and least-privilege `mariadb_runner`). |
| `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN` | `backend/sql-proctor-api/.env` | `http://localhost:3000`, `localhost:3000`, and `localhost`. |
| `NEXT_PUBLIC_API_URL` | `frontend/sql-proctor-ui/.env.local` | Axios base URL (`http://localhost:8000/api`). |

Every `.env` file is gitignored. `.env.example` files contain placeholders only. No credential is stored in a tracked file, a document, or a record.

## Boundaries

1. Frontend and backend are separate applications with separate dependency trees and lockfiles.
2. `infra/` holds infrastructure configuration and database init scripts only. No application code lives there.
3. PostgreSQL is the only application database and owns `audit_logs`, exams, attempts, answers, and grades. MariaDB runs ephemeral `sbx_<32-hex>` execution sandboxes only.
4. The frontend never connects to a database directly. It calls the Laravel API.
5. Agent skills and engineering documents are development guidance only.

## Authentication & Authorization

Two strictly isolated cookie mechanisms operate under [ADR-0002](adr/0002-auth-session-boundaries-and-audit-isolation.md):

- **Staff (`admin`, `examiner`):** Sanctum SPA stateful cookie auth (`web` guard backed by PostgreSQL `sessions`), `EnsureUserIsActive`, `EnsureRole`, and Policies (`UserPolicy`, `ClassroomPolicy`, `AuditLogPolicy`, `SqlDatasetPolicy`, `ExamPolicy`, `ExamAttemptPolicy`).
- **Students (`student`):** Kahoot-style entry via `display_name` + `class_code` (`POST /api/student/join`) issuing signed, encrypted, HTTP-only, `SameSite=Lax` cookie `student_session_id` verified by `VerifyStudentSession` + `EnsureStatefulAuthSession` + attempt ownership checks.

## Startup order

1. `cd infra && docker compose up -d`, then wait for both services to report `healthy`.
2. `cd backend/sql-proctor-api && PATH="/opt/homebrew/bin:$PATH" php artisan migrate && php artisan app:bootstrap-admin && php artisan serve`.
3. `cd frontend/sql-proctor-ui && npm run dev`.

## Deliberately absent

- Containerization of Next.js and Laravel apps, Redis, external mail delivery, and production deployment (Phase 5).
- Self-service staff registration, password reset emails, Bearer token login, 2FA, and SSO.

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
| `@monaco-editor/react` | 4.7.0 (Monaco 0.58.0) |
| PostgreSQL | 17.12 |
| MariaDB | 11.8.9 (`11.8.9-MariaDB-ubu2404`) |
| Docker | 29.8.0 |
| Docker Compose | 5.5.1 |
