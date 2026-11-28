# SQL-Proctor Development Roadmap

> Status: Phases 0 through 4 (`SPEC-0004`, `SPEC-0005`, `ADR-0002`, `ADR-0003`) are complete and verified (2026-11-28). Phase 5 remains `Proposed`.

The Human Owner defined the product as a web-based SQL practical examination system and fixed the stack. This roadmap gives the order of work. It does not authorize any unapproved phase.

## Phase 0: Development environment

**Status: Complete except the MariaDB host port check (2026-11-19).**

Outcome: a reproducible local environment with separate applications, both databases, and verified startup.

Delivered:

- Laravel 13.43 API scaffold with Sanctum, PostgreSQL connection, CORS, and stateful domains.
- Next.js 16.4.6 app with App Router, JavaScript, Axios, and Monaco installed.
- `infra/` Compose services for PostgreSQL 17.12 and MariaDB 11.8.9, named volumes, health checks, and loopback-only ports.
- Architecture, database design, API specification, and this roadmap.
- Toolchain: Homebrew PHP 8.5.12, Composer 2.11.2, Node.js 26.8.1, npm 11.19.0, Docker 29.8.0.

Exit criteria:

1. `php artisan serve` answers `200` on `/up`. Verified.
2. `npm run dev` answers `200` on `http://localhost:3000`. Verified.
3. PostgreSQL is healthy and Laravel migrations are applied. Verified.
4. MariaDB is healthy and answers a query. Verified on `127.0.0.1:3307` (`MARIADB_PORT=3307` in `infra/.env` while local XAMPP MySQL keeps port `3306`; `11.8.9-MariaDB-ubu2404`).
5. Every documented Compose command runs as written. Verified.

## Phase 1: Accounts and authentication

**Status: Complete and verified (2026-11-28).**

Outcome: an administrator, examiner, and student authenticate through role-separated flows, and security actions write to an immutable audit log.

Delivered under [SPEC-0004](specs/SPEC-0004-auth-rbac-and-audit-logging.md) and [ADR-0002](adr/0002-auth-session-boundaries-and-audit-isolation.md):

- Idempotent `php artisan app:bootstrap-admin` command for the first active administrator account.
- Sanctum SPA session authentication (`/api/auth/login`, `/api/auth/logout`, `/api/user`), `last_login_at` tracking, active-user enforcement, and rate limiting.
- Administrator endpoints and `/admin` UI for examiner CRUD, enable/disable toggles, and filterable audit logs.
- Examiner endpoints and `/examiner` UI for classrooms, class-code generation and rotation, class status (`open`/`closed`), and student session revocation.
- Kahoot-style student entry (`/api/student/join`, `/api/student/session`, `/api/student/leave`) on `/join` and `/student` using HTTP-only `student_session_id` cookies.
- Append-only `audit_logs` PostgreSQL table protected by a `BEFORE UPDATE OR DELETE` trigger and centralized secret redaction.
- Next.js 16 App Router pages styled with Tailwind CSS v4, Vitest component tests, Playwright Chromium E2E tests, and GitHub Actions CI.

Exit criteria verified:

1. Backend Pint formatting and 100 PostgreSQL PHPUnit feature/integration tests pass.
2. Frontend ESLint, Next.js production build, 20 Vitest tests, and 11 Chromium Playwright E2E journeys pass.
3. Unauthenticated and cross-role access return `401` and `403`, and `audit_logs` rejects direct `UPDATE`/`DELETE`.

## Phase 2: Exam authoring

**Status: Complete and verified (2026-11-28, [SPEC-0005](specs/SPEC-0005-exam-authoring-and-student-examination.md)).**

Outcome: an examiner can create reusable datasets, build exams across 6 question types (`sql_select`, `sql_dml`, `sql_ddl`, `sql_debug`, `short_answer`, `mcq`) with weighted test cases and rubrics, validate against live MariaDB, publish immutable `exam_versions`, and assign exams to classrooms.

## Phase 3: Student attempts and SQL execution

**Status: Complete and verified (2026-11-28, [SPEC-0005](specs/SPEC-0005-exam-authoring-and-student-examination.md), [ADR-0003](adr/0003-mariadb-sql-execution-isolation-and-exam-versioning.md)).**

Outcome: a student starts an attempt pinned to an immutable `exam_versions` snapshot, writes SQL in Monaco Editor, executes queries against an ephemeral `sbx_<32-hex>` MariaDB 11.8.9 schema under least-privilege `sql_runner`, auto-saves answers, and submits under server-authoritative timers.

## Phase 4: Grading and results

**Status: Complete and verified (2026-11-28, [SPEC-0005](specs/SPEC-0005-exam-authoring-and-student-examination.md)).**

Outcome: submitted attempts are graded via outcome-based SQL result comparison (`ResultSetComparator`), weighted partial credit, MCQ checks, manual/hybrid rubrics, audited score overrides, and controlled result release.

Exit criteria verified across Phases 2–4:

1. Pint clean and 135/135 PHPUnit tests (`1151` assertions) pass against PostgreSQL `sql_proctor_test` and live MariaDB 11.8.9, with zero leaked `sbx_*` schemas or runner grants.
2. Frontend `npm audit` reports `0` vulnerabilities; Vitest (`12` files, `42` tests), ESLint, Next.js build (`16` routes), and Playwright Chromium E2E (`12/12` across `1280x800` desktop and `375x812` phone) pass.

## Phase 5: Hardening and operations

**Status: Proposed.**

Outcome: the system is safe to run for a real cohort.

Scope to define: audit history, backups, retention, monitoring, rate limits, error reporting, and deployment target. These are `TBD`.

Exit criteria to define: a restore from backup is tested; secrets are managed outside local files; production has `APP_DEBUG=false`; a release verification passes.

Not started.

## Cross-phase rules

1. PostgreSQL is the application database. MariaDB is the student-query execution engine.
2. Frontend and backend stay separate applications.
3. Application code never goes in `infra/`.
4. Every phase starts with accepted criteria and one feature task.
5. Dates are not set. The Human Owner sets them when a phase is approved.

## Known gaps

- MariaDB host port 3306 conflicts with XAMPP MySQL when XAMPP MySQL runs. Local `infra/.env` and `backend/sql-proctor-api/.env` use `MARIADB_PORT=3307`; CI uses default `3306`.
- `dompurify` advisories (`<=3.4.16`) via `monaco-editor` were resolved on 2026-11-28 via `npm audit fix` (`monaco-editor` `0.58.0`, `dompurify` `3.4.17`, `0` vulnerabilities in `npm audit`).
- Five pre-existing Symfony advisories appear in `composer audit` (observed 2026-11-25).
- Frontend tests (`Vitest`, `React Testing Library`, `Playwright`) and GitHub Actions CI (`.github/workflows/ci.yml`) were added in Phase 1.
- The Laravel skeleton's `backend/sql-proctor-api/AGENTS.md` asks agents to install Laravel Boost, which stays outside the approved setup.
