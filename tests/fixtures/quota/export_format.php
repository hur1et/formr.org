<?php
// Isolated download writer: exits normally just as an HTTP download does.
$repo=dirname(__DIR__,3);
define('APPLICATION_ROOT',$repo.'/');
require $repo.'/vendor/autoload.php';
require $repo.'/application/Config.php';
Config::initialize(['memory_limit'=>['spr_object_array'=>'256M'],'use_xsendfile'=>false]);
require $repo.'/application/Functions.php';
require $repo.'/application/Spreadsheet/SpreadsheetReader.php';
$format=$argv[1]; $output=$argv[2];
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE answer_export (session TEXT,session_id INTEGER,study_id INTEGER,
    iteration INTEGER,created TEXT,modified TEXT,ended TEXT,expired TEXT,
    quota_status TEXT,quota_counted INTEGER,quota_income_party_group TEXT,
    income INTEGER,party_preference TEXT,note TEXT)');
$insert=$pdo->prepare('INSERT INTO answer_export VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$insert->execute(['P001',101,20,1,'2026-10-16 10:00:00',null,'2026-10-16 10:05:00',null,
    'admitted',1,'high|b',2500,'B','A, B – quoted']);
$insert->execute(['P002',102,20,2,'2026-10-16 10:01:00',null,null,null,null,0,null,1500,'A','001']);
$stmt=$pdo->query('SELECT * FROM answer_export ORDER BY session_id');
$filename='quota_format_test_'.bin2hex(random_bytes(8));
$download=$repo.'/tmp/downloads/'.$filename.'.json';
if ($format==='json' && !is_dir(dirname($download))) { mkdir(dirname($download),0777,true); }
ob_start();
register_shutdown_function(static function () use ($output,$download) {
    $bytes=ob_get_contents(); ob_end_clean();
    if (error_get_last() && in_array(error_get_last()['type'],[E_ERROR,E_PARSE,E_COMPILE_ERROR],true)) { return; }
    file_put_contents($output,$bytes);
    if (is_file($download)) { unlink($download); }
});
(new SpreadsheetReader())->exportInRequestedFormat($stmt,$filename,$format);
throw new RuntimeException('Download writer did not finish.');
