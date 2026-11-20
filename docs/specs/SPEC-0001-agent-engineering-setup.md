# Specification: Discovery-first agent engineering setup

| Field | Value |
|---|---|
| Spec ID | SPEC-0001 |
| Title | Discovery-first agent engineering setup |
| Status | Approved |
| Owner | parent |
| Reviewer | Engineering Manager |
| Related task | TASK-0111 |
| Date | 2026-11-19 |

## Objective

SQL-Proctor has a verified, discovery-first agent engineering environment. The agent finds the project rules in `AGENTS.md`, loads only the skills a task needs, follows SDD and TDD, designs negative tests first, treats student SQL as untrusted, and reports evidence. The repository contains 16 project skills, seven templates, specification and ADR storage, and installation and maintenance documentation. No application feature is added.

## Problem

The repository has a working development environment but no agent engineering standard. An agent can invent a workflow, skip tests, duplicate rules, or treat a framework bootstrap file as authority. The first plan created 24 custom skills. The Human Owner replaced that plan with a discovery-first policy: install existing skills from official or maintained sources, keep cross-cutting rules in `AGENTS.md`, and create a custom skill only when no suitable existing skill covers the need. Without one map, the agent can install duplicates, pick the wrong Tailwind version, or trust an unsafe community skill.

## Scope

In scope:

1. Select and install existing skills from official, trusted, and maintained sources.
2. Create four project-specific skills for gaps that no existing skill covers: negative-first testing, SQL execution isolation, integration testing, and frontend testing.
3. Write the master `AGENTS.md`, seven templates, the specification and ADR folders, and the inventory and maintenance document.
4. Verify skill format, discovery, references, source links, compatibility, and the required workflows.

Out of scope:

- Application features, controllers, models, migrations, and UI pages.
- Application dependencies: Pest, Vitest, React Testing Library, Playwright, Tailwind CSS, and Laravel Boost.
- Commits, pushes, deployment, and the blocked MariaDB host-port work in the old run.

## Requirements

### Functional

1. FR-1: The root `AGENTS.md` states the project context, stack, boundaries, SDD, TDD, negative-first testing, security, Tailwind, approval, skill-loading, review, DoD, reporting, runtime, and environment rules.
2. FR-2: Every required capability in the Human Owner's section 5 to section 9 is mapped to a project skill, an existing global skill, or an `AGENTS.md` section.
3. FR-3: Installed skills keep their source, commit, path, hash, security assessment, and any project modification.
4. FR-4: The four custom skills state why no existing skill covers the need and contain the required workflow, rules, examples, references, and related skills.
5. FR-5: The seven templates contain every required section.
6. FR-6: `docs/specs/` and `docs/adr/` hold the specification and the architecture decision for this setup.
7. FR-7: `docs/agent-engineering.md` documents installation, update, maintenance, rejected candidates, version compatibility, and future activation.
8. FR-8: The skill-discovery path works in OpenCode, and the skill format is valid.

### Non-functional

1. NFR-1: No secret, credential, token, or `.env` value appears in a skill, document, or record.
2. NFR-2: No unsafe destructive instruction is installed.
3. NFR-3: The skill set does not duplicate a function without a recorded reason.
4. NFR-4: The setup does not change application code, dependencies, or the blocked run.
5. NFR-5: Every source link resolves, or the failure is recorded as `UNRESOLVED`.
6. NFR-6: The agent loads only the skills the task needs.

## Constraints

1. APR-0015 authorizes the work. DEC-0044 sets the discovery-first policy.
2. No application feature or dependency. No Laravel Boost.
3. No commit or push.
4. Preserve the existing application files, `.env` files, and the blocked run `2026-11-19-dev-environment-setup`.
5. Use the `skills` CLI and `.agents/skills/` for installed skills. Do not clone a whole repository.
6. Markdown only for the skills and the records. No secret.

## Architecture

```text
AGENTS.md                         always-loaded project rules and skill map
.agents/skills/<id>/SKILL.md      installed or custom skill bodies
skills-lock.json                  source, commit, path, hash, install mode
docs/agent-engineering.md         inventory, sources, compatibility, maintenance
docs/templates/                   specification, plan, test matrix, TDD, review, DoD, styling
docs/specs/                       specifications (SPEC-0001 ...)
docs/adr/                         architecture decisions (ADR-0001 ...)
.agent-runtime/                   local run evidence (gitignored)
```

The skill layer is development guidance. It is not bundled into the Next.js or Laravel applications and has no runtime role. The application boundaries do not change.

## Expected behavior

