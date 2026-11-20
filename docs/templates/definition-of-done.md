# Definition of Done: &lt;title&gt;

> Template. A change is done only when every row is checked with evidence. A row without evidence is `NOT RUN`, and the change is not done. Mark a row `N/A` only when the change cannot affect that item, and write the reason. Use it with `verification-before-completion`.

| Field | Value |
|---|---|
| Spec | `SPEC-<nnn>` at `docs/specs/SPEC-<nnn>-<slug>.md` |
| Related task | TASK-&lt;nnn&gt; |
| Owner | &lt;role&gt; |
| Reviewer | &lt;role&gt; |
| Date | &lt;YYYY-MM-DD&gt; |

## Checklist

| # | Item | Done | Evidence |
|---|---|---|---|
| 1 | Specification satisfied. Every requirement in scope is implemented. | [ ] | &lt;spec ID and requirement IDs&gt; |
| 2 | Acceptance criteria verified. Each criterion has a verdict from its named test, command, or review item. | [ ] | &lt;AC IDs and verdicts&gt; |
| 3 | Tests written. Each acceptance criterion and each negative case has a test in the matrix. | [ ] | &lt;TM IDs and test paths&gt; |
| 4 | Tests executed. The focused tests of the changed code ran. | [ ] | &lt;command, exit status, artifact&gt; |
| 5 | Tests passing. Every new and changed test passes. No test is skipped or weakened. | [ ] | &lt;command result&gt; |
| 6 | Relevant regression tests passing. The tests of every module that reads the changed code ran and passed. | [ ] | &lt;commands, artifact&gt; |
| 7 | Security reviewed. Student SQL, authentication, authorization, secrets, and data exposure were considered; the applicable security skill ran. | [ ] | &lt;review record and finding IDs&gt; |
| 8 | Code reviewed. [code-review-checklist.md](code-review-checklist.md) has no `BLOCKING` finding and no `UNRESOLVED` category. | [ ] | &lt;REV ID&gt; |
| 9 | Documentation updated where required. Architecture, API, database, configuration, security, workflow, or deployment is current. | [ ] | &lt;document paths&gt; |
| 10 | No known unintended changes. The diff contains only files the plan names. | [ ] | &lt;diff summary&gt; |
| 11 | Evidence recorded. The run record names each command, directory, exit status, result, artifact, and time. | [ ] | &lt;`.agent-runtime/` path&gt; |

## Build and style checks

| Check | Command | Result | Evidence |
|---|---|---|---|
| Lint | &lt;command&gt; | PASS / FAIL / NOT RUN | &lt;path&gt; |
| Formatting | &lt;command&gt; | PASS / FAIL / NOT RUN | &lt;path&gt; |
| Build | `cd frontend/sql-proctor-ui && npm run build` | PASS / FAIL / NOT RUN | &lt;path&gt; |
| Frontend styling | [frontend-styling-checklist.md](frontend-styling-checklist.md) | PASS / FAIL / NOT RUN | &lt;path&gt; |

## Remaining work

List every known limitation, deferred item, and open question. Write `None` only when there is none. A hidden limitation makes the change not done.

| Item | Reason | Owner | Record |
|---|---|---|---|
| &lt;item&gt; | &lt;reason&gt; | &lt;role&gt; | &lt;TASK or defect ID&gt; |
