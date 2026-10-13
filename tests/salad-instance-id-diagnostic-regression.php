<?php
declare(strict_types=1);
require_once __DIR__.'/../app/salad-instance-id-diagnostic.php';
function idmap_assert(bool $truth,string $message):void {
    if(!$truth)throw new RuntimeException($message);
}
$now=strtotime('2026-10-13T15:07:00Z');
$nodes=[
    ['instance_id'=>'gpu-a','machine_id'=>'machine-a','state'=>'running',
        'ready'=>true,'started'=>true,'update_time'=>'2026-10-13T15:00:00Z'],
    ['instance_id'=>'gpu-b','machine_id'=>'machine-b','state'=>'running',
        'ready'=>true,'started'=>true,'update_time'=>'2026-10-13T15:00:00Z'],
    ['instance_id'=>'gpu-c','machine_id'=>'machine-c','state'=>'downloading',
        'ready'=>false,'started'=>false,'update_time'=>'2026-10-13T15:00:00Z']
];
$line='#0 RTX 4070 Ti SUPER 161.25 TH/s 235W 0.68 50% 63C';
$known=['time'=>'2026-10-13T15:06:00Z','text_log'=>$line,
    'resource'=>['labels'=>['instance_id'=>'gpu-a']]];
$other=['time'=>'2026-10-13T15:06:03Z','text_log'=>$line,
    'resource'=>['labels'=>['instance_id'=>'gpu-b']]];
$foreign=['time'=>'2026-10-13T15:06:01Z','text_log'=>$line,
    'resource'=>['labels'=>['instance_id'=>'gpu-expired']]];
$anonymous=['time'=>'2026-10-13T15:06:02Z','text_log'=>$line];
$old=['time'=>'2026-10-13T14:40:00Z','text_log'=>$line,
    'resource'=>['labels'=>['instance_id'=>'gpu-b']]];
$coverage=['requests'=>3,'partial'=>false,'unique'=>5];
$report=miner_idmap_diagnostic($nodes,[$known,$foreign,$anonymous,$old],$coverage,$now);
$c=$report['counts'];
idmap_assert($report['status']==='partial','Incomplete identity must be reported partial');
idmap_assert($c['nodes']===3 && $c['ready']===2,'Ready inventory must use real Salad instance state');
idmap_assert($c['gpu_rows']===4 && $c['matched_rows']===1
    && $c['unknown_rows']===1 && $c['anonymous_rows']===1
    && $c['old_rows']===1,'Separate matched, stale, missing and anonymous logs');
idmap_assert($c['matched_ids']===1 && $c['measured']===1 && $c['missing']===1,
    'Only live ready instances with provable metrics count as measured');
idmap_assert($c['requests']===3 && $c['partial']===0 && $c['api_error']===0,
    'Pagination and API status must be retained');
idmap_assert(strlen($report['detail'])<=180
    && !str_contains($report['detail'],'gpu-a')
    && !str_contains($report['detail'],'gpu-expired')
    && !str_contains($report['detail'],'machine-a'),
    'Persistent diagnostics must contain compact numeric aggregates, never identifiers');
$healthy=miner_idmap_diagnostic($nodes,[$known,$other],$coverage,$now);
idmap_assert($healthy['status']==='ok'
    && $healthy['counts']['ready']===2
    && $healthy['counts']['measured']===2
    && $healthy['counts']['missing']===0,'Complete mapping must be healthy');
$error=miner_idmap_diagnostic($nodes,[],null,$now);
idmap_assert($error['status']==='partial'
    && $error['counts']['api_error']===1
    && $error['counts']['missing']===2,
    'Failed log endpoint may not be presented as zero production');
$partial=miner_idmap_diagnostic($nodes,[$known,$other],
    ['requests'=>8,'partial'=>true],$now);
idmap_assert($partial['status']==='partial'
    && $partial['counts']['measured']===2
    && $partial['counts']['partial']===1,
    'Bounded API pages must retain measured IDs while marking incomplete coverage');
$poll=file_get_contents(__DIR__.'/../bin/poll.php');
idmap_assert(str_contains($poll,'miner_idmap_diagnostic($nodes,$logs,$coverage)')
    && str_contains($poll,"miner_run_record('salad-idmap:'"),
    'Collector must compare the two existing endpoint payloads without additional requests');
$admin=file_get_contents(__DIR__.'/../app/diagnostics.php');
idmap_assert(str_contains($admin,"miner_log_sync_source_key('salad-idmap:'")
    && !str_contains($admin,'curl_init('),
    'Admin page must only read already-persisted safe diagnostic data');
echo "SALAD_IDMAP_REGRESSION_OK\n";
