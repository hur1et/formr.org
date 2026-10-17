<?php
/** Bounded database/service benchmark: no HTTP, OpenCPU, provider, or application config. */
require_once __DIR__.'/astra_fixture.php';
function astraPool(array $tasks): array {
    $workers=[]; $start=hrtime(true);
    foreach ($tasks as $task) {
        $command=[PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir'),'-d','extension=mbstring',
            '-d','extension=pdo_mysql',__DIR__.'/astra_worker.php'];
        $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start audit worker.'); }
        astraAssert(trim(fgets($pipes[1]))==='READY','Worker became ready');
        $workers[]=[$process,$pipes,$task];
    }
    $running=hrtime(true);
    foreach ($workers as [$process,$pipes,$task]) { fwrite($pipes[0],json_encode($task,JSON_THROW_ON_ERROR)); fclose($pipes[0]); }
    $merged=['admission'=>[],'completion'=>[],'retry'=>[],'monitor'=>[],'errors'=>[]];
    foreach ($workers as [$process,$pipes,$task]) {
        $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
        astraAssert($exit===0 && $error==='','Worker exit without stderr: '.$error);
        $data=json_decode($output,true,512,JSON_THROW_ON_ERROR);
        foreach ($merged as $key=>$_) { $merged[$key]=array_merge($merged[$key],$data[$key]); }
    }
    $merged['wall_seconds']=(hrtime(true)-$running)/1e9;
    $merged['startup_seconds']=($running-$start)/1e9;
    return $merged;
}
function astraTasks(int $workers,array $ids,string $mode,int $quota): array {
    $buckets=array_fill(0,$workers,[]);
    foreach ($ids as $i=>$id) { $buckets[$i%$workers][]=$id; }
    return array_map(fn($sessions)=>['mode'=>$mode,'quota'=>$quota,'sessions'=>$sessions],$buckets);
}
function astraSummary(array $raw): array {
    $summary=['wall_seconds'=>round($raw['wall_seconds'],3),'startup_seconds'=>round($raw['startup_seconds'],3)];
    foreach (['admission','completion','retry','monitor'] as $kind) { $summary[$kind]=astraTimings($raw[$kind]); }
    $summary['error_count']=count($raw['errors']); $summary['errors']=$raw['errors']; return $summary;
}
$database='formr_quota_astra_'.bin2hex(random_bytes(8));
$admin=astraConnection('mysql:host=127.0.0.1;port=13368;charset=utf8mb4');
$admin->exec('CREATE DATABASE `'.$database.'`');
$dsn='mysql:host=127.0.0.1;port=13368;charset=utf8mb4;dbname='.$database;
putenv('ASTRA_QUOTA_DSN='.$dsn); $db=astraConnection($dsn);
$report=['audited_commit'=>'0caf2a43df562892beaf5de452210e9af8c4a002','utc'=>gmdate(DATE_ATOM),
    'php'=>PHP_VERSION,'mariadb'=>$db->query('SELECT VERSION()')->fetchColumn(),'scenarios'=>[]];
try {
    astraSchema($db); $expected=astraSeed($db,1,2000);
    (new QuotaEditor($db))->save(1,102,7,astraTables());
    (new QuotaProviderStore($db))->save(1,103,7,['mode'=>'none']);
    $store=new QuotaStore($db); $quota=$store->activate(1,7);
    $key=(new QuotaMonitor($db))->enable(1,7);
    foreach ([[8,1,800,2],[16,801,600,2],[32,1401,600,4]] as [$workers,$first,$count,$readers]) {
        echo 'Starting '.$workers.' writers, '.$readers.' readers, '.$count." saved-source lifecycles\n"; flush();
        $tasks=astraTasks($workers,range($first,$first+$count-1),'lifecycle',$quota);
        for ($i=0;$i<$readers;$i++) { $tasks[]=['mode'=>'monitor','key'=>$key,'sessions'=>range(1,100)]; }
        $raw=astraPool($tasks); $summary=astraSummary($raw);
        $summary['participants']=$count; $summary['writers']=$workers; $summary['readers']=$readers;
        $report['scenarios'][]=$summary;
        echo json_encode($summary,JSON_THROW_ON_ERROR)."\n"; flush();
        astraAssert(!$raw['errors'],'No load operation errors');
    }
    foreach (['field','test'] as $cohort) {
        $actual=$store->getCounts(1,$quota,$cohort);
        foreach ($actual as $table=>$groups) { foreach ($groups as $group=>$count) {
            astraAssert($count===($expected[$cohort][$table][$group]??0),$cohort.' '.$table.' '.$group.' exact count');
        } }
        $report['counts'][$cohort]=$actual;
    }
    astraAssert((int)$db->query('SELECT COUNT(*) FROM survey_quota_completions')->fetchColumn()===2000,'Exactly 2000 completions');
    astraAssert((int)$db->query('SELECT COUNT(*) FROM survey_quota_assignments')->fetchColumn()===4000,'Exactly two assignments per participation');
    $raw=astraPool(astraTasks(32,array_fill(0,320,1),'retry',$quota));
    $report['duplicate_retry_burst']=astraSummary($raw);
    astraAssert(!$raw['errors'],'Same-participation concurrent retries succeed');
    astraAssert((int)$db->query('SELECT COUNT(*) FROM survey_quota_completions')->fetchColumn()===2000,'Retries count once');
    astraSeed($db,2001,33,false);
    $before=$store->getCounts(1,$quota,'field');
    $store->setLimit(1,$quota,'age_sex','adult|female',$before['age_sex']['adult|female']+1,7,'Audit near-full','near-age');
    $store->setLimit(1,$quota,'education','uni',$before['education']['uni']+1,7,'Audit near-full','near-edu');
    $admit=astraPool(astraTasks(32,range(2001,2032),'admit',$quota));
    $complete=astraPool(astraTasks(32,range(2001,2032),'complete',$quota));
    astraAssert(!$admit['errors'] && !$complete['errors'],'Near-full admitted participants all finish');
    $after=$store->getCounts(1,$quota,'field');
    astraAssert($after['age_sex']['adult|female']===$before['age_sex']['adult|female']+32,'Allowed overfill all 32');
    astraAssert($after['education']['uni']===$before['education']['uni']+32,'Allowed overfill in second table');
    $reject=$store->admitFromSurvey(1,$quota,2033,102);
    astraAssert($reject['status']==='rejected' && $reject['reason']==='group_full','Next participation rejected');
    $report['near_full']=['admission'=>astraSummary($admit),'completion'=>astraSummary($complete),
        'places_remaining_before'=>1,'admitted_before_completion'=>32,'completed'=>32,'overfill'=>31,'next_rejected'=>true];
    $report['final_completions']=(int)$db->query('SELECT COUNT(*) FROM survey_quota_completions')->fetchColumn();
    $report['integrity_checks']='passed';
    if (isset($argv[1])) { file_put_contents($argv[1],json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n"); }
    echo 'AUDIT_LOAD_PASS '.json_encode($report,JSON_THROW_ON_ERROR)."\n";
} finally {
    $db=null;
    if (preg_match('/\Aformr_quota_astra_[0-9a-f]{16}\z/',$database)) { $admin->exec('DROP DATABASE `'.$database.'`'); }
    putenv('ASTRA_QUOTA_DSN');
}
