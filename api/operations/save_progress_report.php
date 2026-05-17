<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');

    $project_id  = $_POST['project_id'] ?? null;
    $sc_id       = isset($_POST['sc_id']) && $_POST['sc_id'] !== '' ? intval($_POST['sc_id']) : null;
    $report_date = $_POST['report_date'] ?? date('Y-m-d');
    $report_type = $_POST['report_type'] ?? 'daily';
    $details     = json_decode($_POST['details'] ?? '[]', true);
    $comments    = $_POST['comments'] ?? '';
    $removed_ids = json_decode($_POST['removed_attachment_ids'] ?? '[]', true);

    if (!$project_id) throw new Exception('Project ID is required');

    $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png'];
    $upload_dir = __DIR__ . '/../../uploads/projects/reports/';
    if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);

    $pdo->beginTransaction();

    // Upsert the progress report row (keyed by project_id + report_date + report_type + sc_id)
    if ($sc_id !== null) {
        $stmtCheck = $pdo->prepare("SELECT id FROM project_progress_reports WHERE project_id = ? AND report_date = ? AND report_type = ? AND sc_id = ?");
        $stmtCheck->execute([$project_id, $report_date, $report_type, $sc_id]);
    } else {
        $stmtCheck = $pdo->prepare("SELECT id FROM project_progress_reports WHERE project_id = ? AND report_date = ? AND report_type = ? AND sc_id IS NULL");
        $stmtCheck->execute([$project_id, $report_date, $report_type]);
    }
    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $report_id = $existing['id'];
        $pdo->prepare("UPDATE project_progress_reports SET comments = ?, updated_at = NOW(), created_by = ? WHERE id = ?")
            ->execute([$comments, $_SESSION['user_id'], $report_id]);
        $pdo->prepare("DELETE FROM project_progress_report_details WHERE report_id = ?")->execute([$report_id]);
    } else {
        $pdo->prepare("INSERT INTO project_progress_reports (project_id, sc_id, report_date, report_type, comments, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
            ->execute([$project_id, $sc_id, $report_date, $report_type, $comments, $_SESSION['user_id']]);
        $report_id = $pdo->lastInsertId();
    }

    // Insert milestone details
    $stmtDetailInsert = $pdo->prepare("INSERT INTO project_progress_report_details (report_id, milestone_id, actual_value, progress_percent) VALUES (?, ?, ?, ?)");
    foreach ($details as $d) {
        $stmtDetailInsert->execute([$report_id, $d['milestone_id'], (float)($d['actual_value'] ?? 0), (float)($d['progress_percent'] ?? 0)]);
    }

    // Remove attachments the user deleted
    if (!empty($removed_ids)) {
        foreach ($removed_ids as $rid) {
            $rid = intval($rid);
            if (!$rid) continue;
            $row = $pdo->prepare("SELECT file_path FROM project_progress_report_attachments WHERE id = ? AND report_id = ?");
            $row->execute([$rid, $report_id]);
            $att = $row->fetch(PDO::FETCH_ASSOC);
            if ($att) {
                $physPath = __DIR__ . '/../../' . $att['file_path'];
                if (file_exists($physPath)) @unlink($physPath);
            }
            $pdo->prepare("DELETE FROM project_progress_report_attachments WHERE id = ? AND report_id = ?")->execute([$rid, $report_id]);
        }
    }

    // Save new uploaded attachments
    $names = $_POST['attachment_names'] ?? [];
    $files = $_FILES['attachment_files'] ?? [];

    if (!empty($files['name'])) {
        $count = count($files['name']);
        $stmtAtt = $pdo->prepare("INSERT INTO project_progress_report_attachments (report_id, attachment_name, file_path, file_size, file_ext) VALUES (?, ?, ?, ?, ?)");
        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
            $file_ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($file_ext, $allowed_ext)) continue;
            if ($files['size'][$i] > 20 * 1024 * 1024) continue;
            $filename = 'report_' . $project_id . '_' . date('Ymd') . '_' . uniqid() . '.' . $file_ext;
            if (!move_uploaded_file($files['tmp_name'][$i], $upload_dir . $filename)) continue;
            $att_name = !empty($names[$i]) ? trim($names[$i]) : ($files['name'][$i]);
            $file_path = 'uploads/projects/reports/' . $filename;
            $stmtAtt->execute([$report_id, $att_name, $file_path, $files['size'][$i], $file_ext]);

            // Register in Docs Library
            try {
                $pdo->prepare("INSERT INTO documents (document_name, description, file_path, original_filename, file_size, file_type, version, tags, access_level, uploaded_by, project_id, source) VALUES (?, ?, ?, ?, ?, ?, '1.0', 'daily report', 'internal', ?, ?, ?)")
                    ->execute([$att_name, 'Attached to daily progress report (' . $report_date . ')', $file_path, $files['name'][$i], $files['size'][$i], $file_ext, $_SESSION['user_id'], $project_id, 'Daily Report']);
            } catch (Exception $docEx) { /* non-fatal */ }
        }
    }

    $pdo->commit();

    $new_overall_progress = syncProjectProgress($pdo, $project_id);

    echo json_encode(['success' => true, 'message' => 'Progress report saved successfully', 'overall_progress' => $new_overall_progress]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
