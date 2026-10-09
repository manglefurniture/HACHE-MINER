<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/salad-credits.php';
function credit_check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$valid=[
 'organization'=>'test-organization','snapshot_date'=>'2026-10-09',
 'issued_usd'=>'50.00','consumed_usd'=>'38.25',
 'available_usd'=>'11.75','expired_usd'=>'0.00',
 'evidence_reference'=>'test-salad-credits-screenshot-20261009'
];
$row=miner_credit_validate($valid,['test-organization']);
credit_check($row['issued_usd']==='50.00' && $row['available_usd']==='11.75','Valid billing reconciliation');
credit_check(miner_credit_cents('38.25')===3825,'Exact cents conversion');
credit_check(miner_credit_cents('0.00')===0,'Zero may be valid credit expiry');
credit_check(miner_credit_cents('12.345')===null,'Cannot truncate a third decimal');
credit_check(miner_credit_cents('-1.00')===null,'Negative grant amount not accepted');
credit_check(miner_credit_cents('1e3')===null,'Scientific notation rejected');
$reject=static function(array $data):void{
 try{miner_credit_validate($data,['test-organization']);throw new RuntimeException('Invalid snapshot accepted');}
 catch(InvalidArgumentException $e){}
};
$reject(array_replace($valid,['consumed_usd'=>'38.26']));
$reject(array_replace($valid,['organization'=>'unknown']));
$reject(array_replace($valid,['snapshot_date'=>'2026-02-30']));
$reject(array_replace($valid,['evidence_reference'=>'bad']));
$reject(array_replace($valid,['available_usd'=>'-5.00']));
echo "PASS salad-credit-snapshot-regression\n";
