# Specification: &lt;title&gt;

> Template. Copy this file to `docs/specs/SPEC-<nnn>-<slug>.md` for a larger change, or fill the matching task record for a small change. Replace the guidance under each heading. Follow `AGENTS.md` "Mandatory workflow" phase 2. Ask the Human Owner when a critical requirement is ambiguous.

| Field | Value |
|---|---|
| Spec ID | SPEC-&lt;nnn&gt; |
| Title | &lt;short, result-oriented title&gt; |
| Status | Draft / In Review / Approved |
| Owner | &lt;role&gt; |
| Reviewer | &lt;role&gt; |
| Related task | TASK-&lt;nnn&gt; |
| Date | &lt;YYYY-MM-DD&gt; |

## Objective

One paragraph. State the result the user or the system gets. It must be observable and testable.

## Problem

What is wrong or missing today. Give the current behavior with its source (file or API path and line, a command result, or a document). Do not assume the repository state.

## Scope

- In scope: &lt;the behavior, modules, and documents this change covers&gt;
- Not in scope: &lt;what it deliberately does not cover&gt;

## Requirements

### Functional

| ID | Requirement |
|---|---|
| FR-1 | &lt;observable behavior; one requirement per row&gt; |

### Non-functional

| ID | Requirement |
|---|---|
| NFR-1 | Performance, security, reliability, accessibility, operability, or compatibility. Include the measurable limit. |

## Constraints

Technology, version, boundary, budget, and environment limits. Cite the source: `AGENTS.md`, `docs/architecture.md`, `docs/database-design.md`, `docs/api-specification.md`, or a decision record. Include the layers that must not change.

## Architecture

Which components are affected and how they communicate. Name the layer for each change: frontend, backend, PostgreSQL, MariaDB, `infra/`. State the API contract change, if any, and the required update to `docs/api-specification.md`. Keep the boundaries in `AGENTS.md` "Stack and architecture boundaries".

## Expected behavior

The success path, step by step, with the request, the response, and the visible result. Include the empty state and the loading state for UI work.

## Failure behavior

Each failure: the trigger, the user-visible result, the HTTP status or error shape, and the log or audit entry. Cover invalid input, unauthorized, forbidden, missing resource, invalid state, boundary, and dependency failure. Do not expose a stack trace, SQL error, credential, or another user's data.

## Security considerations

- Untrusted input and where it is validated.
- Authentication and authorization for each protected action.
- The SQL execution boundary, when student SQL is involved: load `sql-execution-isolation`.
- Secrets, logging, and data exposure.
- The OWASP ASVS or Top 10 item that applies, with the source.

## Acceptance criteria

| ID | Criterion | Verified by |
|---|---|---|
| AC-1 | &lt;one checkable statement&gt; | &lt;test ID, command, or review item&gt; |

An acceptance criterion is checkable only when a reviewer can decide PASS or FAIL from the evidence. `Verified by` names the test, command, or checklist row.

## Test scenarios

List the scenario IDs and point to the matrix. Use the negative-first order in [test-matrix.md](test-matrix.md).

| ID | Scenario | Type | Priority |
|---|---|---|---|
| TM-1 | &lt;scenario&gt; | Unit / Feature / Integration / E2E / Manual | High / Medium / Low |

## Dependencies

- Tasks, decisions, approvals, or external inputs that must exist first.
- A product repository change needs an approved authorization record before work starts.

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| &lt;risk&gt; | High / Medium / Low | High / Medium / Low | &lt;action&gt; |

## Definition of Done

The change is done only when every item in [definition-of-done.md](definition-of-done.md) passes. Test evidence is recorded in [test-matrix.md](test-matrix.md); the TDD cycle is recorded in [tdd-checklist.md](tdd-checklist.md); the code review is recorded in [code-review-checklist.md](code-review-checklist.md).
