<?php
/**
 * core/mobile_auth.php
 *
 * Bearer-token auth for the Flutter mobile API. Include this after roots.php
 * and call mobileBearerAuth(). On success $_SESSION is populated identically
 * to a web login so every existing permission helper works unchanged.
 */

if (!function_exists('mobileBearerAuth')) {

    function mobileBearerAuth(): bool
    {
        if (!empty($_SESSION['user_id'])) {
            return true; // already authenticated this request
        }

        // Extract token from  Authorization: Bearer <64 hex chars>
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($authHeader === '' && function_exists('getallheaders')) {
            $hdrs       = getallheaders();
            $authHeader = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
        }
        if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($authHeader), $m)) {
            return false;
        }
        $token = strtolower($m[1]);

        global $pdo;

        $stmt = $pdo->prepare("
            SELECT mt.token_id, mt.user_id, mt.expires_at,
                   u.role, u.user_role, u.role_id,
                   u.first_name, u.last_name, u.is_active
              FROM mobile_tokens mt
              JOIN users u ON u.user_id = mt.user_id
             WHERE mt.token = ?
             LIMIT 1
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }
        if ((int)($row['is_active'] ?? 1) !== 1) {
            return false;
        }
        if (new DateTime($row['expires_at']) < new DateTime()) {
            return false;
        }

        $_SESSION['user_id']    = (int)$row['user_id'];
        $_SESSION['role_id']    = (int)($row['role_id'] ?? 0);
        $_SESSION['role']       = $row['role']       ?? $row['user_role'] ?? 'user';
        $_SESSION['user_role']  = $row['user_role']  ?? $row['role']      ?? 'user';
        $_SESSION['first_name'] = $row['first_name'] ?? '';
        $_SESSION['last_name']  = $row['last_name']  ?? '';

        if (function_exists('loadUserPermissions')) {
            loadUserPermissions($_SESSION['role_id']);
        }

        // Best-effort last-used stamp; non-fatal
        try {
            $pdo->prepare("UPDATE mobile_tokens SET last_used_at = NOW() WHERE token_id = ?")
                ->execute([$row['token_id']]);
        } catch (Exception $e) { /* non-fatal */ }

        return true;
    }

}
