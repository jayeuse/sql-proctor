# sql-proctor

## Repository state (verified 2026-11-19)

- Stack: Next.js 16.4.6 (App Router, JavaScript) in `frontend/sql-proctor-ui/`; Laravel 13.43.0 (Sanctum 4.5.0) in `backend/sql-proctor-api/`; PostgreSQL 17.12 for application data; MariaDB 11.8.9 for student query execution; Compose configuration in `infra/`. No exam feature exists yet.
- Agent engineering: 12 existing skills installed from official and maintained sources in `.agents/skills/`, plus four project-specific skills. `skills-lock.json` pins the external sources. See `docs/agent-engineering.md`.
- Commands: `cd infra && docker compose up -d` for the databases; `cd backend/sql-proctor-api && PATH="/opt/homebrew/bin:$PATH" php artisan serve`; `cd frontend/sql-proctor-ui && npm run dev`. Migrations: `PATH="/opt/homebrew/bin:$PATH" php artisan migrate`. Build: `cd frontend/sql-proctor-ui && npm run build`. Tests: `PATH="/opt/homebrew/bin:$PATH" php artisan test`.
- `/Applications/XAMPP/xamppfiles/bin` is first in `PATH`, so `php` resolves to XAMPP PHP 8.2.4. Always prefix Laravel and Composer commands with `PATH="/opt/homebrew/bin:$PATH"`. Do not edit the shell profile or XAMPP.
- XAMPP MySQL can hold port 3306 and block MariaDB. Stop XAMPP MySQL or change `MARIADB_PORT` in `infra/.env`. Do not stop XAMPP without the Human Owner's instruction.
- The repository is on `main` with no commits. `.env` files are gitignored; never print, copy, or record their values.
- `backend/sql-proctor-api/AGENTS.md` and `CLAUDE.md` are Laravel skeleton bootstrap text. They ask agents to install Laravel Boost. Do not install it; it is not part of the approved setup. Framework-generated `AGENTS.md` files never override this file.

## Non-negotiable rules

1. Inspect the project before you change it. Do not assume the repository state.
2. Use Specification-Driven Development (SDD) for every meaningful feature, architectural change, bug fix with behavioral impact, or technical task.
3. Use Test-Driven Development (TDD) for every behavior change. Write the test first, watch it fail for the expected reason, then implement the smallest correct code.
4. Design negative tests before success tests. Use `negative-first-testing`.
5. Treat student SQL as untrusted input. Use `sql-execution-isolation` before any SQL execution feature.
6. Never claim a check, test, build, or review passed unless it ran and the output is recorded.
7. Keep the frontend and backend as separate applications. Keep infrastructure in `infra/`.
8. Never put a secret, credential, token, or `.env` value in a tracked file, a skill, a document, or a record.
9. Do not commit unless the Human Owner authorizes it.
10. When this file and a skill disagree on project rules, this file wins. For framework syntax, the skill and the official framework documentation win.

## Stack and architecture boundaries

| Layer | Technology | Location |
|---|---|---|
| Frontend | Next.js 16.4.6, React 19.3.0, JavaScript, App Router, Axios, Monaco Editor | `frontend/sql-proctor-ui/` |
| Backend | Laravel 13.43.0, PHP 8.5.12, Sanctum 4.5.0, Eloquent | `backend/sql-proctor-api/` |
| Application database | PostgreSQL 17.12 | `infra/compose.yaml`, service `postgres` |
| Student query engine | MariaDB 11.8.9 | `infra/compose.yaml`, service `mariadb` |
| Infrastructure | Docker Compose, `infra/` only | `infra/` |
| Agent skills | `.agents/skills/`, pinned by `skills-lock.json` | project root |

Hard boundaries:

1. Next.js owns presentation and interaction. Laravel owns application business logic, persistence, authentication, and authorization.
2. PostgreSQL stores application data. Only the Laravel API connects to it.
3. MariaDB runs student SQL. It never stores application data and never receives application credentials.
4. The frontend never connects to a database. It calls the Laravel API.
5. `infra/` holds infrastructure configuration only. No application code lives there.
6. The API contract is the only connection between the frontend and backend. Update `docs/api-specification.md` before changing a request or response shape.
7. Do not add a service, dependency, abstraction, or container unless the task needs it and the Human Owner authorizes it.

