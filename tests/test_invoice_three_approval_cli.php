<?php
/**
 * Invoice — three_approval.md slice CLI smoke test
 * ------------------------------------------------
 *   php tests/test_invoice_three_approval_cli.php
 *
 * Exit 0 = all pass (safe to push)
 * Exit 1 = failures found (push blocked)
 */

$root     = dirname(__DIR__);
$failures = 0;
$passes   = 0;

function pass(string $msg): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $msg\n"; }
function fail(string $msg): void  { global $failures; $failures++; echo "  \033[31m❌ $msg\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function readSrc(string $root, string $rel): string {
    $path = "$root/$rel";
    return file_exists($path) ? file_get_contents($path) : '';
}

// ─────────────────────────────────────────────────────────────────────────────
section('1. Required files exist');
// ─────────────────────────────────────────────────────────────────────────────
$required = [
    // Shared partials (from PO/SO slices; reused here)
    'core/workflow.php',
    'includes/workflow_audit_panel.php',
    'includes/workflow_signature_row.php',
    'includes/workflow_draft_watermark.php',
    // Invoice migration
    'migrations/2026_05_24_invoice_three_approval.php',
    // Invoice APIs
    'api/account/review_invoice.php',
    'api/account/approve_invoice.php',
    'api/account/save_invoice.php',
    'api/account/get_invoices.php',
    // Invoice pages
    'app/bms/invoice/invoices.php',
    'app/bms/invoice/invoice_view.php',
    'app/bms/invoice/invoice_print.php',
    // Spec
    'three_approval.md',
];
foreach ($required as $f) {
    file_exists("$root/$f") ? pass($f) : fail("MISSING: $f");
}

// ─────────────────────────────────────────────────────────────────────────────
section('2. PHP syntax — all touched files');
// ─────────────────────────────────────────────────────────────────────────────
foreach ($required as $f) {
    if (substr($f, -3) !== 'php') continue;
    $path = "$root/$f";
    if (!file_exists($path)) continue;
    $out = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
    if (str_contains($out, 'Parse error') || str_contains($out, 'Fatal error')) {
        fail("Syntax error in $f:\n     $out");
    } else {
        pass("Syntax OK: $f");
    }
}

// ─────────────────────────────────────────────────────────────────────────────
section('3. Workflow helper present (shared with PO/SO)');
// ─────────────────────────────────────────────────────────────────────────────
require_once "$root/core/workflow.php";
foreach (['canEditDocument', 'assertReviewable', 'assertApprovable', 'workflowActorSnapshot'] as $fn) {
    function_exists($fn) ? pass("core/workflow.php exposes $fn()") : fail("core/workflow.php missing $fn()");
}

// ─────────────────────────────────────────────────────────────────────────────
section('4. DB schema — invoices');
// ─────────────────────────────────────────────────────────────────────────────
require_once "$root/includes/config.php";
global $pdo;

