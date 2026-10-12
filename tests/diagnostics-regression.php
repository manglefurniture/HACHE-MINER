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
$missing=miner_rate_catalog_health(['status'=>'ok'],null,$now);
diagnostics_assert($missing['status']==='sin_tarifas' && $missing['entries']===0,'Missing catalog must remain unknown, never zero cost');
diagnostics_assert(miner_rate_catalog_health_label('tarifas_parciales')==='Última tarifa parcial','Partial catalog label missing');
$source=file_get_contents(__DIR__.'/../app/diagnostics.php');
diagnostics_assert(str_contains($source,'SELECT MAX(observed_at) AS observed_at,COUNT(*) AS entries FROM salad_gpu_prices'),'Diagnostics must reuse persisted price cache');
diagnostics_assert(!str_contains($source,'curl_init('),'Diagnostics must not make external API calls');
$view=file_get_contents(__DIR__.'/../public/index.php');
diagnostics_assert(str_contains($view,'Catálogos de tarifas recientes') && str_contains($view,'Último error'),'Admin diagnostics must expose freshness and prior errors');
echo "DIAGNOSTICS_REGRESSION_OK\n";
