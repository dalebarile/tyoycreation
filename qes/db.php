<?php
// Load DB credentials from .env.php (falls back to local dev defaults if not set)
$env_file = __DIR__ . '/.env.php';
if (file_exists($env_file)) {
    require_once $env_file;
}

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/supabase_driver.php';
require_once __DIR__ . '/crypto_helper.php';

// Connect directly to Supabase
$conn = new SupabaseConnection();

// Fallback only if Supabase fails
if ($conn->connect_error) {
    $servername = (defined('ENV_DB_HOST') && ENV_DB_HOST !== 'your_db_host') ? ENV_DB_HOST : "127.0.0.1";
    $username   = (defined('ENV_DB_USER') && ENV_DB_USER !== 'your_db_username') ? ENV_DB_USER : "root";
    $password   = (defined('ENV_DB_PASS') && ENV_DB_PASS !== 'your_db_password') ? ENV_DB_PASS : "";
    $dbname     = (defined('ENV_DB_NAME') && ENV_DB_NAME !== 'your_db_name') ? ENV_DB_NAME : "qe";
    
    $mysql_fallback = @new mysqli($servername, $username, $password, $dbname);
    if (!$mysql_fallback->connect_error) {
        $conn = $mysql_fallback;
    } else {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'reply' => "Our system is temporarily unavailable. Please try again shortly or use the 'Book Now' button to submit your inquiry.",
            'response' => "Our system is temporarily unavailable. Please try again shortly.",
            'error' => 'db_connection_failed'
        ]);
        error_log('Supabase connection failed: ' . $conn->connect_error);
        exit;
    }
}

$conn->set_charset("utf8mb4");

// CSRF Protection Helpers
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'] ?? '';
    }
}

if (!function_exists('get_device_info')) {
    function get_device_info($ua) {
        $platform = 'Unknown Device';
        $device_type = 'desktop';
        $icon = 'fa-desktop';

        if (preg_match('/iphone/i', $ua)) {
            $platform = 'Apple iPhone';
            $device_type = 'mobile';
            $icon = 'fa-mobile-screen-button';
        } elseif (preg_match('/ipad/i', $ua)) {
            $platform = 'Apple iPad';
            $device_type = 'tablet';
            $icon = 'fa-tablet-screen-button';
        } elseif (preg_match('/android/i', $ua)) {
            $platform = 'Android Device';
            $device_type = 'mobile';
            $icon = 'fa-mobile-screen-button';
        } elseif (preg_match('/windows nt 10/i', $ua)) {
            $platform = 'Windows 10/11';
            $icon = 'fa-laptop';
        } elseif (preg_match('/windows/i', $ua)) {
            $platform = 'Windows PC';
            $icon = 'fa-laptop';
        } elseif (preg_match('/macintosh|mac os x/i', $ua)) {
            $platform = 'macOS (MacBook/iMac)';
            $icon = 'fa-laptop';
        } elseif (preg_match('/linux/i', $ua)) {
            $platform = 'Linux System';
            $icon = 'fa-laptop';
        }

        $browser = 'Web Browser';
        if (preg_match('/edg/i', $ua)) {
            $browser = 'Microsoft Edge';
        } elseif (preg_match('/chrome|crios/i', $ua) && !preg_match('/opr|opera/i', $ua)) {
            $browser = 'Google Chrome';
        } elseif (preg_match('/firefox|fxios/i', $ua)) {
            $browser = 'Mozilla Firefox';
        } elseif (preg_match('/safari/i', $ua) && !preg_match('/chrome|crios/i', $ua)) {
            $browser = 'Apple Safari';
        } elseif (preg_match('/opr|opera/i', $ua)) {
            $browser = 'Opera Browser';
        }

        return [
            'name' => "{$platform} • {$browser}",
            'type' => $device_type,
            'icon' => $icon
        ];
    }
}

if (!function_exists('get_user_online_status')) {
    function get_user_online_status($user) {
        $last_seen = !empty($user['last_seen_at']) ? strtotime($user['last_seen_at']) : 0;
        $last_logout = !empty($user['last_logout_at']) ? strtotime($user['last_logout_at']) : 0;
        $last_login = !empty($user['last_login_at']) ? strtotime($user['last_login_at']) : 0;

        $now = time();
        // If user explicitly logged out at or after their last activity
        if ($last_logout > 0 && $last_logout >= $last_seen) {
            return [
                'is_online' => false,
                'status_label' => 'Offline',
                'sub_label' => 'Logged out ' . date('M d, g:i A', $last_logout),
                'color' => '#6b7280',
                'bg' => '#f3f4f6'
            ];
        }

        // Active within the last 5 minutes (300 seconds)
        if ($last_seen > 0 && ($now - $last_seen) <= 300) {
            return [
                'is_online' => true,
                'status_label' => 'Online Now',
                'sub_label' => 'Active just now',
                'color' => '#16a34a',
                'bg' => '#dcfce7'
            ];
        }

        // Offline / Inactive
        if ($last_seen > 0) {
            $diff = $now - $last_seen;
            if ($diff < 3600) {
                $time_str = max(1, round($diff / 60)) . ' mins ago';
            } elseif ($diff < 86400) {
                $time_str = round($diff / 3600) . ' hours ago';
            } else {
                $time_str = date('M d, g:i A', $last_seen);
            }
            return [
                'is_online' => false,
                'status_label' => 'Offline',
                'sub_label' => 'Last seen ' . $time_str,
                'color' => '#6b7280',
                'bg' => '#f3f4f6'
            ];
        }

        return [
            'is_online' => false,
            'status_label' => 'Offline',
            'sub_label' => 'Never logged in',
            'color' => '#9ca3af',
            'bg' => '#f3f4f6'
        ];
    }
}

