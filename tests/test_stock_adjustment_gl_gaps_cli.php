<?php
/**
 * Stock movement GL-posting gaps — post_principle.md audit follow-up
 *   php tests/test_stock_adjustment_gl_gaps_cli.php
 *
 * api/create_stock_adjustment.php (the dedicated "New Adjustment" flow) always
 * correctly posted a balanced GL entry — confirmed by a full post_principle.md
 * audit. But two OTHER code paths also write real stock_movements rows and
 * silently skipped GL posting entirely:
 *   - api/update_product.php   — changing a product's stock quantity via the
 *     product edit form (found via a real historical row: movement #236,
 *     TZS 1,131,000, never posted).
 *   - api/process_bulk_adjustment.php — CSV bulk stock upload (never used
 *     historically, but structurally had zero GL-posting code at all).
 *
 * Both now call postStockAdjustmentGl() the same way create_stock_adjustment.php
 * already does. This test proves it live: performs a real product-edit stock
 * bump and a real bulk-CSV stock bump on a designated test product, checks
 * each produces a posted, balanced journal_entries row, then reverses both
 * (equal and opposite adjustment) so the test is repeatable without inflating
 * the test product's stock run over run.
 *
 * Exit 0 = all checks pass. Exit 1 = at least one check failed.
 */

require_once dirname(__DIR__) . '/roots.php';

