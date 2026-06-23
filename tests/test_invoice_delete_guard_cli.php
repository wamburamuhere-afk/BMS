<?php
/**
 * Invoice delete guard — CLI test
 *   php tests/test_invoice_delete_guard_cli.php
 *
 * Gap (account_financial.md #2): delete_invoice.php only reversed the legacy Output
 * VAT stamp and hard-deleted with no guard — so deleting an APPROVED invoice
 * orphaned its revenue + COGS GL entries (Dr AR / Cr Revenue / Cr Output VAT and
 * Dr COGS / Cr Inventory), and a PAID one also dangled the collection entry.
 *
 * Fix: block delete of an invoice that has posted, un-reversed revenue OR any
 * payment — require Cancel first (which reverses the GL). Only drafts / already-
 * cancelled (no payments) may be hard-deleted. This verifies both paths.
 *
 * The endpoint calls exit on its block path, so it is invoked in a SUBPROCESS
 * worker (exit ends the worker, not this test).
 */

$root = dirname(__DIR__);

// ── worker: run the delete endpoint for one invoice id and print its JSON ─────
if (($argv[1] ?? '') === 'del') {
    require_once "$root/roots.php";
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = 4; $_SESSION['username'] = 'cli'; $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1;
    $_POST = ['invoice_id' => (int)($argv[2] ?? 0)]; $_GET = []; $_SERVER['REQUEST_METHOD'] = 'POST';
    error_reporting(E_ERROR | E_PARSE);
    include "$root/api/account/delete_invoice.php";
    exit;
}

require_once "$root/roots.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['is_admin'] = true;
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $p=false; if($p)return; $p=true;
    echo "\nPasses: \033[32m$pass\033[0m   Failures: " . ($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m") . "\n";
    if ($fail>0) exit(1);
});
function callDelete(int $invoiceId) {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' del ' . (int)$invoiceId . ' 2>&1');
    // the endpoint prints a JSON object; grab the last {...}
    if (preg_match('/\{.*\}\s*$/s', (string)$out, $m)) return json_decode($m[0], true);
    return ['_raw' => $out];
}

// ── 1. Source contract ───────────────────────────────────────────────────────
section('1. delete_invoice.php — guard wired');
$src = file_get_contents("$root/api/account/delete_invoice.php");
ok(strpos($src, "core/revenue_posting.php") !== false, 'includes core/revenue_posting.php');
ok(strpos($src, "entity_type='invoice'") !== false, 'checks for a posted invoice revenue entry');
ok(strpos($src, 'invoiceRevenueReversed($pdo') !== false, 'uses invoiceRevenueReversed() (not blocked once cancelled)');
ok(strpos($src, "FROM payments WHERE invoice_id") !== false, 'checks for payments');
ok(strpos($src, 'http_response_code(409)') !== false, 'blocks with 409 when posted/paid');

// ── 2. Runtime: draft deletes; approved is blocked ───────────────────────────
section('2. Runtime — draft deletes, approved is BLOCKED');
$cust = (int)$pdo->query("SELECT customer_id FROM customers ORDER BY customer_id LIMIT 1")->fetchColumn();
ok($cust > 0, "have a customer to seed with (#$cust)");

// (a) DRAFT invoice → delete should SUCCEED (nothing posted).
$pdo->prepare("INSERT INTO invoices (invoice_number, customer_id, invoice_date, grand_total, status)
               VALUES (?, ?, CURDATE(), 1000, 'draft')")->execute(['INV-DELT-DRAFT-'.time(), $cust]);
$draftId = (int)$pdo->lastInsertId();
$rA = callDelete($draftId);
ok(is_array($rA) && !empty($rA['success']), 'draft invoice: delete succeeds — ' . ($rA['message'] ?? json_encode($rA)));
ok((int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE invoice_id=$draftId")->fetchColumn() === 0, 'draft invoice row removed');

// (b) APPROVED invoice with a posted revenue entry → delete should be BLOCKED.
$pdo->prepare("INSERT INTO invoices (invoice_number, customer_id, invoice_date, grand_total, status)
               VALUES (?, ?, CURDATE(), 5000, 'approved')")->execute(['INV-DELT-APPR-'.time(), $cust]);
$apprId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO journal_entries (entry_date, reference_number, description, status, created_by, debit_account_id, credit_account_id, amount, entity_type, entity_id, created_at)
               VALUES (CURDATE(), ?, 'seed revenue', 'posted', 4, 0, 0, 5000, 'invoice', ?, NOW())")
    ->execute(['JE-DELT-'.time(), $apprId]);
$seedJe = (int)$pdo->lastInsertId();

$rB = callDelete($apprId);
ok(is_array($rB) && empty($rB['success']), 'approved invoice: delete is BLOCKED — ' . ($rB['message'] ?? json_encode($rB)));
ok(strpos(strtolower($rB['message'] ?? ''), 'cancel it first') !== false, 'block message tells user to cancel first');
ok((int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE invoice_id=$apprId")->fetchColumn() === 1, 'approved invoice still present (not deleted)');

// teardown — by pattern, so any earlier aborted run self-heals (no orphan single-leg JE left behind)
$pdo->exec("DELETE FROM journal_entries WHERE reference_number LIKE 'JE-DELT-%'");
$pdo->exec("DELETE FROM invoices WHERE invoice_number LIKE 'INV-DELT-%'");
ok((int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE invoice_number LIKE 'INV-DELT-%'")->fetchColumn() === 0, 'teardown removed all test invoices');
ok((int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE reference_number LIKE 'JE-DELT-%'")->fetchColumn() === 0, 'teardown removed all seeded journal headers (no orphan single-leg entry)');
