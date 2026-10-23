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
// Medium: two distinct consecutive observations strictly below 145 TH/s.
// All required observations must be from Medium, the same GPU and instance.
$mediumRow=static function(string $date,float $hash,string $priority='medium',string $gpu='RTX 4070 Ti SUPER') use ($row):array {
    return array_replace($row($date,$hash,$gpu),['priority_at_observation'=>$priority]);
};
$medium=[
 $mediumRow('2026-10-09 21:09:00',144.99),
 $mediumRow('2026-10-09 21:04:00',144.50),
 $mediumRow('2026-10-09 20:59:00',165.0)
];
$mediumGood=miner_auto_prl_metric($medium,$now,'medium');
auto_check($mediumGood!==null,'Two consecutive Medium readings below 145 TH/s must qualify');
auto_check($mediumGood['span_seconds']===300 && $mediumGood['average_ths']===144.75,
    'Medium must use exactly the two newest samples, not a third historical reading');
auto_check(miner_auto_prl_metric([$medium[0]],$now,'medium')===null,'One Medium reading must not trigger');
auto_check(miner_auto_prl_metric([$mediumRow('2026-10-09 21:09:00',145),$medium[1]],$now,'medium')===null,
    'Exactly 145 TH/s in latest sample must not qualify');
auto_check(miner_auto_prl_metric([$medium[0],$mediumRow('2026-10-09 21:04:00',145)],$now,'medium')===null,
    'Exactly 145 TH/s in earlier sample must not qualify');
auto_check(miner_auto_prl_metric([$mediumRow('2026-10-09 21:09:00',146),$medium[1]],$now,'medium')===null,
    'Recovered latest Medium GPU must not qualify');
auto_check(miner_auto_prl_metric([$medium[0],$mediumRow('2026-10-09 21:04:00',150)],$now,'medium')===null,
    'Recovered earlier Medium GPU must not qualify');
auto_check(miner_auto_prl_metric([$medium[0],$mediumRow('2026-10-09 21:04:00',120,'low')],$now,'medium')===null,
    'Low observations cannot trigger Medium policy');
auto_check(miner_auto_prl_metric([$medium[0],$mediumRow('2026-10-09 21:04:00',120,'medium','RTX 5080')],$now,'medium')===null,
    'Other GPU models must not trigger Medium');
auto_check(miner_auto_prl_metric([$medium[0],array_replace($medium[1],['ready'=>0])],$now,'medium')===null,
    'Medium requires the GPU to be ready for both readings');
auto_check(miner_auto_prl_metric([$medium[0],$mediumRow('2026-10-09 21:09:00',130)],$now,'medium')===null,
    'Repeated Medium timestamps must not count as two readings');
auto_check(miner_auto_prl_metric([$medium[0],$mediumRow('2026-10-09 21:07:00',130)],$now,'medium')===null,
    'Medium observations closer than 4 minutes must not qualify');
auto_check(miner_auto_prl_metric([$medium[0],$mediumRow('2026-10-09 21:01:00',130)],$now,'medium')===null,
    'Medium observations more than 7.5 minutes apart must not qualify');
auto_check(miner_auto_prl_metric([$mediumRow('2026-10-09 20:59:00',130),$mediumRow('2026-10-09 20:54:00',130)],$now,'medium')===null,
    'Stale Medium observations must not qualify');
// High: two distinct consecutive observations strictly below 155 TH/s,
// taken while this same identified GPU was actually running at High.
$high=[
 $mediumRow('2026-10-09 21:09:00',154.99,'high'),
 $mediumRow('2026-10-09 21:04:00',154.50,'high'),
 $mediumRow('2026-10-09 20:59:00',175.0,'high')
];
$highGood=miner_auto_prl_metric($high,$now,'high');
auto_check($highGood!==null,'Two consecutive High readings below 155 TH/s must qualify');
auto_check($highGood['span_seconds']===300 && $highGood['average_ths']===154.75,
    'High must use exactly the two latest readings');
auto_check(miner_auto_prl_metric([$high[0]],$now,'high')===null,'One High reading must not trigger');
auto_check(miner_auto_prl_metric([$mediumRow('2026-10-09 21:09:00',155,'high'),$high[1]],$now,'high')===null,
    'Exactly 155 TH/s in latest High reading must not qualify');
auto_check(miner_auto_prl_metric([$high[0],$mediumRow('2026-10-09 21:04:00',155,'high')],$now,'high')===null,
    'Exactly 155 TH/s in prior High reading must not qualify');
