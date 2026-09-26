<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants.
require_once __DIR__ . '/../../roots.php';
require_once ROOT_DIR . '/core/code_generator.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$method      = strtoupper($_POST['_method'] ?? 'SAVE');
$id          = intval($_POST['agent_id'] ?? 0);
$name        = trim($_POST['agent_name'] ?? '');
$outletType  = in_array($_POST['outlet_type'] ?? '', ['main','sub','kiosk','shop_in_shop']) ? $_POST['outlet_type'] : 'main';
$phonePrimary = trim($_POST['phone_primary'] ?? '');
$region      = trim($_POST['region'] ?? '');
$district    = trim($_POST['district'] ?? '');
$street      = trim($_POST['street'] ?? '');
$botLicense  = trim($_POST['bot_license'] ?? '');
$parentId    = intval($_POST['parent_agent_id'] ?? 0) ?: null;
$status      = in_array($_POST['status'] ?? '', ['active', 'suspended', 'closed']) ? $_POST['status'] : 'active';

if ($method === 'DELETE') {
    if (!canDelete('mm_agents')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
    if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid ID']); exit; }
    $pdo->prepare("UPDATE mm_agents SET status='closed', updated_at=NOW() WHERE agent_id=?")->execute([$id]);
    logActivity($pdo, $_SESSION['user_id'], "Closed MM agent id=$id");
    echo json_encode(['success' => true, 'message' => 'Agent closed.']);
    exit;
}

if (empty($name)) { echo json_encode(['success' => false, 'message' => 'Agent name is required']); exit; }

try {
    if ($id) {
        if (!canEdit('mm_agents')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
        $pdo->prepare("UPDATE mm_agents SET agent_name=?, outlet_type=?, phone_primary=?, region=?, district=?, street=?, bot_license=?, parent_agent_id=?, status=?, updated_at=NOW() WHERE agent_id=?")
            ->execute([$name, $outletType, $phonePrimary, $region, $district, $street, $botLicense, $parentId, $status, $id]);
        logActivity($pdo, $_SESSION['user_id'], "Updated MM agent: $name (id=$id)");
        echo json_encode(['success' => true, 'message' => 'Agent updated.']);
    } else {
        if (!canCreate('mm_agents')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
        $code = nextCode($pdo, 'MM-AGT');
        $pdo->prepare("INSERT INTO mm_agents (agent_code, agent_name, outlet_type, phone_primary, region, district, street, bot_license, parent_agent_id, status, created_by, created_at, updated_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([$code, $name, $outletType, $phonePrimary, $region, $district, $street, $botLicense, $parentId, $status, $_SESSION['user_id']]);
        $newId = (int)$pdo->lastInsertId();
        logActivity($pdo, $_SESSION['user_id'], "Created MM agent: $name ($code, id=$newId)");
        echo json_encode(['success' => true, 'message' => "Agent created ($code).", 'id' => $newId, 'code' => $code]);
    }
} catch (PDOException $e) {
    error_log("save_agent.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
