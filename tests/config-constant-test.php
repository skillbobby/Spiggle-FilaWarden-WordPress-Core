<?php
/**
 * Constants beat environment variables. This file is a separate process
 * because a defined constant cannot be removed.
 */
define('FILAWARDEN_MAX_SCANNED_FILES', '11');
putenv('FILAWARDEN_MAX_SCANNED_FILES=99');
putenv('FILAWARDEN_ENABLED=0');
require __DIR__ . '/../includes/class-filawarden-config.php';

$config = FilaWardenConfig::all();
if ($config['max_scanned_files'] !== 11) {
    fwrite(STDERR, "FAIL constant did not win over the environment\n");
    exit(1);
}
if ($config['enabled'] !== false) {
    fwrite(STDERR, "FAIL FILAWARDEN_ENABLED=0 was ignored\n");
    exit(1);
}
echo "config-constant-test ok\n";
