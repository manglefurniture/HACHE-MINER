<?php
declare(strict_types=1);
require_once __DIR__.'/../app/monitor.php';
function gpu_name_check(bool $value,string $reason):void {if(!$value)throw new RuntimeException($reason);}
$labels=[
    'interactive'."\\0".'gpu-5060'=>'NVIDIA GeForce RTX 5060',
    'regla'."\\0".'gpu-5090l'=>'NVIDIA GeForce RTX 5090 Laptop GPU',
];
gpu_name_check(miner_monitor_gpu_display_name('interactive','gpu-5060',$labels)==='NVIDIA GeForce RTX 5060',
    'Official catalog name must replace UUID');
gpu_name_check(miner_monitor_gpu_display_name('regla','gpu-5090l',$labels)==='NVIDIA GeForce RTX 5090 Laptop GPU',
    'Names must be scoped by organization and model');
gpu_name_check(miner_monitor_gpu_display_name('interactive','gpu-5090l',$labels)==='Nombre de GPU no disponible',
    'Unknown class cannot use another organization label');
gpu_name_check(miner_monitor_gpu_display_name('regla','',$labels)==='GPU sin identificar',
    'Missing class must not fabricate an identification');
$time=strtotime('2026-10-12 12:00:00 UTC');
$at=gmdate('Y-m-d H:i:s',$time-60);
$groups=[
    ['id'=>1,'organization'=>'interactive','project_name'=>'default','group_name'=>'mixed',
     'recent'=>true,'state'=>'running','priority'=>'batch','gpu_class'=>null,
     'instances'=>[
        ['id'=>'a','state'=>'running','ready'=>true,'observed_at'=>$at,'gpu_class'=>'gpu-5060']
     ]],
    ['id'=>2,'organization'=>'regla','project_name'=>'default','group_name'=>'multi',
     'recent'=>true,'state'=>'running','priority'=>'batch','gpu_class'=>null,
     'instances'=>[
        ['id'=>'b','state'=>'running','ready'=>true,'observed_at'=>$at,'gpu_class'=>'gpu-5090l'],
        ['id'=>'c','state'=>'running','ready'=>true,'observed_at'=>$at,'gpu_class'=>null]
     ]]
];
$targets=[
 ['organization_slug'=>'interactive','project_slug'=>'default','enabled'=>1],
 ['organization_slug'=>'regla','project_slug'=>'default','enabled'=>1]
];
$rates=[
 ['organization'=>'interactive','gpu_class'=>'gpu-5060','priority'=>'lowest','usd_per_hour'=>'0.0600'],
 ['organization'=>'regla','gpu_class'=>'gpu-5090l','priority'=>'lowest','usd_per_hour'=>'0.1000'],
];
$result=miner_monitor_live_hourly_cost($groups,$targets,$rates,$time,$labels);
gpu_name_check($result['priced']===2 && $result['unpriced']===1 && abs($result['usd_per_hour']-0.16)<1e-9,
    'Names must never alter cost arithmetic');
gpu_name_check(count($result['details'])===2 && $result['details'][0]['gpu_name']==='NVIDIA GeForce RTX 5060'
    && $result['details'][1]['gpu_name']==='NVIDIA GeForce RTX 5090 Laptop GPU',
    'Priced lines must have human-readable GPU names');
gpu_name_check($result['missing_details'][0]['gpu_name']==='GPU sin identificar',
    'Unpriced unknown model must be labeled unknown, not billed or guessed');
$page=file_get_contents(__DIR__.'/../public/index.php');
gpu_name_check(str_contains($page,'miner_h($d[\'gpu_name\'])')
    && !str_contains($page,'miner_h($d[\'gpu_class\'])')
    && !str_contains($page,'miner_h($d[\'gpu_class\']!=='),
    'HTML must not expose class UUIDs where GPU names belong');
echo "MONITOR_GPU_NAMES_REGRESSION_OK\n";
