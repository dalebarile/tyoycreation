<?php
/**
 * Philippine Standard Geographic Code (PSGC) Address API Proxy & Cache
 * Provides Regions, Provinces, Cities/Municipalities, and Barangays.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$cache_dir = __DIR__ . '/assets/psgc_cache';
if (!is_dir($cache_dir)) {
    @mkdir($cache_dir, 0755, true);
}

$action = trim($_GET['action'] ?? 'regions');
$code = preg_replace('/[^0-9]/', '', trim($_GET['code'] ?? ''));

function fetch_psgc($endpoint, $cache_file) {
    if (file_exists($cache_file) && (time() - filemtime($cache_file) < 86400 * 30)) {
        $content = file_get_contents($cache_file);
        if ($content && strlen($content) > 5) {
            return $content;
        }
    }

    $ch = curl_init("https://psgc.gitlab.io/api/{$endpoint}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_USERAGENT, 'TyoyCreation-QES/2026');
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http === 200 && $res && strlen($res) > 5) {
        @file_put_contents($cache_file, $res);
        return $res;
    }

    if (file_exists($cache_file)) {
        return file_get_contents($cache_file);
    }

    return null;
}

if ($action === 'regions') {
    $res = fetch_psgc('regions.json', "{$cache_dir}/regions.json");
    if ($res) {
        echo $res;
        exit;
    }
    // Static fallback
    echo json_encode([
        ["code" => "130000000", "name" => "National Capital Region (NCR)"],
        ["code" => "030000000", "name" => "Region III (Central Luzon)"],
        ["code" => "040000000", "name" => "Region IV-A (CALABARZON)"]
    ]);
    exit;
}

if ($action === 'provinces') {
    if (empty($code)) {
        $res = fetch_psgc('provinces.json', "{$cache_dir}/provinces.json");
    } else {
        $res = fetch_psgc("regions/{$code}/provinces.json", "{$cache_dir}/provinces_reg_{$code}.json");
    }
    if ($res) {
        echo $res;
        exit;
    }
    echo json_encode([]);
    exit;
}

if ($action === 'cities') {
    if (!empty($code)) {
        // Try region cities first (NCR, Region 3, Region 4-A, etc.), then provinces
        $res = fetch_psgc("regions/{$code}/cities-municipalities.json", "{$cache_dir}/cities_reg_{$code}.json");
        if (!$res || json_decode($res, true) === []) {
            $res = fetch_psgc("provinces/{$code}/cities-municipalities.json", "{$cache_dir}/cities_prov_{$code}.json");
        }
    } else {
        $res = fetch_psgc('cities-municipalities.json', "{$cache_dir}/all_cities.json");
    }
    if ($res) {
        echo $res;
        exit;
    }
    echo json_encode([]);
    exit;
}

if ($action === 'barangays') {
    if (!empty($code)) {
        $res = fetch_psgc("cities-municipalities/{$code}/barangays.json", "{$cache_dir}/bgy_{$code}.json");
        if ($res) {
            echo $res;
            exit;
        }
    }
    echo json_encode([]);
    exit;
}

echo json_encode(["error" => "Invalid action"]);
exit;
