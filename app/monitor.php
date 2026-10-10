<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';

/** Separar estados de asignación de evidencia de minería. */
function miner_monitor_status(string $groupState, bool $recent, int $desired, int $observed, int $ready): string {
    if (!$recent) return 'sin_lectura';
    $state=strtolower($groupState);
    if ($state==='not_listed') return 'no_figura_en_salad';
    if ($state==='unverified') return 'sin_confirmacion';
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
        'no_figura_en_salad'=>'Ya no aparece en Salad',
        'sin_confirmacion'=>'Instancias sin confirmar',
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
    $clean=miner_scrub_log($line);
    // Parsing is GPU-model agnostic. Unlike financial thresholds, telemetry
    // must recognize RTX 4070 Laptop, 3080 Ti, 5090 Laptop and other models.
    // Never interpret accepted-share, pool total or 15-min mean as GPU0 speed.
    $match=null;
    $strict='/#\d+\s+(?<gpu>.+?)\s+(?<hash>\d+(?:\.\d+)?)\s+TH\/s\s+(?<power>\d+(?:\.\d+)?)W\s+(?<eff>\d+(?:\.\d+)?)\s+(?<fan>\d+)%\s+(?<temp>\d+)C/i';
    // Genuine SRBMiner table rows can start with "#0 RTX 4070 Laptop Gpu"
    // (no "GPU0" prefix) and may omit watts/fan/temperature. Anchor to the
    // beginning of the log after its timestamp: do not match pool/share text.
    $table='/^(?:\[[^\]\r\n]{1,90}\]\s*)?(?:GPU\s*#?\d{1,2}\s+)?#\d{1,2}\s+'
          .'(?<gpu>[^\r\n]{3,120}?)\s+(?<hash>\d+(?:\.\d+)?)\s+TH\/s\b/i';
    if(preg_match($strict,$clean,$m)) {
        $match=$m;
    } elseif(preg_match($table,$clean,$m)) {
        $match=$m;
    } else {
        // Several SRBMiner builds and GPU drivers omit watts/fan/temperature
        // or write "GPU [0]" / "GPU0:". Require explicit GPU device index
        // AND TH/s on that SAME line, never a standalone pool/worker total.
        $pattern='/\bGPU\s*(?:#?\d+|\[\d+\])\s*(?:#\d+)?\s*[:\-|]?\s*'
               .'(?<gpu>[^\\r\\n]{3,130}?)\s*[:\-|]?\s*'
               .'(?<hash>\d+(?:\.\d+)?)\s*TH\/s\b/i';
        if(preg_match($pattern,$clean,$m))$match=$m;
    }
    if($match===null)return null;
    $gpu=trim((string)$match['gpu']," \t:-|[]");
    if($gpu===''||strlen($gpu)>120 || !preg_match('/[a-zA-Z]/',$gpu)
        || preg_match('/\b(?:share|shares|accepted|rejected|pool|total|average|hashrate|worker|speed|reported|overall|min|mins|minute|minutes|avg)\b/i',$gpu))return null;
    $hash=(float)$match['hash'];
    if(!is_finite($hash)||$hash<0||$hash>20000)return null;
    // Power, temperature and fan are optional metadata, never prerequisites
    // for recognizing an actual per-GPU hashrate.
    $suffix=substr($clean,(int)(strpos($clean,$match[0])?:0)+strlen($match[0]),160);
    $watts=null;$temp=null;$fan=null;
    if(isset($match['power']) && $match['power']!=='')$watts=(float)$match['power'];
    elseif(preg_match('/\b(\d+(?:\.\d+)?)\s*W\b/i',$suffix,$p))$watts=(float)$p[1];
    if(isset($match['fan']) && $match['fan']!=='')$fan=(int)$match['fan'];
    elseif(preg_match('/\b(?:fan\s*[:=]?\s*)?(\d{1,3})\s*%/i',$suffix,$mFan))$fan=(int)$mFan[1];
    if(isset($match['temp']) && $match['temp']!=='')$temp=(int)$match['temp'];
    elseif(preg_match('/\b(\d{1,3})\s*°?C\b/i',$suffix,$mTemp))$temp=(int)$mTemp[1];
    if(($watts!==null && ($watts<0||$watts>1500))
       ||($fan!==null && ($fan<0||$fan>100))
       ||($temp!==null && ($temp<0||$temp>150)))return null;
    return ['gpu'=>$gpu,'hashrate_ths'=>$hash,'watts'=>$watts,'temp_c'=>$temp,'fan'=>$fan];
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
    // Salad SDK wraps attribution in resource.labels, but deployments may
    // expose the same labels at top level or use a camelCase field.
    $sources=[$item];
    foreach([$item['labels']??null,$item['resource']['labels']??null,
             $item['resource']??null] as $container)
        if(is_array($container))$sources[]=$container;
    $idCandidates=[];$machineCandidates=[];
    $idKeys=['instance_id','container_group_instance_id','container_instance_id',
             'container_instance','instanceId','containerGroupInstanceId'];
    $machineKeys=['machine_id','container_group_machine_id','machineId'];
    foreach($sources as $source) {
        foreach($idKeys as $key) {
            $value=$source[$key]??null;
            if(is_string($value)&&$value!=='')$idCandidates[$value]=true;
        }
        foreach($machineKeys as $key) {
            $value=$source[$key]??null;
            if(is_string($value)&&$value!=='')$machineCandidates[$value]=true;
        }
    }
    $knownById=[];$knownByMachine=[];
    foreach($nodes as $node) {
        if(!is_array($node))continue;
        $id=(string)($node['instance_id']??$node['id']??'');
        $machine=(string)($node['machine_id']??'');
        if($id!=='')$knownById[$id]=$id;
        if($machine!==''){
            if(!isset($knownByMachine[$machine]))$knownByMachine[$machine]=$id;
            elseif($knownByMachine[$machine]!==$id)$knownByMachine[$machine]='';
        }
    }
    // A full UUID explicitly printed in the miner's worker log is equally
    // strong evidence as a resource label; truncated IDs are NOT sufficient.
    $raw=(string)($item['text_log']??$item['message']??'');
    foreach($knownById as $id)if(preg_match('/(?<![a-z0-9_-])'.preg_quote($id,'/').'(?![a-z0-9_-])/i',$raw))
        $idCandidates[$id]=true;
    foreach($knownByMachine as $machine=>$id)
        if($id!=='' && preg_match('/(?<![a-z0-9_-])'.preg_quote($machine,'/').'(?![a-z0-9_-])/i',$raw))
            $machineCandidates[$machine]=true;
    $matched=[];
    foreach(array_keys($idCandidates) as $id) {
        if(!isset($knownById[$id]))return null;
        $matched[$knownById[$id]]=true;
    }
    foreach(array_keys($machineCandidates) as $machine) {
        if(empty($knownByMachine[$machine]))return null;
        $matched[$knownByMachine[$machine]]=true;
    }
    if(count($matched)===1)return (string)array_key_first($matched);
    if($idCandidates!==[] || $machineCandidates!==[])return null;
    if(count($nodes)!==1 || !is_array($nodes[0]))return null;
    $node=$nodes[0];
    $id=(string)($node['instance_id']??$node['id']??'');
    if($id==='' || !miner_instance_ready($node))return null;
    $changed=strtotime((string)($node['update_time']??''));
    $logged=strtotime((string)($item['time']??$item['timestamp']??''));
    if($changed===false || $logged===false || $logged < $changed-30)return null;
    return $id;
}
/**
 * An unmatched identity is not anonymous: it may belong to a replaced node,
 * conflict with the current API inventory or be missing from this page.
 */
