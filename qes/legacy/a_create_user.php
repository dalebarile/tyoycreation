<?php
session_start();
include('db.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: login.php");
    exit;
}

$message = "";
$message_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $username = trim($_POST['username']);
    $email    = trim($_POST['email']);
    $raw_pass = $_POST['password'];
    $password = password_hash($raw_pass, PASSWORD_DEFAULT);
    $role     = $_POST['role'];
    $status   = 'approved';

    $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $check->bind_param("ss", $username, $email);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $message = "Error: Username or email already exists!";
        $message_type = "error";
    } else {
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, status, must_change_password, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
        $stmt->bind_param("sssss", $username, $email, $password, $role, $status);
        if ($stmt->execute()) {
            $_SESSION['user_message'] = "User '$username' created successfully! They will be prompted to change their password on first login.";
            $_SESSION['user_message_type'] = "success";

            if ($role === 'admin') {
                require_once __DIR__ . '/../notification_helper.php';
                NotificationHelper::sendAdminCreatedEmail($conn, $email, $username, $username, $raw_pass);
            }

            header("Location: a_user.php");
            exit;
        } else {
            $message = "Error creating user: " . $conn->error;
            $message_type = "error";
        }
        $stmt->close();
    }
    $check->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create User Account - SCHEDFIX</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@800;900&family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Outfit', 'Inter', sans-serif; min-height: 100vh; color: #111; overflow-x: hidden; }

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

        .dashboard-wrapper { width: 100%; max-width: 1260px; margin: 0 auto; padding: 0 20px 40px; }
        .home-card-container {
            background: #ffffff; border-radius: 32px; padding: 40px 45px 45px;
            box-shadow: 0 25px 60px rgba(10,30,85,0.22), 0 8px 20px rgba(0,0,0,0.06);
            width: 100%; min-height: 520px;
        }
        .page-heading { font-family: 'Outfit', sans-serif; font-size: clamp(22px,2.4vw,28px); font-weight: 700; color: #3b5bf6; margin-bottom: 6px; }
        .page-subheading { color: #6b7280; font-size: 14px; margin-bottom: 24px; }

        .create-user-form {
            background: #f8faff; border: 1px solid #dbeafe;
            padding: 32px 36px; border-radius: 20px;
            max-width: 540px; margin: 10px auto;
            box-shadow: 0 8px 24px rgba(43,85,246,0.06);
        }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block; font-weight: 600; font-size: 13.5px;
            color: #1e293b; margin-bottom: 8px;
        }
        .form-group input, .form-group select {
            width: 100%; padding: 11px 14px;
            border: 2px solid #dbeafe; border-radius: 10px;
            font-size: 14px; font-family: 'Outfit', sans-serif;
            background: #ffffff; outline: none; transition: border-color 0.2s;
        }
        .form-group input:focus, .form-group select:focus {
            border-color: #2b55f6;
        }
        .password-wrapper {
            position: relative; display: flex; align-items: center;
        }
        .password-wrapper input {
            padding-right: 46px;
        }
        .toggle-password-btn {
            position: absolute; right: 10px; background: none; border: none;
            cursor: pointer; padding: 6px; color: #64748b; display: inline-flex;
            align-items: center; justify-content: center; border-radius: 6px;
            transition: color 0.2s;
        }
        .toggle-password-btn:hover { color: #2b55f6; }
        .toggle-password-btn svg { width: 20px; height: 20px; stroke: currentColor; fill: none; stroke-width: 2; }

        .btn-row { display: flex; gap: 12px; margin-top: 26px; }
        .btn-primary {
            flex: 1; padding: 12px; background: #2b55f6; color: white;
            border: none; border-radius: 12px; font-size: 15px; font-weight: 600;
            cursor: pointer; font-family: 'Outfit', sans-serif; transition: background 0.2s;
        }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-secondary {
            flex: 1; padding: 12px; background: #64748b; color: white;
            border: none; border-radius: 12px; font-size: 15px; font-weight: 600;
            cursor: pointer; text-align: center; text-decoration: none;
            display: inline-flex; align-items: center; justify-content: center;
            font-family: 'Outfit', sans-serif; transition: background 0.2s;
        }
        .btn-secondary:hover { background: #475569; color: white; }

        .alert-message {
            padding: 14px 18px; margin-bottom: 20px; border-radius: 12px;
            font-weight: 500; font-size: 14px;
        }
        .alert-error { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; }
        .info-note {
            background: #eff6ff; border-left: 4px solid #3b5bf6;
            padding: 12px 16px; border-radius: 0 10px 10px 0;
            font-size: 13.5px; color: #1e40af; margin-bottom: 24px;
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
        <a href="a_user.php" class="nav-icon-btn active" title="Manage Users"><svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg></a>
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
        <h1 class="page-heading">Create User Account</h1>
        <p class="page-subheading">Fill in the details below to add a new account. The user will be prompted to set a new password on their first login.</p>

        <div class="create-user-form">
            <?php if (!empty($message)): ?>
                <div class="alert-message alert-<?php echo $message_type; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="info-note">
                🔒 A temporary password will be set. Click the eye icon to view or verify what you type.
            </div>

            <form method="POST" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="create">

                <div class="form-group">
                    <label>Username *</label>
                    <input type="text" name="username" placeholder="Enter username (3-50 chars)"
                           required minlength="3" maxlength="50"
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label>Email Address *</label>
                    <input type="email" name="email" placeholder="Enter email address"
                           required
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label>Temporary Password *</label>
                    <div class="password-wrapper">
                        <input type="password" name="password" placeholder="Set a temporary password (min 6 chars)"
                               required minlength="6" id="passwordInput">
                        <button type="button" class="toggle-password-btn" onclick="togglePasswordVisibility('passwordInput', this)" title="Show / Hide Password">
                            <svg class="eye-open" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Role *</label>
                    <select name="role" required>
                        <option value="user" <?php echo (($_POST['role'] ?? '') === 'user') ? 'selected' : ''; ?>>User</option>
                        <option value="admin" <?php echo (($_POST['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>Admin</option>
                    </select>
                </div>

                <div class="btn-row">
                    <button type="submit" class="btn-primary">✅ Create Account</button>
                    <a href="a_user.php" class="btn-secondary">← Cancel</a>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
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

function validateForm() {
    const pw = document.getElementById('passwordInput').value;
    if (pw.length < 6) {
        alert('Password must be at least 6 characters.');
        return false;
    }
    return true;
}
</script>
<script src="chatbot.js"></script>
</body>
</html>
