<?php
/**
 * CLI checks for the collector and config. These do not boot WordPress.
 * Assertions are explicit because zend.assertions=-1 compiles assert() out.
 */
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) {
        return $component === -1 ? parse_url($url) : parse_url($url, $component);
    }
}
require __DIR__ . '/../includes/class-filawarden-engine.php';

function fw_check(bool $ok, string $message): void {
    if ($ok) {
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$empty = FilaWardenEngine::audit([]);
fw_check($empty['score'] === 100 && $empty['total'] === 0, 'empty audit');

$checks = [
    FilaWardenEngine::check('app_debug', 'APP_DEBUG', 'Security', 15, 'passed', 'off', 'off', 'ok', 'none'),
    FilaWardenEngine::check('app_env', 'ENV', 'Security', 10, 'warning', 'local', 'production', 'local', 'set production'),
    FilaWardenEngine::check('queue_workers', 'Queue', 'Infrastructure', 8, 'passed', '0', '0', 'ok', 'none'),
    FilaWardenEngine::check('scheduler_running', 'Sched', 'Infrastructure', 8, 'warning', 'stale', 'fresh', 'stale', 'cron'),
    FilaWardenEngine::check('config_cache', 'Cache', 'Performance', 8, 'passed', 'on', 'on', 'ok', 'none'),
];
$audit = FilaWardenEngine::audit($checks);
fw_check($audit['total'] === 5, 'check count');
fw_check($audit['score'] === 82, 'weighted audit score');
fw_check($audit['passed'] === 3 && $audit['warnings'] === 2 && $audit['failed'] === 0, 'audit tallies');

$resources = FilaWardenEngine::resources(sys_get_temp_dir());
fw_check(array_key_exists('percentage', $resources['cpu']), 'cpu telemetry key');
fw_check(array_key_exists('percentage', $resources['memory']), 'memory telemetry key');
fw_check(is_int($resources['memory']['percentage']), 'this host reports memory');
fw_check($resources['uptime'] !== 'n/a', 'this host reports uptime');
fw_check($resources['cpu']['cores'] >= 1, 'cpu core count');
fw_check($resources['php'] === PHP_VERSION, 'php version is the running interpreter');
fw_check(FilaWardenEngine::cpuListCount("0-3\n") === 4, 'cpu online range');
fw_check(FilaWardenEngine::cpuListCount('0-1,4-7') === 6, 'cpu online list');
fw_check(FilaWardenEngine::cpuListCount('3') === 1, 'single cpu');
$exact = FilaWardenEngine::memoryFromText("MemTotal: 1000 kB\nMemAvailable: 400 kB\n");
fw_check($exact !== null && $exact['total'] === 1000 * 1024 && $exact['used'] === 600 * 1024 && $exact['percentage'] === 60, 'meminfo uses MemAvailable');
$node = FilaWardenEngine::memoryFromText("Node 0 MemTotal: 1000 kB\nNode 0 MemFree: 100 kB\nNode 0 Active(file): 50 kB\nNode 0 Inactive(file): 150 kB\nNode 0 SReclaimable: 50 kB\n");
fw_check($node !== null && $node['used'] === 650 * 1024 && $node['percentage'] === 65, 'sysfs memory estimates available bytes');
fw_check(FilaWardenEngine::startTicks('5963 (cat) R 5962 144 144 0 -1 4194304 441 0 0 0 0 0 0 0 20 0 1 0 1187479 16633856') === 1187479, 'start ticks follow the process name');
fw_check(FilaWardenEngine::startTicks('12 (my proc) R 1 1 1 1 -1 0 0 0 0 0 0 0 0 0 20 0 1 0 50 0') === 50, 'start ticks allow a space in the process name');
fw_check(FilaWardenEngine::uptimeLabel(90061) === '1d 1h 1m', 'uptime label');
fw_check(FilaWardenEngine::uptimeLabel(null) === 'n/a', 'missing uptime');

$health = FilaWardenEngine::health($audit, $resources);
fw_check(count($health['vectors']) === 5, 'vectors');

$unknown = FilaWardenEngine::health($audit, [
    'cpu' => ['percentage' => null],
    'memory' => ['percentage' => null],
    'disk' => ['percentage' => null],
]);
fw_check($unknown['vectors']['infrastructure']['score'] === 100, 'missing metrics add no penalty');
fw_check($unknown['vectors']['reliability']['score'] === 80, 'scheduler warning costs 20 reliability points');
fw_check($unknown['overall'] === 82, 'overall uses the 25/25/20/20/10 weights');

$penalized = FilaWardenEngine::health(FilaWardenEngine::audit([]), [
    'cpu' => ['percentage' => 80],
    'memory' => ['percentage' => 90],
    'disk' => ['percentage' => 85],
]);
fw_check($penalized['vectors']['infrastructure']['score'] === 10, 'cpu 50x1.5, memory 60x1.5, disk 70x2.0');
fw_check($penalized['status'] === 'warning', 'penalized host is degraded');

$quiet = FilaWardenEngine::health(FilaWardenEngine::audit([]), [
    'cpu' => ['percentage' => 50],
    'memory' => ['percentage' => 60],
    'disk' => ['percentage' => 70],
]);
fw_check($quiet['vectors']['infrastructure']['score'] === 100, 'thresholds are not penalties');
fw_check($quiet['status'] === 'healthy' && $quiet['overall'] === 100, 'clean host is healthy');

$emptyPercentiles = FilaWardenEngine::percentiles([]);
fw_check($emptyPercentiles['count'] === 0 && !array_key_exists('rpm', $emptyPercentiles), 'percentiles do not pretend to be rpm');
$series = range(1, 100);
$percentiles = FilaWardenEngine::percentiles($series);
fw_check($percentiles === ['p50' => 50, 'p95' => 95, 'p99' => 99, 'count' => 100], 'percentile ranks');

fw_check(FilaWardenEngine::tailLog('/no/such/log') === [], 'missing log');
$logDir = sys_get_temp_dir() . '/fw-log-' . getmypid();
mkdir($logDir);
$logFile = $logDir . '/debug.log';
file_put_contents($logFile, "all good\n[error] boom\nPHP Fatal error: exploded\n");
$tailed = FilaWardenEngine::tailLog($logFile);
$parsed = FilaWardenEngine::parseLogLines($tailed);
fw_check($parsed[0]['level'] === 'info' && $parsed[1]['level'] === 'error' && $parsed[2]['level'] === 'error', 'log levels');
unlink($logFile);
rmdir($logDir);

fw_check(FilaWardenEngine::validWebhook('http://hooks.slack.com/x') === false, 'reject http webhook');
fw_check(FilaWardenEngine::validWebhook('https://hooks.slack.com/services/T/B/x') === true, 'accept https webhook');
fw_check(FilaWardenEngine::validWebhook('') === false, 'reject empty webhook');
fw_check(FilaWardenEngine::validWebhook('javascript:alert(1)') === false, 'reject javascript webhook');
fw_check(FilaWardenEngine::ssl('not a host')['status'] === 'failed', 'bad host');
fw_check(FilaWardenEngine::ssl('wordpress.test', true)['status'] === 'passed', 'local ssl skip');
fw_check(FilaWardenEngine::ssl('localhost')['message'] === 'Local development host.', 'localhost ssl skip');

$token = 'ghp_' . str_repeat('ab', 18);
$masked = FilaWardenEngine::maskSecret($token);
fw_check(!str_contains($masked, str_repeat('ab', 8)), 'secret masked');
fw_check(FilaWardenEngine::maskSecret('short') === '*****', 'short secret masked');

$dir = sys_get_temp_dir() . '/fw-scan-' . getmypid();
mkdir($dir);
file_put_contents($dir . '/leak.env', "token {$token}\n");
file_put_contents($dir . '/empty.env', '');
file_put_contents($dir . '/key.pem', '-----BEGIN ' . "PRIVATE KEY-----\n");
$found = FilaWardenEngine::scanSecrets([$dir . '/leak.env', $dir . '/empty.env', $dir . '/missing.env', $dir . '/key.pem']);
$types = array_column($found, 'type');
fw_check(in_array('github_pat', $types, true) && in_array('private_key', $types, true), 'secret scan');
foreach ($found as $finding) {
    fw_check(str_contains($finding['preview'], '*'), 'preview masked');
    fw_check(!str_contains($finding['preview'], str_repeat('ab', 8)), 'preview hides the token body');
}
unlink($dir . '/leak.env');
unlink($dir . '/empty.env');
unlink($dir . '/key.pem');
rmdir($dir);

$headers = FilaWardenEngine::headerReport(['Content-Security-Policy' => "default-src 'self'", 'X-Frame-Options' => '']);
fw_check($headers[0]['status'] === 'passed' && $headers[1]['status'] === 'failed' && $headers[2]['status'] === 'failed', 'header report');
$cased = FilaWardenEngine::headerReport(['content-security-policy' => "default-src 'self'", 'Strict-Transport-Security' => 'max-age=60', 'x-frame-options' => 'DENY']);
fw_check($cased[0]['status'] === 'passed' && $cased[1]['status'] === 'passed' && $cased[2]['status'] === 'passed', 'header names are case insensitive');

$riskRoot = sys_get_temp_dir() . '/fw-risk-' . getmypid();
mkdir($riskRoot);
mkdir($riskRoot . '/wp-admin/nested', 0777, true);
file_put_contents($riskRoot . '/wp-admin/nested/hidden.sql', '-- skip');
file_put_contents($riskRoot . '/dump.sql', '-- keep');
file_put_contents($riskRoot . '/.env', "APP=1\n");
file_put_contents($riskRoot . '/pack.zip', 'PK');
$risks = FilaWardenEngine::riskFiles($riskRoot, 40, ['wp-admin'], 8000);
$riskNames = array_column($risks, 'name');
fw_check(in_array('dump.sql', $riskNames, true), 'risk scan keeps sql dumps');
fw_check(in_array('.env', $riskNames, true), 'risk scan keeps env files');
fw_check(in_array('pack.zip', $riskNames, true), 'risk scan keeps archives');
fw_check(!in_array('hidden.sql', $riskNames, true), 'risk scan does not enter skipped directories');
foreach (['dump.sql', '.env', 'pack.zip'] as $name) {
    unlink($riskRoot . '/' . $name);
}
unlink($riskRoot . '/wp-admin/nested/hidden.sql');
rmdir($riskRoot . '/wp-admin/nested');
rmdir($riskRoot . '/wp-admin');
rmdir($riskRoot);

$scanRoot = sys_get_temp_dir() . '/fw-files-' . getmypid();
mkdir($scanRoot);
mkdir($scanRoot . '/node_modules', 0777, true);
file_put_contents($scanRoot . '/keep.php', "<?php\n");
file_put_contents($scanRoot . '/node_modules/secret.php', "<?php\n");
file_put_contents($scanRoot . '/composer.lock', '{}');
$candidates = FilaWardenEngine::candidateFiles($scanRoot, 20, ['node_modules']);
fw_check($candidates === [$scanRoot . '/keep.php'], 'candidate scan skips vendor trees and lockfiles');
unlink($scanRoot . '/keep.php');
unlink($scanRoot . '/node_modules/secret.php');
unlink($scanRoot . '/composer.lock');
rmdir($scanRoot . '/node_modules');
rmdir($scanRoot);

fw_check(FilaWardenEngine::bytes(512) === '512 B', 'bytes');
fw_check(FilaWardenEngine::bytes(1536) === '1.5 KB', 'kilobytes');

require __DIR__ . '/../includes/class-filawarden-config.php';
$config = FilaWardenConfig::all();
fw_check($config['max_scanned_files'] === 500, 'scan cap default is 500');
fw_check($config['apm']['slow_query_threshold_ms'] === 500, 'slow query threshold default is 500');
fw_check($config['apm']['slow_request_threshold_ms'] === 1000, 'slow request threshold');
fw_check(str_starts_with($config['pro_upgrade_url'], 'https://'), 'upgrade url is https');
fw_check(str_starts_with($config['managed_cloud_url'], 'https://'), 'managed cloud url is https');
fw_check($config['thresholds']['cpu']['warning'] === 70 && $config['thresholds']['cpu']['critical'] === 90, 'cpu thresholds');
fw_check($config['thresholds']['memory'] === ['warning' => 75, 'critical' => 90], 'memory thresholds');
fw_check($config['thresholds']['disk'] === ['warning' => 80, 'critical' => 95], 'disk thresholds');
fw_check($config['cache']['telemetry_ttl'] === 10 && $config['cache']['audit_ttl'] === 60, 'cache ttls');
fw_check($config['enabled'] === true && $config['capability'] === 'manage_options', 'defaults enabled');
fw_check($config['license_pattern'] === '/^FW-PRO-[A-Z0-9-]{8,}$/', 'license pattern');
$sampleKey = 'FW-PRO-' . 'TESTKEY1';
fw_check(preg_match($config['license_pattern'], $sampleKey) === 1, 'sample key matches the pattern');
fw_check(preg_match($config['license_pattern'], 'FW-PRO-SHORT') === 0, 'short key is rejected');
fw_check(preg_match($config['license_pattern'], 'nope') === 0, 'junk key is rejected');

putenv('FILAWARDEN_MAX_SCANNED_FILES=42');
putenv('FILAWARDEN_SLOW_QUERY_THRESHOLD=250');
putenv('FILAWARDEN_SLOW_REQUEST_THRESHOLD=1500');
putenv('FILAWARDEN_ENABLED=0');
putenv('FILAWARDEN_CAPTURE_QUERIES=false');
putenv('FILAWARDEN_PRO_UPGRADE_URL=http://example.com/nope');
$overridden = FilaWardenConfig::all();
fw_check($overridden['max_scanned_files'] === 42, 'max scanned files from the environment is an integer');
fw_check($overridden['apm']['slow_query_threshold_ms'] === 250, 'slow query threshold from the environment');
fw_check($overridden['apm']['slow_request_threshold_ms'] === 1500, 'slow request threshold from the environment');
fw_check($overridden['enabled'] === false, 'FILAWARDEN_ENABLED=0 disables the plugin');
fw_check($overridden['apm']['capture_queries'] === false, 'query capture can be disabled');
fw_check($overridden['pro_upgrade_url'] === 'http://example.com/nope', 'upgrade url is not hardcoded');
fw_check(FilaWardenEngine::validWebhook($overridden['pro_upgrade_url']) === false, 'http upgrade url is not linked');
foreach ([
    'FILAWARDEN_MAX_SCANNED_FILES',
    'FILAWARDEN_SLOW_QUERY_THRESHOLD',
    'FILAWARDEN_SLOW_REQUEST_THRESHOLD',
    'FILAWARDEN_ENABLED',
    'FILAWARDEN_CAPTURE_QUERIES',
    'FILAWARDEN_PRO_UPGRADE_URL',
] as $name) {
    putenv($name);
}
$restored = FilaWardenConfig::all();
fw_check($restored['max_scanned_files'] === 500 && $restored['enabled'] === true, 'clearing the environment restores defaults');

$pluginFiles = FilaWardenEngine::candidateFiles(dirname(__DIR__), 5000, ['.git']);
$advancedRoot = dirname(__DIR__, 2) . '/spiggle-filawarden-advanced';
if (is_dir($advancedRoot)) {
    $pluginFiles = array_merge($pluginFiles, FilaWardenEngine::candidateFiles($advancedRoot, 5000, ['.git']));
}
$leaks = FilaWardenEngine::scanSecrets($pluginFiles);
if ($leaks !== []) {
    foreach ($leaks as $leak) {
        fwrite(STDERR, "FAIL secret {$leak['type']} in {$leak['file']}:{$leak['line']}\n");
    }
    exit(1);
}

$coreUninstall = (string) file_get_contents(dirname(__DIR__) . '/uninstall.php');
fw_check(!str_contains($coreUninstall, 'filawarden_license_hash'), 'core uninstall leaves the license');
$proUninstall = $advancedRoot . '/uninstall.php';
if (is_file($proUninstall)) {
    $proUninstallText = (string) file_get_contents($proUninstall);
    fw_check(str_contains($proUninstallText, 'filawarden_license_hash'), 'pro uninstall removes the license hash');
    fw_check(str_contains($proUninstallText, 'DROP TABLE'), 'pro uninstall drops its tables');
    fw_check(!str_contains($proUninstallText, 'filawarden_heartbeat'), 'pro uninstall leaves the heartbeat');
}
$proCatalog = $advancedRoot . '/data/recommendations.json';
if (is_file($proCatalog)) {
    $recommendations = json_decode((string) file_get_contents($proCatalog), true);
    fw_check(is_array($recommendations) && $recommendations !== [], 'recommendations catalog');
    foreach ($recommendations as $recommendation) {
        fw_check(is_array($recommendation) && !empty($recommendation['id']) && !empty($recommendation['title']), 'recommendation shape');
    }
}
fw_check(!is_file(dirname(__DIR__) . '/data/recommendations.json'), 'core does not ship a second catalog');

echo "engine-test ok score={$audit['score']} overall={$unknown['overall']}\n";
