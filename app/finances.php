<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';

/**
 * Selectable historical Salad GPU class IDs for manual hourly-rate entry.
 * The group table retains observed classes even when their groups stop;
 * the rate catalog adds classes previously configured but no longer used.
 * Never substitute a miner's display model for Salad's exact class ID.
 */
function miner_gpu_rate_class_choices(array $observedGroups,array $existingRates):array {
    $seen=[];
    foreach([$observedGroups,$existingRates] as $rows) {
        foreach($rows as $r) {
            if(!is_array($r))continue;
            $class=$r['gpu_class']??null;
            if(!is_string($class))continue;
            $class=trim($class);
            if($class===''||strlen($class)>120
                ||in_array(strtolower($class),['unknown','null','none','n/a','desconocida'],true))continue;
            // Prefix the deduplication key: PHP casts numeric-string array
            // keys (e.g. "123") into integers, but miner_h() requires string.
            // Preserve exactly the original Salad class ID in array values.
            $seen['class:'.$class]=$class;
        }
    }
    $options=array_values($seen);
    sort($options,SORT_NATURAL|SORT_FLAG_CASE);
    return $options;
}

/**
 * Expenses, explicitly separated into:
 * 1. amounts manually reconciled with Salad invoices
 * 2. partial observation-derived estimates from confirmed price catalog
 * There is no automatic revenue attribution across shared wallets.
 */
function miner_finance_overview(): array {
    $db=miner_db();
    $targets=miner_salad_targets(false);
    $orgs=[];
    foreach($targets as $target) {
        $slug=(string)$target['organization_slug'];
        if(!isset($orgs[$slug]))$orgs[$slug]=[
            'org'=>$slug,'projects'=>0,'charges_usd'=>null,
            'charge_count'=>0,'last_charge_at'=>null,
            'estimated_24h_usd'=>null,'priced_samples'=>0,
            'unpriced_samples'=>0
        ];
        $orgs[$slug]['projects']++;
    }
    $actual=$db->query('SELECT organization,COUNT(*) n,SUM(amount_usd) total,MAX(period_end) latest FROM reconciled_charges GROUP BY organization')->fetchAll(PDO::FETCH_ASSOC);
    foreach($actual as $a) {
        if(!isset($orgs[$a['organization']]))continue;
        $orgs[$a['organization']]['charge_count']=(int)$a['n'];
        $orgs[$a['organization']]['charges_usd']=(string)$a['total'];
        $orgs[$a['organization']]['last_charge_at']=$a['latest'];
    }
    // Only price-at-observation records are summed. No hourly extrapolation
    // from replicas requested, no dynamic price guesses and no invoice claims.
    $sql="SELECT g.organization,
        SUM(CASE WHEN m.estimated_cost_usd IS NOT NULL THEN m.estimated_cost_usd ELSE 0 END) amount,
        SUM(CASE WHEN m.estimated_cost_usd IS NOT NULL THEN 1 ELSE 0 END) priced,
        SUM(CASE WHEN m.estimated_cost_usd IS NULL AND m.ready=1 AND m.started=1 THEN 1 ELSE 0 END) unpriced
      FROM miner_observations m JOIN group_state g ON g.id=m.group_id
      WHERE m.observed_at>=UTC_TIMESTAMP()-INTERVAL 24 HOUR
      GROUP BY g.organization";
    foreach($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $org=(string)$row['organization'];
        if (!isset($orgs[$org]))continue;
        $orgs[$org]['priced_samples']=(int)$row['priced'];
        $orgs[$org]['unpriced_samples']=(int)$row['unpriced'];
        if ((int)$row['priced']>0) $orgs[$org]['estimated_24h_usd']=(string)$row['amount'];
    }
    return array_values($orgs);
}


/** Parse the official read-only Salad GPU catalog: id is the exact Salad
 * GPU class identifier used by container groups; name is display-only.
 * Deliberately does NOT interpret class names as billing prices.
 */
function miner_rate_parse_salad_gpu_classes(array $payload): array
{
    $items=$payload['items']??null;
    if (!is_array($items) || count($items)>1500) throw new UnexpectedValueException('GPU catalog response invalid');
    $result=[];
    foreach ($items as $item) {
        if (!is_array($item))continue;
        $id=trim((string)($item['id']??''));
        $name=trim((string)($item['name']??''));
        if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,119}$/D',$id) ||
            $name==='' || mb_strlen($name)>100)continue;
        $result['gpu:'.$id]=['id'=>$id,'name'=>$name,'source'=>'salad'];
    }
    uasort($result,static fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
    return array_values($result);
}

