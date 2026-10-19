<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/vast-enrollment.php';
function expect_fail(array $value): void {
    try{
        miner_vast_enrollment_from_provider($value);
        throw new LogicException('unsafe enrollment accepted');
    }catch(DomainException $expected){}
}
$good=[
 'id'=>56667162,'machine_id'=>151300,'host_id'=>467312,
 'actual_status'=>'running','intended_status'=>'running',
 'num_gpus'=>8,'dph_total'=>4.290370,
 'ssh_host'=>'ssh8.vast.ai','ssh_port'=>10566,
];
$r=miner_vast_enrollment_from_provider($good);
if($r['instance_id']!=='56667162'||$r['machine_id']!=='151300'||
   $r['host_id']!=='467312'||$r['gpu_count']!==8||
   $r['hourly_usd']!=='4.290370')throw new LogicException('wrong projected inventory');
foreach([
 ['actual_status'=>'exited'], ['intended_status'=>'stopped'],
 ['id'=>'../../../etc/passwd'], ['machine_id'=>0],['host_id'=>null],
 ['num_gpus'=>0],['num_gpus'=>17],['num_gpus'=>'8'],
 ['dph_total'=>-1],['dph_total'=>'NaN'],['dph_total'=>101],
 ['ssh_host'=>null],['ssh_port'=>false],['ssh_port'=>0]
] as $changed)expect_fail(array_replace($good,$changed));
echo "VAST_ENROLLMENT_REGRESSION_OK\n";
