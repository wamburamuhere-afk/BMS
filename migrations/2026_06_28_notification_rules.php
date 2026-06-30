<?php
/**
 * 2026_06_28_notification_rules.php
 * ---------------------------------------------------------------------------
 * Phase 5 of the Smart Notification Engine — admin-configurable routing rules.
 *
 * Each rule says: for an event, notify a target (everyone-with-access | a role |
 * a specific user) on chosen channels (email / in-app). resolveRecipients()
 * narrows the permission-resolved audience to the rule targets, so a rule can
 * never reach someone who lacks access to that area.
 *
 * No rules for an event => default behaviour (in-app to all with access; email if
 * the global toggle is on). Additive & idempotent. No DDL transactions.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: notification routing rules...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notification_rules (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            event_key     VARCHAR(80)  NOT NULL,
            target_type   VARCHAR(20)  NOT NULL DEFAULT 'permission',  -- permission|role|user
            target_id     INT          NULL,                            -- role_id or user_id; NULL for 'permission'
            channel_email TINYINT(1)   NOT NULL DEFAULT 0,
            channel_inapp TINYINT(1)   NOT NULL DEFAULT 1,
            digest        VARCHAR(20)  NOT NULL DEFAULT 'immediate',    -- immediate|daily (future)
            is_active     TINYINT(1)   NOT NULL DEFAULT 1,
            created_by    INT          NULL,
            created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_nr_event (event_key),
            KEY idx_nr_target (target_type, target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + table notification_rules ensured.\n";

    // Admin page permission key so the rules UI can be RBAC-gated.
    $pdo->prepare("INSERT IGNORE INTO permissions (permission_name, page_key, page_name, module_name, is_hidden)
                   VALUES ('Notification Rules', 'notification_rules', 'Notification Rules', 'Settings', 0)")
        ->execute();
    echo "  + permission page_key 'notification_rules' ensured.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
