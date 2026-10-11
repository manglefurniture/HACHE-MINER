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
echo "SAFETRADE_READONLY_CONTRACT_OK\n";
