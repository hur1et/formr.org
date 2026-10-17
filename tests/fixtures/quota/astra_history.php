<?php
/** Bounded accumulated-history probe; synthetic ledger history is seeded outside timed work. */
require_once __DIR__.'/astra_fixture.php';
function astraHistoryPool(array $tasks): array {
    $workers=[];
    foreach ($tasks as $task) {
        $command=[PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir'),'-d','extension=mbstring',
          '-d','extension=pdo_mysql',__DIR__.'/astra_worker.php'];
        $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        astraAssert(is_resource($process) && trim(fgets($pipes[1]))==='READY','History worker ready');
        $workers[]=[$process,$pipes,$task];
    }
    $start=hrtime(true);
    foreach ($workers as [$process,$pipes,$task]) { fwrite($pipes[0],json_encode($task)); fclose($pipes[0]); }
    $merged=['admission'=>[],'completion'=>[],'retry'=>[],'monitor'=>[],'errors'=>[]];
    foreach ($workers as [$process,$pipes,$task]) {
        $data=json_decode(stream_get_contents($pipes[1]),true,512,JSON_THROW_ON_ERROR);
        $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        astraAssert(proc_close($process)===0 && $error==='','History worker successful');
        foreach ($merged as $key=>$_) { $merged[$key]=array_merge($merged[$key],$data[$key]); }
    }
    $report=['wall_seconds'=>round((hrtime(true)-$start)/1e9,3),'errors'=>$merged['errors']];
    foreach (['admission','completion','retry','monitor'] as $kind) { $report[$kind]=astraTimings($merged[$kind]); }
    return $report;
}
$name='formr_quota_astra_'.bin2hex(random_bytes(8));
$admin=astraConnection('mysql:host=127.0.0.1;port=13368;charset=utf8mb4');
$admin->exec('CREATE DATABASE `'.$name.'`');
$dsn='mysql:host=127.0.0.1;port=13368;charset=utf8mb4;dbname='.$name;
$db=astraConnection($dsn); putenv('ASTRA_QUOTA_DSN='.$dsn);
try {
    astraSchema($db); astraSeed($db,100001,32,false);
    (new QuotaEditor($db))->save(1,102,7,astraTables());
    (new QuotaProviderStore($db))->save(1,103,7,['mode'=>'none']);
    $store=new QuotaStore($db); $quota=$store->activate(1,7); $key=(new QuotaMonitor($db))->enable(1,7);
    $db->exec('CREATE TEMPORARY TABLE digits (n INT); INSERT INTO digits VALUES(0),(1),(2),(3),(4),(5),(6),(7),(8),(9);
      CREATE TEMPORARY TABLE sequence (n INT PRIMARY KEY);
      INSERT INTO sequence SELECT a.n+10*b.n+100*c.n+1000*d.n+10000*e.n+1
      FROM digits a CROSS JOIN digits b CROSS JOIN digits c CROSS JOIN digits d CROSS JOIN digits e
      WHERE a.n+10*b.n+100*c.n+1000*d.n+10000*e.n<50000');
    $reports=[]; $previous=0;
    foreach ([[20000,8,100001],[50000,16,100017]] as [$history,$writers,$first]) {
        $range='n>'.$previous.' AND n<='.$history;
        $db->beginTransaction();
        $db->exec('INSERT INTO survey_run_sessions SELECT n,1,0,n*10+3,4,NOW(),CONCAT("history-",n) FROM sequence WHERE '.$range);
        $db->exec('INSERT INTO survey_unit_sessions(id,run_session_id,unit_id,run_unit_id,ended,state)
          SELECT n*10+3,n,30,103,NOW(),"ENDED" FROM sequence WHERE '.$range);
        $db->exec('INSERT INTO survey_quota_decisions(id,quota_id,run_session_id,cohort,outcome)
          SELECT n,'.$quota.',n,"field","admitted" FROM sequence WHERE '.$range);
        $db->exec('INSERT INTO survey_quota_assignments(decision_id,quota_id,table_id,group_id)
          SELECT s.n,g.quota_id,g.table_id,g.id FROM sequence s CROSS JOIN survey_quota_groups g
          JOIN survey_quota_tables t ON t.id=g.table_id WHERE '.$range.' AND
          ((t.code="age_sex" AND g.code="adult|female") OR (t.code="education" AND g.code="uni"))');
        $db->exec('INSERT INTO survey_quota_completions SELECT n,n*10+3,NOW() FROM sequence WHERE '.$range);
        $db->commit(); $previous=$history;
        // Keep subsequent auto IDs clear of the synthetic history IDs still to be inserted.
        $db->exec('ALTER TABLE survey_quota_decisions AUTO_INCREMENT=200000');
        echo 'History probe: '.$history.' seeded completions, '.$writers." writers and 2 readers\n"; flush();
        $tasks=[]; $buckets=array_fill(0,$writers,[]);
        foreach (range($first,$first+15) as $i=>$id) { $buckets[$i%$writers][]=$id; }
        foreach ($buckets as $ids) { $tasks[]=['mode'=>'lifecycle','quota'=>$quota,'sessions'=>$ids]; }
        for ($i=0;$i<2;$i++) { $tasks[]=['mode'=>'monitor','key'=>$key,'sessions'=>range(1,10)]; }
        $result=astraHistoryPool($tasks); $result['seeded_history']=$history; $result['writers']=$writers; $result['readers']=2;
        astraAssert(!$result['errors'],'History operations succeeded'); $reports[]=$result;
        echo json_encode($result,JSON_THROW_ON_ERROR)."\n"; flush();
    }
    $counts=$store->getCounts(1,$quota,'field');
    astraAssert($counts['age_sex']['adult|female']===50032 && $counts['education']['uni']===50032,'History and new counts exact');
    $report=['audited_commit'=>'0caf2a43df562892beaf5de452210e9af8c4a002','utc'=>gmdate(DATE_ATOM),
      'scenarios'=>$reports,'final_completions'=>50032,'integrity_checks'=>'passed'];
    if (isset($argv[1])) { file_put_contents(dirname($argv[1]).'/QUOTA_ASTRA_HISTORY_RESULTS.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n"); }
    echo "ASTRA_HISTORY_PASS\n";
} finally {
    if ($db->inTransaction()) { $db->rollBack(); } $db=null;
    if (preg_match('/\Aformr_quota_astra_[0-9a-f]{16}\z/',$name)) { $admin->exec('DROP DATABASE `'.$name.'`'); }
    putenv('ASTRA_QUOTA_DSN');
}
