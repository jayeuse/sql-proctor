# ADR-0002: Dual Session Authentication Boundaries and Append-Only PostgreSQL Audit Logging

| Field | Value |
|---|---|
| Status | Accepted |
| Date | 2026-11-28 |
| Decision owner | Human Owner |
| Related spec | [SPEC-0004](../specs/SPEC-0004-auth-rbac-and-audit-logging.md) |
| Related task | Phase 1: Auth, RBAC, Student Session Entry, and Audit Logging |

## Context

SQL-Proctor serves three distinct actors with fundamentally different trust and onboarding models:

1. **Staff (`admin`, `examiner`)** hold long-lived privileged accounts authenticated by `username` + `password` and authorized to manage accounts, classrooms, and future exam definitions or audit trails.
2. **Students (`student`)** enter Kahoot-style using only a temporary `display_name` and an examiner-issued `class_code`, without registering accounts or holding passwords.
3. **Security & Compliance (`audit_logs`)** require tamper-evident recording of authentication, account management, class lifecycle, and student entry events across a dual-database architecture where MariaDB 11.8.9 executes untrusted student SQL and PostgreSQL 17.12 owns all application data.

Mixing students into `users` with synthetic passwords or storing audit records near the MariaDB execution sandbox would blur authorization boundaries and undermine audit integrity.

## Decision

1. **Separate staff SPA sessions from student session cookies:**
   - **Staff (`admin`, `examiner`)** authenticate over Laravel Sanctum SPA stateful cookie auth (`web` guard backed by PostgreSQL `sessions` table, `EnsureFrontendRequestsAreStateful`, CSRF cookie via `GET /sanctum/csrf-cookie`, `SANCTUM_STATEFUL_DOMAINS=localhost:3000`, and `SESSION_DOMAIN=localhost`).
   - **Students (`student`)** never receive rows in `users` or `sessions`. Instead, `POST /api/student/join` creates a `student_sessions` record in PostgreSQL and issues a dedicated signed, AES-encrypted, HTTP-only, `SameSite=Lax` cookie (`student_session_id`) validated by `VerifyStudentSession` against active, non-expired, non-revoked session state and an open `classroom`.
   - Staff endpoints (`auth:sanctum` + `EnsureUserIsActive` + `EnsureRole`) and student endpoints (`VerifyStudentSession`) enforce completely separate guards so a student cookie cannot authenticate to `/api/user`, `/api/admin/*`, `/api/examiner/*`, or `/api/audit-logs`.

2. **Store `audit_logs` exclusively in PostgreSQL 17 with dual-layer append-only enforcement:**
   - `audit_logs` lives solely in PostgreSQL 17 (`sql_proctor` / `sql_proctor_test`). MariaDB 11.8 never holds, reads, or references audit tables.
   - All writes flow through a single `AuditLogger` service that attaches correlation metadata (`request_id`, `ip_address`, `user_agent`) and recursively redacts sensitive keys (`password`, `password_confirmation`, `current_password`, `token`, `cookie`, `authorization`, `xsrf`, `secret`) prior to insertion.
   - Immutability is enforced at **both** layers: Eloquent `updating` and `deleting` model hooks throw `ImmutableAuditLogException`, and a PostgreSQL `BEFORE UPDATE OR DELETE ON audit_logs` trigger (`prevent_audit_logs_mutation()`) raises an exception even against raw SQL statements (`DB::statement`).

## Consequences

1. Privilege separation between staff and students is structural: compromise or forgery of a student flow cannot mint a `User` or pass `auth:sanctum`.
2. Student entry remains frictionless (no registration/password overhead) while supporting immediate revocation (`POST /api/student/leave`), TTL expiration, and classroom closure checks.
3. Audit logs remain isolated from future untrusted student SQL execution on MariaDB and cannot be altered through Eloquent or application-user SQL mutations.
4. Automated backend tests (`phpunit.xml`) and CI must execute against PostgreSQL 17 so the PL/pgSQL trigger, `jsonb`, `inet`, and partial unique index constraints are verified faithfully rather than mocked on SQLite.
5. **Known boundary limitation:** The PostgreSQL trigger blocks `UPDATE` and `DELETE` per row, whereas `TRUNCATE` or superuser `ALTER TABLE ... DISABLE TRIGGER` bypass row triggers unless restricted by database role privileges in production deployment (Phase 5).

## Alternatives considered

| Alternative | Why it was not chosen |
|---|---|
| Create synthetic `users` rows with `role = 'student'` and dummy passwords | Pollutes staff `users` table, weakens credential hygiene, and risks RBAC/session confusion between staff and ephemeral exam entrants. |
| Issue stateless Bearer tokens in `localStorage` for SPA auth | Exposes credentials to XSS; `httpOnly` Sanctum SPA cookies and encrypted `student_session_id` cookies protect session identifiers from JavaScript reads. |
| Enforce audit append-only behavior only in Eloquent model events | Easily bypassed by `DB::table('audit_logs')->update(...)` or raw SQL; adding a PostgreSQL trigger closes the persistence-layer gap. |
| Store audit logs in MariaDB alongside student query execution | Violates the hard architectural isolation rule (`AGENTS.md`, `docs/database-design.md`) that MariaDB never stores application or audit data. |

## References

- [AGENTS.md](../../AGENTS.md)
- [SPEC-0004](../specs/SPEC-0004-auth-rbac-and-audit-logging.md)
- [docs/database-design.md](../database-design.md)
- [docs/api-specification.md](../api-specification.md)