auto_check(miner_auto_prl_metric([$high[0],$mediumRow('2026-10-09 21:04:00',160,'high')],$now,'high')===null,
    'A recovered High reading must cancel relocation');
auto_check(miner_auto_prl_metric([$high[0],$mediumRow('2026-10-09 21:04:00',140,'medium')],$now,'high')===null,
    'Medium history must not trigger High relocation');
auto_check(miner_auto_prl_metric([$medium[0],$high[1]],$now,'high')===null,
    'A current Medium reading must not trigger High relocation');
auto_check(miner_auto_prl_metric([$high[0],$mediumRow('2026-10-09 21:04:00',120,'high','RTX 5080')],$now,'high')===null,
    'Other GPU classes must not trigger High relocation');
auto_check(miner_auto_prl_metric([$high[0],array_replace($high[1],['started'=>0])],$now,'high')===null,
    'Both High readings must have started');
auto_check(miner_auto_prl_metric([$high[0],$mediumRow('2026-10-09 21:09:00',120,'high')],$now,'high')===null,
    'Repeated timestamps cannot count twice for High');
auto_check(miner_auto_prl_metric([$high[0],$mediumRow('2026-10-09 21:07:00',120,'high')],$now,'high')===null,
    'High readings too close must not count twice');
auto_check(miner_auto_prl_metric([$high[0],$mediumRow('2026-10-09 21:01:00',120,'high')],$now,'high')===null,
    'Stale High reading gap must not qualify');
auto_check(miner_auto_prl_metric([$mediumRow('2026-10-09 20:59:00',130,'high'),
    $mediumRow('2026-10-09 20:54:00',130,'high')],$now,'high')===null,
    'Stale High readings must not trigger');
auto_check(miner_auto_prl_metric(array_map(static fn($v)=>array_replace($v,['priority_at_observation'=>null]),$high),$now,'high')===null,
    'High must fail closed without historical priority');
auto_check(miner_auto_prl_metric($high,$now,'medium')===null,
    'High readings must not trigger Medium relocation');
auto_check(miner_auto_prl_metric($medium,$now,'high')===null,'Medium readings must not trigger High');

// RTX 5090 desktop High: strictly below 400 TH/s in two valid ~5-minute
// observations of the same individual instance. Laptop is never eligible.
$high5090=[
 $mediumRow('2026-10-09 21:09:00',399.99,'high','NVIDIA GeForce RTX 5090'),
 $mediumRow('2026-10-09 21:04:00',399.00,'high','RTX 5090'),
 $mediumRow('2026-10-09 20:59:00',415.00,'high','RTX 5090')
];
$high5090Good=miner_auto_prl_metric($high5090,$now,'high');
auto_check($high5090Good!==null && $high5090Good['span_seconds']===300,
    'Desktop RTX 5090 High requires two consecutive sub-400 readings');
auto_check($high5090Good['average_ths']===399.5,'5090 policy must use only two newest readings');
auto_check(miner_auto_prl_metric([
    $mediumRow('2026-10-09 21:09:00',390.0,'high','RTX 5090'),
    $mediumRow('2026-10-09 21:04:00',395.0,'high','RTX 5090')],$now,'high')!==null,
    '5090 readings between old 375 and new 400 must trigger after two observations');
auto_check(miner_auto_prl_metric([$high5090[0]],$now,'high')===null,
    'A single 5090 High reading never relocates');
auto_check(miner_auto_prl_metric([$mediumRow('2026-10-09 21:09:00',400,'high','RTX 5090'),$high5090[1]],$now,'high')===null,
    'Exactly 400 TH/s in newest 5090 reading must reset');
auto_check(miner_auto_prl_metric([$high5090[0],$mediumRow('2026-10-09 21:04:00',400,'high','RTX 5090')],$now,'high')===null,
    'Exactly 400 TH/s in older 5090 reading must reset');
auto_check(miner_auto_prl_metric([$mediumRow('2026-10-09 21:09:00',415,'high','RTX 5090'),$high5090[1]],$now,'high')===null,
    'A recovered 5090 High reading must reset');
auto_check(miner_auto_prl_metric([$high5090[0],$mediumRow('2026-10-09 21:04:00',154.5,'high','RTX 4070 Ti SUPER')],$now,'high')===null,
    '5090 and 4070 Ti SUPER must not combine two different model thresholds');
auto_check(miner_auto_prl_metric([$mediumRow('2026-10-09 21:09:00',154.5,'high','RTX 4070 Ti SUPER'),$high5090[1]],$now,'high')===null,
    '4070 Ti SUPER and 5090 must not combine two different model thresholds');
