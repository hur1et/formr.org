<?php

/** Researcher overview: saved groups, cohort counts and whole-page edits. */
class QuotaOverview {
    public function __construct(private PDO $pdo) {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql' || $pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('Quota overview requires MariaDB PDO with exception error mode.');
        }
    }

    public function read(int $runId,int $actorId,string $cohort): array {
        $this->owner($runId,$actorId);
        self::cohort($cohort);
        $quotaId=(int)$this->query('SELECT id FROM survey_quotas WHERE run_id=?',[$runId])->fetchColumn();
        $unitId=(int)$this->query('SELECT ru.id FROM survey_run_units ru JOIN survey_units u ON u.id=ru.unit_id
            WHERE ru.run_id=? AND u.type="Quota" ORDER BY ru.position LIMIT 1',[$runId])->fetchColumn();
        $snapshot=$quotaId ? (new QuotaStore($this->pdo))->overview($runId,$quotaId,$cohort,$actorId)
            : ['tables'=>$unitId ? (new QuotaEditor($this->pdo))->load($runId,$unitId,$actorId) : [],'counts'=>[],'completed'=>0];
        $snapshot['quota_id']=$quotaId;
        $snapshot['definition_hash']=QuotaStore::definitionHash($snapshot['tables']);
        $snapshot['groups']=[];
        $sources=$unitId ? (new QuotaEditor($this->pdo))->sources($runId,$unitId,$actorId) : [];
        if ($snapshot['tables']) {
            foreach ((new QuotaRules($snapshot['tables']))->getGroups() as $table=>$groups) {
                foreach ($groups as $code=>$group) {
                    $labels=[];
                    foreach ($snapshot['tables'][$table]['dimensions'] as $dimension) {
                        $category=$dimension['categories'][$group['categories'][$dimension['source']]];
                        $label=$dimension['type']==='integer' ? $category['min'].'–'.$category['max'] : (string)$category;
                        foreach ($sources[$dimension['source']]['choices']??[] as $choice) {
                            if ($dimension['type']==='category' && (string)$choice['value']===(string)$category) { $label=$choice['label'].' ('.$category.')'; break; }
                        }
                        $labels[]=($dimension['item_name']??$dimension['source']).': '.$label;
                    }
                    $snapshot['groups'][$table][$code]=['labels'=>$labels,'limit'=>$group['limit'],
                        'count'=>$snapshot['counts'][$table][$code]??0];
                }
            }
        }
        return $snapshot;
    }

    public function save(int $runId,int $actorId,string $cohort,int $expectedQuotaId,
        string $definitionHash,array $input,string $operationKey): void {
        $this->owner($runId,$actorId);
        self::cohort($cohort);
        $rows=self::parseRows($input);
        $quotaId=(int)$this->query('SELECT id FROM survey_quotas WHERE run_id=?',[$runId])->fetchColumn();
        if ($quotaId!==$expectedQuotaId) { throw new DomainException('Quota configuration changed. Refresh before saving.'); }
        if ($quotaId) {
            (new QuotaStore($this->pdo))->saveOverview($runId,$quotaId,$cohort,$rows,$actorId,$operationKey,$definitionHash);
            return;
        }
        if ($this->pdo->inTransaction()) { throw new LogicException('Quota overview must own its transaction.'); }
        $this->pdo->beginTransaction();
        try {
            if (!$this->query('SELECT id FROM survey_runs WHERE id=? AND user_id=? FOR UPDATE',[$runId,$actorId])->fetch()) {
                throw new DomainException('Only the run owner can edit quotas.');
            }
            if ($this->query('SELECT id FROM survey_quotas WHERE run_id=?',[$runId])->fetch()) {
                throw new DomainException('Quota configuration changed. Refresh before saving.');
            }
            $draft=$this->query('SELECT tables_json FROM survey_quota_drafts WHERE run_id=?',[$runId])->fetchColumn();
            if ($draft===false) { throw new DomainException('Set up quota groups in Edit Run first.'); }
            $tables=json_decode($draft,true,512,JSON_THROW_ON_ERROR);
            if (!hash_equals(QuotaStore::definitionHash($tables),$definitionHash)) { throw new DomainException('Quota groups changed. Refresh before saving.'); }
            $groups=(new QuotaRules($tables))->getGroups();
            if (array_keys($rows)!==self::sortedKeys($groups)) { throw new InvalidArgumentException('Submit all quota tables together.'); }
            foreach ($groups as $table=>$definitions) {
                if (array_keys($rows[$table])!==self::sortedKeys($definitions)) { throw new InvalidArgumentException('The submitted quota groups do not match.'); }
                foreach ($definitions as $group=>$definition) {
                    $row=$rows[$table][$group];
                    if ($row['count']!==0 || $row['base_count']!==0) { throw new DomainException('There are no completed participants to correct.'); }
                    if ($row['limit']!==$row['base_limit'] && $definition['limit']!==$row['base_limit'] && $definition['limit']!==$row['limit']) {
                        throw new DomainException('A limit changed while you were editing. Refresh and try again.');
                    }
                    if ($row['limit']!==$row['base_limit']) { $tables[$table]['limits'][$group]=$row['limit']; }
                }
            }
            $this->query('UPDATE survey_quota_drafts SET tables_json=?,modified=CURRENT_TIMESTAMP(6) WHERE run_id=?',
                [json_encode($tables,JSON_THROW_ON_ERROR),$runId]);
            $this->pdo->commit();
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } throw $error; }
    }

    public static function parseRows(array $input): array {
        if (!$input) { throw new InvalidArgumentException('No quota groups submitted.'); }
        $rows=[];
        foreach ($input as $table=>$groups) {
            if (!is_array($groups) || !$groups) { throw new InvalidArgumentException('Invalid quota table.'); }
            foreach ($groups as $group=>$row) {
                if (!is_array($row)) { throw new InvalidArgumentException('Invalid quota group.'); }
                foreach (['limit','base_limit','count','base_count'] as $field) {
                    $value=$row[$field]??null;
                    if ((!is_string($value) && !is_int($value)) || !preg_match('/\A(?:0|[1-9][0-9]*)\z/',(string)$value)
                        || filter_var($value,FILTER_VALIDATE_INT)===false
                        || (str_contains($field,'limit') && (int)$value>4294967295)) {
                        throw new InvalidArgumentException('Limits and counts must be nonnegative whole numbers.');
                    }
                    $rows[$table][$group][$field]=(int)$value;
                }
                if (!is_string($row['reason']??'') || strlen($row['reason']??'')>2000) { throw new InvalidArgumentException('Give a short correction reason.'); }
                $rows[$table][$group]['reason']=trim($row['reason']??'');
            }
            ksort($rows[$table],SORT_STRING);
        }
        ksort($rows,SORT_STRING);
        return $rows;
    }

    private static function sortedKeys(array $map): array { $keys=array_keys($map); sort($keys,SORT_STRING); return $keys; }
    private static function cohort(string $cohort): void {
        if (!in_array($cohort,['field','test'],true)) { throw new InvalidArgumentException('Unknown quota cohort.'); }
    }
    private function owner(int $runId,int $actorId): void {
        if (!$this->query('SELECT id FROM survey_runs WHERE id=? AND user_id=?',[$runId,$actorId])->fetch()) { throw new DomainException('Only the run owner can access quotas.'); }
    }
    private function query(string $sql,array $values): PDOStatement { $s=$this->pdo->prepare($sql); $s->execute($values); return $s; }
}
