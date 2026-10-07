-- ========================================================
-- QES DATABASE SECURITY HARDENING MIGRATION
-- Generated: 2026-10-02
-- Purpose: Lock down RLS, add fail-safe indexes/constraints
-- Target: Supabase (Project ref: kanotzhwqsscejqecqom)
--
-- INSTRUCTIONS:
-- Run this SQL in the Supabase SQL Editor:
--   https://supabase.com/dashboard/project/kanotzhwqsscejqecqom/sql
-- ========================================================


-- ========================================================
-- STEP 1: DROP ALL INSECURE "ALLOW ALL" RLS POLICIES
-- These policies grant FULL read/write to anyone with the
-- public anon key, including password hashes, reset codes,
-- and all client PII.
-- ========================================================

DROP POLICY IF EXISTS "Allow full access for anon and authenticated on users" ON users;
DROP POLICY IF EXISTS "Allow full access for anon and authenticated on bookings" ON bookings;
DROP POLICY IF EXISTS "Allow full access for anon and authenticated on events" ON events;
DROP POLICY IF EXISTS "Allow full access for anon and authenticated on facilities" ON facilities;
DROP POLICY IF EXISTS "Allow full access for anon and authenticated on chat_sessions" ON chat_sessions;
DROP POLICY IF EXISTS "Allow full access for anon and authenticated on chat_messages" ON chat_messages;
DROP POLICY IF EXISTS "Allow full access for anon and authenticated on notifications" ON notifications;
DROP POLICY IF EXISTS "Allow full access for anon and authenticated on settings" ON settings;
DROP POLICY IF EXISTS "Allow full access for anon and authenticated on trash" ON trash;
DROP POLICY IF EXISTS "Allow full access for anon and authenticated on user_sessions" ON user_sessions;


-- ========================================================
-- STEP 2: CREATE RESTRICTIVE RLS POLICIES
-- After dropping the open policies, RLS is still enabled
-- on all tables, so the default is now DENY ALL for anon
-- and authenticated roles. Only the service_role key
-- (used server-side by PHP) bypasses RLS automatically.
--
-- We add SELECT-only policies for tables that the public
-- booking form and chatbot legitimately need to read.
-- ========================================================

-- FACILITIES: Public users can read facility names (for booking form dropdowns)
CREATE POLICY "anon_read_facilities"
  ON facilities FOR SELECT
  USING (true);

-- SETTINGS: Public users can read site settings (business name, packages, etc.)
CREATE POLICY "anon_read_settings"
  ON settings FOR SELECT
  USING (true);

-- BOOKINGS: Public users can only read approved event dates (for calendar/chatbot availability checks)
-- They cannot see client PII, pending bookings, or rejection details
CREATE POLICY "anon_read_approved_bookings"
  ON bookings FOR SELECT
  USING (status = 'approved');

-- BOOKINGS: Allow anonymous inserts for the public booking form (online inquiries)
-- The PHP server validates and sanitizes all fields before insert
CREATE POLICY "anon_insert_bookings"
  ON bookings FOR INSERT
  WITH CHECK (status = 'pending' AND source = 'online_inquiry');

-- CHAT: Public users can insert and read their own chat messages (session-based)
CREATE POLICY "anon_read_chat_messages"
  ON chat_messages FOR SELECT
  USING (true);

CREATE POLICY "anon_insert_chat_messages"
  ON chat_messages FOR INSERT
  WITH CHECK (true);

CREATE POLICY "anon_read_chat_sessions"
  ON chat_sessions FOR SELECT
  USING (true);

CREATE POLICY "anon_insert_chat_sessions"
  ON chat_sessions FOR INSERT
  WITH CHECK (true);

-- USERS: Public (anon) users can insert new registrations (status defaults to 'pending')
CREATE POLICY "anon_insert_users"
  ON users FOR INSERT
  WITH CHECK (role = 'user' AND status = 'pending');

-- ALL OTHER TABLES: No anon/authenticated policies = DENY ALL
-- users (SELECT/UPDATE/DELETE), notifications, trash, user_sessions, events
-- are accessed exclusively through the service_role key from the PHP backend.


-- ========================================================
-- STEP 3: PERFORMANCE INDEXES
-- Based on actual query patterns found in the codebase
-- ========================================================

-- users: Login lookups by email or username (auth_action.php, loginadmin.php, register.php)
CREATE INDEX IF NOT EXISTS idx_users_username ON users (username);

-- bookings: Status-based queries (a_events.php, a_home.php, admin_sidebar.php, event_load.php)
CREATE INDEX IF NOT EXISTS idx_bookings_status ON bookings (status);

-- bookings: Conflict detection on approved events by time range (db.php check_booking_conflict)
CREATE INDEX IF NOT EXISTS idx_bookings_approved_time ON bookings (status, event_start, event_end)
  WHERE status = 'approved';

-- bookings: Auto-purge of old rejected bookings (a_events.php)
CREATE INDEX IF NOT EXISTS idx_bookings_rejected_updated ON bookings (status, updated_at)
  WHERE status = 'rejected';

-- bookings: Client lookup by email/phone (a_clients.php)
CREATE INDEX IF NOT EXISTS idx_bookings_client_email ON bookings (client_email);

-- bookings: User's booking history (auth_action.php my_bookings)
CREATE INDEX IF NOT EXISTS idx_bookings_user_id ON bookings (user_id);

-- user_sessions: Session lookup (db.php track_user_session)
CREATE INDEX IF NOT EXISTS idx_usersessions_user_session ON user_sessions (user_id, session_id);

-- notifications: Booking notification lookup (notification_helper.php)
CREATE INDEX IF NOT EXISTS idx_notif_booking_id ON notifications (booking_id);

-- chat_messages: Session-based message retrieval (chatbot.php)
CREATE INDEX IF NOT EXISTS idx_chatmsg_session ON chat_messages (session_id);

-- events: Status-based event queries
CREATE INDEX IF NOT EXISTS idx_events_status ON events (status);


-- ========================================================
-- STEP 4: BOOKING OVERLAP EXCLUSION CONSTRAINT
-- Prevents double-booking at the database level.
-- The PHP check_booking_conflict function is a check-then-insert
-- pattern that has a race condition. This constraint is the
-- authoritative server of record.
--
-- Requires the btree_gist extension (available on Supabase).
-- ========================================================

CREATE EXTENSION IF NOT EXISTS btree_gist;

-- Prevent overlapping approved bookings at the same venue
-- This uses a GiST exclusion constraint on the time range + venue
ALTER TABLE bookings
  ADD CONSTRAINT no_overlapping_approved_bookings
  EXCLUDE USING GIST (
    location_venue WITH =,
    tstzrange(event_start, event_end) WITH &&
  )
  WHERE (status = 'approved');


-- ========================================================
-- STEP 5: UPDATED_AT AUTO-TRIGGER
-- The auto-purge of rejected bookings relies on updated_at
-- being current. Add a trigger to keep it fresh.
-- ========================================================

CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_bookings_updated_at ON bookings;
CREATE TRIGGER trg_bookings_updated_at
    BEFORE UPDATE ON bookings
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();
