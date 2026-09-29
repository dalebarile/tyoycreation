-- EventVista Database Setup & Schema Migration

CREATE TABLE IF NOT EXISTS `bookings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `reference_no` VARCHAR(50) NOT NULL UNIQUE,
  `client_name` VARCHAR(150) NOT NULL,
  `client_email` VARCHAR(150) NOT NULL,
  `client_phone` VARCHAR(50) NOT NULL,
  `client_address` TEXT NULL,
  `event_title` VARCHAR(255) NOT NULL,
  `event_type` VARCHAR(100) NOT NULL,
  `event_start` DATETIME NOT NULL,
  `event_end` DATETIME NOT NULL,
  `guest_count` INT DEFAULT 50,
  `location_venue` VARCHAR(255) NOT NULL,
  `service_requirements` TEXT NULL,
  `special_notes` TEXT NULL,
  `status` ENUM('pending', 'approved', 'rejected', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
  `rejection_reason` TEXT NULL,
  `source` ENUM('online_inquiry', 'manual_entry') NOT NULL DEFAULT 'online_inquiry',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `booking_id` INT NULL,
  `recipient_name` VARCHAR(150) NOT NULL,
  `recipient_contact` VARCHAR(150) NOT NULL,
  `channel` VARCHAR(50) NOT NULL DEFAULT 'email',
  `template_type` ENUM('approval', 'rejection', 'reminder', 'custom') NOT NULL,
  `subject` VARCHAR(255) NULL,
  `message` TEXT NOT NULL,
  `status` ENUM('sent', 'pending', 'failed') NOT NULL DEFAULT 'sent',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` VARCHAR(100) PRIMARY KEY,
  `setting_value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('business_name', 'EventVista'),
('business_tagline', 'TURNING YOUR MOMENTS INTO Unforgettable Events'),
('contact_email', 'contact@eventvista.com'),
('contact_phone', '+63 912 345 6789'),
('business_address', '123 Grand Ballroom Avenue, Metro Manila, Philippines'),
('approval_template', 'Dear {client_name},\n\nYour booking for {event_title} on {event_date} has been APPROVED! Our coordinator will contact you for coordination and downpayment details. Ref: {ref_no}'),
('rejection_template', 'Dear {client_name},\n\nUnfortunately we are unavailable for your requested date ({event_date}) due to: {reason}. Please contact us to reschedule. Ref: {ref_no}'),
('reminder_template', 'Hi {client_name}!\n\nFriendly reminder that your event {event_title} is coming up on {event_date} at {venue}. See you soon! Ref: {ref_no}')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- Seed sample bookings matching mockup screen data if table is empty
INSERT INTO `bookings` (`reference_no`, `client_name`, `client_email`, `client_phone`, `client_address`, `event_title`, `event_type`, `event_start`, `event_end`, `guest_count`, `location_venue`, `service_requirements`, `special_notes`, `status`, `rejection_reason`, `source`, `created_at`)
SELECT 'EV-2026-001', 'Maria Santos', 'maria.santos@gmail.com', '0917-123-4567', 'Quezon City', 'Santos & Reyes Wedding', 'Weddings', '2026-04-20 14:00:00', '2026-04-20 21:00:00', 150, 'The Glasshouse Pavillion', 'Sound System, Wireless Microphones, Mood Lighting, Stage & Backdrop', 'Gold and ivory floral motif requested', 'pending', NULL, 'online_inquiry', '2026-04-10 09:30:00'
WHERE NOT EXISTS (SELECT 1 FROM `bookings` WHERE `reference_no` = 'EV-2026-001');

INSERT INTO `bookings` (`reference_no`, `client_name`, `client_email`, `client_phone`, `client_address`, `event_title`, `event_type`, `event_start`, `event_end`, `guest_count`, `location_venue`, `service_requirements`, `special_notes`, `status`, `rejection_reason`, `source`, `created_at`)
SELECT 'EV-2026-002', 'John Cruz', 'john.cruz@techcorp.com', '0918-234-5678', 'Makati City', 'ABC Corp Annual Summit', 'Corporate Events', '2026-04-21 09:00:00', '2026-04-21 17:00:00', 200, 'Grand Peninsula Ballroom', 'Sound System, Projectors, Stage Setup, Catering Coordination', 'Requires simultaneous presentation setup', 'pending', NULL, 'online_inquiry', '2026-04-11 11:15:00'
WHERE NOT EXISTS (SELECT 1 FROM `bookings` WHERE `reference_no` = 'EV-2026-002');

INSERT INTO `bookings` (`reference_no`, `client_name`, `client_email`, `client_phone`, `client_address`, `event_title`, `event_type`, `event_start`, `event_end`, `guest_count`, `location_venue`, `service_requirements`, `special_notes`, `status`, `rejection_reason`, `source`, `created_at`)
SELECT 'EV-2026-003', 'Ana Reyes', 'ana.reyes@yahoo.com', '0920-345-6789', 'San Jose Del Monte', 'Liam 7th Superhero Birthday', 'Birthday Parties', '2026-04-22 15:00:00', '2026-04-22 19:00:00', 80, 'Emerald Garden Hall', 'Sound System, Wireless Microphones, Emcee/Host, Mood Lighting', 'Candy buffet table arrangement', 'pending', NULL, 'online_inquiry', '2026-04-12 14:00:00'
WHERE NOT EXISTS (SELECT 1 FROM `bookings` WHERE `reference_no` = 'EV-2026-003');

INSERT INTO `bookings` (`reference_no`, `client_name`, `client_email`, `client_phone`, `client_address`, `event_title`, `event_type`, `event_start`, `event_end`, `guest_count`, `location_venue`, `service_requirements`, `special_notes`, `status`, `rejection_reason`, `source`, `created_at`)
SELECT 'EV-2026-004', 'Paolo Garcia', 'paolo.g@gmail.com', '0922-456-7890', 'Bulacan', 'Sofia 18th Enchanted Debut', 'Debut & Others', '2026-04-23 18:00:00', '2026-04-23 23:00:00', 120, 'Versailles Palace Hall', 'Sound System, Mood Lighting, Photo/Video Coverage, Stage & Backdrop', 'Violet fairy tale lighting theme', 'pending', NULL, 'online_inquiry', '2026-04-13 16:20:00'
WHERE NOT EXISTS (SELECT 1 FROM `bookings` WHERE `reference_no` = 'EV-2026-004');

INSERT INTO `bookings` (`reference_no`, `client_name`, `client_email`, `client_phone`, `client_address`, `event_title`, `event_type`, `event_start`, `event_end`, `guest_count`, `location_venue`, `service_requirements`, `special_notes`, `status`, `rejection_reason`, `source`, `created_at`)
SELECT 'EV-2026-005', 'Liza Fernandez', 'liza.f@gmail.com', '0919-567-8901', 'Caloocan', 'Fernandez-David Nuptials', 'Weddings', '2026-04-24 13:00:00', '2026-04-24 20:00:00', 180, 'St. Jude Reception Pavilion', 'Sound System, Wireless Microphones, Mood Lighting, Stage Setup', 'String quartet audio plug-in needed', 'pending', NULL, 'online_inquiry', '2026-04-14 10:45:00'
WHERE NOT EXISTS (SELECT 1 FROM `bookings` WHERE `reference_no` = 'EV-2026-005');

-- Approved Bookings (for Master Calendar and Dashboard Upcoming)
INSERT INTO `bookings` (`reference_no`, `client_name`, `client_email`, `client_phone`, `client_address`, `event_title`, `event_type`, `event_start`, `event_end`, `guest_count`, `location_venue`, `service_requirements`, `special_notes`, `status`, `rejection_reason`, `source`, `created_at`)
SELECT 'EV-2026-006', 'Garcia & Santos', 'garcia.santos@gmail.com', '0917-888-1234', 'Manila', 'Wedding - Garcia & Santos', 'Weddings', '2026-04-26 10:00:00', '2026-04-26 18:00:00', 160, 'Bellevue Ballroom A', 'Sound System, Mood Lighting, Stage & Backdrop', 'Pastel color theme', 'approved', NULL, 'online_inquiry', '2026-04-01 10:00:00'
WHERE NOT EXISTS (SELECT 1 FROM `bookings` WHERE `reference_no` = 'EV-2026-006');

INSERT INTO `bookings` (`reference_no`, `client_name`, `client_email`, `client_phone`, `client_address`, `event_title`, `event_type`, `event_start`, `event_end`, `guest_count`, `location_venue`, `service_requirements`, `special_notes`, `status`, `rejection_reason`, `source`, `created_at`)
SELECT 'EV-2026-007', 'ABC Corp.', 'events@abccorp.ph', '0917-999-5678', 'BGC Taguig', 'Corporate Event - ABC Corp.', 'Corporate Events', '2026-04-28 14:00:00', '2026-04-28 18:00:00', 100, 'Ascott Bonifacio Ballroom', 'Sound System, Wireless Microphones, Projectors', 'Annual awards celebration', 'approved', NULL, 'online_inquiry', '2026-04-02 11:30:00'
WHERE NOT EXISTS (SELECT 1 FROM `bookings` WHERE `reference_no` = 'EV-2026-007');

INSERT INTO `bookings` (`reference_no`, `client_name`, `client_email`, `client_phone`, `client_address`, `event_title`, `event_type`, `event_start`, `event_end`, `guest_count`, `location_venue`, `service_requirements`, `special_notes`, `status`, `rejection_reason`, `source`, `created_at`)
SELECT 'EV-2026-008', 'Reyes Family', 'reyes.family@gmail.com', '0915-444-2222', 'Pasig City', 'Birthday Party - Reyes', 'Birthday Parties', '2026-05-03 16:00:00', '2026-05-03 20:00:00', 75, 'Silverleaf Pavilion', 'Sound System, Mood Lighting, Emcee/Host', 'Dinosaur themed kids party', 'approved', NULL, 'manual_entry', '2026-04-05 15:00:00'
WHERE NOT EXISTS (SELECT 1 FROM `bookings` WHERE `reference_no` = 'EV-2026-008');

INSERT INTO `bookings` (`reference_no`, `client_name`, `client_email`, `client_phone`, `client_address`, `event_title`, `event_type`, `event_start`, `event_end`, `guest_count`, `location_venue`, `service_requirements`, `special_notes`, `status`, `rejection_reason`, `source`, `created_at`)
SELECT 'EV-2026-009', 'Elena Mendoza', 'elena.mendoza@gmail.com', '0918-777-3333', 'Quezon City', 'Debut - Mendoza 18th', 'Debut & Others', '2026-04-18 17:00:00', '2026-04-18 22:00:00', 100, 'Grand Sapphire Hall', 'Sound System, Mood Lighting, Stage & Backdrop', 'Royal Blue motif', 'approved', NULL, 'online_inquiry', '2026-04-03 09:00:00'
WHERE NOT EXISTS (SELECT 1 FROM `bookings` WHERE `reference_no` = 'EV-2026-009');

-- Sample Notifications Log
INSERT INTO `notifications` (`booking_id`, `recipient_name`, `recipient_contact`, `channel`, `template_type`, `subject`, `message`, `status`, `created_at`)
VALUES
(6, 'Garcia & Santos', 'garcia.santos@gmail.com', 'email', 'approval', 'Booking Confirmed', 'Hello Garcia & Santos, your booking for Wedding - Garcia & Santos on 2026-04-26 has been APPROVED! Our coordinator will contact you soon. Ref: EV-2026-006', 'sent', '2026-04-25 10:30:00'),
(7, 'ABC Corp.', 'events@abccorp.ph', 'email', 'approval', 'Booking Confirmed - ABC Corp Annual Summit', 'Dear ABC Corp., we are delighted to confirm your Corporate Event reservation on 2026-04-28. Ref: EV-2026-007.', 'sent', '2026-04-25 10:15:00'),
(8, 'Reyes Family', 'reyes.family@gmail.com', 'email', 'reminder', 'Event Reminder', 'Hi Reyes Family! Friendly reminder that your Birthday Party - Reyes is coming up on 2026-05-03. Ref: EV-2026-008', 'sent', '2026-04-24 17:20:00');
