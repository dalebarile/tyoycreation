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
    <title><?= htmlspecialchars($business_name) ?> - Event Management &amp; Styling</title>
    <meta name="description" content="Tyoy Creation is a premier event management and styling business specializing in unforgettable Kids Party celebrations and bespoke Weddings.">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <link rel="icon" type="image/png" href="assets/favicon.png?v=<?= filemtime(__DIR__ . '/assets/favicon.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,400;1,600&family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,400&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            /* Primary Botanical Emerald */
            --primary: #18392b;
            --primary-hover: #122c21;
            --primary-dark: #0d2018;
            --primary-light: #eaf2ec;
            --accent-gold: #c5a059;
            --accent-gold-soft: #f7f1e4;
            --bg-cream: #f7f9f7;
            --bg-cream-dark: #eff4f0;
            --border-color: #dbe5de;
            --border-subtle: #e6ede8;
            --text-primary: #14261c;
            --text-secondary: #3d5345;
            --text-muted: #667d6f;
        }

        /* Navigation Bar */
        .public-nav {
            background: #18392b;
            position: sticky;
            top: 0;
            z-index: 1000;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            padding: 14px 0;
            box-shadow: 0 4px 20px rgba(24, 57, 43, 0.15);
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
            display: block;
            transition: transform 0.2s ease;
        }

        .brand-logo-img:hover {
            transform: scale(1.02);
        }

        .public-nav-links {
            display: flex;
            align-items: center;
            gap: 24px;
            flex-wrap: wrap;
        }

        .public-nav-links a {
            color: rgba(255, 255, 255, 0.88);
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            letter-spacing: 0.01em;
            transition: color 0.2s ease;
        }

        .public-nav-links a:hover,
        .public-nav-links a.active {
            color: #ffffff;
            font-weight: 600;
        }

        .btn-nav-book {
            background: #ffffff !important;
            color: var(--primary) !important;
            font-weight: 700 !important;
            font-size: 13px !important;
            padding: 9px 22px !important;
            border-radius: 9999px !important;
            text-decoration: none !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
            box-shadow: 0 3px 10px rgba(24, 57, 43, 0.18) !important;
            transition: all 0.2s ease !important;
        }

        .btn-nav-book:hover {
            background: var(--primary-light) !important;
            color: var(--primary-hover) !important;
            transform: translateY(-1px);
            box-shadow: 0 5px 14px rgba(24, 57, 43, 0.25) !important;
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

        /* Services - 2-Package Showcase */
        .services-2-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 32px;
            max-width: 1040px;
            margin: 0 auto 36px;
        }
        @media (max-width: 768px) {
            .services-2-grid {
                grid-template-columns: 1fr;
                gap: 24px;
            }
        }
        .service-package-card {
            background: #ffffff;
            border-radius: 20px;
            border: 1px solid var(--border-color);
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(24, 57, 43, 0.06);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            text-align: left;
        }
        .service-package-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 16px 36px rgba(24, 57, 43, 0.12);
            border-color: #5b826d;
        }
        .svc-card-media {
            position: relative;
            height: 230px;
            overflow: hidden;
            background: #18392b;
        }
        .svc-card-media img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.4s ease;
            display: block;
        }
        .service-package-card:hover .svc-card-media img {
            transform: scale(1.05);
        }
        .svc-badge {
            position: absolute;
            top: 14px;
            left: 14px;
            background: rgba(24, 57, 43, 0.90);
            backdrop-filter: blur(8px);
            color: #ffffff;
            font-size: 11.5px;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 9999px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            border: 1px solid rgba(255, 255, 255, 0.22);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .svc-badge.wedding {
            background: rgba(197, 160, 89, 0.94);
            color: #18392b;
            border-color: rgba(24, 57, 43, 0.2);
        }
        .svc-card-content {
            padding: 28px 26px;
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        .svc-title-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 12px;
            gap: 12px;
            flex-wrap: wrap;
        }
        .svc-title-row h3 {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
        }
        .svc-price-pill {
            font-size: 13px;
            font-weight: 800;
            color: #18392b;
            background: #eaf2ec;
            padding: 4px 12px;
            border-radius: 9999px;
            border: 1px solid rgba(24, 57, 43, 0.12);
            white-space: nowrap;
        }
        .svc-description {
            font-size: 14px;
            color: var(--text-secondary);
            line-height: 1.6;
            margin: 0 0 18px 0;
        }
        .svc-feature-list {
            list-style: none;
            padding: 0;
            margin: 0 0 24px 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .svc-feature-list li {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 13.5px;
            color: var(--text-primary);
            line-height: 1.4;
        }
        .svc-feature-list li i {
            color: #18392b;
            font-size: 13px;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .svc-actions {
            margin-top: auto;
            display: grid;
            grid-template-columns: 1fr 1.3fr;
            gap: 12px;
        }
        @media (max-width: 480px) {
            .svc-actions {
                grid-template-columns: 1fr;
            }
        }
        .btn-svc-details {
            background: transparent;
            color: var(--primary);
            border: 1.5px solid var(--border-color);
            padding: 11px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .btn-svc-details:hover {
            background: #eaf2ec;
            border-color: #18392b;
        }
        .btn-svc-book {
            background: #18392b;
            color: #ffffff !important;
            padding: 11px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            box-shadow: 0 3px 10px rgba(24, 57, 43, 0.22);
            transition: all 0.2s;
        }
        .btn-svc-book:hover {
            background: #122c21;
            transform: translateY(-1px);
            box-shadow: 0 5px 14px rgba(24, 57, 43, 0.32);
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
            overflow: auto;
            animation: modalFadeIn 0.25s ease;
            position: relative;
        }

        /* Wedding Package Modal: strictly do not scroll */
        #weddingPackageModal .package-view-modal {
            overflow: hidden !important;
            max-height: 90vh;
        }
        #weddingPackageModal .modal-split-layout {
            overflow: hidden !important;
            padding: 20px 24px;
            gap: 20px;
            align-items: center;
        }
        #weddingPackageModal .modal-split-img {
            max-height: 250px;
            object-fit: cover;
        }
        #weddingPackageModal .modal-inclusions-checklist {
            margin: 0 0 12px 0;
        }
        #weddingPackageModal .modal-inclusions-checklist li {
            margin-bottom: 6px;
            font-size: 13px;
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
        .chat-bubble, .chat-msg {
            max-width: 85%;
            padding: 11px 15px;
            border-radius: 16px;
            font-size: 13.5px;
            line-height: 1.55;
            white-space: pre-wrap;
            word-break: break-word;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .chat-bubble strong, .chat-msg strong {
            font-weight: 700;
        }
        .chat-bubble em, .chat-msg em {
            font-style: italic;
        }
        .chat-bubble.bot, .chat-msg.bot {
            align-self: flex-start;
            background: #ffffff;
            color: #1f2937;
            border: 1px solid #e5e7eb;
            border-bottom-left-radius: 4px;
        }
        .chat-bubble.user, .chat-msg.user {
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
            color: white;
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
    </style>
</head>
<body>

    <!-- Public Navigation Bar -->
    <nav class="public-nav">
        <div class="public-container public-nav-content">
            <a href="#home" style="display: flex; align-items: center; text-decoration: none;">
                <img src="assets/tyoy_logo_cropped.png?v=<?= filemtime(__DIR__ . '/assets/tyoy_logo_cropped.png') ?>" alt="<?= htmlspecialchars($business_name) ?>" class="brand-logo-img">
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

    <!-- Hero Header (Panel 1) -->
    <?php $hero_bg_img = get_setting($conn, 'media_hero_bg', 'assets/tyoy_creation_banner.jpg'); ?>
    <header class="hero-section" id="home" style="background-image: linear-gradient(90deg, rgba(20, 32, 21, 0.90) 0%, rgba(20, 32, 21, 0.75) 50%, rgba(20, 32, 21, 0.35) 80%, rgba(20, 32, 21, 0.15) 100%), url('<?= htmlspecialchars($hero_bg_img) ?>');">
        <div class="public-container">
            <div class="hero-content">
                <span class="hero-tag">EVENT MANAGEMENT &bull; KIDS PARTY &bull; WEDDINGS</span>
                <h1 class="hero-title">
                    Celebration &amp; Styling
                    <span class="hero-subtitle-line">Event Management &amp; Coordination</span>
                </h1>
                <p class="hero-desc">
                    <?= htmlspecialchars(get_setting($conn, 'hero_subtitle', 'Premier event management and bespoke styling for magical Kids Parties and unforgettable Weddings — crafted with precision, passion, and dedication since 2020.')) ?>
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

    <!-- Services & Celebration Packages Showcase -->
    <section class="section" id="services" style="background: #ffffff; padding: 70px 0 50px;">
        <div class="public-container">
            <div class="section-header">
                <h2 class="section-title">Our Services</h2>
                <p class="section-subtitle">Specialized celebration packages crafted to perfection.</p>
            </div>

            <div class="services-2-grid">
                <!-- Kids Party Package Card -->
                <div class="service-package-card">
                    <div class="svc-card-media">
                        <img src="assets/portfolio/svc-kids-party.jpg" alt="Kids Party Package" onerror="this.src='assets/portfolio/portfolio-snowwhite-party.jpg'">
                        <span class="svc-badge"><i class="fa-solid fa-cake-candles"></i> Celebrations &amp; Birthdays</span>
                    </div>
                    <div class="svc-card-content">
                        <div class="svc-title-row">
                            <h3>Kids Party Package</h3>
                        </div>
                        <p class="svc-description">
                            Complete celebration packages designed for children's birthdays and family milestones. Vibrant themes, custom backdrops, balloon art, and stress-free event coordination.
                        </p>
                        <ul class="svc-feature-list">
                            <li><i class="fa-solid fa-circle-check"></i> Thematic Backdrop &amp; Character Styling</li>
                            <li><i class="fa-solid fa-circle-check"></i> Balloon Art &amp; Ceiling / Entrance Installations</li>
                            <li><i class="fa-solid fa-circle-check"></i> Table Set-up, Centerpieces &amp; Cake Table</li>
                            <li><i class="fa-solid fa-circle-check"></i> Party Favors, Magician &amp; Entertainment Direction</li>
                            <li><i class="fa-solid fa-circle-check"></i> Dedicated On-the-Day Event Coordinator</li>
                        </ul>
                        <div class="svc-actions">
                            <button type="button" class="btn-svc-details" onclick="openPackageModal('birthday')">
                                <i class="fa-solid fa-circle-info"></i> View Inclusions
                            </button>
                            <a href="booking.php?event_type=Kids+Party" class="btn-svc-book">
                                <i class="fa-solid fa-calendar-check"></i> Book Kids Party
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Wedding Package Card -->
                <div class="service-package-card">
                    <div class="svc-card-media">
                        <img src="assets/portfolio/svc-wedding.jpg" alt="Wedding Package" onerror="this.src='assets/portfolio/portfolio-wedding-ceremony.jpg'">
                        <span class="svc-badge wedding"><i class="fa-solid fa-champagne-glasses"></i> Ceremonies &amp; Receptions</span>
                    </div>
                    <div class="svc-card-content">
                        <div class="svc-title-row">
                            <h3>Wedding Package</h3>
                        </div>
                        <p class="svc-description">
                            Bespoke elegance for your dream wedding. From romantic ceremony setups to lavish reception styling and seamless day-of coordination, every detail is flawlessly executed.
                        </p>
                        <ul class="svc-feature-list">
                            <li><i class="fa-solid fa-circle-check"></i> Ceremony Altar, Aisle &amp; Entrance Arch Styling</li>
                            <li><i class="fa-solid fa-circle-check"></i> Reception Head Table &amp; Guest Tablescapes</li>
                            <li><i class="fa-solid fa-circle-check"></i> Bridal Entourage Arrangements &amp; Stage Backdrop</li>
                            <li><i class="fa-solid fa-circle-check"></i> Atmospheric Lighting, Sound &amp; Equipment Coordination</li>
                            <li><i class="fa-solid fa-circle-check"></i> Full-Day Coordination &amp; Master of Ceremonies</li>
                        </ul>
                        <div class="svc-actions">
                            <button type="button" class="btn-svc-details" onclick="openPackageModal('wedding')">
                                <i class="fa-solid fa-circle-info"></i> View Inclusions
                            </button>
                            <a href="booking.php?event_type=Weddings" class="btn-svc-book">
                                <i class="fa-solid fa-calendar-check"></i> Book Wedding
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Let's Plan Your Special Event Feature Card (Panel 1) -->
            <div class="special-event-card">
                <img src="assets/portfolio/portfolio-wedding-ceremony.jpg" alt="Special Event" class="special-event-img">
                <div class="special-event-content">
                    <h3>Let's Plan Your Special Event</h3>
                    <p>Tell us your vision and we'll make it happen with unforgettable styling and coordination.</p>
                    <a href="booking.php" class="btn-event-pill"><i class="fa-solid fa-calendar-plus"></i> Book Now</a>
                </div>
            </div>

            <!-- Featured Portfolio Preview (Panel 1) -->
            <div style="margin-top: 50px;">
                <div class="section-header" style="margin-bottom: 30px;">
                    <h2 class="section-title">Featured Portfolio</h2>
                    <p class="section-subtitle">A glimpse of our past creations.</p>
                </div>
                <div class="services-4-grid" style="margin-top: 0; margin-bottom: 28px;">
                    <?php 
                    $default_portfolio = [
                        ['category' => 'weddings', 'img' => 'assets/portfolio/portfolio-wedding-ceremony.jpg', 'title' => 'Ceremony Floral Arch Styling', 'location' => 'Weddings • Church Ceremony Styling'],
                        ['category' => 'birthdays', 'img' => 'assets/portfolio/portfolio-jasmine-aladdin.jpg', 'title' => 'Arabian Nights: Jasmine & Aladdin', 'location' => 'Kids Party • Character Backdrop'],
                        ['category' => 'weddings', 'img' => 'assets/portfolio/portfolio-wedding-couple.jpg', 'title' => 'Garden Wedding Portraits', 'location' => 'Weddings • Full Event Styling'],
                        ['category' => 'birthdays', 'img' => 'assets/portfolio/portfolio-snowwhite-party.jpg', 'title' => 'Snow White Themed Celebration', 'location' => 'Kids Party • Character Backdrop'],
                        ['category' => 'birthdays', 'img' => 'assets/portfolio/portfolio-elisha-carparty.jpg', 'title' => 'Vintage Car Themed 1st Birthday', 'location' => 'Kids Party • Full Event Styling'],
                        ['category' => 'birthdays', 'img' => 'assets/portfolio/portfolio-kiara-magician.jpg', 'title' => 'Circus Magician Entertainment', 'location' => 'Kids Party • Hosts & Entertainment'],
                        ['category' => 'birthdays', 'img' => 'assets/portfolio/portfolio-agatha-butterfly.jpg', 'title' => 'Butterfly Garden Celebration', 'location' => 'Kids Party • Full Event Styling'],
                        ['category' => 'birthdays', 'img' => 'assets/portfolio/portfolio-rabbalucia-boho.jpg', 'title' => 'Boho Floral 1st Birthday', 'location' => 'Kids Party • Full Event Styling']
                    ];
                    $saved_portfolio_json = get_setting($conn, 'portfolio_items', '');
                    $portfolio_items = !empty($saved_portfolio_json) ? json_decode($saved_portfolio_json, true) : null;
                    if (!is_array($portfolio_items) || empty($portfolio_items)) {
                        $portfolio_items = $default_portfolio;
                    }
                    $featured_slice = array_slice($portfolio_items, 0, 4);
                    foreach ($featured_slice as $fp): 
                    ?>
                    <div style="border-radius: 14px; overflow: hidden; border: 1px solid var(--border-color); aspect-ratio: 4/3; box-shadow: var(--shadow-sm);">
                        <img src="<?= htmlspecialchars($fp['img']) ?>" alt="<?= htmlspecialchars(strip_tags($fp['title'])) ?>" style="width: 100%; height: 100%; object-fit: cover; display: block;" onerror="this.src='assets/portfolio/portfolio-wedding-ceremony.jpg'">
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="text-align: center;">
                    <a href="#portfolio" class="btn-event-pill" style="background: transparent; color: var(--primary) !important; border: 1.5px solid var(--border-color); box-shadow: none;">
                        View More &rarr;
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- About Us Section (Panel 2) -->
    <section class="story-section" id="about-us" style="background: var(--bg-cream); padding: 80px 0;">
        <div class="public-container">
            <div class="section-header">
                <h2 class="section-title">About Us</h2>
                <p class="section-subtitle">Turning your vision into beautiful memories.</p>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 48px; align-items: center; margin-bottom: 40px;">
                <div style="border-radius: 18px; overflow: hidden; border: 1px solid var(--border-color); box-shadow: var(--shadow-md);">
                    <img src="assets/portfolio/portfolio-wedding-couple.jpg" alt="About <?= htmlspecialchars($business_name) ?>" style="width: 100%; height: 380px; object-fit: cover; display: block;" onerror="this.src='assets/portfolio/portfolio-wedding-ceremony.jpg'">
                </div>
                <div style="text-align: left;">
                    <h3 style="font-family: 'Cormorant Garamond', 'Playfair Display', serif; font-size: 32px; font-weight: 600; color: var(--primary); margin-bottom: 14px;">Our Story</h3>
                    <p style="color: var(--text-secondary); font-size: 15px; line-height: 1.8; margin-bottom: 16px;">
                        <?= nl2br(htmlspecialchars(get_setting($conn, 'about_story', $business_name . ' started in 2020 with a simple mission — to bring joy and beauty to every celebration. What began as a small passion for creative styling and floral designs has grown into a trusted event management and coordination service, known for quality, creativity, and personalized care.'))) ?>
                    </p>
                    <p style="color: var(--text-secondary); font-size: 15px; line-height: 1.8;">
                        Today, we curate bespoke weddings, unforgettable children's parties, and milestone gatherings. Our team coordinates every vendor, handles logistical challenges, and executes stunning floral atmospheres so you can be fully present with the people who matter most.
                    </p>
                </div>
            </div>

            <!-- 4 Stat Counters (Panel 2) -->
            <div class="stats-wire-grid">
                <div class="stat-wire-card">
                    <span class="num">5+</span>
                    <span class="label">Years of Experience</span>
                </div>
                <div class="stat-wire-card">
                    <span class="num">100+</span>
                    <span class="label">Events Styled</span>
                </div>
                <div class="stat-wire-card">
                    <span class="num">50+</span>
                    <span class="label">Happy Clients</span>
                </div>
                <div class="stat-wire-card">
                    <span class="num">100%</span>
                    <span class="label">Dedication</span>
                </div>
            </div>

            <!-- Our Values (Panel 2) -->
            <div style="margin-top: 50px;">
                <div style="text-align: center; margin-bottom: 30px;">
                    <h3 style="font-family: 'Cormorant Garamond', 'Playfair Display', serif; font-size: 30px; font-weight: 600; color: var(--primary);">Our Values</h3>
                </div>
                <div class="values-wire-grid">
                    <div class="value-wire-card">
                        <div class="val-icon"><i class="fa-solid fa-lightbulb"></i></div>
                        <h4>Creativity</h4>
                        <p>Unique and fresh ideas for every event.</p>
                    </div>
                    <div class="value-wire-card">
                        <div class="val-icon"><i class="fa-solid fa-award"></i></div>
                        <h4>Quality</h4>
                        <p>Only the best materials and designs.</p>
                    </div>
                    <div class="value-wire-card">
                        <div class="val-icon"><i class="fa-solid fa-handshake"></i></div>
                        <h4>Commitment</h4>
                        <p>Your vision is our priority.</p>
                    </div>
                    <div class="value-wire-card">
                        <div class="val-icon"><i class="fa-solid fa-face-smile"></i></div>
                        <h4>Customer Satisfaction</h4>
                        <p>Because every event matters.</p>
                    </div>
                </div>
            </div>

            <!-- CTA Banner (Panel 2) -->
            <div class="cta-banner-wire">
                <h3>Let's make your next event extra special!</h3>
                <p>We are ready to bring your dream celebration to life with precision and flair.</p>
                <a href="booking.php" class="btn-cta-wire-white"><i class="fa-solid fa-calendar-plus"></i> Book an Event</a>
            </div>
        </div>
    </section>


    <!-- Portfolio Section (Panel 4) -->
    <section class="section" id="portfolio" style="background: var(--bg-cream);">
        <div class="public-container">
            <div class="section-header">
                <h2 class="section-title">Our Portfolio</h2>
                <p class="section-subtitle">Moments we've styled, memories we've created.</p>
            </div>

            <!-- Filter Pills (Panel 4) -->
            <div class="portfolio-filters">
                <button type="button" class="filter-btn active" onclick="filterGallery('all', this)">All</button>
                <button type="button" class="filter-btn" onclick="filterGallery('birthdays', this)">Birthdays</button>
                <button type="button" class="filter-btn" onclick="filterGallery('weddings', this)">Weddings</button>
                <button type="button" class="filter-btn" onclick="filterGallery('corporate', this)">Corporate</button>
                <button type="button" class="filter-btn" onclick="filterGallery('others', this)">Others</button>
            </div>

            <div class="portfolio-grid-wire">
                <?php foreach ($portfolio_items as $item): ?>
                <div class="portfolio-card-wire portfolio-item" data-category="<?= htmlspecialchars($item['category'] ?? 'weddings') ?>">
                    <img src="<?= htmlspecialchars($item['img'] ?? '') ?>" alt="<?= htmlspecialchars(strip_tags($item['title'] ?? '')) ?>" onerror="this.src='assets/portfolio/portfolio-wedding-ceremony.jpg'">
                    <div class="overlay">
                        <h4 style="font-size: 16px; font-weight: 700; margin: 0 0 4px 0;"><?= htmlspecialchars($item['title'] ?? '') ?></h4>
                        <span style="font-size: 12px; color: rgba(255,255,255,0.8);"><?= htmlspecialchars($item['location'] ?? '') ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Bottom Portfolio CTA (Panel 4) -->
            <div style="text-align: center; margin-top: 50px;">
                <h3 style="font-family: 'Cormorant Garamond', 'Playfair Display', serif; font-size: 28px; font-weight: 600; color: var(--primary); margin-bottom: 8px;">Have a special event in mind?</h3>
                <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 20px;">Let's bring your vision to life.</p>
                <a href="booking.php" class="btn-event-pill"><i class="fa-solid fa-calendar-plus"></i> Book Now</a>
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
            <p class="cta-sub">Whether it's an unforgettable kids' party or a bespoke wedding celebration &mdash; we are here to bring your vision to life. Submit an inquiry today and let's start planning.</p>
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
                        <?= htmlspecialchars($business_name) ?> is a professional event management and styling business. We specialize in transforming your vision into a beautifully executed, stress-free celebration.
                    </p>
                    <p style="color: var(--text-secondary); margin-bottom: 24px; line-height: 1.7;">
                        From magical kids' parties to romantic weddings, our team coordinates every detail &mdash; from custom backdrops and theme styling to vendor coordination and flawless day-of execution.
                    </p>
                    <div style="display: flex; gap: 32px; flex-wrap: wrap;">
                        <div>
                            <div style="font-size: 32px; font-weight: 800; color: var(--primary);">2020</div>
                            <div style="font-size: 13px; color: var(--text-secondary);">Year Established</div>
                        </div>
                        <div>
                            <div style="font-size: 32px; font-weight: 800; color: var(--primary);">2</div>
                            <div style="font-size: 13px; color: var(--text-secondary);">Signature Packages</div>
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
    </section>

    <!-- Footer (Panels 1 - 5) -->
    <footer style="background: #1f3327; color: #b6c7bc; padding: 48px 0 32px; border-top: 1px solid rgba(255,255,255,0.08); font-size: 14px;">
        <div class="public-container">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 24px; flex-wrap: wrap; margin-bottom: 32px;">
                <a href="#home">
                    <img src="assets/tyoy_logo_cropped.png?v=<?= filemtime(__DIR__ . '/assets/tyoy_logo_cropped.png') ?>" alt="<?= htmlspecialchars($business_name) ?>" style="height: 38px; width: auto; border-radius: 6px; display: block;">
                </a>
                <div style="display: flex; gap: 24px; align-items: center; flex-wrap: wrap;">
                    <a href="#home" style="color: #ffffff; text-decoration: none; font-size: 14px;">Home</a>
                    <a href="#about-us" style="color: #ffffff; text-decoration: none; font-size: 14px;">About</a>
                    <a href="#services" style="color: #ffffff; text-decoration: none; font-size: 14px;">Services</a>
                    <a href="#portfolio" style="color: #ffffff; text-decoration: none; font-size: 14px;">Portfolio</a>
                    <a href="#contact" style="color: #ffffff; text-decoration: none; font-size: 14px;">Contact</a>
                </div>
                <div style="display: flex; gap: 14px; align-items: center; font-size: 16px;">
                    <a href="https://facebook.com" target="_blank" rel="noopener" style="color: #ffffff; width: 34px; height: 34px; border-radius: 50%; background: rgba(255,255,255,0.12); display: flex; align-items: center; justify-content: center; text-decoration: none;"><i class="fa-brands fa-facebook-f"></i></a>
                    <a href="https://instagram.com" target="_blank" rel="noopener" style="color: #ffffff; width: 34px; height: 34px; border-radius: 50%; background: rgba(255,255,255,0.12); display: flex; align-items: center; justify-content: center; text-decoration: none;"><i class="fa-brands fa-instagram"></i></a>
                    <a href="https://tiktok.com" target="_blank" rel="noopener" style="color: #ffffff; width: 34px; height: 34px; border-radius: 50%; background: rgba(255,255,255,0.12); display: flex; align-items: center; justify-content: center; text-decoration: none;"><i class="fa-brands fa-tiktok"></i></a>
                    <a href="mailto:<?= htmlspecialchars($contact_email) ?>" style="color: #ffffff; width: 34px; height: 34px; border-radius: 50%; background: rgba(255,255,255,0.12); display: flex; align-items: center; justify-content: center; text-decoration: none;"><i class="fa-solid fa-envelope"></i></a>
                </div>
            </div>
            <div style="border-top: 1px solid rgba(255,255,255,0.08); padding-top: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                <p style="margin: 0; font-size: 13px; color: rgba(255,255,255,0.45);">&copy; <?= date('Y') ?> <?= htmlspecialchars($business_name) ?>. All rights reserved.</p>
                <a href="loginadmin.php" style="color: rgba(255,255,255,0.40); text-decoration: none; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; transition: color 0.2s;" onmouseover="this.style.color='#ffffff'" onmouseout="this.style.color='rgba(255,255,255,0.40)'">
                    <i class="fa-solid fa-lock"></i> Staff / Admin Portal
                </a>
            </div>
        </div>
    </footer>

    <!-- ==========================================================================
       VIEW-ONLY MODAL 1: BIRTHDAY PACKAGES (Panel 6)
       ========================================================================== -->
    <div class="package-view-overlay" id="birthdayPackageModal" onclick="handleOverlayClick(event, this)">
        <div class="package-view-modal">
            
            <div class="package-view-header">
                <div>
                    <h2 style="font-family: 'Playfair Display', serif; font-size: 22px; font-weight: 700; margin: 0 0 4px 0; color: #ffffff;">Birthday Package</h2>
                    <p style="margin: 0; font-size: 13px; color: rgba(255,255,255,0.85);">Complete and hassle-free celebration package.</p>
                </div>
                <button type="button" class="modal-close-icon" onclick="closePackageModal('birthdayPackageModal')" aria-label="Close">&times;</button>
            </div>

            <div class="modal-split-layout">
                <div>
                    <img src="assets/portfolio/svc-kids-party.jpg" alt="Birthday Package" class="modal-split-img" onerror="this.src='assets/portfolio/portfolio-snowwhite-party.jpg'">
                </div>
                <div class="modal-split-info">
                    <div class="modal-inclusions-title"><i class="fa-solid fa-gift"></i> Package Inclusions</div>
                    <ul class="modal-inclusions-checklist">
                        <li><i class="fa-solid fa-check"></i> Balloon Decoration &amp; Thematic Backdrop</li>
                        <li><i class="fa-solid fa-check"></i> Table Set-up, Centerpieces &amp; Cake Table</li>
                        <li><i class="fa-solid fa-check"></i> Character Decors &amp; Stage Installations</li>
                        <li><i class="fa-solid fa-check"></i> Party Favors &amp; Giveaways Coordination</li>
                        <li><i class="fa-solid fa-check"></i> Dedicated On-the-Day Event Coordinator</li>
                    </ul>

                    <div class="modal-pricing-title"><i class="fa-solid fa-tag"></i> Packages &amp; Pricing</div>
                    <div class="modal-pricing-rows">
                        <?php 
                        $tp_items = $pricing_theme['theme_styling']['items'] ?? [];
                        if (!empty($tp_items)):
                            foreach ($tp_items as $item): 
                                $price = (int)$item['price'];
                                $price_text = ($price > 0) ? '&#8369; ' . number_format($price) : 'Custom Quote';
                                $item_label = $item['label'];
                        ?>
                            <div class="modal-price-row" style="display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; gap: 8px;">
                                <div>
                                    <span class="p-name" style="font-weight: 700; color: var(--text-primary);"><?= htmlspecialchars($item_label) ?></span>
                                    <span class="p-val" style="display: block; font-size: 13px; color: var(--primary); font-weight: 800;"><?= $price_text ?></span>
                                </div>
                                <a href="booking.php?event_type=Kids+Party&package=<?= urlencode($item_label) ?>" class="btn-event-pill" style="font-size: 11px; padding: 6px 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;">
                                    <i class="fa-solid fa-lock"></i> Select
                                </a>
                            </div>
                        <?php 
                            endforeach;
                        else:
                        ?>
                            <div class="modal-price-row" style="display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; gap: 8px;">
                                <div>
                                    <span class="p-name" style="font-weight: 700; color: var(--text-primary);">Basic Package</span>
                                    <span class="p-val" style="display: block; font-size: 13px; color: var(--primary); font-weight: 800;">&#8369; 8,000</span>
                                </div>
                                <a href="booking.php?event_type=Kids+Party&package=Basic+Package" class="btn-event-pill" style="font-size: 11px; padding: 6px 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;">
                                    <i class="fa-solid fa-lock"></i> Select
                                </a>
                            </div>
                            <div class="modal-price-row" style="display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; gap: 8px;">
                                <div>
                                    <span class="p-name" style="font-weight: 700; color: var(--text-primary);">Standard Package</span>
                                    <span class="p-val" style="display: block; font-size: 13px; color: var(--primary); font-weight: 800;">&#8369; 12,000</span>
                                </div>
                                <a href="booking.php?event_type=Kids+Party&package=Standard+Package" class="btn-event-pill" style="font-size: 11px; padding: 6px 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;">
                                    <i class="fa-solid fa-lock"></i> Select
                                </a>
                            </div>
                            <div class="modal-price-row" style="display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; gap: 8px;">
                                <div>
                                    <span class="p-name" style="font-weight: 700; color: var(--text-primary);">Premium Package</span>
                                    <span class="p-val" style="display: block; font-size: 13px; color: var(--primary); font-weight: 800;">&#8369; 18,000</span>
                                </div>
                                <a href="booking.php?event_type=Kids+Party&package=Premium+Package" class="btn-event-pill" style="font-size: 11px; padding: 6px 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;">
                                    <i class="fa-solid fa-lock"></i> Select
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 16px; margin-top: 10px;">
                        <i class="fa-solid fa-lock" style="color: var(--primary);"></i> Selecting a package locks this service in your booking form to preserve your reservation details.
                    </p>

                    <a href="booking.php?event_type=Kids+Party" class="btn-event-pill" style="width: 100%; text-align: center; display: block; box-sizing: border-box; text-decoration: none;">
                        <i class="fa-solid fa-calendar-check"></i> Book Kids Party (Locked to Service)
                    </a>
                </div>
            </div>

        </div>
    </div>

    <!-- ==========================================================================
       VIEW-ONLY MODAL 2: WEDDING PACKAGES (Panel 6)
       ========================================================================== -->
    <div class="package-view-overlay" id="weddingPackageModal" onclick="handleOverlayClick(event, this)">
        <div class="package-view-modal">
            
            <div class="package-view-header">
                <div>
                    <h2 style="font-family: 'Playfair Display', serif; font-size: 22px; font-weight: 700; margin: 0 0 4px 0; color: #ffffff;">Wedding Package</h2>
                    <p style="margin: 0; font-size: 13px; color: rgba(255,255,255,0.85);">Complete and elegant celebration package.</p>
                </div>
                <button type="button" class="modal-close-icon" onclick="closePackageModal('weddingPackageModal')" aria-label="Close">&times;</button>
            </div>

            <div class="modal-split-layout">
                <div>
                    <img src="assets/portfolio/svc-wedding.jpg" alt="Wedding Package" class="modal-split-img" onerror="this.src='assets/portfolio/portfolio-wedding-ceremony.jpg'">
                </div>
                <div class="modal-split-info">
                    <div class="modal-inclusions-title"><i class="fa-solid fa-rings-wedding"></i> Package Inclusions</div>
                    <ul class="modal-inclusions-checklist">
                        <li><i class="fa-solid fa-check"></i> Altar, Aisle &amp; Entrance Arch Ceremony Styling</li>
                        <li><i class="fa-solid fa-check"></i> Reception Head Table &amp; Centerpieces</li>
                        <li><i class="fa-solid fa-check"></i> Bridal Entourage Arrangements &amp; Stage Backdrop</li>
                        <li><i class="fa-solid fa-check"></i> Atmospheric Ambient Lighting &amp; Sound Setup</li>
                        <li><i class="fa-solid fa-check"></i> Full-Day Coordination &amp; Master of Ceremonies</li>
                    </ul>

                    <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 16px; margin-top: 12px;">
                        <i class="fa-solid fa-lock" style="color: var(--primary);"></i> Booking locks Weddings as your selected service in the reservation form.
                    </p>

                    <a href="booking.php?event_type=Weddings" class="btn-event-pill" style="width: 100%; text-align: center; display: block; box-sizing: border-box; text-decoration: none;">
                        <i class="fa-solid fa-calendar-check"></i> Book Wedding Package (Locked to Service)
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
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                    </div>
                    <div>
                        <div style="font-weight: 700; font-size: 14px;">Event Concierge AI</div>
                        <div style="font-size: 11px; opacity: 0.85;">Here to answer general FAQs &amp; packages</div>
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

            <div class="chatbot-messages" id="chatbotMessages">
                <div class="chat-bubble bot">
                    Hello! Welcome to <strong><?= htmlspecialchars($business_name) ?></strong>. I can answer questions regarding our services, Birthday and Wedding packages, pricing, and booking requirements!
                    <div class="quick-chips-wrapper">
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('What packages do you offer?')"><i class="fa-solid fa-gift"></i><span>What packages do you offer?</span></button>
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('How does the booking process work?')"><i class="fa-solid fa-calendar-check"></i><span>How does booking work?</span></button>
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('What is the minimum lead time for reservations?')"><i class="fa-solid fa-clock"></i><span>What is the lead time requirement?</span></button>
                    </div>
                </div>
            </div>

            <div class="chatbot-footer-wrapper">
                <form class="chatbot-footer" onsubmit="sendChatMessage(event)">
                    <textarea id="chatInput" placeholder="Type a message..." rows="1" oninput="autoResizeChatInput(this)" onkeydown="handleChatInputKeydown(event)"></textarea>
                    <button type="submit" aria-label="Send message"><i class="fa-solid fa-paper-plane"></i></button>
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

        // Chatbot Auto-expanding Textarea & Keyboard Controls
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
                    Hello! Welcome to <strong><?= htmlspecialchars($business_name) ?></strong>. I can answer questions regarding our services, Birthday and Wedding packages, pricing, and booking requirements!
                    <div class="quick-chips-wrapper">
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('What packages do you offer?')"><i class="fa-solid fa-gift"></i><span>What packages do you offer?</span></button>
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('How does the booking process work?')"><i class="fa-solid fa-calendar-check"></i><span>How does booking work?</span></button>
                        <button type="button" class="quick-chip" onclick="sendQuickPrompt('What is the minimum lead time for reservations?')"><i class="fa-solid fa-clock"></i><span>What is the lead time requirement?</span></button>
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
            if (!confirm('Are you sure you want to reset the conversation?')) return;
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
                        Conversation has been reset. You have full message capacity available! How can I assist you today?
                        <div class="quick-chips-wrapper">
                            <button type="button" class="quick-chip" onclick="sendQuickPrompt('What packages do you offer?')"><i class="fa-solid fa-gift"></i><span>What packages do you offer?</span></button>
                            <button type="button" class="quick-chip" onclick="sendQuickPrompt('How does the booking process work?')"><i class="fa-solid fa-calendar-check"></i><span>How does booking work?</span></button>
                            <button type="button" class="quick-chip" onclick="sendQuickPrompt('What is the minimum lead time for reservations?')"><i class="fa-solid fa-clock"></i><span>What is the lead time requirement?</span></button>
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

        // Chatbot Window Controls
        function toggleChatWindow() {
            const win = document.getElementById('chatbotWindow');
            win.classList.toggle('active');
            if (win.classList.contains('active')) {
                const input = document.getElementById('chatInput');
                input.focus();
                autoResizeChatInput(input);
            }
        }

        function sendQuickPrompt(text) {
            const input = document.getElementById('chatInput');
            input.value = text;
            autoResizeChatInput(input);
            sendChatMessage(new Event('submit'));
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
            
            // Reset input and its height
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
                botDiv.innerHTML = formatChatMessage(replyText);
                saveMessageToStorage('bot', replyText);
            } catch (err) {
                const fallbackText = "Thank you for messaging Tyoy Creation Concierge! I can answer questions about our event styling packages, services, date availability, check booking status with your reference number, or guide you on how to book your celebration on our website. How can I help you today?";
                botDiv.innerHTML = formatChatMessage(fallbackText);
                saveMessageToStorage('bot', fallbackText);
            }
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        window.addEventListener('DOMContentLoaded', () => {
            renderSynchronizedChat();
            checkCookieConsent();
        });
    </script>
    <script src="assets/app_speed.js?v=<?= filemtime(__DIR__ . '/assets/app_speed.js') ?>"></script>
</body>
</html>
