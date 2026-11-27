# Implementation plan: Universal API response shape and pagination

> Plan aligned with [api-specification.md](../api-specification.md) and [implementation-plan.md](../templates/implementation-plan.md). Auth, audit, exam authoring, and later endpoints build directly on this foundation without changing response envelopes, pagination parameters, or error shapes.

| Field | Value |
|---|---|
| Spec / Contract | [SPEC-0003](../specs/SPEC-0003-api-response-envelope-and-pagination.md), [api-specification.md](../api-specification.md) (Conventions, Shared types, Pagination, Fixed error bodies) |
| Related task | Foundation slice (precedes Phase 1 auth/audit implementation) |
| Owner | parent |
| Reviewer | Human Owner / Architecture & Security review |
| Date | 2026-11-27 |
| Authorization | Approved by Human Owner (2026-11-27) |

## Goal and non-goals

### Goal

Establish a minimal, native-Laravel universal JSON response envelope, deterministic offset pagination contract, request-ID correlation, and hardened JSON exception rendering for all `/api/*` routes.

### Non-goals

- No custom wrapper framework that fights Laravel `JsonResource`, `LengthAwarePaginator`, `FormRequest`, or Sanctum responses.
- No cursor pagination in this slice (offset pagination matches [api-specification.md](../api-specification.md) and Phase 1 admin tables).
- No auth, user management, or audit-log routes, migrations, or business logic.
- No database migrations (PostgreSQL or MariaDB).
- No new Composer or npm dependencies.
- No dummy production endpoints in `routes/api.php` (tests register ephemeral routes inside PHPUnit only).
- No frontend edits (`frontend/sql-proctor-ui/lib/api.js` remains untouched in this slice).

## Contract summary

Aligned with [api-specification.md](../api-specification.md):

### Success envelopes

| Case | Status | Body |
|---|---|---|
| Single resource | `200` / `201` | `{"data": {...}}` |
| Paginated collection | `200` | `{"data": [...], "links": {"first", "last", "prev", "next"}, "meta": {"current_page", "from", "last_page", "links", "path", "per_page", "to", "total"}}` |
| Message-only success | `200` | `{"message": "..."}` |
| Empty success | `204` | Empty body |

### Error envelopes (always JSON for `api/*` and `Accept: application/json`)

| Status | Body | Notes |
|---|---|---|
| `401` | `{"message": "Unauthenticated."}` | Never redirects to a `login` route |
| `403` | `{"message": "This action is unauthorized."}` or `{"message": "...", "code": "<machine_code>"}` | Machine-readable `code` when domain-specific |
| `404` | `{"message": "Resource not found."}` | Never exposes model class names, IDs, or route internals |
| `409` | `{"message": "...", "code": "<machine_code>"}` | Domain conflict via `ApiException` |
| `419` | `{"message": "CSRF token mismatch."}` | `TokenMismatchException` rendered as JSON for API requests |
| `422` | `{"message": "...", "errors": {"field": ["..."]}}` | Standard Laravel validation shape |
| `423` | `{"message": "...", "code": "<machine_code>"}` | Account locked or resource locked via `ApiException` |
| `429` | `{"message": "...", "code": "too_many_attempts"}` | Plus `Retry-After` header |
| `500` | `{"message": "Server error."}` | Fixed in every environment, including `APP_DEBUG=true`; details go to logs only |

### Request correlation (`X-Request-Id`)

- Runs first in the `/api` middleware group via `AssignRequestId`.
- If the inbound `X-Request-Id` header matches `^[A-Za-z0-9._-]{1,64}$`, echo it; otherwise generate a fresh UUIDv4.
- Store the resolved ID on request attributes (`request_id`), share it with `Log::shareContext(['request_id' => $requestId])`, and return `X-Request-Id` on every `/api/*` response (including error responses).

### Pagination parameters and deterministic ordering

- `page`: optional integer, `min:1`, default `1`.
- `per_page`: optional integer, `min:1`, `max:200`, default `50` (configurable per endpoint up to global cap `200` in `config/pagination.php`).
- Invalid values (`0`, negative, `> 200`, non-integer strings, floats such as `1.5`, arrays such as `per_page[]=10`) return `422` with field errors — never silently clamped and never `500`.
- Out-of-range valid page (`page > last_page`) returns `200` with `data: []` and accurate `meta` / `links`.
- Deterministic ordering rule: every paginated query must end with a deterministic tie-break (`id ASC` or `id DESC`).

## Tasks

