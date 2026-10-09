<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/core.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    $db = miner_db();
    if ((int)$db->query('SELECT 1')->fetchColumn() !== 1) throw new RuntimeException('Database unavailable');
    if ((int)$db->query('SELECT COUNT(*) FROM administrators')->fetchColumn() < 1) throw new RuntimeException('Administrator unavailable');
    http_response_code(200);
    echo "ok\n";
} catch (Throwable $e) {
    http_response_code(503);
    echo "unavailable\n";
}
