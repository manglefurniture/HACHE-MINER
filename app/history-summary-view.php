<?php
// Read-only consolidated history; data calculated in app/history-summary.php.
?>
<section class="card">
<h2>Resumen acumulado por modelo de GPU</h2>
<p class="muted">Una sola fila por modelo, sumando todas las organizaciones y grupos. Dos GPU trabajando una hora suman dos horas-GPU. Tiempo observado entre sondeos running consecutivos, incluso sin hashrate ni shares; no es factura de Salad.</p>
<?php if(!empty($modelHistory['unavailable'])): ?>
<p class="warn"><?= miner_h($modelHistory['reason']??'Resumen temporalmente no disponible') ?></p>
<?php else: ?>
<p class="muted"><?= (int)$modelHistory['scanned'] ?> observaciones analizadas. Desde <?= miner_h($modelHistory['start_utc']??'sin datos') ?> hasta <?= miner_h($modelHistory['end_utc']??'sin datos') ?> UTC.
<?php if(!empty($modelHistory['partial'])): ?><strong>Cobertura parcial por límite de procesamiento; no representa todo el historial.</strong><?php endif; ?></p>
<?php
$historyAlerts=[];
if(empty($shareLearning['unavailable']))foreach(($shareLearning['devices']??[]) as $device) {
    $key=miner_share_gpu_family((string)($device['gpu']??''));
    if($key!=='' && ($device['signal']??'')==='watch')
        $historyAlerts[$key]=($historyAlerts[$key]??0)+1;
}
?>
<?php if(empty($modelHistory['rows'])): ?>
<p class="muted">Todavía no hay observaciones para agrupar.</p>
<?php else: ?>
<div class="tablewrap mobile-stack">
<table>
<thead><tr><th>GPU</th><th>Sesiones</th><th>Horas-GPU observadas</th><th>Costo estimado USD</th><th>TH/s medio</th><th>Shares aceptadas</th><th>Rechazadas</th><th>Alertas recientes</th></tr></thead>
<tbody>
<?php foreach($modelHistory['rows'] as $model): ?>
<tr>
<td data-label="GPU">
<details>
<summary><strong><?= miner_h($model['gpu']==='SIN_IDENTIFICAR'?'GPU sin identificar':$model['gpu']) ?></strong></summary>
<p class="muted">Detalle por prioridad histórica registrada; «sin registro» significa que no se puede atribuir una prioridad pasada.</p>
<div class="tablewrap mobile-stack"><table>
<thead><tr><th>Prioridad</th><th>Sesiones</th><th>Horas-GPU</th><th>USD estimados</th><th>TH/s medio</th><th>Shares aceptadas</th><th>Horas sin tarifa</th></tr></thead>
<tbody>
<?php foreach($model['priorities'] as $detail): ?>
<tr>
<td data-label="Prioridad"><?= miner_h($detail['priority']==='sin_registro'?'Sin registro':ucfirst($detail['priority'])) ?></td>
<td data-label="Sesiones"><?= (int)$detail['sessions'] ?></td>
<td data-label="Horas"><?= miner_h(number_format($detail['observed_seconds']/3600,2,'.',',')) ?> h</td>
<td data-label="USD"><?= $detail['cost_intervals']>0?'$'.miner_h(number_format($detail['cost_usd'],4,'.',',')):'Sin tarifa' ?></td>
<td data-label="TH/s"><?= $detail['avg_ths']===null?'Sin lectura':miner_h(number_format($detail['avg_ths'],2,'.',',')) ?></td>
<td data-label="Shares"><?= $detail['accepted_intervals']>0?(int)$detail['accepted']:'Sin pares' ?></td>
<td data-label="Sin tarifa"><?= miner_h(number_format($detail['unpriced_seconds']/3600,2,'.',',')) ?> h</td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
</details>
</td>
<td data-label="Sesiones"><?= (int)$model['sessions'] ?></td>
<td data-label="Horas-GPU"><?= miner_h(number_format($model['observed_seconds']/3600,2,'.',',')) ?> h</td>
<td data-label="Costo USD">
<?= $model['cost_intervals']>0?'$'.miner_h(number_format($model['cost_usd'],4,'.',',')):'Sin tarifa histórica' ?>
<?php if($model['unpriced_seconds']>0): ?>
<small>Parcial: <?= miner_h(number_format($model['unpriced_seconds']/3600,2,'.',',')) ?> h sin precio oficial</small>
<?php endif; ?>
</td>
<td data-label="TH/s"><?= $model['avg_ths']===null?'Sin medición':miner_h(number_format($model['avg_ths'],2,'.',',')) ?></td>
<td data-label="Aceptadas"><?= $model['accepted_intervals']>0?(int)$model['accepted']:'Sin pares válidos' ?></td>
<td data-label="Rechazadas">Sin historial</td>
<td data-label="Alertas recientes"><?= empty($shareLearning['unavailable'])?(int)($historyAlerts[$model['gpu']]??0):'Sin cobertura' ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<?php endif; ?>
<p class="muted"><strong>Cobertura:</strong> las sesiones son tramos observados de un mismo nodo, GPU y prioridad; el tiempo excluye huecos mayores a siete minutos. El gasto se suma solo si la tarifa oficial y la clase corresponden a los dos extremos del intervalo. Shares aceptadas son incrementos de contadores acumulados, no PRL cobrado. Shares rechazadas no están almacenadas históricamente por GPU; las alertas muestran solo vigilancia informativa de las últimas treinta horas.</p>
</section>
