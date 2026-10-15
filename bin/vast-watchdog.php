<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/vast-watchdog.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit(2); }
date_default_timezone_set('UTC');
$dryRun=($argv[1]??'')==='--dry-run';
if (!in_array($argv[1]??'',['','--dry-run'],true)) { fwrite(STDERR,"Usage: vast-watchdog.php [--dry-run]\n");exit(2); }
$dir='/var/lib/hache-miner';
$lockFile=$dir.'/vast-watchdog.lock';
$stateFile=$dir.'/vast-watchdog-state.json';
if (!is_dir($dir) || is_link($dir)) { fwrite(STDERR,"VAST_WATCHDOG_DIR_MISSING\n");exit(2); }
$lock=fopen($lockFile,'c');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { echo "VAST_WATCHDOG_LOCKED\n";exit(0); }
try {
    $topic='';
    if (!$dryRun) {
        $path='/etc/hache-miner/vast-ntfy-topic';
        if (!is_file($path) || is_link($path) || !is_readable($path)) throw new RuntimeException('Private Vast ntfy topic missing');
        $topic=trim((string)file_get_contents($path));
        if (!preg_match('/^[A-Za-z0-9_-]{16,200}$/D',$topic)) throw new RuntimeException('Invalid private Vast ntfy topic');
    }
    $result=miner_vast_wd_run(miner_vast_wd_snapshot(),$stateFile,$topic,time(),$dryRun);
    echo ($dryRun?'VAST_WATCHDOG_DRY_RUN_OK':'VAST_WATCHDOG_OK').' instances='.$result['instances'].' issues='.$result['issues'].' notifications='.$result['sent']."\n";
} catch (Throwable $e) {
    error_log('[vast-watchdog] '.get_class($e)); // never leak topic, SQL, credentials or payloads
    fwrite(STDERR,"VAST_WATCHDOG_FAILED\n");exit(1);
} finally {
    flock($lock,LOCK_UN);fclose($lock);
}
