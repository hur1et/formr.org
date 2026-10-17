<?php
/** Real Run/User methods against disposable SQL and disposable synthetic files only. */
require_once __DIR__.'/astra_fixture.php';
class Model { public $db; }
function alert($message,$kind) {}
function __($message,...$args) { return sprintf($message,...$args); }
function h($value) { return htmlspecialchars($value,ENT_QUOTES,'UTF-8'); }
function formr_log_exception($error,$context) {}
require_once __DIR__.'/../../../application/Model/Run.php';
require_once __DIR__.'/../../../application/Model/User.php';
class MaintenanceDb {
    public function __construct(private PDO $connection) {}
    public function pdo() { return $this->connection; }
    public function prepare($sql) { return $this->connection->prepare($sql); }
    public function find($table,$where,$options=[]) {
        $query=$this->connection->prepare('SELECT * FROM '.$table.' WHERE run_id=?');
        $query->execute([$where['run_id']]); return $query->fetchAll(PDO::FETCH_ASSOC);
    }
    public function findValue($table,$where,$column) {
        $key=array_key_first($where); $query=$this->connection->prepare('SELECT '.$column.' FROM '.$table.' WHERE '.$key.'=?');
        $query->execute([$where[$key]]); return $query->fetchColumn();
    }
    public function delete($table,$where) {
        $key=array_key_first($where); $query=$this->connection->prepare('DELETE FROM '.$table.' WHERE '.$key.'=?');
        $query->execute([$where[$key]]); return $query->rowCount();
    }
}
class MaintenanceUser extends User {
    public function __construct($db) { $this->db=$db; $this->id=7; }
    public function isSuperAdmin() { return false; }
    public function getRuns($order='id DESC',$limit=null) { throw new RuntimeException('Account cleanup reached before refusal.'); }
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
        INSERT INTO survey_runs VALUES (3,7,"share-only"),(4,7,"ordinary"),(5,7,"unexpected-fk");
        INSERT INTO survey_run_units VALUES (301,3,10,1,"Survey"),(401,4,10,1,"Survey"),(501,5,10,1,"Survey");
        CREATE TABLE maintenance_fk (run_id INT UNSIGNED,FOREIGN KEY (run_id) REFERENCES survey_runs(id)) ENGINE=InnoDB;
        INSERT INTO maintenance_fk VALUES (5)');
    (new QuotaMonitor($db))->enable(3,7);
    foreach ([1,3,4,5] as $id) {
        $db->exec('INSERT INTO survey_uploaded_files VALUES ('.$id.','.$id.',"synthetic'.$id.'.txt");
            INSERT INTO survey_run_special_units VALUES ('.$id.','.$id.')');
        file_put_contents($directory.'webroot/synthetic'.$id.'.txt','Disposable audit resource.');
    }
    $adapter=new MaintenanceDb($db);
    $run=(new ReflectionClass(Run::class))->newInstanceWithoutConstructor(); $run->db=$adapter;
    $evidence=[];
    foreach ([1=>4,3=>1,5=>1] as $id=>$links) {
        $run->id=$id; $run->name='synthetic-'.$id;
        astraAssert($run->delete()===false,'Protected/failing run refused');
        foreach (['survey_runs'=>'id','survey_uploaded_files'=>'run_id','survey_run_special_units'=>'run_id'] as $table=>$column) {
            astraAssert((int)$db->query('SELECT COUNT(*) FROM '.$table.' WHERE '.$column.'='.$id)->fetchColumn()===1,$table.' preserved');
        }
        astraAssert((int)$db->query('SELECT COUNT(*) FROM survey_run_units WHERE run_id='.$id)->fetchColumn()===$links,'Run structure preserved');
        astraAssert(file_exists($directory.'webroot/synthetic'.$id.'.txt'),'Physical file preserved');
        astraAssert(!$db->inTransaction(),'Deletion transaction closed');
        $evidence['preserved'][]=$id;
    }
    $run->id=4; $run->name='ordinary';
    $db->beginTransaction(); astraAssert($run->delete()===false,'Outer transaction refused');
    astraAssert($db->inTransaction() && file_exists($directory.'webroot/synthetic4.txt'),'Outer transaction/file untouched'); $db->rollBack();
    astraAssert($run->delete()===true,'Ordinary run still deletable');
    astraAssert((int)$db->query('SELECT COUNT(*) FROM survey_runs WHERE id=4')->fetchColumn()===0,'Ordinary run deleted');
    astraAssert(!file_exists($directory.'webroot/synthetic4.txt'),'File deleted after commit');
    $account=new MaintenanceUser($adapter);
    astraAssert($account->delete()===false && $account->carefulDelete()!=='','Account cleanup refuses before side effects');
    $evidence['ordinary_deleted']=true; $evidence['account_preserved']=true; $evidence['outer_transaction_preserved']=true;
    echo json_encode($evidence,JSON_THROW_ON_ERROR),PHP_EOL;
} finally {
    $run=null; $account=null; $adapter=null; $db=null;
    if (preg_match('/\Aformr_quota_astra_[0-9a-f]{16}\z/',$name)) { $admin->exec('DROP DATABASE `'.$name.'`'); }
    foreach ([1,3,4,5] as $id) { $path=$directory.'webroot/synthetic'.$id.'.txt'; if (is_file($path)) { unlink($path); } }
    rmdir($directory.'webroot'); rmdir($directory);
}
