<?php
/**
 * tests/test_income_statement_drilldown_cli.php
 * ---------------------------------------------
 * Guards the per-line drill-down on the Income Statement: each grouped P&L line
 * carries a `drill` descriptor; a detail endpoint lists the contributing records;
 * the page shows a print-hidden View icon + modal.
 *
 *   php tests/test_income_statement_drilldown_cli.php
 */

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok($m){ global $pass; $pass++; echo "  [PASS] $m\n"; }
function no($m){ global $fail; $fail++; echo "  [FAIL] $m\n"; }
function chk($c,$m){ $c?ok($m):no($m); }
function src($p){ return file_exists($p)?file_get_contents($p):''; }
function has($hay,$needle,$label){ strpos($hay,$needle)!==false ? ok($label) : no("$label (missing: ".substr($needle,0,46).")"); }
register_shutdown_function(function(){ global $pass,$fail; echo "\n".str_repeat('-',50)."\nRESULT: $pass passed, $fail failed\n"; if($fail>0) exit(1); });

$page   = "$root/app/bms/invoice/income_statement.php";
$apiMain= "$root/api/account/get_income_statement.php";
$apiDet = "$root/api/account/get_income_statement_detail.php";

echo "== 1. Lint ==\n";
foreach ([$page,$apiMain,$apiDet,"$root/roots.php"] as $f) {
    $rc=0;$o=[]; exec('php -l '.escapeshellarg($f).' 2>&1',$o,$rc);
    chk($rc===0, 'lint: '.basename($f));
}

echo "\n== 2. Main API attaches drill descriptors ==\n";
$m = src($apiMain);
foreach (["'source' => 'invoices'","'source' => 'ipc'","'source' => 'sales_returns'","'source' => 'product_cogs'",
          "'source' => 'subcontractor'","'source' => 'expenses'","'source' => 'payroll'","'source' => 'depreciation'",
          "'source' => 'other_income'","'source' => 'revenues'","'source' => 'journal'"] as $needle) {
    has($m, $needle, 'drill: '.trim(explode("=>",$needle)[1]));
}
has($m, "ec.id AS category_id", 'expense grouping exposes category_id for drill');

echo "\n== 3. Detail endpoint handles every source ==\n";
$d = src($apiDet);
foreach (['invoices','ipc','sales_returns','product_cogs','subcontractor','expenses','payroll','depreciation','other_income','revenues','journal','petty_cash'] as $s) {
    has($d, "case '$s':", "detail handles source '$s'");
}
has($d, "canView('income_statement')", 'detail endpoint permission-gated');
// Regression: supplier_credit_notes PK is credit_note_id, not `id`.
chk(strpos($d, "CONCAT('SCN-', id)") === false, "no bad bare 'id' column for supplier_credit_notes");
has($d, 'scn.credit_note_id', 'supplier_credit_notes uses its real PK (credit_note_id)');
has($d, "userCan('project'", 'detail endpoint enforces project scope');
has($d, "scopeFilterSqlNullable", 'detail endpoint applies non-admin scope filter');
has($root && strpos(src("$root/roots.php"), "get_income_statement_detail") !== false ? 'x':'', 'x', 'detail route registered in roots.php');

echo "\n== 4. Page: print-hidden View icon + modal ==\n";
$p = src($page);
has($p, 'drill-btn', 'view buttons rendered per line (drill-btn)');
has($p, 'bi bi-eye', 'eye icon used');
has($p, '>View</th>', 'View column header present');
has($p, 'd-print-none', 'View column hidden on print (d-print-none)');
has($p, 'id="drillModal"', 'drill modal present');
has($p, 'function openDrill', 'openDrill() drives the modal');
has($p, "get_income_statement_detail", 'page calls the detail endpoint');
has($p, '>Status</th>', 'drill modal has a Status column');
has($p, 'function drillStatus', 'status badge helper present');
has($d, 'AS status', 'detail endpoint selects a status per record');

echo "\n== 6. P&L completeness: gross payroll + employer SDL + petty cash ==\n";
has($m, 'SUM(gross_salary)', 'payroll recognised at GROSS (true employment cost)');
has($m, "'source' => 'petty_cash'", 'Petty Cash Expenses is a drillable P&L line');
has($m, 'Skills Development Levy (SDL)', 'employer SDL is a P&L expense line');
has($m, "FROM petty_cash_transactions", 'petty cash read from its own module');
has($m, 'calcSdlAmount(', 'SDL computed via the statutory engine (rate, threshold)');
has($d, 'pr.gross_salary AS amount', 'payroll drill shows gross (reconciles with the line)');
has($d, "type = 'expense'", 'petty cash drill lists expense-type transactions');
// The View header cell must be the print-hidden one.
chk((bool)preg_match('/<th[^>]*d-print-none[^>]*>\s*View\s*<\/th>/', $p), 'View header cell carries d-print-none');
