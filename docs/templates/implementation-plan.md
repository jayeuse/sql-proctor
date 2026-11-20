# Implementation plan: &lt;title&gt;

> Template. Keep it proportional: a small change needs a short plan, a feature needs a section per task. Follow `AGENTS.md` "Mandatory workflow" phase 3. The plan references the spec; it does not repeat it.

| Field | Value |
|---|---|
| Spec | `SPEC-<nnn>` at `docs/specs/SPEC-<nnn>-<slug>.md` |
| Related task | TASK-&lt;nnn&gt; |
| Owner | &lt;role&gt; |
| Reviewer | &lt;role&gt; |
| Date | &lt;YYYY-MM-DD&gt; |
| Authorization | &lt;APR record, or "not a product repository change"&gt; |

## Tasks

Order the tasks so each one leaves the repository in a testable state. One owner per task.

| ID | Task | Owner | Depends on | Status |
|---|---|---|---|---|
| P-1 | &lt;one bounded change&gt; | &lt;role&gt; | None | Pending |
| P-2 | &lt;next change&gt; | &lt;role&gt; | P-1 | Pending |

## Dependencies

- Every task boundary is a mergeable, testable state.
- A product repository change starts only when the Authorization field names an approval record.
- External inputs: &lt;services, versions, decisions, or "None"&gt;.

## Files affected

| Path | Change | Layer |
|---|---|---|
| &lt;path relative to the repository root&gt; | Create / Modify / Delete | Frontend / Backend / Database / Infrastructure / Docs |

State every file that the plan expects to touch. A file that is not in this table is out of scope for the plan; a newly discovered file goes into the plan before it is edited.

## Architecture considerations

- Which boundaries from `AGENTS.md` "Stack and architecture boundaries" the change touches.
- The API contract change and the update to `docs/api-specification.md`.
- The PostgreSQL or MariaDB change and the update to `docs/database-design.md`.
- The new component, service, dependency, or container, if any. Each one needs a stated reason and Human Owner approval.
- The student SQL boundary, if the change executes SQL: the `sql-execution-isolation` design decision comes first.

## Risks

| Risk | Likelihood | Impact | Mitigation | Owner |
|---|---|---|---|---|
| &lt;risk&gt; | High / Medium / Low | High / Medium / Low | &lt;action&gt; | &lt;role&gt; |

## Test requirements

| Requirement | Test type | Test IDs |
|---|---|---|
| AC-1 | Unit / Feature / Integration / E2E | TM-1, TM-2 |

Rules:

1. Write the failing test first and record the red run in [tdd-checklist.md](tdd-checklist.md).
2. Cover the negative cases before the success case, in the order of [test-matrix.md](test-matrix.md).
3. Name the software each test needs. If the runner is not installed, install it in the first task that needs it, under its own approval.
4. Record every case in the matrix with its real status: `NOT RUN` is not `PASS`.

## Verification steps

| # | Command or check | Working directory | Expected result | Evidence |
|---|---|---|---|---|
| 1 | &lt;focused test command&gt; | &lt;path&gt; | Exit 0; the named tests pass | &lt;file in `.agent-runtime/`&gt; |
| 2 | &lt;lint / format / build&gt; | &lt;path&gt; | Exit 0; no new warning | &lt;file&gt; |
| 3 | &lt;security review&gt; | &lt;path&gt; | [code-review-checklist.md](code-review-checklist.md) has no BLOCKING finding | &lt;review record&gt; |
| 4 | Manual check in the browser, desktop and phone width | &lt;URL&gt; | The behavior in "Expected behavior" matches | &lt;screenshot or note&gt; |

Prefix Laravel and Composer commands with `PATH="/opt/homebrew/bin:$PATH"`. Run one heavy test lane at a time. Report a skipped or unrun check as `NOT RUN`, never as passed. The change closes against [definition-of-done.md](definition-of-done.md).
