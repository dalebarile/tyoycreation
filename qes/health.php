<?php
/**
 * Health Check & Load Balancer Probe Endpoint
 * Used by Load Balancers (AWS ALB, Nginx, HAProxy, Cloudflare, Kubernetes)
 * to monitor instance health and route traffic efficiently.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$start_time = microtime(true);

require_once __DIR__ . '/db.php';

$status_code = 200;
$checks = [
    'php' => [
        'status' => 'OK',
        'version' => PHP_VERSION,
    ],
    'database' => [
        'status' => 'UNKNOWN'
    ],
    'cache' => [
        'status' => 'UNKNOWN'
    ]
];

// Check Database Connection
try {
    if (isset($conn) && (is_object($conn) || $conn instanceof SupabaseConnection)) {
        if ($conn instanceof SupabaseConnection) {
            $checks['database']['status'] = 'OK';
            $checks['database']['driver'] = 'SupabasePostgresDriver';
        } elseif (method_exists($conn, 'ping') && @$conn->ping()) {
            $checks['database']['status'] = 'OK';
            $checks['database']['driver'] = 'MySQLi';
        } else {
            // Ping fallback with simple query
            $res = $conn->query("SELECT 1");
            if ($res) {
                $checks['database']['status'] = 'OK';
                $checks['database']['driver'] = 'MySQLi';
            } else {
                $checks['database']['status'] = 'DEGRADED';
                $status_code = 503;
            }
        }
    } else {
        $checks['database']['status'] = 'DISCONNECTED';
        $status_code = 503;
    }
} catch (\Throwable $e) {
    $checks['database']['status'] = 'ERROR';
    $checks['database']['error'] = $e->getMessage();
    $status_code = 503;
}

// Check Cache Subsystem
try {
    if (class_exists('QesCache')) {
        $cache_test_key = '__health_ping__';
        QesCache::set($cache_test_key, 1, 10);
        $val = QesCache::get($cache_test_key);
        if ($val === 1) {
            $checks['cache']['status'] = 'OK';
            $checks['cache']['storage'] = QesCache::getCacheDir();
        } else {
            $checks['cache']['status'] = 'DEGRADED';
        }
    } else {
        $checks['cache']['status'] = 'DISABLED';
    }
} catch (\Throwable $e) {
    $checks['cache']['status'] = 'ERROR';
}

$elapsed_ms = round((microtime(true) - $start_time) * 1000, 2);

http_response_code($status_code);

echo json_encode([
    'status'       => ($status_code === 200) ? 'healthy' : 'unhealthy',
    'timestamp'    => gmdate('Y-m-d\TH:i:s\Z'),
    'response_ms'  => $elapsed_ms,
    'node'         => function_exists('qes_load_balancer_info') ? qes_load_balancer_info() : ['node_id' => gethostname()],
    'services'     => $checks,
    'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
