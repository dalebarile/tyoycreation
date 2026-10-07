<?php
require_once __DIR__ . '/db.php';

// Allows both Main Admin and standard Admin to manage their own security
require_login();

$is_main_admin = is_main_admin();
$admin_username = $_SESSION['username'] ?? 'Admin';
$current_user_id = (int)($_SESSION['id'] ?? 0);
$current_session_id = session_id();

$pwd_success = '';
$pwd_error = '';
$device_msg_success = '';
$device_msg_error = '';
$admin_msg_success = '';
$admin_msg_error = '';
$monitor_msg_success = '';
$monitor_msg_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $action = $_POST['action'] ?? '';

    // ─── ACTION 1: Changing Password ─────────────────────────────────────────
    if ($action === 'change_password') {
        $current_pwd = $_POST['current_password'] ?? '';
        $new_pwd = $_POST['new_password'] ?? '';
        $confirm_pwd = $_POST['confirm_password'] ?? '';

        if (empty($current_pwd) || empty($new_pwd) || empty($confirm_pwd)) {
            $pwd_error = "Please fill in all password fields.";
        } elseif (strlen($new_pwd) < 6) {
            $pwd_error = "New password must be at least 6 characters long.";
        } elseif ($new_pwd !== $confirm_pwd) {
            $pwd_error = "New password and confirmation password do not match.";
        } else {
            $stmt = $conn->prepare("SELECT id, password FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $current_user_id);
            if ($stmt) {
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $u = $res->fetch_assoc()) {
                    if (password_verify($current_pwd, $u['password'])) {
                        $new_hash = password_hash($new_pwd, PASSWORD_DEFAULT);
                        $up_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $up_stmt->bind_param("si", $new_hash, $current_user_id);
                        if ($up_stmt->execute()) {
                            $pwd_success = "Password successfully changed! Your account is now secured with the new password.";
                        } else {
                            $pwd_error = "Could not update password: " . $conn->error;
                        }
                        $up_stmt->close();
                    } else {
                        $pwd_error = "The current password you entered is incorrect. Please try again.";
                    }
                } else {
                    $pwd_error = "Account not found in database.";
                }
                $stmt->close();
            }
        }
    }

    // ─── ACTION 2: Block a Device Session (Self) ─────────────────────────────
    elseif ($action === 'block_device') {
        $target_session_db_id = (int)($_POST['session_db_id'] ?? 0);
        if ($target_session_db_id > 0) {
            $stmt = $conn->prepare("UPDATE user_sessions SET is_blocked = 1 WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $target_session_db_id, $current_user_id);
            if ($stmt->execute()) {
                $device_msg_success = "Device session has been successfully blocked. If someone attempts to access from that device, they will be kicked out immediately.";
            } else {
                $device_msg_error = "Could not block session: " . $conn->error;
            }
            $stmt->close();
        }
    }

    // ─── ACTION 3: Unblock a Device Session (Self) ───────────────────────────
    elseif ($action === 'unblock_device') {
        $target_session_db_id = (int)($_POST['session_db_id'] ?? 0);
        if ($target_session_db_id > 0) {
            $stmt = $conn->prepare("UPDATE user_sessions SET is_blocked = 0 WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $target_session_db_id, $current_user_id);
            if ($stmt->execute()) {
                $device_msg_success = "Device has been unblocked. This device may log in again.";
            } else {
                $device_msg_error = "Could not unblock device: " . $conn->error;
            }
            $stmt->close();
        }
    }

    // ─── ACTION 4: Terminate / Remove Session (Self) ─────────────────────────
    elseif ($action === 'terminate_session') {
        $target_session_db_id = (int)($_POST['session_db_id'] ?? 0);
        if ($target_session_db_id > 0) {
            $stmt = $conn->prepare("DELETE FROM user_sessions WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $target_session_db_id, $current_user_id);
            if ($stmt->execute()) {
                $device_msg_success = "Session terminated and removed from your device history.";
            } else {
                $device_msg_error = "Could not remove session: " . $conn->error;
            }
            $stmt->close();
        }
    }

    // ─── ACTION 5: Block All Other Devices (Self) ────────────────────────────
    elseif ($action === 'block_all_other_devices') {
        $stmt = $conn->prepare("UPDATE user_sessions SET is_blocked = 1 WHERE user_id = ? AND session_id != ?");
        $stmt->bind_param("is", $current_user_id, $current_session_id);
        if ($stmt->execute()) {
            $device_msg_success = "Security Alert: All other devices and sessions have been blocked! Only your current device remains authorized.";
        } else {
            $device_msg_error = "Could not block other sessions: " . $conn->error;
        }
        $stmt->close();
    }

    // ─── ACTION 6: Main Admin - Force Logout & Block Admin's Devices ────────
    elseif ($action === 'admin_force_logout' && $is_main_admin) {
        $target_admin_id = (int)($_POST['target_admin_id'] ?? 0);
        if ($target_admin_id > 0 && $target_admin_id !== $current_user_id) {
            $conn->query("UPDATE user_sessions SET is_blocked = 1 WHERE user_id = {$target_admin_id}");
            $conn->query("UPDATE users SET last_logout_at = NOW() WHERE id = {$target_admin_id}");
            $monitor_msg_success = "Admin ID #{$target_admin_id} has been forcefully logged out and all their device sessions were blocked.";
        }
    }

    // ─── ACTION 7: Main Admin - Block Specific Admin Device ─────────────────
    elseif ($action === 'admin_block_device_specific' && $is_main_admin) {
        $session_db_id = (int)($_POST['session_db_id'] ?? 0);
        if ($session_db_id > 0) {
            $stmt = $conn->prepare("UPDATE user_sessions SET is_blocked = 1 WHERE id = ?");
            $stmt->bind_param("i", $session_db_id);
            if ($stmt->execute()) {
                $monitor_msg_success = "Admin device has been successfully blocked!";
            } else {
                $monitor_msg_error = "Could not block admin device: " . $conn->error;
            }
            $stmt->close();
        }
    }

    // ─── ACTION 8: Main Admin - Unblock Specific Admin Device ───────────────
    elseif ($action === 'admin_unblock_device_specific' && $is_main_admin) {
        $session_db_id = (int)($_POST['session_db_id'] ?? 0);
        if ($session_db_id > 0) {
            $stmt = $conn->prepare("UPDATE user_sessions SET is_blocked = 0 WHERE id = ?");
            $stmt->bind_param("i", $session_db_id);
            if ($stmt->execute()) {
                $monitor_msg_success = "Admin device has been unblocked.";
            } else {
                $monitor_msg_error = "Could not unblock admin device: " . $conn->error;
            }
            $stmt->close();
        }
    }

    // ─── ACTION 9: Creating Admin Account (Main Admin Only) ──────────────────
    elseif ($action === 'create_admin' && $is_main_admin) {
        $full_name = trim($_POST['full_name'] ?? '');
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $password  = $_POST['password'] ?? '';
        $confirm   = $_POST['confirm_password'] ?? '';

        if (empty($full_name) || empty($username) || empty($email) || empty($password)) {
            $admin_msg_error = "Please fill in all required fields (Full Name, Username, Email, and Password).";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $admin_msg_error = "Please provide a valid email address.";
        } elseif (strlen($password) < 6) {
            $admin_msg_error = "Password must be at least 6 characters long.";
        } elseif ($password !== $confirm) {
            $admin_msg_error = "Passwords do not match.";
        } else {
            $check = $conn->prepare("SELECT id, role, username, email FROM users WHERE username = ? OR email = ? LIMIT 1");
            $check->bind_param("ss", $username, $email);
            $check->execute();
            $check_res = $check->get_result();

            if ($check_res && $check_res->num_rows > 0) {
                $existing = $check_res->fetch_assoc();
                if ($existing['role'] === 'user') {
                    // Automatically upgrade existing user to Administrator
                    $pwd_hash = password_hash($password, PASSWORD_DEFAULT);
                    $upd = $conn->prepare("UPDATE users SET full_name = ?, phone = ?, password = ?, role = 'admin', status = 'approved' WHERE id = ?");
                    $upd->bind_param("sssi", $full_name, $phone, $pwd_hash, $existing['id']);
                    if ($upd->execute()) {
                        $admin_msg_success = "Existing user account '{$existing['username']}' ({$email}) has been successfully upgraded to Administrator!";
                        require_once __DIR__ . '/notification_helper.php';
                        NotificationHelper::sendAdminCreatedEmail($conn, $email, $full_name, $username, $password);
                    } else {
                        $admin_msg_error = "Failed to upgrade existing user to admin: " . $conn->error;
                    }
                    $upd->close();
                } else {
                    $admin_msg_error = "An administrator account with that username or email already exists.";
                }
            } else {
                $role = 'admin'; // Created admins strictly receive standard limited admin access
                $status = 'approved';
                $pwd_hash = password_hash($password, PASSWORD_DEFAULT);
                $ins = $conn->prepare("INSERT INTO users (username, full_name, email, phone, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $ins->bind_param("sssssss", $username, $full_name, $email, $phone, $pwd_hash, $role, $status);
                if ($ins->execute()) {
                    $admin_msg_success = "Admin account '{$username}' ({$email}) was successfully created!";
                    
                    // Dispatch email notification to new admin's Gmail
                    require_once __DIR__ . '/notification_helper.php';
                    $sent = NotificationHelper::sendAdminCreatedEmail($conn, $email, $full_name, $username, $password);
                    if ($sent) {
                        $admin_msg_success .= " An activation email with credentials has been sent to {$email}.";
                    }
                } else {
                    $admin_msg_error = "Failed to create administrator: " . $conn->error;
                }
                $ins->close();
            }
            $check->close();
        }
    }

    // ─── ACTION 10: Delete Admin or User Account (Main Admin Only) ───────────
    elseif ($action === 'delete_admin' && $is_main_admin) {
        $target_user_id = (int)($_POST['target_user_id'] ?? 0);

        if ($target_user_id <= 0) {
            $admin_msg_error = "Invalid account ID.";
        } elseif ($target_user_id === $current_user_id) {
            $admin_msg_error = "You cannot delete your own Main Admin account.";
        } else {
            $chk = $conn->prepare("SELECT username, email, role FROM users WHERE id = ? LIMIT 1");
            $chk->bind_param("i", $target_user_id);
            $chk->execute();
            $chk_res = $chk->get_result();
            if ($chk_res && $adm = $chk_res->fetch_assoc()) {
                if ($adm['role'] === 'main_admin' || $adm['email'] === 'creationtyoy@gmail.com') {
                    $admin_msg_error = "Protection rule: The Main Admin account cannot be deleted.";
                } elseif (in_array($adm['role'], ['admin', 'user'])) {
                    // Delete sessions of deleted user/admin first
                    $conn->query("DELETE FROM user_sessions WHERE user_id = {$target_user_id}");
                    $del = $conn->prepare("DELETE FROM users WHERE id = ? LIMIT 1");
                    $del->bind_param("i", $target_user_id);
                    if ($del->execute()) {
                        $role_label = ($adm['role'] === 'admin') ? 'Admin' : 'User';
                        $admin_msg_success = "{$role_label} account '{$adm['username']}' was successfully deleted.";
                    } else {
                        $admin_msg_error = "Failed to delete account: " . $conn->error;
                    }
                    $del->close();
                } else {
                    $admin_msg_error = "Selected account cannot be deleted.";
                }
            } else {
                $admin_msg_error = "Account not found.";
            }
            $chk->close();
        }
    }

    // ─── ACTION 11: Promote Registered User to Admin (Main Admin Only) ───────
    elseif ($action === 'promote_to_admin' && $is_main_admin) {
        $target_user_id = (int)($_POST['target_user_id'] ?? 0);
        if ($target_user_id > 0) {
            $up = $conn->prepare("UPDATE users SET role = 'admin', status = 'approved' WHERE id = ? AND role = 'user'");
            $up->bind_param("i", $target_user_id);
            if ($up->execute() && $up->affected_rows > 0) {
                $admin_msg_success = "User account #{$target_user_id} was successfully promoted to Administrator!";
            } else {
                $admin_msg_error = "Could not promote user or user is already an administrator.";
            }
            $up->close();
        }
    }
}

// ─── Fetch logged-in devices / sessions for current user ─────────────────────
$sessions_stmt = $conn->prepare("SELECT id, session_id, ip_address, user_agent, device_name, device_type, is_blocked, is_logged_out, created_at, last_activity FROM user_sessions WHERE user_id = ? ORDER BY last_activity DESC");
$sessions_stmt->bind_param("i", $current_user_id);
$sessions_stmt->execute();
$sessions_res = $sessions_stmt->get_result();
$user_sessions_list = [];
if ($sessions_res) {
    while ($row = $sessions_res->fetch_assoc()) {
        $user_sessions_list[] = $row;
    }
}
$sessions_stmt->close();

// ─── If Main Admin: Fetch all admins with their live presence & devices ──────
$all_admins = [];
$total_online_count = 0;
if ($is_main_admin) {
    $all_admins_query = "SELECT id, username, full_name, email, phone, role, status, last_login_at, last_seen_at, last_logout_at, last_ip, last_device, created_at FROM users WHERE role IN ('main_admin', 'super_admin', 'admin') ORDER BY role DESC, id ASC";
    $all_admins_res = $conn->query($all_admins_query);
    if ($all_admins_res) {
        while ($r = $all_admins_res->fetch_assoc()) {
            $s_stmt = $conn->prepare("SELECT id, session_id, ip_address, user_agent, device_name, device_type, is_blocked, is_logged_out, created_at, last_activity FROM user_sessions WHERE user_id = ? ORDER BY last_activity DESC");
            $s_stmt->bind_param("i", $r['id']);
            $s_stmt->execute();
            $s_res = $s_stmt->get_result();
            $sessions = [];
            if ($s_res) {
                while ($s = $s_res->fetch_assoc()) {
                    $sessions[] = $s;
                }
            }
            $s_stmt->close();
            $r['sessions'] = $sessions;
            $r['online_info'] = get_user_online_status($r);
            if ($r['online_info']['is_online']) {
                $total_online_count++;
            }
            $all_admins[] = $r;
        }
    }
}

// ─── If Main Admin: Fetch registered client accounts (role = 'user') ─────────
$all_client_users = [];
if ($is_main_admin) {
    $all_users_res = $conn->query("SELECT id, username, full_name, email, phone, role, status, created_at FROM users WHERE role = 'user' ORDER BY id DESC");
    if ($all_users_res) {
        while ($ru = $all_users_res->fetch_assoc()) {
            $all_client_users[] = $ru;
        }
    }
}

// Main admin info
$main_admin_res = $conn->query("SELECT id, username, full_name, email, phone, role FROM users WHERE role IN ('main_admin', 'super_admin') LIMIT 1");
$main_admin_info = $main_admin_res ? $main_admin_res->fetch_assoc() : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings &amp; Security - Tyoy Creation</title>
    <link rel="icon" type="image/png" href="assets/favicon.png?v=<?= filemtime(__DIR__ . '/assets/favicon.png') ?>">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .settings-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            padding: 30px;
            max-width: 960px;
            margin-bottom: 24px;
        }
        .pwd-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .pwd-input-wrap input {
            padding-right: 42px;
        }
        .pwd-toggle-btn {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 14px;
            padding: 4px;
        }
        .pwd-toggle-btn:hover {
            color: var(--primary);
        }
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 14px;
        }
        .admin-table th {
            text-align: left;
            padding: 10px 14px;
            background: #f8faf8;
            color: var(--text-secondary);
            font-weight: 700;
            border-bottom: 1px solid var(--border-color);
        }
        .admin-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f1;
            color: var(--text-primary);
            vertical-align: middle;
        }
        .role-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .role-main_admin {
            background: #fef3c7;
            color: #92400e;
        }
        .role-admin {
            background: #eef2ee;
            color: #2b392a;
        }
        .role-user {
            background: #e0f2fe;
            color: #0369a1;
        }
        .account-tabs {
            display: flex;
            gap: 8px;
            margin-top: 14px;
            margin-bottom: 14px;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 8px;
        }
        .account-tab-btn {
            background: none;
            border: none;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 700;
            color: var(--text-secondary);
            cursor: pointer;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.15s;
        }
        .account-tab-btn:hover {
            background: #f3f4f6;
            color: var(--text-primary);
        }
        .account-tab-btn.active {
            background: #eef2ee;
            color: var(--primary);
        }
        .btn-promote {
            background: #eef2ee;
            color: var(--primary);
            border-color: #c7d7c6;
        }
        .btn-promote:hover {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }
        .btn-action-sm {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            transition: all 0.15s;
            border: 1px solid transparent;
        }
        .btn-block-device {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fca5a5;
        }
        .btn-block-device:hover {
            background: #dc2626;
            color: #ffffff;
            border-color: #dc2626;
        }
        .btn-unblock-device {
            background: #d1fae5;
            color: #065f46;
            border-color: #a7f3d0;
        }
        .btn-unblock-device:hover {
            background: #059669;
            color: #ffffff;
            border-color: #059669;
        }
        .btn-terminate {
            background: #f3f4f6;
            color: #4b5563;
            border-color: #d1d5db;
        }
        .btn-terminate:hover {
            background: #e5e7eb;
            color: #111827;
        }
        .device-card-item {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 16px 18px;
            margin-bottom: 12px;
            background: #ffffff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
            transition: all 0.2s;
        }
        .device-card-item.is-current {
            border-color: #86efac;
            background: #f0fdf4;
        }
        .device-card-item.is-blocked {
            border-color: #fca5a5;
            background: #fff5f5;
        }
        .current-badge {
            background: #22c55e;
            color: #ffffff;
            font-size: 10px;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .pulse-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #22c55e;
            display: inline-block;
            animation: pulse-ring 1.5s infinite;
        }
        @keyframes pulse-ring {
            0% { transform: scale(0.9); opacity: 0.8; }
            50% { transform: scale(1.4); opacity: 1; }
            100% { transform: scale(0.9); opacity: 0.8; }
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 99px;
        }
        .status-pill.online {
            background: #dcfce7;
            color: #15803d;
        }
        .status-pill.offline {
            background: #f3f4f6;
            color: #6b7280;
        }
        .stat-badge-summary {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .stat-badge-box {
            background: #f8faf8;
            border: 1px solid #dce5dc;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .stat-badge-box strong {
            font-size: 15px;
            color: var(--primary);
        }
        .drawer-row {
            background: #fafafa;
            border: 1px solid #ebebeb;
            border-radius: 8px;
            padding: 12px 14px;
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
    </style>
</head>
<body class="admin-app">

    <!-- Admin Sidebar -->
    <?php include __DIR__ . '/admin_sidebar.php'; ?>

    <main class="admin-main">
        <header class="admin-header">
            <div>
                <h1 class="admin-page-title">Settings &amp; Security</h1>
                <p style="color: var(--text-secondary); font-size: 13px; margin: 2px 0 0 0;">
                    Manage your credentials, track devices, and monitor admin activity in real time.
                </p>
            </div>
            <div class="admin-profile">
                <div class="admin-avatar" style="background: <?= $is_main_admin ? '#d97706' : '#364735' ?>; color: #fff;">
                    <?= strtoupper(substr($admin_username, 0, 1)) ?>
                </div>
                <div>
                    <div style="font-size: 14px; font-weight: 600;"><?= htmlspecialchars($admin_username) ?></div>
                    <span style="font-size: 10px; font-weight: 800; background: <?= $is_main_admin ? '#d97706' : '#6b7280' ?>; color: #fff; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">
                        <?= $is_main_admin ? 'Main Admin' : 'Admin' ?>
                    </span>
                </div>
            </div>
        </header>

        <div class="admin-body">

            <?php if ($is_main_admin): ?>
            <!-- Main Admin Banner pointing to Edit Web Templates -->
            <div style="background: #ffffff; border: 1px solid #dce5dc; border-left: 4px solid #364735; border-radius: 8px; padding: 16px 20px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; box-shadow: var(--shadow-sm); max-width: 960px;">
                <div style="display: flex; align-items: center; gap: 14px;">
                    <div style="width: 40px; height: 40px; border-radius: 8px; background: #eef2ee; color: #364735; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                        <i class="fa-solid fa-palette"></i>
                    </div>
                    <div>
                        <strong style="font-size: 14px; color: var(--text-primary); display: block;">Editing Pricing &amp; Packages, Business Details, or Email Templates?</strong>
                        <span style="font-size: 13px; color: var(--text-secondary);">Those management sections have been consolidated into the unified Edit Web Templates section.</span>
                    </div>
                </div>
                <a href="a_sitemanager.php" class="btn-primary" style="text-decoration: none; font-size: 13px; padding: 9px 18px; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Edit Web Templates
                </a>
            </div>
            <?php endif; ?>

            <?php if ($is_main_admin): ?>
            <!-- =================================================================== -->
            <!-- SECTION A (MAIN ADMIN ONLY): LIVE ADMIN MONITORING & TRACKING     -->
            <!-- =================================================================== -->
            <div class="settings-card" id="adminMonitorSection" style="border-top: 4px solid #10b981;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 14px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 40px; height: 40px; border-radius: 8px; background: #dcfce7; color: #10b981; display: flex; align-items: center; justify-content: center; font-size: 19px;">
                            <i class="fa-solid fa-tower-broadcast"></i>
                        </div>
                        <div>
                            <h2 style="font-size: 18px; margin: 0;">Admin Live Activity &amp; Device Monitor</h2>
                            <p style="color: var(--text-secondary); font-size: 13px; margin: 2px 0 0 0;">
                                Track in real-time which admins are Online or Offline, their login/logout times, and their connected devices.
                            </p>
                        </div>
                    </div>

                    <a href="a_settings.php#adminMonitorSection" class="btn-action-sm btn-terminate" style="padding: 7px 14px;">
                        <i class="fa-solid fa-arrows-rotate"></i> Refresh Status
                    </a>
                </div>

                <?php if (!empty($monitor_msg_success)): ?>
                    <div style="background: #d1fae5; color: #065f46; padding: 12px 16px; border-radius: 8px; margin: 14px 0; display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 600;">
                        <i class="fa-solid fa-circle-check"></i>
                        <span><?= htmlspecialchars($monitor_msg_success) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($monitor_msg_error)): ?>
                    <div style="background: #fee2e2; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin: 14px 0; display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 600;">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <span><?= htmlspecialchars($monitor_msg_error) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Summary Badges -->
                <div class="stat-badge-summary">
                    <div class="stat-badge-box">
                        <i class="fa-solid fa-users" style="color: var(--primary);"></i>
                        <span>Total Admins: <strong><?= count($all_admins) ?></strong></span>
                    </div>
                    <div class="stat-badge-box" style="background: #f0fdf4; border-color: #bbf7d0;">
                        <span class="pulse-dot"></span>
                        <span style="color: #15803d;">Online Now: <strong><?= $total_online_count ?></strong></span>
                    </div>
                    <div class="stat-badge-box" style="background: #f9fafb; border-color: #e5e7eb;">
                        <i class="fa-solid fa-moon" style="color: #6b7280;"></i>
                        <span style="color: #4b5563;">Offline: <strong><?= count($all_admins) - $total_online_count ?></strong></span>
                    </div>
                </div>

                <!-- Admin Presence Table -->
                <div style="overflow-x: auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Admin &amp; Role</th>
                                <th>Live Presence</th>
                                <th>Login Time</th>
                                <th>Logout / Last Seen</th>
                                <th>Last Known Device &amp; IP</th>
                                <th style="text-align: right;">Device Control</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_admins as $adm): 
                                $on_info = $adm['online_info'];
                                $is_online = $on_info['is_online'];
                                $session_count = count($adm['sessions']);
                                $is_self = ($adm['id'] == $current_user_id);
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($adm['full_name'] ?: $adm['username']) ?></strong>
                                    <?php if ($is_self): ?>
                                        <span style="font-size: 10px; background: #e0f2fe; color: #0284c7; padding: 1px 6px; border-radius: 4px; font-weight: 700; margin-left: 4px;">YOU</span>
                                    <?php endif; ?>
                                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                                        @<?= htmlspecialchars($adm['username']) ?> &bull; <?= htmlspecialchars($adm['email']) ?>
                                    </div>
                                    <div style="margin-top: 4px;">
                                        <span class="role-badge role-<?= htmlspecialchars($adm['role']) ?>">
                                            <?= ($adm['role'] === 'main_admin') ? 'Main Admin' : 'Admin' ?>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <?php if ($is_online): ?>
                                        <span class="status-pill online">
                                            <span class="pulse-dot"></span> Online Now
                                        </span>
                                    <?php else: ?>
                                        <span class="status-pill offline">
                                            <i class="fa-solid fa-circle" style="font-size: 7px; color: #9ca3af;"></i> Offline
                                        </span>
                                    <?php endif; ?>
                                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 3px;">
                                        <?= htmlspecialchars($on_info['sub_label']) ?>
                                    </div>
                                </td>

                                <td>
                                    <?php if (!empty($adm['last_login_at'])): ?>
                                        <div style="font-weight: 600;"><?= htmlspecialchars(date('M d, Y', strtotime($adm['last_login_at']))) ?></div>
                                        <div style="font-size: 12px; color: var(--text-secondary);"><?= htmlspecialchars(date('h:i:s A', strtotime($adm['last_login_at']))) ?></div>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">No login recorded</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if (!empty($adm['last_logout_at']) && strtotime($adm['last_logout_at']) >= strtotime($adm['last_seen_at'] ?? '0')): ?>
                                        <div style="font-weight: 600; color: #b91c1c;"><i class="fa-solid fa-arrow-right-from-bracket" style="font-size: 11px;"></i> Logged out</div>
                                        <div style="font-size: 12px; color: var(--text-secondary);"><?= htmlspecialchars(date('M d, Y h:i A', strtotime($adm['last_logout_at']))) ?></div>
                                    <?php elseif (!empty($adm['last_seen_at'])): ?>
                                        <div style="font-weight: 600;"><?= htmlspecialchars(date('M d, Y', strtotime($adm['last_seen_at']))) ?></div>
                                        <div style="font-size: 12px; color: var(--text-secondary);"><?= htmlspecialchars(date('h:i:s A', strtotime($adm['last_seen_at']))) ?></div>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">&mdash;</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if (!empty($adm['last_device'])): ?>
                                        <div style="font-size: 12px; font-weight: 600; color: var(--text-primary);">
                                            <?= htmlspecialchars($adm['last_device']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">
                                        <i class="fa-solid fa-network-wired" style="font-size: 10px;"></i> <?= htmlspecialchars($adm['last_ip'] ?: '127.0.0.1') ?>
                                    </div>
                                    <div style="font-size: 11px; color: #0284c7; margin-top: 2px; font-weight: 600;">
                                        <i class="fa-solid fa-laptop"></i> <?= $session_count ?> device session(s)
                                    </div>
                                </td>

                                <td style="text-align: right;">
                                    <div style="display: flex; flex-direction: column; gap: 6px; align-items: flex-end;">
                                        <!-- Toggle devices dropdown -->
                                        <button type="button" class="btn-action-sm btn-terminate" onclick="toggleAdminDevicesDrawer('drawer_<?= $adm['id'] ?>', this)">
                                            <i class="fa-solid fa-devices"></i> Track Devices (<?= $session_count ?>)
                                        </button>

                                        <?php if (!$is_self): ?>
                                            <!-- Force Logout button -->
                                            <button type="button" class="btn-action-sm btn-block-device" style="font-size: 11px; padding: 4px 8px;" title="Kick out and block all devices" onclick="openForceLogoutModal(<?= (int)$adm['id'] ?>, '<?= htmlspecialchars(addslashes($adm['username'])) ?>')">
                                                <i class="fa-solid fa-ban"></i> Force Logout
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                            <!-- Collapsible Drawer for this Admin's Logged-in Devices -->
                            <tr id="drawer_<?= $adm['id'] ?>" style="display: none; background: #fdfdfd;">
                                <td colspan="6" style="padding: 16px 20px; border-bottom: 2px solid #e5e7eb;">
                                    <div style="font-size: 13px; font-weight: 700; color: var(--primary); margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
                                        <span><i class="fa-solid fa-shield-halved"></i> Active &amp; Historical Devices for <u><?= htmlspecialchars($adm['username']) ?></u></span>
                                        <button type="button" class="btn-action-sm btn-terminate" onclick="toggleAdminDevicesDrawer('drawer_<?= $adm['id'] ?>', null)" style="font-size: 11px; padding: 2px 8px;">Close &times;</button>
                                    </div>

                                    <?php if (empty($adm['sessions'])): ?>
                                        <p style="color: var(--text-muted); font-size: 12px; margin: 4px 0;">No active device sessions found for this user.</p>
                                    <?php else: ?>
                                        <?php foreach ($adm['sessions'] as $s_item): 
                                            $d_info = get_device_info($s_item['user_agent']);
                                            $s_blocked = ((int)$s_item['is_blocked'] === 1);
                                            $s_logged_out = ((int)($s_item['is_logged_out'] ?? 0) === 1);
                                        ?>
                                        <div class="drawer-row" style="<?= $s_blocked ? 'border-color: #fca5a5; background: #fff5f5;' : '' ?>">
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <i class="fa-solid <?= htmlspecialchars($d_info['icon']) ?>" style="font-size: 18px; color: <?= $s_blocked ? '#dc2626' : '#364735' ?>;"></i>
                                                <div>
                                                    <div style="font-size: 13px; font-weight: 600;">
                                                        <?= htmlspecialchars($s_item['device_name'] ?: $d_info['name']) ?>
                                                        <?php if ($s_blocked): ?>
                                                            <span style="background: #ef4444; color: #fff; font-size: 10px; font-weight: 800; padding: 1px 6px; border-radius: 4px; margin-left: 6px;">BLOCKED</span>
                                                        <?php elseif ($s_logged_out): ?>
                                                            <span style="background: #e5e7eb; color: #4b5563; font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 4px; margin-left: 6px;">LOGGED OUT</span>
                                                        <?php else: ?>
                                                            <span style="background: #dcfce7; color: #15803d; font-size: 10px; font-weight: 800; padding: 1px 6px; border-radius: 4px; margin-left: 6px;">ACTIVE</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div style="font-size: 11px; color: var(--text-secondary); margin-top: 2px;">
                                                        IP: <?= htmlspecialchars($s_item['ip_address']) ?> &bull; Logged in: <?= date('M d, Y h:i A', strtotime($s_item['created_at'])) ?> &bull; Last Active: <?= date('M d, Y h:i A', strtotime($s_item['last_activity'])) ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div>
                                                <?php if ($s_blocked): ?>
                                                    <form method="POST" action="a_settings.php#adminMonitorSection" style="display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                        <input type="hidden" name="action" value="admin_unblock_device_specific">
                                                        <input type="hidden" name="session_db_id" value="<?= (int)$s_item['id'] ?>">
                                                        <button type="submit" class="btn-action-sm btn-unblock-device" style="font-size: 11px; padding: 4px 8px;">
                                                            <i class="fa-solid fa-lock-open"></i> Unblock Device
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <button type="button" class="btn-action-sm btn-block-device" style="font-size: 11px; padding: 4px 8px;" onclick="openBlockDeviceSpecificModal(<?= (int)$s_item['id'] ?>, '<?= htmlspecialchars(addslashes($adm['username'])) ?>', '<?= htmlspecialchars(addslashes($s_item['device_name'] ?: $d_info['name'])) ?>', '<?= htmlspecialchars(addslashes($s_item['ip_address'])) ?>')">
                                                        <i class="fa-solid fa-ban"></i> Block Device
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- =================================================================== -->
            <!-- SECTION 1: Changing Password (For logged-in user)                  -->
            <!-- =================================================================== -->
            <div class="settings-card" id="changePasswordSection" style="border-top: 4px solid var(--primary);">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
                    <div style="width: 38px; height: 38px; border-radius: 8px; background: #eef2ee; color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 18px;">
                        <i class="fa-solid fa-key"></i>
                    </div>
                    <div>
                        <h2 style="font-size: 18px; margin: 0;">Change Password</h2>
                        <p style="color: var(--text-secondary); font-size: 13px; margin: 2px 0 0 0;">Update your administrator credentials to keep your account safe.</p>
                    </div>
                </div>

                <?php if (!empty($pwd_success)): ?>
                    <div style="background: #d1fae5; color: #065f46; padding: 12px 16px; border-radius: 8px; margin: 16px 0; display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 600;">
                        <i class="fa-solid fa-circle-check"></i>
                        <span><?= htmlspecialchars($pwd_success) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($pwd_error)): ?>
                    <div style="background: #fee2e2; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin: 16px 0; display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 600;">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <span><?= htmlspecialchars($pwd_error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="a_settings.php#changePasswordSection" style="margin-top: 18px;">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="change_password">

                    <div class="form-group">
                        <label class="form-label">Current Password *</label>
                        <div class="pwd-input-wrap">
                            <input type="password" name="current_password" id="currPwd" class="form-control" placeholder="Enter current password" required autocomplete="current-password">
                            <button type="button" class="pwd-toggle-btn" onclick="togglePwdVisibility('currPwd', this)" title="Show/Hide Password">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label">New Password *</label>
                            <div class="pwd-input-wrap">
                                <input type="password" name="new_password" id="newPwd" class="form-control" placeholder="Minimum 6 characters" required minlength="6" autocomplete="new-password">
                                <button type="button" class="pwd-toggle-btn" onclick="togglePwdVisibility('newPwd', this)" title="Show/Hide Password">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm New Password *</label>
                            <div class="pwd-input-wrap">
                                <input type="password" name="confirm_password" id="confPwd" class="form-control" placeholder="Repeat new password" required minlength="6" autocomplete="new-password">
                                <button type="button" class="pwd-toggle-btn" onclick="togglePwdVisibility('confPwd', this)" title="Show/Hide Password">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary" style="padding: 11px 24px; font-size: 14px; margin-top: 6px;">
                        <i class="fa-solid fa-floppy-disk"></i> Update Password
                    </button>
                </form>
            </div>

            <!-- =================================================================== -->
            <!-- SECTION 2: My Device & Login Activity Tracker                       -->
            <!-- =================================================================== -->
            <div class="settings-card" id="devicesSection" style="border-top: 4px solid #0284c7;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 8px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 38px; height: 38px; border-radius: 8px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 18px;">
                            <i class="fa-solid fa-shield-virus"></i>
                        </div>
                        <div>
                            <h2 style="font-size: 18px; margin: 0;">My Device &amp; Login Activity</h2>
                            <p style="color: var(--text-secondary); font-size: 13px; margin: 2px 0 0 0;">
                                Track devices logged into your account. You can block or revoke any session immediately if you suspect unauthorized access.
                            </p>
                        </div>
                    </div>

                    <!-- Panic Button: Block all other devices -->
                    <button type="button" class="btn-action-sm btn-block-device" style="padding: 8px 14px;" onclick="openBlockAllOtherDevicesModal()">
                        <i class="fa-solid fa-ban"></i> Block All Other Devices
                    </button>
                </div>

                <?php if (!empty($device_msg_success)): ?>
                    <div style="background: #d1fae5; color: #065f46; padding: 12px 16px; border-radius: 8px; margin: 16px 0; display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 600;">
                        <i class="fa-solid fa-circle-check"></i>
                        <span><?= htmlspecialchars($device_msg_success) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($device_msg_error)): ?>
                    <div style="background: #fee2e2; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin: 16px 0; display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 600;">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <span><?= htmlspecialchars($device_msg_error) ?></span>
                    </div>
                <?php endif; ?>

                <div style="margin-top: 18px;">
                    <?php if (empty($user_sessions_list)): ?>
                        <div style="text-align: center; padding: 24px; color: var(--text-muted); background: #f9fafb; border-radius: 8px;">
                            <i class="fa-solid fa-desktop" style="font-size: 24px; margin-bottom: 8px; display: block;"></i>
                            No device activity logged yet. Your current device will be recorded on your next action.
                        </div>
                    <?php else: ?>
                        <?php foreach ($user_sessions_list as $sess): 
                            $is_current = ($sess['session_id'] === $current_session_id);
                            $is_blocked = ((int)$sess['is_blocked'] === 1);
                            $device_info = get_device_info($sess['user_agent']);
                        ?>
                        <div class="device-card-item <?= $is_current ? 'is-current' : ($is_blocked ? 'is-blocked' : '') ?>">
                            <div style="display: flex; align-items: center; gap: 14px;">
                                <div style="width: 44px; height: 44px; border-radius: 10px; background: <?= $is_blocked ? '#fee2e2' : ($is_current ? '#dcfce7' : '#f3f4f6') ?>; color: <?= $is_blocked ? '#dc2626' : ($is_current ? '#16a34a' : '#4b5563') ?>; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                                    <i class="fa-solid <?= htmlspecialchars($device_info['icon']) ?>"></i>
                                </div>
                                <div>
                                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                        <strong style="font-size: 14px; color: var(--text-primary);"><?= htmlspecialchars($sess['device_name'] ?: $device_info['name']) ?></strong>
                                        <?php if ($is_current): ?>
                                            <span class="current-badge"><span class="pulse-dot" style="background:#fff;"></span> This Device</span>
                                        <?php endif; ?>
                                        <?php if ($is_blocked): ?>
                                            <span style="background: #ef4444; color: #fff; font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 4px; text-transform: uppercase;">
                                                <i class="fa-solid fa-lock"></i> Blocked
                                            </span>
                                        <?php else: ?>
                                            <span style="background: #e0f2fe; color: #0284c7; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 4px;">
                                                Authorized
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 12px; color: var(--text-secondary); margin-top: 4px; display: flex; gap: 12px; flex-wrap: wrap;">
                                        <span><i class="fa-solid fa-network-wired" style="margin-right: 4px;"></i> IP: <?= htmlspecialchars($sess['ip_address']) ?></span>
                                        <span><i class="fa-solid fa-clock" style="margin-right: 4px;"></i> Last Active: <?= htmlspecialchars(date('M d, Y h:i A', strtotime($sess['last_activity']))) ?></span>
                                    </div>
                                </div>
                            </div>

                            <div style="display: flex; gap: 8px; align-items: center;">
                                <?php if ($is_current): ?>
                                    <span style="font-size: 12px; color: #16a34a; font-weight: 700; padding: 6px 12px; background: #e8fbee; border-radius: 6px;">
                                        <i class="fa-solid fa-circle-check"></i> Active Now
                                    </span>
                                <?php else: ?>
                                    <?php if ($is_blocked): ?>
                                        <!-- Unblock button -->
                                        <form method="POST" action="a_settings.php#devicesSection" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="unblock_device">
                                            <input type="hidden" name="session_db_id" value="<?= (int)$sess['id'] ?>">
                                            <button type="submit" class="btn-action-sm btn-unblock-device" title="Allow this device to log in again">
                                                <i class="fa-solid fa-lock-open"></i> Unblock Device
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <!-- Block button -->
                                        <button type="button" class="btn-action-sm btn-block-device" title="Block access from this device" onclick="openBlockMyDeviceModal(<?= (int)$sess['id'] ?>, '<?= htmlspecialchars(addslashes($sess['device_name'] ?: $device_info['name'])) ?>', '<?= htmlspecialchars(addslashes($sess['ip_address'])) ?>')">
                                            <i class="fa-solid fa-ban"></i> Block Device
                                        </button>
                                    <?php endif; ?>

                                    <!-- Remove / Terminate button -->
                                    <form method="POST" action="a_settings.php#devicesSection" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="action" value="terminate_session">
                                        <input type="hidden" name="session_db_id" value="<?= (int)$sess['id'] ?>">
                                        <button type="submit" class="btn-action-sm btn-terminate" title="Remove session record">
                                            <i class="fa-solid fa-xmark"></i> Revoke
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($is_main_admin): ?>
            <!-- =================================================================== -->
            <!-- SECTION 3: Creating Admin Feature (Main Admin Only)                -->
            <!-- =================================================================== -->
            <div class="settings-card" id="createAdminSection" style="border-top: 4px solid var(--primary);">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
                    <div style="width: 38px; height: 38px; border-radius: 8px; background: #eef2ee; color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 18px;">
                        <i class="fa-solid fa-user-plus"></i>
                    </div>
                    <div>
                        <h2 style="font-size: 18px; margin: 0;">Create New Admin</h2>
                        <p style="color: var(--text-secondary); font-size: 13px; margin: 2px 0 0 0;">
                            Create an admin account. Created admins have operational access restricted to: Dashboard, Bookings, Master Calendar, Client Info, Password, and Device Security.
                        </p>
                    </div>
                </div>

                <?php if (!empty($admin_msg_success)): ?>
                    <div style="background: #d1fae5; color: #065f46; padding: 12px 16px; border-radius: 8px; margin: 16px 0; display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 600;">
                        <i class="fa-solid fa-circle-check"></i>
                        <span><?= htmlspecialchars($admin_msg_success) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($admin_msg_error)): ?>
                    <div style="background: #fee2e2; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin: 16px 0; display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 600;">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <span><?= htmlspecialchars($admin_msg_error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="a_settings.php#createAdminSection" style="margin-top: 18px;">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="create_admin">

                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="full_name" class="form-control" placeholder="e.g. Juan Dela Cruz" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Username *</label>
                            <input type="text" name="username" class="form-control" placeholder="e.g. juan_admin" required autocomplete="off">
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label">Email Address *</label>
                            <input type="email" name="email" class="form-control" placeholder="e.g. juan@gmail.com" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Contact Phone</label>
                            <input type="text" name="phone" class="form-control" placeholder="+63 9...">
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label">Password *</label>
                            <div class="pwd-input-wrap">
                                <input type="password" name="password" id="newAdminPwd" class="form-control" placeholder="Minimum 6 characters" required minlength="6" autocomplete="new-password">
                                <button type="button" class="pwd-toggle-btn" onclick="togglePwdVisibility('newAdminPwd', this)" title="Show/Hide Password">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm Password *</label>
                            <div class="pwd-input-wrap">
                                <input type="password" name="confirm_password" id="newAdminConfPwd" class="form-control" placeholder="Repeat password" required minlength="6" autocomplete="new-password">
                                <button type="button" class="pwd-toggle-btn" onclick="togglePwdVisibility('newAdminConfPwd', this)" title="Show/Hide Password">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary" style="padding: 11px 26px; font-size: 14px; margin-top: 6px;">
                        <i class="fa-solid fa-user-plus"></i> Create Admin Account
                    </button>
                </form>
            </div>

            <!-- =================================================================== -->
            <!-- SECTION 4: Manage & Delete Admin Accounts (Main Admin Only)        -->
            <!-- =================================================================== -->
            <div class="settings-card" id="manageAdminsSection">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 38px; height: 38px; border-radius: 8px; background: #eef2ee; color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 18px;">
                            <i class="fa-solid fa-users-gear"></i>
                        </div>
                        <div>
                            <h2 style="font-size: 18px; margin: 0;">Manage &amp; Delete Accounts</h2>
                            <p style="color: var(--text-secondary); font-size: 13px; margin: 2px 0 0 0;">
                                View and delete administrator and user accounts. Main Admin cannot be deleted.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Main Admin Info Box -->
                <?php if ($main_admin_info): ?>
                <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 16px; margin: 16px 0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <span class="role-badge role-main_admin"><i class="fa-solid fa-crown" style="margin-right: 4px;"></i> Main Admin (Original)</span>
                        <strong style="margin-left: 8px; color: #92400e; font-size: 14px;"><?= htmlspecialchars($main_admin_info['email']) ?></strong>
                        <span style="color: #b45309; font-size: 13px;">(@<?= htmlspecialchars($main_admin_info['username']) ?>)</span>
                    </div>
                    <span style="font-size: 12px; color: #92400e; font-weight: 600;"><i class="fa-solid fa-shield-halved"></i> Protected &bull; Full Access</span>
                </div>
                <?php endif; ?>

                <?php 
                $created_admins = array_filter($all_admins, function($a) {
                    return $a['role'] === 'admin';
                });
                ?>

                <!-- Account Tabs -->
                <div class="account-tabs">
                    <button type="button" class="account-tab-btn active" id="tabBtnAdmins" onclick="switchAccountTab('admins')">
                        <i class="fa-solid fa-user-shield"></i> Administrators (<?= count($created_admins) ?>)
                    </button>
                    <button type="button" class="account-tab-btn" id="tabBtnUsers" onclick="switchAccountTab('users')">
                        <i class="fa-solid fa-users"></i> Registered Users (<?= count($all_client_users) ?>)
                    </button>
                </div>

                <!-- TAB 1: Administrators Table -->
                <div id="tableAdminsWrap" style="overflow-x: auto; margin-top: 10px;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Name / Username</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Access Level</th>
                                <th>Created Date</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($created_admins)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 24px;">
                                    <i class="fa-solid fa-user-slash" style="font-size: 20px; display: block; margin-bottom: 6px;"></i>
                                    No created admin accounts yet. Use the form above to add an admin.
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($created_admins as $adm): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($adm['full_name'] ?: $adm['username']) ?></strong>
                                        <div style="font-size: 12px; color: var(--text-muted);">@<?= htmlspecialchars($adm['username']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($adm['email']) ?></td>
                                    <td><?= htmlspecialchars($adm['phone'] ?: '—') ?></td>
                                    <td>
                                        <span class="role-badge role-admin">
                                            Limited Admin
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars(date('M d, Y', strtotime($adm['created_at'] ?? 'now'))) ?></td>
                                    <td style="text-align: right;">
                                        <button type="button" class="btn-action-sm btn-block-device" title="Delete this admin account" onclick="openDeleteAdminModal(<?= (int)$adm['id'] ?>, '<?= htmlspecialchars(addslashes($adm['username'])) ?>', '<?= htmlspecialchars(addslashes($adm['email'])) ?>', 'Administrator')">
                                            <i class="fa-solid fa-trash-can"></i> Delete Admin
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- TAB 2: Registered Client Users Table -->
                <div id="tableUsersWrap" style="overflow-x: auto; margin-top: 10px; display: none;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Name / Username</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Role</th>
                                <th>Registered Date</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($all_client_users)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 24px;">
                                    <i class="fa-solid fa-user-slash" style="font-size: 20px; display: block; margin-bottom: 6px;"></i>
                                    No registered client users found in the database.
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($all_client_users as $usr): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($usr['full_name'] ?: $usr['username']) ?></strong>
                                        <div style="font-size: 12px; color: var(--text-muted);">@<?= htmlspecialchars($usr['username']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($usr['email']) ?></td>
                                    <td><?= htmlspecialchars($usr['phone'] ?: '—') ?></td>
                                    <td>
                                        <span class="role-badge role-user">
                                            Client User
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars(date('M d, Y', strtotime($usr['created_at'] ?? 'now'))) ?></td>
                                    <td style="text-align: right;">
                                        <div style="display: inline-flex; gap: 6px; align-items: center;">
                                            <form method="POST" action="a_settings.php#manageAdminsSection" style="display: inline;" onsubmit="return confirm('Promote <?= htmlspecialchars(addslashes($usr['username'])) ?> to Administrator?');">
                                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                <input type="hidden" name="action" value="promote_to_admin">
                                                <input type="hidden" name="target_user_id" value="<?= (int)$usr['id'] ?>">
                                                <button type="submit" class="btn-action-sm btn-promote" title="Upgrade this user to Administrator">
                                                    <i class="fa-solid fa-arrow-up-right-dots"></i> Make Admin
                                                </button>
                                            </form>
                                            <button type="button" class="btn-action-sm btn-block-device" title="Delete this user account" onclick="openDeleteAdminModal(<?= (int)$usr['id'] ?>, '<?= htmlspecialchars(addslashes($usr['username'])) ?>', '<?= htmlspecialchars(addslashes($usr['email'])) ?>', 'User')">
                                                <i class="fa-solid fa-trash-can"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </main>

    <!-- Modal 1: Force Admin Logout & Block -->
    <div class="modal-backdrop" id="forceLogoutModal" style="z-index: 9999;">
        <div class="modal-card" style="max-width: 480px; flex-direction: column; border-radius: 16px; overflow: hidden; box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.4);">
            <form method="POST" action="a_settings.php#adminMonitorSection" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="admin_force_logout">
                <input type="hidden" name="target_admin_id" id="flTargetAdminId" value="">

                <div style="padding: 20px 24px; border-bottom: 1px solid #fee2e2; display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, #fff5f5 0%, #fef2f2 100%);">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 40px; height: 40px; border-radius: 12px; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 2px 8px rgba(220, 38, 38, 0.15);">
                            <i class="fa-solid fa-ban"></i>
                        </div>
                        <div>
                            <h3 style="font-size: 17px; margin: 0; font-weight: 700; color: #991b1b;">Force Admin Logout</h3>
                            <p style="font-size: 12px; color: #b91c1c; margin: 2px 0 0 0;">Security Session Termination</p>
                        </div>
                    </div>
                    <button type="button" onclick="closeForceLogoutModal()" style="background: transparent; border: none; font-size: 20px; color: #9ca3af; cursor: pointer;" aria-label="Close">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div style="padding: 24px;">
                    <p style="font-size: 14px; color: #374151; margin: 0 0 16px 0; line-height: 1.5;">
                        Are you sure you want to force sign out this administrator?
                    </p>

                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; font-size: 13px;">
                        <span style="color: #64748b; font-weight: 600;">Target Administrator:</span>
                        <strong id="flModalAdminName" style="color: #0f172a; font-size: 14px; margin-left: 6px;">-</strong>
                    </div>

                    <div style="background: #fff1f2; border-left: 4px solid #f43f5e; padding: 10px 14px; border-radius: 6px; font-size: 12px; color: #9f1239; display: flex; align-items: flex-start; gap: 8px; line-height: 1.4;">
                        <i class="fa-solid fa-triangle-exclamation" style="font-size: 14px; margin-top: 1px; flex-shrink: 0;"></i>
                        <span>This will terminate all current device sessions for this user and revoke access immediately.</span>
                    </div>
                </div>

                <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 10px; background: #fafafa;">
                    <button type="button" class="btn-secondary" onclick="closeForceLogoutModal()" style="padding: 10px 20px; font-size: 13px; font-weight: 600; border-radius: 8px;">Cancel</button>
                    <button type="submit" style="padding: 10px 22px; font-size: 13px; font-weight: 700; background: #dc2626; color: #ffffff; border: none; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);">
                        <i class="fa-solid fa-ban"></i> Force Logout &amp; Block
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal 2: Block Device Modal (Specific & My Device) -->
    <div class="modal-backdrop" id="blockDeviceModal" style="z-index: 9999;">
        <div class="modal-card" style="max-width: 480px; flex-direction: column; border-radius: 16px; overflow: hidden; box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.4);">
            <form method="POST" id="blockDeviceForm" action="" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" id="bdFormAction" value="admin_block_device_specific">
                <input type="hidden" name="session_db_id" id="bdSessionId" value="">

                <div style="padding: 20px 24px; border-bottom: 1px solid #fee2e2; display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, #fff5f5 0%, #fef2f2 100%);">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 40px; height: 40px; border-radius: 12px; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 2px 8px rgba(220, 38, 38, 0.15);">
                            <i class="fa-solid fa-laptop-slash"></i>
                        </div>
                        <div>
                            <h3 style="font-size: 17px; margin: 0; font-weight: 700; color: #991b1b;">Block Device Access</h3>
                            <p style="font-size: 12px; color: #b91c1c; margin: 2px 0 0 0;">Revoke connection &amp; blacklist</p>
                        </div>
                    </div>
                    <button type="button" onclick="closeBlockDeviceModal()" style="background: transparent; border: none; font-size: 20px; color: #9ca3af; cursor: pointer;" aria-label="Close">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div style="padding: 24px;">
                    <p style="font-size: 14px; color: #374151; margin: 0 0 16px 0; line-height: 1.5;">
                        Are you sure you want to block login access from this device?
                    </p>

                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; font-size: 13px;">
                        <div style="margin-bottom: 6px;">
                            <span style="color: #64748b; font-weight: 600;">Device:</span>
                            <strong id="bdModalDeviceName" style="color: #0f172a; margin-left: 6px;">-</strong>
                        </div>
                        <div style="margin-bottom: 6px;">
                            <span style="color: #64748b; font-weight: 600;">IP Address:</span>
                            <span id="bdModalIp" style="color: #334155; margin-left: 6px;">-</span>
                        </div>
                        <div id="bdModalUserRow" style="display: none;">
                            <span style="color: #64748b; font-weight: 600;">Account:</span>
                            <span id="bdModalUser" style="color: #334155; margin-left: 6px;">-</span>
                        </div>
                    </div>

                    <div style="background: #fff1f2; border-left: 4px solid #f43f5e; padding: 10px 14px; border-radius: 6px; font-size: 12px; color: #9f1239; display: flex; align-items: flex-start; gap: 8px; line-height: 1.4;">
                        <i class="fa-solid fa-circle-exclamation" style="font-size: 14px; margin-top: 1px; flex-shrink: 0;"></i>
                        <span>Any active session on this device will be immediately terminated. You can unblock it later if needed.</span>
                    </div>
                </div>

                <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 10px; background: #fafafa;">
                    <button type="button" class="btn-secondary" onclick="closeBlockDeviceModal()" style="padding: 10px 20px; font-size: 13px; font-weight: 600; border-radius: 8px;">Cancel</button>
                    <button type="submit" style="padding: 10px 22px; font-size: 13px; font-weight: 700; background: #dc2626; color: #ffffff; border: none; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);">
                        <i class="fa-solid fa-ban"></i> Block Device
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal 3: Block All Other Devices (Panic Button) -->
    <div class="modal-backdrop" id="blockAllOtherDevicesModal" style="z-index: 9999;">
        <div class="modal-card" style="max-width: 480px; flex-direction: column; border-radius: 16px; overflow: hidden; box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.4);">
            <form method="POST" action="a_settings.php#devicesSection" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="block_all_other_devices">

                <div style="padding: 20px 24px; border-bottom: 1px solid #fee2e2; display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, #fff5f5 0%, #fef2f2 100%);">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 40px; height: 40px; border-radius: 12px; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 2px 8px rgba(220, 38, 38, 0.15);">
                            <i class="fa-solid fa-shield-virus"></i>
                        </div>
                        <div>
                            <h3 style="font-size: 17px; margin: 0; font-weight: 700; color: #991b1b;">Security Panic Lock</h3>
                            <p style="font-size: 12px; color: #b91c1c; margin: 2px 0 0 0;">Block all other logged-in devices</p>
                        </div>
                    </div>
                    <button type="button" onclick="closeBlockAllOtherDevicesModal()" style="background: transparent; border: none; font-size: 20px; color: #9ca3af; cursor: pointer;" aria-label="Close">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div style="padding: 24px;">
                    <p style="font-size: 14px; color: #374151; margin: 0 0 16px 0; line-height: 1.5;">
                        Are you sure you want to block all other devices currently logged into your account?
                    </p>

                    <div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 6px; font-size: 13px; color: #92400e; margin-bottom: 14px; line-height: 1.4;">
                        <strong><i class="fa-solid fa-info-circle"></i> Security Protection:</strong> Only this current browser will stay logged in. All other sessions on phones, tablets, or other computers will be immediately killed and blocked.
                    </div>
                </div>

                <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 10px; background: #fafafa;">
                    <button type="button" class="btn-secondary" onclick="closeBlockAllOtherDevicesModal()" style="padding: 10px 20px; font-size: 13px; font-weight: 600; border-radius: 8px;">Cancel</button>
                    <button type="submit" style="padding: 10px 22px; font-size: 13px; font-weight: 700; background: #dc2626; color: #ffffff; border: none; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);">
                        <i class="fa-solid fa-ban"></i> Block All Other Devices
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal 4: Delete Admin Account Modal -->
    <div class="modal-backdrop" id="deleteAdminModal" style="z-index: 9999;">
        <div class="modal-card" style="max-width: 480px; flex-direction: column; border-radius: 16px; overflow: hidden; box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.4);">
            <form method="POST" action="a_settings.php#manageAdminsSection" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="delete_admin">
                <input type="hidden" name="target_user_id" id="daTargetUserId" value="">

                <div style="padding: 20px 24px; border-bottom: 1px solid #fee2e2; display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, #fff5f5 0%, #fef2f2 100%);">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 40px; height: 40px; border-radius: 12px; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 2px 8px rgba(220, 38, 38, 0.15);">
                            <i class="fa-solid fa-user-slash"></i>
                        </div>
                        <div>
                            <h3 style="font-size: 17px; margin: 0; font-weight: 700; color: #991b1b;" id="daModalTitle">Delete Administrator</h3>
                            <p style="font-size: 12px; color: #b91c1c; margin: 2px 0 0 0;">Permanent account deletion</p>
                        </div>
                    </div>
                    <button type="button" onclick="closeDeleteAdminModal()" style="background: transparent; border: none; font-size: 20px; color: #9ca3af; cursor: pointer;" aria-label="Close">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div style="padding: 24px;">
                    <p style="font-size: 14px; color: #374151; margin: 0 0 16px 0; line-height: 1.5;" id="daModalDesc">
                        Are you sure you want to permanently delete this administrator account?
                    </p>

                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; font-size: 13px;">
                        <div style="margin-bottom: 6px;">
                            <span style="color: #64748b; font-weight: 600;">Username:</span>
                            <strong id="daModalAdminName" style="color: #0f172a; margin-left: 6px;">-</strong>
                        </div>
                        <div>
                            <span style="color: #64748b; font-weight: 600;">Email:</span>
                            <span id="daModalAdminEmail" style="color: #334155; margin-left: 6px;">-</span>
                        </div>
                    </div>

                    <div style="background: #fff1f2; border-left: 4px solid #f43f5e; padding: 10px 14px; border-radius: 6px; font-size: 12px; color: #9f1239; display: flex; align-items: flex-start; gap: 8px; line-height: 1.4;">
                        <i class="fa-solid fa-triangle-exclamation" style="font-size: 14px; margin-top: 1px; flex-shrink: 0;"></i>
                        <span>This action cannot be undone. The account will immediately lose portal access and their profile will be removed.</span>
                    </div>
                </div>

                <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 10px; background: #fafafa;">
                    <button type="button" class="btn-secondary" onclick="closeDeleteAdminModal()" style="padding: 10px 20px; font-size: 13px; font-weight: 600; border-radius: 8px;">Cancel</button>
                    <button type="submit" style="padding: 10px 22px; font-size: 13px; font-weight: 700; background: #dc2626; color: #ffffff; border: none; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);">
                        <i class="fa-solid fa-trash-can"></i> Yes, Delete Account
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function switchAccountTab(tab) {
            const adminTab = document.getElementById('tabBtnAdmins');
            const userTab = document.getElementById('tabBtnUsers');
            const adminWrap = document.getElementById('tableAdminsWrap');
            const userWrap = document.getElementById('tableUsersWrap');

            if (tab === 'admins') {
                if (adminTab) adminTab.classList.add('active');
                if (userTab) userTab.classList.remove('active');
                if (adminWrap) adminWrap.style.display = 'block';
                if (userWrap) userWrap.style.display = 'none';
            } else {
                if (userTab) userTab.classList.add('active');
                if (adminTab) adminTab.classList.remove('active');
                if (userWrap) userWrap.style.display = 'block';
                if (adminWrap) adminWrap.style.display = 'none';
            }
        }

        function togglePwdVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                if (icon) {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                }
            } else {
                input.type = 'password';
                if (icon) {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            }
        }

        function toggleAdminDevicesDrawer(drawerId, btn) {
            const drawer = document.getElementById(drawerId);
            if (!drawer) return;
            if (drawer.style.display === 'none' || drawer.style.display === '') {
                drawer.style.display = 'table-row';
            } else {
                drawer.style.display = 'none';
            }
        }

        // Modal Handlers: Force Logout
        function openForceLogoutModal(adminId, username) {
            document.getElementById('flTargetAdminId').value = adminId;
            document.getElementById('flModalAdminName').textContent = username || 'Administrator';
            document.getElementById('forceLogoutModal').classList.add('active');
        }
        function closeForceLogoutModal() {
            document.getElementById('forceLogoutModal').classList.remove('active');
        }

        // Modal Handlers: Block Device
        function openBlockDeviceSpecificModal(sessionId, username, deviceName, ip) {
            document.getElementById('blockDeviceForm').action = 'a_settings.php#adminMonitorSection';
            document.getElementById('bdFormAction').value = 'admin_block_device_specific';
            document.getElementById('bdSessionId').value = sessionId;
            document.getElementById('bdModalDeviceName').textContent = deviceName || 'Device';
            document.getElementById('bdModalIp').textContent = ip || '—';
            document.getElementById('bdModalUser').textContent = username || '—';
            document.getElementById('bdModalUserRow').style.display = 'block';
            document.getElementById('blockDeviceModal').classList.add('active');
        }
        function openBlockMyDeviceModal(sessionId, deviceName, ip) {
            document.getElementById('blockDeviceForm').action = 'a_settings.php#devicesSection';
            document.getElementById('bdFormAction').value = 'block_device';
            document.getElementById('bdSessionId').value = sessionId;
            document.getElementById('bdModalDeviceName').textContent = deviceName || 'Device';
            document.getElementById('bdModalIp').textContent = ip || '—';
            document.getElementById('bdModalUserRow').style.display = 'none';
            document.getElementById('blockDeviceModal').classList.add('active');
        }
        function closeBlockDeviceModal() {
            document.getElementById('blockDeviceModal').classList.remove('active');
        }

        // Modal Handlers: Block All Other Devices
        function openBlockAllOtherDevicesModal() {
            document.getElementById('blockAllOtherDevicesModal').classList.add('active');
        }
        function closeBlockAllOtherDevicesModal() {
            document.getElementById('blockAllOtherDevicesModal').classList.remove('active');
        }

        // Modal Handlers: Delete Admin / User
        function openDeleteAdminModal(adminId, username, email, roleLabel) {
            roleLabel = roleLabel || 'Administrator';
            document.getElementById('daTargetUserId').value = adminId;
            document.getElementById('daModalAdminName').textContent = username || '—';
            document.getElementById('daModalAdminEmail').textContent = email || '—';
            const titleEl = document.getElementById('daModalTitle');
            if (titleEl) titleEl.textContent = 'Delete ' + roleLabel;
            const descEl = document.getElementById('daModalDesc');
            if (descEl) descEl.textContent = 'Are you sure you want to permanently delete this ' + roleLabel.toLowerCase() + ' account?';
            document.getElementById('deleteAdminModal').classList.add('active');
        }
        function closeDeleteAdminModal() {
            document.getElementById('deleteAdminModal').classList.remove('active');
        }

        // Global backdrop click and escape handlers
        window.addEventListener('click', function(e) {
            const modals = ['forceLogoutModal', 'blockDeviceModal', 'blockAllOtherDevicesModal', 'deleteAdminModal'];
            modals.forEach(id => {
                const m = document.getElementById(id);
                if (m && e.target === m) {
                    m.classList.remove('active');
                }
            });
        });
        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeForceLogoutModal();
                closeBlockDeviceModal();
                closeBlockAllOtherDevicesModal();
                closeDeleteAdminModal();
            }
        });
    </script>
</body>
</html>
