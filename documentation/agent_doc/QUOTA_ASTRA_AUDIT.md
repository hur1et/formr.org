# Astra quota security and load audit

Audit date: 17 October 2026. Audited application commit:
`0caf2a43df562892beaf5de452210e9af8c4a002` (`feature/quotas`, official base
`e9fdb410aa2ca157e65fa74a4c37c5f51b40860a`). Application code was not changed.
This audit adds fixtures and reports only. No deployment, push, provider request,
production VM access, or access to the persistent application/database was performed.

## Decision

The quota ledger behaved consistently in the executed adversarial and concurrent tests.
The measured service throughput is substantially above the average needed for 2,000
participations/day. This commit nevertheless needs corrections before production:
existing generic run deletion can lose resources before a new quota foreign key refuses
the deletion, and the API can report a refused structure replacement as successful.
These findings do not require a new participant-deletion or counter-retention workflow.

This is a review of the new modules and their application integration, supported by local
service/database tests. It is not a whole-host penetration test, dependency-advisory scan,
provider certification, or proof of production VM/HTTP/OpenCPU capacity.

Completion note: Astra reached its usage limit before completing the closing report.
The primary agent subsequently consolidated the already-written JSON measurements in
the sections below. These additions are editorial verification of recorded results,
not an additional Astra review or release approval. No application fixes were made.

## Scope and method

- Read `CLAUDE.md`, current quota structure/export/monitor/lifecycle/storage documents,
  and actual services, controllers, models, templates, migrations and tests. Historical
  milestone statuses were not treated as current release evidence.
- Reviewed owner boundaries, source occurrence/item binding, activation, current-unit
  checks, field/test separation, explicit Complete booking, retry/error behavior,
  provider identity/URL handling, public statistics, audited corrections, portable
  import/export, transaction ownership and generic maintenance integration.
- Executed the existing disposable MariaDB suite: **137 tests / 534 assertions**, passed
  in **38.889 s**. Its tests include concurrent writers, rollback after injected faults,
  inactive structure round-trip, source/ownership checks, exports and manual corrections.
- Added and executed independent adversarial cases and native-method reproductions.
  Their fixtures use minimal legacy parent tables and the actual **060–064** patches.
  They are not a migration rehearsal against the current production schema.
- Benchmarked actual `QuotaRuntime`, `QuotaStore`, `QuotaAnswerLoader` and `QuotaMonitor`
  with saved SQL answer rows and independent PHP processes. No remote work is included.
  Loopback database access was explicitly limited to port **13368** and random
  `formr_quota_astra_<16 hex digits>` databases, dropped after each script.

## Confirmed findings

### A1 — P1: failed native run deletion has already removed resources

Locations: `application/Model/Run.php:234` (`delete`), `:250` (`deleteUnits`), `:530`
(`deleteFiles`); `sql/patches/062_quota_lifecycle.sql:21` and `:22` pin run-unit links;
`sql/patches/064_quota_shares.sql:7` restricts deletion of a shared run.

The native delete operation removes physical uploads and their database records first,
then special-unit links, then regular run links, then the run. There is no preflight for
the newly introduced restrictive foreign keys and no transaction protecting the earlier
database operations. An SQL failure is caught and reported after those side effects.

Executed reproduction: `tests/fixtures/quota/astra_delete_refusal.php`. This invokes the
actual `Run::delete`, `deleteFiles` and `deleteUnits` methods, with a thin PDO adapter,
actual quota FKs, and newly created synthetic files in an owned temporary directory.

| Case | Return | Run remains | Regular links remain | Upload remains |
|---|---|---:|---:|---|
| Activated quota | false, SQLSTATE 23000 | 1 | 4 | No |
| Monitor key, no active quota | false | 1 | 0 | No |

In the activated case the special links and uploaded-file records are also gone. In the
monitor-only case the entire regular structure disappears before the run deletion fails.
This is owner-triggered data loss through an existing operation, not an unauthenticated
attack. It also matters to callers that reuse generic run cleanup.

Minimal protective option: detect incompatible quota/share references and refuse **before
any file or link deletion**, preserving the existing run. Cover both active quota and
share-only cases. Do not silently add cascading deletion of completion evidence or invent
a participant-deletion workflow. This is a production release blocker.

### A2 — P2: API reports refused replacement as a successful import

Locations: `application/Model/Run.php:1519` (new saved-quota refusal),
`application/Api/V1/StructureResource.php:66`, `:71`, `:75`, `:83`.

`replaceUnits` correctly returns false when a saved quota must not be replaced.
`StructureResource::importStructure` ignores the return and compares the old unit count
with the submitted count. An old run with five units and a submitted one-unit structure
therefore satisfies the count test and returns success despite importing nothing.

Executed reproduction: `tests/fixtures/quota/astra_api_refusal.php`. It invokes the actual
Run refusal and actual API method, with isolated input/response adapters and an existing
five-unit fixture. Output:

```text
native_replace_result = false
api_response.code = 200
api_response.message = "Run structure imported"
api_response.payload.units_imported = 5
warnings = "Structure replacement is unavailable for a run with saved quotas..."
```

This was a native-method reproduction, not a live authenticated HTTP request. The issue
can mislead an automation or researcher about which study definition is in effect.
Minimal correction: require an explicitly successful replacement result and report refusal
as an error/conflict. Old unit counts must not serve as proof of an import.

The separate inherited transaction limitation also remains relevant: `Run.php:1528–1556`
wraps replacement, but `SurveyStudy.php:446–506` opens/commits through the non-nesting
wrappers in `DB.php:568–590`, and survey result DDL may implicitly commit. A later failure
cannot be assumed to restore the old structure. The quota draft insertion itself preserves
an outer transaction (covered by the existing real-DB test). Full mixed Survey/Quota API
replacement atomicity was **not** proved by that test. Until this path is independently
repaired/rehearsed, exclude destructive API replacement from the initial study workflow;
use an explicitly reviewed import into a new inactive run.