if (!function_exists('track_user_session')) {
    function track_user_session($conn, $user_id, $is_login = false) {
        if ($user_id <= 0) return;
        $session_id = session_id();
        if (empty($session_id)) return;

        // Throttle session updates: only ping DB on login or if > 60 seconds since last tracked
        $now = time();
        $last_tracked = $_SESSION['last_session_tracked_at'] ?? 0;
        if (!$is_login && ($now - $last_tracked) < 60) {
            return;
        }
        $_SESSION['last_session_tracked_at'] = $now;

        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Browser';
        $device = get_device_info($ua);
        $device_name = $device['name'];
        $device_type = $device['type'];

        // Update user's last_seen_at and last device / IP
        if ($is_login) {
            $u_stmt = $conn->prepare("UPDATE users SET last_login_at = NOW(), last_seen_at = NOW(), last_ip = ?, last_device = ? WHERE id = ?");
            if ($u_stmt) {
                $u_stmt->bind_param("ssi", $ip, $device_name, $user_id);
                $u_stmt->execute();
                $u_stmt->close();
            }
        } else {
            $u_stmt = $conn->prepare("UPDATE users SET last_seen_at = NOW(), last_ip = ?, last_device = ? WHERE id = ?");
            if ($u_stmt) {
                $u_stmt->bind_param("ssi", $ip, $device_name, $user_id);
                $u_stmt->execute();
                $u_stmt->close();
            }
        }

        // Check if this session already exists
        $chk = $conn->prepare("SELECT id FROM user_sessions WHERE user_id = ? AND session_id = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param("is", $user_id, $session_id);
            $chk->execute();
            $res = $chk->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $up = $conn->prepare("UPDATE user_sessions SET ip_address = ?, user_agent = ?, device_name = ?, device_type = ?, is_logged_out = 0, last_activity = NOW() WHERE id = ?");
                $id = (int)$row['id'];
                $up->bind_param("ssssi", $ip, $ua, $device_name, $device_type, $id);
                $up->execute();
                $up->close();
            } else {
                $ins = $conn->prepare("INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, device_name, device_type, is_blocked, is_logged_out, created_at, last_activity) VALUES (?, ?, ?, ?, ?, ?, 0, 0, NOW(), NOW())");
                $ins->bind_param("isssss", $user_id, $session_id, $ip, $ua, $device_name, $device_type);
                $ins->execute();
                $ins->close();
            }
            $chk->close();
        }
    }
}

if (!function_exists('is_current_session_blocked')) {
    function is_current_session_blocked($conn, $user_id) {
        if ($user_id <= 0) return false;
        $session_id = session_id();
        if (empty($session_id)) return false;

        // Throttle block check to once every 30 seconds
        $now = time();
        $last_check = $_SESSION['last_block_check_at'] ?? 0;
        if (($now - $last_check) < 30 && isset($_SESSION['is_device_blocked'])) {
            return (bool)$_SESSION['is_device_blocked'];
        }

        $stmt = $conn->prepare("SELECT is_blocked FROM user_sessions WHERE user_id = ? AND session_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("is", $user_id, $session_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $stmt->close();
                $blocked = ((int)$row['is_blocked'] === 1);
                $_SESSION['last_block_check_at'] = $now;
                $_SESSION['is_device_blocked'] = $blocked;
                return $blocked;
            }
            $stmt->close();
        }
        $_SESSION['last_block_check_at'] = $now;
        $_SESSION['is_device_blocked'] = false;
        return false;
    }
}

if (!function_exists('require_login')) {
    function require_login() {
        global $conn;
        if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin', 'main_admin'])) {
            header("Location: loginadmin.php");
            exit;
        }

        $uid = (int)($_SESSION['id'] ?? 0);
        if ($uid > 0 && isset($conn) && is_object($conn)) {
            if (is_current_session_blocked($conn, $uid)) {
                session_unset();
                session_destroy();
                header("Location: loginadmin.php?error=device_blocked");
                exit;
            }
            track_user_session($conn, $uid);
        }
    }
}

if (!function_exists('is_main_admin')) {
    function is_main_admin() {
        return isset($_SESSION['role']) && in_array($_SESSION['role'], ['main_admin', 'super_admin']);
    }
}

if (!function_exists('require_main_admin')) {
    function require_main_admin() {
        require_login();
        if (!is_main_admin()) {
            header("Location: a_home.php?error=unauthorized");
            exit;
        }
    }
}

if (!function_exists('get_setting')) {
    function get_setting($conn, $key, $default = '') {
        static $settings_cache = null;

        if ($key === '__invalidate_cache__') {
            $settings_cache = null;
            return '';
        }

        if ($settings_cache === null) {
            $settings_cache = [];
            $res = $conn->query("SELECT setting_key, setting_value FROM settings");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $settings_cache[$row['setting_key']] = $row['setting_value'];
                }
            }
        }

        return $settings_cache[$key] ?? $default;
    }
}

if (!function_exists('set_setting')) {
    function set_setting($conn, $key, $value) {
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        if ($stmt) {
            $stmt->bind_param("ss", $key, $value);
            $success = $stmt->execute();
            $stmt->close();
            get_setting($conn, '__invalidate_cache__');
            return $success;
        }
        return false;
    }
}

// ============================================================
// DYNAMIC EVENT ADVANCE-NOTICE RULES & VALIDATION
// ============================================================
if (!function_exists('get_event_advance_notice_rule')) {
    function get_event_advance_notice_rule($event_type) {
        $type_raw = trim($event_type ?? '');
        $t = strtolower($type_raw);
        
        if (strpos($t, 'wedding') !== false) {
            return [
                'type'         => 'Weddings',
                'notice_label' => '6 months',
                'months'       => 6,
                'days'         => 0
            ];
        } elseif (strpos($t, 'kid') !== false || strpos($t, 'birthday') !== false) {
            return [
                'type'         => 'Kids Party',
                'notice_label' => '14 days',
                'months'       => 0,
                'days'         => 14
            ];
        } elseif (strpos($t, 'corporate') !== false) {
            return [
                'type'         => 'Corporate Events',
                'notice_label' => '14 days',
                'months'       => 0,
                'days'         => 14
            ];
        } elseif (strpos($t, 'debut') !== false) {
            return [
                'type'         => 'Debut & Others',
                'notice_label' => '14 days',
                'months'       => 0,
                'days'         => 14
            ];
        }

        $typeName = !empty($type_raw) ? $type_raw : 'Special Events';
        return [
            'type'         => $typeName,
            'notice_label' => '14 days',
            'months'       => 0,
            'days'         => 14
        ];
    }
}

