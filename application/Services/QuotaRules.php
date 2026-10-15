<?php

/**
 * Pure quota classification and eligibility rules, independent of storage and redirects.
 * The caller must supply a consistent, cohort-specific count snapshot and persist decisions.
 * Invalid configuration/counts throw; only participant mismatches return a rejection.
 */
class QuotaRules {

    private array $tables;
    private array $groups = [];

    private array $warnings = [];

    /** Validate every table before any participant can be classified. */
    public function __construct(array $tables) {
        if (!$tables) {
            throw new InvalidArgumentException('At least one quota table is required.');
        }
        foreach ($tables as $tableCode => $table) {
            self::checkCode($tableCode);
            if (!is_array($table) || empty($table['dimensions']) || !is_array($table['dimensions'])
                || !array_is_list($table['dimensions'])) {
                throw new InvalidArgumentException('Quota dimensions must be a nonempty list.');
            }
            $combinations = [[]];
            $sources = [];
            foreach ($table['dimensions'] as $dimension) {
                self::validateDimension($dimension);
                $source = $dimension['source'];
                if (isset($sources[$source])) {
                    throw new InvalidArgumentException('A source occurs twice in one quota table.');
                }
                $sources[$source] = true;
                if ($dimension['type'] === 'integer') {
                    $ranges = array_values($dimension['categories']);
                    usort($ranges, static fn($a, $b) => $a['min'] <=> $b['min']);
                    for ($i = 1; $i < count($ranges); $i++) {
                        if ($ranges[$i - 1]['max'] < $ranges[$i]['min'] - 1) {
                            $this->warnings[] = ['reason' => 'range_gap', 'table' => (string) $tableCode,
                                'source' => $source, 'min' => $ranges[$i - 1]['max'] + 1,
                                'max' => $ranges[$i]['min'] - 1];
                        }
                    }
                }
                $next = [];
                foreach ($combinations as $combination) {
                    foreach ($dimension['categories'] as $code => $category) {
                        $next[] = $combination + [$source => (string) $code];
                    }
                }
                $combinations = $next;
            }
            $limits = array_key_exists('limits', $table) ? $table['limits'] : [];
            if (!is_array($limits)) {
                throw new InvalidArgumentException('Quota limits must be a map.');
            }
            foreach ($combinations as $combination) {
                $code = implode('|', $combination);
                $limit = array_key_exists($code, $limits) ? $limits[$code] : 0;
                if (!is_int($limit) || $limit < 0) {
                    throw new InvalidArgumentException('Quota limits must be nonnegative integers.');
                }
                $this->groups[$tableCode][$code] = ['categories' => $combination, 'limit' => $limit];
            }
            if (array_diff_key($limits, $this->groups[$tableCode])) {
                throw new InvalidArgumentException('A quota limit refers to an unknown group.');
            }
        }
        $this->tables = $tables;
    }

    /** Return the automatic category cross-products, including closed groups with limit zero. */
    public function getGroups(): array {
        return $this->groups;
    }

    /** Expose deliberate gaps for the configuration UI without inventing extra categories. */
    public function getWarnings(): array {
        return $this->warnings;
    }

    /**
     * Evaluate initial admission using normalized saved answers and effective integer counts.
     * No partial assignments are returned on rejection. Never call this to recheck completion.
     */
    public function evaluate(array $answers, array $counts): array {
        $this->validateCounts($counts);
        $assignments = [];
        foreach ($this->tables as $tableCode => $table) {
            $categories = [];
            foreach ($table['dimensions'] as $dimension) {
                $source = $dimension['source'];
                $value = $answers[$source] ?? null;
                if ($value === null || $value === '') {
                    return self::reject('missing_answer', (string) $tableCode, $source);
                }
                $category = self::matchCategory($dimension, $value);
                if ($category === null) {
                    return self::reject('unmatched_answer', (string) $tableCode, $source);
                }
                $categories[] = $category;
            }
            $code = implode('|', $categories);
            if ($counts[$tableCode][$code] >= $this->groups[$tableCode][$code]['limit']) {
                return self::reject('group_full', (string) $tableCode);
            }
            $assignments[$tableCode] = $code;
        }
        return ['status' => 'admitted', 'reason' => null, 'assignments' => $assignments];
    }

