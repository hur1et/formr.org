# Survey answer exports with quota metadata

2026-10-17. Implemented on feature/quotas; no production deployment or schema change.

## Agreed structure and scope

The user selected administrative fields, then quota fields, then existing survey items.
The change applies to the normal **Survey / Export Results** download in all existing
formats: CSV, German CSV, TSV, XLS, XLSX and JSON. The six format writers already use
associative field names/order, not a hardcoded first item index. Fixed spreadsheet-cell
positions elsewhere concern question-definition exports, not response columns.

AdminSurveyController opts into the new sixth `getResults` argument, `includeQuota`.
Its default is false: internal R readers, backupResults, existing API consumers and the
on-screen result table retain their previous schemas. The separate long Run Data and
user-overview downloads are not changed. Extending these is not claimed by this feature.
Unlinked surveys are explicitly excluded; hidden-result restrictions still apply.
Saved drafts without an active quota definition produce no additional response columns.

## Columns and meanings

- `quota_status`: saved `admitted` or `rejected`; blank when no decision was saved.
  A rejection corresponds to the quota_full exit, including missing/unmatched values.
- `quota_counted`: 1 if a quota completion exists, 0 if the applicable active quota has
  no completion, blank for a row outside the authorized active-quota run scope.
  This is completion evidence; anonymous manual group-total corrections do not identify
  or reverse an individual's completion and therefore do not change this flag.
- `quota_<table_code>_group`: saved group code for each actual quota table. There is
  no hardcoded age, sex, education or other substantive list. Hyphens become underscores.

Existing result columns plus `session` are reserved case-insensitively. Conflicting new
names receive `_2`, `_3`, etc. in stable run-ID/table-ID order, including collisions
between normalized table codes or identical codes in different runs. Existing names
and values are never renamed or replaced. This same plan drives the codebook. Names
depend on the survey schema and relevant active definitions, not on the current rows.
Changing that schema or adding another relevant active run can change collision suffixes;
the exported codebook states the applicable mapping.

Repeated Survey visits are linked through the actual unit session's run_session_id,
never by a panel ID, visible item label or shared participant-code string. A saved run
decision therefore appears consistently on every answer row for that participation,
even when later answer values differ. No admission is recomputed during export.

QuotaExport uses scalar subqueries with the ledger's unique decision/completion/
assignment constraints, so it cannot multiply the outer answer rows. Discovery covers
configured Survey units and historic unit sessions after removal of the Survey unit.
Run and survey ownership are checked; another run owner's quota metadata is omitted.
If a survey is shared across authorized runs, separate group columns are generated and
each row fills only its applicable run's columns. No synthetic answer rows are created.

## CSV codebook

Since 18 October 2026, Run / Logs offers **Download quota codebook (CSV)** for that
run only. It includes a `survey` column because one run may use several surveys.
Survey / Export Results calls its existing download **Download quota codebook for
all runs (CSV)**. Both retain the actual response export's collision-resolved column
names, including suffixes when surveys are shared. These files explain the response
values; they are not a download of current aggregate limits/counts.

The user selected a separate CSV file, available under **Export Results / Download
quota codebook (CSV)** in the survey menu and the existing download dialog. Its fields
are `column`, `run`, `quota_table`, `value`, `meaning`. It documents actual collision-
resolved names, statuses, completion flags, saved category codes and integer ranges.
Current choice labels are included where available, with original answer codes retained;
missing source metadata falls back to the saved code/range. Labels are descriptive,
while the saved definitions and assignments determine the exported values.

Normal legacy response exports remain unchanged when no relevant active quota exists.
No codebook link appears then. The endpoint reuses survey authorization/hidden-result
rules, returns 404 when no book is available, and stores no provider URLs or sharing keys.

## Verification

- Standard suite, normal local runner outside the restricted socket/file sandbox:
  **265 tests / 678 assertions** passed, 11 existing PHPUnit deprecations.
- Disposable MariaDB: **130 tests / 509 assertions** passed. Export cases include
  admitted/completed, rejected, not yet checked, repeated visits with changed answers,
  identical participant codes, shared surveys/runs, ownership, removed Survey units,
  column collisions, codebook mappings, unlinked/no-quota boundaries and manual totals.
- Six format round trips use the existing actual download writers in isolated processes;
  files are read back and headers, two answer rows, nulls, numeric values, Unicode,
  punctuation and a leading-zero text value are compared. Included in the standard suite.
- Authenticated local browser: synthetic E2E_Demographics_05, native export dialog,
  CSV answer download and CSV codebook download. On-screen result schema stayed unchanged.
  The downloaded answer file matched the actual model query exactly: one original row,
  all original fields/values unchanged, quota fields at positions 9-12 before age/sex/
  education. Codebook had 19 entries and no references to absent export columns.
- Console warnings/errors: none. Export dialog screenshot saved outside Git at
  outputs/Quotierung-Exportdialog.png. No study configuration or participant data changed.

Portable quota Run import/export is implemented; see QUOTA_STRUCTURE.md. Optional
API/long-answer-export coverage decisions, production-copy migration, OpenCPU/provider
acceptance, pilot and release/rollback remain.
