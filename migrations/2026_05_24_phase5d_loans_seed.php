<?php
/**
 * Security Phase 5d — Permission key seeds
 * ---------------------------------------------
 * Phase 5d of security_implementation_plan.md v2. Adds 3 page_keys
 * referenced by pages newly gated in this phase:
 *
 *   - loans         (Finance)  — used by app/bms/loans/{loan_application,loan_details}.php
 *   - help          (Settings) — used by app/constant/settings/help.php
 *   - my_settings   (Settings) — used by app/constant/settings/my_settings.php
 *
 * Without these rows, the audit reports page_key_missing_db (ceiling 0,
 * which would block CI). Default-deny posture: no role_permissions rows
 * are inserted, so non-admin roles cannot reach the gated pages until
 * an admin explicitly ticks the box in /user_roles.php. Admin always
 * bypasses via isAdmin().
 *
 * Idempotent — INSERT IGNORE so re-runs are no-ops.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Security Phase 5d permission seeds...\n";

try {
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO permissions (page_key, page_name, description, module_name)
        VALUES (?, ?, ?, ?)
    ");

    $seeds = [
        ['loans',       'Loans',       'Manage loan applications, repayments, and portfolio tracking', 'Finance'],
        ['help',        'Help',        'Application help / documentation pages',                       'Settings'],
        ['my_settings', 'My Settings', 'Personal user preferences and profile editing',                'Settings'],
    ];

    foreach ($seeds as [$key, $name, $desc, $module]) {
        $stmt->execute([$key, $name, $desc, $module]);
        if ($stmt->rowCount() > 0) {
            echo "  Inserted '$key' permission key.\n";
        } else {
            echo "  '$key' permission key already present (no-op).\n";
        }
    }

    echo "Phase 5d permission seed migration complete.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
