<?php
declare(strict_types=1);
require_once __DIR__.'/../app/history-summary.php';
function history_assert(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$names=['hache'."\0".'class-5060'=>'NVIDIA GeForce RTX 5060',
        'interactive'."\0".'class-5060'=>'NVIDIA GeForce RTX 5060',
        'hache'."\0".'class-5070l'=>'NVIDIA GeForce RTX 5070 Laptop GPU'];
history_assert(miner_history_model('', 'class-5060','hache',$names)==='RTX 5060',
    'Official Salad class must identify hardware even without hashrate');
history_assert(miner_history_priority('batch')==='lowest','Batch must be Lowest');
history_assert(miner_history_priority(null)==='sin_registro','Missing priority cannot inherit current group settings');
$models=[];$previous=null;
$make=static fn(int $group,string $org,string $id,string $at,string $class,string $priority,
   ?string $gpu,?string $price,?string $cost,?int $shares):array=>[
    'group_id'=>$group,'organization'=>$org,'instance_id'=>$id,'observed_at'=>$at,
    'state'=>'running','ready'=>1,'started'=>1,'gpu_model'=>$gpu,
    'gpu_class_at_observation'=>$class,'priority_at_observation'=>$priority,
    'official_rate_usd_per_hour'=>$price,'estimated_cost_usd'=>$cost,
    'accepted_shares'=>$shares,'hashrate_ths'=>100
];
$rows=[
$make(1,'hache','node-a','2026-10-11 10:00:00','class-5060','lowest',null,'0.060000',null,5),
$make(1,'hache','node-a','2026-10-11 10:05:00','class-5060','lowest',null,'0.060000','0.00500000',8),
$make(1,'hache','node-a','2026-10-11 10:10:00','class-5060','lowest',null,'0.060000','0.00500000',9),
$make(1,'hache','node-a','2026-10-11 10:15:00','class-5060','low',null,'0.080000',null,10),
$make(2,'interactive','node-b','2026-10-11 10:00:00','class-5060','batch',null,'0.072000',null,2),
$make(2,'interactive','node-b','2026-10-11 10:05:00','class-5060','batch',null,'0.072000','0.00600000',3),
$make(3,'hache','node-c','2026-10-11 10:00:00','class-5070l','lowest','RTX 5070 Laptop GPU','0.100000',null,null),
$make(3,'hache','node-c','2026-10-11 10:05:00','class-5070l','lowest',null,'0.100000','0.00833333',null),
$make(4,'hache','node-old','2026-10-11 10:00:00','','','RTX 5070 Ti Laptop GPU',null,null,null),
$make(4,'hache','node-old','2026-10-11 10:05:00','','','RTX 5070 Ti Laptop GPU',null,'0.04000000',null)
];
foreach($rows as $r)miner_history_reduce($models,$previous,$r,$names);
$final=miner_history_finish($models);$by=[];
foreach($final as $item)$by[$item['gpu']]=$item;
history_assert(count($final)===3,'RTX 5060 across organizations should be a single row');
$gpu=$by['RTX 5060'];
history_assert($gpu['sessions']===3,'Sessions require separate model/priority/instance continuous runs');
history_assert($gpu['observed_seconds']===1200,'All observed running time counts, including startup and non-share periods');
history_assert($gpu['priced_seconds']===900 && $gpu['unpriced_seconds']===300,
    'Priority transitions cannot apply new rates to old minutes');
history_assert(abs($gpu['cost_usd']-0.016)<0.0000001,'Only historically verified USD rates may be accumulated');
history_assert($gpu['accepted']===5 && $gpu['accepted_intervals']===3,
    'Accepted shares are cumulative-counter DELTAS, never summed snapshots');
history_assert(count($gpu['priorities'])===3,'Lowest, Low and unknown transition duration must be distinguishable');
history_assert($by['RTX 5070 LAPTOP']['cost_intervals']===1,
    'GPU can be identified by official Salad class when hashrate missing');
history_assert($by['RTX 5070 TI LAPTOP']['cost_intervals']===0
    && $by['RTX 5070 TI LAPTOP']['unpriced_seconds']===300,
    'Legacy manual estimated costs must NEVER be treated as official');
$prev=null;$m=[];
miner_history_reduce($m,$prev,$make(12,'hache','A','2026-10-11 11:00:00','class-5060','low','RTX 5060',null,null,null),$names);
miner_history_reduce($m,$prev,$make(12,'hache','A','2026-10-11 11:20:00','class-5060','low','RTX 5060',null,null,null),$names);
history_assert($m['RTX 5060']['observed_seconds']===0 && $m['RTX 5060']['sessions']===2,
    'Gaps >7 minutes must not become billed time or a single session');
$page=file_get_contents(__DIR__.'/../public/index.php');
history_assert(str_contains($page,'Resumen acumulado por modelo de GPU')
    && str_contains($page,'miner_history_summary()')
    && str_contains($page,'Desglose por prioridad histórica verificada'),
    'New main history section or priority details missing');
history_assert(str_contains($page,'Sin tarifa histórica') &&
    str_contains($page,'Vigilancia reciente'), 'Missing evidence cannot masquerade as zero');
$source=file_get_contents(__DIR__.'/../app/history-summary.php');
history_assert(!str_contains($source,'UPDATE ') && !str_contains($source,'INSERT INTO'),
    'History must remain read-only');
echo "HISTORY_SUMMARY_REGRESSION_OK\n";
