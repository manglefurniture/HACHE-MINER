<?php
declare(strict_types=1);
require_once __DIR__.'/../app/finances.php';
require_once __DIR__.'/../app/salad-gpu-prices.php';
function price_assert(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException($why);
}
$api=['items'=>[
 ['id'=>'3eae6ce4-aa14-4c7d-b502-131730c9af48','name'=>'RTX 4070 Ti SUPER',
  'prices'=>[
    ['priority'=>'batch','price'=>'0.090'],
    ['priority'=>'low','price'=>'0.130'],
    ['priority'=>'medium','price'=>'0.170'],
    ['priority'=>'high','price'=>'0.210']]],
 ['id'=>'f0a512bc-782b-40cc-8718-d988b50d83ce','name'=>'RTX 5060 Ti',
  'prices'=>[['priority'=>'lowest','price'=>'0.070'],['priority'=>'low','price'=>'0.097']]]
]];
$catalog=miner_rate_parse_salad_gpu_classes($api);
price_assert(count($catalog)===2,'Official GPU classes lost');
$rates=miner_salad_price_rows($catalog);
price_assert(count($rates)===6,'Official GPU class prices lost');
$by=[];
foreach($rates as $r)$by[$r['gpu_class'].'|'.$r['priority']]=$r['usd_per_hour'];
price_assert($by['3eae6ce4-aa14-4c7d-b502-131730c9af48|lowest']==='0.090000',
    'Lowest rate not exact');
price_assert(!isset($by['3eae6ce4-aa14-4c7d-b502-131730c9af48|batch']),
    'API batch must be normalized to Lowest only');
price_assert($by['3eae6ce4-aa14-4c7d-b502-131730c9af48|low']==='0.130000',
    'Low must not inherit Lowest rate');
price_assert($by['f0a512bc-782b-40cc-8718-d988b50d83ce|low']==='0.097000',
    'Second model lost');
price_assert(miner_salad_price_rows([['id'=>'same','prices'=>[
    ['priority'=>'lowest','price'=>''],['priority'=>'low','price'=>'NaN'],
    ['priority'=>'high','price'=>'-0.02'],['priority'=>'medium','price'=>'1000'],
    ['priority'=>'none','price'=>'0.1']]]])===[],
    'Invalid or unknown prices must not become zero');
price_assert(miner_salad_price_rows([['id'=>'same','prices'=>null]])===[],
    'Missing API prices must stay unknown');
$contradiction=false;
try {miner_salad_price_rows([['id'=>'valid','prices'=>[
    ['priority'=>'low','price'=>'0.12'],['priority'=>'low','price'=>'0.09']]]]);}
catch(UnexpectedValueException $e){$contradiction=true;}
price_assert($contradiction,'Contradictory official prices silently accepted');
$priceSource=file_get_contents(__DIR__.'/../app/salad-gpu-prices.php');
$collector=file_get_contents(__DIR__.'/../bin/poll.php');
$finance=file_get_contents(__DIR__.'/../app/finances.php');
$page=file_get_contents(__DIR__.'/../public/index.php');
$migration=file_get_contents(__DIR__.'/../database/006_salad_gpu_prices.sql');
price_assert(str_contains($priceSource,'INTERVAL 90 MINUTE'),'Freshness bound missing');
price_assert(str_contains($collector,'miner_salad_price_for('),'Collector does not use official Salad price');
price_assert(!str_contains($collector,"SELECT usd_per_hour FROM gpu_rates"),
    'Collector still uses unverified manual prices');
price_assert(str_contains($collector,'official_rate_usd_per_hour') &&
             str_contains($collector,'priority_at_observation'),
    'Rate/priority provenance must be stamped on each observation');
price_assert(str_contains($finance,'m.official_rate_usd_per_hour IS NOT NULL'),
    'Legacy manual charges are incorrectly reported as official');
price_assert(str_contains($page,'Tarifas oficiales de Salad') &&
             !str_contains($page,"$".'action==='."'rate'"),
    'Manual editing still active');
price_assert(str_contains($migration,'salad_gpu_prices') &&
             str_contains($migration,'salad_gpu_price_changes') &&
             str_contains($migration,'official_rate_usd_per_hour'),
    'Migration or price audit missing');
echo "SALAD_GPU_PRICES_REGRESSION_OK\n";
