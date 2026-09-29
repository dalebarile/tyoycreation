<?php
require_once __DIR__ . '/db.php';

$business_name = get_setting($conn, 'business_name', 'Tyoy Creation');
$business_tagline = get_setting($conn, 'business_tagline', 'TURNING YOUR MOMENTS INTO Unforgettable Events');
$contact_phone = get_setting($conn, 'contact_phone', '+63 912 345 6789');
$contact_email = get_setting($conn, 'contact_email', 'contact@tyoycreation.com');

// Database-driven pricing packages
$pricing_theme = get_packages_pricing($conn, 'theme_party');
$pricing_wedding = get_packages_pricing($conn, 'wedding');

// Calculate minimum booking dates per event type via db.php
$wedding_calc = calculate_earliest_event_date('Weddings');
$kids_calc = calculate_earliest_event_date('Kids Party');
$min_wedding_date = $wedding_calc['min_date']->format('Y-m-d');
$min_wedding_formatted = $wedding_calc['min_date']->format('F j, Y');
$min_kids_date = $kids_calc['min_date']->format('Y-m-d');
$min_kids_formatted = $kids_calc['min_date']->format('F j, Y');

// Query param preselection if user clicked from modal or landing page
$requested_type = trim($_GET['event_type'] ?? $_GET['type'] ?? $_GET['event'] ?? '');
$is_type_locked_from_url = !empty($requested_type);
if (stripos($requested_type, 'wedding') !== false) {
    $initial_type = 'Weddings';
    $initial_family = 'wedding';
    $initial_min_date = $min_wedding_date;
    $initial_min_formatted = $min_wedding_formatted;
    $initial_notice_label = '6 months';
} else {
    $initial_type = 'Kids Party';
    $initial_family = 'theme_party';
    $initial_min_date = $min_kids_date;
    $initial_min_formatted = $min_kids_formatted;
    $initial_notice_label = '14 days';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book an Event - <?= htmlspecialchars($business_name) ?></title>
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- React 18 Engine (Local Vendor Files) -->
    <script src="assets/vendor/react.min.js"></script>
    <script src="assets/vendor/react-dom.min.js"></script>
    <script src="assets/vendor/babel.min.js"></script>
    <style>
        :root {
            --primary: #364735;
            --primary-hover: #2b392a;
            --primary-dark: #232f22;
            --primary-light: #eef2ee;
            --accent-gold: #d97706;
            --border-color: #e5e7eb;
            --bg-page: #f8faf8;
            --text-main: #1f2937;
            --text-muted: #6b7280;
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
            height: 42px;
            width: auto;
            border-radius: 6px;
            background: #ffffff;
            padding: 2px;
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

        /* Booking Header Banner */
        .booking-hero {
            background: linear-gradient(135deg, #1b261b 0%, #293828 50%, #1a251a 100%) !important;
            color: #ffffff !important;
            padding: 54px 24px 68px 24px;
            text-align: center;
            position: relative;
            box-shadow: inset 0 -1px 0 rgba(255, 255, 255, 0.1);
        }

        .booking-hero h1,
        .booking-hero .hero-main-title {
            color: #ffffff !important;
            font-size: 36px;
            font-weight: 800;
            margin: 0 0 14px 0;
            letter-spacing: -0.02em;
            line-height: 1.25;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
        }

        .booking-hero p,
        .booking-hero .hero-subtitle {
            font-size: 15px;
            color: #e5ede5 !important;
            max-width: 680px;
            margin: 0 auto;
            line-height: 1.65;
            font-weight: 400;
            text-shadow: 0 1px 4px rgba(0, 0, 0, 0.35);
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
            margin: -24px auto 60px auto;
            padding: 0 20px;
            position: relative;
            z-index: 10;
        }

        .booking-grid {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 28px;
            align-items: stretch;
        }

        .booking-right-column {
            height: 100%;
            position: relative;
        }

        @media (max-width: 960px) {
            .booking-grid {
                grid-template-columns: 1fr;
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
        }

        .step-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 14px;
            border-bottom: 2px solid #f3f4f6;
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
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
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
            }
            .booking-container {
                padding-bottom: 90px;
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
            letter-spacing: 0.05em;
            background: rgba(255, 255, 255, 0.18) !important;
            color: #ffffff !important;
            border: 1px solid rgba(255, 255, 255, 0.3) !important;
            padding: 4px 10px;
            border-radius: 9999px;
            display: inline-flex;
            align-items: center;
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
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 15, 0.82);
            backdrop-filter: blur(8px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
        }

        .success-overlay.active {
            display: flex;
        }

        .success-dialog {
            background: #ffffff;
            border-radius: 20px;
            width: 100%;
            max-width: 520px;
            padding: 40px 32px;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
            animation: zoomSuccess 0.25s ease;
        }

        @keyframes zoomSuccess {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .success-icon-wrap {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            background: #dcfce7;
            color: #15803d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin: 0 auto 18px auto;
            border: 2px solid #86efac;
        }

        .ref-badge-display {
            background: #eef2ee;
            border: 1px dashed var(--primary);
            color: var(--primary);
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 0.05em;
            display: inline-block;
            margin: 12px 0 18px 0;
        }

        .btn-done-modal {
            background: var(--primary);
            color: #ffffff;
            border: none;
            padding: 12px 28px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-done-modal:hover {
            background: var(--primary-hover);
        }

        /* Chatbot widget placement */
        .chatbot-widget-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 1500;
        }
        .chatbot-launcher-btn {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            background: #364735;
            color: #ffffff;
            border: 2px solid rgba(255, 255, 255, 0.4);
            box-shadow: 0 8px 24px rgba(54, 71, 53, 0.4);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .chatbot-launcher-btn:hover {
            transform: scale(1.08) rotate(5deg);
            background: #2b392a;
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
            background: #232f22;
            color: white;
            padding: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .chatbot-header-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .chatbot-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #364735;
            border: 1px solid rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: #ffffff;
        }
        .chatbot-messages {
            flex: 1;
            padding: 16px;
            overflow-y: auto;
            background: #fbfcfb;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .chat-msg {
            max-width: 82%;
            padding: 10px 14px;
            border-radius: 14px;
            font-size: 13px;
            line-height: 1.45;
        }
        .chat-msg.bot {
            align-self: flex-start;
            background: #ffffff;
            color: var(--text-main);
            border: 1px solid #e5e7eb;
            border-bottom-left-radius: 4px;
        }
        .chat-msg.user {
            align-self: flex-end;
            background: #364735;
            color: #ffffff;
            border-bottom-right-radius: 4px;
        }
        .chatbot-footer {
            padding: 12px;
            border-top: 1px solid #e5e7eb;
            background: #ffffff;
            display: flex;
            gap: 8px;
            align-items: flex-end;
        }
        .chatbot-footer textarea {
            flex: 1;
            padding: 9px 14px;
            border-radius: 18px;
            border: 1px solid #d1d5db;
            font-size: 13px;
            outline: none;
            resize: none;
            height: 38px;
            box-sizing: border-box;
            font-family: inherit;
        }
        .chatbot-footer button {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #364735;
            color: #ffffff;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body>

    <!-- Header Navigation -->
    <nav class="booking-nav">
        <div class="booking-nav-inner">
            <a href="index.php" class="brand-link">
                <img src="assets/tyoy_logo_cropped.png" alt="<?= htmlspecialchars($business_name) ?>" class="brand-logo-img">
                <span style="font-weight: 700; font-size: 16px;"><?= htmlspecialchars($business_name) ?></span>
            </a>

            <div class="nav-links-right">
                <a href="index.php#home" class="nav-link-btn">
                    <i class="fa-solid fa-house"></i> Home
                </a>
                <a href="index.php#services" class="nav-link-btn">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Services
                </a>
                <a href="index.php#packages" class="nav-link-btn">
                    <i class="fa-solid fa-tags"></i> Packages
                </a>
            </div>
        </div>
    </nav>

    <!-- Banner -->
    <header class="booking-hero" style="background: linear-gradient(135deg, #1b261b 0%, #293828 50%, #1a251a 100%) !important; color: #ffffff !important;">
        <div class="public-badge" style="background: rgba(255, 255, 255, 0.15) !important; color: #ffffff !important; border: 1px solid rgba(255, 255, 255, 0.3) !important;">
            <i class="fa-solid fa-calendar-check" style="color: #facc15 !important;"></i> Public Event Booking &bull; No Account Required
        </div>
        <h1 class="hero-main-title" style="color: #ffffff !important; text-shadow: 0 2px 10px rgba(0,0,0,0.5);">Reserve Your Special Celebration</h1>
        <p class="hero-subtitle" style="color: #e5ede5 !important; text-shadow: 0 1px 4px rgba(0,0,0,0.35);">
            Customize your setup, select preferred services, and view live real-time price calculations. Our team will review your inquiry and confirm availability.
        </p>
    </header>

        <!-- React Booking App Mount Point (Option 2) -->
    <div id="reactBookingRoot"></div>

    <!-- AI Chatbot Floating Concierge Widget -->
    <div class="chatbot-widget-container">
        <button type="button" class="chatbot-launcher-btn" onclick="toggleChatWindow()" aria-label="Open AI Event Concierge">
            <i class="fa-solid fa-comments"></i>
        </button>

        <div class="chatbot-window" id="chatbotWindow">
            <div class="chatbot-header">
                <div class="chatbot-header-info">
                    <div class="chatbot-avatar">
                        <i class="fa-solid fa-sparkles"></i>
                    </div>
                    <div>
                        <div style="font-weight: 700; font-size: 14px;">Event Concierge AI</div>
                        <div style="font-size: 11px; opacity: 0.85;">Here to answer booking &amp; pricing questions</div>
                    </div>
                </div>
                <button type="button" onclick="toggleChatWindow()" style="background: none; border: none; color: white; cursor: pointer; font-size: 18px;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="chatbot-messages" id="chatbotMessages">
                <div class="chat-msg bot">
                    Hello! Welcome to <?= htmlspecialchars($business_name) ?>. Feel free to ask me anything about our packages, setup options, or booking lead times.
                </div>
            </div>

            <form class="chatbot-footer" onsubmit="sendChatMessage(event)">
                <textarea id="chatInput" placeholder="Ask a question..." onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendChatMessage(event);}"></textarea>
                <button type="submit" aria-label="Send Message"><i class="fa-solid fa-paper-plane"></i></button>
            </form>
        </div>
    </div>

    <!-- React Data Configuration from Backend -->
    <script>
        window.BOOKING_CONFIG = {
            csrfToken: <?= json_encode(csrf_token()) ?>,
            businessName: <?= json_encode($business_name) ?>,
            pricingTheme: <?= json_encode($pricing_theme) ?>,
            pricingWedding: <?= json_encode($pricing_wedding) ?>,
            minWeddingDate: <?= json_encode($min_wedding_date) ?>,
            minWeddingFormatted: <?= json_encode($min_wedding_formatted) ?>,
            minKidsDate: <?= json_encode($min_kids_date) ?>,
            minKidsFormatted: <?= json_encode($min_kids_formatted) ?>,
            initialType: <?= json_encode($initial_type) ?>,
            initialFamily: <?= json_encode($initial_family) ?>,
            isTypeLocked: <?= json_encode($is_type_locked_from_url) ?>
        };
    </script>

    <!-- React 18 Embedded Component (Option 2) -->
    <script type="text/babel">
        const { useState, useMemo, useEffect } = React;

        function BookingApp() {
            const config = window.BOOKING_CONFIG || {};

            // Celebration family & event type state
            const [family, setFamily] = useState(config.initialFamily || 'theme_party');
            const [eventType, setEventType] = useState(config.initialType || 'Kids Party');

            // Dynamic lead rules based on active family
            const isWedding = (family === 'wedding');
            const minDateStr = isWedding ? (config.minWeddingDate || '') : (config.minKidsDate || '');
            const minDateFormatted = isWedding ? (config.minWeddingFormatted || '') : (config.minKidsFormatted || '');
            const noticeLabel = isWedding ? '6 months' : '14 days';

            // Form inputs state
            const [clientName, setClientName] = useState('');
            const [clientEmail, setClientEmail] = useState('');
            const [clientPhone, setClientPhone] = useState('');
            const [clientAddress, setClientAddress] = useState('');
            const [eventTitle, setEventTitle] = useState('');
            const [guestCount, setGuestCount] = useState('100');
            const [eventDate, setEventDate] = useState(minDateStr);
            const [eventTime, setEventTime] = useState('14:00');
            const [locationVenue, setLocationVenue] = useState('');
            const [specialNotes, setSpecialNotes] = useState('');

            // Selected packages/addons state: { [itemId]: { id, catKey, catTitle, label, price, value, isRadio } }
            const [selectedItems, setSelectedItems] = useState({});

            // Validation & submission state
            const [errors, setErrors] = useState({});
            const [submitError, setSubmitError] = useState('');
            const [isSubmitting, setIsSubmitting] = useState(false);
            const [confirmedBooking, setConfirmedBooking] = useState(null);

            // Dynamic lock: locked ONLY if user arrived from "Our Services" (preset via URL)
            const isTypeLocked = Boolean(config.isTypeLocked);

            // Switch celebration type (Weddings vs Kids Party)
            const handleSwitchEventType = (newType) => {
                if (isTypeLocked) {
                    return; // Prevent changing event type when locked
                }
                const newFam = newType.toLowerCase().includes('wedding') ? 'wedding' : 'theme_party';
                setEventType(newType);
                setFamily(newFam);
                const newMin = (newFam === 'wedding') ? (config.minWeddingDate || '') : (config.minKidsDate || '');
                if (!eventDate || eventDate < newMin) {
                    setEventDate(newMin);
                }
                // Reset selections when switching family to prevent cross-catalog mismatches
                setSelectedItems({});
            };

            const handleSwitchFamily = (newFam) => {
                if (isTypeLocked) {
                    return; // Prevent changing family when locked
                }
                const newType = (newFam === 'wedding') ? 'Weddings' : 'Kids Party';
                handleSwitchEventType(newType);
            };

            // Compute catering pax capacity limit from selected catering package
            const cateringPaxLimit = useMemo(() => {
                const items = Object.values(selectedItems);
                const cateringItem = items.find(it => 
                    it.catKey === 'catering' || 
                    (it.catTitle && it.catTitle.toLowerCase().includes('catering'))
                );
                if (cateringItem && cateringItem.label) {
                    const match = cateringItem.label.match(/(\d+)\s*pax/i) || cateringItem.label.match(/\d+/);
                    if (match) return parseInt(match[0], 10);
                }
                return null;
            }, [selectedItems]);

            // Additional pax capacity from add-on packages (if any add extra pax)
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

            // Combined total guest limit based on catering service pax
            const totalGuestLimit = useMemo(() => {
                if (cateringPaxLimit) {
                    return cateringPaxLimit + additionalPax;
                }
                return null;
            }, [cateringPaxLimit, additionalPax]);

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
                const isCateringCat = (catKey === 'catering' || (catInfo.category_title && catInfo.category_title.toLowerCase().includes('catering')));
                if (isCateringCat) {
                    const match = item.label.match(/(\d+)\s*pax/i) || item.label.match(/\d+/);
                    if (match) {
                        const newPax = parseInt(match[0], 10);
                        // If selecting (not deselecting), sync guest count if it was empty, default 100, or matched old catering limit
                        if (!selectedItems[itemId]) {
                            setGuestCount(prev => (prev === '' || prev === '100' || (cateringPaxLimit && prev === String(cateringPaxLimit))) ? String(newPax) : prev);
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

            // Real-time reactive price total (adds and subtracts automatically)
            const totalEstimate = useMemo(() => {
                return Object.values(selectedItems).reduce((sum, item) => sum + (item.price || 0), 0);
            }, [selectedItems]);

            // Field validation matching backend strict rules
            const validate = () => {
                const errs = {};
                if (!clientName.trim() || clientName.trim().length < 2 || !/^[a-zA-Z\s\.\-']+$/.test(clientName.trim())) {
                    errs.clientName = 'Please enter a valid Full Name (letters and spaces only, at least 2 characters).';
                }
                if (!clientEmail.trim() || !/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/.test(clientEmail.trim())) {
                    errs.clientEmail = 'Please enter a valid Email Address (e.g. name@gmail.com).';
                }
                const cleanPhone = clientPhone.replace(/[^0-9]/g, '');
                if (/[a-zA-Z]/.test(clientPhone) || cleanPhone.length < 10 || cleanPhone.length > 13) {
                    errs.clientPhone = 'Please enter a valid Contact Number with 10 to 12 digits (e.g. 0917-123-4567, no letters).';
                }
                if (!eventTitle.trim() || eventTitle.trim().length < 3) {
                    errs.eventTitle = 'Please enter a valid Event Title (at least 3 characters).';
                }
                if (!locationVenue.trim() || locationVenue.trim().length < 3) {
                    errs.locationVenue = 'Please enter the Event Venue or Location.';
                }
                if (!eventDate || eventDate < minDateStr) {
                    errs.eventDate = `Selected date must be at least ${noticeLabel} from today (${minDateFormatted} onwards).`;
                }
                const num = parseInt(guestCount, 10);
                if (guestCount === '' || isNaN(num) || num < 1) {
                    errs.guestCount = 'Please enter a valid Expected Guest Count (minimum 1 attendee).';
                } else if (totalGuestLimit && num > totalGuestLimit) {
                    errs.guestCount = `Cannot proceed: Expected guest count (${num}) exceeds your selected Catering Service package limit (${totalGuestLimit} pax). Please adjust your guest count or choose a higher catering package below.`;
                }
                setErrors(errs);
                return Object.keys(errs).length === 0;
            };

            // Form submission via standard POST to booking_submit.php
            const handleSubmit = async (e) => {
                e.preventDefault();
                setSubmitError('');

                if (!validate()) {
                    setSubmitError('Cannot proceed: Please resolve the highlighted issues in the form before submitting.');
                    if (isGuestExceeded || !guestCount || parseInt(guestCount, 10) < 1) {
                        const guestEl = document.getElementById('guest_count_input');
                        if (guestEl) {
                            guestEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            guestEl.focus();
                            return;
                        }
                    }
                    window.scrollTo({ top: 320, behavior: 'smooth' });
                    return;
                }

                setIsSubmitting(true);

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

                    const res = await fetch('booking_submit.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await res.json();

                    if (data.success) {
                        setConfirmedBooking({
                            refNo: data.reference_no,
                            title: data.event_title || eventTitle,
                            client: data.client_name || clientName,
                            date: data.event_date || eventDate
                        });
                    } else {
                        setSubmitError(data.message || 'Submission failed. Please check your inputs.');
                        alert(data.message || 'Submission failed. Please check your inputs.');
                    }
                } catch (err) {
                    setSubmitError('Connection error while saving your booking. Please try again.');
                    alert('Connection error while saving your booking. Please try again.');
                } finally {
                    setIsSubmitting(false);
                }
            };

            // Reset form for submitting another booking
            const handleReset = () => {
                setConfirmedBooking(null);
                setClientName('');
                setClientEmail('');
                setClientPhone('');
                setClientAddress('');
                setEventTitle('');
                setGuestCount('100');
                setEventTime('14:00');
                setLocationVenue('');
                setSpecialNotes('');
                setSelectedItems({});
                setErrors({});
                setSubmitError('');
                if (!config.isTypeLocked) {
                    handleSwitchEventType('Kids Party');
                } else {
                    setSelectedItems({});
                }
                window.scrollTo({ top: 0, behavior: 'smooth' });
            };

            // Active catalog based on family
            const currentCatalog = isWedding ? (config.pricingWedding || {}) : (config.pricingTheme || {});
            const selectedItemsArray = Object.values(selectedItems);

            return (
                <div className="booking-container">
                    <form onSubmit={handleSubmit} noValidate>
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
                                                className={`form-control ${errors.clientPhone ? 'is-invalid' : ''}`}
                                                placeholder="e.g. 0917-123-4567"
                                                value={clientPhone}
                                                onChange={(e) => {
                                                    setClientPhone(e.target.value);
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
                                        <label>Complete Address / City</label>
                                        <input 
                                            type="text" 
                                            className="form-control"
                                            placeholder="e.g. Quezon City, Metro Manila"
                                            value={clientAddress}
                                            onChange={(e) => setClientAddress(e.target.value)}
                                        />
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
                                                onClick={() => {
                                                    if (isTypeLocked) return;
                                                    handleSwitchEventType('Kids Party');
                                                }}
                                                title={isTypeLocked && isWedding ? "Event type is locked to your selected service" : ""}
                                            >
                                                <div className="event-type-icon" style={{ background: '#fef3c7', color: '#d97706' }}>
                                                    <i className="fa-solid fa-cake-candles"></i>
                                                </div>
                                                <div className="event-type-info">
                                                    <div className="event-type-name">Kids Party &amp; Birthdays</div>
                                                    <div className="event-type-meta">Themed Styling, Characters, Food Carts &bull; <strong style={{ color: '#15803d' }}>Min. 14 Days Notice</strong></div>
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
                                                onClick={() => {
                                                    if (isTypeLocked) return;
                                                    handleSwitchEventType('Weddings');
                                                }}
                                                title={isTypeLocked && !isWedding ? "Event type is locked to your selected service" : ""}
                                            >
                                                <div className="event-type-icon" style={{ background: '#fdf2f8', color: '#db2777' }}>
                                                    <i className="fa-solid fa-rings-wedding"></i>
                                                </div>
                                                <div className="event-type-info">
                                                    <div className="event-type-name">Weddings &amp; Milestones</div>
                                                    <div className="event-type-meta">Ceremony Floral, Reception Styling, Coordination &bull; <strong style={{ color: '#b45309' }}>Min. 6 Months Notice</strong></div>
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
                                        <div className="form-group">
                                            <label>Event Target Date <span className="required">*</span></label>
                                            <input 
                                                type="date" 
                                                className={`form-control ${errors.eventDate ? 'is-invalid' : ''}`}
                                                min={minDateStr}
                                                value={eventDate}
                                                onChange={(e) => {
                                                    const val = e.target.value;
                                                    setEventDate(val);
                                                    if (val < minDateStr) {
                                                        setErrors(prev => ({ ...prev, eventDate: `Selected date must be at least ${noticeLabel} from today (${minDateFormatted} onwards).` }));
                                                    } else {
                                                        setErrors(prev => ({ ...prev, eventDate: null }));
                                                    }
                                                }}
                                                required
                                            />
                                            <div className={`lead-time-notice ${isWedding ? 'wedding-notice' : 'kids-notice'}`}>
                                                <i className={`fa-solid ${isWedding ? 'fa-rings-wedding' : 'fa-clock-rotate-left'}`}></i>
                                                <span>
                                                    <strong>{isWedding ? '6-month advance notice required' : '14-day advance notice required'}</strong> for {eventType}. Earliest available date is <strong>{minDateFormatted}</strong>.
                                                </span>
                                            </div>
                                            {errors.eventDate && (
                                                <div className="date-error-badge" style={{ display: 'flex' }}>
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
                                                        <i className={`fa-solid ${isGuestExceeded ? 'fa-triangle-exclamation' : 'fa-users'}`}></i> Max {totalGuestLimit} pax
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
                                                            Catering Package Limit Exceeded ({numGuests} / {totalGuestLimit} pax)
                                                        </strong>
                                                        Your expected guest count (<strong>{numGuests} guests</strong>) exceeds your selected Catering Service package limit (<strong>{totalGuestLimit} pax</strong>).
                                                        <div style={{ color: '#be123c', fontWeight: 700, marginTop: '4px' }}>
                                                            <i className="fa-solid fa-ban"></i> You cannot proceed with this booking unless you adjust the guest count or choose a higher catering package below.
                                                        </div>
                                                    </div>
                                                </div>
                                            ) : errors.guestCount ? (
                                                <div className="field-error-text" style={{ display: 'block' }}>{errors.guestCount}</div>
                                            ) : (
                                                <small style={{ color: '#6b7280', fontSize: '11px', display: 'flex', alignItems: 'center', gap: '4px', marginTop: '4px' }}>
                                                    <i className="fa-solid fa-utensils" style={{ color: totalGuestLimit ? '#15803d' : '#9ca3af' }}></i>
                                                    {totalGuestLimit ? (
                                                        <span>Limit synced with Catering Service (<strong>{totalGuestLimit} pax max</strong>).</span>
                                                    ) : (
                                                        <span>Guest limit syncs with selected Catering Service package below.</span>
                                                    )}
                                                </small>
                                            )}
                                        </div>
                                        <div className="form-group" style={{ marginBottom: 0 }}>
                                            <label>Venue / Location Address <span className="required">*</span></label>
                                            <input 
                                                type="text" 
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

                                    {/* Catalog Family Switcher */}
                                    <div className="family-pill-selector">
                                        {isTypeLocked ? (
                                            <button 
                                                type="button" 
                                                className="family-pill-btn active"
                                                style={{ cursor: 'default' }}
                                            >
                                                <i className={`fa-solid ${isWedding ? 'fa-rings-wedding' : 'fa-cake-candles'}`}></i> 
                                                {isWedding ? 'Wedding Packages' : 'Kids Party Packages'}
                                                <span style={{ fontSize: '11px', background: 'rgba(255,255,255,0.22)', padding: '2px 8px', borderRadius: '12px', marginLeft: '6px' }}>
                                                    <i className="fa-solid fa-lock"></i> Locked to Service
                                                </span>
                                            </button>
                                        ) : (
                                            <>
                                                <button 
                                                    type="button" 
                                                    className={`family-pill-btn ${!isWedding ? 'active' : ''}`}
                                                    onClick={() => handleSwitchFamily('theme_party')}
                                                >
                                                    <i className="fa-solid fa-cake-candles"></i> Kids Party Packages
                                                </button>
                                                <button 
                                                    type="button" 
                                                    className={`family-pill-btn ${isWedding ? 'active' : ''}`}
                                                    onClick={() => handleSwitchFamily('wedding')}
                                                >
                                                    <i className="fa-solid fa-rings-wedding"></i> Wedding Packages
                                                </button>
                                            </>
                                        )}
                                    </div>

                                    {/* Dynamic Catalog Categories */}
                                    <div>
                                        {Object.entries(currentCatalog).map(([catKey, catInfo]) => {
                                            const isCheckbox = (catInfo.type === 'checkbox');
                                            const items = catInfo.items || [];
                                            return (
                                                <div key={catKey} className="catalog-category">
                                                    <div className="catalog-cat-header">
                                                        <div className="catalog-cat-title">
                                                            <i className={`fa-solid ${catInfo.icon || 'fa-circle-dot'}`}></i>
                                                            <span>{catInfo.category_title || catKey}</span>
                                                        </div>
                                                        <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                                                            {(catKey === 'catering' || (catInfo.category_title && catInfo.category_title.toLowerCase().includes('catering'))) && (
                                                                <span style={{ fontSize: '11px', background: '#dcfce7', color: '#15803d', padding: '3px 8px', borderRadius: '12px', fontWeight: 600 }}>
                                                                    <i className="fa-solid fa-users"></i> Sets Guest Limit
                                                                </span>
                                                            )}
                                                            <span className={`badge-type ${isCheckbox ? 'badge-check' : 'badge-radio'}`}>
                                                                {isCheckbox ? 'Pick Any' : 'Pick One'}
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
                                        })}
                                    </div>

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
                                                padding: '10px 12px',
                                                marginBottom: '12px',
                                                color: '#9f1239',
                                                fontSize: '12px',
                                                display: 'flex',
                                                gap: '8px',
                                                alignItems: 'center',
                                                lineHeight: 1.35
                                            }}>
                                                <i className="fa-solid fa-ban" style={{ color: '#e11d48', fontSize: '16px', flexShrink: 0 }}></i>
                                                <div>
                                                    <strong>Cannot Proceed:</strong> Expected guests ({numGuests}) exceeds catering limit ({totalGuestLimit} pax). Please adjust to proceed.
                                                </div>
                                            </div>
                                        )}

                                        <button 
                                            type="submit" 
                                            className="btn-submit-booking"
                                            disabled={isSubmitting || isGuestExceeded}
                                            style={isGuestExceeded ? { opacity: 0.65, cursor: 'not-allowed', background: '#9ca3af', boxShadow: 'none' } : {}}
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
                                const rightCol = document.querySelector('.booking-right-column');
                                if (rightCol) {
                                    rightCol.scrollIntoView({ behavior: 'smooth' });
                                }
                            }}
                        >
                            <span>Review &bull; Submit</span>
                            <i className="fa-solid fa-arrow-down"></i>
                        </button>
                    </div>

                    {/* Success Confirmation Modal */}
                    {confirmedBooking && (
                        <div className="success-overlay active" id="successOverlay">
                            <div className="success-dialog">
                                <div className="success-icon-wrap">
                                    <i className="fa-solid fa-check"></i>
                                </div>
                                <h2 style={{ fontSize: '24px', fontWeight: 800, color: '#111827', margin: '0 0 6px 0' }}>
                                    Booking Inquiry Received!
                                </h2>
                                <p style={{ fontSize: '14px', color: '#6b7280', margin: '0 0 16px 0', lineHeight: 1.5 }}>
                                    Thank you! Your event inquiry has been logged as <strong style={{ color: '#d97706' }}>PENDING</strong> and submitted to the event team for review.
                                </p>

                                <div style={{ fontSize: '12px', fontWeight: 700, color: '#4b5563', textTransform: 'uppercase' }}>
                                    Your Booking Reference Number:
                                </div>
                                <div className="ref-badge-display">
                                    {confirmedBooking.refNo}
                                </div>

                                <div style={{ background: '#f9fafb', border: '1px solid #e5e7eb', borderRadius: '10px', padding: '14px', textAlign: 'left', marginBottom: '24px', fontSize: '13px' }}>
                                    <div style={{ marginBottom: '6px' }}><strong>Event:</strong> <span>{confirmedBooking.title}</span></div>
                                    <div style={{ marginBottom: '6px' }}><strong>Client:</strong> <span>{confirmedBooking.client}</span></div>
                                    <div><strong>Scheduled Date:</strong> <span>{confirmedBooking.date}</span></div>
                                </div>

                                <div style={{ display: 'flex', gap: '12px', justifyContent: 'center', flexWrap: 'wrap' }}>
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
                            </div>
                        </div>
                    )}
                </div>
            );
        }

        // Render React 18 Root
        const rootContainer = document.getElementById('reactBookingRoot');
        if (rootContainer) {
            const root = ReactDOM.createRoot(rootContainer);
            root.render(<BookingApp />);
        }
    </script>

    <!-- Chatbot Widget Scripts -->
    <script>
        function toggleChatWindow() {
            const win = document.getElementById('chatbotWindow');
            if (win) {
                win.classList.toggle('active');
                if (win.classList.contains('active')) {
                    const input = document.getElementById('chatInput');
                    if (input) input.focus();
                }
            }
        }

        async function sendChatMessage(e) {
            e.preventDefault();
            const input = document.getElementById('chatInput');
            const msg = input.value.trim();
            if (!msg) return;

            const chatMessages = document.getElementById('chatbotMessages');
            
            const uDiv = document.createElement('div');
            uDiv.className = 'chat-msg user';
            uDiv.textContent = msg;
            chatMessages.appendChild(uDiv);
            input.value = '';
            chatMessages.scrollTop = chatMessages.scrollHeight;

            const botDiv = document.createElement('div');
            botDiv.className = 'chat-msg bot';
            botDiv.innerHTML = '<i class="fa-solid fa-ellipsis fa-fade"></i> Thinking...';
            chatMessages.appendChild(botDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;

            try {
                const res = await fetch('chatbot.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ message: msg })
                });
                const data = await res.json();
                botDiv.innerHTML = data.reply ? data.reply.replace(/\n/g, '<br>') : (data.message || 'Sorry, I could not process your message right now.');
            } catch (err) {
                botDiv.textContent = 'Our concierge is temporarily unavailable. Please submit your booking form and our team will contact you directly!';
            }
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
    </script>
</body>
</html>