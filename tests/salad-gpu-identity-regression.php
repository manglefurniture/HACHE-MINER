<?php
declare(strict_types=1);
require_once __DIR__.'/../app/salad-gpu-identity.php';
require_once __DIR__.'/../app/monitor.php';

function identity_assert(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException($reason);
}
$names=[
  'uuid-5060'=>'NVIDIA GeForce RTX 5060',
  'uuid-5060ti'=>'NVIDIA GeForce RTX 5060 Ti',
  'uuid-5070laptop'=>'NVIDIA GeForce RTX 5070 Laptop GPU',
  'uuid-5070tilaptop'=>'NVIDIA GeForce RTX 5070 Ti Laptop GPU',
  'uuid-5090laptop'=>'NVIDIA GeForce RTX 5090 Laptop GPU',
];
$allowed=array_keys($names);
$names['uuid-rx6800']='AMD Radeon RX 6800';
identity_assert(miner_gpu_class_for_model(['uuid-rx6800'],'RX 6800',$names)==='uuid-rx6800',
    'AMD RX alias must match official Radeon branding');
identity_assert(miner_gpu_class_for_model(['uuid-rx6800'],'Radeon RX 6800',$names)==='uuid-rx6800',
    'Radeon brand must not block legitimate class matching');
identity_assert(miner_gpu_class_for_model($allowed,'RTX 5060',$names)==='uuid-5060',
  '5060 must not be mistaken for 5060 Ti');
identity_assert(miner_gpu_class_for_model($allowed,'RTX 5060 Ti',$names)==='uuid-5060ti',
  '5060 Ti needs its own class');
identity_assert(miner_gpu_class_for_model($allowed,'RTX 5070 Laptop Gpu',$names)==='uuid-5070laptop',
  'Laptop class should match actual SRBMiner label');
identity_assert(miner_gpu_class_for_model($allowed,'RTX 5070 Ti Laptop Gpu',$names)==='uuid-5070tilaptop',
  'Ti Laptop must differ from Laptop');
identity_assert(miner_gpu_class_for_model($allowed,'RTX 5090 Laptop Gpu',$names)==='uuid-5090laptop',
  '5090 laptop class must match');
identity_assert(miner_gpu_class_for_model($allowed,'RTX 5070 Ti Laptop...',$names)===null,
  'Truncated labels cannot prove a class');
identity_assert(miner_gpu_class_for_model($allowed,'RTX 5070 Laptop',$names)==='uuid-5070laptop',
  'Equivalent full model without GPU suffix should match');
identity_assert(miner_gpu_class_for_model(['uuid-5060ti'],'RTX 5060',$names)===null,
  'A class cannot be selected just because it is allowed');
identity_assert(miner_gpu_class_for_model(['uuid-5060'],'SIN_MODELO',$names)===null,
  'Missing model must remain unknown for multi-class groups');
$ambiguous=$names+['uuid-5070laptop-alt'=>'NVIDIA RTX 5070 Laptop GPU 8 GB'];
identity_assert(miner_gpu_class_for_model(array_keys($ambiguous),'RTX 5070 Laptop GPU',$ambiguous)===null,
  'Duplicate equivalent classes (VRAM variants) must remain unpriced');

$now=1_800_000_000;$at=gmdate('Y-m-d H:i:s',$now-100);
$groups=[[
    'organization'=>'interactive','project_name'=>'default','group_name'=>'mixed','id'=>7,
    'state'=>'running','recent'=>true,'gpu_class'=>null,'priority'=>'batch',
    'instances'=>[
       ['id'=>'gpu-a','state'=>'running','ready'=>true,'observed_at'=>$at,'gpu_class'=>'uuid-5060'],
       ['id'=>'gpu-b','state'=>'running','ready'=>true,'observed_at'=>$at,'gpu_class'=>'uuid-5070laptop'],
       ['id'=>'gpu-c','state'=>'running','ready'=>true,'observed_at'=>$at,'gpu_class'=>null],
    ]
]];
$targets=[['organization_slug'=>'interactive','project_slug'=>'default','enabled'=>1]];
$prices=[
 ['organization'=>'interactive','gpu_class'=>'uuid-5060','priority'=>'lowest','usd_per_hour'=>'0.060000'],
 ['organization'=>'interactive','gpu_class'=>'uuid-5070laptop','priority'=>'lowest','usd_per_hour'=>'0.080000'],
 ['organization'=>'interactive','gpu_class'=>'uuid-5060ti','priority'=>'lowest','usd_per_hour'=>'0.070000'],
];
$cost=miner_monitor_live_hourly_cost($groups,$targets,$prices,$now);
identity_assert($cost['priced']===2 && $cost['unpriced']===1,'Only two matched machines may be priced');
identity_assert(abs($cost['usd_per_hour']-0.14)<0.00000001,'Price must be sum of per-instance official rates');
identity_assert($cost['complete']===false,'Unknown machine means partial total, never 0');
identity_assert(count($cost['details'])===2,'Each real GPU class needs separate subtotal');
$groups[0]['instances'][1]['gpu_class']=null;
$cost=miner_monitor_live_hourly_cost($groups,$targets,$prices,$now);
identity_assert($cost['priced']===1 && $cost['unpriced']===2,'Missing individual identity cannot inherit another replica price');

// Salad may provide a structured per-instance class. Never infer it from the
// group's list of permitted classes, or accept an unpermitted ID.
identity_assert(miner_gpu_class_from_instance(['gpu_class_id'=>'uuid-5060'], $allowed)==='uuid-5060',
  'Explicit per-instance class may be used for official prices');
identity_assert(miner_gpu_class_from_instance(['gpu_class_id'=>'uuid-5060ti'], $allowed)==='uuid-5060ti',
  'Ti must retain its distinct explicit class');
