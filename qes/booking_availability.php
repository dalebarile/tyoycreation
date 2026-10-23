<?php
/**
 * Tyoy Creation - Public Booking Availability API
 * 
 * Secure, real-time availability endpoint for the customer booking calendar and AI Concierge.
 * Returns only calendar dates that are occupied by approved or completed bookings.
 * 
 * PRIVACY GUARANTEE: Never exposes client names, phone numbers, emails, event titles,
 * or venues (strictly compliant with Republic Act No. 10173 - Data Privacy Act).
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=60'); // Safe 60s cache for fast UX

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking_verification_helper.php';

$request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($request_method !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

// Anti-scraping rate limit: 60 availability checks per 5 minutes per IP
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (function_exists('qes_rate_limit_check')) {
    $rate = qes_rate_limit_check('booking_avail', $client_ip, 60, 300);
    if (!$rate['allowed']) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'code'    => 'RATE_LIMIT_EXCEEDED',
            'message' => 'Rate limit exceeded. Please wait a moment before checking dates again.'
        ]);
        exit;
    }
}

try {
    $requested_date = trim($_GET['date'] ?? '');
    $requested_month = trim($_GET['month'] ?? ''); // Format: YYYY-MM

    // If querying a specific date
    if (!empty($requested_date)) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested_date) || strtotime($requested_date) === false) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid date format. Expected YYYY-MM-DD.'
            ]);
            exit;
        }

        $conflict = BookingVerificationHelper::checkDateConflict($conn, $requested_date);
        $is_available = empty($conflict['conflict']);
        $friendly_date = date('F j, Y', strtotime($requested_date));

        echo json_encode([
            'success'        => true,
            'date'           => $requested_date,
            'formatted_date' => $friendly_date,
            'available'      => $is_available,
            'code'           => $is_available ? 'AVAILABLE' : 'DATE_CONFLICT',
            'message'        => $is_available 
                ? "{$friendly_date} is currently available for booking inquiries." 
                : $conflict['message']
        ]);
        exit;
    }

    // Default / Month query: Fetch all occupied calendar dates (pending, approved, or completed)
    // Extract both timezone-adjusted date and direct string date to cover evening events in Supabase
    $sql = "SELECT DISTINCT DATE(event_start) as booked_date,
                    SUBSTRING(CAST(event_start AS CHAR(10)), 1, 10) as booked_date_direct
            FROM bookings 
            WHERE status IN ('pending', 'approved', 'completed')";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Query preparation failed: " . $conn->error);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $booked_dates = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            foreach (['booked_date', 'booked_date_direct'] as $col) {
                if (!empty($row[$col])) {
                    $dt = substr(trim((string)$row[$col]), 0, 10);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
                        $booked_dates[] = $dt;
                    }
                }
            }
        }
    }
    $stmt->close();

    // If month filter is provided, also provide month-specific list
    $month_booked = [];
    if (!empty($requested_month) && preg_match('/^\d{4}-\d{2}$/', $requested_month)) {
        foreach ($booked_dates as $d) {
            if (str_starts_with($d, $requested_month)) {
                $month_booked[] = $d;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'booked_dates' => array_values(array_unique($booked_dates)),
        'month_booked' => $month_booked,
        'timezone' => 'Asia/Manila',
        'server_time' => date('Y-m-d H:i:s')
    ]);
} catch (\Throwable $e) {
    error_log('[QES Availability] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'booked_dates' => [],
        'message' => 'Unable to retrieve availability schedule. Please try again shortly.'
    ]);
}
