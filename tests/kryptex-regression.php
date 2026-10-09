<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/kryptex.php';
function kryptex_assert(bool $ok,string $reason): void {
    if (!$ok) throw new RuntimeException($reason);
}
// Verified response keys, synthetic values, no production addresses.
$b=miner_kryptex_balance(['total'=>7.828518268,'unconfirmed'=>4.851785572,'confirmed'=>2.976732696,'threshold'=>5]);
kryptex_assert($b['pending_prl']==='4.85178557','Pending PRL does not map');
kryptex_assert($b['confirmed_prl']==='2.97673270','Confirmed PRL does not map');
try {miner_kryptex_balance(['total'=>50]);throw new RuntimeException('Missing balance accepted');}
catch (UnexpectedValueException $e) {}
try {miner_kryptex_balance(['unconfirmed'=>-1,'confirmed'=>2]);throw new RuntimeException('Negative balance accepted');}
catch (UnexpectedValueException $e) {}
$workers=miner_kryptex_workers(['results'=>[
 ['worker'=>'w01','scheme'=>'pps','status'=>'online','avg_hashrate_30m'=>'1586267868751608.03'],
 ['worker'=>'w02','scheme'=>'pps','status'=>'online','avg_hashrate_30m'=>'1136267868751608.03'],
 ['worker'=>'w03','scheme'=>'pps','status'=>'offline','avg_hashrate_30m'=>'9999999999999999'],
 ['worker'=>'w01','scheme'=>'pps','status'=>'online','avg_hashrate_30m'=>'1586267868751608.03']
]]);
kryptex_assert($workers['worker_count']===2,'Online workers must be unique and exclude offline');
kryptex_assert((float)$workers['hashrate_raw']>2.7e15 && (float)$workers['hashrate_raw']<2.8e15,'Pool 30-minute hashrate H/s incorrectly combined');
kryptex_assert($workers['partial']===false,'Expected full coverage');
$empty=miner_kryptex_workers(['results'=>[]]);
kryptex_assert($empty['worker_count']===0 && $empty['hashrate_raw']==='0','Genuinely empty pool workers should be zero');
$unknown=miner_kryptex_workers(['results'=>[['worker'=>'w01','scheme'=>'pps','status'=>'online']]]);
kryptex_assert($unknown['hashrate_raw']===null && $unknown['partial']===true,'Unknown hashrate must not be invented as zero');
try {miner_kryptex_workers(['other'=>[]]);throw new RuntimeException('Unknown worker schema accepted');}
catch (UnexpectedValueException $e) {}
echo "PASS kryptex-schema-regression\n";
