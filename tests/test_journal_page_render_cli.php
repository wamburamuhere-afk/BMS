<?php
/**
 * tests/test_journal_page_render_cli.php
 * Renders the General Journal list AND a journal-details view against the live DB,
 * asserting both load with NO error and that the UX fixes are in place:
 *   - S/NO column + full-width list
 *   - SweetAlert (no native confirm) + clean (no-.php) AJAX routes
 *   - details page: AJAX reverse/void (no raw-JSON form post), getUrl trial-balance,
 *     single print header (no duplicated company logo/name).
 *
 *   php tests/test_journal_page_render_cli.php
 */
$root = dirname(__DIR__);

// ── workers: render a page and print its HTML ───────────────────────────────
if (($argv[1] ?? '') === 'list' || ($argv[1] ?? '') === 'view') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1; $_SESSION['role'] = 'admin';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    if ($argv[1] === 'list') { $_SERVER['REQUEST_URI'] = '/journals'; require "$root/app/constant/accounts/journals.php"; }
    else { $_GET['id'] = (int)($argv[2] ?? 0); $_SERVER['REQUEST_URI'] = '/journal/view'; require "$root/app/constant/accounts/journal_details.php"; }
    exit;
}

require_once "$root/roots.php";
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function render($root, $mode, $id = 0) { return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " $mode $id 2>&1"); }
function noErr($html) { foreach (['Fatal error','Parse error','Uncaught','Unknown column','SQLSTATE','Call to a member function'] as $e) if (stripos($html,$e)!==false) return false; return true; }

$listSrc = file_get_contents("$root/app/constant/accounts/journals.php");
$viewSrc = file_get_contents("$root/app/constant/accounts/journal_details.php");

// ── 1. lint ─────────────────────────────────────────────────────────────────
foreach (['app/constant/accounts/journals.php','app/constant/accounts/journal_details.php','app/constant/accounts/add_journal.php'] as $f) {
    $rc=0; exec("php -l ".escapeshellarg("$root/$f")." 2>&1",$o,$rc); ok($rc===0,"lint $f");
}

// ── 2. list-page source fixes ───────────────────────────────────────────────
ok(strpos($listSrc,'S/NO')!==false, 'list: S/NO column header present');
ok(strpos($listSrc,'container-fluid')!==false, 'list: full-width container');
ok(strpos($listSrc,'_iDisplayStart')!==false, 'list: S/NO uses running row number');
ok(strpos($listSrc,'Swal.fire')!==false, 'list: uses SweetAlert');
ok(strpos($listSrc,'!confirm(')===false, 'list: no native confirm()');
ok(strpos($listSrc,'buildUrl("api/reverse_journal")')!==false, 'list: clean (no-.php) reverse route');

// ── 3. details-page source fixes ────────────────────────────────────────────
ok(strpos($viewSrc,'/api/reverse_journal.php')===false, 'view: no raw form post to /api/reverse_journal.php');
ok(strpos($viewSrc,'/api/void_journal.php')===false, 'view: no raw form post to /api/void_journal.php');
ok(strpos($viewSrc,'onclick="reverseEntry()"')!==false, 'view: AJAX reverse button');
ok(strpos($viewSrc,'onclick="voidEntry()"')!==false, 'view: AJAX void button');
ok(strpos($viewSrc,'Swal.fire')!==false, 'view: uses SweetAlert');
ok(strpos($viewSrc,'confirmJournalAction')===false, 'view: native confirm helper removed');
ok(strpos($viewSrc,"getUrl('trial_balance')")!==false, 'view: trial-balance link uses route (no 404)');
ok(strpos($viewSrc,'$c_name')===false, 'view: in-file company name removed (single print header)');
ok(strpos($viewSrc,'buildUrl("api/reverse_journal")')!==false, 'view: clean (no-.php) reverse route');

// ── 4. list renders cleanly ─────────────────────────────────────────────────
$listHtml = render($root, 'list');
ok(strlen($listHtml) > 500, 'list rendered (' . strlen($listHtml) . ' bytes)');
ok(noErr($listHtml), 'list: no fatal/parse/SQL error in render');
foreach (['General Journal'=>'title','journalsTable'=>'table','addJournalModal'=>'modal','S/NO'=>'S/NO column','get_journals'=>'list AJAX'] as $n=>$l)
    ok(strpos($listHtml,$n)!==false, "list renders: $l");

// ── 5. details renders cleanly (seed a posted journal, render, clean up) ─────
$acc = $pdo->query("SELECT account_id FROM accounts WHERE status='active' ORDER BY account_id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
$ref = 'TEST-VIEW-' . date('YmdHis');
$pdo->prepare("INSERT INTO journal_entries (entry_date, reference_number, description, status, created_by, debit_account_id, credit_account_id, amount, created_at)
               VALUES (?,?,?,'posted',4,0,0,500,NOW())")->execute([date('Y-m-d'), $ref, 'CLI view test']);
$vid = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO journal_entry_items (entry_id,account_id,type,amount,description) VALUES (?,?,'debit',500,'t'),(?,?,'credit',500,'t')")
    ->execute([$vid,$acc[0],$vid,$acc[1]]);

$viewHtml = render($root, 'view', $vid);
ok(strlen($viewHtml) > 500, 'details rendered (' . strlen($viewHtml) . ' bytes)');
ok(noErr($viewHtml), 'details: no fatal/parse/SQL error in render');
ok(strpos($viewHtml,'Ledger Entries')!==false, 'details renders the ledger table');
ok(strpos($viewHtml,'reverseEntry')!==false, 'details renders the AJAX reverse action');
ok(substr_count($viewHtml, 'Journal Entry Report') <= 1, 'details: single journal print header (no duplicate)');

$pdo->prepare("DELETE FROM journal_entry_items WHERE entry_id=?")->execute([$vid]);
$pdo->prepare("DELETE FROM journal_entries WHERE entry_id=?")->execute([$vid]);
ok((int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE reference_number LIKE 'TEST-VIEW-%'")->fetchColumn()===0, 'cleanup removed the test view journal');

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
