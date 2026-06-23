<?php
/**
 * Credit note → COGS/Inventory restock → GL posting — CLI test
 *   php tests/test_credit_note_restock_cli.php
 *
 * Guards core/sales_posting.php::postCreditNoteRestock(): settling a credit note for a
 * customer return of stocked goods posts the balanced contra of the sale's COGS —
 * Dr Inventory / Cr Cost of Goods Sold for the returned cost — idempotently, and proves
 * the report engine then tells the truth (Income-Statement COGS drops, Balance-Sheet
 * Inventory rises, and the statement stays balanced because the two offset). Runs the
 * real helpers against a synthetic credit note inside a ROLLED-BACK transaction, so the
 * database is left exactly as found.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/sales_posting.php";
require_once "$root/core/financial_reports.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function money(float $n): string { return number_format($n, 2); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

/** Inventory amount carried on the Balance Sheet (asset line for the Inventory account). */
function bs_inventory_amount(array $bs, int $invAcc): float {
    foreach ($bs['assets'] as $a) { if ((int)($a['account_id'] ?? 0) === $invAcc) return (float)$a['amount']; }
    return 0.0;
}

// ─────────────────────────────────────────────────────────────────────────
section('1. Control accounts resolve');
$inv  = inventoryAccountId($pdo);
$cogs = cogsAccountId($pdo);
$sra  = salesReturnsAccountId($pdo);
$inv  ? pass("inventoryAccountId → #$inv")           : fail('inventoryAccountId returned null (Inventory account missing)');
$cogs ? pass("cogsAccountId → #$cogs")               : fail('cogsAccountId returned null (COGS account missing)');
$sra  ? pass("salesReturnsAccountId → #$sra")        : fail('salesReturnsAccountId returned null (Sales Returns account missing)');

// ─────────────────────────────────────────────────────────────────────────
section('2. postCreditNoteRestock posts a balanced Dr Inventory / Cr COGS (rolled back)');

// A real stocked product with a sane cost (cost ≤ selling, mirroring the COGS guard).
$prod = $pdo->query("SELECT product_id, cost_price, selling_price FROM products
                      WHERE cost_price > 0 AND (selling_price = 0 OR selling_price >= cost_price)
                   ORDER BY product_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$cust = (int)($pdo->query("SELECT customer_id FROM customers ORDER BY customer_id LIMIT 1")->fetchColumn() ?: 0);
$uid  = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);

if (!$prod || !$cust) {
    fail('no stocked product with a sane cost and/or no customer found — cannot run the functional test');
    return;
}
$pid_prod = (int)$prod['product_id'];
$cost     = round((float)$prod['cost_price'], 2);
$qty      = 3;
$expected = round($qty * $cost, 2);
$today    = date('Y-m-d');
echo "   product #$pid_prod  cost=" . money($cost) . "  qty=$qty  → expected restock cost=" . money($expected) . "\n";

$before_pl = glProfitLoss($pdo, date('Y-01-01'), $today, null, '');
$before_bs = glBalanceSheet($pdo, $today, null, false, '');

$leak_before = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='credit_note_cogs'")->fetchColumn();

