<?php
/**
 * HTTP helpers for the WordPress live suite.
 * Load this with WP-CLI eval-file so WordPress is already booted.
 */
if (!defined('ABSPATH')) {
    fwrite(STDERR, "live-lib.php must run inside WordPress\n");
    exit(1);
}

final class FwLive {
    private static string $cookie = '';
    private static string $nonce = '';
    private static string $proNonce = '';
    private static int $userId = 0;
    private static string $session = '';
    /** @var list<callable> */
    private static array $cleanups = [];

    public static function boot(): void {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $user = get_user_by('login', 'admin');
        if (!$user instanceof WP_User) {
            self::fail('admin user is missing');
        }
        self::$userId = (int) $user->ID;
        $expiration = time() + HOUR_IN_SECONDS;
        $manager = WP_Session_Tokens::get_instance(self::$userId);
        self::$session = $manager->create($expiration);
        $auth = wp_generate_auth_cookie(self::$userId, $expiration, 'auth', self::$session);
        $loggedIn = wp_generate_auth_cookie(self::$userId, $expiration, 'logged_in', self::$session);
        $_COOKIE[LOGGED_IN_COOKIE] = $loggedIn;
        wp_set_current_user(self::$userId);
        $parsed = wp_parse_auth_cookie($loggedIn, 'logged_in');
        self::ok(is_array($parsed) && ($parsed['token'] ?? '') === self::$session, 'session token is in the login cookie');
        self::$nonce = wp_create_nonce('filawarden_action');
        self::$proNonce = wp_create_nonce('filawarden_pro');
        self::ok(wp_verify_nonce(self::$nonce, 'filawarden_action') === 1, 'core nonce verifies');
        self::$cookie = AUTH_COOKIE . '=' . $auth . '; ' . LOGGED_IN_COOKIE . '=' . $loggedIn;
        self::cleanup(static function (): void {
            if (self::$userId && self::$session !== '') {
                WP_Session_Tokens::get_instance(self::$userId)->destroy(self::$session);
            }
        });
        register_shutdown_function([self::class, 'cleanupNow']);
    }

    public static function cleanup(callable $fn): void {
        self::$cleanups[] = $fn;
    }

    public static function cleanupNow(): void {
        $fns = array_reverse(self::$cleanups);
        self::$cleanups = [];
        foreach ($fns as $fn) {
            try {
                $fn();
            } catch (Throwable $e) {
                fwrite(STDERR, 'cleanup: ' . $e->getMessage() . "\n");
            }
        }
    }

    public static function ok(bool $ok, string $message): void {
        if (!$ok) {
            self::fail($message);
        }
    }

    public static function fail(string $message): void {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }

    public static function request(string $url, array $opt = []): array {
        $headers = ['Cache-Control' => 'no-cache'];
        if (($opt['cookie'] ?? self::$cookie) !== '') {
            $headers['Cookie'] = (string) ($opt['cookie'] ?? self::$cookie);
        }
        if (!empty($opt['referer'])) {
            $headers['Referer'] = (string) $opt['referer'];
        }
        $args = [
            'method' => array_key_exists('post', $opt) ? 'POST' : 'GET',
            'timeout' => (int) ($opt['timeout'] ?? 60),
            'redirection' => 0,
            'headers' => $headers,
            'cookies' => [],
        ];
        if (array_key_exists('post', $opt)) {
            $args['body'] = $opt['post'];
        }
        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) {
            self::fail($url . ' ' . $res->get_error_message());
        }
        wp_cache_flush();
        $map = [];
        $raw = wp_remote_retrieve_headers($res);
        if (is_object($raw) && method_exists($raw, 'getAll')) {
            foreach ($raw->getAll() as $key => $value) {
                $map[strtolower((string) $key)] = is_array($value) ? implode(', ', $value) : (string) $value;
            }
        }

        return [
            'status' => (int) wp_remote_retrieve_response_code($res),
            'body' => (string) wp_remote_retrieve_body($res),
            'location' => (string) ($map['location'] ?? ''),
            'headers' => $map,
        ];
    }

    public static function screen(string $page, array $needles = []): string {
        $res = self::request(admin_url('admin.php?page=' . $page));
        self::ok($res['status'] === 200, "{$page} HTTP {$res['status']} {$res['location']}");
        $body = $res['body'];
        self::ok(!str_contains($body, 'There has been a critical error'), "{$page} rendered");
        self::ok(str_contains($body, 'id="fw-app"'), "{$page} shell");
        self::ok(str_contains($body, 'fw-screen'), "{$page} screen class");
        self::ok(str_contains($body, 'filawarden-css'), "{$page} enqueues the stylesheet");
        self::ok(str_contains($body, 'filawarden-boot'), "{$page} enqueues the theme boot script");
        self::ok(!str_contains($body, 'id="fw-screen"') && !str_contains($body, "id='fw-screen'"), "{$page} does not echo a style tag");
        self::ok(!str_contains($body, 'localStorage.getItem'), "{$page} does not print the theme script inline");
        self::ok(str_contains($body, 'id="fw-theme"'), "{$page} theme control");
        foreach ($needles as $needle) {
            self::ok(str_contains($body, $needle), "{$page} contains {$needle}");
        }
        self::guardSecrets($page, $body);

        return $body;
    }

    public static function post(string $which, string $action, array $fields, string $page): array {
        $referer = admin_url('admin.php?page=' . $page);
        $fields['action'] = $which === 'pro' ? 'filawarden_pro_action' : 'filawarden_action';
        $fields['fw_action'] = $action;
        $fields['_fw'] = $which === 'pro' ? self::$proNonce : self::$nonce;
        $fields['_wp_http_referer'] = $referer;

        return self::request(admin_url('admin-post.php'), [
            'post' => $fields,
            'referer' => $referer,
            'timeout' => 90,
        ]);
    }

    public static function notice(array $res): string {
        $query = [];
        parse_str((string) parse_url($res['location'], PHP_URL_QUERY), $query);

        return (string) ($query['fw_notice'] ?? '');
    }

    public static function expectNotice(array $res, string $notice, string $label): void {
        if ($res['status'] !== 302) {
            $hint = str_contains($res['body'], 'Are you sure') ? 'nonce rejected' : 'HTTP ' . $res['status'];
            self::fail("{$label} ({$hint})");
        }
        $got = self::notice($res);
        self::ok($got === $notice, "{$label} notice was {$got}");
    }

    public static function guardSecrets(string $label, string $body): void {
        foreach (['DB_PASSWORD', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT'] as $name) {
            if (!defined($name)) {
                continue;
            }
            $value = (string) constant($name);
            if (strlen($value) < 12) {
                continue;
            }
            self::ok(!str_contains($body, $value), "{$label} does not expose {$name}");
        }
    }

    public static function finish(string $label): void {
        self::cleanupNow();
        echo "{$label} ok\n";
    }
}
