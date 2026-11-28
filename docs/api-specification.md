# SQL-Proctor API Specification

> Status: Updated for SPEC-0005 (Exam Authoring, Isolated MariaDB SQL Execution, Student Examination, and Grading) on 2026-11-28.

## Base URL

| Environment | URL |
|---|---|
| Local | `http://localhost:8000` |
| API prefix | `/api` |

The frontend reads the base URL from `NEXT_PUBLIC_API_URL`, defaulting to `http://localhost:8000/api`.

## Conventions

1. Requests and responses use JSON. Send `Accept: application/json`.
2. Any request matching `/api/*` or sending `Accept: application/json` always renders JSON and never redirects to a `login` route.
3. Timestamps are ISO 8601 in UTC.
4. Staff identifiers (`users.id`) are integers; classroom, student session, audit log, dataset, exam, question, test case, option, version, classroom assignment, attempt, answer, and query run identifiers are UUIDv4 strings.
5. Staff authentication uses Laravel Sanctum SPA stateful cookie auth (`web` guard). Student entry uses an encrypted, signed, HTTP-only, `SameSite=Lax` cookie (`student_session_id`).
6. CORS allows `http://localhost:3000` with `supports_credentials = true` and exposes `X-Request-Id` and `Retry-After`.
7. Single resources return `{"data": {...}}` (`200` / `201`). Paginated collections return `{"data": [...], "links": {...}, "meta": {...}}` (`200`). Message-only successes return `{"message": "..."}` (`200`). Empty successes return `204`.
8. Coded domain errors carry a stable `code` field alongside `message` (and validation errors include `errors`, plus `issues` on `exam_validation_failed`).
9. Every `/api/*` response includes an `X-Request-Id` header matching `^[A-Za-z0-9._-]{1,64}$`.
10. Unhandled `500` responses return `{"message": "Server error."}` in every environment. MariaDB engine unavailability returns `503` `{"message": "SQL execution service is temporarily unavailable.", "code": "execution_engine_unavailable"}`.

## Authentication & Session Endpoints

| Method | Path | Auth | Notes |
|---|---|---|---|
| `GET` | `/sanctum/csrf-cookie` | Public | Issues `XSRF-TOKEN` + session cookies (`204`). |
| `POST` | `/api/auth/login` | Public (stateful + CSRF, `5/min`) | `200` `UserResource` or generic `401` (`invalid_credentials`). |
| `POST` | `/api/auth/logout` | `auth:sanctum`, `active` | Invalidates session, `200` `{"message": "Logged out."}`. |
| `GET` | `/api/user` | `auth:sanctum`, `active` | `200` `UserResource`; deactivated accounts return `401`. |

## Admin Endpoints (`role:admin`)

All routes require `auth:sanctum`, `active`, and `role:admin`.

