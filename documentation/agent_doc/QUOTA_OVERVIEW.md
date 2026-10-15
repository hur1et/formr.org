# Researcher quota overview

2026-10-15. Development feature on `feature/quotas`; no production deployment.

## Access and interface

The run menu always offers **Quotas**, under Testing & Management. The route is
`/admin/run/<run-name>/quotas/`. The run owner can open it before activation or
without a Quota unit. Without saved groups it links to Edit Run. Saved draft groups
show editable limits and zero counts; viewing or editing limits does not activate them.

The native Bootstrap/AdminLTE page displays each table with Code, Quota group,
Limit, Count and Fulfilment. Variable names, choice labels/codes and integer ranges
identify the groups. Field participants are selected by default; a dropdown selects
test participants. Limits are shared; counts and corrections are cohort-specific.

There is one **Refresh** link and one **Save** button for the whole page. Refresh
loads current database values and discards unsaved edits. There is no polling.
Active count corrections require a reason in the affected row. Fulfilment can exceed
100%; a zero limit displays an em dash. Completed participants counts unique completion
records once, independently of table count or manual corrections.

## Saving concurrent edits

Count changes express a difference from the displayed value. If the page displayed
87 and the researcher enters 85, the correction is -2. If three participants complete
before Save, the store reads 90 under its quota lock and saves an effective count of
88. New completions are retained. This does not reserve places or revoke admissions.

All submitted groups are validated and saved in one transaction. A missing reason,
negative resulting count, changed category meaning or conflicting edited limit rolls
back the whole submission. Unedited limits do not overwrite newer changes. Active
limit changes and count adjustments record actor, reason and database timestamp.

Patch `063_quota_overview_edits.sql` adds a save receipt keyed by quota and operation
key. Its payload hash rejects changed reuse; an identical retry is a no-op. Receipt,
limits and adjustments commit together. This is a retry receipt, not a CSRF mechanism
or an additional aggregate counter. Apply patches 060-063 for the complete feature;
coordinate these development migration numbers with upstream before release.

Draft saves lock the run row, recheck ownership, activation and category definitions,
and update only changed limits. They create no active quota or completion evidence.
The active Run editor reads updated limits from the same store.

Inputs use text fields with a numeric keyboard hint and strict server-side integer
validation. Native number fields triggered the existing Webshims shadow controls in
browser testing and could submit an older value immediately after editing. No custom
shadow-input synchronization or automatic rounding was added.

## Verification

- Standard unit suite: user-run normal PowerShell, **253 tests / 617 assertions**,
  with 11 existing PHPUnit deprecations and no failures.
- Disposable MariaDB suite: **112 tests / 418 assertions**, including concurrent
  completion/correction, duplicate saves, rollback, ownership, cohorts, draft changes
  and stale category definitions. This lane is separate from the standard unit suite.
- Real local formr: synthetic `Quota_Overview_Test` run; two completed participants
  displayed, correction entered, third participant completed before saving. Corrected
  group became two, independent education count stayed three, completed total three,
  field count zero. Exactly one -1 adjustment was stored with its reason.
- Missing-reason submission retained inputs and saved no partial limit changes;
  Refresh discarded edits. Draft limits saved without activation; no-group access worked.
- Actual viewport widths 375, 768 and 1440 checked. Wide tables scroll within their
  wrappers, without document-level horizontal overflow. No console errors/warnings.
- Production Webpack build passed through its direct Node entry point on Windows,
  with three existing size warnings and an older Browserslist-data notice.

The local overview is at
`http://127.0.0.1:8097/admin/run/Quota_Overview_Test/quotas/?cohort=test`.
Synthetic fixtures are outside the repository. The production VM and AI code are unchanged.
Still pending: revocable provider read-only monitor, response export, portable run
import/export, production-copy migration rehearsal, OpenCPU and approved provider
acceptance, pilot and release/rollback review. This overview is not field release approval.
