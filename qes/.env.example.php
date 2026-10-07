<?php
// ============================================================
// ENVIRONMENT SECRETS TEMPLATE
// Copy this file to ".env.php" and fill in your real values.
// ============================================================

// Local MySQL Database (Fallback if Supabase is offline)
define('ENV_DB_HOST', 'localhost');
define('ENV_DB_USER', 'your_local_db_user');
define('ENV_DB_PASS', 'your_local_db_pass');
define('ENV_DB_NAME', 'qe');

// Google Gemini API Key - Get yours at https://aistudio.google.com/
define('ENV_GEMINI_API_KEY', 'YOUR_GEMINI_API_KEY_HERE');

// Google OAuth 2.0 Web Client ID & Secret (Google Sign-In / GIS)
define('ENV_GOOGLE_CLIENT_ID', 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com');
define('ENV_GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET_HERE');

// Data Protection & Privacy: Client PII Master Encryption Key (AES-256)
define('ENV_ENCRYPTION_KEY', 'generate_a_secure_32_character_random_key_here');

// ============================================================
// SUPABASE CREDENTIALS & PDO POOLER
// ============================================================
define('ENV_SUPABASE_PROJECT_REF', 'your_project_ref');
define('ENV_SUPABASE_URL',         'https://your_project_ref.supabase.co');
define('ENV_SUPABASE_ANON_KEY',    'your_anon_jwt_key');
define('ENV_SUPABASE_SERVICE_KEY', 'your_service_role_jwt_key');
define('ENV_SUPABASE_TOKEN',       'sbp_your_personal_access_token');
define('ENV_SUPABASE_DB_HOST',     'aws-0-ap-southeast-1.pooler.supabase.com');
define('ENV_SUPABASE_DB_PORT',     6543); // 6543 for Supabase Transaction Pooler
define('ENV_SUPABASE_DB_USER',     'postgres.your_project_ref');
define('ENV_SUPABASE_DB_PASS',     ''); // Set your Supabase database password to enable high-speed PDO pooler

// ============================================================
// EMAIL (Gmail SMTP via PHPMailer)
// ============================================================
define('ENV_EMAIL_HOST',      'smtp.gmail.com');
define('ENV_EMAIL_PORT',      587);
define('ENV_EMAIL_USERNAME',  'your_gmail_address@gmail.com');
define('ENV_EMAIL_PASSWORD',  'your_16_char_gmail_app_password');
define('ENV_EMAIL_FROM_NAME', 'Tyoy Creation Events');
