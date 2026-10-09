<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';

/** ISO-independent UTC freshness classification; never confuse stale and zero. */
function miner_pool_observation_recent(?string $utcTime, int $now, int $seconds=1200): bool {
    if ($utcTime===null || $utcTime==='') return false;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$utcTime,new DateTimeZone('UTC'));
    if (!$date) return false;
    $timestamp=$date->getTimestamp();
    return $timestamp<=$now+120 && $now-$timestamp<=$seconds;
}
function miner_pool_hashrate_ths(?string $hashrate): ?string {
    if ($hashrate===null || !preg_match('/^(?:0|[1-9][0-9]{0,23})$/D',$hashrate)) return null;
    $rate=(float)$hashrate;
    if (!is_finite($rate)) return null;
    return number_format($rate/1e12,2,'.','');
}
/**
 * Never attribute a pool wallet balance to a Salad organization.
 * Reused public addresses represent one global Kryptex balance.
 */
function miner_pool_overview(): array {
    $db=miner_db();
    $wallets=$db->query("SELECT id,organization,label,address FROM wallets WHERE coin='PRL' ORDER BY id LIMIT 101")->fetchAll(PDO::FETCH_ASSOC);
    $tooMany=count($wallets)>100;
    $wallets=array_slice($wallets,0,100);
    $byAddress=[];
    foreach($wallets as $wallet) {
        $addr=$wallet['address'];
        if(!isset($byAddress[$addr])) {
            $byAddress[$addr]=[
              'label'=>$wallet['label'],
              'suffix'=>substr($addr,-8),
              'organizations'=>[],
              'wallet_ids'=>[],
              'observed_at'=>null,
              'pending'=>null,
              'confirmed'=>null,
              'hashrate_ths'=>null,
              'workers'=>null,
              'coverage'=>null,
              'sync_status'=>null,
              'fresh'=>false
            ];
        }
        $byAddress[$addr]['organizations'][$wallet['organization']]=true;
        $byAddress[$addr]['wallet_ids'][]=(int)$wallet['id'];
    }
    $latest=$db->prepare('SELECT observed_at,pending_prl,confirmed_prl,hashrate_raw,worker_count,coverage_note FROM pool_observations WHERE wallet_id=? ORDER BY observed_at DESC,id DESC LIMIT 1');
    $latestSync=$db->prepare('SELECT status,observed_at FROM sync_runs WHERE source_name=? ORDER BY observed_at DESC,id DESC LIMIT 1');
    $now=time();
    foreach($byAddress as &$item) {
        $best=null;
        $bestSync=null;
        foreach($item['wallet_ids'] as $id) {
            $latest->execute([$id]);
            $row=$latest->fetch(PDO::FETCH_ASSOC);
            if ($row && ($best===null || $row['observed_at']>$best['observed_at'])) $best=$row;
            $latestSync->execute(['kryptex:wallet:'.$id]);
            $sync=$latestSync->fetch(PDO::FETCH_ASSOC);
            if($sync && ($bestSync===null || $sync['observed_at']>$bestSync['observed_at'])) $bestSync=$sync;
        }
        $item['organizations']=array_keys($item['organizations']);
        $item['sync_status']=$bestSync['status']??null;
        if($best!==null) {
            $item['observed_at']=$best['observed_at'];
            $item['pending']=$best['pending_prl']!==null ? (string)$best['pending_prl']:null;
            $item['confirmed']=$best['confirmed_prl']!==null ? (string)$best['confirmed_prl']:null;
            $item['workers']=$best['worker_count']===null ? null : (int)$best['worker_count'];
            $item['hashrate_ths']=miner_pool_hashrate_ths($best['hashrate_raw']);
            $item['coverage']=$best['coverage_note'];
            $item['fresh']=miner_pool_observation_recent($item['observed_at'],$now);
        }
        unset($item['wallet_ids']);
    }
    unset($item);
    $items=array_values($byAddress);
    $ready=$items!==[] && !$tooMany;
    $pending=0.0;$confirmed=0.0;$sumWorkers=0;$rates=0.0;
    $allWorkers=true;$allRates=true;
    foreach($items as $item) {
        if (!$item['fresh'] || $item['pending']===null || $item['confirmed']===null) $ready=false;
        if ($item['fresh'] && $item['pending']!==null && $item['confirmed']!==null) {
            $pending+=(float)$item['pending'];
            $confirmed+=(float)$item['confirmed'];
        }
        if(!$item['fresh'] || $item['workers']===null) $allWorkers=false;
        else $sumWorkers+=$item['workers'];
        if(!$item['fresh'] || $item['hashrate_ths']===null) $allRates=false;
        else $rates+=(float)$item['hashrate_ths'];
    }
    return [
       'wallets'=>$items,'distinct_wallets'=>count($items),
       'too_many'=>$tooMany,
       'all_balances_fresh'=>$ready,
       'pending_prl'=>$ready?number_format($pending,8,'.',''):null,
       'confirmed_prl'=>$ready?number_format($confirmed,8,'.',''):null,
       'workers'=>$items!==[] && $allWorkers && !$tooMany?$sumWorkers:null,
       'hashrate_ths'=>$items!==[] && $allRates && !$tooMany?number_format($rates,2,'.',''):null
    ];
}