identity_assert(miner_gpu_class_from_instance(['gpu_classes'=>['uuid-5060']], $allowed)===null,
  'Group-like allowed list is not per-instance identity');
identity_assert(miner_gpu_class_from_instance(['gpu_class_id'=>'other'], $allowed)===null,
  'Unsupported instance class must not be priced');
identity_assert(miner_gpu_instance_class_claimed(['gpu_class_id'=>'other']),
  'Invalid explicit identity must suppress historical and singleton fallbacks');
identity_assert(miner_gpu_instance_class_claimed(['gpu_class_id'=>'uuid-5060','gpu_class'=>'uuid-5060ti']),
  'Contradictory explicit identity must be treated as a claim');
identity_assert(!miner_gpu_instance_class_claimed(['gpu_classes'=>['uuid-5060'],'gpu_class'=>null]),
  'Absent or null per-instance class is not a contradictory identity');
identity_assert(miner_gpu_class_from_instance(['gpu_class_id'=>'uuid-5060','gpu_class'=>'uuid-5060ti'], $allowed)===null,
  'Conflicting explicit class fields must remain unknown');

// Real SRBMiner zero-speed rows still reveal GPU hardware, without proving
// mining activity. No group's aggregate or share lines may be used.
$zero='[2026-10-13 12:38:00] #0 RTX 5050 0.00 H/s 5.4W - 41% 33C';
$zeroMeasurement=miner_monitor_log_metric($zero);
identity_assert($zeroMeasurement!==null && $zeroMeasurement['hashrate_ths']===0.0,
  'Verified zero H/s device row is measured zero production, not missing data');
identity_assert(miner_monitor_log_gpu_identity($zero)==='RTX 5050',
  'Zero-speed indexed GPU row should expose model');
identity_assert(miner_monitor_log_gpu_identity('[2026-10-13 12:38:00] #0 RTX 5060 Laptop 0.00 H/s 5.4W')==='RTX 5060 Laptop',
  'Laptop suffix must not be truncated by trim mask');
identity_assert(miner_monitor_log_gpu_identity('Hashrate 0.00 H/s 5.4W')===null,
  'Aggregate zero speed cannot prove a GPU model');
identity_assert(miner_monitor_log_gpu_identity('GPU0 share accepted')===null,
  'Share messages cannot prove hardware identity');
$clock=strtotime('2026-10-13 12:43:00 UTC');
$nodeA=['instance_id'=>'gpu-a','machine_id'=>'machine-a','state'=>'running','started'=>true,'ready'=>true,
 'update_time'=>'2026-10-13T12:31:00Z'];
$nodeB=['instance_id'=>'gpu-b','machine_id'=>'machine-b','state'=>'running','started'=>true,'ready'=>true,
 'update_time'=>'2026-10-13T12:31:00Z'];
$zeroEvidence=['time'=>'2026-10-13T12:42:00Z','text_log'=>$zero,
 'resource'=>['labels'=>['machine_id'=>'machine-a']]];
$anonymous=['time'=>'2026-10-13T12:42:00Z','text_log'=>$zero];
$models=miner_monitor_instance_log_gpu_models([$zeroEvidence,$anonymous],[$nodeA,$nodeB],$clock);
identity_assert(($models['gpu-a']??null)==='RTX 5050' && !isset($models['gpu-b']),
  'Only verified exact instance may use zero-speed hardware identity');
identity_assert(str_ends_with(miner_monitor_tagged_log_summary($zeroEvidence,[$nodeA,$nodeB]),
  '[MONITOR_GPU_ATTRIBUTED]'),'Verified zero-speed identity must be auditable');
identity_assert(str_ends_with(miner_monitor_tagged_log_summary($anonymous,[$nodeA,$nodeB]),
  '[MONITOR_GPU_UNATTRIBUTED]'),'Anonymous zero-speed row cannot be assigned in mixed group');
$conflicting=$zeroEvidence;
$conflicting['time']='2026-10-13T12:41:00Z';
$conflicting['text_log']='[2026-10-13 12:41:00] #0 RTX 5060 0.00 H/s 5.4W';
$models=miner_monitor_instance_log_gpu_models([$zeroEvidence,$conflicting],[$nodeA,$nodeB],$clock);
identity_assert(array_key_exists('gpu-a',$models) && $models['gpu-a']===null,
  'Different models on one instance must fail closed without a class');

$collector=file_get_contents(__DIR__.'/../bin/poll.php');
$monitor=file_get_contents(__DIR__.'/../app/monitor.php');
$official=file_get_contents(__DIR__.'/../app/salad-gpu-prices.php');
$sql=file_get_contents(__DIR__.'/../database/007_salad_gpu_class_names.sql');
identity_assert(str_contains($collector,'miner_gpu_class_for_model(')
 && str_contains($collector,'miner_gpu_instance_class_claimed(')
 && str_contains($collector,'($explicitClaim && $explicitClass===null)')
 && str_contains($collector,'miner_gpu_previous_running_class('),
 'Collector must identify each actual GPU model before pricing');
identity_assert(str_contains($collector,'gpu_classes'), 'Allowed Salad classes must be maintained');
identity_assert(str_contains($monitor,"m.gpu_class_at_observation"),
 'Monitor must use saved per-instance class, never group-only class');
identity_assert(str_contains($official,'INSERT INTO salad_gpu_class_names'),
 'Official catalog name must be cached with authenticated prices');
identity_assert(str_contains($sql,'PRIMARY KEY (organization,gpu_class)'),
 'Name cache must be scoped to exact organization and class');
echo "SALAD_GPU_IDENTITY_REGRESSION_OK\n";
