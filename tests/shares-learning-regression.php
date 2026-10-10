<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/shares.php';
function shcheck(bool $ok,string $reason): void { if(!$ok)throw new RuntimeException($reason); }

$c=miner_share_counters('Shares accepted 16 rejected 1 HW errors 0');
shcheck($c!==null && $c['accepted']===16 && $c['rejected']===1 && $c['hardware_errors']===0,'Cumulative SRBMiner shares not parsed');
shcheck(miner_share_counters('Accepted shares: 33 rejected shares: 2')['accepted']===33,'Alternate shares summary not parsed');
shcheck(miner_share_counters('shares: A: 22 R: 0')['accepted']===22,'Labeled A/R summary not parsed');
shcheck(miner_share_counters('accepted share: 16')===null,'Single accepted event mistaken for cumulative count');
shcheck(miner_share_counters('Pool shares accepted 80000 rejected 20')===null,'Pool-wide count mistaken for GPU');
shcheck(miner_share_counters('GPU #0 RTX 4070 Laptop 80 TH/s')===null,'GPU speed mistaken for shares');
shcheck(miner_share_counters('shares accepted 999999999999999')===null,'Huge corrupt counter accepted');

$now=strtotime('2026-10-10T20:00:00Z');
$one=['instance_id'=>'node-a','machine_id'=>'machine-a','state'=>'running','ready'=>true,'started'=>true,'update_time'=>'2026-10-10T19:00:00Z'];
$two=['instance_id'=>'node-b','machine_id'=>'machine-b','state'=>'running','ready'=>true,'started'=>true,'update_time'=>'2026-10-10T19:00:00Z'];
$logs=[
 ['time'=>'2026-10-10T19:58:00Z','text_log'=>'Shares accepted 40 rejected 0 HW errors 0','resource'=>['labels'=>['instance_id'=>'node-a']]],
 ['time'=>'2026-10-10T19:57:00Z','text_log'=>'Shares accepted 100 rejected 0 HW errors 0'],
 ['time'=>'2026-10-10T19:56:00Z','text_log'=>'Shares accepted 10 rejected 0 HW errors 0','resource'=>['labels'=>['instance_id'=>'node-b']]],
 ['time'=>'2026-10-10T18:00:00Z','text_log'=>'Shares accepted 900 rejected 0 HW errors 0','resource'=>['labels'=>['instance_id'=>'node-a']]]
];
$mapped=miner_share_instance_counters($logs,[$one,$two],$now);
shcheck(count($mapped)===2 && $mapped['node-a']['accepted']===40 && $mapped['node-b']['accepted']===10,'Multi GPU shares mixed or stale counters reused');
$anonymous=miner_share_instance_counters([$logs[1]],[$one,$two],$now);
shcheck($anonymous===[],'Anonymous multireplica shares cannot be assigned');
$alone=miner_share_instance_counters([$logs[1]],[$one],$now);
shcheck(($alone['node-a']['accepted']??null)===100,'Single proven node should collect anonymous post-start log');
$replaced=$one;$replaced['update_time']='2026-10-10T19:59:00Z';
shcheck(miner_share_instance_counters([$logs[0]],[$replaced],$now)===[], 'Log before reallocation wrongly linked to next node');
shcheck(miner_share_gpu_family('NVIDIA GeForce RTX 4070 Laptop GPU')==='RTX 4070 LAPTOP','Laptop family');
shcheck(miner_share_gpu_family('NVIDIA RTX 4070 Ti SUPER')==='RTX 4070 TI SUPER','Super and Ti family');
shcheck(miner_share_gpu_family('NVIDIA RTX 5060 Ti')==='RTX 5060 TI','5060 Ti family');

shcheck(miner_share_delta(['ts'=>1000,'accepted'=>10],['ts'=>1300,'accepted'=>15])['accepted']===5,'Counter difference incorrect');
shcheck(miner_share_delta(['ts'=>1000,'accepted'=>10],['ts'=>1300,'accepted'=>10])['accepted']===0,'Repeated cumulative sample must add no shares');
shcheck(miner_share_delta(['ts'=>1000,'accepted'=>10],['ts'=>1300,'accepted'=>2])['reset']===true,'Counter reset misinterpreted');
shcheck(miner_share_delta(['ts'=>1000,'accepted'=>10],['ts'=>1800,'accepted'=>13])===null,'Discontinuous counter should not be integrated');

function trial_row(int $sec,int $count,string $id='a',string $model='RTX 4070 Laptop GPU',string $priority='lowest'):array {
 return ['group_id'=>4,'instance_id'=>$id,'group_name'=>'prl-laptop-trial','organization'=>'interactive','priority'=>$priority,
   'observed_at'=>gmdate('Y-m-d H:i:s',$sec),'accepted_shares'=>$count,'ready'=>1,'started'=>1,'state'=>'running','gpu_model'=>$model];
}
$t=$now-3000;
$rows=[trial_row($t,10),trial_row($t+300,12),trial_row($t+600,12),trial_row($t+900,16),
       trial_row($t+1200,2),trial_row($t+1500,7),trial_row($t+1800,8)];
$result=miner_share_analyze($rows,[],$now);
$d=$result['devices'][0]??[];
shcheck($d['accepted_delta']===12 && $d['resets']===1,'Repeated or reset counters inflated accepted shares');
shcheck($d['covered_seconds']===1500 && $d['intervals']===5,'Valid timed intervals wrong');
shcheck($d['accepted_per_hour']===28.8,'Shares per hour not normalized to valid sample time');
shcheck($result['groups'][0]['priority_current']==='lowest','Lowest group excluded from learning');
shcheck($result['alert_mode']==='informational_only'&&!$result['share_difficulty_verified'],'Never enable automatic share thresholds without difficulty');
$unsafe=trial_row($t+2100,1000,'other','');
shcheck(miner_share_analyze([$unsafe],[],$now)['devices']===[], 'Unknown GPU model must not be attributed');
$quantus=trial_row($t,10);$quantus['group_name']='quantus-trial';
shcheck(miner_share_analyze([$quantus],[],$now)['devices']===[], 'Other algorithms must not be treated as PRL baseline');

$source=file_get_contents(dirname(__DIR__).'/bin/poll.php');
shcheck(str_contains($source,'$shareTs>$prevTs'),'Repeated old share log cannot seed a new poll');
shcheck(str_contains($source,'accepted_shares,estimated_cost_usd') && str_contains($source,'miner_share_instance_counters($logs,$nodes)'),'Poller does not persist shares');
shcheck(!str_contains(file_get_contents(dirname(__DIR__).'/app/shares.php'),'reallocate_instance('),'Learning signals must not trigger reallocation');
$page=file_get_contents(dirname(__DIR__).'/public/index.php');
shcheck(str_contains($page,'Aprendizaje de shares por GPU') && str_contains($page,'shareLearning'), 'Admin view missing');
echo "SHARES_LEARNING_REGRESSION_OK\n";
