<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');

    $id = $_POST['record_id'] ?? null;

    if (!empty($id) ? !canEdit('compliance') : !canCreate('compliance')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to ' . (!empty($id) ? 'edit' : 'create') . ' compliance records');
    }

    $title = $_POST['title'] ?? '';
    $category = $_POST['category'] ?? '';
    $refNo = $_POST['ref_no'] ?? '';
    $expiryDate = $_POST['expiry_date'] ?? null;
    $notes = $_POST['notes'] ?? '';
    $userId = $_SESSION['user_id'] ?? null;

    if (empty($title)) {
        throw new Exception("Document title is required");
    }

    $filePath = null;
    if (isset($_FILES['doc_file']) && $_FILES['doc_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = ROOT_DIR . '/uploads/compliance/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        // Harden against script execution (security.md §19). uploads/ is
        // gitignored so the .htaccess must be created here.
        $ht = $uploadDir . '.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "<FilesMatch \"\\.(php|php5|phtml|pl|py|jsp|asp|sh|cgi)$\">\n    Require all denied\n</FilesMatch>\nOptions -ExecCGI\nRemoveHandler .php .phtml .php5\nRemoveType .php .phtml .php5\n");
        }

        // Sanitise the filename so the stored path is URL-safe (no spaces or
        // special chars that break the View link). Keep it readable + the ext.
        $origName = basename($_FILES['doc_file']['name']);
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $base     = pathinfo($origName, PATHINFO_FILENAME);
        $safeBase = trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', $base), '_');
        if ($safeBase === '') $safeBase = 'document';
        $filename = time() . '_' . $safeBase . ($ext !== '' ? '.' . $ext : '');
        $targetPath = $uploadDir . $filename;

        if (move_uploaded_file($_FILES['doc_file']['tmp_name'], $targetPath)) {
            $filePath = '/uploads/compliance/' . $filename;
            registerFileInLibrary($pdo, ltrim($filePath, '/'), $_FILES['doc_file']['name'], $_FILES['doc_file']['size'], 'Compliance Document - ' . $title, 'compliance', $userId ?? 0);
        }
    }

    if (!empty($id)) {
        // Update
        if ($filePath) {
            $stmt = $pdo->prepare("UPDATE compliance_records SET title = ?, category = ?, ref_no = ?, expiry_date = ?, file_path = ?, notes = ? WHERE id = ?");
            $stmt->execute([$title, $category, $refNo, $expiryDate ?: null, $filePath, $notes, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE compliance_records SET title = ?, category = ?, ref_no = ?, expiry_date = ?, notes = ? WHERE id = ?");
            $stmt->execute([$title, $category, $refNo, $expiryDate ?: null, $notes, $id]);
        }
        $msg = "Compliance record updated";
        logActivity($pdo, $userId ?? 0, "Updated Compliance Record", "Title: $title (ID: $id)");
    } else {
        // Create
        $stmt = $pdo->prepare("INSERT INTO compliance_records (title, category, ref_no, expiry_date, file_path, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $category, $refNo, $expiryDate ?: null, $filePath, $notes, $userId]);
        $newId = $pdo->lastInsertId();
        $msg = "Compliance record saved";
        logActivity($pdo, $userId ?? 0, "Created Compliance Record", "Title: $title (ID: $newId)");
    }

    echo json_encode(['success' => true, 'message' => $msg]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