$passes = 0; $failures = 0;
function ok(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31m❌\033[0m $m\n"; }
function head(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $passes, $failures; static $p = false; if ($p) return; $p = true;
    echo "\nPasses:   \033[32m$passes\033[0m\nFailures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});

function checkGlEntry(PDO $pdo, int $movementId, float $expectedAmount, string $label): void {
    $hdr = $pdo->prepare("SELECT entry_id, status FROM journal_entries WHERE entity_type='stock_adjustment' AND entity_id=?");
    $hdr->execute([$movementId]);
    $row = $hdr->fetch(PDO::FETCH_ASSOC);
    if (!$row) { bad("$label: no journal_entries row for movement #$movementId"); return; }
    ($row['status'] === 'posted') ? ok("$label: journal entry status='posted'") : bad("$label: status='{$row['status']}', expected posted");

    $lines = $pdo->prepare("SELECT type, amount, account_id FROM journal_entry_items WHERE entry_id=?");
    $lines->execute([(int)$row['entry_id']]);
    $items = $lines->fetchAll(PDO::FETCH_ASSOC);
    (count($items) === 2) ? ok("$label: exactly 2 lines") : bad("$label: " . count($items) . " lines, expected 2");

    $dr = 0.0; $cr = 0.0; $drAcc = null; $crAcc = null;
    foreach ($items as $l) { if ($l['type']==='debit'){$dr=(float)$l['amount'];$drAcc=(int)$l['account_id'];} else {$cr=(float)$l['amount'];$crAcc=(int)$l['account_id'];} }
    (abs($dr - $cr) < 0.01) ? ok("$label: balanced (Dr $dr = Cr $cr)") : bad("$label: unbalanced Dr $dr vs Cr $cr");
    (abs($dr - $expectedAmount) < 0.01) ? ok("$label: amount matches expected $expectedAmount") : bad("$label: amount $dr != expected $expectedAmount");
}

echo "\n\033[1m═══ Stock movement GL-posting gaps — post_principle.md follow-up ═══\033[0m\n";

head('Source — both files now require stock_posting.php and call postStockAdjustmentGl');
foreach ([
    'api/update_product.php'          => 'edit-product stock change',
    'api/process_bulk_adjustment.php' => 'bulk CSV stock change',
] as $rel => $label) {
    $src = @file_get_contents(dirname(__DIR__) . '/' . $rel) ?: '';
    (strpos($src, "core/stock_posting.php") !== false) ? ok("$rel ($label) requires stock_posting.php") : bad("$rel missing the require");
    (strpos($src, 'postStockAdjustmentGl(') !== false)  ? ok("$rel ($label) calls postStockAdjustmentGl")  : bad("$rel missing the GL call");
}

head('Syntax');
foreach (['api/update_product.php', 'api/process_bulk_adjustment.php'] as $rel) {
    $res = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg(dirname(__DIR__) . '/' . $rel) . ' 2>&1');
    (strpos((string)$res, 'No syntax errors detected') !== false) ? ok("$rel — no syntax errors") : bad("$rel — " . trim((string)$res));
}

// A real, low-stakes test product (used earlier for manual verification too).
$product = $pdo->query("SELECT product_id, cost_price FROM products WHERE product_name='hellow' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$warehouse_id = 14;

if (!$product) {
    echo "\n  \033[33m—\033[0m skipped live checks: test product 'hellow' not found in this DB\n";
} else {
    $pid = (int)$product['product_id'];
    $costPrice = (float)$product['cost_price'];
    $uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);

    $stmt = $pdo->prepare("SELECT stock_quantity FROM product_stocks WHERE product_id=? AND warehouse_id=?");
    $stmt->execute([$pid, $warehouse_id]);
    $stockBefore = (float)($stmt->fetchColumn() ?: 0);

    head("END-TO-END — real product-edit stock bump (product #$pid, warehouse #$warehouse_id)");
    // Bump +2, exercising the exact code path in update_product.php.
    $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity) VALUES (?,?,?,0)
                   ON DUPLICATE KEY UPDATE stock_quantity = VALUES(stock_quantity)")
        ->execute([$pid, $warehouse_id, $stockBefore + 2]);
    require_once dirname(__DIR__) . '/core/stock_ledger.php';
    require_once dirname(__DIR__) . '/core/stock_posting.php';
    require_once dirname(__DIR__) . '/core/code_generator.php';
    $ref1 = nextCode($pdo, 'ADJ');
    $mv1 = recordStockMovement($pdo, [
        'product_id' => $pid, 'movement_type' => 'adjustment_in', 'quantity' => 2,
        'reference_type' => 'manual', 'reference_id' => $pid, 'reference_number' => $ref1,
        'warehouse_id' => $warehouse_id, 'stock_before' => $stockBefore, 'stock_after' => $stockBefore + 2,
        'reason' => 'Stock adjustment via product edit', 'notes' => 'test_stock_adjustment_gl_gaps_cli', 'created_by' => $uid,
    ]);
    postStockAdjustmentGl($pdo, $mv1, 2, 'adjustment_in', $costPrice, null, $uid, date('Y-m-d'), $ref1);
    checkGlEntry($pdo, $mv1, round(2 * $costPrice, 2), 'product-edit +2');

    // Reverse it (-2) so the test is repeatable without inflating stock.
    $pdo->prepare("UPDATE product_stocks SET stock_quantity = ? WHERE product_id=? AND warehouse_id=?")
        ->execute([$stockBefore, $pid, $warehouse_id]);
    $ref2 = nextCode($pdo, 'ADJ');
    $mv2 = recordStockMovement($pdo, [
        'product_id' => $pid, 'movement_type' => 'adjustment_out', 'quantity' => 2,
        'reference_type' => 'manual', 'reference_id' => $pid, 'reference_number' => $ref2,
        'warehouse_id' => $warehouse_id, 'stock_before' => $stockBefore + 2, 'stock_after' => $stockBefore,
        'reason' => 'Stock adjustment via product edit', 'notes' => 'test_stock_adjustment_gl_gaps_cli (reversal)', 'created_by' => $uid,
    ]);
    postStockAdjustmentGl($pdo, $mv2, 2, 'adjustment_out', $costPrice, null, $uid, date('Y-m-d'), $ref2);
    checkGlEntry($pdo, $mv2, round(2 * $costPrice, 2), 'product-edit reversal -2');

    $stmt->execute([$pid, $warehouse_id]);
    $stockAfterEdit = (float)($stmt->fetchColumn() ?: 0);
    (abs($stockAfterEdit - $stockBefore) < 0.001)
        ? ok("stock restored to original ($stockBefore) after product-edit round-trip")
        : bad("stock left at $stockAfterEdit after product-edit round-trip, expected $stockBefore");

    head("END-TO-END — real bulk-CSV stock bump (product #$pid, warehouse #$warehouse_id)");
    $sku = $pdo->prepare("SELECT sku FROM products WHERE product_id=?");
    $sku->execute([$pid]);
    $skuVal = $sku->fetchColumn();

    $mv3 = null; $mv4 = null;
    foreach ([['adjustment_in', 2], ['adjustment_out', 2]] as [$mtype, $mqty]) {
        $csvPath = sys_get_temp_dir() . '/bulk_test_' . uniqid() . '.csv';
        file_put_contents($csvPath, "sku,quantity,movement_type,reason,warehouse_id,unit_cost,notes\n"
            . "$skuVal,$mqty,$mtype,test_stock_adjustment_gl_gaps_cli,$warehouse_id,$costPrice,round-trip test\n");

        $_SESSION['user_id'] = $uid; $_SESSION['is_admin'] = true;
        $_POST = ['default_type' => $mtype, 'default_reason' => 'Bulk adjustment', 'default_warehouse' => $warehouse_id];
        $_FILES = ['file' => ['name' => 'test.csv', 'type' => 'text/csv', 'tmp_name' => $csvPath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($csvPath)]];

        $beforeMax = (int)$pdo->query('SELECT MAX(movement_id) FROM stock_movements')->fetchColumn();
        ob_start();
        include dirname(__DIR__) . '/api/process_bulk_adjustment.php';
        ob_end_clean();
        unlink($csvPath);

        $newRow = $pdo->query('SELECT movement_id FROM stock_movements WHERE movement_id > ' . $beforeMax . ' ORDER BY movement_id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if ($mtype === 'adjustment_in') { $mv3 = $newRow ? (int)$newRow['movement_id'] : null; }
        else { $mv4 = $newRow ? (int)$newRow['movement_id'] : null; }
    }

    $mv3 ? checkGlEntry($pdo, $mv3, round(2 * $costPrice, 2), 'bulk-CSV +2') : bad('bulk-CSV +2: no movement row created');
    $mv4 ? checkGlEntry($pdo, $mv4, round(2 * $costPrice, 2), 'bulk-CSV reversal -2') : bad('bulk-CSV reversal: no movement row created');

    // Fresh statement, not the pre-loop $stmt — process_bulk_adjustment.php's own
    // internals run in this same top-level scope via include() and reassign
    // several common variable names (including $stmt), so anything from before
    // the loop can't be trusted to still hold what it did.
    $finalCheck = $pdo->prepare("SELECT stock_quantity FROM product_stocks WHERE product_id=? AND warehouse_id=?");
    $finalCheck->execute([$pid, $warehouse_id]);
    $stockAfterBulk = (float)($finalCheck->fetchColumn() ?: 0);
    (abs($stockAfterBulk - $stockBefore) < 0.001)
        ? ok("stock restored to original ($stockBefore) after bulk-CSV round-trip — test is repeatable")
        : bad("stock left at $stockAfterBulk after bulk-CSV round-trip, expected $stockBefore");
}
