<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
// Shared by api/mobile/users/*.php — same rules as the web add_user.php / edit_user.php.
if (!defined('BMS_MOBILE_USERS_COMMON')) {
    define('BMS_MOBILE_USERS_COMMON', 1);

    /** Admin gate + method + JSON body, shared by every users endpoint. */
    function mobileUsersBootstrap(string $method): void
    {
        if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
        if (!isAdmin())         { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Only an administrator can manage users']); exit; }
        if ($_SERVER['REQUEST_METHOD'] !== $method) { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
        if ($method === 'POST') {
            if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();
            mobileJsonBody();
        }
    }

    function mobileUsersFail(int $code, string $msg, array $errors = []): void
    {
        http_response_code($code);
        echo json_encode(['success' => false, 'message' => $msg] + ($errors ? ['errors' => $errors] : []));
        exit;
    }

    /**
     * Validate username/email/names/role/password exactly like the web forms.
     * $existingId null = create (password required). Returns field => message.
     */
    function mobileUsersValidate(PDO $pdo, array $d, ?int $existingId): array
    {
        $e = [];
        $ex = (int)($existingId ?? 0);
        if ($d['username'] === '') $e['username'] = t('Username is required');
        elseif (strlen($d['username']) < 4) $e['username'] = t('Username must be at least 4 characters');
        else {
            $st = $pdo->prepare("SELECT 1 FROM users WHERE username = ? AND user_id <> ?");
            $st->execute([$d['username'], $ex]);
            if ($st->fetchColumn()) $e['username'] = t('Username already exists');
        }
        if ($d['email'] === '') $e['email'] = t('Email is required');
        elseif (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $e['email'] = t('Invalid email format');
        else {
            $st = $pdo->prepare("SELECT 1 FROM users WHERE email = ? AND user_id <> ?");
            $st->execute([$d['email'], $ex]);
            if ($st->fetchColumn()) $e['email'] = t('Email already exists');
        }
        if ($d['first_name'] === '') $e['first_name'] = t('First name is required');
        if ($d['last_name'] === '')  $e['last_name']  = t('Last name is required');
        $st = $pdo->prepare("SELECT 1 FROM roles WHERE role_id = ?");
        $st->execute([(int)$d['role_id']]);
        if ((int)$d['role_id'] <= 0 || !$st->fetchColumn()) $e['role_id'] = t('Invalid role selected');

        $needPw = $existingId === null || $d['password'] !== '';
        if ($needPw) {
            if ($d['password'] === '') $e['password'] = t('Password is required');
            elseif (strlen($d['password']) < 8) $e['password'] = t('Password must be at least 8 characters');
            if ($d['password'] !== $d['confirm_password']) $e['confirm_password'] = t('Passwords do not match');
        }
        return $e;
    }

    /** Current shop access for a user (user_scope_overrides, resource_type='warehouse'). */
    function mobileUserShops(PDO $pdo, int $userId): array
    {
        $st = $pdo->prepare("SELECT resource_id FROM user_scope_overrides WHERE user_id = ? AND resource_type = 'warehouse'");
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        return [
            'all_shops'     => in_array(null, $rows, true),
            'warehouse_ids' => array_values(array_map('intval', array_filter($rows, fn($v) => $v !== null))),
        ];
    }

    /** Replace a user's shop access — same full-replace rule as Settings > Project & Warehouse Access. */
    function mobileSetUserShops(PDO $pdo, int $userId, bool $all, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if (!$all && $ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT COUNT(*) FROM warehouses WHERE warehouse_id IN ($in) AND status != 'deleted'");
            $st->execute($ids);
            if ((int)$st->fetchColumn() !== count($ids)) throw new InvalidArgumentException('One or more warehouse_ids do not exist');
        }
        $me = (int)$_SESSION['user_id'];
        $pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id = ? AND resource_type = 'warehouse'")->execute([$userId]);
        if ($all) {
            $pdo->prepare("INSERT INTO user_scope_overrides (user_id, resource_type, resource_id, granted_by) VALUES (?, 'warehouse', NULL, ?)")->execute([$userId, $me]);
        } else {
            $ins = $pdo->prepare("INSERT IGNORE INTO user_scope_overrides (user_id, resource_type, resource_id, granted_by) VALUES (?, 'warehouse', ?, ?)");
            foreach ($ids as $wid) $ins->execute([$userId, $wid, $me]);
        }
    }

    /** Parse all_shops / warehouse_ids from the request (warehouse_ids may be a JSON string or CSV). */
    function mobileShopsFromRequest(): ?array
    {
        if (!array_key_exists('all_shops', $_POST) && !array_key_exists('warehouse_ids', $_POST)) return null;
        $raw = $_POST['warehouse_ids'] ?? [];
        if (is_string($raw)) $raw = json_decode($raw, true) ?? array_filter(explode(',', $raw), 'strlen');
        $all = !empty($_POST['all_shops']) && $_POST['all_shops'] !== 'false';
        return [$all, is_array($raw) ? $raw : []];
    }

    function mobileUserRow(PDO $pdo, int $userId): ?array
    {
        $st = $pdo->prepare("SELECT u.user_id, u.username, u.first_name, u.last_name, u.email, u.phone, u.role_id,
                                    COALESCE(r.role_name, u.role, u.user_role) AS role_name, u.is_active, u.last_login, u.avatar, u.created_at
                               FROM users u LEFT JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?");
        $st->execute([$userId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) return null;
        return mobileUserShape($pdo, $u);
    }

    function mobileUserShape(PDO $pdo, array $u): array
    {
        return [
            'user_id'    => (int)$u['user_id'],
            'username'   => $u['username'],
            'first_name' => $u['first_name'] ?? '',
            'last_name'  => $u['last_name'] ?? '',
            'full_name'  => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')),
            'email'      => $u['email'] ?? '',
            'phone'      => $u['phone'] ?? '',
            'role_id'    => (int)$u['role_id'],
            'role_name'  => $u['role_name'] ?? '',
            'is_active'  => (int)$u['is_active'] === 1,
            'last_login' => $u['last_login'],
            'avatar_url' => !empty($u['avatar']) ? 'uploads/avatars/' . basename($u['avatar']) : '',
            'created_at' => $u['created_at'] ?? null,
            'shops'      => mobileUserShops($pdo, (int)$u['user_id']),
            'is_me'      => (int)$u['user_id'] === (int)($_SESSION['user_id'] ?? 0),
        ];
    }
}
