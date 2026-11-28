# SQL-Proctor Database Design

> Status: Updated for SPEC-0005 (Exam Authoring, Isolated MariaDB SQL Execution, Student Examination, and Grading) and ADR-0003 on 2026-11-28.

SQL-Proctor uses two databases with strictly isolated responsibilities:

| Database | Engine | Role | Application data |
|---|---|---|---|
| `sql_proctor` (and `sql_proctor_test`) | PostgreSQL 17.12 | Application, exam, attempt, grading, and audit database | Yes |
| MariaDB (`127.0.0.1:3306` / `3307`) | MariaDB 11.8.9 | Ephemeral student SQL execution sandboxes (`sbx_<32-hex>`) | No |

The separation is a hard rule (`AGENTS.md`, [ADR-0002](adr/0002-auth-session-boundaries-and-audit-isolation.md), [ADR-0003](adr/0003-mariadb-sql-execution-isolation-and-exam-versioning.md)). A student query never executes with access to application, credential, or audit tables.

## PostgreSQL: `sql_proctor`

Connection: `127.0.0.1:5432`, user `sql_proctor`, from `backend/sql-proctor-api/.env` (`sql_proctor_test` via `phpunit.xml`). Only the Laravel API connects.

### Phase 1 Foundation Tables

| Table | Purpose | Key columns & constraints |
|---|---|---|
| `users` | Staff accounts (`admin`, `examiner`) | `id` (bigint PK), `username` (varchar(64) unique, lowercase `CHECK`), `name` (nullable), `email` (nullable unique), `password`, `role` (`'admin' \| 'examiner'`, `CHECK`), `is_active`, `last_login_at`, timestamps |
| `classrooms` | Examiner-managed class entry codes | `id` (uuid PK), `examiner_id` (FK -> `users.id`, restrict delete), `name`, `join_code` (unique), `is_open`, `code_expires_at`, timestamps |
| `student_sessions` | Kahoot-style student sessions (no `users` row) | `id` (uuid PK), `classroom_id` (FK -> `classrooms.id`, cascade delete), `display_name`, `normalized_display_name`, `ip_address` (`inet`), `user_agent`, `joined_at`, `last_seen_at`, `expires_at`, `revoked_at`; partial unique index `(classroom_id, normalized_display_name) WHERE revoked_at IS NULL` |
| `audit_logs` | Immutable append-only security & domain audit log | `id` (uuid PK), `actor_type`, `actor_id`, `actor_label`, `event_type`, `target_type`, `target_id`, `metadata` (`jsonb`), `ip_address` (`inet`), `user_agent`, `request_id` (`uuid`), `created_at`; `BEFORE UPDATE OR DELETE` trigger `audit_logs_prevent_update_delete` |
| `sessions`, `cache`, `jobs`, `personal_access_tokens`, `migrations` | Laravel runtime & framework tables | Standard Laravel schemas |

### Phase 2–4 Exam, Attempt, and Grading Tables

