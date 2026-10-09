<?php
// Load DB credentials from .env.php (falls back to local dev defaults if not set)
$env_file = __DIR__ . '/.env.php';
if (file_exists($env_file)) {
    require_once $env_file;
}

date_default_timezone_set('Asia/Manila');

if (!defined('PHPUNIT_RUNNING')) {
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

require_once __DIR__ . '/cache_helper.php';
require_once __DIR__ . '/supabase_driver.php';
require_once __DIR__ . '/crypto_helper.php';

// Connect directly to Supabase (bypassed in PHPUnit CLI test suite)
if (!defined('PHPUNIT_RUNNING')) {
    $conn = new SupabaseConnection();

    // Fallback to local MySQL only if Supabase has an immediate connection error
    // AND valid local DB credentials are configured (not placeholder values).
    if (!empty($conn->connect_error)) {
        $has_local_config = defined('ENV_DB_HOST') && ENV_DB_HOST !== 'your_db_host'
                        && defined('ENV_DB_NAME') && ENV_DB_NAME !== 'your_db_name';

        if ($has_local_config) {
            $servername = ENV_DB_HOST;
            $username   = (defined('ENV_DB_USER') && ENV_DB_USER !== 'your_db_username') ? ENV_DB_USER : 'root';
            $password   = (defined('ENV_DB_PASS') && ENV_DB_PASS !== 'your_db_password') ? ENV_DB_PASS : '';
            $dbname     = ENV_DB_NAME;

            $mysql_fallback = @new mysqli($servername, $username, $password, $dbname);
            if (!$mysql_fallback->connect_error) {
                $conn = $mysql_fallback;
                error_log('[QES DB] Supabase unavailable, fell back to local MySQL.');
            }
        }

        // If we still have a connection error after fallback attempt, halt
        if ($conn->connect_error || (isset($mysql_fallback) && $mysql_fallback->connect_error)) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'reply' => "Our system is temporarily unavailable. Please try again shortly or use the 'Book Now' button to submit your inquiry.",
                'response' => "Our system is temporarily unavailable. Please try again shortly.",
                'error' => 'db_connection_failed'
            ]);
            error_log('[QES DB] All database connections failed. Supabase: ' . ($conn->connect_error ?? 'unknown'));
            exit;
        }
    }

    $conn->set_charset("utf8mb4");
}

// Secure Session Configuration & CSRF Protection
if (session_status() === PHP_SESSION_NONE && !headers_sent() && php_sapi_name() !== 'cli') {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'] ?? '';
    }
}

// =========================================================================
// Hardened Rate Limiter for Authentication & Security-Sensitive Operations
// =========================================================================

if (!defined('ADMIN_LOGIN_MAX_ATTEMPTS')) {
    define('ADMIN_LOGIN_MAX_ATTEMPTS', 5);
}
if (!defined('ADMIN_LOGIN_LOCKOUT_SECONDS')) {
    define('ADMIN_LOGIN_LOCKOUT_SECONDS', 900); // 15 minutes
}
if (!defined('ADMIN_LOGIN_IP_MAX_ATTEMPTS')) {
    define('ADMIN_LOGIN_IP_MAX_ATTEMPTS', 20); // Lenient per-IP ceiling
}
if (!defined('ADMIN_LOGIN_IP_LOCKOUT_SECONDS')) {
    define('ADMIN_LOGIN_IP_LOCKOUT_SECONDS', 900); // 15 minutes
}

if (!function_exists('qes_trusted_proxies')) {
    function qes_trusted_proxies(): array {
        if (defined('ENV_TRUSTED_PROXIES')) {
            if (is_array(ENV_TRUSTED_PROXIES)) {
                return ENV_TRUSTED_PROXIES;
            }
            if (is_string(ENV_TRUSTED_PROXIES) && trim(ENV_TRUSTED_PROXIES) !== '') {
                return array_filter(array_map('trim', explode(',', ENV_TRUSTED_PROXIES)));
            }
        }
        return []; // Empty by default (secure default: trust only REMOTE_ADDR)
    }
}

if (!function_exists('qes_client_ip')) {
    function qes_client_ip(?array $trusted_proxies = null): string {
        $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $remote_ip = filter_var($remote_addr, FILTER_VALIDATE_IP) ? $remote_addr : '127.0.0.1';

        $trusted = $trusted_proxies !== null ? $trusted_proxies : qes_trusted_proxies();

        // Trust forwarded headers ONLY if REMOTE_ADDR is in the trusted-proxy list
        if (!empty($trusted) && in_array($remote_ip, $trusted, true)) {
            $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'];
            foreach ($headers as $header) {
                if (!empty($_SERVER[$header])) {
                    $candidates = explode(',', $_SERVER[$header]);
                    foreach ($candidates as $cand) {
                        $cand = trim($cand);
                        if (filter_var($cand, FILTER_VALIDATE_IP)) {
                            return $cand;
                        }
                    }
                }
            }
        }

        return $remote_ip;
    }
}

if (!function_exists('qes_is_postgres_conn')) {
    function qes_is_postgres_conn($conn): bool {
        if ($conn instanceof SupabaseConnection) {
            return true;
        }
        if ($conn instanceof \PDO) {
            return $conn->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql';
        }
        if ($conn instanceof \mysqli) {
            return false;
        }
        return false;
    }
}

if (!function_exists('qes_db_cleanup_expired')) {
    function qes_db_cleanup_expired($conn): void {
        // Run probabilistically (~1% of requests)
        try {
            if (random_int(1, 100) !== 1) {
                return;
            }
            if (!$conn || (isset($conn->connect_error) && $conn->connect_error)) {
                return;
            }
            if (qes_is_postgres_conn($conn)) {
                $sql = "DELETE FROM login_attempts WHERE (locked_until IS NOT NULL AND locked_until < NOW()) OR (locked_until IS NULL AND first_attempt < (NOW() - INTERVAL '3600 seconds'))";
            } else {
                $sql = "DELETE FROM login_attempts WHERE (locked_until IS NOT NULL AND locked_until < NOW()) OR (locked_until IS NULL AND first_attempt < DATE_SUB(NOW(), INTERVAL 3600 SECOND))";
            }
            $conn->query($sql);
        } catch (\Throwable $e) {
            // Non-critical background cleanup failure, suppress
        }
    }
}

