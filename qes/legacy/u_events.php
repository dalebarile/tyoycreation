<?php
session_start(); // Start session to access user data
include('db.php'); // Database connection

// --------------------------------------
// Access Control: Only users allowed
// --------------------------------------
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'user') {
    header("Location: login.php");
    exit;
}

// Store messages
$message = "";
$message_type = "";

// Fetch logged-in user's ID
$user_id = $_SESSION['id'] ?? null;
if (!$user_id) {
    $user_stmt = $conn->prepare("SELECT id FROM users WHERE username=?");
    $user_stmt->bind_param("s", $_SESSION['username']);
    $user_stmt->execute();
    $user_data = $user_stmt->get_result()->fetch_assoc();
    $user_id = $user_data['id'];
    $_SESSION['id'] = $user_id;
    $user_stmt->close();
}

// Search functionality
$search_query = "";
$search_sql = "";
$status_filter = "";
$current_status = "";

// Handle status filter
if (isset($_GET['status']) && !empty(trim($_GET['status']))) {
    $current_status = $conn->real_escape_string(trim($_GET['status']));
    $status_filter = " AND e.status = '$current_status'";
}

if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $search_query = $conn->real_escape_string(trim($_GET['search']));
    $search_sql = " AND (e.title LIKE '%$search_query%' 
                        OR e.description LIKE '%$search_query%' 
                        OR f.name LIKE '%$search_query%'
                        OR e.status LIKE '%$search_query%')";
}

