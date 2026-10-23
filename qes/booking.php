<?php
require_once __DIR__ . '/db.php';

$business_name = get_setting($conn, 'business_name', 'Tyoy Creation');
$business_tagline = get_setting($conn, 'business_tagline', 'TURNING YOUR MOMENTS INTO Unforgettable Events');
$contact_phone = get_setting($conn, 'contact_phone', '+63 912 345 6789');
$contact_email = get_setting($conn, 'contact_email', 'contact@tyoycreation.com');

if (empty($_SESSION['id']) && function_exists('qes_restore_client_session_from_cookie')) {
    qes_restore_client_session_from_cookie($conn);
}
$is_client_logged_in = !empty($_SESSION['id']) && (!isset($_SESSION['role']) || $_SESSION['role'] === 'user');
$is_admin_logged_in = !empty($_SESSION['id']) && isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'super_admin', 'main_admin'], true);

// Client MUST be logged in or signed up in order to book
if (!$is_client_logged_in && !$is_admin_logged_in) {
    $queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    $redirect_url = 'login.php?redirect=' . urlencode('booking.php' . $queryString);
    header('Location: ' . $redirect_url);
    exit;
}

// Database-driven pricing packages
$pricing_theme = get_packages_pricing($conn, 'theme_party');
$pricing_wedding = get_packages_pricing($conn, 'wedding');

// Calculate minimum booking dates per event type via db.php (today onwards; user has free will to choose date)
$today_date = date('Y-m-d');
$today_formatted = date('F j, Y');
$wedding_calc = calculate_earliest_event_date('Weddings');
$kids_calc = calculate_earliest_event_date('Kids Party');
$min_wedding_date = $today_date;
$min_wedding_formatted = $today_formatted;
$min_kids_date = $today_date;
$min_kids_formatted = $today_formatted;

// Query param preselection if user clicked from modal or landing page
$requested_type = trim($_GET['event_type'] ?? $_GET['type'] ?? $_GET['event'] ?? '');
$requested_package = trim($_GET['package'] ?? $_GET['pkg'] ?? $_GET['item'] ?? '');
$is_type_locked_from_url = !empty($requested_type) || !empty($requested_package);

