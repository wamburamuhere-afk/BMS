<?php
// session_start(); // Handled by roots.php
require_once __DIR__ . '/includes/config.php';
// AI Assistant helpers (aiConfigured) — so the Comms menu can show "Ask BMS"
// only when AI is enabled. Cheap; reads a few settings. Never fatals.
if (is_file(__DIR__ . '/core/ai_service.php')) require_once __DIR__ . '/core/ai_service.php';
// posSimpleModeEnabled() — Reports/Finance menus collapse to a shopkeeper-simple
// view when a tenant turns this on (POS Settings). See pos_config_settings.php.
require_once __DIR__ . '/core/pos_nav.php';
require_once __DIR__ . '/core/nav_menu.php';

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

// Resolve the user's Language preference and load the matching translation
// catalog for the rest of this request (t()/te() calls in header.php and
// every page that includes it). Cached in session so this only hits the DB
// once per session rather than on every page load — refreshed only when
// absent from session (my_settings.php's save_preferences handler updates
// $_SESSION['user_lang'] directly whenever it saves a new value to the DB).
if (!isset($_SESSION['user_lang'])) {
    $_SESSION['user_lang'] = get_setting('user_language_' . $_SESSION['user_id'], 'en');
}
// header.php always runs before a page's own POST handling, so without this
// the navbar would keep showing the *previous* language for exactly one
// request — the one where the user just changed it — even though the save
// itself succeeds. Prefer the just-posted value on that one request only.
$__bms_lang_pref = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_preferences']) && isset($_POST['user_language']))
    ? $_POST['user_language']
    : $_SESSION['user_lang'];
// Organization-wide language (System Settings): overrides every personal choice.
$__bms_org_lang = orgLanguage();
if ($__bms_org_lang !== null) {
    $_SESSION['user_lang'] = $__bms_org_lang;
    $__bms_lang_pref = $__bms_org_lang;
}
loadLanguage($__bms_lang_pref);

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

// ESS linchpin (Tier 4, D24): resolve the session user's linked employee_id
// each request so the "My HR" nav item + page gates know the link exists.
// Guarded so it is a no-op on a DB where the column isn't there yet.
if (!empty($_SESSION['user_id'])) {
    try {
        $__essStmt = $pdo->prepare("SELECT employee_id FROM users WHERE user_id = ?");
        $__essStmt->execute([(int)$_SESSION['user_id']]);
        $__essEmp = $__essStmt->fetchColumn();
        $_SESSION['employee_id'] = ($__essEmp !== false && $__essEmp !== null) ? (int)$__essEmp : null;
    } catch (Throwable $e) {
        // column not present yet (pre-migration) — leave unset
    }
}

// Login History — precise location (one-shot, consent-based). Only ask the
// browser once per session: check here whether THIS session's row already has
// a fix, and skip emitting the JS entirely if so, rather than relying only on
// a client-side "don't ask again" flag that a private window/cleared storage
// could bypass. Never required — Approximate (GeoIP) location always works
// with no prompt at all; this only ever upgrades it.
$__bmsAskPreciseLocation = false;
if (!empty($_SESSION['session_row_id'])) {
    try {
        $__geoStmt = $pdo->prepare("SELECT precise_captured_at FROM user_sessions WHERE id = ?");
        $__geoStmt->execute([(int) $_SESSION['session_row_id']]);
        // fetchColumn() returns false when no row matches and null/'' when the
        // column itself is unset — every one of those means "not captured yet".
        // Only a real timestamp string should suppress the prompt.
        $__bmsAskPreciseLocation = empty($__geoStmt->fetchColumn());
    } catch (Throwable $e) {
        // column not present yet (pre-migration) — leave false, never blocks the page
    }
}

// ── Background housekeeping (email outbox drain + 5 daily checks) ──────────
// These six jobs used to run INLINE right here (see git history / changelog
// for the pre-2026-09-07 version). That meant whichever user's ordinary page
// load happened to be "due" absorbed the full synchronous cost — up to 50 real
// SMTP sends (each up to a 20s connect timeout) for the outbox, or a full table
// scan for the daily checks. That is the "the system sometimes just freezes"
// symptom: nothing to do with the page being loaded, purely a timing
// coincidence of which request crossed the throttle window.
//
// api/run_background_jobs.php now owns the actual work and re-checks every one
// of these conditions itself; the checks below only decide CHEAPLY (settings
// are already cached in-process by get_setting()) whether it is worth firing
// an async, fire-and-forget ping at all, so a normal page load never blocks on
// any of it.
$__bmsBackgroundDue = function_exists('get_setting') && (
    get_setting('doc_expiry_last_run') !== date('Y-m-d')
    || get_setting('leave_accrual_last_run') !== date('Y-m-d')
    || get_setting('hr_expiry_last_run') !== date('Y-m-d')
    || get_setting('notif_checks_last_run') !== date('Y-m-d')
    || (time() - (int) get_setting('notif_outbox_last_ts', '0')) >= 120
    || (get_setting('notif_digest_enabled', '0') === '1' && get_setting('notif_digest_last_run') !== date('Y-m-d'))
);

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
<html lang="<?= htmlspecialchars(currentLanguage()) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title : 'Business Management System'; ?></title>

    <link rel="manifest" href="<?= getUrl('manifest.json') ?>">
    <meta name="theme-color" content="#ffffff">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Smart BMS">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="apple-touch-icon" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAALQAAAC0CAMAAAAKE/YAAAAALVBMVEX+/v7U1NiMj7dcX5k9QYY1OoEpLXqwsczV1uQVGm0cIXGdn8KoqK1+ga6/wNfXo1VqAAAFCUlEQVR42u2c23akIBBFG1FETOb/P3da++Id6hSW4ArnJZNMQu2QCrSy4+NRUlJSUlJSUlJSUlLyJ6MqXTfGNLWuWpsahpRWm24Wp/vURGHkeiR1L2A3/sNUqam8UfUXeJrq5/umTU12nOqJ2O3k+UF9IYZd5Pvu/ifrA+YRu7HhCrN32MTq5zB7i0J9iDxSOxVdgTDJP95sELzMI7WNrEDITyArBO1nHqiNjaoQ1xvvLD69CjEP1DqmwhkTvZwIFSJ+Uff8CgLQNQW6W7Z1uALa1RYasg03x7ZBUkMTJ3o51YmhWzJzV2UDranQXddkA23o0J3KBJq03m37Iy00YWPZWz/SQgd38P2mTgtdI9DTopcaGkgu0MjiMVs+CrRse3SWUSE1tMsEGlryzLdEWuhbbi7kF3k5beOW3h2uazOBRl6aTi2dGpp4tbW63koMTd5eFtfjqaGB9UOyp8EhgZ28TQo9v4dB7uoZNVaBlDY45OLTkV3xTS1wWyzY1KufHXIl0LIqUKKwEa2BqcEK8XO9HZFBDVYgYlu1n93fENvgfY1VEAmwnXf5nHf1buesyLnMqa12x5jZUj9stbr6Mr/9wVFdRtSv83wzNIozuvr3GK8S8qcesjjCPLq2yY56kUJNytCjtTF1lHPi7et3hdOslo1zwp2bQ2p1VoVvpblz0r2dE6Yq47vT8LFaXt9XlNWy55yMg/Lm4oj6VKvldOek7YgvqfhWi/YNWnuck+WbwT2BqT1WizcB58TsjBl2ThBqh7+iDjonhuWc0O/77Vgt4d4IOifN6ktCl3wWpjYYtaBzgnQI9Nso6pwg1MiWQDyQMDznBLmfTW8QjnMichceaRCOcyIjiNCnmuWcQNBkb2FhtXgD3JerOT3NtVq8kXdOeBV8+UcfkHlYxbRafOEdCyI93fMOHk9q6VlTX3DE6wvknBgeNBDaotewhpTXFrzB9A0ONDQttOWDB42sHgLQ2JAcaIH2kJe/mBV84S1IiZc8YHPpeJsLVKEmQSOnr9Mmi72ePn1H5DknF1gtgaYmZ2q4C6wWb3r6NPzyoHlWiz/yzgl9AyPfZuI4J5jVQnxxCt35kHdOqEfSwP08oOeYzgmpAniLibxnTdTnWy2OfFUL/vgmagmrBbzZa520c0LwQ+AzDIVTn2q1PP+LcSBFN2Wmvj5STnCrxXHPRTVwrnNuhecHG64OJO+c7Ffoov5ke+OceGaeR72u8Dpf1dxpfg9arS4Zje5Ppd6rEIc8RlW6Gb9/Z/TvQLZ7K5jd16sK0Sfjs+l4rgsB5ySGelNBIsU5SUz96RDVa103zWi1pAYNU3db58Sc+JslRz2ttp83XKvlaupV0zT5zDZwdHfpk1Q+GVbQ7SqKGAW1BcaPWK9JzgmZmme1oJNLuRCJok72JBWEmme1nNQb7zxg6nyepIJQ5/MkFcA5YVot1FzgnKSFZjoniaF5zkliaHmrRQCafmfY3fxJKon/Qp938FiepIJD3/KhJLeEvuVDSW4JLW+1iPT0DR9pJG+1CEDLK5MC0EznJC30XZ+kQsZmWi0C0Lk8SeUC5yTxTFPXD+kLW6EnqajU0LHOCVaBFHnnBKxACeqcUKwWFVWBEtQ5CfshbVyF+Lnejui3WnadE5knqWDOicGdE6yCRKyEcyIfCedEPiLOyQXYIs6JfFSlzeuY2dRZnYyHIu+clJSUlJSUlJSUlJT8nfwHEXIZVeHdeokAAAAASUVORK5CYII=">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('<?= getUrl('sw.js') ?>').catch(function () {});
            });
        }
        // Installed desktop app without a title bar: tint the window buttons to the header blue.
        (function () {
            if (!window.matchMedia) return;
            var mq = window.matchMedia('(display-mode: window-controls-overlay)');
            var meta = document.querySelector('meta[name="theme-color"]');
            function sync() { meta.setAttribute('content', mq.matches ? '#0b5ed7' : '#ffffff'); }
            sync();
            if (mq.addEventListener) mq.addEventListener('change', sync);
        })();
    </script>
    <style>
        @media (display-mode: window-controls-overlay) {
            .top-header {
                app-region: drag;
                -webkit-app-region: drag;
                padding-left: env(titlebar-area-x, 0);
                padding-right: calc(100vw - env(titlebar-area-x, 0px) - env(titlebar-area-width, 100vw));
            }
            .top-header a, .top-header button { app-region: no-drag; -webkit-app-region: no-drag; }
        }
    </style>

    <!-- Open the DNS + TCP + TLS connection to each CDN in parallel, immediately.
         Every page pulls scripts, stylesheets and webfonts from these four hosts,
         and the browser would otherwise pay a fresh handshake for each one only
         when it first reaches that tag. On a high-latency link (which is what
         users see on the live site, versus localhost) those serial handshakes
         cost more than the downloads. Purely a hint — no behaviour changes. -->
    <link rel="preconnect" href="https://code.jquery.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preconnect" href="https://cdn.datatables.net" crossorigin>
    <link rel="dns-prefetch" href="https://code.jquery.com">
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
    <link rel="dns-prefetch" href="https://cdn.datatables.net">

    <!-- jQuery first — deliberately NOT deferred: page markup contains inline
         <script> blocks that use $ during parsing, which would break. -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <!-- S/No column helper for every DataTable (BMSSerial) — needs only jQuery. -->
    <script src="<?= getUrl('assets/js/bms-serial.js') ?>?v=<?= @filemtime(__DIR__ . '/assets/js/bms-serial.js') ?>"></script>
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
    // Company operating currency (core/currency.php) for plain .js files that cannot run PHP.
    window.BMS_COMPANY_CURRENCY = <?= json_encode(bmsCompanyCurrency()) ?>;
    window.BMS_CURRENCY_SYMBOL  = <?= json_encode(bmsCurrencySymbol()) ?>;
    $.ajaxSetup({ headers: { 'X-CSRF-Token': CSRF_TOKEN } });

    // Text Display Case (2026-09-18) — JS twin of core/text_display_case.php's
    // applyCaseMode()/caseFormat(), for pages whose lists render rows
    // client-side from AJAX/DataTables JSON (server-side PHP never runs on
    // that raw JSON, since it's built in the API endpoint then rendered by a
    // JS `columns[].render` function, or set via jQuery .text()). Same rule,
    // same read-only-display-only guarantee: never call either of these on
    // a value that will be written back into an editable input.
    const TEXT_CASE_MODE = <?= json_encode(textDisplayCaseMode()) ?>;
    // Pure transform, NO escaping — use this with jQuery .text()/.val() or
    // any DOM API that already escapes for you (textContent, etc.). Passing
    // an escaped string to .text() would show literal "&amp;" on screen
    // instead of "&", since .text() doesn't decode entities.
    function applyCaseModeJs(str, modeOverride) {
        if (str === null || str === undefined || str === '') return '';
        str = String(str);
        var mode = modeOverride || TEXT_CASE_MODE;
        switch (mode) {
            case 'lower':
                return str.toLowerCase();
            case 'upper':
                return str.toUpperCase();
            case 'title':
                return str.toLowerCase().replace(/\b\w/g, function (c) { return c.toUpperCase(); });
            case 'sentence':
                return str.toLowerCase().replace(/(^\s*\w|[.!?]\s+\w)/g, function (c) { return c.toUpperCase(); });
            case 'toggle':
                return str.split('').map(function (ch) {
                    return ch === ch.toUpperCase() ? ch.toLowerCase() : ch.toUpperCase();
                }).join('');
            // 'as_typed' (default): unchanged
            default:
                return str;
        }
    }
    // Transform + escape — use this when building an HTML string yourself
    // (template literals inserted via .html()/innerHTML/DataTables render:).
    function caseFormatJs(str) {
        return applyCaseModeJs(str).replace(/[&<>"']/g, function (m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
        });
    }

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

    // Single-warehouse auto-select (2026-09-18)
    // When a warehouse/shop <select> has exactly 1 real option the user should
    // not have to pick it — auto-select it and replace the dropdown with a
    // read-only name label. The <select> is kept in the DOM (just hidden) so
    // form submission still sends the value and JS can still read .val().
    // Runs on DOM ready AND every shown.bs.modal so both page-level forms and
    // dynamically opened create modals are covered.
    function bmsAutoSelectWarehouse($scope) {
        $scope.find('select[name="warehouse_id"], select#posWarehouseId').each(function () {
            var $sel = $(this);
            if ($sel.data('bms-single-wh-done')) return; // idempotent
            $sel.data('bms-single-wh-done', true);
            var $real = $sel.find('option').filter(function () {
                var v = String($(this).val());
                return v !== '' && v !== '0';
            });
            if ($real.length !== 1) return; // 0 or 2+ options → leave as normal dropdown
            var wid  = $real.first().val();
            var name = applyCaseModeJs($real.first().text().trim());
            // Set value and fire change so dependents react (loadProducts,
            // warehouse-project-filter, Select2 display, etc.)
            $sel.val(wid);
            if (!$sel.prop('disabled')) $sel.trigger('change');
            // Hide the select visually but keep it in the DOM so form POST
            // still submits the value and JS (.val()) still works.
            // Remove `required` to prevent browser validation on a hidden field.
            $sel.addClass('d-none').prop('required', false);
            // If wrapped in an input-group, hide that too
            var $ig = $sel.parent('.input-group');
            if ($ig.length) $ig.addClass('d-none');
            // Insert a compact plaintext display where the select was
            var $display = $('<div class="bms-single-wh-display d-flex align-items-center gap-2 py-1 small"></div>')
                .append('<i class="bi bi-house-door text-primary"></i>')
                .append($('<span class="fw-semibold text-dark"></span>').text(name));
            ($ig.length ? $ig : $sel).before($display);
        });
    }
    $(document).ready(function () { bmsAutoSelectWarehouse($(document)); });
    $(document).on('shown.bs.modal', function (e) { bmsAutoSelectWarehouse($(e.target)); });

    // Stat card labels: remove forced uppercase so text shows in its natural case
    $(document).ready(function () {
        $('[id^="stat-"]').each(function () {
            $(this).closest('.card-body, .card').find('.text-uppercase').not('[id^="stat-"]').removeClass('text-uppercase');
        });
    });

    // Language toggle pill — flip between EN and SW without going to preferences
    function bmsToggleLang() {
        var current = '<?= $__bms_lang_pref ?>';
        var next    = current === 'sw' ? 'en' : 'sw';
        $.post('<?= buildUrl('api/set_language.php') ?>', { lang: next, _csrf: CSRF_TOKEN }, function (res) {
            if (res.success) location.reload();
        }, 'json');
    }

    // Mobile marquee: only scroll when the company name is actually wider than the
    // available space. Short names (e.g. "BJP SHOP") stay static and centred.
    $(document).ready(function () {
        var $ct = $('.marquee-container');
        var $tx = $('.marquee-text');
        if (!$ct.length || !$tx.length) return;
        // Neutralise the marquee CSS so we can measure the raw text width
        $tx.css({ animation: 'none', paddingLeft: '0' });
        var textW  = $tx[0].scrollWidth;
        var contW  = $ct[0].offsetWidth;
        if (textW <= contW) {
            // Text fits — keep it static and centred
            $tx.css({ textAlign: 'center', width: '100%' });
        } else {
            // Text overflows — restore the scrolling marquee
            $tx.css({ animation: '', paddingLeft: '' });
        }
    });

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

