<?php

/** Portable definitions only: no database identities, provider profile or counting history. */
class QuotaStructure {
    public function __construct(private PDO $pdo) {}

    public function exportQuota(int $runId, int $unitId, int $ownerId): array {
        $tables = (new QuotaEditor($this->pdo))->load($runId, $unitId, $ownerId);
        $query = $this->pdo->prepare('SELECT ru.id,ru.position FROM survey_run_units ru
            JOIN survey_units u ON u.id=ru.unit_id WHERE ru.run_id=? AND u.type="Survey"');
        $query->execute([$runId]);
        $positions = $query->fetchAll(PDO::FETCH_KEY_PAIR);
        return ['version'=>1, 'tables'=>self::portableTables($tables, $positions)];
    }

    public static function portableTables(array $tables, array $positions): array {
        $result = [];
        foreach ($tables as $code=>$table) {
            $dimensions = [];
            foreach ($table['dimensions'] as $index=>$dimension) {
                $position = $positions[$dimension['run_unit_id'] ?? 0] ?? null;
                $dimensions[] = ['source'=>self::source($position, $dimension['item_name'], $index),
                    'survey_position'=>$position === null ? null : (int) $position,
                    'item_name'=>$dimension['item_name'], 'type'=>$dimension['type'],
                    'categories'=>$dimension['categories']];
            }
            $result[$code] = ['dimensions'=>$dimensions, 'limits'=>$table['limits'] ?? []];
        }
        return $result;
    }

    /** Resolve only surveys actually created by THIS import; never guess by name or old ID. */
    public static function localTables(array $definition, array $importedSurveys): array {
        if (($definition['version'] ?? null) !== 1 || !isset($definition['tables']) || !is_array($definition['tables'])) {
            throw new InvalidArgumentException('Unsupported or missing portable quota definition.');
        }
        $result = [];
        foreach ($definition['tables'] as $code=>$table) {
            if (!is_array($table) || empty($table['dimensions']) || !is_array($table['dimensions'])
                || !array_is_list($table['dimensions'])) {
                throw new InvalidArgumentException('Invalid portable quota dimensions.');
            }
            $dimensions = [];
            foreach ($table['dimensions'] as $index=>$dimension) {
                if (!is_array($dimension) || !is_string($dimension['item_name'] ?? null)
                    || !preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/', $dimension['item_name'])
                    || !array_key_exists('survey_position', $dimension)
                    || ($dimension['survey_position'] !== null && !is_int($dimension['survey_position']))) {
                    throw new InvalidArgumentException('Invalid portable quota source.');
                }
                $position = $dimension['survey_position'];
                $id = $position === null ? 0 : ($importedSurveys[$position] ?? 0);
                $dimensions[] = ['source'=>$id ? 'survey_'.$id.'.'.$dimension['item_name']
                        : self::source($position, $dimension['item_name'], $index),
                    'run_unit_id'=>$id, 'item_name'=>$dimension['item_name'],
                    'type'=>$dimension['type'] ?? null, 'categories'=>$dimension['categories'] ?? []];
            }
            $result[$code] = ['dimensions'=>$dimensions, 'limits'=>$table['limits'] ?? []];
        }
        if ($result) { new QuotaRules($result); }
        return $result;
    }

    private static function source(?int $position, string $item, int $index): string {
        return ($position === null ? 'unresolved_'.$index : 'position_'.$position).'.'.$item;
    }

    /** Validate before creating any units; external settings/identity fields are never executable. */
    public static function prepareImport(array $units): array {
        $hasQuota = false;
        foreach ($units as $unit) { $hasQuota = $hasQuota || in_array($unit->type ?? null, ['Quota','Complete'], true); }
        if (!$hasQuota) { return $units; }
        $seen = $types = [];
        foreach ($units as $index=>$unit) {
            if (!isset($unit->position) || !is_numeric($unit->position) || (int) $unit->position != $unit->position
                || isset($seen[(int) $unit->position])) {
                throw new InvalidArgumentException('Quota imports require unique whole-number unit positions.');
            }
            $seen[(int) $unit->position] = true;
            if (!in_array($unit->type ?? null, ['Quota','Complete'], true)) { continue; }
            if (!empty($unit->special) || isset($types[$unit->type])) {
                throw new InvalidArgumentException('Import at most one regular Quota and one Complete unit.');
            }
            $types[$unit->type] = true;
            $safe = ['type'=>$unit->type, 'position'=>(int) $unit->position, 'description'=>(string) ($unit->description ?? '')];
            if ($unit->type === 'Quota') {
                $definition = json_decode(json_encode($unit->quota_definition ?? null, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($definition)) { throw new InvalidArgumentException('Missing portable quota definition.'); }
                self::localTables($definition, []);
                $safe['quota_definition'] = $definition;
            } else {
                // Study-specific profiles are intentionally excluded, even from hand-edited JSON.
                $safe['provider_mode'] = ($unit->provider_mode ?? null) === 'none' ? 'none' : '';
                if (isset($unit->completion_page)) {
                    $safe['completion_page']=QuotaCompletionPage::validate((array)$unit->completion_page);
                }
            }
            $units[$index] = (object) $safe;
        }
        return $units;
    }

    public function assertImportTarget(int $runId, int $ownerId, array $units, int $offset = 0): void {
        $types = array_values(array_filter(array_column($units, 'type'), static fn($type)=>in_array($type, ['Quota','Complete'], true)));
        if (!$types) { return; }
        $query = $this->pdo->prepare('SELECT id FROM survey_runs WHERE id=? AND user_id=?');
        $query->execute([$runId,$ownerId]);
        if (!$query->fetchColumn()) { throw new DomainException('Quota import requires the run owner.'); }
        $query = $this->pdo->prepare('SELECT id FROM survey_quotas WHERE run_id=?');
        $query->execute([$runId]);
        if ($query->fetchColumn()) { throw new DomainException('Import quota definitions into a new, inactive run.'); }
        $query = $this->pdo->prepare('SELECT u.type FROM survey_run_units ru JOIN survey_units u ON u.id=ru.unit_id
            WHERE ru.run_id=? AND u.type IN ("Quota","Complete")');
        $query->execute([$runId]);
        if (array_intersect($types, $query->fetchAll(PDO::FETCH_COLUMN))) {
            throw new DomainException('This run already contains one of the Quota or Complete units being imported.');
        }
        $query = $this->pdo->prepare('SELECT position FROM survey_run_units WHERE run_id=?');
        $query->execute([$runId]);
        $existing = $query->fetchAll(PDO::FETCH_COLUMN);
        foreach ($units as $unit) {
            if (!isset($unit->position)) { continue; }
            $position = $offset + (int) $unit->position;
            if ($position < -32768 || $position > 32767 || in_array($position,$existing)) {
                throw new InvalidArgumentException('Imported positions must fit the run and not overlap existing units.');
            }
        }
    }

    /** Atomic INSERT ... SELECT also works in the API caller's existing transaction; never commits it. */
    public function importQuota(int $runId, int $unitId, int $ownerId, array $definition, array $surveys): bool {
        $tables = self::localTables($definition, $surveys);
        $editor = new QuotaEditor($this->pdo);
        $editor->assertUnit($runId,$unitId,$ownerId);
        if (!$tables) { return true; }
        $ready = true;
        try { $editor->validateConfiguration($runId,$unitId,$ownerId,$tables); }
        catch (InvalidArgumentException $error) { $ready = false; }
        $query = $this->pdo->prepare('INSERT INTO survey_quota_drafts (run_id,run_unit_id,tables_json)
            SELECT r.id,ru.id,? FROM survey_runs r JOIN survey_run_units ru ON ru.run_id=r.id
            JOIN survey_units u ON u.id=ru.unit_id WHERE r.id=? AND r.user_id=? AND ru.id=? AND u.type="Quota"
            AND NOT EXISTS (SELECT 1 FROM survey_quotas q WHERE q.run_id=r.id)');
        $query->execute([json_encode($tables,JSON_THROW_ON_ERROR),$runId,$ownerId,$unitId]);
        if ($query->rowCount() !== 1) { throw new DomainException('Cannot import a quota draft into an active run.'); }
        return $ready;
    }

    public function importComplete(int $runId, int $unitId, int $ownerId, string $mode): void {
        // Only an explicit no-provider export may use that mode. Imported panel runs
        // need fresh settings; do not let the new-unit default silently change them.
        $provider = ['mode'=>$mode === 'none' ? 'none' : 'provider'];
        $query = $this->pdo->prepare('INSERT INTO survey_quota_exit_drafts (run_id,complete_run_unit_id,provider_json)
            SELECT r.id,ru.id,? FROM survey_runs r JOIN survey_run_units ru ON ru.run_id=r.id
            JOIN survey_units u ON u.id=ru.unit_id WHERE r.id=? AND r.user_id=? AND ru.id=? AND u.type="Complete"
            AND NOT EXISTS (SELECT 1 FROM survey_quota_lifecycle q WHERE q.run_id=r.id)');
        $query->execute([json_encode($provider,JSON_THROW_ON_ERROR),$runId,$ownerId,$unitId]);
        if ($query->rowCount() !== 1) { throw new DomainException('Cannot import Complete settings into an active run.'); }
    }
}
