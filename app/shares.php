<?php
declare(strict_types=1);
require_once __DIR__.'/monitor.php';

/**
 * Capture ONLY cumulative SRBMiner share summaries, not individual "accepted
 * share" events or pool-side shares. Counters are snapshots, not increments.
 * Missing rejected/HW are unknown, never silently zero.
 */
function miner_share_counters(string $line): ?array
{
    $line=miner_scrub_log($line);
    if (strlen($line)>1200 || !preg_match('/\\bshares\\b/i',$line)) return null;
    if (preg_match('/\\b(?:pool|network|total pool|wallet)\\b/i',$line)) return null;
    $accepted=null;
    if (preg_match('/\\b(?:shares\\s+accepted|accepted\\s+shares)\\s*[:=]?\\s*(\\d{1,10})\\b/i',$line,$m)) {
        $accepted=(int)$m[1];
    } elseif (preg_match('/\\bshares\\s*[:=]\\s*(?:\\[)?\\s*A\\s*[:=]\\s*(\\d{1,10})\\b/i',$line,$m)) {
        $accepted=(int)$m[1];
    }
    if ($accepted===null || $accepted>1000000000) return null;
    $rejected=null;$hw=null;
    if (preg_match('/\\b(?:shares\\s+rejected|rejected\\s+shares|rejected)\\s*[:=]?\\s*(\\d{1,10})\\b/i',$line,$m))$rejected=(int)$m[1];
    if (preg_match('/\\b(?:hw\\s*errors?|hardware\\s*errors?)\\s*[:=]?\\s*(\\d{1,10})\\b/i',$line,$m))$hw=(int)$m[1];
    // A standalone success event is NOT proof of a cumulative counter. The
    // plural 'shares' and numeric counters are mandatory.
    return ['accepted'=>$accepted,'rejected'=>$rejected,'hardware_errors'=>$hw];
}

/** Return newest attributable cumulative share counter for each live instance. */
function miner_share_instance_counters(array $logs,array $nodes,?int $now=null): array
{
    $now??=time();
    usort($logs,static fn($a,$b)=>strcmp((string)($b['time']??$b['timestamp']??''),(string)($a['time']??$a['timestamp']??'')));
    $found=[];
    $startById=[];
    foreach ($nodes as $node) {
        if (!is_array($node))continue;
        $id=(string)($node['instance_id']??$node['id']??'');
        $started=strtotime((string)($node['update_time']??''));
        if ($id!=='' && $started!==false)$startById[$id]=$started;
    }
    foreach (array_slice($logs,0,300) as $item) {
        if (!is_array($item))continue;
        $ts=strtotime((string)($item['time']??$item['timestamp']??''));
        if ($ts===false || $ts>$now+120 || $now-$ts>540)continue;
        $count=miner_share_counters((string)($item['text_log']??$item['message']??''));
        if ($count===null)continue;
        $id=miner_monitor_log_instance($item,$nodes);
        if ($id===null || isset($found[$id]))continue;
        // Even a labeled log may be older than the newest running node state.
        // Reallocation/old worker summaries must never seed a new counter.
        if (!isset($startById[$id]) || $ts<$startById[$id]-30)continue;
        $found[$id]=$count+['log_at'=>gmdate('Y-m-d H:i:s',$ts)];
    }
    return $found;
}

/** GPU canonicalization for comparison only; never affects node selection. */
function miner_share_gpu_family(string $model): string
{
    $m=strtoupper(trim($model));
    if ($m==='')return '';
    if (preg_match('/\\b(?:RTX|GTX)\\s*(\\d{4})(?:\\s*(TI))?(?:\\s*(SUPER))?(?:\\s*(LAPTOP))?/i',$m,$parts)) {
        $prefix=str_contains($m,'GTX')?'GTX':'RTX';
        $result=$prefix.' '.$parts[1];
        if (!empty($parts[2])) $result.=' TI';
        if (!empty($parts[3])) $result.=' SUPER';
        if (!empty($parts[4]) || str_contains($m,'MOBILE')) $result.=' LAPTOP';
        return $result;
    }
    return substr(trim(preg_replace('/\\s+/',' ',$m)??$m),0,120);
}

/** A share counter reset or missing next reading must NEVER inflate totals. */
function miner_share_delta(?array $previous,array $current): ?array
{
    if ($previous===null)return null;
    $elapsed=(int)$current['ts']-(int)$previous['ts'];
    if ($elapsed<=0 || $elapsed>660)return null;
    if (!is_numeric($current['accepted']) || !is_numeric($previous['accepted']))return null;
    $now=(int)$current['accepted'];$before=(int)$previous['accepted'];
    if ($now<$before)return ['reset'=>true,'accepted'=>0,'seconds'=>0];
    if ($now-$before>100000)return null; // unit mismatch or corrupted miner counter
    return ['reset'=>false,'accepted'=>$now-$before,'seconds'=>$elapsed];
}

/**
 * Uses per-instance counters from MariaDB; no pool-reward attribution.
 * The peer median of raw share rates is illustrative ONLY because share
 * difficulty is unverified, so it cannot trigger automated penalties.
 */
