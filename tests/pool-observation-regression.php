<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/pool-overview.php';
function poolcheck(bool $condition,string $description):void {
    if(!$condition)throw new RuntimeException($description);
}
$clock=(new DateTimeImmutable('2026-10-09T18:00:00+00:00'))->getTimestamp();
poolcheck(miner_pool_observation_recent('2026-10-09 17:59:00',$clock),'Recent UTC reading must be fresh');
poolcheck(!miner_pool_observation_recent('2026-10-09 17:30:00',$clock),'Old balance must be stale');
poolcheck(!miner_pool_observation_recent('2026-10-09 18:10:00',$clock),'Future balance must be invalid');
poolcheck(!miner_pool_observation_recent('',$clock),'Missing balance must not become zero');
poolcheck(miner_pool_hashrate_ths('1586267868751608')==='1586.27','H/s must convert to TH/s');
poolcheck(miner_pool_hashrate_ths('0')==='0.00','Measured zero must remain zero');
poolcheck(miner_pool_hashrate_ths(null)===null,'Missing hashrate must remain unknown');
poolcheck(miner_pool_hashrate_ths('not-a-number')===null,'Invalid hashrate must be rejected');
date_default_timezone_set('America/Cancun');
poolcheck(miner_pool_observation_recent('2026-10-09 17:59:00',$clock),'UTC date parsing must ignore device timezone');
echo "PASS pool-observation-regression\n";
