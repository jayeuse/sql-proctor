# Specification: Universal API response shape and pagination

> Backfilled after implementation and reconciled against the verified test suite (`php artisan test`: 43 passed, 183 assertions; `vendor/bin/pint --test`: passed) on 2026-11-27.

| Field | Value |
|---|---|
| Spec ID | SPEC-0003 |
| Title | Universal API response shape and pagination |
| Status | Implemented |
| Owner | parent |
| Reviewer | Human Owner |
| Related task | Foundation slice (pre-Phase 1 auth/audit) |
| Date | 2026-11-27 |

## Objective

All `/api/*` endpoints share a uniform, native-Laravel JSON envelope for success and error responses, correlate every request and response with a sanitized `X-Request-Id` header, redact internal details in every environment (including `APP_DEBUG=true`), and validate offset pagination parameters (`page`, `per_page`) strictly before query execution.

## Problem

Before this change, `backend/sql-proctor-api/bootstrap/app.php` only called `$exceptions->shouldRenderJsonWhen(...)` with default Laravel exception rendering:

1. Unhandled exceptions under `APP_DEBUG=true` returned `exception`, `file`, `line`, `message`, and `trace` in the JSON body instead of a fixed `{"message": "Server error."}`.
2. `ModelNotFoundException` could leak model class names or identifiers instead of returning `{"message": "Resource not found."}`.
3. There was no `X-Request-Id` correlation middleware or log context propagation, no shared helper for `ApiResponse` or domain `ApiException` codes, and no reusable pagination validation (`page`, `per_page`).

## Scope

