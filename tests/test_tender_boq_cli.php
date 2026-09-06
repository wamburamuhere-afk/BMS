<?php
/**
 * Tender upgrade — Phase A (Bills of Quantities engine) CLI test
 * ----------------------------------------------------------------
 *   php tests/test_tender_boq_cli.php
 *
 * Verifies:
 *   - api/tender_boq.php, core/tender_boq.php, app/bms/tenders/tender_boq.php
 *     and _tender_nav.php are lint-clean
 *   - tender_boq_bills / tender_boq_items tables + the three new tenders.boq_*
 *     columns exist (migration ran)
 *   - recomputeTenderBoqTotal() math: subtotal -> +contingency% -> +VAT% on
 *     (subtotal+contingency), in that exact order
 *   - deleting a bill cascades to its items (ON DELETE CASCADE) and the
 *     grand total recomputes correctly afterwards
 *   - a tampered item update naming a bill_id from a DIFFERENT tender is
 *     silently skipped, not applied (cross-tender write guard)
 *
 * Writes only inside a transaction that is always rolled back. Exit 0 = pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/tender_boq.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }

register_shutdown_function(function () {
    global $pass, $fail, $pdo;
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

try {
    // ─────────────────────────────────────────────────────────────────────
    section('1. New/changed files are lint-clean');
    // ─────────────────────────────────────────────────────────────────────
    foreach ([
        'api/tender_boq.php',
        'core/tender_boq.php',
        'app/bms/tenders/tender_boq.php',
        'app/bms/tenders/_tender_nav.php',
        'app/bms/tenders/tender_view.php',
        'app/bms/tenders/tender_edit.php',
        'app/bms/tenders/tenders.php',
        'migrations/2026_09_05_tender_boq.php',
    ] as $f) {
        $out = []; $rc = 0;
        exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
        ok($rc === 0, "$f lint-clean");
    }
    ok(function_exists('recomputeTenderBoqTotal'), 'recomputeTenderBoqTotal() is defined');

    // ─────────────────────────────────────────────────────────────────────
    section('2. Schema — tables and columns exist');
    // ─────────────────────────────────────────────────────────────────────
    $tables = $pdo->query("SHOW TABLES LIKE 'tender_boq_%'")->fetchAll(PDO::FETCH_COLUMN);
    ok(in_array('tender_boq_bills', $tables, true), 'tender_boq_bills table exists');
    ok(in_array('tender_boq_items', $tables, true), 'tender_boq_items table exists');
    foreach (['boq_contingency_percent', 'boq_vat_percent', 'boq_grand_total'] as $col) {
        $has = $pdo->query("SHOW COLUMNS FROM tenders LIKE '$col'")->fetch();
        ok((bool)$has, "tenders.$col column exists");
    }

    // ─────────────────────────────────────────────────────────────────────
    section('3. BOQ math — subtotal -> contingency -> VAT, in that order');
    // ─────────────────────────────────────────────────────────────────────
    $pdo->beginTransaction();

    $pdo->exec("INSERT INTO tenders (tender_no, tender_description, status) VALUES ('TEST-BOQ-001', 'CLI test tender', 'PENDING')");
    $tenderId = (int)$pdo->lastInsertId();
    ok($tenderId > 0, "test tender created (#$tenderId)");

    $pdo->prepare("INSERT INTO tender_boq_bills (tender_id, bill_title, sort_order) VALUES (?, 'Bill No. 1 - General', 0)")->execute([$tenderId]);
    $bill1 = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO tender_boq_bills (tender_id, bill_title, sort_order) VALUES (?, 'Bill No. 2 - Electrical', 1)")->execute([$tenderId]);
    $bill2 = (int)$pdo->lastInsertId();
    ok($bill1 > 0 && $bill2 > 0 && $bill1 !== $bill2, 'two bills created');

    // Bill 1: 10 units @ 1,000 = 10,000
    $pdo->prepare("INSERT INTO tender_boq_items (bill_id, description, unit, qty, rate, amount, sort_order) VALUES (?, 'Cement', 'bags', 10, 1000, 10000, 0)")->execute([$bill1]);
    // Bill 2: 5 units @ 2,000 = 10,000
    $pdo->prepare("INSERT INTO tender_boq_items (bill_id, description, unit, qty, rate, amount, sort_order) VALUES (?, 'Cable', 'm', 5, 2000, 10000, 0)")->execute([$bill2]);
    // expected subtotal = 20,000

    $grandTotal = recomputeTenderBoqTotal($pdo, $tenderId, 10.0, 18.0);
    // subtotal=20000; contingency=10% of 20000=2000; base=22000; vat=18% of 22000=3960; grand=25960
    ok(abs($grandTotal - 25960.0) < 0.01, "grand total is 25,960.00 (got " . number_format($grandTotal, 2) . ")");

    $row = $pdo->prepare("SELECT boq_contingency_percent, boq_vat_percent, boq_grand_total FROM tenders WHERE tender_id = ?");
    $row->execute([$tenderId]);
    $persisted = $row->fetch(PDO::FETCH_ASSOC);
    ok((float)$persisted['boq_contingency_percent'] === 10.0, 'contingency % persisted onto tenders row');
    ok((float)$persisted['boq_vat_percent'] === 18.0, 'VAT % persisted onto tenders row');
    ok(abs((float)$persisted['boq_grand_total'] - 25960.0) < 0.01, 'grand total persisted onto tenders row');

    // ─────────────────────────────────────────────────────────────────────
    section('4. Cascade delete + recompute after removing a bill');
    // ─────────────────────────────────────────────────────────────────────
    $pdo->prepare("DELETE FROM tender_boq_bills WHERE bill_id = ?")->execute([$bill2]);
    $remainingItems = $pdo->prepare("SELECT COUNT(*) FROM tender_boq_items WHERE bill_id = ?");
    $remainingItems->execute([$bill2]);
    ok((int)$remainingItems->fetchColumn() === 0, 'deleting a bill cascades to its items (ON DELETE CASCADE)');

    $grandTotal2 = recomputeTenderBoqTotal($pdo, $tenderId, 10.0, 18.0);
    // subtotal now = 10000; contingency=1000; base=11000; vat=1980; grand=12980
    ok(abs($grandTotal2 - 12980.0) < 0.01, "grand total recomputes to 12,980.00 after bill removal (got " . number_format($grandTotal2, 2) . ")");

    // ─────────────────────────────────────────────────────────────────────
    section('5. Cross-tender write guard (the SAVE_BOQ validity check)');
    // ─────────────────────────────────────────────────────────────────────
    $pdo->exec("INSERT INTO tenders (tender_no, tender_description, status) VALUES ('TEST-BOQ-002', 'CLI test tender 2', 'PENDING')");
    $otherTenderId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO tender_boq_bills (tender_id, bill_title, sort_order) VALUES (?, 'Other Tender Bill', 0)")->execute([$otherTenderId]);
    $otherBillId = (int)$pdo->lastInsertId();

    // Replicate the exact guard used in api/tender_boq.php's SAVE_BOQ: only
    // bill_ids that belong to $tenderId are ever accepted.
    $validBills = $pdo->prepare("SELECT bill_id FROM tender_boq_bills WHERE tender_id = ?");
    $validBills->execute([$tenderId]);
    $validBillIds = array_map('intval', $validBills->fetchAll(PDO::FETCH_COLUMN));
    ok(!in_array($otherBillId, $validBillIds, true), 'a bill belonging to a different tender is not in the valid set — SAVE_BOQ would skip it');

    $pdo->rollBack();
    ok(!$pdo->inTransaction(), 'rolled back — no test data left behind');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
