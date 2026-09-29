-- ============================================================
-- QES Notification Queue & Retry Migration
-- Run once on both MySQL (local) and Supabase (cloud)
-- Safe to re-run -- uses IF NOT EXISTS guards
-- ============================================================

-- 1. Track how many send attempts have been made
ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS attempt_count   INT           NOT NULL DEFAULT 0;

-- 2. Maximum allowed attempts before marking permanently Failed
ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS max_attempts    INT           NOT NULL DEFAULT 3;

-- 3. When was the last send attempt made (for retry cooldown checks)
ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS last_attempt_at DATETIME      NULL DEFAULT NULL;

-- 4. When was the email successfully delivered
ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS sent_at         DATETIME      NULL DEFAULT NULL;

-- 5. Last error message (sanitized -- no SMTP credentials stored)
ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS last_error      VARCHAR(500)  NULL DEFAULT NULL;

-- ============================================================
-- Index: speed up queue worker queries that filter by status
-- ============================================================
-- MySQL / MariaDB:
-- Note: MySQL does not support CREATE INDEX IF NOT EXISTS; skip if already exists.
-- Run manually if needed:
-- CREATE INDEX idx_notif_status ON notifications (status);

-- ============================================================
-- Supabase / PostgreSQL equivalent (run in Supabase SQL editor):
-- ============================================================
-- ALTER TABLE notifications ADD COLUMN IF NOT EXISTS attempt_count   INT           NOT NULL DEFAULT 0;
-- ALTER TABLE notifications ADD COLUMN IF NOT EXISTS max_attempts    INT           NOT NULL DEFAULT 3;
-- ALTER TABLE notifications ADD COLUMN IF NOT EXISTS last_attempt_at TIMESTAMPTZ   NULL DEFAULT NULL;
-- ALTER TABLE notifications ADD COLUMN IF NOT EXISTS sent_at         TIMESTAMPTZ   NULL DEFAULT NULL;
-- ALTER TABLE notifications ADD COLUMN IF NOT EXISTS last_error      VARCHAR(500)  NULL DEFAULT NULL;
-- CREATE INDEX IF NOT EXISTS idx_notif_status ON notifications (status);
-- 
-- PostgreSQL check constraint for template types (including 'inquiry'):
-- ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_template_type_check;
-- ALTER TABLE notifications ADD CONSTRAINT notifications_template_type_check 
--     CHECK (((template_type)::text = ANY ((ARRAY['inquiry'::character varying, 'approval'::character varying, 'rejection'::character varying, 'reminder'::character varying, 'custom'::character varying])::text[])));

