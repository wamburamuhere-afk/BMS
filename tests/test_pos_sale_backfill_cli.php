<?php
/**
 * IN-5 — POS sale revenue/COGS backfill — CLI test
 *   php tests/test_pos_sale_backfill_cli.php
 *
 * Guards money.md IN-5: completed POS sales must post to the canonical ledger —
 *   Revenue: Dr Cash/Bank (+ AR) / Cr Sales Revenue / Cr Output VAT
 *   COGS:    Dr Cost of Goods Sold / Cr Inventory
 * and the backfill migration must post every transacted sale that has no GL entry,
 * idempotently and criteria-based (no hard-coded ids).
 *
 * Also guards the ledger_post.php reference-number hardening: a burst of entries
 * within the same one-second timestamp must NOT collide on reference_number (this
 * is exactly what a backfill does). Everything runs in a transaction, rolled back.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/sales_posting.php";
require_once "$root/core/ledger_post.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 50) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail, $pdo; static $printed = false; if ($printed) return; $printed = true;
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

$migration = 'migrations/2026_06_15_backfill_pos_sale_revenue.php';

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint clean');
foreach ([$migration, 'core/sales_posting.php', 'core/ledger_post.php', 'api/pos/process_sale.php'] as $f) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    $rc === 0 ? pass("$f lints clean") : fail("php -l failed: $f");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Backfill migration — criteria-based + idempotent + self-healing');
$mig = src($root, $migration);
has($mig, 'postPosSale', 'calls the same posting fn process_sale.php uses (postPosSale)');
has($mig, "entity_type = 'pos_sale'", 'detects by missing posted pos_sale entry (idempotent)');
has($mig, "entity_type = 'pos_cogs'", 'self-heals missing pos_cogs entry too');
has($mig, "sale_status IN ('completed','partially_refunded','refunded')", 'only transacted statuses recognised');
has($mig, 'is_return_sale = 0', 'excludes return rows (returns = IN-6)');
has($mig, 'assertLedgerBalanced', 'runs the balance guardrail');
has($mig, 'cost_price > p.selling_price', 'detection skips implausible-cost lines (stays self-healing)');
has($mig, 'WARNING', 'reports any deferred COGS from corrupt costs');
// No hard-coded sale/account ids anywhere in the WHERE/posting.
(preg_match('/entity_id\s*=\s*\d{2,}/', $mig) === 0) ? pass('no hard-coded entity_id literals') : fail('found a hard-coded id literal');

// ─────────────────────────────────────────────────────────────────────────
section('2b. posSaleCogs guards corrupt cost data (cost > selling → 0)');
$sp = src($root, 'core/sales_posting.php');
has($sp, 'NOT (p.cost_price > p.selling_price AND p.selling_price > 0)', 'posSaleCogs excludes implausible-cost lines');
// Runtime: if any implausible-cost product sits in a completed sale, posSaleCogs
// for that sale must NOT include it (else it would inject a bogus COGS).
$badSale = $pdo->query("
    SELECT si.sale_id
      FROM pos_sale_items si
      JOIN products p ON p.product_id = si.product_id
      JOIN pos_sales ps ON ps.sale_id = si.sale_id AND ps.sale_status='completed'
     WHERE p.cost_price > p.selling_price AND p.selling_price > 0
     LIMIT 1
")->fetchColumn();
if ($badSale) {
    $guarded = posSaleCogs($pdo, (int)$badSale);
    $naive = (float)$pdo->query("SELECT COALESCE(SUM(si.quantity*COALESCE(p.cost_price,0)),0)
                                   FROM pos_sale_items si JOIN products p ON p.product_id=si.product_id
                                  WHERE si.sale_id=" . (int)$badSale)->fetchColumn();
    ($guarded < $naive) ? pass("posSaleCogs deferred the corrupt cost (guarded $guarded < naive " . number_format($naive, 0) . ")")
                        : fail("posSaleCogs did NOT defer the corrupt cost ($guarded vs $naive)");
} else {
    pass('no implausible-cost product in any sale (cost data clean) — guard inert');
}

// ─────────────────────────────────────────────────────────────────────────
section('3. ledger_post.php reference-number hardening (burst-safe)');
$lp = src($root, 'core/ledger_post.php');
has($lp, 'random_int(0, 999999)', 'reference suffix widened to 6 digits');
has($lp, '=== 1062', 'retries on the duplicate-key (1062) collision');

// ─────────────────────────────────────────────────────────────────────────
section('4. Runtime — a real completed sale posts balanced revenue + COGS');
$uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 0);
$uid ? pass("posting user resolved (#$uid)") : fail('no users found');

// A completed, non-return sale that actually has product cost (so both legs post).
$sale = $pdo->query("
    SELECT ps.sale_id, ps.receipt_number, ps.payment_method, ps.grand_total, ps.tax_amount,
           ps.project_id, ps.sale_date
      FROM pos_sales ps
     WHERE (ps.is_return_sale = 0 OR ps.is_return_sale IS NULL)
       AND ps.sale_status = 'completed'
       AND EXISTS (SELECT 1 FROM pos_sale_items si JOIN products p ON p.product_id=si.product_id
                    WHERE si.sale_id=ps.sale_id AND COALESCE(p.cost_price,0) > 0)
     ORDER BY ps.sale_id LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$sale) {
    fail('no completed POS sale with product cost found to test');
} else {
    pass("test sale: {$sale['receipt_number']} (grand " . number_format((float)$sale['grand_total'], 2) . ")");
    $sid   = (int)$sale['sale_id'];
    $grand = round((float)$sale['grand_total'], 2);
    $tax   = round((float)$sale['tax_amount'], 2);
    $cogs  = posSaleCogs($pdo, $sid);

    $pdo->beginTransaction();
    // Clear any existing GL for this sale so the test posts fresh, then roll back.
    $ids = $pdo->query("SELECT entry_id FROM journal_entries WHERE entity_type IN ('pos_sale','pos_cogs') AND entity_id=$sid")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM journal_entry_items WHERE entry_id IN ($in)");
        $pdo->exec("DELETE FROM journal_entries WHERE entry_id IN ($in)");
    }

    $res = postPosSale($pdo, $sid, (string)($sale['payment_method'] ?: 'cash'), $grand, 0.0,
        $grand, $tax, substr((string)$sale['sale_date'], 0, 10), $sale['receipt_number'],
        $sale['project_id'] ? (int)$sale['project_id'] : null, $uid);

    (!empty($res['revenue'])) ? pass('revenue leg posted') : fail('revenue leg NOT posted: ' . ($res['reason'] ?: '?'));
    ($cogs > 0 ? !empty($res['cogs']) : true) ? pass('COGS leg posted (sale has product cost)') : fail('COGS leg NOT posted');

    // Revenue entry balances Dr = Cr and the credit side carries revenue (+ VAT).
    $revEntry = (int)$pdo->query("SELECT entry_id FROM journal_entries WHERE entity_type='pos_sale' AND entity_id=$sid AND status='posted' LIMIT 1")->fetchColumn();
    if ($revEntry) {
        $dr = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM journal_entry_items WHERE entry_id=$revEntry AND type='debit'")->fetchColumn();
        $cr = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM journal_entry_items WHERE entry_id=$revEntry AND type='credit'")->fetchColumn();
        (abs($dr - $cr) < 0.01) ? pass("revenue entry balances (Dr $dr = Cr $cr)") : fail("revenue entry unbalanced (Dr $dr vs Cr $cr)");
        (abs($dr - $grand) < 0.01) ? pass('revenue debit = grand_total') : fail("revenue debit $dr != grand_total $grand");
    } else { fail('no posted pos_sale entry found'); }

    // COGS entry balances.
    if ($cogs > 0) {
        $cogsEntry = (int)$pdo->query("SELECT entry_id FROM journal_entries WHERE entity_type='pos_cogs' AND entity_id=$sid AND status='posted' LIMIT 1")->fetchColumn();
        if ($cogsEntry) {
            $dr = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM journal_entry_items WHERE entry_id=$cogsEntry AND type='debit'")->fetchColumn();
            (abs($dr - $cogs) < 0.01) ? pass("COGS debit = Σ qty×cost ($cogs)") : fail("COGS debit $dr != $cogs");
        } else { fail('no posted pos_cogs entry found'); }
    }

    // Idempotency — calling again posts nothing new.
    $before = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type IN ('pos_sale','pos_cogs') AND entity_id=$sid")->fetchColumn();
    postPosSale($pdo, $sid, (string)($sale['payment_method'] ?: 'cash'), $grand, 0.0,
        $grand, $tax, substr((string)$sale['sale_date'], 0, 10), $sale['receipt_number'],
        $sale['project_id'] ? (int)$sale['project_id'] : null, $uid);
    $after = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type IN ('pos_sale','pos_cogs') AND entity_id=$sid")->fetchColumn();
    ($before === $after) ? pass("idempotent — re-post added no entries ($before=$after)") : fail("idempotency broken ($before → $after)");

    $pdo->rollBack();
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Burst safety — many entries in one second never collide on reference');
$acctA = (int)($pdo->query("SELECT account_id FROM accounts WHERE status='active' ORDER BY account_id LIMIT 1")->fetchColumn() ?: 0);
$acctB = (int)($pdo->query("SELECT account_id FROM accounts WHERE status='active' ORDER BY account_id LIMIT 1 OFFSET 1")->fetchColumn() ?: 0);
if ($acctA && $acctB) {
    $pdo->beginTransaction();
    $ok = true; $err = '';
    try {
        for ($i = 0; $i < 60; $i++) {
            postLedgerEntry($pdo, "burst test #$i", [
                ['account_id' => $acctA, 'type' => 'debit',  'amount' => 1.00],
                ['account_id' => $acctB, 'type' => 'credit', 'amount' => 1.00],
            ], null, null, 'test_burst', date('Y-m-d'), $uid);
        }
    } catch (Throwable $e) { $ok = false; $err = $e->getMessage(); }
    $ok ? pass('posted 60 entries in a tight loop with no reference collision') : fail("burst collided: $err");
    $pdo->rollBack();
} else {
    fail('could not resolve two accounts for the burst test');
}
