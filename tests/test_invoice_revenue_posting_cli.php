<?php
/**
 * IN-3 — Invoice approval revenue posting — CLI test
 *   php tests/test_invoice_revenue_posting_cli.php
 *
 * Guards money.md IN-3: approving an invoice posts ONE balanced double-entry into the
 * canonical ledger — Dr Accounts Receivable / Cr Sales Revenue / Cr Output VAT — so
 * revenue and AR actually land in their accounts (was: Revenue = 0, single-sided VAT nudge).
 *
 * Verifies: both approval paths call postInvoiceRevenue; the helper resolves AR/Revenue/VAT;
 * runtime posts a balanced 3-line entry for a VAT invoice and a 2-line for a no-VAT invoice,
 * each Dr=Cr, idempotent (no double-post). Runs in a transaction that is rolled back.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/revenue_posting.php";
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

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint + both approval paths post revenue');
foreach (['core/revenue_posting.php', 'api/account/approve_invoice.php', 'api/account/update_invoice_status.php'] as $f) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    $rc === 0 ? pass("$f lints clean") : fail("php -l failed: $f");
}
has(src($root, 'api/account/approve_invoice.php'), 'postInvoiceRevenue', 'approve_invoice.php posts revenue');
has(src($root, 'api/account/update_invoice_status.php'), 'postInvoiceRevenue', 'update_invoice_status.php (approved) posts revenue');
$rp = src($root, 'core/revenue_posting.php');
has($rp, 'postLedgerEntry', 'helper posts via the canonical ledger (postLedgerEntry)');
has($rp, "entity_type = 'invoice'", 'helper is idempotent on the invoice journal entry');

// ─────────────────────────────────────────────────────────────────────────
section('2. Resolvers find AR / Revenue / Output VAT');
$ar  = arAccountId($pdo);
$rev = salesRevenueAccountId($pdo);
$vat = function_exists('outputVatAccountId') ? outputVatAccountId($pdo) : null;
$ar  ? pass("AR account resolved (#$ar)")          : fail('AR account NOT resolved');
$rev ? pass("Sales Revenue account resolved (#$rev)") : fail('Sales Revenue account NOT resolved');
$vat ? pass("Output VAT account resolved (#$vat)") : fail('Output VAT account not resolved (VAT invoices need it)');
// Revenue must be a LEAF (never a group header) and a revenue-category account.
if ($rev) {
    $isLeaf = !(int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE parent_account_id = $rev")->fetchColumn();
    $cat = $pdo->query("SELECT at.category FROM accounts a JOIN account_types at ON a.account_type_id=at.type_id WHERE a.account_id=$rev")->fetchColumn();
    ($isLeaf && $cat === 'revenue') ? pass('Revenue account is a revenue LEAF (postable)') : fail("Revenue account not a revenue leaf (leaf=" . ($isLeaf?'y':'n') . ", cat=$cat)");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Runtime — balanced entry posts, idempotent (rolled back)');
function pickInvoice(PDO $pdo, bool $withVat): ?int {
    $cond = $withVat ? 'tax_amount > 0' : 'tax_amount = 0';
    return (int)($pdo->query("SELECT invoice_id FROM invoices WHERE grand_total > 0 AND $cond ORDER BY invoice_id DESC LIMIT 1")->fetchColumn() ?: 0) ?: null;
}

function runCase(PDO $pdo, int $uid, ?int $invId, bool $withVat): void {
    $label = $withVat ? 'VAT invoice (3-line)' : 'no-VAT invoice (2-line)';
    if (!$invId) { pass("$label — no sample invoice (skipped n/a)"); return; }
    $pdo->beginTransaction();
    // clean slate for a deterministic test
    $pdo->prepare("DELETE ji FROM journal_entry_items ji JOIN journal_entries je ON ji.entry_id=je.entry_id WHERE je.entity_type='invoice' AND je.entity_id=?")->execute([$invId]);
    $pdo->prepare("DELETE FROM journal_entries WHERE entity_type='invoice' AND entity_id=?")->execute([$invId]);

    $res = postInvoiceRevenue($pdo, $invId, $uid);
    if (empty($res['posted'])) { fail("$label — did not post (" . ($res['reason'] ?? '?') . ")"); $pdo->rollBack(); return; }

    $rows = $pdo->query("SELECT type, amount FROM journal_entry_items WHERE entry_id=" . (int)$res['entry_id'])->fetchAll(PDO::FETCH_ASSOC);
    $dr = 0; $cr = 0; foreach ($rows as $r) { if ($r['type'] === 'debit') $dr += (float)$r['amount']; else $cr += (float)$r['amount']; }
    $expectLines = $withVat ? 3 : 2;
    (count($rows) === $expectLines) ? pass("$label — $expectLines lines") : fail("$label — expected $expectLines lines, got " . count($rows));
    (abs($dr - $cr) < 0.01) ? pass("$label — balanced (Dr=Cr=" . number_format($dr, 2) . ")") : fail("$label — unbalanced Dr=$dr Cr=$cr");

    // AR debit equals the invoice grand_total
    $grand = (float)$pdo->query("SELECT grand_total FROM invoices WHERE invoice_id=$invId")->fetchColumn();
    (abs($dr - round($grand, 2)) < 0.01) ? pass("$label — AR debit == grand_total (" . number_format($grand, 2) . ")") : fail("$label — AR debit $dr != grand $grand");

    $res2 = postInvoiceRevenue($pdo, $invId, $uid);
    ($res2['reason'] ?? '') === 'already_posted' ? pass("$label — idempotent (no double-post)") : fail("$label — NOT idempotent (" . ($res2['reason'] ?? '?') . ")");
    $pdo->rollBack();
}

$uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
runCase($pdo, $uid, pickInvoice($pdo, true),  true);
runCase($pdo, $uid, pickInvoice($pdo, false), false);
