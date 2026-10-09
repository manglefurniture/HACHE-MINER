<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/core.php';
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
if ($argc!==2 || !in_array($argv[1],['make-key','create-admin'],true)) {
    fwrite(STDERR,"Uso: php bin/bootstrap.php make-key|create-admin\n");
    exit(2);
}
if($argv[1]==='make-key') {
    $path=getenv('MINER_MASTER_KEY_FILE')?:'';
    if($path==='' || file_exists($path) || is_link($path) || !is_dir(dirname($path))) {
        fwrite(STDERR,"La ruta privada debe existir y el archivo NO debe existir.\n");exit(2);
    }
    umask(0077);
    $key=base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES))."\n";
    $fh=@fopen($path,'x');
    if(!$fh) {fwrite(STDERR,"No se pudo crear clave.\n");exit(1);}
    fwrite($fh,$key);fclose($fh);chmod($path,0600);
    echo "KEY_CREATED (guarda una copia cifrada fuera del VPS; sin ella no se recuperan secretos)\n";
    exit;
}
$username=getenv('MINER_ADMIN_USER')?:'admin';
if(!preg_match('/^[a-zA-Z0-9_-]{3,64}$/D',$username)){fwrite(STDERR,"Nombre de usuario inválido.\n");exit(2);}
if(!defined('STDIN') || !stream_isatty(STDIN)){fwrite(STDERR,"Ejecutar desde consola interactiva.\n");exit(2);}
fwrite(STDOUT,"Contraseña nueva (la entrada puede verse en algunas consolas; usa terminal privado): ");
$password=trim((string)fgets(STDIN),"\r\n");
if(strlen($password)<16 || strlen($password)>512){fwrite(STDERR,"Contraseña entre 16 y 512 caracteres.\n");exit(2);}
if((int)miner_db()->query('SELECT COUNT(*) FROM administrators')->fetchColumn()!==0) {
    fwrite(STDERR,"Administrador ya existe. Crear/restablecer vía procedimiento auditado.\n");exit(2);
}
$hash=password_hash($password,PASSWORD_ARGON2ID,['memory_cost'=>19456,'time_cost'=>2,'threads'=>1]);
sodium_memzero($password);
$st=miner_db()->prepare('INSERT INTO administrators(username,password_hash) VALUES (?,?)');$st->execute([$username,$hash]);
miner_audit((int)miner_db()->lastInsertId(),'initial_admin_created','provisioning');
echo "ADMIN_CREATED\n";
