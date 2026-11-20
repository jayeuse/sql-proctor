# Agent Engineering: skills, sources, and maintenance

> Status: Current for the SQL-Proctor repository on 2026-11-19. This document records what is installed, where it came from, how it was modified, how it is verified, and how to maintain it. It does not claim a verification that TASK-0112 or TASK-0113 owns.

| Field | Value |
|---|---|
| Owner | Engineering Manager |
| Related rules | [AGENTS.md](../AGENTS.md) |
| Related records | DEC-0044 (discovery-first skill sourcing), APR-0015 (setup authorization), TASK-0106 to TASK-0115 |
| Evidence | `.agent-runtime/runs/2026-11-19-agent-engineering-setup/evidence/` |

## Purpose

This document is the single record of the project's agent engineering setup. It answers five questions:

1. Which skill covers which required capability.
2. Where each skill came from and how it was installed.
3. What was changed after installation.
4. What is verified, and what is still open.
5. How to install, update, and remove a skill without breaking the project rules.

The root [AGENTS.md](../AGENTS.md) holds the project rules and the skill-loading map. This document holds the inventory and the maintenance procedure. A rule and this inventory must not disagree; when they do, `AGENTS.md` wins.

## Discovery-first policy

DEC-0044 sets this priority for every required capability:

1. Official framework skills.
2. Skills from a trusted organization.
3. Maintained community skills.
4. A limited adaptation of an existing skill.
5. A custom skill, only when no suitable existing skill covers the requirement, with the reason recorded.

The policy adds these rules:

1. Search existing skills before writing anything. Record the candidates, the evaluation, and the selection.
2. Install with the `skills` CLI. It installs project skills into `.agents/skills/` and writes `skills-lock.json`.
3. `.agents/skills/` is a documented OpenCode compatibility path. No extra `opencode.json` entry is needed.
4. The requested root `skills/` directory is not used. The supported location is `.agents/skills/`.
5. `sql-proctor-project-rules` is replaced by the root `AGENTS.md`. Project-wide rules are always loaded and are not duplicated in a skill.
6. A standalone `spec-driven-development` skill is replaced by the global `write-spec` skill plus the SDD workflow in `AGENTS.md` and the templates in `docs/templates/`.
7. Cross-cutting global skills are referenced from `AGENTS.md`, not copied into the repository.
8. A custom skill needs a recorded reason.

## Where skills live and how they load

| Item | Location | Meaning |
|---|---|---|
| Project skills | `.agents/skills/<name>/SKILL.md` | Installed or custom. Discovered by OpenCode. |
| Lock file | [skills-lock.json](../skills-lock.json) | Source, commit, path, hash, and install mode for each installed skill. |
| Rules hub | [AGENTS.md](../AGENTS.md) | Project rules, always loaded, and the skill-loading map. |
| Global skills | Harness skill store | Referenced by exact ID from `AGENTS.md`; not copied into the repository. |
| Local evidence | `.agent-runtime/` | Install transcripts, research notes, and verification. Gitignored. |

A skill is a directory with a `SKILL.md` file. The YAML frontmatter `name` and `description` are the discovery fields.

## Skill inventory

Type values:

- `Original`: installed from upstream and used without a deliberate project change.
- `Adapted`: installed from upstream and changed for this project; the change is recorded below.
- `Custom`: written in this repository because no existing skill covered the need.

Repository links are the GitHub form of the `source` field in `skills-lock.json`. The `skills.sh` links are the ones printed by the install run. Commit values are short prefixes; the full commit, `skillPath`, and hash are in `skills-lock.json`.

