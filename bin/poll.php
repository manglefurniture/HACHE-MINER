<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/core.php';
require_once dirname(__DIR__).'/app/kryptex.php';
require_once dirname(__DIR__).'/app/monitor.php';
require_once dirname(__DIR__).'/app/shares.php';
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
function miner_log_save(int $groupId,array $items,array $nodes): void {
    $st=miner_db()->prepare('INSERT IGNORE INTO log_events(event_hash,group_id,logged_at,severity,summary) VALUES (?,?,?,?,?)');
    foreach($items as $item){
        if(!is_array($item))continue;
        $raw=(string)($item['text_log']??$item['message']??'');
        $time=(string)($item['time']??$item['timestamp']??'');
        $ts=strtotime($time);
        if($ts===false||$ts>time()+120||$ts<time()-86400||$raw==='')continue;
        $line=miner_monitor_tagged_log_summary($item,$nodes);
        if($line==='')continue;
        $severity=preg_match('/error|reject|fail|invalid|exit 64|disconnect/i',$line)?'warning':'info';
        $hash=hash('sha256',$groupId.'|'.$time.'|'.$raw);
        $st->execute([$hash,$groupId,gmdate('Y-m-d H:i:s',$ts),$severity,$line]);
    }
}
function miner_instance_save(int $groupId,array $instance,string $now,?string $rate,?array $metric=null,?array $shares=null): void {
    $instanceId=(string)($instance['instance_id']??$instance['id']??'');
    if(!preg_match('/^[a-zA-Z0-9_-]{1,120}$/D',$instanceId))return;
    $state=miner_instance_state($instance);
    $ready=($instance['ready']??null)===true;$started=($instance['started']??null)===true;
    $db=miner_db();
    $prev=$db->prepare('SELECT observed_at,ready,started FROM miner_observations WHERE group_id=? AND instance_id=? ORDER BY observed_at DESC LIMIT 1');
    $prev->execute([$groupId,$instanceId]);$old=$prev->fetch();
    $cost=null;
    if($old && $ready && $started && (int)$old['ready']===1 && (int)$old['started']===1 && $rate!==null) {
        $elapsed=strtotime($now)-strtotime($old['observed_at']);
        if($elapsed>0 && $elapsed<=420) $cost=number_format(((float)$rate)*$elapsed/3600,8,'.','');
    }
    // Group log hashrate must NOT be attributed to an individual replica without an instance ID.
    $safeMetric=$ready && $started && $state==='running' ? $metric:null;
    // A repeated log returned by the seven-minute Salad window is NOT a new
    // counter observation. Its original time must advance past the last poll.
    $shareTs=isset($shares['log_at'])?strtotime((string)$shares['log_at']):false;
    $prevTs=$old?strtotime((string)($old['observed_at']??'')):false;
    $shareFresh=$shareTs!==false && ($prevTs===false || $shareTs>$prevTs);
    $safeShares=$ready && $started && $state==='running' && $shareFresh ? $shares:null;
    $st=$db->prepare('INSERT IGNORE INTO miner_observations (group_id,instance_id,observed_at,state,ready,started,hashrate_ths,gpu_model,watts,accepted_shares,estimated_cost_usd) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([
        $groupId,$instanceId,$now,$state,(int)$ready,(int)$started,
        $safeMetric['hashrate_ths']??null,$safeMetric['gpu']??null,$safeMetric['watts']??null,
        $safeShares['accepted']??null,$cost
    ]);
}
/**
 * Salad's full successful group list is authoritative. Remove an old "running"
 * claim when a group disappeared, retaining all SQL history and rate settings.
 * Called ONLY after a complete, validated group listing; never on API error.
 */
function miner_poll_mark_unlisted_groups(string $org,string $project,array $listedNames): void {
    $db=miner_db();
    $rows=$db->prepare("SELECT id,group_name FROM group_state WHERE organization=? AND project_name=? AND state<>'not_listed' AND last_seen_at>=UTC_TIMESTAMP()-INTERVAL 15 MINUTE");
    $rows->execute([$org,$project]);
    $mark=$db->prepare("UPDATE group_state SET state='not_listed',last_seen_at=UTC_TIMESTAMP() WHERE id=?");
    foreach($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if(!isset($listedNames[(string)$row['group_name']]))$mark->execute([(int)$row['id']]);
    }
}

