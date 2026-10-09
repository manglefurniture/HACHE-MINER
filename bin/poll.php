<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/core.php';
if(PHP_SAPI!=='cli') {http_response_code(404);exit;}
date_default_timezone_set('UTC');
$lockPath=getenv('MINER_POLL_LOCK')?:'/var/lib/hache-miner/poll.lock';
if(!is_dir(dirname($lockPath)) || is_link($lockPath)) {fwrite(STDERR,"Directorio de lock no configurado\n");exit(2);}
$lock=fopen($lockPath,'c');
if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) {echo "POLL_BUSY\n";exit(0);}
function miner_run_record(string $source,string $status,string $detail): void {
    $st=miner_db()->prepare('INSERT INTO sync_runs (source_name,observed_at,status,detail) VALUES (?,UTC_TIMESTAMP(),?,?)');
    $st->execute([$source,$status,substr($detail,0,180)]);
}
function miner_logs(string $org,string $project,string $group,string $key): array {
    $url='https://api.salad.com/api/public/organizations/'.rawurlencode($org).'/log-entries';
    $safe=static fn(string $v):string=>str_replace(['"',"'"],'',$v);
    $end=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $body=[
      'sort_order'=>'desc','start_time'=>$end->sub(new DateInterval('PT7M'))->format('Y-m-d\\TH:i:s\\Z'),
      'end_time'=>$end->format('Y-m-d\\TH:i:s\\Z'),'page_size'=>100,
      'query'=>'resource.type = "container" and resource.labels.project_name = "'.$safe($project).'" and resource.labels.container_group_name = "'.$safe($group).'"'
    ];
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>7,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['Salad-Api-Key: '.$key,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR)]);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if(!is_string($raw)||$status<200||$status>=300||strlen($raw)>1500000)throw new RuntimeException('Logs HTTP '.$status);
    $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    return is_array($data['items']??null)?$data['items']:[];
}
function miner_positive_number(mixed $v): ?string {
    return $v!==null?miner_finite_decimal($v,8):null;
}
function miner_group_upsert(string $org,string $project,array $group): ?array {
    $name=(string)($group['name']??'');
    if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,119}$/D',$name))return null;
    $state=substr((string)($group['current_state']['status']??'unknown'),0,50);
    $priority=strtolower((string)($group['container']['priority']??$group['priority']??''));
    $classes=$group['container']['resources']['gpu_classes']??[];
    $class=is_array($classes)&&count($classes)===1?(string)$classes[0]:null;
    $replicas=max(0,min(10000,(int)($group['replicas']??0)));
    $sql='INSERT INTO group_state (organization,project_name,group_name,state,priority,desired_replicas,gpu_class,last_seen_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),state=VALUES(state),priority=VALUES(priority),desired_replicas=VALUES(desired_replicas),gpu_class=VALUES(gpu_class),last_seen_at=UTC_TIMESTAMP()';
    $st=miner_db()->prepare($sql);
    $st->execute([$org,$project,$name,$state,$priority,$replicas,$class]);
    return ['id'=>(int)miner_db()->lastInsertId(),'name'=>$name,'state'=>$state,'priority'=>$priority,'gpu_class'=>$class,'replicas'=>$replicas];
}
function miner_log_save(int $groupId,array $items): void {
    $st=miner_db()->prepare('INSERT IGNORE INTO log_events(event_hash,group_id,logged_at,severity,summary) VALUES (?,?,?,?,?)');
    foreach($items as $item){
        if(!is_array($item))continue;
        $raw=(string)($item['text_log']??$item['message']??'');
        $time=(string)($item['time']??$item['timestamp']??'');
        $ts=strtotime($time);
        if($ts===false||$ts>time()+120||$ts<time()-86400||$raw==='')continue;
        $line=miner_scrub_log($raw);
        if($line==='')continue;
        $severity=preg_match('/error|reject|fail|invalid|exit 64|disconnect/i',$line)?'warning':'info';
        $hash=hash('sha256',$groupId.'|'.$time.'|'.$raw);
        $st->execute([$hash,$groupId,gmdate('Y-m-d H:i:s',$ts),$severity,$line]);
    }
}
function miner_instance_save(int $groupId,array $instance,string $now,?string $rate): void {
    $instanceId=(string)($instance['id']??'');
    if(!preg_match('/^[a-zA-Z0-9_-]{1,120}$/D',$instanceId))return;
    $state=substr((string)($instance['state']??'unknown'),0,64);
    $ready=!empty($instance['ready']);$started=!empty($instance['started']);
    $db=miner_db();
    $prev=$db->prepare('SELECT observed_at,ready,started FROM miner_observations WHERE group_id=? AND instance_id=? ORDER BY observed_at DESC LIMIT 1');
    $prev->execute([$groupId,$instanceId]);$old=$prev->fetch();
    $cost=null;
    if($old && $ready && $started && (int)$old['ready']===1 && (int)$old['started']===1 && $rate!==null) {
        $elapsed=strtotime($now)-strtotime($old['observed_at']);
        if($elapsed>0 && $elapsed<=420) $cost=number_format(((float)$rate)*$elapsed/3600,8,'.','');
    }
    // Group log hashrate must NOT be attributed to an individual replica without an instance ID.
    $st=$db->prepare('INSERT IGNORE INTO miner_observations (group_id,instance_id,observed_at,state,ready,started,estimated_cost_usd) VALUES (?,?,?,?,?,?,?)');
    $st->execute([$groupId,$instanceId,$now,$state,(int)$ready,(int)$started,$cost]);
}
function miner_poll_salad(string $org,string $project,string $key): void {
    $base='https://api.salad.com/api/public/organizations/'.rawurlencode($org).'/projects/'.rawurlencode($project).'/containers';
    $headers=['Salad-Api-Key: '.$key,'Accept: application/json'];
    $groups=miner_http_json($base,$headers);
    $items=$groups['items']??$groups['container_groups']??null;
    if(!is_array($items))throw new RuntimeException('No se pudo interpretar el listado.');
    if(isset($groups['next_cursor']) && $groups['next_cursor'])throw new RuntimeException('Paginación pendiente: no se registra cobertura parcial como completa.');
    $now=gmdate('Y-m-d H:i:s');
    $recorded=0;
    foreach($items as $group){
        if(!is_array($group))continue;
        $g=miner_group_upsert($org,$project,$group);
        if(!$g)continue;
        $recorded++;
        try {
            $instances=miner_http_json($base.'/'.rawurlencode($g['name']).'/instances',$headers);
            $nodes=$instances['instances']??$instances['items']??[];
            if(!is_array($nodes))throw new RuntimeException('Sin lista de réplicas.');
            $rate=null;
            if($g['gpu_class']!==null) {
                $st=miner_db()->prepare('SELECT usd_per_hour FROM gpu_rates WHERE organization=? AND gpu_class=? AND priority=?');
                $st->execute([$org,$g['gpu_class'],$g['priority']]);
                $rate=$st->fetchColumn();if($rate===false)$rate=null;
            }
            foreach($nodes as $node) if(is_array($node)) miner_instance_save($g['id'],$node,$now,$rate);
            $logs=miner_logs($org,$project,$g['name'],$key);
            miner_log_save($g['id'],$logs);
        } catch(Throwable $e) {
            miner_run_record('salad:'.$org.'/'.$project.'/'.$g['name'],'partial',get_class($e).' during collection');
        }
    }
    miner_run_record('salad:'.$org.'/'.$project,'ok','group snapshots: '.$recorded.'; instance identity maintained');
}
function miner_poll_kryptex(): void {
    $wallets=miner_db()->query("SELECT id,address FROM wallets WHERE coin='PRL' ORDER BY id")->fetchAll();
    if(!$wallets){miner_run_record('kryptex:prl','missing','No public PRL wallets configured');return;}
    foreach($wallets as $w){
        try{
            $data=miner_http_json('https://pool.kryptex.com/prl/api/v1/miner/balance/'.rawurlencode($w['address']));
            // Provider schema must be mapped after inspecting real response; never infer payout from arbitrary numbers.
            $pending=null;$confirmed=null;$note='API reachable; balances not yet mapped/verified';
            $st=miner_db()->prepare('INSERT IGNORE INTO pool_observations(wallet_id,observed_at,pending_prl,confirmed_prl,coverage_note) VALUES (?,UTC_TIMESTAMP(),?,?,?)');
            $st->execute([$w['id'],$pending,$confirmed,$note]);
            miner_run_record('kryptex:wallet:'.$w['id'],'partial',$note);
        }catch(Throwable $e){
            miner_run_record('kryptex:wallet:'.$w['id'],'error','API not available: '.get_class($e));
        }
    }
}
try {
    miner_db();
    $targets=miner_salad_targets();
    $key=miner_shared_salad_api_key();
    if (!$key) {
        miner_run_record('salad:shared','missing','Shared API key not configured');
    } else {
        foreach($targets as $target) {
            $org=(string)$target['organization_slug'];
            $project=(string)$target['project_slug'];
            try {miner_poll_salad($org,$project,$key);}
            catch(Throwable $e){miner_run_record('salad:'.$org.'/'.$project,'error',get_class($e).' during target polling');}
        }
    }
    miner_poll_kryptex();
    echo "POLL_COMPLETE\n";
} catch(Throwable $e) {
    error_log('[hache-miner] poll-failed '.get_class($e));fwrite(STDERR,"POLL_FAILED\n");exit(1);
} finally {flock($lock,LOCK_UN);fclose($lock);}
