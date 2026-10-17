<?php
/** Small structural demonstration only; deliberately avoids a large allocation attack. */
require_once __DIR__.'/../../../application/Services/QuotaRules.php';
$dimensions=[];
for ($i=0;$i<12;$i++) {
    $dimensions[]=['source'=>'dimension_'.$i,'type'=>'category','categories'=>['a'=>'1','b'=>'2']];
}
$start=hrtime(true); $before=memory_get_usage(true);
$rules=new QuotaRules(['crossed'=>['dimensions'=>$dimensions]]);
$result=['dimensions'=>12,'categories_per_dimension'=>2,
    'accepted_group_count'=>count($rules->getGroups()['crossed']),
    'elapsed_ms'=>round((hrtime(true)-$start)/1e6,3),
    'additional_allocated_bytes'=>memory_get_usage(true)-$before];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
if ($result['accepted_group_count']!==4096) { throw new RuntimeException('Group cap behavior changed; re-review.'); }
