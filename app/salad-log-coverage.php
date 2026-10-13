<?php
declare(strict_types=1);

/**
 * Salad log search has a 100-row limit and NO cursor. Divide saturated time
 * intervals, newest first, using inclusive UTC boundaries. Overlapping
 * boundary events are deduplicated without assigning group metrics to GPUs.
 *
 * Returns partial=true whenever the bounded search cannot prove coverage.
 * Callable receives (int $startUtc, int $endUtc) and returns Salad's response.
 */
function miner_log_collect_windows(callable $fetch,int $startUtc,int $endUtc,
    int $maxRequests=8,float $timeBudgetSeconds=18.0):array
{
    if($endUtc<=$startUtc || $maxRequests<1 || $maxRequests>32
        || $timeBudgetSeconds<=0 || $timeBudgetSeconds>90)
        throw new InvalidArgumentException('Invalid Salad log coverage limits');

    $deadline=microtime(true)+$timeBudgetSeconds;
    $pending=[[$startUtc,$endUtc]];
    $unique=[];$requests=0;$partial=false;$saturated=0;
    while($pending!==[] && $requests<$maxRequests && microtime(true)<$deadline) {
        [$from,$to]=array_pop($pending);
        $requests++;
        try{
            $response=$fetch($from,$to);
            $items=$response['items']??null;
            if(!is_array($items) || !array_is_list($items) || count($items)>100)
                throw new UnexpectedValueException('Malformed Salad log page');
        }catch(Throwable $e){
            // Do not discard previously verified logs if a later subdivision
            // times out or hits API limits. The result remains explicitly partial.
            if($requests===1)throw $e;
            $partial=true;
            break;
        }
        foreach($items as $item) {
            if(!is_array($item)){$partial=true;continue;}
            $ts=strtotime((string)($item['time']??$item['timestamp']??''));
            if($ts===false || $ts<$from || $ts>$to){$partial=true;continue;}
            // Full original event identity prevents overlap double-counting and
            // keeps identical GPU messages from DIFFERENT labelled nodes apart.
            $fingerprint=hash('sha256',json_encode($item,JSON_THROW_ON_ERROR));
            $unique[$fingerprint]=$item;
        }
        if(count($items)===100) {
            $saturated++;
            // Splitting [from,mid] and [mid,to] intentionally repeats midpoint.
            // Never claim full coverage of a 1-second bucket with >=100 events.
            $middle=intdiv($from+$to,2);
            if($middle<=$from || $middle>=$to) {
                $partial=true;
            } else {
                $pending[]=[$from,$middle];
                $pending[]=[$middle,$to];
            }
        }
    }
    if($pending!==[])$partial=true;
    $result=array_values($unique);
    usort($result,static fn($a,$b)=>strcmp(
        (string)($b['time']??$b['timestamp']??''),
        (string)($a['time']??$a['timestamp']??'')));
    return ['items'=>$result,'partial'=>$partial,'requests'=>$requests,
        'saturated_windows'=>$saturated,'unique'=>count($result)];
}
