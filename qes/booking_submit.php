<?php
// Endpoint for public booking inquiry submissions
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// CSRF protection — validate token sent from the booking form
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh the page and try again.']);
    exit;
}

$client_name = trim($_POST['client_name'] ?? '');
$client_email = trim($_POST['client_email'] ?? '');
$client_phone = trim($_POST['client_phone'] ?? '');
$client_address = trim($_POST['client_address'] ?? '');

$event_title = trim($_POST['event_title'] ?? '');
$event_type = trim($_POST['event_type'] ?? 'Weddings');
$event_date = trim($_POST['event_date'] ?? '');
$event_time = trim($_POST['event_time'] ?? '10:00');
$guest_count = (int)($_POST['guest_count'] ?? 50);
$location_venue = trim($_POST['location_venue'] ?? '');

// Service requirements: accept the assembled hidden string from Step 3
// (falls back to legacy array format for backward compatibility)
$raw_requirements = $_POST['service_requirements'] ?? '';
if (is_array($raw_requirements)) {
    // Legacy: old checkbox array format
    $service_requirements = implode('; ', $raw_requirements);
} else {
    $service_requirements = trim($raw_requirements);
}

// Estimated total (sent as hidden field from the JS assembler)
$estimated_total = (int)($_POST['estimated_total'] ?? 0);

if (empty($service_requirements)) {
    $service_requirements = 'Standard Consultation & Custom Event Styling';
}

// Ensure the ESTIMATED TOTAL tag is always clearly present for admin visibility
if (strpos($service_requirements, 'ESTIMATED TOTAL') === false) {
    $peso = '₱' . number_format($estimated_total);
    $service_requirements .= ' | ESTIMATED TOTAL: ' . $peso;
}


$special_notes = trim($_POST['special_notes'] ?? '');


// User ID is optional - clients do NOT need an account or login to submit a booking
$user_id = !empty($_SESSION['id']) ? (int)$_SESSION['id'] : null;

// Validation
if (empty($client_name) || empty($client_email) || empty($client_phone) || empty($event_title) || empty($event_date) || empty($location_venue)) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
    exit;
}

// 1. Strict Name Validation: must be letters, spaces, hyphens/periods, minimum 2 characters
if (strlen($client_name) < 2 || !preg_match("/^[a-zA-Z\s\.\-']+$/", $client_name)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid Full Name (letters and spaces only).']);
    exit;
}

// 2. Strict Email Validation: must be a valid email format (e.g. user@domain.com)
if (!filter_var($client_email, FILTER_VALIDATE_EMAIL) || !preg_match("/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/", $client_email)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid Email Address (e.g. name@gmail.com).']);
    exit;
}

// 3. Strict Phone / Contact Number Validation: cannot contain letters, must have valid phone digits
$clean_phone = preg_replace('/[^0-9]/', '', $client_phone);
if (preg_match('/[a-zA-Z]/', $client_phone) || strlen($clean_phone) < 10 || strlen($clean_phone) > 13) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid Contact Number with 10 to 12 digits (e.g. 0917-123-4567).']);
    exit;
}

// 4. Event Title & Venue Minimum Length
if (strlen($event_title) < 3) {
    echo json_encode(['success' => false, 'message' => 'Event Title must be at least 3 characters.']);
    exit;
}
if (strlen($location_venue) < 3) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid Event Venue or Location.']);
    exit;
}

// Server-side Dynamic Lead-Time Validation per Event Type
$dateValidation = validate_event_booking_date($event_type, $event_date);
if (!$dateValidation['valid']) {
    echo json_encode([
        'success' => false,
        'message' => $dateValidation['message']
    ]);
    exit;
}

// Server-side Guest Count against Catering Service Capacity
if (preg_match('/Catering[^:]*:\s*(\d+)\s*pax/i', $service_requirements, $cat_match)) {
    $catering_pax = (int)$cat_match[1];
    if ($guest_count > $catering_pax) {
        echo json_encode([
            'success' => false,
            'message' => "Cannot proceed: Expected guest count ({$guest_count}) exceeds your selected Catering Service package limit ({$catering_pax} pax). Please adjust your guest count or choose a higher package."
        ]);
        exit;
    }
}

// Generate unique reference number: EV-YEAR-XXXX
$year = date('Y');
$rand = str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
$reference_no = "EV-{$year}-{$rand}";

// Check for uniqueness
$check = $conn->prepare("SELECT id FROM bookings WHERE reference_no = ?");
$check->bind_param("s", $reference_no);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    $reference_no = "EV-{$year}-" . str_pad(mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT);
}
$check->close();

$event_start = date('Y-m-d H:i:s', strtotime("{$event_date} {$event_time}"));
$event_end = date('Y-m-d H:i:s', strtotime("{$event_date} {$event_time} + 5 hours"));

$stmt = $conn->prepare("INSERT INTO bookings (user_id, reference_no, client_name, client_email, client_phone, client_address, event_title, event_type, event_start, event_end, guest_count, location_venue, service_requirements, special_notes, status, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'online_inquiry')");

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit;
}

$enc_client_name    = qes_encrypt($client_name, false);
$enc_client_email   = qes_encrypt($client_email, true);
$enc_client_phone   = qes_encrypt($client_phone, true);
$enc_client_address = qes_encrypt($client_address, false);

$stmt->bind_param("issssssssissss", 
    $user_id,
    $reference_no, 
    $enc_client_name, 
    $enc_client_email, 
    $enc_client_phone, 
    $enc_client_address, 
    $event_title, 
    $event_type, 
    $event_start, 
    $event_end, 
    $guest_count, 
    $location_venue, 
    $service_requirements, 
    $special_notes
);

if ($stmt->execute()) {
    $new_booking_id = (int)($stmt->insert_id ?? 0);
    if ($new_booking_id <= 0) {
        $new_booking_id = (int)($conn->insert_id ?? 0);
    }
    if ($new_booking_id <= 0) {
        $chk = $conn->prepare("SELECT id FROM bookings WHERE reference_no = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param("s", $reference_no);
            $chk->execute();
            $chk_res = $chk->get_result();
            if ($chk_res && ($row = $chk_res->fetch_assoc())) {
                $new_booking_id = (int)($row['id'] ?? 0);
            }
            $chk->close();
        }
    }

    // Automatically send inquiry received confirmation notice to client's email
    if ($new_booking_id > 0) {
        require_once __DIR__ . '/notification_helper.php';
        try {
            NotificationHelper::sendInquiryReceivedNotice($conn, $new_booking_id);
        } catch (\Throwable $e) {
            error_log("[QES Booking] Error dispatching inquiry email: " . $e->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'reference_no' => $reference_no,
        'client_name' => $client_name,
        'event_title' => $event_title,
        'event_date' => date('F j, Y', strtotime($event_date))
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Could not save booking: ' . $stmt->error]);
}
$stmt->close();
?>
