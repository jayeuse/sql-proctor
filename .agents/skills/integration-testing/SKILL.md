---
name: integration-testing
description: Prove SQL-Proctor works at its real boundaries, covering the Laravel API, PostgreSQL, MariaDB student-SQL execution, Sanctum authentication, the Next.js/backend API contract, database consistency, Compose services, and failure handling. Use when writing or reviewing integration or contract tests, testing migrations and constraints, testing SQL execution isolation, verifying an API response shape, or diagnosing a failure that crosses a layer. Triggers on "integration test", "feature test against the real database", "contract test", "API contract", "PostgreSQL test", "MariaDB test", "Sanctum test", "cross-attempt isolation", "timeout test", "database down", "transaction rollback", "RefreshDatabase", "test isolation", "mock the database", "Compose health", "end-to-end API".
---

# Integration Testing

## Purpose

A unit test proves one piece in isolation. SQL-Proctor's costly defects live between the pieces: Laravel's policy applied after the query, a PostgreSQL constraint that the factory avoided, a MariaDB scope that one attempt can reach from another, a Sanctum token that works on one route and not the next, or a response that the Next.js client cannot read.

This skill defines which boundaries must be tested against the real component, how test data is isolated and cleaned, and what counts as evidence.

It does not define syntax for each runner. Use [laravel-testing](../laravel-testing/SKILL.md) for PHPUnit or Pest, [frontend-testing](../frontend-testing/SKILL.md) for Vitest and React Testing Library, [e2e-playwright-testing](../e2e-playwright-testing/SKILL.md) for browser journeys, and [sql-execution-isolation](../sql-execution-isolation/SKILL.md) for the execution mechanism itself.

## Trigger conditions

Use this skill when:

- a test must cross a real boundary: HTTP route, PostgreSQL, MariaDB, Sanctum, or the Next.js client;
- a migration, constraint, transaction, or factory behavior is the risk;
- SQL execution scope, privileges, timeout, row cap, or cleanup is tested;
- an API response shape is verified against `docs/api-specification.md`;
- a failure must be reproduced across layers (frontend request, API response, database state);
- infrastructure availability or a dependency-down path is tested.

Do not use it to design the isolation mechanism, to choose product behavior, or to write a browser journey. Those belong to the related skills and the accepted specification.

## Scope

In scope:

- integration test selection and the integration matrix;
- real versus mocked decisions at each boundary;
- test data isolation and deterministic cleanup;
- contract verification against the shared API specification;
- infrastructure preflight and failure-path tests.

Out of scope:

- the MariaDB isolation design, privileges, and limits (owned by [sql-execution-isolation](../sql-execution-isolation/SKILL.md));
- unit-test granularity and pure functions;
- E2E locator strategy and browser configuration;
- deployment, CI service definitions, and production monitoring.

## Required workflow

1. Name the boundary and the risk before writing the test. Take the scenario from the test matrix ([negative-first-testing](../negative-first-testing/SKILL.md)); if the risk crosses a layer, it is an integration test.
2. Apply the mock policy in the Integration matrix. Use the real component when it is the risk. Mock only a dependency you do not own and cannot control, and never the boundary under test.
3. Preflight the services. PostgreSQL and MariaDB come from `cd infra && docker compose up -d`, both `healthy`. Confirm the test can reach each one and that the test database name is not the development database.
4. Isolate the data before the act. Use `RefreshDatabase` or an explicit transaction for PostgreSQL. Create one disposable schema per attempt for MariaDB, with a random suffix. Never reuse a name or a row across tests.
5. Arrange with factories or seeders against the real database. Use factory states for the scenario. Do not hardcode IDs that another test also uses.
6. Act through the real entry point: an HTTP request through the Laravel route and middleware for an API test, the real executor for a SQL test, and the real Axios call through a mocked transport only when the transport is not the risk.
7. Assert the visible result and the cross-boundary state: status and body shape, PostgreSQL rows, MariaDB scope contents, and the absence of leakage.
8. Clean up in a `finally`, `tearDown()`, or `afterEach()` block that runs on failure too. Drop disposable MariaDB schemas, close connections, and remove committed rows that a transaction cannot roll back.
9. Record the evidence: the command, the service state, the result, and the artifact path, in the matrix template's Evidence table. A test that did not run is `NOT RUN`, never `PASS`.

## Integration matrix