if (!function_exists('calculate_earliest_event_date')) {
    function calculate_earliest_event_date($event_type, $from_date = null) {
        $rule = get_event_advance_notice_rule($event_type);
        $base = ($from_date instanceof DateTime) ? clone $from_date : new DateTime('today');
        $base->setTime(0, 0, 0);

        if ($rule['months'] > 0) {
            $curMonth = (int)$base->format('n');
            $targetMonth = (($curMonth - 1 + $rule['months']) % 12) + 1;
            $base->modify("+{$rule['months']} months");
            if ((int)$base->format('n') !== $targetMonth) {
                $base->modify('last day of previous month');
            }
        } elseif ($rule['days'] > 0) {
            $base->modify("+{$rule['days']} days");
        }
        return ['min_date' => $base, 'rule' => $rule];
    }
}

if (!function_exists('validate_event_booking_date')) {
    function validate_event_booking_date($event_type, $event_date) {
        $dateStr = trim($event_date ?? '');
        if (empty($dateStr)) {
            return ['valid' => false, 'message' => 'Event date is required.'];
        }
        $targetDate = DateTime::createFromFormat('Y-m-d', $dateStr);
        if (!$targetDate) {
            return ['valid' => false, 'message' => 'Invalid event date format (must be YYYY-MM-DD).'];
        }
        $targetDate->setTime(0, 0, 0);

        $calc = calculate_earliest_event_date($event_type);
        $minDate = $calc['min_date'];
        $rule = $calc['rule'];

        if ($targetDate < $minDate) {
            $earliestFormatted = $minDate->format('F j, Y');
            $selectedFormatted = $targetDate->format('F j, Y');
            return [
                'valid' => false,
                'min_date_str' => $minDate->format('Y-m-d'),
                'earliest_formatted' => $earliestFormatted,
                'rule' => $rule,
                'message' => "Advance Notice Violation: Selected date ({$selectedFormatted}) is earlier than the required {$rule['notice_label']} advance notice for {$rule['type']}. Earliest available date is {$earliestFormatted}."
            ];
        }

        return [
            'valid' => true,
            'min_date_str' => $minDate->format('Y-m-d'),
            'earliest_formatted' => $minDate->format('F j, Y'),
            'rule' => $rule
        ];
    }
}

if (!function_exists('check_booking_conflict')) {
    /**
     * Checks if a given event time range overlaps with an existing approved event.
     * Overlap rule: (event_start < target_end AND event_end > target_start)
     * Same date alone is NOT a conflict; only overlapping times are a conflict.
     *
     * @param mysqli|SupabaseConnection $conn
     * @param string $event_start Target event start datetime
     * @param string $event_end Target event end datetime
     * @param int|null $exclude_booking_id Optional booking ID to exclude from conflict check
     * @return array ['conflict' => bool, 'conflicting_event' => array|null, 'message' => string]
     */
    function check_booking_conflict($conn, $event_start, $event_end, $exclude_booking_id = null) {
        $exclude_id = !empty($exclude_booking_id) ? (int)$exclude_booking_id : 0;
        
        // Normalize datetime strings to standard 'YYYY-MM-DD HH:MM:SS' without timezone offsets (+00 / +08)
        $norm_start = substr(trim((string)$event_start), 0, 19);
        $norm_end   = substr(trim((string)$event_end), 0, 19);
        if (strlen($norm_start) < 19) {
            $norm_start = date('Y-m-d H:i:s', strtotime($event_start));
        }
        if (strlen($norm_end) < 19) {
            $norm_end = date('Y-m-d H:i:s', strtotime($event_end));
        }

        $sql = "SELECT id, reference_no, event_title, event_type, event_start, event_end, location_venue, status 
                FROM bookings 
                WHERE status = 'approved' 
                  AND id != ? 
                  AND (event_start < ? AND event_end > ?) 
                ORDER BY event_start ASC 
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [
                'conflict' => false,
                'conflicting_event' => null,
                'message' => ''
            ];
        }

        $stmt->bind_param("iss", $exclude_id, $norm_end, $norm_start);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $conflict = $result->fetch_assoc();
            $stmt->close();

            $c_raw_start = substr(trim((string)$conflict['event_start']), 0, 19);
            $c_raw_end   = substr(trim((string)$conflict['event_end']), 0, 19);
            $c_start_ts  = strtotime($c_raw_start);
            $c_end_ts    = strtotime($c_raw_end);
            $c_start_str = date('M j, Y g:i A', $c_start_ts);
            $c_end_str   = (date('Y-m-d', $c_start_ts) === date('Y-m-d', $c_end_ts)) 
                ? date('g:i A', $c_end_ts) 
                : date('M j, Y g:i A', $c_end_ts);

            $msg = "Schedule Conflict: Cannot approve this booking because its date and time overlap with an existing approved event \"" . 
                   $conflict['event_title'] . "\" (Ref: " . $conflict['reference_no'] . ") scheduled on " . 
                   $c_start_str . " – " . $c_end_str . ".";

            return [
                'conflict' => true,
                'conflicting_event' => $conflict,
                'message' => $msg
            ];
        }

        $stmt->close();
        return [
            'conflict' => false,
            'conflicting_event' => null,
            'message' => ''
        ];
    }
}


if (!function_exists('get_event_family')) {
    function get_event_family($event_type) {
        $t = strtolower(trim($event_type ?? ''));
        if (strpos($t, 'wedding') !== false) {
            return 'wedding';
        }
        return 'theme_party';
    }
}

