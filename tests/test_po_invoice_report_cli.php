<?php
/**
 * PO vs Invoice Report — CLI Test Suite
 * Locks in the fixes from update 130:
 *   - .fail() handler on AJAX so failures are visible
 *   - ≤1 TZS tolerance for "Fully Billed" (no strict === on floats)
 *   - SQL HAVING clause for status filter (not post-fetch PHP)
 *   - Project-scope filter via scopeFilterSqlNullable('project','po')
 *   - getFilterSummary() empty-state helper that explains the cause
 *
 * Exit 0 = all pass | Exit 1 = one or more failures (blocks git push)
 */

$ROOT = dirname(__DIR__);
$pass = 0;
$fail = 0;

function readSrc(string $path): string {
    global $ROOT;
    $full = $ROOT . '/' . ltrim($path, '/');
    return file_exists($full) ? file_get_contents($full) : '';
}
function ok(string $msg): void   { global $pass; $pass++; echo "\033[32m  OK\033[0m $msg\n"; }
function fail(string $msg): void { global $fail; $fail++; echo "\033[31m  XX\033[0m $msg\n"; }
function section(string $t): void { echo "\n\033[1m-- $t --\033[0m\n"; }
function has(string $src, string $needle, string $label): void {
    strpos($src, $needle) !== false ? ok($label) : fail($label);
}
function hasNot(string $src, string $needle, string $label): void {
    strpos($src, $needle) === false ? ok($label) : fail($label);
}
function fileSyntaxOk(string $rel): void {
    global $ROOT;
    $out = shell_exec('php -l ' . escapeshellarg($ROOT . '/' . $rel) . ' 2>&1');
    strpos($out, 'No syntax errors') !== false ? ok("Syntax OK: $rel") : fail("Syntax ERROR: $rel — $out");
}

// ── 1. Required files exist ──────────────────────────────────────────────────
section('1. Required files exist');
$files = [
    'api/po_invoice_report.php',
    'app/bms/invoice/po_invoice_report.php',
];
foreach ($files as $f) {
    file_exists($ROOT . '/' . $f) ? ok($f) : fail("MISSING: $f");
}

// ── 2. PHP syntax ────────────────────────────────────────────────────────────
section('2. PHP syntax');
foreach ($files as $f) {
    fileSyntaxOk($f);
}

// ── Load sources ─────────────────────────────────────────────────────────────
$api  = readSrc('api/po_invoice_report.php');
$page = readSrc('app/bms/invoice/po_invoice_report.php');

// ── 3. API — auth + permission gates ─────────────────────────────────────────
section('3. API — auth + permission gates');
has($api, 'isAuthenticated()',              'api: isAuthenticated() check present');
has($api, "canView('received_invoices')",   "api: canView('received_invoices') check present");
has($api, 'http_response_code(401)',        'api: returns 401 when unauthenticated');
has($api, 'http_response_code(403)',        'api: returns 403 when forbidden');

// ── 4. API — status filter is in SQL via HAVING (not post-fetch PHP) ────────
section('4. API — status filter via SQL HAVING');
has($api, "HAVING (invoiced_total - po.grand_total) > 1",
    "api: HAVING clause for 'over' uses > 1 TZS tolerance");
has($api, "HAVING ABS(invoiced_total - po.grand_total) <= 1 AND po.grand_total > 0",
    "api: HAVING clause for 'fully' uses ABS <= 1 TZS tolerance");
has($api, "HAVING invoiced_total > 0",
    "api: HAVING clause for 'partial' present");
has($api, "HAVING invoiced_total = 0",
    "api: HAVING clause for 'open' present");
hasNot($api, '$inv === $total',
    "api: no strict === comparison on floats (would misclassify Fully Billed)");
hasNot($api, "array_filter(\$rows, function",
    "api: no post-fetch PHP array_filter for status (HAVING handles it now)");

// ── 5. API — project-scope filter present ───────────────────────────────────
section('5. API — project-scope filter (Phase G-2)');
has($api, "scopeFilterSqlNullable('project', 'po')",
    "api: scopeFilterSqlNullable('project','po') applied so non-admins see only their projects");
has($api, "function_exists('scopeFilterSqlNullable')",
    "api: scope helper guarded so older deploys don't fatal");

// ── 6. API — core query shape ───────────────────────────────────────────────
section('6. API — core query shape intact');
has($api, 'FROM purchase_orders po',                            'api: SELECTs from purchase_orders');
has($api, 'LEFT JOIN suppliers s',                              'api: LEFT JOIN suppliers');
has($api, 'FROM supplier_invoices',                             'api: aggregates supplier_invoices');
has($api, "po.status NOT IN ('cancelled')",                     'api: excludes cancelled POs');
has($api, "status != 'deleted'",                                'api: excludes deleted invoices');

// ── 7. Page JS — .fail() handler with descriptive errors ────────────────────
section('7. Page JS — .fail() handler with descriptive errors');
has($page, '.fail(function',                              'page: .fail() handler on AJAX call');
has($page, 'jqXHR.status === 401',                        'page: handles 401 explicitly');
has($page, 'jqXHR.status === 403',                        'page: handles 403 explicitly');
has($page, 'jqXHR.status === 500',                        'page: handles 500 explicitly');
has($page, "textStatus === 'timeout'",                    'page: handles timeout explicitly');
has($page, 'Received Invoices',                           'page: 403 message names the required permission');

// ── 8. Page JS — Float tolerance in statusFor() ─────────────────────────────
section('8. Page JS — float tolerance in statusFor()');
has($page, 'Math.abs(diff) <= 1',
    'page: statusFor() uses Math.abs(diff) <= 1 TZS tolerance');
hasNot($page, 'invoiced === total',
    'page: no strict === comparison (would diverge from API HAVING tolerance)');

// ── 9. Page JS — empty-state helper ─────────────────────────────────────────
section('9. Page JS — empty-state helper');
has($page, 'function getFilterSummary',
    'page: getFilterSummary() helper exists');
has($page, 'Try widening these filters',
    'page: empty-state explains filter-narrowing cause');
has($page, 'No purchase orders exist yet',
    'page: empty-state explains genuine-no-data cause');
has($page, 'No purchase orders found',
    'page: replaced vague "match these filters" message');

// ── 10. Page wiring — buildUrl + API constant ───────────────────────────────
section('10. Page wiring — buildUrl + API constant');
has($page, "buildUrl('api/po_invoice_report.php')",
    "page: API endpoint resolved via buildUrl()");
has($page, 'const RIR_API',
    'page: RIR_API constant defined');

// ── Summary ─────────────────────────────────────────────────────────────────
echo "\n────────────────────────────────────────────────────────────\n";
echo "Total: " . ($pass + $fail) . "  ";
echo "\033[32mPASS: $pass\033[0m  ";
echo $fail > 0 ? "\033[31mFAIL: $fail\033[0m" : "\033[32mFAIL: 0\033[0m";
echo "\n";

if ($fail > 0) {
    echo "\033[31m" . $fail . " check(s) failed.\033[0m\n";
    exit(1);
}
echo "\033[32mAll checks passed.\033[0m\n";
exit(0);
