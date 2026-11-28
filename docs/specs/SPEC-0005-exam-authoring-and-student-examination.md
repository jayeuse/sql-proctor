# Specification: Exam Authoring, Isolated MariaDB SQL Execution, Student Examination, and Grading

| Field | Value |
|---|---|
| Spec ID | SPEC-0005 |
| Title | Exam Authoring, Isolated MariaDB SQL Execution, Student Examination, and Grading |
| Status | Implemented & Verified (2026-11-28) |
| Owner | parent |
| Reviewer | Human Owner |
| Related task | Phases 2, 3, and 4: Exam Authoring, Isolated SQL Execution, Student Examination, and Grading |
| Date | 2026-11-28 |

## Objective

SQL-Proctor provides end-to-end practical SQL examination capabilities: examiners author reusable MariaDB datasets, construct multi-type exams (`sql_select`, `sql_dml`, `sql_ddl`, `sql_debug`, `short_answer`, `mcq`) with weighted visible/hidden test cases and rubrics, validate and publish immutable `exam_versions` snapshots, assign exams to classrooms, grade submissions automatically/manually/hybrid with audited overrides, and release results; students discover assigned exams in their active classroom, write and execute bounded SQL against isolated ephemeral MariaDB 11.8.9 sandboxes via Monaco Editor, auto-save and submit under server-enforced deadlines, and view released feedback; and administrators inspect all exams and perform audited emergency force-unpublishing.

## Problem

After Phase 1 (`SPEC-0004`), SQL-Proctor supports staff authentication, RBAC, classroom codes, Kahoot-style student sessions, and append-only audit logs, while MariaDB 11.8.9 (`sql-proctor-mariadb-1`) was blocked on host port `3306` by local XAMPP MySQL and had no schema, execution isolation, exam tables, grading logic, or exam UI. `docs/development-roadmap.md` also had `monaco-editor` (`0.57.0`) pulling `dompurify <=3.4.16` with two low-severity advisories pending review before wiring Monaco into the UI.

Pre-flight findings (2026-11-28):

1. Local XAMPP MySQL (`mysqld`) holds `127.0.0.1:3306`. Setting `MARIADB_PORT=3307` in gitignored `infra/.env` brought `mariadb:11.8.9` (`11.8.9-MariaDB-ubu2404`) healthy on `127.0.0.1:3307->3306/tcp` without stopping XAMPP.
2. In `frontend/sql-proctor-ui/`, `npm audit fix` resolved both `dompurify` advisories by upgrading `monaco-editor` `0.57.0 -> 0.58.0` and `dompurify` `3.4.15 -> 3.4.17` (`0` vulnerabilities in `npm audit`), while keeping `@monaco-editor/react@4.7.0`, Vitest (`20/20`), ESLint, and Next.js `16.4.6` production build green.

## Scope

- **In scope:**
  - PostgreSQL 17 migrations, Eloquent models, factories, policies, form requests, resources, services, and controllers for `sql_datasets`, `exams`, `exam_questions`, `question_test_cases`, `question_options`, `exam_versions`, `classroom_exams`, `exam_attempts`, `attempt_answers`, and `attempt_query_runs`.
  - MariaDB 11.8.9 isolated SQL execution engine (`App\Services\SqlExecution\*`): `mariadb_provisioner` vs. `mariadb_runner` dual-connection separation, `SandboxManager`, `SqlGuard`, `SqlExecutionService`, `SchemaIntrospector`, and `sandbox:cleanup-orphans` Artisan command.
  - Grading subsystem (`App\Services\Grading\*`): `ResultSetComparator` and `ExamGradingService` supporting outcome-based SQL auto-grading, weighted partial credit, `override_seed_sql`, `assertion_sql`, `expect_error`, `non_empty`, MCQ auto-grading, manual/hybrid rubric grading, and audited score overrides.
  - Authoring, publishing, classroom assignment, grading, admin oversight, and student examination `/api/*` endpoints adhering to `SPEC-0003` envelopes, plus extended `AuditEvent` enum cases logged via `AuditLogger`.
  - Next.js 16 App Router UI (`components/SqlEditor.jsx`, `/examiner/datasets`, `/examiner/exams/*`, `/examiner/attempts/*`, `/admin` Exams tab, `/student`, `/student/exams/[attemptId]`, `/student/exams/[attemptId]/result`) styled with Tailwind CSS v4.
  - Negative-first PHPUnit 12 suite (PostgreSQL `sql_proctor_test` + live MariaDB `sql_exec`), Vitest + RTL + MSW component tests, Playwright Chromium E2E tests (`1280x800` and `375x812`), and CI workflow update (`.github/workflows/ci.yml` adding `mariadb:11.8.9`).
