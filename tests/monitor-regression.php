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
echo "PASS dynamic-monitor-regression\n";