if (!function_exists('qes_db_rate_limit_check')) {
    function qes_db_rate_limit_check($conn, string $action, string $key, int $max_attempts, int $decay_seconds): ?array {
        if (!$conn || (isset($conn->connect_error) && $conn->connect_error)) {
            return null;
        }
        if (is_object($conn) && (get_class($conn) === 'Qes\Tests\Fakes\FakeConnection' || str_ends_with(get_class($conn), 'FakeConnection'))) {
            return null;
        }
        $clean_action = preg_replace('/[^a-z0-9_-]/i', '', $action);
        $key_hash = hash('sha256', strtolower(trim($clean_action)) . ':' . strtolower(trim($key)));

        try {
            $stmt = $conn->prepare("SELECT attempts, first_attempt, locked_until, NOW() AS current_db_time FROM login_attempts WHERE key_hash = ? LIMIT 1");
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param("s", $key_hash);
            if (!$stmt->execute()) {
                $stmt->close();
                return null;
            }
            $res = $stmt->get_result();
            if (!$res || $res->num_rows === 0) {
                $stmt->close();
                qes_db_cleanup_expired($conn);
                return [
                    'allowed' => true,
                    'retry_after' => 0,
                    'remaining' => $max_attempts,
                    'message' => ''
                ];
            }

            $row = $res->fetch_assoc();
            $stmt->close();

            $attempts = (int)($row['attempts'] ?? 0);
            $now_ts = !empty($row['current_db_time']) ? strtotime($row['current_db_time']) : time();
            $locked_ts = !empty($row['locked_until']) ? strtotime($row['locked_until']) : 0;
            $first_ts = !empty($row['first_attempt']) ? strtotime($row['first_attempt']) : $now_ts;

            if ($locked_ts > $now_ts) {
                $retry_after = (int)($locked_ts - $now_ts);
                $mins = max(1, (int)ceil($retry_after / 60));
                return [
                    'allowed' => false,
                    'retry_after' => $retry_after,
                    'remaining' => 0,
                    'message' => "Too many failed attempts. Please try again in {$mins} minute(s)."
                ];
            }

            // Decay window expired: reset row
            if (($now_ts - $first_ts) > $decay_seconds) {
                $del = $conn->prepare("DELETE FROM login_attempts WHERE key_hash = ?");
                if ($del) {
                    $del->bind_param("s", $key_hash);
                    $del->execute();
                    $del->close();
                }
                return [
                    'allowed' => true,
                    'retry_after' => 0,
                    'remaining' => $max_attempts,
                    'message' => ''
                ];
            }

            $remaining = max(0, $max_attempts - $attempts);
            return [
                'allowed' => ($attempts < $max_attempts),
                'retry_after' => 0,
                'remaining' => $remaining,
                'message' => ''
            ];
        } catch (\Throwable $e) {
            error_log('[QES RateLimit DB Check Error] ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('qes_db_rate_limit_record_fail')) {
    function qes_db_rate_limit_record_fail($conn, string $action, string $key, int $max_attempts = 5, int $lockout_seconds = 900): ?int {
        if (!$conn || (isset($conn->connect_error) && $conn->connect_error)) {
            return null;
        }
        if (is_object($conn) && (get_class($conn) === 'Qes\Tests\Fakes\FakeConnection' || str_ends_with(get_class($conn), 'FakeConnection'))) {
            return null;
        }
        $clean_action = preg_replace('/[^a-z0-9_-]/i', '', $action);
        $key_hash = hash('sha256', strtolower(trim($clean_action)) . ':' . strtolower(trim($key)));
        $is_pg = qes_is_postgres_conn($conn);

        try {
            if ($is_pg) {
                $sql = "INSERT INTO login_attempts (key_hash, scope, attempts, first_attempt, locked_until, updated_at)
                        VALUES (?, ?, 1, NOW(), NULL, NOW())
                        ON CONFLICT (key_hash) DO UPDATE SET
                          attempts = CASE
                            WHEN login_attempts.locked_until IS NOT NULL AND login_attempts.locked_until > NOW()
                              THEN login_attempts.attempts
                            WHEN EXTRACT(EPOCH FROM (NOW() - login_attempts.first_attempt)) > ?
                              THEN 1
                            ELSE login_attempts.attempts + 1
                          END,
                          first_attempt = CASE
                            WHEN login_attempts.locked_until IS NOT NULL AND login_attempts.locked_until > NOW()
                              THEN login_attempts.first_attempt
                            WHEN EXTRACT(EPOCH FROM (NOW() - login_attempts.first_attempt)) > ?
                              THEN NOW()
                            ELSE login_attempts.first_attempt
                          END,
                          locked_until = CASE
                            WHEN login_attempts.locked_until IS NOT NULL AND login_attempts.locked_until > NOW()
                              THEN login_attempts.locked_until
                            WHEN (login_attempts.attempts + 1) >= ?
                              AND EXTRACT(EPOCH FROM (NOW() - login_attempts.first_attempt)) <= ?
                              THEN NOW() + (? * INTERVAL '1 second')
                            ELSE NULL
                          END,
                          updated_at = NOW()
                        RETURNING attempts";
                $stmt = $conn->prepare($sql);
                if (!$stmt) return null;
                $stmt->bind_param("ssiiiii", $key_hash, $clean_action, $lockout_seconds, $lockout_seconds, $max_attempts, $lockout_seconds, $lockout_seconds);
                if (!$stmt->execute()) {
                    $stmt->close();
                    return null;
                }
                $res = $stmt->get_result();
                $attempts = 1;
                if ($res && $row = $res->fetch_assoc()) {
                    $attempts = (int)($row['attempts'] ?? 1);
                }
                $stmt->close();
                return $attempts;
            } else {
                $sql = "INSERT INTO login_attempts (key_hash, scope, attempts, first_attempt, locked_until, updated_at)
                        VALUES (?, ?, 1, NOW(), NULL, NOW())
                        ON DUPLICATE KEY UPDATE
                          attempts = IF(locked_until IS NOT NULL AND locked_until > NOW(),
                            attempts,
                            IF(TIMESTAMPDIFF(SECOND, first_attempt, NOW()) > ?, 1, attempts + 1)
                          ),
                          first_attempt = IF(locked_until IS NOT NULL AND locked_until > NOW(),
                            first_attempt,
                            IF(TIMESTAMPDIFF(SECOND, first_attempt, NOW()) > ?, NOW(), first_attempt)
                          ),
                          locked_until = IF(locked_until IS NOT NULL AND locked_until > NOW(),
                            locked_until,
                            IF((attempts + 1) >= ? AND TIMESTAMPDIFF(SECOND, first_attempt, NOW()) <= ?,
                              DATE_ADD(NOW(), INTERVAL ? SECOND),
                              NULL
                            )
                          ),
                          updated_at = NOW()";
                $stmt = $conn->prepare($sql);
                if (!$stmt) return null;
                $stmt->bind_param("ssiiiii", $key_hash, $clean_action, $lockout_seconds, $lockout_seconds, $max_attempts, $lockout_seconds, $lockout_seconds);
                if (!$stmt->execute()) {
                    $stmt->close();
                    return null;
                }
                $stmt->close();

                $sel = $conn->prepare("SELECT attempts FROM login_attempts WHERE key_hash = ? LIMIT 1");
                if ($sel) {
                    $sel->bind_param("s", $key_hash);
                    $sel->execute();
                    $res = $sel->get_result();
                    $attempts = 1;
                    if ($res && $row = $res->fetch_assoc()) {
                        $attempts = (int)($row['attempts'] ?? 1);
                    }
                    $sel->close();
                    return $attempts;
                }
                return 1;
            }
        } catch (\Throwable $e) {
            error_log('[QES RateLimit DB Fail Error] ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('qes_db_rate_limit_clear')) {
    function qes_db_rate_limit_clear($conn, string $action, string $key): bool {
        if (!$conn || (isset($conn->connect_error) && $conn->connect_error)) {
            return false;
        }
        if (is_object($conn) && (get_class($conn) === 'Qes\Tests\Fakes\FakeConnection' || str_ends_with(get_class($conn), 'FakeConnection'))) {
            return false;
        }
        $clean_action = preg_replace('/[^a-z0-9_-]/i', '', $action);
        $key_hash = hash('sha256', strtolower(trim($clean_action)) . ':' . strtolower(trim($key)));
        try {
            $stmt = $conn->prepare("DELETE FROM login_attempts WHERE key_hash = ?");
            if ($stmt) {
                $stmt->bind_param("s", $key_hash);
                $res = $stmt->execute();
                $stmt->close();
                return (bool)$res;
            }
        } catch (\Throwable $e) {
            error_log('[QES RateLimit DB Clear Error] ' . $e->getMessage());
        }
        return false;
    }
}

// -------------------------------------------------------------------------
// File Store Fallback (with atomic flock to prevent race conditions)
// -------------------------------------------------------------------------

if (!function_exists('qes_file_rate_limit_check')) {
    function qes_file_rate_limit_check(string $action, string $key, int $max_attempts = 5, int $decay_seconds = 900): array {
        $clean_action = preg_replace('/[^a-z0-9_-]/i', '', $action);
        $hashed_key = hash('sha256', strtolower(trim($key)));
        $temp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qes_limits';
        if (!is_dir($temp_dir)) {
            @mkdir($temp_dir, 0700, true);
        }
        $file = $temp_dir . DIRECTORY_SEPARATOR . $clean_action . '_' . $hashed_key . '.json';
        $now = time();
        $data = ['attempts' => 0, 'first_attempt' => $now, 'locked_until' => 0];

        if (file_exists($file)) {
            $content = @file_get_contents($file);
            if ($content) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $data = array_merge($data, $decoded);
                }
            }
        }

        // If locked out
        if (!empty($data['locked_until']) && $data['locked_until'] > $now) {
            $retry_after = (int)($data['locked_until'] - $now);
            $mins = max(1, (int)ceil($retry_after / 60));
            return [
                'allowed' => false,
                'retry_after' => $retry_after,
                'remaining' => 0,
                'message' => "Too many failed attempts. Please try again in {$mins} minute(s)."
            ];
        }

        // Reset if decay window has elapsed
        if (($now - (int)($data['first_attempt'] ?? $now)) > $decay_seconds) {
            $data = ['attempts' => 0, 'first_attempt' => $now, 'locked_until' => 0];
            @unlink($file);
        }

        $remaining = max(0, $max_attempts - (int)($data['attempts'] ?? 0));
        return [
            'allowed' => ((int)($data['attempts'] ?? 0) < $max_attempts),
            'retry_after' => 0,
            'remaining' => $remaining,
            'message' => ''
        ];
    }
}

if (!function_exists('qes_file_rate_limit_record_fail')) {
    function qes_file_rate_limit_record_fail(string $action, string $key, int $max_attempts = 5, int $lockout_seconds = 900): int {
        $clean_action = preg_replace('/[^a-z0-9_-]/i', '', $action);
        $hashed_key = hash('sha256', strtolower(trim($key)));
        $temp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qes_limits';
        if (!is_dir($temp_dir)) {
            @mkdir($temp_dir, 0700, true);
        }
        $file = $temp_dir . DIRECTORY_SEPARATOR . $clean_action . '_' . $hashed_key . '.json';
        $now = time();
        $data = ['attempts' => 0, 'first_attempt' => $now, 'locked_until' => 0];

        $fp = @fopen($file, 'c+');
        if ($fp) {
            if (@flock($fp, LOCK_EX)) {
                $content = '';
                while (!feof($fp)) {
                    $content .= fread($fp, 8192);
                }
                if (!empty($content)) {
                    $decoded = json_decode($content, true);
                    if (is_array($decoded)) {
                        $data = array_merge($data, $decoded);
                    }
                }

                // Reset if previous window expired
                if (($now - (int)($data['first_attempt'] ?? $now)) > $lockout_seconds && empty($data['locked_until'])) {
                    $data['attempts'] = 0;
                    $data['first_attempt'] = $now;
                }

                // If not locked, increment
                if (empty($data['locked_until']) || $data['locked_until'] <= $now) {
                    $data['attempts'] = ((int)($data['attempts'] ?? 0)) + 1;
                    if ($data['attempts'] >= $max_attempts) {
                        $data['locked_until'] = $now + $lockout_seconds;
                    }
                }

                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($data));
                fflush($fp);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
            return (int)$data['attempts'];
        }

        return 1;
    }
}

if (!function_exists('qes_file_rate_limit_clear')) {
    function qes_file_rate_limit_clear(string $action, string $key): void {
        $clean_action = preg_replace('/[^a-z0-9_-]/i', '', $action);
        $hashed_key = hash('sha256', strtolower(trim($key)));
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qes_limits' . DIRECTORY_SEPARATOR . $clean_action . '_' . $hashed_key . '.json';
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}

// -------------------------------------------------------------------------
// Unified Rate Limiter Public API (Preserves Existing Signatures & Shapes)
// -------------------------------------------------------------------------

if (!function_exists('qes_rate_limit_check')) {
    function qes_rate_limit_check(string $action, string $key, int $max_attempts = 5, int $decay_seconds = 900, $conn = null): array {
        if ($conn === null) {
            global $conn;
        }
        if ($conn) {
            $db_res = qes_db_rate_limit_check($conn, $action, $key, $max_attempts, $decay_seconds);
            if ($db_res !== null) {
                return $db_res;
            }
        }
        return qes_file_rate_limit_check($action, $key, $max_attempts, $decay_seconds);
    }
}

if (!function_exists('qes_rate_limit_record_fail')) {
    function qes_rate_limit_record_fail(string $action, string $key, int $max_attempts = 5, int $lockout_seconds = 900, $conn = null): int {
        if ($conn === null) {
            global $conn;
        }
        if ($conn) {
            $db_res = qes_db_rate_limit_record_fail($conn, $action, $key, $max_attempts, $lockout_seconds);
            if ($db_res !== null) {
                return $db_res;
            }
        }
        return qes_file_rate_limit_record_fail($action, $key, $max_attempts, $lockout_seconds);
    }
}

if (!function_exists('qes_rate_limit_clear')) {
    function qes_rate_limit_clear(string $action, string $key, $conn = null): void {
        if ($conn === null) {
            global $conn;
        }
        if ($conn) {
            qes_db_rate_limit_clear($conn, $action, $key);
        }
        qes_file_rate_limit_clear($action, $key);
    }
}

// -------------------------------------------------------------------------
// Admin Authentication Hardening Helpers
// -------------------------------------------------------------------------

if (!function_exists('qes_resolve_admin_user')) {
    function qes_resolve_admin_user($conn, string $identifier): ?array {
        if (!$conn || (isset($conn->connect_error) && $conn->connect_error)) {
            return null;
        }
        $trimmed = trim($identifier);
        if ($trimmed === '') {
            return null;
        }
        try {
            $stmt = $conn->prepare("SELECT * FROM users WHERE (email = ? OR username = ? OR (role IN ('admin', 'super_admin', 'main_admin') AND ? = 'admin')) LIMIT 1");
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param("sss", $trimmed, $trimmed, $trimmed);
            if (!$stmt->execute()) {
                $stmt->close();
                return null;
            }
            $res = $stmt->get_result();
            $user = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
            $stmt->close();
            return $user;
        } catch (\Throwable $e) {
            error_log('[QES Resolve Admin User Error] ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('qes_admin_login_account_key')) {
    function qes_admin_login_account_key(?array $user, string $identifier): string {
        if ($user !== null && !empty($user['id'])) {
            return 'user:' . (int)$user['id'];
        }
        return 'unknown:' . hash('sha256', strtolower(trim($identifier)));
    }
}

if (!function_exists('qes_process_admin_login')) {
    function qes_process_admin_login($conn, string $identifier, string $password, ?string $client_ip = null): array {
        $ip = $client_ip !== null ? $client_ip : qes_client_ip();
        $ip_key = 'ip:' . $ip;

        // 1. Check lenient per-IP cap (~20 fails / 15 min)
        $ip_check = qes_rate_limit_check('admin_login_ip', $ip_key, ADMIN_LOGIN_IP_MAX_ATTEMPTS, ADMIN_LOGIN_IP_LOCKOUT_SECONDS, $conn);
        if (!$ip_check['allowed']) {
            $mins = max(1, (int)ceil($ip_check['retry_after'] / 60));
            return [
                'success' => false,
                'user' => null,
                'error' => "Too many failed attempts from your IP address. Please try again in {$mins} minute(s).",
                'locked' => true,
                'retry_after' => $ip_check['retry_after'],
                'remaining' => 0,
                'lock_type' => 'ip'
            ];
        }

        // 2. Resolve identifier to user
        $user = qes_resolve_admin_user($conn, $identifier);
        $account_key = qes_admin_login_account_key($user, $identifier);

        // 3. Check account lock BEFORE password_verify (locks reject valid passwords too)
        $acct_check = qes_rate_limit_check('admin_login', $account_key, ADMIN_LOGIN_MAX_ATTEMPTS, ADMIN_LOGIN_LOCKOUT_SECONDS, $conn);
        if (!$acct_check['allowed']) {
            $mins = max(1, (int)ceil($acct_check['retry_after'] / 60));
            return [
                'success' => false,
                'user' => null,
                'error' => "Too many failed attempts. Please try again in {$mins} minute(s).",
                'locked' => true,
                'retry_after' => $acct_check['retry_after'],
                'remaining' => 0,
                'lock_type' => 'account'
            ];
        }

        // 4. Verify password
        $is_valid_pw = $user && !empty($user['password']) && password_verify($password, $user['password']);

        if ($is_valid_pw) {
            // Verify account status and admin privileges
            if (($user['status'] ?? '') !== 'approved') {
                return [
                    'success' => false,
                    'user' => null,
                    'error' => "Your account is " . htmlspecialchars($user['status'] ?? 'inactive') . ".",
                    'locked' => false,
                    'retry_after' => 0,
                    'remaining' => $acct_check['remaining'],
                    'lock_type' => ''
                ];
            }
            if (!in_array($user['role'] ?? '', ['admin', 'super_admin', 'main_admin'], true)) {
                return [
                    'success' => false,
                    'user' => null,
                    'error' => "Access denied. The Admin Portal is restricted to authorized administrators.",
                    'locked' => false,
                    'retry_after' => 0,
                    'remaining' => $acct_check['remaining'],
                    'lock_type' => ''
                ];
            }

            // Success clears the account counter ONLY (IP counter remains unaffected)
            qes_rate_limit_clear('admin_login', $account_key, $conn);

            return [
                'success' => true,
                'user' => $user,
                'error' => '',
                'locked' => false,
                'retry_after' => 0,
                'remaining' => ADMIN_LOGIN_MAX_ATTEMPTS,
                'lock_type' => ''
            ];
        }

        // 5. Invalid credentials (wrong password or nonexistent user)
        // Record failure on both account counter and per-IP counter
        $fails = qes_rate_limit_record_fail('admin_login', $account_key, ADMIN_LOGIN_MAX_ATTEMPTS, ADMIN_LOGIN_LOCKOUT_SECONDS, $conn);
        qes_rate_limit_record_fail('admin_login_ip', $ip_key, ADMIN_LOGIN_IP_MAX_ATTEMPTS, ADMIN_LOGIN_IP_LOCKOUT_SECONDS, $conn);

        $remaining = max(0, ADMIN_LOGIN_MAX_ATTEMPTS - $fails);

        if ($fails >= ADMIN_LOGIN_MAX_ATTEMPTS) {
            return [
                'success' => false,
                'user' => null,
                'error' => "Too many failed attempts. Please try again in 15 minute(s).",
                'locked' => true,
                'retry_after' => ADMIN_LOGIN_LOCKOUT_SECONDS,
                'remaining' => 0,
                'lock_type' => 'account'
            ];
        }

        // Uniform message regardless of whether user exists or not (prevents enumeration)
        return [
            'success' => false,
            'user' => null,
            'error' => "Invalid credentials. {$remaining} attempt(s) left before a 15-minute lockout.",
            'locked' => false,
            'retry_after' => 0,
            'remaining' => $remaining,
            'lock_type' => ''
        ];
    }
}

if (!function_exists('get_device_info')) {
    function get_device_info($ua) {
        $platform = 'Unknown Device';
        $device_type = 'desktop';
        $icon = 'fa-desktop';

        if (preg_match('/iphone/i', $ua)) {
            $platform = 'Apple iPhone';
            $device_type = 'mobile';
            $icon = 'fa-mobile-screen-button';
        } elseif (preg_match('/ipad/i', $ua)) {
            $platform = 'Apple iPad';
            $device_type = 'tablet';
            $icon = 'fa-tablet-screen-button';
        } elseif (preg_match('/android/i', $ua)) {
            $platform = 'Android Device';
            $device_type = 'mobile';
            $icon = 'fa-mobile-screen-button';
        } elseif (preg_match('/windows nt 10/i', $ua)) {
            $platform = 'Windows 10/11';
            $icon = 'fa-laptop';
        } elseif (preg_match('/windows/i', $ua)) {
            $platform = 'Windows PC';
            $icon = 'fa-laptop';
        } elseif (preg_match('/macintosh|mac os x/i', $ua)) {
            $platform = 'macOS (MacBook/iMac)';
            $icon = 'fa-laptop';
        } elseif (preg_match('/linux/i', $ua)) {
            $platform = 'Linux System';
            $icon = 'fa-laptop';
        }

        $browser = 'Web Browser';
        if (preg_match('/edg/i', $ua)) {
            $browser = 'Microsoft Edge';
        } elseif (preg_match('/chrome|crios/i', $ua) && !preg_match('/opr|opera/i', $ua)) {
            $browser = 'Google Chrome';
        } elseif (preg_match('/firefox|fxios/i', $ua)) {
            $browser = 'Mozilla Firefox';
        } elseif (preg_match('/safari/i', $ua) && !preg_match('/chrome|crios/i', $ua)) {
            $browser = 'Apple Safari';
        } elseif (preg_match('/opr|opera/i', $ua)) {
            $browser = 'Opera Browser';
        }

        return [
            'name' => "{$platform} • {$browser}",
            'type' => $device_type,
            'icon' => $icon
        ];
    }
}

if (!function_exists('get_user_online_status')) {
    function get_user_online_status($user) {
        $last_seen = !empty($user['last_seen_at']) ? strtotime($user['last_seen_at']) : 0;
        $last_logout = !empty($user['last_logout_at']) ? strtotime($user['last_logout_at']) : 0;
        $last_login = !empty($user['last_login_at']) ? strtotime($user['last_login_at']) : 0;

        $now = time();
        // If user explicitly logged out at or after their last activity
        if ($last_logout > 0 && $last_logout >= $last_seen) {
            return [
                'is_online' => false,
                'status_label' => 'Offline',
                'sub_label' => 'Logged out ' . date('M d, g:i A', $last_logout),
                'color' => '#6b7280',
                'bg' => '#f3f4f6'
            ];
        }

        // Active within the last 5 minutes (300 seconds)
        if ($last_seen > 0 && ($now - $last_seen) <= 300) {
            return [
                'is_online' => true,
                'status_label' => 'Online Now',
                'sub_label' => 'Active just now',
                'color' => '#16a34a',
                'bg' => '#dcfce7'
            ];
        }

        // Offline / Inactive
        if ($last_seen > 0) {
            $diff = $now - $last_seen;
            if ($diff < 3600) {
                $time_str = max(1, round($diff / 60)) . ' mins ago';
            } elseif ($diff < 86400) {
                $time_str = round($diff / 3600) . ' hours ago';
            } else {
                $time_str = date('M d, g:i A', $last_seen);
            }
            return [
                'is_online' => false,
                'status_label' => 'Offline',
                'sub_label' => 'Last seen ' . $time_str,
                'color' => '#6b7280',
                'bg' => '#f3f4f6'
            ];
        }

        return [
            'is_online' => false,
            'status_label' => 'Offline',
            'sub_label' => 'Never logged in',
            'color' => '#9ca3af',
            'bg' => '#f3f4f6'
        ];
    }
}

if (!function_exists('track_user_session')) {
    function track_user_session($conn, $user_id, $is_login = false) {
        if ($user_id <= 0) return;
        $session_id = session_id();
        if (empty($session_id)) return;

        // Throttle session updates: only ping DB on login or if > 60 seconds since last tracked
        $now = time();
        $last_tracked = $_SESSION['last_session_tracked_at'] ?? 0;
        if (!$is_login && ($now - $last_tracked) < 60) {
            return;
        }
        $_SESSION['last_session_tracked_at'] = $now;

        $ip = qes_client_ip();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Browser';
        $device = get_device_info($ua);
        $device_name = $device['name'];
        $device_type = $device['type'];

        // Update user's last_seen_at and last device / IP
        if ($is_login) {
            $u_stmt = $conn->prepare("UPDATE users SET last_login_at = NOW(), last_seen_at = NOW(), last_ip = ?, last_device = ? WHERE id = ?");
            if ($u_stmt) {
                $u_stmt->bind_param("ssi", $ip, $device_name, $user_id);
                $u_stmt->execute();
                $u_stmt->close();
            }
        } else {
            $u_stmt = $conn->prepare("UPDATE users SET last_seen_at = NOW(), last_ip = ?, last_device = ? WHERE id = ?");
            if ($u_stmt) {
                $u_stmt->bind_param("ssi", $ip, $device_name, $user_id);
                $u_stmt->execute();
                $u_stmt->close();
            }
        }

        // Check if this session already exists
        $chk = $conn->prepare("SELECT id FROM user_sessions WHERE user_id = ? AND session_id = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param("is", $user_id, $session_id);
            $chk->execute();
            $res = $chk->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $up = $conn->prepare("UPDATE user_sessions SET ip_address = ?, user_agent = ?, device_name = ?, device_type = ?, is_logged_out = 0, last_activity = NOW() WHERE id = ?");
                $id = (int)$row['id'];
                $up->bind_param("ssssi", $ip, $ua, $device_name, $device_type, $id);
                $up->execute();
                $up->close();
            } else {
                $ins = $conn->prepare("INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, device_name, device_type, is_blocked, is_logged_out, created_at, last_activity) VALUES (?, ?, ?, ?, ?, ?, 0, 0, NOW(), NOW())");
                $ins->bind_param("isssss", $user_id, $session_id, $ip, $ua, $device_name, $device_type);
                $ins->execute();
                $ins->close();
            }
            $chk->close();
        }
    }
}

if (!function_exists('is_current_session_blocked')) {
    function is_current_session_blocked($conn, $user_id) {
        if ($user_id <= 0) return false;
        $session_id = session_id();
        if (empty($session_id)) return false;

        // Throttle block check to once every 30 seconds
        $now = time();
        $last_check = $_SESSION['last_block_check_at'] ?? 0;
        if (($now - $last_check) < 30 && isset($_SESSION['is_device_blocked'])) {
            return (bool)$_SESSION['is_device_blocked'];
        }

        $stmt = $conn->prepare("SELECT is_blocked FROM user_sessions WHERE user_id = ? AND session_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("is", $user_id, $session_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $stmt->close();
                $blocked = ((int)$row['is_blocked'] === 1);
                $_SESSION['last_block_check_at'] = $now;
                $_SESSION['is_device_blocked'] = $blocked;
                return $blocked;
            }
            $stmt->close();
        }
        $_SESSION['last_block_check_at'] = $now;
        $_SESSION['is_device_blocked'] = false;
        return false;
    }
}

if (!function_exists('require_login')) {
    function require_login() {
        global $conn;
        if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin', 'main_admin'])) {
            header("Location: loginadmin.php");
            exit;
        }

        $uid = (int)($_SESSION['id'] ?? 0);
        if ($uid > 0 && isset($conn) && is_object($conn)) {
            if (is_current_session_blocked($conn, $uid)) {
                session_unset();
                session_destroy();
                header("Location: loginadmin.php?error=device_blocked");
                exit;
            }
            track_user_session($conn, $uid);
        }
    }
}

if (!function_exists('is_main_admin')) {
    function is_main_admin() {
        return isset($_SESSION['role']) && in_array($_SESSION['role'], ['main_admin', 'super_admin']);
    }
}

if (!function_exists('require_main_admin')) {
    function require_main_admin() {
        require_login();
        if (!is_main_admin()) {
            header("Location: a_home.php?error=unauthorized");
            exit;
        }
    }
}

// ============================================================
// SYSTEM CACHING & LOAD BALANCING INFRASTRUCTURE
// ============================================================
if (!class_exists('QesCache')) {
    class QesCache {
        private static array $memory = [];
        private static ?string $cache_dir = null;

        public static function getCacheDir(): string {
            if (self::$cache_dir === null) {
                $dir = __DIR__ . '/cache';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                if (!is_writable($dir)) {
                    $dir = sys_get_temp_dir() . '/tyoy_qes_cache';
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }
                }
                self::$cache_dir = $dir;
            }
            return self::$cache_dir;
        }

        public static function get(string $key, mixed $default = null): mixed {
            if (array_key_exists($key, self::$memory)) {
                return self::$memory[$key];
            }

            $file = self::getCacheDir() . '/' . md5($key) . '.cache';
            if (is_file($file)) {
                $content = @file_get_contents($file);
                if ($content !== false) {
                    $data = @unserialize($content);
                    if (is_array($data) && isset($data['exp'], $data['val'])) {
                        if ($data['exp'] === 0 || $data['exp'] >= time()) {
                            self::$memory[$key] = $data['val'];
                            return $data['val'];
                        } else {
                            @unlink($file);
                        }
                    }
                }
            }

            return $default;
        }

        public static function set(string $key, mixed $val, int $ttl = 300): bool {
            self::$memory[$key] = $val;
            $file = self::getCacheDir() . '/' . md5($key) . '.cache';
            $exp = $ttl > 0 ? (time() + $ttl) : 0;
            $payload = serialize(['exp' => $exp, 'val' => $val]);
            return (bool)@file_put_contents($file, $payload, LOCK_EX);
        }

        public static function remember(string $key, int $ttl, callable $callback): mixed {
            $val = self::get($key);
            if ($val !== null) {
                return $val;
            }
            $val = $callback();
            self::set($key, $val, $ttl);
            return $val;
        }

        public static function delete(string $key): bool {
            unset(self::$memory[$key]);
            $file = self::getCacheDir() . '/' . md5($key) . '.cache';
            if (is_file($file)) {
                return @unlink($file);
            }
            return true;
        }

        public static function flush(): bool {
            self::$memory = [];
            $dir = self::getCacheDir();
            if (is_dir($dir)) {
                $files = glob($dir . '/*.cache');
                if ($files) {
                    foreach ($files as $f) {
                        @unlink($f);
                    }
                }
            }
            return true;
        }
    }
}

if (!function_exists('qes_load_balancer_info')) {
    function qes_load_balancer_info(): array {
        $client_ip = function_exists('qes_client_ip') ? qes_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return [
            'node_id'     => gethostname() ?: ('node-' . substr(md5($_SERVER['SERVER_ADDR'] ?? 'localhost'), 0, 8)),
            'client_ip'   => $client_ip,
            'is_https'    => $is_https,
            'proto'       => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ($is_https ? 'https' : 'http'),
            'cached_keys' => count(glob(QesCache::getCacheDir() . '/*.cache') ?: [])
        ];
    }
}

if (!function_exists('get_setting')) {
    function get_setting($conn, $key, $default = '') {
        static $settings_cache = null;

        if ($key === '__invalidate_cache__') {
            $settings_cache = null;
            QesCache::delete('all_settings_map');
            QesCache::delete('packages_pricing_theme_party');
            QesCache::delete('packages_pricing_wedding');
            return '';
        }

        if ($settings_cache === null) {
            $cached = QesCache::get('all_settings_map');
            if (is_array($cached)) {
                $settings_cache = $cached;
            } else {
                $settings_cache = [];
                if (is_object($conn) && method_exists($conn, 'query')) {
                    try {
                        $res = $conn->query("SELECT setting_key, setting_value FROM settings");
                        if ($res) {
                            while ($row = $res->fetch_assoc()) {
                                $settings_cache[$row['setting_key']] = $row['setting_value'];
                            }
                        }
                        QesCache::set('all_settings_map', $settings_cache, 600);
                    } catch (\Throwable $e) {}
                }
            }
        }

        return $settings_cache[$key] ?? $default;
    }
}

if (!function_exists('set_setting')) {
    function set_setting($conn, $key, $value) {
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        if ($stmt) {
            $stmt->bind_param("ss", $key, $value);
            $success = $stmt->execute();
            $stmt->close();
            get_setting($conn, '__invalidate_cache__');
            return $success;
        }
        return false;
    }
}

// ============================================================
// CLOUDFLARE TURNSTILE CAPTCHA PROTECTION HELPERS
// ============================================================
if (!function_exists('qes_get_turnstile_site_key')) {
    function qes_get_turnstile_site_key($conn = null): string {
        if ($conn && function_exists('get_setting')) {
            $db_key = get_setting($conn, 'turnstile_site_key', '');
            if (!empty($db_key)) return trim((string)$db_key);
        }
        return defined('ENV_TURNSTILE_SITE_KEY') ? trim((string)ENV_TURNSTILE_SITE_KEY) : '1x00000000000000000000AA';
    }
}

if (!function_exists('qes_get_turnstile_secret_key')) {
    function qes_get_turnstile_secret_key($conn = null): string {
        if ($conn && function_exists('get_setting')) {
            $db_key = get_setting($conn, 'turnstile_secret_key', '');
            if (!empty($db_key)) return trim((string)$db_key);
        }
        return defined('ENV_TURNSTILE_SECRET_KEY') ? trim((string)ENV_TURNSTILE_SECRET_KEY) : '1x0000000000000000000000000000000AA';
    }
}

if (!function_exists('qes_is_turnstile_enabled')) {
    function qes_is_turnstile_enabled($conn = null): bool {
        if ($conn && function_exists('get_setting')) {
            $status = get_setting($conn, 'turnstile_enabled', '1');
            if ($status === '0' || $status === 'false') return false;
        }
        $site_key = qes_get_turnstile_site_key($conn);
        $secret_key = qes_get_turnstile_secret_key($conn);
        return (!empty($site_key) && !empty($secret_key));
    }
}

if (!function_exists('qes_verify_turnstile')) {
    function qes_verify_turnstile(?string $token, ?string $client_ip = null, $conn = null): array {
        if (!qes_is_turnstile_enabled($conn)) {
            return ['success' => true, 'error' => null];
        }

        $token = trim((string)$token);
        if (empty($token)) {
            return [
                'success' => false,
                'error' => 'Please complete the Cloudflare Turnstile security check.'
            ];
        }

        $secret_key = qes_get_turnstile_secret_key($conn);
        if (empty($secret_key)) {
            return ['success' => true, 'error' => null];
        }

        // Cloudflare official test dummy keys
        if ($secret_key === '1x0000000000000000000000000000000AA') {
            return ['success' => true, 'error' => null];
        }
        if ($secret_key === '2x0000000000000000000000000000000AA') {
            return [
                'success' => false,
                'error' => 'Cloudflare Turnstile test verification failed (always-fails test key).'
            ];
        }

        $post_data = [
            'secret'   => $secret_key,
            'response' => $token,
        ];
        if (!empty($client_ip)) {
            $post_data['remoteip'] = $client_ip;
        }

        $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
        $response = null;

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $response = curl_exec($ch);
            curl_close($ch);
        }

        if ($response === false || $response === null) {
            $opts = [
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                    'content' => http_build_query($post_data),
                    'timeout' => 10
                ]
            ];
            $context = stream_context_create($opts);
            $response = @file_get_contents($url, false, $context);
        }

        if ($response === false || empty($response)) {
            error_log('[QES Turnstile] Could not connect to Cloudflare verification API.');
            return [
                'success' => false,
                'error' => 'Unable to verify Cloudflare security challenge due to a network error. Please try again.'
            ];
        }

        $result = json_decode($response, true);
        if (!is_array($result) || empty($result['success'])) {
            $err_codes = isset($result['error-codes']) ? implode(', ', (array)$result['error-codes']) : 'invalid-input-response';
            error_log("[QES Turnstile] Verification failed: " . $err_codes);
            return [
                'success' => false,
                'error' => 'Security challenge validation failed. Please check the box again to continue.',
                'error_codes' => $result['error-codes'] ?? []
            ];
        }

        return ['success' => true, 'error' => null];
    }
}

// ============================================================
// DYNAMIC EVENT ADVANCE-NOTICE RULES & VALIDATION
// ============================================================
if (!function_exists('get_event_advance_notice_rule')) {
    function get_event_advance_notice_rule($event_type) {
        $type_raw = trim($event_type ?? '');
        $t = strtolower($type_raw);
        
        if (strpos($t, 'wedding') !== false) {
            return [
                'type'         => 'Weddings',
                'notice_label' => '6 months',
                'months'       => 6,
                'days'         => 0
            ];
        } elseif (strpos($t, 'kid') !== false || strpos($t, 'birthday') !== false) {
            return [
                'type'         => 'Kids Party',
                'notice_label' => '14 days',
                'months'       => 0,
                'days'         => 14
            ];
        } elseif (strpos($t, 'corporate') !== false) {
            return [
                'type'         => 'Corporate Events',
                'notice_label' => '14 days',
                'months'       => 0,
                'days'         => 14
            ];
        } elseif (strpos($t, 'debut') !== false) {
            return [
                'type'         => 'Debut & Others',
                'notice_label' => '14 days',
                'months'       => 0,
                'days'         => 14
            ];
        }

        $typeName = !empty($type_raw) ? $type_raw : 'Special Events';
        return [
            'type'         => $typeName,
            'notice_label' => '14 days',
            'months'       => 0,
            'days'         => 14
        ];
    }
}

if (!function_exists('calculate_earliest_event_date')) {
    function calculate_earliest_event_date($event_type, $from_date = null) {
        $rule = get_event_advance_notice_rule($event_type);
        $base = ($from_date instanceof DateTime) ? clone $from_date : new DateTime('today');
        $base->setTime(0, 0, 0);

        if ($rule['months'] > 0) {
            $curMonth = (int)$base->format('n');
            $targetMonth = (($curMonth - 1 + $rule['months']) % 12) + 1;
            $base->modify("+{$rule['months']} months");
            if ((int)$base->format('n') !== $targetMonth) {
                $base->modify('last day of previous month');
            }
        } elseif ($rule['days'] > 0) {
            $base->modify("+{$rule['days']} days");
        }
        return ['min_date' => $base, 'rule' => $rule];
    }
}

if (!function_exists('validate_event_booking_date')) {
    function validate_event_booking_date($event_type, $event_date) {
        $dateStr = trim($event_date ?? '');
        if (empty($dateStr)) {
            return ['valid' => false, 'message' => 'Event date is required.'];
        }
        $targetDate = DateTime::createFromFormat('Y-m-d', $dateStr);
        if (!$targetDate) {
            return ['valid' => false, 'message' => 'Invalid event date format (must be YYYY-MM-DD).'];
        }
        $targetDate->setTime(0, 0, 0);

        $calc = calculate_earliest_event_date($event_type);
        $minDate = $calc['min_date'];
        $rule = $calc['rule'];

        if ($targetDate < $minDate) {
            $earliestFormatted = $minDate->format('F j, Y');
            $selectedFormatted = $targetDate->format('F j, Y');
            return [
                'valid' => false,
                'min_date_str' => $minDate->format('Y-m-d'),
                'earliest_formatted' => $earliestFormatted,
                'rule' => $rule,
                'message' => "Advance Notice Violation: Selected date ({$selectedFormatted}) is earlier than the required {$rule['notice_label']} advance notice for {$rule['type']}. Earliest available date is {$earliestFormatted}."
            ];
        }

        return [
            'valid' => true,
            'min_date_str' => $minDate->format('Y-m-d'),
            'earliest_formatted' => $minDate->format('F j, Y'),
            'rule' => $rule
        ];
    }
}

if (!function_exists('check_booking_conflict')) {
    /**
     * Checks if a given event time range overlaps with an existing approved event.
     * Overlap rule: (event_start < target_end AND event_end > target_start)
     * Same date alone is NOT a conflict; only overlapping times are a conflict.
     *
     * @param mysqli|SupabaseConnection $conn
     * @param string $event_start Target event start datetime
     * @param string $event_end Target event end datetime
     * @param int|null $exclude_booking_id Optional booking ID to exclude from conflict check
     * @return array ['conflict' => bool, 'conflicting_event' => array|null, 'message' => string]
     */
    function check_booking_conflict($conn, $event_start, $event_end, $exclude_booking_id = null) {
        $exclude_id = !empty($exclude_booking_id) ? (int)$exclude_booking_id : 0;
        
        // Normalize datetime strings to standard 'YYYY-MM-DD HH:MM:SS' without timezone offsets (+00 / +08)
        $norm_start = substr(trim((string)$event_start), 0, 19);
        $norm_end   = substr(trim((string)$event_end), 0, 19);
        if (strlen($norm_start) < 19) {
            $norm_start = date('Y-m-d H:i:s', strtotime($event_start));
        }
        if (strlen($norm_end) < 19) {
            $norm_end = date('Y-m-d H:i:s', strtotime($event_end));
        }

        $sql = "SELECT id, reference_no, event_title, event_type, event_start, event_end, location_venue, status 
                FROM bookings 
                WHERE status = 'approved' 
                  AND id != ? 
                  AND (event_start < ? AND event_end > ?) 
                ORDER BY event_start ASC 
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            // FAIL CLOSED: If we can't query the database, assume conflict
            // to prevent double-booking. Never silently allow a booking
            // when we can't verify there's no overlap.
            error_log('[QES Booking] CRITICAL: check_booking_conflict query preparation failed: ' . $conn->error);
            return [
                'conflict' => true,
                'conflicting_event' => null,
                'message' => 'Unable to verify schedule availability due to a temporary database issue. Please try again in a moment.'
            ];
        }

        $stmt->bind_param("iss", $exclude_id, $norm_end, $norm_start);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $conflict = $result->fetch_assoc();
            $stmt->close();

            $c_raw_start = substr(trim((string)$conflict['event_start']), 0, 19);
            $c_raw_end   = substr(trim((string)$conflict['event_end']), 0, 19);
            $c_start_ts  = strtotime($c_raw_start);
            $c_end_ts    = strtotime($c_raw_end);
            $c_start_str = date('M j, Y g:i A', $c_start_ts);
            $c_end_str   = (date('Y-m-d', $c_start_ts) === date('Y-m-d', $c_end_ts)) 
                ? date('g:i A', $c_end_ts) 
                : date('M j, Y g:i A', $c_end_ts);

            $msg = "Schedule Conflict: Cannot approve this booking because its date and time overlap with an existing approved event \"" . 
                   $conflict['event_title'] . "\" (Ref: " . $conflict['reference_no'] . ") scheduled on " . 
                   $c_start_str . " – " . $c_end_str . ".";

            return [
                'conflict' => true,
                'conflicting_event' => $conflict,
                'message' => $msg
            ];
        }

        $stmt->close();
        return [
            'conflict' => false,
            'conflicting_event' => null,
            'message' => ''
        ];
    }
}


