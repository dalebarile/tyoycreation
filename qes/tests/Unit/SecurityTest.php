<?php
declare(strict_types=1);

namespace Qes\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qes\Tests\Fakes\FakeConnection;

class SecurityTest extends TestCase
{
    /**
     * @dataProvider redirectUrlProvider
     */
    public function test_open_redirect_sanitizer(string $input, bool $isDangerous): void
    {
        $sanitized = $input;
        if (preg_match('#^https?://|^//#i', $sanitized) || str_contains($sanitized, '://')) {
            $sanitized = 'loginadmin.php';
        }

        if ($isDangerous) {
            $this->assertSame('loginadmin.php', $sanitized, "Dangerous redirect '{$input}' should be blocked");
        } else {
            $this->assertSame($input, $sanitized, "Safe relative redirect '{$input}' should be preserved");
        }
    }

    public static function redirectUrlProvider(): array
    {
        return [
            ['a_home.php', false],
            ['index.php?registered=1', false],
            ['booking.php?event=wedding', false],
            ['https://evil-phishing-site.com', true],
            ['http://attacker.com', true],
            ['//attacker.com/fake-login', true],
            ['javascript:alert(1)', false], // Note: relative/header Location doesn't execute JS, but good to know
            ['ftp://attacker.com', true],
        ];
    }

    public function test_reference_number_format(): void
    {
        $year = date('Y');
        $rand = str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $reference_no = "EV-{$year}-{$rand}";

        $this->assertMatchesRegularExpression('/^EV-\d{4}-\d{4}$/', $reference_no);
    }

    public function test_rate_limiter_blocks_after_max_attempts(): void
    {
        $test_key = 'test_ip_user_' . bin2hex(random_bytes(6));
        qes_rate_limit_clear('test_action', $test_key);

        // First 4 attempts should be allowed
        for ($i = 1; $i <= 4; $i++) {
            $check = qes_rate_limit_check('test_action', $test_key, 5, 60);
            $this->assertTrue($check['allowed'], "Attempt {$i} should be allowed");
            qes_rate_limit_record_fail('test_action', $test_key, 5, 60);
        }

        // 5th attempt records failure and triggers lockout
        qes_rate_limit_record_fail('test_action', $test_key, 5, 60);
        $check = qes_rate_limit_check('test_action', $test_key, 5, 60);
        $this->assertFalse($check['allowed'], 'Should be blocked after 5 failed attempts');
        $this->assertStringContainsString('Too many failed attempts', $check['message']);

        // Clear should reset immediately
        qes_rate_limit_clear('test_action', $test_key);
        $check_after_clear = qes_rate_limit_check('test_action', $test_key, 5, 60);
        $this->assertTrue($check_after_clear['allowed'], 'Should be allowed after rate limit clear');
    }

    public function test_google_token_verifier_rejects_empty_and_invalid_tokens(): void
    {
        if (!defined('QES_TESTING')) {
            define('QES_TESTING', true);
        }
        require_once __DIR__ . '/../../auth_action.php';

        // Empty token
        $res1 = qes_verify_google_token('');
        $this->assertFalse($res1['valid']);
        $this->assertNotEmpty($res1['error']);

        // Malformed non-JWT token
        $res2 = qes_verify_google_token('invalid_non_jwt_token_string');
        $this->assertFalse($res2['valid']);
        $this->assertSame('Invalid Google token structure.', $res2['error']);

        // Mock test token
        $res3 = qes_verify_google_token('test_mock_token:alice@example.com');
        $this->assertTrue($res3['valid']);
        $this->assertSame('alice@example.com', $res3['email']);
    }

    public function test_role_authorization_helpers(): void
    {
        // When not logged in
        unset($_SESSION['role']);
        $this->assertFalse(is_main_admin());

        // When standard user
        $_SESSION['role'] = 'user';
        $this->assertFalse(is_main_admin());

        // When standard admin
        $_SESSION['role'] = 'admin';
        $this->assertFalse(is_main_admin());

        // When super_admin or main_admin
        $_SESSION['role'] = 'super_admin';
        $this->assertTrue(is_main_admin());

        $_SESSION['role'] = 'main_admin';
        $this->assertTrue(is_main_admin());

        // Cleanup
        unset($_SESSION['role']);
    }

