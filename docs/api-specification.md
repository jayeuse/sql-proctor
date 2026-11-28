# SQL-Proctor API Specification

> Status: Updated for SPEC-0003 (response envelope & pagination) and SPEC-0004 (Authentication, RBAC, Classrooms, Student Sessions, and Audit Logging) on 2026-11-28. Exam authoring, SQL execution, and grading endpoints remain `TBD`.

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
4. Staff identifiers (`users.id`) are integers; classroom, student session, and audit log identifiers are UUIDv4 strings.
5. Staff authentication uses Laravel Sanctum SPA stateful cookie authentication (`web` guard, `SANCTUM_STATEFUL_DOMAINS=localhost:3000`, `SESSION_DOMAIN=localhost`). Student entry uses an encrypted, signed, HTTP-only, `SameSite=Lax` cookie (`student_session_id`).
6. API versioning is not implemented (`TBD`).
7. CORS allows `http://localhost:3000` with `supports_credentials = true` and exposes `X-Request-Id` and `Retry-After`.
8. A single resource is returned as `{"data": {...}}` (`200` / `201`). A paginated collection is returned as `{"data": [...], "links": {...}, "meta": {...}}` (`200`). A message-only success is returned as `{"message": "..."}` (`200`). An empty success returns `204` with no body.
9. Coded domain errors carry a stable machine-readable `code` field alongside `message`.
10. The `500` body is `{"message": "Server error."}` in every environment, including `APP_DEBUG=true`. Debug details go to the application log only.
11. Every `/api/*` response includes an `X-Request-Id` header. If the client sends a valid `X-Request-Id` matching `^[A-Za-z0-9._-]{1,64}$`, the server echoes it; otherwise the server generates a UUIDv4 and attaches it to logs and the response.

## Authentication & Session Endpoints

### `GET /sanctum/csrf-cookie`

Sanctum built-in endpoint. Issues the `XSRF-TOKEN` cookie and session cookie before mutating requests from `http://localhost:3000`. Returns `204`.

### `POST /api/auth/login`

- **Auth:** Public (stateful session + CSRF).
- **Rate limit:** `5` attempts per minute keyed by `lower(username)|ip`. Exceeding returns `429` (`code: "too_many_attempts"`, `Retry-After` header) and writes `auth.lockout` audit log.
- **Request body:** `{"username": "string", "password": "string"}` (`username` is trimmed and lowercased).
- **Success (`200`):** Rotates session ID, updates `last_login_at`, audits `auth.login_succeeded`, returns `{"data": UserResource}`.
- **Failure (`401`):** Identical generic body `{"message": "Invalid username or password.", "code": "invalid_credentials"}` for unknown username, wrong password, or inactive account (`is_active = false`). Audits `auth.login_failed` without password data.

### `POST /api/auth/logout`

- **Auth:** `auth:sanctum` + active staff user.
- **Success (`200`):** Calls `Auth::guard('web')->logout()`, invalidates session, regenerates CSRF token, audits `auth.logout`, returns `{"message": "Logged out."}`.

### `GET /api/user`

- **Auth:** `auth:sanctum` + active staff user.
- **Success (`200`):** Returns `{"data": {"id", "username", "name", "role", "is_active", "last_login_at", "created_at", "updated_at"}}`.
- **Failure (`401`):** Unauthenticated or deactivated account (existing session invalidated).

## Admin Endpoints (`role:admin`)

All routes require `auth:sanctum`, active user enforcement, and `role:admin` (examiners and students receive `403`/`401`).

| Method | Path | Purpose | Request / Query | Response |
|---|---|---|---|---|
| `GET` | `/api/admin/examiners` | Paginated examiner list | `page`, `per_page`, optional `is_active` (bool), `username` (search) | `200` paginated `UserResource` collection (`order by username asc, id asc`) |
| `POST` | `/api/admin/examiners` | Create examiner account | `username` (required, unique lowercase `3..32`), `name` (optional), `password` (optional `min:12`, max `255`) | `201` `{"data": {User fields, "generated_password"?: "..."}}` (`generated_password` returned once when password not supplied) |
| `PATCH` | `/api/admin/examiners/{examiner}` | Update examiner profile/status | Optional `name`, `is_active` (bool) | `200` `{"data": UserResource}` (`403` `cannot_modify_admin` if target is admin or self; deactivating purges target sessions) |
| `POST` | `/api/admin/examiners/{examiner}/reset-password` | Reset examiner password & invalidate sessions | Optional `password` (`min:12`, max `255`) | `200` `{"data": {User fields, "generated_password"?: "..."}}` (`403` `cannot_modify_admin` if target is admin or self) |
| `GET` | `/api/audit-logs` | Paginated audit log viewer | `page`, `per_page`, optional `actor_type`, `actor_id`, `event_type`, `target_type`, `target_id`, `from`, `to` | `200` paginated `AuditLogResource` collection (`order by created_at desc, id desc`) |

## Examiner Classroom Endpoints (`role:examiner`)

All routes require `auth:sanctum`, active user enforcement, `role:examiner`, and `ClassroomPolicy` (`classroom.examiner_id === user.id`).

