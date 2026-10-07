<?php
require_once __DIR__ . '/db.php';

// Auth check
require_login();

$admin_username = $_SESSION['username'] ?? 'Admin';
$business_name = get_setting($conn, 'business_name', 'Tyoy Creation');

// Fetch summary counts
$pending_count = 0;
$approved_count = 0;
$rejected_count = 0;
$total_count = 0;

$res = $conn->query("SELECT status, COUNT(*) as cnt FROM bookings GROUP BY status");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        if ($row['status'] === 'pending') $pending_count = (int)$row['cnt'];
        if ($row['status'] === 'approved') $approved_count = (int)$row['cnt'];
        if ($row['status'] === 'rejected') $rejected_count = (int)$row['cnt'];
        $total_count += (int)$row['cnt'];
    }
}

// Fetch upcoming approved events
$upcoming_events = [];
$up_stmt = $conn->query("SELECT * FROM bookings WHERE status = 'approved' AND event_start >= NOW() ORDER BY event_start ASC LIMIT 5");
if ($up_stmt) {
    while ($row = $up_stmt->fetch_assoc()) {
        $upcoming_events[] = qes_decrypt_booking($row);
    }
}

// Fetch recent pending inquiries requiring admin review
$pending_inquiries = [];
$pen_stmt = $conn->query("SELECT * FROM bookings WHERE status = 'pending' ORDER BY created_at DESC LIMIT 5");
if ($pen_stmt) {
    while ($row = $pen_stmt->fetch_assoc()) {
        $pending_inquiries[] = qes_decrypt_booking($row);
    }
}

// Fetch recent bookings for Panel 8 Recent Bookings table
$recent_bookings = [];
$rb_stmt = $conn->query("SELECT * FROM bookings ORDER BY created_at DESC LIMIT 6");
if ($rb_stmt) {
    while ($row = $rb_stmt->fetch_assoc()) {
        $recent_bookings[] = qes_decrypt_booking($row);
    }
}

// Fetch total clients count
$total_clients = 0;
$cl_res = $conn->query("SELECT COUNT(DISTINCT client_email) as cnt FROM bookings");
if ($cl_res && $cl_row = $cl_res->fetch_assoc()) {
    $total_clients = (int)$cl_row['cnt'];
}
if ($total_clients === 0) {
    $u_res = $conn->query("SELECT COUNT(*) as cnt FROM users");
    if ($u_res && $u_row = $u_res->fetch_assoc()) {
        $total_clients = (int)$u_row['cnt'];
    }
}


