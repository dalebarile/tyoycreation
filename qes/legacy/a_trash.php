<?php
session_start();
include('db.php');

// Admins only
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: login.php");
    exit;
}

$message = "";
$message_type = "";

// Auto-purge items older than 30 days
$conn->query("DELETE FROM trash WHERE deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");

// Handle restore or permanent delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action']    ?? '';
    $trash_id  = (int)($_POST['trash_id'] ?? 0);
    $item_type = $_POST['item_type'] ?? '';

    // Fetch the trashed item
    $fetch = $conn->prepare("SELECT * FROM trash WHERE id = ?");
    $fetch->bind_param("i", $trash_id);
    $fetch->execute();
    $trashed = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    if (!$trashed) {
        $message = "Item not found in trash.";
        $message_type = "error";
    } elseif ($action === 'restore') {
        $data = json_decode($trashed['item_data'], true);

        if ($trashed['item_type'] === 'event') {
            // Restore event — check facility still exists
            $fac_check = $conn->prepare("SELECT id FROM facilities WHERE id = ?");
            $fac_id_var = (int)($data['facility_id'] ?? 0);
            $fac_check->bind_param("i", $fac_id_var);
            $fac_check->execute();
            $fac_check->store_result();
            if ($fac_check->num_rows === 0) {
                $message = "Cannot restore: the facility this event used no longer exists.";
                $message_type = "error";
                $fac_check->close();
            } else {
                $fac_check->close();
                // Restore event with original user if exists, or current admin
                $u_check = $conn->prepare("SELECT id FROM users WHERE id = ?");
                $u_id_var = (int)($data['user_id'] ?? 0);
                $u_check->bind_param("i", $u_id_var);
                $u_check->execute();
                $u_check->store_result();
                if ($u_check->num_rows === 0) {
                    $u_id_var = (int)$_SESSION['id'];
                }
                $u_check->close();

                $stmt = $conn->prepare("INSERT INTO events (user_id, facility_id, title, description, attendees_count, start_time, end_time, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)");
                $title_var = $data['title'] ?? '';
                $description_var = $data['description'] ?? '';
                $attendees_count_var = (int)($data['attendees_count'] ?? 1);
                $start_time_var = $data['start_time'] ?? date('Y-m-d H:i:s');
                $end_time_var = $data['end_time'] ?? date('Y-m-d H:i:s', strtotime('+1 hour'));
                $created_at_var = $data['created_at'] ?? date('Y-m-d H:i:s');
                $stmt->bind_param("iississs",
                    $u_id_var, $fac_id_var,
                    $title_var, $description_var, $attendees_count_var,
                    $start_time_var, $end_time_var, $created_at_var
                );
                if ($stmt->execute()) {
                    $del = $conn->prepare("DELETE FROM trash WHERE id = ?");
                    $del->bind_param("i", $trash_id);
                    $del->execute();
                    $del->close();
                    $message = "Event \"{$data['title']}\" restored successfully (set to Pending).";
                    $message_type = "success";
                } else {
                    $message = "Failed to restore event: " . $conn->error;
                    $message_type = "error";
                }
                $stmt->close();
            }
        } elseif ($trashed['item_type'] === 'facility') {
            // Check name collision
            $name_check = $conn->prepare("SELECT id FROM facilities WHERE name = ?");
            $name_var = $data['name'] ?? '';
            $name_check->bind_param("s", $name_var);
            $name_check->execute();
            $name_check->store_result();
            if ($name_check->num_rows > 0) {
                $message = "Cannot restore: a facility named \"{$data['name']}\" already exists.";
                $message_type = "error";
            } else {
                $stmt = $conn->prepare("INSERT INTO facilities (name, description, capacity, created_at) VALUES (?, ?, ?, ?)");
                $fac_name_var = $data['name'] ?? '';
                $fac_description_var = $data['description'] ?? '';
                $fac_capacity_var = (int)($data['capacity'] ?? 10);
                $fac_created_at_var = $data['created_at'] ?? date('Y-m-d H:i:s');
                $stmt->bind_param("ssis", $fac_name_var, $fac_description_var, $fac_capacity_var, $fac_created_at_var);
                if ($stmt->execute()) {
                    $del = $conn->prepare("DELETE FROM trash WHERE id = ?");
                    $del->bind_param("i", $trash_id);
                    $del->execute();
                    $del->close();
                    $message = "Facility \"{$data['name']}\" restored successfully.";
                    $message_type = "success";
                } else {
                    $message = "Failed to restore facility: " . $conn->error;
                    $message_type = "error";
                }
                $stmt->close();
            }
            $name_check->close();
        } elseif ($trashed['item_type'] === 'user') {
            // Check username/email collision
            $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $uname_v = $data['username'] ?? '';
            $uemail_v = $data['email'] ?? '';
            $check->bind_param("ss", $uname_v, $uemail_v);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $message = "Cannot restore: a user with username \"$uname_v\" or email \"$uemail_v\" already exists.";
                $message_type = "error";
            } else {
                $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, status, must_change_password, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)");
                $u_username = $data['username'] ?? '';
                $u_email    = $data['email'] ?? '';
                $u_password = $data['password'] ?? password_hash('123456', PASSWORD_DEFAULT);
                $u_role     = $data['role'] ?? 'user';
                $u_status   = $data['status'] ?? 'approved';
                $u_created  = $data['created_at'] ?? date('Y-m-d H:i:s');
                $stmt->bind_param("ssssss", $u_username, $u_email, $u_password, $u_role, $u_status, $u_created);
                if ($stmt->execute()) {
                    $del = $conn->prepare("DELETE FROM trash WHERE id = ?");
                    $del->bind_param("i", $trash_id);
                    $del->execute();
                    $del->close();
                    $message = "User account \"{$data['username']}\" restored successfully.";
                    $message_type = "success";
                } else {
                    $message = "Failed to restore user: " . $conn->error;
                    $message_type = "error";
                }
                $stmt->close();
            }
            $check->close();
        }
    } elseif ($action === 'permanent_delete') {
        $del = $conn->prepare("DELETE FROM trash WHERE id = ?");
        $del->bind_param("i", $trash_id);
        if ($del->execute()) {
            $message = "Item permanently deleted from recycling bin.";
            $message_type = "success";
        } else {
            $message = "Error deleting item: " . $conn->error;
            $message_type = "error";
        }
        $del->close();
    }

    $_SESSION['trash_message'] = $message;
    $_SESSION['trash_message_type'] = $message_type;
    header("Location: a_trash.php?tab=" . urlencode($_GET['tab'] ?? 'events'));
    exit;
}

