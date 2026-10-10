<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';

/** La etiqueta de día se calcula siempre en Cancún (UTC-05:00, sin DST). */
function miner_trial_day_cancun(string $utc): ?string
{
    $at=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$utc,new DateTimeZone('UTC'));
    return $at===false?null:$at->setTimezone(new DateTimeZone('America/Cancun'))->format('Y-m-d');
}
/** @return array<string,mixed> */
function miner_trial_history_normalize(array $item): array
{
    $samples=max(0,(int)($item['samples']??0));
    $observed=max(0,(int)($item['observed']??0));
    $instances=max(0,(int)($item['instances']??0));
    $mean=null;
    if($observed>0 && isset($item['avg_ths']) && is_numeric($item['avg_ths'])) {
        $value=(float)$item['avg_ths'];
        if(is_finite($value) && $value>=0 && $value<=20000) $mean=round($value,2);
    }
    return [
        'day'=>(string)($item['day_cancun']??''),
        'organization'=>(string)($item['organization']??''),
        'project'=>(string)($item['project_name']??''),
        'group'=>(string)($item['group_name']??''),
        'priority_current'=>(string)($item['priority']??''),
        'gpu'=>(string)($item['model_key']??''),
        'samples'=>$samples,
        'observed'=>$observed,
        'instances'=>$instances,
        'avg_ths'=>$mean,
        'last_utc'=>(string)($item['last_utc']??'')
    ];
}
/**
 * Usa mediciones individuales ya persistidas por el collector; no abre
 * conexiones adicionales a Salad ni presume PRL generados por GPU.
 *
 * "priority_current" procede de group_state: es el nivel ACTUAL y no se
 * atribuye retroactivamente como evidencia de la prioridad del pasado.
 */
function miner_trial_history_compare(): array
{
    $sql="SELECT DATE(m.observed_at - INTERVAL 5 HOUR) day_cancun,
        g.organization,g.project_name,g.group_name,
        COALESCE(g.priority,'desconocida') priority,
        COALESCE(NULLIF(m.gpu_model,''),identified.gpu_model,'Sin identificación') model_key,
        COUNT(*) samples,
        COUNT(DISTINCT m.instance_id) instances,
        SUM(CASE WHEN m.ready=1 AND m.started=1 AND m.state='running'
                AND m.hashrate_ths>0 THEN 1 ELSE 0 END) observed,
        AVG(CASE WHEN m.ready=1 AND m.started=1 AND m.state='running'
                AND m.hashrate_ths>0 THEN m.hashrate_ths ELSE NULL END) avg_ths,
        MAX(m.observed_at) last_utc
      FROM miner_observations m
      INNER JOIN group_state g ON g.id=m.group_id
      LEFT JOIN (
          -- A missing model may inherit identity ONLY if the same instance
          -- had exactly one known GPU on that same local day.
          SELECT group_id,instance_id,
                 DATE(observed_at - INTERVAL 5 HOUR) local_day,
                 CASE WHEN COUNT(DISTINCT NULLIF(gpu_model,''))=1
                      THEN MAX(NULLIF(gpu_model,'')) ELSE NULL END gpu_model
          FROM miner_observations
          WHERE observed_at>=UTC_TIMESTAMP()-INTERVAL 8 DAY
          GROUP BY group_id,instance_id,local_day
      ) identified
        ON identified.group_id=m.group_id AND identified.instance_id=m.instance_id
       AND identified.local_day=DATE(m.observed_at - INTERVAL 5 HOUR)
      WHERE m.observed_at>=UTC_TIMESTAMP()-INTERVAL 8 DAY
        AND DATE(m.observed_at - INTERVAL 5 HOUR)
            >=DATE(UTC_TIMESTAMP() - INTERVAL 5 HOUR)-INTERVAL 6 DAY
      GROUP BY day_cancun,g.id,g.organization,g.project_name,g.group_name,
               g.priority,model_key
      ORDER BY day_cancun DESC,g.organization,g.project_name,g.group_name,model_key
      LIMIT 601";
    $rows=miner_db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $partial=count($rows)>600;
    if($partial)$rows=array_slice($rows,0,600);
    return [
      'days'=>7,
      'partial'=>$partial,
      'rows'=>array_map('miner_trial_history_normalize',$rows),
    ];
}
