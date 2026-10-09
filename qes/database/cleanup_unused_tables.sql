-- ==============================================================================
-- CLEANUP SCRIPT: REMOVE UNUSED & OBSOLETE TABLES
-- Target Database: Supabase Cloud PostgreSQL (Project: kanotzhwqsscejqecqom)
-- Schema: public
--
-- Description:
-- These tables were leftover from an old prototype / initial database migration
-- and are completely unused in the Tyoy Creation Quick Event System (QES):
--
--  1. facilities    - Prototype table for campus/venue rooms (0 rows, 0 queries in QES).
--  2. events        - Old boilerplate table (0 rows). All events are in 'bookings'.
--  3. trash         - Old soft-delete bin (0 rows). QES uses 7-day auto-purge for rejected bookings.
--  4. chat_messages - Legacy chat log table (0 rows). Chatbot uses session memory & Gemini API.
--  5. chat_sessions - Companion table to chat_messages (0 rows, 0 queries in QES).
-- ==============================================================================

-- Drop tables safely with CASCADE in case of any lingering constraints
DROP TABLE IF EXISTS public.facilities CASCADE;
DROP TABLE IF EXISTS public.events CASCADE;
DROP TABLE IF EXISTS public.trash CASCADE;
DROP TABLE IF EXISTS public.chat_messages CASCADE;
DROP TABLE IF EXISTS public.chat_sessions CASCADE;

-- Verify remaining active tables (Expected: bookings, users, notifications, settings, login_attempts, user_sessions)
SELECT table_name, table_type 
FROM information_schema.tables 
WHERE table_schema = 'public' 
ORDER BY table_name;
