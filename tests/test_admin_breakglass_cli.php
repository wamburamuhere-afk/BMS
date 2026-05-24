<?php
/**
 * Admin Break-Glass — CI Regression Guard
 * ---------------------------------------
 * Phase 0.5 of security_implementation_plan.md.
 *
 * Source-code-level invariants only. No DB required — runs on every push.
 * The runtime-DB version of the same checks lives in
 * scratch/verify_admin_bypass.php for ops to run on local / production
 * before deploying security-tightening changes.
 *
 * What this CI guard asserts:
 *
 *   1. core/permissions.php exposes isAdmin() and uses roles.is_admin
 *      as the source of truth (NOT a hard-coded role_id=1 anywhere).
 *
 *   2. Every can*() helper has the `if (isAdmin()) return true;` bypass
 *      so admin is never denied a permission check.
 *
 *   3. The scratch/verify_admin_bypass.php file exists, is valid PHP,
 *      and is the canonical runtime checker.
 *
 *   4. app/constant/settings/user_roles.php exists and compiles — admin
 *      must always be able to reach the permission-management UI.
 *
 * Run:
 *   php tests/test_admin_breakglass_cli.php
 *
 * Exit 0 = source invariants intact (safe to push)
 * Exit 1 = at least one invariant broken (push blocked)
 */

$root = dirname(__DIR__);
$failures = 0;
$passes   = 0;

function ok(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function head(string $t): void{ echo "\n\033[1m── $t ──\033[0m\n"; }

echo "\n\033[1m═══ Admin Break-Glass — CI Regression Guard ═══\033[0m\n";

// ─────────────────────────────────────────────────────────────────────────
head('1. core/permissions.php exposes isAdmin() and uses roles.is_admin');
// ─────────────────────────────────────────────────────────────────────────
$permFile = $root . '/core/permissions.php';
if (!file_exists($permFile)) {
    bad('core/permissions.php is missing — entire permission system broken');
} else {
    $src = file_get_contents($permFile);

    preg_match('/function\s+isAdmin\s*\(/', $src)
        ? ok('isAdmin() function declared')
        : bad('isAdmin() function NOT declared — admin bypass impossible');

    // Must reference the roles.is_admin column, not a hard-coded role_id.
    preg_match('/is_admin\s*FROM\s+roles|FROM\s+roles\s+WHERE\s+role_id/i', $src)
        ? ok('isAdmin() queries roles.is_admin (not hard-coded)')
        : bad('isAdmin() does NOT query roles.is_admin — bypass logic fragile');

    // The fast-path session key must be 'is_admin' (must match what login.php sets)
    str_contains($src, "\$_SESSION['is_admin']")
        ? ok('isAdmin() reads $_SESSION[\'is_admin\'] fast path')
        : bad('isAdmin() does not check $_SESSION[\'is_admin\'] fast path');
}

// ─────────────────────────────────────────────────────────────────────────
head('2. Every canX() helper has the admin bypass');
// ─────────────────────────────────────────────────────────────────────────
$src = file_exists($permFile) ? file_get_contents($permFile) : '';
$helpers = ['canView', 'canCreate', 'canEdit', 'canDelete', 'canReview', 'canApprove'];

foreach ($helpers as $fn) {
    // Match: function canX($pageKey) { ... if (isAdmin()) return true; ... }
    // Captures the function body up to the next "function" or end-of-file.
    if (preg_match(
        '/function\s+' . preg_quote($fn) . '\s*\([^)]*\)\s*\{(.*?)(?=\n\s*function\s|\z)/s',
        $src,
        $m
    )) {
        $body = $m[1];
        if (preg_match('/if\s*\(\s*isAdmin\s*\(\s*\)\s*\)\s*\{?\s*return\s+true\s*;?\s*\}?/', $body)) {
            ok("$fn() has the `if (isAdmin()) return true;` bypass");
        } else {
            bad("$fn() is MISSING the admin bypass — admin could be denied");
        }
    } else {
        bad("$fn() function not found in core/permissions.php");
    }
}

// ─────────────────────────────────────────────────────────────────────────
head('3. scratch/verify_admin_bypass.php exists and is valid PHP');
// ─────────────────────────────────────────────────────────────────────────
$breakglass = $root . '/scratch/verify_admin_bypass.php';
if (!file_exists($breakglass)) {
    bad('scratch/verify_admin_bypass.php missing — no runtime break-glass check available');
} else {
    ok('scratch/verify_admin_bypass.php present');
    $lint = shell_exec('php -l ' . escapeshellarg($breakglass) . ' 2>&1') ?: '';
    if (str_contains($lint, 'Parse error') || str_contains($lint, 'Fatal error')) {
        bad('scratch/verify_admin_bypass.php has a syntax error: ' . trim($lint));
    } else {
        ok('scratch/verify_admin_bypass.php passes php -l');
    }
}

// ─────────────────────────────────────────────────────────────────────────
head('4. app/constant/settings/user_roles.php intact');
// ─────────────────────────────────────────────────────────────────────────
$urPath = $root . '/app/constant/settings/user_roles.php';
if (!file_exists($urPath)) {
    bad('user_roles.php MISSING — admin cannot manage permissions from UI');
} else {
    ok('user_roles.php present');
    $lint = shell_exec('php -l ' . escapeshellarg($urPath) . ' 2>&1') ?: '';
    if (str_contains($lint, 'Parse error') || str_contains($lint, 'Fatal error')) {
        bad('user_roles.php has a syntax error: ' . trim($lint));
    } else {
        ok('user_roles.php passes php -l');
    }
}

// ─────────────────────────────────────────────────────────────────────────
head('5. login.php sets the admin session flags correctly');
// ─────────────────────────────────────────────────────────────────────────
// login.php / actions/login.php sets $_SESSION['role_id'] which isAdmin() reads.
// Verify the login flow sets role_id so isAdmin() can do its DB fallback.
$loginAction = $root . '/actions/login.php';
if (!file_exists($loginAction)) {
    bad('actions/login.php missing — no login flow to set session');
} else {
    $loginSrc = file_get_contents($loginAction);
    str_contains($loginSrc, "\$_SESSION['role_id']")
        ? ok("actions/login.php sets \$_SESSION['role_id'] (isAdmin DB fallback usable)")
        : bad("actions/login.php does NOT set \$_SESSION['role_id'] — isAdmin() cannot resolve admin status after login");
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n\033[1m═════════════════════════════════════════════\033[0m\n";
echo "Passes: $passes  Failures: $failures\n";
if ($failures > 0) {
    echo "\033[31m❌ Admin break-glass invariants broken — push blocked.\033[0m\n";
    echo "   Fix the regressions before merging any further security work.\n\n";
    exit(1);
}
echo "\033[32m✅ Admin break-glass source invariants intact.\033[0m\n";
echo "   (For the runtime / DB check, run scratch/verify_admin_bypass.php\n";
echo "    on the target server before deploying further security changes.)\n\n";
exit(0);