<?php if ($__bmsBackgroundDue): ?>
<script>
    // Fire-and-forget: api/run_background_jobs.php re-verifies every condition
    // itself and does the real work (up to 50 SMTP sends, daily housekeeping
    // scans) in its OWN request, so THIS page never waits on any of it.
    if (navigator.sendBeacon) {
        navigator.sendBeacon(APP_URL + '/api/run_background_jobs');
    } else {
        $.post(APP_URL + '/api/run_background_jobs');
    }
</script>
<?php endif; ?>

<?php if ($__bmsAskPreciseLocation): ?>
<script>
    // Login History — precise location (one-shot, consent-based upgrade over
    // the always-on IP-based Approximate location). PHP has already confirmed
    // this session doesn't have a fix yet, so this only ever runs once per
    // login. If the browser lacks the API, the user already declined at the
    // OS/browser level, or the origin isn't secure, geolocation fails or is
    // simply absent — either way this must never surface an error to the user
    // or block anything else on the page.
    (function () {
        if (!('geolocation' in navigator)) return;
        navigator.geolocation.getCurrentPosition(
            function (pos) {
                $.post('<?= buildUrl('api/session_geo_ping.php') ?>', {
                    lat: pos.coords.latitude,
                    lng: pos.coords.longitude,
                    accuracy: pos.coords.accuracy
                });
            },
            function () { /* denied or unavailable — Approximate location remains authoritative, silently */ },
            { enableHighAccuracy: false, timeout: 8000, maximumAge: 300000 }
        );
    })();