| ID | Task | Owner | Depends on | Status |
|---|---|---|---|---|
| P-1 | Contract and config alignment: add a concise "Pagination parameters & Request ID" subsection to [api-specification.md](../api-specification.md) and create `config/pagination.php` (`default_per_page => 50`, `max_per_page => 200`) | parent | None | Completed |
| P-2 | Request ID middleware: create `app/Http/Middleware/AssignRequestId.php` and register it at the start of the `api` middleware group in `bootstrap/app.php` | parent | P-1 | Completed |
| P-3 | Universal response helper and typed domain exception: create `app/Http/Support/ApiResponse.php` (`resource`, `collection`, `message`, `noContent`, `error`) and `app/Exceptions/ApiException.php` (status, message, optional machine-readable `code`, optional headers) | parent | P-1 | Completed |
| P-4 | Hardened global JSON exception rendering: wire `bootstrap/app.php` exception handlers for `ApiException`, `AuthenticationException`, `AuthorizationException` / `AccessDeniedHttpException`, `ModelNotFoundException` / `NotFoundHttpException`, `TokenMismatchException`, `ThrottleRequestsException`, `ValidationException`, and catch-all `Throwable` (fixed `500` even when `APP_DEBUG=true`) | parent | P-2, P-3 | Completed |
| P-5 | Reusable pagination FormRequest concern: create `app/Http/Requests/Concerns/ValidatesPagination.php` (`paginationRules()`, `page()`, `perPage()`, strict integer validation rejecting floats/strings/arrays) and `app/Http/Requests/PaginatedRequest.php` | parent | P-1 | Completed |
| P-6 | Negative-first TDD test suite and verification: write failing tests first (`ResponseEnvelopeTest`, `ExceptionRenderingTest`, `PaginationTest`, `ValidatesPaginationTest`) using ephemeral test-only routes inside PHPUnit, watch them fail for the expected reason, verify P-1 to P-5 turn them green, and run Pint plus diff/security checks | parent | P-2, P-3, P-4, P-5 | Completed |

## Dependencies

- No external services or approvals beyond Human Owner sign-off on this plan.
- Uses existing PHP `8.5.12`, Laravel `13.43.0`, and PHPUnit `12` (`12.5.44` installed).
- No new Composer or npm packages.

## Files affected

| Path | Change | Layer |
|---|---|---|
| `docs/api-specification.md` | Modify (add Pagination parameters & Request ID subsection) | Docs |
| `backend/sql-proctor-api/config/pagination.php` | Create | Backend |
| `backend/sql-proctor-api/bootstrap/app.php` | Modify (register `AssignRequestId` and JSON exception renderers) | Backend |
| `backend/sql-proctor-api/app/Http/Middleware/AssignRequestId.php` | Create | Backend |
| `backend/sql-proctor-api/app/Http/Support/ApiResponse.php` | Create | Backend |
| `backend/sql-proctor-api/app/Exceptions/ApiException.php` | Create | Backend |
| `backend/sql-proctor-api/app/Http/Requests/Concerns/ValidatesPagination.php` | Create | Backend |
| `backend/sql-proctor-api/app/Http/Requests/PaginatedRequest.php` | Create | Backend |
| `backend/sql-proctor-api/tests/Feature/Api/ResponseEnvelopeTest.php` | Create | Backend (Tests) |
| `backend/sql-proctor-api/tests/Feature/Api/ExceptionRenderingTest.php` | Create | Backend (Tests) |
| `backend/sql-proctor-api/tests/Feature/Api/PaginationTest.php` | Create | Backend (Tests) |
| `backend/sql-proctor-api/tests/Unit/ValidatesPaginationTest.php` | Create | Backend (Tests) |

`frontend/sql-proctor-ui/lib/api.js` is intentionally untouched in this slice; a future frontend task can consume this contract directly.

## Architecture considerations

- **Boundaries (`AGENTS.md`):** Laravel owns the API contract, validation, pagination, and error rendering. Next.js, PostgreSQL schema, MariaDB student SQL engine, and `infra/` are untouched.
- **API contract first:** [api-specification.md](../api-specification.md) is updated in `P-1` before backend behavior changes so the request-ID header rule and generic pagination parameter rules are documented in the single source of truth.
- **Native Laravel alignment:** `ApiResponse` delegates to `JsonResource` and `LengthAwarePaginator` rather than inventing a custom envelope, keeping Laravel's `data` / `links` / `meta` structure intact.
- **No extra abstractions or packages:** Zero new dependencies, zero service containers, and no production stub routes.

## Risks

| Risk | Likelihood | Impact | Mitigation | Owner |
|---|---|---|---|---|
| Over-engineering custom response wrappers that clash with `JsonResource` / `LengthAwarePaginator` | Medium | Medium | Keep `ApiResponse` as a thin helper wrapping native `JsonResource`, `AnonymousResourceCollection`, and `JsonResponse`. | parent |
| `APP_DEBUG=true` leaking stack traces, file paths, or SQL errors on `500` | High | High | Register an explicit catch-all `Throwable` renderer for `api/*` returning fixed `{"message": "Server error."}` and test it with `config(['app.debug' => true])`. | parent |
| Inbound `X-Request-Id` header/log injection or oversized header abuse | Medium | Medium | Enforce strict allow-list `^[A-Za-z0-9._-]{1,64}$`; replace any invalid or missing value with a fresh UUIDv4. | parent |
| Query-string `per_page=1.5` or `per_page[]=10` bypassing weak validation | Medium | Medium | Reject non-integers, floats, and arrays in `ValidatesPagination` (`integer` validation on raw scalar input) and cover all variants in negative tests. | parent |
| Nondeterministic pagination order across pages when sort keys tie | Medium | Low | Document and enforce a deterministic `id` tie-break in every paginated endpoint and in code review. | parent |

