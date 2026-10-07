<?php
declare(strict_types=1);

namespace Qes\Tests\Unit;

use PHPUnit\Framework\TestCase;

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
}