function miner_monitor_log_has_identity_hint(array $item): bool {
    $sources=[$item,$item['resource']??null,$item['resource']['labels']??null,$item['labels']??null];
    $keys=['instance_id','container_group_instance_id','container_instance_id',
        'container_instance','instanceId','containerGroupInstanceId',
        'machine_id','container_group_machine_id','machineId'];
    foreach($sources as $source){
        if(!is_array($source))continue;
        foreach($keys as $key)
            if(is_scalar($source[$key]??null) && trim((string)$source[$key])!=='')
                return true;
    }
    // A full UUID embedded in log content could be a worker identity. When
    // unsure, fail closed as unknown rather than claim provenance was absent.
    $raw=(string)($item['text_log']??$item['message']??'');
    return preg_match('/\\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\\b/i',$raw)===1;
}

/**
 * Persist *provenance class*, never worker IDs, alongside a scrubbed GPU log.
 * Older untagged rows remain unknown and must NOT be called unattributed.
 * This keeps existing schema and release rollback compatible.
 */
function miner_monitor_tagged_log_summary(array $item,array $nodes): string {
    $raw=(string)($item['text_log']??$item['message']??'');
    $safe=miner_scrub_log($raw);
    // A worker could print a tag; discard any text resembling our marker.
    $safe=preg_replace('/\\s*\\[MONITOR_GPU_(?:ATTRIBUTED|UNATTRIBUTED|UNKNOWN)\\]/i','',$safe)??'';
    if(miner_monitor_log_metric($raw)===null)return $safe;
    $matched=miner_monitor_log_instance($item,$nodes);
    // UNKNOWN covers explicit but mismatched/conflicting labels or worker IDs.
    // Only the absence of identity hints is genuinely unattributed.
    $suffix=$matched!==null?' [MONITOR_GPU_ATTRIBUTED]'
        :(miner_monitor_log_has_identity_hint($item)
            ?' [MONITOR_GPU_UNKNOWN]':' [MONITOR_GPU_UNATTRIBUTED]');
    return mb_substr($safe,0,800-strlen($suffix)).$suffix;
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
 * GPU cards are a production-only view, never an inventory or billing source.
 * Preserve Salad/group/instance order; do not sort by hashrate.
 * Only show a currently running, ready instance with a recent positive
 * individual TH/s reading. Zero or missing readings stay in diagnostics.
 */
function miner_monitor_producing_nodes(array $group, ?int $now=null): array {
    if(empty($group['recent']) || strtolower((string)($group['state']??''))!=='running')return [];
    $now=$now??time();
    $nodes=[];
    foreach($group['instances']??[] as $node) {
        if(!is_array($node) || empty($node['ready'])
           || strtolower((string)($node['state']??''))!=='running')continue;
        $metric=$node['metric']??null;
        if(!is_array($metric))continue;
        $hash=$metric['hashrate_ths']??null;
        if(!is_numeric($hash) || !is_finite((float)$hash)
            || (float)$hash<=0 || (float)$hash>20000)continue;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',
            (string)($metric['at']??''),new DateTimeZone('UTC'));
        if($date===false)continue;
        $age=$now-$date->getTimestamp();
        if($age < -120 || $age > 600)continue;
        $nodes[]=$node;
    }
    return $nodes;
}

