<?php
session_start(); // Start session to access user data
include('db.php'); // Database connection

// --------------------------------------
// Access Control: Only admins allowed
// --------------------------------------
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: login.php");
    exit;
}

// Search functionality
$search_query = "";
$search_sql = "";

if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $search_query = $conn->real_escape_string(trim($_GET['search']));
    $search_sql = " AND (username LIKE '%$search_query%' 
                        OR email LIKE '%$search_query%' 
                        OR role LIKE '%$search_query%')";
}

// Store messages
$message = "";
$message_type = "";

// --------------------------------------
// Handle form actions (Approve / Reject / Delete)
// --------------------------------------
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    $user_id = (int)($_POST['user_id'] ?? 0);

    if ($action === 'approve' && $user_id) {
        $stmt = $conn->prepare("UPDATE users SET status='approved' WHERE id=?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $_SESSION['pending_message'] = "User approved successfully!";
            $_SESSION['pending_message_type'] = "success";
        } else {
            $_SESSION['pending_message'] = "Error approving user: " . $conn->error;
            $_SESSION['pending_message_type'] = "error";
        }
        $stmt->close();
    } elseif ($action === 'delete' && $user_id) {
        // Soft delete: move to trash
        $fetch = $conn->prepare("SELECT * FROM users WHERE id=?");
        $fetch->bind_param("i", $user_id);
        $fetch->execute();
        $user_data = $fetch->get_result()->fetch_assoc();
        $fetch->close();

        if ($user_data) {
            $json = json_encode($user_data);
            $trash_stmt = $conn->prepare("INSERT INTO trash (item_type, item_id, item_data, deleted_at) VALUES ('user', ?, ?, NOW())");
            $trash_stmt->bind_param("is", $user_id, $json);
            $trash_stmt->execute();
            $trash_stmt->close();

            $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
            $stmt->bind_param("i", $user_id);
            if ($stmt->execute()) {
                $_SESSION['pending_message'] = "User \"{$user_data['username']}\" rejected and moved to recycling bin.";
                $_SESSION['pending_message_type'] = "success";
            } else {
                $_SESSION['pending_message'] = "Error rejecting user: " . $conn->error;
                $_SESSION['pending_message_type'] = "error";
            }
            $stmt->close();
        }
    } 
    
    // Redirect to clear POST data
    header("Location: a_pending.php" . (!empty($search_query) ? "?search=" . urlencode($search_query) : ""));
    exit;
}

if (isset($_SESSION['pending_message'])) {
    $message = $_SESSION['pending_message'];
    $message_type = $_SESSION['pending_message_type'];
    unset($_SESSION['pending_message'], $_SESSION['pending_message_type']);
}

