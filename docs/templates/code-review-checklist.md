# Code review checklist: &lt;title&gt;

> Template. Use it for every completed change, with `requesting-code-review` and `receiving-code-review`. A reviewer records a verdict for each category. The change does not merge while any finding is `BLOCKING` or `UNRESOLVED`.

| Field | Value |
|---|---|
| Review ID | REV-&lt;nnn&gt; |
| Spec | `SPEC-<nnn>` at `docs/specs/SPEC-<nnn>-<slug>.md` |
| Related task | TASK-&lt;nnn&gt; |
| Author | &lt;role&gt; |
| Reviewer | &lt;role, not the author&gt; |
| Diff under review | &lt;base commit..head commit, or the file list&gt; |
| Date | &lt;YYYY-MM-DD&gt; |

## How to decide a finding

| Label | Rule |
|---|---|
| `BLOCKING` | The change is wrong, unsafe, out of scope, or unproven in a way that matters. The review verdict is `FAIL` until it is fixed and re-reviewed. |
| `NON-BLOCKING` | An improvement that does not affect correctness, security, or the accepted behavior. It is recorded and does not fail the change. |
| `UNRESOLVED` | The evidence needed to decide is missing. The review verdict is `UNRESOLVED`; the change does not merge. |

A finding is `NON-BLOCKING` only when the reviewer can name why it is safe to defer. "Style preference" is not a reason to block. "I could not verify it" is not a reason to pass.

## Correctness

- [ ] `[BLOCKING]` Every acceptance criterion is satisfied and the evidence is linked.
- [ ] `[BLOCKING]` The change handles the empty, single, maximum, and over-maximum cases the spec names.
- [ ] `[BLOCKING]` No logic error in a condition, loop bound, null or undefined path, or type conversion.
- [ ] `[NON-BLOCKING]` Names state the intent, not the mechanism.

## Security

- [ ] `[BLOCKING]` Every protected action is authorized on the server with a policy, gate, or form request. A frontend check is not authorization.
- [ ] `[BLOCKING]` Untrusted input is validated at the server boundary; output is escaped for its context.
- [ ] `[BLOCKING]` SQL values use parameter binding; identifiers are allow-listed.
- [ ] `[BLOCKING]` Student SQL is isolated per the `sql-execution-isolation` decision: least privilege, bounded time and rows, cleanup.
- [ ] `[BLOCKING]` No secret, credential, token, `.env` value, stack trace, or another user's data appears in code, logs, an error response, a test fixture, or a document.
- [ ] `[BLOCKING]` A new dependency passed `composer audit` or `npm audit`, or the advisory is reported and accepted.

## Architecture

- [ ] `[BLOCKING]` The frontend and backend stay separate; the frontend calls the API and never a database.
- [ ] `[BLOCKING]` PostgreSQL holds application data; MariaDB runs student SQL and holds no application data.
- [ ] `[BLOCKING]` The API contract change is in `docs/api-specification.md` before the request or response shape changes.
- [ ] `[BLOCKING]` No service, dependency, abstraction, or container is added without a stated reason and approval.
- [ ] `[NON-BLOCKING]` The change fits the existing module boundaries.

## Maintainability

- [ ] `[NON-BLOCKING]` No duplicated block that an existing function already provides.
- [ ] `[NON-BLOCKING]` No dead code, commented-out code, debug output, or unused import.
- [ ] `[NON-BLOCKING]` The change is no larger than the spec requires.

## Performance

- [ ] `[BLOCKING]` No unbounded query, unbounded loop, or missing index on a new filtered or joined column.
- [ ] `[BLOCKING]` No N+1 query in a path that returns a collection.
- [ ] `[NON-BLOCKING]` The change follows `vercel-react-best-practices` for a client component and `build-web-apps:supabase-postgres-best-practices` for a query.

## Testing

- [ ] `[BLOCKING]` Tests were written before the implementation; the red run failed for the expected reason.
- [ ] `[BLOCKING]` The negative cases exist and pass.
- [ ] `[BLOCKING]` The focused regression tests of the changed code and of every module that reads it pass.
- [ ] `[BLOCKING]` No test is weakened, deleted, skipped, or retried until it passes.
- [ ] `[BLOCKING]` Every unrun check is reported as `NOT RUN`, not as passed.

## Error handling

- [ ] `[BLOCKING]` Each failure path returns the documented status and error shape.
- [ ] `[BLOCKING]` An exception cannot leak a stack trace, SQL text, or a credential to the client.
- [ ] `[NON-BLOCKING]` The log entry has enough context to diagnose the failure without the secret.

## Data integrity

- [ ] `[BLOCKING]` A multi-row write is inside a transaction, or the reviewer records why it does not need one.
- [ ] `[BLOCKING]` A migration is reversible, or its irreversibility is stated and approved.
- [ ] `[BLOCKING]` Constraints, nullability, uniqueness, and foreign keys match `docs/database-design.md`.
- [ ] `[BLOCKING]` Test data is cleaned up and cannot reach a real database.

## Documentation

- [ ] `[BLOCKING]` The document that the change affects is updated: architecture, API, database, configuration, security, workflow, or deployment.
- [ ] `[NON-BLOCKING]` The change needs no new document, or the new document has one purpose and a link from the map in `AGENTS.md`.

## Unintended changes

- [ ] `[BLOCKING]` The diff contains only files the plan names. Every other change is reverted or explained.
- [ ] `[BLOCKING]` No file belonging to another owner or another concurrent task is edited.
- [ ] `[BLOCKING]` No `.env` value, local runtime artifact, generated file, or build output is added to the tracked tree.
- [ ] `[BLOCKING]` No formatting-only rewrite of an untouched file.

## Findings

| ID | Category | Finding | Label | Owner | Status |
|---|---|---|---|---|---|
| F-1 | &lt;category&gt; | &lt;what is wrong and where&gt; | BLOCKING / NON-BLOCKING | &lt;role&gt; | Open / Fixed / Accepted |

## Verdict

| Category | Verdict |
|---|---|
| Correctness | PASS / FAIL / UNRESOLVED |
| Security | PASS / FAIL / UNRESOLVED |
| Architecture | PASS / FAIL / UNRESOLVED |
| Maintainability | PASS / FAIL / UNRESOLVED |
| Performance | PASS / FAIL / UNRESOLVED |
| Testing | PASS / FAIL / UNRESOLVED |
| Error handling | PASS / FAIL / UNRESOLVED |
| Data integrity | PASS / FAIL / UNRESOLVED |
| Documentation | PASS / FAIL / UNRESOLVED |
| Unintended changes | PASS / FAIL / UNRESOLVED |

Overall: `PASS` only when no category is `FAIL` or `UNRESOLVED` and no `BLOCKING` finding is open. A `NON-BLOCKING` finding does not change the overall verdict. Close the change against [definition-of-done.md](definition-of-done.md).
