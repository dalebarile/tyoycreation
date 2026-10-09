<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_helper.php';

// Auth check
require_login();

$admin_username = $_SESSION['username'] ?? 'Admin';
$status_filter = $_GET['status'] ?? 'pending';
$search = trim($_GET['search'] ?? '');

$alert_message = '';
$alert_type = 'success';

// Auto-purge: permanently delete rejected bookings older than 7 days (throttled to once daily)
$now_ts = time();
if (($now_ts - ($_SESSION['last_rejected_purge_at'] ?? 0)) > 86400) {
    $_SESSION['last_rejected_purge_at'] = $now_ts;
    $conn->query("DELETE FROM bookings WHERE status = 'rejected' AND updated_at < (NOW() - INTERVAL '7 DAY')");
}

// Status counts for navigation tabs (Requirement 5: Unified Bookings tab)
$status_counts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'bookings' => 0];
$c_res = $conn->query("SELECT status, COUNT(*) as cnt FROM bookings GROUP BY status");
if ($c_res) {
    while ($r = $c_res->fetch_assoc()) {
        $st = $r['status'] ?? '';
        $cnt = (int)($r['cnt'] ?? 0);
        if (isset($status_counts[$st])) {
            $status_counts[$st] = $cnt;
        }
        $status_counts['all'] += $cnt;
    }
}
$status_counts['bookings'] = $status_counts['approved'] + $status_counts['rejected'];


// Handle Actions (POST)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $action = $_POST['action'] ?? '';
    $booking_id = (int)($_POST['booking_id'] ?? 0);

    if ($action === 'approve' && $booking_id > 0) {
        $result = update_booking_status($conn, $booking_id, 'approve');
        $alert_message = $result['message'];
        $alert_type = $result['success'] ? 'success' : 'warning';
    } elseif ($action === 'reject' && $booking_id > 0) {
        $reason = trim($_POST['rejection_reason'] ?? 'Requested schedule or venue is unavailable.');
        $result = update_booking_status($conn, $booking_id, 'reject', $reason);
        $alert_message = $result['message'];
        $alert_type = 'warning';
    } elseif ($action === 'delete_rejected' && $booking_id > 0) {
        // Only allow permanent deletion of rejected bookings
        $stmt = $conn->prepare("DELETE FROM bookings WHERE id = ? AND status = 'rejected'");
        $stmt->bind_param("i", $booking_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $alert_message = "Rejected booking #{$booking_id} has been permanently deleted from the system.";
            $alert_type = 'warning';
        } else {
            $alert_message = "Could not delete booking. Only rejected bookings can be permanently deleted.";
            $alert_type = 'warning';
        }
        $stmt->close();
    }
}

// Build query
$where_clauses = [];
$params = [];
$types = "";

$sub_filter = $_GET['sub'] ?? '';

if ($status_filter === 'bookings') {
    if ($sub_filter === 'approved') {
        $where_clauses[] = "status = ?";
        $params[] = 'approved';
        $types .= "s";
    } elseif ($sub_filter === 'rejected') {
        $where_clauses[] = "status = ?";
        $params[] = 'rejected';
        $types .= "s";
    } else {
        $where_clauses[] = "status IN ('approved', 'rejected')";
    }
} elseif ($status_filter !== 'all' && in_array($status_filter, ['pending', 'approved', 'rejected'])) {
    $where_clauses[] = "status = ?";
    $params[] = $status_filter;
    $types .= "s";
}


$per_page = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$bookings = [];

if (!empty($search)) {
    // When searching, fetch records for current status filter, decrypt client PII, and search in-memory
    $sql = "SELECT * FROM bookings";
    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(" AND ", $where_clauses);
    }
    $sql .= " ORDER BY created_at DESC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $matched = [];
    if ($result) {
        $searchLower = mb_strtolower($search);
        while ($row = $result->fetch_assoc()) {
            $row = qes_decrypt_booking($row);
            $haystack = mb_strtolower(
                ($row['client_name'] ?? '') . ' ' .
                ($row['client_phone'] ?? '') . ' ' .
                ($row['client_email'] ?? '') . ' ' .
                ($row['client_address'] ?? '') . ' ' .
                ($row['event_title'] ?? '') . ' ' .
                ($row['event_type'] ?? '') . ' ' .
                ($row['reference_no'] ?? '') . ' ' .
                ($row['location_venue'] ?? '')
            );
            if (mb_stripos($haystack, $searchLower) !== false) {
                $matched[] = $row;
            }
        }
    }
    $stmt->close();

    $total_records = count($matched);
    $total_pages = max(1, (int)ceil($total_records / $per_page));
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset = max(0, ($page - 1) * $per_page);
    $bookings = array_slice($matched, $offset, $per_page);
} else {
    // Standard fast paginated load
    $count_sql = "SELECT COUNT(*) as total FROM bookings";
    if (!empty($where_clauses)) {
        $count_sql .= " WHERE " . implode(" AND ", $where_clauses);
    }
    $count_stmt = $conn->prepare($count_sql);
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $total_records = (int)($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $count_stmt->close();

    $total_pages = max(1, (int)ceil($total_records / $per_page));
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset = max(0, ($page - 1) * $per_page);

    $sql = "SELECT * FROM bookings";
    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(" AND ", $where_clauses);
    }
    $sql .= " ORDER BY created_at DESC LIMIT ?, ?";

    $stmt = $conn->prepare($sql);
    $query_types = $types . "ii";
    $query_params = array_merge($params, [$offset, $per_page]);
    $stmt->bind_param($query_types, ...$query_params);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $bookings[] = qes_decrypt_booking($row);
        }
    }
    $stmt->close();
}

// Helper to preserve GET params for pagination links
$page_url = function($p) use ($status_filter, $sub_filter, $search) {
    $params = ['page' => $p];
    if ($status_filter !== 'all') {
        $params['status'] = $status_filter;
    } else {
        $params['status'] = 'all';
    }
    if (!empty($sub_filter)) {
        $params['sub'] = $sub_filter;
    }
    if (!empty($search)) {
        $params['search'] = $search;
    }
    return 'a_events.php?' . http_build_query($params);
};

