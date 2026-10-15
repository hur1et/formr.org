<?php

/** Quota editor entry point. Participant execution stays blocked during development. */
class Quota extends RunUnit {
    public $type = 'Quota';
    public $icon = 'fa-users';

    public function create($options = []) {
        if (!empty($options['special']) || !empty($options['importing']) || !empty($options['add_to_run'])) {
            throw new InvalidArgumentException('Quota drafts cannot yet be imported or reused as special units.');
        }
        $editor = new QuotaEditor($this->db->pdo());
        if ($this->id) {
            $editor->assertUnit((int) $this->run->id, (int) $this->run_unit_id, (int) $this->run->user_id);
        }
        if (isset($options['quota_tables'])) {
            if (!$this->id || !is_string($options['quota_tables'])) {
                throw new InvalidArgumentException('Create the Quota unit before saving its tables.');
            }
            $tables = json_decode($options['quota_tables'], true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($tables)) {
                throw new InvalidArgumentException('Quota configuration could not be read. Reload and try again.');
            }
            $warnings = $editor->save((int) $this->run->id, (int) $this->run_unit_id,
                (int) $this->run->user_id, $tables);
            foreach ($warnings as $warning) {
                alert(h('Uncovered integer range: ' . $warning['source'] . ' ' . $warning['min'] . '–' . $warning['max']), 'alert-warning');
            }
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
        ]));
    }

    public function getExportUnit() {
        throw new DomainException('Quota draft import/export is not implemented yet.');
    }

    public function getUnitSessionOutput(UnitSession $unitSession) {
        return ['content' => '<p>This study is temporarily unavailable. Please try again later.</p>'];
    }
}
