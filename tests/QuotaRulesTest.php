<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../application/Services/QuotaRules.php';

class QuotaRulesTest extends TestCase {

    /** Small artificial rule fixture: no participant data or database access. */
    private function tables(): array {
        return [
            'age_sex' => [
                'dimensions' => [
                    ['source' => 'demography.age', 'type' => 'integer', 'categories' => [
                        'young' => ['min' => 18, 'max' => 29], 'older' => ['min' => 30, 'max' => 39],
                    ]],
                    ['source' => 'demography.sex', 'type' => 'category', 'categories' => ['male' => 1, 'female' => 2]],
                ],
                'limits' => ['young|male' => 2, 'young|female' => 2, 'older|male' => 2, 'older|female' => 2],
            ],
            'education' => [
                'dimensions' => [['source' => 'demography.education', 'type' => 'category',
                    'categories' => ['university' => 'uni', 'other' => 'other']]],
                'limits' => ['university' => 1, 'other' => 2],
            ],
        ];
    }

    private function counts(): array {
        return ['age_sex' => ['young|male' => 0, 'young|female' => 0, 'older|male' => 0, 'older|female' => 0],
            'education' => ['university' => 0, 'other' => 0]];
    }

    private function answers(): array {
        return ['demography.age' => 29, 'demography.sex' => '2', 'demography.education' => 'uni'];
    }

    public function testAllTablesMustBeOpenWithoutPartialAssignments(): void {
        $rules = new QuotaRules($this->tables());
        $counts = $this->counts();
        $counts['education']['university'] = 1;
        $result = $rules->evaluate($this->answers(), $counts);
        self::assertSame('rejected', $result['status']);
        self::assertSame('group_full', $result['reason']);
        self::assertSame('education', $result['table']);
        self::assertSame([], $result['assignments']);
    }

    public function testAdmissionDoesNotModifyAnswersOrCounts(): void {
        $answers = $this->answers();
        $counts = $this->counts();
        $rules = new QuotaRules($this->tables());
        self::assertSame(['status' => 'admitted', 'reason' => null,
            'assignments' => ['age_sex' => 'young|female', 'education' => 'university']],
            $rules->evaluate($answers, $counts));
        self::assertSame($this->answers(), $answers);
        self::assertSame($this->counts(), $counts);
    }

    public function testAutomaticCrossingAndClosedDefault(): void {
        $tables = $this->tables();
        unset($tables['age_sex']['limits']['young|female']);
        $rules = new QuotaRules($tables);
        self::assertCount(4, $rules->getGroups()['age_sex']);
        self::assertSame(0, $rules->getGroups()['age_sex']['young|female']['limit']);
        self::assertSame('group_full', $rules->evaluate($this->answers(), $this->counts())['reason']);
    }

    public static function ages(): array {
        return [
            [18, 'young|female'], [29, 'young|female'], [30, 'older|female'], [39, 'older|female'],
            ['18', 'young|female'], ['30', 'older|female'], [17, null], [40, null],
            [29.9, null], ['29.9', null], ['29.0', null], ['3e1', null], [true, null],
            [[], null], ['999999999999999999999999', null], ['029', null], [' 29 ', null],
        ];
    }

    #[DataProvider('ages')]
    public function testIntegerBoundariesWithoutRounding($age, ?string $group): void {
        $answers = $this->answers();
        $answers['demography.age'] = $age;
        $result = (new QuotaRules($this->tables()))->evaluate($answers, $this->counts());
        if ($group === null) {
            self::assertSame('rejected', $result['status']);
            self::assertSame('unmatched_answer', $result['reason']);
            self::assertSame([], $result['assignments']);
        } else {
            self::assertSame('admitted', $result['status']);
            self::assertSame($group, $result['assignments']['age_sex']);
        }
    }

