<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/core.php';
function check_remember(bool $ok, string $message): void {if(!$ok) throw new RuntimeException($message);}
$selector=bin2hex(random_bytes(16));
$validator=bin2hex(random_bytes(32));
$token=miner_remember_cookie_value($selector,$validator);
check_remember(miner_parse_remember_cookie($token)===[$selector,$validator],'cookie roundtrip');
check_remember(miner_parse_remember_cookie('bad')===null,'bad token accepted');
check_remember(miner_parse_remember_cookie($token.'x')===null,'trailing characters accepted');
check_remember(miner_parse_remember_cookie(strtoupper($token))===null,'noncanonical token accepted');
check_remember(!hash_equals(hash('sha256',$validator),hash('sha256',bin2hex(random_bytes(32)))),'hash mismatch');
check_remember(MINER_REMEMBER_DAYS===30,'wrong device expiry');
check_remember(miner_device_label('Mozilla/5.0 (Linux; Android 16)')==='Android · navegador','device family');
echo "PASS remember-device-regression\n";
