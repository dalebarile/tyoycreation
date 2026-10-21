<?php
/**
 * Environment Loader for Tyoy Creation / QES
 * Loads environment variables from .env file or system environment (e.g. Vercel),
 * and defines the corresponding application constants with fallback to .env.php.
 */

if (!function_exists('qes_load_env')) {
    function qes_load_env(): void {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        // Candidate paths for .env file
        $candidates = [
            __DIR__ . '/.env',
            dirname(__DIR__) . '/.env'
        ];

        foreach ($candidates as $env_path) {
            if (file_exists($env_path) && is_readable($env_path)) {
                $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines !== false) {
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '' || str_starts_with($line, '#')) {
                            continue;
                        }

                        $eqPos = strpos($line, '=');
                        if ($eqPos === false) {
                            continue;
                        }

                        $key = trim(substr($line, 0, $eqPos));
                        $val = trim(substr($line, $eqPos + 1));

                        // Strip outer quotes if present
                        if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                            (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                            $val = substr($val, 1, -1);
                        }

                        // Set in $_ENV, $_SERVER, and putenv if not already set
                        if (!isset($_ENV[$key])) {
                            $_ENV[$key] = $val;
                        }
                        if (!isset($_SERVER[$key])) {
                            $_SERVER[$key] = $val;
                        }
                        putenv("{$key}={$val}");
                    }
                }
                break; // Use the first found .env file
            }
        }

        // Also check if .env.php exists and load it
        $phpEnvCandidates = [
            __DIR__ . '/.env.php',
            dirname(__DIR__) . '/.env.php'
        ];
        foreach ($phpEnvCandidates as $phpEnvPath) {
            if (file_exists($phpEnvPath) && is_readable($phpEnvPath)) {
                require_once $phpEnvPath;
                break;
            }
        }

        // Helper to retrieve an env variable from putenv/$_ENV/$_SERVER
        $getEnvVar = function(string $key, ?string $default = null): ?string {
            $val = getenv($key);
            if ($val !== false && $val !== '') {
                return $val;
            }
            if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
                return $_ENV[$key];
            }
            if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
                return $_SERVER[$key];
            }
            return $default;
        };

        // Bridge all known keys to defined constants (if not already defined by .env.php)
        $mappings = [
            'GEMINI_API_KEY'         => 'ENV_GEMINI_API_KEY',
            'ENCRYPTION_KEY'         => 'ENV_ENCRYPTION_KEY',
            'SUPABASE_PROJECT_REF'   => 'ENV_SUPABASE_PROJECT_REF',
            'SUPABASE_URL'           => 'ENV_SUPABASE_URL',
            'SUPABASE_ANON_KEY'      => 'ENV_SUPABASE_ANON_KEY',
            'SUPABASE_SERVICE_KEY'   => 'ENV_SUPABASE_SERVICE_KEY',
            'SUPABASE_TOKEN'         => 'ENV_SUPABASE_TOKEN',
            'SUPABASE_DB_HOST'       => 'ENV_SUPABASE_DB_HOST',
            'SUPABASE_DB_PORT'       => 'ENV_SUPABASE_DB_PORT',
            'SUPABASE_DB_USER'       => 'ENV_SUPABASE_DB_USER',
            'SUPABASE_DB_PASS'       => 'ENV_SUPABASE_DB_PASS',
            'EMAIL_HOST'             => 'ENV_EMAIL_HOST',
            'EMAIL_PORT'             => 'ENV_EMAIL_PORT',
            'EMAIL_USERNAME'         => 'ENV_EMAIL_USERNAME',
            'EMAIL_PASSWORD'         => 'ENV_EMAIL_PASSWORD',
            'EMAIL_FROM_NAME'        => 'ENV_EMAIL_FROM_NAME',
            'GOOGLE_CLIENT_ID'       => 'ENV_GOOGLE_CLIENT_ID',
            'GOOGLE_CLIENT_SECRET'   => 'ENV_GOOGLE_CLIENT_SECRET',
            'TURNSTILE_ENABLED'      => 'ENV_TURNSTILE_ENABLED',
            'TURNSTILE_SITE_KEY'     => 'ENV_TURNSTILE_SITE_KEY',
            'TURNSTILE_SECRET_KEY'   => 'ENV_TURNSTILE_SECRET_KEY',
            'DB_HOST'                => 'ENV_DB_HOST',
            'DB_USER'                => 'ENV_DB_USER',
            'DB_PASS'                => 'ENV_DB_PASS',
            'DB_NAME'                => 'ENV_DB_NAME'
        ];

        foreach ($mappings as $rawKey => $constName) {
            if (!defined($constName)) {
                // Try ENV_PREFIX, then RAW_KEY
                $val = $getEnvVar($constName) ?? $getEnvVar($rawKey);
                if ($val !== null) {
                    // Port integer conversion
                    if ($rawKey === 'SUPABASE_DB_PORT' || $rawKey === 'EMAIL_PORT') {
                        define($constName, (int)$val);
                    } else {
                        define($constName, $val);
                    }
                }
            }
        }
    }
}

// Auto-invoke when included
qes_load_env();
