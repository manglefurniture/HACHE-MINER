<?php
declare(strict_types=1);
require_once __DIR__.'/../app/diagnostics.php';
function diagnostics_assert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
$now=strtotime('2026-10-12 12:00:00 UTC');
$fresh=miner_rate_catalog_health(['status'=>'ok'],['observed_at'=>'2026-10-12 11:30:00','entries'=>8],$now);
diagnostics_assert($fresh['status']==='tarifas_recientes' && $fresh['entries']===8,'Fresh official catalog must remain usable');
$partial=miner_rate_catalog_health(['status'=>'partial'],['observed_at'=>'2026-10-12 11:30:00','entries'=>8],$now);
diagnostics_assert($partial['status']==='tarifas_parciales','Partial refresh must not be described as fully current');
$stale=miner_rate_catalog_health(['status'=>'ok'],['observed_at'=>'2026-10-12 10:29:59','entries'=>8],$now);
diagnostics_assert($stale['status']==='tarifas_atrasadas','Catalog older than 90 minutes must be stale');
$staleRows=miner_rate_catalog_health(['status'=>'ok'],['observed_at'=>'2026-10-12 10:29:59','entries'=>0],$now);
diagnostics_assert($staleRows['status']==='tarifas_atrasadas','Expired rows must not be counted as current tariffs');
$missing=miner_rate_catalog_health(['status'=>'ok'],null,$now);
diagnostics_assert($missing['status']==='sin_tarifas' && $missing['entries']===0,'Missing catalog must remain unknown, never zero cost');
diagnostics_assert(miner_rate_catalog_health_label('tarifas_parciales')==='Última tarifa parcial','Partial catalog label missing');
$source=file_get_contents(__DIR__.'/../app/diagnostics.php');
diagnostics_assert(str_contains($source,'SUM(observed_at>=UTC_TIMESTAMP()-INTERVAL 90 MINUTE) AS entries'),'Diagnostics must count only the current persisted price snapshot');
diagnostics_assert(str_contains($source,'$rateHealthByOrganization'),'Rate catalog totals must be deduplicated per organization');
diagnostics_assert(!str_contains($source,'curl_init('),'Diagnostics must not make external API calls');
diagnostics_assert(str_contains($source,"miner_log_sync_source_key('salad:'"),
    'Long Salad source names must be normalized in diagnostic read paths');
$view=file_get_contents(__DIR__.'/../public/index.php');
diagnostics_assert(str_contains($view,'Catálogos de tarifas recientes') && str_contains($view,'Último error'),'Admin diagnostics must expose freshness and prior errors');
echo "DIAGNOSTICS_REGRESSION_OK\n";
