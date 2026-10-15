<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';

const MINER_SALAD_TELEMETRY_JWKS = 'https://matrix-rest-api.salad.com/.well-known/workload-jwks.json';

function miner_salad_telemetry_schema_ready(): bool {
    try {
        return (int)miner_db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('salad_telemetry_instances','salad_telemetry_samples','salad_telemetry_gpu_samples')")->fetchColumn()===3;
    } catch(Throwable) { return false; }
}

/** Record identity only from a complete authenticated Salad API instance snapshot. */
function miner_salad_telemetry_inventory_sync(string $org,string $project,array $group,array $nodes): void {
    if(!miner_salad_telemetry_schema_ready())return;
    $groupName=(string)($group['name']??'');
    $workload=(string)($group['id']??$group['container_group_id']??$group['container_group_instance_group_id']??'');
    if($groupName===''||$workload===''||strlen($workload)>120)return;
    $db=miner_db();$db->beginTransaction();
    try {
        $db->prepare('UPDATE salad_telemetry_instances SET enabled=0,last_seen_at=UTC_TIMESTAMP() WHERE organization_slug=? AND project_slug=? AND group_name=?')
            ->execute([$org,$project,$groupName]);
        $save=$db->prepare('INSERT INTO salad_telemetry_instances(organization_slug,project_slug,group_name,workload_id,instance_id,machine_id,instance_state,enabled,last_seen_at) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE workload_id=VALUES(workload_id),machine_id=VALUES(machine_id),instance_state=VALUES(instance_state),enabled=VALUES(enabled),last_seen_at=UTC_TIMESTAMP()');
        foreach($nodes as $node) {
            if(!is_array($node))continue;
            $id=(string)($node['instance_id']??$node['id']??'');
            $machine=(string)($node['machine_id']??$node['container_group_machine_id']??'');
            if(!preg_match('/^[a-zA-Z0-9_-]{1,120}$/D',$id)||!preg_match('/^[a-zA-Z0-9_-]{1,120}$/D',$machine))continue;
            $state=strtolower((string)($node['state']??'unknown'));
            $groupRunning=strtolower((string)($group['state']??''))==='running';
            $enabled=$groupRunning && $state==='running';
            $save->execute([$org,$project,$groupName,$workload,$id,$machine,$state,(int)$enabled]);
        }
        $db->commit();
    } catch(Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }
}

