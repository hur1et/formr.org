# Quota usability and error-tolerance review

2026-10-17, current application commit `da7f9abe`. Review requested by the user under
the colloquial German DAU concept: understandable operation and tolerance of predictable
mistakes, without adding excessive warnings or restricting exploration. No functional
or interface changes approved or made during this review.

Method: inspect the actual local run editor, its rendered controls, templates, editor
JavaScript, provider validation, overview parsing and participant error paths. A small
standalone PHP form-parsing reproduction checks the newly identified input limit.
This is not another security audit, load test or final post-copy-review UI acceptance.

## Input-limit finding (subsequently corrected)

The overview submits four leaf values per group: base_limit, limit, base_count, count,
plus four page-level fields. A 500-group form therefore submits 2,004 variables.
PHP's default max_input_vars=1000 truncates this before application parsing. The local
start script uses PHP -n and does not override this setting. The VM setting still needs
inspection; no claim is made that its configured value is necessarily 1000.

Reproduction with the bundled PHP 8.2.34, -n, http_build_query followed by parse_str:
500 groups submitted, 249 complete groups parsed, PHP input-limit warning. The server
checks the full expected table/group set before changes, so a truncated request is
refused, not partly saved. Nevertheless the supported configuration cannot be edited
through the default overview setup at that size.

Options to discuss with the user:
- A: compact structured submission independent of per-input PHP variable count; preserve
  complete server-side validation and the existing Save behavior. Requires adapting and
  testing the form submission path.
- B: explicitly configure enough input variables in every supported environment and
  validate/document that deployment prerequisite. Less application change, but depends
  on administrator configuration and cannot silently be assumed for upstream installs.

The user chose A. The native form now posts its rows as one JSON value, preserving
strings until the existing server/ledger checks. Actual browser Save and reload with
500 groups pass under the local default input-variable limit. Standard lane 289 tests,
MariaDB lane 139 tests and 18 JavaScript tests pass. See QUOTA_OVERVIEW.md for implementation
and evidence. The other usability findings below have not been implemented.

## Other concrete usability findings

1. Changing a source or range resets affected/new group limits to zero. This prevents
   applying an old target to a different group, but the consequence is not explained
   next to the action. Zero closes a group; an unnoticed reset can exclude participants.
   Preserve the safe rule; discuss a concise indication of which limits need resetting.
2. Overview Refresh, participant-cohort selection (automatic GET submit), navigation and
   the separate provider-sharing form can discard unsaved overview edits. The run editor
   already tracks unsaved changes; the quota overview does not. Discuss protection only
   when edits exist, rather than confirmations for ordinary page visits.
3. Test and field counts are separate, while limits are shared. The Participants selector
   does not explicitly explain that a limit edited in test view affects field admission.
   A short clarification is preferable to adding a second limit system.
4. Complete displays all five provider inputs even with explicit no-provider mode, and
   does not dynamically adapt the form. Distinguishing an incoming parameter's name,
   provider-supplied placeholder and individual received ID still relies on technical
   vocabulary. Use one clearly synthetic example; never infer real provider IDs or
   rewrite supplied URLs automatically. Hide irrelevant fields without destroying input.
5. Provider URL validation can name the failing complete/quota_full/screen_out field
   more precisely; some current messages group all three URLs together. Unsupported
   sources are disabled but their option text does not explain the exact restriction.
6. Participant technical-error messages say to retry/contact the team but provide no
   retry button. Recovery currently requires browser reload. Discuss a simple retry
   action using the same participation, preserving once-only booking.

## Existing behavior worth preserving

Server validation does not trust the browser. Overlaps, unsupported source/storage,
decimal ranges, negative counts/limits and excessive cross-products are rejected.
Activation checks saved Complete settings and source order. Category/provider definitions
are fixed after activation; targets and count corrections remain editable in Quotas.
Repeated completion/save requests do not double count; corrections preserve concurrent
completions. Missing answers and technical faults are different paths. Test exits do
not contact the real provider. Provider statistics expose aggregates with no edit route.

Recommended discussion order: form submission limit first, silent limit resets and
unsaved edits next, then the provider form and short clarifications during the user's
text pass. No wizard, extra approval stages or replacement of the agreed quota logic.
