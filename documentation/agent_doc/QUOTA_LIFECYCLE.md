# Quota activation, participant execution and provider exits

Development milestone, updated 2026-10-18. Not a field release.

Complete now supports editable feedback, a configurable provider-return countdown
(default 10 seconds) and an individual fallback link. Apply patches 066–067 in addition
to the earlier quota patches. See [QUOTA_COMPLETION_PAGE.md](QUOTA_COMPLETION_PAGE.md)
for storage, transfer, Stop Point audit and targeted/browser verification.

## Activation

Save the Quota draft, then use **Activate quotas and completion** in Complete.
Complete defaults to **Without a panel provider** and shows the return-link fields
only when **With a panel provider** is selected. The button saves these settings
and asks for confirmation before jointly activating both modules. Quota itself
has only Save. Unsaved quota edits must be saved before activation.
Activation is an owner-only transaction locking the run. It revalidates
saved survey bindings and requires exactly one Quota followed by one Complete,
with unique run positions. Result columns must belong to an unambiguous base table;
views and shared result-table metadata cannot be activated.

When Publicness is changed to 1, 2 or 3, Run::togglePublic checks any Quota run
through QuotaPublication. Exactly one Quota followed by one Complete, their shared
activation and valid pinned completion settings are required. Rejection preserves
the stored and displayed visibility. Messages appear only in the administrative
editor (or the authenticated management API), never on participant pages. Making
a run private remains possible; runs without Quota keep their existing behavior.

The transaction creates the counting definition and pins both run-unit identities
and the provider settings together. A failed write rolls everything back, preserving
the drafts. Repeating the activation service returns the existing identity. An old
editor submission after activation can report that the definition is already fixed;
reload the editor to see the active state. It cannot create a second definition.

Active category controls are read-only. The Quotas menu now provides audited limit/count
changes; see QUOTA_OVERVIEW.md for all-table saves and concurrent completion handling.
Provider settings are locked along with the definition until the owner explicitly
chooses Reopen quota configuration in Complete and acknowledges the warning.

## Reopening (2026-10-18)

Patch 065 adds an editing flag, active/retired table and group markers, a table-code
snapshot for each admission, and an owner/time/configuration audit log. Reopening
copies the current rules and provider profile into the drafts. It leaves the live
configuration, visibility, counts, participant decisions and responses untouched.
Repeated reopening does not overwrite an already edited draft.

Reactivation locks the run and quota, validates the drafts, and updates the live
configuration atomically. Matching table/group codes reuse their ledger identities
and counts (including codes that had been retired); entirely new codes start at
zero. No earlier admission is re-evaluated or retroactively assigned to new tables.
Removed groups/tables are marked inactive, never deleted: admitted participants
still complete against their original assignments and table-code snapshot. Failed
reactivation leaves the old live rules in force and the drafts editable. History
records actor, time and before/after configurations, including provider settings.

The export keeps retired table assignments. The CSV codebook recovers retired group
meanings from the configuration log. For reused codes whose variables or ranges
change, the current meaning is shown; the warning explicitly requires researcher
review of retained counts. This is deliberate user-approved behavior, not automatic
reclassification. No response deletion or counter reset is introduced.

## Participant lifecycle

QuotaRuntime validates the actual current unit session, run, run-unit identity, type,
test status and Quota/Complete order. A saved admission advances to the next unit.
Every table must admit. A saved rejection ends at Quota with quota_full. Missing or
unmatched answers retain their distinct internal reasons in the quota ledger.
Technical failures hold the current position and do not become quota_full/admission.

Only the pinned Complete records completion, and only after saved admission. Stored
group assignments are reused. Completion does not recheck capacity, so previously
admitted participants can overfill a group. The completion evidence is unique.
An already-ended participation cannot create its first completion.

Booking happens before resolving the return link. With a missing provider identity,
the completion remains booked once, while the unit/run remain unfinished and retryable.
Supplying the identity on a later request allows the same completion to finish without
another count. A technical failure in terminal writes similarly permits a retry.

The run and current unit end in one transaction. Their current position remains intact.
RunSession handles the explicit quota_terminal result before its ordinary redirect
branch, which would advance a non-Survey unit. Reloading an ended participation can
therefore retry the same exit. Local booking is once-only; the provider's processing
of repeated browser redirects cannot be guaranteed once-only without its own protocol.