// ============================================================
// DYNAMIC PRICING & PACKAGES MANAGEMENT
// ============================================================
if (!function_exists('get_default_packages_pricing')) {
    function get_default_packages_pricing() {
        return [
            'theme_party' => [
                'catering' => [
                    'category_title' => 'Catering Service (Pax Capacity)',
                    'icon' => 'fa-utensils',
                    'package' => 'custom catering',
                    'type' => 'radio',
                    'field' => 'svc_catering',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'cat_75',  'label' => '75 pax',  'price' => 37000, 'value' => 'Catering: 75 pax'],
                        ['id' => 'cat_100', 'label' => '100 pax', 'price' => 49000, 'value' => 'Catering: 100 pax'],
                        ['id' => 'cat_150', 'label' => '150 pax', 'price' => 72000, 'value' => 'Catering: 150 pax'],
                        ['id' => 'cat_200', 'label' => '200 pax', 'price' => 96000, 'value' => 'Catering: 200 pax'],
                        ['id' => 'cat_300', 'label' => '300 pax', 'price' => 141000, 'value' => 'Catering: 300 pax'],
                        ['id' => 'cat_400', 'label' => '400 pax', 'price' => 188000, 'value' => 'Catering: 400 pax'],
                        ['id' => 'cat_500', 'label' => '500 pax', 'price' => 235000, 'value' => 'Catering: 500 pax'],
                    ]
                ],
                'catering_addons' => [
                    'category_title' => 'Catering Inclusions & Add-ons',
                    'icon' => 'fa-bowl-food',
                    'package' => 'catering',
                    'type' => 'checkbox',
                    'field' => 'svc_catering_addons[]',
                    'badge' => 'Pick Any',
                    'items' => [
                        ['id' => 'cat_add_f4', 'label' => 'Food: 4 main courses', 'price' => 0, 'value' => 'Food: 4 main courses'],
                        ['id' => 'cat_add_f5', 'label' => 'Food: 5 main courses', 'price' => 0, 'value' => 'Food: 5 main courses'],
                        ['id' => 'cat_add_f6', 'label' => 'Food: 6 main courses', 'price' => 0, 'value' => 'Food: 6 main courses'],
                        ['id' => 'cat_add_s_basic', 'label' => 'Styling: Basic', 'price' => 0, 'value' => 'Catering Presentation: Basic'],
                        ['id' => 'cat_add_s_med',   'label' => 'Styling: Medium', 'price' => 0, 'value' => 'Catering Presentation: Medium'],
                        ['id' => 'cat_add_s_prem',  'label' => 'Styling: Premium', 'price' => 0, 'value' => 'Catering Presentation: Premium'],
                    ]
                ],
                'styling' => [
                    'category_title' => 'Styling',
                    'icon' => 'fa-wand-magic-sparkles',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_styling',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'bd_basic',   'label' => 'Basic',                              'price' => 0,     'value' => 'Styling: Basic'],
                        ['id' => 'bd_reg',     'label' => 'Regular Package',                    'price' => 20000, 'value' => 'Styling: Regular Package'],
                        ['id' => 'bd_up_ceil', 'label' => 'Upgraded Backdrop With Ceiling',      'price' => 45000, 'value' => 'Styling: Upgraded Backdrop With Ceiling'],
                        ['id' => 'bd_up_prem', 'label' => 'Upgraded Premium Ceiling Treatment', 'price' => 60000, 'value' => 'Styling: Upgraded Premium Ceiling Treatment'],
                    ]
                ],
                'theme_styling' => [
                    'category_title' => 'Theme Party Styling',
                    'icon' => 'fa-wand-magic-sparkles',
                    'package' => 'theme_party',
                    'type' => 'radio',
                    'field' => 'svc_theme_styling',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'tp_little',  'label' => 'Little Celebration',                 'price' => 8500,  'value' => 'Theme Styling: Little Celebration'],
                        ['id' => 'tp_reg',     'label' => 'Regular Package',                    'price' => 15000, 'value' => 'Theme Styling: Regular Package'],
                        ['id' => 'tp_up_back', 'label' => 'Upgraded Backdrop Setup',             'price' => 25000, 'value' => 'Theme Styling: Upgraded Backdrop Setup'],
                        ['id' => 'tp_up_ceil', 'label' => 'Upgraded Backdrop With Ceiling',      'price' => 40000, 'value' => 'Theme Styling: Upgraded Backdrop With Ceiling'],
                    ]
                ],
                'character' => [
                    'category_title' => 'Character',
                    'icon' => 'fa-shapes',
                    'package' => 'theme_party',
                    'type' => 'radio',
                    'field' => 'svc_character',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'char_styro',   'label' => 'Styro Character 2D/3D',         'price' => 0, 'value' => 'Character: Styro Character 2D/3D'],
                        ['id' => 'char_printed', 'label' => 'Printed Styro Foam Character',  'price' => 0, 'value' => 'Character: Printed Styro Foam Character'],
                    ]
                ],
                'sound_lights' => [
                    'category_title' => 'Sound & Lights',
                    'icon' => 'fa-music',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_sound',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'snd_basic', 'label' => 'Basic',   'price' => 6500,  'value' => 'Sound & Lights: Basic'],
                        ['id' => 'snd_med',   'label' => 'Medium',  'price' => 8500,  'value' => 'Sound & Lights: Medium'],
                        ['id' => 'snd_prem',  'label' => 'Premium', 'price' => 15000, 'value' => 'Sound & Lights: Premium'],
                    ]
                ],
                'lightning' => [
                    'category_title' => 'Lightning Setup',
                    'icon' => 'fa-bolt',
                    'package' => 'theme_party',
                    'type' => 'radio',
                    'field' => 'svc_lightning',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'lt_basic', 'label' => 'Basic',   'price' => 0,    'value' => 'Lightning: Basic'],
                        ['id' => 'lt_med',   'label' => 'Medium',  'price' => 4000, 'value' => 'Lightning: Medium'],
                        ['id' => 'lt_prem',  'label' => 'Premium', 'price' => 6000, 'value' => 'Lightning: Premium'],
                    ]
                ],
                'ceiling' => [
                    'category_title' => 'Ceiling Treatment',
                    'icon' => 'fa-house-chimney',
                    'package' => 'theme_party',
                    'type' => 'radio',
                    'field' => 'svc_ceiling',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'ceil_basic', 'label' => 'Basic Ceiling Treatment (no trusses)', 'price' => 25000, 'value' => 'Ceiling: Basic Ceiling Treatment Only without trusses'],
                        ['id' => 'ceil_up',    'label' => 'Upgraded Ceiling Treatment with trusses', 'price' => 45000, 'value' => 'Ceiling: Upgraded Ceiling Treatment with trusses'],
                        ['id' => 'ceil_prem',  'label' => 'Upgraded Premium Ceiling Treatment', 'price' => 60000, 'value' => 'Ceiling: Upgraded Premium Ceiling Treatment'],
                    ]
                ],
                'entertainment' => [
                    'category_title' => 'Entertainment',
                    'icon' => 'fa-masks-theater',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_entertainment',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'ent_host', 'label' => 'Host',                          'price' => 5000,  'value' => 'Entertainment: Host'],
                        ['id' => 'ent_mag',  'label' => 'Magician',                      'price' => 5000,  'value' => 'Entertainment: Magician'],
                        ['id' => 'ent_hm',   'label' => 'Host & Magician',               'price' => 7500,  'value' => 'Entertainment: Host & Magician'],
                        ['id' => 'ent_full', 'label' => 'Host, Magician & Puppet Show',  'price' => 15000, 'value' => 'Entertainment: Host, Magician & Puppet Show'],
                    ]
                ],
                'food_carts' => [
                    'category_title' => 'Food Carts',
                    'icon' => 'fa-cart-flatbed',
                    'package' => 'custom',
                    'type' => 'checkbox',
                    'field' => 'svc_addons[]',
                    'badge' => 'Pick Any',
                    'items' => [
                        ['id' => 'fc_pop',     'label' => 'PopCorn Carts',                         'price' => 5000,  'value' => 'Food Cart: PopCorn Carts'],
                        ['id' => 'fc_hotdog',  'label' => 'Hotdog Carts',                          'price' => 5000,  'value' => 'Food Cart: Hotdog Carts'],
                        ['id' => 'fc_sweet',   'label' => 'Sweet Corner',                          'price' => 5000,  'value' => 'Food Cart: Sweet Corner'],
                        ['id' => 'fc_ice',     'label' => 'Ice Cream',                             'price' => 5000,  'value' => 'Food Cart: Ice Cream'],
                        ['id' => 'fc_fries',   'label' => 'Fries',                                 'price' => 5000,  'value' => 'Food Cart: Fries'],
                        ['id' => 'fc_nachos',  'label' => 'Nachos',                                'price' => 5000,  'value' => 'Food Cart: Nachos'],
                        ['id' => 'fc_donuts',  'label' => 'Donuts',                                'price' => 5000,  'value' => 'Food Cart: Donuts'],
                        ['id' => 'fc_grazing', 'label' => 'Grazing Table w/ Cold Cuts (100 pax)',  'price' => 10500, 'value' => 'Food Cart: Grazing Table with Cold Cuts (good for 100 pax)'],
                    ]
                ],
                'photobooth' => [
                    'category_title' => 'Photobooth',
                    'icon' => 'fa-camera-retro',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_photobooth',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'pb_basic',  'label' => 'Basic Paper Frame',  'price' => 3500,  'value' => 'Photobooth: Basic Paper Frame'],
                        ['id' => 'pb_magnet', 'label' => 'Premium Ref Magnet', 'price' => 4500,  'value' => 'Photobooth: Premium Ref Magnet'],
                        ['id' => 'pb_360',    'label' => 'Premium 360',        'price' => 12000, 'value' => 'Photobooth: Premium 360'],
                    ]
                ],
                'photo_video' => [
                    'category_title' => 'Photo & Video Coverage',
                    'icon' => 'fa-video',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_photo',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'pv_photo', 'label' => 'Photo Coverage',                  'price' => 4500,  'value' => 'Photo & Video: Photo Coverage'],
                        ['id' => 'pv_pv',    'label' => 'Photo & Video Coverage',          'price' => 12000, 'value' => 'Photo & Video: Photo & Video Coverage'],
                        ['id' => 'pv_mtv',   'label' => 'Photo & Video Coverage MTV Highlights', 'price' => 15000, 'value' => 'Photo & Video: MTV Highlights'],
                        ['id' => 'pv_sde',   'label' => 'Photo & Video Coverage Sameday Edit',   'price' => 35000, 'value' => 'Photo & Video: Sameday Edit'],
                    ]
                ],
                'otd' => [
                    'category_title' => 'OTD Coordination',
                    'icon' => 'fa-clipboard-list',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_otd',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'otd_basic', 'label' => 'Basic Package',   'price' => 10000, 'value' => 'OTD Coordination: Basic Package'],
                        ['id' => 'otd_prem',  'label' => 'Premium Package', 'price' => 15000, 'value' => 'OTD Coordination: Premium Package'],
                    ]
                ]
            ],
            'wedding' => [
                'catering' => [
                    'category_title' => 'Catering Service (Pax Capacity)',
                    'icon' => 'fa-utensils',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_catering',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_cat_75',  'label' => '75 pax',  'price' => 37000, 'value' => 'Catering: 75 pax'],
                        ['id' => 'w_cat_100', 'label' => '100 pax', 'price' => 49000, 'value' => 'Catering: 100 pax'],
                        ['id' => 'w_cat_150', 'label' => '150 pax', 'price' => 72000, 'value' => 'Catering: 150 pax'],
                        ['id' => 'w_cat_200', 'label' => '200 pax', 'price' => 96000, 'value' => 'Catering: 200 pax'],
                        ['id' => 'w_cat_300', 'label' => '300 pax', 'price' => 141000, 'value' => 'Catering: 300 pax'],
                        ['id' => 'w_cat_400', 'label' => '400 pax', 'price' => 188000, 'value' => 'Catering: 400 pax'],
                        ['id' => 'w_cat_500', 'label' => '500 pax', 'price' => 235000, 'value' => 'Catering: 500 pax'],
                    ]
                ],
                'styling' => [
                    'category_title' => 'Styling',
                    'icon' => 'fa-wand-magic-sparkles',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_styling',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_style_basic',   'label' => 'Basic',                              'price' => 0,     'value' => 'Styling: Basic'],
                        ['id' => 'w_style_reg',     'label' => 'Regular Package',                    'price' => 20000, 'value' => 'Styling: Regular Package'],
                        ['id' => 'w_style_up_ceil', 'label' => 'Upgraded Backdrop With Ceiling',      'price' => 45000, 'value' => 'Styling: Upgraded Backdrop With Ceiling'],
                        ['id' => 'w_style_up_prem', 'label' => 'Upgraded Premium Ceiling Treatment', 'price' => 60000, 'value' => 'Styling: Upgraded Premium Ceiling Treatment'],
                    ]
                ],
                'entourage_flower' => [
                    'category_title' => 'Entourage Flower',
                    'icon' => 'fa-spa',
                    'package' => 'custom styling_package',
                    'type' => 'radio',
                    'field' => 'svc_entourage_flower',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_ef_basic', 'label' => 'Basic',                         'price' => 10000, 'value' => 'Entourage Flower: Basic'],
                        ['id' => 'w_ef_reg',   'label' => 'Regular Package',               'price' => 12000, 'value' => 'Entourage Flower: Regular Package'],
                        ['id' => 'w_ef_mix',   'label' => 'Upgraded Mix Local & Imported', 'price' => 15000, 'value' => 'Entourage Flower: Upgraded Mix Local & Imported'],
                        ['id' => 'w_ef_imp',   'label' => 'Upgraded All Imported',         'price' => 17000, 'value' => 'Entourage Flower: Upgraded All Imported'],
                    ]
                ],
                'ceremony_styling' => [
                    'category_title' => 'Ceremony Styling',
                    'icon' => 'fa-church',
                    'package' => 'custom styling_package',
                    'type' => 'radio',
                    'field' => 'svc_ceremony_styling',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_cs_church',     'label' => 'Regular Church Styling',                   'price' => 17000, 'value' => 'Ceremony Styling: Regular Church Styling'],
                        ['id' => 'w_cs_garden',     'label' => 'Regular Garden Package',                   'price' => 20000, 'value' => 'Ceremony Styling: Regular Garden Package'],
                        ['id' => 'w_cs_mix_church', 'label' => 'Upgraded Mix Local & Imported (Church)',   'price' => 25000, 'value' => 'Ceremony Styling: Upgraded Mix Local & Imported (Church)'],
                        ['id' => 'w_cs_mix_garden', 'label' => 'Upgraded Mix Local & Imported (Garden)',   'price' => 25000, 'value' => 'Ceremony Styling: Upgraded Mix Local & Imported (Garden)'],
                    ]
                ],
                'sound_lights' => [
                    'category_title' => 'Sound & Lights',
                    'icon' => 'fa-music',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_sound',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_snd_basic', 'label' => 'Basic',   'price' => 6500,  'value' => 'Sound & Lights: Basic'],
                        ['id' => 'w_snd_med',   'label' => 'Medium',  'price' => 8500,  'value' => 'Sound & Lights: Medium'],
                        ['id' => 'w_snd_prem',  'label' => 'Premium', 'price' => 15000, 'value' => 'Sound & Lights: Premium'],
                    ]
                ],
                'entertainment' => [
                    'category_title' => 'Entertainment',
                    'icon' => 'fa-masks-theater',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_entertainment',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_ent_reg',  'label' => 'Regular Host',                          'price' => 5000,  'value' => 'Entertainment: Regular Host'],
                        ['id' => 'w_ent_ruth', 'label' => 'Professional Host "Ruth"',             'price' => 7500,  'value' => 'Entertainment: Professional Host "Ruth"'],
                        ['id' => 'w_ent_jen',  'label' => 'Popular & Professional Host "Jen"',     'price' => 25000, 'value' => 'Entertainment: Popular & Professional Host "Jen"'],
                        ['id' => 'w_ent_jam',  'label' => 'Host "Jam"',                           'price' => 60000, 'value' => 'Entertainment: Host "Jam"'],
                    ]
                ],
                'food_carts' => [
                    'category_title' => 'Food Carts',
                    'icon' => 'fa-cart-flatbed',
                    'package' => 'custom',
                    'type' => 'checkbox',
                    'field' => 'svc_addons[]',
                    'badge' => 'Pick Any',
                    'items' => [
                        ['id' => 'w_fc_pop',     'label' => 'PopCorn Carts',                         'price' => 5000,  'value' => 'Food Cart: PopCorn Carts'],
                        ['id' => 'w_fc_hotdog',  'label' => 'Hotdog Carts',                          'price' => 5000,  'value' => 'Food Cart: Hotdog Carts'],
                        ['id' => 'w_fc_sweet',   'label' => 'Sweet Corner',                          'price' => 5000,  'value' => 'Food Cart: Sweet Corner'],
                        ['id' => 'w_fc_ice',     'label' => 'Ice Cream',                             'price' => 5000,  'value' => 'Food Cart: Ice Cream'],
                        ['id' => 'w_fc_fries',   'label' => 'Fries',                                 'price' => 5000,  'value' => 'Food Cart: Fries'],
                        ['id' => 'w_fc_nachos',  'label' => 'Nachos',                                'price' => 5000,  'value' => 'Food Cart: Nachos'],
                        ['id' => 'w_fc_donuts',  'label' => 'Donuts',                                'price' => 5000,  'value' => 'Food Cart: Donuts'],
                        ['id' => 'w_fc_grazing', 'label' => 'Grazing Table w/ Cold Cuts (100 pax)',  'price' => 10500, 'value' => 'Food Cart: Grazing Table with Cold Cuts (good for 100 pax)'],
                    ]
                ],
                'photobooth' => [
                    'category_title' => 'Photobooth',
                    'icon' => 'fa-camera-retro',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_photobooth',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_pb_basic',  'label' => 'Basic Paper Frame',  'price' => 3500,  'value' => 'Photobooth: Basic Paper Frame'],
                        ['id' => 'w_pb_magnet', 'label' => 'Premium Ref Magnet', 'price' => 4500,  'value' => 'Photobooth: Premium Ref Magnet'],
                        ['id' => 'w_pb_360',    'label' => 'Premium 360',        'price' => 12000, 'value' => 'Photobooth: Premium 360'],
                    ]
                ],
                'photo_video' => [
                    'category_title' => 'Photo & Video Coverage',
                    'icon' => 'fa-video',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_photo',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_pv_photo', 'label' => 'Photo Coverage',                  'price' => 10000, 'value' => 'Photo & Video: Photo Coverage'],
                        ['id' => 'w_pv_pv',    'label' => 'Photo & Video Coverage',          'price' => 18000, 'value' => 'Photo & Video: Photo & Video Coverage'],
                        ['id' => 'w_pv_mtv',   'label' => 'MTV Highlights',                  'price' => 25000, 'value' => 'Photo & Video: MTV Highlights'],
                        ['id' => 'w_pv_sde',   'label' => 'Sameday Edit',                    'price' => 45000, 'value' => 'Photo & Video: Sameday Edit'],
                    ]
                ],
                'otd' => [
                    'category_title' => 'OTD Coordination & Planning',
                    'icon' => 'fa-clipboard-list',
                    'package' => 'custom planning_package',
                    'type' => 'radio',
                    'field' => 'svc_otd',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_otd_basic', 'label' => 'Basic Package',                 'price' => 25000, 'value' => 'OTD Coordination: Basic Package'],
                        ['id' => 'w_otd_full',  'label' => 'Full Planning & Coordination',  'price' => 35000, 'value' => 'OTD Coordination: Full Planning & Coordination'],
                    ]
                ],
                'partner_venue' => [
                    'category_title' => 'Partner Venue',
                    'icon' => 'fa-building',
                    'package' => 'custom',
                    'type' => 'radio',
                    'field' => 'svc_partner_venue',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_pv_emerald', 'label' => 'Emerald Courtyard', 'price' => 35000, 'value' => 'Partner Venue: Emerald Courtyard'],
                    ]
                ],
                'reception_styling' => [
                    'category_title' => 'Reception Styling',
                    'icon' => 'fa-champagne-glasses',
                    'package' => 'styling_package',
                    'type' => 'radio',
                    'field' => 'svc_reception_styling',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_rs_basic',   'label' => 'Basic',                                  'price' => 15000, 'value' => 'Reception Styling: Basic'],
                        ['id' => 'w_rs_reg',     'label' => 'Regular Package',                        'price' => 20000, 'value' => 'Reception Styling: Regular Package'],
                        ['id' => 'w_rs_up_ceil', 'label' => 'Upgraded Backdrop With Ceiling',          'price' => 65000, 'value' => 'Reception Styling: Upgraded Backdrop With Ceiling'],
                        ['id' => 'w_rs_up_prem', 'label' => 'Upgraded With Premium Ceiling Treatment', 'price' => 99000, 'value' => 'Reception Styling: Upgraded With Premium Ceiling Treatment'],
                    ]
                ],
                'ceiling_treatment' => [
                    'category_title' => 'Ceiling Treatment',
                    'icon' => 'fa-house-chimney',
                    'package' => 'styling_package',
                    'type' => 'radio',
                    'field' => 'svc_ceiling',
                    'badge' => 'Pick One',
                    'items' => [
                        ['id' => 'w_ceil_no_truss', 'label' => 'Regular Package Without Trusses',         'price' => 25000, 'value' => 'Ceiling: Regular Package Without Trusses'],
                        ['id' => 'w_ceil_truss',    'label' => 'Regular Package With Trusses',            'price' => 45000, 'value' => 'Ceiling: Regular Package With Trusses'],
                        ['id' => 'w_ceil_up_truss', 'label' => 'Upgraded With Trusses',                    'price' => 70000, 'value' => 'Ceiling: Upgraded With Trusses'],
                        ['id' => 'w_ceil_up_prem',  'label' => 'Upgraded With Premium Ceiling Treatment', 'price' => 85000, 'value' => 'Ceiling: Upgraded With Premium Ceiling Treatment'],
                    ]
                ]
            ]
        ];
    }
}

