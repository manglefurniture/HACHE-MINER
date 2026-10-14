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
// The page_min_time boundary is inclusive on both pages; it must be counted once.
$events[]=['time'=>gmdate('Y-m-d\\TH:i:s\\Z',$start+210),
    'text_log'=>'#0 RTX 4070 Ti SUPER 167.50 TH/s 235W 0.68 50% 63C',
    'resource'=>['labels'=>['instance_id'=>'gpu-a']]];
$events[]=['time'=>gmdate('Y-m-d\\TH:i:s\\Z',$start+210),
    'text_log'=>'#0 RTX 4070 Ti SUPER 159.50 TH/s 235W 0.68 50% 63C',
    'resource'=>['labels'=>['instance_id'=>'gpu-b']]];
$fetch=function(int $from,string $to,float $remainingSeconds) use($events):array {
    coverage_assert($remainingSeconds>0 && $remainingSeconds<=14.0,
        'Every request must receive remaining wall-clock budget');
    $end=miner_log_cursor_position($to);
    $subset=array_values(array_filter($events,static function(array $item) use($from,$end) {
        $t=miner_log_cursor_position($item['time']);
        return $t!==null && $t[0]>=$from
            && ($t[0]<$end[0] || ($t[0]===$end[0] && $t[1]<=$end[1]));
    }));
    usort($subset,static fn($a,$b)=>strcmp($b['time'],$a['time']));
    $page=array_slice($subset,0,100);
    return ['items'=>$page,'page_min_time'=>$page===[]?null:$page[count($page)-1]['time']];
};
$coverage=miner_log_collect_windows($fetch,$start,$start+420,12);
coverage_assert(!$coverage['partial'],'Seven-minute collection should be complete with enough bounded requests');
coverage_assert($coverage['unique']===262,'Overlapping windows must preserve unique per-instance events');
coverage_assert($coverage['requests']>1 && $coverage['requests']<=12,
    'Saturated 100-item Salad pages must advance using a timestamp cursor');
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
    static fn(int $from,string $to,float $remaining)=>['items'=>$twins],
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
    static function(int $from,string $to,float $remaining) use($dense):array {
        $page=array_slice(array_values(array_filter($dense,
            static fn($r)=>strtotime($r['time'])>=$from && strtotime($r['time'])<=strtotime($to))),0,100);
        return ['items'=>$page,'page_min_time'=>$page[0]['time']??null];
    },$start,$start+420,6);
coverage_assert($limited['partial'] && $limited['requests']<=6,
    'Dense unpageable timestamps must be reported as partial within strict budget');

// A later failing window cannot erase valid, already received attribution.
$fails=0;
$degraded=miner_log_collect_windows(function(int $from,string $to,float $remaining)use(&$fails,$events):array{
    $fails++;
    if($fails>1)throw new RuntimeException('Salad rate limited');
    $first=array_reverse(array_slice($events,160,100));
    return ['items'=>$first,'page_min_time'=>$first[count($first)-1]['time']];
},$start,$start+420,4);
coverage_assert($degraded['partial'] && $degraded['unique']===100,
    'Retain received evidence while marking partial collection after later API error');

// Four hundred evenly spaced events must fit inside the 8-request budget
// using true cursor pagination. Midpoint splitting used to leave older GPUs out.
$fourHundred=[];
for($i=0;$i<400;$i++){
    $fourHundred[]=['time'=>gmdate('Y-m-d\\TH:i:s\\Z',$start+$i),
        'text_log'=>'gpu event '.$i,
        'resource'=>['labels'=>['instance_id'=>($i%2===0?'gpu-a':'gpu-b')]]];
}
$cursorFetch=static function(int $from,string $to,float $remaining)use($fourHundred):array{
    $end=miner_log_cursor_position($to);
    $rows=array_values(array_filter($fourHundred,static function(array $r)use($from,$end) {
        $p=miner_log_cursor_position($r['time']);
        return $p[0]>=$from && ($p[0]<$end[0] || ($p[0]===$end[0] && $p[1]<=$end[1]));
    }));
    usort($rows,static fn($a,$b)=>strcmp($b['time'],$a['time']));
    $page=array_slice($rows,0,100);
    return ['items'=>$page,'page_min_time'=>$page===[]?null:$page[count($page)-1]['time']];
};
$fourResult=miner_log_collect_windows($cursorFetch,$start,$start+420,8);
coverage_assert($fourResult['unique']===400 && !$fourResult['partial']
    && $fourResult['requests']<=6,
    '400 rows must be paged without losing older GPU logs');
