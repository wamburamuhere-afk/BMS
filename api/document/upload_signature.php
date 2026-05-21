<?php
/**
 * upload_signature.php — stores a user's uploaded signature image.
 *
 * Hardened per security §19: extension whitelist, real MIME (magic-byte) check,
 * size limit, non-guessable filename, script-blocking .htaccess, activity log.
 * Only PNG/JPEG are accepted — pdf-lib can only embed those when signing.
 */
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['signature_file'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

csrf_check();

try {
    $userId = $_SESSION['user_id'];
    $file   = $_FILES['signature_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload error (code ' . $file['error'] . ')');
    }

    // 1. Whitelist by extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_ext = ['png', 'jpg', 'jpeg'];
    if (!in_array($ext, $allowed_ext, true)) {
        throw new Exception('Invalid file type. Only PNG and JPEG images are allowed.');
    }

    // 2. Whitelist by REAL MIME (magic bytes — never trust $_FILES['type'])
    $finfo     = new finfo(FILEINFO_MIME_TYPE);
    $real_mime = $finfo->file($file['tmp_name']);
    $allowed_mime = ['image/png', 'image/jpeg'];
    if (!in_array($real_mime, $allowed_mime, true)) {
        throw new Exception('File content is not a valid PNG or JPEG image.');
    }

    // 3. Size limit — 2 MB
    if ($file['size'] > 2 * 1024 * 1024) {
        throw new Exception('Signature image exceeds the 2 MB size limit.');
    }

    // 5. Store under uploads/ with .htaccess protection
    $userDir = ROOT_DIR . '/uploads/signatures/' . $userId;
    if (!is_dir($userDir)) {
        mkdir($userDir, 0755, true);
    }
    $htaccess = ROOT_DIR . '/uploads/signatures/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess,
            "<FilesMatch \"\\.(php|php5|phtml|pl|py|jsp|asp|sh|cgi)\$\">\n" .
            "    Require all denied\n" .
            "</FilesMatch>\n" .
            "Options -ExecCGI\n" .
            "RemoveHandler .php .phtml .php5\n" .
            "RemoveType .php .phtml .php5\n"
        );
    }

    // 4. Sanitised, non-guessable filename
    $safeExt  = ($ext === 'jpeg') ? 'jpg' : $ext;
    $filename = 'sig_' . bin2hex(random_bytes(16)) . '.' . $safeExt;
    $filepath = $userDir . '/' . $filename;
    // Leading slash kept to match save_drawn_signature.php and existing signature rows
    $dbPath   = '/uploads/signatures/' . $userId . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to store the uploaded signature.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO user_signatures (user_id, signature_type, file_path, status, created_at)
        VALUES (?, 'uploaded', ?, 'active', NOW())
    ");
    $stmt->execute([$userId, $dbPath]);

    logActivity($pdo, $userId, 'Uploaded an electronic signature image');

    echo json_encode(['success' => true, 'message' => 'Signature uploaded successfully']);

} catch (Exception $e) {
    error_log('upload_signature.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
