<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';
require_once __DIR__.'/salad-gpu-prices.php';

/**
 * Read-only health inference for one Salad target. A successful sync is not
 * proof of active GPUs; those are separately observed, not assumed.
 */
function miner_collector_health(?array $sync, int $now): string {
    if ($sync===null) return 'sin_datos';
    $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)($sync['observed_at']??''),new DateTimeZone('UTC'));
    if ($date===false) return 'atrasado';
    $when=$date->getTimestamp();
    if ($when>$now+120 || $now-$when>900) return 'atrasado';
    return match ((string)($sync['status']??'')) {
        'ok'=>'correcto',
        'partial'=>'parcial',
        'missing'=>'sin_configurar',
        default=>'error'
    };
}
function miner_collector_health_label(string $status): string {
    return match($status) {
        'correcto'=>'Lectura reciente',
        'parcial'=>'Lectura parcial',
        'sin_configurar'=>'Falta configuración',
        'atrasado'=>'Sin lectura reciente',
        'error'=>'Error de lectura',
        default=>'Sin datos'
    };
}

/**
 * Official prices are useful only while the local authenticated snapshot is
 * fresh. A failed refresh never turns an unknown price into zero.
 *
 * @return array{status:string,observed_at:?string,entries:int}
 */
function miner_rate_catalog_health(?array $sync, ?array $catalog, int $now): array {
    $observed=is_array($catalog) ? ($catalog['observed_at']??null) : null;
    $entries=is_array($catalog) ? (int)($catalog['entries']??0) : 0;
    if (!is_string($observed) || $observed==='' || $entries<1) {
        return ['status'=>'sin_tarifas','observed_at'=>null,'entries'=>0];
    }
    $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$observed,new DateTimeZone('UTC'));
    if ($date===false || $date->getTimestamp()>$now+120 || $now-$date->getTimestamp()>5400) {
        return ['status'=>'tarifas_atrasadas','observed_at'=>$observed,'entries'=>$entries];
    }
    if ($sync!==null && (string)($sync['status']??'')!=='ok') {
        return ['status'=>'tarifas_parciales','observed_at'=>$observed,'entries'=>$entries];
    }
    return ['status'=>'tarifas_recientes','observed_at'=>$observed,'entries'=>$entries];
}
function miner_rate_catalog_health_label(string $status): string {
    return match($status) {
        'tarifas_recientes'=>'Tarifas recientes',
        'tarifas_parciales'=>'Última tarifa parcial',
        'tarifas_atrasadas'=>'Tarifas atrasadas',
        default=>'Sin tarifas oficiales'
    };
}
/** Latest distinct instances, not accumulated samples. A ready container is not proof of mining. */
function miner_recent_group_statuses(): array {
    $sql="SELECT m.group_id,m.state,m.ready,m.started,m.observed_at
      FROM miner_observations m
      JOIN (
        SELECT group_id,instance_id,MAX(observed_at) AS sample_time
        FROM miner_observations
        WHERE observed_at>=UTC_TIMESTAMP()-INTERVAL 10 MINUTE
        GROUP BY group_id,instance_id
      ) latest ON latest.group_id=m.group_id
             AND latest.instance_id=m.instance_id AND latest.sample_time=m.observed_at
      JOIN group_state g ON g.id=m.group_id
      WHERE g.last_seen_at>=UTC_TIMESTAMP()-INTERVAL 10 MINUTE";
    $rows=miner_db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $groups=[];
    foreach ($rows as $row) {
        $id=(int)$row['group_id'];
        if (!isset($groups[$id])) $groups[$id]=['observed'=>0,'ready'=>0,'latest'=>null];
        $groups[$id]['observed']++;
        if ((string)$row['state']==='running' && (int)$row['ready']===1 && (int)$row['started']===1) {
            $groups[$id]['ready']++;
        }
        if ($groups[$id]['latest']===null || $groups[$id]['latest']<$row['observed_at']) {
            $groups[$id]['latest']=$row['observed_at'];
        }
    }
    return $groups;
}

