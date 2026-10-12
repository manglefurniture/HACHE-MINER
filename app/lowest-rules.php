<?php
declare(strict_types=1);

/**
 * Each Lowest GPU receives an individual read-only indicator. Historical
 * references use verified measurements, never requested counts or assumed profit.
 * These are relative efficiency references, NOT profit or auto-reallocation.
 */
function miner_lowest_median(array $values): ?float {
    if($values===[])return null;
    sort($values,SORT_NUMERIC);
    $n=count($values);
    return $n%2 ? (float)$values[intdiv($n,2)]
        : ((float)$values[intdiv($n,2)-1]+(float)$values[intdiv($n,2)])/2;
}

/**
 * A group-wide PRL event is insufficient. Historical sample must have
 * explicit pearlhash evidence in its own time window (not 72h apart).
 * Timestamp arrays are sorted ascending; binary search keeps it bounded.
 */
function miner_lowest_evidence_near(array $timestamps,int $at):bool {
    $lo=0;$hi=count($timestamps);
    $start=$at-900;$end=$at+120;
    while($lo<$hi) {
        $mid=intdiv($lo+$hi,2);
        if($timestamps[$mid]<$start)$lo=$mid+1;
        else $hi=$mid;
    }
    return isset($timestamps[$lo]) && $timestamps[$lo]<=$end;
}

/** Pure reducer; explicit algorithm evidence must accompany every observation. */
function miner_lowest_profiles_from_rows(array $rows,array $evidence,int $maxModels=PHP_INT_MAX):array {
    $buckets=[];
    foreach($rows as $r) {
        if(strtolower((string)($r['priority_at_observation']??''))!=='lowest'
            || strtolower((string)($r['state']??''))!=='running'
            || (int)($r['ready']??0)!==1 || (int)($r['started']??0)!==1)continue;
        $org=(string)($r['organization']??'');
        $class=(string)($r['gpu_class_at_observation']??'');
        $gpu=trim((string)($r['gpu_model']??''));
        $hash=$r['hashrate_ths']??null;
        $rate=$r['official_rate_usd_per_hour']??null;
        $at=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',
            (string)($r['observed_at']??''),new DateTimeZone('UTC'));
        if($org===''||$class===''||$gpu===''||!$at
            ||!is_numeric($hash)||!is_numeric($rate))continue;
        $groupId=(int)($r['group_id']??-1);
        if(!miner_lowest_evidence_near($evidence[$groupId]??[],$at->getTimestamp()))continue;
        $hash=(float)$hash;$rate=(float)$rate;
        if(!is_finite($hash)||$hash<=0||$hash>20000
            ||!is_finite($rate)||$rate<=0||$rate>100)continue;
        $key=$org."\0".$class;
        if(!isset($buckets[$key]))$buckets[$key]=[
            'organization'=>$org,'gpu_class'=>$class,'gpu'=>$gpu,
            'points'=>[],'first_at'=>PHP_INT_MAX,'last_at'=>0,'instances'=>[]
        ];
        $b=&$buckets[$key];
        $ts=$at->getTimestamp();
        $b['first_at']=min($b['first_at'],$ts);
        $b['last_at']=max($b['last_at'],$ts);
        $b['instances'][(string)($r['group_id']??'').':'.(string)($r['instance_id']??'')]=true;
        $b['points'][]=['hash'=>$hash,'efficiency'=>$hash/$rate];
        unset($b);
    }
    $qualified=[];
    foreach($buckets as $key=>$b) {
        $n=count($b['points']);
        // Two hours at a 5-minute interval, plus at least one hour of actual
        // span. Short-lived burst/one-off test nodes must keep learning.
        if($n<24 || $b['last_at']-$b['first_at']<3600)continue;
        $eff=miner_lowest_median(array_column($b['points'],'efficiency'));
        $ths=miner_lowest_median(array_column($b['points'],'hash'));
        if($eff===null || $eff<=0 || $ths===null)continue;
        $qualified[$key]=[
            'organization'=>$b['organization'],'gpu_class'=>$b['gpu_class'],
            'gpu'=>$b['gpu'],'samples'=>$n,'instances'=>count($b['instances']),
            'median_ths'=>round($ths,2),'median_efficiency'=>$eff,
            'hours_spanned'=>round(($b['last_at']-$b['first_at'])/3600,1)
        ];
    }
    uasort($qualified,static fn($a,$b)=>
        $b['samples']<=>$a['samples']
        ?:strcmp($a['organization'],$b['organization'])
        ?:strcmp($a['gpu_class'],$b['gpu_class']));
    return array_slice($qualified,0,max(0,$maxModels),true);
}

/**
 * Read explicit algorithm evidence and priced observations in bounded batches.
 * Non-PRL groups are excluded BEFORE the observation limit is applied.
 */