function miner_salad_b64url_decode(string $value): string {
    if($value===''||!preg_match('/^[A-Za-z0-9_-]+$/D',$value))throw new InvalidArgumentException('Invalid token');
    $decoded=base64_decode(strtr($value,'-_','+/').str_repeat('=',(4-strlen($value)%4)%4),true);
    if($decoded===false)throw new InvalidArgumentException('Invalid token');
    return $decoded;
}
function miner_salad_der_length(int $length): string {
    if($length<128)return chr($length);
    $bytes='';while($length>0){$bytes=chr($length&255).$bytes;$length>>=8;}
    return chr(128|strlen($bytes)).$bytes;
}
function miner_salad_der_integer(string $raw): string {
    $raw=ltrim($raw,"\0");if($raw==='')$raw="\0";if((ord($raw[0])&128)!==0)$raw="\0".$raw;
    return "\x02".miner_salad_der_length(strlen($raw)).$raw;
}
function miner_salad_ecdsa_der(string $signature): string {
    if(strlen($signature)!==64)throw new InvalidArgumentException('Invalid signature');
    $body=miner_salad_der_integer(substr($signature,0,32)).miner_salad_der_integer(substr($signature,32,32));
    return "\x30".miner_salad_der_length(strlen($body)).$body;
}
function miner_salad_workload_jwks(bool $refresh=false): array {
    $cache=sys_get_temp_dir().'/hache-salad-workload-jwks.json';
    if(!$refresh&&is_file($cache)&&time()-(int)filemtime($cache)<900){$cached=json_decode((string)file_get_contents($cache),true);if(is_array($cached))return $cached;}
    $ch=curl_init(MINER_SALAD_TELEMETRY_JWKS);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if(!is_string($body)||$status!==200||strlen($body)>65536)throw new RuntimeException('Salad identity unavailable');
    $jwks=json_decode($body,true,16,JSON_THROW_ON_ERROR);if(!is_array($jwks)||!is_array($jwks['keys']??null))throw new RuntimeException('Salad identity unavailable');
    $tmp=$cache.'.'.bin2hex(random_bytes(5));if(file_put_contents($tmp,$body,LOCK_EX)!==false){@chmod($tmp,0600);@rename($tmp,$cache);}
    return $jwks;
}
function miner_salad_telemetry_verify_jwt(string $jwt,?array $testJwks=null,?callable $jwksLoader=null): array {
    if(strlen($jwt)<100||strlen($jwt)>8192)throw new InvalidArgumentException('Invalid identity');
    $parts=explode('.',$jwt);if(count($parts)!==3)throw new InvalidArgumentException('Invalid identity');
    $header=json_decode(miner_salad_b64url_decode($parts[0]),true,8,JSON_THROW_ON_ERROR);
    $claims=json_decode(miner_salad_b64url_decode($parts[1]),true,16,JSON_THROW_ON_ERROR);
    if(!is_array($header)||($header['alg']??'')!=='ES256'||!is_string($header['kid']??null)||!is_array($claims))throw new InvalidArgumentException('Invalid identity');
    $findKey=static function(array $set)use($header):?array {
        foreach($set['keys']??[] as $candidate)if(is_array($candidate)&&($candidate['kid']??null)===$header['kid'])return $candidate;
        return null;
    };
    $jwks=$jwksLoader?$jwksLoader(false):($testJwks??miner_salad_workload_jwks());
    $key=$findKey($jwks);
    if(!$key&&!$testJwks){
        $jwks=$jwksLoader?$jwksLoader(true):miner_salad_workload_jwks(true);
        $key=$findKey($jwks);
    }
    if(!$key||($key['kty']??'')!=='EC'||($key['crv']??'')!=='P-256')throw new InvalidArgumentException('Invalid identity');
    $x=miner_salad_b64url_decode((string)($key['x']??''));$y=miner_salad_b64url_decode((string)($key['y']??''));
    if(strlen($x)!==32||strlen($y)!==32)throw new InvalidArgumentException('Invalid identity');
    $point="\x04".$x.$y;
    $spki="\x30\x59\x30\x13\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07\x03\x42\x00".$point;
    $pem="-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki),64,"\n")."-----END PUBLIC KEY-----\n";
    $public=openssl_pkey_get_public($pem);$sig=miner_salad_ecdsa_der(miner_salad_b64url_decode($parts[2]));
    if($public===false||openssl_verify($parts[0].'.'.$parts[1],$sig,$public,OPENSSL_ALGO_SHA256)!==1)throw new InvalidArgumentException('Invalid identity');
    $now=time();$exp=filter_var($claims['exp']??null,FILTER_VALIDATE_INT);$iat=filter_var($claims['iat']??null,FILTER_VALIDATE_INT);$nbf=filter_var($claims['nbf']??null,FILTER_VALIDATE_INT);
    $aud=$claims['aud']??null;
    if($exp===false||$iat===false||$nbf===false||$exp<$now||$exp<=$iat||$nbf>=$exp||$exp-$iat>600||$iat>$now+30||$nbf>$now+30
        ||(!is_string($aud)&&!is_array($aud))||$aud===[]||$aud==='')throw new InvalidArgumentException('Expired identity');
    $machine=(string)($claims['salad_machine_id']??$claims['sub']??'');
    $workload=(string)($claims['salad_workload_id']??$claims['salad_container_group_id']??'');
    $org=(string)($claims['salad_organization_name']??'');
    if(!preg_match('/^[a-zA-Z0-9_-]{1,120}$/D',$machine)||!preg_match('/^[a-zA-Z0-9_-]{1,120}$/D',$workload)||!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$org))throw new InvalidArgumentException('Incomplete identity');
    return ['machine_id'=>$machine,'workload_id'=>$workload,'organization'=>$org];
}
function miner_salad_telemetry_number(mixed $value,float $max): ?float {
    if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<0||(float)$value>$max)return null;
    return (float)$value;
}
function miner_salad_telemetry_mean(PDO $db,string $instance,int $gpu,string $observed,float $current,int $minutes): float {
    $st=$db->prepare('SELECT g.hashrate_ths FROM salad_telemetry_gpu_samples g JOIN salad_telemetry_samples s ON s.id=g.sample_id WHERE s.instance_id=? AND g.gpu_index=? AND s.observed_at>=? AND s.observed_at<? ORDER BY s.observed_at DESC LIMIT 15');
    $observedAt=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$observed,new DateTimeZone('UTC'));
    if(!$observedAt)throw new InvalidArgumentException('Invalid sample time');
    $from=$observedAt->modify('-'.$minutes.' minutes')->format('Y-m-d H:i:s');$st->execute([$instance,$gpu,$from,$observed]);
    $values=array_map('floatval',$st->fetchAll(PDO::FETCH_COLUMN));$values[]=$current;
    return array_sum($values)/count($values);
}
function miner_salad_telemetry_ingest(string $raw,string $timestamp,string $signature,string $jwt): void {
    if(!miner_salad_telemetry_schema_ready())throw new RuntimeException('Telemetry is unavailable');
    if(strlen($raw)<2||strlen($raw)>16384||!preg_match('/^\d{10}$/D',$timestamp)||abs(time()-(int)$timestamp)>90)throw new InvalidArgumentException('Invalid envelope');
    $expected=hash_hmac('sha256',$timestamp.'.'.$raw,$jwt);
    if(!preg_match('/^[a-f0-9]{64}$/D',$signature)||!hash_equals($expected,$signature))throw new InvalidArgumentException('Invalid signature');
    $claims=miner_salad_telemetry_verify_jwt($jwt);
    $p=json_decode($raw,true,24,JSON_THROW_ON_ERROR);
    $keys=['sample_id','observed_at','miner_version','state','total_ths','gpus'];sort($keys);$actual=is_array($p)?array_keys($p):[];sort($actual);
    if($actual!==$keys||!preg_match('/^[0-9a-f-]{36}$/D',(string)$p['sample_id'])||!preg_match('/^[A-Za-z0-9._+-]{1,32}$/D',(string)$p['miner_version']))throw new InvalidArgumentException('Invalid payload');
    $observed=DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339,(string)$p['observed_at']);
    if(!$observed||abs(time()-$observed->getTimestamp())>150)throw new InvalidArgumentException('Stale payload');
    if(!in_array($p['state'],['mining','inactive','error','stopped'],true))throw new InvalidArgumentException('Invalid state');
    $total=miner_salad_telemetry_number($p['total_ths'],20000);$gpus=$p['gpus'];
    if($total===null||!is_array($gpus)||count($gpus)<1||count($gpus)>16)throw new InvalidArgumentException('Invalid GPU payload');
    $db=miner_db();$db->beginTransaction();
    try {
        // Lock the mapped Salad instance row so concurrent samples for this
        // instance serialize before checking the latest observed timestamp.
        $map=$db->prepare('SELECT organization_slug,project_slug,group_name,instance_id,machine_id,workload_id FROM salad_telemetry_instances WHERE machine_id=? AND workload_id=? AND enabled=1 AND last_seen_at>=UTC_TIMESTAMP()-INTERVAL 10 MINUTE FOR UPDATE');
        $map->execute([$claims['machine_id'],$claims['workload_id']]);$matches=$map->fetchAll(PDO::FETCH_ASSOC);
        if(count($matches)!==1||$matches[0]['organization_slug']!==$claims['organization'])throw new InvalidArgumentException('Unknown or ambiguous Salad identity');
        $identity=$matches[0];$instance=(string)$identity['instance_id'];
        $observedSql=$observed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $latest=$db->prepare('SELECT MAX(observed_at) FROM salad_telemetry_samples WHERE instance_id=?');$latest->execute([$instance]);$previous=$latest->fetchColumn();
        if($previous!==null&&$observedSql<=(string)$previous)throw new InvalidArgumentException('Replayed or out-of-order sample');
        $seen=[];$rows=[];$sum=0.0;
        foreach($gpus as $gpu) {
            $gpuKeys=['index','model','hashrate_ths','avg_1m_ths','avg_5m_ths','avg_15m_ths','temp_c','watts','accepted_shares','rejected_shares','hardware_errors','state'];sort($gpuKeys);$got=is_array($gpu)?array_keys($gpu):[];sort($got);
            if($got!==$gpuKeys)throw new InvalidArgumentException('Invalid GPU fields');
            $index=filter_var($gpu['index'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>15]]);
            $rate=miner_salad_telemetry_number($gpu['hashrate_ths'],20000);$watts=miner_salad_telemetry_number($gpu['watts'],1500);
            $temp=filter_var($gpu['temp_c'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>150]]);
            if($index===false||isset($seen[$index])||!is_string($gpu['model'])||strlen($gpu['model'])<3||strlen($gpu['model'])>120||$rate===null||$watts===null||$temp===false||!in_array($gpu['state'],['mining','low','inactive','error'],true))throw new InvalidArgumentException('Invalid GPU');
            $seen[$index]=true;$sum+=$rate;
            $avgs=[];foreach([1,5,15] as $mins){$claim=miner_salad_telemetry_number($gpu['avg_'.$mins.'m_ths'],20000);if($claim===null)throw new InvalidArgumentException('Invalid average');$avgs[]=miner_salad_telemetry_mean($db,$instance,(int)$index,$observedSql,$rate,$mins);}
            $counts=[];foreach(['accepted_shares','rejected_shares','hardware_errors'] as $name){$value=filter_var($gpu[$name],FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>2000000000]]);if($value===false)throw new InvalidArgumentException('Invalid counter');$counts[]=$value;}
            $rows[]=[(int)$index,(string)$gpu['model'],$rate,$avgs[0],$avgs[1],$avgs[2],(int)$temp,$watts,...$counts,(string)$gpu['state']];
        }
        if(array_keys($seen)!==range(0,count($seen)-1)||abs($sum-$total)>max(0.1,$sum*0.01))throw new InvalidArgumentException('Inconsistent GPU set');
        $insert=$db->prepare('INSERT INTO salad_telemetry_samples(organization_slug,project_slug,group_name,instance_id,machine_id,sample_id,observed_at,received_at,miner_version,miner_state,total_ths) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(),?,?,?)');
        $insert->execute([$identity['organization_slug'],$identity['project_slug'],$identity['group_name'],$instance,$claims['machine_id'],$p['sample_id'],$observedSql,$p['miner_version'],$p['state'],$total]);
        $sampleId=(int)$db->lastInsertId();$gpuInsert=$db->prepare('INSERT INTO salad_telemetry_gpu_samples(sample_id,gpu_index,gpu_model,hashrate_ths,average_1m_ths,average_5m_ths,average_15m_ths,temp_c,watts,accepted_shares,rejected_shares,hardware_errors,state) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach($rows as $row){$row[]=$sampleId;array_unshift($row,array_pop($row));$gpuInsert->execute($row);}
        $db->commit();
    } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function miner_salad_telemetry_monitor(): array {
    if(!miner_salad_telemetry_schema_ready())return ['ready'=>false,'instances'=>[]];
    $db=miner_db();
    $instances=$db->query('SELECT i.organization_slug,i.project_slug,i.group_name,i.instance_id,i.machine_id,s.id sample_id,s.observed_at,s.received_at,s.miner_version,s.miner_state,s.total_ths FROM salad_telemetry_instances i LEFT JOIN salad_telemetry_samples s ON s.id=(SELECT MAX(x.id) FROM salad_telemetry_samples x WHERE x.instance_id=i.instance_id) WHERE i.enabled=1 ORDER BY i.organization_slug,i.project_slug,i.group_name,i.instance_id')->fetchAll(PDO::FETCH_ASSOC);
    $gpuQuery=$db->prepare('SELECT gpu_index,gpu_model,hashrate_ths,average_1m_ths,average_5m_ths,average_15m_ths,temp_c,watts,accepted_shares,rejected_shares,hardware_errors,state FROM salad_telemetry_gpu_samples WHERE sample_id=? ORDER BY gpu_index');
    $history=$db->prepare('SELECT g.hashrate_ths FROM salad_telemetry_gpu_samples g JOIN salad_telemetry_samples s ON s.id=g.sample_id WHERE s.instance_id=? AND g.gpu_index=? AND s.observed_at>=UTC_TIMESTAMP()-INTERVAL 60 MINUTE ORDER BY s.observed_at');
    foreach($instances as &$instance){$sample=$instance['sample_id']!==null;$instance['stale']=!$sample||strtotime((string)$instance['received_at'])<time()-180;$instance['gpus']=[];if($sample){$gpuQuery->execute([(int)$instance['sample_id']]);$instance['gpus']=$gpuQuery->fetchAll(PDO::FETCH_ASSOC);foreach($instance['gpus'] as &$gpu){$history->execute([$instance['instance_id'],(int)$gpu['gpu_index']]);$gpu['history']=array_map('floatval',$history->fetchAll(PDO::FETCH_COLUMN));$gpu['sparkline']=function_exists('miner_monitor_sparkline')?miner_monitor_sparkline($gpu['history']):null;}unset($gpu);}}
    return ['ready'=>true,'instances'=>$instances];
}