    public function test_csrf_token_generator_is_secure(): void
    {
        $token = csrf_token();
        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token), 'CSRF token must be 64-character hex (32 bytes)');
        $this->assertSame($_SESSION['csrf_token'], $token);

        // Valid token passes hash_equals
        $this->assertTrue(hash_equals($_SESSION['csrf_token'], $token));

        // Forged or empty token fails hash_equals
        $this->assertFalse(hash_equals($_SESSION['csrf_token'], 'forged_csrf_token_value_attempt'));
        $this->assertFalse(hash_equals($_SESSION['csrf_token'], ''));
    }

    public function test_password_reset_code_is_six_digits(): void
    {
        $code = sprintf("%06d", random_int(100000, 999999));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertGreaterThanOrEqual(100000, (int)$code);
        $this->assertLessThanOrEqual(999999, (int)$code);
    }

    public function test_admin_login_four_fails_allowed_and_fifth_locks(): void
    {
        $conn = new FakeConnection();
        $ip = '10.0.1.' . random_int(1, 254);
        $test_user = 'test_usr_' . bin2hex(random_bytes(4));

        // Attempts 1 to 4 should be allowed with warning message
        for ($i = 1; $i <= 4; $i++) {
            $res = qes_process_admin_login($conn, $test_user, 'wrong_pass', $ip);
            $this->assertFalse($res['success'], "Attempt {$i} must not succeed");
            $this->assertFalse($res['locked'], "Attempt {$i} must not lock yet");
            $expected_remaining = 5 - $i;
            $this->assertSame($expected_remaining, $res['remaining'], "Remaining attempts mismatch on try {$i}");
            $this->assertSame("Invalid credentials. {$expected_remaining} attempt(s) left before a 15-minute lockout.", $res['error']);
        }

        // 5th failed attempt triggers lockout
        $res5 = qes_process_admin_login($conn, $test_user, 'wrong_pass', $ip);
        $this->assertFalse($res5['success']);
        $this->assertTrue($res5['locked'], '5th failed attempt must trigger lockout');
        $this->assertSame(0, $res5['remaining']);
        $this->assertGreaterThanOrEqual(890, $res5['retry_after']);
        $this->assertStringContainsString('Too many failed attempts', $res5['error']);
    }

    public function test_admin_login_lockout_expires_and_resets(): void
    {
        $test_key = 'test_decay_' . bin2hex(random_bytes(6));
        qes_rate_limit_clear('test_expire_action', $test_key);

        // Record a failure with a 1-second lockout window
        $fails = qes_rate_limit_record_fail('test_expire_action', $test_key, 1, 1);
        $this->assertSame(1, $fails);

        // Immediate check: locked out
        $check1 = qes_rate_limit_check('test_expire_action', $test_key, 1, 1);
        $this->assertFalse($check1['allowed'], 'Must be locked immediately after exceeding max attempts');
        $this->assertGreaterThan(0, $check1['retry_after']);

        // Sleep 2 seconds for decay window to elapse
        sleep(2);

        // Check after expiration: reset and allowed
        $check2 = qes_rate_limit_check('test_expire_action', $test_key, 1, 1);
        $this->assertTrue($check2['allowed'], 'Lockout must expire and allow new attempts after window elapses');
        $this->assertSame(1, $check2['remaining']);
    }

    public function test_admin_login_username_email_and_admin_alias_share_one_counter(): void
    {
        $admin_pw = password_hash('ComplexPass123!', PASSWORD_BCRYPT);
        $userData = [
            'id' => 77,
            'username' => 'super_admin_alex',
            'email' => 'alex@eventvista.com',
            'role' => 'admin',
            'status' => 'approved',
            'password' => $admin_pw
        ];

        $conn = new FakeConnection();
        $conn->addQueryResult(
            "SELECT * FROM users WHERE (email = ? OR username = ? OR (role IN ('admin', 'super_admin', 'main_admin') AND ? = 'admin')) LIMIT 1",
            [$userData]
        );

        $ip = '10.0.2.' . random_int(1, 254);
        qes_rate_limit_clear('admin_login', 'user:77');

        // 2 fails via username
        qes_process_admin_login($conn, 'super_admin_alex', 'badpass1', $ip);
        qes_process_admin_login($conn, 'super_admin_alex', 'badpass2', $ip);

        // 2 fails via email
        qes_process_admin_login($conn, 'alex@eventvista.com', 'badpass3', $ip);
        $res4 = qes_process_admin_login($conn, 'alex@eventvista.com', 'badpass4', $ip);
        $this->assertSame(1, $res4['remaining'], '4 total fails across username/email should leave 1 try');

        // 5th fail via the 'admin' alias keyword
        $res5 = qes_process_admin_login($conn, 'admin', 'badpass5', $ip);
        $this->assertTrue($res5['locked'], '5th fail via admin alias must lock the shared account');

        // Verify account is now locked when tried via username, email, AND admin alias
        $try_user = qes_process_admin_login($conn, 'super_admin_alex', 'badpass', $ip);
        $this->assertTrue($try_user['locked']);

        $try_email = qes_process_admin_login($conn, 'alex@eventvista.com', 'badpass', $ip);
        $this->assertTrue($try_email['locked']);

        $try_alias = qes_process_admin_login($conn, 'admin', 'badpass', $ip);
        $this->assertTrue($try_alias['locked']);

        // Cleanup
        qes_rate_limit_clear('admin_login', 'user:77');
    }

    public function test_admin_login_per_ip_cap_works(): void
    {
        $conn = new FakeConnection();
        $attacker_ip = '198.51.100.77';
        qes_rate_limit_clear('admin_login_ip', 'ip:' . $attacker_ip);

        // Simulate 20 attempts from the same IP using rotating unknown usernames
        for ($i = 1; $i <= 20; $i++) {
            $user_variant = 'victim_' . $i . '_' . bin2hex(random_bytes(3));
            qes_process_admin_login($conn, $user_variant, 'guess_pass', $attacker_ip);
        }

        // 21st attempt from this IP must be blocked by the IP cap, even with a fresh identifier
        $fresh_user = 'brand_new_target_' . bin2hex(random_bytes(3));
        $res_blocked = qes_process_admin_login($conn, $fresh_user, 'guess_pass', $attacker_ip);

        $this->assertTrue($res_blocked['locked'], 'IP limit must lock out after 20 failed attempts');
        $this->assertSame('ip', $res_blocked['lock_type']);
        $this->assertStringContainsString('Too many failed attempts from your IP address', $res_blocked['error']);

        // Cleanup
        qes_rate_limit_clear('admin_login_ip', 'ip:' . $attacker_ip);
    }

    public function test_admin_login_success_clears_counter_only(): void
    {
        $valid_password = 'CorrectAdminSecret#99';
        $admin_pw_hash = password_hash($valid_password, PASSWORD_BCRYPT);
        $userData = [
            'id' => 88,
            'username' => 'cleartest_admin',
            'email' => 'cleartest@eventvista.com',
            'role' => 'admin',
            'status' => 'approved',
            'password' => $admin_pw_hash
        ];

        $conn = new FakeConnection();
        $conn->addQueryResult(
            "SELECT * FROM users WHERE (email = ? OR username = ? OR (role IN ('admin', 'super_admin', 'main_admin') AND ? = 'admin')) LIMIT 1",
            [$userData]
        );

        $ip = '10.0.3.' . random_int(1, 254);
        qes_rate_limit_clear('admin_login', 'user:88');
        qes_rate_limit_clear('admin_login_ip', 'ip:' . $ip);

        // Fail 3 times
        for ($i = 1; $i <= 3; $i++) {
            qes_process_admin_login($conn, 'cleartest_admin', 'wrong_pass', $ip);
        }

        // Verify account counter currently has 3 fails (2 remaining)
        $mid_check = qes_rate_limit_check('admin_login', 'user:88');
        $this->assertSame(2, $mid_check['remaining']);

        // Successful login with correct password
        $success_res = qes_process_admin_login($conn, 'cleartest_admin', $valid_password, $ip);
        $this->assertTrue($success_res['success']);

        // Account counter must now be cleared (5 remaining)
        $after_check = qes_rate_limit_check('admin_login', 'user:88');
        $this->assertTrue($after_check['allowed']);
        $this->assertSame(5, $after_check['remaining']);

        // IP counter must NOT be cleared (must retain its recorded failure count)
        $ip_check = qes_rate_limit_check('admin_login_ip', 'ip:' . $ip, 20, 900);
        $this->assertSame(17, $ip_check['remaining'], 'IP counter must NOT be reset on account login success');

        // Cleanup
        qes_rate_limit_clear('admin_login', 'user:88');
        qes_rate_limit_clear('admin_login_ip', 'ip:' . $ip);
    }

    public function test_admin_login_correct_password_during_lockout_is_rejected(): void
    {
        $valid_password = 'KnownSecretPassword#1';
        $admin_pw_hash = password_hash($valid_password, PASSWORD_BCRYPT);
        $userData = [
            'id' => 99,
            'username' => 'locked_admin',
            'email' => 'locked@eventvista.com',
            'role' => 'admin',
            'status' => 'approved',
            'password' => $admin_pw_hash
        ];

        $conn = new FakeConnection();
        $conn->addQueryResult(
            "SELECT * FROM users WHERE (email = ? OR username = ? OR (role IN ('admin', 'super_admin', 'main_admin') AND ? = 'admin')) LIMIT 1",
            [$userData]
        );

        $ip = '10.0.4.' . random_int(1, 254);
        qes_rate_limit_clear('admin_login', 'user:99');

        // Trigger account lockout with 5 wrong password attempts
        for ($i = 1; $i <= 5; $i++) {
            qes_process_admin_login($conn, 'locked_admin', 'bad_pass', $ip);
        }

        // Now attempt login with the CORRECT password while locked
        $res = qes_process_admin_login($conn, 'locked_admin', $valid_password, $ip);

        // Must still be rejected!
        $this->assertFalse($res['success'], 'Login with correct password must be rejected during active lockout');
        $this->assertTrue($res['locked'], 'Result must report locked state');
        $this->assertStringContainsString('Too many failed attempts', $res['error']);

        // Cleanup
        qes_rate_limit_clear('admin_login', 'user:99');
    }

    public function test_client_ip_detection_with_trusted_proxies(): void
    {
        // 1. Without trusted proxies: REMOTE_ADDR is strictly returned
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.5';
        $this->assertSame('203.0.113.10', qes_client_ip([]), 'Untrusted proxy header must be ignored');

        // 2. With trusted proxy matching REMOTE_ADDR: Forwarded IP is used
        $trusted = ['203.0.113.10'];
        $this->assertSame('198.51.100.5', qes_client_ip($trusted), 'Trusted proxy X-Forwarded-For should be accepted');

        // 3. Invalid IP format in forwarded header falls back to REMOTE_ADDR
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'malicious<script>alert(1)</script>';
        $this->assertSame('203.0.113.10', qes_client_ip($trusted), 'Invalid IP in forwarded header must fall back to REMOTE_ADDR');

        // Cleanup
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    public function test_db_failure_falls_back_to_file_store(): void
    {
        $broken_conn = new \stdClass();
        $broken_conn->connect_error = 'Database offline or query failure';

        $key = 'test_fallback_' . bin2hex(random_bytes(4));
        qes_rate_limit_clear('test_fallback_action', $key, $broken_conn);

        $check = qes_rate_limit_check('test_fallback_action', $key, 5, 60, $broken_conn);
        $this->assertTrue($check['allowed'], 'Should allow request via file fallback when DB connection fails');
        $this->assertSame(5, $check['remaining']);

        $fails = qes_rate_limit_record_fail('test_fallback_action', $key, 5, 60, $broken_conn);
        $this->assertSame(1, $fails, 'Should record failure via file fallback when DB connection fails');

        // Cleanup
        qes_rate_limit_clear('test_fallback_action', $key, $broken_conn);
    }
}

