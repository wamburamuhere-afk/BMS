<?php
/**
 * Admin Break-Glass Sanity Check
 * ------------------------------
 * Phase 0.5 of security_implementation_plan.md.
 *
 * Run this BEFORE every later security phase merges and BEFORE every
 * production deploy that changes permissions. It confirms that:
 *
 *   1. At least one role in the `roles` table has is_admin = 1.
 *   2. At least one ACTIVE user is assigned to such a role (so somebody
 *      can actually log in as admin tomorrow).
 *   3. The isAdmin() function in core/permissions.php correctly returns
 *      true when given an admin role's session — meaning the bypass
 *      logic that lets admin read users.php / user_roles.php is intact.
 *   4. canView / canCreate / canEdit / canDelete / canReview / canApprove
 *      all honour the isAdmin() bypass — so admin can never be locked
 *      out of permission management even if a permissions row is missing.
 *
 * If any check fails, do NOT deploy security changes. Fix the gap first.
 *
 * Run:
 *   php scratch/verify_admin_bypass.php
 *
 * Exit 0 = admin break-glass intact, safe to proceed.
 * Exit 1 = at least one safety net is broken; DO NOT roll out further
 *          permission tightening until fixed.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/permissions.php';

$failures = 0;
$passes   = 0;

function ok(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function head(string $t): void{ echo "\n\033[1m── $t ──\033[0m\n"; }

echo "\n\033[1m═══ Admin Break-Glass Sanity Check ═══\033[0m\n";

global $pdo;

// ─────────────────────────────────────────────────────────────────────────
head('1. roles table has at least one is_admin=1 role');
// ─────────────────────────────────────────────────────────────────────────
$adminRoles = [];
try {
    $stmt = $pdo->query("SELECT role_id, role_name FROM roles WHERE is_admin = 1");
    $adminRoles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    bad('Could not query roles table: ' . $e->getMessage());
}

if (empty($adminRoles)) {
    bad('No role has is_admin=1 — nobody can bypass permission checks. SYSTEM UNUSABLE for admin tasks.');
} else {
    foreach ($adminRoles as $r) {
        ok("role_id={$r['role_id']} '{$r['role_name']}' has is_admin=1");
    }
}

// ─────────────────────────────────────────────────────────────────────────
head('2. At least one ACTIVE user is assigned to an admin role');
// ─────────────────────────────────────────────────────────────────────────
try {
    $stmt = $pdo->query("
        SELECT u.user_id, u.username, u.first_name, u.last_name, r.role_name
        FROM users u
        JOIN roles r ON r.role_id = u.role_id
        WHERE r.is_admin = 1
          AND u.is_active = 1
    ");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($admins)) {
        bad('No ACTIVE user has an admin role. NOBODY CAN LOG IN AND MANAGE PERMISSIONS. STOP.');
    } else {
        foreach ($admins as $a) {
            $name = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: $a['username'];
            ok("user_id={$a['user_id']} '{$name}' is active and has role '{$a['role_name']}'");
        }
    }
} catch (PDOException $e) {
    bad('Could not query users table: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
head('3. isAdmin() returns true for a simulated admin session');
// ─────────────────────────────────────────────────────────────────────────
if (!empty($adminRoles)) {
    $savedSession = $_SESSION ?? [];
    $_SESSION = [
        'user_id'  => 0,
        'role_id'  => (int)$adminRoles[0]['role_id'],
        // Deliberately NOT setting is_admin here so isAdmin() exercises the
        // DB-fallback path on roles.is_admin — the real safety net.
    ];

    $result = function_exists('isAdmin') ? isAdmin() : false;
    if ($result === true) {
        ok('isAdmin() returns true for role_id=' . $_SESSION['role_id'] . ' (DB fallback path works)');
    } else {
        bad('isAdmin() returned false even with an admin role_id — bypass logic is BROKEN');
    }

    $_SESSION = $savedSession;
} else {
    bad('Skipped — no admin role exists to test against');
}

// ─────────────────────────────────────────────────────────────────────────
head('4. canX() helpers honour the isAdmin() bypass');
// ─────────────────────────────────────────────────────────────────────────
if (!empty($adminRoles)) {
    $savedSession = $_SESSION ?? [];
    $_SESSION = [
        'user_id'  => 0,
        'role_id'  => (int)$adminRoles[0]['role_id'],
        'is_admin' => true, // fast path
        // Deliberately leave $_SESSION['permissions'] empty — if the bypass
        // is working, admin should still be allowed.
    ];

    $bypassTests = [
        'canView'    => '__breakglass_should_pass',
        'canCreate'  => '__breakglass_should_pass',
        'canEdit'    => '__breakglass_should_pass',
        'canDelete'  => '__breakglass_should_pass',
        'canReview'  => '__breakglass_should_pass',
        'canApprove' => '__breakglass_should_pass',
    ];
    foreach ($bypassTests as $fn => $fakeKey) {
        if (!function_exists($fn)) {
            bad("$fn() does not exist");
            continue;
        }
        $result = $fn($fakeKey);
        if ($result === true) {
            ok("$fn('$fakeKey') = true (admin bypass works even for a key that doesn't exist)");
        } else {
            bad("$fn('$fakeKey') = false — admin bypass is BROKEN, will lock admin out of permission management");
        }
    }

    $_SESSION = $savedSession;
} else {
    bad('Skipped — no admin role exists');
}

// ─────────────────────────────────────────────────────────────────────────
head('5. user_roles.php exists and is reachable');
// ─────────────────────────────────────────────────────────────────────────
$urPath = __DIR__ . '/../app/constant/settings/user_roles.php';
if (!file_exists($urPath)) {
    bad('user_roles.php file MISSING — admin cannot manage permissions from UI');
} else {
    ok('user_roles.php file exists');
    // Quick sanity — must compile
    $lint = shell_exec('php -l ' . escapeshellarg($urPath) . ' 2>&1') ?: '';
    if (str_contains($lint, 'Parse error') || str_contains($lint, 'Fatal error')) {
        bad('user_roles.php has a syntax error: ' . trim($lint));
    } else {
        ok('user_roles.php passes php -l');
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n\033[1m═════════════════════════════════════════\033[0m\n";
echo "Passes: $passes  Failures: $failures\n";
if ($failures > 0) {
    echo "\033[31m❌ ADMIN BREAK-GLASS IS BROKEN — do NOT deploy further security tightening until fixed.\033[0m\n\n";
    exit(1);
}
echo "\033[32m✅ Admin break-glass is intact. Safe to proceed with the next security phase.\033[0m\n\n";
exit(0);
