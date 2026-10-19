<?php
declare(strict_types=1);
// Dedicated disposable CI database only. Never load the production runtime.
if (!str_contains(getenv('MINER_DB_DSN') ?: '', 'dbname=hache_miner_vast_test;')) {
    throw new RuntimeException('Dedicated Vast test database required');
}
require_once dirname(__DIR__).'/app/vast-auto-stop.php';
function verify(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
$db=miner_db();
verify($db->query('SELECT DATABASE()')->fetchColumn()==='hache_miner_vast_test','test database');
$db->exec(file_get_contents(dirname(__DIR__).'/database/009_vast_telemetry.sql'));
verify((int)$db->query('SELECT COUNT(*) FROM vast_instances')->fetchColumn()===0,'empty test database required');
$db->exec("INSERT INTO vast_instances(instance_id,machine_id,label,gpu_count,hourly_usd,updated_at) VALUES('56667162','test','fixture',8,0,UTC_TIMESTAMP())");
$key=str_repeat('test-only-',4);putenv('MINER_VAST_INGEST_KEY='.$key);
$now=time();$envelope=(string)$now;
$gpu=[];for($i=0;$i<8;$i++)$gpu[]=['index'=>$i,'model'=>'RTX 5090','hashrate_ths'=>$i%2?383.25:0,'average_ths'=>null,'temp_c'=>68,'watts'=>497.4,'state'=>$i%2?'mining':'inactive'];
$payload=['instance_id'=>'56667162','total_ths'=>1533,'accepted_shares'=>100,'rejected_shares'=>0,'gpus'=>$gpu];
for($n=0;$n<4;$n++) {
    $payload['sample_id']=sprintf('00000000-0000-4000-8000-%012d',$n);
    $payload['observed_at']=gmdate('Y-m-d\TH:i:s\Z',$now-90+30*$n);
    $raw=json_encode($payload,JSON_THROW_ON_ERROR);
    $signature=hash_hmac('sha256',$envelope.'.'.$raw,$key);
    miner_vast_ingest($raw,$envelope,$signature);
    miner_vast_ingest($raw,$envelope,$signature); // idempotent retry
}
verify((int)$db->query('SELECT COUNT(*) FROM vast_samples')->fetchColumn()===4,'four independent samples');
verify((int)$db->query('SELECT COUNT(*) FROM vast_gpu_samples')->fetchColumn()===32,'all eight GPU rows stored');
verify((int)$db->query("SELECT COUNT(*) FROM vast_gpu_samples WHERE hashrate_ths=0 AND state='inactive'")->fetchColumn()===16,'zero GPUs stored as zero');
verify((float)$db->query('SELECT MIN(total_ths) FROM vast_samples')->fetchColumn()===1533.0,'degraded total stored');
$snapshot=miner_vast_wd_snapshot()[0];
verify(miner_vast_stop_reason($snapshot['instance'],$snapshot['samples'],$now,$now-400)==='hashrate_low','stored readings trigger pure low-hash decision');
verify(miner_vast_stop_reason($snapshot['instance'],$snapshot['samples'],$now+180,$now-400)==='telemetry_lost','stored readings expire');
try {
    miner_vast_ingest($raw,$envelope,str_repeat('0',64));
    throw new RuntimeException('Bad HMAC accepted');
} catch(InvalidArgumentException) {}
verify((int)$db->query('SELECT COUNT(*) FROM vast_samples')->fetchColumn()===4,'bad signature writes nothing');
echo "vast MariaDB ingest integration ok (no provider actions)\n";
