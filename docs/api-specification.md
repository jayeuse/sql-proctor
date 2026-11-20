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
2. Without that header, an unauthenticated request tries to redirect to a `login` route that does not exist and returns `500`. This is Laravel's default behavior. The Axios client sets the header.
3. Timestamps are ISO 8601 in UTC.
4. Identifiers are integers for application records. The public identifier format is `TBD`.
5. Authentication uses Laravel Sanctum. Protected routes use the `auth:sanctum` middleware.
6. API versioning is not implemented. A version prefix is `TBD`.
7. CORS allows `http://localhost:3000` and `http://127.0.0.1:3000` with credentials.

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

## Error responses

| Status | Body | When |
|---|---|---|
| `401` | `{"message":"Unauthenticated."}` | Missing or invalid credentials. Verified. |
| `422` | `{"message":"...","errors":{"field":["..."]}}` | Validation failure. Standard Laravel shape. No endpoint exercises it yet. |
| `404` | `{"message":"..."}` | Unknown route or record. Standard Laravel shape. |
| `500` | Error detail | Unhandled error. `APP_DEBUG=true` locally exposes the trace. Production must set `APP_DEBUG=false`. |

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
