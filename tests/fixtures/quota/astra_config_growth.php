<?php
/** Regression: the previously accepted 4096-group configuration is refused before expansion. */
require_once __DIR__.'/../../../application/Services/QuotaRules.php';
$dimensions=[];
for ($i=0;$i<12;$i++) {
    $dimensions[]=['source'=>'dimension_'.$i,'type'=>'category','categories'=>['a'=>'1','b'=>'2']];
}
$start=hrtime(true); $before=memory_get_usage(true);
try {
    new QuotaRules(['crossed'=>['dimensions'=>$dimensions]]);
    throw new RuntimeException('Oversized quota configuration was accepted.');
} catch (InvalidArgumentException $expected) {
    if (!str_contains($expected->getMessage(),'at most 500')) { throw $expected; }
}
$result=['dimensions'=>12,'categories_per_dimension'=>2,
    'requested_group_count'=>4096,'maximum'=>QuotaRules::MAX_GROUPS,'rejected'=>true,
    'elapsed_ms'=>round((hrtime(true)-$start)/1e6,3),
    'additional_allocated_bytes'=>memory_get_usage(true)-$before];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
