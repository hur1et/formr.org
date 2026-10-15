# QuotaStore: transactional persistence core

2026-10-15. Internal service, not a deployable Quota/Complete feature yet.
Based on official commit e9fdb410 and the contract in QUOTA_RULES.md.

## Implemented boundary

Patch 060 creates eight additive quota tables. It does not change historic answers.
One definition per run contains multiple tables and their generated groups. Definitions
are validated on creation and again when read; limits can change through an audited method.
Definition editing, activation, run-unit attachment and display labels are not implemented.
Coordinate migration number 060 with upstream before merging.

QuotaStore accepts an exception-mode MariaDB PDO connection. Callers must authenticate
and authorize access, supply trusted run/session identities and map errors to a retryable
technical failure. The service additionally checks run ownership for administrative writes
and run membership for participation. It is not an HTTP authorization boundary.

| Method | Contract |
|---|---|
| create | Validate and store a definition for a run owned by the actor; cannot replace an existing definition |
| admit | Read saved answers through a trusted DB-only callback and atomically save decision plus all assignments |
| complete | Require an admitted participation and its Complete unit session; record at most one completion |
| adjust | Append a signed correction with actor, reason and retry key; effective count cannot become negative |
| setLimit | Update limit and append old/new limit audit record atomically |
| getCounts | Read one consistent test or field snapshot |

Repeated admission reuses the saved outcome without loading answers again. Rejected
participations stay rejected after a limit increase or changed answers. Repeated completion
does not add a count. A completion uses the saved assignments without checking capacity again.
Admitted participants may exceed a later/full limit; there are no reservations.

## Transaction and counting rules

Each operation owns a short transaction. Caller-owned transactions are rejected before any
work, rather than committed or rolled back. All quota writers first lock the quota row;
admission/completion then lock the participation. READ COMMITTED ensures count reads see
committed changes after waiting for the quota lock. The run adapter must respect this order
and must invoke the service outside another transaction.

Decision and assignment insertion is atomic. Completion is uniquely keyed by decision.
Composite foreign keys prevent assigning a group from another quota/table. Corrections and
limit changes use unique operation keys scoped to the quota within their respective journals.
Same-key, same-payload retries return the existing operation; changed payloads fail. Retrying
an old limit change cannot undo a newer limit change.

Counts are completion totals plus correction totals, aggregated separately to avoid multiplying
rows. There is no materialized counter. Invalid totals and incomplete definitions are errors.
Test/field cohort comes from the saved participation, not the browser. Their completions and
corrections are isolated; configured target limits are shared. A later changed testing flag
currently raises a technical error rather than moving existing records between cohorts.
This is a guard pending the agreed UI/reset policy, not an implemented reset feature.

All exceptions roll back this operation. No provider request, redirect or remote call belongs
inside a transaction. Deadlocks/timeouts propagate as technical failures; callers may retry
the whole operation with the same identity/key. The service does not loop automatically.
The coarse per-quota lock favors clarity; production throughput has not been benchmarked.

## Verification

QuotaStoreTest is opt-in (`integration` group). It creates a random database named
`formr_quota_test_<16 hex digits>`, inserts synthetic parent records, applies the actual patch,
and drops that database afterwards. It never loads the application's configured database.
Use a disposable MariaDB instance/account with database creation and test-trigger privileges.
Concurrent tests spawn two PHP processes, wait until both execute the locking read while the
parent holds the quota lock, release it and verify persisted outcomes.

```sh
export QUOTA_TEST_ALLOW_CREATE_DATABASE=1
export QUOTA_TEST_DSN='mysql:host=127.0.0.1;port=3306;charset=utf8mb4'
export QUOTA_TEST_USER='quota_test'
# Supply QUOTA_TEST_PASSWORD through the test environment, not source control.
php vendor/bin/phpunit --no-configuration --do-not-cache-result tests/QuotaStoreTest.php
```

The PHP CLI needs pdo_mysql, mbstring and proc_open. On 2026-10-15, PHP 8.2.34,
PHPUnit 11.5.6 and MariaDB 12.1.2 passed 26 storage tests / 110 assertions.
Pure rules separately passed 53 tests / 106 assertions. Cases include duplicate concurrent
admission/completion/correction, competing corrections, completion racing a correction,
rollback after injected write failures, cohort isolation, ownership and invalid assignments.
These are core tests using minimal parent fixtures, not a full production migration rehearsal.

## Required before application integration and release

- Resolve actual survey/item/response identities and normalize supported metadata without
  changing raw answers. Never concatenate an untrusted source key into SQL.
- Attach definitions to a Quota unit; add explicit Complete lifecycle/retry behavior.
- Implement activation/editing policy and agree test resets/testing-flag changes.
- Agree deletion/cleanup behavior and integrate every existing deletion path. Internal quota
  foreign keys are restrictive. References to legacy run/unit sessions and actors have no
  foreign keys yet: deployed types differ and deletion semantics are unresolved. The service
  validates ownership, but cannot prevent legacy code from deleting these referenced rows.
- Validate patch against the full upgraded schema and exercise normal application transactions.
- Add administrator UI, provider handling, field-only monitor, exports and browser acceptance.

Do not apply this intermediate patch to a live study or expose the service as a public endpoint.
