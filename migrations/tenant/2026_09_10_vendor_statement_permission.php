<?php
/**
 * migrations/tenant/2026_09_10_vendor_statement_permission.php
 *
 * Vendor Statement shared the generic 'financial_reports' page_key with
 * Receivables Aging, Customer Statement and Consolidated Expenses — so there
 * was no way to gate it under the Procurement module (core/feature_registry.php)
 * without also hiding those three, which are core/always-on. Carves out its own
 * 'vendor_statement' page_key (app/constant/reports/vendor_statement.php,
 * api/account/get_vendor_statement.php, api/account/search_vendors.php).
 *
 * Unlike a brand-new feature seed, this is a CARVE-OUT of an existing grant, so
 * every role currently holding 'financial_reports' is copied onto
 * 'vendor_statement' too — otherwise the split would silently strip Vendor
 * Statement access from any non-admin role that has it today. Mirrors the
 * copy-forward pattern in migrations/2026_08_29_hr_dashboard_permission.php
 * (there: hr_performance -> hr_dashboard).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: seed 'vendor_statement' permission...\n";

try {
    $existing = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
    $existing->execute(['vendor_statement']);
    $permId = $existing->fetchColumn();

    if ($permId) {
        echo "  · 'vendor_statement' permission already exists (id {$permId}) — skipping insert.\n";
    } else {
        $pdo->prepare("INSERT INTO permissions (permission_name, page_key, page_name, description, module_name, is_hidden, created_at)
                       VALUES (?, ?, ?, ?, ?, 0, NOW())")
            ->execute([
                '', 'vendor_statement', 'Vendor Statement',
                'Supplier / sub-contractor statement of account',
                'Reports',
            ]);
        $permId = (int)$pdo->lastInsertId();
        echo "  + 'vendor_statement' permission created (id {$permId}).\n";
    }

    $sourcePermId = (int)$pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'financial_reports'")->fetchColumn();
    if (!$sourcePermId) {
        echo "  ! 'financial_reports' permission not found — cannot seed role grants. Migration complete.\n";
        return;
    }

    $roleRows = $pdo->prepare("SELECT role_id, can_view, can_create, can_edit, can_delete, can_review, can_approve
                                  FROM role_permissions WHERE permission_id = ?");
    $roleRows->execute([$sourcePermId]);
    $grants = $roleRows->fetchAll(PDO::FETCH_ASSOC);

    $hasRow = $pdo->prepare("SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ?");
    $insert = $pdo->prepare("INSERT INTO role_permissions
                                (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_review, can_approve)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $seeded = 0;
    foreach ($grants as $g) {
        $hasRow->execute([$g['role_id'], $permId]);
        if ($hasRow->fetchColumn()) continue;
        $insert->execute([
            $g['role_id'], $permId,
            $g['can_view'], $g['can_create'], $g['can_edit'], $g['can_delete'], $g['can_review'], $g['can_approve'],
        ]);
        $seeded++;
    }
    echo "  + seeded vendor_statement grants for {$seeded} role(s) from their current financial_reports grant.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
