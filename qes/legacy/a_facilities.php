<?php
session_start();
include('db.php');

// Only admins
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: login.php");
    exit;
}

// Search functionality
$search_query = "";
$search_sql = "";

if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $search_query = $conn->real_escape_string(trim($_GET['search']));
    $search_sql = " AND (name LIKE '%$search_query%' 
                        OR description LIKE '%$search_query%')";
}

// Store error/success messages
$message = "";
$message_type = ""; // success, error, warning

// --------------------------------------
// Handle actions: add, edit, delete
// --------------------------------------
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    $id = (int)($_POST['facility_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $capacity = (int)($_POST['capacity'] ?? 0);

    if ($action === 'add') {
        if (empty($name)) {
            $message = "Facility name cannot be empty!";
            $message_type = "error";
        } elseif ($capacity < 1 || $capacity > 1000) {
            $message = "Capacity must be between 1 and 1000 attendees!";
            $message_type = "error";
        } else {
            // Check if facility name already exists
            $check_stmt = $conn->prepare("SELECT id FROM facilities WHERE name = ?");
            $check_stmt->bind_param("s", $name);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $message = "Error: Facility '$name' already exists!";
                $message_type = "error";
            } else {
                $stmt = $conn->prepare("INSERT INTO facilities (name, description, capacity, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->bind_param("ssi", $name, $desc, $capacity);
                if ($stmt->execute()) {
                    $message = "Facility '$name' added successfully!";
                    $message_type = "success";
                } else {
                    $message = "Error adding facility: " . $conn->error;
                    $message_type = "error";
                }
                $stmt->close();
            }
            $check_stmt->close();
        }
    } elseif ($action === 'edit' && $id > 0) {
        if (empty($name)) {
            $message = "Facility name cannot be empty!";
            $message_type = "error";
        } elseif ($capacity < 1 || $capacity > 1000) {
            $message = "Capacity must be between 1 and 1000 attendees!";
            $message_type = "error";
        } else {
            // Check name collision
            $check_stmt = $conn->prepare("SELECT id FROM facilities WHERE name = ? AND id != ?");
            $check_stmt->bind_param("si", $name, $id);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $message = "Error: Another facility named '$name' already exists!";
                $message_type = "error";
            } else {
                $stmt = $conn->prepare("UPDATE facilities SET name=?, description=?, capacity=? WHERE id=?");
                $stmt->bind_param("ssii", $name, $desc, $capacity, $id);
                if ($stmt->execute()) {
                    $message = "Facility '$name' updated successfully!";
                    $message_type = "success";
                } else {
                    $message = "Error updating facility: " . $conn->error;
                    $message_type = "error";
                }
                $stmt->close();
            }
            $check_stmt->close();
        }
    } elseif ($action === 'delete' && $id > 0) {
        // Check if facility has active upcoming events
        $check_stmt = $conn->prepare("SELECT COUNT(*) as active_count FROM events WHERE facility_id = ? AND end_time >= NOW()");
        $check_stmt->bind_param("i", $id);
        $check_stmt->execute();
        $active_count = $check_stmt->get_result()->fetch_assoc()['active_count'];
        $check_stmt->close();
        
        if ($active_count > 0) {
            $message = "Cannot delete facility because it has $active_count active upcoming event(s). Cancel those events first.";
            $message_type = "error";
        } else {
            // Fetch full facility data to store in trash
            $fetch = $conn->prepare("SELECT * FROM facilities WHERE id = ?");
            $fetch->bind_param("i", $id);
            $fetch->execute();
            $fac_data = $fetch->get_result()->fetch_assoc();
            $fetch->close();

            if ($fac_data) {
                $json = json_encode($fac_data);
                $admin_id = $_SESSION['id'] ?? null;
                $ins = $conn->prepare("INSERT INTO trash (item_type, item_id, item_data, deleted_by, deleted_at) VALUES ('facility', ?, ?, ?, NOW())");
                $ins->bind_param("isi", $id, $json, $admin_id);
                $ins->execute();
                $ins->close();

                $stmt = $conn->prepare("DELETE FROM facilities WHERE id=?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $message = "Facility '{$fac_data['name']}' moved to recycling bin.";
                    $message_type = "success";
                } else {
                    $message = "Error deleting facility: " . $conn->error;
                    $message_type = "error";
                }
                $stmt->close();
            }
        }
    }
    
    $redirect_url = "a_facilities.php";
    if (!empty($search_query)) {
        $redirect_url .= "?search=" . urlencode($search_query);
    }
    
    if (!empty($message)) {
        $_SESSION['facility_message'] = $message;
        $_SESSION['facility_message_type'] = $message_type;
    }
    
    header("Location: $redirect_url");
    exit;
}

