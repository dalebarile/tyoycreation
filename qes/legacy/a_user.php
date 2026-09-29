<?php
session_start(); // Start session to identify logged-in admin
include('db.php'); // Database connection

//This will allow the admin to only access this page
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
// Handle form actions (Approve / Reject / Delete / Edit / Create)
// --------------------------------------
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    $user_id = $_POST['user_id'] ?? null;

    if ($action === 'approve' && $user_id) {
        $stmt = $conn->prepare("UPDATE users SET status='approved' WHERE id=?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $message = "User approved successfully!";
            $message_type = "success";
        } else {
            $message = "Error approving user: " . $conn->error;
            $message_type = "error";
        }
        $stmt->close();
    } elseif ($action === 'delete' && $user_id) {
        // Soft delete: always move to trash (no restriction on events)
        $user_fetch = $conn->prepare("SELECT * FROM users WHERE id=?");
        $user_fetch->bind_param("i", $user_id);
        $user_fetch->execute();
        $user_data_row = $user_fetch->get_result()->fetch_assoc();
        $user_fetch->close();

        if ($user_data_row) {
            $item_data_json = json_encode($user_data_row);
            $trash_stmt = $conn->prepare("INSERT INTO trash (item_type, item_id, item_data, deleted_at) VALUES ('user', ?, ?, NOW())");
            $trash_stmt->bind_param("is", $user_id, $item_data_json);
            $trash_stmt->execute();
            $trash_stmt->close();

            $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
            $stmt->bind_param("i", $user_id);
            if ($stmt->execute()) {
                $message = "User moved to trash successfully.";
                $message_type = "success";
            } else {
                // If delete failed, remove the trash entry we just added
                $rollback = $conn->prepare("DELETE FROM trash WHERE item_type='user' AND item_id=? ORDER BY deleted_at DESC LIMIT 1");
                $rollback->bind_param("i", $user_id);
                $rollback->execute();
                $rollback->close();
                $message = "Error deleting user: " . $conn->error;
                $message_type = "error";
            }
            $stmt->close();
        } else {
            $message = "User not found.";
            $message_type = "error";
        }
    } elseif ($action === 'create') {
        // Create new user manually
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $role = $_POST['role'];
        $status = 'approved';
        
        // Check if username already exists
        $check_user = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $check_user->bind_param("ss", $username, $email);
        $check_user->execute();
        $check_user->store_result();
        
        if ($check_user->num_rows > 0) {
            $message = "Error: Username or email already exists!";
            $message_type = "error";
        } else {
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, status, created_at)
                                    VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("sssss", $username, $email, $password, $role, $status);
            if ($stmt->execute()) {
                $message = "User '$username' created successfully!";
                $message_type = "success";
            } else {
                $message = "Error creating user: " . $conn->error;
                $message_type = "error";
            }
            $stmt->close();
        }
        $check_user->close();
    } elseif ($action === 'edit' && $user_id) {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        
        // Check if username/email already exists (excluding current user)
        $check_user = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
        $check_user->bind_param("ssi", $username, $email, $user_id);
        $check_user->execute();
        $check_user->store_result();
        
        if ($check_user->num_rows > 0) {
            $message = "Error: Username or email already exists!";
            $message_type = "error";
        } else {
            $stmt = $conn->prepare("UPDATE users SET username=?, email=?, role=? WHERE id=?");
            $stmt->bind_param("sssi", $username, $email, $role, $user_id);
            if ($stmt->execute()) {
                $message = "User updated successfully!";
                $message_type = "success";
            } else {
                $message = "Error updating user: " . $conn->error;
                $message_type = "error";
            }
            $stmt->close();
        }
        $check_user->close();
    }
    
    // Store message in session for redirect
    if (!empty($message)) {
        $_SESSION['user_message'] = $message;
        $_SESSION['user_message_type'] = $message_type;
    }
    
    // Redirect to clear POST data
    header("Location: a_user.php" . (!empty($search_query) ? "?search=" . urlencode($search_query) : ""));
    exit;
}

// Check for stored messages
if (isset($_SESSION['user_message'])) {
    $message = $_SESSION['user_message'];
    $message_type = $_SESSION['user_message_type'];
    unset($_SESSION['user_message']);
    unset($_SESSION['user_message_type']);
}