/** API only lists classes. It does NOT guarantee stock nor determine charges. */
function miner_rate_fetch_salad_gpu_classes(string $org,string $apiKey): array
{
    if (!miner_valid_salad_slug($org) || $apiKey==='')throw new InvalidArgumentException('Invalid catalog request');
    $url='https://api.salad.com/api/public/organizations/'.rawurlencode($org).'/gpu-classes';
    $ch=curl_init($url);
    if ($ch===false)throw new RuntimeException('No GPU catalog transport');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPGET=>true,
        CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
        CURLOPT_HTTPHEADER=>['Salad-Api-Key: '.$apiKey,'Accept: application/json'],
    ]);
    try {
        $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    } finally {
        curl_close($ch);
    }
    if(!is_string($body)||strlen($body)>400000||$status!==200)
        throw new RuntimeException('GPU catalog temporarily unavailable');
    $data=json_decode($body,true,64,JSON_THROW_ON_ERROR);
    if(!is_array($data))throw new UnexpectedValueException('GPU catalog invalid');
    return miner_rate_parse_salad_gpu_classes($data);
}

/**
 * Separate GPU classes for EACH configured Salad organization and union
 * current live catalog with preserved exact historical class IDs.
 * Live API errors never cause the authenticated settings page to return 503.
 * $load is injectable for regression tests. Returns list of organization
 * groups, each with source and human-readable options.
 */
function miner_rate_gpu_catalogue(array $targets,array $observedGroups,array $rates,?callable $load=null):array
{
    $organizations=[];
    foreach($targets as $t) {
        $org=(string)($t['organization_slug']??'');
        if(miner_valid_salad_slug($org))$organizations[$org]=true;
    }
    $history=[];
    foreach([$observedGroups,$rates] as $rows) {
        foreach($rows as $r) {
            $org=(string)($r['organization']??'');
            $id=trim((string)($r['gpu_class']??''));
            if(!isset($organizations[$org]) ||
                !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,119}$/D',$id) ||
                in_array(strtolower($id),['unknown','null','none','n/a'],true))continue;
            $history[$org]['gpu:'.$id]=['id'=>$id,'name'=>$id,'source'=>'recorded'];
        }
    }
    ksort($organizations,SORT_STRING);
    $catalog=[];
    foreach(array_keys($organizations) as $org) {
        $entries=$history[$org]??[];
        $live=false;
        if($load!==null) {
            try {
                $items=$load($org);
                if(!is_array($items))throw new UnexpectedValueException('Invalid live catalog');
                foreach($items as $item) {
                    if(!is_array($item))continue;
                    $id=(string)($item['id']??'');$name=(string)($item['name']??'');
                    if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,119}$/D',$id) ||
                       $name==='' || mb_strlen($name)>100)continue;
                    $entries['gpu:'.$id]=['id'=>$id,'name'=>$name,'source'=>'salad'];
                }
                $live=true;
            } catch(Throwable $e) {
                // API failures are expected; never log secrets, URLs or org.
                error_log('[hache-miner] rate-gpu-catalog-unavailable '.get_class($e));
            }
        }
        uasort($entries,static fn($a,$b)=>strnatcasecmp($a['name'],$b['name']) ?:
            strcmp($a['id'],$b['id']));
        $catalog[]=['organization'=>$org,'live'=>$live,'items'=>array_values($entries)];
    }
    return $catalog;
}

/** A selection always contains the owning organization; rejects ambiguity. */
function miner_rate_parse_gpu_selection(string $raw): ?array
{
    $parts=explode('|',$raw,2);
    if(count($parts)!==2)return null;
    [$org,$gpu]=$parts;
    if(!miner_valid_salad_slug($org) ||
       !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,119}$/D',$gpu))return null;
    return ['organization'=>$org,'gpu_class'=>$gpu];
}

/** Lowest is a distinct billed priority; never inherit Low prices. */
function miner_rate_valid_priority(string $priority):bool
{
    return in_array($priority,['lowest','low','medium','high'],true);
}