| Table | Purpose | Key columns & constraints |
|---|---|---|
| `sql_datasets` | Reusable MariaDB DDL/DML datasets | `id` (uuid PK), `owner_id` (FK -> `users.id`, restrict delete), `name` (varchar 120), `description` (nullable), `schema_sql` (text), `seed_sql` (text nullable), `schema_summary` (`jsonb` nullable), `is_shared` (bool default `false`), `validated_at` (nullable), timestamps; indexes `(owner_id, created_at)`, `(is_shared)` |
| `exams` | Exam blueprints | `id` (uuid PK), `examiner_id` (FK -> `users.id`, restrict delete), `dataset_id` (uuid nullable FK -> `sql_datasets.id`, restrict delete), `title` (varchar 160), `description`, `instructions`, `status` (`'draft' \| 'published' \| 'unpublished' \| 'archived'` `CHECK`), `duration_minutes` (`CHECK > 0 AND <= 480`), `passing_score_percent` (`numeric(5,2)`, `CHECK 0..100`), `total_points` (`numeric(7,2)`), `settings` (`jsonb`), `current_version_number` (int default `0`), `published_at`, `unpublished_at`, `archived_at`, timestamps; indexes `(examiner_id, status)`, `(status, published_at)` |
| `exam_questions` | Ordered questions on an exam | `id` (uuid PK), `exam_id` (FK -> `exams.id`, cascade delete), `position` (`CHECK >= 1`), `question_type` (`'sql_select' \| 'sql_dml' \| 'sql_ddl' \| 'sql_debug' \| 'short_answer' \| 'mcq'` `CHECK`), `grading_mode` (`'auto' \| 'manual' \| 'hybrid'` `CHECK`), `title`, `prompt`, `points` (`numeric(6,2)`, `CHECK > 0`), `starter_sql`, `reference_sql` (never exposed to students), `comparison_config` (`jsonb`), `rubric` (`jsonb`), timestamps; unique `(exam_id, position)` |
| `question_test_cases` | Weighted SQL evaluation cases | `id` (uuid PK), `question_id` (FK -> `exam_questions.id`, cascade delete), `name`, `position` (`CHECK >= 1`), `weight_points` (`numeric(6,2)`, `CHECK > 0`), `is_hidden` (bool default `false`), `override_seed_sql`, `assertion_sql`, `expected_behavior` (`'match_reference' \| 'expect_error' \| 'non_empty'` `CHECK`), timestamps; unique `(question_id, position)` |
| `question_options` | MCQ answer options | `id` (uuid PK), `question_id` (FK -> `exam_questions.id`, cascade delete), `position` (`CHECK >= 1`), `label`, `is_correct` (bool default `false`, never exposed to students), timestamps; unique `(question_id, position)` |
| `exam_versions` | Immutable published JSONB exam snapshots | `id` (uuid PK), `exam_id` (FK -> `exams.id`, restrict delete), `version_number` (`CHECK >= 1`), `snapshot` (`jsonb`), `published_by` (FK -> `users.id`, restrict delete), `published_at` (immutable); unique `(exam_id, version_number)` |
| `classroom_exams` | Decoupled exam-to-classroom assignment | `id` (uuid PK), `classroom_id` (FK -> `classrooms.id`, cascade delete), `exam_id` (FK -> `exams.id`, restrict delete), `exam_version_id` (FK -> `exam_versions.id`, restrict delete), `is_active`, `available_from`, `available_until`, `results_released_at`, `assigned_by` (FK -> `users.id`, restrict delete), timestamps; unique `(classroom_id, exam_id)` |
| `exam_attempts` | Student exam attempts pinned to snapshot version | `id` (uuid PK), `classroom_exam_id` (FK -> `classroom_exams.id`, restrict delete), `exam_version_id` (FK -> `exam_versions.id`, restrict delete), `student_session_id` (FK -> `student_sessions.id`, restrict delete), `status` (`'in_progress' \| 'submitted' \| 'auto_submitted' \| 'force_closed'` `CHECK`), `grading_status` (`'pending' \| 'auto_graded' \| 'needs_review' \| 'finalized'` `CHECK`), `started_at`, `expires_at`, `submitted_at`, `question_order` (`jsonb`), `auto_score`, `manual_score`, `final_score`, `max_score`, `passed`, `graded_by` (nullable FK -> `users.id`, restrict delete), `graded_at`, timestamps; unique `(classroom_exam_id, student_session_id)`; indexes `(classroom_exam_id, status)`, `(grading_status)` |
| `attempt_answers` | Per-question student answers, test results, and grading | `id` (uuid PK), `attempt_id` (FK -> `exam_attempts.id`, cascade delete), `question_id` (uuid inside snapshot), `submitted_sql`, `submitted_text`, `selected_option_ids` (`jsonb`), `submitted_at` (nullable timestamp locking per-question edits and query execution once confirmed), `auto_score`, `manual_score`, `final_score`, `max_points`, `grading_status` (`'unanswered' \| 'auto_graded' \| 'needs_review' \| 'graded'` `CHECK`), `test_results` (`jsonb`), `rubric_scores` (`jsonb`), `examiner_feedback`, `override_reason`, `graded_by` (nullable FK -> `users.id`, restrict delete), `graded_at`, timestamps; unique `(attempt_id, question_id)` |
| `attempt_query_runs` | Telemetry for live student SQL runs | `id` (uuid PK), `attempt_id` (FK -> `exam_attempts.id`, cascade delete), `question_id` (uuid), `sql_text`, `status` (`'succeeded' \| 'sql_error' \| 'timeout' \| 'policy_blocked' \| 'system_error'` `CHECK`), `row_count`, `execution_ms`, `error_message`, `created_at`; index `(attempt_id, question_id, created_at)` |

