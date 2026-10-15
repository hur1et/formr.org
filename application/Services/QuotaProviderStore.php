<?php

/** One provider profile per run, with a separate immutable identity per participation. */
class QuotaProviderStore {
    public function __construct(private PDO $pdo) {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('Provider store requires MariaDB PDO with exception error mode.');
        }
    }

    public function active(int $runId): ?array {
        $row = $this->query('SELECT * FROM survey_quota_lifecycle WHERE run_id=?', [$runId])->fetch(PDO::FETCH_ASSOC);
        if (!$row) { return null; }
        $row['provider'] = new QuotaProviderConfig(json_decode($row['provider_json'],true,512,JSON_THROW_ON_ERROR));
        return $row;
    }

    public function settings(int $runId, int $unitId, int $actorId): array {
        $this->requireComplete($runId,$unitId,$actorId);
        $active = $this->active($runId);
        if ($active) {
            if ((int) $active['complete_run_unit_id'] !== $unitId) { throw new DomainException('This is not the configured Complete unit.'); }
            return ['active'=>true,'provider'=>$active['provider']->toArray()];
        }
        $json = $this->query('SELECT provider_json FROM survey_quota_exit_drafts WHERE run_id=? AND complete_run_unit_id=?',
            [$runId,$unitId])->fetchColumn();
        return ['active'=>false,'provider'=>$json === false ? ['mode'=>''] : json_decode($json,true,512,JSON_THROW_ON_ERROR)];
    }

    public function save(int $runId, int $unitId, int $actorId, array $config): void {
        $config = new QuotaProviderConfig($config);
        $this->transaction(function () use ($runId,$unitId,$actorId,$config) {
            if (!$this->query('SELECT id FROM survey_runs WHERE id=? AND user_id=? FOR UPDATE', [$runId,$actorId])->fetch()) {
                throw new DomainException('Provider settings require the run owner.');
            }
            $this->requireComplete($runId,$unitId,$actorId);
            if ($this->active($runId)) { throw new DomainException('Activated provider settings cannot be replaced.'); }
            $other = $this->query('SELECT complete_run_unit_id FROM survey_quota_exit_drafts WHERE run_id=?', [$runId])->fetchColumn();
            if ($other !== false && (int) $other !== $unitId) { throw new DomainException('Use one Complete unit per run.'); }
            $this->query('INSERT INTO survey_quota_exit_drafts (run_id,complete_run_unit_id,provider_json) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE provider_json=VALUES(provider_json)',
                [$runId,$unitId,json_encode($config->toArray(),JSON_THROW_ON_ERROR)]);
        });
    }

    /** Called on entry before running surveys. Missing identity is deliberately not an entry gate. */
    public function capture(int $runId, int $sessionId, array $query): void {
        $active = $this->active($runId);
        $parameter = $active ? $active['provider']->entryParameter() : null;
        if ($parameter === null || !array_key_exists($parameter,$query) || $query[$parameter] === '') { return; }
        $identity = $query[$parameter];
        if (!is_string($identity) || strlen($identity)>1024 || preg_match('//u',$identity)!==1
            || preg_match('/[\x00-\x1f\x7f]/',$identity)) {
            throw new DomainException('Invalid provider identity received.');
        }
        $this->transaction(function () use ($runId,$sessionId,$active,$identity) {
            $this->query('SELECT id FROM survey_quotas WHERE id=? AND run_id=? FOR UPDATE', [$active['quota_id'],$runId])->fetch();
            if (!$this->query('SELECT id FROM survey_run_sessions WHERE id=? AND run_id=? FOR UPDATE', [$sessionId,$runId])->fetch()) {
                throw new DomainException('Provider identity requires a participation in this run.');
            }
            $saved = $this->query('SELECT provider_id FROM survey_quota_provider_ids WHERE run_session_id=?', [$sessionId])->fetchColumn();
            if ($saved !== false) {
                if (!hash_equals($saved,$identity)) { throw new DomainException('The provider identity cannot change during this participation.'); }
                return;
            }
            $this->query('INSERT INTO survey_quota_provider_ids (run_session_id,quota_id,provider_id) VALUES (?,?,?)',
                [$sessionId,$active['quota_id'],$identity]);
        });
    }

    public function identity(int $quotaId, int $sessionId): ?string {
        $value = $this->query('SELECT provider_id FROM survey_quota_provider_ids WHERE quota_id=? AND run_session_id=?',
            [$quotaId,$sessionId])->fetchColumn();
        return $value === false ? null : $value;
    }

    private function requireComplete(int $runId, int $unitId, int $actorId): void {
        if (!$this->query('SELECT ru.id FROM survey_run_units ru JOIN survey_runs r ON r.id=ru.run_id
            JOIN survey_units u ON u.id=ru.unit_id WHERE ru.id=? AND ru.run_id=? AND r.user_id=? AND u.type="Complete"',
            [$unitId,$runId,$actorId])->fetch()) { throw new DomainException('Provider settings require this run owner and Complete unit.'); }
    }

    private function transaction(callable $operation) {
        if ($this->pdo->inTransaction()) { throw new LogicException('Provider store must own its transaction.'); }
        $this->pdo->beginTransaction();
        try { $result=$operation(); $this->pdo->commit(); return $result; }
        catch (Throwable $error) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } throw $error; }
    }

    private function query(string $sql, array $values): PDOStatement {
        $statement=$this->pdo->prepare($sql); $statement->execute($values); return $statement;
    }
}