if (!function_exists('get_event_family')) {
    function get_event_family($event_type) {
        $t = strtolower(trim($event_type ?? ''));
        if (strpos($t, 'wedding') !== false) {
            return 'wedding';
        }
        return 'theme_party';
    }
}

if (!function_exists('create_booking_inquiry')) {
    /**
     * Unified Central Booking Service.
     * Single source of truth for creating bookings across Web Form, Chatbot, and Admin flows.
     * Ensures future updates to validations, reference generator, or notifications never break any booking channel.
     *
     * @param mysqli|SupabaseConnection $conn
     * @param array $params Booking parameters
     * @return array ['success' => bool, 'message' => string, 'booking_id' => int, 'reference_no' => string, ...]
     */
    function create_booking_inquiry($conn, array $params): array {
        $user_id          = !empty($params['user_id']) ? (int)$params['user_id'] : null;
        $client_name      = trim($params['client_name'] ?? '');
        $client_email     = trim($params['client_email'] ?? '');
        $client_phone     = trim($params['client_phone'] ?? '');
        $client_address   = trim($params['client_address'] ?? '');
        $event_title      = trim($params['event_title'] ?? '');
        $event_type       = trim($params['event_type'] ?? 'Theme Party');
        $event_date       = trim($params['event_date'] ?? '');
        $event_time       = trim($params['event_time'] ?? '10:00');
        $guest_count      = max(1, (int)($params['guest_count'] ?? 100));
        $location_venue   = trim($params['location_venue'] ?? '');
        $service_requirements = trim($params['service_requirements'] ?? 'Standard Event Styling');
        $source           = trim($params['source'] ?? 'online_inquiry');
        $raw_status       = $params['status'] ?? 'pending';
        $status           = in_array($raw_status, ['pending', 'approved', 'declined', 'cancelled'], true) ? $raw_status : 'pending';
        $check_conflict   = !empty($params['check_conflict']);
        $send_notice      = $params['send_notice'] ?? ($status === 'approved' ? 'approval' : 'inquiry');

        // 1. Required fields
        if (empty($client_name) || empty($client_phone) || empty($event_title) || empty($event_date) || empty($location_venue)) {
            return ['success' => false, 'message' => 'Please fill in all required fields (Name, Contact Number, Event Title, Date, and Venue).'];
        }

        // 2. Strict Email Validation (if provided or for online/chatbot sources)
        if (!empty($client_email)) {
            if (!filter_var($client_email, FILTER_VALIDATE_EMAIL) || !preg_match("/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/", $client_email)) {
                return ['success' => false, 'message' => 'Please enter a valid Email Address (e.g. name@gmail.com).'];
            }
        } elseif ($source !== 'manual_entry') {
            return ['success' => false, 'message' => 'Please enter a valid Email Address.'];
        }

        // 3. Strict Phone / Contact Number Validation (10 to 13 digits)
        $clean_phone = preg_replace('/[^0-9]/', '', $client_phone);
        if (preg_match('/[a-zA-Z]/', $client_phone) || strlen($clean_phone) < 10 || strlen($clean_phone) > 13) {
            return ['success' => false, 'message' => 'Please enter a valid Contact Number with 10 to 13 digits (e.g. 0917-123-4567).'];
        }

        // 4. Minimum Length Checks
        if (strlen($event_title) < 3) {
            return ['success' => false, 'message' => 'Event Title must be at least 3 characters.'];
        }
        if (strlen($location_venue) < 3) {
            return ['success' => false, 'message' => 'Please enter a valid Event Venue or Location.'];
        }

        // 5. Dynamic Lead-Time Validation per Event Type
        $dateValidation = validate_event_booking_date($event_type, $event_date);
        if (!$dateValidation['valid']) {
            return ['success' => false, 'message' => $dateValidation['message']];
        }

        // 6. Catering Capacity Check (if catering package selected, including add-ons)
        if (preg_match('/Catering.*?(\d+)\s*pax/i', $service_requirements, $cat_match)) {
            $catering_pax = (int)$cat_match[1];
            // Check for additional pax add-ons (e.g. +25 pax, extra 50 pax)
            if (preg_match_all('/(?:\+|extra|additional)\s*(\d+)\s*pax/i', $service_requirements, $extra_matches)) {
                foreach ($extra_matches[1] as $extra_pax) {
                    $catering_pax += (int)$extra_pax;
                }
            }
            if ($guest_count > $catering_pax) {
                return [
                    'success' => false,
                    'message' => "Cannot proceed: Expected guest count ({$guest_count}) exceeds your selected Catering Service package limit ({$catering_pax} pax). Please adjust your guest count or choose a higher package."
                ];
            }
        }

        // 7. Calculate start and end timestamps
        $event_start = date('Y-m-d H:i:s', strtotime("{$event_date} {$event_time}"));
        $event_end   = date('Y-m-d H:i:s', strtotime("{$event_date} {$event_time} + 5 hours"));

        // 8. Optional Conflict Check (e.g., for approved manual entries)
        if ($check_conflict) {
            $conflict = check_booking_conflict($conn, $event_start, $event_end);
            if ($conflict['conflict']) {
                return ['success' => false, 'message' => $conflict['message']];
            }
        }

        // 9. Collision-free Reference Number Generation
        $year = date('Y');
        $ref_prefix = !empty($params['ref_prefix']) ? $params['ref_prefix'] : "EV-{$year}-";
        $reference_no = '';
        $max_attempts = 10;
        for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
            $rand = str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $candidate = $ref_prefix . $rand;
            $check = $conn->prepare("SELECT id FROM bookings WHERE reference_no = ?");
            if ($check) {
                $check->bind_param("s", $candidate);
                $check->execute();
                $is_unique = ($check->get_result()->num_rows === 0);
                $check->close();
                if ($is_unique) {
                    $reference_no = $candidate;
                    break;
                }
            }
        }

        if (empty($reference_no)) {
            return ['success' => false, 'message' => 'Could not generate a unique reference number. Please try again.'];
        }

        // 10. PII Encryption
        $enc_client_name    = qes_encrypt($client_name, false);
        $enc_client_email   = !empty($client_email) ? qes_encrypt($client_email, true) : '';
        $enc_client_phone   = qes_encrypt($client_phone, true);
        $enc_client_address = !empty($client_address) ? qes_encrypt($client_address, false) : '';

        // 11. Database Insertion
        $stmt = $conn->prepare("INSERT INTO bookings (user_id, reference_no, client_name, client_email, client_phone, client_address, event_title, event_type, event_start, event_end, guest_count, location_venue, service_requirements, special_notes, status, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if (!$stmt) {
            error_log('[QES Booking] DB prepare error: ' . $conn->error);
            return ['success' => false, 'message' => 'A database error occurred while creating your booking. Please try again shortly.'];
        }

        $stmt->bind_param("issssssssissssss",
            $user_id,
            $reference_no,
            $enc_client_name,
            $enc_client_email,
            $enc_client_phone,
            $enc_client_address,
            $event_title,
            $event_type,
            $event_start,
            $event_end,
            $guest_count,
            $location_venue,
            $service_requirements,
            $special_notes,
            $status,
            $source
        );

        if (!$stmt->execute()) {
            error_log('[QES Booking] DB execute error: ' . $stmt->error);
            $stmt->close();
            return ['success' => false, 'message' => 'Could not save booking inquiry due to a database error. Please try again shortly.'];
        }

        $new_booking_id = (int)($stmt->insert_id ?? 0);
        if ($new_booking_id <= 0) {
            $new_booking_id = (int)($conn->insert_id ?? 0);
        }
        $stmt->close();

        if ($new_booking_id <= 0) {
            $chk = $conn->prepare("SELECT id FROM bookings WHERE reference_no = ? LIMIT 1");
            if ($chk) {
                $chk->bind_param("s", $reference_no);
                $chk->execute();
                $chk_res = $chk->get_result();
                if ($chk_res && ($row = $chk_res->fetch_assoc())) {
                    $new_booking_id = (int)($row['id'] ?? 0);
                }
                $chk->close();
            }
        }

        // 12. Dispatch Notifications (if enabled)
        if ($new_booking_id > 0 && !empty($send_notice) && $send_notice !== 'none') {
            require_once __DIR__ . '/notification_helper.php';
            try {
                if ($send_notice === 'approval' || $status === 'approved') {
                    NotificationHelper::sendApprovalNotice($conn, $new_booking_id);
                } elseif ($send_notice === 'inquiry' || $status === 'pending') {
                    NotificationHelper::sendInquiryReceivedNotice($conn, $new_booking_id);
                }
            } catch (\Throwable $e) {
                error_log("[QES Booking] Error dispatching notification: " . $e->getMessage());
            }
        }

        return [
            'success'      => true,
            'booking_id'   => $new_booking_id,
            'reference_no' => $reference_no,
            'client_name'  => $client_name,
            'event_title'  => $event_title,
            'event_date'   => date('F j, Y', strtotime($event_date)),
            'message'      => $status === 'approved' 
                ? "Booking '{$event_title}' (Ref: {$reference_no}) was approved and scheduled!" 
                : 'Booking request submitted successfully!'
        ];
    }
}

