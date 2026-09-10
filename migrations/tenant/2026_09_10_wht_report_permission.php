<?php
/**
 * migrations/tenant/2026_09_10_wht_report_permission.php
 *
 * WHT Report shared the generic 'tax_report' page_key with Tax Report and WHT
 * Credit (Received) — so there was no way to gate it under the Procurement
 * module (core/feature_registry.php) without also hiding those two, which are
 * core/always-on (Tax Report reads invoices + pos_sales + supplier_invoices;
 * WHT Credit reads customer payments only). WHT Report itself reads ONLY
 * supplier_invoices/supplier_payments — 100% Procurement data — so it carves
 * out its own 'wht_report' page_key (app/constant/reports/wht_report.php).
 *
 * Unlike a brand-new feature seed, this is a CARVE-OUT of an existing grant, so
 * every role currently holding 'tax_report' is copied onto 'wht_report' too —
 * otherwise the split would silently strip WHT Report access from any
 * non-admin role that has it today. Mirrors the copy-forward pattern in
 * migrations/2026_08_29_hr_dashboard_permission.php (there: hr_performance -> hr_dashboard).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: seed 'wht_report' permission...\n";

try {
    $existing = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
    $existing->execute(['wht_report']);
    $permId = $existing->fetchColumn();

    if ($permId) {
        echo "  · 'wht_report' permission already exists (id {$permId}) — skipping insert.\n";
    } else {
        $pdo->prepare("INSERT INTO permissions (permission_name, page_key, page_name, description, module_name, is_hidden, created_at)
                       VALUES (?, ?, ?, ?, ?, 0, NOW())")
            ->execute([
                '', 'wht_report', 'WHT Report',
                'Withholding tax withheld on supplier payments, for the TRA monthly return',
                'Reports',
            ]);
        $permId = (int)$pdo->lastInsertId();
        echo "  + 'wht_report' permission created (id {$permId}).\n";
    }

    $sourcePermId = (int)$pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'tax_report'")->fetchColumn();
    if (!$sourcePermId) {
        echo "  ! 'tax_report' permission not found — cannot seed role grants. Migration complete.\n";
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
    echo "  + seeded wht_report grants for {$seeded} role(s) from their current tax_report grant.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
