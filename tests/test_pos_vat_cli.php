<?php
/**
 * POS VAT — Phase 2 guard (two-option selector + output-VAT capture)
 *   php tests/test_pos_vat_cli.php
 *
 * Requirement: POS tax is NEVER auto-applied. The cashier picks one of exactly
 * TWO options per sale — "No Tax (0%)" (default) or "VAT 18%". Tax is exclusive
 * (added on top), so net = grand_total − tax_amount.
 *
 *   A. STATIC contract — pos.php exposes exactly the two options; the script uses
 *      a cashier-selected saleVatRate (not the product's tax_rate); the tax report
 *      folds in POS output VAT.
 *   B. LIVE reconciliation — invoking get_tax_report.php in-process, output_tax =
 *      invoice VAT + POS output VAT (net of returns, un-invoiced only).
 *
 * Read-only. Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function approx($a,$b){ return abs((float)$a-(float)$b) < 0.01; }
function src($p){ return is_file($p)?file_get_contents($p):''; }
register_shutdown_function(function(){ global $pass,$fail; echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; });

try {
    section('A. Two-option VAT selector contract');
    $page   = src("$root/app/bms/pos/pos.php");
    $script = src("$root/app/bms/pos/pos_scripts_new.php");

    ok(strpos($page, 'id="saleVatSelect"') !== false, 'pos.php has the #saleVatSelect tax selector');
    // exactly two <option>s inside the select
    if (preg_match('/id="saleVatSelect".*?<\/select>/s', $page, $m)) {
        $sel = $m[0];
        $optCount = preg_match_all('/<option\b/i', $sel);
        ok($optCount === 2, "selector exposes exactly TWO options (found $optCount)");
        ok(strpos($sel, 'value="0"') !== false && stripos($sel, 'No Tax') !== false, 'option 1 = No Tax (0%)');
        ok(strpos($sel, 'value="18"') !== false && stripos($sel, 'VAT 18%') !== false, 'option 2 = VAT 18%');
        ok(stripos($sel, '5%') === false && stripos($sel, 'withhold') === false && stripos($sel, 'wht') === false,
            'selector does NOT offer the 5% reduced rate or WHT');
        ok(preg_match('/value="0"[^>]*selected/i', $sel) === 1, 'default selection is No Tax (0%) — never auto-applies VAT');
    } else {
        ok(false, 'could not isolate the #saleVatSelect markup');
    }

    ok(strpos($script, 'saleVatRate') !== false, 'script declares a cashier-selected saleVatRate');
    ok((bool)preg_match('/tax_rate:\s*saleVatRate/', $script), 'new cart items take saleVatRate (not the product tax_rate)');
    ok(strpos($script, "'#saleVatSelect'") !== false || strpos($script, '#saleVatSelect') !== false, 'script binds a change handler to #saleVatSelect');
    ok((bool)preg_match('/parseFloat\(\$\(this\)\.val\(\)\)\s*===\s*18/', $script), 'change handler maps the selection to 0 or 18 only');
    // the old auto-apply from the product tax must be gone
    ok(strpos($script, 'parseFloat(currentProduct.tax_rate)') === false, 'no longer auto-applies the product tax_rate to the line');

    section('B. Tax report folds in POS output VAT');
    $tax = src("$root/api/account/get_tax_report.php");
    ok(strpos($tax, 'pos_sales') !== false, 'get_tax_report.php reads pos_sales for output VAT');
    ok(strpos($tax, '$pos_out') !== false && strpos($tax, '+ $pos_out') !== false, 'POS output VAT added into total_output');
    ok(strpos($tax, 'ps.invoice_id IS NULL') !== false, 'POS output VAT excludes already-invoiced POS sales (no double count)');
    ok((bool)preg_match("/SHOW TABLES LIKE 'pos_sales'/", $tax), 'guarded on pos_sales existence (degrades to 0)');

    // Live: output_tax == invoice VAT + POS output VAT
    if ((bool)$pdo->query("SHOW TABLES LIKE 'pos_sales'")->fetch()) {
        $from = '2000-01-01'; $to = '2099-12-31';
        $invVat = (float)$pdo->query("SELECT COALESCE(SUM(tax_amount),0) FROM invoices
                                       WHERE status IN ('approved','overdue','paid','partial')
                                         AND invoice_date BETWEEN '$from' AND '$to'")->fetchColumn();
        $posVat = (float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN is_return_sale=0 THEN tax_amount ELSE -tax_amount END),0)
                                        FROM pos_sales
                                       WHERE invoice_id IS NULL AND DATE(sale_date) BETWEEN '$from' AND '$to'
                                         AND ((is_return_sale=0 AND sale_status IN ('completed','partially_refunded','refunded'))
                                           OR (is_return_sale=1 AND sale_status NOT IN ('voided','cancelled')))")->fetchColumn();

        // Admin session so the in-process report sees everything (no project scope).
        $uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn();
        $_SESSION['user_id'] = $uid; $_SESSION['role_id'] = 1; $_SESSION['is_admin'] = true;
        $_GET['date_from'] = $from; $_GET['date_to'] = $to; unset($_GET['project_id']);

        ob_start();
        include "$root/api/account/get_tax_report.php";
        $json = ob_get_clean();
        $d = json_decode($json, true);
        ok($d && !empty($d['success']), 'tax report returns successfully in-process');
        if ($d && !empty($d['success'])) {
            ok(approx($d['summary']['output_tax'], $invVat + $posVat),
                sprintf('report output_tax (%.2f) == invoice VAT (%.2f) + POS VAT (%.2f)', $d['summary']['output_tax'], $invVat, $posVat));
            ok($posVat == 0.0 || $d['summary']['output_tax'] > $invVat + 0.01,
                'POS output VAT visibly increases output_tax when POS data exists');
        }
    } else {
        ok(true, 'pos_sales absent — live tax-report reconciliation skipped');
    }

} catch (Throwable $e) {
    ok(false, 'threw: ' . $e->getMessage());
}
exit($fail === 0 ? 0 : 1);
