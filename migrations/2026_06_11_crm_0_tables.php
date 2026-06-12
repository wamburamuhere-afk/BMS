<?php
/**
 * 2026_06_11_crm_tables.php
 * -------------------------
 * Creates all five CRM tables:
 *   crm_pipeline_stages  — configurable Kanban columns
 *   crm_leads            — the core lead record
 *   crm_lead_activities  — call / meeting / note log per lead
 *   crm_labels           — colour-coded tags
 *   crm_lead_labels      — many-to-many lead ↔ label
 *
 * Idempotent: uses CREATE TABLE IF NOT EXISTS throughout.
 * No transactions — DDL auto-commits in MySQL.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: CRM tables...\n";

try {

    // ── 1. Pipeline stages ────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `crm_pipeline_stages` (
            `stage_id`    INT          AUTO_INCREMENT PRIMARY KEY,
            `stage_name`  VARCHAR(100) NOT NULL,
            `stage_order` TINYINT      NOT NULL DEFAULT 0,
            `color`       VARCHAR(7)   NOT NULL DEFAULT '#6c757d',
            `is_won`      TINYINT(1)   NOT NULL DEFAULT 0,
            `is_lost`     TINYINT(1)   NOT NULL DEFAULT 0,
            `status`      ENUM('active','deleted') NOT NULL DEFAULT 'active',
            `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + crm_pipeline_stages created (or already exists).\n";

    // ── 2. Leads ──────────────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `crm_leads` (
            `lead_id`             INT           AUTO_INCREMENT PRIMARY KEY,
            `lead_code`           VARCHAR(20)   NOT NULL,
            `first_name`          VARCHAR(100)  NOT NULL,
            `last_name`           VARCHAR(100)  DEFAULT NULL,
            `company_name`        VARCHAR(200)  DEFAULT NULL,
            `email`               VARCHAR(150)  DEFAULT NULL,
            `phone`               VARCHAR(30)   DEFAULT NULL,
            `mobile`              VARCHAR(30)   DEFAULT NULL,
            `website`             VARCHAR(200)  DEFAULT NULL,
            `address`             TEXT          DEFAULT NULL,
            `city`                VARCHAR(100)  DEFAULT NULL,
            `country`             VARCHAR(100)  NOT NULL DEFAULT 'Tanzania',
            `lead_source`         ENUM(
                                    'website','referral','walk_in','phone_call',
                                    'social_media','exhibition','cold_call',
                                    'email_campaign','other'
                                  ) NOT NULL DEFAULT 'other',
            `pipeline_stage_id`   INT           DEFAULT NULL,
            `assigned_to`         INT           DEFAULT NULL,
            `lead_value`          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `probability`         TINYINT       NOT NULL DEFAULT 20,
            `expected_close_date` DATE          DEFAULT NULL,
            `product_interest`    TEXT          DEFAULT NULL,
            `notes`               TEXT          DEFAULT NULL,
            `converted`           TINYINT(1)    NOT NULL DEFAULT 0,
            `customer_id`         INT           DEFAULT NULL,
            `quotation_id`        INT           DEFAULT NULL,
            `lost_reason`         TEXT          DEFAULT NULL,
            `project_id`          INT           DEFAULT NULL,
            `status`              ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
            `created_by`          INT           NOT NULL,
            `updated_by`          INT           DEFAULT NULL,
            `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_lead_code` (`lead_code`),
            INDEX `idx_stage`    (`pipeline_stage_id`),
            INDEX `idx_assigned` (`assigned_to`),
            INDEX `idx_status`   (`status`),
            INDEX `idx_converted`(`converted`),
            INDEX `idx_project`  (`project_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + crm_leads created (or already exists).\n";

    // ── 3. Lead activities ────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `crm_lead_activities` (
            `activity_id`   INT  AUTO_INCREMENT PRIMARY KEY,
            `lead_id`       INT  NOT NULL,
            `activity_type` ENUM('call','email','meeting','note','task','site_visit')
                            NOT NULL DEFAULT 'note',
            `subject`       VARCHAR(200) NOT NULL,
            `description`   TEXT         DEFAULT NULL,
            `activity_date` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `due_date`      DATETIME     DEFAULT NULL,
            `outcome`       TEXT         DEFAULT NULL,
            `status`        ENUM('pending','done','overdue','deleted') NOT NULL DEFAULT 'pending',
            `created_by`    INT  NOT NULL,
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_lead`     (`lead_id`),
            INDEX `idx_due`      (`due_date`),
            INDEX `idx_act_status`(`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + crm_lead_activities created (or already exists).\n";

    // ── 4. Labels ─────────────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `crm_labels` (
            `label_id`   INT AUTO_INCREMENT PRIMARY KEY,
            `label_name` VARCHAR(60)  NOT NULL,
            `color`      VARCHAR(7)   NOT NULL DEFAULT '#6c757d',
            `status`     ENUM('active','deleted') NOT NULL DEFAULT 'active',
            `created_by` INT NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + crm_labels created (or already exists).\n";

    // ── 5. Lead ↔ Label join ──────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `crm_lead_labels` (
            `id`       INT AUTO_INCREMENT PRIMARY KEY,
            `lead_id`  INT NOT NULL,
            `label_id` INT NOT NULL,
            UNIQUE KEY `uq_lead_label` (`lead_id`, `label_id`),
            INDEX `idx_ll_lead`  (`lead_id`),
            INDEX `idx_ll_label` (`label_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + crm_lead_labels created (or already exists).\n";

    echo "\nMigration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
