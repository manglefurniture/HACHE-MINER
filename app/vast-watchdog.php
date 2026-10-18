<?php
declare(strict_types=1);
require_once __DIR__.'/vast.php';

/**
 * VAST-002: independent server-side watchdog. Monitoring does NOT depend on
 * cron, the miner, or networking on a Vast instance. It never controls GPUs.
 * The absence of telemetry is NOT proof that an instance is powered off.
 */

/** Parse MySQL UTC dates without using the VPS's local timezone. */
function miner_vast_wd_unix(mixed $date): int {
    if (!is_string($date) || $date==='') return 0;
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date, new DateTimeZone('UTC'));
    return $dt===false ? 0 : $dt->getTimestamp();
}

/**
 * Pure classification, newest sample first. Rows must be scoped to ONE
 * instance and GPU lists indexed by database sample ID.
 * @return array{code:string,severity:string,reason:string}
 */
function miner_vast_wd_classify(array $instance, array $samples, array $gpuRows, int $now, string $previousCode='ok'): array {
    $healthy=['code'=>'ok','severity'=>'green','reason'=>'Minería y telemetría en observación'];
    $last=$samples[0]??null;
    $registered=miner_vast_wd_unix($instance['updated_at']??null);
    if (!$last) {
        // Avoid alarming while a newly registered machine is provisioning.
        if ($registered > 0 && $now-$registered < 180) return $healthy;
        return ['code'=>'telemetry_missing','severity'=>'red','reason'=>'Sin muestras de telemetría: la instancia podría seguir facturando'];
    }
    $received=miner_vast_wd_unix($last['received_at']??null);
    $observed=miner_vast_wd_unix($last['observed_at']??null);
    if ($received===0 || $observed===0 || $now-$received > 180 || $now-$observed > 180 || $observed > $now+45) {
        return ['code'=>'telemetry_lost','severity'=>'red','reason'=>'Sin telemetría reciente durante más de 3 minutos; verificar si sigue minando y facturando'];
    }
    $expected=(int)($instance['gpu_count']??0);
    $gpu=$gpuRows[(int)$last['id']]??[];
    if ($expected<1 || count($gpu)!==$expected) {
        return ['code'=>'gpu_telemetry_incomplete','severity'=>'red','reason'=>'Faltan lecturas de GPU en la última muestra'];
    }
    // Confirm a zero-hash GPU with 2 chronological samples, not one transient.
    if (count($samples)>=2) {
        $older=$samples[1];
        $previous=$gpuRows[(int)$older['id']]??[];
        if (count($previous)===$expected) {
            $oldByIndex=[];
            foreach ($previous as $row) $oldByIndex[(int)$row['gpu_index']]=$row;
            foreach ($gpu as $row) {
                $index=(int)$row['gpu_index'];
                $hash=$row['hashrate_ths']??null;
                $prior=$oldByIndex[$index]['hashrate_ths']??null;
                if ($hash!==null && $prior!==null && (float)$hash <= 0.0 && (float)$prior <= 0.0) {
                    return ['code'=>'gpu_zero_'.$index,'severity'=>'red','reason'=>'GPU #'.$index.' con 0 TH/s en dos lecturas consecutivas'];
                }
            }
        }
    }
    // Instance-specific, disabled by default; 3 consecutive recent observations.
    $low=(float)($instance['low_ths']??0);
    $normal=(float)($instance['normal_ths']??0);
    if ($low>0 && $normal>=$low) {
        $recent=array_slice($samples,0,3);
        $continuous=count($recent)===3;
        for ($n=0;$continuous && $n<3;$n++) {
            $at=miner_vast_wd_unix($recent[$n]['observed_at']??null);
            if ($at===0 || $now-$at>180 || $at>$now+45 || !isset($recent[$n]['total_ths'])) {
                $continuous=false;
                break;
            }
            if ($n>0) {
                $priorAt=miner_vast_wd_unix($recent[$n-1]['observed_at']??null);
                if ($priorAt-$at<1 || $priorAt-$at>150) $continuous=false;
            }
        }
        if ($continuous) {
            if ($previousCode==='hashrate_low') {
                if ((float)$recent[0]['total_ths']<$normal || (float)$recent[1]['total_ths']<$normal) {
                    return ['code'=>'hashrate_low','severity'=>'yellow','reason'=>'Hashrate sin recuperar el umbral normal de '.number_format($normal,2,'.','').' TH/s'];
                }
            } elseif (array_reduce($recent,static fn(bool $all,array $s):bool=>$all && (float)$s['total_ths']<$low,true)) {
                return ['code'=>'hashrate_low','severity'=>'yellow','reason'=>'Hashrate bajo '.number_format($low,2,'.','').' TH/s en tres lecturas consecutivas'];
            }
        } elseif ($previousCode==='hashrate_low') {
            return ['code'=>'hashrate_low','severity'=>'yellow','reason'=>'Esperando muestras para confirmar recuperación'];
        }
    }
    // Stalled share counter: require a continuous ~10min window, plausible
    // sample count and non-zero hashrate. No blanket rule for low share rates.
    $latestShares=$last['accepted_shares']??null;
    if ($latestShares!==null && (float)($last['total_ths']??0)>0 && count($samples)>=8) {
        foreach (array_reverse($samples) as $old) {
            $elapsed=$observed-miner_vast_wd_unix($old['observed_at']??null);
            if ($elapsed<600 || $elapsed>1500 || $old['accepted_shares']===null) continue;
            if ((int)$latestShares===(int)$old['accepted_shares']) {
                return ['code'=>'shares_stalled','severity'=>'yellow','reason'=>'Sin nuevas shares aceptadas durante al menos 10 minutos; comprobar dificultad y conexión al pool'];
            }
            break;
        }
    }
    return $healthy;
}

