<?php

/** Explicit completion unit; no completion is recorded until lifecycle wiring is ready. */
class Complete extends RunUnit {
    public $type = 'Complete';
    public $icon = 'fa-check-circle';

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
        $options['type'] = 'Complete';
        return parent::create($options);
    }

    public function displayForRun($prepend = '') {
        return $this->runDialog(Template::get($this->getTemplatePath(), ['prepend' => $prepend]));
    }

    public function getExportUnit() {
        throw new DomainException('Complete import/export is not implemented yet.');
    }

    public function getUnitSessionOutput(UnitSession $unitSession) {
        return ['content' => '<p>This study is temporarily unavailable. Please try again later.</p>'];
    }
}
