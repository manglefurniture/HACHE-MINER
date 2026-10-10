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
/**
 * Report only the shape of a TH/s log line, NOT its raw contents. Unknown
 * words, wallet IDs, addresses, UUIDs, hostnames, URLs and long numbers
 * are replaced. This is enough to determine SRBMiner output grammar safely.
 */
function diag_safe_ths_shape(string $raw): ?string {
    $line=miner_scrub_log($raw);
    $index=stripos($line,'TH/s');
    if($index===false)return null;
    $fragment=substr($line,max(0,$index-115),150);
    $keep=[
       'gpu','gpuhashrate','hashrate','hash','speed','hashrateavg','current','total',
       'min','mins','minute','minutes','avg','average','reported','instant','instantaneous',
       'srbminer','miner','device','devices','deviceid','gpuid','pool','worker','local',
       'nvidia','geforce','rtx','laptop','ti','super','mh','gh','kh','th','s','w',
       'c','fan','temp','power','core','accepted','rejected','share','shares',
       'thread','threads','mining','rate','h','sec','watts','elapsed','overall',
       'ethash','pearlhash','vram','cuda','opencl','mhz','bus','id'
    ];
    $result=preg_replace_callback('/[A-Za-z0-9_+.-]+/',static function(array $matches) use($keep):string {
        $token=$matches[0];
        if(preg_match('/^gpu[0-9]{1,2}$/i',$token))return 'GPU#';
        if(preg_match('/^[0-9]+(?:\.[0-9]+)?$/D',$token))return '#';
        return in_array(strtolower($token),$keep,true)?$token:'WORD';
    },$fragment) ?? '';
    // Keep only harmless ASCII separators; do not leak raw control characters.
    $result=preg_replace('/[^a-zA-Z0-9#\[\](){}\\/:.,;|%+><=\-_\s]/','?',$result)??'';
    return substr(preg_replace('/\s+/',' ',$result)??'',0,180);
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
    // Salad serves paged results (max 100). Counts are only of this one page.
    // Never imply that a 25-minute interval was scanned completely at the cap.
    echo "LOG_QUERY_".strtoupper($label)."_PAGE_SIZE_LIMIT=100\n";
    echo "LOG_QUERY_".strtoupper($label)."_MAY_BE_TRUNCATED=".(count($items)>=100?'YES':'NO')."\n";
    if($res['status']!==200)continue;
    $stats=['has_text'=>0,'has_json'=>0,'gpu_text'=>0,'ths_text'=>0,'recognized_gpu_metric'=>0,
       'recognized_and_instance_matched'=>0,'recognized_unattributed'=>0,'identity_label_present'=>0];
    $resourceKeys=[];$labelKeys=[];$structKeys=[];$lastTextAt='unknown';
    $hasThWithMatchedInstance=0;$hasThAndGpuSameLine=0;$safeShapes=[];
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
        if(stripos($raw,'TH/s')!==false){
            $stats['ths_text']++;
            if(stripos($raw,'GPU')!==false)$hasThAndGpuSameLine++;
            if(miner_monitor_log_instance($item,$nodes)!==null)$hasThWithMatchedInstance++;
            $shape=diag_safe_ths_shape($raw);
            if($shape!==null)$safeShapes[$shape]=($safeShapes[$shape]??0)+1;
        }
        $metric=miner_monitor_log_metric($raw);
        if($metric!==null){
           $stats['recognized_gpu_metric']++;
           $matched=miner_monitor_log_instance($item,$nodes);
           if($matched===null)$stats['recognized_unattributed']++;
           else $stats['recognized_and_instance_matched']++;
        }
    }
    foreach($stats as $name=>$count)echo strtoupper($label).'_'.strtoupper($name).'='.$count."\n";
    echo strtoupper($label).'_LATEST_TEXT_UTC='.$lastTextAt."\n";
    echo strtoupper($label).'_THS_WITH_GPU_SAME_LINE='.$hasThAndGpuSameLine."\n";
    echo strtoupper($label).'_THS_WITH_UNIQUE_INSTANCE='.$hasThWithMatchedInstance."\n";
    arsort($safeShapes);
    $shown=0;
    foreach($safeShapes as $shape=>$count){
        if(++$shown>6)break;
        echo strtoupper($label).'_SANITIZED_SHAPE_'.$shown.'_COUNT='.$count."\n";
        echo strtoupper($label).'_SANITIZED_SHAPE_'.$shown.'='.$shape."\n";
    }
    // Only key names are displayed. Never print raw logs or label values.
    $safeKeys=static function(array $keys):string{
        $keys=array_values(array_filter(array_unique($keys),static fn($k)=>preg_match('/^[a-zA-Z0-9_]{1,64}$/D',(string)$k)));
        sort($keys);return implode(',',array_slice($keys,0,35));
    };
    echo strtoupper($label).'_FIELD_KEYS='.$safeKeys($structKeys)."\n";
    echo strtoupper($label).'_RESOURCE_KEYS='.$safeKeys($resourceKeys)."\n";
    echo strtoupper($label).'_LABEL_KEYS='.$safeKeys($labelKeys)."\n";
    // Even parsed GPU model strings may contain private IDs; never print any.
    echo strtoupper($label).'_PARSED_GPU_MODELS=REDACTED'."\n";
}
echo "DIAGNOSTIC_COMPLETE_NO_GPU_ACTIONS_NO_SECRETS_PRINTED\n";
