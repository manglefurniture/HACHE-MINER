<?php
declare(strict_types=1);
// This versioned loader contains NO secrets. The referenced file must be
// created outside GitHub and readable only by the dedicated service user.
$runtime = '/etc/hache-miner/runtime.php';
if (!is_file($runtime) || is_link($runtime)) {
    throw new RuntimeException('HACHE-MINER private runtime not installed');
}
require_once $runtime;
