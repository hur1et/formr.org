# German quota interface and native formr styling

2026-10-15. Interface refinement after lifecycle commit e849231e; development version.

The quota editor uses the existing Bootstrap/AdminLTE styling and native run-unit
wrapper. Tables and variables are separated by ordinary form headings and rules,
instead of nested panels/wells. Limits use compact numeric fields with accessible
labels, without repeating their labels visibly in each row. Removal actions remain
secondary to adding variables/ranges and saving. Scoped CSS supports the actual inline
run form and narrow screens without hiding position/removal controls.

All authored quota/Complete interface text is German: labels, instructions, activation,
validation failures, provider settings, participant end/error messages, and save states.
The added end-page controls are German too. The rest of the existing formr application
has not been translated. Survey-authored variable names, question/choice labels,
provider placeholders, URL parameters, stored outcome/group codes and technical logs
are not automatically translated. Existing IDs and data contracts stay unchanged.

These are German strings, matching the requested local interface, not a completed
language-switching feature. Before upstream submission, discuss locale conventions
with the maintainer; conformity with existing visual patterns does not imply approval.

The preview at 127.0.0.1:8096 now loads the actual unit.php wrapper and actual formr CSS,
fonts and icons. Its three links show Quota, Complete and the existing end-page template.
All data are synthetic. Preview saves use sessionStorage; activation, position changes,
removal and participant execution remain unavailable. No authenticated backend is
simulated. The production VM has not been changed.

Verification after this refinement:

- 12 editor DOM tests passed, including save validation, input retention, stable codes,
  reset of limits when group meanings change and read-only active controls.
- 76 focused PHP tests / 178 assertions passed (rules/provider config/run-unit dispatch).
- 92 disposable MariaDB tests / 360 assertions passed after translated server messages.
- Changed PHP files passed syntax checks. Production Webpack build passed with the
  existing three bundle-size warnings and an older Browserslist-data notice.
- Real browser: age 18–29 / 30–39 crossed with two sex codes, independent education,
  six limits retained after save/reload; overlap prevented saving with German feedback.
  Complete and end-page controls inspected in German. No console errors/warnings.
- Responsive checks requested 375, 768 and 1440 pixels; observed content widths were
  360, 753 and 1440, with no document-level horizontal overflow. Viewport reset afterwards.

The user's 238-test standard-suite pass predates this interface refinement; it must
not be represented as a fresh full-suite run after these edits. The agent sandbox
still prevents the original autoloader from reading its file-access status correctly.
The focused tests above do not replace the pending full authenticated browser run,
full-schema migration and provider/pilot acceptance described in QUOTA_LIFECYCLE.md.

Screenshots were inspected through the browser tool. Saving a screenshot to the host
output directory was denied (EPERM), so no local screenshot artifact is claimed.