coverage_assert(isset($fourResult['elapsed_ms']) && $fourResult['elapsed_ms']>=0,
    'Coverage diagnostics must expose bounded elapsed milliseconds');

coverage_assert(miner_log_cursor_position('2026-10-13T15:01:01.123456789Z')[1]===123456789,
    'Full nanosecond timestamp precision must be preserved');
// Real Salad RFC3339 date-times can include +00:00 or numeric zone offsets.
// The old strict Z-only parser discarded every such log, hiding all GPU TH/s.
$utcZ=miner_log_cursor_position('2026-10-13T15:01:01.123456789Z');
$utcOffset=miner_log_cursor_position('2026-10-13T15:01:01.123456789+00:00');
$localOffset=miner_log_cursor_position('2026-10-13T10:01:01.123456789-05:00');
coverage_assert($utcZ!==null && $utcZ===$utcOffset && $utcZ===$localOffset,
    'Equivalent RFC3339 offsets must preserve UTC cursor and full nanoseconds');
coverage_assert(miner_log_cursor_position('not-a-timestamp')===null,
    'Malformed log timestamps are not valid cursors');
$offsetLogs=[[
    'time'=>'2026-10-13T15:01:01.123456789+00:00',
    'text_log'=>'#0 RTX 4070 Ti SUPER 160.00 TH/s 235W 0.68 50% 63C',
    'resource'=>['labels'=>['instance_id'=>'gpu-a']]
]];
$offsetWindow=miner_log_collect_windows(
    static fn(int $from,string $to,float $remaining)=>['items'=>$offsetLogs],
    $start,$start+420,2);
coverage_assert($offsetWindow['unique']===1 && !$offsetWindow['partial'],
    'Offset timestamps must not discard otherwise attributable miner logs');

coverage_assert(miner_log_cursor_position('2026-10-13T15:01:01.123456788Z')[1]===123456788,
    'Adjacent nanosecond cursors must remain distinct');
$longA='salad-logs:'.str_repeat('a',120).'group-A';
$longB='salad-logs:'.str_repeat('a',120).'group-B';
coverage_assert(strlen(miner_log_sync_source_key($longA))<=120
    && miner_log_sync_source_key($longA)!==miner_log_sync_source_key($longB),
    'Long sync source names must fit the schema without collision');
coverage_assert(miner_log_sync_source_key('salad:hache/prl-tests')==='salad:hache/prl-tests',
    'Normal diagnostic source names must remain unchanged');

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

// UTC offsets must be ordered chronologically, never lexicographically.
// An older Z timestamp sorts above the newer -05:00 spelling as text.
$oldMetric=[
    'time'=>'2026-10-13T15:30:00Z',
    'text_log'=>'#0 RTX 4070 Ti SUPER 151.00 TH/s 235W 0.68 50% 63C 2535 11043 3 0 0',
    'resource'=>['labels'=>['instance_id'=>'gpu-a']]
];
$newMetric=[
    'time'=>'2026-10-13T10:31:00.123456789-05:00',
    'text_log'=>'#0 RTX 4070 Ti SUPER 172.00 TH/s 235W 0.68 50% 63C 2535 11043 9 0 0',
    'resource'=>['labels'=>['instance_id'=>'gpu-a']]
];
$mixedMetrics=[$oldMetric,$newMetric];
usort($mixedMetrics,'miner_log_compare_newest');
coverage_assert($mixedMetrics[0]['time']===$newMetric['time'],
    'Newest timestamp comparator must normalize UTC before sorting');
$actualNow=strtotime('2026-10-13T15:32:00Z');
$latestGPU=miner_monitor_instance_log_metrics([$oldMetric,$newMetric],$nodes,$actualNow);
coverage_assert(isset($latestGPU['gpu-a'])
    && $latestGPU['gpu-a']['hashrate_ths']===172.0,
    'GPU metric reader must choose newest UTC log when offsets differ');
coverage_assert($latestGPU['gpu-a']['log_at']===$newMetric['time'],
    'Individual metric must retain the source log timestamp and its nanoseconds');
$latestShares=miner_share_instance_counters([$oldMetric,$newMetric],$nodes,$actualNow);
coverage_assert(isset($latestShares['gpu-a'])
    && $latestShares['gpu-a']['accepted']===9,
    'Share reader must choose newest UTC log when offsets differ');

echo "SALAD_LOG_COVERAGE_REGRESSION_OK\n";
