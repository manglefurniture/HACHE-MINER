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
    return is_string($response)?$status:0;
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
            WHERE action_name='manual_reallocate_intent' AND detail=?
            AND occurred_at>UTC_TIMESTAMP()-INTERVAL 15 MINUTE");
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
        if (($live['id']??null)!==$instanceId || !miner_instance_ready($live)) {
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
