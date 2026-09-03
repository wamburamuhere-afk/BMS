<?php
/**
 * Tenant resource audit — CLI test
 *   php tests/test_tenant_resource_audit_cli.php
 *
 * A RATCHET, in the same shape as tests/test_project_scope_cli.php: it counts
 * the places that still name a database or a directory for themselves, and
 * fails if that count goes UP. Existing offenders are grandfathered; new ones
 * are blocked. Each baseline below is a debt to drive to zero, never to raise.
 *
 * WHY THIS EXISTS. On 2026-09-02 a tenant pressed Restore and the MAIN database
 * was dropped and overwritten, because api/backup_actions.php built its own
 * mysqli from the DB_* constants instead of asking which database the request
 * owned. Phase 9 of the multi-tenancy rollout had proved isolation at the MySQL
 * grant layer and stopped there — it never checked for application code that
 * bypasses $pdo entirely. This is that missing check.
 *
 *     Application code must never name a database or a directory.
 *     It asks the request which one it is on.
 *
 * THE SANCTIONED ACCESSORS (core/tenant_bootstrap.php):
 *     bmsCurrentDbConfig()   host/user/pass/name for this request
 *     bmsCurrentDbName()     instead of reading DB_NAME
 *     bmsUploadsDir($sub)    absolute uploads path        — write here
 *     bmsUploadsRel($sub)    relative uploads path        — store this
 *     bmsBackupDir()         absolute backup directory
 *
 * ESCAPE HATCH. A file that genuinely must do its own thing carries
 *     // tenant-audit: skip — <reason>
 * on any line. Use it sparingly and always state the reason.
 *
 * Exit 0 = pass.
 */
$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    exit($fail === 0 ? 0 : 1);
});

// ── what we scan ────────────────────────────────────────────────────────────
$scanDirs = ['app', 'api', 'ajax', 'actions', 'core', 'cron'];

// The connection/resolution layer itself is allowed to name things — it is what
// every other file asks. Tests and one-off scripts are out of scope.
$exemptFiles = [
    'core/tenant_bootstrap.php',      // publishes the accessors
    'core/tenant_resolver.php',       // resolves which tenant a request is
    'core/tenant_provisioner.php',    // creates tenant databases, by definition
    'core/tenant_migration_runner.php',
    'core/tenant_migration_bootstrap.php',
    'core/control_db.php',            // the one hardcoded connection, by design
];

