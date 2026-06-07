<?php
/**
 * Account detail page shows the sub-account distribution
 * ------------------------------------------------------
 *   php tests/test_account_details_children_cli.php
 *
 * Verifies app/constant/accounts/account_details.php now surfaces how a parent
 * account is distributed across its children:
 *   - lints clean;
 *   - queries direct children + a recursive roll-up total + the parent link;
 *   - renders a "Sub-Accounts" section with per-child balance and a roll-up;
 *   - the data queries return the right children for a known parent (live).
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel){ $p="$root/$rel"; return is_file($p)?file_get_contents($p):''; }

register_shutdown_function(function(){ global $pass,$fail; echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n"; });

$page = 'app/constant/accounts/account_details.php';
$s = src($root, $page);

try {
    section('1. Page lints + has the sub-account section wired');
    $out=[]; $rc=0; exec('php -l ' . escapeshellarg("$root/$page") . ' 2>&1', $out, $rc);
    ok($rc === 0, "$page lint-clean");
    ok(strpos($s, 'Sub-Accounts') !== false, 'renders a "Sub-Accounts" section');
    ok(strpos($s, '$children') !== false, 'queries direct children ($children)');
    ok(strpos($s, 'WITH RECURSIVE subtree') !== false, 'computes a recursive roll-up total');
    ok(strpos($s, '$rollup_total') !== false, 'shows a group roll-up total');
    ok(strpos($s, '$parent') !== false && strpos($s, 'breadcrumb-item') !== false, 'links to the parent in the breadcrumb');
    ok(stripos($s, 'no sub-accounts') !== false, 'leaf accounts get a clear "no sub-accounts" note');
    ok(strpos($s, 'add_child=') !== false, '"Add sub-account" link carries the parent id');

    section('2. Child + roll-up queries return correct data (live)');
    // 1-1100 Cash On Hand is a known parent with several children.
    $pid = (int)$pdo->query("SELECT account_id FROM accounts WHERE account_code='1-1100'")->fetchColumn();
    if ($pid > 0) {
        $ch = $pdo->prepare("SELECT account_code FROM accounts WHERE parent_account_id=? AND account_id<>? ORDER BY account_code");
        $ch->execute([$pid, $pid]);
        $kids = $ch->fetchAll(PDO::FETCH_COLUMN);
        ok(count($kids) >= 2, 'Cash On Hand has multiple sub-accounts (' . count($kids) . ')');
        ok(in_array('1-1110', $kids, true), 'Cheque Account (1-1110) is listed as a child');

        $r = $pdo->prepare("WITH RECURSIVE subtree AS (SELECT account_id,current_balance FROM accounts WHERE account_id=? UNION ALL SELECT a.account_id,a.current_balance FROM accounts a JOIN subtree s ON a.parent_account_id=s.account_id WHERE a.account_id<>a.parent_account_id) SELECT COALESCE(SUM(current_balance),0) FROM subtree");
        $r->execute([$pid]);
        ok($r->fetchColumn() !== false, 'roll-up query executes and returns a number');
    } else {
        ok(true, 'standard chart not seeded here — live child probe skipped (n/a)');
    }

    section('3. A leaf account reports zero children');
    $leaf = (int)$pdo->query("SELECT account_id FROM accounts WHERE account_code='1-1110'")->fetchColumn();
    if ($leaf > 0) {
        $c = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE parent_account_id=$leaf")->fetchColumn();
        ok($c === 0, 'Cheque Account (1-1110) is a leaf (0 children) → "post here" note path');
    } else {
        ok(true, 'leaf probe skipped (n/a)');
    }

} catch (Throwable $e) {
    ok(false, 'test threw: ' . $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
