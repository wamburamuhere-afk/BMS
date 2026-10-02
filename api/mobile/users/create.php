<?php
// scope-audit: skip — admin-only user management (same gate as the web Users page)
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
require_once __DIR__ . '/../../../core/permissions.php';
require_once __DIR__ . '/_common.php';
mobileBearerAuth();
mobileUsersBootstrap('POST');

$d = [
    'username'         => trim((string)($_POST['username'] ?? '')),
    'email'            => trim((string)($_POST['email'] ?? '')),
    'first_name'       => trim((string)($_POST['first_name'] ?? '')),
    'last_name'        => trim((string)($_POST['last_name'] ?? '')),
    'phone'            => trim((string)($_POST['phone'] ?? '')),
    'role_id'          => (int)($_POST['role_id'] ?? 0),
    'password'         => (string)($_POST['password'] ?? ''),
    'confirm_password' => (string)($_POST['confirm_password'] ?? ''),
];

mobileRun($pdo, 'users/create', $_POST['client_uuid'] ?? null, function () use ($d) {
    global $pdo;
    $errors = mobileUsersValidate($pdo, $d, null);
    if ($errors) mobileUsersFail(422, reset($errors), $errors);
    if (function_exists('tenantWithinUserLimit') && !tenantWithinUserLimit($pdo)) {
        mobileUsersFail(409, t("You have reached your plan's user limit. Deactivate an existing user to free a seat, or ask the platform to raise your limit."));
    }
    $shops = mobileShopsFromRequest();
    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO users (username, email, first_name, last_name, phone, role_id, password, is_active, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())")
            ->execute([$d['username'], $d['email'], $d['first_name'], $d['last_name'], $d['phone'] !== '' ? $d['phone'] : null,
                       $d['role_id'], password_hash($d['password'], PASSWORD_DEFAULT)]);
        $id = (int)$pdo->lastInsertId();
        if ($shops) mobileSetUserShops($pdo, $id, $shops[0], $shops[1]);
        $pdo->commit();
    } catch (InvalidArgumentException $ie) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        mobileUsersFail(422, $ie->getMessage());
    }
    logActivity($pdo, $_SESSION['user_id'], 'Create user', "Mobile: created user {$d['first_name']} {$d['last_name']} ({$d['username']}, ID $id)");
    logAudit($pdo, $_SESSION['user_id'], 'create_user', [
        'entity_type' => 'user', 'entity_id' => $id,
        'description' => "Created new user: {$d['username']} ({$d['email']}) via mobile",
        'new_values'  => ['username' => $d['username'], 'email' => $d['email'], 'first_name' => $d['first_name'], 'last_name' => $d['last_name'], 'role_id' => $d['role_id']],
    ]);
    echo json_encode(['success' => true, 'message' => t('User created successfully!'), 'user' => mobileUserRow($pdo, $id)]);
});