- **Not in scope:**
  - Containerizing Laravel or Next.js, Redis queues, background job workers, email notifications, plagiarism detection, or multi-engine student SQL targets beyond MariaDB 11.8.9.

## Requirements

### Functional

| ID | Requirement |
|---|---|
| FR-1 | **Datasets:** Examiners create, list (`mine` / `shared` / `all`), view, update, delete (blocked `409` if referenced by an exam), and live-validate reusable MariaDB datasets (`schema_sql` + `seed_sql`), persisting auto-introspected `schema_summary` and `validated_at`. Admins manage shared template datasets via `/api/admin/datasets`. |
| FR-2 | **Exam & Question Authoring:** Examiners create/edit/delete `draft` (or zero-attempt `unpublished`) exams and manage all 6 question types (`sql_select`, `sql_dml`, `sql_ddl`, `sql_debug`, `short_answer`, `mcq`), `grading_mode` (`auto`, `manual`, `hybrid`), `comparison_config`, nested weighted `test_cases`, MCQ `options`, `rubric` items, and transactional question reordering while atomically recomputing `exams.total_points`. |
| FR-3 | **Pre-Publish Validation & Immutable Versioning:** `validate` and `publish` verify `total_points > 0`, attached dataset for SQL questions, non-empty `reference_sql` and valid test-case weight sums (`sum(weight_points) == points`) for auto/hybrid SQL questions, >=2 options with >=1 `is_correct` for MCQs, rubric weight sums for manual/hybrid questions with rubrics, and live MariaDB dry-run success for `schema_sql` + `seed_sql`, `reference_sql`, and all test cases (`override_seed_sql` + `assertion_sql`). `publish` creates an immutable `exam_versions` JSONB snapshot (`updating`/`deleting` blocked in model) and increments `current_version_number`. |
| FR-4 | **Exam Lifecycle & Classroom Assignment:** Examiners unpublish (`force_close_active: false` allows active attempts to finish; `true` immediately force-closes and grades saved answers), archive (read-only), and deep-clone any exam into an editable `draft`. Published exams bind to the examiner's classrooms via `classroom_exams` pinning `exam_version_id` with availability windows, active toggle, and explicit `release_results`. Admins inspect all exams read-only and execute audited `force-unpublish` with mandatory `reason`. |
| FR-5 | **Isolated MariaDB Execution:** Every dataset validation, dry-run, student query preview, and grading test evaluation allocates a fresh `sbx_<32-hex>` MariaDB schema via `mariadb_provisioner`, grants question-scoped privileges to `mariadb_runner` (`sql_runner`), enforces static `SqlGuard` rules, executes with `PDO::MYSQL_ATTR_MULTI_STATEMENTS = false`, `PDO::MYSQL_ATTR_LOCAL_INFILE = false`, `max_statement_time = 2`, unbuffered row cap (`500` rows, `truncated: true`), and cell/payload byte caps, and revokes privileges + drops `sbx_<hex>` inside `finally`. `php artisan sandbox:cleanup-orphans` sweeps stale `sbx_*` databases and grants. |
| FR-6 | **Student Exam Flow & Anti-Leakage:** Authenticated student sessions discover active assigned exams on `GET /api/student/exams`, start or idempotently resume attempts (`expires_at = min(started_at + duration_minutes, available_until)`), auto-save drafts (`PUT .../answers/{questionId}`), confirm-submit individual answers (`submit_answer: true`, persisting `attempt_answers.submitted_at` and locking further edits and `/run` calls on that question with `409` `answer_locked` while leaving unsubmitted questions editable), run live queries rate-limited at `20/min` per `student_session_id`, submit manually or auto-submit deterministically on expiry, and read released results. Student payloads NEVER expose `reference_sql`, hidden `test_cases`, `override_seed_sql`, `assertion_sql`, MCQ `is_correct`, or other students' data. |
| FR-7 | **Outcome-Based Grading & Audited Overrides:** `ResultSetComparator` grades SQL by comparing fresh MariaDB outputs (never SQL text strings) respecting `order_sensitive`, `column_name_sensitive`, `case_sensitive_values`, `float_tolerance`, `NULL` vs `""`, `required_clauses`, `forbidden_patterns`, `assertion_sql`, `expect_error`, and `non_empty`, awarding weighted partial credit across test cases. MCQs auto-grade by exact sorted option IDs. `manual`/`hybrid`/`short_answer` answers enter `needs_review`; examiners score with rubric breakdown/feedback, require mandatory `override_reason` when overriding `auto` questions (preserving `auto_score`), and finalize attempts. |
| FR-8 | **Audit Completeness:** All dataset, exam, question, classroom assignment, student exam lifecycle (including `student.answer_submitted`), blocked query, grading, override, and finalization events write append-only records to PostgreSQL `audit_logs` via `AuditLogger`. |
| FR-9 | **Frontend UI:** Next.js 16 App Router provides dynamic `SqlEditor` (`@monaco-editor/react` with accessible textarea fallback), Examiner console tabs/routes for Datasets, Exams Studio, Classroom Assignments, and Grading Console, Admin Exams oversight tab with Force Unpublish modal, and Student Dashboard, Exam Workspace (server-synced countdown timer, schema explorer, question navigator with per-question draft/submitted badges, Monaco query runner with visible execution feedback, draft save, per-answer submit confirmation with read-only persisted lock, submit exam confirmation), and Results view (green/amber/red pass, partial/pending review, and fail visual cues). |