    public function testMissingAnswersHaveASeparateReason(): void {
        foreach ([null, ''] as $missing) {
            $answers = $this->answers();
            $answers['demography.sex'] = $missing;
            $result = (new QuotaRules($this->tables()))->evaluate($answers, $this->counts());
            self::assertSame('missing_answer', $result['reason']);
            self::assertSame('demography.sex', $result['source']);
        }
        $answers = $this->answers();
        unset($answers['demography.sex']);
        self::assertSame('missing_answer', (new QuotaRules($this->tables()))->evaluate($answers, $this->counts())['reason']);
    }

    public function testOverlappingInclusiveRangesAreConfigurationErrors(): void {
        $tables = $this->tables();
        $tables['age_sex']['dimensions'][0]['categories']['older']['min'] = 29;
        $this->expectException(InvalidArgumentException::class);
        new QuotaRules($tables);
    }

    public function testRangeGapsRejectWithoutInventingARestGroup(): void {
        $tables = $this->tables();
        $tables['age_sex']['dimensions'][0]['categories']['young']['max'] = 28;
        $rules = new QuotaRules($tables);
        self::assertSame([['reason' => 'range_gap', 'table' => 'age_sex', 'source' => 'demography.age',
            'min' => 29, 'max' => 29]], $rules->getWarnings());
        self::assertSame('unmatched_answer', $rules->evaluate($this->answers(), $this->counts())['reason']);
        self::assertSame([], (new QuotaRules($this->tables()))->getWarnings());
    }

    public function testRangeWarningsAreIndependentOfCategoryOrder(): void {
        $tables = $this->tables();
        $categories = &$tables['age_sex']['dimensions'][0]['categories'];
        $categories['young']['max'] = 27;
        $categories = array_reverse($categories, true);
        $rules = new QuotaRules($tables);
        self::assertSame(28, $rules->getWarnings()[0]['min']);
        self::assertSame(29, $rules->getWarnings()[0]['max']);
    }

    public function testStoredNamesDistinguishTwoSurveys(): void {
        $tables = ['both' => ['dimensions' => [
            ['source' => 'screening.age', 'type' => 'integer', 'categories' => ['young' => ['min' => 18, 'max' => 29]]],
            ['source' => 'followup.age', 'type' => 'integer', 'categories' => ['older' => ['min' => 30, 'max' => 39]]],
        ], 'limits' => ['young|older' => 1]]];
        $rules = new QuotaRules($tables);
        self::assertSame(['both' => 'young|older'], $rules->evaluate(
            ['screening.age' => 29, 'followup.age' => 30], ['both' => ['young|older' => 0]])['assignments']);
    }

    public function testZeroAndNegativeIntegerBoundaries(): void {
        $tables = ['number' => ['dimensions' => [['source' => 'survey.number', 'type' => 'integer',
            'categories' => ['range' => ['min' => -2, 'max' => 0]]]], 'limits' => ['range' => 1]]];
        $rules = new QuotaRules($tables);
        foreach ([-2, '-2', 0, '0'] as $value) {
            self::assertSame('admitted', $rules->evaluate(['survey.number' => $value], ['number' => ['range' => 0]])['status']);
        }
    }

    public function testChoiceCodesKeepLeadingZeroesAndZeroIsNotMissing(): void {
        $tables = ['code' => ['dimensions' => [['source' => 'survey.code', 'type' => 'category',
            'categories' => ['zero' => '0', 'padded' => '01', 'one' => 1]]],
            'limits' => ['zero' => 1, 'padded' => 1, 'one' => 1]]];
        $rules = new QuotaRules($tables);
        foreach (['zero' => 0, 'padded' => '01', 'one' => '1'] as $group => $value) {
            $result = $rules->evaluate(['survey.code' => $value], ['code' => ['zero' => 0, 'padded' => 0, 'one' => 0]]);
            self::assertSame(['code' => $group], $result['assignments']);
        }
    }

    public static function invalidDefinitions(): array {
        return [['empty'], ['duplicate_code'], ['decimal_bound'], ['reversed_bound'], ['unknown_type'],
            ['duplicate_source'], ['empty_category'], ['invalid_code'], ['negative_limit'], ['null_limit'],
            ['unknown_limit'], ['null_limits'], ['no_dimensions'], ['invalid_dimension']];
    }

