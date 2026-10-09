<?php
require_once __DIR__ . '/db.php';

// Auth check - Strictly restricted to Main Admin
require_main_admin();

$business_name = get_setting($conn, 'business_name', 'Tyoy Creation');
$admin_username = $_SESSION['username'] ?? 'Admin';

// Determine active family tab
$active_family = trim($_GET['family'] ?? $_GET['tab'] ?? 'theme_party');
if (!in_array($active_family, ['theme_party', 'wedding'], true)) {
    $active_family = 'theme_party';
}

$flash_success = '';
$flash_error = '';

// Handle POST actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $action = $_POST['action'] ?? '';
    $family = $_POST['family'] ?? $active_family;
    if (!in_array($family, ['theme_party', 'wedding'], true)) {
        $family = 'theme_party';
    }
    $active_family = $family;

    if ($action === 'add_item') {
        $cat_key = trim($_POST['cat_key'] ?? '');
        $label = trim($_POST['label'] ?? '');
        $price = max(0, (int)($_POST['price'] ?? 0));

        if (empty($cat_key)) {
            $flash_error = "Please select a category for the new item.";
        } elseif (empty($label)) {
            $flash_error = "Package or item label cannot be empty.";
        } else {
            $res = add_package_item($conn, $family, $cat_key, $label, $price);
            if ($res['success']) {
                $flash_success = "Package/Item '{$label}' has been added successfully! Changes are immediately live.";
            } else {
                $flash_error = $res['message'] ?? "Failed to add item.";
            }
        }
    } elseif ($action === 'update_item') {
        $cat_key = trim($_POST['cat_key'] ?? '');
        $item_id = trim($_POST['item_id'] ?? '');
        $label = trim($_POST['label'] ?? '');
        $price = max(0, (int)($_POST['price'] ?? 0));
        $target_cat = trim($_POST['target_cat'] ?? $cat_key);

        if (empty($cat_key) || empty($item_id)) {
            $flash_error = "Invalid item identifier.";
        } elseif (empty($label)) {
            $flash_error = "Package or item label cannot be empty.";
        } else {
            $res = update_package_item($conn, $family, $cat_key, $item_id, $label, $price, $target_cat);
            if ($res['success']) {
                $flash_success = "Package/Item '{$label}' has been updated successfully!";
            } else {
                $flash_error = $res['message'] ?? "Failed to update item.";
            }
        }
    } elseif ($action === 'delete_item') {
        $cat_key = trim($_POST['cat_key'] ?? '');
        $item_id = trim($_POST['item_id'] ?? '');
        $item_name = trim($_POST['item_name'] ?? 'Item');

        if (empty($cat_key) || empty($item_id)) {
            $flash_error = "Invalid item identifier.";
        } else {
            $res = delete_package_item($conn, $family, $cat_key, $item_id);
            if ($res['success']) {
                $flash_success = "Package/Item '{$item_name}' was deleted successfully.";
            } else {
                $flash_error = $res['message'] ?? "Failed to delete item.";
            }
        }
    } elseif ($action === 'add_category') {
        $title = trim($_POST['cat_title'] ?? '');
        $type = in_array($_POST['cat_type'] ?? '', ['radio', 'checkbox'], true) ? $_POST['cat_type'] : 'radio';
        $icon = trim($_POST['cat_icon'] ?? 'fa-tag');

        if (empty($title)) {
            $flash_error = "Category title cannot be empty.";
        } else {
            $res = add_package_category($conn, $family, $title, $type, $icon);
            if ($res['success']) {
                $flash_success = "New category '{$title}' created successfully!";
            } else {
                $flash_error = $res['message'] ?? "Failed to create category.";
            }
        }
    } elseif ($action === 'delete_category') {
        $cat_key = trim($_POST['cat_key'] ?? '');
        $cat_name = trim($_POST['cat_name'] ?? 'Category');

        if (empty($cat_key)) {
            $flash_error = "Invalid category identifier.";
        } else {
            $res = delete_package_category($conn, $family, $cat_key);
            if ($res['success']) {
                $flash_success = "Category '{$cat_name}' and all its items were deleted.";
            } else {
                $flash_error = $res['message'] ?? "Failed to delete category.";
            }
        }
    } elseif ($action === 'reset_defaults') {
        $ok = reset_family_pricing_to_default($conn, $family);
        $fam_label = ($family === 'wedding') ? 'Wedding' : 'Kids Party';
        if ($ok) {
            $flash_success = "All {$fam_label} packages and pricing have been reset to factory defaults.";
        } else {
            $flash_error = "Failed to reset to defaults.";
        }
    }
}

