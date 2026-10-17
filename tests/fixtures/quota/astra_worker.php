<?php
require_once __DIR__.'/astra_fixture.php';
$db=astraConnection(getenv('ASTRA_QUOTA_DSN')?:'');
$db->exec('SET SESSION innodb_lock_wait_timeout=20');
echo "READY\n"; flush();
$task=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$store=new QuotaStore($db); $runtime=new QuotaRuntime($db); $monitor=new QuotaMonitor($db);
$result=['admission'=>[],'completion'=>[],'retry'=>[],'monitor'=>[],'errors'=>[]];
foreach ($task['sessions'] as $sid) {
    try {
        if ($task['mode']==='monitor') {
            $start=hrtime(true); $read=$monitor->read('astra-study',$task['key']);
            astraAssert($read!==null,'Monitor key accepted');
            $result['monitor'][]=(hrtime(true)-$start)/1e6; continue;
        }
        if ($task['mode']==='lifecycle' || $task['mode']==='admit') {
            $start=hrtime(true);
            $out=$runtime->execute('Quota',1,$sid,102,$sid*10+2);
            astraAssert(!empty($out['move_on']),'Expected admitted participant');
            $result['admission'][]=(hrtime(true)-$start)/1e6;
        }
        if ($task['mode']==='lifecycle' || $task['mode']==='complete') {
            $db->exec('UPDATE survey_run_sessions SET current_unit_session_id='.($sid*10+3).',position=4 WHERE id='.$sid);
            $start=hrtime(true); $out=$runtime->execute('Complete',1,$sid,103,$sid*10+3);
            astraAssert(!empty($out['quota_terminal']),'Completion finished');
            $result['completion'][]=(hrtime(true)-$start)/1e6;
        }
        if ($task['mode']==='retry' || ($task['mode']==='lifecycle' && $sid%10===0)) {
            $start=hrtime(true);
            $saved=$store->admitFromSurvey(1,$task['quota'],$sid,102);
            astraAssert($saved['status']==='admitted','Sticky decision');
            $out=$runtime->execute('Complete',1,$sid,103,$sid*10+3);
            astraAssert(!empty($out['quota_terminal']),'Retry finished');
            $result['retry'][]=(hrtime(true)-$start)/1e6;
        }
    } catch (Throwable $e) {
        $result['errors'][]=['session'=>$sid,'class'=>get_class($e),'message'=>$e->getMessage()];
    }
}
echo json_encode($result,JSON_THROW_ON_ERROR);
