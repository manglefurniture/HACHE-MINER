<?php
declare(strict_types=1);
require_once __DIR__.'/../app/trial-history.php';
function trial_assert(bool $v,string $m):void {if(!$v)throw new RuntimeException($m);}
trial_assert(miner_trial_day_cancun('2026-10-10 04:59:59')==='2026-10-09','UTC offset in Cancun wrong');
trial_assert(miner_trial_day_cancun('2026-10-10 05:00:00')==='2026-10-10','Cancun local midnight wrong');
$small=miner_trial_history_normalize([
 'day_cancun'=>'2026-10-10',
 'organization'=>'interactive','project_name'=>'default',
 'group_name'=>'prl-lowest-small','model_key'=>'RTX 4070 Laptop GPU',
 'priority'=>'lowest','samples'=>22,'observed'=>18,'instances'=>2,
 'avg_ths'=>'79.5454','last_utc'=>'2026-10-10 22:00:00'
]);
trial_assert($small['priority_current']==='lowest' && $small['organization']==='interactive','Lowest/Interactive discarded');
trial_assert($small['gpu']==='RTX 4070 Laptop GPU' && $small['project']==='default','Project or model attribution missing');
trial_assert($small['instances']===2 && $small['observed']===18,'Reallocated node or telemetry lost');
trial_assert($small['avg_ths']===79.55,'Average rate incorrectly rounded');
$unknown=miner_trial_history_normalize([
 'priority'=>'low','samples'=>5,'observed'=>0,'instances'=>2,
 'avg_ths'=>null
]);
trial_assert($unknown['avg_ths']===null,'Missing hash was misrepresented as zero');
trial_assert($unknown['instances']===2,'Multiple replicas must not disappear');
$invalid=miner_trial_history_normalize(['samples'=>7,'observed'=>3,'avg_ths'=>'INF']);
trial_assert($invalid['avg_ths']===null,'Non-finite values accepted');
$page=file_get_contents(__DIR__.'/../public/index.php');
trial_assert(str_contains($page,'miner_trial_history_compare()'),'UI not loading comparative history');
trial_assert(str_contains($page,'Prioridad mostrada: actual del grupo.'),'Historical priority caveat omitted');
$source=file_get_contents(__DIR__.'/../app/trial-history.php');
trial_assert(str_contains($source,'miner_observations')&&str_contains($source,'group_state'),'Read-only historical sources changed');
trial_assert(str_contains($source,"COUNT(DISTINCT NULLIF(gpu_model,''))=1"),'Metric-less GPU attribution must require unique identified model');
trial_assert(str_contains($page,"miner_h($entry['project'])"),'Project hidden from history comparisons');
trial_assert(!str_contains($source,'INSERT INTO')&&!str_contains($source,'UPDATE group_state'),'Comparison must be read only');
echo "TRIAL_HISTORY_REGRESSION_OK\n";