// --------------------------------------
// Fetch all users to display in table
// --------------------------------------
$result = $conn->query("SELECT * FROM users WHERE status ='approved' $search_sql ORDER BY created_at DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - SCHEDFIX</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@800;900&family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Outfit', 'Inter', sans-serif; min-height: 100vh; color: #111; overflow-x: hidden; }
        .top-navbar { width: 100%; max-width: 1260px; margin: 0 auto; padding: 24px 20px 16px; display: flex; align-items: center; justify-content: space-between; position: relative; z-index: 100; }
        .brand-logo-container { display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .logo-text { font-family: 'Montserrat', sans-serif; font-size: clamp(28px,3.2vw,42px); font-weight: 900; color: #ffffff; letter-spacing: 2px; text-transform: uppercase; line-height: 1; text-shadow: 0 4px 15px rgba(0,0,0,0.15); }
        .calendar-icon-box { display: inline-flex; align-items: center; justify-content: center; background: #0d1a33; border-radius: 10px; padding: 6px 7px; box-shadow: 0 4px 12px rgba(0,0,0,0.25); transition: transform 0.2s ease; }
        .calendar-icon-box:hover { transform: scale(1.05); }
        .calendar-icon-box svg { width: clamp(24px,2.8vw,34px); height: clamp(24px,2.8vw,34px); display: block; }
        .icon-nav-group { display: flex; align-items: center; gap: 12px; }
        .nav-icon-btn { width: 48px; height: 48px; border-radius: 14px; background: #0d2253; color: #ffffff; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; transition: all 0.25s cubic-bezier(0.16,1,0.3,1); box-shadow: 0 4px 12px rgba(0,0,0,0.15); border: 1px solid rgba(255,255,255,0.1); position: relative; }
        .nav-icon-btn svg { width: 22px; height: 22px; fill: currentColor; stroke: currentColor; stroke-width: 0.5; }
        .nav-icon-btn.active { background: #00a8ff; box-shadow: 0 4px 16px rgba(0,168,255,0.4); border-color: rgba(255,255,255,0.3); transform: scale(1.04); }
        .nav-icon-btn:hover:not(.active) { background: #143075; transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.25); }
        .nav-dropdown-wrapper { position: relative; }
        .nav-dropdown-menu { display: none; position: absolute; top: calc(100% + 10px); right: 0; background: #0d2253; border: 1px solid rgba(255,255,255,0.15); border-radius: 14px; box-shadow: 0 8px 28px rgba(0,0,0,0.3); z-index: 1000; min-width: 170px; overflow: hidden; }
        .nav-dropdown-menu a { display: block; padding: 12px 20px; color: white; text-decoration: none; font-size: 13px; font-weight: 600; border-bottom: 1px solid rgba(255,255,255,0.08); transition: background 0.2s; font-family: 'Outfit', sans-serif; }
        .nav-dropdown-menu a:last-child { border-bottom: none; }
        .nav-dropdown-menu a:hover { background: rgba(255,255,255,0.12); }
        .dashboard-wrapper { width: 100%; max-width: 1260px; margin: 0 auto; padding: 0 20px 40px; }
        .home-card-container { background: #ffffff; border-radius: 32px; padding: 40px 45px 45px; box-shadow: 0 25px 60px rgba(10,30,85,0.22), 0 8px 20px rgba(0,0,0,0.06); width: 100%; min-height: 520px; }
        .page-heading { font-family: 'Outfit', sans-serif; font-size: clamp(22px,2.4vw,28px); font-weight: 700; color: #3b5bf6; margin-bottom: 6px; }
        .page-subheading { color: #6b7280; font-size: 14px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .page-subheading a.btn-create { background: #2b55f6; color: white; padding: 6px 16px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 13px; transition: background 0.2s; }
        .page-subheading a.btn-create:hover { background: #1d4ed8; }
        .admin-search-bar { display: flex; gap: 10px; margin-bottom: 16px; }
        .admin-search-bar input { flex: 1; padding: 10px 16px; border: 2px solid #dbeafe; border-radius: 12px; font-size: 14px; font-family: 'Outfit', sans-serif; outline: none; transition: border-color 0.2s; }
        .admin-search-bar input:focus { border-color: #2b55f6; }
        .admin-search-bar button { padding: 10px 20px; background: #2b55f6; color: white; border: none; border-radius: 12px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: 'Outfit', sans-serif; transition: background 0.2s; }
        .admin-search-bar button:hover { background: #1d4ed8; }
        .admin-search-bar a { padding: 10px 16px; background: #6b7280; color: white; text-decoration: none; border-radius: 12px; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; transition: background 0.2s; }
        .admin-search-bar a:hover { background: #4b5563; }
        .search-results-info { margin-bottom: 12px; font-size: 13px; color: #4b5563; font-style: italic; }
        .alert-message { padding: 14px 18px; margin-bottom: 20px; border-radius: 12px; font-weight: 500; display: flex; align-items: center; justify-content: space-between; animation: fadeIn 0.3s ease; font-size: 14px; }
        .alert-success { background: #dcfce7; border: 1px solid #bbf7d0; color: #166534; }
        .alert-error { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; }
        .alert-warning { background: #fef9c3; border: 1px solid #fde047; color: #854d0e; }
        .close-alert { background: none; border: none; font-size: 18px; cursor: pointer; color: inherit; opacity: 0.7; }
        .close-alert:hover { opacity: 1; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
        @media (max-width: 820px) { .home-card-container { padding: 30px 24px; border-radius: 24px; } .top-navbar { padding: 20px 16px 12px; } .nav-icon-btn { width: 42px; height: 42px; } }
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
        <a href="a_user.php" class="nav-icon-btn active" title="Manage Users"><svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg></a>
        <div class="nav-dropdown-wrapper">
            <button class="nav-icon-btn" id="adminMoreBtn" title="More" onclick="toggleAdminDropdown()" style="border:none;cursor:pointer;"><svg viewBox="0 0 24 24"><path d="M6 10c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm12 0c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm-6 0c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg></button>
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

    <h1 class="page-heading">Users Management</h1>
    <p class="page-subheading">Edit or delete users. <a href="a_create_user.php" class="btn-create">+ Create New User</a></p>
    
    <!-- Display Messages -->
    <?php if (!empty($message)): ?>
        <div class="alert-message alert-<?php echo $message_type; ?>" id="alertMessage">
            <span><?php echo htmlspecialchars($message); ?></span>
            <button class="close-alert" onclick="document.getElementById('alertMessage').style.display='none'">×</button>
        </div>
    <?php endif; ?>
    
    <!-- Search Bar -->
    <form method="GET" action="a_user.php" class="admin-search-bar">
        <input type="text" name="search"
            placeholder="Search users by username, email, or role..."
            value="<?php echo htmlspecialchars($search_query); ?>">
        <button type="submit">Search</button>
        <?php if (!empty($search_query)): ?>
            <a href="a_user.php">Clear</a>
        <?php endif; ?>
    </form>
    
    <?php if (!empty($search_query)): ?>
        <div class="search-results-info">
            Search results for: "<strong><?php echo htmlspecialchars($search_query); ?></strong>"
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
                    <form method="POST" onsubmit="return validateUserForm(this)">
                        <td><?= $row['id']; ?></td>
                        <td>
                            <input type="text" name="username" value="<?= htmlspecialchars($row['username']); ?>"
                                   required pattern=".{3,50}" title="Username must be between 3 and 50 characters">
                        </td>
                        <td><input type="email" name="email" value="<?= htmlspecialchars($row['email']); ?>" required></td>
                        <td>
                            <select name="role">
                                <option <?= $row['role']=='admin'?'selected':''; ?> value="admin">admin</option>
                                <option <?= $row['role']=='user'?'selected':''; ?> value="user">user</option>
                            </select>
                        </td>
                        <td><?= ucfirst($row['status']); ?></td>
                        <td><?= $row['created_at']; ?></td>
                        <td>
                            <input type="hidden" name="user_id" value="<?= $row['id']; ?>">
                            <button name="action" value="edit">Save</button>
                            <button name="action" value="delete" 
                                    onclick="return confirmDeleteUser('<?= htmlspecialchars(addslashes($row['username'])); ?>')">
                                Delete
                            </button>
                        </td>
                    </form>
                </tr>
                <?php } ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 20px;">
                        <?php if (!empty($search_query)): ?>
                            No users found matching your search: "<?php echo htmlspecialchars($search_query); ?>"
                        <?php else: ?>
                            No approved users found.
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
        </table>
    </div>
</div>

<script>
// Auto-hide alert message after 5 seconds
setTimeout(function() {
    const alert = document.getElementById('alertMessage');
    if (alert) {
        alert.style.opacity = '0';
        setTimeout(function() {
            alert.style.display = 'none';
        }, 300);
    }
}, 5000);

// Form validation
function validateUserForm(form) {
    const usernameInput = form.querySelector('input[name="username"]');
    const emailInput = form.querySelector('input[name="email"]');
    
    if (usernameInput.value.trim().length < 3) {
        alert('Username must be at least 3 characters long.');
        usernameInput.focus();
        return false;
    }
    
    if (usernameInput.value.trim().length > 50) {
        alert('Username cannot exceed 50 characters.');
        usernameInput.focus();
        return false;
    }
    
    // Basic email validation
    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailPattern.test(emailInput.value)) {
        alert('Please enter a valid email address.');
        emailInput.focus();
        return false;
    }
    
    return true;
}

// Enhanced delete confirmation
function confirmDeleteUser(username) {
    return confirm(`Are you sure you want to delete user "${username}"?\n\nThe account will be moved to the trash bin.`);
}

// Prevent duplicate form submission
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = 'Processing...';
            }
        });
    });
});
</script>

</div>
</main>
<script src="chatbot.js"></script>
</body>
</html>