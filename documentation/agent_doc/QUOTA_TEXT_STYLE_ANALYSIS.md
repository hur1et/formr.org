# Existing formr text style: analysis for quota copy review

2026-10-18. Analysis requested by the user. The user subsequently authorized a copy
revision based on this direction; behavior changes remain outside that authorization.

## Basis and attribution

Read official base e9fdb410, before this branch's Quota/Complete copy: module explanations,
Survey/Pause/SkipForward/External/Shuffle/Page forms, spreadsheet help, survey upload,
run import/export and deletion screens. Compared with the current quota forms and help.
Git blame confirms contributions by Ruben Arslan and Cyril Tata. Some explanatory
paragraphs currently attributed to Ruben were inspected separately, including run
design, data linking, survey reuse, R exports and external modules. Blame tracks the
last recorded edit; it does not prove sole authorship or reveal personal preferences.

## Observed patterns

1. **Describe the research action.** Module summaries focus on asking questions, inviting
   participants, creating loops and collecting data. They seldom lead with internal
   storage, object identity or validation terminology.
2. **Separate the form from the explanation.** Buttons and field labels are compact;
   expandable documentation can be detailed. Brevity everywhere is not the house style.
   The help often progresses from purpose, to placement/configuration, to an example,
   to the participant's actual experience.
3. **Address the researcher directly.** Sentences use you/your and instructions such as
   choose, enter and place. Technical vocabulary is used when it helps author a study,
   with a concrete example or code expression nearby.
4. **Explain by running a small study mentally.** Email and skip documentation lists
   positions, then follows one participant through them. This is more instructive than
   repeating rules about correct configuration.
5. **Assume competence without assuming platform knowledge.** R and research designs
   are normal topics; a new field or mechanism still deserves a working example.
   Familiarity with research does not imply familiarity with URL parameter syntax.
6. **Use a conversational, occasionally humorous voice.** The recorder/tape metaphor
   and an aside about using other survey software make the help human. They are not
   reasons to add forced jokes to quota exclusion or payment-related errors.
7. **Make consequential warnings specific.** Survey upload and deletion screens explain
   what may be lost and which action causes it. The style does contain substantial
   warnings; it does not support a rule that all warnings should disappear.

## Implications for Quota and Complete (proposals)

- Form: short purpose sentence, meaningful labels and the one consequence needed for
  the decision in front of the user. Put the full lifecycle in Module explanations.
- Describe people progressing through the study rather than records moving between
  internal states. For example, explain that only people reaching Complete count,
  rather than naming a persisted admission object.
- Provider fields need one synthetic entry-link/return-link example. Show the parameter
  name, the provider placeholder and the received ID as three distinct things. Do not
  infer provider formats or suggest that IDs are assigned by the researcher.
- Explain no reservations using the observable result: already admitted participants
  can still finish when the target has been reached, so a count can exceed its limit.
- Explain the most useful example as demographics -> Quota -> main survey/filters ->
  Complete, with a small crossed table and an independent second table. State clearly
  that the chosen variables are examples, not built-in restrictions.
- Count-correction help should explain the user's edit and its effect on the current
  total. Database locks and retry receipts belong in technical documentation.
- Error copy should name the field/problem and the next action. A broad technical
  failure must not be rewritten as quota_full or falsely claim that nothing was saved.
  A Complete retry may already have a completion booking; keep that distinction accurate.
- Distinguish run-wide completion, a single Survey finishing, a quota target and a count.
  Retain the agreed machine identifiers and existing Fulfillment spelling.

## Limits of imitation

Existing copy is uneven: short labels coexist with long paragraphs, user/participant
terminology varies, and some older English is awkward. Older technical advice is not
validated by this language review. Borrow the useful structure and directness rather
than copying grammatical mistakes, dated assumptions or every informal aside.

Current quota copy tends to compress implementation specifications into interface
instructions. That makes it precise, but sometimes impersonal and hard to apply.
The proposed change is editorial: concrete research actions and examples, with details
placed where they are useful. It does not authorize more features, restrictions or
confirmation steps. Final wording remains for the user's review.

## Applied copy revision

On the user's subsequent instruction, revised Quota/Complete forms, the quota overview,
the provider monitor, the Page provider-exit labels, common editor instructions and
validation messages, and the Quota/Complete module explanations. Participant error copy
now explains browser reload without adding a retry action or claiming nothing was saved.
Provider validation identifies the failing return-link field; its validation predicates
and accepted values are unchanged. Native controls, machine identifiers, quota rules,
activation, corrections and provider redirects retain their behavior.

The module help now follows a concrete run with crossed and independent quotas, and
shows how a received ID becomes part of a provider return URL. The synthetic example
does not prefill settings or infer provider formats. Test-link help explicitly retains
the requirement for a provider ID. Form hints explain zero limits/reset behavior, shared
test/field limits and unsaved-edit loss on Refresh. No new dialogs, badges or gates.

Verification: existing standard suite 289 tests / 724 assertions; editor/overview lane
18 tests. Eleven existing PHPUnit deprecations remain. Changed PHP syntax and diff checks
pass; frontend production build passes with existing size warnings. No new copy-only tests
were added. The actual local formr draft editor shows the revised strings and the overview
shows the revised correction/sharing hints. Expanded module help and relevant forms were
checked at 375/768/1440px without horizontal overflow in these blocks; viewport reset.
No console errors/warnings. Screenshot is external project output
`Quotierung-Texte-Complete.png`. User review and the final agreed UI/UX pass remain next.
