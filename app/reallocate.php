<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';

/**
 * Manual operation, deliberately separate from the autonomous alert system.
 * Never called by the collector, a GET request, or unauthenticated pages.
 */
function miner_reallocation_valid_instance_id(string $id): bool {
    return strlen($id)<=120 && preg_match('/^[a-zA-Z0-9_-]{1,120}$/D',$id)===1;
}
function miner_reallocation_confirmed(string $answer): bool {
    return hash_equals('REASIGNAR',$answer);
}
function miner_reallocation_fingerprint(array $target,string $instanceId): string {
    return hash('sha256',(string)$target['organization']."\0".(string)$target['project_name']."\0".(string)$target['group_name']."\0".$instanceId);
}
/** Only registered/enabled targets with fresh, running and ready observed instances. */
function miner_reallocation_target(int $groupId,string $instanceId): array {
    if ($groupId<1 || !miner_reallocation_valid_instance_id($instanceId)) {
        throw new InvalidArgumentException('Invalid group or instance');
    }
    $db=miner_db();
    $st=$db->prepare("SELECT g.id,g.organization,g.project_name,g.group_name,g.state,g.last_seen_at
        FROM group_state g
        JOIN salad_targets t ON t.organization_slug=g.organization
          AND t.project_slug=g.project_name AND t.enabled=1
        WHERE g.id=? AND g.last_seen_at>=UTC_TIMESTAMP()-INTERVAL 10 MINUTE LIMIT 1");
    $st->execute([$groupId]);$group=$st->fetch(PDO::FETCH_ASSOC);
    if (!$group || strtolower((string)$group['state'])!=='running') {
        throw new DomainException('Group not currently running and monitored');
    }
    $st=$db->prepare("SELECT state,ready,started,observed_at FROM miner_observations
       WHERE group_id=? AND instance_id=? AND observed_at>=UTC_TIMESTAMP()-INTERVAL 10 MINUTE
       ORDER BY observed_at DESC,id DESC LIMIT 1");
    $st->execute([$groupId,$instanceId]);$observed=$st->fetch(PDO::FETCH_ASSOC);
    if (!$observed || strtolower((string)$observed['state'])!=='running'
        || (int)$observed['ready']!==1 || (int)$observed['started']!==1) {
        throw new DomainException('Instance not currently running and ready');
    }
    return $group+['instance_id'=>$instanceId,'instance_observed_at'=>$observed['observed_at']];
}
/**
 * The manual cooldown is scoped to the EXACT instance. No hashrate threshold
 * applies: the administrator can request another node even when healthy.
 */
function miner_reallocation_cooldown_seconds(array $group,string $instanceId, ?int $now=null): int {
    $fingerprint=miner_reallocation_fingerprint($group,$instanceId);
    $st=miner_db()->prepare("SELECT action_name,MAX(occurred_at) AS at FROM audit_events
      WHERE detail=? AND (
        (action_name='manual_reallocate_intent' AND occurred_at>=UTC_TIMESTAMP()-INTERVAL 15 MINUTE)
        OR (action_name='auto_reallocate_intent' AND occurred_at>=UTC_TIMESTAMP()-INTERVAL 60 MINUTE)
      ) GROUP BY action_name");
    $st->execute([$fingerprint]);
    $cooldown=0;
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $entry){
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)$entry['at'],new DateTimeZone('UTC'));
        $limit=$entry['action_name']==='auto_reallocate_intent'?3600:900;
        if(!$parsed)return $limit;
        $elapsed=($now??time())-$parsed->getTimestamp();
        $cooldown=max($cooldown,max(0,$limit-max(0,$elapsed)));
    }
    return $cooldown;
}
/** One read for the entire monitor instead of an SQL query per displayed GPU. */
function miner_reallocation_cooldown_index(?int $now=null): array {
    $db=miner_db();
    $rows=$db->query("SELECT detail,action_name,MAX(occurred_at) AS at
        FROM audit_events
        WHERE (action_name='manual_reallocate_intent'
          AND occurred_at>=UTC_TIMESTAMP()-INTERVAL 15 MINUTE)
           OR (action_name='auto_reallocate_intent'
          AND occurred_at>=UTC_TIMESTAMP()-INTERVAL 60 MINUTE)
        GROUP BY detail,action_name LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
    $out=[];
    foreach($rows as $item) {
        if (!preg_match('/^[a-f0-9]{64}$/D',(string)$item['detail']))continue;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)$item['at'],new DateTimeZone('UTC'));
        if (!$date)continue;
        $duration=$item['action_name']==='auto_reallocate_intent'?3600:900;
        $seconds=max(0,$duration-max(0,($now??time())-$date->getTimestamp()));
        if($seconds>0)$out[(string)$item['detail']]=max($seconds,$out[(string)$item['detail']]??0);
    }
    return $out;
}
/** Rate-limited password confirmation, independent of the remembered-device cookie. */
function miner_reallocation_password(int $adminId,string $password,string $ip): bool {
    if($adminId<1 || $password==='' || strlen($password)>1024) return false;
    $db=miner_db();
    $account='manual-reallocate:'.$adminId;
    $hash=hash('sha256',$ip);
    $st=$db->prepare('SELECT COUNT(*) FROM login_attempts WHERE username=? AND success=0 AND attempted_at>UTC_TIMESTAMP()-INTERVAL 15 MINUTE');
    $st->execute([$account]);
    if((int)$st->fetchColumn()>=5) return false;
    $st=$db->prepare('SELECT password_hash FROM administrators WHERE id=? LIMIT 1');
    $st->execute([$adminId]);$stored=$st->fetchColumn();
    $ok=is_string($stored) && password_verify($password,$stored);
    $st=$db->prepare('INSERT INTO login_attempts(username,ip_hash,success) VALUES (?,?,?)');
    $st->execute([$account,$hash,$ok?1:0]);
    return $ok;
}
function miner_reallocation_live_matches(array $live,string $expectedId): bool {
    return ($live['instance_id']??$live['id']??null)===$expectedId
        && miner_instance_ready($live);
}
function miner_reallocation_api_instance(string $base,string $key): array {
    return miner_http_json($base,['Salad-Api-Key: '.$key,'Accept: application/json'],8);
}
function miner_reallocation_request(string $url,string $key): int {
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>12,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_HTTPHEADER=>['Salad-Api-Key: '.$key,'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_USERAGENT=>'HACHE-MINER/1.0'
    ]);
    $response=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    // An HTTP 202 means accepted, NOT that the new node is allocated.
    return $response===false?0:$status;
}
/** Intent is recorded BEFORE the non-idempotent API call. Never retry automatically. */
function miner_reallocation_execute(int $adminId,int $groupId,string $instanceId): void {
    $target=miner_reallocation_target($groupId,$instanceId);
    $fingerprint=miner_reallocation_fingerprint($target,$instanceId);
    $db=miner_db();
    $lock='hmr:'.substr($fingerprint,0,60);
    $st=$db->prepare('SELECT GET_LOCK(?,2)');$st->execute([$lock]);
    if ((int)$st->fetchColumn()!==1) throw new RuntimeException('Reallocation already in progress');
    try {
        $st=$db->prepare("SELECT COUNT(*) FROM audit_events
            WHERE detail=? AND (
            (action_name='manual_reallocate_intent' AND occurred_at>UTC_TIMESTAMP()-INTERVAL 15 MINUTE)
            OR (action_name='auto_reallocate_intent' AND occurred_at>UTC_TIMESTAMP()-INTERVAL 60 MINUTE))");
        $st->execute([$fingerprint]);
        if ((int)$st->fetchColumn()>0) throw new DomainException('Recent request, cooldown active');
        // Re-check the stored instance after entering the per-instance lock.
        $target=miner_reallocation_target($groupId,$instanceId);
        $key=miner_shared_salad_api_key();
        if ($key===null || $key==='') throw new RuntimeException('Salad API unavailable');
        $base='https://api.salad.com/api/public/organizations/'.rawurlencode((string)$target['organization'])
            .'/projects/'.rawurlencode((string)$target['project_name'])
            .'/containers/'.rawurlencode((string)$target['group_name'])
            .'/instances/'.rawurlencode($instanceId);
        // No POST if Salad no longer reports the exact same instance as running.
        $live=miner_reallocation_api_instance($base,$key);
        if (!miner_reallocation_live_matches($live,$instanceId)) {
            throw new DomainException('Salad instance changed since monitoring');
        }
        miner_audit($adminId,'manual_reallocate_intent',$fingerprint);
        // A timeout is *uncertain*: do not retry automatically, even if no HTTP 202.
        $status=miner_reallocation_request($base.'/reallocate',$key);
        if($status===202) {
            miner_audit($adminId,'manual_reallocate_accepted',$fingerprint);
            return;
        }
        miner_audit($adminId,'manual_reallocate_not_confirmed',$fingerprint);
        throw new RuntimeException('Salad did not confirm reallocation');
    } finally {
        $st=$db->prepare('SELECT RELEASE_LOCK(?)');$st->execute([$lock]);
    }
}
