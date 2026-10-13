<?php
/**
 * MM tables — desktop DataTable + mobile card view + S/No + gear Actions
 *   php tests/test_mm_tables_cards_cli.php [--dump=<dir>]
 *
 * Renders the real Mobile Money pages as admin (php-cgi, committed ZZSCOPE fixture,
 * removed in finally) and checks every list table:
 *   - first column is S/No, an Actions column (if any) uses the gear dropdown
 *   - the table sits in a table-view wrapper with a sibling card container + a JS card renderer
 *   - tables with page-built cards opt out of the global auto-cards (data-bms-cards="off")
 *   - mm_reports: totals live in <tfoot> (not sortable rows) and every row matches the header width
 * --dump writes each rendered page to <dir>/<name>.html for a browser check of the JS.
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

$dumpDir = null;
foreach ($argv as $a) if (strpos($a, '--dump=') === 0) $dumpDir = substr($a, 7);

function dom(string $html): DOMXPath
{
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    return new DOMXPath($d);
}
function cellTexts(DOMXPath $x, string $q): array
{
    $out = [];
    foreach ($x->query($q) as $n) $out[] = trim(preg_replace('/\s+/', ' ', $n->textContent));
    return $out;
}

/** Common checks for one list table. */
function checkListTable(DOMXPath $x, string $html, string $page, string $tableId, string $viewId, string $cardId, string $renderFn, bool $hasActions): void
{
    $t = $x->query("//table[@id='$tableId']")->item(0);
    ok($t !== null, "$page: #$tableId rendered");
    if (!$t) return;
    $th = cellTexts($x, "//table[@id='$tableId']/thead/tr/th");
    ok(($th[0] ?? '') === 'S/No', "$page: #$tableId first column is S/No (got '" . ($th[0] ?? '') . "')");
    if ($hasActions) {
        ok(end($th) === 'Actions', "$page: #$tableId last column is Actions");
        $rows  = $x->query("//table[@id='$tableId']/tbody/tr")->length;
        $gears = $x->query("//table[@id='$tableId']/tbody/tr/td[last()]//button[contains(@class,'dropdown-toggle')]/i[contains(@class,'bi-gear-fill')]")->length;
        ok($rows > 0 && $gears === $rows, "$page: #$tableId every row's action is a gear dropdown ($gears/$rows)");
    }
    // Either the table opts out, or the page has #cardView (which disables the global script page-wide)
    ok($t->getAttribute('data-bms-cards') === 'off' || $x->query("//*[@id='cardView']")->length === 1,
       "$page: #$tableId can't get a second (auto) card set");
    ok($x->query("//*[@id='$viewId']//table[@id='$tableId']")->length === 1, "$page: #$tableId wrapped in #$viewId");
    ok($x->query("//div[@id='$cardId']")->length === 1, "$page: card container #$cardId present");
    ok(strpos($html, "function $renderFn(") !== false && strpos($html, "$renderFn(") !== false, "$page: card renderer $renderFn() defined");
    // every body row has the same width as the header (DataTables needs this)
    $bad = 0;
    foreach ($x->query("//table[@id='$tableId']/tbody/tr") as $tr) {
        if ($x->query('td', $tr)->length !== count($th)) $bad++;
    }
    ok($bad === 0, "$page: #$tableId every row has " . count($th) . " cells");
}

function render(string $name, string $path, int $uid, array $get = []): array
{
    global $dumpDir;
    $r = mmFixtureRun($path, $uid, false, $get);
    if ($dumpDir) file_put_contents(rtrim($dumpDir, '/\\') . "/$name.html", $r['out']);
    // The CLI harness has no Host header by design, so buildUrl()'s HTTP_HOST notice is expected here only.
    $out = preg_replace('/<b>Warning<\/b>:\s+Undefined array key "HTTP_HOST"[^\n]*/', '', $r['out']);
    ok($r['location'] === null && strpos($out, 'Fatal error') === false && strpos($out, '<b>Warning</b>') === false
       && strpos($out, '<b>Notice</b>') === false && strpos($out, '<b>Deprecated</b>') === false,
       "$name: renders cleanly (no redirect / PHP error)");
    return [$r['out'], dom($r['out'])];
}

section('Lint');
foreach (['mm_transactions', 'mm_commissions', 'mm_reports', 'mm_agent_view', 'mm_compliance'] as $f) {
    $o = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/app/bms/mobile_money/$f.php") . ' 2>&1', $o, $rc);
    ok($rc === 0, "$f.php lints");
}
$js = file_get_contents("$root/assets/js/bms-mobile-cards.js");
ok(strpos($js, "getAttribute('data-bms-cards') === 'off'") !== false, 'bms-mobile-cards.js honours data-bms-cards="off"');
// Card renderers call safeOutput() but most MM pages never defined it → empty card view on phones
ok(strpos($js, "if (typeof window.safeOutput !== 'function')") !== false, 'bms-mobile-cards.js provides a global safeOutput() fallback');

