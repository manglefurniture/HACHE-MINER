<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';
require_once __DIR__.'/reallocate.php';
require_once __DIR__.'/monitor.php';

/**
 * Policy applies ONLY to independently identified PRL (pearlhash) mining.
 * Quantus/QTC and unknown algorithms are intentionally unsupported.
 */
function miner_auto_low_prl_metric(array $rows, int $now): ?array {
    if(count($rows)<3)return null;
    $rows=array_slice($rows,0,3);
    $timestamps=[];$values=[];
    foreach($rows as $row) {
        $stamp=(string)($row['observed_at']??'');
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$stamp,new DateTimeZone('UTC'));
        $hash=$row['hashrate_ths']??null;
        $gpu=strtoupper((string)($row['gpu_model']??''));
        if(!$parsed || !is_numeric($hash) || !is_finite((float)$hash)
          || (float)$hash<0 || (float)$hash>125.0 || !str_contains($gpu,'4070 TI SUPER')
          || (int)($row['ready']??0)!==1 || (int)($row['started']??0)!==1
          || strtolower((string)($row['state']??''))!=='running')return null;
        $timestamps[]=$parsed->getTimestamp();$values[]=(float)$hash;
    }
    // Three distinct observations ~5m apart; skip ambiguous gaps, clock drift
    // and samples too old. Never infer sustained mining from a single log.
    if($timestamps[0]>$now+120 || $now-$timestamps[0]>480
       || $timestamps[0]-$timestamps[1]<240 || $timestamps[0]-$timestamps[1]>450
       || $timestamps[1]-$timestamps[2]<240 || $timestamps[1]-$timestamps[2]>450
       || $timestamps[0]-$timestamps[2]<540 || $timestamps[0]-$timestamps[2]>900)return null;
    return ['hashrate_ths'=>$values[0],'average_ths'=>round(array_sum($values)/3,2),
      'span_seconds'=>$timestamps[0]-$timestamps[2]];
}
function miner_auto_enabled(): bool {
    return getenv('MINER_INTERACTIVE_AUTO_ENABLED')==='1';
}
/** All database reads are read-only; no change is made while planning. */
function miner_auto_candidates(int $now): array {
    $db=miner_db();
    $groups=$db->query("SELECT g.id,g.organization,g.project_name,g.group_name,g.state,g.priority,
      g.last_seen_at
      FROM group_state g JOIN salad_targets t
      ON t.organization_slug=g.organization AND t.project_slug=g.project_name AND t.enabled=1
      WHERE g.organization='interactive' AND g.state='running' AND g.priority='low'
        AND g.last_seen_at>=UTC_TIMESTAMP()-INTERVAL 8 MINUTE
      ORDER BY g.id LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    $out=[];
    $alg=$db->prepare("SELECT 1 FROM log_events
      WHERE group_id=? AND logged_at>=UTC_TIMESTAMP()-INTERVAL 30 MINUTE
        AND summary LIKE '%[pearlhash]%' LIMIT 1");
    $latest=$db->prepare("SELECT instance_id,MAX(observed_at) last_seen FROM miner_observations
      WHERE group_id=? AND observed_at>=UTC_TIMESTAMP()-INTERVAL 8 MINUTE
      GROUP BY instance_id LIMIT 20");
    $samples=$db->prepare("SELECT observed_at,state,ready,started,hashrate_ths,gpu_model
       FROM miner_observations WHERE group_id=? AND instance_id=?
       ORDER BY observed_at DESC,id DESC LIMIT 3");
    $manual=$db->prepare("SELECT 1 FROM audit_events WHERE
       action_name IN ('manual_reallocate_intent','auto_reallocate_intent')
       AND detail=? AND (action_name='auto_reallocate_intent'
          OR occurred_at>=UTC_TIMESTAMP()-INTERVAL 60 MINUTE) LIMIT 1");
    foreach($groups as $group){
        $alg->execute([$group['id']]);if(!$alg->fetchColumn())continue;
        $latest->execute([$group['id']]);$nodes=$latest->fetchAll(PDO::FETCH_ASSOC);
        // Parallel replicas are supported, but don't scan unbounded groups.
        foreach($nodes as $node){
            $id=(string)$node['instance_id'];
            if(!miner_reallocation_valid_instance_id($id))continue;
            $samples->execute([$group['id'],$id]);
            $metric=miner_auto_low_prl_metric($samples->fetchAll(PDO::FETCH_ASSOC),$now);
            if(!$metric)continue;
            $fingerprint=miner_reallocation_fingerprint($group,$id);
            $manual->execute([$fingerprint]);if($manual->fetchColumn())continue;
            $out[]=['target'=>$group,'instance_id'=>$id,'fingerprint'=>$fingerprint,'metric'=>$metric];
        }
    }
    return $out;
}
/**
 * The same named MySQL lock/fingerprint is used by manual reallocation.
 * Never automatically retry an uncertain external POST on the same instance.
 */
function miner_auto_execute(array $candidate): string {
    $g=$candidate['target'];$instanceId=$candidate['instance_id'];$fp=$candidate['fingerprint'];
    $db=miner_db();$lock='hmr:'.substr($fp,0,60);
    $st=$db->prepare('SELECT GET_LOCK(?,2)');$st->execute([$lock]);
    if((int)$st->fetchColumn()!==1)return 'busy';
    try{
        $st=$db->prepare("SELECT 1 FROM audit_events WHERE
          action_name IN ('manual_reallocate_intent','auto_reallocate_intent')
          AND detail=? AND (action_name='auto_reallocate_intent' OR
          occurred_at>=UTC_TIMESTAMP()-INTERVAL 60 MINUTE) LIMIT 1");
        $st->execute([$fp]);if($st->fetchColumn())return 'already_requested';
        $liveTarget=miner_reallocation_target((int)$g['id'],$instanceId);
        if($liveTarget['organization']!=='interactive' || $liveTarget['project_name']!==$g['project_name']
           || $liveTarget['group_name']!==$g['group_name'])return 'not_interactive';
        // Reassess all three samples + verified pearlhash immediately after lock.
        $candidates=miner_auto_candidates(time());
        $matching=array_values(array_filter($candidates,static fn($c)=>$c['fingerprint']===$fp));
        if(count($matching)!==1)return 'no_longer_eligible';
        $key=miner_shared_salad_api_key();
        if(!$key)throw new RuntimeException('Salad credential unavailable');
        $base='https://api.salad.com/api/public/organizations/'.rawurlencode($g['organization'])
            .'/projects/'.rawurlencode($g['project_name'])
            .'/containers/'.rawurlencode($g['group_name'])
            .'/instances/'.rawurlencode($instanceId);
        $live=miner_reallocation_api_instance($base,$key);
        if(!miner_reallocation_live_matches($live,$instanceId))return 'live_instance_changed';
        // Durable intent before sending non-idempotent operation.
        miner_audit(null,'auto_reallocate_intent',$fp);
        $status=miner_reallocation_request($base.'/reallocate',$key);
        if($status===202){
            miner_audit(null,'auto_reallocate_accepted',$fp);
            return 'accepted';
        }
        miner_audit(null,'auto_reallocate_unknown',$fp);
        return 'unknown';
    }finally{
        $st=$db->prepare('SELECT RELEASE_LOCK(?)');$st->execute([$lock]);
    }
}
