<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/monitor.php';
function monitor_assert(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException($reason);
}
monitor_assert(miner_monitor_status('running',true,4,4,4)==='listas','all ready');
monitor_assert(miner_monitor_status('running',true,4,4,2)==='parcial','partial ready');
monitor_assert(miner_monitor_status('running',true,4,0,0)==='sin_instancias','allocation missing');
monitor_assert(miner_monitor_status('pending',true,4,0,0)==='esperando','pending group');
monitor_assert(miner_monitor_status('stopped',true,4,0,0)==='detenido','stopped group');
monitor_assert(miner_monitor_status('running',false,4,4,4)==='sin_lectura','stale is not healthy');
monitor_assert(miner_monitor_status('running',true,0,0,0)==='sin_replicas','zero configured');
$line="\x1B[0m[2026-10-09 16:00:00] \x1B[1mGPU0 #0 RTX 4070 Ti SUPER 160.25 TH/s 254.0W 0.63 88% 69C\x1B[0m";
$metric=miner_monitor_log_metric($line);
monitor_assert(is_array($metric) && $metric['hashrate_ths']===160.25,'SRBMiner line metrics');
monitor_assert($metric['watts']===254.0 && $metric['fan']===88 && $metric['temp_c']===69,'GPU temp/fan/watts');
monitor_assert(miner_monitor_log_metric('share rejected [stale share]')===null,'No invented hashrate');
monitor_assert(miner_monitor_average_15m('15 min 125.4 TH/s')===125.4,'15 minute average');

$laptop=miner_monitor_log_metric('GPU 0 - NVIDIA GeForce RTX 4070 Laptop GPU: 80.37 TH/s');
monitor_assert($laptop!==null && $laptop['hashrate_ths']===80.37
    && str_contains($laptop['gpu'],'RTX 4070 Laptop')
    && $laptop['watts']===null && $laptop['temp_c']===null,
    'RTX 4070 Laptop is measured without mandatory power/fan/temp');
$laptop2=miner_monitor_log_metric('GPU[1] GeForce RTX 4070 Laptop GPU | 78.25 TH/s 87W fan:40% temp:62C');
monitor_assert($laptop2!==null && $laptop2['hashrate_ths']===78.25 && $laptop2['watts']===87.0,
    'Alternate SRBMiner GPU prefix and optional wattage');
$other=miner_monitor_log_metric('GPU2: NVIDIA RTX 5090 Laptop 165.5 TH/s 170.4W 58C');
monitor_assert($other!==null && $other['hashrate_ths']===165.5,'GPU parser must not whitelist card models');
monitor_assert(miner_monitor_log_metric('Pool total 150.0 TH/s')===null,'Pool aggregate cannot impersonate a GPU');
monitor_assert(miner_monitor_log_metric('GPU 0 accepted share 80 TH/s')===null,'Share statistics must not impersonate GPU hashrate');
monitor_assert(miner_monitor_log_metric('GPU 0 average 15 min 80 TH/s')===null,'Rolling average is not individual instant GPU hashrate');
monitor_assert(miner_monitor_log_metric('GPU 0 RTX 4070 Laptop GPU 80 MH/s')===null,'Wrong units cannot be called TH/s');

monitor_assert(miner_monitor_average_15m('GPU0 190 TH/s')===null,'instantaneous not averaged');

$nodeA=['instance_id'=>'a-111','machine_id'=>'machine-a','state'=>'running','ready'=>true,'started'=>true,'update_time'=>'2026-10-09T18:40:00Z'];
$nodeB=['instance_id'=>'b-222','machine_id'=>'machine-b','state'=>'running','ready'=>true,'started'=>true,'update_time'=>'2026-10-09T18:40:00Z'];
$time=(new DateTimeImmutable('2026-10-09T18:45:00Z'))->getTimestamp();
$line='GPU0 #0 RTX 4070 Ti SUPER 158.13 TH/s 227.9W 0.62 40% 59C';
$metricLogs=[
 ['time'=>'2026-10-09T18:44:00Z','text_log'=>$line,'resource'=>['labels'=>['instance_id'=>'a-111']]],
 ['time'=>'2026-10-09T18:43:00Z','text_log'=>'GPU0 #0 RTX 4070 Ti SUPER 117.13 TH/s 227.9W 0.62 40% 59C','resource'=>['labels'=>['machine_id'=>'machine-b']]],
 ['time'=>'2026-10-09T18:44:30Z','text_log'=>'GPU0 #0 RTX 4070 Ti SUPER 999.00 TH/s 227.9W 0.62 40% 59C'],
];
$matched=miner_monitor_instance_log_metrics($metricLogs,[$nodeA,$nodeB],$time);
monitor_assert(count($matched)===2,'Multi-node readings should map to two exact known instances');
monitor_assert($matched['a-111']['hashrate_ths']===158.13,'First instance assigned incorrect hashrate');
monitor_assert($matched['b-222']['hashrate_ths']===117.13,'Second machine_id mapping failed');
monitor_assert(miner_monitor_log_instance($metricLogs[2],[$nodeA,$nodeB])===null,'Anonymous multi-node log must NOT be assigned');

