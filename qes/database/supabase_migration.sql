-- ========================================================
-- SUPABASE / POSTGRESQL COMPLETE DATABASE MIGRATION
-- Source: MySQL Database 'qe'
-- Generated: 2026-09-25 05:28:11
-- Target: Supabase (Project ref: kanotzhwqsscejqecqom)
-- ========================================================

-- Clean up existing tables if any (safe order)
DROP TABLE IF EXISTS user_sessions CASCADE;
DROP TABLE IF EXISTS trash CASCADE;
DROP TABLE IF EXISTS settings CASCADE;
DROP TABLE IF EXISTS notifications CASCADE;
DROP TABLE IF EXISTS events CASCADE;
DROP TABLE IF EXISTS chat_messages CASCADE;
DROP TABLE IF EXISTS chat_sessions CASCADE;
DROP TABLE IF EXISTS facilities CASCADE;
DROP TABLE IF EXISTS bookings CASCADE;
DROP TABLE IF EXISTS users CASCADE;

-- ========================================================
-- 1. USERS TABLE
-- ========================================================
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    full_name VARCHAR(150),
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(50),
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) DEFAULT 'user',
    must_change_password BOOLEAN NOT NULL DEFAULT FALSE,
    status VARCHAR(50) DEFAULT 'pending' CHECK (status IN ('approved','pending','rejected')),
    last_login_at TIMESTAMP WITH TIME ZONE,
    last_seen_at TIMESTAMP WITH TIME ZONE,
    last_logout_at TIMESTAMP WITH TIME ZONE,
    last_ip VARCHAR(45),
    last_device VARCHAR(150),
    reset_code VARCHAR(20),
    reset_expires_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ========================================================