/** @return array{targets:array,warnings:array,summary:array} */
function miner_collector_diagnostics(): array {
    $db=miner_db();
    $targets=miner_salad_targets(false);
    // All queries are SELECT-only; this page must not trigger API calls.
    $getRun=$db->prepare('SELECT observed_at,status,detail FROM sync_runs WHERE source_name=? ORDER BY observed_at DESC,id DESC LIMIT 1');
    $getLastError=$db->prepare("SELECT observed_at,status,detail FROM sync_runs WHERE source_name=? AND status<>'ok' ORDER BY observed_at DESC,id DESC LIMIT 1");
    $getGroups=$db->prepare('SELECT COUNT(*) AS groups_seen,COALESCE(SUM(desired_replicas),0) AS desired_replicas,MAX(last_seen_at) AS last_snapshot FROM group_state WHERE organization=? AND project_name=? AND last_seen_at >= UTC_TIMESTAMP()-INTERVAL 15 MINUTE');
    $getSamples=$db->prepare('SELECT COUNT(*) FROM miner_observations m JOIN group_state g ON g.id=m.group_id WHERE g.organization=? AND g.project_name=? AND m.observed_at >= UTC_TIMESTAMP()-INTERVAL 15 MINUTE');
    $getCurrentIds=$db->prepare('SELECT id FROM group_state WHERE organization=? AND project_name=? AND last_seen_at>=UTC_TIMESTAMP()-INTERVAL 10 MINUTE');
    $pricesReady=miner_salad_prices_ready($db);
    $getRateRun=$pricesReady ? $db->prepare('SELECT observed_at,status,detail FROM sync_runs WHERE source_name=? ORDER BY observed_at DESC,id DESC LIMIT 1') : null;
    $getRateCatalog=$pricesReady ? $db->prepare('SELECT MAX(observed_at) AS observed_at,COUNT(*) AS entries FROM salad_gpu_prices WHERE organization=?') : null;
    $now=time();
    $replicaStates=miner_recent_group_statuses();
    $items=[];
    $totals=['fresh'=>0,'stale'=>0,'other'=>0,'groups_seen'=>0,'samples'=>0,'instances'=>0,'ready'=>0,
        'rates_fresh'=>0,'rates_stale'=>0,'rates_other'=>0];
    foreach(array_slice($targets,0,100) as $target) {
        $org=(string)$target['organization_slug'];
        $project=(string)$target['project_slug'];
        $enabled=(int)$target['enabled']===1;
        $getRun->execute(['salad:'.$org.'/'.$project]);
        $run=$getRun->fetch(PDO::FETCH_ASSOC)?:null;
        $getLastError->execute(['salad:'.$org.'/'.$project]);
        $lastError=$getLastError->fetch(PDO::FETCH_ASSOC)?:null;
        $rateRun=null;$rateCatalog=null;
        if($getRateRun!==null && $getRateCatalog!==null) {
            $getRateRun->execute(['salad-rates:'.$org]);
            $rateRun=$getRateRun->fetch(PDO::FETCH_ASSOC)?:null;
            $getRateCatalog->execute([$org]);
            $rateCatalog=$getRateCatalog->fetch(PDO::FETCH_ASSOC)?:null;
        }
        $rateHealth=$pricesReady
            ? miner_rate_catalog_health($rateRun,$rateCatalog,$now)
            : ['status'=>'sin_tarifas','observed_at'=>null,'entries'=>0];
        $getGroups->execute([$org,$project]);
        $groups=$getGroups->fetch(PDO::FETCH_ASSOC)?:[];
        $getSamples->execute([$org,$project]);
        $samples=(int)$getSamples->fetchColumn();
        $getCurrentIds->execute([$org,$project]);
        $instances=0;$ready=0;
        foreach($getCurrentIds->fetchAll(PDO::FETCH_COLUMN) as $groupId) {
            $snap=$replicaStates[(int)$groupId]??null;
            if ($snap!==null) {
                $instances+=(int)$snap['observed'];
                $ready+=(int)$snap['ready'];
            }
        }
        $status=$enabled?miner_collector_health($run,$now):'pausado';
        if ($status==='correcto') $totals['fresh']++;
        elseif($status==='atrasado') $totals['stale']++;
        else $totals['other']++;
        if($rateHealth['status']==='tarifas_recientes')$totals['rates_fresh']++;
        elseif($rateHealth['status']==='tarifas_atrasadas')$totals['rates_stale']++;
        else $totals['rates_other']++;
        $totals['groups_seen']+=(int)($groups['groups_seen']??0);
        $totals['samples']+=$samples;
        $totals['instances']+=$instances;
        $totals['ready']+=$ready;
        $items[]=[
            'label'=>(string)$target['label'],
            'organization'=>$org,
            'project'=>$project,
            'enabled'=>$enabled,
            'status'=>$status,
            'observed_at'=>$run['observed_at']??null,
            'detail'=>$run['detail']??null,
            'last_error_at'=>$lastError['observed_at']??null,
            'last_error_status'=>$lastError['status']??null,
            'rate_status'=>$rateHealth['status'],
            'rate_observed_at'=>$rateHealth['observed_at'],
            'rate_entries'=>$rateHealth['entries'],
            'groups_seen'=>(int)($groups['groups_seen']??0),
            'desired_replicas'=>(int)($groups['desired_replicas']??0),
            'last_snapshot'=>$groups['last_snapshot']??null,
            'samples'=>$samples,
            'instances'=>$instances,
            'ready'=>$ready
        ];
    }
    $warnings=$db->query("SELECT g.organization,g.group_name,l.logged_at,l.summary FROM log_events l JOIN group_state g ON g.id=l.group_id WHERE l.severity='warning' AND l.logged_at>=UTC_TIMESTAMP()-INTERVAL 24 HOUR ORDER BY l.logged_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    foreach($warnings as &$warning) $warning['summary']=miner_scrub_log((string)$warning['summary']);
    unset($warning);
    return ['targets'=>$items,'warnings'=>$warnings,'summary'=>$totals];
}
