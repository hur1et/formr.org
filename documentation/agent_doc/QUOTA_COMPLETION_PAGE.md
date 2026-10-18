# Complete feedback and delayed provider return

18 October 2026. User selected alignment of Stop Point and Complete, retaining both
modules. Complete now precedes Stop in the module-add toolbar. Module explanations,
the run-session missing-ending message and the R-helper description acknowledge both.

## Behaviour

- Complete still requires a passed Quota, records completion once and ends the run.
  The booking happens before the page is delivered, independently of the countdown.
- Editable feedback/debriefing uses the same Markdown/HTML and optional OpenCPU-backed
  body parsing as Stop Point. An OpenCPU error holds the participant before completion.
- With a provider, show the resolved individual complete link and a countdown, then
  navigate to that link. Default wait: 10 seconds; configurable whole seconds, including
  0 for immediate return. Maximum 2,147,483 seconds fits a browser's 32-bit millisecond
  timer. This is presentation timing, not a quota reservation.
- The return message is editable plain text: `{seconds}` inserts the countdown and
  `{link}` inserts the link. Its label is separately editable, allowing translation.
  If the researcher omits `{link}`, the link is appended. Dynamic text and URL attributes
  are escaped; the provider ID is resolved/encoded by the existing provider configuration.
- Without a provider, show the feedback without a countdown or return notice. Test
  participants retain the plain-text return preview with no automatic or clickable
  provider return. Quota-full and screen-out redirects keep their existing behaviour.
- Feedback, notice, link label and delay remain editable after activation. Saving them
  does not reopen quotas or change provider settings. The legacy form serializer also
  submits disabled fieldset values: Complete accepts an identical active provider
  profile during such a save and refuses a changed profile without reopening.
- Browser timing uses elapsed time, avoiding an extended wait after throttled ticks.
  A manual click suppresses a second automatic navigation. If JavaScript fails, the
  native link remains usable. A reload restarts the visible wait but does not rebook.

## Storage and transfer

Patches 066 and 067 add `survey_quota_completion_pages`, keyed by the Complete unit.
Units without a row use the defaults. The table contains only presentation settings,
not participant data or provider profiles. It is separate from frozen quota definitions.
Portable Run JSON carries optional `completion_page` settings. Import validates and
saves them on the new Complete unit; provider profiles remain excluded and require
fresh setup. Older portable definitions without this field use the defaults.
The patch numbers still need coordination with upstream before release.

## Stop audit

Searched application, controllers/API, queue/CLI and templates for Stop/Endpage/Page
references and requirements. No presence check requiring an additional regular Stop
was found. Stop-specific type handling concerns its own output/logging, template choice,
legacy import aliases and queue classification. Complete's quota-terminal path commits
run/unit ending and returns the page without advancing beyond its retry position.
The native no-Stop browser fixture below confirms this path. Special settings pages
(privacy, deactivation, overview scripts) remain distinct from a regular terminal Stop.

## Executed verification

- Targeted SQLite lane: 29 tests / 81 assertions; 11 existing PHPUnit deprecations.
- Targeted disposable MariaDB: 9 tests / 51 assertions, including exactly-once retry,
  missing-ID recovery, test/field separation, ownership, active page edits and exits.
- Countdown JavaScript: 5 tests (default, elapsed-time recovery, manual link, invalid/
  zero delays, unsafe protocols). Production build succeeds with 3 existing size warnings.
- Actual local formr: `Completion_Local_4f337e`, demographic Survey → Quota → Complete,
  no Stop. A synthetic field participant returned after the default ten seconds to a
  local documentation URL with the exact `synthetic_auto` identity. No real provider
  was contacted. Native active-editor save persisted Markdown debriefing, 120 seconds
  and the edited link label; reload confirmed persistence. Another field participant
  saw that page and manually returned through its own `synthetic_manual` link.
- Test participant saw debriefing plus plain preview, zero countdown/link elements.
  Reloading a completed field page rendered it again. Persisted totals stayed field=2,
  test=1. Active provider mutation without reopening was refused by the native model.
- Native Run export/import retained all presentation settings, imported inactive and
  omitted provider URLs; activation refused the missing imported profile. The first CLI
  probe omitted the native current-user context; it was corrected in the fixture.
- Content checked at 375/768/1440 widths; viewport restored. No JS errors observed;
  existing missing service-worker/VAPID warnings remain in this local environment.
  Screenshot outside Git: `outputs/Complete-debriefing-countdown.png`.

No full general suite rerun, VM change, real-provider pilot or OpenCPU exercise was
performed for this change. VM migration/load/HiWi gates remain required.
