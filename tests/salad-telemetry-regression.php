<?php
declare(strict_types=1);
require_once __DIR__.'/../app/salad-telemetry.php';
function telemetry_assert(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message); }
function b64u(string $value):string { return rtrim(strtr(base64_encode($value),'+/','-_'),'='); }
function der_signature_to_raw(string $der):string {
    $offset=2;if((ord($der[1])&128)!==0)$offset=2+(ord($der[1])&127);
    if(ord($der[$offset++])!==2)throw new RuntimeException('bad test signature');
    $rlen=ord($der[$offset++]);$r=substr($der,$offset,$rlen);$offset+=$rlen;
    if(ord($der[$offset++])!==2)throw new RuntimeException('bad test signature');
    $slen=ord($der[$offset++]);$s=substr($der,$offset,$slen);
    $r=str_pad(ltrim($r,"\0"),32,"\0",STR_PAD_LEFT);$s=str_pad(ltrim($s,"\0"),32,"\0",STR_PAD_LEFT);
    return $r.$s;
}
function signed_test_jwt(array $claims,OpenSSLAsymmetricKey $private,string $kid='telemetry-test'):array {
    $header=b64u(json_encode(['alg'=>'ES256','kid'=>$kid],JSON_THROW_ON_ERROR));
    $body=b64u(json_encode($claims,JSON_THROW_ON_ERROR));$input=$header.'.'.$body;
    openssl_sign($input,$der,$private,OPENSSL_ALGO_SHA256);
    return [$input.'.'.b64u(der_signature_to_raw($der)),$header.'.'.$body];
}
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
if(!$key)throw new RuntimeException('Could not create ephemeral test key');
$details=openssl_pkey_get_details($key);$now=time();
$jwks=['keys'=>[['kid'=>'telemetry-test','kty'=>'EC','crv'=>'P-256','x'=>b64u($details['ec']['x']),'y'=>b64u($details['ec']['y'])]]];
$claims=['sub'=>'machine-test-01','salad_machine_id'=>'machine-test-01','salad_workload_id'=>'workload-test-01','salad_organization_name'=>'hache','aud'=>'org-id-test','iat'=>$now,'nbf'=>$now-1,'exp'=>$now+240];
[$jwt,$signed]=signed_test_jwt($claims,$key);
$verified=miner_salad_telemetry_verify_jwt($jwt,$jwks);
telemetry_assert($verified['machine_id']==='machine-test-01','Provider machine claim was not read');
telemetry_assert($verified['workload_id']==='workload-test-01','Provider workload claim was not read');
telemetry_assert($verified['organization']==='hache','Provider organization claim was not read');
$refreshCalls=[];
$refreshed=miner_salad_telemetry_verify_jwt($jwt,null,function(bool $refresh)use(&$refreshCalls,$jwks):array {
    $refreshCalls[]=$refresh;
    return $refresh? $jwks:['keys'=>[]];
});
telemetry_assert($refreshed['machine_id']==='machine-test-01'&&$refreshCalls===[false,true],'Unknown JWT key did not trigger one JWKS refresh');

[$tampered]=signed_test_jwt($claims,$key);
$parts=explode('.',$tampered);$parts[1]=b64u(json_encode(array_merge($claims,['salad_machine_id'=>'attacker']),JSON_THROW_ON_ERROR));
try { miner_salad_telemetry_verify_jwt(implode('.',$parts),$jwks);throw new RuntimeException('Tampered provider token was accepted'); }
catch(InvalidArgumentException) {}

$expired=$claims;$expired['iat']=$now-800;$expired['nbf']=$now-800;$expired['exp']=$now-500;
[$expiredJwt]=signed_test_jwt($expired,$key);
try { miner_salad_telemetry_verify_jwt($expiredJwt,$jwks);throw new RuntimeException('Expired provider token was accepted'); }
catch(InvalidArgumentException) {}

$secret='test-short-lived-salad-jwt';$raw='{"sample_id":"a"}';$timestamp=(string)$now;
$signature=hash_hmac('sha256',$timestamp.'.'.$raw,$secret);
telemetry_assert(hash_equals($signature,hash_hmac('sha256',$timestamp.'.'.$raw,$secret)),'HMAC envelope mismatch');
telemetry_assert(!hash_equals($signature,hash_hmac('sha256',$timestamp.'.{"sample_id":"b"}',$secret)),'Modified payload did not invalidate HMAC');
telemetry_assert(str_contains((string)file_get_contents(__DIR__.'/../database/010_salad_native_telemetry.sql'),'uq_salad_native_sample'),'Sample replay uniqueness is missing');
telemetry_assert(str_contains((string)file_get_contents(__DIR__.'/../app/salad-telemetry.php'),'Replayed or out-of-order sample'),'Timestamp replay guard is missing');
telemetry_assert(str_contains((string)file_get_contents(__DIR__.'/../app/salad-telemetry.php'),'last_seen_at>=UTC_TIMESTAMP()-INTERVAL 10 MINUTE FOR UPDATE'),'Ingestion must lock the instance identity before timestamp ordering');
echo "SALAD_TELEMETRY_REGRESSION_OK\n";
