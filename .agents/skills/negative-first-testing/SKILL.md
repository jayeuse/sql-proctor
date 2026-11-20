---
name: negative-first-testing
description: Plan and write SQL-Proctor tests failure-first, in the required 14-category order, with a test matrix and verified TDD Red evidence. Use when planning tests for a feature, writing or reviewing test code, filling docs/templates/test-matrix.md, reproducing a defect as a regression test, or ordering the TDD Red phase. Triggers on "negative test", "negative-first", "failure first", "failure catalog", "test matrix", "error cases", "edge cases", "validation test", "authorization test", "401", "403", "404", "422", "boundary test", "concurrency test", "timeout test", "write tests before implementation", "TDD red", "regression test".
---

# Negative-First Testing

## Purpose

SQL-Proctor handles untrusted student SQL, protected exam data, time-bounded attempts, and two databases with different privileges. A test suite that proves only the happy path does not show that the product fails safely.

This skill fixes three things so every feature fails safely before it succeeds:

1. the order in which failure cases are designed and run;
2. the failure catalog that each feature is checked against;
3. the evidence that the TDD Red phase produced a real failure for each case.

**Negative-first does not replace success coverage.** Every feature also needs at least one normal-path test that proves the documented behavior works. A suite of only negative cases is incomplete.

The mechanics live in other skills: PHPUnit or Pest syntax in [laravel-testing](../laravel-testing/SKILL.md), Vitest and React Testing Library in [frontend-testing](../frontend-testing/SKILL.md), real-boundary setup in [integration-testing](../integration-testing/SKILL.md), browser journeys in [e2e-playwright-testing](../e2e-playwright-testing/SKILL.md), and SQL isolation design in [sql-execution-isolation](../sql-execution-isolation/SKILL.md). This skill does not repeat them.

## Trigger conditions

Use this skill when:

- a feature or change has acceptance criteria and needs a test plan;
- tests are written before implementation (TDD Red);
- a test plan, a test matrix, or a test diff is reviewed;
- a defect must become a regression test;
- someone asks whether risk coverage is sufficient;
- a new endpoint, policy, state transition, or SQL execution path is designed.

Do not use this skill to decide framework syntax, service topology, or the isolation mechanism. Use the related skills.

## Scope

In scope:

- the order of failure cases and the required failure catalog;
- the test matrix and its required columns;
- the Red-phase evidence rule;
- assertion quality for failure cases: status, error shape, side effects, and no data leak.

Out of scope:

- framework and runner syntax;
- test-environment setup and service provisioning;
- the MariaDB isolation model, privileges, and limits;
- success-path coverage rules beyond the "does not replace" rule.

## Required workflow

1. Read the requirements and acceptance criteria. Copy each criterion ID into the matrix. If a criterion cannot be tested, record it and raise it with the Product Manager; do not rewrite it.
2. Open [docs/templates/test-matrix.md](../../../docs/templates/test-matrix.md) (owned by TASK-0110) and fill one row per case **before** writing test code. Keep the template's header (spec, task, runner, date), its **Matrix** table with **Test ID, Requirement, Scenario, Input, Expected result, Test type, Priority, Status**, and its **Evidence** table. Do not invent a second format.
3. Order the cases with the fixed order below. Order decides design, review, and Red-phase sequence, not the size of the suite.
4. Write each case as a test. Run it. Confirm it fails, and confirm it fails **for the expected reason**. A syntax error, a runner error, a missing route with `500` instead of the specified `401`/`403`, or a `200` that should be `422` is not a verified Red; fix the test or the expectation until the failure matches the matrix.
5. Implement the smallest change that makes the case pass. Do not weaken the assertion, do not catch the error into a pass, and do not delete the case.
6. Turn it green, then continue in the same order. Add the normal-path case last.
7. Re-run the focused tests and the project's suite. Update each matrix row `Status` with the template's values: `PASS`, `FAIL`, `NOT RUN`, `BLOCKED`, or `N/A`. A skipped, blocked, or unrun case is `NOT RUN` or `BLOCKED`, never `PASS`. Link the evidence path in the Evidence table.
8. Attach the evidence to the task or change: command, exit status, the Red failure message, and the Green result. Do not claim coverage that did not run.

