# QuotaRules: first executable component

2026-10-14. Internal PHP contract, not a public spreadsheet syntax or API.

## Responsibility

QuotaRules validates definitions, builds the Cartesian product for each table and evaluates
initial eligibility. It has no I/O, no global DB dependency, no counters and no side effects.
Its constructor checks all definitions. evaluate() checks the entire supplied count snapshot
before classifying answers, so infrastructure errors cannot masquerade as participant rejection.

## Input example

```php
$tables = [
    'age_sex' => [
        'dimensions' => [
            ['source' => 'demography.age', 'type' => 'integer', 'categories' => [
                'young' => ['min' => 18, 'max' => 29],
                'older' => ['min' => 30, 'max' => 39],
            ]],
            ['source' => 'demography.sex', 'type' => 'category', 'categories' => [
                'male' => 1, 'female' => 2,
            ]],
        ],
        'limits' => ['young|male' => 10, 'young|female' => 10,
                     'older|male' => 10, 'older|female' => 10],
    ],
];
$rules = new QuotaRules($tables);
$result = $rules->evaluate(
    ['demography.age' => '29', 'demography.sex' => '2'],
    ['age_sex' => ['young|male' => 0, 'young|female' => 9,
                   'older|male' => 0, 'older|female' => 0]]
);
```

The result is admitted with assignment age_sex -> young|female. There is no increment.
A second table, e.g. education, is evaluated using the same contract. Every table must pass.

## Contract details

- Each source is an opaque, qualified key into an answer map. It is never an SQL identifier.
  The storage adapter must resolve actual survey/variable identity, verify ownership and
  supported item metadata, and load the intended saved demographic response.
- Table/category codes use letters, digits, underscores and hyphens. Cross-product codes
  join the selected category codes in dimension order with a pipe. They are internal codes;
  the UI must use labels. Structure must stay fixed after activation.
- Category values are exact choice codes: integers and their equivalent strings match;
  leading zeroes in string codes are preserved. Booleans, floats and arrays never match.
- Integer answers must already be integers or canonical decimal integer strings in PHP's
  integer range. Whitespace, padded forms, fractions and scientific notation are not coerced.
  The future storage/UI adapter must define normalization without rounding or modifying raw data.
- Bounds and limits are typed integers. All bounds are inclusive. Overlap is an error;
  gaps produce getWarnings() diagnostics and out-of-range answers are rejected.
- getGroups() returns the automatic cross-product. Unspecified limits default to zero.
  A null/invalid limits map or a limit for a nonexistent group is a configuration error.
- Counts must include every table and group, with nonnegative integer effective totals.
  Missing/extra groups are errors, not zero. SQL numeric strings must be checked and converted
  by the storage adapter. Completions and adjustments are aggregated separately there.
- No test/field state is held in this component. The persistence layer must supply a snapshot
  for exactly one cohort and use the same cohort when saving the decision. Independent snapshot
  tests do not prove database cohort isolation.

## Outcomes

- admitted: complete table-to-group assignments.
- rejected/group_full: a matched group is full or closed.
- rejected/missing_answer: null, absent or empty-string answer.
- rejected/unmatched_answer: unsupported answer shape, unknown category or range mismatch.
- InvalidArgumentException: invalid rule configuration; never convert to quota_full.
- UnexpectedValueException: incomplete/invalid count data; never convert to quota_full.

Rejections return no partial assignments or raw response values. Only the first blocking
table/source is reported, in definition order. Persisted rejections must be reused by the
QuotaStore service; this evaluator itself has no participant history.

## Testing

With existing project dependencies, the focused test can run without formr setup or a DB:

```sh
php vendor/bin/phpunit --no-configuration --do-not-cache-result tests/QuotaRulesTest.php
```

The file is also discovered by the normal tests/phpunit.xml suite. Local verification used
PHP 8.2.34 and the official PHPUnit 11.5.6 PHAR with mbstring enabled. Artificial rule fixtures
contain no research data. See the test file for boundaries, crossing, configuration failures,
independent snapshots, all-table exclusion, exact codes and gap diagnostics.

## Remaining integration work

Persistence and idempotent admission/completion are implemented and tested on MariaDB;
see QUOTA_STORAGE.md. Remaining: activation and reset; load survey metadata/results;
run units and lifecycle; UI and input validation; provider/monitor/export integration.
Do not call evaluate() to recheck capacity at Complete: already-admitted users may finish.
