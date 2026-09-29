<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        echo json_encode(['success' => false, 'message' => 'CSRF validation failed. Please refresh the page.']);
        exit;
    }

    if ($action === 'login') {
        $identifier = trim($_POST['identifier'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($identifier) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Please enter both username/email and password.']);
            exit;
        }

        $stmt = $conn->prepare("SELECT * FROM users WHERE (email = ? OR username = ? OR (role = 'admin' AND ? = 'admin')) LIMIT 1");
        $stmt->bind_param("sss", $identifier, $identifier, $identifier);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 1) {
            $user = $res->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                if ($user['status'] === 'approved') {
                    session_regenerate_id(true);

                    $_SESSION['id'] = (int)$user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'] ?? 'user';
                    $_SESSION['full_name'] = !empty($user['full_name']) ? $user['full_name'] : $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['phone'] = $user['phone'] ?? '';

                    $redirect = ($user['role'] === 'admin' || $user['role'] === 'super_admin') ? 'a_home.php' : 'index.php';

                    echo json_encode([
                        'success' => true,
                        'message' => 'Login successful!',
                        'user' => [
                            'id' => $_SESSION['id'],
                            'username' => $_SESSION['username'],
                            'full_name' => $_SESSION['full_name'],
                            'email' => $_SESSION['email'],
                            'phone' => $_SESSION['phone'],
                            'role' => $_SESSION['role']
                        ],
                        'redirect' => $redirect
                    ]);
                    exit;
                } else {
                    echo json_encode(['success' => false, 'message' => 'Your account status is: ' . $user['status'] . '. Please contact support.']);
                    exit;
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Incorrect password. Please try again.']);
                exit;
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'No account found matching this username or email.']);
            exit;
        }
    }

    if ($action === 'google_login') {
        $email = trim($_POST['email'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid Google email address.']);
            exit;
        }

        if (empty($full_name)) {
            $parts = explode('@', $email);
            $full_name = ucwords(str_replace(['.', '_', '-'], ' ', $parts[0]));
        }

        // Check if user already exists with this email
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 1) {
            $user = $res->fetch_assoc();
            if ($user['status'] !== 'approved') {
                echo json_encode(['success' => false, 'message' => 'Your account status is: ' . $user['status'] . '.']);
                exit;
            }

            session_regenerate_id(true);
            $_SESSION['id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'] ?? 'user';
            $_SESSION['full_name'] = !empty($user['full_name']) ? $user['full_name'] : ($full_name ?: $user['username']);
            $_SESSION['email'] = $user['email'];
            $_SESSION['phone'] = $user['phone'] ?? '';

            $redirect = ($user['role'] === 'admin' || $user['role'] === 'super_admin') ? 'a_home.php' : 'index.php';

            echo json_encode([
                'success' => true,
                'message' => 'Signed in with Google successfully!',
                'user' => [
                    'id' => $_SESSION['id'],
                    'username' => $_SESSION['username'],
                    'full_name' => $_SESSION['full_name'],
                    'email' => $_SESSION['email'],
                    'phone' => $_SESSION['phone'],
                    'role' => $_SESSION['role']
                ],
                'redirect' => $redirect
            ]);
            exit;
        } else {
            // Auto-register new user via Google
            $username_base = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $email)[0]));
            if (empty($username_base)) $username_base = 'googleuser';
            $username = $username_base;

            $check_u = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $check_u->bind_param("s", $username);
            $check_u->execute();
            if ($check_u->get_result()->num_rows > 0) {
                $username = $username_base . rand(100, 999);
            }
            $check_u->close();

            $dummy_pass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            $role = 'user';
            $status = 'approved';

            $insert = $conn->prepare("INSERT INTO users (username, full_name, email, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $insert->bind_param("ssssss", $username, $full_name, $email, $dummy_pass, $role, $status);

            if ($insert->execute()) {
                $new_id = (int)$insert->insert_id;
                $insert->close();

                session_regenerate_id(true);
                $_SESSION['id'] = $new_id;
                $_SESSION['username'] = $username;
                $_SESSION['role'] = 'user';
                $_SESSION['full_name'] = $full_name;
                $_SESSION['email'] = $email;
                $_SESSION['phone'] = '';

                echo json_encode([
                    'success' => true,
                    'message' => 'Connected with Google account successfully!',
                    'user' => [
                        'id' => $new_id,
                        'username' => $username,
                        'full_name' => $full_name,
                        'email' => $email,
                        'phone' => '',
                        'role' => 'user'
                    ],
                    'redirect' => 'index.php'
                ]);
                exit;
            } else {
                echo json_encode(['success' => false, 'message' => 'Could not connect account: ' . $insert->error]);
                exit;
            }
        }
    }

    if ($action === 'register') {
        $full_name = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($full_name) || empty($username) || empty($email) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
            exit;
        }

        if (strlen($password) < 6) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters long.']);
            exit;
        }

        if ($password !== $confirm_password) {
            echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
            exit;
        }

        // Check if username or email is already taken
        $check = $conn->prepare("SELECT id, username, email FROM users WHERE username = ? OR email = ? LIMIT 1");
        $check->bind_param("ss", $username, $email);
        $check->execute();
        $check_res = $check->get_result();

        if ($check_res->num_rows > 0) {
            $existing = $check_res->fetch_assoc();
            $check->close();
            if (strcasecmp($existing['email'], $email) === 0) {
                echo json_encode(['success' => false, 'message' => 'This email address is already registered. Please log in instead.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'This username is already taken. Please choose another one.']);
            }
            exit;
        }
        $check->close();

        // Hash password and insert
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $role = 'user';
        $status = 'approved';

        $insert = $conn->prepare("INSERT INTO users (username, full_name, email, phone, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        if (!$insert) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            exit;
        }

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

            echo json_encode([
                'success' => true,
                'message' => 'Account registered successfully!',
                'user' => [
                    'id' => $new_id,
                    'username' => $username,
                    'full_name' => $full_name,
                    'email' => $email,
                    'phone' => $phone,
                    'role' => 'user'
                ]
            ]);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create account: ' . $insert->error]);
            exit;
        }
    }

    // ============================================================
    // FORGOT PASSWORD STEP 1: REQUEST VERIFICATION CODE
    // ============================================================
    if ($action === 'forgot_password_request') {
        require_once __DIR__ . '/notification_helper.php';

        $identifier = trim($_POST['identifier'] ?? '');
        if (empty($identifier)) {
            echo json_encode(['success' => false, 'message' => 'Please enter your registered email address or username.']);
            exit;
        }

        $stmt = $conn->prepare("SELECT id, username, full_name, email, status FROM users WHERE (email = ? OR username = ?) LIMIT 1");
        $stmt->bind_param("ss", $identifier, $identifier);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows === 1) {
            $user = $res->fetch_assoc();
            $stmt->close();

            if ($user['status'] !== 'approved') {
                echo json_encode(['success' => false, 'message' => 'This account is currently ' . htmlspecialchars($user['status']) . '. Please contact support.']);
                exit;
            }

            // Generate 6-digit numeric verification code
            $code = sprintf("%06d", random_int(100000, 999999));

            // Set expiration to 15 minutes from now
            $update = $conn->prepare("UPDATE users SET reset_code = ?, reset_expires_at = (NOW() + INTERVAL '15 minutes') WHERE id = ?");
            $uid = (int)$user['id'];
            $update->bind_param("si", $code, $uid);
            $update->execute();
            $update->close();

            // Mask email for user preview (e.g., d***e@gmail.com)
            $email = $user['email'];
            $at_pos = strpos($email, '@');
            if ($at_pos > 2) {
                $masked_email = substr($email, 0, 1) . str_repeat('*', max(1, $at_pos - 2)) . substr($email, $at_pos - 1);
            } else {
                $masked_email = $email;
            }

            $user_name = !empty($user['full_name']) ? $user['full_name'] : $user['username'];
            $email_sent = NotificationHelper::sendPasswordResetCodeEmail($conn, $email, $user_name, $code);

            if ($email_sent) {
                echo json_encode([
                    'success' => true,
                    'message' => "A 6-digit verification code has been dispatched to {$masked_email}.",
                    'masked_email' => $masked_email,
                    'identifier' => $user['username']
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to dispatch verification email via SMTP. Please verify mail configuration or try again.'
                ]);
            }
            exit;
        } else {
            if ($stmt) $stmt->close();
            echo json_encode([
                'success' => false,
                'message' => 'No active account found with that email address or username.'
            ]);
            exit;
        }
    }

    // ============================================================
    // FORGOT PASSWORD STEP 2: VERIFY 6-DIGIT CODE
    // ============================================================
    if ($action === 'forgot_password_verify') {
        $identifier = trim($_POST['identifier'] ?? '');
        $code = trim($_POST['code'] ?? '');

        if (empty($identifier) || empty($code)) {
            echo json_encode(['success' => false, 'message' => 'Please enter both your account identifier and the 6-digit verification code.']);
            exit;
        }

        $stmt = $conn->prepare("SELECT id, username, email, reset_code, reset_expires_at FROM users WHERE (email = ? OR username = ?) LIMIT 1");
        $stmt->bind_param("ss", $identifier, $identifier);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows === 1) {
            $user = $res->fetch_assoc();
            $stmt->close();

            $saved_code = trim($user['reset_code'] ?? '');
            $expires_at = $user['reset_expires_at'] ? strtotime($user['reset_expires_at']) : 0;

            if (empty($saved_code) || $saved_code !== $code) {
                echo json_encode(['success' => false, 'message' => 'The verification code entered is incorrect. Please re-check your email.']);
                exit;
            }

            if (time() > $expires_at) {
                echo json_encode(['success' => false, 'message' => 'This verification code has expired (15-minute limit). Please request a new code.']);
                exit;
            }

            // Code is valid! Save verified session token
            $_SESSION['pw_reset_user_id'] = (int)$user['id'];
            $_SESSION['pw_reset_code'] = $code;

            echo json_encode([
                'success' => true,
                'message' => 'Verification code confirmed! You may now set your new password.'
            ]);
            exit;
        } else {
            if ($stmt) $stmt->close();
            echo json_encode(['success' => false, 'message' => 'Account not found. Please try again.']);
            exit;
        }
    }

    // ============================================================
    // FORGOT PASSWORD STEP 3: RE-READ & SET NEW PASSWORD
    // ============================================================
    if ($action === 'forgot_password_reset') {
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $code = trim($_POST['code'] ?? ($_SESSION['pw_reset_code'] ?? ''));
        $user_id = (int)($_SESSION['pw_reset_user_id'] ?? 0);

        if (empty($new_password) || empty($confirm_password)) {
            echo json_encode(['success' => false, 'message' => 'Please fill in both the new password and confirmation password fields.']);
            exit;
        }

        // Requirement 5: Re-read input: if not identical, give warning notice to repeat
        if ($new_password !== $confirm_password) {
            echo json_encode([
                'success' => false,
                'message' => 'Warning: New Password and Confirm Password do not match. Please re-enter both fields to repeat.'
            ]);
            exit;
        }

        if (strlen($new_password) < 6) {
            echo json_encode([
                'success' => false,
                'message' => 'Password must be at least 6 characters long.'
            ]);
            exit;
        }

        if ($user_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Your verification session has expired. Please restart the password reset process.']);
            exit;
        }

        // Verify that the code matches the user in DB
        $stmt = $conn->prepare("SELECT id, reset_code, reset_expires_at FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows === 1) {
            $user = $res->fetch_assoc();
            $stmt->close();

            $saved_code = trim($user['reset_code'] ?? '');
            $expires_at = $user['reset_expires_at'] ? strtotime($user['reset_expires_at']) : 0;

            if (empty($saved_code) || $saved_code !== $code || time() > $expires_at) {
                echo json_encode(['success' => false, 'message' => 'Invalid or expired reset session. Please request a new verification code.']);
                exit;
            }

            // Update user password & clear reset code
            $hashed = password_hash($new_password, PASSWORD_BCRYPT);
            $update = $conn->prepare("UPDATE users SET password = ?, reset_code = NULL, reset_expires_at = NULL, must_change_password = FALSE WHERE id = ?");
            $update->bind_param("si", $hashed, $user_id);
            $update->execute();
            $update->close();

            // Clear session flags
            unset($_SESSION['pw_reset_user_id'], $_SESSION['pw_reset_code']);

            echo json_encode([
                'success' => true,
                'message' => 'Password changed successfully! You can now sign in with your new password.'
            ]);
            exit;
        } else {
            if ($stmt) $stmt->close();
            echo json_encode(['success' => false, 'message' => 'User account not found.']);
            exit;
        }
    }
}

