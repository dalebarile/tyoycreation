<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');

// Only allow authenticated staff / admin
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin', 'main_admin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$query = "SELECT id, reference_no, client_name, client_email, client_phone, event_title, event_type, event_start, event_end, guest_count, location_venue, service_requirements, special_notes, status FROM bookings WHERE status = 'approved' ORDER BY event_start ASC";

$result = $conn->query($query);
$events = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row = qes_decrypt_booking($row);
        // Assign color-coding matching the EventVista design mockup
        $type = $row['event_type'];
        $bg_color = '#364735'; // Default brand green
        $border_color = '#2b392a';

        if (stripos($type, 'Wedding') !== false) {
            $bg_color = '#10b981'; // Emerald
            $border_color = '#059669';
        } elseif (stripos($type, 'Birthday') !== false || stripos($type, 'Kids') !== false) {
            $bg_color = '#ec4899'; // Pink
            $border_color = '#db2777';
        } elseif (stripos($type, 'Corporate') !== false) {
            $bg_color = '#3b82f6'; // Blue
            $border_color = '#2563eb';
        } elseif (stripos($type, 'Debut') !== false) {
            $bg_color = '#364735'; // Brand green
            $border_color = '#2b392a';
        }

        $events[] = [
            'id' => $row['id'],
            'title' => $row['event_title'],
            'start' => $row['event_start'],
            'end' => $row['event_end'],
            'backgroundColor' => $bg_color,
            'borderColor' => $border_color,
            'textColor' => '#ffffff',
            'extendedProps' => [
                'ref_no' => $row['reference_no'],
                'client_name' => $row['client_name'],
                'client_email' => $row['client_email'],
                'client_phone' => $row['client_phone'],
                'event_type' => $row['event_type'],
                'venue' => $row['location_venue'],
                'guests' => $row['guest_count'],
                'services' => $row['service_requirements'],
                'notes' => $row['special_notes']
            ]
        ];
    }
}

echo json_encode($events);
?>
