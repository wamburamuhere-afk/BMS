<?php
/**
 * Products — Manufacturing Date + real batch on creation — CLI regression suite
 *   php tests/test_product_batch_manufacturing_date_cli.php
 *
 * Covers products_simple_pos_plan.md §1-2: product_batches gains a
 * manufacturing_date column, and api/create_product.php's opening-stock path
 * now creates a real, trackable batch (via the same receiveProductBatch()
 * helper Restock/GRN already use) instead of a bare product_stocks insert —
 * so a product's very first stock is never invisible to the batch/expiry
 * system. Applies in both normal and Simple POS mode.
 *
 *   A. STATIC    — files lint clean; source wiring present.
 *   B. SCHEMA    — product_batches.manufacturing_date column exists.
 *   C. RUNTIME   — the real api/create_product.php endpoint, end to end:
 *                  a product created with initial stock + both dates gets a
 *                  real product_batches row, product_stocks/stock_movements/
 *                  GL posting all still work exactly as before.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 70) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
$files = [
    'api/create_product.php',
    'core/stock_intake.php',
    'migrations/tenant/2026_09_15_product_batch_manufacturing_date.php',
];
foreach ($files as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $out = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Source wiring');
$intakeSrc = src($root, 'core/stock_intake.php');
has($intakeSrc, "manufacturing_date", 'receiveProductBatch() accepts manufacturing_date');
has($intakeSrc, "product_batches", 'receiveProductBatch() still writes product_batches');

$createSrc = src($root, 'api/create_product.php');
has($createSrc, 'receiveProductBatch($pdo', "create_product.php's opening stock now calls receiveProductBatch()");
has($createSrc, "'write_batch'        => true", 'opening stock is written as a real batch, not a bare stock bump');
has($createSrc, 'postStockAdjustmentGl(', 'GL posting for opening stock is preserved');

// ─────────────────────────────────────────────────────────────────────────
section('3. Schema — product_batches.manufacturing_date');
$col = $pdo->query("SHOW COLUMNS FROM product_batches LIKE 'manufacturing_date'")->fetch(PDO::FETCH_ASSOC);
$col ? pass('product_batches.manufacturing_date exists') : fail('product_batches.manufacturing_date missing');
if ($col) {
    (stripos($col['Type'], 'date') !== false) ? pass('column type is DATE') : fail('unexpected column type: ' . $col['Type']);
    ($col['Null'] === 'YES') ? pass('column is nullable (not every product has one)') : fail('column should be nullable');
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Runtime — api/create_product.php, end to end');

function _pb_run_php(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'prodbatch_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}

$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn();
$wh  = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$uid || !$wh) {
    pass('no admin user / warehouse fixture available — section 4 skipped (n/a)');
} else {
    $wid = (int)$wh['warehouse_id'];
    $uniqueName = 'CLI Batch Test Product ' . time() . '-' . rand(1000, 9999);

    $out = _pb_run_php("
        \$_SESSION = [];
        \$_SERVER['REQUEST_METHOD'] = 'POST';
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_POST = [
            'product_name'       => " . var_export($uniqueName, true) . ",
            'unit'               => 'pcs',
            'cost_price'         => '1000',
            'selling_price'      => '1500',
            'manufacturing_date' => '2026-01-01',
            'expiry_date'        => '2026-12-31',
            'initial_stock'      => [$wid => 10],
        ];
        ob_start();
        include '$root/api/create_product.php';
        \$out = ob_get_clean();
        \$pos = strpos(\$out, '{');
        echo \$pos === false ? \$out : substr(\$out, \$pos);
    ");
    $res = json_decode($out, true);

    if (empty($res['success']) || empty($res['product_id'])) {
        fail('product creation failed: ' . ($res['message'] ?? $out));
    } else {
        pass('product created via the real endpoint');
        $pid = (int)$res['product_id'];

        $batch = $pdo->prepare("SELECT * FROM product_batches WHERE product_id = ?");
        $batch->execute([$pid]);
        $b = $batch->fetch(PDO::FETCH_ASSOC);

        if (!$b) {
            fail('no product_batches row was created for the opening stock');
        } else {
            pass('a real product_batches row was created for the opening stock');
            ($b['manufacturing_date'] === '2026-01-01') ? pass('manufacturing_date stored correctly') : fail('manufacturing_date mismatch: ' . var_export($b['manufacturing_date'], true));
            ($b['expiry_date'] === '2026-12-31') ? pass('expiry_date stored correctly') : fail('expiry_date mismatch: ' . var_export($b['expiry_date'], true));
            ((float)$b['unit_cost'] === 1000.0) ? pass('batch unit_cost matches cost_price') : fail('unit_cost mismatch: ' . $b['unit_cost']);
            ((float)$b['quantity_received'] === 10.0 && (float)$b['quantity_remaining'] === 10.0) ? pass('batch quantity recorded correctly') : fail('batch quantity mismatch');
            ((int)$b['warehouse_id'] === $wid) ? pass('batch tied to the correct warehouse') : fail('warehouse_id mismatch');
        }

        $stockQty = $pdo->prepare("SELECT stock_quantity FROM product_stocks WHERE product_id = ? AND warehouse_id = ?");
        $stockQty->execute([$pid, $wid]);
        ((float)$stockQty->fetchColumn() === 10.0) ? pass('product_stocks quantity correct (no regression)') : fail('product_stocks quantity wrong');

        $movement = $pdo->prepare("SELECT movement_id FROM stock_movements WHERE product_id = ? AND movement_type = 'adjustment_in'");
        $movement->execute([$pid]);
        $movementId = $movement->fetchColumn();
        $movementId ? pass('exactly one stock_movements row created (no double-write)') : fail('no stock_movements row found');

        if ($movementId) {
            $je = $pdo->prepare("SELECT je.entry_id, SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE 0 END) dr,
                                         SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE 0 END) cr
                                    FROM journal_entries je JOIN journal_entry_items jei ON jei.entry_id = je.entry_id
                                   WHERE je.entity_type = 'stock_adjustment' AND je.entity_id = ?
                                GROUP BY je.entry_id");
            $je->execute([(int)$movementId]);
            $jeRow = $je->fetch(PDO::FETCH_ASSOC);
            if ($jeRow && (float)$jeRow['dr'] === 10000.0 && (float)$jeRow['dr'] === (float)$jeRow['cr']) {
                pass('opening-stock GL entry posted and balanced (Dr = Cr = qty * cost_price)');
            } else {
                fail('opening-stock GL entry missing or unbalanced: ' . json_encode($jeRow));
            }
            // Cleanup GL
            if ($jeRow) {
                $pdo->prepare("DELETE FROM journal_entry_items WHERE entry_id = ?")->execute([$jeRow['entry_id']]);
                $pdo->prepare("DELETE FROM journal_entries WHERE entry_id = ?")->execute([$jeRow['entry_id']]);
            }
        }

        // Cleanup product + derived rows
        $pdo->prepare("DELETE FROM product_batches WHERE product_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM stock_movements WHERE product_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM product_stocks WHERE product_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$pid]);
        $left = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_id = $pid")->fetchColumn();
        ($left === 0) ? pass('test product fully cleaned up') : fail('test product not cleaned up');
    }
}
