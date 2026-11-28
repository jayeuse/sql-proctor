# Implementation plan: Exam Authoring, Isolated MariaDB SQL Execution, Student Examination, and Grading

| Field | Value |
|---|---|
| Spec | `SPEC-0005` at [SPEC-0005-exam-authoring-and-student-examination.md](../specs/SPEC-0005-exam-authoring-and-student-examination.md) |
| Related task | Phases 2–4: Exam Authoring, Isolated SQL Execution, Student Examination, and Grading |
| Owner | parent |
| Reviewer | Human Owner |
| Date | 2026-11-28 |
| Authorization | Authorized by Human Owner task directive (2026-11-28) |

## Tasks

| ID | Task | Owner | Depends on | Status |
|---|---|---|---|---|
| P-1 | Discovery, MariaDB port 3307 resolution, Monaco `dompurify` security upgrade (`npm audit fix`), SPEC-0005, ADR-0003, plan, test matrix, and documentation sync | parent | None | Completed |
| P-2 | Configure MariaDB sandbox accounts (`sql_provisioner`, hardened `sql_runner`), `infra/mariadb-init/`, `config/database.php`, `config/sql_proctor.php`, and `.env.example`/`.env` | parent | P-1 | Completed |
| P-3 | TDD Red (Backend): write negative-first PHPUnit 12 feature/integration tests covering dataset validation, MariaDB isolation/escapes/timeouts/row-caps/cleanup, authoring/locks/versioning, grading/overrides, student flow/anti-leakage, and admin oversight | parent | P-2 | Completed |
| P-4 | TDD Green (Database & MariaDB Engine): PostgreSQL migrations (10 tables), enums, models, factories, `AuditEvent` extensions, `SandboxManager`, `SqlGuard`, `SqlExecutionService`, `SchemaIntrospector`, `CleanupOrphanSandboxesCommand` | parent | P-3 | Completed |
| P-5 | TDD Green (Grading & APIs): `ResultSetComparator`, `ExamGradingService`, `ExamValidationService`, `ExamVersioningService`, policies, FormRequests, Resources (anti-leakage), Controllers, rate limiters, routes | parent | P-4 | Completed |
| P-6 | Verify backend Pint + full PHPUnit suite on PostgreSQL `sql_proctor_test` + live MariaDB `sql_exec`, verify zero `sbx_*` leaks, and update `.github/workflows/ci.yml` | parent | P-5 | Completed |
| P-7 | Frontend TDD Red -> Green: `SqlEditor` (`@monaco-editor/react`), Examiner datasets/exams/grading console, Admin Exams oversight tab, Student exam dashboard/workspace/results, Vitest + RTL + MSW tests, ESLint, `next build` | parent | P-6 | Completed |
| P-8 | Playwright Chromium E2E (`exam-lifecycle.spec.js` + mobile `375x812` responsive checks), dependency audits, security/code review, final DoD verification, and `.agent-runtime/` completion | parent | P-7 | Completed |

## Dependencies

- PostgreSQL 17.12 (`sql_proctor` and `sql_proctor_test`) healthy on `127.0.0.1:5432`.
- MariaDB 11.8.9 (`sql-proctor-mariadb-1`) healthy on `127.0.0.1:3307` locally (`3306` in CI) without stopping local XAMPP MySQL.
- Upgraded `monaco-editor@0.58.0` / `dompurify@3.4.17` (`0` vulnerabilities in `npm audit`).

## Files affected

| Path | Change | Layer |
|---|---|---|
| `docs/specs/SPEC-0005-exam-authoring-and-student-examination.md`, `docs/specs/README.md` | Create / Modify | Docs |
| `docs/adr/0003-mariadb-sql-execution-isolation-and-exam-versioning.md`, `docs/adr/README.md` | Create / Modify | Docs |
| `docs/plans/exam-authoring-and-student-examination.md`, `docs/plans/exam-authoring-and-student-examination-test-matrix.md` | Create | Docs |
| `docs/architecture.md`, `docs/database-design.md`, `docs/api-specification.md`, `docs/development-roadmap.md` | Modify | Docs |
| `infra/compose.yaml`, `infra/.env.example`, `infra/README.md`, `infra/mariadb-init/01-sandbox-accounts.sh` | Modify / Create | Infrastructure |
| `.github/workflows/ci.yml` | Modify (add `mariadb:11.8.9` service + account init) | Infrastructure / CI |
| `backend/sql-proctor-api/.env.example`, `config/database.php`, `config/sql_proctor.php`, `bootstrap/app.php`, `routes/api.php`, `app/Providers/AppServiceProvider.php` | Modify / Create | Backend Config & Routing |
| `backend/sql-proctor-api/database/migrations/*` & `database/factories/*` | Create | Database & Factories |
| `backend/sql-proctor-api/app/Enums/*`, `app/Exceptions/*`, `app/Models/*`, `app/Policies/*` | Modify / Create | Backend Domain |
| `backend/sql-proctor-api/app/Services/SqlExecution/*`, `app/Services/Grading/*`, `app/Services/Exams/*`, `app/Console/Commands/*` | Create | Backend Services |
| `backend/sql-proctor-api/app/Http/Requests/*`, `app/Http/Resources/*`, `app/Http/Controllers/Api/*` | Create | Backend HTTP |
| `backend/sql-proctor-api/tests/Feature/*` & `tests/Unit/*` | Create | Backend Tests |
| `frontend/sql-proctor-ui/package.json`, `package-lock.json` | Modify (`npm audit fix`) | Frontend |
| `frontend/sql-proctor-ui/components/*`, `frontend/sql-proctor-ui/app/*` | Modify / Create | Frontend UI |
| `frontend/sql-proctor-ui/__tests__/*` & `tests/e2e/specs/*` | Create / Modify | Frontend & E2E Tests |

## Architecture considerations

- Strict PostgreSQL / MariaDB boundary (`ADR-0002`, `ADR-0003`): MariaDB only runs ephemeral `sbx_<hex>` sandboxes; zero app data or audit logs enter MariaDB.
- `ApiException` keeps `5xx` responses sanitized to `{"message": "Server error."}` per `SPEC-0003`; MariaDB downtime throws `SandboxUnavailableException` rendered as `503` (`execution_engine_unavailable`).
- Student serializers enforce zero leakage of reference solutions, hidden test cases, or MCQ keys.

## Verification steps

| # | Command or check | Working directory | Expected result |
|---|---|---|---|
| 1 | `PATH="/opt/homebrew/bin:$PATH" ./vendor/bin/pint --test` | `backend/sql-proctor-api` | Exit 0; zero style violations |
| 2 | `PATH="/opt/homebrew/bin:$PATH" php artisan test` | `backend/sql-proctor-api` | Exit 0 against PostgreSQL `sql_proctor_test` + live MariaDB `sql_exec` |
| 3 | Verify zero `sbx_*` databases remain in MariaDB | `infra` | `SHOW DATABASES LIKE 'sbx_%'` empty |
| 4 | `PATH="/opt/homebrew/bin:$PATH" composer audit` & `npm audit` | Backend & Frontend | `npm audit`: 0 vulnerabilities; `composer audit` status documented |
| 5 | `npm test && npm run lint && npm run build` | `frontend/sql-proctor-ui` | Exit 0 |
| 6 | `npm run test:e2e` | `frontend/sql-proctor-ui` | Exit 0 across desktop `1280x800` and mobile `375x812` |