if (!function_exists('get_packages_pricing')) {
    function get_packages_pricing($conn, $family = 'theme_party') {
        $all_defaults = get_default_packages_pricing();
        if (!isset($all_defaults[$family])) {
            $family = 'theme_party';
        }
        $defaults = $all_defaults[$family];

        $raw = get_setting($conn, 'pricing_packages', '');
        if (empty($raw)) {
            return $defaults;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $defaults;
        }

        // Inline Migration: if settings.pricing_packages is NOT nested by family (old flat format),
        // wrap it as ['theme_party' => <old JSON>] so existing admin edits are not lost.
        if (!isset($data['theme_party']) && !isset($data['wedding'])) {
            $data = ['theme_party' => $data];
        }

        $family_data = $data[$family] ?? null;
        if (!is_array($family_data) || empty($family_data)) {
            return $defaults;
        }

        // Return saved family data with metadata fallbacks
        $result = [];
        foreach ($family_data as $cat_key => $cat_info) {
            $def_cat = $defaults[$cat_key] ?? [];
            $result[$cat_key] = [
                'category_title' => $cat_info['category_title'] ?? ($def_cat['category_title'] ?? ucfirst(str_replace('_', ' ', $cat_key))),
                'icon'           => $cat_info['icon'] ?? ($def_cat['icon'] ?? 'fa-circle-dot'),
                'package'        => $cat_info['package'] ?? ($def_cat['package'] ?? 'custom'),
                'type'           => $cat_info['type'] ?? ($def_cat['type'] ?? 'radio'),
                'field'          => $cat_info['field'] ?? ($def_cat['field'] ?? 'svc_' . $cat_key),
                'badge'          => $cat_info['badge'] ?? ($def_cat['badge'] ?? (($cat_info['type'] ?? '') === 'checkbox' ? 'Pick Any' : 'Pick One')),
                'items'          => []
            ];

            if (isset($cat_info['items']) && is_array($cat_info['items'])) {
                foreach ($cat_info['items'] as $item) {
                    if (!isset($item['label']) || trim($item['label']) === '') continue;
                    $id = !empty($item['id']) ? $item['id'] : 'pkg_' . substr(md5($item['label'] . microtime(true)), 0, 8);
                    $price = max(0, (int)($item['price'] ?? 0));
                    $value = !empty($item['value']) ? $item['value'] : ($result[$cat_key]['category_title'] . ': ' . $item['label']);
                    $result[$cat_key]['items'][] = [
                        'id'    => $id,
                        'label' => trim($item['label']),
                        'price' => $price,
                        'value' => $value
                    ];
                }
            }
        }

        return $result;
    }
}

