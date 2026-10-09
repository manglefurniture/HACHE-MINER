<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';

/**
 * Accounting uses event-level receipts; no value is inferred from mining
 * hashrate, provider balance, wallet transfer or unconfirmed PRL.
 */
function miner_accounting_types(): array {
    return [
        'salad_topup'=>'Recarga Salad · salida de caja',
        'salad_refund'=>'Reembolso Salad · entrada de caja',
        'prl_sale'=>'Venta PRL realizada · entrada neta',
        'other_cost'=>'Otro pago real · salida de caja',
        'other_income'=>'Otro cobro real · entrada de caja',
        'prl_payout'=>'Pago Kryptex en PRL · sin USD realizado',
        'prl_transfer'=>'Transferencia PRL entre cuentas propias · sin ingreso'
    ];
}
function miner_accounting_ready(): bool {
    $st=miner_db()->query("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema=DATABASE() AND table_name='accounting_events'");
    return (int)$st->fetchColumn()===1;
}
/** Fixed decimal canonicalization: never use floating point to store receipts. */
function miner_accounting_decimal(mixed $value,bool $strictlyPositive=true): ?string {
    if (!is_string($value) && !is_int($value)) return null;
    $v=trim((string)$value);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,10})(?:\.[0-9]{1,8})?$/D',$v))return null;
    [$whole,$frac]=array_pad(explode('.',$v,2),2,'');
    $canon=$whole.'.'.str_pad($frac,8,'0');
    if($strictlyPositive && $canon==='0.00000000')return null;
    return $canon;
}
/** Lexicographic compare is safe on normalized decimals of bounded length. */
function miner_accounting_decimal_lte(string $a,string $b): bool {
    return strcmp(str_pad($a,20,'0',STR_PAD_LEFT),str_pad($b,20,'0',STR_PAD_LEFT))<=0;
}
/** @return array<string,string|null> */
function miner_accounting_validate(array $post,array $validOrgs): array {
    $type=(string)($post['movement_type']??'');
    if(!array_key_exists($type,miner_accounting_types())) throw new InvalidArgumentException('Movement type invalid');
    $org=trim((string)($post['movement_org']??''));
    $orgRequired=in_array($type,['salad_topup','salad_refund','other_cost','other_income'],true);
    if ($orgRequired && !in_array($org,$validOrgs,true)) throw new InvalidArgumentException('Valid organization required');
    if (!$orgRequired && $org!=='') throw new InvalidArgumentException('Shared PRL movement must not be allocated to an organization without evidence');
    $date=(string)($post['movement_date']??'');
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$date,new DateTimeZone('UTC'));
    if (!$parsed || $parsed->format('Y-m-d H:i:s')!==$date) throw new InvalidArgumentException('UTC movement date invalid');
    $ref=trim((string)($post['movement_reference']??''));
    if(strlen($ref)<5 || strlen($ref)>180 || preg_match('/[\x00-\x1F\x7F]/',$ref)) {
        throw new InvalidArgumentException('Receipt reference required');
    }
    $memo=trim((string)($post['movement_memo']??''));
    if (strlen($memo)>300 || preg_match('/[\x00-\x08\x0B-\x1F\x7F]/',$memo)) throw new InvalidArgumentException('Invalid note');
    $usdRaw=trim((string)($post['movement_usd']??''));
    $feeRaw=trim((string)($post['movement_fee']??''));
    $prlRaw=trim((string)($post['movement_prl']??''));
    $needsUsd=in_array($type,['salad_topup','salad_refund','prl_sale','other_cost','other_income'],true);
    $needsPrl=in_array($type,['prl_sale','prl_payout','prl_transfer'],true);
    $usd=$needsUsd ? miner_accounting_decimal($usdRaw) : null;
    $prl=$needsPrl ? miner_accounting_decimal($prlRaw) : null;
    if($needsUsd && $usd===null)throw new InvalidArgumentException('Confirmed USD equivalent required');
    if($needsPrl && $prl===null)throw new InvalidArgumentException('Confirmed PRL quantity required');
    if(!$needsUsd && $usdRaw!=='')throw new InvalidArgumentException('No USD proceeds belong to this PRL transfer');
    if(!$needsPrl && $prlRaw!=='')throw new InvalidArgumentException('No PRL quantity belongs to this cash movement');
    $fee=null;
    if($type==='prl_sale') {
        $fee=miner_accounting_decimal($feeRaw!==''?$feeRaw:'0',false);
        if($fee===null || !miner_accounting_decimal_lte($fee,$usd))throw new InvalidArgumentException('Sale fees exceed gross proceeds');
    } elseif($feeRaw!=='')throw new InvalidArgumentException('Fees only recorded within realized PRL sales');
    return [
      'event_type'=>$type,'organization'=>$orgRequired?$org:null,
      'occurred_at'=>$date,'usd_amount'=>$usd,'usd_fee'=>$fee,'prl_amount'=>$prl,
      'source_reference'=>$ref,'memo'=>$memo
    ];
}
function miner_accounting_insert(int $adminId,array $data): void {
    if($adminId<1 || !miner_accounting_ready())throw new RuntimeException('Accounting migration not applied');
    $db=miner_db();
    $db->beginTransaction();
    try {
        $st=$db->prepare('INSERT INTO accounting_events (event_type,organization,occurred_at,usd_amount,usd_fee,prl_amount,source_reference,memo,created_by)
            VALUES (?,?,?,?,?,?,?,?,?)');
        $st->execute([$data['event_type'],$data['organization'],$data['occurred_at'],$data['usd_amount'],
            $data['usd_fee'],$data['prl_amount'],$data['source_reference'],$data['memo'],$adminId]);
        miner_audit($adminId,'accounting_event_added',(string)$db->lastInsertId().':'.$data['event_type']);
        $db->commit();
    } catch(Throwable $e) {
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
}
/**
 * Cash movements != consumption costs. A Salad top-up adds cloud credit but
 * does not prove GPU usage. A PRL payout/transfer is not a USD sale.
 */
function miner_accounting_overview(): array {
    if(!miner_accounting_ready())return ['ready'=>false,'entries'=>[],'aggregates'=>[],'registered_cash_in'=>null,'registered_cash_out'=>null,'registered_cash_net'=>null,'shared_sales_net'=>null,'prl_sold'=>null,'prl_paid_out'=>null];
    $db=miner_db();
    $groups=$db->query("SELECT COALESCE(organization,'[compartido]') org,event_type,
      COUNT(*) records,SUM(usd_amount) usd,SUM(usd_fee) fee,SUM(prl_amount) prl
      FROM accounting_events GROUP BY org,event_type ORDER BY org,event_type")->fetchAll(PDO::FETCH_ASSOC);
    $total=$db->query("SELECT COUNT(*) records,
      SUM(CASE WHEN event_type IN ('salad_refund','other_income') THEN usd_amount
               WHEN event_type='prl_sale' THEN usd_amount-usd_fee ELSE 0 END) cash_in,
      SUM(CASE WHEN event_type IN ('salad_topup','other_cost') THEN usd_amount ELSE 0 END) cash_out,
      SUM(CASE WHEN event_type='prl_sale' THEN usd_amount-usd_fee ELSE 0 END) sales_net,
      SUM(CASE WHEN event_type='prl_sale' THEN prl_amount ELSE 0 END) prl_sold,
      SUM(CASE WHEN event_type='prl_payout' THEN prl_amount ELSE 0 END) prl_payouts
      FROM accounting_events")->fetch(PDO::FETCH_ASSOC);
    $entries=$db->query("SELECT id,event_type,organization,occurred_at,usd_amount,usd_fee,prl_amount,source_reference,memo,created_at
      FROM accounting_events ORDER BY occurred_at DESC,id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
    $hasRows=(int)($total['records']??0)>0;
    $cashIn=$hasRows?(string)$total['cash_in']:null;
    $cashOut=$hasRows?(string)$total['cash_out']:null;
    $cashNet=null;
    if($hasRows){
        $st=$db->query("SELECT
        SUM(CASE WHEN event_type IN ('salad_refund','other_income') THEN usd_amount
                 WHEN event_type='prl_sale' THEN usd_amount-usd_fee ELSE 0 END)
        - SUM(CASE WHEN event_type IN ('salad_topup','other_cost') THEN usd_amount ELSE 0 END) net
        FROM accounting_events");
        $cashNet=(string)$st->fetchColumn();
    }
    return [
        'ready'=>true,'entries'=>$entries,'aggregates'=>$groups,
        'registered_cash_in'=>$cashIn,'registered_cash_out'=>$cashOut,
        'registered_cash_net'=>$cashNet,
        'shared_sales_net'=>$hasRows?(string)$total['sales_net']:null,
        'prl_sold'=>$hasRows?(string)$total['prl_sold']:null,
        'prl_paid_out'=>$hasRows?(string)$total['prl_payouts']:null
    ];
}
