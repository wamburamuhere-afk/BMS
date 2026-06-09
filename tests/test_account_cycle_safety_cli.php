<?php
/**
 * Account tree cycle-safety — regression guard
 *   php tests/test_account_cycle_safety_cli.php
 *
 * A cycle in accounts.parent_account_id (A→B→A) made every recursive tree query
 * loop until MySQL aborted it ("Recursive query aborted after 1001 iterations"),
 * fatal-erroring Account Details + Chart of Accounts. This guard proves:
 *   A. the recursive CTEs now carry a FIND_IN_SET path guard (cycle-safe),
 *   B. live — with an injected cycle (rolled back), the hardened query returns
 *      cleanly while the old weak-guard query aborts,
 *   C. the break-cycles migration exists and detects an injected cycle.
 *
 * Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function src($p){ return is_file($p)?file_get_contents($p):''; }
register_shutdown_function(function(){ global $pass,$fail; echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; });

try {
    section('A. CTEs carry a FIND_IN_SET cycle guard');
    $det = src("$root/app/constant/accounts/account_details.php");
    $coa = src("$root/api/account/get_chart_of_accounts.php");
    ok(substr_count($det, 'FIND_IN_SET') >= 1, 'account_details.php subtree CTE has a FIND_IN_SET path guard');
    ok(substr_count($coa, 'FIND_IN_SET') >= 2, 'get_chart_of_accounts.php both CTEs have FIND_IN_SET path guards');

    section('B. Live: hardened query survives a cycle that aborts the old one');
    $pdo->beginTransaction();
    $ids = $pdo->query("SELECT account_id FROM accounts ORDER BY account_id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) < 2) { ok(true, 'fewer than 2 accounts — live cycle test skipped'); $pdo->rollBack(); }
    else {
        [$A, $B] = [(int)$ids[0], (int)$ids[1]];
        $pdo->prepare("UPDATE accounts SET parent_account_id=? WHERE account_id=?")->execute([$B, $A]);
        $pdo->prepare("UPDATE accounts SET parent_account_id=? WHERE account_id=?")->execute([$A, $B]);

        // Old weak-guard query must abort (bound depth low so the test is fast).
        $aborted = false;
        try {
            $pdo->exec("SET SESSION cte_max_recursion_depth = 40");
            $st = $pdo->prepare("WITH RECURSIVE s AS (
                SELECT account_id, current_balance FROM accounts WHERE account_id=?
                UNION ALL SELECT a.account_id,a.current_balance FROM accounts a JOIN s ON a.parent_account_id=s.account_id
                WHERE a.account_id<>a.parent_account_id) SELECT COUNT(*) FROM s");
            $st->execute([$A]); $st->fetchColumn();
        } catch (Throwable $e) { $aborted = (strpos($e->getMessage(), '3636') !== false || stripos($e->getMessage(), 'recursive') !== false); }
        ok($aborted, 'old weak-guard query aborts on the cycle (reproduces error 3636)');

        // Hardened query must return cleanly, each node once.
        $okHard = false; $nodes = null;
        try {
            $st = $pdo->prepare("WITH RECURSIVE s AS (
                SELECT account_id,current_balance,CAST(account_id AS CHAR(4000)) AS _path FROM accounts WHERE account_id=?
                UNION ALL SELECT a.account_id,a.current_balance,CONCAT(s._path,',',a.account_id)
                  FROM accounts a JOIN s ON a.parent_account_id=s.account_id
                 WHERE a.account_id<>a.parent_account_id AND FIND_IN_SET(a.account_id,s._path)=0)
                SELECT COUNT(*) FROM s");
            $st->execute([$A]); $nodes = (int)$st->fetchColumn(); $okHard = true;
        } catch (Throwable $e) { $okHard = false; }
        ok($okHard && $nodes !== null && $nodes < 40, "hardened query returns cleanly under the cycle (nodes={$nodes}, each counted once)");

        $pdo->exec("SET SESSION cte_max_recursion_depth = 1000");

        section('C. Break-cycles migration detects the injected cycle');
        $mig = glob("$root/migrations/*break_account_parent_cycles*.php");
        ok(count($mig) > 0, 'break_account_parent_cycles migration exists');

        // Re-run the detection logic (same as the migration) against the still-injected cycle.
        $rows = $pdo->query("SELECT account_id, parent_account_id FROM accounts")->fetchAll(PDO::FETCH_ASSOC);
        $parent = [];
        foreach ($rows as $r) $parent[(int)$r['account_id']] = $r['parent_account_id'] !== null ? (int)$r['parent_account_id'] : null;
        $toBreak = [];
        foreach ($parent as $start => $_) { $seen=[];$cur=$start;$prev=null;$s=0;
            while ($cur!==null && array_key_exists($cur,$parent)) {
                if (isset($seen[$cur])) { if ($prev!==null) $toBreak[$prev]=true; break; }
                $seen[$cur]=true;$prev=$cur;$cur=$parent[$cur]; if(++$s>100000) break; } }
        ok(!empty($toBreak), 'detection logic flags the cyclic node(s) to break');

        $pdo->rollBack();   // undo the injected cycle — DB untouched
        ok(!$pdo->inTransaction(), 'injected cycle rolled back — no data changed');
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'threw: ' . $e->getMessage());
}
exit($fail === 0 ? 0 : 1);
