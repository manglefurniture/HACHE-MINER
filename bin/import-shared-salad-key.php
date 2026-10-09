<?php
declare(strict_types=1);
// Private root-only one-time import: reuse HACHE's already provisioned Salad API
// credential without exposing it in a shell command, terminal, repository or log.
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    fwrite(STDERR, "ROOT_CLI_ONLY\n"); exit(2);
}
$source='/etc/hache-salad-monitor.env';
if (!is_file($source) || is_link($source) || filesize($source)>65536 || fileowner($source)!==0) {
    fwrite(STDERR,"LEGACY_SALAD_SOURCE_NOT_TRUSTED\n");exit(2);
}
$contents=file_get_contents($source);
if (!is_string($contents)) {
    fwrite(STDERR,"LEGACY_SALAD_SOURCE_NOT_READABLE\n");exit(2);
}
function miner_import_find_key(string $contents): string {
    $values=[];
    foreach (preg_split('/\r\n|\n|\r/', $contents) as $line) {
        if (preg_match('/^\s*SALAD_API_KEY\s*=\s*(.*?)\s*$/D', $line, $m)) {
            $value=trim($m[1]);
            if (strlen($value)>=2 && in_array($value[0], ['"', "'"], true) && substr($value,-1)===$value[0]) {
                $value=substr($value,1,-1);
            }
            $values[]=$value;
        }
    }
    if (count($values)!==1 || strlen($values[0])<16 || strlen($values[0])>1024
        || preg_match('/[^\x21-\x7E]/',$values[0])) {
        throw new RuntimeException('Legacy Salad credential invalid or ambiguous');
    }
    return $values[0];
}
try {
    $key=miner_import_find_key($contents);
    sodium_memzero($contents);
    require_once '/etc/hache-miner/load-env.php';
    require_once dirname(__DIR__).'/app/core.php';
    $existing=miner_get_secret('salad:api-key');
    if ($existing !== null) {
        sodium_memzero($key);
        sodium_memzero($existing);
        echo "SALAD_SHARED_KEY_ALREADY_CONFIGURED\n";
        exit(0);
    }
    miner_put_secret('salad:api-key',$key);
    sodium_memzero($key);
    miner_audit(null,'bootstrap_shared_salad_key_imported','source legacy monitor; value never logged');
    echo "SALAD_SHARED_KEY_IMPORTED_ENCRYPTED\n";
} catch (Throwable $e) {
    error_log('[hache-miner] shared-key-import-failed '.get_class($e));
    fwrite(STDERR,"SALAD_SHARED_KEY_IMPORT_FAILED\n");
    exit(1);
}
