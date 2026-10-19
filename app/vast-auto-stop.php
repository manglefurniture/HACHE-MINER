<?php
declare(strict_types=1);
require_once __DIR__.'/vast-watchdog.php';

/** Only the explicitly armed instance can be stopped. No fleet-wide control. */
function miner_vast_stop_reason(array $instance, array $samples, int $now, int $armedAt): ?string {
    if ((string)($instance['instance_id']??'') !== '56667162' || (int)($instance['gpu_count']??0)!==8) return null;
    if ($armedAt <= 0 || $now < $armedAt + 180) return null;
    $recent=$samples[0]??null;
    if (!$recent) return 'telemetry_missing';
    $received=miner_vast_wd_unix($recent['received_at']??null);
    $observed=miner_vast_wd_unix($recent['observed_at']??null);
    if ($received<=0 || $observed<=0 || $observed>$now+45 ||
        $now-$received>=180 || $now-$observed>=180) return 'telemetry_lost';
    // 3 independent, approximately 30-second samples spanning >= 60 seconds:
    // confirmed below threshold over the 90-second observation window.
    if(count($samples)<3) return null;
    $three=array_slice($samples,0,3);
    $times=[];
    foreach($three as $s) {
        $at=miner_vast_wd_unix($s['observed_at']??null);
        $rx=miner_vast_wd_unix($s['received_at']??null);
        if($at<=0 || $at>$now+45 || $now-$at>=120 || $rx<=0 || $now-$rx>=120 ||
            !isset($s['total_ths']) || !is_numeric($s['total_ths']) ||
            !is_finite((float)$s['total_ths']) || (float)$s['total_ths']>=2800) return null;
        $times[]=$at;
    }
    if($times[0]-$times[1]<20 || $times[0]-$times[1]>45 ||
       $times[1]-$times[2]<20 || $times[1]-$times[2]>45 ||
       $times[0]-$times[2]<60) return null;
    return 'hashrate_low';
}

/** No credentials in source, CLI arguments, logs or URLs. */
function miner_vast_stop_api(string $apiKey, string $id): bool {
    if($id!=='56667162' || strlen($apiKey)<20 || preg_match('/\s/',$apiKey)) throw new RuntimeException('Invalid stop authorization');
    $ch=curl_init('https://console.vast.ai/api/v0/instances/'.$id);
    if($ch===false) throw new RuntimeException('Vast transport missing');
    curl_setopt_array($ch,[
        CURLOPT_CUSTOMREQUEST=>'PUT',
        CURLOPT_POSTFIELDS=>'{"state":"stopped"}',
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey,'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
    ]);
    $body=curl_exec($ch);
    $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $decoded=is_string($body)?json_decode($body,true):null;
    return $code===200 && is_array($decoded) && ($decoded['success']??null)===true;
}
