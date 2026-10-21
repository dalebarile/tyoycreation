<?php
require_once __DIR__ . '/db.php';

// Determine base href
$script_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$base_href = ($script_dir === '/' || $script_dir === '.') ? '/' : ($script_dir . '/');

// If already logged in, redirect
if (isset($_SESSION['id']) && !empty($_SESSION['role'])) {
    if (in_array($_SESSION['role'], ['admin', 'super_admin', 'main_admin'])) {
        header("Location: " . $base_href . "a_home.php");
        exit;
    } else {
        $dest = $_GET['redirect'] ?? 'index.php';
        if (preg_match('#^https?://|^//#i', $dest) || str_contains($dest, '://')) {
            $dest = 'index.php';
        }
        header("Location: " . $dest);
        exit;
    }
}

$business_name = function_exists('get_setting') ? get_setting($conn, 'business_name', 'Tyoy Creation') : 'Tyoy Creation';
$google_client_id = defined('ENV_GOOGLE_CLIENT_ID') ? ENV_GOOGLE_CLIENT_ID : (function_exists('get_setting') ? get_setting($conn, 'google_client_id', '') : '');
$redirect_target = htmlspecialchars($_GET['redirect'] ?? 'index.php', ENT_QUOTES, 'UTF-8');
if (preg_match('#^https?://|^//#i', $redirect_target) || str_contains($redirect_target, '://')) {
    $redirect_target = 'index.php';
}

