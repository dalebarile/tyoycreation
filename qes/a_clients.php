<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/data_privacy_helper.php';

// Auth check
require_login();

$admin_username = $_SESSION['username'] ?? 'Admin';
$search = trim($_GET['search'] ?? '');

$alert_message = '';
$alert_type = 'success';

// Handle Data-Subject Requests (DSR) & Retention Schedule Actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }

    $action = $_POST['action'] ?? '';

    // ACTION 1: Export Data-Subject Archive (Right to Data Portability - RA 10173 Sec. 36)
    if ($action === 'export_dsr') {
        $identifier = trim($_POST['identifier'] ?? '');
        $export = DataPrivacyHelper::exportDataSubjectArchive($conn, $identifier);
        if ($export['success']) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
            echo json_encode($export['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        } else {
            $alert_message = $export['message'] ?? 'Could not export data subject archive.';
            $alert_type = 'danger';
        }
    }

    // ACTION 2: Run Automated Retention Schedule Purge
    elseif ($action === 'run_retention_purge') {
        $purge_res = DataPrivacyHelper::runRetentionSchedulePurge($conn);
        $alert_message = $purge_res['message'];
        $alert_type = 'success';
    }

    // ACTION 3: Handle Client Erasure (DSR Right to Erasure / Blocking - RA 10173 Sec. 34(e))
    elseif ($action === 'delete_client') {
        $raw_ids = trim($_POST['booking_ids'] ?? '');
        $id_list = array_values(array_filter(array_map('intval', explode(',', $raw_ids)), fn($x) => $x > 0));
        $erasure_mode = in_array($_POST['erasure_mode'] ?? '', ['purge', 'anonymize'], true) ? $_POST['erasure_mode'] : 'purge';

        // Fallback: search by encrypted email or phone if booking_ids was missing
        if (empty($id_list)) {
            $email = trim($_POST['client_email'] ?? '');
            $phone = trim($_POST['client_phone'] ?? '');
            if (!empty($email) || !empty($phone)) {
                $enc_e = qes_encrypt($email, true);
                $enc_p = qes_encrypt($phone, true);
                $find_stmt = $conn->prepare("SELECT id FROM bookings WHERE client_email = ? OR (client_phone = ? AND client_phone != '')");
                if ($find_stmt) {
                    $find_stmt->bind_param("ss", $enc_e, $enc_p);
                    $find_stmt->execute();
                    $f_res = $find_stmt->get_result();
                    while ($f_row = $f_res->fetch_assoc()) {
                        $id_list[] = (int)$f_row['id'];
                    }
                    $find_stmt->close();
                }
            }
        }

        if (!empty($id_list)) {
            $erasure_res = DataPrivacyHelper::executeErasureRequest($conn, $id_list, $erasure_mode, $admin_username);
            $alert_message = $erasure_res['message'];
            $alert_type = $erasure_res['success'] ? 'success' : 'danger';
        } else {
            $alert_message = "No matching client booking records found for erasure.";
            $alert_type = 'warning';
        }
    }
}

// Pagination setup
$per_page = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$base_sql = "SELECT MAX(client_name) as client_name, client_email, client_phone, MAX(client_address) as client_address, COUNT(*) as total_events, MAX(event_start) as latest_event, GROUP_CONCAT(DISTINCT event_type SEPARATOR ', ') as event_types, GROUP_CONCAT(CAST(id AS CHAR) SEPARATOR ',') as booking_ids FROM bookings";
$group_order = " GROUP BY client_email, client_phone ORDER BY total_events DESC, latest_event DESC";

$clients = [];

