<?php
/**
 * Vercel Serverless Function Entry Point / Front Controller
 * Routes clean URLs directly to the QES application.
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = ltrim($uri, '/');
if (str_starts_with($uri, 'api/')) {
    $uri = substr($uri, 4);
}

$qesDir = dirname(__DIR__) . '/qes';
if (is_dir($qesDir)) {
    chdir($qesDir);
}

// Root or index
if ($uri === '' || $uri === 'index' || $uri === 'index.php') {
    require $qesDir . '/index.php';
    exit;
}

// Admin shortcuts
if (in_array(rtrim($uri, '/'), ['admin', 'admin-page', 'loginadmin'], true)) {
    require $qesDir . '/loginadmin.php';
    exit;
}

// User auth shortcuts
if ($uri === 'login' || $uri === 'login/') {
    require $qesDir . '/login.php';
    exit;
}
if ($uri === 'register' || $uri === 'register/') {
    require $qesDir . '/register.php';
    exit;
}
if ($uri === 'logout' || $uri === 'logout/') {
    require $qesDir . '/logout.php';
    exit;
}

// Candidates for file resolution
$candidates = [$uri];

// If uri starts with admin/ or qes/ (e.g., admin/auth_action.php, admin/a_events.php),
// allow resolving to the underlying script in qes/
$stripped = preg_replace('#^(admin|qes)/+#', '', $uri);
if ($stripped !== $uri && !empty($stripped)) {
    $candidates[] = $stripped;
}

foreach ($candidates as $cand) {
    $target = $qesDir . '/' . $cand;
    if (file_exists($target) && is_file($target)) {
        if (str_ends_with($target, '.php')) {
            require $target;
            exit;
        }
        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        $mimes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'json' => 'application/json',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
        ];
        if (isset($mimes[$ext])) {
            header('Content-Type: ' . $mimes[$ext]);
        }
        readfile($target);
        exit;
    }

    if (file_exists($target . '.php') && is_file($target . '.php')) {
        require $target . '.php';
        exit;
    }
}

// Default fallback to index.php
require $qesDir . '/index.php';
