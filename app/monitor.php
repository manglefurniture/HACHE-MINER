<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';

/** Separar estados de asignación de evidencia de minería. */
function miner_monitor_status(string $groupState, bool $recent, int $desired, int $observed, int $ready): string {
    if (!$recent) return 'sin_lectura';
    $state=strtolower($groupState);
    if (in_array($state,['stopped','stopping','disabled'],true)) return 'detenido';
    if (in_array($state,['pending','allocating','starting','deploying'],true)) return 'esperando';
    if (!in_array($state,['running','started'],true)) return 'desconocido';
    if ($desired===0) return 'sin_replicas';
    if ($observed===0) return 'sin_instancias';
    if ($ready<$desired) return 'parcial';
    return 'listas';
}
function miner_monitor_status_label(string $status):string {
    return match ($status) {
        'sin_lectura'=>'Sin lectura reciente',
        'detenido'=>'Detenido',
        'esperando'=>'Pendiente de asignación',
        'sin_replicas'=>'Sin réplicas solicitadas',
        'sin_instancias'=>'Sin instancias observadas',
        'parcial'=>'Asignación parcial',
        'listas'=>'Contenedores listos',
        default=>'Estado sin confirmar'
    };
}
function miner_monitor_log_metric(string $line): ?array {
    $line=miner_scrub_log($line);
    // SRBMiner single GPU line: device, H/s, watts, fan and temperature.
    if (!preg_match('/#\d+\s+(?<gpu>.+?)\s+(?<hash>\d+(?:\.\d+)?)\s+TH\/s\s+(?<power>\d+(?:\.\d+)?)W\s+(?<eff>\d+(?:\.\d+)?)\s+(?<fan>\d+)%\s+(?<temp>\d+)C/i',$line,$m)) return null;
    $hash=(float)$m['hash'];$watts=(float)$m['power'];$temp=(int)$m['temp'];$fan=(int)$m['fan'];
    if (!is_finite($hash) || $hash<0 || $hash>20000 || $watts<0 || $watts>1500 || $temp<0 || $temp>150 || $fan<0 || $fan>100) return null;
    return ['gpu'=>substr(trim($m['gpu']),0,120),'hashrate_ths'=>$hash,'watts'=>$watts,'temp_c'=>$temp,'fan'=>$fan];
}
function miner_monitor_average_15m(string $line): ?float {
    $clean=miner_scrub_log($line);
    if (!preg_match('/15\s*min\s+(\d+(?:\.\d+)?)\s+TH\/s/i',$clean,$m)) return null;
    $v=(float)$m[1];
    return is_finite($v) && $v>=0 && $v<20000 ? $v : null;
}
/**
 * Vista dinámica de todas las agrupaciones registradas por las APIs de los
 * proyectos habilitados y de los históricos conocidos desde la activación.
 * Las instancias vistas en 15 min se listan individualmente, también si están
 * allocating o pendientes. No se usan para atribuir ingresos ni hashrate.
 */
function miner_monitor_inventory(): array {
    $db=miner_db();
    $targets=miner_salad_targets(false);
    $groups=$db->query('SELECT id,organization,project_name,group_name,state,priority,desired_replicas,gpu_class,last_seen_at FROM group_state ORDER BY organization,project_name,group_name')->fetchAll(PDO::FETCH_ASSOC);
    $query="SELECT m.group_id,m.instance_id,m.observed_at,m.state,m.ready,m.started
      FROM miner_observations m INNER JOIN (
        SELECT group_id,instance_id,MAX(observed_at) latest_at
        FROM miner_observations
        WHERE observed_at>=UTC_TIMESTAMP()-INTERVAL 15 MINUTE
        GROUP BY group_id,instance_id
      ) last ON m.group_id=last.group_id AND m.instance_id=last.instance_id AND m.observed_at=last.latest_at";
    $observations=$db->query($query)->fetchAll(PDO::FETCH_ASSOC);
    $byGroup=[];
    foreach ($observations as $ob) {
        $id=(int)$ob['group_id'];
        $byGroup[$id][]=[
            'id'=>(string)$ob['instance_id'],
            'state'=>(string)$ob['state'],
            'ready'=>((int)$ob['ready']===1 && (int)$ob['started']===1 && $ob['state']==='running'),
            'observed_at'=>(string)$ob['observed_at']
        ];
    }
    $logSql="SELECT group_id,logged_at,summary,severity FROM log_events
      WHERE logged_at>=UTC_TIMESTAMP()-INTERVAL 15 MINUTE
      ORDER BY logged_at DESC LIMIT 1500";
    $logs=$db->query($logSql)->fetchAll(PDO::FETCH_ASSOC);
    $lastMetric=[];$lastAverage=[];$warningCount=[];
    foreach($logs as $log) {
        $id=(int)$log['group_id'];
        if ($log['severity']==='warning') $warningCount[$id]=($warningCount[$id]??0)+1;
        if (!isset($lastMetric[$id])) {
            $metric=miner_monitor_log_metric((string)$log['summary']);
            if ($metric!==null) $lastMetric[$id]=$metric+['at'=>$log['logged_at']];
        }
        if (!isset($lastAverage[$id])) {
            $average=miner_monitor_average_15m((string)$log['summary']);
            if ($average!==null) $lastAverage[$id]=['ths'=>$average,'at'=>$log['logged_at']];
        }
    }
    $now=time();
    $stats=['groups'=>count($groups),'current_groups'=>0,'desired'=>0,'observed'=>0,'ready'=>0,'waiting'=>0,'historical'=>0];
    foreach($groups as &$group) {
        $id=(int)$group['id'];
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)$group['last_seen_at'],new DateTimeZone('UTC'));
        $age=$date!==false?$now-$date->getTimestamp():PHP_INT_MAX;
        $recent=$age>=-120 && $age<=900;
        $instances=$recent?($byGroup[$id]??[]):[];
        $observed=count($instances);
        $ready=0;
        foreach($instances as $instance) if($instance['ready']) $ready++;
        $desired=(int)$group['desired_replicas'];
        $group['recent']=$recent;
        $group['instances']=$instances;
        $group['observed']=$observed;
        $group['ready']=$ready;
        $group['warnings']=$recent?($warningCount[$id]??0):0;
        // This is group-log GPU 0 telemetry only; not per-instance measurements
        // on multi-replica groups. Never infer mining from ready/started state.
        $group['metric']=$recent && $desired===1 && $observed===1 ? ($lastMetric[$id]??null):null;
        $group['average_15m']=$recent && $desired===1 && $observed===1 ? ($lastAverage[$id]??null):null;
        $group['classification']=miner_monitor_status((string)$group['state'],$recent,$desired,$observed,$ready);
        if($recent) {
            $stats['current_groups']++;
            $stats['desired']+=$desired;$stats['observed']+=$observed;$stats['ready']+=$ready;
            if ($ready<$desired) $stats['waiting']+=$desired-$ready;
        } else $stats['historical']++;
    }
    unset($group);
    return ['targets'=>$targets,'groups'=>$groups,'stats'=>$stats];
}
