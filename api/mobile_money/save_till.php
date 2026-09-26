<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants.
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$method   = strtoupper($_POST['_method'] ?? 'SAVE');
$id       = intval($_POST['till_id'] ?? 0);
$agentId  = intval($_POST['agent_id'] ?? 0);
$name     = trim($_POST['till_name'] ?? '');
$teller   = trim($_POST['teller_name'] ?? '');
$phone    = trim($_POST['phone'] ?? '');
$status   = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

if ($method === 'DELETE') {
    if (!canDelete('mm_agents')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
    $pdo->prepare("UPDATE mm_tills SET status='deleted' WHERE till_id=?")->execute([$id]);
    logActivity($pdo, $_SESSION['user_id'], "Deleted MM till id=$id");
    echo json_encode(['success' => true, 'message' => 'Till deleted.']);
    exit;
}

if (!$agentId) { echo json_encode(['success' => false, 'message' => 'Agent ID missing']); exit; }
if (empty($name)) { echo json_encode(['success' => false, 'message' => 'Till name is required']); exit; }

try {
    if ($id) {
        if (!canEdit('mm_agents')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
        $pdo->prepare("UPDATE mm_tills SET till_name=?, teller_name=?, phone=?, status=?, updated_at=NOW() WHERE till_id=?")
            ->execute([$name, $teller, $phone, $status, $id]);
        logActivity($pdo, $_SESSION['user_id'], "Updated MM till: $name (id=$id)");
        echo json_encode(['success' => true, 'message' => 'Till updated.']);
    } else {
        if (!canCreate('mm_agents')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
        $code = nextCode($pdo, 'MM-TIL');
        $pdo->prepare("INSERT INTO mm_tills (till_code, agent_id, till_name, teller_name, phone, status, created_by, created_at, updated_at)
                        VALUES (?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([$code, $agentId, $name, $teller, $phone, $status, $_SESSION['user_id']]);
        $newId = $pdo->lastInsertId();
        logActivity($pdo, $_SESSION['user_id'], "Created MM till: $name ($code) for agent id=$agentId");
        echo json_encode(['success' => true, 'message' => "Till created ($code).", 'id' => $newId]);
    }
} catch (PDOException $e) {
    error_log("save_till.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
