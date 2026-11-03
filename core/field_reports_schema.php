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
            // A joined client's own company on the platform, and the customer record it
            // became (customer_subscription_plan.md Phase 3). Only BJP's root install fills them.
            'tenant_id'         => "ADD COLUMN `tenant_id` INT NULL DEFAULT NULL AFTER `joined_marked_by`",
            'tenant_subdomain'  => "ADD COLUMN `tenant_subdomain` VARCHAR(63) NULL DEFAULT NULL AFTER `tenant_id`",
            'customer_id'       => "ADD COLUMN `customer_id` INT NULL DEFAULT NULL AFTER `tenant_subdomain`",
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

        // Services the Sales Officer intended to sell on a visit (from Services).
        // The name is copied at save time so the report still reads correctly if
        // the service is renamed or deactivated later.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `field_visit_services` (
                `visit_id`     INT UNSIGNED NOT NULL,
                `product_id`   INT UNSIGNED NOT NULL,
                `service_name` VARCHAR(255) NOT NULL,
                `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`visit_id`, `product_id`),
                KEY `idx_fvs_product` (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // A report PDF shared as a link (WhatsApp where the device cannot attach
        // a file). Only the SHA-256 of the token is stored; the PDF lives here,
        // not in a public folder, and is served only until it expires.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `field_report_shares` (
                `share_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `token_hash` CHAR(64) NOT NULL,
                `filename`   VARCHAR(190) NOT NULL,
                `pdf`        MEDIUMBLOB NOT NULL,
                `created_by` INT UNSIGNED NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `expires_at` DATETIME NOT NULL,
                PRIMARY KEY (`share_id`),
                UNIQUE KEY `ux_frs_token` (`token_hash`),
                KEY `idx_frs_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Every follow-up of a visit, done or not (customer_visit_followups_plan.md D1).
        // At most one 'pending' row per visit (enforced in core/field_visit_followups.php).
        // field_visits.follow_up_* mirror the visit's current one (D3).
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `field_visit_follow_ups` (
                `follow_up_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `visit_id`        INT UNSIGNED NOT NULL,
                `due_date`        DATE NOT NULL,
                `purpose`         VARCHAR(255) NULL DEFAULT NULL,
                `status`          ENUM('pending','done','cancelled') NOT NULL DEFAULT 'pending',
                `method`          ENUM('visit','call','whatsapp','sms','other') NULL DEFAULT NULL,
                `outcome`         TEXT NULL DEFAULT NULL,
                `done_at`         DATETIME NULL DEFAULT NULL,
                `done_by`         INT UNSIGNED NULL DEFAULT NULL,
                `result_visit_id` INT UNSIGNED NULL DEFAULT NULL,
                `created_by`      INT UNSIGNED NULL DEFAULT NULL,
                `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_by`      INT UNSIGNED NULL DEFAULT NULL,
                `updated_at`      DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`follow_up_id`),
                KEY `idx_fvf_visit` (`visit_id`, `status`),
                KEY `idx_fvf_due` (`status`, `due_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Follow-ups recorded before the table existed (one date per visit): copied once,
        // done or not. A visit that already has rows is skipped, so re-running copies nothing.
        $pdo->exec("
            INSERT INTO field_visit_follow_ups (visit_id, due_date, status, done_at, done_by, created_by, created_at)
            SELECT v.visit_id, v.follow_up_date,
                   IF(v.follow_up_done_at IS NULL, 'pending', 'done'), v.follow_up_done_at, v.follow_up_done_by,
                   v.created_by, v.created_at
              FROM field_visits v
             WHERE v.follow_up_date IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM field_visit_follow_ups f WHERE f.visit_id = v.visit_id)
        ");
        fieldReportsEnsureFollowUpEvent($pdo);

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

if (!function_exists('fieldReportsEnsureFollowUpEvent')) {
    /**
     * The "follow-up due" reminder (customer_visit_followups_plan.md D7/D8). Its two
     * default rules — the staff member who recorded the visit, and all admins, both
     * in-app + email — are added only when the event itself is first created, so a
     * rule an admin later removes on Notification Rules is never put back by a re-run.
     */
    function fieldReportsEnsureFollowUpEvent(PDO $pdo): void
    {
        if (!$pdo->query("SHOW TABLES LIKE 'notification_events'")->fetch()) return;
        $ev = $pdo->prepare("
            INSERT IGNORE INTO notification_events
                (event_key, title, description, module, page_key, required_verb, default_severity, scope_aware)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ev->execute([
            'field_visit.follow_up_due',
            'Customer visit follow-up due',
            'A customer visit follow-up is due in 2 days, tomorrow, today, or was missed yesterday',
            'Customer Visits', 'field_visits', 'view', 'medium', 0,
        ]);
        if ($ev->rowCount() === 0 || !$pdo->query("SHOW TABLES LIKE 'notification_rules'")->fetch()) return;
        $rule = $pdo->prepare("
            INSERT INTO notification_rules (event_key, target_type, target_id, channel_email, channel_inapp, is_active, created_at)
            VALUES ('field_visit.follow_up_due', ?, NULL, 1, 1, 1, NOW())
        ");
        $rule->execute(['record_owner']);
        $rule->execute(['admins']);
    }
}
