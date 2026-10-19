<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/vast-auto-stop.php';
if(PHP_SAPI!=='cli') exit(2);
date_default_timezone_set('UTC');
// Fail closed until an operator explicitly arms THIS instance with an UTC timestamp.
$dir='/var/lib/hache-miner';
$armedFile='/etc/hache-miner/vast-stop-56667162-armed-at';
$keyFile='/etc/hache-miner/vast-stop-api-key';
$lockFile=$dir.'/vast-auto-stop.lock';
$doneFile=$dir.'/vast-auto-stop-56667162.done';
if(!is_dir($dir)||is_link($dir)) exit(2);
$lock=fopen($lockFile,'c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)) exit(2);
try {
    if(is_file($doneFile)) { echo "ALREADY_STOPPED\n"; exit(0); }
    if(!is_file($armedFile)||is_link($armedFile)||!is_readable($armedFile)) { echo "NOT_ARMED\n"; exit(0); }
    $armed=filter_var(trim((string)file_get_contents($armedFile)),FILTER_VALIDATE_INT);
    if($armed===false||$armed<=0||$armed>time()) throw new RuntimeException('Invalid arming');
    $snapshots=miner_vast_wd_snapshot();
    $target=null;
    foreach($snapshots as $s) if((string)$s['instance']['instance_id']==='56667162') $target=$s;
    if($target===null) throw new RuntimeException('Target has no telemetry registration');
    $reason=miner_vast_stop_reason($target['instance'],$target['samples'],time(),$armed);
    if($reason===null){echo "HEALTHY_OR_NOT_CONFIRMED\n";exit(0);}
    if(!is_file($keyFile)||is_link($keyFile)||!is_readable($keyFile)) throw new RuntimeException('Vast stop key unavailable');
    $key=trim((string)file_get_contents($keyFile));
    if(!miner_vast_stop_api($key,'56667162')) throw new RuntimeException('Vast stop unconfirmed');
    // Persistent idempotency marker; never automatically restart a stopped rental.
    if(file_put_contents($doneFile,gmdate(DATE_ATOM).' '.$reason."\n",LOCK_EX)===false) throw new RuntimeException('Stop marker failed');
    chmod($doneFile,0600);
    echo "STOP_CONFIRMED reason=".$reason." instance=56667162\n";
} catch(Throwable $e) {
    error_log('[vast-auto-stop] '.get_class($e)); // never log credentials
    fwrite(STDERR,"VAST_AUTO_STOP_FAILED\n");exit(1);
} finally {
    flock($lock,LOCK_UN);fclose($lock);
}
