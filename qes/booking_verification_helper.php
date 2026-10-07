<?php
/**
 * QES Two-Step Booking Verification Helper
 * Intercepts public booking submissions from both the Manual Booking Form (booking.php)
 * and the AI Chatbot Concierge (chatbot.php).
 * Temporarily stages the booking payload and verifies the user via a 6-digit OTP email
 * before committing the record to the main `bookings` database table.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_helper.php';

class BookingVerificationHelper {
    const CODE_EXPIRY_SECONDS = 600; // 10 minutes
    const RESEND_COOLDOWN_SECONDS = 60; // 1 minute between resends
    const MAX_VERIFY_ATTEMPTS = 5;

    /**
     * Masks an email address for safe client-side display (e.g. j***n@gmail.com).
     */
    public static function maskEmail(string $email): string {
        $email = trim($email);
        $at = strpos($email, '@');
        if ($at === false) return $email;
        $user = substr($email, 0, $at);
        $domain = substr($email, $at);
        $len = strlen($user);
        if ($len <= 2) {
            $masked_user = substr($user, 0, 1) . '*';
        } else {
            $masked_user = substr($user, 0, 1) . str_repeat('*', max(2, $len - 2)) . substr($user, -1);
        }
        return $masked_user . $domain;
    }

    /**
     * Validates and temporarily stages a booking submission.
     * Generates a 6-digit OTP code and dispatches a verification email.
     * DOES NOT insert into the main database `bookings` table.
     *
     * @param mysqli|SupabaseConnection $conn
     * @param array $params Booking parameters
     * @return array Response payload with requires_verification flag
     */
    public static function stageBooking($conn, array $params): array {
        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        $client_name          = trim($params['client_name'] ?? '');
        $client_email         = trim($params['client_email'] ?? '');
        $client_phone         = trim($params['client_phone'] ?? '');
        $client_address       = trim($params['client_address'] ?? '');
        $event_title          = trim($params['event_title'] ?? '');
        $event_type           = trim($params['event_type'] ?? 'Kids Party');
        $event_date           = trim($params['event_date'] ?? '');
        $event_time           = trim($params['event_time'] ?? '10:00');
        $guest_count          = max(1, (int)($params['guest_count'] ?? 50));
        $location_venue       = trim($params['location_venue'] ?? '');
        $service_requirements = trim($params['service_requirements'] ?? 'Standard Event Styling');
        $special_notes        = trim($params['special_notes'] ?? '');
        $source               = trim($params['source'] ?? 'online_inquiry');
        $user_id              = !empty($params['user_id']) ? (int)$params['user_id'] : null;

        // 1. Required fields
        if (empty($client_name) || empty($client_phone) || empty($event_title) || empty($event_date) || empty($location_venue)) {
            return ['success' => false, 'message' => 'Please fill in all required fields (Name, Contact Number, Event Title, Date, and Venue).'];
        }

        // 2. Strict Email Validation
        if (empty($client_email) || !filter_var($client_email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'A valid Email Address is required to receive your booking verification code.'];
        }

        // 3. Strict Phone Validation (10 to 13 digits)
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
        if (function_exists('validate_event_booking_date')) {
            $dateValidation = validate_event_booking_date($event_type, $event_date);
            if (!$dateValidation['valid']) {
                return ['success' => false, 'message' => $dateValidation['message']];
            }
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

        // 7. Generate secure 6-digit OTP code & verification token
        $code  = sprintf("%06d", random_int(100000, 999999));
        $token = bin2hex(random_bytes(16));
        $masked_email = self::maskEmail($client_email);

        $booking_payload = [
            'user_id'              => $user_id,
            'client_name'          => $client_name,
            'client_email'         => $client_email,
            'client_phone'         => $client_phone,
            'client_address'       => $client_address,
            'event_title'          => $event_title,
            'event_type'           => $event_type,
            'event_date'           => $event_date,
            'event_time'           => $event_time,
            'guest_count'          => $guest_count,
            'location_venue'       => $location_venue,
            'service_requirements' => $service_requirements,
            'special_notes'        => $special_notes,
            'source'               => $source,
            'status'               => 'pending'
        ];

        $summary = [
            'event_title'          => $event_title,
            'event_type'           => $event_type,
            'event_date'           => $event_date,
            'event_time'           => $event_time,
            'guest_count'          => $guest_count,
            'location_venue'       => $location_venue,
            'service_requirements' => $service_requirements,
            'client_name'          => $client_name,
            'client_phone'         => $client_phone,
            'client_email'         => $client_email
        ];

        // 8. Save temporarily in session
        $_SESSION['pending_booking_verification'] = [
            'token'          => $token,
            'code'           => $code,
            'email'          => $client_email,
            'name'           => $client_name,
            'payload'        => $booking_payload,
            'summary'        => $summary,
            'created_at'     => time(),
            'expires_at'     => time() + self::CODE_EXPIRY_SECONDS,
            'attempts'       => 0,
            'last_resend_at' => time()
        ];

        // 9. Dispatch Email with Verification Code & Booking Summary
        $email_sent = NotificationHelper::sendBookingVerificationCodeEmail(
            $conn,
            $client_email,
            $client_name,
            $code,
            $summary
        );

        return [
            'success'               => true,
            'requires_verification' => true,
            'verification_token'    => $token,
            'email'                 => $client_email,
            'masked_email'          => $masked_email,
            'expires_in'            => self::CODE_EXPIRY_SECONDS,
            'email_sent'            => $email_sent,
            'summary'               => $summary,
            'message'               => "A 6-digit verification code has been dispatched to {$client_email}. Please enter it below to confirm your booking."
        ];
    }

    /**
     * Verifies the 6-digit code. If valid, commits the booking to the main `bookings` database table.
     *
     * @param mysqli|SupabaseConnection $conn
     * @param string $code 6-digit user input code
     * @param string|null $token Optional verification token
     * @return array
     */
    public static function verifyBooking($conn, string $code, ?string $token = null): array {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        $pending = $_SESSION['pending_booking_verification'] ?? null;
        if (!$pending || !is_array($pending)) {
            return [
                'success' => false,
                'message' => 'No active pending booking verification session was found. Please re-submit your booking inquiry.'
            ];
        }

        // Validate token if provided
        if ($token !== null && !empty($pending['token']) && !hash_equals($pending['token'], (string)$token)) {
            return [
                'success' => false,
                'message' => 'Security token mismatch for this verification session. Please refresh and try again.'
            ];
        }

        // Check maximum attempt cap
        if (($pending['attempts'] ?? 0) >= self::MAX_VERIFY_ATTEMPTS) {
            unset($_SESSION['pending_booking_verification']);
            return [
                'success' => false,
                'message' => 'Too many failed verification attempts (maximum 5). For your security, this session has been cancelled. Please submit your booking again.'
            ];
        }

        // Check expiration
        if (time() > ($pending['expires_at'] ?? 0)) {
            return [
                'success' => false,
                'message' => 'This verification code has expired (10-minute limit). Please click "Resend Code" to receive a fresh code.'
            ];
        }

        $clean_code = trim($code);
        $expected_code = trim((string)$pending['code']);

        // Check code match
        if (empty($clean_code) || !hash_equals($expected_code, $clean_code)) {
            $_SESSION['pending_booking_verification']['attempts'] = ($pending['attempts'] ?? 0) + 1;
            $remaining = max(0, self::MAX_VERIFY_ATTEMPTS - $_SESSION['pending_booking_verification']['attempts']);
            return [
                'success' => false,
                'message' => "The 6-digit verification code entered is incorrect. Please re-check your email ({$remaining} attempts remaining)."
            ];
        }

        // Code is VALID! Commit to main database table via create_booking_inquiry
        $payload = $pending['payload'];
        $commitResult = create_booking_inquiry($conn, $payload);

        if (!$commitResult['success']) {
            return [
                'success' => false,
                'message' => 'Verification confirmed, but database commit failed: ' . ($commitResult['message'] ?? 'Unknown error.')
            ];
        }

        // Successfully committed! Clear temporary session storage
        unset($_SESSION['pending_booking_verification']);

        return [
            'success'      => true,
            'verified'     => true,
            'booking_id'   => $commitResult['booking_id'] ?? null,
            'reference_no' => $commitResult['reference_no'] ?? null,
            'event_title'  => $commitResult['event_title'] ?? ($payload['event_title'] ?? ''),
            'client_name'  => $commitResult['client_name'] ?? ($payload['client_name'] ?? ''),
            'event_date'   => $commitResult['event_date'] ?? ($payload['event_date'] ?? ''),
            'message'      => 'Booking successfully verified and confirmed! Your reservation has been placed.'
        ];
    }

    /**
     * Resends a fresh 6-digit OTP code to the client email with rate-limiting.
     *
     * @param mysqli|SupabaseConnection $conn
     * @param string|null $token Optional verification token
     * @return array
     */
    public static function resendCode($conn, ?string $token = null): array {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        $pending = $_SESSION['pending_booking_verification'] ?? null;
        if (!$pending || !is_array($pending)) {
            return [
                'success' => false,
                'message' => 'No active pending booking verification session was found. Please re-submit your booking inquiry.'
            ];
        }

        // Cooldown check: 60 seconds
        $last_resend = (int)($pending['last_resend_at'] ?? 0);
        $elapsed = time() - $last_resend;
        if ($elapsed < self::RESEND_COOLDOWN_SECONDS) {
            $wait = self::RESEND_COOLDOWN_SECONDS - $elapsed;
            return [
                'success' => false,
                'message' => "Please wait {$wait} seconds before requesting another verification code."
            ];
        }

        // Generate fresh 6-digit code
        $new_code = sprintf("%06d", random_int(100000, 999999));
        $_SESSION['pending_booking_verification']['code']           = $new_code;
        $_SESSION['pending_booking_verification']['expires_at']     = time() + self::CODE_EXPIRY_SECONDS;
        $_SESSION['pending_booking_verification']['last_resend_at'] = time();
        $_SESSION['pending_booking_verification']['attempts']       = 0; // reset attempts for fresh code

        $masked_email = self::maskEmail($pending['email']);

        $sent = NotificationHelper::sendBookingVerificationCodeEmail(
            $conn,
            $pending['email'],
            $pending['name'],
            $new_code,
            $pending['summary'] ?? []
        );

        return [
            'success'      => true,
            'message'      => "A fresh 6-digit verification code has been dispatched to {$pending['email']}.",
            'email'        => $pending['email'],
            'masked_email' => $masked_email,
            'expires_in'   => self::CODE_EXPIRY_SECONDS
        ];
    }

    /**
     * Updates the recipient email for a pending booking and sends a fresh OTP verification code.
     * Prevents user misconception or lock-out when a letter is missing or mistyped in the email address.
     *
     * @param mysqli|SupabaseConnection $conn
     * @param string $new_email
     * @param string|null $token
     * @return array
     */
    public static function updateEmail($conn, string $new_email, ?string $token = null): array {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        $pending = $_SESSION['pending_booking_verification'] ?? null;
        if (!$pending || !is_array($pending)) {
            return [
                'success' => false,
                'message' => 'No active pending verification session found. Please re-submit your booking.'
            ];
        }

        if ($token && !empty($pending['token']) && !hash_equals($pending['token'], $token)) {
            return [
                'success' => false,
                'message' => 'Verification token mismatch or expired.'
            ];
        }

        $new_email = trim($new_email);
        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Please enter a valid email address (e.g. name@gmail.com).'
            ];
        }

        // Anti-abuse rate limit: 10 seconds between email updates
        $last_resend = (int)($pending['last_resend_at'] ?? 0);
        $elapsed = time() - $last_resend;
        if ($elapsed < 10) {
            $wait = 10 - $elapsed;
            return [
                'success' => false,
                'message' => "Please wait {$wait} seconds before updating your email again."
            ];
        }

        // Generate fresh 6-digit code
        $new_code = sprintf("%06d", random_int(100000, 999999));

        // Update pending session state
        $_SESSION['pending_booking_verification']['email']          = $new_email;
        if (isset($_SESSION['pending_booking_verification']['payload']) && is_array($_SESSION['pending_booking_verification']['payload'])) {
            $_SESSION['pending_booking_verification']['payload']['client_email'] = $new_email;
        }
        if (isset($_SESSION['pending_booking_verification']['summary']) && is_array($_SESSION['pending_booking_verification']['summary'])) {
            $_SESSION['pending_booking_verification']['summary']['client_email'] = $new_email;
        }
        $_SESSION['pending_booking_verification']['code']           = $new_code;
        $_SESSION['pending_booking_verification']['expires_at']     = time() + self::CODE_EXPIRY_SECONDS;
        $_SESSION['pending_booking_verification']['last_resend_at'] = time();
        $_SESSION['pending_booking_verification']['attempts']       = 0;

        $masked_email = self::maskEmail($new_email);

        $sent = NotificationHelper::sendBookingVerificationCodeEmail(
            $conn,
            $new_email,
            $pending['name'],
            $new_code,
            $_SESSION['pending_booking_verification']['summary'] ?? []
        );

        return [
            'success'      => true,
            'message'      => "Verification code has been successfully dispatched to your corrected email: {$new_email}.",
            'email'        => $new_email,
            'masked_email' => $masked_email,
            'email_sent'   => $sent,
            'expires_in'   => self::CODE_EXPIRY_SECONDS
        ];
    }

    /**
     * Gets current pending status for active session.
     */
    public static function getPendingStatus(): ?array {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        $pending = $_SESSION['pending_booking_verification'] ?? null;
        if (!$pending || !is_array($pending)) return null;

        $remaining_secs = max(0, ($pending['expires_at'] ?? 0) - time());
        if ($remaining_secs <= 0) return null;

        return [
            'active'       => true,
            'token'        => $pending['token'] ?? '',
            'email'        => $pending['email'] ?? '',
            'masked_email' => self::maskEmail($pending['email'] ?? ''),
            'expires_in'   => $remaining_secs,
            'summary'      => $pending['summary'] ?? []
        ];
    }
}
