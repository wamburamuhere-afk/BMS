<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    $id = $_POST['record_id'] ?? null;
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
            mkdir($uploadDir, 0777, true);
        }

        $filename = time() . '_' . basename($_FILES['doc_file']['name']);
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
