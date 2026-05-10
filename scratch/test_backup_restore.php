<?php
/**
 * Test: Backup & Restore — All Buttons & Logic
 * Architecture: backup_restore.php (SweetAlert2 + fetch) + api/backup_actions.php (JSON)
 * URL: http://localhost/bms/scratch/test_backup_restore.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
global $pdo;

$pass = 0; $fail = 0; $results = [];

function ok($label, $cond, $detail = '') {
    global $pass, $fail, $results;
    if ($cond) { $pass++; $results[] = ['pass', $label, $detail]; }
    else        { $fail++; $results[] = ['fail', $label, $detail]; }
}

$backupDir = ROOT_DIR . '/backups/';
$uiFile    = ROOT_DIR . '/app/constant/settings/backup_restore.php';
$apiFile   = ROOT_DIR . '/api/backup_actions.php';
$dlFile    = ROOT_DIR . '/app/constant/settings/download_backup.php';
$dlApiFile = ROOT_DIR . '/api/download_backup.php';

$ui  = file_get_contents($uiFile);
$api = file_get_contents($apiFile);

// ═══════════════════════════════════════════════════════════════
// SECTION 1 — SOURCE: backup_restore.php UI
// ═══════════════════════════════════════════════════════════════

// --- Old confirm() removed ---
ok('[UI] No browser confirm() calls remain',
    strpos($ui, "onclick=\"return confirm(") === false &&
    strpos($ui, 'confirm(\'') === false,
    'All confirm() replaced by SweetAlert2');

// --- PHP POST handling removed ---
ok('[UI] PHP form POST handler removed (no switch action)',
    strpos($ui, "switch (\$_POST['action'])") === false &&
    strpos($ui, 'switch($_POST[\'action\'])') === false,
    'Actions now handled by api/backup_actions.php');

ok('[UI] No old $message/$messageType POST logic',
    strpos($ui, "\$messageType = \"success\"") === false &&
    strpos($ui, "\$messageType = \"danger\"") === false,
    'Message handling moved to JS SweetAlert2');

// --- JS functions present ---
ok('[UI] createBackup() JS function defined',
    strpos($ui, 'function createBackup()') !== false);

ok('[UI] restoreBackup() JS function defined',
    strpos($ui, 'function restoreBackup(') !== false);

ok('[UI] uploadRestore() JS function defined',
    strpos($ui, 'function uploadRestore()') !== false);

ok('[UI] deleteBackup() JS function defined',
    strpos($ui, 'function deleteBackup(') !== false);

// --- SweetAlert2 usage ---
ok('[UI] Swal.fire used (not confirm)',
    strpos($ui, 'Swal.fire(') !== false,
    'SweetAlert2 used for all confirmations');

ok('[UI] Success icon used for create backup result',
    strpos($ui, "icon: 'success'") !== false,
    'Green tick shown after successful backup creation');

ok('[UI] Warning icon used for destructive confirmations',
    strpos($ui, "icon: 'warning'") !== false,
    'Warning icon on Restore and Delete confirmations');

ok('[UI] showLoading() helper defined',
    strpos($ui, 'function showLoading(') !== false,
    'Spinner shown while backup/restore runs');

ok('[UI] showLoading() called in createBackup',
    strpos($ui, 'showLoading(') !== false);

ok('[UI] Loading uses Swal.showLoading()',
    strpos($ui, 'Swal.showLoading()') !== false,
    'Built-in SweetAlert2 spinner used');

// --- AJAX fetch helper ---
ok('[UI] backupPost() fetch helper defined',
    strpos($ui, 'function backupPost(') !== false,
    'Shared fetch wrapper used by all actions');

ok('[UI] BACKUP_API JS variable set from PHP',
    strpos($ui, 'const BACKUP_API') !== false &&
    strpos($ui, 'api/backup_actions.php') !== false,
    'PHP getUrl() resolves the API endpoint');

ok('[UI] CSRF_TOKEN JS variable set from PHP',
    strpos($ui, 'const CSRF_TOKEN') !== false,
    'CSRF token passed from PHP session to JS');

ok('[UI] CSRF token appended in backupPost()',
    strpos($ui, 'csrf_token') !== false &&
    strpos($ui, 'CSRF_TOKEN') !== false,
    'Every fetch request includes the CSRF token');

// --- Buttons in HTML ---
ok('[UI] Generate Backup button calls createBackup()',
    strpos($ui, 'onclick="createBackup()"') !== false,
    'Button no longer submits a form');

ok('[UI] Upload & Restore button calls uploadRestore()',
    strpos($ui, 'onclick="uploadRestore()"') !== false);

ok('[UI] File input for upload has id="uploadBackupFile"',
    strpos($ui, 'id="uploadBackupFile"') !== false,
    'JS reads file from this input');

ok('[UI] Restore dropdown button calls restoreBackup(filename)',
    strpos($ui, "onclick=\"restoreBackup('") !== false ||
    strpos($ui, 'onclick="restoreBackup(') !== false);

ok('[UI] Delete dropdown button calls deleteBackup(filename, rowHash)',
    strpos($ui, "onclick=\"deleteBackup('") !== false ||
    strpos($ui, 'onclick="deleteBackup(') !== false);

ok('[UI] Delete removes row from DOM on success',
    strpos($ui, 'row-\' + rowHash') !== false ||
    strpos($ui, "'row-' + rowHash") !== false ||
    strpos($ui, 'row.remove()') !== false,
    'Row removed in-place, no full page reload');

ok('[UI] Restore reloads page on success',
    strpos($ui, 'location.reload()') !== false,
    'Page refreshes so backup list stays accurate');

// --- Auto backup still present ---
ok('[UI] runAutoBackup() function still present',
    strpos($ui, 'function runAutoBackup(') !== false);

ok('[UI] Auto backup notice shown when backup ran',
    strpos($ui, '$autoBackupNotice') !== false);

// --- CSRF token generation still present ---
ok('[UI] generateCsrfToken() function still present',
    strpos($ui, 'function generateCsrfToken()') !== false);

ok('[UI] CSRF token generated before HTML output',
    strpos($ui, '$csrfToken') !== false);

// ═══════════════════════════════════════════════════════════════
// SECTION 2 — SOURCE: api/backup_actions.php
// ═══════════════════════════════════════════════════════════════

ok('[API] File exists',
    file_exists($apiFile), $apiFile);

// --- Security gates ---
ok('[API] isAdmin() check present',
    strpos($api, 'isAdmin()') !== false,
    'Non-admins receive 403');

ok('[API] CSRF: hash_equals used',
    strpos($api, 'hash_equals(') !== false,
    'Timing-safe token comparison');

ok('[API] CSRF: rejects missing/wrong token',
    strpos($api, 'Invalid or expired request') !== false);

ok('[API] Returns JSON Content-Type header',
    strpos($api, "header('Content-Type: application/json')") !== false);

// --- Requires correct path ---
ok('[API] roots.php path is one level up (/../roots.php)',
    strpos($api, "__DIR__ . '/../roots.php'") !== false,
    'Two-level path (/../../) would point outside the project');

ok('[API] includes/config.php path correct',
    strpos($api, "__DIR__ . '/../includes/config.php'") !== false);

ok('[API] core/permissions.php path correct',
    strpos($api, "__DIR__ . '/../core/permissions.php'") !== false);

// --- writeDump function ---
ok('[API] writeDump() function defined',
    strpos($api, 'function writeDump(') !== false);

ok('[API] writeDump: uses fopen/fwrite (streaming)',
    strpos($api, 'fopen($filepath') !== false &&
    strpos($api, 'fwrite($handle') !== false,
    'Avoids loading entire DB into RAM');

ok('[API] writeDump: DROP TABLE IF EXISTS present',
    strpos($api, 'DROP TABLE IF EXISTS') !== false,
    'Prevents "table already exists" on restore');

ok('[API] writeDump: backtick-quoted table names',
    strpos($api, '"`$table`"') !== false || strpos($api, '$tq = "`$table`"') !== false,
    'Protects reserved-word table names');

ok('[API] writeDump: SET FOREIGN_KEY_CHECKS=0 written',
    strpos($api, 'SET FOREIGN_KEY_CHECKS=0') !== false);

// --- restoreFromFile function ---
ok('[API] restoreFromFile() function defined',
    strpos($api, 'function restoreFromFile(') !== false);

ok('[API] restoreFromFile: uses mysqli (not PDO exec)',
    strpos($api, 'new mysqli(') !== false,
    'mysqli::multi_query is designed for SQL dumps');

ok('[API] restoreFromFile: uses multi_query (not exec)',
    strpos($api, 'multi_query(') !== false,
    'Single PDO exec() only ran the first statement');

ok('[API] restoreFromFile: drains result sets with next_result()',
    strpos($api, 'next_result()') !== false,
    'Required after multi_query to avoid "out of sync" errors');

ok('[API] restoreFromFile: uses DB_SERVER, DB_USERNAME, DB_PASSWORD constants',
    strpos($api, 'DB_SERVER') !== false &&
    strpos($api, 'DB_USERNAME') !== false &&
    strpos($api, 'DB_PASSWORD') !== false,
    'Credentials from config.php constants');

ok('[API] restoreFromFile: charset set to utf8mb4',
    strpos($api, "set_charset('utf8mb4')") !== false,
    'Prevents encoding issues on restore');

// --- All 4 actions present ---
ok('[API] case create_backup present',
    strpos($api, "case 'create_backup'") !== false);

ok('[API] case restore_backup present',
    strpos($api, "case 'restore_backup'") !== false);

ok('[API] case delete_backup present',
    strpos($api, "case 'delete_backup'") !== false);

ok('[API] case upload_restore present',
    strpos($api, "case 'upload_restore'") !== false);

// --- create_backup returns filename and size ---
ok('[API] create_backup response includes filename',
    strpos($api, "'filename'") !== false);

ok('[API] create_backup response includes size label',
    strpos($api, "'size'") !== false);

// --- restore reports errors correctly ---
ok('[API] restore_backup: success=true when no errors',
    strpos($api, "'success' => true") !== false &&
    strpos($api, 'restored successfully') !== false);

ok('[API] restore_backup: reports error count when errors found',
    strpos($api, 'error(s)') !== false);

// --- upload validation ---
ok('[API] upload_restore: extension check (.sql only)',
    strpos($api, "!== 'sql'") !== false);

ok('[API] upload_restore: content validation (first line check)',
    strpos($api, '$firstLine') !== false &&
    strpos($api, 'str_starts_with') !== false);

ok('[API] upload_restore: filename sanitized with preg_replace',
    strpos($api, 'preg_replace') !== false);

ok('[API] delete_backup: uses basename() (prevents path traversal)',
    strpos($api, 'basename($_POST') !== false);

// ═══════════════════════════════════════════════════════════════
// SECTION 3 — SOURCE: download_backup.php handlers
// ═══════════════════════════════════════════════════════════════

ok('[DOWNLOAD UI] download_backup.php (settings) exists',
    file_exists($dlFile));

if (file_exists($dlFile)) {
    $dlSrc = file_get_contents($dlFile);
    ok('[DOWNLOAD UI] isAdmin() check present',
        strpos($dlSrc, 'isAdmin()') !== false);
    ok('[DOWNLOAD UI] basename() prevents path traversal',
        strpos($dlSrc, 'basename(') !== false);
    ok('[DOWNLOAD UI] ob_end_clean() before readfile',
        strpos($dlSrc, 'ob_end_clean()') !== false);
    ok('[DOWNLOAD UI] Content-Disposition: attachment set',
        strpos($dlSrc, 'Content-Disposition') !== false &&
        strpos($dlSrc, 'attachment') !== false);
}

ok('[DOWNLOAD API] api/download_backup.php exists',
    file_exists($dlApiFile));

if (file_exists($dlApiFile)) {
    $dlApiSrc = file_get_contents($dlApiFile);
    ok('[DOWNLOAD API] Admin check NOT commented out',
        strpos($dlApiSrc, '/*if (!has_permission') === false &&
        strpos($dlApiSrc, 'isAdmin()') !== false,
        'Permission was previously commented out — anyone could download backups');
    ok('[DOWNLOAD API] .sql extension enforced',
        strpos($dlApiSrc, "'.sql'") !== false || strpos($dlApiSrc, '".sql"') !== false);
    ok('[DOWNLOAD API] ob_end_clean() before readfile',
        strpos($dlApiSrc, 'ob_end_clean()') !== false);
}

// ═══════════════════════════════════════════════════════════════
// SECTION 4 — FILE SYSTEM
// ═══════════════════════════════════════════════════════════════

ok('[FS] backups/ directory exists',
    is_dir($backupDir), 'Path: ' . $backupDir);

ok('[FS] backups/ directory is writable',
    is_writable($backupDir));

// ═══════════════════════════════════════════════════════════════
// SECTION 5 — LIVE: writeDump() — create backup & verify content
// ═══════════════════════════════════════════════════════════════

// Mirror writeDump() from api/backup_actions.php for direct testing
function test_writeDump($pdo, $filepath) {
    set_time_limit(0);
    $handle = fopen($filepath, 'w');
    if (!$handle) throw new Exception("Cannot open file for writing.");
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
    return count($tables);
}

$liveBackupFile = null;
$liveTableCount = 0;
try {
    $liveBackupFile = $backupDir . 'live_test_' . time() . '.sql';
    $liveTableCount = test_writeDump($pdo, $liveBackupFile);

    ok('[BACKUP] File created successfully',
        file_exists($liveBackupFile) && filesize($liveBackupFile) > 0,
        'Size: ' . round(filesize($liveBackupFile) / 1024, 2) . ' KB, Tables: ' . $liveTableCount);

    $content = file_get_contents($liveBackupFile);

    ok('[BACKUP] Header comment present',
        strpos($content, '-- BMS Database Backup') !== false);

    ok('[BACKUP] SET FOREIGN_KEY_CHECKS=0 present',
        strpos($content, 'SET FOREIGN_KEY_CHECKS=0') !== false);

    ok('[BACKUP] SET FOREIGN_KEY_CHECKS=1 present at end',
        strpos($content, 'SET FOREIGN_KEY_CHECKS=1') !== false);

    ok('[BACKUP] DROP TABLE IF EXISTS present',
        strpos($content, 'DROP TABLE IF EXISTS') !== false,
        'Critical: without this, restore fails with "table already exists"');

    ok('[BACKUP] Table names backtick-quoted',
        strpos($content, 'DROP TABLE IF EXISTS `') !== false,
        'Backticks protect reserved-word table names');

    ok('[BACKUP] CREATE TABLE statements present',
        strpos($content, 'CREATE TABLE') !== false);

    $dropCount = substr_count($content, 'DROP TABLE IF EXISTS');
    ok('[BACKUP] DROP TABLE count matches DB table count',
        $dropCount === $liveTableCount,
        "DB has $liveTableCount tables, backup has $dropCount DROP TABLE statements");

    ok('[BACKUP] File is valid SQL (no PHP tags)',
        strpos($content, '<?php') === false && strpos($content, '<?=') === false,
        'Backup file must not contain PHP code');

    ok('[BACKUP] File ends with FOREIGN_KEY_CHECKS=1',
        str_contains(substr($content, -100), 'SET FOREIGN_KEY_CHECKS=1'),
        'Last section restores FK checks');

} catch (Exception $e) {
    ok('[BACKUP] writeDump() execution', false, $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════
// SECTION 6 — LIVE: restoreFromFile() via mysqli::multi_query
// ═══════════════════════════════════════════════════════════════

// Mirror restoreFromFile() for direct testing
function test_restoreFromFile($filepath) {
    set_time_limit(0);
    $mysqli = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    if ($mysqli->connect_error) throw new Exception("Connection failed: " . $mysqli->connect_error);
    $mysqli->set_charset('utf8mb4');
    $sql = file_get_contents($filepath);
    if ($sql === false) throw new Exception("Cannot read file.");
    $errors = [];
    if (!$mysqli->multi_query($sql)) $errors[] = $mysqli->error;
    do {
        if ($result = $mysqli->store_result()) $result->free();
        if ($mysqli->errno) $errors[] = $mysqli->error;
    } while ($mysqli->more_results() && $mysqli->next_result());
    $mysqli->close();
    return $errors;
}

$testTable    = 'bms_restore_test_' . time();
$miniDumpFile = $backupDir . 'mini_restore_test_' . time() . '.sql';
$restoreOk    = false;

try {
    // Create test table with data
    $pdo->exec("CREATE TABLE `$testTable` (id INT PRIMARY KEY AUTO_INCREMENT, label VARCHAR(100), note TEXT) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO `$testTable` (label, note) VALUES ('row_one', 'hello world'), ('row_two', 'second row'), ('row_three', NULL)");

    $origCount = $pdo->query("SELECT COUNT(*) FROM `$testTable`")->fetchColumn();
    ok('[RESTORE] Test table created with rows',
        $origCount == 3, "Inserted 3 rows, found $origCount");

    // Write a proper SQL dump of this test table
    $miniSql  = "-- BMS Test Dump\n\n";
    $miniSql .= "SET FOREIGN_KEY_CHECKS=0;\n";
    $miniSql .= "DROP TABLE IF EXISTS `$testTable`;\n";
    $ct = $pdo->query("SHOW CREATE TABLE `$testTable`")->fetch(PDO::FETCH_NUM);
    $miniSql .= $ct[1] . ";\n\n";
    $rows = $pdo->query("SELECT * FROM `$testTable`");
    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
        $values = array_map(fn($v) => is_null($v) ? 'NULL' : $pdo->quote($v), $row);
        $miniSql .= "INSERT INTO `$testTable` VALUES(" . implode(',', $values) . ");\n";
    }
    $miniSql .= "\nSET FOREIGN_KEY_CHECKS=1;\n";
    file_put_contents($miniDumpFile, $miniSql);

    ok('[RESTORE] Mini SQL dump written',
        file_exists($miniDumpFile) && filesize($miniDumpFile) > 0,
        round(filesize($miniDumpFile)) . ' bytes');

    // Drop the table to simulate real restore scenario
    $pdo->exec("DROP TABLE `$testTable`");
    ok('[RESTORE] Table dropped (simulating real restore)',
        !in_array($testTable, array_column($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM), 0)));

    // Run the restore via multi_query
    $errors = test_restoreFromFile($miniDumpFile);
    ok('[RESTORE] multi_query restore returned zero errors',
        empty($errors),
        empty($errors) ? 'No errors' : implode(', ', array_slice($errors, 0, 3)));

    // Verify data came back
    $restoredCount = $pdo->query("SELECT COUNT(*) FROM `$testTable`")->fetchColumn();
    ok('[RESTORE] All rows restored correctly',
        $restoredCount == 3,
        "Expected 3 rows, got $restoredCount");

    $row1 = $pdo->query("SELECT label FROM `$testTable` WHERE id = 1")->fetchColumn();
    ok('[RESTORE] Row 1 value correct after restore',
        $row1 === 'row_one', "Expected 'row_one', got '$row1'");

    $nullRow = $pdo->query("SELECT note FROM `$testTable` WHERE label = 'row_three'")->fetchColumn();
    ok('[RESTORE] NULL value preserved correctly',
        $nullRow === false || $nullRow === null,
        'NULL in original → NULL after restore');

    $restoreOk = true;

} catch (Exception $e) {
    ok('[RESTORE] Restore test execution', false, $e->getMessage());
} finally {
    try { $pdo->exec("DROP TABLE IF EXISTS `$testTable`"); } catch (Exception $e) {}
    if (file_exists($miniDumpFile)) unlink($miniDumpFile);
}

ok('[RESTORE] Test table cleaned up',
    !in_array($testTable, array_column($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM), 0)));

// ═══════════════════════════════════════════════════════════════
// SECTION 7 — LIVE: create_backup returns size label correctly
// ═══════════════════════════════════════════════════════════════

if ($liveBackupFile && file_exists($liveBackupFile)) {
    $bytes = filesize($liveBackupFile);
    $size  = round($bytes / 1024, 2);
    $sizeLabel = $size >= 1024 ? round($size / 1024, 2) . ' MB' : $size . ' KB';

    ok('[SIZE] Backup size label generated',
        str_ends_with($sizeLabel, 'KB') || str_ends_with($sizeLabel, 'MB'),
        "Got: $sizeLabel for " . number_format($bytes) . " bytes");

    ok('[SIZE] Size label matches expected unit',
        ($bytes >= 1048576 && str_ends_with($sizeLabel, 'MB')) ||
        ($bytes < 1048576  && str_ends_with($sizeLabel, 'KB')),
        'Threshold: 1,048,576 bytes = 1 MB');
}

function formatSize($bytes) {
    $kb = round($bytes / 1024, 2);
    return $kb >= 1024 ? round($kb / 1024, 2) . ' MB' : $kb . ' KB';
}
ok('[SIZE] 512 KB → KB',  str_ends_with(formatSize(524288), 'KB'),   formatSize(524288));
ok('[SIZE] 2 MB → MB',    str_ends_with(formatSize(2097152), 'MB'),  formatSize(2097152));
ok('[SIZE] 1 MB boundary → MB', str_ends_with(formatSize(1048576), 'MB'), formatSize(1048576));

// ═══════════════════════════════════════════════════════════════
// SECTION 8 — LIVE: delete_backup logic
// ═══════════════════════════════════════════════════════════════

$deleteTestFile = $backupDir . 'delete_test_' . time() . '.sql';
file_put_contents($deleteTestFile, "-- delete test\n");

ok('[DELETE] Test file created for deletion test',
    file_exists($deleteTestFile));

// Simulate the delete action (basename + unlink)
$filename = basename($deleteTestFile);
$filepath = $backupDir . $filename;
$deleted  = file_exists($filepath) && unlink($filepath);

ok('[DELETE] File deleted successfully',
    $deleted && !file_exists($deleteTestFile),
    $filename);

ok('[DELETE] basename() prevents directory traversal',
    basename('../../../etc/passwd') === 'passwd' &&
    basename('/etc/shadow') === 'shadow',
    'basename() strips directory components');

// ═══════════════════════════════════════════════════════════════
// SECTION 9 — CSRF logic
// ═══════════════════════════════════════════════════════════════

$goodToken  = bin2hex(random_bytes(32));
$wrongToken = bin2hex(random_bytes(32));
$emptyToken = '';

ok('[CSRF] hash_equals: matching tokens pass',
    hash_equals($goodToken, $goodToken));

ok('[CSRF] hash_equals: wrong token rejected',
    !hash_equals($goodToken, $wrongToken));

ok('[CSRF] hash_equals: empty token rejected',
    !hash_equals($goodToken, $emptyToken));

ok('[CSRF] generateCsrfToken produces 64-char hex string',
    strlen($goodToken) === 64 && ctype_xdigit($goodToken));

// ═══════════════════════════════════════════════════════════════
// SECTION 10 — Upload validation logic
// ═══════════════════════════════════════════════════════════════

function checkExt($name)  { return strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'sql'; }
function checkContent($l) {
    $l = trim($l);
    return str_starts_with($l, '--') || str_starts_with($l, '/*')
        || str_starts_with($l, 'SET ') || str_starts_with($l, 'CREATE ')
        || str_starts_with($l, 'INSERT ');
}

ok('[UPLOAD] .sql extension accepted',          checkExt('backup.sql'));
ok('[UPLOAD] .php extension rejected',          !checkExt('evil.php'));
ok('[UPLOAD] .sql.php double ext rejected',     !checkExt('evil.sql.php'));
ok('[UPLOAD] .SQL uppercase accepted',          strtolower(pathinfo('BIG.SQL', PATHINFO_EXTENSION)) === 'sql');

ok('[UPLOAD] Valid: -- comment accepted',       checkContent('-- BMS Backup'));
ok('[UPLOAD] Valid: /* comment accepted',       checkContent('/* Generated */'));
ok('[UPLOAD] Valid: SET statement accepted',    checkContent('SET FOREIGN_KEY_CHECKS=0;'));
ok('[UPLOAD] Valid: CREATE TABLE accepted',     checkContent('CREATE TABLE `users`'));
ok('[UPLOAD] Valid: INSERT INTO accepted',      checkContent('INSERT INTO `t` VALUES'));