| Skill | Type | Source | Original link | Install method | Purpose | Modifications | Verification status |
|---|---|---|---|---|---|---|---|
| `laravel-best-practices` | Adapted | `laravel/boost` @ `6004f09e` | [github.com/laravel/boost](https://github.com/laravel/boost), [skills.sh/laravel/boost](https://skills.sh/laravel/boost) | `npx skills add`, copy mode, path `.ai/laravel/skill/laravel-best-practices` | Laravel 13 patterns for controllers, models, migrations, form requests, policies, jobs, and Eloquent | The `search-docs` references replaced with Context7 or the official Laravel 13 documentation; six `.blade.php` rule files converted to Markdown (`@php` block removed, `artisanCommand`/`composerCommand` placeholders rendered); the rule index now points to `laravel-testing` instead of the rejected `testing-best-practices` skill. | Listed; Snyk Low |
| `tailwindcss-development` | Adapted | `laravel/boost` @ `6004f09e` (ref `main`) | [github.com/laravel/boost](https://github.com/laravel/boost), [skills.sh/laravel/boost](https://skills.sh/laravel/boost) | `npx skills add`, copy mode, path `.ai/tailwindcss/4/skill/tailwindcss-development` | Tailwind CSS 4 utilities, responsive layout, state variants, dark mode | One `search-docs` reference replaced with the official Tailwind CSS v4 documentation and Context7. The first install selected the v3 skill; the v3 skill was removed and the v4 path was installed. | Listed; Snyk Low |
| `laravel-testing` | Adapted | `AsyrafHussin/agent-skills` @ `1aa0ff71` | [github.com/AsyrafHussin/agent-skills](https://github.com/AsyrafHussin/agent-skills), [skills.sh/AsyrafHussin/agent-skills](https://skills.sh/AsyrafHussin/agent-skills) | `npx skills add`, copy mode | Laravel 13 feature and unit tests with Pest 4 or PHPUnit 12 | Removed duplicate upstream `AGENTS.md` and `README.md`; `SKILL.md` and rules unchanged. | Listed; Snyk Low |
| `api-design-patterns` | Adapted | `AsyrafHussin/agent-skills` @ `1aa0ff71` | [github.com/AsyrafHussin/agent-skills](https://github.com/AsyrafHussin/agent-skills), [skills.sh/AsyrafHussin/agent-skills](https://skills.sh/AsyrafHussin/agent-skills) | `npx skills add`, copy mode | REST design, error responses, versioning, and endpoint structure | Removed duplicate upstream `AGENTS.md` and `README.md`; `SKILL.md` and rules unchanged. | Listed; Snyk Low |
| `e2e-playwright-testing` | Adapted | `AsyrafHussin/agent-skills` @ `1aa0ff71` | [github.com/AsyrafHussin/agent-skills](https://github.com/AsyrafHussin/agent-skills), [skills.sh/AsyrafHussin/agent-skills](https://skills.sh/AsyrafHussin/agent-skills) | `npx skills add`, copy mode | Playwright E2E design, locators, web-first assertions, auth storage state, isolation | Removed duplicate upstream `AGENTS.md` and `README.md`; `SKILL.md` and rules unchanged. | Listed; Snyk Low |
| `laravel-owasp-security` | Adapted | `AsyrafHussin/agent-skills` @ `1aa0ff71` | [github.com/AsyrafHussin/agent-skills](https://github.com/AsyrafHussin/agent-skills), [skills.sh/AsyrafHussin/agent-skills](https://skills.sh/AsyrafHussin/agent-skills) | `npx skills add`, copy mode | OWASP Top 10 audit and secure Laravel code for authentication, payments, uploads, and API design | Removed duplicate upstream `AGENTS.md` and `README.md`; `SKILL.md` and rules unchanged. | Listed; Snyk Low |
| `project-docs` | Adapted | `AsyrafHussin/agent-skills` @ `1aa0ff71` | [github.com/AsyrafHussin/agent-skills](https://github.com/AsyrafHussin/agent-skills), [skills.sh/AsyrafHussin/agent-skills](https://skills.sh/AsyrafHussin/agent-skills) | `npx skills add`, copy mode | Documentation lifecycle, naming, freshness, and cleanup of stale files | Removed duplicate upstream `AGENTS.md` and `README.md`; `SKILL.md` and rules unchanged. | Listed; Snyk Low |
| `mariadb-features` | Original | `MariaDB/skills` @ `5895ef8d` | [github.com/MariaDB/skills](https://github.com/MariaDB/skills), [skills.sh/MariaDB/skills](https://skills.sh/MariaDB/skills) | `npx skills add`, copy mode | MariaDB capabilities beyond standard MySQL | None recorded | Listed; Snyk Low |
| `mariadb-query-optimization` | Original | `MariaDB/skills` @ `5895ef8d` | [github.com/MariaDB/skills](https://github.com/MariaDB/skills), [skills.sh/MariaDB/skills](https://skills.sh/MariaDB/skills) | `npx skills add`, copy mode | Indexing, `EXPLAIN`, pagination, statistics, and optimizer settings | None recorded | Listed; Snyk Medium (see Known limitations) |
| `mysql-to-mariadb` | Original | `MariaDB/skills` @ `5895ef8d` | [github.com/MariaDB/skills](https://github.com/MariaDB/skills), [skills.sh/MariaDB/skills](https://skills.sh/MariaDB/skills) | `npx skills add`, copy mode | MySQL to MariaDB compatibility and the points where MariaDB diverges | None recorded | Listed; Snyk Low |
| `test-driven-development` | Original | `obra/superpowers` @ `22f0b463` | [github.com/obra/superpowers](https://github.com/obra/superpowers), [skills.sh/obra/superpowers](https://skills.sh/obra/superpowers) | `npx skills add`, copy mode | Red-Green-Refactor discipline before implementation code | None recorded | Listed; Snyk Low |
| `runbook-creation` | Adapted | `BagelHole/DevOps-Security-Agent-Skills` @ `0365f57a` | [github.com/BagelHole/DevOps-Security-Agent-Skills](https://github.com/BagelHole/DevOps-Security-Agent-Skills), [skills.sh/BagelHole/DevOps-Security-Agent-Skills](https://skills.sh/BagelHole/DevOps-Security-Agent-Skills) | `npx skills add`, copy mode, path `compliance/continuity/runbook-creation` | Operational runbooks and standard operating procedures | Example links converted to plain file names; project note added. | Listed; Snyk Low; inactive until deployment work |

### Custom skills

| Skill | Type | Source | Original link | Install method | Purpose | Modifications | Verification status |
|---|---|---|---|---|---|---|---|
| `sql-execution-isolation` | Custom | This repository (TASK-0107) | None; no upstream | Written in `.agents/skills/`, not installed by the CLI | Per-attempt MariaDB isolation, least privilege, resource limits, and cleanup for untrusted student SQL | Not applicable | Verified: format PASS, load PASS (TASK-0113) |
| `negative-first-testing` | Custom | This repository (TASK-0108) | None; no upstream | Written in `.agents/skills/`, not installed by the CLI | Failure-first order, failure catalog, test matrix, and verified TDD Red evidence | Not applicable | Verified: format PASS, load PASS (TASK-0112) |
| `integration-testing` | Custom | This repository (TASK-0108) | None; no upstream | Written in `.agents/skills/`, not installed by the CLI | Contract matrix across Laravel, PostgreSQL, MariaDB, and Next.js | Not applicable | Verified: format PASS; load not exercised (TASK-0113) |
| `frontend-testing` | Custom | This repository (TASK-0109) | None; no upstream | Written in `.agents/skills/`, not installed by the CLI | Vitest and React Testing Library for Next.js 16 client/server boundaries, forms, UI states, and authentication states | Not applicable | Verified: format PASS; load not exercised (TASK-0113) |

### Recorded modifications

Eight installed skills have a deliberate project change: two guidance adaptations, five duplicate support-file removals, and one example-link cleanup. The adaptation evidence is `.agent-runtime/runs/2026-11-19-agent-engineering-setup/evidence/04-skill-adaptation.txt`.

| Skill | Change | Reason |
|---|---|---|
| `laravel-best-practices` | The `search-docs` references were replaced with Context7 or the official Laravel 13 documentation; six `.blade.php` rule files were converted to Markdown; the rule index now points to `laravel-testing` | Laravel Boost is not installed; the project rule forbids it. The reference pointed at a tool that does not exist here. The Blade files were the Boost installer format and would not resolve as Markdown rules. The original rule index named `testing-best-practices`, which is rejected and not installed. |
| `tailwindcss-development` | The `search-docs` reference was replaced with the official Tailwind CSS v4 documentation and Context7; the v3 skill was removed and the v4 path installed | Same reason for the documentation reference. The CLI selected the v3 skill because both versions share a name; the project requires v4. |
| `laravel-testing` | Removed the duplicate upstream `AGENTS.md` and `README.md` | They duplicate the skill body and can pollute the instruction context. `SKILL.md` and the rules are unchanged. |
| `api-design-patterns` | Removed the duplicate upstream `AGENTS.md` and `README.md` | Same reason. |
| `e2e-playwright-testing` | Removed the duplicate upstream `AGENTS.md` and `README.md` | Same reason. |
| `laravel-owasp-security` | Removed the duplicate upstream `AGENTS.md` and `README.md` | Same reason. |
| `project-docs` | Removed the duplicate upstream `AGENTS.md` and `README.md` | Same reason. |
| `runbook-creation` | Converted six example index links to plain file names and added a project note | The links pointed to runbooks that do not exist in this project. The examples are still readable and the relative-link check is clean. |

The four custom skills are not modifications of an upstream skill. They were written because no existing skill covered the need (DEC-0044 point 8).

### What the verification status means

| Value | Meaning |
|---|---|
| Listed | The skill is present in `.agents/skills/` and was returned by `npx skills list --json` on 2026-11-19. Its source and hash are pinned in `skills-lock.json`. |
| Snyk Low / Medium | The security assessment printed by the install run for that skill. |
| Pending | The skill's format, frontmatter, relative links, and load behavior are verified by TASK-0111 and TASK-0113, not by this document. |
| Verified | The skill passed the format, frontmatter, and relative-link checks. The load result is stated in the row. |

A custom skill is not in `skills-lock.json` and `npx skills list` reports it as `untracked`. That is expected: the CLI did not install it, so it has no upstream source or hash to pin.

## Required-capability matrix

Every capability the Human Owner's instruction requires has exactly one primary coverage. The matrix names the skill ID, the global skill, or the `AGENTS.md` section.

| # | Required capability | Covered by | Notes |
|---|---|---|---|
| 1 | `sql-proctor-project-rules` | Root [AGENTS.md](../AGENTS.md): "Non-negotiable rules", "Stack and architecture boundaries", "Security requirements", "Approval rules", "Skill loading" | Replaces a skill (DEC-0044 point 4). A framework-generated `AGENTS.md` never overrides it. |
| 2 | `spec-driven-development` | Global `write-spec`; `AGENTS.md` "Mandatory workflow" phases 2 and 3; [templates/specification.md](templates/specification.md); [templates/implementation-plan.md](templates/implementation-plan.md) | No standalone SDD skill (DEC-0044 point 5). |
| 3 | `test-driven-development` | `test-driven-development`; `AGENTS.md` "Testing policy"; [templates/tdd-checklist.md](templates/tdd-checklist.md) | Red is verified before implementation. |
| 4 | `negative-first-testing` | `negative-first-testing` (custom); `AGENTS.md` non-negotiable rule 4 and phase 4; [templates/test-matrix.md](templates/test-matrix.md) | Failure catalog and ordering. |
| 5 | `testing-strategy` | `AGENTS.md` "Testing policy" and "Code review and Definition of Done"; the four testing skills; the test, TDD, review, and DoD templates | The policy is in `AGENTS.md`; no separate strategy skill. |
| 6 | `laravel-development` | `laravel-best-practices`; `laravel-owasp-security` for security; `laravel-testing` for tests | Laravel 13.43.0 on PHP 8.5.12. |
| 7 | `nextjs-development` | Global `vercel-react-best-practices`, `vercel-composition-patterns`; the official Next.js documentation through Context7 | Next.js 16.4.6. The official `next-dev-loop` skill is rejected because it needs the `agent-browser` CLI. |
| 8 | `tailwindcss-development` | `tailwindcss-development` (v4 path); `AGENTS.md` "Frontend styling rules"; [templates/frontend-styling-checklist.md](templates/frontend-styling-checklist.md) | Tailwind CSS 4.3; not installed yet. |
| 9 | `api-contracts` | `api-design-patterns`; [api-specification.md](api-specification.md); `AGENTS.md` hard boundary 6 | The contract document is updated before a request or response shape changes. |
| 10 | `laravel-testing` | `laravel-testing` | PHPUnit ^12.5.12 (12.5.44 installed); Pest 4 is compatible. |
| 11 | `frontend-testing` | `frontend-testing` (custom); global `build-web-apps:frontend-testing-debugging` for browser debugging | Vitest and RTL are not installed yet. |
| 12 | `integration-testing` | `integration-testing` (custom); `e2e-playwright-testing` for the browser boundary; [architecture.md](architecture.md) for the component map | Laravel plus PostgreSQL plus MariaDB plus Next.js. |
| 13 | `playwright-e2e` | `e2e-playwright-testing`; global `build-web-apps:frontend-testing-debugging` | Playwright is not installed yet. The official `playwright-trace` skill is not installed. |
| 14 | `postgresql-engineering` | Global `build-web-apps:supabase-postgres-best-practices`; the official PostgreSQL 17 documentation; [database-design.md](database-design.md) | The official docs remain the source for administration, locking, backup, and recovery. |
| 15 | `mariadb-sql-engineering` | `mariadb-features`, `mariadb-query-optimization`, `mysql-to-mariadb`; the official MariaDB documentation | MariaDB 11.8.9; not a drop-in MySQL 8.0 or PostgreSQL replacement. |
| 16 | `docker-infrastructure` | No skill. `AGENTS.md` "Environment constraints"; `infra/README.md`; `infra/compose.yaml`; [architecture.md](architecture.md) | `BagelHole/docker-compose` was rejected. Docker Compose 5.5.1, Docker 29.8.0. |
| 17 | `security-engineering` | `laravel-owasp-security`; the global security set (see below); `AGENTS.md` "Security requirements"; [templates/code-review-checklist.md](templates/code-review-checklist.md) "Security" | OWASP ASVS and Top 10. |
| 18 | `sql-execution-isolation` | `sql-execution-isolation` (custom, critical); the MariaDB skills; [database-design.md](database-design.md) "MariaDB: `sql_exec`"; `AGENTS.md` rule 5 | No SQL execution feature may start before the isolation design is approved. |
| 19 | `security-testing` | Global `semgrep`, `codeql`, `sarif-parsing`, `fp-check`, `variant-analysis`, `differential-review`, `sharp-edges`, `supply-chain-risk-auditor`, `audit-context-building`; `laravel-owasp-security` | Use the light pass for a small change and the full set for a security-boundary change. |
| 20 | `systematic-debugging` | Global `systematic-debugging` | Required before a fix proposal for a defect. |
| 21 | `code-review` | Global `requesting-code-review`, `receiving-code-review`; [templates/code-review-checklist.md](templates/code-review-checklist.md); `AGENTS.md` "Code review and Definition of Done" | A review resolves every criterion to PASS, FAIL, or UNRESOLVED. |
| 22 | `git-workflow` | Global `finishing-a-development-branch`; `AGENTS.md` non-negotiable rule 9 and environment constraint 6; the Human Owner's main-checkout rule | No commit, push, or worktree without authorization. `BagelHole/git-workflow` and `using-git-worktrees` were rejected. |
| 23 | `documentation-maintenance` | `project-docs`; `AGENTS.md` "Documentation map" and phase 9; [development-roadmap.md](development-roadmap.md) | Update the document the change affects. |
| 24 | `deployment-and-operations` | `runbook-creation` (installed, inactive); [development-roadmap.md](development-roadmap.md) | No deployment work has started. Production deployment is Human-only. `BagelHole/blue-green-deploy` was rejected. |

Coverage: 24 of 24 capabilities are mapped. By primary coverage: 10 by an installed project skill (3, 6, 8, 9, 10, 13, 15, 17, 23, 24), 4 by a custom project skill (4, 11, 12, 18), 7 by a referenced global skill (2, 7, 14, 19, 20, 21, 22), and 3 by `AGENTS.md` and the existing documents alone (1, 5, 16). Many rows name a second source for the same capability; the table's `Covered by` cell is the complete answer for that row.

### Stack-fit notes

Two general skills carry prescriptions that do not fully match this project. `AGENTS.md` and the project documents win.

1. `laravel-owasp-security` targets Laravel plus React/Inertia in its full checklist and mentions Breeze, Fortify, and Jetstream for authentication. SQL-Proctor uses a separate Next.js frontend with Sanctum. The Laravel checks apply; the Inertia-specific checks are skipped by the skill's own stack detection. Use `docs/api-specification.md` for the authentication contract.
2. `api-design-patterns` recommends URL-path versioning. The current contract is `/api` without a version (`docs/api-specification.md`). Follow the current contract; add a version only when a later decision approves it.

## Global skills referenced, not copied

These skills are installed in the harness skill store. `AGENTS.md` names the exact ID; the repository does not copy them.

| Global skill ID | Use in this project |
|---|---|
| `vercel-react-best-practices` | React and Next.js performance rules for client components. |
| `vercel-composition-patterns` | Component composition and reusable component APIs. |
| `build-web-apps:supabase-postgres-best-practices` | PostgreSQL schema and query performance. |
| `build-web-apps:frontend-testing-debugging` | Browser debugging and targeted frontend test repair. |
| `systematic-debugging` | Root-cause method before any defect fix. |
| `verification-before-completion` | Evidence before a completion claim. |
| `requesting-code-review` | Preparing a change for review. |
| `receiving-code-review` | Handling review feedback with technical rigor. |
| `finishing-a-development-branch` | Integration decision after the implementation is complete. |
| `property-based-testing` | Invariant, inverse, and oracle tests. |
| `write-spec` | Specification authoring, with the project template. |
| `ask-questions-if-underspecified` | The exact installed ID. Used when a material ambiguity remains after a quick read. |
| `audit-context-building` | Understand a codebase before a security audit. |
| `differential-review` | Security review of a diff. |
| `fp-check` | Verify a suspected vulnerability before reporting it as real. |
| `variant-analysis` | Find other instances after a real bug. |
| `semgrep` | Pattern-based static analysis. |
| `codeql` | Data-flow and taint analysis. |
| `sarif-parsing` | Read and deduplicate SARIF results. |
| `sharp-edges` | Review an API or configuration that a developer can misuse. |
| `supply-chain-risk-auditor` | Dependency and supply-chain risk. |

## Rejected candidates

Reasons are from DEC-0044 point 9. `database-backups` was installed in the first run, then removed after review; the removal reason is from the research evidence.

| Candidate | Type | Reason |
|---|---|---|
| Laravel Boost package | Composer package with bundled skills | The root project rule forbids the dependency. The individual official skill files are used directly instead. |
| `JustSteveKing/api-skill` | Community skill | The prescriptive single-action controller and route layout conflict with the existing Laravel 13 scaffold. |
| `BagelHole/docker-compose` | Community skill | It uses the obsolete Compose `version` key and hardcoded secrets. The project already has a verified `infra/compose.yaml` and `infra/README.md`. |
| `BagelHole/postgresql` | Community skill | Generic administration content overlaps the Supabase Postgres skill and the official PostgreSQL 17 documentation. No distinct value. |
| `BagelHole/blue-green-deploy` | Community skill | The Kubernetes-based deployment model does not match the current local Compose stage. |
| `BagelHole/git-workflow` | Community skill | It duplicates the installed Git skills and conflicts with the Human Owner's main-checkout, no-worktree rule. |
| `using-git-worktrees` | Global skill | It conflicts with the Human Owner's main-checkout rule. |
| `next-dev-loop` | Official Next.js skill | It requires the `agent-browser` CLI, which is not installed. Installing it is outside this task. |
| `playwright-cli` | Official Playwright skill | It overlaps the browser tools. `e2e-playwright-testing` and the existing browser tools cover the need. `playwright-trace` is not installed. |
| `database-backups` | Community skill | Installed, then removed: it contains `sudo rm -rf /var/lib/mysql/*` in a restore example. The instruction forbids unsafe agent instructions. |
| `testing-best-practices` (generic) | Community skill | It duplicates `laravel-testing`, `frontend-testing`, `e2e-playwright-testing`, and `property-based-testing`. |
| `lackeyjb/playwright-skill` | Community skill (additional candidate in the research evidence) | Browser automation overlaps the existing browser tools. |

## Version compatibility

| Fact | Value | Source |
|---|---|---|
| Next.js | 16.4.6, App Router, JavaScript | [architecture.md](architecture.md) verified versions |
| React | 19.3.0 | [architecture.md](architecture.md) |
| Tailwind CSS | 4.3 release line; not installed | Research evidence E-02; `AGENTS.md` "Frontend styling rules" |
| Laravel | 13.43.0, PHP 8.5.12 | [architecture.md](architecture.md) |
| PHPUnit | 12.5.44 installed (`^12.5.12` constraint) | `backend/sql-proctor-api/composer.json` and `composer.lock`; `AGENTS.md` "Testing policy" |
| Pest | Pest 4 is compatible with PHPUnit 12; Pest 5 needs PHPUnit 13 and PHP 8.4 | Research evidence E-02; Pest support policy |
| Vitest | Not installed. Install the current stable in the first frontend test task. Exact version `TBD`. | `AGENTS.md` "Testing policy"; `frontend-testing` skill |
| React Testing Library | Not installed. RTL 16 or later is the stated minimum for React 19. Exact version `TBD`. | TASK-0109 required inputs; `frontend-testing` skill |
| Playwright | Not installed. Exact version `TBD`. | `AGENTS.md` "Testing policy" |
| MariaDB | 11.8.9. `MAX_EXECUTION_TIME` is 12.0+; use `SET STATEMENT max_statement_time=...` on 11.8. | Research evidence E-02; [database-design.md](database-design.md) |

### Tailwind CSS 4.3 install path

1. `npm install tailwindcss@latest @tailwindcss/postcss@latest`.
2. Add `"@tailwindcss/postcss"` to `postcss.config.mjs`.
3. Use `@import "tailwindcss";` in the global stylesheet.
4. Put design tokens in `@theme` only when the project needs a shared token set.
5. Do not create `tailwind.config.js` for a new v4 setup.

The `tailwindcss-development` skill must come from the v4 path (`.ai/tailwindcss/4/skill/tailwindcss-development`). The same repository also holds a v3 skill under the same name.

### Next.js 16 facts that change test design

1. Layouts and pages are Server Components by default. A Client Component needs `"use client"`.
2. `params` and `searchParams` are Promises. Await them in a Server Component, or use React `use()` in a Client Component.
3. Next.js 16 deprecates `middleware` in favor of `proxy` (`proxy.js` or `proxy.ts`).
4. Vitest does not support async Server Components. Test those through Playwright E2E.

## Skills that require future activation

| Skill | Activates when | Prerequisite |
|---|---|---|
| `frontend-testing` | The first frontend test task | Vitest, jsdom, React Testing Library, and the setup file are installed with approval. |
| `integration-testing` | The first cross-boundary test | The database containers run; the test environment is defined. |
| `e2e-playwright-testing` | The first critical browser journey | Playwright and its browsers are installed with approval. |
| `sql-execution-isolation` | Before any design, code change, or review of student SQL execution | The per-attempt isolation design is approved by the Human Owner. |
| `runbook-creation` | Deployment or operations work starts | A deployment scope and an environment exist. |
| Deployment work | A release to production is authorized | Human Owner approval; no deployment exists today. |

Do not load a skill before its prerequisite exists. Do not install a test runner or a browser as part of an unrelated task.

## Installation, update, and maintenance

### Commands

| Purpose | Command | Notes |
|---|---|---|
| Inspect the installed skills | `npx skills list --json` | Prints name, path, scope, agents, source, and status. Run it from the repository root. |
| Install one skill | `npx skills add <source> --skill <name> --agent opencode --copy --json` | `<source>` is a GitHub repository or a GitHub subdirectory URL. `--json` gives the machine-readable result used as evidence. |
| Update the installed skills | `npx skills update` | Applies upstream changes. Review the diff and the security assessment before you keep an update. |
| Remove a skill | `npx skills remove <name>` | Removes the directory and the lock entry. The `database-backups` removal is the recorded example. Record why. |

The CLI writes `.agents/skills/` and `skills-lock.json`. Do not edit `skills-lock.json` by hand.

### Install checklist

1. Search official, then trusted, then maintained community sources. Record the candidates and why each was accepted or rejected.
2. Install with `--copy` and `--json`. Save the JSON output as evidence in `.agent-runtime/`.
3. Read the security assessment. Record any Snyk rating above Low.
4. Read the installed `SKILL.md`. Look for a tool, a command, or an instruction the project does not have or forbids.
5. If an adaptation is needed, make the smallest change and record the file, the line, and the reason.
6. Confirm that `skills-lock.json` has the source, commit, path, and hash.
7. Run `npx skills list --json` and confirm the skill appears.
8. Add the skill to the `AGENTS.md` skill-loading table when a task needs it.
9. Add or update the row in this document.

### Update checklist

1. Run `npx skills update`.
2. Read the upstream diff. A change that alters a rule, a security requirement, or a framework behavior is reviewed as a rule change, not as a routine update.
3. Re-run the security assessment.
4. Re-apply a recorded adaptation if the update overwrites it. Confirm the `search-docs` replacements still hold for the two adapted skills.
5. Run the affected checks. A skill is documentation; a wrong rule shows up in the next change, so review the text.
6. Record the new commit and the result in this document.

### Rules

1. One skill per need. Do not install a second skill that duplicates a function.
2. Do not clone a whole skill repository. Install one skill at a time.
3. Do not install a skill that needs a tool, a package, or a service the project forbids.
4. Do not edit an installed skill silently. Record every adaptation in "Recorded modifications" and in the evidence folder.
5. A skill change that alters a project rule or a security requirement needs Human Owner approval ([AGENTS.md](../AGENTS.md) "Approval rules").
6. A new dependency that a skill needs is a separate task with its own approval.
7. A skill never overrides `AGENTS.md`. When the two disagree on a project rule, `AGENTS.md` wins; for framework syntax, the skill and the official documentation win.
8. A skill that references a tool the project does not have: use the official documentation instead, and do not install the tool without approval.

## Source links

### Harness

- OpenCode V2 skills documentation: [https://opencode.ai/v2/docs/skills](https://opencode.ai/v2/docs/skills)

### Install evidence (printed by the `skills` CLI)

- [https://skills.sh/laravel/boost](https://skills.sh/laravel/boost)
- [https://skills.sh/MariaDB/skills](https://skills.sh/MariaDB/skills)
- [https://skills.sh/AsyrafHussin/agent-skills](https://skills.sh/AsyrafHussin/agent-skills)
- [https://skills.sh/obra/superpowers](https://skills.sh/obra/superpowers)
- [https://skills.sh/BagelHole/DevOps-Security-Agent-Skills](https://skills.sh/BagelHole/DevOps-Security-Agent-Skills)

### Official framework and standard documentation

- Laravel 13: [https://laravel.com/docs/13.x](https://laravel.com/docs/13.x)
- Laravel 13 testing: [https://laravel.com/docs/13.x/testing](https://laravel.com/docs/13.x/testing)
- Pest support policy: [https://pestphp.com/docs/support-policy](https://pestphp.com/docs/support-policy)
- Tailwind CSS with Next.js: [https://tailwindcss.com/docs/installation/framework-guides/nextjs](https://tailwindcss.com/docs/installation/framework-guides/nextjs)
- Next.js 16 documentation through Context7: `/vercel/next.js/v16.4.5` (the path recorded in the research evidence)
- MariaDB `GRANT`: [https://mariadb.com/docs/server/reference/sql-statements/account-management-sql-statements/grant](https://mariadb.com/docs/server/reference/sql-statements/account-management-sql-statements/grant)
- MariaDB aborting statements: [https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/query-optimizations/aborting-statements](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/query-optimizations/aborting-statements)
- PostgreSQL 17: [https://www.postgresql.org/docs/17/](https://www.postgresql.org/docs/17/)
- Playwright best practices: [https://playwright.dev/docs/best-practices](https://playwright.dev/docs/best-practices)
- Playwright locators: [https://playwright.dev/docs/locators](https://playwright.dev/docs/locators)
- Playwright authentication: [https://playwright.dev/docs/auth](https://playwright.dev/docs/auth)
- OWASP Top 10: [https://owasp.org/Top10/](https://owasp.org/Top10/)
- OWASP ASVS: [https://owasp.org/www-project-application-security-verification-standard/](https://owasp.org/www-project-application-security-verification-standard/)

### GitHub sources

- [https://github.com/laravel/boost](https://github.com/laravel/boost) (MIT)
- [https://github.com/MariaDB/skills](https://github.com/MariaDB/skills) (MIT)
- [https://github.com/AsyrafHussin/agent-skills](https://github.com/AsyrafHussin/agent-skills) (MIT)
- [https://github.com/obra/superpowers](https://github.com/obra/superpowers) (MIT)
- [https://github.com/BagelHole/DevOps-Security-Agent-Skills](https://github.com/BagelHole/DevOps-Security-Agent-Skills) (MIT)
- [https://github.com/JustSteveKing/api-skill](https://github.com/JustSteveKing/api-skill) (rejected)
- [https://github.com/lackeyjb/playwright-skill](https://github.com/lackeyjb/playwright-skill) (rejected)

## Verification record

| Check | Command or method | Result | Evidence |
|---|---|---|---|
| Installed skill count | `npx skills list --json` | 16 project skills listed on 2026-11-19: 12 installed and 4 custom (`untracked`) | Command output |
| Lock entries | Read `skills-lock.json` | 12 entries, each with source, commit, skill path, hash, and copy mode. The 4 custom skills are not in the lock because the CLI did not install them. | [skills-lock.json](../skills-lock.json) |
| Adapted skills | Read `04-skill-adaptation.txt` | 0 Blade rule files; 8 modified skills have a recorded change; 0 relative-link problems | Evidence folder |
| Custom skills | Read the four task records and load three | `PASS`: 4 present; 16/16 frontmatter; 3 loaded by ID (`negative-first-testing`, `sql-execution-isolation`, `tailwindcss-development`); `integration-testing` and `frontend-testing` load not exercised | TASK-0107, TASK-0108, TASK-0109, TASK-0113 |
| Source links | `07-source-links.txt` and `07-source-link-summary.md` (103 unique URLs) | 89 reachable; 10 local URLs by design; 2 malformed code-fence extractions; 1 official MySQL 8.0 docs URL returned 403 to curl; 1 extraction artifact on the OpenCode URL, whose clean form returned 200 | Evidence folder |
| Skill load behavior | Load test | `PASS`: 3 skills loaded by ID; the other 2 custom skills were not load-tested | TASK-0112, TASK-0113 |
| Final verification | TASK-0113 thirteen items and four additional checks | `PASS`: 13/13 items and 4/4 additional checks; 5 `NON-BLOCKING` findings fixed or recorded | E-10, E-11 |

## Known limitations

1. `mariadb-query-optimization` has a Snyk `Medium` rating. Every other installed skill is `Low`. The skill is official MariaDB documentation with SQL tuning examples and no executable script; the rating stays recorded for review.
2. The official Playwright `playwright-trace` skill is not installed. The `skills` CLI did not reach the nested path `packages/playwright-core/src/tools/skills/` in `microsoft/playwright`.
3. `database-backups` was removed after review because its restore example contains `sudo rm -rf /var/lib/mysql/*`.
4. Four concurrent role calls succeeded on 2026-11-19. A later quota failure still stops the run; do not replace an independent review with a parent claim.
5. `npx skills list --json` reports `status: modified` for 8 of the 12 installed skills and `tracked` for 4. The cause is established: two guidance adaptations (`laravel-best-practices`, `tailwindcss-development`), five duplicate support-file removals (`laravel-testing`, `api-design-patterns`, `e2e-playwright-testing`, `laravel-owasp-security`, `project-docs`), and one example-link cleanup (`runbook-creation`). The four `tracked` skills are `mariadb-features`, `mariadb-query-optimization`, `mysql-to-mariadb`, and `test-driven-development`. `modified` is expected after a recorded project change.
6. The four custom skills passed the format check. `negative-first-testing`, `sql-execution-isolation`, and `tailwindcss-development` were loaded by ID. `integration-testing` and `frontend-testing` were not load-tested; their format, frontmatter, and links passed. This is a load-test gap, not a failure.
7. The installed rule files inside `project-docs` and the `e2e-playwright-testing` template contain illustrative links to files that do not exist in this project (for example `LICENSE`, `CONTRIBUTING.md`, and `docs/guides/deployment.md`). They are examples, not repository references. The 16 `SKILL.md` entry points and the project documents have 0 broken relative links.
8. `laravel-owasp-security` and `api-design-patterns` carry stack-specific prescriptions that do not fully match the Laravel API plus Next.js plus Sanctum stack. The stack-fit notes above and `AGENTS.md` precedence limit the risk. Adapt them at the first task that uses the conflicting section.
9. The `nextjs-development` capability is covered by `vercel-react-best-practices` and `vercel-composition-patterns` plus Context7. Neither is a full Next.js App Router framework skill. Revisit when an official Next.js skill without the `agent-browser` dependency is available.

## Maintenance

The Engineering Manager keeps this document current. Update it in the same change that installs, updates, removes, or adapts a skill. A rule change that affects the skill-loading map is made in `AGENTS.md` first, then reflected here.

| Date | Change | By |
|---|---|---|
| 2026-11-19 | Created with the initial inventory, capability matrix, rejected candidates, version facts, and maintenance procedure | Engineering Manager, TASK-0110 |
| 2026-11-19 | Final verification 13/13 PASS; 5 NON-BLOCKING findings fixed or recorded; custom skill load results recorded | parent, TASK-0113 |
