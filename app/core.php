<?php
declare(strict_types=1);

const MINER_TIMEZONE = 'America/Cancun';
// Organizations/projects are loaded from salad_targets, not hardcoded here.

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
    // Strip ANSI CSI / SGR escape sequences BEFORE generic control filtering.
    // Older imported records already lost the ESC byte; remove orphaned SGR
    // fragments too, without deleting timestamps like [2026-10-09 ...].
    $line=preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $line) ?? '';
    $line=preg_replace('/\[(?:0|1|2|3[0-9]|4[0-9]|9[0-7])(?:;(?:0|1|2|3[0-9]|4[0-9]|9[0-7]))*m/', '', $line) ?? '';
    // Never persist or redisplay wallet identifiers and credentials in logs.
    $line=preg_replace('/prl1[a-z0-9]{20,}/i','[wallet]', $line) ?? '';
    $line=preg_replace('/(?i)(api[_-]?key|authorization|password|token|secret)\s*[:=]\s*[^\s,;]+/','[redacted]',$line) ?? '';
    return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/',' ', $line)??''),0,800);
}
/** Salad's instance state is an object {status: "..."}; older fixtures may use strings. */
function miner_instance_state(array $instance): string {
    $raw=$instance['state']??null;
    $state=is_array($raw)?($raw['status']??null):$raw;
    if (!is_string($state) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D',$state)) return 'unknown';
    return strtolower($state);
}
/** Container ready/started is not evidence of accepted mining shares. */
function miner_instance_ready(array $instance): bool {
    return ($instance['ready']??null)===true && ($instance['started']??null)===true
        && miner_instance_state($instance)==='running';
}


const MINER_REMEMBER_COOKIE = 'HACHEMINERDEVICE';
const MINER_REMEMBER_DAYS = 30;

/** Cookies are opaque, randomly generated bearer tokens, never a password. */
function miner_remember_cookie_value(string $selector, string $validator): string {
    if (!preg_match('/^[a-f0-9]{32}$/D', $selector) || !preg_match('/^[a-f0-9]{64}$/D', $validator)) {
        throw new InvalidArgumentException('Token format invalid');
    }
    return $selector.':'.$validator;
}
function miner_parse_remember_cookie(mixed $cookie): ?array {
    if (!is_string($cookie) || !preg_match('/^([a-f0-9]{32}):([a-f0-9]{64})$/D', $cookie, $m)) return null;
    return [$m[1], $m[2]];
}
function miner_remember_cookie(string $value, int $expiry): void {
    if (headers_sent()) throw new RuntimeException('Headers already sent');
    setcookie(MINER_REMEMBER_COOKIE, $value, [
        'expires'=>$expiry, 'path'=>'/', 'secure'=>true,
        'httponly'=>true, 'samesite'=>'Strict'
    ]);
}
function miner_clear_remember_cookie(): void {
    miner_remember_cookie('', time()-3600);
    unset($_COOKIE[MINER_REMEMBER_COOKIE]);
}
function miner_device_label(string $agent): string {
    if (stripos($agent, 'Android')!==false) return 'Android · navegador';
    if (stripos($agent, 'iPhone')!==false || stripos($agent, 'iPad')!==false) return 'iOS · navegador';
    if (stripos($agent, 'Windows')!==false) return 'Windows · navegador';
    if (stripos($agent, 'Macintosh')!==false) return 'Mac · navegador';
    return 'Navegador';
}
function miner_issue_remember(int $adminId, string $agent): void {
    $selector=bin2hex(random_bytes(16));
    $validator=bin2hex(random_bytes(32));
    $hash=hash('sha256', $validator);
    $expiry=time()+MINER_REMEMBER_DAYS*86400;
    $db=miner_db();
    $db->prepare('DELETE FROM trusted_devices WHERE expires_at < UTC_TIMESTAMP() OR revoked_at IS NOT NULL')->execute();
    // Limit outstanding trusted devices per administrator.
    $db->prepare('DELETE FROM trusted_devices WHERE admin_id=? AND id NOT IN (SELECT id FROM (SELECT id FROM trusted_devices WHERE admin_id=? ORDER BY created_at DESC, id DESC LIMIT 9) AS allowed)')->execute([$adminId,$adminId]);
    $stmt=$db->prepare('INSERT INTO trusted_devices(admin_id,selector,token_hash,device_label,expires_at) VALUES (?,?,?,?,?)');
    $stmt->execute([$adminId,$selector,$hash,miner_device_label($agent),gmdate('Y-m-d H:i:s',$expiry)]);
    miner_remember_cookie(miner_remember_cookie_value($selector,$validator),$expiry);
    $_COOKIE[MINER_REMEMBER_COOKIE]=miner_remember_cookie_value($selector,$validator);
    miner_audit($adminId,'trusted_device_added');
}
function miner_restore_remember(): void {
    if (!empty($_SESSION['admin_id'])) return;
    $raw=$_COOKIE[MINER_REMEMBER_COOKIE]??null;
    if ($raw===null) return;
    $pair=miner_parse_remember_cookie($raw);
    if ($pair===null) { miner_clear_remember_cookie(); return; }
    [$selector,$validator]=$pair;
    $st=miner_db()->prepare('SELECT t.admin_id,t.token_hash FROM trusted_devices t JOIN administrators a ON a.id=t.admin_id WHERE t.selector=? AND t.revoked_at IS NULL AND t.expires_at>UTC_TIMESTAMP() LIMIT 1');
    $st->execute([$selector]);
    $row=$st->fetch();
    if (!$row || !hash_equals((string)$row['token_hash'],hash('sha256',$validator))) {
        miner_clear_remember_cookie();
        return;
    }
    $newValidator=bin2hex(random_bytes(32));
    $newHash=hash('sha256',$newValidator);
    $expires=time()+MINER_REMEMBER_DAYS*86400;
    // Atomic rotation prevents reuse of old stolen cookies after restoration.
    $u=miner_db()->prepare('UPDATE trusted_devices SET token_hash=?,last_used_at=UTC_TIMESTAMP(),expires_at=? WHERE selector=? AND token_hash=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP()');
    $u->execute([$newHash,gmdate('Y-m-d H:i:s',$expires),$selector,$row['token_hash']]);
    if ($u->rowCount()!==1) { miner_clear_remember_cookie(); return; }
    session_regenerate_id(true);
    $_SESSION=['admin_id'=>(int)$row['admin_id'],'last_seen'=>time(),'csrf'=>bin2hex(random_bytes(32))];
    miner_remember_cookie(miner_remember_cookie_value($selector,$newValidator),$expires);
    $_COOKIE[MINER_REMEMBER_COOKIE]=miner_remember_cookie_value($selector,$newValidator);
    miner_audit((int)$row['admin_id'],'trusted_device_restored');
}
function miner_revoke_current_device(int $adminId): void {
    $pair=miner_parse_remember_cookie($_COOKIE[MINER_REMEMBER_COOKIE]??null);
    if ($pair!==null) {
        $st=miner_db()->prepare('UPDATE trusted_devices SET revoked_at=UTC_TIMESTAMP() WHERE admin_id=? AND selector=? AND revoked_at IS NULL');
        $st->execute([$adminId,$pair[0]]);
    }
    miner_clear_remember_cookie();
}
function miner_revoke_all_devices(int $adminId): void {
    $st=miner_db()->prepare('UPDATE trusted_devices SET revoked_at=UTC_TIMESTAMP() WHERE admin_id=? AND revoked_at IS NULL');
    $st->execute([$adminId]);
    miner_audit($adminId,'trusted_devices_revoked');
    miner_clear_remember_cookie();
}


function miner_valid_salad_slug(string $slug): bool {
    return preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $slug)===1;
}
function miner_salad_targets(bool $enabledOnly=true): array {
    $sql='SELECT id,organization_slug,project_slug,label,enabled FROM salad_targets';
    if ($enabledOnly) $sql.=' WHERE enabled=1';
    $sql.=' ORDER BY organization_slug,project_slug';
    return miner_db()->query($sql)->fetchAll();
}
function miner_known_salad_org(string $org): bool {
    if (!miner_valid_salad_slug($org)) return false;
    $st=miner_db()->prepare('SELECT 1 FROM salad_targets WHERE organization_slug=? LIMIT 1');
    $st->execute([$org]);
    return (bool)$st->fetchColumn();
}
function miner_salad_add_target(string $org,string $project,string $label): void {
    $org=strtolower(trim($org));$project=strtolower(trim($project));$label=trim($label);
    if (!miner_valid_salad_slug($org) || !miner_valid_salad_slug($project) || $label==='' || mb_strlen($label)>100)
        throw new InvalidArgumentException('Organización o proyecto inválido.');
    $st=miner_db()->prepare('INSERT INTO salad_targets(organization_slug,project_slug,label,enabled) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE label=VALUES(label),enabled=1');
    $st->execute([$org,$project,$label]);
}
function miner_salad_set_enabled(int $id,bool $enabled): void {
    if ($id<1) throw new InvalidArgumentException('Invalid target');
    $st=miner_db()->prepare('UPDATE salad_targets SET enabled=? WHERE id=?');
    $st->execute([(int)$enabled,$id]);
    if ($st->rowCount()===0) {
       $check=miner_db()->prepare('SELECT 1 FROM salad_targets WHERE id=?');$check->execute([$id]);
       if (!$check->fetchColumn()) throw new InvalidArgumentException('Target not found');
    }
}
function miner_shared_salad_api_key(): ?string {
    $shared=miner_get_secret('salad:api-key');
    if ($shared!==null) return $shared;
    // Read-only compatibility for installations that previously stored an
    // organization-specific key. New keys are saved only in the shared slot.
    return miner_get_secret('salad:hache:api-key') ?? miner_get_secret('salad:interactive:api-key');
}
