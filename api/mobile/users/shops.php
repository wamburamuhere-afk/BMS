<?php
// scope-audit: skip — admin-only shop-access grants (same as Settings > Project & Warehouse Access)
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
require_once __DIR__ . '/../../../core/permissions.php';
require_once __DIR__ . '/_common.php';
mobileBearerAuth();
$method = $_SERVER['REQUEST_METHOD'] === 'GET' ? 'GET' : 'POST';
mobileUsersBootstrap($method);

$user_id = (int)($method === 'GET' ? ($_GET['user_id'] ?? 0) : ($_POST['user_id'] ?? 0));
if ($user_id <= 0) mobileUsersFail(400, 'Invalid user ID');
$st = $pdo->prepare("SELECT 1 FROM users WHERE user_id = ?");
$st->execute([$user_id]);
if (!$st->fetchColumn()) mobileUsersFail(404, 'User not found');

if ($method === 'GET') {
    echo json_encode(['success' => true, 'user_id' => $user_id] + mobileUserShops($pdo, $user_id));
    exit;
}

$shops = mobileShopsFromRequest();
if ($shops === null) mobileUsersFail(422, 'Send all_shops (true/false) and/or warehouse_ids');
try {
    $pdo->beginTransaction();
    mobileSetUserShops($pdo, $user_id, $shops[0], $shops[1]);
    $pdo->commit();
} catch (InvalidArgumentException $ie) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    mobileUsersFail(422, $ie->getMessage());
}
if (function_exists('refreshScopeCache')) refreshScopeCache($user_id);
$now = mobileUserShops($pdo, $user_id);
logActivity($pdo, $_SESSION['user_id'], 'Updated Warehouse Access',
    "user_id=$user_id warehouses=" . ($now['all_shops'] ? 'ALL' : implode(',', $now['warehouse_ids'])) . ' (mobile)');
logAudit($pdo, $_SESSION['user_id'], 'user_warehouse_access_updated', [
    'activity_type' => 'access_control', 'entity_type' => 'user', 'entity_id' => $user_id,
    'description'   => "Updated warehouse access for user_id=$user_id via mobile",
    'new_values'    => ['grant_all_warehouses' => $now['all_shops'], 'warehouse_ids' => $now['warehouse_ids']],
]);
echo json_encode(['success' => true, 'message' => 'Shop access saved', 'user_id' => $user_id] + $now);