### Required case order (fixed)

Design, write, and run the categories in this order:

1. Invalid input.
2. Security violation.
3. Unauthorized access.
4. Forbidden access.
5. Invalid state transition.
6. Missing resource.
7. Duplicate operation.
8. Boundary condition.
9. Concurrency.
10. Infrastructure failure.
11. Timeout.
12. Resource exhaustion.
13. Data integrity failure.
14. Normal behavior.

Why this order: the earliest categories carry the highest cost of a defect. A validation hole or an access-control gap can expose another student's exam; a missing success test only delays a feature.

This order matches `AGENTS.md` phase 4 and [docs/templates/test-matrix.md](../../../docs/templates/test-matrix.md). Do not use a condensed order.

## Failure catalog

Each category must have at least one SQL-Proctor case when the feature touches the relevant risk. An example is a starting point, not a complete list.

| # | Category | What the test proves | SQL-Proctor example |
|---|---|---|---|
| 1 | Invalid input | Malformed, empty, oversized, wrong-type, or badly encoded input is rejected at the server boundary, before any state changes. | Submit an answer with an empty `sql` field, a 1 MB statement, and a non-UTF-8 body: `422`, field errors in the documented shape, no answer row written. |
| 2 | Security violation | Untrusted student SQL cannot read application data, escape its scope, or inject into application queries. | `SELECT * FROM users`, `SELECT ... INTO OUTFILE`, and `; DROP DATABASE sql_exec` all fail inside the attempt scope; `?sort=id;--` on a list endpoint cannot change the query. |
| 3 | Unauthorized access | A request without valid Sanctum credentials is rejected, and no protected work happens. | `POST /api/attempts/{id}/execute` with no token and with an expired token: `401`, no query reaches MariaDB. |
| 4 | Forbidden access | An authenticated user without the right role or ownership is rejected, and the response does not leak the record. | A student reads or executes another student's attempt: `403`, or `404` identical to an unknown ID; the response contains no attempt, answer, or owner data. |
| 5 | Invalid state transition | Actions are refused unless the record is in an allowed state, and the state does not change. | Executing SQL in a submitted or expired attempt, or editing a published exam: `409` or the documented code, and the attempt or exam row is unchanged. |
| 6 | Missing resource | An unknown ID or route returns the documented not-found response, not a crash or an empty success. | `GET /api/attempts/999999` and an unknown question ID: `404` with the standard JSON error shape; no `500`, no stack trace. |
| 7 | Duplicate operation | A repeated action creates one record or returns the defined idempotent result. | Double-click on "Submit answer" sends two identical submissions: one answer row exists; the second request returns the documented conflict or idempotent result. |
| 8 | Boundary condition | Values at, just below, and just above each limit behave as documented. | Time limit at `0 s` and at the exact end, row cap at 0, 1, and cap+1 rows, empty exam with 0 questions, page size at its maximum: each has the documented result. |
| 9 | Concurrency | Two actions at the same time do not corrupt state or cross scopes. | Two students execute SQL at the same time on separate attempts and neither sees the other's schema; two "start attempt" requests for one student produce exactly one active attempt. |
| 10 | Infrastructure failure | A dependency failure returns a controlled error and preserves committed state. | PostgreSQL is stopped: the API returns `503` in the documented shape with no trace; MariaDB is stopped: execution reports a controlled failure and the attempt stays usable. |
| 11 | Timeout | A slow operation is stopped at the configured bound and releases its resources. | `SELECT SLEEP(30)` under a 2 s query bound returns the timeout result within a bounded elapsed time; the connection is released and the next attempt still executes. |
| 12 | Resource exhaustion | Statement size, row count, connection, and memory limits stop the work instead of the process. | A query that would return millions of rows stops at the row cap; a statement above the size limit is rejected; N parallel executions return the documented limit error, not a crash. |
| 13 | Data integrity failure | Constraints, transactions, and foreign keys prevent partial or contradictory data. | An answer without an attempt violates a foreign key; a grading run that fails midway rolls back its score and audit rows together; a duplicate `users.email` is rejected. |
| 14 | Normal behavior | The documented behavior works for the valid actor in the valid state. | The owner executes a valid query on their own attempt, sees the bounded result, submits, and sees the stored answer and updated state. |

