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
    // Independent, approximately 30-second samples spanning >= 90 seconds.
    // A 29-second cadence needs five samples; four alone would never trigger.
    if(count($samples)<4) return null;
    $times=[];
    foreach($samples as $s) {
        $at=miner_vast_wd_unix($s['observed_at']??null);
        $rx=miner_vast_wd_unix($s['received_at']??null);
        if($at<=0 || $at>$now+45 || $now-$at>=150 || $rx<=0 || $now-$rx>=150 ||
            !isset($s['total_ths']) || !is_numeric($s['total_ths']) ||
            !is_finite((float)$s['total_ths']) || (float)$s['total_ths']>=2800) return null;
        if ($times && ($times[count($times)-1]-$at<20 ||
                       $times[count($times)-1]-$at>45)) return null;
        $times[]=$at;
        if(count($times)>=4 && $times[0]-$at>=90) return 'hashrate_low';
    }
    return null;
}

/** No credentials in source, CLI arguments, logs or URLs. */
function miner_vast_stop_api(string $apiKey, string $id): bool {
    if($id!=='56667162' || strlen($apiKey)<20 || preg_match('/\s/',$apiKey)) throw new RuntimeException('Invalid stop authorization');
    $ch=curl_init('https://console.vast.ai/api/v0/instances/'.$id.'/');
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
