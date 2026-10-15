# Quota architecture — M1 design draft

Date: 2026-10-14. Base: e9fdb410aa2ca157e65fa74a4c37c5f51b40860a.
This document describes a proposed implementation, not a shipped feature.

## Accepted scope

- Native Quota and Complete run units, independent of the AI extension.
- Multiple tables per quota module; cross selected categories within a table.
- Admit only if every table maps to an open group after saved demographics.
- Count explicit completions, not generic run endings or admissions.
- No reservations. Admitted participants may finish after a limit is reached.
- Preserve admitted group assignments through completion and export.
- Derive counts from completion records plus audited corrections. No materialized counter.
- Integer ranges only in v1, inclusive boundaries and no implicit rounding (confirmed).
- Separate test completion counts can fill test quotas (confirmed 2026-10-14).
- Test exits display a redirect preview only; no automatic provider request.
- Separate technical failure from quota-full and screening outcomes.
- Complete must be locally idempotent and recoverable before any provider redirect.

## Data flow

Saved demographics -> group mapping -> all-table eligibility -> saved admission
-> main survey and quality filters -> unique completion -> provider redirect.

Corrections affect the effective count; they never rewrite answers or completion records.
Provider identity is received from the provider and remains distinct from the run session ID.
Provider configuration, external monitor access and export are later integration layers.

## Proposed persistence model

Names below describe the target design. The core subset is now implemented by patch 060;
see QUOTA_STORAGE.md for exact boundaries. Run-unit attachment, lifecycle status, labels
and definition editing/versioning in this table are not implemented yet.

| Relation | Main fields | Integrity rule |
|---|---|---|
| survey_quotas | id, run_id, run_unit_id, definition version, lifecycle status | One configured quota at the intended run position; validate run ownership |
| survey_quota_tables | id, quota_id, stable export key, label, validated definition | Unique export key within quota; structure immutable after activation |
| survey_quota_groups | id, table_id, stable code, category combination, limit | Unique code and combination within table; nonnegative limit |
| survey_quota_decisions | id, quota_id, run_session_id, outcome, reason, decided_at, test cohort | One persisted decision per quota and participation; same-run ownership |
| survey_quota_assignments | decision_id, table_id, group_id | At most one group per table; group belongs to that table and quota |
| survey_quota_completions | decision_id, completed_at, complete unit session reference | Unique decision_id; only a fully assigned admission may complete |
| survey_quota_adjustments | id, group_id, signed delta, actor, reason, created_at, operation key | Audited append-only corrections; retried requests cannot duplicate corrections |
| survey_quota_limit_changes | id, group_id, old_limit, new_limit, actor, reason, created_at | Limit update and audit record written together |

Use relational keys for participant/group links, not JSON for these integrity-critical links.
A validated JSON definition per quota table is a proposed way to store category/range settings;
separate feature/category tables are an alternative if relational validation warrants them.
Do not freeze SQL column types, foreign keys or migration numbering until these are reviewed.

The unique completion references the saved assignment set. Counting completions joined to
assignments counts all the admitted groups without maintaining individual group counters.
Do not multiply count rows by joining both adjustment and completion detail in one aggregate:
aggregate those independently, then combine their group totals.

Scope every eligibility/count query to the test or field cohort, including adjustment totals.
Store the decision cohort explicitly; completion counts inherit it from that decision.
Adjustments must also carry a cohort. No test completions or corrections may affect field
counts, and vice versa. The provider monitor always uses field counts. Treatment of later
changes to the existing RunSession testing flag and test reset behavior remains to be agreed;
do not implement silent migration of existing quota records between cohorts.

## Admission transaction contract

1. Resolve the run participation and quota ownership from trusted server-side objects.
2. Check for an existing decision before creating another one.
3. Read the pinned quota definition and the appropriate saved demographic response.
4. Map exactly one group per required table; missing response and missing configuration differ.
5. Read a consistent effective count and limit for every candidate group.
6. Save the decision and the complete assignment set in one transaction.
7. Continue only after commit. A rejected decision never authorizes the main survey.

