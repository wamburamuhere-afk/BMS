<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $voucher_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $status = $_POST['status'] ?? '';

    $allowed_statuses = ['draft', 'approved', 'paid', 'cancelled'];
    
    if (!$voucher_id || !in_array($status, $allowed_statuses)) {
        throw new Exception("Invalid parameters.");
    }

    // Handle Paid status extra fields
    $payment_reference = $_POST['payment_reference'] ?? null;
    $attachment_path = null;

    if ($status === 'paid') {
        if (isset($_FILES['attachment_file']) && $_FILES['attachment_file']['error'] == 0) {
            // Get project_id for this voucher
            $p_stmt = $pdo->prepare("SELECT project_id FROM payment_vouchers WHERE id = ?");
            $p_stmt->execute([$voucher_id]);
            $proj_id = $p_stmt->fetchColumn();
            $proj_folder = $proj_id ?: 'general';

            $upload_dir = __DIR__ . "/../../uploads/projects/$proj_folder/vouchers/";
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $file_ext = pathinfo($_FILES['attachment_file']['name'], PATHINFO_EXTENSION);
            $file_name = 'voucher_' . time() . '_' . uniqid() . '.' . $file_ext;
            if (move_uploaded_file($_FILES['attachment_file']['tmp_name'], $upload_dir . $file_name)) {
                $attachment_path = "uploads/projects/$proj_folder/vouchers/" . $file_name;
                registerFileInLibrary($pdo, $attachment_path, $_FILES['attachment_file']['name'], $_FILES['attachment_file']['size'], 'Payment Proof - Voucher #' . $voucher_id, 'voucher,payment,finance', $_SESSION['user_id']);
            }
        }
    }
    
    $sql = "UPDATE payment_vouchers SET status = ?";
    $params = [$status];
    
    if ($payment_reference !== null) {
        $sql .= ", reference_number = ?";
        $params[] = $payment_reference;
    }
    if ($attachment_path !== null) {
        $sql .= ", attachment = ?";
        $params[] = $attachment_path;
    }
    
    $sql .= " WHERE id = ?";
    $params[] = $voucher_id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Phase 3a — voucher state changes are critical financial events
    // (especially the 'paid' transition that records actual cash movement).
    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Updated Payment Voucher Status", "Voucher ID: $voucher_id, new status: $status");

    echo json_encode(['success' => true, 'message' => 'Voucher status updated' . ($status === 'paid' ? ' and payment recorded' : '')]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
