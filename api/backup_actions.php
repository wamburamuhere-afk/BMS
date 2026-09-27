<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/backup.php';

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
// on the same files. Direct HTTP access is blocked by a deny-all .htaccess —
// downloads are served via PHP readfile() in the gated download routes.
//
// bmsBackupDir() rather than ROOT_DIR . '/backups/': every tenant subdomain is
// served from the SAME webroot, so the literal path is shared by all of them —
// any tenant could list and download every other tenant's full database dump.
// The legacy install keeps the unprefixed path; tenants get their own.
require_once __DIR__ . '/../core/tenant_bootstrap.php';
$backupsDir = bmsBackupDir();

$action = $_POST['action'] ?? '';

// Dump logic now lives in core/backup.php (bms_write_dump) — shared by the
// page, this API and the nightly cron, and it handles VIEWS correctly.
// Thin alias kept so the rest of this file reads unchanged.
function writeDump($pdo, $filepath) {
    bms_write_dump($pdo, $filepath);
}

// ─────────────────────────────────────────────
// Helper: restore SQL file via mysqli multi_query
// ─────────────────────────────────────────────
function restoreFromFile($filepath) {
    set_time_limit(0);

    // Turn off strict mysqli exceptions so individual statement failures
    // are collected as errors rather than thrown as uncatchable exceptions.
    mysqli_report(MYSQLI_REPORT_OFF);

    // ─────────────────────────────────────────────────────────────────────────
    // THE DATABASE THIS REQUEST OWNS — never the DB_* constants.
    //
    // This line used to read:
    //     new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME)
    // The dump above is correctly written from the tenant's own $pdo, but the
    // restore connected to the MAIN database. So a tenant pressing Restore
    // dumped THEIR data and wrote it over the MAIN company's database — and
    // because every CREATE TABLE below is preceded by DROP TABLE IF EXISTS,
    // it destroyed it rather than merely polluting it.
    //
    // That is not hypothetical: on 2026-09-02 tenant #9002 did exactly this and
    // wiped the demo host's `bejundas_main`. Recovered from a 31-Aug dump.
    // See tenant_isolation_plan.md, "INCIDENT".
    //
    // mysqli (not $pdo) is genuinely required here for multi_query, so what is
    // needed is credentials — which is precisely what bmsCurrentDbConfig()
    // exists to hand out. It throws rather than falling back.
    // ─────────────────────────────────────────────────────────────────────────
    $db = bmsCurrentDbConfig();

    // tenant-audit: skip — this mysqli is REQUIRED (PDO cannot multi_query a
    // dump) and is correct: every value comes from bmsCurrentDbConfig(), i.e.
    // the database this request owns, never the DB_* constants.
    $mysqli = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
    if ($mysqli->connect_error) {
        throw new Exception("DB connection failed: " . $mysqli->connect_error);
    }
    $mysqli->set_charset('utf8mb4');

    $sql = file_get_contents($filepath);
    if ($sql === false) throw new Exception("Cannot read backup file.");

    // ─────────────────────────────────────────────────────────────────────────
    // Upgrade legacy dumps in memory before restoring them.
    //
    // Every dump written before 2026-09-03 uses `INSERT INTO t VALUES(...)`
    // with no column list, which supplies a value for GENERATED columns. MySQL
    // rejects those rows, and because multi_query STOPS at the first failing
    // statement, every table after the first offender is silently skipped — the
    // restore reports "1 error" while having loaded only part of the database.
    // Fixing the writer cannot help files already on disk, so they are fixed
    // here. Also strips the DEFINER from CREATE VIEW, which otherwise makes
    // MySQL demand SYSTEM_USER when a tenant restores a dump written by another
    // account. Both observed live on 2026-09-03.
    // ─────────────────────────────────────────────────────────────────────────
    $upgrade = bms_upgrade_legacy_dump($sql);
    if ($upgrade['rows'] > 0 || $upgrade['sql'] !== $sql) {
        error_log('restoreFromFile: upgraded legacy dump ' . basename($filepath)
            . ' — rewrote ' . $upgrade['rows'] . ' row(s) across '
            . (count($upgrade['tables']) ?: 0) . ' table(s): '
            . implode(', ', $upgrade['tables']));
        $sql = $upgrade['sql'];
    }

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

    // Drain all result sets — required after multi_query.
    //
    // The loop below used to be `while ($mysqli->more_results() && $mysqli->next_result())`,
    // which SILENTLY LOSES the error that stopped the restore: a failing
    // next_result() returns false and exits the loop before anything reads
    // $mysqli->error. A restore could therefore abort halfway and report
    // nothing. Each result is now checked explicitly, including the failing one.
    do {
        if ($result = $mysqli->store_result()) $result->free();
        if ($mysqli->errno) $errors[] = $mysqli->error;
        if (!$mysqli->more_results()) break;
        if (!$mysqli->next_result()) {
            if ($mysqli->errno) $errors[] = $mysqli->error;
            break;
        }
    } while (true);

    $mysqli->close();
    return $errors;
}

