# Test matrix: &lt;title&gt;

> Template. One row per test case. Link the matrix from the spec and the implementation plan. A row is complete only when `Status` holds the result of a real run.

| Field | Value |
|---|---|
| Spec | `SPEC-<nnn>` at `docs/specs/SPEC-<nnn>-<slug>.md` |
| Related task | TASK-&lt;nnn&gt; |
| Test runner | &lt;PHPUnit ^12.5.12 (12.5.44 installed) / Pest 4 / Vitest / Playwright, or "not installed yet"&gt; |
| Date | &lt;YYYY-MM-DD&gt; |

## Negative-first ordering

Plan and run the cases in the order in `AGENTS.md` "Mandatory workflow" phase 4. Do not start the success cases before the failure cases exist and fail for the expected reason.

1. Invalid input.
2. Security violation: injection, escaping, authorization bypass, secret exposure.
3. Unauthorized access: no credential.
4. Forbidden access: a valid credential without the permission.
5. Invalid state transition: the action is not allowed in the current state.
6. Missing resource.
7. Duplicate operation.
8. Boundary condition: empty, one, maximum, and over-maximum values.
9. Concurrency: two attempts, two writers, a repeated request.
10. Infrastructure failure: database down, connection limit.
11. Timeout.
12. Resource exhaustion.
13. Data integrity failure.
14. Normal behavior.

The `negative-first-testing` skill holds the failure catalog and a SQL-Proctor example for each category. Keep this order in the matrix, the tests, and the Red phase.

A test that fails for a reason other than the one the case states is `FAIL`, not `PASS`. Never weaken or delete a test to make it pass. Fix the cause or report it.

## Matrix

| Test ID | Requirement | Scenario | Input | Expected result | Test type | Priority | Status |
|---|---|---|---|---|---|---|---|
| TM-1 | FR-1 | &lt;the condition under test&gt; | &lt;the exact input or request&gt; | &lt;observable result: status, body, row count, UI state&gt; | Unit / Feature / Integration / E2E / Manual | High | NOT RUN |
| TM-2 | NFR-1 | &lt;condition&gt; | &lt;input&gt; | &lt;result&gt; | &lt;type&gt; | Medium | NOT RUN |

## Status values

| Status | Meaning |
|---|---|
| `NOT RUN` | The case exists but has not run. It is not a pass. |
| `PASS` | The case ran and matched the expected result. Record the evidence path. |
| `FAIL` | The case ran and did not match. Link the defect. |
| `BLOCKED` | The case cannot run. Name the missing prerequisite. |
| `N/A` | The case does not apply. Give the reason. |

Use one vocabulary inside one matrix; this template uses the `AGENTS.md` words above.

## Evidence

| Test ID | Command | Working directory | Exit status | Artifact | Time (UTC) |
|---|---|---|---|---|---|
| TM-1 | &lt;command&gt; | &lt;path&gt; | 0 | &lt;`.agent-runtime/` path&gt; | &lt;YYYY-MM-DD HH:MM&gt; |
