<?php
/** Audit-only fixture. Never reads application configuration or uses an existing database. */
foreach (glob(__DIR__.'/../../../application/Services/Quota*.php') as $file) { require_once $file; }
function astraConnection(string $dsn): PDO {
    if (!preg_match('/\Amysql:host=127\.0\.0\.1;port=13368;charset=utf8mb4(?:;dbname=formr_quota_astra_[0-9a-f]{16})?\z/', $dsn)) {
        throw new RuntimeException('Astra audit permits only its disposable local database on 13368.');
    }
    return new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
}
function astraSchema(PDO $db): void {
    $db->exec('CREATE TABLE survey_runs (id INT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED,name VARCHAR(45) UNIQUE) ENGINE=InnoDB;
        CREATE TABLE survey_run_sessions (id INT UNSIGNED PRIMARY KEY,run_id INT UNSIGNED,testing TINYINT DEFAULT 0,
          current_unit_session_id INT UNSIGNED,position INT,ended DATETIME NULL,session VARCHAR(64),KEY(run_id)) ENGINE=InnoDB;
        CREATE TABLE survey_units (id INT UNSIGNED PRIMARY KEY,type VARCHAR(32)) ENGINE=InnoDB;
        CREATE TABLE survey_run_units (id INT UNSIGNED,run_id INT UNSIGNED,unit_id INT UNSIGNED,position INT,
          description VARCHAR(500),PRIMARY KEY(id,run_id),KEY(run_id,position)) ENGINE=InnoDB;
        CREATE TABLE survey_unit_sessions (id INT UNSIGNED PRIMARY KEY,run_session_id INT UNSIGNED,unit_id INT UNSIGNED,
          run_unit_id INT UNSIGNED,ended DATETIME NULL,state VARCHAR(32),queued INT DEFAULT 0,result VARCHAR(40),
          result_log TEXT,state_log TEXT,KEY(run_session_id,run_unit_id)) ENGINE=InnoDB;
        CREATE TABLE survey_pages (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB;
        CREATE TABLE survey_studies (id INT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED,results_table VARCHAR(64),valid INT,unlinked INT) ENGINE=InnoDB;
        CREATE TABLE survey_items (study_id INT UNSIGNED,name VARCHAR(64),type VARCHAR(100),choice_list VARCHAR(255),label TEXT,
          UNIQUE KEY(study_id,name)) ENGINE=InnoDB;
        CREATE TABLE survey_item_choices (study_id INT UNSIGNED,list_name VARCHAR(255),name VARCHAR(255),label TEXT,
          KEY(study_id,list_name)) ENGINE=InnoDB;
        CREATE TABLE astra_answers (session_id INT UNSIGNED PRIMARY KEY,study_id INT UNSIGNED,ended DATETIME,
          age INT,sex VARCHAR(12),education VARCHAR(12)) ENGINE=InnoDB;');
    foreach (['060_quota_core','061_quota_drafts','062_quota_lifecycle','063_quota_overview_edits','064_quota_shares','065_quota_reopening'] as $patch) {
        $db->exec(file_get_contents(__DIR__.'/../../../sql/patches/'.$patch.'.sql'));
    }
    $db->exec('INSERT INTO survey_runs VALUES(1,7,"astra-study"),(2,8,"other-owner");
      INSERT INTO survey_units VALUES(10,"Survey"),(20,"Quota"),(30,"Complete"),(40,"Page");
      INSERT INTO survey_run_units VALUES(101,1,10,1,"Saved demographics"),(102,1,20,2,"Quota"),
        (103,1,30,4,"Complete"),(104,1,40,3,"Screen out");
      INSERT INTO survey_studies VALUES(10,7,"astra_answers",1,0);
      INSERT INTO survey_items VALUES(10,"age","number",NULL,"Age"),(10,"sex","mc","sex","Sex"),
        (10,"education","mc","education","Education");
      INSERT INTO survey_item_choices VALUES(10,"sex","1","Male"),(10,"sex","2","Female"),
        (10,"education","uni","University"),(10,"education","other","Other");
      INSERT INTO survey_pages VALUES(40,"screen_out")');
}
function astraTables(int $limit=100000): array {
    return ['age_sex'=>['dimensions'=>[
      ['source'=>'survey_101.age','run_unit_id'=>101,'item_name'=>'age','type'=>'integer',
       'categories'=>['adult'=>['min'=>18,'max'=>29],'older'=>['min'=>30,'max'=>65]]],
      ['source'=>'survey_101.sex','run_unit_id'=>101,'item_name'=>'sex','type'=>'category','categories'=>['male'=>'1','female'=>'2']]],
      'limits'=>array_fill_keys(['adult|male','adult|female','older|male','older|female'],$limit)],
      'education'=>['dimensions'=>[['source'=>'survey_101.education','run_unit_id'=>101,'item_name'=>'education',
        'type'=>'category','categories'=>['uni'=>'uni','other'=>'other']]],'limits'=>['uni'=>$limit,'other'=>$limit]]];
}
function astraSeed(PDO $db,int $start,int $count,bool $vary=true): array {
    $rs=$db->prepare('INSERT INTO survey_run_sessions VALUES(?,1,?,?,2,NULL,?)');
    $us=$db->prepare('INSERT INTO survey_unit_sessions(id,run_session_id,unit_id,run_unit_id,ended,state) VALUES(?,?,?,?,?,?)');
    $answer=$db->prepare('INSERT INTO astra_answers VALUES(?,10,NOW(),?,?,?)');
    $expected=['field'=>[],'test'=>[]]; $db->beginTransaction();
    for ($sid=$start;$sid<$start+$count;$sid++) {
        $test=$vary && $sid%10===0; $age=$vary && $sid%4>=2 ? 45 : 29;
        $sex=$vary && $sid%2===0 ? '1':'2'; $edu=$vary && $sid%3===0 ? 'other':'uni';
        $rs->execute([$sid,$test?1:0,$sid*10+2,'synthetic-'.$sid]);
        foreach ([[1,10,101,date('Y-m-d H:i:s'),'ENDED'],[2,20,102,null,'CREATED'],
            [3,30,103,null,'CREATED'],[4,40,104,null,'CREATED']] as [$offset,$unit,$link,$end,$state]) {
            $us->execute([$sid*10+$offset,$sid,$unit,$link,$end,$state]);
        }
        $answer->execute([$sid*10+1,$age,$sex,$edu]);
        $cohort=$test?'test':'field'; $group=($age===29?'adult':'older').'|'.($sex==='1'?'male':'female');
        $expected[$cohort]['age_sex'][$group]=($expected[$cohort]['age_sex'][$group]??0)+1;
        $expected[$cohort]['education'][$edu]=($expected[$cohort]['education'][$edu]??0)+1;
    }
    $db->commit(); return $expected;
}
function astraAssert(bool $condition,string $message): void {
    if (!$condition) { throw new RuntimeException('Audit assertion: '.$message); }
}
function astraTimings(array $values): array {
    if (!$values) { return ['n'=>0]; } sort($values); $n=count($values);
    return ['n'=>$n,'p50_ms'=>round($values[(int)ceil($n*.5)-1],3),
      'p95_ms'=>round($values[(int)ceil($n*.95)-1],3),'max_ms'=>round(max($values),3)];
}
