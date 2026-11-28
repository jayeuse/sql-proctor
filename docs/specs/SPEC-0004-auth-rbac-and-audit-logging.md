# Specification: Authentication, Authorization, and Audit Logging Modules

| Field | Value |
|---|---|
| Spec ID | SPEC-0004 |
| Title | Authentication, Authorization, and Audit Logging Modules |
| Status | Verified (95 PHPUnit PostgreSQL tests, Pint, 20 Vitest tests, ESLint, Next build, and 11 Chromium Playwright E2E journeys passed) |
| Owner | parent |
| Reviewer | Human Owner |
| Related task | Phase 1: Auth, RBAC, Student Session Entry, and Audit Logging |
| Date | 2026-11-28 |

## Objective

SQL-Proctor enforces role-separated authentication and authorization across three actors (`admin`, `examiner`, and Kahoot-style `student`) backed exclusively by PostgreSQL 17, provides admin examiner-account management and examiner class-code management, records all security-relevant events in an immutable append-only `audit_logs` table in PostgreSQL 17, and exposes clean Next.js 16 App Router pages for each role.

## Problem

Today, the repository has only the Phase 0 scaffold and `SPEC-0003` universal JSON envelope/pagination foundation (`backend/sql-proctor-api/`):

1. `users` (`0001_01_01_000000_create_users_table.php`) uses `name` + `email` with no `username`, `role`, `is_active`, or `last_login_at`, and `sql_proctor` has zero accounts.
2. Only `GET /api/user` exists in `routes/api.php`; there are no login, logout, admin bootstrap, examiner management, classroom code, student session, or audit logging endpoints or policies.
3. No `classrooms`, `student_sessions`, or `audit_logs` tables exist in PostgreSQL.
4. `frontend/sql-proctor-ui/` has only the Next.js starter page (`app/page.js`) and `lib/api.js` without `withXSRFToken`, Tailwind CSS v4, or auth/role views.

## Scope

- **In scope:**
  - PostgreSQL migrations for `users` (username/role/active updates), `classrooms`, `student_sessions`, and `audit_logs` (with PostgreSQL `BEFORE UPDATE OR DELETE` immutability trigger).
  - PHP enums (`UserRole`, `ActorType`, `AuditEvent`), `AuditLogger` service with recursive sensitive-key redaction, `ApiResponse` / `ApiException` integration, `AssignRequestId` UUID handling for `audit_logs`.
  - Artisan command `app:bootstrap-admin` (idempotent first-time system admin creation).
  - Staff SPA cookie authentication (`/sanctum/csrf-cookie`, `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/user`) with rate limiting, session regeneration/invalidation, active-user check, and `SANCTUM_STATEFUL_DOMAINS=localhost:3000` / `SESSION_DOMAIN=localhost`.
  - Admin endpoints (`/api/admin/examiners*`, `GET /api/audit-logs`) protected by `auth:sanctum`, active-account enforcement, `role:admin`, and policies.
  - Examiner endpoints (`/api/examiner/classes*`) protected by `auth:sanctum`, active-account enforcement, `role:examiner`, and `ClassroomPolicy`.
  - Student Kahoot-style entry (`/api/student/join`, `GET /api/student/session`, `POST /api/student/leave`) using signed, encrypted, HTTP-only, `SameSite=Lax` `student_session_id` cookie and `VerifyStudentSession` middleware.
  - Next.js 16 App Router UI (`frontend/sql-proctor-ui/`): Axios CSRF/credentials config, Tailwind CSS v4 setup, `/login`, `/` & `/join`, `/admin`, `/examiner`, `/student`, and Vitest + React Testing Library tests.
  - Negative-first PHPUnit 12 suite executed against PostgreSQL (`sql_proctor_test`) and CI PostgreSQL workflow update.
- **Not in scope:**
  - Exam authoring, questions, exam attempts, grading, and MariaDB student SQL execution (deferred to later phases; MariaDB remains untouched).
  - Email verification, self-service registration, password-reset email delivery, Bearer API tokens for login, 2FA, or SSO.
  - Production deployment or containerization of Next.js/Laravel.

## Requirements

### Functional

