<?php
declare(strict_types=1);
require_once __DIR__.'/../app/salad-log-coverage.php';
require_once __DIR__.'/../app/monitor.php';

function coverage_assert(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException($message);
}
$start=strtotime('2026-10-13T15:00:00Z');
$nodes=[
    ['instance_id'=>'gpu-a','machine_id'=>'machine-a','state'=>'running','ready'=>true,'started'=>true,
     'update_time'=>'2026-10-13T14:59:00Z'],
    ['instance_id'=>'gpu-b','machine_id'=>'machine-b','state'=>'running','ready'=>true,'started'=>true,
     'update_time'=>'2026-10-13T14:59:00Z']
];
$events=[];
for($i=0;$i<260;$i++){
    $owner=$i%2===0?'gpu-a':'gpu-b';
    $events[]=[
        'time'=>gmdate('Y-m-d\\TH:i:s\\Z',$start+$i),
        'text_log'=>'#0 RTX 4070 Ti SUPER '.($owner==='gpu-a'?'161.25':'158.75').' TH/s 235W 0.68 50% 63C line-'.$i,
        'resource'=>['labels'=>['instance_id'=>$owner]],
        'receive_time'=>gmdate('Y-m-d\\TH:i:s\\Z',$start+$i)
    ];
}
// The inclusive midpoint appears in both subwindows; it must be counted once.
$events[]=['time'=>gmdate('Y-m-d\\TH:i:s\\Z',$start+210),
    'text_log'=>'#0 RTX 4070 Ti SUPER 167.50 TH/s 235W 0.68 50% 63C',
    'resource'=>['labels'=>['instance_id'=>'gpu-a']]];
$events[]=['time'=>gmdate('Y-m-d\\TH:i:s\\Z',$start+210),
    'text_log'=>'#0 RTX 4070 Ti SUPER 159.50 TH/s 235W 0.68 50% 63C',
    'resource'=>['labels'=>['instance_id'=>'gpu-b']]];
$fetch=function(int $from,int $to) use($events):array {
    $subset=array_values(array_filter($events,static function(array $item) use($from,$to) {
        $t=strtotime($item['time']);
        return $t>=$from && $t<=$to;
    }));
    usort($subset,static fn($a,$b)=>strcmp($b['time'],$a['time']));
    return ['items'=>array_slice($subset,0,100)];
};
$coverage=miner_log_collect_windows($fetch,$start,$start+420,12);
coverage_assert(!$coverage['partial'],'Seven-minute collection should be complete with enough bounded requests');
coverage_assert($coverage['unique']===262,'Overlapping windows must preserve unique per-instance events');
coverage_assert($coverage['requests']>1 && $coverage['requests']<=12,
    'Saturated 100-item Salad pages must be divided');
$metrics=miner_monitor_instance_log_metrics($coverage['items'],$nodes,$start+420);
coverage_assert(count($metrics)===2,'Per-instance attributed TH/s must survive bounded coverage');
coverage_assert($metrics['gpu-a']['hashrate_ths']===161.25 || $metrics['gpu-a']['hashrate_ths']===167.5,
    'First GPU speed must stay independent');
coverage_assert($metrics['gpu-b']['hashrate_ths']===158.75 || $metrics['gpu-b']['hashrate_ths']===159.5,
    'Second GPU speed must stay independent');

// Exactly identical messages/time are allowed from separate Salad instances;
// labels prove they represent two distinct physical workers.
$sameTime=$start+200;
$twins=[
    ['time'=>gmdate('Y-m-d\\TH:i:s\\Z',$sameTime),'text_log'=>'#0 RTX 4070 Ti SUPER 160 TH/s',
     'resource'=>['labels'=>['instance_id'=>'gpu-a']]],
    ['time'=>gmdate('Y-m-d\\TH:i:s\\Z',$sameTime),'text_log'=>'#0 RTX 4070 Ti SUPER 160 TH/s',
     'resource'=>['labels'=>['instance_id'=>'gpu-b']]]
];
$twinsCoverage=miner_log_collect_windows(
    static fn(int $from,int $to)=>['items'=>$twins],
    $start,$start+420,2);
coverage_assert($twinsCoverage['unique']===2,
    'Identical GPU lines with distinct instance labels must not be deduplicated together');

// Dense logs at a single timestamp cannot be exhausted by time splitting.
// Bounded response must declare partial, never claim full coverage.
$dense=[];
for($i=0;$i<175;$i++)$dense[]=[
    'time'=>gmdate('Y-m-d\\TH:i:s\\Z',$start+210),
    'text_log'=>'worker log '.$i
];
$limited=miner_log_collect_windows(
    static function(int $from,int $to) use($dense):array {
        return ['items'=>array_slice(array_values(array_filter($dense,
            static fn($r)=>strtotime($r['time'])>=$from && strtotime($r['time'])<=$to)),0,100)];
    },$start,$start+420,6);
coverage_assert($limited['partial'] && $limited['requests']<=6,
    'Dense unpageable timestamps must be reported as partial within strict budget');

// A later failing window cannot erase valid, already received attribution.
$fails=0;
$degraded=miner_log_collect_windows(function(int $from,int $to)use(&$fails,$events):array{
    $fails++;
    if($fails>1)throw new RuntimeException('Salad rate limited');
    return ['items'=>array_slice($events,0,100)];
},$start,$start+420,4);
coverage_assert($degraded['partial'] && $degraded['unique']===100,
    'Retain received evidence while marking partial collection after later API error');

// More than the previous 300-row parser cutoff: retain an older labelled
// instance's only metric, despite 350 newer rows from another worker.
$deep=[];
for($i=0;$i<350;$i++)$deep[]=[
    'time'=>gmdate('Y-m-d\\TH:i:s\\Z',$start+420-$i),
    'text_log'=>'#0 RTX 4070 Ti SUPER 161.00 TH/s 235W 0.68 50% 63C',
    'resource'=>['labels'=>['instance_id'=>'gpu-a']]
];
$deep[]=['time'=>gmdate('Y-m-d\\TH:i:s\\Z',$start+70),
    'text_log'=>'#0 RTX 4070 Ti SUPER 153.00 TH/s 215W 0.72 50% 60C',
    'resource'=>['labels'=>['instance_id'=>'gpu-b']]];
$deepMetrics=miner_monitor_instance_log_metrics($deep,$nodes,$start+420);
coverage_assert(isset($deepMetrics['gpu-b']) && $deepMetrics['gpu-b']['hashrate_ths']===153.0,
    'Bounded log analyzer must not ignore other instances after 300 lines');
// Deep-page share counters must also be attributed after 300 rows; do not
// treat absent per-instance counters as accepted 0 or another GPU's shares.
require_once __DIR__.'/../app/shares.php';
$sharesRow=[
    'time'=>gmdate('Y-m-d\\TH:i:s\\Z',$start+70),
    'text_log'=>'#0 RTX 4070 Ti SUPER 153.00 TH/s 215W 0.72 50% 60C 2610 10251 7 0 0',
    'resource'=>['labels'=>['instance_id'=>'gpu-b']]
];
$deepShares=miner_share_instance_counters([...$deep,$sharesRow],$nodes,$start+420);
coverage_assert(isset($deepShares['gpu-b']) && $deepShares['gpu-b']['accepted']===7
    && !isset($deepShares['gpu-a']),
    'Deep-page cumulative shares must not be lost or attributed to other replicas');

echo "SALAD_LOG_COVERAGE_REGRESSION_OK\n";
