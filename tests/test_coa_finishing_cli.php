<?php
/**
 * Chart of Accounts upgrade — finishing items CLI test
 * ----------------------------------------------------
 *   php tests/test_coa_finishing_cli.php
 *
 * Covers the gap-closure work:
 *   - Phase 10 roll-up: recursive subtree sum wired in get_chart_of_accounts.php
 *     + renderer uses balance_incl/has_children; PROVEN live (parent+child,
 *     transaction rolled back)
 *   - Select2 on the parent picker (guarded) + Select2-safe value setting
 *   - bank_accounts.php system-account lock parity (banner, lock, re-enable, badge)
 *
 * Writes only inside a transaction that is always rolled back. Exit 0 = pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function rd(string $root, string $rel): string { $p = "$root/$rel"; return is_file($p) ? file_get_contents($p) : ''; }

register_shutdown_function(function () {
    global $pass, $fail, $pdo;
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

$coaApi  = rd($root, 'api/account/get_chart_of_accounts.php');
$coaPage = rd($root, 'app/constant/accounts/chart_of_accounts.php');
$bank    = rd($root, 'app/constant/accounts/bank_accounts.php');

try {
    // ─────────────────────────────────────────────────────────────────────
    section('1. Files lint clean');
    // ─────────────────────────────────────────────────────────────────────
    foreach (['api/account/get_chart_of_accounts.php', 'app/constant/accounts/chart_of_accounts.php', 'app/constant/accounts/bank_accounts.php'] as $f) {
        $out = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
        ok($rc === 0, "$f lint-clean");
    }

    // ─────────────────────────────────────────────────────────────────────
    section('2. Phase 10 roll-up wired');
    // ─────────────────────────────────────────────────────────────────────
    ok(strpos($coaApi, 'WITH RECURSIVE subtree') !== false, 'API uses a recursive subtree CTE');
    ok(strpos($coaApi, "\$row['balance_incl']") !== false, 'API attaches balance_incl to rows');
    ok(strpos($coaApi, "\$row['has_children']") !== false, 'API attaches has_children to rows');
    ok(strpos($coaPage, 'row.balance_incl') !== false, 'renderer reads balance_incl');
    ok(strpos($coaPage, 'row.has_children') !== false, 'renderer reads has_children');
    ok(strpos($coaPage, 'Includes sub-accounts') !== false, 'parent rows label the rolled-up total');

    // ─────────────────────────────────────────────────────────────────────
    section('3. Roll-up math proven live (parent + child, rolled back)');
    // ─────────────────────────────────────────────────────────────────────
    $typeId = (int)$pdo->query("SELECT type_id FROM account_types ORDER BY type_id LIMIT 1")->fetchColumn();
    $pdo->beginTransaction();
    try {
        $pc = 'TP10P-' . substr(uniqid(), -6);
        $pdo->prepare("INSERT INTO accounts (account_code, account_name, account_type_id, account_type, opening_balance, current_balance, level, normal_balance, status, created_at, updated_at) VALUES (?,?,?,'asset',0,1000,1,'debit','active',NOW(),NOW())")->execute([$pc, 'Rollup Parent', $typeId]);
        $parentId = (int)$pdo->lastInsertId();
        $cc = 'TP10C-' . substr(uniqid(), -6);
        $pdo->prepare("INSERT INTO accounts (account_code, account_name, account_type_id, account_type, opening_balance, current_balance, parent_account_id, level, normal_balance, status, created_at, updated_at) VALUES (?,?,?,'asset',0,250,?,2,'debit','active',NOW(),NOW())")->execute([$cc, 'Rollup Child', $typeId, $parentId]);
        $childId = (int)$pdo->lastInsertId();

        $rsql = "WITH RECURSIVE subtree AS (
                    SELECT account_id AS root_id, account_id AS node_id, current_balance FROM accounts
                    UNION ALL
                    SELECT s.root_id, a.account_id, a.current_balance FROM subtree s JOIN accounts a ON a.parent_account_id = s.node_id WHERE a.account_id <> a.parent_account_id
                 ) SELECT root_id, SUM(current_balance) AS balance_incl, COUNT(*)-1 AS descendant_count FROM subtree GROUP BY root_id";
        $map = [];
        foreach ($pdo->query($rsql) as $r) { $map[(int)$r['root_id']] = $r; }

        ok(isset($map[$parentId]) && abs((float)$map[$parentId]['balance_incl'] - 1250.0) < 0.01, 'parent balance_incl = own 1000 + child 250 = 1250');
        ok(isset($map[$parentId]) && (int)$map[$parentId]['descendant_count'] === 1, 'parent descendant_count = 1 (has_children)');
        ok(isset($map[$childId]) && abs((float)$map[$childId]['balance_incl'] - 250.0) < 0.01, 'child balance_incl = own 250 (leaf)');
        ok(isset($map[$childId]) && (int)$map[$childId]['descendant_count'] === 0, 'child descendant_count = 0 (leaf)');

        $pdo->rollBack();
        ok(!$pdo->inTransaction(), 'rolled back — no test accounts left behind');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ok(false, 'rollup live probe threw: ' . $e->getMessage());
    }

    // ─────────────────────────────────────────────────────────────────────
    section('4. Select2 on the parent picker (guarded) + Select2-safe set');
    // ─────────────────────────────────────────────────────────────────────
    ok(strpos($coaPage, '$.fn.select2') !== false, 'Select2 init guarded by $.fn.select2 (graceful fallback)');
    ok(strpos($coaPage, "\$('#parent_account_id').select2(") !== false, 'parent picker initialised as Select2');
    ok(strpos($coaPage, "\$('#parent_account_id').val(account.parent_account_id || '').trigger('change')") !== false, 'editAccount sets parent Select2-safely');
    ok(strpos($coaPage, "\$('#parent_account_id').val(parentId).trigger('change')") !== false, 'addSubAccountFor sets parent Select2-safely');

    // ─────────────────────────────────────────────────────────────────────
    section('5. bank_accounts.php system-lock parity');
    // ─────────────────────────────────────────────────────────────────────
    ok(strpos($bank, 'id="bankSystemLockBanner"') !== false, 'edit modal has a system-lock banner');
    ok(strpos($bank, 'function setBankFieldsLocked') !== false, 'setBankFieldsLocked() defined');
    ok(strpos($bank, 'setBankFieldsLocked(parseInt(acc.is_system') !== false, 'editAccount applies the lock from is_system');
    ok(strpos($bank, "document.getElementById(id).disabled = false;") !== false, 'submit re-enables locked fields so they still POST');
    ok(strpos($bank, 'System account — protected') !== false, 'system accounts show a lock badge in the list');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
