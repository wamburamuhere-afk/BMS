<?php
/**
 * Document reference pickers — cross-warehouse/project leak regression test.
 *
 * User-reported: creating a GRN and referencing a Purchase Order showed POs
 * from warehouses the user isn't even assigned to. Guards the fix: the GRN
 * PO-picker, the Delivery Note Sales-Order/Customer-LPO picker, and the
 * IDOR fix on get_dn_source_prefill.php (which returned full order data for
 * any guessable id with no ownership check at all).
 *
 * Run:  php tests/test_document_reference_pickers_cli.php
 *   Exit 0 = all pass  (safe to commit / push)
 *   Exit 1 = failures   (push blocked — fix before pushing)
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root   = dirname(__DIR__);
$isLive = is_file("$root/includes/config.php");

if ($isLive) {
    require_once "$root/roots.php";
    require_once "$root/core/project_scope.php";
    require_once "$root/core/warehouse_scope.php";
}

$failures = 0;
$passes   = 0;

function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }

section('1. php -l on every touched file');

foreach ([
    'app/bms/grn/grn_create.php',
    'app/bms/grn/grn_edit.php',
    'api/get_customer_dn_sources.php',
    'api/get_dn_source_prefill.php',
    'api/received_invoices.php',
    'api/get_warehouse_supplier_grns.php',
    'api/operations/get_return_grns.php',
] as $rel) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$rel") . ' 2>&1', $out, $rc);
    check($rc === 0, "$rel — no syntax errors", "$rel — php -l failed: " . implode(' ', $out));
}

check(!is_file("$root/api/get_supplier_purchase_orders.php"), 'confirmed-dead api/get_supplier_purchase_orders.php was removed', 'api/get_supplier_purchase_orders.php still exists');

section('2. Static — the picker queries are actually scoped now');

$grnCreateSrc = file_get_contents("$root/app/bms/grn/grn_create.php");
check(strpos($grnCreateSrc, "scopeFilterSqlNullable('project', 'po')") !== false, 'grn_create.php PO picker applies project scope', 'grn_create.php PO picker missing project scope');
check(strpos($grnCreateSrc, "scopeFilterSqlNullable('warehouse', 'po')") !== false, 'grn_create.php PO picker applies warehouse scope', 'grn_create.php PO picker missing warehouse scope');
check(strpos($grnCreateSrc, 'scope-audit: skip') === false, 'grn_create.php no longer carries the incorrect file-level skip marker', 'grn_create.php still has the stale skip marker hiding this query from the audit');

$grnEditSrc = file_get_contents("$root/app/bms/grn/grn_edit.php");
check(strpos($grnEditSrc, "scopeFilterSqlNullable('project', 'po')") !== false, 'grn_edit.php PO picker applies project scope', 'grn_edit.php PO picker missing project scope');
check(strpos($grnEditSrc, "scopeFilterSqlNullable('warehouse', 'po')") !== false, 'grn_edit.php PO picker applies warehouse scope', 'grn_edit.php PO picker missing warehouse scope');

$dnSourcesSrc = file_get_contents("$root/api/get_customer_dn_sources.php");
check(substr_count($dnSourcesSrc, "scopeFilterSqlNullable('project')") >= 2, 'DN source picker applies project scope to both SO and LPO queries', 'DN source picker missing project scope on one or both queries');
check(substr_count($dnSourcesSrc, "scopeFilterSqlNullable('warehouse')") >= 2, 'DN source picker applies warehouse scope to both SO and LPO queries', 'DN source picker missing warehouse scope on one or both queries');

$dnPrefillSrc = file_get_contents("$root/api/get_dn_source_prefill.php");
check(substr_count($dnPrefillSrc, "userCan('project'") >= 2, 'DN prefill IDOR fix: ownership check present for both SO and LPO branches', 'DN prefill missing the project ownership check on one or both branches');
check(substr_count($dnPrefillSrc, "userCan('warehouse'") >= 2, 'DN prefill IDOR fix: warehouse check present for both SO and LPO branches', 'DN prefill missing the warehouse ownership check on one or both branches');

$grnSupplierSrc = file_get_contents("$root/api/get_warehouse_supplier_grns.php");
check(strpos($grnSupplierSrc, "userCan('warehouse'") !== false, 'get_warehouse_supplier_grns.php checks warehouse authorization server-side', 'missing server-side warehouse check');

$returnGrnsSrc = file_get_contents("$root/api/operations/get_return_grns.php");
check(strpos($returnGrnsSrc, "userCan('warehouse'") !== false, 'get_return_grns.php checks warehouse authorization server-side', 'missing server-side warehouse check');

section('3. Live — the GRN PO-picker query actually excludes an out-of-scope warehouse');

if (!$isLive) {
    echo "  \033[33m⊘\033[0m  Skipped (no includes/config.php — not a live install)\n";
} else {
    global $pdo;
    try {
        // Two real, distinct warehouses already in this DB (any two will do —
        // we don't need to know which, just that they differ).
        $whRows = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' ORDER BY warehouse_id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        if (count($whRows) < 2) {
            echo "  \033[33m⊘\033[0m  Skipped (fewer than 2 active warehouses in this DB)\n";
        } else {
            [$grantedWh, $otherWh] = $whRows;
            $testUserId = 999011;

            // A PO in the granted warehouse, and one in the other (out-of-scope) warehouse.
            $poIn = $pdo->prepare("INSERT INTO purchase_orders (order_number, order_date, supplier_id, status, warehouse_id, grand_total) SELECT 'TEST-PICKER-IN', CURDATE(), supplier_id, 'approved', ?, 1000 FROM suppliers LIMIT 1");
            $poIn->execute([$grantedWh]);
            $poInId = (int)$pdo->lastInsertId();

            $poOut = $pdo->prepare("INSERT INTO purchase_orders (order_number, order_date, supplier_id, status, warehouse_id, grand_total) SELECT 'TEST-PICKER-OUT', CURDATE(), supplier_id, 'approved', ?, 1000 FROM suppliers LIMIT 1");
            $poOut->execute([$otherWh]);
            $poOutId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO user_scope_overrides (user_id, resource_type, resource_id) VALUES (?, 'warehouse', ?)")
                ->execute([$testUserId, $grantedWh]);

            $_SESSION['user_id'] = $testUserId;
            unset($_SESSION['is_admin'], $_SESSION['role_id'], $_SESSION['scope']);
            loadUserScope($testUserId);

            // The exact scope fragment now appended to grn_create.php's $po_query.
            $scopeSql = scopeFilterSqlNullable('project', 'po') . scopeFilterSqlNullable('warehouse', 'po');
            $rows = $pdo->query("SELECT po.purchase_order_id FROM purchase_orders po WHERE po.order_number IN ('TEST-PICKER-IN','TEST-PICKER-OUT') $scopeSql")->fetchAll(PDO::FETCH_COLUMN);

            check(in_array($poInId, $rows, false), 'the PO in the user\'s granted warehouse IS returned by the scoped picker query', 'the granted-warehouse PO was incorrectly excluded');
            check(!in_array($poOutId, $rows, false), 'the PO in a DIFFERENT warehouse is correctly EXCLUDED — this is the exact leak the user reported', 'LEAK STILL PRESENT: a PO from an out-of-scope warehouse was returned by the picker query');

            // Cleanup
            $pdo->prepare("DELETE FROM purchase_orders WHERE purchase_order_id IN (?, ?)")->execute([$poInId, $poOutId]);
            $pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id = ?")->execute([$testUserId]);
            pass('test data cleaned up (self-contained, no residue left in the DB)');
        }
    } catch (Throwable $e) {
        fail('Live GRN picker leak test threw: ' . $e->getMessage());
    }
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures > 0 ? "\033[31m$failures\033[0m" : "\033[32m0\033[0m") . "\n";
exit($failures > 0 ? 1 : 0);