| Method | Path | Purpose | Request / Query | Response |
|---|---|---|---|---|
| `GET` | `/api/admin/examiners` | Paginated examiner list | `page`, `per_page`, `is_active`, `username` | `200` paginated `UserResource` |
| `POST` | `/api/admin/examiners` | Create examiner | `username`, optional `name`, `password` | `201` `UserResource` (+ one-time `generated_password`) |
| `PATCH` | `/api/admin/examiners/{examiner}` | Update examiner status/name | `name`, `is_active` | `200` `UserResource` (`403` `cannot_modify_admin`) |
| `POST` | `/api/admin/examiners/{examiner}/reset-password` | Reset password & purge sessions | Optional `password` | `200` `UserResource` (+ one-time `generated_password`) |
| `GET` | `/api/audit-logs` | Paginated audit logs | `page`, `per_page`, `actor_type`, `actor_id`, `event_type`, `target_type`, `target_id`, `from`, `to` | `200` paginated `AuditLogResource` |
| `GET` | `/api/admin/exams` | Cross-examiner exam oversight list | `page`, `per_page`, `status`, `examiner_id`, `search` | `200` paginated `ExamResource` |
| `GET` | `/api/admin/exams/{exam}` | Read-only exam oversight detail | — | `200` `ExamResource` (with questions, dataset, assignments) |
| `POST` | `/api/admin/exams/{exam}/force-unpublish` | Emergency audited unpublish | `reason` (required `min:5`), optional `force_close_active` (bool default `true`) | `200` `ExamResource` (`exam.force_unpublished` audited) |
| `GET` | `/api/admin/datasets` | List shared/all datasets | `page`, `per_page`, `scope`, `search` | `200` paginated `SqlDatasetResource` |
| `POST` | `/api/admin/datasets` | Create shared institutional dataset | `name`, `description`, `schema_sql`, `seed_sql`, optional `is_shared` (default `true`) | `201` `SqlDatasetResource` |
| `GET` | `/api/admin/datasets/{dataset}` | Inspect dataset | — | `200` `SqlDatasetResource` |
| `PATCH` | `/api/admin/datasets/{dataset}` | Update dataset | Partial fields | `200` `SqlDatasetResource` |
| `DELETE` | `/api/admin/datasets/{dataset}` | Delete unreferenced dataset | — | `204` (`409` `dataset_in_use` if referenced by exam) |
| `POST` | `/api/admin/datasets/{dataset}/validate` | Validate dataset in MariaDB sandbox | — | `200` `SqlDatasetResource` (`422` `dataset_validation_failed` on SQL error) |

## Examiner Endpoints (`role:examiner`)

All routes require `auth:sanctum`, `active`, `role:examiner`, and resource ownership policies (`ClassroomPolicy`, `SqlDatasetPolicy`, `ExamPolicy`, `ExamAttemptPolicy`).

### Classrooms

| Method | Path | Purpose | Response |
|---|---|---|---|
| `GET` | `/api/examiner/classes` | Paginated own classrooms | `200` paginated `ClassroomResource` |
| `POST` | `/api/examiner/classes` | Create classroom + join code | `201` `ClassroomResource` |
| `GET` | `/api/examiner/classes/{classroom}` | Inspect own classroom | `200` `ClassroomResource` |
| `POST` | `/api/examiner/classes/{classroom}/open` | Open classroom | `200` `ClassroomResource` |
| `POST` | `/api/examiner/classes/{classroom}/close` | Close classroom | `200` `ClassroomResource` |
| `POST` | `/api/examiner/classes/{classroom}/regenerate-code` | Rotate join code | `200` `ClassroomResource` |

### Datasets

| Method | Path | Purpose | Query / Body | Response |
|---|---|---|---|---|
| `GET` | `/api/examiner/datasets` | List datasets | `page`, `per_page`, `scope=mine\|shared\|all`, `search` | `200` paginated `SqlDatasetResource` |
| `POST` | `/api/examiner/datasets` | Create owned dataset | `name`, `description`, `schema_sql`, `seed_sql` | `201` `SqlDatasetResource` |
| `GET` | `/api/examiner/datasets/{dataset}` | View owned or shared dataset | — | `200` `SqlDatasetResource` (`403` for private unowned) |
| `PATCH` | `/api/examiner/datasets/{dataset}` | Update owned dataset (clears `validated_at` if SQL changed; snapshots unaffected) | Partial fields | `200` `SqlDatasetResource` (`403` if shared/unowned) |
| `DELETE` | `/api/examiner/datasets/{dataset}` | Delete owned dataset | — | `204` (`409` `dataset_in_use` if used by any exam) |
| `POST` | `/api/examiner/datasets/{dataset}/validate` | Dry-run DDL+seed in MariaDB & parse `schema_summary` | — | `200` `SqlDatasetResource` (`422` `dataset_validation_failed` on MariaDB error) |

### Exams, Questions, Lifecycle, and Assignments

