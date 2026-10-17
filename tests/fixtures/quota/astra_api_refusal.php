<?php
/** Isolated reproduction: real Run refusal and real API response, no app config or sockets. */
class Model { public $db; }
class AstraGuardDb {
    public function findValue($table,$where,$field) { return $table==='survey_quota_drafts' ? 1 : false; }
}
class Site {
    public static array $alerts=[];
    public static function getInstance() { return new self(); }
    public function renderAlerts() { $text=implode(' ',self::$alerts); self::$alerts=[]; return $text; }
}
function alert($message,$kind) { Site::$alerts[]=$message; }
abstract class BaseResource {
    public function response($code,$message,$payload) { return compact('code','message','payload'); }
    public function error($code,$message) { return compact('code','message'); }
}
require_once __DIR__.'/../../../application/Model/Run.php';
require_once __DIR__.'/../../../application/Api/V1/StructureResource.php';
class AstraExistingRun extends Run {
    public function getAllUnitIds() { return [1,2,3,4,5]; }
}
class AstraInput {
    public $context; private int $offset=0;
    public function stream_open($path,$mode,$options,&$opened): bool { return true; }
    public function stream_read($count): string {
        $value=substr('{"units":[{"type":"Page","position":1}]}',$this->offset,$count);
        $this->offset+=strlen($value); return $value;
    }
    public function stream_eof(): bool { return $this->offset>=40; }
    public function stream_stat(): array { return []; }
}
$run=(new ReflectionClass(AstraExistingRun::class))->newInstanceWithoutConstructor();
$run->id=1; $run->db=new AstraGuardDb();
$direct=$run->replaceUnits('{"units":[{"type":"Page","position":1}]}');
if ($direct!==false) { throw new RuntimeException('Expected native saved-quota replacement refusal.'); }
$resource=new StructureResource();
(new ReflectionProperty(StructureResource::class,'run'))->setValue($resource,$run);
stream_wrapper_unregister('php'); stream_wrapper_register('php',AstraInput::class);
$response=(new ReflectionMethod(StructureResource::class,'importStructure'))->invoke($resource);
stream_wrapper_restore('php');
echo json_encode(['native_replace_result'=>$direct,'api_response'=>$response],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
if ($response['code']!==200 || $response['payload']['units_imported']!==5) {
    throw new RuntimeException('The audited false-success behavior changed; review this reproduction.');
}
