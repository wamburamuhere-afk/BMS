<?php
/**
 * tests/test_return_note_supplier_list_cli.php
 *   php tests/test_return_note_supplier_list_cli.php
 *
 * Project Details > Procurements > Return Note — the Supplier dropdown came back
 * empty after choosing a warehouse, even where approved GRNs existed for that
 * warehouse and project.
 *
 * Cause: get_return_suppliers.php filtered on `suppliers.project_id` (a legacy
 * single "primary project" column) instead of the GRN's own project. Suppliers
 * linked to the project through the `supplier_projects` junction, left untagged,
 * or carrying a different primary project were all excluded.
 *
 * Proves: the supplier list is driven by purchase_receipts, the GRN list agrees
 * with it, both are scope-gated, and the real endpoints return the suppliers of
 * a live warehouse's approved GRNs.
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

function callGet(string $root, string $rel, array $get) {
    $_GET = $get; $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    include "$root/$rel";
    return json_decode(ob_get_clean(), true);
}

$SUP = 'api/operations/get_return_suppliers.php';
$GRN = 'api/operations/get_return_grns.php';

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
foreach ([$SUP, $GRN, 'app/bms/operations/project_view.php'] as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg($full) . ' 2>&1', $o, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Supplier list is driven by the GRN, not the supplier record');
$supSrc = file_get_contents("$root/$SUP");
has($supSrc, 'FROM purchase_receipts pr', 'query is anchored on purchase_receipts');
has($supSrc, 'pr.project_id = ?', "filters on the GRN's project");
(strpos($supSrc, 's.project_id = ?') === false)
    ? pass('no longer filters on the legacy suppliers.project_id column')
    : fail('still filters on suppliers.project_id — the original defect');
has($supSrc, 'pr.project_id IS NULL', 'includes untagged receipts (§23 rule 3 leniency)');
has($supSrc, "s.status != 'deleted'", 'excludes deleted suppliers');
has($supSrc, "userCan('warehouse', \$warehouse_id)", 'gates the chosen warehouse (§23 rule 2)');
has($supSrc, "userCan('project', \$project_id)", 'gates the chosen project (§23 rule 2)');

// ─────────────────────────────────────────────────────────────────────────
section('3. GRN list agrees with the supplier list');
$grnSrc = file_get_contents("$root/$GRN");
has($grnSrc, 'project_id = ? OR project_id IS NULL', 'GRN list uses the same project rule');
has($grnSrc, "userCan('project', \$project_id)", 'GRN list gates the chosen project');
has(file_get_contents("$root/app/bms/operations/project_view.php"),
    "supplier_id: supplierId, project_id:", 'front end passes project_id to the GRN lookup');

// ─────────────────────────────────────────────────────────────────────────
section('4. Empty list explains itself instead of looking broken');
has(file_get_contents("$root/app/bms/operations/project_view.php"),
    'No supplier has a GRN in this warehouse', 'empty supplier list shows a reason');

// ── Fixtures ─────────────────────────────────────────────────────────────
$admin = $pdo->query("SELECT user_id FROM users WHERE role_id = 1 AND is_active = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
if (!$admin) { fail('no admin user in this DB — cannot run the runtime tests'); return; }
$_SESSION['user_id'] = (int) $admin;
$_SESSION['role_id'] = 1;

// Pick a warehouse/project pair that actually has non-cancelled GRNs.
$fx = $pdo->query("
    SELECT pr.warehouse_id, pr.project_id, COUNT(DISTINCT pr.supplier_id) AS suppliers
    FROM purchase_receipts pr
    WHERE pr.status != 'cancelled' AND pr.project_id IS NOT NULL AND pr.warehouse_id IS NOT NULL
    GROUP BY pr.warehouse_id, pr.project_id
    ORDER BY suppliers DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$fx) { fail('no warehouse/project pair with GRNs in this DB — cannot run the runtime tests'); return; }
$WH = (int) $fx['warehouse_id'];
$PJ = (int) $fx['project_id'];
echo "  (fixture: warehouse #$WH, project #$PJ, {$fx['suppliers']} supplier(s) with GRNs)\n";

// ─────────────────────────────────────────────────────────────────────────
section('5. The old query really did return nothing (bug reproduced)');
$old = $pdo->prepare("
    SELECT DISTINCT s.supplier_id FROM suppliers s
    JOIN purchase_receipts pr ON s.supplier_id = pr.supplier_id
    WHERE pr.warehouse_id = ? AND s.project_id = ? AND pr.status != 'cancelled'");
$old->execute([$WH, $PJ]);
$oldCount = count($old->fetchAll());

$expected = $pdo->prepare("
    SELECT DISTINCT s.supplier_id FROM purchase_receipts pr
    JOIN suppliers s ON s.supplier_id = pr.supplier_id
    WHERE pr.warehouse_id = ? AND (pr.project_id = ? OR pr.project_id IS NULL)
      AND pr.status != 'cancelled' AND s.status != 'deleted'");
$expected->execute([$WH, $PJ]);
$expectedCount = count($expected->fetchAll());

($expectedCount > 0) ? pass("warehouse #$WH genuinely has $expectedCount supplier(s) with GRNs") : fail('fixture has no suppliers — cannot prove anything');
($oldCount < $expectedCount)
    ? pass("old supplier-project filter returned $oldCount of $expectedCount (the reported empty dropdown)")
    : fail("old filter returned $oldCount — this DB does not reproduce the bug");

// ─────────────────────────────────────────────────────────────────────────
section('6. Runtime: the endpoint now returns those suppliers');
$res = callGet($root, $SUP, ['warehouse_id' => $WH, 'project_id' => $PJ]);
if (!is_array($res) || empty($res['success'])) {
    fail('get_return_suppliers.php failed: ' . json_encode($res));
} else {
    pass('get_return_suppliers.php returned success');
    $got = count($res['data']);
    ($got === $expectedCount) ? pass("returned all $got supplier(s) with GRNs in the warehouse") : fail("returned $got, expected $expectedCount");
    ($got > 0) ? pass('dropdown is no longer empty (the reported symptom)') : fail('dropdown still empty');

    // ── Every supplier offered must yield at least one GRN ────────────────
    section('7. Runtime: every listed supplier yields at least one GRN');
    $allHaveGrns = true; $checked = 0;
    foreach ($res['data'] as $s) {
        $g = callGet($root, $GRN, ['warehouse_id' => $WH, 'supplier_id' => (int) $s['supplier_id'], 'project_id' => $PJ]);
        $checked++;
        if (empty($g['success']) || count($g['data'] ?? []) === 0) {
            $allHaveGrns = false;
            fail("supplier '{$s['supplier_name']}' is offered but has no selectable GRN");
        }
    }
    $allHaveGrns ? pass("all $checked listed supplier(s) return at least one GRN (dropdowns agree)") : null;
}

// ─────────────────────────────────────────────────────────────────────────
// The endpoints exit() on a scope refusal, which would tear down this process,
// so assert the gate itself: the guard the endpoints call must deny, and the
// endpoints must exit rather than fall through to the query.
section('8. Scope gates deny out-of-scope ids');
$savedScope = $_SESSION['scope'] ?? null;
$_SESSION['scope'] = ['is_admin' => false, 'projects' => [], 'warehouses' => [], 'warehouse_explicit' => true];

(userCan('warehouse', $WH) === false) ? pass("userCan('warehouse', $WH) denies a non-admin with no scope") : fail('warehouse gate allowed an out-of-scope id');
(userCan('project', $PJ) === false)   ? pass("userCan('project', $PJ) denies a non-admin with no scope")   : fail('project gate allowed an out-of-scope id');

if ($savedScope !== null) { $_SESSION['scope'] = $savedScope; } else { unset($_SESSION['scope']); }

foreach ([$SUP => 2, $GRN => 2] as $f => $expectedGuards) {
    $s = file_get_contents("$root/$f");
    $guards = preg_match_all('/if \([^)]*userCan\(.*?\n\s*http_response_code\(403\);.*?exit\(\);/s', $s);
    ($guards >= $expectedGuards)
        ? pass(basename($f) . ": $guards scope guard(s) return 403 and exit before querying")
        : fail(basename($f) . ": expected $expectedGuards guard(s) that 403+exit, found $guards");
}
