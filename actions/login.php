<?php
// actions/login.php
session_start();
require_once '../includes/config.php';

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Fetch user from database
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Guard: $user is false when no row matches — never index into it directly.
    if ($user && password_verify($password, $user['password'])) {

        // is_active was previously only cosmetic — Settings > Users could flip
        // it, and Login History's "Block Account" writes it too, but nothing
        // ever actually checked it here, so a "deactivated" account could still
        // log in normally. This is the one place that makes the flag real: an
        // account manually blocked by an admin (never automatically) cannot
        // sign back in until an admin reactivates it.
        if ((int) ($user['is_active'] ?? 1) !== 1) {
            $response['message'] = 'This account has been deactivated. Please contact an administrator.';
            echo json_encode($response);
            exit;
        }

        // Include permissions logic
        require_once '../core/permissions.php';

        // Update last_login timestamp
        $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
        $updateStmt->execute([$user['user_id']]);

        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['role_id'] = $user['role_id'] ?? 0;
        $_SESSION['role'] = $user['role'] ?? $user['user_role'] ?? 'user';
        $_SESSION['user_role'] = $user['user_role'] ?? $user['role'] ?? 'user';
        $_SESSION['first_name'] = $user['first_name'] ?? '';
        $_SESSION['last_name'] = $user['last_name'] ?? '';

        // Load permissions
        if (function_exists('loadUserPermissions')) {
            loadUserPermissions($_SESSION['role_id']);
        }

        // ── Session tracking (best-effort, never blocks login) ──────────────
        // Open a user_sessions row so we can measure how long the user stays,
        // and record a Login event in the activity feed (who / when / IP).
        try {
            require_once __DIR__ . '/../core/session_tracker.php';
            require_once __DIR__ . '/../helpers.php';
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $sid = startUserSession($pdo, (int) $user['user_id'], $ip, $ua, session_id());
            if ($sid) $_SESSION['session_row_id'] = $sid;
            if (function_exists('logActivity')) {
                logActivity($pdo, (int) $user['user_id'], 'Login', 'Logged in to the system');
            }
        } catch (Throwable $e) {
            error_log('login session-tracking: ' . $e->getMessage());
        }

        $response['success'] = true;
    } else {
        $response['message'] = 'Invalid username or password.';
    }
}

echo json_encode($response);