Admission and completion do not reserve capacity. A persisted eligibility rejection is final
for the same participation (confirmed 2026-10-14), including after a limit increase or changed
answers. Reuse the saved outcome/reason and exclusion path. Technical failures must never be
persisted as eligibility rejections and remain retryable. This is not provider-ID deduplication
across different run sessions.

## Completion transaction contract

1. Verify quota, participant and Complete-unit relationships.
2. Lock the relevant participation/decision consistently with other quota writers.
3. If a completion exists, return the saved success without creating another record.
4. Otherwise require admission and exactly one valid assignment per configured table.
5. Insert the unique completion record and commit.
6. Only then resolve and execute the complete redirect.

Do not check group capacity again on completion. Raising or lowering a limit cannot revoke
an admitted participation. Technical failure leaves the completion retryable.
The core lock order and transaction ownership have been tested against MariaDB. Integration
with RunSession lifecycle transactions still requires separate verification.

Inspection finding: DB::beginTransaction silently does nothing inside an existing transaction,
but DB::commit commits that entire transaction. It does not implement nesting or savepoints.
Quota code must define transaction ownership explicitly, rather than silently committing work
started by its caller. Test both standalone invocation and the integrated run lifecycle.

## Existing integration points and hazards

- RunUnitFactory::SupportedUnits in application/Model/RunUnit/RunUnit.php registers run types.
- RunSession::executeUnitSession moves on before returning non-Survey redirects.
  Preserve a retry path even when the completion has already been persisted.
- RunSession::end is used outside successful completion. Never use ended as quota success.
- SurveyStudy::getItemsWithChoices and getItemsInResultsTable expose metadata and stored names.
- SurveyStudy::getResults links result rows to run sessions but treats unlinked surveys separately.
  Never restore identifying links to unlinked exports as an accidental quota feature.
- Run exports, individual survey exports and survey-file ZIP exports have separate paths.
- RunSession testing status can change. Define stable test-cohort semantics before counting.
- Existing deletion paths can remove run sessions. Decide explicit quota cleanup and correction
  behavior before adding cascade constraints; no orphaned or silently changed counts.

## Required acceptance scenarios

| Scenario | Expected result |
|---|---|
| One of two groups full | Rejected; no partial admission |
| Both open | Saved admission and two assignments, zero completions |
| Complete | One completion counted in each assigned group |
| Concurrent duplicate Complete | Exactly one completion |
| Invalid/incomplete assignments | No completion or success redirect |
| Failure before commit | No success and no partial state; retry possible |
| Commit succeeds but response is lost | Retry returns existing completion |
| Limit lowered after admission | Completion still accepted |
| Two admissions with one remaining place | Both may complete; overfill visible |
| Corrections submitted concurrently/retried | No duplicate operation or negative effective total |
| Generic run end or quality exclusion | No quota completion |
| Rejected participation reopens after limit increase | Same rejection; no admission or completion |
| Missing-answer rejection followed by changed answers | Same rejection for the same participation |
| Technical admission failure followed by retry | Evaluate again; no permanent eligibility rejection |
| Export | One output row per original result row; no answer overwritten |
| Test group filled by test completions | Next matching test admission rejected; field count unchanged |
| Field group full but test group open | Test admission uses test count; field admission still rejected |
| Duplicate test Complete | One test completion; zero field completions |
| Test complete/quota_full/screen_out exit | Display preview without automatic provider redirect |
| Provider monitor after test completions | Field-only totals remain unchanged |

## Pending scope decisions

Activation lifecycle, later testing-flag changes and test reset; completion without
provider; provider profiles/exit paths; unlinked surveys and export scope; deletion behavior.
These must not be silently selected by an implementation.

## Implementation status

Isolated feature/quotas checkout created from the official base with no AI working files.
The first pure rule evaluator is implemented as QuotaRules with standalone PHPUnit tests.
See QUOTA_RULES.md for its input contract and limitations. On 2026-10-15, QuotaStore and
additive patch 060 implement persisted definitions, decisions, assignments, completions,
corrections and limit audits. See QUOTA_STORAGE.md for transaction rules and remaining gaps.
Local tests: 53 rule tests / 106 assertions and 26 MariaDB tests / 110 assertions, including
real concurrent writers. This is not connected to run units or deployed. Full schema migration,
lifecycle, deletion, provider and browser acceptance remain separate integration work.