// -----------------------------
// HANDLE EVENT ACTIONS
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ADD NEW EVENT
    if ($action === 'create') {
        $title = trim($_POST['title']);
        $desc = trim($_POST['description'] ?? '');
        $facility = (int)($_POST['facility'] ?? 0);
        $start = $_POST['start'] ?? '';
        $end = $_POST['end'] ?? '';
        $attendees = (int)($_POST['attendees'] ?? 1);

        // Validate input
        if (empty($title) || strlen($title) < 3) {
            $message = "Event title must be at least 3 characters long!";
            $message_type = "error";
        } elseif (empty($facility)) {
            $message = "Please select a facility!";
            $message_type = "error";
        } elseif (empty($start) || empty($end)) {
            $message = "Please select both start and end times!";
            $message_type = "error";
        } elseif (strtotime($start) < time() - 60) {
            $message = "❌ Cannot schedule events in the past!";
            $message_type = "error";
        } elseif (strtotime($end) <= strtotime($start)) {
            $message = "❌ End time must be after start time!";
            $message_type = "error";
        } elseif ($attendees < 1) {
            $message = "Number of attendees must be at least 1!";
            $message_type = "error";
        } else {
            // Check facility capacity
            $capacity_query = $conn->prepare("SELECT capacity FROM facilities WHERE id = ?");
            $capacity_query->bind_param("i", $facility);
            $capacity_query->execute();
            $capacity_result = $capacity_query->get_result();
            $facility_data = $capacity_result->fetch_assoc();
            
            if ($attendees > $facility_data['capacity']) {
                $message = "❌ Number of attendees ($attendees) exceeds facility capacity ({$facility_data['capacity']})!";
                $message_type = "error";
                $capacity_query->close();
            } else {
                $capacity_query->close();
                
                // Conflict detection (same facility + overlapping time)
                $conflict_query = "
                    SELECT e.*, f.name as facility_name 
                    FROM events e
                    JOIN facilities f ON e.facility_id = f.id
                    WHERE e.facility_id = ?
                    AND e.status IN ('approved', 'pending')
                    AND (e.start_time < ? AND e.end_time > ?)
                ";
                $check = $conn->prepare($conflict_query);
                $check->bind_param("iss", $facility, $end, $start);
                $check->execute();
                $conflicts = $check->get_result();

                if ($conflicts->num_rows > 0) {
                    $conflict = $conflicts->fetch_assoc();
                    $message = "⚠ Conflict detected! '{$conflict['title']}' is already scheduled at {$conflict['facility_name']} from " . 
                              date('M j, Y g:i A', strtotime($conflict['start_time'])) . " to " . 
                              date('M j, Y g:i A', strtotime($conflict['end_time'])) . ".";
                    $message_type = "error";
                } else {
                    // Insert new pending event with attendees count
                    $stmt = $conn->prepare("INSERT INTO events (user_id, facility_id, title, description, attendees_count, start_time, end_time, status, created_at)
                                            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
                    $stmt->bind_param("iississ", $user_id, $facility, $title, $desc, $attendees, $start, $end);
                    if ($stmt->execute()) {
                        $message = "✅ Event '$title' created successfully and is pending admin approval.";
                        $message_type = "success";
                    } else {
                        $message = "❌ Error creating event: " . $conn->error;
                        $message_type = "error";
                    }
                    $stmt->close();
                }
                $check->close();
            }
        }

    // EDIT EVENT (Only if pending + belongs to current user)
    } elseif ($action === 'edit') {
        $event_id = (int)$_POST['event_id'];
        $title = trim($_POST['title']);
        $desc = trim($_POST['description'] ?? '');
        $facility = (int)$_POST['facility'];
        $start = $_POST['start'];
        $end = $_POST['end'];
        $attendees = (int)($_POST['attendees'] ?? 1);

        // Check if event belongs to user and is pending
        $check_stmt = $conn->prepare("SELECT id, title FROM events WHERE id=? AND user_id=? AND status='pending'");
        $check_stmt->bind_param("ii", $event_id, $user_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            $message = "❌ Cannot edit this event. Either it doesn't exist, doesn't belong to you, or is already approved/rejected.";
            $message_type = "error";
        } elseif (empty($start) || empty($end)) {
            $message = "Please select both start and end times!";
            $message_type = "error";
        } elseif (strtotime($start) < time() - 60) {
            $message = "❌ Cannot schedule events in the past!";
            $message_type = "error";
        } elseif (strtotime($end) <= strtotime($start)) {
            $message = "❌ End time must be after start time!";
            $message_type = "error";
        } elseif ($attendees < 1) {
            $message = "Number of attendees must be at least 1!";
            $message_type = "error";
        } else {
            // Check facility capacity
            $capacity_query = $conn->prepare("SELECT capacity FROM facilities WHERE id = ?");
            $capacity_query->bind_param("i", $facility);
            $capacity_query->execute();
            $capacity_result = $capacity_query->get_result();
            $facility_data = $capacity_result->fetch_assoc();
            
            if ($attendees > $facility_data['capacity']) {
                $message = "❌ Number of attendees ($attendees) exceeds facility capacity ({$facility_data['capacity']})!";
                $message_type = "error";
                $capacity_query->close();
            } else {
                $capacity_query->close();
                
                // Conflict detection for edited event
                $conflict_query = "
                    SELECT e.*, f.name as facility_name 
                    FROM events e
                    JOIN facilities f ON e.facility_id = f.id
                    WHERE e.facility_id = ?
                    AND e.id != ?
                    AND e.status IN ('approved', 'pending')
                    AND (e.start_time < ? AND e.end_time > ?)
                ";
                $check = $conn->prepare($conflict_query);
                $check->bind_param("iiss", $facility, $event_id, $end, $start);
                $check->execute();
                $conflicts = $check->get_result();

                if ($conflicts->num_rows > 0) {
                    $conflict = $conflicts->fetch_assoc();
                    $message = "⚠ Conflict detected! '{$conflict['title']}' is already scheduled at {$conflict['facility_name']} from " . 
                              date('M j, Y g:i A', strtotime($conflict['start_time'])) . " to " . 
                              date('M j, Y g:i A', strtotime($conflict['end_time'])) . ".";
                    $message_type = "error";
                } else {
                    $stmt = $conn->prepare("
                        UPDATE events 
                        SET title=?, description=?, attendees_count=?, start_time=?, end_time=?, facility_id=?, status='pending'
                        WHERE id=? AND user_id=? AND status='pending'
                    ");
                    $stmt->bind_param("ssissiii", $title, $desc, $attendees, $start, $end, $facility, $event_id, $user_id);
                    if ($stmt->execute()) {
                        $message = "✏ Event updated and pending re-approval.";
                        $message_type = "success";
                    } else {
                        $message = "❌ Error updating event: " . $conn->error;
                        $message_type = "error";
                    }
                    $stmt->close();
                }
                $check->close();
            }
        }
        $check_stmt->close();

    // DELETE / CANCEL EVENT (Only if owned by user)
    } elseif ($action === 'delete') {
        $event_id = (int)$_POST['event_id'];
        
        // Fetch event data to store in recycling bin
        $fetch_stmt = $conn->prepare("SELECT e.*, f.name AS facility_name FROM events e JOIN facilities f ON e.facility_id = f.id WHERE e.id=? AND e.user_id=?");
        $fetch_stmt->bind_param("ii", $event_id, $user_id);
        $fetch_stmt->execute();
        $event_data = $fetch_stmt->get_result()->fetch_assoc();
        $fetch_stmt->close();
        
        if (!$event_data) {
            $message = "❌ Cannot cancel this event. It doesn't exist or doesn't belong to you.";
            $message_type = "error";
        } else {
            $event_title = $event_data['title'];
            $json = json_encode($event_data);

            // Move to recycling bin
            $trash_stmt = $conn->prepare("INSERT INTO trash (item_type, item_id, item_data, deleted_by, deleted_at) VALUES ('event', ?, ?, ?, NOW())");
            $trash_stmt->bind_param("isi", $event_id, $json, $user_id);
            $trash_stmt->execute();
            $trash_stmt->close();

            $stmt = $conn->prepare("DELETE FROM events WHERE id=? AND user_id=?");
            $stmt->bind_param("ii", $event_id, $user_id);
            if ($stmt->execute()) {
                $message = "🗑 Event '$event_title' has been cancelled and moved to the recycling bin. The booked date has been reset and freed up.";
                $message_type = "success";
            } else {
                $message = "❌ Error cancelling event: " . $conn->error;
                $message_type = "error";
            }
            $stmt->close();
        }
    }
    
    // Store message in session for redirect
    if (!empty($message)) {
        $_SESSION['event_message'] = $message;
        $_SESSION['event_message_type'] = $message_type;
    }
    
    // Redirect to clear POST data (preserve search/filter)
    $redirect_url = "u_events.php";
    if (!empty($current_status)) {
        $redirect_url .= "?status=" . urlencode($current_status);
    }
    if (!empty($search_query)) {
        $redirect_url .= (strpos($redirect_url, '?') !== false ? '&' : '?') . "search=" . urlencode($search_query);
    }
    header("Location: $redirect_url");
    exit;
}

// Check for stored messages
if (isset($_SESSION['event_message'])) {
    $message = $_SESSION['event_message'];
    $message_type = $_SESSION['event_message_type'];
    unset($_SESSION['event_message']);
    unset($_SESSION['event_message_type']);
}

// FETCH USER'S EVENTS ONLY with search and filter
$query = "
SELECT e.*, f.name AS facility_name, f.capacity
FROM events e
JOIN facilities f ON e.facility_id = f.id
WHERE e.user_id = ?
$status_filter
$search_sql
ORDER BY e.created_at DESC
";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

// Get counts for each status for this user
$count_query = "SELECT status, COUNT(*) as count FROM events WHERE user_id = ? GROUP BY status";
$count_stmt = $conn->prepare($count_query);
$count_stmt->bind_param("i", $user_id);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$status_counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$total_user_events = 0;
while ($row = $count_result->fetch_assoc()) {
    $status_counts[$row['status']] = (int)$row['count'];
    $total_user_events += (int)$row['count'];
}
$count_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Your List Event - SCHEDFIX</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@800;900&family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #2955f3;
            min-height: 100vh;
            color: #111;
            position: relative;
            overflow-x: hidden;
        }

        /* -------------------------------------------------------------
           Top Navigation Bar (Matching SCHEDFIX reference design)
        ------------------------------------------------------------- */
        .top-navbar {
            width: 100%;
            max-width: 1260px;
            margin: 0 auto;
            padding: 24px 20px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            z-index: 100;
        }

        .brand-logo-container {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .logo-text {
            font-family: 'Montserrat', 'Outfit', sans-serif;
            font-size: clamp(28px, 3.2vw, 42px);
            font-weight: 900;
            color: #ffffff;
            letter-spacing: 2px;
            text-transform: uppercase;
            line-height: 1;
            text-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }

        .calendar-icon-box {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #0d1a33;
            color: #ffffff;
            border-radius: 10px;
            padding: 6px 7px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
            transition: transform 0.2s ease;
        }

        .calendar-icon-box:hover {
            transform: scale(1.05);
        }

        .calendar-icon-box svg {
            width: clamp(24px, 2.8vw, 34px);
            height: clamp(24px, 2.8vw, 34px);
            display: block;
        }

        /* Icon Navigation Buttons */
        .icon-nav-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .nav-icon-btn {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: #0d2253;
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .nav-icon-btn svg {
            width: 22px;
            height: 22px;
            fill: currentColor;
            stroke: currentColor;
            stroke-width: 0.5;
        }

        .nav-icon-btn.active {
            background: #00a8ff;
            color: #ffffff;
            box-shadow: 0 4px 16px rgba(0, 168, 255, 0.4);
            border-color: rgba(255, 255, 255, 0.3);
            transform: scale(1.04);
        }

        .nav-icon-btn:hover:not(.active) {
            background: #143075;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.25);
        }

        /* -------------------------------------------------------------
           Main Card Container (Matching Reference Mockup with Cyan Frame)
        ------------------------------------------------------------- */
        .dashboard-wrapper {
            width: 100%;
            max-width: 1260px;
            margin: 0 auto;
            padding: 0 20px 40px;
            position: relative;
            z-index: 1;
        }

        .events-card-frame {
            border: 3.5px solid #00d2ff;
            border-radius: 32px;
            padding: 4px;
            background: rgba(0, 210, 255, 0.15);
            box-shadow: 0 20px 50px rgba(10, 30, 85, 0.35);
        }

        .events-card-container {
            background: #ffffff;
            border-radius: 28px;
            padding: 38px 42px 45px;
            width: 100%;
            min-height: 540px;
            position: relative;
        }

        /* Top Header Row (Title on Left, Search & Actions on Right) */
        .events-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .page-title-blue {
            font-family: 'Outfit', 'Montserrat', sans-serif;
            font-size: clamp(20px, 2.2vw, 26px);
            font-weight: 800;
            color: #385bf6;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            line-height: 1.2;
        }

        .header-actions-group {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        /* Search Pill Form */
        .search-pill-form {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-pill-input {
            background: #d5dce6;
            border: 1px solid transparent;
            border-radius: 20px;
            padding: 8px 38px 8px 16px;
            font-size: 14px;
            font-family: inherit;
            color: #1e293b;
            width: 200px;
            transition: all 0.2s ease;
            outline: none;
        }

        .search-pill-input:focus {
            background: #ffffff;
            border-color: #385bf6;
            box-shadow: 0 0 0 3px rgba(56, 91, 246, 0.18);
            width: 240px;
        }

        .search-pill-input::placeholder {
            color: #334155;
            font-size: 14px;
            font-weight: 500;
        }

        .search-pill-btn {
            position: absolute;
            right: 8px;
            background: none;
            border: none;
            cursor: pointer;
            color: #334155;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 4px;
            border-radius: 50%;
            transition: transform 0.2s ease, color 0.2s ease;
        }

        .search-pill-btn:hover {
            color: #385bf6;
            transform: scale(1.1);
        }

        .search-pill-btn svg {
            width: 18px;
            height: 18px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2.2;
        }

        /* Create Event Trigger Button */
        .btn-add-event {
            background: #385bf6;
            color: #ffffff;
            border: none;
            border-radius: 20px;
            padding: 8px 18px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(56, 91, 246, 0.25);
            text-decoration: none;
        }

        .btn-add-event:hover {
            background: #2546e0;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(56, 91, 246, 0.35);
        }

        .btn-add-event svg {
            width: 15px;
            height: 15px;
            fill: currentColor;
        }

        /* -------------------------------------------------------------
           Status Filter Pill Tabs (Matching Reference Design)
        ------------------------------------------------------------- */
        .status-pill-group {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .filter-pill {
            padding: 7px 18px;
            border-radius: 8px;
            background: #d5dce6;
            color: #000000;
            font-size: 13.5px;
            font-weight: 800;
            text-decoration: none;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid transparent;
        }

        .filter-pill:hover {
            background: #c5ceda;
            transform: translateY(-1px);
        }

        .filter-pill.active {
            background: #004bbb;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(0, 75, 187, 0.3);
        }

        .filter-badge {
            background: rgba(255, 255, 255, 0.25);
            padding: 1px 6px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 800;
        }

        .filter-pill:not(.active) .filter-badge {
            background: #bdc7d4;
            color: #000000;
        }

        /* Search active indicator alert */
        .search-feedback-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #eef4ff;
            border-left: 4px solid #385bf6;
            padding: 9px 15px;
            border-radius: 8px;
            font-size: 13px;
            color: #1e3a8a;
            margin-bottom: 18px;
        }

        .search-feedback-bar a {
            color: #385bf6;
            font-weight: 700;
            text-decoration: none;
            margin-left: 10px;
        }

        .search-feedback-bar a:hover {
            text-decoration: underline;
        }

        /* -------------------------------------------------------------
           Data Table & Capsule Header (Matching Mockup)
        ------------------------------------------------------------- */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 12px;
            margin-top: 8px;
        }

        .schedfix-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 14px;
        }

        .schedfix-table thead tr {
            background: #004bbb;
            color: #ffffff;
        }

        .schedfix-table thead th {
            padding: 14px 16px;
            font-weight: 700;
            font-size: 13.5px;
            text-align: left;
            white-space: nowrap;
            letter-spacing: 0.3px;
        }

        /* Rounded corners for the capsule blue table header */
        .schedfix-table thead th:first-child {
            border-top-left-radius: 10px;
            border-bottom-left-radius: 10px;
            padding-left: 20px;
        }

        .schedfix-table thead th:last-child {
            border-top-right-radius: 10px;
            border-bottom-right-radius: 10px;
            padding-right: 20px;
        }

        .schedfix-table tbody tr {
            transition: background 0.15s ease;
            background: #ffffff;
        }

        .schedfix-table tbody tr:hover {
            background: #f8faff;
        }

        .schedfix-table tbody td {
            padding: 14px 16px;
            border-bottom: 1px solid #edf2f7;
            vertical-align: middle;
            color: #1e293b;
        }

        .schedfix-table tbody tr:first-child td {
            padding-top: 18px;
        }

        .schedfix-table tbody td:first-child {
            padding-left: 20px;
        }

        .schedfix-table tbody td:last-child {
            padding-right: 20px;
        }

        /* Table Inputs for Editing */
        .table-input {
            width: 100%;
            padding: 7px 10px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            background: #ffffff;
            color: #111827;
            box-sizing: border-box;
            transition: border-color 0.2s ease;
        }

        .table-input:focus {
            outline: none;
            border-color: #385bf6;
            box-shadow: 0 0 0 2px rgba(56, 91, 246, 0.15);
        }

        .table-input[readonly] {
            background: transparent;
            border: 1px solid transparent;
            padding: 6px 0;
            color: #1e293b;
            font-weight: 500;
            cursor: default;
        }

        .table-input-title {
            font-weight: 600;
            min-width: 140px;
        }

        .table-input-attendees {
            width: 75px;
            text-align: center;
        }

        .table-select {
            width: 100%;
            min-width: 150px;
            padding: 7px 10px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            background: #ffffff;
        }

        .table-select[disabled] {
            background: transparent;
            border: 1px solid transparent;
            padding: 6px 0;
            color: #1e293b;
            font-weight: 500;
            appearance: none;
            -webkit-appearance: none;
        }

        .datetime-display-box {
            font-size: 13px;
            color: #334155;
            line-height: 1.4;
            white-space: nowrap;
        }

        .datetime-display-box strong {
            color: #0f172a;
        }

        /* Status Badges */
        .status-pill-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-pill-badge.approved {
            background: #dcfce7;
            color: #15803d;
        }

        .status-pill-badge.pending {
            background: #fef3c7;
            color: #b45309;
        }

        .status-pill-badge.rejected {
            background: #fee2e2;
            color: #b91c1c;
        }

        /* Actions Buttons */
        .action-btn-group {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .btn-save-row {
            background: #385bf6;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-save-row:hover {
            background: #2546e0;
            transform: translateY(-1px);
        }

        .btn-delete-row {
            background: #ef4444;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-delete-row:hover {
            background: #dc2626;
            transform: translateY(-1px);
        }

        .locked-action-tag {
            font-size: 12px;
            color: #94a3b8;
            font-weight: 600;
            font-style: italic;
        }

        /* Empty State Container */
        .empty-table-state {
            text-align: center;
            padding: 55px 20px;
            color: #64748b;
        }

        .empty-table-state svg {
            width: 48px;
            height: 48px;
            color: #94a3b8;
            margin-bottom: 12px;
        }

        .empty-table-state h4 {
            font-size: 17px;
            color: #1e293b;
            margin-bottom: 6px;
        }

        .empty-table-state p {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 16px;
        }

        /* -------------------------------------------------------------
           Modal Styles for Creating Events
        ------------------------------------------------------------- */
        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(13, 26, 51, 0.6);
            backdrop-filter: blur(4px);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: fadeInModal 0.25s ease forwards;
        }

        .modal-card {
            background: #ffffff;
            border-radius: 24px;
            padding: 34px 38px;
            width: 100%;
            max-width: 580px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
        }

        .modal-title {
            font-family: 'Outfit', sans-serif;
            font-size: 22px;
            font-weight: 800;
            color: #1e293b;
        }

        .modal-close-btn {
            background: #f1f5f9;
            border: none;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            transition: all 0.2s ease;
        }

        .modal-close-btn:hover {
            background: #fee2e2;
            color: #ef4444;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-weight: 700;
            font-size: 13px;
            color: #334155;
            margin-bottom: 6px;
        }

        .form-control {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid #d1d5db;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            color: #111827;
            box-sizing: border-box;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #385bf6;
            box-shadow: 0 0 0 3px rgba(56, 91, 246, 0.15);
        }

        .form-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .capacity-alert-box {
            font-size: 12.5px;
            color: #1e3a8a;
            background: #eff6ff;
            border-left: 3px solid #385bf6;
            padding: 8px 12px;
            border-radius: 6px;
            margin-top: 6px;
            display: none;
        }

        .modal-form-errors {
            background: #fef2f2;
            border: 1px solid #fee2e2;
            color: #b91c1c;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 16px;
            display: none;
        }

        .modal-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 24px;
        }

        .btn-modal-cancel {
            background: #f1f5f9;
            color: #475569;
            border: none;
            border-radius: 10px;
            padding: 11px 20px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-modal-cancel:hover {
            background: #e2e8f0;
        }

        .btn-modal-submit {
            background: #385bf6;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 11px 24px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(56, 91, 246, 0.3);
        }

        .btn-modal-submit:hover {
            background: #2546e0;
            transform: translateY(-1px);
        }

        /* -------------------------------------------------------------
           Alert Notification Popup
        ------------------------------------------------------------- */
        .toast-alert {
            margin-bottom: 20px;
            padding: 14px 18px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
            animation: fadeInAlert 0.3s ease;
        }

        .toast-alert.alert-success {
            background: #dcfce7;
            border: 1px solid #bbf7d0;
            color: #15803d;
        }

        .toast-alert.alert-error {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #b91c1c;
        }

        .toast-alert-close {
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            color: inherit;
            opacity: 0.7;
        }

        @keyframes fadeInAlert {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeInModal {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* -------------------------------------------------------------
           Responsive Media Queries
        ------------------------------------------------------------- */
        @media (max-width: 820px) {
            .events-card-container {
                padding: 26px 20px;
                border-radius: 24px;
            }

            .top-navbar {
                padding: 18px 16px 12px;
            }

            .events-header-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }

            .header-actions-group {
                width: 100%;
                justify-content: space-between;
            }

            .search-pill-input {
                width: 180px;
            }

            .search-pill-input:focus {
                width: 210px;
            }

            .form-row-2 {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .modal-card {
                padding: 24px 20px;
            }
        }
    </style>
</head>
<body>

<!-- Top Navigation Bar -->
<header class="top-navbar">
    <!-- Brand Logo -->
    <a href="u_home.php" class="brand-logo-container" title="SCHEDFIX">
        <span class="logo-text">SCH</span>
        <div class="calendar-icon-box">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <!-- Calendar Body -->
                <rect x="2" y="4" width="20" height="18" rx="4" fill="none" stroke="#ffffff" stroke-width="2"/>
                <!-- Top Binder Rings -->
                <line x1="7" y1="1.5" x2="7" y2="5" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
                <line x1="17" y1="1.5" x2="17" y2="5" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
                <!-- Header Divider -->
                <line x1="2" y1="9" x2="22" y2="9" stroke="#ffffff" stroke-width="1.8"/>
                <!-- Calendar Grid Dots -->
                <circle cx="6.5" cy="13" r="1.2" fill="#ffffff"/>
                <circle cx="12" cy="13" r="1.2" fill="#ffffff"/>
                <circle cx="17.5" cy="13" r="1.2" fill="#ffffff"/>
                <circle cx="6.5" cy="17" r="1.2" fill="#ffffff"/>
                <!-- Checkmark on Calendar -->
                <path d="M10.5 17.5 L12.5 19.5 L18 14" fill="none" stroke="#00e5ff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <span class="logo-text">DFIX</span>
    </a>

    <!-- Top Right 4 Icon Navigation Buttons -->
    <nav class="icon-nav-group" aria-label="Main Navigation">
        <!-- 1. Home Button -->
        <a href="u_home.php" class="nav-icon-btn" title="Home">
            <svg viewBox="0 0 24 24">
                <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/>
            </svg>
        </a>

        <!-- 2. Events / Tasks Clipboard Button (Active) -->
        <a href="u_events.php" class="nav-icon-btn active" title="My Events">
            <svg viewBox="0 0 24 24">
                <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
            </svg>
        </a>


        <!-- 4. Logout Button -->
        <a href="logout.php" class="nav-icon-btn" title="Logout">
            <svg viewBox="0 0 24 24">
                <path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/>
            </svg>
        </a>
    </nav>
</header>

<!-- Main Container Card -->
<main class="dashboard-wrapper">
    <div class="events-card-container">

        <!-- Display Session / Flash Message -->
        <?php if (!empty($message)): ?>
            <div class="toast-alert alert-<?php echo $message_type; ?>" id="toastAlert">
                <span><?php echo htmlspecialchars($message); ?></span>
                <button type="button" class="toast-alert-close" onclick="document.getElementById('toastAlert').style.display='none'">×</button>
            </div>
        <?php endif; ?>

        <!-- Top Header Row -->
        <div class="events-header-row">
            <!-- Title -->
            <h1 class="page-title-blue">VIEW YOUR LIST EVENT</h1>

            <!-- Right Actions: Capsule Search & New Event Button -->
            <div class="header-actions-group">
                <!-- Search Capsule -->
                <form method="GET" action="u_events.php" class="search-pill-form">
                    <?php if (!empty($current_status)): ?>
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($current_status); ?>">
                    <?php endif; ?>
                    <input type="text" name="search" class="search-pill-input" placeholder="search" 
                           value="<?php echo htmlspecialchars($search_query); ?>" aria-label="Search events">
                    <button type="submit" class="search-pill-btn" title="Search">
                        <svg viewBox="0 0 24 24">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                    </button>
                </form>

                <!-- Set Event Button -->
                <button type="button" class="btn-add-event" onclick="openCreateEventModal()">
                    <svg viewBox="0 0 24 24">
                        <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
                    </svg>
                    <span>Set Event</span>
                </button>
            </div>
        </div>

        <!-- Filter Pills Row (All events, Pending, APPROVE, Rejected) -->
        <div class="status-pill-group">
            <?php
            $filters = [
                '' => ['label' => 'All events', 'count' => $total_user_events],
                'pending' => ['label' => 'Pending', 'count' => $status_counts['pending']],
                'approved' => ['label' => 'APPROVE', 'count' => $status_counts['approved']],
                'rejected' => ['label' => 'Rejected', 'count' => $status_counts['rejected']]
            ];

            foreach ($filters as $status_key => $filter_data):
                $is_active = ($current_status === $status_key) || (empty($current_status) && empty($status_key));
                $link_url = "u_events.php" . ($status_key ? "?status=" . $status_key : "");
                if (!empty($search_query)) {
                    $link_url .= (strpos($link_url, '?') !== false ? '&' : '?') . "search=" . urlencode($search_query);
                }
            ?>
                <a href="<?php echo $link_url; ?>" class="filter-pill <?php echo $is_active ? 'active' : ''; ?>">
                    <span><?php echo $filter_data['label']; ?></span>
                    <?php if ($filter_data['count'] > 0): ?>
                        <span class="filter-badge"><?php echo $filter_data['count']; ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Search / Filter Info Notice -->
        <?php if (!empty($search_query) || !empty($current_status)): ?>
            <div class="search-feedback-bar">
                <div>
                    <?php if (!empty($current_status)): ?>
                        Filtering by: <strong><?php echo ucfirst($current_status); ?></strong>
                    <?php endif; ?>
                    <?php if (!empty($search_query)): ?>
                        <?php if (!empty($current_status)): ?> • <?php endif; ?>
                        Search: "<strong><?php echo htmlspecialchars($search_query); ?></strong>"
                    <?php endif; ?>
                </div>
                <a href="u_events.php">Clear Filter ✕</a>
            </div>
        <?php endif; ?>

        <!-- Event List Table -->
        <div class="table-responsive">
            <table class="schedfix-table">
                <thead>
                    <tr>
                        <th style="width: 22%;">Title</th>
                        <th style="width: 11%;">Attendees</th>
                        <th style="width: 20%;">Facilities</th>
                        <th style="width: 12%;">Status</th>
                        <th style="width: 20%;">Start Date & Time</th>
                        <th style="width: 15%;">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): 
                            $is_pending = ($row['status'] === 'pending');
                        ?>
                            <tr>
                                <form method="POST" class="event-row-form">
                                    <!-- 1. Title -->
                                    <td>
                                        <input type="text" name="title" value="<?= htmlspecialchars($row['title']); ?>" 
                                               <?= !$is_pending ? 'readonly' : ''; ?>
                                               class="table-input table-input-title" required minlength="3" maxlength="255">
                                        <?php if (!empty($row['description'])): ?>
                                            <div style="font-size: 11.5px; color: #64748b; margin-top: 3px; max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($row['description']); ?>">
                                                <?= htmlspecialchars($row['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 2. Attendees -->
                                    <td>
                                        <input type="number" name="attendees" min="1" max="1000" 
                                               value="<?= (int)$row['attendees_count']; ?>" 
                                               <?= !$is_pending ? 'readonly' : ''; ?>
                                               class="table-input table-input-attendees" required>
                                    </td>

                                    <!-- 3. Facilities -->
                                    <td>
                                        <select name="facility" <?= !$is_pending ? 'disabled' : ''; ?> class="table-select facility-select" onchange="updateRowCapacityInfo(this)">
                                            <?php
                                            $fac_list = $conn->query("SELECT * FROM facilities ORDER BY name ASC");
                                            while ($f = $fac_list->fetch_assoc()) {
                                                $sel = ($f['id'] == $row['facility_id']) ? 'selected' : '';
                                                echo "<option value='{$f['id']}' data-capacity='{$f['capacity']}' $sel>{$f['name']} (Cap: {$f['capacity']})</option>";
                                            }
                                            ?>
                                        </select>
                                    </td>

                                    <!-- 4. Status -->
                                    <td>
                                        <span class="status-pill-badge <?= htmlspecialchars($row['status']); ?>">
                                            <?= ucfirst($row['status']); ?>
                                        </span>
                                    </td>

                                    <!-- 5. Start Date & Time -->
                                    <td>
                                        <?php if ($is_pending): ?>
                                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                                <input type="datetime-local" name="start" 
                                                       value="<?= date('Y-m-d\TH:i', strtotime($row['start_time'])); ?>" 
                                                       min="<?= date('Y-m-d\TH:i'); ?>"
                                                       class="table-input" required title="Start Time"
                                                       onchange="var endInput = this.parentElement.querySelector('input[name=end]'); if(endInput) endInput.min = this.value;">
                                                <input type="datetime-local" name="end" 
                                                       value="<?= date('Y-m-d\TH:i', strtotime($row['end_time'])); ?>" 
                                                       min="<?= date('Y-m-d\TH:i'); ?>"
                                                       class="table-input" required title="End Time">
                                            </div>
                                        <?php else: ?>
                                            <div class="datetime-display-box">
                                                <strong><?= date('M j, Y', strtotime($row['start_time'])); ?></strong><br>
                                                <span><?= date('g:i A', strtotime($row['start_time'])); ?> - <?= date('g:i A', strtotime($row['end_time'])); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- 6. Actions -->
                                    <td>
                                        <input type="hidden" name="event_id" value="<?= $row['id']; ?>">
                                        <input type="hidden" name="description" value="<?= htmlspecialchars($row['description']); ?>">
                                        <?php if ($is_pending): ?>
                                            <div class="action-btn-group">
                                                <button type="submit" name="action" value="edit" class="btn-save-row" title="Save changes">Save</button>
                                                <button type="submit" name="action" value="delete" class="btn-delete-row" 
                                                        onclick="return confirmDeleteEvent('<?= htmlspecialchars(addslashes($row['title'])); ?>')" title="Delete event">
                                                    Delete
                                                </button>
                                            </div>
                                        <?php elseif (strtotime($row['end_time']) >= time()): ?>
                                            <div class="action-btn-group">
                                                <button type="submit" name="action" value="delete" class="btn-delete-row"
                                                        style="background: #ef4444; color: white;"
                                                        onclick="return confirm('Cancel this booked event? The reserved date and slot will be freed up.')" title="Cancel this booked event">
                                                    Cancel
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <span class="locked-action-tag" style="background:#f1f5f9;color:#94a3b8;border-color:#e2e8f0;">Completed</span>
                                        <?php endif; ?>
                                    </td>
                                </form>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-table-state">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                        <rect x="3" y="4" width="18" height="18" rx="3" ry="3"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                    </svg>
                                    <h4>No Events Found</h4>
                                    <p>
                                        <?php if (!empty($search_query) || !empty($current_status)): ?>
                                            No events match your current filter criteria.
                                        <?php else: ?>
                                            You have not created any events yet.
                                        <?php endif; ?>
                                    </p>
                                    <button type="button" class="btn-add-event" onclick="openCreateEventModal()">
                                        + Set Event
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</main>

<!-- Create Event Modal Form -->
<div class="modal-backdrop" id="createEventModal" role="dialog" aria-modal="true">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="modal-title">Set Event</h3>
            <button type="button" class="modal-close-btn" onclick="closeCreateEventModal()">×</button>
        </div>

        <form method="POST" id="modalEventForm">
            <input type="hidden" name="action" value="create">

            <div class="form-group">
                <label class="form-label">Event Title *</label>
                <input type="text" name="title" class="form-control" placeholder="Enter event title" required minlength="3" maxlength="255">
            </div>

            <div class="form-group">
                <label class="form-label">Description (optional)</label>
                <textarea name="description" class="form-control" placeholder="Enter short event description..." maxlength="500" style="height: 70px; resize: vertical;"></textarea>
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label class="form-label">Number of Attendees *</label>
                    <input type="number" name="attendees" id="modalAttendeesInput" min="1" max="1000" value="1" required class="form-control" oninput="filterModalFacilities()">
                </div>

                <div class="form-group">
                    <label class="form-label">Facility *</label>
                    <select name="facility" id="modalFacilitySelect" required class="form-control" onchange="updateModalCapacityInfo()">
                        <option value="">-- Select a facility --</option>
                        <?php
                        $facilities_modal = $conn->query("SELECT * FROM facilities ORDER BY name ASC");
                        while ($fm = $facilities_modal->fetch_assoc()) {
                            echo "<option value='{$fm['id']}' data-capacity='{$fm['capacity']}'>{$fm['name']} (Cap: {$fm['capacity']})</option>";
                        }
                        ?>
                    </select>
                </div>
            </div>

            <div id="modalCapacityAlert" class="capacity-alert-box"></div>

            <div class="form-row-2">
                <div class="form-group">
                    <label class="form-label">Start Date &amp; Time *</label>
                    <input type="datetime-local" name="start" id="modalStartTime" class="form-control" required min="<?= date('Y-m-d\TH:i'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">End Date &amp; Time *</label>
                    <input type="datetime-local" name="end" id="modalEndTime" class="form-control" required min="<?= date('Y-m-d\TH:i'); ?>">
                </div>
            </div>

            <div id="modalFormErrors" class="modal-form-errors"></div>

            <div class="modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeCreateEventModal()">Cancel</button>
                <button type="submit" id="modalSubmitBtn" class="btn-modal-submit">Set Event</button>
            </div>
        </form>
    </div>
</div>

<script>
// Modal Controls
function openCreateEventModal() {
    const now = new Date();
    const pad = n => String(n).padStart(2, '0');
    const localNow = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
    const later = new Date(now.getTime() + 60*60*1000);
    const localLater = `${later.getFullYear()}-${pad(later.getMonth()+1)}-${pad(later.getDate())}T${pad(later.getHours())}:${pad(later.getMinutes())}`;

    const startInput = document.getElementById('modalStartTime');
    const endInput = document.getElementById('modalEndTime');
    if (startInput) {
        startInput.min = localNow;
        startInput.value = localNow;
    }
    if (endInput) {
        endInput.min = localNow;
        endInput.value = localLater;
    }
    document.getElementById('createEventModal').style.display = 'flex';
    filterModalFacilities();
}

// Sync modal start and end times dynamically
document.addEventListener('DOMContentLoaded', function() {
    const startInput = document.getElementById('modalStartTime');
    const endInput = document.getElementById('modalEndTime');
    if (startInput && endInput) {
        startInput.addEventListener('input', function() {
            if (this.value) {
                endInput.min = this.value;
                if (endInput.value && endInput.value <= this.value) {
                    const d = new Date(this.value);
                    d.setHours(d.getHours() + 1);
                    const pad = n => String(n).padStart(2, '0');
                    endInput.value = `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
                }
            }
        });
    }
});

function closeCreateEventModal() {
    const modal = document.getElementById('createEventModal');
    modal.style.display = 'none';
    const errBox = document.getElementById('modalFormErrors');
    if (errBox) { errBox.style.display = 'none'; errBox.innerHTML = ''; }
    const form = modal.querySelector('form');
    if (form) form.reset();
    const startInput = document.getElementById('modalStartTime');
    const endInput = document.getElementById('modalEndTime');
    if (startInput) startInput.value = '';
    if (endInput) endInput.value = '';
    const capAlert = document.getElementById('modalCapacityAlert');
    if (capAlert) capAlert.style.display = 'none';
}

// Close modal on click outside
window.addEventListener('click', function(e) {
    const modal = document.getElementById('createEventModal');
    if (e.target === modal) {
        closeCreateEventModal();
    }
});

// Capacity Info in Modal
function updateModalCapacityInfo() {
    const sel = document.getElementById('modalFacilitySelect');
    const opt = sel.options[sel.selectedIndex];
    const alertBox = document.getElementById('modalCapacityAlert');
    if (opt && opt.value) {
        const cap = opt.getAttribute('data-capacity');
        alertBox.innerHTML = `🏢 Facility capacity: <strong>${cap}</strong> attendees maximum`;
        alertBox.style.display = 'block';
    } else {
        alertBox.style.display = 'none';
    }
}

// Filter facilities in modal based on attendee count
function filterModalFacilities() {
    const attendeesInput = document.getElementById('modalAttendeesInput');
    const select = document.getElementById('modalFacilitySelect');
    if (!attendeesInput || !select) return;

    const attendees = parseInt(attendeesInput.value) || 0;
    const options = select.querySelectorAll('option');
    let firstVisible = null;

    options.forEach(opt => {
        if (!opt.value) return;
        const cap = parseInt(opt.getAttribute('data-capacity')) || 0;
        if (attendees > 0 && cap < attendees) {
            opt.style.display = 'none';
            if (select.value === opt.value) select.value = '';
        } else {
            opt.style.display = '';
            if (!firstVisible) firstVisible = opt.value;
        }
    });

    updateModalCapacityInfo();
}

// Modal Form Validation
document.getElementById('modalEventForm').addEventListener('submit', function(e) {
    const title = this.querySelector('[name="title"]').value.trim();
    const facility = this.querySelector('[name="facility"]').value;
    const start = this.querySelector('[name="start"]').value;
    const end = this.querySelector('[name="end"]').value;
    const attendees = parseInt(this.querySelector('[name="attendees"]').value) || 1;
    const errorsBox = document.getElementById('modalFormErrors');
    const submitBtn = document.getElementById('modalSubmitBtn');

    let errors = [];
    errorsBox.innerHTML = '';
    errorsBox.style.display = 'none';

    if (title.length < 3) errors.push('Event title must be at least 3 characters');
    if (!facility) errors.push('Please select a facility');

    const sel = document.getElementById('modalFacilitySelect');
    const opt = sel.options[sel.selectedIndex];
    const capacity = opt ? parseInt(opt.getAttribute('data-capacity')) : 0;

    if (attendees < 1) {
        errors.push('Number of attendees must be at least 1');
    } else if (capacity > 0 && attendees > capacity) {
        errors.push(`Number of attendees (${attendees}) exceeds facility capacity (${capacity})`);
    }

    if (!start || !end) {
        errors.push('Please select both start and end times');
    } else {
        const startDate = new Date(start);
        const endDate = new Date(end);
        const now = new Date();

        if (endDate <= startDate) errors.push('End time must be after start time');
        if (startDate < now) errors.push('Cannot schedule events in the past');
    }

    if (errors.length > 0) {
        errorsBox.innerHTML = errors.join('<br>');
        errorsBox.style.display = 'block';
        e.preventDefault();
        return false;
    }

    submitBtn.disabled = true;
    submitBtn.innerHTML = 'Submitting...';
});

// Row Form Validation (for edits)
document.querySelectorAll('.event-row-form').forEach(form => {
    form.addEventListener('submit', function(e) {
        const clickedBtn = document.activeElement;
        if (clickedBtn && clickedBtn.value === 'edit') {
            const title = this.querySelector('[name="title"]').value.trim();
            const start = this.querySelector('[name="start"]')?.value;
            const end = this.querySelector('[name="end"]')?.value;
            const attendees = parseInt(this.querySelector('[name="attendees"]').value) || 1;
            const facilitySelect = this.querySelector('.facility-select');
            const selectedOption = facilitySelect ? facilitySelect.options[facilitySelect.selectedIndex] : null;
            const capacity = selectedOption ? parseInt(selectedOption.getAttribute('data-capacity')) : 0;

            if (title.length < 3) {
                alert('Event title must be at least 3 characters');
                e.preventDefault();
                return false;
            }

            if (attendees < 1) {
                alert('Number of attendees must be at least 1');
                e.preventDefault();
                return false;
            } else if (capacity > 0 && attendees > capacity) {
                alert(`Number of attendees (${attendees}) exceeds facility capacity (${capacity})`);
                e.preventDefault();
                return false;
            }

            if (start && end) {
                const startDate = new Date(start);
                const endDate = new Date(end);
                const now = new Date();
                if (startDate < now) {
                    alert('Cannot schedule events in the past');
                    e.preventDefault();
                    return false;
                }
                if (endDate <= startDate) {
                    alert('End time must be after start time');
                    e.preventDefault();
                    return false;
                }
            }
        }
    });
});

// Delete Confirmation
function confirmDeleteEvent(eventTitle) {
    return confirm(`Are you sure you want to delete event "${eventTitle}"?`);
}

// Auto-hide alert notification
setTimeout(function() {
    const alertBox = document.getElementById('toastAlert');
    if (alertBox) {
        alertBox.style.transition = 'opacity 0.3s ease';
        alertBox.style.opacity = '0';
        setTimeout(() => alertBox.style.display = 'none', 300);
    }
}, 5000);

// Clear query string history on navigation
if (window.history.replaceState) {
    window.history.replaceState(null, null, window.location.href);
}
</script>
<script src="chatbot.js"></script>
</body>
</html>
