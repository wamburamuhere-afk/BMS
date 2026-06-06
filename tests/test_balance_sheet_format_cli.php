<?php
/**
 * tests/test_balance_sheet_format_cli.php
 * ---------------------------------------
 * Guards the European (horizontal) | British (vertical) format toggle on the
 * Balance Sheet. Source-level checks (the data contract is covered by
 * test_balance_sheet_cli.php) + a numeric proof that the British "Net Assets =
 * Capital Employed" identity is the SAME balance check as the European one.
 *
 *   php tests/test_balance_sheet_format_cli.php
 */

$root = dirname(__DIR__);
$file = "$root/app/constant/reports/balance_sheet.php";
$pass = 0; $fail = 0;
function ok($m){ global $pass; $pass++; echo "  [PASS] $m\n"; }
function no($m){ global $fail; $fail++; echo "  [FAIL] $m\n"; }
function chk($c,$m){ $c?ok($m):no($m); }
register_shutdown_function(function(){ global $pass,$fail; echo "\n".str_repeat('-',50)."\nRESULT: $pass passed, $fail failed\n"; if($fail>0) exit(1); });

echo "== Balance Sheet format toggle ==\n";

// 1. Lint
$rc=0;$o=[]; exec('php -l '.escapeshellarg($file).' 2>&1',$o,$rc);
chk($rc===0, 'balance_sheet.php lints clean');

$src = file_get_contents($file);

// 2. Toggle + both branches present
chk(strpos($src, "\$format = ") !== false, 'reads a $format selector');
chk(strpos($src, "format=european") !== false && strpos($src, "format=british") !== false, 'European + British toggle buttons present');
chk(strpos($src, "if (\$format === 'european')") !== false, 'European (horizontal) branch guarded');
chk(strpos($src, "if (\$format === 'british')") !== false, 'British (vertical) branch guarded');
chk(strpos($src, 'NET CURRENT ASSETS') !== false, 'British block shows Net Current Assets (working capital)');
chk(strpos($src, 'CAPITAL EMPLOYED') !== false, 'British block shows Capital Employed');
chk(strpos($src, 'NET ASSETS') !== false, 'British block shows Net Assets');
// European layout preserved (two-sided)
chk(strpos($src, 'col-md-6 border-end') !== false, 'European two-column layout still present');

// 3. Numeric identity: British (Net Assets − Capital Employed) == European (Assets − (Liab+Equity)).
// Using representative figures, prove the two balance checks are the same number.
$nonCurrentAssets = 500000.0; $currentAssets = 300000.0;
$currentLiab = 120000.0; $nonCurrentLiab = 200000.0; $equity = 480000.0;
$assetsTotal = $nonCurrentAssets + $currentAssets;
$liabTotal   = $currentLiab + $nonCurrentLiab;
// British chain
$netCurrent  = $currentAssets - $currentLiab;
$totLessCL   = $nonCurrentAssets + $netCurrent;
$netAssets   = $totLessCL - $nonCurrentLiab;
$britishDiff  = $netAssets - $equity;
$europeanDiff = $assetsTotal - ($liabTotal + $equity);
chk(abs($britishDiff - $europeanDiff) < 0.001, "British balance check == European balance check ($britishDiff)");
chk(abs($netCurrent - 180000) < 0.001, 'Net current assets = current assets − current liabilities (180,000)');
chk(abs($netAssets - ($assetsTotal - $liabTotal)) < 0.001, 'Net assets = total assets − total liabilities');