function miner_share_analyze(array $rows,array $hashRows,int $now): array
{
    $identified=[];$distinct=[];
    foreach ($rows as $r) {
        $id=(string)($r['group_id']??'').':'.(string)($r['instance_id']??'');
        $model=miner_share_gpu_family((string)($r['gpu_model']??''));
        if ($model!=='')$distinct[$id][$model]=true;
    }
    foreach ($hashRows as $r) {
        $id=(string)($r['group_id']??'').':'.(string)($r['instance_id']??'');
        $model=miner_share_gpu_family((string)($r['gpu_model']??''));
        if ($model!=='')$distinct[$id][$model]=true;
    }
    foreach ($distinct as $id=>$models)if(count($models)===1)$identified[$id]=array_key_first($models);
    usort($rows,static fn($a,$b)=>strcmp((string)$a['observed_at'],(string)$b['observed_at']));
    $last=[];$devices=[];
    foreach ($rows as $r) {
        $id=(string)$r['group_id'].':'.(string)$r['instance_id'];
        if (!isset($identified[$id]))continue;
        if (!str_starts_with(strtolower((string)($r['group_name']??'')),'prl-'))continue;
        $at=strtotime((string)($r['observed_at']??''));
        $counter=$r['accepted_shares']??null;
        if ($at===false)continue;
        // Missing/invalid counters break continuity, even if nearby valid
        // snapshots are less than eleven minutes apart.
        if ($counter===null || !is_numeric($counter) || (float)$counter<0) {
            unset($last[$id]);
            if (isset($devices[$id]))$devices[$id]['no_change_seconds']=0;
            continue;
        }
        $key=(string)$r['organization'].'|'.strtolower((string)($r['priority']??'')).'|'.$identified[$id];
        $active=(int)$r['ready']===1 && (int)$r['started']===1 && (string)$r['state']==='running';
        $current=['ts'=>$at,'accepted'=>(int)$counter,'active'=>$active];
        if (!isset($devices[$id]))$devices[$id]=[
            'key'=>$key,'gpu'=>$identified[$id],'organization'=>$r['organization'],
            'priority'=>$r['priority'],'group'=>$r['group_name'],'instance_id'=>$r['instance_id'],
            'accepted_delta'=>0,'covered_seconds'=>0,'intervals'=>0,'resets'=>0,
            'last_at'=>0,'last_counter'=>null,'last_increment_ts'=>null,'no_change_seconds'=>0
        ];
        $d=&$devices[$id];
        $delta=$active && ($last[$id]['active']??false)?miner_share_delta($last[$id],$current):null;
        if ($delta!==null) {
            if ($delta['reset']){$d['resets']++;$d['no_change_seconds']=0;$d['last_increment_ts']=null;}
            else {
                $d['covered_seconds']+=$delta['seconds'];
                $d['accepted_delta']+=$delta['accepted'];
                $d['intervals']++;
                if ($delta['accepted']>0) {$d['last_increment_ts']=$at;$d['no_change_seconds']=0;}
                else $d['no_change_seconds']+=$delta['seconds'];
            }
        } else $d['no_change_seconds']=0;
        $d['last_at']=$at;$d['last_counter']=$current['accepted'];
        $last[$id]=$current;
        unset($d);
    }

    $hashes=[];
    foreach ($hashRows as $r) {
        $id=(string)$r['group_id'].':'.(string)$r['instance_id'];
        if(!isset($identified[$id]))continue;
        if(!str_starts_with(strtolower((string)($r['group_name']??'')),'prl-'))continue;
        $at=strtotime((string)($r['observed_at']??''));
        $rate=$r['hashrate_ths']??null;
        if ($at===false || $at>$now+120 || $at<$now-7200 ||
            !is_numeric($rate) || (float)$rate<=0 || (float)$rate>20000 ||
            (int)$r['ready']!==1 || (int)$r['started']!==1 ||
            (string)$r['state']!=='running')continue;
        $key=(string)$r['organization'].'|'.strtolower((string)($r['priority']??'')).'|'.$identified[$id];
        if(!isset($hashes[$id]))$hashes[$id]=['key'=>$key,'points'=>[]];
        $hashes[$id]['points'][$at]=(float)$rate;
    }
    $peers=[];
    foreach ($hashes as $id=>$v) {
        ksort($v['points']);
        $points=$v['points'];$recent=array_slice($points,-12,true);
        $oldest=$recent===[]?0:(int)array_key_first($recent);
        $newest=$recent===[]?0:(int)array_key_last($recent);
        if(count($recent)<6 || $newest-$oldest<1500 || $newest<$now-600)continue;
        $mean=array_sum($recent)/count($recent);
        $peers[$v['key']][$id]=['mean'=>$mean,'last3'=>array_slice(array_values($recent),-3)];
    }

    $byClass=[];
    foreach ($devices as $id=>$d) {
        if($d['covered_seconds']<1)continue;
        $rate=round($d['accepted_delta']*3600/$d['covered_seconds'],2);
        $d['accepted_per_hour']=$rate;
        $d['covered_minutes']=round($d['covered_seconds']/60,1);
        $d['current']=$d['last_at']>=$now-900;
        $d['signal']='learning';$d['note']='Referencia en aprendizaje; shares sin dificultad normalizada';
        $ref=$peers[$d['key']]??[];
        unset($ref[$id]);
        if(isset($peers[$d['key']][$id]) && count($ref)>=3) {
            $refRates=array_column($ref,'mean');
            sort($refRates,SORT_NUMERIC);
            $count=count($refRates);
            $median=$count%2?$refRates[intdiv($count,2)]:($refRates[$count/2-1]+$refRates[$count/2])/2;
            $last3=$peers[$d['key']][$id]['last3'];
            if($d['current'] && count($last3)===3 && $median>20 &&
                max($last3)<$median*.75 && $median-max($last3)>=10) {
                $d['signal']='watch';$d['note']='TH/s sostenidos por debajo del 75% de tres o más GPU comparables; revisión manual';
            } else {$d['signal']='observing';$d['note']='TH/s contrastado con pares; shares no normalizados';}
        }
        if($d['signal']!=='watch' && $d['current'] &&
            $d['covered_seconds']>=2700 && $d['no_change_seconds']>=2700) {
            $d['signal']='watch';$d['note']='Contador de shares sin incremento durante 45 min observados; revisar dificultad y pool';
        }
        $byClass[$d['key']][]=$d;
        $devices[$id]=$d;
    }
    $groups=[];
    foreach ($byClass as $key=>$records) {
        $rates=array_column($records,'accepted_per_hour');
        sort($rates,SORT_NUMERIC);
        $n=count($rates);
        $median=$n%2?$rates[intdiv($n,2)]:($rates[$n/2-1]+$rates[$n/2])/2;
        $groups[]=[
            'key'=>$key,'gpu'=>$records[0]['gpu'],
            'organization'=>$records[0]['organization'],'priority_current'=>$records[0]['priority'],
            'devices'=>$n,'median_shares_hour_raw'=>round($median,2),
            'coverage_minutes'=>round(array_sum(array_column($records,'covered_minutes')),1),
            'watch'=>count(array_filter($records,static fn($r)=>$r['signal']==='watch')),
            'difficulty_verified'=>false,
            'notes'=>'Shares brutos/h, sin dificultad verificada; comparación ilustrativa',
        ];
    }
    usort($groups,static fn($a,$b)=>strcmp($a['organization'],$b['organization']) ?: strcmp($a['gpu'],$b['gpu']));
    usort($devices,static fn($a,$b)=>($a['signal']==='watch'?-1:0)<=>($b['signal']==='watch'?-1:0) ?: strcmp($a['gpu'],$b['gpu']));
    return ['groups'=>$groups,'devices'=>array_values($devices),'share_difficulty_verified'=>false,
        'alert_mode'=>'informational_only','sample_scope'=>'prl-prefixed groups'];
}

