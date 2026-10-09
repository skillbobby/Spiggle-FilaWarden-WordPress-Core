<?php
/**
 * FilaWarden collector engine. Platform adapters pass a context array.
 */
class FilaWardenEngine {
    public static function resources(string $root): array {
        $load = self::loadAverage();
        $cores = self::cpuCores();
        $cpu = (int) min(100, round(($load[0] / $cores) * 100));
        $memory = self::hostMemory();
        $diskTotalRaw = @disk_total_space($root);
        $diskFreeRaw = @disk_free_space($root);
        $diskPct = null;
        $diskTotal = 0.0;
        $diskFree = 0.0;
        $diskUsed = 0.0;
        if ($diskTotalRaw !== false && $diskFreeRaw !== false) {
            $diskTotal = (float) $diskTotalRaw;
            $diskFree = (float) $diskFreeRaw;
            $diskUsed = max(0, $diskTotal - $diskFree);
            $diskPct = $diskTotal > 0 ? (int) round(($diskUsed / $diskTotal) * 100) : 0;
        }

        return [
            'cpu' => ['percentage' => $cpu, 'load' => $load, 'cores' => $cores],
            'memory' => $memory,
            'disk' => ['percentage' => $diskPct, 'used' => $diskUsed, 'total' => $diskTotal, 'free' => $diskFree],
            'php' => PHP_VERSION,
            'uptime' => self::uptimeLabel(self::uptimeSeconds()),
        ];
    }

