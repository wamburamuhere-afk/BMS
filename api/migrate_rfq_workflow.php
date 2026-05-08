<?php
// File: api/migrate_rfq_workflow.php
// Phase 1 Migration — RFQ Three-Stage Workflow
// Run ONCE via browser: http://localhost/bms/api/migrate_rfq_workflow.php
// Safe to re-run — each step checks before altering.

require_once __DIR__ . '/../roots.php';
if (!isAuthenticated() || !isAdmin()) die('Unauthorized — Admin only.');

global $pdo;
header('Content-Type: text/plain; charset=utf-8');

$results = [];

// ─────────────────────────────────────────────────────────────
// STEP 1: Add can_review and can_approve to role_permissions
// ─────────────────────────────────────────────────────────────
echo "=== STEP 1: role_permissions columns ===\n";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM role_permissions")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('can_review', $cols)) {
        $pdo->exec("ALTER TABLE role_permissions
            ADD COLUMN can_review  TINYINT(1) NOT NULL DEFAULT 0 AFTER can_delete");
        echo "  ✓ Added: can_review\n";
    } else {
        echo "  – Skipped: can_review already exists\n";
    }

    if (!in_array('can_approve', $cols)) {
        $pdo->exec("ALTER TABLE role_permissions
            ADD COLUMN can_approve TINYINT(1) NOT NULL DEFAULT 0 AFTER can_review");
        echo "  ✓ Added: can_approve\n";
    } else {
        echo "  – Skipped: can_approve already exists\n";
    }
    $results['step1'] = 'OK';
} catch (Exception $e) {
    echo "  ✗ ERROR: " . $e->getMessage() . "\n";
    $results['step1'] = 'FAILED';
}

// ─────────────────────────────────────────────────────────────
// STEP 2: Add snapshot columns to rfq table
// ─────────────────────────────────────────────────────────────
echo "\n=== STEP 2: rfq snapshot columns ===\n";
$newCols = [
    'prepared_by_name' => "VARCHAR(150) NULL DEFAULT NULL COMMENT 'Full name of creator at time of creation'",
    'prepared_by_role' => "VARCHAR(100) NULL DEFAULT NULL COMMENT 'Role of creator at time of creation'",
    'reviewed_by'      => "INT NULL DEFAULT NULL COMMENT 'User ID of reviewer'",
    'reviewed_by_name' => "VARCHAR(150) NULL DEFAULT NULL COMMENT 'Full name of reviewer at time of review'",
    'reviewed_by_role' => "VARCHAR(100) NULL DEFAULT NULL COMMENT 'Role of reviewer at time of review'",
    'reviewed_at'      => "DATETIME NULL DEFAULT NULL COMMENT 'Timestamp of review action'",
    'approved_by'      => "INT NULL DEFAULT NULL COMMENT 'User ID of approver'",
    'approved_by_name' => "VARCHAR(150) NULL DEFAULT NULL COMMENT 'Full name of approver at time of approval'",
    'approved_by_role' => "VARCHAR(100) NULL DEFAULT NULL COMMENT 'Role of approver at time of approval'",
    'approved_at'      => "DATETIME NULL DEFAULT NULL COMMENT 'Timestamp of approval action'",
];

try {
    $rfqCols = $pdo->query("SHOW COLUMNS FROM rfq")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($newCols as $col => $def) {
        if (!in_array($col, $rfqCols)) {
            $pdo->exec("ALTER TABLE rfq ADD COLUMN {$col} {$def}");
            echo "  ✓ Added: {$col}\n";
        } else {
            echo "  – Skipped: {$col} already exists\n";
        }
    }
    $results['step2'] = 'OK';
} catch (Exception $e) {
    echo "  ✗ ERROR: " . $e->getMessage() . "\n";
    $results['step2'] = 'FAILED';
}

// ─────────────────────────────────────────────────────────────
// STEP 3: Add 'review' to status ENUM
// ─────────────────────────────────────────────────────────────
echo "\n=== STEP 3: status ENUM update ===\n";
try {
    $row = $pdo->query("SHOW COLUMNS FROM rfq LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($row && strpos($row['Type'], "'review'") !== false) {
        echo "  – Skipped: 'review' already in ENUM\n";
    } else {
        $pdo->exec("ALTER TABLE rfq MODIFY COLUMN status
            ENUM('draft','review','approved','sent','received',
                 'evaluated','awarded','partially','completed','cancelled')
            NOT NULL DEFAULT 'draft'");
        echo "  ✓ Added 'review' to status ENUM\n";
    }
    $results['step3'] = 'OK';
} catch (Exception $e) {
    echo "  ✗ ERROR: " . $e->getMessage() . "\n";
    $results['step3'] = 'FAILED';
}

// ─────────────────────────────────────────────────────────────
// STEP 4: Backfill prepared_by_name for existing RFQs
// ─────────────────────────────────────────────────────────────
echo "\n=== STEP 4: Backfill prepared_by_name ===\n";
try {
    $count = $pdo->query("SELECT COUNT(*) FROM rfq WHERE prepared_by_name IS NULL AND created_by IS NOT NULL")
                 ->fetchColumn();
    if ($count > 0) {
        $pdo->exec("UPDATE rfq r
            JOIN users u ON r.created_by = u.user_id
            SET r.prepared_by_name = CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))
            WHERE r.prepared_by_name IS NULL AND r.created_by IS NOT NULL");
        // Set a default role label for backfilled records (historical — real role unknown)
        $pdo->exec("UPDATE rfq SET prepared_by_role = 'Staff'
            WHERE prepared_by_role IS NULL AND prepared_by_name IS NOT NULL");
        echo "  ✓ Backfilled {$count} existing RFQ(s)\n";
    } else {
        echo "  – No records to backfill\n";
    }
    $results['step4'] = 'OK';
} catch (Exception $e) {
    echo "  ✗ ERROR: " . $e->getMessage() . "\n";
    $results['step4'] = 'FAILED';
}

// ─────────────────────────────────────────────────────────────
// SUMMARY
// ─────────────────────────────────────────────────────────────
echo "\n=== MIGRATION SUMMARY ===\n";
$allOk = true;
foreach ($results as $step => $status) {
    $icon = $status === 'OK' ? '✓' : '✗';
    echo "  {$icon} {$step}: {$status}\n";
    if ($status !== 'OK') $allOk = false;
}
echo "\n" . ($allOk ? "✅ ALL STEPS COMPLETED — Phase 1 migration done." : "❌ SOME STEPS FAILED — check errors above.") . "\n";
echo "\nNext step: Open http://localhost/bms/api/test_rfq_phase1.php to run verification tests.\n";
