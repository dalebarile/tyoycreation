<?php
// Route admin login when accessed via URL like index.php/loginadmin.php or index.php/ loginadmin.php
$raw_path_info = $_SERVER['PATH_INFO'] ?? '';
$raw_request_uri = $_SERVER['REQUEST_URI'] ?? '';
$decoded_uri = urldecode($raw_request_uri);

if (
    stripos($raw_path_info, 'loginadmin') !== false ||
    preg_match('#index\.php/\s*loginadmin(\.php)?#i', $decoded_uri) ||
    stripos($raw_path_info, 'login.php') !== false ||
    preg_match('#index\.php/\s*login(\.php)?#i', $decoded_uri)
) {
    require __DIR__ . '/loginadmin.php';
    exit;
}

require_once __DIR__ . '/db.php';

$business_name = get_setting($conn, 'business_name', 'Tyoy Creation');
$business_tagline = get_setting($conn, 'business_tagline', 'TURNING YOUR MOMENTS INTO Unforgettable Events');
$contact_phone = get_setting($conn, 'contact_phone', '+63 912 345 6789');
$contact_email = get_setting($conn, 'contact_email', 'contact@tyoycreation.com');

// Live Pricing Packages from Database
$pricing_theme   = get_packages_pricing($conn, 'theme_party');
$pricing_wedding = get_packages_pricing($conn, 'wedding');

// ── Determine which package families are active (have items) ──────────────
$has_kids_party = !empty($pricing_theme);
$has_wedding    = !empty($pricing_wedding);