monitor_assert(miner_monitor_log_instance([
    'resource'=>['labels'=>['containerGroupInstanceId'=>'b-222']],
    'time'=>'2026-10-09T18:44:00Z'
],[$nodeA,$nodeB])==='b-222','CamelCase Salad instance label should be recognized');
monitor_assert(miner_monitor_log_instance([
    'labels'=>['instance_id'=>'a-111'],
    'time'=>'2026-10-09T18:44:00Z'
],[$nodeA,$nodeB])==='a-111','Top-level labels should identify individual logs');
monitor_assert(miner_monitor_log_instance([
    'resource'=>['labels'=>['instance_id'=>'a-111','machine_id'=>'machine-b']],
    'time'=>'2026-10-09T18:44:00Z'
],[$nodeA,$nodeB])===null,'Conflicting Salad resource labels must fail closed');
$workerA=['instance_id'=>'11111111-1111-4111-8111-111111111111','machine_id'=>'machine-a',
    'state'=>'running','ready'=>true,'started'=>true,'update_time'=>'2026-10-09T18:40:00Z'];
$workerB=['instance_id'=>'22222222-2222-4222-8222-222222222222','machine_id'=>'machine-b',
    'state'=>'running','ready'=>true,'started'=>true,'update_time'=>'2026-10-09T18:40:00Z'];
monitor_assert(miner_monitor_log_instance([
    'text_log'=>'worker id 11111111-1111-4111-8111-111111111111 GPU 0 RTX 4070 Laptop GPU 80 TH/s',
    'time'=>'2026-10-09T18:44:00Z'
],[$workerA,$workerB])===$workerA['instance_id'],
    'Explicit full worker UUID in log can identify replica even with missing resource labels');
monitor_assert(miner_monitor_log_instance([
    'text_log'=>'worker id 11111111-1111-4111-8111-111111111111 and 22222222-2222-4222-8222-222222222222',
    'time'=>'2026-10-09T18:44:00Z'
],[$workerA,$workerB])===null,'Two different worker UUIDs cannot be falsely assigned to one GPU');

monitor_assert(miner_monitor_log_instance(['resource'=>['labels'=>['instance_id'=>'unknown']], 'time'=>'2026-10-09T18:44:00Z'],[$nodeA,$nodeB])===null,'Unknown instance cannot be assigned');
monitor_assert(miner_monitor_log_instance(['resource'=>['labels'=>['instance_id'=>'a-111','machine_id'=>'machine-b']]],[$nodeA,$nodeB])===null,'Conflicting identity labels must fail closed');
monitor_assert(miner_monitor_log_instance(['time'=>'2026-10-09T18:41:00Z','text_log'=>$line],[$nodeA])==='a-111','Unique running node post-transition can receive unlabelled log');
monitor_assert(miner_monitor_log_instance(['time'=>'2026-10-09T18:30:00Z','text_log'=>$line],[$nodeA])===null,'Log before last running transition cannot be attributed');
monitor_assert(miner_monitor_profit_signal('prl-low-4070-ti-super','low',['hashrate_ths'=>158.13,'gpu_model'=>'RTX 4070 Ti SUPER'])['level']==='green','PRL healthy reference');
monitor_assert(miner_monitor_profit_signal('prl-low-4070-ti-super','low',['hashrate_ths'=>132,'gpu_model'=>'RTX 4070 Ti SUPER'])['level']==='yellow','PRL watch reference');
monitor_assert(miner_monitor_profit_signal('prl-low-4070-ti-super','low',['hashrate_ths'=>125,'gpu_model'=>'RTX 4070 Ti SUPER'])['level']==='red','PRL warning reference');
monitor_assert(miner_monitor_profit_signal('quantus-trial','low',['hashrate_ths'=>110,'gpu_model'=>'RTX 4070 Ti SUPER'])['level']==='neutral','Quantus cannot inherit PRL reference');
monitor_assert(miner_monitor_profit_signal('prl-low-5080','low',['hashrate_ths'=>110,'gpu_model'=>'RTX 5080'])['level']==='neutral','Other GPUs must not be judged by 4070 margin');
monitor_assert(miner_monitor_profit_signal('prl-low-4070-ti-super','medium',['hashrate_ths'=>145,'gpu_model'=>'RTX 4070 Ti SUPER'])['level']==='neutral','Low reference cannot apply to medium cost');
monitor_assert(miner_monitor_profit_signal('prl-low-4070-ti-super','low',null)['level']==='unknown','Missing telemetry not red nor green');
monitor_assert(miner_monitor_sparkline([])===null,'Empty series must not draw fabricated line');
monitor_assert(miner_monitor_sparkline([158.1])===null,'One reading is not a trend');
$chart=miner_monitor_sparkline([156.3,158.13,162.0]);
monitor_assert(is_string($chart) && substr_count($chart,' ')===2 && !str_contains($chart,'NaN'),'Last three measurements must draw a 3-point line');