function miner_lowest_reference_data(PDO $db):array {
    if(!function_exists('miner_salad_prices_ready') || !miner_salad_prices_ready($db))
        return ['profiles'=>[],'partial'=>false,'observations'=>0,'reason'=>'Sin tarifas oficiales'];
    $logs=$db->query("SELECT group_id,logged_at FROM log_events
        WHERE logged_at>=UTC_TIMESTAMP()-INTERVAL 72 HOUR-INTERVAL 15 MINUTE
          AND summary LIKE '%[pearlhash]%'
        ORDER BY group_id,logged_at LIMIT 30001")->fetchAll(PDO::FETCH_ASSOC);
    if(count($logs)>30000)return ['profiles'=>[],'partial'=>true,'observations'=>0];
    $evidence=[];
    foreach($logs as $log) {
        $at=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',
            (string)$log['logged_at'],new DateTimeZone('UTC'));
        if($at!==false)$evidence[(int)$log['group_id']][]=$at->getTimestamp();
    }
    if($evidence===[])return ['profiles'=>[],'partial'=>false,'observations'=>0];
    $ids=array_keys($evidence);
    if(count($ids)>300)return ['profiles'=>[],'partial'=>true,'observations'=>0];
    $placeholders=implode(',',array_fill(0,count($ids),'?'));
    $sql="SELECT m.group_id,m.instance_id,m.observed_at,m.state,m.ready,m.started,
        m.priority_at_observation,m.gpu_class_at_observation,m.gpu_model,
        m.hashrate_ths,m.official_rate_usd_per_hour,g.organization
        FROM miner_observations m JOIN group_state g ON g.id=m.group_id
        WHERE m.observed_at>=UTC_TIMESTAMP()-INTERVAL 72 HOUR
          AND m.group_id IN ($placeholders)
          AND m.priority_at_observation='lowest'
          AND m.state='running' AND m.ready=1 AND m.started=1
          AND m.hashrate_ths>0 AND m.gpu_class_at_observation IS NOT NULL
          AND m.official_rate_usd_per_hour>0
        ORDER BY m.observed_at DESC,m.id DESC LIMIT 18001";
    $st=$db->prepare($sql);
    $st->execute($ids);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    $partial=count($rows)>18000;
    return ['profiles'=>$partial?[]:miner_lowest_profiles_from_rows($rows,$evidence),
        'partial'=>$partial,'observations'=>min(count($rows),18000)];
}

/** Current official Salad rate is required, never yesterday's or manual price. */
function miner_lowest_price_index(array $rates):array {
    $out=[];
    foreach($rates as $r) {
        if(($r['priority']??'')!=='lowest'||!is_numeric($r['usd_per_hour']??null))continue;
        $price=(float)$r['usd_per_hour'];
        if(!is_finite($price)||$price<=0||$price>100)continue;
        $out[(string)$r['organization']."\0".(string)$r['gpu_class']]=$price;
    }
    return $out;
}

/**
 * Every Lowest instance receives an explicit signal, including a neutral
 * learning/unknown state when no defensible model-specific threshold exists.
 * Never borrow thresholds from Low or treat missing measurements as losses.
 */
function miner_lowest_signal(string $organization,string $gpuClass,?array $metric,
    bool $pearlhash,array $profiles,array $prices):array {
    $pending=[
      'level'=>'neutral','label'=>'Aprendiendo referencia Lowest',
      'floor'=>null,'margin'=>null,'kind'=>'lowest_learning'
    ];
    if(!$pearlhash)return array_replace($pending,['label'=>'PRL sin verificar']);
    if($metric===null)return array_replace($pending,[
        'level'=>'unknown','label'=>'Sin medición individual reciente'
    ]);
    if($gpuClass==='')return array_replace($pending,['label'=>'Clase de GPU sin identificar']);
    $hash=$metric['hashrate_ths']??null;
    if(!is_numeric($hash)||!is_finite((float)$hash)||(float)$hash<0)
        return array_replace($pending,['level'=>'unknown','label'=>'Medición inválida']);
    $key=$organization."\0".$gpuClass;
    $reference=$profiles[$key]??null;
    if($reference===null)return array_replace($pending,[
        'label'=>'Aprendiendo · faltan mediciones verificadas'
    ]);
    $price=$prices[$key]??null;
    if($price===null)return array_replace($pending,[
        'label'=>'Sin tarifa oficial Lowest vigente','samples'=>$reference['samples']
    ]);
    $floor=$reference['median_efficiency']*$price*0.75;
    $watch=$reference['median_efficiency']*$price*0.90;
    $level=(float)$hash<$floor?'red':((float)$hash<$watch?'yellow':'green');
    return [
      'level'=>$level,
      'label'=>$level==='red'?'Baja eficiencia Lowest'
          :($level==='yellow'?'Vigilar eficiencia Lowest':'Eficiencia Lowest estable'),
      'floor'=>round($floor,2),
      'margin'=>round((float)$hash-$floor,2),
      'kind'=>'lowest_relative',
      'samples'=>$reference['samples'],
      'rate'=>$price
    ];
}
