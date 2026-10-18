<?php

/** Explicit completion unit with a retry-safe booking and configured terminal exit. */
class Complete extends RunUnit {
    public $type = 'Complete';
    public $icon = 'fa-check-circle';

    protected function quotaRuntime(): QuotaRuntime { return new QuotaRuntime($this->db->pdo()); }

    public function create($options = []) {
        $page = null;
        if (isset($options['completion_body'])) {
            $page=QuotaCompletionPage::validate(['body'=>$options['completion_body'],
                'redirect_message'=>$options['completion_message'] ?? QuotaCompletionPage::defaults()['redirect_message'],
                'redirect_delay'=>$options['completion_delay'] ?? 10,
                'redirect_link_text'=>$options['completion_link_text'] ?? 'click here']);
        }
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
            $providers=new QuotaProviderStore($this->db->pdo());
            $saved=$providers->settings((int)$this->run->id,(int)$this->run_unit_id,(int)$this->run->user_id);
            // The legacy form serializer also posts disabled fieldset values.
            // An unchanged provider profile is not a provider edit when saving text.
            if ($saved['active']) {
                if ((new QuotaProviderConfig($config))->toArray() !== $saved['provider']) {
                    throw new DomainException('Reopen the quota configuration in Complete before changing provider settings.');
                }
            } else {
                $providers->save((int)$this->run->id,(int)$this->run_unit_id,(int)$this->run->user_id,$config);
            }
        }
        $options['type'] = 'Complete';
        $result = parent::create($options);
        if ($page !== null) {
            (new QuotaCompletionPage($this->db->pdo()))->save((int)$this->run->id,(int)$this->run_unit_id,(int)$this->run->user_id,$page);
        }
        if ($this->valid && !empty($options['quota_activate'])) {
            (new QuotaStore($this->db->pdo()))->activate((int) $this->run->id,(int) $this->run->user_id);
        }
        return $result;
    }

    public function displayForRun($prepend = '') {
        $settings = (new QuotaProviderStore($this->db->pdo()))->settings((int) $this->run->id,(int) $this->run_unit_id,(int) $this->run->user_id);
        $page=(new QuotaCompletionPage($this->db->pdo()))->load((int)$this->id);
        return $this->runDialog(Template::get($this->getTemplatePath(), ['prepend' => $prepend, 'unitId' => $this->run_unit_id,
            'completionPage'=>$page] + $settings));
    }

    public function getExportUnit() {
        if (!$this->run || !$this->run_unit_id) { throw new DomainException('Export requires a saved Complete unit.'); }
        $settings = (new QuotaProviderStore($this->db->pdo()))->settings(
            (int) $this->run->id,(int) $this->run_unit_id,(int) $this->run->user_id);
        return parent::getExportUnit() + ['provider_mode'=>($settings['provider']['mode'] ?? '') === 'none' ? 'none' : '',
            'completion_page'=>(new QuotaCompletionPage($this->db->pdo()))->load((int)$this->id)];
    }

    protected function completionBody(UnitSession $unitSession) {
        $settings=(new QuotaCompletionPage($this->db->pdo()))->load((int)$this->id);
        $this->body=$settings['body'];
        $parser=new ParsedownExtra();
        $parser->setBreaksEnabled(true);
        $this->body_parsed=parsedown_text_safe($parser,$this->body,'the completion page');
        $body=$this->getParsedBody($this->body,$unitSession);
        return $body===false ? false : do_run_shortcodes($body,$this->run->name,$unitSession->runSession->session);
    }

    public function getUnitSessionOutput(UnitSession $unitSession) {
        try {
            if ($unitSession->isExecutedByCron()) { return ['wait_user'=>true]; }
            $body=$this->completionBody($unitSession);
            if ($body===false) {
                notify_study_admin($unitSession,'Complete: OpenCPU error while preparing completion text.','error');
                return ['wait_opencpu'=>true,'log'=>array_val($this->errors,'log',[])];
            }
            return $this->quotaRuntime()->execute('Complete',(int) $this->run->id,(int) $unitSession->run_session_id,
                (int) $unitSession->run_unit_id,(int) $unitSession->id,false,$body);
        } catch (Throwable $error) {
            return ['content'=>'<p>We could not finish processing your participation. Reload this page to try again, or contact the study team.</p>',
                'log'=>$this->getLogMessage('quota_error','Complete processing failed (' . get_class($error) . '); retry remains available.')];
        }
    }
}
