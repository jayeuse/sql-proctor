---
name: sql-execution-isolation
description: "Design, build, review, and verify isolated execution of untrusted student SQL on SQL-Proctor's MariaDB 11.8.9 engine: per-attempt scope (schema per attempt, database per attempt, or restricted server), least-privilege execution accounts, application and student credential separation, query time, row, result, and connection limits, seed data, reset, cleanup on success and failure, concurrency, audit logging, and safe error responses. Use when planning or reviewing an exam-attempt execution feature, MariaDB grants for student queries, a query timeout or runaway query, attempt seed data, attempt cleanup or reset, or a suspected cross-attempt or application-data exposure. Triggers on \"SQL execution\", \"run student query\", \"query sandbox\", \"execution isolation\", \"least privilege MariaDB\", \"max_statement_time\", \"MAX_USER_CONNECTIONS\", \"runaway query\", \"attempt cleanup\", \"attempt reset\", \"student SQL\", \"sql_exec\"."
---

# SQL Execution Isolation

> Project-specific skill. Last verified 2026-11-19 against the MariaDB 11.8 documentation and the pinned 11.8.9 image, OWASP ASVS 5.0.0, and OWASP Top 10:2025.

## Purpose

SQL-Proctor executes SQL that a student writes during an exam, against MariaDB 11.8.9. That SQL is untrusted input in the strongest sense: the product runs it on purpose, and every statement is also a possible attack on the engine, the exam, or another student.

This skill fixes four things:

1. the decisions that must be recorded before any execution feature is built;
2. the rules that do not depend on which isolation model is chosen;
3. the checks that prove the chosen model works;
4. the examples that show a safe shape for the two highest-risk operations: cross-scope access tests and a bounded execution call.