if (!function_exists('update_booking_status')) {
    /**
     * Unified Booking Status Transition Service.
     * Centralizes approval, rejection, and future status lifecycle transitions.
     * Enforces conflict checks, database updates, and client notifications consistently.
     *
     * @param mysqli|SupabaseConnection $conn
     * @param int $booking_id
     * @param string $action 'approve' | 'reject'
     * @param string|null $reason Optional rejection reason
     * @param bool $send_notice Whether to dispatch email notice
     * @return array ['success' => bool, 'message' => string, 'booking' => ?array]
     */
    function update_booking_status($conn, int $booking_id, string $action, ?string $reason = null, bool $send_notice = true): array {
        if ($booking_id <= 0) {
            return ['success' => false, 'message' => 'Invalid booking ID.', 'booking' => null];
        }

        $stmt = $conn->prepare("SELECT id, reference_no, event_title, event_type, event_start, event_end, status FROM bookings WHERE id = ?");
        if (!$stmt) {
            error_log('[QES Booking] update_booking_status prepare failed: ' . $conn->error);
            return ['success' => false, 'message' => 'A database error occurred. Please try again shortly.', 'booking' => null];
        }
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $target = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$target) {
            return ['success' => false, 'message' => "Booking #{$booking_id} was not found.", 'booking' => null];
        }

        $action = strtolower(trim($action));

        if ($action === 'approve') {
            // Server-side Conflict Check
            $conflict = check_booking_conflict($conn, $target['event_start'], $target['event_end'], $booking_id);
            if ($conflict['conflict']) {
                return ['success' => false, 'message' => $conflict['message'], 'conflict' => true, 'booking' => $target];
            }

            $up = $conn->prepare("UPDATE bookings SET status = 'approved', rejection_reason = NULL WHERE id = ?");
            if (!$up) {
                error_log('[QES Booking] approve prepare failed: ' . $conn->error);
                return ['success' => false, 'message' => 'A database error occurred. Please try again shortly.', 'booking' => $target];
            }
            $up->bind_param("i", $booking_id);
            $saved = $up->execute();
            $up->close();

            if (!$saved) {
                return ['success' => false, 'message' => "Failed to approve booking #{$booking_id}.", 'booking' => $target];
            }

            if ($send_notice) {
                require_once __DIR__ . '/notification_helper.php';
                try {
                    NotificationHelper::sendApprovalNotice($conn, $booking_id);
                } catch (\Throwable $e) {
                    error_log("[QES Booking] Error sending approval email: " . $e->getMessage());
                }
            }

            return [
                'success' => true,
                'message' => "Booking #{$booking_id} (" . htmlspecialchars($target['event_title']) . ") has been APPROVED and added to the Master Calendar. Approval notifications were dispatched.",
                'booking' => $target
            ];
        } elseif ($action === 'reject') {
            $reason_clean = !empty($reason) ? trim($reason) : 'Requested schedule or venue is unavailable.';
            $up = $conn->prepare("UPDATE bookings SET status = 'rejected', rejection_reason = ? WHERE id = ?");
            if (!$up) {
                error_log('[QES Booking] reject prepare failed: ' . $conn->error);
                return ['success' => false, 'message' => 'A database error occurred. Please try again shortly.', 'booking' => $target];
            }
            $up->bind_param("si", $reason_clean, $booking_id);
            $saved = $up->execute();
            $up->close();

            if (!$saved) {
                return ['success' => false, 'message' => "Failed to reject booking #{$booking_id}.", 'booking' => $target];
            }

            if ($send_notice) {
                require_once __DIR__ . '/notification_helper.php';
                try {
                    NotificationHelper::sendRejectionNotice($conn, $booking_id, $reason_clean);
                } catch (\Throwable $e) {
                    error_log("[QES Booking] Error sending rejection email: " . $e->getMessage());
                }
            }

            return [
                'success' => true,
                'message' => "Booking #{$booking_id} has been REJECTED. Rejection notice was dispatched to the client.",
                'booking' => $target
            ];
        }

        return ['success' => false, 'message' => "Unknown action '{$action}'.", 'booking' => $target];
    }
}

