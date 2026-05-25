<?php
/**
 * BMS — Project-Scope Coverage Regression Guard (Phase F)
 *
 * Locks in the current scope-coverage baseline so future PRs cannot
 * silently add new unscoped queries against project-scoped tables.
 *
 * Run:
 *   php tests/test_project_scope_cli.php
 *
 * Exit 0 = all counts ≤ ceiling (no regression).
 * Exit 1 = at least one ceiling exceeded (regression — block the PR).
 *
 * ── CEILING HISTORY ──────────────────────────────────────────────────────
 *
 *   Phase F baseline (2026-05-25):
 *     unscoped_count ≤ 225
 *
 *   Context: Phases A–E gated all write APIs for operations, finance,
 *   procurement, HR, and inventory. READ endpoints (list pages, AJAX
 *   data fetchers, export/print files) were not yet gated — 225 files
 *   carry scoped-table queries without a scope guard. This ceiling locks
 *   that number in place; any new PR that adds an unscoped read endpoint
 *   will push the count above 225 and fail CI.
 *
 *   Phase G-Sales (2026-05-25):
 *     unscoped_count ≤ 197
 *
 *   Context: Phase G read-side scope enforcement started. Sales module
 *   gated: sales_orders, quotations, sales_returns list pages;
 *   invoice list + export APIs; all write/status-change APIs for sales
 *   orders, quotations, invoices. 28 files cleared (225 → 197).
 *
 *   To reduce: add scopeFilterSql() to a list page or assertScopeForRecord()
 *   to a detail/print/API file, re-run this script, confirm the count drops,
 *   and lower the ceiling in this file. Target: 0.
 *
 * ── ENVIRONMENT BEHAVIOUR ────────────────────────────────────────────────
 *
 *   The audit (scratch/project_scope_audit.php) is purely static — it
 *   reads PHP source files on disk without a database connection. It runs
 *   identically on local and CI environments.
 *
 * ── FILES CHECKED ────────────────────────────────────────────────────────
 *
 *   Static (always):
 *     core/project_scope.php              must exist (Phase A helper)
 *     migrations/2026_05_24_project_scope_foundation.php  must exist
 *     app/constant/settings/user_projects.php             must exist (admin UI)
 *     scratch/project_scope_audit.php                     must exist
 *
 *   Coverage (static, no DB):
 *     unscoped_count  ≤ 225   (Phase F baseline — drive to 0 over time)
 */

$root     = dirname(__DIR__);
$failures = 0;
$passes   = 0;

