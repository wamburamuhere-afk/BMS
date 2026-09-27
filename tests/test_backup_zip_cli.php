<?php
/**
 * Backup Full ZIP — Functional & Invariant Test Suite
 *
 * Verifies every layer of the full-backup upgrade:
 *  - core/backup.php: new ZIP helpers present and syntactically valid
 *  - api/backup_actions.php: handles .zip and .sql for all actions;
 *    exposes actual error text (not just "check server log")
 *  - app/constant/settings/backup_restore.php: lists .zip; correct icons;
 *    JS accepts .zip; Important Notes updated
 *  - cron/auto_backup.php: calls bms_write_zip_backup (not bms_write_dump)
 *  - Functional: actually creates a ZIP fixture and validates extraction
 *
 * Run:  php tests/test_backup_zip_cli.php
 *   Exit 0 = all pass
 *   Exit 1 = failures
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root     = dirname(__DIR__);
$failures = 0;
$passes   = 0;

function pass(string $m): void    { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void    { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }

echo "\n\033[1m═══ Backup Full ZIP — Invariant & Functional Tests ═══\033[0m\n";

$coreFile  = $root . '/core/backup.php';
$apiFile   = $root . '/api/backup_actions.php';
$uiFile    = $root . '/app/constant/settings/backup_restore.php';
$cronFile  = $root . '/cron/auto_backup.php';

// ─────────────────────────────────────────────────────────────────────────────
section('1. All target files exist and pass php -l');
// ─────────────────────────────────────────────────────────────────────────────

foreach ([$coreFile, $apiFile, $uiFile, $cronFile] as $f) {
    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $f);
    check(is_file($f), "$rel exists", "$rel is missing");
    $out = []; $code = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $code);
    check($code === 0, "$rel passes php -l", "$rel has syntax errors: " . implode(' | ', $out));
}

$core = is_file($coreFile) ? file_get_contents($coreFile) : '';
$api  = is_file($apiFile)  ? file_get_contents($apiFile)  : '';
$ui   = is_file($uiFile)   ? file_get_contents($uiFile)   : '';
$cron = is_file($cronFile) ? file_get_contents($cronFile) : '';

// ─────────────────────────────────────────────────────────────────────────────
section('2. core/backup.php — new ZIP helper functions declared');
// ─────────────────────────────────────────────────────────────────────────────

foreach ([
    'bms_write_zip_backup'  => 'full-backup writer (DB + uploads/ + manifest)',
    'bms_extract_zip_backup'=> 'ZIP extractor / validator for restore',
    'bms_copy_dir'          => 'recursive directory copier for upload restore',
    'bms_delete_dir'        => 'temp directory cleanup after restore',
] as $fn => $desc) {
    check(
        (bool) preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\(/', $core),
        "core/backup.php declares $fn() — $desc",
        "core/backup.php is missing $fn() — $desc"
    );
}

// ─────────────────────────────────────────────────────────────────────────────
section('3. core/backup.php — bms_prune_backups handles .zip patterns');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($core, 'auto_backup_*.zip'),
    'bms_prune_backups() prunes auto_backup_*.zip files',
    'bms_prune_backups() does not prune auto_backup_*.zip — old ZIP auto-backups will accumulate'
);
check(
    str_contains($core, 'pre_restore_*.zip'),
    'bms_prune_backups() prunes pre_restore_*.zip files',
    'bms_prune_backups() does not prune pre_restore_*.zip — old ZIPs will accumulate'
);

// ─────────────────────────────────────────────────────────────────────────────
section('4. core/backup.php — bms_write_zip_backup quality invariants');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($core, 'ZipArchive'),
    'bms_write_zip_backup uses ZipArchive',
    'bms_write_zip_backup does not reference ZipArchive — ZIP creation is broken'
);
check(
    str_contains($core, 'manifest.json'),
    'bms_write_zip_backup writes a manifest.json into the archive',
    'bms_write_zip_backup does not write manifest.json — restore cannot validate the archive'
);
check(
    str_contains($core, 'database.sql'),
    'bms_write_zip_backup adds database.sql to the archive',
    'bms_write_zip_backup does not include database.sql — archive is not restorable'
);
check(
    str_contains($core, 'bms_backup_format'),
    'manifest.json includes bms_backup_format version field',
    'manifest.json missing bms_backup_format — format version cannot be validated on restore'
);
check(
    str_contains($core, 'uploads/'),
    'bms_write_zip_backup adds uploads/ directory contents',
    'bms_write_zip_backup does not add uploads/ — uploaded files will be missing from backup'
);
// Symlink guard: files outside uploadsDir must be rejected
check(
    str_contains($core, "strpos(\$realFile, \$prefix)"),
    'bms_write_zip_backup has symlink guard (strpos check against uploadsDir prefix)',
    'bms_write_zip_backup lacks symlink guard — a symlink in uploads/ could pull in arbitrary files'
);
// Partial-archive cleanup on failure
check(
    (bool) preg_match('/if\s*\(\s*is_file\s*\(\s*\$zipPath\s*\)\s*\)\s*@unlink\s*\(\s*\$zipPath\s*\)/', $core),
    'bms_write_zip_backup deletes partial .zip on failure',
    'bms_write_zip_backup does not clean up a partial .zip on error — corrupt files will appear in the backup list'
);

// ─────────────────────────────────────────────────────────────────────────────
section('5. core/backup.php — bms_extract_zip_backup quality invariants');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($core, "locateName('database.sql')"),
    'bms_extract_zip_backup checks database.sql exists before extraction',
    'bms_extract_zip_backup does not validate database.sql presence — restore could fail silently'
);
check(
    str_contains($core, "locateName('manifest.json')"),
    'bms_extract_zip_backup checks manifest.json exists before extraction',
    'bms_extract_zip_backup does not validate manifest.json — non-BMS ZIPs could be used for restore'
);
check(
    str_contains($core, 'bms_backup_format'),
    'bms_extract_zip_backup validates manifest bms_backup_format field',
    'bms_extract_zip_backup does not check bms_backup_format — corrupted manifests accepted'
);
check(
    str_contains($core, 'bms_delete_dir'),
    'bms_extract_zip_backup calls bms_delete_dir on failure to clean up temp dir',
    'bms_extract_zip_backup does not clean up temp dir on failure — disk leak'
);
check(
    str_contains($core, 'bin2hex(random_bytes(8))'),
    'bms_extract_zip_backup uses a random suffix for the temp directory name',
    'bms_extract_zip_backup uses a predictable temp directory name — path collision risk'
);

// ─────────────────────────────────────────────────────────────────────────────
section('6. api/backup_actions.php — create_backup writes .zip');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($api, "bms_write_zip_backup("),
    'create_backup action calls bms_write_zip_backup()',
    'create_backup action does not call bms_write_zip_backup() — still creates .sql only'
);
check(
    (bool) preg_match("/'bms_backup_' \. date\('Y-m-d_H-i-s'\) \. '\.zip'/", $api),
    "create_backup generates a .zip filename",
    "create_backup still generates a .sql filename — format not upgraded"
);
check(
    str_contains($api, 'ROOT_DIR . \'/uploads\''),
    'create_backup passes ROOT_DIR/uploads to bms_write_zip_backup',
    'create_backup does not pass uploads directory — files will not be included in backup'
);
check(
    str_contains($api, 'files_count'),
    'create_backup response includes files_count for the success dialog',
    'create_backup response is missing files_count — UI cannot show how many files were backed up'
);

// ─────────────────────────────────────────────────────────────────────────────
section('7. api/backup_actions.php — restore_backup handles .zip and .sql');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($api, "bms_extract_zip_backup("),
    'restore_backup calls bms_extract_zip_backup() for ZIP files',
    'restore_backup does not call bms_extract_zip_backup() — .zip restores will fail'
);
check(
    str_contains($api, "bms_copy_dir("),
    'restore_backup calls bms_copy_dir() to restore uploaded files',
    'restore_backup does not call bms_copy_dir() — files will not be restored from ZIP'
);
check(
    str_contains($api, "bms_delete_dir("),
    'restore_backup calls bms_delete_dir() to clean up after extraction',
    'restore_backup does not clean up temp extraction directory — disk leak on every restore'
);
check(
    (bool) preg_match("/in_array\s*\(\s*\\\$ext\s*,\s*\['sql',\s*'zip'\]/", $api),
    'restore_backup validates extension is sql or zip',
    'restore_backup does not check extension — any file type could be passed as a backup'
);
// Backward compat: .sql still works
check(
    str_contains($api, '$ext === \'sql\'') || str_contains($api, "\$ext === 'zip'"),
    'restore_backup branches on $ext to support both .zip and .sql',
    'restore_backup does not branch by extension — .sql backward compat may be broken'
);

// ─────────────────────────────────────────────────────────────────────────────
section('8. api/backup_actions.php — upload_restore accepts .zip');
// ─────────────────────────────────────────────────────────────────────────────

check(
    (bool) preg_match("/in_array\s*\(\s*\\\$ext\s*,\s*\['sql',\s*'zip'\]/", $api),
    'upload_restore extension check accepts both sql and zip',
    'upload_restore still only accepts .sql — .zip uploads will be rejected'
);
check(
    (bool) preg_match('/PK\\\\x03\\\\x04/', $api),
    'upload_restore validates ZIP magic bytes (PK\\x03\\x04) for .zip uploads',
    'upload_restore does not check ZIP magic bytes — arbitrary files could be accepted as ZIP backups'
);

// ─────────────────────────────────────────────────────────────────────────────
section('9. api/backup_actions.php — error messages include actual error text');
// ─────────────────────────────────────────────────────────────────────────────

check(
    !str_contains($api, 'Check the server error log for details'),
    'restore errors no longer say "Check the server error log" — actual error text is shown',
    'restore still says "Check the server error log" — user cannot see what failed'
);
check(
    str_contains($api, '$preview = implode'),
    'restore_backup builds a $preview of the first few errors for the response',
    'restore_backup does not build an error preview — error details are still hidden from the user'
);
check(
    str_contains($api, 'array_slice($errors, 0, 3)'),
    'restore_backup includes up to 3 error messages in the JSON response',
    'restore_backup does not slice errors for the response — too many errors could flood the UI'
);

// ─────────────────────────────────────────────────────────────────────────────
section('10. app/constant/settings/backup_restore.php — UI covers .zip');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($ui, "glob(\$backupsDir . '*.zip')"),
    'backup listing glob includes *.zip files',
    'backup listing glob does not include *.zip — ZIP backups will not appear in the list'
);
check(
    str_contains($ui, 'accept=".sql,.zip"'),
    'file input accepts both .sql and .zip',
    'file input still only accepts .sql — users cannot upload ZIP backups via the browser'
);
check(
    str_contains($ui, "ext !== 'sql' && ext !== 'zip'"),
    'JS upload validation accepts both sql and zip extensions',
    'JS upload validation still only accepts .sql — .zip uploads will be rejected client-side'
);
check(
    str_contains($ui, 'bi-file-zip'),
    'backup listing shows bi-file-zip icon for .zip files',
    'backup listing does not differentiate icons — .zip and .sql look identical'
);
check(
    str_contains($ui, 'Full backup'),
    'backup listing shows "Full backup" badge for .zip files',
    'backup listing does not show a type badge — user cannot tell which backups include files'
);
check(
    str_contains($ui, 'DB only'),
    'backup listing shows "DB only" badge for .sql files',
    'backup listing does not show "DB only" badge — user may think .sql backups restore files'
);
check(
    str_contains($ui, '.zip backups'),
    'Important Notes card explains .zip vs .sql backup scope',
    'Important Notes card does not mention .zip — user has no way to know what is restored'
);
check(
    str_contains($ui, 'bms_write_zip_backup'),
    'runAutoBackup() calls bms_write_zip_backup (not bms_write_dump)',
    'runAutoBackup() still calls bms_write_dump — auto-backups do not include uploaded files'
);

// ─────────────────────────────────────────────────────────────────────────────
section('11. app/constant/settings/backup_restore.php — restore dialog shows scope');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($ui, 'isZip') && str_contains($ui, 'scopeNote'),
    'restoreBackup() and uploadRestore() show a scope note (DB+files vs DB only)',
    'restore confirmation dialog does not show scope — user does not know what will be restored'
);

// ─────────────────────────────────────────────────────────────────────────────
section('12. cron/auto_backup.php — creates .zip backup');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($cron, 'bms_write_zip_backup'),
    'cron/auto_backup.php calls bms_write_zip_backup()',
    'cron/auto_backup.php still calls bms_write_dump() — scheduled backups do not include files'
);
check(
    (bool) preg_match("/'auto_backup_' \. date\('Y-m-d_H-i-s'\) \. '\.zip'/", $cron),
    'cron generates auto_backup_*.zip filename',
    'cron still generates auto_backup_*.sql — cron and UI produce different formats'
);
check(
    str_contains($cron, "'/uploads'"),
    'cron passes uploads directory to bms_write_zip_backup',
    'cron does not pass uploads dir — files omitted from scheduled backups'
);

// ─────────────────────────────────────────────────────────────────────────────
section('13. Functional — ZipArchive availability and fixture ZIP creation');
// ─────────────────────────────────────────────────────────────────────────────

if (!class_exists('ZipArchive')) {
    fail('ZipArchive class is not available — enable php_zip in php.ini and restart Apache/PHP-FPM. All ZIP operations will fail at runtime.');
} else {
    pass('ZipArchive class is available');

    // Build a fixture ZIP that looks like a real BMS backup
    $tmpDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_test_' . uniqid();
    @mkdir($tmpDir, 0700, true);
    $zipPath = $tmpDir . DIRECTORY_SEPARATOR . 'fixture.zip';

    $manifest = json_encode([
        'bms_backup_format' => '1.0',
        'created_at'        => date('c'),
        'db_size_mb'        => 1.23,
        'files_count'       => 2,
        'files_size_mb'     => 0.01,
    ], JSON_PRETTY_PRINT);
    $sqlContent = "-- BMS Database Backup\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n\nSET FOREIGN_KEY_CHECKS=1;\n";

    $zip = new ZipArchive();
    $ok  = $zip->open($zipPath, ZipArchive::CREATE);
    check($ok === true, 'ZipArchive::open() for fixture ZIP succeeded', "ZipArchive::open() failed with code $ok");

    if ($ok === true) {
        $zip->addFromString('database.sql',  $sqlContent);
        $zip->addFromString('manifest.json', $manifest);
        $zip->addFromString('uploads/test/file1.txt', 'hello');
        $zip->addFromString('uploads/test/file2.txt', 'world');
        $zip->close();
        check(is_file($zipPath) && filesize($zipPath) > 0, 'Fixture ZIP was created and is non-empty', 'Fixture ZIP creation failed');

        // Now test bms_extract_zip_backup() using the fixture
        require_once $coreFile;

        try {
            $extracted = bms_extract_zip_backup($zipPath);
            pass('bms_extract_zip_backup() succeeded on a valid fixture ZIP');
            check(is_file($extracted['sql_path']), 'Extracted database.sql exists at sql_path', 'database.sql not found at sql_path after extraction');
            check(is_dir($extracted['uploads_path'] ?? ''), 'Extracted uploads/ directory exists at uploads_path', 'uploads/ directory not found after extraction');
            check(is_array($extracted['manifest']) && ($extracted['manifest']['bms_backup_format'] ?? '') === '1.0', 'manifest.json decoded correctly from extracted backup', 'manifest.json missing or format field incorrect');
            check(is_dir($extracted['temp_dir']), 'temp_dir path returned and directory exists', 'temp_dir not returned or does not exist');

            // Test bms_copy_dir
            $destDir = $tmpDir . DIRECTORY_SEPARATOR . 'dest_uploads';
            $count   = bms_copy_dir($extracted['uploads_path'], $destDir);
            check($count === 2, "bms_copy_dir() copied 2 files from the extracted uploads/", "bms_copy_dir() copied $count files (expected 2)");
            check(is_file($destDir . '/test/file1.txt'), 'file1.txt was copied to destination', 'file1.txt not found in destination after bms_copy_dir');

            // Test cleanup
            bms_delete_dir($extracted['temp_dir']);
            check(!is_dir($extracted['temp_dir']), 'bms_delete_dir() removed the temp extraction directory', 'bms_delete_dir() did not remove the temp directory — disk leak');

        } catch (Throwable $e) {
            fail('bms_extract_zip_backup() threw an unexpected exception: ' . $e->getMessage());
        }

        // Test that bms_extract_zip_backup() rejects a non-BMS ZIP (missing manifest)
        $badZipPath = $tmpDir . DIRECTORY_SEPARATOR . 'bad.zip';
        $badZip = new ZipArchive();
        $badZip->open($badZipPath, ZipArchive::CREATE);
        $badZip->addFromString('some_random_file.txt', 'not a backup');
        $badZip->close();
        try {
            bms_extract_zip_backup($badZipPath);
            fail('bms_extract_zip_backup() should have thrown for a non-BMS ZIP (missing database.sql)');
        } catch (Exception $e) {
            pass('bms_extract_zip_backup() correctly rejects a ZIP missing database.sql: "' . $e->getMessage() . '"');
        }

        // Clean up test artifacts
        @unlink($zipPath);
        @unlink($badZipPath);
        if (isset($destDir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($destDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            @rmdir($destDir);
        }
        @rmdir($tmpDir);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
section('14. Backward compatibility — .sql restore still wired');
// ─────────────────────────────────────────────────────────────────────────────

check(
    str_contains($api, "restoreFromFile(\$filepath)") || str_contains($api, "restoreFromFile(\$extracted['sql_path'])"),
    'restoreFromFile() is still called — .sql restore path remains intact',
    'restoreFromFile() is no longer called — .sql backup restore is broken'
);
// The four canonical actions must all still be present
foreach (['create_backup', 'restore_backup', 'delete_backup', 'upload_restore'] as $act) {
    check(
        (bool) preg_match('/case\s+[\'"]' . $act . '[\'"]\s*:/', $api),
        "api/backup_actions.php still handles '$act' action",
        "api/backup_actions.php lost the '$act' action — this button is now dead"
    );
}

// ─────────────────────────────────────────────────────────────────────────────
section('15. Security — permission gates, CSRF, and path safety intact');
// ─────────────────────────────────────────────────────────────────────────────

check(
    (bool) preg_match("/canDelete\\s*\\(\\s*['\"]backup_restore['\"]\\s*\\)/", $api),
    'api/backup_actions.php permission gate still present',
    'api/backup_actions.php lost its permission gate — any authenticated user could restore'
);
check(
    (bool) preg_match('/\bcsrf_check\s*\(\s*\)\s*;/', $api),
    'api/backup_actions.php still calls csrf_check()',
    'api/backup_actions.php lost csrf_check() — CSRF protection is OFF'
);
check(
    str_contains($api, 'basename($_POST[\'filename\'] ?? \'\')'),
    'restore_backup uses basename() to prevent path traversal',
    'restore_backup does not sanitise the filename — directory traversal is possible'
);
// ZipArchive extraction uses a randomised temp dir — no predictable path
check(
    str_contains($core, 'bms_restore_' . '') || str_contains($core, 'bms_restore_'),
    'temp extraction directory name is prefixed (not a bare random string)',
    'temp extraction directory is not prefixed — harder to identify BMS temp dirs for cleanup'
);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m═════════════════════════════════════════════\033[0m\n";
echo "Passes: $passes  Failures: $failures\n";
if ($failures === 0) {
    echo "\033[32m✅ Backup Full ZIP — all invariants intact.\033[0m\n\n";
    exit(0);
}
echo "\033[31m❌ Backup Full ZIP — see failures above.\033[0m\n\n";
exit(1);