### A3 — P2: no explicit cap on crossed quota groups

Location: `application/Services/QuotaRules.php:26–58`; configuration validation in
`QuotaEditor` limits individual identifiers/limits after generating all combinations.

The accepted group count is the product of category counts. A small bounded proof,
`tests/fixtures/quota/astra_config_growth.php`, supplied 12 dimensions with two categories
each: **4,096 groups were accepted**, with about **6 MiB** additional allocation in that
process. It took 3.679 ms; no huge allocation was attempted. More dimensions grow this
exponentially, and the expansion is repeated during ledger operations under its lock.

This concerns authenticated, owner-authored/imported configuration and accidental resource
exhaustion; participants cannot submit quota definitions through the reviewed runtime.
Use a modest explicit supported limit on tables/dimensions/total generated groups and reject
before expansion. Pending that change, the initial study should use a small reviewed
configuration comparable to the measured six-group fixture. The benchmark does not certify
large cross-products. This is a bounded-scope condition, not evidence of an auth bypass.

## Measured fresh-history load

Environment: Windows, PHP 8.2.34, MariaDB 12.1.2 on local loopback. Each participant
uses actual saved-answer loading, admission through two quota tables and explicit
Complete. Synthetic history/answer seeding and worker startup are outside the timings.
Participants are distributed between field and test cohorts. A writer is a concurrent
PHP process repeatedly executing the service lifecycle, not a complete browser user.

Source: `QUOTA_ASTRA_LOAD_RESULTS.json`, written by Astra's executed harness.
All three phases recorded zero operation errors.

| Writers / monitor readers | Participants | Measured seconds | Admission p95 | Complete p95 | Monitor p95 |
|---|---:|---:|---:|---:|---:|
| 8 / 2 | 800 | 181.036 | 2.092 s | 1.764 s | 2.503 s |
| 16 / 2 | 600 | 123.126 | 2.650 s | 2.479 s | 2.961 s |
| 32 / 4 | 600 | 145.458 | 5.735 s | 5.650 s | 6.222 s |

The phases total 2,000 completions in about 450 seconds of timed service work,
approximately 4.45 participations/second. This is much higher than 2,000/day averaged
over 24 hours (about 0.023/second), but does not certify a production daily capacity.
OpenCPU, HTTP, browser pacing, web-worker limits and the real questionnaire were absent.
At 32 writers the maximum recorded monitor read was 7.617 seconds. Exclusive per-quota
locking, including monitor reads, makes burst latency a material staging-test concern.
No speculative lock/counter redesign has been made from these results.

Integrity checks in the same recorded run passed:

- Exactly 2,000 completions and 4,000 table assignments before the overfill test.
- Field counts: 1,800 completions; test counts: 200, with exact expected group totals.
- 320 additional concurrent retries of the same participation added zero completions.
  Retry-burst p95 was 174.620 ms; no operation errors.
- With one remaining counted place, 32 participants were admitted before any completed.
  All 32 then completed; the resulting overfill of 31 is the agreed no-reservation behavior.
  The next participant was rejected as group_full. Final completion count: 2,032.

## Accumulated-history probe

Source: `QUOTA_ASTRA_HISTORY_RESULTS.json`, written by Astra's executed harness.
Completed history was inserted directly outside measured work. These are short probes
against a populated ledger, not 20,000/50,000 newly exercised end-to-end participations.
Each phase executed 16 new lifecycles and 20 monitor reads.

| Existing completions | Writers / readers | Admission p95 | Complete p95 | Monitor p95 | Errors |
|---|---:|---:|---:|---:|---:|
| 20,000 | 8 / 2 | 1.983 s | 1.830 s | 1.872 s | 0 |
| 50,000 | 16 / 2 | 4.700 s | 4.693 s | 2.608 s | 0 |

Final 50,032 completions and integrity checks passed. The 50,000-history monitor maximum
was 5.099 seconds. The phases use different concurrency, so their difference must not
be attributed to history size alone. A fixed-concurrency comparison and real VM timings
remain future measurements if performance changes are considered.

## Other adversarial checks

Astra reported successful execution of `astra_security.php`: seven cross-owner refusals;
immutable/run-scoped provider IDs; hostile/Unicode values encoded without query injection;
sticky rejection after an answer change; technical holds/recovery; missing-ID completion
recovery without a second booking; field/test separation; screen-out/cron behavior;
wrong/expired current-unit refusal; monitor output allowlist and key revocation.
No successful unauthorized access was demonstrated in these executed cases. This is not
a proof that no other authorization flaw exists.

The API/delete fixtures deliberately reproduce the failures described above and return
success when that old behavior is observed. They are diagnostic reproductions, not tests
asserting the desired fixed behavior. Convert/add regression assertions when fixing them.
The fixture scripts run in separate PHP processes and must not be included in the live app.

## Before release

1. Correct A1 and A2 with targeted regression tests, then have their fixes reviewed.
2. Agree the supported configuration-size boundary for A3 before adding restrictions.
3. Rehearse migration against a fresh production copy, including actual schema differences,
   files, encryption keys and the official OAuth credential changes.
4. Exercise HTTP/PHP/OpenCPU on an isolated VM copy, with realistic arrival bursts and
   the actual questionnaire; choose acceptable timeouts/response times from that evidence.
5. Complete the user's text review and HiWi/provider study acceptance, then present a
   concrete deployment/rollback procedure. No production release is approved by this audit.
