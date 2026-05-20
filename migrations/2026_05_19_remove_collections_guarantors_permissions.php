<?php
/**
 * Migration: Remove Collections & Guarantors from permissions
 * Reason: Loan/microfinance module does not exist in this system.
 *         These permission rows referred to pages that were never built,
 *         causing ghost entries in Admin → Roles & Permissions.
 */
require_once __DIR__ . '/../roots.php';

try {
    $pdo->beginTransaction();

    // Remove role_permissions rows first (FK child)
    $pdo->exec("
        DELETE FROM role_permissions
        WHERE permission_id IN (
            SELECT permission_id FROM permissions
            WHERE module_name IN ('Collections', 'Guarantors')
        )
    ");
    $rp_deleted = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();

    // Remove the permission definitions
    $pdo->exec("DELETE FROM permissions WHERE module_name IN ('Collections', 'Guarantors')");
    $p_deleted = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();

    $pdo->commit();

    echo "OK — removed {$p_deleted} permission(s) and {$rp_deleted} role_permission assignment(s).\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
