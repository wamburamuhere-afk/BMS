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

        // ── Multi-tenancy ───────────────────────────────────────────────────
        // Pin the session to the tenant this login actually authenticated
        // against, so core/tenant_bootstrap.php can reject the cookie if it is
        // ever replayed against a different tenant's subdomain. Null in
        // single-tenant mode, where the key is simply absent and the guard is
        // a no-op. Guarded by function_exists so this file keeps working if the
        // multi-tenancy layer is reverted.
        if (function_exists('bmsCurrentTenantId')) {
            $__tenantId = bmsCurrentTenantId();
            if ($__tenantId !== null) {
                $_SESSION['tenant_id'] = $__tenantId;
            }
        }

        // A platform operator session must never coexist with a tenant login in
        // the same browser session — whichever is established last wins.
        unset($_SESSION['superadmin_id']);

        // Load permissions
        if (function_exists('loadUserPermissions')) {
            loadUserPermissions($_SESSION['role_id']);
        }

        // Session-row creation is deliberately NOT done here — see
        // api/finalize_login.php. It needs a GeoIP lookup (a real HTTP call to
        // ip-api.com, up to a 3s timeout) and can trigger an SMTP-sent
        // notification email; doing that inline used to make login itself as
        // slow as whichever of those was slowest. login.php's JS fires that
        // endpoint via sendBeacon right after this response, so the user never
        // waits on either.
        $response['success'] = true;
    } else {
        $response['message'] = 'Invalid username or password.';
    }
}

echo json_encode($response);