if (!empty($search)) {
    // When searching, fetch grouped clients, decrypt them, and search accurately across client PII
    $all_stmt = $conn->query($base_sql . $group_order);
    $matched_clients = [];
    if ($all_stmt) {
        while ($row = $all_stmt->fetch_assoc()) {
            $row = qes_decrypt_booking($row);
            $haystack = mb_strtolower(($row['client_name'] ?? '') . ' ' . ($row['client_phone'] ?? '') . ' ' . ($row['client_email'] ?? '') . ' ' . ($row['client_address'] ?? '') . ' ' . ($row['event_types'] ?? ''));
            if (mb_stripos($haystack, mb_strtolower($search)) !== false) {
                $matched_clients[] = $row;
            }
        }
    }
    $total_records = count($matched_clients);
    $total_pages = max(1, (int)ceil($total_records / $per_page));
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset = max(0, ($page - 1) * $per_page);
    $clients = array_slice($matched_clients, $offset, $per_page);
} else {
    // Standard fast paginated load
    $count_sql = "SELECT COUNT(*) as total FROM (SELECT 1 FROM bookings GROUP BY client_email, client_phone) as t";
    $count_res = $conn->query($count_sql);
    $total_records = (int)($count_res->fetch_assoc()['total'] ?? 0);

    $total_pages = max(1, (int)ceil($total_records / $per_page));
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset = max(0, ($page - 1) * $per_page);

    $stmt = $conn->prepare($base_sql . $group_order . " LIMIT ?, ?");
    $stmt->bind_param("ii", $offset, $per_page);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $clients[] = qes_decrypt_booking($row);
        }
    }
    $stmt->close();
}

