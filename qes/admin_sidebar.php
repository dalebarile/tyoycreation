<?php
// Shared Admin Sidebar for EventVista
require_once __DIR__ . '/db.php';

// Auth check
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin', 'main_admin'])) {
    header("Location: loginadmin.php");
    exit;
}

$is_main_admin = in_array($_SESSION['role'] ?? '', ['main_admin', 'super_admin']);

// Fetch pending count for badge (cached / reused if already computed on page)
$pending_count = 0;
if (isset($status_counts['pending'])) {
    $pending_count = (int)$status_counts['pending'];
    $_SESSION['admin_pending_badge_cnt'] = $pending_count;
    $_SESSION['admin_pending_badge_at'] = time();
} elseif (isset($_SESSION['admin_pending_badge_cnt']) && (time() - ($_SESSION['admin_pending_badge_at'] ?? 0)) < 120) {
    $pending_count = (int)$_SESSION['admin_pending_badge_cnt'];
} else {
    $p_res = $conn->query("SELECT COUNT(*) as cnt FROM bookings WHERE status = 'pending'");
    if ($p_res && $p_row = $p_res->fetch_assoc()) {
        $pending_count = (int)$p_row['cnt'];
        $_SESSION['admin_pending_badge_cnt'] = $pending_count;
        $_SESSION['admin_pending_badge_at'] = time();
    }
}

$current_page = basename($_SERVER['PHP_SELF']);
$current_status = $_GET['status'] ?? '';
?>
<!-- FontAwesome CDN for clean icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<aside class="admin-sidebar">
    <div class="sidebar-brand" style="gap: 10px; align-items: center; justify-content: space-between; display: flex; padding: 16px 20px;">
        <img src="assets/tyoy_logo_cropped.png?v=<?= filemtime(__DIR__ . '/assets/tyoy_logo_cropped.png') ?>" alt="Tyoy Creation" style="height: 38px; width: auto; max-width: 140px; border-radius: 6px; display: block; object-fit: contain;">
        <span style="font-size: 10px; font-weight: 800; padding: 3px 8px; border-radius: 4px; text-transform: uppercase; letter-spacing: 0.05em; background: <?= $is_main_admin ? '#d97706' : 'rgba(255,255,255,0.2)' ?>; color: #fff;">
            <?= $is_main_admin ? 'Main Admin' : 'Admin' ?>
        </span>
    </div>

    <nav class="sidebar-nav">
        <a href="a_home.php" class="sidebar-link <?= ($current_page === 'a_home.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-chart-pie"></i>
            <span>Dashboard</span>
        </a>

        <a href="a_events.php?status=pending" class="sidebar-link <?= ($current_page === 'a_events.php' && $current_status === 'pending') ? 'active' : '' ?>">
            <i class="fa-solid fa-clock"></i>
            <span>Pending Requests</span>
            <?php if ($pending_count > 0): ?>
                <span class="sidebar-badge"><?= $pending_count ?></span>
            <?php endif; ?>
        </a>

        <a href="a_events.php?status=bookings" class="sidebar-link <?= ($current_page === 'a_events.php' && in_array($current_status, ['bookings', 'approved', 'rejected'])) ? 'active' : '' ?>">
            <i class="fa-solid fa-calendar-check"></i>
            <span>Bookings</span>
        </a>


        <a href="a_calendar.php" class="sidebar-link <?= ($current_page === 'a_calendar.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-calendar-days"></i>
            <span>Master Calendar</span>
        </a>

        <?php if ($is_main_admin): ?>
        <a href="a_manual_booking.php" class="sidebar-link <?= ($current_page === 'a_manual_booking.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-file-circle-plus"></i>
            <span>Manual Entry</span>
        </a>

        <a href="a_sitemanager.php" class="sidebar-link <?= in_array($current_page, ['a_sitemanager.php', 'a_packages.php']) ? 'active' : '' ?>">
            <i class="fa-solid fa-palette"></i>
            <span>Edit Web Templates</span>
        </a>
        <?php endif; ?>

        <a href="a_clients.php" class="sidebar-link <?= ($current_page === 'a_clients.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-users"></i>
            <span>Client Information</span>
        </a>

        <?php if ($is_main_admin): ?>
        <a href="a_notifications.php" class="sidebar-link <?= ($current_page === 'a_notifications.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-bell"></i>
            <span>Notifications</span>
        </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <a href="a_settings.php" class="sidebar-link <?= ($current_page === 'a_settings.php') ? 'active' : '' ?>">
            <i class="fa-solid fa-gear"></i>
            <span>Settings</span>
        </a>
        <button type="button" class="sidebar-link" onclick="openLogoutModal()" style="background: none; border: none; width: 100%; text-align: left; cursor: pointer; font-family: inherit; font-size: inherit; color: inherit;">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
            <span>Logout</span>
        </button>
    </div>
