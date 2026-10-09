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
 * Resolve an individual Salad log ONLY when a resource label/field identifies
 * exactly one of the currently observed instances. The one-node fallback is
 * allowed solely when that node is the group's only running instance and
 * the log is after its recorded running-state transition.
 */
function miner_monitor_log_instance(array $item,array $nodes): ?string {
    $labels=is_array($item['resource']['labels']??null)?$item['resource']['labels']:[];
    $idCandidates=[];
    foreach(['instance_id','container_group_instance_id','container_instance_id'] as $key) {
        foreach([$labels[$key]??null,$item[$key]??null] as $value) {
            if(is_string($value) && $value!=='')$idCandidates[]=$value;
        }
    }
    $machineCandidates=[];
    foreach(['machine_id','container_group_machine_id'] as $key) {
        foreach([$labels[$key]??null,$item[$key]??null] as $value) {
            if(is_string($value) && $value!=='')$machineCandidates[]=$value;
        }
    }
    $matched=[];
    foreach($nodes as $node) {
        if(!is_array($node))continue;
        $id=(string)($node['instance_id']??$node['id']??'');
        $machine=(string)($node['machine_id']??'');
        if ($id!=='' && (in_array($id,$idCandidates,true)
             || ($machine!==''&&in_array($machine,$machineCandidates,true)))) {
            $matched[$id]=true;
        }
    }
    if(count($matched)===1)return (string)array_key_first($matched);
    // Conflicting labels must fail closed, even if the group has one instance.
    if($idCandidates!==[] || $machineCandidates!==[])return null;
    if(count($nodes)!==1 || !is_array($nodes[0]))return null;
    $node=$nodes[0];
    $id=(string)($node['instance_id']??$node['id']??'');
    if($id==='' || !miner_instance_ready($node))return null;
    $changed=strtotime((string)($node['update_time']??''));
    $logged=strtotime((string)($item['time']??$item['timestamp']??''));
    if($changed===false || $logged===false || $logged < $changed-30) return null;
    return $id;
}
/** Return newest provably attributable GPU metric for each instance. */
function miner_monitor_instance_log_metrics(array $logs,array $nodes,?int $now=null): array {
    $now=$now??time();
    usort($logs,static fn($a,$b)=>strcmp(
        (string)($b['time']??$b['timestamp']??''),
        (string)($a['time']??$a['timestamp']??'')));
    $found=[];
    foreach(array_slice($logs,0,300) as $item) {
        if(!is_array($item))continue;
        $time=strtotime((string)($item['time']??$item['timestamp']??''));
        if($time===false || $time>$now+120 || $now-$time>540)continue;
        $metric=miner_monitor_log_metric((string)($item['text_log']??$item['message']??''));
        if($metric===null)continue;
        $instanceId=miner_monitor_log_instance($item,$nodes);
        if($instanceId===null || isset($found[$instanceId]))continue;
        $found[$instanceId]=$metric+['log_at'=>gmdate('Y-m-d H:i:s',$time)];
    }
    return $found;
}
/** Never call an estimated threshold a proven USD profit. */
function miner_monitor_profit_signal(string $group,string $priority,?array $metric,bool $verifiedPearlhash=false): array {
    if($metric===null) return ['level'=>'unknown','label'=>'Sin medición individual','floor'=>null,'margin'=>null];
    $hash=(float)($metric['hashrate_ths']??-1);
    $gpu=strtoupper((string)($metric['gpu_model']??$metric['gpu']??''));
    if(!is_finite($hash)||$hash<0)return ['level'=>'unknown','label'=>'Sin medición individual','floor'=>null,'margin'=>null];
    if(!($verifiedPearlhash || str_starts_with(strtolower($group),'prl-'))
      || strtolower($priority)!=='low'
      || !str_contains($gpu,'4070 TI SUPER')) {
       return ['level'=>'neutral','label'=>'Sin umbral económico validado','floor'=>null,'margin'=>null];
    }
    $floor=125.0;
    return [
      'level'=>$hash<=125?'red':($hash<140?'yellow':'green'),
      'label'=>$hash<=125?'Bajo referencia':($hash<140?'Vigilar rendimiento':'Sobre referencia'),
      'floor'=>$floor,
      'margin'=>round($hash-$floor,2)
    ];
}
/** SVG shape derived from genuinely attributed, chronologically ordered rows. */
function miner_monitor_sparkline(array $values): ?string {
    $values=array_values(array_filter($values,static fn($v)=>is_numeric($v)&&is_finite((float)$v)&&(float)$v>=0));
    if(count($values)<2)return null;
    $min=min($values);$max=max($values);$span=max(4.0,$max-$min);
    $bottom=$min-($span-($max-$min))/2;
    $points=[];$last=count($values)-1;
    foreach($values as $i=>$value) {
        $x=6+348*$i/$last;
        $y=80-68*(((float)$value-$bottom)/$span);
        $points[]=number_format($x,1,'.','').','.number_format(max(4,min(86,$y)),1,'.','');
    }
    return implode(' ',$points);
}