// Helper to preserve GET params for pagination links
$page_url = function($p) use ($search) {
    $params = ['page' => $p];
    if (!empty($search)) {
        $params['search'] = $search;
    }
    return 'a_clients.php?' . http_build_query($params);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Client Information - Tyoy Creation</title>
    <link rel="icon" type="image/png" href="assets/favicon.png?v=<?= filemtime(__DIR__ . '/assets/favicon.png') ?>">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .table-responsive-container {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .custom-table {
            width: 100%;
            min-width: 980px;
            border-collapse: collapse;
            text-align: left;
        }
        .custom-table th {
            background: #f8fafc;
            padding: 13px 16px;
            font-weight: 700;
            color: #475569;
            border-bottom: 1px solid #e2e8f0;
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .custom-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            vertical-align: middle;
            font-size: 13px;
        }
        .custom-table tr:hover {
            background: #fafcfb;
        }
        .client-name-cell {
            font-weight: 700;
            color: #1e293b;
            white-space: nowrap;
        }
        .client-phone-cell {
            font-family: 'SF Mono', Consolas, Menlo, monospace;
            font-size: 12.5px;
            color: #475569;
            white-space: nowrap;
        }
        .client-email-cell {
            color: #334155;
            white-space: nowrap;
        }
        .client-loc-cell {
            color: #64748b;
            max-width: 240px;
            white-space: normal;
            line-height: 1.4;
        }
        .client-events-cell {
            color: #64748b;
            font-size: 12.5px;
            white-space: nowrap;
        }
        .client-count-cell {
            text-align: center;
            white-space: nowrap;
        }
        .client-actions-cell {
            text-align: center;
            white-space: nowrap;
            min-width: 180px;
        }
        .btn-action-view, .btn-action-delete {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px !important;
            font-size: 12px !important;
            font-weight: 600;
            border-radius: 7px;
            white-space: nowrap;
            flex-shrink: 0;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-action-view {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
            text-decoration: none;
        }
        .btn-action-view:hover {
            background: #dcfce7;
            color: #14532d;
        }
        .btn-action-delete {
            background: #fff1f2;
            color: #be123c;
            border: 1px solid #fecdd3;
        }
        .btn-action-delete:hover {
            background: #ffe4e6;
            color: #9f1239;
        }
        .security-badge {
            font-size: 11px;
            font-weight: 600;
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
            flex-shrink: 0;
        }
    </style>
</head>
<body class="admin-app">

    <!-- Admin Sidebar -->
    <?php include __DIR__ . '/admin_sidebar.php'; ?>

    <main class="admin-main">
        <header class="admin-header">
            <h1 class="admin-page-title">Client Information Directory</h1>
            <div class="admin-profile">
                <div class="admin-avatar"><?= strtoupper(substr($admin_username, 0, 1)) ?></div>
                <div style="font-size: 14px; font-weight: 600;"><?= htmlspecialchars($admin_username) ?></div>
            </div>
        </header>

        <div class="admin-body">
            <!-- Alert Banner -->
            <?php if (!empty($alert_message)): ?>
                <div style="background: <?= $alert_type === 'warning' ? '#fef3c7' : ($alert_type === 'danger' ? '#fee2e2' : '#d1fae5') ?>; color: <?= $alert_type === 'warning' ? '#b45309' : ($alert_type === 'danger' ? '#991b1b' : '#065f46') ?>; border: 1px solid <?= $alert_type === 'warning' ? '#fde68a' : ($alert_type === 'danger' ? '#fca5a5' : '#a7f3d0') ?>; padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; font-size: 14px; font-weight: 600;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid <?= $alert_type === 'warning' ? 'fa-triangle-exclamation' : ($alert_type === 'danger' ? 'fa-circle-xmark' : 'fa-circle-check') ?>"></i>
                        <span><?= htmlspecialchars($alert_message) ?></span>
                    </div>
                    <button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; cursor:pointer; font-size:18px; color:inherit; line-height:1;" title="Dismiss">&times;</button>
                </div>
            <?php endif; ?>

            <div class="table-card">
                <div class="table-header-bar">
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <div style="font-weight: 700; font-size: 16px; color: var(--primary);">Registered Client Inquiries</div>
                        <span class="security-badge" title="Client personal identifiable data is securely encrypted in the database at rest and decrypted for authorized system accounts.">
                            <i class="fa-solid fa-shield-halved"></i> 256-Bit Encrypted at Rest
                        </span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <form method="POST" action="a_clients.php" style="margin: 0; display: inline;" onsubmit="return confirm('Execute automated retention schedule purge now? This will permanently clean rejected inquiries older than 7 days, expired sessions, and aged chat logs.');">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="run_retention_purge">
                            <button type="submit" class="btn-action-view" style="background: #18392b; color: #ffffff; padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;" title="Execute Automated Retention Schedule Purge per RA 10173">
                                <i class="fa-solid fa-clock-rotate-left"></i> Run Retention Purge
                            </button>
                        </form>
                        <form method="GET" action="a_clients.php" class="search-input-wrap">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" name="search" placeholder="Search client by name, phone..." value="<?= htmlspecialchars($search) ?>">
                        </form>
                    </div>
                </div>

                <div class="table-responsive-container">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Client Name</th>
                                <th>Phone Number</th>
                                <th>Email Address</th>
                                <th>Location / Address</th>
                                <th>Event History</th>
                                <th style="text-align: center;">Total Bookings</th>
                                <th style="text-align: center; min-width: 220px;">DSR &amp; Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($clients)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 36px;">
                                        No client records found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($clients as $c): ?>
                                    <tr>
                                        <td class="client-name-cell"><?= htmlspecialchars($c['client_name']) ?></td>
                                        <td class="client-phone-cell"><?= htmlspecialchars($c['client_phone']) ?></td>
                                        <td class="client-email-cell"><?= htmlspecialchars($c['client_email']) ?></td>
                                        <td class="client-loc-cell"><?= htmlspecialchars($c['client_address'] ?: 'N/A') ?></td>
                                        <td class="client-events-cell"><?= htmlspecialchars($c['event_types']) ?></td>
                                        <td class="client-count-cell">
                                            <span class="badge" style="background: #eef2ee; color: var(--primary); font-weight: 700; padding: 4px 10px; border-radius: 99px; font-size: 11.5px; display: inline-block;">
                                                <?= $c['total_events'] ?> Event<?= $c['total_events'] > 1 ? 's' : '' ?>
                                            </span>
                                        </td>
                                        <td class="client-actions-cell">
                                            <div style="display: flex; align-items: center; justify-content: center; gap: 6px; flex-wrap: nowrap;">
                                                <form method="POST" action="a_clients.php" style="margin: 0; display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                    <input type="hidden" name="action" value="export_dsr">
                                                    <input type="hidden" name="identifier" value="<?= htmlspecialchars(!empty($c['client_email']) ? $c['client_email'] : (!empty($c['client_phone']) ? $c['client_phone'] : $c['client_name'])) ?>">
                                                    <button type="submit" class="btn-action-view" style="background: #0284c7; color: #ffffff; border: none; padding: 6px 10px; border-radius: 6px; font-size: 11.5px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;" title="Export personal data archive in structured JSON format (RA 10173 Section 36 - Data Portability)">
                                                        <i class="fa-solid fa-file-export"></i> Export
                                                    </button>
                                                </form>
                                                <a href="a_notifications.php?recipient=<?= urlencode($c['client_name']) ?>&contact=<?= urlencode($c['client_email']) ?>" class="btn-action-view" style="padding: 6px 10px; font-size: 11.5px;" title="Message Client">
                                                    <i class="fa-solid fa-paper-plane"></i> Message
                                                </a>
                                                <button type="button" class="btn-action-delete" style="padding: 6px 10px; font-size: 11.5px;" onclick="openDeleteClientModal(<?= htmlspecialchars(json_encode($c['client_name'])) ?>, <?= htmlspecialchars(json_encode($c['client_phone'])) ?>, <?= htmlspecialchars(json_encode($c['client_email'])) ?>, <?= (int)$c['total_events'] ?>, <?= htmlspecialchars(json_encode($c['booking_ids'] ?? '')) ?>)" title="Right to Erasure / Blocking (RA 10173 Section 34(e))">
                                                    <i class="fa-solid fa-trash-can"></i> Erasure
                                                </button>
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

    <!-- Delete Client Verification Modal -->
    <div class="modal-backdrop" id="deleteClientModal">
        <div class="modal-card" style="max-width: 520px; flex-direction: column;">
            <form method="POST" action="a_clients.php" id="deleteClientForm">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="delete_client">
                <input type="hidden" name="booking_ids" id="deleteBookingIds" value="">
                <input type="hidden" name="client_email" id="deleteClientEmail" value="">
                <input type="hidden" name="client_phone" id="deleteClientPhone" value="">
                <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                <input type="hidden" name="page" value="<?= (int)$page ?>">

                <div style="padding: 20px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #fff5f5;">
                    <div style="display: flex; align-items: center; gap: 10px; color: #dc2626;">
                        <i class="fa-solid fa-user-shield" style="font-size: 20px;"></i>
                        <h3 style="font-size: 17px; margin: 0; font-weight: 700;">Data-Subject Right to Erasure</h3>
                    </div>
                    <button type="button" onclick="closeDeleteClientModal()" style="background: transparent; border: none; font-size: 20px; color: var(--text-muted); cursor: pointer;" aria-label="Close">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div style="padding: 24px;">
                    <p style="font-size: 13.5px; color: #374151; margin-bottom: 14px; line-height: 1.5;">
                        Process a verified data erasure request under <strong>Section 34(e) of the Data Privacy Act of 2012 (RA 10173)</strong>. Select an appropriate compliance mode below:
                    </p>
                    
                    <!-- Client Preview Card -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; font-size: 12.5px;">
                        <div style="margin-bottom: 6px;">
                            <span style="color: #64748b; font-weight: 600;">Client:</span>
                            <strong id="delModalClientName" style="color: #0f172a; margin-left: 6px;">-</strong>
                        </div>
                        <div style="margin-bottom: 6px;">
                            <span style="color: #64748b; font-weight: 600;">Phone / Email:</span>
                            <span id="delModalClientPhone" style="color: #334155; margin-left: 6px;">-</span> &bull; <span id="delModalClientEmail" style="color: #334155;">-</span>
                        </div>
                        <div>
                            <span style="color: #64748b; font-weight: 600;">Associated Inquiries:</span>
                            <span id="delModalBookingCount" class="badge" style="background: #fee2e2; color: #dc2626; font-weight: 700; margin-left: 6px;">-</span>
                        </div>
                    </div>

                    <!-- Erasure Mode Selection -->
                    <div style="margin-bottom: 16px;">
                        <div style="font-weight: 700; font-size: 13px; color: #1e293b; margin-bottom: 8px;">Select Compliance Mode:</div>
                        
                        <label style="display: flex; align-items: flex-start; gap: 10px; background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 10px 14px; margin-bottom: 8px; cursor: pointer;">
                            <input type="radio" name="erasure_mode" value="anonymize" checked style="margin-top: 3px; accent-color: #18392b;">
                            <div>
                                <strong style="color: #0f172a; font-size: 13px;">Audit-Safe Anonymization (Recommended for Past/Completed Events)</strong>
                                <p style="margin: 2px 0 0 0; font-size: 11.5px; color: #64748b; line-height: 1.4;">
                                    Permanently scrubs and removes all personal identification (Name, Email, Phone, Address), while keeping non-PII financial totals and dates to comply with <strong>BIR 5-year accounting and tax audit rules</strong>.
                                </p>
                            </div>
                        </label>

                        <label style="display: flex; align-items: flex-start; gap: 10px; background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 10px 14px; cursor: pointer;">
                            <input type="radio" name="erasure_mode" value="purge" style="margin-top: 3px; accent-color: #dc2626;">
                            <div>
                                <strong style="color: #991b1b; font-size: 13px;">Full Permanent Purge (Hard Delete)</strong>
                                <p style="margin: 2px 0 0 0; font-size: 11.5px; color: #64748b; line-height: 1.4;">
                                    Permanently removes and deletes the entire booking records and notification history. Recommended for cancelled, unconfirmed, or duplicate inquiries.
                                </p>
                            </div>
                        </label>
                    </div>

                    <div style="background: #fff1f2; border-left: 4px solid #f43f5e; padding: 10px 14px; border-radius: 4px; font-size: 12px; color: #9f1239; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-circle-exclamation" style="font-size: 14px; flex-shrink: 0;"></i>
                        <span>This action will be written to the system audit trail. Irreversible once confirmed.</span>
                    </div>
                </div>

                <div style="padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 10px; background: #fafafa;">
                    <button type="button" class="btn-secondary" onclick="closeDeleteClientModal()" style="padding: 9px 18px; font-size: 13px; font-weight: 600;">Cancel</button>
                    <button type="submit" class="btn-action-reject" style="padding: 9px 18px; font-size: 13px; font-weight: 700; background: #dc2626; color: #ffffff; border: none; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-check"></i> Execute Erasure Request
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openDeleteClientModal(clientName, phone, email, bookingCount, bookingIds) {
            document.getElementById('delModalClientName').textContent = clientName || 'N/A';
            document.getElementById('delModalClientPhone').textContent = phone || 'N/A';
            document.getElementById('delModalClientEmail').textContent = email || 'N/A';
            document.getElementById('delModalBookingCount').textContent = bookingCount + (bookingCount === 1 ? ' Event' : ' Events');
            document.getElementById('deleteBookingIds').value = bookingIds || '';
            document.getElementById('deleteClientEmail').value = email || '';
            document.getElementById('deleteClientPhone').value = phone || '';
            document.getElementById('deleteClientModal').classList.add('active');
        }

        function closeDeleteClientModal() {
            document.getElementById('deleteClientModal').classList.remove('active');
        }

        // Close when clicking outside of modal
        window.addEventListener('click', function(e) {
            var modal = document.getElementById('deleteClientModal');
            if (e.target === modal) {
                closeDeleteClientModal();
            }
        });

        // Close on Escape key press
        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDeleteClientModal();
            }
        });
    </script>
</body>
</html>
