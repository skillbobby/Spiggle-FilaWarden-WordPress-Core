<?php
/**
 * Runtime configuration.
 *
 * Defaults match the Laravel packages' config/filawarden.php and
 * config/filawarden-advanced.php. Override a value with a wp-config.php
 * constant, an environment variable of the same name, or the
 * `filawarden_config` filter. Nothing in the plugins embeds a site URL,
 * license key, webhook, or log path.
 */
class FilaWardenConfig {
    public static function all(): array {
        $config = [
            'enabled' => true,
            'capability' => 'manage_options',
            'menu_position' => 3,
            'pro_upgrade_url' => 'https://spiggle.dev/filawarden-pro',
            'managed_cloud_url' => 'https://spiggle.dev/managed-cloud',
            'log_path' => null,
            'max_scanned_files' => 500,
            'risk_scan_limit' => 40,
            'risk_scan_walk_limit' => 8000,
            'permalink_structure' => '/%postname%/',
            'license_pattern' => '/^FW-PRO-[A-Z0-9-]{8,}$/',
            'insecure_salt' => 'put your unique phrase here',
            'config_perms_max' => 0644,
            'heartbeat_stale_seconds' => 600,
            'cron_overdue_seconds' => 900,
            'cron_overdue_warn_count' => 10,
            'thresholds' => [
                'cpu' => ['warning' => 70, 'critical' => 90],
                'memory' => ['warning' => 75, 'critical' => 90],
                'disk' => ['warning' => 80, 'critical' => 95],
            ],
            'cache' => [
                'telemetry_ttl' => 10,
                'audit_ttl' => 60,
            ],
            'apm' => [
                'capture_queries' => true,
                'slow_query_threshold_ms' => 500,
                'slow_request_threshold_ms' => 1000,
                'retention_seconds' => 3600,
                'slow_query_retention_seconds' => 604800,
                'sample_every' => 1,
            ],
            'security' => [
                'scan_secrets' => true,
                'scan_public_exposures' => true,
                'audit_headers' => true,
                'skip_dirs' => ['wp-admin', 'wp-includes', 'vendor', 'node_modules', '.git'],
            ],
            'alerts' => [
                'slack_webhook' => '',
                'discord_webhook' => '',
                'webhook_url' => '',
                'email_recipient' => '',
            ],
            'recommendations_path' => null,
        ];

        $config = self::applyConstants($config);
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('filawarden_config', $config);
            if (is_array($filtered)) {
                $config = array_replace_recursive($config, $filtered);
            }
        }

        return $config;
    }

    public static function get(string $key, mixed $default = null): mixed {
        $value = self::all();
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function logPath(): string {
        $configured = self::get('log_path');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        if (defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '' && WP_DEBUG_LOG !== '1') {
            return WP_DEBUG_LOG;
        }
        if (defined('WP_CONTENT_DIR')) {
            return WP_CONTENT_DIR . '/debug.log';
        }

        return '';
    }

    public static function recommendationsPath(): string {
        $configured = self::get('recommendations_path');
        if (is_string($configured) && $configured !== '' && is_readable($configured)) {
            return $configured;
        }
        if (defined('FILAWARDEN_PRO_DIR')) {
            $pro = FILAWARDEN_PRO_DIR . 'data/recommendations.json';
            if (is_readable($pro)) {
                return $pro;
            }
        }

        return defined('FILAWARDEN_DIR') ? FILAWARDEN_DIR . 'data/recommendations.json' : '';
    }

    private static function applyConstants(array $config): array {
        $map = [
            'FILAWARDEN_CAPABILITY' => 'capability',
            'FILAWARDEN_PRO_UPGRADE_URL' => 'pro_upgrade_url',
            'FILAWARDEN_MANAGED_CLOUD_URL' => 'managed_cloud_url',
            'FILAWARDEN_LOG_PATH' => 'log_path',
            'FILAWARDEN_MAX_SCANNED_FILES' => 'max_scanned_files',
            'FILAWARDEN_RECOMMENDATIONS_PATH' => 'recommendations_path',
            'FILAWARDEN_SLOW_QUERY_THRESHOLD' => 'apm.slow_query_threshold_ms',
            'FILAWARDEN_SLOW_REQUEST_THRESHOLD' => 'apm.slow_request_threshold_ms',
            'FILAWARDEN_ALERT_SLACK_WEBHOOK' => 'alerts.slack_webhook',
            'FILAWARDEN_ALERT_DISCORD_WEBHOOK' => 'alerts.discord_webhook',
            'FILAWARDEN_ALERT_WEBHOOK_URL' => 'alerts.webhook_url',
            'FILAWARDEN_ALERT_EMAIL' => 'alerts.email_recipient',
        ];
        foreach ($map as $constant => $path) {
            $value = self::constantOrEnv($constant);
            if ($value === null || $value === '') {
                continue;
            }
            if (is_numeric($value) && (str_contains($path, 'threshold') || $path === 'max_scanned_files')) {
                $value = (int) $value;
            }
            self::setPath($config, $path, $value);
        }
        if (self::constantOrEnv('FILAWARDEN_ENABLED') === '0') {
            $config['enabled'] = false;
        }
        $capture = self::constantOrEnv('FILAWARDEN_CAPTURE_QUERIES');
        if ($capture === '0' || $capture === 'false') {
            $config['apm']['capture_queries'] = false;
        }

        return $config;
    }

    private static function constantOrEnv(string $name): ?string {
        if (defined($name)) {
            $value = constant($name);
            return is_scalar($value) ? (string) $value : null;
        }
        $env = getenv($name);
        return $env === false ? null : $env;
    }

    private static function setPath(array &$config, string $path, mixed $value): void {
        $cursor = &$config;
        $parts = explode('.', $path);
        $last = array_pop($parts);
        foreach ($parts as $part) {
            if (!isset($cursor[$part]) || !is_array($cursor[$part])) {
                $cursor[$part] = [];
            }
            $cursor = &$cursor[$part];
        }
        $cursor[$last] = $value;
    }
}
