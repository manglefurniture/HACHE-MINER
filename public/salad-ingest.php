<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/salad-telemetry.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'
        ||strtolower((string)($_SERVER['CONTENT_TYPE']??''))!=='application/json')
        throw new InvalidArgumentException('Invalid request');
    $authorization=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
    if(!preg_match('/^Bearer ([A-Za-z0-9_.-]{100,8192})$/D',$authorization,$match))
        throw new InvalidArgumentException('Invalid identity');
    miner_salad_telemetry_ingest(
        (string)file_get_contents('php://input'),
        (string)($_SERVER['HTTP_X_HACHE_TIMESTAMP']??''),
        (string)($_SERVER['HTTP_X_HACHE_SIGNATURE']??''),
        $match[1]);
    http_response_code(202);echo '{"status":"accepted"}';
} catch(Throwable $e) {
    http_response_code(400);echo '{"status":"rejected"}';
    error_log('[hache-miner] salad-ingest '.get_class($e));
}
