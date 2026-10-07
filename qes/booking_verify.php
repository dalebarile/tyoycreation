<?php
// ============================================================
// Dedicated Public Verification Endpoint for Bookings
// Accepts OTP code submission and commits verified booking to DB.
// ============================================================
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking_verification_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$action = trim($_POST['action'] ?? ($_GET['action'] ?? 'verify'));
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// Handle GET status check
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'status') {
    $status = BookingVerificationHelper::getPendingStatus();
    echo json_encode(['success' => true, 'pending' => $status]);
    exit;
}

// CSRF check
$csrf = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh and try again.']);
    exit;
}

if ($action === 'resend') {
    $rate = qes_rate_limit_check('booking_resend', $client_ip, 5, 600);
    if (!$rate['allowed']) {
        echo json_encode(['success' => false, 'message' => "Too many resend requests. Please wait {$rate['wait_seconds']} seconds."]);
        exit;
    }
    $token = trim($_POST['verification_token'] ?? ($_POST['token'] ?? ''));
    $res = BookingVerificationHelper::resendCode($conn, $token ?: null);
    echo json_encode($res);
    exit;
}

// Default: verify code
$rate = qes_rate_limit_check('booking_verify', $client_ip, 15, 600);
if (!$rate['allowed']) {
    echo json_encode(['success' => false, 'message' => "Too many verification attempts. Please wait {$rate['wait_seconds']} seconds."]);
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