// ============================================================
// DYNAMIC PRICING & PACKAGES MANAGEMENT
// ============================================================
if (!function_exists('get_default_packages_pricing')) {
    function get_default_packages_pricing() {
        return [
            'theme_party' => [
                'catering' => [
                    'category_title' => 'Catering Service (Pax Capacity)',
                    'icon' => 'fa-utensils',
                    'package' => 'custom catering',
                    'type' => 'radio',
                    'field' => 'svc_catering',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'cat_75',  'label' => '75 pax',  'price' => 37000, 'value' => 'Catering: 75 pax'],
                        ['id' => 'cat_100', 'label' => '100 pax', 'price' => 49000, 'value' => 'Catering: 100 pax'],
                        ['id' => 'cat_150', 'label' => '150 pax', 'price' => 72000, 'value' => 'Catering: 150 pax'],
                        ['id' => 'cat_200', 'label' => '200 pax', 'price' => 96000, 'value' => 'Catering: 200 pax'],
                        ['id' => 'cat_300', 'label' => '300 pax', 'price' => 141000, 'value' => 'Catering: 300 pax'],
                        ['id' => 'cat_400', 'label' => '400 pax', 'price' => 188000, 'value' => 'Catering: 400 pax'],
                        ['id' => 'cat_500', 'label' => '500 pax', 'price' => 235000, 'value' => 'Catering: 500 pax'],
                    ]
                ],
                'catering_addons' => [
                    'category_title' => 'Catering Inclusions & Add-ons',
                    'icon' => 'fa-bowl-food',
                    'package' => 'catering',
                    'type' => 'checkbox',
                    'field' => 'svc_catering_addons[]',
                    'badge' => 'Pick Any',
                    'items' => [
                        ['id' => 'cat_add_f4', 'label' => 'Food: 4 main courses', 'price' => 0, 'value' => 'Food: 4 main courses'],
                        ['id' => 'cat_add_f5', 'label' => 'Food: 5 main courses', 'price' => 0, 'value' => 'Food: 5 main courses'],
                        ['id' => 'cat_add_f6', 'label' => 'Food: 6 main courses', 'price' => 0, 'value' => 'Food: 6 main courses'],
                        ['id' => 'cat_add_s_basic', 'label' => 'Styling: Basic', 'price' => 0, 'value' => 'Catering Presentation: Basic'],
                        ['id' => 'cat_add_s_med',   'label' => 'Styling: Medium', 'price' => 0, 'value' => 'Catering Presentation: Medium'],
                        ['id' => 'cat_add_s_prem',  'label' => 'Styling: Premium', 'price' => 0, 'value' => 'Catering Presentation: Premium'],
                    ]
                ],
                'styling' => [
                    'category_title' => 'Styling',
                    'icon' => 'fa-wand-magic-sparkles',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_styling',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'bd_basic',   'label' => 'Basic',                              'price' => 0,     'value' => 'Styling: Basic'],
                        ['id' => 'bd_reg',     'label' => 'Regular Package',                    'price' => 20000, 'value' => 'Styling: Regular Package'],
                        ['id' => 'bd_up_ceil', 'label' => 'Upgraded Backdrop With Ceiling',      'price' => 45000, 'value' => 'Styling: Upgraded Backdrop With Ceiling'],
                        ['id' => 'bd_up_prem', 'label' => 'Upgraded Premium Ceiling Treatment', 'price' => 60000, 'value' => 'Styling: Upgraded Premium Ceiling Treatment'],
                    ]
                ],
                'theme_styling' => [
                    'category_title' => 'Theme Party Styling',
                    'icon' => 'fa-wand-magic-sparkles',
                    'package' => 'theme_party',
                    'type' => 'radio',
                    'field' => 'svc_theme_styling',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'tp_little',  'label' => 'Little Celebration',                 'price' => 8500,  'value' => 'Theme Styling: Little Celebration'],
                        ['id' => 'tp_reg',     'label' => 'Regular Package',                    'price' => 15000, 'value' => 'Theme Styling: Regular Package'],
                        ['id' => 'tp_up_back', 'label' => 'Upgraded Backdrop Setup',             'price' => 25000, 'value' => 'Theme Styling: Upgraded Backdrop Setup'],
                        ['id' => 'tp_up_ceil', 'label' => 'Upgraded Backdrop With Ceiling',      'price' => 40000, 'value' => 'Theme Styling: Upgraded Backdrop With Ceiling'],
                    ]
                ],
                'character' => [
                    'category_title' => 'Character',
                    'icon' => 'fa-shapes',
                    'package' => 'theme_party',
                    'type' => 'radio',
                    'field' => 'svc_character',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'char_styro',   'label' => 'Styro Character 2D/3D',         'price' => 0, 'value' => 'Character: Styro Character 2D/3D'],
                        ['id' => 'char_printed', 'label' => 'Printed Styro Foam Character',  'price' => 0, 'value' => 'Character: Printed Styro Foam Character'],
                    ]
                ],
                'sound_lights' => [
                    'category_title' => 'Sound & Lights',
                    'icon' => 'fa-music',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_sound',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'snd_basic', 'label' => 'Basic',   'price' => 6500,  'value' => 'Sound & Lights: Basic'],
                        ['id' => 'snd_med',   'label' => 'Medium',  'price' => 8500,  'value' => 'Sound & Lights: Medium'],
                        ['id' => 'snd_prem',  'label' => 'Premium', 'price' => 15000, 'value' => 'Sound & Lights: Premium'],
                    ]
                ],
                'lightning' => [
                    'category_title' => 'Lightning Setup',
                    'icon' => 'fa-bolt',
                    'package' => 'theme_party',
                    'type' => 'radio',
                    'field' => 'svc_lightning',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'lt_basic', 'label' => 'Basic',   'price' => 0,    'value' => 'Lightning: Basic'],
                        ['id' => 'lt_med',   'label' => 'Medium',  'price' => 4000, 'value' => 'Lightning: Medium'],
                        ['id' => 'lt_prem',  'label' => 'Premium', 'price' => 6000, 'value' => 'Lightning: Premium'],
                    ]
                ],
                'ceiling' => [
                    'category_title' => 'Ceiling Treatment',
                    'icon' => 'fa-house-chimney',
                    'package' => 'theme_party',
                    'type' => 'radio',
                    'field' => 'svc_ceiling',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'ceil_basic', 'label' => 'Basic Ceiling Treatment (no trusses)', 'price' => 25000, 'value' => 'Ceiling: Basic Ceiling Treatment Only without trusses'],
                        ['id' => 'ceil_up',    'label' => 'Upgraded Ceiling Treatment with trusses', 'price' => 45000, 'value' => 'Ceiling: Upgraded Ceiling Treatment with trusses'],
                        ['id' => 'ceil_prem',  'label' => 'Upgraded Premium Ceiling Treatment', 'price' => 60000, 'value' => 'Ceiling: Upgraded Premium Ceiling Treatment'],
                    ]
                ],
                'entertainment' => [
                    'category_title' => 'Entertainment',
                    'icon' => 'fa-masks-theater',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_entertainment',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'ent_host', 'label' => 'Host',                          'price' => 5000,  'value' => 'Entertainment: Host'],
                        ['id' => 'ent_mag',  'label' => 'Magician',                      'price' => 5000,  'value' => 'Entertainment: Magician'],
                        ['id' => 'ent_hm',   'label' => 'Host & Magician',               'price' => 7500,  'value' => 'Entertainment: Host & Magician'],
                        ['id' => 'ent_full', 'label' => 'Host, Magician & Puppet Show',  'price' => 15000, 'value' => 'Entertainment: Host, Magician & Puppet Show'],
                    ]
                ],
                'food_carts' => [
                    'category_title' => 'Food Carts',
                    'icon' => 'fa-cart-flatbed',
                    'package' => 'custom',
                    'type' => 'checkbox',
                    'field' => 'svc_addons[]',
                    'badge' => 'Pick Any',
                    'items' => [
                        ['id' => 'fc_pop',     'label' => 'PopCorn Carts',                         'price' => 5000,  'value' => 'Food Cart: PopCorn Carts'],
                        ['id' => 'fc_hotdog',  'label' => 'Hotdog Carts',                          'price' => 5000,  'value' => 'Food Cart: Hotdog Carts'],
                        ['id' => 'fc_sweet',   'label' => 'Sweet Corner',                          'price' => 5000,  'value' => 'Food Cart: Sweet Corner'],
                        ['id' => 'fc_ice',     'label' => 'Ice Cream',                             'price' => 5000,  'value' => 'Food Cart: Ice Cream'],
                        ['id' => 'fc_fries',   'label' => 'Fries',                                 'price' => 5000,  'value' => 'Food Cart: Fries'],
                        ['id' => 'fc_nachos',  'label' => 'Nachos',                                'price' => 5000,  'value' => 'Food Cart: Nachos'],
                        ['id' => 'fc_donuts',  'label' => 'Donuts',                                'price' => 5000,  'value' => 'Food Cart: Donuts'],
                        ['id' => 'fc_grazing', 'label' => 'Grazing Table w/ Cold Cuts (100 pax)',  'price' => 10500, 'value' => 'Food Cart: Grazing Table with Cold Cuts (good for 100 pax)'],
                    ]
                ],
                'photobooth' => [
                    'category_title' => 'Photobooth',
                    'icon' => 'fa-camera-retro',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_photobooth',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'pb_basic',  'label' => 'Basic Paper Frame',  'price' => 3500,  'value' => 'Photobooth: Basic Paper Frame'],
                        ['id' => 'pb_magnet', 'label' => 'Premium Ref Magnet', 'price' => 4500,  'value' => 'Photobooth: Premium Ref Magnet'],
                        ['id' => 'pb_360',    'label' => 'Premium 360',        'price' => 12000, 'value' => 'Photobooth: Premium 360'],
                    ]
                ],
                'photo_video' => [
                    'category_title' => 'Photo & Video Coverage',
                    'icon' => 'fa-video',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_photo',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'pv_photo', 'label' => 'Photo Coverage',                  'price' => 4500,  'value' => 'Photo & Video: Photo Coverage'],
                        ['id' => 'pv_pv',    'label' => 'Photo & Video Coverage',          'price' => 12000, 'value' => 'Photo & Video: Photo & Video Coverage'],
                        ['id' => 'pv_mtv',   'label' => 'Photo & Video Coverage MTV Highlights', 'price' => 15000, 'value' => 'Photo & Video: MTV Highlights'],
                        ['id' => 'pv_sde',   'label' => 'Photo & Video Coverage Sameday Edit',   'price' => 35000, 'value' => 'Photo & Video: Sameday Edit'],
                    ]
                ],
                'otd' => [
                    'category_title' => 'OTD Coordination',
                    'icon' => 'fa-clipboard-list',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_otd',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'otd_basic', 'label' => 'Basic Package',   'price' => 10000, 'value' => 'OTD Coordination: Basic Package'],
                        ['id' => 'otd_prem',  'label' => 'Premium Package', 'price' => 15000, 'value' => 'OTD Coordination: Premium Package'],
                    ]
                ]
            ],
            'wedding' => [
                'catering' => [
                    'category_title' => 'Catering Service (Pax Capacity)',
                    'icon' => 'fa-utensils',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_catering',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_cat_75',  'label' => '75 pax',  'price' => 37000, 'value' => 'Catering: 75 pax'],
                        ['id' => 'w_cat_100', 'label' => '100 pax', 'price' => 49000, 'value' => 'Catering: 100 pax'],
                        ['id' => 'w_cat_150', 'label' => '150 pax', 'price' => 72000, 'value' => 'Catering: 150 pax'],
                        ['id' => 'w_cat_200', 'label' => '200 pax', 'price' => 96000, 'value' => 'Catering: 200 pax'],
                        ['id' => 'w_cat_300', 'label' => '300 pax', 'price' => 141000, 'value' => 'Catering: 300 pax'],
                        ['id' => 'w_cat_400', 'label' => '400 pax', 'price' => 188000, 'value' => 'Catering: 400 pax'],
                        ['id' => 'w_cat_500', 'label' => '500 pax', 'price' => 235000, 'value' => 'Catering: 500 pax'],
                    ]
                ],
                'catering_addons' => [
                    'category_title' => 'Catering Inclusions & Add-ons',
                    'icon' => 'fa-bowl-food',
                    'package' => 'catering',
                    'type' => 'checkbox',
                    'field' => 'svc_catering_addons[]',
                    'badge' => 'Pick Any',
                    'items' => [
                        ['id' => 'w_cat_add_f4', 'label' => 'Food: 4 main courses', 'price' => 0, 'value' => 'Food: 4 main courses'],
                        ['id' => 'w_cat_add_f5', 'label' => 'Food: 5 main courses', 'price' => 0, 'value' => 'Food: 5 main courses'],
                        ['id' => 'w_cat_add_f6', 'label' => 'Food: 6 main courses', 'price' => 0, 'value' => 'Food: 6 main courses'],
                        ['id' => 'w_cat_add_s_basic', 'label' => 'Styling: Basic Presentation', 'price' => 0, 'value' => 'Catering Presentation: Basic'],
                        ['id' => 'w_cat_add_s_prem',  'label' => 'Styling: Premium Presentation', 'price' => 0, 'value' => 'Catering Presentation: Premium'],
                    ]
                ],
                'styling' => [
                    'category_title' => 'Styling',
                    'icon' => 'fa-wand-magic-sparkles',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_styling',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_style_basic',   'label' => 'Basic',                              'price' => 0,     'value' => 'Styling: Basic'],
                        ['id' => 'w_style_reg',     'label' => 'Regular Package',                    'price' => 20000, 'value' => 'Styling: Regular Package'],
                        ['id' => 'w_style_up_ceil', 'label' => 'Upgraded Backdrop With Ceiling',      'price' => 45000, 'value' => 'Styling: Upgraded Backdrop With Ceiling'],
                        ['id' => 'w_style_up_prem', 'label' => 'Upgraded Premium Ceiling Treatment', 'price' => 60000, 'value' => 'Styling: Upgraded Premium Ceiling Treatment'],
                    ]
                ],
                'entourage_flower' => [
                    'category_title' => 'Entourage Flower',
                    'icon' => 'fa-spa',
                    'package' => 'custom styling_package',
                    'type' => 'radio',
                    'field' => 'svc_entourage_flower',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_ef_basic', 'label' => 'Basic',                         'price' => 10000, 'value' => 'Entourage Flower: Basic'],
                        ['id' => 'w_ef_reg',   'label' => 'Regular Package',               'price' => 12000, 'value' => 'Entourage Flower: Regular Package'],
                        ['id' => 'w_ef_mix',   'label' => 'Upgraded Mix Local & Imported', 'price' => 15000, 'value' => 'Entourage Flower: Upgraded Mix Local & Imported'],
                        ['id' => 'w_ef_imp',   'label' => 'Upgraded All Imported',         'price' => 17000, 'value' => 'Entourage Flower: Upgraded All Imported'],
                    ]
                ],
                'ceremony_styling' => [
                    'category_title' => 'Ceremony Styling',
                    'icon' => 'fa-church',
                    'package' => 'custom styling_package',
                    'type' => 'radio',
                    'field' => 'svc_ceremony_styling',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_cs_church',     'label' => 'Regular Church Styling',                   'price' => 17000, 'value' => 'Ceremony Styling: Regular Church Styling'],
                        ['id' => 'w_cs_garden',     'label' => 'Regular Garden Package',                   'price' => 20000, 'value' => 'Ceremony Styling: Regular Garden Package'],
                        ['id' => 'w_cs_mix_church', 'label' => 'Upgraded Mix Local & Imported (Church)',   'price' => 25000, 'value' => 'Ceremony Styling: Upgraded Mix Local & Imported (Church)'],
                        ['id' => 'w_cs_mix_garden', 'label' => 'Upgraded Mix Local & Imported (Garden)',   'price' => 25000, 'value' => 'Ceremony Styling: Upgraded Mix Local & Imported (Garden)'],
                    ]
                ],
                'sound_lights' => [
                    'category_title' => 'Sound & Lights',
                    'icon' => 'fa-music',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_sound',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_snd_basic', 'label' => 'Basic',   'price' => 6500,  'value' => 'Sound & Lights: Basic'],
                        ['id' => 'w_snd_med',   'label' => 'Medium',  'price' => 8500,  'value' => 'Sound & Lights: Medium'],
                        ['id' => 'w_snd_prem',  'label' => 'Premium', 'price' => 15000, 'value' => 'Sound & Lights: Premium'],
                    ]
                ],
                'entertainment' => [
                    'category_title' => 'Entertainment',
                    'icon' => 'fa-masks-theater',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_entertainment',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_ent_reg',  'label' => 'Regular Host',                          'price' => 5000,  'value' => 'Entertainment: Regular Host'],
                        ['id' => 'w_ent_ruth', 'label' => 'Professional Host "Ruth"',             'price' => 7500,  'value' => 'Entertainment: Professional Host "Ruth"'],
                        ['id' => 'w_ent_jen',  'label' => 'Popular & Professional Host "Jen"',     'price' => 25000, 'value' => 'Entertainment: Popular & Professional Host "Jen"'],
                        ['id' => 'w_ent_jam',  'label' => 'Host "Jam"',                           'price' => 60000, 'value' => 'Entertainment: Host "Jam"'],
                    ]
                ],
                'food_carts' => [
                    'category_title' => 'Food Carts',
                    'icon' => 'fa-cart-flatbed',
                    'package' => 'custom',
                    'type' => 'checkbox',
                    'field' => 'svc_addons[]',
                    'badge' => 'Pick Any',
                    'items' => [
                        ['id' => 'w_fc_pop',     'label' => 'PopCorn Carts',                         'price' => 5000,  'value' => 'Food Cart: PopCorn Carts'],
                        ['id' => 'w_fc_hotdog',  'label' => 'Hotdog Carts',                          'price' => 5000,  'value' => 'Food Cart: Hotdog Carts'],
                        ['id' => 'w_fc_sweet',   'label' => 'Sweet Corner',                          'price' => 5000,  'value' => 'Food Cart: Sweet Corner'],
                        ['id' => 'w_fc_ice',     'label' => 'Ice Cream',                             'price' => 5000,  'value' => 'Food Cart: Ice Cream'],
                        ['id' => 'w_fc_fries',   'label' => 'Fries',                                 'price' => 5000,  'value' => 'Food Cart: Fries'],
                        ['id' => 'w_fc_nachos',  'label' => 'Nachos',                                'price' => 5000,  'value' => 'Food Cart: Nachos'],
                        ['id' => 'w_fc_donuts',  'label' => 'Donuts',                                'price' => 5000,  'value' => 'Food Cart: Donuts'],
                        ['id' => 'w_fc_grazing', 'label' => 'Grazing Table w/ Cold Cuts (100 pax)',  'price' => 10500, 'value' => 'Food Cart: Grazing Table with Cold Cuts (good for 100 pax)'],
                    ]
                ],
                'photobooth' => [
                    'category_title' => 'Photobooth',
                    'icon' => 'fa-camera-retro',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_photobooth',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_pb_basic',  'label' => 'Basic Paper Frame',  'price' => 3500,  'value' => 'Photobooth: Basic Paper Frame'],
                        ['id' => 'w_pb_magnet', 'label' => 'Premium Ref Magnet', 'price' => 4500,  'value' => 'Photobooth: Premium Ref Magnet'],
                        ['id' => 'w_pb_360',    'label' => 'Premium 360',        'price' => 12000, 'value' => 'Photobooth: Premium 360'],
                    ]
                ],
                'photo_video' => [
                    'category_title' => 'Photo & Video Coverage',
                    'icon' => 'fa-video',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_photo',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_pv_photo', 'label' => 'Photo Coverage',                  'price' => 10000, 'value' => 'Photo & Video: Photo Coverage'],
                        ['id' => 'w_pv_pv',    'label' => 'Photo & Video Coverage',          'price' => 18000, 'value' => 'Photo & Video: Photo & Video Coverage'],
                        ['id' => 'w_pv_mtv',   'label' => 'MTV Highlights',                  'price' => 25000, 'value' => 'Photo & Video: MTV Highlights'],
                        ['id' => 'w_pv_sde',   'label' => 'Sameday Edit',                    'price' => 45000, 'value' => 'Photo & Video: Sameday Edit'],
                    ]
                ],
                'otd' => [
                    'category_title' => 'OTD Coordination & Planning',
                    'icon' => 'fa-clipboard-list',
                    'package' => 'custom planning_package',
                    'type' => 'radio',
                    'field' => 'svc_otd',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_otd_basic', 'label' => 'Basic Package',                 'price' => 25000, 'value' => 'OTD Coordination: Basic Package'],
                        ['id' => 'w_otd_full',  'label' => 'Full Planning & Coordination',  'price' => 35000, 'value' => 'OTD Coordination: Full Planning & Coordination'],
                    ]
                ],
                'partner_venue' => [
                    'category_title' => 'Partner Venue',
                    'icon' => 'fa-building',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_partner_venue',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_pv_emerald', 'label' => 'Emerald Courtyard', 'price' => 35000, 'value' => 'Partner Venue: Emerald Courtyard'],
                    ]
                ],
                'reception_styling' => [
                    'category_title' => 'Reception Styling',
                    'icon' => 'fa-champagne-glasses',
                    'package' => 'styling_package',
                    'type' => 'radio',
                    'field' => 'svc_reception_styling',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_rs_basic',   'label' => 'Basic',                                  'price' => 15000, 'value' => 'Reception Styling: Basic'],
                        ['id' => 'w_rs_reg',     'label' => 'Regular Package',                        'price' => 20000, 'value' => 'Reception Styling: Regular Package'],
                        ['id' => 'w_rs_up_ceil', 'label' => 'Upgraded Backdrop With Ceiling',          'price' => 65000, 'value' => 'Reception Styling: Upgraded Backdrop With Ceiling'],
                        ['id' => 'w_rs_up_prem', 'label' => 'Upgraded With Premium Ceiling Treatment', 'price' => 99000, 'value' => 'Reception Styling: Upgraded With Premium Ceiling Treatment'],
                    ]
                ],
                'ceiling_treatment' => [
                    'category_title' => 'Ceiling Treatment',
                    'icon' => 'fa-house-chimney',
                    'package' => 'styling_package',
                    'type' => 'radio',
                    'field' => 'svc_ceiling',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_ceil_no_truss', 'label' => 'Regular Package Without Trusses',         'price' => 25000, 'value' => 'Ceiling: Regular Package Without Trusses'],
                        ['id' => 'w_ceil_truss',    'label' => 'Regular Package With Trusses',            'price' => 45000, 'value' => 'Ceiling: Regular Package With Trusses'],
                        ['id' => 'w_ceil_up_truss', 'label' => 'Upgraded With Trusses',                    'price' => 70000, 'value' => 'Ceiling: Upgraded With Trusses'],
                        ['id' => 'w_ceil_up_prem',  'label' => 'Upgraded With Premium Ceiling Treatment', 'price' => 85000, 'value' => 'Ceiling: Upgraded With Premium Ceiling Treatment'],
                    ]
                ]
            ]
        ];
    }
}

