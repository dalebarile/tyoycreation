<?php
/**
 * TestCleanupHelper
 *
 * Automatically purges and rolls back testing sample data from the database,
 * session storage, and filesystem so that test records never stay in the system.
 */

declare(strict_types=1);

class TestCleanupHelper
{
    /**
     * Purges all test data from the database, sessions, and temp directories.
     *
     * @param mysqli|SupabaseConnection|null $conn
     * @return array Summary of purged records
     */
    public static function purgeAllTestData($conn = null): array
    {
        global $conn;
        $dbConn = $conn;

        $results = [
            'notifications_purged' => 0,
            'bookings_purged'      => 0,
            'rate_limits_purged'   => 0,
            'session_cleared'      => false,
            'temp_files_removed'   => 0
        ];

        // 1. Purge Session Test State
        if (session_status() === PHP_SESSION_ACTIVE || (session_status() === PHP_SESSION_NONE && !headers_sent() && @session_start())) {
            if (isset($_SESSION['pending_booking_verification'])) {
                $email = $_SESSION['pending_booking_verification']['email'] ?? '';
                if (str_contains($email, 'example.com') || str_contains($email, 'test') || str_contains($email, 'gmai.com')) {
                    unset($_SESSION['pending_booking_verification']);
                    $results['session_cleared'] = true;
                }
            }
        }

        if (!$dbConn || !is_object($dbConn)) {
            return $results;
        }

        // Avoid running queries if $dbConn is a FakeConnection that does not support direct deletion
        $className = get_class($dbConn);
        if (str_contains($className, 'FakeConnection')) {
            return $results;
        }

        // 2. Purge Test Notifications
        try {
            $res = $dbConn->query("SELECT id, subject, recipient_contact FROM notifications ORDER BY id DESC LIMIT 100");
            if ($res) {
                $idsToDelete = [];
                while ($row = $res->fetch_assoc()) {
                    $subj = $row['subject'] ?? '';
                    $contact = function_exists('qes_decrypt') ? (qes_decrypt($row['recipient_contact']) ?: $row['recipient_contact']) : ($row['recipient_contact'] ?? '');
                    if (
                        str_contains(strtolower($subj), 'test') ||
                        str_contains(strtolower($subj), 'phpunit') ||
                        str_ends_with(strtolower($contact), '@example.com') ||
                        str_ends_with(strtolower($contact), '@test.com')
                    ) {
                        $idsToDelete[] = (int)$row['id'];
                    }
                }
                foreach ($idsToDelete as $notifId) {
                    $delStmt = $dbConn->prepare("DELETE FROM notifications WHERE id = ?");
                    if ($delStmt) {
                        $delStmt->bind_param("i", $notifId);
                        $delStmt->execute();
                        $results['notifications_purged']++;
                        $delStmt->close();
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[TestCleanupHelper] Error purging notifications: ' . $e->getMessage());
        }

        // 3. Purge Test Bookings
        try {
            $res = $dbConn->query("SELECT id, reference_no, event_title, client_name, client_email FROM bookings ORDER BY id DESC LIMIT 100");
            if ($res) {
                $bookingIdsToDelete = [];
                while ($row = $res->fetch_assoc()) {
                    $ref = $row['reference_no'] ?? '';
                    $title = strtolower($row['event_title'] ?? '');
                    $client = strtolower(function_exists('qes_decrypt') ? (qes_decrypt($row['client_name']) ?: $row['client_name']) : ($row['client_name'] ?? ''));
                    $email = strtolower(function_exists('qes_decrypt') ? (qes_decrypt($row['client_email']) ?: $row['client_email']) : ($row['client_email'] ?? ''));

                    if (
                        str_starts_with($ref, 'EV-TEST-') ||
                        $ref === 'EV-2026-3212' ||
                        $title === 'test' ||
                        str_contains($title, 'phpunit test') ||
                        $client === 'test' ||
                        str_ends_with($email, '@example.com') ||
                        str_ends_with($email, '@test.com')
                    ) {
                        $bookingIdsToDelete[] = (int)$row['id'];
                    }
                }
                foreach ($bookingIdsToDelete as $bId) {
                    $bDel = $dbConn->prepare("DELETE FROM bookings WHERE id = ?");
                    if ($bDel) {
                        $bDel->bind_param("i", $bId);
                        $bDel->execute();
                        $results['bookings_purged']++;
                        $bDel->close();
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[TestCleanupHelper] Error purging bookings: ' . $e->getMessage());
        }

        // 4. Purge Rate-Limiter Test Keys
        try {
            $res = $dbConn->query("SELECT key_hash, scope FROM login_attempts LIMIT 100");
            if ($res) {
                $keysToDelete = [];
                while ($row = $res->fetch_assoc()) {
                    $scope = $row['scope'] ?? '';
                    if (str_starts_with($scope, 'test_') || $scope === 'test_action' || $scope === 'test_expire_action') {
                        $keysToDelete[] = $row['key_hash'];
                    }
                }
                foreach ($keysToDelete as $kHash) {
                    $delStmt = $dbConn->prepare("DELETE FROM login_attempts WHERE key_hash = ?");
                    if ($delStmt) {
                        $delStmt->bind_param("s", $kHash);
                        $delStmt->execute();
                        $results['rate_limits_purged']++;
                        $delStmt->close();
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[TestCleanupHelper] Error purging login_attempts: ' . $e->getMessage());
        }

        // 5. Purge Any Diagnostic Scratch Scripts in Artifact Scratch Directory
        try {
            $scratchDir = dirname(__DIR__) . '/scratch';
            if (is_dir($scratchDir)) {
                $files = glob($scratchDir . '/*.php');
                if ($files) {
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            @unlink($file);
                            $results['temp_files_removed']++;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[TestCleanupHelper] Error purging scratch files: ' . $e->getMessage());
        }

        return $results;
    }
}
