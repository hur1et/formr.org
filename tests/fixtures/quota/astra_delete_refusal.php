<?php
/** Reproduce native run-deletion partial effects on synthetic resources only. */
require_once __DIR__.'/astra_fixture.php';
class Model { public $db; }
function alert($message,$kind) {}
function __($message,...$args) { return sprintf($message,...$args); }
function formr_log_exception($error,$context) { $GLOBALS['astra_delete_error']=$error->getCode(); }
require_once __DIR__.'/../../../application/Model/Run.php';
class AstraDeleteDb {
    public function __construct(private PDO $pdo) {}
    public function find($table,$where,$options=[]) {
        $statement=$this->pdo->prepare('SELECT * FROM '.$table.' WHERE run_id=?');
        $statement->execute([$where['run_id']]); return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    public function delete($table,$where) {
        $key=array_key_first($where); $statement=$this->pdo->prepare('DELETE FROM '.$table.' WHERE '.$key.'=?');
        $statement->execute([$where[$key]]); return $statement->rowCount();
    }
}
$name='formr_quota_astra_'.bin2hex(random_bytes(8));
$admin=astraConnection('mysql:host=127.0.0.1;port=13368;charset=utf8mb4');
$admin->exec('CREATE DATABASE `'.$name.'`');
$db=astraConnection('mysql:host=127.0.0.1;port=13368;charset=utf8mb4;dbname='.$name);
$directory=sys_get_temp_dir().'/'.$name.'/'; mkdir($directory); mkdir($directory.'webroot');
define('APPLICATION_ROOT',$directory);
try {
    astraSchema($db);
    (new QuotaEditor($db))->save(1,102,7,astraTables());
    (new QuotaProviderStore($db))->save(1,103,7,['mode'=>'none']);
    (new QuotaStore($db))->activate(1,7);
    $db->exec('CREATE TABLE survey_uploaded_files (id INT PRIMARY KEY,run_id INT,new_file_path VARCHAR(255)) ENGINE=InnoDB;
      CREATE TABLE survey_run_special_units (id INT PRIMARY KEY,run_id INT) ENGINE=InnoDB;
      INSERT INTO survey_uploaded_files VALUES(1,1,"synthetic.txt"); INSERT INTO survey_run_special_units VALUES(90,1)');
    file_put_contents($directory.'webroot/synthetic.txt','This file belongs only to the disposable audit.');
    $run=(new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
    $run->id=1; $run->name='astra-study'; $run->db=new AstraDeleteDb($db);
    $deleted=$run->delete();
    $evidence=['native_delete_result'=>$deleted,'sqlstate'=>$GLOBALS['astra_delete_error']??null,
      'run_survives'=>(int)$db->query('SELECT COUNT(*) FROM survey_runs WHERE id=1')->fetchColumn(),
      'pinned_links_survive'=>(int)$db->query('SELECT COUNT(*) FROM survey_run_units WHERE run_id=1')->fetchColumn(),
      'special_links_remaining'=>(int)$db->query('SELECT COUNT(*) FROM survey_run_special_units WHERE run_id=1')->fetchColumn(),
      'upload_rows_remaining'=>(int)$db->query('SELECT COUNT(*) FROM survey_uploaded_files WHERE run_id=1')->fetchColumn(),
      'physical_file_exists'=>file_exists($directory.'webroot/synthetic.txt')];
    astraAssert($deleted===false && $evidence['run_survives']===1 && $evidence['pinned_links_survive']===4
      && $evidence['special_links_remaining']===0 && $evidence['upload_rows_remaining']===0
      && !$evidence['physical_file_exists'],'Native failed deletion causes partial destructive effects');
    echo json_encode($evidence,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
    // A sharing key alone also blocks the final run DELETE after all links are gone.
    $db->exec('INSERT INTO survey_runs VALUES(3,7,"share-only");
      INSERT INTO survey_run_units VALUES(301,3,10,1,"Existing survey");
      INSERT INTO survey_uploaded_files VALUES(3,3,"synthetic3.txt");
      INSERT INTO survey_run_special_units VALUES(93,3)');
    (new QuotaMonitor($db))->enable(3,7);
    file_put_contents($directory.'webroot/synthetic3.txt','Audit share-only resource.');
    $run->id=3; $run->name='share-only'; $deleted=$run->delete();
    $shareEvidence=['case'=>'monitor key without active quota','native_delete_result'=>$deleted,
      'run_survives'=>(int)$db->query('SELECT COUNT(*) FROM survey_runs WHERE id=3')->fetchColumn(),
      'run_links_remaining'=>(int)$db->query('SELECT COUNT(*) FROM survey_run_units WHERE run_id=3')->fetchColumn(),
      'physical_file_exists'=>file_exists($directory.'webroot/synthetic3.txt')];
    astraAssert($deleted===false && $shareEvidence['run_survives']===1
      && $shareEvidence['run_links_remaining']===0 && !$shareEvidence['physical_file_exists'],
      'Monitor key alone causes partial destructive failure');
    echo json_encode($shareEvidence,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
} finally {
    $run=null; $db=null;
    if (preg_match('/\Aformr_quota_astra_[0-9a-f]{16}\z/',$name)) { $admin->exec('DROP DATABASE `'.$name.'`'); }
    if (is_file($directory.'webroot/synthetic.txt')) { unlink($directory.'webroot/synthetic.txt'); }
    if (is_file($directory.'webroot/synthetic3.txt')) { unlink($directory.'webroot/synthetic3.txt'); }
    rmdir($directory.'webroot'); rmdir($directory);
}
