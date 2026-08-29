<?php
// api/pos/save_employment_type.php — create/update an employment type lookup.
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();
if (!canEdit('employment_types')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied: you cannot manage employment types']); exit; }

try {
    $id   = (int)($_POST['type_id'] ?? 0);
    $name = trim($_POST['type_name'] ?? '');
    $desc = trim($_POST['description'] ?? '');

    if ($name === '') throw new Exception('Name is required.');

    $dupCheck = $pdo->prepare("SELECT type_id FROM employment_types WHERE LOWER(type_name) = LOWER(?) AND type_id != ?");
    $dupCheck->execute([$name, $id]);
    if ($dupCheck->fetchColumn()) throw new Exception('An employment type with this name already exists.');

    if ($id > 0) {
        $pdo->prepare("UPDATE employment_types SET type_name = ?, description = ?, updated_at = NOW() WHERE type_id = ?")
            ->execute([$name, ($desc !== '' ? $desc : null), $id]);
        logActivity($pdo, $_SESSION['user_id'], "Updated employment type", "$name (ID: $id)");
        echo json_encode(['success' => true, 'message' => 'Employment type updated.', 'id' => $id]);
    } else {
        $pdo->prepare("INSERT INTO employment_types (type_name, description, status, created_by, created_at)
                       VALUES (?, ?, 'active', ?, NOW())")
            ->execute([$name, ($desc !== '' ? $desc : null), $_SESSION['user_id']]);
        $newId = (int)$pdo->lastInsertId();
        logActivity($pdo, $_SESSION['user_id'], "Created employment type", "$name (ID: $newId)");
        echo json_encode(['success' => true, 'message' => 'Employment type created.', 'id' => $newId]);
    }

} catch (Exception $e) {
    error_log('save_employment_type error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
