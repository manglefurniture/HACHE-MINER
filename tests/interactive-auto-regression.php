<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/interactive-auto.php';
function auto_check(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException($label);
}
$now=(new DateTimeImmutable('2026-10-09T21:10:00+00:00'))->getTimestamp();
$row=static function(string $date,float $hash,string $gpu='RTX 4070 Ti SUPER',string $state='running'):array{
    return ['observed_at'=>$date,'state'=>$state,'ready'=>1,'started'=>1,
        'hashrate_ths'=>$hash,'gpu_model'=>$gpu];
};
$samples=[
 $row('2026-10-09 21:09:00',52.06),
 $row('2026-10-09 21:04:00',51.72),
 $row('2026-10-09 20:59:00',50.99)
];
$good=miner_auto_low_prl_metric($samples,$now);
auto_check(is_array($good),'50 TH/s held for 10min must become eligible');
auto_check($good['span_seconds']===600,'Sample duration incorrect');
auto_check($good['hashrate_ths']===52.06,'Latest sample must be preserved');
auto_check(miner_auto_low_prl_metric([$samples[0]],$now)===null,'Single low reading must not trigger');
auto_check(miner_auto_low_prl_metric([$samples[0],$samples[1]],$now)===null,'Two samples not enough');
auto_check(miner_auto_low_prl_metric([$row('2026-10-09 21:09:00',125),$samples[1],$samples[2]],$now)!==null,'Exact 125 threshold should qualify');
auto_check(miner_auto_low_prl_metric([$row('2026-10-09 21:09:00',125.01),$samples[1],$samples[2]],$now)===null,'No action above threshold');
auto_check(miner_auto_low_prl_metric([$samples[0],$row('2026-10-09 21:04:00',156),$samples[2]],$now)===null,'Recovered GPU must not trigger');
auto_check(miner_auto_low_prl_metric([$samples[0],$samples[1],$row('2026-10-09 20:59:00',52.0,'RTX 5080')],$now)===null,'Different GPU model must be excluded');
auto_check(miner_auto_low_prl_metric([$samples[0],$samples[1],$row('2026-10-09 20:59:00',52.0,'RTX 4070 Ti SUPER','allocating')],$now)===null,'Allocating not eligible');
auto_check(miner_auto_low_prl_metric([$samples[0],$samples[1],array_replace($row('2026-10-09 20:59:00',52.0),['ready'=>0])],$now)===null,'Unready not eligible');
auto_check(miner_auto_low_prl_metric([$samples[0],$samples[1],$row('2026-10-09 20:57:00',52.0)],$now)===null,'Stale or gapped must reset policy');
auto_check(miner_auto_low_prl_metric([$samples[0],$samples[1],$row('2026-10-09 21:04:00',52.0)],$now)===null,'Duplicate timestamps cannot fake persistence');
auto_check(miner_auto_low_prl_metric([$row('2026-10-09 20:59:00',50),$row('2026-10-09 20:54:00',50),$row('2026-10-09 20:49:00',50)],$now)===null,'Outdated samples cannot trigger');
auto_check(miner_auto_enabled()===false,'Actions must default to dry-run in tests');
auto_check(miner_monitor_profit_signal('quantus-trial','low',['hashrate_ths'=>169.05,'gpu_model'=>'RTX 4070 Ti SUPER'],true)['level']==='green','Explicit pearlhash proof enables green indicator even when name says Quantus');
auto_check(miner_monitor_profit_signal('quantus-trial','low',['hashrate_ths'=>52.06,'gpu_model'=>'RTX 4070 Ti SUPER'],true)['level']==='red','Explicit pearlhash proof enables red indicator even when name says Quantus');
auto_check(miner_monitor_profit_signal('quantus-trial','low',['hashrate_ths'=>52.06,'gpu_model'=>'RTX 4070 Ti SUPER'],false)['level']==='neutral','Unproven Quantus must never use PRL threshold');
auto_check(miner_monitor_profit_signal('quantus-trial','low',['hashrate_ths'=>52.06,'gpu_model'=>'RTX 5080'],true)['level']==='neutral','PRL threshold requires independently confirmed GPU class');
auto_check(miner_monitor_profit_signal('quantus-trial','medium',['hashrate_ths'=>52.06,'gpu_model'=>'RTX 4070 Ti SUPER'],true)['level']==='neutral','Low economics must not be applied to medium');
echo "PASS interactive-auto-regression\n";
