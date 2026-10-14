<?php
declare(strict_types=1);
require_once __DIR__.'/monitor.php';

/**
 * Temporary, bounded, read-only telemetry: compare live Salad instance IDs
 * with individual GPU lines in the already-fetched Salad log response.
 * No external requests, persisted identifiers, addresses or raw log text.
 * Does NOT influence attribution, costs, shares, or auto-reallocation.
 */
function miner_idmap_diagnostic(array $nodes,array $logs,?array $coverage=null,?int $now=null):array {
    $now??=time();
    $ready=[];
    $total=0;
    foreach($nodes as $node) {
        if(!is_array($node))continue;
        $total++;
        $id=(string)($node['instance_id']??$node['id']??'');
        if($id!=='' && miner_instance_ready($node))$ready[$id]=true;
    }
    $gpu=0;$matchedRows=0;$unknown=0;$anonymous=0;$old=0;
    $matchedIds=[];
    foreach(array_slice($logs,0,1000) as $item) {
        if(!is_array($item))continue;
        $metric=miner_monitor_log_metric((string)($item['text_log']??$item['message']??''));
        if($metric===null)continue;
        $gpu++;
        $at=strtotime((string)($item['time']??$item['timestamp']??''));
        if($at===false || $at>$now+120 || $now-$at>540) {$old++;continue;}
        // An unlabeled log from a singleton group may be attributed in
        // production, but it is NOT proof that Salad supplied its exact ID.
        if(!miner_monitor_log_has_identity_hint($item)) {
            $anonymous++;
            continue;
        }
        $id=miner_monitor_log_instance($item,$nodes);
        if($id!==null) {
            $matchedRows++;
            $matchedIds[$id]=true;
        } else {
            $unknown++;
        }
    }
    // Use the EXACT production per-instance metric reader for the coverage
    // figure. A log belonging to a non-ready node is not a measured GPU.
    $actual=miner_monitor_instance_log_metrics($logs,$nodes,$now);
    $readyMeasured=count(array_intersect_key($ready,$actual));
    $missing=count($ready)-$readyMeasured;
    $apiError=$coverage===null || !empty($coverage['metric_error']);
    $partial=$apiError || !empty($coverage['metric_partial']??$coverage['partial']??false);
    $counts=[
        'nodes'=>$total,'ready'=>count($ready),'gpu_rows'=>$gpu,
        'matched_rows'=>$matchedRows,'unknown_rows'=>$unknown,
        'anonymous_rows'=>$anonymous,'old_rows'=>$old,
        'matched_ids'=>count($matchedIds),
        'measured'=>$readyMeasured,'missing'=>$missing,
        'requests'=>(int)($coverage['metric_requests']??$coverage['requests']??0),
        'event_partial'=>!empty($coverage['event_partial'])?1:0,
        'partial'=>$partial?1:0,'api_error'=>$apiError?1:0
    ];
    // Intentionally only small numeric aggregates in VARCHAR(180).
    $detail=sprintf('n=%d ready=%d gpu=%d mapped=%d unknown=%d noid=%d old=%d ids=%d ths=%d missing=%d pages=%d partial=%d evpartial=%d apierr=%d',
        $counts['nodes'],$counts['ready'],$counts['gpu_rows'],
        $counts['matched_rows'],$counts['unknown_rows'],$counts['anonymous_rows'],
        $counts['old_rows'],$counts['matched_ids'],$counts['measured'],
        $counts['missing'],$counts['requests'],$counts['partial'],$counts['event_partial'],$counts['api_error']);
    return ['status'=>($partial || $missing>0 || $unknown>0 || $anonymous>0)?'partial':'ok',
        'detail'=>$detail,'counts'=>$counts];
}
