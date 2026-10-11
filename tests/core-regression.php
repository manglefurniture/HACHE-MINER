<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/core.php';
require_once dirname(__DIR__).'/app/finances.php';
function check(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
$key=random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$sealed=miner_seal('test-api-secret-value',$key);
check(miner_open($sealed['ciphertext'],$sealed['nonce'],$key)==='test-api-secret-value','Cipher round-trip failed');
try {miner_open($sealed['ciphertext'],$sealed['nonce'],random_bytes(32));throw new RuntimeException('Wrong key was accepted');}
catch(RuntimeException $e){check($e->getMessage()!=='Wrong key was accepted','Wrong key must fail');}
$sanitized=miner_scrub_log('connect wallet prl1pcc2lcq2jnkzhfk9xnc2nvuv5hzla09ej2ra0g06gw60czv04hxksa090gu password=secret123');
check(!str_contains($sanitized,'prl1pcc') && !str_contains($sanitized,'secret123'),'Sensitive log text leaked');
check(miner_finite_decimal('0.135',6)==='0.135000','Rate precision failed');
check(miner_finite_decimal('-1')===null,'Negative rate accepted');
check(miner_valid_salad_slug('hache'),'HACHE valid');
check(miner_valid_salad_slug('interactive'),'INTERACTIVE valid');
check(miner_valid_salad_slug('new-organization-5'),'New organizations supported');
check(!miner_valid_salad_slug('../hache'),'Path traversal organization rejected');
check(!miner_valid_salad_slug('invalid_underscore'),'Invalid organization slug rejected');
$ansi="\x1B[0m[2026-10-09 16:24:37] \x1B[1m\x1B[40m\x1B[31m GPU0 share rejected [stale share] \x1B[0m";
$clean=miner_scrub_log($ansi);
check(str_contains($clean,'GPU0 share rejected'),'Real warning must remain readable');
check(!str_contains($clean,'[0m') && !str_contains($clean,'[31m') && !str_contains($clean,"\x1B"),'ANSI sequences were not removed');
$legacy=miner_scrub_log('[0m[2026-10-09 16:24:37] [1m [40m [31mGPU0 error');
check(str_contains($legacy,'[2026-10-09 16:24:37]') && !str_contains($legacy,'[31m'),'Old log artifact not removed without deleting dates');
check(miner_instance_state(['state'=>['status'=>'running']])==='running','Nested Salad instance state not recognized');
check(miner_instance_state(['state'=>'pending'])==='pending','String instance status fixture not recognized');
check(miner_instance_state(['state'=>['unexpected'=>'value']])==='unknown','Unknown state must not be guessed');
check(miner_instance_ready(['state'=>['status'=>'running'],'ready'=>true,'started'=>true]),'Container readiness lost');
check(!miner_instance_ready(['state'=>['status'=>'running'],'ready'=>true,'started'=>false]),'Startup incomplete must not count ready');
check(!miner_instance_ready(['state'=>['status'=>'pending'],'ready'=>true,'started'=>true]),'Pending container must not count ready');
check(!miner_instance_ready(['state'=>['status'=>'running'],'ready'=>'true','started'=>true]),'Non-boolean readiness must not count');
// GPU price selection must use exact Salad class IDs retained in any group
// (including stopped ones), unioned with the existing price catalog.
$recordedGpu=miner_gpu_rate_class_choices([
    ['gpu_class'=>'nvidia-geforce-rtx4070tisuper'],
    ['gpu_class'=>'nvidia-geforce-rtx4070laptop'],
    ['gpu_class'=>'nvidia-geforce-rtx4070tisuper'],
    ['gpu_class'=>' unknown '],
    ['gpu_class'=>''],
    ['gpu_class'=>null],
    ['gpu_class'=>'  amd-radeon-rx6800  '],
],[
    ['gpu_class'=>'nvidia-geforce-rtx5090'],
    ['gpu_class'=>'nvidia-geforce-rtx4070tisuper'],
    ['gpu_class'=>'n/a'],
]);
check(count($recordedGpu)===4,'Historical GPU classes should be deduplicated, ignore blank/unknown and include saved rates');
check(in_array('nvidia-geforce-rtx4070tisuper',$recordedGpu,true),'Persisted GPU class ID must not change');
check(in_array('amd-radeon-rx6800',$recordedGpu,true),'Leading and trailing whitespace should be removed');
check(in_array('nvidia-geforce-rtx5090',$recordedGpu,true),'Classes from old saved tariffs must remain selectable');
check(miner_gpu_rate_class_choices([],[])===[],'No recorded models must not invent a GPU');
check(miner_gpu_rate_class_choices([['gpu_class'=>str_repeat('A',121)]],[])===[],
    'Oversized GPU IDs must not be presented as rate options');

$numericIds=miner_gpu_rate_class_choices([['gpu_class'=>'123'],['gpu_class'=>'000123'],['gpu_class'=>'123']],[]);
check(count($numericIds)===2 && in_array('000123',$numericIds,true) && in_array('123',$numericIds,true), 'Numeric GPU IDs must stay strings with original leading zeros');
foreach($numericIds as $gpuId)check(is_string($gpuId), 'GPU dropdown ID must be a string');

$saladGpu=miner_rate_parse_salad_gpu_classes(['items'=>[
 ['id'=>'5bac1f6e-e000-40af-a7f9-4469d6ca8888','name'=>'NVIDIA GeForce RTX 4070 Ti SUPER'],
 ['id'=>'abc-5090','name'=>'NVIDIA RTX 5090 Laptop'],
 ['id'=>'abc-5090','name'=>'NVIDIA RTX 5090 Laptop'],
 ['id'=>'invalid/id','name'=>'Invalid'],
 ['id'=>'','name'=>'Empty']
]]);
check(count($saladGpu)===2 && $saladGpu[0]['id']==='5bac1f6e-e000-40af-a7f9-4469d6ca8888',
    'Official Salad GPU catalog must preserve exact IDs, dedupe and sort human names');
try {miner_rate_parse_salad_gpu_classes(['invalid'=>[]]);throw new RuntimeException('Missing catalog accepted');}
catch(UnexpectedValueException $e) {}

$called=[];
$read=static function(string $org)use(&$called):array{
    $called[]=$org;
    if($org==='guanabacoa')throw new RuntimeException('Simulated Salad API outage');
    if($org==='interactive')return [
       ['id'=>'uuid-small','name'=>'RTX 4070 Laptop','source'=>'salad'],
    ];
    return [
       ['id'=>'5bac1f6e-e000-40af-a7f9-4469d6ca8888','name'=>'RTX 4070 Ti SUPER','source'=>'salad']
    ];
};
$catalog=miner_rate_gpu_catalogue(
 [['organization_slug'=>'hache'],['organization_slug'=>'interactive'],['organization_slug'=>'guanabacoa'],
  ['organization_slug'=>'hache']],
 [['organization'=>'hache','gpu_class'=>'5bac1f6e-e000-40af-a7f9-4469d6ca8888'],
  ['organization'=>'guanabacoa','gpu_class'=>'class-guana'],
  ['organization'=>'hache','gpu_class'=>'class-only-old'],
  ['organization'=>'interactive','gpu_class'=>'uuid-small']],
 [['organization'=>'interactive','gpu_class'=>'saved-interactive']],
 $read
);
check(count($called)===3 && count(array_unique($called))===3,'Only one API query per configured organization');
check(count($catalog)===3,'All organizations need separate GPU option groups');
$catalogByOrg=[];
foreach($catalog as $entry)$catalogByOrg[$entry['organization']]=$entry;
check($catalogByOrg['guanabacoa']['live']===false &&
      count($catalogByOrg['guanabacoa']['items'])===1 &&
      $catalogByOrg['guanabacoa']['items'][0]['id']==='class-guana',
      'A failed Salad API must retain only the appropriate org historical classes');
check($catalogByOrg['hache']['live']===true && count($catalogByOrg['hache']['items'])===2,
      'Live official classes must be unioned with saved Hache classes');
check($catalogByOrg['interactive']['live']===true && count($catalogByOrg['interactive']['items'])===2,
      'Saved Interactive rates must remain selectable separately');
check(miner_rate_parse_gpu_selection('guanabacoa|class-guana')['organization']==='guanabacoa',
      'Pricing form must preserve target organization');
check(miner_rate_parse_gpu_selection('interactive|uuid-small')['gpu_class']==='uuid-small',
      'Pricing form must preserve exact Salad class');
foreach(['../hache|gpu','hache|../gpu','hache|','|class','hache|class|another','hache','hache| invalid'] as $bad)
    check(miner_rate_parse_gpu_selection($bad)===null,'Forged or malformed tariff selection accepted');
check(miner_rate_valid_priority('lowest') && miner_rate_valid_priority('low') &&
      miner_rate_valid_priority('medium') && miner_rate_valid_priority('high'),
      'All four Salad priority tiers required');
check(!miner_rate_valid_priority('batch') && !miner_rate_valid_priority(''), 'Unknown priority accepted');
$view=file_get_contents(dirname(__DIR__).'/public/index.php');
check(str_contains($view,'name="rate_gpu_choice"') &&
      str_contains($view,'<option value="lowest">Lowest</option>') &&
      str_contains($view,'miner_rate_valid_priority($priority)'),
      'Production rate form must offer official GPU selector and Lowest');
check(!str_contains($view,'name="gpu_class_custom"'),
      'Manual text field must not override selected Salad GPU');

echo "PASS core-security-regression\n";
