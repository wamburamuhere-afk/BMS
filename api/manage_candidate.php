<?php
// API: Manage candidates (Tier 4, Phase 4.5 — D27). add (with optional CV
// upload → central library) / update / delete. Internal ATS only — no public
// application form.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$action = trim($_POST['action'] ?? '');
$need = ($action === 'add') ? 'create' : (($action === 'delete') ? 'delete' : 'edit');
$ok = $need === 'create' ? canCreate('recruitment') : ($need === 'delete' ? canDelete('recruitment') : canEdit('recruitment'));
if (!$ok) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }

$cv_path = null;
try {
    switch ($action) {
        case 'add':
        case 'update': {
            $id = intval($_POST['candidate_id'] ?? 0);
            $opening = intval($_POST['opening_id'] ?? 0);
            $name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $source = trim($_POST['source'] ?? '');
            if (!$opening) throw new Exception('Opening is required');
            if ($name === '') throw new Exception('Candidate name is required');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email');
            $chk = $pdo->prepare("SELECT opening_id FROM job_openings WHERE opening_id=? AND status!='deleted'");
            $chk->execute([$opening]);
            if (!$chk->fetch()) throw new Exception('Opening not found');

            // Optional CV — §19 5-step → central library
            $library_id = null; $cv_name = null;
            if (!empty($_FILES['cv']) && $_FILES['cv']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['cv']['error'] !== UPLOAD_ERR_OK) throw new Exception('CV upload failed');
                $ext = strtolower(pathinfo($_FILES['cv']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['pdf','doc','docx'], true)) throw new Exception('CV must be PDF or Word');
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES['cv']['tmp_name']);
                if (!in_array($mime, ['application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)) throw new Exception('CV content does not match allowed types');
                if ($_FILES['cv']['size'] > 10*1024*1024) throw new Exception('CV exceeds 10MB');
                $safe = bin2hex(random_bytes(16)) . '.' . $ext;
                $dir = __DIR__ . '/../uploads/candidate_cvs/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                if (!move_uploaded_file($_FILES['cv']['tmp_name'], $dir . $safe)) throw new Exception('Upload failed');
                $cv_path = 'uploads/candidate_cvs/' . $safe;
                $cv_name = $_FILES['cv']['name'];
                if (function_exists('registerFileInLibrary')) {
                    $library_id = registerFileInLibrary($pdo, $cv_path, $cv_name, (int)$_FILES['cv']['size'], 'Candidate CV — ' . $name, 'hr,recruitment,cv', (int)$_SESSION['user_id'], null, 'private');
                }
            }

            if ($action === 'add') {
                $pdo->prepare("INSERT INTO candidates (opening_id, full_name, email, phone, source, cv_path, cv_name, library_document_id, stage, status, created_by)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'applied', 'active', ?)")
                    ->execute([$opening, $name, ($email!==''?$email:null), ($phone!==''?$phone:null), ($source!==''?$source:null), $cv_path, $cv_name, $library_id, $_SESSION['user_id']]);
                $id = (int)$pdo->lastInsertId();
                logActivity($pdo, $_SESSION['user_id'], 'Add candidate', "candidate '$name' for opening #$opening");
                echo json_encode(['success' => true, 'message' => 'Candidate added', 'candidate_id' => $id]);
            } else {
                if (!$id) throw new Exception('Candidate id is required');
                if ($cv_path !== null) {
                    $pdo->prepare("UPDATE candidates SET opening_id=?, full_name=?, email=?, phone=?, source=?, cv_path=?, cv_name=?, library_document_id=?, updated_by=? WHERE candidate_id=? AND status!='deleted'")
                        ->execute([$opening, $name, ($email!==''?$email:null), ($phone!==''?$phone:null), ($source!==''?$source:null), $cv_path, $cv_name, $library_id, $_SESSION['user_id'], $id]);
                } else {
                    $pdo->prepare("UPDATE candidates SET opening_id=?, full_name=?, email=?, phone=?, source=?, updated_by=? WHERE candidate_id=? AND status!='deleted'")
                        ->execute([$opening, $name, ($email!==''?$email:null), ($phone!==''?$phone:null), ($source!==''?$source:null), $_SESSION['user_id'], $id]);
                }
                logActivity($pdo, $_SESSION['user_id'], 'Update candidate', "candidate #$id");
                echo json_encode(['success' => true, 'message' => 'Candidate updated']);
            }
            break;
        }
        case 'delete': {
            $id = intval($_POST['candidate_id'] ?? 0);
            if (!$id) throw new Exception('Candidate id is required');
            $pdo->prepare("UPDATE candidates SET status='deleted', updated_by=? WHERE candidate_id=?")->execute([$_SESSION['user_id'], $id]);
            echo json_encode(['success' => true, 'message' => 'Candidate removed']);
            break;
        }
        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    if ($cv_path !== null && file_exists(__DIR__ . '/../' . $cv_path)) @unlink(__DIR__ . '/../' . $cv_path);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
