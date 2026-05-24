<?php
/**
 * Delivery Note — three_approval.md slice CLI smoke test
 * ------------------------------------------------------
 *   php tests/test_dn_three_approval_cli.php
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
    // Shared partials (reused from PO/SO/Invoice slices)
    'core/workflow.php',
    'includes/workflow_audit_panel.php',
    'includes/workflow_draft_watermark.php',
    // DN migration
    'migrations/2026_05_24_dn_three_approval.php',
    // DN APIs
    'api/review_dn.php',
    'api/approve_dn.php',
    'api/create_dn.php',
    'api/get_delivery_notes_list.php',
    // DN pages
    'app/bms/grn/delivery_notes.php',
    'app/bms/grn/dn_view.php',
    'app/bms/grn/dn_create.php',
    'api/account/print_delivery_note.php',
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
section('3. Workflow helper present');
// ─────────────────────────────────────────────────────────────────────────────
require_once "$root/core/workflow.php";
foreach (['canEditDocument', 'assertReviewable', 'assertApprovable', 'workflowActorSnapshot'] as $fn) {
    function_exists($fn) ? pass("core/workflow.php exposes $fn()") : fail("core/workflow.php missing $fn()");
}

// ─────────────────────────────────────────────────────────────────────────────
section('4. DB schema — deliveries');
// ─────────────────────────────────────────────────────────────────────────────
require_once "$root/includes/config.php";
global $pdo;

try {
    $col = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        fail('deliveries.status column missing');
    } else {
        if (strpos($col['Type'], "'reviewed'") !== false) pass("status ENUM contains 'reviewed'");
        else fail("status ENUM missing 'reviewed': " . $col['Type']);

        if (strpos($col['Type'], "'pending'") !== false) pass("status ENUM contains 'pending'");
        else fail("status ENUM missing 'pending': " . $col['Type']);

        if (strpos($col['Type'], "'draft'") === false) pass("status ENUM no longer contains 'draft'");
        else fail("status ENUM still contains 'draft' (should be removed)");

        if (strpos($col['Type'], "'review'") === false || strpos($col['Type'], "'reviewed'") !== false) {
            // The string 'review' is a substring of 'reviewed', so need exact-quoted match
            if (!preg_match("/'review'/", $col['Type'])) pass("status ENUM no longer contains legacy 'review' (only 'reviewed')");
            else fail("status ENUM still contains legacy 'review'");
        }

        if ($col['Default'] === 'pending') pass("status default is 'pending'");
        else fail("status default should be 'pending', got: " . var_export($col['Default'], true));
    }

    $auditCols = ['reviewed_by', 'reviewed_by_name', 'reviewed_by_role', 'reviewed_at',
                  'approved_by', 'approved_by_name', 'approved_by_role', 'approved_at'];
    foreach ($auditCols as $c) {
        $r = $pdo->query("SHOW COLUMNS FROM deliveries LIKE '$c'")->fetch(PDO::FETCH_ASSOC);
        $r ? pass("column deliveries.$c exists") : fail("column deliveries.$c missing");
    }

    $stuck = (int)$pdo->query("SELECT COUNT(*) FROM deliveries WHERE status IN ('draft','review')")->fetchColumn();
    $stuck === 0 ? pass('No rows stuck at draft/review')
                 : fail("$stuck rows still in draft/review");
} catch (PDOException $e) {
    fail("DB query failed: " . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────────
section('5. Permissions — page_key=dn');
// ─────────────────────────────────────────────────────────────────────────────
try {
    $perm = $pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'dn' LIMIT 1")->fetchColumn();
    if (!$perm) {
        fail("permissions row for page_key='dn' missing");
    } else {
        pass("permissions row for dn exists (id=$perm)");
        $rev = (int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE permission_id=$perm AND can_review=1")->fetchColumn();
        $app = (int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE permission_id=$perm AND can_approve=1")->fetchColumn();
        $rev > 0 ? pass("$rev role(s) have can_review on dn") : fail('No role has can_review on dn');
        $app > 0 ? pass("$app role(s) have can_approve on dn") : fail('No role has can_approve on dn');
    }
} catch (PDOException $e) {
    fail("Permission query failed: " . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────────
section('6. Source-code contracts');
// ─────────────────────────────────────────────────────────────────────────────
$reviewApi  = readSrc($root, 'api/review_dn.php');
$approveApi = readSrc($root, 'api/approve_dn.php');
$createApi  = readSrc($root, 'api/create_dn.php');
$listPage   = readSrc($root, 'app/bms/grn/delivery_notes.php');
$viewPage   = readSrc($root, 'app/bms/grn/dn_view.php');
$printPage  = readSrc($root, 'api/account/print_delivery_note.php');
$routes     = readSrc($root, 'roots.php');

str_contains($reviewApi, 'assertReviewable') ? pass('review_dn.php uses assertReviewable()')
                                              : fail('review_dn.php missing assertReviewable() guard');
str_contains($approveApi, 'assertApprovable') ? pass('approve_dn.php uses assertApprovable()')
                                               : fail('approve_dn.php missing assertApprovable() guard');

// Critical: approve_dn.php must still preserve the stock-reservation side-effect
str_contains($approveApi, 'reserved_quantity') ? pass('approve_dn.php preserves outbound stock-reservation side-effect')
                                                : fail('approve_dn.php missing legacy stock-reservation logic');
str_contains($approveApi, 'stock_movements')   ? pass('approve_dn.php preserves inbound stock-add side-effect (logs to stock_movements)')
                                                : fail('approve_dn.php missing legacy inbound stock-add logic');

// create_dn forces pending
str_contains($createApi, "\$status = 'pending'") ? pass("create_dn.php hard-codes status='pending' on insert")
                                                  : fail("create_dn.php should hard-code status='pending'");
!str_contains($createApi, "\$_POST['status'] ?? 'draft'") ? pass('create_dn.php no longer accepts client-supplied draft default')
                                                            : fail('create_dn.php still has \$_POST[status] ?? draft fallback');

// List page contracts
str_contains($listPage, 'DN_CAN_REVIEW')  ? pass('delivery_notes.php injects JS flag DN_CAN_REVIEW')  : fail('delivery_notes.php missing DN_CAN_REVIEW');
str_contains($listPage, 'DN_CAN_APPROVE') ? pass('delivery_notes.php injects JS flag DN_CAN_APPROVE') : fail('delivery_notes.php missing DN_CAN_APPROVE');
str_contains($listPage, 'DN_IS_ADMIN')    ? pass('delivery_notes.php injects JS flag DN_IS_ADMIN')    : fail('delivery_notes.php missing DN_IS_ADMIN');
str_contains($listPage, 'review_dn.php')  ? pass('delivery_notes.php calls new review_dn.php API')    : fail('delivery_notes.php still points at legacy review API');
str_contains($listPage, 'approve_dn.php') ? pass('delivery_notes.php calls new approve_dn.php API')   : fail('delivery_notes.php still points at legacy approve API');
str_contains($listPage, 'Mark Reviewed') && str_contains($listPage, 'Approve DN')
    ? pass('delivery_notes.php menu renders Mark Reviewed + Approve DN')
    : fail('delivery_notes.php menu missing Mark Reviewed or Approve DN');

// Filter dropdown no longer offers draft/review
!str_contains($listPage, '<option value="draft"')  ? pass('delivery_notes.php status filter no longer offers Draft')
                                                    : fail('delivery_notes.php status filter still offers Draft');
!preg_match('/<option value="review"[^a-z]/', $listPage) ? pass('delivery_notes.php status filter no longer offers legacy In Review')
                                                          : fail('delivery_notes.php status filter still offers legacy In Review');

// View page contracts
str_contains($viewPage, 'workflow_audit_panel.php') ? pass('dn_view.php includes workflow_audit_panel.php')
                                                    : fail('dn_view.php missing audit panel include');
str_contains($viewPage, 'canEditDocument(') ? pass('dn_view.php uses canEditDocument() guard')
                                            : fail('dn_view.php missing canEditDocument() guard');
str_contains($viewPage, 'markReviewedFromView') && str_contains($viewPage, 'approveDNFromView')
    ? pass('dn_view.php defines markReviewedFromView + approveDNFromView')
    : fail('dn_view.php missing review/approve handlers');

// Print page contracts
str_contains($printPage, 'workflow_draft_watermark.php') ? pass('print_delivery_note.php includes DRAFT watermark partial')
                                                          : fail('print_delivery_note.php missing DRAFT watermark partial');
preg_match('/(prepared by|created by).*reviewed by.*approved by/is', $printPage)
    ? pass('print_delivery_note.php has Prepared/Reviewed/Approved By signature block')
    : fail('print_delivery_note.php signature labels altered');

// Routes
str_contains($routes, 'api/review_dn')  ? pass('roots.php registers api/review_dn')  : fail('roots.php missing review_dn route');
str_contains($routes, 'api/approve_dn') ? pass('roots.php registers api/approve_dn') : fail('roots.php missing approve_dn route');

// ─────────────────────────────────────────────────────────────────────────────
section('7. i_e_print.md compliance for print_delivery_note.php');
// ─────────────────────────────────────────────────────────────────────────────
preg_match("/@page\\s*\\{\\s*margin:\\s*10mm\\s+8mm\\s+16mm\\s+8mm/", $printPage)
    ? pass('i_e_print rule 1 — canonical @page margins') : fail('i_e_print rule 1 — @page margins changed');
str_contains($printPage, 'print_footer_css.php')
    ? pass('i_e_print rule 2 — shared print_footer_css.php included') : fail('i_e_print rule 2 violated');
str_contains($printPage, 'print_footer_html.php')
    ? pass('i_e_print rule 3 — shared print_footer_html.php included') : fail('i_e_print rule 3 violated');
preg_match("/padding:\\s*20px\\s+20px\\s+0\\s+20px/", $printPage)
    ? pass('i_e_print rule 6 — body padding preserved') : fail('i_e_print rule 6 — body padding changed');

// ─────────────────────────────────────────────────────────────────────────────
section('8. Runtime — invoke get_delivery_notes_list.php as authenticated admin');
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

    $errFile = tempnam(sys_get_temp_dir(), 'dn_test_err_');
    $oldErrLog = ini_get('error_log');
    ini_set('log_errors', '1');
    ini_set('error_log', $errFile);

    ob_start();
    try {
        require "$root/api/get_delivery_notes_list.php";
    } catch (Throwable $e) {
        fail('get_delivery_notes_list.php threw: ' . $e->getMessage());
    }
    $rawOut = ob_get_clean();

    ini_set('error_log', $oldErrLog);
    $errLog = file_exists($errFile) ? file_get_contents($errFile) : '';
    @unlink($errFile);

    $jsonStart = strpos($rawOut, '{');
    $body      = $jsonStart === false ? $rawOut : substr($rawOut, $jsonStart);
    $resp      = json_decode($body, true);

    if (!is_array($resp)) {
        fail('get_delivery_notes_list.php did not return parseable JSON');
    } else {
        $rows = $resp['data'] ?? $resp['rows'] ?? [];
        is_array($rows) && count($rows) > 0
            ? pass('API returns ' . count($rows) . ' DN row(s)')
            : fail('API returned empty data — list page would render empty');
    }

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
    echo "\033[31m❌ DN three-approval slice has regressions.\033[0m\n";
    exit(1);
}
echo "\033[32m✅ DN three-approval slice is intact.\033[0m\n";
exit(0);
