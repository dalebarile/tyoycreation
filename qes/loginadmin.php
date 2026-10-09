<?php 
require_once __DIR__ . '/db.php';

// Determine web root path (e.g., /qes)
$script_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($script_dir === '/' || $script_dir === '.') {
    $base_href = '/';
} else {
    $base_href = $script_dir . '/';
}

// If already logged in as admin, redirect to admin home
if (isset($_SESSION['id']) && !empty($_SESSION['role'])) {
    if (in_array($_SESSION['role'], ['admin', 'super_admin', 'main_admin'])) {
        header("Location: " . $base_href . "a_home.php");
        exit;
    }
}

$login_error = '';
$is_locked = false;
$retry_after = 0;

$client_ip = qes_client_ip();
$ip_key = 'ip:' . $client_ip;

// Proactively check if this IP is already locked out on initial load
$ip_initial_check = qes_rate_limit_check('admin_login_ip', $ip_key, ADMIN_LOGIN_IP_MAX_ATTEMPTS, ADMIN_LOGIN_IP_LOCKOUT_SECONDS, $conn);
if (!$ip_initial_check['allowed']) {
    $is_locked = true;
    $retry_after = (int)($ip_initial_check['retry_after'] ?? 0);
    $mins = max(1, (int)ceil($retry_after / 60));
    $login_error = "Too many failed attempts from your IP address. Please try again in {$mins} minute(s).";
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$is_locked) {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }

    // Cloudflare Turnstile Verification
    if (qes_is_turnstile_enabled($conn)) {
        $ts_token = $_POST['cf-turnstile-response'] ?? '';
        $ts_check = qes_verify_turnstile($ts_token, $client_ip, $conn);
        if (!$ts_check['success']) {
            $login_error = $ts_check['error'] ?? 'Security check failed. Please complete the Cloudflare Turnstile verification.';
        }
    }

    if (empty($login_error)) {
        $identifier = trim($_POST['identifier'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($identifier) || empty($password)) {
            $login_error = "Please enter your username/email and password.";
        } else {
            $login_res = qes_process_admin_login($conn, $identifier, $password, $client_ip);

            if ($login_res['success']) {
                $user = $login_res['user'];

                // Prevent session fixation
                session_regenerate_id(true);

                $_SESSION['id'] = (int)$user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = !empty($user['full_name']) ? $user['full_name'] : $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['phone'] = $user['phone'] ?? '';

                // Track device & active session
                track_user_session($conn, (int)$user['id'], true);

                header("Location: " . $base_href . "a_home.php");
                exit;
            } else {
                $login_error = $login_res['error'];
                $is_locked = !empty($login_res['locked']);
                $retry_after = (int)($login_res['retry_after'] ?? 0);
            }
        }
    }
}

if (isset($_GET['error']) && $_GET['error'] === 'device_blocked') {
    $login_error = "Security Notice: Your session on this device has been blocked or terminated from your account settings.";
}