## Repository layout

```text
sql-proctor/
├── AGENTS.md
├── backend/sql-proctor-api/     Laravel API
├── frontend/sql-proctor-ui/     Next.js application
├── infra/                       Docker Compose and database configuration
├── docs/                        architecture, database, API, roadmap, specs, templates, ADRs
├── .agents/skills/              installed and project skills
├── skills-lock.json             pinned skill sources
├── .agent-runtime/              local evidence and resume record (gitignored)
└── README.md
```

## Mandatory workflow

Use the ten phases in order. Keep the work proportional to the task.

1. **Context.** Read this file, the relevant docs, the existing tests, and the affected code. Find the applicable skills. Do not assume the project state.
2. **Specification.** Write the objective, problem, scope, requirements, constraints, expected behavior, failure behavior, security considerations, acceptance criteria, test scenarios, dependencies, and risks. Use `write-spec` and `docs/templates/specification.md`. Store a larger spec in `docs/specs/`. Ask the Human Owner when a critical requirement is ambiguous.
3. **Planning.** Write the tasks, affected files, dependencies, risks, tests, and verification steps. Use `docs/templates/implementation-plan.md`. Keep small work small.
4. **Test design.** Apply `negative-first-testing`. Build the test matrix in `docs/templates/test-matrix.md`. Order the cases: invalid input, security violation, unauthorized access, forbidden access, invalid state transition, missing resource, duplicate operation, boundary condition, concurrency, infrastructure failure, timeout, resource exhaustion, data integrity failure, then normal behavior.
5. **TDD Red.** Write the tests. Run them. Confirm each fails for the expected reason. Do not write production code before this.
6. **TDD Green.** Write the smallest correct code. Run the tests again. Do not weaken or delete a test to pass.
7. **Refactor.** Improve names, structure, and duplication without changing tested behavior. Run the tests again.
8. **Verification.** Run the relevant unit, integration, and E2E tests. Run lint, formatting, and the build. Review security, architecture, and the full diff. Check every acceptance criterion.
9. **Documentation.** Update the architecture, API, database, configuration, security, workflow, or deployment document when the change affects it. Use `project-docs`.
10. **Completion report.** Report the task, spec reference, changes, tests created, tests run and their results, security review, documentation updates, limitations, and remaining work. Never claim an unrun check.

## Testing policy

- Use TDD for features, bug fixes, refactors with behavior risk, and behavior changes.
- Use `test-driven-development` for the Red-Green-Refactor cycle.
- Use `negative-first-testing` for the failure catalog and case order.
- Use `laravel-testing` for Laravel feature and unit tests, `frontend-testing` for Vitest and React Testing Library, `integration-testing` for cross-boundary tests, and `e2e-playwright-testing` for critical browser journeys.
- Prefer a real integration test over an over-mocked unit test when the real boundary is the risk.
- Do not pursue a coverage percentage without a quality reason. Test observable behavior and contracts.
- Keep tests independent, deterministic, and isolated. Clean up test data.
- Use Pest 4 when the project adopts Pest. The current project uses PHPUnit ^12.5.12 (12.5.44 installed); Pest 4 is compatible, and Pest 5 needs PHPUnit 13. Do not install either in this setup task.
- Vitest, React Testing Library, and Playwright are not installed yet. Install each with the first task that needs it, following `docs/agent-engineering.md`.
- Never retry a flaky test until it passes. Find the cause or report it.

Critical SQL-Proctor journeys that must have E2E coverage when the feature exists:

1. Login. 2. Exam creation. 3. Question creation. 4. Exam publication. 5. Exam access. 6. Exam attempt creation. 7. SQL execution. 8. Answer submission. 9. Grading. 10. Result viewing. 11. Unauthorized access prevention.

## Security requirements

