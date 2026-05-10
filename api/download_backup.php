<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

if (!isAdmin()) {
    http_response_code(403);
    die('Unauthorized');
}

if (!isset($_GET['file'])) {
    http_response_code(400);
    die('File not specified');
}

$filename = basename($_GET['file']);
$filePath = ROOT_DIR . '/backups/' . $filename;

// Only allow .sql files from the backups directory
if (!str_ends_with($filename, '.sql') || !file_exists($filePath) || !is_file($filePath)) {
    http_response_code(404);
    die('File not found');
}

while (ob_get_level()) ob_end_clean();
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;
