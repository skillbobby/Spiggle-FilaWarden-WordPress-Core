<?php
if (!defined('ABSPATH')) {
    exit;
}

class FilaWardenPlugin {
    private static $instance;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public static function schedules(array $schedules): array {
        $schedules['filawarden_minutely'] = ['interval' => 60, 'display' => 'Every Minute'];

        return $schedules;
    }

    public static function activate(): void {
        add_filter('cron_schedules', [self::class, 'schedules']);
        if (!get_option('filawarden_heartbeat')) {
            update_option('filawarden_heartbeat', time(), false);
        }
        if (!wp_next_scheduled('filawarden_heartbeat_event')) {
            wp_schedule_event(time() + 60, 'filawarden_minutely', 'filawarden_heartbeat_event');
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook('filawarden_heartbeat_event');
    }

    public function boot(): void {
        add_filter('cron_schedules', [self::class, 'schedules']);
        add_action('filawarden_heartbeat_event', static function () {
            update_option('filawarden_heartbeat', time(), false);
        });
        if (!FilaWardenConfig::get('enabled', true)) {
            return;
        }
        add_action('admin_menu', [$this, 'menu']);
        add_filter('admin_body_class', [$this, 'bodyClass']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_filawarden_action', [$this, 'handleAction']);
    }

    public function isScreen(): bool {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        return $page === 'filawarden' || str_starts_with($page, 'filawarden-');
    }

    public function bodyClass(string $classes): string {
        if ($this->isScreen()) {
            $classes .= ' fw-screen';
        }

        return $classes;
    }

    public function cap(): string {
        $cap = (string) FilaWardenConfig::get('capability', 'manage_options');

        return $cap !== '' ? $cap : 'manage_options';
    }

    public function menu(): void {
        $cap = $this->cap();
        add_menu_page('FilaWarden', 'FilaWarden', $cap, 'filawarden', [$this, 'render'], 'dashicons-shield', (int) FilaWardenConfig::get('menu_position', 3));
        add_submenu_page('filawarden', 'Executive Dashboard', 'Executive Dashboard', $cap, 'filawarden', [$this, 'render']);
        foreach ($this->pages() as $slug => $label) {
            if ($slug === 'dashboard') {
                continue;
            }
            add_submenu_page('filawarden', $label, $label, $cap, 'filawarden-' . $slug, [$this, 'render']);
        }
    }

    public function pages(): array {
        return [
            'dashboard' => 'Executive Dashboard',
            'auditor' => 'Deployment Auditor',
            'infrastructure' => 'Infrastructure',
            'queues' => 'Queue Monitor',
            'scheduler' => 'Task Scheduler',
            'database' => 'Database Health',
            'error-log' => 'Error Log',
            'ssl' => 'SSL / TLS',
            'risk-files' => 'Risk Files',
        ];
    }

    public function assets(string $hook): void {
        if (!str_contains($hook, 'filawarden')) {
            return;
        }
        wp_enqueue_style('filawarden', plugins_url('assets/css/filawarden.css', FILAWARDEN_FILE), [], FILAWARDEN_VERSION);
        // Head script so the dark class is set before the shell paints.
        wp_enqueue_script('filawarden-boot', plugins_url('assets/js/filawarden-boot.js', FILAWARDEN_FILE), [], FILAWARDEN_VERSION, false);
        wp_enqueue_script('filawarden', plugins_url('assets/js/filawarden.js', FILAWARDEN_FILE), [], FILAWARDEN_VERSION, true);
    }

    public function currentPage(): string {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'filawarden';
        $page = str_replace('filawarden-', '', $page);
        $page = $page === 'filawarden' ? 'dashboard' : $page;

        return array_key_exists($page, $this->pages()) ? $page : 'dashboard';
    }

    public function render(): void {
        if (!current_user_can($this->cap())) {
            return;
        }
        $page = $this->currentPage();
        // shell() and body() escape every value before this markup is printed.
        echo $this->shell($page, $this->body($page)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function titles(): array {
        $titles = $this->pages();
        if (class_exists('FilaWardenPro')) {
            $titles += FilaWardenPro::pages();
            $titles['license'] = 'License';
        }

        return $titles;
    }

    public function shell(string $page, string $body): string {
        $nav = '';
        foreach ($this->pages() as $slug => $label) {
            $url = esc_url(admin_url('admin.php?page=' . ($slug === 'dashboard' ? 'filawarden' : 'filawarden-' . $slug)));
            $active = $slug === $page ? ' active' : '';
            $nav .= '<a class="' . trim($active) . '" href="' . $url . '">' . esc_html($label) . '</a>';
        }
        if (class_exists('FilaWardenPro')) {
            $nav .= '<div class="grp">Pro</div>';
            foreach (FilaWardenPro::pages() as $slug => $label) {
                $url = esc_url(admin_url('admin.php?page=filawarden-pro-' . $slug));
                $active = $slug === $page ? ' active' : '';
                $nav .= '<a class="pro' . $active . '" href="' . $url . '">' . esc_html($label) . '</a>';
            }
            $active = $page === 'license' ? ' active' : '';
            $nav .= '<a class="pro' . $active . '" href="' . esc_url(admin_url('admin.php?page=filawarden-pro-license')) . '">License</a>';
            $pro = '';
        } else {
            $upgrade = (string) FilaWardenConfig::get('pro_upgrade_url', '');
            $pro = $upgrade !== '' && FilaWardenEngine::validWebhook($upgrade)
                ? '<div class="grp">Pro</div><a class="pro" href="' . esc_url($upgrade) . '" target="_blank" rel="noopener">Upgrade to Pro</a>'
                : '';
        }
        $title = esc_html($this->titles()[$page] ?? 'FilaWarden');
        $health = $this->health();
        $logo = esc_url(plugins_url('assets/img/filawarden-logo.svg', FILAWARDEN_FILE));
        $wordpress = esc_url(admin_url('index.php'));

        return '<div class="fw-app" id="fw-app"><aside class="fw-sidebar"><div class="fw-brand"><img class="fw-logo" src="' . $logo . '" alt="FilaWarden" width="40" height="40"><div><strong>FilaWarden</strong><span>Operations</span></div></div><nav class="fw-nav"><div class="grp">Core</div>' . $nav . $pro . '</nav><a class="fw-leave" href="' . $wordpress . '">WordPress menu</a></aside><main class="fw-main"><header class="fw-top"><div><h1>' . $title . '</h1><p>WordPress operations sentinel · ' . esc_html($health['status_label']) . ' ' . (int) $health['overall'] . '</p></div><div class="fw-actions"><button type="button" class="fw-btn" id="fw-theme" aria-pressed="false">Dark mode</button><a class="fw-btn" href="' . $wordpress . '">WordPress menu</a></div></header><div class="fw-content">' . $this->noticeHtml() . $body . '</div></main></div>';
    }

    public function noticeHtml(): string {
        $code = isset($_GET['fw_notice']) ? sanitize_key(wp_unslash($_GET['fw_notice'])) : '';
        $messages = [
            'heartbeat' => ['Heartbeat recorded.', false],
            'cron_retried' => ['Cron event rescheduled.', false],
            'cron_forgotten' => ['Cron event removed.', false],
            'cron_invalid' => ['That cron event was not found.', true],
            'license_saved' => ['License saved.', false],
            'license_invalid' => ['That license key was not accepted.', true],
            'alerts_saved' => ['Alert channels saved.', false],
            'alert_sent' => ['Test alert dispatched.', false],
            'alert_none' => ['No alert channel is configured.', true],
            'scan_done' => ['Security scan finished.', false],
            'risk_rescanned' => ['Risk file scan refreshed.', false],
            'incident_opened' => ['Incident opened.', false],
            'incident_updated' => ['Incident status updated.', false],
            'incident_invalid' => ['An incident needs a title and a known severity.', true],
            'fix_applied' => ['The change was applied.', false],
            'fix_unavailable' => ['This item is guidance only. Nothing was changed.', true],
        ];
        if (!isset($messages[$code])) {
            return '';
        }
        [$text, $bad] = $messages[$code];

        return '<div class="fw-notice' . ($bad ? ' bad' : '') . '" role="status">' . esc_html($text) . '</div>';
    }

    public function checks(): array {
        $debug = defined('WP_DEBUG') && WP_DEBUG;
        $display = defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY;
        $edit = defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT;
        $placeholder = (string) FilaWardenConfig::get('insecure_salt', 'put your unique phrase here');
        $keys = defined('AUTH_KEY') && strlen(AUTH_KEY) > 8 && AUTH_KEY !== $placeholder;
        $https = is_ssl() || str_starts_with(home_url(), 'https://');
        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        $localEnv = in_array($env, ['local', 'development'], true);
        $opcache = function_exists('opcache_get_status') && ($st = @opcache_get_status(false)) && !empty($st['opcache_enabled']);
        $object = wp_using_ext_object_cache();
        $beat = (int) get_option('filawarden_heartbeat', 0);
        $staleAfter = (int) FilaWardenConfig::get('heartbeat_stale_seconds', 600);
        $schedOk = $beat && (time() - $beat) < $staleAfter;
        $failed = $this->failedCronCount();
        $failAt = (int) FilaWardenConfig::get('cron_overdue_warn_count', 10);
        $config = $this->configPath();
        $perms = is_readable($config) ? (fileperms($config) & 0777) : 0644;
        $permMax = (int) FilaWardenConfig::get('config_perms_max', 0644);

        return [
            FilaWardenEngine::check('app_debug', 'WP_DEBUG State', 'Security', 15, $debug ? 'failed' : 'passed', $debug ? 'Enabled' : 'Disabled', 'Disabled', $debug ? 'Debug mode can expose stack traces.' : 'Debug mode is disabled.', 'Set WP_DEBUG to false in wp-config.php.'),
            FilaWardenEngine::check('app_env', 'Environment Type', 'Security', 10, $env === 'production' ? 'passed' : 'warning', $env, 'production', 'Environment is ' . $env . '.', 'Use WP_ENVIRONMENT_TYPE=production on live sites.'),
            FilaWardenEngine::check('app_key', 'Auth Keys & Salts', 'Security', 15, $keys ? 'passed' : 'failed', $keys ? 'Configured' : 'Missing', 'Unique salts', $keys ? 'Salts are set.' : 'Default or missing salts.', 'Generate salts and set AUTH_KEY and the other salts in wp-config.php.'),
            FilaWardenEngine::check('debug_display', 'Display Errors', 'Security', 8, $display ? 'failed' : 'passed', $display ? 'Displayed' : 'Hidden', 'Hidden', $display ? 'Errors may render to visitors.' : 'Display of errors is off.', 'Set WP_DEBUG_DISPLAY to false.'),
            FilaWardenEngine::check('file_edit', 'Theme/Plugin Editor', 'Security', 6, $edit ? 'passed' : 'warning', $edit ? 'Disallowed' : 'Allowed', 'Disallowed', $edit ? 'File editor is disabled.' : 'Dashboard file editor is enabled.', 'Define DISALLOW_FILE_EDIT as true.'),
            FilaWardenEngine::check('config_cache', 'Object Cache', 'Performance', 8, $object ? 'passed' : 'warning', $object ? 'External' : 'Default', 'External object cache', $object ? 'External object cache is active.' : 'Using the default object cache.', 'Install a Redis or Memcached object-cache.php drop-in.'),
            FilaWardenEngine::check('route_cache', 'OPcache', 'Performance', 8, $opcache ? 'passed' : 'warning', $opcache ? 'Enabled' : 'Off', 'Enabled', $opcache ? 'OPcache is enabled.' : 'OPcache is not active.', 'Enable Zend OPcache in php.ini.'),
            FilaWardenEngine::check('view_cache', 'Permalink Cache', 'Performance', 5, get_option('permalink_structure') ? 'passed' : 'warning', get_option('permalink_structure') ?: 'Plain', 'Pretty permalinks', 'Permalink structure checked.', 'Set pretty permalinks under Settings → Permalinks.'),
            FilaWardenEngine::check('queue_workers', 'Cron Queue Health', 'Infrastructure', 8, $failed > $failAt ? 'warning' : 'passed', $failed . ' overdue hooks', 'Zero overdue', $failed . ' cron hooks are overdue.', 'Inspect WP-Cron and overdue scheduled events.'),
            FilaWardenEngine::check('scheduler_running', 'Scheduler Heartbeat', 'Infrastructure', 8, $schedOk ? 'passed' : 'warning', $schedOk ? 'Active' : 'Stale', 'Heartbeat < ' . $staleAfter . 's', $schedOk ? 'Heartbeat is fresh.' : 'No recent scheduler heartbeat.', 'Allow WP-Cron, or call wp-cron.php from system cron.'),
            FilaWardenEngine::check('storage_link', 'Config Permissions', 'Infrastructure', 5, $perms <= $permMax ? 'passed' : 'failed', decoct($perms), decoct($permMax), 'wp-config.php mode is ' . decoct($perms) . '.', 'Restrict wp-config.php to the recommended mode.'),
            FilaWardenEngine::check('https_enforcement', 'HTTPS Scheme', 'Security', 4, ($https || $localEnv) ? 'passed' : 'warning', $https ? 'HTTPS' : 'HTTP', 'HTTPS', $https ? 'Site URL uses HTTPS.' : ($localEnv ? 'HTTP is expected while the environment is ' . $env . '.' : 'Site URL is not HTTPS.'), 'Set the site URL to https and force SSL.'),
        ];
    }

    public function audit(): array {
        return FilaWardenEngine::audit($this->checks());
    }

    public function resources(): array {
        return FilaWardenEngine::resources(ABSPATH);
    }

    public function health(): array {
        return FilaWardenEngine::health($this->audit(), $this->resources());
    }

    private function configPath(): string {
        if (is_readable(ABSPATH . 'wp-config.php')) {
            return ABSPATH . 'wp-config.php';
        }
        $parent = dirname(ABSPATH) . '/wp-config.php';

        return is_readable($parent) ? $parent : ABSPATH . 'wp-config.php';
    }

    private function failedCronCount(): int {
        $crons = _get_cron_array();
        if (!is_array($crons)) {
            return 0;
        }
        $n = 0;
        $cutoff = time() - (int) FilaWardenConfig::get('cron_overdue_seconds', 900);
        foreach ($crons as $ts => $hooks) {
            if ((int) $ts < $cutoff) {
                $n += is_array($hooks) ? count($hooks) : 0;
            }
        }

        return $n;
    }

    public function body(string $page): string {
        return match ($page) {
            'auditor' => $this->pageAuditor(),
            'infrastructure' => $this->pageInfra(),
            'queues' => $this->pageQueues(),
            'scheduler' => $this->pageScheduler(),
            'database' => $this->pageDatabase(),
            'error-log' => $this->pageLog(),
            'ssl' => $this->pageSsl(),
            'risk-files' => $this->pageRisk(),
            default => $this->pageDashboard(),
        };
    }

    private function badge(string $status): string {
        return '<span class="fw-badge ' . esc_attr($status) . '">' . esc_html($status) . '</span>';
    }

    private function pageDashboard(): string {
        $h = $this->health();
        $pills = '';
        foreach ($h['vectors'] as $v) {
            $pills .= '<div class="fw-pill"><div class="fw-k">' . esc_html($v['name']) . '</div><div class="fw-score sm">' . (int) $v['score'] . '%</div>' . $this->badge($v['status']) . '</div>';
        }
        $hero = '<div class="fw-card fw-hero"><div class="fw-hero-main"><div class="fw-scoretile ' . esc_attr($h['status']) . '">' . (int) $h['overall'] . '</div><div class="fw-hero-copy"><h3>Overall Operations Health</h3><p>Evaluated across 5 core reliability vectors. Last assessment: ' . esc_html($h['evaluated_at']) . '.</p><div class="fw-statusline">' . $this->badge($h['status']) . '<span>' . esc_html($h['status_label']) . '</span></div></div></div><div class="fw-grid cols-5">' . $pills . '</div></div>';
        $links = [
            'auditor' => ['Deployment Auditor', '12 production readiness checks, debug state, and cache validation.'],
            'infrastructure' => ['Infrastructure Telemetry', 'CPU load, memory, disk headroom, and uptime.'],
            'queues' => ['Queue Monitor', 'Background jobs, pending cron, and failed event inspection.'],
            'scheduler' => ['Task Scheduler', 'Cron heartbeat and scheduled event registry.'],
            'database' => ['Database Health', 'Storage footprint, connections, and top tables.'],
            'error-log' => ['Error Log Reader', 'Parsed error records and log footprint.'],
            'ssl' => ['SSL / TLS Certificate', 'Validity countdown and issuer.'],
            'risk-files' => ['Risk File Scanner', 'Public dumps, shell scripts, and stray archives.'],
        ];
        $grid = '';
        foreach ($links as $slug => $meta) {
            $url = esc_url(admin_url('admin.php?page=filawarden-' . $slug));
            $grid .= '<a class="fw-card fw-launch" href="' . $url . '"><h3>' . esc_html($meta[0]) . '</h3><p>' . esc_html($meta[1]) . '</p></a>';
        }
        $feed = '<div class="fw-feed">[' . esc_html(gmdate('H:i:s')) . '] INFO FilaWarden WordPress core online<br>[' . esc_html(gmdate('H:i:s')) . '] INFO Health ' . (int) $h['overall'] . ' (' . esc_html($h['status_label']) . ')</div>';
        $upgrade = (string) FilaWardenConfig::get('pro_upgrade_url', '');
        $banner = class_exists('FilaWardenPro') || $upgrade === '' || !FilaWardenEngine::validWebhook($upgrade)
            ? ''
            : '<div class="fw-banner"><div><strong>FilaWarden Pro</strong><div class="fw-note">Security intelligence, APM, incidents, and webhooks are in the commercial add-on.</div></div><a class="fw-btn amber" href="' . esc_url($upgrade) . '" target="_blank" rel="noopener">Upgrade</a></div>';

        return $hero . $banner . '<div class="fw-card"><h3>Subsystem Quick Access</h3><p>Direct access to real-time operations diagnostics and monitoring telemetry.</p><div class="fw-grid cols-4">' . $grid . '</div></div>' . $feed;
    }

    private function pageAuditor(): string {
        $a = $this->audit();
        $rows = '';
        foreach ($a['checks'] as $c) {
            $rows .= '<tr><td>' . esc_html($c['name']) . '<div class="fw-note">' . esc_html($c['category']) . '</div></td><td>' . $this->badge($c['status']) . '</td><td>' . esc_html($c['current']) . '</td><td>' . esc_html($c['message']) . '<div class="fw-note">' . esc_html($c['remediation']) . '</div></td></tr>';
        }

        return '<div class="fw-grid cols-3"><div class="fw-card"><div class="fw-k">Readiness</div><div class="fw-score">' . (int) $a['score'] . '</div><p>' . esc_html($a['rating']) . '</p></div><div class="fw-card"><div class="fw-k">Passed</div><div class="fw-score">' . (int) $a['passed'] . '</div></div><div class="fw-card"><div class="fw-k">Warnings / Failed</div><div class="fw-score">' . (int) $a['warnings'] . ' / ' . (int) $a['failed'] . '</div></div></div><div class="fw-card fw-scroll"><table class="fw-table"><thead><tr><th>Check</th><th>Status</th><th>Current</th><th>Detail</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function pageInfra(): string {
        $r = $this->resources();
        $thresholds = [
            'CPU' => FilaWardenConfig::get('thresholds.cpu', []),
            'Memory' => FilaWardenConfig::get('thresholds.memory', []),
            'Disk' => FilaWardenConfig::get('thresholds.disk', []),
        ];
        $card = function (string $key, ?int $pct, string $extra) use ($thresholds) {
            $limits = is_array($thresholds[$key] ?? null) ? $thresholds[$key] : [];
            $warn = (int) ($limits['warning'] ?? 70);
            $crit = (int) ($limits['critical'] ?? 90);
            $cls = '';
            if ($pct !== null && $pct >= $crit) {
                $cls = ' bad';
            } elseif ($pct !== null && $pct >= $warn) {
                $cls = ' warn';
            }
            $shown = $pct === null ? 'n/a' : ((int) $pct . '%');
            $width = $pct === null ? 0 : (int) $pct;

            return '<div class="fw-card"><div class="fw-k">' . esc_html($key) . '</div><div class="fw-score">' . esc_html($shown) . '</div><div class="fw-bar' . $cls . '"><span style="width:' . $width . '%"></span></div><p>' . $extra . '</p></div>';
        };
        $mem = $r['memory']['percentage'] === null
            ? 'Memory metrics unavailable on this host.'
            : FilaWardenEngine::bytes($r['memory']['used']) . ' / ' . FilaWardenEngine::bytes($r['memory']['total']);
        $disk = $r['disk']['percentage'] === null
            ? 'Disk metrics unavailable for this path.'
            : FilaWardenEngine::bytes($r['disk']['free']) . ' free';

        return '<div class="fw-grid cols-3">'
            . $card('CPU', $r['cpu']['percentage'], 'Load ' . implode(' / ', $r['cpu']['load']) . ' · ' . (int) $r['cpu']['cores'] . ' cores')
            . $card('Memory', $r['memory']['percentage'], $mem)
            . $card('Disk', $r['disk']['percentage'], $disk)
            . '</div><div class="fw-card"><div class="fw-k">Runtime</div><p>PHP ' . esc_html($r['php']) . ' · uptime ' . esc_html($r['uptime']) . '</p></div>';
    }

    private function pageQueues(): string {
        $crons = _get_cron_array() ?: [];
        $rows = '';
        $i = 0;
        foreach ($crons as $ts => $hooks) {
            foreach ((array) $hooks as $hook => $events) {
                foreach ((array) $events as $sig => $event) {
                    if ($i++ > 40) {
                        break 3;
                    }
                    $rows .= '<tr><td class="fw-mono">' . esc_html((string) $hook) . '</td><td>' . esc_html(gmdate('Y-m-d H:i', (int) $ts)) . '</td><td>' . esc_html((string) ($event['schedule'] ?? 'single')) . '</td><td>'
                        . $this->actionForm('retry_cron', ['hook' => $hook, 'ts' => (int) $ts, 'sig' => $sig], 'Retry') . ' '
                        . $this->actionForm('forget_cron', ['hook' => $hook, 'ts' => (int) $ts, 'sig' => $sig], 'Forget', 'danger')
                        . '</td></tr>';
                }
            }
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">No scheduled cron events.</td></tr>';
        }

        return '<div class="fw-card fw-scroll"><h3>WP-Cron queue</h3><p>Pending events. Retry keeps the event arguments and schedule. Forget removes that one instance.</p><table class="fw-table"><thead><tr><th>Hook</th><th>Timestamp</th><th>Schedule</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function pageScheduler(): string {
        $beat = (int) get_option('filawarden_heartbeat', 0);
        $age = $beat ? (time() - $beat) : null;
        $next = wp_next_scheduled('filawarden_heartbeat_event');

        return '<div class="fw-grid cols-2"><div class="fw-card"><div class="fw-k">Heartbeat age</div><div class="fw-score md">' . ($age === null ? 'none' : (int) $age . 's') . '</div><p>Last heartbeat ' . ($beat ? esc_html(gmdate('c', $beat)) : 'never') . '.</p><p>Next scheduled run ' . ($next ? esc_html(gmdate('c', $next)) : 'not scheduled') . '.</p>' . $this->actionForm('heartbeat', [], 'Record heartbeat', 'primary') . '</div><div class="fw-card"><div class="fw-k">WP-Cron</div><p>' . ((defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? 'DISABLE_WP_CRON is on. A system cron must call wp-cron.php.' : 'WP-Cron runs on site traffic.') . '</p></div></div>';
    }

    private function pageDatabase(): string {
        global $wpdb;
        $tables = [];
        if (method_exists($wpdb, 'get_results')) {
            $tables = $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
            if (!is_array($tables)) {
                $tables = [];
            }
        }
        usort($tables, fn ($a, $b) => ((int) ($b['Data_length'] ?? 0) + (int) ($b['Index_length'] ?? 0)) <=> ((int) ($a['Data_length'] ?? 0) + (int) ($a['Index_length'] ?? 0)));
        $tables = array_slice($tables, 0, 20);
        $rows = '';
        $total = 0;
        foreach ($tables as $t) {
            $size = (int) ($t['Data_length'] ?? 0) + (int) ($t['Index_length'] ?? 0);
            $total += $size;
            $rows .= '<tr><td class="fw-mono">' . esc_html($t['Name'] ?? '') . '</td><td>' . esc_html((string) ($t['Rows'] ?? '')) . '</td><td>' . esc_html(FilaWardenEngine::bytes($size)) . '</td><td>' . esc_html((string) ($t['Engine'] ?? '')) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">Table status is unavailable on this driver. Database name: ' . esc_html(DB_NAME) . '</td></tr>';
        }
        $server = method_exists($wpdb, 'db_server_info') ? (string) $wpdb->db_server_info() : '';

        return '<div class="fw-grid cols-2"><div class="fw-card"><div class="fw-k">Top tables footprint</div><div class="fw-score md">' . esc_html(FilaWardenEngine::bytes($total)) . '</div></div><div class="fw-card"><div class="fw-k">Server</div><p>' . esc_html($server !== '' ? $server : 'unavailable') . '</p><p class="fw-note">Database ' . esc_html(DB_NAME) . '</p></div></div><div class="fw-card fw-scroll"><table class="fw-table"><thead><tr><th>Table</th><th>Rows</th><th>Size</th><th>Engine</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function pageLog(): string {
        $path = FilaWardenConfig::logPath();
        $lines = FilaWardenEngine::parseLogLines(FilaWardenEngine::tailLog($path));
        $body = '';
        foreach ($lines as $row) {
            $level = $row['level'] === 'error' || $row['level'] === 'critical' || $row['level'] === 'emergency' || $row['level'] === 'alert' ? 'failed' : ($row['level'] === 'warning' ? 'warning' : 'passed');
            $body .= $this->badge($level) . ' ' . esc_html($row['line']) . "\n";
        }
        if ($body === '') {
            $body = $path === '' ? 'No log path is configured.' : 'No log entries yet.';
        }

        return '<div class="fw-card"><div class="fw-k">' . esc_html($path !== '' ? $path : 'Log path not set') . '</div><p class="fw-note">This screen reads the log. It does not change the file.</p><pre class="fw-feed">' . $body . '</pre></div>';
    }

    private function pageSsl(): string {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        $ssl = FilaWardenEngine::ssl($host, in_array($env, ['local', 'development'], true));

        return '<div class="fw-card"><div class="fw-k">Certificate</div><div class="fw-statusline"><h3>' . esc_html($ssl['host']) . '</h3>' . $this->badge($ssl['status']) . '</div><p>' . esc_html($ssl['message']) . '</p><p class="fw-note">Issuer ' . esc_html((string) $ssl['issuer']) . ' · subject ' . esc_html((string) ($ssl['subject'] ?? '')) . ' · valid to ' . esc_html((string) ($ssl['valid_to'] ?? 'n/a')) . '</p></div>';
    }

    private function pageRisk(): string {
        $ttl = (int) FilaWardenConfig::get('cache.audit_ttl', 60);
        $cached = get_transient('filawarden_risk_files');
        if (!is_array($cached)) {
            $skip = FilaWardenConfig::get('security.skip_dirs', ['wp-admin', 'wp-includes']);
            $skip = is_array($skip) ? $skip : [];
            $cached = FilaWardenEngine::riskFiles(
                ABSPATH,
                (int) FilaWardenConfig::get('risk_scan_limit', 40),
                $skip,
                (int) FilaWardenConfig::get('risk_scan_walk_limit', 8000)
            );
            set_transient('filawarden_risk_files', $cached, max(10, $ttl));
        }
        $rows = '';
        foreach ($cached as $f) {
            $rows .= '<tr><td>' . $this->badge(($f['kind'] ?? '') === 'high' ? 'failed' : 'warning') . '</td><td class="fw-mono">' . esc_html($this->relative((string) $f['path'])) . '</td><td>' . esc_html(FilaWardenEngine::bytes((float) $f['size'])) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="3">No risk files found in the scan window.</td></tr>';
        }

        return '<div class="fw-card fw-scroll"><h3>Risk file scanner</h3><p class="fw-note">Scans the document root and skips ' . esc_html(implode(', ', (array) FilaWardenConfig::get('security.skip_dirs', []))) . '.</p><table class="fw-table"><thead><tr><th>Risk</th><th>Path</th><th>Size</th></tr></thead><tbody>' . $rows . '</tbody></table>' . $this->actionForm('rescan_risk', [], 'Scan again') . '</div>';
    }

    private function relative(string $path): string {
        $root = trailingslashit(wp_normalize_path(ABSPATH));
        $norm = wp_normalize_path($path);

        return str_starts_with($norm, $root) ? substr($norm, strlen($root)) : $norm;
    }

    private function actionForm(string $action, array $extra, string $label, string $class = ''): string {
        $fields = '<input type="hidden" name="action" value="filawarden_action"><input type="hidden" name="fw_action" value="' . esc_attr($action) . '">';
        $fields .= wp_nonce_field('filawarden_action', '_fw', false, false);
        foreach ($extra as $k => $v) {
            $fields .= '<input type="hidden" name="fw_' . esc_attr((string) $k) . '" value="' . esc_attr((string) $v) . '">';
        }

        return '<form class="fw-inline-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . $fields . '<button class="fw-btn ' . esc_attr($class) . '" type="submit">' . esc_html($label) . '</button></form>';
    }

    public function handleAction(): void {
        if (!current_user_can($this->cap()) || !check_admin_referer('filawarden_action', '_fw')) {
            wp_die('Forbidden', 403);
        }
        $action = sanitize_key(wp_unslash($_POST['fw_action'] ?? ''));
        $notice = 'fix_unavailable';
        if ($action === 'heartbeat') {
            update_option('filawarden_heartbeat', time(), false);
            $notice = 'heartbeat';
        } elseif ($action === 'rescan_risk') {
            delete_transient('filawarden_risk_files');
            $notice = 'risk_rescanned';
        } elseif ($action === 'forget_cron' || $action === 'retry_cron') {
            $hook = sanitize_text_field(wp_unslash($_POST['fw_hook'] ?? ''));
            $ts = absint(wp_unslash($_POST['fw_ts'] ?? 0));
            $sig = strtolower(sanitize_text_field(wp_unslash($_POST['fw_sig'] ?? '')));
            $notice = $this->mutateCron($action, $hook, $ts, $sig) ? ($action === 'retry_cron' ? 'cron_retried' : 'cron_forgotten') : 'cron_invalid';
        }
        $this->redirect($notice);
    }

    private function mutateCron(string $action, string $hook, int $ts, string $sig): bool {
        if ($hook === '' || !$ts || !preg_match('/^[A-Za-z0-9_-]+$/', $hook) || !preg_match('/^[a-f0-9]{32}$/', $sig)) {
            return false;
        }
        $crons = _get_cron_array();
        $event = $crons[$ts][$hook][$sig] ?? null;
        if (!is_array($event)) {
            return false;
        }
        $args = is_array($event['args'] ?? null) ? $event['args'] : [];
        wp_unschedule_event($ts, $hook, $args);
        if ($action === 'retry_cron') {
            if (!empty($event['schedule'])) {
                wp_schedule_event(time() + 5, (string) $event['schedule'], $hook, $args);
            } else {
                wp_schedule_single_event(time() + 5, $hook, $args);
            }
        }

        return true;
    }

    private function redirect(string $notice): void {
        $target = wp_get_referer() ?: admin_url('admin.php?page=filawarden');
        wp_safe_redirect(add_query_arg('fw_notice', $notice, $target));
        exit;
    }
}
