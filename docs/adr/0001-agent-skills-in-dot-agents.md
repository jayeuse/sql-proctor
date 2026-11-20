# ADR-0001: Agent skills use the discovery-first `.agents/skills` layout

| Field | Value |
|---|---|
| Status | Accepted |
| Date | 2026-11-19 |
| Decision owner | Human Owner |
| Related spec | [SPEC-0001](../specs/SPEC-0001-agent-engineering-setup.md) |
| Related task | TASK-0111 |
| Authorization | APR-0015 |

## Context

SQL-Proctor needs an agent engineering standard. The first plan created 24 custom skills. The Human Owner replaced it with a discovery-first policy: search official, trusted, and maintained sources; install the best compatible skill; keep cross-cutting rules in the root `AGENTS.md`; and create a custom skill only when no suitable existing skill covers the need.

OpenCode discovers project skills in `.opencode/skills`, `.claude/skills`, and `.agents/skills`. The supported `skills` CLI installs a project skill into `.agents/skills/<id>/` and writes `skills-lock.json` with the source, commit, path, hash, and install mode. The root `skills/` directory from the first plan is not a discovery path.

## Decision

1. Use the supported `skills` CLI and `.agents/skills/` as the project skill location. Do not add a root `skills/` directory or an `opencode.json` skill entry.
2. Keep the project rules in `AGENTS.md`. It is always loaded, and it holds the skill-loading map. Do not duplicate those rules in a hub skill.
3. Install existing skills where they fit: official Laravel and MariaDB skills, an official Playwright debugging skill if available, and maintained community skills for Laravel testing, API design, E2E, security, documentation, TDD, and runbooks.
4. Reference global skills by ID instead of copying them: Vercel React, Vercel composition, Supabase Postgres, frontend testing/debugging, systematic debugging, verification, code review, Git integration, property-based testing, spec writing, clarification, and the security set.
5. Create four custom skills only for gaps that no existing skill covers: `negative-first-testing`, `sql-execution-isolation`, `integration-testing`, and `frontend-testing`.
6. Adapt an installed skill only with the smallest change that makes it usable, and record the change in `docs/agent-engineering.md` and the evidence folder.
7. Remove an installed skill that contains an unsafe destructive instruction. `database-backups` was removed for a `sudo rm -rf /var/lib/mysql/*` example.

## Consequences

1. The repository has 16 project skills: 12 installed and 4 custom. Four installed skills are `tracked`; eight are `modified` for a recorded reason.
2. `skills-lock.json` makes the external set reproducible. `npx skills update` needs a diff review and a security assessment.
3. `AGENTS.md` is the single source for project rules. A skill may describe a domain workflow, but it does not override a project boundary.
4. `docs/agent-engineering.md` is the inventory and maintenance record. A skill change that alters a rule or a security requirement needs Human Owner approval.
5. No application dependency is added by the setup. Tailwind, Pest, Vitest, React Testing Library, and Playwright are installed by the first task that needs them.
6. The official Laravel skills are adapted for this project: `search-docs` is replaced with Context7 or the official Laravel documentation, and six Blade rule files are converted to Markdown.

## Alternatives considered

| Alternative | Why it was not chosen |
|---|---|
| Create 24 custom skills | The Human Owner replaced the create-first plan with discovery-first |
| Put the skills in a root `skills/` directory with an `opencode.json` entry | The supported CLI and the documented discovery path use `.agents/skills`; an extra config entry adds a second source of truth |
| Clone a whole skill repository | The policy forbids copying whole repositories and requires per-skill evaluation |
| Install Laravel Boost | The root project rule forbids the package; the official skill files are installed individually |
| Copy the global skills into the repository | They are already installed in the harness; copying creates duplicate IDs and drift |

## References

- [AGENTS.md](../../AGENTS.md)
- [docs/agent-engineering.md](../agent-engineering.md)
- [SPEC-0001](../specs/SPEC-0001-agent-engineering-setup.md)
- OpenCode skills documentation: `https://opencode.ai/v2/docs/skills`
- DEC-0044 in the company workspace