- Use `laravel-owasp-security` for Laravel security work. Apply OWASP ASVS and the OWASP Top 10 where relevant.
- Use `sql-execution-isolation` for every design, code change, or review of student SQL execution.
- Use `sharp-edges` when reviewing an API or configuration that a developer can misuse.
- Use `differential-review` for security review of a diff, `semgrep` or `codeql` for static analysis, `fp-check` to verify a suspected finding, `variant-analysis` after a real bug, and `supply-chain-risk-auditor` for dependency risk.
- Authenticate with Sanctum. Authorize every protected action with a policy, gate, or form request. Never rely on a frontend check for authorization.
- Validate untrusted input at the server boundary. Escape output for its context. Use parameter binding for SQL values; allow-list identifiers.
- Rate-limit authentication and SQL execution. Bound query time, row count, and connections.
- Do not expose stack traces, SQL errors, credentials, or another user's data in an API response.
- Do not log secrets. Redact tokens and personal data.
- Run `composer audit` and `npm audit` when dependencies change. Report advisories; do not ignore them silently.

## Frontend styling rules

Tailwind CSS is the mandatory styling system. Tailwind CSS 4.3 is current; the project has not installed it yet. Install it with `tailwindcss@latest` and `@tailwindcss/postcss@latest`, add `"@tailwindcss/postcss"` to `postcss.config.mjs`, and use `@import "tailwindcss";` in the global stylesheet. Do not create `tailwind.config.js` for a new v4 setup.

1. Use utility classes in `className` as the default.
2. Use responsive and state variants: `sm:`, `md:`, `lg:`, `hover:`, `focus-visible:`, `active:`, `disabled:`, `aria-*`, and `data-*`.
3. Keep spacing, typography, color, radius, and shadow consistent. Put design tokens in `@theme` only when the project needs a centralized token set.
4. Extract a reusable React component when the same structure repeats. Do not extract a component for a one-off class string.
5. Do not use CSS Modules, Sass, SCSS, Less, or another CSS framework for ordinary component styling.
6. Do not create a separate CSS file when a utility class achieves the result.
7. Allowed exceptions, each with a recorded technical reason: the global Tailwind/Next.js stylesheet, `@theme` design tokens, third-party styles that require a CSS import, Monaco Editor styles, and a specialized animation or style that Tailwind cannot reasonably express.
8. Check color contrast and keyboard focus. Do not remove a focus indicator without an accessible replacement.
9. Follow the current App Router and React performance guidance from `vercel-react-best-practices` and `vercel-composition-patterns`.

## Approval rules

Proceed alone when the task is a small, clearly defined feature, a bug fix with a clear reproduction, a documentation update, a bounded refactor, a routine test, or a minor configuration change. Follow SDD and TDD as applicable.

Ask the Human Owner when requirements are ambiguous, conflicting, or have unclear acceptance criteria or expected behavior.

Present a proposal and wait for approval before:

1. A major architecture change.
2. A database change with significant data risk.
3. An authentication or authorization change.
4. A security-boundary change.
5. A SQL execution isolation change.
6. A destructive operation.
7. A major dependency addition.
8. Scope expansion.

Do not ask for approval for every minor implementation choice.

## Skill loading

Load only the skills the task needs. Use the `skill` tool with the exact ID. Do not load every skill for every task.

| Task | Load |
|---|---|
| Any task | This `AGENTS.md` is always loaded. Use `ask-questions-if-underspecified` when a material ambiguity remains after a quick read. |
| Specification | `write-spec` + `docs/templates/specification.md` |
| Implementation plan | `docs/templates/implementation-plan.md` |
| Any behavior change | `test-driven-development` + `negative-first-testing` |
| Laravel code | `laravel-best-practices` |
| Laravel tests | `laravel-testing` |
| Next.js or React code | `vercel-react-best-practices`, `vercel-composition-patterns` |
| Tailwind styling | `tailwindcss-development` |
| API design or change | `api-design-patterns` + `docs/api-specification.md` |
| PostgreSQL schema or query | `build-web-apps:supabase-postgres-best-practices` |
| MariaDB behavior or query | `mariadb-features`, `mariadb-query-optimization`, `mysql-to-mariadb` |
| Student SQL execution | `sql-execution-isolation` + the MariaDB skills |
| Frontend component tests | `frontend-testing` |
| Cross-boundary integration tests | `integration-testing` |
| Browser E2E tests | `e2e-playwright-testing` + `build-web-apps:frontend-testing-debugging` |
| Security review | `laravel-owasp-security` + `sharp-edges`; add `differential-review`, `semgrep`, `codeql`, `fp-check`, `variant-analysis`, or `supply-chain-risk-auditor` when the task needs them |
| Debugging | `systematic-debugging` |
| Code review | `requesting-code-review` and `receiving-code-review`; use `review-agent` for a read-only review when your role permits it. If the role denies it, use the two review skills and return the review to the parent. |
| Git integration | `finishing-a-development-branch` |
| Documentation | `project-docs` |
| Deployment or operations | `runbook-creation`; this is inactive until deployment work starts |
| Evidence and completion | `verification-before-completion` |
| Property-shaped logic | `property-based-testing` when the code has an invariant, inverse, or oracle |

