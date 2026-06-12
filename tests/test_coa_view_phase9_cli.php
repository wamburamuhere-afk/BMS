<?php
/**
 * Chart of Accounts upgrade — Phase 9 (View offcanvas) CLI test
 * -------------------------------------------------------------
 *   php tests/test_coa_view_phase9_cli.php
 *
 * Static wiring checks for the slide-in account view on chart_of_accounts.php
 * (fed by api/account/get_account_detail.php from Phase 2):
 *   - offcanvas markup with 4 tab panes (Details/Sub-Accounts/Transactions/Balance)
 *   - account name is clickable → openAccountView()
 *   - openAccountView() fetches get_account_detail.php; renderAccountView() fills tabs
 *   - balance tab shows the in_sync reconciliation cue
 *   - "Add sub-account" pre-fills the parent
 *   - full-page "View Details" dropdown link is still present (coexists)
 *
 * NOTE: actual panel behaviour still needs a browser smoke check. Exit 0 = pass.
 */

$root = dirname(__DIR__);
$page = 'app/constant/accounts/chart_of_accounts.php';

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }

register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

$s = is_file("$root/$page") ? file_get_contents("$root/$page") : '';

section('1. Page lints clean');
$out = []; $rc = 0;
exec('php -l ' . escapeshellarg("$root/$page") . ' 2>&1', $out, $rc);
ok($rc === 0, "$page lint-clean");

section('2. Offcanvas markup + 4 tab panes');
ok(strpos($s, 'id="accountViewOffcanvas"') !== false, 'offcanvas container present');
foreach (['avDetails', 'avChildren', 'avTxns', 'avBalance'] as $pane) {
    ok(strpos($s, 'id="' . $pane . '"') !== false, "tab pane #$pane present");
}

section('3. Row name opens the view');
ok(strpos($s, 'openAccountView(${row.account_id})') !== false, 'account name wired to openAccountView');

section('4. JS fetch + render');
ok(strpos($s, 'function openAccountView') !== false, 'openAccountView() defined');
ok(strpos($s, 'get_account_detail.php?account_id=') !== false, 'fetches get_account_detail.php');
ok(strpos($s, 'function renderAccountView') !== false, 'renderAccountView() defined');
ok(strpos($s, 'data.children') !== false, 'renders sub-accounts');
ok(strpos($s, 'data.transactions') !== false, 'renders transactions');

section('5. Balance tab reconciliation cue');
ok(strpos($s, 'b.in_sync') !== false, 'reads in_sync flag');
ok(strpos($s, 'calculated_balance') !== false, 'shows calculated balance');
ok(strpos($s, 'differs from the ledger-calculated balance') !== false, 'shows drift warning');

section('6. Add sub-account + coexistence with full page');
ok(strpos($s, 'function addSubAccountFor') !== false, 'addSubAccountFor() defined (pre-fills parent)');
ok(strpos($s, "getUrl('accounts/account_details')") !== false, 'full-page View Details link kept (working route; not the Apache-shadowed account/view)');

exit($fail === 0 ? 0 : 1);
