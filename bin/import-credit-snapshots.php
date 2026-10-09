<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli' || !function_exists('posix_geteuid') || posix_geteuid()!==0){
    fwrite(STDERR,"ROOT_REQUIRED\n");exit(2);
}
require_once dirname(__DIR__).'/app/salad-credits.php';
$path='/srv/hache-miner/private-imports/salad-credits-2026-10-09.csv';
$expected=$argv[1]??'';
if(!is_file($path) || is_link($path) || !preg_match('/^[a-f0-9]{64}$/D',$expected)
    || !hash_equals($expected,(string)hash_file('sha256',$path))) {
    fwrite(STDERR,"PRIVATE_EVIDENCE_CHECKSUM_MISMATCH\n");exit(2);
}
if(!miner_credit_snapshots_ready()) {
    fwrite(STDERR,"CREDIT_TABLE_MISSING\n");exit(2);
}
$fp=fopen($path,'rb');
if(!$fp){fwrite(STDERR,"PRIVATE_SOURCE_UNREADABLE\n");exit(2);}
$header=fgetcsv($fp);
$columns=['organization','snapshot_date','issued_usd','consumed_usd','available_usd','expired_usd','evidence_reference'];
if($header!==$columns){fclose($fp);fwrite(STDERR,"HEADER_INVALID\n");exit(2);}
$orgs=array_values(array_unique(array_column(miner_salad_targets(false),'organization_slug')));
$rows=[];
try{
    while(($fields=fgetcsv($fp))!==false){
        if(count($fields)!==count($header)||count($rows)>=2)throw new UnexpectedValueException('Wrong CSV row count');
        $data=array_combine($header,$fields);
        if($data===false)throw new UnexpectedValueException('Bad row');
        $rows[]=miner_credit_validate($data,$orgs);
    }
} finally {fclose($fp);}
if(count($rows)!==2 || array_column($rows,'organization')!==['hache','interactive']){
    fwrite(STDERR,"EXPECTED_TWO_ORGANIZATIONS\n");exit(2);
}
$expectedValues=[
 ['organization'=>'hache','issued_usd'=>'70.00','consumed_usd'=>'62.23','available_usd'=>'7.77','expired_usd'=>'0.00'],
 ['organization'=>'interactive','issued_usd'=>'10.00','consumed_usd'=>'3.96','available_usd'=>'6.04','expired_usd'=>'0.00']
];
foreach($rows as $index=>$row){
    foreach($expectedValues[$index] as $field=>$value){
        if($row[$field]!==$value){fwrite(STDERR,"SCREENSHOT_TOTALS_MISMATCH\n");exit(2);}
    }
}
$created=0;$unchanged=0;
foreach($rows as $row) {
    if(miner_credit_snapshot_insert($row))$created++;else $unchanged++;
}
echo "BILLING_SNAPSHOT_IMPORT_OK\n";
echo "ADDED=$created EXISTING=$unchanged\n";
echo "HACHE_ISSUED=70.00 HACHE_CONSUMED=62.23 HACHE_AVAILABLE=7.77\n";
echo "INTERACTIVE_ISSUED=10.00 INTERACTIVE_CONSUMED=3.96 INTERACTIVE_AVAILABLE=6.04\n";
echo "TOTAL_CONSUMED=66.19 TOTAL_AVAILABLE=13.81\n";
echo "NO_CASH_RECEIPTS_CREATED\n";
