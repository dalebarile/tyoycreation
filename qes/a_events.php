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

// Auto-purge: permanently delete rejected bookings older than 7 days
$conn->query("DELETE FROM bookings WHERE status = 'rejected' AND updated_at < (NOW() - INTERVAL '7 DAY')");

// Status counts for navigation tabs
$status_counts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
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

// Handle Actions (POST)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $action = $_POST['action'] ?? '';
    $booking_id = (int)($_POST['booking_id'] ?? 0);

    if ($action === 'approve' && $booking_id > 0) {
        // Fetch target booking details to check schedule
        $fetch_stmt = $conn->prepare("SELECT id, reference_no, event_title, event_start, event_end, status FROM bookings WHERE id = ?");
        $fetch_stmt->bind_param("i", $booking_id);
        $fetch_stmt->execute();
        $booking_res = $fetch_stmt->get_result();
        $target_booking = $booking_res ? $booking_res->fetch_assoc() : null;
        $fetch_stmt->close();

        if (!$target_booking) {
            $alert_message = "Booking #{$booking_id} was not found.";
            $alert_type = 'warning';
        } else {
            // Server-side Conflict Check: verify date and time do not overlap with any approved event
            $conflict = check_booking_conflict($conn, $target_booking['event_start'], $target_booking['event_end'], $booking_id);

            if ($conflict['conflict']) {
                // Block approval, keep booking pending, display conflict message
                $alert_message = $conflict['message'];
                $alert_type = 'warning';
            } else {
                // No conflict: approve booking and add to Master Calendar
                $stmt = $conn->prepare("UPDATE bookings SET status = 'approved', rejection_reason = NULL WHERE id = ?");
                $stmt->bind_param("i", $booking_id);
                if ($stmt->execute()) {
                    NotificationHelper::sendApprovalNotice($conn, $booking_id);
                    $alert_message = "Booking #{$booking_id} (" . htmlspecialchars($target_booking['event_title']) . ") has been APPROVED and added to the Master Calendar. Approval notifications were dispatched.";
                    $alert_type = 'success';
                } else {
                    $alert_message = "Failed to approve booking #{$booking_id}. Please try again.";
                    $alert_type = 'warning';
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'reject' && $booking_id > 0) {
        $reason = trim($_POST['rejection_reason'] ?? 'Requested schedule or venue is unavailable.');
        $stmt = $conn->prepare("UPDATE bookings SET status = 'rejected', rejection_reason = ? WHERE id = ?");
        $stmt->bind_param("si", $reason, $booking_id);
        if ($stmt->execute()) {
            NotificationHelper::sendRejectionNotice($conn, $booking_id, $reason);
            $alert_message = "Booking #{$booking_id} has been REJECTED. Rejection notice was dispatched to the client.";
            $alert_type = 'warning';
        }
        $stmt->close();
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

if ($status_filter !== 'all' && in_array($status_filter, ['pending', 'approved', 'rejected'])) {
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
$page_url = function($p) use ($status_filter, $search) {
    $params = ['page' => $p];
    if ($status_filter !== 'all') {
        $params['status'] = $status_filter;
    } else {
        $params['status'] = 'all';
    }
    if (!empty($search)) {
        $params['search'] = $search;
    }
    return 'a_events.php?' . http_build_query($params);
};

// Page title determination
$page_title = "Request Queue";
if ($status_filter === 'pending') $page_title = "Pending Requests";
elseif ($status_filter === 'approved') $page_title = "Approved Bookings";
elseif ($status_filter === 'rejected') $page_title = "Rejected Requests";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SCHEDFIX - <?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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

            <?php if ($status_filter === 'rejected'): ?>
                <div style="background: #fef3c7; border: 1px solid #fcd34d; color: #92400e; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 13px;">
                    <i class="fa-solid fa-clock" style="color: #d97706;"></i>
                    <span><strong>Auto-Delete Policy:</strong> Rejected booking requests are automatically and permanently deleted after <strong>7 days</strong> if not manually deleted or re-approved before that.</span>
                </div>
            <?php endif; ?>

            <!-- Table Card -->
            <div class="table-card">
                <div class="table-header-bar">
                    <div class="table-tabs">
                        <a href="a_events.php?status=all" class="tab-btn <?= $status_filter === 'all' ? 'active' : '' ?>">
                            All Requests <span class="tab-count"><?= $status_counts['all'] ?></span>
                        </a>
                        <a href="a_events.php?status=pending" class="tab-btn <?= $status_filter === 'pending' ? 'active' : '' ?>">
                            Pending <span class="tab-count badge-pending-count"><?= $status_counts['pending'] ?></span>
                        </a>
                        <a href="a_events.php?status=approved" class="tab-btn <?= $status_filter === 'approved' ? 'active' : '' ?>">
                            Approved <span class="tab-count"><?= $status_counts['approved'] ?></span>
                        </a>
                        <a href="a_events.php?status=rejected" class="tab-btn <?= $status_filter === 'rejected' ? 'active' : '' ?>">
                            Rejected <span class="tab-count"><?= $status_counts['rejected'] ?></span>
                        </a>
                    </div>

                    <form method="GET" action="a_events.php" class="search-input-wrap">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" name="search" placeholder="Search by name, type, venue..." value="<?= htmlspecialchars($search) ?>">
                    </form>
                </div>

                <div style="overflow-x: auto;">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Client Contact</th>
                                <th>Event Details</th>
                                <th>Target Schedule</th>
                                <th>Venue</th>
                                <th>Estimated Total</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bookings)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 40px;">
                                        No bookings found matching the selected criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($bookings as $idx => $b): 
                                    $badge_class = 'badge-pending';
                                    if ($b['status'] === 'approved') $badge_class = 'badge-approved';
                                    elseif ($b['status'] === 'rejected') $badge_class = 'badge-rejected';

                                    $estimated_total_display = '';
                                    if (!empty($b['service_requirements'])) {
                                        if (preg_match('/ESTIMATED TOTAL:\s*(₱[\d,]+)/u', $b['service_requirements'], $m_total)) {
                                            $estimated_total_display = $m_total[1];
                                        }
                                    }

                                    $booking_json = htmlspecialchars(json_encode($b, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                                ?>
                                    <tr>
                                        <td style="font-family: monospace; font-weight: 700; color: var(--primary);">
                                            <?= htmlspecialchars($b['reference_no']) ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 700; font-size: 14px;"><?= htmlspecialchars($b['client_name']) ?></div>
                                            <div style="font-size: 12px; color: var(--text-muted); display: flex; align-items: center; gap: 5px; margin-top: 2px;">
                                                <i class="fa-solid fa-phone" style="font-size: 10px; color: var(--primary);"></i>
                                                <span><?= htmlspecialchars($b['client_phone']) ?></span>
                                            </div>
                                            <?php if (!empty($b['client_email'])): ?>
                                            <div style="font-size: 11px; color: var(--text-muted); display: flex; align-items: center; gap: 5px; margin-top: 1px;">
                                                <i class="fa-solid fa-envelope" style="font-size: 10px; color: var(--text-muted);"></i>
                                                <span><?= htmlspecialchars($b['client_email']) ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 700; font-size: 14px; color: var(--text-main);"><?= htmlspecialchars($b['event_title']) ?></div>
                                            <div style="display: flex; align-items: center; gap: 6px; margin-top: 4px; flex-wrap: wrap;">
                                                <span class="badge" style="background: <?= stripos($b['event_type'], 'Wedding') !== false ? '#ecfdf5' : '#fef2f2' ?>; color: <?= stripos($b['event_type'], 'Wedding') !== false ? '#065f46' : '#991b1b' ?>; font-size: 11px; padding: 2px 8px; font-weight: 600;">
                                                    <i class="fa-solid <?= stripos($b['event_type'], 'Wedding') !== false ? 'fa-rings-wedding' : 'fa-cake-candles' ?>" style="font-size: 10px;"></i>
                                                    <?= htmlspecialchars($b['event_type']) ?>
                                                </span>
                                                <?php if (!empty($b['guest_count'])): ?>
                                                    <span style="font-size: 11px; color: var(--text-muted); background: #f3f4f6; padding: 2px 6px; border-radius: 4px;">
                                                        <i class="fa-solid fa-users" style="font-size: 10px;"></i> <?= (int)$b['guest_count'] ?> guests
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?= date('M d, Y', strtotime($b['event_start'])) ?></div>
                                            <div style="font-size: 12px; color: var(--text-muted);"><?= date('g:i A', strtotime($b['event_start'])) ?></div>
                                        </td>
                                        <td>
                                            <div style="font-size: 13px; font-weight: 500;"><?= htmlspecialchars($b['location_venue']) ?></div>
                                        </td>
                                        <td>
                                            <?php if ($estimated_total_display): ?>
                                                <span class="badge" style="background: #232f22; color: #ffffff; font-weight: 700; font-size: 12px; padding: 4px 10px; border-radius: 6px; white-space: nowrap;">
                                                    <?= $estimated_total_display ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="font-size: 12px; color: var(--text-muted); font-style: italic;">Custom / TBD</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?= $badge_class ?>">
                                                <span class="badge-dot"></span>
                                                <?= ucfirst($b['status']) ?>
                                            </span>
                                        </td>
                                        <td                                            <div class="action-buttons">
                                                <?php if ($b['status'] === 'pending' || $b['status'] === 'rejected'): ?>
                                                    <button type="button" class="btn-action-approve" 
                                                        data-id="<?= (int)$b['id'] ?>" 
                                                        data-client="<?= htmlspecialchars($b['client_name'], ENT_QUOTES, 'UTF-8') ?>" 
                                                        data-title="<?= htmlspecialchars($b['event_title'], ENT_QUOTES, 'UTF-8') ?>" 
                                                        data-date="<?= htmlspecialchars(date('M d, Y h:i A', strtotime($b['event_start'])), ENT_QUOTES, 'UTF-8') ?>"
                                                        data-venue="<?= htmlspecialchars($b['location_venue'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-ref="<?= htmlspecialchars($b['reference_no'], ENT_QUOTES, 'UTF-8') ?>"
                                                        onclick="openApproveFromBtn(this)" 
                                                        title="Approve booking">
                                                        <i class="fa-solid fa-check"></i> Approve
                                                    </button>
                                                <?php endif; ?>

                                                <?php if ($b['status'] === 'pending' || $b['status'] === 'approved'): ?>
                                                    <button type="button" class="btn-action-reject" 
                                                        data-id="<?= (int)$b['id'] ?>" 
                                                        data-client="<?= htmlspecialchars($b['client_name'], ENT_QUOTES, 'UTF-8') ?>" 
                                                        data-title="<?= htmlspecialchars($b['event_title'], ENT_QUOTES, 'UTF-8') ?>" 
                                                        data-ref="<?= htmlspecialchars($b['reference_no'], ENT_QUOTES, 'UTF-8') ?>"
                                                        onclick="openRejectFromBtn(this)" 
                                                        title="Reject booking">
                                                        <i class="fa-solid fa-xmark"></i> Reject
                                                    </button>
                                                <?php endif; ?>

                                                <button type="button" class="btn-action-view" data-booking="<?= $booking_json ?>" onclick="openBookingFromBtn(this)" title="View booking details">
                                                    <i class="fa-solid fa-eye"></i> Details
                                                </button>

                                                <?php if ($b['status'] === 'rejected'): ?>
                                                    <button type="button" class="btn-action-delete" 
                                                        data-id="<?= (int)$b['id'] ?>" 
                                                        data-client="<?= htmlspecialchars($b['client_name'], ENT_QUOTES, 'UTF-8') ?>" 
                                                        data-title="<?= htmlspecialchars($b['event_title'], ENT_QUOTES, 'UTF-8') ?>" 
                                                        data-ref="<?= htmlspecialchars($b['reference_no'], ENT_QUOTES, 'UTF-8') ?>"
                                                        onclick="openDeleteFromBtn(this)" 
                                                        title="Permanently delete rejected booking">
                                                        <i class="fa-solid fa-trash"></i> Delete
                                                    </button>
                                                <?php endif; ?>
                                            </div>v>
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
