<?php
/**
 * Expense Report — Account filter swapped for Shop under Simple POS — CLI test
 * ----------------------------------------------------------------------------
 *   php tests/test_expense_report_shop_filter_cli.php
 *
 * User request 2026-09-15: "in expenses report page... if is simple pos...
 * replace that [Expense Account filter] section with shop." In Simple POS
 * every expense auto-resolves to the same generic account, so filtering or
 * charting by Account there was always meaningless — now that
 * expenses.warehouse_id exists (previous phase), the Account filter, the
 * "By Account" chart, and the Account table column are all swapped for Shop
 * when posSimpleModeEnabled() is true. Normal-mode tenants see no change at
 * all — the Account filter/chart/column behave exactly as before.
 *
 * Verifies:
 *   1. Both touched files lint-clean.
 *   2. Source wiring — the filter/chart/column swap is keyed off $posSimple
 *      consistently in both the page and its API, warehouse_id is
 *      scope-checked the same way project_id already is.
 *   3. Live (in-process, admin session) — with Simple Mode OFF: the API
 *      still groups/labels by Account exactly as before (no regression).
 *      With Simple Mode ON: the API groups by Shop, rows carry
 *      warehouse_name, and a synthetic expense in one warehouse doesn't
 *      leak into a request scoped to another.
 *
 * Exit 0 = all pass.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/project_scope.php";
require_once "$root/core/pos_nav.php";
global $pdo;

$passes = 0; $failures = 0;
function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
});

$touchedFiles = [
    'app/constant/reports/expense_report.php',
    'api/account/get_expense_report.php',
];

// ─────────────────────────────────────────────────────────────────────────
section('1. Both files lint-clean');
// ─────────────────────────────────────────────────────────────────────────
foreach ($touchedFiles as $f) {
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg("$root/$f") . " 2>&1", $o, $rc);
    $rc === 0 ? pass("$f lint-clean") : fail("$f lint failed: " . implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Source wiring');
// ─────────────────────────────────────────────────────────────────────────
$pageSrc = file_get_contents("$root/app/constant/reports/expense_report.php");
strpos($pageSrc, '$posSimple  = posSimpleModeEnabled();') !== false || strpos($pageSrc, '$posSimple = posSimpleModeEnabled();') !== false
    ? pass('page resolves $posSimple')
    : fail('page never resolves $posSimple');
strpos($pageSrc, 'name="warehouse_id"') !== false
    ? pass('page renders a warehouse_id filter field')
    : fail('page has no warehouse_id filter field');
strpos($pageSrc, "wLabel('By Account', 'By Shop')") !== false
    ? pass('the chart header swaps wording between Account/Shop')
    : fail('the chart header does not swap wording');
strpos($pageSrc, 'POS_SIMPLE ? r.warehouse_name : r.expense_account_name') !== false
    ? pass('the JS row renderer picks the column matching the active mode')
    : fail('the JS row renderer does not branch by mode — a Simple POS row would show a stale/empty Account value');

$apiSrc = file_get_contents("$root/api/account/get_expense_report.php");
strpos($apiSrc, "\$warehouse_id  = (isset(\$_GET['warehouse_id'])") !== false
    ? pass('API reads warehouse_id from $_GET')
    : fail('API never reads warehouse_id');
strpos($apiSrc, "if (\$warehouse_id !== null && !userCan('warehouse', \$warehouse_id))") !== false
    ? pass('API scope-checks the submitted warehouse_id, same discipline as project_id')
    : fail('API does not verify warehouse_id is in the caller\'s scope');
strpos($apiSrc, "scopeFilterSqlNullable('warehouse', 'e')") !== false
    ? pass('API applies the default warehouse scope when none is explicitly chosen')
    : fail('API never applies a default warehouse scope — a non-admin could see every shop\'s expenses');
strpos($apiSrc, 'if ($posSimple) {') !== false
    ? pass('the by-account/by-shop chart query branches on $posSimple')
    : fail('the chart query does not branch by mode');
strpos($apiSrc, 'w.warehouse_name') !== false && strpos($apiSrc, 'AS warehouse_name') !== false
    ? pass('detail rows carry warehouse_name')
    : fail('detail rows are missing warehouse_name');

// ─────────────────────────────────────────────────────────────────────────
section('3. Live (in-process, admin session)');
// ─────────────────────────────────────────────────────────────────────────
$adminUid = (int)($pdo->query("SELECT user_id FROM users WHERE is_active=1 ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']  = $adminUid;
$_SESSION['is_admin'] = true;
$_SESSION['role_id']  = 1;

function _call_expense_report(string $root, array $get): array {
    $_GET = $get;
    ob_start();
    require "$root/api/account/get_expense_report.php";
    $out = ob_get_clean();
    $json = json_decode($out, true);
    return is_array($json) ? $json : ['success' => false, 'raw' => $out];
}

$origSetting = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();

function _set_simple_mode($pdo, $on) {
    $val = $on ? '1' : '0';
    $exists = $pdo->query("SELECT 1 FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
    if ($exists) {
        $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'pos_simple_mode'")->execute([$val]);
    } else {
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode', ?)")->execute([$val]);
    }
}

try {
    // ── OFF: no regression for normal-mode tenants ──────────────────────
    _set_simple_mode($pdo, false);
    $resp = _call_expense_report($root, ['date_from' => '2020-01-01', 'date_to' => '2030-12-31']);
    $resp['success'] === true ? pass('Simple Mode OFF: response success=true') : fail('Simple Mode OFF: response failed — ' . json_encode($resp));
    if (($resp['rows'][0] ?? null) !== null) {
        array_key_exists('expense_account_name', $resp['rows'][0]) ? pass('OFF: rows still carry expense_account_name') : fail('OFF: expense_account_name missing from rows');
    }

    // ── ON: shop-based grouping + isolation ─────────────────────────────
    _set_simple_mode($pdo, true);

    $warehouses = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    if (count($warehouses) < 1) {
        fail('no active warehouse exists in this database — skipping the live isolation check');
    } else {
        $whA = (int)$warehouses[0];
        $whB = isset($warehouses[1]) ? (int)$warehouses[1] : $whA + 900001;

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare("INSERT INTO expenses (expense_date, amount, description, status, warehouse_id, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $ins->execute(['2026-09-15', 41000, 'TEST shop-filter expense, shop A', 'approved', $whA, $adminUid]);
            $ins->execute(['2026-09-15', 62000, 'TEST shop-filter expense, shop B — must not leak', 'approved', $whB, $adminUid]);

            $respAll = _call_expense_report($root, ['date_from' => '2026-09-15', 'date_to' => '2026-09-15']);
            $respAll['success'] === true ? pass('Simple Mode ON: response success=true') : fail('Simple Mode ON: response failed — ' . json_encode($respAll));

            if (($respAll['rows'][0] ?? null) !== null) {
                array_key_exists('warehouse_name', $respAll['rows'][0]) ? pass('ON: rows carry warehouse_name') : fail('ON: warehouse_name missing from rows');
            }
            $accountGrouped = false;
            foreach (($respAll['charts']['by_account'] ?? []) as $slice) {
                // A slice named after an accounts-table row (not "Company-wide"/
                // a real warehouse name) would mean the chart didn't actually swap.
                if ($slice['name'] === 'Unclassified') { $accountGrouped = true; break; }
            }
            !$accountGrouped ? pass('ON: chart is grouped by shop, not falling back to account grouping') : fail('ON: chart still shows an account-shaped "Unclassified" slice');

            $respScopedA = _call_expense_report($root, ['date_from' => '2026-09-15', 'date_to' => '2026-09-15', 'warehouse_id' => $whA]);
            $totalA = (float)($respScopedA['summary']['total_amount'] ?? -1);
            abs($totalA - 41000.0) < 0.01
                ? pass("filtering by warehouse_id=$whA returns exactly shop A's 41,000, not shop B's 62,000")
                : fail("filtering by warehouse_id=$whA returned $totalA, expected 41000 — cross-shop leakage or a real one was dropped");
        } finally {
            $pdo->rollBack();
            pass('synthetic test expenses rolled back — no test data persisted');
        }
    }
} finally {
    if ($origSetting === false) {
        $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'pos_simple_mode'");
    } else {
        $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'pos_simple_mode'")->execute([$origSetting]);
    }
    pass('pos_simple_mode setting restored to its original value');
}

exit($failures === 0 ? 0 : 1);