$business_name = get_setting($conn, 'business_name', 'Tyoy Creation');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="<?= htmlspecialchars($base_href) ?>">
    <title><?= htmlspecialchars($business_name) ?> - Admin Portal</title>
    <link rel="icon" type="image/png" href="assets/favicon.png?v=<?= filemtime(__DIR__ . '/assets/favicon.png') ?>">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php if (qes_is_turnstile_enabled($conn)): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
    <style>
        body {
            background-color: #142e23;
            background-image: 
                linear-gradient(135deg, rgba(20, 46, 35, 0.92) 0%, rgba(24, 57, 43, 0.88) 50%, rgba(13, 32, 24, 0.95) 100%),
                url('assets/tyoy_creation_banner.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            position: relative;
            font-family: inherit;
        }

        /* Abstract glowing background shapes */
        .bg-glow-1 { display: none; }
        .bg-glow-2 { display: none; }

        .login-card {
            background: #ffffff;
            border-radius: 20px;
            width: 100%;
            max-width: 440px;
            padding: 42px 36px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            z-index: 10;
            text-align: center;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 18px;
        }

        .admin-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #eaf2ec;
            color: #18392b;
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            border: 1px solid rgba(24, 57, 43, 0.15);
        }

        .login-logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .login-heading {
            font-size: 22px;
            font-weight: 800;
            color: #14261c;
            margin: 0 0 6px 0;
            letter-spacing: -0.02em;
        }

        .login-title {
            font-size: 13px;
            color: #3d5345;
            margin-bottom: 24px;
            line-height: 1.5;
        }

        .login-field {
            text-align: left;
            margin-bottom: 18px;
            position: relative;
        }

        .login-field label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #14261c;
            margin-bottom: 6px;
        }

        .input-group {
            position: relative;
        }

        .input-group input {
            width: 100%;
            padding: 12px 14px;
            padding-right: 42px;
            border: 1px solid #dbe5de;
            border-radius: 10px;
            font-size: 14px;
            outline: none;
            transition: var(--transition);
            box-sizing: border-box;
            background: #ffffff;
        }

        .input-group input:focus {
            border-color: #18392b;
            box-shadow: 0 0 0 3px rgba(24, 57, 43, 0.12);
        }

        .toggle-password-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: #667d6f;
            cursor: pointer;
            font-size: 14px;
            padding: 4px;
        }

        .toggle-password-btn:hover {
            color: #18392b;
        }

        .btn-login {
            width: 100%;
            padding: 13px;
            background: #18392b;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(24, 57, 43, 0.28);
            transition: all 0.2s ease;
            margin-top: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-login:hover {
            background: #122c21;
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(24, 57, 43, 0.38);
        }

        .login-error {
            background: #fee2e2;
            color: #dc2626;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid #fecaca;
            line-height: 1.4;
        }

        .back-to-site {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 24px;
            font-size: 13px;
            color: #3d5345;
            font-weight: 600;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 8px;
            transition: all 0.2s;
            border: 1px solid transparent;
        }

        .back-to-site:hover {
            color: #18392b;
            background: #eaf2ec;
            border-color: #dbe5de;
        }

        .login-footer-text {
            margin-top: 24px;
            font-size: 11px;
            color: var(--text-muted);
        }
    </style>
</head>
<body>

    <div class="bg-glow-1"></div>
    <div class="bg-glow-2"></div>

    <div class="login-card">
        <div class="login-header" style="margin-bottom: 20px;">
            <div class="login-logo">
                <img src="assets/tyoy_logo_cropped.png?v=<?= filemtime(__DIR__ . '/assets/tyoy_logo_cropped.png') ?>" alt="<?= htmlspecialchars($business_name) ?>" style="height: 60px; width: auto; display: block; margin: 0 auto;">
            </div>
        </div>

        <h2 class="login-heading" style="font-family: 'Playfair Display', Georgia, serif; font-size: 24px; font-weight: 700; color: #1f2937; margin: 0 0 6px 0;">Admin Login</h2>
        <p class="login-title" style="font-size: 13.5px; color: #6b7280; margin-bottom: 24px;">Access your dashboard to manage events and bookings.</p>

        <?php if (!empty($login_error)): ?>
            <div class="login-error" id="loginAlertBox" style="border-radius: 12px; padding: 12px 16px; border: 1px solid #fecaca; background: #fef2f2; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.08); margin-bottom: 20px; display: flex; align-items: center; gap: 12px;">
                <div id="loginAlertIcon" style="width: 28px; height: 28px; border-radius: 50%; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0;">
                    <i class="fa-solid <?= $is_locked ? 'fa-lock' : 'fa-circle-exclamation' ?>"></i>
                </div>
                <span id="loginAlertMessage" style="font-size: 13px; font-weight: 500; color: #991b1b; text-align: left;">
                    <?php if ($is_locked && $retry_after > 0): ?>
                        Too many failed attempts. Please try again in <strong id="lockCountdownDisplay"><?= sprintf('%02d:%02d', floor($retry_after / 60), $retry_after % 60) ?></strong>.
                    <?php else: ?>
                        <?= htmlspecialchars($login_error) ?>
                    <?php endif; ?>
                </span>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="adminLoginForm">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            
            <div class="login-field">
                <label style="font-weight: 600; font-size: 13px; color: #374151;">Email Address</label>
                <div class="input-group">
                    <input type="text" id="identifierInput" name="identifier" value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>" placeholder="Enter your email" required autocomplete="username" autofocus style="border-radius: 8px; border-color: #d1d5db; padding: 11px 14px;" <?= $is_locked ? 'disabled' : '' ?>>
                </div>
            </div>

            <div class="login-field" style="margin-bottom: 22px;">
                <label style="font-weight: 600; font-size: 13px; color: #374151;">Password</label>
                <div class="input-group">
                    <input type="password" id="passwordInput" name="password" placeholder="Enter your password" required autocomplete="current-password" style="border-radius: 8px; border-color: #d1d5db; padding: 11px 14px;" <?= $is_locked ? 'disabled' : '' ?>>
                    <button type="button" class="toggle-password-btn" onclick="togglePasswordVisibility()" aria-label="Toggle password visibility">
                        <i class="fa-solid fa-eye" id="passwordEyeIcon"></i>
                    </button>
                </div>
            </div>

            <?php if (qes_is_turnstile_enabled($conn)): ?>
            <!-- Cloudflare Turnstile CAPTCHA Protection -->
            <div class="login-field" style="margin-bottom: 18px; display: flex; justify-content: center;">
                <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars(qes_get_turnstile_site_key($conn)) ?>" data-theme="light"></div>
            </div>
            <?php endif; ?>

            <button type="submit" id="loginSubmitBtn" class="btn-login" style="background: #18392b; color: #ffffff; border-radius: 8px; font-weight: 700; padding: 12px; font-size: 14.5px; width: 100%; box-shadow: 0 4px 14px rgba(24, 57, 43, 0.28); <?= $is_locked ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>" <?= $is_locked ? 'disabled' : '' ?>>
                Login
            </button>

            <!-- Centered Forgot Password Link (Panel 7) -->
            <div style="text-align: center; margin-top: 14px; margin-bottom: 8px;">
                <button type="button" onclick="openForgotPasswordModal()" style="background: none; border: none; font-size: 12.5px; color: #6b7280; cursor: pointer; padding: 0; text-decoration: underline; transition: color 0.15s ease;">
                    Forgot password?
                </button>
            </div>
        </form>

        <div>
            <a href="index.php" class="back-to-site">
                <i class="fa-solid fa-arrow-left"></i> Back to Website
            </a>
        </div>

        <div class="login-footer-text">
            &copy; <?= date('Y') ?> <?= htmlspecialchars($business_name) ?> &bull; Authorized Personnel Only
        </div>
    </div>

    <!-- Multi-Step Forgot Password Verification & Reset Modal -->
    <div class="modal-backdrop" id="forgotPasswordModal" style="z-index: 9999; padding: 15px;">
        <div class="modal-card" style="max-width: 450px; width: 100%; max-height: 92vh; display: flex; flex-direction: column; border-radius: 16px; overflow: hidden; box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.5); background: #ffffff; margin: auto;">
            <!-- Modal Header -->
            <div style="padding: 14px 20px; border-bottom: 1px solid #eef2ee; display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, #f7faf7 0%, #eef2ee 100%); flex-shrink: 0;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div id="fpHeaderIcon" style="width: 36px; height: 36px; border-radius: 10px; background: #e0ece0; color: #364735; display: flex; align-items: center; justify-content: center; font-size: 16px; box-shadow: 0 2px 6px rgba(54, 71, 53, 0.12);">
                        <i class="fa-solid fa-key"></i>
                    </div>
                    <div style="text-align: left;">
                        <h3 id="fpHeaderTitle" style="font-size: 16px; margin: 0; font-weight: 700; color: #1f291e;">Forgot Password</h3>
                        <p id="fpHeaderSubtitle" style="font-size: 11.5px; color: #527952; margin: 1px 0 0 0;">Account Recovery &amp; Security</p>
                    </div>
                </div>
                <button type="button" onclick="closeForgotPasswordModal()" style="background: transparent; border: none; font-size: 18px; color: #9ca3af; cursor: pointer; border-radius: 6px; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;" aria-label="Close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Modal Content Steps (Scrollable Container) -->
            <div style="padding: 18px 22px 22px 22px; overflow-y: auto; max-height: calc(92vh - 65px); flex: 1; -webkit-overflow-scrolling: touch;">
                <!-- STEP 1: Ask for Email / Identifier -->
                <div id="fpStep1">
                    <p style="font-size: 13.5px; color: #374151; margin: 0 0 14px 0; line-height: 1.5; text-align: left;">
                        Enter your registered email address or username. The system will dispatch a <strong>6-digit security code</strong> to your email.
                    </p>

                    <div id="fpStep1Alert" style="display: none; border-radius: 8px; padding: 10px 12px; margin-bottom: 14px; font-size: 12.5px; text-align: left;"></div>

                    <div style="text-align: left; margin-bottom: 16px;">
                        <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 5px;">Email or Username</label>
                        <div class="input-group">
                            <input type="text" id="fpIdentifierInput" placeholder="e.g. admin@gmail.com or username" style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13.5px; outline: none; box-sizing: border-box;" onkeydown="if(event.key==='Enter')submitForgotRequest();">
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px;">
                        <button type="button" class="btn-secondary" onclick="closeForgotPasswordModal()" style="padding: 9px 16px; font-size: 13px; font-weight: 600; border-radius: 8px; cursor: pointer;">Cancel</button>
                        <button type="button" id="btnSendCode" onclick="submitForgotRequest()" style="padding: 9px 20px; font-size: 13px; font-weight: 700; background: #364735; color: #ffffff; border: none; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(54, 71, 53, 0.25);">
                            <i class="fa-solid fa-paper-plane"></i> Send Verification Code
                        </button>
                    </div>
                </div>

                <!-- STEP 2: Input 6-Digit Code -->
                <div id="fpStep2" style="display: none;">
                    <p style="font-size: 13.5px; color: #374151; margin: 0 0 14px 0; line-height: 1.5; text-align: left;">
                        A 6-digit verification code has been dispatched to <strong id="fpMaskedEmailText" style="color: #364735;"></strong>. Enter the code below:
                    </p>

                    <div id="fpStep2Alert" style="display: none; border-radius: 8px; padding: 10px 12px; margin-bottom: 14px; font-size: 12.5px; text-align: left;"></div>

                    <div style="text-align: center; margin-bottom: 14px;">
                        <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">6-Digit Security Code</label>
                        <input type="text" id="fpCodeInput" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" placeholder="••••••" style="letter-spacing: 10px; font-size: 24px; font-weight: 800; text-align: center; padding: 10px; font-family: monospace, Courier, monospace; width: 100%; border: 2px solid #d1d5db; border-radius: 8px; box-sizing: border-box; outline: none; background: #f9fafb;" onkeydown="if(event.key==='Enter')submitVerifyCode();">
                        <div style="font-size: 11.5px; color: #6b7280; margin-top: 6px;"><i class="fa-solid fa-clock"></i> Valid for 15 minutes</div>
                    </div>

                    <div style="margin-bottom: 16px; font-size: 12.5px; color: #6b7280; text-align: center;">
                        Didn't receive the email? 
                        <button type="button" id="btnResendCode" onclick="submitForgotRequest(true)" style="background: none; border: none; font-size: 12.5px; font-weight: 700; color: #364735; text-decoration: underline; cursor: pointer; padding: 0;">
                            Resend Code
                        </button>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 8px;">
                        <button type="button" class="btn-secondary" onclick="backToStep1()" style="padding: 9px 16px; font-size: 13px; font-weight: 600; border-radius: 8px; cursor: pointer;">
                            <i class="fa-solid fa-arrow-left"></i> Back
                        </button>
                        <button type="button" id="btnVerifyCode" onclick="submitVerifyCode()" style="padding: 9px 20px; font-size: 13px; font-weight: 700; background: #364735; color: #ffffff; border: none; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(54, 71, 53, 0.25);">
                            <i class="fa-solid fa-check"></i> Verify Code
                        </button>
                    </div>
                </div>

                <!-- STEP 3: Change Password with Warning on Mismatch -->
                <div id="fpStep3" style="display: none;">
                    <p style="font-size: 13px; color: #374151; margin: 0 0 11px 0; line-height: 1.4; text-align: left;">
                        Code verified! Please enter your <strong>new password</strong> and confirm below:
                    </p>

                    <!-- Warning notice if inputs differ -->
                    <div id="fpStep3Alert" style="display: none; border-radius: 8px; padding: 9px 12px; margin-bottom: 10px; font-size: 12.5px; text-align: left;"></div>

                    <div style="text-align: left; margin-bottom: 10px;">
                        <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 4px;">New Password</label>
                        <div class="input-group" style="position: relative;">
                            <input type="password" id="fpNewPassword" placeholder="Minimum 8 characters" style="width: 100%; padding: 9px 38px 9px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13.5px; outline: none; box-sizing: border-box;" oninput="validateFpPasswordCriteria()">
                            <button type="button" onclick="togglePassField('fpNewPassword', 'fpNewEye')" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #6b7280; cursor: pointer; padding: 4px;" aria-label="Toggle password">
                                <i class="fa-solid fa-eye" id="fpNewEye" style="font-size: 13px;"></i>
                            </button>
                        </div>
                    </div>

                    <div style="text-align: left; margin-bottom: 10px;">
                        <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 4px;">Confirm Password</label>
                        <div class="input-group" style="position: relative;">
                            <input type="password" id="fpConfirmPassword" placeholder="Repeat your new password" style="width: 100%; padding: 9px 38px 9px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13.5px; outline: none; box-sizing: border-box;" onkeydown="if(event.key==='Enter')submitPasswordReset();" oninput="validateFpPasswordCriteria()">
                            <button type="button" onclick="togglePassField('fpConfirmPassword', 'fpConfEye')" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #6b7280; cursor: pointer; padding: 4px;" aria-label="Toggle password">
                                <i class="fa-solid fa-eye" id="fpConfEye" style="font-size: 13px;"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Compact Real-Time Password Criteria Box (Step 3) -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 9px 12px; margin-bottom: 13px; text-align: left;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                            <span style="font-size: 11.5px; font-weight: 700; color: #1e293b;">Requirements:</span>
                            <span id="fpStrengthPill" style="font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 99px; background: #e2e8f0; color: #475569;">Enter password</span>
                        </div>
                        <div style="height: 3px; background: #e2e8f0; border-radius: 99px; overflow: hidden; margin-bottom: 7px;">
                            <div id="fpStrengthBar" style="height: 100%; width: 0%; background: #ef4444; transition: all 0.3s ease;"></div>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 3px 8px; font-size: 11px;">
                            <div id="fpCritLength" style="display: flex; align-items: center; gap: 5px; color: #64748b;">
                                <i class="fa-solid fa-circle-xmark" style="color: #cbd5e1; font-size: 10.5px;"></i>
                                <span>8+ chars</span>
                            </div>
                            <div id="fpCritUpper" style="display: flex; align-items: center; gap: 5px; color: #64748b;">
                                <i class="fa-solid fa-circle-xmark" style="color: #cbd5e1; font-size: 10.5px;"></i>
                                <span>Uppercase (A-Z)</span>
                            </div>
                            <div id="fpCritLower" style="display: flex; align-items: center; gap: 5px; color: #64748b;">
                                <i class="fa-solid fa-circle-xmark" style="color: #cbd5e1; font-size: 10.5px;"></i>
                                <span>Lowercase (a-z)</span>
                            </div>
                            <div id="fpCritNumber" style="display: flex; align-items: center; gap: 5px; color: #64748b;">
                                <i class="fa-solid fa-circle-xmark" style="color: #cbd5e1; font-size: 10.5px;"></i>
                                <span>Number (0-9)</span>
                            </div>
                            <div id="fpCritSpecial" style="display: flex; align-items: center; gap: 5px; color: #64748b;">
                                <i class="fa-solid fa-circle-xmark" style="color: #cbd5e1; font-size: 10.5px;"></i>
                                <span>Symbol (!@#$)</span>
                            </div>
                            <div id="fpCritMatch" style="display: flex; align-items: center; gap: 5px; color: #64748b;">
                                <i class="fa-solid fa-circle-xmark" style="color: #cbd5e1; font-size: 10.5px;"></i>
                                <span>Passwords match</span>
                            </div>
                        </div>
                        <div id="fpLiveMatchAlert" style="margin-top: 5px; font-size: 11px; font-weight: 600; display: none; align-items: center; gap: 5px;">
                            <i id="fpLiveMatchIcon" class="fa-solid fa-circle-exclamation"></i>
                            <span id="fpLiveMatchText"></span>
                        </div>
                    </div>

                    <!-- Spacious Confirm Button with Bottom Margin -->
                    <div style="display: flex; justify-content: flex-end; margin-top: 4px; padding-bottom: 6px;">
                        <button type="button" id="btnResetPassword" onclick="submitPasswordReset()" style="width: 100%; padding: 11px 16px; font-size: 13.5px; font-weight: 700; background: #364735; color: #ffffff; border: none; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 12px rgba(54, 71, 53, 0.25); transition: all 0.2s;">
                            <i class="fa-solid fa-circle-check"></i> Confirm &amp; Update Password
                        </button>
                    </div>
                </div>

                <!-- STEP 4: Success State -->
                <div id="fpStep4" style="display: none; text-align: center; padding: 8px 0;">
                    <div style="width: 56px; height: 56px; border-radius: 50%; background: #dcfce7; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 26px; margin: 0 auto 16px auto;">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <h4 style="font-size: 18px; font-weight: 700; color: #1f291e; margin: 0 0 8px 0;">Password Changed Successfully!</h4>
                    <p style="font-size: 13px; color: #4b5563; margin: 0 0 24px 0; line-height: 1.5;">
                        Your account credentials have been updated. You can now use your new password to sign into the system.
                    </p>
                    <button type="button" onclick="finishForgotPassword()" class="btn-login" style="margin: 0; width: 100%;">
                        <i class="fa-solid fa-right-to-bracket"></i> Sign In With New Password
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['error']) && $_GET['error'] === 'device_blocked'): ?>
    <!-- Security Device Blocked Alert Modal -->
    <div class="modal-backdrop active" id="deviceBlockedModal" style="z-index: 9999; display: flex;">
        <div class="modal-card" style="max-width: 460px; flex-direction: column; border-radius: 16px; overflow: hidden; box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.5);">
            <div style="padding: 20px 24px; border-bottom: 1px solid #fee2e2; display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, #fff5f5 0%, #fef2f2 100%);">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 42px; height: 42px; border-radius: 12px; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 20px; box-shadow: 0 2px 8px rgba(220, 38, 38, 0.15);">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 17px; margin: 0; font-weight: 700; color: #991b1b;">Device Access Blocked</h3>
                        <p style="font-size: 12px; color: #b91c1c; margin: 2px 0 0 0;">Security Policy Enforcement</p>
                    </div>
                </div>
                <button type="button" onclick="closeDeviceBlockedModal()" style="background: transparent; border: none; font-size: 20px; color: #9ca3af; cursor: pointer;" aria-label="Close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div style="padding: 24px; text-align: left;">
                <p style="font-size: 14px; color: #374151; margin: 0 0 16px 0; line-height: 1.5;">
                    Your active session on this device has been <strong>blocked or terminated</strong> from the administrative account settings.
                </p>

                <div style="background: #fff1f2; border-left: 4px solid #f43f5e; padding: 12px 16px; border-radius: 6px; font-size: 12px; color: #9f1239; line-height: 1.4; margin-bottom: 8px;">
                    <i class="fa-solid fa-circle-info" style="margin-right: 4px;"></i>
                    If you are an authorized administrator and suspect this was an error, please request an unblock from the Main Admin or sign in from an approved workstation.
                </div>
            </div>

            <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; background: #fafafa;">
                <button type="button" onclick="closeDeviceBlockedModal()" style="padding: 10px 24px; font-size: 13px; font-weight: 700; background: #364735; color: #ffffff; border: none; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(54, 71, 53, 0.25);">
                    <i class="fa-solid fa-check"></i> Understood
                </button>
            </div>
        </div>
    </div>
    <script>
        function closeDeviceBlockedModal() {
            const m = document.getElementById('deviceBlockedModal');
            if (m) m.style.display = 'none';
        }
        window.addEventListener('click', function(e) {
            const m = document.getElementById('deviceBlockedModal');
            if (m && e.target === m) closeDeviceBlockedModal();
        });
        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDeviceBlockedModal();
        });
    </script>
    <?php endif; ?>

    <script>
        const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
        let fpCurrentIdentifier = '';
        let fpVerifiedCode = '';

        function togglePasswordVisibility() {
            const input = document.getElementById('passwordInput');
            const icon = document.getElementById('passwordEyeIcon');
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

        function togglePassField(inputId, iconId) {
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

        function openForgotPasswordModal() {
            // Reset to Step 1
            document.getElementById('fpStep1').style.display = 'block';
            document.getElementById('fpStep2').style.display = 'none';
            document.getElementById('fpStep3').style.display = 'none';
            document.getElementById('fpStep4').style.display = 'none';

            // Reset header
            setFpHeader('fa-key', 'Forgot Password', 'Account Recovery & Security');

            // Reset fields
            document.getElementById('fpIdentifierInput').value = document.querySelector('input[name="identifier"]').value || '';
            document.getElementById('fpCodeInput').value = '';
            document.getElementById('fpNewPassword').value = '';
            document.getElementById('fpConfirmPassword').value = '';
            hideFpAlert('fpStep1Alert');
            hideFpAlert('fpStep2Alert');
            hideFpAlert('fpStep3Alert');
            clearPasswordErrorHighlights();

            const m = document.getElementById('forgotPasswordModal');
            if (m) m.classList.add('active');
            setTimeout(() => document.getElementById('fpIdentifierInput').focus(), 100);
        }

        function closeForgotPasswordModal() {
            const m = document.getElementById('forgotPasswordModal');
            if (m) m.classList.remove('active');
        }

        function setFpHeader(iconClass, title, subtitle) {
            document.getElementById('fpHeaderIcon').innerHTML = `<i class="fa-solid ${iconClass}"></i>`;
            document.getElementById('fpHeaderTitle').textContent = title;
            document.getElementById('fpHeaderSubtitle').textContent = subtitle;
        }

        function showFpAlert(elementId, type, message) {
            const el = document.getElementById(elementId);
            if (!el) return;
            el.style.display = 'flex';
            el.style.alignItems = 'flex-start';
            el.style.gap = '8px';
            el.style.lineHeight = '1.4';

            if (type === 'error' || type === 'warning') {
                el.style.background = '#fff1f2';
                el.style.color = '#9f1239';
                el.style.border = '1px solid #fecdd3';
                el.innerHTML = `<i class="fa-solid fa-triangle-exclamation" style="margin-top:2px;flex-shrink:0;"></i> <span>${message}</span>`;
            } else if (type === 'success') {
                el.style.background = '#f0fdf4';
                el.style.color = '#166534';
                el.style.border = '1px solid #bbf7d0';
                el.innerHTML = `<i class="fa-solid fa-circle-check" style="margin-top:2px;flex-shrink:0;"></i> <span>${message}</span>`;
            }
        }

        function hideFpAlert(elementId) {
            const el = document.getElementById(elementId);
            if (el) el.style.display = 'none';
        }

        function clearPasswordErrorHighlights() {
            const newPw = document.getElementById('fpNewPassword');
            const confPw = document.getElementById('fpConfirmPassword');
            if (newPw) newPw.style.borderColor = '#d1d5db';
            if (confPw) confPw.style.borderColor = '#d1d5db';
        }

        function backToStep1() {
            document.getElementById('fpStep1').style.display = 'block';
            document.getElementById('fpStep2').style.display = 'none';
            setFpHeader('fa-key', 'Forgot Password', 'Account Recovery & Security');
        }

        // STEP 1: Request Code via Email
        async function submitForgotRequest(isResend = false) {
            const identifier = isResend ? fpCurrentIdentifier : document.getElementById('fpIdentifierInput').value.trim();
            const btn = isResend ? document.getElementById('btnResendCode') : document.getElementById('btnSendCode');
            const alertId = isResend ? 'fpStep2Alert' : 'fpStep1Alert';

            if (!identifier) {
                showFpAlert(alertId, 'error', 'Please enter your registered email address or username.');
                return;
            }

            hideFpAlert(alertId);
            const originalBtnHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Dispatching Code...`;

            try {
                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('action', 'forgot_password_request');
                formData.append('identifier', identifier);

                const res = await fetch('auth_action.php?action=forgot_password_request', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (data.success) {
                    fpCurrentIdentifier = data.identifier || identifier;
                    document.getElementById('fpMaskedEmailText').textContent = data.masked_email || identifier;

                    // Transition to Step 2
                    document.getElementById('fpStep1').style.display = 'none';
                    document.getElementById('fpStep2').style.display = 'block';
                    setFpHeader('fa-envelope-open-text', 'Enter Security Code', 'Verify code sent to email');
                    hideFpAlert('fpStep2Alert');
                    showFpAlert('fpStep2Alert', 'success', data.message);
                    setTimeout(() => document.getElementById('fpCodeInput').focus(), 150);
                } else {
                    showFpAlert(alertId, 'error', data.message || 'Could not send verification code. Please check your credentials.');
                }
            } catch (err) {
                showFpAlert(alertId, 'error', 'Network error while contacting the server. Please check your connection.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalBtnHtml;
            }
        }

        // STEP 2: Verify 6-digit Code
        async function submitVerifyCode() {
            const code = document.getElementById('fpCodeInput').value.trim();
            const btn = document.getElementById('btnVerifyCode');

            if (!code || code.length < 6) {
                showFpAlert('fpStep2Alert', 'error', 'Please enter the complete 6-digit verification code.');
                document.getElementById('fpCodeInput').focus();
                return;
            }

            hideFpAlert('fpStep2Alert');
            const originalBtnHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Verifying...`;

            try {
                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('action', 'forgot_password_verify');
                formData.append('identifier', fpCurrentIdentifier);
                formData.append('code', code);

                const res = await fetch('auth_action.php?action=forgot_password_verify', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (data.success) {
                    fpVerifiedCode = code;

                    // Transition to Step 3 (Set New Password)
                    document.getElementById('fpStep2').style.display = 'none';
                    document.getElementById('fpStep3').style.display = 'block';
                    setFpHeader('fa-lock', 'Set New Password', 'Create your new secure password');
                    hideFpAlert('fpStep3Alert');
                    setTimeout(() => document.getElementById('fpNewPassword').focus(), 150);
                } else {
                    showFpAlert('fpStep2Alert', 'error', data.message || 'Invalid verification code. Please check your code or request a new one.');
                }
            } catch (err) {
                showFpAlert('fpStep2Alert', 'error', 'Network error. Please try again.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalBtnHtml;
            }
        }

        // Real-time Password Criteria validation for Step 3 (Requirement 1)
        function validateFpPasswordCriteria() {
            const newPassword = document.getElementById('fpNewPassword')?.value || '';
            const confirmPassword = document.getElementById('fpConfirmPassword')?.value || '';
            const newEl = document.getElementById('fpNewPassword');
            const confEl = document.getElementById('fpConfirmPassword');

            const hasLength = newPassword.length >= 8;
            const hasUpper = /[A-Z]/.test(newPassword);
            const hasLower = /[a-z]/.test(newPassword);
            const hasNumber = /[0-9]/.test(newPassword);
            const hasSpecial = /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?~`]/.test(newPassword);
            const isMatch = (newPassword !== '' && confirmPassword !== '' && newPassword === confirmPassword);

            function updateItem(id, passed) {
                const el = document.getElementById(id);
                if (!el) return;
                const icon = el.querySelector('i');
                if (passed) {
                    el.style.color = '#065f46';
                    el.style.fontWeight = '600';
                    if (icon) {
                        icon.className = 'fa-solid fa-circle-check';
                        icon.style.color = '#10b981';
                    }
                } else {
                    el.style.color = '#64748b';
                    el.style.fontWeight = '400';
                    if (icon) {
                        icon.className = 'fa-solid fa-circle-xmark';
                        icon.style.color = '#cbd5e1';
                    }
                }
            }

            updateItem('fpCritLength', hasLength);
            updateItem('fpCritUpper', hasUpper);
            updateItem('fpCritLower', hasLower);
            updateItem('fpCritNumber', hasNumber);
            updateItem('fpCritSpecial', hasSpecial);
            updateItem('fpCritMatch', isMatch);

            let score = 0;
            if (hasLength) score++;
            if (hasUpper) score++;
            if (hasLower) score++;
            if (hasNumber) score++;
            if (hasSpecial) score++;
            if (isMatch) score++;

            const bar = document.getElementById('fpStrengthBar');
            const pill = document.getElementById('fpStrengthPill');
            if (bar && pill) {
                if (newPassword.length === 0) {
                    bar.style.width = '0%';
                    bar.style.background = '#ef4444';
                    pill.textContent = 'Enter password';
                    pill.style.background = '#e2e8f0';
                    pill.style.color = '#475569';
                } else if (score <= 2) {
                    bar.style.width = '25%';
                    bar.style.background = '#ef4444';
                    pill.textContent = 'Weak';
                    pill.style.background = '#fee2e2';
                    pill.style.color = '#b91c1c';
                } else if (score <= 4) {
                    bar.style.width = '60%';
                    bar.style.background = '#f59e0b';
                    pill.textContent = 'Medium';
                    pill.style.background = '#fef3c7';
                    pill.style.color = '#b45309';
                } else {
                    bar.style.width = '100%';
                    bar.style.background = '#10b981';
                    pill.textContent = 'Strong ✓';
                    pill.style.background = '#d1fae5';
                    pill.style.color = '#065f46';
                }
            }

            const alertBox = document.getElementById('fpLiveMatchAlert');
            const alertIcon = document.getElementById('fpLiveMatchIcon');
            const alertText = document.getElementById('fpLiveMatchText');
            if (alertBox && alertIcon && alertText) {
                if (confirmPassword.length > 0) {
                    alertBox.style.display = 'flex';
                    if (newPassword !== confirmPassword) {
                        alertBox.style.color = '#dc2626';
                        alertIcon.className = 'fa-solid fa-circle-xmark';
                        alertIcon.style.color = '#dc2626';
                        alertText.textContent = 'Passwords do not match';
                        if (confEl) confEl.style.borderColor = '#ef4444';
                    } else {
                        alertBox.style.color = '#059669';
                        alertIcon.className = 'fa-solid fa-circle-check';
                        alertIcon.style.color = '#10b981';
                        alertText.textContent = 'Passwords match!';
                        if (confEl) confEl.style.borderColor = '#10b981';
                        if (newEl) newEl.style.borderColor = '#10b981';
                    }
                } else {
                    alertBox.style.display = 'none';
                    if (confEl) confEl.style.borderColor = '#d1d5db';
                }
            }
        }

        // STEP 3: Re-read & Reset Password (Requirement 4 & 5)
        async function submitPasswordReset() {
            const newPassword = document.getElementById('fpNewPassword').value;
            const confirmPassword = document.getElementById('fpConfirmPassword').value;
            const btn = document.getElementById('btnResetPassword');

            hideFpAlert('fpStep3Alert');
            clearPasswordErrorHighlights();

            if (!newPassword || !confirmPassword) {
                showFpAlert('fpStep3Alert', 'error', 'Please fill in both the new password and confirm password fields.');
                return;
            }

            if (newPassword.length < 6) {
                showFpAlert('fpStep3Alert', 'error', 'New password must be at least 6 characters long.');
                document.getElementById('fpNewPassword').style.borderColor = '#ef4444';
                return;
            }

            // Requirement 5: Re-read input: if not identical, give warning notice to repeat
            if (newPassword !== confirmPassword) {
                showFpAlert('fpStep3Alert', 'warning', '⚠️ Warning Notice: New Password and Confirm Password do not match. Please re-enter both fields carefully to repeat.');
                document.getElementById('fpNewPassword').style.borderColor = '#ef4444';
                document.getElementById('fpConfirmPassword').style.borderColor = '#ef4444';
                document.getElementById('fpConfirmPassword').focus();
                return;
            }

            const originalBtnHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Saving Password...`;

            try {
                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('action', 'forgot_password_reset');
                formData.append('new_password', newPassword);
                formData.append('confirm_password', confirmPassword);
                formData.append('code', fpVerifiedCode);

                const res = await fetch('auth_action.php?action=forgot_password_reset', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (data.success) {
                    // Transition to Step 4 (Success celebration)
                    document.getElementById('fpStep3').style.display = 'none';
                    document.getElementById('fpStep4').style.display = 'block';
                    setFpHeader('fa-circle-check', 'Password Reset Complete', 'You are ready to log in');

                    // Pre-populate login form with identifier
                    const mainIdInput = document.querySelector('input[name="identifier"]');
                    if (mainIdInput && fpCurrentIdentifier) {
                        mainIdInput.value = fpCurrentIdentifier;
                    }
                } else {
                    showFpAlert('fpStep3Alert', 'error', data.message || 'Failed to update password. Please try again.');
                }
            } catch (err) {
                showFpAlert('fpStep3Alert', 'error', 'Network error while updating password.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalBtnHtml;
            }
        }

        function finishForgotPassword() {
            closeForgotPasswordModal();
            const pwInput = document.getElementById('passwordInput');
            if (pwInput) {
                pwInput.value = '';
                pwInput.focus();
            }
        }

        // Global backdrop and escape handlers for forgot password modal
        window.addEventListener('click', function(e) {
            const m = document.getElementById('forgotPasswordModal');
            if (m && e.target === m) closeForgotPasswordModal();
        });
        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeForgotPasswordModal();
        });

        // Live Lockout Countdown & Automatic Re-enabling (Zero Reload Required)
        (function() {
            let retryAfter = <?= (int)($retry_after ?? 0) ?>;
            const isLocked = <?= $is_locked ? 'true' : 'false' ?>;

            if (isLocked && retryAfter > 0) {
                const countdownEl = document.getElementById('lockCountdownDisplay');
                const alertBox = document.getElementById('loginAlertBox');
                const alertMsg = document.getElementById('loginAlertMessage');
                const alertIcon = document.getElementById('loginAlertIcon');
                const idInput = document.getElementById('identifierInput');
                const passInput = document.getElementById('passwordInput');
                const submitBtn = document.getElementById('loginSubmitBtn');

                function formatTime(sec) {
                    const m = Math.floor(sec / 60);
                    const s = sec % 60;
                    return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
                }

                const timer = setInterval(function() {
                    retryAfter--;
                    if (retryAfter > 0) {
                        if (countdownEl) {
                            countdownEl.textContent = formatTime(retryAfter);
                        }
                    } else {
                        clearInterval(timer);
                        // Re-enable form inputs & button at 0 without a page reload
                        if (idInput) idInput.disabled = false;
                        if (passInput) passInput.disabled = false;
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.style.opacity = '1';
                            submitBtn.style.cursor = 'pointer';
                        }
                        if (alertBox && alertMsg) {
                            alertBox.style.background = '#f0fdf4';
                            alertBox.style.borderColor = '#bbf7d0';
                            alertBox.style.boxShadow = '0 4px 12px rgba(22, 163, 74, 0.08)';
                            if (alertIcon) {
                                alertIcon.style.background = '#dcfce7';
                                alertIcon.style.color = '#16a34a';
                                alertIcon.innerHTML = '<i class="fa-solid fa-lock-open"></i>';
                            }
                            alertMsg.style.color = '#166534';
                            alertMsg.textContent = 'Lockout has expired. You may attempt to log in now.';
                        }
                        if (idInput) idInput.focus();
                    }
                }, 1000);
            }
        })();
    </script>
</body>
</html>