$fx = null; $subId = 0; $crId = 0;
try {
    $fx  = mmFixtureCreate($pdo);
    mmFixtureSeedActivity($pdo, $fx);
    $adm = $fx['admin_id'];
    $pdo->prepare("INSERT INTO mm_agents (agent_code, agent_name, parent_agent_id, phone_primary, region, status) VALUES ('ZZSCOPE-SUB','ZZSCOPE Sub Agent',?, '255711000000','Dar','active')")
        ->execute([$fx['agents']['A']]);
    $subId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO mm_commissions_received (network_id, period_from, period_to, amount_received, reference_no, status, created_by)
                   VALUES (?, CURDATE(), CURDATE(), 1234, 'ZZSCOPE-CR', 'posted', ?)")
        ->execute([$fx['network_id'], $adm]);
    $crId = (int)$pdo->lastInsertId();

    section('mm_transactions — gear Actions instead of the lone "View" button');
    [$h, $x] = render('mm_transactions', 'app/bms/mobile_money/mm_transactions.php', $adm);
    checkListTable($x, $h, 'mm_transactions', 'txnTable', 'tableView', 'cardView', 'renderCards', true);
    ok(strpos($h, 'ZZSCOPE-TX-A1') !== false, 'mm_transactions: fixture txn listed');
    ok($x->query("//table[@id='txnTable']//td[last()]//a[contains(@href,'mm_transaction_view')][contains(@class,'dropdown-item')]")->length > 0,
       'mm_transactions: View is an item inside the gear menu');
    ok($x->query("//table[@id='txnTable']//td/a[contains(@class,'btn')]/i[contains(@class,'bi-eye')]")->length === 0,
       'mm_transactions: no standalone eye button left');

    section('mm_commissions — Earned + Received');
    [$h, $x] = render('mm_commissions', 'app/bms/mobile_money/mm_commissions.php', $adm);
    checkListTable($x, $h, 'mm_commissions', 'earnedTable', 'earnedTableView', 'earnedCardView', 'renderEarnedCards', false);
    checkListTable($x, $h, 'mm_commissions', 'receivedTable', 'receivedTableView', 'receivedCardView', 'renderReceivedCards', false);
    ok(strpos($h, "$('#earnedTable').DataTable(") !== false, 'mm_commissions: Earned is now a DataTable');
    ok(strpos($h, 'ZZSCOPE-CR') !== false, 'mm_commissions: fixture receipt listed');
    ok(strpos($h, "shown.bs.tab") !== false, 'mm_commissions: hidden-tab tables re-measured on tab show');

    section('mm_reports — every report type');
    $withTotals = ['txn_summary', 'commission', 'network_comparison'];
    foreach (['txn_summary', 'float_position', 'commission', 'agent_perf', 'shift_summary', 'void_suspicious', 'network_comparison'] as $rep) {
        [$h, $x] = render("mm_reports_$rep", 'app/bms/mobile_money/mm_reports.php', $adm, ['report' => $rep]);
        checkListTable($x, $h, "mm_reports[$rep]", 'reportTable', 'tableView', 'cardView', 'renderReportCards', false);
        $thN = $x->query("//table[@id='reportTable']/thead/tr/th")->length;
        ok($x->query("//table[@id='reportTable']/tbody/tr[td[2][normalize-space()='Total']]")->length === 0,
           "mm_reports[$rep]: no Total row inside tbody");
        if (in_array($rep, $withTotals, true)) {
            $ft = $x->query("//table[@id='reportTable']/tfoot/tr/td");
            ok($ft->length === $thN && trim($ft->item(1)->textContent) === 'Total', "mm_reports[$rep]: Total row in tfoot, $thN cells");
        }
        ok(strpos($h, "columns: ':not(:last-child)'") === false, "mm_reports[$rep]: Excel export keeps the last data column");
    }
    [$h] = render('mm_reports_void_check', 'app/bms/mobile_money/mm_reports.php', $adm, ['report' => 'void_suspicious']);
    ok(strpos($h, 'ZZSCOPE-VD-A1') !== false, 'mm_reports: fixture void row listed');

    section('mm_agent_view — Tills + Sub-Agents');
    [$h, $x] = render('mm_agent_view', 'app/bms/mobile_money/mm_agent_view.php', $adm, ['id' => $fx['agents']['A']]);
    checkListTable($x, $h, 'mm_agent_view', 'tillsTable', 'tillsTableView', 'tillsCardView', 'renderTillCards', true);
    checkListTable($x, $h, 'mm_agent_view', 'subAgentsTable', 'subAgentsTableView', 'subAgentsCardView', 'renderSubAgentCards', true);
    ok(stripos($h, 'ZZSCOPE Sub Agent') !== false, 'mm_agent_view: fixture sub-agent listed');
    ok($x->query("//table[@id='subAgentsTable']//a[contains(@class,'dropdown-item')][contains(@href,'id=$subId')]")->length === 1,
       'mm_agent_view: sub-agent View lives in the gear menu');

    section('mm_compliance — no duplicate auto-cards');
    [$h, $x] = render('mm_compliance', 'app/bms/mobile_money/mm_compliance.php', $adm);
    checkListTable($x, $h, 'mm_compliance', 'kycTable', 'kycTableView', 'kycCardView', 'renderKycCards', false);
} finally {
    if ($crId)  $pdo->prepare("DELETE FROM mm_commissions_received WHERE credit_id=?")->execute([$crId]);
    if ($subId) $pdo->prepare("DELETE FROM mm_agents WHERE agent_id=?")->execute([$subId]);
    if ($fx)    mmFixtureDestroy($pdo);
    section('Cleanup');
    ok((int)$pdo->query("SELECT COUNT(*) FROM mm_agents WHERE agent_code LIKE 'ZZSCOPE%'")->fetchColumn() === 0
       && (int)$pdo->query("SELECT COUNT(*) FROM mm_commissions_received WHERE reference_no='ZZSCOPE-CR'")->fetchColumn() === 0,
       'fixture rows removed');
}
