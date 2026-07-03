<?php
// API: Upload a training certificate for a participant (Tier 3, Phase 3.5 — D22).
// §19 5-step upload; registers into the central documents library with an
// optional expire_date so the existing document-expiry cron alerts on expiring
// certifications with zero new alert code.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('trainings')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to upload certificates']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$file_rel = null;
try {
    $participant_id = intval($_POST['participant_id'] ?? 0);
    $expire_date    = trim($_POST['certificate_expire_date'] ?? '');
    if (!$participant_id) throw new Exception('Participant is required');
    if ($expire_date !== '' && !strtotime($expire_date)) throw new Exception('Expiry date is not a valid date');

    $row = $pdo->prepare("
        SELECT p.participant_id, p.employee_id, e.first_name, e.last_name, e.project_id, t.title
        FROM training_participants p
        JOIN employees e ON e.employee_id = p.employee_id
        JOIN trainings t ON t.training_id = p.training_id
        WHERE p.participant_id = ?
    ");
    $row->execute([$participant_id]);
    $p = $row->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new Exception('Participant not found');
    if (function_exists('assertScopeForEmployee')) assertScopeForEmployee((int)$p['employee_id']);
    $emp_name = trim($p['first_name'] . ' ' . $p['last_name']);

    if (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) throw new Exception('A file is required');
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) throw new Exception('File upload failed');

    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    $allowed_ext = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif'];
    if (!in_array($ext, $allowed_ext, true)) throw new Exception('File type not allowed');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $real_mime = $finfo->file($_FILES['file']['tmp_name']);
    $allowed_mime = ['application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($real_mime, $allowed_mime, true)) throw new Exception('File content does not match allowed types');

    if ($_FILES['file']['size'] > 10 * 1024 * 1024) throw new Exception('File exceeds the 10MB size limit');

    $safe_name = bin2hex(random_bytes(16)) . '.' . $ext;
    $target_dir = __DIR__ . '/../uploads/training_certs/';
    if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $target_dir . $safe_name)) throw new Exception('Upload failed');
    $file_rel = 'uploads/training_certs/' . $safe_name;

    $pdo->beginTransaction();

    $library_id = null;
    if (function_exists('registerFileInLibrary')) {
        $library_id = registerFileInLibrary(
            $pdo, $file_rel, $_FILES['file']['name'], (int)$_FILES['file']['size'],
            'Training Certificate — ' . $emp_name . ' (' . $p['title'] . ')',
            'hr,training,certificate', (int)$_SESSION['user_id'],
            $p['project_id'] !== null ? (int)$p['project_id'] : null
        );
        if ($library_id && $expire_date !== '') {
            $pdo->prepare("UPDATE documents SET expire_date = ? WHERE id = ?")->execute([$expire_date, $library_id]);
        }
    }

    $pdo->prepare("UPDATE training_participants SET certificate_path=?, certificate_name=?, certificate_expire_date=?, library_document_id=?, updated_by=? WHERE participant_id=?")
        ->execute([$file_rel, $_FILES['file']['name'], ($expire_date!==''?$expire_date:null), $library_id, $_SESSION['user_id'], $participant_id]);

    logActivity($pdo, $_SESSION['user_id'], 'Upload training certificate', "certificate for $emp_name (participant #$participant_id)");
    logAudit($pdo, $_SESSION['user_id'], 'create', [
        'activity_type' => 'create', 'entity_type' => 'training_certificate', 'entity_id' => $participant_id,
        'description' => "Uploaded training certificate for $emp_name",
        'new_values' => ['expire_date' => $expire_date ?: null],
    ]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Certificate uploaded']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($file_rel !== null && file_exists(__DIR__ . '/../' . $file_rel)) @unlink(__DIR__ . '/../' . $file_rel);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
