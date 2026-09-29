
<?php
session_start();
include('db.php');

// --------------------------------------
// Access Control: Allow only logged-in users
// --------------------------------------
if (!isset($_SESSION['username'])) {
    exit("Unauthorized access.");
}

// Gather form input
$title       = $_POST['title'];
$start       = $_POST['start'];
$end         = $_POST['end'];
$facility_id = (int)$_POST['facility']; // ✅ Cast to int
$desc        = $_POST['description'] ?? '';
$attendees   = (int)$_POST['attendees'] ?? 1; // ✅ NEW: Get attendees count
$user_id     = $_SESSION['id'] ?? null;

// Validate attendees count
if ($attendees < 1) {
    echo "❌ Attendee count must be at least 1.";
    exit;
}

// Validate date & time: cannot schedule in the past
if (empty($start) || empty($end)) {
    echo "❌ Please select both start and end times.";
    exit;
}
if (strtotime($start) < time() - 60) {
    echo "❌ Cannot schedule events in the past.";
    exit;
}
if (strtotime($end) <= strtotime($start)) {
    echo "❌ End time must be after start time.";
    exit;
}

// If session lacks user ID, fetch from DB
if (!$user_id) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE username=?");
    $stmt->bind_param("s", $_SESSION['username']);
    $stmt->execute();
    $user_id = $stmt->get_result()->fetch_assoc()['id'];
    $_SESSION['id'] = $user_id;
}

// --------------------------------------
// 1️⃣ Get facility capacity
// --------------------------------------
$capacity_query = $conn->prepare("SELECT capacity FROM facilities WHERE id = ?");
$capacity_query->bind_param("i", $facility_id);
$capacity_query->execute();
$capacity_result = $capacity_query->get_result();
$facility = $capacity_result->fetch_assoc();

if (!$facility) {
    echo "❌ Facility not found.";
    exit;
}

$facility_capacity = $facility['capacity'];

// Check if attendees exceed capacity
if ($attendees > $facility_capacity) {
    echo "❌ Attendee count ($attendees) exceeds facility capacity ($facility_capacity).";
    exit;
}

// --------------------------------------
// 2️⃣ Check for Conflicting Events (FIXED)
// --------------------------------------
$conflictQuery = $conn->prepare("
    SELECT e.*, f.name AS facility_name
    FROM events e
    JOIN facilities f ON e.facility_id = f.id
    WHERE e.facility_id = ?
      AND e.status = 'approved'
      AND (e.start_time < ? AND e.end_time > ?)
");
$conflictQuery->bind_param("iss", $facility_id, $end, $start);
$conflictQuery->execute();
$conflictResult = $conflictQuery->get_result();

if ($conflictResult->num_rows > 0) {
    // Conflict found → suggest an alternative
    $conflict = $conflictResult->fetch_assoc();

    // ✅ FIXED: Use prepared statement for alternative facility
    $altStmt = $conn->prepare("SELECT name FROM facilities WHERE id != ? LIMIT 1");
    $altStmt->bind_param("i", $facility_id);
    $altStmt->execute();
    $altResult = $altStmt->get_result();
    $altFacility = $altResult->fetch_assoc()['name'] ?? 'another facility';
    $altStmt->close();

    $suggestedTime = date('Y-m-d H:i:s', strtotime($end . ' +1 hour'));

    echo "⚠️ Conflict detected with '{$conflict['title']}' at '{$conflict['facility_name']}'.\n";
    echo "Suggested: Try $altFacility or start at $suggestedTime instead.";
    exit;
}

// --------------------------------------
// 3️⃣ Determine Event Status (auto-approve for admin)
// --------------------------------------
$status = ($_SESSION['role'] === 'admin') ? 'approved' : 'pending';

// --------------------------------------
// 4️⃣ Insert New Event with attendees count
// --------------------------------------
$stmt = $conn->prepare("
    INSERT INTO events (user_id, facility_id, title, description, attendees_count, start_time, end_time, status, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
");
// The parameters are: user_id, facility_id, title, description, attendees_count, start_time, end_time, status
// So we need 8 parameters, not 9 (because NOW() is a MySQL function)
$stmt->bind_param("iississs", $user_id, $facility_id, $title, $desc, $attendees, $start, $end, $status);

if ($stmt->execute()) {
    echo ($_SESSION['role'] === 'admin')
        ? "✅ Event added successfully and automatically approved."
        : "✅ Event submitted! Waiting for admin approval.";
} else {
    echo "❌ Error adding event: " . $stmt->error;
}
?>
