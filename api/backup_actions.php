<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/permissions.php';

header('Content-Type: application/json');
error_reporting(E_ERROR | E_PARSE);

// canDelete admin-bypasses internally; future non-admin roles can be delegated via user_roles.php.
// Use canDelete (broadest verb) since backup_actions covers create/restore/delete/upload paths.
if (!canDelete('backup_restore')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to manage system backups']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// CSRF — uses the canonical global helper from helpers.php (§21).
// Accepts the token from either POST['_csrf'] or the X-CSRF-Token header.
csrf_check();

// Single source of truth for the backup directory. MUST match the path used
// by app/constant/settings/backup_restore.php (table + auto-backup) and
// api/download_backup.php so create/list/download/restore/delete all operate
// on the same files. Direct HTTP access is blocked by backups/.htaccess —
// downloads are served via PHP readfile() in the gated download routes.
$backupsDir = ROOT_DIR . '/backups/';
if (!is_dir($backupsDir)) mkdir($backupsDir, 0755, true);

$action = $_POST['action'] ?? '';

// ─────────────────────────────────────────────
// Helper: write SQL dump to file (streaming)
// ─────────────────────────────────────────────
function writeDump($pdo, $filepath) {
    set_time_limit(0);
    $handle = fopen($filepath, 'w');
    if (!$handle) throw new Exception("Cannot open file for writing: $filepath");

    $tables = [];
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) $tables[] = $row[0];

    fwrite($handle, "-- BMS Database Backup\n");
    fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n\n");
    fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($handle, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n\n");

    foreach ($tables as $table) {
        $tq = "`$table`";
        $row2 = $pdo->query("SHOW CREATE TABLE $tq")->fetch(PDO::FETCH_NUM);
        fwrite($handle, "\nDROP TABLE IF EXISTS $tq;\n");
        fwrite($handle, $row2[1] . ";\n\n");
        $rows = $pdo->query("SELECT * FROM $tq");
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $values = array_map(fn($v) => is_null($v) ? 'NULL' : $pdo->quote($v), $row);
            fwrite($handle, "INSERT INTO $tq VALUES(" . implode(',', $values) . ");\n");
        }
        fwrite($handle, "\n");
    }

    fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    fclose($handle);
}

// ─────────────────────────────────────────────
// Helper: restore SQL file via mysqli multi_query
// ─────────────────────────────────────────────
function restoreFromFile($filepath) {
    set_time_limit(0);

    // Turn off strict mysqli exceptions so individual statement failures
    // are collected as errors rather than thrown as uncatchable exceptions.
    mysqli_report(MYSQLI_REPORT_OFF);

    $mysqli = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    if ($mysqli->connect_error) {
        throw new Exception("DB connection failed: " . $mysqli->connect_error);
    }
    $mysqli->set_charset('utf8mb4');

    $sql = file_get_contents($filepath);
    if ($sql === false) throw new Exception("Cannot read backup file.");

    // Guarantee every CREATE TABLE is preceded by DROP TABLE IF EXISTS.
    // Old backups created before the current fix did not include this line,
    // causing "table already exists" errors on restore.
    $sql = preg_replace_callback(
        '/\bCREATE TABLE\s+(`[^`]+`|\w+)/i',
        fn($m) => "DROP TABLE IF EXISTS {$m[1]};\nCREATE TABLE {$m[1]}",
        $sql
    );

    $errors = [];
    if (!$mysqli->multi_query($sql)) {
        $errors[] = $mysqli->error;
    }

    // Drain all result sets — required after multi_query
    do {
        if ($result = $mysqli->store_result()) $result->free();
        if ($mysqli->errno) $errors[] = $mysqli->error;
    } while ($mysqli->more_results() && $mysqli->next_result());

    $mysqli->close();
    return $errors;
}