if (!function_exists('save_packages_pricing')) {
    function save_packages_pricing($conn, $family, $pricing_data) {
        if (!in_array($family, ['theme_party', 'wedding'], true)) {
            return false;
        }
        if (!is_array($pricing_data)) {
            return false;
        }

        $raw = get_setting($conn, 'pricing_packages', '');
        $existing = !empty($raw) ? json_decode($raw, true) : [];
        if (!is_array($existing)) {
            $existing = [];
        }

        // If other family is not in database yet, initialize it from defaults
        $all_defaults = get_default_packages_pricing();
        $other_family = ($family === 'theme_party') ? 'wedding' : 'theme_party';
        if (!isset($existing[$other_family])) {
            $existing[$other_family] = $all_defaults[$other_family] ?? [];
        }

        $existing[$family] = $pricing_data;
        $json = json_encode($existing, JSON_UNESCAPED_UNICODE);
        return set_setting($conn, 'pricing_packages', $json);
    }
}

if (!function_exists('add_package_item')) {
    function add_package_item($conn, $family, $cat_key, $label, $price) {
        $data = get_packages_pricing($conn, $family);
        if (!isset($data[$cat_key])) {
            return ['success' => false, 'message' => "Category '$cat_key' does not exist."];
        }

        $label = trim($label);
        if ($label === '') {
            return ['success' => false, 'message' => 'Package/Item name cannot be empty.'];
        }

        $clean_label = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($label));
        $unique_suffix = substr(md5(microtime(true) . $label), 0, 5);
        $new_id = substr($cat_key, 0, 4) . '_' . substr($clean_label, 0, 8) . '_' . $unique_suffix;

        $cat_title = $data[$cat_key]['category_title'] ?? $cat_key;
        $new_item = [
            'id'    => $new_id,
            'label' => $label,
            'price' => max(0, (int)$price),
            'value' => $cat_title . ': ' . $label
        ];

        $data[$cat_key]['items'][] = $new_item;
        $ok = save_packages_pricing($conn, $family, $data);
        return ['success' => $ok, 'item' => $new_item, 'message' => $ok ? 'Item created successfully!' : 'Failed to save to database.'];
    }
}