// Fetch current pricing catalogs
$theme_pricing = get_packages_pricing($conn, 'theme_party');
$wedding_pricing = get_packages_pricing($conn, 'wedding');
$current_catalog = ($active_family === 'wedding') ? $wedding_pricing : $theme_pricing;

// Count items in each family
$theme_item_count = 0;
foreach ($theme_pricing as $c) {
    $theme_item_count += count($c['items'] ?? []);
}
$wedding_item_count = 0;
foreach ($wedding_pricing as $c) {
    $wedding_item_count += count($c['items'] ?? []);
}
$current_family_item_count = ($active_family === 'wedding') ? $wedding_item_count : $theme_item_count;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Packages &amp; Pricing Management - <?= htmlspecialchars($business_name) ?></title>
    <link rel="icon" type="image/png" href="assets/favicon.png?v=<?= filemtime(__DIR__ . '/assets/favicon.png') ?>">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .packages-topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .packages-title-area h1 {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-primary);
            margin: 0 0 6px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .packages-title-area p {
            font-size: 13px;
            color: var(--text-secondary);
            margin: 0;
        }

        .packages-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-add-item {
            background: var(--primary);
            color: #ffffff !important;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 8px rgba(43, 83, 41, 0.25);
            transition: all 0.2s;
        }

        .btn-add-item:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        .btn-add-cat {
            background: #ffffff;
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-add-cat:hover {
            background: #f8faf8;
            border-color: #cbd5e1;
        }

        .btn-reset-defaults {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .btn-reset-defaults:hover {
            background: #fecaca;
        }

        /* Family Switcher Tabs */
        .family-tabs-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 12px;
        }

        .family-tab-btn {
            padding: 10px 22px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s ease;
            background: #f1f5f9;
            color: #475569;
        }

        .family-tab-btn.active {
            background: #2b5329;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(43, 83, 41, 0.25);
        }

        .tab-counter {
            background: rgba(255, 255, 255, 0.25);
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 800;
        }

        .family-tab-btn:not(.active) .tab-counter {
            background: #cbd5e1;
            color: #334155;
        }

        /* Search & Filter Bar */
        .search-filter-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
            flex-wrap: wrap;
        }

        .search-input-wrap {
            position: relative;
            flex: 1;
            min-width: 240px;
        }

        .search-input-wrap i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 14px;
        }

        .search-input-wrap input {
            width: 100%;
            padding: 9px 12px 9px 36px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 13px;
            outline: none;
            box-sizing: border-box;
        }

        .search-input-wrap input:focus {
            border-color: var(--primary);
        }

        /* Category Panels */
        .category-box {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            margin-bottom: 24px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
            transition: all 0.2s ease;
        }

        .category-box-header {
            background: #f8faf8;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .category-header-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .cat-icon-badge {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: #eef2ee;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .cat-title-text {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
        }

        .cat-meta-pills {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .pill-badge {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .pill-radio {
            background: #fef3c7;
            color: #92400e;
        }

        .pill-check {
            background: #e0e7ff;
            color: #3730a3;
        }

        .pill-count {
            background: #e2e8f0;
            color: #475569;
        }

        .category-header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-inline-add {
            background: #eef2ee;
            color: var(--primary);
            border: 1px solid rgba(43, 83, 41, 0.2);
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }

        .btn-inline-add:hover {
            background: #2b5329;
            color: #ffffff;
        }

        .btn-del-cat {
            background: transparent;
            color: #94a3b8;
            border: none;
            padding: 6px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s;
        }

        .btn-del-cat:hover {
            color: #dc2626;
            background: #fee2e2;
        }

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .items-table th {
            text-align: left;
            padding: 10px 20px;
            background: #ffffff;
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid #f1f5f9;
        }

        .items-table td {
            padding: 14px 20px;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text-primary);
            vertical-align: middle;
        }

        .items-table tr:last-child td {
            border-bottom: none;
        }

        .items-table tr:hover td {
            background: #fafbfa;
        }

        .item-label-text {
            font-weight: 600;
            color: #1e293b;
        }

        .item-price-display {
            font-weight: 800;
            color: var(--primary);
            font-size: 14px;
        }

        .price-badge-free {
            background: #dcfce7;
            color: #166534;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-block;
        }

        .action-btns-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            justify-content: flex-end;
        }

        .btn-action-edit {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }

        .btn-action-edit:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .btn-action-delete {
            background: #fff1f2;
            color: #e11d48;
            border: 1px solid #fecdd3;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }

        .btn-action-delete:hover {
            background: #ffe4e6;
            color: #be123c;
        }

        .empty-cat-message {
            padding: 24px;
            text-align: center;
            color: var(--text-muted);
            font-size: 13px;
        }

        /* Modals */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 15, 0.75);
            backdrop-filter: blur(4px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-card-box {
            background: #ffffff;
            border-radius: 16px;
            width: 100%;
            max-width: 500px;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            animation: zoomModal 0.2s ease;
        }

        @keyframes zoomModal {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }

        .modal-card-header {
            background: #f8faf8;
            padding: 18px 24px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-card-header h3 {
            font-size: 17px;
            font-weight: 700;
            margin: 0;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-card-body {
            padding: 24px;
        }

        .modal-card-footer {
            padding: 16px 24px;
            background: #f8faf8;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn-modal-cancel {
            background: #ffffff;
            border: 1px solid #d1d5db;
            color: #374151;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-modal-save {
            background: var(--primary);
            border: none;
            color: #ffffff;
            padding: 9px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-modal-save:hover {
            background: var(--primary-hover);
        }

        .btn-modal-danger {
            background: #dc2626;
            border: none;
            color: #ffffff;
            padding: 9px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-modal-danger:hover {
            background: #b91c1c;
        }

        .modal-form-group {
            margin-bottom: 16px;
        }

        .modal-form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }

        .modal-form-group input,
        .modal-form-group select {
            width: 100%;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            font-size: 14px;
            box-sizing: border-box;
            outline: none;
        }

        .modal-form-group input:focus,
        .modal-form-group select:focus {
            border-color: var(--primary);
        }

        .alert-box {
            padding: 14px 18px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
    </style>
</head>
<body class="admin-app">
    <?php include __DIR__ . '/admin_sidebar.php'; ?>

    <main class="admin-main">
        <header class="admin-header">
            <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
                <a href="a_sitemanager.php?tab=packages" class="btn-secondary" style="text-decoration: none; padding: 7px 12px; font-size: 13px; display: inline-flex; align-items: center; gap: 6px;" title="Back to Edit Web Templates">
                    <i class="fa-solid fa-arrow-left"></i> Web Templates
                </a>
                <h1 class="admin-page-title" style="margin: 0;"><i class="fa-solid fa-tags" style="color: var(--primary); font-size: 20px; margin-right: 8px;"></i> Edit Web Templates &rsaquo; Packages &amp; Pricing</h1>
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
            
            <!-- Flash Messages -->
            <?php if (!empty($flash_success)): ?>
                <div class="alert-box alert-success">
                    <i class="fa-solid fa-circle-check" style="font-size: 18px;"></i>
                    <span><?= htmlspecialchars($flash_success) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($flash_error)): ?>
                <div class="alert-box alert-error">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 18px;"></i>
                    <span><?= htmlspecialchars($flash_error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Action Bar -->
            <div class="packages-topbar">
                <div class="packages-title-area">
                    <h2 style="font-size: 19px; font-weight: 800; color: var(--text-primary); margin: 0 0 4px 0;">Live Catalog &amp; Pricing Control</h2>
                    <p>Create, update, and delete service packages, equipment add-ons, and real-time live pricing</p>
                </div>

                <div class="packages-actions">
                    <button type="button" class="btn-add-item" onclick="openAddItemModal()">
                        <i class="fa-solid fa-plus"></i> Add New Package / Item
                    </button>
                    <button type="button" class="btn-add-cat" onclick="openAddCatModal()">
                        <i class="fa-solid fa-folder-plus"></i> Add Category
                    </button>
                </div>
            </div>

            <!-- Family Tabs -->
            <div class="family-tabs-bar">
                <a href="a_packages.php?family=theme_party" class="family-tab-btn <?= ($active_family === 'theme_party') ? 'active' : '' ?>">
                    <i class="fa-solid fa-cake-candles"></i>
                    <span>Kids Party Packages</span>
                    <span class="tab-counter"><?= $theme_item_count ?> items</span>
                </a>
                <a href="a_packages.php?family=wedding" class="family-tab-btn <?= ($active_family === 'wedding') ? 'active' : '' ?>">
                    <i class="fa-solid fa-rings-wedding"></i>
                    <span>Wedding Packages</span>
                    <span class="tab-counter"><?= $wedding_item_count ?> items</span>
                </a>
            </div>

            <!-- Single Unified Catalog Table Card -->
            <div class="unified-catalog-card" style="background: #ffffff; border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; box-shadow: 0 4px 14px rgba(0, 0, 0, 0.03); margin-bottom: 30px;">
                <!-- Table Controls / Toolbar: Category Dropdown & Reset Defaults on Top Right -->
                <div class="unified-table-toolbar" style="display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid var(--border-color); background: #fbfdfa; flex-wrap: wrap; gap: 14px;">
                    <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap; flex: 1;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <label for="unifiedCategorySelect" style="font-weight: 700; font-size: 13px; color: var(--text-primary); display: flex; align-items: center; gap: 6px; white-space: nowrap;">
                                <i class="fa-solid fa-layer-group" style="color: var(--primary);"></i> Category:
                            </label>
                            <select id="unifiedCategorySelect" onchange="filterUnifiedTableByCategory(this.value)" style="padding: 9px 14px; border-radius: 8px; border: 1px solid #d1d5db; font-size: 13px; font-weight: 600; outline: none; background: #ffffff; min-width: 290px; cursor: pointer;">
                                <option value="all">All Categories (<?= $current_family_item_count ?> Total Items)</option>
                                <?php foreach ($current_catalog as $cat_key => $cat_info): ?>
                                    <option value="<?= htmlspecialchars($cat_key) ?>" data-title="<?= htmlspecialchars($cat_info['category_title'] ?? $cat_key) ?>">
                                        <?= htmlspecialchars($cat_info['category_title'] ?? $cat_key) ?> (<?= count($cat_info['items'] ?? []) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="position: relative; flex: 1; max-width: 320px; min-width: 200px;">
                            <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 13px;"></i>
                            <input type="text" id="unifiedSearchInput" placeholder="Filter item by name..." oninput="filterUnifiedTableBySearch(this.value)" style="width: 100%; padding: 8px 12px 8px 34px; border-radius: 8px; border: 1px solid #d1d5db; font-size: 13px; outline: none; box-sizing: border-box;">
                        </div>
                    </div>

                    <!-- Reset Defaults strictly on the top right side of the table -->
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <button type="button" class="btn-reset-defaults" onclick="openResetDefaultsModal()" title="Restore default catalog configuration" style="margin: 0; box-shadow: 0 1px 3px rgba(185, 28, 28, 0.1);">
                            <i class="fa-solid fa-rotate-left"></i> Reset Defaults
                        </button>
                    </div>
                </div>

                <!-- Category Sub-Bar (Active when a specific category is selected) -->
                <div id="categoryActiveInfoBar" style="display: none; padding: 10px 20px; background: #f0fdf4; border-bottom: 1px solid #bbf7d0; font-size: 13px; justify-content: space-between; align-items: center;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="color: #166534; font-weight: 700;">Selected Category:</span>
                        <span id="activeCategoryNameDisplay" style="color: #15803d; font-weight: 600;">-</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <button type="button" class="btn-inline-add" id="activeCategoryAddBtn" onclick="openAddItemForActiveCategory()">
                            <i class="fa-solid fa-plus"></i> Add Item to Category
                        </button>
                        <button type="button" class="btn-del-cat" id="activeCategoryDelBtn" title="Delete Category" onclick="confirmDeleteActiveCategory()">
                            <i class="fa-solid fa-trash-can"></i> Delete Category
                        </button>
                    </div>
                </div>

                <!-- Single Unified Table -->
                <div style="overflow-x: auto;">
                    <table class="items-table" id="unifiedPackagesTable" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th style="width: 26%;">Category / Service</th>
                                <th style="width: 37%;">Package / Item Name</th>
                                <th style="width: 15%;">Price (PHP)</th>
                                <th style="width: 11%;">Selection Type</th>
                                <th style="width: 11%; text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="unifiedTableBody">
                            <?php 
                            $total_rendered = 0;
                            foreach ($current_catalog as $cat_key => $cat_info): 
                                $items = $cat_info['items'] ?? [];
                                $is_checkbox = (($cat_info['type'] ?? '') === 'checkbox');
                                $cat_title = $cat_info['category_title'] ?? $cat_key;
                                $cat_icon = $cat_info['icon'] ?? 'fa-circle-dot';
                                foreach ($items as $item):
                                    $total_rendered++;
                                    $price = (int)($item['price'] ?? 0);
                            ?>
                                <tr class="unified-package-row" 
                                    data-cat="<?= htmlspecialchars($cat_key) ?>" 
                                    data-cat-title="<?= strtolower(htmlspecialchars($cat_title)) ?>" 
                                    data-label="<?= strtolower(htmlspecialchars($item['label'] ?? '')) ?>">
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div class="cat-icon-badge" style="width: 30px; height: 30px; font-size: 13px; flex-shrink: 0;">
                                                <i class="fa-solid <?= htmlspecialchars($cat_icon) ?>"></i>
                                            </div>
                                            <div>
                                                <span style="font-weight: 700; color: var(--text-primary); font-size: 13px;"><?= htmlspecialchars($cat_title) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="item-label-text"><?= htmlspecialchars($item['label'] ?? '') ?></div>
                                    </td>
                                    <td>
                                        <?php if ($price > 0): ?>
                                            <div class="item-price-display">&#8369;<?= number_format($price) ?></div>
                                        <?php else: ?>
                                            <span class="price-badge-free">Included / &#8369;0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="pill-badge <?= $is_checkbox ? 'pill-check' : 'pill-radio' ?>" style="font-size: 10px; padding: 2px 8px;">
                                            <?= $is_checkbox ? 'Pick Any' : 'Pick One' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-btns-wrap">
                                            <button type="button" class="btn-action-edit" 
                                                    onclick="openEditItemModal('<?= htmlspecialchars(addslashes($cat_key)) ?>', '<?= htmlspecialchars(addslashes($item['id'])) ?>', '<?= htmlspecialchars(addslashes($item['label'])) ?>', <?= $price ?>)">
                                                <i class="fa-solid fa-pen-to-square"></i> Edit
                                            </button>
                                            <button type="button" class="btn-action-delete" 
                                                    onclick="confirmDeleteItem('<?= htmlspecialchars(addslashes($cat_key)) ?>', '<?= htmlspecialchars(addslashes($item['id'])) ?>', '<?= htmlspecialchars(addslashes($item['label'])) ?>')">
                                                <i class="fa-solid fa-trash"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php 
                                endforeach;
                            endforeach; 
                            ?>

                            <?php if ($total_rendered === 0): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 40px 20px; color: #64748b;">
                                        <i class="fa-solid fa-box-open" style="font-size: 28px; color: #cbd5e1; display: block; margin-bottom: 10px;"></i>
                                        No items registered in this catalog yet. Click <strong>Add New Package / Item</strong> above to get started.
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <tr id="noUnifiedResultsRow" style="display: none;">
                                <td colspan="5" style="text-align: center; padding: 36px 20px; color: #64748b;">
                                    <i class="fa-solid fa-magnifying-glass" style="font-size: 22px; color: #cbd5e1; display: block; margin-bottom: 8px;"></i>
                                    No package or item matches your selected category or search filter.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div> <!-- .admin-body -->
    </main>

    <!-- ==========================================================================
       MODAL 1: ADD NEW ITEM
       ========================================================================== -->
    <div class="modal-overlay" id="addItemModal">
        <div class="modal-card-box">
            <form method="POST" action="a_packages.php?family=<?= urlencode($active_family) ?>">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="add_item">
                <input type="hidden" name="family" value="<?= htmlspecialchars($active_family) ?>">

                <div class="modal-card-header">
                    <h3><i class="fa-solid fa-circle-plus" style="color: var(--primary);"></i> Add Package / Item</h3>
                    <button type="button" onclick="closeModal('addItemModal')" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #94a3b8;">&times;</button>
                </div>

                <div class="modal-card-body">
                    <div class="modal-form-group">
                        <label>Category <span style="color: #dc2626;">*</span></label>
                        <select name="cat_key" id="addItemCatKey" required>
                            <?php foreach ($current_catalog as $cat_key => $cat_info): ?>
                                <option value="<?= htmlspecialchars($cat_key) ?>"><?= htmlspecialchars($cat_info['category_title'] ?? $cat_key) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="modal-form-group">
                        <label>Package / Item Name <span style="color: #dc2626;">*</span></label>
                        <input type="text" name="label" id="addItemLabel" placeholder="e.g. Deluxe Superhero Stage Backdrop" required autofocus>
                    </div>

                    <div class="modal-form-group">
                        <label>Price (PHP &#8369;) <span style="color: #dc2626;">*</span></label>
                        <input type="number" name="price" id="addItemPrice" value="0" min="0" step="100" required>
                        <small style="color: #64748b; font-size: 11px; margin-top: 4px; display: block;">Enter 0 if this item is free or included in the base package.</small>
                    </div>
                </div>

                <div class="modal-card-footer">
                    <button type="button" class="btn-modal-cancel" onclick="closeModal('addItemModal')">Cancel</button>
                    <button type="submit" class="btn-modal-save">Create Package Item</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==========================================================================
       MODAL 2: EDIT ITEM
       ========================================================================== -->
    <div class="modal-overlay" id="editItemModal">
        <div class="modal-card-box">
            <form method="POST" action="a_packages.php?family=<?= urlencode($active_family) ?>">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="update_item">
                <input type="hidden" name="family" value="<?= htmlspecialchars($active_family) ?>">
                <input type="hidden" name="cat_key" id="editOrigCatKey">
                <input type="hidden" name="item_id" id="editItemId">

                <div class="modal-card-header">
                    <h3><i class="fa-solid fa-pen-to-square" style="color: var(--primary);"></i> Edit Package / Item</h3>
                    <button type="button" onclick="closeModal('editItemModal')" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #94a3b8;">&times;</button>
                </div>

                <div class="modal-card-body">
                    <div class="modal-form-group">
                        <label>Category</label>
                        <select name="target_cat" id="editTargetCat">
                            <?php foreach ($current_catalog as $cat_key => $cat_info): ?>
                                <option value="<?= htmlspecialchars($cat_key) ?>"><?= htmlspecialchars($cat_info['category_title'] ?? $cat_key) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="modal-form-group">
                        <label>Package / Item Name <span style="color: #dc2626;">*</span></label>
                        <input type="text" name="label" id="editItemLabel" required>
                    </div>

                    <div class="modal-form-group">
                        <label>Price (PHP &#8369;) <span style="color: #dc2626;">*</span></label>
                        <input type="number" name="price" id="editItemPrice" min="0" step="100" required>
                    </div>
                </div>

                <div class="modal-card-footer">
                    <button type="button" class="btn-modal-cancel" onclick="closeModal('editItemModal')">Cancel</button>
                    <button type="submit" class="btn-modal-save">Update Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==========================================================================
       MODAL 3: DELETE ITEM CONFIRMATION
       ========================================================================== -->
    <div class="modal-overlay" id="deleteItemModal">
        <div class="modal-card-box">
            <form method="POST" action="a_packages.php?family=<?= urlencode($active_family) ?>">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="delete_item">
                <input type="hidden" name="family" value="<?= htmlspecialchars($active_family) ?>">
                <input type="hidden" name="cat_key" id="deleteCatKey">
                <input type="hidden" name="item_id" id="deleteItemId">
                <input type="hidden" name="item_name" id="deleteItemNameField">

                <div class="modal-card-header">
                    <h3 style="color: #dc2626;"><i class="fa-solid fa-triangle-exclamation"></i> Delete Package Item</h3>
                    <button type="button" onclick="closeModal('deleteItemModal')" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #94a3b8;">&times;</button>
                </div>

                <div class="modal-card-body">
                    <p style="font-size: 14px; color: #374151; margin: 0 0 12px 0;">
                        Are you sure you want to permanently delete:
                    </p>
                    <div style="background: #f1f5f9; padding: 12px 16px; border-radius: 8px; font-weight: 700; color: #0f172a; margin-bottom: 12px;" id="deleteItemNameDisplay">
                        -
                    </div>
                    <p style="font-size: 12px; color: #64748b; margin: 0;">
                        This item will no longer appear on client booking forms or landing page modals.
                    </p>
                </div>

                <div class="modal-card-footer">
                    <button type="button" class="btn-modal-cancel" onclick="closeModal('deleteItemModal')">Cancel</button>
                    <button type="submit" class="btn-modal-danger">Confirm Delete</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==========================================================================
       MODAL 4: ADD NEW CATEGORY
       ========================================================================== -->
    <div class="modal-overlay" id="addCatModal">
        <div class="modal-card-box">
            <form method="POST" action="a_packages.php?family=<?= urlencode($active_family) ?>">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="add_category">
                <input type="hidden" name="family" value="<?= htmlspecialchars($active_family) ?>">

                <div class="modal-card-header">
                    <h3><i class="fa-solid fa-folder-plus" style="color: var(--primary);"></i> Add New Category</h3>
                    <button type="button" onclick="closeModal('addCatModal')" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #94a3b8;">&times;</button>
                </div>

                <div class="modal-card-body">
                    <div class="modal-form-group">
                        <label>Category Title <span style="color: #dc2626;">*</span></label>
                        <input type="text" name="cat_title" placeholder="e.g. Inflatable Castles &amp; Play Area" required>
                    </div>

                    <div class="modal-form-group">
                        <label>Selection Behavior <span style="color: #dc2626;">*</span></label>
                        <select name="cat_type" required>
                            <option value="radio">Single Choice (Radio - Client picks one option)</option>
                            <option value="checkbox">Multiple Choice (Checkbox - Client can pick multiple add-ons)</option>
                        </select>
                    </div>

                    <div class="modal-form-group">
                        <label>Font Awesome Icon Class</label>
                        <select name="cat_icon">
                            <option value="fa-wand-magic-sparkles">Magic / Styling (fa-wand-magic-sparkles)</option>
                            <option value="fa-utensils">Food &amp; Catering (fa-utensils)</option>
                            <option value="fa-cart-flatbed">Food Carts (fa-cart-flatbed)</option>
                            <option value="fa-music">Music &amp; Sound (fa-music)</option>
                            <option value="fa-video">Photo / Video (fa-video)</option>
                            <option value="fa-camera-retro">Photobooth (fa-camera-retro)</option>
                            <option value="fa-masks-theater">Entertainment / Host (fa-masks-theater)</option>
                            <option value="fa-spa">Floral / Bouquets (fa-spa)</option>
                            <option value="fa-church">Church / Ceremony (fa-church)</option>
                            <option value="fa-house-chimney">Ceiling (fa-house-chimney)</option>
                            <option value="fa-tag">General Tag (fa-tag)</option>
                        </select>
                    </div>
                </div>

                <div class="modal-card-footer">
                    <button type="button" class="btn-modal-cancel" onclick="closeModal('addCatModal')">Cancel</button>
                    <button type="submit" class="btn-modal-save">Create Category</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==========================================================================
       MODAL 5: DELETE CATEGORY CONFIRMATION
       ========================================================================== -->
    <div class="modal-overlay" id="deleteCatModal">
        <div class="modal-card-box">
            <form method="POST" action="a_packages.php?family=<?= urlencode($active_family) ?>">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="delete_category">
                <input type="hidden" name="family" value="<?= htmlspecialchars($active_family) ?>">
                <input type="hidden" name="cat_key" id="deleteCatKeyInput">
                <input type="hidden" name="cat_name" id="deleteCatNameInput">

                <div class="modal-card-header">
                    <h3 style="color: #dc2626;"><i class="fa-solid fa-triangle-exclamation"></i> Delete Entire Category</h3>
                    <button type="button" onclick="closeModal('deleteCatModal')" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #94a3b8;">&times;</button>
                </div>

                <div class="modal-card-body">
                    <p style="font-size: 14px; color: #374151; margin: 0 0 12px 0;">
                        Are you sure you want to delete this category and <strong>all</strong> items inside it?
                    </p>
                    <div style="background: #fee2e2; border: 1px solid #fecaca; padding: 12px 16px; border-radius: 8px; font-weight: 700; color: #991b1b; margin-bottom: 12px;" id="deleteCatNameDisplay">
                        -
                    </div>
                    <p style="font-size: 12px; color: #64748b; margin: 0;">
                        This will immediately remove the whole category from the public booking form and landing page.
                    </p>
                </div>

                <div class="modal-card-footer">
                    <button type="button" class="btn-modal-cancel" onclick="closeModal('deleteCatModal')">Cancel</button>
                    <button type="submit" class="btn-modal-danger">Confirm Delete Category</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal management
        function openModal(id) {
            document.getElementById(id).classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        function openAddItemModal(defaultCatKey = '') {
            if (defaultCatKey) {
                document.getElementById('addItemCatKey').value = defaultCatKey;
            }
            document.getElementById('addItemLabel').value = '';
            document.getElementById('addItemPrice').value = '0';
            openModal('addItemModal');
            setTimeout(() => document.getElementById('addItemLabel').focus(), 100);
        }

        function openAddCatModal() {
            openModal('addCatModal');
        }

        function openEditItemModal(catKey, itemId, label, price) {
            document.getElementById('editOrigCatKey').value = catKey;
            document.getElementById('editItemId').value = itemId;
            document.getElementById('editTargetCat').value = catKey;
            document.getElementById('editItemLabel').value = label;
            document.getElementById('editItemPrice').value = price;
            openModal('editItemModal');
            setTimeout(() => document.getElementById('editItemLabel').focus(), 100);
        }

        function confirmDeleteItem(catKey, itemId, itemName) {
            document.getElementById('deleteCatKey').value = catKey;
            document.getElementById('deleteItemId').value = itemId;
            document.getElementById('deleteItemNameField').value = itemName;
            document.getElementById('deleteItemNameDisplay').textContent = itemName;
            openModal('deleteItemModal');
        }

        function confirmDeleteCategory(catKey, catName) {
            document.getElementById('deleteCatKeyInput').value = catKey;
            document.getElementById('deleteCatNameInput').value = catName;
            document.getElementById('deleteCatNameDisplay').textContent = catName;
            openModal('deleteCatModal');
        }

        // Live text filter
        let currentFilteredCat = 'all';

        function filterUnifiedTableByCategory(selectedCat) {
            currentFilteredCat = selectedCat;
            const selectEl = document.getElementById('unifiedCategorySelect');
            const selectedOpt = selectEl ? selectEl.options[selectEl.selectedIndex] : null;
            const catTitle = selectedOpt ? selectedOpt.getAttribute('data-title') : '';

            const infoBar = document.getElementById('categoryActiveInfoBar');
            const nameDisplay = document.getElementById('activeCategoryNameDisplay');
            if (infoBar && nameDisplay) {
                if (selectedCat !== 'all') {
                    infoBar.style.display = 'flex';
                    nameDisplay.textContent = catTitle || selectedCat;
                } else {
                    infoBar.style.display = 'none';
                }
            }

            applyUnifiedFilters();
        }

        function filterUnifiedTableBySearch(query) {
            applyUnifiedFilters();
        }

        function applyUnifiedFilters() {
            const selectEl = document.getElementById('unifiedCategorySelect');
            const selectedCat = selectEl ? selectEl.value : 'all';
            const searchInput = document.getElementById('unifiedSearchInput');
            const query = (searchInput ? searchInput.value : '').toLowerCase().trim();

            const rows = document.querySelectorAll('.unified-package-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const rowCat = row.getAttribute('data-cat') || '';
                const rowCatTitle = row.getAttribute('data-cat-title') || '';
                const rowLabel = row.getAttribute('data-label') || '';

                const matchesCat = (selectedCat === 'all' || rowCat === selectedCat);
                const matchesSearch = (!query || rowLabel.includes(query) || rowCatTitle.includes(query));

                if (matchesCat && matchesSearch) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            const noResultsRow = document.getElementById('noUnifiedResultsRow');
            if (noResultsRow) {
                noResultsRow.style.display = (visibleCount === 0 && rows.length > 0) ? '' : 'none';
            }
        }

        function openAddItemForActiveCategory() {
            if (currentFilteredCat && currentFilteredCat !== 'all') {
                openAddItemModal(currentFilteredCat);
            } else {
                openAddItemModal();
            }
        }

        function confirmDeleteActiveCategory() {
            if (currentFilteredCat && currentFilteredCat !== 'all') {
                const selectEl = document.getElementById('unifiedCategorySelect');
                const selectedOpt = selectEl ? selectEl.options[selectEl.selectedIndex] : null;
                const catTitle = selectedOpt ? (selectedOpt.getAttribute('data-title') || currentFilteredCat) : currentFilteredCat;
                confirmDeleteCategory(currentFilteredCat, catTitle);
            }
        }

        // Modal Handlers: Reset Defaults
        function openResetDefaultsModal() {
            const m = document.getElementById('resetDefaultsModal');
            if (m) m.classList.add('active');
        }
        function closeResetDefaultsModal() {
            const m = document.getElementById('resetDefaultsModal');
            if (m) m.classList.remove('active');
        }

        // Close on ESC
        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
                closeResetDefaultsModal();
            }
        });
        window.addEventListener('click', (e) => {
            const m = document.getElementById('resetDefaultsModal');
            if (m && e.target === m) closeResetDefaultsModal();
        });
    </script>

    <!-- Reset Defaults Confirmation Modal -->
    <div class="modal-backdrop" id="resetDefaultsModal" style="z-index: 9999;">
        <div class="modal-card" style="max-width: 480px; flex-direction: column; border-radius: 16px; overflow: hidden; box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.4);">
            <form method="POST" action="a_packages.php?family=<?= urlencode($active_family) ?>" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="reset_defaults">
                <input type="hidden" name="family" value="<?= htmlspecialchars($active_family) ?>">

                <div style="padding: 20px 24px; border-bottom: 1px solid #fee2e2; display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, #fff5f5 0%, #fef2f2 100%);">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 40px; height: 40px; border-radius: 12px; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 18px; box-shadow: 0 2px 8px rgba(220, 38, 38, 0.15);">
                            <i class="fa-solid fa-rotate-left"></i>
                        </div>
                        <div>
                            <h3 style="font-size: 17px; margin: 0; font-weight: 700; color: #991b1b;">Reset Category Defaults</h3>
                            <p style="font-size: 12px; color: #b91c1c; margin: 2px 0 0 0;">Restore factory preset packages</p>
                        </div>
                    </div>
                    <button type="button" onclick="closeResetDefaultsModal()" style="background: transparent; border: none; font-size: 20px; color: #9ca3af; cursor: pointer;" aria-label="Close">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div style="padding: 24px;">
                    <p style="font-size: 14px; color: #374151; margin: 0 0 16px 0; line-height: 1.5;">
                        Are you sure you want to reset <strong><?= ($active_family === 'wedding') ? 'Wedding Packages' : 'Kids Party Packages' ?></strong> back to factory defaults?
                    </p>

                    <div style="background: #fff1f2; border-left: 4px solid #f43f5e; padding: 12px 14px; border-radius: 6px; font-size: 12px; color: #9f1239; display: flex; align-items: flex-start; gap: 8px; line-height: 1.4;">
                        <i class="fa-solid fa-triangle-exclamation" style="font-size: 14px; margin-top: 2px; flex-shrink: 0;"></i>
                        <span><strong>Warning:</strong> All custom items, pricing changes, and descriptions saved in this category will be permanently overwritten with default templates.</span>
                    </div>
                </div>

                <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 10px; background: #fafafa;">
                    <button type="button" class="btn-secondary" onclick="closeResetDefaultsModal()" style="padding: 10px 20px; font-size: 13px; font-weight: 600; border-radius: 8px;">Cancel</button>
                    <button type="submit" style="padding: 10px 22px; font-size: 13px; font-weight: 700; background: #dc2626; color: #ffffff; border: none; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);">
                        <i class="fa-solid fa-rotate-left"></i> Yes, Reset to Defaults
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