    /** Keep response values out of rejection messages and application logs. */
    private static function reject(string $reason, string $table, ?string $source = null): array {
        return ['status' => 'rejected', 'reason' => $reason, 'table' => $table,
            'source' => $source, 'assignments' => []];
    }

    /** Reject ambiguous category definitions rather than selecting the first match. */
    private static function validateDimension($dimension): void {
        if (!is_array($dimension) || !isset($dimension['source']) || !is_string($dimension['source'])
            || trim($dimension['source']) === '' || !isset($dimension['type'])
            || !in_array($dimension['type'], ['category', 'integer'], true)
            || empty($dimension['categories']) || !is_array($dimension['categories'])) {
            throw new InvalidArgumentException('A quota dimension requires source, type and categories.');
        }
        $seen = [];
        foreach ($dimension['categories'] as $code => $category) {
            self::checkCode($code);
            if ($dimension['type'] === 'category') {
                if ((!is_string($category) && !is_int($category)) || (string) $category === '') {
                    throw new InvalidArgumentException('A categorical answer code must be a nonempty string or integer.');
                }
                $value = (string) $category;
                if (in_array($value, $seen, true)) {
                    throw new InvalidArgumentException('An answer code belongs to more than one category.');
                }
                $seen[] = $value;
            } else {
                if (!is_array($category) || !isset($category['min'], $category['max'])
                    || !is_int($category['min']) || !is_int($category['max'])
                    || $category['min'] > $category['max']) {
                    throw new InvalidArgumentException('Integer ranges require ordered integer min and max bounds.');
                }
                foreach ($seen as $range) {
                    if ($category['min'] <= $range['max'] && $range['min'] <= $category['max']) {
                        throw new InvalidArgumentException('Quota integer ranges must not overlap.');
                    }
                }
                $seen[] = $category;
            }
        }
    }

    /** Accept database integer strings without coercing decimals, booleans or scientific notation. */
    private static function integerValue($value): ?int {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || !preg_match('/\A-?(?:0|[1-9][0-9]*)\z/', $value)) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        return $parsed === false ? null : $parsed;
    }

    /** Match exact stored choice codes or inclusive integer ranges; never round answers. */
    private static function matchCategory(array $dimension, $value): ?string {
        if ($dimension['type'] === 'integer') {
            $value = self::integerValue($value);
            if ($value === null) {
                return null;
            }
        } elseif (!is_string($value) && !is_int($value)) {
            return null;
        }
        foreach ($dimension['categories'] as $code => $category) {
            if ($dimension['type'] === 'integer') {
                if ($value >= $category['min'] && $value <= $category['max']) {
                    return (string) $code;
                }
            } elseif ((string) $value === (string) $category) {
                return (string) $code;
            }
        }
        return null;
    }

    /** Missing snapshots must stop evaluation, not silently open groups with a default count. */
    private function validateCounts(array $counts): void {
        if (array_diff_key($counts, $this->groups) || array_diff_key($this->groups, $counts)) {
            throw new UnexpectedValueException('Quota count snapshot has missing or unknown tables.');
        }
        foreach ($this->groups as $table => $groups) {
            if (!is_array($counts[$table]) || array_diff_key($counts[$table], $groups)
                || array_diff_key($groups, $counts[$table])) {
                throw new UnexpectedValueException('Quota count snapshot has missing or unknown groups.');
            }
            foreach ($counts[$table] as $count) {
                if (!is_int($count) || $count < 0) {
                    throw new UnexpectedValueException('Effective quota counts must be nonnegative integers.');
                }
            }
        }
    }

    /** Delimiter-free internal codes keep generated cross-product identifiers unambiguous. */
    private static function checkCode($code): void {
        if ((!is_string($code) && !is_int($code)) || !preg_match('/\A[A-Za-z0-9_-]+\z/', (string) $code)) {
            throw new InvalidArgumentException('Quota codes may contain letters, digits, underscores and hyphens.');
        }
    }
}
