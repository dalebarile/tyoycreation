<?php
// ============================================================
// QES Notification Engine — Queue & Retry Edition
// All email sends flow through the queue:
//   1. Enqueue  → INSERT with status='pending'
//   2. Dispatch → attempt SMTP send immediately
//   3. Success  → UPDATE status='sent', sent_at=NOW()
//   4. Failure  → UPDATE status='failed', increment attempt_count
//   5. Retry    → Admin triggers re-attempt on failed rows
//   6. Max cap  → After max_attempts reached, stays 'failed'
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// Load PHPMailer (manual install, no Composer required)
require_once __DIR__ . '/vendor/phpmailer/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class NotificationHelper {

    /**
     * Maximum retry attempts before a notification is permanently failed.
     * Configurable via the settings table key 'notif_max_attempts'.
     */
    const DEFAULT_MAX_ATTEMPTS = 3;

    // ============================================================
    // INTERNAL: Low-level SMTP send — returns [bool $ok, string $error]
    // Never logs credentials. Sanitizes error strings.
    // ============================================================
    private static function trySMTPSend(string $to, string $name, string $subject, string $body): array {
        if (empty(EMAIL_USERNAME) || empty(EMAIL_PASSWORD)) {
            return [false, 'SMTP credentials not configured in .env.php'];
        }
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = EMAIL_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = EMAIL_USERNAME;
            $mail->Password   = EMAIL_PASSWORD;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = EMAIL_PORT;
            $mail->CharSet    = 'UTF-8';
            $mail->Encoding   = 'base64';
            $mail->Timeout    = 8;
            $mail->Timelimit  = 10;
            $mail->setFrom(EMAIL_USERNAME, EMAIL_FROM_NAME);
            $mail->addAddress($to, $name);
            $mail->Subject    = $subject;

            $is_html = (strpos($body, '<html') !== false || strpos($body, '<div') !== false || strpos($body, '<p>') !== false);
            if ($is_html) {
                $mail->isHTML(true);
                $mail->Body    = $body;
                $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</tr>', '</div>'], "\n", $body));
            } else {
                $mail->isHTML(false);
                $mail->Body    = $body;
            }

            $mail->send();
            error_log("[QES Notify] Email SENT to {$to} | Subject: {$subject}");
            return [true, ''];
        } catch (PHPMailerException $e) {
            // Sanitize: remove any credential-like tokens from PHPMailer error
            $raw_error = $mail->ErrorInfo;
            $safe_error = preg_replace('/Username.*|Password.*|AUTH.*/i', '[credential redacted]', $raw_error);
            $safe_error = substr(trim($safe_error), 0, 480); // cap at column length
            error_log("[QES Notify] Email FAILED to {$to}: {$safe_error}");
            return [false, $safe_error];
        }
    }

    // ============================================================
    // INTERNAL: Update a queued notification row after a send attempt
    // ============================================================
    private static function updateQueueRow($conn, int $notif_id, bool $success, string $error_msg = ''): void {
        if ($success) {
            $stmt = $conn->prepare(
                "UPDATE notifications
                    SET status          = 'sent',
                        sent_at         = NOW(),
                        last_attempt_at = NOW(),
                        attempt_count   = attempt_count + 1,
                        last_error      = NULL
                  WHERE id = ?"
            );
            if ($stmt) {
                $stmt->bind_param("i", $notif_id);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            // Increment attempt count; mark 'failed' regardless of max_attempts
            // (The caller decides whether to keep retrying or permanently fail)
            $stmt = $conn->prepare(
                "UPDATE notifications
                    SET attempt_count   = attempt_count + 1,
                        last_attempt_at = NOW(),
                        last_error      = ?,
                        status          = CASE
                            WHEN (attempt_count + 1) >= max_attempts THEN 'failed'
                            ELSE 'pending'
                        END
                  WHERE id = ?"
            );
            if ($stmt) {
                $stmt->bind_param("si", $error_msg, $notif_id);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    // ============================================================
    // CORE: Enqueue a notification, then attempt immediate dispatch.
    // Returns the notification row ID for tracking.
    // Booking approval/rejection is NEVER blocked by email failure.
    // ============================================================
    private static function enqueueAndDispatch(
        $conn,
        ?int    $booking_id,
        string  $recipient_name,
        string  $recipient_contact,
        string  $channel,
        string  $template_type,
        string  $subject,
        string  $body
    ): int {
        // --- Guard: skip if already sent for this booking+template (duplicate prevention) ---
        if ($booking_id !== null && in_array($template_type, ['inquiry', 'approval', 'rejection', 'reminder'])) {
            $dup = $conn->prepare(
                "SELECT id FROM notifications
                  WHERE booking_id    = ?
                    AND template_type = ?
                    AND status        = 'sent'
                  LIMIT 1"
            );
            if ($dup) {
                $dup->bind_param("is", $booking_id, $template_type);
                $dup->execute();
                $dup_res = $dup->get_result();
                if ($dup_res && $dup_res->num_rows > 0) {
                    $existing = $dup_res->fetch_assoc();
                    $dup->close();
                    error_log("[QES Notify] Duplicate suppressed: booking #{$booking_id} type={$template_type} already sent (notif #{$existing['id']})");
                    return (int)$existing['id'];
                }
                $dup->close();
            }
        }

        $max_attempts = self::DEFAULT_MAX_ATTEMPTS;
        $enc_name     = qes_encrypt($recipient_name, false);
        $enc_contact  = qes_encrypt($recipient_contact, true);

        // 1. INSERT with status='pending'
        $stmt = $conn->prepare(
            "INSERT INTO notifications
                (booking_id, recipient_name, recipient_contact, channel, template_type,
                 subject, message, status, attempt_count, max_attempts)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 0, ?)"
        );
        if (!$stmt) {
            error_log("[QES Notify] Failed to prepare INSERT: " . $conn->error);
            return 0;
        }
        $stmt->bind_param("issssssi",
            $booking_id, $enc_name, $enc_contact,
            $channel, $template_type, $subject, $body, $max_attempts
        );
        $stmt->execute();
        $notif_id = (int)($stmt->insert_id ?? 0);
        if ($notif_id <= 0) {
            $notif_id = (int)($conn->insert_id ?? 0);
        }
        $stmt->close();

        // Fallback: if insert_id wasn't returned by driver, lookup by booking_id
        if ($notif_id <= 0 && $booking_id !== null) {
            $f_check = $conn->prepare("SELECT id FROM notifications WHERE booking_id = ? AND template_type = ? ORDER BY id DESC LIMIT 1");
            if ($f_check) {
                $f_check->bind_param("is", $booking_id, $template_type);
                $f_check->execute();
                $f_res = $f_check->get_result();
                if ($f_res && ($f_row = $f_res->fetch_assoc())) {
                    $notif_id = (int)($f_row['id'] ?? 0);
                }
                $f_check->close();
            }
        }

        // 2. Attempt immediate send (channel = email only for now)
        // Note: We never block email dispatch even if notif_id could not be resolved
        if ($channel === 'email' && !empty($recipient_contact)) {
            [$ok, $error] = self::trySMTPSend($recipient_contact, $recipient_name, $subject, $body);
            if ($notif_id > 0) {
                self::updateQueueRow($conn, $notif_id, $ok, $error);
            }
        }

        return $notif_id;
    }

    // ============================================================
    // PUBLIC: Retry a specific failed/pending notification by ID.
    // Returns true if the email was successfully delivered.
    // ============================================================
    public static function retryNotification($conn, int $notif_id): bool {
        $stmt = $conn->prepare(
            "SELECT * FROM notifications WHERE id = ? LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param("i", $notif_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) return false;

        // Do not retry already-sent notifications
        if ($row['status'] === 'sent') {
            error_log("[QES Notify] Retry skipped: notification #{$notif_id} is already 'sent'");
            return false;
        }

        // Decrypt contact info for sending
        $recipient_name    = qes_decrypt($row['recipient_name']);
        $recipient_contact = qes_decrypt($row['recipient_contact']);

        if (empty($recipient_contact)) {
            error_log("[QES Notify] Retry aborted: no contact address on notification #{$notif_id}");
            return false;
        }

        // Reset max_attempts if admin is explicitly retrying past the cap
        $current_attempts = (int)($row['attempt_count'] ?? 0);
        $max_attempts     = (int)($row['max_attempts']  ?? self::DEFAULT_MAX_ATTEMPTS);
        if ($current_attempts >= $max_attempts) {
            // Extend cap by DEFAULT_MAX_ATTEMPTS more tries on manual retry
            $new_max = $current_attempts + self::DEFAULT_MAX_ATTEMPTS;
            $ext = $conn->prepare("UPDATE notifications SET max_attempts = ? WHERE id = ?");
            if ($ext) {
                $ext->bind_param("ii", $new_max, $notif_id);
                $ext->execute();
                $ext->close();
            }
        }

        [$ok, $error] = self::trySMTPSend(
            $recipient_contact,
            $recipient_name,
            $row['subject'] ?? '(no subject)',
            $row['message']
        );
        self::updateQueueRow($conn, $notif_id, $ok, $error);
        return $ok;
    }

    // ============================================================
    // PUBLIC: Process all pending/failed notifications in the queue
    // that have not yet reached their max_attempts cap.
    // Call from a cron-like admin action or background task.
    // ============================================================
    public static function processQueue($conn): array {
        $results = ['attempted' => 0, 'sent' => 0, 'failed' => 0];

        $stmt = $conn->prepare(
            "SELECT * FROM notifications
              WHERE status IN ('pending', 'failed')
                AND attempt_count < max_attempts
              ORDER BY created_at ASC
              LIMIT 50"
        );
        if (!$stmt) return $results;
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();

        if (!$res) return $results;

        while ($row = $res->fetch_assoc()) {
            $results['attempted']++;
            $recipient_name    = qes_decrypt($row['recipient_name']);
            $recipient_contact = qes_decrypt($row['recipient_contact']);

            if (empty($recipient_contact)) {
                $results['failed']++;
                continue;
            }

            [$ok, $error] = self::trySMTPSend(
                $recipient_contact,
                $recipient_name,
                $row['subject'] ?? '(no subject)',
                $row['message']
            );
            self::updateQueueRow($conn, (int)$row['id'], $ok, $error);

            if ($ok) {
                $results['sent']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    // ============================================================
    // PUBLIC LEGACY: logNotification — preserved for callers that
    // log-only without dispatching (e.g., chatbot booking log).
    // ============================================================
    public static function logNotification(
        $conn,
        $booking_id,
        $recipient_name,
        $recipient_contact,
        $channel,
        $template_type,
        $subject,
        $message,
        $status = 'pending'
    ) {
        // If caller already knows outcome (status='sent'|'failed'), use direct INSERT
        // This path is used by chatbot and admin-created accounts.
        $max_attempts = self::DEFAULT_MAX_ATTEMPTS;
        $enc_name     = qes_encrypt($recipient_name, false);
        $enc_contact  = qes_encrypt($recipient_contact, true);

        $stmt = $conn->prepare(
            "INSERT INTO notifications
                (booking_id, recipient_name, recipient_contact, channel, template_type,
                 subject, message, status, attempt_count, max_attempts,
                 sent_at, last_attempt_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?,
                     CASE WHEN ? = 'sent' THEN NOW() ELSE NULL END,
                     NOW())"
        );
        if ($stmt) {
            $stmt->bind_param("isssssssis",
                $booking_id, $enc_name, $enc_contact,
                $channel, $template_type, $subject, $message,
                $status, $max_attempts, $status
            );
            $stmt->execute();
            $stmt->close();
            return true;
        }
        return false;
    }

    // ============================================================
    // PUBLIC LEGACY: sendCustomEmail — one-shot, no queue.
    // Used by a_notifications.php manual dispatch.
    // ============================================================
    public static function sendCustomEmail($to, $name, $subject, $body) {
        [$ok] = self::trySMTPSend($to, $name, $subject, $body);
        return $ok;
    }

    // ============================================================
    // ADMIN ACCOUNT CREATED — direct send + log (not queued,
    // since password must reach admin immediately)
    // ============================================================
    public static function sendAdminCreatedEmail($conn, $to_email, $full_name, $username, $password) {
        $business_name = function_exists('get_setting') ? get_setting($conn, 'business_name', 'Tyoy Creation') : 'Tyoy Creation';

        $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $login_url = $protocol . $host . '/qes/loginadmin.php';

        $subject = "Admin Account Access Granted - {$business_name}";
        $body    = "Hello {$full_name},\n\n"
                 . "An administrator account has been created for you at {$business_name} by the Main Administrator.\n\n"
                 . "You can now log in to the Admin Management Portal using the credentials below:\n\n"
                 . "==================================================\n"
                 . "Admin Portal URL : {$login_url}\n"
                 . "Username / Email : {$username} (or {$to_email})\n"
                 . "Temporary Password: {$password}\n"
                 . "Assigned Role    : Administrator\n"
                 . "==================================================\n\n"
                 . "Security Reminders:\n"
                 . "1. Please log in immediately and update your password in Settings.\n"
                 . "2. Never share your administrative login credentials with anyone.\n\n"
                 . "Best regards,\n"
                 . "{$business_name} Administration";

        [$sent] = self::trySMTPSend($to_email, $full_name, $subject, $body);

        self::logNotification(
            $conn, null, $full_name, $to_email,
            'email', 'custom', $subject, $body,
            $sent ? 'sent' : 'failed'
        );

        return $sent;
    }

    // ============================================================
    // PASSWORD RESET VERIFICATION CODE EMAIL
    // Sent when an administrator or user requests a password reset
    // ============================================================
    public static function sendPasswordResetCodeEmail($conn, string $to_email, string $recipient_name, string $code): bool {
        $business_name = function_exists('get_setting') ? get_setting($conn, 'business_name', 'Tyoy Creation') : 'Tyoy Creation';
        $subject = "Your Password Reset Code: {$code} - {$business_name}";

        $body = '<div style="font-family: Arial, Helvetica, sans-serif; max-width: 580px; margin: 0 auto; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 18px rgba(0,0,0,0.06);">'
              . '<div style="background: #364735; padding: 26px 20px; text-align: center; color: #ffffff;">'
              . '<h2 style="margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -0.02em;">' . htmlspecialchars($business_name) . '</h2>'
              . '<p style="margin: 6px 0 0 0; font-size: 13px; color: #e5ede5;">Account Security &amp; Password Recovery</p>'
              . '</div>'
              . '<div style="padding: 30px 24px;">'
              . '<h3 style="margin: 0 0 10px 0; color: #111827; font-size: 18px; font-weight: 700;">Password Reset Request</h3>'
              . '<p style="font-size: 14px; line-height: 1.6; color: #374151; margin: 0 0 16px 0;">Hello <strong>' . htmlspecialchars($recipient_name) . '</strong>,</p>'
              . '<p style="font-size: 14px; line-height: 1.6; color: #374151; margin: 0 0 20px 0;">We received a request to reset your account password. Enter the 6-digit verification code below to verify your identity and set a new password:</p>'
              . '<div style="background: #eef2ee; border: 2px dashed #364735; border-radius: 12px; padding: 20px; text-align: center; margin-bottom: 22px;">'
              . '<div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em; color: #64748b; font-weight: 700; margin-bottom: 6px;">Your One-Time Security Code</div>'
              . '<div style="font-family: monospace, Courier, monospace; font-size: 36px; font-weight: 800; color: #232f22; letter-spacing: 12px;">' . htmlspecialchars($code) . '</div>'
              . '<div style="font-size: 12px; color: #527952; margin-top: 6px; font-weight: 600;"><i class="fa-solid fa-clock"></i> Valid for 15 minutes</div>'
              . '</div>'
              . '<div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 14px; border-radius: 6px; font-size: 12px; color: #92400e; line-height: 1.5; margin-bottom: 20px;">'
              . '<strong>Security Alert:</strong> Never share this verification code with anyone. Tyoy Creation personnel will never ask for your code. If you did not make this request, your account is safe and you can ignore this email.'
              . '</div>'
              . '<p style="font-size: 13px; color: #6b7280; line-height: 1.5; margin: 0;">'
              . 'Thank you,<br><strong>' . htmlspecialchars($business_name) . ' Security Team</strong>'
              . '</p>'
              . '</div>'
              . '<div style="background: #f9fafb; padding: 14px 20px; text-align: center; font-size: 11px; color: #9ca3af; border-top: 1px solid #e5e7eb;">'
              . htmlspecialchars($business_name) . ' &bull; Authorized Security Verification'
              . '</div>'
              . '</div>';

        [$sent] = self::trySMTPSend($to_email, $recipient_name, $subject, $body);

        // Security Hardening: Redact reset code from database notification logs
        $safe_log_subject = "Your Password Reset Code: [REDACTED] - {$business_name}";
        $safe_log_body    = str_replace($code, '••••••', $body);

        self::logNotification(
            $conn, null, $recipient_name, $to_email,
            'email', 'password_reset', $safe_log_subject, $safe_log_body,
            $sent ? 'sent' : 'failed'
        );

        return $sent;
    }

    // ============================================================
    // BOOKING VERIFICATION CODE EMAIL
    // Sent when a user submits a booking (2-step verification)
    // ============================================================
    public static function sendBookingVerificationCodeEmail($conn, string $to_email, string $recipient_name, string $code, array $summary = []): bool {
        $business_name = function_exists('get_setting') ? get_setting($conn, 'business_name', 'Tyoy Creation') : 'Tyoy Creation';
        $subject = "Your 6-Digit Booking Verification Code: {$code} - {$business_name}";

        $event_title  = htmlspecialchars($summary['event_title'] ?? 'Special Event');
        $event_type   = htmlspecialchars($summary['event_type'] ?? 'General Event');
        $event_date   = htmlspecialchars($summary['event_date'] ?? 'TBD');
        $event_time   = htmlspecialchars($summary['event_time'] ?? '10:00');
        $location     = htmlspecialchars($summary['location_venue'] ?? 'To Be Confirmed');
        $guest_count  = (int)($summary['guest_count'] ?? 0);
        $requirements = htmlspecialchars($summary['service_requirements'] ?? 'Standard Event Styling');

        $body = '<div style="font-family: Arial, Helvetica, sans-serif; max-width: 580px; margin: 0 auto; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 14px; overflow: hidden; box-shadow: 0 4px 18px rgba(0,0,0,0.06);">'
              . '<div style="background: #18392b; padding: 26px 20px; text-align: center; color: #ffffff;">'
              . '<h2 style="margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -0.02em;">' . htmlspecialchars($business_name) . '</h2>'
              . '<p style="margin: 6px 0 0 0; font-size: 13px; color: #e5ede5;">Event Reservation Verification</p>'
              . '</div>'
              . '<div style="padding: 28px 24px;">'
              . '<h3 style="margin: 0 0 10px 0; color: #111827; font-size: 18px; font-weight: 700;">Confirm Your Booking Request</h3>'
              . '<p style="font-size: 14px; line-height: 1.6; color: #374151; margin: 0 0 16px 0;">Hello <strong>' . htmlspecialchars($recipient_name) . '</strong>,</p>'
              . '<p style="font-size: 14px; line-height: 1.6; color: #374151; margin: 0 0 20px 0;">Thank you for planning your event with us! To complete your submission and verify your identity, please enter the following 6-digit verification code:</p>'
              . '<div style="background: #f0fdf4; border: 2px dashed #16a34a; border-radius: 12px; padding: 20px; text-align: center; margin-bottom: 24px;">'
              . '<div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em; color: #15803d; font-weight: 700; margin-bottom: 6px;">Your One-Time Verification Code</div>'
              . '<div style="font-family: monospace, Courier, monospace; font-size: 38px; font-weight: 800; color: #14532d; letter-spacing: 12px;">' . htmlspecialchars($code) . '</div>'
              . '<div style="font-size: 12px; color: #166534; margin-top: 6px; font-weight: 600;">&#9201; Valid for 10 minutes only</div>'
              . '</div>'
              . '<h4 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 700; color: #111827; text-transform: uppercase; letter-spacing: 0.05em;">Pending Booking Summary</h4>'
              . '<table style="width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 22px; background: #f9fafb; border-radius: 8px; overflow: hidden; border: 1px solid #e5e7eb;">'
              . '<tr><td style="padding: 10px 14px; color: #6b7280; font-weight: 600; width: 38%; border-bottom: 1px solid #e5e7eb;">Event Title:</td><td style="padding: 10px 14px; color: #111827; font-weight: 700; border-bottom: 1px solid #e5e7eb;">' . $event_title . '</td></tr>'
              . '<tr><td style="padding: 10px 14px; color: #6b7280; font-weight: 600; border-bottom: 1px solid #e5e7eb;">Event Type:</td><td style="padding: 10px 14px; color: #111827; border-bottom: 1px solid #e5e7eb;">' . $event_type . '</td></tr>'
              . '<tr><td style="padding: 10px 14px; color: #6b7280; font-weight: 600; border-bottom: 1px solid #e5e7eb;">Date &amp; Time:</td><td style="padding: 10px 14px; color: #111827; border-bottom: 1px solid #e5e7eb;">' . $event_date . ' at ' . $event_time . '</td></tr>'
              . '<tr><td style="padding: 10px 14px; color: #6b7280; font-weight: 600; border-bottom: 1px solid #e5e7eb;">Venue / Location:</td><td style="padding: 10px 14px; color: #111827; border-bottom: 1px solid #e5e7eb;">' . $location . '</td></tr>'
              . ($guest_count > 0 ? '<tr><td style="padding: 10px 14px; color: #6b7280; font-weight: 600; border-bottom: 1px solid #e5e7eb;">Guest Count:</td><td style="padding: 10px 14px; color: #111827; border-bottom: 1px solid #e5e7eb;">' . $guest_count . ' attendees</td></tr>' : '')
              . '<tr><td style="padding: 10px 14px; color: #6b7280; font-weight: 600;">Requirements:</td><td style="padding: 10px 14px; color: #111827; font-size: 12px;">' . $requirements . '</td></tr>'
              . '</table>'
              . '<div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 14px; border-radius: 6px; font-size: 12px; color: #92400e; line-height: 1.5; margin-bottom: 20px;">'
              . '<strong>Note:</strong> Your reservation is not officially placed until you enter this verification code on our site. If you did not make this reservation, please disregard this email.'
              . '</div>'
              . '<p style="font-size: 13px; color: #6b7280; line-height: 1.5; margin: 0;">'
              . 'Best regards,<br><strong>' . htmlspecialchars($business_name) . ' Team</strong>'
              . '</p>'
              . '</div>'
              . '<div style="background: #f9fafb; padding: 14px 20px; text-align: center; font-size: 11px; color: #9ca3af; border-top: 1px solid #e5e7eb;">'
              . htmlspecialchars($business_name) . ' &bull; Two-Step Booking Verification'
              . '</div>'
              . '</div>';

        if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
            $sent = true;
        } else {
            [$sent] = self::trySMTPSend($to_email, $recipient_name, $subject, $body);
        }

        $safe_log_subject = "Your 6-Digit Booking Verification Code: [REDACTED] - {$business_name}";
        $safe_log_body    = str_replace($code, '••••••', $body);

        self::logNotification(
            $conn, null, $recipient_name, $to_email,
            'email', 'inquiry', $safe_log_subject, $safe_log_body,
            $sent ? 'sent' : 'failed'
        );

        return $sent;
    }

    // ============================================================
    // INQUIRY RECEIVED NOTICE — queued + immediate dispatch
    // Sent automatically to client upon submitting a public booking
    // ============================================================
    public static function sendInquiryReceivedNotice($conn, $booking_id) {
        $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$booking) return false;
        $booking = qes_decrypt_booking($booking);

        $client_name  = $booking['client_name'] ?? 'Valued Client';
        $client_email = $booking['client_email'] ?? '';
        $client_phone = $booking['client_phone'] ?? '';
        $event_title  = $booking['event_title'] ?? 'Special Celebration';
        $event_type   = $booking['event_type'] ?? 'Event';
        $event_date   = !empty($booking['event_start']) ? date('F j, Y (l) \a\t g:i A', strtotime($booking['event_start'])) : 'TBD';
        $ref_no       = $booking['reference_no'] ?? ('EV-' . date('Y'));
        $venue        = $booking['location_venue'] ?? 'To Be Confirmed';
        $guest_count  = (int)($booking['guest_count'] ?? 0);
        $requirements = $booking['service_requirements'] ?? '';
        $notes        = $booking['special_notes'] ?? '';

        if (empty($client_email) || !filter_var($client_email, FILTER_VALIDATE_EMAIL)) {
            error_log("[QES Notify] Cannot send inquiry notice: invalid or empty email '{$client_email}'");
            return false;
        }

        $business_name = function_exists('get_setting') ? get_setting($conn, 'business_name', 'Tyoy Creation') : 'Tyoy Creation';
        $subject = "Booking Inquiry Received - Ref: {$ref_no} ({$event_title})";

        $req_display = !empty($requirements) ? htmlspecialchars($requirements) : 'Standard Consultation & Custom Event Styling';
        $notes_html = !empty($notes) ? '<tr><td style="color:#6b7280; font-weight:600; vertical-align:top; padding:6px 0;">Special Notes:</td><td style="color:#374151; padding:6px 0;">' . nl2br(htmlspecialchars($notes)) . '</td></tr>' : '';

        $body = '<div style="font-family: Arial, Helvetica, sans-serif; max-width: 600px; margin: 0 auto; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.06);">'
              . '<div style="background: #364735; padding: 26px 20px; text-align: center; color: #ffffff;">'
              . '<h2 style="margin: 0; font-size: 24px; font-weight: 800; letter-spacing: -0.02em;">' . htmlspecialchars($business_name) . '</h2>'
              . '<p style="margin: 6px 0 0 0; font-size: 13px; color: #e5ede5;">Event Coordination, Styling, Flowers &amp; Balloons Specialist</p>'
              . '</div>'
              . '<div style="padding: 28px 24px;">'
              . '<div style="display:inline-block; background:#eef2ee; color:#232f22; font-size:12px; font-weight:700; padding:4px 12px; border-radius:20px; margin-bottom:14px;">Inquiry Confirmation</div>'
              . '<h3 style="margin: 0 0 12px 0; color: #111827; font-size: 19px; font-weight: 700;">We received your booking inquiry!</h3>'
              . '<p style="font-size: 14px; line-height: 1.6; color: #374151; margin: 0 0 14px 0;">Dear <strong>' . htmlspecialchars($client_name) . '</strong>,</p>'
              . '<p style="font-size: 14px; line-height: 1.6; color: #374151; margin: 0 0 20px 0;">Thank you for trusting <strong>' . htmlspecialchars($business_name) . '</strong> for your upcoming celebration. We have received your booking details and our event coordination team is currently reviewing your schedule and customized styling package.</p>'
              . '<div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 18px 20px; margin-bottom: 22px;">'
              . '<div style="font-size: 12px; text-transform: uppercase; font-weight: 800; color: #364735; margin-bottom: 12px; border-bottom: 1px solid #e5e7eb; padding-bottom: 6px; letter-spacing:0.04em;">Event Inquiry Summary</div>'
              . '<table style="width: 100%; font-size: 13px; line-height: 1.6; border-collapse: collapse;">'
              . '<tr><td style="width: 38%; color: #6b7280; font-weight: 600; padding: 5px 0;">Reference Code:</td><td style="font-family: monospace; font-weight: 800; font-size: 15px; color: #364735; padding: 5px 0;">' . htmlspecialchars($ref_no) . '</td></tr>'
              . '<tr><td style="color: #6b7280; font-weight: 600; padding: 5px 0;">Event Title:</td><td style="font-weight: 700; color: #111827; padding: 5px 0;">' . htmlspecialchars($event_title) . '</td></tr>'
              . '<tr><td style="color: #6b7280; font-weight: 600; padding: 5px 0;">Occasion Type:</td><td style="font-weight: 600; color: #364735; padding: 5px 0;">' . htmlspecialchars($event_type) . '</td></tr>'
              . '<tr><td style="color: #6b7280; font-weight: 600; padding: 5px 0;">Target Schedule:</td><td style="font-weight: 600; color: #111827; padding: 5px 0;">' . htmlspecialchars($event_date) . '</td></tr>'
              . '<tr><td style="color: #6b7280; font-weight: 600; padding: 5px 0;">Venue Location:</td><td style="color: #374151; padding: 5px 0;">' . htmlspecialchars($venue) . '</td></tr>'
              . '<tr><td style="color: #6b7280; font-weight: 600; padding: 5px 0;">Expected Guests:</td><td style="color: #374151; padding: 5px 0;">' . $guest_count . ' attendees</td></tr>'
              . '<tr><td style="color: #6b7280; font-weight: 600; vertical-align: top; padding: 5px 0;">Selected Package:</td><td style="color: #232f22; font-weight: 600; padding: 5px 0;">' . $req_display . '</td></tr>'
              . $notes_html
              . '</table>'
              . '</div>'
              . '<div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px; margin-bottom: 22px;">'
              . '<div style="font-weight: 700; font-size: 13px; color: #166534; margin-bottom: 6px;">Next Steps:</div>'
              . '<ol style="margin: 0; padding-left: 20px; font-size: 13px; color: #166534; line-height: 1.6;">'
              . '<li><strong>Schedule Verification:</strong> Our planning team will confirm venue coordination and team availability.</li>'
              . '<li><strong>Official Approval:</strong> Once verified, an official confirmation email will be dispatched to this address.</li>'
              . '<li><strong>Styling Consultation:</strong> Our coordinator may reach out at ' . htmlspecialchars($client_phone) . ' for theme preferences.</li>'
              . '</ol>'
              . '</div>'
              . '<p style="font-size: 13px; color: #6b7280; line-height: 1.5; margin: 0 0 16px 0;">'
              . 'Have questions or need to make immediate adjustments? Simply reply directly to this email or contact us at <a href="mailto:creationtyoy@gmail.com" style="color:#364735; font-weight:600;">creationtyoy@gmail.com</a>.'
              . '</p>'
              . '<p style="font-size: 14px; font-weight: 700; color: #364735; margin: 16px 0 0 0;">Warm regards,<br>' . htmlspecialchars($business_name) . ' Planning Team</p>'
              . '</div>'
              . '<div style="background: #f9fafb; padding: 14px 20px; text-align: center; font-size: 11px; color: #9ca3af; border-top: 1px solid #e5e7eb;">'
              . htmlspecialchars($business_name) . ' &bull; Turning Moments into Unforgettable Memories'
              . '</div>'
              . '</div>';

        self::enqueueAndDispatch(
            $conn, (int)$booking_id, $client_name,
            $client_email, 'email', 'inquiry', $subject, $body
        );

        return true;
    }

    // ============================================================
    // APPROVAL NOTICE — queued + immediate dispatch
    // ============================================================
    public static function sendApprovalNotice($conn, $booking_id) {
        $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$booking) return false;
        $booking = qes_decrypt_booking($booking);

        $client_name = $booking['client_name'];
        $event_title = $booking['event_title'];
        $event_date  = date('M d, Y g:i A', strtotime($booking['event_start']));
        $ref_no      = $booking['reference_no'];
        $venue       = $booking['location_venue'];

        if (empty($booking['client_email'])) return false;

        $subject         = "Booking Confirmed - " . $event_title;
        $custom_template = function_exists('get_setting') ? get_setting($conn, 'approval_template', '') : '';

        if (!empty(trim($custom_template))) {
            $replacements = [
                '{client_name}' => $client_name,
                '{event_title}' => $event_title,
                '{event_date}'  => $event_date,
                '{ref_no}'      => $ref_no,
                '{venue}'       => $venue,
                '{reason}'      => ''
            ];
            $body = str_replace(array_keys($replacements), array_values($replacements), $custom_template);
        } else {
            $business_name = function_exists('get_setting') ? get_setting($conn, 'business_name', 'Tyoy Creation') : 'Tyoy Creation';
            $body = '<div style="font-family: Arial, Helvetica, sans-serif; max-width: 600px; margin: 0 auto; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.06);">'
                  . '<div style="background: #364735; padding: 26px 20px; text-align: center; color: #ffffff;">'
                  . '<h2 style="margin: 0; font-size: 24px; font-weight: 800;">' . htmlspecialchars($business_name) . '</h2>'
                  . '<p style="margin: 6px 0 0 0; font-size: 13px; color: #e5ede5;">Event Coordination, Styling, Flowers &amp; Balloons Specialist</p>'
                  . '</div>'
                  . '<div style="padding: 28px 24px;">'
                  . '<div style="display:inline-block; background:#dcfce7; color:#15803d; font-size:12px; font-weight:700; padding:4px 12px; border-radius:20px; margin-bottom:14px;">Booking Confirmed &amp; Approved</div>'
                  . '<h3 style="margin: 0 0 12px 0; color: #111827; font-size: 20px; font-weight: 700;">Congratulations, your event is confirmed!</h3>'
                  . '<p style="font-size: 14px; line-height: 1.6; color: #374151; margin: 0 0 14px 0;">Dear <strong>' . htmlspecialchars($client_name) . '</strong>,</p>'
                  . '<p style="font-size: 14px; line-height: 1.6; color: #374151; margin: 0 0 20px 0;">Great news! Your booking for <strong>' . htmlspecialchars($event_title) . '</strong> has been officially approved and added to our Master Event Calendar.</p>'
                  . '<div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 18px 20px; margin-bottom: 22px;">'
                  . '<div style="font-size: 12px; text-transform: uppercase; font-weight: 800; color: #364735; margin-bottom: 12px; border-bottom: 1px solid #e5e7eb; padding-bottom: 6px; letter-spacing:0.04em;">Confirmed Event Details</div>'
                  . '<table style="width: 100%; font-size: 13px; line-height: 1.6; border-collapse: collapse;">'
                  . '<tr><td style="width: 38%; color: #6b7280; font-weight: 600; padding: 5px 0;">Reference Code:</td><td style="font-family: monospace; font-weight: 800; font-size: 15px; color: #364735; padding: 5px 0;">' . htmlspecialchars($ref_no) . '</td></tr>'
                  . '<tr><td style="color: #6b7280; font-weight: 600; padding: 5px 0;">Event Title:</td><td style="font-weight: 700; color: #111827; padding: 5px 0;">' . htmlspecialchars($event_title) . '</td></tr>'
                  . '<tr><td style="color: #6b7280; font-weight: 600; padding: 5px 0;">Confirmed Schedule:</td><td style="font-weight: 600; color: #111827; padding: 5px 0;">' . htmlspecialchars($event_date) . '</td></tr>'
                  . '<tr><td style="color: #6b7280; font-weight: 600; padding: 5px 0;">Venue Location:</td><td style="color: #374151; padding: 5px 0;">' . htmlspecialchars($venue) . '</td></tr>'
                  . '<tr><td style="color: #6b7280; font-weight: 600; padding: 5px 0;">Occasion Type:</td><td style="color: #374151; padding: 5px 0;">' . htmlspecialchars($booking['event_type'] ?? 'Special Event') . '</td></tr>'
                  . '<tr><td style="color: #6b7280; font-weight: 600; padding: 5px 0;">Expected Attendees:</td><td style="color: #374151; padding: 5px 0;">' . ((int)($booking['guest_count'] ?? 0)) . ' guests</td></tr>'
                  . '</table>'
                  . '</div>'
                  . '<div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px; margin-bottom: 22px;">'
                  . '<div style="font-weight: 700; font-size: 13px; color: #166534; margin-bottom: 6px;">What Happens Next:</div>'
                  . '<p style="margin: 0; font-size: 13px; color: #166534; line-height: 1.5;">Our lead coordinator will contact you shortly to finalize theme styling, floral choices, and assist with reservation deposit confirmation.</p>'
                  . '</div>'
                  . '<p style="font-size: 13px; color: #6b7280; line-height: 1.5; margin: 0 0 16px 0;">Need to discuss anything sooner? Reach us directly at <a href="mailto:creationtyoy@gmail.com" style="color:#364735; font-weight:600;">creationtyoy@gmail.com</a>.</p>'
                  . '<p style="font-size: 14px; font-weight: 700; color: #364735; margin: 16px 0 0 0;">Warm regards,<br>' . htmlspecialchars($business_name) . ' Team</p>'
                  . '</div>'
                  . '<div style="background: #f9fafb; padding: 14px 20px; text-align: center; font-size: 11px; color: #9ca3af; border-top: 1px solid #e5e7eb;">'
                  . htmlspecialchars($business_name) . ' &bull; Turning Moments into Unforgettable Memories'
                  . '</div>'
                  . '</div>';
        }

        self::enqueueAndDispatch(
            $conn, (int)$booking_id, $client_name,
            $booking['client_email'], 'email', 'approval', $subject, $body
        );

        return true;
    }

    // ============================================================
    // REJECTION NOTICE — queued + immediate dispatch
    // ============================================================
    public static function sendRejectionNotice($conn, $booking_id, $reason = '') {
        $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$booking) return false;
        $booking = qes_decrypt_booking($booking);

        $client_name = $booking['client_name'];
        $event_title = $booking['event_title'];
        $event_date  = date('M d, Y', strtotime($booking['event_start']));
        $ref_no      = $booking['reference_no'];
        $reason_text = !empty($reason) ? $reason : 'Schedule conflict or venue unavailability';

        if (empty($booking['client_email'])) return false;

        $subject         = "Event Booking Update - " . $event_title;
        $custom_template = function_exists('get_setting') ? get_setting($conn, 'rejection_template', '') : '';

        if (!empty(trim($custom_template))) {
            $replacements = [
                '{client_name}' => $client_name,
                '{event_title}' => $event_title,
                '{event_date}'  => $event_date,
                '{ref_no}'      => $ref_no,
                '{venue}'       => '',
                '{reason}'      => $reason_text
            ];
            $body = str_replace(array_keys($replacements), array_values($replacements), $custom_template);
        } else {
            $body = "Dear {$client_name},\n\n"
                  . "Thank you for your inquiry regarding '{$event_title}' (Ref: {$ref_no}).\n\n"
                  . "Regrettably, we are unable to accept your booking for {$event_date}.\n"
                  . "Reason: {$reason_text}\n\n"
                  . "We would love to help you find an alternative date. Please reply or give us a call.\n\n"
                  . "Sincerely,\nTyoy Creation Team";
        }

        self::enqueueAndDispatch(
            $conn, (int)$booking_id, $client_name,
            $booking['client_email'], 'email', 'rejection', $subject, $body
        );

        return true;
    }

    // ============================================================
    // EVENT REMINDER NOTICE — queued + immediate dispatch
    // ============================================================
    public static function sendEventReminderNotice($conn, $booking_id) {
        $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$booking) return false;
        $booking = qes_decrypt_booking($booking);

        $client_name = $booking['client_name'];
        $event_title = $booking['event_title'];
        $event_date  = date('M d, Y g:i A', strtotime($booking['event_start']));
        $ref_no      = $booking['reference_no'];
        $venue       = $booking['location_venue'];

        if (empty($booking['client_email'])) return false;

        $subject         = "Reminder: Your Upcoming Event with Tyoy Creation";
        $custom_template = function_exists('get_setting') ? get_setting($conn, 'reminder_template', '') : '';

        if (!empty(trim($custom_template))) {
            $replacements = [
                '{client_name}' => $client_name,
                '{event_title}' => $event_title,
                '{event_date}'  => $event_date,
                '{ref_no}'      => $ref_no,
                '{venue}'       => $venue,
                '{reason}'      => ''
            ];
            $body = str_replace(array_keys($replacements), array_values($replacements), $custom_template);
        } else {
            $body = "Hi {$client_name}!\n\n"
                  . "Your event '{$event_title}' is coming up soon.\n\n"
                  . "Details:\n"
                  . "- Date & Time: {$event_date}\n"
                  . "- Venue: {$venue}\n"
                  . "- Reference No.: {$ref_no}\n\n"
                  . "If you have any last-minute requests, please don't hesitate to reach out.\n\n"
                  . "We look forward to making your event unforgettable!\n"
                  . "Warm regards,\nTyoy Creation Team";
        }

        self::enqueueAndDispatch(
            $conn, (int)$booking_id, $client_name,
            $booking['client_email'], 'email', 'reminder', $subject, $body
        );

        return true;
    }
}
?>
