<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';
require_once __DIR__.'/shares.php';
require_once __DIR__.'/salad-gpu-prices.php';

/**
 * Aggregate one model across ALL organizations, projects, groups and replicas.
 * Every duration is an observed GPU interval (GPU-hours), not wall-clock
 * time, and neither time nor cost depends on hashrate/accepted shares.
 * Never substitute today's price/priority for a historical observation.
 */
function miner_history_model(string $raw,?string $officialClass,string $org,array $catalog):string {
    $label=$officialClass!==null && $officialClass!==''
        ?($catalog[$org."\0".$officialClass]??null):null;
    $name=is_string($label) && $label!==''?$label:$raw;
    if($name==='' || preg_match('/^(?:unknown|sin modelo|sin identificaci[oó]n)$/iu',trim($name)))
        return 'SIN_IDENTIFICAR';
    $model=miner_share_gpu_family($name);
    return $model!==''?$model:'SIN_IDENTIFICAR';
}
function miner_history_priority(?string $priority):string {
    $p=strtolower(trim($priority??''));
    if($p==='batch')$p='lowest';
    return in_array($p,['lowest','low','medium','high'],true)?$p:'sin_registro';
}
function miner_history_empty_bucket(string $model,string $priority=''):array {
    return ['gpu'=>$model,'priority'=>$priority,'sessions'=>0,
        'observed_seconds'=>0,'priced_seconds'=>0,'unpriced_seconds'=>0,
        'cost_usd'=>0.0,'cost_intervals'=>0,'hash_total'=>0.0,
        'hash_count'=>0,'accepted'=>0,'accepted_intervals'=>0,'resets'=>0];
}
function miner_history_add(array &$records,string $model,string $priority,string $metric,float $quantity):void {
    if(!isset($records[$model]))$records[$model]=miner_history_empty_bucket($model);
    if(!isset($records[$model]['priorities'][$priority]))
        $records[$model]['priorities'][$priority]=miner_history_empty_bucket($model,$priority);
    $records[$model][$metric]+=$quantity;
    $records[$model]['priorities'][$priority][$metric]+=$quantity;
}
/**
 * Pure reducer with one prior row because query ordering groups consecutive
 * samples by group/instance/time. Exercises are covered by regression tests.
 */
function miner_history_reduce(array &$models,?array &$previous,array $r,array $names):void {
    $id=(string)($r['group_id']??'')."\0".(string)($r['instance_id']??'');
    $at=strtotime((string)($r['observed_at']??'').' UTC');
    if($at===false)return;
    $active=($r['state']??'')==='running';
    $ready=$active && (int)($r['ready']??0)===1 && (int)($r['started']??0)===1;
    $priority=miner_history_priority(isset($r['priority_at_observation'])?(string)$r['priority_at_observation']:null);
    $cl=isset($r['gpu_class_at_observation'])?(string)$r['gpu_class_at_observation']:null;
    $model=miner_history_model((string)($r['gpu_model']??''),$cl,(string)($r['organization']??''),$names);
    $same=$previous!==null && $previous['id']===$id;
    $gap=$same?$at-$previous['at']:0;
    $contiguous=$same && $gap>0 && $gap<=420;
    $priorRunning=$contiguous && $previous['active'];
    if($active && $model==='SIN_IDENTIFICAR' && $priorRunning
        && $previous['model']!=='SIN_IDENTIFICAR')$model=$previous['model'];
    // A new model or priority after a gap/stop represents a new observed session.
    if($active && (!$priorRunning || $previous['model']!==$model
        || $previous['priority']!==$priority))
        miner_history_add($models,$model,$priority,'sessions',1);
    if($active && $ready && is_numeric($r['hashrate_ths']??null)) {
        $hash=(float)$r['hashrate_ths'];
        if(is_finite($hash) && $hash>=0 && $hash<=20000) {
            miner_history_add($models,$model,$priority,'hash_total',$hash);
            miner_history_add($models,$model,$priority,'hash_count',1);
        }
    }
    if($active && $priorRunning) {
        // Across a known model swap the interval cannot be attributed.
        $intervalModel=($previous['model']!=='SIN_IDENTIFICAR'
            && $model!=='SIN_IDENTIFICAR' && $previous['model']!==$model)
            ?'SIN_IDENTIFICAR':$model;
        if($intervalModel==='SIN_IDENTIFICAR' && $previous['model']!=='SIN_IDENTIFICAR'
            && $model==='SIN_IDENTIFICAR')$intervalModel=$previous['model'];
        $intervalPriority=$previous['priority']===$priority?$priority:'sin_registro';
        miner_history_add($models,$intervalModel,$intervalPriority,'observed_seconds',$gap);
        $official=$cl!==null && $cl!=='' && $cl===$previous['class']
            && $priority!=='sin_registro' && $priority===$previous['priority']
            && is_numeric($r['official_rate_usd_per_hour']??null)
            && $previous['rate']!==null
            && (string)$r['official_rate_usd_per_hour']===$previous['rate']
            && is_numeric($r['estimated_cost_usd']??null);
        $cost=$official?(float)$r['estimated_cost_usd']:null;
        if($cost!==null && is_finite($cost) && $cost>=0 && $cost<=100) {
            miner_history_add($models,$intervalModel,$intervalPriority,'cost_usd',$cost);
            miner_history_add($models,$intervalModel,$intervalPriority,'cost_intervals',1);
            miner_history_add($models,$intervalModel,$intervalPriority,'priced_seconds',$gap);
        } else miner_history_add($models,$intervalModel,$intervalPriority,'unpriced_seconds',$gap);
    }
    if($ready && $priorRunning && $previous['ready']
        && $previous['model']===$model && $previous['priority']===$priority && $gap<=660) {
        $cur=$r['accepted_shares']??null;$before=$previous['shares'];
        if(is_numeric($cur) && is_numeric($before) && (float)$cur>=0 && (float)$before>=0) {
            $delta=(int)$cur-(int)$before;
            if($delta<0)miner_history_add($models,$model,$priority,'resets',1);
            elseif($delta<=100000) {
                miner_history_add($models,$model,$priority,'accepted',$delta);
                miner_history_add($models,$model,$priority,'accepted_intervals',1);
            }
        }
    }
    $previous=['id'=>$id,'at'=>$at,'active'=>$active,'ready'=>$ready,
        'model'=>$model,'priority'=>$priority,'class'=>$cl,
        'rate'=>isset($r['official_rate_usd_per_hour'])?(string)$r['official_rate_usd_per_hour']:null,
        'shares'=>$r['accepted_shares']??null];
}
function miner_history_finish(array $records):array {
    foreach($records as &$model){
        if(isset($model['priorities'])) {
            $order=['lowest'=>0,'low'=>1,'medium'=>2,'high'=>3,'sin_registro'=>4];
            uksort($model['priorities'],static fn($a,$b)=>($order[$a]??9)<=>($order[$b]??9));
            $model['priorities']=array_values($model['priorities']);
        } else $model['priorities']=[];
        $model['avg_ths']=$model['hash_count']>0
            ?round($model['hash_total']/$model['hash_count'],2):null;
        foreach($model['priorities'] as &$p)
            $p['avg_ths']=$p['hash_count']>0?round($p['hash_total']/$p['hash_count'],2):null;
        unset($p);
    }
    unset($model);
    $rows=array_values($records);
    usort($rows,static fn($a,$b)=>$b['observed_seconds']<=>$a['observed_seconds']
        ?:strcmp($a['gpu'],$b['gpu']));
    return $rows;
}
/**
 * Streaming all-time scan. A hard cap prevents unexpectedly large migrations
 * from starving the small VPS. When hit, the UI explicitly says partial.
 */
