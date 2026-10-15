# English quota interface and native formr styling

2026-10-15. Interface refinement after lifecycle commit e849231e; development version.

The quota editor uses the existing Bootstrap/AdminLTE styling and native run-unit
wrapper. Tables and variables are separated by ordinary form headings and rules,
instead of nested panels/wells. Limits use compact numeric fields with accessible
labels, without repeating their labels visibly in each row. Removal actions remain
secondary to adding variables/ranges and saving. Scoped CSS supports the actual inline
run form and narrow screens without hiding position/removal controls.

All authored quota/Complete interface text is English: labels, instructions, activation,
validation failures, provider settings, participant end/error messages, and save states.
The added end-page controls follow the existing English interface. Survey-authored variable names, question/choice labels,
provider placeholders, URL parameters, stored outcome/group codes and technical logs
are not automatically translated. Existing IDs and data contracts stay unchanged.

These are interface strings, not a language-switching feature. Quota and Complete now
have native accordion entries under Module explanations, covering placement, crossed
and independent tables, completion-only counting, possible overfill and provider exits.
Conformity with existing visual patterns does not imply maintainer approval.

The preview at 127.0.0.1:8096 now loads the actual unit.php wrapper and actual formr CSS,
fonts and icons. Its three links show Quota, Complete and the existing end-page template.
All data are synthetic. Preview saves use sessionStorage; activation, position changes,
removal and participant execution remain unavailable. No authenticated backend is
simulated. The production VM has not been changed.

Verification after this refinement:

- 12 editor DOM tests passed, including save validation, input retention, stable codes,
  reset of limits when group meanings change and read-only active controls.
- 76 focused PHP tests / 178 assertions passed (rules/provider config/run-unit dispatch).
- 95 disposable MariaDB tests / 366 assertions passed, including legacy import metadata,
  Windows table-name handling and shared-table ownership ambiguity.
- Changed PHP files passed syntax checks. Production Webpack build passed with the
  existing three bundle-size warnings and an older Browserslist-data notice.
- Real browser: age 18–29 / 30–39 crossed with two sex codes, independent education,
  six limits retained after save/reload; overlap prevented saving. Complete and end-page
  controls inspected. The authenticated local application now renders English controls,
  both saved quota tables and both expandable module explanations. No new console errors
  or warnings after the Windows build fix; earlier bundle errors remain in tab history.
- Responsive checks requested 375, 768 and 1440 pixels; observed content widths were
  360, 753 and 1440, with no document-level horizontal overflow. Viewport reset afterwards.

The user's latest standard-suite pass including the overview is 253
tests / 617 assertions, with 11 existing PHPUnit deprecations. The agent sandbox
has separate file-read probe limitations; no application loader/Crypto changes were made.
The full local schema with migrations and synthetic surveys is available on port 8097;
login and the authenticated editor were checked. Participant lifecycle checks are
documented in QUOTA_BROWSER_ACCEPTANCE.md. OpenCPU and real provider/pilot acceptance
remain pending.

Screenshots were inspected through the browser tool. Saving a screenshot to the host
output directory was denied (EPERM), so no local screenshot artifact is claimed.

The researcher overview is now implemented and browser-checked in the authenticated
application. It offers all-table Save and Refresh, cohort selection, draft limits and
audited count corrections that retain concurrent completions. Its separate MariaDB
lane passes 112 tests / 421 assertions. Corrections need no explanation input; actor,
timestamp and delta are recorded automatically. Fulfillment cells turn green at 100%
and above. See [QUOTA_OVERVIEW.md](QUOTA_OVERVIEW.md)
for behavior, migration 063 and acceptance evidence.
