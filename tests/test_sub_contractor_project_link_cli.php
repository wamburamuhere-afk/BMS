<?php
/**
 * tests/test_sub_contractor_project_link_cli.php
 *   php tests/test_sub_contractor_project_link_cli.php
 *
 * Sub-contractor ↔ project link. A sub-contractor's primary project was only ever
 * written to `sub_contractors.project_id`, while every project-side view reads the
 * `sub_contractor_projects` junction table — so a sub-contractor created from the
 * core form never appeared inside Project > Procurement > Sub-Contractors.
 *
 * Proves: both write APIs now mirror the primary project into the junction table,
 * the backfill migration repairs pre-existing records idempotently, and the real
 * create/edit runtime path makes the record visible to the project-side API.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 60) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

function callApi(string $root, string $rel, array $post = [], array $get = []) {
    $_POST = $post; $_GET = $get;
    $_SERVER['REQUEST_METHOD'] = empty($post) ? 'GET' : 'POST';
    $_POST['_csrf'] = $_SESSION['csrf_token'] ?? 'testtok';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['csrf_token'] ?? 'testtok';
    ob_start();
    include "$root/$rel";
    return json_decode(ob_get_clean(), true);
}

$MIG = 'migrations/2026_08_12_backfill_sub_contractor_project_links.php';

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
foreach ([$MIG, 'api/add_sub_contractor.php', 'api/update_sub_contractor.php', 'api/get_project_sub_contractors.php'] as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg($full) . ' 2>&1', $o, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Both write APIs mirror the primary project into the junction');
foreach (['api/add_sub_contractor.php', 'api/update_sub_contractor.php'] as $f) {
    $s = file_get_contents("$root/$f");
    has($s, 'INSERT IGNORE INTO sub_contractor_projects', "$f writes the junction row");
    has($s, 'if (!empty($project_id))', "$f only links when a project was chosen");
}
$addSrc = file_get_contents("$root/api/add_sub_contractor.php");
(strpos($addSrc, 'INSERT IGNORE INTO sub_contractor_projects') < strpos($addSrc, '$pdo->commit();'))
    ? pass('add: junction insert is inside the create transaction (atomic)')
    : fail('add: junction insert happens after commit — a failure would orphan the record again');

// ─────────────────────────────────────────────────────────────────────────
section('3. Backfill migration is criteria-based + idempotent');
$mig = file_get_contents("$root/$MIG");
has($mig, 'INSERT IGNORE', 'uses INSERT IGNORE (safe to re-run)');
has($mig, 'NOT EXISTS', 'selects only rows missing their junction link');
has($mig, "SHOW TABLES LIKE 'sub_contractor_projects'", 'guards on table existence');
has($mig, "SHOW COLUMNS FROM sub_contractors LIKE 'project_id'", 'guards on column existence');
has($mig, 'JOIN projects p', 'joins projects so a stale project_id cannot create a dangling row');
has($mig, 'exit(1)', 'exit(1) on failure (halts the deploy)');
preg_match('/supplier_id\s*(=|IN)\s*\(?\s*\d/', $mig)
    ? fail('migration contains a hard-coded supplier id')
    : pass('no hard-coded ids — criteria-based');

// ─────────────────────────────────────────────────────────────────────────
section('4. Project-side views read the junction (the reason the link matters)');
has(file_get_contents("$root/api/get_project_sub_contractors.php"), 'FROM sub_contractor_projects scp',
    'get_project_sub_contractors.php reads sub_contractor_projects');

// ── Fixtures for the runtime tests ───────────────────────────────────────
$admin = $pdo->query("SELECT user_id FROM users WHERE role_id = 1 AND is_active = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$projects = $pdo->query("SELECT project_id FROM projects ORDER BY project_id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (!$admin || empty($projects)) {
    fail('no admin user or no project in this DB — cannot run the runtime tests');
    return;
}
$_SESSION['user_id'] = (int) $admin;
$_SESSION['role_id'] = 1;
$P1 = (int) $projects[0];
$P2 = isset($projects[1]) ? (int) $projects[1] : null;
$made = [];   // supplier_ids to clean up

// ─────────────────────────────────────────────────────────────────────────
section('5. Backfill repairs a pre-existing orphan (the live-data fix)');
$orphanName = 'ZZ Test Orphan SC ' . uniqid();
$pdo->prepare("INSERT INTO sub_contractors (supplier_name, project_id, status, created_by, created_at, updated_at)
               VALUES (?, ?, 'active', ?, NOW(), NOW())")->execute([$orphanName, $P1, $admin]);
$orphanId = (int) $pdo->lastInsertId();
$made[] = $orphanId;

$linked = fn(int $sid, int $pid) => (bool) $pdo->query(
    "SELECT 1 FROM sub_contractor_projects WHERE supplier_id = $sid AND project_id = $pid")->fetchColumn();

!$linked($orphanId, $P1) ? pass('fixture starts unlinked (reproduces the bug)') : fail('fixture was already linked');

exec('php ' . escapeshellarg("$root/$MIG") . ' 2>&1', $out1, $rc1);
$rc1 === 0 ? pass('migration ran clean') : fail('migration exited non-zero: ' . implode("\n", $out1));
$linked($orphanId, $P1) ? pass('orphan is now linked to its primary project') : fail('orphan still unlinked after backfill');

$before = (int) $pdo->query("SELECT COUNT(*) FROM sub_contractor_projects")->fetchColumn();
exec('php ' . escapeshellarg("$root/$MIG") . ' 2>&1', $out2, $rc2);
$after = (int) $pdo->query("SELECT COUNT(*) FROM sub_contractor_projects")->fetchColumn();
($rc2 === 0 && $after === $before) ? pass("re-run is a no-op (idempotent, still $after rows)") : fail("re-run changed the data ($before → $after)");

// ─────────────────────────────────────────────────────────────────────────
section('6. Runtime: create via the real API → visible inside the project');
$scName = 'ZZ Test SC ' . uniqid();
$res = callApi($root, 'api/add_sub_contractor.php', [
    'supplier_name' => $scName,
    'project_id'    => $P1,
    'status'        => 'active',
]);
if (!is_array($res) || empty($res['success'])) {
    fail('add_sub_contractor.php failed: ' . json_encode($res));
} else {
    pass('add_sub_contractor.php created the sub-contractor');
    $newId = (int) $res['sub_contractor_id'];
    $made[] = $newId;

    $linked($newId, $P1)
        ? pass('junction row written at creation (was the missing step)')
        : fail('no junction row after create — the project tab will not show it');

    $list = callApi($root, 'api/get_project_sub_contractors.php', [], ['project_id' => $P1]);
    $names = array_column($list['data'] ?? [], 'supplier_name');
    in_array($scName, $names, true)
        ? pass('appears in Project > Procurement > Sub-Contractors')
        : fail('still missing from the project-side API');

    // ── Edit the primary project → junction follows ──────────────────────
    if ($P2 !== null) {
        section('7. Runtime: edit the primary project → junction follows');
        $res2 = callApi($root, 'api/update_sub_contractor.php', [
            'supplier_id'   => $newId,
            'supplier_name' => $scName,
            'project_id'    => $P2,
            'status'        => 'active',
        ]);
        (is_array($res2) && !empty($res2['success']))
            ? pass('update_sub_contractor.php saved') : fail('update failed: ' . json_encode($res2));
        $linked($newId, $P2) ? pass('junction row written for the newly chosen project') : fail('junction not updated on edit');
        $linked($newId, $P1) ? pass('previous project link retained (assign-only, no silent unassign)') : fail('editing silently dropped an existing project link');
    } else {
        echo "  (only one project in this DB — skipping the edit test)\n";
    }
}

// ── Cleanup ──────────────────────────────────────────────────────────────
section('8. Cleanup');
foreach ($made as $sid) {
    $accId = $pdo->query("SELECT ledger_account_id FROM sub_contractors WHERE supplier_id = $sid")->fetchColumn();
    $pdo->exec("DELETE FROM sub_contractor_projects WHERE supplier_id = $sid");
    $pdo->exec("DELETE FROM sub_contractors WHERE supplier_id = $sid");
    if ($accId) $pdo->exec("DELETE FROM accounts WHERE account_id = " . (int) $accId);
}
$left = $pdo->query("SELECT COUNT(*) FROM sub_contractors WHERE supplier_name LIKE 'ZZ Test %'")->fetchColumn();
((int) $left === 0) ? pass('test fixtures removed') : fail("$left test fixture(s) left behind");
