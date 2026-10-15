<?php

/** Native quota editor and admission unit, bound to an explicitly activated definition. */
class Quota extends RunUnit {
    public $type = 'Quota';
    public $icon = 'fa-users';

    protected function quotaRuntime(): QuotaRuntime { return new QuotaRuntime($this->db->pdo()); }

    public function create($options = []) {
        if (!empty($options['special']) || !empty($options['importing']) || !empty($options['add_to_run'])) {
            throw new InvalidArgumentException('Quotenmodule können noch nicht importiert oder als Sonderbausteine wiederverwendet werden.');
        }
        $editor = new QuotaEditor($this->db->pdo());
        if ($this->id) {
            $editor->assertUnit((int) $this->run->id, (int) $this->run_unit_id, (int) $this->run->user_id);
        }
        if (isset($options['quota_tables'])) {
            if (!$this->id || !is_string($options['quota_tables'])) {
                throw new InvalidArgumentException('Bitte zuerst das Quotenmodul anlegen, anschließend die Tabellen speichern.');
            }
            $tables = json_decode($options['quota_tables'], true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($tables)) {
                throw new InvalidArgumentException('Die Quoteneinstellungen konnten nicht gelesen werden. Bitte neu laden und erneut versuchen.');
            }
            $warnings = $editor->save((int) $this->run->id, (int) $this->run_unit_id,
                (int) $this->run->user_id, $tables);
            foreach ($warnings as $warning) {
                alert(h('Nicht abgedeckter Zahlenbereich: ' . $warning['source'] . ' ' . $warning['min'] . '–' . $warning['max']), 'alert-warning');
            }
        }
        if (!empty($options['quota_activate'])) {
            (new QuotaStore($this->db->pdo()))->activate((int) $this->run->id,(int) $this->run->user_id);
        }
        $options['type'] = 'Quota';
        return parent::create($options);
    }

    public function displayForRun($prepend = '') {
        $editor = new QuotaEditor($this->db->pdo());
        $runId = (int) $this->run->id;
        $unitId = (int) $this->run_unit_id;
        $actorId = (int) $this->run->user_id;
        return $this->runDialog(Template::get($this->getTemplatePath(), [
            'prepend' => $prepend, 'tables' => $editor->load($runId, $unitId, $actorId),
            'sources' => $editor->sources($runId, $unitId, $actorId),
            'active' => (new QuotaProviderStore($this->db->pdo()))->active($runId) !== null,
        ]));
    }

    public function getExportUnit() {
        throw new DomainException('Import und Export von Quotenmodulen sind noch nicht verfügbar.');
    }

    public function getUnitSessionOutput(UnitSession $unitSession) {
        try {
            return $this->quotaRuntime()->execute('Quota',(int) $this->run->id,(int) $unitSession->run_session_id,
                (int) $unitSession->run_unit_id,(int) $unitSession->id,$unitSession->isExecutedByCron());
        } catch (Throwable $error) {
            return ['content'=>'<p>Die Studie ist vorübergehend nicht verfügbar. Bitte erneut versuchen oder das Studienteam kontaktieren.</p>',
                'log'=>$this->getLogMessage('quota_error','Quota processing failed (' . get_class($error) . '); retry remains available.')];
        }
    }
}
