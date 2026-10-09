<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/reallocate.php';
function reallocate_assert(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$id='65fdef35-efde-41d1-b781-b6a811c399e4';
reallocate_assert(miner_reallocation_valid_instance_id($id),'UUID rejected');
reallocate_assert(!miner_reallocation_valid_instance_id('foo/bar'),'Path injection accepted');
reallocate_assert(!miner_reallocation_valid_instance_id(''),'Empty instance accepted');
reallocate_assert(!miner_reallocation_valid_instance_id(str_repeat('a',121)),'Oversized instance accepted');
reallocate_assert(miner_reallocation_confirmed('REASIGNAR'),'Explicit confirmation rejected');
reallocate_assert(!miner_reallocation_confirmed('Reasignar'),'Weak confirmation accepted');
reallocate_assert(!miner_reallocation_confirmed(''),'Empty confirmation accepted');
$target=['organization'=>'hache','project_name'=>'prl-tests','group_name'=>'prl-low-4070'];
$hash=miner_reallocation_fingerprint($target,$id);
reallocate_assert(strlen($hash)===64 && ctype_xdigit($hash),'Invalid action fingerprint');
reallocate_assert($hash!==miner_reallocation_fingerprint($target,'another-id'),'Different node must differ');
reallocate_assert($hash!==miner_reallocation_fingerprint(array_merge($target,['organization'=>'interactive']),$id),'Different organization must differ');
$live=['instance_id'=>$id,'state'=>'running','ready'=>true,'started'=>true];
reallocate_assert(miner_reallocation_live_matches($live,$id),'Valid Salad instance must pass');
reallocate_assert(miner_reallocation_live_matches(['id'=>$id,'state'=>['status'=>'running'],'ready'=>true,'started'=>true],$id),'Legacy id fallback must pass');
reallocate_assert(!miner_reallocation_live_matches($live,'another-id'),'ID mismatch must fail');
reallocate_assert(!miner_reallocation_live_matches(array_merge($live,['instance_id'=>'other']),$id),'No false alternative match');
reallocate_assert(!miner_reallocation_live_matches(['instance_id'=>$id,'state'=>'allocating','ready'=>true,'started'=>true],$id),'Allocating cannot be reallocated');
reallocate_assert(!miner_reallocation_live_matches(['instance_id'=>$id,'state'=>'running','ready'=>false,'started'=>true],$id),'Unready cannot be reallocated');
reallocate_assert(!miner_reallocation_live_matches(['instance_id'=>$id,'state'=>'running','ready'=>true,'started'=>false],$id),'Not started cannot be reallocated');
echo "PASS reallocation-safety-regression\n";
