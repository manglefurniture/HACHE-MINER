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