/** HTTP 2xx confirmation is required before a state transition is persisted. */
function miner_vast_wd_ntfy(string $topic, string $title, string $body, string $severity): bool {
    if (!preg_match('/^[A-Za-z0-9_-]{16,200}$/D',$topic)) throw new RuntimeException('Invalid ntfy topic');
    if (!function_exists('curl_init')) throw new RuntimeException('curl unavailable');
    $ch=curl_init('https://ntfy.sh/'.rawurlencode($topic));
    $headers=[
        'Content-Type: text/plain; charset=utf-8',
        'Title: '.$title,
        'Priority: '.($severity==='red'?'urgent':($severity==='yellow'?'high':'default')),
        'Tags: '.($severity==='red'?'rotating_light,computer':($severity==='yellow'?'warning,computer':'white_check_mark,computer')),
    ];
    curl_setopt_array($ch,[
        CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body, CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>12, CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_MAXREDIRS=>0, CURLOPT_USERAGENT=>'HACHE-MINER-Vast-Watchdog/1.0',
    ]);
    $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return is_string($response) && $status>=200 && $status<300;
}

/** A single, read-only fleet snapshot; SQL data are never logged verbatim. */
function miner_vast_wd_snapshot(): array {
    $db=miner_db();
    if (!miner_vast_schema_ready()) throw new RuntimeException('Vast telemetry tables missing');
    $instances=$db->query('SELECT instance_id,label,gpu_count,hourly_usd,low_ths,normal_ths,updated_at FROM vast_instances WHERE enabled=1 ORDER BY instance_id')->fetchAll();
    $sample=$db->prepare('SELECT id,observed_at,received_at,total_ths,accepted_shares,rejected_shares FROM vast_samples WHERE instance_id=? ORDER BY observed_at DESC,id DESC LIMIT 20');
    $gpus=$db->prepare('SELECT gpu_index,hashrate_ths FROM vast_gpu_samples WHERE sample_id=? ORDER BY gpu_index');
    $result=[];
    foreach ($instances as $i) {
        $sample->execute([$i['instance_id']]);
        $rows=$sample->fetchAll();
        $byId=[];
        foreach (array_slice($rows,0,2) as $s) {
            $gpus->execute([(int)$s['id']]);
            $byId[(int)$s['id']]=$gpus->fetchAll();
        }
        $result[]=['instance'=>$i,'samples'=>$rows,'gpus'=>$byId];
    }
    return $result;
}

