<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/vast.php';

putenv('MINER_VAST_INGEST_KEY=CI-ONLY-EXAMPLE-SIGNING-KEY-WITH-ENOUGH-LENGTH-12345');
$a=miner_vast_instance_key('56667162');
$b=miner_vast_instance_key('56667163');
if(strlen($a)!==64||$a===$b||$a===miner_vast_ingest_key())
    throw new LogicException('Per-instance HMAC isolation failed');
if($a!==hash_hmac('sha256','vast-instance-v1:56667162',miner_vast_ingest_key()))
    throw new LogicException('Per-instance KDF changed unexpectedly');

try{miner_vast_instance_key('../56667162');throw new LogicException('unsafe instance accepted');}
catch(InvalidArgumentException $expected){}

$body=json_encode(['instance_id'=>'56667162','sample_id'=>'12345678-1234-1234-1234-123456789abc',
                   'observed_at'=>gmdate('Y-m-d\TH:i:s\Z'),'gpus'=>[]],JSON_THROW_ON_ERROR);
$timestamp=(string)time();
$wrong=hash_hmac('sha256',$timestamp.'.'.$body,$b);
try {
    miner_vast_ingest($body,$timestamp,$wrong,'instance-v1');
    throw new LogicException('another instance could sign my telemetry');
}catch(InvalidArgumentException $expected){
    if($expected->getMessage()!=='Invalid signature')throw $expected;
}
$wrongVersion=hash_hmac('sha256',$timestamp.'.'.$body,$a);
try {
    miner_vast_ingest($body,$timestamp,$wrongVersion,'made-up-scheme');
    throw new LogicException('unapproved key version accepted');
}catch(InvalidArgumentException $expected){
    if($expected->getMessage()!=='Unsupported key scheme')throw $expected;
}
echo "VAST_PER_INSTANCE_HMAC_ISOLATED_OK\n";
