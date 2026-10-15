<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/vast-watchdog.php';

function wd_assert(bool $ok,string $why):void { if (!$ok) throw new RuntimeException($why); }
function wd_sample(int $id,string $observed,string $received,int $accepted,float $total):array {
    return ['id'=>$id,'observed_at'=>$observed,'received_at'=>$received,'accepted_shares'=>$accepted,'total_ths'=>$total];
}
function wd_gpu(int $index,float $ths):array {return ['gpu_index'=>$index,'hashrate_ths'=>$ths];}

$now=strtotime('2026-10-15 22:30:00 UTC');
$recent='2026-10-15 22:29:30';
$prior='2026-10-15 22:28:30';
$old='2026-10-15 22:18:30';
$i=['instance_id'=>'55983893','updated_at'=>'2026-10-15 21:00:00','gpu_count'=>2,'hourly_usd'=>1.073,'label'=>'Vast trial'];
$s0=wd_sample(50,$recent,$recent,100,755);
$s1=wd_sample(49,$prior,$prior,95,750);
$g=[50=>[wd_gpu(0,380),wd_gpu(1,375)],49=>[wd_gpu(0,378),wd_gpu(1,372)]];
wd_assert(miner_vast_wd_classify($i,[$s0,$s1],$g,$now)['code']==='ok','healthy GPU');
wd_assert(miner_vast_wd_classify($i,[],[],$now)['code']==='telemetry_missing','no telemetry while billed');
$boot=$i;$boot['updated_at']='2026-10-15 22:29:00';
wd_assert(miner_vast_wd_classify($boot,[],[],$now)['code']==='ok','provisioning grace');
$stale=wd_sample(50,'2026-10-15 22:26:00','2026-10-15 22:26:00',100,755);
wd_assert(miner_vast_wd_classify($i,[$stale],$g,$now)['code']==='telemetry_lost','stale collector');
$gZero=[50=>[wd_gpu(0,0),wd_gpu(1,375)],49=>[wd_gpu(0,0),wd_gpu(1,372)]];
wd_assert(miner_vast_wd_classify($i,[$s0,$s1],$gZero,$now)['code']==='gpu_zero_0','two zeros required');
$gBrief=[50=>[wd_gpu(0,0),wd_gpu(1,375)],49=>[wd_gpu(0,378),wd_gpu(1,372)]];
wd_assert(miner_vast_wd_classify($i,[$s0,$s1],$gBrief,$now)['code']==='ok','one zero insufficient');
wd_assert(miner_vast_wd_classify($i,[$s0,$s1],[], $now)['code']==='gpu_telemetry_incomplete','no GPU table');
$history=[];
for($n=0;$n<12;$n++){
    $at=gmdate('Y-m-d H:i:s',$now-30-$n*60);
    $history[]=wd_sample(50-$n,$at,$at,50,755);
}
wd_assert(miner_vast_wd_classify($i,$history,$g,$now)['code']==='shares_stalled','no new shares 10 minutes');
$history[0]['accepted_shares']=51;
wd_assert(miner_vast_wd_classify($i,$history,$g,$now)['code']==='ok','new shares clear alert');
wd_assert(miner_vast_wd_unix('2026-10-15 22:29:30')===$now-30,'UTC timestamps');
$dir=sys_get_temp_dir().'/vast-watchdog-'.bin2hex(random_bytes(5));mkdir($dir,0700);
try {
    $state=$dir.'/state.json';
    $snap=[['instance'=>$i,'samples'=>[$stale],'gpus'=>[50=>$g[50]]]];
    $res=miner_vast_wd_run($snap,$state,'',$now,true);
    wd_assert($res['issues']===1 && $res['sent']===0,'dry run evaluates without sending');
    wd_assert(!file_exists($state),'dry run does not write state');
}finally{foreach(glob($dir.'/*')?:[] as $f)unlink($f);rmdir($dir);}
echo "vast watchdog regression ok\n";
