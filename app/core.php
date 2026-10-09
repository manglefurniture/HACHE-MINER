<?php
declare(strict_types=1);

const MINER_TIMEZONE = 'America/Cancun';
const MINER_ORGANIZATIONS = ['hache' => 'prl-tests', 'interactive' => 'default'];

function miner_db(): PDO {
    static $db = null;
    if ($db instanceof PDO) return $db;
    $dsn = getenv('MINER_DB_DSN') ?: '';
    $user = getenv('MINER_DB_USER') ?: '';
    $pass = getenv('MINER_DB_PASSWORD') ?: '';
    if ($dsn === '' || !str_starts_with($dsn, 'mysql:')) throw new RuntimeException('MINER_DB_DSN no configurado.');
    $db = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $db->exec("SET time_zone = '+00:00'");
    return $db;
}
function miner_key(): string {
    $path = getenv('MINER_MASTER_KEY_FILE') ?: '';
    if ($path === '' || !is_file($path) || is_link($path)) throw new RuntimeException('Falta la clave maestra privada.');
    $encoded = trim((string)file_get_contents($path));
    $key = base64_decode($encoded, true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('Clave maestra inválida.');
    return $key;
}
function miner_seal(string $plain, string $key): array {
    if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new InvalidArgumentException('Clave inválida.');
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return ['ciphertext' => base64_encode(sodium_crypto_secretbox($plain,$nonce,$key)), 'nonce'=>base64_encode($nonce)];
}
function miner_open(string $cipher, string $nonce, string $key): string {
    $c = base64_decode($cipher,true);
    $n = base64_decode($nonce,true);
    if ($c === false || $n === false || strlen($n) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new RuntimeException('Secreto corrupto.');
    $plain = sodium_crypto_secretbox_open($c,$n,$key);
    if ($plain === false) throw new RuntimeException('No se pudo descifrar el secreto.');
    return $plain;
}
function miner_get_secret(string $name): ?string {
    $st = miner_db()->prepare('SELECT ciphertext, nonce FROM secret_store WHERE secret_name=?');
    $st->execute([$name]); $row = $st->fetch();
    return $row ? miner_open($row['ciphertext'],$row['nonce'],miner_key()) : null;
}
function miner_put_secret(string $name, string $value): void {
    if (!preg_match('/^[a-z0-9:_-]{3,120}$/D', $name) || strlen($value) > 4096 || $value === '') throw new InvalidArgumentException('Nombre o valor de secreto inválido.');
    $c = miner_seal($value,miner_key());
    $st = miner_db()->prepare('INSERT INTO secret_store (secret_name,ciphertext,nonce) VALUES (?,?,?) ON DUPLICATE KEY UPDATE ciphertext=VALUES(ciphertext),nonce=VALUES(nonce)');
    $st->execute([$name,$c['ciphertext'],$c['nonce']]);
}
function miner_audit(?int $uid, string $action, string $detail=''): void {
    $st=miner_db()->prepare('INSERT INTO audit_events (admin_id,action_name,detail) VALUES (?,?,?)');
    $st->execute([$uid,substr($action,0,100),substr($detail,0,180)]);
}
function miner_h(string $value): string {return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function miner_security_headers(): void {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') header('Strict-Transport-Security: max-age=31536000');
}
function miner_session(): void {
    if (PHP_SAPI === 'cli') throw new LogicException('Sesiones solo para HTTP.');
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if (!$https && getenv('MINER_ALLOW_HTTP_DEV') !== '1') throw new RuntimeException('HTTPS obligatorio.');
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    ini_set('session.cookie_httponly','1');
    ini_set('session.gc_maxlifetime','3600');
    session_name('HACHEMINERSESSID');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    if (isset($_SESSION['last_seen']) && time() - (int)$_SESSION['last_seen'] > 1800) {
        $_SESSION=[];session_regenerate_id(true);
    }
    $_SESSION['last_seen']=time();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function miner_csrf(): string {return (string)($_SESSION['csrf']??'');}
function miner_check_csrf(): void {
    if (!hash_equals(miner_csrf(),(string)($_POST['csrf']??''))) {http_response_code(403);exit('Solicitud rechazada.');}
}
function miner_require_admin(): int {
    if (empty($_SESSION['admin_id'])) {header('Location: /?page=login',true,303);exit;}
    return (int)$_SESSION['admin_id'];
}
function miner_login(string $user,string $password,string $ip): bool {
    $user=trim($user);
    if ($user==='' || strlen($user)>64 || strlen($password)>1024) return false;
    $ipHash=hash('sha256',$ip);
    $db=miner_db();
    $s=$db->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempted_at>UTC_TIMESTAMP()-INTERVAL 15 MINUTE AND (username=? OR ip_hash=?) AND success=0');
    $s->execute([$user,$ipHash]);
    if ((int)$s->fetchColumn()>=5) return false;
    $s=$db->prepare('SELECT id,password_hash FROM administrators WHERE username=?');$s->execute([$user]);$admin=$s->fetch();
    // Constant amount of hashing work also for unknown user.
    $fallback='$2y$10$e0NRThT8CMiA3s4Ewpm0ZOz/WKZXmFKBbLbIgylJTd7GCksVK9VQG';
    $valid=password_verify($password,(string)($admin['password_hash']??$fallback));
    $s=$db->prepare('INSERT INTO login_attempts (username,ip_hash,success) VALUES (?,?,?)');$s->execute([$user,$ipHash,$valid&&$admin?1:0]);
    if (!$valid || !$admin) return false;
    session_regenerate_id(true);
    $_SESSION=['admin_id'=>(int)$admin['id'],'last_seen'=>time(),'csrf'=>bin2hex(random_bytes(32))];
    $s=$db->prepare('UPDATE administrators SET last_login_at=UTC_TIMESTAMP() WHERE id=?');$s->execute([$admin['id']]);
    miner_audit((int)$admin['id'],'login');
    return true;
}
function miner_finite_decimal(mixed $value,int $decimals=6): ?string {
    if (!is_numeric($value) || !is_finite((float)$value) || (float)$value<0) return null;
    return number_format((float)$value,$decimals,'.','');
}
function miner_http_json(string $url,array $headers=[]): array {
    $host=parse_url($url,PHP_URL_HOST);
    if (!in_array($host,['api.salad.com','pool.kryptex.com'],true) || !str_starts_with($url,'https://')) throw new InvalidArgumentException('Destino API no permitido.');
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>7,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>$headers,CURLOPT_MAXREDIRS=>0,CURLOPT_USERAGENT=>'HACHE-MINER/0.1']);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if (!is_string($raw) || $status<200 || $status>=300 || strlen($raw)>1500000) throw new RuntimeException('Consulta externa no disponible (HTTP '.$status.').');
    $out=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if (!is_array($out)) throw new RuntimeException('Respuesta API inesperada.');
    return $out;
}
function miner_scrub_log(string $line): string {
    // Never persist full wallet identifiers or credentials in imported log text.
    $line=preg_replace('/prl1[a-z0-9]{20,}/i','[wallet]', $line) ?? '';
    $line=preg_replace('/(?i)(api[_-]?key|authorization|password|token|secret)\s*[:=]\s*[^\s,;]+/','[redacted]',$line) ?? '';
    return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/',' ', $line)??''),0,800);
}
