# SQL-Proctor API Specification

> Status: Current for the scaffold on 2026-11-19. Only the routes listed as existing are implemented. Every exam endpoint is `TBD`.

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
4. Identifiers are integers for application records. The public identifier format is `TBD`.
5. Authentication uses Laravel Sanctum. Protected routes use the `auth:sanctum` middleware.
6. API versioning is not implemented. A version prefix is `TBD`.
7. CORS allows `http://localhost:3000` and `http://127.0.0.1:3000` with credentials.
8. A single resource is returned as `{"data": {...}}` (`200` / `201`). A paginated collection is returned as `{"data": [...], "links": {...}, "meta": {...}}` (`200`). A message-only success is returned as `{"message": "..."}` (`200`). An empty success returns `204` with no body.
9. Coded domain errors carry a stable machine-readable `code` field alongside `message`.
10. The `500` body is `{"message": "Server error."}` in every environment, including `APP_DEBUG=true`. Debug details go to the application log only.
11. Every `/api/*` response includes an `X-Request-Id` header. If the client sends a valid `X-Request-Id` matching `^[A-Za-z0-9._-]{1,64}$`, the server echoes it; otherwise the server generates a UUIDv4 and attaches it to logs and the response.

## Existing routes

| Method | Path | Auth | Response | Verified |
|---|---|---|---|---|
| `GET` | `/up` | No | `200` health check | Yes, `200` |
| `GET` | `/api/user` | `auth:sanctum` | `401` unauthenticated; the user when authenticated | Yes, `401` without a token |

`/api/user` comes from Laravel's API scaffold. It is not an exam feature.

## Authentication

Laravel Sanctum 4.5.0 is installed:

- `personal_access_tokens` table on PostgreSQL.
- `HasApiTokens` on `App\Models\User`.
- Stateful API middleware enabled in `bootstrap/app.php`.
- `SANCTUM_STATEFUL_DOMAINS=localhost:3000,127.0.0.1:3000`.

### Token flow

Planned, not implemented:

1. The client authenticates against an endpoint that does not exist yet. `TBD`.
2. The server returns a personal access token.
3. The client sends `Authorization: Bearer <token>` on protected requests.
4. Logout revokes the token.

### Cookie flow

The stateful configuration is present for a same-site SPA using Sanctum cookies. The CSRF cookie route is `GET /sanctum/csrf-cookie`, provided by Sanctum. No login endpoint exists, so the flow cannot complete yet.

### Not implemented

Registration, login, logout, password reset, email verification, token abilities, and rate limiting on authentication. Each is an application feature.

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
- `per_page`: optional integer, `1` to `200`, default `50` (endpoints may declare a smaller default up to the `200` cap).
- Invalid `page` or `per_page` values (non-integer, `< 1`, `> 200`, floats, empty strings, or arrays) return `422` with field errors in `errors`; values are never silently clamped.
- Valid requests with `page > last_page` return `200` with `"data": []` and accurate `meta` and `links`.
- Every paginated query ends with a deterministic tie-break (`id` ascending or `id` descending) so page boundaries never shift between requests.

## Error responses

| Status | Body | When |
|---|---|---|
| `401` | `{"message": "Unauthenticated."}` | Missing or invalid credentials. |
| `403` | `{"message": "This action is unauthorized."}` or `{"message": "...", "code": "<machine_code>"}` | Role/policy denial or coded domain refusal. |
| `404` | `{"message": "Resource not found."}` | Unknown route or missing record (`ModelNotFoundException`); never exposes model class names. |
| `409` | `{"message": "...", "code": "<machine_code>"}` | Domain conflict. |
| `419` | `{"message": "CSRF token mismatch."}` | Missing or mismatched CSRF token. |
| `422` | `{"message": "...", "errors": {"field": ["..."]}}` | Validation failure. Standard Laravel shape. |
| `423` | `{"message": "...", "code": "<machine_code>"}` | Locked account or resource. |
| `429` | `{"message": "...", "code": "too_many_attempts"}` | Rate limit exceeded; includes `Retry-After` header. |
| `500` | `{"message": "Server error."}` | Unhandled error in every environment, including `APP_DEBUG=true`. Debug detail goes to logs only. |

## Planned resources

These are placeholders for later specifications. None is implemented, and no path is fixed.

| Resource | Purpose | Status |
|---|---|---|
| Sessions or tokens | Sign in and sign out | `TBD` |
| Exams | Create and publish an exam | `TBD` |
| Questions | SQL tasks and expected results | `TBD` |
| Attempts | A student's exam session | `TBD` |
| Submissions | A student's SQL answer and its result | `TBD` |
| Results | Scores and feedback | `TBD` |

Do not implement a path from this table without a feature task and accepted criteria.

## API tooling

No Postman or Bruno collection exists yet. When one is added, it must not contain a real token or password. Use an environment variable for the bearer token.

## Related documents

- [architecture.md](architecture.md)
- [database-design.md](database-design.md)