// ─────────────────────────────────────────────
// ACTIONS
// ─────────────────────────────────────────────
switch ($action) {

    // ── CREATE BACKUP ──────────────────────────
    case 'create_backup':
        try {
            $filename = 'bms_backup_' . date('Y-m-d_H-i-s') . '.sql';
            $filepath = $backupsDir . $filename;
            writeDump($pdo, $filepath);
            $size = round(filesize($filepath) / 1024, 2);
            $sizeLabel = $size >= 1024 ? round($size / 1024, 2) . ' MB' : $size . ' KB';

            logActivity($pdo, $_SESSION['user_id'], "Created Database Backup", "File: $filename, Size: $sizeLabel");

            echo json_encode([
                'success'  => true,
                'message'  => "Backup created successfully.",
                'filename' => $filename,
                'size'     => $sizeLabel
            ]);
        } catch (Exception $e) {
            if (isset($filepath) && file_exists($filepath)) unlink($filepath);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // ── RESTORE FROM EXISTING BACKUP ───────────
    case 'restore_backup':
        $filename = basename($_POST['filename'] ?? '');
        $filepath = $backupsDir . $filename;

        if (!$filename || !file_exists($filepath)) {
            echo json_encode(['success' => false, 'message' => 'Backup file not found.']);
            break;
        }

        try {
            $errors = restoreFromFile($filepath);
            if (empty($errors)) {
                logActivity($pdo, $_SESSION['user_id'], "Restored Database Backup", "File: $filename");
                echo json_encode(['success' => true, 'message' => "Database restored successfully from $filename."]);
            } else {
                $count = count($errors);
                error_log("Restore errors from $filename: " . implode(' | ', array_slice($errors, 0, 10)));
                echo json_encode([
                    'success' => false,
                    'message' => "Restore completed with $count error(s). Check the server error log for details."
                ]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()]);
        }
        break;

    // ── DELETE BACKUP ──────────────────────────
    case 'delete_backup':
        $filename = basename($_POST['filename'] ?? '');
        $filepath = $backupsDir . $filename;

        if (!$filename || !file_exists($filepath)) {
            echo json_encode(['success' => false, 'message' => 'File not found.']);
            break;
        }
        if (unlink($filepath)) {
            logActivity($pdo, $_SESSION['user_id'], "Deleted Database Backup", "File: $filename");
            echo json_encode(['success' => true, 'message' => "$filename deleted."]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete file.']);
        }
        break;

    // ── UPLOAD & RESTORE ───────────────────────
    case 'upload_restore':
        // When post_max_size is exceeded PHP empties $_FILES and $_POST entirely
        if (empty($_FILES)) {
            $maxPost = ini_get('post_max_size');
            echo json_encode(['success' => false,
                'message' => "Upload failed: the file is too large for the server. Current post_max_size is $maxPost. Increase it in php.ini (post_max_size and upload_max_filesize), then restart Apache."]);
            break;
        }

        if (!isset($_FILES['backup_file'])) {
            echo json_encode(['success' => false, 'message' => 'No file received by the server.']);
            break;
        }

        if ($_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            $phpUploadErrors = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize (' . ini_get('upload_max_filesize') . ') in php.ini — increase it and restart Apache.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds the MAX_FILE_SIZE directive in the HTML form.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded. Try again.',
                UPLOAD_ERR_NO_FILE    => 'No file was selected.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder for uploads.',
                UPLOAD_ERR_CANT_WRITE => 'Server failed to write the uploaded file to disk.',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
            ];
            $code = $_FILES['backup_file']['error'];
            $msg  = $phpUploadErrors[$code] ?? "PHP upload error code: $code";
            echo json_encode(['success' => false, 'message' => $msg]);
            break;
        }

        $ext = strtolower(pathinfo($_FILES['backup_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'sql') {
            echo json_encode(['success' => false, 'message' => 'Invalid file type. Only .sql files allowed.']);
            break;
        }

        // Content validation — first non-empty line must look like SQL
        $tmpHandle = fopen($_FILES['backup_file']['tmp_name'], 'r');
        $firstLine = '';
        while (!feof($tmpHandle) && trim($firstLine) === '') $firstLine = fgets($tmpHandle);
        fclose($tmpHandle);
        $firstLine = trim($firstLine);
        $validStart = str_starts_with($firstLine, '--') || str_starts_with($firstLine, '/*')
                   || str_starts_with($firstLine, 'SET ') || str_starts_with($firstLine, 'CREATE ')
                   || str_starts_with($firstLine, 'INSERT ');
        if (!$validStart) {
            echo json_encode(['success' => false, 'message' => 'File does not appear to be a valid SQL dump.']);
            break;
        }

        $safeOrigName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', basename($_FILES['backup_file']['name']));
        $filename = 'uploaded_' . date('Ymd_His') . '_' . $safeOrigName;
        $destination = $backupsDir . $filename;

        if (!move_uploaded_file($_FILES['backup_file']['tmp_name'], $destination)) {
            echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file.']);
            break;
        }

        try {
            $errors = restoreFromFile($destination);
            if (empty($errors)) {
                logActivity($pdo, $_SESSION['user_id'], "Uploaded & Restored Database Backup", "File: $filename");
                echo json_encode(['success' => true, 'message' => "File uploaded and database restored successfully."]);
            } else {
                $count = count($errors);
                error_log("Upload restore errors: " . implode(' | ', array_slice($errors, 0, 10)));
                echo json_encode([
                    'success' => false,
                    'message' => "Restore completed with $count error(s). Check the server error log."
                ]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
