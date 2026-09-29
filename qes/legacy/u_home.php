<?php
session_start();
include('db.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'user') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['id'] ?? null;
if (!$user_id) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE username=?");
    $stmt->bind_param("s", $_SESSION['username']);
    $stmt->execute();
    $user_id = $stmt->get_result()->fetch_assoc()['id'];
    $_SESSION['id'] = $user_id;
    $stmt->close();
}

// Handle voluntary password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'user_change_password') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Fetch user current password hash
    $stmt = $conn->prepare("SELECT password FROM users WHERE id=?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user_row || !password_verify($current_password, $user_row['password'])) {
        $_SESSION['pw_change_msg'] = "Current password is incorrect.";
        $_SESSION['pw_change_type'] = "error";
    } elseif (strlen($new_password) < 6) {
        $_SESSION['pw_change_msg'] = "New password must be at least 6 characters long.";
        $_SESSION['pw_change_type'] = "error";
    } elseif ($new_password !== $confirm_password) {
        $_SESSION['pw_change_msg'] = "New password and confirmation do not match.";
        $_SESSION['pw_change_type'] = "error";
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password=?, must_change_password=0 WHERE id=?");
        $stmt->bind_param("si", $hashed, $user_id);
        if ($stmt->execute()) {
            $_SESSION['pw_change_msg'] = "Your password has been changed successfully!";
            $_SESSION['pw_change_type'] = "success";
        } else {
            $_SESSION['pw_change_msg'] = "Error updating password: " . $conn->error;
            $_SESSION['pw_change_type'] = "error";
        }
        $stmt->close();
    }
    header("Location: u_home.php?tab=password");
    exit;
}

// Handle forced password change submission (admin-created account first login)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $pw_error = '';
    if (strlen($new_password) < 6) {
        $pw_error = 'Password must be at least 6 characters.';
    } elseif ($new_password !== $confirm_password) {
        $pw_error = 'Passwords do not match.';
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password=?, must_change_password=0 WHERE id=?");
        $stmt->bind_param("si", $hashed, $user_id);
        $stmt->execute();
        $stmt->close();
        unset($_SESSION['force_password_change']);
        $_SESSION['pw_changed_success'] = true;
        header("Location: u_home.php");
        exit;
    }
}

// Global Counts for Dashboard Cards
$total_users_query = $conn->query("SELECT COUNT(*) as total FROM users");
$total_users_count = $total_users_query ? $total_users_query->fetch_assoc()['total'] : 0;

$total_events_query = $conn->query("SELECT COUNT(*) as total FROM events");
$total_events_count = $total_events_query ? $total_events_query->fetch_assoc()['total'] : 0;

$total_facilities_query = $conn->query("SELECT COUNT(*) as total FROM facilities");
$total_facilities_count = $total_facilities_query ? $total_facilities_query->fetch_assoc()['total'] : 0;

// Fetch User's Active/Upcoming Approved Events (Auto-hides finished events: end_time >= NOW())
$events_query = "
    SELECT e.*, f.name AS facility_name, f.capacity
    FROM events e
    JOIN facilities f ON e.facility_id = f.id
    WHERE e.user_id = ?
      AND e.status = 'approved'
      AND e.end_time >= NOW()
    ORDER BY e.start_time ASC