| ID | Requirement |
|---|---|
| FR-1 | `users` stores `username` (lowercase-normalized, 3–32 chars `^[a-z0-9][a-z0-9._-]{2,31}$`, unique), nullable `name`, nullable `email`, `password` (`hashed` cast), `role` (`admin` or `examiner` via `UserRole` enum), `is_active` (boolean, default `true`), `last_login_at` (nullable timestamp), and timestamps. |
| FR-2 | `php artisan app:bootstrap-admin` creates one active `admin` user (default username `admin`, configurable via `--username`) with a random 20-character password (`Str::password(20)`), prints credentials once to stdout, never writes plaintext password to logs/audit/env, and logs `admin.bootstrap_completed` (`actor_type = 'system'`). Re-running when an active admin exists exits `0` without modifying credentials, creating duplicates, or writing another bootstrap audit row. |
| FR-3 | `POST /api/auth/login` authenticates `username` + `password` over the `web` guard, rejects inactive or invalid accounts with identical `401` (`code: "invalid_credentials"`) and logs `auth.login_failed` without passwords, enforces `5` attempts/minute per normalized `username + IP` returning `429` (`code: "too_many_attempts"`) + `Retry-After` and logging `auth.lockout`, regenerates session ID on success, updates `last_login_at`, logs `auth.login_succeeded`, and returns `{"data": UserResource}`. |
| FR-4 | `POST /api/auth/logout` logs out the `web` guard, invalidates the session, regenerates the CSRF token, logs `auth.logout`, and returns `200` `{"message": "Logged out."}`. |
| FR-5 | `GET /api/user` requires an active authenticated staff user (`auth:sanctum` + active check) and returns `{"data": UserResource}`. Inactive or deactivated staff sessions are rejected with `401` and invalidated. |
| FR-6 | Admin examiner endpoints (`GET /api/admin/examiners`, `POST /api/admin/examiners`, `PATCH /api/admin/examiners/{examiner}`, `POST /api/admin/examiners/{examiner}/reset-password`) require `role:admin` and `UserPolicy`. `GET` supports pagination and filtering by `is_active` and `username`. `POST` creates an examiner with supplied password (`min:12`) or generated random 16-char password returned once as `generated_password`, logging `examiner.created`. `PATCH` updates `name`/`is_active`, forbids modifying admins or self (`403`), terminates active sessions when deactivated, and logs `examiner.deactivated` or `examiner.updated`. `reset-password` forbids admins/self (`403`), sets supplied or generated password returned once, terminates active sessions, and logs `examiner.password_reset`. |
| FR-7 | Examiner classroom endpoints (`GET /api/examiner/classes`, `POST /api/examiner/classes`, `GET /api/examiner/classes/{classroom}`, `POST /api/examiner/classes/{classroom}/open`, `POST /api/examiner/classes/{classroom}/close`, `POST /api/examiner/classes/{classroom}/regenerate-code`) require `role:examiner` and `ClassroomPolicy` (`classroom.examiner_id === user.id`). Join codes are 8 uppercase alphanumeric characters excluding `0/O/1/I/L`. Events `class.created`, `class.opened`, `class.closed`, and `class.code_regenerated` are audited. |
| FR-8 | `POST /api/student/join` accepts `display_name` (trimmed, whitespace-collapsed, 2–50 chars `^[\p{L}\p{N} ._-]+$/u`) and `class_code` (trimmed, uppercased, 6–8 alphanumeric), rate-limited by IP (`10` attempts/minute). Unknown `class_code` returns `404` (`code: "class_not_found"`); closed or expired classroom returns `422` (`errors.class_code`). Duplicate active `display_name` (case-insensitive among non-revoked, non-expired sessions in the same classroom) returns `409` (`code: "display_name_taken"`). Failed joins log `student.join_failed`. Success creates `student_sessions` row, revokes any superseded prior `student_session_id` cookie held by that browser, logs `student.joined_class`, issues signed, encrypted, HTTP-only, `SameSite=Lax` cookie `student_session_id`, and returns `201` `{"data": StudentSessionResource}`. |
| FR-9 | `GET /api/student/session` and `POST /api/student/leave` are protected by `VerifyStudentSession`, which decrypts and validates `student_session_id` cookie signature, verifies non-revoked/non-expired `StudentSession` (`401` otherwise), updates `last_seen_at`, and binds the session to the request. `GET /api/student/session` requires an open classroom (`403` `code: "class_closed"` otherwise). `POST /api/student/leave` permits leaving even after a classroom closes, revokes the session (`revoked_at = now()`), expires the cookie, logs `student.left_class`, and returns `200` `{"message": "Left class."}`. |
| FR-10 | All audit writes go through `App\Services\AuditLogger`, which captures `id` (UUID), `actor_type`, `actor_id`, `actor_label`, `event_type`, `target_type`, `target_id`, redacted `metadata` (`jsonb`), `ip_address` (`inet`), `user_agent`, `request_id` (`uuid`), and immutable `created_at`. Sensitive keys (`password`, `password_confirmation`, `current_password`, `token`, `cookie`, `authorization`, `xsrf`, `secret`) are recursively redacted before persistence. |
| FR-11 | `audit_logs` is strictly append-only at both the Eloquent layer (`updating`/`deleting` throw `ImmutableAuditLogException`) and PostgreSQL 17 layer (`BEFORE UPDATE OR DELETE` trigger raises an exception). |
| FR-12 | `GET /api/audit-logs` is strictly admin-only, uses `PaginatedRequest` (`page`, `per_page` 1..200, default 50), supports filtering by `actor_type`, `actor_id`, `event_type`, `target_type`, `target_id`, `from`, `to` (ISO 8601), validates filter inputs (`422` on invalid), and sorts `created_at DESC, id DESC`. |
| FR-13 | `frontend/sql-proctor-ui/` configures Axios with `withCredentials: true`, `withXSRFToken: true`, and `ensureCsrfCookie()`, and implements `/login`, `/` & `/join`, `/admin` (examiners + audit logs), `/examiner` (class code management), and `/student` (active session + leave) styled with Tailwind CSS v4. |

