<?php
/** Extra adversarial service cases, isolated from the load measurement and application DB. */
require_once __DIR__.'/astra_fixture.php';
function astraFails(callable $action,string $label): void {
    try { $action(); } catch (DomainException|InvalidArgumentException|UnexpectedValueException|LogicException $e) { return; }
    throw new RuntimeException('Expected refusal: '.$label);
}
function astraComplete(PDO $db,QuotaRuntime $runtime,int $sid): array {
    $db->exec('UPDATE survey_run_sessions SET current_unit_session_id='.($sid*10+3).',position=4 WHERE id='.$sid);
    return $runtime->execute('Complete',1,$sid,103,$sid*10+3);
}
$name='formr_quota_astra_'.bin2hex(random_bytes(8));
$admin=astraConnection('mysql:host=127.0.0.1;port=13368;charset=utf8mb4');
$admin->exec('CREATE DATABASE `'.$name.'`');
$db=astraConnection('mysql:host=127.0.0.1;port=13368;charset=utf8mb4;dbname='.$name);
try {
    astraSchema($db); astraSeed($db,1,12,false);
    $editor=new QuotaEditor($db); $providers=new QuotaProviderStore($db); $store=new QuotaStore($db);
    $editor->save(1,102,7,astraTables());
    $config=['mode'=>'provider','entry_parameter'=>'panel','placeholder'=>'%PID%'];
    foreach (['complete','quota_full','screen_out'] as $exit) { $config[$exit]='https://provider.invalid/'.$exit.'?study=fixed&id=%PID%'; }
    $providers->save(1,103,7,$config); $quota=$store->activate(1,7); $runtime=new QuotaRuntime($db);
    $monitor=new QuotaMonitor($db); $key=$monitor->enable(1,7); $checks=[];
    foreach ([fn()=>$editor->load(1,102,8),fn()=>$store->activate(1,8),fn()=>$providers->settings(1,103,8),
        fn()=>$monitor->enable(1,8),fn()=>$monitor->revoke(1,8),fn()=>(new QuotaOverview($db))->read(1,8,'field'),
        fn()=>(new QuotaExport($db))->columns(10,8,'astra_answers',[])] as $action) { astraFails($action,'Other owner'); }
    $checks[]='seven cross-owner operations refused';
    $identity='ä/字 &injected=1?+%2F'; $providers->capture(1,1,['panel'=>$identity]);
    astraFails(fn()=>$providers->capture(1,1,['panel'=>'replacement']),'Changed ID');
    foreach ([['nested'],"bad\r\nvalue",str_repeat('x',1025)] as $bad) {
        astraFails(fn()=>$providers->capture(1,2,['panel'=>$bad]),'Malformed identity');
    }
    $db->exec('UPDATE survey_run_sessions SET run_id=2 WHERE id=12');
    astraFails(fn()=>$providers->capture(1,12,['panel'=>'other-session']),'Cross-run capture');
    $checks[]='provider identity immutable, bounded, and run-scoped';
    astraAssert(!empty($runtime->execute('Quota',1,1,102,12)['move_on']),'Admission');
    $out=astraComplete($db,$runtime,1);
    preg_match('/data-completion-link href="([^"]+)"/',$out['content'],$returnLink);
    parse_str(parse_url(html_entity_decode($returnLink[1],ENT_QUOTES,'UTF-8'),PHP_URL_QUERY),$query);
    astraAssert($query===['study'=>'fixed','id'=>$identity],'Exact single encoding without query injection');
    astraComplete($db,$runtime,1);
    $checks[]='field redirect encoded once, no provider request performed, completion retry idempotent';
    $providers->capture(1,2,['panel'=>'id2']); $db->exec('UPDATE astra_answers SET age=NULL WHERE session_id=21');
    $out=$runtime->execute('Quota',1,2,102,22);
    astraAssert(str_contains($out['redirect'],'/quota_full?'),'Missing answer uses quota_full');
    astraAssert($store->admitFromSurvey(1,$quota,2,102)['reason']==='missing_answer','Missing answer reason');
    $db->exec('UPDATE astra_answers SET age=29 WHERE session_id=21');
    astraAssert($store->admitFromSurvey(1,$quota,2,102)['status']==='rejected','Rejection sticky');
    $checks[]='missing answer rejected and later edits cannot reopen admission';
    $db->exec('UPDATE survey_unit_sessions SET state="EXPIRED" WHERE id=31');
    astraFails(fn()=>$runtime->execute('Quota',1,3,102,32),'Expired source');
    astraAssert(!(bool)$db->query('SELECT id FROM survey_quota_decisions WHERE run_session_id=3')->fetchColumn(),'No technical rejection persisted');
    $db->exec('UPDATE survey_unit_sessions SET state="ENDED" WHERE id=31');
    astraAssert(!empty($runtime->execute('Quota',1,3,102,32)['move_on']),'Retry after source recovery');
    $db->exec('UPDATE survey_studies SET results_table="missing_source_table" WHERE id=10');
    astraFails(fn()=>$runtime->execute('Quota',1,4,102,42),'Missing result table');
    $db->exec('UPDATE survey_studies SET results_table="astra_answers" WHERE id=10');
    $checks[]='expired/missing source holds, no decision saved, corrected source retries';
    $db->exec('UPDATE survey_run_sessions SET testing=1 WHERE id=5'); $providers->capture(1,5,['panel'=>'test5']);
    $runtime->execute('Quota',1,5,102,52); $out=astraComplete($db,$runtime,5);
    astraAssert(!isset($out['redirect']) && str_contains($out['content'],'provider.invalid'),'Test uses plain preview');
    $runtime->execute('Quota',1,6,102,62);
    astraFails(fn()=>astraComplete($db,$runtime,6),'Missing provider identity');
    astraAssert((bool)$db->query('SELECT decision_id FROM survey_quota_completions c JOIN survey_quota_decisions d ON d.id=c.decision_id WHERE d.run_session_id=6')->fetchColumn(),'Booking survives missing identity');
    astraAssert($db->query('SELECT ended FROM survey_run_sessions WHERE id=6')->fetchColumn()===null,'Missing identity keeps run unfinished');
    $providers->capture(1,6,['panel'=>'recovered6']); astraComplete($db,$runtime,6);
    $checks[]='test cohort isolated and missing identity recovery books only once';
    $providers->capture(1,7,['panel'=>'screen7']); $db->exec('UPDATE survey_run_sessions SET current_unit_session_id=74 WHERE id=7');
    astraAssert(str_contains($runtime->execute('screen_out',1,7,104,74)['redirect'],'/screen_out?'),'Screen-out route');
    astraFails(fn()=>astraComplete($db,$runtime,8),'Complete without admission');
    astraAssert($runtime->execute('Complete',1,9,103,93,true)===['wait_user'=>true],'Cron holds');
    astraFails(fn()=>$runtime->execute('Quota',1,10,102,112),'Foreign unit session');
    $db->exec('UPDATE survey_unit_sessions SET state="EXPIRED" WHERE id=112');
    astraFails(fn()=>$runtime->execute('Quota',1,11,102,112),'Expired current unit');
    $checks[]='screen-out and cron do not count; wrong/expired unit and no-admission Complete refused';
    $view=$monitor->read('astra-study',$key);
    astraAssert($view['completed']===2,'Only two field completions in monitor');
    astraAssert(array_keys($view)===['name','completed','groups'],'Public output allowlist');
    astraAssert($monitor->read('other-owner',$key)===null,'Key is run-scoped');
    $monitor->revoke(1,7); astraAssert($monitor->read('astra-study',$key)===null,'Revocation');
    astraAssert((int)$db->query('SELECT COUNT(*) FROM survey_quota_completions')->fetchColumn()===3,'Two field and one test total');
    $checks[]='monitor omits test cohort/identities and revocation works';
    echo json_encode(['security_checks'=>$checks,'result'=>'PASS'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
} finally {
    $db=null;
    if (preg_match('/\Aformr_quota_astra_[0-9a-f]{16}\z/',$name)) { $admin->exec('DROP DATABASE `'.$name.'`'); }
}
