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
check(count(MINER_ORGANIZATIONS)===2,'Two Salad organizations required');
echo "PASS core-security-regression\n";