| # | Area | Real boundary | What the test proves | Mock policy |
|---|---|---|---|---|
| 1 | Laravel API integration | The HTTP kernel, routing, middleware, form requests, policies, and resource classes | A request reaches the controller with the documented status, validation, authorization, and JSON shape | Use the real application. Do not fake the router, middleware, or the policy under test. |
| 2 | PostgreSQL integration | The real PostgreSQL 17.12 database | Migrations apply from a clean state; unique, foreign-key, and not-null constraints reject invalid data; transactions roll back a partial write; factories create valid isolated rows | Use a real test database. Do not fake Eloquent, the query builder, or PDO when persistence or a constraint is the risk. |
| 3 | MariaDB execution integration | The real MariaDB 11.8.9 service | An attempt runs in a disposable schema or database; the execution user has only its scope; timeout, row cap, and statement size stop the work; cleanup removes the scope | Use the real service. Do not mock the connection, the executor, or the server when isolation, limits, or cleanup is the risk. |
| 4 | Sanctum authentication integration | Laravel Sanctum 4.5.0 with the `personal_access_tokens` table | Missing, invalid, expired, and revoked credentials return `401`; a valid credential reaches the route as the right user; a token cannot act for another user | Use the real middleware and token table. Do not stub `Auth` to bypass Sanctum when authentication is the risk. |
| 5 | Frontend/backend API contract | The documented shape in `docs/api-specification.md` and the real API response | Status, keys, types, nullability, and error shape match the shared specification that the Next.js client reads | Use the real API response. Do not define a second response shape in frontend test code; a hand-written fixture that can drift is forbidden as the source of truth. |
| 6 | Database consistency | PostgreSQL and MariaDB together, through the application | Application state and execution state agree; no orphan or half-written row; no application table or credential in MariaDB; a failed multi-step action leaves no partial result | Use both real services. Do not assert one side while mocking the other when the agreement is the risk. |
| 7 | Infrastructure dependencies | Docker Compose services, health checks, ports, and startup order | Both databases are reachable and healthy before the suite acts; a missing service is reported as blocked; the configured ports match the running services | No mock. A fake health check proves nothing. |
| 8 | Failure handling | A closed local port, a stopped dependency, a timeout, a `4xx`/`5xx` path, and a partial failure | A dependency failure returns the documented controlled error with no trace or secret; committed state survives; a retry is safe | Trigger the failure with a dedicated test connection on a closed loopback port. Do not stop a shared container that other tests use. |

### PostgreSQL: what to cover

- **Migrations.** Apply from a clean test database (`RefreshDatabase` or `php artisan migrate:fresh` on the test database only). A migration that depends on existing data must fail on a clean run; assert that.
- **Constraints.** Prove each rule by attempting a violation: duplicate `users.email`, an answer with a missing attempt, a `null` in a required column. Assert the database rejects it, not only the form request.
- **Transactions.** Make a multi-step action fail after its first write. Assert no partial row remains and the connection is reusable.
- **Factories.** Assert a factory produces a valid row on its own, and each state matches its scenario. A factory that needs another row uses `for()` or `has()`, not a hardcoded foreign ID.
- **Isolation.** `RefreshDatabase` wraps each test in a transaction and is the default. When another connection must see committed data (concurrency, a queue worker, a process boundary), commit and clean by a unique key; do not assume the transaction isolates it.

### Contract, consistency, infrastructure, and failure: what to cover

- **Contract.** Send the request with `Accept: application/json` and the client's credential mode. A missing header produces Laravel's redirect attempt and a `500`; the contract test asserts the documented `401` JSON instead. Assert status, required keys, types, nullability, timestamps in ISO 8601 UTC, and the `422` error shape. The frontend test asserts the same documented shape from one shared source (a fixture generated from a real response or a schema derived from the specification), never a hand-written local copy.
- **Consistency.** After the action, assert both stores: the PostgreSQL attempt state and answer rows agree, and the MariaDB scope contains only that attempt's objects. No application table, application credential, or application row is reachable from MariaDB.
- **Infrastructure.** A preflight checks `compose ps` for both services `healthy`, `GET /up` on Laravel, and a connection to each database. A port conflict (`MARIADB_PORT` against XAMPP MySQL) is an environment blocker, not a test failure. Do not start, stop, or recreate containers from a test.
- **Failure handling.** Cover each path separately: PostgreSQL down, MariaDB down, timeout, `4xx`, `5xx`, and partial failure. A dependency-down test uses a dedicated connection on a closed loopback port, not a stopped shared container. Assert the documented status and body, no trace or SQL text, no secret, preserved committed state, and a safe retry.

## Rules

