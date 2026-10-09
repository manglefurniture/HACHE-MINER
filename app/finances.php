<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';

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
