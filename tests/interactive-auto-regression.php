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
// Medium is a separate strict threshold: 129.99 qualifies, exactly 130 does not.
// The last three readings must all belong to Medium, not a previous Low run.
$mediumRow=static function(string $date,float $hash,string $priority='medium',string $gpu='RTX 4070 Ti SUPER') use ($row):array {
    return array_replace($row($date,$hash,$gpu),['priority_at_observation'=>$priority]);
};
$medium=[
 $mediumRow('2026-10-09 21:09:00',129.99),
 $mediumRow('2026-10-09 21:04:00',129.5),
 $mediumRow('2026-10-09 20:59:00',129.8)
];
auto_check(miner_auto_prl_metric($medium,$now,'medium')!==null,'Sustained Medium below 130 TH/s must qualify');
auto_check(miner_auto_prl_metric([$mediumRow('2026-10-09 21:09:00',130.0),$medium[1],$medium[2]],$now,'medium')===null,'Exactly 130 TH/s must NOT reallocate');
auto_check(miner_auto_prl_metric([$medium[0],$mediumRow('2026-10-09 21:04:00',131),$medium[2]],$now,'medium')===null,'Recovered Medium GPU must not reallocate');
auto_check(miner_auto_prl_metric([$medium[0],$mediumRow('2026-10-09 21:04:00',120,'low'),$medium[2]],$now,'medium')===null,'Low observations cannot trigger Medium policy');
auto_check(miner_auto_prl_metric([$medium[0],$medium[1],$mediumRow('2026-10-09 20:59:00',120,'medium','RTX 5080')],$now,'medium')===null,'Different GPU in Medium must be excluded');
auto_check(miner_auto_prl_metric($medium,$now,'high')===null,'High priority must not be automated');
auto_check(miner_auto_prl_metric($medium,$now,'lowest')===null,'Lowest priority must not be automated');
auto_check(miner_auto_prl_metric($medium,$now,'low')===null,'Existing Low rule must remain at 125 TH/s');
$lowTagged=[
 $mediumRow('2026-10-09 21:09:00',125.0,'low'),
 $mediumRow('2026-10-09 21:04:00',124.7,'low'),
 $mediumRow('2026-10-09 20:59:00',124.3,'low')
];
auto_check(miner_auto_prl_metric($lowTagged,$now,'low')!==null,'Global Low 125 TH/s must qualify after consecutive samples');
auto_check(miner_auto_prl_metric([$lowTagged[0],$medium[1],$lowTagged[2]],$now,'low')===null,
    'Low must ignore historical samples recorded under Medium');
auto_check(miner_auto_prl_metric([$mediumRow('2026-10-09 21:09:00',125.01,'low'),$lowTagged[1],$lowTagged[2]],$now,'low')===null,
    'Low cutoff must remain inclusive at 125');
auto_check(miner_auto_low_prl_metric([$samples[0],$row('2026-10-09 21:04:00',156),$samples[2]],$now)===null,'Recovered GPU must not trigger');
auto_check(miner_auto_low_prl_metric([$samples[0],$samples[1],$row('2026-10-09 20:59:00',52.0,'RTX 5080')],$now)===null,'Different GPU model must be excluded');
auto_check(miner_auto_low_prl_metric([$samples[0],$samples[1],$row('2026-10-09 20:59:00',52.0,'RTX 4070 Ti SUPER','allocating')],$now)===null,'Allocating not eligible');
auto_check(miner_auto_low_prl_metric([$samples[0],$samples[1],array_replace($row('2026-10-09 20:59:00',52.0),['ready'=>0])],$now)===null,'Unready not eligible');
auto_check(miner_auto_low_prl_metric([$samples[0],$samples[1],$row('2026-10-09 20:56:00',52.0)],$now)===null,'Stale or gapped must reset policy');
auto_check(miner_auto_low_prl_metric([$samples[0],$samples[1],$row('2026-10-09 21:04:00',52.0)],$now)===null,'Duplicate timestamps cannot fake persistence');
auto_check(miner_auto_low_prl_metric([$row('2026-10-09 20:59:00',50),$row('2026-10-09 20:54:00',50),$row('2026-10-09 20:49:00',50)],$now)===null,'Outdated samples cannot trigger');
auto_check(miner_auto_enabled()===false,'Actions must default to dry-run in tests');
auto_check(miner_monitor_profit_signal('quantus-trial','low',['hashrate_ths'=>169.05,'gpu_model'=>'RTX 4070 Ti SUPER'],true)['level']==='green','Explicit pearlhash proof enables green indicator even when name says Quantus');
auto_check(miner_monitor_profit_signal('quantus-trial','low',['hashrate_ths'=>52.06,'gpu_model'=>'RTX 4070 Ti SUPER'],true)['level']==='red','Explicit pearlhash proof enables red indicator even when name says Quantus');
auto_check(miner_monitor_profit_signal('quantus-trial','low',['hashrate_ths'=>52.06,'gpu_model'=>'RTX 4070 Ti SUPER'],false)['level']==='neutral','Unproven Quantus must never use PRL threshold');
auto_check(miner_monitor_profit_signal('quantus-trial','low',['hashrate_ths'=>52.06,'gpu_model'=>'RTX 5080'],true)['level']==='neutral','PRL threshold requires independently confirmed GPU class');
auto_check(miner_monitor_profit_signal('quantus-trial','medium',['hashrate_ths'=>52.06,'gpu_model'=>'RTX 4070 Ti SUPER'],true)['level']==='neutral','Low economics must not be applied to medium');
$autoSource=file_get_contents(dirname(__DIR__).'/app/interactive-auto.php');
auto_check(miner_auto_policy_group('interactive','low'),'Existing INTERACTIVE Low remains enabled');
foreach(['interactive','hache','guanabacoa','other-registered-organization'] as $org) {
    auto_check(miner_auto_policy_group($org,'low'),'Low must apply across organizations: '.$org);
    auto_check(miner_auto_policy_group($org,'medium'),'Medium must apply across organizations: '.$org);
}
auto_check(!miner_auto_policy_group('','low'),'Missing organization is never a valid target');
auto_check(!miner_auto_policy_group('hache','lowest') && !miner_auto_policy_group('hache','high'),'No Lowest or High action');
auto_check(miner_auto_prl_metric(array_map(static fn($v)=>array_replace($v,['priority_at_observation'=>null]),$medium),$now,'medium')===null,
    'Missing migration 006 priority must never trigger Medium');
auto_check(str_contains($autoSource,"g.priority IN ('low','medium')"),'Both Low and Medium must be global');
auto_check(str_contains($autoSource,"'NULL AS priority_at_observation'"),'Missing optional migration must not break legacy Low query');
auto_check(!str_contains($autoSource,"\$liveTarget['organization']!=='interactive'"),'Live cross-organization Medium must not be rejected');

auto_check(str_contains($autoSource,"miner_salad_api_key_for_org((string)\$liveTarget['organization'])"),
    'Automatic live verification must honor each registered organization key');

echo "PASS interactive-auto-regression\n";
