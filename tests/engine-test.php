<?php
require __DIR__ . '/../includes/class-filawarden-engine.php';

$empty = FilaWardenEngine::audit([]);
assert($empty['score'] === 100 && $empty['total'] === 0, 'empty audit');

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
assert(count($health['vectors']) === 5, 'vectors');
assert(FilaWardenEngine::percentiles([])['count'] === 0, 'empty percentiles');
assert(FilaWardenEngine::tailLog('/no/such/log') === [], 'missing log');
assert(FilaWardenEngine::validWebhook('http://hooks.slack.com/x') === false, 'reject http webhook');
assert(FilaWardenEngine::validWebhook('https://hooks.slack.com/services/T/B/x') === true, 'accept https webhook');
assert(FilaWardenEngine::ssl('not a host')['status'] === 'failed', 'bad host');
$masked = FilaWardenEngine::maskSecret('ghp_abcdefghijklmnopqrstuvwxyz1234567890');
assert(!str_contains($masked, 'abcdefghijklmnopqrstuvwxyz'), 'secret masked');

$dir = sys_get_temp_dir() . '/fw-scan-' . getmypid();
mkdir($dir);
file_put_contents($dir . '/leak.env', "token ghp_abcdefghijklmnopqrstuvwxyz1234567890\n");
file_put_contents($dir . '/empty.env', '');
$found = FilaWardenEngine::scanSecrets([$dir . '/leak.env', $dir . '/empty.env', $dir . '/missing.env']);
assert(count($found) === 1 && $found[0]['type'] === 'github_pat', 'secret scan');
assert(str_contains($found[0]['preview'], '*'), 'preview masked');
unlink($dir . '/leak.env');
unlink($dir . '/empty.env');
rmdir($dir);

$parsed = FilaWardenEngine::parseLogLines(['[error] boom', 'all good']);
assert($parsed[0]['level'] === 'error' && $parsed[1]['level'] === 'info', 'log levels');
$headers = FilaWardenEngine::headerReport(['Content-Security-Policy' => "default-src 'self'"]);
assert($headers[0]['status'] === 'passed' && $headers[1]['status'] === 'failed', 'header report');
echo "engine-test ok score={$audit['score']} overall={$health['overall']}\n";
