<?php
/**
 * tests/test_journal_page_render_cli.php
 * Renders the REAL General Journal page (app/constant/accounts/journals.php) in a
 * subprocess with an admin session and asserts it loads with NO error (fatal /
 * parse / uncaught / SQL), with all its key parts present, and that every endpoint
 * the page calls actually exists. Answers: "is the page working, or is there an error?"
 *
 *   php tests/test_journal_page_render_cli.php
 */
$root = dirname(__DIR__);

// ── worker: render the page and print its HTML ──────────────────────────────
if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1; $_SESSION['role'] = 'admin';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/journals';
    require "$root/app/constant/accounts/journals.php";
    exit;
}

require_once "$root/roots.php";
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }

// 1) Lint the page + modal.
foreach (['app/constant/accounts/journals.php', 'app/constant/accounts/add_journal.php'] as $f) {
    $rc = 0; exec("php -l " . escapeshellarg("$root/$f") . " 2>&1", $o, $rc);
    ok($rc === 0, "lint $f");
}

// 2) Every endpoint the page calls must exist.
$endpoints = [
    'api/account/get_journals.php', 'api/account/save_journal.php', 'api/account/search_accounts.php',
    'api/account/reverse_journal.php', 'api/account/delete_journal.php',
    'api/account/void_journal.php', 'api/account/update_journal_status.php', 'api/account/export_journals.php',
];
foreach ($endpoints as $ep) ok(file_exists("$root/$ep"), "endpoint exists: $ep");

// 3) Render the page in a subprocess and inspect the HTML.
$html = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker 2>&1');
ok(strlen($html) > 500, 'page rendered (' . strlen($html) . ' bytes)');

// No error of any severity should leak into the page output.
foreach (['Fatal error', 'Parse error', 'Uncaught', 'Unknown column', 'SQLSTATE', 'Call to a member function'] as $err) {
    ok(stripos($html, $err) === false, "no '$err' in rendered page");
}

// Key parts of the page are present.
$markers = [
    'General Journal'        => 'page title',
    'journalsTable'          => 'entries DataTable',
    'addJournalModal'        => 'New Entry modal included',
    'DEBIT ACCOUNTS'         => 'debit lines section',
    'CREDIT ACCOUNTS'        => 'credit lines section',
    'get_journals'           => 'list AJAX wired to get_journals',
    'search_accounts'        => 'account picker wired to search_accounts',
    'saveJournalBtn'         => 'balance-gated Save button',
];
foreach ($markers as $needle => $label) {
    ok(strpos($html, $needle) !== false, "renders: $label");
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
