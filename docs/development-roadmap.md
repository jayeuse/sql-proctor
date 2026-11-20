# SQL-Proctor Development Roadmap

> Status: Proposed. Only Phase 0 is complete and verified. The Human Owner has not approved any later phase, date, or scope. Feature items are `TBD`.

The Human Owner defined the product as a web-based SQL practical examination system and fixed the stack. This roadmap gives the order of work. It does not authorize any phase.

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

**Status: Proposed.**

Outcome: a person can create an account, sign in, sign out, and call a protected endpoint.

Scope to define: registration policy (open, invited, or administrator-created), email verification, password reset, and token lifetime. These are `TBD`.

Exit criteria to define: an end-to-end sign-in test against PostgreSQL, token revocation, and rate limiting on the sign-in route.

Not started. Needs accepted criteria and a feature task.

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

## Known gaps at the end of Phase 0

- MariaDB host port 3306 conflicts with XAMPP MySQL. Stop XAMPP MySQL or set `MARIADB_PORT`.
- No frontend test runner. `Vitest` is listed in the stack but not installed.
- No CI pipeline.
- Two low-severity `dompurify` advisories arrive through `monaco-editor`. Monaco is installed but not used yet. Review before the editor feature starts.
- The Laravel skeleton's `backend/sql-proctor-api/AGENTS.md` asks agents to install Laravel Boost. That was not done, because this setup did not authorize the extra package.
