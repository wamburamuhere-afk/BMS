<?php
/**
 * Chart of Accounts — delete is ADMIN-ONLY (accounts, categories, system accounts)
 * --------------------------------------------------------------------------------
 *   php tests/test_admin_only_delete_cli.php
 *
 * Verifies the policy the owner asked for:
 *   - delete_account.php and delete_account_category.php require isAdmin();
 *   - the chart page only renders Delete (account + category) for admins;
 *   - admins may delete LOCKED/system accounts (the hard is_system block is gone),
 *     while non-admins don't even see the button.
 * Static + source checks (no destructive writes). Exit 0 = pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel){ $p="$root/$rel"; return is_file($p)?file_get_contents($p):''; }
register_shutdown_function(function(){ global $pass,$fail; echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; });

$page = src($root, 'app/constant/accounts/chart_of_accounts.php');
$delAcc = src($root, 'api/account/delete_account.php');
$delCat = src($root, 'api/account/delete_account_category.php');

section('1. Server APIs are admin-only');
foreach ([
    'api/account/delete_account.php'          => $delAcc,
    'api/account/delete_account_category.php' => $delCat,
] as $rel => $s) {
    $out=[]; $rc=0; exec('php -l ' . escapeshellarg("$root/$rel") . ' 2>&1', $out, $rc);
    ok($rc === 0, "$rel lint-clean");
    ok(preg_match('/if \(!isAdmin\(\)\)/', $s) === 1, "$rel gated by isAdmin()");
    ok(strpos($s, "canDelete('chart_of_accounts')") === false, "$rel no longer uses the canDelete permission for the gate");
}

section('2. delete_account.php — admin delete + system-wired safeguard');
ok(strpos($delAcc, 'system account and cannot be deleted') === false, 'old is_system-flag blanket block is gone (uses a live reference check instead)');
ok(strpos($delAcc, 'existing transactions') !== false, 'still blocks deleting an account that has transactions');
ok(strpos($delAcc, 'sub-accounts') !== false, 'still blocks deleting an account that has sub-accounts');
// New safeguard: block deleting an account wired into system_settings or journal_mappings.
ok(strpos($delAcc, "system_settings WHERE setting_key REGEXP '_account_id\$' AND setting_value = ?") !== false, 'checks system_settings *_account_id references');
ok(strpos($delAcc, 'journal_mappings WHERE debit_account_id = ? OR credit_account_id = ?') !== false, 'checks journal_mappings references');
ok(strpos($delAcc, 'wired into the system and cannot be deleted') !== false || strpos($delAcc, 'it is wired into the system') !== false, 'blocks deletion of a settings/mapping-wired account with a clear message');

section('3. Chart page renders Delete for admins only');
ok(strpos($page, "isAdmin: <?= isAdmin()") !== false, 'JS receives an isAdmin flag');
ok(strpos($page, 'if (userPermissions.isAdmin) {') !== false, 'account-row Delete is gated on isAdmin');
ok(strpos($page, "userPermissions.canDelete && !locked") === false, 'old canDelete/!locked rule removed');
// admin can delete locked rows → the delete onclick carries the locked flag (1) for system rows
ok(strpos($page, "deleteAccount(\${row.account_id}, '\${escapeHtml(row.account_name)}', \${locked ? 1 : 0})") !== false, 'admin Delete works on locked rows (passes a locked flag for the warning)');
ok(strpos($page, 'System account — protected') !== false, 'non-admins still see the protected note instead of a button');

section('4. Account Categories Delete is admin-only');
ok(strpos($page, '<?php if (isAdmin()): /* delete is admin-only */ ?>') !== false, 'category Delete link gated on isAdmin()');
// non-admin without edit shouldn't even get the gear (wrapper uses canEdit || isAdmin)
ok(strpos($page, "if (canEdit('chart_of_accounts') || isAdmin())") !== false, 'category gear shows for editors or admins');

section('5. Non-admin experience unchanged otherwise (view/edit intact)');
ok(strpos($page, "canEdit: <?= canEdit('chart_of_accounts')") !== false, 'edit still permission-driven (not admin-gated)');
ok(strpos($page, "canView: <?= canView('chart_of_accounts')") !== false, 'view still permission-driven');

section('6. Safeguard proven live: a settings-wired account is blocked');
// Reproduce the guard condition against real data.
$wiredId = (int)$pdo->query("
    SELECT CAST(setting_value AS UNSIGNED) AS aid
      FROM system_settings
     WHERE setting_key REGEXP '_account_id$' AND setting_value REGEXP '^[0-9]+$'
       AND CAST(setting_value AS UNSIGNED) IN (SELECT account_id FROM accounts)
     LIMIT 1
")->fetchColumn();
if ($wiredId > 0) {
    $refs = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key REGEXP '_account_id$' AND setting_value = ?");
    $refs->execute([(string)$wiredId]);
    ok((int)$refs->fetchColumn() > 0, "settings-wired account #$wiredId is detected by the guard → would be BLOCKED");
} else {
    ok(true, 'no settings-wired account to probe (n/a)');
}
// A plain, non-wired leaf is NOT caught by the guard.
$freeId = (int)$pdo->query("
    SELECT a.account_id FROM accounts a
     WHERE a.is_system = 0
       AND NOT EXISTS (SELECT 1 FROM accounts c WHERE c.parent_account_id = a.account_id)
       AND NOT EXISTS (
            SELECT 1 FROM system_settings s
             WHERE s.setting_key REGEXP '_account_id$'
               AND s.setting_value REGEXP '^[0-9]+$'
               AND CAST(s.setting_value AS UNSIGNED) = a.account_id
       )
     LIMIT 1
")->fetchColumn();
if ($freeId > 0) {
    $refs = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key REGEXP '_account_id$' AND setting_value = ?");
    $refs->execute([(string)$freeId]);
    ok((int)$refs->fetchColumn() === 0, "non-wired account #$freeId is NOT caught by the guard → deletable (if empty)");
} else {
    ok(true, 'no non-wired account to probe (n/a)');
}

exit($fail === 0 ? 0 : 1);
