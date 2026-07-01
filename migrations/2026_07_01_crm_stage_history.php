<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: CRM stage history + lead score columns + new permissions...\n";

try {
    // 1. Create crm_lead_stage_history table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_lead_stage_history` (
        `history_id`    INT AUTO_INCREMENT PRIMARY KEY,
        `lead_id`       INT         NOT NULL,
        `from_stage_id` INT         NULL,
        `to_stage_id`   INT         NOT NULL,
        `changed_by`    INT         NOT NULL,
        `changed_at`    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `note`          TEXT        NULL,
        INDEX `idx_lead_id` (`lead_id`),
        INDEX `idx_changed_at` (`changed_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + crm_lead_stage_history table created.\n";

    // 2. Add lead_score to crm_leads
    $col = $pdo->query("SHOW COLUMNS FROM `crm_leads` LIKE 'lead_score'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE `crm_leads` ADD COLUMN `lead_score` TINYINT NOT NULL DEFAULT 0 AFTER `probability`");
        echo "  + lead_score column added.\n";
    } else {
        echo "  ~ lead_score already exists.\n";
    }

    // 3. Add won_date
    $col = $pdo->query("SHOW COLUMNS FROM `crm_leads` LIKE 'won_date'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE `crm_leads` ADD COLUMN `won_date` DATE NULL AFTER `lost_reason`");
        echo "  + won_date column added.\n";
    } else {
        echo "  ~ won_date already exists.\n";
    }

    // 4. Add lost_date
    $col = $pdo->query("SHOW COLUMNS FROM `crm_leads` LIKE 'lost_date'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE `crm_leads` ADD COLUMN `lost_date` DATE NULL AFTER `won_date`");
        echo "  + lost_date column added.\n";
    } else {
        echo "  ~ lost_date already exists.\n";
    }

    // 5. Add last_activity
    $col = $pdo->query("SHOW COLUMNS FROM `crm_leads` LIKE 'last_activity'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE `crm_leads` ADD COLUMN `last_activity` DATETIME NULL AFTER `lost_date`");
        echo "  + last_activity column added.\n";
    } else {
        echo "  ~ last_activity already exists.\n";
    }

    // 6. Add stage_entered
    $col = $pdo->query("SHOW COLUMNS FROM `crm_leads` LIKE 'stage_entered'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE `crm_leads` ADD COLUMN `stage_entered` DATETIME NULL AFTER `last_activity`");
        echo "  + stage_entered column added.\n";
    } else {
        echo "  ~ stage_entered already exists.\n";
    }

    // 7. Seed new permissions (idempotent via INSERT IGNORE)
    $newPerms = [
        ['crm_reports', 'CRM Reports',       'CRM Reports — funnel, agent performance, campaign ROI'],
        ['crm_import',  'CRM Lead Import',    'Bulk import leads from CSV'],
        ['crm_labels',  'CRM Labels',         'Manage lead labels/tags'],
        ['crm_bulk',    'CRM Bulk Actions',   'Bulk operations on leads (assign, stage change, delete)'],
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO permissions (page_key, page_name, description, module_name) VALUES (?, ?, ?, 'Marketing & CRM')");
    foreach ($newPerms as [$key, $name, $desc]) {
        $stmt->execute([$key, $name, $desc]);
        echo "  + permission '$key' seeded.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
