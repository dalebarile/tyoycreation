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

$request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($request_method !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
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

        $conflict = check_booking_conflict($conn, $requested_date);
        $is_available = empty($conflict['conflict']);
        $friendly_date = date('F j, Y', strtotime($requested_date));

        echo json_encode([
            'success' => true,
            'date' => $requested_date,
            'formatted_date' => $friendly_date,
            'available' => $is_available,
            'message' => $is_available
                ? "{$friendly_date} is currently available for booking inquiries."
                : "{$friendly_date} is already fully booked. Please select another date."
        ]);
        exit;
    }

    // Default / Month query: Fetch all occupied calendar dates (approved or completed)
    $sql = "SELECT DISTINCT DATE(event_start) as booked_date 
            FROM bookings 
            WHERE (status = 'approved' OR status = 'completed') 
            ORDER BY booked_date ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Query preparation failed: " . $conn->error);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $booked_dates = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['booked_date'])) {
                $dt = substr(trim((string)$row['booked_date']), 0, 10);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
                    $booked_dates[] = $dt;
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