if (!function_exists('update_package_item')) {
    function update_package_item($conn, $family, $cat_key, $item_id, $new_label, $new_price, $target_cat = null) {
        $data = get_packages_pricing($conn, $family);
        if (!isset($data[$cat_key])) {
            return ['success' => false, 'message' => "Category '$cat_key' not found."];
        }

        $target_cat = $target_cat ?? $cat_key;
        if (!isset($data[$target_cat])) {
            $target_cat = $cat_key;
        }

        $found = false;
        $target_item = null;
        $target_index = -1;

        foreach ($data[$cat_key]['items'] as $idx => $it) {
            if ($it['id'] === $item_id) {
                $found = true;
                $target_item = $it;
                $target_index = $idx;
                break;
            }
        }

        if (!$found) {
            return ['success' => false, 'message' => 'Package/Item not found.'];
        }

        $target_item['label'] = trim($new_label);
        $target_item['price'] = max(0, (int)$new_price);
        $target_item['value'] = $data[$target_cat]['category_title'] . ': ' . $target_item['label'];

        if ($target_cat === $cat_key) {
            $data[$cat_key]['items'][$target_index] = $target_item;
        } else {
            // Moved to another category
            array_splice($data[$cat_key]['items'], $target_index, 1);
            $data[$target_cat]['items'][] = $target_item;
        }

        $ok = save_packages_pricing($conn, $family, $data);
        return ['success' => $ok, 'item' => $target_item, 'message' => $ok ? 'Item updated successfully!' : 'Failed to save changes.'];
    }
}

