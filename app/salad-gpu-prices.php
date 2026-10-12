<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';
require_once __DIR__.'/salad-gpu-identity.php';

/** Prices are USD per GPU-hour, as returned by authenticated SaladCloud GPU Classes. */
function miner_salad_price_rows(array $catalog): array
{
    $rows=[];
    foreach($catalog as $gpu) {
        if(!is_array($gpu))continue;
        $id=(string)($gpu['id']??'');
        if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,119}$/D',$id))continue;
        $prices=$gpu['prices']??null;
        if(!is_array($prices))continue;
        foreach($prices as $entry) {
            if(!is_array($entry))continue;
            $priority=strtolower((string)($entry['priority']??''));
            // Salad API names the Lowest tier 'batch'; normalize for the UI.
            if($priority==='batch')$priority='lowest';
            $raw=$entry['price']??null;
            if(!in_array($priority,['lowest','low','medium','high'],true)
                || !is_string($raw) && !is_int($raw) && !is_float($raw))continue;
            $raw=trim((string)$raw);
            // Require a positive, bounded decimal in the column's exact precision.
            // Never silently turn invalid input into 0 or round unknown units.
            if(!preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\\.[0-9]{1,6})?$/D',$raw)
                || (float)$raw<=0 || (float)$raw>100)continue;
            $value=number_format((float)$raw,6,'.','');
            $key=$id."\0".$priority;
            if(isset($rows[$key]) && $rows[$key]['usd_per_hour']!==$value)
                throw new UnexpectedValueException('Contradictory official Salad price');
            $rows[$key]=['gpu_class'=>$id,'priority'=>$priority,'usd_per_hour'=>$value];
        }
    }
    return array_values($rows);
}
function miner_salad_prices_ready(PDO $db):bool
{
    try {
        $db->query('SELECT organization,gpu_class,priority,usd_per_hour FROM salad_gpu_prices LIMIT 0');
        $db->query('SELECT 1 FROM salad_gpu_price_changes LIMIT 0');
        $db->query('SELECT gpu_class_at_observation,priority_at_observation,official_rate_usd_per_hour FROM miner_observations LIMIT 0');
        return true;
    }
    catch(PDOException $e) {
        if(in_array((string)$e->getCode(),['42S02','42S22'],true))return false;
        throw $e;
    }
}
/** Never fall back to legacy manual entries if migration or recent API evidence is missing. */
function miner_salad_current_prices(PDO $db):array
{
    if(!miner_salad_prices_ready($db))return [];
    return $db->query("SELECT organization,gpu_class,priority,usd_per_hour,observed_at
       FROM salad_gpu_prices WHERE observed_at>=UTC_TIMESTAMP()-INTERVAL 90 MINUTE
       ORDER BY organization,gpu_class,priority")->fetchAll(PDO::FETCH_ASSOC);
}
function miner_salad_price_for(PDO $db,string $org,string $gpu,string $priority):?string
{
    if($gpu===''||!miner_salad_prices_ready($db))return null;
    if($priority==='batch')$priority='lowest';
    $stmt=$db->prepare("SELECT usd_per_hour FROM salad_gpu_prices
        WHERE organization=? AND gpu_class=? AND priority=?
          AND observed_at>=UTC_TIMESTAMP()-INTERVAL 90 MINUTE");
    $stmt->execute([$org,$gpu,$priority]);
    $value=$stmt->fetchColumn();
    return $value===false?null:(string)$value;
}
/** Call at most once per organization in a 30-minute window, including failures. */
function miner_salad_price_sync_due(PDO $db,string $org):bool
{
    if(!miner_salad_prices_ready($db))return false;
    $stmt=$db->prepare("SELECT 1 FROM sync_runs WHERE source_name=?
        AND observed_at>=UTC_TIMESTAMP()-INTERVAL 30 MINUTE LIMIT 1");
    $stmt->execute(['salad-rates:'.$org]);
    return !$stmt->fetchColumn();
}
/** Atomic replacement of authenticated class/priority rates, with change-only price history. */
function miner_salad_price_sync(PDO $db,string $org,array $catalog):int
{
    if(!miner_valid_salad_slug($org))throw new InvalidArgumentException('Invalid org');
    $rates=miner_salad_price_rows($catalog);
    if($rates===[])throw new UnexpectedValueException('No valid Salad GPU price data');
    if(!miner_salad_prices_ready($db))throw new RuntimeException('Official price schema missing');
    $select=$db->prepare('SELECT usd_per_hour FROM salad_gpu_prices
        WHERE organization=? AND gpu_class=? AND priority=? FOR UPDATE');
    $upsert=$db->prepare("INSERT INTO salad_gpu_prices
      (organization,gpu_class,priority,usd_per_hour,observed_at)
      VALUES (?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE
      usd_per_hour=VALUES(usd_per_hour),observed_at=VALUES(observed_at)");
    $history=$db->prepare("INSERT INTO salad_gpu_price_changes
      (organization,gpu_class,priority,usd_per_hour,observed_at)
      VALUES (?,?,?,?,UTC_TIMESTAMP())");
    // Persist official catalog display names from the SAME authenticated
    // response as the rates. Never derive a class from a group label.
    $saveNames=null;
    if (function_exists('miner_gpu_names_ready') && miner_gpu_names_ready($db)) {
        $saveNames=$db->prepare("INSERT INTO salad_gpu_class_names
          (organization,gpu_class,name,observed_at)
          VALUES (?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE
          name=VALUES(name),observed_at=VALUES(observed_at)");
    }
    $db->beginTransaction();
    try{
        foreach($rates as $rate){
            $key=[$org,$rate['gpu_class'],$rate['priority']];
            $select->execute($key);
            $previous=$select->fetchColumn();
            $amount=$rate['usd_per_hour'];
            if($previous===false || number_format((float)$previous,6,'.','')!==$amount)
                $history->execute([...$key,$amount]);
            $upsert->execute([...$key,$amount]);
        }
        if($saveNames!==null) {
            foreach($catalog as $gpu) {
                if(!is_array($gpu))continue;
                $id=(string)($gpu['id']??'');
                $name=trim((string)($gpu['name']??''));
                if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,119}$/D',$id)
                    || $name==='' || mb_strlen($name)>100)continue;
                $saveNames->execute([$org,$id,$name]);
            }
        }
        $db->commit();
    }catch(Throwable $e){$db->rollBack();throw $e;}
    return count($rates);
}