### Non-functional

| ID | Requirement |
|---|---|
| NFR-1 | **Dual-DB Isolation (`AGENTS.md`, `ADR-0003`):** All 10 domain tables and `audit_logs` live strictly in PostgreSQL 17 (`sql_proctor` / `sql_proctor_test`). MariaDB 11.8.9 executes synthetic exam SQL only and never stores application tables, user records, session tokens, or audit logs. |
| NFR-2 | **Least-Privilege Execution (`sql-execution-isolation`):** `mariadb_runner` holds `USAGE ON *.* WITH MAX_USER_CONNECTIONS 30 MAX_STATEMENT_TIME 5` only, zero global privileges (`FILE`, `PROCESS`, `SUPER`, `CREATE USER`, `GRANT OPTION`), zero access to `mysql`, `performance_schema`, `sys`, `sql_exec`, or other `sbx_*` schemas, and `PDO::MYSQL_ATTR_LOCAL_INFILE = false`. |
| NFR-3 | **Bounded Execution:** Payload `<= 16384` bytes, statement count `<= 20` (`1` for `sql_select`/`sql_debug`), `max_statement_time = 2` s, row cap `500`, cell cap `4096` bytes, total response cap `1048576` bytes, client timeout `5` s, rate limit `20/min` per student session on `/run`. |
| NFR-4 | **Error Sanitization:** MariaDB errors never expose `sbx_<hex>`, DSNs, hostnames, ports, credentials, or stack traces. MariaDB outage returns controlled `503` (`execution_engine_unavailable`). |
| NFR-5 | **UI Responsiveness & Accessibility:** All staff and student routes render cleanly with Tailwind CSS v4 utilities, keyboard navigation, accessible status/alert regions, and zero horizontal overflow at `1280x800` and `375x812`. |

## SQL Execution Isolation Decision Register (`sql-execution-isolation`)