if (isset($_SESSION['trash_message'])) {
    $message = $_SESSION['trash_message'];
    $message_type = $_SESSION['trash_message_type'];
    unset($_SESSION['trash_message'], $_SESSION['trash_message_type']);
}

$active_tab = $_GET['tab'] ?? 'events';

// Fetch trashed counts
$count_events = (int)$conn->query("SELECT COUNT(*) AS c FROM trash WHERE item_type = 'event'")->fetch_assoc()['c'];
$count_facilities = (int)$conn->query("SELECT COUNT(*) AS c FROM trash WHERE item_type = 'facility'")->fetch_assoc()['c'];
$count_users = (int)$conn->query("SELECT COUNT(*) AS c FROM trash WHERE item_type = 'user'")->fetch_assoc()['c'];

// Fetch items for each category
$trashed_events = $conn->query("SELECT * FROM trash WHERE item_type = 'event' ORDER BY deleted_at DESC");
$trashed_facilities = $conn->query("SELECT * FROM trash WHERE item_type = 'facility' ORDER BY deleted_at DESC");
$trashed_users = $conn->query("SELECT * FROM trash WHERE item_type = 'user' ORDER BY deleted_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recycling Bin - SCHEDFIX</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@800;900&family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Outfit', 'Inter', sans-serif; min-height: 100vh; color: #111; overflow-x: hidden; }
        
        /* Top Navigation */
        .top-navbar {
            width: 100%; max-width: 1260px; margin: 0 auto;
            padding: 24px 20px 16px;
            display: flex; align-items: center; justify-content: space-between;
            position: relative; z-index: 100;
        }
        .brand-logo-container { display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .logo-text {
            font-family: 'Montserrat', sans-serif; font-size: clamp(28px,3.2vw,42px);
            font-weight: 900; color: #ffffff; letter-spacing: 2px; text-transform: uppercase;
            line-height: 1; text-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        .calendar-icon-box {
            display: inline-flex; align-items: center; justify-content: center;
            background: #0d1a33; border-radius: 10px; padding: 6px 7px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.25); transition: transform 0.2s ease;
        }
        .calendar-icon-box:hover { transform: scale(1.05); }
        .calendar-icon-box svg { width: clamp(24px,2.8vw,34px); height: clamp(24px,2.8vw,34px); display: block; }
        
        .icon-nav-group { display: flex; align-items: center; gap: 12px; }
        .nav-icon-btn {
            width: 48px; height: 48px; border-radius: 14px; background: #0d2253;
            color: #ffffff; display: inline-flex; align-items: center; justify-content: center;
            text-decoration: none; transition: all 0.25s cubic-bezier(0.16,1,0.3,1);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15); border: 1px solid rgba(255,255,255,0.1); position: relative;
        }
        .nav-icon-btn svg { width: 22px; height: 22px; fill: currentColor; stroke: currentColor; stroke-width: 0.5; }
        .nav-icon-btn.active { background: #00a8ff; box-shadow: 0 4px 16px rgba(0,168,255,0.4); border-color: rgba(255,255,255,0.3); transform: scale(1.04); }
        .nav-icon-btn:hover:not(.active) { background: #143075; transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.25); }
        .nav-dropdown-wrapper { position: relative; }
        .nav-dropdown-menu {
            display: none; position: absolute; top: calc(100% + 10px); right: 0;
            background: #0d2253; border: 1px solid rgba(255,255,255,0.15); border-radius: 14px;
            box-shadow: 0 8px 28px rgba(0,0,0,0.3); z-index: 1000; min-width: 170px; overflow: hidden;
        }
        .nav-dropdown-menu a {
            display: block; padding: 12px 20px; color: white; text-decoration: none;
            font-size: 13px; font-weight: 600; border-bottom: 1px solid rgba(255,255,255,0.08);
            transition: background 0.2s; font-family: 'Outfit', sans-serif;
        }
        .nav-dropdown-menu a:last-child { border-bottom: none; }
        .nav-dropdown-menu a:hover { background: rgba(255,255,255,0.12); }

        /* Dashboard Container */
        .dashboard-wrapper { width: 100%; max-width: 1260px; margin: 0 auto; padding: 0 20px 40px; }
        .home-card-container {
            background: #ffffff; border-radius: 32px; padding: 40px 45px 45px;
            box-shadow: 0 25px 60px rgba(10,30,85,0.22), 0 8px 20px rgba(0,0,0,0.06);
            width: 100%; min-height: 520px;
        }
        .page-heading { font-family: 'Outfit', sans-serif; font-size: clamp(22px,2.4vw,28px); font-weight: 700; color: #3b5bf6; margin-bottom: 6px; }
        .page-subheading { color: #6b7280; font-size: 14px; margin-bottom: 24px; }

        /* Tabs */
        .tab-bar {
            display: flex; gap: 8px; margin-bottom: 25px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;
            overflow-x: auto;
        }
        .tab-pill {
            display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px;
            text-decoration: none; color: #64748b; font-weight: 600; font-size: 14px;
            border-radius: 12px; transition: all 0.2s ease;
        }
        .tab-pill.active {
            background: #2b55f6; color: #ffffff; box-shadow: 0 4px 12px rgba(43,85,246,0.25);
        }
        .tab-pill:hover:not(.active) { background: #f1f5f9; color: #1e293b; }
        .tab-count-badge {
            background: rgba(255,255,255,0.25); padding: 2px 8px; border-radius: 20px; font-size: 12px; font-weight: 700;
        }
        .tab-pill:not(.active) .tab-count-badge {
            background: #e2e8f0; color: #475569;
        }

        /* Days Left Badges */
        .days-left {
            display: inline-block; font-size: 12px; padding: 4px 10px;
            border-radius: 20px; font-weight: 700; letter-spacing: -0.2px;
        }
        .days-urgent { background: #fee2e2; color: #991b1b; }
        .days-warning { background: #fef9c3; color: #854d0e; }
        .days-ok { background: #dcfce7; color: #166534; }

        /* Actions Buttons */
        .restore-btn {
            background: #10b981; color: white; border: none;
            padding: 7px 14px; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600;
            transition: background 0.2s, transform 0.15s; display: inline-flex; align-items: center; gap: 4px;
        }
        .restore-btn:hover { background: #059669; transform: translateY(-1px); }
        .perm-del-btn {
            background: #ef4444; color: white; border: none;
            padding: 7px 14px; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600;
            transition: background 0.2s, transform 0.15s; margin-left: 6px;
        }
        .perm-del-btn:hover { background: #dc2626; transform: translateY(-1px); }

        .alert-message {
            padding: 14px 18px; margin-bottom: 20px; border-radius: 12px;
            font-weight: 500; display: flex; align-items: center; justify-content: space-between;
            font-size: 14px; animation: fadeIn 0.3s ease;
        }
        .alert-success { background: #dcfce7; border: 1px solid #bbf7d0; color: #166534; }
        .alert-error { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; }
        .close-alert { background: none; border: none; font-size: 18px; cursor: pointer; color: inherit; opacity: 0.7; }
        .close-alert:hover { opacity: 1; }

        .empty-trash {
            text-align: center; padding: 50px 20px; color: #94a3b8;
            background: #f8fafc; border-radius: 16px; border: 2px dashed #e2e8f0; font-size: 15px;
        }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
        @media (max-width: 820px) {
            .home-card-container { padding: 30px 24px; border-radius: 24px; }
            .top-navbar { padding: 20px 16px 12px; }
            .nav-icon-btn { width: 42px; height: 42px; }
        }
    </style>
</head>
<body>

<header class="top-navbar">
    <a href="a_home.php" class="brand-logo-container" title="SCHEDFIX">
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
    <nav class="icon-nav-group" aria-label="Admin Navigation">
        <a href="a_home.php" class="nav-icon-btn" title="Dashboard"><svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg></a>
        <a href="a_events.php" class="nav-icon-btn" title="Manage Events"><svg viewBox="0 0 24 24"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg></a>
        <a href="a_pending.php" class="nav-icon-btn" title="Pending Users"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg></a>
        <a href="a_user.php" class="nav-icon-btn" title="Manage Users"><svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg></a>
        <div class="nav-dropdown-wrapper">
            <button class="nav-icon-btn active" id="adminMoreBtn" title="More" onclick="toggleAdminDropdown()" style="border:none;cursor:pointer;">
                <svg viewBox="0 0 24 24"><path d="M6 10c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm12 0c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm-6 0c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
            </button>
            <div class="nav-dropdown-menu" id="adminDropdown">
                <a href="a_facilities.php">🏢 Facilities</a>
                <a href="a_calendar.php">📅 Calendar</a>
                <a href="a_trash.php" style="color: #60a5fa; font-weight: bold;">🗑 Trash</a>
                <a href="logout.php">🚪 Logout</a>
            </div>
        </div>
    </nav>
</header>
<script>
function toggleAdminDropdown() {
    const menu = document.getElementById('adminDropdown');
    menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
}
document.addEventListener('click', function(e) {
    const btn = document.getElementById('adminMoreBtn');
    const menu = document.getElementById('adminDropdown');
    if (menu && btn && !btn.contains(e.target) && !menu.contains(e.target)) {
        menu.style.display = 'none';
    }
});
</script>

<main class="dashboard-wrapper">
    <div class="home-card-container">
        <h1 class="page-heading">Recycling Bin</h1>
        <p class="page-subheading">Deleted events, facilities, and accounts are safely stored here for <strong>30 days</strong> before permanent automatic deletion. You can restore or permanently delete them anytime.</p>

        <?php if (!empty($message)): ?>
            <div class="alert-message alert-<?php echo $message_type; ?>" id="alertMessage">
                <span><?php echo htmlspecialchars($message); ?></span>
                <button class="close-alert" onclick="this.parentElement.style.display='none'">×</button>
            </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tab-bar">
            <a href="a_trash.php?tab=events" class="tab-pill <?php echo $active_tab === 'events' ? 'active' : ''; ?>">
                <span>🗓 Deleted Events</span>
                <span class="tab-count-badge"><?php echo $count_events; ?></span>
            </a>
            <a href="a_trash.php?tab=facilities" class="tab-pill <?php echo $active_tab === 'facilities' ? 'active' : ''; ?>">
                <span>🏢 Deleted Facilities</span>
                <span class="tab-count-badge"><?php echo $count_facilities; ?></span>
            </a>
            <a href="a_trash.php?tab=users" class="tab-pill <?php echo $active_tab === 'users' ? 'active' : ''; ?>">
                <span>👤 Deleted Accounts</span>
                <span class="tab-count-badge"><?php echo $count_users; ?></span>
            </a>
        </div>

        <?php if ($active_tab === 'events'): ?>

            <?php if ($trashed_events && $trashed_events->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Event Title</th>
                                <th>Facility</th>
                                <th>Attendees</th>
                                <th>Schedule</th>
                                <th>Deleted At</th>
                                <th>Auto-Delete In</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php while ($row = $trashed_events->fetch_assoc()):
                            $data = json_decode($row['item_data'], true);
                            $deleted_at = new DateTime($row['deleted_at']);
                            $auto_del = clone $deleted_at; $auto_del->modify('+30 days');
                            $now = new DateTime();
                            $days_left = max(0, (int)$now->diff($auto_del)->days);
                            $badge_class = $days_left <= 3 ? 'days-urgent' : ($days_left <= 7 ? 'days-warning' : 'days-ok');
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($data['title'] ?? '—'); ?></strong></td>
                            <td><?php echo htmlspecialchars($data['facility_name'] ?? ('ID: ' . ($data['facility_id'] ?? '?'))); ?></td>
                            <td><?php echo htmlspecialchars($data['attendees_count'] ?? '—'); ?></td>
                            <td>
                                <?php if (isset($data['start_time'])): ?>
                                    <span style="font-size: 13px;"><?php echo date('M j, Y g:i A', strtotime($data['start_time'])); ?></span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td><?php echo date('M j, Y g:i A', strtotime($row['deleted_at'])); ?></td>
                            <td><span class="days-left <?php echo $badge_class; ?>"><?php echo $days_left; ?> days</span></td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="trash_id" value="<?php echo $row['id']; ?>">
                                    <input type="hidden" name="item_type" value="event">
                                    <button type="submit" name="action" value="restore" class="restore-btn" title="Restore back to pending events">↩ Restore</button>
                                    <button type="submit" name="action" value="permanent_delete" class="perm-del-btn"
                                            onclick="return confirm('Permanently delete this event? This cannot be undone.')">✕ Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-trash">No deleted events currently in the recycling bin.</div>
            <?php endif; ?>

        <?php elseif ($active_tab === 'facilities'): ?>

            <?php if ($trashed_facilities && $trashed_facilities->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Facility Name</th>
                                <th>Description</th>
                                <th>Capacity</th>
                                <th>Deleted At</th>
                                <th>Auto-Delete In</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php while ($row = $trashed_facilities->fetch_assoc()):
                            $data = json_decode($row['item_data'], true);
                            $deleted_at = new DateTime($row['deleted_at']);
                            $auto_del = clone $deleted_at; $auto_del->modify('+30 days');
                            $now = new DateTime();
                            $days_left = max(0, (int)$now->diff($auto_del)->days);
                            $badge_class = $days_left <= 3 ? 'days-urgent' : ($days_left <= 7 ? 'days-warning' : 'days-ok');
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($data['name'] ?? '—'); ?></strong></td>
                            <td><?php echo htmlspecialchars($data['description'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($data['capacity'] ?? '—'); ?> attendees</td>
                            <td><?php echo date('M j, Y g:i A', strtotime($row['deleted_at'])); ?></td>
                            <td><span class="days-left <?php echo $badge_class; ?>"><?php echo $days_left; ?> days</span></td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="trash_id" value="<?php echo $row['id']; ?>">
                                    <input type="hidden" name="item_type" value="facility">
                                    <button type="submit" name="action" value="restore" class="restore-btn">↩ Restore</button>
                                    <button type="submit" name="action" value="permanent_delete" class="perm-del-btn"
                                            onclick="return confirm('Permanently delete this facility? This cannot be undone.')">✕ Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-trash">No deleted facilities currently in the recycling bin.</div>
            <?php endif; ?>

        <?php elseif ($active_tab === 'users'): ?>

            <?php if ($trashed_users && $trashed_users->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Deleted At</th>
                                <th>Auto-Delete In</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php while ($row = $trashed_users->fetch_assoc()):
                            $data = json_decode($row['item_data'], true);
                            $deleted_at = new DateTime($row['deleted_at']);
                            $auto_del = clone $deleted_at; $auto_del->modify('+30 days');
                            $now = new DateTime();
                            $days_left = max(0, (int)$now->diff($auto_del)->days);
                            $badge_class = $days_left <= 3 ? 'days-urgent' : ($days_left <= 7 ? 'days-warning' : 'days-ok');
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($data['username'] ?? '—'); ?></strong></td>
                            <td><?php echo htmlspecialchars($data['email'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($data['role'] ?? '—')); ?></td>
                            <td><?php echo date('M j, Y g:i A', strtotime($row['deleted_at'])); ?></td>
                            <td><span class="days-left <?php echo $badge_class; ?>"><?php echo $days_left; ?> days</span></td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="trash_id" value="<?php echo $row['id']; ?>">
                                    <input type="hidden" name="item_type" value="user">
                                    <button type="submit" name="action" value="restore" class="restore-btn">↩ Restore</button>
                                    <button type="submit" name="action" value="permanent_delete" class="perm-del-btn"
                                            onclick="return confirm('Permanently delete this account? This cannot be undone.')">✕ Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-trash">No deleted accounts currently in the recycling bin.</div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</main>

<script>
setTimeout(function() {
    const alert = document.getElementById('alertMessage');
    if (alert) { alert.style.opacity = '0'; setTimeout(() => alert.style.display = 'none', 300); }
}, 5000);
</script>
<script src="chatbot.js"></script>
</body>
</html>
