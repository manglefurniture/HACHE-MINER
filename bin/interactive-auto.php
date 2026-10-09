<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/interactive-auto.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit(2);}
date_default_timezone_set('UTC');
$lockFile='/var/lib/hache-miner/interactive-auto.lock';
if(!is_dir(dirname($lockFile)) || is_link($lockFile)){fwrite(STDERR,"AUTO_LOCK_DIRECTORY_MISSING\n");exit(2);}
$handle=fopen($lockFile,'c');
if(!$handle || !flock($handle,LOCK_EX|LOCK_NB)){echo "AUTO_BUSY\n";exit(0);}
try {
    $candidates=miner_auto_candidates(time());
    echo 'AUTO_MODE='.(miner_auto_enabled()?'enabled':'dry_run')."\n";
    echo 'ELIGIBLE_INTERACTIVE_PRL_INSTANCES='.count($candidates)."\n";
    $processed=0;
    foreach(array_slice($candidates,0,3) as $candidate){
        if(!miner_auto_enabled()){echo "DRY_RUN_ELIGIBLE_INSTANCE\n";continue;}
        $processed++;
        try{
            $status=miner_auto_execute($candidate);
            echo 'REALLOCATION_RESULT='.$status."\n";
        } catch(Throwable $e){
            // Never print API key, wallet, full instance ID or external payload.
            error_log('[interactive-auto] '.get_class($e));
            echo "REALLOCATION_RESULT=error\n";
        }
    }
    echo 'MAX_ACTIONS_PER_RUN=3'."\n";
} catch(Throwable $e) {
    error_log('[interactive-auto] '.get_class($e));
    echo "INTERACTIVE_AUTO_CHECK_FAILED\n";
    exit(1);
} finally {
    flock($handle,LOCK_UN);
    fclose($handle);
}