| Method | Path | Purpose | Request / Query | Response |
|---|---|---|---|---|
| `GET` | `/api/examiner/classes` | Paginated examiner classrooms | `page`, `per_page` | `200` paginated `ClassroomResource` collection (`active_students_count` included) |
| `POST` | `/api/examiner/classes` | Create classroom + code | `name` (required `1..120`), optional `is_open` (bool default `true`), optional `code_expires_at` (future ISO timestamp) | `201` `{"data": ClassroomResource}` |
| `GET` | `/api/examiner/classes/{classroom}` | Inspect own classroom | — | `200` `{"data": ClassroomResource}` (`403` for non-owner) |
| `POST` | `/api/examiner/classes/{classroom}/open` | Reopen classroom | Optional `code_expires_at` | `200` `{"data": ClassroomResource}` |
| `POST` | `/api/examiner/classes/{classroom}/close` | Close classroom to joins/active checks | — | `200` `{"data": ClassroomResource}` |
| `POST` | `/api/examiner/classes/{classroom}/regenerate-code` | Rotate join code | Optional `code_expires_at` | `200` `{"data": ClassroomResource}` |

## Student Session Endpoints (Kahoot-Style Entry)

| Method | Path | Auth | Request | Response |
|---|---|---|---|---|
| `POST` | `/api/student/join` | Public (rate-limited `10/min` by IP) | `{"display_name": "string (2..50)", "class_code": "string (6..8 alnum)"}` | `201` `{"data": StudentSessionResource}` + `student_session_id` encrypted/signed HTTP-only `SameSite=Lax` cookie; `404` (`class_not_found`); `422` (validation / closed / expired); `409` (`display_name_taken`); `429` (`too_many_attempts`) |
| `GET` | `/api/student/session` | `VerifyStudentSession` | Cookie `student_session_id` | `200` `{"data": StudentSessionResource}`; `401` (missing/tampered/expired/revoked); `403` (`class_closed`) |
| `POST` | `/api/student/leave` | `VerifyStudentSession` (`allow-closed`) | Cookie `student_session_id` | `200` `{"message": "Left class."}` (revokes session even if classroom closed, expires cookie, audits `student.left_class`) |

## Pagination

Paginated endpoints use Laravel's offset paginator shape:

```json
{
  "data": [],
  "links": {
    "first": "http://localhost:8000/api/items?page=1",
    "last": "http://localhost:8000/api/items?page=3",
    "prev": null,
    "next": "http://localhost:8000/api/items?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 3,
    "links": [
      { "url": "http://localhost:8000/api/items?page=1", "label": "1", "active": true }
    ],
    "path": "http://localhost:8000/api/items",
    "per_page": 50,
    "to": 50,
    "total": 120
  }
}
```

Standard query parameters across all paginated endpoints:

- `page`: optional integer, `>= 1`, default `1`.
- `per_page`: optional integer, `1` to `200`, default `50`.
- Invalid `page` or `per_page` values (`0`, negative, `> 200`, floats, strings, empty strings, or arrays) return `422` with field errors in `errors`; values are never silently clamped.
- Valid requests with `page > last_page` return `200` with `"data": []` and accurate `meta` and `links`.
- Every paginated query ends with a deterministic `id` tie-break.

## Error responses

| Status | Body | When |
|---|---|---|
| `401` | `{"message": "Unauthenticated."}` or `{"message": "Invalid username or password.", "code": "invalid_credentials"}` | Missing/invalid/expired/revoked credentials or deactivated staff session |
| `403` | `{"message": "This action is unauthorized."}` or `{"message": "...", "code": "<machine_code>"}` | Role/policy denial, `cannot_modify_admin`, or `class_closed` |
| `404` | `{"message": "Resource not found."}` or `{"message": "...", "code": "class_not_found"}` | Unknown route, missing record, or unknown `class_code` |
| `409` | `{"message": "...", "code": "<machine_code>"}` | Domain conflict (e.g. `display_name_taken`) |
| `419` | `{"message": "CSRF token mismatch."}` | Missing or mismatched CSRF token |
| `422` | `{"message": "...", "errors": {"field": ["..."]}}` | Validation failure (including closed/expired class on join or bad filters) |
| `423` | `{"message": "...", "code": "<machine_code>"}` | Locked resource (`ApiException::locked`) |
| `429` | `{"message": "...", "code": "too_many_attempts"}` | Rate limit exceeded; includes `Retry-After` header |
| `500` | `{"message": "Server error."}` | Unhandled error in every environment, including `APP_DEBUG=true`. Debug detail goes to logs only. |

## Planned resources (`TBD`)

| Resource | Purpose | Status |
|---|---|---|
| Exams | Create and publish an exam | `TBD` |
| Questions | SQL tasks and expected results | `TBD` |
| Attempts | A student's exam session | `TBD` |
| Submissions | A student's SQL answer and its result | `TBD` |
| Results | Scores and feedback | `TBD` |

## Related documents

- [architecture.md](architecture.md)
- [database-design.md](database-design.md)
- [SPEC-0003](specs/SPEC-0003-api-response-envelope-and-pagination.md)
- [SPEC-0004](specs/SPEC-0004-auth-rbac-and-audit-logging.md)
