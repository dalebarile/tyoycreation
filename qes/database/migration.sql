-- ============================================================
-- QES_v2 Migration: Capstone Changes
-- Run this SQL once on your database before deploying the files
-- ============================================================

-- 1. Add must_change_password flag to users table
--    (forces first-login password change for admin-created accounts)
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0;

-- If your MySQL version doesn't support IF NOT EXISTS for ALTER TABLE, use:
-- ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0;

-- 2. Make sure the trash table supports item_type = 'user'
--    (the existing ENUM or VARCHAR column just needs 'user' to be a valid value)
--    If item_type is an ENUM, run this; if it's already VARCHAR, skip it.
-- ALTER TABLE trash MODIFY COLUMN item_type ENUM('event','facility','user') NOT NULL;

-- Verify:
-- SELECT COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
-- WHERE TABLE_SCHEMA = 'qe' AND TABLE_NAME = 'trash' AND COLUMN_NAME = 'item_type';

-- ============================================================
-- 3. Auto-Purge Policy: Rejected Booking Requests (7-Day Rule)
-- ============================================================
-- As of QES_v2, rejected bookings are automatically deleted after
-- 7 days via a query that runs on every load of a_events.php:
--
--   DELETE FROM bookings
--   WHERE status = 'rejected'
--     AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
--
-- NOTE: No changes to the database schema are needed for this feature.
-- The existing `updated_at` TIMESTAMP column (ON UPDATE CURRENT_TIMESTAMP)
-- is used to track when the booking was last modified (i.e., when it was rejected).
--
-- To manually run a one-time cleanup of old rejected records, execute:
-- DELETE FROM bookings WHERE status = 'rejected' AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY);

-- ============================================================
-- 4. User Registration and Booking Association
-- ============================================================
ALTER TABLE users ADD COLUMN IF NOT EXISTS full_name VARCHAR(150) NULL AFTER username;
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(50) NULL AFTER email;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS user_id INT NULL AFTER id;

-- ============================================================
-- 5. Forgot Password & Email Verification Code
-- ============================================================
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_code VARCHAR(20) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_expires_at TIMESTAMP NULL;


