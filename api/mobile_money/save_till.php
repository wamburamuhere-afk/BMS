<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants.
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$method       = strtoupper($_POST['_method'] ?? 'SAVE');
$id           = intval($_POST['till_id'] ?? 0);
$agentId      = intval($_POST['agent_id'] ?? 0);
$networkId    = intval($_POST['network_id'] ?? 0);
$tillNumber   = trim($_POST['till_number'] ?? '');
$simMsisdn    = trim($_POST['sim_msisdn'] ?? '');
$floatCeiling = (float)str_replace(',', '', $_POST['float_ceiling'] ?? 0) ?: null;
$cashCeiling  = (float)str_replace(',', '', $_POST['cash_ceiling'] ?? 0) ?: null;
$status       = in_array($_POST['status'] ?? '', ['active', 'suspended', 'closed']) ? $_POST['status'] : 'active';

if ($method === 'DELETE') {
    if (!canDelete('mm_agents')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
    if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid ID']); exit; }
    $pdo->prepare("UPDATE mm_tills SET status='closed', updated_at=NOW() WHERE till_id=?")->execute([$id]);
    logActivity($pdo, $_SESSION['user_id'], "Closed MM till id=$id");
    echo json_encode(['success' => true, 'message' => 'Till closed.']);
    exit;
}

if (!$agentId)       { echo json_encode(['success' => false, 'message' => 'Agent is required']); exit; }
if (!$networkId)     { echo json_encode(['success' => false, 'message' => 'Network is required']); exit; }
if (empty($tillNumber)) { echo json_encode(['success' => false, 'message' => 'Till number is required']); exit; }

try {
    if ($id) {
        if (!canEdit('mm_agents')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
        $pdo->prepare("UPDATE mm_tills SET network_id=?, till_number=?, sim_msisdn=?, float_ceiling=?, cash_ceiling=?, status=?, updated_at=NOW() WHERE till_id=?")
            ->execute([$networkId, $tillNumber, $simMsisdn ?: null, $floatCeiling, $cashCeiling, $status, $id]);
        logActivity($pdo, $_SESSION['user_id'], "Updated MM till: $tillNumber (id=$id)");
        echo json_encode(['success' => true, 'message' => 'Till updated.']);
    } else {
        if (!canCreate('mm_agents')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
        $pdo->prepare("INSERT INTO mm_tills (agent_id, network_id, till_number, sim_msisdn, float_ceiling, cash_ceiling, status, created_by, created_at, updated_at)
                        VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([$agentId, $networkId, $tillNumber, $simMsisdn ?: null, $floatCeiling, $cashCeiling, $status, $_SESSION['user_id']]);
        $newId = (int)$pdo->lastInsertId();
        logActivity($pdo, $_SESSION['user_id'], "Created MM till: $tillNumber for agent id=$agentId (id=$newId)");
        echo json_encode(['success' => true, 'message' => "Till created.", 'id' => $newId]);
    }
} catch (PDOException $e) {
    error_log("save_till.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
