<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';

/** Provider-reported prepaid credits are not proof of payment or invoice amounts. */
function miner_credit_snapshots_ready(): bool {
    return (int)miner_db()->query("SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema=DATABASE() AND table_name='salad_credit_snapshots'")->fetchColumn()===1;
}
function miner_credit_cents(string $value): ?int {
    if(!preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D',$value))return null;
    $parts=explode('.',$value,2);
    $whole=(int)$parts[0];$frac=(int)str_pad($parts[1]??'',2,'0');
    return $whole*100+$frac;
}
function miner_credit_validate(array $data,array $organizations): array {
    $org=(string)($data['organization']??'');
    if(!in_array($org,$organizations,true)) throw new InvalidArgumentException('Unknown Salad organization');
    $date=(string)($data['snapshot_date']??'');
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
    if(!$parsed || $parsed->format('Y-m-d')!==$date)throw new InvalidArgumentException('Invalid credit snapshot date');
    $ref=(string)($data['evidence_reference']??'');
    if(strlen($ref)<10 || strlen($ref)>160 || preg_match('/[\x00-\x1F\x7F]/',$ref)) {
        throw new InvalidArgumentException('Invalid evidence reference');
    }
    $cents=[];
    foreach(['issued_usd','consumed_usd','available_usd','expired_usd'] as $key){
        $num=miner_credit_cents(trim((string)($data[$key]??'')));
        if($num===null)throw new InvalidArgumentException('Invalid or missing Salad credit amount');
        $cents[$key]=$num;
    }
    if($cents['issued_usd']!==$cents['consumed_usd']+$cents['available_usd']+$cents['expired_usd']) {
        throw new InvalidArgumentException('Billing totals do not reconcile');
    }
    $normalized=['organization'=>$org,'snapshot_date'=>$date,'evidence_reference'=>$ref];
    foreach($cents as $key=>$n) $normalized[$key]=number_format($n/100,2,'.','');
    return $normalized;
}
/** Only one evidence row per uniquely named provider screenshot. */
function miner_credit_snapshot_insert(array $row): bool {
    if(!miner_credit_snapshots_ready())throw new RuntimeException('Salad credit schema not installed');
    $db=miner_db();
    $db->beginTransaction();
    try{
        $q=$db->prepare('SELECT organization,snapshot_date,issued_usd,consumed_usd,available_usd,expired_usd FROM salad_credit_snapshots WHERE evidence_reference=? FOR UPDATE');
        $q->execute([$row['evidence_reference']]);$existing=$q->fetch(PDO::FETCH_ASSOC);
        if($existing){
            foreach(['organization','snapshot_date','issued_usd','consumed_usd','available_usd','expired_usd'] as $key) {
                if($key==='organization'||$key==='snapshot_date'){
                    if((string)$existing[$key]!==$row[$key]) throw new DomainException('Credit snapshot evidence conflict');
                } elseif(number_format((float)$existing[$key],2,'.','')!==$row[$key]) {
                    throw new DomainException('Credit snapshot evidence amount conflict');
                }
            }
            $db->commit();return false;
        }
        $st=$db->prepare('INSERT INTO salad_credit_snapshots
            (organization,snapshot_date,issued_usd,consumed_usd,available_usd,expired_usd,evidence_reference)
            VALUES (?,?,?,?,?,?,?)');
        $st->execute([$row['organization'],$row['snapshot_date'],$row['issued_usd'],$row['consumed_usd'],
            $row['available_usd'],$row['expired_usd'],$row['evidence_reference']]);
        $db->commit();return true;
    } catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
}
function miner_credit_snapshot_overview(): array {
    if(!miner_credit_snapshots_ready())return ['ready'=>false,'items'=>[],'totals'=>null];
    $targets=miner_salad_targets(false);
    $orgs=array_values(array_unique(array_column($targets,'organization_slug')));
    $db=miner_db();
    $st=$db->prepare('SELECT organization,snapshot_date,issued_usd,consumed_usd,available_usd,expired_usd,evidence_reference
        FROM salad_credit_snapshots WHERE organization=?
        ORDER BY snapshot_date DESC,id DESC LIMIT 1');
    $items=[];$totals=['issued_usd'=>0,'consumed_usd'=>0,'available_usd'=>0,'expired_usd'=>0];
    $full=count($orgs)>0;
    foreach($orgs as $org){
        $st->execute([$org]);$row=$st->fetch(PDO::FETCH_ASSOC);
        if(!$row){$items[]=['organization'=>$org,'snapshot_date'=>null,'issued_usd'=>null,'consumed_usd'=>null,'available_usd'=>null,'expired_usd'=>null];$full=false;continue;}
        $items[]=$row;
        foreach(array_keys($totals) as $key)$totals[$key]+=(int)round((float)$row[$key]*100);
    }
    foreach($totals as $key=>$n)$totals[$key]=number_format($n/100,2,'.','');
    return ['ready'=>true,'items'=>$items,'totals'=>$full?$totals:null];
}