1. An agent reads `AGENTS.md` and inspects the repository before acting.
2. A meaningful task gets a specification, a plan, and a test matrix.
3. A behavior change starts with a failing test, verified for the expected reason.
4. Negative cases run before success cases, in the 14-category order.
5. Student SQL work loads `sql-execution-isolation` and does not proceed without an approved isolation design.
6. Frontend work loads `tailwindcss-development` and uses utility classes in `className`.
7. A completion claim names the command, the exit status, and the observed result.
8. A missing check is `NOT RUN`, not a pass.

## Failure behavior

| Failure | Response |
|---|---|
| A skill is missing or fails to load | Record `UNRESOLVED`; do not substitute a built-in. |
| A relative link is broken | Fix it or record it as a finding. Do not report the skill as verified. |
| A source link fails | Record the HTTP result and the date; do not claim it resolves. |
| A role quota is exhausted | Stop and report `blocked`; do not replace independent review with a parent claim. |
| An installed skill contains an unsafe instruction | Remove it and record the rejection. |
| A test tool is not installed | Record `NOT RUN`; do not install it without approval. |
| The SQL isolation design is unapproved | Stop; the Human Owner decides the model and the privileges. |

## Security considerations

1. Student SQL is untrusted. The execution engine is separate from PostgreSQL and never receives application credentials or data.
2. `sql-execution-isolation` requires least privilege, per-attempt scope, bounded time/rows/connections, deterministic cleanup, and safe errors.
3. Installed skills are reviewed for unsafe commands. `database-backups` was removed for a destructive `rm -rf` example.
4. No secret is written to a skill, a document, or a record.
5. Security skills cover OWASP Top 10, ASVS, diff review, static analysis, false-positive checks, variant analysis, and supply-chain risk.

## Acceptance criteria

1. AC-1: Every installed skill is present and valid.
2. AC-2: The skill format and the discovery path work in OpenCode.
3. AC-3: The master `AGENTS.md` contains every required section.
4. AC-4: The SDD workflow is present and usable.
5. AC-5: The TDD workflow is present and usable.
6. AC-6: The negative-first policy is present and uses the 14 categories in order.
7. AC-7: The Tailwind rules are present and correct for v4.
8. AC-8: Every related-skill reference resolves.
9. AC-9: Duplicated instructions are removed where appropriate.
10. AC-10: The source links are verified or the failures are recorded.
11. AC-11: The environment is compatible with OpenCode.
12. AC-12: The future-activation skills are identified.
13. AC-13: Installation and maintenance are documented.

## Test scenarios

| ID | Scenario | Expected result | Type |
|---|---|---|---|
| TS-1 | List the project skills | 16 entries: 12 installed and 4 custom | Inspection |
| TS-2 | Parse each `SKILL.md` frontmatter | `name` and `description` present | Script |
| TS-3 | Resolve every relative link | 0 broken links | Script |
| TS-4 | Read `AGENTS.md` | All required sections present | Inspection |
| TS-5 | Read the negative-first skill and the template | 14 categories in the same order | Inspection |
| TS-6 | Read the Tailwind skill and the AGENTS rules | v4 install path, no v3 config | Inspection |
| TS-7 | Check the capability matrix | 24 of 24 capabilities mapped | Script |
| TS-8 | Check the source URLs | Reachable, or recorded failure | HTTP check |
| TS-9 | Check the application tree | No feature, controller, model, migration, or UI page added | Git status and inspection |
| TS-10 | Load a project skill in OpenCode | The skill loads by ID | Harness load test |

## Dependencies

- APR-0015 (authorization).
- DEC-0044 (discovery-first policy).
- The 12 installed skills in `skills-lock.json`.
- The four custom skills from TASK-0107, TASK-0108, and TASK-0109.
- The templates and inventory from TASK-0110.

## Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Skill drift after an upstream update | The agent follows a changed rule | `npx skills update` plus a diff review; a rule or security change needs Human Owner approval. |
| The CLI selects the wrong skill version | The agent uses Tailwind v3 in a v4 project | Install from the version-specific path and record the source path. |
| A community skill is inaccurate or unsafe | A wrong or dangerous instruction enters the workflow | Official-first priority; security assessment; manual read; removal on a destructive example. |
| Duplicate instructions across AGENTS.md and skills | Conflicting rules | AGENTS.md wins on project rules; skills hold the detailed domain workflow. |
| A testing tool is installed without approval | Scope and dependency creep | Record `NOT RUN` and install in the first task that needs it, with approval. |
| Role quota or harness capability is missing | No independent review or load test | Stop and report `blocked`; do not substitute a claim. |

## Definition of Done

The [Definition of Done template](../templates/definition-of-done.md) applies. This specification is complete when AC-1 to AC-13 are `PASS` with evidence, or an item is `UNRESOLVED` with the reason and the Human Owner accepts the limitation.
