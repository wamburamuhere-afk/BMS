<?php
/**
 * core/field_reports_schema.php — tables + permission for the Field Reports
 * (marketing) module. Shared by the tenant and legacy-DB migrations so both
 * stay identical. Idempotent (no DDL inside a transaction).
 */

if (!function_exists('fieldReportsEnsureSchema')) {
    function fieldReportsEnsureSchema(PDO $pdo): void
    {
        // One row per client visit. user_id = the staff member who made it
        // (the owner); only they and admins may see it.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `field_visits` (
                `visit_id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`           INT UNSIGNED NOT NULL,
                `visit_date`        DATE NOT NULL,
                `visit_time`        TIME NULL DEFAULT NULL,
                `location`          VARCHAR(255) NOT NULL,
                `latitude`          DECIMAL(10,7) NULL DEFAULT NULL,
                `longitude`         DECIMAL(10,7) NULL DEFAULT NULL,
                `gps_accuracy_m`    INT UNSIGNED NULL DEFAULT NULL,
                `client_name`       VARCHAR(150) NOT NULL,
                `client_phone`      VARCHAR(30) NOT NULL,
                `phone_normalized`  VARCHAR(20) NOT NULL DEFAULT '',
                `business_type`     VARCHAR(40) NOT NULL DEFAULT 'other',
                `business_other`    VARCHAR(150) NULL DEFAULT NULL,
                `gave_business_card` TINYINT(1) NOT NULL DEFAULT 0,
                `gave_trial_link`   TINYINT(1) NOT NULL DEFAULT 0,
                `gave_training`     TINYINT(1) NOT NULL DEFAULT 0,
                `interest`          ENUM('interested','thinking','not_interested') NULL DEFAULT NULL,
                `notes`             TEXT NULL DEFAULT NULL,
                `follow_up_date`    DATE NULL DEFAULT NULL,
                `follow_up_done_at` DATETIME NULL DEFAULT NULL,
                `follow_up_done_by` INT UNSIGNED NULL DEFAULT NULL,
                `joined`            TINYINT(1) NOT NULL DEFAULT 0,
                `joined_at`         DATE NULL DEFAULT NULL,
                `joined_marked_by`  INT UNSIGNED NULL DEFAULT NULL,
                `status`            ENUM('active','deleted') NOT NULL DEFAULT 'active',
                `created_by`        INT UNSIGNED NULL DEFAULT NULL,
                `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_by`        INT UNSIGNED NULL DEFAULT NULL,
                `updated_at`        DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`visit_id`),
                KEY `idx_fv_user_date` (`user_id`, `visit_date`),
                KEY `idx_fv_date` (`visit_date`),
                KEY `idx_fv_phone` (`phone_normalized`),
                KEY `idx_fv_follow_up` (`user_id`, `follow_up_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Follow-up columns (customer_visits_ux_plan 3.1) for tables created before them.
        // Plain ALTERs, each only when missing — no DDL inside a transaction, re-runnable.
        $have = array_flip($pdo->query("SHOW COLUMNS FROM `field_visits`")->fetchAll(PDO::FETCH_COLUMN));
        foreach ([
            'follow_up_date'    => "ADD COLUMN `follow_up_date` DATE NULL DEFAULT NULL AFTER `notes`",
            'follow_up_done_at' => "ADD COLUMN `follow_up_done_at` DATETIME NULL DEFAULT NULL AFTER `follow_up_date`",
            'follow_up_done_by' => "ADD COLUMN `follow_up_done_by` INT UNSIGNED NULL DEFAULT NULL AFTER `follow_up_done_at`",
        ] as $col => $ddl) {
            if (!isset($have[$col])) $pdo->exec("ALTER TABLE `field_visits` $ddl");
        }
        $idx = $pdo->query("SHOW INDEX FROM `field_visits` WHERE Key_name = 'idx_fv_follow_up'")->fetch();
        if (!$idx) $pdo->exec("ALTER TABLE `field_visits` ADD KEY `idx_fv_follow_up` (`user_id`, `follow_up_date`)");

        // One row per staff member per day once they press "Submit today's report".
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `field_report_days` (
                `day_id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`              INT UNSIGNED NOT NULL,
                `report_date`          DATE NOT NULL,
                `submitted_at`         DATETIME NOT NULL,
                `visit_count`          INT UNSIGNED NOT NULL DEFAULT 0,
                `changed_after_submit` TINYINT(1) NOT NULL DEFAULT 0,
                `last_changed_at`      DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`day_id`),
                UNIQUE KEY `ux_frd_user_date` (`user_id`, `report_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->prepare("
            INSERT IGNORE INTO permissions (page_key, page_name, description, module_name)
            VALUES (?, ?, ?, ?)
        ")->execute([
            'field_visits',
            'Customer Visits',
            'Record own customer visits (marketing) and print/download own daily report (admins see all staff)',
            'Customer Visits',
        ]);
        // Renamed 2026-10-03 (customer_visits_ux_plan 2.1) — only rows still carrying
        // the name this module first shipped with; a custom name is left alone.
        $pdo->exec("UPDATE permissions SET page_name = 'Customer Visits' WHERE page_key = 'field_visits' AND page_name = 'Field Visits'");
        $pdo->exec("UPDATE permissions SET module_name = 'Customer Visits' WHERE page_key = 'field_visits' AND module_name = 'Field Reports'");
    }
}
