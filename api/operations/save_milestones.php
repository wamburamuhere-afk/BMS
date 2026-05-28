<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');

    if (!canEdit('projects')) {
        throw new Exception('Access Denied: you do not have permission to save project milestones');
    }

    $project_id = $_POST['project_id'] ?? null;
    $milestones = json_decode($_POST['milestones'] ?? '[]', true);

    if (!$project_id) throw new Exception('Project ID is required');

    // Phase B (scope) — block writes against projects not in user scope
    if (!userCan('project', (int)$project_id)) {
        http_response_code(403);
        throw new Exception('Access denied: this project is not in your scope.');
    }

    $pdo->beginTransaction();

    // Fetch current milestone IDs in DB for this project
    $stmtExisting = $pdo->prepare("SELECT id FROM project_milestones WHERE project_id = ? AND scope_type = 'milestone'");
    $stmtExisting->execute([$project_id]);
    $existingIds = $stmtExisting->fetchAll(PDO::FETCH_COLUMN);
    $existingIdsSet = array_flip($existingIds);

    $tempIdToRealId = [];
    $sentDbIds = [];

    $stmtUpdate = $pdo->prepare("UPDATE project_milestones SET description=?, unit=?, scope=?, weight_percent=?, parent_id=NULL WHERE id=? AND project_id=? AND scope_type='milestone'");
    $stmtInsert = $pdo->prepare("INSERT INTO project_milestones (project_id, scope_type, description, unit, scope, weight_percent, parent_id) VALUES (?, 'milestone', ?, ?, ?, ?, NULL)");

    // Pass 1: Update existing milestones, insert new ones, build temp→real ID map
    foreach ($milestones as $m) {
        $dbId = !empty($m['db_id']) ? intval($m['db_id']) : null;

        if ($dbId && isset($existingIdsSet[$dbId])) {
            // Existing milestone — UPDATE in place (preserves progress report links)
            $stmtUpdate->execute([$m['description'], $m['unit'], $m['scope'], $m['weight_percent'], $dbId, $project_id]);
            $realId = $dbId;
            $sentDbIds[] = $dbId;
        } else {
            // New milestone — INSERT
            $stmtInsert->execute([$project_id, $m['description'], $m['unit'], $m['scope'], $m['weight_percent']]);
            $realId = $pdo->lastInsertId();
        }

        if (isset($m['temp_id'])) {
            $tempIdToRealId[$m['temp_id']] = $realId;
        }
    }

    // Pass 2: Restore parent_id links using the temp→real map
    $stmtUpdateParent = $pdo->prepare("UPDATE project_milestones SET parent_id = ? WHERE id = ?");
    foreach ($milestones as $m) {
        if (!empty($m['parent_temp_id'])) {
            $realParentId = $tempIdToRealId[$m['parent_temp_id']] ?? null;
            $realId = $tempIdToRealId[$m['temp_id']] ?? null;
            if ($realParentId && $realId) {
                $stmtUpdateParent->execute([$realParentId, $realId]);
            }
        }
    }

    // Pass 3: Delete milestones removed from the list — ONLY if safe (no progress report details reference them)
    $idsToDelete = array_diff($existingIds, $sentDbIds);
    if (!empty($idsToDelete)) {
        $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
        $stmtCheckDetails = $pdo->prepare("SELECT DISTINCT milestone_id FROM project_progress_report_details WHERE milestone_id IN ($placeholders)");
        $stmtCheckDetails->execute(array_values($idsToDelete));
        $protectedIds = $stmtCheckDetails->fetchAll(PDO::FETCH_COLUMN);
        $safeToDelete = array_diff($idsToDelete, $protectedIds);

        if (!empty($safeToDelete)) {
            $placeholders2 = implode(',', array_fill(0, count($safeToDelete), '?'));
            $params = array_merge(array_values($safeToDelete), [$project_id]);
            $pdo->prepare("DELETE FROM project_milestones WHERE id IN ($placeholders2) AND project_id = ? AND scope_type = 'milestone'")->execute($params);
        }
    }

    $pdo->commit();

    // Phase 3c — milestones define project schedule baseline.
    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Saved Project Milestones", "Project ID: " . ($project_id ?? 'unknown'));

    echo json_encode(['success' => true, 'message' => 'Milestones saved successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
