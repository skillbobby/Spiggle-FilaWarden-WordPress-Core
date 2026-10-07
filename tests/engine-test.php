<?php
require __DIR__ . '/../includes/class-filawarden-engine.php';
$checks = [
    FilaWardenEngine::check('app_debug', 'APP_DEBUG', 'Security', 15, 'passed', 'off', 'off', 'ok', 'none'),
    FilaWardenEngine::check('app_env', 'ENV', 'Security', 10, 'warning', 'local', 'production', 'local', 'set production'),
    FilaWardenEngine::check('queue_workers', 'Queue', 'Infrastructure', 8, 'passed', '0', '0', 'ok', 'none'),
    FilaWardenEngine::check('scheduler_running', 'Sched', 'Infrastructure', 8, 'warning', 'stale', 'fresh', 'stale', 'cron'),
    FilaWardenEngine::check('config_cache', 'Cache', 'Performance', 8, 'passed', 'on', 'on', 'ok', 'none'),
];
$audit = FilaWardenEngine::audit($checks);
$resources = FilaWardenEngine::resources(sys_get_temp_dir());
$health = FilaWardenEngine::health($audit, $resources);
assert($audit['total'] === 5, 'check count');
assert($audit['score'] >= 0 && $audit['score'] <= 100, 'score range');
assert(isset($health['vectors']['deployment'], $health['vectors']['infrastructure'], $health['vectors']['reliability'], $health['vectors']['security'], $health['vectors']['performance']), 'vectors');
$p = FilaWardenEngine::percentiles([10, 20, 30, 40, 100]);
assert($p['p50'] > 0 && $p['p99'] >= $p['p50'], 'percentiles');
$tmp = tempnam(sys_get_temp_dir(), 'fw');
file_put_contents($tmp, "token ghp_abcdefghijklmnopqrstuvwxyz123456\n");
$found = FilaWardenEngine::scanSecrets([$tmp]);
assert(count($found) === 1 && $found[0]['type'] === 'github_token', 'secret scan');
unlink($tmp);
echo "engine-test ok score={$audit['score']} overall={$health['overall']}\n";
