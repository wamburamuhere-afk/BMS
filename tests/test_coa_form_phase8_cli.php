<?php
/**
 * Chart of Accounts upgrade — Phase 8 (Add/Edit form redesign) CLI test
 * ---------------------------------------------------------------------
 *   php tests/test_coa_form_phase8_cli.php
 *
 * Static wiring checks for the redesigned account modal on
 * chart_of_accounts.php + the save_account.php parent change:
 *   - parent account select is always visible (old checkbox/hidden div gone)
 *   - normal_balance radio (debit/credit) present
 *   - account type → normal_balance auto-fill wired (ACCOUNT_TYPE_SIDES)
 *   - level badge from parent (ACCOUNT_LEVELS) wired
 *   - system-lock banner + setAccountFieldsLocked + re-enable on submit
 *   - save_account.php reads parent_account_id directly (no is_sub_account)
 *
 * NOTE: actual form behaviour still needs a browser smoke check. Exit 0 = pass.
 */

$root = dirname(__DIR__);
$page = 'app/constant/accounts/chart_of_accounts.php';
$api  = 'api/account/save_account.php';

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function rd(string $root, string $rel): string { $p = "$root/$rel"; return is_file($p) ? file_get_contents($p) : ''; }

register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

$s = rd($root, $page);
$a = rd($root, $api);

section('1. Files lint clean');
foreach ([$page, $api] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
    ok($rc === 0, "$f lint-clean");
}

section('2. Old sub-account checkbox pattern removed');
ok(strpos($s, 'is_sub_account') === false, '#is_sub_account checkbox gone from page');
ok(strpos($s, 'parentAccountField') === false, 'hidden #parentAccountField wrapper gone');
ok(strpos($s, 'toggleParentAccountField') === false, 'toggleParentAccountField() removed');

section('3. Parent select always visible + level badge');
ok(strpos($s, 'id="parent_account_id"') !== false, 'parent_account_id select present');
ok(strpos($s, 'top-level account') !== false, 'parent placeholder = None (top-level)');
ok(strpos($s, 'id="levelBadge"') !== false, 'level badge element present');
ok(strpos($s, 'function updateLevelBadge') !== false, 'updateLevelBadge() defined');
ok(strpos($s, 'ACCOUNT_LEVELS') !== false, 'account level map emitted');

section('4. Normal balance radio + auto-fill from type');
ok(strpos($s, 'name="normal_balance"') !== false, 'normal_balance radio group present');
ok(strpos($s, 'id="nb_debit"') !== false && strpos($s, 'id="nb_credit"') !== false, 'debit + credit options present');
ok(strpos($s, 'ACCOUNT_TYPE_SIDES') !== false, 'type→side map emitted');
ok(preg_match('/#account_type.{0,40}\.on\(.change./s', $s) === 1, 'account type change handler wired');

section('5. System-account lock in the form');
ok(strpos($s, 'id="systemLockBanner"') !== false, 'system lock banner element present');
ok(strpos($s, 'function setAccountFieldsLocked') !== false, 'setAccountFieldsLocked() defined');
ok(strpos($s, 'setAccountFieldsLocked(parseInt(account.is_system') !== false, 'editAccount() applies the lock from is_system');
ok(strpos($s, "document.getElementById(id).disabled = false;") !== false, 'submit re-enables locked fields so they still POST');

section('6. save_account.php reads parent directly');
ok(strpos($a, "\$parent_account_id = !empty(\$_POST['parent_account_id'])") !== false, 'parent read without is_sub_account gate');
ok(strpos($a, "isset(\$_POST['is_sub_account'])") === false, 'is_sub_account POST dependency removed from save_account.php');

exit($fail === 0 ? 0 : 1);