/** Fixed-interval low-volume notification with durable no-duplicate state. */
function miner_vast_wd_run(array $snapshots, string $stateFile, string $topic, int $now, bool $dryRun=false): array {
    $raw=is_file($stateFile)?file_get_contents($stateFile):'';
    $previous=is_string($raw)&&$raw!==''?json_decode($raw,true):[];
    if (!is_array($previous)) throw new RuntimeException('Invalid watchdog state');
    $next=$previous; $sent=0; $issues=0; $active=[];
    foreach ($snapshots as $snapshot) {
        $i=$snapshot['instance'];
        $id=(string)$i['instance_id'];$active[$id]=true;
        if (!preg_match('/^[0-9]{4,64}$/D',$id)) throw new RuntimeException('Invalid Vast instance');
        $old=(string)($previous[$id]['code']??'ok');
        $a=miner_vast_wd_classify($i,$snapshot['samples'],$snapshot['gpus'],$now,$old);
        $code=$a['code'];
        if ($code!=='ok')$issues++;
        if ($old===$code) continue;
        $name=mb_substr(preg_replace('/[\x00-\x1f\x7f]/u',' ',(string)($i['label']??'Vast'))??'Vast',0,80);
        $elapsed=0;$rate=max(0,(float)($i['hourly_usd']??0));
        $since=(int)($previous[$id]['since']??$now);
        if ($old!=='ok') $elapsed=max(0,$now-$since);
        $status=$code==='ok'?'RECUPERACIÓN':($a['severity']==='red'?'ALERTA ROJA':'ALERTA AMARILLA');
        $title='Vast Monitor - '.$status;
        $body="Vast.ai | ".$name."\nInstancia: ".$id."\n".
            ($code==='ok'?'Alerta resuelta: muestras recientes y GPU comprobadas':$a['reason'])."\n".
            "Tarifa estimada: ".number_format($rate,3,'.','')." USD/h\n".
            ($code==='ok'?"Incidencia registrada: ~".(int)ceil($elapsed/60)." min\n":"").
            "Hora UTC: ".gmdate('Y-m-d H:i:s',$now);
        if ($dryRun) {
            echo 'DRY_RUN instance='.$id.' from='.$old.' to='.$code."\n";
            continue;
        }
        if (!miner_vast_wd_ntfy($topic,$title,$body,$a['severity'])) throw new RuntimeException('ntfy did not accept the Vast alert');
        $next[$id]=['code'=>$code,'since'=>$code==='ok'?0:($old==='ok'?$now:$since),'updated_at'=>gmdate(DATE_ATOM,$now)];
        $sent++;
        miner_vast_wd_save_state($stateFile,$next); // persist each acknowledged notification
    }
    // Disabled instances are no longer watched, and must not emit recovery alerts.
    foreach (array_keys($next) as $id) if (!isset($active[$id])) unset($next[$id]);
    if (!$dryRun) miner_vast_wd_save_state($stateFile,$next);
    return ['instances'=>count($snapshots),'issues'=>$issues,'sent'=>$sent];
}

function miner_vast_wd_save_state(string $file, array $state): void {
    $dir=dirname($file);
    if (!is_dir($dir) || is_link($dir)) throw new RuntimeException('Watchdog state directory unavailable');
    $temp=tempnam($dir,'vast-watchdog-');
    if ($temp===false) throw new RuntimeException('State temporary file unavailable');
    try {
        if (file_put_contents($temp,json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))===false) throw new RuntimeException('State write failed');
        chmod($temp,0600);
        if (!rename($temp,$file)) throw new RuntimeException('State atomic rename failed');
    } finally { if (is_file($temp)) @unlink($temp); }
}
