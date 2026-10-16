<?php
// Isolated router regression: exercise the actual route/controller classes in both modes.
define('FMRSD_CONTEXT',($argv[1]??'')==='subdomain');
class Config {
    public static function get($key,$default=null) {
        return ['routes'=>['public'=>'PublicController','run'=>'RunController','admin/run'=>'AdminRunController'],
            'use_study_subdomains'=>FMRSD_CONTEXT][$key]??$default;
    }
}
require_once __DIR__.'/../../../application/Controller/Controller.php';
require_once __DIR__.'/../../../application/Controller/RunController.php';
require_once __DIR__.'/../../../application/Controller/PublicController.php';
require_once __DIR__.'/../../../application/Controller/AdminController.php';
require_once __DIR__.'/../../../application/Controller/AdminRunController.php';
require_once __DIR__.'/../../../application/Router.php';
$_SERVER['SERVER_NAME']=FMRSD_CONTEXT?'demo.example.org':'127.0.0.1';
$site=new class {
    public $request;
    public function __construct() {
        $this->request=new class {
            public function str($key) {
                return ($GLOBALS['argv'][1]??'')==='admin' ? 'admin/run/Quoten_Demo/quota_share'
                    : (FMRSD_CONTEXT?'quotas':'Quoten_Demo/quotas');
            }
        };
    }
    public function setPath($path) {}
};
$router=(new Router($site))->route();
$actual=[];
foreach (['controller','action','params'] as $property) {
    $actual[$property]=(new ReflectionProperty(Router::class,$property))->getValue($router);
}
$expected=($argv[1]??'')==='admin'
    ? ['controller'=>'AdminRunController','action'=>'indexAction','params'=>['Quoten_Demo','quota_share']]
    : ['controller'=>'RunController','action'=>'indexAction','params'=>[FMRSD_CONTEXT?'demo':'Quoten_Demo','quotas']];
if ($actual!==$expected || !method_exists(RunController::class,'quotasAction')) { throw new RuntimeException('Quota route mismatch.'); }
$admin=(new ReflectionClass(AdminRunController::class))->newInstanceWithoutConstructor();
$method=(new ReflectionMethod(Controller::class,'getPrivateAction'))->invoke($admin,'quota_share');
if ($method!=='quotaShareAction') { throw new RuntimeException('Quota share route mismatch.'); }
echo 'Quota route OK',PHP_EOL;