An existing Page can explicitly select the screen_out exit. It must be before Complete
and have provider_exit=screen_out in storage. Ordinary Pages keep existing behavior.
Screen-out records no completion. No new participant deletion workflow is introduced.

## Provider identity and URLs

QuotaProviderConfig validates an explicit incoming query parameter, exact placeholder
and three complete HTTP(S) return URL templates. The placeholder must occur in query
values, not in the host, path, fragment or parameter names. No provider format is
inferred. Fixed URL parts, including study identifiers, are preserved verbatim.

Run captures the supplied identity before executing the participant's survey. Missing
identity does not block entry. A supplied value is stored separately from the formr
session code and cannot change during that participation. Invalid/changed values
produce a retryable technical hold, with no fabricated replacement identity.
PHP has decoded the query value; rawurlencode encodes it once when replacing the
placeholder. A literal percent sequence remains literal after the return link is decoded.

Without a provider, terminal exits display a local end page. With a provider, field
participations return a browser redirect. Test participations only show the resolved
URL as escaped plain text, without a clickable link or real callback. Test completions
affect only the test cohort. Cron execution waits for the participant and cannot book.
Logs and debug output added here omit provider identities and complete return URLs.

Patch 062 adds exit drafts, pinned lifecycle settings and captured identities, plus the
Page exit selector. Apply patches 060–062 before using this code, including on runs
without quotas: Run's entry hook queries the lifecycle table. These development patch
numbers must be coordinated with upstream before submission/deployment.

## Verification and remaining release work

- Standard SQLite unit lane: user-run outside the Windows agent sandbox,
  253 tests / 617 assertions passed, 11 PHPUnit deprecations, including the overview. This includes the 76
  focused rules/provider/run-unit tests, so those must not be counted a second time.
- Focused standalone PHP lane: 76 tests / 178 assertions passed.
- Disposable MariaDB lane: 112 tests / 421 assertions passed. Includes overview saves,
  concurrent corrections/completions and rollback, as well as native legacy
  import metadata and Windows table-name/ownership handling as well as activation
  rollback, ownership/order/source failures, saved admissions/completions, all exits,
  missing identity recovery, injected terminal write failures and concurrent writers.
- DOM editor lane: 12 tests passed, including read-only activated controls.
- PHP syntax check: all 17 changed/new PHP files passed.
- Production Webpack build passed, with three existing bundle-size warnings and an
  older Browserslist-data notice. No dependency upgrades were made for these notices.

The MariaDB fixtures use minimal parent-table schemas and synthetic data. Separately,
the full local baseline plus migrations was initialized, and native survey imports,
login and authenticated editor rendering were checked on Windows. This is not an
upgrade rehearsal against the VM database. The browser
fixture exercises the real editor template/JS, but has no authenticated backend and
its activation button is deliberately disabled. Earlier browser acceptance concerns
draft editing only, not this full participant lifecycle or provider settings panel.

Researcher overview and audited correction/limit controls are implemented and checked;
patch 063 is required for active saves. The revocable read-only provider monitor is
also implemented and checked (patch 064; see QUOTA_MONITOR.md). Apply 060-064 for the
current Quotas page. Survey response downloads and a CSV quota codebook are implemented
and checked; see QUOTA_EXPORT.md (265 standard tests / 678 assertions, 130 MariaDB
tests / 509 assertions). Internal/API readers and the long Run Data export are unchanged.
Portable run import/export is now implemented; see QUOTA_STRUCTURE.md. Definitions and
current limits transfer as inactive drafts; panel-provider profiles and counting history do not.
Still required:
production-copy migration rehearsal and OpenCPU-backed participant browser runs; provider-approved test links;
pilot/retry/failure checks and a reviewed release/rollback procedure. Test reset/status
changes and provider changes after activation need decisions before exposing them.
The production VM and AI integration have not been changed.

Authenticated local participant acceptance now covers admission, completion, overfill,
missing/unmatched answers, independent tables, abandonment, cohort separation, source
failure/recovery and all three synthetic provider exits. A native session-resume defect
was fixed and regression-tested. See QUOTA_BROWSER_ACCEPTANCE.md for evidence and limits;
this does not replace R-filter, real-provider or deployment-level acceptance.