-- 2. FACILITIES TABLE
-- ========================================================
CREATE TABLE facilities (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    capacity INT DEFAULT 10,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ========================================================
-- 3. BOOKINGS TABLE
-- ========================================================
CREATE TABLE bookings (
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE SET NULL,
    reference_no VARCHAR(50) NOT NULL UNIQUE,
    client_name VARCHAR(150) NOT NULL,
    client_email VARCHAR(150) NOT NULL,
    client_phone VARCHAR(50) NOT NULL,
    client_address TEXT,
    event_title VARCHAR(255) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    event_start TIMESTAMP WITH TIME ZONE NOT NULL,
    event_end TIMESTAMP WITH TIME ZONE NOT NULL,
    guest_count INT DEFAULT 50,
    location_venue VARCHAR(255) NOT NULL,
    service_requirements TEXT,
    special_notes TEXT,
    status VARCHAR(50) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected','completed','cancelled')),
    rejection_reason TEXT,
    source VARCHAR(50) NOT NULL DEFAULT 'online_inquiry' CHECK (source IN ('online_inquiry','manual_entry')),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ========================================================
-- 4. EVENTS TABLE
-- ========================================================
CREATE TABLE events (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    facility_id INT NOT NULL REFERENCES facilities(id) ON DELETE CASCADE,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    attendees_count INT DEFAULT 1,
    start_time TIMESTAMP WITH TIME ZONE NOT NULL,
    end_time TIMESTAMP WITH TIME ZONE NOT NULL,
    status VARCHAR(50) DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ========================================================
-- 5. CHAT SESSIONS & MESSAGES
-- ========================================================
CREATE TABLE chat_sessions (
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE SET NULL,
    session_id VARCHAR(100),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE chat_messages (
    id SERIAL PRIMARY KEY,
    session_id VARCHAR(100),
    message TEXT,
    is_user BOOLEAN DEFAULT FALSE,
    timestamp TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ========================================================
-- 6. NOTIFICATIONS TABLE
-- ========================================================
CREATE TABLE notifications (
    id SERIAL PRIMARY KEY,
    booking_id INT,
    recipient_name VARCHAR(150) NOT NULL,
    recipient_contact VARCHAR(150) NOT NULL,
    channel VARCHAR(50) NOT NULL CHECK (channel IN ('sms','email')),
    template_type VARCHAR(50) NOT NULL CHECK (template_type IN ('approval','rejection','reminder','custom')),
    subject VARCHAR(255),
    message TEXT NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'sent' CHECK (status IN ('sent','pending','failed')),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ========================================================
-- 7. SETTINGS TABLE
-- ========================================================
CREATE TABLE settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL
);

-- ========================================================
-- 8. TRASH TABLE
-- ========================================================
CREATE TABLE trash (
    id SERIAL PRIMARY KEY,
    item_type VARCHAR(50) NOT NULL CHECK (item_type IN ('event','facility','user')),
    item_id INT,
    item_data JSONB NOT NULL,
    deleted_by INT,
    deleted_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    auto_delete_at TIMESTAMP WITH TIME ZONE DEFAULT (CURRENT_TIMESTAMP + INTERVAL '30 days')
);

-- ========================================================
-- 9. USER SESSIONS TABLE
-- ========================================================
CREATE TABLE user_sessions (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    session_id VARCHAR(128) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NOT NULL,
    device_name VARCHAR(150) NOT NULL,
    device_type VARCHAR(30) DEFAULT 'desktop',
    is_blocked BOOLEAN DEFAULT FALSE,
    is_logged_out BOOLEAN DEFAULT FALSE,
    logged_out_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL,
    last_activity TIMESTAMP WITH TIME ZONE NOT NULL
);

-- --------------------------------------------------------
-- DATA FOR: users (6 rows)
-- --------------------------------------------------------
INSERT INTO users ("id", "username", "full_name", "email", "phone", "password", "role", "must_change_password", "status", "last_login_at", "last_seen_at", "last_logout_at", "last_ip", "last_device", "created_at") VALUES (3, 'daniel', NULL, 'daniel@gmail.com', NULL, '$2y$10$nwYoNz7hhMTPZFpFW274geTCTElcoy6GVjs08FhQbrtUFwDYerMI6', 'user', FALSE, 'approved', NULL, NULL, NULL, NULL, NULL, '2026-09-10 21:38:33');
INSERT INTO users ("id", "username", "full_name", "email", "phone", "password", "role", "must_change_password", "status", "last_login_at", "last_seen_at", "last_logout_at", "last_ip", "last_device", "created_at") VALUES (5, 'albert', NULL, 'albert@gmail.com', NULL, '$2y$10$qYE9PhReD4g0uSJm1ZndGePM06OK4LD3oifNL4XKqEu.JtSncs8cm', 'user', FALSE, 'approved', NULL, NULL, NULL, NULL, NULL, '2026-09-13 15:18:30');
INSERT INTO users ("id", "username", "full_name", "email", "phone", "password", "role", "must_change_password", "status", "last_login_at", "last_seen_at", "last_logout_at", "last_ip", "last_device", "created_at") VALUES (6, 'jeremiah', NULL, 'cansantosan@gmail.com', NULL, '$2y$10$yQ0IifV2obW.idtbKtuA8.Rn.KYrkpiIlpMfhxDGDbL3zk2suJ99W', 'user', TRUE, 'approved', NULL, NULL, NULL, NULL, NULL, '2026-09-01 11:38:29');
INSERT INTO users ("id", "username", "full_name", "email", "phone", "password", "role", "must_change_password", "status", "last_login_at", "last_seen_at", "last_logout_at", "last_ip", "last_device", "created_at") VALUES (8, 'creationtyoy', NULL, 'creationtyoy@gmail.com', NULL, '$2y$10$OZBAmhRs7fHzLS2fQ69Jbea/WLxUHK2gVDIowHIrFUzT/pw036Vyu', 'main_admin', FALSE, 'approved', NULL, '2026-09-25 11:04:33', '2026-09-25 00:50:57', '::1', 'Windows 10/11 • Google Chrome', '2026-09-15 15:10:03');
INSERT INTO users ("id", "username", "full_name", "email", "phone", "password", "role", "must_change_password", "status", "last_login_at", "last_seen_at", "last_logout_at", "last_ip", "last_device", "created_at") VALUES (12, 'Ben', 'Benedict Aquino', 'benedictaquino2005@gmail.com', '09622356546', '$2y$10$O2VJP3CwrWsVo5hrR1iAmuc9Jdzrda8WGrfbryfy7/GFmQt2epKce', 'admin', FALSE, 'approved', NULL, NULL, NULL, NULL, NULL, '2026-09-24 15:18:27');
INSERT INTO users ("id", "username", "full_name", "email", "phone", "password", "role", "must_change_password", "status", "last_login_at", "last_seen_at", "last_logout_at", "last_ip", "last_device", "created_at") VALUES (13, 'bariledale', 'dale anthony b.barile', 'bariledale@gmail.com', NULL, '$2y$10$tiL0MmkZ6blGxX4KSy4IeOb6fQydHz5ycLA12IGxFj9gHrAUzIi82', 'user', FALSE, 'approved', NULL, NULL, NULL, NULL, NULL, '2026-09-24 16:55:56');

SELECT setval(pg_get_serial_sequence('users', 'id'), COALESCE((SELECT MAX(id) FROM users), 1));

-- --------------------------------------------------------
-- DATA FOR: facilities (3 rows)
-- --------------------------------------------------------
INSERT INTO facilities ("id", "name", "description", "capacity", "created_at") VALUES (1, 'Computer Laboratory', '', 60, '2026-09-01 20:45:10');
INSERT INTO facilities ("id", "name", "description", "capacity", "created_at") VALUES (2, 'Grandstand', '', 1000, '2026-09-01 20:46:36');
INSERT INTO facilities ("id", "name", "description", "capacity", "created_at") VALUES (3, 'Room 512', '', 100, '2026-09-01 20:47:21');

SELECT setval(pg_get_serial_sequence('facilities', 'id'), COALESCE((SELECT MAX(id) FROM facilities), 1));1

-- --------------------------------------------------------
-- DATA FOR: bookings (10 rows)
-- --------------------------------------------------------
INSERT INTO bookings ("id", "user_id", "reference_no", "client_name", "client_email", "client_phone", "client_address", "event_title", "event_type", "event_start", "event_end", "guest_count", "location_venue", "service_requirements", "special_notes", "status", "rejection_reason", "source", "created_at", "updated_at") VALUES (11, NULL, 'EV-2026-9103', 'Daniel B.Barile', 'Daniel@gmail.com', '09948042117', 'Muzon Pabahay 2000', 'Daniel''s 21th birthday', 'Birthday Parties', '2026-10-29 14:00:00', '2026-10-29 19:00:00', 100, 'tagaytay garden', 'Sound System, Wireless Microphones, Mood Lighting, Stage & Backdrop, Photo/Video Coverage', 'i want to make it formal', 'approved', NULL, 'online_inquiry', '2026-09-16 22:44:09', '2026-09-16 22:58:20');
INSERT INTO bookings ("id", "user_id", "reference_no", "client_name", "client_email", "client_phone", "client_address", "event_title", "event_type", "event_start", "event_end", "guest_count", "location_venue", "service_requirements", "special_notes", "status", "rejection_reason", "source", "created_at", "updated_at") VALUES (12, NULL, 'EV-2026-8594', 'Dale Barile', 'dale@example.com', '0917-123-4567', '', 'Barile & Santos Nuptials', 'Weddings', '2026-11-20 14:00:00', '2026-11-20 19:00:00', 100, 'The Glasshouse Pavillion', 'Sound system, mood lighting, ceremony & reception coordination, wireless microphones, stage & backdrop, photo/video coverage, catering coordination, emcee/host', '[Booked via AI Chatbot Concierge]', 'approved', NULL, 'online_inquiry', '2026-09-18 21:00:21', '2026-09-19 08:00:00');
INSERT INTO bookings ("id", "user_id", "reference_no", "client_name", "client_email", "client_phone", "client_address", "event_title", "event_type", "event_start", "event_end", "guest_count", "location_venue", "service_requirements", "special_notes", "status", "rejection_reason", "source", "created_at", "updated_at") VALUES (13, NULL, 'EV-2026-2360', 'Alex Smith', 'alex@example.com', '0917-123-4567', 'N/A', 'Smith-Taylor Wedding', 'Weddings', '1970-01-01 01:00:00', '1970-01-01 01:00:00', 100, 'Grand Ballroom', 'N/A', 'N/A [Booked via AI Chatbot Concierge]', 'approved', NULL, 'online_inquiry', '2026-09-18 21:03:02', '2026-09-19 07:59:47');
INSERT INTO bookings ("id", "user_id", "reference_no", "client_name", "client_email", "client_phone", "client_address", "event_title", "event_type", "event_start", "event_end", "guest_count", "location_venue", "service_requirements", "special_notes", "status", "rejection_reason", "source", "created_at", "updated_at") VALUES (14, NULL, 'EV-2026-0654', 'lorenz dalma', 'lorenz@gmail.com', '09933053982', 'graceville ,sjdm Bulacan', '21  Birthday', 'Birthday Parties', '2026-09-23 14:00:00', '2026-09-23 19:00:00', 30, 'philippine arena', 'Stage & Backdrop, Photo/Video Coverage, Catering Coordination', 'black and white theme nsblkasf;lanf', 'approved', NULL, 'online_inquiry', '2026-09-19 10:02:04', '2026-09-19 10:06:27');
INSERT INTO bookings ("id", "user_id", "reference_no", "client_name", "client_email", "client_phone", "client_address", "event_title", "event_type", "event_start", "event_end", "guest_count", "location_venue", "service_requirements", "special_notes", "status", "rejection_reason", "source", "created_at", "updated_at") VALUES (16, NULL, 'EV-2026-5169', 'dale barile', 'dale@gmail.com', '099948042117', 'muzon , pabahay 200o', '21  Birthday', 'Debut & Others', '2026-09-19 14:00:00', '2026-09-19 19:00:00', 30, 'manila  hotel', 'Sound System, Wireless Microphones, Mood Lighting, Stage & Backdrop, Photo/Video Coverage', 'black and white theme', 'approved', NULL, 'online_inquiry', '2026-09-19 12:44:09', '2026-09-19 12:47:45');
INSERT INTO bookings ("id", "user_id", "reference_no", "client_name", "client_email", "client_phone", "client_address", "event_title", "event_type", "event_start", "event_end", "guest_count", "location_venue", "service_requirements", "special_notes", "status", "rejection_reason", "source", "created_at", "updated_at") VALUES (17, NULL, 'EV-2026-3730', 'Dezzell Allen B. Barile', 'dabarile@gmail.com', '099519316505', 'muzon south pabahay 2000', '24th birthday', 'Birthday Parties', '2026-09-24 14:00:00', '2026-09-24 19:00:00', 100, 'manila hotel', 'Sound System, Wireless Microphones, Mood Lighting, Stage & Backdrop, Photo/Video Coverage', 'red and black theme', 'approved', NULL, 'online_inquiry', '2026-09-19 18:11:29', '2026-09-19 18:13:52');
INSERT INTO bookings ("id", "user_id", "reference_no", "client_name", "client_email", "client_phone", "client_address", "event_title", "event_type", "event_start", "event_end", "guest_count", "location_venue", "service_requirements", "special_notes", "status", "rejection_reason", "source", "created_at", "updated_at") VALUES (20, NULL, 'EV-2026-7788', 'sandry antang', 'antang.sandree64@gmail.com', '794279124012', 'quezon city', '21 birthday', 'Birthday Parties', '2026-10-22 16:00:00', '2026-10-22 21:00:00', 100, 'manila hotel', 'Sound System, Wireless Microphones, Mood Lighting, Stage & Backdrop, Photo/Video Coverage', 'black and white theme and sure the ballon design is elegant', 'approved', NULL, 'online_inquiry', '2026-09-21 11:35:05', '2026-09-21 11:38:39');
INSERT INTO bookings ("id", "user_id", "reference_no", "client_name", "client_email", "client_phone", "client_address", "event_title", "event_type", "event_start", "event_end", "guest_count", "location_venue", "service_requirements", "special_notes", "status", "rejection_reason", "source", "created_at", "updated_at") VALUES (21, NULL, 'EV-2026-M7439', 'Dale Anthony B. Barile', 'bariledale@gmail.com', '09948042117', 'San Jose del Monte', '22nd Birthday', 'Birthday Parties', '2027-03-21 14:00:00', '2027-03-21 19:00:00', 100, 'grotto vista resort', 'Sound System, Wireless Microphones, Mood Lighting, Stage & Backdrop', 'dbsdkabsaksf;as', 'approved', NULL, 'manual_entry', '2026-09-21 22:43:40', '2026-09-21 22:43:40');
INSERT INTO bookings ("id", "user_id", "reference_no", "client_name", "client_email", "client_phone", "client_address", "event_title", "event_type", "event_start", "event_end", "guest_count", "location_venue", "service_requirements", "special_notes", "status", "rejection_reason", "source", "created_at", "updated_at") VALUES (22, NULL, 'EV-2026-9942', 'Dale Anthony B. Barile', 'bariledale@gmail.com', '09948042117', 'pabahay2000, muzon south , SJDM', '22 birthday', 'Corporate Events', '2026-10-21 15:00:00', '2026-10-21 20:00:00', 100, 'tungko mangga', 'Sound System, Wireless Microphones, Mood Lighting, Stage & Backdrop, Photo/Video Coverage', 'make sure the designs will be a detailed', 'approved', NULL, 'online_inquiry', '2026-09-21 23:15:27', '2026-09-21 23:18:29');
INSERT INTO bookings ("id", "user_id", "reference_no", "client_name", "client_email", "client_phone", "client_address", "event_title", "event_type", "event_start", "event_end", "guest_count", "location_venue", "service_requirements", "special_notes", "status", "rejection_reason", "source", "created_at", "updated_at") VALUES (23, NULL, 'EV-2026-7041', 'Sandree Antang', 'antang.sandree.lebico@gmail.com', '09948042117', 'Test', 'test', 'Weddings', '2027-03-23 14:00:00', '2027-03-23 19:00:00', 100, 'test', 'Sound System, Wireless Microphones, Mood Lighting, Stage & Backdrop', 'test', 'approved', NULL, 'online_inquiry', '2026-09-22 09:33:56', '2026-09-22 09:37:04');

SELECT setval(pg_get_serial_sequence('bookings', 'id'), COALESCE((SELECT MAX(id) FROM bookings), 1));

-- --------------------------------------------------------
-- DATA FOR: events (3 rows)
-- --------------------------------------------------------
INSERT INTO events ("id", "user_id", "facility_id", "title", "description", "attendees_count", "start_time", "end_time", "status", "created_at") VALUES (4, 3, 2, 'pampanga', 'sd', 100, '2026-09-12 08:33:00', '2026-09-14 09:33:00', 'approved', '2026-09-11 08:34:19');
INSERT INTO events ("id", "user_id", "facility_id", "title", "description", "attendees_count", "start_time", "end_time", "status", "created_at") VALUES (5, 3, 1, 'kontra droga', '', 60, '2026-09-12 08:30:00', '2026-09-12 09:30:00', 'approved', '2026-09-11 09:14:52');
INSERT INTO events ("id", "user_id", "facility_id", "title", "description", "attendees_count", "start_time", "end_time", "status", "created_at") VALUES (7, 5, 3, 'birthday', 'hfsdouagsdla', 100, '2026-09-15 15:00:00', '2026-09-15 17:00:00', 'approved', '2026-09-13 17:50:08');

SELECT setval(pg_get_serial_sequence('events', 'id'), COALESCE((SELECT MAX(id) FROM events), 1));

-- --------------------------------------------------------
-- DATA FOR: chat_sessions (0 rows)
-- --------------------------------------------------------
SELECT setval(pg_get_serial_sequence('chat_sessions', 'id'), COALESCE((SELECT MAX(id) FROM chat_sessions), 1));

-- --------------------------------------------------------
-- DATA FOR: chat_messages (0 rows)
-- --------------------------------------------------------
SELECT setval(pg_get_serial_sequence('chat_messages', 'id'), COALESCE((SELECT MAX(id) FROM chat_messages), 1));

-- --------------------------------------------------------
-- DATA FOR: notifications (48 rows)
-- --------------------------------------------------------
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (1, 6, 'Garcia & Santos', '0917-888-1234', 'sms', 'approval', 'Booking Confirmed', 'Hello Garcia & Santos, your booking for Wedding - Garcia & Santos on 2026-04-26 has been APPROVED! Our coordinator will contact you soon. Ref: EV-2026-006', 'sent', '2026-04-25 10:30:00');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (2, 7, 'ABC Corp.', 'events@abccorp.ph', 'email', 'approval', 'Booking Confirmed - ABC Corp Annual Summit', 'Dear ABC Corp., we are delighted to confirm your Corporate Event reservation on 2026-04-28. Ref: EV-2026-007.', 'sent', '2026-04-25 10:15:00');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (3, 8, 'Reyes Family', '0915-444-2222', 'sms', 'reminder', 'Event Reminder', 'Hi Reyes Family! Friendly reminder that your Birthday Party - Reyes is coming up on 2026-05-03. Ref: EV-2026-008', 'sent', '2026-04-24 17:20:00');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (4, 1, 'Maria Santos', '0917-123-4567', 'sms', 'rejection', 'Event Booking Update - Santos & Reyes Wedding', 'Hello Maria Santos, unfortunately we are unavailable for your requested date (Apr 20, 2026) due to: sdasfnaksbfasbfas;lfaf. Please contact us to reschedule. Ref: EV-2026-001', 'sent', '2026-09-16 10:30:59');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (5, 1, 'Maria Santos', 'maria.santos@gmail.com', 'email', 'rejection', 'Event Booking Update - Santos & Reyes Wedding', 'Dear Maria Santos,

Thank you for your inquiry regarding ''Santos & Reyes Wedding'' (Ref: EV-2026-001).

Regrettably, we are unable to accept your booking for Apr 20, 2026 at this time.
Reason: sdasfnaksbfasbfas;lfaf

We would love to help you find an alternative date or make custom arrangements. Please reply to this message or give us a call.

Sincerely,
SCHEDFIX Team', 'sent', '2026-09-16 10:30:59');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (6, 10, 'David Miller', '0917-555-1234', 'sms', 'rejection', 'Event Booking Update - Miller Grand Wedding', 'Hello David Miller, unfortunately we are unavailable for your requested date (Nov 20, 2026) due to: sdasfasfasfasfa. Please contact us to reschedule. Ref: EV-2026-1076', 'sent', '2026-09-16 10:31:05');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (7, 10, 'David Miller', 'david.m@test.com', 'email', 'rejection', 'Event Booking Update - Miller Grand Wedding', 'Dear David Miller,

Thank you for your inquiry regarding ''Miller Grand Wedding'' (Ref: EV-2026-1076).

Regrettably, we are unable to accept your booking for Nov 20, 2026 at this time.
Reason: sdasfasfasfasfa

We would love to help you find an alternative date or make custom arrangements. Please reply to this message or give us a call.

Sincerely,
SCHEDFIX Team', 'sent', '2026-09-16 10:31:05');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (8, 5, 'Liza Fernandez', '0919-567-8901', 'sms', 'approval', 'Booking Confirmed - Fernandez-David Nuptials', 'Hello Liza Fernandez, your booking for Fernandez-David Nuptials on Apr 24, 2026 1:00 PM has been APPROVED! Our team will contact you for coordination and downpayment details. Ref: EV-2026-005', 'sent', '2026-09-16 10:31:20');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (9, 5, 'Liza Fernandez', 'liza.f@gmail.com', 'email', 'approval', 'Booking Confirmed - Fernandez-David Nuptials', 'Dear Liza Fernandez,

We are thrilled to inform you that your booking request for ''Fernandez-David Nuptials'' has been officially APPROVED!

Event Details:
- Reference Number: EV-2026-005
- Date & Time: Apr 24, 2026 1:00 PM
- Venue: St. Jude Reception Pavilion
- Type: Weddings
- Expected Guests: 180

Next Steps:
An event coordinator will get in touch with you shortly to finalize the setup requirements and process the reservation deposit.

Thank you for choosing SCHEDFIX!
Warm regards,
SCHEDFIX Planning Team', 'sent', '2026-09-16 10:31:20');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (10, 4, 'Paolo Garcia', '0922-456-7890', 'sms', 'rejection', 'Event Booking Update - Sofia 18th Enchanted Debut', 'Hello Paolo Garcia, unfortunately we are unavailable for your requested date (Apr 23, 2026) due to: iqfhofa[fajfafaofha[f. Please contact us to reschedule. Ref: EV-2026-004', 'sent', '2026-09-16 22:48:29');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (11, 4, 'Paolo Garcia', 'paolo.g@gmail.com', 'email', 'rejection', 'Event Booking Update - Sofia 18th Enchanted Debut', 'Dear Paolo Garcia,

Thank you for your inquiry regarding ''Sofia 18th Enchanted Debut'' (Ref: EV-2026-004).

Regrettably, we are unable to accept your booking for Apr 23, 2026 at this time.
Reason: iqfhofa[fajfafaofha[f

We would love to help you find an alternative date or make custom arrangements. Please reply to this message or give us a call.

Sincerely,
SCHEDFIX Team', 'sent', '2026-09-16 22:48:29');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (12, 3, 'Ana Reyes', '0920-345-6789', 'sms', 'rejection', 'Event Booking Update - Liam 7th Superhero Birthday', 'Hello Ana Reyes, unfortunately we are unavailable for your requested date (Apr 22, 2026) due to: asdbaishdashd;ad. Please contact us to reschedule. Ref: EV-2026-003', 'sent', '2026-09-16 22:48:36');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (13, 3, 'Ana Reyes', 'ana.reyes@yahoo.com', 'email', 'rejection', 'Event Booking Update - Liam 7th Superhero Birthday', 'Dear Ana Reyes,

Thank you for your inquiry regarding ''Liam 7th Superhero Birthday'' (Ref: EV-2026-003).

Regrettably, we are unable to accept your booking for Apr 22, 2026 at this time.
Reason: asdbaishdashd;ad

We would love to help you find an alternative date or make custom arrangements. Please reply to this message or give us a call.

Sincerely,
SCHEDFIX Team', 'sent', '2026-09-16 22:48:36');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (14, 2, 'John Cruz', '0918-234-5678', 'sms', 'rejection', 'Event Booking Update - ABC Corp Annual Summit', 'Hello John Cruz, unfortunately we are unavailable for your requested date (Apr 21, 2026) due to: sdbasdb;asbfas. Please contact us to reschedule. Ref: EV-2026-002', 'sent', '2026-09-16 22:48:42');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (15, 2, 'John Cruz', 'john.cruz@techcorp.com', 'email', 'rejection', 'Event Booking Update - ABC Corp Annual Summit', 'Dear John Cruz,

Thank you for your inquiry regarding ''ABC Corp Annual Summit'' (Ref: EV-2026-002).

Regrettably, we are unable to accept your booking for Apr 21, 2026 at this time.
Reason: sdbasdb;asbfas

We would love to help you find an alternative date or make custom arrangements. Please reply to this message or give us a call.

Sincerely,
SCHEDFIX Team', 'sent', '2026-09-16 22:48:42');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (16, 5, 'Liza Fernandez', '0919-567-8901', 'sms', 'rejection', 'Event Booking Update - Fernandez-David Nuptials', 'Hello Liza Fernandez, unfortunately we are unavailable for your requested date (Apr 24, 2026) due to: ckjflj. Please contact us to reschedule. Ref: EV-2026-005', 'sent', '2026-09-16 22:49:05');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (17, 5, 'Liza Fernandez', 'liza.f@gmail.com', 'email', 'rejection', 'Event Booking Update - Fernandez-David Nuptials', 'Dear Liza Fernandez,

Thank you for your inquiry regarding ''Fernandez-David Nuptials'' (Ref: EV-2026-005).

Regrettably, we are unable to accept your booking for Apr 24, 2026 at this time.
Reason: ckjflj

We would love to help you find an alternative date or make custom arrangements. Please reply to this message or give us a call.

Sincerely,
SCHEDFIX Team', 'sent', '2026-09-16 22:49:05');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (18, 8, 'Reyes Family', '0915-444-2222', 'sms', 'rejection', 'Event Booking Update - Birthday Party - Reyes', 'Hello Reyes Family, unfortunately we are unavailable for your requested date (May 03, 2026) due to: sdklbalskdbkasdba. Please contact us to reschedule. Ref: EV-2026-008', 'sent', '2026-09-16 22:49:10');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (19, 8, 'Reyes Family', 'reyes.family@gmail.com', 'email', 'rejection', 'Event Booking Update - Birthday Party - Reyes', 'Dear Reyes Family,

Thank you for your inquiry regarding ''Birthday Party - Reyes'' (Ref: EV-2026-008).

Regrettably, we are unable to accept your booking for May 03, 2026 at this time.
Reason: sdklbalskdbkasdba

We would love to help you find an alternative date or make custom arrangements. Please reply to this message or give us a call.

Sincerely,
SCHEDFIX Team', 'sent', '2026-09-16 22:49:10');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (20, 7, 'ABC Corp.', '0917-999-5678', 'sms', 'rejection', 'Event Booking Update - Corporate Event - ABC Corp.', 'Hello ABC Corp., unfortunately we are unavailable for your requested date (Apr 28, 2026) due to: dasblasbdk;sfa. Please contact us to reschedule. Ref: EV-2026-007', 'sent', '2026-09-16 22:49:16');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (21, 7, 'ABC Corp.', 'events@abccorp.ph', 'email', 'rejection', 'Event Booking Update - Corporate Event - ABC Corp.', 'Dear ABC Corp.,

Thank you for your inquiry regarding ''Corporate Event - ABC Corp.'' (Ref: EV-2026-007).

Regrettably, we are unable to accept your booking for Apr 28, 2026 at this time.
Reason: dasblasbdk;sfa

We would love to help you find an alternative date or make custom arrangements. Please reply to this message or give us a call.

Sincerely,
SCHEDFIX Team', 'sent', '2026-09-16 22:49:16');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (22, 9, 'Elena Mendoza', '0918-777-3333', 'sms', 'rejection', 'Event Booking Update - Debut - Mendoza 18th', 'Hello Elena Mendoza, unfortunately we are unavailable for your requested date (Apr 18, 2026) due to: asdjlabsjabslddas. Please contact us to reschedule. Ref: EV-2026-009', 'sent', '2026-09-16 22:49:23');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (23, 9, 'Elena Mendoza', 'elena.mendoza@gmail.com', 'email', 'rejection', 'Event Booking Update - Debut - Mendoza 18th', 'Dear Elena Mendoza,

Thank you for your inquiry regarding ''Debut - Mendoza 18th'' (Ref: EV-2026-009).

Regrettably, we are unable to accept your booking for Apr 18, 2026 at this time.
Reason: asdjlabsjabslddas

We would love to help you find an alternative date or make custom arrangements. Please reply to this message or give us a call.

Sincerely,
SCHEDFIX Team', 'sent', '2026-09-16 22:49:23');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (24, 6, 'Garcia & Santos', '0917-888-1234', 'sms', 'rejection', 'Event Booking Update - Wedding - Garcia & Santos', 'Hello Garcia & Santos, unfortunately we are unavailable for your requested date (Apr 26, 2026) due to: asdlasbkasnd;lasda. Please contact us to reschedule. Ref: EV-2026-006', 'sent', '2026-09-16 22:49:29');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (25, 6, 'Garcia & Santos', 'garcia.santos@gmail.com', 'email', 'rejection', 'Event Booking Update - Wedding - Garcia & Santos', 'Dear Garcia &amp; Santos,

Thank you for your inquiry regarding ''Wedding - Garcia & Santos'' (Ref: EV-2026-006).

Regrettably, we are unable to accept your booking for Apr 26, 2026 at this time.
Reason: asdlasbkasnd;lasda

We would love to help you find an alternative date or make custom arrangements. Please reply to this message or give us a call.

Sincerely,
SCHEDFIX Team', 'sent', '2026-09-16 22:49:29');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (26, 6, 'Garcia & Santos', '0917-888-1234', 'sms', 'rejection', 'Event Booking Update - Wedding - Garcia & Santos', 'Hello Garcia & Santos, unfortunately we are unavailable for your requested date (Apr 26, 2026) due to: asdlasbkasnd;lasda. Please contact us to reschedule. Ref: EV-2026-006', 'sent', '2026-09-16 22:57:00');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (27, 6, 'Garcia & Santos', 'garcia.santos@gmail.com', 'email', 'rejection', 'Event Booking Update - Wedding - Garcia & Santos', 'Dear Garcia &amp; Santos,

Thank you for your inquiry regarding ''Wedding - Garcia & Santos'' (Ref: EV-2026-006).

Regrettably, we are unable to accept your booking for Apr 26, 2026 at this time.
Reason: asdlasbkasnd;lasda

We would love to help you find an alternative date or make custom arrangements. Please reply to this message or give us a call.

Sincerely,
SCHEDFIX Team', 'sent', '2026-09-16 22:57:00');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (28, 11, 'Daniel B.Barile', '09948042117', 'sms', 'approval', 'Booking Confirmed - Daniel''s 21th birthday', 'Hello Daniel B.Barile, your booking for Daniel''s 21th birthday on Oct 29, 2026 2:00 PM has been APPROVED! Our team will contact you for coordination and downpayment details. Ref: EV-2026-9103', 'sent', '2026-09-16 22:58:20');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (29, 11, 'Daniel B.Barile', 'Daniel@gmail.com', 'email', 'approval', 'Booking Confirmed - Daniel''s 21th birthday', 'Dear Daniel B.Barile,

We are thrilled to inform you that your booking request for ''Daniel''s 21th birthday'' has been officially APPROVED!

Event Details:
- Reference Number: EV-2026-9103
- Date & Time: Oct 29, 2026 2:00 PM
- Venue: tagaytay garden
- Type: Birthday Parties
- Expected Guests: 100

Next Steps:
An event coordinator will get in touch with you shortly to finalize the setup requirements and process the reservation deposit.

Thank you for choosing SCHEDFIX!
Warm regards,
SCHEDFIX Planning Team', 'sent', '2026-09-16 22:58:20');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (30, 12, 'Dale Barile', '0917-123-4567', 'sms', 'custom', 'New Booking Received', 'New AI Chatbot Booking received: Barile & Santos Nuptials on 2026-11-20 by Dale Barile (0917-123-4567). Reference: EV-2026-8594', 'sent', '2026-09-18 21:00:21');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (31, 13, 'Alex Smith', '0917-123-4567', 'sms', 'custom', 'New Booking Received', 'New AI Chatbot Booking received: Smith-Taylor Wedding on 2026-11-25 by Alex Smith (0917-123-4567). Reference: EV-2026-2360', 'sent', '2026-09-18 21:03:02');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (32, 13, 'Alex Smith', '0917-123-4567', 'sms', 'approval', 'Booking Confirmed - Smith-Taylor Wedding', 'Hello Alex Smith, your booking for Smith-Taylor Wedding on Jan 01, 1970 1:00 AM has been APPROVED! Our team will contact you for coordination and downpayment details. Ref: EV-2026-2360', 'sent', '2026-09-19 07:59:47');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (33, 13, 'Alex Smith', 'alex@example.com', 'email', 'approval', 'Booking Confirmed - Smith-Taylor Wedding', 'Dear Alex Smith,

We are thrilled to inform you that your booking request for ''Smith-Taylor Wedding'' has been officially APPROVED!

Event Details:
- Reference Number: EV-2026-2360
- Date & Time: Jan 01, 1970 1:00 AM
- Venue: Grand Ballroom
- Type: Weddings
- Expected Guests: 100

Next Steps:
An event coordinator will get in touch with you shortly to finalize the setup requirements and process the reservation deposit.

Thank you for choosing SCHEDFIX!
Warm regards,
SCHEDFIX Planning Team', 'sent', '2026-09-19 07:59:47');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (34, 12, 'Dale Barile', '0917-123-4567', 'sms', 'approval', 'Booking Confirmed - Barile & Santos Nuptials', 'Hello Dale Barile, your booking for Barile & Santos Nuptials on Nov 20, 2026 2:00 PM has been APPROVED! Our team will contact you for coordination and downpayment details. Ref: EV-2026-8594', 'sent', '2026-09-19 08:00:00');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (35, 12, 'Dale Barile', 'dale@example.com', 'email', 'approval', 'Booking Confirmed - Barile & Santos Nuptials', 'Dear Dale Barile,

We are thrilled to inform you that your booking request for ''Barile & Santos Nuptials'' has been officially APPROVED!

Event Details:
- Reference Number: EV-2026-8594
- Date & Time: Nov 20, 2026 2:00 PM
- Venue: The Glasshouse Pavillion
- Type: Weddings
- Expected Guests: 100

Next Steps:
An event coordinator will get in touch with you shortly to finalize the setup requirements and process the reservation deposit.

Thank you for choosing SCHEDFIX!
Warm regards,
SCHEDFIX Planning Team', 'sent', '2026-09-19 08:00:00');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (36, 14, 'lorenz dalma', '09933053982', 'sms', 'approval', 'Booking Confirmed - 21  Birthday', 'Hello lorenz dalma, your booking for 21  Birthday on Sep 23, 2026 2:00 PM has been APPROVED! Our team will contact you for coordination and downpayment details. Ref: EV-2026-0654', 'sent', '2026-09-19 10:06:27');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (37, 14, 'lorenz dalma', 'lorenz@gmail.com', 'email', 'approval', 'Booking Confirmed - 21  Birthday', 'Dear lorenz dalma,

We are thrilled to inform you that your booking request for ''21  Birthday'' has been officially APPROVED!

Event Details:
- Reference Number: EV-2026-0654
- Date & Time: Sep 23, 2026 2:00 PM
- Venue: philippine arena
- Type: Birthday Parties
- Expected Guests: 30

Next Steps:
An event coordinator will get in touch with you shortly to finalize the setup requirements and process the reservation deposit.

Thank you for choosing SCHEDFIX!
Warm regards,
SCHEDFIX Planning Team', 'sent', '2026-09-19 10:06:27');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (38, 14, 'lorenz dalma', '09933053982', 'sms', 'custom', 'Update Regarding Your Event - SCHEDFIX', 'Hello Client, our lead event coordinator would like to schedule a quick 15-minute alignment call with you to finalize your program and setup details. Please let us know your available time today.', 'sent', '2026-09-19 10:09:52');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (39, 15, 'Juan Dela Cruz', '+63 917 123 4567', 'sms', 'custom', 'New Booking Received', 'New AI Chatbot Booking received: Juan & Maria Wedding on 2027-02-14 by Juan Dela Cruz (+63 917 123 4567). Reference: EV-2026-7601', 'sent', '2026-09-19 10:13:16');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (40, 16, 'dale barile', '099948042117', 'sms', 'approval', 'Booking Confirmed - 21  Birthday', 'Hello dale barile, your booking for 21  Birthday on Sep 19, 2026 2:00 PM has been APPROVED! Our team will contact you for coordination and downpayment details. Ref: EV-2026-5169', 'sent', '2026-09-19 12:47:45');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (41, 16, 'dale barile', 'dale@gmail.com', 'email', 'approval', 'Booking Confirmed - 21  Birthday', 'Dear dale barile,

We are thrilled to inform you that your booking request for ''21  Birthday'' has been officially APPROVED!

Event Details:
- Reference Number: EV-2026-5169
- Date & Time: Sep 19, 2026 2:00 PM
- Venue: manila  hotel
- Type: Debut & Others
- Expected Guests: 30

Next Steps:
An event coordinator will get in touch with you shortly to finalize the setup requirements and process the reservation deposit.

Thank you for choosing SCHEDFIX!
Warm regards,
SCHEDFIX Planning Team', 'sent', '2026-09-19 12:47:45');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (42, 17, 'Dezzell Allen B. Barile', '099519316505', 'sms', 'approval', 'Booking Confirmed - 24th birthday', 'Hello Dezzell Allen B. Barile, your booking for 24th birthday on Sep 24, 2026 2:00 PM has been APPROVED! Our team will contact you for coordination and downpayment details. Ref: EV-2026-3730', 'sent', '2026-09-19 18:13:52');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (43, 17, 'Dezzell Allen B. Barile', 'dabarile@gmail.com', 'email', 'approval', 'Booking Confirmed - 24th birthday', 'Dear Dezzell Allen B. Barile,

We are thrilled to inform you that your booking request for ''24th birthday'' has been officially APPROVED!

Event Details:
- Reference Number: EV-2026-3730
- Date & Time: Sep 24, 2026 2:00 PM
- Venue: manila hotel
- Type: Birthday Parties
- Expected Guests: 100

Next Steps:
An event coordinator will get in touch with you shortly to finalize the setup requirements and process the reservation deposit.

Thank you for choosing SCHEDFIX!
Warm regards,
SCHEDFIX Planning Team', 'sent', '2026-09-19 18:13:52');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (44, 20, 'sandry antang', 'antang.sandree64@gmail.com', 'email', 'approval', 'Booking Confirmed - 21 birthday', 'Dear sandry antang,

Your booking for ''21 birthday'' has been officially APPROVED!

Event Details:
- Reference: EV-2026-7788
- Date & Time: Oct 22, 2026 4:00 PM
- Venue: manila hotel
- Type: Birthday Parties
- Expected Guests: 100

Our coordinator will reach out to finalize details and process the reservation deposit.

Thank you for choosing Tyoy Creation!
Warm regards,
Tyoy Creation Planning Team', 'sent', '2026-09-21 11:38:44');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (45, 21, 'Dale Anthony B. Barile', 'bariledale@gmail.com', 'email', 'approval', 'Booking Confirmed - 22nd Birthday', 'Dear Dale Anthony B. Barile,

Your booking for ''22nd Birthday'' has been officially APPROVED!

Event Details:
- Reference: EV-2026-M7439
- Date & Time: Mar 21, 2027 2:00 PM
- Venue: grotto vista resort
- Type: Birthday Parties
- Expected Guests: 100

Our coordinator will reach out to finalize details and process the reservation deposit.

Thank you for choosing Tyoy Creation!
Warm regards,
Tyoy Creation Planning Team', 'sent', '2026-09-21 22:43:44');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (46, 22, 'Dale Anthony B. Barile', 'bariledale@gmail.com', 'email', 'approval', 'Booking Confirmed - 22 birthday', 'Dear Dale Anthony B. Barile,

Your booking for ''22 birthday'' has been officially APPROVED!

Event Details:
- Reference: EV-2026-9942
- Date & Time: Oct 21, 2026 3:00 PM
- Venue: tungko mangga
- Type: Corporate Events
- Expected Guests: 100

Our coordinator will reach out to finalize details and process the reservation deposit.

Thank you for choosing Tyoy Creation!
Warm regards,
Tyoy Creation Planning Team', 'sent', '2026-09-21 23:18:32');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (47, 23, 'Sandree Antang', 'antang.sandree.lebico@gmail.com', 'email', 'approval', 'Booking Confirmed - test', 'Dear Sandree Antang,

Your booking for ''test'' has been officially APPROVED!

Event Details:
- Reference: EV-2026-7041
- Date & Time: Mar 23, 2027 2:00 PM
- Venue: test
- Type: Weddings
- Expected Guests: 100

Our coordinator will reach out to finalize details and process the reservation deposit.

Thank you for choosing Tyoy Creation!
Warm regards,
Tyoy Creation Planning Team', 'sent', '2026-09-22 09:37:08');
INSERT INTO notifications ("id", "booking_id", "recipient_name", "recipient_contact", "channel", "template_type", "subject", "message", "status", "created_at") VALUES (48, 15, 'Juan Dela Cruz', 'juan.delacruz@email.com', 'email', 'rejection', 'Event Booking Update - Juan & Maria Wedding', 'Dear Juan Dela Cruz,

Thank you for your inquiry regarding ''Juan & Maria Wedding'' (Ref: EV-2026-7601).

Regrettably, we are unable to accept your booking for Feb 14, 2027.
Reason: dsadasdsdsd

We would love to help you find an alternative date. Please reply or give us a call.

Sincerely,
Tyoy Creation Team', 'sent', '2026-09-23 23:01:40');

SELECT setval(pg_get_serial_sequence('notifications', 'id'), COALESCE((SELECT MAX(id) FROM notifications), 1));

-- --------------------------------------------------------
-- DATA FOR: settings (7 rows)
-- --------------------------------------------------------
INSERT INTO settings ("setting_key", "setting_value") VALUES ('business_address', '123 Grand Ballroom Avenue, Metro Manila, Philippines');
INSERT INTO settings ("setting_key", "setting_value") VALUES ('business_name', 'Tyoy Creation');
INSERT INTO settings ("setting_key", "setting_value") VALUES ('business_tagline', 'FLOWERS & BALLOONS • Event Styling & Coordination');
INSERT INTO settings ("setting_key", "setting_value") VALUES ('contact_email', 'contact@tyoycreation.com');
INSERT INTO settings ("setting_key", "setting_value") VALUES ('contact_phone', '+63 912 345 6789');
INSERT INTO settings ("setting_key", "setting_value") VALUES ('gemini_daily_api_count', '64');
INSERT INTO settings ("setting_key", "setting_value") VALUES ('pricing_packages', '{"wedding":{"catering":{"category_title":"Catering Service (Pax Capacity)","icon":"fa-utensils","package":"custom","type":"radio","field":"svc_catering","badge":"Pick One","items":[{"id":"w_cat_75","label":"75 pax","price":37000,"value":"Catering: 75 pax"},{"id":"w_cat_100","label":"100 pax","price":49000,"value":"Catering: 100 pax"},{"id":"w_cat_150","label":"150 pax","price":72000,"value":"Catering: 150 pax"},{"id":"w_cat_200","label":"200 pax","price":96000,"value":"Catering: 200 pax"},{"id":"w_cat_300","label":"300 pax","price":141000,"value":"Catering: 300 pax"},{"id":"w_cat_400","label":"400 pax","price":188000,"value":"Catering: 400 pax"},{"id":"w_cat_500","label":"500 pax","price":235000,"value":"Catering: 500 pax"}]},"styling":{"category_title":"Styling","icon":"fa-wand-magic-sparkles","package":"custom","type":"radio","field":"svc_styling","badge":"Pick One","items":[{"id":"w_style_basic","label":"Basic","price":0,"value":"Styling: Basic"},{"id":"w_style_reg","label":"Regular Package","price":20000,"value":"Styling: Regular Package"},{"id":"w_style_up_ceil","label":"Upgraded Backdrop With Ceiling","price":45000,"value":"Styling: Upgraded Backdrop With Ceiling"},{"id":"w_style_up_prem","label":"Upgraded Premium Ceiling Treatment","price":60000,"value":"Styling: Upgraded Premium Ceiling Treatment"}]},"entourage_flower":{"category_title":"Entourage Flower","icon":"fa-spa","package":"custom styling_package","type":"radio","field":"svc_entourage_flower","badge":"Pick One","items":[{"id":"w_ef_basic","label":"Basic","price":10000,"value":"Entourage Flower: Basic"},{"id":"w_ef_reg","label":"Regular Package","price":12000,"value":"Entourage Flower: Regular Package"},{"id":"w_ef_mix","label":"Upgraded Mix Local & Imported","price":15000,"value":"Entourage Flower: Upgraded Mix Local & Imported"},{"id":"w_ef_imp","label":"Upgraded All Imported","price":17000,"value":"Entourage Flower: Upgraded All Imported"}]},"ceremony_styling":{"category_title":"Ceremony Styling","icon":"fa-church","package":"custom styling_package","type":"radio","field":"svc_ceremony_styling","badge":"Pick One","items":[{"id":"w_cs_church","label":"Regular Church Styling","price":17000,"value":"Ceremony Styling: Regular Church Styling"},{"id":"w_cs_garden","label":"Regular Garden Package","price":20000,"value":"Ceremony Styling: Regular Garden Package"},{"id":"w_cs_mix_church","label":"Upgraded Mix Local & Imported (Church)","price":25000,"value":"Ceremony Styling: Upgraded Mix Local & Imported (Church)"},{"id":"w_cs_mix_garden","label":"Upgraded Mix Local & Imported (Garden)","price":25000,"value":"Ceremony Styling: Upgraded Mix Local & Imported (Garden)"}]},"sound_lights":{"category_title":"Sound & Lights","icon":"fa-music","package":"custom","type":"radio","field":"svc_sound","badge":"Pick One","items":[{"id":"w_snd_basic","label":"Basic","price":6500,"value":"Sound & Lights: Basic"},{"id":"w_snd_med","label":"Medium","price":8500,"value":"Sound & Lights: Medium"},{"id":"w_snd_prem","label":"Premium","price":15000,"value":"Sound & Lights: Premium"}]},"entertainment":{"category_title":"Entertainment","icon":"fa-masks-theater","package":"custom","type":"radio","field":"svc_entertainment","badge":"Pick One","items":[{"id":"w_ent_reg","label":"Regular Host","price":5000,"value":"Entertainment: Regular Host"},{"id":"w_ent_ruth","label":"Professional Host \"Ruth\"","price":7500,"value":"Entertainment: Professional Host \"Ruth\""},{"id":"w_ent_jen","label":"Popular & Professional Host \"Jen\"","price":25000,"value":"Entertainment: Popular & Professional Host \"Jen\""},{"id":"w_ent_jam","label":"Host \"Jam\"","price":60000,"value":"Entertainment: Host \"Jam\""}]},"food_carts":{"category_title":"Food Carts","icon":"fa-cart-flatbed","package":"custom","type":"checkbox","field":"svc_addons[]","badge":"Pick Any","items":[{"id":"w_fc_pop","label":"PopCorn Carts","price":5000,"value":"Food Cart: PopCorn Carts"},{"id":"w_fc_hotdog","label":"Hotdog Carts","price":5000,"value":"Food Cart: Hotdog Carts"},{"id":"w_fc_sweet","label":"Sweet Corner","price":5000,"value":"Food Cart: Sweet Corner"},{"id":"w_fc_ice","label":"Ice Cream","price":5000,"value":"Food Cart: Ice Cream"},{"id":"w_fc_fries","label":"Fries","price":5000,"value":"Food Cart: Fries"},{"id":"w_fc_nachos","label":"Nachos","price":5000,"value":"Food Cart: Nachos"},{"id":"w_fc_donuts","label":"Donuts","price":5000,"value":"Food Cart: Donuts"},{"id":"w_fc_grazing","label":"Grazing Table w\/ Cold Cuts (100 pax)","price":10500,"value":"Food Cart: Grazing Table with Cold Cuts (good for 100 pax)"}]},"photobooth":{"category_title":"Photobooth","icon":"fa-camera-retro","package":"custom","type":"radio","field":"svc_photobooth","badge":"Pick One","items":[{"id":"w_pb_basic","label":"Basic Paper Frame","price":3500,"value":"Photobooth: Basic Paper Frame"},{"id":"w_pb_magnet","label":"Premium Ref Magnet","price":4500,"value":"Photobooth: Premium Ref Magnet"},{"id":"w_pb_360","label":"Premium 360","price":12000,"value":"Photobooth: Premium 360"}]},"photo_video":{"category_title":"Photo & Video Coverage","icon":"fa-video","package":"custom","type":"radio","field":"svc_photo","badge":"Pick One","items":[{"id":"w_pv_photo","label":"Photo Coverage","price":10000,"value":"Photo & Video: Photo Coverage"},{"id":"w_pv_pv","label":"Photo & Video Coverage","price":18000,"value":"Photo & Video: Photo & Video Coverage"},{"id":"w_pv_mtv","label":"MTV Highlights","price":25000,"value":"Photo & Video: MTV Highlights"},{"id":"w_pv_sde","label":"Sameday Edit","price":45000,"value":"Photo & Video: Sameday Edit"}]},"otd":{"category_title":"OTD Coordination & Planning","icon":"fa-clipboard-list","package":"custom planning_package","type":"radio","field":"svc_otd","badge":"Pick One","items":[{"id":"w_otd_basic","label":"Basic Package","price":25000,"value":"OTD Coordination: Basic Package"},{"id":"w_otd_full","label":"Full Planning & Coordination","price":35000,"value":"OTD Coordination: Full Planning & Coordination"}]},"partner_venue":{"category_title":"Partner Venue","icon":"fa-building","package":"custom","type":"radio","field":"svc_partner_venue","badge":"Pick One","items":[{"id":"w_pv_emerald","label":"Emerald Courtyard","price":35000,"value":"Partner Venue: Emerald Courtyard"}]},"reception_styling":{"category_title":"Reception Styling","icon":"fa-champagne-glasses","package":"styling_package","type":"radio","field":"svc_reception_styling","badge":"Pick One","items":[{"id":"w_rs_basic","label":"Basic","price":15000,"value":"Reception Styling: Basic"},{"id":"w_rs_reg","label":"Regular Package","price":20000,"value":"Reception Styling: Regular Package"},{"id":"w_rs_up_ceil","label":"Upgraded Backdrop With Ceiling","price":65000,"value":"Reception Styling: Upgraded Backdrop With Ceiling"},{"id":"w_rs_up_prem","label":"Upgraded With Premium Ceiling Treatment","price":99000,"value":"Reception Styling: Upgraded With Premium Ceiling Treatment"}]},"ceiling_treatment":{"category_title":"Ceiling Treatment","icon":"fa-house-chimney","package":"styling_package","type":"radio","field":"svc_ceiling","badge":"Pick One","items":[{"id":"w_ceil_no_truss","label":"Regular Package Without Trusses","price":25000,"value":"Ceiling: Regular Package Without Trusses"},{"id":"w_ceil_truss","label":"Regular Package With Trusses","price":45000,"value":"Ceiling: Regular Package With Trusses"},{"id":"w_ceil_up_truss","label":"Upgraded With Trusses","price":70000,"value":"Ceiling: Upgraded With Trusses"},{"id":"w_ceil_up_prem","label":"Upgraded With Premium Ceiling Treatment","price":85000,"value":"Ceiling: Upgraded With Premium Ceiling Treatment"}]}},"theme_party":{"catering":{"category_title":"Catering Service (Pax Capacity)","icon":"fa-utensils","package":"custom catering","type":"radio","field":"svc_catering","badge":"Pick One","items":[{"id":"cat_75","label":"75 pax","price":37000,"value":"Catering: 75 pax"},{"id":"cat_100","label":"100 pax","price":49000,"value":"Catering: 100 pax"},{"id":"cat_150","label":"150 pax","price":72000,"value":"Catering: 150 pax"},{"id":"cat_200","label":"200 pax","price":96000,"value":"Catering: 200 pax"},{"id":"cat_300","label":"300 pax","price":141000,"value":"Catering: 300 pax"},{"id":"cat_400","label":"400 pax","price":188000,"value":"Catering: 400 pax"},{"id":"cat_500","label":"500 pax","price":235000,"value":"Catering: 500 pax"}]},"catering_addons":{"category_title":"Catering Inclusions & Add-ons","icon":"fa-bowl-food","package":"catering","type":"checkbox","field":"svc_catering_addons[]","badge":"Pick Any","items":[{"id":"cat_add_f4","label":"Food: 4 main courses","price":0,"value":"Food: 4 main courses"},{"id":"cat_add_f5","label":"Food: 5 main courses","price":0,"value":"Food: 5 main courses"},{"id":"cat_add_f6","label":"Food: 6 main courses","price":0,"value":"Food: 6 main courses"},{"id":"cat_add_s_basic","label":"Styling: Basic","price":0,"value":"Catering Presentation: Basic"},{"id":"cat_add_s_med","label":"Styling: Medium","price":0,"value":"Catering Presentation: Medium"},{"id":"cat_add_s_prem","label":"Styling: Premium","price":0,"value":"Catering Presentation: Premium"}]},"styling":{"category_title":"Styling","icon":"fa-wand-magic-sparkles","package":"custom","type":"radio","field":"svc_styling","badge":"Pick One","items":[{"id":"bd_basic","label":"Basic","price":0,"value":"Styling: Basic"},{"id":"bd_reg","label":"Regular Package","price":20000,"value":"Styling: Regular Package"},{"id":"bd_up_ceil","label":"Upgraded Backdrop With Ceiling","price":45000,"value":"Styling: Upgraded Backdrop With Ceiling"},{"id":"bd_up_prem","label":"Upgraded Premium Ceiling Treatment","price":60000,"value":"Styling: Upgraded Premium Ceiling Treatment"}]},"theme_styling":{"category_title":"Theme Party Styling","icon":"fa-wand-magic-sparkles","package":"theme_party","type":"radio","field":"svc_theme_styling","badge":"Pick One","items":[{"id":"tp_little","label":"Little Celebration","price":8500,"value":"Theme Styling: Little Celebration"},{"id":"tp_reg","label":"Regular Package","price":15000,"value":"Theme Styling: Regular Package"},{"id":"tp_up_back","label":"Upgraded Backdrop Setup","price":25000,"value":"Theme Styling: Upgraded Backdrop Setup"},{"id":"tp_up_ceil","label":"Upgraded Backdrop With Ceiling","price":40000,"value":"Theme Styling: Upgraded Backdrop With Ceiling"}]},"character":{"category_title":"Character","icon":"fa-shapes","package":"theme_party","type":"radio","field":"svc_character","badge":"Pick One","items":[{"id":"char_styro","label":"Styro Character 2D\/3D","price":0,"value":"Character: Styro Character 2D\/3D"},{"id":"char_printed","label":"Printed Styro Foam Character","price":0,"value":"Character: Printed Styro Foam Character"}]},"sound_lights":{"category_title":"Sound & Lights","icon":"fa-music","package":"custom","type":"radio","field":"svc_sound","badge":"Pick One","items":[{"id":"snd_basic","label":"Basic","price":6500,"value":"Sound & Lights: Basic"},{"id":"snd_med","label":"Medium","price":8500,"value":"Sound & Lights: Medium"},{"id":"snd_prem","label":"Premium","price":15000,"value":"Sound & Lights: Premium"}]},"lightning":{"category_title":"Lightning Setup","icon":"fa-bolt","package":"theme_party","type":"radio","field":"svc_lightning","badge":"Pick One","items":[{"id":"lt_basic","label":"Basic","price":0,"value":"Lightning: Basic"},{"id":"lt_med","label":"Medium","price":4000,"value":"Lightning: Medium"},{"id":"lt_prem","label":"Premium","price":6000,"value":"Lightning: Premium"}]},"ceiling":{"category_title":"Ceiling Treatment","icon":"fa-house-chimney","package":"theme_party","type":"radio","field":"svc_ceiling","badge":"Pick One","items":[{"id":"ceil_basic","label":"Basic Ceiling Treatment (no trusses)","price":25000,"value":"Ceiling: Basic Ceiling Treatment Only without trusses"},{"id":"ceil_up","label":"Upgraded Ceiling Treatment with trusses","price":45000,"value":"Ceiling: Upgraded Ceiling Treatment with trusses"},{"id":"ceil_prem","label":"Upgraded Premium Ceiling Treatment","price":60000,"value":"Ceiling: Upgraded Premium Ceiling Treatment"}]},"entertainment":{"category_title":"Entertainment","icon":"fa-masks-theater","package":"custom","type":"radio","field":"svc_entertainment","badge":"Pick One","items":[{"id":"ent_host","label":"Host","price":5000,"value":"Entertainment: Host"},{"id":"ent_mag","label":"Magician","price":5000,"value":"Entertainment: Magician"},{"id":"ent_hm","label":"Host & Magician","price":7500,"value":"Entertainment: Host & Magician"},{"id":"ent_full","label":"Host, Magician & Puppet Show","price":15000,"value":"Entertainment: Host, Magician & Puppet Show"}]},"food_carts":{"category_title":"Food Carts","icon":"fa-cart-flatbed","package":"custom","type":"checkbox","field":"svc_addons[]","badge":"Pick Any","items":[{"id":"fc_pop","label":"PopCorn Carts","price":5000,"value":"Food Cart: PopCorn Carts"},{"id":"fc_hotdog","label":"Hotdog Carts","price":5000,"value":"Food Cart: Hotdog Carts"},{"id":"fc_sweet","label":"Sweet Corner","price":5000,"value":"Food Cart: Sweet Corner"},{"id":"fc_ice","label":"Ice Cream","price":5000,"value":"Food Cart: Ice Cream"},{"id":"fc_fries","label":"Fries","price":5000,"value":"Food Cart: Fries"},{"id":"fc_nachos","label":"Nachos","price":5000,"value":"Food Cart: Nachos"},{"id":"fc_donuts","label":"Donuts","price":5000,"value":"Food Cart: Donuts"},{"id":"fc_grazing","label":"Grazing Table w\/ Cold Cuts (100 pax)","price":10500,"value":"Food Cart: Grazing Table with Cold Cuts (good for 100 pax)"}]},"photobooth":{"category_title":"Photobooth","icon":"fa-camera-retro","package":"custom","type":"radio","field":"svc_photobooth","badge":"Pick One","items":[{"id":"pb_basic","label":"Basic Paper Frame","price":3500,"value":"Photobooth: Basic Paper Frame"},{"id":"pb_magnet","label":"Premium Ref Magnet","price":4500,"value":"Photobooth: Premium Ref Magnet"},{"id":"pb_360","label":"Premium 360","price":12000,"value":"Photobooth: Premium 360"}]},"photo_video":{"category_title":"Photo & Video Coverage","icon":"fa-video","package":"custom","type":"radio","field":"svc_photo","badge":"Pick One","items":[{"id":"pv_photo","label":"Photo Coverage","price":4500,"value":"Photo & Video: Photo Coverage"},{"id":"pv_pv","label":"Photo & Video Coverage","price":12000,"value":"Photo & Video: Photo & Video Coverage"},{"id":"pv_mtv","label":"Photo & Video Coverage MTV Highlights","price":15000,"value":"Photo & Video: MTV Highlights"},{"id":"pv_sde","label":"Photo & Video Coverage Sameday Edit","price":35000,"value":"Photo & Video: Sameday Edit"}]},"otd":{"category_title":"OTD Coordination","icon":"fa-clipboard-list","package":"custom","type":"radio","field":"svc_otd","badge":"Pick One","items":[{"id":"otd_basic","label":"Basic Package","price":10000,"value":"OTD Coordination: Basic Package"},{"id":"otd_prem","label":"Premium Package","price":15000,"value":"OTD Coordination: Premium Package"}]}}}');

-- --------------------------------------------------------
-- DATA FOR: trash (1 rows)
-- --------------------------------------------------------
INSERT INTO trash ("id", "item_type", "item_id", "item_data", "deleted_by", "deleted_at") VALUES (4, 'event', 6, '{"id":6,"user_id":5,"facility_id":3,"title":"birthday","description":"sduasgfiahfoas","attendees_count":100,"start_time":"2026-09-15 15:47:00","end_time":"2026-09-15 18:47:00","status":"pending","created_at":"2026-09-13 17:48:30","facility_name":"Room 512"}', 1, '2026-09-13 17:48:56');

SELECT setval(pg_get_serial_sequence('trash', 'id'), COALESCE((SELECT MAX(id) FROM trash), 1));

-- --------------------------------------------------------
-- DATA FOR: user_sessions (5 rows)
-- --------------------------------------------------------
INSERT INTO user_sessions ("id", "user_id", "session_id", "ip_address", "user_agent", "device_name", "device_type", "is_blocked", "is_logged_out", "logged_out_at", "created_at", "last_activity") VALUES (2, 8, 't3lc70i3qgqt70nmcshq5fgaiv', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'Windows 10/11 • Google Chrome', 'desktop', FALSE, FALSE, NULL, '2026-09-25 00:23:51', '2026-09-25 00:23:54');
INSERT INTO user_sessions ("id", "user_id", "session_id", "ip_address", "user_agent", "device_name", "device_type", "is_blocked", "is_logged_out", "logged_out_at", "created_at", "last_activity") VALUES (3, 8, 'gijq8sb1a82594cnh60leo2ucf', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'Windows 10/11 • Google Chrome', 'desktop', FALSE, TRUE, '2026-09-25 00:26:25', '2026-09-25 00:24:24', '2026-09-25 00:26:24');
INSERT INTO user_sessions ("id", "user_id", "session_id", "ip_address", "user_agent", "device_name", "device_type", "is_blocked", "is_logged_out", "logged_out_at", "created_at", "last_activity") VALUES (4, 8, 'aden4agqd2f4d4pav0m99vn4te', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'Windows 10/11 • Google Chrome', 'desktop', FALSE, TRUE, '2026-09-25 00:50:57', '2026-09-25 00:28:19', '2026-09-25 00:50:49');
INSERT INTO user_sessions ("id", "user_id", "session_id", "ip_address", "user_agent", "device_name", "device_type", "is_blocked", "is_logged_out", "logged_out_at", "created_at", "last_activity") VALUES (5, 8, 'bq6vf385uusm7avbc75cs0lqc0', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'Windows 10/11 • Google Chrome', 'desktop', FALSE, FALSE, NULL, '2026-09-25 07:58:41', '2026-09-25 07:59:44');
INSERT INTO user_sessions ("id", "user_id", "session_id", "ip_address", "user_agent", "device_name", "device_type", "is_blocked", "is_logged_out", "logged_out_at", "created_at", "last_activity") VALUES (6, 8, '33emkqkbin2593p0fvcmnrp0bk', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', 'Windows 10/11 • Google Chrome', 'desktop', FALSE, FALSE, NULL, '2026-09-25 11:03:34', '2026-09-25 11:04:33');

SELECT setval(pg_get_serial_sequence('user_sessions', 'id'), COALESCE((SELECT MAX(id) FROM user_sessions), 1));

-- ========================================================
-- ENABLE ROW LEVEL SECURITY (RLS) POLICIES
-- By default, allow full access for authenticated/anon keys
-- ========================================================
ALTER TABLE users ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Allow full access for anon and authenticated on users" ON users FOR ALL USING (true) WITH CHECK (true);
ALTER TABLE facilities ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Allow full access for anon and authenticated on facilities" ON facilities FOR ALL USING (true) WITH CHECK (true);
ALTER TABLE bookings ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Allow full access for anon and authenticated on bookings" ON bookings FOR ALL USING (true) WITH CHECK (true);
ALTER TABLE events ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Allow full access for anon and authenticated on events" ON events FOR ALL USING (true) WITH CHECK (true);
ALTER TABLE chat_sessions ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Allow full access for anon and authenticated on chat_sessions" ON chat_sessions FOR ALL USING (true) WITH CHECK (true);
ALTER TABLE chat_messages ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Allow full access for anon and authenticated on chat_messages" ON chat_messages FOR ALL USING (true) WITH CHECK (true);
ALTER TABLE notifications ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Allow full access for anon and authenticated on notifications" ON notifications FOR ALL USING (true) WITH CHECK (true);
ALTER TABLE settings ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Allow full access for anon and authenticated on settings" ON settings FOR ALL USING (true) WITH CHECK (true);
ALTER TABLE trash ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Allow full access for anon and authenticated on trash" ON trash FOR ALL USING (true) WITH CHECK (true);
ALTER TABLE user_sessions ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Allow full access for anon and authenticated on user_sessions" ON user_sessions FOR ALL USING (true) WITH CHECK (true);
