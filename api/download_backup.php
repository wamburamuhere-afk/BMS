<?php
require_once __DIR__ . '/../../roots.php';

// Check permissions
/*if (!has_permission('manage_settings')) {
    die('Unauthorized');
}*/

if (!isset($_GET['file'])) {
    die('File not specified');
}

$filename = basename($_GET['file']);
$filePath = ROOT_DIR . '/backups/' . $filename;

if (file_exists($filePath)) {
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
} else {
    die('File not found');
}
