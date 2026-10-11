<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';

/** Official GPU catalog labels, not arbitrary names inferred from miner logs. */
function miner_gpu_names_ready(PDO $db):bool {
    try {$db->query('SELECT organization,gpu_class,name,observed_at FROM salad_gpu_class_names LIMIT 0');return true;}
    catch(PDOException $e) {
        if(in_array((string)$e->getCode(),['42S02','42S22'],true))return false;
        throw $e;
    }
}
function miner_gpu_catalog_names(PDO $db,string $org):array {
    if(!miner_gpu_names_ready($db))return [];
    $st=$db->prepare("SELECT gpu_class,name FROM salad_gpu_class_names
        WHERE organization=? AND observed_at>=UTC_TIMESTAMP()-INTERVAL 90 MINUTE");
    $st->execute([$org]);
    $names=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)
        $names[(string)$row['gpu_class']]=(string)$row['name'];
    return $names;
}
/**
 * Strict comparison against the official name. Avoid substring matching:
 * 5060 != 5060 Ti, Laptop != desktop, and memory variants may be distinct.
 * Return NULL when the miner name is truncated or multiple allowed classes
 * normalize to the same model, even when their current prices coincide.
 */
function miner_gpu_model_key(string $name):?string {
    if($name==='' || preg_match('/\.\.\.|…/u',$name))return null;
    $name=preg_replace('/\([^)]*\b(?:gb|gib|mb)\b[^)]*\)/i',' ', $name)??$name;
    $name=preg_replace('/\b\d+\s*(?:gb|gib|mb)\b/i',' ', $name)??$name;
    $name=preg_replace('/\b(?:nvidia|geforce|graphics|gpu|card)\b/i',' ',$name)??$name;
    $key=strtolower(preg_replace('/[^a-z0-9]+/i','',$name)??'');
    return strlen($key)>=5 && strlen($key)<=100 ? $key:null;
}
function miner_gpu_class_for_model(array $allowedIds,string $logModel,array $catalogNames):?string {
    $wanted=miner_gpu_model_key($logModel);
    if($wanted===null)return null;
    $matches=[];
    foreach($allowedIds as $id) {
        if(!is_string($id) || !isset($catalogNames[$id]))continue;
        if(miner_gpu_model_key($catalogNames[$id])===$wanted)$matches[$id]=true;
    }
    return count($matches)===1?(string)array_key_first($matches):null;
}
/** Only continue a class already proven for THIS exact running instance. */
function miner_gpu_previous_running_class(PDO $db,int $groupId,string $instanceId,string $priority,
    string $now,array $allowedIds):?string {
    $st=$db->prepare('SELECT observed_at,state,priority_at_observation,gpu_class_at_observation
        FROM miner_observations WHERE group_id=? AND instance_id=? ORDER BY observed_at DESC LIMIT 1');
    $st->execute([$groupId,$instanceId]);
    $old=$st->fetch(PDO::FETCH_ASSOC);
    if(!$old || $old['state']!=='running' || $old['priority_at_observation']!==$priority)return null;
    $elapsed=strtotime($now)-strtotime((string)$old['observed_at']);
    if($elapsed===false || $elapsed<=0 || $elapsed>420)return null;
    $known=(string)($old['gpu_class_at_observation']??'');
    return $known!=='' && in_array($known,$allowedIds,true)?$known:null;
}
