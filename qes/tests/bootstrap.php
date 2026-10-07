<?php
/**
 * PHPUnit bootstrap.
 *
 * - Loads the Composer autoloader only.
 * - Does NOT include db.php (which connects to Supabase/MySQL and starts a session).
 * - Sets the same timezone the production code uses.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Asia/Manila');

if (!defined('PHPUNIT_RUNNING')) {
    define('PHPUNIT_RUNNING', true);
}

if (!defined('ENV_ENCRYPTION_KEY')) {
    define('ENV_ENCRYPTION_KEY', 'test_secret_key_for_phpunit_testing_32chars!');
}

require_once __DIR__ . '/../crypto_helper.php';
require_once __DIR__ . '/../db.php';