## Test requirements

Follow TDD Red-Green-Refactor (`tdd-workflow`) and the 14-category negative-first order in [test-matrix.md](../templates/test-matrix.md) (`negative-first-testing`). Write tests before production code in `P-2` to `P-5` (or run Red against ephemeral test routes before wiring each component) and record the Red output before implementing.

| Test class | Scope | Negative-first cases (in order) | Normal behavior cases |
|---|---|---|---|
| `tests/Unit/ValidatesPaginationTest.php` | Pagination rules & bounds helper | 1. **Invalid input:** `page=0`, `page=-1`, `page=abc`, `page=1.5`, `page[]=1`, `per_page=0`, `per_page=-5`, `per_page=abc`, `per_page=1.5`, `per_page[]=10`, `per_page=201` fail validation<br>8. **Boundary:** `page=1`, `per_page=1`, `per_page=200` pass | Default `page=1`, `per_page=50` when omitted; custom endpoint default respected when `<= 200` |
| `tests/Feature/Api/ExceptionRenderingTest.php` | Global JSON exception handler & `AssignRequestId` | 2. **Security violation:** `X-Request-Id` with CRLF / spaces / `> 64` chars is replaced by a valid UUIDv4; unhandled `RuntimeException` / `QueryException` under `APP_DEBUG=true` returns `500` `{"message": "Server error."}` with no stack trace, file path, or SQL; `ModelNotFoundException` returns `404` `{"message": "Resource not found."}` without leaking model class name<br>3. **Unauthorized:** `AuthenticationException` returns `401` `{"message": "Unauthenticated."}` (even without `Accept: application/json` on `/api/*`, no redirect)<br>4. **Forbidden:** `AuthorizationException` returns `403` `{"message": "This action is unauthorized."}`; coded `ApiException(403, ..., 'password_change_required')` returns `403` with `code`<br>5. **Invalid state / Conflict / Locked:** `ApiException` `409` and `423` return exact `message` and `code`<br>6. **Missing resource:** unknown `/api/*` route and `NotFoundHttpException` return `404` `{"message": "Resource not found."}`<br>10/12. **Throttle (`429`) & CSRF (`419`):** `TokenMismatchException` returns `419` `{"message": "CSRF token mismatch."}`; `ThrottleRequestsException` returns `429` with `code: "too_many_attempts"` and `Retry-After` header | Valid inbound `X-Request-Id` (`^[A-Za-z0-9._-]{1,64}$`) is preserved on response header; `ValidationException` returns `422` with `message` and `errors` |
| `tests/Feature/Api/ResponseEnvelopeTest.php` | `ApiResponse` helper shapes | 1. **Invalid input:** error helper rejects/handles standard non-2xx JSON payloads cleanly | Single resource `200` / `201` (`{"data": {...}}`); message `200` (`{"message": "..."}`); `204` empty body; `X-Request-Id` header present on all responses |
| `tests/Feature/Api/PaginationTest.php` | End-to-end paginated collection shape | 1. **Invalid input:** `GET ?page=0`, `?per_page=201`, `?per_page=1.5`, `?per_page[]=10` return `422` with `errors`<br>8. **Boundary:** `per_page=1`, `per_page=200`, `page=999` (`page > last_page`) returns `200` with `data: []` and accurate `meta`/`links` | `200` `{"data": [...], "links": {first, last, prev, next}, "meta": {current_page, from, last_page, links, path, per_page, to, total}}` with deterministic tie-break ordering |

## Verification steps

| # | Command or check | Working directory | Expected result | Evidence |
|---|---|---|---|---|
| 1 | `PATH="/opt/homebrew/bin:$PATH" php artisan test --filter="ValidatesPaginationTest\|ExceptionRenderingTest\|ResponseEnvelopeTest\|PaginationTest"` | `backend/sql-proctor-api` | TDD Red: fails for expected missing classes/handlers before implementation | `.agent-runtime/` (if run recorded) |
| 2 | `PATH="/opt/homebrew/bin:$PATH" php artisan test` | `backend/sql-proctor-api` | Exit 0; all baseline + new unit and feature tests pass | `.agent-runtime/` |
| 3 | `PATH="/opt/homebrew/bin:$PATH" vendor/bin/pint --test` | `backend/sql-proctor-api` | Exit 0; no formatting violations | `.agent-runtime/` |
| 4 | Diff, architecture boundary, and secret scan (`git diff`, `git status`) | repository root | Only planned files changed; no secrets, debug leaks, or production stub routes | `.agent-runtime/` |
| 5 | Closeout checklist review against [definition-of-done.md](../templates/definition-of-done.md) and [code-review-checklist.md](../templates/code-review-checklist.md) | repository root | All criteria satisfied; zero `BLOCKING` findings | `.agent-runtime/` |