$summaryNow=(new DateTimeImmutable('2026-10-09T21:15:00Z'))->getTimestamp();
$summaryTargets=[
 ['organization_slug'=>'hache','project_slug'=>'prl-tests','enabled'=>1],
 ['organization_slug'=>'interactive','project_slug'=>'default','enabled'=>1],
 ['organization_slug'=>'disabled','project_slug'=>'test','enabled'=>0]
];
$measuredNode=static function(string $id,?float $hash,string $when='2026-10-09 21:14:00'):array {
    return [
      'id'=>$id,'ready'=>true,'state'=>'running',
      'metric'=>$hash===null?null:['hashrate_ths'=>$hash,'at'=>$when]
    ];
};
$summaryGroups=[
 ['id'=>11,'organization'=>'hache','project_name'=>'prl-tests','state'=>'running','recent'=>true,'instances'=>[
    $measuredNode('h1',158.13),
    $measuredNode('h2',169.05),
    $measuredNode('h2',169.05),
    $measuredNode('h3',null)]],
 ['id'=>22,'organization'=>'interactive','project_name'=>'default','state'=>'running','recent'=>true,'instances'=>[
    $measuredNode('i1',162.85),
    $measuredNode('i2',52.06),
    $measuredNode('i3',50.00,'2026-10-09 21:00:00')]],
 ['id'=>33,'organization'=>'hache','project_name'=>'prl-tests','state'=>'stopped','recent'=>true,'instances'=>[
    $measuredNode('stopped',300.00)]],
 ['id'=>44,'organization'=>'interactive','project_name'=>'default','state'=>'running','recent'=>false,'instances'=>[
    $measuredNode('old-group',400.00)]],
 ['id'=>55,'organization'=>'disabled','project_name'=>'test','state'=>'running','recent'=>true,'instances'=>[
    $measuredNode('paused-project',1000.00)]]
];
$aggregate=miner_monitor_live_hashrate($summaryGroups,$summaryTargets,$summaryNow);
monitor_assert($aggregate['ths']===542.09,'Total TH/s must sum only unique fresh effective instance metrics');
monitor_assert($aggregate['ready']===6 && $aggregate['measured']===4 && $aggregate['missing']===2,
    'Coverage must report unavailable and stale instances without making them zero');
monitor_assert(!$aggregate['complete'],'Missing telemetry must mark total as partial');
monitor_assert($aggregate['organizations']['hache']['ths']===327.18
    && $aggregate['organizations']['interactive']['ths']===214.91,
    'Organization subtotals must add to global verified total');
monitor_assert($aggregate['last_at']==='2026-10-09 21:14:00'
    && $aggregate['oldest_at']==='2026-10-09 21:14:00',
    'Summary must report actual effective observation window');
$allReported=miner_monitor_live_hashrate([[
 'id'=>11,'organization'=>'hache','project_name'=>'prl-tests','state'=>'running','recent'=>true,
 'instances'=>[$measuredNode('h1',158.13),$measuredNode('h2',169.05)]
]],$summaryTargets,$summaryNow);
monitor_assert($allReported['complete'] && $allReported['measured']===2 && $allReported['ths']===327.18,
    'Full coverage must be shown only for all identified current ready machines');
$empty=miner_monitor_live_hashrate([[
 'id'=>11,'organization'=>'hache','project_name'=>'prl-tests','state'=>'running','recent'=>true,
 'instances'=>[$measuredNode('h1',null)]
]],$summaryTargets,$summaryNow);
monitor_assert($empty['ths']===null && $empty['measured']===0 && !$empty['complete'],
    'No telemetry must be unknown, never 0 TH/s');
$zero=miner_monitor_live_hashrate([[
 'id'=>11,'organization'=>'hache','project_name'=>'prl-tests','state'=>'running','recent'=>true,
 'instances'=>[$measuredNode('h1',0.0)]
]],$summaryTargets,$summaryNow);
monitor_assert($zero['ths']===0.0 && $zero['complete'],
    'Genuine measured zero TH/s must remain distinct from missing telemetry');
$clockFuture=miner_monitor_live_hashrate([[
 'id'=>11,'organization'=>'hache','project_name'=>'prl-tests','state'=>'running','recent'=>true,
 'instances'=>[$measuredNode('h1',158.13,'2026-10-09 21:18:00')]
]],$summaryTargets,$summaryNow);
monitor_assert($clockFuture['ths']===null,'Future timestamps must be excluded');
echo "PASS dynamic-monitor-regression\n";
