<?php
// Start the session if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Session tracking: close the open session row + log a Logout event BEFORE
//    the session is destroyed (best-effort — must never block sign-out). ──────
try {
    $logout_uid = $_SESSION['user_id'] ?? null;
    $logout_sid = $_SESSION['session_row_id'] ?? null;
    if ($logout_uid && $logout_sid) {
        require_once __DIR__ . '/includes/config.php';        // $pdo
        require_once __DIR__ . '/core/session_tracker.php';
        require_once __DIR__ . '/helpers.php';
        if (isset($pdo) && $pdo instanceof PDO) {
            $dur = endUserSession($pdo, (int) $logout_sid, 'manual');
            if (function_exists('logActivity')) {
                logActivity($pdo, (int) $logout_uid, 'Logout',
                    'Logged out of the system — session lasted ' . formatDuration($dur));
            }
        }
    }
} catch (Throwable $e) {
    error_log('logout session-tracking: ' . $e->getMessage());
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