### Non-functional

| ID | Requirement |
|---|---|
| NFR-1 | **PostgreSQL/MariaDB isolation (`AGENTS.md`):** `users`, `classrooms`, `student_sessions`, and `audit_logs` reside exclusively in PostgreSQL 17. MariaDB 11.8 is never connected or referenced. |
| NFR-2 | **Username enumeration & secret protection:** Login errors never reveal whether a `username` exists or is inactive. Plaintext passwords, session cookies, and CSRF tokens are never written to logs or `audit_logs.metadata`. |
| NFR-3 | **Session & cookie hardening:** `SANCTUM_STATEFUL_DOMAINS=localhost:3000`, `SESSION_DOMAIN=localhost`, `http_only=true`, `same_site=lax`, session ID rotation on login, full invalidation on logout/deactivation/password-reset. |
| NFR-4 | **Strict server-side RBAC:** Every protected route enforces middleware + policy authorization server-side; students never receive `users` rows or access staff endpoints. |
| NFR-5 | **Test & quality gates:** Negative-first PHPUnit 12 tests execute against PostgreSQL (`sql_proctor_test`) with 100% pass and zero skipped tests; Pint `--test`, ESLint, `next build`, and Vitest pass. |

## Constraints

- **Architecture boundaries (`AGENTS.md`, `docs/architecture.md`):** Next.js owns presentation; Laravel owns business logic, auth, RBAC, and audit persistence; PostgreSQL owns application data; MariaDB is untouched.
- **API response & pagination contract (`SPEC-0003`, `docs/api-specification.md`):** Every `/api/*` endpoint uses `ApiResponse` envelopes, `ApiException` coded errors, `X-Request-Id` header correlation, and `ValidatesPagination` rules.
- **Environment (`AGENTS.md`):** Prefix PHP/Composer commands with `PATH="/opt/homebrew/bin:$PATH"`. Never print, copy, or commit `.env` values. Do not run `docker compose down -v`.

## Architecture

- **PostgreSQL 17 (`backend/sql-proctor-api/database/migrations/`):**
  - `users` updated with `username`, `role`, `is_active`, `last_login_at`, nullable `name`/`email`, and lowercase/role `CHECK` constraints.
  - `classrooms` (`uuid` PK, `examiner_id` FK -> `users`, `name`, unique `join_code`, `is_open`, `code_expires_at`).
  - `student_sessions` (`uuid` PK, `classroom_id` FK -> `classrooms`, `display_name`, `normalized_display_name`, `ip_address` `inet`, `user_agent`, `joined_at`, `last_seen_at`, `expires_at`, `revoked_at`, partial unique active name index).
  - `audit_logs` (`uuid` PK, `actor_type`, `actor_id`, `actor_label`, `event_type`, `target_type`, `target_id`, `metadata` `jsonb`, `ip_address` `inet`, `user_agent`, `request_id` `uuid`, `created_at` immutable, composite indexes, `BEFORE UPDATE OR DELETE` trigger).