    #[DataProvider('invalidDefinitions')]
    public function testConfigurationErrorsCannotBecomeParticipantRejections(string $case): void {
        $tables = $this->tables();
        switch ($case) {
            case 'empty': $tables = []; break;
            case 'duplicate_code': $tables['age_sex']['dimensions'][1]['categories']['female'] = '1'; break;
            case 'decimal_bound': $tables['age_sex']['dimensions'][0]['categories']['young']['max'] = 29.5; break;
            case 'reversed_bound': $tables['age_sex']['dimensions'][0]['categories']['young']['max'] = 17; break;
            case 'unknown_type': $tables['education']['dimensions'][0]['type'] = 'text'; break;
            case 'duplicate_source': $tables['age_sex']['dimensions'][] = $tables['age_sex']['dimensions'][0]; break;
            case 'empty_category': $tables['education']['dimensions'][0]['categories'] = []; break;
            case 'invalid_code': $tables['education']['dimensions'][0]['categories']['bad|code'] = 'bad'; break;
            case 'negative_limit': $tables['education']['limits']['other'] = -1; break;
            case 'null_limit': $tables['education']['limits']['other'] = null; break;
            case 'unknown_limit': $tables['education']['limits']['missing'] = 1; break;
            case 'null_limits': $tables['education']['limits'] = null; break;
            case 'no_dimensions': $tables['education']['dimensions'] = []; break;
            case 'invalid_dimension': $tables['education']['dimensions'][0] = 'invalid'; break;
        }
        $this->expectException(InvalidArgumentException::class);
        new QuotaRules($tables);
    }

    public static function brokenCounts(): array {
        return [['missing_table'], ['missing_group'], ['unknown_table'], ['unknown_group'],
            ['negative'], ['numeric_string'], ['null'], ['float'], ['boolean']];
    }

    #[DataProvider('brokenCounts')]
    public function testBrokenCountsThrowEvenWhenParticipantAnswersAreMissing(string $case): void {
        $counts = $this->counts();
        switch ($case) {
            case 'missing_table': unset($counts['education']); break;
            case 'missing_group': unset($counts['education']['other']); break;
            case 'unknown_table': $counts['unknown'] = []; break;
            case 'unknown_group': $counts['education']['unknown'] = 0; break;
            case 'negative': $counts['education']['other'] = -1; break;
            case 'numeric_string': $counts['education']['other'] = '0'; break;
            case 'null': $counts['education'] = null; break;
            case 'float': $counts['education']['other'] = 0.5; break;
            case 'boolean': $counts['education']['other'] = false; break;
        }
        $this->expectException(UnexpectedValueException::class);
        (new QuotaRules($this->tables()))->evaluate([], $counts);
    }

    public function testOverfilledGroupIsClosed(): void {
        $counts = $this->counts();
        $counts['education']['university'] = 2;
        self::assertSame('group_full', (new QuotaRules($this->tables()))->evaluate($this->answers(), $counts)['reason']);
    }

    public function testSnapshotsAreEvaluatedIndependentlyWithoutCachedCounts(): void {
        $rules = new QuotaRules($this->tables());
        $field = $this->counts();
        $field['education']['university'] = 1;
        self::assertSame('rejected', $rules->evaluate($this->answers(), $field)['status']);
        self::assertSame('admitted', $rules->evaluate($this->answers(), $this->counts())['status']);
        self::assertSame('rejected', $rules->evaluate($this->answers(), $field)['status']);
    }

    public function testUnknownCategoryAndMultipleAnswersDoNotMatch(): void {
        foreach ([-9, '2.0', true, [2], 'unknown'] as $value) {
            $answers = $this->answers();
            $answers['demography.sex'] = $value;
            self::assertSame('unmatched_answer', (new QuotaRules($this->tables()))->evaluate($answers, $this->counts())['reason']);
        }
    }
}