if ($action === 'my_bookings') {
    if (empty($_SESSION['id'])) {
        echo json_encode(['success' => false, 'message' => 'Not logged in.']);
        exit;
    }

    $uid = (int)$_SESSION['id'];
    $uemail = $_SESSION['email'] ?? '';

    $stmt = $conn->prepare("SELECT id, reference_no, event_title, event_type, event_start, event_end, location_venue, guest_count, status, created_at FROM bookings WHERE user_id = ? OR (client_email = ? AND ? != '') ORDER BY event_start DESC");
    $stmt->bind_param("iss", $uid, $uemail, $uemail);
    $stmt->execute();
    $res = $stmt->get_result();

    $bookings = [];
    while ($row = $res->fetch_assoc()) {
        $bookings[] = $row;
    }
    $stmt->close();

    echo json_encode(['success' => true, 'bookings' => $bookings]);
    exit;
}

if ($action === 'check') {
    if (isset($_SESSION['id'])) {
        echo json_encode([
            'logged_in' => true,
            'user' => [
                'id' => $_SESSION['id'],
                'username' => $_SESSION['username'] ?? '',
                'full_name' => $_SESSION['full_name'] ?? ($_SESSION['username'] ?? ''),
                'email' => $_SESSION['email'] ?? '',
                'phone' => $_SESSION['phone'] ?? '',
                'role' => $_SESSION['role'] ?? 'user'
            ]
        ]);
    } else {
        echo json_encode(['logged_in' => false]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
