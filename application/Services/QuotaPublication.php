<?php

/** Administrative publication check; never called while rendering participant pages. */
class QuotaPublication {
    public function __construct(private PDO $pdo) {}

    public function assertReady(int $runId): void {
        $query = $this->pdo->prepare('SELECT ru.id,ru.position,u.type FROM survey_run_units ru
            JOIN survey_units u ON u.id=ru.unit_id WHERE ru.run_id=? AND u.type IN ("Quota","Complete")');
        $query->execute([$runId]);
        $units = $query->fetchAll(PDO::FETCH_ASSOC);
        $quotas = array_values(array_filter($units, fn($unit) => $unit['type'] === 'Quota'));
        if (!$quotas) { return; }
        $completes = array_values(array_filter($units, fn($unit) => $unit['type'] === 'Complete'));
        if (count($quotas) !== 1 || count($completes) !== 1) {
            throw new DomainException('Visibility was not changed. A run with quotas needs exactly one Quota and one Complete module. Add the missing module in Edit Run.');
        }
        if ((int) $completes[0]['position'] <= (int) $quotas[0]['position']) {
            throw new DomainException('Visibility was not changed. Place Complete after Quota in Edit Run.');
        }
        $query = $this->pdo->prepare('SELECT quota_run_unit_id,complete_run_unit_id,provider_json,editing
            FROM survey_quota_lifecycle WHERE run_id=?');
        $query->execute([$runId]);
        $active = $query->fetch(PDO::FETCH_ASSOC);
        if (!$active || (int)$active['editing'] || (int) $active['quota_run_unit_id'] !== (int) $quotas[0]['id']
            || (int) $active['complete_run_unit_id'] !== (int) $completes[0]['id']) {
            throw new DomainException('Visibility was not changed. Configure Quota, then choose Activate quotas and completion in Complete. A panel provider is optional.');
        }
        try {
            new QuotaProviderConfig(json_decode($active['provider_json'], true, 512, JSON_THROW_ON_ERROR));
        } catch (InvalidArgumentException | JsonException | TypeError $error) {
            throw new DomainException('Visibility was not changed. The saved completion settings are invalid. Check Complete before publishing.', 0, $error);
        }
        $query = $this->pdo->prepare('SELECT position FROM survey_run_units WHERE run_id=? GROUP BY position HAVING COUNT(*)>1');
        $query->execute([$runId]);
        if ($query->fetch()) {
            throw new DomainException('Visibility was not changed. Give each module a different position in Edit Run.');
        }
    }
}