- **Backend (`backend/sql-proctor-api/app/`):**
  - Enums: `UserRole`, `ActorType`, `AuditEvent`.
  - Middleware: `EnsureUserIsActive`, `EnsureRole`, `VerifyStudentSession`, `EnsureStatefulAuthSession`.
  - Policies: `UserPolicy`, `ClassroomPolicy`, `AuditLogPolicy`.
  - Services: `AuditLogger`, `JoinCodeGenerator`.
  - Controllers & Requests & Resources for `AuthController`, `Admin/ExaminerController`, `Admin/AuditLogController`, `Examiner/ClassroomController`, `Student/StudentSessionController`.
- **Frontend (`frontend/sql-proctor-ui/`):**
  - `lib/api.js` shared Axios instance + `ensureCsrfCookie()`.
  - App Router routes: `/`, `/join`, `/login`, `/admin`, `/examiner`, `/student`.
  - Tailwind CSS v4 (`postcss.config.mjs`, `app/globals.css`).

## Expected behavior

1. **Bootstrap Admin:** Operator runs `PATH="/opt/homebrew/bin:$PATH" php artisan app:bootstrap-admin`. Admin user is created in PostgreSQL, credentials print once to stdout, and `admin.bootstrap_completed` is audited.
2. **Staff Login & Navigation:** Admin or Examiner enters `username` + `password` at `/login`. Frontend calls `GET /sanctum/csrf-cookie` then `POST /api/auth/login`. Backend rotates session, updates `last_login_at`, logs `auth.login_succeeded`, and returns user resource. Frontend routes Admin to `/admin` and Examiner to `/examiner`.
3. **Examiner Management & Audit Review:** Admin manages examiner accounts on `/admin` (creating accounts with optional or generated one-time password, deactivating accounts, resetting passwords) and filters paginated audit logs newest-first.
4. **Class Creation & Student Join:** Examiner creates/opens a classroom on `/examiner` and shares its 8-character `join_code`. Student enters `display_name` + `class_code` on `/` or `/join`. Backend validates code, checks uniqueness in a locked transaction, creates `student_sessions`, audits `student.joined_class`, and sets encrypted/signed HTTP-only `student_session_id` cookie. Student sees active classroom on `/student` and can leave via `POST /api/student/leave`.

## Failure behavior

| Trigger | Status | Response body | Side effect / Audit |
|---|---|---|---|
| Missing/invalid validation input | `422` | `{"message": "...", "errors": {...}}` | None (or `student.join_failed` for closed/expired class code) |
| Wrong username, wrong password, or inactive account on login | `401` | `{"message": "Invalid username or password.", "code": "invalid_credentials"}` | Rate limit hit; `auth.login_failed` with no password |
| Exceeding login (`5/min`) or student join (`10/min`) rate limit | `429` | `{"message": "...", "code": "too_many_attempts"}` + `Retry-After` | `auth.lockout` audited |
| Missing/expired/revoked/tampered staff or student session | `401` | `{"message": "Unauthenticated."}` | Protected work blocked; invalid student cookie cleared |
| Missing CSRF token on stateful mutation | `419` | `{"message": "CSRF token mismatch."}` | Request rejected |
| Wrong role, non-owner classroom access, self-modify/admin-modify via examiner routes | `403` | `{"message": "This action is unauthorized."}` or coded `403` | Request denied |
| Student joins closed classroom (`VerifyStudentSession` or join) | `403` / `422` | Coded `403` (`class_closed`) on active session; `422` on join | `student.join_failed` on join |
| Unknown route, model, or unknown `class_code` | `404` | `{"message": "Resource not found."}` or `{"message": "...", "code": "class_not_found"}` | `student.join_failed` on join |
| Duplicate active `display_name` in classroom | `409` | `{"message": "That display name is already in use in this class.", "code": "display_name_taken"}` | `student.join_failed` audited |
| `AuditLog` Eloquent or SQL `UPDATE`/`DELETE` | Exception | N/A (domain exception / PostgreSQL `QueryException`) | Row preserved unchanged |

