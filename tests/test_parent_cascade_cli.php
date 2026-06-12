<?php
/**
 * tests/test_parent_cascade_cli.php
 * Verifies the cascading Parent Account selector in chart_of_accounts.php:
 *   (1) wiring present (cascade container + hidden field + functions);
 *   (2) the data model that feeds it (parent links) reproduces the real tree, so
 *       each cascade level will offer the correct children — proven by replicating
 *       the coaChildrenOf() logic in PHP against the live accounts.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { echo "  [PASS] $m\n"; $pass++; } else { echo "  [FAIL] $m\n"; $fail++; } }

echo "== 1. Wiring present ==\n";
$src = file_get_contents("$root/app/constant/accounts/chart_of_accounts.php");
ok(strpos($src, 'id="parentCascade"') !== false, 'cascade container present');
ok(strpos($src, 'type="hidden" id="parent_account_id"') !== false, 'parent_account_id is a hidden field the cascade writes to');
ok(strpos($src, 'function renderParentCascade') !== false, 'renderParentCascade() defined');
ok(strpos($src, 'function coaChildrenOf') !== false, 'coaChildrenOf() defined (level filtering)');
ok(strpos($src, 'function coaOnLevelChange') !== false, 'coaOnLevelChange() drills deeper on selection');
ok(strpos($src, 'function coaSyncHiddenParent') !== false, 'coaSyncHiddenParent() mirrors choice to the hidden field');
ok(strpos($src, "'parent' => (\$a['parent_account_id']") !== false, 'ACCOUNTS_LIST carries parent_account_id');
ok(strpos($src, 'function rebuildParentOptions') !== false, 'rebuildParentOptions shim kept for existing callers');
ok(strpos($src, "a.parent_account_id, at.category") !== false, 'accounts query selects parent_account_id');

echo "\n== 2. Data model: cascade children-of logic reproduces the real tree ==\n";
// Mirror the JS ACCOUNTS_LIST.
$rows = $pdo->query("SELECT a.account_id id, a.account_code code, a.account_name name, a.parent_account_id parent, at.category
                       FROM accounts a LEFT JOIN account_types at ON a.account_type_id=at.type_id
                      WHERE a.status != 'deleted'")->fetchAll(PDO::FETCH_ASSOC);
$byId = []; foreach ($rows as $r) $byId[(int)$r['id']] = $r;

// Replicates coaChildrenOf(parentId, category): direct children, self-loop -> root.
$childrenOf = function ($pid, $category) use ($rows) {
    $out = [];
    foreach ($rows as $a) {
        $p = ($a['parent'] === null) ? '' : (string)$a['parent'];
        if ($p === (string)$a['id']) $p = '';
        if ($p !== (string)$pid) continue;
        if ($category && $a['category'] !== $category) continue;
        $out[] = $a;
    }
    return $out;
};

// Roots of the asset class (level-0 options).
$assetRoots = $childrenOf('', 'asset');
ok(count($assetRoots) > 0, 'asset class has at least one top-level root (' . count($assetRoots) . ')');

// Walk a known chain: 1-0000 Assets -> 1-1000 Current Assets -> 1-1100 Cash On Hand -> a leaf.
$find = function ($code) use ($rows) { foreach ($rows as $r) if ($r['code'] === $code) return $r; return null; };
$assets = $find('1-0000'); $ca = $find('1-1000'); $coh = $find('1-1100');
if ($assets && $ca && $coh) {
    $kidsOfAssets = $childrenOf($assets['id'], 'asset');
    ok(in_array('1-1000', array_column($kidsOfAssets, 'code'), true), 'drilling 1-0000 offers 1-1000 Current Assets');
    $kidsOfCA = $childrenOf($ca['id'], 'asset');
    ok(in_array('1-1100', array_column($kidsOfCA, 'code'), true), 'drilling 1-1000 offers 1-1100 Cash On Hand');
    $kidsOfCOH = $childrenOf($coh['id'], 'asset');
    ok(count($kidsOfCOH) > 0, 'drilling 1-1100 offers its sub-accounts (' . count($kidsOfCOH) . ')');
    // Cross-class isolation: no asset child leaks a non-asset category.
    $leak = array_filter($kidsOfCOH, fn($k) => $k['category'] !== 'asset');
    ok(count($leak) === 0, 'cascade keeps the same class (no cross-class children)');
} else {
    ok(false, 'expected seeded chain 1-0000/1-1000/1-1100 not found — cannot test drill');
}

// Every non-root child resolves to a real parent (tree integrity for the cascade).
$orphans = 0;
foreach ($rows as $a) {
    $p = ($a['parent'] === null) ? null : (int)$a['parent'];
    if ($p && $p !== (int)$a['id'] && !isset($byId[$p])) $orphans++;
}
ok($orphans === 0, "no child points to a missing parent ($orphans orphan links)");

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
