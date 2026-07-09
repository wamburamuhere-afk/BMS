<?php
/**
 * 2026_07_09_leaves_contact_handover.php
 * --------------------------------------
 * Leaves module — Phase 3 support: persist two fields the form already collects
 * and then threw away.
 *
 * api/apply_leave.php reads $_POST['contact_during_leave'] and
 * $_POST['handover_to'], but neither appears in its INSERT column list and
 * neither column exists on `leaves`. Both were silently discarded, which is why
 * leave_details.php could never show them.
 *
 * Additive + idempotent.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: leaves.contact_during_leave + leaves.handover_to...\n";

try {
    $cols = [
        'contact_during_leave' => "VARCHAR(150) NULL",
        'handover_to'          => "INT NULL",
    ];

    foreach ($cols as $col => $def) {
        $st = $pdo->prepare("SHOW COLUMNS FROM `leaves` LIKE ?");
        $st->execute([$col]);
        if ($st->fetch()) {
            echo "  = leaves.$col already present, skipped.\n";
            continue;
        }
        $pdo->exec("ALTER TABLE `leaves` ADD COLUMN `$col` $def");
        echo "  + leaves.$col added.\n";
    }

    $hasIdx = $pdo->query("SHOW INDEX FROM `leaves` WHERE Key_name = 'idx_leaves_handover_to'")->fetch();
    if (!$hasIdx) {
        $pdo->exec("ALTER TABLE `leaves` ADD INDEX `idx_leaves_handover_to` (`handover_to`)");
        echo "  + index idx_leaves_handover_to added.\n";
    } else {
        echo "  = index idx_leaves_handover_to already present, skipped.\n";
    }

    // No FK to employees: handover_to is a colleague who may later be off-boarded,
    // and a RESTRICT would then block that. The detail page LEFT JOINs it.

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
