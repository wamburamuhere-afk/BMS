<?php
// Ensure session is started safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If accessed directly, these might be needed, but via index.php they are redundant-safe
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/permissions.php';

// Phase 5d — canView('backup_restore') admin-bypasses internally and
// makes the audit detect the gate. Replaces the raw isAdmin() check so
// non-admin roles can be delegated via user_roles.php in future.
if (!canView('backup_restore')) {
    header("Location: " . getUrl('unauthorized'));
    exit();
}

if (isset($_GET['file'])) {
    $filename = basename($_GET['file']); // Prevent directory traversal
    $filepath = __DIR__ . '/../../../backups/' . $filename;

    if (file_exists($filepath) && is_file($filepath)) {
        // Clear headers to avoid conflicts
        if (headers_sent()) {
            die('Cannot download file, headers already sent.');
        }

        // Clean output buffer
        while (ob_get_level()) ob_end_clean();

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    } else {
        http_response_code(404);
        die("File not found.");
    }
} else {
    die("No file specified.");
}
?>
