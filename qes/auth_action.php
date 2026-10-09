<?php
if (!defined('PHPUNIT_RUNNING') && !headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

/**
 * Server-side Google ID Token Verification
 * Validates token signature, expiration, and extracts verified claims directly from Google.
 * NEVER trusts raw email submitted directly by browser.
 */
if (!function_exists('qes_verify_google_token')) {
    function qes_verify_google_token(string $id_token): array {
        $token = trim($id_token);
        if (empty($token)) {
            return ['valid' => false, 'email' => '', 'name' => '', 'error' => 'Google identity token is missing.'];
        }

        // Mock test token support for automated offline test suites
        if (defined('QES_TESTING') && str_starts_with($token, 'test_mock_token:')) {
            $mock_email = substr($token, strlen('test_mock_token:'));
            return ['valid' => true, 'email' => strtolower(trim($mock_email)), 'name' => 'Test User', 'error' => ''];
        }

        // Structural check: Valid JWT has 3 dot-separated base64 segments
        $jwt_parts = explode('.', $token);
        if (count($jwt_parts) !== 3) {
            return ['valid' => false, 'email' => '', 'name' => '', 'error' => 'Invalid Google token structure.'];
        }

        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($token);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $ca_bundle = 'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt';
        if (file_exists($ca_bundle)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca_bundle);
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($curl_err)) {
            error_log('[QES Google Auth] cURL error connecting to Google tokeninfo: ' . $curl_err);
            return ['valid' => false, 'email' => '', 'name' => '', 'error' => 'Unable to verify token with Google identity services.'];
        }

        $data = json_decode($response, true);
        if ($http_code !== 200 || !is_array($data) || !empty($data['error'])) {
            $err_desc = $data['error_description'] ?? ($data['error'] ?? 'Token validation rejected by Google');
            error_log('[QES Google Auth] Google rejected token: ' . $err_desc);
            return ['valid' => false, 'email' => '', 'name' => '', 'error' => 'Google verification rejected: ' . $err_desc];
        }

        $iss = $data['iss'] ?? '';
        if ($iss !== 'accounts.google.com' && $iss !== 'https://accounts.google.com') {
            return ['valid' => false, 'email' => '', 'name' => '', 'error' => 'Invalid token issuer.'];
        }

        // Audience verification: Ensure token was issued specifically for this application
        if (defined('ENV_GOOGLE_CLIENT_ID') && !empty(ENV_GOOGLE_CLIENT_ID)) {
            $aud = $data['aud'] ?? '';
            if ($aud !== ENV_GOOGLE_CLIENT_ID) {
                error_log('[QES Google Auth] Token aud mismatch.');
                return ['valid' => false, 'email' => '', 'name' => '', 'error' => 'Token audience mismatch.'];
            }
        }

        $email = strtolower(trim($data['email'] ?? ''));
        $email_verified = filter_var($data['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['valid' => false, 'email' => '', 'name' => '', 'error' => 'Token did not provide a valid email address.'];
        }

        if (!$email_verified) {
            return ['valid' => false, 'email' => '', 'name' => '', 'error' => 'Google email address is not verified.'];
        }

        $name = trim($data['name'] ?? ($data['given_name'] ?? ''));
        return [
            'valid' => true,
            'email' => $email,
            'name' => $name,
            'error' => ''
        ];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // CSRF check
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        echo json_encode(['success' => false, 'message' => 'CSRF validation failed. Please refresh the page.']);
        exit;
    }

    // ============================================================
    // LOGIN ACTION (Standard Username/Email & Password)
    // ============================================================
    if ($action === 'login') {
        $identifier = trim($_POST['identifier'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($identifier) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Please enter both username/email and password.']);
            exit;
        }

        // Rate limiting: 5 failed attempts per 15 minutes per IP + identifier
        $client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $rate_key = $client_ip . '|' . strtolower($identifier);
        $rate_check = qes_rate_limit_check('user_login', $rate_key, 5, 900);

        if (!$rate_check['allowed']) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => $rate_check['message']]);
            exit;
        }

        $stmt = $conn->prepare("SELECT * FROM users WHERE (LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?)) LIMIT 1");
        $stmt->bind_param("ss", $identifier, $identifier);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows === 1) {
            $user = $res->fetch_assoc();
            $stmt->close();

            if (password_verify($password, $user['password'])) {
                if ($user['status'] === 'approved') {
                    // Reset rate limiting counter upon success
                    qes_rate_limit_clear('user_login', $rate_key);

                    // Prevent session fixation
                    session_regenerate_id(true);

                    $_SESSION['id'] = (int)$user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'] ?? 'user';
                    $_SESSION['full_name'] = !empty($user['full_name']) ? $user['full_name'] : $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['phone'] = $user['phone'] ?? '';

                    // Track device & active session
                    if (function_exists('track_user_session')) {
                        track_user_session($conn, (int)$user['id'], true);
                    }

                    $redirect = ($user['role'] === 'admin' || $user['role'] === 'super_admin' || $user['role'] === 'main_admin') ? 'a_home.php' : 'index.php';

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
                    echo json_encode(['success' => false, 'message' => 'Your account status is: ' . htmlspecialchars($user['status']) . '. Please contact support.']);
                    exit;
                }
            } else {
                qes_rate_limit_record_fail('user_login', $rate_key, 5, 900);
                // Prevent account enumeration with generic message
                echo json_encode(['success' => false, 'message' => 'Invalid username/email or password.']);
                exit;
            }
        } else {
            if ($stmt) $stmt->close();
            if (!empty($conn->error)) {
                error_log('[QES Login] DB error: ' . $conn->error);
                echo json_encode(['success' => false, 'message' => 'Database service temporarily unavailable. Please try again shortly.']);
                exit;
            }
            qes_rate_limit_record_fail('user_login', $rate_key, 5, 900);
            // Prevent account enumeration with generic message
            echo json_encode(['success' => false, 'message' => 'Invalid username/email or password.']);
            exit;
        }
    }

    // ============================================================
    // GOOGLE LOGIN ACTION (Cryptographically Verified Server-Side)
    // ============================================================
    if ($action === 'google_login') {
        $client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $rate_check = qes_rate_limit_check('google_login', $client_ip, 10, 600);
        if (!$rate_check['allowed']) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => $rate_check['message']]);
            exit;
        }

        // A genuine Google login MUST supply a verifiable Google identity token
        $token = trim($_POST['credential'] ?? ($_POST['id_token'] ?? ''));

        if (empty($token)) {
            // NEVER trust an email submitted directly by browser
            qes_rate_limit_record_fail('google_login', $client_ip, 10, 600);
            echo json_encode([
                'success' => false,
                'message' => 'Google identity verification token required. Untrusted email submission is rejected.'
            ]);
            exit;
        }

        $verification = qes_verify_google_token($token);
        if (!$verification['valid']) {
            qes_rate_limit_record_fail('google_login', $client_ip, 10, 600);
            echo json_encode([
                'success' => false,
                'message' => 'Google verification failed: ' . $verification['error']
            ]);
            exit;
        }

        // Verified email from Google token payload — NEVER trust browser input
        $email = $verification['email'];
        $full_name = !empty($verification['name']) ? $verification['name'] : '';
        if (empty($full_name)) {
            $parts = explode('@', $email);
            $full_name = ucwords(str_replace(['.', '_', '-'], ' ', $parts[0]));
        }

        // Check if user already exists with this verified email
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows === 1) {
            $user = $res->fetch_assoc();
            $stmt->close();

            if ($user['status'] !== 'approved') {
                echo json_encode(['success' => false, 'message' => 'Your account status is: ' . htmlspecialchars($user['status']) . '.']);
                exit;
            }

            // Defense-in-depth: Administrative accounts must NOT authenticate via public consumer Google button
            if (in_array($user['role'], ['admin', 'super_admin', 'main_admin'])) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Administrator accounts must sign in via the Admin Portal with administrative credentials.'
                ]);
                exit;
            }

            qes_rate_limit_clear('google_login', $client_ip);
            session_regenerate_id(true);

            $_SESSION['id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = 'user';
            $_SESSION['full_name'] = !empty($user['full_name']) ? $user['full_name'] : ($full_name ?: $user['username']);
            $_SESSION['email'] = $user['email'];
            $_SESSION['phone'] = $user['phone'] ?? '';

            if (function_exists('track_user_session')) {
                track_user_session($conn, (int)$user['id'], true);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Signed in with Google successfully!',
                'user' => [
                    'id' => $_SESSION['id'],
                    'username' => $_SESSION['username'],
                    'full_name' => $_SESSION['full_name'],
                    'email' => $_SESSION['email'],
                    'phone' => $_SESSION['phone'],
                    'role' => 'user'
                ],
                'redirect' => 'index.php'
            ]);
            exit;
        } else {
            if ($stmt) $stmt->close();

            // Auto-register new client user verified via Google
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

            $dummy_pass = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $role = 'user';
            $status = 'approved';

            $insert = $conn->prepare("INSERT INTO users (username, full_name, email, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $insert->bind_param("ssssss", $username, $full_name, $email, $dummy_pass, $role, $status);

            if ($insert->execute()) {
                $new_id = (int)$insert->insert_id;
                $insert->close();

                qes_rate_limit_clear('google_login', $client_ip);
                session_regenerate_id(true);

                $_SESSION['id'] = $new_id;
                $_SESSION['username'] = $username;
                $_SESSION['role'] = 'user';
                $_SESSION['full_name'] = $full_name;
                $_SESSION['email'] = $email;
                $_SESSION['phone'] = '';

                if (function_exists('track_user_session')) {
                    track_user_session($conn, $new_id, true);
                }

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
                error_log('[QES Google Auth] Registration insert failed: ' . $insert->error);
                echo json_encode(['success' => false, 'message' => 'Could not create account due to a database error. Please try again shortly.']);
                exit;
            }
        }
    }

    // ============================================================
    // REGISTRATION ACTION (Public Client Registration)
    // ============================================================
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

        if ($check_res && $check_res->num_rows > 0) {
            $existing = $check_res->fetch_assoc();
            $check->close();
            if (strcasecmp($existing['email'], $email) === 0) {
                echo json_encode(['success' => false, 'message' => 'This email address is already registered. Please log in instead.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'This username is already taken. Please choose another one.']);
            }
            exit;
        }
        if ($check) $check->close();

        // Hash password and insert
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $role = 'user';
        $status = 'approved';

        $insert = $conn->prepare("INSERT INTO users (username, full_name, email, phone, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        if (!$insert) {
            error_log('[QES Register] Prepare error: ' . $conn->error);
            echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again shortly.']);
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

            if (function_exists('track_user_session')) {
                track_user_session($conn, $new_id, true);
            }

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
            error_log('[QES Register] Insert execute error: ' . $insert->error);
            echo json_encode(['success' => false, 'message' => 'Failed to create account due to a database error. Please try again shortly.']);
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

        // Rate limit password reset requests: at most 3 requests per 10 minutes per IP/identifier
        $client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $rate_key = $client_ip . '|' . strtolower($identifier);
        $rate_check = qes_rate_limit_check('pw_reset_request', $rate_key, 3, 600);

        if (!$rate_check['allowed']) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => $rate_check['message']]);
            exit;
        }

        // Smart User Resolution: Case-insensitive, Token match, and Typo tolerance
        if (!function_exists('qes_find_user_for_auth')) {
            function qes_find_user_for_auth($conn, string $raw_identifier): ?array {
                $id_trim = trim($raw_identifier);
                if (empty($id_trim)) return null;

                // 1. Direct exact case-insensitive match on email, username, or full_name
                $stmt = $conn->prepare("SELECT id, username, full_name, email, status FROM users WHERE (LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?) OR LOWER(full_name) = LOWER(?)) LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param("sss", $id_trim, $id_trim, $id_trim);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res && $res->num_rows === 1) {
                        $u = $res->fetch_assoc();
                        $stmt->close();
                        return $u;
                    }
                    $stmt->close();
                }

                // 2. Fetch active approved accounts for token & typo-tolerant matching
                $stmt = $conn->prepare("SELECT id, username, full_name, email, status FROM users WHERE status = 'approved'");
                if (!$stmt) return null;
                $stmt->execute();
                $all = $stmt->get_result();
                $candidates = [];
                while ($row = $all->fetch_assoc()) {
                    $candidates[] = $row;
                }
                $stmt->close();

                $clean_input = preg_replace('/[^a-z0-9]/', '', strtolower($id_trim));
                $input_words = array_filter(explode(' ', strtolower(preg_replace('/[^a-z0-9 ]/', ' ', $id_trim))));

                foreach ($candidates as $row) {
                    $clean_full = preg_replace('/[^a-z0-9]/', '', strtolower($row['full_name'] ?? ''));
                    if (!empty($clean_full) && !empty($clean_input)) {
                        if ($clean_full === $clean_input || str_contains($clean_full, $clean_input) || str_contains($clean_input, $clean_full)) {
                            return $row;
                        }
                    }
                    if (count($input_words) >= 2) {
                        $fn_lower = strtolower($row['full_name'] ?? '');
                        $all_present = true;
                        foreach ($input_words as $w) {
                            if (!str_contains($fn_lower, $w)) {
                                $all_present = false;
                                break;
                            }
                        }
                        if ($all_present) {
                            return $row;
                        }
                    }
                }

                // 3. Typo-tolerant matching (Levenshtein distance <= 2 on username or email prefix)
                $input_email = strtolower($id_trim);
                $is_email = str_contains($input_email, '@');
                $input_prefix = $is_email ? substr($input_email, 0, strpos($input_email, '@')) : $input_email;
                $input_domain = $is_email ? substr(strrchr($input_email, '@'), 1) : '';

                $best_match = null;
                $min_distance = 999;

                foreach ($candidates as $row) {
                    $row_email = strtolower($row['email'] ?? '');
                    $row_uname = strtolower($row['username'] ?? '');
                    $row_domain = str_contains($row_email, '@') ? substr(strrchr($row_email, '@'), 1) : '';
                    $row_prefix = str_contains($row_email, '@') ? substr($row_email, 0, strpos($row_email, '@')) : $row_email;

                    if ($is_email && !empty($input_domain) && !empty($row_domain)) {
                        if ($input_domain !== $row_domain) continue;
                    }

                    $dist1 = levenshtein($input_prefix, $row_prefix);
                    $dist2 = levenshtein($input_prefix, $row_uname);
                    $dist = min($dist1, $dist2);

                    if ($dist <= 2 && strlen($input_prefix) >= 4 && $dist < $min_distance) {
                        $min_distance = $dist;
                        $best_match = $row;
                    }
                }

                return $best_match;
            }
        }

        $user = qes_find_user_for_auth($conn, $identifier);

        if ($user) {
            if ($user['status'] !== 'approved') {
                echo json_encode(['success' => false, 'message' => 'This account is currently ' . htmlspecialchars($user['status']) . '. Please contact support.']);
                exit;
            }

            qes_rate_limit_record_fail('pw_reset_request', $rate_key, 3, 600);

            // Generate cryptographically secure 6-digit numeric verification code
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
            if (!empty($conn->error)) {
                error_log('[QES Reset] DB error: ' . $conn->error);
                echo json_encode([
                    'success' => false,
                    'message' => 'Database service temporarily unavailable. Please try again shortly.'
                ]);
                exit;
            }
            qes_rate_limit_record_fail('pw_reset_request', $rate_key, 3, 600);
            echo json_encode([
                'success' => false,
                'message' => 'No active account found with that email address or username. Please check your spelling or try entering your registered username.'
            ]);
            exit;
        }
    }

    // ============================================================
    // FORGOT PASSWORD STEP 2: VERIFY 6-DIGIT CODE
    // Enforces attempt limits and invalidates code upon successful verification (single-use)
    // ============================================================
    if ($action === 'forgot_password_verify') {
        $identifier = trim($_POST['identifier'] ?? '');
        $code = trim($_POST['code'] ?? '');

        if (empty($identifier) || empty($code)) {
            echo json_encode(['success' => false, 'message' => 'Please enter both your account identifier and the 6-digit verification code.']);
            exit;
        }

        // Rate limit verification attempts: maximum 5 attempts per identifier + IP
        $client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $verify_key = $client_ip . '|' . strtolower($identifier);
        $rate_check = qes_rate_limit_check('pw_verify_code', $verify_key, 5, 900);

        if (!$rate_check['allowed']) {
            // Invalidate code in DB immediately to stop further attacks
            $invalidate = $conn->prepare("UPDATE users SET reset_code = NULL, reset_expires_at = NULL WHERE (LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?))");
            $invalidate->bind_param("ss", $identifier, $identifier);
            $invalidate->execute();
            $invalidate->close();

            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Too many failed verification attempts. This verification code has been invalidated for security. Please request a new code.']);
            exit;
        }

        $stmt = $conn->prepare("SELECT id, username, email, reset_code, reset_expires_at FROM users WHERE (LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?)) LIMIT 1");
        $stmt->bind_param("ss", $identifier, $identifier);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows === 1) {
            $user = $res->fetch_assoc();
            $stmt->close();

            $saved_code = trim($user['reset_code'] ?? '');
            $expires_at = $user['reset_expires_at'] ? strtotime($user['reset_expires_at']) : 0;

            if (empty($saved_code) || !hash_equals($saved_code, $code)) {
                $attempts = qes_rate_limit_record_fail('pw_verify_code', $verify_key, 5, 900);
                $remaining = max(0, 5 - $attempts);
                if ($remaining === 0) {
                    // Lock out & invalidate code immediately
                    $clear_stmt = $conn->prepare("UPDATE users SET reset_code = NULL, reset_expires_at = NULL WHERE id = ?");
                    $uid = (int)$user['id'];
                    $clear_stmt->bind_param("i", $uid);
                    $clear_stmt->execute();
                    $clear_stmt->close();

                    echo json_encode(['success' => false, 'message' => 'Maximum verification attempts exceeded. Code has been invalidated. Please request a new code.']);
                } else {
                    echo json_encode(['success' => false, 'message' => "The verification code entered is incorrect. ({$remaining} attempt(s) remaining)"]);
                }
                exit;
            }

            if (time() > $expires_at) {
                echo json_encode(['success' => false, 'message' => 'This verification code has expired (15-minute limit). Please request a new code.']);
                exit;
            }

            // Code is verified! Make reset code strictly ONE-TIME USE by clearing it immediately from DB
            $uid = (int)$user['id'];
            $clear_code = $conn->prepare("UPDATE users SET reset_code = NULL WHERE id = ?");
            $clear_code->bind_param("i", $uid);
            $clear_code->execute();
            $clear_code->close();

            // Issue a cryptographically random, one-time session token for Step 3
            $session_token = bin2hex(random_bytes(32));
            $_SESSION['pw_reset_user_id'] = $uid;
            $_SESSION['pw_reset_token'] = $session_token;
            $_SESSION['pw_reset_token_expires'] = time() + 900; // 15-minute validity window

            // Clear failed attempts counter
            qes_rate_limit_clear('pw_verify_code', $verify_key);

            echo json_encode([
                'success' => true,
                'message' => 'Verification code confirmed! You may now set your new password.'
            ]);
            exit;
        } else {
            if ($stmt) $stmt->close();
            qes_rate_limit_record_fail('pw_verify_code', $verify_key, 5, 900);
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
        $user_id = (int)($_SESSION['pw_reset_user_id'] ?? 0);
        $reset_token = $_SESSION['pw_reset_token'] ?? '';
        $token_expires = (int)($_SESSION['pw_reset_token_expires'] ?? 0);

        if (empty($new_password) || empty($confirm_password)) {
            echo json_encode(['success' => false, 'message' => 'Please fill in both the new password and confirmation password fields.']);
            exit;
        }

        // Re-read input: if not identical, give warning notice to repeat
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

        // Validate verified session token
        if ($user_id <= 0 || empty($reset_token) || time() > $token_expires) {
            unset($_SESSION['pw_reset_user_id'], $_SESSION['pw_reset_token'], $_SESSION['pw_reset_token_expires']);
            echo json_encode(['success' => false, 'message' => 'Your verification session has expired. Please restart the password reset process.']);
            exit;
        }

        // Verify that the user exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows === 1) {
            $stmt->close();

            // Update user password & clear any remaining reset fields
            $hashed = password_hash($new_password, PASSWORD_BCRYPT);
            $update = $conn->prepare("UPDATE users SET password = ?, reset_code = NULL, reset_expires_at = NULL, must_change_password = FALSE WHERE id = ?");
            $update->bind_param("si", $hashed, $user_id);
            $update->execute();
            $update->close();

            // Invalidate the one-time reset session token immediately
            unset($_SESSION['pw_reset_user_id'], $_SESSION['pw_reset_token'], $_SESSION['pw_reset_token_expires']);
            session_regenerate_id(true);

            echo json_encode([
                'success' => true,
                'message' => 'Password changed successfully! You can now sign in with your new password.'
            ]);
            exit;
        } else {
            if ($stmt) $stmt->close();
            unset($_SESSION['pw_reset_user_id'], $_SESSION['pw_reset_token'], $_SESSION['pw_reset_token_expires']);
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
    $enc_uemail = (!empty($uemail)) ? qes_encrypt($uemail, true) : '';

    $stmt = $conn->prepare("SELECT id, reference_no, event_title, event_type, event_start, event_end, location_venue, guest_count, status, created_at FROM bookings WHERE user_id = ? OR (client_email = ? AND ? != '') OR (client_email = ? AND ? != '') ORDER BY event_start DESC");
    $stmt->bind_param("issss", $uid, $enc_uemail, $enc_uemail, $uemail, $uemail);
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

if (!defined('PHPUNIT_RUNNING')) {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
