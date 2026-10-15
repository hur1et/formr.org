<?php

/**
 * MariaDB quota ledger. Owns short transactions and serializes writers per quota.
 * Controllers must authorize access before calling; there are no public endpoints here.
 * No redirects or remote calls may occur inside a transaction.
 */
class QuotaStore {

    private PDO $pdo;

    /** Use formr's PDO connection without its non-nesting transaction wrappers. */
    public function __construct(PDO $pdo) {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('QuotaStore requires MariaDB PDO with exception error mode.');
        }
        $this->pdo = $pdo;
    }

    /** Store a core definition; application activation uses activate() instead. */
    public function create(int $runId, int $actorId, array $tables): int {
        return $this->transaction(function () use ($runId, $actorId, $tables) {
            $this->requireOwner($runId, $actorId);
            return $this->writeDefinition($runId, $tables);
        });
    }

    /** Caller already owns the quota/activation transaction. */
    private function writeDefinition(int $runId, array $tables): int {
        $rules = new QuotaRules($tables);
        foreach ($rules->getGroups() as $tableCode => $groups) {
            if (strlen((string) $tableCode) > 64) {
                throw new InvalidArgumentException('Die Tabellenkennung darf höchstens 64 Zeichen enthalten.');
            }
            foreach ($groups as $code => $group) {
                if (strlen((string) $code) > 255 || $group['limit'] > 4294967295) {
                    throw new InvalidArgumentException('Eine Gruppenkennung oder Sollzahl überschreitet den zulässigen Bereich.');
                }
            }
        }
        $this->query('INSERT INTO survey_quotas (run_id) VALUES (?)', [$runId]);
        $quotaId = (int) $this->pdo->lastInsertId();
        foreach ($tables as $code => $table) {
            $this->query('INSERT INTO survey_quota_tables (quota_id, code, dimensions_json) VALUES (?, ?, ?)',
                [$quotaId, (string) $code, json_encode($table['dimensions'], JSON_THROW_ON_ERROR)]);
            $tableId = (int) $this->pdo->lastInsertId();
            foreach ($rules->getGroups()[$code] as $groupCode => $group) {
                $this->query('INSERT INTO survey_quota_groups (quota_id, table_id, code, quota_limit) VALUES (?, ?, ?, ?)',
                    [$quotaId, $tableId, (string) $groupCode, $group['limit']]);
            }
        }
        return $quotaId;
    }

    /** Atomically pin both saved drafts and their exact Quota/Complete run-unit identities. */
    public function activate(int $runId, int $actorId): int {
        return $this->transaction(function () use ($runId, $actorId) {
            if (!$this->query('SELECT id FROM survey_runs WHERE id=? AND user_id=? FOR UPDATE', [$runId,$actorId])->fetch()) {
                throw new DomainException('Nur die verantwortliche Person kann die Quotierung aktivieren.');
            }
            $existing = $this->query('SELECT quota_id FROM survey_quota_lifecycle WHERE run_id=?', [$runId])->fetchColumn();
            if ($existing !== false) { return (int) $existing; }
            $draft = $this->query('SELECT run_unit_id,tables_json FROM survey_quota_drafts WHERE run_id=? FOR UPDATE', [$runId])->fetch(PDO::FETCH_ASSOC);
            $exit = $this->query('SELECT complete_run_unit_id,provider_json FROM survey_quota_exit_drafts WHERE run_id=? FOR UPDATE', [$runId])->fetch(PDO::FETCH_ASSOC);
            if (!$draft || !$exit) { throw new DomainException('Bitte vor der Aktivierung sowohl die Quotentabellen als auch die Rückleitung im Abschlussbaustein speichern.'); }
            $tables = json_decode($draft['tables_json'], true, 512, JSON_THROW_ON_ERROR);
            (new QuotaEditor($this->pdo))->validateConfiguration($runId,(int) $draft['run_unit_id'],$actorId,$tables);
            $config = new QuotaProviderConfig(json_decode($exit['provider_json'],true,512,JSON_THROW_ON_ERROR));
            $units = $this->query('SELECT ru.id,ru.position,u.type FROM survey_run_units ru
                JOIN survey_units u ON u.id=ru.unit_id WHERE ru.run_id=? AND u.type IN ("Quota","Complete")', [$runId])->fetchAll(PDO::FETCH_ASSOC);
            $quotaUnits = array_values(array_filter($units, fn($unit) => $unit['type'] === 'Quota'));
            $completeUnits = array_values(array_filter($units, fn($unit) => $unit['type'] === 'Complete'));
            if (count($quotaUnits) !== 1 || count($completeUnits) !== 1
                || (int) $quotaUnits[0]['id'] !== (int) $draft['run_unit_id']
                || (int) $completeUnits[0]['id'] !== (int) $exit['complete_run_unit_id']
                || (int) $completeUnits[0]['position'] <= (int) $quotaUnits[0]['position']) {
                throw new DomainException('Bitte genau ein Quotenmodul und danach genau einen Abschlussbaustein in diesem Run verwenden.');
            }
            if ($this->query('SELECT position FROM survey_run_units WHERE run_id=? GROUP BY position HAVING COUNT(*)>1', [$runId])->fetch()) {
                throw new DomainException('Vor der Aktivierung müssen alle Run-Positionen eindeutig sein.');
            }
            $quotaId = $this->writeDefinition($runId,$tables);
            $this->query('INSERT INTO survey_quota_lifecycle
                (quota_id,run_id,quota_run_unit_id,complete_run_unit_id,provider_json,activated_by) VALUES (?,?,?,?,?,?)',
                [$quotaId,$runId,$draft['run_unit_id'],$exit['complete_run_unit_id'],json_encode($config->toArray(),JSON_THROW_ON_ERROR),$actorId]);
            return $quotaId;
        });
    }

    /**
     * Load saved answers inside the transaction; never accept answers from a browser directly.
     * The callback is a database-only loader. Existing decisions bypass it and stay unchanged.
     */
    public function admit(int $runId, int $quotaId, int $sessionId, callable $loadAnswers): array {
        return $this->withQuota($runId, $quotaId, function () use ($quotaId, $runId, $sessionId, $loadAnswers) {
            $cohort = $this->participation($runId, $sessionId);
            $definitions = $this->definitions($quotaId);
            $existing = $this->decision($quotaId, $sessionId);
            if ($existing) {
                return $this->result($existing, $cohort, $definitions);
            }
            $rules = new QuotaRules($definitions['tables']);
            $evaluation = $rules->evaluate($loadAnswers(), $this->counts($quotaId, $cohort, $definitions));
            $this->query('INSERT INTO survey_quota_decisions
                (quota_id, run_session_id, cohort, outcome, reason, rejected_table, rejected_source)
                VALUES (?, ?, ?, ?, ?, ?, ?)', [$quotaId, $sessionId, $cohort, $evaluation['status'],
                    $evaluation['reason'], $evaluation['table'] ?? null, $evaluation['source'] ?? null]);
            $decisionId = (int) $this->pdo->lastInsertId();
            foreach ($evaluation['assignments'] as $table => $group) {
                $identity = $definitions['groups'][$table][$group];
                $this->query('INSERT INTO survey_quota_assignments (decision_id, quota_id, table_id, group_id)
                    VALUES (?, ?, ?, ?)', [$decisionId, $quotaId, $identity['table_id'], $identity['id']]);
            }
            return $this->result($this->decision($quotaId, $sessionId), $cohort, $definitions);
        });
    }

    /** Use only saved survey data; existing decisions still bypass all source loading. */
    public function admitFromSurvey(int $runId, int $quotaId, int $sessionId, int $quotaRunUnitId): array {
        return $this->admit($runId, $quotaId, $sessionId, function () use ($runId, $quotaId, $sessionId, $quotaRunUnitId) {
            return (new QuotaAnswerLoader($this->pdo))->load($runId, $sessionId, $quotaRunUnitId,
                $this->definitions($quotaId)['tables']);
        });
    }

    /** Record one completion without rechecking capacity; repeated calls return the same record. */
    public function complete(int $runId, int $quotaId, int $sessionId, int $unitSessionId): array {
        return $this->withQuota($runId, $quotaId, function () use ($runId, $quotaId, $sessionId, $unitSessionId) {
            $cohort = $this->participation($runId, $sessionId);
            $unit = $this->query('SELECT us.id FROM survey_unit_sessions us
                JOIN survey_units u ON u.id = us.unit_id
                WHERE us.id = ? AND us.run_session_id = ? AND u.type = ?',
                [$unitSessionId, $sessionId, 'Complete'])->fetch();
            if (!$unit) {
                throw new DomainException('Completion requires this participation\'s Complete unit session.');
            }
            $decision = $this->decision($quotaId, $sessionId);
            if (!$decision || $decision['outcome'] !== 'admitted') {
                throw new DomainException('Completion requires a saved quota admission.');
            }
            $definitions = $this->definitions($quotaId);
            $result = $this->result($decision, $cohort, $definitions);
            if ($result['completed_at'] === null) {
                $this->query('INSERT INTO survey_quota_completions (decision_id, complete_unit_session_id) VALUES (?, ?)',
                    [$decision['id'], $unitSessionId]);
                $result = $this->result($decision, $cohort, $definitions);
            }
            return $result;
        });
    }

    /** Append an audited correction; retry keys are scoped to the quota and reject changed payloads. */
    public function adjust(int $runId, int $quotaId, string $table, string $group, string $cohort,
        int $delta, int $actorId, string $reason, string $operationKey): int {
        self::checkCohort($cohort);
        self::checkAudit($reason, $operationKey);
        if ($delta === 0) {
            throw new InvalidArgumentException('A correction must change the count.');
        }
        return $this->withQuota($runId, $quotaId, function () use ($runId, $quotaId, $table, $group,
            $cohort, $delta, $actorId, $reason, $operationKey) {
            $this->requireOwner($runId, $actorId);
            $definitions = $this->definitions($quotaId);
            $identity = $definitions['groups'][$table][$group] ?? null;
            if (!$identity) {
                throw new InvalidArgumentException('Unknown quota group.');
            }
            $existing = $this->query('SELECT * FROM survey_quota_adjustments WHERE quota_id = ? AND operation_key = ?',
                [$quotaId, $operationKey])->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ((int) $existing['group_id'] !== $identity['id'] || $existing['cohort'] !== $cohort
                    || self::integer($existing['delta']) !== $delta || (int) $existing['actor_id'] !== $actorId
                    || $existing['reason'] !== $reason) {
                    throw new DomainException('Correction retry key was reused with different values.');
                }
                return (int) $existing['id'];
            }
            $total = $this->counts($quotaId, $cohort, $definitions)[$table][$group] + $delta;
            if (!is_int($total) || $total < 0) {
                throw new DomainException('Correction would produce an invalid effective count.');
            }
            $this->query('INSERT INTO survey_quota_adjustments
                (quota_id, table_id, group_id, cohort, delta, actor_id, reason, operation_key)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$quotaId, $identity['table_id'], $identity['id'],
                    $cohort, $delta, $actorId, $reason, $operationKey]);
            return (int) $this->pdo->lastInsertId();
        });
    }

    /** Change only a limit, leaving saved admissions intact and auditing the change atomically. */
    public function setLimit(int $runId, int $quotaId, string $table, string $group, int $limit,
        int $actorId, string $reason, string $operationKey): int {
        self::checkAudit($reason, $operationKey);
        if ($limit < 0 || $limit > 4294967295) {
            throw new InvalidArgumentException('Limit is outside the supported storage range.');
        }
        return $this->withQuota($runId, $quotaId, function () use ($runId, $quotaId, $table, $group,
            $limit, $actorId, $reason, $operationKey) {
            $this->requireOwner($runId, $actorId);
            $definitions = $this->definitions($quotaId);
            $identity = $definitions['groups'][$table][$group] ?? null;
            if (!$identity) {
                throw new InvalidArgumentException('Unknown quota group.');
            }
            $existing = $this->query('SELECT * FROM survey_quota_limit_changes WHERE quota_id = ? AND operation_key = ?',
                [$quotaId, $operationKey])->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ((int) $existing['group_id'] !== $identity['id'] || self::integer($existing['new_limit']) !== $limit
                    || (int) $existing['actor_id'] !== $actorId || $existing['reason'] !== $reason) {
                    throw new DomainException('Limit retry key was reused with different values.');
                }
                return (int) $existing['id'];
            }
            $oldLimit = $definitions['tables'][$table]['limits'][$group];
            $this->query('UPDATE survey_quota_groups SET quota_limit = ? WHERE id = ?', [$limit, $identity['id']]);
            $this->query('INSERT INTO survey_quota_limit_changes
                (quota_id, table_id, group_id, old_limit, new_limit, actor_id, reason, operation_key)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$quotaId, $identity['table_id'], $identity['id'],
                    $oldLimit, $limit, $actorId, $reason, $operationKey]);
            return (int) $this->pdo->lastInsertId();
        });
    }

    /** Read a consistent effective count snapshot for exactly one cohort. */
    public function getCounts(int $runId, int $quotaId, string $cohort): array {
        self::checkCohort($cohort);
        return $this->withQuota($runId, $quotaId, function () use ($quotaId, $cohort) {
            return $this->counts($quotaId, $cohort, $this->definitions($quotaId));
        });
    }

    /** Regenerate combinations and check the stored group set before trusting it. */
    private function definitions(int $quotaId): array {
        $tables = $groups = [];
        foreach ($this->query('SELECT * FROM survey_quota_tables WHERE quota_id = ? ORDER BY id', [$quotaId]) as $row) {
            $table = $row['code'];
            $tables[$table] = ['dimensions' => json_decode($row['dimensions_json'], true, 512, JSON_THROW_ON_ERROR),
                'limits' => []];
            $groups[$table] = [];
            foreach ($this->query('SELECT * FROM survey_quota_groups WHERE table_id = ? AND quota_id = ? ORDER BY id',
                [$row['id'], $quotaId]) as $group) {
                $tables[$table]['limits'][$group['code']] = self::integer($group['quota_limit']);
                $groups[$table][$group['code']] = ['id' => (int) $group['id'], 'table_id' => (int) $row['id']];
            }
        }
        $rules = new QuotaRules($tables);
        foreach ($rules->getGroups() as $table => $expected) {
            if (array_diff_key($expected, $groups[$table]) || array_diff_key($groups[$table], $expected)) {
                throw new UnexpectedValueException('Stored quota groups do not match the definition.');
            }
        }
        return ['tables' => $tables, 'groups' => $groups];
    }

    /** Count completions and corrections independently to prevent join multiplication. */
    private function counts(int $quotaId, string $cohort, array $definitions): array {
        $completed = $this->query('SELECT a.group_id, COUNT(*) AS total FROM survey_quota_completions c
            JOIN survey_quota_decisions d ON d.id = c.decision_id
            JOIN survey_quota_assignments a ON a.decision_id = d.id
            WHERE d.quota_id = ? AND d.cohort = ? GROUP BY a.group_id', [$quotaId, $cohort])->fetchAll(PDO::FETCH_KEY_PAIR);
        $corrections = $this->query('SELECT group_id, SUM(delta) AS total FROM survey_quota_adjustments
            WHERE quota_id = ? AND cohort = ? GROUP BY group_id', [$quotaId, $cohort])->fetchAll(PDO::FETCH_KEY_PAIR);
        $counts = [];
        foreach ($definitions['groups'] as $table => $groups) {
            foreach ($groups as $code => $identity) {
                $id = $identity['id'];
                $total = self::integer($completed[$id] ?? 0) + self::integer($corrections[$id] ?? 0);
                if (!is_int($total) || $total < 0) {
                    throw new UnexpectedValueException('Invalid effective quota count.');
                }
                $counts[$table][$code] = $total;
            }
        }
        return $counts;
    }

    /** Check saved assignment completeness before returning any successful result. */
    private function result(array $decision, string $cohort, array $definitions): array {
        if ($decision['cohort'] !== $cohort) {
            throw new DomainException('Participation test status changed after its quota decision.');
        }
        $assignments = [];
        foreach ($this->query('SELECT t.code AS table_code, g.code AS group_code FROM survey_quota_assignments a
            JOIN survey_quota_tables t ON t.id = a.table_id
            JOIN survey_quota_groups g ON g.id = a.group_id
            WHERE a.decision_id = ? ORDER BY t.id', [$decision['id']]) as $row) {
            if (!isset($definitions['groups'][$row['table_code']][$row['group_code']])) {
                throw new UnexpectedValueException('Saved assignment no longer matches this quota.');
            }
            $assignments[$row['table_code']] = $row['group_code'];
        }
        if (($decision['outcome'] === 'admitted' && count($assignments) !== count($definitions['tables']))
            || ($decision['outcome'] === 'rejected' && $assignments)) {
            throw new UnexpectedValueException('Saved quota assignments are incomplete or inconsistent.');
        }
        $completion = $this->query('SELECT completed_at FROM survey_quota_completions WHERE decision_id = ?',
            [$decision['id']])->fetchColumn();
        return ['decision_id' => (int) $decision['id'], 'status' => $decision['outcome'], 'cohort' => $cohort,
            'reason' => $decision['reason'], 'table' => $decision['rejected_table'],
            'source' => $decision['rejected_source'], 'assignments' => $assignments,
            'completed_at' => $completion === false ? null : $completion];
    }

    /** Load the single persisted decision for this participation and quota. */
    private function decision(int $quotaId, int $sessionId) {
        return $this->query('SELECT * FROM survey_quota_decisions WHERE quota_id = ? AND run_session_id = ?',
            [$quotaId, $sessionId])->fetch(PDO::FETCH_ASSOC);
    }

    /** Lock the participation and derive cohort from trusted DB state, never request parameters. */
    private function participation(int $runId, int $sessionId): string {
        $row = $this->query('SELECT testing FROM survey_run_sessions WHERE id = ? AND run_id = ? FOR UPDATE',
            [$sessionId, $runId])->fetch(PDO::FETCH_ASSOC);
        if (!$row || !in_array($row['testing'], [null, 0, 1, '0', '1'], true)) {
            throw new DomainException('Missing or invalid participation in this run.');
        }
        return (int) $row['testing'] === 1 ? 'test' : 'field';
    }

    /** Administrative mutations require the run owner; broader admin policy is not implicit. */
    private function requireOwner(int $runId, int $actorId): void {
        if ($actorId <= 0 || !$this->query('SELECT id FROM survey_runs WHERE id = ? AND user_id = ?',
            [$runId, $actorId])->fetch()) {
            throw new DomainException('Quota mutation requires the run owner.');
        }
    }

    /** Bind all values; table names above are fixed program constants. */
    private function query(string $sql, array $values = []): PDOStatement {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);
        return $statement;
    }

    /** MariaDB may return aggregate integers as strings; reject overflow rather than truncate. */
    private static function integer($value): int {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new UnexpectedValueException('Database quota integer is outside the supported range.');
        }
        return $integer;
    }

    private static function checkCohort(string $cohort): void {
        if (!in_array($cohort, ['field', 'test'], true)) {
            throw new InvalidArgumentException('Unknown quota cohort.');
        }
    }

    private static function checkAudit(string $reason, string $key): void {
        if (trim($reason) === '' || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $key)) {
            throw new InvalidArgumentException('Audit reason and valid retry key are required.');
        }
    }

    /** Always lock quota first; all store writers and snapshots use this same order. */
    private function withQuota(int $runId, int $quotaId, callable $operation) {
        return $this->transaction(function () use ($runId, $quotaId, $operation) {
            if (!$this->query('SELECT id FROM survey_quotas WHERE id = ? AND run_id = ? FOR UPDATE',
                [$quotaId, $runId])->fetch()) {
                throw new DomainException('Quota does not belong to this run.');
            }
            return $operation();
        });
    }

    /** Refuse caller-owned transactions instead of accidentally committing their work. */
    private function transaction(callable $operation) {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('QuotaStore must own its transaction.');
        }
        // A locking read must precede fresh count reads, including after waiting for a writer.
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
