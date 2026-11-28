# ADR-0003: Ephemeral MariaDB Schema Isolation, Immutable Exam Versioning, and Classroom Decoupling

| Field | Value |
|---|---|
| Status | Accepted |
| Date | 2026-11-28 |
| Decision owner | Human Owner |
| Related spec | [SPEC-0005](../specs/SPEC-0005-exam-authoring-and-student-examination.md) |
| Related task | Phases 2–4: Exam Authoring, Isolated SQL Execution, Student Examination, and Grading |

## Context

Executing untrusted student SQL in practical exams introduces severe data-exposure, availability, and academic-integrity risks:

1. MariaDB DDL (`CREATE`, `ALTER`, `DROP`) causes implicit commits, so transaction-rollback sandboxing fails for `sql_ddl` tasks and can leak altered tables into subsequent queries.
2. Reusing a shared schema or Compose bootstrap user (`sql_runner` with `ALL PRIVILEGES ON sql_exec.*`) allows one query to corrupt another attempt's state or persist artifacts across test cases.
3. Editing an exam, dataset, or test case after students have started or finished attempts would corrupt active attempts, break deterministic grading, and invalidate historical score breakdowns.
4. Tightly coupling exams directly to a single `classroom_id` prevents examiners from reusing an exam across multiple sections or cohorts.

## Decision

1. **Ephemeral per-execution MariaDB schema isolation (`sbx_<32-hex>`) with provisioner/runner account separation:**
   - Every dry-run validation, student query preview run, and grading test-case evaluation allocates a unique `sbx_<32-hex>` database on MariaDB 11.8.9 using the dedicated `mariadb_provisioner` connection (`sql_provisioner`, scoped strictly to `sbx_%.*` with `GRANT OPTION` and zero access to `mysql.*`, `sys.*`, `performance_schema.*`, or `sql_exec.*`).
   - `mariadb_provisioner` applies `schema_sql` and `seed_sql` (or `override_seed_sql`), then grants only question-type-scoped privileges on `sbx_<hex>.*` (`SELECT` for `sql_select`/`sql_debug`, DML for `sql_dml`, DDL+DML for `sql_ddl`) to `mariadb_runner` (`sql_runner`, hardened to `USAGE ON *.* WITH MAX_USER_CONNECTIONS 30 MAX_STATEMENT_TIME 5` and zero `sql_exec.*` privileges).
   - Untrusted SQL executes through a fresh `mariadb_runner` PDO connection bound directly to `dbname=sbx_<hex>` with `PDO::MYSQL_ATTR_MULTI_STATEMENTS = false`, `PDO::MYSQL_ATTR_LOCAL_INFILE = false`, unbuffered reading capped at `500` rows / `1 MiB`, `SET SESSION max_statement_time = 2`, and `PDO::ATTR_TIMEOUT = 5` after `SqlGuard` validation.
   - A `try ... finally` block unconditionally revokes the granted schema privileges from `sql_runner` and drops `sbx_<hex>`. `php artisan sandbox:cleanup-orphans` sweeps any schemas or grants left by crashed workers.
   - Host pattern `'%'` is permitted for `sql_provisioner` and `sql_runner` solely because Docker Desktop routes loopback host connections (`127.0.0.1:${MARIADB_PORT}`) over VM/bridge gateway IPs while `infra/compose.yaml` binds container ports strictly to `127.0.0.1`.

2. **Immutable JSONB snapshotting (`exam_versions`):**
   - Publishing an exam freezes the complete exam configuration, attached dataset (`schema_sql`, `seed_sql`, `schema_summary`), ordered questions, weighted test cases (including `override_seed_sql` and `assertion_sql`), MCQ options, rubrics, and `total_points` into an append-only `exam_versions` row (`updating` and `deleting` rejected at the Eloquent model layer).
   - `exam_attempts` and `classroom_exams` reference `exam_version_id` (`ON DELETE RESTRICT`), guaranteeing later dataset updates or cloned drafts never alter active attempts or historical grades.

3. **Decoupled classroom assignments (`classroom_exams`):**
   - `exams` remain reusable examiner blueprints decoupled from `classrooms` via `classroom_exams` (`unique(classroom_id, exam_id)`), enabling per-classroom `available_from`/`available_until`, `is_active`, and `results_released_at` controls.

## Consequences

1. DDL, DML, and `SELECT` queries execute deterministically from pristine state with zero cross-run residue and zero MariaDB storage of application or audit data.
2. Live MariaDB verification runs in both local PHPUnit/Playwright suites and GitHub Actions CI (`mariadb:11.8.9` service container).
3. Published exam history remains reproducible and auditable even after an examiner clones or evolves future versions or edits underlying datasets.

## Alternatives considered

| Alternative | Why it was not chosen |
|---|---|
| Rollback-based transaction wrapping on a shared MariaDB schema | MariaDB implicitly commits DDL statements (`CREATE`/`ALTER`/`DROP`), breaking rollback isolation for `sql_ddl` exams. |
| Ephemeral per-execution MariaDB Docker containers controlled by Laravel | Grants Laravel access to the host Docker socket (equivalent to host root control) and adds multi-second container startup latency per query run. |
| Linking `exam_attempts` directly to live mutable `exam_questions` rows | Editing or deleting a question/dataset after publication would silently alter active student exams and invalidate graded attempts. |

## References

- [AGENTS.md](../../AGENTS.md)
- [SPEC-0005](../specs/SPEC-0005-exam-authoring-and-student-examination.md)
- [ADR-0002](0002-auth-session-boundaries-and-audit-isolation.md)
- [sql-execution-isolation skill](../../.agents/skills/sql-execution-isolation/SKILL.md)