function miner_history_summary():array {
    $db=miner_db();
    if(!miner_salad_prices_ready($db))return ['unavailable'=>true,'rows'=>[],
        'reason'=>'Falta la migración 006 de tarifas históricas.'];
    $catalog=[];
    if(function_exists('miner_gpu_names_ready') && miner_gpu_names_ready($db)) {
        foreach($db->query('SELECT organization,gpu_class,name FROM salad_gpu_class_names')->fetchAll(PDO::FETCH_ASSOC) as $r)
            $catalog[(string)$r['organization']."\0".(string)$r['gpu_class']]=(string)$r['name'];
    }
    $sql="SELECT m.group_id,m.instance_id,m.observed_at,m.state,m.ready,m.started,
        m.hashrate_ths,m.gpu_model,m.accepted_shares,m.estimated_cost_usd,
        m.gpu_class_at_observation,m.priority_at_observation,m.official_rate_usd_per_hour,
        g.organization
        FROM miner_observations m JOIN group_state g ON g.id=m.group_id
        ORDER BY m.group_id,m.instance_id,m.observed_at LIMIT 100001";
    $models=[];$prev=null;$rows=0;$oldest=null;$newest=null;$limit=100000;
    // MySQL unbuffered result avoids materializing 100k observations in PHP.
    $changed=false;
    try {
        if(defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            // Core creates the PDO connection with MySQL's default buffering ON.
            // getAttribute() for this driver-specific flag is not supported by
            // all PDO MySQL versions; avoid using it.
            $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
            $changed=true;
        }
        $st=$db->query($sql);
        while(($r=$st->fetch(PDO::FETCH_ASSOC))!==false){
            $rows++;
            if($rows>$limit)break;
            $date=(string)$r['observed_at'];
            if($oldest===null || $date<$oldest)$oldest=$date;
            if($newest===null || $date>$newest)$newest=$date;
            miner_history_reduce($models,$prev,$r,$catalog);
        }
        $st->closeCursor();
    }finally{
        if($changed)$db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);
    }
    return ['unavailable'=>false,'rows'=>miner_history_finish($models),
        'partial'=>$rows>$limit,'scanned'=>min($rows,$limit),
        'start_utc'=>$oldest,'end_utc'=>$newest,
        'explanation'=>'Sesiones y horas GPU estimadas mediante muestras consecutivas de estado running, máximo 7 minutos; no son segundos facturados certificados.'];
}
