<?php
/**
 * Backup ZIP tenant scoping — CLI test
 *   php tests/test_backup_tenant_scope_cli.php
 *
 * Every tenant subdomain shares ONE uploads/ root: the legacy install owns the
 * unprefixed paths, each provisioned tenant owns uploads/t{id}/. Before this
 * fix a backup zipped the whole root and a restore copied the whole tree back,
 * so (seen on production 2026-10-31):
 *   - every tenant's backup ZIP carried every other company's files;
 *   - a tenant restoring a ZIP could overwrite another company's files.
 *
 * Sections:
 *   1. bms_upload_in_scope() rules
 *   2. bms_write_zip_backup() — tenant ZIP holds only its own folder
 *   3. bms_write_zip_backup() — legacy ZIP holds no tenant folder
 *   4. bms_restore_uploads() — an old all-tenants ZIP restores only in scope
 *   5. NEGATIVE CONTROL — the old bms_copy_dir() path does overwrite (so 4 is real)
 *   6. Call-site guards — every writer passes a prefix, no restore uses bms_copy_dir
 *
 * Creates and drops one throwaway database and temp dirs. Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/includes/config.php";
require_once "$root/core/backup.php";
require_once "$root/core/tenant_bootstrap.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }

$scratchDb = 'bms_scopetest_' . bin2hex(random_bytes(4));
$work      = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_scopetest_' . bin2hex(random_bytes(4));
$madeDb    = false;

register_shutdown_function(function () {
    global $pass, $fail, $scratchDb, $work, $madeDb;
    if (is_dir($work)) bms_delete_dir($work);
    if ($madeDb) {
        try {
            $a = new PDO('mysql:host=' . DB_SERVER, DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $a->exec("DROP DATABASE IF EXISTS `$scratchDb`");
        } catch (Throwable $e) {}
    }
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    exit($fail === 0 ? 0 : 1);
});

/** Write $content to $base/$rel, creating parent dirs. */
function put(string $base, string $rel, string $content): void
{
    $p = $base . '/' . $rel;
    if (!is_dir(dirname($p))) mkdir(dirname($p), 0755, true);
    file_put_contents($p, $content);
}

/** Entry names of a ZIP, sorted. */
function zipEntries(string $zipPath): array
{
    $z = new ZipArchive();
    $z->open($zipPath);
    $names = [];
    for ($i = 0; $i < $z->numFiles; $i++) $names[] = $z->getNameIndex($i);
    $z->close();
    sort($names);
    return $names;
}