/** Read-only, bounded histories; no new Salad request and no schema change. */
function miner_share_learning_dashboard(): array
{
    $db=miner_db();
    $base=" FROM miner_observations m JOIN group_state g ON g.id=m.group_id ";
    $samples=$db->query("SELECT m.group_id,m.instance_id,m.observed_at,m.state,m.ready,m.started,m.accepted_shares,m.gpu_model,
        g.organization,g.project_name,g.group_name,g.priority".$base."
        WHERE m.observed_at>=UTC_TIMESTAMP()-INTERVAL 30 HOUR
        ORDER BY m.observed_at DESC,m.id DESC LIMIT 12000")->fetchAll(PDO::FETCH_ASSOC);
    $hash=$db->query("SELECT m.group_id,m.instance_id,m.observed_at,m.state,m.ready,m.started,m.hashrate_ths,m.gpu_model,
        g.organization,g.project_name,g.group_name,g.priority".$base."
        WHERE m.observed_at>=UTC_TIMESTAMP()-INTERVAL 2 HOUR AND m.hashrate_ths>0
        ORDER BY m.observed_at DESC,m.id DESC LIMIT 6000")->fetchAll(PDO::FETCH_ASSOC);
    return miner_share_analyze($samples,$hash,time());
}

/**
 * The learning panel is OPTIONAL. A missing/older column, database resource
 * limit, or a faulty third-party log must never take the authenticated
 * monitor, historic TH/s tables, or session offline.
 */
function miner_share_learning_safe(callable $read): array
{
    try {
        $data=$read();
        if (!is_array($data) || !is_array($data['groups']??null)
            || !is_array($data['devices']??null)) {
            throw new UnexpectedValueException('invalid optional learning data');
        }
        $data['unavailable']=false;
        return $data;
    } catch(Throwable $e) {
        // Never emit SQL text, input, DB credentials or instance identifiers.
        $code=preg_replace('/[^A-Za-z0-9]/','',(string)$e->getCode())??'';
        error_log('[hache-miner] optional-shares-unavailable '.get_class($e).' code='.substr($code,0,12));
        return ['groups'=>[],'devices'=>[],'unavailable'=>true,
            'share_difficulty_verified'=>false,'alert_mode'=>'informational_only'];
    }
}
