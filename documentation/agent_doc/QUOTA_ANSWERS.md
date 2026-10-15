# Saved demographic answer adapter

2026-10-15. QuotaAnswerLoader connects persisted formr responses to QuotaStore's
admission transaction. Run-unit UI and lifecycle integration are still pending.

## Binding contract

Each dimension retains its opaque `source` key and adds `run_unit_id` (integer) and
`item_name` (the exact stored spreadsheet variable name). These fields are persisted
in the existing dimensions JSON; no additional migration is needed. Example:

```php
['source' => 'demography.age', 'run_unit_id' => 201, 'item_name' => 'age',
 'type' => 'integer', 'categories' => ['adult' => ['min' => 18, 'max' => 29]]]
```

The source key is not SQL and not parsed to infer a table. The visible item label
is not used for lookup. The future editor must show the survey, stored variable
name and readable label separately and persist this binding on configuration.
One source key cannot refer to different bindings/types across quota tables.

`QuotaStore::admitFromSurvey(runId, quotaId, sessionId, quotaRunUnitId)` reuses the
existing admission transaction. Only a new decision loads answers. Repeated admission
returns the saved decision, even if answers or metadata have since changed. The
lower-level `admit` callback API remains for trusted adapters/tests, not browser data.

## Resolution and checks

1. Require the caller's quota transaction. Validate the definitions.
2. Verify a Quota run unit and the participation belong to the given run.
3. Resolve each selected Survey run unit before the Quota position. Require a valid,
   linked survey owned by the run owner. Never resolve a source from another run.
4. Find the exact item name, validate its type and configured category codes against
   metadata. Validate the registered result table and actual result column type.
5. Require exactly one completed unit session for that participation, run-unit
   occurrence and survey. Require ENDED state and an end timestamp. Do not silently
   choose a latest/earliest visit or fall back to an occurrence-less historic session.
6. Read the matching result row by unit-session ID and survey ID; require its end
   timestamp. Return stored values without updating, rounding or recoding answers.

Missing metadata/table/column/row, incomplete sources and ambiguous visits raise
technical exceptions. Only a NULL/empty item value in an otherwise valid saved row
represents a missing answer for the rule evaluator. Exceptions roll back admission;
after repair, the same participation can retry. Do not convert exceptions to quota_full.

All predicates use bound values. Dynamic SQL identifiers come from checked database
metadata and are quoted with escaped backticks. Table ownership must be unique and
the result source must be a base table in the active database.

## Implemented types and boundaries

- Integer ranges: `number`, `range`, `range_ticks`, with integer SQL storage only.
  Decimal/float columns raise a configuration/storage error; no implicit rounding.
- Categories: `mc`, `mc_button`, `mc_heading`, `select_one`, with a saved choice list;
  `sex` uses its existing built-in codes 1 and 2, matching Sex_Item.
- Category codes must match choice codes, not labels. Text storage preserves leading
  zeroes. Integer storage rejects configured codes it cannot preserve exactly.
- Multiple-choice, free text, calculated/dynamic items and other specialized types
  are not supported by this initial adapter. Unsupported metadata is a setup error,
  not a participant rejection. Supporting additional types requires explicit handling.
- Unlinked surveys are rejected without reading responses; no anonymity linkage is
  reconstructed. Repeat-visit semantics remain unimplemented rather than guessed.

These are current implementation limits, not newly agreed product requirements.
The editor must surface unsupported selections before field activation. Source
metadata/bindings must be validated and protected by the later activation layer.
The quota-to-run-unit attachment is not persisted/enforced by this adapter yet; the
given run-unit ID is validated as a Quota in the run, but final lifecycle wiring must
bind it to the correct definition. There are still no participant HTTP endpoints.

## Verification

QuotaStoreTest now includes synthetic survey metadata, run positions and real MariaDB
result tables. It exercises admission from saved answers through persisted assignments
and completion, another participant's values, NULL versus absent results, wrong ownership
or occurrence, duplicate visits, invalid states, missing choice codes, malicious table
names, lossy numeric storage, conflicting bindings and retry after repairing a source.

Local result: 58 database tests / 227 assertions and 53 pure rule tests / 106 assertions
on PHP 8.2.34, PHPUnit 11.5.6 and MariaDB 12.1.2. All changed PHP files pass syntax checks.
These are synthetic fixture tests, not a full formr/browser/provider acceptance test.
The existing local full-suite bootstrap/Windows readability limitation remains unresolved.
