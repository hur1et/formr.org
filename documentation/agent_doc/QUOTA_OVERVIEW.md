# Researcher quota overview

2026-10-15. Development feature on `feature/quotas`; no production deployment.

## Access and interface

The run menu always offers **Quotas**, under Testing & Management. The route is
`/admin/run/<run-name>/quotas/`. The run owner can open it before activation or
without a Quota unit. Without saved groups it links to Edit Run. Saved draft groups
show editable limits and zero counts; viewing or editing limits does not activate them.

The native Bootstrap/AdminLTE page displays each table with Code, Quota group,
Limit, Count and Fulfillment. Variable names, choice labels/codes and integer ranges
identify the groups. Field participants are selected by default; a dropdown selects
test participants. Limits are shared; counts and corrections are cohort-specific.

There is one **Refresh** link and one **Save** button for the whole page. Refresh
loads current database values and discards unsaved edits. There is no polling.
Count corrections require no explanation. A short hint explains that actor, timestamp
and delta are logged automatically. Fulfillment can exceed 100%; its cell uses native
Bootstrap success styling at 100% and above. A zero limit displays an uncolored em dash.
Completed participants counts unique completion
records once, independently of table count or manual corrections.

## Saving concurrent edits

Count changes express a difference from the displayed value. If the page displayed
87 and the researcher enters 85, the correction is -2. If three participants complete
before Save, the store reads 90 under its quota lock and saves an effective count of
88. New completions are retained. This does not reserve places or revoke admissions.

All submitted groups are validated and saved in one transaction. A negative resulting
count, changed category meaning or conflicting edited limit rolls
back the whole submission. Unedited limits do not overwrite newer changes. Active
limit changes and count adjustments record actor and database timestamp. Count edits
store their delta and the automatic origin label `Quota overview` in the existing
reason column; no schema migration or user-supplied explanation is needed.

Patch `063_quota_overview_edits.sql` adds a save receipt keyed by quota and operation
key. Its payload hash rejects changed reuse; an identical retry is a no-op. Receipt,
limits and adjustments commit together. This is a retry receipt, not a CSRF mechanism
or an additional aggregate counter. Apply patches 060-064 for the complete feature;
coordinate these development migration numbers with upstream before release.

Draft saves lock the run row, recheck ownership, activation and category definitions,
and update only changed limits. They create no active quota or completion evidence.
The active Run editor reads updated limits from the same store.

Inputs use text fields with a numeric keyboard hint and strict server-side integer
validation. Native number fields triggered the existing Webshims shadow controls in
browser testing and could submit an older value immediately after editing. No custom
shadow-input synchronization or automatic rounding was added.

## Compact submission follow-up

The user selected compact submission after the usability review found that four named
inputs per group plus page metadata exceeded PHP's default max_input_vars=1000.
500 groups previously produced 2,004 form variables, truncated to 249 complete groups
under that default. The existing full-group check rejected the request without saving.

The form now sends five named fields: cohort, quota_id, definition_hash, operation_key,
and rows_json. The 2,000 displayed/baseline group values use data attributes instead of
individual POST names. On native submit, the admin bundle serializes their current
strings to one JSON value; it never converts them to JavaScript numbers. Table/group
maps have null prototypes. Save is enabled after initialization, with a no-JavaScript
message if scripting is disabled. The page still uses the same native POST and redirect.

The controller decodes that value and retains submitted rows for validation errors.
QuotaOverview and QuotaStore still validate all expected groups, ownership, exact
integers, baseline/conflict rules, correction deltas and retry receipts. Broken/missing
JSON is refused explicitly. Old cached forms receive a reload error instead of falling
back to potentially truncated legacy input. No migration, new counter or PHP setting.

Verification: **289 standard tests / 724 assertions**, **139 disposable MariaDB tests /
545 assertions**, **18 editor/overview JavaScript tests**. Eleven existing PHPUnit
deprecations remain; production build passes with existing size warnings. The new
database test saves 500 groups, retries the same correction without double counting,
and verifies an incomplete subsequent request makes no changes.

Actual local browser: synthetic `Quota_Compact_e5a488`, two tables of 250 groups,
max_input_vars=1000 in the local PHP configuration. The page has 500 rows but only five
named form fields. Native Save records a count correction in first/g1 and a limit change
in second/g250; a reload preserves both. No browser console warnings/errors. Screenshot
is external project output `Quotierung-500-Gruppen-Speicherung.png`. No VM test or full
load rerun was performed. Other usability findings remain proposals for user discussion.

## Verification

- Standard unit suite: user-run normal PowerShell, **253 tests / 617 assertions**,
  with 11 existing PHPUnit deprecations and no failures.
- Disposable MariaDB suite: **112 tests / 421 assertions**, including concurrent
  completion/correction, duplicate saves, rollback, ownership, cohorts, draft changes
  and stale category definitions. This lane is separate from the standard unit suite.
- Real local formr: synthetic `Quota_Overview_Test` run; two completed participants
  displayed, correction entered, third participant completed before saving. Corrected
  group became two, independent education count stayed three, completed total three,
  field count zero. Exactly one -1 adjustment was stored with its reason.
- Earlier acceptance included required reasons; that requirement was removed after
  user clarification. Current regression checks save without reasons, retain automatic
  audit metadata, and roll back the batch on a negative effective count. Refresh
  discards edits. Draft limits saved without activation; no-group access worked.
- Actual viewport widths 375, 768 and 1440 checked. Wide tables scroll within their
  wrappers, without document-level horizontal overflow. No console errors/warnings.
- Production Webpack build passed through its direct Node entry point on Windows,
  with three existing size warnings and an older Browserslist-data notice.

The local overview is at
`http://127.0.0.1:8097/admin/run/Quota_Overview_Test/quotas/?cohort=test`.
Synthetic fixtures are outside the repository. The production VM and AI code are unchanged.
The revocable provider read-only monitor is now implemented; see QUOTA_MONITOR.md.
Survey response downloads and the CSV quota codebook are implemented; see QUOTA_EXPORT.md.
Portable run import/export is implemented; see QUOTA_STRUCTURE.md.
Still pending: production-copy migration rehearsal, OpenCPU and approved provider
acceptance, pilot and release/rollback review. This overview is not field release approval.