function miner_poll_salad(string $org,string $project,string $key): void {
    $base='https://api.salad.com/api/public/organizations/'.rawurlencode($org).'/projects/'.rawurlencode($project).'/containers';
    $headers=['Salad-Api-Key: '.$key,'Accept: application/json'];
    $groups=miner_http_json($base,$headers);
    $items=$groups['items']??$groups['container_groups']??null;
    if(!is_array($items))throw new RuntimeException('No se pudo interpretar el listado.');
    if(isset($groups['next_cursor']) && $groups['next_cursor'])throw new RuntimeException('Paginación pendiente: no se registra cobertura parcial como completa.');
    $recorded=0;
    $failed=0;$validList=true;$listedNames=[];
    foreach($items as $group){
        if(!is_array($group)){$validList=false;continue;}
        $groupName=(string)($group['name']??'');
        if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,119}$/D',$groupName)){
            $validList=false;continue;
        }
        $listedNames[$groupName]=true;
        $g=miner_group_upsert($org,$project,$group);
        if(!$g){$validList=false;continue;}
        $recorded++;
        try {
            try {
                $instances=miner_http_json($base.'/'.rawurlencode($g['name']).'/instances',$headers);
                $nodes=$instances['instances']??$instances['items']??null;
                if(!is_array($nodes))throw new RuntimeException('Sin lista de réplicas.');
                if(!empty($instances['next_cursor']))
                    throw new RuntimeException('Paginación de instancias pendiente.');
                // Use the SAME identifier validation as miner_instance_save().
                // A malformed/partial response cannot prove a live GPU vanished.
                $snapshotIds=[];
                foreach($nodes as $node){
                    if(!is_array($node))
                        throw new RuntimeException('Instancias Salad incompletas.');
                    $id=(string)($node['instance_id']??$node['id']??'');
                    if(!preg_match('/^[a-zA-Z0-9_-]{1,120}$/D',$id)
                        ||isset($snapshotIds[$id]))
                        throw new RuntimeException('Identificadores de instancia inválidos.');
                    $snapshotIds[$id]=true;
                }
            } catch(Throwable $e) {
                // Fail closed: a group status alone is not proof of active GPUs.
                miner_db()->prepare("UPDATE group_state SET state='unverified' WHERE id=?")
                    ->execute([$g['id']]);
                throw $e;
            }
            // A single timestamp identifies THIS exact, successfully received
            // snapshot. Historical replica rows are never treated as current.
            $snapshotAt=gmdate('Y-m-d H:i:s');
            $rate=null;
            if($g['gpu_class']!==null) {
                $st=miner_db()->prepare('SELECT usd_per_hour FROM gpu_rates WHERE organization=? AND gpu_class=? AND priority=?');
                $st->execute([$org,$g['gpu_class'],$g['priority']]);
                $rate=$st->fetchColumn();if($rate===false)$rate=null;
            }
            // The log response may identify multiple different instances.
            // Only attribute a hashrate to a matching instance or a provably
            // unique running node; never divide a group hashrate across replicas.
            $logs=[];$logsError=null;
            try {
                $logs=miner_logs($org,$project,$g['name'],$key);
                miner_log_save($g['id'],$logs,$nodes);
            } catch(Throwable $e) { $logsError=$e; }
            $metrics=miner_monitor_instance_log_metrics($logs,$nodes);
            $shareCounters=miner_share_instance_counters($logs,$nodes);
            foreach($nodes as $node) {
                if(!is_array($node))continue;
                $id=(string)($node['instance_id']??$node['id']??'');
                miner_instance_save($g['id'],$node,$snapshotAt,$rate,$metrics[$id]??null,$shareCounters[$id]??null);
            }
            // Update the group snapshot marker also when Salad returns zero
            // instances; this immediately makes older replica cards obsolete.
            miner_db()->prepare('UPDATE group_state SET last_seen_at=? WHERE id=?')
                ->execute([$snapshotAt,$g['id']]);
            if($logsError!==null)throw $logsError;
        } catch(Throwable $e) {
            $failed++;
            miner_run_record('salad:'.$org.'/'.$project.'/'.$g['name'],'partial',get_class($e).' during collection');
        }
    }
    if($validList) {
        miner_poll_mark_unlisted_groups($org,$project,$listedNames);
    } else {
        $failed++;
        miner_run_record('salad:'.$org.'/'.$project,'partial',
            'Malformed group list: missing-group reconciliation skipped');
    }
    miner_run_record('salad:'.$org.'/'.$project,$failed>0?'partial':'ok',
      'group snapshots: '.$recorded.'; partial groups: '.$failed.'; current instance snapshots only');
}
function miner_poll_kryptex(): void {
    $wallets=miner_db()->query("SELECT id,address FROM wallets WHERE coin='PRL' ORDER BY id LIMIT 101")->fetchAll();
    if (!$wallets) {
        miner_run_record('kryptex:prl','missing','No public PRL wallets configured');return;
    }
    if (count($wallets)>100) {
        miner_run_record('kryptex:prl','partial','More than 100 configured wallet records; limit protects collector resources');
        $wallets=array_slice($wallets,0,100);
    }
    // A shared wallet is queried once even when labelled in many organizations.
    // Rotate at most TWO distinct public wallets per 5-minute cycle to protect
    // the 150-second Salad collector budget from slow external pool endpoints.
    $distinct=array_values(array_unique(array_column($wallets,'address')));
    $allowed=[];
    for($i=0;$i<min(2,count($distinct));$i++) {
        $start=(int)floor(time()/300)%max(1,count($distinct));
        $allowed[$distinct[($start+$i)%count($distinct)]]=true;
    }
    $cache=[];
    foreach ($wallets as $w) {
        $address=(string)$w['address'];
        if (!isset($allowed[$address])) {
            miner_run_record('kryptex:wallet:'.$w['id'],'partial','Wallet collection deferred to next cycle (bounded requests)');
            continue;
        }
        if (!isset($cache[$address])) {
            try {
                if (!preg_match('/^prl1[a-z0-9]{30,150}$/D',$address)) {
                    throw new InvalidArgumentException('Invalid public PRL address');
                }
                $url='https://pool.kryptex.com/prl/api/v1/miner/balance/'.rawurlencode($address);
                $balance=miner_kryptex_balance(miner_http_json($url,[],6));
                $status='ok';$note='Kryptex PRL public API: pending/confirmed verified; worker 30m H/s wallet-wide';
                try {
                    $workers=miner_kryptex_workers(miner_http_json(
                        'https://pool.kryptex.com/prl/api/v3/miner/workers/'.rawurlencode($address),[],6));
                    if ($workers['partial']) {
                        $status='partial';$note='Kryptex balance verified; incomplete pool hashrate';
                    }
                } catch(Throwable $e) {
                    $workers=['hashrate_raw'=>null,'worker_count'=>null];
                    $status='partial';
                    $note='Kryptex balance verified; workers unavailable ('.get_class($e).')';
                }
                $cache[$address]=['balance'=>$balance,'workers'=>$workers,'status'=>$status,'note'=>$note];
            } catch(Throwable $e) {
                $cache[$address]=['error'=>get_class($e)];
            }
        }
        $result=$cache[$address];
        $source='kryptex:wallet:'.$w['id'];
        if (isset($result['error'])) {
            miner_run_record($source,'error','Kryptex balance unavailable ('.$result['error'].')');
            continue;
        }
        $st=miner_db()->prepare('INSERT IGNORE INTO pool_observations(wallet_id,observed_at,pending_prl,confirmed_prl,hashrate_raw,worker_count,coverage_note) VALUES (?,UTC_TIMESTAMP(),?,?,?,?,?)');
        $st->execute([
            $w['id'],
            $result['balance']['pending_prl'],$result['balance']['confirmed_prl'],
            $result['workers']['hashrate_raw'],$result['workers']['worker_count'],
            $result['note']
        ]);
        miner_run_record($source,$result['status'],$result['note']);
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