if (!function_exists('delete_package_item')) {
    function delete_package_item($conn, $family, $cat_key, $item_id) {
        $data = get_packages_pricing($conn, $family);
        if (!isset($data[$cat_key])) {
            return ['success' => false, 'message' => "Category '$cat_key' not found."];
        }

        $initial_count = count($data[$cat_key]['items']);
        $data[$cat_key]['items'] = array_values(array_filter($data[$cat_key]['items'], function($it) use ($item_id) {
            return $it['id'] !== $item_id;
        }));

        if (count($data[$cat_key]['items']) === $initial_count) {
            return ['success' => false, 'message' => 'Package/Item not found to delete.'];
        }

        $ok = save_packages_pricing($conn, $family, $data);
        return ['success' => $ok, 'message' => $ok ? 'Package/Item deleted successfully!' : 'Failed to delete item.'];
    }
}

if (!function_exists('add_package_category')) {
    function add_package_category($conn, $family, $title, $type = 'radio', $icon = 'fa-tag') {
        $data = get_packages_pricing($conn, $family);
        $title = trim($title);
        if ($title === '') {
            return ['success' => false, 'message' => 'Category title cannot be empty.'];
        }

        $cat_key = 'cat_' . preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(' ', '_', $title)));
        if (isset($data[$cat_key])) {
            $cat_key .= '_' . mt_rand(10, 99);
        }

        $field = ($type === 'checkbox') ? 'svc_' . $cat_key . '[]' : 'svc_' . $cat_key;
        $badge = ($type === 'checkbox') ? 'Pick Any' : 'Pick One';

        $data[$cat_key] = [
            'category_title' => $title,
            'icon'           => !empty($icon) ? $icon : 'fa-tag',
            'package'        => 'custom',
            'type'           => $type,
            'field'          => $field,
            'badge'          => $badge,
            'items'          => []
        ];

        $ok = save_packages_pricing($conn, $family, $data);
        return ['success' => $ok, 'cat_key' => $cat_key, 'message' => $ok ? 'New category added successfully!' : 'Failed to add category.'];
    }
}

if (!function_exists('delete_package_category')) {
    function delete_package_category($conn, $family, $cat_key) {
        $data = get_packages_pricing($conn, $family);
        if (!isset($data[$cat_key])) {
            return ['success' => false, 'message' => "Category '$cat_key' not found."];
        }

        unset($data[$cat_key]);
        $ok = save_packages_pricing($conn, $family, $data);
        return ['success' => $ok, 'message' => $ok ? 'Category and all its items deleted successfully!' : 'Failed to delete category.'];
    }
}

if (!function_exists('reset_family_pricing_to_default')) {
    function reset_family_pricing_to_default($conn, $family) {
        $all_defaults = get_default_packages_pricing();
        if (!isset($all_defaults[$family])) {
            return false;
        }
        return save_packages_pricing($conn, $family, $all_defaults[$family]);
    }
}
?>