</script>
<?php endif; ?>

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
    <link rel="stylesheet" href="<?= getUrl('assets/css/responsive.css') ?>?v=<?= @filemtime(__DIR__ . '/assets/css/responsive.css') ?>">
    <link rel="stylesheet" href="<?= getUrl('assets/css/loading.css') ?>?v=<?= @filemtime(__DIR__ . '/assets/css/loading.css') ?>">

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

        /* Top bar: company name truncates instead of pushing date-location off-screen */
        .top-header .container-fluid > a {
            min-width: 0;
            flex-shrink: 1;
            overflow: hidden;
        }
        .top-header .container-fluid > a > h5 {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .date-location-box {
            flex-shrink: 0;
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

    <style>
        /* Stat cards: numbers scale to fit, never wrap to a second row */
        [id^="stat-"] {
            white-space: nowrap !important;
            overflow: hidden !important;
            font-size: clamp(0.72rem, 3.2vw, 1.25rem) !important;
            line-height: 1.4 !important;
        }
    </style>

<script src="<?= getUrl('assets/js/bms-mobile-cards.js') ?>?v=<?= @filemtime(__DIR__ . '/assets/js/bms-mobile-cards.js') ?>"></script>
<script>
window.BMS_PRICE_CHECK_T = <?= json_encode([
    'title' => t('Wholesale price is higher than retail'),
    'text'  => t('Wholesale price ({w}) is higher than retail price ({r}). Is this correct?'),
    'buyTitle'     => t('Selling price is below the buying price'),
    'buyRetail'    => t('Retail price ({r}) is lower than the buying price ({b}) — you would sell at a loss. Is this correct?'),
    'buyWholesale' => t('Wholesale price ({w}) is lower than the buying price ({b}) — you would sell at a loss. Is this correct?'),
    'multiTitle'   => t('Please check these prices'),
    'yes'   => t('Yes, keep it'),
    'no'    => t('No, change it'),
]) ?>;
</script>
<script src="<?= getUrl('assets/js/bms-price-check.js') ?>?v=<?= @filemtime(__DIR__ . '/assets/js/bms-price-check.js') ?>"></script>
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
                <?php require_once __DIR__ . '/includes/help_panel.php'; ?>
                <?php if (!posSimpleModeEnabled()) helpPanelButton('mobile', 'ms-auto me-2 d-lg-none'); ?>
                <button class="navbar-toggler<?= posSimpleModeEnabled() ? ' ms-auto' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <?php if (posSimpleModeEnabled()): ?>
                <!-- Simple POS mobile: Language + ☰ (opens the left side menu). Desktop: hidden. -->
                <div class="bms-mobile-actions d-flex d-lg-none align-items-center gap-1 ms-auto">
                    <?php helpPanelButton('mobile'); ?>
                    <?php if ($__bms_org_lang === null): ?>
                    <button type="button" class="btn btn-outline-light"
                        onclick="event.stopPropagation();bmsToggleLang();"
                        title="<?= $__bms_lang_pref === 'sw' ? 'Switch to English' : 'Badilisha lugha kuwa Kiswahili' ?>"
                        style="font-size:0.65rem;padding:1px 7px;border-radius:14px;font-weight:700;letter-spacing:0.3px;line-height:1.4;">
                        <i class="bi bi-globe2 me-1"></i><?= strtoupper($__bms_lang_pref) ?>
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-light"
                        data-bs-toggle="offcanvas" data-bs-target="#bmsSideMenu" aria-controls="bmsSideMenu"
                        title="<?= htmlspecialchars(t('Menu')) ?>" aria-label="<?= htmlspecialchars(t('Open menu')) ?>"
                        style="font-size:0.8rem;padding:2px 6px;line-height:1.4;">
                        <i class="bi bi-list"></i>
                    </button>
                </div>
                <?php endif; ?>

                <!-- Bottom Row: Navigation Modules -->
                <div class="header-nav-bar w-100">
                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav me-auto">
                        <!-- Core Modules — every group below is rendered by bmsNavMenu()
                             (core/nav_menu.php): 0 granted links = hidden, 1 = direct header
                             link, 2+ = dropdown. Simple POS mode keeps each group's original gate. -->
                        <?php ob_start(); ?>
                            <ul class="dropdown-menu" aria-labelledby="coreDropdown">
                                <li><h6 class="dropdown-header"><?= posSimpleModeEnabled() ? t('My Business') : t('Business Core') ?></h6></li>
                                <?php if(canView('dashboard')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('dashboard') ?>"><i class="bi bi-speedometer2"></i> <?= t('Dashboard') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('customers')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('customers') ?>"><i class="bi bi-people"></i> <?= t('Customers') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('suppliers')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('suppliers') ?>"><i class="bi bi-truck"></i> <?= t('Suppliers') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('suppliers') && tenantFeatureEnabled('projects')): ?>
                                <li><a class="dropdown-item" data-nav-companion href="<?= getUrl('sub_contractors') ?>"><i class="bi bi-person-workspace text-info"></i> <?= t('Sub-Contractors') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('products')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('products') ?>"><i class="bi bi-box text-success"></i> <?= wLabel('Inventory Products', 'Shop Products') ?></a></li>
                                <?php endif; ?>
                                <?php if(posSimpleModeEnabled() && canView('categories')): ?>
                                <!-- Smart POS: Categories has no Inventory menu, so it sits next to Shop Products
                                     (full ERP keeps it in the Inventory dropdown). Page does View/Add/Edit/Delete by permission. -->
                                <li><a class="dropdown-item" href="<?= getUrl('categories') ?>"><i class="bi bi-tags text-warning"></i> <?= t('Categories') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('products')): ?>
                                <li><a class="dropdown-item" data-nav-companion href="<?= getUrl('services') ?>"><i class="bi bi-box-seam text-primary"></i> <?= t('Service') ?></a></li>
                                <?php endif; ?>
                            </ul>
                        <?php bmsNavMenu(ob_get_clean(), 'coreDropdown',
                            '<i class="bi bi-house"></i> ' . (posSimpleModeEnabled() ? t('My Business') : t('Core')),
                            canView('dashboard') || canView('customers') || canView('suppliers') || canView('products')); ?>

                        <!-- Financial Modules -->
                        <?php
                        // Simple Mode ("normal business man" — core/pos_nav.php::posSimpleModeEnabled())
                        // hides the whole double-entry Finance dropdown, same
                        // reasoning as Chart of Accounts/Journals already being
                        // hidden from it. But expense tracking is essential even
                        // for a shop with no accountant, so — same pattern
                        // header.php already uses for POS standing alone when
                        // Sales is closed — Expenses promotes itself to a direct
                        // header link instead of disappearing along with the
                        // rest of Finance. Same page, same full CRUD; only where
                        // it's linked from changes. Once Simple Mode is off
                        // again ("pro business man" needing the double-entry
                        // system), the full Finance dropdown is back, Expenses
                        // included.
                        $financeModuleOpen = !posSimpleModeEnabled()
                            && (canView('expenses') || canView('budget') || canView('chart_of_accounts') || canView('bank_accounts') || canView('cash_register'));
                        ?>
                        <?php if(!posSimpleModeEnabled()): ?>
                        <?php ob_start(); ?>
                            <ul class="dropdown-menu" aria-labelledby="financeDropdown">
                                <li><h6 class="dropdown-header"><?= t('Accounting') ?></h6></li>
                                <?php if(canView('expenses')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('expenses') ?>"><i class="bi bi-receipt"></i> <?= t('Expenses') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('revenue')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('revenue') ?>"><i class="bi bi-cash-coin"></i> <?= t('Revenue / Other Income') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('budget')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('budget') ?>"><i class="bi bi-pie-chart"></i> <?= t('Budget') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('chart_of_accounts')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('chart_of_accounts') ?>"><i class="bi bi-diagram-3"></i> <?= t('Chart of Accounts') ?></a></li>
                                <?php endif; ?>

                                <li><h6 class="dropdown-header"><?= t('Banking & Cash') ?></h6></li>
                                <?php if(canView('bank_accounts')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('bank_accounts') ?>"><i class="bi bi-bank"></i> <?= t('Bank Accounts') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('cash_register')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('cash_register') ?>"><i class="bi bi-cash"></i> <?= t('Cash Register') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('petty_cash')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('petty_cash') ?>"><i class="bi bi-wallet"></i> <?= t('Petty Cash') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('bank_transfers')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('bank_transfers') ?>"><i class="bi bi-arrow-left-right"></i> <?= t('Bank Transfers') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('bank_reconciliation')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('bank_reconciliation') ?>"><i class="bi bi-check-circle"></i> <?= t('Reconciliation') ?></a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('bank_statement') ?>"><i class="bi bi-card-list"></i> <?= t('Bank Statement') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('journals')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('journals') ?>"><i class="bi bi-journal-text"></i> <?= t('Journals') ?></a></li>
                                <?php endif; ?>

                                <li><h6 class="dropdown-header"><?= t('Sales & Purchases') ?></h6></li>
                                <?php if(canView('invoices')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('invoices') ?>"><i class="bi bi-receipt"></i> <?= t('Invoices') ?></a></li>
                                <li><a class="dropdown-item" data-nav-companion href="<?= getUrl('receive_payment') ?>"><i class="bi bi-cash-stack"></i> <?= t('Receive Payment') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('purchase_orders')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('purchase_orders') ?>"><i class="bi bi-file-text"></i> <?= t('Purchase Orders') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('payment_vouchers')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('payment_vouchers') ?>"><i class="bi bi-credit-card"></i> <?= t('Payment Vouchers') ?></a></li>
                                <?php endif; ?>
                            </ul>
                        <?php bmsNavMenu(ob_get_clean(), 'financeDropdown', '<i class="bi bi-cash-stack"></i> ' . t('Finance'), $financeModuleOpen); ?>
                        <?php elseif(canView('expenses')): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= getUrl('expenses') ?>">
                                <i class="bi bi-receipt"></i> <?= t('Expenses') ?>
                            </a>
                        </li>
                        <?php endif; ?>

                        <!-- CRM -->
                        <?php ob_start(); ?>
                            <ul class="dropdown-menu" aria-labelledby="crmDropdown">
                                <li><h6 class="dropdown-header"><?= t('Customer Relations') ?></h6></li>
                                <?php if(canView('crm_dashboard')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('crm/dashboard') ?>"><i class="bi bi-speedometer2 me-1"></i><?= t('CRM Dashboard') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('crm_leads')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('crm/leads') ?>"><i class="bi bi-person-plus me-1"></i><?= t('Leads') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('crm_pipeline')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('crm/pipeline') ?>"><i class="bi bi-kanban me-1"></i><?= t('Pipeline Board') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('crm_pipeline')): ?>
                                <li><a class="dropdown-item" data-nav-companion href="<?= getUrl('crm/pipeline_stages') ?>"><i class="bi bi-gear me-1"></i><?= t('Pipeline Stages') ?></a></li>
                                <?php endif; ?>
                            </ul>
                        <?php bmsNavMenu(ob_get_clean(), 'crmDropdown', '<i class="bi bi-funnel"></i> ' . t('CRM'),
                            canView('crm_dashboard') || canView('crm_leads') || canView('crm_pipeline')); ?>

                        <!-- Sales -->
                        <?php
                        // 'sales_orders' is the flagship page_key for the whole Sales
                        // module — the same signal this dropdown's own outer gate
                        // already relied on. When Sales is closed for this tenant,
                        // POS stands alone as a direct header link instead of being
                        // buried inside a "Sales" dropdown that would otherwise exist
                        // only to hold it. Once Sales is open again, POS moves back
                        // inside it as before.
                        // (That flagship gate now applies in Simple POS mode only; otherwise
                        // bmsNavMenu() shows Sales as soon as any of its pages is granted,
                        // and POS alone still collapses to the same direct link.)
                        $salesModuleOpen = canView('sales_orders');
                        ?>
                        <?php ob_start(); ?>
                            <ul class="dropdown-menu" aria-labelledby="salesDropdown">
                                <li><h6 class="dropdown-header"><?= t('Sales Operations') ?></h6></li>
                                <?php if(canView('quotations')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('quotations') ?>"><i class="bi bi-file-text"></i> <?= t('Quotations') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('sales_orders')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('sales_orders') ?>"><i class="bi bi-bag"></i><?= t('Sales Orders') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('lpo')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('lpos') ?>"><i class="bi bi-file-earmark-text"></i> <?= t('LPO') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('dn')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('delivery_notes') ?>?type=outbound"><i class="bi bi-box-arrow-up-right"></i> <?= t('DN (Outbound)') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('pos')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('pos') ?>"><i class="bi bi-cart-check"></i> <?= htmlspecialchars(wLabel('POS', 'Sell')) ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('invoices')): ?>
                                <?php // companion: Invoices' own header entry lives under Finance, so it is not counted here (no duplicate direct link) ?>
                                <li><a class="dropdown-item" data-nav-companion href="<?= getUrl('invoices') ?>"><i class="bi bi-receipt"></i> <?= t('Invoices') ?></a></li>
                                <?php endif; ?>
                                <li><h6 class="dropdown-header"><?= t('Returns') ?></h6></li>
                                <?php if(canView('sales_returns')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('sales_returns') ?>"><i class="bi bi-arrow-return-left"></i> <?= t('Sales Returns') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('credit_notes')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('credit_notes') ?>"><i class="bi bi-receipt"></i> <?= t('Credit Notes') ?></a></li>
                                <?php endif; ?>
                            </ul>
                        <?php $__sales_shown = bmsNavMenu(ob_get_clean(), 'salesDropdown', '<i class="bi bi-cart"></i> ' . t('Sales'), $salesModuleOpen); ?>
                        <?php if(!$__sales_shown && canView('pos')): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= getUrl('pos') ?>">
                                <i class="bi bi-cart-check"></i> <?= htmlspecialchars(wLabel('POS', 'Sell')) ?>
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php // Restaurant — only when superadmin enabled Restaurant POS for this tenant (canView checks the entitlement first, even for admins). ?>
                        <?php if(canView('restaurant_pos')):
                            require_once __DIR__ . '/core/restaurant_scope.php';
                            $__rest_on = restaurantModuleOn($pdo); // Smart POS restaurant: show its daily pages directly ?>
                        <?php if ($__rest_on): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="restaurantDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-cup-hot"></i> <?= t('Restaurant') ?>
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="restaurantDropdown">
                                <li><a class="dropdown-item" href="<?= getUrl('restaurant/menu') ?>"><i class="bi bi-journal-richtext"></i> <?= t('Menu') ?></a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('restaurant/tables-board') ?>"><i class="bi bi-grid-3x3-gap"></i> <?= t('Tables') ?></a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('restaurant/kitchen-dashboard') ?>"><i class="bi bi-fire"></i> <?= t('Kitchen') ?></a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('restaurant/reservations') ?>"><i class="bi bi-calendar-check"></i> <?= t('Reservations') ?></a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('restaurant/modifier-group') ?>"><i class="bi bi-list-check"></i> <?= t('Modifier Group') ?></a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?= getUrl('restaurant') ?>"><i class="bi bi-cup-hot"></i> <?= t('All restaurant pages') ?></a></li>
                            </ul>
                        </li>
                        <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= getUrl('restaurant') ?>">
                                <i class="bi bi-cup-hot"></i> <?= t('Restaurant') ?>
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php endif; ?>

                        <!-- Inventory / Shop -->
                        <?php if(posSimpleModeEnabled()): ?>
                        <!-- Simple POS: "Shop" links directly to warehouses — no dropdown,
                             no products/services/categories (those are accessible via core). -->
                        <?php
                        $__simple_can_restock = canView('pos') && canView('pos_restock');
                        $__restock_url = getUrl('receive_stock'); // the Receive Stock window on its own page
                        ?>
                        <?php if(canView('warehouses') && $__simple_can_restock): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="simpleShopDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-house-door"></i> <?= wLabel('Inventory', 'Shop') ?>
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="simpleShopDropdown">
                                <li><a class="dropdown-item" href="<?= getUrl('warehouses') ?>"><i class="bi bi-shop"></i> <?= wLabel('Warehouses', 'Shops') ?></a></li>
                                <li><a class="dropdown-item" href="<?= $__restock_url ?>"><i class="bi bi-box-arrow-in-down"></i> <?= t('Receive Stock') ?></a></li>
                            </ul>
                        </li>
                        <?php elseif(canView('warehouses')): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= getUrl('warehouses') ?>">
                                <i class="bi bi-house-door"></i> <?= wLabel('Inventory', 'Shop') ?>
                            </a>
                        </li>
                        <?php elseif($__simple_can_restock): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= $__restock_url ?>"><i class="bi bi-box-arrow-in-down"></i> <?= t('Receive Stock') ?></a>
                        </li>
                        <?php endif; ?>
                        <?php else: ?>
                        <?php ob_start(); ?>
                            <ul class="dropdown-menu" aria-labelledby="inventoryDropdown">
                                <li><h6 class="dropdown-header"><?= wLabel('Stock Management', 'Shop Management') ?></h6></li>
                                <?php if(canView('products')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('products') ?>"><i class="bi bi-box text-success"></i> <?= wLabel('Inventory Products', 'Shop Products') ?></a></li>
                                <li><a class="dropdown-item" data-nav-companion href="<?= getUrl('services') ?>"><i class="bi bi-box-seam text-primary"></i> <?= t('Services') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('categories')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('categories') ?>"><i class="bi bi-tags"></i> <?= t('Categories') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('stock_adjustments')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('stock_adjustments') ?>"><i class="bi bi-arrow-left-right"></i> <?= t('Adjustments') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('inventory_valuation')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('inventory_valuation') ?>"><i class="bi bi-calculator"></i> <?= t('Valuation') ?></a></li>
                                <?php endif; ?>
                                <li><h6 class="dropdown-header"><?= wLabel('Warehouse', 'Shop') ?></h6></li>
                                <?php if(canView('warehouses')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('warehouses') ?>"><i class="bi bi-house-door"></i> <?= wLabel('Warehouses', 'Shops') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('locations')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('locations') ?>"><i class="bi bi-geo-alt"></i> <?= t('Locations') ?></a></li>
                                <?php endif; ?>
                            </ul>
                        <?php bmsNavMenu(ob_get_clean(), 'inventoryDropdown', '<i class="bi bi-boxes"></i> ' . wLabel('Inventory', 'Shop'),
                            canView('products') || canView('warehouses')); ?>
                        <?php endif; ?>

                        <!-- Purchases -->
                        <?php if (!posSimpleModeEnabled()): ?>
                        <?php ob_start(); ?>
                            <ul class="dropdown-menu" aria-labelledby="purchasesDropdown">
                                <li><h6 class="dropdown-header"><?= t('Procurement') ?></h6></li>

                                <?php if(canView('suppliers')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('suppliers') ?>"><i class="bi bi-truck"></i> <?= t('Suppliers') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('rfq')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('rfq') ?>"><i class="bi bi-file-earmark-text"></i> <?= t('RFQ') ?></a></li>
                                <?php endif; ?>

                                <?php if(canView('purchase_orders')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('purchase_orders') ?>"><i class="bi bi-file-text"></i><?= t('Purchase Order') ?></a></li>
                                <?php endif; ?>
                                 <?php if(canView('grn')): ?>
                                <li><a class="dropdown-item" data-nav-companion href="<?= getUrl('delivery_notes') ?>"><i class="bi bi-file-earmark-check"></i> <?= t('DN') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('grn')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('grn') ?>"><i class="bi bi-check-square"></i> <?= t('GRN') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('received_invoices')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('received_invoices') ?>"><i class="bi bi-inbox"></i> <?= t('Bills') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('purchase_returns')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('purchase_returns') ?>"><i class="bi bi-arrow-return-right"></i> <?= t('Return Note') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('debit_notes')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('debit_notes') ?>"><i class="bi bi-receipt-cutoff"></i> <?= t('Debit Notes') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('nip_materials')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('nip_materials') ?>"><i class="bi bi-boxes"></i> <?= t('Materials') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('tenders')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('tenders') ?>"><i class="bi bi-clipboard-check"></i> <?= t('Tenders') ?></a></li>
                                <?php endif; ?>
                            </ul>
                        <?php bmsNavMenu(ob_get_clean(), 'purchasesDropdown', '<i class="bi bi-basket"></i> ' . t('Procurement'), false); ?>
                        <?php endif; ?>

                        <!-- Operations -->
                        <?php ob_start(); ?>
                            <ul class="dropdown-menu" aria-labelledby="operationsDropdown">
                                <?php if(canView('hr_dashboard') || canView('employees') || canView('employee_lifecycle') || canView('employee_contracts') || canView('org_chart')): ?>
                                <li><h6 class="dropdown-header"><?= t('Workforce') ?></h6></li>
                                <?php if(canView('hr_dashboard')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('hr_dashboard') ?>"><i class="bi bi-speedometer2"></i> <?= t('HR Dashboard') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('employees')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('employees') ?>"><i class="bi bi-person-badge"></i> <?= t('Employees') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('employee_lifecycle')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('hr_actions') ?>"><i class="bi bi-person-lines-fill"></i> <?= t('HR Actions') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('org_chart')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('org_chart') ?>"><i class="bi bi-diagram-3"></i> <?= t('Org Chart') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('employee_contracts')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('employee_contracts') ?>"><i class="bi bi-file-earmark-text"></i> <?= t('Contracts') ?></a></li>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php if(canView('departments') || canView('designations') || canView('employment_types')): ?>
                                <li><h6 class="dropdown-header"><?= t('Org Structure') ?></h6></li>
                                <?php if(canView('departments')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('departments') ?>"><i class="bi bi-diagram-2"></i> <?= t('Departments') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('designations')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('designations') ?>"><i class="bi bi-person-badge"></i> <?= t('Designations') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('employment_types')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('employment_types') ?>"><i class="bi bi-person-workspace"></i> <?= t('Employment Types') ?></a></li>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php if(canView('hr_performance') || canView('trainings') || canView('recruitment') || canView('hr_checklists')): ?>
                                <li><h6 class="dropdown-header"><?= t('Performance & Growth') ?></h6></li>
                                <?php if(canView('hr_performance')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('hr_performance') ?>"><i class="bi bi-graph-up-arrow"></i> <?= t('Performance (HR)') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('trainings')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('trainings') ?>"><i class="bi bi-mortarboard"></i> <?= t('Training') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('recruitment')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('recruitment') ?>"><i class="bi bi-person-badge"></i> <?= t('Recruitment') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('hr_checklists')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('hr_checklists') ?>"><i class="bi bi-check2-square"></i> <?= t('Checklists') ?></a></li>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php if(canView('payroll') || canView('salary_components')): ?>
                                <li><h6 class="dropdown-header"><?= t('Payroll') ?></h6></li>
                                <?php if(canView('payroll')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('payroll') ?>"><i class="bi bi-cash"></i> <?= t('Payroll') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('salary_components')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('salary_components') ?>"><i class="bi bi-sliders"></i> <?= t('Salary Components') ?></a></li>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php if(canView('attendance') || canView('leaves') || canView('company_calendar')): ?>
                                <li><h6 class="dropdown-header"><?= t('Attendance & Leave') ?></h6></li>
                                <?php if(canView('attendance')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('attendance') ?>"><i class="bi bi-clock"></i> <?= t('Attendance') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('leaves')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('leaves') ?>"><i class="bi bi-calendar"></i> <?= t('Leaves') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('company_calendar')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('company_calendar') ?>"><i class="bi bi-calendar-week"></i> <?= t('Working Days & Holidays') ?></a></li>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php if(canView('meetings') || canView('employee_trips') || canView('announcements')): ?>
                                <li><h6 class="dropdown-header"><?= t('Communication & Calendar') ?></h6></li>
                                <?php if(canView('meetings')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('meetings') ?>"><i class="bi bi-calendar-event"></i> <?= t('Meetings') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('employee_trips')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('employee_trips') ?>"><i class="bi bi-airplane"></i> <?= t('Trips') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('announcements')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('announcements') ?>"><i class="bi bi-megaphone"></i> <?= t('Announcements') ?></a></li>
                                <?php endif; ?>
                                <?php endif; ?>
                                <li><h6 class="dropdown-header"><?= t('Assets & Maintenance') ?></h6></li>
                                <?php if(canView('assets')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('assets') ?>"><i class="bi bi-pc-display"></i> <?= t('Assets') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('maintenance')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('maintenance') ?>"><i class="bi bi-tools"></i> <?= t('Maintenance') ?></a></li>
                                <?php endif; ?>
                            </ul>
                        <?php bmsNavMenu(ob_get_clean(), 'operationsDropdown', '<i class="bi bi-gear"></i> ' . t('Operations'),
                            canView('employees') || canView('assets')); ?>
                        
                        <!-- Projects -->
                        <?php if (get_setting('enable_projects') == '1' && canView('projects')): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= getUrl('projects') ?>">
                                <i class="bi bi-kanban"></i> <?= t('Projects') ?>
                            </a>
                        </li>
                        <?php endif; ?>

                        <!-- Comms -->
                        <?php ob_start(); ?>
                            <ul class="dropdown-menu" aria-labelledby="communicationDropdown">
                                <li><h6 class="dropdown-header"><?= t('Communication') ?></h6></li>
                                <?php if(canView('ai_assistant') && function_exists('aiConfigured') && aiConfigured()): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('ai_assistant') ?>"><i class="bi bi-stars text-primary"></i> <?= t('Ask BMS') ?> <span class="badge bg-primary-subtle text-primary ms-1" style="font-size:.6rem;">AI</span></a></li>
                                <?php endif; ?>
                                <?php if(canView('message_center')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('message_center') ?>"><i class="bi bi-chat-left"></i> <?= t('Messages') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('email_templates')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('email_templates') ?>"><i class="bi bi-envelope"></i> <?= t('Email') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('notification_center')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('notification_center') ?>"><i class="bi bi-bell"></i> <?= t('Notifications') ?></a></li>
                                <?php endif; ?>
                                <li><h6 class="dropdown-header"><?= t('Marketing') ?></h6></li>
                                <?php if(canView('campaigns')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('campaigns') ?>"><i class="bi bi-megaphone"></i> <?= t('Campaigns') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('leads')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('leads') ?>"><i class="bi bi-person-plus"></i> <?= t('Leads') ?></a></li>
                                <?php endif; ?>
                            </ul>
                        <?php bmsNavMenu(ob_get_clean(), 'communicationDropdown', '<i class="bi bi-chat"></i> ' . t('Comms'),
                            canView('message_center') || canView('notification_center') || canView('ai_assistant')); ?>

                        <!-- Docs — 'audit_logs' is deliberately NOT part of this menu's gate: it is an
                             always-on report (not tied to the 'documents'/'compliance' features), and
                             living here alone used to keep this whole menu visible with empty headers
                             for a tenant with both features off. It now lives under Reports > Compliance
                             & Operations instead, so this menu fully disappears when its own features are off. -->
                        <?php
                        $show_doc_mgmt = canView('document_library') || canView('document_templates') || canView('e_signatures');
                        $show_doc_compliance = canView('compliance_documents');
                        ?>
                        <?php ob_start(); ?>
                            <ul class="dropdown-menu" aria-labelledby="documentsDropdown">
                                <?php if($show_doc_mgmt): ?>
                                <li><h6 class="dropdown-header"><?= t('Document Management') ?></h6></li>
                                <?php if(canView('document_library')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('library') ?>"><i class="bi bi-folder"></i> <?= t('Library') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('document_templates')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('templates') ?>"><i class="bi bi-file-earmark"></i> <?= t('Templates') ?></a></li>
                                <?php endif; ?>
                                <?php if(canView('e_signatures')): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('e_signatures') ?>"><i class="bi bi-pen"></i> <?= t('E-Sign') ?></a></li>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php if($show_doc_compliance): ?>
                                <li><h6 class="dropdown-header"><?= t('Compliance') ?></h6></li>
                                <li><a class="dropdown-item" href="<?= getUrl('compliance_documents') ?>"><i class="bi bi-shield-check"></i> <?= t('Compliance') ?></a></li>
                                <?php endif; ?>
                            </ul>
                        <?php bmsNavMenu(ob_get_clean(), 'documentsDropdown', '<i class="bi bi-files"></i> ' . t('Docs'),
                            $show_doc_mgmt || $show_doc_compliance); ?>

                        <!-- Customer Visits (marketing) — module 'field_reports', off by default -->
                        <?php if(canView('field_visits')): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= getUrl('field_reports') ?>"><i class="bi bi-geo-alt"></i> <?= t('Customer Visits') ?></a>
                        </li>
                        <?php endif; ?>

                        <!-- Reports -->
                        <?php ob_start(); ?>
                            <?php if (posSimpleModeEnabled()): ?>
                            <!-- Simple Mode — a short, plain-language report list for a small
                                 shop with no accountant. Same canView() gates as the full menu
                                 below; only the GL-facing columns (Financial/Analytics/Compliance)
                                 are dropped. See core/pos_nav.php::posSimpleModeEnabled(). -->
                            <ul class="dropdown-menu" aria-labelledby="reportsDropdown">
                                <li><h6 class="dropdown-header"><?= t('Business Reports') ?></h6></li>
                                <?php if(canView('sales_report')): ?><li><a class="dropdown-item" href="<?= getUrl('sales_report') ?>"><i class="bi bi-cart"></i> <?= t('Sales Report') ?></a></li><?php endif; ?>
                                <?php if(canView('purchase_report')): ?><li><a class="dropdown-item" href="<?= getUrl('purchase_report') ?>"><i class="bi bi-basket"></i> <?= t('Purchase Report') ?></a></li><?php endif; ?>
                                <?php if(canView('inventory_report')): ?><li><a class="dropdown-item" href="<?= getUrl('inventory_report') ?>"><i class="bi bi-boxes"></i> <?= t('Inventory Report') ?></a></li><?php endif; ?>
                                <?php if(canView('expense_report')): ?><li><a class="dropdown-item" href="<?= getUrl('expense_report') ?>"><i class="bi bi-cash-stack"></i> <?= t('Expense Report') ?></a></li><?php endif; ?>
                                <?php if(canView('pos') && canView('pos_user_sales_report')): ?><li><a class="dropdown-item" href="<?= getUrl('pos_user_sales_report') ?>"><i class="bi bi-person-badge"></i> <?= t('Sales by User Report') ?></a></li><?php endif; ?>
                                <?php if(canView('pos') && canView('pos_profit_report')): ?><li><a class="dropdown-item" href="<?= getUrl('pos_profit_report') ?>"><i class="bi bi-graph-up-arrow"></i> <?= t('Profit Report') ?></a></li><?php endif; ?>
                                <?php if(canView('product_analysis')): ?><li><a class="dropdown-item" href="<?= getUrl('product_analysis') ?>?view=profit"><i class="bi bi-trophy"></i> <?= t('Most Profitable Products') ?></a></li><?php endif; ?>
                                <?php if (!empty($__rest_on)): // restaurant_plan.md Phase 6 ?><li><a class="dropdown-item" href="<?= getUrl('restaurant/sales-report') ?>"><i class="bi bi-cup-hot"></i> <?= t('Restaurant Sales') ?></a></li><?php endif; ?>
                            </ul>
                            <?php else: ?>
                            <div class="dropdown-menu mega-dropdown-menu" aria-labelledby="reportsDropdown">
                                <div class="row">
                                    <div class="col-lg-3 mega-column">
                                        <h6><?= t('Financial Reports') ?></h6>
                                        <?php if(canView('income_statement')): ?><a class="dropdown-item" href="<?= getUrl('income_statement') ?>"><i class="bi bi-graph-up-arrow"></i> <?= t('Income Statement') ?></a><?php endif; ?>
                                        <?php if(canView('balance_sheet')): ?><a class="dropdown-item" href="<?= getUrl('balance_sheet') ?>"><i class="bi bi-bar-chart"></i> <?= t('Balance Sheet') ?></a><?php endif; ?>
                                        <?php if(canView('cash_flow')): ?><a class="dropdown-item" href="<?= getUrl('cash_flow') ?>"><i class="bi bi-arrow-left-right"></i> <?= t('Cash Flow') ?></a><?php endif; ?>
                                        <?php if(canView('financial_reports')): ?><a class="dropdown-item" href="<?= getUrl('consolidated_expenses') ?>"><i class="bi bi-cash-stack"></i> <?= t('Consolidated Expenses') ?></a><?php endif; ?>
                                        <?php if(canView('trial_balance')): ?><a class="dropdown-item" href="<?= getUrl('trial_balance') ?>"><i class="bi bi-journal"></i> <?= t('Trial Balance') ?></a><?php endif; ?>
                                        <?php if(canView('ledger_report')): ?><a class="dropdown-item" href="<?= getUrl('ledger_report') ?>"><i class="bi bi-journal-text"></i> <?= t('General Ledger') ?></a><?php endif; ?>
                                        <?php if(canView('financial_reports')): ?><a class="dropdown-item" href="<?= getUrl('ar_aging') ?>"><i class="bi bi-hourglass-split"></i> <?= t('Receivables Aging') ?></a><?php endif; ?>
                                        <?php if(canView('ap_aging')): ?><a class="dropdown-item" href="<?= getUrl('ap_aging') ?>"><i class="bi bi-hourglass-split"></i> <?= t('Payables Aging') ?></a><?php endif; ?>
                                        <?php if(canView('financial_reports')): ?><a class="dropdown-item" href="<?= getUrl('customer_statement') ?>"><i class="bi bi-file-earmark-text"></i> <?= t('Customer Statement') ?></a><?php endif; ?>
                                        <?php if(canView('vendor_statement')): ?><a class="dropdown-item" href="<?= getUrl('vendor_statement') ?>"><i class="bi bi-file-earmark-text"></i> <?= t('Vendor Statement') ?></a><?php endif; ?>
                                    </div>
                                    <div class="col-lg-3 mega-column">
                                        <h6><?= t('Business Reports') ?></h6>
                                        <?php if(canView('sales_report')): ?><a class="dropdown-item" href="<?= getUrl('sales_report') ?>"><i class="bi bi-cart"></i> <?= t('Sales Report') ?></a><?php endif; ?>
                                        <?php if(canView('service_sales_report')): ?><a class="dropdown-item" href="<?= getUrl('service_sales_report') ?>"><i class="bi bi-briefcase"></i> <?= t('Service Sales Report') ?></a><?php endif; ?>
                                        <?php if(canView('purchase_report')): ?><a class="dropdown-item" href="<?= getUrl('purchase_report') ?>"><i class="bi bi-basket"></i> <?= t('Purchase Report') ?></a><?php endif; ?>
                                        <?php if(canView('received_invoices')): ?><a class="dropdown-item" href="<?= getUrl('po_invoice_report') ?>"><i class="bi bi-clipboard-data"></i> <?= t('PO vs Invoice Report') ?></a><?php endif; ?>
                                        <?php if(canView('inventory_report')): ?><a class="dropdown-item" href="<?= getUrl('inventory_report') ?>"><i class="bi bi-boxes"></i> <?= t('Inventory Report') ?></a><?php endif; ?>


                                        <?php if(canView('expense_report')): ?><a class="dropdown-item" href="<?= getUrl('expense_report') ?>"><i class="bi bi-cash-stack"></i> <?= t('Expense Report') ?></a><?php endif; ?>
                                    </div>
                                    <div class="col-lg-3 mega-column">
                                        <h6><?= t('Analytics') ?></h6>
                                        <?php if(canView('performance_dashboard')): ?><a class="dropdown-item" href="<?= getUrl('performance_dashboard') ?>"><i class="bi bi-speedometer2"></i> <?= t('Performance') ?></a><?php endif; ?>
                                        <?php if(canView('customer_analysis')): ?><a class="dropdown-item" href="<?= getUrl('customer_analysis') ?>"><i class="bi bi-people"></i> <?= t('Customer Analysis') ?></a><?php endif; ?>
                                        <?php if(canView('product_analysis')): ?><a class="dropdown-item" href="<?= getUrl('product_analysis') ?>"><i class="bi bi-box"></i> <?= t('Product Analysis') ?></a><?php endif; ?>
                                        <?php if(canView('product_analysis')): ?><a class="dropdown-item" href="<?= getUrl('product_analysis') ?>?view=profit"><i class="bi bi-trophy"></i> <?= t('Most Profitable Products') ?></a><?php endif; ?>
                                        <?php if(canView('sales_forecast')): ?><a class="dropdown-item" href="<?= getUrl('sales_forecast') ?>"><i class="bi bi-graph-up-arrow"></i> <?= t('Sales Forecast') ?></a><?php endif; ?>
                                        <?php if(canView('trends_analysis')): ?><a class="dropdown-item" href="<?= getUrl('trends_analysis') ?>"><i class="bi bi-activity"></i> <?= t('Trends') ?></a><?php endif; ?>
                                    </div>
                                    <div class="col-lg-3 mega-column">
                                        <h6><?= t('Compliance & Operations') ?></h6>
                                        <?php if(canView('tax_report')): ?><a class="dropdown-item" href="<?= getUrl('tax_report') ?>"><i class="bi bi-percent"></i> <?= t('Tax Report') ?></a><?php endif; ?>
                                        <?php if(canView('wht_report')): ?><a class="dropdown-item" href="<?= getUrl('wht_report') ?>"><i class="bi bi-cash-stack"></i> <?= t('WHT Report') ?></a><?php endif; ?>
                                        <?php if(canView('tax_report')): ?><a class="dropdown-item" href="<?= getUrl('wht_receivable_report') ?>"><i class="bi bi-cash-coin"></i> <?= t('WHT Credit (Received)') ?></a><?php endif; ?>
                                        <?php if(canView('audit_logs')): ?><a class="dropdown-item" href="<?= getUrl('audit_logs') ?>"><i class="bi bi-clock-history"></i> <?= t('Audit Logs') ?></a><?php endif; ?>
                                        <?php if(canView('compliance_report')): ?><a class="dropdown-item" href="<?= getUrl('compliance_report') ?>"><i class="bi bi-file-check"></i> <?= t('Compliance') ?></a><?php endif; ?>
                                        <?php if(canView('employee_report')): ?><a class="dropdown-item" href="<?= getUrl('employee_report') ?>"><i class="bi bi-person-badge"></i> <?= t('Employee Report') ?></a><?php endif; ?>
                                        <?php if(canView('asset_report')): ?><a class="dropdown-item" href="<?= getUrl('asset_report') ?>"><i class="bi bi-pc-display"></i> <?= t('Asset Report') ?></a><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; // posSimpleModeEnabled() ?>
                        <?php bmsNavMenu(ob_get_clean(), 'reportsDropdown', '<i class="bi bi-graph-up"></i> ' . t('Reports'),
                            hasReportsAccess(), posSimpleModeEnabled() ? 'nav-item' : 'nav-item mega-dropdown'); ?>

                        <!-- Settings — each item gated individually. Everything strictly
                             admin-only (Users, Roles & Permissions, Payments, Backup, Login
                             History, Zoom Integration, Company Profile, Notification Rules,
                             Project Assignments, AI Assistant) now lives ONLY inside the "Admin"
                             page (system_settings.php) itself — not as separate top-nav items —
                             so a non-admin never even sees them as menu entries; the Admin link
                             below is the single entry point, itself isAdmin()-gated. Everything
                             else here is gated by canView('page_key'), which admins always pass
                             too, so a non-admin granted that specific permission sees and can
                             reach it. -->
                        <?php
                        // Color Setting only styles sales/purchase documents.
                        $_set_color_visible = canView('color_settings') && (tenantFeatureEnabled('sales') || tenantFeatureEnabled('procurement'));
                        $_set_sys_visible = isAdmin() || canView('pos_config_settings') || $_set_color_visible;
                        // notification_settings dropped 2026-07-31: folded into notification_rules.php
                        // (Settings > Admin > Notification Rules) as an admin-only collapsible panel,
                        // no longer delegable via Roles & Permissions.
                        // Tax (tax_settings) removed from the menu 2026-10-02: no code reads its
                        // values — VAT comes from each product's tax rate, TIN from company_tin.
                        // Billing (service_plans_plan.md D10): top level, before Settings, company admins
                        // on their own subdomain only — never on the platform root.
                        $_billing_visible = isAdmin() && function_exists('bmsCurrentTenantId') && bmsCurrentTenantId() !== null;
                        ?>
                        <?php if ($_billing_visible): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= getUrl('subscription') ?>"><i class="bi bi-credit-card-2-front"></i> <?= t('Subscription') ?>
                                <span class="badge rounded-pill d-none bms-billing-badge" style="background:#0d6efd;color:#fff">0</span></a>
                        </li>
                        <?php endif; ?>
                        <?php if ($_set_sys_visible): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-sliders"></i> <?= t('Settings') ?>
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="adminDropdown">
                                <?php if ($_set_sys_visible): ?>
                                <li><h6 class="dropdown-header"><?= t('System Configuration') ?></h6></li>
                                <?php if (isAdmin()): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('system_settings') ?>"><i class="bi bi-gear"></i> <?= t('Admin') ?></a></li>
                                <?php endif; ?>
                                <?php if (canView('pos_config_settings')): ?><li><a class="dropdown-item" href="<?= getUrl('pos_config_settings') ?>"><i class="bi bi-cart"></i> <?= wLabel('POS Settings', 'Selling Settings') ?></a></li><?php endif; ?>
                                <?php if ($_set_color_visible): ?><li><a class="dropdown-item" href="<?= getUrl('color_settings') ?>"><i class="bi bi-palette"></i> <?= t('Color Setting') ?></a></li><?php endif; ?>
                                <?php endif; ?>
                            </ul>
                        </li>
                        <?php endif; ?>
                    </ul>

                    <!-- User Account (right side of bottom nav — matches Vikundi) -->
                    <ul class="navbar-nav align-items-center flex-row">
                        <li class="nav-item me-1 d-none d-lg-flex align-items-center"><?php helpPanelButton('desktop'); ?></li>
                        <!-- Language toggle pill — switches between EN and SW instantly -->
                        <?php if ($__bms_org_lang === null): ?>
                        <li class="nav-item me-1">
                            <button class="btn btn-sm btn-outline-light" onclick="bmsToggleLang()" title="<?= $__bms_lang_pref === 'sw' ? 'Switch to English' : 'Badilisha lugha kuwa Kiswahili' ?>" style="font-size:0.75rem;padding:2px 10px;border-radius:20px;font-weight:700;letter-spacing:0.5px;line-height:1.6;">
                                <i class="bi bi-globe2 me-1"></i><?= strtoupper($__bms_lang_pref) ?>
                            </button>
                        </li>
                        <?php endif; ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle py-2 px-3 d-flex align-items-center fw-bold" href="#" id="userDrop" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-person-circle fs-5 me-2"></i>
                                <div class="d-none d-xl-block">
                                    <span class="d-block" style="font-size:0.85rem;line-height:1;"><?= htmlspecialchars($username) ?></span>
                                    <span class="text-white-50" style="font-size:10px;text-transform:uppercase;"><?= htmlspecialchars(t($user_role)) ?></span>
                                </div>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-0" aria-labelledby="userDrop">
                                <li class="px-3 py-2 border-bottom">
                                    <div class="fw-bold" style="font-size:0.85rem;"><?= htmlspecialchars($username) ?></div>
                                    <div class="text-muted" style="font-size:0.72rem;text-transform:uppercase;"><?= htmlspecialchars(t($user_role)) ?></div>
                                </li>
                                <?php if (!empty($_SESSION['employee_id'])): // ESS "My HR" — only for users linked to an employee (D24) ?>
                                <li><a class="dropdown-item py-2" href="<?= getUrl('my_hr') ?>"><i class="bi bi-person-workspace me-2"></i> <?= t('My HR') ?></a></li>
                                <?php endif; ?>
                                <li><a class="dropdown-item py-2" href="<?= getUrl('my_settings') ?>"><i class="bi bi-person-gear me-2"></i> <?= t('My Profile & Settings') ?></a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item py-2" href="<?= getUrl('help') ?>"><i class="bi bi-question-circle me-2"></i> <?= t('Help') ?></a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item py-2 text-danger fw-bold" href="<?= getUrl('logout') ?>"><i class="bi bi-box-arrow-right me-2"></i> <?= t('Logout') ?></a></li>
                            </ul>
                        </li>
                    </ul>
                </div><!-- /.collapse -->
                </div><!-- /.header-nav-bar -->
            </div><!-- /.container-fluid -->
        </nav><!-- /.bottom-header -->

    </div><!-- /.header-wrapper -->
    <?php helpPanelRender(); ?>
    <?php require_once __DIR__ . '/includes/ask_widget.php'; askWidgetRender(); ?>
    <?php require_once __DIR__ . '/core/support_notices.php'; echo supportNoticeBanner(); ?>

    <?php if (posSimpleModeEnabled()): ?>
    <?php
    // ── Simple POS · phone bottom navigation bar ───────────────────────────
    // On phones the header menu sits behind the ☰ button, so every action
    // costs 2–3 taps. For a Simple POS tenant only, the everyday destinations
    // are shown directly in a bar fixed to the bottom: POS, Products,
    // Expenses, Reports (opens a short list) and More (the rest of the menu).
    // Desktop (>= 992px) is untouched: the bar is d-lg-none and the CSS below
    // is scoped to max-width 991.98px. Same canView()/getUrl() gates as the
    // header items above — this only changes where they are reachable from.
    $__bn_dark = (($_SESSION['theme'] ?? 'light') === 'dark');
    $__bn_cur  = rtrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    $__bn_on   = function (string $page) use ($__bn_cur): bool {
        $p = rtrim((string)parse_url(getUrl($page), PHP_URL_PATH), '/');
        return $p !== '' && ($__bn_cur === $p || str_starts_with($__bn_cur, $p . '/'));
    };
    $__bn_report_keys = ['sales_report', 'purchase_report', 'inventory_report', 'expense_report', 'pos_user_sales_report', 'pos_profit_report'];
    $__bn_reports_on  = false;
    foreach ($__bn_report_keys as $__k) { if ($__bn_on($__k)) { $__bn_reports_on = true; break; } }
    ?>
    <style>
        .bms-bnav { position: fixed; left: 0; right: 0; bottom: 0; z-index: 1030;
            background: <?= $__bn_dark ? '#24282d' : '#ffffff' ?>;
            border-top: 1px solid <?= $__bn_dark ? '#3a3f45' : '#dee2e6' ?>;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.10);
            padding: 6px 4px calc(6px + env(safe-area-inset-bottom, 0px)); }
        .bms-bnav .bn-item { flex: 1 1 0; min-width: 0; display: flex; flex-direction: column; align-items: center; gap: 2px;
            padding: 4px 1px; background: none; border: 0; text-decoration: none;
            color: <?= $__bn_dark ? '#aab3bd' : '#6c757d' ?>; font-size: clamp(0.54rem, 2.1vw, 0.68rem); font-weight: 500; line-height: 1.15; }
        .bms-bnav .bn-item i { font-size: clamp(1.05rem, 3.8vw, 1.3rem); line-height: 1; }
        .bms-bnav .bn-item span { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .bms-bnav .bn-item.active { color: #0d6efd; font-weight: 700; }
        /* Add Product window (Receive Stock / New Product / View Products): centred,
           page dimmed behind it. Opened by the dashboard tile and the Products tab. */
        #bmsAddProductModal .modal-dialog { max-width: 400px; }
        #bmsAddProductModal .modal-content { border: 0; border-radius: 16px; overflow: hidden;
            <?= $__bn_dark ? 'background:#2b3035;color:#e1e7ec;' : '' ?> }
        #bmsAddProductModal .apm-item { display: flex; align-items: center; gap: 14px; padding: 12px 10px; border-radius: 12px; text-decoration: none;
            color: <?= $__bn_dark ? '#e1e7ec' : '#212529' ?>; }
        #bmsAddProductModal .apm-item:hover, #bmsAddProductModal .apm-item:focus-visible { background: <?= $__bn_dark ? '#343a40' : '#f4f6f9' ?>; }
        #bmsAddProductModal .apm-item strong { display: block; font-size: 1rem; }
        #bmsAddProductModal .apm-item small { display: block; color: <?= $__bn_dark ? '#aab3bd' : '#6c757d' ?>; line-height: 1.35; }
        #bmsAddProductModal .apm-ico { flex-shrink: 0; width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; }
        #bmsAddProductModal .apm-new { background: #fff4d6; color: #8a5a00; }
        #bmsAddProductModal .apm-list { background: <?= $__bn_dark ? '#3a3f45' : '#f1f3f5' ?>; color: <?= $__bn_dark ? '#e1e7ec' : '#495057' ?>; }
        #bmsAddProductModal .apm-head { display: flex; justify-content: flex-end; padding: 10px 12px 0; }
        #bmsAddProductModal .apm-head .btn-close { <?= $__bn_dark ? 'filter: invert(1);' : '' ?> }
        .bms-sheet { height: auto !important; max-height: 80vh; border-radius: 16px 16px 0 0;
            <?= $__bn_dark ? 'background:#24282d;color:#e1e7ec;' : '' ?> }
        .bms-sheet .list-group-item { display: flex; align-items: center; gap: 10px; padding: 0.8rem 0.25rem; font-size: 0.95rem;
            <?= $__bn_dark ? 'background:transparent;color:#e1e7ec;border-color:#3a3f45;' : 'background:transparent;' ?> }
        .bms-sheet .list-group-item i { width: 1.4rem; text-align: center; color: #0d6efd; }
        .bms-sheet .bn-group { font-size: 0.7rem; letter-spacing: 0.06em; text-transform: uppercase; color: #6c757d; padding: 0.9rem 0.25rem 0.2rem; }
        .bms-sheet .btn-close { <?= $__bn_dark ? 'filter: invert(1);' : '' ?> }
        /* Centre "Sell" button: raised blue circle, the main action of a shop. */
        .bms-bnav .bn-item.bn-sell { position: relative; top: -14px; color: #0d6efd; font-weight: 700; }
        .bms-bnav .bn-sell .bn-circle { width: 54px; height: 54px; border-radius: 50%; background: #0d6efd; color: #fff;
            display: flex; align-items: center; justify-content: center; margin-bottom: 1px;
            border: 4px solid <?= $__bn_dark ? '#24282d' : '#ffffff' ?>; box-shadow: 0 6px 14px rgba(13,110,253,0.38); }
        .bms-bnav .bn-sell .bn-circle i { font-size: 1.45rem; color: #fff; }
        .bms-bnav .bn-item.bn-sell.active .bn-circle { background: #0b5ed7; }
        /* The bar is a fixed layer of its own so scrolling/overscroll never drags it. */
        .bms-bnav { transform: translateZ(0); will-change: transform; }
        /* Left side menu (☰) — style "A": slides over the page from the left. */
        .bms-side { width: min(82vw, 330px) !important; border: 0 !important; box-shadow: 4px 0 18px rgba(0,0,0,0.22);
            <?= $__bn_dark ? 'background:#24282d;color:#e1e7ec;' : '' ?> }
        .bms-side .bms-side-head { background: #0b4fc4; color: #fff; padding: 18px 16px 14px; position: relative; }
        .bms-side .bms-side-avatar { width: 46px; height: 46px; border-radius: 50%; background: rgba(255,255,255,0.18);
            display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1rem; margin-bottom: 8px; }
        .bms-side .bms-side-name { font-weight: 700; font-size: 1rem; line-height: 1.25; padding-right: 40px; word-break: break-word; }
        .bms-side .bms-side-role { font-size: 0.8rem; opacity: 0.85; }
        .bms-side .bms-side-close { position: absolute; top: 12px; right: 12px; width: 36px; height: 36px; border-radius: 50%;
            border: 0; background: rgba(255,255,255,0.18); color: #fff; display: flex; align-items: center; justify-content: center; }
        .bms-side .bms-side-close i { font-size: 1.1rem; }
        .bms-side .offcanvas-body { padding: 4px 0 calc(16px + env(safe-area-inset-bottom, 0px)); }
        .bms-side .bn-group { font-size: 0.7rem; letter-spacing: 0.06em; text-transform: uppercase; color: #6c757d; padding: 14px 18px 4px; }
        .bms-side .list-group-item { display: flex; align-items: center; gap: 12px; padding: 0.7rem 18px; font-size: 0.95rem; border: 0;
            <?= $__bn_dark ? 'background:transparent;color:#e1e7ec;' : 'background:transparent;' ?> }
        .bms-side .list-group-item i { width: 1.4rem; text-align: center; color: #0d6efd; font-size: 1.1rem; }
        .bms-side .bms-side-logout { border-top: 1px solid <?= $__bn_dark ? '#3a3f45' : '#dee2e6' ?> !important; margin-top: 8px; }
        .bms-side.offcanvas.bms-dragging { transition: none !important; }
        @media (max-width: 991.98px) {
            body { padding-bottom: calc(68px + env(safe-area-inset-bottom, 0px)) !important; }
            html, body { overscroll-behavior-y: none; }
            /* Hamburger and full nav area hidden on mobile; bottom bar + Language/☰ toolbar handle navigation. */
            .bottom-header .navbar-toggler { display: none !important; }
            .bottom-header .header-nav-bar { display: none !important; }
            body.bms-kb-open .bms-bnav { display: none !important; }
        }
    </style>

    <nav class="bms-bnav d-flex d-lg-none d-print-none" aria-label="<?= htmlspecialchars(t('Main Menu')) ?>">
        <?php // Five items, "Sell" raised in the middle; everything else lives in the ☰ side menu. ?>
        <?php if (canView('dashboard')): ?>
        <a class="bn-item<?= $__bn_on('dashboard') ? ' active' : '' ?>" href="<?= getUrl('dashboard') ?>"><i class="bi bi-speedometer2"></i><span><?= t('Dashboard') ?></span></a>
        <?php endif; ?>
        <?php
        // Products opens the centred Add Product window (below the bar): Receive
        // Stock / New Product / View Products. Same gates as the dashboard's Add
        // Product tile; with neither extra permission it stays a plain link.
        $__bn_new_product   = canCreate('products');
        $__bn_receive_stock = canView('pos') && canView('pos_restock');
        ?>
        <?php
        // restaurant_plan.md Phase 1 — a restaurant-only company sells from its Menu,
        // so this tab opens the Menu instead. Mixed/retail companies: unchanged.
        require_once __DIR__ . '/core/restaurant_scope.php';
        $__bn_rest_only = canView('restaurant_pos') && restaurantOnlyCompany($pdo);
        ?>
        <?php if ($__bn_rest_only): ?>
        <a class="bn-item<?= $__bn_on('restaurant/menu') ? ' active' : '' ?>" href="<?= getUrl('restaurant/menu') ?>"><i class="bi bi-journal-richtext"></i><span><?= t('Menu') ?></span></a>
        <?php elseif (canView('products') && ($__bn_new_product || $__bn_receive_stock)): ?>
        <button type="button" class="bn-item<?= $__bn_on('products') ? ' active' : '' ?>" data-bs-toggle="modal" data-bs-target="#bmsAddProductModal" data-apm-from="tab"><i class="bi bi-box"></i><span><?= t('Products') ?></span></button>
        <?php elseif (canView('products')): ?>
        <a class="bn-item<?= $__bn_on('products') ? ' active' : '' ?>" href="<?= getUrl('products') ?>"><i class="bi bi-box"></i><span><?= t('Products') ?></span></a>
        <?php endif; ?>
        <?php if (canView('pos')): ?>
        <a class="bn-item bn-sell<?= $__bn_on('pos') ? ' active' : '' ?>" href="<?= getUrl('pos') ?>"><span class="bn-circle"><i class="bi bi-cart-check"></i></span><span><?= htmlspecialchars(wLabel('POS', 'Sell')) ?></span></a>
        <?php endif; ?>
        <?php if (canView('expenses')): ?>
        <a class="bn-item<?= $__bn_on('expenses') ? ' active' : '' ?>" href="<?= getUrl('expenses') ?>"><i class="bi bi-receipt"></i><span><?= t('Expenses') ?></span></a>
        <?php endif; ?>
        <?php if (hasReportsAccess()): ?>
        <button type="button" class="bn-item<?= $__bn_reports_on ? ' active' : '' ?>" data-bs-toggle="offcanvas" data-bs-target="#bmsReportsSheet" aria-controls="bmsReportsSheet"><i class="bi bi-graph-up"></i><span><?= t('Reports') ?></span></button>
        <?php endif; ?>
    </nav>

    <?php if ($__bn_new_product || $__bn_receive_stock): ?>
    <!-- Add Product window — Smart POS only (this whole block is posSimpleModeEnabled()).
         Opened by the Products tab above and the dashboard's Add Product tile;
         View Products shows only when opened from the tab (data-apm-from="tab"). -->
    <div class="modal fade d-print-none" id="bmsAddProductModal" tabindex="-1" data-bs-backdrop="static" aria-label="<?= htmlspecialchars(t('Add Product')) ?>" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg">
                <div class="apm-head">
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(t('Close')) ?>"></button>
                </div>
                <div class="modal-body p-2 pt-0">
                    <?php if ($__bn_receive_stock): ?>
                    <a class="apm-item" href="<?= getUrl('receive_stock') ?>">
                        <span class="apm-ico bg-primary text-white"><i class="bi bi-box-arrow-in-down"></i></span>
                        <span><strong><?= t('Receive Stock') ?></strong><small><?= t('Add stock to a product you already have') ?></small></span>
                    </a>
                    <?php endif; ?>
                    <?php if ($__bn_new_product): ?>
                    <a class="apm-item" href="<?= getUrl('product_create') ?>">
                        <span class="apm-ico apm-new"><i class="bi bi-plus-square"></i></span>
                        <span><strong><?= t('New Product') ?></strong><small><?= t("Create a product you don't have yet") ?></small></span>
                    </a>
                    <?php endif; ?>
                    <?php if (canView('products')): ?>
                    <a class="apm-item apm-from-tab" href="<?= getUrl('products') ?>">
                        <span class="apm-ico apm-list"><i class="bi bi-list-ul"></i></span>
                        <span><strong><?= t('View Products') ?></strong></span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.getElementById('bmsAddProductModal').addEventListener('show.bs.modal', function (e) {
        var fromTab = !!(e.relatedTarget && e.relatedTarget.getAttribute('data-apm-from') === 'tab');
        this.querySelectorAll('.apm-from-tab').forEach(function (el) { el.hidden = !fromTab; });
    });
    </script>
    <?php endif; ?>

    <?php if (hasReportsAccess()): ?>
    <!-- Reports list — same six reports and gates as the Simple Mode Reports dropdown -->
    <div class="offcanvas offcanvas-bottom bms-sheet d-lg-none d-print-none" tabindex="-1" id="bmsReportsSheet" aria-labelledby="bmsReportsSheetLabel">
        <div class="offcanvas-header pb-0">
            <h6 class="offcanvas-title fw-bold" id="bmsReportsSheetLabel"><?= t('Business Reports') ?></h6>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body pt-1">
            <div class="list-group list-group-flush">
                <?php if(canView('sales_report')): ?><a class="list-group-item list-group-item-action" href="<?= getUrl('sales_report') ?>"><i class="bi bi-cart"></i><?= t('Sales Report') ?></a><?php endif; ?>
                <?php if(canView('purchase_report')): ?><a class="list-group-item list-group-item-action" href="<?= getUrl('purchase_report') ?>"><i class="bi bi-basket"></i><?= t('Purchase Report') ?></a><?php endif; ?>
                <?php if(canView('inventory_report')): ?><a class="list-group-item list-group-item-action" href="<?= getUrl('inventory_report') ?>"><i class="bi bi-boxes"></i><?= t('Inventory Report') ?></a><?php endif; ?>
                <?php if(canView('expense_report')): ?><a class="list-group-item list-group-item-action" href="<?= getUrl('expense_report') ?>"><i class="bi bi-cash-stack"></i><?= t('Expense Report') ?></a><?php endif; ?>
                <?php if(canView('pos') && canView('pos_user_sales_report')): ?><a class="list-group-item list-group-item-action" href="<?= getUrl('pos_user_sales_report') ?>"><i class="bi bi-person-badge"></i><?= t('Sales by User Report') ?></a><?php endif; ?>
                <?php if(canView('pos') && canView('pos_profit_report')): ?><a class="list-group-item list-group-item-action" href="<?= getUrl('pos_profit_report') ?>"><i class="bi bi-graph-up-arrow"></i><?= t('Profit Report') ?></a><?php endif; ?>
                <?php if (!empty($__rest_on)): // restaurant_plan.md Phase 6 ?><a class="list-group-item list-group-item-action" href="<?= getUrl('restaurant/sales-report') ?>"><i class="bi bi-cup-hot"></i><?= t('Restaurant Sales') ?></a><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php
    $__side_name = trim((string)$username) !== '' ? (string)$username : t('User');
    $__side_initials = '';
    foreach (preg_split('/\s+/', trim($__side_name)) as $__w) { if ($__w !== '' && mb_strlen($__side_initials) < 2) $__side_initials .= mb_strtoupper(mb_substr($__w, 0, 1)); }
    ?>
    <!-- ☰ Side menu (phones only) — slides over the page from the left. Holds what the old
         "More" and account sheets had; same canView() gates. Closes with ✕, a tap outside,
         a swipe to the left, the phone's Back button, or by choosing an item. -->
    <div class="offcanvas offcanvas-start bms-side d-lg-none d-print-none" tabindex="-1" id="bmsSideMenu" aria-labelledby="bmsSideMenuLabel">
        <div class="bms-side-head">
            <div class="bms-side-avatar" aria-hidden="true"><?= htmlspecialchars($__side_initials) ?></div>
            <div class="bms-side-name" id="bmsSideMenuLabel"><?= htmlspecialchars($__side_name) ?></div>
            <div class="bms-side-role"><?= htmlspecialchars(t($user_role)) ?> · <?= htmlspecialchars((string)$company_name) ?></div>
            <button type="button" class="bms-side-close" data-bs-dismiss="offcanvas" aria-label="<?= htmlspecialchars(t('Close menu')) ?>"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="offcanvas-body">
            <?php
            // ☰ side menu groups (phones). Grouped by what each item IS, not by where it was added:
            //   Sales        = selling-side pages (shift history, who owes me, catalog setup)
            //   Shop         = the places and the stock (shops/warehouses, receive stock, services)
            //   My Business  = the people (customers, suppliers, customer visits)
            //   Restaurant   = restaurant pages (own group, only when the module is allowed)
            // Same canView() gates as before; "Who Owes Me" / "Catalog Setup" still come from
            // core/pos_nav.php::posNavGroups() so they match the Sales Overview hub cards.
            $__side_nav_icons = ['credit_customers' => 'bi-cash-coin', 'catalog_setup' => 'bi-tags'];
            $__side_sales = [];
            if (canView('pos')) $__side_sales[] = [getUrl('pos/shifts'), 'bi-clock-history', t('Shift History')];
            foreach (posNavGroups() as $__sg) {
                if (isset($__side_nav_icons[$__sg['key']])) $__side_sales[] = [getUrl($__sg['url']), $__side_nav_icons[$__sg['key']], $__sg['label']];
            }
            $__side_shop = [];
            if (canView('warehouses'))                    $__side_shop[] = [getUrl('warehouses'), 'bi-shop', wLabel('Warehouses', 'Shops')];
            if (canView('pos') && canView('pos_restock')) $__side_shop[] = [$__restock_url ?? getUrl('receive_stock'), 'bi-box-arrow-in-down', t('Receive Stock')];
            if (canView('products'))                      $__side_shop[] = [getUrl('services'), 'bi-box-seam', t('Service')];
            if (canView('categories'))                    $__side_shop[] = [getUrl('categories'), 'bi-tags', t('Categories')];
            $__side_people = [];
            if (canView('customers'))    $__side_people[] = [getUrl('customers'), 'bi-people', t('Customers')];
            if (canView('suppliers'))    $__side_people[] = [getUrl('suppliers'), 'bi-truck', t('Suppliers')];
            if (canView('field_visits')) $__side_people[] = [getUrl('field_reports'), 'bi-geo-alt', t('Customer Visits')];
            $__side_rest = [];
            if (canView('restaurant_pos')) {
                if (!empty($__rest_on)) {
                    $__side_rest[] = [getUrl('restaurant/menu'), 'bi-journal-richtext', t('Menu')];
                    $__side_rest[] = [getUrl('restaurant/tables-board'), 'bi-grid-3x3-gap', t('Tables')];
                }
                $__side_rest[] = [getUrl('restaurant'), 'bi-cup-hot', t('Restaurant')];
            }
            $__side_groups = [
                [t('Sales'),                   $__side_sales],
                [wLabel('Inventory', 'Shop'),  $__side_shop],
                [t('My Business'),             $__side_people],
                [t('Restaurant'),              $__side_rest],
            ];
            foreach ($__side_groups as [$__side_gl, $__side_items]):
                if (!$__side_items) continue; ?>
            <div class="bn-group"><?= htmlspecialchars($__side_gl) ?></div>
            <div class="list-group list-group-flush">
                <?php foreach ($__side_items as [$__side_u, $__side_i, $__side_l]): ?>
                <a class="list-group-item list-group-item-action" href="<?= $__side_u ?>"><i class="bi <?= htmlspecialchars(preg_replace('/^bi /', '', $__side_i)) ?>"></i><?= htmlspecialchars($__side_l) ?></a>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
            <?php if ($_set_sys_visible): ?>
            <div class="bn-group"><?= t('Settings') ?></div>
            <div class="list-group list-group-flush">
                <?php if (isAdmin()): ?><a class="list-group-item list-group-item-action" href="<?= getUrl('system_settings') ?>"><i class="bi bi-gear"></i><?= t('Admin') ?></a><?php endif; ?>
                <?php if (canView('pos_config_settings')): ?><a class="list-group-item list-group-item-action" href="<?= getUrl('pos_config_settings') ?>"><i class="bi bi-cart"></i><?= wLabel('POS Settings', 'Selling Settings') ?></a><?php endif; ?>
                <?php if ($_set_color_visible): ?><a class="list-group-item list-group-item-action" href="<?= getUrl('color_settings') ?>"><i class="bi bi-palette"></i><?= t('Color Setting') ?></a><?php endif; ?>            </div>
            <?php endif; ?>
            <div class="bn-group"><?= t('My Account') ?></div>
            <div class="list-group list-group-flush">
                <?php if (!empty($_billing_visible)): ?>
                <a class="list-group-item list-group-item-action" href="<?= getUrl('subscription') ?>"><i class="bi bi-credit-card-2-front"></i><?= t('Subscription') ?>
                    <span class="badge rounded-pill d-none bms-billing-badge ms-1" style="background:#0d6efd;color:#fff">0</span></a>
                <?php endif; ?>
                <?php if (!empty($_SESSION['employee_id'])): ?><a class="list-group-item list-group-item-action" href="<?= getUrl('my_hr') ?>"><i class="bi bi-person-workspace"></i><?= t('My HR') ?></a><?php endif; ?>
                <a class="list-group-item list-group-item-action" href="<?= getUrl('my_settings') ?>"><i class="bi bi-person-gear"></i><?= t('My Profile & Settings') ?></a>
                <a class="list-group-item list-group-item-action" href="<?= getUrl('help') ?>"><i class="bi bi-question-circle"></i><?= t('Help') ?></a>
                <a class="list-group-item list-group-item-action text-danger fw-bold bms-side-logout" href="<?= getUrl('logout') ?>"><i class="bi bi-box-arrow-right text-danger"></i><?= t('Logout') ?></a>
            </div>
        </div>
    </div>

    <script>
    (function () {
        document.addEventListener('DOMContentLoaded', function () {
            var menu = document.getElementById('navbarNav');
            if (menu) {
                menu.addEventListener('show.bs.collapse', function () { document.body.classList.add('bms-menu-open'); });
                menu.addEventListener('hidden.bs.collapse', function () { document.body.classList.remove('bms-menu-open'); });
            }
            var side = document.getElementById('bmsSideMenu');
            if (!side || !window.bootstrap) return;
            var oc = bootstrap.Offcanvas.getOrCreateInstance(side);
            var pushed = false, leaving = false;
            // Phone Back button closes the menu instead of leaving the page.
            side.addEventListener('shown.bs.offcanvas', function () {
                if (window.history && history.pushState) { history.pushState({ bmsSideMenu: true }, ''); pushed = true; }
            });
            side.addEventListener('hidden.bs.offcanvas', function () {
                side.style.transform = '';
                if (pushed && !leaving) { pushed = false; history.back(); }
            });
            window.addEventListener('popstate', function () {
                if (pushed) { pushed = false; oc.hide(); }
            });
            // Choosing an item: let the link navigate, the menu goes with the page.
            side.querySelectorAll('a[href]').forEach(function (a) {
                a.addEventListener('click', function () { leaving = true; });
            });
            // Swipe left to close.
            var x0 = null, dx = 0;
            side.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; dx = 0; }, { passive: true });
            side.addEventListener('touchmove', function (e) {
                if (x0 === null) return;
                dx = Math.min(0, e.touches[0].clientX - x0);
                if (dx < -6) { side.classList.add('bms-dragging'); side.style.transform = 'translateX(' + dx + 'px)'; }
            }, { passive: true });
            side.addEventListener('touchend', function () {
                if (x0 === null) return;
                side.classList.remove('bms-dragging');
                if (dx < -70) { oc.hide(); } else { side.style.transform = ''; }
                x0 = null;
            });
        });
        // Hide the bar only while the on-screen keyboard is really open (a text field has
        // focus AND the visible area shrank a lot) — the browser's address bar sliding in
        // and out while scrolling must not make the bar jump.
        if (window.visualViewport) {
            var maxH = window.innerHeight;
            var typing = function () {
                var el = document.activeElement;
                if (!el) return false;
                if (el.isContentEditable) return true;
                if (el.tagName === 'TEXTAREA') return true;
                if (el.tagName !== 'INPUT') return false;
                if ((el.getAttribute('inputmode') || '') === 'none') return false;
                return !/^(button|checkbox|radio|range|color|file|submit|reset|image|hidden)$/i.test(el.type || 'text');
            };
            var check = function () {
                maxH = Math.max(maxH, window.innerHeight);
                document.body.classList.toggle('bms-kb-open', typing() && window.visualViewport.height < maxH * 0.75);
            };
            window.visualViewport.addEventListener('resize', check);
            document.addEventListener('focusout', function () { setTimeout(check, 150); });
            window.addEventListener('orientationchange', function () { maxH = 0; setTimeout(function () { maxH = window.innerHeight; check(); }, 300); });
        }
    })();
    </script>
    <?php endif; // posSimpleModeEnabled() bottom bar ?>

    <?php if (!empty($_billing_visible)): ?>
    <script>
    // Billing badge: new quotations / invoices from BJP — asked once the page has loaded (cached 10 min server-side).
    window.addEventListener('load', function () {
        if (!window.fetch) return;
        fetch('<?= buildUrl('api/my_subscription.php') ?>?action=badge', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (r) {
                var n = r && r.success ? parseInt(r.new, 10) || 0 : 0;
                document.querySelectorAll('.bms-billing-badge').forEach(function (b) { b.textContent = n; b.classList.toggle('d-none', n < 1); });
            }).catch(function () {});
    });
    </script>
    <?php endif; ?>

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

    <!-- Grace-period warning banner — shown when the tenant is past expiry but still within the grace window -->
    <?php if (!empty($_SESSION['_bms_grace_warning'])): ?>
    <?php $__gw = $_SESSION['_bms_grace_warning']; ?>
    <div id="bmsGraceBanner" class="alert alert-warning alert-dismissible fade show mb-0 rounded-0 border-0 border-bottom border-warning" role="alert" style="font-size:.88rem">
        <div class="container-fluid d-flex align-items-center gap-2 px-4">
            <?php if (($__gw['type'] ?? '') === 'trial'): ?>
                <i class="bi bi-hourglass-split flex-shrink-0"></i>
                <span>
                    <strong>Your free trial has ended.</strong>
                    <?php if (($__gw['days_left'] ?? 0) > 0): ?>
                        You have <strong><?= (int)$__gw['days_left'] ?> day<?= $__gw['days_left'] == 1 ? '' : 's' ?></strong> remaining in your grace period.
                    <?php else: ?>
                        Your grace period expires today.
                    <?php endif; ?>
                    Please contact your service provider to continue.
                </span>
            <?php else: ?>
                <i class="bi bi-credit-card flex-shrink-0"></i>
                <span>
                    <strong>Your subscription has expired.</strong>
                    <?php if (($__gw['days_left'] ?? 0) > 0): ?>
                        You have <strong><?= (int)$__gw['days_left'] ?> day<?= $__gw['days_left'] == 1 ? '' : 's' ?></strong> remaining in your grace period.
                    <?php else: ?>
                        Your grace period expires today.
                    <?php endif; ?>
                    Please contact your service provider to renew.
                </span>
            <?php endif; ?>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Advance warning — shown in the last week BEFORE expiry, so the first
         thing a customer hears about it is not "your trial has ended". Set by
         core/tenant_bootstrap.php; never shown at the same time as the grace
         banner above, because by then it is no longer advance notice. -->
    <?php if (empty($_SESSION['_bms_grace_warning']) && !empty($_SESSION['_bms_expiry_warning'])): ?>
    <?php
    $__ew   = $_SESSION['_bms_expiry_warning'];
    $__days = (int)($__ew['days_left'] ?? 0);
    $__when = $__days === 0 ? 'today' : ($__days === 1 ? 'tomorrow' : 'in ' . $__days . ' days');
    ?>
    <div id="bmsExpiryBanner" class="alert alert-info alert-dismissible fade show mb-0 rounded-0 border-0 border-bottom" role="alert" style="font-size:.88rem">
        <div class="container-fluid d-flex align-items-center gap-2 px-4">
            <i class="bi bi-<?= ($__ew['type'] ?? '') === 'trial' ? 'hourglass-split' : 'calendar-event' ?> flex-shrink-0"></i>
            <span>
                <?php if (($__ew['type'] ?? '') === 'trial'): ?>
                    <strong>Your free trial ends <?= $__when ?>.</strong>
                <?php else: ?>
                    <strong>Your subscription ends <?= $__when ?>.</strong>
                <?php endif; ?>
                <?php if (!empty($__ew['ends_at'])): ?>
                    <span class="text-muted">(<?= date('d M Y', strtotime((string)$__ew['ends_at'])) ?>)</span>
                <?php endif; ?>
                Contact your service provider to continue without interruption.
            </span>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    </div>
    <?php endif; ?>

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