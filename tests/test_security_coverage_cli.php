<?php
/**
 * BMS — Security Coverage Regression Guard
 *
 * Locks in the current security/audit baselines so future PRs cannot
 * silently regress them. Re-runs the same audits committed under
 * scratch/security_audit.php and scratch/activity_log_audit.php and
 * fails the build if any gap count GROWS above the ceiling.
 *
 * Baselines (set on main as of Phase 0):
 *   pages_no_gate        ≤ 76
 *   page_key_missing_db  ≤ 23
 *   write_apis_no_log    ≤ 100
 *   view_pages_no_log    ≤ 55  (deferred to Phase 7; tracked but not actively
 *                                enforced — kept high so build stays green)
 *
 * The ceilings DROP as each Phase ships. After Phase 9 merges, all ceilings
 * land at 0 and any regression fails CI forever.
 *
 * Run:
 *   php tests/test_security_coverage_cli.php
 *
 * Exit 0 = all counts ≤ ceiling (safe to push)
 * Exit 1 = at least one count grew (push blocked)
 *
 * Phase 0 ships the framework. Phases 1-9 drop the ceilings as work lands.
 */

$root = dirname(__DIR__);

// ── Ceilings — UPDATE THESE as each phase ships. ───────────────────────────
$CEILINGS = [
    'pages_no_gate'       => 76,    // Phase 2 + Phase 5 will drop this to 0.
    'page_key_missing_db' => 23,    // Phase 1 drops this to 0.
    'write_apis_no_log'   => 100,   // Phases 3 + 4 drop this to 0.
    'view_pages_no_log'   => 55,    // Phase 7 (DEFERRED) — kept loose for now.
];
// ───────────────────────────────────────────────────────────────────────────

$failures = 0;
$passes   = 0;

function ok(string $m): void   { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void  { global $failures; $failures++; echo "  \033[31m❌\033[0m $m\n"; }
function head(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

echo "\n\033[1m═══ BMS Security Coverage Regression Guard ═══\033[0m\n";

// ─────────────────────────────────────────────────────────────────────────
head('1. Re-run scratch/security_audit.php and parse the gap counts');
// ─────────────────────────────────────────────────────────────────────────
$auditScript = $root . '/scratch/security_audit.php';
if (!file_exists($auditScript)) {
    bad('scratch/security_audit.php missing — cannot establish baseline');
} else {
    $out = shell_exec('php ' . escapeshellarg($auditScript) . ' 2>&1') ?: '';

    $pagesNoGate    = parseCount($out, 'Pages with NO gate');
    $pagesKeyMissDb = parseCount($out, 'Gate key missing in DB');

    checkCeiling('pages_no_gate',       $pagesNoGate,    $GLOBALS['CEILINGS']['pages_no_gate']);
    checkCeiling('page_key_missing_db', $pagesKeyMissDb, $GLOBALS['CEILINGS']['page_key_missing_db']);
}

// ─────────────────────────────────────────────────────────────────────────
head('2. Re-run scratch/activity_log_audit.php and parse the gap counts');
// ─────────────────────────────────────────────────────────────────────────
$logScript = $root . '/scratch/activity_log_audit.php';
if (!file_exists($logScript)) {
    bad('scratch/activity_log_audit.php missing — cannot establish baseline');
} else {
    $out = shell_exec('php ' . escapeshellarg($logScript) . ' 2>&1') ?: '';

    // The audit prints two "Total: N <thing>" lines; one for view pages, one
    // for write APIs. Pull them with anchored regexes.
    $viewNoLog  = parseLineCount($out, '/Total:\s+(\d+)\s+page\(s\)\s+with\s+no\s+activity\s+log\s+call/i');
    $writeNoLog = parseLineCount($out, '/Total:\s+(\d+)\s+write\s+API/i');

    checkCeiling('view_pages_no_log', $viewNoLog,  $GLOBALS['CEILINGS']['view_pages_no_log']);
    checkCeiling('write_apis_no_log', $writeNoLog, $GLOBALS['CEILINGS']['write_apis_no_log']);
}

// ─────────────────────────────────────────────────────────────────────────
head('3. Required security artefacts');
// ─────────────────────────────────────────────────────────────────────────
$required = [
    'core/security_helpers.php',
    'scratch/security_audit.php',
    'scratch/activity_log_audit.php',
    'security_implementation_plan.md',
    'security_audit_2026_05_24.md',
];
foreach ($required as $rel) {
    file_exists("$root/$rel")
        ? ok("$rel present")
        : bad("$rel MISSING — security framework broken");
}

// ─────────────────────────────────────────────────────────────────────────
head('4. core/permissions.php loads core/security_helpers.php');
// ─────────────────────────────────────────────────────────────────────────
$perm = @file_get_contents("$root/core/permissions.php") ?: '';
str_contains($perm, "/security_helpers.php")
    ? ok('core/permissions.php require_once security_helpers.php')
    : bad('core/permissions.php does NOT load security_helpers.php — helpers unavailable');

// ─────────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────────
echo "\n\033[1m══════════════════════════════════════════════\033[0m\n";
echo "Passes: $passes  Failures: $failures\n";
if ($failures > 0) {
    echo "\033[31m❌ Security coverage regressed — push blocked.\033[0m\n";
    echo "   Fix the regressions (or, if intentional, update \$CEILINGS).\n\n";
    exit(1);
}
echo "\033[32m✅ Security coverage is within baseline — safe to push.\033[0m\n\n";
exit(0);

// ─────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────
function parseCount(string $haystack, string $label): int {
    // Matches lines like:  "Pages with NO gate    : 76"
    if (preg_match('/' . preg_quote($label, '/') . '\s*:\s*(\d+)/i', $haystack, $m)) {
        return (int)$m[1];
    }
    return -1;
}

function parseLineCount(string $haystack, string $regex): int {
    if (preg_match($regex, $haystack, $m)) return (int)$m[1];
    return -1;
}

function checkCeiling(string $name, int $count, int $ceiling): void {
    if ($count < 0) {
        bad("$name : could not parse audit output");
        return;
    }
    if ($count > $ceiling) {
        bad("$name : $count  (ceiling: $ceiling) — REGRESSION, $count gap(s) above the allowed baseline");
    } elseif ($count < $ceiling) {
        ok("$name : $count  (ceiling: $ceiling) — improved by " . ($ceiling - $count));
    } else {
        ok("$name : $count  (ceiling: $ceiling) — at baseline");
    }
}
