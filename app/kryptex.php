<?php
declare(strict_types=1);

/**
 * Kryptex PRL public API mapping, observed against:
 * GET /prl/api/v1/miner/balance/{address}
 * GET /prl/api/v3/miner/workers/{address}
 * All PRL amounts are coin units, not USD or wallet balances.
 */
function miner_kryptex_amount(mixed $amount): string {
    if (!is_int($amount) && !is_float($amount) && !is_string($amount)) {
        throw new UnexpectedValueException('Missing numeric PRL amount');
    }
    if (!is_numeric($amount) || !is_finite((float)$amount)
        || (float)$amount<0 || (float)$amount>=1000000000000) {
        throw new UnexpectedValueException('Invalid PRL amount');
    }
    return number_format((float)$amount,8,'.','');
}
function miner_kryptex_balance(array $data): array {
    if (!array_key_exists('unconfirmed',$data) || !array_key_exists('confirmed',$data)) {
        throw new UnexpectedValueException('Unknown Kryptex balance schema');
    }
    $pending=miner_kryptex_amount($data['unconfirmed']);
    $confirmed=miner_kryptex_amount($data['confirmed']);
    return ['pending_prl'=>$pending,'confirmed_prl'=>$confirmed];
}

/**
 * Hashrate comes from the pool, measured in H/s over 30 minutes.
 * Not per Salad group/GPU and not proof of accepted shares at this instant.
 */
function miner_kryptex_workers(array $data): array {
    if (!isset($data['results']) || !is_array($data['results']) || count($data['results'])>10000) {
        throw new UnexpectedValueException('Unknown Kryptex workers schema');
    }
    $online=0;$hashTotal=0.0;$hashSamples=0;$missingRate=0;
    $seen=[];
    foreach($data['results'] as $item) {
        if (!is_array($item)) throw new UnexpectedValueException('Invalid worker');
        if (($item['status']??'')!=='online') continue;
        $name=(string)($item['worker']??'');
        $scheme=(string)($item['scheme']??'');
        if ($name==='' || strlen($name)>256 || strlen($scheme)>30) throw new UnexpectedValueException('Invalid worker identity');
        $id=$name."\0".$scheme;
        if (isset($seen[$id])) continue;
        $seen[$id]=true;
        $online++;
        $rate=$item['avg_hashrate_30m']??null;
        if ((is_string($rate) || is_numeric($rate)) && is_numeric($rate)
            && is_finite((float)$rate) && (float)$rate>=0 && (float)$rate<1e24) {
            $hashTotal+=(float)$rate;
            $hashSamples++;
        } else $missingRate++;
    }
    return [
        'worker_count'=>$online,
        'hashrate_raw'=>$online===0?'0':($missingRate===0 && $hashSamples===$online ? sprintf('%.0f',$hashTotal) : null),
        'partial'=>$missingRate>0
    ];
}
