<?php

/** Revocable provider access to aggregate field quotas only. */
class QuotaMonitor {
    public function __construct(private PDO $pdo) {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql' || $pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('Quota monitor requires MariaDB PDO with exception error mode.');
        }
    }

    public function key(int $runId,int $actorId): ?string {
        $this->owner($runId,$actorId);
        $key=$this->query('SELECT access_key FROM survey_quota_shares WHERE run_id=?',[$runId])->fetchColumn();
        return $key===false ? null : $key;
    }

    public function enable(int $runId,int $actorId): string {
        return $this->manage($runId,$actorId,true);
    }

    public function revoke(int $runId,int $actorId): void {
        $this->manage($runId,$actorId,false);
    }

    private function manage(int $runId,int $actorId,bool $enable): ?string {
        if ($this->pdo->inTransaction()) { throw new LogicException('Quota sharing must own its transaction.'); }
        $this->pdo->beginTransaction();
        try {
            $this->owner($runId,$actorId,true);
            if ($enable) {
                $key=$this->query('SELECT access_key FROM survey_quota_shares WHERE run_id=?',[$runId])->fetchColumn();
                if ($key===false) {
                    $key=bin2hex(random_bytes(32));
                    $this->query('INSERT INTO survey_quota_shares (run_id,access_key) VALUES (?,?)',[$runId,$key]);
                }
            } else {
                $this->query('DELETE FROM survey_quota_shares WHERE run_id=?',[$runId]);
                $key=null;
            }
            $this->pdo->commit();
            return $key;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    /** No request-controlled cohort or mutation: return only the display allowlist. */
    public function read(string $runName,string $key): ?array {
        if (!preg_match('/\A[0-9a-f]{64}\z/',$key)) { return null; }
        $run=$this->query('SELECT r.id,r.user_id,r.name,s.access_key FROM survey_runs r
            JOIN survey_quota_shares s ON s.run_id=r.id WHERE r.name=?',[$runName])->fetch(PDO::FETCH_ASSOC);
        if (!$run || !hash_equals($run['access_key'],$key)) { return null; }
        $snapshot=(new QuotaOverview($this->pdo))->read((int)$run['id'],(int)$run['user_id'],'field');
        return ['name'=>$run['name'],'completed'=>$snapshot['completed'],'groups'=>$snapshot['groups']];
    }

    private function owner(int $runId,int $actorId,bool $lock=false): void {
        if ($actorId<=0 || !$this->query('SELECT id FROM survey_runs WHERE id=? AND user_id=?'.($lock?' FOR UPDATE':''),
            [$runId,$actorId])->fetch()) { throw new DomainException('Only the run owner can manage provider links.'); }
    }

    private function query(string $sql,array $values): PDOStatement {
        $statement=$this->pdo->prepare($sql); $statement->execute($values); return $statement;
    }
}