auto_check(miner_auto_prl_metric([$high5090[0],$mediumRow('2026-10-09 21:04:00',370,'high','RTX 5090 Laptop GPU')],$now,'high')===null,
    '5090 Laptop never inherits desktop threshold');
auto_check(miner_auto_prl_metric([$mediumRow('2026-10-09 21:09:00',370,'high','RTX 5090 Laptop GPU'),$high5090[1]],$now,'high')===null,
    'Latest reading from Laptop cannot trigger desktop reallocation');
auto_check(miner_auto_prl_metric([$high5090[0],$mediumRow('2026-10-09 21:04:00',370,'high','RTX 5090 Ti')],$now,'high')===null,
    'RTX 5090 Ti must not inherit desktop 5090 threshold');
auto_check(miner_auto_prl_metric([$high5090[0],$mediumRow('2026-10-09 21:04:00',370,'high','RTX 5090 D')],$now,'high')===null,
    'RTX 5090 D must not inherit desktop 5090 threshold');
auto_check(miner_auto_prl_metric([$high5090[0],$mediumRow('2026-10-09 21:04:00',370,'medium','RTX 5090')],$now,'high')===null,
    '5090 High cannot use Medium samples');
auto_check(miner_auto_prl_metric([$high5090[0],array_replace($high5090[1],['ready'=>0])],$now,'high')===null,
    '5090 High requires ready samples');
auto_check(miner_auto_prl_metric([$high5090[0],array_replace($high5090[1],['hashrate_ths'=>null])],$now,'high')===null,
    'Missing 5090 hashrate must not trigger');
auto_check(miner_auto_prl_metric([$high5090[0],array_replace($high5090[1],['observed_at'=>'2026-10-09 21:09:00'])],$now,'high')===null,
    'Repeated 5090 timestamps must not trigger');
auto_check(miner_auto_prl_metric([$high5090[0],array_replace($high5090[1],['observed_at'=>'2026-10-09 21:07:00'])],$now,'high')===null,
    '5090 samples too close must not trigger');
auto_check(miner_auto_prl_metric([$high5090[0],array_replace($high5090[1],['observed_at'=>'2026-10-09 21:01:00'])],$now,'high')===null,
    'Gapped 5090 samples must not trigger');
auto_check(miner_auto_prl_metric([$mediumRow('2026-10-09 20:59:00',370,'high','RTX 5090'),
    $mediumRow('2026-10-09 20:54:00',370,'high','RTX 5090')],$now,'high')===null,
    'Stale 5090 samples must not trigger');
auto_check(miner_auto_prl_metric($high5090,$now,'low')===null,
    '5090 High measurements must not trigger Low');
auto_check(miner_auto_prl_metric($high5090,$now,'medium')===null,
    '5090 High measurements must not trigger Medium');
auto_check(miner_auto_prl_metric([$mediumRow('2026-10-09 21:09:00',370,'medium','RTX 5090'),
    $mediumRow('2026-10-09 21:04:00',370,'medium','RTX 5090')],$now,'medium')===null,
    'RTX 5090 Medium must not be automated');
auto_check(miner_auto_prl_metric([$mediumRow('2026-10-09 21:09:00',374,'high','RTX 5080'),
    $mediumRow('2026-10-09 21:04:00',370,'high','RTX 5080')],$now,'high')===null,
    'RTX 5080 must not inherit RTX 5090 High threshold');

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
    auto_check(miner_auto_policy_group($org,'high'),'High must apply across organizations: '.$org);
}
auto_check(!miner_auto_policy_group('','low'),'Missing organization is never a valid target');
auto_check(!miner_auto_policy_group('hache','lowest'),'No Lowest action');
auto_check(miner_auto_prl_metric(array_map(static fn($v)=>array_replace($v,['priority_at_observation'=>null]),$medium),$now,'medium')===null,
    'Missing migration 006 priority must never trigger Medium');
auto_check(str_contains($autoSource,"g.priority IN ('low','medium','high')"),'Low, Medium and High must be global');
auto_check(str_contains($autoSource,"'NULL AS priority_at_observation'"),'Missing optional migration must not break legacy Low query');
auto_check(!str_contains($autoSource,"\$liveTarget['organization']!=='interactive'"),'Live cross-organization Medium must not be rejected');

auto_check(str_contains($autoSource,"miner_salad_api_key_for_org((string)\$liveTarget['organization'])"),
    'Automatic live verification must honor each registered organization key');

echo "PASS interactive-auto-regression\n";
