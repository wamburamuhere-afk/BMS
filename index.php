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
        // User is logged in, redirect to dashboard
        redirectTo('dashboard');
    } else {
        // User is not logged in, redirect to login
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