## Security considerations

- **OWASP ASVS V2 / A07 (Authentication):** Stateful Sanctum cookie auth (`http_only=true`, `same_site=lax`), `session()->regenerate()` on login, `session()->invalidate()` + token regeneration on logout and admin deactivation/password reset, Argon2/Bcrypt hashing via Laravel `'hashed'` cast, `5/min` per `username|ip` login throttle, `10/min` per IP join throttle, generic `401` errors preventing username/status enumeration.
- **OWASP ASVS V4 / A01 (Access Control):** Deny-by-default route middleware (`auth:sanctum`, `EnsureUserIsActive`, `role:...`, `VerifyStudentSession`) backed by `UserPolicy`, `ClassroomPolicy`, and `AuditLogPolicy`. Students have no `users` rows and are isolated to `/api/student/*` via signed/encrypted `student_session_id`.
- **OWASP ASVS V5 / A03 (Validation & Injection):** FormRequests validate and allow-list inputs; `LIKE` search escapes wildcards; `AuditLogger` recursively redacts sensitive keys; `student` `display_name` is charset-restricted and HTML-escaped by React.
- **OWASP ASVS V7 / A09 (Logging & Immutability):** Every critical auth, account, class, student, and lockout event flows through `AuditLogger` into PostgreSQL-only `audit_logs` enforced append-only by Eloquent hooks + PostgreSQL `BEFORE UPDATE OR DELETE` trigger, correlated by `X-Request-Id`.
- **Duplicate display name rule:** Enforced unique active `normalized_display_name` per `classroom_id` (`409 Conflict`, `code: "display_name_taken"`) inside a `SELECT ... FOR UPDATE` transaction on the classroom row that expires stale `expires_at <= now()` sessions first, paired with a PostgreSQL partial unique index (`WHERE revoked_at IS NULL`).

## Acceptance criteria

| ID | Criterion | Verified by |
|---|---|---|
| AC-1 | `app:bootstrap-admin` creates one active admin with random 20-char password, prints once to stdout, audits `admin.bootstrap_completed` (`actor_type=system`), and is idempotent on repeat runs. | `BootstrapAdminCommandTest` (`TM-14`, `TM-23`) |
| AC-2 | Staff login rotates session ID, updates `last_login_at`, returns `UserResource`, rejects wrong/nonexistent/inactive accounts identically (`401` + `auth.login_failed` with no password), rate-limits at 5/min (`429` + `auth.lockout`), and invalidates session on logout (`auth.logout`). | `StaffAuthenticationTest` (`TM-1`, `TM-2`, `TM-5`, `TM-15`, `TM-16`, `TM-24`) |
| AC-3 | Deactivated examiners cannot log in and existing sessions are immediately rejected (`401`); admins can list/filter, create (with one-time generated or supplied password), toggle active, and reset examiner passwords while blocked from modifying admins or self (`403`). | `AdminExaminerManagementTest` (`TM-6`, `TM-8`, `TM-10`, `TM-12`, `TM-25`) |
| AC-4 | Examiners manage only their own classrooms (`create`, `open`, `close`, `regenerate-code`) with 8-char unambiguous codes; other examiners and non-examiners receive `403`. | `ExaminerClassroomTest` (`TM-7`, `TM-9`, `TM-26`) |
| AC-5 | Students join open non-expired classes using `display_name` + `class_code` without `users` rows, receive encrypted/signed HTTP-only `SameSite=Lax` `student_session_id` cookie, reject invalid/closed/expired/duplicate-name/rate-limited/tampered/revoked requests, cannot access staff routes, and can leave via `POST /api/student/leave`. | `StudentSessionTest` (`TM-3`, `TM-4`, `TM-11`, `TM-13`, `TM-17`, `TM-18`, `TM-27`) |
| AC-6 | `AuditLogger` redacts sensitive keys (`password`, `token`, `cookie`, `authorization`, `secret`, `xsrf`), attaches `request_id`/`ip_address`/`user_agent`, and `audit_logs` rejects `update()`/`delete()` in Eloquent and raw SQL `UPDATE`/`DELETE` via PostgreSQL trigger. | `AuditLoggingTest` (`TM-19`, `TM-20`, `TM-21`, `TM-22`) |
| AC-7 | `GET /api/audit-logs` is strictly admin-only, validates filters, paginates deterministically newest-first, and exposes accurate records for every critical event. | `AuditLogEndpointTest` (`TM-1`, `TM-7`, `TM-28`) |
| AC-8 | Frontend Next.js 16 pages (`/login`, `/`, `/join`, `/admin`, `/examiner`, `/student`) integrate with Sanctum CSRF + cookies, pass Vitest/RTL component tests, pass `npm run lint && npm run build`, and pass Chromium Playwright E2E verification at desktop and mobile viewports. | `Frontend Vitest`, `npm run lint && npm run build`, `npm run test:e2e` (`TM-29`, `TM-30`) |

