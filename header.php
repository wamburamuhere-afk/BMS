<?php
// session_start(); // Handled by roots.php
require_once __DIR__ . '/includes/config.php';
// AI Assistant helpers (aiConfigured) — so the Comms menu can show "Ask BMS"
// only when AI is enabled. Cheap; reads a few settings. Never fatals.
if (is_file(__DIR__ . '/core/ai_service.php')) require_once __DIR__ . '/core/ai_service.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT username, first_name, last_name FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
$username = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if (empty($username)) $username = $user['username'];

// Get user role information — include is_admin flag (column may not exist on older DBs)
try {
    $role_stmt = $pdo->prepare("
        SELECT u.role_id, r.role_name, COALESCE(r.is_admin, 0) AS is_admin
        FROM users u
        JOIN roles r ON u.role_id = r.role_id
        WHERE u.user_id = ?
    ");
    $role_stmt->execute([$_SESSION['user_id']]);
    $role_data = $role_stmt->fetch();
} catch (PDOException $e) {
    // Fallback: is_admin column not yet added by migration — derive from role_id
    $role_stmt = $pdo->prepare("
        SELECT u.role_id, r.role_name
        FROM users u
        JOIN roles r ON u.role_id = r.role_id
        WHERE u.user_id = ?
    ");
    $role_stmt->execute([$_SESSION['user_id']]);
    $role_data = $role_stmt->fetch();
    if ($role_data) {
        $role_data['is_admin'] = ($role_data['role_id'] == 1) ? 1 : 0;
    }
}

$user_role = $role_data['role_name'] ?? 'user';
$role_id   = $role_data['role_id']   ?? 0;

// Update session with latest role info + admin flag
$_SESSION['role_id']   = $role_id;
$_SESSION['user_role'] = $user_role;
$_SESSION['role']      = $user_role;
$_SESSION['is_admin']  = (bool)($role_data['is_admin'] ?? false);

// Load permissions if not in session or if we want to ensure they are fresh
// Note: In production, you might only do this if !isset($_SESSION['permissions'])
// But for now, let's ensure they are loaded to fix the user's issue.
if (function_exists('loadUserPermissions')) {
    loadUserPermissions($role_id);
}

// Project-scope (Phase A foundation): compute the user's accessible
// project / warehouse / supplier / customer / employee sets once per
// request. Admin gets the unrestricted sentinel; non-admins get the
// derived sets from user_projects + user_scope_overrides.
// Phase A only — no SELECT in the app uses scopeFilterSql() yet.
if (function_exists('loadUserScope') && !isset($_SESSION['scope'])) {
    loadUserScope((int)$_SESSION['user_id']);
}

// Document expiry check — runs at most once per day (see cron/check_document_expiry.php).
// The engine is self-contained and fails silently so it can never break a page load.
if (function_exists('get_setting') && get_setting('doc_expiry_last_run') !== date('Y-m-d')) {
    @include_once __DIR__ . '/cron/check_document_expiry.php';
}

// Recurring documents — generate any due recurring expenses, at most once per day.
// Self-contained + fail-silent (see cron/run_recurring.php); never blocks a page load.
if (function_exists('get_setting') && get_setting('recurring_last_run') !== date('Y-m-d')) {
    @include_once __DIR__ . '/cron/run_recurring.php';
}

// Leave accrual — seed this year's leave balances (entitlement + carry-over), once
// per day. Self-contained + fail-silent (see cron/run_leave_accrual.php).
if (function_exists('get_setting') && get_setting('leave_accrual_last_run') !== date('Y-m-d')) {
    @include_once __DIR__ . '/cron/run_leave_accrual.php';
}

// Smart notification engine — time-based event checks (overdue/expiring/due),
// at most once per day. Self-contained + fail-silent (see cron/run_notification_checks.php).
if (function_exists('get_setting') && get_setting('notif_checks_last_run') !== date('Y-m-d')) {
    @include_once __DIR__ . '/cron/run_notification_checks.php';
}

// Notification email worker — drain a small batch from the outbox, throttled to
// ~2 minutes so it never noticeably slows a page load. (Use a server cron for volume.)
if (function_exists('get_setting') && (time() - (int)get_setting('notif_outbox_last_ts', '0')) >= 120) {
    if (function_exists('save_setting')) save_setting('notif_outbox_last_ts', (string)time());
    @include_once __DIR__ . '/cron/process_notifications.php';
}

// AI daily digest — one summary email per user with pending items, once per day.
// Opt-in (notif_digest_enabled). Self-contained + fail-silent.
if (function_exists('get_setting') && get_setting('notif_digest_enabled', '0') === '1'
    && get_setting('notif_digest_last_run') !== date('Y-m-d')) {
    @include_once __DIR__ . '/cron/send_notification_digests.php';
}

// Get company type + location from settings
$settings_stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'company_type'");
$settings_stmt->execute();
$company_type = $settings_stmt->fetchColumn() ?: 'general';

$location_stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'company_physical_address'");
$location_stmt->execute();
$company_location = $location_stmt->fetchColumn() ?: '';

// Ensure these are available globally for function-scoped headers (e.g. includeHeader())
global $company_name, $company_logo, $pdo;

// Company Name
$company_name_stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'company_name'");
$company_name_stmt->execute();
$company_name = $company_name_stmt->fetchColumn() ?: 'BUSINESS MANAGEMENT SYSTEM';
$GLOBALS['DISPLAY_COMPANY_NAME'] = $company_name;

// Company Logo
$company_logo = get_setting('company_logo');

// Page-visit logging — record every authenticated page load with a human-readable name.
if (function_exists('logActivity') && !empty($_SESSION['user_id'])) {
    try {
        $req_path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $base      = function_exists('getBasePath') ? trim(getBasePath(), '/') : '';
        $clean     = trim($base !== '' ? ltrim(substr($req_path, strlen('/' . $base)), '/') : ltrim($req_path, '/'), '/');
        $parts     = array_values(array_filter(explode('/', $clean)));
        $last      = array_pop($parts) ?? 'page';
        $parent    = $parts ? array_pop($parts) : '';
        // If last segment is a generic action word, prepend the parent segment for context.
        if (in_array(strtolower($last), ['view', 'details', 'edit', 'add', 'create', 'list', 'index', ''], true) && $parent !== '') {
            $page_seg = $parent . ' ' . $last;
        } else {
            $page_seg = $last;
        }
        $page_name = ucwords(str_replace(['_', '-'], ' ', trim($page_seg)));
        if ($page_name === '') $page_name = 'Page';
        logActivity($pdo, (int)$_SESSION['user_id'],
            'View ' . $page_name,
            'User viewed ' . $page_name . ' page');
    } catch (Throwable $e) { /* never break page load */ }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title : 'Business Management System'; ?></title>
    
    <!-- jQuery first -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  

<!-- SweetAlert2 (REQUIRED for Swal.fire) -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    // Global App Configuration
    const APP_URL = '<?= getUrl("") ?>'.replace(/\/$/, '');
    const BMS_COMPANY_NAME = '<?= htmlspecialchars($company_name ?? '', ENT_QUOTES) ?>';
    const BMS_COMPANY_LOGO = '<?= htmlspecialchars($company_logo ? getUrl($company_logo) : '', ENT_QUOTES) ?>';
    const CSRF_TOKEN = '<?= csrf_token() ?>';
    $.ajaxSetup({ headers: { 'X-CSRF-Token': CSRF_TOKEN } });

    // Set global SweetAlert2 defaults - Green OK button everywhere
    const originalSwalFire = Swal.fire.bind(Swal);
    Swal.fire = function(...args) {
        // Only modify object-style calls to preserve .then() chaining
        if (args.length === 1 && typeof args[0] === 'object') {
            const options = { ...args[0] };
            // Always set green confirm button
            if (!options.confirmButtonColor) options.confirmButtonColor = '#28a745';
            if (!options.confirmButtonText) options.confirmButtonText = 'OK';
            // Ensure success alerts always show the OK button
            if (options.icon === 'success') {
                if (options.showConfirmButton === undefined) options.showConfirmButton = true;
            }
            return originalSwalFire(options);
        }
        // For positional calls: Swal.fire('title', 'text', 'icon')
        // Convert to object to apply green defaults
        if (typeof args[0] === 'string') {
            const options = { title: args[0] };
            if (args[1]) options.text = args[1];
            if (args[2]) options.icon = args[2];
            options.confirmButtonColor = '#28a745';
            options.confirmButtonText = 'OK';
            if (options.icon === 'success') options.showConfirmButton = true;
            return originalSwalFire(options);
        }
        return originalSwalFire(...args);
    };
    // Global Activity Logging Helper
    function logActivityAction(action, activity_type, description, entity_type = null, entity_id = null) {
        $.post(APP_URL + '/api/log_audit', { // keeping API name to avoid breakage elsewhere if any
            action: action,
            activity_type: activity_type,
            description: description,
            entity_type: entity_type,
            entity_id: entity_id
        });
    }

    // Global helper for logging activities moved to header.php
    function logReportAction(action, description) {
        if (navigator.sendBeacon) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('description', description);
            navigator.sendBeacon(APP_URL + '/api/log_audit', formData);
        } else {
            $.post(APP_URL + '/api/log_audit', {
                action: action,
                description: description
            });
        }
    }
</script>


    <!-- Font Awesome 5 CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <style>
        /* Global Select2-in-modal behaviour: float above the modal and let the
           results list scroll on its own (fixes the "hard to scroll" feel). The
           "closes on click" part is fixed by disabling the modal focus-trap in footer.php. */
        .select2-container--open { z-index: 1060 !important; }
        .select2-dropdown { z-index: 1060 !important; }
        .select2-results__options {
            max-height: 240px;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior: contain;
        }
    </style>
    
    <link rel="stylesheet" href="<?= getUrl('style.css') ?>">
    <link rel="stylesheet" href="<?= getUrl('assets/css/responsive.css') ?>">

    <style>
        /* ── Two-bar fixed header ── */
        .header-wrapper {
            position: fixed;
            top: 0; left: 0; right: 0;
            width: 100%;
            z-index: 1030;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }

        /* Top branding bar */
        .top-header {
            background: #0b5ed7;
            padding: 4px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        /* Bottom nav bar */
        .bottom-header {
            background: #0d6efd;
            padding: 0;
        }
        .bottom-header .nav-link {
            padding-top: 4px !important;
            padding-bottom: 4px !important;
            font-size: 0.88rem;
        }

        body {
            padding-top: 90px; /* fallback before JS runs */
        }

        .navbar {
            padding: 0 !important;
            box-shadow: none !important;
            position: static !important;
        }

        .header-top-bar { display: none; } /* legacy class — replaced by .top-header */

        .header-nav-bar {
            padding: 0.2rem 0;
        }

        .user-info-dropdown .dropdown-toggle::after {
            display: none;
        }
        
        .user-info-dropdown .dropdown-toggle {
            cursor: pointer;
            transition: all 0.2s ease;
            border-radius: 8px;
        }
        
        .user-info-dropdown .dropdown-toggle:hover {
            background-color: rgba(255,255,255,0.1);
        }

        .user-info-text {
            font-size: 0.85rem;
            color: rgba(255,255,255,0.9);
            line-height: 1.2;
        }
        
        /* Adjust main content for fixed header */
        .container.mt-4, .container-fluid.mt-4 {
            padding-top: 0px !important;
        }

        @media (max-width: 992px) {
            body {
                padding-top: 100px; /* fallback before JS runs */
            }
        }
        
        /* Smooth scrolling for anchor links */
        html {
            scroll-padding-top: 115px;
        }

        /* Compact dropdowns */
        .dropdown-menu {
            border: none;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            max-height: 80vh;
            overflow-y: auto;
            font-size: 0.9rem;
        }
        
        .dropdown-item {
            padding: 0.4rem 1rem;
        }
        
        .dropdown-header {
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0.3rem 1rem;
        }
        
        /* Mega menu for reports */
        .mega-dropdown {
            position: static !important;
        }
        
        .mega-dropdown-menu {
            width: 100%;
            max-width: 1200px;
            left: 50% !important;
            transform: translateX(-50%) !important;
            padding: 1.5rem;
        }
        
        .mega-column {
            padding: 0 1rem;
        }
        
        .mega-column h6 {
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 0.8rem;
            color: #495057;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 0.5rem;
        }
        
        /* Compact navigation for many items */
        .navbar-nav {
            flex-wrap: wrap;
        }
        
        .nav-item {
            margin-right: 0.2rem;
        }
        
        .nav-link {
            padding: 0.5rem 0.8rem;
            font-size: 0.9rem;
        }
        
        /* Company type badge */
        .company-badge {
            font-size: 0.7rem;
            padding: 0.15rem 0.4rem;
            margin-left: 0.3rem;
        }
        
        /* Responsive adjustments */
        @media (max-width: 992px) {
            .header-top-bar .container-fluid {
                flex-direction: row !important;
                flex-wrap: nowrap;
                align-items: center !important;
            }
            .header-top-bar .navbar-toggler {
                position: static;
                margin-left: 0.5rem;
            }
            .user-info-text {
                display: none !important;
            }
            .navbar-brand .company-name-wrapper span {
                max-width: none !important;
                overflow: visible !important;
                text-overflow: clip !important;
                white-space: nowrap;
                font-size: 1rem !important;
            }
            .header-nav-bar {
                background-color: rgba(0,0,0,0.05);
            }
            /* Force dropdown menu to be right-aligned on mobile to prevent overflow */
            .user-info-dropdown .dropdown-menu {
                left: auto !important;
                right: 0 !important;
                transform: none !important;
                margin-top: 0.5rem !important;
            }
        }

        /* Hover effects for Nav Links */
        .nav-link {
            transition: all 0.2s ease;
            position: relative;
            font-weight: 500;
        }
        
        .nav-link:hover {
            color: #fff !important;
            transform: translateY(-1px);
        }

        .nav-item.dropdown:hover > .nav-link {
            color: #fff !important;
        }

        /* Active line under nav items on hover */
        @media (min-width: 992px) {
            .nav-link::after {
                content: '';
                position: absolute;
                width: 0;
                height: 2px;
                bottom: 5px;
                left: 50%;
                background-color: #fff;
                transition: all 0.3s ease;
                transform: translateX(-50%);
                opacity: 0;
            }
            .nav-link:hover::after {
                width: 70%;
                opacity: 1;
            }
        }

        /* Dropdown refinement */
        .dropdown-menu {
            border-top: 3px solid #0d6efd;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Company Name Header Handling */
        .company-name-wrapper {
            display: inline-block;
            vertical-align: middle;
        }

        /* Mobile scrolling marquee for company name */
        @media (max-width: 576px) {
            .top-header { padding: 4px 0; }
            .main-logo { height: 26px !important; margin-right: 4px !important; }

            .marquee-container {
                flex-grow: 1;
                overflow: hidden;
                white-space: nowrap;
                margin: 0 6px;
            }
            .marquee-text {
                display: inline-block;
                padding-left: 100%;
                animation: marquee 15s linear infinite;
                font-size: 0.95rem;
                font-weight: 700;
                color: white;
            }
            @keyframes marquee {
                0%   { transform: translateX(0); }
                100% { transform: translateX(-100%); }
            }

            .date-location-box {
                font-size: 0.52rem !important;
                flex-shrink: 0;
            }
            .date-location-box .date-text,
            .date-location-box .location-text {
                font-size: 0.52rem !important;
            }

            body { padding-top: 72px; } /* fallback before JS runs */
        }

        @media (max-width: 768px) {
            .company-name-wrapper {
                overflow: hidden;
                white-space: nowrap;
                max-width: 140px;
            }
        }

        /* ── Mobile navigation accessibility (CSS only — no markup/logic change) ──
           On phones the collapsed menu is long and the Reports mega-menu has
           4 side-by-side columns that overflowed off-screen, hiding options.
           Make the whole collapsed menu scrollable, stack the mega-menu, and
           let inner dropdowns expand inline so every option is reachable. */
        @media (max-width: 991.98px) {
            /* The whole collapsed menu scrolls within the viewport, so the
               lower sections (Reports, Admin) are always reachable. */
            .navbar-collapse {
                max-height: calc(100vh - 110px);
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
            }
            /* Inner dropdowns expand inline (no per-menu scroll fighting the
               collapse) so the menu scrolls as one continuous list. */
            .navbar-collapse .dropdown-menu {
                max-height: none !important;
                overflow: visible !important;
                box-shadow: none !important;
                border-top: 0 !important;
            }
            /* Reports mega-menu: full width, no off-screen transform, columns
               stacked vertically so every report link is visible. */
            .mega-dropdown-menu {
                width: 100% !important;
                max-width: 100% !important;
                left: 0 !important;
                right: 0 !important;
                transform: none !important;
                padding: 0.5rem 1rem !important;
            }
            .mega-dropdown-menu .row {
                display: block !important;
            }
            .mega-dropdown-menu .mega-column {
                width: 100% !important;
                padding: 0 !important;
                margin-bottom: 0.5rem;
            }
        }

        /* Print styles */
        @media print {
            .header-wrapper, .navbar {
                display: none !important;
            }
            body { padding-top: 0 !important; }
        }

        .main-logo {
            height: 32px;
            width: auto;
            object-fit: contain;
            border-radius: 4px;
            background: white;
            padding: 2px;
        }
    </style>

    <!-- Dark mode -->
    <?php if (($_SESSION['theme'] ?? 'light') === 'dark'): ?>
    <style>
        body { background-color: #1a1d21 !important; color: #e1e7ec !important; }
        .card, .modal-content, .dropdown-menu { background-color: #24282d !important; color: #e1e7ec !important; border-color: #3a3f45 !important; }
        .text-dark, h1, h2, h3, h4, h5, h6, .dropdown-item { color: #ffffff !important; }
        .dropdown-item:hover { background-color: #3a3f45 !important; }
        .form-control, .form-select, .bg-light { background-color: #2d3238 !important; border-color: #444b52 !important; color: #ffffff !important; }
        .form-control:focus, .form-select:focus { background-color: #333940 !important; color: #ffffff !important; }
        .border-bottom, .border-top, .border, hr { border-color: #3a3f45 !important; }
        .text-muted, .form-text { color: #a1aab2 !important; }
        .nav-link { color: #d1d8de !important; }
        .nav-link:hover { color: #ffffff !important; }
        .nav-pills .nav-link.active { background-color: #0d6efd !important; }
        .alert-light { background-color: #2d3238 !important; color: #e1e7ec !important; border: 1px solid #3a3f45 !important; }
        .table { color: #e1e7ec !important; }
        .table-striped > tbody > tr:nth-of-type(odd) { background-color: rgba(255,255,255,0.03) !important; }
    </style>
    <?php endif; ?>

<script src="<?= getUrl('assets/js/bms-mobile-cards.js') ?>"></script>
</head>
<body>
    <div class="header-wrapper">

        <!-- TOP BRANDING BAR -->
        <div class="top-header">
            <div class="container-fluid px-4 d-flex align-items-center">

                <!-- Logo + company name (desktop) -->
                <a class="d-flex align-items-center text-white text-decoration-none me-3" href="<?= getUrl('dashboard') ?>">
                    <?php $logo = get_setting('company_logo'); if ($logo): ?>
                        <img src="<?= getUrl($logo) ?>" alt="Logo" class="main-logo me-2">
                    <?php else: ?>
                        <i class="bi bi-currency-exchange me-2 fs-5"></i>
                    <?php endif; ?>
                    <h5 class="fw-bold mb-0 text-white d-none d-md-block" style="letter-spacing:-0.5px;font-size:1.1rem;line-height:1.2;">
                        <?= htmlspecialchars(get_setting('company_name', 'BMS')) ?>
                        <span class="badge bg-light text-dark ms-1" style="font-size:0.55rem;vertical-align:middle;">
                            <?= strtoupper(substr($company_type, 0, 3)) ?>
                        </span>
                    </h5>
                </a>

                <!-- Mobile scrolling marquee -->
                <div class="marquee-container d-md-none">
                    <div class="marquee-text"><?= htmlspecialchars(get_setting('company_name', 'BMS')) ?></div>
                </div>

                <!-- Date + location (right side) -->
                <div class="ms-auto d-flex align-items-center gap-2 gap-md-3 text-white date-location-box">
                    <div class="small fw-bold date-text" style="font-size:0.85rem;">
                        <i class="bi bi-calendar3 me-1 opacity-75"></i>
                        <span class="d-none d-md-inline"><?= date('l, d M Y') ?></span>
                        <span class="d-inline d-md-none"><?= date('D, d M Y') ?></span>
                    </div>
                    <span class="opacity-25 d-none d-md-inline">|</span>
                    <div class="text-white-50 small location-text" style="font-size:0.85rem;">
                        <i class="bi bi-geo-alt-fill text-warning me-1"></i>
                        <?= htmlspecialchars($company_location ?: 'Tanzania') ?>
                    </div>
                </div>

            </div>
        </div>

        <!-- BOTTOM NAVIGATION BAR -->
        <nav class="navbar navbar-expand-lg navbar-dark bottom-header">
            <div class="container-fluid px-4">
                <button class="navbar-toggler ms-auto" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <!-- Bottom Row: Navigation Modules -->
                <div class="header-nav-bar w-100">
                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav me-auto">
                        <!-- Core Modules -->
                        <?php if(canView('dashboard') || canView('customers') || canView('suppliers') || canView('products')): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="coreDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-house"></i> Core
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="coreDropdown">
                                <li><h6 class="dropdown-header">Business Core</h6></li>
                                <?php if(canView('dashboard')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('dashboard') ?>"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                                <?php endif; ?>
                                <?php if(canView('customers')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('customers') ?>"><i class="bi bi-people"></i> Customers</a></li>
                                <?php endif; ?>
                                <?php if(canView('suppliers')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('suppliers') ?>"><i class="bi bi-truck"></i> Suppliers</a></li>
                                <?php endif; ?>
                                <?php if(canView('suppliers')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('sub_contractors') ?>"><i class="bi bi-person-workspace text-info"></i> Sub-Contractors</a></li>
                                <?php endif; ?>
                                <?php if(canView('products')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('products') ?>"><i class="bi bi-box text-success"></i> Inventory Products</a></li>
                                <?php endif; ?>
                                <?php if(canView('products')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('services') ?>"><i class="bi bi-box-seam text-primary"></i> Non-Inventory Products</a></li>
                                <?php endif; ?>
                            </ul>
                        </li>
                        <?php endif; ?>
                        
                        <!-- Financial Modules -->
                        <?php if(canView('expenses') || canView('budget') || canView('chart_of_accounts') || canView('bank_accounts') || canView('cash_register')): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="financeDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-cash-stack"></i> Finance
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="financeDropdown">
                                <li><h6 class="dropdown-header">Accounting</h6></li>
                                <?php if(canView('expenses')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('expenses') ?>"><i class="bi bi-currency-dollar"></i> Expenses</a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('recurring') ?>"><i class="bi bi-arrow-repeat"></i> Recurring Documents</a></li>
                                <?php endif; ?>
                                <?php if(canView('revenue')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('revenue') ?>"><i class="bi bi-cash-coin"></i> Revenue / Other Income</a></li>
                                <?php endif; ?>
                                <?php if(canView('budget')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('budget') ?>"><i class="bi bi-pie-chart"></i> Budget</a></li>
                                <?php endif; ?>
                                <?php if(canView('chart_of_accounts')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('chart_of_accounts') ?>"><i class="bi bi-diagram-3"></i> Chart of Accounts</a></li>
                                <?php endif; ?>
                                
                                <li><h6 class="dropdown-header">Banking & Cash</h6></li>
                                <?php if(canView('bank_accounts')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('bank_accounts') ?>"><i class="bi bi-bank"></i> Bank Accounts</a></li>
                                <?php endif; ?>
                                <?php if(canView('cash_register')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('cash_register') ?>"><i class="bi bi-cash"></i> Cash Register</a></li>
                                <?php endif; ?>
                                <?php if(canView('petty_cash')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('petty_cash') ?>"><i class="bi bi-wallet"></i> Petty Cash</a></li>
                                <?php endif; ?>
                                <?php if(canView('bank_transfers')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('bank_transfers') ?>"><i class="bi bi-arrow-left-right"></i> Bank Transfers</a></li>
                                <?php endif; ?>
                                <?php if(canView('bank_reconciliation')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('bank_reconciliation') ?>"><i class="bi bi-check-circle"></i> Reconciliation</a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('bank_statement') ?>"><i class="bi bi-card-list"></i> Bank Statement</a></li>
                                <?php endif; ?>
                                <?php if(canView('journals')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('journals') ?>"><i class="bi bi-journal-text"></i> Journals</a></li>
                                <?php endif; ?>
                                
                                <li><h6 class="dropdown-header">Sales & Purchases</h6></li>
                                <?php if(canView('invoices')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('invoices') ?>"><i class="bi bi-receipt"></i> Invoices</a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('receive_payment') ?>"><i class="bi bi-cash-stack"></i> Receive Payment</a></li>
                                <?php endif; ?>
                                <?php if(canView('purchase_orders')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('purchase_orders') ?>"><i class="bi bi-file-text"></i> Purchase Orders</a></li>
                                <?php endif; ?>
                                <?php if(canView('payment_vouchers')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('payment_vouchers') ?>"><i class="bi bi-credit-card"></i> Payment Vouchers</a></li>
                                <?php endif; ?>
                            </ul>
                        </li>
                        <?php endif; ?>

                        <!-- CRM -->
                        <?php if(canView('crm_dashboard') || canView('crm_leads') || canView('crm_pipeline')): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="crmDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-funnel"></i> CRM
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="crmDropdown">
                                <li><h6 class="dropdown-header">Customer Relations</h6></li>
                                <?php if(canView('crm_dashboard')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('crm/dashboard') ?>"><i class="bi bi-speedometer2 me-1"></i>CRM Dashboard</a></li>
                                <?php endif; ?>
                                <?php if(canView('crm_leads')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('crm/leads') ?>"><i class="bi bi-person-plus me-1"></i>Leads</a></li>
                                <?php endif; ?>
                                <?php if(canView('crm_pipeline')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('crm/pipeline') ?>"><i class="bi bi-kanban me-1"></i>Pipeline Board</a></li>
                                <?php endif; ?>
                                <?php if(canView('crm_pipeline')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('crm/pipeline_stages') ?>"><i class="bi bi-gear me-1"></i>Pipeline Stages</a></li>
                                <?php endif; ?>
                            </ul>
                        </li>
                        <?php endif; ?>

                        <!-- Sales -->
                        <?php if(canView('sales_orders') || canView('invoices') || canView('pos')): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="salesDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-cart"></i> Sales
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="salesDropdown">
                                <li><h6 class="dropdown-header">Sales Operations</h6></li>
                                <?php if(canView('sales_orders')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('sales_orders') ?>"><i class="bi bi-bag"></i>Sales Orders</a></li>
                                <?php endif; ?>
                                <?php if(canView('invoices')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('invoices') ?>"><i class="bi bi-receipt"></i> Invoices</a></li>
                                <?php endif; ?>
                                <?php if(canView('pos')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('pos') ?>"><i class="bi bi-cart-check"></i> POS</a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('pos/dashboard') ?>"><i class="bi bi-speedometer2"></i> POS Dashboard &amp; Sales</a></li>
                                <?php endif; ?>
                                <?php if(canView('quotations')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('quotations') ?>"><i class="bi bi-file-text"></i> Quotations</a></li>
                                <?php endif; ?>
                                <li><h6 class="dropdown-header">Returns</h6></li>
                                <?php if(canView('sales_returns')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('sales_returns') ?>"><i class="bi bi-arrow-return-left"></i> Sales Returns</a></li>
                                <?php endif; ?>
                                <?php if(canView('credit_notes')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('credit_notes') ?>"><i class="bi bi-receipt"></i> Credit Notes</a></li>
                                <?php endif; ?>
                            </ul>
                        </li>
                        <?php endif; ?>
                        
                        <!-- Inventory -->
                        <?php if(canView('products') || canView('warehouses')): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="inventoryDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-boxes"></i> Inventory
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="inventoryDropdown">
                                <li><h6 class="dropdown-header">Stock Management</h6></li>
                                <?php if(canView('products')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('products') ?>"><i class="bi bi-box text-success"></i> Inventory Products</a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('services') ?>"><i class="bi bi-box-seam text-primary"></i> Non-Inventory Products</a></li>
                                <?php endif; ?>
                                <?php if(canView('categories')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('categories') ?>"><i class="bi bi-tags"></i> Categories</a></li>
                                <?php endif; ?>
                                <?php if(canView('stock_adjustments')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('stock_adjustments') ?>"><i class="bi bi-arrow-left-right"></i> Adjustments</a></li>
                                <?php endif; ?>
                                <?php if(canView('inventory_valuation')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('inventory_valuation') ?>"><i class="bi bi-calculator"></i> Valuation</a></li>
                                <?php endif; ?>
                                <li><h6 class="dropdown-header">Warehouse</h6></li>
                                <?php if(canView('warehouses')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('warehouses') ?>"><i class="bi bi-house-door"></i> Warehouses</a></li>
                                <?php endif; ?>
                                <?php if(canView('locations')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('locations') ?>"><i class="bi bi-geo-alt"></i> Locations</a></li>
                                <?php endif; ?>
                            </ul>
                        </li>
                        <?php endif; ?>
                        
                        <!-- Purchases -->
                        <?php if (canView('suppliers')|| canView('rfq') || canView('purchase_orders') || canView('tenders')): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="purchasesDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-basket"></i> Procurement
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="purchasesDropdown">
                                <li><h6 class="dropdown-header">Procurement</h6></li>
                                
                                <?php if(canView('suppliers')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('suppliers') ?>"><i class="bi bi-truck"></i> Suppliers</a></li>
                                <?php endif; ?>
                                <?php if(canView('rfq')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('rfq') ?>"><i class="bi bi-file-earmark-text"></i> RFQ</a></li>
                                <?php endif; ?>
                                
                                <?php if(canView('purchase_orders')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('purchase_orders') ?>"><i class="bi bi-file-text"></i>Purchase Order</a></li>
                                <?php endif; ?>
                                 <?php if(canView('grn')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('delivery_notes') ?>"><i class="bi bi-file-earmark-check"></i> DN</a></li>
                                <?php endif; ?>
                                <?php if(canView('grn')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('grn') ?>"><i class="bi bi-check-square"></i> GRN</a></li>
                                <?php endif; ?>
                                <?php if(canView('received_invoices')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('received_invoices') ?>"><i class="bi bi-inbox"></i> Bills</a></li>
                                <?php endif; ?>
                                <?php if(canView('purchase_returns')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('purchase_returns') ?>"><i class="bi bi-arrow-return-right"></i> Return Note</a></li>
                                <?php endif; ?>
                                <?php if(canView('debit_notes')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('debit_notes') ?>"><i class="bi bi-receipt-cutoff"></i> Debit Notes</a></li>
                                <?php endif; ?>
                                <?php if(canView('nip_materials')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('nip_materials') ?>"><i class="bi bi-boxes"></i> Materials</a></li>
                                <?php endif; ?>
                                <?php if(canView('tenders')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('tenders') ?>"><i class="bi bi-clipboard-check"></i> Tenders</a></li>
                                <?php endif; ?>
                            </ul>
                        </li>
                        <?php endif; ?>
                        
                        <!-- Operations -->
                        <?php if(canView('employees') || canView('assets')): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="operationsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-gear"></i> Operations
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="operationsDropdown">
                                <li><h6 class="dropdown-header">Human Resources</h6></li>
                                <?php if(canView('employees')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('employees') ?>"><i class="bi bi-person-badge"></i> Employees</a></li>
                                <?php endif; ?>
                                <?php if(canView('payroll')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('payroll') ?>"><i class="bi bi-cash"></i> Payroll</a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('salary_components') ?>"><i class="bi bi-sliders"></i> Salary Components</a></li>
                                <?php endif; ?>
                                <?php if(canView('attendance')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('attendance') ?>"><i class="bi bi-clock"></i> Attendance</a></li>
                                <?php endif; ?>
                                <?php if(canView('leaves')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('leaves') ?>"><i class="bi bi-calendar"></i> Leaves</a></li>
                                <?php endif; ?>
                                <li><h6 class="dropdown-header">Assets & Maintenance</h6></li>
                                <?php if(canView('assets')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('assets') ?>"><i class="bi bi-pc-display"></i> Assets</a></li>
                                <?php endif; ?>
                                <?php if(canView('maintenance')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('maintenance') ?>"><i class="bi bi-tools"></i> Maintenance</a></li>
                                <?php endif; ?>
                            </ul>
                        </li>
                        <?php endif; ?>
                        
                        <!-- Projects -->
                        <?php if (get_setting('enable_projects') == '1' && canView('projects')): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= getUrl('projects') ?>">
                                <i class="bi bi-kanban"></i> Projects
                            </a>
                        </li>
                        <?php endif; ?>
    
                        <!-- Comms -->
                        <?php if(canView('message_center') || canView('notification_center') || canView('ai_assistant')): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="communicationDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-chat"></i> Comms
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="communicationDropdown">
                                <li><h6 class="dropdown-header">Communication</h6></li>
                                <?php if(canView('ai_assistant') && function_exists('aiConfigured') && aiConfigured()): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('ai_assistant') ?>"><i class="bi bi-stars text-primary"></i> Ask BMS <span class="badge bg-primary-subtle text-primary ms-1" style="font-size:.6rem;">AI</span></a></li>
                                <?php endif; ?>
                                <?php if(canView('message_center')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('message_center') ?>"><i class="bi bi-chat-left"></i> Messages</a></li>
                                <?php endif; ?>
                                <?php if(canView('email_templates')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('email_templates') ?>"><i class="bi bi-envelope"></i> Email</a></li>
                                <?php endif; ?>
                                <?php if(canView('sms_templates')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('sms_templates') ?>"><i class="bi bi-chat-text"></i> SMS</a></li>
                                <?php endif; ?>
                                <?php if(canView('notification_center')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('notification_center') ?>"><i class="bi bi-bell"></i> Notifications</a></li>
                                <?php endif; ?>
                                <li><h6 class="dropdown-header">Marketing</h6></li>
                                <?php if(canView('campaigns')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('campaigns') ?>"><i class="bi bi-megaphone"></i> Campaigns</a></li>
                                <?php endif; ?>
                                <?php if(canView('leads')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('leads') ?>"><i class="bi bi-person-plus"></i> Leads</a></li>
                                <?php endif; ?>
                            </ul>
                        </li>
                        <?php endif; ?>
                        
                        <!-- Docs -->
                        <?php if(canView('document_library') || canView('document_templates') || canView('e_signatures') || canView('compliance_documents') || canView('audit_logs')): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="documentsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-files"></i> Docs
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="documentsDropdown">
                                <li><h6 class="dropdown-header">Document Management</h6></li>
                                <?php if(canView('document_library')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('library') ?>"><i class="bi bi-folder"></i> Library</a></li>
                                <?php endif; ?>
                                <?php if(canView('document_templates')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('templates') ?>"><i class="bi bi-file-earmark"></i> Templates</a></li>
                                <?php endif; ?>
                                <?php if(canView('e_signatures')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('e_signatures') ?>"><i class="bi bi-pen"></i> E-Sign</a></li>
                                <?php endif; ?>
                                <li><h6 class="dropdown-header">Compliance</h6></li>
                                <?php if(canView('compliance_documents')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('compliance_documents') ?>"><i class="bi bi-shield-check"></i> Compliance</a></li>
                                <?php endif; ?>
                                <?php if(canView('audit_logs')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('audit_logs') ?>"><i class="bi bi-clock-history"></i> Audit Logs</a></li>
                                <?php endif; ?>
                            </ul>
                        </li>
                        <?php endif; ?>
                        
                        <!-- Reports -->
                        <?php if(hasReportsAccess()): ?>
                        <li class="nav-item mega-dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="reportsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-graph-up"></i> Reports
                            </a>
                            <div class="dropdown-menu mega-dropdown-menu" aria-labelledby="reportsDropdown">
                                <div class="row">
                                    <div class="col-lg-3 mega-column">
                                        <h6>Financial Reports</h6>
                                        <?php if(canView('income_statement')): ?><a class="dropdown-item" href="<?= getUrl('income_statement') ?>"><i class="bi bi-graph-up-arrow"></i> Income Statement</a><?php endif; ?>
                                        <?php if(canView('balance_sheet')): ?><a class="dropdown-item" href="<?= getUrl('balance_sheet') ?>"><i class="bi bi-bar-chart"></i> Balance Sheet</a><?php endif; ?>
                                        <?php if(canView('cash_flow')): ?><a class="dropdown-item" href="<?= getUrl('cash_flow') ?>"><i class="bi bi-arrow-left-right"></i> Cash Flow</a><?php endif; ?>
                                        <?php if(canView('financial_reports')): ?><a class="dropdown-item" href="<?= getUrl('consolidated_expenses') ?>"><i class="bi bi-cash-stack"></i> Consolidated Expenses</a><?php endif; ?>
                                        <?php if(canView('trial_balance')): ?><a class="dropdown-item" href="<?= getUrl('trial_balance') ?>"><i class="bi bi-journal"></i> Trial Balance</a><?php endif; ?>
                                        <?php if(canView('ledger_report')): ?><a class="dropdown-item" href="<?= getUrl('ledger_report') ?>"><i class="bi bi-journal-text"></i> General Ledger</a><?php endif; ?>
                                        <?php if(canView('financial_reports')): ?><a class="dropdown-item" href="<?= getUrl('ar_aging') ?>"><i class="bi bi-hourglass-split"></i> Receivables Aging</a><?php endif; ?>
                                        <?php if(canView('financial_reports')): ?><a class="dropdown-item" href="<?= getUrl('ap_aging') ?>"><i class="bi bi-hourglass-split"></i> Payables Aging</a><?php endif; ?>
                                        <?php if(canView('financial_reports')): ?><a class="dropdown-item" href="<?= getUrl('customer_statement') ?>"><i class="bi bi-file-earmark-text"></i> Customer Statement</a><?php endif; ?>
                                        <?php if(canView('financial_reports')): ?><a class="dropdown-item" href="<?= getUrl('vendor_statement') ?>"><i class="bi bi-file-earmark-text"></i> Vendor Statement</a><?php endif; ?>
                                    </div>
                                    <div class="col-lg-3 mega-column">
                                        <h6>Business Reports</h6>
                                        <?php if(canView('sales_report')): ?><a class="dropdown-item" href="<?= getUrl('sales_report') ?>"><i class="bi bi-cart"></i> Sales Report</a><?php endif; ?>
                                        <?php if(canView('purchase_report')): ?><a class="dropdown-item" href="<?= getUrl('purchase_report') ?>"><i class="bi bi-basket"></i> Purchase Report</a><?php endif; ?>
                                        <?php if(canView('received_invoices')): ?><a class="dropdown-item" href="<?= getUrl('po_invoice_report') ?>"><i class="bi bi-clipboard-data"></i> PO vs Invoice Report</a><?php endif; ?>
                                        <?php if(canView('inventory_report')): ?><a class="dropdown-item" href="<?= getUrl('inventory_report') ?>"><i class="bi bi-boxes"></i> Inventory Report</a><?php endif; ?>
                                    
                                            
                                        <?php if(canView('expense_report')): ?><a class="dropdown-item" href="<?= getUrl('expense_report') ?>"><i class="bi bi-cash-stack"></i> Expense Report</a><?php endif; ?>
                                    </div>
                                    <div class="col-lg-3 mega-column">
                                        <h6>Analytics</h6>
                                        <?php if(canView('performance_dashboard')): ?><a class="dropdown-item" href="<?= getUrl('performance_dashboard') ?>"><i class="bi bi-speedometer2"></i> Performance</a><?php endif; ?>
                                        <?php if(canView('customer_analysis')): ?><a class="dropdown-item" href="<?= getUrl('customer_analysis') ?>"><i class="bi bi-people"></i> Customer Analysis</a><?php endif; ?>
                                        <?php if(canView('product_analysis')): ?><a class="dropdown-item" href="<?= getUrl('product_analysis') ?>"><i class="bi bi-box"></i> Product Analysis</a><?php endif; ?>
                                        <?php if(canView('sales_forecast')): ?><a class="dropdown-item" href="<?= getUrl('sales_forecast') ?>"><i class="bi bi-graph-up-arrow"></i> Sales Forecast</a><?php endif; ?>
                                        <?php if(canView('trends_analysis')): ?><a class="dropdown-item" href="<?= getUrl('trends_analysis') ?>"><i class="bi bi-activity"></i> Trends</a><?php endif; ?>
                                    </div>
                                    <div class="col-lg-3 mega-column">
                                        <h6>Compliance & Operations</h6>
                                        <?php if(canView('tax_report')): ?><a class="dropdown-item" href="<?= getUrl('tax_report') ?>"><i class="bi bi-percent"></i> Tax Report</a><?php endif; ?>
                                        <?php if(canView('tax_report')): ?><a class="dropdown-item" href="<?= getUrl('wht_report') ?>"><i class="bi bi-cash-stack"></i> WHT Report</a><?php endif; ?>
                                        <?php if(canView('tax_report')): ?><a class="dropdown-item" href="<?= getUrl('wht_receivable_report') ?>"><i class="bi bi-cash-coin"></i> WHT Credit (Received)</a><?php endif; ?>
                                        <?php if(canView('audit_report')): ?><a class="dropdown-item" href="<?= getUrl('audit_report') ?>"><i class="bi bi-shield-check"></i> Audit Report</a><?php endif; ?>
                                        <?php if(canView('compliance_report')): ?><a class="dropdown-item" href="<?= getUrl('compliance_report') ?>"><i class="bi bi-file-check"></i> Compliance</a><?php endif; ?>
                                        <?php if(canView('employee_report')): ?><a class="dropdown-item" href="<?= getUrl('employee_report') ?>"><i class="bi bi-person-badge"></i> Employee Report</a><?php endif; ?>
                                        <?php if(canView('asset_report')): ?><a class="dropdown-item" href="<?= getUrl('asset_report') ?>"><i class="bi bi-pc-display"></i> Asset Report</a><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </li>
                        <?php endif; ?>
                        
                        <!-- Admin -->
                        <?php if (isset($role_id) && ($role_id == 1 || strtolower($user_role) == 'admin')): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-sliders"></i> Admin
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="adminDropdown">
                                <li><h6 class="dropdown-header">User Management</h6></li>
                                <li><a class="dropdown-item" href="<?= getUrl('users') ?>"><i class="bi bi-people"></i> Users</a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('user_roles') ?>"><i class="bi bi-shield-check"></i> Roles & Permissions</a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('user_projects') ?>"><i class="bi bi-diagram-3"></i> Project Assignments</a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('login_history') ?>"><i class="bi bi-clock-history"></i> Login History</a></li>
                                <li><h6 class="dropdown-header">System Configuration</h6></li>
                                <li><a class="dropdown-item" href="<?= getUrl('system_settings') ?>"><i class="bi bi-gear"></i> Settings</a></li>
                                <?php if (isAdmin()): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('ai_settings') ?>"><i class="bi bi-stars"></i> AI Assistant</a></li>
                                <?php endif; ?>
                                <li><a class="dropdown-item" href="<?= getUrl('company_profile') ?>"><i class="bi bi-building"></i> Company Profile</a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('backup_restore') ?>"><i class="bi bi-database"></i> Backup</a></li>
                                <li><h6 class="dropdown-header">Business Settings</h6></li>
                                <li><a class="dropdown-item" href="<?= getUrl('tax_settings') ?>"><i class="bi bi-percent"></i> Tax</a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('payment_settings') ?>"><i class="bi bi-credit-card"></i> Payments</a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('notification_settings') ?>"><i class="bi bi-bell"></i> Notifications</a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('notification_rules') ?>"><i class="bi bi-bell-fill"></i> Notification Rules</a></li>
                            </ul>
                        </li>
                        <?php endif; ?>
                    </ul>

                    <!-- User Account (right side of bottom nav — matches Vikundi) -->
                    <ul class="navbar-nav">
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle py-2 px-3 d-flex align-items-center fw-bold" href="#" id="userDrop" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-person-circle fs-5 me-2"></i>
                                <div class="d-none d-xl-block">
                                    <span class="d-block" style="font-size:0.85rem;line-height:1;"><?= htmlspecialchars($username) ?></span>
                                    <span class="text-white-50" style="font-size:10px;text-transform:uppercase;"><?= htmlspecialchars($user_role) ?></span>
                                </div>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-0" aria-labelledby="userDrop">
                                <li class="px-3 py-2 border-bottom">
                                    <div class="fw-bold" style="font-size:0.85rem;"><?= htmlspecialchars($username) ?></div>
                                    <div class="text-muted" style="font-size:0.72rem;text-transform:uppercase;"><?= htmlspecialchars($user_role) ?></div>
                                </li>
                                <li><a class="dropdown-item py-2" href="<?= getUrl('profile') ?>"><i class="bi bi-person me-2"></i> Profile</a></li>
                                <li><a class="dropdown-item py-2" href="<?= getUrl('my_settings') ?>"><i class="bi bi-gear me-2"></i> Settings</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item py-2" href="<?= getUrl('help') ?>"><i class="bi bi-question-circle me-2"></i> Help</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item py-2 text-danger fw-bold" href="<?= getUrl('logout') ?>"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                            </ul>
                        </li>
                    </ul>
                </div><!-- /.collapse -->
                </div><!-- /.header-nav-bar -->
            </div><!-- /.container-fluid -->
        </nav><!-- /.bottom-header -->

    </div><!-- /.header-wrapper -->

    <script>
    /* Runs synchronously — header is in the DOM, body content not yet rendered */
    (function() {
        var h = document.querySelector('.header-wrapper');
        if (!h) return;
        var setpad = function() {
            var height = h.offsetHeight;
            document.body.style.setProperty('padding-top', height + 'px', 'important');
            document.documentElement.style.scrollPaddingTop = (height + 8) + 'px';
        };
        setpad();
        window.addEventListener('resize', setpad);
    })();
    </script>

    <?php
    // Global Print Header (Visible only when printing)
    if (!defined('BMS_SUPPRESS_PRINT_HEADER') && function_exists('renderPrintHeader')) {
        renderPrintHeader();
    }
    ?>

    <!-- Main Content Area -->
    <div class="container-fluid mt-4">

<script>
// Initialize Bootstrap dropdowns
document.addEventListener('DOMContentLoaded', function() {
    // Enable all dropdowns
    var dropdownElements = document.querySelectorAll('.dropdown-toggle');
    dropdownElements.forEach(function(dropdown) {
        new bootstrap.Dropdown(dropdown);
    });
    
    // Mega dropdown positioning
    var megaDropdowns = document.querySelectorAll('.mega-dropdown');
    megaDropdowns.forEach(function(mega) {
        mega.addEventListener('show.bs.dropdown', function(e) {
            var menu = this.querySelector('.dropdown-menu');
            menu.style.left = '50%';
            menu.style.transform = 'translateX(-50%)';
        });
    });
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown-toggle')) {
            var openDropdowns = document.querySelectorAll('.dropdown-menu.show');
            openDropdowns.forEach(function(dropdown) {
                bootstrap.Dropdown.getInstance(dropdown.previousElementSibling).hide();
            });
        }
    });
});
</script>