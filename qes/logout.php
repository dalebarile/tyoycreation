<?php
require_once __DIR__ . '/db.php';

$uid = (int)($_SESSION['id'] ?? 0);
$sess_id = session_id();

if ($uid > 0 && isset($conn) && is_object($conn)) {
    // Record logout time on users table
    $stmt = $conn->prepare("UPDATE users SET last_logout_at = NOW() WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->close();
    }

    // Mark current session as logged out
    if (!empty($sess_id)) {
        $stmt2 = $conn->prepare("UPDATE user_sessions SET is_logged_out = 1, logged_out_at = NOW() WHERE user_id = ? AND session_id = ?");
        if ($stmt2) {
            $stmt2->bind_param("is", $uid, $sess_id);
            $stmt2->execute();
            $stmt2->close();
        }
    }
}

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();
$redirect = $_GET['redirect'] ?? 'loginadmin.php';
// Security: only allow relative redirects (block absolute URLs / protocol-relative URLs)
if (preg_match('#^https?://|^//#i', $redirect) || str_contains($redirect, '://')) {
    $redirect = 'loginadmin.php';
}
header("Location: " . $redirect);
exit;
?>
