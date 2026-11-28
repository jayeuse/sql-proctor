# Test matrix: Authentication, Authorization, and Audit Logging Modules

| Field | Value |
|---|---|
| Spec | `SPEC-0004` at [SPEC-0004-auth-rbac-and-audit-logging.md](../specs/SPEC-0004-auth-rbac-and-audit-logging.md) |
| Related task | Phase 1: Auth, RBAC, Student Session Entry, and Audit Logging |
| Test runner | PHPUnit 12.5.44 (PostgreSQL `sql_proctor_test`), Vitest 5.1.2 + RTL 16.3.3, & Playwright 1.65.1 (Chromium) |
| Date | 2026-11-28 |

## Negative-first ordering

Cases follow the fixed 14-category order in [negative-first-testing](../../.agents/skills/negative-first-testing/SKILL.md):
1. Invalid input -> 2. Security violation -> 3. Unauthorized access -> 4. Forbidden access -> 5. Invalid state transition -> 6. Missing resource -> 7. Duplicate operation -> 8. Boundary condition -> 9. Concurrency -> 10. Infrastructure failure -> 11. Timeout -> 12. Resource exhaustion -> 13. Data integrity failure -> 14. Normal behavior.

## Matrix

| Test ID | Requirement | Scenario | Input | Expected result | Test type | Priority | Status |
|---|---|---|---|---|---|---|---|
| TM-1 | FR-3, FR-6, FR-7, FR-8, FR-12 | Invalid input rejected across auth, examiner, classroom, student join, and audit log filter endpoints | Missing/malformed `username`, `<12` char password, invalid `role`/`actor_type`/`from`/`to` dates, bad `display_name` characters or `class_code` | `422` with `errors` map; zero rows created | Feature | High | PASS |
| TM-2 | FR-3, NFR-2 | Login failure returns generic `401` without revealing account existence/inactive status and omits password from `audit_logs` | Unknown `username`, wrong `password`, and inactive examiner | Identical `401` (`invalid_credentials`), `auth.login_failed` row with no secret/password | Feature | Critical | PASS |
| TM-3 | FR-8, FR-9, NFR-3 | Tampered `student_session_id` cookie and missing CSRF token on auth or student mutations are rejected | Tampered cookie value on `/api/student/session`; stateful `POST /api/auth/login`, `/api/student/join`, `/api/student/leave` without `X-XSRF-TOKEN` | `401` on tampered cookie; `419` on missing CSRF token | Feature | Critical | PASS |
| TM-4 | FR-10, NFR-2 | `AuditLogger` recursively redacts sensitive keys before PostgreSQL write | Nested `password`, `password_confirmation`, `current_password`, `token`, `cookie`, `authorization`, `xsrf`, `secret` in metadata | Stored `jsonb` contains `"[REDACTED]"` and zero raw secret strings | Feature | Critical | PASS |
| TM-5 | FR-5, FR-6, FR-7, FR-9, FR-12 | Unauthenticated requests to all protected routes return `401` | No session/cookie on `/api/user`, `/api/admin/examiners`, `/api/audit-logs`, `/api/examiner/classes`, `/api/student/session`, `/api/student/leave` | `401` `{"message": "Unauthenticated."}` + `X-Request-Id` | Feature | Critical | PASS |
| TM-6 | FR-4, FR-5, FR-6 | Inactive or logged-out staff sessions are rejected immediately | Deactivated examiner or invalidated session calling `/api/user`; password-reset examiner old session | `401` `{"message": "Unauthenticated."}` | Feature | Critical | PASS |
| TM-7 | FR-6, FR-7, FR-9, FR-12, NFR-4 | Cross-role forbidden access denied (`403`/`401`) | Examiner calling `/api/admin/*` and `/api/audit-logs` (`403`); Student calling `/api/user`, `/api/admin/*`, `/api/examiner/*`, `/api/audit-logs` (`401`); Admin calling `/api/examiner/*` (`403`) | Access refused with standard JSON envelope; no data leaked | Feature | Critical | PASS |
| TM-8 | FR-6 | Admin cannot deactivate or reset password of self or another admin via examiner endpoints | `PATCH` / `reset-password` targeting self or another `admin` | `403` (`cannot_modify_admin`); target unchanged | Feature | High | PASS |
| TM-9 | FR-7 | Examiner cannot access or mutate another examiner's classroom | Examiner B sends `GET`/`open`/`close`/`regenerate-code` on Examiner A's classroom | `403`; classroom unchanged | Feature | High | PASS |
| TM-10 | FR-8, FR-9 | Student rejected from closed or expired classroom | Join closed or expired `class_code`; `GET /api/student/session` after class closed | `422` on join + `student.join_failed`; `403` (`class_closed`) on active session | Feature | High | PASS |
| TM-11 | FR-9 | Expired or revoked `student_session_id` cookie is rejected | `student_session_id` past `expires_at` or after `POST /api/student/leave` | `401` `{"message": "Unauthenticated."}` | Feature | High | PASS |
| TM-12 | FR-6, FR-7, FR-8 | Missing examiner, oversized examiner ID, missing classroom, or unknown `class_code` returns `404` without model leaks | Unknown integer ID, >18-digit integer ID, unknown UUID, or non-existent `class_code` | `404` without model class leaks; `student.join_failed` logged on join | Feature | High | PASS |
| TM-13 | FR-8 | Duplicate active `display_name` in classroom returns `409` (`display_name_taken`) and duplicate `username` returns `422` | Case-variant active `display_name` in same open class; existing `username` on create examiner | `409` (`display_name_taken`) + `student.join_failed`; `422` on duplicate `username` | Feature | High | PASS |
| TM-14 | FR-2 | `app:bootstrap-admin` run twice is idempotent | Execute `app:bootstrap-admin` twice | Exit `0` both times; 1 admin total; password unchanged; 1 `admin.bootstrap_completed` audit row | Feature | Critical | PASS |
| TM-15 | FR-3 | Login rate limiter locks out at 6th attempt within 1 minute | 6 failed `POST /api/auth/login` calls for same `username + IP` | 6th returns `429` (`too_many_attempts`) + `Retry-After` + `auth.lockout` | Feature | High | PASS |
| TM-16 | FR-8 | Student join rate limiter locks out at 11th failed attempt within 1 minute per IP (`60/min` burst cap) | 11 failed `POST /api/student/join` calls from same IP | 11th returns `429` (`too_many_attempts`) + `Retry-After` + `auth.lockout` | Feature | High | PASS |
| TM-17 | FR-1, FR-8, FR-12 | Boundary checks for username/display_name lengths, pagination bounds, and expired-session display name reuse | Exact min/max lengths, `per_page=1`/`200`, expired session name reuse | Accepted within bounds, rejected outside bounds; expired name freed on next join | Feature | Medium | PASS |
| TM-18 | FR-8 | Transactional classroom lock + PostgreSQL partial unique index guard concurrent duplicate `display_name` joins | Classroom `lockForUpdate()` transaction + unique partial index `WHERE revoked_at IS NULL` | Duplicate active join rejected `409`; DB unique constraint rejects second insert | Feature | Medium | PASS |
| TM-19 | FR-11 | Eloquent `AuditLog::update()` and `AuditLog::delete()` throw domain exception | `$log->update(...)`, `$log->delete()` | Throws `ImmutableAuditLogException`; row intact | Feature | Critical | PASS |
| TM-20 | FR-11 | PostgreSQL trigger blocks raw SQL `UPDATE` and `DELETE` on `audit_logs` | `DB::statement('UPDATE audit_logs ...')` and `DELETE FROM audit_logs` | Throws `QueryException` from trigger; row intact | Feature (PostgreSQL) | Critical | PASS |
| TM-21 | FR-1, FR-7, FR-8 | PostgreSQL constraints enforce lowercase `username`, valid `role`, unique `join_code` | Direct DB insert violating constraint | Throws `QueryException` | Feature (PostgreSQL) | High | PASS |
| TM-22 | FR-10 | Non-UUID inbound `X-Request-Id` does not break `audit_logs.request_id` UUID column | Header `X-Request-Id: req_custom-123` on audited endpoint | Request succeeds, response echoes `req_custom-123`, `audit_logs.request_id` holds valid UUID | Feature | High | PASS |
| TM-23 | FR-2 | `app:bootstrap-admin` creates active admin and logs `admin.bootstrap_completed` | Fresh empty DB + `app:bootstrap-admin` | Exit `0`, credentials printed once to stdout, admin active, audit logged | Feature | Critical | PASS |
| TM-24 | FR-3, FR-4, FR-5 | Staff login rotates session, sets `last_login_at`, accesses `/api/user`, logs out cleanly | Valid `admin` / `examiner` login, `/api/user`, `/api/auth/logout` | `200`, session ID changed, `auth.login_succeeded` + `auth.logout` recorded | Feature | Critical | PASS |
| TM-25 | FR-6 | Admin examiner CRUD lifecycle & audit completeness | List/filter, create (supplied + generated password), update, deactivate, reset password | Exact JSON envelopes, one-time `generated_password`, session invalidation, accurate audit rows | Feature | Critical | PASS |
| TM-26 | FR-7 | Examiner classroom lifecycle & unambiguous 8-char code generation | Create, list, open, close, regenerate-code | Valid 8-char unambiguous codes, accurate counts and audit rows | Feature | High | PASS |
| TM-27 | FR-8, FR-9 | Student Kahoot join, session check, and leave lifecycle | Valid `display_name` + `class_code`, `GET /api/student/session`, `POST /api/student/leave` | `201`, encrypted HTTP-only cookie, `200` session, `200` leave + cookie expired + audits | Feature | Critical | PASS |
| TM-28 | FR-12 | Admin `GET /api/audit-logs` filtering and newest-first pagination | Filters (`actor_type`, `actor_id`, `event_type`, `target_type`, `target_id`, `from`, `to`) + `per_page` | Accurate filtered slices in `created_at DESC, id DESC` order | Feature | High | PASS |
| TM-29 | FR-13 | Frontend components cover invalid input, `401`/`403`/`409`/`429`/`500`, pagination, date filters, action busy locks, paste normalization, CSRF `419` retry, and success states | Vitest + RTL + MSW tests for API retry, login, join, admin, examiner, student views | 20/20 assertions pass with accessible error/status feedback | Component (Vitest) | High | PASS |
| TM-30 | FR-13, NFR-5 | Frontend lint, production build, and live Playwright E2E desktop/mobile verification | `npm run lint && npm run build && npm run test:e2e` across `/`, `/join`, `/login`, `/admin`, `/examiner`, `/student` (`1280x800` and `375x812`) | Lint & build exit `0`; `11/11` Playwright E2E journeys pass | Build / E2E | High | PASS |