### Relationships & integrity rules

- `exam_versions` rejects Eloquent `updating` and `deleting` operations once persisted, keeping snapshots immutable even if the parent `sql_datasets` or cloned `exams` draft changes later.
- `exams.dataset_id` uses `ON DELETE RESTRICT`; deleting an in-use dataset returns `409` (`dataset_in_use`).
- `exam_questions` reorder executes as a deferred/two-step transactional update to avoid `(exam_id, position)` unique constraint collisions.
- `audit_logs` enforces append-only integrity via both Eloquent model hooks and the PostgreSQL `prevent_audit_logs_mutation()` trigger.

## MariaDB: Ephemeral Sandboxes (`mariadb_provisioner` + `mariadb_runner`)

Connections configured in `backend/sql-proctor-api/config/database.php` targeting `127.0.0.1:${MARIADB_PORT}` (`3306` default / CI, `3307` locally when XAMPP holds `3306`):

1. **`mariadb_provisioner` (`sql_provisioner`):** Scoped to `sbx_%.*` with schema-level privileges and `GRANT OPTION` only. Creates ephemeral `sbx_<32-hex>` databases, provisions examiner `schema_sql` + `seed_sql`, grants question-scoped privileges on `sbx_<32-hex>.*` to `sql_runner`, revokes them in `finally`, and drops `sbx_<32-hex>`. Cannot access `mysql.*`, `sys.*`, `performance_schema.*`, or `sql_exec.*`.
2. **`mariadb_runner` (`sql_runner`):** Hardened to `GRANT USAGE ON *.* WITH MAX_USER_CONNECTIONS 30 MAX_STATEMENT_TIME 5` with zero baseline database grants (`sql_exec.*` revoked). Receives temporary privileges solely on the active `sbx_<32-hex>` schema (`SELECT` for `sql_select`/`sql_debug`, DML for `sql_dml`, DDL+DML for `sql_ddl`) and executes untrusted student SQL with `PDO::MYSQL_ATTR_MULTI_STATEMENTS = false`, `PDO::MYSQL_ATTR_LOCAL_INFILE = false`, `max_statement_time = 2` s, `500`-row cap, and `1 MiB` result cap.
3. **Orphan recovery:** `php artisan sandbox:cleanup-orphans` revokes any leftover `sbx_%` schema grants and drops stale `sbx_*` databases.

## Storage and lifecycle

| Item | Value |
|---|---|
| PostgreSQL volume | `sql-proctor_postgres_data` |
| MariaDB volume | `sql-proctor_mariadb_data` |
| Survives `docker compose down` | Yes |
| Deleted by `docker compose down -v` | Yes. Destructive. |
| Local credentials | `infra/.env`, `backend/sql-proctor-api/.env` (gitignored) |

## Related documents

- [architecture.md](architecture.md)
- [api-specification.md](api-specification.md)
- [SPEC-0005](specs/SPEC-0005-exam-authoring-and-student-examination.md)
- [ADR-0003](adr/0003-mariadb-sql-execution-isolation-and-exam-versioning.md)
- `infra/README.md`
