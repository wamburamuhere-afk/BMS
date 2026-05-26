<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: create workflow_signatures table...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS workflow_signatures (
            id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
            entity_type     VARCHAR(50)      NOT NULL,
            entity_id       INT UNSIGNED     NOT NULL,
            action          ENUM('created','reviewed','approved') NOT NULL,
            user_id         INT UNSIGNED     NOT NULL,
            user_name       VARCHAR(150)     NOT NULL DEFAULT '',
            user_role       VARCHAR(100)     NOT NULL DEFAULT '',
            sig_path        VARCHAR(500)     NULL,
            ip_address      VARCHAR(45)      NULL,
            consent_accepted TINYINT(1)      NOT NULL DEFAULT 1,
            signed_at       TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_entity_action (entity_type, entity_id, action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + Table workflow_signatures created (or already exists).\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
