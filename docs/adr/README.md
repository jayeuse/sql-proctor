# Architecture Decision Records

> Owner: the project's parent session · Status: Active · Updated: 2026-11-19

This folder holds the architecture decisions that later work must follow. Each decision follows the lightweight ADR shape: context, decision, consequences, alternatives, and references.

## Naming

| Part | Rule |
|---|---|
| File | `NNNN-<slug>.md`, for example `0001-agent-skills-in-dot-agents.md` |
| Status | `Proposed`, `Accepted`, `Superseded`, or `Rejected` |
| Link | The related spec, task, and `docs/agent-engineering.md` link the ADR |

## Rules

1. Write an ADR when a change alters a boundary, a contract, a schema, a security control, or the agent engineering layout.
2. Do not rewrite an accepted ADR. Add a new ADR that supersedes it and link both.
3. Keep the decision short. The implementation detail belongs in the spec or the skill.

## Current decisions

| ADR | Title | Status | Date |
|---|---|---|---|
| [ADR-0001](0001-agent-skills-in-dot-agents.md) | Agent skills use the discovery-first `.agents/skills` layout | Accepted | 2026-11-19 |
| [ADR-0002](0002-auth-session-boundaries-and-audit-isolation.md) | Dual Session Authentication Boundaries and Append-Only PostgreSQL Audit Logging | Accepted | 2026-11-28 |