Two categories need care:

- **Security violation vs forbidden access.** Category 2 is the content of the student's SQL and the isolation boundary. Category 4 is the API's authorization decision. Test both; passing one does not prove the other.
- **Infrastructure failure vs timeout vs resource exhaustion.** These are different failure paths with different messages and different cleanup. A single "server error" test does not cover all three.

## Rules

1. Copy requirement IDs from the accepted requirements. Do not invent or reword criteria in the matrix.
2. Every acceptance criterion gets at least one negative case where the risk exists, plus at least one normal-path case.
3. Write the negative tests first. A success test written first hides the failure paths behind a green run.
4. In the Red phase, record the command, the exit status, and the exact failure message for each case. "It would fail" is not evidence.
5. Assert the failure completely: status code, documented error shape (for example `{"message": ...}`), no protected data in the body, and the unchanged database state. A `403` with the record in the body is still a failure.
6. Assert the specific expected failure. `assertStatus(500)` where the spec says `422` is a false pass; it proves the app crashed, not that it validated.
7. Test through the real entry point for the risk: an HTTP route for authorization, the real MariaDB boundary for execution, the real policy through the route for forbidden access. Use [integration-testing](../integration-testing/SKILL.md) for the boundary setup.
8. Use [sql-execution-isolation](../sql-execution-isolation/SKILL.md) as the source for scope, privileges, and limits; do not restate or invent the mechanism here.
9. No real credential, token, personal data, or `.env` value in a test, fixture, or recorded failure message.
10. Never weaken, skip, or delete a failing negative test to make a change pass. Report the defect instead.
11. Keep test IDs in test names or dataset labels, so a reviewer can map each test to its matrix row.
12. A flaky negative test is a defect in the test or the product. Never retry until green.

### Correct and wrong

Correct:

```text
TST-011 | REQ-ATTEMPT-3 | forbidden access
Red: php artisan test --filter=test_another_student_cannot_read_an_attempt
  FAILED: expected 404, received 200; the response contained the attempt row.
Green: same command, PASS. Matrix row set to `PASS`.
```

Correct:

```text
TST-020 | REQ-EXAM-5 | invalid state transition
Red: expected 409, received 200; the published exam title changed.
Fix: policy check added. Green. Matrix row set to `PASS`.
```

Wrong:

```text
"The policy test exists and the suite is green, so the matrix says `PASS`." (No run is recorded for the case.)
```

Wrong:

```text
assertStatus(500)  // "something failed, so validation is covered"
```

## Example

Requirement: `REQ-ATTEMPT-3`. A student may read only their own exam attempt. The response must not reveal another student's attempt.

The requirement ID, model, and endpoint below are illustrative. Replace them with the names in the accepted specification; do not treat this example as a specification.

Matrix rows before test code:

| Test ID | Requirement | Scenario | Input | Expected result | Test type | Priority | Status |
|---|---|---|---|---|---|---|---|
| TST-011 | REQ-ATTEMPT-3 | Another student reads an attempt | Student B, attempt owned by A | `404` (or the status `docs/api-specification.md` defines), no attempt or answer data, attempt count unchanged | Feature | Critical | NOT RUN |
| TST-012 | REQ-ATTEMPT-3 | Unknown attempt | Student A, ID `999999` | Same status and body shape as TST-011; no `500` | Feature | High | NOT RUN |
| TST-013 | REQ-ATTEMPT-3 | Missing credentials | No token | `401`, no data | Feature | Critical | NOT RUN |
| TST-014 | REQ-ATTEMPT-3 | Owner reads own attempt | Student A, own attempt | `200`, documented attempt shape | Feature | High | NOT RUN |

