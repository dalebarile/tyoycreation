<?php
// ============================================================
// Two-Step Public Booking Submission & Verification Endpoint
// Intercepts direct insertion into main database table.
// Stages booking in temporary session, triggers 6-digit OTP email,
// and commits to main database ONLY upon successful code validation.
// ============================================================
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking_verification_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$action = trim($_POST['action'] ?? ($_GET['action'] ?? 'submit'));
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// Handle GET status check
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'status') {
    $status = BookingVerificationHelper::getPendingStatus();
    echo json_encode(['success' => true, 'pending' => $status]);
    exit;
}

// CSRF protection for all POST requests
$csrf = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh the page and try again.']);
    exit;
}

// ============================================================
// ACTION 1: VERIFY OTP CODE & COMMIT TO DATABASE
// ============================================================
if ($action === 'verify') {
    // Cloudflare Turnstile Security Verification
    if (qes_is_turnstile_enabled($conn)) {
        $turnstile_token = trim($_POST['cf-turnstile-response'] ?? ($_POST['turnstile_token'] ?? ''));
        $ts_check = qes_verify_turnstile($turnstile_token, $client_ip, $conn);
        if (!$ts_check['success']) {
            echo json_encode([
                'success' => false,
                'message' => $ts_check['error'] ?? 'Cloudflare Turnstile security verification failed. Please complete the security check.'
            ]);
            exit;
        }
    }

    // Rate limit verification attempts: 15 per 10 minutes per IP
    $rate = qes_rate_limit_check('booking_verify', $client_ip, 15, 600);
    if (!$rate['allowed']) {
        echo json_encode([
            'success' => false,
            'message' => "Too many verification attempts from this network. Please wait {$rate['wait_seconds']} seconds."
        ]);
        exit;
    }

    $code  = trim($_POST['code'] ?? '');
    $token = trim($_POST['verification_token'] ?? ($_POST['token'] ?? ''));

    if (empty($code)) {
        echo json_encode(['success' => false, 'message' => 'Please enter the 6-digit verification code.']);
        exit;
    }

    $res = BookingVerificationHelper::verifyBooking($conn, $code, $token ?: null);
    if ($res['success']) {
        qes_rate_limit_clear('booking_verify', $client_ip);
    } else {
        qes_rate_limit_record_fail('booking_verify', $client_ip, 15, 600);
    }

    echo json_encode($res);
    exit;
}

// ============================================================
// ACTION 2: RESEND VERIFICATION CODE
// ============================================================
if ($action === 'resend') {
    // Rate limit resend requests: 5 per 10 minutes per IP
    $rate = qes_rate_limit_check('booking_resend', $client_ip, 5, 600);
    if (!$rate['allowed']) {
        echo json_encode([
            'success' => false,
            'message' => "Too many resend requests. Please wait {$rate['wait_seconds']} seconds."
        ]);
        exit;
    }

    $token = trim($_POST['verification_token'] ?? ($_POST['token'] ?? ''));
    $res = BookingVerificationHelper::resendCode($conn, $token ?: null);
    echo json_encode($res);
    exit;
}

// ============================================================
// ACTION 2.5: UPDATE EMAIL & RESEND OTP CODE
// ============================================================
if ($action === 'update_email') {
    // Rate limit email update requests: 6 per 10 minutes per IP
    $rate = qes_rate_limit_check('booking_update_email', $client_ip, 6, 600);
    if (!$rate['allowed']) {
        echo json_encode([
            'success' => false,
            'message' => "Too many email update attempts. Please wait {$rate['wait_seconds']} seconds."
        ]);
        exit;
    }

    $new_email = trim($_POST['new_email'] ?? ($_POST['client_email'] ?? ''));
    $token     = trim($_POST['verification_token'] ?? ($_POST['token'] ?? ''));

    if (empty($new_email) || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please provide a valid email address (e.g. name@gmail.com).']);
        exit;
    }

    $res = BookingVerificationHelper::updateEmail($conn, $new_email, $token ?: null);
    echo json_encode($res);
    exit;
}

// ============================================================
// ACTION 3: SUBMIT BOOKING (STAGE & SEND OTP CODE)
// ============================================================
if ($action === 'submit') {
    // Cloudflare Turnstile Verification if submitted from form
    if (qes_is_turnstile_enabled($conn) && !empty($_POST['cf-turnstile-response'])) {
        $ts_token = trim($_POST['cf-turnstile-response'] ?? '');
        $ts_check = qes_verify_turnstile($ts_token, $client_ip, $conn);
        if (!$ts_check['success']) {
            echo json_encode([
                'success' => false,
                'message' => $ts_check['error'] ?? 'Cloudflare Turnstile security verification failed.'
            ]);
            exit;
        }
    }

    // Rate limit booking submissions: 6 per 10 minutes per IP
    $rate = qes_rate_limit_check('booking_submit', $client_ip, 6, 600);
    if (!$rate['allowed']) {
        echo json_encode([
            'success' => false,
            'message' => "Too many booking submissions. Please wait {$rate['wait_seconds']} seconds before submitting another request."
        ]);
        exit;
    }

    $client_name    = trim($_POST['client_name'] ?? '');
    $client_email   = trim($_POST['client_email'] ?? '');
    $client_phone   = preg_replace('/[^0-9]/', '', trim($_POST['client_phone'] ?? ''));
    $client_address = trim($_POST['client_address'] ?? '');

    $event_title    = trim($_POST['event_title'] ?? '');
    $event_type     = trim($_POST['event_type'] ?? 'Kids Party');
    $event_date     = trim($_POST['event_date'] ?? '');
    $event_time     = trim($_POST['event_time'] ?? '10:00');
    $guest_count    = (int)($_POST['guest_count'] ?? 50);
    $location_venue = trim($_POST['location_venue'] ?? '');

    // Service requirements: accept assembled hidden string
    $raw_requirements = $_POST['service_requirements'] ?? '';
    if (is_array($raw_requirements)) {
        $service_requirements = implode('; ', $raw_requirements);
    } else {
        $service_requirements = trim($raw_requirements);
    }

    // Estimated total (sent as hidden field from JS assembler)
    $estimated_total = (int)($_POST['estimated_total'] ?? 0);

    if (empty($service_requirements)) {
        $service_requirements = 'Standard Consultation & Custom Event Styling';
    }

    // Ensure ESTIMATED TOTAL tag is clearly present
    if (strpos($service_requirements, 'ESTIMATED TOTAL') === false && $estimated_total > 0) {
        $peso = '₱' . number_format($estimated_total);
        $service_requirements .= ' | ESTIMATED TOTAL: ' . $peso;
    }

    $special_notes = trim($_POST['special_notes'] ?? '');
    $user_id = !empty($_SESSION['id']) ? (int)$_SESSION['id'] : null;

    // Stage booking payload and dispatch verification code email
    $result = BookingVerificationHelper::stageBooking($conn, [
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
        'source'               => 'online_inquiry'
    ]);

    if (!$result['success']) {
        qes_rate_limit_record_fail('booking_submit', $client_ip, 6, 600);
    }

    echo json_encode($result);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action requested.']);
exit;
