<?php
/**
 * Sales Order — three_approval.md slice CLI smoke test
 * ----------------------------------------------------
 * Verifies that the migrations + code edits applied in updates 69-71 left
 * the SO module in a working state.
 *
 *   php tests/test_sales_order_three_approval_cli.php
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
    // Shared partials (created in update 68; reused by SO)
    'core/workflow.php',
    'includes/workflow_audit_panel.php',
    'includes/workflow_signature_row.php',
    'includes/workflow_draft_watermark.php',
    // SO migrations
    'migrations/2026_05_24_so_three_approval.php',
    'migrations/2026_05_24_so_remove_draft.php',
    // SO APIs
    'api/account/review_sales_order.php',
    'api/account/approve_sales_order.php',
    'api/account/save_sales_order.php',
    'api/account/get_sales_orders.php',
    // SO pages
    'app/bms/sales/sales_orders.php',
    'app/bms/sales/sales_order_create.php',
    'app/bms/sales/sales_order_edit.php',
    'app/bms/sales/sales_order_view.php',
    'app/bms/sales/print_sales_order.php',
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
section('3. Workflow helper exposes the required functions');
// ─────────────────────────────────────────────────────────────────────────────
require_once "$root/core/workflow.php";
foreach (['canEditDocument', 'assertReviewable', 'assertApprovable', 'assertConvertible', 'workflowActorSnapshot', 'statusBadgeClass'] as $fn) {
    function_exists($fn) ? pass("core/workflow.php exposes $fn()") : fail("core/workflow.php missing $fn()");
}

// Behaviour checks on the guards
try { assertReviewable('pending'); pass('assertReviewable("pending") accepts'); }
catch (Throwable $e) { fail('assertReviewable("pending") should accept but threw: ' . $e->getMessage()); }

try { assertReviewable('reviewed'); fail('assertReviewable("reviewed") should have thrown'); }
catch (Throwable $e) { pass('assertReviewable("reviewed") rejects'); }

try { assertApprovable('reviewed'); pass('assertApprovable("reviewed") accepts'); }
catch (Throwable $e) { fail('assertApprovable("reviewed") should accept but threw: ' . $e->getMessage()); }

try { assertApprovable('pending'); fail('assertApprovable("pending") should have thrown'); }
catch (Throwable $e) { pass('assertApprovable("pending") rejects (sequence enforced)'); }

try { assertConvertible('approved'); pass('assertConvertible("approved") accepts'); }
catch (Throwable $e) { fail('assertConvertible("approved") should accept but threw: ' . $e->getMessage()); }

try { assertConvertible('reviewed'); fail('assertConvertible("reviewed") should have thrown'); }
catch (Throwable $e) { pass('assertConvertible("reviewed") rejects'); }

// canEditDocument: admin always wins; non-admin blocked when approved
if (canEditDocument('approved', true)  === true)  pass('canEditDocument(approved, admin)   → true');  else fail('canEditDocument(approved, admin) wrong');
if (canEditDocument('approved', false) === false) pass('canEditDocument(approved, !admin)  → false'); else fail('canEditDocument(approved, !admin) wrong');
if (canEditDocument('pending',  false) === true)  pass('canEditDocument(pending,  !admin)  → true');  else fail('canEditDocument(pending,  !admin) wrong');

// ─────────────────────────────────────────────────────────────────────────────
section('4. DB schema — sales_orders');
// ─────────────────────────────────────────────────────────────────────────────
require_once "$root/includes/config.php";
global $pdo;

try {
    $col = $pdo->query("SHOW COLUMNS FROM sales_orders LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        fail('sales_orders.status column missing');
    } else {
        // 4a. 'reviewed' present
        if (strpos($col['Type'], "'reviewed'") !== false) pass("status ENUM contains 'reviewed'");
        else fail("status ENUM missing 'reviewed': " . $col['Type']);

        // 4b. 'draft' removed
        if (strpos($col['Type'], "'draft'") === false) pass("status ENUM no longer contains 'draft'");
        else fail("status ENUM still contains 'draft' (should have been removed): " . $col['Type']);

        // 4c. default is 'pending'
        if ($col['Default'] === 'pending') pass("status default is 'pending'");
        else fail("status default should be 'pending', got: " . var_export($col['Default'], true));
    }

    // 4d. Audit columns
    $auditCols = ['reviewed_by', 'reviewed_by_name', 'reviewed_by_role', 'reviewed_at',
                  'approved_by', 'approved_by_name', 'approved_by_role', 'approved_at'];
    foreach ($auditCols as $c) {
        $r = $pdo->query("SHOW COLUMNS FROM sales_orders LIKE '$c'")->fetch(PDO::FETCH_ASSOC);
        $r ? pass("column sales_orders.$c exists") : fail("column sales_orders.$c missing");
    }

    // 4e. No row should currently be in the removed 'draft' state
    $stuck = (int)$pdo->query("SELECT COUNT(*) FROM sales_orders WHERE status = 'draft'")->fetchColumn();
    $stuck === 0 ? pass('No sales_orders rows still in status=draft')
                 : fail("$stuck sales_orders row(s) still in status=draft — migration must promote them");

} catch (PDOException $e) {
    fail("DB query failed: " . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────────
section('5. Permissions — page_key=sales_orders');
// ─────────────────────────────────────────────────────────────────────────────
try {
    $perm = $pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'sales_orders' LIMIT 1")->fetchColumn();
    if (!$perm) {
        fail("permissions row for page_key='sales_orders' missing");
    } else {
        pass("permissions row for sales_orders exists (id=$perm)");

        // 5a. At least one role has can_review + can_approve
        $rev = (int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE permission_id=$perm AND can_review=1")->fetchColumn();
        $app = (int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE permission_id=$perm AND can_approve=1")->fetchColumn();
        $rev > 0 ? pass("$rev role(s) have can_review on sales_orders") : fail('No role has can_review on sales_orders');
        $app > 0 ? pass("$app role(s) have can_approve on sales_orders") : fail('No role has can_approve on sales_orders');
    }
} catch (PDOException $e) {
    fail("Permission query failed: " . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────────
section('6. Source-code contracts');
// ─────────────────────────────────────────────────────────────────────────────

// 6a. Review API guards on 'pending'/'draft' via assertReviewable
$reviewApi = readSrc($root, 'api/account/review_sales_order.php');
str_contains($reviewApi, 'assertReviewable') ? pass('review_sales_order.php uses assertReviewable()')
                                              : fail('review_sales_order.php missing assertReviewable() guard');
str_contains($reviewApi, "status            = 'reviewed'") || str_contains($reviewApi, "status = 'reviewed'")
    ? pass("review_sales_order.php sets status='reviewed'")
    : fail("review_sales_order.php should set status='reviewed'");

// 6b. Approve API guards via assertApprovable
$approveApi = readSrc($root, 'api/account/approve_sales_order.php');
str_contains($approveApi, 'assertApprovable') ? pass('approve_sales_order.php uses assertApprovable()')
                                              : fail('approve_sales_order.php missing assertApprovable() guard');
str_contains($approveApi, "status            = 'approved'") || str_contains($approveApi, "status = 'approved'")
    ? pass("approve_sales_order.php sets status='approved'")
    : fail("approve_sales_order.php should set status='approved'");

// 6c. save_sales_order.php forces 'pending' on insert
$saveApi = readSrc($root, 'api/account/save_sales_order.php');
if (str_contains($saveApi, "\$status = 'pending'")) {
    pass("save_sales_order.php hard-codes status='pending' on insert");
} else {
    fail("save_sales_order.php should hard-code status='pending' on insert");
}
if (!str_contains($saveApi, "\$_POST['status'] ?? 'draft'")) {
    pass("save_sales_order.php no longer accepts client-supplied 'draft' default");
} else {
    fail("save_sales_order.php still has \$_POST['status'] ?? 'draft' fallback");
}

// 6d. Create page no longer offers Save-as-Draft
$createPage = readSrc($root, 'app/bms/sales/sales_order_create.php');
!str_contains($createPage, "onclick=\"saveAsDraft()") ? pass('sales_order_create.php no Save-as-Draft button')
                                                       : fail('sales_order_create.php still has Save-as-Draft button');
!str_contains($createPage, "onclick=\"createAndApprove()") ? pass('sales_order_create.php no Create-&-Approve button')
                                                            : fail('sales_order_create.php still has Create-&-Approve button');
!preg_match("/function\\s+saveAsDraft\\s*\\(/", $createPage) ? pass('sales_order_create.php no saveAsDraft() JS handler')
                                                              : fail('sales_order_create.php still defines saveAsDraft()');

// 6e. Edit page same
$editPage = readSrc($root, 'app/bms/sales/sales_order_edit.php');
!str_contains($editPage, "onclick=\"saveAsDraft()") ? pass('sales_order_edit.php no Save-as-Draft button')
                                                     : fail('sales_order_edit.php still has Save-as-Draft button');
!str_contains($editPage, "onclick=\"createAndApprove()") ? pass('sales_order_edit.php no Create-&-Approve button')
                                                          : fail('sales_order_edit.php still has Create-&-Approve button');

// 6f. List page: Reviewed filter + no Draft filter
$listPage = readSrc($root, 'app/bms/sales/sales_orders.php');
str_contains($listPage, '<option value="reviewed"') ? pass('sales_orders.php status filter includes Reviewed')
                                                     : fail('sales_orders.php status filter missing Reviewed');
!str_contains($listPage, '<option value="draft"')   ? pass('sales_orders.php status filter no longer offers Draft')
                                                     : fail('sales_orders.php status filter still offers Draft');

// 6g. List page: parallel review/approve actions in JS
str_contains($listPage, 'Mark Reviewed') && str_contains($listPage, 'Approve Order')
    ? pass('sales_orders.php JS menu renders Mark Reviewed + Approve Order')
    : fail('sales_orders.php JS menu missing Mark Reviewed or Approve Order');

// Change-Status entry must be gone from the dropdown menu
preg_match("/onclick=\"changeOrderStatus\\(/", $listPage)
    ? fail('sales_orders.php still has Change Status menu item — should be removed')
    : pass('sales_orders.php Change Status menu item removed');

// JS capability flags injected
foreach (['SO_CAN_REVIEW', 'SO_CAN_APPROVE', 'SO_IS_ADMIN'] as $flag) {
    str_contains($listPage, "const $flag")
        ? pass("sales_orders.php injects JS flag $flag")
        : fail("sales_orders.php missing JS flag $flag");
}

// 6h. View page: audit panel + sequential buttons + edit gating
$viewPage = readSrc($root, 'app/bms/sales/sales_order_view.php');
str_contains($viewPage, "includes/workflow_audit_panel.php")
    ? pass('sales_order_view.php includes workflow_audit_panel.php')
    : fail('sales_order_view.php must include workflow_audit_panel.php');
str_contains($viewPage, 'canEditDocument(')
    ? pass('sales_order_view.php uses canEditDocument() guard')
    : fail('sales_order_view.php missing canEditDocument() guard');
str_contains($viewPage, "reviewThisOrder") && str_contains($viewPage, 'approveThisOrder')
    ? pass('sales_order_view.php defines reviewThisOrder + approveThisOrder JS')
    : fail('sales_order_view.php missing review/approve JS handlers');

// 6i. Print page: watermark + canonical signature row + small CSS rule
$printPage = readSrc($root, 'app/bms/sales/print_sales_order.php');
str_contains($printPage, 'workflow_draft_watermark.php')
    ? pass('print_sales_order.php includes workflow_draft_watermark.php')
    : fail('print_sales_order.php missing DRAFT watermark partial');
str_contains($printPage, 'workflow_signature_row.php')
    ? pass('print_sales_order.php includes workflow_signature_row.php')
    : fail('print_sales_order.php missing canonical signature row partial');
str_contains($printPage, '.signature-line small')
    ? pass('print_sales_order.php has .signature-line small CSS rule (i_e_print.md §6.3)')
    : fail('print_sales_order.php missing .signature-line small CSS rule');

// 6j. Routes registered
$routes = readSrc($root, 'roots.php');
str_contains($routes, "review_sales_order")  ? pass("roots.php registers api/review_sales_order")  : fail('roots.php missing review_sales_order route');
str_contains($routes, "approve_sales_order") ? pass("roots.php registers api/approve_sales_order") : fail('roots.php missing approve_sales_order route');

// ─────────────────────────────────────────────────────────────────────────────
section('7. i_e_print.md compliance for print_sales_order.php (regression guard)');
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
section('8. Runtime — invoke get_sales_orders.php as authenticated admin');
// ─────────────────────────────────────────────────────────────────────────────
// This catches the kind of regression a structural test misses:
// undefined array keys, broken stats keys, fatal errors in the handler, etc.
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
    // Save current session state so we can restore it
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

    // Capture stdout AND PHP warnings (warnings go to error_log by default; route to a string)
    $oldErrLog = ini_get('error_log');
    $errFile = tempnam(sys_get_temp_dir(), 'so_test_err_');
    ini_set('log_errors', '1');
    ini_set('error_log', $errFile);

    ob_start();
    try {
        require "$root/api/account/get_sales_orders.php";
    } catch (Throwable $e) {
        fail('get_sales_orders.php threw: ' . $e->getMessage());
    }
    $rawOut = ob_get_clean();

    ini_set('error_log', $oldErrLog);
    $errLog = file_exists($errFile) ? file_get_contents($errFile) : '';
    @unlink($errFile);

    // Extract JSON from output (strip any preamble warnings printed by display_errors)
    $jsonStart = strpos($rawOut, '{');
    $body      = $jsonStart === false ? $rawOut : substr($rawOut, $jsonStart);
    $resp      = json_decode($body, true);

    if (!is_array($resp)) {
        fail('get_sales_orders.php did not return parseable JSON');
    } else {
        ($resp['success'] ?? false) === true
            ? pass('API returns success=true')
            : fail('API returned success=false: ' . ($resp['message'] ?? 'no message'));

        $rows = $resp['data'] ?? [];
        is_array($rows) && count($rows) > 0
            ? pass('API returns ' . count($rows) . ' data row(s)')
            : fail('API returned empty data array — the list page would render empty');

        $total = (int)($resp['recordsTotal'] ?? 0);
        $total > 0
            ? pass("API recordsTotal=$total")
            : fail('API recordsTotal=0 — DataTables would show "No data"');

        $stats = $resp['stats'] ?? [];
        $expectedStatKeys = ['total_orders','total_value','pending_count','reviewed_count','approved_count','processing_count','delivered_count','partially_delivered_count'];
        foreach ($expectedStatKeys as $k) {
            array_key_exists($k, $stats)
                ? pass("API stats includes $k")
                : fail("API stats missing $k");
        }
        !array_key_exists('draft_count', $stats)
            ? pass('API stats no longer exposes draft_count (legacy)')
            : fail('API stats still exposes draft_count — legacy key not cleaned up');

        if (!empty($rows)) {
            $expectedRowKeys = ['sales_order_id','order_number','status','grand_total','customer_name','display_status','order_type','total_items'];
            $missing = array_diff($expectedRowKeys, array_keys($rows[0]));
            empty($missing)
                ? pass('First row has all DataTable-expected keys')
                : fail('Row keys missing for DataTable: ' . implode(', ', $missing));
        }
    }

    // Surface PHP warnings/notices that came out of the handler — but ignore
    // CLI-only artifacts that never trigger when served by Apache:
    //   - "headers already sent" (the test itself echoes before roots.php loads)
    //   - "ini_set()/session_*() after headers" (same root cause)
    $cliArtifactPatterns = [
        'headers already sent',
        'Session ini settings cannot be changed',
        'Session cookie parameters cannot be changed',
        'Session cannot be started after',
    ];
    $realIssues = [];
    if (preg_match_all('/^\[.*\] PHP (Warning|Notice|Deprecated):.*$/m', $errLog, $m)) {
        foreach ($m[0] as $line) {
            $isCli = false;
            foreach ($cliArtifactPatterns as $p) if (strpos($line, $p) !== false) { $isCli = true; break; }
            if (!$isCli) $realIssues[] = $line;
        }
    }
    if (empty($realIssues)) {
        pass('API call produced no handler-side PHP warnings or notices');
    } else {
        foreach ($realIssues as $line) fail("PHP error during API call: $line");
    }

    // Restore session
    $_SESSION = $saved_session;
}

// ─────────────────────────────────────────────────────────────────────────────
section('Summary');
// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
echo "  Passes:   \033[32m$passes\033[0m\n";
echo "  Failures: " . ($failures > 0 ? "\033[31m$failures\033[0m" : '0') . "\n\n";

if ($failures > 0) {
    echo "\033[31m❌ Sales Order three-approval slice has regressions.\033[0m\n";
    exit(1);
}
echo "\033[32m✅ Sales Order three-approval slice is intact.\033[0m\n";
exit(0);