/** Source with comments stripped, so a comment quoting old code cannot match. */
function phpCodeOnly(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

echo "\n\033[1mBackup ZIP tenant scoping\033[0m\n";

// ═══════════════════════════════════════════════════════════════════════════
section('1. bms_upload_in_scope() rules');
// ═══════════════════════════════════════════════════════════════════════════

ok(bms_upload_in_scope('t29/contracts/a.pdf', 't29/'),  "tenant t29 owns t29/…");
ok(!bms_upload_in_scope('t47/a.pdf', 't29/'),           "tenant t29 does NOT own t47/…");
ok(!bms_upload_in_scope('t2/a.pdf', 't29/') && !bms_upload_in_scope('t290/a.pdf', 't29/'),
   "prefix match is folder-exact (t2/ and t290/ are not t29/)");
ok(!bms_upload_in_scope('contracts/a.pdf', 't29/'),     "tenant t29 does NOT own legacy files");
ok(bms_upload_in_scope('contracts/a.pdf', ''),          "legacy owns its unprefixed files");
ok(!bms_upload_in_scope('t29/a.pdf', ''),               "legacy does NOT own a tenant folder");
ok(bms_upload_in_scope('tenders/a.pdf', '') && bms_upload_in_scope('training_certs/x', '') && bms_upload_in_scope('trips/x', ''),
   "legacy folders that merely start with 't' (tenders/, training_certs/, trips/) stay in scope");
ok(bms_upload_in_scope('t29\\sub\\a.pdf', 't29/'),      "Windows separators are normalised");
ok(!bms_upload_in_scope('t29/../t47/a.pdf', 't29/') && !bms_upload_in_scope('../x', ''),
   "a '..' segment is never in scope");
ok(!bms_upload_in_scope('', '') && !bms_upload_in_scope('', 't29/'), "an empty path is never in scope");

// ═══════════════════════════════════════════════════════════════════════════
section('2. Tenant ZIP holds only its own folder');
// ═══════════════════════════════════════════════════════════════════════════

if (!class_exists('ZipArchive')) { ok(false, 'ZipArchive is required'); exit; }

try {
    $admin = new PDO('mysql:host=' . DB_SERVER, DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec("CREATE DATABASE `$scratchDb` CHARACTER SET utf8mb4");
    $madeDb = true;
} catch (Throwable $e) {
    ok(false, 'cannot create a scratch database: ' . $e->getMessage());
    exit;
}
$sp = new PDO("mysql:host=" . DB_SERVER . ";dbname=$scratchDb;charset=utf8mb4", DB_USERNAME, DB_PASSWORD,
              [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sp->exec("CREATE TABLE marker (id INT PRIMARY KEY, v VARCHAR(20))");
$sp->exec("INSERT INTO marker VALUES (1, 'scope')");

// A shared uploads root shaped like production.
$uploads = "$work/live/uploads";
put($uploads, 'contracts/legacy.pdf',     'LEGACY');
put($uploads, 'tenders/tender.pdf',       'LEGACY-TENDER');
put($uploads, '.htaccess',                'guard');
put($uploads, 't29/products/shop.jpg',    'T29');
put($uploads, 't29/contracts/deep/c.pdf', 'T29-DEEP');
put($uploads, 't47/products/hanga.jpg',   'T47');
put($uploads, 't4/x.pdf',                 'T4');

$tZip = "$work/t29.zip";
$stats = bms_write_zip_backup($sp, $tZip, $uploads, 't29/');
$names = zipEntries($tZip);
$uploadsIn = array_values(array_filter($names, fn($n) => str_starts_with($n, 'uploads/')));

ok(in_array('database.sql', $names, true) && in_array('manifest.json', $names, true), 'database.sql + manifest.json present');
ok($uploadsIn === ['uploads/t29/contracts/deep/c.pdf', 'uploads/t29/products/shop.jpg'],
   "only t29's two files are in the ZIP — got: " . implode(', ', $uploadsIn));
ok($stats['files_count'] === 2, "files_count reports 2 (got {$stats['files_count']})");
ok(!preg_grep('#^uploads/(t47|t4)/#', $names), 'no other tenant folder leaked in');
ok(!preg_grep('#^uploads/(contracts|tenders)/#', $names), 'no legacy file leaked in');
$z = new ZipArchive(); $z->open($tZip);
$manifest = json_decode($z->getFromName('manifest.json'), true);
$dbSql    = $z->getFromName('database.sql');
$z->close();
ok(($manifest['uploads_prefix'] ?? null) === 't29/', "manifest records uploads_prefix 't29/'");
ok(str_contains($dbSql, "'scope'"), 'the dump inside is the database that was passed in');

// A tenant with no uploads folder yet still gets a valid DB-only ZIP.
$emptyZip = "$work/t99.zip";
$stats = bms_write_zip_backup($sp, $emptyZip, $uploads, 't99/');
ok(is_file($emptyZip) && $stats['files_count'] === 0
   && !preg_grep('#^uploads/#', zipEntries($emptyZip)),
   'a tenant with no uploads folder → valid ZIP with no files (and none of anyone else\'s)');

// ═══════════════════════════════════════════════════════════════════════════
section('3. Legacy ZIP holds no tenant folder');
// ═══════════════════════════════════════════════════════════════════════════

$lZip  = "$work/legacy.zip";
$stats = bms_write_zip_backup($sp, $lZip, $uploads, '');
$names = zipEntries($lZip);
$uploadsIn = array_values(array_filter($names, fn($n) => str_starts_with($n, 'uploads/')));
ok($uploadsIn === ['uploads/.htaccess', 'uploads/contracts/legacy.pdf', 'uploads/tenders/tender.pdf'],
   'legacy ZIP = its own files incl. tenders/ — got: ' . implode(', ', $uploadsIn));
ok(!preg_grep('#^uploads/t\d+/#', $names), 'no t{id}/ folder in the legacy ZIP');

// ═══════════════════════════════════════════════════════════════════════════
section('4. Restore writes only inside the restoring scope');
// ═══════════════════════════════════════════════════════════════════════════

// An OLD-style ZIP that carries everyone's files, with hostile contents for the
// folders the restorer does not own.
$oldZip = "$work/old_all.zip";
$z = new ZipArchive(); $z->open($oldZip, ZipArchive::CREATE);
$z->addFromString('database.sql', "SELECT 1;\n");
$z->addFromString('manifest.json', json_encode(['bms_backup_format' => '1.0']));
$z->addFromString('uploads/t29/products/shop.jpg', 'T29-RESTORED');
$z->addFromString('uploads/t47/products/hanga.jpg', 'INTRUDER');
$z->addFromString('uploads/contracts/legacy.pdf',   'INTRUDER');
$z->addFromString('uploads/t4/new_planted.php',     'INTRUDER');
$z->close();

$target = "$work/target/uploads";
put($target, 't47/products/hanga.jpg',  'T47-ORIGINAL');
put($target, 'contracts/legacy.pdf',    'LEGACY-ORIGINAL');

$ex = bms_extract_zip_backup($oldZip);
$copied = bms_restore_uploads($ex['uploads_path'], $target, 't29/');
ok($copied === 1, "tenant t29 restore copied exactly 1 file (got $copied)");
ok(@file_get_contents("$target/t29/products/shop.jpg") === 'T29-RESTORED', "t29's own file was restored");
ok(file_get_contents("$target/t47/products/hanga.jpg") === 'T47-ORIGINAL', "t47's file was NOT overwritten");
ok(file_get_contents("$target/contracts/legacy.pdf") === 'LEGACY-ORIGINAL', 'the legacy file was NOT overwritten');
ok(!file_exists("$target/t4/new_planted.php"), 'nothing was planted in t4/');

$copied = bms_restore_uploads($ex['uploads_path'], $target, '');
ok($copied === 1 && file_get_contents("$target/contracts/legacy.pdf") === 'INTRUDER',
   'legacy restore writes its own file (1 copied)');
ok(file_get_contents("$target/t47/products/hanga.jpg") === 'T47-ORIGINAL' && !file_exists("$target/t4/new_planted.php"),
   'legacy restore does NOT touch any tenant folder');

// Round trip: the tenant ZIP from section 2 restores into a clean root intact.
$ex2 = bms_extract_zip_backup($tZip);
$clean = "$work/clean/uploads";
$copied = bms_restore_uploads($ex2['uploads_path'], $clean, 't29/');
ok($copied === 2 && file_get_contents("$clean/t29/contracts/deep/c.pdf") === 'T29-DEEP',
   'round trip: tenant ZIP → restore → both files back at uploads/t29/…');
bms_delete_dir($ex2['temp_dir']);

// ═══════════════════════════════════════════════════════════════════════════
section('5. NEGATIVE CONTROL — the old restore path did overwrite');
// ═══════════════════════════════════════════════════════════════════════════

$neg = "$work/neg/uploads";
put($neg, 't47/products/hanga.jpg', 'T47-ORIGINAL');
bms_copy_dir($ex['uploads_path'], $neg);
ok(file_get_contents("$neg/t47/products/hanga.jpg") === 'INTRUDER',
   "NEGATIVE CONTROL: bms_copy_dir() (the old call) overwrites t47's file — so section 4 proves the fix");
bms_delete_dir($ex['temp_dir']);

// ═══════════════════════════════════════════════════════════════════════════
section('6. Call-site guards');
// ═══════════════════════════════════════════════════════════════════════════

$callers = [
    'api/backup_actions.php',
    'app/constant/settings/backup_restore.php',
    'cron/auto_backup.php',
];
foreach ($callers as $rel) {
    $code = phpCodeOnly(file_get_contents("$root/$rel"));
    preg_match_all('/bms_write_zip_backup\s*\(([^;]*)\)\s*;/', $code, $m);
    $allFour = !empty($m[1]);
    foreach ($m[1] as $args) { if (substr_count($args, ',') < 3) $allFour = false; }
    ok($allFour, "$rel passes an upload prefix to every bms_write_zip_backup() call");
}
$api = phpCodeOnly(file_get_contents("$root/api/backup_actions.php"));
ok(substr_count($api, 'bms_restore_uploads(') === 2, 'both restore paths in api/backup_actions.php use bms_restore_uploads()');
ok(!str_contains($api, 'bms_copy_dir('), 'api/backup_actions.php no longer restores with bms_copy_dir()');
ok(str_contains($api, '$uploadPrefix = bmsTenantPathPrefix()'), 'the API takes its prefix from bmsTenantPathPrefix()');
