<?php
// Start the session if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Unset all session variables
$_SESSION = array();

// Delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Clear any additional cookies you might have set
setcookie('remember_token', '', time() - 3600, '/');

// Redirect to login page with a logout message
header("Location: login?logout=1");
exit();
?>