if (stripos($requested_type, 'wedding') !== false || stripos($requested_package, 'wedding') !== false) {
    $initial_type = 'Weddings';
    $initial_family = 'wedding';
    $initial_min_date = $today_date;
    $initial_min_formatted = $today_formatted;
    $initial_notice_label = '3 Months Preparation Reminder';
} else {
    $initial_type = 'Kids Party';
    $initial_family = 'theme_party';
    $initial_min_date = $today_date;
    $initial_min_formatted = $today_formatted;
    $initial_notice_label = '2 Weeks Preparation Reminder';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Book an Event - <?= htmlspecialchars($business_name) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,400;1,600&family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,400&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <link rel="icon" type="image/png" href="assets/favicon.png?v=<?= filemtime(__DIR__ . '/assets/favicon.png') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php if (qes_is_turnstile_enabled($conn)): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>
    <?php endif; ?>
    <!-- React 18 and Babel Standalone for interactive booking engine -->
    <script src="https://unpkg.com/react@18/umd/react.production.min.js" crossorigin></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js" crossorigin></script>
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
    <style>
        :root {
            /* Primary Botanical Emerald */
            --primary: #18392b;
            --primary-hover: #122c21;
            --primary-dark: #0d2018;
            --primary-light: #eaf2ec;
            --accent-gold: #c5a059;
            --accent-gold-soft: #f7f1e4;
            --border-color: #dbe5de;
            --border-subtle: #e6ede8;
            --bg-page: #f7f9f7;
            --text-main: #14261c;
            --text-muted: #667d6f;
        }

        body {
            background-color: var(--bg-page);
            color: var(--text-main);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }

        /* Top Bar & Nav */
        .booking-nav {
            background: var(--primary);
            color: #ffffff;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        }

        .booking-nav-inner {
            max-width: 1200px;
            margin: 0 auto;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .brand-link {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #ffffff;
        }

        .brand-logo-img {
            height: 44px;
            width: 125px;
            aspect-ratio: 834 / 294;
            border-radius: 6px;
            display: block;
            object-fit: contain;
            transition: transform 0.2s ease;
        }

        .brand-logo-img:hover {
            transform: scale(1.02);
        }

        .nav-links-right {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .nav-link-btn {
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: color 0.2s;
        }

        .nav-link-btn:hover {
            color: #ffffff;
        }

        .btn-nav-book {
            background: #ffffff !important;
            color: #18392b !important;
            font-weight: 700 !important;
            font-size: 13px !important;
            padding: 7px 18px !important;
            border-radius: 9999px !important;
            border: none !important;
            text-decoration: none !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15) !important;
            transition: all 0.2s ease !important;
            cursor: pointer;
        }

        .btn-nav-book:hover {
            background: #f0fdf4 !important;
            color: #10261c !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2) !important;
        }

        .btn-nav-client-signin {
            text-decoration: none !important;
            font-weight: 600 !important;
            font-size: 13px !important;
            color: #ffffff !important;
            padding: 6px 14px !important;
            border: 1.5px solid rgba(255, 255, 255, 0.75) !important;
            border-radius: 9999px !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
            transition: all 0.2s ease !important;
            cursor: pointer;
        }

        .btn-nav-client-signin:hover {
            background: rgba(255, 255, 255, 0.15) !important;
            border-color: #ffffff !important;
            color: #ffffff !important;
            transform: translateY(-1px);
        }

        .client-user-badge {
            display: inline-flex !important;
            align-items: center !important;
            gap: 7px !important;
            font-size: 13px !important;
            font-weight: 600 !important;
            color: #18392b !important;
            padding: 6px 14px !important;
            background: #ffffff !important;
            border-radius: 9999px !important;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.18) !important;
            text-decoration: none;
        }

        .client-logout-nav {
            font-size: 13px !important;
            color: #cbd5e1 !important;
            text-decoration: none !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
            font-weight: 500 !important;
            transition: color 0.2s ease !important;
        }

        .client-logout-nav:hover {
            color: #ffffff !important;
        }

        .btn-admin-nav {
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.25);
            padding: 7px 16px;
            border-radius: 9999px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-admin-nav:hover {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }

        /* Mobile Nav Toggle & Backdrop for Booking */
        .mobile-nav-toggle {
            display: none;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.22);
            background: rgba(255, 255, 255, 0.08);
            color: #ffffff;
            font-size: 18px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .mobile-nav-toggle:hover {
            background: rgba(255, 255, 255, 0.18);
        }

        .mobile-nav-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(13, 32, 24, 0.65);
            backdrop-filter: blur(2px);
            z-index: 998;
            opacity: 0;
            transition: opacity 0.25s ease;
        }

        .mobile-nav-backdrop.active {
            display: block;
            opacity: 1;
        }

        @media (max-width: 768px) {
            .mobile-nav-toggle {
                display: inline-flex;
            }
            .nav-links-right {
                display: none;
                position: fixed;
                top: 68px;
                left: 0;
                right: 0;
                background: #18392b;
                border-bottom: 2px solid #2d6a4f;
                padding: 18px 24px 26px;
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                box-shadow: 0 16px 36px rgba(0, 0, 0, 0.45);
                z-index: 999;
                animation: slideDownBookingNav 0.22s ease-out;
            }
            .nav-links-right.nav-open {
                display: flex;
            }
            .nav-link-btn {
                font-size: 15.5px;
                padding: 10px 4px;
                border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            }
            .client-user-badge {
                justify-content: center;
                padding: 10px 16px !important;
                font-size: 14px !important;
                margin-top: 6px;
            }
            .client-logout-nav {
                justify-content: center;
                padding: 8px 12px;
                color: #fca5a5 !important;
                font-size: 14px !important;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 6px;
            }
            .btn-admin-nav {
                margin-top: 8px;
                text-align: center;
                justify-content: center;
                padding: 12px;
                font-size: 14.5px !important;
            }
            .hero-main-title {
                font-size: clamp(26px, 6vw, 36px) !important;
            }
            .form-card {
                padding: 20px 16px !important;
                border-radius: 12px !important;
            }
            .form-control, input, select, textarea {
                font-size: 16px !important;
            }
        }

        @keyframes slideDownBookingNav {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Booking Header Banner - Professional Color Theory Harmony */
        .booking-hero {
            background: linear-gradient(180deg, #f7f9f7 0%, #edf3ee 100%) !important;
            color: var(--text-primary) !important;
            padding: 44px 24px 50px 24px !important;
            text-align: center;
            position: relative;
            border-bottom: 1px solid var(--border-color);
            box-shadow: 0 4px 16px rgba(24, 57, 43, 0.03);
        }

        .booking-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 100%;
            max-width: 900px;
            height: 100%;
            background: radial-gradient(ellipse at 50% 0%, rgba(197, 160, 89, 0.12) 0%, transparent 70%);
            pointer-events: none;
        }

        .booking-hero h1,
        .booking-hero .hero-main-title {
            font-family: 'Playfair Display', Georgia, serif !important;
            color: var(--primary) !important;
            font-size: 34px !important;
            font-weight: 700 !important;
            margin: 0 0 8px 0 !important;
            letter-spacing: -0.01em;
            text-shadow: none !important;
            position: relative;
        }

        .booking-hero p,
        .booking-hero .hero-subtitle {
            font-size: 14.5px !important;
            color: var(--text-muted) !important;
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.5;
            font-weight: 400;
            text-shadow: none !important;
            position: relative;
        }

        .public-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.15) !important;
            color: #ffffff !important;
            padding: 7px 18px;
            border-radius: 9999px;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.02em;
            margin-bottom: 18px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.3) !important;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }

        .public-badge i {
            color: #facc15 !important;
            font-size: 14px;
        }

        /* Container Layout */
        .booking-container {
            max-width: 1200px;
            width: 100%;
            margin: -24px auto 60px auto;
            padding: 0 20px;
            position: relative;
            transform: none !important;
            will-change: auto !important;
            box-sizing: border-box;
        }

        .booking-grid {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 28px;
            align-items: stretch;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }

        .booking-left-column {
            min-width: 0;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }

        .booking-right-column {
            height: 100%;
            position: relative;
            min-width: 0;
            box-sizing: border-box;
        }

        @media (max-width: 960px) {
            .booking-grid {
                grid-template-columns: 100%;
            }
            .booking-right-column {
                height: auto;
            }
        }

        /* Wizard Cards */
        .form-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid var(--border-color);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.04);
            padding: 32px;
            margin-bottom: 24px;
            min-width: 0;
            max-width: 100%;
            box-sizing: border-box;
        }

        .step-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 14px;
            border-bottom: 2px solid #f3f4f6;
            min-width: 0;
            box-sizing: border-box;
        }
        .step-header > div:last-child {
            min-width: 0;
            flex: 1;
        }

        .step-num {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: var(--primary-light);
            color: var(--primary);
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
            border: 1px solid rgba(54, 71, 53, 0.2);
        }

        .step-header h2 {
            font-size: 19px;
            font-weight: 700;
            color: #111827;
            margin: 0;
        }

        .step-header p {
            font-size: 12px;
            color: var(--text-muted);
            margin: 2px 0 0 0;
        }

        .form-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        @media (max-width: 640px) {
            .form-row-2 {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }

        .form-group label .required {
            color: #dc2626;
        }

        .form-control {
            width: 100%;
            padding: 11px 14px;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            font-size: 14px;
            box-sizing: border-box;
            outline: none;
            transition: all 0.2s;
            background: #ffffff;
            color: var(--text-main);
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(54, 71, 53, 0.15);
        }

        .form-control.is-invalid {
            border-color: #dc2626 !important;
            background-color: #fff8f8 !important;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15) !important;
        }

        .field-error-text {
            color: #dc2626;
            font-size: 11px;
            font-weight: 600;
            margin-top: 4px;
            display: none;
            line-height: 1.35;
        }

        .lead-time-notice {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 12px;
            color: #1e40af;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 6px;
            line-height: 1.4;
        }

        .date-error-badge {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 12px;
            margin-top: 8px;
            display: none;
            align-items: center;
            gap: 8px;
            line-height: 1.4;
        }

        /* Package Selector Cards */
        .family-pill-selector {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .family-pill-btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            border: 1px solid var(--border-color);
            background: #ffffff;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .family-pill-btn.active {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
            box-shadow: 0 4px 10px rgba(54, 71, 53, 0.25);
        }

        .event-type-card.disabled-locked {
            opacity: 0.45;
            cursor: not-allowed !important;
            filter: grayscale(0.6);
            border-style: dashed;
            background: #f9fafb !important;
        }

        .locked-badge-pill {
            background: #eef2ee;
            color: var(--primary);
            border: 1px solid #c8d8c8;
            padding: 3px 10px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .catalog-category {
            background: #fafbfa;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 18px;
        }

        .catalog-cat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e5e7eb;
        }

        .catalog-cat-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .badge-type {
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 6px;
            text-transform: uppercase;
        }

        .badge-radio {
            background: #fef3c7;
            color: #b45309;
        }

        .badge-check {
            background: #dbeafe;
            color: #1e40af;
        }

        .options-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(260px, 100%), 1fr));
            gap: 10px;
        }

        .option-choice-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            transition: all 0.2s ease;
            position: relative;
            user-select: none;
        }

        .option-choice-card:hover {
            border-color: var(--primary);
            background: #fdfefe;
            transform: translateY(-1px);
        }

        .option-choice-card input[type="radio"],
        .option-choice-card input[type="checkbox"] {
            margin: 0;
            width: 17px;
            height: 17px;
            accent-color: var(--primary);
            cursor: pointer;
            flex-shrink: 0;
        }

        .option-label-text {
            font-size: 13px;
            font-weight: 600;
            color: #1f2937;
            flex: 1;
            line-height: 1.35;
        }

        .option-price-tag {
            font-size: 13px;
            font-weight: 700;
            color: var(--primary);
            white-space: nowrap;
            background: var(--primary-light);
            padding: 2px 8px;
            border-radius: 6px;
        }

        .option-choice-card.selected {
            border-color: var(--primary);
            background: #f3f6f3;
            box-shadow: 0 0 0 1px var(--primary);
        }

        /* Sticky Summary Card Sidebar */
        .summary-card-sidebar {
            position: sticky;
            top: 86px;
            max-height: calc(100vh - 105px);
            overflow-y: auto;
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            z-index: 80;
            scrollbar-width: thin;
            scrollbar-color: rgba(24, 57, 43, 0.25) transparent;
        }

        .summary-card-sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .summary-card-sidebar::-webkit-scrollbar-track {
            background: transparent;
            margin-top: 56px;
            margin-bottom: 12px;
        }

        .summary-card-sidebar::-webkit-scrollbar-thumb {
            background-color: rgba(24, 57, 43, 0.25);
            border-radius: 10px;
        }

        .summary-card-sidebar::-webkit-scrollbar-thumb:hover {
            background-color: rgba(24, 57, 43, 0.45);
        }

        /* Mobile / Small Screen Floating Sticky Total Bar */
        .mobile-sticky-total-bar {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(33, 45, 33, 0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            color: #ffffff;
            padding: 12px 20px;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.25);
            border-top: 1px solid rgba(255, 255, 255, 0.15);
            z-index: 990;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        @media (max-width: 960px) {
            .mobile-sticky-total-bar {
                display: flex;
                padding-bottom: max(12px, env(safe-area-inset-bottom));
            }
            .booking-container {
                padding-bottom: 100px;
            }
            .chatbot-widget-container {
                bottom: calc(72px + max(12px, env(safe-area-inset-bottom))) !important;
            }
            .cookie-consent-bar {
                bottom: calc(76px + max(10px, env(safe-area-inset-bottom))) !important;
            }
        }

        .mobile-sticky-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .mobile-sticky-label {
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 700;
            color: #86efac;
            letter-spacing: 0.05em;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .mobile-sticky-amount {
            font-size: 20px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.1;
        }

        .mobile-sticky-btn {
            background: linear-gradient(135deg, #15803d 0%, #166534 100%);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.25);
            padding: 10px 18px;
            border-radius: 9999px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .mobile-sticky-btn:hover {
            filter: brightness(1.1);
            transform: translateY(-1px);
        }

        .summary-head {
            background: linear-gradient(135deg, #2b392a 0%, #1f291e 100%) !important;
            color: #ffffff !important;
            padding: 18px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        }

        .summary-head h3,
        .summary-head .summary-head-title {
            font-size: 17px;
            font-weight: 700;
            margin: 0;
            color: #ffffff !important;
            display: flex;
            align-items: center;
            gap: 8px;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
        }

        .summary-head h3 i {
            color: #86efac !important;
        }

        .summary-badge {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            background: rgba(255, 255, 255, 0.18) !important;
            color: #ffffff !important;
            border: 1px solid rgba(255, 255, 255, 0.3) !important;
            padding: 4px 10px;
            border-radius: 9999px;
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .summary-body {
            padding: 20px;
        }

        .selected-items-list {
            max-height: 260px;
            overflow-y: auto;
            margin-bottom: 16px;
            padding-right: 4px;
        }

        .selected-item-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px dashed #e5e7eb;
            font-size: 13px;
        }

        .selected-item-row:last-child {
            border-bottom: none;
        }

        .selected-item-name {
            color: #374151;
            font-weight: 500;
            line-height: 1.35;
        }

        .selected-item-price {
            font-weight: 700;
            color: var(--primary);
            white-space: nowrap;
        }

        .btn-remove-item {
            background: #fee2e2;
            color: #b91c1c;
            border: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            line-height: 1;
            padding: 0;
            transition: all 0.15s ease;
        }
        .btn-remove-item:hover {
            background: #ef4444;
            color: #ffffff;
            transform: scale(1.1);
        }

        /* Choice Cards for Event Type */
        .event-choice-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }
        @media (max-width: 640px) {
            .event-choice-selector {
                grid-template-columns: 1fr;
            }
        }
        .event-type-card {
            background: #ffffff;
            border: 2px solid var(--border-color);
            border-radius: 14px;
            padding: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: all 0.2s ease;
            position: relative;
        }
        .event-type-card:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(54, 71, 53, 0.08);
        }
        .event-type-card.active {
            border-color: var(--primary);
            background: #f4f7f4;
            box-shadow: 0 4px 16px rgba(54, 71, 53, 0.12);
        }
        .event-type-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .event-type-info {
            flex-grow: 1;
        }
        .event-type-name {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 3px;
        }
        .event-type-meta {
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.35;
        }
        .event-type-check {
            color: #d1d5db;
            font-size: 18px;
            transition: color 0.2s ease;
        }
        .event-type-card.active .event-type-check {
            color: var(--primary);
        }

        .lead-time-notice.wedding-notice {
            background: #fffbeb !important;
            border-color: #fde68a !important;
            color: #92400e !important;
        }
        .lead-time-notice.kids-notice {
            background: #f0fdf4 !important;
            border-color: #bbf7d0 !important;
            color: #166534 !important;
        }

        .empty-selection-msg {
            font-size: 13px;
            color: var(--text-muted);
            text-align: center;
            padding: 24px 0;
            line-height: 1.5;
        }

        .summary-total-bar {
            background: #fafbfa;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .summary-total-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .summary-total-value {
            font-size: 24px;
            font-weight: 800;
            color: var(--primary);
        }

        .btn-submit-booking {
            width: 100%;
            background: var(--primary);
            color: #ffffff;
            border: none;
            padding: 15px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 4px 14px rgba(54, 71, 53, 0.35);
            transition: all 0.2s;
        }

        .btn-submit-booking:hover:not(:disabled) {
            background: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(54, 71, 53, 0.45);
        }

        .btn-submit-booking:disabled {
            opacity: 0.65;
            cursor: not-allowed;
        }

        .submit-error-banner {
            background: #fff1f2;
            border: 1.5px solid #fecdd3;
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 14px;
            color: #9f1239;
            font-size: 13px;
            display: flex;
            gap: 10px;
            align-items: flex-start;
            line-height: 1.4;
            box-shadow: 0 2px 8px rgba(225, 29, 72, 0.08);
            animation: fadeInError 0.25s ease-out;
        }

        .submit-error-banner i {
            color: #e11d48;
            font-size: 18px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .btn-quick-fix-pax {
            background: #15803d;
            color: #ffffff;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
            transition: all 0.15s ease;
        }

        .btn-quick-fix-pax:hover {
            background: #166534;
            transform: translateY(-1px);
        }

        .btn-quick-scroll-guest {
            background: #ffffff;
            color: #9f1239;
            border: 1px solid #fca5a5;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.15s ease;
        }

        .btn-quick-scroll-guest:hover {
            background: #fee2e2;
        }

        @keyframes fadeInError {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .terms-note {
            font-size: 11px;
            color: var(--text-muted);
            text-align: center;
            margin-top: 12px;
            line-height: 1.4;
        }

        /* Success Confirmation Modal */
        .success-overlay {
            display: none;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            width: 100% !important;
            height: 100% !important;
            min-height: 100dvh;
            background: rgba(15, 23, 15, 0.82);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 9999999 !important;
            align-items: center;
            justify-content: center;
            padding: 16px;
            box-sizing: border-box;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .success-overlay.active {
            display: flex;
        }

        .success-dialog {
            position: relative;
            background: #ffffff;
            border-radius: 18px;
            width: 100%;
            max-width: 500px;
            max-height: 86vh;
            overflow-y: auto;
            padding: 24px 22px 20px 22px;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
            animation: zoomSuccess 0.25s ease;
        }

        .success-dialog::-webkit-scrollbar {
            width: 5px;
        }
        .success-dialog::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        /* Top-right 'x' close button to return to website */
        .modal-close-x-btn {
            position: absolute;
            top: 12px;
            right: 14px;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            z-index: 10;
        }

        .modal-close-x-btn:hover {
            background: #fee2e2;
            color: #dc2626;
            border-color: #fca5a5;
            transform: scale(1.08);
        }

        @media (max-width: 640px) {
            .success-dialog {
                padding: 20px 16px 16px 16px;
                max-width: 95%;
                max-height: 88vh;
            }
            .modal-close-x-btn {
                top: 10px;
                right: 10px;
                width: 30px;
                height: 30px;
            }
            .confirmation-terms-card {
                max-height: 120px;
            }
        }

        /* Confirmation Terms & Conditions Card */
        .confirmation-terms-card {
            background: #f8faf9;
            border: 1px solid #d1fae5;
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 16px;
            text-align: left;
            font-size: 11.5px;
            line-height: 1.45;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.02);
            max-height: 140px;
            overflow-y: auto;
        }

        .confirmation-terms-card::-webkit-scrollbar {
            width: 6px;
        }
        .confirmation-terms-card::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .confirmation-terms-header {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            color: #1e3a2b;
            font-size: 12.5px;
            padding-bottom: 8px;
            margin-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .confirmation-terms-header i {
            color: #059669;
            font-size: 14px;
        }

        .confirmation-terms-body {
            display: flex;
            flex-direction: column;
            gap: 9px;
        }

        .confirmation-terms-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            color: #334155;
            font-size: 12px;
        }

        .confirmation-terms-item i {
            color: #059669;
            margin-top: 2px;
            font-size: 13px;
            flex-shrink: 0;
        }

        .confirmation-terms-item strong {
            color: #0f172a;
        }

        /* Email Warning Modal Terms Notice */
        .email-warning-terms-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            padding: 12px 14px;
            margin: 14px 0;
            text-align: left;
            font-size: 12px;
            line-height: 1.45;
        }

        .email-warning-terms-title {
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 700;
            color: #166534;
            margin-bottom: 6px;
            font-size: 12px;
        }

        .email-warning-terms-text {
            color: #15803d;
            margin: 0;
            font-size: 11.5px;
        }

        @keyframes zoomSuccess {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .success-icon-wrap {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #dcfce7;
            color: #15803d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin: 0 auto 10px auto;
            border: 2px solid #86efac;
        }

        .ref-badge-display {
            background: #eef2ee;
            border: 1px dashed var(--primary);
            color: var(--primary);
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 17px;
            font-weight: 800;
            letter-spacing: 0.05em;
            display: inline-block;
            margin: 4px 0 10px 0;
        }

        .btn-done-modal {
            background: var(--primary);
            color: #ffffff;
            border: none;
            padding: 9px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .btn-done-modal:hover {
            background: var(--primary-hover);
        }

        /* Two-Step Verification Modal */
        .verify-modal-overlay {
            display: flex;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            width: 100% !important;
            height: 100% !important;
            min-height: 100dvh;
            background: rgba(10, 20, 14, 0.88);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 9999999 !important;
            align-items: center;
            justify-content: center;
            padding: 16px;
            box-sizing: border-box;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            animation: fadeInOverlay 0.2s ease;
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .verify-modal-card {
            background: #ffffff;
            border-radius: 18px;
            width: 100%;
            max-width: 410px;
            max-height: calc(100dvh - 32px);
            max-height: calc(100vh - 32px);
            margin: auto;
            padding: 18px 20px 16px;
            text-align: center;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.55);
            animation: zoomSuccess 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            box-sizing: border-box;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .verify-modal-close-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s ease;
            z-index: 20;
        }

        .verify-modal-close-btn:hover {
            background: #fee2e2;
            color: #dc2626;
            border-color: #fca5a5;
            transform: scale(1.08);
        }

        .verify-typo-alert {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-left: 4px solid #ea580c;
            border-radius: 8px;
            padding: 7px 10px;
            margin: 0 0 8px 0;
            text-align: left;
            font-size: 11.5px;
            color: #9a3412;
            line-height: 1.4;
            display: flex;
            align-items: flex-start;
            gap: 6px;
        }

        .verify-modal-card::-webkit-scrollbar {
            width: 5px;
        }
        .verify-modal-card::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .verify-modal-icon {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            color: #059669;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            margin: 0 auto 6px auto;
            border: 2px solid #a7f3d0;
            box-shadow: 0 4px 10px -2px rgba(5, 150, 105, 0.2);
        }

        .verify-otp-input {
            width: 100%;
            max-width: 240px;
            height: 42px;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: 7px;
            text-align: center;
            padding-left: 7px;
            color: #1e293b;
            background: #f8fafc;
            border: 2px solid #cbd5e1;
            border-radius: 10px;
            outline: none;
            transition: all 0.2s ease;
            font-family: 'Courier New', Courier, monospace;
            margin: 6px auto 8px auto;
            display: block;
        }

        .verify-otp-input:focus {
            border-color: #059669;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15);
        }

        .verify-timer-box {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11.5px;
            color: #64748b;
            background: #f1f5f9;
            padding: 3px 8px;
            border-radius: 14px;
            font-weight: 600;
        }

        .verify-timer-box.warning {
            color: #b91c1c;
            background: #fee2e2;
        }

        .verify-spam-callout {
            background: #fefce8;
            border: 1px solid #fef08a;
            border-radius: 8px;
            padding: 6px 9px;
            margin: 8px 0 6px 0;
            text-align: left;
            display: flex;
            align-items: flex-start;
            gap: 6px;
            font-size: 11px;
            color: #854d0e;
            line-height: 1.35;
        }

        .verify-error-banner {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 7px 11px;
            border-radius: 8px;
            font-size: 12px;
            margin-bottom: 8px;
            text-align: left;
            display: flex;
            align-items: flex-start;
            gap: 6px;
            line-height: 1.35;
        }

        .verify-success-banner {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            padding: 7px 11px;
            border-radius: 8px;
            font-size: 12px;
            margin-bottom: 8px;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-verify-submit {
            background: #18392b;
            color: #ffffff;
            border: none;
            width: 100%;
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(24, 57, 43, 0.25);
        }

        .btn-verify-submit:hover:not(:disabled) {
            background: #122c21;
            transform: translateY(-1px);
        }

        .btn-verify-submit:disabled {
            opacity: 0.65;
            cursor: not-allowed;
        }

        .verify-modal-footer {
            margin-top: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11.5px;
            gap: 6px;
            flex-wrap: wrap;
        }

        .btn-resend-otp {
            background: transparent;
            border: none;
            color: #059669;
            font-weight: 600;
            cursor: pointer;
            padding: 3px 6px;
            text-decoration: underline;
            transition: color 0.15s;
        }

        .btn-resend-otp:hover:not(:disabled) {
            color: #047857;
        }

        .btn-resend-otp:disabled {
            color: #94a3b8;
            text-decoration: none;
            cursor: not-allowed;
        }

        .btn-cancel-verify {
            background: transparent;
            border: none;
            color: #64748b;
            font-weight: 600;
            cursor: pointer;
            padding: 3px 6px;
            transition: color 0.15s;
        }

        .btn-cancel-verify:hover {
            color: #1e293b;
        }

        /* Email Warning & Misconception Prevention Modal */
        .email-warning-overlay {
            display: flex;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            width: 100% !important;
            height: 100% !important;
            min-height: 100dvh;
            background: rgba(15, 23, 15, 0.86);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 9999999 !important;
            align-items: center;
            justify-content: center;
            padding: 16px;
            box-sizing: border-box;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            animation: fadeInOverlay 0.2s ease;
        }

        .email-warning-card {
            background: #ffffff;
            border-radius: 20px;
            width: 100%;
            max-width: 500px;
            max-height: calc(100dvh - 32px);
            max-height: calc(100vh - 32px);
            overflow-y: auto;
            margin: auto; /* Guarantees safe centering without clipping off the top */
            padding: 24px 22px 20px;
            text-align: center;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.5);
            animation: zoomSuccess 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            box-sizing: border-box;
        }

        .email-warning-card::-webkit-scrollbar {
            width: 5px;
        }
        .email-warning-card::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .email-warning-header-icon {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #d97706;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin: 0 auto 10px auto;
            border: 2px solid #fcd34d;
            box-shadow: 0 6px 14px -3px rgba(217, 119, 6, 0.22);
        }

        .email-warning-title {
            font-size: 19px;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 5px 0;
            line-height: 1.3;
        }

        .email-warning-lead {
            font-size: 13px;
            color: #64748b;
            margin: 0 0 12px 0;
            line-height: 1.45;
        }

        .email-display-highlight-box {
            background: #f8fafc;
            border: 2px solid #cbd5e1;
            border-radius: 12px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 12px;
            transition: all 0.2s;
        }

        .email-display-highlight-box.has-warning {
            border-color: #f59e0b;
            background: #fffbeb;
        }

        .email-display-text {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: 0.3px;
            word-break: break-all;
        }

        .email-typo-alert-box {
            background: #fff7ed;
            border: 1px solid #ffedd5;
            border-left: 4px solid #ea580c;
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 12px;
            text-align: left;
        }

        .btn-apply-email-suggestion {
            background: #ffedd5;
            border: 1px solid #fdba74;
            color: #9a3412;
            padding: 7px 11px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }

        .btn-apply-email-suggestion:hover {
            background: #fed7aa;
            transform: translateY(-1px);
        }

        .email-warning-checklist {
            background: #f8fafc;
            border-radius: 12px;
            padding: 11px 13px;
            text-align: left;
            margin-bottom: 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .warning-checklist-item {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            font-size: 12px;
            color: #475569;
            line-height: 1.4;
        }

        .email-warning-modal-actions {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .btn-confirm-email-send {
            background: #18392b;
            color: #ffffff;
            border: none;
            width: 100%;
            padding: 12px 18px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(24, 57, 43, 0.25);
        }

        .btn-confirm-email-send:hover:not(:disabled) {
            background: #122c21;
            transform: translateY(-1px);
        }

        .btn-edit-email-address {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            width: 100%;
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.15s;
        }

        .btn-edit-email-address:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        /* Targeted styles inside Two-Step Verification Modal */
        .verify-target-email-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 14px;
            font-size: 13.5px;
            color: #1e293b;
            margin-bottom: 14px;
            word-break: break-all;
            max-width: 100%;
        }

        .verify-missing-letter-guide {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 12px;
            padding: 12px 14px;
            margin: 16px 0;
            text-align: left;
        }

        .missing-letter-header {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 700;
            color: #92400e;
            margin-bottom: 4px;
        }

        .missing-letter-text {
            font-size: 12px;
            color: #78350f;
            margin: 0 0 8px 0;
            line-height: 1.4;
        }

        .btn-open-email-edit {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            color: #b45309;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }

        .btn-open-email-edit:hover {
            background: #fde68a;
            color: #92400e;
        }

        .verify-email-edit-box {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            padding: 12px 14px;
            margin: 16px 0;
            text-align: left;
            animation: fadeInOverlay 0.2s ease;
        }

        .email-edit-input-row {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .email-edit-input {
            flex: 1;
            min-width: 200px;
            padding: 8px 12px;
            font-size: 13.5px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            outline: none;
        }

        .email-edit-input:focus {
            border-color: #059669;
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15);
        }

        .btn-save-email-edit {
            background: #059669;
            color: #ffffff;
            border: none;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.15s;
        }

        .btn-save-email-edit:hover:not(:disabled) {
            background: #047857;
        }

        .btn-cancel-email-edit {
            background: #e2e8f0;
            color: #475569;
            border: none;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-cancel-email-edit:hover {
            background: #cbd5e1;
            color: #0f172a;
        }

        .email-edit-error {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #dc2626;
            font-size: 12px;
            font-weight: 600;
            margin-top: 6px;
        }

        /* Exit & Discard Booking Warning Modal */
        .exit-warning-overlay {
            display: flex;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            width: 100% !important;
            height: 100% !important;
            min-height: 100dvh;
            background: rgba(10, 20, 14, 0.88);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 9999999 !important;
            align-items: center;
            justify-content: center;
            padding: 16px;
            box-sizing: border-box;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            animation: fadeInOverlay 0.2s ease;
        }

        .exit-warning-card {
            background: #ffffff;
            border-radius: 18px;
            width: 100%;
            max-width: 440px;
            max-height: calc(100dvh - 32px);
            max-height: calc(100vh - 32px);
            margin: auto;
            padding: 22px 22px 18px;
            text-align: center;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.55);
            animation: zoomSuccess 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            box-sizing: border-box;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .exit-warning-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #fff7ed, #ffedd5);
            color: #ea580c;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin: 0 auto 10px auto;
            border: 2px solid #fed7aa;
            box-shadow: 0 4px 12px -2px rgba(234, 88, 12, 0.25);
        }

        .btn-stay-booking {
            background: #18392b;
            color: #ffffff;
            border: none;
            padding: 10px 16px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 13.5px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            flex: 1;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(24, 57, 43, 0.2);
        }

        .btn-stay-booking:hover {
            background: #122c21;
            transform: translateY(-1px);
        }

        .btn-discard-exit {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            padding: 10px 16px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 13.5px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            flex: 1;
            transition: all 0.2s ease;
        }

        .btn-discard-exit:hover {
            background: #dc2626;
            color: #ffffff;
            border-color: #dc2626;
            transform: translateY(-1px);
        }

        .btn-cancel-booking-flow {
            background: transparent;
            border: none;
            color: #64748b;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 6px;
            transition: all 0.15s ease;
        }

        .btn-cancel-booking-flow:hover {
            color: #dc2626;
            background: #fef2f2;
        }

        /* Floating AI Chatbot CSS Variables & Styles */
        :root {
            --chat-bg: #0b1120;
            --chat-surface: #111c35;
            --chat-accent: #3b82f6;
            --chat-accent-hover: #2563eb;
            --chat-accent-light: rgba(59, 130, 246, 0.14);
            --chat-accent-border: rgba(59, 130, 246, 0.3);
            --chat-accent-glow: rgba(59, 130, 246, 0.55);
            --chat-text: #ffffff;
            --chat-muted: #94a3b8;
        }

        .chatbot-widget-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 1500;
        }

        /* 1. Launcher button: Circular with glowing blue ring, dark fill, subtle glow shadow */
        .chatbot-launcher-btn {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: var(--chat-bg);
            color: var(--chat-text);
            border: 2.5px solid var(--chat-accent);
            box-shadow: 0 0 18px var(--chat-accent-glow), inset 0 0 10px rgba(59, 130, 246, 0.35), 0 8px 24px rgba(0, 0, 0, 0.5);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.25s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            z-index: 1502;
            outline: none;
            padding: 0;
        }
        .chatbot-launcher-btn:hover,
        .chatbot-launcher-btn:focus-visible {
            transform: scale(1.08);
            border-color: #60a5fa;
            box-shadow: 0 0 28px rgba(59, 130, 246, 0.85), inset 0 0 12px rgba(59, 130, 246, 0.45), 0 12px 30px rgba(0, 0, 0, 0.6);
        }

        /* 2. Welcome Popup Card (Speech Bubble above Launcher) */
        .chatbot-welcome-popup {
            display: none;
            position: absolute;
            bottom: 82px;
            right: 0;
            width: 330px;
            max-width: calc(100vw - 36px);
            background: var(--chat-bg);
            border: 1px solid var(--chat-accent-border);
            border-radius: 24px;
            padding: 20px 22px 18px 22px;
            box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.7), 0 0 25px rgba(59, 130, 246, 0.15);
            z-index: 1501;
            color: var(--chat-text);
            box-sizing: border-box;
            opacity: 0;
            transform: translateY(12px);
            pointer-events: none;
        }

        .chatbot-welcome-popup.visible {
            display: block;
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
            animation: chatFadeSlideUp 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        .chatbot-welcome-popup.hiding {
            opacity: 0;
            transform: translateY(8px);
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
        }

        @keyframes chatFadeSlideUp {
            from {
                opacity: 0;
                transform: translateY(12px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Triangular Speech Bubble Tail pointing down to launcher */
        .chatbot-welcome-tail {
            position: absolute;
            bottom: -9px;
            right: 22px;
            width: 20px;
            height: 10px;
            pointer-events: none;
        }

        /* Header Row */
        .chatbot-welcome-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }
        .chatbot-welcome-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #16233f;
            border: 1px solid var(--chat-accent-border);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }
        .chatbot-welcome-avatar-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .chatbot-welcome-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--chat-text);
            flex: 1;
        }
        .chatbot-welcome-close {
            background: transparent;
            border: none;
            color: var(--chat-muted);
            font-size: 17px;
            cursor: pointer;
            padding: 4px 6px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: color 0.15s, background 0.15s;
        }
        .chatbot-welcome-close:hover,
        .chatbot-welcome-close:focus-visible {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.08);
            outline: none;
        }

        /* Greeting & Description */
        .chatbot-welcome-greeting {
            font-size: 16px;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 6px;
            line-height: 1.35;
        }
        .chatbot-welcome-desc {
            font-size: 13.5px;
            color: var(--chat-muted);
            line-height: 1.5;
            margin: 0 0 14px 0;
        }

        /* Notice Pill at Bottom */
        .chatbot-welcome-notice-pill {
            background: var(--chat-accent-light);
            border: 1px solid var(--chat-accent-border);
            border-radius: 9999px;
            padding: 8px 14px;
            display: flex;
            align-items: center;
            gap: 9px;
            color: #93c5fd;
            font-size: 12px;
            font-weight: 500;
            line-height: 1.3;
        }
        .chatbot-welcome-notice-pill i {
            font-size: 13px;
            flex-shrink: 0;
            color: #60a5fa;
        }

        .chatbot-window {
            display: none;
            position: fixed;
            bottom: 96px;
            right: 24px;
            width: 360px;
            height: 520px;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.18);
            border: 1px solid var(--border-color);
            flex-direction: column;
            overflow: hidden;
            z-index: 1500;
            animation: slideUp 0.3s ease;
        }
        .chatbot-window.active {
            display: flex;
        }
        .chatbot-header {
            background: #0f172a;
            color: white;
            padding: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(59, 130, 246, 0.2);
        }
        .chatbot-header-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .chatbot-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #0b1120;
            border: 1.5px solid #3b82f6;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: #ffffff;
        }
        .chatbot-disclaimer-bar {
            background: #eff6ff;
            border-bottom: 1px solid #dbeafe;
            padding: 7px 12px;
            font-size: 11px;
            color: #1e40af;
            display: flex;
            align-items: center;
            gap: 8px;
            line-height: 1.35;
        }
        .chatbot-disclaimer-bar i {
            color: #3b82f6;
            font-size: 12px;
            flex-shrink: 0;
        }
        .chatbot-messages {
            flex: 1;
            padding: 16px 14px;
            overflow-y: auto;
            background: #f8faf8;
            display: flex;
            flex-direction: column;
            gap: 12px;
            scroll-behavior: smooth;
        }
        .chatbot-messages::-webkit-scrollbar {
            width: 5px;
        }
        .chatbot-messages::-webkit-scrollbar-track {
            background: transparent;
        }
        .chatbot-messages::-webkit-scrollbar-thumb {
            background: #d1d5db;
            border-radius: 10px;
        }
        .chatbot-messages::-webkit-scrollbar-thumb:hover {
            background: #9ca3af;
        }
        .chat-msg, .chat-bubble {
            max-width: 85%;
            padding: 11px 15px;
            border-radius: 16px;
            font-size: 13.5px;
            line-height: 1.55;
            white-space: pre-wrap;
            word-break: break-word;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .chat-msg strong, .chat-bubble strong {
            font-weight: 700;
        }
        .chat-msg em, .chat-bubble em {
            font-style: italic;
        }
        .chat-msg.bot, .chat-bubble.bot {
            align-self: flex-start;
            background: #ffffff;
            color: #1f2937;
            border: 1px solid #e5e7eb;
            border-bottom-left-radius: 4px;
        }
        .typing-cursor {
            display: inline-block;
            width: 6px;
            height: 13px;
            background: #285440;
            margin-left: 3px;
            vertical-align: middle;
            border-radius: 2px;
            animation: cursorBlink 0.6s infinite ease-in-out;
        }
        @keyframes cursorBlink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0; }
        }
        .chat-msg.user, .chat-bubble.user {
            align-self: flex-end;
            background: #2a3c29;
            color: #ffffff;
            border-bottom-right-radius: 4px;
            box-shadow: 0 2px 6px rgba(42, 60, 41, 0.2);
        }
        .quick-chips-wrapper {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-top: 8px;
            white-space: normal;
        }
        .quick-chip {
            background: #f3f6f3;
            border: 1px solid #d4dfd4;
            border-radius: 6px;
            padding: 6px 10px;
            font-size: 11.5px;
            font-weight: 500;
            color: #2a3c29;
            cursor: pointer;
            text-align: left;
            transition: all 0.15s ease;
            white-space: normal;
            display: flex;
            align-items: center;
            gap: 7px;
            width: 100%;
            box-sizing: border-box;
            line-height: 1.3;
        }
        .quick-chip i {
            font-size: 11px;
            color: #2a3c29;
            flex-shrink: 0;
        }
        .quick-chip:hover {
            background: #e2ebe2;
            border-color: #2a3c29;
            transform: translateX(2px);
        }
        .chatbot-footer-wrapper {
            background: #ffffff;
            border-top: 1px solid #e5e7eb;
            padding: 9px 12px 7px;
        }
        .chatbot-footer {
            display: flex;
            gap: 8px;
            align-items: flex-end;
        }
        .chatbot-footer textarea {
            flex: 1;
            padding: 8px 12px;
            border-radius: 19px;
            border: 1.5px solid #d1d5db;
            font-size: 13px;
            line-height: 1.4;
            outline: none;
            resize: none;
            min-height: 38px;
            max-height: 120px;
            height: 38px;
            box-sizing: border-box;
            font-family: inherit;
            color: #1f2937;
            background: #ffffff;
            transition: border-color 0.2s, box-shadow 0.2s;
            overflow-y: hidden;
        }
        .chatbot-footer textarea:focus {
            border-color: #2a3c29;
            box-shadow: 0 0 0 2px rgba(42, 60, 41, 0.15);
        }
        .chatbot-footer textarea::-webkit-scrollbar {
            width: 4px;
        }
        .chatbot-footer textarea::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        .chatbot-footer button {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #2a3c29;
            color: #ffffff;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.15s ease;
            box-shadow: 0 2px 6px rgba(42, 60, 41, 0.2);
        }
        .chatbot-footer button:hover {
            background: #1f2d1e;
            transform: scale(1.05);
        }
        .chatbot-footer button:active {
            transform: scale(0.95);
        }
        .chat-input-hint {
            font-size: 10.5px;
            color: #9ca3af;
            margin-top: 4px;
            text-align: right;
            padding-right: 4px;
        }

        /* Package Category Modal & Navigation */
        .pkg-modal-overlay {
            display: flex;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(13, 32, 24, 0.78);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
            animation: fadeInOverlay 0.2s ease;
        }

        .pkg-modal-dialog {
            background: #ffffff;
            border-radius: 20px;
            width: 100%;
            max-width: 860px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.45);
            animation: zoomSuccess 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(24, 57, 43, 0.15);
        }

        .pkg-modal-header {
            background: linear-gradient(135deg, #18392b 0%, #0d2018 100%);
            color: #ffffff;
            padding: 18px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        }

        .pkg-modal-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
            flex: 1;
        }

        .pkg-modal-title {
            font-size: 20px;
            font-weight: 800;
            margin: 0;
            color: #ffffff;
            letter-spacing: -0.01em;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .pkg-modal-subtitle {
            font-size: 13px;
            color: #cbd5e1;
            margin: 3px 0 0 0;
        }

        .pkg-modal-close-btn {
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #ffffff;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }

        .pkg-modal-close-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: scale(1.05);
        }

        .pkg-modal-body {
            padding: 22px 24px;
            overflow-y: auto;
            flex: 1;
            background: #f8faf9;
        }

        .pkg-modal-body::-webkit-scrollbar {
            width: 6px;
        }
        .pkg-modal-body::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 6px;
        }

        /* Category Choice Cards Grid */
        .pkg-choice-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
        }
        @media (max-width: 768px) {
            .pkg-choice-grid {
                grid-template-columns: 1fr;
            }
        }

        .pkg-choice-card {
            background: #ffffff;
            border: 2px solid var(--border-color);
            border-radius: 16px;
            padding: 22px 18px;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            text-align: left;
            transition: all 0.22s ease;
            position: relative;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
        }

        .pkg-choice-card:hover {
            border-color: var(--primary);
            transform: translateY(-3px);
            box-shadow: 0 10px 24px rgba(24, 57, 43, 0.12);
        }

        .pkg-choice-card.active-group {
            border-color: var(--primary);
            background: #f4f8f5;
            box-shadow: 0 0 0 2px var(--primary);
        }

        .pkg-choice-icon-wrap {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 14px;
            transition: transform 0.2s ease;
        }

        .pkg-choice-card:hover .pkg-choice-icon-wrap {
            transform: scale(1.08);
            background: var(--primary);
            color: #ffffff;
        }

        .pkg-choice-name {
            font-size: 17px;
            font-weight: 800;
            color: #111827;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .pkg-choice-desc {
            font-size: 12.5px;
            color: #64748b;
            line-height: 1.45;
            margin-bottom: 16px;
            flex: 1;
        }

        .pkg-choice-action-btn {
            background: #ffffff;
            border: 1.5px solid var(--primary);
            color: var(--primary);
            border-radius: 10px;
            padding: 9px 14px;
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease;
            margin-top: auto;
        }

        .pkg-choice-card:hover .pkg-choice-action-btn {
            background: var(--primary);
            color: #ffffff;
        }

        .pkg-choice-count-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #86efac;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 9999px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* Modal Top Alert / Optional Notice */
        .pkg-optional-banner {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 18px;
            color: #065f46;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
            line-height: 1.4;
        }

        .pkg-optional-banner i {
            font-size: 16px;
            color: #059669;
            flex-shrink: 0;
        }

        /* Modal Footer */
        .pkg-modal-footer {
            background: #ffffff;
            padding: 14px 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .pkg-modal-footer-info {
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: 13px;
            color: #374151;
        }

        .pkg-btn-back {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: all 0.15s ease;
        }

        .pkg-btn-back:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .pkg-btn-done {
            background: var(--primary);
            color: #ffffff;
            border: none;
            padding: 10px 22px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(24, 57, 43, 0.25);
        }

        .pkg-btn-done:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        /* Step 3 Package Choice Prompt & Active Selection Styles */
        .pkg-selection-prompt-wrapper {
            background: #ffffff;
            border: 2px dashed #cbd5e1;
            border-radius: 18px;
            padding: 24px 20px;
            margin-bottom: 24px;
            text-align: center;
            transition: border-color 0.2s ease;
        }

        .pkg-selection-prompt-wrapper:hover {
            border-color: #94a3b8;
        }

        .pkg-prompt-title-row {
            max-width: 620px;
            margin: 0 auto 20px auto;
        }

        .pkg-prompt-step-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f1f5f9;
            color: #475569;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 4px 12px;
            border-radius: 9999px;
            margin-bottom: 8px;
        }

        .pkg-prompt-title {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 6px 0;
            letter-spacing: -0.3px;
        }

        .pkg-prompt-desc {
            font-size: 13.5px;
            color: #64748b;
            margin: 0;
            line-height: 1.5;
        }

        /* Active Selected Category Banner in Step 3 */
        .pkg-active-selected-bar {
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
            border: 1.5px solid #86efac;
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            box-shadow: 0 4px 14px rgba(22, 101, 52, 0.06);
            animation: fadeInSelected 0.25s ease-out;
        }

        @keyframes fadeInSelected {
            from {
                opacity: 0;
                transform: translateY(-4px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .pkg-active-selected-left {
            display: flex;
            align-items: center;
            gap: 14px;
            flex: 1;
            min-width: 240px;
        }

        .pkg-active-selected-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: var(--primary);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
            box-shadow: 0 3px 8px rgba(24, 57, 43, 0.2);
        }

        .pkg-active-selected-tag {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #15803d;
            margin-bottom: 2px;
        }

        .pkg-active-selected-title {
            font-size: 17px;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
            line-height: 1.3;
        }

        .pkg-active-selected-desc {
            font-size: 12.5px;
            color: #475569;
            margin: 2px 0 0 0;
            line-height: 1.4;
        }

        .btn-change-pkg-choice {
            background: #ffffff;
            color: #1e293b;
            border: 1.5px solid #cbd5e1;
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .btn-change-pkg-choice:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            color: #0f172a;
            transform: translateY(-1px);
        }

        .pkg-active-content-wrapper {
            animation: fadeInSelected 0.3s ease-out;
        }

        /* Address Picker (PSGC API) Styling */
        .address-picker-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            margin-top: 6px;
        }
        .address-picker-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .address-picker-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .address-toggle-btn {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #475569;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.15s ease;
        }
        .address-toggle-btn:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
            color: var(--primary);
        }
        .address-select-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr));
            gap: 12px;
            margin-bottom: 12px;
        }
        .address-select-group label {
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            margin-bottom: 4px;
            display: block;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .address-preview-box {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            padding: 9px 13px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }

        /* Live Availability Calendar Styles */
        .avail-calendar-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px;
            margin-top: 12px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }
        .avail-calendar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-subtle);
        }
        .avail-calendar-title {
            font-size: 14.5px;
            font-weight: 700;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .avail-nav-btn {
            background: #f1f5f3;
            border: 1px solid #dbe5de;
            color: var(--primary);
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.15s ease;
        }
        .avail-nav-btn:hover:not(:disabled) {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }
        .avail-nav-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
        .avail-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
            margin-bottom: 6px;
            text-align: center;
        }
        .avail-weekday-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            padding: 4px 0;
        }
        .avail-days-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
        }
        .avail-day-cell {
            position: relative;
            aspect-ratio: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.15s ease;
            background: #f8faf9;
            color: var(--text-main);
            padding: 2px;
            min-height: 42px;
        }
        .avail-day-cell.empty {
            background: transparent;
            border: none;
            cursor: default;
        }
        .avail-day-cell.available {
            background: #ffffff;
            border-color: #d1fae5;
            color: #065f46;
        }
        .avail-day-cell.available:hover {
            background: #eaf2ec;
            border-color: var(--primary);
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(24, 57, 43, 0.15);
        }
        .avail-day-cell.selected {
            background: var(--primary) !important;
            color: #ffffff !important;
            border-color: var(--primary-dark) !important;
            font-weight: 800;
            box-shadow: 0 3px 8px rgba(24, 57, 43, 0.35);
        }
        .avail-day-cell.booked {
            background: #fef2f2 !important;
            color: #991b1b !important;
            border-color: #fecaca !important;
            cursor: not-allowed !important;
            opacity: 0.9;
        }
        .avail-day-cell.past {
            background: #f1f5f3 !important;
            color: #94a3b8 !important;
            cursor: not-allowed !important;
            opacity: 0.55;
        }
        .avail-badge-booked {
            font-size: 8.5px;
            font-weight: 800;
            line-height: 1;
            margin-top: 2px;
            background: #fee2e2;
            color: #dc2626;
            padding: 1px 4px;
            border-radius: 3px;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .avail-legend {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid var(--border-subtle);
            font-size: 11.5px;
            color: var(--text-muted);
            flex-wrap: wrap;
        }
        .avail-legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .avail-legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        .avail-legend-dot.available { background: #10b981; }
        .avail-legend-dot.booked { background: #ef4444; }
        .avail-legend-dot.selected { background: var(--primary); }
        .avail-legend-dot.past { background: #cbd5e1; }
    </style>
</head>
<body>

    <!-- Header Navigation (Panel 5 Style) -->
    <nav class="booking-nav" style="background: #233a2d; border-bottom: 1px solid rgba(255,255,255,0.1);">
        <div class="booking-nav-inner" style="max-width: 1240px; margin: 0 auto; padding: 12px 24px; display: flex; align-items: center; justify-content: space-between;">
            <a href="index.php" style="display: flex; align-items: center; text-decoration: none;">
                <img src="assets/tyoy_logo_cropped.png?v=<?= filemtime(__DIR__ . '/assets/tyoy_logo_cropped.png') ?>" alt="<?= htmlspecialchars($business_name) ?>" class="brand-logo-img" width="125" height="44">
            </a>

            <button type="button" class="mobile-nav-toggle" id="bookingNavToggle" aria-label="Toggle navigation menu" onclick="toggleBookingMobileNav()">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div class="nav-links-right" id="bookingNavLinks">
                <a href="index.php#home" class="nav-link-btn" style="color: #ffffff; text-decoration: none; font-size: 13.5px; font-weight: 500; opacity: 0.9;" onclick="toggleBookingMobileNav(false)">Home</a>
                <a href="index.php#about" class="nav-link-btn" style="color: #ffffff; text-decoration: none; font-size: 13.5px; font-weight: 500; opacity: 0.9;" onclick="toggleBookingMobileNav(false)">About</a>
                <a href="index.php#services" class="nav-link-btn" style="color: #ffffff; text-decoration: none; font-size: 13.5px; font-weight: 500; opacity: 0.9;" onclick="toggleBookingMobileNav(false)">Services</a>
                <a href="index.php#portfolio" class="nav-link-btn" style="color: #ffffff; text-decoration: none; font-size: 13.5px; font-weight: 500; opacity: 0.9;" onclick="toggleBookingMobileNav(false)">Portfolio</a>
                <a href="index.php#contact" class="nav-link-btn" style="color: #ffffff; text-decoration: none; font-size: 13.5px; font-weight: 500; opacity: 0.9;" onclick="toggleBookingMobileNav(false)">Contact</a>
                <?php if ($is_admin_logged_in): ?>
                    <a href="a_home.php" class="client-user-badge" title="Go to Admin Portal">
                        <i class="fa-solid fa-shield-halved" style="color: #285440; font-size: 14px;"></i>
                        Admin Portal
                    </a>
                    <a href="logout.php?redirect=booking.php" class="client-logout-nav" title="Sign Out">
                        <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
                    </a>
                <?php elseif ($is_client_logged_in): ?>
                    <span class="client-user-badge">
                        <i class="fa-solid fa-circle-user" style="color: #285440; font-size: 15px;"></i>
                        <?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User') ?>
                    </span>
                    <a href="logout.php?redirect=booking.php" class="client-logout-nav" title="Sign Out">
                        <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
                    </a>
                <?php else: ?>
                    <a href="login.php?redirect=booking.php" class="btn-nav-client-signin">
                        <i class="fa-solid fa-user"></i> Sign In
                    </a>
                <?php endif; ?>
                <a href="booking.php" class="btn-nav-book">Book Now</a>
            </div>
        </div>
    </nav>
    <div class="mobile-nav-backdrop" id="bookingNavBackdrop" onclick="toggleBookingMobileNav(false)"></div>

    <!-- Banner (Panel 5: Book an Event - Refined Color Harmony) -->
    <header class="booking-hero">
        <h1 class="hero-main-title">Book an Event</h1>
        <p class="hero-subtitle">
            Fill in the details below and we'll get back to you soon.
        </p>
    </header>

    <!-- React Booking App Mount Point with Loading Skeleton Fallback -->
    <div id="reactBookingRoot">
        <div id="bookingEngineFallback" style="max-width: 680px; margin: 40px auto; padding: 40px 24px; text-align: center; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
            <div style="font-size: 26px; color: var(--primary); margin-bottom: 12px;">
                <i class="fa-solid fa-circle-notch fa-spin"></i>
            </div>
            <div style="font-weight: 700; font-size: 16px; color: #1e293b; margin-bottom: 6px;">Loading Event Booking Form...</div>
            <div style="font-size: 13px; color: #64748b;">Please wait while packages and availability are initialized.</div>
        </div>
    </div>

    <!-- Panel 5 Brand Footer -->
    <footer class="wire-footer" style="background: #1f3327; color: #ffffff; padding: 36px 20px 24px 20px; border-top: 1px solid rgba(255,255,255,0.08); margin-top: 40px;">
        <div style="max-width: 1240px; margin: 0 auto; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 20px;">
            <a href="index.php" style="display: flex; align-items: center; text-decoration: none;">
                <img src="assets/tyoy_logo_cropped.png?v=<?= filemtime(__DIR__ . '/assets/tyoy_logo_cropped.png') ?>" alt="<?= htmlspecialchars($business_name) ?>" style="height: 38px; width: auto; border-radius: 6px; display: block;">
            </a>
            <div style="display: flex; gap: 16px 22px; font-size: 13.5px; opacity: 0.85; flex-wrap: wrap;">
                <a href="index.php#home" style="color: #ffffff; text-decoration: none;">Home</a>
                <a href="index.php#about" style="color: #ffffff; text-decoration: none;">About</a>
                <a href="index.php#services" style="color: #ffffff; text-decoration: none;">Services</a>
                <a href="index.php#portfolio" style="color: #ffffff; text-decoration: none;">Portfolio</a>
                <a href="index.php#contact" style="color: #ffffff; text-decoration: none;">Contact</a>
            </div>
            <div style="display: flex; gap: 14px; font-size: 16px;">
                <a href="https://www.facebook.com/tyoycreation" target="_blank" rel="noopener" title="Tyoy Creation Event Management &amp; Styling Service" aria-label="Tyoy Creation Facebook Page" style="color: #ffffff; opacity: 0.85; text-decoration: none;"><i class="fa-brands fa-facebook"></i></a>
                <a href="https://tiktok.com" target="_blank" rel="noopener" title="Tyoy Creation TikTok" aria-label="Tyoy Creation TikTok" style="color: #ffffff; opacity: 0.85; text-decoration: none;"><i class="fa-brands fa-tiktok"></i></a>
            </div>
        </div>
        <div style="text-align: center; margin-top: 24px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.08); font-size: 12px; opacity: 0.65;">
            &copy; <?= date('Y') ?> <?= htmlspecialchars($business_name) ?>. All rights reserved. &bull; <a href="loginadmin.php" style="color: inherit; text-decoration: none;"><i class="fa-solid fa-lock" style="font-size: 10px; margin-right: 4px;"></i>Staff Portal</a>
        </div>
    </footer>

    <!-- AI Chatbot Floating Concierge Widget -->
    <div class="chatbot-widget-container">
        <!-- 2. Welcome Popup Card (Speech Bubble above Launcher) -->
        <div class="chatbot-welcome-popup" id="chatbotWelcomePopup" role="region" aria-label="AI Chatbot Welcome Notification">
            <div class="chatbot-welcome-tail" aria-hidden="true">
                <svg width="20" height="10" viewBox="0 0 20 10" style="display: block;">
                    <path d="M0 0 L10 9 L20 0" fill="#0b1120" stroke="rgba(59, 130, 246, 0.3)" stroke-width="1.2" />
                    <line x1="0" y1="0" x2="20" y2="0" stroke="#0b1120" stroke-width="2.5" />
                </svg>
            </div>
            <div class="chatbot-welcome-header">
                <div class="chatbot-welcome-avatar">
                    <img src="assets/tyoy_logo_cropped.png?v=<?= filemtime(__DIR__ . '/assets/tyoy_logo_cropped.png') ?>" alt="Anton" class="chatbot-welcome-avatar-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                    <i class="fa-solid fa-robot" style="display: none; color: #60a5fa; font-size: 16px;"></i>
                </div>
                <div class="chatbot-welcome-title">AI Chatbot</div>
                <button type="button" class="chatbot-welcome-close" onclick="closeChatWelcomePopup(true)" aria-label="Close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="chatbot-welcome-body">
                <div class="chatbot-welcome-greeting">Hi! I'm <strong>Anton</strong>, your AI assistant.</div>
                <p class="chatbot-welcome-desc">
                    I can help you with questions about our event styling, kids parties, weddings, and more. Just type your message and I'll do my best to assist you!
                </p>
            </div>
            <div class="chatbot-welcome-notice-pill">
                <i class="fa-solid fa-circle-info"></i>
                <span>This is an AI chatbot. I'm not a real person.</span>
            </div>
        </div>

        <!-- 1. Floating Launcher Button: Circular with glowing blue ring & robot icon -->
        <button type="button" class="chatbot-launcher-btn" onclick="toggleChatWindow()" aria-label="Open AI chat">
            <svg class="chatbot-robot-svg" viewBox="0 0 32 32" width="32" height="32" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <line x1="16" y1="4.5" x2="16" y2="7.5"></line>
                <circle cx="16" cy="3.8" r="1.5" fill="#ffffff"></circle>
                <rect x="6" y="7.5" width="20" height="15.5" rx="5.5" stroke="#ffffff" stroke-width="2"></rect>
                <rect x="2.5" y="11.5" width="2.5" height="7" rx="1.25" fill="#ffffff"></rect>
                <rect x="27" y="11.5" width="2.5" height="7" rx="1.25" fill="#ffffff"></rect>
                <path d="M10 15.5 C10.6 13.5 12.6 13.5 13.2 15.5" stroke="#ffffff" stroke-width="2.2"></path>
                <path d="M18.8 15.5 C19.4 13.5 21.4 13.5 22 15.5" stroke="#ffffff" stroke-width="2.2"></path>
            </svg>
        </button>

        <div class="chatbot-window" id="chatbotWindow">
            <div class="chatbot-header">
                <div class="chatbot-header-info">
                    <div class="chatbot-avatar" style="background: #0b1120; border: 1.5px solid #3b82f6; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                        <svg viewBox="0 0 32 32" width="20" height="20" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="16" y1="4.5" x2="16" y2="7.5"></line>
                            <circle cx="16" cy="3.8" r="1.5" fill="#ffffff"></circle>
                            <rect x="6" y="7.5" width="20" height="15.5" rx="5.5" stroke="#ffffff" stroke-width="2"></rect>
                            <rect x="2.5" y="11.5" width="2.5" height="7" rx="1.25" fill="#ffffff"></rect>
                            <rect x="27" y="11.5" width="2.5" height="7" rx="1.25" fill="#ffffff"></rect>
                            <path d="M10 15.5 C10.6 13.5 12.6 13.5 13.2 15.5" stroke="#ffffff" stroke-width="2.2"></path>
                            <path d="M18.8 15.5 C19.4 13.5 21.4 13.5 22 15.5" stroke="#ffffff" stroke-width="2.2"></path>
                        </svg>
                    </div>
                    <div>
                        <div style="font-weight: 700; font-size: 14px;">Anton &bull; AI Assistant</div>
                        <div style="font-size: 11px; opacity: 0.85;">Here to answer business FAQs &amp; packages</div>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <button type="button" onclick="resetChatConversation()" title="Reset Conversation" style="background: none; border: none; color: white; cursor: pointer; font-size: 15px; opacity: 0.85; transition: opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.85'" aria-label="Reset Conversation">
                        <i class="fa-solid fa-arrows-rotate"></i>
                    </button>
                    <button type="button" onclick="toggleChatWindow()" style="background: none; border: none; color: white; cursor: pointer; font-size: 18px;" aria-label="Close Chat">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>

            <div class="chatbot-disclaimer-bar">
                <i class="fa-solid fa-circle-info"></i>
                <span>This is an AI chatbot. I'm not a real person &bull; Instant package &amp; styling FAQ assistance.</span>
            </div>

            <div class="chatbot-messages" id="chatbotMessages">
                <div class="chat-bubble bot">
                    Hello! I'm <strong>Anton</strong>, your AI assistant for <strong><?= htmlspecialchars($business_name) ?></strong>. I can help you with questions about our event styling, kids parties, weddings, pricing, and booking policies!
                    <div style="margin-top: 6px; font-size: 11.5px; opacity: 0.88; color: #4b5563;">
                        <em>💡 Note: Estimates are guided by AI; official reservations are verified by human staff coordinators.</em>
                    </div>
                    <div class="quick-chips-wrapper">
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('Track my booking status with Reference Number')"><i class="fa-solid fa-magnifying-glass"></i><span>Track booking status (Ref #)</span></button>
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('What packages and pricing do you offer?')"><i class="fa-solid fa-gift"></i><span>What packages do you offer?</span></button>
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('Why do bookings need staff review?')"><i class="fa-solid fa-clipboard-check"></i><span>Why do bookings need staff review?</span></button>
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('How does the booking process work?')"><i class="fa-solid fa-calendar-check"></i><span>How does booking work?</span></button>
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('What are your lead time and downpayment policies?')"><i class="fa-solid fa-clock"></i><span>Lead time &amp; downpayment</span></button>
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('Where is your office and what areas do you service?')"><i class="fa-solid fa-location-dot"></i><span>Office location &amp; service areas</span></button>
                    </div>
                </div>
            </div>

            <div class="chatbot-footer-wrapper">
                <form class="chatbot-footer" onsubmit="sendChatMessage(event)">
                    <textarea id="chatInput" placeholder="Type a message..." rows="1" oninput="autoResizeChatInput(this)" onkeydown="handleChatInputKeydown(event)"></textarea>
                    <button type="submit" aria-label="Send Message"><i class="fa-solid fa-paper-plane"></i></button>
                </form>
                <div class="chat-input-hint">Press <strong>Enter</strong> to send • <strong>Shift + Enter</strong> for new line</div>
            </div>
        </div>
    </div>

    <!-- Cookie Consent Banner -->
    <div id="cookieConsentBanner" class="cookie-consent-bar" style="display: none;">
        <div class="cookie-consent-content">
            <div class="cookie-consent-icon">
                <i class="fa-solid fa-cookie-bite"></i>
            </div>
            <div class="cookie-consent-text">
                <h4>We value your privacy &amp; experience</h4>
                <p>
                    We use cookies and local storage to optimize your navigation, ensure session security, remember your customized package preferences, and synchronize chat concierge inquiries. Do you accept the use of cookies?
                </p>
            </div>
            <div class="cookie-consent-actions">
                <button type="button" class="btn-cookie-decline" onclick="handleCookieConsent('declined')">Decline</button>
                <button type="button" class="btn-cookie-accept" onclick="handleCookieConsent('accepted')">Accept Cookies</button>
            </div>
        </div>
    </div>

    <!-- React Data Configuration from Backend -->
    <script>
        window.BOOKING_CONFIG = {
            csrfToken: <?= json_encode(csrf_token()) ?>,
            businessName: <?= json_encode($business_name) ?>,
            pricingTheme: <?= json_encode($pricing_theme) ?>,
            pricingWedding: <?= json_encode($pricing_wedding) ?>,
            todayDate: <?= json_encode($today_date) ?>,
            todayFormatted: <?= json_encode($today_formatted) ?>,
            minWeddingDate: <?= json_encode($min_wedding_date) ?>,
            minWeddingFormatted: <?= json_encode($min_wedding_formatted) ?>,
            minKidsDate: <?= json_encode($min_kids_date) ?>,
            minKidsFormatted: <?= json_encode($min_kids_formatted) ?>,
            initialType: <?= json_encode($initial_type) ?>,
            initialFamily: <?= json_encode($initial_family) ?>,
            initialPackage: <?= json_encode($requested_package) ?>,
            isTypeLocked: <?= json_encode($is_type_locked_from_url) ?>,
            turnstileEnabled: <?= json_encode(qes_is_turnstile_enabled($conn)) ?>,
            turnstileSiteKey: <?= json_encode(qes_get_turnstile_site_key($conn)) ?>,
            userLoggedIn: <?= json_encode(!empty($_SESSION['id']) && (!isset($_SESSION['role']) || $_SESSION['role'] === 'user')) ?>,
            userFullName: <?= json_encode($_SESSION['full_name'] ?? '') ?>,
            userEmail: <?= json_encode($_SESSION['email'] ?? '') ?>,
            userPhone: <?= json_encode($_SESSION['phone'] ?? '') ?>
        };
    </script>

    <!-- React 18 Embedded Component (Option 2) -->
    <script type="text/babel">
        const { useState, useMemo, useEffect, useRef } = React;

        function BookingApp() {
            const config = window.BOOKING_CONFIG || {};

            // Celebration family & event type state
            const [family, setFamily] = useState(config.initialFamily || 'theme_party');
            const [eventType, setEventType] = useState(config.initialType || 'Kids Party');

            // Dynamic preparation reminder rules based on active family (free will to choose date from today onwards)
            const isWedding = (family === 'wedding');
            const todayStr = config.todayDate || new Date().toISOString().split('T')[0];
            const todayFormatted = config.todayFormatted || '';
            const minDateStr = todayStr;
            const minDateFormatted = todayFormatted;
            const reminderLabel = isWedding ? '3 Months Preparation Reminder' : '2 Weeks Preparation Reminder';

            // Active catalog based on family (theme_party or wedding)
            const currentCatalog = useMemo(() => isWedding ? (config.pricingWedding || {}) : (config.pricingTheme || {}), [isWedding, config]);

            // Form inputs state (Pre-filled if logged in as client)
            const [clientName, setClientName] = useState(config.userFullName || '');
            const [clientEmail, setClientEmail] = useState(config.userEmail || '');
            const [clientPhone, setClientPhone] = useState(config.userPhone || '');
            const [clientAddress, setClientAddress] = useState('');

            // Address API State (Philippine Standard Geographic Code: Regions, Cities/Municipalities, Barangays)
            const [regionsList, setRegionsList] = useState([]);
            const [selectedRegion, setSelectedRegion] = useState('');
            const [citiesList, setCitiesList] = useState([]);
            const [selectedCity, setSelectedCity] = useState('');
            const [barangaysList, setBarangaysList] = useState([]);
            const [selectedBarangay, setSelectedBarangay] = useState('');
            const [streetDetail, setStreetDetail] = useState('');
            const [addressLoading, setAddressLoading] = useState(false);
            const [isManualAddress, setIsManualAddress] = useState(false);

            const [eventTitle, setEventTitle] = useState('');
            const [guestCount, setGuestCount] = useState('100');
            const [eventDate, setEventDate] = useState(todayStr);
            const [eventTime, setEventTime] = useState('14:00');
            const [locationVenue, setLocationVenue] = useState('');
            const [specialNotes, setSpecialNotes] = useState('');

            // Selected packages/addons state: { [itemId]: { id, catKey, catTitle, label, price, value, isRadio } }
            const [selectedItems, setSelectedItems] = useState({});

            // Package Category Selection State (null initially until customer picks 1 of the 3)
            const [activeStep3GroupKey, setActiveStep3GroupKey] = useState(null);

            // Lightweight Package Group Mapping (Dynamically mapped to existing catalog keys)
            const PACKAGE_GROUPS = useMemo(() => ({
                theme_party: [
                    {
                        key: 'custom_package',
                        title: 'Custom Package',
                        subtitle: 'Complete event package customized for your celebration',
                        icon: 'fa-cubes-stacked',
                        categories: [
                            'catering',
                            'styling',
                            'sound_lights',
                            'entertainment',
                            'food_carts',
                            'photobooth',
                            'photo_video',
                            'otd'
                        ],
                        titleOverrides: {
                            catering: 'Catering Service',
                            photo_video: 'Photo & Video'
                        }
                    },
                    {
                        key: 'theme_styling',
                        title: 'Theme Party Styling',
                        subtitle: 'Theme and decoration options for memorable celebrations',
                        icon: 'fa-wand-magic-sparkles',
                        categories: [
                            'theme_styling',
                            'character',
                            'lightning',
                            'ceiling'
                        ],
                        titleOverrides: {
                            theme_styling: 'Styling',
                            lightning: 'Lightning / Lighting',
                            ceiling: 'Ceiling'
                        }
                    },
                    {
                        key: 'catering_service',
                        title: 'Catering Service',
                        subtitle: 'Food and guest capacity options tailored to your guests',
                        icon: 'fa-utensils',
                        isCateringOrganizer: true,
                        categories: [
                            'catering',
                            'catering_addons'
                        ]
                    }
                ],
                wedding: [
                    {
                        key: 'custom_package',
                        title: 'Custom Package',
                        subtitle: 'Complete wedding celebration package',
                        icon: 'fa-crown',
                        categories: [
                            'catering',
                            'styling',
                            'entourage_flower',
                            'ceremony_styling',
                            'sound_lights',
                            'entertainment',
                            'food_carts',
                            'photobooth',
                            'photo_video',
                            'otd',
                            'partner_venue'
                        ],
                        titleOverrides: {
                            catering: 'Catering Service',
                            otd: 'OTD Coordination',
                            photo_video: 'Photo & Video'
                        }
                    },
                    {
                        key: 'custom_styling',
                        title: 'Custom Styling',
                        subtitle: 'Reception styling, ceremony floral, and venue ceiling options',
                        icon: 'fa-spa',
                        categories: [
                            'reception_styling',
                            'entourage_flower',
                            'ceremony_styling',
                            'ceiling_treatment'
                        ]
                    },
                    {
                        key: 'planning_coordination',
                        title: 'Planning & Coordination',
                        subtitle: 'Professional on-the-day coordination and planning',
                        icon: 'fa-clipboard-check',
                        categories: [
                            'otd'
                        ],
                        titleOverrides: {
                            otd: 'OTD Coordination'
                        }
                    }
                ]
            }), []);

            // Active category groups for the current celebration family
            const currentGroups = useMemo(() => {
                return PACKAGE_GROUPS[family] || PACKAGE_GROUPS.theme_party;
            }, [PACKAGE_GROUPS, family]);

            const activeGroup = useMemo(() => {
                if (!activeStep3GroupKey) return null;
                return currentGroups.find(g => g.key === activeStep3GroupKey) || null;
            }, [currentGroups, activeStep3GroupKey]);

            // Count selected items within a specific package group
            const getGroupSelectedCount = (group) => {
                if (!group) return 0;
                const catKeys = group.categories || [];
                let count = 0;
                Object.values(selectedItems).forEach(it => {
                    if (catKeys.includes(it.catKey)) {
                        count++;
                    }
                });
                return count;
            };

            // Validation & submission state
            const [errors, setErrors] = useState({});
            const [submitError, setSubmitError] = useState('');
            const [isSubmitting, setIsSubmitting] = useState(false);
            const [confirmedBooking, setConfirmedBooking] = useState(null);
            const [showExitConfirm, setShowExitConfirm] = useState(false);

            // Data Privacy Consent & Policy Modal State (RA 10173)
            // Gate: modal shows if not previously agreed; form is locked until user agrees
            const hasPreAgreed = (() => {
                try {
                    if (config.userLoggedIn) return true;
                    return sessionStorage.getItem('tyoy_privacy_agreed') === '1';
                } catch (e) {
                    return false;
                }
            })();
            const [privacyConsent, setPrivacyConsent] = useState(hasPreAgreed);
            const [privacyAccepted, setPrivacyAccepted] = useState(hasPreAgreed);
            const [showPrivacyModal, setShowPrivacyModal] = useState(!hasPreAgreed);

            // Exit & Reset Reminder Modal State
            const [exitWarningModal, setExitWarningModal] = useState({
                isOpen: false,
                targetUrl: 'index.php'
            });

            // Turnstile Widget ID Refs
            const mainTurnstileWidgetId = useRef(null);
            const modalTurnstileWidgetId = useRef(null);

            // Email Warning & Misconception Prevention Modal State
            const [emailWarningModal, setEmailWarningModal] = useState({
                isOpen: false,
                email: '',
                typoWarning: null
            });

            // Schedule Conflict & Double-Booking Reminder Modal State
            const [conflictModal, setConflictModal] = useState({
                isOpen: false,
                eventDate: '',
                eventTime: '',
                friendlyDate: '',
                friendlyTime: '',
                message: ''
            });

            // Double-booking detection helper: Triggers reminder modal and blocks verification flow
            const triggerDoubleBookingModal = (dateStr, customMsg) => {
                let friendly = dateStr;
                try {
                    if (dateStr) {
                        friendly = new Date(dateStr + 'T00:00:00').toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
                    }
                } catch (e) {}

                // Ensure verification modals are closed
                setVerifyModal(prev => ({ ...prev, isOpen: false }));
                setEmailWarningModal({ isOpen: false, email: '', typoWarning: null });

                setConflictModal({
                    isOpen: true,
                    eventDate: dateStr,
                    eventTime: eventTime,
                    friendlyDate: friendly,
                    friendlyTime: '',
                    message: customMsg || 'Sorry, the date and time you selected is already reserved. Please choose a different date or time, or contact us if you need help.'
                });
            };

            // Accessibility: Escape key dismisses Date Unavailable modal and focuses date input
            useEffect(() => {
                if (!conflictModal.isOpen) return;
                const handleEscKey = (e) => {
                    if (e.key === 'Escape') {
                        handleResolveConflictChangeDate();
                    }
                };
                window.addEventListener('keydown', handleEscKey);
                return () => window.removeEventListener('keydown', handleEscKey);
            }, [conflictModal.isOpen]);

            // Live Database-Driven Availability State (One confirmed event per day)
            const [bookedDates, setBookedDates] = useState([]);
            const [isAvailabilityLoading, setIsAvailabilityLoading] = useState(true);
            const [availabilityError, setAvailabilityError] = useState('');
            const [calendarViewMonth, setCalendarViewMonth] = useState(() => {
                const d = new Date();
                return new Date(d.getFullYear(), d.getMonth(), 1);
            });

            // Fetch live availability from database
            const fetchAvailability = async () => {
                setIsAvailabilityLoading(true);
                setAvailabilityError('');
                try {
                    const res = await fetch('booking_availability.php');
                    const data = await res.json();
                    if (data.success && Array.isArray(data.booked_dates)) {
                        setBookedDates(data.booked_dates);
                    } else {
                        setAvailabilityError(data.message || 'Unable to retrieve availability schedule.');
                    }
                } catch (e) {
                    setAvailabilityError('Unable to connect to availability service. Please check your connection.');
                } finally {
                    setIsAvailabilityLoading(false);
                }
            };

            useEffect(() => {
                fetchAvailability();
            }, []);

            const calendarMonthName = useMemo(() => {
                return calendarViewMonth.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
            }, [calendarViewMonth]);

            const handlePrevMonth = () => {
                setCalendarViewMonth(prev => new Date(prev.getFullYear(), prev.getMonth() - 1, 1));
            };

            const handleNextMonth = () => {
                setCalendarViewMonth(prev => new Date(prev.getFullYear(), prev.getMonth() + 1, 1));
            };

            const renderCalendarDays = () => {
                const year = calendarViewMonth.getFullYear();
                const month = calendarViewMonth.getMonth();
                const firstDayIndex = new Date(year, month, 1).getDay();
                const daysInMonth = new Date(year, month + 1, 0).getDate();
                const todayStr = minDateStr;

                const cells = [];
                for (let i = 0; i < firstDayIndex; i++) {
                    cells.push(<div key={`empty-${i}`} className="avail-day-cell empty"></div>);
                }

                for (let day = 1; day <= daysInMonth; day++) {
                    const dStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                    const isPast = dStr < todayStr;
                    const isBooked = bookedDates.includes(dStr);
                    const isSelected = (eventDate === dStr);

                    let cellClass = 'avail-day-cell';
                    if (isSelected) {
                        cellClass += ' selected';
                    } else if (isPast) {
                        cellClass += ' past';
                    } else if (isBooked) {
                        cellClass += ' booked';
                    } else {
                        cellClass += ' available';
                    }

                    const handleDayClick = () => {
                        if (isPast) return;
                        if (isBooked) {
                            triggerDoubleBookingModal(dStr);
                            return;
                        }
                        setEventDate(dStr);
                        setErrors(prev => ({ ...prev, eventDate: null }));
                    };

                    cells.push(
                        <div 
                            key={dStr} 
                            className={cellClass}
                            onClick={handleDayClick}
                            title={isBooked ? `${dStr} is already fully booked` : (isPast ? `${dStr} is in the past` : `Click to select ${dStr}`)}
                        >
                            <span>{day}</span>
                            {isBooked && !isPast && (
                                <span className="avail-badge-booked">Booked</span>
                            )}
                        </div>
                    );
                }
                return cells;
            };

            // Two-Step Verification State
            const [verifyModal, setVerifyModal] = useState({
                isOpen: false,
                email: '',
                maskedEmail: '',
                token: '',
                otpCode: '',
                error: '',
                isLoading: false,
                resendCooldown: 60,
                resendSuccess: '',
                isVerified: false,
                expiresIn: 300,
                isEditingEmail: false,
                newEmailInput: '',
                isUpdatingEmail: false,
                emailUpdateError: ''
            });

            // Check if user has active unsaved booking data/input
            const hasEnteredData = useMemo(() => {
                if (confirmedBooking) return false;
                return Boolean(
                    clientName.trim() ||
                    clientEmail.trim() ||
                    clientPhone.trim() ||
                    clientAddress.trim() ||
                    streetDetail.trim() ||
                    locationVenue.trim() ||
                    eventTitle.trim() ||
                    specialNotes.trim() ||
                    Object.keys(selectedItems).length > 0 ||
                    verifyModal.isOpen ||
                    emailWarningModal.isOpen ||
                    conflictModal.isOpen
                );
            }, [
                confirmedBooking,
                clientName,
                clientEmail,
                clientPhone,
                clientAddress,
                streetDetail,
                locationVenue,
                eventTitle,
                specialNotes,
                selectedItems,
                verifyModal.isOpen,
                emailWarningModal.isOpen,
                conflictModal.isOpen
            ]);

            // Exit trigger helper
            const triggerExitWarning = (targetUrl = 'index.php') => {
                if (hasEnteredData) {
                    setExitWarningModal({
                        isOpen: true,
                        targetUrl: targetUrl || 'index.php'
                    });
                } else {
                    window.location.href = targetUrl || 'index.php';
                }
            };

            // Smart Typo & Domain Misscheck Helper
            const detectEmailIssues = (email) => {
                if (!email) return null;
                const trimmed = email.trim().toLowerCase();
                const parts = trimmed.split('@');
                if (parts.length !== 2) return null;
                const [user, domain] = parts;

                const domainMap = {
                    'gmai.com': 'gmail.com',
                    'gmial.com': 'gmail.com',
                    'gamil.com': 'gmail.com',
                    'gnail.com': 'gmail.com',
                    'gmaill.com': 'gmail.com',
                    'gmal.com': 'gmail.com',
                    'gmaik.com': 'gmail.com',
                    'gmail.co': 'gmail.com',
                    'gmail.con': 'gmail.com',
                    'gemail.com': 'gmail.com',
                    'yaho.com': 'yahoo.com',
                    'yahooo.com': 'yahoo.com',
                    'yhaoo.com': 'yahoo.com',
                    'yahoo.co': 'yahoo.com',
                    'yahoo.con': 'yahoo.com',
                    'hotmial.com': 'hotmail.com',
                    'hotmaill.com': 'hotmail.com',
                    'hotmai.com': 'hotmail.com',
                    'outlok.com': 'outlook.com',
                    'outloo.com': 'outlook.com',
                    'outllok.com': 'outlook.com',
                    'iclod.com': 'icloud.com',
                    'iclou.com': 'icloud.com'
                };

                if (domainMap[domain]) {
                    const corrected = `${user}@${domainMap[domain]}`;
                    return {
                        type: 'domain_typo',
                        suggested: corrected,
                        message: `Did you mean "@${domainMap[domain]}"? We detected a likely typo in "${domain}".`
                    };
                }

                if (domain.endsWith('.cm')) {
                    const corrected = `${user}@${domain.slice(0, -3)}.com`;
                    return {
                        type: 'tld_typo',
                        suggested: corrected,
                        message: `Did you mean ".com" instead of ".cm"?`
                    };
                }

                return null;
            };

            // Timer countdown effect for OTP expiration & resend cooldown
            useEffect(() => {
                if (!verifyModal.isOpen) return;

                const timer = setInterval(() => {
                    setVerifyModal(prev => {
                        if (!prev.isOpen) return prev;
                        return {
                            ...prev,
                            expiresIn: Math.max(0, prev.expiresIn - 1),
                            resendCooldown: Math.max(0, prev.resendCooldown - 1)
                        };
                    });
                }, 1000);

                return () => clearInterval(timer);
            }, [verifyModal.isOpen]);

            // Global link navigation interceptor & browser unload warning
            useEffect(() => {
                const handleGlobalLinkClick = (e) => {
                    const link = e.target.closest('a');
                    if (!link) return;
                    const href = link.getAttribute('href');
                    if (!href) return;

                    // Ignore hash anchors or void
                    if (href.startsWith('#') || href.startsWith('javascript:')) return;

                    const isExternalOrHome = (
                        href === 'index.php' ||
                        href.startsWith('index.php#') ||
                        href === '/' ||
                        (href.startsWith('http') && !href.includes('booking.php'))
                    );

                    if (isExternalOrHome && hasEnteredData && !confirmedBooking) {
                        e.preventDefault();
                        e.stopPropagation();
                        setExitWarningModal({
                            isOpen: true,
                            targetUrl: link.href || href
                        });
                    }
                };

                document.addEventListener('click', handleGlobalLinkClick, true);

                const handleBeforeUnload = (e) => {
                    if (hasEnteredData && !confirmedBooking) {
                        e.preventDefault();
                        e.returnValue = '';
                        return '';
                    }
                };

                window.addEventListener('beforeunload', handleBeforeUnload);

                return () => {
                    document.removeEventListener('click', handleGlobalLinkClick, true);
                    window.removeEventListener('beforeunload', handleBeforeUnload);
                };
            }, [hasEnteredData, confirmedBooking]);

            // Lock body scroll when any modal is open
            useEffect(() => {
                const isAnyModalOpen = verifyModal.isOpen || emailWarningModal.isOpen || exitWarningModal.isOpen || conflictModal.isOpen || !!confirmedBooking;
                if (isAnyModalOpen) {
                    const originalOverflow = document.body.style.overflow;
                    document.body.style.overflow = 'hidden';
                    return () => {
                        document.body.style.overflow = originalOverflow;
                    };
                }
            }, [verifyModal.isOpen, emailWarningModal.isOpen, exitWarningModal.isOpen, conflictModal.isOpen, !!confirmedBooking]);

            // Cloudflare Turnstile Manager
            const renderTurnstileWidget = (containerSelector, widgetRef) => {
                if (!config.turnstileEnabled || !config.turnstileSiteKey) return;

                const tryRender = () => {
                    if (typeof window.turnstile === 'undefined' || !window.turnstile.render) {
                        return false;
                    }
                    const container = document.querySelector(containerSelector);
                    if (!container) return false;

                    // If already rendered, reset
                    if (widgetRef.current !== null) {
                        try {
                            window.turnstile.reset(widgetRef.current);
                            return true;
                        } catch (e) {
                            widgetRef.current = null;
                        }
                    }

                    container.innerHTML = '';
                    try {
                        widgetRef.current = window.turnstile.render(container, {
                            sitekey: config.turnstileSiteKey,
                            theme: 'light',
                            'refresh-expired': 'auto',
                            'error-callback': (err) => {
                                console.warn('[QES Turnstile] Widget error:', err);
                                if ((err === '110200' || err === 110200) && (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1')) {
                                    try {
                                        if (window.turnstile.remove) window.turnstile.remove(widgetRef.current);
                                        container.innerHTML = '';
                                        widgetRef.current = window.turnstile.render(container, {
                                            sitekey: '1x00000000000000000000AA',
                                            theme: 'light',
                                            'refresh-expired': 'auto'
                                        });
                                    } catch (ex) {}
                                }
                            }
                        });
                        return true;
                    } catch (e) {
                        return false;
                    }
                };

                if (!tryRender()) {
                    let attempts = 0;
                    const interval = setInterval(() => {
                        attempts++;
                        if (tryRender() || attempts > 25) {
                            clearInterval(interval);
                        }
                    }, 200);
                }
            };

            const resetTurnstileWidget = (widgetRef) => {
                if (widgetRef && widgetRef.current !== null && window.turnstile && window.turnstile.reset) {
                    try {
                        window.turnstile.reset(widgetRef.current);
                    } catch (e) {}
                }
            };

            // Initialize main form Turnstile
            useEffect(() => {
                if (config.turnstileEnabled && config.turnstileSiteKey) {
                    renderTurnstileWidget('#mainBookingTurnstile', mainTurnstileWidgetId);
                }
            }, [config.turnstileEnabled, config.turnstileSiteKey]);

            // Initialize modal Turnstile when OTP verification opens
            useEffect(() => {
                if (verifyModal.isOpen && config.turnstileEnabled && config.turnstileSiteKey) {
                    renderTurnstileWidget('#verifyModalTurnstile', modalTurnstileWidgetId);
                } else if (!verifyModal.isOpen) {
                    resetTurnstileWidget(modalTurnstileWidgetId);
                }
            }, [verifyModal.isOpen, config.turnstileEnabled, config.turnstileSiteKey]);

            const formatTimeRemaining = (totalSec) => {
                const m = Math.floor(totalSec / 60);
                const s = totalSec % 60;
                return `${m}:${s < 10 ? '0' : ''}${s}`;
            };

            const handleOtpChange = (e) => {
                const val = e.target.value.replace(/\D/g, '').slice(0, 6);
                setVerifyModal(prev => ({ ...prev, otpCode: val, error: '' }));
            };

            // Dynamic lock: locked ONLY if user arrived from "Our Services" (preset via URL)
            const isTypeLocked = Boolean(config.isTypeLocked);

            // Auto-select package item if passed from Services / URL
            useEffect(() => {
                if (config.initialPackage) {
                    const catalog = (family === 'wedding') ? (config.pricingWedding || {}) : (config.pricingTheme || {});
                    let foundItem = null;
                    let foundCatKey = null;
                    let foundCatInfo = null;

                    for (const [catKey, catInfo] of Object.entries(catalog)) {
                        const items = catInfo.items || [];
                        const match = items.find(it => 
                            it.label && (
                                it.label.toLowerCase() === config.initialPackage.toLowerCase() || 
                                it.label.toLowerCase().includes(config.initialPackage.toLowerCase()) ||
                                config.initialPackage.toLowerCase().includes(it.label.toLowerCase())
                            )
                        );
                        if (match) {
                            foundItem = match;
                            foundCatKey = catKey;
                            foundCatInfo = catInfo;
                            break;
                        }
                    }

                    if (foundItem && foundCatKey && foundCatInfo) {
                        const itemId = foundItem.id || (foundCatKey + '_' + foundItem.label);
                        const price = parseInt(foundItem.price, 10) || 0;
                        setSelectedItems(prev => ({
                            ...prev,
                            [itemId]: {
                                id: itemId,
                                catKey: foundCatKey,
                                catTitle: foundCatInfo.category_title || foundCatKey,
                                label: foundItem.label,
                                price: price,
                                value: foundItem.value || ((foundCatInfo.category_title || foundCatKey) + ': ' + foundItem.label),
                                isRadio: (foundCatInfo.type !== 'checkbox')
                            }
                        }));

                        // If preselected package is catering, sync guest count so it doesn't default to 100 and exceed
                        const isCateringCat = (foundCatKey === 'catering');
                        if (isCateringCat && foundItem.label) {
                            const match = foundItem.label.match(/(\d+)\s*pax/i);
                            if (match) {
                                setGuestCount(String(parseInt(match[1], 10)));
                            }
                        }

                        // If preselected package is found, automatically activate its category group
                        const matchedGroup = currentGroups.find(g => g.categories && g.categories.includes(foundCatKey));
                        if (matchedGroup) {
                            setActiveStep3GroupKey(matchedGroup.key);
                        }
                    }
                }
            }, []);

            // Switch celebration type (Weddings vs Kids Party)
            const handleSwitchEventType = (newType) => {
                if (isTypeLocked) {
                    return; // Prevent changing event type when locked
                }
                const newFam = newType.toLowerCase().includes('wedding') ? 'wedding' : 'theme_party';
                setEventType(newType);
                setFamily(newFam);
                if (!eventDate || eventDate < minDateStr) {
                    setEventDate(minDateStr);
                }
                // Reset selections when switching family to prevent cross-catalog mismatches
                setSelectedItems({});
                setActiveStep3GroupKey(null);
            };

            // Unified Event Type Selection Handler
            const handleSelectEventType = (newType) => {
                if (isTypeLocked) {
                    return;
                }
                const targetFam = newType.toLowerCase().includes('wedding') ? 'wedding' : 'theme_party';
                if (targetFam !== family) {
                    handleSwitchEventType(newType);
                } else {
                    setActiveStep3GroupKey(null);
                }
            };

            const handleSwitchFamily = (newFam) => {
                if (isTypeLocked) {
                    return; // Prevent changing family when locked
                }
                const newType = (newFam === 'wedding') ? 'Weddings' : 'Kids Party';
                handleSelectEventType(newType);
            };

            // Dynamically compute the maximum catering service package offered by admin in active catalog.
            // When admin adds e.g. 600 pax, 700 pax, etc., this automatically becomes 600, 700, etc.!
            const maxCatalogCateringPax = useMemo(() => {
                const cateringCat = currentCatalog['catering'];
                if (!cateringCat || !Array.isArray(cateringCat.items) || cateringCat.items.length === 0) {
                    return 500;
                }
                let maxFound = 0;
                cateringCat.items.forEach(item => {
                    const m = (item.label || '').match(/(\d+)\s*pax/i);
                    if (m) {
                        const val = parseInt(m[1], 10);
                        if (val > maxFound) maxFound = val;
                    }
                });
                return maxFound > 0 ? maxFound : 500;
            }, [currentCatalog]);

            // Address API: Load Regions on Mount
            useEffect(() => {
                fetch('address_api.php?action=regions')
                    .then(res => res.json())
                    .then(data => {
                        if (Array.isArray(data)) {
                            setRegionsList(data);
                        }
                    })
                    .catch(err => console.error('Address API: failed to load regions', err));
            }, []);

            // Address API: When Region changes, fetch Cities / Municipalities
            useEffect(() => {
                if (!selectedRegion) {
                    setCitiesList([]);
                    setSelectedCity('');
                    setBarangaysList([]);
                    setSelectedBarangay('');
                    return;
                }
                setAddressLoading(true);
                fetch(`address_api.php?action=cities&code=${encodeURIComponent(selectedRegion)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (Array.isArray(data)) {
                            const sorted = [...data].sort((a, b) => (a.name || '').localeCompare(b.name || ''));
                            setCitiesList(sorted);
                        }
                        setSelectedCity('');
                        setBarangaysList([]);
                        setSelectedBarangay('');
                    })
                    .catch(err => console.error('Address API: failed to load cities', err))
                    .finally(() => setAddressLoading(false));
            }, [selectedRegion]);

            // Address API: When City changes, fetch Barangays
            useEffect(() => {
                if (!selectedCity) {
                    setBarangaysList([]);
                    setSelectedBarangay('');
                    return;
                }
                setAddressLoading(true);
                fetch(`address_api.php?action=barangays&code=${encodeURIComponent(selectedCity)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (Array.isArray(data)) {
                            const sorted = [...data].sort((a, b) => (a.name || '').localeCompare(b.name || ''));
                            setBarangaysList(sorted);
                        }
                        setSelectedBarangay('');
                    })
                    .catch(err => console.error('Address API: failed to load barangays', err))
                    .finally(() => setAddressLoading(false));
            }, [selectedCity]);

            // Address API: Format clientAddress when picker fields change
            useEffect(() => {
                if (isManualAddress) return;
                const regObj = regionsList.find(r => r.code === selectedRegion);
                const cityObj = citiesList.find(c => c.code === selectedCity);
                const bgyObj = barangaysList.find(b => b.code === selectedBarangay);

                const parts = [];
                if (streetDetail.trim()) parts.push(streetDetail.trim());
                if (bgyObj) parts.push(`Brgy. ${bgyObj.name}`);
                if (cityObj) parts.push(cityObj.name);
                if (regObj) parts.push(regObj.name);

                if (parts.length > 0) {
                    setClientAddress(parts.join(', '));
                }
            }, [selectedRegion, selectedCity, selectedBarangay, streetDetail, isManualAddress, regionsList, citiesList, barangaysList]);

            // Compute catering pax capacity limit from selected catering package.
            // STRICT: Must ONLY match the actual 'catering' category (Pax Capacity), NEVER catering_addons or others!
            const cateringPaxLimit = useMemo(() => {
                const items = Object.values(selectedItems);
                const cateringItem = items.find(it => it.catKey === 'catering');
                if (cateringItem && cateringItem.label) {
                    const match = cateringItem.label.match(/(\d+)\s*pax/i);
                    if (match) return parseInt(match[1], 10);
                }
                return null;
            }, [selectedItems]);

            // Additional pax capacity from add-on packages (if any add extra pax like "+50 pax")
            const additionalPax = useMemo(() => {
                let extra = 0;
                Object.values(selectedItems).forEach(it => {
                    if (it.catKey !== 'catering' && it.label) {
                        const addMatch = it.label.match(/(?:\+|extra|additional)\s*(\d+)\s*pax/i);
                        if (addMatch) {
                            extra += parseInt(addMatch[1], 10);
                        }
                    }
                });
                return extra;
            }, [selectedItems]);

            // Selected catering package limit (+ extra addon pax)
            const selectedPackageLimit = useMemo(() => {
                if (cateringPaxLimit) {
                    return cateringPaxLimit + additionalPax;
                }
                return null;
            }, [cateringPaxLimit, additionalPax]);

            // Combined total guest limit based on catering service pax:
            // 1. If user selected a catering package (e.g. 200 pax), limit is 200.
            // 2. If no catering package is selected yet, limit is the highest catering package configured by admin (e.g. 500 pax, or 600 if admin added 600 pax).
            const totalGuestLimit = useMemo(() => {
                return selectedPackageLimit || maxCatalogCateringPax;
            }, [selectedPackageLimit, maxCatalogCateringPax]);

            // Real-time numeric guest count and exceeded status
            const numGuests = useMemo(() => parseInt(guestCount, 10) || 0, [guestCount]);
            const isGuestExceeded = Boolean(totalGuestLimit && numGuests > totalGuestLimit);

            // Handle guest count input changes (strips non-digits and removes leading zeros)
            const handleGuestCountChange = (e) => {
                const raw = e.target.value;
                let cleaned = raw.replace(/\D/g, '');
                // Prevent leading zeros like "0100" -> "100"
                if (cleaned.length > 1 && cleaned.startsWith('0')) {
                    cleaned = cleaned.replace(/^0+/, '');
                    if (cleaned === '') cleaned = '0';
                }
                setGuestCount(cleaned);
                if (errors.guestCount) {
                    setErrors(prev => ({ ...prev, guestCount: null }));
                }
            };

            // Dynamic add/subtract selection handler
            const handleOptionToggle = (catKey, catInfo, item) => {
                const isCheckbox = (catInfo.type === 'checkbox');
                const itemId = item.id || (catKey + '_' + item.label);
                const price = parseInt(item.price, 10) || 0;
                const itemVal = item.value || ((catInfo.category_title || catKey) + ': ' + item.label);

                // Auto-sync guest count suggestion when selecting a catering package
                const isCateringCat = (catKey === 'catering');
                if (isCateringCat) {
                    const match = item.label.match(/(\d+)\s*pax/i);
                    if (match) {
                        const newPax = parseInt(match[1], 10);
                        // If selecting (not deselecting), sync guest count
                        if (!selectedItems[itemId]) {
                            setGuestCount(String(newPax));
                        }
                    }
                }

                setSelectedItems(prev => {
                    const next = { ...prev };
                    if (isCheckbox) {
                        if (next[itemId]) {
                            // Unselect checkbox -> subtract price
                            delete next[itemId];
                        } else {
                            // Select checkbox -> add price
                            next[itemId] = {
                                id: itemId,
                                catKey: catKey,
                                catTitle: catInfo.category_title || catKey,
                                label: item.label,
                                price: price,
                                value: itemVal,
                                isRadio: false
                            };
                        }
                    } else {
                        // Radio behavior: support unselecting!
                        if (next[itemId]) {
                            // Already selected -> unselect and subtract price
                            delete next[itemId];
                        } else {
                            // Deselect any other radio in this same category
                            Object.keys(next).forEach(k => {
                                if (next[k].catKey === catKey && next[k].isRadio) {
                                    delete next[k];
                                }
                            });
                            // Select this radio -> add price
                            next[itemId] = {
                                id: itemId,
                                catKey: catKey,
                                catTitle: catInfo.category_title || catKey,
                                label: item.label,
                                price: price,
                                value: itemVal,
                                isRadio: true
                            };
                        }
                    }
                    return next;
                });
            };

            // Remove item from sidebar summary
            const handleRemoveItem = (itemId) => {
                setSelectedItems(prev => {
                    const next = { ...prev };
                    delete next[itemId];
                    return next;
                });
            };

            // Dynamic category rendering helper
            const renderCatalogCategory = (catKey, catInfo, displayTitle, displayItems, customBadge) => {
                if (!catInfo) return null;
                const isCheckbox = (catInfo.type === 'checkbox');
                const items = displayItems || catInfo.items || [];
                if (!items || items.length === 0) return null;
                const title = displayTitle || catInfo.category_title || catKey;

                return (
                    <div key={catKey + '_' + title} className="catalog-category">
                        <div className="catalog-cat-header">
                            <div className="catalog-cat-title">
                                <i className={`fa-solid ${catInfo.icon || 'fa-circle-dot'}`}></i>
                                <span>{title}</span>
                            </div>
                            <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                                {catKey === 'catering' && (
                                    <span style={{ fontSize: '11px', background: '#dcfce7', color: '#15803d', padding: '3px 8px', borderRadius: '12px', fontWeight: 600 }}>
                                        <i className="fa-solid fa-users"></i> Sets Guest Limit
                                    </span>
                                )}
                                <span className={`badge-type ${isCheckbox ? 'badge-check' : 'badge-radio'}`}>
                                    {customBadge || (isCheckbox ? 'Pick Any' : 'Pick One')}
                                </span>
                            </div>
                        </div>

                        <div className="options-grid">
                            {items.map((item, idx) => {
                                const itemId = item.id || `${catKey}_${idx}`;
                                const isSelected = !!selectedItems[itemId];
                                const price = parseInt(item.price, 10) || 0;
                                const isCharacter = (catKey === 'character' || (catInfo.category_title && catInfo.category_title.toLowerCase().includes('character')));
                                return (
                                    <div 
                                        key={itemId}
                                        className={`option-choice-card ${isSelected ? 'selected' : ''}`}
                                        onClick={() => handleOptionToggle(catKey, catInfo, { ...item, id: itemId })}
                                    >
                                        <input 
                                            type={isCheckbox ? 'checkbox' : 'radio'}
                                            checked={isSelected}
                                            onChange={() => {}}
                                            name={`group_${catKey}`}
                                        />
                                        <span className="option-label-text">{item.label}</span>
                                        {price > 0 ? (
                                            <span className="option-price-tag">₱{price.toLocaleString()}</span>
                                        ) : (
                                            isCharacter ? null : (
                                                (catKey.toLowerCase().includes('addons') || (item.label && item.label.toLowerCase().includes('basic'))) ? (
                                                    <span className="option-price-tag">Included</span>
                                                ) : null
                                            )
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                );
            };

            // Dynamic package group content renderer
            const renderGroupContent = (group) => {
                if (!group) return null;

                if (group.isCateringOrganizer) {
                    // KIDS PARTY -> CATERING SERVICE: Organized into Pax, Food, Styling
                    const cateringCat = currentCatalog['catering'] || { category_title: 'Pax Capacity', icon: 'fa-users', type: 'radio', items: [] };
                    const addonsCat = currentCatalog['catering_addons'] || { category_title: 'Catering Inclusions & Add-ons', icon: 'fa-bowl-food', type: 'checkbox', items: [] };
                    const addonItems = addonsCat.items || [];

                    const paxItems = cateringCat.items || [];
                    const foodItems = addonItems.filter(it => (it.label || '').toLowerCase().includes('food') || (!(it.label || '').toLowerCase().includes('styling')));
                    const stylingItems = addonItems.filter(it => (it.label || '').toLowerCase().includes('styling'));
                    const otherItems = addonItems.filter(it => !foodItems.includes(it) && !stylingItems.includes(it));

                    return (
                        <div>
                            {/* 1. Pax Capacity */}
                            {renderCatalogCategory('catering', cateringCat, 'Pax Capacity', paxItems, 'Pick One • Sets Guest Limit')}
                            
                            {/* 2. Food Menu Options */}
                            {renderCatalogCategory('catering_addons', addonsCat, 'Food Menu Selection', foodItems, 'Pick Any • Included')}
                            
                            {/* 3. Catering Styling & Presentation */}
                            {renderCatalogCategory('catering_addons', addonsCat, 'Catering Presentation & Styling', stylingItems, 'Pick Any • Included')}

                            {/* 4. Other items if added by admin */}
                            {otherItems.length > 0 && renderCatalogCategory('catering_addons', addonsCat, 'Additional Inclusions', otherItems, 'Pick Any')}
                        </div>
                    );
                }

                // Standard group category list
                return (
                    <div>
                        {group.categories.map(catKey => {
                            const catInfo = currentCatalog[catKey];
                            if (!catInfo) return null;
                            const overrideTitle = (group.titleOverrides && group.titleOverrides[catKey]) || null;
                            return renderCatalogCategory(catKey, catInfo, overrideTitle);
                        })}
                    </div>
                );
            };

            // Real-time reactive price total (adds and subtracts automatically)
            const totalEstimate = useMemo(() => {
                return Object.values(selectedItems).reduce((sum, item) => sum + (item.price || 0), 0);
            }, [selectedItems]);

            // Field validation matching backend strict rules
            const validate = () => {
                const errs = {};
                const trimmedName = clientName.trim();
                // Support Unicode characters (e.g. Filipino names like Peña, Niño)
                if (!trimmedName || trimmedName.length < 2 || !/^[\p{L}\s\.\-']+$/u.test(trimmedName)) {
                    errs.clientName = 'Please enter a valid Full Name (letters and spaces only, at least 2 characters).';
                }
                const trimmedEmail = clientEmail.trim();
                if (!trimmedEmail || !/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/.test(trimmedEmail)) {
                    errs.clientEmail = 'Please enter a valid Email Address (e.g. name@gmail.com).';
                }
                const cleanPhone = clientPhone.replace(/[^0-9]/g, '');
                if (!cleanPhone || cleanPhone.length < 10 || cleanPhone.length > 11 || !/^[0-9]+$/.test(cleanPhone)) {
                    errs.clientPhone = 'Please enter a valid Contact Number with 10 to 11 numerical digits (e.g. 09171234567, numbers only).';
                }
                const trimmedTitle = eventTitle.trim();
                if (!trimmedTitle || trimmedTitle.length < 3) {
                    errs.eventTitle = 'Please enter a valid Event Title (at least 3 characters).';
                }
                const trimmedVenue = locationVenue.trim();
                if (!trimmedVenue || trimmedVenue.length < 3) {
                    errs.locationVenue = 'Please enter the Event Venue or Location.';
                }
                if (!eventDate || eventDate < minDateStr) {
                    errs.eventDate = 'Event date cannot be in the past. Please select today or a future date.';
                } else if (bookedDates.includes(eventDate)) {
                    const formatted = new Date(eventDate + 'T00:00:00').toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
                    errs.eventDate = `${formatted} is already fully booked. Please select another date.`;
                }
                const num = parseInt(guestCount, 10);
                if (guestCount === '' || isNaN(num) || num < 1) {
                    errs.guestCount = 'Please enter a valid Expected Guest Count (minimum 1 attendee).';
                } else if (selectedPackageLimit && num > selectedPackageLimit) {
                    errs.guestCount = `Cannot proceed: Expected guest count (${num}) exceeds your selected Catering Service package limit (${selectedPackageLimit} pax). Please adjust your guest count or choose a higher catering package below.`;
                } else if (!selectedPackageLimit && num > maxCatalogCateringPax) {
                    errs.guestCount = `Cannot proceed: Expected guest count (${num}) exceeds our maximum catering service capacity (${maxCatalogCateringPax} pax). Please adjust your guest count or select an available package.`;
                }
                // Data Privacy Consent is now enforced via upfront gate modal (RA 10173)
                // privacyConsent is guaranteed true once the user passes the gate
                setErrors(errs);
                return Object.keys(errs).length === 0;
            };

            // Intercept submit to show Email Warning & Typo Check Modal
            const handleSubmit = async (e) => {
                if (e && e.preventDefault) e.preventDefault();
                setSubmitError('');

                // 1. Mandatory Double-Booking Pre-Check: Block verification if date is already confirmed
                if (eventDate) {
                    if (bookedDates.includes(eventDate)) {
                        triggerDoubleBookingModal(eventDate);
                        return;
                    }

                    // Live pre-flight check to catch real-time double bookings before verification
                    try {
                        const checkRes = await fetch(`booking_availability.php?date=${encodeURIComponent(eventDate)}`);
                        if (checkRes.ok) {
                            const checkData = await checkRes.json();
                            if (checkData.success && checkData.available === false) {
                                if (!bookedDates.includes(eventDate)) {
                                    setBookedDates(prev => [...prev, eventDate]);
                                }
                                triggerDoubleBookingModal(eventDate, checkData.message);
                                return;
                            }
                        }
                    } catch (e) {}
                }

                // 2. If guest count exceeds catering capacity limit, immediately focus and alert user
                if (isGuestExceeded) {
                    const limitMsg = selectedPackageLimit 
                        ? `Cannot proceed: Expected guest count (${numGuests}) exceeds your selected catering service package limit (${selectedPackageLimit} pax). Please adjust your guest count or choose a higher catering package.`
                        : `Cannot proceed: Expected guest count (${numGuests}) exceeds our maximum catering service capacity (${maxCatalogCateringPax} pax). Please adjust your guest count or choose an available catering package.`;
                    setSubmitError(limitMsg);
                    const guestEl = document.getElementById('guest_count_input');
                    if (guestEl) {
                        guestEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        guestEl.focus();
                    }
                    return;
                }

                // 3. Validate all required form fields
                const isValid = validate();
                if (!isValid) {
                    // If error is double booking on date, trigger modal immediately
                    if (errors.eventDate && bookedDates.includes(eventDate)) {
                        triggerDoubleBookingModal(eventDate);
                        return;
                    }

                    setSubmitError('Cannot proceed: Please fill in all required fields highlighted in red below.');
                    
                    // Priority list to smoothly scroll and focus the very first invalid field
                    const fieldOrder = [
                        { key: 'clientName', id: 'client_name_input' },
                        { key: 'clientEmail', id: 'client_email_input' },
                        { key: 'clientPhone', id: 'client_phone_input' },
                        { key: 'eventTitle', id: 'event_title_input' },
                        { key: 'eventDate', id: 'event_date_input' },
                        { key: 'guestCount', id: 'guest_count_input' },
                        { key: 'locationVenue', id: 'location_venue_input' },
                        // privacyConsent removed: enforced via upfront gate modal
                    ];

                    setTimeout(() => {
                        for (const item of fieldOrder) {
                            const el = document.getElementById(item.id);
                            if (el && (el.classList.contains('is-invalid') || errors[item.key])) {
                                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                el.focus();
                                break;
                            }
                        }
                    }, 50);
                    return;
                }

                // 4. Prevent typo misconception: Check for common domain typos
                const typo = detectEmailIssues(clientEmail);

                // Open Email Confirmation & Misconception Prevention Modal before dispatching code
                setEmailWarningModal({
                    isOpen: true,
                    email: clientEmail.trim(),
                    typoWarning: typo
                });
            };

            // Resolve schedule conflict by dismissing reminder and focusing event date input
            const handleResolveConflictChangeDate = () => {
                setConflictModal({
                    isOpen: false,
                    eventDate: '',
                    eventTime: '',
                    friendlyDate: '',
                    friendlyTime: '',
                    message: ''
                });
                setErrors(prev => ({
                    ...prev,
                    eventDate: 'Please select an open calendar date to proceed with email verification.'
                }));
                setTimeout(() => {
                    const dateInput = document.getElementById('event_date_input');
                    if (dateInput) {
                        dateInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        dateInput.focus();
                    }
                }, 100);
            };

            const handleProceedWithEmail = () => {
                setEmailWarningModal({ isOpen: false, email: '', typoWarning: null });
                if (eventDate && bookedDates.includes(eventDate)) {
                    triggerDoubleBookingModal(eventDate);
                    return;
                }
                executeBookingSubmit();
            };

            // Execute actual booking submission & OTP code dispatch
            const executeBookingSubmit = async () => {
                setIsSubmitting(true);
                setSubmitError('');

                try {
                    const itemsArr = Object.values(selectedItems);
                    let reqStr = itemsArr.map(it => `${it.catTitle}: ${it.label}${it.price > 0 ? ` (₱${it.price.toLocaleString()})` : ''}`).join('; ');
                    if (!reqStr) {
                        reqStr = 'Standard Consultation & Custom Event Styling';
                    }
                    reqStr += ` | ESTIMATED TOTAL: ₱${totalEstimate.toLocaleString()}`;

                    const formData = new FormData();
                    formData.append('csrf_token', config.csrfToken || '');
                    formData.append('client_name', clientName.trim());
                    formData.append('client_email', clientEmail.trim());
                    formData.append('client_phone', clientPhone.trim());
                    formData.append('client_address', clientAddress.trim());
                    formData.append('event_title', eventTitle.trim());
                    formData.append('event_type', eventType);
                    formData.append('event_date', eventDate);
                    formData.append('event_time', eventTime);
                    formData.append('guest_count', parseInt(guestCount, 10) || 10);
                    formData.append('location_venue', locationVenue.trim());
                    formData.append('service_requirements', reqStr);
                    formData.append('estimated_total', totalEstimate);
                    formData.append('special_notes', specialNotes.trim());
                    formData.append('privacy_consent', privacyConsent ? '1' : '0');
                    formData.append('privacy_consent_at', new Date().toISOString());

                    // Capture Cloudflare Turnstile token if present on page
                    const mainTsEl = document.querySelector('#mainBookingTurnstile input[name="cf-turnstile-response"]') ||
                                     document.querySelector('input[name="cf-turnstile-response"]');
                    let turnstileToken = mainTsEl ? mainTsEl.value : '';
                    if (!turnstileToken && window.turnstile && mainTurnstileWidgetId.current !== null) {
                        try {
                            turnstileToken = window.turnstile.getResponse(mainTurnstileWidgetId.current) || '';
                        } catch (e) {}
                    }

                    if (turnstileToken) {
                        formData.append('cf-turnstile-response', turnstileToken);
                        formData.append('turnstile_token', turnstileToken);
                    } else if (config.turnstileEnabled && config.turnstileSiteKey) {
                        setSubmitError('Please complete the Cloudflare security verification before submitting your booking request.');
                        setIsSubmitting(false);
                        const tsEl = document.getElementById('mainBookingTurnstile');
                        if (tsEl) tsEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        return;
                    }

                    const res = await fetch('booking_submit.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await res.json();

                    if (data.success) {
                        if (data.requires_verification) {
                            const isSent = (data.email_sent === true);
                            // Intercept direct commit: Show Two-Step Verification Modal
                            setVerifyModal({
                                isOpen: true,
                                email: data.email || clientEmail.trim(),
                                maskedEmail: data.masked_email || clientEmail.trim(),
                                token: data.verification_token || '',
                                otpCode: '',
                                error: isSent ? '' : (data.email_error || data.message || 'Verification email could not be delivered to this address.'),
                                isLoading: false,
                                resendCooldown: isSent ? 60 : 0, // No cooldown penalty if send failed
                                resendSuccess: isSent ? (data.message || '') : '',
                                isVerified: false,
                                emailSent: isSent,
                                expiresIn: data.expires_in || 300,
                                isEditingEmail: !isSent, // Prompt user to correct email immediately if delivery failed
                                newEmailInput: isSent ? '' : (data.email || clientEmail.trim()),
                                isUpdatingEmail: false,
                                emailUpdateError: ''
                            });
                        } else {
                            setConfirmedBooking({
                                refNo: data.reference_no,
                                title: data.event_title || eventTitle,
                                client: data.client_name || clientName,
                                date: data.event_date || eventDate
                            });
                        }
                    } else {
                        resetTurnstileWidget(mainTurnstileWidgetId);
                        if (data.is_conflict || data.conflict) {
                            triggerDoubleBookingModal(data.event_date || eventDate, data.message);
                        } else {
                            setSubmitError(data.message || 'Submission failed. Please check your inputs.');
                        }
                    }
                } catch (err) {
                    resetTurnstileWidget(mainTurnstileWidgetId);
                    setSubmitError('Connection or network error while saving your booking. Please try again.');
                } finally {
                    setIsSubmitting(false);
                }
            };

            // Submit OTP code for backend verification and database commit
            const handleVerifyCodeSubmit = async (e) => {
                if (e && e.preventDefault) e.preventDefault();
                if (!verifyModal.otpCode || verifyModal.otpCode.length !== 6) {
                    setVerifyModal(prev => ({ ...prev, error: 'Please enter the complete 6-digit verification code sent to your email.' }));
                    return;
                }

                setVerifyModal(prev => ({ ...prev, isLoading: true, error: '', resendSuccess: '' }));

                try {
                    const formData = new FormData();
                    formData.append('action', 'verify');
                    formData.append('code', verifyModal.otpCode.trim());
                    formData.append('verification_token', verifyModal.token || '');
                    formData.append('csrf_token', config.csrfToken || '');

                    // Capture Cloudflare Turnstile token from verify modal
                    const modalTsEl = document.querySelector('#verifyModalTurnstile input[name="cf-turnstile-response"]') ||
                                      document.querySelector('input[name="cf-turnstile-response"]');
                    if (modalTsEl && modalTsEl.value) {
                        formData.append('cf-turnstile-response', modalTsEl.value);
                        formData.append('turnstile_token', modalTsEl.value);
                    }

                    const res = await fetch('booking_submit.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await res.json();

                    if (data.success && data.verified) {
                        setVerifyModal(prev => ({
                            ...prev,
                            isOpen: false,
                            isLoading: false,
                            isVerified: true
                        }));
                        setConfirmedBooking({
                            refNo: data.reference_no,
                            title: data.event_title || eventTitle,
                            client: data.client_name || clientName,
                            date: data.event_date || eventDate
                        });
                    } else {
                        resetTurnstileWidget(modalTurnstileWidgetId);
                        if (data.is_conflict || data.conflict || data.code === 'DATE_CONFLICT') {
                            setVerifyModal(prev => ({ ...prev, isOpen: false, isLoading: false }));
                            triggerDoubleBookingModal(data.event_date || eventDate, data.message);
                        } else {
                            setVerifyModal(prev => ({
                                ...prev,
                                isLoading: false,
                                error: data.message || 'The verification code entered is incorrect or expired.'
                            }));
                        }
                    }
                } catch (err) {
                    resetTurnstileWidget(modalTurnstileWidgetId);
                    setVerifyModal(prev => ({
                        ...prev,
                        isLoading: false,
                        error: 'Connection error while verifying your code. Please try again.'
                    }));
                }
            };

            // Resend fresh OTP verification code with cooldown rate limit
            const handleResendCode = async () => {
                if (verifyModal.resendCooldown > 0 || verifyModal.isLoading) return;

                resetTurnstileWidget(modalTurnstileWidgetId);
                setVerifyModal(prev => ({ ...prev, isLoading: true, error: '', resendSuccess: '' }));

                try {
                    const formData = new FormData();
                    formData.append('action', 'resend');
                    formData.append('verification_token', verifyModal.token || '');
                    formData.append('csrf_token', config.csrfToken || '');

                    const res = await fetch('booking_submit.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await res.json();

                    if (data.success) {
                        setVerifyModal(prev => ({
                            ...prev,
                            isLoading: false,
                            otpCode: '',
                            error: '',
                            emailSent: true,
                            resendSuccess: data.message || `A new 6-digit code has been sent to ${data.masked_email || prev.maskedEmail}.`,
                            resendCooldown: 60,
                            expiresIn: data.expires_in || 300
                        }));
                    } else {
                        setVerifyModal(prev => ({
                            ...prev,
                            isLoading: false,
                            emailSent: false,
                            resendCooldown: 0, // allow immediate retry on failure
                            error: data.message || 'Unable to resend verification code. Please check your email or connection.'
                        }));
                    }
                } catch (err) {
                    setVerifyModal(prev => ({
                        ...prev,
                        isLoading: false,
                        resendCooldown: 0,
                        error: 'Connection error while requesting new code. Please try again.'
                    }));
                }
            };

            // In-modal email update & resend handler (recovers from missing letter or mistyped emails)
            const handleUpdateEmailAndResend = async () => {
                const newEmail = (verifyModal.newEmailInput || '').trim();
                if (!newEmail || !/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/.test(newEmail)) {
                    setVerifyModal(prev => ({
                        ...prev,
                        emailUpdateError: 'Please enter a valid email address (e.g. name@gmail.com).'
                    }));
                    return;
                }

                setVerifyModal(prev => ({ ...prev, isUpdatingEmail: true, emailUpdateError: '', error: '' }));

                try {
                    const formData = new FormData();
                    formData.append('action', 'update_email');
                    formData.append('new_email', newEmail);
                    formData.append('verification_token', verifyModal.token || '');
                    formData.append('csrf_token', config.csrfToken || '');

                    const res = await fetch('booking_submit.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await res.json();

                    if (data.success) {
                        setClientEmail(newEmail); // update client email state in form as well
                        setVerifyModal(prev => ({
                            ...prev,
                            isUpdatingEmail: false,
                            isEditingEmail: false,
                            newEmailInput: '',
                            email: data.email || newEmail,
                            maskedEmail: data.masked_email || newEmail,
                            otpCode: '',
                            error: '',
                            emailSent: true,
                            resendSuccess: `Verification code successfully dispatched to: ${data.email || newEmail}`,
                            resendCooldown: 60,
                            expiresIn: data.expires_in || 300
                        }));
                    } else {
                        setVerifyModal(prev => ({
                            ...prev,
                            isUpdatingEmail: false,
                            emailUpdateError: data.message || 'Unable to update email address. Please try again.'
                        }));
                    }
                } catch (err) {
                    setVerifyModal(prev => ({
                        ...prev,
                        isUpdatingEmail: false,
                        emailUpdateError: 'Network error while updating email. Please check your connection.'
                    }));
                }
            };

            // Reset form for submitting another booking
            const handleReset = () => {
                setConfirmedBooking(null);
                setClientName('');
                setClientEmail('');
                setClientPhone('');
                setClientAddress('');
                setSelectedRegion('');
                setSelectedCity('');
                setSelectedBarangay('');
                setStreetDetail('');
                setIsManualAddress(false);
                setEventTitle('');
                setGuestCount('100');
                setEventTime('14:00');
                setLocationVenue('');
                setSpecialNotes('');
                setSelectedItems({});
                setErrors({});
                setSubmitError('');
                // privacyConsent stays true after gate acceptance (no need to re-consent per form reset)
                if (!config.isTypeLocked) {
                    handleSwitchEventType('Kids Party');
                } else {
                    setSelectedItems({});
                }
                window.scrollTo({ top: 0, behavior: 'smooth' });
            };

            const selectedItemsArray = Object.values(selectedItems);

            return (
                <div className="booking-container">
                    <form onSubmit={handleSubmit} noValidate style={!privacyAccepted ? { pointerEvents: 'none', filter: 'blur(3px)', userSelect: 'none', opacity: 0.65 } : {}}>
                        <div className="booking-grid">
                            
                            {/* Left Column: Form Steps */}
                            <div className="booking-left-column">
                                
                                {/* STEP 1: CLIENT INFORMATION */}
                                <div className="form-card">
                                    <div className="step-header">
                                        <div className="step-num">1</div>
                                        <div>
                                            <h2>Client Contact Details</h2>
                                            <p>Provide your contact information so our coordination team can reach you</p>
                                        </div>
                                    </div>

                                    <div className="form-group">
                                        <label>Full Name <span className="required">*</span></label>
                                        <input 
                                            type="text" 
                                            id="client_name_input"
                                            className={`form-control ${errors.clientName ? 'is-invalid' : ''}`}
                                            placeholder="e.g. Maria Santos"
                                            value={clientName}
                                            onChange={(e) => {
                                                setClientName(e.target.value);
                                                if (errors.clientName) setErrors(prev => ({ ...prev, clientName: null }));
                                            }}
                                            required
                                            autoFocus
                                        />
                                        {errors.clientName && (
                                            <div className="field-error-text" style={{ display: 'block' }}>{errors.clientName}</div>
                                        )}
                                    </div>

                                    <div className="form-row-2">
                                        <div className="form-group">
                                            <label>Email Address <span className="required">*</span></label>
                                            <input 
                                                type="email" 
                                                id="client_email_input"
                                                className={`form-control ${errors.clientEmail ? 'is-invalid' : ''}`}
                                                placeholder="e.g. maria.santos@gmail.com"
                                                value={clientEmail}
                                                onChange={(e) => {
                                                    setClientEmail(e.target.value);
                                                    if (errors.clientEmail) setErrors(prev => ({ ...prev, clientEmail: null }));
                                                }}
                                                required
                                            />
                                            {errors.clientEmail && (
                                                <div className="field-error-text" style={{ display: 'block' }}>{errors.clientEmail}</div>
                                            )}
                                        </div>
                                        <div className="form-group">
                                            <label>Mobile / Contact Number <span className="required">*</span></label>
                                            <input 
                                                type="tel" 
                                                id="client_phone_input"
                                                className={`form-control ${errors.clientPhone ? 'is-invalid' : ''}`}
                                                placeholder="e.g. 09171234567"
                                                value={clientPhone}
                                                inputMode="numeric"
                                                pattern="[0-9]*"
                                                maxLength={11}
                                                onKeyPress={(e) => {
                                                    if (!/[0-9]/.test(e.key)) {
                                                        e.preventDefault();
                                                    }
                                                }}
                                                onChange={(e) => {
                                                    const numericOnly = e.target.value.replace(/[^0-9]/g, '').slice(0, 11);
                                                    setClientPhone(numericOnly);
                                                    if (errors.clientPhone) setErrors(prev => ({ ...prev, clientPhone: null }));
                                                }}
                                                required
                                            />
                                            {errors.clientPhone && (
                                                <div className="field-error-text" style={{ display: 'block' }}>{errors.clientPhone}</div>
                                            )}
                                        </div>
                                    </div>

                                    <div className="form-group" style={{ marginBottom: 0 }}>
                                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px', flexWrap: 'wrap', gap: '6px' }}>
                                            <label style={{ margin: 0, fontWeight: 700 }}>Client / Event Address</label>
                                            <button 
                                                type="button" 
                                                className="address-toggle-btn"
                                                onClick={() => setIsManualAddress(prev => !prev)}
                                            >
                                                <i className={`fa-solid ${isManualAddress ? 'fa-list-check' : 'fa-pen-to-square'}`}></i>
                                                <span>{isManualAddress ? 'Use Address Selector' : 'Type Manually'}</span>
                                            </button>
                                        </div>

                                        {!isManualAddress ? (
                                            <div className="address-picker-card">
                                                <div className="address-select-grid">
                                                    <div className="address-select-group">
                                                        <label>Region</label>
                                                        <select 
                                                            className="form-control"
                                                            value={selectedRegion}
                                                            onChange={(e) => setSelectedRegion(e.target.value)}
                                                        >
                                                            <option value="">-- Select Region --</option>
                                                            {regionsList.map(r => (
                                                                <option key={r.code} value={r.code}>{r.name}</option>
                                                            ))}
                                                        </select>
                                                    </div>
                                                    <div className="address-select-group">
                                                        <label>City / Municipality</label>
                                                        <select 
                                                            className="form-control"
                                                            value={selectedCity}
                                                            onChange={(e) => setSelectedCity(e.target.value)}
                                                            disabled={!selectedRegion || addressLoading}
                                                        >
                                                            <option value="">
                                                                {addressLoading && selectedRegion && !selectedCity ? 'Loading cities...' : '-- Select City / Municipality --'}
                                                            </option>
                                                            {citiesList.map(c => (
                                                                <option key={c.code} value={c.code}>{c.name}</option>
                                                            ))}
                                                        </select>
                                                    </div>
                                                    <div className="address-select-group">
                                                        <label>Barangay</label>
                                                        <select 
                                                            className="form-control"
                                                            value={selectedBarangay}
                                                            onChange={(e) => setSelectedBarangay(e.target.value)}
                                                            disabled={!selectedCity || addressLoading}
                                                        >
                                                            <option value="">
                                                                {addressLoading && selectedCity && !selectedBarangay ? 'Loading barangays...' : '-- Select Barangay --'}
                                                            </option>
                                                            {barangaysList.map(b => (
                                                                <option key={b.code} value={b.code}>{b.name}</option>
                                                            ))}
                                                        </select>
                                                    </div>
                                                </div>

                                                <div className="form-group" style={{ marginBottom: 0 }}>
                                                    <label style={{ fontSize: '11px', fontWeight: 700, color: '#64748b', textTransform: 'uppercase' }}>
                                                        Street / Unit / House No. / Landmark
                                                    </label>
                                                    <input 
                                                        type="text" 
                                                        className="form-control"
                                                        placeholder="e.g. Unit 402, 123 Emerald Ave"
                                                        value={streetDetail}
                                                        onChange={(e) => setStreetDetail(e.target.value)}
                                                    />
                                                </div>

                                                {clientAddress && (
                                                    <div className="address-preview-box">
                                                        <i className="fa-solid fa-location-dot"></i>
                                                        <span><strong>Formatted Address:</strong> {clientAddress}</span>
                                                    </div>
                                                )}
                                            </div>
                                        ) : (
                                            <div>
                                                <input 
                                                    type="text" 
                                                    className="form-control"
                                                    placeholder="e.g. 123 Emerald Ave, San Antonio, Pasig City, Metro Manila"
                                                    value={clientAddress}
                                                    onChange={(e) => setClientAddress(e.target.value)}
                                                />
                                                <p style={{ fontSize: '11.5px', color: '#64748b', margin: '6px 0 0 0' }}>
                                                    <i className="fa-solid fa-circle-info"></i> Manual mode enabled. You may switch back to the Philippine Address Selector anytime.
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                </div>

                                {/* STEP 2: EVENT DETAILS */}
                                <div className="form-card">
                                    <div className="step-header">
                                        <div className="step-num">2</div>
                                        <div>
                                            <h2>Event Specifics &amp; Schedule</h2>
                                            <p>Tell us about the occasion, venue, and required schedule</p>
                                        </div>
                                    </div>

                                    {/* Celebration Type Selector */}
                                    <div className="form-group">
                                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '8px', flexWrap: 'wrap', gap: '6px' }}>
                                            <label style={{ margin: 0 }}>Choose Your Celebration Type <span className="required">*</span></label>
                                            {isTypeLocked && (
                                                <span className="locked-badge-pill">
                                                    <i className="fa-solid fa-lock"></i> 
                                                    {config.isTypeLocked ? 'Selected from Services (Locked)' : 'Locked to Selected Package'}
                                                </span>
                                            )}
                                        </div>
                                        <div className="event-choice-selector">
                                            <div 
                                                className={`event-type-card ${!isWedding ? 'active' : (isTypeLocked ? 'disabled-locked' : '')}`}
                                                onClick={() => handleSelectEventType('Kids Party')}
                                                title={isTypeLocked && isWedding ? "Event type is locked to your selected service" : "Click to select Kids Party and open package options"}
                                            >
                                                <div className="event-type-icon" style={{ background: '#fef3c7', color: '#d97706' }}>
                                                    <i className="fa-solid fa-cake-candles"></i>
                                                </div>
                                                <div className="event-type-info">
                                                    <div className="event-type-name">Kids Party &amp; Birthdays</div>
                                                    <div className="event-type-meta">Themed Styling, Characters, Food Carts &bull; <strong style={{ color: '#15803d' }}>2 Weeks Preparation Reminder</strong></div>
                                                </div>
                                                <div className="event-type-check">
                                                    {!isWedding ? (
                                                        <i className="fa-solid fa-circle-check"></i>
                                                    ) : (
                                                        isTypeLocked ? <i className="fa-solid fa-lock" style={{ color: '#9ca3af' }}></i> : null
                                                    )}
                                                </div>
                                            </div>

                                            <div 
                                                className={`event-type-card ${isWedding ? 'active' : (isTypeLocked ? 'disabled-locked' : '')}`}
                                                onClick={() => handleSelectEventType('Weddings')}
                                                title={isTypeLocked && !isWedding ? "Event type is locked to your selected service" : "Click to select Weddings and open package options"}
                                            >
                                                <div className="event-type-icon" style={{ background: '#fdf2f8', color: '#db2777' }}>
                                                    <i className="fa-solid fa-rings-wedding"></i>
                                                </div>
                                                <div className="event-type-info">
                                                    <div className="event-type-name">Weddings &amp; Milestones</div>
                                                    <div className="event-type-meta">Ceremony Floral, Reception Styling, Coordination &bull; <strong style={{ color: '#b45309' }}>3 Months Preparation Reminder</strong></div>
                                                </div>
                                                <div className="event-type-check">
                                                    {isWedding ? (
                                                        <i className="fa-solid fa-circle-check"></i>
                                                    ) : (
                                                        isTypeLocked ? <i className="fa-solid fa-lock" style={{ color: '#9ca3af' }}></i> : null
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="form-group">
                                        <label>Event Title / Occasion Name <span className="required">*</span></label>
                                        <input 
                                            type="text" 
                                            id="event_title_input"
                                            className={`form-control ${errors.eventTitle ? 'is-invalid' : ''}`}
                                            placeholder="e.g. Santos &amp; Reyes Wedding Reception / Liam 7th Kids Party"
                                            value={eventTitle}
                                            onChange={(e) => {
                                                setEventTitle(e.target.value);
                                                if (errors.eventTitle) setErrors(prev => ({ ...prev, eventTitle: null }));
                                            }}
                                            required
                                        />
                                        {errors.eventTitle && (
                                            <div className="field-error-text" style={{ display: 'block' }}>{errors.eventTitle}</div>
                                        )}
                                    </div>

                                    <div className="form-row-2">
                                        <div className="form-group" style={{ gridColumn: 'span 2' }}>
                                            <label style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: '6px' }}>
                                                <span>Event Target Date <span className="required">*</span></span>
                                                <span style={{ fontSize: '12px', fontWeight: 600, color: 'var(--primary)' }}>
                                                    <i className="fa-solid fa-calendar-check" style={{ marginRight: '4px' }}></i>
                                                    1 Confirmed Event Per Day Policy
                                                </span>
                                            </label>
                                            <input 
                                                type="date" 
                                                id="event_date_input"
                                                className={`form-control ${errors.eventDate ? 'is-invalid' : ''}`}
                                                min={minDateStr}
                                                value={eventDate}
                                                onChange={(e) => {
                                                    const val = e.target.value;
                                                    setEventDate(val);
                                                    if (val && val < minDateStr) {
                                                        setErrors(prev => ({ ...prev, eventDate: 'Event date cannot be in the past. Please select today or a future date.' }));
                                                    } else if (val && bookedDates.includes(val)) {
                                                        triggerDoubleBookingModal(val);
                                                    } else {
                                                        setErrors(prev => ({ ...prev, eventDate: null }));
                                                    }
                                                }}
                                                required
                                            />

                                            {/* Live Database-Driven Availability Calendar */}
                                            <div className="avail-calendar-card">
                                                <div className="avail-calendar-header">
                                                    <button 
                                                        type="button" 
                                                        className="avail-nav-btn" 
                                                        onClick={handlePrevMonth}
                                                        title="Previous Month"
                                                    >
                                                        <i className="fa-solid fa-chevron-left"></i>
                                                    </button>
                                                    <div className="avail-calendar-title">
                                                        <i className="fa-solid fa-calendar-days"></i>
                                                        <span>{calendarMonthName}</span>
                                                    </div>
                                                    <button 
                                                        type="button" 
                                                        className="avail-nav-btn" 
                                                        onClick={handleNextMonth}
                                                        title="Next Month"
                                                    >
                                                        <i className="fa-solid fa-chevron-right"></i>
                                                    </button>
                                                </div>

                                                {isAvailabilityLoading ? (
                                                    <div style={{ textAlign: 'center', padding: '20px 12px', color: 'var(--text-muted)', fontSize: '13px' }}>
                                                        <i className="fa-solid fa-spinner fa-spin" style={{ marginRight: '8px', color: 'var(--primary)' }}></i>
                                                        Checking real-time calendar availability...
                                                    </div>
                                                ) : availabilityError ? (
                                                    <div style={{ textAlign: 'center', padding: '14px 12px', color: '#b91c1c', background: '#fef2f2', borderRadius: '8px', fontSize: '12.5px' }}>
                                                        <div><i className="fa-solid fa-triangle-exclamation" style={{ marginRight: '6px' }}></i>{availabilityError}</div>
                                                        <button type="button" onClick={fetchAvailability} style={{ marginTop: '8px', padding: '4px 12px', fontSize: '11.5px', background: 'var(--primary)', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>
                                                            <i className="fa-solid fa-rotate-right" style={{ marginRight: '4px' }}></i> Retry
                                                        </button>
                                                    </div>
                                                ) : (
                                                    <>
                                                        <div className="avail-weekdays">
                                                            {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(d => (
                                                                <div key={d} className="avail-weekday-label">{d}</div>
                                                            ))}
                                                        </div>
                                                        <div className="avail-days-grid">
                                                            {renderCalendarDays()}
                                                        </div>
                                                        <div className="avail-legend">
                                                            <div className="avail-legend-item">
                                                                <span className="avail-legend-dot available"></span>
                                                                <span>Available</span>
                                                            </div>
                                                            <div className="avail-legend-item">
                                                                <span className="avail-legend-dot booked"></span>
                                                                <span>Fully Booked</span>
                                                            </div>
                                                            <div className="avail-legend-item">
                                                                <span className="avail-legend-dot selected"></span>
                                                                <span>Selected</span>
                                                            </div>
                                                            <div className="avail-legend-item">
                                                                <span className="avail-legend-dot past"></span>
                                                                <span>Past</span>
                                                            </div>
                                                        </div>
                                                    </>
                                                )}
                                            </div>

                                            <div className={`lead-time-notice ${isWedding ? 'wedding-notice' : 'kids-notice'}`}>
                                                <i className={`fa-solid ${isWedding ? 'fa-rings-wedding' : 'fa-clock-rotate-left'}`}></i>
                                                <span>
                                                    <strong>{isWedding ? '3-Month Preparation Reminder' : '2-Week Preparation Reminder'}</strong>: {isWedding ? 'We recommend at least 3 months preparation time to ensure seamless execution for weddings. You are free to choose any date from today onwards.' : 'We recommend at least 2 weeks preparation time to ensure seamless execution for kids parties & birthdays. You are free to choose any date from today onwards.'}
                                                </span>
                                            </div>
                                            {errors.eventDate && (
                                                <div className="date-error-badge" style={{ display: 'flex', marginTop: '8px' }}>
                                                    <i className="fa-solid fa-triangle-exclamation"></i>
                                                    <span>{errors.eventDate}</span>
                                                </div>
                                            )}
                                        </div>
                                        <div className="form-group">
                                            <label>Target Start Time</label>
                                            <input 
                                                type="time" 
                                                className="form-control"
                                                value={eventTime}
                                                onChange={(e) => setEventTime(e.target.value)}
                                            />
                                        </div>
                                    </div>

                                    <div className="form-row-2">
                                        <div className="form-group">
                                            <label style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: '4px' }}>
                                                <span>Expected Guest Count <span className="required">*</span></span>
                                                {totalGuestLimit ? (
                                                    <span style={{ 
                                                        fontSize: '11px', 
                                                        color: isGuestExceeded ? '#dc2626' : '#15803d', 
                                                        background: isGuestExceeded ? '#fee2e2' : '#dcfce7', 
                                                        border: isGuestExceeded ? '1px solid #fca5a5' : '1px solid transparent',
                                                        padding: '2px 8px', 
                                                        borderRadius: '12px', 
                                                        fontWeight: 700 
                                                    }}>
                                                        <i className={`fa-solid ${isGuestExceeded ? 'fa-triangle-exclamation' : 'fa-users'}`}></i> {selectedPackageLimit ? `Package Limit: ${selectedPackageLimit} pax` : `Max Capacity: ${maxCatalogCateringPax} pax`}
                                                    </span>
                                                ) : null}
                                            </label>
                                            <input 
                                                type="text" 
                                                inputMode="numeric"
                                                pattern="[0-9]*"
                                                id="guest_count_input"
                                                className={`form-control ${isGuestExceeded || errors.guestCount ? 'is-invalid' : ''}`}
                                                value={guestCount}
                                                placeholder="e.g. 100"
                                                onChange={handleGuestCountChange}
                                                required
                                            />
                                            {isGuestExceeded ? (
                                                <div style={{
                                                    marginTop: '8px',
                                                    background: '#fff1f2',
                                                    border: '1.5px solid #f43f5e',
                                                    borderRadius: '8px',
                                                    padding: '10px 14px',
                                                    color: '#9f1239',
                                                    fontSize: '12.5px',
                                                    display: 'flex',
                                                    alignItems: 'flex-start',
                                                    gap: '10px',
                                                    boxShadow: '0 2px 6px rgba(244, 63, 94, 0.12)'
                                                }}>
                                                    <i className="fa-solid fa-triangle-exclamation" style={{ color: '#e11d48', fontSize: '16px', marginTop: '2px', flexShrink: 0 }}></i>
                                                    <div>
                                                        <strong style={{ display: 'block', marginBottom: '2px', fontSize: '13px' }}>
                                                            {selectedPackageLimit 
                                                                ? `Catering Package Limit Exceeded (${numGuests} / ${selectedPackageLimit} pax)`
                                                                : `Catering Maximum Capacity Exceeded (${numGuests} / ${maxCatalogCateringPax} pax)`}
                                                        </strong>
                                                        {selectedPackageLimit ? (
                                                            <span>Your expected guest count (<strong>{numGuests} guests</strong>) exceeds your selected Catering Service package limit (<strong>{selectedPackageLimit} pax</strong>).</span>
                                                        ) : (
                                                            <span>Your expected guest count (<strong>{numGuests} guests</strong>) exceeds our maximum catering service capacity (<strong>{maxCatalogCateringPax} pax</strong>).</span>
                                                        )}
                                                        <div style={{ color: '#be123c', fontWeight: 700, marginTop: '4px' }}>
                                                            <i className="fa-solid fa-ban"></i> {selectedPackageLimit 
                                                                ? 'You cannot proceed with this booking unless you adjust the guest count or choose a higher catering package below.'
                                                                : 'You cannot proceed with this booking unless you reduce the guest count to match available catering packages.'}
                                                        </div>
                                                    </div>
                                                </div>
                                            ) : errors.guestCount ? (
                                                <div className="field-error-text" style={{ display: 'block' }}>{errors.guestCount}</div>
                                            ) : (
                                                <small style={{ color: '#6b7280', fontSize: '11px', display: 'flex', alignItems: 'center', gap: '4px', marginTop: '4px' }}>
                                                    <i className="fa-solid fa-utensils" style={{ color: totalGuestLimit ? '#15803d' : '#9ca3af' }}></i>
                                                    {selectedPackageLimit ? (
                                                        <span>Limit synced with selected Catering Service (<strong>{selectedPackageLimit} pax max</strong>).</span>
                                                    ) : (
                                                        <span>Catering service capacity up to <strong>{maxCatalogCateringPax} pax max</strong> (select a catering package below).</span>
                                                    )}
                                                </small>
                                            )}
                                        </div>
                                        <div className="form-group" style={{ marginBottom: 0 }}>
                                            <label>Venue / Location Address <span className="required">*</span></label>
                                            <input 
                                                type="text" 
                                                id="location_venue_input"
                                                className={`form-control ${errors.locationVenue ? 'is-invalid' : ''}`}
                                                placeholder="e.g. Grand Peninsula Ballroom, Makati / Emerald Courtyard"
                                                value={locationVenue}
                                                onChange={(e) => {
                                                    setLocationVenue(e.target.value);
                                                    if (errors.locationVenue) setErrors(prev => ({ ...prev, locationVenue: null }));
                                                }}
                                                required
                                            />
                                            {errors.locationVenue && (
                                                <div className="field-error-text" style={{ display: 'block' }}>{errors.locationVenue}</div>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                {/* STEP 3: PACKAGES & EQUIPMENT SELECTION */}
                                <div className="form-card">
                                    <div className="step-header">
                                        <div className="step-num">3</div>
                                        <div>
                                            <h2>Package &amp; Equipment Customization</h2>
                                            <p>Select packages, setup options, and equipment add-ons (live pricing updates automatically)</p>
                                        </div>
                                    </div>


                                    {/* Optional Banner Requirement */}
                                    <div className="pkg-optional-banner" style={{ margin: '0 0 16px 0' }}>
                                        <i className="fa-solid fa-circle-info"></i>
                                        <div>
                                            <strong>Choose the services you want. You may skip any section.</strong> All package selections and add-ons are completely optional.
                                        </div>
                                    </div>

                                    {/* STATE 1: If no category chosen yet, show only the 3 choices (no service options) */}
                                    {!activeGroup ? (
                                        <div className="pkg-selection-prompt-wrapper">
                                            <div className="pkg-prompt-title-row">
                                                <span className="pkg-prompt-step-tag">
                                                    <i className="fa-solid fa-layer-group"></i> Package Selection
                                                </span>
                                                <h3 className="pkg-prompt-title">Choose Your Celebration Package</h3>
                                                <p className="pkg-prompt-desc">
                                                    Select one of the 3 package categories below to customize your inclusions. Package options will be revealed once you make a selection.
                                                </p>
                                            </div>

                                            <div className="pkg-choice-grid">
                                                {currentGroups.map((group) => {
                                                    const count = getGroupSelectedCount(group);
                                                    return (
                                                        <div 
                                                            key={group.key} 
                                                            className="pkg-choice-card"
                                                            onClick={() => setActiveStep3GroupKey(group.key)}
                                                            role="button"
                                                            tabIndex={0}
                                                        >
                                                            {count > 0 && (
                                                                <span className="pkg-choice-count-badge">
                                                                    <i className="fa-solid fa-circle-check"></i> {count} selected
                                                                </span>
                                                            )}
                                                            <div className="pkg-choice-icon-wrap">
                                                                <i className={`fa-solid ${group.icon}`}></i>
                                                            </div>
                                                            <div className="pkg-choice-name">
                                                                <span>{group.title}</span>
                                                            </div>
                                                            <div className="pkg-choice-desc">
                                                                {group.subtitle}
                                                            </div>
                                                            <button type="button" className="pkg-choice-action-btn">
                                                                <span>Select This Package</span>
                                                                <i className="fa-solid fa-arrow-right"></i>
                                                            </button>
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    ) : (
                                        /* STATE 2: Once chosen, remove the 3 choices and show only the selected package options */
                                        <div>
                                            <div className="pkg-active-selected-bar">
                                                <div className="pkg-active-selected-left">
                                                    <div className="pkg-active-selected-icon">
                                                        <i className={`fa-solid ${activeGroup.icon}`}></i>
                                                    </div>
                                                    <div>
                                                        <span className="pkg-active-selected-tag">Selected Package Category</span>
                                                        <h3 className="pkg-active-selected-title">{activeGroup.title}</h3>
                                                        <p className="pkg-active-selected-desc">{activeGroup.subtitle}</p>
                                                    </div>
                                                </div>
                                                <button 
                                                    type="button" 
                                                    className="btn-change-pkg-choice"
                                                    onClick={() => setActiveStep3GroupKey(null)}
                                                    title="Change package category"
                                                >
                                                    <i className="fa-solid fa-arrows-rotate"></i>
                                                    <span>Change Category</span>
                                                </button>
                                            </div>

                                            {/* Render Only the Selected Category's Content */}
                                            <div className="pkg-active-content-wrapper">
                                                {renderGroupContent(activeGroup)}
                                            </div>
                                        </div>
                                    )}

                                </div>

                                {/* STEP 4: SPECIAL NOTES */}
                                <div className="form-card" style={{ marginBottom: 0 }}>
                                    <div className="step-header">
                                        <div className="step-num">4</div>
                                        <div>
                                            <h2>Special Requests &amp; Motif</h2>
                                            <p>Let us know your preferred theme, colors, program details, or special requests</p>
                                        </div>
                                    </div>

                                    <div className="form-group" style={{ marginBottom: 0 }}>
                                        <label>Special Instructions / Theme / Color Motif</label>
                                        <textarea 
                                            rows="4" 
                                            className="form-control"
                                            placeholder="e.g. Lavender and Gold floral motif, 2-tier cake presentation, special entrance arches..."
                                            value={specialNotes}
                                            onChange={(e) => setSpecialNotes(e.target.value)}
                                        ></textarea>
                                    </div>
                                </div>

                            </div>

                            {/* Right Column: Sticky Summary & Real-time Total */}
                            <div className="booking-right-column">
                                <div className="summary-card-sidebar">
                                    <div className="summary-head" style={{ background: 'linear-gradient(135deg, #2b392a 0%, #1f291e 100%)', color: '#ffffff' }}>
                                        <h3 className="summary-head-title" style={{ color: '#ffffff' }}>
                                            <i className="fa-solid fa-receipt" style={{ color: '#86efac' }}></i> Booking Summary
                                        </h3>
                                        <span className="summary-badge" style={{ background: 'rgba(255, 255, 255, 0.2)', color: '#ffffff', border: '1px solid rgba(255, 255, 255, 0.3)' }}>
                                            Live Estimate
                                        </span>
                                    </div>

                                    <div className="summary-body">
                                        <div style={{ fontSize: '12px', fontWeight: 700, color: '#4b5563', textTransform: 'uppercase', marginBottom: '10px' }}>
                                            Selected Services &amp; Setup:
                                        </div>

                                        <div className="selected-items-list">
                                            {selectedItemsArray.length === 0 ? (
                                                <div className="empty-selection-msg">
                                                    <i className="fa-solid fa-hand-pointer" style={{ fontSize: '24px', color: '#d1d5db', marginBottom: '8px', display: 'block' }}></i>
                                                    Select any package, equipment, or service from the list to see live pricing.
                                                </div>
                                            ) : (
                                                selectedItemsArray.map((it) => (
                                                    <div key={it.id} className="selected-item-row">
                                                        <div style={{ flex: 1, paddingRight: '8px' }}>
                                                            <div className="selected-item-name">{it.label}</div>
                                                            <small style={{ fontSize: '11px', color: '#6b7280' }}>{it.catTitle}</small>
                                                        </div>
                                                        <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                                                            <div className="selected-item-price">
                                                                {it.price > 0 ? `₱${it.price.toLocaleString()}` : 'Included'}
                                                            </div>
                                                            <button 
                                                                type="button" 
                                                                className="btn-remove-item"
                                                                title="Remove item"
                                                                onClick={() => handleRemoveItem(it.id)}
                                                            >
                                                                &times;
                                                            </button>
                                                        </div>
                                                    </div>
                                                ))
                                            )}
                                        </div>

                                        <div className="summary-total-bar">
                                            <div>
                                                <div className="summary-total-label">Total Estimate</div>
                                                <small style={{ fontSize: '11px', color: '#9ca3af' }}>PHP (&#8369;)</small>
                                            </div>
                                            <div className="summary-total-value">
                                                &#8369;{totalEstimate.toLocaleString()}
                                            </div>
                                        </div>

                                        {isGuestExceeded && (
                                            <div style={{
                                                background: '#fff1f2',
                                                border: '1.5px solid #fecdd3',
                                                borderRadius: '8px',
                                                padding: '12px',
                                                marginBottom: '12px',
                                                color: '#9f1239',
                                                fontSize: '12px',
                                                display: 'flex',
                                                gap: '8px',
                                                alignItems: 'flex-start',
                                                lineHeight: 1.35
                                            }}>
                                                <i className="fa-solid fa-ban" style={{ color: '#e11d48', fontSize: '16px', flexShrink: 0, marginTop: '2px' }}></i>
                                                <div style={{ flex: 1 }}>
                                                    <strong style={{ display: 'block', marginBottom: '4px' }}>Cannot Proceed with Current Guest Count:</strong>
                                                    {selectedPackageLimit ? (
                                                        <span>Expected guests (<strong>{numGuests} pax</strong>) exceeds your selected catering package limit (<strong>{selectedPackageLimit} pax</strong>).</span>
                                                    ) : (
                                                        <span>Expected guests (<strong>{numGuests} pax</strong>) exceeds our maximum catering service capacity (<strong>{maxCatalogCateringPax} pax</strong>).</span>
                                                    )}
                                                    <div style={{ marginTop: '8px', display: 'flex', gap: '6px', flexWrap: 'wrap' }}>
                                                        <button 
                                                            type="button" 
                                                            className="btn-quick-fix-pax"
                                                            onClick={() => {
                                                                setGuestCount(String(totalGuestLimit));
                                                                setSubmitError('');
                                                                if (errors.guestCount) {
                                                                    setErrors(prev => ({ ...prev, guestCount: null }));
                                                                }
                                                            }}
                                                        >
                                                            <i className="fa-solid fa-check"></i> Set Guests to {totalGuestLimit} Pax
                                                        </button>
                                                        <button 
                                                            type="button" 
                                                            className="btn-quick-scroll-guest"
                                                            onClick={() => {
                                                                const el = document.getElementById('guest_count_input');
                                                                if (el) {
                                                                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                                                    el.focus();
                                                                }
                                                            }}
                                                        >
                                                            <i className="fa-solid fa-pen-to-square"></i> Edit Count
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        )}

                                        {submitError && (
                                            <div className="submit-error-banner" role="alert">
                                                <i className="fa-solid fa-circle-exclamation"></i>
                                                <div style={{ flex: 1 }}>
                                                    <strong style={{ display: 'block', marginBottom: '3px' }}>Attention Needed:</strong>
                                                    <span>{submitError}</span>
                                                    {Object.keys(errors).length > 0 && (
                                                        <ul style={{ margin: '6px 0 0 0', paddingLeft: '16px', fontSize: '11.5px', color: '#881337', lineHeight: 1.4 }}>
                                                            {errors.clientName && <li>{errors.clientName}</li>}
                                                            {errors.clientEmail && <li>{errors.clientEmail}</li>}
                                                            {errors.clientPhone && <li>{errors.clientPhone}</li>}
                                                            {errors.eventTitle && <li>{errors.eventTitle}</li>}
                                                            {errors.eventDate && <li>{errors.eventDate}</li>}
                                                            {errors.guestCount && <li>{errors.guestCount}</li>}
                                                            {errors.locationVenue && <li>{errors.locationVenue}</li>}

                                                        </ul>
                                                    )}
                                                </div>
                                            </div>
                                        )}

                                        {/* Data Privacy Consent Badge — consent accepted via upfront gate modal (RA 10173) */}
                                        <div style={{
                                            background: '#ecfdf5',
                                            border: '1px solid #a7f3d0',
                                            borderRadius: '10px',
                                            padding: '10px 14px',
                                            marginBottom: '14px',
                                            textAlign: 'left',
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'space-between',
                                            gap: '10px'
                                        }}>
                                            <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                                                <i className="fa-solid fa-circle-check" style={{ color: '#059669', fontSize: '16px' }}></i>
                                                <span style={{ fontSize: '12px', fontWeight: 700, color: '#065f46' }}>
                                                    Data Privacy Consent Accepted (RA 10173)
                                                </span>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => setShowPrivacyModal(true)}
                                                style={{
                                                    background: 'none',
                                                    border: 'none',
                                                    color: '#0284c7',
                                                    fontSize: '11px',
                                                    fontWeight: 600,
                                                    cursor: 'pointer',
                                                    padding: 0,
                                                    textDecoration: 'underline'
                                                }}
                                            >
                                                Review Policy
                                            </button>
                                        </div>

                                        {config.turnstileEnabled && config.turnstileSiteKey && (
                                            <div style={{ display: 'flex', justifyContent: 'center', marginBottom: '14px' }}>
                                                <div id="mainBookingTurnstile"></div>
                                            </div>
                                        )}

                                        <button 
                                            type="submit" 
                                            className="btn-submit-booking"
                                            disabled={isSubmitting}
                                            style={isGuestExceeded ? { background: '#dc2626', borderColor: '#b91c1c', cursor: 'pointer', boxShadow: '0 4px 14px rgba(220, 38, 38, 0.35)' } : {}}
                                        >
                                            {isSubmitting ? (
                                                <>
                                                    <i className="fa-solid fa-spinner fa-spin"></i> Processing Booking...
                                                </>
                                            ) : isGuestExceeded ? (
                                                <>
                                                    <i className="fa-solid fa-triangle-exclamation"></i> Adjust Guest Count to Proceed
                                                </>
                                            ) : (
                                                <>
                                                    <i className="fa-solid fa-paper-plane"></i> Submit Booking Request
                                                </>
                                            )}
                                        </button>

                                        <p className="terms-note">
                                            <i className="fa-solid fa-shield-halved" style={{ color: 'var(--primary)' }}></i>
                                            Submitting queues your reservation as <strong>PENDING</strong>. No immediate payment is required until our coordinator approves and contacts you.
                                        </p>

                                        <div style={{ textAlign: 'center', marginTop: '10px' }}>
                                            <button
                                                type="button"
                                                className="btn-cancel-booking-flow"
                                                onClick={() => triggerExitWarning('index.php')}
                                                title="Cancel booking and return to home"
                                            >
                                                <i className="fa-solid fa-arrow-left"></i>
                                                <span>Cancel Booking &amp; Return to Home</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </form>

                    {/* Mobile Floating Sticky Total Bar */}
                    <div className="mobile-sticky-total-bar">
                        <div className="mobile-sticky-info">
                            <div className="mobile-sticky-label">
                                <i className="fa-solid fa-receipt"></i> Live Total &bull; {selectedItemsArray.length} {selectedItemsArray.length === 1 ? 'item' : 'items'}
                            </div>
                            <div className="mobile-sticky-amount">
                                &#8369;{totalEstimate.toLocaleString()}
                            </div>
                        </div>
                        <button 
                            type="button" 
                            className="mobile-sticky-btn"
                            onClick={() => {
                                const btn = document.querySelector('.btn-submit-booking');
                                if (btn) {
                                    btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                    btn.focus();
                                } else {
                                    const rightCol = document.querySelector('.booking-right-column');
                                    if (rightCol) {
                                        rightCol.scrollIntoView({ behavior: 'smooth' });
                                    }
                                }
                            }}
                        >
                            <span>Review &bull; Submit</span>
                            <i className="fa-solid fa-arrow-down"></i>
                        </button>
                    </div>

                    {/* 1. Email Warning & Misconception Prevention Modal */}
                    {emailWarningModal.isOpen && (
                        <div className="email-warning-overlay" role="dialog" aria-modal="true" aria-labelledby="emailWarnTitle">
                            <div className="email-warning-card">
                                <div className="email-warning-header-icon">
                                    <i className="fa-solid fa-envelope-circle-check"></i>
                                </div>
                                
                                <h3 id="emailWarnTitle" className="email-warning-title">
                                    Verify Email Before Sending Code
                                </h3>
                                
                                <p className="email-warning-lead">
                                    Please double-check your email address below. A 6-digit verification code will be sent to this exact address:
                                </p>

                                {/* Prominent High-Contrast Target Email Display */}
                                <div className={`email-display-highlight-box ${emailWarningModal.typoWarning ? 'has-warning' : ''}`}>
                                    <i className="fa-solid fa-envelope" style={{ color: emailWarningModal.typoWarning ? '#ea580c' : '#059669', fontSize: '20px' }}></i>
                                    <span className="email-display-text">{emailWarningModal.email}</span>
                                </div>

                                {/* Smart Typo Detection Banner (if domain typo was found) */}
                                {emailWarningModal.typoWarning && (
                                    <div className="email-typo-alert-box" role="alert">
                                        <div style={{ display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 700, color: '#9a3412', marginBottom: '6px' }}>
                                            <i className="fa-solid fa-triangle-exclamation"></i>
                                            <span>Possible Typo Detected</span>
                                        </div>
                                        <div style={{ fontSize: '13px', color: '#7c2d12', marginBottom: '10px' }}>
                                            {emailWarningModal.typoWarning.message}
                                        </div>
                                        {emailWarningModal.typoWarning.suggested && (
                                            <button
                                                type="button"
                                                className="btn-apply-email-suggestion"
                                                onClick={() => {
                                                    const suggested = emailWarningModal.typoWarning.suggested;
                                                    setClientEmail(suggested);
                                                    setEmailWarningModal(prev => ({
                                                        ...prev,
                                                        email: suggested,
                                                        typoWarning: null
                                                    }));
                                                }}
                                            >
                                                <i className="fa-solid fa-wand-magic-sparkles"></i>
                                                <span>Click to Fix: <strong>{emailWarningModal.typoWarning.suggested}</strong></span>
                                            </button>
                                        )}
                                    </div>
                                )}

                                {/* Checklist / Misconception Prevention Advisory */}
                                <div className="email-warning-checklist">
                                    <div className="warning-checklist-item">
                                        <i className="fa-solid fa-circle-exclamation" style={{ color: '#d97706', marginTop: '2px' }}></i>
                                        <div>
                                            <strong>Check for Missing or Extra Letters:</strong> Double-check the spelling of your name and domain. If any letter is missing (e.g. <em>Dale</em> vs <em>Dal</em>, or <em>barile</em> vs <em>baril</em>), you will <u>not</u> receive your verification code.
                                        </div>
                                    </div>
                                    <div className="warning-checklist-item">
                                        <i className="fa-solid fa-clock" style={{ color: '#059669', marginTop: '2px' }}></i>
                                        <div>
                                            <strong>Active Mailbox:</strong> Ensure you can open this inbox now. The 6-digit code expires in 5 minutes.
                                        </div>
                                    </div>
                                </div>

                                {/* Data Privacy & Terms Notice */}
                                <div className="email-warning-terms-box">
                                    <div className="email-warning-terms-title">
                                        <i className="fa-solid fa-shield-halved"></i>
                                        <span>Data Privacy &amp; Terms Notice</span>
                                    </div>
                                    <p className="email-warning-terms-text">
                                        By confirming your email, you agree that your details will be stored securely in our protected cloud database and used solely for booking verification, quotation generation, and event coordination.
                                    </p>
                                </div>

                                <div className="email-warning-modal-actions">
                                    <button
                                        type="button"
                                        className="btn-confirm-email-send"
                                        onClick={handleProceedWithEmail}
                                        disabled={isSubmitting}
                                    >
                                        {isSubmitting ? (
                                            <>
                                                <i className="fa-solid fa-spinner fa-spin"></i>
                                                <span>Sending Verification Code...</span>
                                            </>
                                        ) : (
                                            <>
                                                <i className="fa-solid fa-paper-plane"></i>
                                                <span>Email is Correct — Send Code</span>
                                            </>
                                        )}
                                    </button>

                                    <button
                                        type="button"
                                        className="btn-edit-email-address"
                                        onClick={() => {
                                            setEmailWarningModal({ isOpen: false, email: '', typoWarning: null });
                                            setTimeout(() => {
                                                const input = document.getElementById('client_email_input');
                                                if (input) {
                                                    input.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                                    input.focus();
                                                    input.select();
                                                }
                                            }, 80);
                                        }}
                                    >
                                        <i className="fa-solid fa-pen-to-square"></i>
                                        <span>Wait, Let Me Fix My Email</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Data Privacy Notice & Statutory Rights Gate Modal (RA 10173) */}
                    {showPrivacyModal && (
                        <div className="email-warning-overlay" style={{ zIndex: 9999999 }} role="dialog" aria-modal="true" aria-labelledby="privacyModalTitle">
                            <div className="email-warning-card" style={{ maxWidth: '640px', width: '92%', textAlign: 'left', padding: '24px 28px', animation: 'scaleUp 0.25s ease-out' }}>
                                {/* Header */}
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', borderBottom: '1px solid #e5e7eb', paddingBottom: '14px', marginBottom: '16px' }}>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                                        <div style={{ width: '44px', height: '44px', borderRadius: '12px', background: 'linear-gradient(135deg, #eaf2ec 0%, #d1fae5 100%)', color: '#18392b', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '22px' }}>
                                            <i className="fa-solid fa-shield-halved"></i>
                                        </div>
                                        <div>
                                            <h3 id="privacyModalTitle" style={{ fontSize: '18px', fontWeight: 800, margin: 0, color: '#18392b' }}>
                                                Data Privacy Notice &amp; Rights
                                            </h3>
                                            <span style={{ fontSize: '11px', color: '#6b7280' }}>Republic Act No. 10173 (Data Privacy Act of 2012)</span>
                                        </div>
                                    </div>
                                    {/* Only show close X if already accepted (reviewing later) */}
                                    {privacyAccepted && (
                                        <button
                                            type="button"
                                            onClick={() => setShowPrivacyModal(false)}
                                            style={{ background: 'none', border: 'none', fontSize: '20px', color: '#9ca3af', cursor: 'pointer', padding: '4px' }}
                                            aria-label="Close modal"
                                        >
                                            <i className="fa-solid fa-xmark"></i>
                                        </button>
                                    )}
                                </div>

                                {/* Gate Notice Banner — only shown before acceptance */}
                                {!privacyAccepted && (
                                    <div style={{
                                        background: '#fffbeb',
                                        border: '1px solid #fef3c7',
                                        borderLeft: '4px solid #f59e0b',
                                        borderRadius: '8px',
                                        padding: '10px 14px',
                                        marginBottom: '16px',
                                        fontSize: '12.5px',
                                        color: '#92400e',
                                        lineHeight: 1.5,
                                        display: 'flex',
                                        alignItems: 'flex-start',
                                        gap: '8px'
                                    }}>
                                        <i className="fa-solid fa-triangle-exclamation" style={{ color: '#d97706', marginTop: '2px', fontSize: '14px', flexShrink: 0 }}></i>
                                        <span>
                                            <strong>Please read and accept</strong> the Data Privacy Notice below before proceeding to the booking form. Your consent is required under RA 10173.
                                        </span>
                                    </div>
                                )}

                                {/* Policy Content */}
                                <div style={{ fontSize: '13px', color: '#374151', lineHeight: 1.6 }}>
                                    <h4 style={{ fontSize: '14px', fontWeight: 700, color: '#111827', margin: '14px 0 6px 0' }}>1. Personal Information Controller</h4>
                                    <p style={{ margin: 0 }}>
                                        <strong>Tyoy Creation Event Styling</strong> acts as the Personal Information Controller (PIC) responsible for safeguarding your personal data submitted through this booking portal.
                                    </p>

                                    <h4 style={{ fontSize: '14px', fontWeight: 700, color: '#111827', margin: '14px 0 6px 0' }}>2. Data We Collect &amp; Purpose of Processing</h4>
                                    <p style={{ margin: '0 0 6px 0' }}>
                                        We collect your full name, email address, contact number, venue location, and event specifications solely for declared legitimate purposes:
                                    </p>
                                    <ul style={{ margin: 0, paddingLeft: '20px', color: '#4b5563' }}>
                                        <li>Evaluating your event styling inquiry and checking calendar availability.</li>
                                        <li>Preparing personalized floral, decor, and catering package estimates.</li>
                                        <li>Sending 6-digit OTP verification codes to confirm your identity.</li>
                                        <li>Direct communication and styling coordination for your celebration.</li>
                                    </ul>

                                    <h4 style={{ fontSize: '14px', fontWeight: 700, color: '#111827', margin: '14px 0 6px 0' }}>3. Data Protection &amp; Security Standards</h4>
                                    <p style={{ margin: 0 }}>
                                        Your sensitive contact details are encrypted at rest using <strong>AES-256-CBC with HMAC-SHA256 authenticated encryption</strong> and protected in transit via SSL/TLS 1.3. We enforce a <strong>strict zero-monetization policy</strong>: Tyoy Creation will <u>never</u> sell, trade, or share your personal data with third-party marketers or data brokers.
                                    </p>

                                    <h4 style={{ fontSize: '14px', fontWeight: 700, color: '#111827', margin: '14px 0 6px 0' }}>4. Retention &amp; Disposal Schedule</h4>
                                    <p style={{ margin: 0 }}>
                                        Rejected or declined inquiries are automatically purged after <strong>7 days</strong>. Active booking records are kept for the duration of event coordination plus 1 year. Basic financial records are retained for 5 years per BIR statutory accounting rules with client PII scrubbed.
                                    </p>

                                    <h4 style={{ fontSize: '14px', fontWeight: 700, color: '#111827', margin: '14px 0 6px 0' }}>5. Your Statutory Rights as a Data Subject</h4>
                                    <p style={{ margin: '0 0 6px 0' }}>Under Chapter VIII, Section 34 of RA 10173, you hold the following statutory rights:</p>
                                    <ul style={{ margin: 0, paddingLeft: '20px', color: '#4b5563' }}>
                                        <li><strong>Right to Access &amp; Data Portability:</strong> You may request a downloadable JSON copy of all personal data held about you.</li>
                                        <li><strong>Right to Erasure or Blocking:</strong> You may request the permanent removal or anonymization of your records from our systems.</li>
                                        <li><strong>Right to Rectification:</strong> You may dispute and correct any inaccurate or outdated information.</li>
                                    </ul>

                                    <div style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: '8px', padding: '12px 16px', marginTop: '16px', fontSize: '12.5px' }}>
                                        <strong>Data Protection Officer (DPO) Contact:</strong><br />
                                        Email: <a href="mailto:privacy@tyoycreation.com" style={{ color: '#0284c7' }}>privacy@tyoycreation.com</a> &bull; Mobile: +63 912 345 6789
                                    </div>
                                </div>

                                {/* Action Buttons */}
                                <div style={{ display: 'flex', justifyContent: privacyAccepted ? 'flex-end' : 'space-between', alignItems: 'center', marginTop: '20px', paddingTop: '14px', borderTop: '1px solid #e5e7eb', gap: '12px' }}>
                                    {!privacyAccepted && (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                window.location.href = 'index.php';
                                            }}
                                            style={{
                                                background: 'none',
                                                border: '1px solid #d1d5db',
                                                color: '#6b7280',
                                                padding: '9px 18px',
                                                fontSize: '13px',
                                                borderRadius: '8px',
                                                cursor: 'pointer',
                                                fontWeight: 600,
                                                transition: 'all 0.15s ease'
                                            }}
                                        >
                                            <i className="fa-solid fa-arrow-left" style={{ marginRight: '6px' }}></i> Decline &amp; Go Back
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        onClick={() => {
                                            try { sessionStorage.setItem('tyoy_privacy_agreed', '1'); } catch (e) {}
                                            setShowPrivacyModal(false);
                                            setPrivacyConsent(true);
                                            setPrivacyAccepted(true);
                                        }}
                                        className="btn-primary"
                                        style={{ padding: '9px 22px', fontSize: '13px', borderRadius: '8px', fontWeight: 700 }}
                                    >
                                        <i className="fa-solid fa-check" style={{ marginRight: '6px' }}></i>
                                        {privacyAccepted ? 'Close' : 'I Agree & Continue'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* 2. Two-Step Verification Modal */}
                    {verifyModal.isOpen && (() => {
                        const modalContent = (
                            <div className="verify-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="verifyModalTitle">
                                <div className="verify-modal-card">
                                    <button
                                        type="button"
                                        className="verify-modal-close-btn"
                                        title="Close and edit booking details"
                                        aria-label="Close verification modal"
                                        onClick={() => setVerifyModal(prev => ({ ...prev, isOpen: false, error: '' }))}
                                    >
                                        <i className="fa-solid fa-xmark"></i>
                                    </button>

                                    <div className="verify-modal-icon">
                                        <i className="fa-solid fa-envelope-circle-check"></i>
                                    </div>
                                    <h2 id="verifyModalTitle" style={{ fontSize: '18px', fontWeight: 800, color: '#0f172a', margin: '0 0 4px 0' }}>
                                        Enter Verification Code
                                    </h2>

                                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '6px', flexWrap: 'wrap', marginBottom: '8px' }}>
                                        <div className="verify-target-email-badge" style={{ margin: 0, padding: '3px 8px', fontSize: '11.5px', background: verifyModal.emailSent ? '#ecfdf5' : '#fef2f2', borderColor: verifyModal.emailSent ? '#a7f3d0' : '#fecaca' }}>
                                            <i className={`fa-solid ${verifyModal.emailSent ? 'fa-envelope' : 'fa-triangle-exclamation'}`} style={{ color: verifyModal.emailSent ? '#059669' : '#dc2626' }}></i>
                                            <span>{verifyModal.emailSent ? 'Sent to: ' : 'Delivery issue: '}<strong style={{ color: '#0f172a' }}>{verifyModal.email || clientEmail}</strong></span>
                                        </div>
                                        <div className={`verify-timer-box ${verifyModal.expiresIn <= 60 ? 'warning' : ''}`}>
                                            <i className="fa-regular fa-clock"></i>
                                            {verifyModal.expiresIn > 0 ? (
                                                <span>Expires in <strong>{formatTimeRemaining(verifyModal.expiresIn)}</strong></span>
                                            ) : (
                                                <span style={{ color: '#dc2626' }}>Code expired</span>
                                            )}
                                        </div>
                                    </div>

                                    {verifyModal.emailSent === false && (
                                        <div className="verify-error-banner" role="alert" style={{ marginBottom: '8px', padding: '7px 11px', fontSize: '12px', background: '#fff1f2', border: '1px solid #fecdd3', color: '#9f1239', textAlign: 'left' }}>
                                            <i className="fa-solid fa-triangle-exclamation" style={{ marginTop: '2px', color: '#e11d48', fontSize: '13px', flexShrink: 0 }}></i>
                                            <div>
                                                <strong>Verification Code Undelivered:</strong> {verifyModal.error || 'The code could not be sent to your email.'} Please check the spelling or click <strong>&ldquo;Change Email&rdquo;</strong> to update your address.
                                            </div>
                                        </div>
                                    )}

                                    {(() => {
                                        const typo = detectEmailIssues(verifyModal.email || clientEmail);
                                        if (!typo) return null;
                                        return (
                                            <div className="verify-typo-alert" role="alert">
                                                <i className="fa-solid fa-triangle-exclamation" style={{ color: '#ea580c', marginTop: '2px', fontSize: '13px', flexShrink: 0 }}></i>
                                                <div>
                                                    <strong>Typo Alert:</strong> Address <code>{verifyModal.email || clientEmail}</code>. {typo.message}{' '}
                                                    {typo.suggested && (
                                                        <button
                                                            type="button"
                                                            style={{ display: 'inline-block', marginTop: '4px', background: '#ffedd5', border: '1px solid #fdba74', color: '#9a3412', padding: '2px 8px', borderRadius: '5px', fontSize: '11px', fontWeight: 700, cursor: 'pointer' }}
                                                            onClick={() => {
                                                                setVerifyModal(prev => ({
                                                                    ...prev,
                                                                    isEditingEmail: true,
                                                                    newEmailInput: typo.suggested,
                                                                    emailUpdateError: ''
                                                                }));
                                                            }}
                                                        >
                                                            <i className="fa-solid fa-pen-to-square"></i> Change to: {typo.suggested}
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })()}

                                    {verifyModal.error && verifyModal.emailSent !== false && (
                                        <div className="verify-error-banner" role="alert" style={{ marginBottom: '8px', padding: '7px 11px', fontSize: '12px' }}>
                                            <i className="fa-solid fa-circle-exclamation" style={{ marginTop: '2px' }}></i>
                                            <div>{verifyModal.error}</div>
                                        </div>
                                    )}

                                    {verifyModal.resendSuccess && (
                                        <div className="verify-success-banner" role="status" style={{ marginBottom: '8px', padding: '7px 11px', fontSize: '12px' }}>
                                            <i className="fa-solid fa-circle-check"></i>
                                            <div>{verifyModal.resendSuccess}</div>
                                        </div>
                                    )}

                                    {verifyModal.isEditingEmail && (
                                        <div className="verify-email-edit-box" style={{ margin: '0 0 10px 0' }}>
                                            <div style={{ fontWeight: 700, fontSize: '12px', color: '#1e293b', marginBottom: '4px', display: 'flex', alignItems: 'center', gap: '6px' }}>
                                                <i className="fa-solid fa-pen" style={{ color: '#059669' }}></i>
                                                <span>Correct your email address:</span>
                                            </div>
                                            <div className="email-edit-input-row">
                                                <input
                                                    type="email"
                                                    className="email-edit-input"
                                                    placeholder="correct.email@gmail.com"
                                                    value={verifyModal.newEmailInput}
                                                    onChange={(e) => setVerifyModal(prev => ({ ...prev, newEmailInput: e.target.value, emailUpdateError: '' }))}
                                                    autoFocus
                                                />
                                                <button
                                                    type="button"
                                                    className="btn-save-email-edit"
                                                    disabled={verifyModal.isUpdatingEmail}
                                                    onClick={handleUpdateEmailAndResend}
                                                >
                                                    {verifyModal.isUpdatingEmail ? (
                                                        <i className="fa-solid fa-spinner fa-spin"></i>
                                                    ) : (
                                                        <>
                                                            <i className="fa-solid fa-paper-plane"></i>
                                                            <span>Send Code</span>
                                                        </>
                                                    )}
                                                </button>
                                                <button
                                                    type="button"
                                                    className="btn-cancel-email-edit"
                                                    onClick={() => setVerifyModal(prev => ({ ...prev, isEditingEmail: false, emailUpdateError: '' }))}
                                                >
                                                    Cancel
                                                </button>
                                            </div>
                                            {verifyModal.emailUpdateError && (
                                                <div className="email-edit-error">
                                                    <i className="fa-solid fa-circle-exclamation"></i>
                                                    <span>{verifyModal.emailUpdateError}</span>
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    <form onSubmit={handleVerifyCodeSubmit}>
                                        <input
                                            type="text"
                                            inputMode="numeric"
                                            pattern="[0-9]*"
                                            autoComplete="one-time-code"
                                            maxLength={6}
                                            placeholder="------"
                                            value={verifyModal.otpCode}
                                            onChange={handleOtpChange}
                                            className="verify-otp-input"
                                            autoFocus
                                            disabled={verifyModal.isLoading}
                                            aria-label="6-digit verification code"
                                        />

                                        <div style={{ fontSize: '10px', color: '#64748b', textAlign: 'center', margin: '0 0 6px 0', lineHeight: 1.3 }}>
                                            <i className="fa-solid fa-lock" style={{ color: '#059669', marginRight: '4px' }}></i>
                                            By confirming, you agree to our Terms. Your data is stored securely for booking coordination.
                                        </div>

                                        {config.turnstileEnabled && config.turnstileSiteKey && (
                                            <div style={{ display: 'flex', justifyContent: 'center', margin: '2px 0 6px 0', transform: 'scale(0.92)', transformOrigin: 'center' }}>
                                                <div id="verifyModalTurnstile"></div>
                                            </div>
                                        )}

                                        <button
                                            type="submit"
                                            className="btn-verify-submit"
                                            disabled={verifyModal.isLoading || verifyModal.otpCode.length !== 6 || verifyModal.expiresIn <= 0}
                                        >
                                            {verifyModal.isLoading ? (
                                                <>
                                                    <i className="fa-solid fa-spinner fa-spin"></i>
                                                    <span>Verifying Code...</span>
                                                </>
                                            ) : (
                                                <>
                                                    <i className="fa-solid fa-shield-check"></i>
                                                    <span>Verify & Confirm Booking</span>
                                                </>
                                            )}
                                        </button>
                                    </form>

                                    <div className="verify-spam-callout">
                                        <i className="fa-solid fa-inbox" style={{ color: '#d97706', marginTop: '2px', fontSize: '12px' }}></i>
                                        <div>
                                            <strong>Can't find the email?</strong> Please check your <strong>Spam / Junk</strong> folder. If found there, mark as <em>&ldquo;Not Spam&rdquo;</em> so you receive all booking updates.
                                        </div>
                                    </div>

                                    <div className="verify-modal-footer">
                                        <button
                                            type="button"
                                            onClick={handleResendCode}
                                            disabled={verifyModal.resendCooldown > 0 || verifyModal.isLoading}
                                            className="btn-resend-otp"
                                        >
                                            {verifyModal.resendCooldown > 0 ? (
                                                <span><i className="fa-solid fa-arrow-rotate-right"></i> Resend ({verifyModal.resendCooldown}s)</span>
                                            ) : (
                                                <span><i className="fa-solid fa-arrow-rotate-right"></i> Resend Code</span>
                                            )}
                                        </button>

                                        <button
                                            type="button"
                                            className="btn-open-email-edit"
                                            style={{ fontSize: '11px', padding: '3px 7px' }}
                                            onClick={() => setVerifyModal(prev => ({
                                                ...prev,
                                                isEditingEmail: !prev.isEditingEmail,
                                                newEmailInput: prev.email || clientEmail,
                                                emailUpdateError: ''
                                            }))}
                                        >
                                            <i className="fa-solid fa-pen-to-square"></i>
                                            <span>Change Email</span>
                                        </button>

                                        <button
                                            type="button"
                                            onClick={() => setVerifyModal(prev => ({ ...prev, isOpen: false, error: '' }))}
                                            disabled={verifyModal.isLoading}
                                            className="btn-cancel-verify"
                                            title="Close verification and edit form details"
                                        >
                                            Edit Details
                                        </button>

                                        <button
                                            type="button"
                                            onClick={() => triggerExitWarning('index.php')}
                                            disabled={verifyModal.isLoading}
                                            className="btn-cancel-verify"
                                            title="Cancel booking and return to home"
                                            style={{ color: '#dc2626' }}
                                        >
                                            Cancel &amp; Exit
                                        </button>
                                    </div>
                                </div>
                            </div>
                        );
                        return (typeof ReactDOM !== 'undefined' && ReactDOM.createPortal)
                            ? ReactDOM.createPortal(modalContent, document.body)
                            : modalContent;
                    })()}

                    {/* 3. Exit & Discard Booking Warning Modal */}
                    {exitWarningModal.isOpen && (() => {
                        const exitModalContent = (
                            <div className="exit-warning-overlay" role="dialog" aria-modal="true" aria-labelledby="exitWarningTitle">
                                <div className="exit-warning-card">
                                    <button
                                        type="button"
                                        className="verify-modal-close-btn"
                                        title="Stay on booking page"
                                        aria-label="Close exit warning"
                                        onClick={() => setExitWarningModal(prev => ({ ...prev, isOpen: false }))}
                                    >
                                        <i className="fa-solid fa-xmark"></i>
                                    </button>

                                    <div className="exit-warning-icon">
                                        <i className="fa-solid fa-triangle-exclamation"></i>
                                    </div>

                                    <h3 id="exitWarningTitle" style={{ fontSize: '18px', fontWeight: 800, color: '#0f172a', margin: '0 0 6px 0' }}>
                                        Leave Booking &amp; Discard Details?
                                    </h3>

                                    <p style={{ fontSize: '13px', color: '#64748b', lineHeight: 1.45, margin: '0 0 14px 0' }}>
                                        You have active booking details and selected package items. If you proceed back to Home or exit now, <strong>all your entered data will be reset and deleted</strong>.
                                    </p>

                                    <div style={{ background: '#fffbeb', border: '1px solid #fef3c7', borderLeft: '4px solid #f59e0b', borderRadius: '10px', padding: '10px 14px', textAlign: 'left', marginBottom: '16px', fontSize: '12px', color: '#92400e', lineHeight: 1.45 }}>
                                        <div style={{ fontWeight: 700, marginBottom: '5px', display: 'flex', alignItems: 'center', gap: '6px', color: '#78350f' }}>
                                            <i className="fa-solid fa-trash-can" style={{ color: '#d97706' }}></i>
                                            <span>Selections that will be cleared:</span>
                                        </div>
                                        <ul style={{ margin: 0, paddingLeft: '18px', display: 'flex', flexDirection: 'column', gap: '3px' }}>
                                            {clientName && <li>Client Name: <strong>{clientName}</strong></li>}
                                            {clientEmail && <li>Email Address: <strong>{clientEmail}</strong></li>}
                                            {selectedItemsArray.length > 0 && <li>Packages: <strong>{selectedItemsArray.length} items (₱{totalEstimate.toLocaleString()})</strong></li>}
                                            {locationVenue && <li>Venue Location: <strong>{locationVenue}</strong></li>}
                                            {(!clientName && !clientEmail && selectedItemsArray.length === 0) && (
                                                <li>Your partially filled booking fields and customizations.</li>
                                            )}
                                        </ul>
                                    </div>

                                    <div style={{ display: 'flex', gap: '10px', justifyContent: 'center' }}>
                                        <button
                                            type="button"
                                            className="btn-stay-booking"
                                            onClick={() => setExitWarningModal(prev => ({ ...prev, isOpen: false }))}
                                        >
                                            <i className="fa-solid fa-arrow-left"></i>
                                            <span>Stay &amp; Continue Booking</span>
                                        </button>

                                        <button
                                            type="button"
                                            className="btn-discard-exit"
                                            onClick={() => {
                                                const destination = exitWarningModal.targetUrl || 'index.php';
                                                setExitWarningModal({ isOpen: false, targetUrl: 'index.php' });
                                                window.location.href = destination;
                                            }}
                                        >
                                            <i className="fa-solid fa-arrow-right-from-bracket"></i>
                                            <span>Yes, Discard &amp; Leave</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        );

                        return (typeof ReactDOM !== 'undefined' && ReactDOM.createPortal)
                            ? ReactDOM.createPortal(exitModalContent, document.body)
                            : exitModalContent;
                    })()}

                    {/* 4. Schedule Conflict & Double-Booking Reminder Modal */}
                    {conflictModal.isOpen && (() => {
                        const conflictModalContent = (
                            <div className="exit-warning-overlay" role="dialog" aria-modal="true" aria-labelledby="conflictReminderTitle" style={{ zIndex: 9999999 }}>
                                <div className="exit-warning-card" style={{ maxWidth: '460px', padding: '32px 28px', borderRadius: '16px', background: '#ffffff', boxShadow: '0 20px 40px rgba(15, 30, 20, 0.22)', border: '1px solid #e2e8f0', textAlign: 'center' }}>
                                    <button
                                        type="button"
                                        className="verify-modal-close-btn"
                                        title="Close and choose another date"
                                        aria-label="Close date unavailable modal"
                                        onClick={handleResolveConflictChangeDate}
                                    >
                                        <i className="fa-solid fa-xmark"></i>
                                    </button>

                                    <div className="exit-warning-icon" style={{ background: '#fef3c7', color: '#b45309', width: '56px', height: '56px', borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '24px', margin: '0 auto 16px', border: '2px solid #fde68a' }}>
                                        <i className="fa-solid fa-triangle-exclamation"></i>
                                    </div>

                                    <h3 id="conflictReminderTitle" style={{ fontSize: '20px', fontWeight: 800, color: '#14261c', margin: '0 0 10px 0', fontFamily: "'Playfair Display', Georgia, serif" }}>
                                        Date Unavailable
                                    </h3>

                                    <p style={{ fontSize: '13.5px', color: '#475569', lineHeight: 1.55, margin: '0 0 20px 0' }}>
                                        {conflictModal.message || 'Sorry, the date and time you selected is already reserved. Please choose a different date or time, or contact us if you need help.'}
                                    </p>

                                    {conflictModal.friendlyDate && (
                                        <div style={{ background: '#f7f9f7', border: '1px solid #e2e8f0', borderRadius: '10px', padding: '10px 14px', marginBottom: '22px', fontSize: '12.5px', color: '#1e392a', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '8px' }}>
                                            <i className="fa-solid fa-calendar-day" style={{ color: '#c5a059' }}></i>
                                            <span><strong>Selected Date:</strong> {conflictModal.friendlyDate}</span>
                                        </div>
                                    )}

                                    <div style={{ display: 'flex', gap: '12px', justifyContent: 'center', flexWrap: 'wrap' }}>
                                        <button
                                            type="button"
                                            className="btn-modal-choose-date"
                                            onClick={handleResolveConflictChangeDate}
                                            style={{
                                                background: '#18392b',
                                                color: '#ffffff',
                                                border: 'none',
                                                borderRadius: '10px',
                                                padding: '11px 22px',
                                                fontSize: '13.5px',
                                                fontWeight: 700,
                                                cursor: 'pointer',
                                                display: 'inline-flex',
                                                alignItems: 'center',
                                                gap: '8px',
                                                boxShadow: '0 4px 12px rgba(24, 57, 43, 0.25)',
                                                transition: 'all 0.2s'
                                            }}
                                        >
                                            <i className="fa-solid fa-calendar-days"></i>
                                            <span>Choose Another Date</span>
                                        </button>

                                        <a
                                            href="index.php#contact"
                                            className="btn-modal-contact-us"
                                            style={{
                                                background: '#f8fafc',
                                                color: '#18392b',
                                                border: '1.5px solid #cbd5e1',
                                                borderRadius: '10px',
                                                padding: '10px 20px',
                                                fontSize: '13.5px',
                                                fontWeight: 700,
                                                cursor: 'pointer',
                                                textDecoration: 'none',
                                                display: 'inline-flex',
                                                alignItems: 'center',
                                                gap: '8px',
                                                transition: 'all 0.2s'
                                            }}
                                        >
                                            <i className="fa-solid fa-headset" style={{ color: '#c5a059' }}></i>
                                            <span>Contact Us</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        );

                        return (typeof ReactDOM !== 'undefined' && ReactDOM.createPortal)
                            ? ReactDOM.createPortal(conflictModalContent, document.body)
                            : conflictModalContent;
                    })()}

                    {/* Success Confirmation Modal */}
                    {confirmedBooking && (
                        <div className="success-overlay active" id="successOverlay">
                            <div className="success-dialog">
                                {/* 'x' close button to return to website */}
                                <a 
                                    href="index.php" 
                                    className="modal-close-x-btn" 
                                    title="Close and return to website" 
                                    aria-label="Close and return to website"
                                >
                                    <i className="fa-solid fa-xmark"></i>
                                </a>

                                <div className="success-icon-wrap">
                                    <i className="fa-solid fa-check"></i>
                                </div>
                                <h2 style={{ fontSize: '20px', fontWeight: 800, color: '#111827', margin: '0 0 4px 0' }}>
                                    Booking Inquiry Received!
                                </h2>
                                <p style={{ fontSize: '13px', color: '#6b7280', margin: '0 0 10px 0', lineHeight: 1.4 }}>
                                    Thank you! Your event inquiry has been logged as <strong style={{ color: '#d97706' }}>PENDING</strong> and submitted to the event team for review.
                                </p>

                                <div style={{ fontSize: '11px', fontWeight: 700, color: '#4b5563', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                                    Your Booking Reference Number:
                                </div>
                                <div className="ref-badge-display">
                                    {confirmedBooking.refNo}
                                </div>

                                <div style={{ background: '#f9fafb', border: '1px solid #e5e7eb', borderRadius: '10px', padding: '10px 14px', textAlign: 'left', marginBottom: '14px', fontSize: '12.5px' }}>
                                    <div style={{ marginBottom: '4px' }}><strong>Event:</strong> <span>{confirmedBooking.title}</span></div>
                                    <div style={{ marginBottom: '4px' }}><strong>Client:</strong> <span>{confirmedBooking.client}</span></div>
                                    <div><strong>Scheduled Date:</strong> <span>{confirmedBooking.date}</span></div>
                                </div>

                                {/* Terms & Conditions - Data Usage & Storage Details */}
                                <div className="confirmation-terms-card">
                                    <div className="confirmation-terms-header">
                                        <i className="fa-solid fa-shield-halved"></i>
                                        <span>Terms &amp; Conditions &bull; Data Privacy</span>
                                    </div>
                                    <div className="confirmation-terms-body">
                                        <div className="confirmation-terms-item">
                                            <i className="fa-solid fa-database"></i>
                                            <div>
                                                <strong>Where Your Data is Stored:</strong> Your contact information and event specifications are stored securely in Tyoy Creation's protected cloud database (hosted on Supabase PostgreSQL with SSL encryption and Row Level Security) as well as internal transactional backup records. Access is restricted strictly to authorized event personnel.
                                            </div>
                                        </div>
                                        <div className="confirmation-terms-item">
                                            <i className="fa-solid fa-clipboard-check"></i>
                                            <div>
                                                <strong>Where &amp; How Your Data is Used:</strong> Your data is used exclusively by our event coordination and styling management team to:
                                                <ul style={{ margin: '4px 0 0 0', paddingLeft: '18px', color: '#475569' }}>
                                                    <li>Verify calendar availability and reserve your chosen event date.</li>
                                                    <li>Prepare personalized styling quotations, floral concept proposals, and service contracts.</li>
                                                    <li>Dispatch booking updates, email receipts, and OTP verification notices.</li>
                                                    <li>Provide client support and direct event coordination.</li>
                                                </ul>
                                            </div>
                                        </div>
                                        <div className="confirmation-terms-item">
                                            <i className="fa-solid fa-user-shield"></i>
                                            <div>
                                                <strong>Privacy &amp; Third-Party Protection:</strong> We value your privacy. Tyoy Creation will <u>never</u> sell, rent, monetize, or share your personal information with external third parties or advertisers.
                                            </div>
                                        </div>
                                        <div className="confirmation-terms-item">
                                            <i className="fa-solid fa-file-contract"></i>
                                            <div>
                                                <strong>Reservation Terms:</strong> This initial submission logs your inquiry as <strong style={{ color: '#d97706' }}>PENDING</strong>. Official date reservation is confirmed only after event team review, mutual consultation, and formal contract/downpayment processing.
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div style={{ display: 'flex', gap: '12px', justifyContent: 'center', flexWrap: 'wrap' }}>
                                    <button 
                                        type="button" 
                                        onClick={() => setShowExitConfirm(true)} 
                                        className="btn-done-modal" 
                                        style={{ background: '#f8fafc', color: '#475569', border: '1px solid #cbd5e1' }}
                                    >
                                        <i className="fa-solid fa-arrow-left"></i> Back
                                    </button>
                                    <a href="index.php" className="btn-done-modal">
                                        <i className="fa-solid fa-house"></i> Return to Home
                                    </a>
                                    <button 
                                        type="button" 
                                        onClick={handleReset} 
                                        className="btn-done-modal" 
                                        style={{ background: '#ffffff', color: 'var(--primary)', border: '1px solid var(--primary)' }}
                                    >
                                        <i className="fa-solid fa-plus"></i> Submit Another
                                    </button>
                                </div>

                                {/* Confirmation Prompt Before Exiting Modal */}
                                {showExitConfirm && (
                                    <div className="modal-overlay active" style={{ zIndex: 12000, background: 'rgba(15, 23, 15, 0.75)', backdropFilter: 'blur(4px)' }}>
                                        <div className="modal-card" style={{ maxWidth: '420px', textAlign: 'center', padding: '28px 24px', animation: 'scaleUp 0.2s ease-out' }}>
                                            <div style={{ width: '56px', height: '56px', borderRadius: '50%', background: '#fffbeb', color: '#d97706', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '24px', margin: '0 auto 16px auto', border: '2px solid #fde68a' }}>
                                                <i className="fa-solid fa-arrow-right-from-bracket"></i>
                                            </div>
                                            <h3 style={{ fontSize: '18px', fontWeight: 800, margin: '0 0 8px 0', color: '#1e293b' }}>
                                                Exit Confirmation
                                            </h3>
                                            <p style={{ fontSize: '13px', color: '#64748b', margin: '0 0 22px 0', lineHeight: 1.5 }}>
                                                Are you sure you really want to exit this booking confirmation? Please ensure you have copied or taken note of your reference number <strong>{confirmedBooking.refNo}</strong> before leaving.
                                            </p>
                                            <div style={{ display: 'flex', gap: '10px', justifyContent: 'center' }}>
                                                <button 
                                                    type="button" 
                                                    className="btn-done-modal" 
                                                    style={{ background: '#f1f5f9', color: '#475569', border: '1px solid #cbd5e1', padding: '10px 18px', fontSize: '13px' }}
                                                    onClick={() => setShowExitConfirm(false)}
                                                >
                                                    Cancel / Stay
                                                </button>
                                                <button 
                                                    type="button" 
                                                    className="btn-done-modal" 
                                                    style={{ background: '#dc2626', color: '#ffffff', border: 'none', padding: '10px 18px', fontSize: '13px' }}
                                                    onClick={() => {
                                                        setShowExitConfirm(false);
                                                        setConfirmedBooking(null);
                                                    }}
                                                >
                                                    Yes, Exit Modal
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}
                </div>
            );
        }

        // Render React 18 Root with Error Handling & Fallback
        try {
            const rootContainer = document.getElementById('reactBookingRoot');
            if (rootContainer && typeof ReactDOM !== 'undefined' && ReactDOM.createRoot) {
                const root = ReactDOM.createRoot(rootContainer);
                root.render(<BookingApp />);
            } else {
                throw new Error("ReactDOM engine not ready");
            }
        } catch (err) {
            console.error("BookingApp mount error:", err);
            const fb = document.getElementById('bookingEngineFallback');
            if (fb) {
                fb.innerHTML = `
                    <div style="font-size: 26px; color: #dc2626; margin-bottom: 12px;"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div style="font-weight: 700; font-size: 16px; color: #1e293b; margin-bottom: 6px;">Booking Form Loading Notice</div>
                    <div style="font-size: 13px; color: #64748b; margin-bottom: 14px;">The interactive booking engine is initializing. Please click reload to retry.</div>
                    <button type="button" onclick="window.location.reload()" style="background: var(--primary); color: #fff; border: none; padding: 8px 18px; border-radius: 6px; font-weight: 600; cursor: pointer;">
                        <i class="fa-solid fa-rotate-right"></i> Reload Page
                    </button>
                `;
            }
        }
    </script>

    <!-- Chatbot Widget Scripts -->
    <script>
        function autoResizeChatInput(el) {
            if (!el) return;
            el.style.height = 'auto';
            if (!el.value) {
                el.style.height = '38px';
                el.style.overflowY = 'hidden';
                return;
            }
            const newH = Math.min(el.scrollHeight, 120);
            el.style.height = Math.max(38, newH) + 'px';
            el.style.overflowY = el.scrollHeight > 120 ? 'auto' : 'hidden';
        }

        function handleChatInputKeydown(e) {
            if (e.key === 'Enter') {
                if (e.shiftKey) {
                    // Shift + Enter creates a natural new line spacing
                    setTimeout(() => autoResizeChatInput(e.target), 0);
                } else {
                    // Plain Enter submits the message
                    e.preventDefault();
                    sendChatMessage(e);
                }
            }
        }

        function formatChatMessage(text) {
            if (!text) return '';
            return text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                .replace(/__(.*?)__/g, '<strong>$1</strong>')
                .replace(/\*(.*?)\*/g, '<em>$1</em>')
                .replace(/`([^`]+)`/g, '<code style="background: rgba(0,0,0,0.06); padding: 1px 5px; border-radius: 4px; font-size: 0.9em;">$1</code>')
                .replace(/\n/g, '<br>');
        }

        // Cookie Consent Engine
        function checkCookieConsent() {
            const consent = localStorage.getItem('tyoy_cookie_consent');
            if (!consent) {
                setTimeout(() => {
                    const el = document.getElementById('cookieConsentBanner');
                    if (el) el.style.display = 'block';
                }, 500);
            }
        }

        function handleCookieConsent(choice) {
            localStorage.setItem('tyoy_cookie_consent', choice);
            document.cookie = 'cookie_consent=' + choice + '; max-age=31536000; path=/; SameSite=Lax';
            const el = document.getElementById('cookieConsentBanner');
            if (el) {
                el.style.animation = 'cookieSlideDown 0.25s ease forwards';
                setTimeout(() => { el.style.display = 'none'; }, 250);
            }
        }

        // Synchronized Chatbot Concierge Engine
        const CHAT_SYNC_KEY = 'tyoy_synchronized_chat_history';

        function getStoredChatHistory() {
            try {
                const data = localStorage.getItem(CHAT_SYNC_KEY);
                return data ? JSON.parse(data) : [];
            } catch (e) {
                return [];
            }
        }

        function saveMessageToStorage(role, text) {
            try {
                const hist = getStoredChatHistory();
                hist.push({ role: role, text: text, time: Date.now() });
                localStorage.setItem(CHAT_SYNC_KEY, JSON.stringify(hist));
            } catch (e) {}
        }

        function renderSynchronizedChat() {
            const container = document.getElementById('chatbotMessages');
            if (!container) return;
            const history = getStoredChatHistory();
            if (!history || history.length === 0) {
                return;
            }

            container.innerHTML = `
                <div class="chat-bubble bot">
                    Hello! Welcome to <strong><?= htmlspecialchars($business_name) ?></strong> Concierge. I can answer questions regarding our services, packages, pricing, service areas, lead times, and booking policies!
                    <div style="margin-top: 6px; font-size: 11.5px; opacity: 0.88; color: #4b5563;">
                        <em>💡 Note: Estimates are guided by AI; official reservations are verified by human staff coordinators.</em>
                    </div>
                    <div class="quick-chips-wrapper">
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('Track my booking status with Reference Number')"><i class="fa-solid fa-magnifying-glass"></i><span>Track booking status (Ref #)</span></button>
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('What packages and pricing do you offer?')"><i class="fa-solid fa-gift"></i><span>What packages do you offer?</span></button>
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('Why do bookings need staff review?')"><i class="fa-solid fa-clipboard-check"></i><span>Why do bookings need staff review?</span></button>
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('How does the booking process work?')"><i class="fa-solid fa-calendar-check"></i><span>How does booking work?</span></button>
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('What are your lead time and downpayment policies?')"><i class="fa-solid fa-clock"></i><span>Lead time &amp; downpayment</span></button>
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('Where is your office and what areas do you service?')"><i class="fa-solid fa-location-dot"></i><span>Office location &amp; service areas</span></button>
                    </div>
                </div>
            `;

            history.forEach(item => {
                const d = document.createElement('div');
                d.className = (item.role === 'user') ? 'chat-bubble user' : 'chat-bubble bot';
                d.innerHTML = formatChatMessage(item.text);
                container.appendChild(d);
            });
            container.scrollTop = container.scrollHeight;
        }

        async function resetChatConversation() {
            // Directly reset without browser modal
            const resetBtn = document.querySelector('.chatbot-header button[onclick="resetChatConversation()"] i');
            if (resetBtn) {
                resetBtn.classList.add('fa-spin');
                setTimeout(() => resetBtn.classList.remove('fa-spin'), 600);
            }

            try {
                await fetch('chatbot.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'clear_history' })
                });
            } catch (e) {}

            localStorage.removeItem(CHAT_SYNC_KEY);
            const container = document.getElementById('chatbotMessages');
            if (container) {
                container.innerHTML = `
                    <div class="chat-bubble bot">
                        Conversation has been reset. You have full message capacity available! How can I assist you with your celebration today?
                        <div style="margin-top: 6px; font-size: 11.5px; opacity: 0.88; color: #4b5563;">
                            <em>💡 Note: Estimates are guided by AI; official reservations are verified by human staff coordinators.</em>
                        </div>
                        <div class="quick-chips-wrapper">
                            <button type="button" class="quick-chip" onclick="sendQuickPrompt('Track my booking status with Reference Number')"><i class="fa-solid fa-magnifying-glass"></i><span>Track booking status (Ref #)</span></button>
                            <button type="button" class="quick-chip" onclick="sendQuickPrompt('What packages and pricing do you offer?')"><i class="fa-solid fa-gift"></i><span>What packages do you offer?</span></button>
                            <button type="button" class="quick-chip" onclick="sendQuickPrompt('Why do bookings need staff review?')"><i class="fa-solid fa-clipboard-check"></i><span>Why do bookings need staff review?</span></button>
                            <button type="button" class="quick-chip" onclick="sendQuickPrompt('How does the booking process work?')"><i class="fa-solid fa-calendar-check"></i><span>How does booking work?</span></button>
                            <button type="button" class="quick-chip" onclick="sendQuickPrompt('What are your lead time and downpayment policies?')"><i class="fa-solid fa-clock"></i><span>Lead time &amp; downpayment</span></button>
                            <button type="button" class="quick-chip" onclick="sendQuickPrompt('Where is your office and what areas do you service?')"><i class="fa-solid fa-location-dot"></i><span>Office location &amp; service areas</span></button>
                        </div>
                    </div>
                `;
                container.scrollTop = 0;
            }
            window.dispatchEvent(new StorageEvent('storage', { key: CHAT_SYNC_KEY, newValue: null }));
        }

        window.addEventListener('storage', (e) => {
            if (e.key === CHAT_SYNC_KEY) {
                renderSynchronizedChat();
            }
        });

        // Chatbot Welcome Popup State & Controller
        const CHATBOT_WELCOME_STORAGE_KEY = 'tyoy_chatbot_welcome_dismissed_v1';
        const CHATBOT_WELCOME_DELAY_MS = 2000;

        function showChatWelcomePopup() {
            const popup = document.getElementById('chatbotWelcomePopup');
            const win = document.getElementById('chatbotWindow');
            if (!popup || (win && win.classList.contains('active'))) return;
            try {
                if (localStorage.getItem(CHATBOT_WELCOME_STORAGE_KEY) === '1') {
                    return;
                }
            } catch(e) {}
            popup.classList.remove('hiding');
            popup.classList.add('visible');
        }

        function closeChatWelcomePopup(userInitiated = true) {
            const popup = document.getElementById('chatbotWelcomePopup');
            if (!popup) return;
            popup.classList.add('hiding');
            setTimeout(() => {
                popup.classList.remove('visible', 'hiding');
            }, 200);
            if (userInitiated) {
                try {
                    localStorage.setItem(CHATBOT_WELCOME_STORAGE_KEY, '1');
                } catch(e) {}
            }
        }

        function initChatbotWelcomePopup() {
            setTimeout(showChatWelcomePopup, CHATBOT_WELCOME_DELAY_MS);
        }

        function toggleChatWindow() {
            // Dismiss welcome popup when opening or toggling chat
            closeChatWelcomePopup(true);

            const win = document.getElementById('chatbotWindow');
            if (win) {
                win.classList.toggle('active');
                if (win.classList.contains('active')) {
                    const input = document.getElementById('chatInput');
                    if (input) {
                        input.focus();
                        autoResizeChatInput(input);
                    }
                }
            }
        }

        // Dismiss popup or active chat on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' || e.key === 'Esc') {
                const popup = document.getElementById('chatbotWelcomePopup');
                if (popup && popup.classList.contains('visible')) {
                    closeChatWelcomePopup(true);
                }
                const win = document.getElementById('chatbotWindow');
                if (win && win.classList.contains('active')) {
                    win.classList.remove('active');
                }
            }
        });

        function sendQuickPrompt(text) {
            const input = document.getElementById('chatInput');
            if (!input) return;
            input.value = text;
            autoResizeChatInput(input);
            sendChatMessage(new Event('submit'));
        }

        function streamBotReply(targetElement, fullText, onDone) {
            if (!targetElement) return;
            const container = document.getElementById('chatbotMessages');
            
            // Fast direct render for very short messages
            if (!fullText || fullText.length < 50) {
                targetElement.innerHTML = formatChatMessage(fullText || '');
                if (container) container.scrollTop = container.scrollHeight;
                if (typeof onDone === 'function') onDone();
                return;
            }

            // Progressive word/token streaming for silky-smooth readability
            const words = fullText.split(/(\s+)/);
            let index = 0;
            let displayed = '';
            targetElement.innerHTML = '<span class="typing-cursor"></span>';

            const interval = setInterval(() => {
                if (index < words.length) {
                    // 2 to 3 word tokens per frame for brisk, natural 60fps reading stream
                    const chunk = words.slice(index, index + 3).join('');
                    index += 3;
                    displayed += chunk;
                    targetElement.innerHTML = formatChatMessage(displayed) + '<span class="typing-cursor"></span>';
                    if (container) container.scrollTop = container.scrollHeight;
                } else {
                    clearInterval(interval);
                    targetElement.innerHTML = formatChatMessage(fullText);
                    if (container) container.scrollTop = container.scrollHeight;
                    if (typeof onDone === 'function') onDone();
                }
            }, 14);
        }

        async function sendChatMessage(e) {
            if (e && e.preventDefault) e.preventDefault();
            const input = document.getElementById('chatInput');
            const msg = input.value.trim();
            if (!msg) return;

            const chatMessages = document.getElementById('chatbotMessages');
            
            // Append formatted user message
            const uDiv = document.createElement('div');
            uDiv.className = 'chat-bubble user';
            uDiv.innerHTML = formatChatMessage(msg);
            chatMessages.appendChild(uDiv);
            saveMessageToStorage('user', msg);

            // Reset input and shrink height back
            input.value = '';
            autoResizeChatInput(input);
            chatMessages.scrollTop = chatMessages.scrollHeight;

            // Show typing indicator
            const botDiv = document.createElement('div');
            botDiv.className = 'chat-bubble bot';
            botDiv.innerHTML = '<i class="fa-solid fa-ellipsis fa-fade"></i> Thinking...';
            chatMessages.appendChild(botDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;

            try {
                const res = await fetch('chatbot.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ message: msg })
                });
                const rawText = await res.text();
                let data = null;
                try {
                    data = JSON.parse(rawText);
                } catch (parseErr) {
                    const jsonMatch = rawText.match(/\{[\s\S]*\}/);
                    if (jsonMatch) {
                        try { data = JSON.parse(jsonMatch[0]); } catch (e) {}
                    }
                }
                let replyText = '';
                if (data && (data.reply || data.response)) {
                    replyText = data.reply || data.response;
                } else if (data && data.message) {
                    replyText = data.message;
                } else {
                    replyText = "Thank you for messaging Tyoy Creation Concierge! I can answer questions about our event styling packages, services, date availability, check booking status with your reference number, or guide you on how to book your celebration on our website. How can I help you today?";
                }

                // SAKA LANG LALABAS kapag nag-offline ang chatbot / API quota reached
                if (data && data.is_offline_mode) {
                    const discBar = document.querySelector('.chatbot-disclaimer-bar');
                    if (discBar) {
                        discBar.innerHTML = '<i class="fa-solid fa-cloud-slash" style="color: #f59e0b;"></i> <span><strong>Offline Mode Active:</strong> External API limit reached. Answering from verified local packages &amp; policies.</span>';
                    }
                }

                streamBotReply(botDiv, replyText, () => {
                    saveMessageToStorage('bot', replyText);
                });
            } catch (err) {
                const fallbackText = "Thank you for messaging Tyoy Creation Concierge! I can answer questions about our event styling packages, services, date availability, check booking status with your reference number, or guide you on how to book your celebration on our website. How can I help you today?";
                streamBotReply(botDiv, fallbackText, () => {
                    saveMessageToStorage('bot', fallbackText);
                });
            }
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        // Mobile Navigation Toggle for Booking
        function toggleBookingMobileNav(force) {
            const nav = document.getElementById('bookingNavLinks');
            const toggle = document.getElementById('bookingNavToggle');
            const backdrop = document.getElementById('bookingNavBackdrop');
            if (!nav) return;
            const shouldOpen = (typeof force === 'boolean') ? force : !nav.classList.contains('nav-open');
            if (shouldOpen) {
                nav.classList.add('nav-open');
                if (backdrop) backdrop.classList.add('active');
                if (toggle) toggle.innerHTML = '<i class="fa-solid fa-xmark"></i>';
                document.body.style.overflow = 'hidden';
            } else {
                nav.classList.remove('nav-open');
                if (backdrop) backdrop.classList.remove('active');
                if (toggle) toggle.innerHTML = '<i class="fa-solid fa-bars"></i>';
                document.body.style.overflow = '';
            }
        }

        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) {
                toggleBookingMobileNav(false);
            }
        });

        window.addEventListener('DOMContentLoaded', () => {
            renderSynchronizedChat();
            checkCookieConsent();
            initChatbotWelcomePopup();
        });
    </script>
    <script src="assets/app_speed.js?v=<?= filemtime(__DIR__ . '/assets/app_speed.js') ?>"></script>
</body>
</html>