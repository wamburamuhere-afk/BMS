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
    // Check if user is logged in
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
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
