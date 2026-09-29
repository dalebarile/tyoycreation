# Tyoy Creation - Quick Event System (QES)

**Tyoy Creation QES** is a modern, enterprise-grade PHP web application and management platform engineered for professional event coordination, styling, floral creations, and balloon decoration services with an embedded Google Gemini AI Concierge.

---

## 📂 Project Structure & File Directory

```
qes/
│
├── index.php                 # Public showcase landing page, portfolio gallery, packages catalog & booking modal
├── booking.php               # Dedicated public booking inquiry wizard (multi-step custom package builder)
├── booking_submit.php        # Public AJAX endpoint for submitting online booking inquiries
├── chatbot.php               # AI Concierge backend (Google Gemini Flash + direct booking + quota protection)
├── event_load.php            # Authenticated JSON feed for approved calendar event dates
├── style.css                 # Master application stylesheet (public and admin design system)
│
├── loginadmin.php            # Secure admin & user authentication gateway with "Forgot Password" modal & device alerts
├── login.php                 # Routing redirect preserving query strings to loginadmin.php
├── logout.php                # Session destruction and logout handler
│
├── admin_sidebar.php         # Shared admin navigation sidebar with real-time badges & modern Sign Out modal
├── a_home.php                # Admin executive dashboard (metrics, upcoming schedule, Gemini quota gauge)
├── a_events.php              # Event inquiries manager (custom Approve, Reject, Delete modals & 7-day auto-purge)
├── a_calendar.php            # Master interactive FullCalendar schedule
├── a_manual_booking.php      # Walk-in / manual booking reservation entry (Main Admin)
├── a_clients.php             # Client database directory, reservation history & client deletion modal
├── a_packages.php            # Live catalog & pricing control (Theme Party & Wedding packages with Reset Defaults modal)
├── a_sitemanager.php         # Website content CMS (Hero subtitles, Our Story, contact info & portfolio showcase)
├── a_notifications.php       # Automated Email communication logs & manual dispatch
├── a_settings.php            # Business profile, notification templates, device sessions & security modals
│
├── db.php                    # Dual DB router (Cloud Supabase PostgreSQL primary + Local MySQL fallback)
├── config.php                # Environment secrets and Gemini AI configuration
├── crypto_helper.php         # Application-level AES-256-GCM encryption for client PII
├── supabase_driver.php       # Supabase REST database driver
├── notification_helper.php   # Automated email notification dispatcher (Gmail SMTP / PHPMailer)
├── auth_action.php           # User profile & account security action handler (Forgot Password API)
│
├── database/                 # Database schema and migration scripts
│   ├── eventvista_setup.sql  # Primary baseline schema and initial seed data
│   ├── migration.sql         # Column migrations and schema updates (including password reset codes)
│   ├── supabase_migration.sql# Complete Supabase PostgreSQL cloud migration schema
│   └── trash_setup.sql       # Archived legacy trash schema
│
├── docs/                     # System documentation & technical reports
│   └── PROJECT_AUDIT.md      # Comprehensive security, architecture, and code quality audit
│
└── legacy/                   # Archived v1 prototype modules (quarantined, disconnected from routing)
```

---

## 🚀 Key Features & Capabilities

### 1. Client Showcase & Online Booking Wizard
- Interactive, responsive portfolio gallery with category filters (Weddings, Kids Party, Corporate, Debuts) on [`index.php`](index.php).
- Dynamic packages catalog and synchronized pricing synchronized with [`a_packages.php`](a_packages.php).
- Dedicated 4-step booking inquiry builder on [`booking.php`](booking.php) generating automated reference tracking codes (`EV-YYYY-XXXX`).

### 2. Multi-Step "Forgot Password" Recovery Flow
- Accessible directly from [`loginadmin.php`](loginadmin.php) through a modern modal interface.
- **Step 1 (Request):** User submits their registered email or username.
- **Step 2 (Dispatch):** System generates a secure 6-digit numeric verification code with a 15-minute expiration window and sends an HTML email via Gmail SMTP (`NotificationHelper::sendPasswordResetCodeEmail`).
- **Step 3 (Verify):** User inputs the 6-digit code with real-time verification and resend capability.
- **Step 4 (Reset):** User enters and confirms their new password. The system re-reads both inputs:
  - If passwords do not match, a prominent warning notice is displayed prompting the user to repeat.
  - If valid (min. 6 characters), passwords are hashed using `bcrypt` and updated in the database.
- **Step 5 (Success):** Success confirmation state with immediate sign-in redirection.

### 3. Modern Custom UI Dialogs (Zero Native Browser Alerts)
- **All browser `confirm()` and `alert()` popups have been completely eliminated** across the active application in favor of branded custom UI modals matching the Tyoy QES theme (`#364735` forest green and rounded modern cards):
  - **Approve Modal ([`a_events.php`](a_events.php)):** Previews event reference, title, client contact, schedule, venue, calendar sync, and automated client email dispatch.
  - **Reject Modal ([`a_events.php`](a_events.php)):** Includes event preview, rejection reason textarea, and client notification notice.
  - **Delete Inquiries Modal ([`a_events.php`](a_events.php)):** Permanent inquiry purge confirmation.
  - **Delete Client Modal ([`a_clients.php`](a_clients.php)):** Verifies client name, phone, email, and associated booking count before permanent purge with CSRF validation.
  - **Sign Out Modal ([`admin_sidebar.php`](admin_sidebar.php)):** Confirms session termination with active username and security role badge.
  - **Security Modals ([`a_settings.php`](a_settings.php)):** Force Admin Logout, Block Device, Panic Lockout (Block all other devices), and Delete Admin Account modals.
  - **Reset Defaults Modal ([`a_packages.php`](a_packages.php)):** Confirms catalog reset to factory presets.

### 4. Interactive AI Chatbot Concierge
- Powered by Google Gemini (`gemini-2.5-flash`).
- Natural language date availability checks, package explanations, and **direct in-chat booking**.
- **Multi-Tier Quota & Rate-Limiting Protection:**
  - 1,400 daily site-wide hard cap (safeguarding Google free-tier quota).
  - 12 requests/minute burst protection.
  - 30 chats per user session.

### 5. Security & Data Privacy
- **Dual-Database Persistence:** Primary Cloud Supabase PostgreSQL with local MySQL failover.
- **Client Data Protection:** Application-level AES-256-GCM encryption (`crypto_helper.php`) for client contact details (email, phone, venue).
- **Session & Device Security:** Active device fingerprinting, IP tracking, and instant remote session revocation.
- **Prepared Statements:** 100% parameterized SQL queries preventing SQL injection across all active modules.

---

## ⚙️ Installation & Setup

1. Place this project folder into `c:\xampp\htdocs\qes`.
2. Configure credentials in `.env.php` (copy template from `.env.example.php`).
3. Start **Apache** in XAMPP Control Panel.
4. Access the web application:
   - **Public Website:** `http://localhost/qes/`
   - **Admin Portal:** `http://localhost/qes/loginadmin.php`
