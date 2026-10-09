-- ========================================================
-- QES Migration: Persistent Login Rate Limiting Table
-- Generated: 2026-10-07
-- Purpose: Hardened, atomic database storage for admin rate limiting
-- Primary Dialect: Supabase (PostgreSQL)
-- Local Dev Dialect: MySQL (compatible schema included)
-- ========================================================

-- --------------------------------------------------------
-- 1. PRIMARY TARGET: SUPABASE (PostgreSQL)
-- Instructions: Run in the Supabase SQL Editor:
--   https://supabase.com/dashboard/project/kanotzhwqsscejqecqom/sql
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS login_attempts (
    key_hash VARCHAR(64) PRIMARY KEY,
    scope VARCHAR(64) NOT NULL,
    attempts INT NOT NULL DEFAULT 1,
    first_attempt TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until TIMESTAMP WITH TIME ZONE NULL DEFAULT NULL,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_login_attempts_scope ON login_attempts (scope);
CREATE INDEX IF NOT EXISTS idx_login_attempts_locked ON login_attempts (locked_until);

-- Restrictive RLS Policy for Supabase
-- By default, anon users cannot read or modify login_attempts.
-- Only the service_role key used by the PHP backend can access it.
ALTER TABLE login_attempts ENABLE ROW LEVEL SECURITY;

-- --------------------------------------------------------
-- 2. LOCAL DEV TARGET: MySQL / MariaDB (e.g., XAMPP)
-- Instructions: Run in phpMyAdmin or mysql CLI if using local MySQL:
-- --------------------------------------------------------
-- CREATE TABLE IF NOT EXISTS `login_attempts` (
--     `key_hash` VARCHAR(64) NOT NULL,
--     `scope` VARCHAR(64) NOT NULL,
--     `attempts` INT NOT NULL DEFAULT 1,
--     `first_attempt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     `locked_until` TIMESTAMP NULL DEFAULT NULL,
--     `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
--     PRIMARY KEY (`key_hash`),
--     KEY `idx_login_attempts_scope` (`scope`),
--     KEY `idx_login_attempts_locked` (`locked_until`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
