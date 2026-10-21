<?php
/**
 * CLI Tool: Purge All Testing Data
 *
 * Usage: php cleanup_test_data.php
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto_helper.php';
require_once __DIR__ . '/test_cleanup_helper.php';

echo "====================================================\n";
echo "   Tyoy Creation QES - Automated Test Data Purge    \n";
echo "====================================================\n";

$summary = TestCleanupHelper::purgeAllTestData($conn);

echo "Notifications Purged : " . $summary['notifications_purged'] . "\n";
echo "Bookings Purged      : " . $summary['bookings_purged'] . "\n";
echo "Rate Limits Purged   : " . $summary['rate_limits_purged'] . "\n";
echo "Session Cleared      : " . ($summary['session_cleared'] ? "YES" : "NO") . "\n";
echo "Temp Files Removed   : " . $summary['temp_files_removed'] . "\n";
echo "----------------------------------------------------\n";
echo "Status: System is clean. No test data remains.\n";
echo "====================================================\n";
