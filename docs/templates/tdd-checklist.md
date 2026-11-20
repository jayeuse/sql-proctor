# TDD checklist: &lt;title&gt;

> Template. Fill one checklist for each behavior change. Use it with `test-driven-development` and `negative-first-testing`. Record the real command and result for each step. `NOT RUN` is a missing step, not a pass.

| Field | Value |
|---|---|
| Spec | `SPEC-<nnn>` at `docs/specs/SPEC-<nnn>-<slug>.md` |
| Test matrix | [test-matrix.md](test-matrix.md) |
| Related task | TASK-&lt;nnn&gt; |
| Owner | &lt;role&gt; |
| Date | &lt;YYYY-MM-DD&gt; |

## Checklist

| # | Step | Done | Evidence |
|---|---|---|---|
| 1 | Specification reviewed. The objective, scope, and acceptance criteria are understood; open questions are answered or recorded. | [ ] | &lt;spec ID and review note&gt; |
| 2 | Test cases defined. Every acceptance criterion has at least one case in the matrix. | [ ] | &lt;TM IDs&gt; |
| 3 | Negative cases defined first. The failure catalog in `negative-first-testing` is covered, starting with the invalid, security, unauthorized, forbidden, invalid-state, and missing cases. | [ ] | &lt;TM IDs&gt; |
| 4 | Tests written. The cases exist in the test tree and name the acceptance criterion in the test name or comment. | [ ] | &lt;test file paths&gt; |
| 5 | Red verified. The new tests ran and each failed for the expected reason, not a syntax or setup error. | [ ] | &lt;command, exit status, artifact&gt; |
| 6 | Implementation completed. The smallest correct production code is written. No test was weakened or deleted. | [ ] | &lt;files changed&gt; |
| 7 | Green verified. The same command ran and every new test passed. | [ ] | &lt;command, exit status, artifact&gt; |
| 8 | Refactor completed. Names, structure, and duplication improved; the tests still pass unchanged. | [ ] | &lt;command, exit status, artifact&gt; |
| 9 | Regression tests completed. The tests of every module that reads the changed code ran and passed. | [ ] | &lt;commands, artifact&gt; |
| 10 | Evidence recorded. The matrix status and the run record name the command, directory, exit status, result, artifact, and time. | [ ] | &lt;`.agent-runtime/` path&gt; |

## Red run record

| Test | Expected failure reason | Actual failure reason | Matched |
|---|---|---|---|
| &lt;test name&gt; | &lt;for example: returns 200 instead of 403&gt; | &lt;the observed assertion message&gt; | Yes / No |

A red run that fails for a different reason is not a red run. Fix the test setup and run it again.

## Green and regression record

| Command | Working directory | Result | Artifact | Time (UTC) |
|---|---|---|---|---|
| &lt;command&gt; | &lt;path&gt; | Exit 0, N tests passed | &lt;path&gt; | &lt;YYYY-MM-DD HH:MM&gt; |

## Notes

- Do not start step 6 before step 5 passes.
- Do not mark step 7 complete from a different command than step 5. Use the same command so the comparison is valid.
- A test that passes without the production change is a weak test. Strengthen it before you rely on it.
- Close the change against [definition-of-done.md](definition-of-done.md).
