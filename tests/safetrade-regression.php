<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/safetrade.php';
function st_assert(bool $ok,string $msg): void { if(!$ok) throw new RuntimeException($msg); }
st_assert(miner_safetrade_signature('123','mykey','mysecret')===hash_hmac('sha256','123mykey','mysecret'),'HMAC mismatch');
st_assert(miner_safetrade_allowed_path('/trade/market/trades'),'Trades read unavailable');
foreach (['/trade/market/orders','/trade/market/orders/cancel','/trade/account/withdrawals','https://evil.example/'] as $path) {
    st_assert(!miner_safetrade_allowed_path($path),'Mutation/unknown path allowed');
}
try {
    miner_safetrade_read('/trade/market/orders');
    throw new RuntimeException('Unsupported endpoint accepted');
} catch (InvalidArgumentException $e) {}
try {
    miner_safetrade_read('/trade/market/trades',['&unsafe'=>'1']);
    throw new RuntimeException('Unsafe query accepted');
} catch (InvalidArgumentException $e) {}
$settings=file_get_contents(dirname(__DIR__).'/public/index.php');
st_assert(is_string($settings),'Settings source unavailable');
foreach (["\$action==='safetrade_credentials'", "if (\$page!=='settings')", "miner_check_csrf()", "miner_require_admin()", "miner_put_secret('safetrade:api-key'", "miner_put_secret('safetrade:api-secret'", 'name="safetrade_api_key"', 'name="safetrade_api_secret"', 'type="password"'] as $expected) {
    st_assert(str_contains($settings,$expected),'SafeTrade credential form security contract missing');
}
st_assert(!str_contains($settings,"value=\"<?= miner_get_secret('safetrade"),'Secret value must never appear in HTML');
echo "SAFETRADE_READONLY_CONTRACT_OK\n";