/**
 * Current RUNNING/ready GPUs x explicitly configured Salad class/priority
 * tariffs. Independent of TH/s and never a claim of official Salad billing.
 * Stale nodes, disabled projects, allocations and missing rates do not become
 * zero-dollar observations. No machine model-to-price guessing.
 */
function miner_monitor_live_hourly_cost(array $groups,array $targets,array $rates,?int $now=null):array {
    $now=$now??time();
    $enabled=[];
    foreach($targets as $t)if((int)($t['enabled']??0)===1)
        $enabled[(string)$t['organization_slug']."\\0".(string)$t['project_slug']]=true;
    $prices=[];
    foreach($rates as $r){
        $org=(string)($r['organization']??'');
        $gpu=(string)($r['gpu_class']??'');
        $priority=strtolower((string)($r['priority']??''));
        $p=$r['usd_per_hour']??null;
        if($org===''||$gpu===''||$priority===''||!is_numeric($p))continue;
        $v=(float)$p;
        if(!is_finite($v)||$v<0||$v>100)continue;
        $prices[$org."\\0".$gpu."\\0".$priority]=$v;
    }
    $seen=[];$orgs=[];$details=[];$missingDetails=[];
    $priced=0;$unpriced=0;$usd=0.0;
    foreach($groups as $g){
        $org=(string)($g['organization']??'');$project=(string)($g['project_name']??'');
        if(empty($enabled[$org."\\0".$project])||empty($g['recent'])
            ||strtolower((string)($g['state']??''))!=='running')continue;
        $class=(string)($g['gpu_class']??'');
        $priority=strtolower((string)($g['priority']??''));
        $rate=$prices[$org."\\0".$class."\\0".$priority]??null;
        foreach($g['instances']??[] as $n){
            if(!is_array($n)||empty($n['ready'])||strtolower((string)($n['state']??''))!=='running')continue;
            $id=(string)($n['id']??'');
            $at=strtotime((string)($n['observed_at']??''));
            if($id===''||$at===false||$at>$now+120||$now-$at>600)continue;
            $key=$org."\\0".$project."\\0".(string)($g['id']??$g['group_name']??'')."\\0".$id;
            if(isset($seen[$key]))continue;
            $seen[$key]=true;
            if(!isset($orgs[$org]))$orgs[$org]=['usd_per_hour'=>0.0,'priced'=>0,'unpriced'=>0];
            if($rate===null){
                $unpriced++;$orgs[$org]['unpriced']++;
                $unknownKey=$org."\\0".$class."\\0".$priority;
                if(!isset($missingDetails[$unknownKey]))$missingDetails[$unknownKey]=[
                    'organization'=>$org,'gpu_class'=>$class,'priority'=>$priority,'count'=>0
                ];
                $missingDetails[$unknownKey]['count']++;
                continue;
            }
            $priced++;$orgs[$org]['priced']++;$usd+=$rate;$orgs[$org]['usd_per_hour']+=$rate;
            $type=$org."\\0".$class."\\0".$priority;
            if(!isset($details[$type]))$details[$type]=[
                'organization'=>$org,'gpu_class'=>$class,'priority'=>$priority,
                'count'=>0,'unit_usd_per_hour'=>$rate,'subtotal_usd_per_hour'=>0.0
            ];
            $details[$type]['count']++;
            $details[$type]['subtotal_usd_per_hour']+=$rate;
        }
    }
    foreach($orgs as &$v){
        $v['usd_per_hour']=$v['priced']?round($v['usd_per_hour'],6):null;
    }
    unset($v);
    ksort($orgs);ksort($details);ksort($missingDetails);
    foreach($details as &$d)$d['subtotal_usd_per_hour']=round($d['subtotal_usd_per_hour'],6);
    unset($d);
    return [
        'usd_per_hour'=>$priced?round($usd,6):null,
        'priced'=>$priced,'unpriced'=>$unpriced,'total'=>$priced+$unpriced,
        'complete'=>$priced+$unpriced>0&&$unpriced===0,
        'organizations'=>$orgs,'details'=>array_values($details),
        'missing_details'=>array_values($missingDetails)
    ];
}

