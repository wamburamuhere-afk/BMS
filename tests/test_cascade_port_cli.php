<?php
/**
 * tests/test_cascade_port_cli.php
 * Verifies the cascading parent selector was ported to Bank Accounts + Petty Cash
 * using the shared assets/js/parent_cascade.js module, and that the data feeding it
 * (asset accounts + parent links) forms a valid drillable tree.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { echo "  [PASS] $m\n"; $pass++; } else { echo "  [FAIL] $m\n"; $fail++; } }
function src($root, $rel) { $p = "$root/$rel"; return is_file($p) ? file_get_contents($p) : ''; }

echo "== 1. Shared module present + sane ==\n";
$mod = src($root, 'assets/js/parent_cascade.js');
ok($mod !== '', 'assets/js/parent_cascade.js exists');
ok(strpos($mod, 'window.initParentCascade') !== false, 'exposes initParentCascade()');
ok(strpos($mod, 'pcasc-level') !== false, 'renders cascade levels');
ok(substr_count($mod, '{') === substr_count($mod, '}'), 'braces balanced');

echo "\n== 2. Bank Accounts wired to the cascade ==\n";
$bank = src($root, 'app/constant/accounts/bank_accounts.php');
ok(strpos($bank, "parent_cascade.js") !== false, 'includes the shared module');
ok(strpos($bank, 'id="add_parentCascade"') !== false && strpos($bank, 'type="hidden" id="add_parent_account_id"') !== false, 'Add form: cascade + hidden parent field');
ok(strpos($bank, 'id="edit_parentCascade"') !== false && strpos($bank, 'type="hidden" id="edit_parent_account_id"') !== false, 'Edit form: cascade + hidden parent field');
ok(strpos($bank, 'initParentCascade({') !== false, 'initialises the cascade');
ok(strpos($bank, 'const BANK_PARENTS') !== false && strpos($bank, "'parent' =>") !== false, 'passes asset accounts with parent links');
ok(strpos($bank, 'a.parent_account_id, at.category') !== false, 'parent query selects parent_account_id + category');

echo "\n== 3. Petty Cash wired to the cascade ==\n";
$petty = src($root, 'app/constant/accounts/petty_cash.php');
ok(strpos($petty, "parent_cascade.js") !== false, 'includes the shared module');
ok(strpos($petty, 'id="pc_parentCascade"') !== false && strpos($petty, 'type="hidden" id="pc_parent_account_id"') !== false, 'Edit Account modal: cascade + hidden parent field');
ok(strpos($petty, 'const PC_PARENTS') !== false, 'passes asset accounts to the cascade');
ok(strpos($petty, 'excludeId: PC_ACCOUNT_ID') !== false, 'excludes self (no self-parent)');
ok(strpos($petty, 'pcParentChanged') !== false && strpos($petty, 'PC_CODE_LOCKED') !== false, 'system-account code lock respected on re-parent');

echo "\n== 4. Data model: asset tree is drillable (same logic the module uses) ==\n";
$rows = $pdo->query("SELECT a.account_id id, a.account_code code, a.parent_account_id parent, at.category cat
                       FROM accounts a JOIN account_types at ON a.account_type_id=at.type_id
                      WHERE a.status='active' AND at.category='asset'")->fetchAll(PDO::FETCH_ASSOC);
$childrenOf = function ($pid) use ($rows) {
    return array_values(array_filter($rows, function ($a) use ($pid) {
        $p = ($a['parent'] === null) ? '' : (string)$a['parent'];
        if ($p === (string)$a['id']) $p = '';
        return $p === (string)$pid;
    }));
};
$find = function ($code) use ($rows) { foreach ($rows as $r) if ($r['code'] === $code) return $r; return null; };
ok(count($childrenOf('')) > 0, 'asset class has top-level roots (' . count($childrenOf('')) . ')');
$coh = $find('1-1100');
ok($coh && count($childrenOf($coh['id'])) > 0, 'Cash On Hand (1-1100) drills to its sub-accounts');

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
