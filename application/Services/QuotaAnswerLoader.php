<?php

/** Read saved demographic values for QuotaStore, without modifying survey answers. */
class QuotaAnswerLoader {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('QuotaAnswerLoader requires MariaDB PDO with exception error mode.');
        }
        $this->pdo = $pdo;
    }

    /**
     * Called inside the admission transaction with persisted definitions, never browser data.
     * Each dimension binds its opaque source key to run_unit_id and exact stored item_name.
     * Ambiguous/repeated survey sessions require an explicit future policy; never pick one.
     */
    public function load(int $runId, int $sessionId, int $quotaRunUnitId, array $tables): array {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Saved quota answers must be loaded inside the admission transaction.');
        }
        new QuotaRules($tables);
        $quota = $this->query('SELECT ru.position FROM survey_run_units ru
            JOIN survey_units u ON u.id = ru.unit_id
            JOIN survey_run_sessions rs ON rs.run_id = ru.run_id
            WHERE ru.id = ? AND ru.run_id = ? AND rs.id = ? AND u.type = ?',
            [$quotaRunUnitId, $runId, $sessionId, 'Quota'])->fetch(PDO::FETCH_ASSOC);
        if (!$quota) {
            throw new DomainException('Quota unit and participation must belong to this run.');
        }
        $answers = $bindings = [];
        foreach ($tables as $table) {
            foreach ($table['dimensions'] as $dimension) {
                $runUnitId = $dimension['run_unit_id'] ?? null;
                $name = $dimension['item_name'] ?? null;
                if (!is_int($runUnitId) || $runUnitId <= 0 || !is_string($name) || $name === '') {
                    throw new InvalidArgumentException('Quota source requires a run-unit ID and stored item name.');
                }
                $binding = [$runUnitId, $name, $dimension['type']];
                $source = $dimension['source'];
                if (isset($bindings[$source]) && $bindings[$source] !== $binding) {
                    throw new InvalidArgumentException('A quota source key has conflicting survey bindings.');
                }
                $bindings[$source] = $binding;
                // Validate every dimension's categories, even when tables share a source.
                $value = $this->read($runId, $sessionId, (int) $quota['position'], $dimension);
                if (array_key_exists($source, $answers) && $answers[$source] !== $value) {
                    throw new UnexpectedValueException('A saved answer changed while loading quota sources.');
                }
                $answers[$source] = $value;
            }
        }
        return $answers;
    }

    private function read(int $runId, int $sessionId, int $quotaPosition, array $dimension) {
        $study = $this->query('SELECT s.id, s.results_table, s.unlinked, s.valid FROM survey_run_units ru
            JOIN survey_runs r ON r.id = ru.run_id
            JOIN survey_units u ON u.id = ru.unit_id AND u.type = ?
            JOIN survey_studies s ON s.id = ru.unit_id AND s.user_id = r.user_id
            WHERE ru.id = ? AND ru.run_id = ? AND ru.position < ?',
            ['Survey', $dimension['run_unit_id'], $runId, $quotaPosition])->fetch(PDO::FETCH_ASSOC);
        // Native SurveyStudy imports leave this legacy flag NULL; load() considers
        // an existing study valid. Still reject an explicit invalid flag here.
        if (!$study || ($study['valid'] !== null && (int) $study['valid'] !== 1) || (int) $study['unlinked'] !== 0) {
            throw new DomainException('Quota source must be a valid linked survey before the Quota unit, owned by the run owner.');
        }
        $item = $this->query('SELECT name, type, choice_list, label FROM survey_items
            WHERE study_id = ? AND BINARY name = ?', [$study['id'], $dimension['item_name']])->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            throw new UnexpectedValueException('Configured quota variable is absent from survey metadata.');
        }
        $this->validateItem($study, $item, $dimension);
        $table = self::identifier($study['results_table']);
        $column = self::identifier($item['name']);
        $owners = $this->query('SELECT id FROM survey_studies WHERE BINARY results_table = BINARY ?
            OR (@@lower_case_table_names<>0 AND BINARY LOWER(results_table)=BINARY LOWER(?))',
            [$study['results_table'],$study['results_table']])->fetchAll(PDO::FETCH_COLUMN);
        if (count($owners) !== 1 || (int) $owners[0] !== (int) $study['id']) {
            throw new UnexpectedValueException('Survey result table ownership is ambiguous.');
        }
        $sqlType = $this->query('SELECT c.DATA_TYPE FROM information_schema.COLUMNS c
            JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
            WHERE c.TABLE_SCHEMA = DATABASE() AND (BINARY c.TABLE_NAME = BINARY ?
                OR (@@lower_case_table_names<>0 AND BINARY LOWER(c.TABLE_NAME)=BINARY LOWER(?)))
            AND BINARY c.COLUMN_NAME = ? AND t.TABLE_TYPE = ?',
            [$study['results_table'],$study['results_table'],$item['name'],'BASE TABLE'])->fetchColumn();
        $integerTypes = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'];
        $allowedTypes = $dimension['type'] === 'integer' ? $integerTypes
            : array_merge($integerTypes, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext']);
        if (!in_array($sqlType, $allowedTypes, true)) {
            throw new UnexpectedValueException('Quota result column is missing or has an unsupported storage type.');
        }
        if ($dimension['type'] === 'category' && in_array($sqlType, $integerTypes, true)) {
            foreach ($dimension['categories'] as $code) {
                $integer = filter_var($code, FILTER_VALIDATE_INT);
                if ($integer === false || (string) $integer !== (string) $code) {
                    throw new UnexpectedValueException('Integer result storage cannot preserve this exact category code.');
                }
            }
        }
        $sessions = $this->query('SELECT id, ended, state FROM survey_unit_sessions
            WHERE run_session_id = ? AND run_unit_id = ? AND unit_id = ?',
            [$sessionId, $dimension['run_unit_id'], $study['id']])->fetchAll(PDO::FETCH_ASSOC);
        if (count($sessions) !== 1 || $sessions[0]['ended'] === null || $sessions[0]['state'] !== 'ENDED') {
            throw new DomainException('Quota source requires one unambiguous completed survey session.');
        }
        $rows = $this->query("SELECT $column AS answer, ended FROM $table WHERE session_id = ? AND study_id = ?",
            [$sessions[0]['id'], $study['id']])->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1 || $rows[0]['ended'] === null) {
            throw new UnexpectedValueException('Completed demographic response row is missing or inconsistent.');
        }
        // NULL is a real unanswered item. A missing row/column above is a technical failure.
        return $rows[0]['answer'];
    }

    private function validateItem(array $study, array $item, array $dimension): void {
        $type = str_replace('-', '_', $item['type'] ?? '');
        if ($dimension['type'] === 'integer') {
            if (!in_array($type, ['number', 'range', 'range_ticks'], true)) {
                throw new InvalidArgumentException('Integer quota ranges require a supported numeric survey item.');
            }
            return;
        }
        if (!in_array($type, ['mc', 'mc_button', 'mc_heading', 'select_one', 'sex'], true)) {
            throw new InvalidArgumentException('Categorical quotas require a supported single-choice survey item.');
        }
        // Sex_Item defines these two codes in code, independent of a stored choice list.
        $choices = $type === 'sex' ? ['1', '2'] : $this->query(
            'SELECT name FROM survey_item_choices WHERE study_id = ? AND BINARY list_name = ?',
            [$study['id'], $item['choice_list']])->fetchAll(PDO::FETCH_COLUMN);
        $choices = array_map('strval', $choices);
        if (!$choices || count(array_unique($choices)) !== count($choices)) {
            throw new UnexpectedValueException('Quota choice metadata is missing or ambiguous.');
        }
        foreach ($dimension['categories'] as $value) {
            if (!in_array((string) $value, $choices, true)) {
                throw new InvalidArgumentException('Configured quota category is not a stored survey choice code.');
            }
        }
    }

    /** Only DB-resolved names reach this quoting boundary; source keys are never SQL. */
    private static function identifier($name): string {
        if (!is_string($name) || $name === '' || strlen($name) > 64 || str_contains($name, "\0")) {
            throw new UnexpectedValueException('Invalid saved result identifier.');
        }
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function query(string $sql, array $values): PDOStatement {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);
        return $statement;
    }
}
