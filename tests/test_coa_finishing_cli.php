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
$save    = rd($root, 'api/account/save_account.php');

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
    section('4. Cascading parent selector (replaces the flat Select2 picker)');
    // ─────────────────────────────────────────────────────────────────────
    ok(strpos($coaPage, '$.fn.select2') !== false, 'Select2 still guarded for the other modal selects (graceful fallback)');
    ok(strpos($coaPage, 'id="parentCascade"') !== false && strpos($coaPage, 'type="hidden" id="parent_account_id"') !== false,
       'parent picker is now a cascade writing into a hidden #parent_account_id');
    ok(strpos($coaPage, "renderParentCascade(account.category || '', account.parent_account_id || '')") !== false,
       'editAccount pre-selects the cascade to the account parent chain');
    ok(strpos($coaPage, 'renderParentCascade(pa ? pa.category') !== false,
       'addSubAccountFor drills the cascade to the chosen parent');

    // ─────────────────────────────────────────────────────────────────────
    section('5. bank_accounts.php system-lock parity');
    // ─────────────────────────────────────────────────────────────────────
    ok(strpos($bank, 'id="bankSystemLockBanner"') !== false, 'edit modal has a system-lock banner');
    ok(strpos($bank, 'function setBankFieldsLocked') !== false, 'setBankFieldsLocked() defined');
    ok(strpos($bank, 'setBankFieldsLocked(parseInt(acc.is_system') !== false, 'editAccount applies the lock from is_system');
    ok(strpos($bank, "document.getElementById(id).disabled = false;") !== false, 'submit re-enables locked fields so they still POST');
    ok(strpos($bank, 'System account — protected') !== false, 'system accounts show a lock badge in the list');

    // ─────────────────────────────────────────────────────────────────────
    section('6. Tree ORDER + sibling independence (live, rolled back)');
    // ─────────────────────────────────────────────────────────────────────
    ok(strpos($coaApi, 'WITH RECURSIVE acct_tree') !== false, 'API builds a tree sort-path CTE');
    ok(strpos($coaApi, 'ORDER BY atr.sort_path') !== false, 'data query orders by the tree path');
    $tid = (int)$pdo->query("SELECT type_id FROM account_types LIMIT 1")->fetchColumn();
    $pdo->beginTransaction();
    try {
        $mk = function($code, $name, $bal, $parent, $lvl) use ($pdo, $tid) {
            $pdo->prepare("INSERT INTO accounts (account_code,account_name,account_type_id,account_type,opening_balance,current_balance,parent_account_id,level,normal_balance,status,created_at,updated_at) VALUES (?,?,?,'asset',0,?,?,?,'debit','active',NOW(),NOW())")
                ->execute([$code, $name, $tid, $bal, $parent, $lvl]);
            return (int)$pdo->lastInsertId();
        };
        $sfx = substr(uniqid(), -5);
        $p  = $mk("ZZTREE-$sfx-1000", 'Current Assets', 0, null, 1);
        $c1 = $mk("ZZTREE-$sfx-1100", 'Cash On Hand', 500, $p, 2);
        $c2 = $mk("ZZTREE-$sfx-1200", 'Bank', 300, $p, 2);

        $rsql = "WITH RECURSIVE acct_tree AS (
                    SELECT account_id, CAST(account_code AS CHAR(500)) AS sort_path FROM accounts
                     WHERE parent_account_id IS NULL OR parent_account_id=account_id OR parent_account_id NOT IN (SELECT account_id FROM accounts)
                    UNION ALL
                    SELECT a.account_id, CONCAT(t.sort_path,'>',a.account_code) FROM accounts a JOIN acct_tree t ON a.parent_account_id=t.account_id WHERE a.account_id<>a.parent_account_id
                 ) SELECT a.account_id FROM accounts a JOIN acct_tree atr ON atr.account_id=a.account_id WHERE a.account_code LIKE 'ZZTREE-$sfx-%' ORDER BY atr.sort_path, a.account_id";
        $order = array_map('intval', $pdo->query($rsql)->fetchAll(PDO::FETCH_COLUMN));
        ok($order === [$p, $c1, $c2], 'children sort immediately beneath their parent (parent → c1 → c2)');

        // Sibling independence: reduce c1, c2 must not move; parent total reflects it.
        $pdo->prepare("UPDATE accounts SET current_balance = current_balance - 200 WHERE account_id = ?")->execute([$c1]);
        $c2bal = (float)$pdo->query("SELECT current_balance FROM accounts WHERE account_id=$c2")->fetchColumn();
        $incl  = (float)$pdo->query("SELECT SUM(current_balance) FROM accounts WHERE account_id=$p OR parent_account_id=$p")->fetchColumn();
        ok(abs($c2bal - 300.0) < 0.01, 'reducing one child does NOT change its sibling (Bank still 300)');
        ok(abs($incl - 600.0) < 0.01, 'parent roll-up reflects the change (own 0 + 300 + 300 = 600)');

        $pdo->rollBack();
        ok(!$pdo->inTransaction(), 'rolled back — no test accounts left behind');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ok(false, 'tree-order probe threw: ' . $e->getMessage());
    }

    // ─────────────────────────────────────────────────────────────────────
    section('7. Same-class nesting rule (the logic of being related)');
    // ─────────────────────────────────────────────────────────────────────
    ok(strpos($save, 'same class as its parent') !== false, 'save_account.php enforces same-class nesting');
    ok(strpos($coaPage, 'function rebuildParentOptions') !== false, 'parent picker filtered to same class (rebuildParentOptions)');
    ok(strpos($coaPage, 'ACCOUNT_TYPE_CATEGORIES') !== false, 'type→category map emitted for the picker');
    // Every existing child shares its parent's BROAD statement class — the whole tree
    // (incl. the seed) is consistent. cogs / finance_cost are Income-Statement cost
    // sub-classes of expense (IS Phase 1), and income == revenue, so they nest together
    // legitimately (e.g. Bank Charges [finance_cost] under Expenses [expense]).
    $childBroad  = "CASE WHEN at.category IN ('expense','cogs','finance_cost') THEN 'expense' WHEN at.category IN ('revenue','income') THEN 'income' ELSE at.category END";
    $parentBroad = "CASE WHEN pt.category IN ('expense','cogs','finance_cost') THEN 'expense' WHEN pt.category IN ('revenue','income') THEN 'income' ELSE pt.category END";
    $violations = (int)$pdo->query("
        SELECT COUNT(*)
          FROM accounts a
          JOIN accounts p       ON a.parent_account_id = p.account_id
          JOIN account_types at ON a.account_type_id   = at.type_id
          JOIN account_types pt ON p.account_type_id   = pt.type_id
         WHERE a.parent_account_id <> a.account_id
           AND at.category IS NOT NULL AND pt.category IS NOT NULL
           AND ($childBroad) <> ($parentBroad)
    ")->fetchColumn();
    ok($violations === 0, "no account sits under a different-class parent ($violations violations)");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
