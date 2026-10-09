<?php 
require_once __DIR__ . '/db.php';

// If already logged in, redirect accordingly
if (isset($_SESSION['id']) && !empty($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super_admin') {
        header("Location: a_home.php");
        exit;
    } else {
        header("Location: index.php");
        exit;
    }
}

$register_error = '';
$register_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }

    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = preg_replace('/[^0-9]/', '', trim($_POST['phone'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($full_name) || empty($username) || empty($email) || empty($password)) {
        $register_error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $register_error = "Please enter a valid email address.";
    } elseif (strlen($password) < 6) {
        $register_error = "Password must be at least 6 characters long.";
    } elseif ($password !== $confirm_password) {
        $register_error = "Passwords do not match.";
    } else {
        // Check uniqueness
        $check = $conn->prepare("SELECT id, username, email FROM users WHERE username = ? OR email = ? LIMIT 1");
        $check->bind_param("ss", $username, $email);
        $check->execute();
        $check_res = $check->get_result();

        if ($check_res->num_rows > 0) {
            $existing = $check_res->fetch_assoc();
            if (strcasecmp($existing['email'], $email) === 0) {
                $register_error = "This email is already registered. Please log in.";
            } else {
                $register_error = "This username is already taken. Please choose another.";
            }
            $check->close();
        } else {
            $check->close();
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $role = 'user';
            $status = 'approved';

            $insert = $conn->prepare("INSERT INTO users (username, full_name, email, phone, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            if ($insert) {
                $insert->bind_param("sssssss", $username, $full_name, $email, $phone, $hashed, $role, $status);
                if ($insert->execute()) {
                    $new_id = (int)$insert->insert_id;
                    $insert->close();

                    // Auto-login registered user
                    session_regenerate_id(true);
                    $_SESSION['id'] = $new_id;
                    $_SESSION['username'] = $username;
                    $_SESSION['role'] = 'user';
                    $_SESSION['full_name'] = $full_name;
                    $_SESSION['email'] = $email;
                    $_SESSION['phone'] = $phone;

                    $redirect = $_GET['redirect'] ?? 'index.php?registered=1';
                    // Security: only allow relative redirects (block absolute URLs)
                    if (preg_match('#^https?://|^//#i', $redirect) || str_contains($redirect, '://')) {
                        $redirect = 'index.php?registered=1';
                    }
                    header("Location: " . $redirect);
                    exit;
                } else {
                    error_log('[QES Register] Insert execute error: ' . $insert->error);
                    $register_error = "Registration failed due to a system error. Please try again shortly.";
                }
            } else {
                error_log('[QES Register] DB prepare error: ' . $conn->error);
                $register_error = "Registration is temporarily unavailable. Please try again shortly.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tyoy Creation - Register Account</title>
    <link rel="icon" type="image/png" href="assets/favicon.png?v=<?= filemtime(__DIR__ . '/assets/favicon.png') ?>">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body {
            background: radial-gradient(circle at 15% 15%, #232f22 0%, #172117 50%, #0c120c 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 20px;
            position: relative;
            overflow-x: hidden;
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .bg-glow-1 {
            position: absolute;
            top: -120px;
            left: -120px;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(54, 71, 53, 0.45) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .bg-glow-2 {
            position: absolute;
            bottom: -140px;
            right: -120px;
            width: 550px;
            height: 550px;
            background: radial-gradient(circle, rgba(54, 71, 53, 0.35) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .register-card {
            background: #ffffff;
            border-radius: 20px;
            width: 100%;
            max-width: 480px;
            padding: 40px 36px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            position: relative;
            z-index: 10;
            text-align: center;
            animation: slideUp 0.35s ease;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .register-logo {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            margin-bottom: 6px;
        }

        .register-title {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 24px;
        }

        .register-field {
            text-align: left;
            margin-bottom: 16px;
            position: relative;
        }

        .register-field label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 6px;
        }

        .register-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        @media (max-width: 480px) {
            .register-row {
                grid-template-columns: 1fr;
            }
        }

        .input-group {
            position: relative;
        }

        .input-group input {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            font-size: 14px;
            outline: none;
            transition: var(--transition);
            box-sizing: border-box;
        }

        .input-group input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        .toggle-password-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 14px;
        }

        .btn-register {
            width: 100%;
            padding: 13px;
            background: var(--primary);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 14px var(--primary-glow);
            transition: var(--transition);
            margin-top: 10px;
        }

        .btn-register:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        .alert-error {
            background: #fee2e2;
            color: #dc2626;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 18px;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .login-switch-text {
            margin-top: 20px;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .login-switch-text a {
            color: var(--primary);
            font-weight: 700;
            text-decoration: none;
        }

        .login-switch-text a:hover {
            text-decoration: underline;
        }

        .back-to-site {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 16px;
            font-size: 13px;
            color: var(--text-secondary);
            font-weight: 500;
            text-decoration: none;
        }

        .back-to-site:hover {
            color: var(--primary);
        }
    </style>
</head>
<body>

    <div class="bg-glow-1"></div>
    <div class="bg-glow-2"></div>

    <div class="register-card">
        <div class="register-logo">
            <img src="assets/tyoy_logo_cropped.png?v=<?= filemtime(__DIR__ . '/assets/tyoy_logo_cropped.png') ?>" alt="Tyoy Creation" style="height: 52px; width: auto; border-radius: 8px;">
        </div>
        <div style="font-weight: 700; font-size: 16px; color: var(--primary); margin: 8px 0 2px 0;">Create Member Account</div>
        <p class="register-title">Register to book events &amp; customize packages</p>

        <?php if (!empty($register_error)): ?>
            <div class="alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span><?= htmlspecialchars($register_error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php<?= !empty($_GET['redirect']) ? '?redirect=' . urlencode($_GET['redirect']) : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <div class="register-field">
                <label>Full Name <span style="color: #dc2626;">*</span></label>
                <div class="input-group">
                    <input type="text" name="full_name" placeholder="e.g. Dave Anthony Barile" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required autocomplete="name">
                </div>
            </div>

            <div class="register-row">
                <div class="register-field">
                    <label>Username <span style="color: #dc2626;">*</span></label>
                    <div class="input-group">
                        <input type="text" name="username" placeholder="e.g. davebarile" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autocomplete="username">
                    </div>
                </div>

                <div class="register-field">
                    <label>Phone Number</label>
                    <div class="input-group">
                        <input type="tel" name="phone" placeholder="09XXXXXXXXX (11 digits)" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" autocomplete="tel" inputmode="numeric" pattern="[0-9]*" maxlength="11" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);" onkeypress="if (!/[0-9]/.test(event.key)) event.preventDefault();">
                    </div>
                </div>
            </div>

            <div class="register-field">
                <label>Email Address <span style="color: #dc2626;">*</span></label>
                <div class="input-group">
                    <input type="email" name="email" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autocomplete="email">
                </div>
            </div>

            <div class="register-row">
                <div class="register-field">
                    <label>Password <span style="color: #dc2626;">*</span></label>
                    <div class="input-group">
                        <input type="password" id="regPassword" name="password" placeholder="At least 6 chars" required autocomplete="new-password">
                        <button type="button" class="toggle-password-btn" onclick="togglePass('regPassword', 'eye1')">
                            <i class="fa-solid fa-eye" id="eye1"></i>
                        </button>
                    </div>
                </div>

                <div class="register-field">
                    <label>Confirm Password <span style="color: #dc2626;">*</span></label>
                    <div class="input-group">
                        <input type="password" id="regConfirmPassword" name="confirm_password" placeholder="Repeat password" required autocomplete="new-password">
                        <button type="button" class="toggle-password-btn" onclick="togglePass('regConfirmPassword', 'eye2')">
                            <i class="fa-solid fa-eye" id="eye2"></i>
                        </button>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-register">Register &amp; Continue</button>
        </form>

        <div class="social-login-divider">
            <span>or continue with</span>
        </div>

        <button type="button" class="btn-google-connect" onclick="openGoogleAuthModal()" title="Sign up with Google">
            <svg width="20" height="20" viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
            </svg>
            <span>Continue with Google</span>
        </button>

        <div class="login-switch-text">
            Already have an account? <a href="loginadmin.php<?= !empty($_GET['redirect']) ? '?redirect=' . urlencode($_GET['redirect']) : '' ?>">Sign In here</a>
        </div>

        <a href="index.php" class="back-to-site">
            <i class="fa-solid fa-arrow-left"></i> Back to Public Website
        </a>

        <div style="margin-top: 24px; font-size: 12px; color: var(--text-muted);">
            &copy; <?= date('Y') ?> Tyoy Creation. All rights reserved.
        </div>
    </div>

    <!-- Google Auth Connect Modal -->
    <div id="googleAuthModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(12, 18, 12, 0.78); backdrop-filter: blur(8px); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
        <div class="google-modal-dialog">
            <button type="button" class="modal-close-btn" onclick="closeGoogleAuthModal()">&times;</button>
            <div class="google-brand-header">
                <div class="google-brand-logo">
                    <svg width="28" height="28" viewBox="0 0 24 24">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
                    </svg>
                </div>
                <h3 style="font-size: 19px; font-weight: 700; color: #1f2937; margin: 4px 0 2px 0;">Sign in with Google</h3>
                <p style="font-size: 13px; color: #6b7280; margin: 0;">Connect your Google account to Tyoy Creation</p>
            </div>

            <div id="googleAuthError" class="auth-alert-error" style="display: none; background: #fee2e2; color: #dc2626; padding: 9px 12px; border-radius: 8px; font-size: 12px; margin-bottom: 14px; text-align: left;"></div>

            <div id="googleSignInContainer" style="display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 80px; margin: 15px 0;">
                <div id="googleSignInBtn"></div>
            </div>
            <p style="font-size: 12px; color: #6b7280; margin-top: 10px;">Select your Google account securely via Google Identity Services.</p>
        </div>
    </div>

    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script>
        const GOOGLE_CLIENT_ID = <?= json_encode(defined('ENV_GOOGLE_CLIENT_ID') ? ENV_GOOGLE_CLIENT_ID : (function_exists('get_setting') ? get_setting($conn, 'google_client_id', '') : '')) ?>;

        function togglePass(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (!input || !icon) return;
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        let gisInitialized = false;

        function initGoogleAuth() {
            if (window.google && GOOGLE_CLIENT_ID && !gisInitialized) {
                try {
                    google.accounts.id.initialize({
                        client_id: GOOGLE_CLIENT_ID,
                        callback: handleGoogleCredentialResponse,
                        auto_select: false,
                        cancel_on_tap_outside: true
                    });
                    
                    const btnContainer = document.getElementById('googleSignInBtn');
                    if (btnContainer) {
                        google.accounts.id.renderButton(btnContainer, {
                            type: 'standard',
                            theme: 'outline',
                            size: 'large',
                            text: 'continue_with',
                            shape: 'rectangular',
                            width: 280,
                            logo_alignment: 'left'
                        });
                    }
                    gisInitialized = true;
                } catch (e) {
                    console.warn('[QES Google] GIS init:', e);
                }
            }
        }

        window.addEventListener('load', () => {
            initGoogleAuth();
        });

        function openGoogleAuthModal() {
            const modal = document.getElementById('googleAuthModal');
            if (modal) modal.style.display = 'flex';
            if (window.google && GOOGLE_CLIENT_ID) {
                initGoogleAuth();
                try {
                    google.accounts.id.prompt();
                } catch (e) {
                    console.warn('[QES Google] One Tap prompt error:', e);
                }
            }
        }

        function closeGoogleAuthModal() {
            const modal = document.getElementById('googleAuthModal');
            if (modal) modal.style.display = 'none';
        }

        async function handleGoogleCredentialResponse(response) {
            const errBox = document.getElementById('googleAuthError');
            if (errBox) errBox.style.display = 'none';

            if (!response || !response.credential) {
                if (errBox) {
                    errBox.textContent = 'No Google credential received. Please try again.';
                    errBox.style.display = 'block';
                }
                return;
            }

            try {
                const formData = new FormData();
                formData.append('csrf_token', <?= json_encode(csrf_token()) ?>);
                formData.append('action', 'google_login');
                formData.append('credential', response.credential);

                const res = await fetch('auth_action.php?action=google_login', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    const redirect = new URLSearchParams(window.location.search).get('redirect') || data.redirect || 'index.php';
                    window.location.href = redirect;
                } else {
                    if (errBox) {
                        errBox.textContent = data.message || 'Google sign in failed.';
                        errBox.style.display = 'block';
                    }
                }
            } catch (err) {
                if (errBox) {
                    errBox.textContent = 'Connection error during verification. Please try again.';
                    errBox.style.display = 'block';
                }
            }
        }
    </script>
</body>
</html>
