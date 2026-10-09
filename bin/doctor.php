<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/core.php';

// Diagnostic-only: never print credentials, wallet addresses or connection strings.
$checks = [];
$add = static function (string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
};
$add('php_8_4', PHP_VERSION_ID >= 80400);
foreach (['pdo_mysql', 'sodium', 'curl', 'mbstring'] as $ext) {
    $add('extension_' . $ext, extension_loaded($ext));
}
$lock = getenv('MINER_POLL_LOCK') ?: '/var/lib/hache-miner/poll.lock';
$add('lock_directory', is_dir(dirname($lock)) && is_writable(dirname($lock)) && !is_link(dirname($lock)));
try {
    $key = miner_key();
    $add('master_key', strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    sodium_memzero($key);
} catch (Throwable $e) {
    $add('master_key', false);
}
try {
    $db = miner_db();
    $add('database_connection', (int)$db->query('SELECT 1')->fetchColumn() === 1);
    foreach (['administrators','secret_store','wallets','group_state','miner_observations','log_events','sync_runs','audit_events'] as $table) {
        // Fixed names above; not interpolating user input.
        $db->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
    }
    $add('database_schema', true);
    $add('initial_administrator', (int)$db->query('SELECT COUNT(*) FROM administrators')->fetchColumn() > 0);
} catch (Throwable $e) {
    $add('database_ready', false);
}
$ok = count(array_filter($checks, static fn(array $c): bool => !$c['ok'])) === 0;
if (in_array('--json', $argv, true)) {
    echo json_encode(['ready' => $ok, 'checks' => $checks], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} else {
    foreach ($checks as $check) echo ($check['ok'] ? 'PASS ' : 'FAIL ') . $check['check'] . PHP_EOL;
    echo $ok ? "READY\n" : "NOT_READY\n";
}
exit($ok ? 0 : 1);