$pdo->beginTransaction();
try {
    // Synthetic credit note + one product line (same column set the app uses).
    $pdo->prepare("INSERT INTO credit_notes
            (credit_note_number, customer_id, sales_return_id, credit_date, reason, notes,
             subtotal_amount, total_tax, grand_total, status, created_by, created_at)
         VALUES (?, ?, NULL, ?, 'restock test', '', ?, 0, ?, 'approved', ?, NOW())")
        ->execute(['CN-TEST-' . uniqid(), $cust, $today, $expected, $expected, $uid]);
    $cnId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO credit_note_items
            (credit_note_id, product_id, description, quantity, unit_price, tax_rate, tax_amount, total_amount)
         VALUES (?, ?, 'restock test line', ?, ?, 0, 0, ?)")
        ->execute([$cnId, $pid_prod, $qty, $cost, $expected]);
    echo "   synthetic credit note #$cnId created (rolled back at end)\n";

    // 2a. Restock cost matches Σ(qty × cost_price)
    $calc = creditNoteRestockCost($pdo, $cnId);
    (abs($calc - $expected) < 0.01)
        ? pass("creditNoteRestockCost = " . money($calc) . " (Σ qty × cost_price)")
        : fail("creditNoteRestockCost $calc != expected $expected");

    // 2b. Posts the balanced entry
    $res = postCreditNoteRestock($pdo, $cnId, $calc, $today, null, $uid);
    (!empty($res['posted']) && !empty($res['entry_id']))
        ? pass("posted (entry #{$res['entry_id']})")
        : fail("did not post: reason=" . ($res['reason'] ?? '?'));

    if (!empty($res['entry_id'])) {
        $eid   = (int)$res['entry_id'];
        $lines = $pdo->query("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id=$eid")->fetchAll(PDO::FETCH_ASSOC);
        $hdr   = $pdo->query("SELECT entity_type, status FROM journal_entries WHERE entry_id=$eid")->fetch(PDO::FETCH_ASSOC);

        (count($lines) === 2) ? pass('entry has exactly 2 lines') : fail('entry has ' . count($lines) . ' lines (want 2)');
        $dr = 0.0; $cr = 0.0; $drAcc = null; $crAcc = null;
        foreach ($lines as $l) { if ($l['type']==='debit'){$dr=(float)$l['amount'];$drAcc=(int)$l['account_id'];} else {$cr=(float)$l['amount'];$crAcc=(int)$l['account_id'];} }
        (abs($dr - $cr) < 0.01)      ? pass("balanced (Dr " . money($dr) . " = Cr " . money($cr) . ")") : fail("unbalanced Dr $dr vs Cr $cr");
        (abs($dr - $expected) < 0.01)? pass('amount equals returned cost')                              : fail("amount $dr != cost $expected");
        ($drAcc === (int)$inv)       ? pass('debit is the Inventory account')                           : fail("debit acct #$drAcc != Inventory #$inv");
        ($crAcc === (int)$cogs)      ? pass('credit is the COGS account')                               : fail("credit acct #$crAcc != COGS #$cogs");
        (($hdr['status'] ?? '') === 'posted')            ? pass("status='posted' (reports read posted only)") : fail("status=" . ($hdr['status'] ?? 'null'));
        (($hdr['entity_type'] ?? '') === 'credit_note_cogs') ? pass("entity_type='credit_note_cogs'")        : fail("entity_type=" . ($hdr['entity_type'] ?? 'null'));

        // 2c. Idempotency
        $res2 = postCreditNoteRestock($pdo, $cnId, $calc, $today, null, $uid);
        (($res2['reason'] ?? '') === 'already_posted' && (int)$res2['entry_id'] === $eid)
            ? pass('idempotent: second call returns already_posted with the same entry')
            : fail('not idempotent: reason=' . ($res2['reason'] ?? '?'));

        // 2d. Service / price-adjustment note (no product) posts nothing
        $res3 = postCreditNoteRestock($pdo, $cnId + 999000, 0.0, $today, null, $uid);
        (($res3['reason'] ?? '') === 'no_stock_cost' && empty($res3['posted']))
            ? pass('zero-cost (service) note posts nothing — no bogus COGS')
            : fail('zero-cost note unexpectedly posted: ' . json_encode($res3));
    }

    // ── Report-level truth: COGS ↓, Inventory ↑, statement delta stays balanced ──
    section('3. Reports reflect it correctly (same open transaction)');
    $after_pl = glProfitLoss($pdo, date('Y-01-01'), $today, null, '');
    $after_bs = glBalanceSheet($pdo, $today, null, false, '');

    $cogsDrop = round($before_pl['total_cogs'] - $after_pl['total_cogs'], 2);
    (abs($cogsDrop - $expected) < 0.01)
        ? pass("Income Statement COGS dropped by " . money($cogsDrop) . " (was " . money($before_pl['total_cogs']) . " → " . money($after_pl['total_cogs']) . ")")
        : fail("COGS delta $cogsDrop != expected $expected");

    $invRise = round(bs_inventory_amount($after_bs, (int)$inv) - bs_inventory_amount($before_bs, (int)$inv), 2);
    (abs($invRise - $expected) < 0.01)
        ? pass("Balance Sheet Inventory rose by " . money($invRise))
        : fail("Inventory delta $invRise != expected $expected");

    // The entry must not change whether the statement balances (Dr asset +X offset by
    // Cr COGS −X → retained earnings +X). Compare the DELTA, since the live company
    // statement may not currently be balanced for unrelated reasons.
    $diffShift = round($after_bs['difference'] - $before_bs['difference'], 2);
    (abs($diffShift) < 0.01)
        ? pass("Balance Sheet stays balanced through the entry (difference shift = " . money($diffShift) . ")")
        : fail("entry shifted the balance-sheet difference by $diffShift (should be 0)");

    // 3b. Ledger Σ Dr = Σ Cr still holds with the entry applied
    $g = assertLedgerBalanced($pdo, $today);
    (!empty($g['ok'])) ? pass('assertLedgerBalanced ok (Σ Dr = Σ Cr) with the entry applied')
                       : fail('ledger Σ Dr ≠ Σ Cr: ' . json_encode($g));
} finally {
    $pdo->rollBack();   // leave the database exactly as found
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Rolled back cleanly');
$leak_after = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='credit_note_cogs'")->fetchColumn();
($leak_after === $leak_before)
    ? pass("no rows leaked (credit_note_cogs entries: $leak_before → $leak_after)")
    : fail("LEAKED rows: $leak_before → $leak_after");
