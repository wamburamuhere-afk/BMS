<?php
/**
 * migrations/tenant/2026_10_13_service_sales_report_permission.php
 * Seeds the 'service_sales_report' page key and copies view access from
 * Product Analysis. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: service_sales_report_permission...
";

try {
    if (!(bool)$pdo->query("SHOW TABLES LIKE 'permissions'")->fetch()) {
        echo "  · no permissions table — skipped.\n";
        return;
    }
    $existing = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
    $existing->execute(['service_sales_report']);
    $permId = (int)$existing->fetchColumn();

    if ($permId) {
        echo "  · 'service_sales_report' permission already exists (id {$permId}).\n";
    } else {
        $pdo->prepare("INSERT INTO permissions (permission_name, page_key, page_name, description, module_name, is_hidden, created_at)
                       VALUES (?, ?, ?, ?, ?, 0, NOW())")
            ->execute(['', 'service_sales_report', 'Service Sales Report',
                       'Sales of each service from posted invoices and POS: revenue, trend, categories and quotation conversion',
                       'Reports']);
        $permId = (int)$pdo->lastInsertId();
        echo "  + 'service_sales_report' permission created (id {$permId}).\n";
    }

    // Roles that can already read Product Analysis (the same sales data, by
    // item) get the new report too, so nobody loses sight of it on rollout.
    $sourcePermId = (int)$pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'product_analysis'")->fetchColumn();
    if ($sourcePermId && (bool)$pdo->query("SHOW TABLES LIKE 'role_permissions'")->fetch()) {
        $n = $pdo->exec("INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete)
                         SELECT rp.role_id, $permId, rp.can_view, 0, 0, 0
                           FROM role_permissions rp
                          WHERE rp.permission_id = $sourcePermId AND rp.can_view = 1
                            AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = rp.role_id AND x.permission_id = $permId)");
        echo "  + view grant copied to {$n} role(s) that can view Product Analysis.\n";
    }

    echo "Migration complete: service_sales_report_permission.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
