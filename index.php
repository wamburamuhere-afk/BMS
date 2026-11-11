<?php
/**
 * Main Entry Point
 * Handles routing and authentication
 */

// Include routing and core functionality
require_once __DIR__ . '/roots.php';

// Check if this is a direct access to index.php (root URL)
$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
$clean_uri = trim(strtok($request_uri, '?'), '/');

// A company's own address and the Superadmin address are never for search engines (2026-11-11).
// Uses the company this request already resolved — no extra query.
require_once __DIR__ . '/core/superadmin_auth.php';
if (!headers_sent() && ((function_exists('bmsCurrentTenant') && bmsCurrentTenant() !== null) || isSuperadminHost())) {
    header('X-Robots-Tag: noindex, nofollow');
}

// If accessing root URL directly
if (empty($clean_uri) || $clean_uri === 'index.php') {
    // The superadmin host's root ('/') is the platform panel's own front door
    // (core/superadmin_auth.php maps '' to app/superadmin/index.php) and must
    // go through handleRoute(), not the tenant-only check below — a superadmin
    // session carries $_SESSION['superadmin_id'], never $_SESSION['user_id'],
    // so the tenant check always fell through to redirectTo('login'), even
    // for an already-signed-in operator. login.php's own check then saw
    // isSuperadminLoggedIn() === true and sent them straight back to '/',
    // an infinite loop the browser reported as ERR_TOO_MANY_REDIRECTS. Every
    // other superadmin path (tenants, dashboard, ...) never hit this because
    // $clean_uri is non-empty for them, which already took the handleRoute()
    // branch below.
    require_once __DIR__ . '/core/superadmin_auth.php';
    if (isSuperadminHost()) {
        $handled = handleRoute();
        if (!$handled) {
            http_response_code(404);
            echo "404 - Page Not Found";
            exit();
        }
    } elseif (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        redirectTo('dashboard');
    } else {
        // Not signed in. On the platform's own address (bms.… / demo.…) "/" is the public website
        // (landing.php); on a company's own address it stays the company's login, as before.
        require_once __DIR__ . '/core/public_site.php';
        if ((!function_exists('bmsCurrentTenant') || bmsCurrentTenant() === null) && publicSiteIsPlatformRoot()) {
            require __DIR__ . '/landing.php';
            exit();
        }
        redirectTo('login');
    }
} else {
    // Handle the route through the routing system
    $handled = handleRoute();
    
    // If route not found, show 404
    if (!$handled) {
        http_response_code(404);
        echo "404 - Page Not Found";
        exit();
    }
}
?>
