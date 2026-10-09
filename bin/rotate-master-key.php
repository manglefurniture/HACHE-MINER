<?php
declare(strict_types=1);
// Private one-time master-key rotation. NEVER print either key or decrypted values.
// Run only via the root-only deploy/rotate-master-key.sh wrapper.
if (PHP_SAPI!=='cli' || !function_exists('posix_geteuid') || posix_geteuid()!==0) {
    fwrite(STDERR,"ROOT_ONLY\n");exit(2);
}
require_once '/etc/hache-miner/load-env.php';
require_once dirname(__DIR__).'/app/core.php';
$keyPath=(string)getenv('MINER_MASTER_KEY_FILE');
$backupDir=(string)getenv('MINER_ROTATION_BACKUP_DIR');
if ($keyPath!=='/var/lib/hache-miner/master.key' || !is_dir($backupDir)
    || !str_starts_with($backupDir,'/root/hache-miner-key-rotation/')
    || is_link($keyPath) || !is_file($keyPath)) {
    fwrite(STDERR,"ROTATION_PREFLIGHT_INVALID\n");exit(2);
}
$handle=fopen('/var/lib/hache-miner/master-rotation.lock','c');
if (!$handle || !flock($handle,LOCK_EX|LOCK_NB)) {
    fwrite(STDERR,"ROTATION_ALREADY_RUNNING\n");exit(2);
}
$newPath='/var/lib/hache-miner/.master.key.'.bin2hex(random_bytes(8)).'.tmp';
$oldBackup=$backupDir.'/previous-master.key';
$oldKey=null;$newKey=null;$swapped=false;$committed=false;
$db=null;
try {
    if (file_exists($oldBackup)) throw new RuntimeException('Backup already exists');
    $oldKey=miner_key();
    $newKey=random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $db=miner_db();
    $db->beginTransaction();
    $rows=$db->query('SELECT id,ciphertext,nonce FROM secret_store ORDER BY id FOR UPDATE')->fetchAll();
    $updated=[];
    foreach ($rows as $row) {
        $plain=miner_open((string)$row['ciphertext'],(string)$row['nonce'],$oldKey);
        $sealed=miner_seal($plain,$newKey);
        $updated[]=[(int)$row['id'],$sealed['ciphertext'],$sealed['nonce']];
        sodium_memzero($plain);
    }
    $previous=file_get_contents($keyPath);
    if (!is_string($previous) || trim($previous)==='') throw new RuntimeException('Cannot read previous key');
    $bh=fopen($oldBackup,'x');
    if (!$bh) throw new RuntimeException('Cannot create backup');
    chmod($oldBackup,0600);
    if (fwrite($bh,$previous)!==strlen($previous)) { fclose($bh);throw new RuntimeException('Backup write failed');}
    fflush($bh);fclose($bh);
    sodium_memzero($previous);
    $newEncoded=base64_encode($newKey)."\n";
    $fh=fopen($newPath,'x');
    if (!$fh) throw new RuntimeException('Cannot stage replacement');
    if (fwrite($fh,$newEncoded)!==strlen($newEncoded)){fclose($fh);throw new RuntimeException('Key stage failed');}
    fflush($fh);fclose($fh);
    chmod($newPath,0600);
    if (!chown($newPath,'hache-miner') || !chgrp($newPath,'hache-miner')) throw new RuntimeException('Key owner mismatch');
    $stmt=$db->prepare('UPDATE secret_store SET ciphertext=?,nonce=? WHERE id=?');
    foreach ($updated as [$id,$cipher,$nonce]) $stmt->execute([$cipher,$nonce,$id]);
    if (!rename($newPath,$keyPath)) throw new RuntimeException('Key replacement failed');
    $swapped=true;
    $db->commit();
    $committed=true;
    foreach($rows as $row){
        $nameSt=$db->prepare('SELECT secret_name FROM secret_store WHERE id=?');
        $nameSt->execute([(int)$row['id']]);
        $name=$nameSt->fetchColumn();
        if (!is_string($name) || miner_get_secret($name)===null) throw new RuntimeException('Post-rotation verification failed');
    }
    miner_audit(null,'master_key_rotated','Private recovery material stored locally; export new key off-host securely');
    echo "MASTER_KEY_ROTATED_AND_SECRETS_VERIFIED\n";
    echo "REENCRYPTED_SECRET_RECORDS=".count($updated)."\n";
    echo "NEW_KEY_BACKUP_OFF_HOST_STILL_REQUIRED\n";
} catch(Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    if ($swapped && !$committed && is_file($oldBackup)) {
        copy($oldBackup,$keyPath);
        chown($keyPath,'hache-miner');chgrp($keyPath,'hache-miner');chmod($keyPath,0600);
    }
    error_log('[hache-miner] master-key-rotation '.get_class($e));
    fwrite(STDERR,"MASTER_KEY_ROTATION_FAILED\n");
    exit(1);
} finally {
    if (is_file($newPath)) unlink($newPath);
    if (is_string($oldKey)) sodium_memzero($oldKey);
    if (is_string($newKey)) sodium_memzero($newKey);
    flock($handle,LOCK_UN);fclose($handle);
}
