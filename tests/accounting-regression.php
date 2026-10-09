<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/accounting.php';
function account_check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);}
$base=[
 'movement_type'=>'salad_topup','movement_org'=>'hache',
 'movement_date'=>'2026-10-09 18:00:00',
 'movement_usd'=>'10.25','movement_prl'=>'','movement_fee'=>'',
 'movement_reference'=>'invoice-Salad-00001','movement_memo'=>'Recarga real'
];
$orgs=['hache','interactive'];
$topup=miner_accounting_validate($base,$orgs);
account_check($topup['event_type']==='salad_topup' && $topup['organization']==='hache','Cash top-up must be scoped to known org');
account_check($topup['usd_amount']==='10.25000000' && $topup['prl_amount']===null,'Canonical decimal representation');
$sale=miner_accounting_validate(array_replace($base,[
 'movement_type'=>'prl_sale','movement_org'=>'',
 'movement_usd'=>'7.20','movement_prl'=>'25.125','movement_fee'=>'0.30',
 'movement_reference'=>'safetrade-trade-901'
]),$orgs);
account_check($sale['organization']===null && $sale['prl_amount']==='25.12500000','Shared PRL sale cannot be attributed to Salad org');
account_check($sale['usd_fee']==='0.30000000','Sale fees recorded separately from gross proceeds');
$transfer=miner_accounting_validate(array_replace($base,[
 'movement_type'=>'prl_transfer','movement_org'=>'',
 'movement_usd'=>'','movement_prl'=>'1.23456789','movement_fee'=>'',
 'movement_reference'=>'solana-transaction-123'
]),$orgs);
account_check($transfer['usd_amount']===null && $transfer['prl_amount']==='1.23456789','PRL transfer cannot be counted as cash');
account_check(miner_accounting_decimal('0.00000001')==='0.00000001','Sub-cent precision retained');
account_check(miner_accounting_decimal('0')===null,'Zero amount cannot fabricate receipt');
account_check(miner_accounting_decimal('0',false)==='0.00000000','Zero commission accepted');
account_check(miner_accounting_decimal('NaN')===null,'NaN rejected');
account_check(miner_accounting_decimal('1e10')===null,'Scientific values rejected');
account_check(miner_accounting_decimal('-0.01')===null,'Negative values rejected');
account_check(miner_accounting_decimal('1.000000009')===null,'Excess precision rejected');
account_check(miner_accounting_decimal_lte('0.10000000','0.50000000'),'Fee comparator lower');
account_check(!miner_accounting_decimal_lte('0.60000000','0.50000000'),'Fee comparator greater');
$reject=static function(array $values)use($orgs):void {
 try {miner_accounting_validate($values,$orgs);throw new RuntimeException('Invalid accounting entry passed validation');}
 catch (InvalidArgumentException $e) {}
};
$reject(array_replace($base,['movement_org'=>'unregistered']));
$reject(array_replace($base,['movement_type'=>'prl_sale','movement_org'=>'hache','movement_prl'=>'1']));
$reject(array_replace($base,['movement_type'=>'prl_sale','movement_org'=>'','movement_prl'=>'1','movement_fee'=>'20']));
$reject(array_replace($base,['movement_type'=>'prl_payout','movement_org'=>'','movement_usd'=>'20','movement_prl'=>'1']));
$reject(array_replace($base,['movement_type'=>'salad_topup','movement_prl'=>'1']));
$reject(array_replace($base,['movement_type'=>'salad_topup','movement_date'=>'2026-02-30 16:00:00']));
$reject(array_replace($base,['movement_reference'=>'a']));
$reject(array_replace($base,['movement_type'=>'other_cost','movement_usd'=>'-10']));
echo "PASS accounting-ledger-regression\n";