// ─────────────────────────────────────────────
// ACTIONS
// ─────────────────────────────────────────────
switch ($action) {

    // ── CREATE BACKUP (full ZIP: DB + uploads/) ────────────────────
    case 'create_backup':
        try {
            $filename   = 'bms_backup_' . date('Y-m-d_H-i-s') . '.zip';
            $filepath   = $backupsDir . $filename;
            $uploadsDir = ROOT_DIR . '/uploads';
            $stats      = bms_write_zip_backup($pdo, $filepath, $uploadsDir);

            $bytes      = filesize($filepath);
            $sizeLabel  = $bytes >= 1048576
                ? round($bytes / 1048576, 2) . ' MB'
                : round($bytes / 1024, 2) . ' KB';

            logActivity($pdo, $_SESSION['user_id'], "Created Full Backup",
                "File: $filename, Size: $sizeLabel, DB: {$stats['db_size_mb']} MB, Files: {$stats['files_count']}");

            echo json_encode([
                'success'     => true,
                'message'     => "Full backup created successfully (database + {$stats['files_count']} uploaded file(s)).",
                'filename'    => $filename,
                'size'        => $sizeLabel,
                'files_count' => $stats['files_count'],
            ]);
        } catch (Exception $e) {
            if (isset($filepath) && file_exists($filepath)) @unlink($filepath);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // ── RESTORE FROM EXISTING BACKUP (.zip or .sql) ────────────────
    case 'restore_backup':
        $filename = basename($_POST['filename'] ?? '');
        $filepath = $backupsDir . $filename;

        if (!$filename || !file_exists($filepath)) {
            echo json_encode(['success' => false, 'message' => 'Backup file not found.']);
            break;
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['sql', 'zip'], true)) {
            echo json_encode(['success' => false, 'message' => 'Unsupported backup format. Only .zip and .sql files are accepted.']);
            break;
        }

        // Safety net: DB snapshot before overwriting — SQL only (fast; captures
        // the data risk; file-only changes are additive so no file pre-restore needed).
        // A truncated pre-restore dump is deleted and the restore is aborted —
        // same policy as the original; see the 2026-09-03 incident note above.
        $preRestorePath = $backupsDir . 'pre_restore_' . date('Y-m-d_H-i-s') . '.sql';
        try {
            bms_write_dump($pdo, $preRestorePath);
        } catch (Exception $e) {
            if (is_file($preRestorePath)) @unlink($preRestorePath);
            echo json_encode(['success' => false, 'message' => 'Aborted — could not create a pre-restore safety backup: ' . $e->getMessage()]);
            break;
        }

        try {
            $filesCopied = 0;
            if ($ext === 'zip') {
                $extracted = bms_extract_zip_backup($filepath);
                try {
                    $errors = restoreFromFile($extracted['sql_path']);
                    if (empty($errors) && $extracted['uploads_path'] !== null) {
                        $filesCopied = bms_copy_dir($extracted['uploads_path'], ROOT_DIR . '/uploads');
                    }
                } finally {
                    bms_delete_dir($extracted['temp_dir']);
                }
                $manifest = $extracted['manifest'];
            } else {
                $errors = restoreFromFile($filepath);
            }

            if (empty($errors)) {
                $msg = ($ext === 'zip')
                    ? "Database and {$filesCopied} uploaded file(s) restored successfully from $filename."
                    : "Database restored successfully from $filename. Note: this was a database-only backup — uploaded files were not included.";
                logActivity($pdo, $_SESSION['user_id'], "Restored Backup", "File: $filename, Files copied: $filesCopied");
                echo json_encode(['success' => true, 'message' => $msg,
                    'files_copied' => $filesCopied, 'format' => $ext]);
            } else {
                $count = count($errors);
                error_log("Restore errors from $filename: " . implode(' | ', array_slice($errors, 0, 10)));
                $preview = implode('; ', array_slice($errors, 0, 3));
                echo json_encode([
                    'success' => false,
                    'message' => "Restore completed with $count error(s): $preview",
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
        if (!in_array($ext, ['sql', 'zip'], true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid file type. Only .sql and .zip backup files are allowed.']);
            break;
        }

        // Content validation by magic bytes / first-line inspection
        $tmpPath = $_FILES['backup_file']['tmp_name'];
        if ($ext === 'zip') {
            // ZIP magic: PK\x03\x04
            $fh = fopen($tmpPath, 'rb');
            $magic = fread($fh, 4);
            fclose($fh);
            if ($magic !== "PK\x03\x04") {
                echo json_encode(['success' => false, 'message' => 'File does not appear to be a valid ZIP archive.']);
                break;
            }
        } else {
            // SQL: first non-empty line must look like a SQL statement
            $tmpHandle = fopen($tmpPath, 'r');
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
        }

        $safeOrigName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', basename($_FILES['backup_file']['name']));
        $filename = 'uploaded_' . date('Ymd_His') . '_' . $safeOrigName;
        $destination = $backupsDir . $filename;

        if (!move_uploaded_file($_FILES['backup_file']['tmp_name'], $destination)) {
            echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file.']);
            break;
        }

        // Safety net: snapshot the current state before the uploaded restore.
        $preRestorePath = $backupsDir . 'pre_restore_' . date('Y-m-d_H-i-s') . '.sql';
        try {
            bms_write_dump($pdo, $preRestorePath);
        } catch (Exception $e) {
            // Delete the half-written file. bms_write_dump() streams row by row,
            // so a failure partway leaves a TRUNCATED dump on disk — and a
            // truncated dump is the most dangerous artefact this system can
            // produce: it lists in the UI like any other restore point, and
            // restoring it silently loses everything past the cut. Observed for
            // real as pre_restore_2026-09-03_09-49-54.sql, which survived a
            // failed snapshot and sat in the backup list looking valid.
            if (is_file($preRestorePath)) @unlink($preRestorePath);
            echo json_encode(['success' => false, 'message' => 'Aborted — could not create a pre-restore safety backup: ' . $e->getMessage()]);
            break;
        }

        try {
            $filesCopied = 0;
            if ($ext === 'zip') {
                $extracted = bms_extract_zip_backup($destination);
                try {
                    $errors = restoreFromFile($extracted['sql_path']);
                    if (empty($errors) && $extracted['uploads_path'] !== null) {
                        $filesCopied = bms_copy_dir($extracted['uploads_path'], ROOT_DIR . '/uploads');
                    }
                } finally {
                    bms_delete_dir($extracted['temp_dir']);
                }
            } else {
                $errors = restoreFromFile($destination);
            }

            if (empty($errors)) {
                $msg = ($ext === 'zip')
                    ? "File uploaded. Database and {$filesCopied} uploaded file(s) restored successfully."
                    : "File uploaded and database restored successfully. Note: this was a database-only backup — uploaded files were not included.";
                logActivity($pdo, $_SESSION['user_id'], "Uploaded & Restored Backup", "File: $filename, Files copied: $filesCopied");
                echo json_encode(['success' => true, 'message' => $msg,
                    'files_copied' => $filesCopied, 'format' => $ext]);
            } else {
                $count = count($errors);
                error_log("Upload restore errors from $filename: " . implode(' | ', array_slice($errors, 0, 10)));
                $preview = implode('; ', array_slice($errors, 0, 3));
                echo json_encode([
                    'success' => false,
                    'message' => "Restore completed with $count error(s): $preview",
                ]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
