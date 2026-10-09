<?php
/**
 * Live Core checks against the running site. Run from tests/run.sh.
 */
if (!defined('ABSPATH')) {
    fwrite(STDERR, "live-test.php must run through WP-CLI eval-file\n");
    exit(1);
}

define('FW_LIVE_EMBEDDED', true);
require __DIR__ . '/live-lib.php';

FwLive::boot();
fw_core_live_tests();

$pro = defined('FILAWARDEN_PRO_DIR') ? FILAWARDEN_PRO_DIR . 'tests/live-pro.php' : '';
if (is_string($pro) && is_readable($pro) && class_exists('FilaWardenPro')) {
    require $pro;
    fw_pro_live_tests();
} else {
    echo "pro live tests skipped\n";
}

FwLive::finish('live-test');

function fw_core_live_tests(): void {
    FwLive::ok(is_plugin_active('spiggle-filawarden/spiggle-filawarden.php'), 'core plugin active');
    $header = (string) file_get_contents(FILAWARDEN_FILE);
    FwLive::ok(preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $header, $match) === 1 && $match[1] === FILAWARDEN_VERSION, 'core header matches FILAWARDEN_VERSION');

    $home = FwLive::request(home_url('/'), ['cookie' => '']);
    FwLive::ok($home['status'] === 200, 'home HTTP ' . $home['status']);
    FwLive::ok(!str_contains($home['body'], 'Spiggle Suite'), 'home is this WordPress site');
    FwLive::guardSecrets('home', $home['body']);
    $posts = get_posts(['numberposts' => 1, 'post_status' => 'publish', 'post_type' => 'post']);
    FwLive::ok($posts !== [], 'a published post exists');
    $permalink = (string) get_permalink($posts[0]);
    $article = FwLive::request($permalink, ['cookie' => '']);
    FwLive::ok($article['status'] === 200, 'pretty permalink HTTP ' . $article['status']);
    FwLive::ok(str_contains($article['body'], get_the_title($posts[0])), 'permalink renders the post');
    foreach (['.env', '.git/config', 'wp-config.php'] as $relative) {
        $blocked = FwLive::request(home_url('/' . $relative), ['cookie' => '']);
        FwLive::ok(in_array($blocked['status'], [401, 403, 404], true), "{$relative} blocked, got {$blocked['status']}");
    }

    $loggedOut = FwLive::request(admin_url('admin.php?page=filawarden'), ['cookie' => '']);
    FwLive::ok(in_array($loggedOut['status'], [301, 302], true) && str_contains($loggedOut['location'], 'wp-login.php'), 'anonymous admin redirects to login');

    $css = FwLive::request(plugins_url('assets/css/filawarden.css', FILAWARDEN_FILE), ['cookie' => '']);
    FwLive::ok($css['status'] === 200 && str_contains($css['body'], '.fw-grid.cols-5'), 'stylesheet is served and has the five-column grid');
    FwLive::ok(str_contains($css['body'], 'navigation: none') && str_contains($css['body'], 'view-transition-name: none'), 'stylesheet hides the admin chrome and its view transition');
    $js = FwLive::request(plugins_url('assets/js/filawarden.js', FILAWARDEN_FILE), ['cookie' => '']);
    FwLive::ok($js['status'] === 200 && str_contains($js['body'], 'filawarden-theme') && str_contains($js['body'], 'fw-dark'), 'theme script is served');
    $boot = FwLive::request(plugins_url('assets/js/filawarden-boot.js', FILAWARDEN_FILE), ['cookie' => '']);
    FwLive::ok($boot['status'] === 200 && str_contains($boot['body'], 'filawarden-theme') && str_contains($boot['body'], 'fw-dark'), 'theme boot script is served');

    $plainAdmin = FwLive::request(admin_url('index.php'));
    FwLive::ok($plainAdmin['status'] === 200, 'wp-admin HTTP ' . $plainAdmin['status']);
    FwLive::ok(!str_contains($plainAdmin['body'], 'id="fw-screen"') && !str_contains($plainAdmin['body'], 'filawarden-css'), 'wp-admin keeps its own menu');
    FwLive::ok(str_contains($plainAdmin['body'], 'adminmenu'), 'wp-admin menu markup is present');

    $plugin = FilaWardenPlugin::instance();
    FwLive::ok(count($plugin->checks()) === 12, 'auditor has 12 checks');
    $dashboard = FwLive::screen('filawarden', ['Executive Dashboard', 'cols-5', 'grp">Core', 'Overall Operations Health', 'filawarden-logo.svg', 'WordPress menu']);
    FwLive::ok(!str_contains($dashboard, '>FW<'), 'the FW monogram is gone');
    FwLive::ok(substr_count($dashboard, 'fw-pill') === 5, 'dashboard renders five health pills');
    foreach ($plugin->pages() as $label) {
        FwLive::ok(str_contains($dashboard, $label), "dashboard links {$label}");
    }

    $auditor = FwLive::screen('filawarden-auditor', ['Deployment Auditor', 'WP_DEBUG State']);
    foreach ($plugin->checks() as $check) {
        FwLive::ok(str_contains($auditor, esc_html($check['name'])), 'auditor lists ' . $check['name']);
        FwLive::ok(str_contains($auditor, esc_html($check['current'])), 'auditor current value for ' . $check['name']);
    }

    $resources = FilaWardenEngine::resources(ABSPATH);
    $infra = FwLive::screen('filawarden-infrastructure', ['Infrastructure', $resources['php'], 'Runtime']);
    FwLive::ok(!str_contains($infra, 'Memory metrics unavailable'), 'infrastructure page shows host memory');
    FwLive::ok(!str_contains($infra, 'uptime n/a'), 'infrastructure page shows host uptime');
    FwLive::ok(preg_match('/uptime \d+d \d+h \d+m/', $infra) === 1, 'uptime is days, hours, and minutes');
    FwLive::screen('filawarden-queues', ['WP-Cron queue', 'Retry', 'Forget']);

    wp_clear_scheduled_hook('filawarden_heartbeat_event');
    wp_schedule_event(time() + 7200, 'filawarden_minutely', 'filawarden_heartbeat_event');
    FwLive::cleanup(static function (): void {
        wp_clear_scheduled_hook('filawarden_heartbeat_event');
        wp_schedule_event(time() + 60, 'filawarden_minutely', 'filawarden_heartbeat_event');
    });
    $beforeBeat = (int) get_option('filawarden_heartbeat', 0);
    $scheduler = FwLive::screen('filawarden-scheduler', ['Task Scheduler', 'Heartbeat age', 'value="heartbeat"']);
    FwLive::ok((int) get_option('filawarden_heartbeat', 0) === $beforeBeat, 'opening the scheduler does not record a heartbeat');
    FwLive::ok(str_contains($scheduler, 'fw-screen'), 'scheduler uses the shell');
    $beat = FwLive::post('core', 'heartbeat', [], 'filawarden-scheduler');
    FwLive::expectNotice($beat, 'heartbeat', 'record heartbeat');
    $afterBeat = (int) get_option('filawarden_heartbeat', 0);
    FwLive::ok($afterBeat >= $beforeBeat && $afterBeat >= time() - 30, 'heartbeat option advanced');

    global $wpdb;
    $database = FwLive::screen('filawarden-database', ['Database Health', DB_NAME]);
    $tables = $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A) ?: [];
    usort($tables, static fn ($a, $b) => ((int) ($b['Data_length'] ?? 0) + (int) ($b['Index_length'] ?? 0)) <=> ((int) ($a['Data_length'] ?? 0) + (int) ($a['Index_length'] ?? 0)));
    FwLive::ok($tables !== [] && str_contains($database, (string) $tables[0]['Name']), 'database lists the largest table');

    $logPath = FilaWardenConfig::logPath();
    $logPage = FwLive::screen('filawarden-error-log', ['Error Log', $logPath !== '' ? $logPath : 'Log path not set', 'does not change the file']);
    FwLive::ok(!str_contains($logPage, 'truncate_log') && !str_contains($logPage, 'Truncate log'), 'error log has no truncate control');

    $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $local = in_array(wp_get_environment_type(), ['local', 'development'], true);
    $ssl = FilaWardenEngine::ssl($host, $local);
    FwLive::screen('filawarden-ssl', ['SSL / TLS', $ssl['message'], $ssl['status']]);

    delete_transient('filawarden_risk_files');
    FwLive::screen('filawarden-risk-files', ['Risk file scanner', 'Scan again']);
    FwLive::ok(is_array(get_transient('filawarden_risk_files')), 'risk scan stored a real result');
    $rescanned = FwLive::post('core', 'rescan_risk', [], 'filawarden-risk-files');
    FwLive::expectNotice($rescanned, 'risk_rescanned', 'rescan risk files');
    FwLive::ok(get_transient('filawarden_risk_files') === false, 'rescan drops the cached risk list');

    $previousLog = ($logPath !== '' && is_file($logPath)) ? (string) file_get_contents($logPath) : null;
    $logMarker = 'fw-live-log-' . wp_generate_password(8, false);
    $wroteMarker = $previousLog !== null && is_writable($logPath);
    if ($wroteMarker) {
        file_put_contents($logPath, $previousLog . $logMarker . "\n", LOCK_EX);
    }
    FwLive::cleanup(static function () use ($logPath, $previousLog): void {
        if ($previousLog !== null && $logPath !== '' && is_file($logPath)) {
            file_put_contents($logPath, $previousLog);
        }
    });
    $rejected = FwLive::post('core', 'truncate_log', [], 'filawarden-error-log');
    FwLive::expectNotice($rejected, 'fix_unavailable', 'truncate is not an action');
    if ($wroteMarker) {
        FwLive::ok(str_contains((string) file_get_contents($logPath), $logMarker), 'rejected truncate left the log marker in place');
    }

    $hook = 'fw_live_probe';
    $args = ['fw-live-' . wp_generate_password(6, false)];
    $stamp = time() + 86400;
    wp_schedule_event($stamp, 'hourly', $hook, $args);
    $signature = md5(serialize($args));
    FwLive::cleanup(static function () use ($hook, $args): void {
        $crons = _get_cron_array() ?: [];
        foreach ($crons as $when => $hooks) {
            if (isset($hooks[$hook])) {
                wp_unschedule_event((int) $when, $hook, $args);
            }
        }
    });
    $queued = _get_cron_array();
    FwLive::ok(isset($queued[$stamp][$hook][$signature]), 'probe cron was scheduled');
    $invalid = FwLive::post('core', 'forget_cron', [
        'fw_hook' => $hook,
        'fw_ts' => (string) $stamp,
        'fw_sig' => str_repeat('a', 32),
    ], 'filawarden-queues');
    FwLive::expectNotice($invalid, 'cron_invalid', 'wrong cron signature');
    FwLive::ok(isset(_get_cron_array()[$stamp][$hook][$signature]), 'invalid signature did not remove the event');

    $retry = FwLive::post('core', 'retry_cron', [
        'fw_hook' => $hook,
        'fw_ts' => (string) $stamp,
        'fw_sig' => $signature,
    ], 'filawarden-queues');
    FwLive::expectNotice($retry, 'cron_retried', 'retry cron');
    $moved = fw_find_cron($hook, $signature);
    FwLive::ok($moved !== null && $moved <= time() + 30 && $moved >= time(), 'retry moved the event forward');
    $event = _get_cron_array()[$moved][$hook][$signature];
    FwLive::ok(($event['schedule'] ?? '') === 'hourly', 'retry kept the hourly schedule');
    FwLive::ok($event['args'] === $args, 'retry kept the event arguments');

    $forget = FwLive::post('core', 'forget_cron', [
        'fw_hook' => $hook,
        'fw_ts' => (string) $moved,
        'fw_sig' => $signature,
    ], 'filawarden-queues');
    FwLive::expectNotice($forget, 'cron_forgotten', 'forget cron');
    FwLive::ok(fw_find_cron($hook, $signature) === null, 'forgotten event is gone');
    echo "core live checks passed\n";
}

function fw_find_cron(string $hook, string $signature): ?int {
    foreach (_get_cron_array() ?: [] as $when => $hooks) {
        if (isset($hooks[$hook][$signature])) {
            return (int) $when;
        }
    }

    return null;
}