    public static function cpuListCount(string $list): int {
        $count = 0;
        foreach (explode(',', $list) as $part) {
            $part = trim($part);
            if (preg_match('/^(\d+)-(\d+)$/', $part, $match) === 1) {
                $count += max(0, (int) $match[2] - (int) $match[1] + 1);
            } elseif (preg_match('/^\d+$/', $part) === 1) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{percentage: ?int, used: int, total: int}|null
     */
    public static function memoryFromText(string $text): ?array {
        $fields = self::memoryFields($text);
        $totalKb = $fields['MemTotal'] ?? 0;
        if ($totalKb <= 0) {
            return null;
        }
        if ($fields['MemAvailable'] !== null) {
            $availableKb = $fields['MemAvailable'];
        } else {
            // Same inputs as the kernel available-memory estimate, without the
            // zone low-watermark holdback. Watermarks live in /proc/zoneinfo.
            $availableKb = ($fields['MemFree'] ?? 0)
                + ($fields['Active(file)'] ?? 0)
                + ($fields['Inactive(file)'] ?? 0)
                + ($fields['SReclaimable'] ?? 0);
        }

        return self::memoryPair($totalKb, $availableKb);
    }

    public static function startTicks(string $stat): ?int {
        $end = strrpos($stat, ')');
        if ($end === false) {
            return null;
        }
        $fields = preg_split('/\s+/', trim(substr($stat, $end + 1))) ?: [];
        if (!isset($fields[19]) || !is_numeric($fields[19])) {
            return null;
        }

        return (int) $fields[19];
    }

    public static function uptimeLabel(?int $seconds): string {
        if ($seconds === null || $seconds < 0) {
            return 'n/a';
        }

        return sprintf('%dd %dh %dm', intdiv($seconds, 86400), intdiv($seconds % 86400, 3600), intdiv($seconds % 3600, 60));
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function loadAverage(): array {
        $text = self::readText('/proc/loadavg');
        if ($text !== null) {
            $parts = explode(' ', trim($text));

            return [(float) ($parts[0] ?? 0), (float) ($parts[1] ?? 0), (float) ($parts[2] ?? 0)];
        }
        $avg = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        if (!is_array($avg) || !isset($avg[0], $avg[1], $avg[2])) {
            return [0.0, 0.0, 0.0];
        }

        return [(float) $avg[0], (float) $avg[1], (float) $avg[2]];
    }

    private static function cpuCores(): int {
        $info = self::readText('/proc/cpuinfo');
        if ($info !== null) {
            $count = substr_count($info, 'processor');
            if ($count > 0) {
                return $count;
            }
        }
        $online = self::readText('/sys/devices/system/cpu/online');
        if ($online !== null) {
            $count = self::cpuListCount($online);
            if ($count > 0) {
                return $count;
            }
        }

        return 1;
    }

    /** @return array{percentage: ?int, used: int, total: int} */
    private static function hostMemory(): array {
        $proc = self::readText('/proc/meminfo');
        if ($proc !== null) {
            $parsed = self::memoryFromText($proc);
            if ($parsed !== null) {
                return $parsed;
            }
        }
        $total = 0;
        $free = 0;
        $active = 0;
        $inactive = 0;
        $reclaimable = 0;
        $available = 0;
        $haveAvailable = true;
        $saw = false;
        foreach (glob('/sys/devices/system/node/node*/meminfo') ?: [] as $path) {
            $text = self::readText($path);
            if ($text === null) {
                continue;
            }
            $fields = self::memoryFields($text);
            if (($fields['MemTotal'] ?? 0) <= 0) {
                continue;
            }
            $saw = true;
            $total += $fields['MemTotal'];
            $free += $fields['MemFree'] ?? 0;
            $active += $fields['Active(file)'] ?? 0;
            $inactive += $fields['Inactive(file)'] ?? 0;
            $reclaimable += $fields['SReclaimable'] ?? 0;
            if ($fields['MemAvailable'] === null) {
                $haveAvailable = false;
            } else {
                $available += $fields['MemAvailable'];
            }
        }
        if (!$saw || $total <= 0) {
            return ['percentage' => null, 'used' => 0, 'total' => 0];
        }
        if (!$haveAvailable) {
            $available = $free + $active + $inactive + $reclaimable;
        }

        return self::memoryPair($total, $available);
    }

    /** @return array{percentage: int, used: int, total: int} */
    private static function memoryPair(int $totalKb, int $availableKb): array {
        $total = $totalKb * 1024;
        $available = max(0, min($totalKb, $availableKb)) * 1024;
        $used = max(0, $total - $available);

        return [
            'percentage' => (int) round(($used / $total) * 100),
            'used' => $used,
            'total' => $total,
        ];
    }

    /** @return array<string, ?int> */
    private static function memoryFields(string $text): array {
        $keys = ['MemTotal', 'MemFree', 'MemAvailable', 'Active(file)', 'Inactive(file)', 'SReclaimable'];
        $fields = array_fill_keys($keys, null);
        foreach ($keys as $key) {
            $quoted = preg_quote($key, '/');
            if (preg_match('/(?:^|\n)\s*(?:Node\s+\d+\s+)?' . $quoted . ':\s+(\d+)/', $text, $match) === 1) {
                $fields[$key] = (int) $match[1];
            }
        }

        return $fields;
    }

    private static function uptimeSeconds(): ?int {
        $text = self::readText('/proc/uptime');
        if ($text !== null) {
            return max(0, (int) floatval(explode(' ', trim($text))[0]));
        }
        // Apache on current Ubuntu hides /proc/uptime (ProcSubset=pid). A new
        // process can still read its own start time, which is the boot uptime.
        if (!function_exists('shell_exec') || self::functionDisabled('shell_exec')) {
            return null;
        }
        foreach (['/bin/cat', '/usr/bin/cat'] as $cat) {
            if (!is_executable($cat)) {
                continue;
            }
            $stat = shell_exec($cat . ' /proc/self/stat 2>/dev/null');
            $ticks = is_string($stat) ? self::startTicks($stat) : null;
            if ($ticks !== null) {
                return (int) round($ticks / 100);
            }
        }

        return null;
    }

    private static function functionDisabled(string $name): bool {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return in_array($name, $disabled, true);
    }

    private static function readText(string $path): ?string {
        if (!is_readable($path)) {
            return null;
        }
        $text = @file_get_contents($path);
        if (!is_string($text) || $text === '') {
            return null;
        }

        return $text;
    }

    public static function bytes(float $n): string {
        $u = ['B','KB','MB','GB','TB'];
        $i = 0;
        while ($n >= 1024 && $i < count($u) - 1) { $n /= 1024; $i++; }
        return number_format($n, $i ? 1 : 0) . ' ' . $u[$i];
    }

    public static function audit(array $checks): array {
        $passed = count(array_filter($checks, fn($c) => $c['status'] === 'passed'));
        $warnings = count(array_filter($checks, fn($c) => $c['status'] === 'warning'));
        $failed = count(array_filter($checks, fn($c) => $c['status'] === 'failed'));
        $max = array_sum(array_column($checks, 'weight'));
        $earned = 0;
        foreach ($checks as $c) {
            if ($c['status'] === 'passed') $earned += $c['weight'];
            elseif ($c['status'] === 'warning') $earned += $c['weight'] * 0.5;
        }
        $score = $max > 0 ? (int) round(($earned / $max) * 100) : 100;
        $rating = $score >= 90 ? 'Production Ready' : ($score >= 70 ? 'Needs Optimization' : ($score >= 50 ? 'Caution: Degraded' : 'Not Ready / High Risk'));
        return compact('score', 'rating', 'passed', 'warnings', 'failed') + [
            'total' => count($checks),
            'checks' => $checks,
            'audited_at' => gmdate('c'),
        ];
    }

    public static function check(string $id, string $name, string $category, int $weight, string $status, string $current, string $recommended, string $message, string $remediation): array {
        return compact('id', 'name', 'category', 'weight', 'status', 'current', 'recommended', 'message', 'remediation');
    }

    public static function health(array $audit, array $resources): array {
        $deploymentScore = $audit['score'];
        $penalize = static function (mixed $percentage, int $start, float $multiplier): float {
            if (!is_numeric($percentage)) {
                return 0.0;
            }

            return max(0, ((float) $percentage) - $start) * $multiplier;
        };
        $cpuPenalty = $penalize($resources['cpu']['percentage'] ?? null, 50, 1.5);
        $memPenalty = $penalize($resources['memory']['percentage'] ?? null, 60, 1.5);
        $diskPenalty = $penalize($resources['disk']['percentage'] ?? null, 70, 2.0);
        $infra = (int) max(10, min(100, round(100 - ($cpuPenalty + $memPenalty + $diskPenalty))));
        $relPenalty = 0;
        foreach ($audit['checks'] as $c) {
            if ($c['id'] === 'queue_workers' && $c['status'] !== 'passed') $relPenalty += 25;
            if ($c['id'] === 'scheduler_running' && $c['status'] !== 'passed') $relPenalty += 20;
        }
        $reliability = max(20, 100 - $relPenalty);
        $sec = array_values(array_filter($audit['checks'], fn($c) => $c['category'] === 'Security'));
        $perf = array_values(array_filter($audit['checks'], fn($c) => $c['category'] === 'Performance'));
        $secScore = count($sec) ? (int) round(count(array_filter($sec, fn($c) => $c['status'] === 'passed')) / count($sec) * 100) : 100;
        $perfScore = count($perf) ? (int) round(count(array_filter($perf, fn($c) => $c['status'] === 'passed')) / count($perf) * 100) : 100;
        $overall = (int) round($deploymentScore * 0.25 + $infra * 0.25 + $reliability * 0.20 + $secScore * 0.20 + $perfScore * 0.10);
        $status = $overall >= 85 ? 'healthy' : ($overall >= 65 ? 'warning' : 'danger');
        $label = $status === 'healthy' ? 'Healthy' : ($status === 'warning' ? 'Degraded' : 'Critical');
        $vec = function ($name, $score, $ok, $warn) {
            return ['name' => $name, 'score' => $score, 'status' => $score >= $ok ? 'healthy' : ($score >= $warn ? 'warning' : 'danger')];
        };
        return [
            'overall' => $overall,
            'status' => $status,
            'status_label' => $label,
            'vectors' => [
                'deployment' => $vec('Deployment Readiness', $deploymentScore, 80, 60),
                'infrastructure' => $vec('Infrastructure Headroom', $infra, 75, 50),
                'reliability' => $vec('Operational Reliability', $reliability, 80, 60),
                'security' => $vec('Security Baseline', $secScore, 80, 60),
                'performance' => $vec('Optimization & Caches', $perfScore, 80, 50),
            ],
            'evaluated_at' => gmdate('c'),
        ];
    }

    public static function riskFiles(string $root, int $limit = 40, array $skip = ['vendor', 'node_modules', '.git', 'core'], int $maxVisited = 8000): array {
        $found = [];
        $n = 0;
        foreach (self::iterateFiles($root, $skip) as $file) {
            if ($n > $maxVisited) {
                break;
            }
            $n++;
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            $base = strtolower($file->getFilename());
            $kind = null;
            if (in_array($ext, ['sql', 'sh'], true) || $base === '.env') {
                $kind = 'high';
            } elseif (in_array($ext, ['zip', 'tar', 'gz'], true)) {
                $kind = 'medium';
            }
            if ($kind === null) {
                continue;
            }
            $found[] = ['path' => $file->getPathname(), 'size' => $file->getSize(), 'kind' => $kind, 'name' => $file->getFilename()];
            if (count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }

    public static function tailLog(string $path, int $max = 80): array {
        if ($path === '' || !is_readable($path)) return [];
        $size = filesize($path);
        if ($size === false || $size === 0) return [];
        $read = (int) min($size, 200000);
        $chunk = file_get_contents($path, false, null, $size - $read, $read);
        if (!is_string($chunk) || $chunk === '') {
            return [];
        }
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', $chunk) ?: []));
        return array_slice($lines, -$max);
    }

    public static function parseLogLines(array $lines): array {
        $parsed = [];
        foreach ($lines as $line) {
            $level = 'info';
            if (preg_match('/\b(emergency|alert|critical|error|warning|notice|debug)\b/i', $line, $m)) {
                $level = strtolower($m[1]);
            } elseif (stripos($line, 'PHP Fatal') !== false || stripos($line, 'PHP Parse') !== false) {
                $level = 'error';
            }
            $parsed[] = ['level' => $level, 'line' => $line];
        }
        return $parsed;
    }

    public static function ssl(string $host, bool $local = false): array {
        $host = strtolower(trim($host));
        if ($local || in_array($host, ['localhost', '127.0.0.1', '::1', ''], true)) {
            return ['status' => 'passed', 'host' => $host ?: 'localhost', 'message' => 'Local development host.', 'days' => null, 'issuer' => 'n/a', 'subject' => 'local', 'valid_from' => null, 'valid_to' => null];
        }
        if (!preg_match('/^[a-z0-9.-]+$/', $host)) {
            return ['status' => 'failed', 'host' => $host, 'message' => 'Host is not a DNS name.', 'days' => null, 'issuer' => 'n/a', 'subject' => '', 'valid_from' => null, 'valid_to' => null];
        }
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => true, 'verify_peer_name' => true]]);
        $client = @stream_socket_client('ssl://' . $host . ':443', $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
        if (!$client) {
            $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false]]);
            $client = @stream_socket_client('ssl://' . $host . ':443', $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
        }
        if (!$client) return ['status' => 'failed', 'host' => $host, 'message' => $errstr ?: 'TLS handshake failed', 'days' => null, 'issuer' => 'n/a', 'subject' => '', 'valid_from' => null, 'valid_to' => null];
        $params = stream_context_get_params($client);
        $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate'] ?? '') ?: [];
        fclose($client); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- This closes the TLS socket, not a file.
        $to = (int) ($cert['validTo_time_t'] ?? 0);
        $from = (int) ($cert['validFrom_time_t'] ?? 0);
        $days = $to ? (int) floor(($to - time()) / 86400) : null;
        $issuer = $cert['issuer']['O'] ?? ($cert['issuer']['CN'] ?? 'unknown');
        $subject = $cert['subject']['CN'] ?? $host;
        $status = 'passed';
        if ($days !== null && $days < 30) $status = 'warning';
        if ($days !== null && $days < 0) $status = 'failed';
        return ['status' => $status, 'host' => $host, 'message' => $days === null ? 'Certificate parsed' : ($days . ' days remaining'), 'days' => $days, 'issuer' => $issuer, 'subject' => $subject, 'valid_from' => $from ? gmdate('Y-m-d', $from) : null, 'valid_to' => $to ? gmdate('Y-m-d', $to) : null];
    }

    public static function percentiles(array $samples): array {
        $samples = array_values(array_filter(array_map('intval', $samples), fn($n) => $n >= 0));
        sort($samples);
        $n = count($samples);
        if ($n === 0) return ['p50' => 0, 'p95' => 0, 'p99' => 0, 'count' => 0];
        $pick = function ($p) use ($samples, $n) {
            $i = (int) min($n - 1, max(0, ceil($p * $n) - 1));
            return (int) round($samples[$i]);
        };
        return ['p50' => $pick(0.50), 'p95' => $pick(0.95), 'p99' => $pick(0.99), 'count' => $n];
    }

    public static function secretRules(): array {
        return [
            'aws_access_key' => ['title' => 'Exposed AWS Access Key ID', 'severity' => 'critical', 'regex' => '/(?:^|[^A-Z0-9])(AKIA[0-9A-Z]{16})(?:[^A-Z0-9]|$)/', 'remediation' => 'Rotate the AWS IAM access key.'],
            'stripe_live_key' => ['title' => 'Exposed Stripe Live Secret Key', 'severity' => 'critical', 'regex' => '/(sk_live_[0-9a-zA-Z]{24,})/', 'remediation' => 'Roll the live secret key in Stripe.'],
            'github_pat' => ['title' => 'Exposed GitHub Personal Access Token', 'severity' => 'critical', 'regex' => '/(ghp_[a-zA-Z0-9]{36}|github_pat_[a-zA-Z0-9]{22}_[a-zA-Z0-9]{59})/', 'remediation' => 'Revoke the token in GitHub developer settings.'],
            'slack_bot_token' => ['title' => 'Exposed Slack Bot Token', 'severity' => 'critical', 'regex' => '/(xox[baprs]-[0-9A-Za-z-]{10,})/', 'remediation' => 'Rotate the Slack token.'],
            'google_api_key' => ['title' => 'Exposed Google Cloud API Key', 'severity' => 'high', 'regex' => '/(AIza[0-9A-Za-z\-_]{35})/', 'remediation' => 'Restrict or delete the key in Google Cloud.'],
            'private_key' => ['title' => 'Exposed Private Cryptographic Key', 'severity' => 'critical', 'regex' => '/(-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----)/', 'remediation' => 'Revoke and replace the key pair.'],
        ];
    }

    public static function secretPatterns(): array {
        $out = [];
        foreach (self::secretRules() as $id => $rule) $out[$id] = $rule['regex'];
        return $out;
    }

    public static function maskSecret(string $value): string {
        $len = strlen($value);
        if ($len <= 8) return str_repeat('*', $len);
        return substr($value, 0, 4) . str_repeat('*', max(4, $len - 8)) . substr($value, -4);
    }

    public static function scanSecrets(array $files): array {
        $findings = [];
        foreach ($files as $file) {
            if (!is_string($file) || !is_readable($file) || is_dir($file)) continue;
            $size = filesize($file);
            if ($size === false || $size === 0 || $size > 1500000) continue;
            $text = (string) file_get_contents($file);
            if ($text === '' || !mb_check_encoding($text, 'UTF-8')) continue;
            $lines = explode("\n", $text);
            foreach ($lines as $i => $line) {
                foreach (self::secretRules() as $type => $rule) {
                    if (preg_match($rule['regex'], $line, $m)) {
                        $raw = $m[1] ?? $m[0];
                        $findings[] = [
                            'type' => $type,
                            'title' => $rule['title'],
                            'severity' => $rule['severity'],
                            'file' => $file,
                            'line' => $i + 1,
                            'preview' => self::maskSecret($raw),
                            'remediation' => $rule['remediation'],
                        ];
                    }
                }
            }
        }
        return $findings;
    }

    public static function candidateFiles(string $root, int $max = 400, array $skip = ['vendor', 'node_modules', '.git', 'core']): array {
        $found = [];
        $allow = ['php', 'env', 'json', 'yml', 'yaml', 'js', 'log', 'txt', 'ini'];
        foreach (self::iterateFiles($root, $skip) as $file) {
            if (count($found) >= $max) {
                break;
            }
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            $name = $file->getFilename();
            if ($name === 'composer.lock' || $name === 'package-lock.json') {
                continue;
            }
            if ($ext === 'env' || str_starts_with($name, '.env') || in_array($ext, $allow, true)) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    /**
     * Files under $root, without descending into skipped directory names.
     * Skipped trees do not consume scan budgets.
     *
     * @param list<string> $skip
     * @return Generator<int, SplFileInfo>
     */
    private static function iterateFiles(string $root, array $skip): Generator {
        if (!is_dir($root)) {
            return;
        }
        $skipSet = array_fill_keys($skip, true);
        try {
            $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
            $filtered = new RecursiveCallbackFilterIterator($directory, static function ($current) use ($skipSet): bool {
                return !($current->isDir() && isset($skipSet[$current->getFilename()]));
            });
            $iterator = new RecursiveIteratorIterator($filtered, RecursiveIteratorIterator::LEAVES_ONLY);
            foreach ($iterator as $file) {
                yield $file;
            }
        } catch (UnexpectedValueException) {
            return;
        }
    }

    public static function headerReport(array $headers): array {
        $need = ['content-security-policy' => 'CSP', 'strict-transport-security' => 'HSTS', 'x-frame-options' => 'X-Frame-Options'];
        $lower = [];
        foreach ($headers as $k => $v) $lower[strtolower((string) $k)] = $v;
        $rows = [];
        foreach ($need as $key => $label) {
            $present = isset($lower[$key]) && $lower[$key] !== '';
            $rows[] = ['id' => $key, 'label' => $label, 'status' => $present ? 'passed' : 'failed', 'current' => $present ? 'present' : 'missing'];
        }
        return $rows;
    }

    public static function validWebhook(string $url): bool {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
        $parts = wp_parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']);
    }
}