| ID | Decision | Recorded value | Owner | Reason |
|---|---|---|---|---|
| D-01 | Isolation model | Ephemeral per-execution MariaDB schema (`sbx_<32-hex>`) on shared MariaDB 11.8.9 | Human Owner / SPEC-0005 | `SCHEMA` and `DATABASE` are synonyms in MariaDB; fresh per-run schemas prevent implicitly committed DDL/DML residue and cross-run leakage without container-socket host risk. |
| D-02 | Scope naming & lookup | Server-generated `sbx_<32-hex>` via `bin2hex(random_bytes(16))`; attempt context resolved from route + session policy | Parent | Client never supplies schema names; 128-bit random suffix eliminates predictable names. |
| D-03 | Execution account model | Shared least-privilege `sql_runner` (`mariadb_runner` connection) with per-execution scoped grants | Human Owner / SPEC-0005 | Explicitly mandated dual-connection separation (`mariadb_provisioner` + `mariadb_runner`) in `config/database.php`. |
| D-04 | Execution privileges | `sql_select`/`sql_debug`: `SELECT`; `sql_dml`: `SELECT, INSERT, UPDATE, DELETE`; `sql_ddl`: `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, CREATE VIEW` on `sbx_<hex>.*` | Parent | Strictly bounded to question type; omits `FILE`, `PROCESS`, `SUPER`, `GRANT OPTION`, `CREATE ROUTINE`, `TRIGGER`, `EVENT`, `EXECUTE`. |
| D-05 | Provisioner account | Dedicated `sql_provisioner` (`mariadb_provisioner`) with schema privileges + `GRANT OPTION` scoped to `sbx_%.*` | Parent | Provisioner cannot inspect or modify `mysql.*`, `sys.*`, `performance_schema.*`, or global server settings. |
| D-06 | Credentials | Stored in gitignored `.env` (`infra/.env`, `backend/sql-proctor-api/.env`) and CI secrets; placeholders in `.env.example` | Parent | Zero credentials in tracked files, logs, `audit_logs`, or API errors. |
| D-07 | Account host pattern | `'%'` for `sql_provisioner` and `sql_runner` while container port binds strictly to `127.0.0.1` | Parent | Docker Desktop for Mac connects from host via bridge/VM gateway IPs; `127.0.0.1` port binding blocks non-local network access. |
| D-08 | Seed data | Synthetic `schema_sql` + `seed_sql` from `sql_datasets` / `exam_versions.snapshot` (+ `override_seed_sql`) | Parent | Zero real user, session, or application tables ever enter MariaDB. |
| D-09 | Reset semantics | Fresh `sbx_<hex>` schema created and seeded per execution/test case | Parent | Eliminates MariaDB implicit-DDL-commit state drift and guarantees deterministic results. |
| D-10 | Cleanup triggers | `REVOKE <granted> ON sbx_<hex>.*` + `DROP DATABASE IF EXISTS sbx_<hex>` inside `finally`, plus `php artisan sandbox:cleanup-orphans` | Parent | Guarantees zero leaked schemas on SQL errors, timeouts, exceptions, or worker crashes. |
| D-11 | Time limits | `SET SESSION max_statement_time = 2` on `mariadb_runner`, account backstop `MAX_STATEMENT_TIME 5`, PDO `ATTR_TIMEOUT => 5` | Parent | Stops runaway queries inside MariaDB 11.8.9 and bounds socket wait. |
| D-12 | Row & size limits | Max SQL `16384` bytes, max `20` statements (`1` for read-only), max `500` returned rows (`truncated: true`), max `4096` bytes/cell, `1048576` bytes total result | Parent | Prevents PHP memory exhaustion and payload bloat via unbuffered PDO cursor reading. |
| D-13 | Connection limits | `MAX_USER_CONNECTIONS 30` on `sql_runner`, immediate connection close in `finally` | Parent | Prevents connection starvation while keeping headroom below MariaDB default `max_connections` (151). |
| D-14 | Concurrency policy | Per-attempt cache lock (`sandbox:attempt:{id}`, `10s`) returning `409` (`execution_in_progress`) if concurrent run on same attempt; parallel across distinct attempts | Parent | Prevents interleaved runs within one attempt without stalling distinct students. |
| D-15 | Statement policy | Comment/string-aware splitter + `PDO::MYSQL_ATTR_MULTI_STATEMENTS = false`; `1` read-only statement for `sql_select`/`sql_debug`, up to `20` statements for `sql_dml`/`sql_ddl` | Parent | Disables driver-level multi-statement smuggling (`V-16`) while supporting multi-step DDL/DML tasks safely. |
| D-16 | Audit record | `attempt_query_runs` row per run + `AuditLogger` (`student.query_blocked`, lifecycle, grading) in PostgreSQL only | Parent | Preserves full telemetry and tamper-evident audit trail outside MariaDB. |
| D-17 | Error contract | Mapped status/codes (`policy_blocked`, `timeout`, `sql_error`, `execution_engine_unavailable` `503`) stripping `sbx_<hex>`, DSNs, and host info | Parent | Prevents internal topology/credential leaks. |
| D-18 | Engine failure | Attempt remains `in_progress` with saved answers intact; API returns `503` (`execution_engine_unavailable`) | Parent | Allows safe retry once MariaDB recovers. |
| D-19 | Infrastructure limits | Loopback-only `127.0.0.1` ports in `infra/compose.yaml`, `finally` drop, orphan sweep command | Parent | Bounds disk/process blast radius. |
| D-20 | Transport | Loopback TCP (`127.0.0.1`); TLS verification waived for local dev/CI loopback with optional `MYSQL_ATTR_SSL_CA` hook | Parent | Local traffic never leaves loopback interface. |