/**
 * Group records are historical; the authoritative instance list is ONLY the
 * last successful Salad instances snapshot. Previously observed replica IDs
 * must never inherit a newer group "running" state after they disappear.
 * Compare exact UTC sample times: both sides are written together by poll.php.
 */
function miner_monitor_current_snapshot_nodes(array $rows,string $snapshotAt): array {
    if($snapshotAt==='')return [];
    return array_values(array_filter($rows,static fn($node):bool=>
        is_array($node) && ($node['observed_at']??null)===$snapshotAt));
}

/**
 * Vista dinámica de todas las agrupaciones registradas por las APIs de los
 * proyectos habilitados y con lecturas de Salad dentro de las últimas ocho horas.
 * Solo el snapshot de instancias ligado al último sondeo exitoso del grupo
 * se muestra como actual. Los IDs de snapshots anteriores quedan en histórico,
 * incluso si el grupo sigue running. No se infieren hashrates ni costos.
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
    // Show only logs explicitly tagged as unassigned at collection time.
    // Untagged historical rows have unknown provenance, NOT proof of anonymity.
    // Never use this group-only diagnostic in individual or global TH/s sums.
    $unassignedLogRows=$db->query("SELECT group_id,logged_at,summary FROM log_events
        WHERE logged_at>=UTC_TIMESTAMP()-INTERVAL 15 MINUTE
          AND summary LIKE '%[MONITOR_GPU_UNATTRIBUTED]'
        ORDER BY logged_at DESC,event_hash DESC LIMIT 1400")->fetchAll(PDO::FETCH_ASSOC);
    $groupLogMetrics=[];
    foreach($unassignedLogRows as $row){
        $groupId=(int)$row['group_id'];
        $metric=miner_monitor_log_metric((string)$row['summary']);
        if($metric===null)continue;
        if(!isset($groupLogMetrics[$groupId])){
            $groupLogMetrics[$groupId]=[
                'gpu'=>$metric['gpu'],'hashrate_ths'=>$metric['hashrate_ths'],
                'logged_at'=>$row['logged_at'],'samples'=>0
            ];
        }
        $groupLogMetrics[$groupId]['samples']++;
    }

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
        $groupState=strtolower((string)$group['state']);
        // Even a fresh group poll cannot revive rows from a previous replica.
        // A failed or missing-group poll has no confirmed active inventory.
        $showable=!in_array($groupState,
            ['not_listed','unverified','stopped','stopping','disabled'],true);
        $instances=$recent && $showable
            ?miner_monitor_current_snapshot_nodes($byGroup[$id]??[],(string)$group['last_seen_at'])
            :[];
        $observed=count($instances);
        $ready=0;
        foreach($instances as $instance) if($instance['ready']) $ready++;
        $desired=(int)$group['desired_replicas'];
        $group['recent']=$recent;
        $group['instances']=$instances;
        $group['observed']=$observed;
        $group['ready']=$ready;
        $group['warnings']=$recent?($warningCount[$id]??0):0;
        $group['group_log_observation']=$recent?($groupLogMetrics[$id]??null):null;
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
        // Removed/unverified groups can remain visible for context, but their
        // old desired replicas are not live GPU requests or confirmed waiting.
        if($recent && $showable) {
            $stats['current_groups']++;
            $stats['desired']+=$desired;$stats['observed']+=$observed;$stats['ready']+=$ready;
            if ($ready<$desired) $stats['waiting']+=$desired-$ready;
        } else $stats['historical']++;
    }
    unset($group);
    // Rates are catalog entries confirmed by the administrator, not invoices.
    $rates=$db->query('SELECT organization,gpu_class,priority,usd_per_hour FROM gpu_rates')->fetchAll(PDO::FETCH_ASSOC);
    return ['targets'=>$targets,'groups'=>$groups,'stats'=>$stats,
        'live_hashrate'=>miner_monitor_live_hashrate($groups,$targets,$now),
        'live_hourly_cost'=>miner_monitor_live_hourly_cost($groups,$targets,$rates,$now)];
}