- **In scope:** `config/pagination.php`, `AssignRequestId` middleware, `ApiResponse` helper, `ApiException`, `ValidatesPagination` trait, `PaginatedRequest`, hardened JSON exception rendering in `bootstrap/app.php`, [api-specification.md](../api-specification.md) updates (Convention #15 and standard pagination query rules), and PHPUnit unit/feature tests using ephemeral test-only routes.
- **Not in scope:** Account, authentication, or audit-log routes, migrations, cursor pagination, new Composer/npm dependencies, frontend changes (`frontend/sql-proctor-ui/`), and production stub routes in `routes/api.php`.
- **Preserved behavior:** Non-API web routes continue using standard Laravel rendering, `$middleware->statefulApi()` remains active for Sanctum SPA sessions, `/up` health check is unchanged, and `frontend/sql-proctor-ui/` is untouched.

## Requirements

### Functional

| ID | Requirement |
|---|---|
| FR-1 | Single-resource endpoints return `{"data": {...}}` with status `200` or `201`. |
| FR-2 | Paginated collection endpoints return Laravel's paginator shape `{"data": [...], "links": {"first", "last", "prev", "next"}, "meta": {"current_page", "from", "last_page", "links", "path", "per_page", "to", "total"}}` with status `200`. |
| FR-3 | Message-only success endpoints return `{"message": "..."}` (`200`), and empty success responses return `204` with an empty body. |
| FR-4 | All `/api/*` and `Accept: application/json` failures render JSON in the documented shapes: `401` (`Unauthenticated.`), `403` (plain or coded via `ApiException`), `404` (`Resource not found.`), `409` (coded), `419` (`CSRF token mismatch.`), `422` (`{"message": "...", "errors": {...}}`), `423` (coded), `429` (`code: "too_many_attempts"` + `Retry-After`), and `500` (`{"message": "Server error."}`). |
| FR-5 | Every `/api/*` response includes an `X-Request-Id` header. Valid inbound `X-Request-Id` values matching `^[A-Za-z0-9._-]{1,64}$` are echoed; invalid or absent values are replaced with a generated UUIDv4 and shared via `Log::shareContext(['request_id' => ...])`. |
| FR-6 | Paginated requests accept optional `page` (`integer >= 1`, default `1`) and `per_page` (`integer` `1..200`, default `50`, configurable per endpoint up to `200`). |
| FR-7 | Invalid `page` or `per_page` inputs (non-integer, `< 1`, `> 200`, float, empty string, array) fail with `422` field errors and are never silently clamped. |
| FR-8 | Valid requests for `page > last_page` return `200` with `data: []` and accurate `meta` and `links`. |

### Non-functional

| ID | Requirement |
|---|---|
| NFR-1 | Unhandled `500` responses never expose stack traces, file paths, SQL errors, or exception class names in any environment, including `APP_DEBUG=true`. |
| NFR-2 | `404` responses from `ModelNotFoundException` or unknown routes never leak model class names or record identifiers. |
| NFR-3 | Inbound `X-Request-Id` values outside `^[A-Za-z0-9._-]{1,64}$` are rejected and replaced to prevent header/log injection. |
| NFR-4 | Zero new Composer or npm dependencies are added. |

## Constraints

- **Stack and boundaries (`AGENTS.md`):** Laravel owns the API contract, validation, pagination, and error rendering. PostgreSQL schema, MariaDB student SQL execution engine, Next.js frontend, and `infra/` are unchanged.
- **API contract ([api-specification.md](../api-specification.md)):** Envelopes, error statuses, fixed error messages, `X-Request-Id` rule, and pagination query rules must match `docs/api-specification.md`.

## Architecture

- **Backend only (`backend/sql-proctor-api/`):**
  - `AssignRequestId` is prepended globally in `bootstrap/app.php` and resolved in exception rendering so both normal and error responses on `/api/*` carry `X-Request-Id`.
  - `ApiResponse` delegates to native Laravel `JsonResource`, `ResourceCollection`, and `JsonResponse`.
  - `ApiException` carries HTTP status, message, optional machine-readable `code`, and optional headers.
  - `ValidatesPagination` and `PaginatedRequest` read defaults/caps from `config/pagination.php`.
- **Docs (`docs/api-specification.md`):** Updated with Convention #15 and standard pagination parameter rules.

## Expected behavior

1. Client sends a request to `/api/*` with optional `X-Request-Id` and optional `page`/`per_page`.
2. `AssignRequestId` validates or generates the request ID and binds it to request attributes and `Log::shareContext`.
3. `PaginatedRequest` / `ValidatesPagination` validates `page` and `per_page` strictly as integers within bounds and resolves defaults (`1` and `50`).
4. Controller returns `ApiResponse::resource(...)`, `ApiResponse::collection(...)`, `ApiResponse::message(...)`, or `ApiResponse::noContent()` with `X-Request-Id` attached on the response.

## Failure behavior

| Trigger | Status | Response body | Headers |
|---|---|---|---|
| Invalid `page` / `per_page` or `ValidationException` | `422` | `{"message": "...", "errors": {"field": ["..."]}}` | `X-Request-Id` |
| Unauthenticated (`AuthenticationException`, even without `Accept: application/json`) | `401` | `{"message": "Unauthenticated."}` | `X-Request-Id` |
| Unauthorized (`AuthorizationException` / `AccessDeniedHttpException`) | `403` | `{"message": "This action is unauthorized."}` | `X-Request-Id` |
| Coded domain forbidden / conflict / locked (`ApiException`) | `403` / `409` / `423` | `{"message": "...", "code": "..."}` | `X-Request-Id` + custom |
| Missing route or `ModelNotFoundException` | `404` | `{"message": "Resource not found."}` | `X-Request-Id` |
| `TokenMismatchException` | `419` | `{"message": "CSRF token mismatch."}` | `X-Request-Id` |
| `ThrottleRequestsException` | `429` | `{"message": "...", "code": "too_many_attempts"}` | `X-Request-Id`, `Retry-After` |
| Unhandled `Throwable` (even when `APP_DEBUG=true`) | `500` | `{"message": "Server error."}` | `X-Request-Id` |

## Security considerations

- **Header/log injection (ASVS V5 / V7):** `X-Request-Id` accepts only `^[A-Za-z0-9._-]{1,64}$`; spaces, delimiters, control characters, and oversized strings are replaced with a UUIDv4.
- **Information disclosure (ASVS V7.4):** `500` responses return `{"message": "Server error."}` regardless of `APP_DEBUG`; `ModelNotFoundException` maps to `{"message": "Resource not found."}` with no model name or ID.
- **Input validation (ASVS V5.1):** `page` and `per_page` validate raw scalar integers (`integer`, `min:1`, `max:200`) so floats, strings, and array parameters fail with `422`.

## Acceptance criteria

| ID | Criterion | Verified by |
|---|---|---|
| AC-1 | Single resource responses return `{"data": {...}}` with `200` or `201` and `X-Request-Id`. | `ResponseEnvelopeTest::test_resource_helper_returns_data_envelope_for_200_and_201` (`PASS`) |
| AC-2 | Message (`200`) and no-content (`204`) helpers return `{"message": "..."}` and an empty body respectively. | `ResponseEnvelopeTest::test_message_and_no_content_helpers_return_documented_shapes` (`PASS`) |
| AC-3 | `ApiResponse::error` returns status, `message`, optional `code`, optional headers, and `X-Request-Id`. | `ResponseEnvelopeTest::test_error_helper_returns_non_2xx_json_with_optional_code_and_headers` (`PASS`) |
| AC-4 | Paginated collections return `data`, `links`, `meta`, and deterministic order; `page > last_page` returns `data: []`. | `PaginationTest::test_default_paginated_collection_returns_laravel_paginator_envelope`, `PaginationTest::test_pagination_boundaries_and_out_of_range_page_behave_as_documented` (`PASS`) |
| AC-5 | Invalid `page` / `per_page` values (`0`, negative, string, float, empty, array, `> 200`) return `422` field errors. | `ValidatesPaginationTest::test_invalid_pagination_inputs_fail_validation`, `PaginationTest::test_invalid_pagination_query_returns_422_with_field_errors` (`PASS`) |
| AC-6 | Boundary pagination values (`page=1`, `per_page=1`, `per_page=200`) and defaults (`page=1`, `per_page=50`, custom subclass default) resolve accurately. | `ValidatesPaginationTest::test_boundary_pagination_values_pass_validation`, `ValidatesPaginationTest::test_omitted_pagination_params_resolve_to_defaults_and_respect_custom_default` (`PASS`) |
| AC-7 | Valid inbound `X-Request-Id` is echoed; invalid or oversized `X-Request-Id` is replaced with a UUIDv4. | `ExceptionRenderingTest::test_invalid_inbound_request_id_is_replaced_with_generated_uuid`, `ExceptionRenderingTest::test_valid_request_id_is_echoed_and_validation_exception_returns_422_with_request_id` (`PASS`) |
| AC-8 | Unhandled exceptions under `APP_DEBUG=true` return fixed `500` `{"message": "Server error."}` with no leaks. | `ExceptionRenderingTest::test_unhandled_exception_returns_fixed_500_body_even_when_app_debug_is_true` (`PASS`) |
| AC-9 | `AuthenticationException` (even without `Accept: application/json`), `AuthorizationException`, `ModelNotFoundException`, unknown routes, `TokenMismatchException`, `ThrottleRequestsException`, and `ApiException` (`403`/`409`/`423`) return exact JSON shapes and headers. | `ExceptionRenderingTest` (`PASS`) |
| AC-10 | Code style and full test suite pass with zero warnings or unapproved changes. | `php artisan test` (43 passed, 183 assertions), `vendor/bin/pint --test` (`PASS`) |

## Test scenarios

Ordered negative-first per [test-matrix.md](../templates/test-matrix.md):

| ID | Category | Scenario | Type | Priority | Status |
|---|---|---|---|---|---|
| TM-1 | Invalid input | Invalid `page` / `per_page` (`0`, `-1`, `abc`, `1.5`, `""`, array, `201`) fail validation (`422`) | Unit / Feature | High | `PASS` |
| TM-2 | Security violation | Invalid `X-Request-Id` (spaces, `/`, `;`, `> 64` chars) replaced with UUIDv4 | Feature | High | `PASS` |
| TM-3 | Security violation | Unhandled `RuntimeException` with `APP_DEBUG=true` returns fixed `500` with no trace/SQL leak | Feature | Critical | `PASS` |
| TM-4 | Security violation | `ModelNotFoundException` returns `404` `{"message": "Resource not found."}` without model class/ID | Feature | High | `PASS` |
| TM-5 | Unauthorized | `AuthenticationException` without `Accept: application/json` returns `401` JSON (no redirect) | Feature | High | `PASS` |
| TM-6 | Forbidden | `AuthorizationException` returns `403`; coded `ApiException::forbidden` returns `403` + `code` | Feature | High | `PASS` |
| TM-7 | Invalid state | `ApiException::conflict` (`409`) and `ApiException::locked` (`423`) return exact `message` and `code` | Feature | High | `PASS` |
| TM-8 | Missing resource | Unknown `/api/*` route returns `404` `{"message": "Resource not found."}` + `X-Request-Id` | Feature | High | `PASS` |
| TM-9 | Security / CSRF | `TokenMismatchException` returns `419` `{"message": "CSRF token mismatch."}` | Feature | High | `PASS` |
| TM-10 | Resource exhaustion | `ThrottleRequestsException` returns `429` with `code: "too_many_attempts"` + `Retry-After` | Feature | High | `PASS` |
| TM-11 | Boundary condition | `per_page=1`, `per_page=200`, and `page=99` (`> last_page`, `data: []`) behave as documented | Unit / Feature | High | `PASS` |
| TM-12 | Normal behavior | `ApiResponse` (`200`/`201`/message/`204`), paginated envelope (`data`/`links`/`meta`), valid `X-Request-Id` echo, `422` `ValidationException` | Unit / Feature | High | `PASS` |

## Dependencies

- Implementation plan: [api-response-envelope-and-pagination.md](../plans/api-response-envelope-and-pagination.md) (Approved `2026-11-27`, `P-1` to `P-6` completed).
- No external service or database dependency.

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Custom response wrapper clashes with native `JsonResource` / `LengthAwarePaginator` | Medium | Medium | `ApiResponse` delegates directly to `JsonResource::response()` and `ResourceCollection::response()`. |
| `APP_DEBUG=true` leaks stack traces in local development | High | High | Explicit catch-all renderer in `bootstrap/app.php` tested with `config(['app.debug' => true])`. |
| Inbound `X-Request-Id` header or log injection | Medium | Medium | Strict regex allow-list `^[A-Za-z0-9._-]{1,64}$` in `AssignRequestId::resolve()`. |

## Definition of Done

Satisfies [definition-of-done.md](../templates/definition-of-done.md) for this foundation slice: contract documented in [api-specification.md](../api-specification.md), negative-first TDD Red and Green runs recorded, `php artisan test` (43 passed, 183 assertions) and `vendor/bin/pint --test` passed, and no unintended files or dependencies introduced.
