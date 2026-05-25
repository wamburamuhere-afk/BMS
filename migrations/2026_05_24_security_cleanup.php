<?php
/**
 * Security Phase 9 — Orphan permission cleanup
 * --------------------------------------------
 * Phase 9 of security_implementation_plan.md v2. Removes permission rows
 * that are truly unused anywhere in the codebase.
 *
 * **Strict deletion criteria — both conditions must hold:**
 *   1. Zero rows in `role_permissions` referencing the permission (no
 *      grant to any role).
 *   2. Zero references in PHP source files. The audit grep covers every
 *      canX() helper, autoEnforcePermission, requireX, assertCanX,
 *      hasPermission, and enforcePageOrAdmin. The router mapping in
 *      getPagePermissionMapping() is also inspected — a key referenced
 *      there is NOT deleted (defence-in-depth fallback).
 *
 * **Hardcoded delete list.** We do not run live regex against the
 * codebase from inside the migration. Instead the list below was
 * derived offline (in the Phase 9 PR description) so the migration is
 * fully auditable and predictable. To remove additional keys later,
 * extend this list in a new migration — never in-place.
 *
 * Idempotent: each DELETE uses an existence check and only runs if the
 * row is still there.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Security Phase 9 orphan permission cleanup...\n";

// Keys that have zero role_permissions AND zero code references AND no
// mapping in getPagePermissionMapping(). Verified offline; see Phase 9
// PR description for the audit trail.
$ORPHANS_TO_DELETE = [
    'activity_log'  => 'Legacy alias — code uses `audit_logs` (the file activity_log.php maps to audit_logs key)',
    'payment_create'=> 'Never granted, never referenced. The payment_create.php page gates on `invoices` key.',
];

try {
    $deleted = 0;
    $skipped = 0;

    foreach ($ORPHANS_TO_DELETE as $key => $why) {
        // Look up permission_id (idempotent — if already deleted, skip)
        $stmt = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $permission_id = $stmt->fetchColumn();

        if (!$permission_id) {
            echo "  '$key' — already absent (no-op).\n";
            $skipped++;
            continue;
        }

        // Safety net: re-check grant count at runtime in case a developer
        // tied this key to a role between the offline audit and deploy.
        $grantStmt = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE permission_id = ?");
        $grantStmt->execute([$permission_id]);
        $grantCount = (int)$grantStmt->fetchColumn();

        if ($grantCount > 0) {
            echo "  '$key' — has $grantCount role_permission row(s); NOT deleting.\n";
            $skipped++;
            continue;
        }

        $del = $pdo->prepare("DELETE FROM permissions WHERE permission_id = ?");
        $del->execute([$permission_id]);
        echo "  Deleted '$key' — $why\n";
        $deleted++;
    }

    echo "Phase 9 cleanup complete: deleted=$deleted, skipped=$skipped.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
