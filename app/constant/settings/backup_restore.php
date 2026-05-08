<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../core/permissions.php';
require_once __DIR__ . '/../../../header.php';

// Check admin permissions
if (!isAdmin()) {
    header("Location: unauthorized.php");
    exit();
}

$backupsDir = __DIR__ . '/../../../backups/';
if (!is_dir($backupsDir)) {
    mkdir($backupsDir, 0755, true);
}

$message = '';
$messageType = '';

// Helper function to get database size
function getDatabaseSize($pdo) {
    try {
        $stmt = $pdo->query("SELECT table_schema AS 'Database', 
            ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'Size (MB)' 
            FROM information_schema.TABLES 
            WHERE table_schema = '" . DB_NAME . "' 
            GROUP BY table_schema");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['Size (MB)'] : 0;
    } catch (Exception $e) {
        return 0;
    }
}

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_backup':
                try {
                    $filename = 'bms_backup_' . date('Y-m-d_H-i-s') . '.sql';
                    $filepath = $backupsDir . $filename;
                    
                    $tables = [];
                    $stmt = $pdo->query("SHOW TABLES");
                    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                        $tables[] = $row[0];
                    }

                    $sql = "-- BMS Database Backup\n";
                    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
                    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
                    $sql .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n\n";

                    foreach ($tables as $table) {
                        $row2 = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM);
                        $sql .= "\n\n" . $row2[1] . ";\n\n";

                        $rows = $pdo->query("SELECT * FROM $table");
                        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                            $sql .= "INSERT INTO $table VALUES(";
                            $values = [];
                            foreach ($row as $value) {
                                $values[] = is_null($value) ? "NULL" : $pdo->quote($value);
                            }
                            $sql .= implode(',', $values);
                            $sql .= ");\n";
                        }
                    }

                    $sql .= "\nSET FOREIGN_KEY_CHECKS=1;\n";

                    if (file_put_contents($filepath, $sql)) {
                        $message = "Backup created successfully: $filename";
                        $messageType = "success";
                    } else {
                        throw new Exception("Failed to write backup file.");
                    }
                } catch (Exception $e) {
                    $message = "Error creating backup: " . $e->getMessage();
                    $messageType = "danger";
                }
                break;

            case 'delete_backup':
                $filename = basename($_POST['filename']);
                $filepath = $backupsDir . $filename;
                if (file_exists($filepath) && is_file($filepath)) {
                    unlink($filepath);
                    $message = "Backup deleted successfully.";
                    $messageType = "success";
                } else {
                    $message = "File not found.";
                    $messageType = "danger";
                }
                break;

            case 'restore_backup':
                $filename = basename($_POST['filename']);
                $filepath = $backupsDir . $filename;
                
                if (file_exists($filepath)) {
                    try {
                        // Increase time limit for large restores
                        set_time_limit(300);
                        
                        $sql = file_get_contents($filepath);
                        
                        // Disable foreign keys temporarily
                        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
                        
                        // Execute the SQL dump
                        $pdo->exec($sql);
                        
                        // Re-enable foreign keys
                        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

                        $message = "Database restored successfully from $filename";
                        $messageType = "success";
                    } catch (Exception $e) {
                        $message = "Restore failed: " . $e->getMessage();
                        $messageType = "danger";
                    }
                } else {
                    $message = "Backup file not found.";
                    $messageType = "danger";
                }
                break;
                
            case 'upload_restore':
                if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($_FILES['backup_file']['name'], PATHINFO_EXTENSION);
                    if (strtolower($ext) !== 'sql') {
                        $message = "Invalid file type. Only .sql files allowd.";
                        $messageType = "danger";
                    } else {
                        // Move to backups directory
                        $filename = 'uploaded_' . date('Ymd_His') . '_' . basename($_FILES['backup_file']['name']);
                        $destination = $backupsDir . $filename;
                        
                        if (move_uploaded_file($_FILES['backup_file']['tmp_name'], $destination)) {
                            // Now triggering restore logic similar to above
                            try {
                                set_time_limit(300);
                                $sql = file_get_contents($destination);
                                $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
                                $pdo->exec($sql);
                                $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
                                
                                $message = "Backup uploaded and restored successfully.";
                                $messageType = "success";
                            } catch (Exception $e) {
                                $message = "Restore failed: " . $e->getMessage();
                                $messageType = "danger";
                            }
                        } else {
                            $message = "Failed to upload file.";
                            $messageType = "danger";
                        }
                    }
                } else {
                    $message = "No file selected or upload error.";
                    $messageType = "danger";
                }
                break;
        }
    }
}

// List backups
$backups = array_filter(glob($backupsDir . '*.sql'), 'is_file');
rsort($backups); // Newest first