1. **Realism first.** Test the real framework and the real database when the boundary is the risk. A mocked boundary proves the mock works.
2. **No unnecessary mocking.** Do not fake Eloquent, PDO, PostgreSQL, MariaDB, Sanctum, the HTTP kernel, or the policy under test. Fakes are for dependencies you do not own: mail, notifications, queue dispatch, third-party HTTP, and the clock. Use the framework's fakes, not hand-written doubles.
3. **One source of truth for the contract.** Assert the shape that `docs/api-specification.md` documents. Update the specification first, then both sides. Do not keep a second local shape that can drift; the contract test must fail when the API changes a documented key, type, or status.
4. **Isolate every test.** Each test creates what it needs under a unique key (random suffix, unique email, per-test schema). No test depends on another test's row, order, or leftover state.
5. **Clean deterministically.** Use `finally`/`tearDown()`/`afterEach()` so cleanup runs after a failure and after a timeout. Drop disposable MariaDB schemas with `DROP DATABASE IF EXISTS`; report a scope that cannot be removed and fail the run; never continue with silent leftovers.
6. **Never use development data.** Point tests at a dedicated test database and a disposable MariaDB scope. Do not run the suite against the dev database or a shared volume.
7. **No real credentials in code or evidence.** Read values from environment variables and name the variable, never the value. Use a test-only provisioning user for MariaDB setup, and the least-privilege execution user for the code under test. Never print a password, token, or `.env` content.
8. **Local stack only.** Send test traffic only to the local Laravel API, PostgreSQL, and MariaDB that the Human Owner runs. Never point a test at another installation.
9. **Do not manage shared infrastructure from a test.** The suite does not run `docker compose up`, `down`, or `down -v`; those are operator commands and `down -v` destroys both volumes.
10. **Bound the timeout test.** Assert that the operation stopped within a bounded elapsed time and that the connection is usable afterward. Do not assert an exact millisecond. Use a real slow statement (`SLEEP`) rather than a sleep in the test process.
11. **Make concurrency deterministic.** Use a barrier or a lock to overlap actions; do not use arbitrary sleeps. Seed committed data and clean it by key afterward.
12. **Assert the failure shape.** A `500` response must not contain a trace, an SQL string, or a credential. Assert the documented status and body for each failure path.
13. **Report unavailable services honestly.** If PostgreSQL or MariaDB is not healthy, the affected group is `BLOCKED` or `NOT RUN` with the reason, and the run is not reported as green. A skipped test is not a pass.
14. **Never weaken a test to fit the code.** A failure across a boundary is evidence about the product or the design. Report it; do not mock it away.

### Correct and wrong

Correct:

```text
TST-031 (MariaDB isolation): created attempt_a_7f3c and attempt_b_7f3c,
ran the real executor as the least-privilege user, asserted B cannot see A,
dropped both schemas in tearDown after the test failed. Result: `PASS`.
```

Correct:

```text
Contract: GET /api/attempts/{id} asserted against docs/api-specification.md:
200, id integer, status one of the documented values, answers array. The
specification was updated before the response changed.
```

Wrong:

```text
"Mocked the MariaDB connection and asserted execute() was called; isolation
is covered." (The real server, privileges, and cleanup were never exercised.)
```

Wrong:

```text
"Left the two test schemas in place so the next run can reuse them."
(Shared state, and a failure leaves permanent residue.)
```

## Example

Example requirement (illustrative): `REQ-ATTEMPT-7` and `REQ-ATTEMPT-8`. A student's SQL runs in its own disposable MariaDB scope, another attempt cannot read it, a runaway query stops at the bound, and the scope is removed after the test. Replace these IDs with the accepted specification's IDs.

Project note: PHPUnit 12.5.44 is installed today (`^12.5.12` constraint); Pest 4 is compatible and is not adopted. The executor class and its result methods below are illustrative placeholders. Replace them with the contract in the accepted feature specification and [sql-execution-isolation](../sql-execution-isolation/SKILL.md); do not copy the names blindly.

