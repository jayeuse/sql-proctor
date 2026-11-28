# Implementation plan: Authentication, Authorization, and Audit Logging Modules

| Field | Value |
|---|---|
| Spec | `SPEC-0004` at [SPEC-0004-auth-rbac-and-audit-logging.md](../specs/SPEC-0004-auth-rbac-and-audit-logging.md) |
| Related task | Phase 1: Auth, RBAC, Student Session Entry, and Audit Logging |
| Owner | parent |
| Reviewer | Human Owner |
| Date | 2026-11-28 |
| Authorization | Authorized by Human Owner task directive (2026-11-28) |

## Tasks

| ID | Task | Owner | Depends on | Status |
|---|---|---|---|---|
| P-1 | Spec, ADR, database/API docs, implementation plan, and negative-first test matrix | parent | None | Completed |
| P-2 | Configure PostgreSQL test target (`phpunit.xml`, `.github/workflows/ci.yml`) and `.env.example`/`.env` stateful domain (`localhost:3000`, `SESSION_DOMAIN=localhost`) | parent | P-1 | Completed |
| P-3 | TDD Red: write failing PHPUnit 12 tests in negative-first order covering audit immutability/redaction, bootstrap command, staff auth/hardening, admin examiner management, examiner classrooms, student sessions, and audit endpoints | parent | P-2 | Completed |
| P-4 | TDD Green (Database & Core Services): migrations (`users` auth fields, `classrooms`, `student_sessions`, `audit_logs` + PostgreSQL trigger), enums (`UserRole`, `ActorType`, `AuditEvent`), models (`User`, `Classroom`, `StudentSession`, `AuditLog`), `ImmutableAuditLogException`, `AuditLogger`, `JoinCodeGenerator` | parent | P-3 | Completed |
| P-5 | TDD Green (Auth, Middleware, Policies, Controllers, Command): `BootstrapAdminCommand`, rate limiters, `EnsureUserIsActive`, `EnsureRole`, `VerifyStudentSession`, `EnsureStatefulAuthSession`, policies, form requests, resources, controllers, routes | parent | P-4 | Completed |
| P-6 | Frontend Integration & TDD: install Tailwind CSS v4 + Vitest/RTL, update `lib/api.js` (`withXSRFToken`, `ensureCsrfCookie`), write failing Vitest component tests, implement `/login`, `/`, `/join`, `/admin`, `/examiner`, `/student` | parent | P-5 | Completed |
| P-7 | Verification & Review: Pint `--test`, PHPUnit on PostgreSQL (`sql_proctor_test`), `sql_proctor` `migrate:fresh` + `app:bootstrap-admin`, `npm run lint && npm run build`, Vitest, security/code review complete; live Chrome browser check `BLOCKED` (Chrome `DevToolsActivePort` inactive) | parent | P-6 | Blocked |

## Dependencies

- PostgreSQL 17.12 (`sql_proctor` and `sql_proctor_test`) healthy on `127.0.0.1:5432`.
- MariaDB 11.8.9 remains untouched (student SQL execution is out of scope).
- Frontend npm additions: `tailwindcss`, `@tailwindcss/postcss`, and Vitest/RTL devDependencies.

## Files affected

