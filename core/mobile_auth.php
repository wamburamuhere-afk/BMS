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
        // NULL expires_at = no expiry (personal device tokens); only check when set
        if ($row['expires_at'] !== null && new DateTime($row['expires_at']) < new DateTime()) {
            return false;
        }

        $GLOBALS['BMS_MOBILE_TOKEN_ID'] = (int)$row['token_id'];
        $_SESSION['user_id']    = (int)$row['user_id'];

        $_SESSION['role_id']    = (int)($row['role_id'] ?? 0);
        $_SESSION['role']       = $row['role']       ?? $row['user_role'] ?? 'user';
        $_SESSION['user_role']  = $row['user_role']  ?? $row['role']      ?? 'user';
        $_SESSION['first_name'] = $row['first_name'] ?? '';
        $_SESSION['last_name']  = $row['last_name']  ?? '';

        if (function_exists('loadUserPermissions')) {
            loadUserPermissions($_SESSION['role_id']);
        }

        // The web sets this in header.php; API messages wrapped in t() follow it.
        if (empty($_SESSION['user_lang']) && function_exists('get_setting')) {
            $lang = get_setting('user_language_' . (int)$row['user_id'], 'en');
            $_SESSION['user_lang'] = in_array($lang, ['en', 'sw'], true) ? $lang : 'en';
            if (function_exists('loadLanguage')) loadLanguage($_SESSION['user_lang']);
        }


        // Best-effort last-used stamp; non-fatal
        try {
            $pdo->prepare("UPDATE mobile_tokens SET last_used_at = NOW() WHERE token_id = ?")
                ->execute([$row['token_id']]);
        } catch (Exception $e) { /* non-fatal */ }

        return true;
    }

}

if (!function_exists('mobileRun')) {
    /**
     * Run a shared (web) endpoint for a mobile caller. $fn echoes the JSON response
     * and may exit() mid-way; the response is captured in a shutdown-safe way and:
     *  - a 200 carrying success:false is re-coded (403 / 404 / 409 / 422) so the app
     *    can rely on HTTP status, as the rest of the mobile API does;
     *  - with a valid client_uuid the action runs at most once — a retry gets the
     *    stored response back with "idempotent": true. Failed responses release
     *    the key so the client can correct the input and retry with the same uuid.
     * The bearer token is the authentication, so the shared endpoint's CSRF check
     * is satisfied for this request.
     */
    function mobileRun(PDO $pdo, string $endpoint, ?string $uuid, callable $fn): void
    {
        if (function_exists('csrf_token') && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $_POST['_csrf'] = csrf_token();
        }

        $uuid = strtolower(trim((string)$uuid));
        $useKey = (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid);

        if ($useKey) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `mobile_idempotency_keys` (
                `client_uuid` CHAR(36) NOT NULL, `endpoint` VARCHAR(100) NOT NULL, `user_id` INT NOT NULL,
                `http_code` SMALLINT NULL DEFAULT NULL, `response_body` MEDIUMTEXT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`client_uuid`), KEY `idx_endpoint` (`endpoint`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $ins = $pdo->prepare("INSERT IGNORE INTO mobile_idempotency_keys (client_uuid, endpoint, user_id) VALUES (?, ?, ?)");
            $ins->execute([$uuid, $endpoint, (int)($_SESSION['user_id'] ?? 0)]);
            if ($ins->rowCount() === 0) {
                $row = $pdo->prepare("SELECT endpoint, http_code, response_body FROM mobile_idempotency_keys WHERE client_uuid = ?");
                $row->execute([$uuid]);
                $r = $row->fetch(PDO::FETCH_ASSOC);
                if (!$r || $r['endpoint'] !== $endpoint) {
                    http_response_code(409);
                    echo json_encode(['success' => false, 'message' => 'client_uuid already used for a different action']);
                } elseif ($r['response_body'] === null) {
                    http_response_code(409);
                    echo json_encode(['success' => false, 'message' => 'This request is still being processed — retry shortly']);
                } else {
                    $body = json_decode($r['response_body'], true);
                    if (is_array($body)) $body['idempotent'] = true;
                    http_response_code((int)$r['http_code'] ?: 200);
                    echo json_encode($body ?? []);
                }
                return;
            }
        }

        $level = ob_get_level();
        ob_start();
        $done = false;
        $finalise = function () use (&$done, $pdo, $uuid, $useKey, $level): void {
            if ($done) return;
            $done = true;
            $out = '';
            while (ob_get_level() > $level) $out = (string)ob_get_clean() . $out;
            $code = http_response_code() ?: 200;
            $json = json_decode(trim($out), true);
            $ok   = $code < 300 && is_array($json) && !empty($json['success']);
            if (!$ok && $code < 300) {
                $msg  = is_array($json) ? (string)($json['message'] ?? '') : '';
                $code = !is_array($json) ? 500
                      : (preg_match('/unauthori[sz]ed/i', $msg) ? 401
                      : (preg_match('/denied|permission|not allowed|not in your/i', $msg) ? 403
                      : (preg_match('/not found|does not exist/i', $msg) ? 404
                      : (preg_match('/already|duplicate|in use|exists/i', $msg) ? 409 : 422))));
                if (!is_array($json)) { error_log('mobileRun non-JSON output: ' . substr($out, 0, 300)); $out = json_encode(['success' => false, 'message' => 'Server error']); }
                http_response_code($code);
            }
            try {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($useKey) {
                    if ($ok) {
                        $pdo->prepare("UPDATE mobile_idempotency_keys SET http_code = ?, response_body = ? WHERE client_uuid = ?")
                            ->execute([$code, $out, $uuid]);
                    } else {
                        $pdo->prepare("DELETE FROM mobile_idempotency_keys WHERE client_uuid = ?")->execute([$uuid]);
                    }
                }
            } catch (Throwable $e) {
                error_log('mobileRun finalise: ' . $e->getMessage());
            }
            echo $out;
        };
        register_shutdown_function($finalise);
        $fn();
        $finalise();
    }
}

if (!function_exists('mobileJsonBody')) {
    /**
     * For $_POST-reading endpoints: when the request carried a JSON object body
     * instead of form fields, copy it into $_POST. Nested arrays (items,
     * denominations) are re-encoded as JSON strings, the shape form callers send.
     */
    function mobileJsonBody(): void
    {
        if (!empty($_POST) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        $data = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($data)) return;
        foreach ($data as $k => $v) {
            $_POST[$k] = is_array($v) ? json_encode($v) : (is_bool($v) ? (int)$v : $v);
        }
    }
}
