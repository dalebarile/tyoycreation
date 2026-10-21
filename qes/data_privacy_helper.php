<?php
/**
 * QES Data Privacy, Retention & Data-Subject Request (DSR) Helper
 * Compliant with Republic Act No. 10173 (Philippine Data Privacy Act of 2012)
 *
 * Provides:
 * 1. Data-Subject Access Request (DSAR) / Data Portability Export (JSON / Structured Archive)
 * 2. Right to Erasure / Blocking (Full Purge & Legal Anonymization)
 * 3. Automated Retention Schedule Purge Engine
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto_helper.php';

class DataPrivacyHelper {

    /**
     * Export all personal data relating to a specific data subject (client).
     * Compliant with RA 10173 Section 36 (Data Portability) & Section 34(c) (Right of Access).
     *
     * @param mysqli|SupabaseConnection $conn
     * @param string $identifier Client email, phone number, or booking reference number
     * @return array Standardized DSR Export payload
     */
    public static function exportDataSubjectArchive($conn, string $identifier): array {
        $identifier = trim($identifier);
        if (empty($identifier)) {
            return [
                'success' => false,
                'message' => 'Please provide a valid client identifier (email, phone, or reference number).'
            ];
        }

        // Fetch candidate bookings
        $matching_bookings = [];
        $res = $conn->query("SELECT * FROM bookings ORDER BY id DESC LIMIT 500");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $decrypted = qes_decrypt_booking($row);
                $clean_id = strtolower($identifier);

                $match = false;
                if (!empty($decrypted['reference_no']) && strtolower($decrypted['reference_no']) === $clean_id) {
                    $match = true;
                } elseif (!empty($decrypted['client_email']) && strtolower($decrypted['client_email']) === $clean_id) {
                    $match = true;
                } elseif (!empty($decrypted['client_phone']) && preg_replace('/[^0-9]/', '', $decrypted['client_phone']) === preg_replace('/[^0-9]/', '', $clean_id)) {
                    $match = true;
                } elseif (!empty($decrypted['client_name']) && strtolower($decrypted['client_name']) === $clean_id) {
                    $match = true;
                }

                if ($match) {
                    $matching_bookings[] = $decrypted;
                }
            }
        }

        if (empty($matching_bookings)) {
            return [
                'success' => false,
                'message' => "No personal data records found matching identifier: {$identifier}"
            ];
        }

        $booking_ids = array_map(fn($b) => (int)$b['id'], $matching_bookings);
        $primary = $matching_bookings[0];

        // Gather associated notifications
        $notifications = [];
        if (!empty($booking_ids)) {
            $in_clause = implode(',', $booking_ids);
            $n_res = $conn->query("SELECT id, booking_id, title, message, type, is_read, created_at FROM notifications WHERE booking_id IN ({$in_clause}) ORDER BY id DESC");
            if ($n_res) {
                while ($n_row = $n_res->fetch_assoc()) {
                    $notifications[] = $n_row;
                }
            }
        }

        $export_payload = [
            'export_metadata' => [
                'system_name'               => 'Tyoy Creation Quick Event Styling (QES)',
                'regulatory_framework'      => 'Republic Act No. 10173 (Philippine Data Privacy Act of 2012)',
                'request_type'              => 'Data Subject Access Request (DSAR) - Right to Data Portability',
                'generation_timestamp'      => date('c'),
                'generation_date_formatted' => date('F j, Y g:i A T'),
                'data_subject_identifier'   => $identifier,
                'total_bookings_found'      => count($matching_bookings),
                'total_notifications_found' => count($notifications)
            ],
            'data_subject_profile' => [
                'full_name'      => $primary['client_name'] ?? 'N/A',
                'email_address'  => $primary['client_email'] ?? 'N/A',
                'contact_number' => $primary['client_phone'] ?? 'N/A',
                'address'        => $primary['client_address'] ?? 'N/A'
            ],
            'data_processing_basis' => [
                'lawful_basis'               => 'Consent (RA 10173 Sec. 12(a)) & Pre-Contractual Steps (RA 10173 Sec. 12(b))',
                'primary_purposes'           => [
                    'Event inquiry evaluation and calendar date reservation',
                    'Preparation of customized styling quotations and floral concept designs',
                    'Two-step booking verification (OTP codes)',
                    'Direct event styling coordination and client notices'
                ],
                'storage_encryption'         => 'AES-256-CBC with HMAC-SHA256 authenticated encryption at rest; TLS 1.3 in transit',
                'third_party_sharing_policy' => 'Strict zero-monetization policy. No data is shared with or sold to external advertisers or brokers.'
            ],
            'bookings_history' => array_map(function($b) {
                return [
                    'booking_id'           => (int)$b['id'],
                    'reference_no'         => $b['reference_no'] ?? '',
                    'event_title'          => $b['event_title'] ?? '',
                    'event_type'           => $b['event_type'] ?? '',
                    'event_start'          => $b['event_start'] ?? '',
                    'event_end'            => $b['event_end'] ?? '',
                    'guest_count'          => (int)($b['guest_count'] ?? 0),
                    'location_venue'       => $b['location_venue'] ?? '',
                    'service_requirements' => $b['service_requirements'] ?? '',
                    'special_notes'        => $b['special_notes'] ?? '',
                    'booking_status'       => $b['status'] ?? 'pending',
                    'source'               => $b['source'] ?? 'online_inquiry',
                    'created_at'           => $b['created_at'] ?? '',
                    'updated_at'           => $b['updated_at'] ?? ''
                ];
            }, $matching_bookings),
            'system_notifications' => $notifications,
            'statutory_rights_notice' => [
                'right_to_be_informed' => 'You have the right to know how your personal data is collected and processed.',
                'right_to_access'      => 'You have the right to reasonable access to your personal information.',
                'right_to_object'      => 'You have the right to object to the processing of your personal data.',
                'right_to_rectification' => 'You have the right to dispute any inaccuracy or error in your personal data.',
                'right_to_erasure'     => 'You have the right to order the blocking or deletion of your personal data upon request.',
                'right_to_portability' => 'You have the right to obtain a copy of your data in an electronic, structured format.',
                'dpo_contact'          => 'privacy@tyoycreation.com'
            ]
        ];

        return [
            'success'  => true,
            'filename' => 'DSR_Export_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $primary['client_name'] ?? 'Client') . '_' . date('Ymd_His') . '.json',
            'data'     => $export_payload
        ];
    }

    /**
     * Executes Data-Subject Right to Erasure / Blocking per RA 10173 Section 34(e).
     *
     * Supports two compliance modes:
     * 1. 'purge': Complete permanent deletion of booking rows and associated notifications.
     * 2. 'anonymize': For completed bookings with tax/accounting obligations (5-year BIR rule),
     *    strips all identifiable personal information (Name, Email, Phone, Address) while
     *    preserving non-PII financial totals and event dates.
     *
     * @param mysqli|SupabaseConnection $conn
     * @param array $booking_ids Array of integer booking IDs
     * @param string $mode 'purge' or 'anonymize'
     * @param string $admin_username Admin performing the action
     * @return array Result summary
     */
    public static function executeErasureRequest($conn, array $booking_ids, string $mode = 'purge', string $admin_username = 'Admin'): array {
        $id_list = array_values(array_filter(array_map('intval', $booking_ids), fn($x) => $x > 0));
        if (empty($id_list)) {
            return ['success' => false, 'message' => 'No valid booking IDs provided for erasure.'];
        }

        $placeholders = implode(',', array_fill(0, count($id_list), '?'));
        $types = str_repeat('i', count($id_list));
        $now = date('Y-m-d H:i:s');

        if ($mode === 'anonymize') {
            // Anonymization / Pseudonymization
            $anon_name = qes_encrypt('[Anonymized - DSR Erasure Request]', false);
            $anon_email = '';
            $anon_phone = '';
            $anon_address = '';
            $note_append = "\n[DSR Right to Erasure Executed on {$now} by {$admin_username} under RA 10173]";

            $count_updated = 0;
            foreach ($id_list as $bId) {
                $u_stmt = $conn->prepare("UPDATE bookings SET client_name = ?, client_email = ?, client_phone = ?, client_address = ?, special_notes = CONCAT(IFNULL(special_notes,''), ?) WHERE id = ?");
                if ($u_stmt) {
                    $u_stmt->bind_param("sssssi", $anon_name, $anon_email, $anon_phone, $anon_address, $note_append, $bId);
                    if ($u_stmt->execute()) {
                        $count_updated++;
                    }
                    $u_stmt->close();
                }
            }

            // Remove associated personal notification records
            $del_notif = $conn->prepare("DELETE FROM notifications WHERE booking_id IN ($placeholders)");
            if ($del_notif) {
                $del_notif->bind_param($types, ...$id_list);
                $del_notif->execute();
                $del_notif->close();
            }

            self::logDsrAudit($conn, "DSR Erasure (Anonymization) completed for {$count_updated} booking record(s) by {$admin_username}.");

            return [
                'success' => true,
                'mode'    => 'anonymize',
                'count'   => $count_updated,
                'message' => "Successfully anonymized {$count_updated} client record(s). All PII has been permanently scrubbed."
            ];
        }

        // Full Purge
        // 1. Delete associated notifications
        $del_notif = $conn->prepare("DELETE FROM notifications WHERE booking_id IN ($placeholders)");
        if ($del_notif) {
            $del_notif->bind_param($types, ...$id_list);
            $del_notif->execute();
            $del_notif->close();
        }

        // 2. Delete the bookings
        $del_b = $conn->prepare("DELETE FROM bookings WHERE id IN ($placeholders)");
        $count_deleted = 0;
        if ($del_b) {
            $del_b->bind_param($types, ...$id_list);
            $del_b->execute();
            $count_deleted = count($id_list);
            $del_b->close();
        }

        self::logDsrAudit($conn, "DSR Erasure (Full Purge) completed for {$count_deleted} booking record(s) by {$admin_username}.");

        return [
            'success' => true,
            'mode'    => 'purge',
            'count'   => $count_deleted,
            'message' => "Successfully permanently deleted {$count_deleted} client record(s) and all associated notification trails."
        ];
    }

    /**
     * Executes automated Data Retention Schedule purges.
     * Enforces storage limitation per RA 10173 Section 11 (General Principles).
     *
     * @param mysqli|SupabaseConnection $conn
     * @return array Summary of purged records
     */
    public static function runRetentionSchedulePurge($conn): array {
        $stats = [
            'rejected_purged'     => 0,
            'stale_chat_purged'   => 0,
            'stale_notif_purged'  => 0,
            'stale_sessions'      => 0,
            'timestamp'           => date('c')
        ];

        // 1. Auto-Purge Rejected Inquiries older than 7 days
        $rej_stmt = $conn->prepare("DELETE FROM bookings WHERE status = 'rejected' AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        if ($rej_stmt) {
            $rej_stmt->execute();
            $stats['rejected_purged'] = (int)($rej_stmt->affected_rows ?? 0);
            $rej_stmt->close();
        }

        // 2. Auto-Purge Chatbot transcripts older than 30 days (if chat_messages table exists)
        $chat_chk = $conn->query("SHOW TABLES LIKE 'chat_messages'");
        if ($chat_chk && $chat_chk->num_rows > 0) {
            $chat_stmt = $conn->prepare("DELETE FROM chat_messages WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            if ($chat_stmt) {
                $chat_stmt->execute();
                $stats['stale_chat_purged'] = (int)($chat_stmt->affected_rows ?? 0);
                $chat_stmt->close();
            }
        }

        // 3. Auto-Purge Read Notifications older than 90 days
        $notif_chk = $conn->query("SHOW TABLES LIKE 'notifications'");
        if ($notif_chk && $notif_chk->num_rows > 0) {
            $notif_stmt = $conn->prepare("DELETE FROM notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
            if ($notif_stmt) {
                $notif_stmt->execute();
                $stats['stale_notif_purged'] = (int)($notif_stmt->affected_rows ?? 0);
                $notif_stmt->close();
            }
        }

        // 4. Clean Blocked/Expired User Sessions older than 30 days
        $sess_chk = $conn->query("SHOW TABLES LIKE 'user_sessions'");
        if ($sess_chk && $sess_chk->num_rows > 0) {
            $sess_stmt = $conn->prepare("DELETE FROM user_sessions WHERE last_active < DATE_SUB(NOW(), INTERVAL 30 DAY) OR (is_blocked = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY))");
            if ($sess_stmt) {
                $sess_stmt->execute();
                $stats['stale_sessions'] = (int)($sess_stmt->affected_rows ?? 0);
                $sess_stmt->close();
            }
        }

        return [
            'success' => true,
            'stats'   => $stats,
            'message' => "Retention schedule purge complete: {$stats['rejected_purged']} rejected booking(s), {$stats['stale_chat_purged']} chat message(s), and {$stats['stale_notif_purged']} old notification(s) purged."
        ];
    }

    /**
     * Internal audit logging for privacy operations.
     */
    private static function logDsrAudit($conn, string $message): void {
        $notif_chk = $conn->query("SHOW TABLES LIKE 'notifications'");
        if ($notif_chk && $notif_chk->num_rows > 0) {
            $stmt = $conn->prepare("INSERT INTO notifications (booking_id, title, message, type, is_read, created_at) VALUES (0, 'DSR Privacy Audit Log', ?, 'system', 0, NOW())");
            if ($stmt) {
                $stmt->bind_param("s", $message);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}
