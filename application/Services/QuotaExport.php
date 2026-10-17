<?php

/** Download-only quota columns. Scalar subqueries preserve the original answer row set. */
class QuotaExport {
    public const SYSTEM_COLUMNS=['session_id','study_id','iteration','created','modified','ended','expired'];

    public function __construct(private PDO $pdo) {}

    public function columns(int $studyId,int $ownerId,string $resultTable,array $baseColumns): array {
        $plan=$this->plan($studyId,$ownerId,$baseColumns);
        if (!$plan['columns']) { return ['survey_run_sessions.session',self::quote($resultTable).'.*']; }
        $qualified=static fn($column)=>self::quote($resultTable).'.'.self::quote($column);
        $system=array_values(array_intersect($baseColumns,self::SYSTEM_COLUMNS));
        $items=array_values(array_diff($baseColumns,self::SYSTEM_COLUMNS));
        return array_merge(['survey_run_sessions.session'],array_map($qualified,$system),
            $plan['columns'],array_map($qualified,$items));
    }

    public function codebook(int $studyId,int $ownerId,array $baseColumns): array {
        return $this->plan($studyId,$ownerId,$baseColumns,true)['codebook'];
    }

    public function available(int $studyId,int $ownerId): bool {
        return (bool)$this->plan($studyId,$ownerId,[])['columns'];
    }

