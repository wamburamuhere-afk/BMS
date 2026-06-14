<?php
/**
 * Income Statement — single-source (GL) rewrite — CLI test
 *   php tests/test_income_statement_sources_cli.php
 *
 * After the F3 flip the P&L derives from the canonical ledger (glProfitLoss), not
 * from invoices / pos_sales / expenses / payroll / IPC documents. Asserts the GL
 * source contract and that the old document reads are gone.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $p=false; if($p)return; $p=true;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m") . "\n";
    if ($fail>0) exit(1);
});

$src = file_get_contents("$root/api/account/get_income_statement.php");

echo "GL single-source patterns present:\n";
foreach ([
    "core/financial_reports.php" => 'includes the GL engine',
    "glProfitLoss("              => 'reads revenue/cogs/expense/finance from the GL',
    "'general_ledger'"           => 'meta.source = general_ledger',
    "scopeFilterSqlNullable('project'" => 'project scope helper',
    "'source' => 'journal'"      => 'lines drill to the general ledger',
] as $needle => $label) (strpos($src, $needle) !== false) ? pass($label) : fail("$label — missing");

echo "\nOld document reads removed (now single-source):\n";
foreach ([
    "FROM invoices"                     => 'no direct invoices read',
    "FROM interim_payment_certificates" => 'no direct IPC read',
    "FROM sales_returns"                => 'no direct sales_returns read',
    "FROM pos_sales"                    => 'no direct pos_sales read',
    "FROM expenses"                     => 'no direct expenses read',
    "FROM payroll"                      => 'no direct payroll read',
    "ii.quantity * COALESCE(p.cost_price" => 'no document COGS computation',
] as $needle => $label) (strpos($src, $needle) === false) ? pass($label) : fail("$label — old read still present");
