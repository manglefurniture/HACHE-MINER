<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/core.php';
function check(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
$key=random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$sealed=miner_seal('test-api-secret-value',$key);
check(miner_open($sealed['ciphertext'],$sealed['nonce'],$key)==='test-api-secret-value','Cipher round-trip failed');
try {miner_open($sealed['ciphertext'],$sealed['nonce'],random_bytes(32));throw new RuntimeException('Wrong key was accepted');}
catch(RuntimeException $e){check($e->getMessage()!=='Wrong key was accepted','Wrong key must fail');}
$sanitized=miner_scrub_log('connect wallet prl1pcc2lcq2jnkzhfk9xnc2nvuv5hzla09ej2ra0g06gw60czv04hxksa090gu password=secret123');
check(!str_contains($sanitized,'prl1pcc') && !str_contains($sanitized,'secret123'),'Sensitive log text leaked');
check(miner_finite_decimal('0.135',6)==='0.135000','Rate precision failed');
check(miner_finite_decimal('-1')===null,'Negative rate accepted');
check(miner_valid_salad_slug('hache'),'HACHE valid');
check(miner_valid_salad_slug('interactive'),'INTERACTIVE valid');
check(miner_valid_salad_slug('new-organization-5'),'New organizations supported');
check(!miner_valid_salad_slug('../hache'),'Path traversal organization rejected');
check(!miner_valid_salad_slug('invalid_underscore'),'Invalid organization slug rejected');
$ansi="\x1B[0m[2026-10-09 16:24:37] \x1B[1m\x1B[40m\x1B[31m GPU0 share rejected [stale share] \x1B[0m";
$clean=miner_scrub_log($ansi);
check(str_contains($clean,'GPU0 share rejected'),'Real warning must remain readable');
check(!str_contains($clean,'[0m') && !str_contains($clean,'[31m') && !str_contains($clean,"\x1B"),'ANSI sequences were not removed');
$legacy=miner_scrub_log('[0m[2026-10-09 16:24:37] [1m [40m [31mGPU0 error');
check(str_contains($legacy,'[2026-10-09 16:24:37]') && !str_contains($legacy,'[31m'),'Old log artifact not removed without deleting dates');
check(miner_instance_state(['state'=>['status'=>'running']])==='running','Nested Salad instance state not recognized');
check(miner_instance_state(['state'=>'pending'])==='pending','String instance status fixture not recognized');
check(miner_instance_state(['state'=>['unexpected'=>'value']])==='unknown','Unknown state must not be guessed');
check(miner_instance_ready(['state'=>['status'=>'running'],'ready'=>true,'started'=>true]),'Container readiness lost');
check(!miner_instance_ready(['state'=>['status'=>'running'],'ready'=>true,'started'=>false]),'Startup incomplete must not count ready');
check(!miner_instance_ready(['state'=>['status'=>'pending'],'ready'=>true,'started'=>true]),'Pending container must not count ready');
check(!miner_instance_ready(['state'=>['status'=>'running'],'ready'=>'true','started'=>true]),'Non-boolean readiness must not count');
echo "PASS core-security-regression\n";