/** Strip comments so a comment ABOUT a bad pattern is not counted AS one. */
function codeOnly(string $src): string
{
    $out = '';
    foreach (@token_get_all($src) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

function scanTree(string $root, array $dirs): array
{
    $files = [];
    foreach ($dirs as $d) {
        $path = "$root/$d";
        if (!is_dir($path)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                $files[] = str_replace('\\', '/', $f->getPathname());
            }
        }
    }
    sort($files);
    return $files;
}

$files = scanTree($root, $scanDirs);
echo "\n\033[1mTenant resource audit\033[0m\n";
echo "  scanned " . count($files) . " PHP files under " . implode(', ', $scanDirs) . "\n";

$violations = [
    'connection'   => [],   // builds its own DB connection
    'uploads_fs'   => [],   // builds an absolute uploads path
    'uploads_rel'  => [],   // hardcodes a stored 'uploads/...' path
    'backups'      => [],   // builds a backups path
    'db_name'      => [],   // reads the DB_NAME constant directly
];

foreach ($files as $abs) {
    $rel = ltrim(str_replace(str_replace('\\', '/', $root), '', $abs), '/');
    if (in_array($rel, $exemptFiles, true)) continue;

    $raw = @file_get_contents($abs);
    if ($raw === false) continue;
    if (strpos($raw, 'tenant-audit: skip') !== false) continue;   // explicit opt-out

    $src = codeOnly($raw);

    if (preg_match('/\bnew\s+(PDO|mysqli)\s*\(|\bmysqli_connect\s*\(/', $src)) {
        $violations['connection'][] = $rel;
    }
    if (preg_match('/(ROOT_DIR|__DIR__)\s*\.\s*[\'"][^\'"]*uploads\//', $src)) {
        $violations['uploads_fs'][] = $rel;
    }
    if (preg_match('/[\'"]uploads\/[A-Za-z0-9_]/', $src)) {
        $violations['uploads_rel'][] = $rel;
    }
    if (preg_match('/(ROOT_DIR|__DIR__)\s*\.\s*[\'"][^\'"]*backups\//', $src)) {
        $violations['backups'][] = $rel;
    }
    if (preg_match('/\bDB_NAME\b/', $src)) {
        $violations['db_name'][] = $rel;
    }
}

// ── BASELINES ───────────────────────────────────────────────────────────────
// Measured 2026-09-03. LOWER THESE as files are converted; never raise one.
// Raising a baseline to make this suite pass re-opens the defect that dropped a
// production database — convert the file or mark it `tenant-audit: skip`.
// connection / backups are at ZERO and must stay there — those two are the
// exact defect that dropped a database. uploads_* is the remaining debt: the
// filesystem is still shared, which is a quota and path-handling problem rather
// than the direct data leak the backup directory was (uploaded files are named
// bin2hex(random_bytes(16)) and indexed in each tenant's own database, so one
// tenant cannot enumerate another's). Frozen here so it cannot grow.
$baseline = [
    'connection'  => 0,
    'uploads_fs'  => 67,
    'uploads_rel' => 56,
    'backups'     => 0,
    'db_name'     => 1,
];

$labels = [
    'connection'  => 'files building their own DB connection      (use bmsCurrentDbConfig)',
    'uploads_fs'  => 'files building an absolute uploads path     (use bmsUploadsDir)',
    'uploads_rel' => 'files hardcoding a stored uploads/ path     (use bmsUploadsRel)',
    'backups'     => 'files building a backups path               (use bmsBackupDir)',
    'db_name'     => 'files reading the DB_NAME constant          (use bmsCurrentDbName)',
];

section('Ratchet — these counts may fall, never rise');

foreach ($baseline as $key => $max) {
    $n = count($violations[$key]);
    $okNow = $n <= $max;
    ok($okNow, sprintf('%-3d / %-3d  %s', $n, $max, $labels[$key]));
    if (!$okNow) {
        echo "      \033[31mNEW OFFENDERS — convert them, or mark with `// tenant-audit: skip — <reason>`:\033[0m\n";
        foreach (array_slice($violations[$key], 0, 15) as $v) echo "        $v\n";
        if (count($violations[$key]) > 15) echo "        … and " . (count($violations[$key]) - 15) . " more\n";
    }
    if ($okNow && $n < $max) {
        echo "      \033[33m↓ debt reduced — lower the baseline for '$key' to $n in this PR.\033[0m\n";
    }
}

section('The sanctioned accessors exist and behave');

require_once "$root/includes/config.php";
require_once "$root/core/tenant_bootstrap.php";

foreach (['bmsCurrentDbConfig', 'bmsCurrentDbName', 'bmsTenantPathPrefix',
          'bmsUploadsDir', 'bmsUploadsRel', 'bmsBackupDir'] as $fn) {
    ok(function_exists($fn), "$fn() is available");
}

// No tenant → everything unprefixed, exactly as the single-tenant app always was.
unset($GLOBALS['__bms_tenant'], $GLOBALS['__bms_tenant_pw']);
ok(bmsTenantPathPrefix() === '',              'no tenant → no path prefix');
ok(bmsUploadsRel('contracts') === 'uploads/contracts/', 'no tenant → uploads/contracts/');
ok(bmsCurrentDbName() === DB_NAME,            'no tenant → the legacy database');

// A provisioned tenant → its own prefix, on BOTH the stored and written paths.
$GLOBALS['__bms_tenant'] = [
    'id' => 9002, 'subdomain' => 'demo9002', 'db_host' => DB_SERVER,
    'db_name' => 'bms_t9002_not_a_real_db', 'db_username' => DB_USERNAME,
    'db_password_encrypted' => '', 'status' => 'active',
];
$GLOBALS['__bms_tenant_pw'] = DB_PASSWORD;

ok(bmsTenantPathPrefix() === 't9002/',                          'tenant → t9002/ prefix');
ok(bmsUploadsRel('contracts') === 'uploads/t9002/contracts/',   'tenant → uploads/t9002/contracts/');
ok(bmsCurrentDbName() === 'bms_t9002_not_a_real_db',            'tenant → its own database');

// THE PAIRING RULE: what gets stored and what gets written must agree, or the
// file lands somewhere nothing ever reads.
$relPath = bmsUploadsRel('contracts');
$absPath = str_replace('\\', '/', bmsUploadsDir('contracts'));
ok(substr($absPath, -strlen($relPath)) === $relPath,
   'bmsUploadsDir() ends with exactly bmsUploadsRel() — stored and written paths agree');
ok(is_dir($absPath), 'the tenant uploads directory is created on demand');
ok(is_file(rtrim($absPath, '/') . '/.htaccess'),
   'and carries the deny-executables guard (.claude/security.md §19)');

// Clean up the directories this test created.
$g = rtrim($absPath, '/') . '/.htaccess';
if (is_file($g)) @unlink($g);
@rmdir(rtrim($absPath, '/'));
@rmdir(dirname(rtrim($absPath, '/')));

// The legacy install must NEVER get a prefix — that is what keeps every path
// already in document_library valid without moving a single file.
$GLOBALS['__bms_tenant']['db_name'] = DB_NAME;
ok(bmsTenantPathPrefix() === '',
   'the legacy tenant (db_name === DB_NAME) stays unprefixed — no stored path is invalidated');
ok(bmsUploadsRel('contracts') === 'uploads/contracts/',
   'and its uploads path is unchanged from the single-tenant app');

unset($GLOBALS['__bms_tenant'], $GLOBALS['__bms_tenant_pw']);
