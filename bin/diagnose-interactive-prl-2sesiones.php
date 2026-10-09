<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/core.php';
require_once dirname(__DIR__).'/app/monitor.php';

if(PHP_SAPI!=='cli' || !function_exists('posix_geteuid') || posix_geteuid()!==0){
    fwrite(STDERR,"ROOT_ONLY_READ_ONLY_DIAG\n");exit(2);
}
$org='interactive';$project='default';$group='prl-2sesiones';
$key=miner_shared_salad_api_key();
if(!is_string($key)||strlen($key)<12){echo "API_KEY_UNAVAILABLE\n";exit(2);}
$urls=[
 'instances'=>'https://api.salad.com/api/public/organizations/'.$org.'/projects/'.$project.'/containers/'.$group.'/instances',
 'logs'=>'https://api.salad.com/api/public/organizations/'.$org.'/log-entries',
];
function diag_request(string $url,string $key,?array $payload=null):array {
    $ch=curl_init($url);
    $opts=[
       CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
       CURLOPT_CONNECTTIMEOUT=>7,CURLOPT_TIMEOUT=>20,
       CURLOPT_HTTPHEADER=>['Salad-Api-Key: '.$key,'Content-Type: application/json'],
    ];
    if($payload!==null){$opts[CURLOPT_POST]=true;$opts[CURLOPT_POSTFIELDS]=json_encode($payload,JSON_THROW_ON_ERROR);}
    curl_setopt_array($ch,$opts);
    $result=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if(!is_string($result))return ['status'=>$code,'data'=>null];
    $decoded=json_decode($result,true);
    return ['status'=>$code,'data'=>is_array($decoded)?$decoded:null];
}
function diag_fmt_time(mixed $date):string {
    if(!is_string($date) || strlen($date)>36)return 'unknown';
    $when=strtotime($date);
    return $when===false?'unknown':gmdate('Y-m-d H:i:s',$when);
}
echo "SALAD_GROUP_LOG_DIAGNOSTIC_V1\n";
echo "TARGET=INTERACTIVE/default/prl-2sesiones\n";
echo "UTC=".gmdate('Y-m-d H:i:s')."\n";
$instances=diag_request($urls['instances'],$key);
echo "INSTANCES_HTTP=".$instances['status']."\n";
$nodes=is_array($instances['data']['instances']??null)?$instances['data']['instances']:[];
$active=0;
foreach($nodes as $node)if(is_array($node) && miner_instance_ready($node))$active++;
echo "INSTANCES_LISTED=".count($nodes)."\nINSTANCES_READY=".$active."\n";
$state=miner_db()->prepare('SELECT id,desired_replicas,state,last_seen_at FROM group_state WHERE organization=? AND project_name=? AND group_name=? LIMIT 1');
$state->execute([$org,$project,$group]);$g=$state->fetch(PDO::FETCH_ASSOC);
if(!$g){echo "GROUP_IN_DB=NO\n";exit(2);}
$groupId=(int)$g['id'];
echo "GROUP_IN_DB=YES\nGROUP_DESIRED=".$g['desired_replicas']."\nGROUP_STATE=".$g['state']."\nLAST_GROUP_SYNC_UTC=".$g['last_seen_at']."\n";
$db=miner_db();
$counts=$db->prepare("SELECT COUNT(*) AS total,SUM(hashrate_ths IS NOT NULL) AS measured,
 MIN(observed_at) AS oldest,MAX(observed_at) AS newest
 FROM miner_observations WHERE group_id=? AND observed_at>=UTC_TIMESTAMP()-INTERVAL 60 MINUTE");
$counts->execute([$groupId]);$d=$counts->fetch(PDO::FETCH_ASSOC);
echo "DB_INSTANCES_SAMPLES_60M=".$d['total']."\nDB_SAMPLES_WITH_THS_60M=".$d['measured']."\n";
$dbLogs=$db->prepare("SELECT COUNT(*) AS total,SUM(summary LIKE '%TH/s%') AS ths_mention,
 SUM(summary LIKE '%GPU%') AS gpu_mention,MAX(logged_at) AS latest
 FROM log_events WHERE group_id=? AND logged_at>=UTC_TIMESTAMP()-INTERVAL 60 MINUTE");
$dbLogs->execute([$groupId]);$l=$dbLogs->fetch(PDO::FETCH_ASSOC);
echo "DB_LOG_LINES_60M=".$l['total']."\nDB_THS_MENTIONS_60M=".$l['ths_mention']."\nDB_GPU_MENTIONS_60M=".$l['gpu_mention']."\nDB_LAST_LOG_UTC=".($l['latest']??'unknown')."\n";
$end=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$filters=[
 'collector_same'=>'resource.type = "container" and resource.labels.project_name = "'.$project.'" and resource.labels.container_group_name = "'.$group.'"',
 'group_only'=>'resource.labels.container_group_name = "'.$group.'"'
];
foreach($filters as $label=>$query){
    $body=[
      'end_time'=>$end->format('Y-m-d\TH:i:s\Z'),
      'start_time'=>$end->sub(new DateInterval('PT25M'))->format('Y-m-d\TH:i:s\Z'),
      'query'=>$query,'page_size'=>100,'sort_order'=>'desc'
    ];
    $res=diag_request($urls['logs'],$key,$body);
    echo "LOG_QUERY_".strtoupper($label)."_HTTP=".$res['status']."\n";
    $items=is_array($res['data']['items']??null)?$res['data']['items']:[];
    echo "LOG_QUERY_".strtoupper($label)."_ROWS=".count($items)."\n";
    if($res['status']!==200)continue;
    $stats=['has_text'=>0,'has_json'=>0,'gpu_text'=>0,'ths_text'=>0,'recognized_gpu_metric'=>0,
       'recognized_and_instance_matched'=>0,'recognized_unattributed'=>0,'identity_label_present'=>0];
    $resourceKeys=[];$labelKeys=[];$structKeys=[];$metricModels=[];$lastTextAt='unknown';
    foreach($items as $item){
        if(!is_array($item))continue;
        $structKeys=array_merge($structKeys,array_keys($item));
        $resource=is_array($item['resource']??null)?$item['resource']:[];
        $resourceKeys=array_merge($resourceKeys,array_keys($resource));
        $labels=is_array($resource['labels']??null)?$resource['labels']:[];
        $labelKeys=array_merge($labelKeys,array_keys($labels));
        if($labels) {
            foreach(array_keys($labels) as $k) {
                if(str_contains(strtolower($k),'instance')||str_contains(strtolower($k),'machine'))
                    $stats['identity_label_present']++;
            }
        }
        $raw=(string)($item['text_log']??$item['message']??'');
        if($raw!==''){$stats['has_text']++;if($lastTextAt==='unknown')$lastTextAt=diag_fmt_time($item['time']??null);}
        if(is_array($item['json_log']??null) && $item['json_log']!==[])$stats['has_json']++;
        if(stripos($raw,'GPU')!==false)$stats['gpu_text']++;
        if(stripos($raw,'TH/s')!==false)$stats['ths_text']++;
        $metric=miner_monitor_log_metric($raw);
        if($metric!==null){
           $stats['recognized_gpu_metric']++;
           $model=(string)$metric['gpu'];
           $metricModels[$model]=true;
           $matched=miner_monitor_log_instance($item,$nodes);
           if($matched===null)$stats['recognized_unattributed']++;
           else $stats['recognized_and_instance_matched']++;
        }
    }
    foreach($stats as $name=>$count)echo strtoupper($label).'_'.strtoupper($name).'='.$count."\n";
    echo strtoupper($label).'_LATEST_TEXT_UTC='.$lastTextAt."\n";
    // Only key names are displayed. Never print raw logs or label values.
    $safeKeys=static function(array $keys):string{
        $keys=array_values(array_filter(array_unique($keys),static fn($k)=>preg_match('/^[a-zA-Z0-9_]{1,64}$/D',(string)$k)));
        sort($keys);return implode(',',array_slice($keys,0,35));
    };
    echo strtoupper($label).'_FIELD_KEYS='.$safeKeys($structKeys)."\n";
    echo strtoupper($label).'_RESOURCE_KEYS='.$safeKeys($resourceKeys)."\n";
    echo strtoupper($label).'_LABEL_KEYS='.$safeKeys($labelKeys)."\n";
    // The short GPU model is not a credential; never print the miner line.
    $cleanModels=array_map(static fn($m)=>preg_replace('/[^a-zA-Z0-9 .+_-]/','',substr($m,0,80)),array_keys($metricModels));
    echo strtoupper($label).'_PARSED_GPU_MODELS='.implode(' | ',array_slice($cleanModels,0,4))."\n";
}
echo "DIAGNOSTIC_COMPLETE_NO_GPU_ACTIONS_NO_SECRETS_PRINTED\n";
