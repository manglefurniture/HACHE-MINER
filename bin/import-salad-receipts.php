<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || !function_exists('posix_geteuid') || posix_geteuid()!==0) {
    fwrite(STDERR,"IMPORT_ROOT_ONLY\n");exit(2);
}
require_once dirname(__DIR__).'/app/accounting.php';
if(!miner_accounting_ready()){fwrite(STDERR,"ACCOUNTING_SCHEMA_NOT_READY\n");exit(2);}
$input=$argv[1]??'';
$expected=$argv[2]??'';
$allowed='/srv/hache-miner/private-imports/salad-receipts-2026-10.csv';
if ($input!==$allowed || !is_file($input) || is_link($input)
 || !preg_match('/^[0-9a-f]{64}$/D',$expected)
 || !hash_equals($expected,(string)hash_file('sha256',$input))) {
    fwrite(STDERR,"IMPORT_SOURCE_NOT_VERIFIED\n");exit(2);
}
$fp=fopen($input,'rb');
if(!$fp){fwrite(STDERR,"IMPORT_CANNOT_READ\n");exit(2);}
$header=fgetcsv($fp);
if($header!==['organization','occurred_at','usd_amount','source_reference','memo']){
    fwrite(STDERR,"IMPORT_HEADER_INVALID\n");exit(2);
}
$db=miner_db();
$orgs=array_values(array_unique(array_column(miner_salad_targets(false),'organization_slug')));
$lines=[];$refs=[];
try {
    while(($cells=fgetcsv($fp))!==false){
        if(count($cells)!==5 || count($lines)>=100)throw new RuntimeException('Invalid or oversized CSV');
        $row=array_combine($header,$cells);
        if($row===false)throw new RuntimeException('Malformed CSV row');
        $event=miner_accounting_validate([
          'movement_type'=>'salad_topup','movement_org'=>$row['organization'],
          'movement_date'=>$row['occurred_at'],'movement_usd'=>$row['usd_amount'],
          'movement_prl'=>'','movement_fee'=>'',
          'movement_reference'=>$row['source_reference'],'movement_memo'=>$row['memo'],
        ],$orgs);
        if(isset($refs[$event['source_reference']]))throw new RuntimeException('Duplicate reference in import');
        $refs[$event['source_reference']]=true;
        $lines[]=$event;
    }
} finally {fclose($fp);}
if(count($lines)!==8) {fwrite(STDERR,"IMPORT_EXPECTED_EIGHT_RECORDS\n");exit(2);}
$sum='0.00';$hache=0;$interactive=0;$dollars=0;
foreach($lines as $row) {
    $dollars+=(float)$row['usd_amount'];
    if($row['organization']==='hache')$hache+=(float)$row['usd_amount'];
    elseif($row['organization']==='interactive')$interactive+=(float)$row['usd_amount'];
}
if(abs($dollars-45)>0.000001 || abs($hache-40)>0.000001 || abs($interactive-5)>0.000001){
    fwrite(STDERR,"IMPORT_TOTALS_NOT_VERIFIED\n");exit(2);
}
$admins=$db->query('SELECT id FROM administrators ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
if(count($admins)!==1){fwrite(STDERR,"IMPORT_REQUIRES_EXACTLY_ONE_ADMIN\n");exit(2);}
$uid=(int)$admins[0];
$duplicate=0;$inserted=0;
$db->beginTransaction();
try {
    $existing=$db->prepare('SELECT organization,event_type,occurred_at,usd_amount,source_reference
       FROM accounting_events WHERE source_reference=? FOR UPDATE');
    $write=$db->prepare('INSERT INTO accounting_events
      (event_type,organization,occurred_at,usd_amount,usd_fee,prl_amount,source_reference,memo,created_by)
      VALUES (?,?,?,?,?,?,?,?,?)');
    foreach($lines as $row){
        $existing->execute([$row['source_reference']]);$found=$existing->fetch(PDO::FETCH_ASSOC);
        if($found) {
            if($found['organization']!==$row['organization']
               || $found['event_type']!=='salad_topup'
               || $found['occurred_at']!==$row['occurred_at']
               || (float)$found['usd_amount']!==(float)$row['usd_amount']) {
                throw new RuntimeException('Receipt conflict; abort import');
            }
            $duplicate++;continue;
        }
        $write->execute(['salad_topup',$row['organization'],$row['occurred_at'],
            $row['usd_amount'],null,null,$row['source_reference'],$row['memo'],$uid]);
        miner_audit($uid,'salad_receipt_imported','receipt-id:'.$db->lastInsertId());
        $inserted++;
    }
    $db->commit();
} catch(Throwable $e) {
    if($db->inTransaction())$db->rollBack();
    fwrite(STDERR,"IMPORT_FAILED_".get_class($e)."\n");exit(1);
}
echo "VERIFIED_SALAD_RECEIPTS=8\n";
echo "EXPECTED_HACHE_USD=40.00\nEXPECTED_INTERACTIVE_USD=5.00\n";
echo "INSERTED=$inserted\nALREADY_PRESENT=$duplicate\n";
echo "NO_GPU_BILLING_INFERRED_FROM_TOPUPS\n";
