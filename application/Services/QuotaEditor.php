<?php

/** Owner-only editor drafts. Saving here never creates an active counting definition. */
class QuotaEditor {
    public function __construct(private PDO $pdo) {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('Quota editor requires MariaDB PDO with exception error mode.');
        }
    }

    public function assertUnit(int $runId, int $runUnitId, int $actorId): array {
        $row = $this->query('SELECT ru.position FROM survey_run_units ru JOIN survey_runs r ON r.id=ru.run_id
            JOIN survey_units u ON u.id=ru.unit_id WHERE ru.id=? AND ru.run_id=? AND r.user_id=? AND u.type=?',
            [$runUnitId, $runId, $actorId, 'Quota'])->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new DomainException('Quota editor requires the run owner and its Quota unit.'); }
        return $row;
    }

    public function sources(int $runId, int $runUnitId, int $actorId): array {
        $unit = $this->assertUnit($runId, $runUnitId, $actorId);
        $rows = $this->query('SELECT ru.id AS run_unit_id, ru.description AS survey_label, s.id AS study_id,
            i.name, i.label, i.type, i.choice_list, c.DATA_TYPE AS storage_type,
            (SELECT COUNT(*) FROM survey_studies owner WHERE BINARY owner.results_table=BINARY s.results_table) AS result_owners
            FROM survey_run_units ru JOIN survey_units u ON u.id=ru.unit_id AND u.type="Survey"
            JOIN survey_studies s ON s.id=ru.unit_id AND s.user_id=? AND s.valid=1 AND s.unlinked=0
            JOIN survey_items i ON i.study_id=s.id
            LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=DATABASE()
                AND BINARY t.TABLE_NAME=BINARY s.results_table AND t.TABLE_TYPE="BASE TABLE"
            LEFT JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME
                AND BINARY c.TABLE_NAME=BINARY s.results_table AND BINARY c.COLUMN_NAME=BINARY i.name
            WHERE ru.run_id=? AND ru.position<? ORDER BY ru.position, ru.id, i.name',
            [$actorId, $runId, $unit['position']])->fetchAll(PDO::FETCH_ASSOC);
        $sources = [];
        foreach ($rows as $row) {
            $type = str_replace('-', '_', $row['type'] ?? '');
            $kind = in_array($type, ['number', 'range', 'range_ticks'], true) ? 'integer'
                : (in_array($type, ['mc', 'mc_button', 'mc_heading', 'select_one', 'sex'], true) ? 'category' : null);
            $integers = ['tinyint','smallint','mediumint','int','bigint'];
            $storage = $kind === 'integer' ? $integers : array_merge($integers, ['char','varchar','tinytext','text','mediumtext','longtext']);
            $choices = $type === 'sex' ? [['value' => '1', 'label' => '♂'], ['value' => '2', 'label' => '♀']]
                : $this->query('SELECT name AS value, label FROM survey_item_choices WHERE study_id=? AND BINARY list_name=? ORDER BY BINARY name',
                    [$row['study_id'], $row['choice_list']])->fetchAll(PDO::FETCH_ASSOC);
            $supported = (int) $row['result_owners'] === 1 && $kind !== null && in_array($row['storage_type'], $storage, true)
                && ($kind !== 'category' || count($choices) > 0);
            $key = 'survey_' . $row['run_unit_id'] . '.' . $row['name'];
            $sources[$key] = ['source' => $key, 'run_unit_id' => (int) $row['run_unit_id'], 'item_name' => $row['name'],
                'label' => strip_tags($row['label'] ?? ''), 'survey_label' => $row['survey_label'],
                'type' => $kind, 'storage_type' => $row['storage_type'], 'choices' => $choices, 'supported' => $supported,
                'warning' => $supported ? '' : 'This item type, choice list or result column is not supported for quotas.'];
        }
        return $sources;
    }

    public function load(int $runId, int $runUnitId, int $actorId): array {
        $this->assertUnit($runId, $runUnitId, $actorId);
        $json = $this->query('SELECT tables_json FROM survey_quota_drafts WHERE run_id=? AND run_unit_id=?',
            [$runId, $runUnitId])->fetchColumn();
        return $json === false ? [] : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function save(int $runId, int $runUnitId, int $actorId, array $tables): array {
        if ($this->pdo->inTransaction()) { throw new LogicException('Quota editor must own its transaction.'); }
        $rules = new QuotaRules($tables);
        $this->pdo->beginTransaction();
        try {
            if (!$this->query('SELECT id FROM survey_runs WHERE id=? AND user_id=? FOR UPDATE', [$runId,$actorId])->fetch()) {
                throw new DomainException('Quota editor requires the run owner.');
            }
            if ($this->query('SELECT id FROM survey_quotas WHERE run_id=?', [$runId])->fetch()) {
                throw new DomainException('An existing counting definition cannot be edited as a draft.');
            }
            $existing = $this->query('SELECT run_unit_id FROM survey_quota_drafts WHERE run_id=?', [$runId])->fetchColumn();
            if ($existing !== false && (int) $existing !== $runUnitId) {
                throw new DomainException('Use multiple tables in one Quota unit per run.');
            }
            $this->validateConfiguration($runId, $runUnitId, $actorId, $tables);
            $this->query('INSERT INTO survey_quota_drafts (run_id,run_unit_id,tables_json) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE tables_json=VALUES(tables_json), modified=CURRENT_TIMESTAMP(6)',
                [$runId,$runUnitId,json_encode($tables, JSON_THROW_ON_ERROR)]);
            $this->pdo->commit();
            return $rules->getWarnings();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    /** Reused under the activation transaction, without starting/committing a transaction. */
    public function validateConfiguration(int $runId, int $runUnitId, int $actorId, array $tables): void {
        $rules = new QuotaRules($tables);
        $sources = $this->sources($runId, $runUnitId, $actorId);
        foreach ($tables as $table) {
            foreach ($table['dimensions'] as $dimension) {
                $source = $sources[$dimension['source']] ?? null;
                if (!$source || !$source['supported'] || $source['type'] !== $dimension['type']
                    || $source['run_unit_id'] !== ($dimension['run_unit_id'] ?? null)
                    || $source['item_name'] !== ($dimension['item_name'] ?? null)) {
                    throw new InvalidArgumentException('Select a supported, saved variable from a preceding survey.');
                }
                if ($source['type'] === 'category') {
                    $values = array_map('strval', array_column($source['choices'], 'value'));
                    if (count(array_unique($values)) !== count($values)) {
                        throw new InvalidArgumentException('Ambiguous survey choice codes.');
                    }
                    foreach ($dimension['categories'] as $value) {
                        if (!in_array((string) $value, $values, true)) {
                            throw new InvalidArgumentException('Quota categories must use stored answer codes.');
                        }
                        if (in_array($source['storage_type'], ['tinyint','smallint','mediumint','int','bigint'], true)) {
                            $integer = filter_var($value, FILTER_VALIDATE_INT);
                            if ($integer === false || (string) $integer !== (string) $value) {
                                throw new InvalidArgumentException('Integer storage cannot preserve this answer code.');
                            }
                        }
                    }
                }
            }
        }
        foreach ($rules->getGroups() as $code => $groups) {
            if (strlen((string) $code) > 64) { throw new InvalidArgumentException('Table code is too long.'); }
            foreach ($groups as $groupCode => $group) {
                if (strlen((string) $groupCode) > 255 || $group['limit'] > 4294967295) {
                    throw new InvalidArgumentException('Group code or limit exceeds the supported range.');
                }
            }
        }
    }

    private function query(string $sql, array $values): PDOStatement {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);
        return $statement;
    }
}