## Test scenarios

Ordered negative-first per [test-matrix.md](../templates/test-matrix.md); full matrix recorded in [auth-rbac-and-audit-test-matrix.md](../plans/auth-rbac-and-audit-test-matrix.md).

| ID | Category | Scenario | Type | Priority |
|---|---|---|---|---|
| TM-1 | Invalid input | Malformed login, examiner creation, classroom creation, student join, and audit-log filter parameters fail with `422` | Feature | High |
| TM-2 | Security violation | Login failure never reveals account existence or leaks password into `audit_logs` or logs | Feature | Critical |
| TM-3 | Security violation | Tampered `student_session_id` cookie or missing CSRF token (`419`) is rejected | Feature | Critical |
| TM-4 | Security violation | Sensitive keys (`password`, `token`, `cookie`, `authorization`, `xsrf`, `secret`) passed to `AuditLogger` are redacted before PostgreSQL persistence | Unit / Feature | Critical |
| TM-5 | Unauthorized access | Unauthenticated requests to `/api/user`, `/api/admin/examiners`, `/api/audit-logs`, `/api/examiner/classes`, `/api/student/session` return `401` | Feature | Critical |
| TM-6 | Unauthorized access | Deactivated examiner cannot log in and existing session is rejected (`401`); logged-out session is rejected (`401`) | Feature | Critical |
| TM-7 | Forbidden access | Examiner denied `/api/admin/*` and `/api/audit-logs` (`403`); Student denied `/api/user`, `/api/admin/*`, `/api/examiner/*`, `/api/audit-logs` (`401`/`403`) | Feature | Critical |
| TM-8 | Forbidden access | Admin cannot deactivate or reset password of self or another admin via `/api/admin/examiners/*` (`403`) | Feature | High |
| TM-9 | Forbidden access | Examiner B cannot view, open, close, or regenerate code on Examiner A's classroom (`403`) | Feature | High |
| TM-10 | Invalid state transition | Student cannot join or access a closed or expired classroom (`422` / `403`) | Feature | High |
| TM-11 | Invalid state transition | Expired or revoked `student_session_id` cookie is rejected (`401`) | Feature | High |
| TM-12 | Missing resource | Unknown examiner ID, classroom ID, or invalid `class_code` returns `404` without stack traces or model leaks | Feature | High |
| TM-13 | Duplicate operation | Duplicate active `display_name` (case-insensitive) in same open classroom returns `409` (`display_name_taken`) and logs `student.join_failed`; duplicate `username` returns `422` | Feature | High |
| TM-14 | Duplicate operation | Re-running `app:bootstrap-admin` does not create duplicate admin or overwrite password | Feature | Critical |
| TM-15 | Boundary condition | Login rate limit (`5/min`) triggers `429` (`too_many_attempts`), `Retry-After`, and `auth.lockout` | Feature | High |
| TM-16 | Boundary condition | Student join rate limit (`10/min` failed, `60/min` burst by IP) triggers `429` (`too_many_attempts`) and `auth.lockout` (`scope: student_join`) | Feature | High |
| TM-17 | Boundary condition | Display name / username / password length bounds and audit pagination bounds behave as documented | Feature | Medium |
| TM-18 | Concurrency | Concurrent student joins with same `display_name` serialize on classroom lock: one succeeds (`201`), one conflicts (`409`) | Feature | Medium |
| TM-19 | Data integrity failure | Eloquent `AuditLog::update()` and `AuditLog::delete()` throw `ImmutableAuditLogException` | Unit / Feature | Critical |
| TM-20 | Data integrity failure | Raw PostgreSQL `UPDATE` and `DELETE` on `audit_logs` via `DB::statement` fail at database trigger level | Feature (PostgreSQL) | Critical |
| TM-21 | Data integrity failure | Database constraints enforce unique lowercase `username`, valid `role`, unique `join_code`, and active `student_sessions` uniqueness | Feature (PostgreSQL) | High |
| TM-22 | Security violation | Non-UUID inbound `X-Request-Id` does not break `audit_logs.request_id` UUID column and preserves correlation | Feature | High |
| TM-23 | Normal behavior | `app:bootstrap-admin` creates active admin, prints credentials once, and logs `admin.bootstrap_completed` | Feature | Critical |
| TM-24 | Normal behavior | Staff login succeeds, rotates session ID, sets `last_login_at`, logs `auth.login_succeeded`, serves `/api/user`, and logs out cleanly | Feature | Critical |
| TM-25 | Normal behavior | Admin lists/filters, creates (with generated/supplied password), updates, deactivates, and resets passwords for examiners with accurate audit rows | Feature | Critical |
| TM-26 | Normal behavior | Examiner creates, opens, closes, and regenerates code for classrooms with unambiguous 8-char codes and audit rows | Feature | High |
| TM-27 | Normal behavior | Student joins open class, reads `/api/student/session`, leaves via `/api/student/leave` (cookie expired, name reusable), with audit rows | Feature | Critical |
| TM-28 | Normal behavior | Admin queries `GET /api/audit-logs` with filters and pagination sorted newest-first | Feature | High |
| TM-29 | Frontend negative & normal | Staff login form, Student join form, Admin views, Examiner class view, and Student session view handle validation/401/403/409/429 and success states cleanly | Component (Vitest) | High |
| TM-30 | Frontend build & live | `npm run lint && npm run build` passes and desktop (`1280x800`) + phone (`375x812`) Playwright Chromium E2E flows pass without uncaught/5xx/419 errors | Build / E2E | High |

