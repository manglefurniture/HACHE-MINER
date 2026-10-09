<?php
declare(strict_types=1);
function assert_deploy(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$root = dirname(__DIR__);
$nginx = file_get_contents($root.'/deploy/nginx-hache-miner-https.conf.example');
$pool = file_get_contents($root.'/deploy/php-fpm-hache-miner.conf.example');
$timer = file_get_contents($root.'/deploy/systemd/hache-miner-poll.timer');
$service = file_get_contents($root.'/deploy/systemd/hache-miner-poll.service');
$health = file_get_contents($root.'/public/health.php');
$activate = file_get_contents($root.'/deploy/activate.sh');
assert_deploy(str_contains($nginx, 'root /srv/hache-miner/current/public'), 'Webroot must be public');
assert_deploy(str_contains($nginx, 'location ~ \\.php$ { return 404; }'), 'Other PHP scripts must not be served');
assert_deploy(str_contains($nginx, 'ssl_certificate_key'), 'TLS required');
assert_deploy(str_contains($pool, 'pm.max_children = 1'), 'FPM memory cap required');
assert_deploy(str_contains($pool, 'auto_prepend_file'), 'Private runtime loader required');
assert_deploy(str_contains($timer, 'OnCalendar=*:0/5'), 'Five-minute schedule required');
assert_deploy(str_contains($service, 'MemoryMax=128M'), 'Poller memory cap required');
assert_deploy(str_contains($service, 'NoNewPrivileges=yes'), 'Poller privilege lockdown required');
assert_deploy(str_contains($health, 'http_response_code(503)'), 'Readiness failure must be unavailable');
assert_deploy(!is_file($root.'/.env'), 'No environment file in repository');
assert_deploy(str_contains($activate, 'chmod -R a+rX'), 'Release permissions must allow service user to read public code');
assert_deploy(str_contains($activate, "-name 'runtime.php'"), 'Private runtime files must not be published');
echo "PASS deploy-isolation-regression\n";