// Get DB size
$dbSize = getDatabaseSize($pdo);
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12 mt-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-0"><i class="bi bi-hdd-network"></i> Backup & Restore</h2>
                    <p class="text-muted">Manage database backups and system restoration points</p>
                </div>
                <div>
                    <span class="badge bg-info p-2 rounded-pill">Database Size: <?= $dbSize ?> MB</span>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row">
        <!-- Create Backup Card -->
        <div class="col-md-4 mb-4">
            <div class="card border-0 shadow-sm h-100 rounded-4">
                <div class="card-body p-4 text-center">
                    <div class="mb-3">
                        <i class="bi bi-cloud-arrow-down text-primary" style="font-size: 3rem;"></i>
                    </div>
                    <h5 class="fw-bold">Create New Backup</h5>
                    <p class="text-muted small mb-4">Generate a complete snapshot of your current database state.</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="create_backup">
                        <button type="submit" class="btn btn-primary w-100 py-2">
                            <i class="bi bi-plus-circle me-2"></i>Generate Backup
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Restore Upload Card -->
        <div class="col-md-4 mb-4">
            <div class="card border-0 shadow-sm h-100 rounded-4">
                <div class="card-body p-4 text-center">
                    <div class="mb-3">
                        <i class="bi bi-cloud-arrow-up text-success" style="font-size: 3rem;"></i>
                    </div>
                    <h5 class="fw-bold">Restore from File</h5>
                    <p class="text-muted small mb-4">Upload a .sql file to restore your database to a previous state.</p>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="upload_restore">
                        <div class="input-group mb-3">
                            <input type="file" class="form-control" name="backup_file" accept=".sql" required>
                        </div>
                        <button type="submit" class="btn btn-success w-100 py-2" onclick="return confirm('WARNING: This will overwrite your current database. Are you sure?');">
                            <i class="bi bi-upload me-2"></i>Upload & Restore
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Info Card -->
        <div class="col-md-4 mb-4">
            <div class="card border-0 shadow-sm h-100 rounded-4 bg-light">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3"><i class="bi bi-info-circle text-primary me-2"></i>Important Notes</h5>
                    <ul class="text-muted small ps-3 mb-0">
                        <li class="mb-2">Restoring a backup will <strong>overwrite</strong> all current data.</li>
                        <li class="mb-2">It is recommended to create a new backup before restoring an old one.</li>
                        <li>Large backups may take several minutes to restore depending on server performance.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Existing Backups List -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white p-4 border-0 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold">Existing Backups</h5>
                    <span class="badge bg-secondary"><?= count($backups) ?> Files</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-muted small text-uppercase fw-bold">
                                <tr>
                                    <th class="ps-4">Filename</th>
                                    <th>Date Created</th>
                                    <th>Size</th>
                                    <th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($backups)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-5 text-muted">
                                            <i class="bi bi-archive display-4 d-block mb-3 opacity-25"></i>
                                            No backups found. Create one to get started.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($backups as $backup): 
                                        $filename = basename($backup);
                                        $filesize = round(filesize($backup) / 1024, 2); // KB
                                        $filedate = date('d M Y, h:i A', filemtime($backup));
                                    ?>
                                        <tr>
                                            <td class="ps-4 fw-bold text-dark">
                                                <i class="bi bi-file-earmark-code text-secondary me-2"></i><?= $filename ?>
                                            </td>
                                            <td class="text-muted"><?= $filedate ?></td>
                                            <td><?= $filesize ?> KB</td>
                                            <td class="text-end pe-4">
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="bi bi-gear"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <li>
                                                            <form method="POST" class="d-inline">
                                                                <input type="hidden" name="action" value="restore_backup">
                                                                <input type="hidden" name="filename" value="<?= $filename ?>">
                                                                <button type="submit" class="dropdown-item" onclick="return confirm('Are you sure you want to restore this backup? Current data will be lost.');">
                                                                    <i class="bi bi-clock-history me-2 text-warning"></i> Restore
                                                                </button>
                                                            </form>
                                                        </li>
                                                        <li>
                                                            <a href="<?= getUrl('download_backup') ?>?file=<?= urlencode($filename) ?>" class="dropdown-item">
                                                                <i class="bi bi-download me-2 text-primary"></i> Download
                                                            </a>
                                                        </li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li>
                                                            <form method="POST" class="d-inline">
                                                                <input type="hidden" name="action" value="delete_backup">
                                                                <input type="hidden" name="filename" value="<?= $filename ?>">
                                                                <button type="submit" class="dropdown-item text-danger" onclick="return confirm('Are you sure you want to delete this backup file?');">
                                                                    <i class="bi bi-trash me-2"></i> Delete
                                                                </button>
                                                            </form>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
require_once __DIR__ . '/../../../footer.php';
ob_end_flush(); 
?>
