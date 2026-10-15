<?php

/** Connect pinned run units to the quota ledger. No HTTP request is sent here. */
class QuotaRuntime {
    public function __construct(private PDO $pdo) {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('Quota runtime requires MariaDB PDO with exception error mode.');
        }
    }

    public function execute(string $kind, int $runId, int $sessionId, int $runUnitId, int $unitSessionId,
        bool $cron = false, string $pageContent = ''): array {
        if ($cron) { return ['wait_user'=>true]; }
        if (!in_array($kind,['Quota','Complete','screen_out'],true)) { throw new InvalidArgumentException('Unknown quota runtime unit.'); }
        $providers = new QuotaProviderStore($this->pdo);
        $active = $providers->active($runId);
        if (!$active) { return ['content'=>'<p>This study is temporarily unavailable. Please try again later.</p>']; }
        $type = $kind === 'screen_out' ? 'Page' : $kind;
        $unit = $this->query('SELECT rs.testing,rs.ended AS run_ended,ru.position FROM survey_unit_sessions us
            JOIN survey_run_sessions rs ON rs.id=us.run_session_id
            JOIN survey_run_units ru ON ru.id=us.run_unit_id AND ru.run_id=rs.run_id AND ru.unit_id=us.unit_id
            JOIN survey_units u ON u.id=us.unit_id
            WHERE us.id=? AND us.run_session_id=? AND rs.run_id=? AND ru.id=? AND u.type=?
            AND rs.current_unit_session_id=us.id AND us.state NOT IN ("EXPIRED","SUPERSEDED")',
            [$unitSessionId,$sessionId,$runId,$runUnitId,$type])->fetch(PDO::FETCH_ASSOC);
        $positions = $this->query('SELECT q.position AS quota_position,c.position AS complete_position FROM survey_run_units q
            JOIN survey_units qu ON qu.id=q.unit_id AND qu.type="Quota"
            JOIN survey_run_units c ON c.run_id=q.run_id JOIN survey_units cu ON cu.id=c.unit_id AND cu.type="Complete"
            WHERE q.run_id=? AND q.id=? AND c.id=?',
            [$runId,$active['quota_run_unit_id'],$active['complete_run_unit_id']])->fetch(PDO::FETCH_ASSOC);
        if (!$unit || !in_array($unit['testing'],[null,0,1,'0','1'],true) || !$positions
            || (int) $positions['quota_position'] >= (int) $positions['complete_position']) {
            throw new DomainException('Current quota run-unit identity or ordering is invalid.');
        }
        $store = new QuotaStore($this->pdo);
        if ($kind === 'Quota') {
            if ($runUnitId !== (int) $active['quota_run_unit_id']) { throw new DomainException('Wrong Quota occurrence.'); }
            $decision = $store->admitFromSurvey($runId,(int) $active['quota_id'],$sessionId,$runUnitId);
            if ($decision['status'] === 'admitted') {
                if ($unit['run_ended'] !== null) { throw new DomainException('An ended participation cannot advance.'); }
                return ['end_session'=>true,'move_on'=>true,'log'=>['result'=>'quota_admitted','result_log'=>'All quota tables admitted.']];
            }
            $outcome = 'quota_full';
        } elseif ($kind === 'Complete') {
            if ($runUnitId !== (int) $active['complete_run_unit_id']) { throw new DomainException('Wrong Complete occurrence.'); }
            if ($unit['run_ended'] !== null && !$this->query('SELECT c.decision_id FROM survey_quota_completions c
                JOIN survey_quota_decisions d ON d.id=c.decision_id WHERE d.quota_id=? AND d.run_session_id=?',
                [$active['quota_id'],$sessionId])->fetch()) {
                throw new DomainException('An already ended participation cannot create its first completion.');
            }
            // Persist first. Missing identity/redirect failure afterwards must not book again.
            $store->complete($runId,(int) $active['quota_id'],$sessionId,$unitSessionId);
            $outcome = 'complete';
        } else {
            if ((int) $unit['position'] >= (int) $positions['complete_position']
                || !$this->query('SELECT p.id FROM survey_pages p JOIN survey_run_units ru ON ru.unit_id=p.id
                    WHERE ru.run_id=? AND ru.id=? AND p.provider_exit="screen_out"', [$runId,$runUnitId])->fetch()) {
                throw new DomainException('Screen-out requires a configured Page before Complete.');
            }
            $outcome = 'screen_out';
        }
        $url = $active['provider']->resolve($outcome,$providers->identity((int) $active['quota_id'],$sessionId));
        $content = $pageContent ?: ($outcome === 'complete' ? '<p>Thank you. Your participation is complete.</p>'
            : '<p>Thank you. Your participation has ended.</p>');
        $test = (int) $unit['testing'] === 1;
        if ($test && $url !== null) {
            // Plain text only: even following a preview link would contact the real provider.
            $content .= '<p>Test exit: ' . htmlspecialchars($outcome,ENT_QUOTES,'UTF-8') . '</p><pre>'
                . htmlspecialchars($url,ENT_QUOTES,'UTF-8') . '</pre>';
        }
        $endedAt = $this->finish((int) $active['quota_id'],$runId,$sessionId,$unitSessionId,$outcome,$test);
        $output = ['quota_terminal'=>true,'ended_at'=>$endedAt,'content'=>$content];
        if (!$test && $url !== null) { $output['redirect']=$url; }
        return $output;
    }

    /** End the run and current unit together, leaving its position intact for redirect retries. */
    private function finish(int $quotaId, int $runId, int $sessionId, int $unitSessionId, string $outcome, bool $test): string {
        if ($this->pdo->inTransaction()) { throw new LogicException('Quota terminal state must own its transaction.'); }
        $this->pdo->beginTransaction();
        try {
            $this->query('SELECT id FROM survey_quotas WHERE id=? AND run_id=? FOR UPDATE', [$quotaId,$runId])->fetch();
            $session = $this->query('SELECT id,testing FROM survey_run_sessions
                WHERE id=? AND run_id=? AND current_unit_session_id=? FOR UPDATE', [$sessionId,$runId,$unitSessionId])->fetch();
            if (!$session || ((int) $session['testing'] === 1) !== $test) { throw new DomainException('The participation changed before its terminal exit.'); }
            $this->query('UPDATE survey_unit_sessions SET ended=COALESCE(ended,NOW()),state="ENDED",queued=0,
                result=?,result_log=?,state_log=? WHERE id=? AND run_session_id=?',
                ['quota_'.$outcome,'Quota terminal exit: '.$outcome,json_encode(['reason'=>'quota_'.$outcome,
                    'ctx'=>['unit_type'=>$outcome === 'complete' ? 'Complete' : ($outcome === 'quota_full' ? 'Quota' : 'Page')],
                    'at'=>date(DATE_ATOM)],JSON_THROW_ON_ERROR),$unitSessionId,$sessionId]);
            $this->query('UPDATE survey_run_sessions SET ended=COALESCE(ended,NOW()) WHERE id=? AND run_id=?', [$sessionId,$runId]);
            $endedAt = $this->query('SELECT ended FROM survey_run_sessions WHERE id=?', [$sessionId])->fetchColumn();
            $this->pdo->commit();
            return $endedAt;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    private function query(string $sql, array $values): PDOStatement {
        $statement=$this->pdo->prepare($sql); $statement->execute($values); return $statement;
    }
}
