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
echo "PASS dynamic-monitor-regression\n";
