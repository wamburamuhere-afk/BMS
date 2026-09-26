<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants.
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('mm_networks') && !canCreate('mm_networks')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$id              = intval($_POST['network_id'] ?? 0);
$name            = trim($_POST['network_name'] ?? '');
$code            = strtoupper(trim($_POST['network_code'] ?? ''));
$provider        = trim($_POST['provider'] ?? '');
$shortCode       = trim($_POST['short_code'] ?? '');
$colorHex        = trim($_POST['color_hex'] ?? '#198754');
$floatAcctId     = intval($_POST['float_account_id'] ?? 0) ?: null;
$commAcctId      = intval($_POST['commission_account_id'] ?? 0) ?: null;
$sortOrder       = intval($_POST['sort_order'] ?? 10);
$status          = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

if (empty($name)) { echo json_encode(['success' => false, 'message' => 'Network name is required']); exit; }

try {
    if ($id) {
        if (!canEdit('mm_networks')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
        $pdo->prepare("UPDATE mm_networks SET network_name=?, provider=?, short_code=?, color_hex=?,
                        float_account_id=?, commission_account_id=?, sort_order=?, status=?
                        WHERE network_id=?")
            ->execute([$name, $provider, $shortCode, $colorHex, $floatAcctId, $commAcctId, $sortOrder, $status, $id]);
        logActivity($pdo, $_SESSION['user_id'], "Updated MM network: $name (id=$id)");
        echo json_encode(['success' => true, 'message' => 'Network updated.']);
    } else {
        if (!canCreate('mm_networks')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
        if (empty($code)) { echo json_encode(['success' => false, 'message' => 'Network code is required']); exit; }
        $dup = $pdo->prepare("SELECT network_id FROM mm_networks WHERE network_code=? LIMIT 1");
        $dup->execute([$code]);
        if ($dup->fetch()) { echo json_encode(['success' => false, 'message' => 'Network code already exists']); exit; }
        $pdo->prepare("INSERT INTO mm_networks (network_code, network_name, provider, short_code, color_hex,
                        float_account_id, commission_account_id, sort_order, status, created_by, created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$code, $name, $provider, $shortCode, $colorHex, $floatAcctId, $commAcctId, $sortOrder, $status, $_SESSION['user_id']]);
        $newId = $pdo->lastInsertId();
        logActivity($pdo, $_SESSION['user_id'], "Created MM network: $name ($code, id=$newId)");
        echo json_encode(['success' => true, 'message' => 'Network created.', 'id' => $newId]);
    }
} catch (PDOException $e) {
    error_log("save_network.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
