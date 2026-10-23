<?php
/**
 * Smoke test — proves PHPUnit is wired up and the Fakes are loadable.
 */

declare(strict_types=1);

namespace Qes\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qes\Tests\Fakes\FakeConnection;
use Qes\Tests\Fakes\FakeStatement;
use Qes\Tests\Fakes\FakeResult;

class SmokeTest extends TestCase
{
    public function test_phpunit_is_working(): void
    {
        $this->assertTrue(true, 'PHPUnit runs successfully');
    }

    public function test_timezone_is_manila(): void
    {
        $this->assertSame('Asia/Manila', date_default_timezone_get());
    }

    public function test_fake_connection_returns_preloaded_rows(): void
    {
        $conn = new FakeConnection();
        $conn->addQueryResult(
            "SELECT id, name FROM users WHERE id = ?",
            [['id' => 1, 'name' => 'Alice']]
        );

        $stmt = $conn->prepare("SELECT id, name FROM users WHERE id = ?");
        $this->assertNotFalse($stmt);

        $id = 1;
        $stmt->bind_param('i', $id);
        $stmt->execute();

        $result = $stmt->get_result();
        $this->assertSame(1, $result->num_rows);

        $row = $result->fetch_assoc();
        $this->assertSame('Alice', $row['name']);

        // Second fetch returns null
        $this->assertNull($result->fetch_assoc());
    }

    public function test_fake_connection_returns_empty_for_unknown_query(): void
    {
        $conn = new FakeConnection();
        $stmt = $conn->prepare("SELECT * FROM unknown_table");
        $stmt->execute();
        $result = $stmt->get_result();
        $this->assertSame(0, $result->num_rows);
    }

    public function test_all_core_php_files_pass_syntax_lint(): void
    {
        $baseDir = dirname(__DIR__, 2);
        $filesToLint = [
            $baseDir . '/chatbot.php',
            $baseDir . '/booking.php',
            $baseDir . '/db.php',
            $baseDir . '/index.php',
            $baseDir . '/loginadmin.php',
            $baseDir . '/login.php',
            $baseDir . '/admin_sidebar.php',
            $baseDir . '/booking_verification_helper.php',
            $baseDir . '/notification_helper.php',
            $baseDir . '/src/ChatbotBusinessKnowledge.php',
            $baseDir . '/a_manual_booking.php',
            $baseDir . '/a_sitemanager.php',
            $baseDir . '/a_packages.php'
        ];

        $phpBinary = defined('PHP_BINARY') && !empty(PHP_BINARY) ? PHP_BINARY : 'php';
        foreach ($filesToLint as $file) {
            $this->assertFileExists($file, "Target file for syntax lint must exist: {$file}");
            $output = [];
            $exitCode = 0;
            exec(escapeshellarg($phpBinary) . " -l " . escapeshellarg($file) . " 2>&1", $output, $exitCode);
            $msg = implode("\n", $output);
            $this->assertSame(0, $exitCode, "Syntax error detected in {$file}:\n{$msg}");
        }
    }

    public function test_site_manager_and_packages_control_robustness(): void
    {
        $baseDir = dirname(__DIR__, 2);
        $siteManagerContent = file_get_contents($baseDir . '/a_sitemanager.php');
        $this->assertStringContainsString("set_setting(\$conn, \$k, \$v);", $siteManagerContent);
        $this->assertStringContainsString("set_setting(\$conn, 'portfolio_items'", $siteManagerContent);
        $this->assertStringNotContainsString("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?", $siteManagerContent);

        $packagesContent = file_get_contents($baseDir . '/a_packages.php');
        $this->assertStringContainsString("data-cat=", $packagesContent);
        $this->assertStringContainsString("openEditItemModalFromBtn", $packagesContent);
        $this->assertStringContainsString("confirmDeleteItemFromBtn", $packagesContent);
    }

    public function test_speed_engine_and_preconnects_present(): void
    {
        $baseDir = dirname(__DIR__, 2);
        
        $loginAdminContent = file_get_contents($baseDir . '/loginadmin.php');
        $this->assertStringContainsString('rel="preconnect" href="https://fonts.googleapis.com"', $loginAdminContent);
        $this->assertStringContainsString('assets/app_speed.js', $loginAdminContent);

        $appSpeedContent = file_get_contents($baseDir . '/assets/app_speed.js');
        $this->assertStringContainsString('a_events.php?status=pending', $appSpeedContent);
        $this->assertStringContainsString('a_events.php?status=bookings', $appSpeedContent);
        $this->assertStringContainsString('startQesProgress', $appSpeedContent);

        $indexContent = file_get_contents($baseDir . '/index.php');
        $this->assertStringContainsString('startQesProgress', $indexContent);
    }

    public function test_navbar_logout_contrast_and_booking_summary_scrollbar(): void
    {
        $baseDir = dirname(__DIR__, 2);

        $indexContent = file_get_contents($baseDir . '/index.php');
        $this->assertStringContainsString('class="client-logout-nav"', $indexContent);
        $this->assertStringContainsString('color: #cbd5e1', $indexContent);

        $bookingContent = file_get_contents($baseDir . '/booking.php');
        $this->assertStringContainsString('.summary-card-sidebar::-webkit-scrollbar', $bookingContent);
        $this->assertStringContainsString('scrollbar-width: thin', $bookingContent);
        $this->assertStringContainsString('rel="preconnect" href="https://fonts.googleapis.com"', $bookingContent);
    }
}
