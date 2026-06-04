<?php
/**
 * General Ledger — source-document drill-down CLI test.
 *   php tests/test_gl_source_drilldown_cli.php
 *
 * Proves:
 *   1. Source invariants — resolver + page wiring present, fake CSV stub gone,
 *      touched files lint.
 *   2. gl_source_link() — linkable types resolve to a URL with ?id=, unlinkable
 *      types render a label with no link, manual/empty entries render nothing.
 *   3. The ledger query that now selects entity_type/entity_id runs live.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/gl_source.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['is_admin'] = true;
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function lints($f) { exec('php -l ' . escapeshellarg($f) . ' 2>&1', $o, $rc); return $rc === 0; }

echo "\n\033[1m── 1. Source invariants ──\033[0m\n";
$srcF  = "$root/core/gl_source.php";
$pageF = "$root/app/constant/reports/ledger_report.php";
ok(file_exists($srcF), "core/gl_source.php exists");
ok(lints($srcF), "core/gl_source.php passes php -l");
ok(lints($pageF), "ledger_report.php passes php -l");

$page = file_get_contents($pageF);
ok(strpos($page, "core/gl_source.php") !== false, "ledger page requires core/gl_source.php");
ok(strpos($page, "je.entity_type") !== false && strpos($page, "je.entity_id") !== false, "ledger query selects entity_type + entity_id");
ok(strpos($page, ">Source<") !== false, "ledger table has a Source column header");
ok(strpos($page, "gl_source_link(") !== false, "ledger rows call gl_source_link()");
ok(strpos($page, "alert('Generating Ledger CSV Export...')") === false, "fake CSV alert() stub removed");
ok(strpos($page, "new Blob(") !== false && strpos($page, ".csv'") !== false, "exportCSV() now builds a real CSV blob");

echo "\n\033[1m── 2. gl_source_link() resolution ──\033[0m\n";
$inv = gl_source_link('invoice', 123);
ok($inv['label'] === 'Invoice #123', "invoice → label 'Invoice #123'");
ok($inv['url'] !== null && strpos($inv['url'], 'id=123') !== false, "invoice → URL with ?id=123");

$grn = gl_source_link('grn', 5);
ok($grn['label'] === 'GRN #5' && $grn['url'] !== null && strpos($grn['url'], 'id=5') !== false, "grn → 'GRN #5' linked");

$pr = gl_source_link('payroll', 7);
ok($pr['label'] === 'Payroll #7' && $pr['url'] !== null && strpos($pr['url'], 'id=7') !== false, "payroll → 'Payroll #7' linked");

$ex = gl_source_link('expense', 9);
ok($ex['label'] === 'Expense #9' && $ex['url'] !== null && strpos($ex['url'], 'id=9') !== false, "expense → 'Expense #9' linked");

$sp = gl_source_link('supplier_payment', 3);
ok($sp['label'] === 'Supplier Payment #3' && $sp['url'] === null, "supplier_payment → labelled, no link (no detail page)");

$cp = gl_source_link('payment', 4);
ok($cp['label'] === 'Customer Payment #4' && $cp['url'] === null, "payment → 'Customer Payment #4', no link");

$unk = gl_source_link('stock_adjustment', 2);
ok($unk['label'] === 'Stock Adjustment #2' && $unk['url'] === null, "unknown type humanised → 'Stock Adjustment #2', no link");

$empty = gl_source_link('', 0);
ok($empty['label'] === '' && $empty['url'] === null, "empty entity_type (manual journal) → no source");

$zero = gl_source_link('invoice', 0);
ok($zero['label'] === '' && $zero['url'] === null, "entity_id <= 0 → no source (guards bad data)");

echo "\n\033[1m── 3. Ledger query with entity_type/entity_id runs live ──\033[0m\n";
try {
    $acc = $pdo->query("SELECT account_id FROM accounts WHERE status='active' ORDER BY account_id ASC LIMIT 1")->fetchColumn();
    $stmt = $pdo->prepare("
        SELECT je.entry_date, je.reference_number AS entry_number, je.entity_type, je.entity_id,
               jei.type, jei.amount, jei.description, je.entry_id
          FROM journal_entries je
          JOIN journal_entry_items jei ON je.entry_id = jei.entry_id
          JOIN accounts a ON jei.account_id = a.account_id
         WHERE je.entry_date BETWEEN ? AND ?
           AND je.status = 'posted'
           AND jei.account_id = ?
      ORDER BY je.entry_date ASC, je.entry_id ASC, jei.item_id ASC
    ");
    $stmt->execute([date('Y-01-01'), date('Y-12-31'), (int)$acc]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ok(true, "single-account ledger query executes cleanly (" . count($rows) . " rows for account #" . (int)$acc . ")");
    $bad = 0;
    foreach ($rows as $r) {
        $s = gl_source_link($r['entity_type'] ?? null, (int)($r['entity_id'] ?? 0));
        if (!array_key_exists('label', $s) || !array_key_exists('url', $s)) $bad++;
    }
    ok($bad === 0, "resolver returns a well-formed result for every ledger row ($bad bad)");
} catch (Throwable $e) {
    ok(false, "ledger query failed: " . $e->getMessage());
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
