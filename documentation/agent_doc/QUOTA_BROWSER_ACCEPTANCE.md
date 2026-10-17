# Local quota lifecycle acceptance

2026-10-15. Actual formr on Windows, PHP 8.2.34 and MariaDB 12.1.2,
on 127.0.0.1:8097 with a dedicated local database and synthetic data.
This checks the authenticated editor and participant execution, not just the preview.
No production VM or real provider was contacted.

## Fixtures and method

Native Run, SurveyStudy import, Survey, Quota, Complete and Page methods created
separate fixtures. Quotas were activated through the authenticated browser editor.
Browser form submissions provided all participant answers. Local read-only database
checks verified decisions, states, unique completions and separate cohort counts.

- `Quota_E2E_02`: demographic Survey, Quota, main Survey, Complete without provider.
  Age x sex limits are 1 per crossed group; independent education limits are 2 and 5.
- `Quota_E2E_03`: same flow with synthetic provider configuration and three return URLs.
- `Quota_E2E_05`: provider flow with an explicit screen-out Page before Complete.
  This tests the terminal Page, not an R-based conditional quality filter.

The original `Quoten_Demo` was not activated or changed. Fixture setup initially
omitted item ordering and the Page type that the native editor supplies. These fixture
errors were corrected; incomplete/contaminated setup runs 01 and 04 are excluded from
acceptance. They are not evidence of successful participant execution.

## Results

| Case | Observed result |
|---|---|
| Saved demographics match every table | Main Survey reached |
| Two admissions before the first completion, group limit 1 | Both can complete; final group count 2 |
| New participant in that full group | quota_full at Quota, main Survey not reached |
| Missing age, sex or education, tested separately | quota_full; internal missing_answer in the relevant table |
| Age 17 outside configured ranges | quota_full; internal unmatched_answer |
| Available age x sex group but full education target | quota_full in the education table |
| Repeated completion-page loads | No additional completion records |
| Admission at inclusive lower bound 18, then abandon main Survey | Remains unfinished; group count stays 0 |
| Field participation while the corresponding test quota is full | Admitted and counted only in field cohort |
| Source deliberately marked invalid | Technical error at Quota, no admission, rejection or run end |
| Source restored, participant reloads | Main Survey reached; subsequent completion counted once |
| Provider ID with space, plus, ampersand, slash, percent and non-ASCII | Exact identity encoded once in complete return URL |
| Provider quota_full and screen_out exits | Correct outcome and received ID in the corresponding return URL |
| Provider ID missing at Complete | One completion booked, exit remains unfinished; no invented return URL |
| ID supplied later, followed by reload | Correct complete return URL, still one completion |
| Explicit screen-out before Complete | Ends at Page, no completion and no quota consumption |
| Provider test exits | Plain text preview only; no clickable callback and browser remains local |

20 final persisted-state checks passed, including preservation of the raw unmatched age.
In run 02, test counts were 2 for the first
crossed group and 1 for the recovered participant's group; education counts 2 and 1.
The abandoned participant's group remained 0. Field counts were 1 independently.
Provider recovery in run 03 produced exactly two completions for two completing
participants. Screen-out in run 05 produced no completion and all counts stayed 0.

## Resume defect fixed

`RunSession::getCurrentUnitSession()` passed a fetched row into UnitSession's
constructor. That constructor allows only lookup keys and does not load unless
explicitly requested. The resumed object therefore lacked persisted session/run-unit
identity. A newly created unit worked, but retrying a pending Quota failed its identity
checks even after the source fault was repaired.

The caller now passes only the selected row's primary key plus `load=true`, so the
constructor reads the trusted row. Constructor allowlists remain unchanged.
`RunSessionResumeTest` exercises the actual loader for Quota and Complete against an
isolated SQLite database, including exclusion of a newer superseded sibling. Both
cases failed before the fix and passed afterwards (2 tests, 16 assertions).

The user's unchanged standard SQLite lane passed after the fix: **240 tests, 604
assertions, 11 existing PHPUnit deprecations**. Expected logs from invalid OAuth inputs
and deliberately crashing Markdown parsers are test fixtures. The agent's sandbox run
had two CryptoTest file-read failures; the user's normal PowerShell run resolved that
environmental verification gap without modifying Crypto or its tests.

Browser warnings about missing service-worker/VAPID configuration are expected in the
local setup; this was not a PWA test. Screenshots were inspected through the browser;
no saved screenshot artifact is claimed.

## Remaining release checks

- OpenCPU-backed conditional filters and R execution are not installed/tested locally.
- Real provider-approved IDs, links and reimbursement behavior still need a pilot.
- The local PHP development server does not prove multi-worker HTTP behavior. Existing
  disposable MariaDB concurrency tests cover ledger writes; deployment-level load and
  retry checks remain required.
- A production-copy migration rehearsal, complete participant pilot and reviewed
  deployment/rollback procedure remain outstanding.
- Researcher/provider monitors, correction controls, answer exports and portable run
  import/export have since been implemented. See QUOTA_OVERVIEW.md, QUOTA_MONITOR.md,
  QUOTA_EXPORT.md and QUOTA_STRUCTURE.md for the subsequent evidence and remaining limits.
