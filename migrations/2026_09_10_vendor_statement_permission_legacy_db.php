<?php
/**
 * migrations/2026_09_10_vendor_statement_permission_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_10_vendor_statement_permission.php onto
 * the LEGACY / non-tenant database, same reason as
 * migrations/2026_09_08_pos_advanced_permission_legacy_db.php: the split
 * should apply everywhere this codebase runs, not just for registered tenants.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: seed 'vendor_statement' permission on the legacy database...\n";

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