| Path | Change | Layer |
|---|---|---|
| `docs/specs/SPEC-0004-auth-rbac-and-audit-logging.md` | Create | Docs |
| `docs/specs/README.md` | Modify | Docs |
| `docs/adr/0002-auth-session-boundaries-and-audit-isolation.md` | Create | Docs |
| `docs/adr/README.md` | Modify | Docs |
| `docs/database-design.md` | Modify | Docs |
| `docs/api-specification.md` | Modify | Docs |
| `docs/plans/auth-rbac-and-audit-logging.md` | Create | Docs |
| `docs/plans/auth-rbac-and-audit-test-matrix.md` | Create | Docs |
| `.github/workflows/ci.yml` | Modify (PostgreSQL 17 service + frontend CI lane) | Infrastructure / CI |
| `backend/sql-proctor-api/phpunit.xml` | Modify (`DB_CONNECTION=pgsql`, `DB_DATABASE=sql_proctor_test`) | Backend |
| `backend/sql-proctor-api/.env.example` | Modify (`SANCTUM_STATEFUL_DOMAINS=localhost:3000`, `SESSION_DOMAIN=localhost`) | Backend |
| `backend/sql-proctor-api/config/cors.php` | Modify (`http://localhost:3000`) | Backend |
| `backend/sql-proctor-api/config/sanctum.php` | Modify (default stateful domain alignment) | Backend |
| `backend/sql-proctor-api/database/migrations/*` | Create (4 additive migrations) | Database |
| `backend/sql-proctor-api/database/factories/*` | Modify/Create (`UserFactory`, `ClassroomFactory`, `StudentSessionFactory`) | Backend |
| `backend/sql-proctor-api/app/Enums/*` | Create (`UserRole`, `ActorType`, `AuditEvent`) | Backend |
| `backend/sql-proctor-api/app/Exceptions/ImmutableAuditLogException.php` | Create | Backend |
| `backend/sql-proctor-api/app/Models/*` | Modify/Create (`User`, `Classroom`, `StudentSession`, `AuditLog`) | Backend |
| `backend/sql-proctor-api/app/Services/*` | Create (`AuditLogger`, `JoinCodeGenerator`) | Backend |
| `backend/sql-proctor-api/app/Console/Commands/BootstrapAdminCommand.php` | Create | Backend |
| `backend/sql-proctor-api/app/Http/Middleware/*` | Create (`EnsureUserIsActive`, `EnsureRole`, `VerifyStudentSession`, `EnsureStatefulAuthSession`) | Backend |
| `backend/sql-proctor-api/app/Policies/*` | Create (`UserPolicy`, `ClassroomPolicy`, `AuditLogPolicy`) | Backend |
| `backend/sql-proctor-api/app/Http/Requests/*` | Create (auth, examiner, classroom, student, audit-log requests) | Backend |
| `backend/sql-proctor-api/app/Http/Resources/*` | Create (`UserResource`, `ClassroomResource`, `StudentSessionResource`, `AuditLogResource`) | Backend |
| `backend/sql-proctor-api/app/Http/Controllers/Api/*` | Create (auth, admin examiners, admin audit logs, examiner classrooms, student session) | Backend |
| `backend/sql-proctor-api/app/Providers/AppServiceProvider.php` | Modify (rate limiters + policies) | Backend |
| `backend/sql-proctor-api/bootstrap/app.php` | Modify (middleware aliases) | Backend |
| `backend/sql-proctor-api/routes/api.php` | Modify | Backend |
| `backend/sql-proctor-api/tests/Unit/*` & `tests/Feature/*` | Create (negative-first PHPUnit tests) | Backend (Tests) |
| `frontend/sql-proctor-ui/package.json` & `package-lock.json` | Modify (Tailwind CSS v4 + Vitest/RTL) | Frontend |
| `frontend/sql-proctor-ui/postcss.config.mjs` | Create | Frontend |
| `frontend/sql-proctor-ui/vitest.config.js` & `vitest.setup.js` | Create | Frontend (Tests) |
| `frontend/sql-proctor-ui/lib/api.js` | Modify (`withCredentials`, `withXSRFToken`, `ensureCsrfCookie`) | Frontend |
| `frontend/sql-proctor-ui/app/*` & `components/*` | Modify/Create (Tailwind global styles, `/`, `/join`, `/login`, `/admin`, `/examiner`, `/student`, shared components) | Frontend |
| `frontend/sql-proctor-ui/__tests__/*` | Create (Vitest + RTL negative-first component tests) | Frontend (Tests) |

## Architecture considerations

- Keep Next.js and Laravel strictly separated across HTTP JSON API + cookies.
- Keep `audit_logs`, `users`, `classrooms`, and `student_sessions` solely in PostgreSQL 17.
- Follow `SPEC-0003` (`ApiResponse`, `ApiException`, `PaginatedRequest`, `AssignRequestId`) for every new endpoint.
- Enforce `EncryptCookies` and `StartSession` safely without double-encryption when `EnsureFrontendRequestsAreStateful` is active.

## Verification steps

| # | Command or check | Working directory | Expected result |
|---|---|---|---|
| 1 | `PATH="/opt/homebrew/bin:$PATH" ./vendor/bin/pint --test` | `backend/sql-proctor-api` | Exit 0; zero formatting violations |
| 2 | `PATH="/opt/homebrew/bin:$PATH" php artisan test` | `backend/sql-proctor-api` | Exit 0 against PostgreSQL `sql_proctor_test`; 100% pass, 0 skipped |
| 3 | `PATH="/opt/homebrew/bin:$PATH" composer audit` | `backend/sql-proctor-api` | No unaddressed high/critical vulnerabilities |
| 4 | `npm test && npm run lint && npm run build` | `frontend/sql-proctor-ui` | Exit 0; Vitest, ESLint, and Next build clean |
| 5 | `npm audit` | `frontend/sql-proctor-ui` | Report dependency status (only known pre-existing monaco/dompurify low advisories) |
| 6 | Live browser check (`browser` subagent) | `http://localhost:3000` | Desktop & phone UI flows render cleanly with real backend cookies |
