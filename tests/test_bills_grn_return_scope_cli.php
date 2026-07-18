<?php
/**
 * Bills visibility + Purchase Return GRN-status regression test.
 *
 * User-reported: a non-admin assigned only a specific warehouse (no
 * project) saw nothing when creating a Bill, and their own already-created,
 * approved invoice was invisible — while its GRN/DN (also approved) were
 * visible. Separately: Purchase Return creation showed no supplier even
 * though a real, approved GRN existed for that supplier/warehouse.
 *
 * Guards two independent fixes:
 *   1. api/received_invoices.php's Bills list/get actions — strict scope
 *      helper + missing warehouse scope.
 *   2. api/get_warehouse_suppliers.php + get_warehouse_supplier_grns.php —
 *      hardcoded status='completed', a legacy status the current 3-stage
 *      GRN workflow (pending -> reviewed -> approved) never sets anymore.
 *
 * Run:  php tests/test_bills_grn_return_scope_cli.php
 *   Exit 0 = all pass  (safe to commit / push)
 *   Exit 1 = failures   (push blocked — fix before pushing)
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root   = dirname(__DIR__);
$isLive = is_file("$root/includes/config.php");

if ($isLive) {
    require_once "$root/roots.php";
    require_once "$root/core/project_scope.php";
}

$failures = 0;
$passes   = 0;

function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $cond, string $ok, string $ko): void { $cond ? pass($ok) : fail($ko); }

section('1. php -l on every touched file');

foreach ([
    'api/received_invoices.php',
    'api/get_warehouse_suppliers.php',
    'api/get_warehouse_supplier_grns.php',
    'app/bms/purchase/purchase_returns.php',
] as $rel) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$rel") . ' 2>&1', $out, $rc);
    check($rc === 0, "$rel — no syntax errors", "$rel — php -l failed: " . implode(' ', $out));
}

section('2. Static — the fixes are actually present in the source');

$riSrc = file_get_contents("$root/api/received_invoices.php");
check(strpos($riSrc, "scopeFilterSqlNullable('project', 'si') . scopeFilterSqlNullable('warehouse', 'si')") !== false, 'Bills list uses nullable project+warehouse scope, not the strict helper', 'Bills list still uses the strict scope helper or is missing warehouse scope');
check(strpos($riSrc, "scopeFilterSql('project', 'si')") === false, 'Bills list no longer uses the strict scopeFilterSql at all', 'Bills list still calls the strict scopeFilterSql somewhere');
check(substr_count($riSrc, "userCan('warehouse'") >= 1, 'Bills single-record fetch checks warehouse scope', 'Bills single-record fetch is missing the warehouse check');

$gwsSrc = file_get_contents("$root/api/get_warehouse_suppliers.php");
check(strpos($gwsSrc, "IN ('approved', 'completed')") !== false, 'get_warehouse_suppliers.php accepts approved GRNs, not just legacy completed', 'get_warehouse_suppliers.php still only accepts status=completed');
check(strpos($gwsSrc, "userCan('warehouse'") !== false, 'get_warehouse_suppliers.php checks warehouse authorization server-side', 'get_warehouse_suppliers.php missing the server-side warehouse check');

$gwsgSrc = file_get_contents("$root/api/get_warehouse_supplier_grns.php");
check(strpos($gwsgSrc, "IN ('approved', 'completed')") !== false, 'get_warehouse_supplier_grns.php accepts approved GRNs, not just legacy completed', 'get_warehouse_supplier_grns.php still only accepts status=completed');

$prSrc = file_get_contents("$root/app/bms/purchase/purchase_returns.php");
check(strpos($prSrc, "scopeFilterSqlNullable('project')") !== false, 'purchase_returns.php supplier filter uses the shared scope helper', 'purchase_returns.php supplier filter still hand-rolls its own scope logic');

section('3. Live — a warehouse-only user can see their own Bill (previously invisible)');

if (!$isLive) {
    echo "  \033[33m⊘\033[0m  Skipped (no includes/config.php — not a live install)\n";
} else {
    global $pdo;
    try {
        $whRow = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
        if (!$whRow) {
            echo "  \033[33m⊘\033[0m  Skipped (no active warehouse in this DB)\n";
        } else {
            $grantedWh  = (int)$whRow;
            $testUserId = 999021;
            $supplierId = (int)$pdo->query("SELECT supplier_id FROM suppliers WHERE status='active' LIMIT 1")->fetchColumn();

            $ins = $pdo->prepare("
                INSERT INTO supplier_invoices (invoice_type, supplier_id, warehouse_id, project_id, status, date_raised, date_recorded, invoice_ref, amount, amount_paid, recorded_by)
                VALUES ('supplier', ?, ?, NULL, 'pending', CURDATE(), CURDATE(), 'TEST-BILL-SCOPE', 1000, 0, 1)
            ");
            $ins->execute([$supplierId, $grantedWh]);
            $testInvoiceId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO user_scope_overrides (user_id, resource_type, resource_id) VALUES (?, 'warehouse', ?)")
                ->execute([$testUserId, $grantedWh]);

            $_SESSION['user_id'] = $testUserId;
            unset($_SESSION['is_admin'], $_SESSION['role_id'], $_SESSION['scope']);
            loadUserScope($testUserId);

            // The exact scope fragment now used by received_invoices.php's list action.
            $scopeSI = scopeFilterSqlNullable('project', 'si') . scopeFilterSqlNullable('warehouse', 'si');
            $rows = $pdo->query("SELECT si.id FROM supplier_invoices si WHERE si.invoice_ref = 'TEST-BILL-SCOPE' $scopeSI")->fetchAll(PDO::FETCH_COLUMN);

            check(in_array($testInvoiceId, $rows, false), 'a warehouse-only user\'s own Bill is now returned by the Bills list query', 'STILL BROKEN: the warehouse-only user\'s own Bill did not appear');

            // A DIFFERENT warehouse's project-less Bill must still be excluded
            // (the nullable-project fix alone would have leaked this).
            $otherWh = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' AND warehouse_id != " . (int)$grantedWh . " LIMIT 1")->fetchColumn();
            if ($otherWh) {
                $ins2 = $pdo->prepare("
                    INSERT INTO supplier_invoices (invoice_type, supplier_id, warehouse_id, project_id, status, date_raised, date_recorded, invoice_ref, amount, amount_paid, recorded_by)
                    VALUES ('supplier', ?, ?, NULL, 'pending', CURDATE(), CURDATE(), 'TEST-BILL-SCOPE-OTHER', 1000, 0, 1)
                ");
                $ins2->execute([$supplierId, $otherWh]);
                $otherInvoiceId = (int)$pdo->lastInsertId();

                $rows2 = $pdo->query("SELECT si.id FROM supplier_invoices si WHERE si.invoice_ref = 'TEST-BILL-SCOPE-OTHER' $scopeSI")->fetchAll(PDO::FETCH_COLUMN);
                check(!in_array($otherInvoiceId, $rows2, false), 'a different warehouse\'s project-less Bill is correctly EXCLUDED — proves warehouse scope (not just nullable-project) is actually enforced', 'LEAK: a different warehouse\'s Bill was returned');

                $pdo->prepare("DELETE FROM supplier_invoices WHERE id = ?")->execute([$otherInvoiceId]);
            }

            $pdo->prepare("DELETE FROM supplier_invoices WHERE id = ?")->execute([$testInvoiceId]);
            $pdo->prepare("DELETE FROM user_scope_overrides WHERE user_id = ?")->execute([$testUserId]);
            pass('test data cleaned up (self-contained, no residue left in the DB)');
        }
    } catch (Throwable $e) {
        fail('Live Bills-visibility test threw: ' . $e->getMessage());
    }
}

section('4. Live — an approved (non-legacy-completed) GRN now surfaces its supplier for Purchase Return');

if (!$isLive) {
    echo "  \033[33m⊘\033[0m  Skipped (no includes/config.php — not a live install)\n";
} else {
    global $pdo;
    try {
        $whRow2 = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
        $supplierId2 = (int)$pdo->query("SELECT supplier_id FROM suppliers WHERE status='active' LIMIT 1")->fetchColumn();
        if (!$whRow2 || !$supplierId2) {
            echo "  \033[33m⊘\033[0m  Skipped (missing warehouse or supplier fixture data)\n";
        } else {
            $wh = (int)$whRow2;
            $ins = $pdo->prepare("
                INSERT INTO purchase_receipts (receipt_number, receipt_date, warehouse_id, supplier_id, status)
                VALUES ('TEST-GRN-APPROVED', CURDATE(), ?, ?, 'approved')
            ");
            $ins->execute([$wh, $supplierId2]);
            $testReceiptId = (int)$pdo->lastInsertId();

            // The exact query get_warehouse_suppliers.php now runs.
            $supRows = $pdo->prepare("
                SELECT DISTINCT s.supplier_id FROM suppliers s
                INNER JOIN purchase_receipts pr ON s.supplier_id = pr.supplier_id
                WHERE pr.warehouse_id = ? AND pr.status IN ('approved', 'completed') AND s.status = 'active'
            ");
            $supRows->execute([$wh]);
            check(in_array($supplierId2, $supRows->fetchAll(PDO::FETCH_COLUMN), false), 'an approved-status GRN\'s supplier now appears in the Return-creation supplier picker', 'STILL BROKEN: an approved GRN\'s supplier does not appear');

            // The exact query get_warehouse_supplier_grns.php now runs.
            $grnRows = $pdo->prepare("
                SELECT receipt_id FROM purchase_receipts
                WHERE warehouse_id = ? AND supplier_id = ? AND status IN ('approved', 'completed')
            ");
            $grnRows->execute([$wh, $supplierId2]);
            check(in_array($testReceiptId, $grnRows->fetchAll(PDO::FETCH_COLUMN), false), 'the approved GRN itself now appears in the GRN picker for Purchase Return', 'STILL BROKEN: the approved GRN does not appear in its own picker');

            $pdo->prepare("DELETE FROM purchase_receipts WHERE receipt_id = ?")->execute([$testReceiptId]);
            pass('test data cleaned up (self-contained, no residue left in the DB)');
        }
    } catch (Throwable $e) {
        fail('Live GRN-status test threw: ' . $e->getMessage());
    }
}

echo "\nPasses:   \033[32m$passes\033[0m\n";
echo "Failures: " . ($failures > 0 ? "\033[31m$failures\033[0m" : "\033[32m0\033[0m") . "\n";
exit($failures > 0 ? 1 : 0);