## Monaco Security Disposition

- Pre-Phase 2 audit (`docs/development-roadmap.md`) flagged two low-severity `dompurify` advisories (`<=3.4.16`) via `monaco-editor@0.57.0`.
- Disposition: Ran `npm audit fix` in `frontend/sql-proctor-ui/`, upgrading `monaco-editor` to `0.58.0` and `dompurify` to `3.4.17`. `npm audit` reports `0` vulnerabilities, and `@monaco-editor/react@4.7.0` (`peerDependencies: monaco-editor >= 0.25.0 < 1`, `react ^19.0.0`) passes Vitest (`20/20`), ESLint, and `next build` cleanly.
- `SqlEditor` loads `@monaco-editor/react` dynamically (`ssr: false`) and treats Monaco as a plain-text SQL code editor without rendering untrusted HTML outside Monaco's built-in sanitization.

## Constraints

- **Architecture & Dual-DB (`AGENTS.md`, `ADR-0002`, `ADR-0003`):** Laravel owns persistence, business rules, grading, and MariaDB orchestration; Next.js calls Laravel HTTP API only; PostgreSQL holds all application/exam/audit data; MariaDB runs ephemeral isolated SQL only.
- **API Contract (`SPEC-0003`, `docs/api-specification.md`):** Uniform `{"data": ...}`, paginated `data/links/meta`, `{"message": "..."}`, coded `ApiException` errors, `X-Request-Id` header on every `/api/*` response.
- **Authoring REST Pattern:** Test cases (`test_cases`) and MCQ options (`options`) are managed atomically nested inside `POST /api/examiner/exams/{exam}/questions` and `PATCH /api/examiner/exams/{exam}/questions/{question}` inside database transactions so weight sums against `question.points` remain invariant at rest.
- **Environment:** Prefix `php`/`composer` with `PATH="/opt/homebrew/bin:$PATH"`. Never print/commit `.env` values. Never run `docker compose down -v`.

## Architecture

- **PostgreSQL 17 (`backend/sql-proctor-api/database/migrations/`):** 10 new domain tables (`sql_datasets`, `exams`, `exam_questions`, `question_test_cases`, `question_options`, `exam_versions`, `classroom_exams`, `exam_attempts`, `attempt_answers`, `attempt_query_runs`) with UUID PKs, foreign keys, and PostgreSQL `CHECK` constraints.
- **MariaDB 11.8.9 (`infra/`, `config/database.php`, `app/Services/SqlExecution/`):** Dual connections (`mariadb_provisioner`, `mariadb_runner`), `infra/mariadb-init/` bootstrap script, `SandboxManager`, `SqlGuard`, `SqlExecutionService`, `SchemaIntrospector`, and `CleanupOrphanSandboxesCommand`.
- **Grading (`app/Services/Grading/`):** `ResultSetComparator`, `ExamGradingService`.
- **API Layer:** `SqlDatasetPolicy`, `ExamPolicy`, `ExamAttemptPolicy`, dedicated FormRequests, Resources (with strict student anti-leakage serializers), Controllers under `Api/Examiner/`, `Api/Admin/`, `Api/Student/`, and extended `AuditEvent` enum.
- **Frontend (`frontend/sql-proctor-ui/`):** `components/SqlEditor.jsx`, upgraded `/examiner` navigation + `/examiner/datasets`, `/examiner/exams`, `/examiner/exams/[examId]`, `/examiner/exams/[examId]/attempts`, `/examiner/attempts/[attemptId]`, upgraded `/admin` Exams tab, upgraded `/student`, `/student/exams/[attemptId]`, `/student/exams/[attemptId]/result`.