**This skill does not choose the isolation model.** The repository records the model, the execution privileges, the seed source, the cleanup trigger, and the limits as `TBD` ([docs/database-design.md](../../../docs/database-design.md#mariadb-sql_exec)). The choice is a technical design and a Human Owner approval.

**SQL text filtering is not a security control.** Do not design this feature around a keyword blocklist or a regex allowlist. SQL has comments, quoting, functions, and side effects; a filter that blocks `DROP` does not stop `RENAME`, `LOAD DATA`, a stored routine, a file read, or a result set that returns another scope's rows. The controls are the execution scope, the account privileges, the resource limits, and the authorization decision. A filter can exist as defence in depth, never as the boundary.

## Trigger conditions

Use this skill when:

- a design, task, or pull request adds or changes anything that runs student SQL;
- a technical design must choose or revise the isolation model, the execution account, or its privileges;
- a grant, a `max_statement_time`, a row cap, a connection limit, or a cleanup job is proposed;
- an exam attempt must be seeded, reset, or destroyed;
- a timeout, a runaway query, a connection-limit error, or a stuck attempt is investigated;
- a security review covers the execution path, the seed data, the audit record, or an error response;
- a test proves or disputes cross-attempt isolation.

Do not use this skill for application-database schema, migrations, or Eloquent work. Use `docs/database-design.md` and `build-web-apps:supabase-postgres-best-practices`. Do not use it for general MariaDB tuning. Use `mariadb-query-optimization`.

## Threats in scope

| ID | Threat | Boundary that must stop it |
|---|---|---|
| T-01 | Attempt A reads attempt B's rows or schema | Per-attempt scope plus grants that do not reach B |
| T-02 | Attempt A writes, alters, or drops B's objects | Same, with write and DDL privileges checked separately |
| T-03 | Student SQL reads application data | Separate engine; no application schema in MariaDB; no data copy into MariaDB |
| T-04 | Student SQL uses a server capability: files, users, other databases, server variables | Least-privilege grants; no `FILE`, `PROCESS`, `SUPER`, `CREATE USER`, `GRANT OPTION`, no `mysql.*` |
| T-05 | A query or a client exhausts time, rows, memory, connections, or disk | Time, row, result-size, statement-size, and connection limits, with a client-side deadline |
| T-06 | The execution credential reaches a browser, a log, a record, or the repository | Separate credential source; redaction; no credential in any response or record |
| T-07 | An error response reveals a credential, a DSN, a stack trace, an internal path, or another attempt's data | Error mapping at the API; sanitized log line; safe student-facing code and message |
| T-08 | A student executes SQL on an attempt they do not own | Laravel policy before any connection; no client-supplied scope identifier |
| T-09 | One student sees another student's leftover tables or rows in a reused scope | Per-attempt scope or a proven reset; no scope reuse without a verified wipe |
| T-10 | A crashed, timed-out, or disconnected execution leaves an orphan scope or a stuck lease | Durable cleanup with a sweep; idempotent cleanup; lease expiry |
| T-11 | Nobody can tell what ran, on which attempt, with what outcome | Audit record in the application store, with redaction and retention |

These threats map to OWASP Top 10:2025 A01 Broken Access Control, A02 Security Misconfiguration, A05 Injection, A06 Insecure Design, A09 Security Logging and Alerting Failures, and A10 Mishandling of Exceptional Conditions. Apply ASVS 5.0.0 in the same areas. The installed `laravel-owasp-security` checklist uses the 2021 category numbers; map by name, not by number.

## Scope

In scope:

- the execution boundary between the Laravel API and MariaDB 11.8.9;
- the isolation model, the execution account and its grants, and the scope lifecycle;
- time, row, result, statement, and connection limits;
- seed data, reset, cleanup, and orphan recovery;
- concurrency and leases;
- the audit record and the student-facing error;
- the verification checklist and its evidence.

Out of scope:

- application authentication, authorization, and session rules (owned by `laravel-owasp-security` and the Laravel code);
- PostgreSQL schema and queries;
- grading and expected-result comparison (a later phase);
- MariaDB server tuning, indexing, and optimizer behavior (owned by `mariadb-query-optimization`);
- CI, deployment, and production secret management (a later phase; local development uses `infra/.env`).

## Required workflow

1. Read the current state: [docs/architecture.md](../../../docs/architecture.md), the MariaDB section of [docs/database-design.md](../../../docs/database-design.md), Phase 3 of [docs/development-roadmap.md](../../../docs/development-roadmap.md), and [docs/api-specification.md](../../../docs/api-specification.md). Record which facts are current and which are `TBD`. Do not assume the MariaDB container has started or has tables.
2. Copy the decision register below into the technical design or specification. Fill every row with a value, an owner, and a reason. Leave no row as "obvious" or "later".
3. Put the isolation model and the execution-account privileges through approval before writing execution code. The repository's [approval rules](../../../AGENTS.md#approval-rules) list "SQL execution isolation change" and "security-boundary change" as items that need the Human Owner. The design proposes; the Human Owner approves; the Security Engineer reviews.
4. Write the failure cases first, in the order from `negative-first-testing`, and fill the matrix from `docs/templates/test-matrix.md` before test code. The security, authorization, timeout, resource, concurrency, and infrastructure rows are mandatory for this feature.
5. Build in three separate steps with separate privileges: **prepare** the scope (create and seed), **execute** the statement (least privilege), **destroy or reset** the scope (provisioner only). Do not merge them into one account.
6. Apply the limits to every execution: statement size before connecting, time at the session and the account, rows and bytes while reading, connections at the account and the pool.
7. Record the audit row for every attempt, including refusals, timeouts, and engine errors. Redact before writing.
8. Run the verification checklist on the local Compose stack. Record the command, the exit status, and the observed output. `NOT RUN` is not a pass.
9. Return the residual risk and the open `TBD` values in the completion report. Never close the task with an unrecorded limit or an untested cleanup path.

## Design decisions that must be made before implementation

The decision register is the gate. A row without a recorded value blocks the feature.

| ID | Decision | Question the design must answer | Recorded value |
|---|---|---|---|
| D-01 | Isolation model | Schema per attempt, database per attempt, or restricted server? | `TBD` |
| D-02 | Scope naming and lookup | How is a scope named, and how is it resolved from the attempt row? Client input must never select it. | `TBD` |
| D-03 | Execution account model | One shared least-privilege account with grants on the current scope, one account per attempt, or one account per server? | `TBD` |
| D-04 | Execution privileges | Which statements may a student run: `SELECT` only, read-write DML, or DDL inside the scope? Each choice changes the grants. | `TBD` |
| D-05 | Provisioner account | Which separate account creates and drops scopes, and does it exist in every model? | `TBD` |
| D-06 | Credentials | Where does each account's password live, who reads it, and how is it rotated? Nothing enters the browser, the repository, an audit row, or an error. | `TBD` |
| D-07 | Account host pattern | Which host pattern does the account accept, given the loopback-published port and the container network? `'%'` needs a recorded reason. | `TBD` |
| D-08 | Seed data | Which synthetic source seeds the scope, who loads it, and how is "no application data" proven? | `TBD` |
| D-09 | Reset semantics | Does reset re-run the seed, recreate the scope, or restore a snapshot? What happens to a dropped or altered seed table? | `TBD` |
| D-10 | Cleanup triggers | Cleanup on success, failure, timeout, disconnect, worker restart, and volume restore; which sweep retries an orphan, and after what delay? | `TBD` |
| D-11 | Time limits | Session and per-statement `max_statement_time`, the account backstop, and the client-side deadline. Values in seconds. | `TBD` |
| D-12 | Row and size limits | Maximum statement bytes, returned rows, returned bytes, and affected rows; how a truncated result is reported. | `TBD` |
| D-13 | Connection limits | `MAX_USER_CONNECTIONS`, server `max_connections` headroom, pool size, acquisition timeout, and what the student sees at the limit. | `TBD` |
| D-14 | Concurrency policy | May one attempt have two executions at once, and how many executions per student? Refuse or queue? | `TBD` |
| D-15 | Statement policy | Are multiple statements per execution allowed, and which client and server settings enforce the answer? | `TBD` |
| D-16 | Audit record | Which fields, where stored (never in MariaDB), retention, access, and redaction rules. | `TBD` |
| D-17 | Error contract | The student-facing error codes and messages for syntax error, denied privilege, timeout, row cap, connection limit, engine down, and cleanup failure. | `TBD` |
| D-18 | Engine failure | What happens to the attempt when MariaDB stops, restarts, or loses the volume mid-attempt? | `TBD` |
| D-19 | Infrastructure limits | Disk, memory, and container limits per scope and per server; what happens when the volume fills. | `TBD` |
| D-20 | Transport | Is the loopback connection encrypted, and is certificate verification meaningful or explicitly waived with a recorded reason? | `TBD` |

### Isolation model comparison

| Aspect | Schema per attempt | Database per attempt | Restricted server |
|---|---|---|---|
| Boundary | One database (schema) per attempt on the shared 11.8.9 server | One database per attempt on the shared server | A separate server or container per attempt, or a leased pool of them |
| Engine isolation | Namespace and grant only; one process, buffer pool, disk, and connection budget | Same engine mechanism as schema per attempt | Process, port, data directory, connection budget, and usually disk separate |
| Setup cost | `CREATE DATABASE` plus seed; seconds | Same, with its own naming and grant convention | Instance or container start, image pull, bootstrap, health wait; seconds to minutes |
| Cleanup cost | `DROP DATABASE` plus orphan sweep | Same | Container or instance destroy plus volume removal; leaked volumes are the main failure |
| Privilege shape | Needs a per-attempt grant, or a shared account whose grants are revised per attempt | A natural one-database grant per attempt | Still needs least privilege inside each instance |
| Resource blast radius | Shared: one heavy query can affect every attempt on the server | Shared, as above | Contained to the instance, while host disk and memory remain shared |
| Concurrency | Suitable for one attempt at a time with a lease; needs a per-attempt lock | Same | Natural one-attempt-per-instance lease; queueing needs a pool |
| Main failure modes | Orphan schemas, name collisions, wide grants that expose other attempts, disk growth | Same as schema per attempt | Container leaks, port or volume exhaustion, slow starts, image drift, host limits |
| Cost and complexity | Lowest | Low, mostly convention and tooling | Highest; orchestration and host capacity dominate |

Two technical facts change how the choice is read:

1. **In MariaDB, `SCHEMA` and `DATABASE` are synonyms** ([CREATE DATABASE](https://mariadb.com/docs/server/reference/sql-statements/data-definition/create/create-database)). There is no nested namespace: a per-attempt scope is a top-level database, a sibling of `sql_exec`, not a child of it. The difference between "schema per attempt" and "database per attempt" is naming, administration, and the shape of the grant, not engine-level isolation strength. Choose it for operational reasons, not because it adds a boundary.
2. **A restricted server is the only option that separates process, memory, and connections.** It is also the only option that needs infrastructure orchestration. If the application would create those containers itself, it needs access to a container runtime; that access is effectively host control and needs its own approval. Prefer a pre-created pool or a manual local stack until that decision is recorded.

Leasing a pool of pre-created scopes is a scheduling pattern inside one of the three models. It does not substitute for the model decision or the grant decision.

The model, the account model, and the privileges change a schema and an access boundary. A change to any of them after approval is a new security-boundary change, not an implementation detail.

### Engine facts that shape the design

| Fact | Consequence |
|---|---|
| MariaDB is not a drop-in replacement for MySQL 8.0 or PostgreSQL (see `mysql-to-mariadb`) | Do not copy isolation or grant advice written for MySQL 8.0. Verify syntax on 11.8. |
| Per-query limit on 11.8: `SET STATEMENT max_statement_time=100 FOR SELECT ...` | Use this form. `SELECT MAX_STATEMENT_TIME=N ...` is not valid in MariaDB. |
| Per-account limits: `GRANT USAGE ON *.* TO 'user'@'host' WITH MAX_USER_CONNECTIONS 10 MAX_STATEMENT_TIME 60;` | Use the account option as the backstop when per-statement settings are missing or bypassed. |
| `max_statement_time` is in seconds, accepts a fraction, and is checked at intervals | It bounds time but does not guarantee an instant stop. Pair it with row, connection, and client-side limits. |
| `MAX_EXECUTION_TIME(ms)` is an optimizer hint from MariaDB 12.0 | It does not exist on 11.8. Do not use it. |
| `LIMIT ROWS EXAMINED n` is a MariaDB extension for `SELECT` | It caps examined rows and returns a partial result with a warning. Treat the warning as truncation. It is not available for `UPDATE` or `DELETE`. |
| `SHOW DATABASES` without the privilege lists only databases where the user has privileges | Cross-attempt visibility is decided by grants, not by hiding a name. |
| `KILL` without `SUPER` works only on the user's own threads | If attempts share one account, one execution could kill another attempt's query. Prefer time limits and a per-attempt lease; decide `KILL` deliberately. |
| DDL implicitly commits and is not rolled back like PostgreSQL DDL | Reset by re-seeding or recreating, not by rolling back. A dropped seed table is not restored by `ROLLBACK`. |
| The Compose MariaDB volume survives `docker compose down` | Orphan scopes and stale grants survive a restart. Cleanup must find them. |
| The Compose-created `MARIADB_USER` account is the image's bootstrap account for the configured database, not automatically a least-privilege execution account | Reusing it is a finding until `SHOW GRANTS` proves the required minimum. |

## Hard rules

1. **Treat student SQL as hostile, and treat the executed statement as the product.** Do not sanitize away the SQL you were asked to run. Place it in a scope and under an account that make the worst statement harmless to everything else.
2. **Never use application credentials.** The execution account is not the PostgreSQL role, not the user in the Laravel `DB_*` configuration, and not the Compose bootstrap user unless the design proves the minimum. Separate credential, separate engine, separate connection configuration.
3. **Never connect the execution engine to PostgreSQL.** No foreign data wrapper, `FEDERATED` or `CONNECT` table, database link, dump, copy, or synchronization into MariaDB. No application table, view, or row exists in MariaDB.
4. **Never give student SQL access to application tables or data.** Seed data is synthetic and local to the attempt. Student names, emails, answers, scores, and tokens never appear in MariaDB.
5. **Least privilege, scoped to the attempt.** Grant only the privileges the approved statement policy needs, only on the current scope. No `FILE`, `PROCESS`, `SUPER`, `CREATE USER`, `RELOAD`, `SHUTDOWN`, `SET USER`, or `GRANT OPTION`. No privilege on `mysql.*`, on another attempt's scope, or globally beyond `USAGE` plus the resource options. One execution must not be able to grant, revoke, or create a user. Do not grant `CREATE ROUTINE`, `TRIGGER`, `EVENT`, or `EXECUTE` unless the design addresses definer rights: a `SQL SECURITY DEFINER` routine or trigger runs with the definer's privileges, and an event can run after cleanup. Keep the client's `LOCAL INFILE` capability disabled and verify it; when a client enables it, a statement can make the API process read a local file.
6. **The scope comes from the server, never from the request.** Resolve the attempt from the authenticated user and the route, run the Laravel policy, then load the scope from the attempt row. A schema name, database name, connection id, or "use database" value from the client is a security defect, even when it is validated.
7. **Authorize before you connect.** A refused request opens no MariaDB connection and runs no SQL. The frontend check is not the authorization.
8. **One attempt, one scope, one lease.** A second concurrent execution on the same attempt is refused or queued by policy, not interleaved. A scope is not dropped, reset, or reassigned while its lease is held.
9. **Deterministic setup, seed, and reset.** The same seed produces the same row counts and values every time. Seed loading is versioned with the question, not generated ad hoc at run time.
10. **Deterministic cleanup on every exit.** Cleanup runs after success, error, timeout, client disconnect, worker restart, and layout change — and a sweep retries what a crashed process left behind. Cleanup is idempotent, deletes only the attempt's own scope, and never uses `docker compose down -v`, never drops the PostgreSQL database, and never touches another attempt.
11. **Bound time, rows, bytes, statements, and connections.** Every value is recorded in the design and enforced in the API as well as the engine. A missing limit is a finding.
12. **Fail closed.** If the scope is missing, the seed failed, the lease is stale, the account cannot be verified, the limit cannot be applied, the previous cleanup failed, or the engine version is not the pinned one, refuse the execution. Do not fall back to a default or shared scope.
13. **Map errors; do not relay them.** Return a stable code and a short message from the recorded error contract. Never return the raw driver message (it can name the account and host), a credential, a DSN, a stack trace, a file path, a MariaDB version banner, or another attempt's data. Keep the detail in the server log with redaction.
14. **Log enough to audit, and nothing secret.** Record attempt id, user id, scope id (not a credential), statement (or hash, per policy), start, duration, rows, outcome, and error code. Never log passwords, tokens, cookies, connection strings, seed values that contain personal data, or full result sets.
15. **Keep the audit record in the application.** MariaDB holds no application data, so the audit row belongs in PostgreSQL or the structured application log. The MariaDB general query log is server-wide, unbounded, and can capture statements that contain credentials; it is not the audit design.
16. **Bound the result while reading it.** Read at most the row cap, stop at the byte cap, and close the cursor. Do not load an unbounded result into PHP memory and then trim it.
17. **Test on the local stack only.** Never send execution traffic, malformed SQL, or load tests to another installation, a shared server, or a production database.
18. **No real secret in a skill, test, fixture, or record.** Use a placeholder or the local `infra/.env` indirection. Never print a value to prove it exists.

### Correct and wrong

Correct:

```text
Account: exec_attempt (least privilege). SHOW GRANTS shows SELECT, INSERT, UPDATE,
DELETE, CREATE, DROP on `attempt_<server-side id>` only, with MAX_USER_CONNECTIONS
and MAX_STATEMENT_TIME set. The API resolves the scope from the attempt row after
the policy check and proves it with V-01, V-02, and V-10.
```

Wrong:

```text
Reuse the Compose `sql_runner` account because it already exists, and pass
?database=attempt_42 from the client so the executor knows where to run.
```

Correct:

```text
SET STATEMENT max_statement_time=2 FOR SELECT * FROM students;   -- MariaDB 11.8
```

Wrong:

```text
SELECT /*+ MAX_EXECUTION_TIME(2000) */ * FROM students;          -- 12.0+, not 11.8
```

Correct:

```text
Cleanup deletes `attempt_<id>` only after the lease check, is idempotent, and a
sweep removes orphans left by a crash. A failed cleanup blocks the next execution
in that attempt.
```

Wrong:

```text
On submit, drop every schema whose name starts with "attempt_".
```

## Verification checklist

Run every check on the local Compose stack, with the recorded limits. Record the command, the exit status, and the output. A check that did not run is `NOT RUN`.

| ID | Check | Method | Pass condition |
|---|---|---|---|
| V-01 | Attempt A cannot read attempt B's scope | As A's execution account, `SELECT` from a known table in B's scope | Denied with the database- or table-scope error; zero rows returned; A's own scope still works |
| V-02 | Attempt A cannot write attempt B's scope | As A, run `INSERT`, `UPDATE`, `DELETE`, `CREATE TABLE`, `DROP TABLE` against B's scope | Every statement denied; B's row counts and objects unchanged |
| V-03 | A student cannot read application tables | As the execution account, try `users`, `personal_access_tokens`, `sessions`, and the application schema name; also list schemas and engines as the admin | Unknown database or denied; no application schema, table, `FEDERATED`, or `CONNECT` object exists in MariaDB |
| V-04 | No application credential exists in MariaDB | As admin, `SELECT User, Host FROM mysql.user` and `SHOW GRANTS` for each account; compare with the recorded design | No account named after the Laravel or PostgreSQL role; every account and grant matches the design and grants no more; no application schema is reachable |
| V-05 | A runaway query is stopped | `SET STATEMENT max_statement_time=<limit> FOR SELECT SLEEP(<much larger>);` and a heavy cross join | Interrupted inside the recorded bound plus the check interval; the connection is released; the next execution succeeds |
| V-06 | The account backstop applies | Repeat the runaway query with the per-statement setting removed or overridden | The account-level `MAX_STATEMENT_TIME` still stops it, or the client deadline does and the gap is recorded |
| V-07 | Connection limit and leak | Open `MAX_USER_CONNECTIONS + 1` connections as the execution account; then run and release many executions | The extra connection is refused with the documented limit error; no connection remains after the bursts |
| V-08 | Row and byte caps | Run a query that would return far more rows and bytes than the cap | The response stops at the cap, reports truncation honestly, and does not exhaust PHP memory |
| V-09 | Cleanup after success | Complete an attempt, then query the admin view for its scope | Scope gone (or reset to the seed state); no tables, grants, or files left; audit row present |
| V-10 | Cleanup after failure, timeout, and disconnect | Kill the worker during execution; force a timeout; abort the client request; then run the sweep | Each attempt's scope is removed by the sweep; no orphan survives the recorded retry window; the next attempt starts clean |
| V-11 | Reset is deterministic | Reset one attempt twice and compare row counts and a value checksum with the seed | Identical both times; no residue from the earlier statements |
| V-12 | Error responses leak nothing | Force syntax error, denied privilege, timeout, row cap, connection limit, and engine down | Each returns the documented code and message; no credential, DSN, stack trace, path, host, account name, or other attempt's data in body or headers |
| V-13 | Concurrent attempts do not interfere | Run two attempts in parallel with different DML on the same seed; then clean up one | No cross-visibility, no mixed results, no deadlock caused by shared objects; the surviving attempt still works |
| V-14 | One attempt cannot run twice at once | Send two executions for one attempt in parallel | The second is refused or queued by policy; only one statement runs; no interleaved transaction |
| V-15 | Unauthorized execution runs no SQL | Execute without a token, with another student's token, and for an unknown attempt | `401`/`403`/`404` per the API contract; the MariaDB `Connections` counter does not rise; no audit row claims execution |
| V-16 | Statement policy and client capabilities | Send an over-limit statement; send `SELECT 1; DROP TABLE ...`; attempt `LOAD DATA LOCAL INFILE`; list routines, triggers, and events | The over-limit statement is rejected before connecting; the multi-statement call fails and the DDL does not run; the local-file read is refused; no definer-rights object exists in the scope |
| V-17 | Audit completeness and redaction | Trace one success and one timeout from attempt id to audit row | Outcome, duration, rows, and error code present; no secret, token, or personal seed value anywhere in the record |
| V-18 | Engine failure path | Stop and restart MariaDB mid-attempt | The API returns the documented controlled error; the attempt stays usable; cleanup or reset runs on recovery |

## Examples

The examples are shapes, not a specification. Names, limits, and schema patterns come from the approved design.

### Example 1: negative test for cross-attempt access

Scenario: two attempts exist, A and B, each with its own scope and seed. Student A owns A. The test proves that A's SQL cannot reach B, by read or by write, and that A's own scope still works.

```text
Arrange  two attempts with separate scopes and synthetic seed data
Act      as A's execution account, in A's scope:
           SELECT * FROM <B scope>.students;
           INSERT INTO <B scope>.students (id) VALUES (1);
           UPDATE <B scope>.students SET id = 2;
           DELETE FROM <B scope>.students;
           DROP TABLE <B scope>.students;
Assert   each statement fails with a database- or table-scope denial;
         no row of B is returned;
         B's row count and objects are unchanged (checked as admin);
         A's own SELECT on its own scope still returns its own seed rows.
```

Express the same case through the real boundary as a Laravel feature or integration test: authorize as A, ask the API for an execution against B's attempt (expect `403` or a `404` identical to an unknown id), and ask for an execution against A's attempt with a statement that names B's scope (expect the documented execution error, not a stack trace). Put the case in the "security violation" row of the test matrix and run it before the success case. The mechanics are in `negative-first-testing` and `integration-testing`; the failing assertion must name the MariaDB denial, not a generic `500`.

### Example 2: bounded execution call with a least-privilege connection

```text
# Pseudocode. Placeholder names; the approved design supplies values.
authorize(user, attempt_id)                      # Laravel policy; 401/403/404 before any connection
attempt = load_attempt(attempt_id)               # server-side row
scope   = resolve_scope(attempt)                 # server-side mapping; never from the request body
require scope.ready and scope.attempt_owner == user.id
require attempt.state allows execution
require length(sql) <= statement_byte_cap
acquire_lease(attempt) or refuse                 # one execution per attempt

conn = connect(execution_dsn, account=execution_account)   # least-privilege account, not the app user
try:
    set_session_bounds(conn, lock_wait_timeout, innodb_lock_wait_timeout)
    cursor = conn.cursor(multi_statements=False, unbuffered=True)
    cursor.execute(compose_bounded_statement(time_limit_seconds, sql))
    # compose_bounded_statement returns "SET STATEMENT max_statement_time=<n> FOR " + sql
    rows, bytes_read, truncated = read_at_most(cursor, row_cap, byte_cap)
finally:
    cursor.close()
    conn.close()

record_audit(attempt, scope, outcome, duration, rows, truncated, error_code)  # redacted, no secret
schedule_cleanup(attempt)                         # idempotent; durable sweep retries orphans
```

Notes that matter more than the syntax:

- `sql` is the student's statement. Composing it into the `SET STATEMENT ... FOR` wrapper is the one place where student text becomes SQL text. That is the product, not an injection defect. The boundary is the scope, the account, and the single-statement policy, not escaping.
- `time_limit_seconds`, `row_cap`, `byte_cap`, and `statement_byte_cap` come from server configuration. A request field that changes a limit is a security defect.
- Prepared-statement binding protects values. It cannot protect a statement text that is intentionally arbitrary. Do not claim parameter binding as the isolation control.
- Disable multi-statement execution in the client and verify it with V-16. Do not rely on splitting the text on semicolons.
- Read with an unbuffered cursor and a byte cap, or an oversized result becomes a PHP memory failure.
- If the timeout fires, the transaction state is unknown. Roll back on the connection, close it, and reset or destroy the scope according to the design; do not reuse the connection for a second statement.
- A cleanup failure blocks the next execution in that attempt. Fail closed, and record the orphan.

## References

- [MariaDB GRANT](https://mariadb.com/docs/server/reference/sql-statements/account-management-sql-statements/grant) — privileges, grant levels, `MAX_USER_CONNECTIONS`, `MAX_STATEMENT_TIME`, and account host matching. Verified 2026-11-19.
- [MariaDB: aborting statements that exceed a time](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/query-optimizations/aborting-statements) — `max_statement_time`, `SET STATEMENT`, per-user limits, and the limits of the abort. Verified 2026-11-19.
- [MariaDB CREATE DATABASE](https://mariadb.com/docs/server/reference/sql-statements/data-definition/create/create-database) — `SCHEMA` is a synonym; no nested namespaces. Verified 2026-11-19.
- [MariaDB LIMIT ROWS EXAMINED](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/query-optimizations/limit-rows-examined) — the row cap and its partial-result warning.
- [OWASP ASVS 5.0.0](https://owasp.org/www-project-application-security-verification-standard/) — authorization, validation, configuration, data protection, logging, and error handling.
- [OWASP Top 10:2025](https://owasp.org/Top10/) — A01, A02, A05, A06, A09, A10.
- [docs/database-design.md](../../../docs/database-design.md#mariadb-sql_exec) — the two databases, the `TBD` isolation constraints, and the destructive-command warnings.
- [docs/architecture.md](../../../docs/architecture.md) — components, ports, and the boundary that keeps MariaDB out of the request path.
- [docs/development-roadmap.md](../../../docs/development-roadmap.md) — Phase 3 and its exit criteria.
- [docs/api-specification.md](../../../docs/api-specification.md) — status codes and the error shape the execution endpoint must follow when it exists.
- [AGENTS.md](../../../AGENTS.md) — security requirements, TDD, evidence rules, and the approval items.
- `docs/templates/test-matrix.md` — the matrix columns for the verification rows.

## Related skills

| Skill | It owns |
|---|---|
| [mariadb-features](../mariadb-features/SKILL.md) | MariaDB 11.8 capabilities, defaults, and version boundaries. This skill uses only what isolation needs. |
| [mariadb-query-optimization](../mariadb-query-optimization/SKILL.md) | `LIMIT ROWS EXAMINED`, optimizer behavior, and query performance. |
| [mysql-to-mariadb](../mysql-to-mariadb/SKILL.md) | Why MySQL 8.0 and PostgreSQL advice does not transfer, including authentication and connector differences. |
| [laravel-owasp-security](../laravel-owasp-security/SKILL.md) | Application-side authorization, injection defense, logging, and error handling around the execution endpoint. |
| [negative-first-testing](../negative-first-testing/SKILL.md) | The failure order, the failure catalog rows, and the TDD Red evidence for V-01 to V-18. |
| [integration-testing](../integration-testing/SKILL.md) | The real Laravel, PostgreSQL, MariaDB, Sanctum, and frontend boundary setup. |
| [test-driven-development](../test-driven-development/SKILL.md) | The Red-Green-Refactor cycle that every isolation control is built through. |
