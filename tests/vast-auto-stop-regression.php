<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/vast-auto-stop.php';
function check(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$now=strtotime('2026-10-19 05:00:00 UTC');
$i=['instance_id'=>'56667162','gpu_count'=>8];
function sample(int $id,int $age,float $hash,int $now):array {
  $s=gmdate('Y-m-d H:i:s',$now-$age);
  return ['id'=>$id,'received_at'=>$s,'observed_at'=>$s,'total_ths'=>$hash];
}
$good=[sample(4,5,2900,$now),sample(3,35,2910,$now),sample(2,65,2890,$now),sample(1,95,2910,$now)];
$low=[sample(4,5,2750,$now),sample(3,35,2700,$now),sample(2,65,2600,$now),sample(1,95,2600,$now)];
check(miner_vast_stop_reason($i,$good,$now,$now-400)===null,'healthy');
check(miner_vast_stop_reason($i,$low,$now,$now-400)==='hashrate_low','low stop');
check(miner_vast_stop_reason($i,array_slice($low,0,3),$now,$now-400)===null,'three reads not enough');
check(miner_vast_stop_reason($i,$low,$now,$now-100)===null,'arming grace');
check(miner_vast_stop_reason(['instance_id'=>'56658416','gpu_count'=>2],$low,$now,$now-400)===null,'target pin');
check(miner_vast_stop_reason($i,[sample(3,185,2900,$now)],$now,$now-400)==='telemetry_lost','telemetry timeout');
check(miner_vast_stop_reason($i,[],$now,$now-400)==='telemetry_missing','missing');
$gap=$low;$gap[1]=sample(2,59,2700,$now);
check(miner_vast_stop_reason($i,$gap,$now,$now-400)===null,'sample cadence');
$jitter=[];for($n=0;$n<5;$n++)$jitter[]=sample(5-$n,5+29*$n,1533,$now);
check(miner_vast_stop_reason($i,array_slice($jitter,0,4),$now,$now-400)===null,'87 seconds insufficient');
check(miner_vast_stop_reason($i,$jitter,$now,$now-400)==='hashrate_low','29 second cadence eventually stops');
$recovered=$jitter;$recovered[2]['total_ths']=3296;
check(miner_vast_stop_reason($i,$recovered,$now,$now-400)===null,'recovery breaks low window');
$duplicate=$low;$duplicate[1]=$duplicate[0];
check(miner_vast_stop_reason($i,$duplicate,$now,$now-400)===null,'repeated miner observation is not independent');
check(miner_vast_stop_reason($i,[sample(1,179,3296,$now)],$now,$now-400)===null,'179 seconds not lost');
check(miner_vast_stop_reason($i,[sample(1,180,3296,$now)],$now,$now-400)==='telemetry_lost','180 second boundary');
check(miner_vast_stop_reason($i,[],$now,$now-179)===null,'missing telemetry initial grace');
check(miner_vast_stop_reason($i,$low,$now,$now-180)==='hashrate_low','initial grace ends at 180 seconds');
$threshold=$low;$threshold[1]['total_ths']=2800;
check(miner_vast_stop_reason($i,$threshold,$now,$now-400)===null,'2800 is not below threshold');
$observedOld=sample(1,0,3296,$now);$observedOld['observed_at']=gmdate('Y-m-d H:i:s',$now-180);
check(miner_vast_stop_reason($i,[$observedOld],$now,$now-400)==='telemetry_lost','fresh receipt cannot refresh frozen observation');
echo "vast auto stop regression ok\n";
