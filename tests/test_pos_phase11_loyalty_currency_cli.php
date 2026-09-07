<?php
/**
 * POS Phase 11 — Loyalty points + multi-currency fix — CLI test
 * ----------------------------------------------------------------------
 *   php tests/test_pos_phase11_loyalty_currency_cli.php
 *
 * Verifies:
 *   1. New/touched files lint-clean.
 *   2. Schema: customers.loyalty_points_balance + customer_loyalty_transactions exist
 *      (applied via migrations/tenant/2026_09_07_pos_loyalty_program.php),
 *      and schema/tenant_schema_template.sql was updated so new tenants get them.
 *   3. Wiring source patterns:
 *      - process_sale.php calls redeemLoyaltyPoints()/awardLoyaltyPoints()
 *      - void_sale.php calls reverseLoyaltyForSale()
 *      - pos.php no longer hardcodes $currency = 'TZS'
 *      - pos_scripts_new.php has no leftover hardcoded 'TZS ' + concatenation
 *        (the exact bug pattern the scout found), uses POS_CURRENCY throughout
 *   4. Live-DB end-to-end (BEGIN/ROLLBACK isolation) of core/pos_loyalty.php:
 *      a) awardLoyaltyPoints() earns the right number of points and writes an
 *         auditable ledger row
 *      b) redeemLoyaltyPoints() validates against the REAL balance (can't
 *         redeem more than available), computes the right discount, ledger row
 *      c) reverseLoyaltyForSale() reverses both earn and redeem for a sale,
 *         restoring the balance to its pre-sale value, and is idempotent
 *      d) rollback leaves no trace
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";
require_once "$root/core/pos_loyalty.php";

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

$files = [
    'migrations/tenant/2026_09_07_pos_loyalty_program.php', 'core/pos_loyalty.php',
    'api/pos/process_sale.php', 'api/pos/void_sale.php', 'api/pos/search_customers.php',
    'api/pos/print_receipt.php', 'api/pos_session.php',
    'app/bms/pos/pos.php', 'app/bms/pos/pos_scripts_new.php', 'app/bms/pos/pos_modals_new.php',
    'app/bms/pos/customer_display.php', 'app/constant/settings/pos_config_settings.php',
];

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint-clean');
// ─────────────────────────────────────────────────────────────────────────
foreach ($files as $f) {
    $path = "$root/$f";
    if (!file_exists($path)) { fail("$f missing"); continue; }
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($path) . " 2>&1", $o, $rc);
    $rc === 0 ? pass("$f lint-clean") : fail("$f lint failed: " . implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Schema — loyalty columns/table present');
// ─────────────────────────────────────────────────────────────────────────
global $pdo;

$hasCol = (bool)$pdo->query("SHOW COLUMNS FROM customers LIKE 'loyalty_points_balance'")->fetch();
$hasCol ? pass('customers.loyalty_points_balance exists (live DB)') : fail('customers.loyalty_points_balance missing');

$hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'customer_loyalty_transactions'")->fetch();
$hasTable ? pass('customer_loyalty_transactions table exists (live DB)') : fail('customer_loyalty_transactions table missing');

$templateSql = file_get_contents("$root/schema/tenant_schema_template.sql");
strpos($templateSql, 'loyalty_points_balance') !== false
    ? pass('tenant_schema_template.sql updated with loyalty_points_balance (new tenants get it)')
    : fail('tenant_schema_template.sql NOT updated — new tenants would miss this column');
strpos($templateSql, 'CREATE TABLE `customer_loyalty_transactions`') !== false
    ? pass('tenant_schema_template.sql updated with customer_loyalty_transactions table')
    : fail('tenant_schema_template.sql missing the customer_loyalty_transactions table');

// ─────────────────────────────────────────────────────────────────────────
section('3. Wiring source patterns');
// ─────────────────────────────────────────────────────────────────────────
$saleSrc    = file_get_contents("$root/api/pos/process_sale.php");
$voidSrc    = file_get_contents("$root/api/pos/void_sale.php");
$posSrc     = file_get_contents("$root/app/bms/pos/pos.php");
$scriptsSrc = file_get_contents("$root/app/bms/pos/pos_scripts_new.php");

$checks = [
    [$saleSrc, "redeemLoyaltyPoints(\$pdo, \$customer_id",      'process_sale.php calls redeemLoyaltyPoints()'],
    [$saleSrc, "awardLoyaltyPoints(\$pdo, \$customer_id",       'process_sale.php calls awardLoyaltyPoints()'],
    [$voidSrc, "reverseLoyaltyForSale(\$pdo, \$sale_id",        'void_sale.php calls reverseLoyaltyForSale()'],
    [$posSrc,  "\$currency = getSetting('currency'",            'pos.php reads currency from system_settings'],
    [$scriptsSrc, "const POS_CURRENCY",                         'pos_scripts_new.php defines POS_CURRENCY from PHP'],
];
foreach ($checks as [$src, $needle, $label]) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

strpos($posSrc, "\$currency = 'TZS';") === false
    ? pass("pos.php no longer hardcodes \$currency = 'TZS'")
    : fail('pos.php still hardcodes $currency = \'TZS\'');

// The exact bug patterns the scout found — string-concatenation display code
// with a literal 'TZS ' prefix — must be gone from the JS.
$badPatterns = ["'TZS ' +", "text('TZS", "TZS \${", "> TZS ", ">TZS<"];
$stillBad = false;
foreach ($badPatterns as $p) {
    if (strpos($scriptsSrc, $p) !== false) { $stillBad = true; fail("pos_scripts_new.php still contains hardcoded pattern: \"$p\""); }
}
if (!$stillBad) pass('pos_scripts_new.php has no leftover hardcoded TZS display patterns');

// ─────────────────────────────────────────────────────────────────────────
section('4. core/pos_loyalty.php — live-DB (BEGIN/ROLLBACK isolation)');
// ─────────────────────────────────────────────────────────────────────────
$customer = $pdo->query("SELECT customer_id FROM customers WHERE status = 'active' ORDER BY customer_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$customer) { fail('no active customer found to test against'); exit(1); }
$customerId = (int)$customer['customer_id'];

$synthSaleId1 = 90000505;
$synthSaleId2 = 90000506;

$pdo->beginTransaction();
try {
    // Explicit config, NOT real system_settings — awardLoyaltyPoints()/
    // redeemLoyaltyPoints() accept an override precisely so tests don't depend
    // on helpers.php::get_setting()'s process-wide static settings cache
    // (which may already be populated with the real, possibly-disabled,
    // config by the time this test runs).
    $cfg = ['enabled' => true, 'spend_per_point' => 1000.0, 'redeem_value' => 50.0];

    $startBalance = (int)$pdo->query("SELECT loyalty_points_balance FROM customers WHERE customer_id = $customerId")->fetchColumn();

    // (a) Award points on a 25,000 spend @ 1pt/1000 = 25 points.
    $earned = awardLoyaltyPoints($pdo, $customerId, $synthSaleId1, 25000.0, 4, $cfg);
    $earned === 25 ? pass("awardLoyaltyPoints() earned 25 points on a 25,000 spend") : fail("expected 25 points earned, got $earned");

    $balanceAfterEarn = (int)$pdo->query("SELECT loyalty_points_balance FROM customers WHERE customer_id = $customerId")->fetchColumn();
    $balanceAfterEarn === $startBalance + 25
        ? pass("customers.loyalty_points_balance correctly incremented ($startBalance -> $balanceAfterEarn)")
        : fail("balance wrong: expected " . ($startBalance + 25) . ", got $balanceAfterEarn");

    $ledgerEarn = $pdo->query("SELECT txn_type, points, balance_after FROM customer_loyalty_transactions WHERE sale_id = $synthSaleId1 AND txn_type = 'earn'")->fetch(PDO::FETCH_ASSOC);
    ($ledgerEarn && (int)$ledgerEarn['points'] === 25 && (int)$ledgerEarn['balance_after'] === $balanceAfterEarn)
        ? pass('earn ledger row correct')
        : fail('earn ledger row wrong: ' . json_encode($ledgerEarn));

    // (c) Void the sale that earned the points (sale1) IN ISOLATION — the
    // realistic case: void_sale.php reverses exactly one sale_id per call,
    // nothing else has touched this customer's balance yet.
    reverseLoyaltyForSale($pdo, $synthSaleId1, 4);
    $balanceAfterEarnReversal = (int)$pdo->query("SELECT loyalty_points_balance FROM customers WHERE customer_id = $customerId")->fetchColumn();
    $balanceAfterEarnReversal === $startBalance
        ? pass("reverseLoyaltyForSale() undoes an earn back to the starting balance ($startBalance)")
        : fail("expected balance restored to $startBalance, got $balanceAfterEarnReversal");

    // Idempotency — reversing the same sale again must not double-reverse.
    reverseLoyaltyForSale($pdo, $synthSaleId1, 4);
    $balanceAfterDoubleReversal = (int)$pdo->query("SELECT loyalty_points_balance FROM customers WHERE customer_id = $customerId")->fetchColumn();
    $balanceAfterDoubleReversal === $startBalance
        ? pass('earn reversal is idempotent — calling it again does not change the balance further')
        : fail("idempotency broken: balance changed to $balanceAfterDoubleReversal on second reversal");

    // (d) Independently: award fresh points (sale3), redeem some of them
    // (sale2), then void ONLY the redemption (sale2) — must give back exactly
    // what was redeemed, leaving sale3's still-legitimate earn untouched.
    $synthSaleId3 = 90000507;
    awardLoyaltyPoints($pdo, $customerId, $synthSaleId3, 25000.0, 4, $cfg);   // +25
    $balanceBeforeRedeem = (int)$pdo->query("SELECT loyalty_points_balance FROM customers WHERE customer_id = $customerId")->fetchColumn();

    // Requesting more than available must clamp to the real balance, never trust the caller's number.
    $over = redeemLoyaltyPoints($pdo, $customerId, $balanceBeforeRedeem + 1000, $synthSaleId2, 4, $cfg);
    ($over['points'] === $balanceBeforeRedeem && abs($over['discount'] - ($balanceBeforeRedeem * 50)) < 0.01)
        ? pass("redeemLoyaltyPoints() clamps an over-request down to the real balance ($balanceBeforeRedeem pts, discount {$over['discount']})")
        : fail('over-request did not clamp correctly: ' . json_encode($over));
    reverseLoyaltyForSale($pdo, $synthSaleId2, 4);   // undo that clamped redemption before the exact-amount test below

    // A distinct sale_id for the exact-amount redemption — a real pos_sales.sale_id
    // is never reused, and reverseLoyaltyForSale()'s idempotency check is keyed
    // per (sale_id, reversal type), so reusing $synthSaleId2 here would make the
    // reversal below a false-positive no-op against the earlier reversal above.
    $synthSaleId4 = 90000508;
    $redeemed = redeemLoyaltyPoints($pdo, $customerId, 10, $synthSaleId4, 4, $cfg);   // -10
    ($redeemed['points'] === 10 && abs($redeemed['discount'] - 500) < 0.01)
        ? pass("redeemLoyaltyPoints() redeems exactly what was requested when balance allows (10 pts, discount {$redeemed['discount']})")
        : fail('redemption of 10 points did not behave as expected: ' . json_encode($redeemed));

    reverseLoyaltyForSale($pdo, $synthSaleId4, 4);   // give the 10 back
    $balanceAfterRedeemReversal = (int)$pdo->query("SELECT loyalty_points_balance FROM customers WHERE customer_id = $customerId")->fetchColumn();
    $balanceAfterRedeemReversal === $balanceBeforeRedeem
        ? pass("reverseLoyaltyForSale() undoes a redemption, restoring the pre-redemption balance ($balanceBeforeRedeem)")
        : fail("expected balance restored to $balanceBeforeRedeem, got $balanceAfterRedeemReversal");

    // Cleanup — void sale3's earn too, so the customer ends this test exactly
    // where they started (verified below).
    reverseLoyaltyForSale($pdo, $synthSaleId3, 4);
    $balanceFinal = (int)$pdo->query("SELECT loyalty_points_balance FROM customers WHERE customer_id = $customerId")->fetchColumn();
    $balanceFinal === $startBalance
        ? pass("all test activity fully unwound — balance back to $startBalance")
        : fail("expected final balance $startBalance, got $balanceFinal");

    // Redeeming with nothing available must fail cleanly, not go negative.
    $none = redeemLoyaltyPoints($pdo, $customerId, 10, $synthSaleId2, 4, $cfg);
    ($none['points'] === 0 && $none['error'] !== null)
        ? pass('redeeming with a zero balance returns a clear error, not a negative balance')
        : fail('zero-balance redemption did not fail cleanly: ' . json_encode($none));

    $pdo->rollBack();
    pass('transaction rolled back');

    $afterCustomer = (int)$pdo->query("SELECT loyalty_points_balance FROM customers WHERE customer_id = $customerId")->fetchColumn();
    $afterCustomer === $startBalance ? pass('customer balance back to its real pre-test value after rollback') : fail('rollback left the customer balance modified');

    $afterLedger = (int)$pdo->query("SELECT COUNT(*) FROM customer_loyalty_transactions WHERE sale_id IN ($synthSaleId1, $synthSaleId2)")->fetchColumn();
    $afterLedger === 0 ? pass('no synthetic ledger rows persisted after rollback') : fail("rollback left $afterLedger ledger rows");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('section 4 threw: ' . $e->getMessage());
}

exit($failures === 0 ? 0 : 1);
