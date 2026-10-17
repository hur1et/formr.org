<?php

/** Explicit completion unit with a retry-safe booking and configured terminal exit. */
class Complete extends RunUnit {
    public $type = 'Complete';
    public $icon = 'fa-check-circle';

    protected function quotaRuntime(): QuotaRuntime { return new QuotaRuntime($this->db->pdo()); }

    public function create($options = []) {
        if (!empty($options['special']) || !empty($options['importing']) || !empty($options['add_to_run'])) {
            throw new InvalidArgumentException('Complete cannot yet be imported or used as a special unit.');
        }
        if ($this->id) {
            $statement = $this->db->pdo()->prepare('SELECT ru.id FROM survey_run_units ru
                JOIN survey_runs r ON r.id=ru.run_id JOIN survey_units u ON u.id=ru.unit_id
                WHERE ru.id=? AND ru.run_id=? AND ru.unit_id=? AND r.user_id=? AND u.type=?');
            $statement->execute([$this->run_unit_id, $this->run->id, $this->id, $this->run->user_id, 'Complete']);
            if (!$statement->fetch()) { throw new DomainException('Complete must belong to this run and its owner.'); }
        }
        if (isset($options['provider_mode'])) {
            if (!$this->id) { throw new InvalidArgumentException('Create Complete before saving its provider settings.'); }
            $config = ['mode'=>$options['provider_mode']];
            foreach (['entry_parameter','placeholder','complete','quota_full','screen_out'] as $key) {
                $config[$key] = $options['provider_'.$key] ?? '';
            }
            (new QuotaProviderStore($this->db->pdo()))->save((int) $this->run->id,(int) $this->run_unit_id,(int) $this->run->user_id,$config);
        }
        $options['type'] = 'Complete';
        return parent::create($options);
    }

    public function displayForRun($prepend = '') {
        $settings = (new QuotaProviderStore($this->db->pdo()))->settings((int) $this->run->id,(int) $this->run_unit_id,(int) $this->run->user_id);
        return $this->runDialog(Template::get($this->getTemplatePath(), ['prepend' => $prepend, 'unitId' => $this->run_unit_id] + $settings));
    }

    public function getExportUnit() {
        if (!$this->run || !$this->run_unit_id) { throw new DomainException('Export requires a saved Complete unit.'); }
        $settings = (new QuotaProviderStore($this->db->pdo()))->settings(
            (int) $this->run->id,(int) $this->run_unit_id,(int) $this->run->user_id);
        return parent::getExportUnit() + ['provider_mode'=>($settings['provider']['mode'] ?? '') === 'none' ? 'none' : ''];
    }

    public function getUnitSessionOutput(UnitSession $unitSession) {
        try {
            return $this->quotaRuntime()->execute('Complete',(int) $this->run->id,(int) $unitSession->run_session_id,
                (int) $unitSession->run_unit_id,(int) $unitSession->id,$unitSession->isExecutedByCron());
        } catch (Throwable $error) {
            return ['content'=>'<p>Your completion could not be fully processed. Please retry or contact the study team.</p>',
                'log'=>$this->getLogMessage('quota_error','Complete processing failed (' . get_class($error) . '); retry remains available.')];
        }
    }
}