// Build service cards dynamically from active families
$active_services = [];
if ($has_kids_party) {
    $active_services[] = [
        'modal'       => 'birthday',
        'event_type'  => 'Kids Party',
        'img'         => get_setting($conn, 'media_svc_kids', 'assets/portfolio/svc-kids-party.jpg'),
        'img_key'     => 'media_svc_kids',
        'icon'        => 'fa-cake-candles',
        'title'       => 'Kids Party',
        'desc'        => 'Complete celebration packages — Custom Packages, Theme Party Styling, Character backdrops, Magicians &amp; Hosts, Food Carts, and full Catering Services.',
        'link_label'  => 'View Kids Party Packages',
    ];
}
if ($has_wedding) {
    $active_services[] = [
        'modal'       => 'wedding',
        'event_type'  => 'Weddings',
        'img'         => get_setting($conn, 'media_svc_wedding', 'assets/portfolio/svc-wedding.jpg'),
        'img_key'     => 'media_svc_wedding',
        'icon'        => 'fa-rings-wedding',
        'title'       => 'Weddings &amp; Milestones',
        'desc'        => 'Bespoke wedding packages — Custom Packages, Ceremony &amp; Reception Floral Styling, Entourage arrangements, Sound &amp; Lights, and Full Coordination.',
        'link_label'  => 'View Wedding Packages',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($business_name) ?> - Event Management, Styling &amp; Floral Creations</title>
    <meta name="description" content="Tyoy Creation is a premier event management, event styling, and floral creation business established in 2020. We transform your vision into an unforgettable celebration.">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #364735;
            --primary-hover: #2b392a;
            --primary-dark: #232f22;
            --primary-light: #eef2ee;
            --accent-gold: #d97706;
            --border-color: #e5e7eb;
            --text-primary: #1f2937;
            --text-secondary: #4b5563;
            --text-muted: #6b7280;
        }

        /* Navigation Bar */
        .public-nav {
            background: #364735;
            position: sticky;
            top: 0;
            z-index: 1000;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            padding: 12px 0;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
        }

        .public-nav-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }

        .brand-logo-img {
            height: 44px;
            width: auto;
            border-radius: 6px;
            background: #ffffff;
            padding: 2px;
            display: block;
        }

        .public-nav-links {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .public-nav-links a {
            color: rgba(255, 255, 255, 0.9);
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .public-nav-links a:hover,
        .public-nav-links a.active {
            color: #ffffff;
        }

        .btn-nav-book {
            background: #ffffff !important;
            color: #364735 !important;
            font-weight: 700 !important;
            font-size: 13px !important;
            padding: 9px 20px !important;
            border-radius: 9999px !important;
            text-decoration: none !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15) !important;
            transition: all 0.2s ease !important;
        }

        .btn-nav-book:hover {
            background: #eef2ee !important;
            color: #232f22 !important;
            transform: translateY(-1px);
        }



        /* ===== NEW SECTIONS ADDED FOR BUSINESS ALIGNMENT ===== */

        /* Our Story Section */
        .story-section {
            background: #ffffff;
            padding: 80px 0;
            border-bottom: 1px solid #edf2ed;
        }
        .story-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            align-items: center;
        }
        .story-img-wrap {
            position: relative;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 16px 48px rgba(54,71,53,0.14);
        }
        .story-img-wrap img {
            width: 100%;
            height: 420px;
            object-fit: cover;
            display: block;
        }
        .story-img-badge {
            position: absolute;
            bottom: 20px;
            left: 20px;
            background: #364735;
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            padding: 8px 16px;
            border-radius: 9999px;
            letter-spacing: 0.04em;
        }
        .story-text-col .hero-tag {
            margin-bottom: 14px;
            display: inline-block;
        }
        .story-milestones {
            display: flex;
            gap: 32px;
            margin-top: 28px;
            flex-wrap: wrap;
        }
        .story-milestone {
            text-align: center;
            min-width: 80px;
        }
        .story-milestone-num {
            font-size: 30px;
            font-weight: 800;
            color: #364735;
            display: block;
        }
        .story-milestone-label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 600;
            margin-top: 2px;
        }

        /* Services - 3-pillar layout */
        .services-3-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 28px;
            margin-top: 40px;
        }
        .service-pillar-card {
            background: #ffffff;
            border-radius: 18px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
            box-shadow: 0 6px 20px rgba(0,0,0,0.04);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
        }
        .service-pillar-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 16px 36px rgba(54,71,53,0.12);
            border-color: #b0c4ae;
        }
        .service-pillar-header {
            background: linear-gradient(135deg, #364735 0%, #2b392a 100%);
            padding: 32px 28px 24px;
            color: #ffffff;
        }
        .service-pillar-icon {
            width: 52px;
            height: 52px;
            background: rgba(255,255,255,0.15);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 16px;
        }
        .service-pillar-title {
            font-size: 20px;
            font-weight: 800;
            color: #ffffff;
            margin: 0 0 8px 0;
        }
        .service-pillar-tagline {
            font-size: 13px;
            color: rgba(255,255,255,0.78);
            margin: 0;
            line-height: 1.5;
        }
        .service-pillar-body {
            padding: 24px 28px;
            flex: 1;
        }
        .service-pillar-list {
            list-style: none;
            padding: 0;
            margin: 0 0 20px 0;
        }
        .service-pillar-list li {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 13px;
            color: #4b5563;
            padding: 6px 0;
            border-bottom: 1px solid #f3f4f6;
            line-height: 1.4;
        }
        .service-pillar-list li:last-child { border-bottom: none; }
        .service-pillar-list li i {
            color: #364735;
            font-size: 11px;
            margin-top: 4px;
            flex-shrink: 0;
        }
        .service-pillar-action {
            display: block;
            text-align: center;
            background: #eef2ee;
            color: #364735;
            font-size: 13px;
            font-weight: 700;
            padding: 10px 16px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .service-pillar-action:hover {
            background: #364735;
            color: #ffffff;
        }

        /* Why Choose Tyoy Creation */
        .why-section {
            background: linear-gradient(135deg, #232f22 0%, #364735 100%);
            padding: 80px 0;
            color: #ffffff;
        }
        .why-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 32px;
            margin-top: 48px;
        }
        .why-card {
            text-align: center;
            padding: 28px 20px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 16px;
            transition: all 0.3s;
        }
        .why-card:hover {
            background: rgba(255,255,255,0.12);
            transform: translateY(-4px);
        }
        .why-icon {
            width: 56px;
            height: 56px;
            background: rgba(255,255,255,0.12);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: #c8d9c7;
            margin: 0 auto 16px;
        }
        .why-title {
            font-size: 16px;
            font-weight: 800;
            color: #ffffff;
            margin: 0 0 8px 0;
        }
        .why-desc {
            font-size: 13px;
            color: rgba(255,255,255,0.72);
            line-height: 1.55;
            margin: 0;
        }

        /* Floral Creation Section */
        .floral-section {
            background: #fdfaf6;
            padding: 80px 0;
            border-top: 1px solid #f0e8dc;
            border-bottom: 1px solid #f0e8dc;
        }
        .floral-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            align-items: center;
        }
        .floral-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 20px 0 28px;
        }
        .floral-chip {
            background: #fff7ed;
            color: #92400e;
            border: 1px solid #fed7aa;
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .floral-img-mosaic {
            display: flex;
            gap: 12px;
            align-items: stretch;
            min-height: 380px;
        }
        .floral-mosaic-item {
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
            flex-shrink: 0;
        }
        .floral-mosaic-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform 0.4s;
        }
        .floral-mosaic-item:hover img { transform: scale(1.04); }
        .floral-mosaic-item.tall {
            flex: 1;
        }
        .floral-col-stack {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .floral-col-stack .floral-mosaic-item {
            flex: 1;
        }
        .floral-col-stack .floral-mosaic-item img {
            height: 100%;
            min-height: 160px;
        }


        /* Mission & Vision */
        .mv-section {
            background: #ffffff;
            padding: 80px 0;
        }
        .mv-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 32px;
            margin-top: 40px;
        }
        .mv-card {
            border-radius: 18px;
            overflow: hidden;
            position: relative;
        }
        .mv-card-mission {
            background: linear-gradient(140deg, #364735 0%, #1f291e 100%);
            color: #ffffff;
            padding: 40px;
        }
        .mv-card-vision {
            background: linear-gradient(140deg, #fdfaf6 0%, #f5ede0 100%);
            border: 1px solid #f0e8dc;
            padding: 40px;
        }
        .mv-label {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .mv-card-mission .mv-label { color: rgba(255,255,255,0.65); }
        .mv-card-vision .mv-label { color: #92400e; }
        .mv-card-mission .mv-text {
            font-size: 15px;
            color: rgba(255,255,255,0.90);
            line-height: 1.75;
            font-style: italic;
            margin: 0;
        }
        .mv-card-vision .mv-text {
            font-size: 15px;
            color: #374151;
            line-height: 1.75;
            font-style: italic;
            margin: 0;
        }

        /* CTA Booking Section */
        .cta-section {
            background: linear-gradient(135deg, #1f291e 0%, #364735 60%, #455a44 100%);
            padding: 80px 0;
            text-align: center;
            color: #ffffff;
            position: relative;
            overflow: hidden;
        }
        .cta-section::before {
            content: '';
            position: absolute;
            top: -80px; right: -80px;
            width: 300px; height: 300px;
            background: rgba(255,255,255,0.04);
            border-radius: 50%;
        }
        .cta-section::after {
            content: '';
            position: absolute;
            bottom: -60px; left: -60px;
            width: 200px; height: 200px;
            background: rgba(255,255,255,0.03);
            border-radius: 50%;
        }
        .cta-title {
            font-size: 36px;
            font-weight: 800;
            color: #ffffff;
            margin: 0 0 16px 0;
            position: relative;
        }
        .cta-sub {
            font-size: 16px;
            color: rgba(255,255,255,0.78);
            max-width: 540px;
            margin: 0 auto 36px;
            line-height: 1.65;
            position: relative;
        }
        .cta-buttons {
            display: flex;
            justify-content: center;
            gap: 16px;
            flex-wrap: wrap;
            position: relative;
        }
        .btn-cta-primary {
            background: #ffffff;
            color: #364735;
            font-size: 15px;
            font-weight: 800;
            padding: 14px 32px;
            border-radius: 9999px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.2);
            transition: all 0.2s;
        }
        .btn-cta-primary:hover {
            background: #eef2ee;
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.25);
        }
        .btn-cta-secondary {
            background: rgba(255,255,255,0.12);
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            padding: 14px 32px;
            border-radius: 9999px;
            text-decoration: none;
            border: 1px solid rgba(255,255,255,0.25);
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
        }
        .btn-cta-secondary:hover {
            background: rgba(255,255,255,0.22);
            transform: translateY(-2px);
        }

        /* Packages Section Cards */
        .packages-section {
            background: #fafbfa;
            padding: 70px 0;
            border-top: 1px solid #edf2ed;
            border-bottom: 1px solid #edf2ed;
        }

        .packages-category-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 28px;
            max-width: 960px;
            margin: 0 auto;
        }

        .category-card {
            background: #ffffff;
            border-radius: 18px;
            overflow: hidden;
            border: 1px solid var(--border-color);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .category-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 16px 36px rgba(0, 0, 0, 0.09);
            border-color: #cbd5e1;
        }

        .category-card-img {
            width: 100%;
            height: 220px;
            object-fit: cover;
            display: block;
        }

        .category-card-body {
            padding: 24px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .category-badge-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #eef2ee;
            color: #364735;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 12px;
            align-self: flex-start;
        }

        .category-card-title {
            font-size: 22px;
            font-weight: 800;
            color: #111827;
            margin: 0 0 10px 0;
        }

        .category-card-desc {
            font-size: 14px;
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 20px;
            flex: 1;
        }

        .category-card-action {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-top: 16px;
            border-top: 1px solid #f3f4f6;
        }

        .btn-open-category-modal {
            background: #364735;
            color: #ffffff;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .btn-open-category-modal:hover {
            background: #2b392a;
            transform: translateY(-1px);
        }

        /* VIEW-ONLY Package Modal Styles */
        .package-view-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 15, 0.82);
            backdrop-filter: blur(6px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
        }

        .package-view-overlay.active {
            display: flex;
        }

        .package-view-modal {
            background: #ffffff;
            border-radius: 20px;
            width: 100%;
            max-width: 900px;
            max-height: 90vh;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: modalFadeIn 0.25s ease;
            position: relative;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.97) translateY(10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        .package-view-header {
            background: linear-gradient(135deg, #2b392a 0%, #1f291e 100%);
            color: #ffffff;
            padding: 24px 28px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
        }

        .package-view-header h2 {
            font-size: 22px;
            font-weight: 800;
            margin: 0 0 6px 0;
            color: #ffffff !important;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .package-view-header p {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.82);
            margin: 0;
        }

        .view-only-pill {
            background: #fef3c7;
            color: #92400e;
            font-size: 11px;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .modal-close-icon {
            background: rgba(255, 255, 255, 0.15);
            border: none;
            color: #ffffff;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            cursor: pointer;
            transition: all 0.2s;
            flex-shrink: 0;
        }

        .modal-close-icon:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.05);
        }

        .package-view-body {
            padding: 24px 28px;
            overflow-y: auto;
            flex: 1;
            background: #fafbfa;
        }

        /* View-only package cards */
        .view-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }

        .view-pkg-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            position: relative;
        }

        .view-pkg-card:hover {
            border-color: #364735;
            box-shadow: 0 8px 20px rgba(54, 71, 53, 0.08);
        }

        .view-pkg-name {
            font-size: 16px;
            font-weight: 700;
            color: #111827;
            margin: 0 0 8px 0;
        }

        .view-pkg-price {
            font-size: 22px;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 12px;
        }

        .view-pkg-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            color: #047857;
            background: #d1fae5;
            padding: 2px 8px;
            border-radius: 4px;
            margin-bottom: 12px;
            text-transform: uppercase;
        }

        .view-inclusions-list {
            list-style: none;
            padding: 0;
            margin: 0 0 16px 0;
            flex: 1;
            font-size: 13px;
            color: #4b5563;
        }

        .view-inclusions-list li {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin-bottom: 8px;
            line-height: 1.4;
        }

        .view-inclusions-list li i {
            color: var(--primary);
            font-size: 12px;
            margin-top: 3px;
            flex-shrink: 0;
        }

        /* Equipment & Addons section inside modal */
        .view-addon-section {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 16px;
        }

        .view-addon-header {
            font-size: 15px;
            font-weight: 700;
            color: var(--primary);
            margin: 0 0 12px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .view-addon-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }

        .view-addon-item {
            background: #fafbfa;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 10px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
        }

        .view-addon-item strong {
            color: #1f2937;
        }

        .view-addon-price {
            font-weight: 700;
            color: var(--primary);
            white-space: nowrap;
        }

        .package-view-footer {
            background: #ffffff;
            padding: 16px 28px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .package-view-footer-info {
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.4;
        }

        .modal-action-buttons {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-modal-close {
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #d1d5db;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-modal-close:hover {
            background: #e5e7eb;
        }

        .btn-modal-book-now {
            background: #364735;
            color: #ffffff;
            border: none;
            padding: 9px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 8px rgba(54, 71, 53, 0.25);
            transition: all 0.2s;
        }

        .btn-modal-book-now:hover {
            background: #2b392a;
            transform: translateY(-1px);
        }

        /* Event Choice Modal for Book Now */
        .event-choice-option-card {
            background: #ffffff;
            border: 2px solid #e5e7eb;
            border-radius: 16px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s ease;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
            text-decoration: none;
            color: inherit;
        }
        .event-choice-option-card:hover {
            border-color: #364735;
            transform: translateY(-4px);
            box-shadow: 0 12px 28px rgba(54, 71, 53, 0.15);
        }
        .choice-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            margin-bottom: 14px;
        }
        .event-choice-option-card h3 {
            font-size: 18px;
            font-weight: 700;
            color: #1f2937;
            margin: 0 0 8px 0;
        }
        .event-choice-option-card p {
            font-size: 13px;
            color: #6b7280;
            line-height: 1.5;
            margin: 0 0 16px 0;
            flex-grow: 1;
        }
        .lead-badge {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 5px 12px;
            border-radius: 9999px;
            background: #eef2ee;
            color: #364735;
            border: 1px solid #d1dcd0;
            margin-bottom: 16px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-choice-go {
            width: 100%;
            box-sizing: border-box;
            background: #364735;
            color: #ffffff !important;
            font-weight: 700;
            font-size: 13px;
            padding: 11px 16px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.2s;
        }
        .btn-choice-go:hover {
            background: #2b392a;
        }

        /* Floating AI Chatbot styles */
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
        .chat-bubble {
            max-width: 84%;
            padding: 10px 14px;
            border-radius: 14px;
            font-size: 13px;
            line-height: 1.45;
        }
        .chat-bubble.bot {
            align-self: flex-start;
            background: #ffffff;
            color: var(--text-primary);
            border: 1px solid #e5e7eb;
            border-bottom-left-radius: 4px;
        }
        .chat-bubble.user {
            align-self: flex-end;
            background: #364735;
            color: #ffffff;
            border-bottom-right-radius: 4px;
        }
        .quick-chips-wrapper {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 10px;
        }
        .quick-chip {
            background: #f3f6f3;
            border: 1px solid #dce5dc;
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 11px;
            font-weight: 600;
            color: #364735;
            cursor: pointer;
            text-align: left;
            transition: all 0.15s;
        }
        .quick-chip:hover {
            background: #e4ebe4;
            border-color: #364735;
        }
        .chatbot-footer-wrapper {
            background: #ffffff;
            border-top: 1px solid #e5e7eb;
        }
        .chatbot-footer {
            padding: 10px 14px;
            display: flex;
            gap: 8px;
            align-items: flex-end;
        }
        .chatbot-footer textarea {
            flex: 1;
            padding: 9px 12px;
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
            color: white;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
    </style>
</head>
<body>

    <!-- Public Navigation Bar -->
    <nav class="public-nav">
        <div class="public-container public-nav-content">
            <a href="#home" style="display: flex; align-items: center; text-decoration: none;">
                <img src="assets/tyoy_logo_cropped.png" alt="<?= htmlspecialchars($business_name) ?>" class="brand-logo-img">
            </a>

            <div class="public-nav-links">
                <a href="#home" class="active">Home</a>
                <a href="#about-us">About</a>
                <a href="#services">Services</a>
                <a href="#portfolio">Portfolio</a>
                <a href="#contact">Contact</a>
                <a href="booking.php" class="btn-nav-book">
                    <i class="fa-solid fa-calendar-plus"></i> Book Now
                </a>
            </div>
        </div>
    </nav>

    <!-- Hero Header -->
    <?php $hero_bg_img = get_setting($conn, 'media_hero_bg', 'assets/tyoy_creation_banner.jpg'); ?>
    <header class="hero-section" id="home" style="background-image: linear-gradient(90deg, rgba(20, 32, 21, 0.88) 0%, rgba(20, 32, 21, 0.72) 48%, rgba(20, 32, 21, 0.28) 78%, rgba(20, 32, 21, 0.12) 100%), url('<?= htmlspecialchars($hero_bg_img) ?>');">
        <div class="public-container">
            <div class="hero-content">
                <span class="hero-tag">EVENT MANAGEMENT &bull; EVENT STYLING &bull; FLORAL CREATIONS</span>
                <h1 class="hero-title">
                    <span class="hero-brand-callout"><?= htmlspecialchars($business_name) ?></span>
                    <?= htmlspecialchars($business_tagline) ?>
                </h1>
                <p class="hero-desc">
                    <?= htmlspecialchars(get_setting($conn, 'hero_subtitle', 'Premier event management, creative event styling, and personalized floral creations — crafted with precision, passion, and unwavering dedication since 2020.')) ?>
                </p>

                <div class="hero-actions" style="margin-top: 28px;">
                    <a href="booking.php" class="btn-primary-hero" style="text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-calendar-plus"></i> Book an Event
                    </a>
                    <a href="#services" class="btn-secondary-hero" style="text-decoration: none;">
                        Explore Services
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Our Story Section -->
    <section class="story-section" id="about-us">
        <div class="public-container">
            <div style="max-width: 760px; margin: 0 auto;">
                <div style="text-align: center; margin-bottom: 24px;">
                    <span class="hero-tag" style="color: var(--primary); background: var(--primary-light); border-color: var(--border-color);">OUR STORY</span>
                    <h2 class="section-title" style="margin: 14px 0 0;">From Floral Roots to Full-Scale Event Management</h2>
                </div>
                <div style="text-align: justify; text-justify: inter-word; color: #4b5563; line-height: 1.85; font-size: 15px;">
                    <?php
                    $saved_story = get_setting($conn, 'about_story', '');
                    if (!empty($saved_story)) {
                        $paragraphs = explode("\n\n", str_replace(["\r\n", "\r"], "\n", $saved_story));
                        foreach ($paragraphs as $para) {
                            $para = trim($para);
                            if (!empty($para)) {
                                echo '<p style="margin-bottom: 16px;">' . nl2br(htmlspecialchars($para)) . '</p>';
                            }
                        }
                    } else {
                        echo '<p style="margin-bottom: 16px;">' . htmlspecialchars($business_name) . ' was founded in 2020 with a deep passion for flowers and wedding décor. What began as a boutique floral arrangement service quickly grew into a full-service event management and styling business.</p>';
                        echo '<p style="margin-bottom: 16px;">Today, we design and execute weddings, kids\' parties, themed celebrations, and milestone events — delivering creative event styling, reliable day-of coordination, and personalized floral creations that speak to every client\'s unique story.</p>';
                        echo '<p style="margin-bottom: 32px;">Every event we handle is built on our core values: <strong>creativity, professionalism, efficiency, accuracy,</strong> and <strong>personalized service</strong>.</p>';
                    }
                    ?>
                </div>
                <div class="story-milestones" style="justify-content: center;">
                    <div class="story-milestone">
                        <span class="story-milestone-num">2020</span>
                        <div class="story-milestone-label">Year Founded</div>
                    </div>
                    <div class="story-milestone">
                        <span class="story-milestone-num"><?= count($active_services) ?></span>
                        <div class="story-milestone-label">Active Services</div>
                    </div>
                    <div class="story-milestone">
                        <span class="story-milestone-num">100%</span>
                        <div class="story-milestone-label">Client Dedication</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section class="section" id="services">
        <div class="public-container">
            <div class="section-header">
                <h2 class="section-title">Our Services</h2>
                <p class="section-subtitle">Bespoke event packages delivered with creativity, precision, and unwavering professionalism.</p>
            </div>

            <?php if (!empty($active_services)): ?>
            <div class="services-grid" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); max-width: 900px; margin: 0 auto;">
                <?php foreach ($active_services as $svc): ?>
                <div class="service-card" style="display: flex; flex-direction: column;">
                    <div style="cursor: pointer;" onclick="openPackageModal('<?= htmlspecialchars($svc['modal']) ?>')">
                        <img src="<?= htmlspecialchars(get_setting($conn, $svc['img_key'], $svc['img'])) ?>" alt="<?= htmlspecialchars(strip_tags($svc['title'])) ?>" class="service-img" onerror="this.src='<?= htmlspecialchars($svc['img']) ?>'">
                        <div class="service-body">
                            <div class="service-icon-wrap" style="background: #eef2ee; color: #364735;">
                                <i class="fa-solid <?= htmlspecialchars($svc['icon']) ?>"></i>
                            </div>
                            <h3><?= $svc['title'] ?></h3>
                            <p><?= $svc['desc'] ?></p>
                        </div>
                    </div>
                    <div style="padding: 0 24px 20px 24px; margin-top: auto; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; border-top: 1px solid #f3f4f6; padding-top: 14px;">
                        <button type="button" class="service-link" onclick="openPackageModal('<?= htmlspecialchars($svc['modal']) ?>')" style="background: none; border: none; padding: 0; cursor: pointer; font-size: 13px; font-weight: 700; color: #364735;">
                            <?= htmlspecialchars(strip_tags($svc['link_label'])) ?> <i class="fa-solid fa-arrow-right"></i>
                        </button>
                        <a href="booking.php?event_type=<?= urlencode($svc['event_type']) ?>" style="background: #364735; color: #ffffff; padding: 7px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-calendar-check"></i> Book Now
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p style="text-align:center; color: var(--text-muted); padding: 40px 0;">Service packages are being configured. Check back soon!</p>
            <?php endif; ?>
        </div>
    </section>


    <!-- Why Choose Tyoy Creation -->
    <section class="why-section" id="why-us">
        <div class="public-container">
            <div class="section-header">
                <h2 class="section-title" style="color: #ffffff;">Why Choose <?= htmlspecialchars($business_name) ?>?</h2>
                <p class="section-subtitle" style="color: rgba(255,255,255,0.72);">We bring together creativity, structure, and reliability to deliver events that exceed expectations every time.</p>
            </div>
            <div class="why-grid">
                <div class="why-card">
                    <div class="why-icon"><i class="fa-solid fa-palette"></i></div>
                    <h4 class="why-title">Creative Excellence</h4>
                    <p class="why-desc">Innovative designs and fresh ideas tailored to your unique vision and event theme.</p>
                </div>
                <div class="why-card">
                    <div class="why-icon"><i class="fa-solid fa-user-tie"></i></div>
                    <h4 class="why-title">Professionalism</h4>
                    <p class="why-desc">Organized, dependable, and client-focused from the first inquiry through to the last goodbye.</p>
                </div>
                <div class="why-card">
                    <div class="why-icon"><i class="fa-solid fa-gauge-high"></i></div>
                    <h4 class="why-title">Efficiency &amp; Accuracy</h4>
                    <p class="why-desc">Meticulous planning, reliable execution, and razor-sharp attention to every detail.</p>
                </div>
                <div class="why-card">
                    <div class="why-icon"><i class="fa-solid fa-heart"></i></div>
                    <h4 class="why-title">Personalized Service</h4>
                    <p class="why-desc">Every event is uniquely yours. We listen, adapt, and deliver a celebration that truly reflects you.</p>
                </div>
                <div class="why-card">
                    <div class="why-icon"><i class="fa-solid fa-handshake"></i></div>
                    <h4 class="why-title">Trusted Partnerships</h4>
                    <p class="why-desc">A curated network of reliable vendors coordinated seamlessly so you never have to worry.</p>
                </div>
                <div class="why-card">
                    <div class="why-icon"><i class="fa-solid fa-shield-halved"></i></div>
                    <h4 class="why-title">Peace of Mind</h4>
                    <p class="why-desc">On the day that matters most, we handle every challenge — so you can simply enjoy the moment.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Portfolio Section -->
    <section class="section" id="portfolio" style="background: #ffffff;">
        <div class="public-container">
            <div class="section-header">
                <h2 class="section-title">Our Portfolio</h2>
                <p class="section-subtitle">A glimpse of past celebrations handcrafted with love and elegance.</p>
            </div>

            <!-- Filter Pills -->
            <div class="portfolio-filters">
                <button type="button" class="filter-btn active" onclick="filterGallery('all', this)">All</button>
                <button type="button" class="filter-btn" onclick="filterGallery('weddings', this)">Weddings</button>
                <button type="button" class="filter-btn" onclick="filterGallery('birthdays', this)">Kids Party</button>
            </div>

            <?php
            $default_portfolio = [
                [
                    'category' => 'weddings',
                    'img'      => 'assets/portfolio/portfolio-wedding-ceremony.jpg',
                    'title'    => 'Ceremony Floral Arch Styling',
                    'location' => 'Weddings &bull; Church Ceremony Styling'
                ],
                [
                    'category' => 'birthdays',
                    'img'      => 'assets/portfolio/portfolio-jasmine-aladdin.jpg',
                    'title'    => 'Arabian Nights: Jasmine &amp; Aladdin',
                    'location' => 'Kids Party &bull; Character Backdrop'
                ],
                [
                    'category' => 'weddings',
                    'img'      => 'assets/portfolio/portfolio-wedding-couple.jpg',
                    'title'    => 'Garden Wedding Portraits',
                    'location' => 'Weddings &bull; Full Event Styling'
                ],
                [
                    'category' => 'birthdays',
                    'img'      => 'assets/portfolio/portfolio-snowwhite-party.jpg',
                    'title'    => 'Snow White Themed Celebration',
                    'location' => 'Kids Party &bull; Character Backdrop'
                ],
                [
                    'category' => 'birthdays',
                    'img'      => 'assets/portfolio/portfolio-elisha-carparty.jpg',
                    'title'    => 'Vintage Car Themed 1st Birthday',
                    'location' => 'Kids Party &bull; Full Event Styling'
                ],
                [
                    'category' => 'birthdays',
                    'img'      => 'assets/portfolio/portfolio-kiara-magician.jpg',
                    'title'    => 'Circus Magician Entertainment',
                    'location' => 'Kids Party &bull; Hosts &amp; Entertainment'
                ],
                [
                    'category' => 'birthdays',
                    'img'      => 'assets/portfolio/portfolio-agatha-butterfly.jpg',
                    'title'    => 'Butterfly Garden Celebration',
                    'location' => 'Kids Party &bull; Full Event Styling'
                ],
                [
                    'category' => 'birthdays',
                    'img'      => 'assets/portfolio/portfolio-rabbalucia-boho.jpg',
                    'title'    => 'Boho Floral 1st Birthday',
                    'location' => 'Kids Party &bull; Full Event Styling'
                ]
            ];
            $saved_portfolio_json = get_setting($conn, 'portfolio_items', '');
            $portfolio_items = !empty($saved_portfolio_json) ? json_decode($saved_portfolio_json, true) : null;
            if (!is_array($portfolio_items) || empty($portfolio_items)) {
                $portfolio_items = $default_portfolio;
            }
            ?>
            <div class="portfolio-grid">
                <?php foreach ($portfolio_items as $item): ?>
                <div class="portfolio-item" data-category="<?= htmlspecialchars($item['category'] ?? 'weddings') ?>">
                    <img src="<?= htmlspecialchars($item['img'] ?? '') ?>" alt="<?= htmlspecialchars(strip_tags($item['title'] ?? '')) ?>" onerror="this.src='assets/portfolio/portfolio-wedding-ceremony.jpg'">
                    <div class="portfolio-overlay">
                        <div class="portfolio-info">
                            <h4><?= htmlspecialchars($item['title'] ?? '') ?></h4>
                            <span><?= htmlspecialchars($item['location'] ?? '') ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Mission & Vision Section -->
    <?php
    $b_mission = get_setting($conn, 'mission_statement', 'To deliver seamless event management and creative styling that turn visions into unforgettable, stress-free celebrations. Built on efficiency, accuracy, and dedicated support, Tyoy Creation coordinates every detail and manages every partner so our clients can savor every moment with complete peace of mind.');
    $b_vision  = get_setting($conn, 'vision_statement', 'To be a premier and trusted choice in event management and styling, recognized for creating priceless, elevated experiences through creative excellence, reliable service, and flawless execution.');
    ?>
    <section class="mv-section" id="mission-vision">
        <div class="public-container">
            <div class="section-header">
                <h2 class="section-title">Our Mission &amp; Vision</h2>
                <p class="section-subtitle">The principles that guide every event we create and every relationship we build.</p>
            </div>
            <div class="mv-grid">
                <div class="mv-card mv-card-mission">
                    <div class="mv-label"><i class="fa-solid fa-bullseye"></i> Our Mission</div>
                    <p class="mv-text">
                        &ldquo;<?= htmlspecialchars($b_mission) ?>&rdquo;
                    </p>
                </div>
                <div class="mv-card mv-card-vision">
                    <div class="mv-label"><i class="fa-solid fa-eye"></i> Our Vision</div>
                    <p class="mv-text">
                        &ldquo;<?= htmlspecialchars($b_vision) ?>&rdquo;
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Booking CTA Section -->
    <section class="cta-section" id="book-now">
        <div class="public-container">
            <h2 class="cta-title">Ready to Create Your Celebration?</h2>
            <p class="cta-sub">Whether it's a wedding, a kids' party, or a personalized floral arrangement &mdash; we are here to bring your vision to life. Submit an inquiry today and let's start planning.</p>
            <div class="cta-buttons">
                <a href="booking.php" class="btn-cta-primary">
                    <i class="fa-solid fa-calendar-plus"></i> Start Your Booking
                </a>
                <a href="#contact" class="btn-cta-secondary">
                    <i class="fa-solid fa-envelope"></i> Contact Us First
                </a>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section class="section" id="about">
        <div class="public-container">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 48px; align-items: start;">
                <div>
                    <span class="hero-tag" style="color: var(--primary); background: var(--primary-light); border-color: var(--border-color);">ABOUT <?= htmlspecialchars($business_name) ?></span>
                    <h2 class="section-title" style="text-align: left; margin-bottom: 18px;">Event Management &amp; Styling Since 2020</h2>
                    <p style="color: var(--text-secondary); margin-bottom: 16px; line-height: 1.7;">
                        <?= htmlspecialchars($business_name) ?> is a professional event management, styling, and floral creation business. We specialize in transforming your vision into a beautifully executed, stress-free celebration.
                    </p>
                    <p style="color: var(--text-secondary); margin-bottom: 24px; line-height: 1.7;">
                        From intimate weddings to vibrant kids' parties, our team manages every detail &mdash; from the floral arrangements and backdrops to vendor coordination and day-of execution.
                    </p>
                    <div style="display: flex; gap: 32px; flex-wrap: wrap;">
                        <div>
                            <div style="font-size: 32px; font-weight: 800; color: var(--primary);">2020</div>
                            <div style="font-size: 13px; color: var(--text-secondary);">Year Established</div>
                        </div>
                        <div>
                            <div style="font-size: 32px; font-weight: 800; color: var(--primary);">3</div>
                            <div style="font-size: 13px; color: var(--text-secondary);">Core Service Pillars</div>
                        </div>
                        <div>
                            <div style="font-size: 32px; font-weight: 800; color: var(--primary);">100%</div>
                            <div style="font-size: 13px; color: var(--text-secondary);">Client Dedication</div>
                        </div>
                    </div>
                </div>

                <div id="contact" style="background: #ffffff; padding: 36px; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 8px 24px rgba(0,0,0,0.05);">
                    <h3 style="font-size: 20px; font-weight: 700; margin: 0 0 14px 0;">Contact Our Team</h3>
                    <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 20px; line-height: 1.5;">
                        Have questions about our packages or available dates? Reach out directly or click <strong>Book Now</strong> to submit your inquiry online.
                    </p>
                    <div style="display: flex; flex-direction: column; gap: 14px; font-size: 14px;">
                        <div style="display: flex; align-items: center; gap: 12px; color: var(--text-primary);">
                            <i class="fa-solid fa-phone" style="color: var(--primary); width: 20px;"></i>
                            <span><?= htmlspecialchars($contact_phone) ?></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 12px; color: var(--text-primary);">
                            <i class="fa-solid fa-envelope" style="color: var(--primary); width: 20px;"></i>
                            <span><?= htmlspecialchars($contact_email) ?></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 12px; color: var(--text-primary);">
                            <i class="fa-solid fa-location-dot" style="color: var(--primary); width: 20px;"></i>
                            <span><?= htmlspecialchars(get_setting($conn, 'business_address', '123 Grand Ballroom Avenue, Metro Manila, Philippines')) ?></span>
                        </div>
                    </div>
                    <div style="margin-top: 24px; padding-top: 18px; border-top: 1px solid #f3f4f6;">
                        <a href="booking.php" class="btn-open-category-modal" style="text-decoration: none; width: 100%; box-sizing: border-box; justify-content: center; cursor: pointer;">
                            <i class="fa-solid fa-calendar-plus"></i> Start Your Event Inquiry
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer style="background: #232f22; color: #b2c0b1; padding: 48px 0 32px; border-top: 1px solid rgba(255,255,255,0.08); font-size: 14px;">
        <div class="public-container">
            <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 40px; margin-bottom: 36px; text-align: left;">
                <div>
                    <img src="assets/tyoy_logo_cropped.png" alt="<?= htmlspecialchars($business_name) ?>" style="height: 40px; border-radius: 6px; background: #fff; padding: 2px; margin-bottom: 14px; display: block;">
                    <p style="color: rgba(255,255,255,0.55); font-size: 13px; line-height: 1.7; max-width: 280px; margin: 0;">
                        Premier event management, creative styling, and personalized floral creations — crafted with passion since 2020.
                    </p>
                </div>
                <div>
                    <div style="font-size: 12px; font-weight: 800; color: rgba(255,255,255,0.45); text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 14px;">Services</div>
                    <div style="display: flex; flex-direction: column; gap: 9px;">
                        <?php if ($has_kids_party): ?>
                        <a href="#services" onclick="openPackageModal('birthday');" style="color: rgba(255,255,255,0.65); font-size: 13px; text-decoration: none;">Kids Party</a>
                        <?php endif; ?>
                        <?php if ($has_wedding): ?>
                        <a href="#services" onclick="openPackageModal('wedding');" style="color: rgba(255,255,255,0.65); font-size: 13px; text-decoration: none;">Weddings</a>
                        <?php endif; ?>
                        <a href="booking.php" style="color: rgba(255,255,255,0.65); font-size: 13px; text-decoration: none;">Book an Event</a>
                    </div>
                </div>
                <div>
                    <div style="font-size: 12px; font-weight: 800; color: rgba(255,255,255,0.45); text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 14px;">Contact</div>
                    <div style="display: flex; flex-direction: column; gap: 9px;">
                        <a href="mailto:<?= htmlspecialchars($contact_email) ?>" style="color: rgba(255,255,255,0.65); font-size: 13px; text-decoration: none;"><?= htmlspecialchars($contact_email) ?></a>
                        <span style="color: rgba(255,255,255,0.65); font-size: 13px;"><?= htmlspecialchars($contact_phone) ?></span>
                        <span style="color: rgba(255,255,255,0.65); font-size: 13px;"><i class="fa-solid fa-location-dot" style="margin-right:4px;"></i> <?= htmlspecialchars(get_setting($conn, 'business_address', '123 Grand Ballroom Avenue, Metro Manila, Philippines')) ?></span>
                        <a href="booking.php" style="color: #8fa68e; font-size: 13px; font-weight: 700; text-decoration: none;"><i class="fa-solid fa-calendar-plus" style="margin-right:4px;"></i> Book an Event</a>
                    </div>
                </div>
            </div>
            <div style="border-top: 1px solid rgba(255,255,255,0.08); padding-top: 20px; text-align: center;">
                <p style="margin: 0; font-size: 12px; color: rgba(255,255,255,0.4);">&copy; <?= date('Y') ?> <?= htmlspecialchars($business_name) ?>. Event Management, Styling &amp; Floral Creations. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- ==========================================================================
       VIEW-ONLY MODAL 1: BIRTHDAY PACKAGES
       ========================================================================== -->
    <div class="package-view-overlay" id="birthdayPackageModal" onclick="handleOverlayClick(event, this)">
        <div class="package-view-modal">
            
            <div class="package-view-header">
                <div>
                    <span class="view-only-pill"><i class="fa-solid fa-eye"></i> View Only</span>
                    <h2><i class="fa-solid fa-cake-candles"></i> Kids Party Packages &amp; Inclusions</h2>
                    <p>Live package prices from our booking system. To customize and book, click Book Now below.</p>
                </div>
                <button type="button" class="modal-close-icon" onclick="closePackageModal('birthdayPackageModal')" aria-label="Close">&times;</button>
            </div>

            <div class="package-view-body">
                
                <h3 style="font-size: 17px; font-weight: 800; color: #1f2937; margin: 0 0 14px 0;">
                    Core Styling Packages
                </h3>

                <!-- Featured Birthday Styling Packages Grid -->
                <div class="view-cards-grid">
                    <?php 
                    $tp_items = $pricing_theme['theme_styling']['items'] ?? [];
                    foreach ($tp_items as $item): 
                        $price = (int)$item['price'];
                        $price_text = ($price > 0) ? '&#8369;' . number_format($price) : 'Custom Quote';
                    ?>
                        <div class="view-pkg-card">
                            <span class="view-pkg-badge">Theme Styling</span>
                            <h4 class="view-pkg-name"><?= htmlspecialchars($item['label']) ?></h4>
                            <div class="view-pkg-price"><?= $price_text ?></div>
                            <ul class="view-inclusions-list">
                                <li><i class="fa-solid fa-check"></i> <span>Custom Thematic Backdrop Setup</span></li>
                                <li><i class="fa-solid fa-check"></i> <span>Organic Balloon Garland &amp; Styling</span></li>
                                <li><i class="fa-solid fa-check"></i> <span>Themed Centerpieces &amp; Cake Table</span></li>
                                <li><i class="fa-solid fa-check"></i> <span>Setup, Ingress &amp; Egress Coordination</span></li>
                            </ul>
                            <div style="font-size: 11px; color: var(--text-muted); font-style: italic;">View Only &bull; Selectable on Booking Page</div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Additional Setup & Equipment Options (Dynamically Loaded from Database) -->
                <?php 
                $addon_cat_icons = [
                    'sound_lights'  => 'fa-music',
                    'entertainment' => 'fa-masks-theater',
                    'food_carts'    => 'fa-cart-flatbed',
                    'photo_video'   => 'fa-video',
                ];
                $has_other_cats = false;
                foreach ($pricing_theme as $c_k => $c_v) {
                    if ($c_k !== 'theme_styling' && !empty($c_v['items'])) {
                        $has_other_cats = true;
                        break;
                    }
                }
                if ($has_other_cats):
                ?>
                <h3 style="font-size: 17px; font-weight: 800; color: #1f2937; margin: 24px 0 14px 0;">
                    Available Setup, Equipment &amp; Add-ons
                </h3>

                <?php
                foreach ($pricing_theme as $c_key => $c_val):
                    if ($c_key === 'theme_styling') continue;
                    $c_items = $c_val['items'] ?? [];
                    if (empty($c_items)) continue;
                    $c_title = $c_val['category_title'] ?? ucwords(str_replace('_', ' ', $c_key));
                    $c_icon = !empty($c_val['icon']) ? $c_val['icon'] : ($addon_cat_icons[$c_key] ?? 'fa-asterisk');
                ?>
                    <div class="view-addon-section">
                        <div class="view-addon-header">
                            <i class="fa-solid <?= $c_icon ?>"></i> <?= htmlspecialchars($c_title) ?>
                        </div>
                        <div class="view-addon-grid">
                            <?php foreach ($c_items as $c_item): ?>
                                <div class="view-addon-item">
                                    <span><?= htmlspecialchars($c_item['label']) ?></span>
                                    <span class="view-addon-price">&#8369;<?= number_format((int)$c_item['price']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="package-view-footer">
                <div class="package-view-footer-info">
                    <i class="fa-solid fa-circle-info" style="color: var(--primary);"></i>
                    This modal is strictly for viewing package details. To select options and reserve a date, proceed to the Booking Page.
                </div>
                <div class="modal-action-buttons">
                    <button type="button" class="btn-modal-close" onclick="closePackageModal('birthdayPackageModal')">Close</button>
                    <a href="booking.php?event_type=Kids+Party" class="btn-modal-book-now">
                        <i class="fa-solid fa-calendar-plus"></i> Book Now
                    </a>
                </div>
            </div>

        </div>
    </div>

    <!-- ==========================================================================
       VIEW-ONLY MODAL 2: WEDDING PACKAGES
       ========================================================================== -->
    <div class="package-view-overlay" id="weddingPackageModal" onclick="handleOverlayClick(event, this)">
        <div class="package-view-modal">
            
            <div class="package-view-header">
                <div>
                    <span class="view-only-pill"><i class="fa-solid fa-eye"></i> View Only</span>
                    <h2><i class="fa-solid fa-rings-wedding"></i> Wedding Packages &amp; Inclusions</h2>
                    <p>Live wedding &amp; reception prices synced from our database. To customize and book, click Book Now.</p>
                </div>
                <button type="button" class="modal-close-icon" onclick="closePackageModal('weddingPackageModal')" aria-label="Close">&times;</button>
            </div>

            <div class="package-view-body">
                
                <h3 style="font-size: 17px; font-weight: 800; color: #1f2937; margin: 0 0 14px 0;">
                    Reception &amp; Main Styling Packages
                </h3>

                <!-- Featured Reception Styling Packages Grid -->
                <div class="view-cards-grid">
                    <?php 
                    $w_items = $pricing_wedding['reception_styling']['items'] ?? [];
                    foreach ($w_items as $item): 
                        $price = (int)$item['price'];
                        $price_text = ($price > 0) ? '&#8369;' . number_format($price) : 'Custom Quote';
                    ?>
                        <div class="view-pkg-card">
                            <span class="view-pkg-badge">Reception Styling</span>
                            <h4 class="view-pkg-name"><?= htmlspecialchars($item['label']) ?></h4>
                            <div class="view-pkg-price"><?= $price_text ?></div>
                            <ul class="view-inclusions-list">
                                <li><i class="fa-solid fa-check"></i> <span>Thematic Couple's Stage &amp; Backdrop</span></li>
                                <li><i class="fa-solid fa-check"></i> <span>Custom Head Table &amp; Floral Arrangements</span></li>
                                <li><i class="fa-solid fa-check"></i> <span>Guest Table Centerpieces &amp; Napkin Setup</span></li>
                                <li><i class="fa-solid fa-check"></i> <span>Entrance Tunnel / Photo Op Vignette</span></li>
                            </ul>
                            <div style="font-size: 11px; color: var(--text-muted); font-style: italic;">View Only &bull; Selectable on Booking Page</div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Additional Setup & Equipment Options (Dynamically Loaded from Database) -->
                <?php 
                $wedding_cat_icons = [
                    'ceremony_styling'  => 'fa-church',
                    'entourage_flower'  => 'fa-spa',
                    'otd'               => 'fa-clipboard-list',
                    'sound_lights'      => 'fa-music',
                    'entertainment'     => 'fa-microphone',
                    'photo_video'       => 'fa-video',
                ];
                $has_other_wedding_cats = false;
                foreach ($pricing_wedding as $w_k => $w_v) {
                    if ($w_k !== 'reception_styling' && !empty($w_v['items'])) {
                        $has_other_wedding_cats = true;
                        break;
                    }
                }
                if ($has_other_wedding_cats):
                ?>
                <h3 style="font-size: 17px; font-weight: 800; color: #1f2937; margin: 24px 0 14px 0;">
                    Available Setup, Equipment &amp; Floral Options
                </h3>

                <?php
                foreach ($pricing_wedding as $w_key => $w_val):
                    if ($w_key === 'reception_styling') continue;
                    $w_items = $w_val['items'] ?? [];
                    if (empty($w_items)) continue;
                    $w_title = $w_val['category_title'] ?? ucwords(str_replace('_', ' ', $w_key));
                    $w_icon = !empty($w_val['icon']) ? $w_val['icon'] : ($wedding_cat_icons[$w_key] ?? 'fa-asterisk');
                ?>
                    <div class="view-addon-section">
                        <div class="view-addon-header">
                            <i class="fa-solid <?= $w_icon ?>"></i> <?= htmlspecialchars($w_title) ?>
                        </div>
                        <div class="view-addon-grid">
                            <?php foreach ($w_items as $w_item): ?>
                                <div class="view-addon-item">
                                    <span><?= htmlspecialchars($w_item['label']) ?></span>
                                    <span class="view-addon-price">&#8369;<?= number_format((int)$w_item['price']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="package-view-footer">
                <div class="package-view-footer-info">
                    <i class="fa-solid fa-circle-info" style="color: var(--primary);"></i>
                    This modal is strictly for viewing package details. To select options and reserve your wedding date, proceed to the Booking Page.
                </div>
                <div class="modal-action-buttons">
                    <button type="button" class="btn-modal-close" onclick="closePackageModal('weddingPackageModal')">Close</button>
                    <a href="booking.php?event_type=Weddings" class="btn-modal-book-now">
                        <i class="fa-solid fa-calendar-plus"></i> Book Now
                    </a>
                </div>
            </div>

        </div>
    </div>

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
                        <div style="font-size: 11px; opacity: 0.85;">Here to answer general FAQs &amp; packages</div>
                    </div>
                </div>
                <button type="button" onclick="toggleChatWindow()" style="background: none; border: none; color: white; cursor: pointer; font-size: 18px;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="chatbot-messages" id="chatbotMessages">
                <div class="chat-bubble bot">
                    Hello! Welcome to <strong><?= htmlspecialchars($business_name) ?></strong>. I can answer questions regarding our services, Birthday and Wedding packages, pricing, and booking requirements!
                    <div class="quick-chips-wrapper">
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('What packages do you offer?')">
                            <i class="fa-solid fa-gift"></i> What packages do you offer?
                        </button>
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('How does the booking process work?')">
                            <i class="fa-solid fa-calendar-check"></i> How does booking work?
                        </button>
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('What is the minimum lead time for reservations?')">
                            <i class="fa-solid fa-clock"></i> What is the lead time requirement?
                        </button>
                    </div>
                </div>
            </div>

            <div class="chatbot-footer-wrapper">
                <form class="chatbot-footer" onsubmit="sendChatMessage(event)">
                    <textarea id="chatInput" placeholder="Ask a question..." onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendChatMessage(event);}"></textarea>
                    <button type="submit" aria-label="Send message"><i class="fa-solid fa-paper-plane"></i></button>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script>
        // Modal Controls
        function openPackageModal(type) {
            if (type === 'birthday') {
                document.getElementById('birthdayPackageModal').classList.add('active');
            } else if (type === 'wedding') {
                document.getElementById('weddingPackageModal').classList.add('active');
            }
            document.body.style.overflow = 'hidden';
        }

        function closePackageModal(modalId) {
            if (modalId) {
                const el = document.getElementById(modalId);
                if (el) el.classList.remove('active');
            } else {
                document.querySelectorAll('.package-view-overlay').forEach(m => m.classList.remove('active'));
            }
            document.body.style.overflow = '';
        }

        function handleOverlayClick(e, overlayEl) {
            if (e.target === overlayEl) {
                overlayEl.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        // Close on ESC key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closePackageModal();
            }
        });

        // Portfolio Filter
        function filterGallery(category, btn) {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            document.querySelectorAll('.portfolio-item').forEach(item => {
                if (category === 'all' || item.getAttribute('data-category') === category) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        // Chatbot Controls
        function toggleChatWindow() {
            const win = document.getElementById('chatbotWindow');
            win.classList.toggle('active');
            if (win.classList.contains('active')) {
                document.getElementById('chatInput').focus();
            }
        }

        function sendQuickPrompt(text) {
            const input = document.getElementById('chatInput');
            input.value = text;
            sendChatMessage(new Event('submit'));
        }

        async function sendChatMessage(e) {
            e.preventDefault();
            const input = document.getElementById('chatInput');
            const msg = input.value.trim();
            if (!msg) return;

            const chatMessages = document.getElementById('chatbotMessages');
            
            // Append user message
            const uDiv = document.createElement('div');
            uDiv.className = 'chat-bubble user';
            uDiv.textContent = msg;
            chatMessages.appendChild(uDiv);
            input.value = '';
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
                const data = await res.json();
                botDiv.innerHTML = data.reply ? data.reply.replace(/\n/g, '<br>') : (data.message || 'Sorry, I could not process your message right now.');
            } catch (err) {
                botDiv.textContent = 'Our concierge is temporarily unavailable. Please visit our Booking page or contact our team directly!';
            }
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
    </script>
</body>
</html>
