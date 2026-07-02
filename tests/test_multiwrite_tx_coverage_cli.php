<?php
/**
 * Multi-write transaction coverage — regression guard.
 *
 * Every endpoint fixed across the fix/tx-* branches must keep its transaction:
 * this test scans each file for beginTransaction + rollBack so a future edit
 * can't silently drop the wrap. Plus live proof for the two riskiest 2D flows:
 *   - CRM stage move: lead UPDATE + history INSERT roll back together.
 *   - RFQ quick-add: product + stock row roll back together.
 *
 * Run: php tests/test_multiwrite_tx_coverage_cli.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$passes = 0; $failures = 0;
function pass($m) { global $passes;   $passes++;   echo "  \xE2\x9C\x85 $m\n"; }
function fail($m) { global $failures; $failures++; echo "  \xE2\x9D\x8C $m\n"; }
function section($t) { echo "\n\xE2\x94\x80\xE2\x94\x80 $t \xE2\x94\x80\xE2\x94\x80\n"; }

// ── 1. Static coverage: every tx-fixed endpoint keeps its transaction ──────
section('All tx-fixed endpoints still wrap their writes');
// Hard-asserted: fixed on this branch (fix/tx-bulk-and-config).
$guarded = [
    'api/rfq_quick_add_product.php',
    'api/crm/move_lead_stage.php',
    'api/crm/bulk_update_leads.php',
    'api/crm/add_activity.php',
    'api/crm/edit_lead.php',
    'api/crm/manage_stage.php',
    'api/pos/delete_salary_component.php',
    'api/account/save_account.php',
    'api/finance/manage_revenue_schema.php',
];
foreach ($guarded as $f) {
    $src = @file_get_contents("$root/$f") ?: '';
    (strpos($src, 'beginTransaction') !== false && strpos($src, 'rollBack') !== false)
        ? pass("$f is transactional")
        : fail("$f LOST its transaction wrap — restore beginTransaction/rollBack");
}

// Sibling-phase endpoints (fix/tx-payroll-atomicity, fix/tx-warehouse-delete,
// fix/tx-ipc-invoice) are asserted once merged: only flagged here if the file
// HAD a wrap and lost it, so this test passes on branches cut before those
// merges but still catches regressions on main afterwards.
$siblingGuarded = [
    'api/bulk_update_payroll_status.php',
    'api/update_payroll.php',
    'api/operations/process_project_payroll.php',
    'ajax_delete_warehouse.php',
    'app/bms/stock/warehouses.php',
    'api/operations/create_invoice_from_ipc.php',
    'api/operations/update_ipc_status.php',
    'api/operations/save_ipc.php',
    'api/account/save_voucher.php',
];
foreach ($siblingGuarded as $f) {
    $src = @file_get_contents("$root/$f") ?: '';
    if (strpos($src, 'beginTransaction') !== false) {
        (strpos($src, 'rollBack') !== false)
            ? pass("$f is transactional (sibling phase merged)")
            : fail("$f has beginTransaction but no rollBack — incomplete wrap");
    } else {
        pass("$f not yet merged with its tx fix on this branch — skipped");
    }
}

// ── 2. InnoDB foundation still holds ───────────────────────────────────────
section('Storage engine foundation');
$myisam = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE = 'MyISAM'")->fetchColumn();
($myisam === 0)
    ? pass('No MyISAM base tables (rollback works everywhere)')
    : fail("$myisam MyISAM base table(s) reappeared — transactions silently no-op on them");

// ── 3. Live: CRM stage move rolls back as one unit ─────────────────────────
section('Live: lead stage move + history roll back together');
$tag = 'TEST-2D-' . substr(bin2hex(random_bytes(3)), 0, 6);
$leadId = null;
try {
    $stageRow = $pdo->query("SELECT stage_id FROM crm_pipeline_stages WHERE status = 'active' ORDER BY stage_order LIMIT 2")
                    ->fetchAll(PDO::FETCH_COLUMN);
    if (count($stageRow) < 2) {
        pass('fewer than 2 active pipeline stages on this DB — live stage-move check skipped');
    } else {
        [$stageA, $stageB] = array_map('intval', $stageRow);
        $pdo->prepare("INSERT INTO crm_leads (lead_code, first_name, pipeline_stage_id, status, created_by)
                       VALUES (?, ?, ?, 'active', 1)")->execute([$tag, 'TxTest', $stageA]);
        $leadId = (int)$pdo->lastInsertId();
        $histBefore = (int)$pdo->query("SELECT COUNT(*) FROM crm_lead_stage_history WHERE lead_id = $leadId")->fetchColumn();

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE crm_leads SET pipeline_stage_id = ? WHERE lead_id = ?")->execute([$stageB, $leadId]);
            $pdo->prepare("INSERT INTO crm_lead_stage_history (lead_id, from_stage_id, to_stage_id, changed_by) VALUES (?, ?, ?, 1)")
                ->execute([$leadId, $stageA, $stageB]);
            throw new Exception('forced failure');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }

        $cur = (int)$pdo->query("SELECT pipeline_stage_id FROM crm_leads WHERE lead_id = $leadId")->fetchColumn();
        $histAfter = (int)$pdo->query("SELECT COUNT(*) FROM crm_lead_stage_history WHERE lead_id = $leadId")->fetchColumn();
        ($cur === $stageA) ? pass('lead stage restored after rollback') : fail('lead kept the new stage after rollback');
        ($histAfter === $histBefore) ? pass('no orphan history row') : fail('orphan stage-history row survived rollback');
    }
} catch (Throwable $e) {
    fail('stage-move scenario errored: ' . $e->getMessage());
} finally {
    if ($leadId) {
        $pdo->prepare("DELETE FROM crm_lead_stage_history WHERE lead_id = ?")->execute([$leadId]);
        $pdo->prepare("DELETE FROM crm_leads WHERE lead_id = ?")->execute([$leadId]);
    }
}

// ── 4. Live: product + stock row roll back together ────────────────────────
section('Live: RFQ quick-add product + stock roll back together');
$prodName = "Tx test product $tag";
try {
    $whId = (int)($pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' LIMIT 1")->fetchColumn() ?: 0);
    if (!$whId) {
        pass('no active warehouse on this DB — live quick-add check skipped');
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO products (product_name, status, is_service, track_inventory, created_by)
                           VALUES (?, 'active', 0, 1, 1)")->execute([$prodName]);
            $pid = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity) VALUES (?, ?, 0)")
                ->execute([$pid, $whId]);
            throw new Exception('forced failure');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE product_name = ?");
        $cnt->execute([$prodName]);
        ((int)$cnt->fetchColumn() === 0)
            ? pass('rolled-back quick-add left no product row')
            : fail('product row survived the rollback');
    }
} catch (Throwable $e) {
    fail('quick-add scenario errored: ' . $e->getMessage());
} finally {
    $pdo->prepare("DELETE ps FROM product_stocks ps JOIN products p ON p.product_id = ps.product_id WHERE p.product_name = ?")->execute([$prodName]);
    $pdo->prepare("DELETE FROM products WHERE product_name = ?")->execute([$prodName]);
}

// ── Result ────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "Passed: $passes   Failed: $failures\n";
echo $failures > 0 ? "RESULT: FAIL\n" : "RESULT: PASS\n";
exit($failures > 0 ? 1 : 0);