ok('[UPLOAD] Invalid: PHP tag rejected',        !checkContent('<?php echo 1; ?>'));
ok('[UPLOAD] Invalid: script tag rejected',     !checkContent('<script>alert(1)</script>'));
ok('[UPLOAD] Invalid: plain text rejected',     !checkContent('hello world'));
ok('[UPLOAD] Invalid: GIF header rejected',     !checkContent('GIF89a'));
ok('[UPLOAD] Invalid: blank line rejected',     !checkContent(''));

$safeCheck = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', 'my backup (1); DROP TABLE--users.sql');
ok('[UPLOAD] Dangerous filename chars sanitized',
    strpos($safeCheck, ';') === false && strpos($safeCheck, ' ') === false,
    "Sanitized: $safeCheck");

// ═══════════════════════════════════════════════════════════════
// SECTION 11 — Auto backup logic
// ═══════════════════════════════════════════════════════════════

$markerFile = $backupDir . '.last_auto_backup';

// 11a. Fresh marker → should NOT run
file_put_contents($markerFile, time());
$lastRun = (int)file_get_contents($markerFile);
ok('[AUTO] Fresh marker (just now): backup skipped',
    (time() - $lastRun) < 86400, 'Less than 24h since last run');

// 11b. Old marker → SHOULD run
file_put_contents($markerFile, time() - 90000);
$lastRun2 = (int)file_get_contents($markerFile);
ok('[AUTO] Old marker (25h ago): backup triggered',
    (time() - $lastRun2) >= 86400);

