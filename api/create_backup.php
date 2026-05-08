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

$filename = 'bms_backup_' . date('Y-m-d_H-i-s') . '.sql';
$filePath = $backupDir . '/' . $filename;

// In WAMP, we can use the full path to mysqldump
$mysqldumpPath = 'c:\wamp64\bin\mysql\mysql8.4.7\bin\mysqldump.exe';

if (!file_exists($mysqldumpPath)) {
    // Fallback to simpler PHP-based backup if mysqldump is not found
    // (This is a simplified version, usually you'd want a more complete script)
    echo json_encode(['success' => false, 'message' => 'mysqldump not found at ' . $mysqldumpPath]);
    exit;
}

$command = sprintf(
    '"%s" --user=%s --password=%s --host=%s %s > "%s"',
    $mysqldumpPath,
    DB_USERNAME,
    DB_PASSWORD,
    DB_SERVER,
    DB_NAME,
    $filePath
);

// Execute command
exec($command, $output, $returnVar);

if ($returnVar === 0) {
    // Also zip it to save space
    $zipFilename = str_replace('.sql', '.zip', $filename);
    $zipPath = $backupDir . '/' . $zipFilename;
    
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
        $zip->addFile($filePath, $filename);
        $zip->close();
        unlink($filePath); // Remove original SQL file
        
        echo json_encode(['success' => true, 'message' => 'Backup created successfully', 'filename' => $zipFilename]);
    } else {
        echo json_encode(['success' => true, 'message' => 'Backup created as SQL (Zip failed)', 'filename' => $filename]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Backup failed with return code ' . $returnVar]);
}
