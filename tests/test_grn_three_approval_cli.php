<?php
/**
 * GRN — three_approval.md slice CLI smoke test
 * --------------------------------------------
 *   php tests/test_grn_three_approval_cli.php
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
    'core/workflow.php',
    'includes/workflow_audit_panel.php',
    'includes/workflow_draft_watermark.php',
    'migrations/2026_05_24_grn_three_approval.php',
    'api/review_grn.php',
    'api/approve_grn.php',
    'api/create_grn.php',
    'api/get_grns.php',
    'app/bms/grn/grn.php',
    'app/bms/grn/grn_view.php',
    'app/bms/grn/grn_print.php',
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
section('4. DB schema — purchase_receipts');
// ─────────────────────────────────────────────────────────────────────────────
require_once "$root/includes/config.php";
global $pdo;

try {
    $col = $pdo->query("SHOW COLUMNS FROM purchase_receipts LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        fail('purchase_receipts.status column missing');
    } else {
        if (strpos($col['Type'], "'reviewed'") !== false) pass("status ENUM contains 'reviewed'");
        else fail("status ENUM missing 'reviewed'");

        if (strpos($col['Type'], "'approved'") !== false) pass("status ENUM contains 'approved'");
        else fail("status ENUM missing 'approved'");

        if (strpos($col['Type'], "'pending'") !== false) pass("status ENUM contains 'pending'");
        else fail("status ENUM missing 'pending'");

        if (strpos($col['Type'], "'draft'") === false) pass("status ENUM no longer contains legacy 'draft'");
        else fail("status ENUM still contains 'draft'");

        if ($col['Default'] === 'pending') pass("status default is 'pending'");
        else fail("status default should be 'pending', got: " . var_export($col['Default'], true));
    }

    $auditCols = ['reviewed_by', 'reviewed_by_name', 'reviewed_by_role', 'reviewed_at',
                  'approved_by', 'approved_by_name', 'approved_by_role', 'approved_at'];
    foreach ($auditCols as $c) {
        $r = $pdo->query("SHOW COLUMNS FROM purchase_receipts LIKE '$c'")->fetch(PDO::FETCH_ASSOC);
        $r ? pass("column purchase_receipts.$c exists") : fail("column purchase_receipts.$c missing");
    }
} catch (PDOException $e) {
    fail("DB query failed: " . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────────
section('5. Permissions — page_key=grn');
// ─────────────────────────────────────────────────────────────────────────────
try {
    $perm = $pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'grn' LIMIT 1")->fetchColumn();
    if (!$perm) {
        fail("permissions row for page_key='grn' missing");
    } else {
        pass("permissions row for grn exists (id=$perm)");
        $rev = (int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE permission_id=$perm AND can_review=1")->fetchColumn();
        $app = (int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE permission_id=$perm AND can_approve=1")->fetchColumn();
        $rev > 0 ? pass("$rev role(s) have can_review on grn") : fail('No role has can_review on grn');
        $app > 0 ? pass("$app role(s) have can_approve on grn") : fail('No role has can_approve on grn');
    }
} catch (PDOException $e) {
    fail("Permission query failed: " . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────────
section('6. Source-code contracts');
// ─────────────────────────────────────────────────────────────────────────────
$reviewApi  = readSrc($root, 'api/review_grn.php');
$approveApi = readSrc($root, 'api/approve_grn.php');
$createApi  = readSrc($root, 'api/create_grn.php');
$listPage   = readSrc($root, 'app/bms/grn/grn.php');
$viewPage   = readSrc($root, 'app/bms/grn/grn_view.php');
$printPage  = readSrc($root, 'app/bms/grn/grn_print.php');
$routes     = readSrc($root, 'roots.php');

str_contains($reviewApi, 'assertReviewable') ? pass('review_grn.php uses assertReviewable()')
                                              : fail('review_grn.php missing assertReviewable() guard');
str_contains($approveApi, 'assertApprovable') ? pass('approve_grn.php uses assertApprovable()')
                                               : fail('approve_grn.php missing assertApprovable() guard');

// Critical: approve_grn.php must fire the stock-receipt side-effect
str_contains($approveApi, 'product_stocks')   ? pass('approve_grn.php updates product_stocks (stock side-effect preserved)')
                                                : fail('approve_grn.php missing product_stocks update');
str_contains($approveApi, 'stock_movements')  ? pass('approve_grn.php logs to stock_movements (side-effect preserved)')
                                                : fail('approve_grn.php missing stock_movements log');
str_contains($approveApi, 'stock_quantity')   ? pass('approve_grn.php bumps products.stock_quantity (side-effect preserved)')
                                                : fail('approve_grn.php missing products.stock_quantity bump');

// create_grn forces pending
str_contains($createApi, "\$status = 'pending'") ? pass("create_grn.php hard-codes status='pending' on insert")
                                                  : fail("create_grn.php should hard-code status='pending'");
preg_match('/\$updateStock\s*=\s*\(\$status\s*===\s*[\'"]completed[\'"]/', $createApi)
    ? fail('create_grn.php still has the old $updateStock = ($status === completed) line')
    : pass('create_grn.php no longer auto-updates stock on direct-to-completed creation');

// List page contracts
str_contains($listPage, 'GRN_CAN_REVIEW')  ? pass('grn.php injects JS flag GRN_CAN_REVIEW')  : fail('grn.php missing GRN_CAN_REVIEW');
str_contains($listPage, 'GRN_CAN_APPROVE') ? pass('grn.php injects JS flag GRN_CAN_APPROVE') : fail('grn.php missing GRN_CAN_APPROVE');
str_contains($listPage, 'GRN_IS_ADMIN')    ? pass('grn.php injects JS flag GRN_IS_ADMIN')    : fail('grn.php missing GRN_IS_ADMIN');
str_contains($listPage, 'review_grn.php')  ? pass('grn.php calls new review_grn.php API')    : fail('grn.php missing review_grn API call');
str_contains($listPage, 'approve_grn.php') ? pass('grn.php calls new approve_grn.php API')   : fail('grn.php missing approve_grn API call');
str_contains($listPage, 'Mark Reviewed') && str_contains($listPage, 'Approve GRN')
    ? pass('grn.php menu renders Mark Reviewed + Approve GRN')
    : fail('grn.php menu missing Mark Reviewed or Approve GRN');

// View page contracts
str_contains($viewPage, 'workflow_audit_panel.php') ? pass('grn_view.php includes workflow_audit_panel.php')
                                                    : fail('grn_view.php missing audit panel include');
str_contains($viewPage, 'canEditDocument(') ? pass('grn_view.php uses canEditDocument() guard')
                                            : fail('grn_view.php missing canEditDocument() guard');
str_contains($viewPage, 'markReviewedFromView') && str_contains($viewPage, 'approveGRNFromView')
    ? pass('grn_view.php defines markReviewedFromView + approveGRNFromView')
    : fail('grn_view.php missing review/approve handlers');

// Print page contracts
str_contains($printPage, 'workflow_draft_watermark.php')
    ? pass('grn_print.php includes DRAFT watermark partial')
    : fail('grn_print.php missing DRAFT watermark partial');

// Routes
str_contains($routes, 'api/review_grn')  ? pass('roots.php registers api/review_grn')  : fail('roots.php missing review_grn route');
str_contains($routes, 'api/approve_grn') ? pass('roots.php registers api/approve_grn') : fail('roots.php missing approve_grn route');

// ─────────────────────────────────────────────────────────────────────────────
section('7. i_e_print.md compliance for grn_print.php');
// ─────────────────────────────────────────────────────────────────────────────
preg_match("/@page\\s*\\{\\s*margin:\\s*10mm\\s+8mm\\s+16mm\\s+8mm/", $printPage)
    ? pass('i_e_print rule 1 — canonical @page margins') : fail('i_e_print rule 1 violated');
str_contains($printPage, 'print_footer_css.php')
    ? pass('i_e_print rule 2 — shared print_footer_css.php included') : fail('i_e_print rule 2 violated');
str_contains($printPage, 'print_footer_html.php')
    ? pass('i_e_print rule 3 — shared print_footer_html.php included') : fail('i_e_print rule 3 violated');
preg_match("/padding:\\s*20px\\s+20px\\s+0\\s+20px/", $printPage)
    ? pass('i_e_print rule 6 — body padding preserved') : fail('i_e_print rule 6 violated');

// ─────────────────────────────────────────────────────────────────────────────
section('8. Runtime — invoke get_grns.php as authenticated admin');
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

    $errFile = tempnam(sys_get_temp_dir(), 'grn_test_err_');
    ini_set('log_errors', '1');
    ini_set('error_log', $errFile);

    ob_start();
    try {
        require "$root/api/get_grns.php";
    } catch (Throwable $e) {
        fail('get_grns.php threw: ' . $e->getMessage());
    }
    $rawOut = ob_get_clean();

    $errLog = file_exists($errFile) ? file_get_contents($errFile) : '';
    @unlink($errFile);

    $jsonStart = strpos($rawOut, '{');
    $body      = $jsonStart === false ? $rawOut : substr($rawOut, $jsonStart);
    $resp      = json_decode($body, true);

    if (!is_array($resp)) {
        fail('get_grns.php did not return parseable JSON');
    } else {
        $rows = $resp['data'] ?? $resp['rows'] ?? [];
        is_array($rows) && count($rows) > 0
            ? pass('API returns ' . count($rows) . ' GRN row(s)')
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
    echo "\033[31m❌ GRN three-approval slice has regressions.\033[0m\n";
    exit(1);
}
echo "\033[32m✅ GRN three-approval slice is intact.\033[0m\n";
exit(0);
