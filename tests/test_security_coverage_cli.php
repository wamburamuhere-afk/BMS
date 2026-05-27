<?php
/**
 * BMS — Security Coverage Regression Guard
 *
 * Locks in the current security/audit baselines so future PRs cannot
 * silently regress them. Re-runs the same audits committed under
 * scratch/security_audit.php and scratch/activity_log_audit.php and
 * fails the build if any gap count GROWS above the ceiling.
 *
 * FINAL STATE (Phase 9 merged):
 *   pages_no_gate        = 0   LOCKED. Every page has an explicit permission gate.
 *   page_key_missing_db  = 0   LOCKED. Every gate key exists in the permissions table.
 *   write_apis_no_log    = 0   LOCKED. Every write API logs to activity_logs.
 *   api_perms_no_gate    = 0   LOCKED. Every write API has a canX() permission check.
 *   view_pages_no_log    ≤ 59  Phase 7 DEFERRED — view-page activity logging is
 *                              not in the v2 critical path. Re-tighten if/when 7 ships.
 *
 * Original baselines on main as of Phase 0 (for historical reference):
 *   pages_no_gate ≤ 76, page_key_missing_db ≤ 23, write_apis_no_log ≤ 100.
 *
 * Any future PR that introduces a regression in the locked metrics will
 * fail this guard and be blocked from merging.
 *
 * ENVIRONMENT BEHAVIOUR:
 *   - LOCAL (with includes/config.php + MySQL): full audit runs, ceilings
 *     are actively enforced.
 *   - CI (GitHub Actions — no config.php, no MySQL): the audit sub-scripts
 *     can't connect to the DB, so this guard prints a clear SKIPPED note
 *     for the DB-dependent sections and exits 0. The static checks
 *     (file presence, helpers require_once) still run.
 *
 *   Rationale: the audit framework was designed to run locally before
 *   push (via the pre-push hook). On CI we can't bring up MySQL without
 *   significantly heavier YAML, and the pre-push hook already enforces
 *   the same checks against the live local DB. CI's job here is to
 *   guard the static invariants (helpers + audit scripts on disk).
 *
 * Run:
 *   php tests/test_security_coverage_cli.php
 *
 * Exit 0 = all counts ≤ ceiling, OR DB-dependent sections were skipped on CI.
 * Exit 1 = at least one ceiling exceeded with parseable output (regression).
 *
 * Phase 0 ships the framework. Phases 1-9 drop the ceilings as work lands.
 */

$root = dirname(__DIR__);

// ── Ceilings — LOCKED at 0 after Phase 8/9. ────────────────────────────────
// Phase 9 completed the security rollout: every page, every write API, and
// every permission key is now accounted for. Any new gap fails CI forever.
//
// `view_pages_no_log` remains intentionally loose because Phase 7 (view-page
// activity logging) was deferred. Re-tighten when/if Phase 7 ships per the
// security plan.
$CEILINGS = [
    'pages_no_gate'       => 0,     // LOCKED. Phase 2 + 5a/b/c/d + 9 placeholder = all gated.
    'page_key_missing_db' => 0,     // LOCKED. Phase 1 + 5d migrations seed every needed key.
    'write_apis_no_log'   => 0,     // LOCKED. Phase 3a/b/c + 4a/b cover every write API.
    'view_pages_no_log'   => 49,    // Phase 7 (DEFERRED). Dropped 59→49 by Phase 3 (added logActivity to 7 procurement, 2 received-invoice, and 1 warehouse view-pages).
    'api_perms_no_gate'   => 0,     // LOCKED. Phase 4.5a/b/c-1/c-2/c-3/d gate every write API.
];
// ───────────────────────────────────────────────────────────────────────────

$failures = 0;
$passes   = 0;
$skipped  = 0;

