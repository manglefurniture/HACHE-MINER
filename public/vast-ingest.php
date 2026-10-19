<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/vast.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try { if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||($_SERVER['CONTENT_TYPE']??'')!=='application/json')throw new InvalidArgumentException('Invalid request'); miner_vast_ingest((string)file_get_contents('php://input'),(string)($_SERVER['HTTP_X_HACHE_TIMESTAMP']??''),(string)($_SERVER['HTTP_X_HACHE_SIGNATURE']??''),(string)($_SERVER['HTTP_X_HACHE_KEY_VERSION']??'')); http_response_code(202);echo '{"status":"accepted"}'; }
catch(Throwable $e){http_response_code(400);echo '{"status":"rejected"}';error_log('[hache-miner] vast-ingest '.get_class($e));}