function ok(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31m❌\033[0m $m\n"; }
function head(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

echo "\n\033[1m═══ BMS Project-Scope Coverage Regression Guard ═══\033[0m\n";

// ── 1. Static file checks ─────────────────────────────────────────────────
head('Required files');

$required_files = [
    'core/project_scope.php'                                 => 'Core scope helpers (Phase A)',
    'migrations/2026_05_24_project_scope_foundation.php'     => 'Foundation migration (user_projects table)',
    'app/constant/settings/user_projects.php'                => 'Admin assignment UI',
    'scratch/project_scope_audit.php'                        => 'Audit script (Phase F)',
];

foreach ($required_files as $rel => $label) {
    if (file_exists("$root/$rel")) {
        ok("$label — $rel");
    } else {
        bad("MISSING $label — $rel");
    }
}

// ── 2. Core helper presence ───────────────────────────────────────────────
head('Core scope helper signatures');

$scope_src = @file_get_contents("$root/core/project_scope.php") ?: '';
$required_fns = [
    'loadUserScope'               => 'Session bootstrap on login',
    'userCan'                     => 'Single-record gate',
    'scopeFilterSql'              => 'SQL IN-list filter (strict)',
    'scopeFilterSqlNullable'      => 'SQL IN-list filter (nullable project_id)',
    'assertScopeForRecord'        => 'Write-API gate via table+PK lookup',
    'assertScopeForEmployee'      => 'HR write-API gate via employee_id',
    'assertScopeForEmployeeRecord'=> 'HR leaf-record gate (leaves/payroll)',
    'assertScopeForRecordHtml'    => 'Print-page gate (plain-text 403)',
    'refreshScopeCache'           => 'Invalidate scope cache after assignment change',
];

foreach ($required_fns as $fn => $desc) {
    if (strpos($scope_src, "function $fn(") !== false) {
        ok("$fn() — $desc");
    } else {
        bad("MISSING function $fn() — $desc");
    }
}

// ── 3. Session bootstrap is wired into header.php ─────────────────────────
head('Session bootstrap wiring');

$header_src = @file_get_contents("$root/header.php") ?: '';
if (strpos($header_src, 'loadUserScope') !== false) {
    ok('loadUserScope() called in header.php');
} else {
    bad('loadUserScope() NOT found in header.php — scope cache never built for non-API pages');
}

// ── 4. Coverage audit (static) ────────────────────────────────────────────
head('Scope coverage audit');

// ── CEILING — update this number when more files are gated. Target: 0. ──
$CEILING = 197;

$audit_script = "$root/scratch/project_scope_audit.php";

if (!file_exists($audit_script)) {
    bad("Audit script not found: $audit_script");
} else {
    // Run the audit script and capture JSON output
    $redirect = (DIRECTORY_SEPARATOR === '\\') ? ' 2>NUL' : ' 2>/dev/null';
    $raw = shell_exec("php " . escapeshellarg($audit_script) . $redirect);
    $data = $raw ? json_decode($raw, true) : null;

    if (!is_array($data)) {
        bad("Audit script produced unparseable output — check scratch/project_scope_audit.php");
    } else {
        $unscoped = (int)($data['unscoped_count'] ?? PHP_INT_MAX);
        $coverage = $data['coverage_pct']  ?? 0;
        $scoped   = $data['scoped_files']  ?? 0;
        $guarded  = $data['guarded_files'] ?? 0;

        echo "     Total files scanned:     {$data['total_files']}\n";
        echo "     Files querying scoped tables: $scoped\n";
        echo "     Guarded files:           $guarded\n";
        echo "     Unscoped files:          $unscoped (ceiling: $CEILING)\n";
        echo "     Coverage:                {$coverage}%\n";

        if ($unscoped <= $CEILING) {
            ok("unscoped_count = $unscoped ≤ ceiling $CEILING — no regression");
        } else {
            $over = $unscoped - $CEILING;
            bad("unscoped_count = $unscoped EXCEEDS ceiling $CEILING by $over — new unscoped file(s) added");
            if (!empty($data['unscoped'])) {
                // Show the files above the ceiling (newest additions are typically at the end)
                $show = array_slice($data['unscoped'], $CEILING);
                echo "\n     Possible new additions (files beyond ceiling position):\n";
                foreach ($show as $item) {
                    $tables = implode(', ', $item['tables']);
                    echo "       {$item['file']}  [{$tables}]\n";
                }
            }
        }

        // Informational note on the gap
        if ($unscoped > 0) {
            $remaining_app = 0;
            $remaining_api = 0;
            foreach (($data['unscoped'] ?? []) as $item) {
                if (strpos($item['file'], 'app/') === 0) $remaining_app++;
                else $remaining_api++;
            }
            echo "\n     Gap breakdown: $remaining_api API endpoints + $remaining_app app pages still unscoped.\n";
            echo "     To reduce: add scopeFilterSql() / assertScopeForRecord() and lower \$CEILING.\n";
        }
    }
}

// ── Summary ───────────────────────────────────────────────────────────────
echo "\n\033[1m═══ Result ═══\033[0m\n";
$total = $passes + $failures;
if ($failures === 0) {
    echo "\033[32m✅ All $total checks passed.\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m❌ $failures / $total check(s) failed.\033[0m\n\n";
    exit(1);
}