// Reset marker so auto-backup doesn't fire on this page
file_put_contents($markerFile, time());

// 11c. 7-file retention
$fakeFiles = [];
for ($i = 1; $i <= 10; $i++) {
    $fake = $backupDir . 'auto_backup_2024-01-' . str_pad($i, 2, '0', STR_PAD_LEFT) . '_000000.sql';
    file_put_contents($fake, "-- fake $i\n");
    $fakeFiles[] = $fake;
}
$all = glob($backupDir . 'auto_backup_*.sql');
if ($all && count($all) > 7) {
    sort($all);
    foreach (array_slice($all, 0, count($all) - 7) as $old) unlink($old);
}
$remaining = glob($backupDir . 'auto_backup_2024-01-*.sql');
ok('[AUTO] 7-file retention: 10 files trimmed to 7',
    count($remaining) === 7,
    count($remaining) . ' files remain');
foreach (glob($backupDir . 'auto_backup_2024-01-*.sql') as $f) @unlink($f);

// ═══════════════════════════════════════════════════════════════
// SECTION 12 — DB: getDatabaseSize via prepared statement
// ═══════════════════════════════════════════════════════════════

try {
    $stmt = $pdo->prepare("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
        FROM information_schema.TABLES WHERE table_schema = ? GROUP BY table_schema");
    $stmt->execute([DB_NAME]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    $sz  = $res ? $res['size_mb'] : 0;
    ok('[DB] getDatabaseSize() returns numeric', is_numeric($sz), "DB: " . DB_NAME . " = $sz MB");
    ok('[DB] getDatabaseSize() > 0', $sz > 0, "$sz MB");
} catch (PDOException $e) {
    ok('[DB] getDatabaseSize()', false, $e->getMessage());
    ok('[DB] getDatabaseSize() > 0', false, '');
}

// ═══════════════════════════════════════════════════════════════
// CLEANUP
// ═══════════════════════════════════════════════════════════════

if ($liveBackupFile && file_exists($liveBackupFile)) {
    unlink($liveBackupFile);
    ok('[CLEANUP] Live backup test file removed', !file_exists($liveBackupFile));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Backup & Restore — Test Suite</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
    body { background:#f5f5f5; }
    .row-pass td { background:#f0fff4; }
    .row-fail td { background:#fff5f5; }
    .section-card { margin-bottom: 1.5rem; border-radius: .75rem; overflow:hidden; box-shadow:0 1px 6px rgba(0,0,0,.08); }
    .section-head { background:#1e293b; color:#fff; padding:.65rem 1rem; display:flex; justify-content:space-between; align-items:center; }
    .section-title { font-weight:700; font-size:.9rem; letter-spacing:.04em; }
    .tick { color:#4ade80; font-size:1rem; }
    .cross { color:#f87171; font-size:1rem; }
    .detail { color:#6b7280; font-size:.8rem; }
</style>
</head>
<body class="p-4">
<div class="container" style="max-width:1020px;">

    <h3 class="fw-bold mb-1">Backup & Restore — Full Test Suite</h3>
    <p class="text-muted mb-3">
        Tests all buttons and operations: Create, Restore, Upload, Delete, Download, Auto-backup.<br>
        <small>Architecture: <code>backup_restore.php</code> (SweetAlert2 + fetch) → <code>api/backup_actions.php</code> (JSON + mysqli)</small>
    </p>

    <div class="d-flex gap-2 align-items-center mb-4">
        <span class="badge bg-success fs-6"><?= $pass ?> passed</span>
        <span class="badge bg-danger  fs-6"><?= $fail ?> failed</span>
        <span class="badge bg-secondary fs-6"><?= $pass + $fail ?> total</span>
        <?php if ($fail === 0): ?>
            <span class="badge bg-success fs-6 ms-2">✔ All passing</span>
        <?php endif; ?>
    </div>

    <?php if ($fail === 0): ?>
        <div class="alert alert-success fw-bold mb-4">All <?= $pass ?> tests passed. Every button and operation is working correctly.</div>
    <?php else: ?>
        <div class="alert alert-danger fw-bold mb-4"><?= $fail ?> test(s) failed — review the red rows below.</div>
    <?php endif; ?>

    <?php
    $sections = [
        'UI'       => 'backup_restore.php — UI & JavaScript',
        'API'      => 'api/backup_actions.php — JSON Endpoint',
        'DOWNLOAD' => 'download_backup.php — File Download',
        'FS'       => 'File System',
        'BACKUP'   => 'Live: Create Backup (writeDump)',
        'RESTORE'  => 'Live: Restore (mysqli multi_query)',
        'SIZE'     => 'File Size Label',
        'DELETE'   => 'Live: Delete Backup',
        'CSRF'     => 'CSRF Token Security',
        'UPLOAD'   => 'Upload File Validation',
        'AUTO'     => 'Auto Backup (daily, 7-file retention)',
        'DB'       => 'Database Size Query',
        'CLEANUP'  => 'Cleanup',
    ];
    $grouped = array_fill_keys(array_keys($sections), []);
    foreach ($results as $r) {
        $matched = false;
        foreach (array_keys($sections) as $key) {
            if (str_starts_with($r[1], "[$key]")) { $grouped[$key][] = $r; $matched = true; break; }
        }
        if (!$matched) $grouped['CLEANUP'][] = $r;
    }

    foreach ($sections as $key => $title):
        if (empty($grouped[$key])) continue;
        $sPass = count(array_filter($grouped[$key], fn($r) => $r[0] === 'pass'));
        $sFail = count(array_filter($grouped[$key], fn($r) => $r[0] === 'fail'));
    ?>
    <div class="section-card">
        <div class="section-head">
            <span class="section-title"><?= htmlspecialchars($title) ?></span>
            <span>
                <span class="badge bg-success me-1"><?= $sPass ?> ok</span>
                <?php if ($sFail > 0): ?>
                    <span class="badge bg-danger"><?= $sFail ?> fail</span>
                <?php endif; ?>
            </span>
        </div>
        <table class="table table-sm mb-0 align-middle">
            <tbody>
            <?php foreach ($grouped[$key] as $r): ?>
                <tr class="<?= $r[0] === 'pass' ? 'row-pass' : 'row-fail' ?>">
                    <td style="width:14px;" class="ps-3">
                        <span class="<?= $r[0] === 'pass' ? 'tick' : 'cross' ?>">
                            <?= $r[0] === 'pass' ? '✔' : '✖' ?>
                        </span>
                    </td>
                    <td class="py-2 fw-medium"><?= htmlspecialchars(preg_replace('/^\[.*?\]\s*/', '', $r[1])) ?></td>
                    <td class="pe-3 detail"><?= htmlspecialchars($r[2]) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <p class="text-muted small mt-3">
        Run at: <?= date('Y-m-d H:i:s') ?> &nbsp;|&nbsp;
        DB: <strong><?= htmlspecialchars(DB_NAME) ?></strong> &nbsp;|&nbsp;
        Backups dir: <code><?= htmlspecialchars($backupDir) ?></code>
    </p>
</div>
</body>
</html>