$active_tab = (isset($_GET['tab']) && $_GET['tab'] === 'register') ? 'register' : 'login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Client Sign In &bull; <?= htmlspecialchars($business_name) ?></title>
    <link rel="icon" type="image/png" href="assets/favicon.png?v=<?= filemtime(__DIR__ . '/assets/favicon.png') ?>">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1b382b;
            --primary-dark: #12261d;
            --primary-light: #285440;
            --accent-gold: #c59f60;
            --accent-soft: #f4ede2;
            --bg-page: #f8faf8;
            --text-dark: #1c2e24;
            --text-muted: #526359;
            --border-color: #dbe4dd;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-page);
            background-image: 
                radial-gradient(at 0% 0%, rgba(27, 56, 43, 0.08) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(197, 159, 96, 0.08) 0px, transparent 50%);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
            padding: 24px 16px;
        }

        .auth-container {
            width: 100%;
            max-width: 480px;
            margin: auto;
        }

        .auth-header {
            text-align: center;
            margin-bottom: 24px;
        }

        .auth-logo-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #1b382b 0%, #285440 100%);
            border-radius: 18px;
            color: #ffffff;
            font-size: 28px;
            margin-bottom: 14px;
            box-shadow: 0 8px 24px rgba(27, 56, 43, 0.22);
        }

        .auth-title {
            font-family: 'Playfair Display', serif;
            font-size: 26px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 6px;
        }

        .auth-subtitle {
            font-size: 13.5px;
            color: var(--text-muted);
        }

        .auth-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 20px;
            box-shadow: 0 15px 40px -10px rgba(27, 56, 43, 0.12);
            padding: 32px 28px;
            overflow: hidden;
            position: relative;
        }

        /* Mode Tabs */
        .auth-tabs {
            display: flex;
            background: #f1f5f2;
            padding: 4px;
            border-radius: 12px;
            margin-bottom: 24px;
            gap: 4px;
        }

        .auth-tab-btn {
            flex: 1;
            padding: 10px;
            border: none;
            background: transparent;
            font-family: inherit;
            font-size: 13.5px;
            font-weight: 600;
            color: var(--text-muted);
            border-radius: 9px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .auth-tab-btn.active {
            background: #ffffff;
            color: var(--primary);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            font-weight: 700;
        }

        /* Google Connect Button */
        .google-connect-wrapper {
            margin-bottom: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }

        .btn-google-custom {
            width: 100%;
            height: 46px;
            border-radius: 10px;
            border: 1.5px solid #d1d5db;
            background: #ffffff;
            color: #374151;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
            text-decoration: none;
        }

        .btn-google-custom:hover {
            border-color: #9ca3af;
            background: #f9fafb;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .divider-box {
            display: flex;
            align-items: center;
            margin: 20px 0;
            color: #9ca3af;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .divider-box::before,
        .divider-box::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e5e7eb;
        }

        .divider-box span {
            padding: 0 12px;
        }

        /* Form Fields */
        .form-group {
            margin-bottom: 16px;
            text-align: left;
        }

        .form-label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }

        .form-control-wrap {
            position: relative;
        }

        .form-control-wrap i.field-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            height: 44px;
            padding: 10px 14px 10px 40px;
            border: 1.5px solid #d1d5db;
            border-radius: 10px;
            font-family: inherit;
            font-size: 13.5px;
            color: #111827;
            background: #ffffff;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(27, 56, 43, 0.15);
        }

        .toggle-password-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            padding: 4px;
            font-size: 14px;
        }

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            font-size: 12.5px;
        }

        .remember-label {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            color: #4b5563;
        }

        .auth-submit-btn {
            width: 100%;
            height: 46px;
            background: var(--primary);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(27, 56, 43, 0.25);
        }

        .auth-submit-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(27, 56, 43, 0.35);
        }

        .auth-submit-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        /* Alert Boxes */
        .auth-alert {
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 12.5px;
            margin-bottom: 16px;
            display: none;
            line-height: 1.5;
            align-items: flex-start;
            gap: 8px;
        }

        .auth-alert.error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .auth-alert.success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .auth-footer-links {
            text-align: center;
            margin-top: 24px;
            font-size: 12.5px;
            color: #6b7280;
        }

        .auth-footer-links a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .auth-footer-links a:hover {
            text-decoration: underline;
        }

        .admin-link-discreet {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 14px;
            font-size: 11.5px;
            color: #9ca3af;
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .admin-link-discreet:hover {
            color: #4b5563;
        }
    </style>
</head>
<body>

    <div class="auth-container">
        <div class="auth-header">
            <div class="auth-logo-badge">
                <i class="fa-solid fa-cake-candles"></i>
            </div>
            <h1 class="auth-title">Client Sign In</h1>
            <p class="auth-subtitle"><?= htmlspecialchars($business_name) ?> &bull; Client Portal (For Users &amp; Clients Only)</p>
        </div>

        <div class="auth-card">
            <!-- Mode Switcher -->
            <div class="auth-tabs" role="tablist">
                <button type="button" class="auth-tab-btn <?= $active_tab === 'login' ? 'active' : '' ?>" id="tabBtnLogin" onclick="switchTab('login')">
                    <i class="fa-solid fa-right-to-bracket"></i> Sign In
                </button>
                <button type="button" class="auth-tab-btn <?= $active_tab === 'register' ? 'active' : '' ?>" id="tabBtnRegister" onclick="switchTab('register')">
                    <i class="fa-solid fa-user-plus"></i> Create Account
                </button>
            </div>

            <!-- Feedback Alert Box -->
            <div id="authAlert" class="auth-alert">
                <i class="fa-solid fa-circle-exclamation" id="authAlertIcon" style="margin-top: 2px;"></i>
                <span id="authAlertMsg"></span>
            </div>

            <!-- 1. LOGIN FORM -->
            <form id="loginForm" style="<?= $active_tab === 'login' ? 'display: block;' : 'display: none;' ?>" onsubmit="handleLoginSubmit(event)">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="redirect" value="<?= $redirect_target ?>">

                <div class="form-group">
                    <label class="form-label" for="login_identifier">Email or Username</label>
                    <div class="form-control-wrap">
                        <i class="fa-solid fa-user field-icon"></i>
                        <input type="text" id="login_identifier" name="identifier" class="form-control" placeholder="e.g. maria.santos@gmail.com" required autofocus autocomplete="username">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="login_password">Password</label>
                    <div class="form-control-wrap">
                        <i class="fa-solid fa-lock field-icon"></i>
                        <input type="password" id="login_password" name="password" class="form-control" placeholder="Enter your password" required autocomplete="current-password">
                        <button type="button" class="toggle-password-btn" onclick="togglePasswordVisibility('login_password', this)">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-options">
                    <label class="remember-label">
                        <input type="checkbox" id="login_remember" name="remember" checked>
                        <span>Remember me</span>
                    </label>
                    <a href="index.php#contact" style="color: var(--primary); text-decoration: none; font-size: 12px;">Need help?</a>
                </div>

                <button type="submit" class="auth-submit-btn" id="loginSubmitBtn">
                    <i class="fa-solid fa-right-to-bracket"></i>
                    <span>Sign In &amp; Continue</span>
                </button>
            </form>

            <!-- 2. REGISTER FORM -->
            <form id="registerForm" style="<?= $active_tab === 'register' ? 'display: block;' : 'display: none;' ?>" onsubmit="handleRegisterSubmit(event)">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="redirect" value="<?= $redirect_target ?>">

                <div class="form-group">
                    <label class="form-label" for="reg_full_name">Full Name *</label>
                    <div class="form-control-wrap">
                        <i class="fa-solid fa-id-card field-icon"></i>
                        <input type="text" id="reg_full_name" name="full_name" class="form-control" placeholder="e.g. Maria Santos" required autocomplete="name">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="reg_username">Username *</label>
                    <div class="form-control-wrap">
                        <i class="fa-solid fa-at field-icon"></i>
                        <input type="text" id="reg_username" name="username" class="form-control" placeholder="e.g. mariasantos" required autocomplete="username">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="reg_email">Email Address *</label>
                    <div class="form-control-wrap">
                        <i class="fa-solid fa-envelope field-icon"></i>
                        <input type="email" id="reg_email" name="email" class="form-control" placeholder="e.g. maria.santos@gmail.com" required autocomplete="email">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="reg_phone">Mobile / Contact Number</label>
                    <div class="form-control-wrap">
                        <i class="fa-solid fa-phone field-icon"></i>
                        <input type="tel" id="reg_phone" name="phone" class="form-control" placeholder="e.g. 09171234567" autocomplete="tel">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="reg_password">Password (min. 6 chars) *</label>
                    <div class="form-control-wrap">
                        <i class="fa-solid fa-lock field-icon"></i>
                        <input type="password" id="reg_password" name="password" class="form-control" placeholder="Create a secure password" required minlength="6" autocomplete="new-password">
                        <button type="button" class="toggle-password-btn" onclick="togglePasswordVisibility('reg_password', this)">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="reg_confirm_password">Confirm Password *</label>
                    <div class="form-control-wrap">
                        <i class="fa-solid fa-lock field-icon"></i>
                        <input type="password" id="reg_confirm_password" name="confirm_password" class="form-control" placeholder="Re-enter your password" required minlength="6" autocomplete="new-password">
                        <button type="button" class="toggle-password-btn" onclick="togglePasswordVisibility('reg_confirm_password', this)">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 14px;">
                    <label class="remember-label" style="font-size: 12px; line-height: 1.4; color: #4b5563;">
                        <input type="checkbox" id="reg_privacy_consent" required checked>
                        <span>I agree to the collection and processing of my personal details in accordance with the <strong>Philippine Data Privacy Act (RA 10173)</strong>.</span>
                    </label>
                </div>

                <button type="submit" class="auth-submit-btn" id="regSubmitBtn">
                    <i class="fa-solid fa-user-check"></i>
                    <span>Register &amp; Continue</span>
                </button>
            </form>

            <!-- Divider (Google Connect placed below) -->
            <div class="divider-box" style="margin: 22px 0 16px;">
                <span id="dividerText">or continue with Google</span>
            </div>

            <!-- Google One-Click Connect Button via Supabase OAuth -->
            <div class="google-connect-wrapper" style="margin-bottom: 8px;">
                <button type="button" class="btn-google-custom" id="supabaseGoogleBtn" onclick="signInWithSupabaseGoogle()" style="display: flex; justify-content: center; align-items: center; gap: 10px; width: 100%;">
                    <svg width="18" height="18" viewBox="0 0 18 18">
                        <path fill="#4285F4" d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.874 2.684-6.616z"/>
                        <path fill="#34A853" d="M9 18c2.43 0 4.467-.806 5.956-2.184l-2.908-2.258c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332C2.438 15.983 5.482 18 9 18z"/>
                        <path fill="#FBBC05" d="M3.964 10.707c-.18-.54-.282-1.117-.282-1.707 0-.59.102-1.167.282-1.707V4.961H.957C.347 6.173 0 7.548 0 9s.347 2.827.957 4.039l3.007-2.332z"/>
                        <path fill="#EA4335" d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0 5.482 0 2.438 2.017.957 4.961L3.964 7.293C4.672 5.166 6.656 3.58 9 3.58z"/>
                    </svg>
                    <span>Continue with Google</span>
                </button>
            </div>
        </div>

        <div class="auth-footer-links">
            <p>
                <a href="index.php"><i class="fa-solid fa-arrow-left"></i> Return to Homepage</a>
            </p>
            <p>
                <a href="loginadmin.php" class="admin-link-discreet">
                    <i class="fa-solid fa-shield"></i> Staff &amp; Administrator Portal
                </a>
            </p>
        </div>
    </div>

    <script>
        const GOOGLE_CLIENT_ID = <?= json_encode($google_client_id) ?>;
        const REDIRECT_TARGET = <?= json_encode($redirect_target) ?>;

        function showAlert(msg, type = 'error') {
            const alertBox = document.getElementById('authAlert');
            const alertMsg = document.getElementById('authAlertMsg');
            const alertIcon = document.getElementById('authAlertIcon');
            if (!alertBox || !alertMsg) return;

            alertBox.className = 'auth-alert ' + type;
            alertBox.style.display = 'flex';
            alertMsg.textContent = msg;

            if (type === 'success') {
                alertIcon.className = 'fa-solid fa-circle-check';
            } else {
                alertIcon.className = 'fa-solid fa-circle-exclamation';
            }
        }

        function hideAlert() {
            const alertBox = document.getElementById('authAlert');
            if (alertBox) alertBox.style.display = 'none';
        }

        function switchTab(mode) {
            hideAlert();
            const tabLogin = document.getElementById('tabBtnLogin');
            const tabRegister = document.getElementById('tabBtnRegister');
            const formLogin = document.getElementById('loginForm');
            const formRegister = document.getElementById('registerForm');
            const dividerText = document.getElementById('dividerText');

            if (mode === 'register') {
                tabLogin.classList.remove('active');
                tabRegister.classList.add('active');
                formLogin.style.display = 'none';
                formRegister.style.display = 'block';
                if (dividerText) dividerText.textContent = 'or continue with Google';
            } else {
                tabRegister.classList.remove('active');
                tabLogin.classList.add('active');
                formRegister.style.display = 'none';
                formLogin.style.display = 'block';
                if (dividerText) dividerText.textContent = 'or continue with Google';
            }
        }

        function togglePasswordVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('i');
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

        // Supabase Google OAuth
        const SUPABASE_AUTH_URL = <?= json_encode(defined('ENV_SUPABASE_URL') ? rtrim(ENV_SUPABASE_URL, '/') : 'https://kanotzhwqsscejqecqom.supabase.co') ?>;

        function signInWithSupabaseGoogle() {
            hideAlert();
            const btn = document.getElementById('supabaseGoogleBtn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Connecting to Google...';
            }
            const redirectUrl = window.location.origin + window.location.pathname;
            window.location.href = `${SUPABASE_AUTH_URL}/auth/v1/authorize?provider=google&redirect_to=${encodeURIComponent(redirectUrl)}`;
        }

        let isLoginOAuthVerifying = false;
        async function checkSupabaseOAuthCallback() {
            if (isLoginOAuthVerifying) return;

            const hash = window.location.hash.substring(1);
            const search = window.location.search.substring(1);
            if (hash.includes('error=') || search.includes('error=')) {
                isLoginOAuthVerifying = true;
                const errParams = new URLSearchParams(hash.includes('error=') ? hash : search);
                const errMsg = errParams.get('error_description') || errParams.get('error') || 'Google sign-in was cancelled or failed.';
                showAlert(decodeURIComponent(errMsg.replace(/\+/g, ' ')));
                try { history.replaceState(null, '', window.location.pathname); } catch(e){}
                return;
            }
            if (!hash || !hash.includes('access_token=')) return;

            const params = new URLSearchParams(hash);
            const accessToken = params.get('access_token');
            if (!accessToken) return;

            // Clear the hash from address bar immediately so reloads don't loop the same token!
            try { history.replaceState(null, '', window.location.pathname + window.location.search); } catch(e){}

            isLoginOAuthVerifying = true;
            showAlert('<i class="fa-solid fa-circle-notch fa-spin" style="margin-right: 6px;"></i> Signing you in with Google...', 'success');

            const controller = new AbortController();
            const timeoutTimer = setTimeout(() => controller.abort(), 20000);

            try {
                const formData = new FormData();
                formData.append('csrf_token', <?= json_encode(csrf_token()) ?>);
                formData.append('action', 'supabase_login');
                formData.append('access_token', accessToken);

                const res = await fetch('auth_action.php?action=supabase_login', {
                    method: 'POST',
                    body: formData,
                    signal: controller.signal
                });
                clearTimeout(timeoutTimer);
                const data = await res.json();
                if (data.success) {
                    if (REDIRECT_TARGET === 'index.php') {
                        try { sessionStorage.removeItem('tyoy_privacy_agreed'); } catch(e){}
                    } else {
                        sessionStorage.setItem('tyoy_privacy_agreed', '1');
                    }
                    showAlert('<i class="fa-solid fa-circle-check" style="margin-right: 6px;"></i> Signed in! Redirecting...', 'success');
                    setTimeout(() => {
                        window.location.href = REDIRECT_TARGET;
                    }, 100);
                } else {
                    isLoginOAuthVerifying = false;
                    showAlert(data.message || 'Google sign-in could not be completed. Please try again.');
                }
            } catch (err) {
                clearTimeout(timeoutTimer);
                isLoginOAuthVerifying = false;
                showAlert('Connection took longer than expected. Please click Continue with Google to try again.');
            }
        }

        window.addEventListener('load', () => {
            checkSupabaseOAuthCallback();
        });

        // Standard Login Submit
        async function handleLoginSubmit(e) {
            e.preventDefault();
            hideAlert();

            const btn = document.getElementById('loginSubmitBtn');
            const originalContent = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Signing in...';

            const form = document.getElementById('loginForm');
            const formData = new FormData(form);
            formData.append('action', 'login');

            try {
                const res = await fetch('auth_action.php?action=login', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    if (REDIRECT_TARGET === 'index.php') {
                        try { sessionStorage.removeItem('tyoy_privacy_agreed'); } catch(e){}
                    } else {
                        sessionStorage.setItem('tyoy_privacy_agreed', '1');
                    }
                    showAlert('Login successful! Redirecting...', 'success');
                    setTimeout(() => {
                        window.location.href = REDIRECT_TARGET;
                    }, 500);
                } else {
                    showAlert(data.message || 'Invalid username/email or password.');
                    btn.disabled = false;
                    btn.innerHTML = originalContent;
                }
            } catch (err) {
                showAlert('A network error occurred. Please try again.');
                btn.disabled = false;
                btn.innerHTML = originalContent;
            }
        }

        // Standard Register Submit
        async function handleRegisterSubmit(e) {
            e.preventDefault();
            hideAlert();

            const p1 = document.getElementById('reg_password').value;
            const p2 = document.getElementById('reg_confirm_password').value;
            if (p1 !== p2) {
                showAlert('Passwords do not match. Please re-enter.');
                return;
            }

            const privacyCheck = document.getElementById('reg_privacy_consent');
            if (privacyCheck && !privacyCheck.checked) {
                showAlert('Please agree to the Philippine Data Privacy Notice to register.');
                return;
            }

            const btn = document.getElementById('regSubmitBtn');
            const originalContent = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Creating account...';

            const form = document.getElementById('registerForm');
            const formData = new FormData(form);
            formData.append('action', 'register');

            try {
                const res = await fetch('auth_action.php?action=register', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    if (REDIRECT_TARGET === 'index.php') {
                        try { sessionStorage.removeItem('tyoy_privacy_agreed'); } catch(e){}
                    } else {
                        sessionStorage.setItem('tyoy_privacy_agreed', '1');
                    }
                    showAlert('Account created successfully! Redirecting...', 'success');
                    setTimeout(() => {
                        window.location.href = REDIRECT_TARGET;
                    }, 500);
                } else {
                    showAlert(data.message || 'Registration failed. Please check your information.');
                    btn.disabled = false;
                    btn.innerHTML = originalContent;
                }
            } catch (err) {
                showAlert('A network error occurred. Please try again.');
                btn.disabled = false;
                btn.innerHTML = originalContent;
            }
        }
    </script>
</body>
</html>