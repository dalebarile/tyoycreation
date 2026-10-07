<?php
require_once __DIR__ . '/db.php';

// Auth check - Strictly restricted to Main Admin
require_main_admin();

$admin_username = $_SESSION['username'] ?? 'Admin';
$active_tab = $_GET['tab'] ?? 'business';

$msg_success = '';
$msg_error = '';

// Helper for file uploads
function handle_image_upload($file_key, $existing_val) {
    if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
        return $existing_val;
    }
    $file = $_FILES[$file_key];
    $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts)) {
        return $existing_val;
    }
    $target_dir = __DIR__ . '/uploads/';
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0755, true);
    }
    $filename = 'site_' . uniqid() . '.' . $ext;
    $target_path = $target_dir . $filename;
    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        return 'uploads/' . $filename;
    }
    return $existing_val;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_business') {
        $active_tab = 'business';
        $fields = [
            'business_name'    => trim($_POST['business_name'] ?? 'Tyoy Creation'),
            'business_tagline' => trim($_POST['business_tagline'] ?? ''),
            'contact_email'    => trim($_POST['contact_email'] ?? 'contact@tyoycreation.com'),
            'contact_phone'    => trim($_POST['contact_phone'] ?? ''),
            'business_address' => trim($_POST['business_address'] ?? ''),
            'hero_subtitle'    => trim($_POST['hero_subtitle'] ?? ''),
            'about_story'      => trim($_POST['about_story'] ?? ''),
            'mission_statement'=> trim($_POST['mission_statement'] ?? ''),
            'vision_statement' => trim($_POST['vision_statement'] ?? '')
        ];
        foreach ($fields as $k => $v) {
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->bind_param("sss", $k, $v, $v);
            $stmt->execute();
            $stmt->close();
        }
        $msg_success = "Business profile and statements updated successfully!";
    } elseif ($action === 'save_templates') {
        $active_tab = 'templates';
        $fields = [
            'approval_template'  => trim($_POST['approval_template'] ?? ''),
            'rejection_template' => trim($_POST['rejection_template'] ?? ''),
            'reminder_template'  => trim($_POST['reminder_template'] ?? '')
        ];
        foreach ($fields as $k => $v) {
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->bind_param("sss", $k, $v, $v);
            $stmt->execute();
            $stmt->close();
        }
        $msg_success = "Automated email notification templates updated successfully!";
    } elseif ($action === 'save_media') {
        $active_tab = 'media';
        
        // Hero Background
        $hero_bg = trim($_POST['media_hero_bg'] ?? '');
        $hero_bg = handle_image_upload('media_hero_bg_file', $hero_bg);
        if (!empty($hero_bg)) {
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('media_hero_bg', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->bind_param("ss", $hero_bg, $hero_bg);
            $stmt->execute();
            $stmt->close();
        }

        // Kids Party Service Image
        $svc_kids = trim($_POST['media_svc_kids'] ?? '');
        $svc_kids = handle_image_upload('media_svc_kids_file', $svc_kids);
        if (!empty($svc_kids)) {
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('media_svc_kids', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->bind_param("ss", $svc_kids, $svc_kids);
            $stmt->execute();
            $stmt->close();
        }

        // Wedding Service Image
        $svc_wed = trim($_POST['media_svc_wedding'] ?? '');
        $svc_wed = handle_image_upload('media_svc_wedding_file', $svc_wed);
        if (!empty($svc_wed)) {
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('media_svc_wedding', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->bind_param("ss", $svc_wed, $svc_wed);
            $stmt->execute();
            $stmt->close();
        }

        $msg_success = "Landing page section images updated successfully!";
    } elseif ($action === 'save_portfolio') {
        $active_tab = 'portfolio';
        $items = [];
        $titles = $_POST['p_title'] ?? [];
        $cats = $_POST['p_cat'] ?? [];
        $locs = $_POST['p_loc'] ?? [];
        $urls = $_POST['p_img'] ?? [];

        for ($i = 0; $i < count($titles); $i++) {
            $title = trim($titles[$i] ?? '');
            if ($title === '') continue;
            $cat = in_array($cats[$i] ?? '', ['weddings', 'birthdays']) ? $cats[$i] : 'weddings';
            $loc = trim($locs[$i] ?? '');
            $img = trim($urls[$i] ?? '');
            
            // Check for file upload for this item
            $file_key = 'p_file_' . $i;
            $img = handle_image_upload($file_key, $img);

            $items[] = [
                'title'    => $title,
                'category' => $cat,
                'location' => $loc,
                'img'      => $img
            ];
        }

        $json = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('portfolio_items', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param("ss", $json, $json);
        $stmt->execute();
        $stmt->close();

        $msg_success = "Portfolio photos and details updated successfully!";
    }
}

// Fetch current values
$b_name     = get_setting($conn, 'business_name', 'Tyoy Creation');
$b_tagline  = get_setting($conn, 'business_tagline', 'TURNING YOUR MOMENTS INTO Unforgettable Events');
$c_email    = get_setting($conn, 'contact_email', 'contact@tyoycreation.com');
$c_phone    = get_setting($conn, 'contact_phone', '+63 912 345 6789');
$b_address  = get_setting($conn, 'business_address', '123 Grand Ballroom Avenue, Metro Manila, Philippines');
$b_hero_sub = get_setting($conn, 'hero_subtitle', 'Premier event management, creative event styling, and personalized floral creations — crafted with precision, passion, and unwavering dedication since 2020.');
$b_story    = get_setting($conn, 'about_story', "Tyoy Creation was founded in 2020 with a deep passion for flowers and wedding décor. What began as a boutique floral arrangement service quickly grew into a full-service event management and styling business.\n\nToday, we design and execute weddings, kids' parties, themed celebrations, and milestone events — delivering creative event styling, reliable day-of coordination, and personalized floral creations that speak to every client's unique story.\n\nEvery event we handle is built on our core values: creativity, professionalism, efficiency, accuracy, and personalized service.");
$b_mission  = get_setting($conn, 'mission_statement', 'To deliver seamless event management and creative styling that turn visions into unforgettable, stress-free celebrations. Built on efficiency, accuracy, and dedicated support, Tyoy Creation coordinates every detail and manages every partner so our clients can savor every moment with complete peace of mind.');
$b_vision   = get_setting($conn, 'vision_statement', 'To be a premier and trusted choice in event management and styling, recognized for creating priceless, elevated experiences through creative excellence, reliable service, and flawless execution.');

$t_approval  = get_setting($conn, 'approval_template',  'Hello {client_name}, your booking for {event_title} on {event_date} has been APPROVED! Our team will contact you for coordination and downpayment details. Ref: {ref_no}');
$t_rejection = get_setting($conn, 'rejection_template', 'Hello {client_name}, unfortunately we are unavailable for your requested date ({event_date}) due to: {reason}. Please contact us to reschedule. Ref: {ref_no}');
$t_reminder  = get_setting($conn, 'reminder_template',  'Hi {client_name}! Friendly reminder that your event {event_title} is coming up on {event_date} at {venue}. See you soon! Ref: {ref_no}');

$media_hero_bg  = get_setting($conn, 'media_hero_bg', 'assets/tyoy_creation_banner.jpg');
$media_svc_kids = get_setting($conn, 'media_svc_kids', 'assets/portfolio/svc-kids-party.jpg');
$media_svc_wed  = get_setting($conn, 'media_svc_wedding', 'assets/portfolio/svc-wedding.jpg');

// Portfolio items (Default matched to actual live showcase assets)
$default_portfolio = [
    [
        'category' => 'weddings',
        'img'      => 'assets/portfolio/portfolio-wedding-ceremony.jpg',
        'title'    => 'Ceremony Floral Arch Styling',
        'location' => 'Weddings • Church Ceremony Styling'
    ],
    [
        'category' => 'birthdays',
        'img'      => 'assets/portfolio/portfolio-jasmine-aladdin.jpg',
        'title'    => 'Arabian Nights: Jasmine & Aladdin',
        'location' => 'Kids Party • Character Backdrop'
    ],
    [
        'category' => 'weddings',
        'img'      => 'assets/portfolio/portfolio-wedding-couple.jpg',
        'title'    => 'Garden Wedding Portraits',
        'location' => 'Weddings • Full Event Styling'
    ],
    [
        'category' => 'birthdays',
        'img'      => 'assets/portfolio/portfolio-snowwhite-party.jpg',
        'title'    => 'Snow White Themed Celebration',
        'location' => 'Kids Party • Character Backdrop'
    ],
    [
        'category' => 'birthdays',
        'img'      => 'assets/portfolio/portfolio-elisha-carparty.jpg',
        'title'    => 'Vintage Car Themed 1st Birthday',
        'location' => 'Kids Party • Full Event Styling'
    ],
    [
        'category' => 'birthdays',
        'img'      => 'assets/portfolio/portfolio-kiara-magician.jpg',
        'title'    => 'Circus Magician Entertainment',
        'location' => 'Kids Party • Hosts & Entertainment'
    ],
    [
        'category' => 'birthdays',
        'img'      => 'assets/portfolio/portfolio-agatha-butterfly.jpg',
        'title'    => 'Butterfly Garden Celebration',
        'location' => 'Kids Party • Full Event Styling'
    ],
    [
        'category' => 'birthdays',
        'img'      => 'assets/portfolio/portfolio-rabbalucia-boho.jpg',
        'title'    => 'Boho Floral 1st Birthday',
        'location' => 'Kids Party • Full Event Styling'
    ]
];
$saved_p = get_setting($conn, 'portfolio_items', '');
$portfolio_list = !empty($saved_p) ? json_decode($saved_p, true) : null;
if (!is_array($portfolio_list) || empty($portfolio_list)) {
    $portfolio_list = $default_portfolio;
}

// Packages pricing data for quick overview
$kids_packages_data = function_exists('get_packages_pricing') ? get_packages_pricing($conn, 'theme_party') : [];
$wedding_packages_data = function_exists('get_packages_pricing') ? get_packages_pricing($conn, 'wedding') : [];

$kids_total_items = 0;
foreach ($kids_packages_data as $c) {
    $kids_total_items += count($c['items'] ?? []);
}

$wedding_total_items = 0;
foreach ($wedding_packages_data as $c) {
    $wedding_total_items += count($c['items'] ?? []);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Web Templates - Tyoy Creation</title>
    <link rel="icon" type="image/png" href="assets/favicon.png?v=<?= filemtime(__DIR__ . '/assets/favicon.png') ?>">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .site-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 8px;
            flex-wrap: wrap;
        }
        .site-tab-btn {
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-secondary);
            background: #ffffff;
            border: 1px solid var(--border-color);
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .site-tab-btn:hover {
            background: #f3f6f3;
            color: var(--primary);
        }
        .site-tab-btn.active {
            background: #364735;
            color: #ffffff;
            border-color: #364735;
        }
        .manager-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            padding: 28px;
            max-width: 960px;
            margin-bottom: 24px;
        }
        .media-preview-box {
            width: 100%;
            height: 140px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
            margin-bottom: 8px;
            display: block;
        }
        .portfolio-edit-card {
            background: #fbfcfb;
            border: 1px solid #dce5dc;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
        }
    </style>
</head>
<body class="admin-app">

    <!-- Admin Sidebar -->
    <?php include __DIR__ . '/admin_sidebar.php'; ?>

    <main class="admin-main">
        <header class="admin-header">
            <div>
                <h1 class="admin-page-title"><i class="fa-solid fa-palette" style="color: var(--primary); font-size: 22px; margin-right: 8px;"></i> Edit Web Templates</h1>
                <p style="color: var(--text-secondary); font-size: 13px; margin: 2px 0 0 0;">
                    Consolidated section for Packages &amp; Pricing, Business Details, Automated Email Templates, and Website Photos &amp; Portfolio.
                </p>
            </div>
            <div class="admin-profile">
                <div class="admin-avatar" style="background: #d97706; color: #fff;"><?= strtoupper(substr($admin_username, 0, 1)) ?></div>
                <div>
                    <div style="font-size: 14px; font-weight: 600;"><?= htmlspecialchars($admin_username) ?></div>
                    <span style="font-size: 10px; font-weight: 800; background: #d97706; color: #fff; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">Main Admin</span>
                </div>
            </div>
        </header>

        <div class="admin-body">

            <?php if (!empty($msg_success)): ?>
                <div style="background: #d1fae5; color: #065f46; padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 600;">
                    <i class="fa-solid fa-circle-check"></i>
                    <span><?= htmlspecialchars($msg_success) ?></span>
                </div>
            <?php endif; ?>

            <!-- Navigation Tabs -->
            <div class="site-tabs">
                <a href="?tab=business" class="site-tab-btn <?= ($active_tab === 'business') ? 'active' : '' ?>">
                    <i class="fa-solid fa-building"></i> Business Information
                </a>
                <a href="?tab=packages" class="site-tab-btn <?= ($active_tab === 'packages') ? 'active' : '' ?>">
                    <i class="fa-solid fa-tags"></i> Packages &amp; Pricing
                </a>
                <a href="?tab=templates" class="site-tab-btn <?= ($active_tab === 'templates') ? 'active' : '' ?>">
                    <i class="fa-solid fa-envelope"></i> Email Templates
                </a>
                <a href="?tab=media" class="site-tab-btn <?= ($active_tab === 'media') ? 'active' : '' ?>">
                    <i class="fa-solid fa-images"></i> Section Photos
                </a>
                <a href="?tab=portfolio" class="site-tab-btn <?= ($active_tab === 'portfolio') ? 'active' : '' ?>">
                    <i class="fa-solid fa-photo-film"></i> Portfolio Gallery
                </a>
            </div>

            <!-- TAB 1: Business Information -->
            <?php if ($active_tab === 'business'): ?>
            <div class="manager-card" style="border-top: 4px solid var(--primary);">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px;">
                    <div style="width: 38px; height: 38px; border-radius: 8px; background: #eef2ee; color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 18px;">
                        <i class="fa-solid fa-building"></i>
                    </div>
                    <div>
                        <h2 style="font-size: 18px; margin: 0;">Business Profile &amp; Contact Info</h2>
                        <p style="color: var(--text-secondary); font-size: 13px; margin: 2px 0 0;">Updated details appear on the landing page header, footer, and booking views.</p>
                    </div>
                </div>

                <form method="POST" action="a_sitemanager.php?tab=business">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="save_business">

                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label">Business Name</label>
                            <input type="text" name="business_name" class="form-control" value="<?= htmlspecialchars($b_name) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Contact Phone</label>
                            <input type="text" name="contact_phone" class="form-control" value="<?= htmlspecialchars($c_phone) ?>" required>
                        </div>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label class="form-label">Official Email</label>
                            <input type="email" name="contact_email" class="form-control" value="<?= htmlspecialchars($c_email) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tagline / Motto</label>
                            <input type="text" name="business_tagline" class="form-control" value="<?= htmlspecialchars($b_tagline) ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Studio / Office Address</label>
                        <input type="text" name="business_address" class="form-control" value="<?= htmlspecialchars($b_address) ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Landing Page Hero Subtitle / Summary</label>
                        <input type="text" name="hero_subtitle" class="form-control" value="<?= htmlspecialchars($b_hero_sub) ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">About Us / Our Story Text</label>
                        <textarea name="about_story" class="form-control" rows="4"><?= htmlspecialchars($b_story) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Mission Statement</label>
                        <textarea name="mission_statement" class="form-control" rows="3"><?= htmlspecialchars($b_mission) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Vision Statement</label>
                        <textarea name="vision_statement" class="form-control" rows="3"><?= htmlspecialchars($b_vision) ?></textarea>
                    </div>

                    <button type="submit" class="btn-primary" style="padding: 11px 26px; font-size: 14px; margin-top: 10px;">
                        <i class="fa-solid fa-floppy-disk"></i> Save Business Information
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <!-- TAB 2: Packages & Pricing -->
            <?php if ($active_tab === 'packages'): ?>
            <div class="manager-card" style="border-top: 4px solid var(--primary);">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 38px; height: 38px; border-radius: 8px; background: #eef2ee; color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 18px;">
                            <i class="fa-solid fa-tags"></i>
                        </div>
                        <div>
                            <h2 style="font-size: 18px; margin: 0;">Packages &amp; Pricing Management</h2>
                            <p style="color: var(--text-secondary); font-size: 13px; margin: 2px 0 0;">Manage your service families, tier packages, pricing, inclusions, and addons directly.</p>
                        </div>
                    </div>
                    <a href="a_packages.php" class="btn-primary" style="text-decoration: none; display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px;">
                        <i class="fa-solid fa-pen-to-square"></i> Open Full Packages Editor
                    </a>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">
                    <!-- Kids Party Packages Card -->
                    <div style="border: 1px solid #dce5dc; border-radius: 12px; padding: 22px; background: #fbfcfb; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="width: 36px; height: 36px; border-radius: 8px; background: #fef3c7; color: #b45309; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                                        <i class="fa-solid fa-cake-candles"></i>
                                    </div>
                                    <h3 style="margin: 0; font-size: 16px; font-weight: 700;">Kids Party Packages</h3>
                                </div>
                                <span style="font-size: 11px; font-weight: 700; background: #e0f2fe; color: #0369a1; padding: 3px 8px; border-radius: 6px;">
                                    <?= $kids_total_items ?> Items • <?= count($kids_packages_data) ?> Categories
                                </span>
                            </div>
                            <p style="font-size: 13px; color: #4b5563; margin-bottom: 14px; line-height: 1.5;">
                                Themed styling, character backdrop packages, full celebrations, and customizable party addons.
                            </p>
                            
                            <div style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; font-size: 12px; color: var(--text-secondary);">
                                <strong style="display: block; color: var(--text-primary); margin-bottom: 4px;">Active Categories:</strong>
                                <?php foreach ($kids_packages_data as $ck => $cd): ?>
                                    <span style="display: inline-block; background: #f3f4f6; padding: 2px 8px; border-radius: 4px; margin: 2px 2px 2px 0;">
                                        <?= htmlspecialchars($cd['category_title'] ?? $ck) ?> (<?= count($cd['items'] ?? []) ?>)
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <a href="a_packages.php?family=theme_party" class="btn-secondary" style="text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 10px 16px; font-weight: 600;">
                            <i class="fa-solid fa-pen-to-square"></i> Edit Kids Party Pricing &amp; Items &rsaquo;
                        </a>
                    </div>

                    <!-- Wedding Packages Card -->
                    <div style="border: 1px solid #dce5dc; border-radius: 12px; padding: 22px; background: #fbfcfb; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="width: 36px; height: 36px; border-radius: 8px; background: #fce7f3; color: #be185d; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                                        <i class="fa-solid fa-champagne-glasses"></i>
                                    </div>
                                    <h3 style="margin: 0; font-size: 16px; font-weight: 700;">Wedding Packages</h3>
                                </div>
                                <span style="font-size: 11px; font-weight: 700; background: #fef2f2; color: #b91c1c; padding: 3px 8px; border-radius: 6px;">
                                    <?= $wedding_total_items ?> Items • <?= count($wedding_packages_data) ?> Categories
                                </span>
                            </div>
                            <p style="font-size: 13px; color: #4b5563; margin-bottom: 14px; line-height: 1.5;">
                                Custom church &amp; reception floral styling, entourage arrangements, arches, and day-of coordination.
                            </p>

                            <div style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; font-size: 12px; color: var(--text-secondary);">
                                <strong style="display: block; color: var(--text-primary); margin-bottom: 4px;">Active Categories:</strong>
                                <?php foreach ($wedding_packages_data as $ck => $cd): ?>
                                    <span style="display: inline-block; background: #f3f4f6; padding: 2px 8px; border-radius: 4px; margin: 2px 2px 2px 0;">
                                        <?= htmlspecialchars($cd['category_title'] ?? $ck) ?> (<?= count($cd['items'] ?? []) ?>)
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <a href="a_packages.php?family=wedding" class="btn-secondary" style="text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 10px 16px; font-weight: 600;">
                            <i class="fa-solid fa-pen-to-square"></i> Edit Wedding Pricing &amp; Items &rsaquo;
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- TAB 3: Email Templates -->
            <?php if ($active_tab === 'templates'): ?>
            <div class="manager-card" style="border-top: 4px solid var(--primary);">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px;">
                    <div style="width: 38px; height: 38px; border-radius: 8px; background: #eef2ee; color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 18px;">
                        <i class="fa-solid fa-envelope"></i>
                    </div>
                    <div>
                        <h2 style="font-size: 18px; margin: 0;">Automated Email Templates</h2>
                        <p style="color: var(--text-secondary); font-size: 13px; margin: 2px 0 0;">
                            Placeholders: <code>{client_name}</code>, <code>{event_title}</code>, <code>{event_date}</code>, <code>{ref_no}</code>, <code>{venue}</code>, <code>{reason}</code>
                        </p>
                    </div>
                </div>

                <form method="POST" action="a_sitemanager.php?tab=templates">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="save_templates">

                    <div class="form-group">
                        <label class="form-label"><i class="fa-solid fa-circle-check" style="color:#059669; margin-right:4px;"></i> Booking Approval Notice</label>
                        <textarea name="approval_template" class="form-control" rows="3"><?= htmlspecialchars($t_approval) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="fa-solid fa-circle-xmark" style="color:#dc2626; margin-right:4px;"></i> Rejection / Reschedule Notice</label>
                        <textarea name="rejection_template" class="form-control" rows="3"><?= htmlspecialchars($t_rejection) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="fa-solid fa-bell" style="color:#d97706; margin-right:4px;"></i> Event Reminder Notice</label>
                        <textarea name="reminder_template" class="form-control" rows="3"><?= htmlspecialchars($t_reminder) ?></textarea>
                    </div>

                    <button type="submit" class="btn-primary" style="padding: 11px 26px; font-size: 14px; margin-top: 10px;">
                        <i class="fa-solid fa-floppy-disk"></i> Save Email Templates
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <!-- TAB 4: Section Photos -->
            <?php if ($active_tab === 'media'): ?>
            <div class="manager-card" style="border-top: 4px solid var(--primary);">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px;">
                    <div style="width: 38px; height: 38px; border-radius: 8px; background: #eef2ee; color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 18px;">
                        <i class="fa-solid fa-images"></i>
                    </div>
                    <div>
                        <h2 style="font-size: 18px; margin: 0;">Landing Page Section Photos</h2>
                        <p style="color: var(--text-secondary); font-size: 13px; margin: 2px 0 0;">Upload new image files or paste direct image URLs for the key landing page sections.</p>
                    </div>
                </div>

                <form method="POST" action="a_sitemanager.php?tab=media" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="save_media">

                    <!-- Hero Background -->
                    <div style="border: 1px solid var(--border-color); border-radius: 10px; padding: 20px; margin-bottom: 20px; background: #ffffff;">
                        <h3 style="font-size: 15px; margin: 0 0 12px 0;">Hero Header Background Image</h3>
                        <div style="display: grid; grid-template-columns: 200px 1fr; gap: 20px; align-items: center;">
                            <div>
                                <img src="<?= htmlspecialchars($media_hero_bg) ?>" class="media-preview-box" onerror="this.src='assets/tyoy_creation_banner.jpg'" alt="Hero Preview">
                            </div>
                            <div>
                                <div class="form-group" style="margin-bottom: 10px;">
                                    <label class="form-label">Image URL / Path</label>
                                    <input type="text" name="media_hero_bg" class="form-control" value="<?= htmlspecialchars($media_hero_bg) ?>">
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label class="form-label">Or Upload New Image (JPG, PNG, WebP)</label>
                                    <input type="file" name="media_hero_bg_file" class="form-control" accept="image/*">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Kids Party Service Image -->
                    <div style="border: 1px solid var(--border-color); border-radius: 10px; padding: 20px; margin-bottom: 20px; background: #ffffff;">
                        <h3 style="font-size: 15px; margin: 0 0 12px 0;">Kids Party Service Card Image</h3>
                        <div style="display: grid; grid-template-columns: 200px 1fr; gap: 20px; align-items: center;">
                            <div>
                                <img src="<?= htmlspecialchars($media_svc_kids) ?>" class="media-preview-box" alt="Kids Party Preview">
                            </div>
                            <div>
                                <div class="form-group" style="margin-bottom: 10px;">
                                    <label class="form-label">Image URL / Path</label>
                                    <input type="text" name="media_svc_kids" class="form-control" value="<?= htmlspecialchars($media_svc_kids) ?>">
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label class="form-label">Or Upload New Image</label>
                                    <input type="file" name="media_svc_kids_file" class="form-control" accept="image/*">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Wedding Service Image -->
                    <div style="border: 1px solid var(--border-color); border-radius: 10px; padding: 20px; margin-bottom: 20px; background: #ffffff;">
                        <h3 style="font-size: 15px; margin: 0 0 12px 0;">Weddings &amp; Milestones Service Card Image</h3>
                        <div style="display: grid; grid-template-columns: 200px 1fr; gap: 20px; align-items: center;">
                            <div>
                                <img src="<?= htmlspecialchars($media_svc_wed) ?>" class="media-preview-box" alt="Wedding Preview">
                            </div>
                            <div>
                                <div class="form-group" style="margin-bottom: 10px;">
                                    <label class="form-label">Image URL / Path</label>
                                    <input type="text" name="media_svc_wedding" class="form-control" value="<?= htmlspecialchars($media_svc_wed) ?>">
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label class="form-label">Or Upload New Image</label>
                                    <input type="file" name="media_svc_wedding_file" class="form-control" accept="image/*">
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary" style="padding: 11px 26px; font-size: 14px;">
                        <i class="fa-solid fa-floppy-disk"></i> Save Section Images
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <!-- TAB 5: Portfolio Gallery -->
            <?php if ($active_tab === 'portfolio'): ?>
            <div class="manager-card" style="border-top: 4px solid var(--primary);">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px;">
                    <div style="width: 38px; height: 38px; border-radius: 8px; background: #eef2ee; color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 18px;">
                        <i class="fa-solid fa-photo-film"></i>
                    </div>
                    <div>
                        <h2 style="font-size: 18px; margin: 0;">Portfolio Gallery Photos &amp; Information</h2>
                        <p style="color: var(--text-secondary); font-size: 13px; margin: 2px 0 0;">
                            Customize the photos displayed in the landing page portfolio. You can edit captions, categories, and replace photos via upload or URL.
                        </p>
                    </div>
                </div>

                <form method="POST" action="a_sitemanager.php?tab=portfolio" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="save_portfolio">

                    <?php foreach ($portfolio_list as $idx => $p): ?>
                    <div class="portfolio-edit-card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <strong style="font-size: 14px; color: var(--primary);">Portfolio Item #<?= $idx + 1 ?></strong>
                            <span style="font-size: 12px; background: #eef2ee; color: var(--primary); padding: 3px 8px; border-radius: 6px; font-weight: 600;">
                                <?= ($p['category'] === 'weddings') ? 'Wedding' : 'Kids Party' ?>
                            </span>
                        </div>

                        <div style="display: grid; grid-template-columns: 140px 1fr; gap: 16px; align-items: start;">
                            <div>
                                <img src="<?= htmlspecialchars($p['img']) ?>" class="media-preview-box" style="height: 110px;" alt="Item Preview">
                            </div>
                            <div>
                                <div class="form-row-2">
                                    <div class="form-group" style="margin-bottom: 10px;">
                                        <label class="form-label">Title / Event Name</label>
                                        <input type="text" name="p_title[]" class="form-control" value="<?= htmlspecialchars($p['title']) ?>" required>
                                    </div>
                                    <div class="form-group" style="margin-bottom: 10px;">
                                        <label class="form-label">Category</label>
                                        <select name="p_cat[]" class="form-control">
                                            <option value="weddings" <?= ($p['category'] === 'weddings') ? 'selected' : '' ?>>Weddings</option>
                                            <option value="birthdays" <?= ($p['category'] === 'birthdays') ? 'selected' : '' ?>>Kids Party</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-row-2">
                                    <div class="form-group" style="margin-bottom: 10px;">
                                        <label class="form-label">Location / Subtitle</label>
                                        <input type="text" name="p_loc[]" class="form-control" value="<?= htmlspecialchars($p['location']) ?>">
                                    </div>
                                    <div class="form-group" style="margin-bottom: 10px;">
                                        <label class="form-label">Image URL</label>
                                        <input type="text" name="p_img[]" class="form-control" value="<?= htmlspecialchars($p['img']) ?>">
                                    </div>
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label class="form-label" style="font-size: 12px;">Or Upload New Photo for Item #<?= $idx + 1 ?></label>
                                    <input type="file" name="p_file_<?= $idx ?>" class="form-control" accept="image/*">
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <button type="submit" class="btn-primary" style="padding: 12px 28px; font-size: 14px; margin-top: 10px;">
                        <i class="fa-solid fa-floppy-disk"></i> Save Portfolio Gallery
                    </button>
                </form>
            </div>
            <?php endif; ?>

        </div>
    </main>
</body>
</html>