if (isset($_SESSION['facility_message'])) {
    $message = $_SESSION['facility_message'];
    $message_type = $_SESSION['facility_message_type'];
    unset($_SESSION['facility_message'], $_SESSION['facility_message_type']);
}

// Fetch facilities to display with search
$result = $conn->query("SELECT * FROM facilities WHERE 1=1 $search_sql ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Facilities - SCHEDFIX</title>
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

        /* Search Bar */
        .admin-search-bar { display: flex; gap: 10px; margin-bottom: 20px; }
        .admin-search-bar input {
            flex: 1; padding: 11px 16px; border: 2px solid #dbeafe; border-radius: 12px;
            font-size: 14px; font-family: 'Outfit', sans-serif; outline: none; transition: border-color 0.2s;
        }
        .admin-search-bar input:focus { border-color: #2b55f6; }
        .admin-search-bar button {
            padding: 11px 22px; background: #2b55f6; color: white; border: none;
            border-radius: 12px; font-size: 14px; font-weight: 600; cursor: pointer;
            font-family: 'Outfit', sans-serif; transition: background 0.2s;
        }
        .admin-search-bar button:hover { background: #1d4ed8; }
        .admin-search-bar a {
            padding: 11px 18px; background: #64748b; color: white; text-decoration: none;
            border-radius: 12px; font-size: 14px; font-weight: 600;
            display: inline-flex; align-items: center; transition: background 0.2s;
        }
        .admin-search-bar a:hover { background: #475569; }

        /* Add Facility Card */
        .add-facility-card {
            background: #f8faff; border: 1px solid #dbeafe;
            border-radius: 20px; padding: 22px 26px; margin-bottom: 28px;
        }
        .add-facility-card h3 {
            font-size: 16px; font-weight: 700; color: #1e3a8a; margin-bottom: 14px;
            display: flex; align-items: center; gap: 8px;
        }
        .add-form-row {
            display: flex; gap: 12px; flex-wrap: wrap; align-items: center;
        }
        .add-form-row input[type="text"] {
            flex: 2; min-width: 200px; padding: 10px 14px;
            border: 2px solid #dbeafe; border-radius: 10px; font-size: 14px;
            font-family: 'Outfit', sans-serif; outline: none; transition: border-color 0.2s;
            background: white;
        }
        .add-form-row input[type="text"]:focus, .add-form-row input[type="number"]:focus {
            border-color: #2b55f6;
        }
        .add-form-row input[type="number"] {
            width: 130px; padding: 10px 14px;
            border: 2px solid #dbeafe; border-radius: 10px; font-size: 14px;
            font-family: 'Outfit', sans-serif; outline: none; transition: border-color 0.2s;
            background: white; text-align: center;
        }
        .add-form-row button {
            padding: 10px 22px; background: #10b981; color: white; border: none;
            border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer;
            font-family: 'Outfit', sans-serif; transition: background 0.2s;
        }
        .add-form-row button:hover { background: #059669; }

        /* Alerts */
        .alert-message {
            padding: 14px 18px; margin-bottom: 20px; border-radius: 12px;
            font-weight: 500; font-size: 14px; display: flex; align-items: center; justify-content: space-between;
        }
        .alert-success { background: #dcfce7; border: 1px solid #bbf7d0; color: #166534; }
        .alert-error { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; }

        /* Table Inputs */
        .tbl-input {
            width: 100%; padding: 8px 10px; border: 1.5px solid #dbeafe; border-radius: 8px;
            font-size: 13.5px; font-family: 'Outfit', sans-serif; background: #ffffff;
            outline: none; transition: border-color 0.2s;
        }
        .tbl-input:focus { border-color: #2b55f6; }
        .tbl-textarea {
            width: 100%; padding: 6px 10px; border: 1.5px solid #dbeafe; border-radius: 8px;
            font-size: 13px; font-family: 'Outfit', sans-serif; resize: vertical; min-height: 40px;
            background: #ffffff; outline: none;
        }
        .tbl-capacity { width: 90px; text-align: center; }

        .btn-tbl-edit {
            padding: 7px 14px; background: #2b55f6; color: white; border: none;
            border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;
            transition: background 0.2s;
        }
        .btn-tbl-edit:hover { background: #1d4ed8; }
        .btn-tbl-del {
            padding: 7px 14px; background: #ef4444; color: white; border: none;
            border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;
            margin-left: 5px; transition: background 0.2s;
        }
        .btn-tbl-del:hover { background: #dc2626; }

        .capacity-pill {
            display: inline-block; padding: 2px 8px; border-radius: 12px;
            background: #e0f2fe; color: #0369a1; font-size: 11px; font-weight: 700; margin-top: 4px;
        }

        @media (max-width: 820px) {
            .home-card-container { padding: 30px 24px; border-radius: 24px; }
            .top-navbar { padding: 20px 16px 12px; }
            .nav-icon-btn { width: 42px; height: 42px; }
            .add-form-row { flex-direction: column; align-items: stretch; }
            .add-form-row input[type="number"] { width: 100%; }
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
                <a href="a_facilities.php" style="color: #60a5fa; font-weight: bold;">🏢 Facilities</a>
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
        <h1 class="page-heading">Facility Management</h1>
        <p class="page-subheading">Create, update, and manage facilities available for events. Deleted facilities are safely moved to the recycling bin.</p>

        <?php if (!empty($message)): ?>
            <div class="alert-message alert-<?php echo $message_type; ?>">
                <span><?php echo htmlspecialchars($message); ?></span>
                <button type="button" onclick="this.parentElement.style.display='none'" style="background:none;border:none;cursor:pointer;font-size:18px;color:inherit;opacity:0.7;">×</button>
            </div>
        <?php endif; ?>

        <!-- Search Bar -->
        <form method="GET" action="a_facilities.php" class="admin-search-bar">
            <input type="text" name="search" placeholder="Search facilities by name or description..." value="<?php echo htmlspecialchars($search_query); ?>">
            <button type="submit">Search</button>
            <?php if (!empty($search_query)): ?>
                <a href="a_facilities.php">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Add Facility Card -->
        <div class="add-facility-card">
            <h3>🏢 Add New Facility</h3>
            <form method="POST" class="add-form-row">
                <input type="hidden" name="action" value="add">
                <input type="text" name="name" placeholder="Facility Name *" required minlength="2" maxlength="100">
                <input type="text" name="description" placeholder="Description (e.g. Building A, 2nd Floor)" maxlength="255">
                <input type="number" name="capacity" placeholder="Capacity *" min="1" max="1000" value="30" required title="Max attendees">
                <button type="submit">+ Add Facility</button>
            </form>
        </div>

        <!-- Facilities Table -->
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 6%;">ID</th>
                        <th style="width: 25%;">Facility Name</th>
                        <th style="width: 32%;">Description</th>
                        <th style="width: 14%;">Capacity</th>
                        <th style="width: 23%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()):
                        // Check active events count
                        $ev_query = $conn->prepare("SELECT COUNT(*) as active_cnt FROM events WHERE facility_id = ? AND end_time >= NOW()");
                        $ev_query->bind_param("i", $row['id']);
                        $ev_query->execute();
                        $active_events = $ev_query->get_result()->fetch_assoc()['active_cnt'];
                        $ev_query->close();
                    ?>
                        <tr>
                            <form method="POST">
                                <td><?= $row['id']; ?></td>
                                <td>
                                    <input type="text" name="name" value="<?= htmlspecialchars($row['name']); ?>" required class="tbl-input">
                                </td>
                                <td>
                                    <textarea name="description" class="tbl-textarea" placeholder="Optional description..."><?= htmlspecialchars($row['description'] ?? ''); ?></textarea>
                                </td>
                                <td>
                                    <input type="number" name="capacity" value="<?= (int)$row['capacity']; ?>" min="1" max="1000" required class="tbl-input tbl-capacity">
                                    <div class="capacity-pill">Max <?= (int)$row['capacity']; ?> pax</div>
                                </td>
                                <td>
                                    <input type="hidden" name="facility_id" value="<?= $row['id']; ?>">
                                    <button type="submit" name="action" value="edit" class="btn-tbl-edit" title="Save edits">Save</button>
                                    <button type="submit" name="action" value="delete" class="btn-tbl-del"
                                            onclick="return confirm('Move facility \'<?= htmlspecialchars(addslashes($row['name'])); ?>\' to recycling bin?<?php echo $active_events > 0 ? " Warning: It has $active_events upcoming event(s)." : ""; ?>')"
                                            title="Move to recycling bin">
                                        Delete
                                    </button>
                                </td>
                            </form>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 30px; color: #94a3b8;">
                            <?php if (!empty($search_query)): ?>
                                No facilities found matching: "<?php echo htmlspecialchars($search_query); ?>"
                            <?php else: ?>
                                No facilities created yet. Use the form above to add your first facility!
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script src="chatbot.js"></script>
</body>
</html>
