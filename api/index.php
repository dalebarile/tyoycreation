<?php
/**
 * Vercel Serverless Function Entry Point / Front Controller
 * Routes clean URLs directly to the QES application.
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = ltrim($uri, '/');

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
if ($uri === 'admin' || $uri === 'admin/' || $uri === 'loginadmin') {
    require $qesDir . '/loginadmin.php';
    exit;
}

// User auth shortcuts
if ($uri === 'login') {
    require $qesDir . '/login.php';
    exit;
}
if ($uri === 'register') {
    require $qesDir . '/register.php';
    exit;
}
if ($uri === 'logout') {
    require $qesDir . '/logout.php';
    exit;
}

// Direct file in qes/
$target = $qesDir . '/' . $uri;
if (file_exists($target) && is_file($target)) {
    require $target;
    exit;
}

// Direct PHP file in qes/
if (file_exists($target . '.php') && is_file($target . '.php')) {
    require $target . '.php';
    exit;
}

// Default fallback to index.php
require $qesDir . '/index.php';