// --------------------------------------
// Fetch all users to display in table
// --------------------------------------
$result = $conn->query("SELECT * FROM users WHERE status = 'pending' $search_sql ORDER BY created_at DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Users - SCHEDFIX</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@800;900&family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Outfit', 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            min-height: 100vh; color: #111; overflow-x: hidden;
        }
        .top-navbar {
            width: 100%; max-width: 1260px; margin: 0 auto;
            padding: 24px 20px 16px;
            display: flex; align-items: center; justify-content: space-between;
            position: relative; z-index: 100;
        }
        .brand-logo-container { display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .logo-text {
            font-family: 'Montserrat', sans-serif; font-size: clamp(28px, 3.2vw, 42px);
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
        .dashboard-wrapper { width: 100%; max-width: 1260px; margin: 0 auto; padding: 0 20px 40px; }
        .home-card-container {
            background: #ffffff; border-radius: 32px; padding: 40px 45px 45px;
            box-shadow: 0 25px 60px rgba(10,30,85,0.22), 0 8px 20px rgba(0,0,0,0.06);
            width: 100%; min-height: 520px;
        }
        .page-heading {
            font-family: 'Outfit', sans-serif; font-size: clamp(22px,2.4vw,28px);
            font-weight: 700; color: #3b5bf6; margin-bottom: 6px;
        }
        .page-subheading { color: #6b7280; font-size: 14px; margin-bottom: 24px; }
        .page-subheading a { color: #2b55f6; font-weight: 600; text-decoration: none; }
        .page-subheading a:hover { text-decoration: underline; }
        .admin-search-bar { display: flex; gap: 10px; margin-bottom: 16px; }
        .admin-search-bar input {
            flex: 1; padding: 10px 16px; border: 2px solid #dbeafe; border-radius: 12px;
            font-size: 14px; font-family: 'Outfit', sans-serif; outline: none; transition: border-color 0.2s;
        }
        .admin-search-bar input:focus { border-color: #2b55f6; }
        .admin-search-bar button {
            padding: 10px 20px; background: #2b55f6; color: white; border: none;
            border-radius: 12px; font-size: 14px; font-weight: 600; cursor: pointer;
            font-family: 'Outfit', sans-serif; transition: background 0.2s;
        }
        .admin-search-bar button:hover { background: #1d4ed8; }
        .admin-search-bar a {
            padding: 10px 16px; background: #6b7280; color: white; text-decoration: none;
            border-radius: 12px; font-size: 14px; font-weight: 600;
            display: inline-flex; align-items: center; transition: background 0.2s;
        }
        .admin-search-bar a:hover { background: #4b5563; }
        .search-results-info { margin-bottom: 12px; font-size: 13px; color: #4b5563; font-style: italic; }
        .form-inline { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; align-items: center; }
        .form-inline input, .form-inline select {
            padding: 10px 14px; border: 2px solid #dbeafe; border-radius: 10px;
            font-size: 14px; font-family: 'Outfit', sans-serif; flex: 1; min-width: 140px; outline: none;
        }
        .form-inline input:focus, .form-inline select:focus { border-color: #2b55f6; }
        .form-inline button {
            padding: 10px 20px; background: #22c55e; color: white; border: none;
            border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer;
            font-family: 'Outfit', sans-serif; transition: background 0.2s;
        }
        .form-inline button:hover { background: #16a34a; }
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
        <a href="a_home.php" class="nav-icon-btn" title="Dashboard">
            <svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
        </a>
        <a href="a_events.php" class="nav-icon-btn" title="Manage Events">
            <svg viewBox="0 0 24 24"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
        </a>
        <a href="a_pending.php" class="nav-icon-btn active" title="Pending Users">
            <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
        </a>
        <a href="a_user.php" class="nav-icon-btn" title="Manage Users">
            <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
        </a>
        <div class="nav-dropdown-wrapper">
            <button class="nav-icon-btn" id="adminMoreBtn" title="More" onclick="toggleAdminDropdown()" style="border:none;cursor:pointer;">
                <svg viewBox="0 0 24 24"><path d="M6 10c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm12 0c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm-6 0c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
            </button>
            <div class="nav-dropdown-menu" id="adminDropdown">
                <a href="a_facilities.php">🏢 Facilities</a>
                <a href="a_calendar.php">📅 Calendar</a>
                <a href="a_trash.php">🗑 Trash</a>
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
        <h1 class="page-heading">Pending Users</h1>
        <p class="page-subheading">Approve and reject pending users. <a href="a_user.php">View Approved Users</a></p>
    

        <!-- Search Bar -->
        <form method="GET" action="a_pending.php" class="admin-search-bar">
            <input type="text" name="search"
                placeholder="Search pending users by username, email, or role..."
                value="<?php echo htmlspecialchars($search_query); ?>">
            <button type="submit">Search</button>
            <?php if (!empty($search_query)): ?>
                <a href="a_pending.php">Clear</a>
            <?php endif; ?>
        </form>

        <?php if (!empty($search_query)): ?>
            <div class="search-results-info">Results for: "<strong><?php echo htmlspecialchars($search_query); ?></strong>"</div>
        <?php endif; ?>

        <?php if (!empty($message)): ?>
            <div class="alert-message alert-<?php echo $message_type; ?>" id="alertMessage" style="padding: 14px 18px; margin-bottom: 20px; border-radius: 12px; font-weight: 500; display: flex; align-items: center; justify-content: space-between; font-size: 14px; background: <?php echo $message_type === 'success' ? '#dcfce7' : '#fee2e2'; ?>; color: <?php echo $message_type === 'success' ? '#166534' : '#991b1b'; ?>;">
                <span><?php echo htmlspecialchars($message); ?></span>
                <button type="button" onclick="this.parentElement.style.display='none'" style="background:none;border:none;font-size:18px;cursor:pointer;color:inherit;opacity:0.7;">×</button>
            </div>
        <?php endif; ?>

    <!-- Users Table -->
    <div class="table-responsive">
        <table class="data-table">
            <tr>
                <th>ID</th><th>Username</th><th>Email</th>
                <th>Role</th><th>Status</th><th>Created</th><th>Actions</th>
            </tr>
            <?php if ($result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()) { ?>
                    <tr>
                        <form method="POST">
                            <td><?= $row['id']; ?></td>
                            <td><?= htmlspecialchars($row['username']); ?></td>
                            <td><?= htmlspecialchars($row['email']); ?></td>
                            <td><?= htmlspecialchars($row['role']); ?></td>
                            <td><?= ucfirst($row['status']); ?></td>
                            <td><?= $row['created_at']; ?></td>
                            <td>
                                <input type="hidden" name="user_id" value="<?= $row['id']; ?>">
                                <button name="action" value="approve">Approve</button>
                                <button name="action" value="delete" onclick="return confirm('Delete this user?')">Reject</button>
                            </td>
                        </form>
                    </tr>
                <?php } ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 20px;">
                        <?php if (!empty($search_query)): ?>
                            No pending users found matching your search: "<?php echo htmlspecialchars($search_query); ?>"
                        <?php else: ?>
                            No pending users found.
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
        </table>
    </div>
    </div>
</main>
<script src="chatbot.js"></script>
</body>
</html>