// ─── Gemini Free Tier API Quota ───────────────────────────────────────────────
$GLOBAL_DAILY_LIMIT = 1400;
$today = date('Y-m-d');
$stored_date = get_setting($conn, 'gemini_daily_api_date', $today);
if ($stored_date !== $today) {
    $ai_daily_used = 0;
} else {
    $ai_daily_used = (int)get_setting($conn, 'gemini_daily_api_count', 0);
}
$ai_daily_remaining = max(0, $GLOBAL_DAILY_LIMIT - $ai_daily_used);
$ai_pct = ($GLOBAL_DAILY_LIMIT > 0) ? round(($ai_daily_used / $GLOBAL_DAILY_LIMIT) * 100) : 0;
$ai_status_color = $ai_pct >= 90 ? '#ef4444' : ($ai_pct >= 70 ? '#f59e0b' : '#22c55e');
$ai_status_label = $ai_pct >= 90 ? 'Critical' : ($ai_pct >= 70 ? 'Warning' : 'Healthy');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($business_name) ?> - Admin Dashboard</title>
    <link rel="icon" type="image/png" href="assets/favicon.png?v=<?= filemtime(__DIR__ . '/assets/favicon.png') ?>">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
        }

        .upcoming-card, .calendar-widget-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            padding: 24px;
        }

        .card-header-flex {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .event-item-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .event-item-row:last-child {
            border-bottom: none;
        }

        .event-item-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .event-thumb {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-md);
            object-fit: cover;
        }

        .event-info h4 {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .event-info span {
            font-size: 12px;
            color: var(--text-muted);
        }

        /* Mini Calendar Grid */
        .mini-cal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 700;
            margin-bottom: 16px;
        }

        .mini-cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px 6px;
            text-align: center;
            font-size: 13px;
            align-items: center;
            justify-items: center;
        }

        .mini-cal-day-head {
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 700;
            padding-bottom: 6px;
            width: 100%;
            text-align: center;
        }

        .mini-cal-cell {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            cursor: pointer;
            transition: var(--transition);
            margin: 0 auto;
            font-size: 13px;
            font-weight: 500;
        }

        .mini-cal-cell:hover {
            background: #f1f5f9;
        }

        .mini-cal-cell.has-event {
            background: var(--primary, #18392b);
            color: #ffffff;
            font-weight: 700;
        }

        .mini-cal-cell.today {
            border: 2px solid var(--primary, #18392b);
            color: var(--primary, #18392b);
            font-weight: 700;
        }

        .mini-cal-cell.today.has-event {
            background: var(--primary, #18392b);
            color: #ffffff;
            border: 2px solid #c5a059;
        }
        /* AI Quota Widget */
        .ai-quota-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            padding: 20px 24px;
            margin-bottom: 24px;
        }

        .ai-quota-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .ai-quota-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 700;
        }

        .ai-quota-title i {
            color: #364735;
        }

        .ai-quota-bar-wrap {
            background: #f1f5f9;
            border-radius: 99px;
            height: 10px;
            overflow: hidden;
            flex: 1;
            margin: 0 16px;
        }

        .ai-quota-bar-fill {
            height: 100%;
            border-radius: 99px;
            transition: width 0.4s ease;
        }

        .ai-quota-stats {
            display: flex;
            gap: 24px;
            font-size: 13px;
            color: var(--text-muted);
        }

        .ai-quota-stats strong {
            color: var(--text-primary);
        }

        .ai-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 99px;
            color: white;
        }
    </style>
</head>
<body class="admin-app">

    <!-- Admin Sidebar -->
    <?php include __DIR__ . '/admin_sidebar.php'; ?>

    <!-- Main Content Area -->
    <main class="admin-main">
        <header class="admin-header">
            <h1 class="admin-page-title">Dashboard</h1>
            <div class="admin-profile">
                <div class="admin-avatar" style="background: <?= is_main_admin() ? '#d97706' : '#18392b' ?>; color: #fff;">
                    <?= strtoupper(substr($admin_username, 0, 1)) ?>
                </div>
                <div>
                    <div style="font-size: 14px; font-weight: 600;">
                        <?= htmlspecialchars($admin_username) ?>
                    </div>
                    <span style="font-size: 10px; font-weight: 800; background: <?= is_main_admin() ? '#d97706' : '#6b7280' ?>; color: #fff; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">
                        <?= is_main_admin() ? 'Main Admin' : 'Admin' ?>
                    </span>
                </div>
            </div>
        </header>

        <div class="admin-body">

            <!-- Gemini Free Tier AI Quota Monitor -->
            <div class="ai-quota-card">
                <div class="ai-quota-header">
                    <div class="ai-quota-title">
                        <i class="fa-solid fa-robot"></i>
                        <span>AI Chatbot Daily Quota <small style="font-weight:400;color:var(--text-muted);">(Gemini Free Tier)</small></span>
                    </div>
                    <span class="ai-status-badge" style="background: <?= $ai_status_color ?>;">
                        <i class="fa-solid fa-circle" style="font-size:7px;"></i>
                        <?= $ai_status_label ?>
                    </span>
                </div>
                <div style="display:flex;align-items:center;gap:0;">
                    <span style="font-size:13px;color:var(--text-muted);white-space:nowrap;"><?= $ai_daily_used ?> / <?= $GLOBAL_DAILY_LIMIT ?> used</span>
                    <div class="ai-quota-bar-wrap">
                        <div class="ai-quota-bar-fill" style="width:<?= $ai_pct ?>%;background:<?= $ai_status_color ?>;"></div>
                    </div>
                    <span style="font-size:13px;font-weight:700;white-space:nowrap;color:<?= $ai_status_color ?>;"><?= $ai_pct ?>%</span>
                </div>
                <div class="ai-quota-stats" style="margin-top:10px;">
                    <span><strong><?= $ai_daily_remaining ?></strong> requests remaining today</span>
                    <span>•</span>
                    <span>Resets at <strong>midnight (12:00 AM)</strong></span>
                    <span>•</span>
                    <span>Hard cap: <strong>1,500 RPD / 15 RPM</strong> (Google Free Tier)</span>
                </div>
            </div>

            <!-- Panel 8: 4 KPI Cards -->
            <div class="stats-wire-grid-admin" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 18px; margin-bottom: 24px;">
                <div style="background: #ffffff; border: 1px solid var(--border-color); border-top: 3px solid #18392b; border-radius: 12px; padding: 20px 22px; box-shadow: 0 4px 14px rgba(24, 57, 43, 0.04);">
                    <div style="font-size: 13px; font-weight: 600; color: #3d5345; margin-bottom: 8px;">Total Bookings</div>
                    <div style="font-size: 32px; font-weight: 800; color: #18392b; line-height: 1.1; margin-bottom: 6px;"><?= $total_count ?></div>
                    <div style="font-size: 12px; color: #15803d; font-weight: 600;">All active records</div>
                </div>

                <div style="background: #ffffff; border: 1px solid var(--border-color); border-top: 3px solid #d97706; border-radius: 12px; padding: 20px 22px; box-shadow: 0 4px 14px rgba(24, 57, 43, 0.04);">
                    <div style="font-size: 13px; font-weight: 600; color: #3d5345; margin-bottom: 8px;">Pending Requests</div>
                    <div style="font-size: 32px; font-weight: 800; color: #d97706; line-height: 1.1; margin-bottom: 6px;"><?= $pending_count ?></div>
                    <div style="font-size: 12px; color: #d97706; font-weight: 600;">Needs attention</div>
                </div>

                <div style="background: #ffffff; border: 1px solid var(--border-color); border-top: 3px solid #2d6a4f; border-radius: 12px; padding: 20px 22px; box-shadow: 0 4px 14px rgba(24, 57, 43, 0.04);">
                    <div style="font-size: 13px; font-weight: 600; color: #3d5345; margin-bottom: 8px;">Upcoming Events</div>
                    <div style="font-size: 32px; font-weight: 800; color: #2d6a4f; line-height: 1.1; margin-bottom: 6px;"><?= count($upcoming_events) ?></div>
                    <div style="font-size: 12px; color: #667d6f; font-weight: 600;">Next 30 days</div>
                </div>

                <div style="background: #ffffff; border: 1px solid var(--border-color); border-top: 3px solid #c5a059; border-radius: 12px; padding: 20px 22px; box-shadow: 0 4px 14px rgba(24, 57, 43, 0.04);">
                    <div style="font-size: 13px; font-weight: 600; color: #3d5345; margin-bottom: 8px;">Total Clients</div>
                    <div style="font-size: 32px; font-weight: 800; color: #18392b; line-height: 1.1; margin-bottom: 6px;"><?= $total_clients ?></div>
                    <div style="font-size: 12px; color: #15803d; font-weight: 600;">Customer accounts</div>
                </div>
            </div>

            <?php if ($pending_count > 0): ?>
                <!-- Pending Inquiries Attention Alert -->
                <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: var(--radius-lg); padding: 18px 24px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; gap: 16px; box-shadow: var(--shadow-sm);">
                    <div style="display: flex; align-items: center; gap: 14px;">
                        <div style="width: 44px; height: 44px; border-radius: 50%; background: #fef3c7; color: #b45309; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;">
                            <i class="fa-solid fa-bell"></i>
                        </div>
                        <div>
                            <div style="font-weight: 700; font-size: 15px; color: #92400e;">
                                <?= $pending_count ?> New Client <?= ($pending_count === 1) ? 'Inquiry' : 'Inquiries' ?> Awaiting Your Review
                            </div>
                            <div style="font-size: 13px; color: #b45309; margin-top: 2px;">
                                Clients have submitted booking inquiries through the public booking portal. Review their requested dates and custom package estimates.
                            </div>
                        </div>
                    </div>
                    <a href="a_events.php?status=pending" class="btn-primary" style="padding: 10px 20px; font-size: 13px; font-weight: 700; text-decoration: none; white-space: nowrap; border-radius: 8px;">
                        <i class="fa-solid fa-clock"></i> Review Inquiries
                    </a>
                </div>
            <?php endif; ?>

            <!-- Panel 8: Two-Column Grid (Recent Bookings Table + Upcoming Events List) -->
            <div class="dashboard-grid" style="grid-template-columns: 1.5fr 1fr; gap: 24px;">
                <!-- Recent Bookings Table (Panel 8) -->
                <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); overflow: hidden; height: fit-content;">
                    <div style="padding: 18px 22px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                        <h2 style="font-size: 16px; font-weight: 700; color: #1f2937; margin: 0;">Recent Bookings</h2>
                        <a href="a_events.php" style="font-size: 12.5px; font-weight: 600; color: #233a2d; text-decoration: none;">View All &rarr;</a>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="custom-table" style="margin: 0; width: 100%;">
                            <thead>
                                <tr>
                                    <th style="padding: 12px 18px; font-size: 11.5px; text-transform: none; color: #4b5563; font-weight: 600;">Client Name</th>
                                    <th style="padding: 12px 18px; font-size: 11.5px; text-transform: none; color: #4b5563; font-weight: 600;">Event Type</th>
                                    <th style="padding: 12px 18px; font-size: 11.5px; text-transform: none; color: #4b5563; font-weight: 600;">Date</th>
                                    <th style="padding: 12px 18px; font-size: 11.5px; text-transform: none; color: #4b5563; font-weight: 600;">Status</th>
                                    <th style="padding: 12px 18px; font-size: 11.5px; text-transform: none; color: #4b5563; font-weight: 600;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_bookings)): ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; color: #9ca3af; padding: 24px;">No bookings found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_bookings as $rb): 
                                        $status_label = ucfirst($rb['status'] ?? 'pending');
                                        $status_bg = '#fef3c7';
                                        $status_color = '#92400e';
                                        if ($rb['status'] === 'approved') {
                                            $status_bg = '#ecfdf5';
                                            $status_color = '#065f46';
                                        } elseif ($rb['status'] === 'rejected') {
                                            $status_bg = '#fee2e2';
                                            $status_color = '#991b1b';
                                        }
                                        $date_val = !empty($rb['event_start']) ? date('Y-m-d', strtotime($rb['event_start'])) : date('Y-m-d', strtotime($rb['created_at']));
                                    ?>
                                        <tr>
                                            <td style="padding: 12px 18px; font-weight: 600; font-size: 13.5px; color: #111827;">
                                                <?= htmlspecialchars($rb['client_name'] ?? 'Client') ?>
                                            </td>
                                            <td style="padding: 12px 18px; font-size: 13px; color: #4b5563;">
                                                <?= htmlspecialchars($rb['event_type'] ?? 'Event') ?>
                                            </td>
                                            <td style="padding: 12px 18px; font-size: 13px; color: #4b5563;">
                                                <?= htmlspecialchars($date_val) ?>
                                            </td>
                                            <td style="padding: 12px 18px;">
                                                <span style="display: inline-block; padding: 3px 10px; border-radius: 9999px; font-size: 11.5px; font-weight: 600; background: <?= $status_bg ?>; color: <?= $status_color ?>;">
                                                    <?= htmlspecialchars($status_label) ?>
                                                </span>
                                            </td>
                                            <td style="padding: 12px 18px;">
                                                <a href="a_events.php?status=<?= urlencode($rb['status']) ?>" style="color: #233a2d; font-size: 12.5px; font-weight: 600; text-decoration: underline;">
                                                    View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Upcoming Events List with Date Chips (Panel 8) -->
                <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: 12px; padding: 20px 22px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); height: fit-content;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h2 style="font-size: 15.5px; font-weight: 700; color: #1f2937; margin: 0; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-regular fa-calendar-check" style="color: #233a2d;"></i> Upcoming Events
                        </h2>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 14px;">
                        <?php if (empty($upcoming_events)): ?>
                            <div style="text-align: center; color: #9ca3af; font-size: 13px; padding: 20px 0;">No upcoming events scheduled.</div>
                        <?php else: ?>
                            <?php foreach ($upcoming_events as $ev): ?>
                                <div style="display: flex; align-items: center; gap: 12px; padding-bottom: 12px; border-bottom: 1px solid #f3f4f6;">
                                    <div style="background: #eef2ee; color: #233a2d; font-size: 11px; font-weight: 700; padding: 4px 8px; border-radius: 6px; text-transform: uppercase; white-space: nowrap;">
                                        <?= date('M d', strtotime($ev['event_start'])) ?>
                                    </div>
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="font-size: 13px; font-weight: 600; color: #1f2937; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                            <?= htmlspecialchars(($ev['event_type'] ?? 'Event') . ' - ' . ($ev['client_name'] ?? $ev['event_title'] ?? '')) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div style="text-align: right; margin-top: 14px; padding-top: 10px;">
                        <a href="a_events.php?status=approved" style="font-size: 12.5px; font-weight: 600; color: #233a2d; text-decoration: none;">
                            View All &rarr;
                        </a>
                    </div>
                </div>
            </div>

            <!-- Mini Calendar Widget Below Grid -->
            <div style="margin-top: 24px;">
                <div class="calendar-widget-card" style="border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                    <div class="mini-cal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                        <span style="font-weight: 700; font-size: 15px; color: #1f2937;"><i class="fa-regular fa-calendar-days" style="color: #233a2d; margin-right: 6px;"></i> Event Calendar &bull; <?= date('F Y') ?></span>
                        <a href="a_calendar.php" style="font-size: 12.5px; font-weight: 600; color: #233a2d; text-decoration: none;">Open Master Calendar &rarr;</a>
                    </div>
                    <div class="mini-cal-grid">
                        <div class="mini-cal-day-head">Sun</div>
                        <div class="mini-cal-day-head">Mon</div>
                        <div class="mini-cal-day-head">Tue</div>
                        <div class="mini-cal-day-head">Wed</div>
                        <div class="mini-cal-day-head">Thu</div>
                        <div class="mini-cal-day-head">Fri</div>
                        <div class="mini-cal-day-head">Sat</div>

                        <?php
                        $days_in_month = (int)date('t');
                        $first_day_of_month = (int)date('w', strtotime(date('Y-m-01')));
                        $today_day = (int)date('j');

                        // Fetch active event days this month using standard SQL
                        $event_days = [];
                        $m_res = $conn->query("SELECT EXTRACT(DAY FROM (event_start AT TIME ZONE 'Asia/Manila'))::int as d FROM bookings WHERE status = 'approved' AND EXTRACT(MONTH FROM (event_start AT TIME ZONE 'Asia/Manila')) = EXTRACT(MONTH FROM (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')) AND EXTRACT(YEAR FROM (event_start AT TIME ZONE 'Asia/Manila')) = EXTRACT(YEAR FROM (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila'))");
                        if ($m_res) {
                            while ($r = $m_res->fetch_assoc()) {
                                $event_days[] = (int)$r['d'];
                            }
                        }

                        // Pad empty days
                        for ($pad = 0; $pad < $first_day_of_month; $pad++) {
                            echo '<div></div>';
                        }

                        for ($d = 1; $d <= $days_in_month; $d++) {
                            $classes = ['mini-cal-cell'];
                            if (in_array($d, $event_days)) $classes[] = 'has-event';
                            if ($d === $today_day) $classes[] = 'today';
                            echo '<div class="' . implode(' ', $classes) . '">' . $d . '</div>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

</body>
</html>