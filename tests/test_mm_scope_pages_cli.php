<?php
/**
 * MM agent-scope — Phase 4: row-level scoping on every MM operational page
 *   php tests/test_mm_scope_pages_cli.php
 *
 * Runtime via php-cgi against a committed fixture (removed in finally). Each till
 * A1 A2 B1 C1 D1 carries one of every record type with code ZZSCOPE-{TX|VD|SH|FM|RC}-<till>.
 * A persona must see exactly its own tills' codes on every page — never another's.
 * Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once __DIR__ . '/mm_scope_fixture.inc.php';
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    exit($fail === 0 ? 0 : 1);
});

const ALL_TILLS = ['A1', 'A2', 'B1', 'C1', 'D1'];

/** Assert the page shows codes PREFIX-<till> for exactly $allowed tills. */
function seesExactly(string $html, string $prefix, array $allowed, string $label): void
{
    $seen = [];
    foreach (ALL_TILLS as $k) if (strpos($html, "ZZSCOPE-$prefix-$k") !== false) $seen[] = $k;
    ok($seen === array_values(array_intersect(ALL_TILLS, $allowed)),
       "$label: $prefix codes = [" . implode(',', $allowed) . "] (saw [" . implode(',', $seen) . "])");
}

section('Lint');
foreach (['mm_shifts', 'mm_shift_report', 'mm_transactions', 'mm_transaction_view', 'mm_float', 'mm_reconciliation',
          'mm_recon_view', 'mm_compliance', 'mm_commissions', 'mm_reports'] as $f) {
    $o = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/app/bms/mobile_money/$f.php") . ' 2>&1', $o, $rc);
    ok($rc === 0, "$f.php lint-clean");
}

