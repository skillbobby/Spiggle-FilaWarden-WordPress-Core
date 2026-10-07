<?php
if (!defined('ABSPATH')) {
    exit;
}

class FilaWardenPlugin {
    private static $instance;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public static function activate(): void {
        if (!get_option('filawarden_heartbeat')) {
            update_option('filawarden_heartbeat', time(), false);
        }
        if (!wp_next_scheduled('filawarden_heartbeat_event')) {
            wp_schedule_event(time(), 'filawarden_minutely', 'filawarden_heartbeat_event');
        }
    }

    public function boot(): void {
        add_filter('cron_schedules', function ($s) {
            $s['filawarden_minutely'] = ['interval' => 60, 'display' => 'Every Minute'];
            return $s;
        });
        add_action('filawarden_heartbeat_event', static function () {
            update_option('filawarden_heartbeat', time(), false);
        });
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_init', [$this, 'maybeHeartbeat']);
        add_action('admin_post_filawarden_action', [$this, 'handleAction']);
    }

    public function maybeHeartbeat(): void {
        if (current_user_can('manage_options')) {
            update_option('filawarden_heartbeat', time(), false);
        }
    }

    public function menu(): void {
        add_menu_page('FilaWarden', 'FilaWarden', 'manage_options', 'filawarden', [$this, 'render'], 'dashicons-shield', 3);
        foreach ($this->pages() as $slug => $label) {
            add_submenu_page('filawarden', $label, $label, 'manage_options', 'filawarden-' . $slug, [$this, 'render']);
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
        wp_enqueue_script('filawarden', plugins_url('assets/js/filawarden.js', FILAWARDEN_FILE), [], FILAWARDEN_VERSION, true);
    }

    public function currentPage(): string {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'filawarden';
        $page = str_replace('filawarden-', '', $page);
        $page = $page === 'filawarden' ? 'dashboard' : $page;
        return array_key_exists($page, $this->pages()) ? $page : 'dashboard';
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $page = $this->currentPage();
        echo $this->shell($page, $this->body($page));
    }

    public function shell(string $page, string $body): string {
        $nav = '';
        foreach ($this->pages() as $slug => $label) {
            $url = esc_url(admin_url('admin.php?page=' . ($slug === 'dashboard' ? 'filawarden' : 'filawarden-' . $slug)));
            $active = $slug === $page ? ' active' : '';
            $nav .= '<a class="' . $active . '" href="' . $url . '">' . esc_html($label) . '</a>';
        }
        if (class_exists('FilaWardenPro')) {
            foreach (FilaWardenPro::pages() as $slug => $label) {
                $url = esc_url(admin_url('admin.php?page=filawarden-pro-' . $slug));
                $nav .= '<a class="pro" href="' . $url . '">' . esc_html($label) . '</a>';
            }
            $pro = '';
        } else {
            $pro = '<div class="grp">Pro</div><a class="pro" href="https://filawarden.com" target="_blank" rel="noopener">Upgrade to Pro</a>';
        }
        $title = esc_html($this->pages()[$page] ?? 'FilaWarden');
        $health = $this->health();
        return '<div class="fw-app" id="fw-app"><aside class="fw-sidebar"><div class="fw-brand"><div class="fw-mark">FW</div><div><strong>FilaWarden</strong><span>Operations</span></div></div><nav class="fw-nav"><div class="grp">Core</div>' . $nav . $pro . '</nav></aside><main class="fw-main"><header class="fw-top"><div><h1>' . $title . '</h1><p>WordPress operations sentinel · ' . esc_html($health['status_label']) . ' ' . (int) $health['overall'] . '</p></div><div class="fw-actions"><button type="button" class="fw-btn" id="fw-theme">Dark mode</button><a class="fw-btn" href="' . esc_url(admin_url()) . '">WP Admin</a></div></header><div class="fw-content">' . $body . '</div></main></div>';
    }

    public function checks(): array {
        $debug = defined('WP_DEBUG') && WP_DEBUG;
        $display = defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY;
        $edit = defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT;
        $keys = defined('AUTH_KEY') && strlen(AUTH_KEY) > 8 && AUTH_KEY !== 'put your unique phrase here';
        $https = is_ssl() || str_starts_with(home_url(), 'https://');
        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        $opcache = function_exists('opcache_get_status') && ($st = @opcache_get_status(false)) && !empty($st['opcache_enabled']);
        $object = wp_using_ext_object_cache();
        $cronOff = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $beat = (int) get_option('filawarden_heartbeat', 0);
        $schedOk = $beat && (time() - $beat) < 600;
        $failed = $this->failedCronCount();
        $prefix = $GLOBALS['wpdb']->prefix ?? 'wp_';
        $config = ABSPATH . 'wp-config.php';
        $perms = is_readable($config) ? (fileperms($config) & 0777) : 0644;
        return [
            FilaWardenEngine::check('app_debug', 'WP_DEBUG State', 'Security', 15, $debug ? 'failed' : 'passed', $debug ? 'Enabled' : 'Disabled', 'Disabled', $debug ? 'Debug mode can expose stack traces.' : 'Debug mode is disabled.', 'Set WP_DEBUG to false in wp-config.php.'),
            FilaWardenEngine::check('app_env', 'Environment Type', 'Security', 10, $env === 'production' ? 'passed' : 'warning', $env, 'production', 'Environment is ' . $env . '.', 'Use wp_get_environment_type() production on live sites.'),
            FilaWardenEngine::check('app_key', 'Auth Keys & Salts', 'Security', 15, $keys ? 'passed' : 'failed', $keys ? 'Configured' : 'Missing', 'Unique salts', $keys ? 'Salts are set.' : 'Default or missing salts.', 'Regenerate salts from https://api.wordpress.org/secret-key/1.1/salt/'),
            FilaWardenEngine::check('debug_display', 'Display Errors', 'Security', 8, $display ? 'failed' : 'passed', $display ? 'Displayed' : 'Hidden', 'Hidden', $display ? 'Errors may render to visitors.' : 'Display of errors is off.', 'Set WP_DEBUG_DISPLAY to false.'),
            FilaWardenEngine::check('file_edit', 'Theme/Plugin Editor', 'Security', 6, $edit ? 'passed' : 'warning', $edit ? 'Disallowed' : 'Allowed', 'Disallowed', $edit ? 'File editor is disabled.' : 'Dashboard file editor is enabled.', 'Define DISALLOW_FILE_EDIT as true.'),
            FilaWardenEngine::check('config_cache', 'Object Cache', 'Performance', 8, $object ? 'passed' : 'warning', $object ? 'External' : 'Default', 'External object cache', $object ? 'External object cache is active.' : 'Using default object cache.', 'Install Redis or Memcached object-cache.php.'),
            FilaWardenEngine::check('route_cache', 'OPcache', 'Performance', 8, $opcache ? 'passed' : 'warning', $opcache ? 'Enabled' : 'Off', 'Enabled', $opcache ? 'OPcache is enabled.' : 'OPcache is not active.', 'Enable Zend OPcache in php.ini.'),
            FilaWardenEngine::check('view_cache', 'Permalink Cache', 'Performance', 5, get_option('permalink_structure') ? 'passed' : 'warning', get_option('permalink_structure') ?: 'Plain', 'Pretty permalinks', 'Permalink structure checked.', 'Set pretty permalinks in Settings → Permalinks.'),
            FilaWardenEngine::check('queue_workers', 'Cron Queue Health', 'Infrastructure', 8, $failed > 10 ? 'warning' : 'passed', $failed . ' overdue hooks', 'Zero overdue', $failed . ' cron hooks are overdue.', 'Inspect WP-Cron and failed scheduled events.'),
            FilaWardenEngine::check('scheduler_running', 'Scheduler Heartbeat', 'Infrastructure', 8, $schedOk ? 'passed' : 'warning', $schedOk ? 'Active' : 'Stale', 'Heartbeat < 10 min', $schedOk ? 'Heartbeat is fresh.' : 'No recent scheduler heartbeat.', 'Ensure WP-Cron or system cron hits wp-cron.php.'),
            FilaWardenEngine::check('storage_link', 'Config Permissions', 'Infrastructure', 5, $perms <= 0644 ? 'passed' : 'failed', decoct($perms), '0640 or 0644', 'wp-config.php mode is ' . decoct($perms) . '.', 'chmod 640 wp-config.php.'),
            FilaWardenEngine::check('https_enforcement', 'HTTPS Scheme', 'Security', 4, ($https || $env === 'local') ? 'passed' : 'warning', $https ? 'HTTPS' : 'HTTP', 'HTTPS', $https ? 'Site URL uses HTTPS.' : 'Site URL is not HTTPS.', 'Set siteurl/home to https and force SSL.'),
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

    private function failedCronCount(): int {
        $crons = _get_cron_array();
        if (!is_array($crons)) {
            return 0;
        }
        $n = 0;
        $now = time();
        foreach ($crons as $ts => $hooks) {
            if ((int) $ts < $now - 900) {
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
        $cards = '';
        foreach ($h['vectors'] as $v) {
            $cls = $v['status'] === 'healthy' ? '' : ($v['status'] === 'warning' ? ' warn' : ' bad');
            $cards .= '<div class="fw-card"><div class="fw-k">' . esc_html($v['name']) . '</div><div class="fw-score" style="font-size:28px">' . (int) $v['score'] . '</div>' . $this->badge($v['status']) . '<div class="fw-bar' . $cls . '" style="margin-top:8px"><span style="width:' . (int) $v['score'] . '%"></span></div></div>';
        }
        $links = [
            'auditor' => ['Deployment Auditor', '12 production readiness checks, debug state, and cache validation.'],
            'infrastructure' => ['Infrastructure Telemetry', 'Real-time /proc CPU load, RAM, disk headroom, and uptime.'],
            'queues' => ['Queue Monitor', 'Background jobs, pending cron, and failed event inspection.'],
            'scheduler' => ['Task Scheduler', 'Cron heartbeat and scheduled event registry.'],
            'database' => ['Database Health', 'Storage footprint, connections, and top tables.'],
            'error-log' => ['Error Log Reader', 'Parsed error records and log footprint.'],
            'ssl' => ['SSL / TLS Certificate', 'Validity countdown and issuer.'],
            'risk-files' => ['Risk File Scanner', 'Public dumps, shell scripts, and stray archives.'],
        ];
        $grid = '';
        foreach ($links as $slug => $meta) {
            $url = esc_url(admin_url('admin.php?page=' . ($slug === 'dashboard' ? 'filawarden' : 'filawarden-' . $slug)));
            $grid .= '<a class="fw-card fw-launch" href="' . $url . '"><h3>' . esc_html($meta[0]) . '</h3><p>' . esc_html($meta[1]) . '</p></a>';
        }
        $feed = '<div class="fw-feed">[' . esc_html(gmdate('H:i:s')) . '] INFO FilaWarden WordPress core online<br>[' . esc_html(gmdate('H:i:s')) . '] INFO Health ' . (int) $h['overall'] . ' (' . esc_html($h['status_label']) . ')</div>';
        return '<div class="fw-grid cols-4"><div class="fw-card"><div class="fw-k">Overall</div><div class="fw-score">' . (int) $h['overall'] . '</div>' . $this->badge($h['status']) . '</div>' . $cards . '</div><div class="fw-card"><h3>Subsystem Quick Access</h3><p>Direct access to real-time operations diagnostics.</p><div class="fw-grid cols-4" style="margin-top:12px">' . $grid . '</div></div>' . $feed;
    }

    private function pageAuditor(): string {
        $a = $this->audit();
        $rows = '';
        foreach ($a['checks'] as $c) {
            $rows .= '<tr><td>' . esc_html($c['name']) . '<div class="fw-note">' . esc_html($c['category']) . '</div></td><td>' . $this->badge($c['status']) . '</td><td>' . esc_html($c['current']) . '</td><td>' . esc_html($c['message']) . '<div class="fw-note">' . esc_html($c['remediation']) . '</div></td></tr>';
        }
        return '<div class="fw-grid cols-3"><div class="fw-card"><div class="fw-k">Readiness</div><div class="fw-score">' . (int) $a['score'] . '</div><p>' . esc_html($a['rating']) . '</p></div><div class="fw-card"><div class="fw-k">Passed</div><div class="fw-score">' . (int) $a['passed'] . '</div></div><div class="fw-card"><div class="fw-k">Warnings / Failed</div><div class="fw-score">' . (int) $a['warnings'] . ' / ' . (int) $a['failed'] . '</div></div></div><div class="fw-card" style="overflow:auto"><table class="fw-table"><thead><tr><th>Check</th><th>Status</th><th>Current</th><th>Detail</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function pageInfra(): string {
        $r = $this->resources();
        $card = function ($k, $pct, $extra) {
            $cls = $pct >= 85 ? ' bad' : ($pct >= 70 ? ' warn' : '');
            return '<div class="fw-card"><div class="fw-k">' . esc_html($k) . '</div><div class="fw-score">' . (int) $pct . '%</div><div class="fw-bar' . $cls . '"><span style="width:' . (int) $pct . '%"></span></div><p>' . $extra . '</p></div>';
        };
        return '<div class="fw-grid cols-3">' . $card('CPU', $r['cpu']['percentage'], 'Load ' . implode(' / ', $r['cpu']['load']) . ' · ' . (int) $r['cpu']['cores'] . ' cores') . $card('Memory', $r['memory']['percentage'], FilaWardenEngine::bytes($r['memory']['used']) . ' / ' . FilaWardenEngine::bytes($r['memory']['total'])) . $card('Disk', $r['disk']['percentage'], FilaWardenEngine::bytes($r['disk']['free']) . ' free') . '</div><div class="fw-card"><div class="fw-k">Runtime</div><p>PHP ' . esc_html($r['php']) . ' · uptime ' . esc_html($r['uptime']) . '</p></div>';
    }

    private function pageQueues(): string {
        $crons = _get_cron_array() ?: [];
        $rows = '';
        $i = 0;
        foreach ($crons as $ts => $hooks) {
            foreach ((array) $hooks as $hook => $events) {
                if ($i++ > 25) break 2;
                $rows .= '<tr><td class="fw-mono">' . esc_html($hook) . '</td><td>' . esc_html(gmdate('Y-m-d H:i', (int) $ts)) . '</td><td>' . count((array) $events) . '</td><td>' . $this->actionForm('forget_cron', ['hook' => $hook, 'ts' => (int) $ts], 'Forget') . '</td></tr>';
            }
        }
        if ($rows === '') $rows = '<tr><td colspan="4">No scheduled cron events.</td></tr>';
        return '<div class="fw-card"><h3>WP-Cron queue</h3><p>Pending events. Forget removes one scheduled hook instance.</p><table class="fw-table"><thead><tr><th>Hook</th><th>Timestamp</th><th>Events</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function pageScheduler(): string {
        $beat = (int) get_option('filawarden_heartbeat', 0);
        $age = $beat ? (time() - $beat) : null;
        return '<div class="fw-card"><div class="fw-k">Heartbeat</div><div class="fw-score" style="font-size:28px">' . ($age === null ? 'none' : (int) $age . 's') . '</div><p>Last heartbeat ' . ($beat ? esc_html(gmdate('c', $beat)) : 'never') . '.</p>' . $this->actionForm('heartbeat', [], 'Record heartbeat', 'primary') . '</div>';
    }

    private function pageDatabase(): string {
        global $wpdb;
        $tables = $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
        if (!is_array($tables)) {
            $tables = [];
        }
        usort($tables, fn($a, $b) => ((int) ($b['Data_length'] ?? 0) + (int) ($b['Index_length'] ?? 0)) <=> ((int) ($a['Data_length'] ?? 0) + (int) ($a['Index_length'] ?? 0)));
        $tables = array_slice($tables, 0, 20);
        $rows = '';
        $total = 0;
        foreach ($tables as $t) {
            $size = (int) ($t['Data_length'] ?? 0) + (int) ($t['Index_length'] ?? 0);
            $total += $size;
            $rows .= '<tr><td class="fw-mono">' . esc_html($t['Name'] ?? '') . '</td><td>' . esc_html((string) ($t['Rows'] ?? '')) . '</td><td>' . esc_html(FilaWardenEngine::bytes($size)) . '</td><td>' . esc_html((string) ($t['Engine'] ?? 'sqlite')) . '</td></tr>';
        }
        if ($rows === '') $rows = '<tr><td colspan="4">Table status unavailable on this driver. Database name: ' . esc_html(DB_NAME) . '</td></tr>';
        return '<div class="fw-grid cols-2"><div class="fw-card"><div class="fw-k">Top tables footprint</div><div class="fw-score" style="font-size:28px">' . esc_html(FilaWardenEngine::bytes($total)) . '</div></div><div class="fw-card"><div class="fw-k">Server</div><p>' . esc_html($wpdb->db_server_info() ?: 'sqlite') . '</p></div></div><div class="fw-card" style="overflow:auto"><table class="fw-table"><thead><tr><th>Table</th><th>Rows</th><th>Size</th><th>Engine</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function pageLog(): string {
        $path = WP_CONTENT_DIR . '/debug.log';
        $lines = FilaWardenEngine::tailLog($path);
        $body = $lines ? implode("\n", array_map('esc_html', $lines)) : 'No debug.log yet. Enable WP_DEBUG_LOG to capture errors.';
        return '<div class="fw-card"><div class="fw-k">' . esc_html($path) . '</div>' . $this->actionForm('truncate_log', [], 'Truncate log', 'danger') . '<pre class="fw-feed" style="margin-top:12px;white-space:pre-wrap">' . $body . '</pre></div>';
    }

    private function pageSsl(): string {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $ssl = FilaWardenEngine::ssl($host);
        return '<div class="fw-card"><div class="fw-k">Certificate</div><h3>' . esc_html($ssl['host']) . '</h3>' . $this->badge($ssl['status']) . '<p>' . esc_html($ssl['message']) . ' · issuer ' . esc_html((string) $ssl['issuer']) . '</p></div>';
    }

    private function pageRisk(): string {
        $files = FilaWardenEngine::riskFiles(ABSPATH);
        $rows = '';
        foreach ($files as $f) {
            $rows .= '<tr><td>' . $this->badge($f['kind'] === 'high' ? 'failed' : 'warning') . '</td><td class="fw-mono">' . esc_html($f['path']) . '</td><td>' . esc_html(FilaWardenEngine::bytes($f['size'])) . '</td></tr>';
        }
        if ($rows === '') $rows = '<tr><td colspan="3">No risk files found in the scan window.</td></tr>';
        return '<div class="fw-card"><h3>Risk file scanner</h3><table class="fw-table"><thead><tr><th>Risk</th><th>Path</th><th>Size</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function actionForm(string $action, array $extra, string $label, string $class = ''): string {
        $fields = '<input type="hidden" name="action" value="filawarden_action"><input type="hidden" name="fw_action" value="' . esc_attr($action) . '">';
        $fields .= wp_nonce_field('filawarden_action', '_fw', false, false);
        foreach ($extra as $k => $v) {
            $fields .= '<input type="hidden" name="fw_' . esc_attr($k) . '" value="' . esc_attr((string) $v) . '">';
        }
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">' . $fields . '<button class="fw-btn ' . esc_attr($class) . '" type="submit">' . esc_html($label) . '</button></form>';
    }

    public function handleAction(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('filawarden_action', '_fw')) {
            wp_die('Forbidden');
        }
        $action = sanitize_key(wp_unslash($_POST['fw_action'] ?? ''));
        if ($action === 'heartbeat') {
            update_option('filawarden_heartbeat', time(), false);
        } elseif ($action === 'truncate_log') {
            $path = WP_CONTENT_DIR . '/debug.log';
            if (is_writable($path)) {
                file_put_contents($path, '');
            }
        } elseif ($action === 'forget_cron') {
            $hook = sanitize_text_field(wp_unslash($_POST['fw_hook'] ?? ''));
            $ts = (int) ($_POST['fw_ts'] ?? 0);
            if ($hook && $ts) {
                wp_unschedule_event($ts, $hook);
            }
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=filawarden'));
        exit;
    }
}