## Evidence

| Test ID | Command | Working directory | Exit status | Artifact | Time (UTC) |
|---|---|---|---|---|---|
| TDD Red (Backend) | `PATH="/opt/homebrew/bin:$PATH" php artisan test --filter="AuditLoggingAndImmutabilityTest\|BootstrapAdminCommandTest\|StaffAuthenticationTest\|AdminExaminerManagementTest\|ExaminerClassroomTest\|StudentSessionTest"` | `backend/sql-proctor-api` | 0 (41 failed, 1 passed) | `.agent-runtime/archive/2026/2026-11-28-auth-rbac-audit/evidence/02-tdd-red.txt` | `2026-11-28 00:34` |
| TDD Red (Frontend) | `npm test` | `frontend/sql-proctor-ui` | 0 (5 suites failed import) | `.agent-runtime/archive/2026/2026-11-28-auth-rbac-audit/evidence/03-frontend-tdd-red.txt` | `2026-11-28 00:40` |
| TM-1 .. TM-28 | `PATH="/opt/homebrew/bin:$PATH" ./vendor/bin/pint --test && PATH="/opt/homebrew/bin:$PATH" php artisan test` | `backend/sql-proctor-api` | 0 (95 passed, 579 assertions) | `.agent-runtime/archive/2026/2026-11-28-auth-rbac-audit/RUN.md` (`E-07`) | `2026-11-28 01:12` |
| TM-29, TM-30 | `npm test && npm run lint && npm run test:e2e` | `frontend/sql-proctor-ui` | 0 (20 Vitest passed, ESLint clean, Next build clean, 11/11 Playwright E2E passed) | `.agent-runtime/archive/2026/2026-11-28-auth-rbac-audit/RUN.md` (`E-09`) | `2026-11-28 01:29` |