$fx = mmFixtureCreate($pdo); $U = $fx['users']; $A = $fx['agents'];
try {
    $act = mmFixtureSeedActivity($pdo, $fx);
    // An own shift for FULL on A1 (mm_shifts lists own shifts for non-admins)
    $pdo->prepare("INSERT INTO mm_shifts (shift_code, till_id, teller_user_id, opened_at, closed_at, status) VALUES ('ZZSCOPE-OWN-A1', ?, ?, NOW(), NOW(), 'closed')")
        ->execute([$fx['tills']['A1'], $U['FULL']]);
    $ownShift = (int)$pdo->lastInsertId();
    $page = fn(string $p, int $uid, array $get = []) => mmFixtureRun("app/bms/mobile_money/$p.php", $uid, true, $get);

    $personas = ['admin' => [$fx['admin_id'], ALL_TILLS], 'FULL' => [$U['FULL'], ['A1', 'A2', 'B1']],
                 'TILL' => [$U['TILL'], ['A1']], 'DEAD' => [$U['DEAD'], ['C1']]];

    section('mm_transactions — list + form tills');
    foreach ($personas as $name => [$uid, $tills]) {
        $r = $page('mm_transactions', $uid);
        ok($r['location'] === null, "$name renders");
        seesExactly($r['out'], 'TX', $tills, "$name transactions");
    }
    $r = $page('mm_transactions', $U['DEAD']);
    ok(strpos($r['out'], 'id="newTxnModal"') === false, 'DEAD (suspended only): no New Transaction form');
    $r = $page('mm_transactions', $U['FULL']);
    ok(strpos($r['out'], 'ZZSCOPE-C1') === false && strpos($r['out'], 'ZZSCOPE-D1') === false, 'FULL: C1/D1 absent from till selects');
    ok(strpos($r['out'], 'ZZSCOPE-A3') === false, 'FULL: closed till A3 not offered');

    section('mm_transaction_view — IDOR + void');
    $r = $page('mm_transaction_view', $U['FULL'], ['id' => $act['txn']['C1']]);
    ok($r['location'] !== null && strpos($r['location'], 'mm_dashboard') !== false, 'FULL → C1 transaction redirected');
    $r = $page('mm_transaction_view', $U['FULL'], ['id' => $act['txn']['A1']]);
    ok($r['location'] === null && strpos($r['out'], 'ZZSCOPE-TX-A1') !== false, 'FULL → own A1 transaction renders');
    ok(strpos($r['out'], 'voidTransaction(') !== false, 'FULL: void offered (role + can_record)');
    $r = $page('mm_transaction_view', $U['NOREC'], ['id' => $act['txn']['A1']]);
    ok($r['location'] === null && strpos($r['out'], 'onclick="voidTransaction(') === false, 'NOREC: views A1, no void button');
    $r = $page('mm_transaction_view', $U['TILL'], ['id' => $act['txn']['A2']]);
    ok($r['location'] !== null, 'TILL → A2 transaction redirected (till-level grant)');

    section('mm_shifts + mm_shift_report');
    $r = $page('mm_shifts', $U['FULL']);
    ok(strpos($r['out'], 'ZZSCOPE-OWN-A1') !== false, 'FULL: own shift listed');
    ok(strpos($r['out'], 'ZZSCOPE-SH-C1') === false && strpos($r['out'], 'ZZSCOPE-SH-D1') === false, 'FULL: other agents\' shifts absent');
    foreach (['A1', 'A2', 'B1'] as $k) ok(strpos($r['out'], "ZZSCOPE-$k") !== false, "FULL: open-shift list offers $k");
    ok(strpos($r['out'], 'ZZSCOPE-C1') === false && strpos($r['out'], 'ZZSCOPE-A3') === false, 'FULL: C1 / closed A3 not offered');
    $r = $page('mm_shifts', $U['DEAD']);
    ok(strpos($r['out'], 'ZZSCOPE-C1') === false, 'DEAD: suspended C1 not offered for opening');
    $r = $page('mm_shift_report', $U['FULL'], ['id' => $act['shift']['C1']]);
    ok($r['location'] !== null && strpos($r['location'], 'mm_dashboard') !== false, 'FULL → C1 shift report redirected');
    $r = $page('mm_shift_report', $U['FULL'], ['id' => $act['shift']['B1']]);
    ok($r['location'] === null && strpos($r['out'], 'ZZSCOPE-SH-B1') !== false, 'FULL → B1 shift report (granted till) renders');
    $r = $page('mm_shift_report', $U['FULL'], ['id' => $ownShift]);
    ok($r['location'] === null, 'FULL → own shift report renders');

    section('mm_float');
    foreach ($personas as $name => [$uid, $tills]) {
        seesExactly($page('mm_float', $uid)['out'], 'FM', $tills, "$name float");
    }
    $r = $page('mm_float', $U['DEAD']);
    ok(strpos($r['out'], 'id="topupModal"') === false, 'DEAD: no top-up form (no live till)');
    $r = $page('mm_float', $U['NOREC']);
    ok(strpos($r['out'], 'id="topupModal"') === false, 'NOREC: no top-up form (can_record_transactions=0)');

    section('mm_reconciliation + mm_recon_view');
    foreach ($personas as $name => [$uid, $tills]) {
        seesExactly($page('mm_reconciliation', $uid)['out'], 'RC', $tills, "$name reconciliations");
    }
    $r = $page('mm_reconciliation', $U['MIXED']);
    ok(strpos($r['out'], 'editRecon(' . $act['recon']['A2'] . ',') !== false, 'MIXED: edit offered on A2 (reconcile override)');
    ok(strpos($r['out'], 'editRecon(' . $act['recon']['A1'] . ',') === false, 'MIXED: no edit on A1 (no reconcile)');
    ok(preg_match('#<tr data-id="\d+"[^<]*data-can-rec="[01]">#', $r['out']) === 1, '<tr> tag now closed properly');
    $r = $page('mm_reconciliation', $U['TILL']);
    ok(strpos($r['out'], 'data-bs-target="#startReconModal"') === false && strpos($r['out'], 'editRecon(' . $act['recon']['A1'] . ',') === false,
       'TILL (no reconcile): no create, no edit');
    $r = $page('mm_recon_view', $U['FULL'], ['id' => $act['recon']['C1']]);
    ok($r['location'] !== null && strpos($r['location'], 'mm_dashboard') !== false, 'FULL → C1 recon view redirected');
    $r = $page('mm_recon_view', $U['FULL'], ['id' => $act['recon']['A1']]);
    ok($r['location'] === null && strpos($r['out'], 'id="resolveModal"') !== false, 'FULL → A1 recon with resolve');
    $r = $page('mm_recon_view', $U['TILL'], ['id' => $act['recon']['A1']]);
    ok($r['location'] === null && strpos($r['out'], 'id="resolveModal"') === false, 'TILL → A1 recon read-only');

    section('mm_compliance');
    foreach ($personas as $name => [$uid, $tills]) {
        $html = $page('mm_compliance', $uid)['out'];
        seesExactly($html, 'TX', $tills, "$name KYC/pending");
    }

    section('mm_commissions');
    $earned = fn(string $h) => preg_match('#fs-4 fw-bold text-primary">([\d,.]+)</div>\s*<div class="small text-muted">Total Earned#', $h, $m) ? $m[1] : null;
    $r = $page('mm_commissions', $fx['admin_id']);
    ok($earned($r['out']) === '155', 'admin earned = 155 (got ' . var_export($earned($r['out']), true) . ')');
    ok(strpos($r['out'], 'Total Received') !== false && strpos($r['out'], 'href="#receivedTab"') !== false, 'admin sees Received');
    $r = $page('mm_commissions', $U['FULL']);
    ok($earned($r['out']) === '35', 'FULL earned = 35 (got ' . var_export($earned($r['out']), true) . ')');
    ok(strpos($r['out'], 'Total Received') === false && strpos($r['out'], 'href="#receivedTab"') === false, 'FULL: Received hidden (D3)');
    ok(strpos($r['out'], 'data-bs-target="#receiveModal"') === false, 'FULL: no Record Received button');

    section('mm_reports — all 7');
    $rep = fn(int $uid, string $tab, array $extra = []) => $page('mm_reports', $uid, array_merge(['report' => $tab], $extra));
    ok(strpos($rep($fx['admin_id'], 'txn_summary')['out'], '15,500') !== false, 'admin txn_summary volume 15,500');
    $h = $rep($U['FULL'], 'txn_summary')['out'];
    ok(strpos($h, '3,500') !== false && strpos($h, '15,500') === false, 'FULL txn_summary volume 3,500');
    $h = $rep($U['FULL'], 'network_comparison')['out'];
    ok(strpos($h, '3,500') !== false && strpos($h, '15,500') === false, 'FULL network_comparison 3,500');
    $h = $rep($U['FULL'], 'agent_perf')['out'];
    ok(strpos($h, 'ZZSCOPE Agent A') !== false && strpos($h, 'ZZSCOPE Agent B') !== false
       && strpos($h, 'ZZSCOPE Agent C') === false, 'FULL agent_perf: A,B only');
    seesExactly($rep($U['FULL'], 'shift_summary')['out'], 'SH', ['A1', 'A2', 'B1'], 'FULL shift_summary');
    seesExactly($rep($U['FULL'], 'void_suspicious')['out'], 'VD', ['A1', 'A2', 'B1'], 'FULL void_suspicious');
    $h = $rep($U['FULL'], 'float_position')['out'];
    ok(strpos($h, 'ZZSCOPE-A1') !== false && strpos($h, 'ZZSCOPE-C1') === false && strpos($h, 'ZZSCOPE-D1') === false, 'FULL float_position: own tills only');
    $h = $rep($U['FULL'], 'commission')['out'];
    ok(strpos($h, 'Received (TZS)') === false, 'FULL commission report: Received column hidden');
    ok(strpos($rep($fx['admin_id'], 'commission')['out'], 'Received (TZS)') !== false, 'admin commission report: Received column shown');
    seesExactly($rep($U['TILL'], 'void_suspicious')['out'], 'VD', ['A1'], 'TILL void_suspicious');
    $r = $rep($U['FULL'], 'txn_summary', ['agent_id' => $A['C']]);
    ok($r['location'] !== null && strpos($r['location'], 'mm_dashboard') !== false, 'FULL ?agent_id=C (not granted) → redirected');
    $r = $rep($U['FULL'], 'txn_summary', ['agent_id' => $A['A']]);
    ok($r['location'] === null, 'FULL ?agent_id=A renders');
    $h = $rep($U['FULL'], 'txn_summary')['out'];
    ok(strpos($h, 'ZZSCOPE Agent C') === false && strpos($h, 'ZZSCOPE Agent A') !== false, 'agent filter lists granted agents only');
} finally {
    mmFixtureDestroy($pdo);
    echo "  (runtime fixture removed)\n";
}
$left = (int)$pdo->query("SELECT COUNT(*) FROM mm_transactions WHERE txn_code LIKE 'ZZSCOPE%'")->fetchColumn()
      + (int)$pdo->query("SELECT COUNT(*) FROM mm_reconciliations WHERE recon_code LIKE 'ZZSCOPE%'")->fetchColumn()
      + (int)$pdo->query("SELECT COUNT(*) FROM mm_float_movements WHERE movement_code LIKE 'ZZSCOPE%'")->fetchColumn()
      + (int)$pdo->query("SELECT COUNT(*) FROM mm_shifts WHERE shift_code LIKE 'ZZSCOPE%'")->fetchColumn()
      + (int)$pdo->query("SELECT COUNT(*) FROM users WHERE username LIKE 'zzscope\\_%'")->fetchColumn();
ok($left === 0, 'no fixture rows left behind');
