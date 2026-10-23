-- ============================================================
-- Tyoy Creation QES - Database-Backed Session Storage Migration
-- Table: qes_sessions
-- ============================================================

-- PostgreSQL / Supabase Variant:
CREATE TABLE IF NOT EXISTS qes_sessions (
    id VARCHAR(128) PRIMARY KEY,
    data TEXT NOT NULL DEFAULT '',
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at BIGINT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_qes_sessions_expires_at ON qes_sessions (expires_at);

-- Row-Level Security for Supabase
ALTER TABLE qes_sessions ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS "Allow full access for service on qes_sessions" ON qes_sessions;
CREATE POLICY "Allow full access for service on qes_sessions" ON qes_sessions FOR ALL USING (true) WITH CHECK (true);

-- ============================================================
-- MySQL / Local XAMPP Variant (for local development fallback):
-- ============================================================
-- CREATE TABLE IF NOT EXISTS `qes_sessions` (
--     `id` VARCHAR(128) NOT NULL PRIMARY KEY,
--     `data` LONGTEXT NOT NULL,
--     `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
--     `expires_at` BIGINT NOT NULL,
--     INDEX `idx_qes_sessions_expires_at` (`expires_at`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