## Expected behavior

1. Examiner creates a dataset (`schema_sql`, `seed_sql`) and runs **Validate & Inspect Schema** -> MariaDB executes DDL/seed in ephemeral `sbx_<hex>`, introspects schema summary, drops sandbox, persists `schema_summary` + `validated_at`, and logs `dataset.validated`.
2. Examiner creates an exam, adds weighted questions (`sql_select`, `sql_dml`, `sql_ddl`, `sql_debug`, `short_answer`, `mcq`), validates against live MariaDB (`validate`), publishes immutable `exam_versions` snapshot (`publish`), assigns to open classroom (`classroom_exams`).
3. Student joins classroom -> sees assigned exam on `/student` -> clicks **Start Exam** -> receives attempt pinned to `exam_version_id` with server-authoritative `expires_at`, `schema_summary`, sanitized questions -> runs live queries in Monaco (`POST .../run`) -> auto-saves answers (`PUT .../answers/{q}`) -> submits (`POST .../submit`).
4. Auto-gradable questions evaluate via `ResultSetComparator` / MCQ check immediately; `manual`/`hybrid`/`short_answer` queue for examiner grading (`needs_review`); examiner grades/overrides with reason and clicks **Finalize Attempt** -> releases results (`release_results`) -> student views scores and feedback.
5. Admin views all exams on `/admin` Exams tab and can **Force Unpublish** with an audited reason.

## Failure behavior

| Trigger | Status | Response body / code | Audit / Cleanup |
|---|---|---|---|
| Invalid payload, broken `reference_sql`, weight mismatch | `422` | `{"message": "...", "errors": {...}}` or `{"message": "...", "code": "exam_validation_failed", "issues": [...]}` | Ephemeral `sbx_<hex>` dropped |
| Student SQL violates `SqlGuard` (`INTO OUTFILE`, `mysql.`, `SLEEP`, etc.) | `200` (run telemetry) or `422` | Run record `status: "policy_blocked"`, sanitized error message | `student.query_blocked` audited; `sbx_<hex>` dropped |
| Query exceeds `max_statement_time` (`2s`) | `200` (run telemetry) | Run record `status: "timeout"`, `"Query timed out after 2 seconds."` | Connection closed, `sbx_<hex>` dropped |
| Unauthenticated / wrong role / non-owner | `401` / `403` | Standard envelope; zero MariaDB connection opened | Denied before service layer |
| Editing locked exam / deleting in-use dataset / concurrent execution / editing submitted answer | `409` | `exam_locked`, `dataset_in_use`, `attempt_already_submitted`, `attempt_expired`, `answer_locked`, `execution_in_progress` | State unchanged |
| Student `/run` exceeds `20/min` | `429` | `{"message": "...", "code": "too_many_attempts"}` + `Retry-After` | `auth.lockout` or rate-limited |
| MariaDB unavailable | `503` | `{"message": "SQL execution service is temporarily unavailable.", "code": "execution_engine_unavailable"}` | No DSN/credentials leaked; attempt remains active |

## Security considerations

- **ASVS V4 / A01 (Broken Access Control):** Every route enforces Sanctum/student cookie auth + `EnsureUserIsActive` / `VerifyStudentSession` + role middleware + ownership Policies (`SqlDatasetPolicy`, `ExamPolicy`, `ExamAttemptPolicy`). Student serializers strictly omit `reference_sql`, hidden test cases, `override_seed_sql`, `assertion_sql`, and MCQ `is_correct`.
- **ASVS V5 / A03 (Injection & Execution Isolation):** Student SQL runs strictly on `mariadb_runner` inside ephemeral `sbx_<hex>` with `PDO::MYSQL_ATTR_MULTI_STATEMENTS = false`, `LOCAL_INFILE = false`, scoped privileges, timeouts, row/byte caps, and deterministic `finally` cleanup.
- **ASVS V7 / A09 (Auditability):** All authoring, assignment, start/submit/auto-submit/force-close, blocked query, grading, override, and force-unpublish events write append-only rows to PostgreSQL `audit_logs`.

