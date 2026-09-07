<?php
/**
 * POS Phase 7 — void→GL reversal + receipt company-info fix — CLI test
 * ----------------------------------------------------------------------
 *   php tests/test_pos_phase7_void_gl_reversal_cli.php
 *
 * Verifies:
 *   1. api/pos/void_sale.php and api/pos/print_receipt.php lint-clean.
 *   2. Wiring source patterns in void_sale.php:
 *      - requires core/expense_posting.php
 *      - calls reverseAccrualEntry() for both 'pos_sale' and 'pos_cogs'
 *      - both calls happen BEFORE $pdo->commit() (same transaction as the void)
 *      - a failed reversal is logged as a non-fatal warning (never blocks the void)
 *   3. print_receipt.php no longer hardcodes fake company_address/phone/tin —
 *      reads them from system_settings via getSetting() instead.
 *   4. Live-DB end-to-end (BEGIN/ROLLBACK isolation):
 *      a) postPosSale() posts balanced revenue + COGS entries for a synthetic sale
 *      b) reverseAccrualEntry('pos_sale', ...) posts the exact contra, balanced
 *      c) reverseAccrualEntry('pos_cogs', ...) posts the exact contra, balanced
 *      d) idempotency: a second reversal call is a no-op ('already_reversed')
 *      e) rollback leaves DB untouched
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";
require_once "$root/core/sales_posting.php";
require_once "$root/core/expense_posting.php";

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']  = 4;
$_SESSION['username'] = 'admin';
$_SESSION['role']     = 'admin';
$_SESSION['is_admin'] = true;

$failures = 0;
$passes   = 0;

register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false;
    if ($printed) return; $printed = true;
    echo "\n";
    echo "Passes:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
});

function pass(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

$voidFile    = "$root/api/pos/void_sale.php";
$receiptFile = "$root/api/pos/print_receipt.php";

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint-clean');
// ─────────────────────────────────────────────────────────────────────────
foreach ([$voidFile, $receiptFile] as $f) {
    file_exists($f) ? pass(basename($f) . ' exists') : fail(basename($f) . ' missing');
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($f) . " 2>&1", $o, $rc);
    $rc === 0 ? pass(basename($f) . ' lint-clean') : fail(basename($f) . ' lint failed: ' . implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. void_sale.php wiring source patterns');
// ─────────────────────────────────────────────────────────────────────────
$src = file_get_contents($voidFile);
$checks = [
    "require_once __DIR__ . '/../../core/expense_posting.php'" => 'includes core/expense_posting.php',
    "reverseAccrualEntry(\$pdo, 'pos_sale'"                     => "reverses 'pos_sale' (revenue) entry",
    "reverseAccrualEntry(\$pdo, 'pos_cogs'"                     => "reverses 'pos_cogs' entry",
];
foreach ($checks as $needle => $label) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

$pos_rev_reverse  = strpos($src, "reverseAccrualEntry(\$pdo, 'pos_sale'");
$pos_cogs_reverse = strpos($src, "reverseAccrualEntry(\$pdo, 'pos_cogs'");
$pos_commit       = strrpos($src, '$pdo->commit()');
($pos_rev_reverse !== false && $pos_cogs_reverse !== false && $pos_commit !== false
    && $pos_rev_reverse < $pos_commit && $pos_cogs_reverse < $pos_commit)
    ? pass('both reversal calls run BEFORE $pdo->commit() (same transaction as the void)')
    : fail('reversal ordering broken — must precede commit');

strpos($src, "logActivity(\$pdo, \$_SESSION['user_id'], 'POS Void GL warning'") !== false
    ? pass('a failed reversal is logged as a non-fatal warning')
    : fail('missing non-fatal warning log on reversal failure');

// ─────────────────────────────────────────────────────────────────────────
section('3. print_receipt.php company-info source patterns');
// ─────────────────────────────────────────────────────────────────────────
$rsrc = file_get_contents($receiptFile);
$badLiterals = ['Dar es Salaam, Tanzania', '+255 123 456 789', '123-456-789'];
$stillHardcoded = false;
foreach ($badLiterals as $lit) {
    if (strpos($rsrc, $lit) !== false) { $stillHardcoded = true; fail("hardcoded placeholder still present: \"$lit\""); }
}
if (!$stillHardcoded) pass('no hardcoded placeholder company address/phone/TIN literals remain');

$settingChecks = [
    "getSetting('company_physical_address'" => 'company address now read from system_settings',
    "getSetting('company_phone'"            => 'company phone now read from system_settings',
    "getSetting('company_tin'"              => 'company TIN now read from system_settings',
];
foreach ($settingChecks as $needle => $label) {
    strpos($rsrc, $needle) !== false ? pass($label) : fail("$label — missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Live-DB end-to-end (BEGIN/ROLLBACK isolation)');
// ─────────────────────────────────────────────────────────────────────────
global $pdo;

$product = $pdo->query("SELECT product_id, cost_price, selling_price FROM products
                          WHERE is_service = 0 AND cost_price > 0 AND selling_price >= cost_price
                          ORDER BY product_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$product) { fail('no suitable product with cost_price > 0 found — cannot test COGS reversal'); exit(1); }
pass("using product_id={$product['product_id']} (cost_price={$product['cost_price']}) for synthetic sale");

$synth_sale_id = 90000202;
$qty           = 2;
$cost_price    = (float)$product['cost_price'];
$expected_cogs = round($qty * $cost_price, 2);
$grand_total   = round($expected_cogs * 3, 2); // arbitrary sale price well above cost
$tax           = 0.0;

$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO pos_sale_items (sale_id, product_id, product_name, quantity, unit_price, line_total)
                    VALUES (?, ?, 'Phase7 Test Item', ?, ?, ?)")
        ->execute([$synth_sale_id, $product['product_id'], $qty, $grand_total / $qty, $grand_total]);

    $sale = postPosSale($pdo, $synth_sale_id, 'cash', $grand_total, 0.0, $grand_total, $tax, date('Y-m-d'), 'PHASE7-TEST', null, 4);
    $sale['revenue'] ? pass('postPosSale posted the revenue entry') : fail('revenue post failed: ' . json_encode($sale));
    $sale['cogs']    ? pass('postPosSale posted the COGS entry')    : fail('COGS post failed: ' . json_encode($sale));

    $revEntry  = (int)$pdo->query("SELECT entry_id FROM journal_entries WHERE entity_type='pos_sale' AND entity_id=$synth_sale_id AND status='posted'")->fetchColumn();
    $cogsEntry = (int)$pdo->query("SELECT entry_id FROM journal_entries WHERE entity_type='pos_cogs' AND entity_id=$synth_sale_id AND status='posted'")->fetchColumn();
    ($revEntry > 0 && $cogsEntry > 0) ? pass('both original entries exist and are posted') : fail('original entries missing');

    // ── Reverse, exactly as void_sale.php now does ──
    $revReversal  = reverseAccrualEntry($pdo, 'pos_sale', $synth_sale_id, 4);
    $cogsReversal = reverseAccrualEntry($pdo, 'pos_cogs', $synth_sale_id, 4);
    ($revReversal['reversed'] === true && !empty($revReversal['entry_id']))
        ? pass("pos_sale reversed (entry_id={$revReversal['entry_id']})")
        : fail('pos_sale reversal failed: ' . json_encode($revReversal));
    ($cogsReversal['reversed'] === true && !empty($cogsReversal['entry_id']))
        ? pass("pos_cogs reversed (entry_id={$cogsReversal['entry_id']})")
        : fail('pos_cogs reversal failed: ' . json_encode($cogsReversal));

    // Verify the reversal entries balance and are tagged '_void'
    foreach (['pos_sale_void' => $revReversal, 'pos_cogs_void' => $cogsReversal] as $tag => $r) {
        if (empty($r['entry_id'])) continue;
        $items = $pdo->prepare("SELECT type, amount FROM journal_entry_items WHERE entry_id = ?");
        $items->execute([$r['entry_id']]);
        $lines = $items->fetchAll(PDO::FETCH_ASSOC);
        $dr = $cr = 0.0;
        foreach ($lines as $l) { $l['type'] === 'debit' ? $dr += (float)$l['amount'] : $cr += (float)$l['amount']; }
        (count($lines) > 0 && abs($dr - $cr) < 0.01)
            ? pass("$tag reversal entry balances (Dr $dr == Cr $cr)")
            : fail("$tag reversal entry does not balance: " . json_encode($lines));

        $hdr = $pdo->query("SELECT entity_type FROM journal_entries WHERE entry_id = {$r['entry_id']}")->fetchColumn();
        $hdr === $tag ? pass("$tag reversal entry correctly tagged entity_type='$tag'") : fail("$tag entity_type wrong: $hdr");
    }

    // Idempotency — calling reverseAccrualEntry again must be a no-op
    $revAgain  = reverseAccrualEntry($pdo, 'pos_sale', $synth_sale_id, 4);
    $cogsAgain = reverseAccrualEntry($pdo, 'pos_cogs', $synth_sale_id, 4);
    ($revAgain['reversed'] === true && $revAgain['reason'] === 'already_reversed')
        ? pass('second pos_sale reversal is a no-op (already_reversed)')
        : fail('pos_sale reversal not idempotent: ' . json_encode($revAgain));
    ($cogsAgain['reversed'] === true && $cogsAgain['reason'] === 'already_reversed')
        ? pass('second pos_cogs reversal is a no-op (already_reversed)')
        : fail('pos_cogs reversal not idempotent: ' . json_encode($cogsAgain));

    $voidRowCount = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type IN ('pos_sale_void','pos_cogs_void') AND entity_id=$synth_sale_id")->fetchColumn();
    $voidRowCount === 2 ? pass('exactly 2 reversal rows (no double-reversal)') : fail("expected 2 reversal rows, got $voidRowCount");

    $pdo->rollBack();
    pass('transaction rolled back');

    $afterCount = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_id=$synth_sale_id")->fetchColumn();
    $afterCount === 0 ? pass('no synthetic rows persisted after rollback') : fail("rollback left $afterCount rows");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('section 4 threw: ' . $e->getMessage());
}

exit($failures === 0 ? 0 : 1);