    private function plan(int $studyId,int $ownerId,array $baseColumns,bool $describe=false): array {
        $check=$this->pdo->prepare('SELECT user_id,unlinked FROM survey_studies WHERE id=?');
        $check->execute([$studyId]); $study=$check->fetch(PDO::FETCH_ASSOC);
        if (!$study || $ownerId<=0 || (int)$study['user_id']!==$ownerId) {
            throw new DomainException('Quota export requires the survey owner.');
        }
        if ((int)$study['unlinked']!==0) { return ['columns'=>[],'codebook'=>[]]; }
        // Include configured runs and historic participations even if a Survey unit was removed.
        $stmt=$this->pdo->prepare('SELECT q.id AS quota_id,q.run_id,r.name AS run_name,
            t.id AS table_id,t.code,t.dimensions_json FROM survey_quotas q
            JOIN survey_runs r ON r.id=q.run_id JOIN survey_quota_tables t ON t.quota_id=q.id
            WHERE r.user_id=? AND q.run_id IN (
                SELECT ru.run_id FROM survey_run_units ru WHERE ru.unit_id=?
                UNION SELECT rs.run_id FROM survey_unit_sessions us
                    JOIN survey_run_sessions rs ON rs.id=us.run_session_id WHERE us.unit_id=?)
            ORDER BY q.run_id,t.id');
        $stmt->execute([$ownerId,$studyId,$studyId]);
        $tables=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$tables) { return ['columns'=>[],'codebook'=>[]]; }
        $used=array_fill_keys(array_map('strtolower',array_merge(['session'],$baseColumns)),true);
        $status=self::uniqueName('quota_status',$used);
        $counted=self::uniqueName('quota_counted',$used);
        $ids=implode(',',array_unique(array_map(static fn($t)=>(int)$t['quota_id'],$tables)));
        $scope='FROM survey_quotas q LEFT JOIN survey_quota_decisions d ON d.quota_id=q.id
            AND d.run_session_id=survey_run_sessions.id WHERE q.id IN ('.$ids.')
            AND q.run_id=survey_run_sessions.run_id';
        $columns=['(SELECT d.outcome '.$scope.') AS '.self::quote($status),
            '(SELECT IF(EXISTS(SELECT 1 FROM survey_quota_completions c WHERE c.decision_id=d.id),1,0) '
                .$scope.') AS '.self::quote($counted)];
        $book=[];
        if ($describe) {
            foreach (['admitted'=>'Passed all quota tables.','rejected'=>'Rejected by quota (quota_full exit).',
                ''=>'No saved quota decision.'] as $value=>$meaning) {
                $book[]=self::entry($status,'','',(string)$value,$meaning);
            }
            $book[]=self::entry($counted,'','','1','A completion was recorded for this run participation.');
            $book[]=self::entry($counted,'','','0','This run uses quotas but no completion has been recorded.');
            $book[]=self::entry($counted,'','','','No active quota in the applicable run export scope.');
        }
        foreach ($tables as $table) {
            $quotaId=(int)$table['quota_id']; $tableId=(int)$table['table_id'];
            $name=self::uniqueName('quota_'.str_replace('-','_',$table['code']).'_group',$used);
            $columns[]='(SELECT g.code FROM survey_quota_decisions d
                JOIN survey_quota_assignments a ON a.decision_id=d.id AND a.quota_id=d.quota_id
                JOIN survey_quota_groups g ON g.id=a.group_id AND g.table_id=a.table_id AND g.quota_id=a.quota_id
                WHERE d.run_session_id=survey_run_sessions.id AND d.quota_id='.$quotaId.' AND a.table_id='.$tableId.') AS '.self::quote($name);
            if ($describe) { $book=array_merge($book,$this->describeTable($table,$name)); }
        }
        return ['columns'=>$columns,'codebook'=>$book];
    }

    private function describeTable(array $table,string $name): array {
        $dimensions=json_decode($table['dimensions_json'],true,512,JSON_THROW_ON_ERROR);
        $groups=(new QuotaRules([$table['code']=>['dimensions'=>$dimensions]]))->getGroups()[$table['code']];
        $labels=[];
        foreach ($dimensions as $index=>$dimension) {
            if ($dimension['type']==='category') { $labels[$index]=$this->choiceLabels($dimension,(int)$table['run_id']); }
        }
        $rows=[];
        foreach ($groups as $code=>$group) {
            $meanings=[];
            foreach ($dimensions as $index=>$dimension) {
                $category=$dimension['categories'][$group['categories'][$dimension['source']]];
                $value=$dimension['type']==='integer' ? $category['min'].'–'.$category['max']
                    : (($labels[$index][(string)$category]??(string)$category).' (code '.(string)$category.')');
                $meanings[]=($dimension['item_name']??$dimension['source']).': '.$value;
            }
            $rows[]=self::entry($name,$table['run_name'],$table['code'],(string)$code,implode(' × ',$meanings));
        }
        $rows[]=self::entry($name,$table['run_name'],$table['code'],'','No saved admission to this quota table.');
        return $rows;
    }

    private function choiceLabels(array $dimension,int $runId): array {
        $stmt=$this->pdo->prepare('SELECT i.type,c.name,c.label FROM survey_run_units ru
            JOIN survey_items i ON i.study_id=ru.unit_id AND BINARY i.name=?
            LEFT JOIN survey_item_choices c ON c.study_id=i.study_id AND BINARY c.list_name=i.choice_list
            WHERE ru.id=? AND ru.run_id=?');
        $stmt->execute([$dimension['item_name']??'',(int)($dimension['run_unit_id']??0),$runId]);
        $labels=[];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['type']==='sex') { return ['1'=>'Male','2'=>'Female']; }
            if ($row['name']!==null) { $labels[(string)$row['name']]=strip_tags($row['label']??''); }
        }
        return $labels;
    }

    private static function uniqueName(string $base,array &$used): string {
        $name=$base; $number=2;
        while (isset($used[strtolower($name)])) { $name=$base.'_'.$number++; }
        $used[strtolower($name)]=true;
        return $name;
    }

    private static function quote(string $identifier): string { return '`'.str_replace('`','``',$identifier).'`'; }
    private static function entry(string $column,string $run,string $table,string $value,string $meaning): array {
        return ['column'=>$column,'run'=>$run,'quota_table'=>$table,'value'=>$value,'meaning'=>$meaning];
    }
}
