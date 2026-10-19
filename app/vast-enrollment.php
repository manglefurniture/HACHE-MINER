<?php
declare(strict_types=1);

/**
 * Vast-003: exact, provider-confirmed enrollment into the EXISTING HACHE-MINER
 * schema. No Salad tables, schema changes, shell commands or general SQL proxy.
 * The one-use GPT broker authorizes the mutation separately.
 */
function miner_vast_enrollment_from_provider(array $provider): array {
    $id=(string)($provider['id']??'');
    if(!preg_match('/^[1-9][0-9]{3,14}$/D',$id))
        throw new DomainException('invalid_vast_id');
    $machine=(string)($provider['machine_id']??'');
    $host=(string)($provider['host_id']??'');
    if(!preg_match('/^[1-9][0-9]{0,15}$/D',$machine)||
       !preg_match('/^[1-9][0-9]{0,15}$/D',$host))
        throw new DomainException('hardware_identity_missing');
    $gpu=$provider['num_gpus']??null;
    if(!is_int($gpu)||$gpu<1||$gpu>16)
        throw new DomainException('invalid_gpu_inventory');
    if(strtolower((string)($provider['actual_status']??''))!=='running'||
       strtolower((string)($provider['intended_status']??''))!=='running')
        throw new DomainException('instance_not_running');
    $rate=$provider['dph_total']??null;
    if(!is_numeric($rate)||!is_finite((float)$rate)||
       (float)$rate<=0||(float)$rate>100)
        throw new DomainException('invalid_provider_rate');
    if(!is_string($provider['ssh_host']??null)||
       !preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{1,252}$/D',$provider['ssh_host'])||
       !is_int($provider['ssh_port']??null)||
       $provider['ssh_port']<1||$provider['ssh_port']>65535)
        throw new DomainException('unverified_ssh_endpoint');
    return [
        'instance_id'=>$id,'machine_id'=>$machine,'host_id'=>$host,
        'gpu_count'=>$gpu,'hourly_usd'=>sprintf('%.6f',(float)$rate),
        'label'=>'Vast '.$id,
    ];
}

/**
 * Strict insert-or-confirm: never repoint an existing monitored instance,
 * overwrite a rate, re-enable an intentionally disabled instance or reset
 * its thresholds. Collector requires enabled=1 before receiving HMAC samples.
 */
function miner_vast_enrollment_write(PDO $db,array $proposed): array {
    $expected=['instance_id','machine_id','host_id','gpu_count','hourly_usd','label'];
    if(array_keys($proposed)!==$expected)
        throw new DomainException('invalid_enrollment_projection');
    $db->beginTransaction();
    try {
        $read=$db->prepare(
          'SELECT instance_id,machine_id,host_id,gpu_count,hourly_usd,enabled '
          .'FROM vast_instances WHERE instance_id=? FOR UPDATE');
        $read->execute([$proposed['instance_id']]);
        $old=$read->fetch(PDO::FETCH_ASSOC);
        if($old){
            if((string)$old['machine_id']!==$proposed['machine_id']||
               (string)$old['host_id']!==$proposed['host_id']||
               (int)$old['gpu_count']!==$proposed['gpu_count']||
               bccomp((string)$old['hourly_usd'],$proposed['hourly_usd'],6)!==0||
               (int)$old['enabled']!==1)
                throw new DomainException('existing_instance_conflict');
            $db->commit();
            return ['status'=>'already_enrolled','instance_id'=>$proposed['instance_id'],
                    'watchdog_coverage'=>'verify_timer_and_recent_samples'];
        }
        $insert=$db->prepare(
          'INSERT INTO vast_instances '
          .'(instance_id,machine_id,host_id,label,gpu_count,hourly_usd,low_ths,normal_ths,enabled,updated_at) '
          .'VALUES(?,?,?,?,?,?,0,0,1,UTC_TIMESTAMP())');
        $insert->execute([
          $proposed['instance_id'],$proposed['machine_id'],$proposed['host_id'],
          $proposed['label'],$proposed['gpu_count'],$proposed['hourly_usd']
        ]);
        $db->commit();
        return ['status'=>'enrolled','instance_id'=>$proposed['instance_id'],
                'watchdog_coverage'=>'verify_timer_and_recent_samples'];
    } catch(Throwable $e) {
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
}