try {
    $col = $pdo->query("SHOW COLUMNS FROM invoices LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        fail('invoices.status column missing');
    } else {
        // 4a. 'reviewed' present
        if (strpos($col['Type'], "'reviewed'") !== false) pass("status ENUM contains 'reviewed'");
        else fail("status ENUM missing 'reviewed': " . $col['Type']);

        // 4b. default is 'pending'
        if ($col['Default'] === 'pending') pass("status default is 'pending'");
        else fail("status default should be 'pending', got: " . var_export($col['Default'], true));
    }

    // 4c. Audit columns
    $auditCols = ['reviewed_by', 'reviewed_by_name', 'reviewed_by_role', 'reviewed_at',
                  'approved_by', 'approved_by_name', 'approved_by_role', 'approved_at'];
    foreach ($auditCols as $c) {
        $r = $pdo->query("SHOW COLUMNS FROM invoices LIKE '$c'")->fetch(PDO::FETCH_ASSOC);
        $r ? pass("column invoices.$c exists") : fail("column invoices.$c missing");
    }
} catch (PDOException $e) {
    fail("DB query failed: " . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────────
section('5. Permissions — page_key=invoices');
// ─────────────────────────────────────────────────────────────────────────────
try {
    $perm = $pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'invoices' LIMIT 1")->fetchColumn();
    if (!$perm) {
        fail("permissions row for page_key='invoices' missing");
    } else {
        pass("permissions row for invoices exists (id=$perm)");
        $rev = (int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE permission_id=$perm AND can_review=1")->fetchColumn();
        $app = (int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE permission_id=$perm AND can_approve=1")->fetchColumn();
        $rev > 0 ? pass("$rev role(s) have can_review on invoices") : fail('No role has can_review on invoices');
        $app > 0 ? pass("$app role(s) have can_approve on invoices") : fail('No role has can_approve on invoices');
    }
} catch (PDOException $e) {
    fail("Permission query failed: " . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────────
section('6. Source-code contracts');
// ─────────────────────────────────────────────────────────────────────────────
$reviewApi  = readSrc($root, 'api/account/review_invoice.php');
$approveApi = readSrc($root, 'api/account/approve_invoice.php');
$saveApi    = readSrc($root, 'api/account/save_invoice.php');
$listPage   = readSrc($root, 'app/bms/invoice/invoices.php');
$viewPage   = readSrc($root, 'app/bms/invoice/invoice_view.php');
$printPage  = readSrc($root, 'app/bms/invoice/invoice_print.php');
$routes     = readSrc($root, 'roots.php');

str_contains($reviewApi, 'assertReviewable') ? pass('review_invoice.php uses assertReviewable()')
                                              : fail('review_invoice.php missing assertReviewable() guard');
str_contains($reviewApi, "status            = 'reviewed'") || str_contains($reviewApi, "status = 'reviewed'")
    ? pass("review_invoice.php sets status='reviewed'")
    : fail("review_invoice.php should set status='reviewed'");

str_contains($approveApi, 'assertApprovable') ? pass('approve_invoice.php uses assertApprovable()')
                                               : fail('approve_invoice.php missing assertApprovable() guard');
str_contains($approveApi, "status            = 'approved'") || str_contains($approveApi, "status = 'approved'")
    ? pass("approve_invoice.php sets status='approved'")
    : fail("approve_invoice.php should set status='approved'");

if (str_contains($saveApi, "\$status = 'pending'")) {
    pass("save_invoice.php hard-codes status='pending' on insert");
} else {
    fail("save_invoice.php should hard-code status='pending' on insert");
}

// List page contracts
str_contains($listPage, 'INV_CAN_REVIEW')  ? pass('invoices.php injects JS flag INV_CAN_REVIEW')  : fail('invoices.php missing INV_CAN_REVIEW');
str_contains($listPage, 'INV_CAN_APPROVE') ? pass('invoices.php injects JS flag INV_CAN_APPROVE') : fail('invoices.php missing INV_CAN_APPROVE');
str_contains($listPage, 'INV_IS_ADMIN')    ? pass('invoices.php injects JS flag INV_IS_ADMIN')    : fail('invoices.php missing INV_IS_ADMIN');
str_contains($listPage, 'review_invoice.php')  ? pass('invoices.php calls new review_invoice.php API')  : fail('invoices.php still points at update_invoice_status.php for review');
str_contains($listPage, 'approve_invoice.php') ? pass('invoices.php calls new approve_invoice.php API') : fail('invoices.php still points at update_invoice_status.php for approve');
str_contains($listPage, 'Mark Reviewed') && str_contains($listPage, 'Approve Invoice')
    ? pass('invoices.php menu renders Mark Reviewed + Approve Invoice')
    : fail('invoices.php menu missing Mark Reviewed or Approve Invoice');

// View page contracts
str_contains($viewPage, 'workflow_audit_panel.php') ? pass('invoice_view.php includes workflow_audit_panel.php')
                                                    : fail('invoice_view.php missing audit panel include');
str_contains($viewPage, 'canEditDocument(') ? pass('invoice_view.php uses canEditDocument() guard')
                                            : fail('invoice_view.php missing canEditDocument() guard');
str_contains($viewPage, 'reviewThisInvoice') && str_contains($viewPage, 'approveThisInvoice')
    ? pass('invoice_view.php defines reviewThisInvoice + approveThisInvoice JS')
    : fail('invoice_view.php missing review/approve JS handlers');

// Print page contracts
str_contains($printPage, 'workflow_draft_watermark.php')
    ? pass('invoice_print.php includes DRAFT watermark partial')
    : fail('invoice_print.php missing DRAFT watermark partial');
preg_match('/Created By.*Reviewed By.*Approved By/s', $printPage)
    ? pass('invoice_print.php has Created/Reviewed/Approved By signature row')
    : fail('invoice_print.php signature row labels altered');

// Routes
str_contains($routes, 'review_invoice')  ? pass('roots.php registers api/review_invoice')  : fail('roots.php missing review_invoice route');
str_contains($routes, 'approve_invoice') ? pass('roots.php registers api/approve_invoice') : fail('roots.php missing approve_invoice route');

// ─────────────────────────────────────────────────────────────────────────────
section('7. i_e_print.md compliance for invoice_print.php (regression guard)');
// ─────────────────────────────────────────────────────────────────────────────
preg_match("/@page\\s*\\{\\s*margin:\\s*10mm\\s+8mm\\s+16mm\\s+8mm/", $printPage)
    ? pass('i_e_print rule 1 — canonical @page margins') : fail('i_e_print rule 1 — @page margins changed');
str_contains($printPage, 'print_footer_css.php')
    ? pass('i_e_print rule 2 — shared print_footer_css.php included') : fail('i_e_print rule 2 violated');
str_contains($printPage, 'print_footer_html.php')
    ? pass('i_e_print rule 3 — shared print_footer_html.php included') : fail('i_e_print rule 3 violated');
preg_match("/padding:\\s*20px\\s+20px\\s+0\\s+20px/", $printPage)
    ? pass('i_e_print rule 6 — body padding 20px 20px 0 20px preserved')
    : fail('i_e_print rule 6 — body padding changed');

// ─────────────────────────────────────────────────────────────────────────────
section('8. Runtime — invoke get_invoices.php as authenticated admin');
// ─────────────────────────────────────────────────────────────────────────────
try {
    $admin = $pdo->query("
        SELECT user_id, role_id, first_name, last_name, username, user_role
        FROM users
        WHERE role_id = (SELECT role_id FROM roles WHERE is_admin = 1 LIMIT 1)
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $admin = null; }

if (!$admin) {
    fail('No admin user in DB — cannot simulate API call');
} else {
    $saved_session = $_SESSION ?? [];
    $_SESSION['user_id']    = $admin['user_id'];
    $_SESSION['role_id']    = $admin['role_id'];
    $_SESSION['is_admin']   = true;
    $_SESSION['first_name'] = $admin['first_name'] ?? '';
    $_SESSION['last_name']  = $admin['last_name'] ?? '';
    $_SESSION['username']   = $admin['username']   ?? '';
    $_SESSION['user_role']  = $admin['user_role']  ?? 'Admin';

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['draw' => 1, 'start' => 0, 'length' => 25];

    $oldErrLog = ini_get('error_log');
    $errFile = tempnam(sys_get_temp_dir(), 'inv_test_err_');
    ini_set('log_errors', '1');
    ini_set('error_log', $errFile);

    ob_start();
    try {
        require "$root/api/account/get_invoices.php";
    } catch (Throwable $e) {
        fail('get_invoices.php threw: ' . $e->getMessage());
    }
    $rawOut = ob_get_clean();

    ini_set('error_log', $oldErrLog);
    $errLog = file_exists($errFile) ? file_get_contents($errFile) : '';
    @unlink($errFile);

    $jsonStart = strpos($rawOut, '{');
    $body      = $jsonStart === false ? $rawOut : substr($rawOut, $jsonStart);
    $resp      = json_decode($body, true);

    if (!is_array($resp)) {
        fail('get_invoices.php did not return parseable JSON');
    } else {
        ($resp['success'] ?? false) === true
            ? pass('API returns success=true')
            : fail('API returned success=false: ' . ($resp['message'] ?? 'no message'));

        $rows = $resp['data'] ?? $resp['invoices'] ?? [];
        is_array($rows) && count($rows) > 0
            ? pass('API returns ' . count($rows) . ' invoice row(s)')
            : fail('API returned empty data — list page would render empty');
    }

    // Filter CLI-only artifacts
    $cliArtifacts = ['headers already sent','Session ini settings cannot be changed','Session cookie parameters cannot be changed','Session cannot be started after'];
    $realIssues = [];
    if (preg_match_all('/^\[.*\] PHP (Warning|Notice|Deprecated):.*$/m', $errLog, $m)) {
        foreach ($m[0] as $line) {
            $isCli = false;
            foreach ($cliArtifacts as $p) if (strpos($line, $p) !== false) { $isCli = true; break; }
            if (!$isCli) $realIssues[] = $line;
        }
    }
    if (empty($realIssues)) {
        pass('API call produced no handler-side PHP warnings or notices');
    } else {
        foreach ($realIssues as $line) fail("PHP error during API call: $line");
    }

    $_SESSION = $saved_session;
}

// ─────────────────────────────────────────────────────────────────────────────
section('Summary');
// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
echo "  Passes:   \033[32m$passes\033[0m\n";
echo "  Failures: " . ($failures > 0 ? "\033[31m$failures\033[0m" : '0') . "\n\n";

if ($failures > 0) {
    echo "\033[31m❌ Invoice three-approval slice has regressions.\033[0m\n";
    exit(1);
}
echo "\033[32m✅ Invoice three-approval slice is intact.\033[0m\n";
exit(0);