| Method | Path | Purpose | Query / Body | Response |
|---|---|---|---|---|
| `GET` | `/api/examiner/exams` | List owned exams | `page`, `per_page`, `status`, `search` | `200` paginated `ExamResource` |
| `POST` | `/api/examiner/exams` | Create draft exam | `title`, `description`, `instructions`, `dataset_id`, `duration_minutes`, `passing_score_percent`, `settings` | `201` `ExamResource` |
| `GET` | `/api/examiner/exams/{exam}` | Full exam detail | — | `200` `ExamResource` (questions, test cases, options, assignments, counts) |
| `PATCH` | `/api/examiner/exams/{exam}` | Update editable exam (`draft` or zero-attempt `unpublished`) | Partial exam fields | `200` `ExamResource` (`409` `exam_locked` otherwise) |
| `DELETE` | `/api/examiner/exams/{exam}` | Delete zero-attempt `draft` exam | — | `204` (`409` `exam_locked` otherwise) |
| `POST` | `/api/examiner/exams/{exam}/questions` | Add question (with nested `test_cases`, `options`, `rubric`) | Question payload | `201` `ExamQuestionResource` (`409` `exam_locked` if locked) |
| `PATCH` | `/api/examiner/exams/{exam}/questions/{question}` | Update question (replaces nested `test_cases`/`options` when supplied) | Partial question payload | `200` `ExamQuestionResource` (`409` `exam_locked` if locked) |
| `DELETE` | `/api/examiner/exams/{exam}/questions/{question}` | Delete question & recompute `total_points` | — | `204` (`409` `exam_locked` if locked) |
| `PUT` | `/api/examiner/exams/{exam}/questions/reorder` | Transactionally reorder questions | `{"question_ids": ["uuid", ...]}` | `200` ordered questions (`409` `exam_locked`, `422` if mismatch) |
| `POST` | `/api/examiner/exams/{exam}/validate` | Run structural + live MariaDB dry-run checklist | — | `200` `{"data": {"valid": true, "issues": []}}` or `422` (`exam_validation_failed`) |
| `POST` | `/api/examiner/exams/{exam}/publish` | Validate, snapshot immutable `exam_versions`, publish | — | `200` `ExamResource` (`422` `exam_validation_failed`, `409` `exam_locked` if archived) |
| `POST` | `/api/examiner/exams/{exam}/unpublish` | Unpublish exam | `{"force_close_active": false}` | `200` `ExamResource` (`409` if not published) |
| `POST` | `/api/examiner/exams/{exam}/archive` | Permanently archive exam | — | `200` `ExamResource` |
| `POST` | `/api/examiner/exams/{exam}/clone` | Deep-clone exam + questions + test cases + options into `draft` | — | `201` `ExamResource` |
| `GET` | `/api/examiner/exams/{exam}/classrooms` | List classroom assignments for exam | — | `200` `{"data": [...]}` |
| `POST` | `/api/examiner/exams/{exam}/classrooms` | Assign published exam to owned classroom | `classroom_id`, `is_active`, `available_from`, `available_until` | `201` `ClassroomExamResource` (`422` if not published / duplicate) |
| `PATCH` | `/api/examiner/exams/{exam}/classrooms/{classroom}` | Update assignment window, active state, or `release_results: true` | `is_active`, `available_from`, `available_until`, `release_results` | `200` `ClassroomExamResource` |
| `DELETE` | `/api/examiner/exams/{exam}/classrooms/{classroom}` | Remove zero-attempt assignment | — | `204` (`409` `assignment_has_attempts` if attempts exist) |

### Examiner Grading