if (!function_exists('get_packages_pricing')) {
    function get_packages_pricing($conn, $family = 'theme_party') {
        $cache_key = 'packages_pricing_' . $family;
        $cached = QesCache::get($cache_key);
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        $all_defaults = get_default_packages_pricing();
        if (!isset($all_defaults[$family])) {
            $family = 'theme_party';
        }
        $defaults = $all_defaults[$family];

        $raw = get_setting($conn, 'pricing_packages', '');
        if (empty($raw)) {
            QesCache::set($cache_key, $defaults, 300);
            return $defaults;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            QesCache::set($cache_key, $defaults, 300);
            return $defaults;
        }

        // Inline Migration: if settings.pricing_packages is NOT nested by family (old flat format),
        // wrap it as ['theme_party' => <old JSON>] so existing admin edits are not lost.
        if (!isset($data['theme_party']) && !isset($data['wedding'])) {
            $data = ['theme_party' => $data];
        }

        $family_data = $data[$family] ?? null;
        if (!is_array($family_data) || empty($family_data)) {
            QesCache::set($cache_key, $defaults, 300);
            return $defaults;
        }

        // Return saved family data with metadata fallbacks
        $result = [];
        foreach ($family_data as $cat_key => $cat_info) {
            $def_cat = $defaults[$cat_key] ?? [];
            $result[$cat_key] = [
                'category_title' => $cat_info['category_title'] ?? ($def_cat['category_title'] ?? ucfirst(str_replace('_', ' ', $cat_key))),
                'icon'           => $cat_info['icon'] ?? ($def_cat['icon'] ?? 'fa-circle-dot'),
                'package'        => $cat_info['package'] ?? ($def_cat['package'] ?? 'custom'),
                'type'           => $cat_info['type'] ?? ($def_cat['type'] ?? 'radio'),
                'field'          => $cat_info['field'] ?? ($def_cat['field'] ?? 'svc_' . $cat_key),
                'badge'          => $cat_info['badge'] ?? ($def_cat['badge'] ?? (($cat_info['type'] ?? '') === 'checkbox' ? 'Pick Any' : 'Pick One')),
                'items'          => []
            ];

            if (isset($cat_info['items']) && is_array($cat_info['items'])) {
                foreach ($cat_info['items'] as $item) {
                    if (!isset($item['label']) || trim($item['label']) === '') continue;
                    $id = !empty($item['id']) ? $item['id'] : 'pkg_' . substr(md5($item['label'] . microtime(true)), 0, 8);
                    $price = max(0, (int)($item['price'] ?? 0));
                    $value = !empty($item['value']) ? $item['value'] : ($result[$cat_key]['category_title'] . ': ' . $item['label']);
                    $result[$cat_key]['items'][] = [
                        'id'    => $id,
                        'label' => trim($item['label']),
                        'price' => $price,
                        'value' => $value
                    ];
                }
            }
        }

        QesCache::set($cache_key, $result, 300);
        return $result;
    }
}