</aside>

<!-- Global Admin Logout Confirmation Modal -->
<div class="modal-backdrop" id="adminLogoutModal" style="z-index: 9999;">
    <div class="modal-card" style="max-width: 460px; flex-direction: column; border-radius: 16px; overflow: hidden; box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.4);">
        <!-- Modal Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #fee2e2; display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, #fff5f5 0%, #fef2f2 100%);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 40px; height: 40px; border-radius: 12px; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 2px 8px rgba(220, 38, 38, 0.15);">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i>
                </div>
                <div>
                    <h3 style="font-size: 17px; margin: 0; font-weight: 700; color: #991b1b;">Confirm Sign Out</h3>
                    <p style="font-size: 12px; color: #b91c1c; margin: 2px 0 0 0;">End administrative session</p>
                </div>
            </div>
            <button type="button" onclick="closeLogoutModal()" style="background: transparent; border: none; font-size: 20px; color: #9ca3af; cursor: pointer; border-radius: 6px; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; transition: all 0.15s ease;" aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <div style="padding: 24px;">
            <p style="font-size: 14px; color: #374151; margin: 0 0 16px 0; line-height: 1.5;">
                Are you sure you want to end your current administrative session?
            </p>

            <!-- Admin Session Info Card -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; font-size: 13px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span style="color: #64748b; font-weight: 600;">Active Account:</span>
                    <strong style="color: #0f172a; font-size: 13px;">
                        <i class="fa-solid fa-user-shield" style="color: #18392b; margin-right: 4px;"></i>
                        <?= htmlspecialchars($_SESSION['username'] ?? 'Administrator') ?>
                    </strong>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="color: #64748b; font-weight: 600;">Security Role:</span>
                    <span style="background: <?= is_main_admin() ? '#fef3c7' : '#eaf2ec' ?>; color: <?= is_main_admin() ? '#b45309' : '#18392b' ?>; font-size: 11px; font-weight: 800; padding: 2px 8px; border-radius: 4px; text-transform: uppercase; letter-spacing: 0.05em;">
                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', $_SESSION['role'] ?? 'Admin'))) ?>
                    </span>
                </div>
            </div>

            <!-- Notice Alert -->
            <div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 10px 14px; border-radius: 6px; font-size: 12px; color: #92400e; display: flex; align-items: flex-start; gap: 8px; line-height: 1.4;">
                <i class="fa-solid fa-circle-info" style="font-size: 14px; color: #d97706; margin-top: 1px; flex-shrink: 0;"></i>
                <span>You will need to enter your administrative credentials again to regain access to the portal dashboard.</span>
            </div>
        </div>

        <!-- Modal Footer -->
        <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 10px; background: #fafafa;">
            <button type="button" class="btn-secondary" onclick="closeLogoutModal()" style="padding: 10px 20px; font-size: 13px; font-weight: 600; border-radius: 8px; cursor: pointer;">Stay Signed In</button>
            <a href="logout.php" style="padding: 10px 22px; font-size: 13px; font-weight: 700; background: #dc2626; color: #ffffff; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3); transition: all 0.2s ease;">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out Now
            </a>
        </div>
    </div>
</div>

<script>
if (typeof openLogoutModal === 'undefined') {
    function openLogoutModal() {
        const m = document.getElementById('adminLogoutModal');
        if (m) m.classList.add('active');
    }
    function closeLogoutModal() {
        const m = document.getElementById('adminLogoutModal');
        if (m) m.classList.remove('active');
    }
    window.addEventListener('click', function(e) {
        const m = document.getElementById('adminLogoutModal');
        if (m && e.target === m) closeLogoutModal();
    });
    window.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeLogoutModal();
    });
}
</script>
<script src="assets/app_speed.js?v=<?= filemtime(__DIR__ . '/assets/app_speed.js') ?>"></script>