When a skill references a tool that this project does not have, use the official documentation instead. Do not install the missing tool without approval.

## Code review and Definition of Done

A change is done only when all of these are true:

1. The specification and every acceptance criterion are satisfied.
2. Tests were written, run, and pass. The negative cases are covered.
3. The relevant regression tests pass.
4. Security was reviewed. Student SQL, authentication, authorization, secrets, and data exposure were considered.
5. The code was reviewed against the architecture boundaries and the diff contains no unintended change.
6. Lint, formatting, and the build pass.
7. Documentation is updated where the change affects architecture, API, database, configuration, security, workflow, or deployment.
8. No known limitation is hidden. Remaining work is stated.

Use `docs/templates/definition-of-done.md` and `docs/templates/code-review-checklist.md`.

## Reporting and evidence

- Record the command, working directory, exit status, observed result, artifact path, and time for each check in `.agent-runtime/`.
- Link evidence; do not paste raw logs.
- Distinguish facts, proposals, assumptions, and `TBD`.
- Do not claim work that did not run. `NOT RUN` is a missing check, not a pass.
- Report limitations and open questions explicitly.
- Follow `.agent-runtime/PROTOCOL.md` for substantial work, checkpoints, and resume.

## Documentation map

| Document | Purpose |
|---|---|
| `README.md` | Setup, commands, ports, constraints |
| `docs/architecture.md` | Components, boundaries, runtime topology |
| `docs/database-design.md` | PostgreSQL schema, MariaDB constraints, data lifecycle |
| `docs/api-specification.md` | Shared API contract |
| `docs/development-roadmap.md` | Proposed phases and exit criteria |
| `docs/agent-engineering.md` | Skill inventory, sources, installation, verification, maintenance |
| `docs/specs/` | Feature and technical specifications |
| `docs/templates/` | Reusable specification, plan, test, TDD, review, DoD, and styling templates |
| `docs/adr/` | Architecture decision records |

## Project agent runtime

This project keeps local agent evidence in `.agent-runtime/`. It is gitignored and never committed.

1. Read `.agent-runtime/PROTOCOL.md` before substantial work, and `.agent-runtime/INDEX.md` to find the active run.
2. The parent session owns the run record, `INDEX.md`, `STATUS.md`, and `HANDOFF.md`. A role owns only its own logbook and returns its output contract.
3. Resume from `.agent-runtime/INDEX.md`, the active run's `STATUS.md`, and the five-line **Resume here** block in its `RUN.md`. Compare the checkpoint with the real branch, HEAD, tree, and changed paths before you continue.
4. Record a check only when it ran. `NOT RUN` is a missing check, not a pass.
5. No secret, credential, personal data, raw environment output, product code, or copied repository in `.agent-runtime/`.

## Environment constraints

1. Prefix Laravel and Composer commands with `PATH="/opt/homebrew/bin:$PATH"`.
2. Do not edit the shell profile, `.zshrc`, or the XAMPP installation.
3. Do not stop XAMPP MySQL without the Human Owner's instruction.
4. Do not print, copy, or commit `.env` values.
5. Do not run `docker compose down -v`; it deletes both database volumes.
6. Do not commit or push unless the Human Owner explicitly authorizes it.