if (!function_exists('save_packages_pricing')) {
    function save_packages_pricing($conn, $family, $pricing_data) {
        if (!in_array($family, ['theme_party', 'wedding'], true)) {
            return false;
        }
        if (!is_array($pricing_data)) {
            return false;
        }

        $raw = get_setting($conn, 'pricing_packages', '');
        $existing = !empty($raw) ? json_decode($raw, true) : [];
        if (!is_array($existing)) {
            $existing = [];
        }

        // If other family is not in database yet, initialize it from defaults
        $all_defaults = get_default_packages_pricing();
        $other_family = ($family === 'theme_party') ? 'wedding' : 'theme_party';
        if (!isset($existing[$other_family])) {
            $existing[$other_family] = $all_defaults[$other_family] ?? [];
        }

        $existing[$family] = $pricing_data;
        $json = json_encode($existing, JSON_UNESCAPED_UNICODE);
        $ok = set_setting($conn, 'pricing_packages', $json);
        if ($ok) {
            QesCache::delete('packages_pricing_theme_party');
            QesCache::delete('packages_pricing_wedding');
        }
        return $ok;
    }
}

if (!function_exists('add_package_item')) {
    function add_package_item($conn, $family, $cat_key, $label, $price) {
        $data = get_packages_pricing($conn, $family);
        if (!isset($data[$cat_key])) {
            return ['success' => false, 'message' => "Category '$cat_key' does not exist."];
        }

        $label = trim($label);
        if ($label === '') {
            return ['success' => false, 'message' => 'Package/Item name cannot be empty.'];
        }

        $clean_label = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($label));
        $unique_suffix = substr(md5(microtime(true) . $label), 0, 5);
        $new_id = substr($cat_key, 0, 4) . '_' . substr($clean_label, 0, 8) . '_' . $unique_suffix;

        $cat_title = $data[$cat_key]['category_title'] ?? $cat_key;
        $new_item = [
            'id'    => $new_id,
            'label' => $label,
            'price' => max(0, (int)$price),
            'value' => $cat_title . ': ' . $label
        ];

        $data[$cat_key]['items'][] = $new_item;
        $ok = save_packages_pricing($conn, $family, $data);
        return ['success' => $ok, 'item' => $new_item, 'message' => $ok ? 'Item created successfully!' : 'Failed to save to database.'];
    }
}

