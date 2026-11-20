# Specifications

> Owner: the project's parent session · Status: Active · Updated: 2026-11-19

This folder holds the specifications for changes that are larger than one task note. Each specification follows [the specification template](../templates/specification.md) and the SDD workflow in [AGENTS.md](../../AGENTS.md).

## Naming

| Part | Rule |
|---|---|
| File | `SPEC-<nnn>-<slug>.md`, for example `SPEC-0001-agent-engineering-setup.md` |
| Spec ID | `SPEC-<nnn>` inside the file; `nnn` is a zero-padded sequence, never reused |
| Status | `Draft`, `In Review`, `Approved`, `Implemented`, or `Superseded` |
| Link | The task record and the implementation plan link the spec by ID |

## Rules

1. Write the spec before the plan or the tests.
2. Keep the spec in the repository when a later reader must understand the change.
3. Link the acceptance criteria to test IDs in `docs/templates/test-matrix.md`.
4. Record an architecture decision in `docs/adr/` when the spec changes a boundary, a contract, a schema, or a security control.
5. Do not delete a spec. Mark it `Superseded` and link the replacement.

## Current specifications

| Spec | Title | Status | Date |
|---|---|---|---|
| [SPEC-0001](SPEC-0001-agent-engineering-setup.md) | Discovery-first agent engineering setup | Approved | 2026-11-19 |
