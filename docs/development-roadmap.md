# SQL-Proctor Development Roadmap

> Status: Phase 0 is verified except the MariaDB host port check. Phase 1 is complete and verified against [SPEC-0004](specs/SPEC-0004-auth-rbac-and-audit-logging.md). Phases 2 through 5 are `Proposed` and await Human Owner approval.

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
4. MariaDB is healthy and answers a query. `NOT RUN`: XAMPP MySQL holds port 3306. Configured and waiting for the port.
5. Every documented Compose command runs as written. PostgreSQL commands verified. The `up`, `start`, and MariaDB checks are `NOT RUN` until port 3306 is free.

## Phase 1: Accounts and authentication

**Status: Complete and verified (2026-11-28).**

Outcome: an administrator, examiner, and student authenticate through role-separated flows, and security actions write to an immutable audit log.

Delivered under [SPEC-0004](specs/SPEC-0004-auth-rbac-and-audit-logging.md) and [ADR-0002](adr/0002-auth-session-boundaries-and-audit-isolation.md):

- Idempotent `php artisan app:bootstrap-admin` command for the first active administrator account.
- Sanctum SPA session authentication (`/api/auth/login`, `/api/auth/logout`, `/api/user`), `last_login_at` tracking, active-user enforcement, and rate limiting.
- Administrator endpoints and `/admin` UI for examiner CRUD, enable/disable toggles, and filterable audit logs.
- Examiner endpoints and `/examiner` UI for classrooms, class-code generation and rotation, class status (`open`/`closed`), and student session revocation.
- Kahoot-style student entry (`/api/student/join`, `/api/student/me`, `/api/student/leave`) on `/join` and `/student` using HTTP-only `student_session` cookies.
- Append-only `audit_logs` PostgreSQL table protected by a `BEFORE UPDATE OR DELETE` trigger and centralized secret redaction.
- Next.js 16 App Router pages styled with Tailwind CSS v4, Vitest component tests, Playwright Chromium E2E tests, and GitHub Actions CI.

Exit criteria verified:

1. Backend Pint formatting and 98 PostgreSQL PHPUnit feature/integration tests pass.
2. Frontend ESLint, Next.js production build, 20 Vitest tests, and 11 Chromium Playwright E2E journeys pass.
3. Unauthenticated and cross-role access return `401` and `403`, and `audit_logs` rejects direct `UPDATE`/`DELETE`.

## Phase 2: Exam authoring

**Status: Proposed.**

Outcome: an examiner can create an exam with SQL tasks and expected results, then publish it.

Scope to define: question types (single statement, result set, schema modification), per-question points, expected-result comparison rules, and draft versus published states. These are `TBD`.

Exit criteria to define: create, edit, publish, and unpublish an exam; validation errors are visible; a published exam is immutable for students.

Not started. Needs accepted criteria and a feature task.

## Phase 3: Student attempts and SQL execution

**Status: Proposed. The highest-risk phase.**

Outcome: a student starts an attempt, writes SQL in the Monaco editor, runs it against MariaDB, and sees a bounded result.

Scope to define: the isolation model (schema per attempt, database per attempt, or a restricted server), the execution user's privileges, seed data, cleanup, time limit, row limit, and statement limit. These are `TBD`.

Design rule: MariaDB never receives application data or application credentials. PostgreSQL remains the application database.

Exit criteria to define: one attempt cannot see another attempt's data or the application database; a runaway query is stopped; the attempt survives a page reload.

Not started. Needs a technical design and the Human Owner's approval of the isolation model.

## Phase 4: Grading and results

**Status: Proposed.**

Outcome: a submitted attempt is graded against the expected results, and the examiner and student see the outcome.

Scope to define: automatic comparison rules, partial credit, manual review, score visibility, and appeal or regrade behavior. These are `TBD`.

Exit criteria to define: a known set of answers produces the expected scores; a regrade is auditable.

Not started.

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

- MariaDB host port 3306 conflicts with XAMPP MySQL when XAMPP MySQL runs. Stop XAMPP MySQL or set `MARIADB_PORT` before Phase 3 verification.
- Two low-severity `dompurify` advisories (`<=3.4.16`) arrive through `monaco-editor`. Monaco is installed and unused until Phase 3; review before exam execution starts.
- Five pre-existing Symfony advisories appear in `composer audit` (observed 2026-11-25).
- Frontend tests (`Vitest`, `React Testing Library`, `Playwright`) and GitHub Actions CI (`.github/workflows/ci.yml`) were added in Phase 1.
- The Laravel skeleton's `backend/sql-proctor-api/AGENTS.md` asks agents to install Laravel Boost, which stays outside the approved setup.