## Acceptance criteria

| ID | Criterion | Verified by |
|---|---|---|
| AC-1 | Datasets validate against live MariaDB 11.8.9, introspect `schema_summary`, enforce ownership + shared read-only boundaries, and block delete when referenced (`409`). | `DatasetAuthoringTest` |
| AC-2 | Exams/questions enforce draft/zero-attempt edit locks (`409`), transactional reorder, `total_points` accuracy, pre-publish validation (`422` + dry-run), immutable `exam_versions` snapshots, unpublish modes, archive, clone, and classroom assignment. | `ExamAuthoringAndPublishingTest` |
| AC-3 | MariaDB isolation stops cross-schema reads/writes, `mysql.*`/`FILE`/`SLEEP`/`OUTFILE`/`LOCAL INFILE`/oversized SQL/row floods, drops all `sbx_*` schemas in `finally` and via `sandbox:cleanup-orphans`, and handles engine downtime with `503`. | `MariaDbSandboxIsolationTest` |
| AC-4 | Student endpoints enforce session/classroom/window checks, idempotent start, server-side timer auto-submit (`409` `attempt_expired`), rate limit (`429`), and strict zero-leakage of `reference_sql`/hidden cases/MCQ keys. | `StudentExamFlowTest` |
| AC-5 | `ResultSetComparator` + `ExamGradingService` accurately grade equivalent SQL, `order_sensitive`, `column_name_sensitive`, `float_tolerance`, `NULL` vs `""`, `override_seed_sql`, `sql_dml`/`sql_ddl` `assertion_sql`, `expect_error`, `non_empty`, MCQs, manual rubrics, mandatory `override_reason`, and finalize + release results. | `ExamGradingTest` |
| AC-6 | Admin oversight lists all exams and executes audited `force-unpublish`; all critical events appear in PostgreSQL `audit_logs`. | `AdminExamOversightTest` |
| AC-7 | Frontend Vitest/RTL/MSW tests, ESLint, Next.js production build, and Playwright Chromium E2E desktop (`1280x800`) + mobile (`375x812`) flows pass with `0` audit vulnerabilities on frontend and `0` leaked MariaDB schemas. | Vitest, ESLint, `next build`, Playwright E2E |

## Test scenarios

Ordered negative-first in [exam-authoring-and-student-examination-test-matrix.md](../plans/exam-authoring-and-student-examination-test-matrix.md) (`TM-1` through `TM-26`).

## Dependencies

- [SPEC-0003](SPEC-0003-api-response-envelope-and-pagination.md), [SPEC-0004](SPEC-0004-auth-rbac-and-audit-logging.md), [ADR-0003](../adr/0003-mariadb-sql-execution-isolation-and-exam-versioning.md).
- Running PostgreSQL 17.12 (`sql_proctor` / `sql_proctor_test`) and MariaDB 11.8.9 (`127.0.0.1:${MARIADB_PORT}`).

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Concurrent `sql_runner` execution temporarily holds grants on two simultaneous `sbx_*` schemas | Low | Medium | Static `SqlGuard` blocks `sbx_` cross-references + `SHOW DATABASES`; 128-bit random schema names prevent guessing; per-attempt lock serializes intra-attempt runs. |
| Crashed PHP worker leaves an orphan `sbx_*` schema or lingering `sql_runner` grant | Low | Medium | `finally` cleanup revokes and drops; `php artisan sandbox:cleanup-orphans` sweeps stale `sbx_*` databases and grants. |
| MariaDB implicit commit on DDL corrupts baseline state if schemas are reused | High if reused | High | Fresh `sbx_<hex>` schema created per execution/test case; zero reuse. |

## Definition of Done

Satisfies [definition-of-done.md](../templates/definition-of-done.md): Pint `--test`, full PHPUnit 12 suite on PostgreSQL `sql_proctor_test` + live MariaDB `sql_exec`, zero `sbx_*` leaks, `composer audit` & `npm audit`, Vitest, ESLint, Next.js build, Playwright Chromium E2E (desktop + mobile), and security/code review complete with zero `BLOCKING` findings.
