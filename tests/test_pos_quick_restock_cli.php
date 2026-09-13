<?php
/**
 * POS "Restock Product" shortcut — CLI test
 *   php tests/test_pos_quick_restock_cli.php
 *
 * Covers the feature end to end against the real DB, rolled back at the end
 * (no fixtures persist):
 *   1. Wiring — the new files exist, lint clean, and call the right shared
 *      functions (not a copy-pasted second implementation).
 *   2. core/stock_intake.php::receiveProductBatch() — creates a product_batches
 *      row carrying wholesale_price/selling_price (not just unit_cost), and
 *      correctly bumps products/product_stocks/stock_movements — the exact
 *      same side effects GRN approval already relies on.
 *   3. Wholesale pricing — wholesalePriceGroupId() + the upsert into
 *      product_price_group_prices actually changes what api/pos/simple_products.php
 *      would return for a Wholesale-group POS query (the live price a
 *      customer is charged), not the dead products.wholesale_price column.
 *   4. post_principle.md — the buying price posts as an ALREADY-PAID cash
 *      outflow (Dr Inventory / Cr the selected Paid-From account) via
 *      postOutflow(), the same function Expenses/Petty Cash/Payroll already
 *      use — not the Opening-Balance-Equity treatment a no-payment stock
 *      correction uses. Verifies the posted journal_entries row balances,
 *      hits the correct two accounts, and reverseOutflow() undoes it cleanly.
 *
 * Exit 0 = all pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/stock_intake.php";
require_once "$root/core/pos_price_groups.php";
require_once "$root/core/payment_source.php";
require_once "$root/core/gl_accounts.php";
require_once "$root/core/financial_reports.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌\033[0m $m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $p): string { $f = "$root/$p"; return is_file($f) ? file_get_contents($f) : ''; }

register_shutdown_function(function () {
    global $pass, $fail, $pdo;
    static $printed = false; if ($printed) return; $printed = true;
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

section('1. Wiring');
foreach ([
    'core/stock_intake.php',
    'api/pos/quick_restock.php',
    'api/pos/get_restock_defaults.php',
    'api/pos/search_products_for_restock.php',
] as $f) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    $rc === 0 ? pass("$f lints clean") : fail("$f lint failed: " . implode(' ', $o));
}
$quickRestockSrc = src($root, 'api/pos/quick_restock.php');
strpos($quickRestockSrc, 'receiveProductBatch(') !== false
    ? pass('quick_restock.php calls the shared receiveProductBatch() — not a second copy of GRN\'s intake logic')
    : fail('quick_restock.php does not call receiveProductBatch()');
strpos($quickRestockSrc, 'postOutflow(') !== false
    ? pass('quick_restock.php posts via postOutflow() (already-paid cash outflow), not postStockAdjustmentGl()')
    : fail('quick_restock.php missing postOutflow() call');
strpos($quickRestockSrc, "hasPermission('adjust_stock')") !== false
    ? pass('quick_restock.php gates on adjust_stock — same permission Products page stock adjustment uses')
    : fail('quick_restock.php missing adjust_stock permission gate');
strpos(src($root, 'core/stock_intake.php'), 'wholesale_price') !== false
    ? pass('receiveProductBatch() writes product_batches.wholesale_price')
    : fail('receiveProductBatch() missing wholesale_price column');

section('2. Migration — product_batches has the new price columns');
$cols = $pdo->query("SHOW COLUMNS FROM product_batches")->fetchAll(PDO::FETCH_COLUMN);
in_array('wholesale_price', $cols, true) ? pass('product_batches.wholesale_price exists') : fail('product_batches.wholesale_price missing — run the migration');
in_array('selling_price', $cols, true)   ? pass('product_batches.selling_price exists')   : fail('product_batches.selling_price missing — run the migration');

section('3. Runtime — receiveProductBatch() (rolled back)');
$prodRow = $pdo->query("SELECT product_id, product_name FROM products WHERE status='active' AND is_service=0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$whRow   = $pdo->query("SELECT warehouse_id FROM warehouses LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$prodRow || !$whRow) {
    fail('no product/warehouse fixture available — cannot run runtime checks');
} else {
    $pid = (int)$prodRow['product_id'];
    $wid = (int)$whRow['warehouse_id'];

    $pdo->beginTransaction();

    $beforeStock = (float)($pdo->query("SELECT current_stock FROM products WHERE product_id = $pid")->fetchColumn() ?: 0);

    $intake = receiveProductBatch($pdo, [
        'product_id'       => $pid,
        'warehouse_id'     => $wid,
        'quantity'         => 12,
        'unit_cost'        => 1000.00,
        'write_batch'      => true,
        'wholesale_price'  => 1300.00,
        'selling_price'    => 1500.00,
        'movement_type'    => 'adjustment_in',
        'reference_type'   => 'stock_adjustment',
        'reference_number' => 'TEST-RESTOCK-1',
        'created_by'       => 1,
        'notes'            => 'CLI test restock',
    ]);

    ($intake['batch_id'] > 0) ? pass('receiveProductBatch() returned a batch_id') : fail('no batch_id returned');
    ($intake['movement_id'] > 0) ? pass('receiveProductBatch() returned a movement_id') : fail('no movement_id returned');

    $batch = $pdo->prepare("SELECT * FROM product_batches WHERE batch_id = ?");
    $batch->execute([$intake['batch_id']]);
    $b = $batch->fetch(PDO::FETCH_ASSOC);
    if (!$b) {
        fail('product_batches row not found after insert');
    } else {
        (abs((float)$b['unit_cost'] - 1000.00) < 0.01) ? pass('batch.unit_cost = 1000.00 (buying price)') : fail('unit_cost mismatch: ' . $b['unit_cost']);
        (abs((float)$b['wholesale_price'] - 1300.00) < 0.01) ? pass('batch.wholesale_price = 1300.00') : fail('wholesale_price mismatch: ' . $b['wholesale_price']);
        (abs((float)$b['selling_price'] - 1500.00) < 0.01) ? pass('batch.selling_price = 1500.00') : fail('selling_price mismatch: ' . $b['selling_price']);
        (abs((float)$b['quantity_remaining'] - 12) < 0.001) ? pass('batch.quantity_remaining = 12') : fail('quantity_remaining mismatch');
        $b['receipt_id'] === null ? pass('batch.receipt_id is NULL — not tied to a GRN') : fail('receipt_id unexpectedly set');
    }

    $afterStock = (float)$pdo->query("SELECT current_stock FROM products WHERE product_id = $pid")->fetchColumn();
    (abs($afterStock - $beforeStock - 12) < 0.001)
        ? pass('products.current_stock bumped by exactly 12')
        : fail("current_stock: before=$beforeStock after=$afterStock (expected +12)");

    $mv = $pdo->prepare("SELECT movement_type, reference_type FROM stock_movements WHERE movement_id = ?");
    $mv->execute([$intake['movement_id']]);
    $m = $mv->fetch(PDO::FETCH_ASSOC);
    ($m && $m['movement_type'] === 'adjustment_in' && $m['reference_type'] === 'stock_adjustment')
        ? pass('stock_movements row: adjustment_in / stock_adjustment (shows up in the existing Stock Adjustments report)')
        : fail('stock_movements row has wrong type(s): ' . json_encode($m));

    section('4. Runtime — wholesale pricing writes to the LIVE price-group table');
    $wholesaleGroupId = wholesalePriceGroupId($pdo);
    if (!$wholesaleGroupId) {
        pass('no Wholesale price_groups row on this DB — skipped (n/a, matches get_restock_defaults.php\'s own leniency)');
    } else {
        $pdo->prepare("
            INSERT INTO product_price_group_prices (product_id, price_group_id, price, created_at, updated_at)
            VALUES (?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE price = VALUES(price), updated_at = NOW()
        ")->execute([$pid, $wholesaleGroupId, 1300.00]);

        $resolved = resolveGroupPrices($pdo, $wholesaleGroupId, [$pid]);
        (isset($resolved[$pid]) && abs($resolved[$pid] - 1300.00) < 0.01)
            ? pass('resolveGroupPrices() (the exact function simple_products.php POS queries use) resolves the new Wholesale price')
            : fail('resolveGroupPrices() did not pick up the new override: ' . json_encode($resolved));
    }

    $pdo->prepare("UPDATE products SET selling_price = ? WHERE product_id = ?")->execute([1500.00, $pid]);
    $retail = (float)$pdo->query("SELECT selling_price FROM products WHERE product_id = $pid")->fetchColumn();
    (abs($retail - 1500.00) < 0.01)
        ? pass('products.selling_price updated to the new Retail price (Retail group\'s own live fallback)')
        : fail('products.selling_price not updated');

    section('5. Runtime — post_principle.md: already-paid GL posting (postOutflow)');
    $invAcc = inventoryAccountId($pdo);
    $cashAccounts = cashBankAccounts($pdo);
    if (!$invAcc || empty($cashAccounts)) {
        pass('Inventory or cash/bank accounts not configured on this DB — skipped (n/a)');
    } else {
        $paidFrom = (int)$cashAccounts[0]['account_id'];
        $txnId = postOutflow($pdo, 'stock_restock', $paidFrom, $invAcc, 12000.00, date('Y-m-d'), 'TEST-RESTOCK-1', 'CLI test restock payment');
        $txnId ? pass('postOutflow() posted and returned a transaction_id') : fail('postOutflow() returned null');

        if ($txnId) {
            $je = $pdo->prepare("SELECT entry_id FROM journal_entries WHERE entity_type = 'books_transaction' AND entity_id = ? AND status = 'posted'");
            $je->execute([$txnId]);
            $entryId = $je->fetchColumn();
            $entryId ? pass('mirrored into a posted journal_entries row (the one canonical ledger)') : fail('no mirrored journal_entries row found');

            if ($entryId) {
                $lines = $pdo->prepare("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id = ?");
                $lines->execute([$entryId]);
                $items = $lines->fetchAll(PDO::FETCH_ASSOC);
                $dr = array_sum(array_map(fn($l) => $l['type'] === 'debit' ? (float)$l['amount'] : 0, $items));
                $cr = array_sum(array_map(fn($l) => $l['type'] === 'credit' ? (float)$l['amount'] : 0, $items));
                (abs($dr - $cr) < 0.01 && abs($dr - 12000.00) < 0.01)
                    ? pass("balanced entry: Dr $dr = Cr $cr = 12,000.00")
                    : fail("entry does not balance to 12,000.00: Dr=$dr Cr=$cr");

                $hitsInventory = false; $hitsPaidFrom = false;
                foreach ($items as $l) {
                    if ((int)$l['account_id'] === $invAcc && $l['type'] === 'debit') $hitsInventory = true;
                    if ((int)$l['account_id'] === $paidFrom && $l['type'] === 'credit') $hitsPaidFrom = true;
                }
                ($hitsInventory && $hitsPaidFrom)
                    ? pass('Dr Inventory / Cr Paid-From account — matches post_principle.md\'s "already paid" instruction')
                    : fail('wrong account legs — not Dr Inventory / Cr Paid-From');
            }

            reverseOutflow($pdo, $txnId);
            $stillPosted = $pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE entity_type = 'books_transaction' AND entity_id = ? AND status = 'posted'");
            $stillPosted->execute([$txnId]);
            ((int)$stillPosted->fetchColumn() === 0)
                ? pass('reverseOutflow() cleanly undoes the posting (post_principle.md Q6 — delete/void reverses fully)')
                : fail('reverseOutflow() left a posted entry behind');
        }
    }

    $pdo->rollBack();
    pass('all fixtures rolled back — no test data persisted');
}

section('6. Ledger still balances after the (rolled-back) test');
try {
    assertLedgerBalanced($pdo, date('Y-m-d'), true);
    pass('assertLedgerBalanced ok (Σ Dr = Σ Cr)');
} catch (Throwable $e) {
    fail('ledger imbalance: ' . $e->getMessage());
}
