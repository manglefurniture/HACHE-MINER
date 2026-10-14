<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';

/** Private ingest key is deliberately outside Git.  A single trial key is least privilege. */
function miner_vast_ingest_key(): string {
    $key=getenv('MINER_VAST_INGEST_KEY') ?: '';
    if (strlen($key)<32 || strlen($key)>512) throw new RuntimeException('Vast ingest unavailable');
    return $key;
}
function miner_vast_schema_ready(): bool {
    try { return (int)miner_db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('vast_instances','vast_samples','vast_gpu_samples')")->fetchColumn()===3; }
    catch(Throwable) { return false; }
}
function miner_vast_valid_number(mixed $value,float $max): ?float {
    if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<0||(float)$value>$max)return null;
    return (float)$value;
}
function miner_vast_ingest(string $raw,string $timestamp,string $signature): void {
    if(strlen($raw)<2||strlen($raw)>16384||!preg_match('/^\d{10}$/D',$timestamp)||abs(time()-(int)$timestamp)>150)throw new InvalidArgumentException('Invalid envelope');
    $expected=hash_hmac('sha256',$timestamp.'.'.$raw,miner_vast_ingest_key());
    if(!preg_match('/^[a-f0-9]{64}$/D',$signature)||!hash_equals($expected,$signature))throw new InvalidArgumentException('Invalid signature');
    $p=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if(!is_array($p)||!preg_match('/^[0-9]{4,64}$/D',(string)($p['instance_id']??''))||!preg_match('/^[0-9a-f-]{36}$/D',(string)($p['sample_id']??'')))throw new InvalidArgumentException('Invalid payload');
    $observed=DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339,(string)($p['observed_at']??''));
    if(!$observed||abs(time()-$observed->getTimestamp())>180)throw new InvalidArgumentException('Stale payload');
    $gpus=$p['gpus']??null; if(!is_array($gpus)||count($gpus)<1||count($gpus)>16)throw new InvalidArgumentException('Invalid GPU payload');
    $db=miner_db();$db->beginTransaction();
    try {
        $known=$db->prepare('SELECT gpu_count FROM vast_instances WHERE instance_id=? AND enabled=1');$known->execute([$p['instance_id']]);$count=$known->fetchColumn();
        if($count===false||count($gpus)!==(int)$count)throw new InvalidArgumentException('Unknown instance');
        $total=miner_vast_valid_number($p['total_ths']??null,20000);
        $acc=filter_var($p['accepted_shares']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);$rej=filter_var($p['rejected_shares']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        $insert=$db->prepare('INSERT IGNORE INTO vast_samples(instance_id,sample_id,observed_at,received_at,total_ths,accepted_shares,rejected_shares) VALUES(?,?,?,UTC_TIMESTAMP(),?,?,?)');
        $insert->execute([$p['instance_id'],$p['sample_id'],$observed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),$total,$acc===false?null:$acc,$rej===false?null:$rej]);
        if($insert->rowCount()===0){$db->commit();return;}
        $sid=(int)$db->lastInsertId();$seen=[];$gpuInsert=$db->prepare('INSERT INTO vast_gpu_samples(sample_id,gpu_index,gpu_model,hashrate_ths,average_ths,temp_c,watts,state) VALUES(?,?,?,?,?,?,?,?)');
        foreach($gpus as $g){
            $index=is_array($g)?filter_var($g['index']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>15]]):false;
            if($index===false)throw new InvalidArgumentException('Invalid GPU');$i=(int)$index;
            if(isset($seen[$i])||!is_string($g['model']??null)||strlen($g['model'])<3||strlen($g['model'])>120)throw new InvalidArgumentException('Invalid GPU');
            $seen[$i]=true;$hash=miner_vast_valid_number($g['hashrate_ths']??null,20000);$avg=miner_vast_valid_number($g['average_ths']??null,20000);$temp=filter_var($g['temp_c']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>150]]);$watts=miner_vast_valid_number($g['watts']??null,1500);$state=in_array($g['state']??'', ['mining','low','inactive'],true)?$g['state']:'inactive';$gpuInsert->execute([$sid,$i,$g['model'],$hash,$avg,$temp===false?null:$temp,$watts,$state]);
        }
        $db->commit();
    } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