```php
<?php

namespace Tests\Integration;

use App\Services\SqlExecutor;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('mariadb')]
class SqlExecutionIsolationTest extends TestCase
{
    private \PDO $provisioner;
    private string $schemaA;
    private string $schemaB;

    protected function setUp(): void
    {
        parent::setUp();

        // Test-only provisioning user. It creates and drops scopes; the code
        // under test must connect with the least-privilege execution user.
        $this->provisioner = new \PDO(
            sprintf('mysql:host=%s;port=%s', getenv('MARIADB_HOST'), getenv('MARIADB_PORT')),
            getenv('MARIADB_TEST_PROVISION_USER'),
            getenv('MARIADB_TEST_PROVISION_PASSWORD'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        $suffix = bin2hex(random_bytes(6));
        $this->schemaA = "attempt_a_{$suffix}";
        $this->schemaB = "attempt_b_{$suffix}";

        foreach ([$this->schemaA, $this->schemaB] as $schema) {
            $this->provisioner->exec("CREATE DATABASE `{$schema}`");
        }
    }

    protected function tearDown(): void
    {
        try {
            // Runs after a pass, a failure, and a timeout. Never leave a scope.
            foreach ([$this->schemaA, $this->schemaB] as $schema) {
                $this->provisioner->exec("DROP DATABASE IF EXISTS `{$schema}`");
            }
        } finally {
            parent::tearDown();
        }
    }

    /** TST-031 | REQ-ATTEMPT-7 | cross-attempt isolation */
    public function test_an_attempt_cannot_see_another_attempts_scope(): void
    {
        $executor = app(SqlExecutor::class);

        $executor->execute($this->schemaA, 'CREATE TABLE secret (id INT)');

        // The execution user for B must not even see A's schema.
        $visible = $executor->execute(
            $this->schemaB,
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = '{$this->schemaA}'",
        );
        $this->assertSame(0, (int) $visible->rows[0]['c']);

        // A direct read across scopes must fail as an execution error.
        $denied = $executor->execute($this->schemaB, "SELECT * FROM `{$this->schemaA}`.secret");
        $this->assertTrue($denied->isError());
        $this->assertStringNotContainsString('secret', $denied->message());
    }

    /** TST-032 | REQ-ATTEMPT-8 | timeout */
    public function test_a_runaway_query_stops_at_the_bound_and_the_pool_recovers(): void
    {
        $executor = app(SqlExecutor::class);
        $started = microtime(true);

        $result = $executor->execute($this->schemaA, 'SELECT SLEEP(30)', timeoutMs: 2000);

        $elapsedMs = (microtime(true) - $started) * 1000;

        $this->assertTrue($result->timedOut());
        $this->assertLessThan(10000, $elapsedMs, 'The query must stop at its bound, not at SLEEP(30).');

        // The scope and connection must still work after the timeout.
        $next = $executor->execute($this->schemaA, 'SELECT 1 AS ok');
        $this->assertSame(1, (int) $next->rows[0]['ok']);
    }
}
```

For Pest, the same cleanup runs in `afterEach(function () { ... })`. Run the group with `php artisan test --group=mariadb`; keep it out of the default unit run only when the service is not healthy, and report the group as `BLOCKED` when it is skipped.

Contract check in the same suite: send the request across the Laravel boundary, then assert the response against `docs/api-specification.md`. The assertion lists the documented keys, types, allowed statuses, and error shape. When the frontend consumes that response, its test asserts the same documented shape from a fixture generated from a real response or from the specification; it does not re-declare the shape locally.

## References

- [Test matrix template](../../../docs/templates/test-matrix.md) — record each integration case, its result, and the evidence. Owner: TASK-0110.
- [docs/api-specification.md](../../../docs/api-specification.md) — the shared contract; update it before changing a response shape.
- [docs/database-design.md](../../../docs/database-design.md) — PostgreSQL schema, MariaDB constraints, storage, and destructive commands.
- [docs/architecture.md](../../../docs/architecture.md) — components, ports, runtime topology, and boundaries.
- [AGENTS.md](../../../AGENTS.md) — testing policy, environment constraints, and evidence rules.
- [infra/compose.yaml](../../../infra/compose.yaml) — service names, health checks, and published ports.
- [Laravel 13 testing](https://laravel.com/docs/13.x/testing) — HTTP tests, database testing, fakes, and parallel testing.
- [Laravel Sanctum](https://laravel.com/docs/13.x/sanctum) — token and SPA authentication.
- [PHPUnit 12.5](https://docs.phpunit.de/en/12.5/) — groups, fixtures, and lifecycle hooks.
- [PostgreSQL 17](https://www.postgresql.org/docs/17/) — constraints, transactions, and isolation.
- [MariaDB documentation](https://mariadb.com/kb/en/) — users, grants, limits, and information schema.
- [Next.js documentation](https://nextjs.org/docs) — client/server boundaries and environment variables.

## Related skills

| Skill | It owns |
|---|---|
| [laravel-testing](../laravel-testing/SKILL.md) | Laravel 13 test syntax, `RefreshDatabase` versus `DatabaseTransactions`, factories, Sanctum `actingAs`, and framework fakes. |
| [negative-first-testing](../negative-first-testing/SKILL.md) | Case order, the 14-category failure catalog, the test matrix, and Red-phase evidence. This skill supplies the real boundary for those cases. |
| [sql-execution-isolation](../sql-execution-isolation/SKILL.md) | Per-attempt MariaDB scope, least privilege, time and row limits, and cleanup design. |
| [e2e-playwright-testing](../e2e-playwright-testing/SKILL.md) | Browser journeys, locators, authentication state reuse, and reliability. |
| [frontend-testing](../frontend-testing/SKILL.md) | Vitest, React Testing Library, Next.js 16 client/server boundaries, and form states. |
| [api-design-patterns](../api-design-patterns/SKILL.md) | Endpoint, error, and versioning conventions used by the contract. |
