<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    csrf_check();

    $signatureData = $_POST['signature_data'] ?? '';
    if (empty($signatureData)) {
        throw new Exception('Signature data is required');
    }

    // Decode base64 PNG from canvas.toDataURL('image/png')
    if (!preg_match('/^data:image\/png;base64,/', $signatureData)) {
        throw new Exception('Invalid signature format');
    }

    $imageData = base64_decode(preg_replace('/^data:image\/png;base64,/', '', $signatureData));
    if ($imageData === false || strlen($imageData) < 8) {
        throw new Exception('Failed to decode signature image');
    }

    $userId  = $_SESSION['user_id'];
    $userDir = ROOT_DIR . '/uploads/signatures/' . $userId;
    if (!is_dir($userDir)) {
        mkdir($userDir, 0755, true);
    }

    $filename = 'signature_drawn_' . bin2hex(random_bytes(8)) . '.png';
    $filepath = $userDir . '/' . $filename;
    $dbPath   = '/uploads/signatures/' . $userId . '/' . $filename;

    if (file_put_contents($filepath, $imageData) === false) {
        throw new Exception('Failed to save signature file');
    }

    $stmt = $pdo->prepare("
        INSERT INTO user_signatures (user_id, signature_type, file_path, status, created_at)
        VALUES (?, 'drawn', ?, 'active', NOW())
    ");
    $stmt->execute([$userId, $dbPath]);

    logActivity($pdo, $userId, 'Saved drawn e-signature');

    echo json_encode(['success' => true, 'message' => 'Signature saved successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