/**
 * Total de última tasa verificada de cada réplica, sin proyecciones.
 * Usa exactamente las lecturas individuales que reciben las tarjetas;
 * nunca suma grupos históricos, pendientes ni datos de >10 minutos.
 * Múltiples snapshots del mismo ID se cuentan una sola vez.
 * El agregado de TH/s no equivale a ingresos ni garantiza misma moneda.
 */
function miner_monitor_live_hashrate(array $groups,array $targets,int $now): array {
    $enabled=[];
    foreach($targets as $t) {
        if((int)($t['enabled']??0)!==1)continue;
        $enabled[(string)$t['organization_slug']."\0".(string)$t['project_slug']]=true;
    }
    $instances=[];
    foreach($groups as $group) {
        $org=(string)($group['organization']??'');
        $project=(string)($group['project_name']??'');
        if(empty($enabled[$org."\0".$project])
           || empty($group['recent'])
           || strtolower((string)($group['state']??''))!=='running')continue;
        foreach($group['instances']??[] as $node) {
            if(!is_array($node))continue;
            $id=(string)($node['id']??'');
            if($id==='' || empty($node['ready'])
              || strtolower((string)($node['state']??''))!=='running')continue;
            $resource=$org."\0".$project."\0".(string)($group['id']??$group['group_name']??'')."\0".$id;
            if(!isset($instances[$resource]))$instances[$resource]=['org'=>$org,'metric'=>null,'ts'=>null];
            $metric=$node['metric']??null;
            if(!is_array($metric))continue;
            $hash=$metric['hashrate_ths']??null;
            $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)($metric['at']??''),new DateTimeZone('UTC'));
            if(!is_numeric($hash) || !is_finite((float)$hash) || (float)$hash<0
                || (float)$hash>20000 || $date===false)continue;
            $ts=$date->getTimestamp();
            if($ts>$now+120 || $now-$ts>600)continue;
            if($instances[$resource]['ts']!==null && $instances[$resource]['ts']>=$ts)continue;
            $instances[$resource]['metric']=(float)$hash;
            $instances[$resource]['ts']=$ts;
        }
    }
    $total=0.0;$ready=0;$measured=0;$latest=null;$earliest=null;$orgs=[];
    foreach($instances as $item) {
        $org=$item['org'];
        if(!isset($orgs[$org]))$orgs[$org]=['ready'=>0,'measured'=>0,'ths'=>0.0];
        $ready++;$orgs[$org]['ready']++;
        if($item['metric']===null)continue;
        $measured++;$total+=$item['metric'];
        $orgs[$org]['measured']++;$orgs[$org]['ths']+=$item['metric'];
        $latest=$latest===null? $item['ts']:max($latest,$item['ts']);
        $earliest=$earliest===null? $item['ts']:min($earliest,$item['ts']);
    }
    foreach($orgs as &$v)$v['ths']=$v['measured']?round($v['ths'],2):null;
    unset($v);
    ksort($orgs);
    return [
      'ths'=>$measured?round($total,2):null,
      'ready'=>$ready,'measured'=>$measured,'missing'=>$ready-$measured,
      'complete'=>$ready>0 && $ready===$measured,
      'last_at'=>$latest===null?null:gmdate('Y-m-d H:i:s',$latest),
      'oldest_at'=>$earliest===null?null:gmdate('Y-m-d H:i:s',$earliest),
      'organizations'=>$orgs
    ];
}

/**
 * Vista dinámica de todas las agrupaciones registradas por las APIs de los
 * proyectos habilitados y con lecturas de Salad dentro de las últimas ocho horas.
 * Las instancias vistas en 15 min se listan individualmente, también si están
 * allocating o pendientes. No se usan para atribuir ingresos ni hashrate.
 */