if (!function_exists('update_package_item')) {
    function update_package_item($conn, $family, $cat_key, $item_id, $new_label, $new_price, $target_cat = null) {
        $data = get_packages_pricing($conn, $family);
        if (!isset($data[$cat_key])) {
            return ['success' => false, 'message' => "Category '$cat_key' not found."];
        }

        $target_cat = $target_cat ?? $cat_key;
        if (!isset($data[$target_cat])) {
            $target_cat = $cat_key;
        }

        $found = false;
        $target_item = null;
        $target_index = -1;

        foreach ($data[$cat_key]['items'] as $idx => $it) {
            if ($it['id'] === $item_id) {
                $found = true;
                $target_item = $it;
                $target_index = $idx;
                break;
            }
        }

        if (!$found) {
            return ['success' => false, 'message' => 'Package/Item not found.'];
        }

        $target_item['label'] = trim($new_label);
        $target_item['price'] = max(0, (int)$new_price);
        $target_item['value'] = $data[$target_cat]['category_title'] . ': ' . $target_item['label'];

        if ($target_cat === $cat_key) {
            $data[$cat_key]['items'][$target_index] = $target_item;
        } else {
            // Moved to another category
            array_splice($data[$cat_key]['items'], $target_index, 1);
            $data[$target_cat]['items'][] = $target_item;
        }

        $ok = save_packages_pricing($conn, $family, $data);
        return ['success' => $ok, 'item' => $target_item, 'message' => $ok ? 'Item updated successfully!' : 'Failed to save changes.'];
    }
}

if (!function_exists('delete_package_item')) {
    function delete_package_item($conn, $family, $cat_key, $item_id) {
        $data = get_packages_pricing($conn, $family);
        if (!isset($data[$cat_key])) {
            return ['success' => false, 'message' => "Category '$cat_key' not found."];
        }

        $initial_count = count($data[$cat_key]['items']);
        $data[$cat_key]['items'] = array_values(array_filter($data[$cat_key]['items'], function($it) use ($item_id) {
            return $it['id'] !== $item_id;
        }));

        if (count($data[$cat_key]['items']) === $initial_count) {
            return ['success' => false, 'message' => 'Package/Item not found to delete.'];
        }

        $ok = save_packages_pricing($conn, $family, $data);
        return ['success' => $ok, 'message' => $ok ? 'Package/Item deleted successfully!' : 'Failed to delete item.'];
    }
}

if (!function_exists('add_package_category')) {
    function add_package_category($conn, $family, $title, $type = 'radio', $icon = 'fa-tag') {
        $data = get_packages_pricing($conn, $family);
        $title = trim($title);
        if ($title === '') {
            return ['success' => false, 'message' => 'Category title cannot be empty.'];
        }

        $cat_key = 'cat_' . preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(' ', '_', $title)));
        if (isset($data[$cat_key])) {
            $cat_key .= '_' . mt_rand(10, 99);
        }

        $field = ($type === 'checkbox') ? 'svc_' . $cat_key . '[]' : 'svc_' . $cat_key;
        $badge = ($type === 'checkbox') ? 'Pick Any' : 'Pick One';

        $data[$cat_key] = [
            'category_title' => $title,
            'icon'           => !empty($icon) ? $icon : 'fa-tag',
            'package'        => 'custom',
            'type'           => $type,
            'field'          => $field,
            'badge'          => $badge,
            'items'          => []
        ];

        $ok = save_packages_pricing($conn, $family, $data);
        return ['success' => $ok, 'cat_key' => $cat_key, 'message' => $ok ? 'New category added successfully!' : 'Failed to add category.'];
    }
}

if (!function_exists('delete_package_category')) {
    function delete_package_category($conn, $family, $cat_key) {
        $data = get_packages_pricing($conn, $family);
        if (!isset($data[$cat_key])) {
            return ['success' => false, 'message' => "Category '$cat_key' not found."];
        }

        unset($data[$cat_key]);
        $ok = save_packages_pricing($conn, $family, $data);
        return ['success' => $ok, 'message' => $ok ? 'Category and all its items deleted successfully!' : 'Failed to delete category.'];
    }
}

if (!function_exists('reset_family_pricing_to_default')) {
    function reset_family_pricing_to_default($conn, $family) {
        $all_defaults = get_default_packages_pricing();
        if (!isset($all_defaults[$family])) {
            return false;
        }
        return save_packages_pricing($conn, $family, $all_defaults[$family]);
    }
}
?>