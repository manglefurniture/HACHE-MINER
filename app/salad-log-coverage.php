<?php
declare(strict_types=1);

/** Stable, collision-resistant source key within sync_runs.source_name VARCHAR(120). */
function miner_log_sync_source_key(string $source):string {
    return strlen($source)<=120
        ?$source
        :substr($source,0,85).'#'.substr(hash('sha256',$source),0,32);
}

/** Compare Salad RFC3339 Nano UTC cursor values without discarding nanoseconds. */
function miner_log_cursor_position(string $time):?array {
    // RFC3339 permits Z or explicit UTC offsets. Salad can emit either form.
    // Preserve nanoseconds for paging, but normalize the instant to UTC.
    if(!preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,9}))?(Z|[+-]\d{2}:\d{2})$/D',
        $time,$m))return null;
    $seconds=strtotime($m[1].$m[3]);
    if($seconds===false)return null;
    $nanos=(int)str_pad($m[2]??'',9,'0');
    return [$seconds,$nanos];
}

/** Sort newest-first by actual UTC instant, never by RFC3339 text spelling. */
function miner_log_compare_newest(mixed $a,mixed $b):int {
    $at=is_array($a)?(string)($a['time']??$a['timestamp']??''):'';
    $bt=is_array($b)?(string)($b['time']??$b['timestamp']??''):'';
    $ap=miner_log_cursor_position($at);
    $bp=miner_log_cursor_position($bt);
    if($ap!==null && $bp!==null) {
        return ($bp[0]<=>$ap[0]) ?: ($bp[1]<=>$ap[1]);
    }
    if($ap!==null)return -1;
    if($bp!==null)return 1;
    return strcmp($bt,$at);
}

/**
 * Salad logs have a maximum of 100 events per response, a page_min_time
 * cursor and no numbered pages. Advance backwards using Salad's FULL
 * PRECISION cursor, including the boundary on the next page, and dedupe
 * overlapping events. This is O(pages), not repeated midpoint bisection.
 *
 * The fetch callable accepts (startEpoch, endRfc3339, remainingSeconds).
 * Incomplete pagination is ALWAYS reported partial; never invent per-GPU data.
 */
function miner_log_collect_windows(callable $fetch,int $startUtc,int $endUtc,
    int $maxRequests=8,float $timeBudgetSeconds=14.0):array
{
    if($endUtc<=$startUtc || $maxRequests<1 || $maxRequests>32
        || $timeBudgetSeconds<=0 || $timeBudgetSeconds>90)
        throw new InvalidArgumentException('Invalid Salad log coverage limits');

    $startedAt=microtime(true);
    $deadline=$startedAt+$timeBudgetSeconds;
    $cursor=gmdate('Y-m-d\\TH:i:s\\Z',$endUtc);
    $cursorPos=[$endUtc,0];
    $unique=[];$requests=0;$partial=false;$saturated=0;
    while($requests<$maxRequests && microtime(true)<$deadline){
        $remaining=$deadline-microtime(true);
        if($remaining<=0.15){$partial=true;break;}
        $requests++;
        try {
            $response=$fetch($startUtc,$cursor,$remaining);
            $items=$response['items']??null;
            if(!is_array($items) || !array_is_list($items) || count($items)>100)
                throw new UnexpectedValueException('Malformed Salad log page');
        }catch(Throwable $e){
            if($requests===1)throw $e;
            $partial=true;
            break;
        }
        foreach($items as $item){
            if(!is_array($item)){$partial=true;continue;}
            $pos=miner_log_cursor_position((string)($item['time']??$item['timestamp']??''));
            if($pos===null || $pos[0]<$startUtc
                || $pos[0]>$cursorPos[0]
                || ($pos[0]===$cursorPos[0] && $pos[1]>$cursorPos[1])){
                $partial=true;
                continue;
            }
            // The original resource labels are part of this fingerprint,
            // preventing two separate GPUs' identical messages from merging.
            $key=hash('sha256',json_encode($item,JSON_THROW_ON_ERROR));
            $unique[$key]=$item;
        }
        if(count($items)<100)break;

        $saturated++;
        $oldest=$response['page_min_time']??($items[count($items)-1]['time']??null);
        $next=is_string($oldest)?miner_log_cursor_position($oldest):null;
        if($next===null || $next[0]<$startUtc
            || ($next[0]===$startUtc && $next[1]===0)
            || $next[0]>$cursorPos[0]
            || ($next[0]===$cursorPos[0] && $next[1]>=$cursorPos[1])){
            // At a timestamp with >=100 events there is no safe cursor:
            // claiming complete coverage would conceal unidentified GPUs.
            $partial=true;
            break;
        }
        $cursor=$oldest;
        $cursorPos=$next;
        if($requests===$maxRequests)$partial=true;
    }
    if($requests===$maxRequests && $saturated===$requests)$partial=true;
    if(microtime(true)>=$deadline && $saturated>0)$partial=true;

    $result=array_values($unique);
    usort($result,'miner_log_compare_newest');
    return ['items'=>$result,'partial'=>$partial,'requests'=>$requests,
        'saturated_windows'=>$saturated,'unique'=>count($result),
        'elapsed_ms'=>(int)round((microtime(true)-$startedAt)*1000)];
}