function miner_monitor_inventory(): array {
    $db=miner_db();
    $targets=miner_salad_targets(false);
    $groups=$db->query('SELECT id,organization,project_name,group_name,state,priority,desired_replicas,gpu_class,last_seen_at FROM group_state WHERE last_seen_at >= UTC_TIMESTAMP()-INTERVAL 8 HOUR ORDER BY organization,project_name,group_name')->fetchAll(PDO::FETCH_ASSOC);
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
    $logs=$db->query("SELECT group_id,logged_at,severity FROM log_events
         WHERE logged_at>=UTC_TIMESTAMP()-INTERVAL 15 MINUTE AND severity='warning'
         ORDER BY logged_at DESC LIMIT 1500")->fetchAll(PDO::FETCH_ASSOC);
    $warningCount=[];
    foreach($logs as $log)$warningCount[(int)$log['group_id']]=($warningCount[(int)$log['group_id']]??0)+1;
    // Recent explicit pearlhash evidence prevents treating QTC / Quantus hash as PRL.
    // Unverified names remain neutral; actual measured algorithm takes priority.
    $algoGroups=$db->query("SELECT DISTINCT group_id FROM log_events
      WHERE logged_at>=UTC_TIMESTAMP()-INTERVAL 30 MINUTE
      AND summary LIKE '%[pearlhash]%'")->fetchAll(PDO::FETCH_COLUMN);
    $pearlhashGroupIds=array_fill_keys(array_map('intval',$algoGroups),true);
    // Historical samples are per (group,instance), not raw group logs.
    $samples=$db->query("SELECT group_id,instance_id,observed_at,hashrate_ths,gpu_model,watts
         FROM miner_observations
         WHERE observed_at>=UTC_TIMESTAMP()-INTERVAL 3 HOUR
           AND hashrate_ths IS NOT NULL
         ORDER BY observed_at DESC,id DESC LIMIT 6000")->fetchAll(PDO::FETCH_ASSOC);
    $series=[];
    foreach($samples as $row){
        $key=(int)$row['group_id']."\0".$row['instance_id'];
        if(count($series[$key]??[])>=24)continue;
        $series[$key][]=$row;
    }
    $now=time();
    $stats=['groups'=>count($groups),'current_groups'=>0,'desired'=>0,'observed'=>0,'ready'=>0,'waiting'=>0,'historical'=>0,'filtered_age_hours'=>8];
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
        $group['pearlhash_verified']=$recent && isset($pearlhashGroupIds[$id]);
        $total=0.0;$measured=0;$signals=[];
        foreach($group['instances'] as &$node){
            $key=$id."\0".$node['id'];
            $rows=array_reverse($series[$key]??[]);
            $node['history']=array_map(static fn($r)=>(float)$r['hashrate_ths'],$rows);
            $node['chart']=miner_monitor_sparkline($node['history']);
            $latest=$rows===[]?null:$rows[count($rows)-1];
            $observedTime=$latest===null?false:DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)$latest['observed_at'],new DateTimeZone('UTC'));
            $metricFresh=$recent && $node['ready'] && $observedTime!==false
                && $now-$observedTime->getTimestamp()<=600
                && $now-$observedTime->getTimestamp()>=-120;
            $node['metric']=$metricFresh?[
                'hashrate_ths'=>(float)$latest['hashrate_ths'],
                'gpu_model'=>(string)($latest['gpu_model']??''),
                'watts'=>$latest['watts']===null?null:(float)$latest['watts'],
                'at'=>$latest['observed_at']
            ]:null;
            $node['signal']=miner_monitor_profit_signal((string)$group['group_name'],(string)$group['priority'],$node['metric'],$group['pearlhash_verified']);
            $node['avg_recent']=null;
            if(count($rows)>=2){
                $recentRows=array_slice($rows,-3);
                $node['avg_recent']=round(array_sum(array_column($recentRows,'hashrate_ths'))/count($recentRows),2);
            }
            if($node['metric']!==null){$measured++;$total+=(float)$node['metric']['hashrate_ths'];}
            $signals[]=$node['signal']['level'];
        }
        unset($node);
        $group['total_ths']=$recent&&$observed>0&&$ready===$observed&&$measured===$observed
            ?round($total,2):null;
        $group['signal']=in_array('red',$signals,true)?'red'
            :(in_array('yellow',$signals,true)?'yellow'
              :(count($signals)>0 && count(array_filter($signals,static fn($s)=>$s==='green'))===count($signals)?'green':'neutral'));
        $group['classification']=miner_monitor_status((string)$group['state'],$recent,$desired,$observed,$ready);
        if($recent) {
            $stats['current_groups']++;
            $stats['desired']+=$desired;$stats['observed']+=$observed;$stats['ready']+=$ready;
            if ($ready<$desired) $stats['waiting']+=$desired-$ready;
        } else $stats['historical']++;
    }
    unset($group);
    return ['targets'=>$targets,'groups'=>$groups,'stats'=>$stats,
        'live_hashrate'=>miner_monitor_live_hashrate($groups,$targets,$now)];
}
