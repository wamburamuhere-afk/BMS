<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

// Check permissions
/*if (!has_permission('manage_settings')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}*/

$backupDir = ROOT_DIR . '/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}

$backups = [];
$files = scandir($backupDir, SCANDIR_SORT_DESCENDING);

foreach ($files as $file) {
    if ($file === '.' || $file === '..' || is_dir($backupDir . '/' . $file)) {
        continue;
    }
    
    $filePath = $backupDir . '/' . $file;
    $backups[] = [
        'filename' => $file,
        'date' => date('Y-m-d H:i:s', filemtime($filePath)),
        'size' => formatSizeUnits(filesize($filePath))
    ];
}

function formatSizeUnits($bytes) {
    if ($bytes >= 1073741824) {
        $bytes = number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        $bytes = number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        $bytes = number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        $bytes = $bytes . ' bytes';
    } elseif ($bytes == 1) {
        $bytes = $bytes . ' byte';
    } else {
        $bytes = '0 bytes';
    }
    return $bytes;
}

echo json_encode(['success' => true, 'backups' => $backups]);