The negative cases run first; the owner success case runs last.

```php
<?php

namespace Tests\Feature;

use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamAttemptAccessTest extends TestCase
{
    use RefreshDatabase;

    /** TST-011 | REQ-ATTEMPT-3 | forbidden access */
    public function test_another_student_cannot_read_an_attempt_and_no_attempt_data_is_leaked(): void
    {
        $owner = User::factory()->create();
        $otherStudent = User::factory()->create();
        $attempt = ExamAttempt::factory()->for($owner)->create();

        // Use the status the API specification defines. When existence is
        // sensitive, prefer 404: a 403 confirms that the record exists.
        $this->actingAs($otherStudent, 'sanctum')
            ->getJson("/api/attempts/{$attempt->id}")
            ->assertNotFound()
            ->assertJsonMissingPath('id')
            ->assertJsonMissingPath('answers');

        $this->assertDatabaseCount('exam_attempts', 1);
    }

    /** TST-012 | REQ-ATTEMPT-3 | missing resource */
    public function test_unknown_attempt_returns_the_documented_not_found_shape(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/attempts/999999')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    /** TST-013 | REQ-ATTEMPT-3 | unauthorized access */
    public function test_guest_cannot_read_an_attempt(): void
    {
        $attempt = ExamAttempt::factory()->create();

        $this->getJson("/api/attempts/{$attempt->id}")
            ->assertUnauthorized();
    }

    /** TST-014 | REQ-ATTEMPT-3 | normal behavior */
    public function test_owner_can_read_own_attempt(): void
    {
        $owner = User::factory()->create();
        $attempt = ExamAttempt::factory()->for($owner)->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/attempts/{$attempt->id}")
            ->assertOk()
            ->assertJsonPath('id', $attempt->id);
    }
}
```

Project note: PHPUnit 12.5.44 is installed today (`^12.5.12` constraint). Pest 4 is compatible and is not adopted; adapt the syntax from [laravel-testing](../laravel-testing/SKILL.md) if that changes.

## References

- [Test matrix template](../../../docs/templates/test-matrix.md) — required columns, status values, and evidence table. Owner: TASK-0110. Do not create a second template.
- [AGENTS.md](../../../AGENTS.md) — TDD, negative-first, and evidence rules.
- [docs/api-specification.md](../../../docs/api-specification.md) — status codes and error shapes asserted by a negative test.
- [docs/database-design.md](../../../docs/database-design.md) — PostgreSQL and MariaDB boundaries, constraints, and destructive commands.
- [docs/development-roadmap.md](../../../docs/development-roadmap.md) — the critical journeys that need coverage.
- [PHPUnit 12.5 documentation](https://docs.phpunit.de/en/12.5/) — test structure, data providers, assertions.

## Related skills

| Skill | It owns |
|---|---|
| [test-driven-development](../test-driven-development/SKILL.md) | The Red-Green-Refactor cycle, the Iron Law, and what counts as a verified Red. This skill adds the project's case order and catalog. |
| [laravel-testing](../laravel-testing/SKILL.md) | Laravel 13 test syntax, `RefreshDatabase` versus `DatabaseTransactions`, factories, Sanctum `actingAs`, and fakes. |
| [frontend-testing](../frontend-testing/SKILL.md) | Vitest, React Testing Library, Next.js 16 client/server boundaries, form and authentication states. |
| [integration-testing](../integration-testing/SKILL.md) | Real Laravel, PostgreSQL, MariaDB, Sanctum, and frontend/backend contract boundaries. |
| [e2e-playwright-testing](../e2e-playwright-testing/SKILL.md) | Browser journeys, locators, authentication state, and reliability. |
| [sql-execution-isolation](../sql-execution-isolation/SKILL.md) | Per-attempt MariaDB scope, least privilege, limits, and cleanup. |