function ok(string $m): void   { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void  { global $failures; $failures++; echo "  \033[31m❌\033[0m $m\n"; }
function skip(string $m): void { global $skipped;  $skipped++;  echo "  \033[33m⏭\033[0m  $m\n"; }
function head(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

echo "\n\033[1m═══ BMS Security Coverage Regression Guard ═══\033[0m\n";

/**
 * Decide whether DB-dependent audits can run in this environment.
 * Audits include scratch/security_audit.php which requires includes/config.php
 * (gitignored) and a live MySQL connection. On CI neither is present.
 */
function audits_can_run(string $root): bool
{
    // The audit scripts both require_once includes/config.php. If that file
    // is missing, neither can produce useful output.
    if (!file_exists("$root/includes/config.php")) return false;

    // The file exists but might point at a DB we can't reach (CI with a
    // committed stub config, for example). Do a fast connect probe.
    try {
        // Suppress the deprecation notices from includes/config.php's
        // closing-tag whitespace; we just want to know if PDO connects.
        ob_start();
        @require_once "$root/includes/config.php";
        ob_end_clean();
        if (!isset($pdo) || !($pdo instanceof PDO)) return false;
        // Light query — any failure means CI / unconfigured environment.
        $pdo->query('SELECT 1')->fetchColumn();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

$canRunAudits = audits_can_run($root);
if (!$canRunAudits) {
    echo "\n\033[33mNote:\033[0m no usable DB connection — DB-dependent audit sections will be SKIPPED.\n";
    echo "      (Static checks still run. Pre-push hook on a developer machine enforces the\n";
    echo "       full audit before code reaches this point.)\n";
}

// ─────────────────────────────────────────────────────────────────────────
head('1. Re-run scratch/security_audit.php and parse the gap counts');
// ─────────────────────────────────────────────────────────────────────────
$auditScript = $root . '/scratch/security_audit.php';
if (!file_exists($auditScript)) {
    bad('scratch/security_audit.php missing — cannot establish baseline');
} elseif (!$canRunAudits) {
    skip('pages_no_gate       : skipped (no DB on this host)');
    skip('page_key_missing_db : skipped (no DB on this host)');
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
} elseif (!$canRunAudits) {
    skip('view_pages_no_log : skipped (no DB on this host)');
    skip('write_apis_no_log : skipped (no DB on this host)');
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
head('3. Re-run scratch/api_permission_audit.php and parse the gap count');
// ─────────────────────────────────────────────────────────────────────────
$apiPermScript = $root . '/scratch/api_permission_audit.php';
if (!file_exists($apiPermScript)) {
    bad('scratch/api_permission_audit.php missing — Phase 4.5 baseline broken');
} elseif (!$canRunAudits) {
    skip('api_perms_no_gate : skipped (no DB on this host)');
} else {
    $out = shell_exec('php ' . escapeshellarg($apiPermScript) . ' 2>&1') ?: '';
    $apiNoGate = parseLineCount($out, '/Total:\s+(\d+)\s+write\s+API.*without\s+a\s+permission\s+gate/i');
    checkCeiling('api_perms_no_gate', $apiNoGate, $GLOBALS['CEILINGS']['api_perms_no_gate']);
}

// ─────────────────────────────────────────────────────────────────────────
head('4. Required security artefacts');
// ─────────────────────────────────────────────────────────────────────────
$required = [
    'core/security_helpers.php',
    'scratch/security_audit.php',
    'scratch/activity_log_audit.php',
    'scratch/api_permission_audit.php',
    'security_implementation_plan.md',
    'security_audit_2026_05_24.md',
];
foreach ($required as $rel) {
    file_exists("$root/$rel")
        ? ok("$rel present")
        : bad("$rel MISSING — security framework broken");
}

// ─────────────────────────────────────────────────────────────────────────
head('5. core/permissions.php loads core/security_helpers.php');
// ─────────────────────────────────────────────────────────────────────────
$perm = @file_get_contents("$root/core/permissions.php") ?: '';
str_contains($perm, "/security_helpers.php")
    ? ok('core/permissions.php require_once security_helpers.php')
    : bad('core/permissions.php does NOT load security_helpers.php — helpers unavailable');

// ─────────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────────
echo "\n\033[1m══════════════════════════════════════════════\033[0m\n";
echo "Passes: $passes  Failures: $failures  Skipped: $skipped\n";
if ($failures > 0) {
    echo "\033[31m❌ Security coverage regressed — push blocked.\033[0m\n";
    echo "   Fix the regressions (or, if intentional, update \$CEILINGS).\n\n";
    exit(1);
}
if ($skipped > 0) {
    echo "\033[33m⏭  Some DB-dependent checks were skipped (no DB on this host).\033[0m\n";
    echo "   Run \033[33mphp scratch/verify_admin_bypass.php\033[0m and the audit\n";
    echo "   scripts on a host with the live DB before merging security PRs.\n";
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
