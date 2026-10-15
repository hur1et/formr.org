<?php

/** Explicit completion unit with a retry-safe booking and configured terminal exit. */
class Complete extends RunUnit {
    public $type = 'Complete';
    public $icon = 'fa-check-circle';

    protected function quotaRuntime(): QuotaRuntime { return new QuotaRuntime($this->db->pdo()); }

    public function create($options = []) {
        if (!empty($options['special']) || !empty($options['importing']) || !empty($options['add_to_run'])) {
            throw new InvalidArgumentException('Abschlussbausteine können noch nicht importiert oder als Sonderbausteine verwendet werden.');
        }
        if ($this->id) {
            $statement = $this->db->pdo()->prepare('SELECT ru.id FROM survey_run_units ru
                JOIN survey_runs r ON r.id=ru.run_id JOIN survey_units u ON u.id=ru.unit_id
                WHERE ru.id=? AND ru.run_id=? AND ru.unit_id=? AND r.user_id=? AND u.type=?');
            $statement->execute([$this->run_unit_id, $this->run->id, $this->id, $this->run->user_id, 'Complete']);
            if (!$statement->fetch()) { throw new DomainException('Der Abschlussbaustein muss zu diesem Run und seiner verantwortlichen Person gehören.'); }
        }
        if (isset($options['provider_mode'])) {
            if (!$this->id) { throw new InvalidArgumentException('Bitte zuerst den Abschlussbaustein anlegen, anschließend die Provider-Einstellungen speichern.'); }
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
        throw new DomainException('Import und Export von Abschlussbausteinen sind noch nicht verfügbar.');
    }

    public function getUnitSessionOutput(UnitSession $unitSession) {
        try {
            return $this->quotaRuntime()->execute('Complete',(int) $this->run->id,(int) $unitSession->run_session_id,
                (int) $unitSession->run_unit_id,(int) $unitSession->id,$unitSession->isExecutedByCron());
        } catch (Throwable $error) {
            return ['content'=>'<p>Ihr Abschluss konnte noch nicht vollständig verarbeitet werden. Bitte erneut versuchen oder das Studienteam kontaktieren.</p>',
                'log'=>$this->getLogMessage('quota_error','Complete processing failed (' . get_class($error) . '); retry remains available.')];
        }
    }
}
