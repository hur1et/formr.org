# Quota and Complete editor drafts

2026-10-15. This milestone adds editor entry points, not field activation.

## Scope

Quota and Complete are registered in RunUnitFactory and offered in the run editor.
Both are clearly marked as development drafts. Their participant output holds the
current run position with an unavailable message: no move-on, admission, completion,
run termination or provider redirect is performed. QuotaDraftUnitTest checks this
through the actual UnitSession and RunSession execution methods.

QuotaEditor lists variables in valid, linked surveys before the Quota unit, owned by
the run owner. The catalogue keeps the survey run-unit ID, stored variable name and
readable question label separate. Unsupported types/storage remain visible with a
warning and cannot be selected. The server revalidates posted bindings and category
codes; a browser catalogue is never an authorization source.

The UI supports multiple tables, multiple dimensions in each table, category selection,
inclusive integer ranges and automatic Cartesian products. Limits start at zero.
Missing-answer codes are not preselected. Definitions use the same QuotaRules contract
as the core. Range overlaps, decimals and invalid limits prevent saving. Gaps are
reported after server validation. Labels are rendered through text nodes, not HTML.

Input events update the draft state before any re-render, preventing loss of values
when adding a variable/range. Category codes remain stable across toggles without
overwriting other choices. On structural changes, group meaning is compared using
source/type/value or range: changed/new groups start at zero; unchanged groups retain
their limits. This comparison is local editor state, never a new database counter.

## Persistence and integration boundary

Patch 061 adds survey_quota_drafts, separate from survey_quotas. One draft configuration
per run belongs to a concrete Quota run unit and contains multiple tables. Saving a
draft creates no counting definition, decision, assignment or completion. It requires
the run owner and locks the run during validation/save. An existing counting definition
blocks draft mutation. Invalid changes leave the previous saved draft intact.

The AJAX save path returns validation failures without replacing the user's form.
The editor plugs into the existing run-unit save/re-render code via one hidden JSON
field. No spreadsheet syntax is introduced. Complete currently has an explanatory
panel only; provider settings and booking are not exposed as working controls.

Draft import/export is explicitly rejected: stored run-unit IDs are local identities
and cannot yet be copied into another run safely. Portability must be implemented
before final release rather than silently exporting an incomplete quota definition.
No new participant deletion workflow is introduced.

## Verification

- MariaDB: 70 tests / 266 assertions, including draft round trips, spoofed bindings,
  wrong owners, unsupported variables and failed edits preserving the saved draft.
- Pure rules: 53 tests / 106 assertions.
- Run-unit holding/export guards: 4 tests / 10 assertions.
- DOM editor: 11 Node tests via `npm run test:quota-editor`. linkedom 0.18.12 is a
  development-only dependency, not a runtime dependency. Run with Node 22 or newer.
- PHP syntax: 205 files checked, plus the final changed Complete file checked again.
- Production Webpack build passed; existing bundle-size warnings remain.

Browser fixture: `php -S 127.0.0.1:8096 tests/fixtures/quota/editor_preview.php`.
It uses the actual PHP editor template and JS module with synthetic variables only.
Save/reload uses sessionStorage in that fixture; actual MariaDB save/load is exercised
separately by the integration tests. The fixture is not an authenticated full app.

The initial sandbox-started HTTP server was unreachable from the app browser. The user
started the same server in ordinary PowerShell, then a fresh in-app browser tab worked.
Browser acceptance covered age 18–29 / 30–39 crossed with two sex codes, a separate
education table, limits retained after save/reload, overlap/negative-limit rejection,
and an unsupported free-text variable. No browser console errors/warnings were present.
A narrow viewport had no page-level horizontal overflow (360px content width).

## Required next integration

Bind activation atomically to the Quota run-unit definition; validate every source and
run position at activation. Connect saved admission, explicit completion and retry-safe
terminal exits. Add configurable provider identity/links, field monitor and exports.
Exercise the real authenticated editor and full upgraded schema before field release.
The prior full-suite bootstrap limitation in this Windows environment remains open.