## Dependencies

- [SPEC-0003](SPEC-0003-api-response-envelope-and-pagination.md) (universal API response, error, pagination, and `X-Request-Id` contract).
- Running PostgreSQL 17.12 (`sql_proctor` and `sql_proctor_test`).
- Tailwind CSS v4 (`tailwindcss`, `@tailwindcss/postcss`) and Vitest/RTL devDependencies in `frontend/sql-proctor-ui/`.

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| `EnsureFrontendRequestsAreStateful` skips session/cookie middleware when `Origin`/`Referer` header is absent in non-browser tests | High | High | Add targeted `EnsureStatefulAuthSession` / `EnsureStudentCookieEncryption` wrappers checking `$request->attributes->get('sanctum')` so cookies/sessions are never double-encrypted or missing. |
| Inbound non-UUID `X-Request-Id` (allowed by `SPEC-0003`) causes PostgreSQL `uuid` syntax failure on `audit_logs.request_id` | Medium | High | Validate UUID in `AuditLogger`; generate/assign valid UUID for `audit_logs.request_id` and record non-UUID client ID in `metadata.client_request_id`. |
| Deactivated examiner retains an active browser session until idle timeout | Medium | High | Delete `sessions` rows for deactivated/password-reset user AND enforce `EnsureUserIsActive` middleware on every staff route. |
| Concurrent duplicate `display_name` joins bypass application check | Low | Medium | Lock `Classroom` row (`lockForUpdate()`) inside `DB::transaction`, revoke expired sessions, and enforce PostgreSQL partial unique index (`WHERE revoked_at IS NULL`). |

## Definition of Done

Satisfies [definition-of-done.md](../templates/definition-of-done.md): `SPEC-0004`, `ADR-0002`, `docs/database-design.md`, and `docs/api-specification.md` current; negative-first TDD Red and Green runs recorded; full PHPUnit 12 suite on PostgreSQL (`sql_proctor_test`), Pint, ESLint, Next.js build, and Vitest pass; code and security review checklists resolved with zero `BLOCKING` findings.