";
$stmt = $conn->prepare($events_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$events_result = $stmt->get_result();
$stmt->close();

$active_tab = $_GET['tab'] ?? 'dashboard';
$pw_change_msg = $_SESSION['pw_change_msg'] ?? '';
$pw_change_type = $_SESSION['pw_change_type'] ?? '';
unset($_SESSION['pw_change_msg'], $_SESSION['pw_change_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home - SCHEDFIX</title>
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
            min-height: 100vh;
            color: #111;
            position: relative;
            overflow-x: hidden;
        }

        /* Top Navigation Bar */
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
        .calendar-icon-box:hover { transform: scale(1.05); }

        .calendar-icon-box svg {
            width: clamp(24px, 2.8vw, 34px);
            height: clamp(24px, 2.8vw, 34px);
            display: block;
        }

        /* Navigation Buttons */
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

        /* Dashboard Container */
        .dashboard-wrapper {
            width: 100%;
            max-width: 1260px;
            margin: 0 auto;
            padding: 0 20px 40px;
            position: relative;
            z-index: 1;
        }

        .home-card-container {
            background: #ffffff;
            border-radius: 32px;
            padding: 40px 45px 45px;
            box-shadow: 0 25px 60px rgba(10, 30, 85, 0.22), 0 8px 20px rgba(0, 0, 0, 0.06);
            width: 100%;
            min-height: 520px;
            position: relative;
        }

        .user-greeting {
            font-family: 'Outfit', sans-serif;
            font-size: clamp(22px, 2.4vw, 28px);
            font-weight: 700;
            color: #3b5bf6;
            margin-bottom: 22px;
            letter-spacing: -0.3px;
        }

        /* Navigation Tabs Inside Home Card */
        .user-tabs-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 28px;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 10px;
        }

        .tab-btn {
            background: none;
            border: none;
            padding: 10px 22px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            color: #64748b;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .tab-btn:hover:not(.active) {
            background: #f1f5f9;
            color: #1e293b;
        }

        .tab-btn.active {
            background: #2b55f6;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(43, 85, 246, 0.25);
        }

        /* 3 Stat Metric Cards */
        .stats-metrics-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-bottom: 34px;
        }

        .metric-card {
            background: #ffffff;
            border: 2px solid #2b55f6;
            border-radius: 20px;
            padding: 22px 20px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 105px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .metric-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(43, 85, 246, 0.15);
        }

        .metric-title {
            font-size: 14px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 6px;
            letter-spacing: -0.1px;
        }

        .metric-count {
            font-size: 24px;
            font-weight: 800;
            color: #2b55f6;
            line-height: 1;
        }

        /* My Approved Events Section */
        .approved-events-section { width: 100%; }

        .section-label {
            font-size: 15px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 12px;
            display: block;
            letter-spacing: -0.1px;
        }

        .events-outline-box {
            border: 2px solid #2b55f6;
            border-radius: 22px;
            padding: 24px;
            background: #ffffff;
            min-height: 180px;
        }

        .events-list-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
        }

        .user-event-card {
            background: #f8faff;
            border: 1px solid #dbeafe;
            border-left: 4px solid #3b5bf6;
            border-radius: 12px;
            padding: 16px;
            transition: all 0.2s ease;
        }

        .user-event-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(59, 91, 246, 0.12);
            border-color: #bfdbfe;
        }

        .user-event-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .user-event-title {
            font-size: 16px;
            font-weight: 700;
            color: #1e3a8a;
        }

        .status-badge-approved {
            background: #dcfce7;
            color: #166534;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .user-event-meta {
            display: flex;
            flex-direction: column;
            gap: 5px;
            font-size: 13px;
            color: #4b5563;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .no-events-placeholder {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
            font-size: 14px;
        }

        .btn-create-event {
            display: inline-block;
            margin-top: 10px;
            color: #2b55f6;
            font-weight: 700;
            text-decoration: none;
            transition: color 0.2s;
        }
        .btn-create-event:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }

        /* Change Password Tab Content */
        .change-password-box {
            background: #f8faff;
            border: 1px solid #dbeafe;
            border-radius: 20px;
            padding: 32px 36px;
            max-width: 520px;
            margin: 10px 0;
            box-shadow: 0 8px 24px rgba(43, 85, 246, 0.06);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 13.5px;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .password-input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .password-input-group input {
            width: 100%;
            padding: 12px 46px 12px 14px;
            border: 2px solid #dbeafe;
            border-radius: 12px;
            font-size: 14px;
            font-family: 'Outfit', sans-serif;
            background: #ffffff;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .password-input-group input:focus {
            border-color: #2b55f6;
            box-shadow: 0 0 0 3px rgba(43, 85, 246, 0.12);
        }

        .toggle-pw-btn {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            cursor: pointer;
            color: #64748b;
            padding: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: color 0.2s;
        }
        .toggle-pw-btn:hover { color: #2b55f6; }
        .toggle-pw-btn svg { width: 20px; height: 20px; stroke: currentColor; fill: none; stroke-width: 2; }

        .btn-change-pw {
            width: 100%;
            padding: 13px;
            background: #2b55f6;
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Outfit', sans-serif;
            transition: background 0.2s ease, transform 0.15s ease;
            box-shadow: 0 4px 14px rgba(43, 85, 246, 0.25);
        }
        .btn-change-pw:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
        }

        .alert-banner {
            padding: 14px 18px;
            margin-bottom: 20px;
            border-radius: 12px;
            font-weight: 500;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .alert-banner.success { background: #dcfce7; border: 1px solid #bbf7d0; color: #166534; }
        .alert-banner.error { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; }

        @media (max-width: 820px) {
            .stats-metrics-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            .home-card-container {
                padding: 30px 24px;
                border-radius: 24px;
            }
            .top-navbar {
                padding: 20px 16px 12px;
            }
            .nav-icon-btn {
                width: 42px;
                height: 42px;
            }
            .nav-icon-btn svg {
                width: 18px;
                height: 18px;
            }
            .change-password-box {
                padding: 24px 20px;
            }
        }
    </style>
</head>
<body>

<!-- Top Navigation Bar -->
<header class="top-navbar">
    <a href="u_home.php" class="brand-logo-container" title="SCHEDFIX">
        <span class="logo-text">SCH</span>
        <div class="calendar-icon-box">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <rect x="2" y="4" width="20" height="18" rx="4" fill="none" stroke="#ffffff" stroke-width="2"/>
                <line x1="7" y1="1.5" x2="7" y2="5" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
                <line x1="17" y1="1.5" x2="17" y2="5" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
                <line x1="2" y1="9" x2="22" y2="9" stroke="#ffffff" stroke-width="1.8"/>
                <circle cx="6.5" cy="13" r="1.2" fill="#ffffff"/>
                <circle cx="12" cy="13" r="1.2" fill="#ffffff"/>
                <circle cx="17.5" cy="13" r="1.2" fill="#ffffff"/>
                <circle cx="6.5" cy="17" r="1.2" fill="#ffffff"/>
                <path d="M10.5 17.5 L12.5 19.5 L18 14" fill="none" stroke="#00e5ff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <span class="logo-text">DFIX</span>
    </a>

    <!-- Top Right Icon Navigation -->
    <nav class="icon-nav-group" aria-label="Main Navigation">
        <a href="u_home.php" class="nav-icon-btn active" title="Home">
            <svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
        </a>
        <a href="u_events.php" class="nav-icon-btn" title="My Events">
            <svg viewBox="0 0 24 24"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
        </a>
        <a href="logout.php" class="nav-icon-btn" title="Logout">
            <svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
        </a>
    </nav>
</header>

<!-- Main Dashboard Container -->
<main class="dashboard-wrapper">
    <div class="home-card-container">
        
        <!-- Welcome Greeting -->
        <h1 class="user-greeting">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h1>

        <!-- User Tabs: Dashboard & Change Password -->
        <div class="user-tabs-bar">
            <button type="button" class="tab-btn <?php echo $active_tab !== 'password' ? 'active' : ''; ?>" id="tabBtnDashboard" onclick="switchUserTab('dashboard')">
                <span>📊 Dashboard Overview</span>
            </button>
            <button type="button" class="tab-btn <?php echo $active_tab === 'password' ? 'active' : ''; ?>" id="tabBtnPassword" onclick="switchUserTab('password')">
                <span>🔑 Change Password</span>
            </button>
        </div>

        <!-- Tab 1: Dashboard Content -->
        <div id="tabContentDashboard" style="<?php echo $active_tab === 'password' ? 'display:none;' : 'display:block;'; ?>">
            <!-- 3 Metrics Cards -->
            <div class="stats-metrics-grid">
                <div class="metric-card">
                    <div class="metric-title">Total Users</div>
                    <div class="metric-count"><?php echo $total_users_count; ?></div>
                </div>
                <div class="metric-card">
                    <div class="metric-title">Total Events</div>
                    <div class="metric-count"><?php echo $total_events_count; ?></div>
                </div>
                <div class="metric-card">
                    <div class="metric-title">Total Facilities</div>
                    <div class="metric-count"><?php echo $total_facilities_count; ?></div>
                </div>
            </div>

            <!-- My Approved Events Section (Auto-hides past finished events) -->
            <section class="approved-events-section">
                <label class="section-label">My Upcoming Approved Events</label>
                <div class="events-outline-box">
                    <?php if ($events_result && $events_result->num_rows > 0): ?>
                        <div class="events-list-grid">
                            <?php while ($event = $events_result->fetch_assoc()): ?>
                                <div class="user-event-card">
                                    <div class="user-event-header">
                                        <div class="user-event-title"><?php echo htmlspecialchars($event['title']); ?></div>
                                        <span class="status-badge-approved">Approved</span>
                                    </div>
                                    <div class="user-event-meta">
                                        <div class="meta-item">
                                            <span>🏢</span>
                                            <strong><?php echo htmlspecialchars($event['facility_name']); ?></strong>
                                        </div>
                                        <div class="meta-item">
                                            <span>🕒</span>
                                            <span><?php echo date('M j, Y g:i A', strtotime($event['start_time'])); ?> - <?php echo date('g:i A', strtotime($event['end_time'])); ?></span>
                                        </div>
                                        <div class="meta-item">
                                            <span>👥</span>
                                            <span>Attendees: <?php echo htmlspecialchars($event['attendees_count']); ?> / <?php echo htmlspecialchars($event['capacity']); ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-events-placeholder">
                            <p>No active or upcoming approved events scheduled.</p>
                            <a href="u_events.php" class="btn-create-event">+ Set an event now →</a>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <!-- Tab 2: Change Password Content -->
        <div id="tabContentPassword" style="<?php echo $active_tab === 'password' ? 'display:block;' : 'display:none;'; ?>">
            <div class="change-password-box">
                <h3 style="margin-bottom: 12px; color: #1e3a8a; font-size: 18px;">🔒 Change Your Password</h3>
                <p style="color: #64748b; font-size: 13.5px; margin-bottom: 22px;">Keep your account secure by choosing a strong password with at least 6 characters.</p>

                <?php if (!empty($pw_change_msg)): ?>
                    <div class="alert-banner <?php echo $pw_change_type; ?>">
                        <span><?php echo htmlspecialchars($pw_change_msg); ?></span>
                        <button type="button" onclick="this.parentElement.style.display='none'" style="background:none;border:none;cursor:pointer;color:inherit;font-size:18px;">×</button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="u_home.php" onsubmit="return validateChangePwForm()">
                    <input type="hidden" name="action" value="user_change_password">

                    <div class="form-group">
                        <label>Current Password *</label>
                        <div class="password-input-group">
                            <input type="password" name="current_password" id="userCurrentPw" placeholder="Enter your current password" required>
                            <button type="button" class="toggle-pw-btn" onclick="togglePasswordVisibility('userCurrentPw', this)" title="Show / Hide Password">
                                <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>New Password (min 6 characters) *</label>
                        <div class="password-input-group">
                            <input type="password" name="new_password" id="userNewPw" placeholder="Enter new password" required minlength="6">
                            <button type="button" class="toggle-pw-btn" onclick="togglePasswordVisibility('userNewPw', this)" title="Show / Hide Password">
                                <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Confirm New Password *</label>
                        <div class="password-input-group">
                            <input type="password" name="confirm_password" id="userConfirmPw" placeholder="Confirm your new password" required minlength="6">
                            <button type="button" class="toggle-pw-btn" onclick="togglePasswordVisibility('userConfirmPw', this)" title="Show / Hide Password">
                                <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-change-pw">Update Password</button>
                </form>
            </div>
        </div>

    </div>
</main>

<?php if (!empty($_SESSION['force_password_change'])): ?>
<!-- Force Password Change Modal -->
<div id="pwChangeModal" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:9999;display:flex;align-items:center;justify-content:center;">
    <div style="background:white;border-radius:16px;padding:35px 40px;width:400px;max-width:95%;box-shadow:0 10px 40px rgba(0,0,0,0.3);">
        <h3 style="margin:0 0 8px;color:#1a73e8;font-family:'Outfit',sans-serif;">🔐 Set Your Password</h3>
        <p style="color:#666;font-size:14px;margin-bottom:20px;">Your account was created by an admin. Please set a new password before continuing.</p>
        <?php if (!empty($pw_error)): ?>
            <div style="background:#f8d7da;color:#721c24;padding:10px;border-radius:8px;margin-bottom:15px;font-size:14px;"><?php echo htmlspecialchars($pw_error); ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="action" value="change_password">
            <div style="position:relative;margin-bottom:12px;">
                <input type="password" name="new_password" id="forcedNewPw" placeholder="New Password (min 6 chars)" required minlength="6"
                       style="width:100%;padding:10px 42px 10px 12px;border:1px solid #ced4da;border-radius:8px;font-size:14px;box-sizing:border-box;">
                <button type="button" onclick="togglePasswordVisibility('forcedNewPw', this)" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#666;">
                    <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
            <div style="position:relative;margin-bottom:18px;">
                <input type="password" name="confirm_password" id="forcedConfirmPw" placeholder="Confirm New Password" required
                       style="width:100%;padding:10px 42px 10px 12px;border:1px solid #ced4da;border-radius:8px;font-size:14px;box-sizing:border-box;">
                <button type="button" onclick="togglePasswordVisibility('forcedConfirmPw', this)" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#666;">
                    <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
            <button type="submit" style="width:100%;padding:12px;background:#2b55f6;color:white;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;">
                Set Password & Continue
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($_SESSION['pw_changed_success'])): ?>
<div id="pwSuccessNotice" style="position:fixed;top:20px;right:20px;background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:14px 20px;border-radius:10px;z-index:9999;font-weight:500;box-shadow:0 4px 12px rgba(0,0,0,0.15);">
    ✅ Password changed successfully!
</div>
<script>setTimeout(()=>{ const n=document.getElementById('pwSuccessNotice'); if(n){n.style.opacity='0'; setTimeout(()=>n.remove(),300);} },3000);</script>
<?php unset($_SESSION['pw_changed_success']); endif; ?>

<script>
function switchUserTab(tabName) {
    const dashTab = document.getElementById('tabContentDashboard');
    const pwTab = document.getElementById('tabContentPassword');
    const dashBtn = document.getElementById('tabBtnDashboard');
    const pwBtn = document.getElementById('tabBtnPassword');

    if (tabName === 'password') {
        dashTab.style.display = 'none';
        pwTab.style.display = 'block';
        dashBtn.classList.remove('active');
        pwBtn.classList.add('active');
    } else {
        dashTab.style.display = 'block';
        pwTab.style.display = 'none';
        dashBtn.classList.add('active');
        pwBtn.classList.remove('active');
    }
}

function togglePasswordVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        btn.innerHTML = `<svg viewBox="0 0 24 24" style="width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;
    } else {
        input.type = 'password';
        btn.innerHTML = `<svg viewBox="0 0 24 24" style="width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;
    }
}

function validateChangePwForm() {
    const newPw = document.getElementById('userNewPw').value;
    const confirmPw = document.getElementById('userConfirmPw').value;
    if (newPw.length < 6) {
        alert('New password must be at least 6 characters long.');
        return false;
    }
    if (newPw !== confirmPw) {
        alert('New password and confirmation do not match.');
        return false;
    }
    return true;
}
</script>
<script src="chatbot.js"></script>
</body>
</html>
