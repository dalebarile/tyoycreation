-- ============================================================================
-- TY0Y CREATION — DOUBLE BOOKING PREVENTION SYSTEM MIGRATION
-- Rule: ONLY ONE CONFIRMED (APPROVED OR COMPLETED) EVENT PER CALENDAR DAY
-- Timezone: Asia/Manila
-- Database: Supabase PostgreSQL (Production) / MySQL (Local Fallback)
-- ============================================================================

-- ----------------------------------------------------------------------------
-- STEP 1: AUDIT EXISTING RECORDS
-- Verify there are no existing duplicate approved/completed bookings on the same calendar day.
-- Run this BEFORE creating the unique constraint.
-- ----------------------------------------------------------------------------
-- PostgreSQL Audit:
SELECT 
    ((event_start AT TIME ZONE 'Asia/Manila')::date) AS calendar_date,
    COUNT(*) AS confirmed_event_count,
    STRING_AGG(reference_no, ', ') AS conflicting_references
FROM bookings
WHERE status IN ('approved', 'completed')
GROUP BY ((event_start AT TIME ZONE 'Asia/Manila')::date)
HAVING COUNT(*) > 1;

-- If the above query returns 0 rows, the table is completely clean and safe
-- for the unique constraint without needing to delete or modify any records.

-- ----------------------------------------------------------------------------
-- STEP 2: CREATE PARTIAL UNIQUE INDEX (PostgreSQL / Supabase Production)
-- Guarantees at the database engine level that no two approved or completed
-- bookings can share the same calendar date in the Asia/Manila timezone.
-- Pending, rejected, and cancelled bookings are NOT restricted.
-- ----------------------------------------------------------------------------
CREATE UNIQUE INDEX IF NOT EXISTS uq_bookings_one_event_per_day
ON bookings (((event_start AT TIME ZONE 'Asia/Manila')::date))
WHERE status IN ('approved', 'completed');

-- Drop old venue-based constraint if it exists from earlier scripts
ALTER TABLE bookings DROP CONSTRAINT IF EXISTS no_overlapping_approved_bookings;

-- ----------------------------------------------------------------------------
-- STEP 3: REVERSIBLE ROLLBACK INSTRUCTIONS
-- To revert this constraint back to previous state without data loss:
-- ----------------------------------------------------------------------------
/*
-- ROLLBACK COMMAND:
DROP INDEX IF EXISTS uq_bookings_one_event_per_day;
*/
