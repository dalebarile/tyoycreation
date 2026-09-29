<?php
require_once __DIR__ . '/db.php';

// Auth check
require_login();

$admin_username = $_SESSION['username'] ?? 'Admin';

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
    <title>SCHEDFIX - Admin Dashboard</title>
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
            gap: 6px;
            text-align: center;
            font-size: 13px;
        }

        .mini-cal-day-head {
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 700;
            padding-bottom: 6px;
        }

        .mini-cal-cell {
            padding: 8px 0;
            border-radius: 50%;
            cursor: pointer;
            transition: var(--transition);
        }

        .mini-cal-cell.has-event {
            background: var(--primary);
            color: white;
            font-weight: 700;
        }

        .mini-cal-cell.today {
            border: 1px solid var(--primary);
            color: var(--primary);
            font-weight: 700;
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
                <div class="admin-avatar" style="background: <?= is_main_admin() ? '#d97706' : '#364735' ?>; color: #fff;">
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

            <!-- Stat Counters (Screen 6) -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon pending">
                        <i class="fa-solid fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Pending Requests</div>
                        <div class="stat-count"><?= $pending_count ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon approved">
                        <i class="fa-solid fa-calendar-check"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Approved Bookings</div>
                        <div class="stat-count"><?= $approved_count ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon rejected">
                        <i class="fa-solid fa-circle-xmark"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Rejected Requests</div>
                        <div class="stat-count"><?= $rejected_count ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon total">
                        <i class="fa-solid fa-layer-group"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Total Events</div>
                        <div class="stat-count"><?= $total_count ?></div>
                    </div>
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

            <!-- Two-Column Grid: Upcoming Events & Mini Calendar -->
            <div class="dashboard-grid">
                <!-- Upcoming Events List -->
                <div class="upcoming-card">
                    <div class="card-header-flex">
                        <h2 style="font-size: 18px; font-weight: 700;">Upcoming Events</h2>
                        <a href="a_events.php?status=approved" style="font-size: 13px; font-weight: 600;">View All</a>
                    </div>

                    <?php if (empty($upcoming_events)): ?>
                        <p style="color: var(--text-muted); font-size: 14px; padding: 20px 0; text-align: center;">
                            No upcoming approved events scheduled yet.
                        </p>
                    <?php else: ?>
                        <?php foreach ($upcoming_events as $ev): 
                            $thumb = 'https://images.unsplash.com/photo-1519741497674-611481863552?auto=format&fit=crop&w=120&q=80';
                            if (strpos($ev['event_type'], 'Birthday') !== false || strpos($ev['event_type'], 'Kids') !== false) {
                                $thumb = 'https://images.unsplash.com/photo-1530103862676-de8c9debad1d?auto=format&fit=crop&w=120&q=80';
                            } elseif (strpos($ev['event_type'], 'Corporate') !== false) {
                                $thumb = 'https://images.unsplash.com/photo-1511578314322-379afb476865?auto=format&fit=crop&w=120&q=80';
                            } elseif (strpos($ev['event_type'], 'Debut') !== false) {
                                $thumb = 'https://images.unsplash.com/photo-1516450360452-9312f5e86fc7?auto=format&fit=crop&w=120&q=80';
                            }
                        ?>
                            <div class="event-item-row">
                                <div class="event-item-left">
                                    <img src="<?= $thumb ?>" alt="Event" class="event-thumb">
                                    <div class="event-info">
                                        <h4><?= htmlspecialchars($ev['event_title']) ?></h4>
                                        <span><?= date('M d, Y • g:i A', strtotime($ev['event_start'])) ?> • <?= htmlspecialchars($ev['location_venue']) ?></span>
                                    </div>
                                </div>
                                <span class="badge badge-approved">
                                    <span class="badge-dot"></span> Confirmed
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Mini Calendar Widget -->
                <div class="calendar-widget-card">
                    <div class="mini-cal-header">
                        <span><?= date('F Y') ?></span>
                        <a href="a_calendar.php" style="font-size: 13px; font-weight: 600;"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
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
                        $m_res = $conn->query("SELECT EXTRACT(DAY FROM event_start)::int as d FROM bookings WHERE status = 'approved' AND EXTRACT(MONTH FROM event_start) = EXTRACT(MONTH FROM CURRENT_DATE) AND EXTRACT(YEAR FROM event_start) = EXTRACT(YEAR FROM CURRENT_DATE)");
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