<?php
// ============================================================
// Configuration & API Setup
// API keys are loaded from .env.php (never hardcoded here).
// ============================================================

// Load secrets from the separate environment file
$env_file = __DIR__ . '/.env.php';
if (file_exists($env_file)) {
    require_once $env_file;
}

// ── Gemini AI ──────────────────────────────────────────────
if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', defined('ENV_GEMINI_API_KEY') ? ENV_GEMINI_API_KEY : '');
}
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', 'gemini-2.5-flash');
}
if (!defined('GEMINI_URL')) {
    define('GEMINI_URL',
        'https://generativelanguage.googleapis.com/v1beta/models/'
        . GEMINI_MODEL . ':generateContent?key=' . GEMINI_API_KEY
    );
}

// ── Email (Gmail SMTP / PHPMailer) ─────────────────────────
if (!defined('EMAIL_HOST'))      define('EMAIL_HOST',      defined('ENV_EMAIL_HOST')      ? ENV_EMAIL_HOST      : 'smtp.gmail.com');
if (!defined('EMAIL_PORT'))      define('EMAIL_PORT',      defined('ENV_EMAIL_PORT')      ? ENV_EMAIL_PORT      : 587);
if (!defined('EMAIL_USERNAME'))  define('EMAIL_USERNAME',  defined('ENV_EMAIL_USERNAME')  ? ENV_EMAIL_USERNAME  : '');
if (!defined('EMAIL_PASSWORD'))  define('EMAIL_PASSWORD',  defined('ENV_EMAIL_PASSWORD')  ? ENV_EMAIL_PASSWORD  : '');
if (!defined('EMAIL_FROM_NAME')) define('EMAIL_FROM_NAME', defined('ENV_EMAIL_FROM_NAME') ? ENV_EMAIL_FROM_NAME : 'Tyoy Creation Events');

?>