| Method | Path | Purpose | Query / Body | Response |
|---|---|---|---|---|
| `GET` | `/api/examiner/exams/{exam}/attempts` | Paginated attempt list for exam | `page`, `per_page`, `classroom_id`, `status`, `grading_status` | `200` paginated `ExamAttemptSummaryResource` |
| `GET` | `/api/examiner/attempts/{attempt}` | Full grading detail (answers, reference SQL, all test results, query runs, rubric) | — | `200` `ExaminerAttemptDetailResource` |
| `PATCH` | `/api/examiner/attempts/{attempt}/answers/{answer}/grade` | Grade manual/hybrid answer or override `auto` score (mandatory `override_reason`) | `manual_score`, `rubric_scores`, `examiner_feedback`, `override_reason` | `200` `AttemptAnswerResource` (`422` if override reason missing on auto) |
| `POST` | `/api/examiner/attempts/{attempt}/finalize` | Finalize grading & compute `final_score` / `passed` | — | `200` `ExaminerAttemptDetailResource` (`422` if `needs_review` answers remain) |

## Student Endpoints (`VerifyStudentSession` + `EnsureStatefulAuthSession`)

| Method | Path | Auth | Purpose | Response |
|---|---|---|---|---|
| `POST` | `/api/student/join` | Public (CSRF, `10/min`) | Join classroom | `201` `StudentSessionResource` + cookie |
| `GET` | `/api/student/session` | `student.session` | Current session & class | `200` `StudentSessionResource` |
| `POST` | `/api/student/leave` | `student.session:allow-closed` | Revoke session & clear cookie | `200` `{"message": "Left class."}` |
| `GET` | `/api/student/exams` | `student.session` | List assigned active/attempted exams in current classroom | `200` `{"data": StudentExamResource[]}` |
| `POST` | `/api/student/exams/{classroomExam}/start` | `student.session` | Start or idempotently resume (`200`) attempt | `201` / `200` `StudentAttemptResource` (`409` `attempt_already_submitted`) |
| `GET` | `/api/student/attempts/{attempt}` | `student.session` + owner | Resume exam state (auto-submits if expired) | `200` `StudentAttemptResource` (strictly anti-leakage) |
| `PUT` | `/api/student/attempts/{attempt}/answers/{questionId}` | `student.session` + owner | Save draft answer or lock with `submit_answer: true` (`submitted_sql` / `submitted_text` / `selected_option_ids`) | `200` `{"data": ...}` (`409` `answer_locked` / `attempt_expired` / `attempt_not_active`) |
| `POST` | `/api/student/attempts/{attempt}/questions/{questionId}/run` | `student.session` + owner (`20/min`) | Execute SQL in ephemeral MariaDB sandbox (blocked if answer submitted) | `200` `{ columns, rows, row_count, truncated, execution_ms, status, error }` (`409` `answer_locked` / `429` / `503`) |
| `POST` | `/api/student/attempts/{attempt}/submit` | `student.session` + owner | Submit attempt and trigger grading | `200` `StudentAttemptResource` (`409` if already submitted/expired) |
| `GET` | `/api/student/attempts/{attempt}/result` | `student.session` + owner | View released scores & visible test outcomes | `200` `StudentResultResource` (`409` if still `in_progress`) |

## Pagination & Error Responses

- Standard pagination parameters (`page >= 1`, `per_page 1..200`, default `50`) and offset paginator JSON shape apply identically to `SPEC-0003`.
- Coded `409` errors: `display_name_taken`, `exam_locked`, `exam_not_published`, `exam_not_available`, `dataset_in_use`, `assignment_has_attempts`, `attempt_already_submitted`, `attempt_still_in_progress`, `attempt_not_active`, `attempt_expired`, `answer_locked`, `execution_in_progress`, `exam_not_editable`.
- Coded `422` domain errors: `exam_validation_failed` (with `issues` + `errors`), `dataset_validation_failed` (with `errors`).
- Coded `503` error: `execution_engine_unavailable` (`{"message": "SQL execution service is temporarily unavailable.", "code": "execution_engine_unavailable"}`).

## Related documents

- [architecture.md](architecture.md)
- [database-design.md](database-design.md)
- [SPEC-0003](specs/SPEC-0003-api-response-envelope-and-pagination.md)
- [SPEC-0004](specs/SPEC-0004-auth-rbac-and-audit-logging.md)
- [SPEC-0005](specs/SPEC-0005-exam-authoring-and-student-examination.md)