// Page title determination
$page_title = "Request Queue";
if ($status_filter === 'pending') $page_title = "Pending Requests";
elseif ($status_filter === 'bookings') $page_title = "Bookings";
elseif ($status_filter === 'approved') $page_title = "Approved Bookings";
elseif ($status_filter === 'rejected') $page_title = "Rejected Requests";

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tyoy Creation - <?= htmlspecialchars($page_title) ?></title>
    <link rel="icon" type="image/png" href="assets/favicon.png?v=<?= filemtime(__DIR__ . '/assets/favicon.png') ?>">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        /* Table Card and General Layout */
        .table-card {
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03), 0 6px 12px -2px rgba(0,0,0,0.02);
            overflow: hidden;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .custom-table {
            width: 100%;
            min-width: 1120px;
            border-collapse: separate;
            border-spacing: 0;
            text-align: left;
            font-size: 13px;
        }

        .custom-table thead th {
            background: #f8fafc;
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            padding: 14px 18px;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }

        .custom-table tbody tr {
            transition: background 0.15s ease;
        }

        .custom-table tbody tr:hover {
            background: #f8fafc;
        }

        .custom-table tbody td {
            padding: 16px 18px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            color: #334155;
        }

        .custom-table tbody tr:last-child td {
            border-bottom: none;
        }

        /* Column Specific Ref Badge */
        .ref-badge {
            display: inline-flex;
            align-items: center;
            font-family: 'SF Mono', Consolas, 'Liberation Mono', Menlo, monospace;
            font-size: 12px;
            font-weight: 700;
            color: #0f766e;
            background: #f0fdfa;
            border: 1px solid #ccfbf1;
            padding: 4px 9px;
            border-radius: 6px;
            white-space: nowrap;
            letter-spacing: 0.3px;
        }

        /* Client Info */
        .client-info-cell {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .client-name-text {
            font-weight: 700;
            font-size: 14px;
            color: #0f172a;
        }

        .client-meta-row {
            font-size: 12px;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .client-meta-row i {
            font-size: 11px;
            width: 12px;
            color: #94a3b8;
        }

        /* Event Details */
        .event-info-cell {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .event-title-text {
            font-weight: 700;
            font-size: 14px;
            color: #0f172a;
            line-height: 1.35;
        }

        .event-tags-row {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .event-type-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 99px;
            white-space: nowrap;
        }

        .event-type-wedding {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .event-type-birthday {
            background: #fdf2f8;
            color: #9d174d;
            border: 1px solid #fbcfe8;
        }

        .event-type-other {
            background: #f5f3ff;
            color: #5b21b6;
            border: 1px solid #ddd6fe;
        }

        .guest-count-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 500;
            color: #475569;
            background: #f1f5f9;
            padding: 2px 8px;
            border-radius: 99px;
            border: 1px solid #e2e8f0;
            white-space: nowrap;
        }

        /* Schedule Cell */
        .schedule-cell {
            display: flex;
            flex-direction: column;
            gap: 3px;
            white-space: nowrap;
        }

        .schedule-date-text {
            font-weight: 600;
            font-size: 13px;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .schedule-date-text i {
            color: var(--primary, #18392b);
            font-size: 12px;
        }

        .schedule-time-text {
            font-size: 12px;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .schedule-time-text i {
            color: #94a3b8;
            font-size: 11px;
        }

        /* Venue Cell */
        .venue-cell {
            display: flex;
            align-items: flex-start;
            gap: 6px;
            font-size: 13px;
            font-weight: 500;
            color: #334155;
            line-height: 1.4;
            max-width: 170px;
        }

        .venue-cell i {
            color: #c5a059;
            font-size: 12px;
            margin-top: 3px;
            flex-shrink: 0;
        }

        /* Estimated Total Badge */
        .total-price-pill {
            display: inline-flex;
            align-items: center;
            font-weight: 800;
            font-size: 13px;
            color: #064e3b;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            padding: 4px 10px;
            border-radius: 8px;
            white-space: nowrap;
            letter-spacing: 0.2px;
        }

        .total-price-tbd {
            display: inline-flex;
            align-items: center;
            font-size: 12px;
            font-weight: 500;
            color: #94a3b8;
            font-style: italic;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            padding: 3px 8px;
            border-radius: 6px;
            white-space: nowrap;
        }

        /* Status Badge */
        .status-badge-wrap {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 99px;
            white-space: nowrap;
        }

        .status-badge-wrap .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }

        .status-badge-approved {
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
        }

        .status-badge-pending {
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fde68a;
        }

        .status-badge-rejected {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        /* Action Buttons */
        .action-cell {
            text-align: right;
            white-space: nowrap;
        }

        .action-btn-group {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            white-space: nowrap;
        }

        .btn-modern-approve {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #10b981;
            color: #ffffff;
            border: 1px solid #059669;
            padding: 6px 12px;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            box-shadow: 0 1px 2px rgba(16, 185, 129, 0.15);
        }

        .btn-modern-approve:hover {
            background: #059669;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(16, 185, 129, 0.25);
        }

        .btn-modern-reject {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #ffffff;
            color: #dc2626;
            border: 1px solid #fca5a5;
            padding: 6px 12px;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-modern-reject:hover {
            background: #fef2f2;
            border-color: #ef4444;
            color: #b91c1c;
            transform: translateY(-1px);
        }

        .btn-modern-details {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #ffffff;
            color: #334155;
            border: 1px solid #cbd5e1;
            padding: 6px 12px;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }

        .btn-modern-details:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            color: #0f172a;
            transform: translateY(-1px);
        }

        .btn-modern-delete {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #ffffff;
            color: #94a3b8;
            border: 1px solid #e2e8f0;
            padding: 6px 9px;
            border-radius: 7px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-modern-delete:hover {
            background: #fee2e2;
            color: #dc2626;
            border-color: #fca5a5;
            transform: translateY(-1px);
        }
    </style>
</head>
<body class="admin-app">

    <!-- Admin Sidebar -->
    <?php include __DIR__ . '/admin_sidebar.php'; ?>

    <main class="admin-main">
        <header class="admin-header">
            <h1 class="admin-page-title"><?= htmlspecialchars($page_title) ?></h1>
            <div class="admin-profile">
                <div class="admin-avatar"><?= strtoupper(substr($admin_username, 0, 1)) ?></div>
                <div style="font-size: 14px; font-weight: 600;"><?= htmlspecialchars($admin_username) ?></div>
            </div>
        </header>

        <div class="admin-body">
            <?php if (!empty($alert_message)): ?>
                <div style="background: <?= $alert_type === 'warning' ? '#fef3c7' : '#d1fae5' ?>; color: <?= $alert_type === 'warning' ? '#b45309' : '#065f46' ?>; border: 1px solid <?= $alert_type === 'warning' ? '#fde68a' : '#a7f3d0' ?>; padding: 14px 18px; border-radius: 8px; margin-bottom: 24px; display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 600;">
                    <i class="fa-solid <?= $alert_type === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"></i>
                    <span><?= htmlspecialchars($alert_message) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($status_filter === 'rejected' || ($status_filter === 'bookings' && $sub_filter === 'rejected')): ?>
                <div style="background: #fef3c7; border: 1px solid #fcd34d; color: #92400e; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 13px;">
                    <i class="fa-solid fa-clock" style="color: #d97706;"></i>
                    <span><strong>Auto-Delete Policy:</strong> Rejected booking requests are automatically and permanently deleted after <strong>7 days</strong> if not manually deleted or re-approved before that.</span>
                </div>
            <?php endif; ?>

            <!-- Table Card -->
            <div class="table-card">
                <div class="table-header-bar" style="flex-wrap: wrap; gap: 12px;">
                    <div class="table-tabs">
                        <a href="a_events.php?status=all" class="tab-btn <?= $status_filter === 'all' ? 'active' : '' ?>">
                            All Requests <span class="tab-count"><?= $status_counts['all'] ?></span>
                        </a>
                        <a href="a_events.php?status=pending" class="tab-btn <?= $status_filter === 'pending' ? 'active' : '' ?>">
                            Pending Requests <span class="tab-count badge-pending-count"><?= $status_counts['pending'] ?></span>
                        </a>
                        <a href="a_events.php?status=bookings" class="tab-btn <?= in_array($status_filter, ['bookings', 'approved', 'rejected']) ? 'active' : '' ?>">
                            <i class="fa-solid fa-calendar-check" style="margin-right: 4px;"></i> Bookings <span class="tab-count"><?= $status_counts['bookings'] ?></span>
                        </a>
                    </div>

                    <form method="GET" action="a_events.php" class="search-input-wrap">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                        <?php if (!empty($sub_filter)): ?>
                            <input type="hidden" name="sub" value="<?= htmlspecialchars($sub_filter) ?>">
                        <?php endif; ?>
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" name="search" placeholder="Search by name, type, venue..." value="<?= htmlspecialchars($search) ?>">
                    </form>
                </div>

                <?php if (in_array($status_filter, ['bookings', 'approved', 'rejected'])): ?>
                    <!-- Sub-filter pills for Bookings tab -->
                    <div style="padding: 10px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <span style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-right: 4px;">Filter:</span>
                        <a href="a_events.php?status=bookings" style="font-size: 12px; font-weight: <?= (empty($sub_filter) || $sub_filter === 'all') ? '700' : '500' ?>; padding: 4px 12px; border-radius: 99px; text-decoration: none; background: <?= (empty($sub_filter) || $sub_filter === 'all') ? 'var(--primary, #18392b)' : '#ffffff' ?>; color: <?= (empty($sub_filter) || $sub_filter === 'all') ? '#ffffff' : '#475569' ?>; border: 1px solid <?= (empty($sub_filter) || $sub_filter === 'all') ? 'var(--primary, #18392b)' : '#cbd5e1' ?>; transition: all 0.2s;">
                            All Bookings (<?= $status_counts['bookings'] ?>)
                        </a>
                        <a href="a_events.php?status=bookings&sub=approved" style="font-size: 12px; font-weight: <?= $sub_filter === 'approved' ? '700' : '500' ?>; padding: 4px 12px; border-radius: 99px; text-decoration: none; background: <?= $sub_filter === 'approved' ? '#10b981' : '#ffffff' ?>; color: <?= $sub_filter === 'approved' ? '#ffffff' : '#065f46' ?>; border: 1px solid <?= $sub_filter === 'approved' ? '#10b981' : '#a7f3d0' ?>; display: inline-flex; align-items: center; gap: 5px; transition: all 0.2s;">
                            <i class="fa-solid fa-circle-check" style="<?= $sub_filter === 'approved' ? 'color:#fff;' : 'color:#10b981;' ?>"></i> Approved (<?= $status_counts['approved'] ?>)
                        </a>
                        <a href="a_events.php?status=bookings&sub=rejected" style="font-size: 12px; font-weight: <?= $sub_filter === 'rejected' ? '700' : '500' ?>; padding: 4px 12px; border-radius: 99px; text-decoration: none; background: <?= $sub_filter === 'rejected' ? '#dc2626' : '#ffffff' ?>; color: <?= $sub_filter === 'rejected' ? '#ffffff' : '#991b1b' ?>; border: 1px solid <?= $sub_filter === 'rejected' ? '#dc2626' : '#fecaca' ?>; display: inline-flex; align-items: center; gap: 5px; transition: all 0.2s;">
                            <i class="fa-solid fa-circle-xmark" style="<?= $sub_filter === 'rejected' ? 'color:#fff;' : 'color:#dc2626;' ?>"></i> Rejected (<?= $status_counts['rejected'] ?>)
                        </a>
                    </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th style="width: 125px;">Ref #</th>
                                <th style="width: 200px;">Client Contact</th>
                                <th style="width: 220px;">Event Details</th>
                                <th style="width: 140px;">Schedule</th>
                                <th style="width: 160px;">Venue</th>
                                <th style="width: 140px;">Estimated Total</th>
                                <th style="width: 110px;">Status</th>
                                <th style="width: 180px; text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bookings)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 48px;">
                                        <div style="font-size: 28px; margin-bottom: 8px; opacity: 0.5;"><i class="fa-regular fa-folder-open"></i></div>
                                        <div style="font-weight: 600; font-size: 14px; color: #475569;">No booking requests found</div>
                                        <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">No records match your selected filter criteria.</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($bookings as $idx => $b): 
                                    $st = strtolower($b['status']);
                                    $status_badge_class = ($st === 'approved') ? 'status-badge-approved' : (($st === 'rejected') ? 'status-badge-rejected' : 'status-badge-pending');

                                    $estimated_total_display = '';
                                    if (!empty($b['service_requirements'])) {
                                        if (preg_match('/ESTIMATED TOTAL:\s*(₱[\d,]+)/u', $b['service_requirements'], $m_total)) {
                                            $estimated_total_display = $m_total[1];
                                        }
                                    }

                                    $booking_json = htmlspecialchars(json_encode($b, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                                    
                                    $is_wedding = (stripos($b['event_type'] ?? '', 'Wedding') !== false);
                                    $is_bday = (stripos($b['event_type'] ?? '', 'Birthday') !== false || stripos($b['event_type'] ?? '', 'Kids') !== false);
                                    $type_cls = $is_wedding ? 'event-type-wedding' : ($is_bday ? 'event-type-birthday' : 'event-type-other');
                                    $type_icon = $is_wedding ? 'fa-ring' : ($is_bday ? 'fa-cake-candles' : 'fa-champagne-glasses');
                                ?>
                                    <tr>
                                        <!-- Ref # -->
                                        <td>
                                            <span class="ref-badge"><?= htmlspecialchars($b['reference_no']) ?></span>
                                        </td>

                                        <!-- Client Contact -->
                                        <td>
                                            <div class="client-info-cell">
                                                <span class="client-name-text"><?= htmlspecialchars($b['client_name']) ?></span>
                                                <div class="client-meta-row">
                                                    <i class="fa-solid fa-phone"></i>
                                                    <span><?= htmlspecialchars($b['client_phone']) ?></span>
                                                </div>
                                                <?php if (!empty($b['client_email'])): ?>
                                                    <div class="client-meta-row">
                                                        <i class="fa-regular fa-envelope"></i>
                                                        <span><?= htmlspecialchars($b['client_email']) ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <!-- Event Details -->
                                        <td>
                                            <div class="event-info-cell">
                                                <span class="event-title-text"><?= htmlspecialchars($b['event_title']) ?></span>
                                                <div class="event-tags-row">
                                                    <span class="event-type-pill <?= $type_cls ?>">
                                                        <i class="fa-solid <?= $type_icon ?>"></i>
                                                        <?= htmlspecialchars($b['event_type']) ?>
                                                    </span>
                                                    <?php if (!empty($b['guest_count'])): ?>
                                                        <span class="guest-count-pill">
                                                            <i class="fa-solid fa-users"></i> <?= (int)$b['guest_count'] ?> guests
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Schedule -->
                                        <td>
                                            <div class="schedule-cell">
                                                <div class="schedule-date-text">
                                                    <i class="fa-regular fa-calendar"></i>
                                                    <span><?= date('M d, Y', strtotime($b['event_start'])) ?></span>
                                                </div>
                                                <div class="schedule-time-text">
                                                    <i class="fa-regular fa-clock"></i>
                                                    <span><?= date('g:i A', strtotime($b['event_start'])) ?></span>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Venue -->
                                        <td>
                                            <div class="venue-cell" title="<?= htmlspecialchars($b['location_venue']) ?>">
                                                <i class="fa-solid fa-location-dot"></i>
                                                <span><?= htmlspecialchars($b['location_venue']) ?></span>
                                            </div>
                                        </td>

                                        <!-- Estimated Total -->
                                        <td>
                                            <?php if ($estimated_total_display): ?>
                                                <span class="total-price-pill"><?= $estimated_total_display ?></span>
                                            <?php else: ?>
                                                <span class="total-price-tbd">Custom / TBD</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Status -->
                                        <td>
                                            <span class="status-badge-wrap <?= $status_badge_class ?>">
                                                <span class="dot"></span>
                                                <?= ucfirst($b['status']) ?>
                                            </span>
                                        </td>

                                        <!-- Actions -->
                                        <td class="action-cell">
                                            <div class="action-btn-group">
                                                <?php if ($b['status'] === 'pending' || $b['status'] === 'rejected'): ?>
                                                    <button type="button" class="btn-modern-approve" 
                                                        data-id="<?= (int)$b['id'] ?>" 
                                                        data-client="<?= htmlspecialchars($b['client_name'], ENT_QUOTES, 'UTF-8') ?>" 
                                                        data-title="<?= htmlspecialchars($b['event_title'], ENT_QUOTES, 'UTF-8') ?>" 
                                                        data-date="<?= htmlspecialchars(date('M d, Y h:i A', strtotime($b['event_start'])), ENT_QUOTES, 'UTF-8') ?>"
                                                        data-venue="<?= htmlspecialchars($b['location_venue'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-ref="<?= htmlspecialchars($b['reference_no'], ENT_QUOTES, 'UTF-8') ?>"
                                                        onclick="openApproveFromBtn(this)" 
                                                        title="Approve booking request">
                                                        <i class="fa-solid fa-check"></i> Approve
                                                    </button>
                                                <?php endif; ?>

                                                <?php if ($b['status'] === 'pending' || $b['status'] === 'approved'): ?>
                                                    <button type="button" class="btn-modern-reject" 
                                                        data-id="<?= (int)$b['id'] ?>" 
                                                        data-client="<?= htmlspecialchars($b['client_name'], ENT_QUOTES, 'UTF-8') ?>" 
                                                        data-title="<?= htmlspecialchars($b['event_title'], ENT_QUOTES, 'UTF-8') ?>" 
                                                        data-ref="<?= htmlspecialchars($b['reference_no'], ENT_QUOTES, 'UTF-8') ?>"
                                                        onclick="openRejectFromBtn(this)" 
                                                        title="Reject booking request">
                                                        <i class="fa-solid fa-xmark"></i> Reject
                                                    </button>
                                                <?php endif; ?>

                                                <button type="button" class="btn-modern-details" data-booking="<?= $booking_json ?>" onclick="openBookingFromBtn(this)" title="View full booking details">
                                                    <i class="fa-solid fa-eye"></i> Details
                                                </button>

                                                <?php if ($b['status'] === 'rejected'): ?>
                                                    <button type="button" class="btn-modern-delete" 
                                                        data-id="<?= (int)$b['id'] ?>" 
                                                        data-client="<?= htmlspecialchars($b['client_name'], ENT_QUOTES, 'UTF-8') ?>" 
                                                        data-title="<?= htmlspecialchars($b['event_title'], ENT_QUOTES, 'UTF-8') ?>" 
                                                        data-ref="<?= htmlspecialchars($b['reference_no'], ENT_QUOTES, 'UTF-8') ?>"
                                                        onclick="openDeleteFromBtn(this)" 
                                                        title="Permanently delete rejected booking">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Bar -->
                <div class="pagination-wrapper">
                    <div class="pagination-info">
                        Showing <strong><?= $total_records > 0 ? ($offset + 1) : 0 ?></strong> to <strong><?= min($total_records, $offset + $per_page) ?></strong> of <strong><?= $total_records ?></strong> records
                    </div>
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="<?= $page_url($page - 1) ?>" title="Previous Page">
                                    <i class="fa-solid fa-chevron-left"></i>
                                </a>
                            <?php else: ?>
                                <span class="disabled"><i class="fa-solid fa-chevron-left"></i></span>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            if ($start_page > 1) {
                                echo '<a href="' . $page_url(1) . '">1</a>';
                                if ($start_page > 2) {
                                    echo '<span class="disabled">...</span>';
                                }
                            }
                            for ($p = $start_page; $p <= $end_page; $p++) {
                                if ($p === $page) {
                                    echo '<span class="active">' . $p . '</span>';
                                } else {
                                    echo '<a href="' . $page_url($p) . '">' . $p . '</a>';
                                }
                            }
                            if ($end_page < $total_pages) {
                                if ($end_page < $total_pages - 1) {
                                    echo '<span class="disabled">...</span>';
                                }
                                echo '<a href="' . $page_url($total_pages) . '">' . $total_pages . '</a>';
                            }
                            ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="<?= $page_url($page + 1) ?>" title="Next Page">
                                    <i class="fa-solid fa-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="disabled"><i class="fa-solid fa-chevron-right"></i></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Details Modal -->
    <div class="modal-backdrop" id="detailsModal">
        <div class="modal-card" style="max-width: 650px; flex-direction: column;">
            <div style="padding: 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 18px;" id="detailsRefTitle">Booking Details</h3>
                <button type="button" onclick="closeDetailsModal()" style="background: transparent; border: none; font-size: 20px; color: var(--text-muted); cursor: pointer;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div style="padding: 24px; overflow-y: auto; max-height: 70vh;" id="detailsContent">
                <!-- Injected via JavaScript -->
            </div>
            <div style="padding: 16px 24px; border-top: 1px solid var(--border-color); text-align: right;">
                <button type="button" class="btn-secondary" onclick="closeDetailsModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Approve Confirmation Modal -->
    <div class="modal-backdrop" id="approveModal">
        <div class="modal-card" style="max-width: 520px; flex-direction: column;">
            <form method="POST" action="a_events.php?status=<?= htmlspecialchars($status_filter) ?>">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="booking_id" id="approveBookingId" value="">

                <div style="padding: 20px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #f0fdf4;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div style="width: 36px; height: 36px; border-radius: 50%; background: #d1fae5; color: #059669; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                            <i class="fa-solid fa-check"></i>
                        </div>
                        <h3 style="font-size: 17px; margin: 0; font-weight: 700; color: #065f46;">Approve Event Booking</h3>
                    </div>
                    <button type="button" onclick="closeApproveModal()" style="background: transparent; border: none; font-size: 20px; color: var(--text-muted); cursor: pointer;" aria-label="Close">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div style="padding: 24px;">
                    <p style="font-size: 14px; color: #374151; margin-bottom: 16px; line-height: 1.5;">
                        Are you sure you want to approve this event booking inquiry?
                    </p>

                    <!-- Event Summary Card -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 18px; margin-bottom: 16px; font-size: 13px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span style="color: #64748b; font-weight: 600;">Reference No:</span>
                            <span id="appModalRef" class="badge" style="background: #eef2ee; color: var(--primary); font-weight: 700;">-</span>
                        </div>
                        <div style="margin-bottom: 8px;">
                            <span style="color: #64748b; font-weight: 600;">Event Title:</span>
                            <strong id="appModalTitle" style="color: #0f172a; margin-left: 6px;">-</strong>
                        </div>
                        <div style="margin-bottom: 8px;">
                            <span style="color: #64748b; font-weight: 600;">Client:</span>
                            <span id="appModalClient" style="color: #334155; margin-left: 6px;">-</span>
                        </div>
                        <div style="margin-bottom: 8px;">
                            <span style="color: #64748b; font-weight: 600;">Schedule:</span>
                            <span id="appModalDate" style="color: #334155; margin-left: 6px;">-</span>
                        </div>
                        <div>
                            <span style="color: #64748b; font-weight: 600;">Venue:</span>
                            <span id="appModalVenue" style="color: #334155; margin-left: 6px;">-</span>
                        </div>
                    </div>

                    <!-- Automated Workflow Callout -->
                    <div style="background: #ecfdf5; border-left: 4px solid #10b981; padding: 12px 14px; border-radius: 4px; font-size: 12px; color: #065f46; line-height: 1.5;">
                        <div style="display: flex; align-items: flex-start; gap: 8px;">
                            <i class="fa-solid fa-envelope-circle-check" style="font-size: 15px; margin-top: 2px; flex-shrink: 0; color: #059669;"></i>
                            <div>
                                <strong>Automated System Workflow:</strong>
                                <ul style="margin: 4px 0 0 16px; padding: 0;">
                                    <li>Booking will be placed onto the <strong>Master Calendar</strong>.</li>
                                    <li>An automated <strong>Approval Email</strong> will be dispatched to the client.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 10px; background: #fafafa;">
                    <button type="button" class="btn-secondary" onclick="closeApproveModal()" style="padding: 9px 18px; font-size: 13px; font-weight: 600;">Cancel</button>
                    <button type="submit" class="btn-action-approve" style="padding: 9px 20px; font-size: 13px; font-weight: 700; background: #059669; color: #ffffff; border: none; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-check"></i> Yes, Approve Booking
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Reason Modal -->
    <div class="modal-backdrop" id="rejectModal">
        <div class="modal-card" style="max-width: 520px; flex-direction: column;">
            <form method="POST" action="a_events.php?status=<?= htmlspecialchars($status_filter) ?>">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="booking_id" id="rejectBookingId" value="">

                <div style="padding: 20px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #fff5f5;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div style="width: 36px; height: 36px; border-radius: 50%; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                            <i class="fa-solid fa-circle-xmark"></i>
                        </div>
                        <h3 style="font-size: 17px; margin: 0; font-weight: 700; color: #991b1b;">Reject Booking Inquiry</h3>
                    </div>
                    <button type="button" onclick="closeRejectModal()" style="background: transparent; border: none; font-size: 20px; color: var(--text-muted); cursor: pointer;" aria-label="Close">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div style="padding: 24px;">
                    <!-- Event Details Summary -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; font-size: 13px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="color: #64748b; font-weight: 600;">Reference:</span>
                            <span id="rejModalRef" class="badge" style="background: #fee2e2; color: #dc2626; font-weight: 700;">-</span>
                        </div>
                        <div style="margin-bottom: 6px;">
                            <span style="color: #64748b; font-weight: 600;">Event:</span>
                            <strong id="rejModalTitle" style="color: #0f172a; margin-left: 6px;">-</strong>
                        </div>
                        <div>
                            <span style="color: #64748b; font-weight: 600;">Client:</span>
                            <span id="rejModalClient" style="color: #334155; margin-left: 6px;">-</span>
                        </div>
                    </div>

                    <p style="font-size: 13px; color: #4b5563; margin-bottom: 12px; line-height: 1.5;">
                        Please state the reason for rejecting this booking. This will be automatically sent to the client via email:
                    </p>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" style="font-weight: 600;">Rejection / Reschedule Reason *</label>
                        <textarea name="rejection_reason" class="form-control" rows="3" required placeholder="e.g. Fully booked on this date. We invite you to consider alternate dates."></textarea>
                    </div>
                </div>

                <div style="padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 10px; background: #fafafa;">
                    <button type="button" class="btn-secondary" onclick="closeRejectModal()" style="padding: 9px 18px; font-size: 13px; font-weight: 600;">Cancel</button>
                    <button type="submit" class="btn-action-reject" style="padding: 9px 20px; font-size: 13px; font-weight: 700; background: #dc2626; color: #ffffff; border: none; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-paper-plane"></i> Confirm & Notify Client
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Rejected Confirmation Modal -->
    <div class="modal-backdrop" id="deleteRejectedModal">
        <div class="modal-card" style="max-width: 500px; flex-direction: column;">
            <form method="POST" action="a_events.php?status=<?= htmlspecialchars($status_filter) ?>">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="delete_rejected">
                <input type="hidden" name="booking_id" id="deleteRejectedBookingId" value="">

                <div style="padding: 20px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #fff5f5;">
                    <div style="display: flex; align-items: center; gap: 10px; color: #dc2626;">
                        <div style="width: 36px; height: 36px; border-radius: 50%; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                            <i class="fa-solid fa-trash-can"></i>
                        </div>
                        <h3 style="font-size: 17px; margin: 0; font-weight: 700; color: #991b1b;">Permanently Delete Booking</h3>
                    </div>
                    <button type="button" onclick="closeDeleteRejectedModal()" style="background: transparent; border: none; font-size: 20px; color: var(--text-muted); cursor: pointer;" aria-label="Close">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div style="padding: 24px;">
                    <p style="font-size: 14px; color: #374151; margin-bottom: 16px; line-height: 1.5;">
                        Are you sure you want to permanently delete this rejected booking inquiry from the system?
                    </p>

                    <!-- Booking Summary Card -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 18px; margin-bottom: 16px; font-size: 13px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="color: #64748b; font-weight: 600;">Reference:</span>
                            <span id="delRejModalRef" class="badge" style="background: #fee2e2; color: #dc2626; font-weight: 700;">-</span>
                        </div>
                        <div style="margin-bottom: 6px;">
                            <span style="color: #64748b; font-weight: 600;">Event Title:</span>
                            <strong id="delRejModalTitle" style="color: #0f172a; margin-left: 6px;">-</strong>
                        </div>
                        <div>
                            <span style="color: #64748b; font-weight: 600;">Client:</span>
                            <span id="delRejModalClient" style="color: #334155; margin-left: 6px;">-</span>
                        </div>
                    </div>

                    <div style="background: #fff1f2; border-left: 4px solid #f43f5e; padding: 10px 14px; border-radius: 4px; font-size: 12px; color: #9f1239; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-circle-exclamation" style="font-size: 14px; flex-shrink: 0;"></i>
                        <span><strong>Warning:</strong> This inquiry record will be completely erased. This action cannot be undone.</span>
                    </div>
                </div>

                <div style="padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 10px; background: #fafafa;">
                    <button type="button" class="btn-secondary" onclick="closeDeleteRejectedModal()" style="padding: 9px 18px; font-size: 13px; font-weight: 600;">Cancel</button>
                    <button type="submit" class="btn-action-reject" style="padding: 9px 20px; font-size: 13px; font-weight: 700; background: #dc2626; color: #ffffff; border: none; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-trash-can"></i> Yes, Permanently Delete
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"']/g, function(m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
            });
        }

        // Approve Modal Handlers
        function openApproveModal(id, client, title, date, venue, ref) {
            document.getElementById('approveBookingId').value = id;
            document.getElementById('appModalRef').textContent = ref || 'N/A';
            document.getElementById('appModalTitle').textContent = title || 'N/A';
            document.getElementById('appModalClient').textContent = client || 'N/A';
            document.getElementById('appModalDate').textContent = date || 'N/A';
            document.getElementById('appModalVenue').textContent = venue || 'N/A';
            document.getElementById('approveModal').classList.add('active');
        }

        function openApproveFromBtn(btn) {
            const id = btn.getAttribute('data-id');
            const client = btn.getAttribute('data-client');
            const title = btn.getAttribute('data-title');
            const date = btn.getAttribute('data-date');
            const venue = btn.getAttribute('data-venue');
            const ref = btn.getAttribute('data-ref');
            openApproveModal(id, client, title, date, venue, ref);
        }

        function closeApproveModal() {
            document.getElementById('approveModal').classList.remove('active');
        }

        // Reject Modal Handlers
        function openRejectModal(id, client, title, ref) {
            document.getElementById('rejectBookingId').value = id;
            document.getElementById('rejModalRef').textContent = ref || 'N/A';
            document.getElementById('rejModalTitle').textContent = title || 'N/A';
            document.getElementById('rejModalClient').textContent = client || 'N/A';
            document.getElementById('rejectModal').classList.add('active');
        }

        function openRejectFromBtn(btn) {
            const id = btn.getAttribute('data-id');
            const client = btn.getAttribute('data-client');
            const title = btn.getAttribute('data-title');
            const ref = btn.getAttribute('data-ref');
            openRejectModal(id, client, title, ref);
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('active');
        }

        // Delete Rejected Modal Handlers
        function openDeleteRejectedModal(id, client, title, ref) {
            document.getElementById('deleteRejectedBookingId').value = id;
            document.getElementById('delRejModalRef').textContent = ref || 'N/A';
            document.getElementById('delRejModalTitle').textContent = title || 'N/A';
            document.getElementById('delRejModalClient').textContent = client || 'N/A';
            document.getElementById('deleteRejectedModal').classList.add('active');
        }

        function openDeleteFromBtn(btn) {
            const id = btn.getAttribute('data-id');
            const client = btn.getAttribute('data-client');
            const title = btn.getAttribute('data-title');
            const ref = btn.getAttribute('data-ref');
            openDeleteRejectedModal(id, client, title, ref);
        }

        function closeDeleteRejectedModal() {
            document.getElementById('deleteRejectedModal').classList.remove('active');
        }

        // Global Modal Event Listeners
        window.addEventListener('click', function(e) {
            ['approveModal', 'rejectModal', 'deleteRejectedModal', 'detailsModal'].forEach(function(modalId) {
                const el = document.getElementById(modalId);
                if (el && e.target === el) {
                    el.classList.remove('active');
                }
            });
        });

        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                ['approveModal', 'rejectModal', 'deleteRejectedModal', 'detailsModal'].forEach(function(modalId) {
                    const el = document.getElementById(modalId);
                    if (el) el.classList.remove('active');
                });
            }
        });

        function openBookingFromBtn(btn) {
            const raw = btn.getAttribute('data-booking');
            try {
                const b = JSON.parse(raw);
                viewBookingDetails(b);
            } catch (err) {
                console.error('Failed to parse booking details:', err);
                alert('Could not open booking details. Please refresh the page and try again.');
            }
        }

        function formatEventDate(dateStr) {
            if (!dateStr) return 'N/A';
            const d = new Date(dateStr);
            if (isNaN(d.getTime())) return dateStr;
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });
        }

        function viewBookingDetails(b) {
            document.getElementById('detailsRefTitle').textContent = `Booking Details: ${escapeHtml(b.reference_no)}`;

            // Parse service_requirements format:
            // "Service: Package (₱xx,xxx); Service2: Package2 (₱xx,xxx) | ESTIMATED TOTAL: ₱xx,xxx"
            let reqHtml = '<span style="color:var(--text-muted); font-style:italic;">No custom packages or add-ons selected.</span>';
            let totalHtml = '';
            if (b.service_requirements) {
                const raw = b.service_requirements;
                const pipeParts = raw.split('|');
                const servicesPart = pipeParts[0].trim();
                const totalPart = pipeParts.length > 1 ? pipeParts[1].trim() : '';

                const separator = servicesPart.includes(';') ? ';' : ',';
                const items = servicesPart.split(separator)
                    .map(s => s.trim())
                    .filter(s => s.length > 0 && !s.toLowerCase().includes('estimated total'));

                if (items.length > 0) {
                    reqHtml = items.map(s =>
                        `<span class="badge" style="background:#eef2ee; color:#232f22; border:1px solid #d5ded5; margin: 3px 4px 3px 0; display:inline-block; font-size:12px; font-weight:600; padding:5px 10px; border-radius:6px;"><i class="fa-solid fa-check" style="font-size:10px; color:#15803d; margin-right:4px;"></i>${escapeHtml(s)}</span>`
                    ).join(' ');
                }

                if (totalPart) {
                    totalHtml = `<div style="margin-top:12px; display:inline-flex; align-items:center; gap:8px; background:#232f22; color:#fff; padding:10px 18px; border-radius:8px; font-weight:700; font-size:15px; box-shadow:0 2px 8px rgba(35,47,34,0.25);">
                        <i class="fa-solid fa-calculator" style="color:#d97706;"></i> ${escapeHtml(totalPart)}
                    </div>`;
                }
            }

            const formattedStart = formatEventDate(b.event_start);
            const formattedEnd = formatEventDate(b.event_end);
            const notesFormatted = b.special_notes ? escapeHtml(b.special_notes).replace(/\n/g, '<br>') : '<span style="color:var(--text-muted); font-style:italic;">No special notes provided.</span>';

            const statusClass = b.status === 'approved' ? 'badge-approved' : (b.status === 'rejected' ? 'badge-rejected' : 'badge-pending');
            const sourceLabel = (b.source === 'online_inquiry') ? 'Public Online Inquiry (Client Booking Form)' : 'Manual Admin Entry';

            const html = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; background:#f9fafb; padding:12px 16px; border-radius:10px; border:1px solid var(--border-color);">
                    <div>
                        <div style="font-size:11px; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Reference Code</div>
                        <div style="font-family:monospace; font-weight:800; font-size:17px; color:var(--primary);">${escapeHtml(b.reference_no)}</div>
                    </div>
                    <div>
                        <span class="badge ${statusClass}" style="font-size:13px; padding:6px 14px;">
                            <span class="badge-dot"></span> ${escapeHtml(b.status.toUpperCase())}
                        </span>
                    </div>
                </div>

                <div style="font-weight:700; font-size:13px; text-transform:uppercase; color:var(--primary); margin-bottom:10px; letter-spacing:0.04em;">Client Information</div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 20px;">
                    <div>
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Client Name</div>
                        <div style="font-weight: 700; font-size: 15px; color:var(--text-main);">${escapeHtml(b.client_name)}</div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Mobile Number</div>
                        <div style="font-weight: 600;"><a href="tel:${escapeHtml(b.client_phone)}" style="color:var(--primary); text-decoration:none;"><i class="fa-solid fa-phone" style="font-size:11px;"></i> ${escapeHtml(b.client_phone)}</a></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Email Address</div>
                        <div style="font-weight: 600;"><a href="mailto:${escapeHtml(b.client_email)}" style="color:var(--primary); text-decoration:none;"><i class="fa-solid fa-envelope" style="font-size:11px;"></i> ${escapeHtml(b.client_email)}</a></div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Complete Address</div>
                        <div style="font-size: 13px;">${escapeHtml(b.client_address || 'None provided')}</div>
                    </div>
                </div>

                <hr style="border: none; border-top: 1px solid var(--border-color); margin: 16px 0;">

                <div style="font-weight:700; font-size:13px; text-transform:uppercase; color:var(--primary); margin-bottom:10px; letter-spacing:0.04em;">Event Specifics</div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 20px;">
                    <div>
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Event Title</div>
                        <div style="font-weight: 700; font-size: 15px;">${escapeHtml(b.event_title)}</div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Celebration Type</div>
                        <div style="font-weight: 700; color: ${b.event_type.toLowerCase().includes('wedding') ? '#065f46' : '#991b1b'};">
                            <i class="fa-solid ${b.event_type.toLowerCase().includes('wedding') ? 'fa-rings-wedding' : 'fa-cake-candles'}"></i> ${escapeHtml(b.event_type)}
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Event Schedule</div>
                        <div style="font-weight: 600; font-size: 13px;">${formattedStart} &bull; to &bull; ${formattedEnd}</div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Expected Guests</div>
                        <div style="font-weight: 600; font-size: 14px;"><i class="fa-solid fa-users" style="color:var(--text-muted); font-size:12px;"></i> ${escapeHtml(b.guest_count)} attendees</div>
                    </div>
                    <div style="grid-column: span 2;">
                        <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Venue / Location</div>
                        <div style="font-weight: 600; font-size: 14px;"><i class="fa-solid fa-location-dot" style="color:#d97706; font-size:12px;"></i> ${escapeHtml(b.location_venue)}</div>
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px; font-weight:600;">Custom Packages & Equipment Options</div>
                    <div style="line-height:1.7;">${reqHtml}</div>
                    ${totalHtml}
                </div>

                <div>
                    <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; font-weight:600;">Client Special Instructions / Notes</div>
                    <div style="background: #f8fafc; padding: 14px; border-radius: 8px; font-size: 13px; border: 1px solid var(--border-color); line-height:1.5;">${notesFormatted}</div>
                </div>

                ${b.rejection_reason ? `<div style="margin-top: 16px; background: #fee2e2; color: #dc2626; padding: 12px 16px; border-radius: 8px; font-size: 13px; border:1px solid #fecaca;"><strong>Rejection Reason:</strong> ${escapeHtml(b.rejection_reason)}</div>` : ''}

                <div style="margin-top: 18px; padding-top: 12px; border-top: 1px solid var(--border-color); font-size: 11px; color: var(--text-muted); display:flex; justify-content:space-between; align-items:center;">
                    <span><i class="fa-solid fa-clock-rotate-left"></i> Submitted: ${escapeHtml(b.created_at || 'Recently')}</span>
                    <span><i class="fa-solid fa-globe"></i> Source: ${escapeHtml(sourceLabel)}</span>
                </div>
            `;
            document.getElementById('detailsContent').innerHTML = html;
            document.getElementById('detailsModal').classList.add('active');
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').classList.remove('active');
        }
    </script>
</body>
</html>
